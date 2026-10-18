<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos de reposición.
 *
 * ---------------------------------------------------------------------
 * QUE ES Y QUE NO ES
 * ---------------------------------------------------------------------
 * El aviso de "esta variante quedo bajo el mínimo" se GENERA con un comando
 * programado y se ve en pantalla. Lo que decide `FERRETERIA_ALERTAS_ENVIO_ACTIVO`
 * es si el aviso sale a internet por un canal que la ferreteria haya
 * configurado. Con el valor por omisión (`false`) no hay transporte: el aviso
 * queda en `pendiente` y no se finge un envío que no existe.
 *
 * ---------------------------------------------------------------------
 * POR QUE LA CLAVE INCLUYE EL PERIODO
 * ---------------------------------------------------------------------
 * `periodo_clave` es la fecha del dia en que se reviso (en formato
 * YYYY-MM-DD). Con ella, el indice único impide generar dos avisos del mismo
 * dia para la misma variante y sucursal, y la tarea puede correr mil veces:
 * al segundo intento no inserta nada.
 *
 * Es la misma salvaguarda que usa la tarea de alertas de la flota: tres capas
 * (sin solapamiento, candado, indice único) y la última es la que no puede
 * fallar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas_stock', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();
            $tabla->foreignUuid('variante_id')->constrained('variantes')->cascadeOnDelete();
            $tabla->foreignUuid('sucursal_id')->constrained('sucursales')->cascadeOnDelete();

            // Los dos números del aviso, copiados al momento de generarlo.
            // Se copian para que el aviso siga siendo el que era aunque
            // después cambien el mínimo o el stock.
            $tabla->decimal('stock_detectado', 18, 3);
            $tabla->decimal('stock_minimo', 18, 3);

            $tabla->date('periodo_clave');

            $tabla->enum('estado', ['pendiente', 'enviada', 'error'])->default('pendiente');
            $tabla->string('error_texto', 300)->nullable();
            $tabla->timestampTz('enviada_at')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(
                ['tenant_id', 'variante_id', 'sucursal_id', 'periodo_clave'],
                'alertas_stock_una_por_periodo'
            );

            $tabla->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_stock');
    }
};