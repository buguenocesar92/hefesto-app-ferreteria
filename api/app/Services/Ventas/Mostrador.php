<?php

declare(strict_types=1);

namespace App\Services\Ventas;

use App\Enums\EstadoVenta;
use App\Enums\TipoMovimientoCaja;
use App\Enums\TipoMovimientoStock;
use App\Exceptions\ErrorDeFerreteria;
use App\Models\AlertaStock;
use App\Models\Caja;
use App\Models\CajaMovimiento;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\StockSucursal;
use App\Models\Usuario;
use App\Models\Variante;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Precios\ResolutorDePrecios;
use App\Services\Stock\RegistroDeMovimientos;
use App\Services\Unidades\ConversorDeUnidades;
use App\Support\Cantidad;
use Illuminate\Support\Facades\DB;

/**
 * Venta de mostrador con varios renglones.
 *
 * ---------------------------------------------------------------------
 | UNA VENTA ES UNA TRANSACCIÓN O NO ES NADA
 * ---------------------------------------------------------------------
 | Se descuenta el stock, se guarda la venta, se anota el dinero en la caja y
 | se avisa al producto si quedo bajo el mínimo. Todo o nada: si un renglón se
 | queda sin stock, no se cobra nada y no queda una venta a medias que alguien
 | tenga que arreglar a mano.
 *
 * ---------------------------------------------------------------------
 | EL PRECIO LO RESUELVE EL SERVICIO, NO LA PANTALLA
 * ---------------------------------------------------------------------
 | La pantalla manda la variante y la cantidad. El precio sale de la lista
 | activa o del precio de mostrador. Si el mostrador pudiera mandar el precio,
 | bastaria un error de tipeo para cobrar mil pesos lo que vale cien, y el
 | corte de caja lo mostraria como si estuviera bien.
 */
class Mostrador
{
    public function __construct(
        private readonly ResolutorDePrecios $precios,
        private readonly RegistroDeMovimientos $movimientos,
        private readonly ConversorDeUnidades $conversor,
    ) {}

    /**
     * Registra una venta completa.
     *
     * @param  list<array{variante_id: string, cantidad: string, descuento?: string, detalle?: string}>  $items
     * @throws ErrorDeFerreteria si algo del renglón no es válido.
     */
    public function registrar(
        Usuario $usuario,
        string $sucursalId,
        array $items,
        ?ListaPrecio $lista = null,
        ?Caja $caja = null,
        ?string $clienteNombre = null,
        ?string $clienteRut = null,
        ?\DateTimeInterface $vendidaEn = null,
    ): Venta {
        $this->comprobarRenglones($items);

        $momento = $vendidaEn ?? now();

        return DB::transaction(function () use (
            $usuario,
            $sucursalId,
            $items,
            $lista,
            $caja,
            $clienteNombre,
            $clienteRut,
            $momento
        ): Venta {
            $decimales = (int) config('ferreteria.venta.decimales_total', 2);

            $subtotalTotal = Cantidad::cero($decimales);
            $descuentoTotal = Cantidad::cero($decimales);

            $venta = Venta::create([
                'tenant_id' => $usuario->tenant_id,
                'folio' => Venta::siguienteFolio($usuario->tenant_id),
                'caja_id' => $caja?->id,
                'sucursal_id' => $sucursalId,
                'lista_precios_id' => $lista?->id,
                'cliente_nombre' => $clienteNombre,
                'cliente_rut' => $clienteRut,
                'subtotal' => '0.00',
                'descuento' => '0.00',
                'total' => '0.00',
                'estado' => EstadoVenta::Registrada->value,
                'usuario_id' => $usuario->id,
                'vendida_at' => $momento,
            ]);

            foreach ($items as $item) {
                $variante = Variante::query()
                    ->with(['producto.unidadBase', 'unidadVenta', 'unidadContenido'])
                    ->findOrFail($item['variante_id']);

                $cantidad = $this->normalizarCantidad($variante, $item['cantidad']);

                $precioUnitario = $this->precios->precioDe($variante, $lista);

                $subtotal = $this->precios->subtotal($precioUnitario, $cantidad);

                $descuento = $this->cantizar($item['descuento'] ?? '0', $decimales);

                VentaItem::create([
                    'tenant_id' => $usuario->tenant_id,
                    'venta_id' => $venta->id,
                    'variante_id' => $variante->id,
                    'cantidad' => $cantidad,
                    'unidad_medida_id' => $variante->unidad_venta_id,
                    'precio_unitario' => $precioUnitario,
                    'subtotal' => $subtotal,
                    'descuento' => $descuento,
                    'detalle' => $item['detalle'] ?? null,
                ]);

                // El stock se descuenta DESPUÉS de guardar el renglón, y con
                // el movimiento que apunta a esta venta. Si el stock no
                // alcanza, la exception revierte la venta completa.
                $this->movimientos->registrar(
                    variante: $variante,
                    sucursalId: $sucursalId,
                    tipo: TipoMovimientoStock::Venta,
                    cantidad: $cantidad,
                    usuarioId: $usuario->id,
                    glosa: 'Venta ' . $venta->folio,
                    referenciaTipo: 'venta',
                    referenciaId: $venta->id,
                    ocurrioEn: $momento,
                );

                $subtotalTotal = $subtotalTotal->suma(Cantidad::desde($subtotal, $decimales));
                $descuentoTotal = $descuentoTotal->suma(Cantidad::desde($descuento, $decimales));

                $this->avisarSiQuedoBajoMinimo($variante, $sucursalId, $usuario->tenant_id);
            }

            $total = $subtotalTotal->resta($descuentoTotal)->redondearComercial($decimales);

            $venta->forceFill([
                'subtotal' => $subtotalTotal->aEscala($decimales),
                'descuento' => $descuentoTotal->aEscala($decimales),
                'total' => $total->aEscala($decimales),
            ])->save();

            if ($caja !== null && $caja->estaAbierta()) {
                CajaMovimiento::create([
                    'tenant_id' => $usuario->tenant_id,
                    'caja_id' => $caja->id,
                    'tipo' => TipoMovimientoCaja::Venta->value,
                    'monto' => $total->aEscala($decimales),
                    'referencia_tipo' => 'venta',
                    'referencia_id' => $venta->id,
                    'glosa' => 'Venta ' . $venta->folio,
                    'usuario_id' => $usuario->id,
                    'ocurrio_en' => $momento,
                ]);
            }

            return $venta->load('items.variante');
        });
    }

    /**
     * Anula una venta.
     *
     * No borra nada: devuelve el stock con un movimiento contrario y deja el
     * dinero en la caja hasta que el turno la compense. Borrar una venta cuya caja ya esta cerrada dejaria el corte descuadrado sin explicación.
     */
    public function anular(Venta $venta, Usuario $usuario, string $motivo): Venta
    {
        if ($venta->estaAnulada()) {
            throw new ErrorDeFerreteria('La venta ya está anulada.', 'venta_anulada');
        }

        return DB::transaction(function () use ($venta, $usuario, $motivo): Venta {
            foreach ($venta->items()->with('variante')->get() as $item) {
                $this->movimientos->registrar(
                    variante: $item->variante,
                    sucursalId: $venta->sucursal_id,
                    tipo: TipoMovimientoStock::Devolucion,
                    cantidad: $item->cantidad,
                    usuarioId: $usuario->id,
                    glosa: 'Anulacion de la venta ' . $venta->folio . '. ' . $motivo,
                    referenciaTipo: 'venta',
                    referenciaId: $venta->id,
                );
            }

            $venta->forceFill(['estado' => EstadoVenta::Anulada->value])->save();

            return $venta->refresh();
        });
    }

    /**
     * Cuanto queda en la caja si se cobra esta venta.
     *
     * El mostrador necesita el total ANTES de confirmar, para mostrarlo en
     * pantalla. Aquí no se toca stock ni caja: solo se calcula.
     *
     * @param  list<array{variante_id: string, cantidad: string}>  $items
     */
    public function cotizar(array $items, ?ListaPrecio $lista = null): array
    {
        $decimales = (int) config('ferreteria.venta.decimales_total', 2);

        $subtotalTotal = Cantidad::cero($decimales);

        $renglones = [];

        foreach ($items as $item) {
            $variante = Variante::query()
                ->with(['producto.unidadBase', 'unidadVenta'])
                ->findOrFail($item['variante_id']);

            $cantidad = $this->normalizarCantidad($variante, $item['cantidad']);

            $origen = $this->precios->resolverConOrigen($variante, $lista);

            $subtotal = $this->precios->subtotal($origen['precio'], $cantidad);

            $subtotalTotal = $subtotalTotal->suma(Cantidad::desde($subtotal, $decimales));

            $renglones[] = [
                'variante_id' => $variante->id,
                'sku' => $variante->sku,
                'nombre' => $variante->etiquetaDeVariante(),
                'cantidad' => $cantidad,
                'unidad' => $variante->unidadVenta->codigo,
                'precio_unitario' => $origen['precio'],
                'precio_desde' => $origen['origen'],
                'subtotal' => $subtotal,
            ];
        }

        $total = $subtotalTotal->redondearComercial($decimales);

        return [
            'renglones' => $renglones,
            'subtotal' => $subtotalTotal->aEscala($decimales),
            'descuento' => $this->cantizar('0', $decimales),
            'total' => $total->aEscala($decimales),
            'lista_id' => $lista?->id,
            'lista_nombre' => $lista?->nombre,
        ];
    }

    /**
     * Normaliza la cantidad a la unidad de venta de la variante.
     *
     * Si el mostrador escribe en otra unidad, se convierte aquí, una sola vez
     * y con el factor declarado. El renglón queda en la unidad en que esta el
     * saldo, para que el movimiento de stock no tenga que volver a convertir.
     */
    private function normalizarCantidad(Variante $variante, string $cantidad): string
    {
        $valor = Cantidad::desde($cantidad);

        if (! $valor->esPositivo()) {
            throw new ErrorDeFerreteria(
                'La cantidad de cada renglón tiene que ser mayor que cero.',
                'cantidad_invalida'
            );
        }

        return $this->conversor->cuantizar($valor->aEscala(Cantidad::decimalesDeConfiguracion()));
    }

    /** @param  list<array<string, mixed>>  $items */
    private function comprobarRenglones(array $items): void
    {
        $maximo = (int) config('ferreteria.mostrador.renglones_maximos', 200);

        if ($items === []) {
            throw new ErrorDeFerreteria(
                'Una venta sin renglones no se puede cobrar.',
                'venta_vacia'
            );
        }

        if (count($items) > $maximo) {
            throw new ErrorDeFerreteria(
                'La venta supera el máximo de ' . $maximo . ' renglones permitido en el mostrador.',
                'demasiados_renglones'
            );
        }
    }

    /**
     * Avisa al producto si esta variante quedo bajo su mínimo.
     *
     * El aviso queda `pendiente` y con lo que se ve en pantalla. Que salga a
     * internet es otra cosa, y depende de `FERRETERIA_ALERTAS_ENVIO_ACTIVO`.
     */
    private function avisarSiQuedoBajoMinimo(Variante $variante, string $sucursalId, string $tenantId): void
    {
        $saldo = StockSucursal::query()
            ->where('variante_id', $variante->id)
            ->where('sucursal_id', $sucursalId)
            ->first();

        if ($saldo === null) {
            return;
        }

        $producto = $variante->producto;

        if (! $saldo->bajoMinimo($producto)) {
            return;
        }

        AlertaStock::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'variante_id' => $variante->id,
                'sucursal_id' => $sucursalId,
                'periodo_clave' => now()->toDateString(),
            ],
            [
                'stock_detectado' => $saldo->disponible(),
                'stock_minimo' => $producto->stock_minimo,
            ]
        );
    }

    private function cantizar(string $valor, int $decimales): string
    {
        return Cantidad::desde($valor)->aEscala($decimales);
    }
}