<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoMovimientoCaja;
use App\Http\Requests\AperturaCajaRequest;
use App\Http\Requests\ArquearCajaRequest;
use App\Http\Requests\MovimientoCajaRequest;
use App\Models\Caja;
use App\Services\Caja\CorteDeCaja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Cortes de caja.
 *
 * ---------------------------------------------------------------------
 | LA DIFERENCIA SE MUESTRA, NO SE CALCULA EN EL SERVIDOR DE OTRO LADO
 * ---------------------------------------------------------------------
 | El endpoint `arqueo` calcula los tres números SIN cerrar el corte, para
 | que quien esta en el mostrador vea cuanto deberia haber antes de decidir.
 * El cierre (`store` del arqueo) es lo que guarda la diferencia.
 *
 | La diferencia se devuelve COMPLETA y con su signo. Un endpoint que la
 | devolviera como "dentro de tolerancia" cuando hay 500 pesos de diferencia
 * estaria escondiendo justo lo que el dueño necesita ver.
 */
class CajaController extends Controller
{
    public function __construct(private readonly CorteDeCaja $servicio) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Caja::class);

        $cajas = Caja::query()
            ->with(['sucursal', 'usuarioApertura', 'usuarioCierre'])
            ->when($request->query('sucursal'), fn ($consulta, string $sucursal) => $consulta->where('sucursal_id', $sucursal))
            ->when($request->query('estado'), fn ($consulta, string $estado) => $consulta->where('estado', $estado))
            ->orderByDesc('fecha_apertura_at')
            ->paginate((int) $request->query('por_pagina', 50));

        return response()->json($cajas);
    }

    /** Como va el corte ahora, sin cerrarlo. */
    public function show(Caja $caja): JsonResponse
    {
        Gate::authorize('view', $caja);

        return response()->json($this->servicio->estado($caja));
    }

    /** Los movimientos de dinero del corte. */
    public function movimientos(Caja $caja): JsonResponse
    {
        Gate::authorize('view', $caja);

        return response()->json([
            'caja_id' => $caja->id,
            'folio' => $caja->folio,
            'movimientos' => $caja->movimientos()
                ->with('usuario')
                ->orderBy('ocurrio_en')
                ->get()
                ->map(fn ($movimiento): array => [
                    'id' => $movimiento->id,
                    'tipo' => $movimiento->tipo->value,
                    'monto' => $movimiento->monto,
                    'glosa' => $movimiento->glosa,
                    'usuario' => $movimiento->usuario?->nombre,
                    'ocurrio_en' => $movimiento->ocurrio_en?->toIso8601String(),
                ]),
        ]);
    }

    /** Abre el turno. */
    public function store(AperturaCajaRequest $request): JsonResponse
    {
        Gate::authorize('abrir', Caja::class);

        $caja = $this->servicio->abrir(
            $request->user(),
            (string) $request->input('sucursal_id'),
            $request->montoInicial(),
            $request->input('observaciones')
        );

        return response()->json($this->servicio->estado($caja), 201);
    }

    /** Anota un cobro, un retiro o un deposito. */
    public function mover(MovimientoCajaRequest $request, Caja $caja): JsonResponse
    {
        Gate::authorize('mover', $caja);

        $tipo = $request->tipo();

        if ($tipo === null) {
            abort(422, 'El tipo de movimiento no existe.');
        }

        $this->servicio->mover(
            $caja,
            $request->user(),
            $tipo,
            $request->monto(),
            $request->input('glosa')
        );

        return response()->json($this->servicio->estado($caja->refresh()), 201);
    }

    /**
     * Calcula el arqueo SIN cerrar.
     *
     * Sirve para que la persona vea "hay 118.000, tu declaraste 120.000, la
     * diferencia son 2.000 a favor tuyo" antes de confirmar. No escribe nada.
     */
    public function arqueo(ArquearCajaRequest $request, Caja $caja): JsonResponse
    {
        Gate::authorize('arquear', $caja);

        $estado = $this->servicio->estado($caja);

        $arqueo = $caja->arqueear($request->montoDeclarado());

        return response()->json(array_merge($estado, $arqueo));
    }

    /** Cierra el turno y guarda la diferencia. */
    public function cerrar(ArquearCajaRequest $request, Caja $caja): JsonResponse
    {
        Gate::authorize('arquear', $caja);

        $caja = $this->servicio->cerrar(
            $caja,
            $request->user(),
            $request->montoDeclarado(),
            $request->input('observaciones')
        );

        return response()->json([
            'id' => $caja->id,
            'folio' => $caja->folio,
            'estado' => $caja->estado->value,
            'monto_inicial' => $caja->monto_inicial,
            'monto_declarado' => $caja->monto_declarado,
            'monto_sistema' => $caja->monto_sistema,
            'diferencia' => $caja->diferencia,
            'revision' => $caja->revision,
            'fecha_cierre_at' => $caja->fecha_cierre_at?->toIso8601String(),
        ]);
    }

    /** El historial de cortes de una sucursal, con su diferencia. */
    public function cortes(Request $request, string $sucursalId): JsonResponse
    {
        Gate::authorize('viewAny', Caja::class);

        if (! \App\Models\Sucursal::whereKey($sucursalId)->exists()) {
            abort(404, 'La sucursal no existe.');
        }

        $cortes = $this->servicio->cortes($sucursalId);

        return response()->json([
            'sucursal_id' => $sucursalId,
            'cortes' => $cortes->map(fn (Caja $caja): array => [
                'id' => $caja->id,
                'folio' => $caja->folio,
                'estado' => $caja->estado->value,
                'fecha_apertura_at' => $caja->fecha_apertura_at?->toIso8601String(),
                'fecha_cierre_at' => $caja->fecha_cierre_at?->toIso8601String(),
                'monto_inicial' => $caja->monto_inicial,
                'monto_declarado' => $caja->monto_declarado,
                'monto_sistema' => $caja->monto_sistema,
                'diferencia' => $caja->diferencia,
                'revision' => $caja->revision,
                'abrio' => $caja->usuarioApertura?->nombre,
                'cerro' => $caja->usuarioCierre?->nombre,
            ]),
        ]);
    }
}