<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ListaPrecio;
use App\Models\ListaPrecioDetalle;
use App\Models\Variante;
use App\Services\Precios\ResolutorDePrecios;
use App\Support\Cantidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Listas de precios: el margen distinto por linea.
 *
 * ---------------------------------------------------------------------
 | POR QUE EL PRECIO NO ESTA EN LA VARIANTE
 * ---------------------------------------------------------------------
 | Un cliente mayorista no compra al mismo precio que el consumidor final, y
 | además hay más de un mayorista y más de una condicion. Si el precio
 | estuviera en la variante, habria que agregar "precio_mayorista" y, al
 | siguiente tipo de cliente, "precio_constructor".
 |
 | Con una tabla de listas, el precio depende de DOS cosas: la lista y la
 | variante. Un precio especial es una fila en `listas_precios_detalle`, no
 | una columna nueva.
 *
 * ---------------------------------------------------------------------
 | POR QUE UNA LISTA PUEDE SER DE UNA SOLA SUCURSAL
 * ---------------------------------------------------------------------
 | En la práctica los precios del mayorista no son los mismos en todas las
 | tiendas. Una lista atada a una sucursal no se puede aplicar en otra, y el
 | mostrador lo rechaza con un error claro en vez de cobrar al precio que no
 | corresponde.
 */
class ListaPrecioController extends Controller
{
    public function __construct(private readonly ResolutorDePrecios $precios) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ListaPrecio::class);

        $listas = ListaPrecio::query()
            ->with(['sucursal'])
            ->withCount('detalle')
            ->when($request->query('tipo'), fn ($consulta, string $tipo) => $consulta->where('tipo', $tipo))
            ->when($request->boolean('solo_activas'), fn ($consulta) => $consulta->where('activo', true))
            ->orderBy('tipo')
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'listas' => $listas->map(fn (ListaPrecio $lista): array => [
                'id' => $lista->id,
                'nombre' => $lista->nombre,
                'tipo' => $lista->tipo,
                'sucursal_id' => $lista->sucursal_id,
                'sucursal' => $lista->sucursal?->nombre,
                'variantes' => $lista->detalle_count,
                'activo' => $lista->activo,
                'vigente_desde' => $lista->vigente_desde?->toDateString(),
                'vigente_hasta' => $lista->vigente_hasta?->toDateString(),
                'vigente' => $lista->vigenteEn(),
            ]),
        ]);
    }

    public function show(ListaPrecio $listaPrecios): JsonResponse
    {
        Gate::authorize('view', $listaPrecios);

        $listaPrecios->load(['sucursal', 'detalle.variante.producto']);

        return response()->json([
            'lista' => [
                'id' => $listaPrecios->id,
                'nombre' => $listaPrecios->nombre,
                'tipo' => $listaPrecios->tipo,
                'sucursal_id' => $listaPrecios->sucursal_id,
                'activo' => $listaPrecios->activo,
            ],
            'detalle' => $listaPrecios->detalle->map(fn (ListaPrecioDetalle $fila): array => [
                'variante_id' => $fila->variante_id,
                'sku' => $fila->variante?->sku,
                'variante' => $fila->variante?->etiquetaDeVariante(),
                'precio' => $fila->precio,
                'precio_mostrador' => $fila->variante?->precio_venta,
            ]),
        ]);
    }

    /**
     * Guarda el precio de una variante en una lista.
     *
     * El indice único de `listas_precios_detalle` impide cargar dos precios
     * para la misma combinacion: sin el, el resultado dependeria del orden en
     * que los leyera la base.
     */
    public function guardarPrecio(Request $request, ListaPrecio $listaPrecios): JsonResponse
    {
        Gate::authorize('update', $listaPrecios);

        $datos = $request->validate([
            'variante_id' => ['required', 'uuid', 'exists:variantes,id'],
            'precio' => ['required', 'string', 'max:32'],
        ], [], [
            'variante_id.required' => 'Indique la variante a la que se aplica el precio.',
            'precio.required' => 'Indique el precio de la lista.',
        ]);

        $precio = Cantidad::desde((string) $datos['precio'])->aEscala(2);

        $variante = Variante::findOrFail($datos['variante_id']);

        $detalle = ListaPrecioDetalle::updateOrCreate(
            [
                'lista_precios_id' => $listaPrecios->id,
                'variante_id' => $variante->id,
            ],
            [
                'tenant_id' => $listaPrecios->tenant_id,
                'precio' => $precio,
            ]
        );

        return response()->json([
            'lista_precios_id' => $detalle->lista_precios_id,
            'variante_id' => $detalle->variante_id,
            'precio' => $detalle->precio,
            'precio_mostrador' => $variante->precio_venta,
        ]);
    }

    /**
     * El precio que se cobraria, y de donde sale.
     *
     * Es la misma pregunta que se hace el mostrador antes de cobrar. Que el
     * origen del precio venga en la respuesta es lo que permite que la caja
     * pueda explicar después por que se cobró una cifra y no otra.
     */
    public function precioDe(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ListaPrecio::class);

        $datos = $request->validate([
            'variante_id' => ['required', 'uuid', 'exists:variantes,id'],
            'lista_precios_id' => ['nullable', 'uuid', 'exists:listas_precios,id'],
        ]);

        $variante = Variante::query()
            ->with('producto')
            ->findOrFail($datos['variante_id']);

        $lista = null;

        if (($datos['lista_precios_id'] ?? null) !== null) {
            $lista = ListaPrecio::findOrFail($datos['lista_precios_id']);
        }

        return response()->json([
            'variante_id' => $variante->id,
            'variante' => $variante->etiquetaDeVariante(),
            ...$this->precios->resolverConOrigen($variante, $lista),
        ]);
    }
}