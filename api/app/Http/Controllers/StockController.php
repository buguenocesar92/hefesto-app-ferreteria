<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeFerreteria;
use App\Http\Requests\AjusteStockRequest;
use App\Models\AlertaStock;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\StockSucursal;
use App\Models\Sucursal;
use App\Models\Variante;
use App\Services\Stock\RegistroDeMovimientos;
use App\Support\Cantidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Stock por sucursal y su historia.
 *
 * ---------------------------------------------------------------------
 | ESTE ES EL LUGAR DONDE SE VE SI EL INVENTARIO CUADRA
 * ---------------------------------------------------------------------
 | El endpoint `resumen` responde la pregunta que se hace al abrir el
 * sistema: que hay en cada tienda. La respuesta trae el disponible, que se
 | deriva, y la unidad, porque un número sin unidad en una ferreteria es un
 | número que significa dos cosas.
 *
 * ---------------------------------------------------------------------
 | NO HAY NINGUN SALDO QUE SE PUEDA ESCRIBIR
 * ---------------------------------------------------------------------
 | `ingresar` y `ajustar` escriben movimientos. No existe ningun endpoint
 | que ponga un saldo, y el gatillo de PostgreSQL rechaza los que se
 | escriban por otra via.
 */
class StockController extends Controller
{
    public function __construct(private readonly RegistroDeMovimientos $registro) {}

    /** Stock de una sucursal, con su disponible. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockSucursal::class);

        $datos = $request->validate([
            'sucursal_id' => ['nullable', 'uuid', 'exists:sucursales,id'],
            'variante_id' => ['nullable', 'uuid', 'exists:variantes,id'],
            'producto_id' => ['nullable', 'uuid', 'exists:productos,id'],
            'buscar' => ['nullable', 'string', 'max:80'],
        ]);

        $saldos = StockSucursal::query()
            ->with(['unidadMedida', 'sucursal', 'variante.producto'])
            ->when(isset($datos['sucursal_id']), fn ($consulta) => $consulta->where('sucursal_id', $datos['sucursal_id']))
            ->when(isset($datos['variante_id']), fn ($consulta) => $consulta->where('variante_id', $datos['variante_id']))
            ->when(isset($datos['producto_id']), fn ($consulta) => $consulta
                ->whereHas('variante', fn ($interna) => $interna->where('producto_id', $datos['producto_id'])))
            ->when($datos['buscar'] ?? null, function ($consulta, string $termino): void {
                $consulta->whereHas('variante', function ($interna) use ($termino): void {
                    $interna->where('sku', 'ilike', '%' . $termino . '%')
                        ->orWhere('nombre', 'ilike', '%' . $termino . '%');
                });
            })
            ->orderBy('sucursal_id')
            ->paginate((int) $request->query('por_pagina', 50));

        $saldos->getCollection()->transform(function (StockSucursal $saldo): array {
            return $this->presentarSaldo($saldo);
        });

        return response()->json($saldos);
    }

    /** Que hay en cada tienda, en una sola respuesta. */
    public function resumen(): JsonResponse
    {
        Gate::authorize('viewAny', StockSucursal::class);

        $porSucursal = [];

        StockSucursal::query()
            ->with(['unidadMedida', 'sucursal', 'variante.producto'])
            ->orderBy('sucursal_id')
            ->get()
            ->each(function (StockSucursal $saldo) use (&$porSucursal): void {
                $clave = $saldo->sucursal_id;

                if (! isset($porSucursal[$clave])) {
                    $porSucursal[$clave] = [
                        'sucursal_id' => $clave,
                        'sucursal' => $saldo->sucursal->nombre,
                        'codigo' => $saldo->sucursal->codigo,
                        'variantes' => 0,
                    ];
                }

                $porSucursal[$clave]['variantes']++;
            });

        return response()->json(['por_sucursal' => array_values($porSucursal)]);
    }

    /**
     * Las variantes que quedaron bajo su mínimo.
     *
     * El mínimo vive en el producto y esta expresado en la unidad base. El
     * disponible vive en la variante y esta en la unidad de venta. La
     * comparacion de las dos es la que hace `AlertasDeReposicion`.
     */
    public function bajoMinimo(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockSucursal::class);

        $sucursalId = $request->query('sucursal_id');

        $bajoMinimo = [];

        StockSucursal::query()
            ->with(['unidadMedida', 'sucursal', 'variante.producto'])
            ->when(is_string($sucursalId) && $sucursalId !== '', fn ($consulta) => $consulta->where('sucursal_id', $sucursalId))
            ->get()
            ->each(function (StockSucursal $saldo) use (&$bajoMinimo): void {
                $producto = $saldo->variante?->producto;

                if ($producto === null || ! $saldo->bajoMinimo($producto)) {
                    return;
                }

                $bajoMinimo[] = [
                    'variante_id' => $saldo->variante_id,
                    'sku' => $saldo->variante->sku,
                    'variante' => $saldo->variante->etiquetaDeVariante(),
                    'producto' => $producto->nombre,
                    'sucursal_id' => $saldo->sucursal_id,
                    'sucursal' => $saldo->sucursal->nombre,
                    'disponible' => $saldo->disponible(),
                    'unidad' => $saldo->unidadMedida->codigo,
                    'stock_minimo' => $producto->stock_minimo,
                    'unidad_minima' => $producto->unidadBase->codigo,
                ];
            });

        return response()->json(['bajo_minimo' => $bajoMinimo]);
    }

    /** La historia de una variante en una sucursal. */
    public function movimientos(Request $request, Variante $variante): JsonResponse
    {
        Gate::authorize('verStock', $variante);

        $movimientos = MovimientoStock::query()
            ->with(['sucursal', 'usuario', 'unidadMedida'])
            ->where('variante_id', $variante->id)
            ->when($request->query('sucursal'), fn ($consulta, string $sucursal) => $consulta->where('sucursal_id', $sucursal))
            ->orderByDesc('ocurrio_en')
            ->paginate((int) $request->query('por_pagina', 50));

        return response()->json($movimientos);
    }

    /**
     * Ingreso de mercadería.
     *
     * El camino normal cuando llega material del proveedor. No exige glosa
     * porque la guia de despacho ya dice de donde viene, y la glosa es el
     * lugar donde se anota esa referencia.
     */
    public function ingresar(Request $request, Variante $variante): JsonResponse
    {
        Gate::authorize('ingresar', StockSucursal::class);

        $datos = $request->validate([
            'sucursal_id' => ['required', 'uuid', 'exists:sucursales,id'],
            'cantidad' => ['required', 'string', 'max:32'],
            'glosa' => ['required', 'string', 'max:300'],
        ], [], [
            'cantidad.required' => 'Indique cuanto material entra.',
            'glosa.required' => 'Indique la guia o el documento del ingreso.',
        ]);

        $cantidad = Cantidad::desde((string) $datos['cantidad']);

        if (! $cantidad->esPositivo()) {
            abort(422, 'Un ingreso tiene que ser una cantidad positiva.');
        }

        $movimiento = $this->registro->ingresar(
            $variante,
            (string) $datos['sucursal_id'],
            $datos['cantidad'],
            (string) $request->user()->id,
            (string) $datos['glosa']
        );

        return response()->json([
            'movimiento_id' => $movimiento->id,
            'saldo' => $this->presentarSaldo($this->registro->saldoDe($variante, (string) $datos['sucursal_id'])),
        ], 201);
    }

    /**
     * Ajuste con justificacion.
     *
     * Es el único camino para corregir un saldo sin documento. Escribe un
     * movimiento de tipo `ajuste`, no edita el saldo.
     */
    public function ajustar(AjusteStockRequest $request, Variante $variante): JsonResponse
    {
        Gate::authorize('ajustar', StockSucursal::class);

        $datos = $request->datosParaCrear();

        $movimiento = $this->registro->ajustar(
            $variante,
            $datos['sucursal_id'],
            $datos['cantidad'],
            (string) $request->user()->id,
            $datos['glosa']
        );

        return response()->json([
            'movimiento_id' => $movimiento->id,
            'saldo' => $this->presentarSaldo($this->registro->saldoDe($variante, $datos['sucursal_id'])),
        ], 201);
    }

    /** Los avisos de reposición generados. */
    public function alertas(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockSucursal::class);

        $alertas = AlertaStock::query()
            ->with(['variante', 'sucursal'])
            ->when($request->query('sucursal'), fn ($consulta, string $sucursal) => $consulta->where('sucursal_id', $sucursal))
            ->when($request->query('estado'), fn ($consulta, string $estado) => $consulta->where('estado', $estado))
            ->orderByDesc('periodo_clave')
            ->paginate((int) $request->query('por_pagina', 50));

        return response()->json($alertas);
    }

    private function presentarSaldo(StockSucursal $saldo): array
    {
        return [
            'id' => $saldo->id,
            'variante_id' => $saldo->variante_id,
            'sku' => $saldo->variante?->sku,
            'variante' => $saldo->variante?->etiquetaDeVariante(),
            'sucursal_id' => $saldo->sucursal_id,
            'sucursal' => $saldo->sucursal?->nombre,
            'cantidad' => $saldo->cantidad,
            'cantidad_reservada' => $saldo->cantidad_reservada,
            'disponible' => $saldo->disponible(),
            'unidad' => $saldo->unidadMedida?->codigo,
        ];
    }
}