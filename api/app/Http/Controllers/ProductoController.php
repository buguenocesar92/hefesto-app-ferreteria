<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductoRequest;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\Variante;
use App\Services\Unidades\ConversorDeUnidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Productos y su matriz de variantes.
 *
 * ---------------------------------------------------------------------
 | LO QUE ESTE CONTROLADOR PROTEGE
 * ---------------------------------------------------------------------
 | En ninguna respuesta aparece el producto como si fuera vendible: siempre
 | viaja con sus variantes, y el precio y el stock son de la variante. Un
 | endpoint que devuelve "nombre: tornillo, precio: 0" invita a qué alguien
 | lo use para cobrar.
 */
class ProductoController extends Controller
{
    public function __construct(private readonly ConversorDeUnidades $conversor) {}

    /** Listado de productos con su número de variantes. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Producto::class);

        $productos = Producto::query()
            ->with(['categoria', 'marca', 'unidadBase'])
            ->withCount(['variantes' => fn ($consulta) => $consulta->where('activo', true)])
            ->when($request->query('buscar'), function ($consulta, string $termino): void {
                $consulta->where(function ($interna) use ($termino): void {
                    $interna->where('sku', 'ilike', '%' . $termino . '%')
                        ->orWhere('nombre', 'ilike', '%' . $termino . '%');
                });
            })
            ->when($request->boolean('solo_activos'), fn ($consulta) => $consulta->where('activo', true))
            ->orderBy('nombre')
            ->paginate((int) $request->query('por_pagina', 50));

        return response()->json($productos);
    }

    /** Ficha de un producto con la matriz de variantes completa. */
    public function show(Producto $producto): JsonResponse
    {
        Gate::authorize('view', $producto);

        $producto->load([
            'categoria',
            'marca',
            'unidadBase',
            'variantes.unidadVenta',
            'variantes.unidadContenido',
        ]);

        return response()->json([
            'producto' => $this->presentar($producto),
            'ejes' => $producto->ejesEnUso(),
            'variantes' => $producto->variantes->map(fn (Variante $v): array => $this->presentarVariante($v)),
        ]);
    }

    public function store(StoreProductoRequest $request): JsonResponse
    {
        Gate::authorize('create', Producto::class);

        $producto = Producto::create($request->datosParaCrear());

        return response()->json($this->presentar($producto->refresh()), 201);
    }

    public function update(StoreProductoRequest $request, Producto $producto): JsonResponse
    {
        Gate::authorize('update', $producto);

        $producto->update($request->datosParaCrear());

        return response()->json($this->presentar($producto->refresh()));
    }

    /**
     * Desactiva un producto.
     *
     * No lo borra: dejaria variantes huerfanas con historial de stock. Y
     * desactivar el producto no desactiva las variantes, porque puede haber
     * una en la que quedo el último stock y quitarla dejaria un saldo sin
     * origen. Lo que hace es sacarlo del catálogo nuevo.
     */
    public function desactivar(Request $request, Producto $producto): JsonResponse
    {
        Gate::authorize('desactivar', $producto);

        $producto->forceFill(['activo' => false])->save();

        return response()->json($this->presentar($producto->refresh()));
    }

    /** Catálogo de categorias, para los formularios. */
    public function categorias(): JsonResponse
    {
        Gate::authorize('viewAny', Producto::class);

        return response()->json([
            'categorias' => Categoria::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre']),
        ]);
    }

    /** Catálogo de marcas, para los formularios. */
    public function marcas(): JsonResponse
    {
        Gate::authorize('viewAny', Producto::class);

        return response()->json([
            'marcas' => Marca::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre']),
        ]);
    }

    /**
     * Convierte una cantidad de una unidad a otra para este producto.
     *
     * Es el endpoint que responde "¿esto son cuantos kilos?". Falla con 422
     * si las unidades no son de la misma familia, que es la respuesta
     * correcta: no hay conversión.
     */
    public function convertir(Request $request, Producto $producto): JsonResponse
    {
        Gate::authorize('view', $producto);

        $datos = $request->validate([
            'cantidad' => ['required', 'string', 'max:32'],
            'desde_unidad_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
            'hasta_unidad_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
        ], [], [
            'desde_unidad_id.required' => 'Indique la unidad de partida.',
            'hasta_unidad_id.required' => 'Indique la unidad de llegada.',
            'cantidad.required' => 'Indique la cantidad a convertir.',
        ]);

        $origen = UnidadMedida::findOrFail($datos['desde_unidad_id']);
        $destino = UnidadMedida::findOrFail($datos['hasta_unidad_id']);

        $convertida = $this->conversor->convertir(
            $origen,
            $destino,
            $datos['cantidad'],
            $producto->load('unidadBase')
        );

        return response()->json([
            'producto_id' => $producto->id,
            'cantidad' => $datos['cantidad'],
            'desde' => ['id' => $origen->id, 'codigo' => $origen->codigo, 'nombre' => $origen->nombre],
            'hasta' => ['id' => $destino->id, 'codigo' => $destino->codigo, 'nombre' => $destino->nombre],
            'resultado' => $convertida,
            'resultado_mostrado' => $this->conversor->formatear($convertida),
            'unidad_base' => [
                'id' => $producto->unidadBase->id,
                'codigo' => $producto->unidadBase->codigo,
                'nombre' => $producto->unidadBase->nombre,
            ],
        ]);
    }

    private function presentar(Producto $producto): array
    {
        return [
            'id' => $producto->id,
            'sku' => $producto->sku,
            'nombre' => $producto->nombre,
            'descripcion' => $producto->descripcion,
            'categoria' => $producto->categoria === null
                ? null
                : ['id' => $producto->categoria->id, 'nombre' => $producto->categoria->nombre],
            'marca' => $producto->marca === null
                ? null
                : ['id' => $producto->marca->id, 'nombre' => $producto->marca->nombre],
            'unidad_base' => $producto->unidadBase === null
                ? null
                : [
                    'id' => $producto->unidadBase->id,
                    'codigo' => $producto->unidadBase->codigo,
                    'nombre' => $producto->unidadBase->nombre,
                ],
            'stock_minimo' => $producto->stock_minimo,
            'activo' => $producto->activo,
            'variantes' => isset($producto->variantes_count) ? (int) $producto->variantes_count : null,
        ];
    }

    private function presentarVariante(Variante $variante): array
    {
        return [
            'id' => $variante->id,
            'sku' => $variante->sku,
            'nombre' => $variante->nombre,
            'etiqueta' => $variante->etiquetaDeVariante(),
            'medida' => $variante->medida,
            'material' => $variante->material,
            'acabado' => $variante->acabado,
            'color' => $variante->color,
            'contenido' => $variante->contenido,
            'unidad_contenido' => $variante->unidadContenido->codigo,
            'unidad_venta' => $variante->unidadVenta->codigo,
            'codigo_barras' => $variante->codigo_barras,
            'precio_venta' => $variante->precio_venta,
            'activo' => $variante->activo,
        ];
    }
}