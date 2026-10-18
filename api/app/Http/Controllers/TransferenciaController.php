<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GuardarTransferenciaRequest;
use App\Models\Transferencia;
use App\Services\Transferencias\TransferenciasEntreSucursales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Transferencias entre sucursales.
 *
 * ---------------------------------------------------------------------
 | POR QUE EL DESPACHO Y LA RECEPCIÓN SON ENDPOINTS DISTINTOS
 * ---------------------------------------------------------------------
 | Porque pasan en momentos distintos y por personas distintas. Si fueran
 | uno solo, la mercadería sumaria en el destino en el mismo instante en que
 | sale del origen, y durante el trajet no habria ninguna diferencia entre
 | "va en camino" y "no fue".
 *
 | Un solo endpoint para las dos cosas es exactamente el "ajuste invisible"
 * que este modulo quiere evitar.
 */
class TransferenciaController extends Controller
{
    public function __construct(private readonly TransferenciasEntreSucursales $servicio) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Transferencia::class);

        $transferencias = Transferencia::query()
            ->with(['origen', 'destino', 'usuarioDespacho', 'usuarioRecepcion'])
            ->withCount('items')
            ->when($request->query('estado'), fn ($consulta, string $estado) => $consulta->where('estado', $estado))
            ->when($request->query('sucursal'), fn ($consulta, string $sucursal) => $consulta
                ->where('sucursal_origen_id', $sucursal)
                ->orWhere('sucursal_destino_id', $sucursal))
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('por_pagina', 50));

        return response()->json($transferencias);
    }

    public function show(Transferencia $transferencia): JsonResponse
    {
        Gate::authorize('view', $transferencia);

        $transferencia->load([
            'origen',
            'destino',
            'items.variante.unidadVenta',
            'items.variante.producto',
            'usuarioDespacho',
            'usuarioRecepcion',
        ]);

        return response()->json($this->presentar($transferencia));
    }

    /** Arma el papel. No toca stock: la transferencia nace en borrador. */
    public function store(GuardarTransferenciaRequest $request): JsonResponse
    {
        Gate::authorize('create', Transferencia::class);

        $datos = $request->datosParaCrear();

        $transferencia = $this->servicio->crear(
            (string) $request->user()->tenant_id,
            $datos['sucursal_origen_id'],
            $datos['sucursal_destino_id'],
            $datos['items'],
            (string) $request->user()->id,
            $datos['observaciones']
        );

        return response()->json($this->presentar($transferencia), 201);
    }

    /** Despacha: descuenta el stock del origen. */
    public function despachar(Request $request, Transferencia $transferencia): JsonResponse
    {
        Gate::authorize('despachar', $transferencia);

        $transferencia = $this->servicio->despachar(
            $transferencia,
            (string) $request->user()->id
        );

        return response()->json($this->presentar($transferencia->load('items.variante')));
    }

    /**
     * Recibe: suma el stock en el destino.
     *
     * Acepta `recibido` por variante. Si llega incompleto, el sistema suma lo
     * que llego y deja escrito en el movimiento cuanto falto, para que la
     * sucursal de origen lo vea sin depender de que alguien lo recuerde.
     */
    public function recibir(Request $request, Transferencia $transferencia): JsonResponse
    {
        Gate::authorize('recibir', $transferencia);

        $datos = $request->validate([
            'recibido' => ['nullable', 'array'],
            'recibido.*' => ['string', 'max:32'],
        ], [], [
            'recibido.array' => 'Las cantidades recibidas se envian como una lista por variante.',
        ]);

        /** @var array<string, string> $recibido */
        $recibido = (array) ($datos['recibido'] ?? []);

        $transferencia = $this->servicio->recibir(
            $transferencia,
            (string) $request->user()->id,
            $recibido
        );

        return response()->json($this->presentar($transferencia->load('items.variante')));
    }

    /** Que le falta al destino, antes de confirmar la recepción. */
    public function diferencias(Transferencia $transferencia): JsonResponse
    {
        Gate::authorize('view', $transferencia);

        return response()->json([
            'transferencia_id' => $transferencia->id,
            'estado' => $transferencia->estado->value,
            'items' => $transferencia->items()->with('variante')->get()->map(fn ($item): array => [
                'variante_id' => $item->variante_id,
                'variante' => $item->variante->etiquetaDeVariante(),
                'enviado' => $item->cantidad,
                'unidad' => $item->variante->unidadVenta->codigo,
            ]),
        ]);
    }

    /**
     * Anula la transferencia.
     *
     * No borra nada: escribe los movimientos contrarios y deja el documento
     * en estado `anulada` con el motivo. Un traslado anulado deja el mismo
     * saldo que antes, con dos movimientos más que lo dicen.
     */
    public function anular(Request $request, Transferencia $transferencia): JsonResponse
    {
        Gate::authorize('anular', $transferencia);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:300'],
        ], [], [
            'motivo.required' => 'Explique por que se anula la transferencia.',
            'motivo.min' => 'Explique en al menos diez caracteres por que se anula.',
        ]);

        $transferencia = $this->servicio->anular(
            $transferencia,
            (string) $request->user()->id,
            (string) $datos['motivo']
        );

        return response()->json($this->presentar($transferencia->load('items.variante')));
    }

    private function presentar(Transferencia $transferencia): array
    {
        return [
            'id' => $transferencia->id,
            'folio' => $transferencia->folio,
            'estado' => $transferencia->estado->value,
            'en_camino' => $transferencia->estaEnCamino(),
            'origen' => [
                'id' => $transferencia->sucursal_origen_id,
                'nombre' => $transferencia->origen?->nombre,
                'codigo' => $transferencia->origen?->codigo,
            ],
            'destino' => [
                'id' => $transferencia->sucursal_destino_id,
                'nombre' => $transferencia->destino?->nombre,
                'codigo' => $transferencia->destino?->codigo,
            ],
            'fecha_despacho_at' => $transferencia->fecha_despacho_at?->toIso8601String(),
            'fecha_recepcion_at' => $transferencia->fecha_recepcion_at?->toIso8601String(),
            'despacho_por' => $transferencia->usuarioDespacho?->nombre,
            'recepcion_por' => $transferencia->usuarioRecepcion?->nombre,
            'observaciones' => $transferencia->observaciones,
            'items' => $transferencia->relationLoaded('items')
                ? $transferencia->items->map(fn ($item): array => [
                    'variante_id' => $item->variante_id,
                    'sku' => $item->variante?->sku,
                    'variante' => $item->variante?->etiquetaDeVariante(),
                    'cantidad' => $item->cantidad,
                    'unidad' => $item->variante?->unidadVenta->codigo,
                ])
                : [],
        ];
    }
}