<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AlertaStock;
use App\Models\Caja;
use App\Models\MovimientoStock;
use App\Models\StockSucursal;
use App\Models\Sucursal;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Support\Cantidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Reportes.
 *
 * ---------------------------------------------------------------------
 | ESTOS NÚMEROS SON PARA DECIDIR, NO PARA ADMIRAR
 * ---------------------------------------------------------------------
 | Cada reporte responde una pregunta que alguien tiene que responder:
 * cuanto se vendio, donde hay que reponer, cuanto se perdio en el mostrador y
 * que diferencias hay que revisar.
 *
 * ---------------------------------------------------------------------
 | POR QUE LOS MONTOS SE SUMAN COMO CADENA Y NO COMO NÚMERO
 * ---------------------------------------------------------------------
 | Un reporte que suma cien renglones de $1,190 con coma flotante devuelve
 | $118,99999999999998, que se muestra como $119.00 pero que al restarlo de
 * algo más vuelve a aparecer. La suma se hace con `Cantidad`, que trabaja con
 * dígitos, y el resultado se redondea una sola vez al final.
 */
class ReporteController extends Controller
{
    /** Ventas de un dia, por sucursal y por total. */
    public function ventasDelDia(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Venta::class);

        $fecha = (string) $request->query('fecha', now()->toDateString());
        $desde = $fecha . ' 00:00:00';
        $hasta = $fecha . ' 23:59:59';

        $ventas = Venta::query()
            ->with('sucursal')
            ->where('estado', 'registrada')
            ->whereBetween('vendida_at', [$desde, $hasta])
            ->orderBy('vendida_at')
            ->get();

        $porSucursal = [];
        $total = Cantidad::cero(2);
        $cantidadDeVentas = 0;

        foreach ($ventas as $venta) {
            $clave = (string) $venta->sucursal_id;

            if (! isset($porSucursal[$clave])) {
                $porSucursal[$clave] = [
                    'sucursal_id' => $clave,
                    'sucursal' => $venta->sucursal?->nombre,
                    'ventas' => 0,
                    'total' => Cantidad::cero(2),
                ];
            }

            $porSucursal[$clave]['ventas']++;
            $porSucursal[$clave]['total'] = $porSucursal[$clave]['total']->suma(
                Cantidad::desde($venta->total, 2)
            );

            $total = $total->suma(Cantidad::desde($venta->total, 2));
            $cantidadDeVentas++;
        }

        $resumenSucursales = [];

        foreach ($porSucursal as $fila) {
            $resumenSucursales[] = [
                'sucursal_id' => $fila['sucursal_id'],
                'sucursal' => $fila['sucursal'],
                'ventas' => $fila['ventas'],
                'total' => $fila['total']->aEscala(2),
            ];
        }

        return response()->json([
            'fecha' => $fecha,
            'ventas' => $cantidadDeVentas,
            'total' => $total->aEscala(2),
            'por_sucursal' => $resumenSucursales,
        ]);
    }

    /** Ventas de un dia por variante: que se esta llevando la gente. */
    public function rotacion(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Venta::class);

        $fecha = (string) $request->query('fecha', now()->toDateString());

        $filas = VentaItem::query()
            ->join('ventas', 'ventas.id', '=', 'venta_items.venta_id')
            ->join('variantes', 'variantes.id', '=', 'venta_items.variante_id')
            ->where('ventas.estado', 'registrada')
            ->whereBetween('ventas.vendida_at', [$fecha . ' 00:00:00', $fecha . ' 23:59:59'])
            ->where('variantes.tenant_id', (string) $request->user()->tenant_id)
            ->groupBy('variantes.id', 'variantes.sku', 'variantes.nombre')
            ->selectRaw('
                variantes.id as variante_id,
                variantes.sku,
                variantes.nombre,
                SUM(venta_items.cantidad) as unidades,
                SUM(venta_items.subtotal) as monto
            ')
            ->orderByDesc('unidades')
            ->limit((int) $request->query('limite', 50))
            ->get();

        return response()->json(['fecha' => $fecha, 'rotacion' => $filas]);
    }

    /**
     * Las diferencias de caja de un periodo.
     *
     * Es el reporte que el dueño mira todas las noches. Trae el número
     * completo con su signo y la etiqueta de revision, para que se pueda ver
     * de un vistazo si alguna tienda tiene una diferencia que se repite.
     */
    public function diferenciasDeCaja(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Caja::class);

        $desde = (string) $request->query('desde', now()->subDays(30)->toDateString());
        $hasta = (string) $request->query('hasta', now()->toDateString());

        $cajas = Caja::query()
            ->with(['sucursal', 'usuarioCierre'])
            ->where('estado', 'cerrada')
            ->whereBetween('fecha_cierre_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->orderBy('fecha_cierre_at')
            ->get();

        $totalDiferencia = Cantidad::cero(2);
        $requierenRevision = 0;

        $filas = [];

        foreach ($cajas as $caja) {
            $diferencia = Cantidad::desde($caja->diferencia ?? '0', 2);

            $totalDiferencia = $totalDiferencia->suma($diferencia);

            if ($caja->revision === 'requiere_revision') {
                $requierenRevision++;
            }

            $filas[] = [
                'id' => $caja->id,
                'sucursal' => $caja->sucursal?->nombre,
                'folio' => $caja->folio,
                'fecha_cierre_at' => $caja->fecha_cierre_at?->toIso8601String(),
                'monto_declarado' => $caja->monto_declarado,
                'monto_sistema' => $caja->monto_sistema,
                'diferencia' => $caja->diferencia,
                'revision' => $caja->revision,
                'cerro' => $caja->usuarioCierre?->nombre,
            ];
        }

        return response()->json([
            'desde' => $desde,
            'hasta' => $hasta,
            'cortes' => count($filas),
            'diferencia_total' => $totalDiferencia->aEscala(2),
            'cortes_que_requieren_revision' => $requierenRevision,
            'detalle' => $filas,
        ]);
    }

    /** Que hay que reponer, en todas las tiendas. */
    public function reposicion(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockSucursal::class);

        $sucursalId = $request->query('sucursal');

        $saldos = StockSucursal::query()
            ->with(['sucursal', 'unidadMedida', 'variante.producto'])
            ->when(is_string($sucursalId) && $sucursalId !== '', fn ($consulta) => $consulta->where('sucursal_id', $sucursalId))
            ->get();

        $filas = [];

        foreach ($saldos as $saldo) {
            $producto = $saldo->variante?->producto;

            if ($producto === null || ! $saldo->bajoMinimo($producto)) {
                continue;
            }

            $filas[] = [
                'sucursal' => $saldo->sucursal?->nombre,
                'sku' => $saldo->variante->sku,
                'variante' => $saldo->variante->etiquetaDeVariante(),
                'disponible' => $saldo->disponible(),
                'unidad' => $saldo->unidadMedida?->codigo,
                'minimo' => $producto->stock_minimo,
                'unidad_minimo' => $producto->unidadBase?->codigo,
            ];
        }

        return response()->json([
            'bajo_minimo' => count($filas),
            'items' => $filas,
            'alertas_pendientes' => AlertaStock::query()
                ->where('estado', 'pendiente')
                ->count(),
        ]);
    }

    /**
     * Que se movio el stock y por que.
     *
     * Es el historial, no el saldo. Cuando aparece una diferencia, lo que se
     * necesita son los movimientos que la explican.
     */
    public function movimientos(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockSucursal::class);

        $datos = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'sucursal' => ['nullable', 'uuid'],
            'tipo' => ['nullable', 'string', 'max:40'],
        ]);

        $movimientos = MovimientoStock::query()
            ->with(['variante.producto', 'sucursal', 'usuario'])
            ->when($datos['desde'] ?? null, fn ($consulta, string $desde) => $consulta->where('ocurrio_en', '>=', $desde.' 00:00:00'))
            ->when($datos['hasta'] ?? null, fn ($consulta, string $hasta) => $consulta->where('ocurrio_en', '<=', $hasta.' 23:59:59'))
            ->when($datos['sucursal'] ?? null, fn ($consulta, string $sucursal) => $consulta->where('sucursal_id', $sucursal))
            ->when($datos['tipo'] ?? null, fn ($consulta, string $tipo) => $consulta->where('tipo', $tipo))
            ->orderByDesc('ocurrio_en')
            ->limit((int) $request->query('limite', 200))
            ->get()
            ->map(fn (MovimientoStock $movimiento): array => [
                'id' => $movimiento->id,
                'variante' => $movimiento->variante?->etiquetaDeVariante(),
                'producto' => $movimiento->variante?->producto?->nombre,
                'sucursal' => $movimiento->sucursal?->nombre,
                'tipo' => $movimiento->tipo->value,
                'cantidad' => $movimiento->cantidad,
                'unidad' => $movimiento->unidadMedida?->codigo,
                'glosa' => $movimiento->glosa,
                'usuario' => $movimiento->usuario?->nombre,
                'ocurrio_en' => $movimiento->ocurrio_en?->toIso8601String(),
            ]);

        return response()->json(['movimientos' => $movimientos]);
    }

    /** Las sucursales de la ferreteria, para los selectores. */
    public function sucursales(): JsonResponse
    {
        Gate::authorize('viewAny', Sucursal::class);

        return response()->json([
            'sucursales' => Sucursal::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre']),
        ]);
    }
}