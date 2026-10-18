<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja constancia de las operaciones que mueven cosas.
 *
 * Una ferreteria necesita poder responder "quien toco esto y cuando" cuando
 * aparece una diferencia de caja. Los movimientos de stock ya guardan usuario
 * y fecha; este middleware cubre el resto: que venta se confirmo, que caja
 * se cerro, que transferencia se despacho.
 *
 * Se escriben SOLO operaciones de escritura. Un listado de stock no deja
 * rastro: si lo dejara, el registro serviria para nada porque estaria lleno
 * de ruido.
 */
class AuditarOperacion
{
    /** Métodos que dejan rastro. */
    private const METODOS_CON_RASTRO = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = auth()->guard('web')->user();

        $respuesta = $next($request);

        if (! in_array($request->method(), self::METODOS_CON_RASTRO, true)) {
            return $respuesta;
        }

        Log::channel(config('logging.default'))->info('operacion.ferreteria', [
            'usuario_id' => $usuario?->id,
            'ferreteria_id' => $usuario?->tenant_id,
            'rol' => $usuario?->rol?->value,
            'metodo' => $request->method(),
            'ruta' => $request->path(),
            'estado' => $respuesta->getStatusCode(),
            'ip' => $request->ip(),
            'momento' => now()->toIso8601String(),
        ]);

        return $respuesta;
    }
}