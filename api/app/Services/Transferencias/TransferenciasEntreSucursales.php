<?php

declare(strict_types=1);

namespace App\Services\Transferencias;

use App\Enums\EstadoTransferencia;
use App\Enums\TipoMovimientoStock;
use App\Exceptions\ErrorDeFerreteria;
use App\Models\Transferencia;
use App\Models\TransferenciaItem;
use App\Models\Variante;
use App\Services\Stock\RegistroDeMovimientos;
use App\Support\Cantidad;
use Illuminate\Support\Facades\DB;

/**
 * Transferencias entre sucursales.
 *
 * ---------------------------------------------------------------------
 | UN TRASLADO ES UN HECHO, NO UN AJUSTE
 * ---------------------------------------------------------------------
 | Mover 12 cajas de la bodega central a la tienda deja dos saldos que
 | cambian. Si eso se hiciera ajustando los saldos sin documento, habria dos
 | números que cambian sin explicación, y cuando la tienda dijera "llegaron 8,
 | no 12" no habria nada que revisar.
 |
 | Con el documento hay una respuesta: cuantas se mandaron, cuantas se
 | recibieron, quien las recibio y cuando.
 *
 * ---------------------------------------------------------------------
 | LOS TRES ESTADOS Y LO QUE HACE CADA UNO
 * ---------------------------------------------------------------------
 |   borrador   se arma el papel, no toca stock
 |   despachada  se descuenta en el ORIGEN. La mercadería esta en camino
 |   recibida    se suma en el DESTINO
 *   anulada     se revierte con los movimientos contrarios
 |
 | Que `despachada` y `recibida` sean pasos separados es lo que hace útil el
 | estado intermedio: el destino sabe que le llega algo que todavia no esta en
 | su stock, que es información distinta de "no tengo nada".
 */
class TransferenciasEntreSucursales
{
    public function __construct(private readonly RegistroDeMovimientos $movimientos) {}

    /**
     * Crea una transferencia en borrador. No toca stock.
     *
     * @param  list<array{variante_id: string, cantidad: string}>  $items
     */
    public function crear(
        string $tenantId,
        string $sucursalOrigenId,
        string $sucursalDestinoId,
        array $items,
        string $usuarioId,
        ?string $observaciones = null,
    ): Transferencia {
        if ($sucursalOrigenId === $sucursalDestinoId) {
            throw new ErrorDeFerreteria(
                'Una sucursal no puede transferirse mercadería a sí misma.',
                'misma_sucursal'
            );
        }

        if ($items === []) {
            throw new ErrorDeFerreteria(
                'Una transferencia sin renglones no transporta nada.',
                'transferencia_vacia'
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $sucursalOrigenId,
            $sucursalDestinoId,
            $items,
            $usuarioId,
            $observaciones
        ): Transferencia {
            $transferencia = Transferencia::create([
                'tenant_id' => $tenantId,
                'folio' => Transferencia::siguienteFolio($tenantId),
                'sucursal_origen_id' => $sucursalOrigenId,
                'sucursal_destino_id' => $sucursalDestinoId,
                'estado' => EstadoTransferencia::Borrador->value,
                'observaciones' => $observaciones,
            ]);

            foreach ($items as $item) {
                $variante = Variante::findOrFail($item['variante_id']);

                $cantidad = Cantidad::desde(
                    $item['cantidad'],
                    Cantidad::decimalesDeConfiguracion()
                );

                if (! $cantidad->esPositivo()) {
                    throw new ErrorDeFerreteria(
                        'La cantidad de cada renglón tiene que ser mayor que cero.',
                        'cantidad_invalida'
                    );
                }

                TransferenciaItem::create([
                    'tenant_id' => $tenantId,
                    'transferencia_id' => $transferencia->id,
                    'variante_id' => $variante->id,
                    'cantidad' => $cantidad->aEscala(Cantidad::decimalesDeConfiguracion()),
                ]);
            }

            return $transferencia->load('items.variante');
        });
    }

    /**
     * Despacha: descuenta el stock del origen.
     *
     * Cada renglón escribe su movimiento de tipo `traslado_salida`. Si
     * alguna variante no tiene stock suficiente, la transacción se revierte
     * entera: no hay traslado a medias, que es lo que dejaria una mercadería
     * sin destino conocido.
     */
    public function despachar(Transferencia $transferencia, string $usuarioId): Transferencia
    {
        if (! $transferencia->estado->puedeDespacharse()) {
            throw new ErrorDeFerreteria(
                'Una transferencia en estado «' . $transferencia->estado->value
                . '» no se puede despachar.',
                'estado_invalido'
            );
        }

        return DB::transaction(function () use ($transferencia, $usuarioId): Transferencia {
            $items = $transferencia->items()->with('variante')->get();

            foreach ($items as $item) {
                $this->movimientos->registrar(
                    variante: $item->variante,
                    sucursalId: $transferencia->sucursal_origen_id,
                    tipo: TipoMovimientoStock::TrasladoSalida,
                    cantidad: $item->cantidad,
                    usuarioId: $usuarioId,
                    glosa: 'Transferencia ' . $transferencia->folio . ' a la sucursal destino',
                    referenciaTipo: 'transferencia',
                    referenciaId: $transferencia->id,
                );
            }

            $transferencia->forceFill([
                'estado' => EstadoTransferencia::Despachada->value,
                'fecha_despacho_at' => now(),
                'usuario_despacho_id' => $usuarioId,
            ])->save();

            return $transferencia->refresh();
        });
    }

    /**
     * Recibe: suma el stock en el destino.
     *
     * @param  array<string, string>|null  $recibidoPorVariante  Cantidades
     *         realmente recibidas por variante. Si se omite, se recibe todo
     *         lo despachado. Si se envia, la diferencia queda a la vista.
     */
    public function recibir(
        Transferencia $transferencia,
        string $usuarioId,
        ?array $recibidoPorVariante = null,
    ): Transferencia {
        if (! $transferencia->estado->puedeRecibirse()) {
            throw new ErrorDeFerreteria(
                'Una transferencia en estado «' . $transferencia->estado->value . '» no se puede recibir.',
                'estado_invalido'
            );
        }

        return DB::transaction(function () use ($transferencia, $usuarioId, $recibidoPorVariante): Transferencia {
            $items = $transferencia->items()->with('variante')->get();

            foreach ($items as $item) {
                $recibido = $recibidoPorVariante[$item->variante_id] ?? $item->cantidad;

                $cantidad = Cantidad::desde($recibido, Cantidad::decimalesDeConfiguracion());

                if (! $cantidad->esPositivo()) {
                    continue;
                }

                $enviado = Cantidad::desde($item->cantidad);
                $faltante = $enviado->resta($cantidad);

                $glosa = 'Transferencia ' . $transferencia->folio . ' desde la sucursal origen';

                if ($faltante->esPositivo()) {
                    // Lo que no llego queda escrito en el movimiento, para
                    // que la sucursal origen vea que algo salio pero no
                    // volvio, sin depender de que alguien lo recuerde.
                    $glosa .= '. Recibido ' . $cantidad->aEscala(
                        Cantidad::decimalesDeConfiguracion()
                    ) . ' de ' . $enviado->aEscala(Cantidad::decimalesDeConfiguracion()) . '.';
                }

                $this->movimientos->registrar(
                    variante: $item->variante,
                    sucursalId: $transferencia->sucursal_destino_id,
                    tipo: TipoMovimientoStock::TrasladoEntrada,
                    cantidad: $cantidad->aEscala(Cantidad::decimalesDeConfiguracion()),
                    usuarioId: $usuarioId,
                    glosa: $glosa,
                    referenciaTipo: 'transferencia',
                    referenciaId: $transferencia->id,
                );
            }

            $transferencia->forceFill([
                'estado' => EstadoTransferencia::Recibida->value,
                'fecha_recepcion_at' => now(),
                'usuario_recepcion_id' => $usuarioId,
            ])->save();

            return $transferencia->refresh();
        });
    }

    /**
     * Anula una transferencia.
     *
     * No borra nada. Si ya estaba despachada, escribe el movimiento de vuelta
     * en el origen; si ya estaba recibida, escribe el de vuelta en el destino.
     * Un traslado anulado deja el mismo saldo que antes, con dos movimientos
     * más que lo dicen.
     */
    public function anular(Transferencia $transferencia, string $usuarioId, string $motivo): Transferencia
    {
        if ($transferencia->estado->esFinal() && $transferencia->estado === EstadoTransferencia::Anulada) {
            throw new ErrorDeFerreteria('La transferencia ya está anulada.', 'estado_invalido');
        }

        return DB::transaction(function () use ($transferencia, $usuarioId, $motivo): Transferencia {
            $items = $transferencia->items()->with('variante')->get();

            if ($transferencia->estado === EstadoTransferencia::Despachada) {
                foreach ($items as $item) {
                    $this->movimientos->registrar(
                        variante: $item->variante,
                        sucursalId: $transferencia->sucursal_origen_id,
                        tipo: TipoMovimientoStock::Ingreso,
                        cantidad: $item->cantidad,
                        usuarioId: $usuarioId,
                        glosa: 'Anulacion de la transferencia ' . $transferencia->folio . '. ' . $motivo,
                        referenciaTipo: 'transferencia',
                        referenciaId: $transferencia->id,
                    );
                }
            }

            if ($transferencia->estado === EstadoTransferencia::Recibida) {
                foreach ($items as $item) {
                    $this->movimientos->registrar(
                        variante: $item->variante,
                        sucursalId: $transferencia->sucursal_destino_id,
                        tipo: TipoMovimientoStock::Merma,
                        cantidad: $item->cantidad,
                        usuarioId: $usuarioId,
                        glosa: 'Anulacion de la transferencia ' . $transferencia->folio . '. ' . $motivo,
                        referenciaTipo: 'transferencia',
                        referenciaId: $transferencia->id,
                    );
                }
            }

            $transferencia->forceFill([
                'estado' => EstadoTransferencia::Anulada->value,
                'observaciones' => trim(
                    ($transferencia->observaciones ?? '') . ' | Anulada: ' . $motivo
                ),
            ])->save();

            return $transferencia->refresh();
        });
    }

    /**
     * Que le falta a la sucursal destino.
     *
     * Si no hay diferencias, devuelve una lista vacia. Es la pregunta que se
     * hace al recibir, y la que evita descubrir al mes siguiente que la
     * tienda recibio la mitad.
     */
    public function diferencias(
        Transferencia $transferencia,
        array $recibidoPorVariante,
    ): array {
        $diferencias = [];

        foreach ($transferencia->items()->with('variante')->get() as $item) {
            $enviado = Cantidad::desde($item->cantidad);
            $recibido = Cantidad::desde(
                $recibidoPorVariante[$item->variante_id] ?? $item->cantidad,
                Cantidad::decimalesDeConfiguracion()
            );

            $diferencia = $recibido->resta($enviado);

            if ($diferencia->esCero()) {
                continue;
            }

            $diferencias[] = [
                'variante_id' => $item->variante_id,
                'variante' => $item->variante->nombre,
                'enviado' => $enviado->aEscala(Cantidad::decimalesDeConfiguracion()),
                'recibido' => $recibido->aEscala(Cantidad::decimalesDeConfiguracion()),
                'diferencia' => $diferencia->aEscala(Cantidad::decimalesDeConfiguracion()),
            ];
        }

        return $diferencias;
    }
}