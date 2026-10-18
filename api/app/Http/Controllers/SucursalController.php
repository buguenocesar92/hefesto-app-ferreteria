<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSucursalRequest;
use App\Models\Sucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Sucursales de la ferreteria.
 *
 * Cada sucursal tiene su propio stock y su propia caja. El indice de este
 * controlador es también el que la pantalla de acceso al sistema muestra
 * para elegir donde trabajar.
 */
class SucursalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Sucursal::class);

        $sucursales = Sucursal::query()
            ->when($request->boolean('solo_activas'), fn ($consulta) => $consulta->where('activo', true))
            ->orderBy('nombre')
            ->get();

        return response()->json(['sucursales' => $sucursales]);
    }

    public function show(Sucursal $sucursal): JsonResponse
    {
        Gate::authorize('view', $sucursal);

        return response()->json($sucursal);
    }

    public function store(StoreSucursalRequest $request): JsonResponse
    {
        Gate::authorize('create', Sucursal::class);

        $sucursal = Sucursal::create($request->datosParaCrear());

        return response()->json($sucursal, 201);
    }

    public function update(StoreSucursalRequest $request, Sucursal $sucursal): JsonResponse
    {
        Gate::authorize('update', $sucursal);

        $sucursal->update($request->datosParaCrear());

        return response()->json($sucursal->refresh());
    }

    /**
     * Desactiva una sucursal.
     *
     * Con una caja abierta, no: dejaria el turno sin poder cerrarse y el
     * dinero de esa caja quedaria sin arqueo.
     */
    public function desactivar(Sucursal $sucursal): JsonResponse
    {
        Gate::authorize('desactivar', $sucursal);

        $cajaAbierta = \App\Models\Caja::query()
            ->where('sucursal_id', $sucursal->id)
            ->where('estado', 'abierta')
            ->exists();

        if ($cajaAbierta) {
            abort(422, 'No se puede desactivar una sucursal con un corte de caja abierto.');
        }

        $sucursal->forceFill(['activo' => false])->save();

        return response()->json($sucursal->refresh());
    }
}