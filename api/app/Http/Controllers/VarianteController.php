<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreVarianteRequest;
use App\Models\Producto;
use App\Models\Variante;
use App\Services\Unidades\ConversorDeUnidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Variantes: la matriz de variantes, fila por fila.
 *
 * ---------------------------------------------------------------------
 | POR QUE HAY UN CONTROLADOR DE VARIANTES Y NO UNO DE PRODUCTOS
 * ---------------------------------------------------------------------
 | Porque el precio y el stock son de la variante. Si se administraran desde
 | `ProductoController`, la pantalla terminaria mostrando el producto con un
 | precio y un stock que no son suyos, y el mostrador acabaria cobrando y
 | descontando sobre la fila equivocada.
 */
class VarianteController extends Controller
{
    public function __construct(private readonly ConversorDeUnidades $conversor) {}

    /**
     * Buscar una variante por código de barras.
     *
     * Es la operación que más veces se repite por hora: el escaner apunta y
     * la pantalla tiene que decir que es, cuanto hay y en que unidad. Por eso
     * devuelve la descripcion completa, no solo el nombre.
     */
    public function porCodigoDeBarras(Request $request, string $codigo): JsonResponse
    {
        Gate::authorize('viewAny', Variante::class);

        $variante = Variante::query()
            ->with([
                'producto.unidadBase',
                'producto.categoria',
                'producto.marca',
                'unidadVenta',
                'unidadContenido',
            ])
            ->where('codigo_barras', $codigo)
            ->where('activo', true)
            ->first();

        // 404 y no 403: la respuesta no confirma si el código existe en otra
        // ferreteria.
        if ($variante === null) {
            abort(404, 'Código de barras desconocido.');
        }

        $stock = $variante->stockPorSucursal()
            ->with('sucursal')
            ->get()
            ->map(fn ($saldo): array => [
                'sucursal_id' => $saldo->sucursal_id,
                'sucursal' => $saldo->sucursal->nombre,
                'codigo_sucursal' => $saldo->sucursal->codigo,
                'cantidad' => $saldo->cantidad,
                'disponible' => $saldo->disponible(),
                'unidad' => $saldo->unidadMedida->codigo,
            ]);

        return response()->json([
            'variante' => $this->presentar($variante),
            'stock_por_sucursal' => $stock,
        ]);
    }

    /** Listado de variantes de un producto. */
    public function index(Producto $producto): JsonResponse
    {
        Gate::authorize('view', $producto);

        $variantes = $producto->variantes()
            ->with(['unidadVenta', 'unidadContenido'])
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'producto_id' => $producto->id,
            'producto' => $producto->nombre,
            'ejes' => $producto->ejesEnUso(),
            'variantes' => $variantes->map(fn (Variante $v): array => $this->presentar($v)),
        ]);
    }

    public function show(Variante $variante): JsonResponse
    {
        Gate::authorize('view', $variante);

        $variante->load(['producto.unidadBase', 'unidadVenta', 'unidadContenido']);

        return response()->json([
            'variante' => $this->presentar($variante),
            'medidas' => $this->conversor->describir($variante, '1'),
            'precios_de_lista' => $variante->preciosDeLista()
                ->with('lista')
                ->get()
                ->map(fn ($precio): array => [
                    'lista' => $precio->lista->nombre,
                    'tipo' => $precio->lista->tipo,
                    'precio' => $precio->precio,
                ]),
        ]);
    }

    public function store(StoreVarianteRequest $request, Producto $producto): JsonResponse
    {
        Gate::authorize('create', Variante::class);

        $datos = $request->datosParaCrear();
        $datos['producto_id'] = $producto->id;

        $variante = Variante::create($datos);

        return response()->json($this->presentar($variante->refresh()), 201);
    }

    public function update(StoreVarianteRequest $request, Variante $variante): JsonResponse
    {
        Gate::authorize('update', $variante);

        $variante->update($request->datosParaCrear());

        return response()->json($this->presentar($variante->refresh()));
    }

    /**
     * Desactiva la variante.
     *
     * No la borra: tiene stock y movimientos que son evidencia. Desactivada
     * deja de aparecer en el mostrador y en la busqueda por código de barras,
     * pero su historia sigue completa.
     */
    public function desactivar(Variante $variante): JsonResponse
    {
        Gate::authorize('desactivar', $variante);

        $variante->forceFill(['activo' => false])->save();

        return response()->json($this->presentar($variante->refresh()));
    }

    private function presentar(Variante $variante): array
    {
        return [
            'id' => $variante->id,
            'producto_id' => $variante->producto_id,
            'sku' => $variante->sku,
            'nombre' => $variante->nombre,
            'etiqueta' => $variante->etiquetaDeVariante(),
            'medida' => $variante->medida,
            'material' => $variante->material,
            'acabado' => $variante->acabado,
            'color' => $variante->color,
            'contenido' => $variante->contenido,
            'unidad_contenido' => [
                'id' => $variante->unidadContenido->id,
                'codigo' => $variante->unidadContenido->codigo,
                'nombre' => $variante->unidadContenido->nombre,
            ],
            'unidad_venta' => [
                'id' => $variante->unidadVenta->id,
                'codigo' => $variante->unidadVenta->codigo,
                'nombre' => $variante->unidadVenta->nombre,
            ],
            'codigo_barras' => $variante->codigo_barras,
            'precio_venta' => $variante->precio_venta,
            'costo_referencia' => $variante->costo_referencia,
            'activo' => $variante->activo,
        ];
    }
}