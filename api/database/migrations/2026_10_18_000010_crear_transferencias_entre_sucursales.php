<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transferencias entre sucursales.
 *
 * ---------------------------------------------------------------------
 * POR QUE ES UN DOCUMENTO Y NO UN AJUSTE
 * ---------------------------------------------------------------------
 * Mover 12 cajas de la bodega central a la tienda no es "corregir el stock":
 * es un hecho que paso, con una fecha, una persona que lo mando, otra que
 * lo recibio y un papel que lo respalda.
 *
 * Si el traslado se hiciera ajustando el stock de las dos sucursales sin
 * documento, habria dos saldos que cambian sin explicación. Cuando la
 * tienda dijera "llegaron 8, no 12", no habria nada que revisar.
 *
 * Con el documento, la pregunta tiene respuesta: cuantas se mandaron,
 * cuantas se recibieron, quien recibio y cuando. Y si las cifras no
 * coinciden, la diferencia queda a la vista, en el propio documento.
 *
 * ---------------------------------------------------------------------
 * DOS SALDOS, DOS MOVIMIENTOS, UNA TRANSACCIÓN
 * ---------------------------------------------------------------------
 * Despachar descuenta en el origen y recepcionar suma en el destino. Cada
 * paso escribe SU movimiento, en SU momento. El documento pasa por estados:
 *
 *   borrador -> despachada -> recibida
 *
 * `despachada` sin `recibida` es el estado normal de una mercadería que va
 * en camino, y es información valiosa: la tienda sabe que le llega algo que
 * aun no esta en su stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferencias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();

            // Folio correlativo por ferreteria. Es el número que va impreso
            // en el papel que firman las dos partes.
            $tabla->string('folio', 24);

            $tabla->foreignUuid('sucursal_origen_id')->constrained('sucursales');
            $tabla->foreignUuid('sucursal_destino_id')->constrained('sucursales');

            $tabla->enum('estado', ['borrador', 'despachada', 'recibida', 'anulada'])
                ->default('borrador');

            $tabla->timestampTz('fecha_despacho_at')->nullable();
            $tabla->timestampTz('fecha_recepcion_at')->nullable();

            $tabla->foreignUuid('usuario_despacho_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->foreignUuid('usuario_recepcion_id')->nullable()->constrained('usuarios')->nullOnDelete();

            $tabla->string('observaciones', 500)->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'folio']);
            $tabla->index(['sucursal_origen_id', 'estado']);
            $tabla->index(['sucursal_destino_id', 'estado']);
        });

        // Una sucursal no se manda mercadería a si misma. Es un error de
        // tipeo clasico y aquí se ve gratis. Además, no se puede receptionar
        // algo que nunca se despacho.
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.transferencias
                ADD CONSTRAINT transferencias_sucursales_distintas
                CHECK (sucursal_origen_id <> sucursal_destino_id);

            ALTER TABLE public.transferencias
                ADD CONSTRAINT transferencias_recepcion_posterior
                CHECK (fecha_recepcion_at IS NULL OR fecha_despacho_at IS NOT NULL);
        SQL);

        Schema::create('transferencia_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();
            $tabla->foreignUuid('transferencia_id')->constrained('transferencias')->cascadeOnDelete();
            $tabla->foreignUuid('variante_id')->constrained('variantes')->cascadeOnDelete();

            /*
            | Cantidad enviada, en la UNIDAD DE VENTA de la variante, que es la
            | misma en que esta su stock. No se guarda una unidad distinta por
            | item porque la conversión ya esta resuelta en la variante: si se
            | guardara "12 cajas" con su unidad, cada movimiento tendria que
            | volver a convertir para tocar el saldo.
            */
            $tabla->decimal('cantidad', 18, 3);

            $tabla->timestampsTz();

            $tabla->unique(['transferencia_id', 'variante_id']);
            $tabla->index('variante_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.transferencia_items
                ADD CONSTRAINT transferencia_items_cantidad_valida CHECK (cantidad > 0);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('transferencia_items');
        Schema::dropIfExists('transferencias');
    }
};