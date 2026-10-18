<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeFerreteria;
use App\Http\Requests\RegistrarVentaRequest;
use App\Models\ListaPrecio;
use App\Models\Venta;
use App\Services\Precios\ResolutorDePrecios;
use App\Services\Ventas\Mostrador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Venta de mostrador.
 *
 * ---------------------------------------------------------------------
 | POR QUE NO HAY UN "PRECIO EN LA PETICION"
 * ---------------------------------------------------------------------
 | El precio lo resuelve `ResolutorDePrecios` desde la lista activa o desde
 | el precio de la variante. Si la pantalla mandara el precio, un error de
 | tipeo cobraria mil pesos lo que vale cien, y el corte de caja lo
 | mostraria como si estuviera bien. Esa clase de error es la que se
 * descubre al mes.
 */
class VentaController extends Controller
{
    public function __construct(
        private readonly Mostrador $mostrador,
        private readonly ResolutorDePrecios $precios,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Venta::class);

        $ventas = Venta::query()
            ->with(['sucursal', 'usuario', 'caja'])
            ->withCount('items')
            ->when($request->query('sucursal'), fn ($consulta, string $sucursal) => $consulta->where('sucursal_id', $sucursal))
            ->when($request->query('caja'), fn ($consulta, string $caja) => $consulta->where('caja_id', $caja))
            ->when($request->query('estado'), fn ($consulta, string $estado) => $consulta->where('estado', $estado))
            ->when($request->query('desde'), fn ($consulta, string $desde) => $consulta->where('vendida_at', '>=', $desde))
            ->when($request->query('hasta'), fn ($consulta, string $hasta) => $consulta->where('vendida_at', '<=', $hasta))
            ->orderByDesc('vendida_at')
            ->paginate((int) $request->query('por_pagina', 50));

        return response()->json($ventas);
    }

    public function show(Venta $venta): JsonResponse
    {
        Gate::authorize('view', $venta);

        $venta->load(['items.variante.unidadVenta', 'items.unidadMedida', 'sucursal', 'usuario', 'caja', 'listaPrecios']);

        return response()->json([
            'venta' => [
                'id' => $venta->id,
                'folio' => $venta->folio,
                'estado' => $venta->estado->value,
                'sucursal' => $venta->sucursal?->nombre,
                'caja_id' => $venta->caja_id,
                'lista_precios' => $venta->listaPrecios?->nombre,
                'cliente_nombre' => $venta->cliente_nombre,
                'cliente_rut' => $venta->cliente_rut,
                'subtotal' => $venta->subtotal,
                'descuento' => $venta->descuento,
                'total' => $venta->total,
                'vendida_at' => $venta->vendida_at?->toIso8601String(),
                'vendida_por' => $venta->usuario?->nombre,
            ],
            'items' => $venta->items->map(fn ($item): array => [
                'variante_id' => $item->variante_id,
                'sku' => $item->variante?->sku,
                'variante' => $item->variante?->etiquetaDeVariante(),
                'cantidad' => $item->cantidad,
                'unidad' => $item->unidadMedida?->codigo,
                'precio_unitario' => $item->precio_unitario,
                'subtotal' => $item->subtotal,
                'descuento' => $item->descuento,
            ]),
        ]);
    }

    /**
     * Cotiza sin cobrar.
     *
     * Devuelve el precio de cada renglón y de donde sale, para que quien
     * cobra pueda ver si la lista aplicada es la correcta antes de
     * confirmar.
     */
    public function cotizar(RegistrarVentaRequest $request): JsonResponse
    {
        Gate::authorize('cotizar', Venta::class);

        $datos = $request->datosParaCrear();

        $lista = $this->listaDe($request, $datos['sucursal_id']);

        $cotizacion = $this->mostrador->cotizar($datos['items'], $lista);

        return response()->json($cotizacion);
    }

    /** Registra la venta: descuenta stock, guarda el papel y anota la caja. */
    public function store(RegistrarVentaRequest $request): JsonResponse
    {
        Gate::authorize('registrar', Venta::class);

        $datos = $request->datosParaCrear();

        $lista = $this->listaDe($request, $datos['sucursal_id']);

        $caja = null;

        if ($datos['caja_id'] !== null) {
            $caja = \App\Models\Caja::findOrFail($datos['caja_id']);

            if (! $caja->estaAbierta()) {
                throw new ErrorDeFerreteria(
                    'La caja seleccionada ya está cerrada: la venta no pertenece a ese corte.',
                    'caja_cerrada'
                );
            }
        }

        $venta = $this->mostrador->registrar(
            $request->user(),
            $datos['sucursal_id'],
            $datos['items'],
            $lista,
            $caja,
            $datos['cliente_nombre'],
            $datos['cliente_rut']
        );

        return response()->json([
            'id' => $venta->id,
            'folio' => $venta->folio,
            'subtotal' => $venta->subtotal,
            'descuento' => $venta->descuento,
            'total' => $venta->total,
            'items' => $venta->items->count(),
        ], 201);
    }

    /**
     * Anula una venta.
     *
     * No la borra: devuelve el stock con un movimiento contrario. La venta
     * queda en `anulada` y se puede consultar.
     */
    public function anular(Request $request, Venta $venta): JsonResponse
    {
        Gate::authorize('anular', $venta);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:300'],
        ], [], [
            'motivo.required' => 'Explique por que se anula la venta.',
            'motivo.min' => 'Explique en al menos diez caracteres por que se anula.',
        ]);

        $venta = $this->mostrador->anular($venta, $request->user(), (string) $datos['motivo']);

        return response()->json(['id' => $venta->id, 'estado' => $venta->estado->value]);
    }

    /**
     * La lista que se aplica a esta venta.
     *
     * Si la peticion no elige ninguna, se usa la lista de mostrador
     * vigente de la sucursal. Y una lista atada a otra sucursal no se
     * aplica: el precio del mayorista no es el mismo en todas las tiendas.
     */
    private function listaDe(Request $request, string $sucursalId): ?ListaPrecio
    {
        $id = $request->input('lista_precios_id');

        if ($id === null || $id === '') {
            return $this->precios->listaDeMostrador(
                (string) $request->user()->tenant_id,
                $sucursalId
            );
        }

        $lista = ListaPrecio::findOrFail($id);

        if (! $this->precios->esAplicable($lista, $sucursalId)) {
            throw new ErrorDeFerreteria(
                'La lista «' . $lista->nombre . '» es de otra sucursal y no se puede aplicar aquí.',
                'lista_de_otra_sucursal'
            );
        }

        return $lista;
    }
}