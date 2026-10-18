<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EnviarAvisoStockBajo;
use App\Models\Ferreteria;
use App\Services\Stock\GeneradorDeAvisosStock;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Tarea programada que genera los avisos de reposición.
 *
 * POR QUE ES UNA TAREA PROGRAMADA Y NO LA VISTA
 * --------------------------------------------
 * Un aviso que depende de que alguien abra una pantalla no es un aviso. La
 * bolsa de tornillos se acaba un martes a las seis de la tarde, y el que
 * tiene que saberlo es el que abre la bodega el Wednesday, no el que mira un
 * tablero.
 *
 * La programacion vive en `routes/console.php` y su expresion de cron se
 * puede mover desde `.env`, sin tocar código.
 *
 * TRES SALVAGUARDAS CONTRA EJECUCIONES SIMULTANEAS
 * ----------------------------------------------
 * 1. `onOneServer()`: si por algun motivo se despliegan dos replicas de la
 *    API, la tarea corre en una sola.
 * 2. `withoutOverlapping()`: impide que una corrida lenta se pise con la
 *    siguiente. El candado usa la cache (Redis).
 * 3. El indice único por variante, sucursal y periodo: la base no acepta dos
 *    avisos del mismo dia aunque las dos anteriores fallen.
 */
class FerreteriaAvisarStockBajo extends Command
{
    protected $signature = 'ferreteria:avisar-stock-bajo
                            {--ferreteria= : Generar solo para una ferreteria, por su slug}
                            {--fecha= : Tratar esta fecha como "hoy" (YYYY-MM-DD), para pruebas}
                            {--json : Salida en JSON, para consumo de otros sistemas}';

    protected $description = 'Genera los avisos de reposición de las variantes que quedaron bajo el mínimo.';

    public function handle(GeneradorDeAvisosStock $generador): int
    {
        $fecha = $this->fechaDeReferencia();

        $periodo = $fecha === null ? now()->toDateString() : $fecha->toDateString();

        $resultado = $this->opcion('ferreteria') !== null
            ? $this->generarParaUnaFerreteria($generador, (string) $this->opcion('ferreteria'), $periodo)
            : $generador->generarParaTodas($periodo, $fecha);

        if ($this->opcion('json')) {
            $this->line((string) json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Revision de reposición terminada para el periodo ' . $periodo . '.');
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Saldos revisados', $resultado['variantes_revisadas']],
                ['Variantes bajo el mínimo', $resultado['bajo_minimo']],
                ['Avisos nuevos', $resultado['avisos_nuevos']],
                ['Avisos que ya existian (no duplicados)', $resultado['avisos_omitidos']],
                ['Avisos despachados a la cola', count($resultado['ids'])],
            ]
        );

        // La segunda linea es la prueba operativa de que la tarea es
        // idempotente: al correrla de más, con avisos ya generados, la
        // cantidad de avisos nuevos tiene que ser cero.
        if ($resultado['avisos_nuevos'] === 0 && $resultado['avisos_omitidos'] > 0) {
            $this->line('Sin novedades: todos los avisos de este periodo ya estaban generados.');
        }

        if (! config('ferreteria.alertas.envio_activo')) {
            $this->line('El envío de avisos esta desactivado: los avisos quedan en pantalla y no salen a internet.');
        }

        return self::SUCCESS;
    }

    /**
     * Fecha con que se calcula "hoy".
     *
     * Existe la opcion `--fecha` para poder probar el comportamiento en una
     * fecha concreta. Sin ella, el comando usa la fecha real.
     */
    private function fechaDeReferencia(): ?Carbon
    {
        $valor = $this->opcion('fecha');

        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $valor)->startOfDay();
        } catch (\Throwable) {
            $this->error("La fecha «{$valor}» no es válida. Se espera el formato YYYY-MM-DD.");

            // Falla de verdad y no sigue con la fecha de hoy "por si acaso":
            // avisar sobre el stock equivocado es peor que no avisar.
            throw new \RuntimeException('La fecha de referencia no es válida.');
        }
    }

    /**
     * @return array{
     *     variantes_revisadas: int,
     *     bajo_minimo: int,
     *     avisos_nuevos: int,
     *     avisos_omitidos: int,
     *     ids: list<string>
     * }
     */
    private function generarParaUnaFerreteria(
        GeneradorDeAvisosStock $generador,
        string $slug,
        string $periodo,
    ): array {
        $ferreteria = Ferreteria::query()->where('slug', $slug)->first();

        if ($ferreteria === null) {
            $this->error("No existe ninguna ferreteria con el identificador «{$slug}».");

            return [
                'variantes_revisadas' => 0,
                'bajo_minimo' => 0,
                'avisos_nuevos' => 0,
                'avisos_omitidos' => 0,
                'ids' => [],
            ];
        }

        $resultado = $generador->generarParaTenant($ferreteria->id, $periodo, null);

        // El comando despacha los avisos recien generados. Los que ya
        // existian no se vuelven a despachar: ese es justamente el punto del
        // indice único.
        foreach ($resultado['ids'] as $alertaId) {
            EnviarAvisoStockBajo::dispatch($alertaId, $ferreteria->id);
        }

        return $resultado;
    }

    private function opcion(string $nombre): mixed
    {
        return $this->option($nombre);
    }
}