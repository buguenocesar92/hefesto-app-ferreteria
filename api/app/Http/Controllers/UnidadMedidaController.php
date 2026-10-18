<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnidadMedidaRequest;
use App\Models\UnidadMedida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Unidades de medida y sus conversiones.
 *
 * ---------------------------------------------------------------------
 | LA PANTALLA DE CONVERSIÓN ES UNA PARTE DEL CATÁLOGO, NO UN EXTRA
 * ---------------------------------------------------------------------
 | Convertir "12 cajas de cemento" a "¿cuantos kilos son?" es la pregunta
 | que se hace en el mostrador todos los dias. Que la respuesta salga de los
 | factores declarados, y no de una cuenta mental, es lo que evita que una
 * persona venda 96 kilos de cemento cuando tiene 120.
 */
class UnidadMedidaController extends Controller
{
    /** Todas las unidades, agrupadas por base. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', UnidadMedida::class);

        $unidades = UnidadMedida::query()
            ->with('base')
            ->when($request->boolean('solo_activas'), fn ($consulta) => $consulta->where('activo', true))
            ->orderByDesc('es_base')
            ->orderBy('nombre')
            ->get();

        $agrupadas = [];

        foreach ($unidades as $unidad) {
            $base = $unidad->es_base ? $unidad->nombre : ($unidad->base?->nombre ?? 'Sin base');

            $agrupadas[$base][] = [
                'id' => $unidad->id,
                'codigo' => $unidad->codigo,
                'nombre' => $unidad->nombre,
                'es_base' => $unidad->es_base,
                'unidad_base_id' => $unidad->unidad_base_id,
                'unidades_por_base' => $unidad->unidades_por_base,
                'activo' => $unidad->activo,
            ];
        }

        return response()->json([
            'unidades' => $unidades->values(),
            'por_base' => $agrupadas,
        ]);
    }

    public function show(UnidadMedida $unidadMedida): JsonResponse
    {
        Gate::authorize('view', $unidadMedida);

        $unidadMedida->load(['base', 'derivadas']);

        return response()->json([
            'unidad' => [
                'id' => $unidadMedida->id,
                'codigo' => $unidadMedida->codigo,
                'nombre' => $unidadMedida->nombre,
                'es_base' => $unidadMedida->es_base,
                'unidades_por_base' => $unidadMedida->unidades_por_base,
                'activo' => $unidadMedida->activo,
            ],
            'base' => $unidadMedida->base === null ? null : [
                'id' => $unidadMedida->base->id,
                'codigo' => $unidadMedida->base->codigo,
                'nombre' => $unidadMedida->base->nombre,
            ],
            'derivadas' => $unidadMedida->derivadas->map(fn (UnidadMedida $u): array => [
                'id' => $u->id,
                'codigo' => $u->codigo,
                'nombre' => $u->nombre,
                'unidades_por_base' => $u->unidades_por_base,
            ]),
        ]);
    }

    public function store(StoreUnidadMedidaRequest $request): JsonResponse
    {
        Gate::authorize('create', UnidadMedida::class);

        $unidad = UnidadMedida::create($request->datosParaCrear());

        return response()->json($this->presentar($unidad->refresh()), 201);
    }

    public function update(StoreUnidadMedidaRequest $request, UnidadMedida $unidadMedida): JsonResponse
    {
        Gate::authorize('update', $unidadMedida);

        $unidadMedida->update($request->datosParaCrear());

        return response()->json($this->presentar($unidadMedida->refresh()));
    }

    /**
     * Desactiva una unidad.
     *
     * Solo se puede desactivar si ninguna variante la usa. Desactivar una
     * unidad en uso dejaria variantes activas que no pueden convertir, y el
     * mostrador lo descubre con el cliente esperando.
     */
    public function desactivar(UnidadMedida $unidadMedida): JsonResponse
    {
        Gate::authorize('desactivar', $unidadMedida);

        $enUso = \App\Models\Variante::query()
            ->where('unidad_venta_id', $unidadMedida->id)
            ->orWhere('unidad_contenido_id', $unidadMedida->id)
            ->exists();

        if ($enUso) {
            abort(422, 'La unidad esta en uso en el catalogo: desactivarla dejaria variantes que no pueden convertir.');
        }

        $unidadMedida->forceFill(['activo' => false])->save();

        return response()->json($this->presentar($unidadMedida->refresh()));
    }

    private function presentar(UnidadMedida $unidad): array
    {
        return [
            'id' => $unidad->id,
            'codigo' => $unidad->codigo,
            'nombre' => $unidad->nombre,
            'es_base' => $unidad->es_base,
            'unidad_base_id' => $unidad->unidad_base_id,
            'unidades_por_base' => $unidad->unidades_por_base,
            'activo' => $unidad->activo,
        ];
    }
}