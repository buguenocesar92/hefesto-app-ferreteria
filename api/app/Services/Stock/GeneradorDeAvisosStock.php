<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Models\AlertaStock;
use App\Models\StockSucursal;
use App\Services\Tenancy\ContextoTenant;

/**
 * Generación de avisos de reposición.
 *
 * ---------------------------------------------------------------------
 | LA TAREA GENERA EL AVISO; EL TRABAJO LO ENTREGA
 * ---------------------------------------------------------------------
 | Esta clase deja avisos en la base y nada más. El envío es otra cosa, y
 | vive en App\Jobs\EnviarAvisoStockBajo. Si el envío falla, el aviso queda
 | en estado `error` y se puede reintentar sin volver a generarlo, y sin que
 | el mismo aviso salga dos veces.
 *
 * ---------------------------------------------------------------------
 | TRES CAPAS CONTRA LA MISMA EJECUCIÓN DOBLE
 * ---------------------------------------------------------------------
 |   1. `onOneServer()` en la programacion: si hay dos replicas, corre una
 |   2. `withoutOverlapping()`: una corrida lenta no se pisa con la siguiente
 |   3. indice único por variante + sucursal + periodo
 |
 | Las tres apuntan al mismo objetivo y la tercera es la que no puede fallar:
 | aunque las dos primeras fallen, la base no acepta dos avisos del mismo dia
 | para la misma variante.
 */
class GeneradorDeAvisosStock
{
    public function __construct(private readonly ContextoTenant $contexto) {}

    /**
     * Genera los avisos de una ferreteria para un periodo.
     *
     * @return array{
     *     variantes_revisadas: int,
     *     bajo_minimo: int,
     *     avisos_nuevos: int,
     *     avisos_omitidos: int,
     *     ids: list<string>
     * }
     */
    public function generarParaTenant(
        string $tenantId,
        string $periodoClave,
        ?\DateTimeInterface $fecha = null,
    ): array {
        return $this->contexto->ejecutarAislado(
            $tenantId,
            fn (): array => $this->generar($periodoClave, $fecha)
        );
    }

    /** Genera los avisos de todas las ferreterias de la instalación. */
    public function generarParaTodas(string $periodoClave, ?\DateTimeInterface $fecha = null): array
    {
        $resumen = [
            'variantes_revisadas' => 0,
            'bajo_minimo' => 0,
            'avisos_nuevos' => 0,
            'avisos_omitidos' => 0,
            'ids' => [],
        ];

        foreach ($this->contexto->tenantsActivos() as $fila) {
            $tenantId = (string) $fila->id;

            $parcial = $this->generarParaTenant($tenantId, $periodoClave, $fecha);

            $resumen['variantes_revisadas'] += $parcial['variantes_revisadas'];
            $resumen['bajo_minimo'] += $parcial['bajo_minimo'];
            $resumen['avisos_nuevos'] += $parcial['avisos_nuevos'];
            $resumen['avisos_omitidos'] += $parcial['omitidos'];
            $resumen['ids'] = array_merge($resumen['ids'], $parcial['ids']);
        }

        return $resumen;
    }

    /**
     * El trabajo de verdad, dentro del contexto de tenant ya fijado.
     *
     * @return array{
     *     variantes_revisadas: int,
     *     bajo_minimo: int,
     *     avisos_nuevos: int,
     *     omitidos: int,
     *     ids: list<string>
     * }
     */
    private function generar(string $periodoClave, ?\DateTimeInterface $fecha): array
    {
        $salidas = StockSucursal::query()
            ->with(['variante.producto'])
            ->join('variantes', 'variantes.id', '=', 'stock_sucursales.variante_id')
            ->where('variantes.activo', true)
            ->select('stock_sucursales.*')
            ->get();

        $bajoMinimo = 0;
        $nuevos = 0;
        $omitidos = 0;
        $ids = [];

        foreach ($salidas as $saldo) {
            $producto = $saldo->variante?->producto;

            if ($producto === null) {
                continue;
            }

            if (! $saldo->bajoMinimo($producto)) {
                continue;
            }

            $bajoMinimo++;

            $existente = AlertaStock::query()
                ->where('variante_id', $saldo->variante_id)
                ->where('sucursal_id', $saldo->sucursal_id)
                ->where('periodo_clave', $periodoClave)
                ->first();

            if ($existente !== null) {
                // Ya se aviso hoy. La tarea es idempotente: correrla de más
                // no genera un segundo aviso del mismo periodo.
                $omitidos++;

                continue;
            }

            $aviso = AlertaStock::create([
                'tenant_id' => $saldo->tenant_id,
                'variante_id' => $saldo->variante_id,
                'sucursal_id' => $saldo->sucursal_id,
                'stock_detectado' => $saldo->disponible(),
                'stock_minimo' => $producto->stock_minimo,
                'periodo_clave' => $periodoClave,
                'estado' => 'pendiente',
            ]);

            $nuevos++;
            $ids[] = $aviso->id;
        }

        return [
            'variantes_revisadas' => $salidas->count(),
            'bajo_minimo' => $bajoMinimo,
            'avisos_nuevos' => $nuevos,
            'omitidos' => $omitidos,
            'ids' => $ids,
        ];
    }
}