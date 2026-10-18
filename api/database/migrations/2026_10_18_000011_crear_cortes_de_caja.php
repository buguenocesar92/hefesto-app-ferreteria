<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cortes de caja: apertura, movimientos, arqueo y diferencia.
 *
 * ---------------------------------------------------------------------
 * QUE ES UN CORTE
 * ---------------------------------------------------------------------
 * Un corte es el periodo de un turno en una sucursal. Empieza cuando alguien
 * abre la caja con un monto inicial y termina cuando entrega el dinero y
 * cuenta lo que hay.
 *
 * ---------------------------------------------------------------------
 * LA DIFERENCIA, Y POR QUE SE GUARDA COMPLETA
 * ---------------------------------------------------------------------
 * Es `monto_declarado - monto_sistema`. Positivo significa que hay más
 * dinero del que el sistema espera (sobrante). Negativo, que falta.
 *
 * Se guarda SIEMPRE, con su signo, aunque sea de un peso. La tolerancia de
 * `config/ferreteria.php` no oculta ni redondea la diferencia: solo marca el
 * corte como `dentro_de_tolerancia` o `requiere_revision`. El dueño mira la
 * diferencia todas las noches y necesita el número completo, no un "0".
 *
 * ---------------------------------------------------------------------
 | POR QUE LOS MOVIMIENTOS DE CAJA SON UNA TABLA Y NO UN CAMPO
 * ---------------------------------------------------------------------
  * Porque el dinero no se queda quieto: entran ventas, hay cobros de
 | tarjetas, el dueño saca plata para el proveedor, a veces hay que
 | corregir un cobro mal hecho.
 |
  * Si el total se derivara de las ventas, dejaria de cuadrar en cuanto
 | hubiera un retiro, que es justo cuando más importa que cuadre.
 *
 * ---------------------------------------------------------------------
 * UNA CAJA ABIERTA POR SUCURSAL
 * ---------------------------------------------------------------------
  * Lo hace el indice único parcial de más abajo. Dos personas abriendo caja
  * al mismo tiempo en la misma tienda no es una situacion que se pueda
  * diagnosticar después: es dinero que no se sabe de quien es.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();
            $tabla->foreignUuid('sucursal_id')->constrained('sucursales');

            // Folio por sucursal, correlativo.
            $tabla->string('folio', 24);

            $tabla->timestampTz('fecha_apertura_at');
            $tabla->decimal('monto_inicial', 14, 2)->default(0);

            $tabla->timestampTz('fecha_cierre_at')->nullable();

            /*
            | Se guardan los TRES números del arqueo, no solo la diferencia:
            |
            |   monto_declarado  lo que la persona conto y entrego
            |   monto_sistema    lo que el sistema dice que debia haber
            |   diferencia       declarado - sistema
            |
            | Guardar solo la diferencia impediria saber después si el error
            | fue de conteo o de venta. Y son cosas muy distintas: una es un
            | problema del turno, la otra del mostrador.
            */
            $tabla->decimal('monto_declarado', 14, 2)->nullable();
            $tabla->decimal('monto_sistema', 14, 2)->nullable();
            $tabla->decimal('diferencia', 14, 2)->nullable();

            // Como quedo el corte frente a la tolerancia configurada. Es una
            // etiqueta de lectura, no un filtro.
            $tabla->enum('revision', ['dentro_de_tolerancia', 'requiere_revision'])
                ->nullable();

            $tabla->enum('estado', ['abierta', 'cerrada', 'anulada'])->default('abierta');

            $tabla->foreignUuid('usuario_apertura_id')->constrained('usuarios');
            $tabla->foreignUuid('usuario_cierre_id')->nullable()->constrained('usuarios')->nullOnDelete();

            $tabla->string('observaciones', 500)->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'sucursal_id', 'folio']);
            $tabla->index(['tenant_id', 'estado']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.cajas
                ADD CONSTRAINT caja_cierre_completo
                CHECK (
                    estado = 'abierta'
                    OR (fecha_cierre_at IS NOT NULL AND monto_declarado IS NOT NULL AND monto_sistema IS NOT NULL)
                );

            COMMENT ON COLUMN public.cajas.diferencia IS
                'Monto declarado menos monto del sistema, con signo. Se guarda siempre, sin redondear.';
        SQL);

        /*
        | Una caja ABIERTA por sucursal, y solamente una.
        |
        | El indice es PARCIAL: solo mira las cajas abiertas, de modo que la
        | sucursal puede tener cuantos cortes cerrados historial abajo
        | quiera. Sin el filtro `WHERE`, un único indice sobre `sucursal_id`
        | dejaria entrar un solo corte por tienda en toda la vida.
        */
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX caja_abierta_por_sucursal
                ON public.cajas (sucursal_id)
                WHERE estado = 'abierta';

            COMMENT ON INDEX public.caja_abierta_por_sucursal IS
                'Dos cajas abiertas en la misma sucursal mezclarian las ventas de dos turnos.';
        SQL);

        Schema::create('caja_movimientos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();
            $tabla->foreignUuid('caja_id')->constrained('cajas')->cascadeOnDelete();

            /*
            | `venta` suma lo cobrado. `retiro` resta lo que sale de la caja
            | (el pago al proveedor, la retirada al dueño). `cobro` y `deposito`
            | son entradas que no son una venta de mostrador. `ajuste` corrige
            | un cobro mal hecho y exige glosa.
            */
            $tabla->enum('tipo', ['venta', 'cobro', 'retiro', 'deposito', 'ajuste']);

            // Con signo: negativo para lo que sale.
            $tabla->decimal('monto', 14, 2);

            $tabla->string('referencia_tipo', 40)->nullable();
            $tabla->uuid('referencia_id')->nullable();

            // Obligatoria en retiro y ajuste: son los dos casos en que hay
            // que poder responder "y esto por que".
            $tabla->string('glosa', 300)->nullable();

            $tabla->foreignUuid('usuario_id')->constrained('usuarios');

            $tabla->timestampTz('ocurrio_en');
            $tabla->timestampsTz();

            $tabla->index(['caja_id', 'ocurrio_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_movimientos');
        Schema::dropIfExists('cajas');
    }
};