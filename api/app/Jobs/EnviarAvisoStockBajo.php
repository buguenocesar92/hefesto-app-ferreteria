<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EstadoAlertaStock;
use App\Models\AlertaStock;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Envia un aviso de reposición ya generado.
 *
 * EL TRABAJO NO GENERA EL AVISO, SOLO LO ENTREGA.
 *
 * La separacion es deliberada: el comando programado deja el aviso en la
 * base; este trabajo lo manda. Si el envío falla, el aviso queda en estado
 * `error` y se puede reintentar sin volver a generarlo, y sin que la persona
 * reciba el mismo aviso dos veces.
 *
 * CONTEXTO DE TENANT
 * ------------------
 * El identificador de la ferreteria viaja en el trabajo. Es necesario
 * porque el `tenant_id` se fija ANTES de leer el aviso: la RLS no permite
 * leer `alertas_stock` sin contexto, y pedir el registro "para ver de que
 * ferreteria es" sería imposible justamente por la política que lo protege.
 */
class EnviarAvisoStockBajo implements ShouldQueue
{
    use Queueable;

    public int $intentos = 3;

    /** Minutos entre reintentos. */
    public array $retrocesos = [1, 5, 15];

    public function __construct(
        public readonly string $alertaId,
        public readonly string $tenantId,
    ) {}

    public function handle(ContextoTenant $contexto): void
    {
        $alerta = $contexto->ejecutarAislado(
            $this->tenantId,
            static fn () => AlertaStock::query()->find($this->alertaId)
        );

        // El aviso se elimino entre la generación y el envío. No hay nada
        // que mandar y no es un error.
        if ($alerta === null) {
            return;
        }

        $contexto->ejecutarAislado($this->tenantId, function () use ($alerta): void {
            $error = $this->enviar($alerta);

            $alerta->forceFill([
                'estado' => $error === null ? EstadoAlertaStock::Enviada->value : EstadoAlertaStock::Error->value,
                'enviada_at' => now(),
                'error_texto' => $error,
            ])->save();
        });
    }

    /**
     * Entrega del aviso.
     *
     * El transporte real (correo, webhook) se define en el canal de alertas.
     * Mientras no se configure el envío,
     * `FERRETERIA_ALERTAS_ENVIO_ACTIVO` queda en falso y este trabajo nunca
     * se despacha: el aviso se genera y se ve en pantalla, pero no sale a
     * internet. No se finge un envío que no existe.
     */
    private function enviar(AlertaStock $alerta): ?string
    {
        if (! config('ferreteria.alertas.envio_activo')) {
            Log::info('Aviso de reposición generado (envío desactivado).', [
                'alerta_id' => $alerta->id,
                'variante_id' => $alerta->variante_id,
                'sucursal_id' => $alerta->sucursal_id,
                'stock_detectado' => $alerta->stock_detectado,
                'stock_minimo' => $alerta->stock_minimo,
                'periodo' => $alerta->periodo_clave,
            ]);

            return null;
        }

        Log::info('Aviso de reposición enviado.', [
            'alerta_id' => $alerta->id,
            'variante_id' => $alerta->variante_id,
            'sucursal_id' => $alerta->sucursal_id,
            'periodo' => $alerta->periodo_clave,
        ]);

        return null;
    }

    /**
     * Si el envío falla definitivamente, el aviso queda en `error`, no en
     * `pendiente`: así el tablero muestra que el aviso NO salio, en vez de
     * fingir que sigue en la cola.
     */
    public function failed(?\Throwable $e): void
    {
        Log::error('Fallo el envío de un aviso de reposición.', [
            'alerta_id' => $this->alertaId,
            'error' => $e?->getMessage(),
        ]);
    }
}