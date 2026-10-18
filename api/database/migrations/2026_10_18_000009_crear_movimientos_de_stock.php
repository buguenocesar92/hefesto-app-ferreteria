<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos de stock: la HISTORIA.
 *
 * ---------------------------------------------------------------------
 * EL STOCK NO SE EDITA: SE MUEVE
 * ---------------------------------------------------------------------
 * No hay ninguna pantalla donde escribir un saldo, y eso no es una
 * convencion del código: hay un GATILLO en esta migración que rechaza
 * cualquier `UPDATE` o `DELETE` sobre `stock_sucursales` que no venga
 * accompanied por el insert de un movimiento en la misma transacción.
 *
 * Un `UPDATE` escrito a mano desde un `psql` tampoco pasa.
 *
 * ---------------------------------------------------------------------
 * POR QUE NO SE PUEDE EDITAR NI BORRAR UN MOVIMIENTO
 * ---------------------------------------------------------------------
 * El movimiento es la evidencia. Si se pudiera corregir un movimiento, la
 * historia dejaria de ser historia y el arqueo de caja y el contraste de
 * inventario no tendrian contra que revisar. Para corregir algo se escribe
 * el movimiento contrario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_stock', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();
            $tabla->foreignUuid('variante_id')->constrained('variantes')->cascadeOnDelete();
            $tabla->foreignUuid('sucursal_id')->constrained('sucursales')->cascadeOnDelete();

            $tabla->foreignUuid('unidad_medida_id')->constrained('unidades_medida');

            /*
            | Los motivos. Entrega y venta son lo normal; el resto es lo que
            | hace que la diferencia tenga explicación:
            |
            |   traslado_salida / traslado_entrada  una transferencia
            |   ajuste                             correccion con justificacion
            |   devolucion                          el cliente devolvio
            |   merma                               se rompio o se vencio
            |   conteo                              lo que se conto en el piso
            */
            $tabla->enum('tipo', [
                'ingreso',
                'venta',
                'traslado_salida',
                'traslado_entrada',
                'ajuste',
                'devolucion',
                'merma',
                'conteo',
            ]);

            /*
            | Con signo: POSITIVO suma al saldo, NEGATIVO resta. Se guarda con
            | signo y no como una columna de tipo más una cantidad positiva,
            | porque "salio 5" y "entró 5" tienen que sumar en la misma
            | columna sin que el cálculo del saldo tenga que depender del tipo.
            */
            $tabla->decimal('cantidad', 18, 3);

            /*
            | Que documento causo este movimiento. Es la union con el
            | documento que lo provoco (una venta, una transferencia), sin
            | claves foraneas porque el documento puede ser de varias tablas.
            |
            | Para las ventas y las transferencias SI se usa
            | `referencia_tipo` + `referencia_id`, y el indice de más abajo
            | hace que se pueda encontrar el movimiento de una venta sin
            | revisar el tipo.
            */
            $tabla->string('referencia_tipo', 40)->nullable();
            $tabla->uuid('referencia_id')->nullable();

            // Explicación en palabras. Obligatoria en los motivos que cambian
            // el saldo sin documento: ajuste, merma, devolucion, conteo.
            $tabla->string('glosa', 300)->nullable();

            $tabla->foreignUuid('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();

            // Cuando ocurrio, que no siempre es cuando se registro: un
            // ingreso se puede belatedar con la fecha de la guia.
            $tabla->timestampTz('ocurrio_en');

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'variante_id', 'sucursal_id', 'ocurrio_en']);
            $tabla->index(['referencia_tipo', 'referencia_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.movimientos_stock
                ADD CONSTRAINT movimientos_stock_cantidad_no_cero CHECK (cantidad <> 0);

            ALTER TABLE public.movimientos_stock
                ADD CONSTRAINT movimientos_stock_cantidad_acotada CHECK (abs(cantidad) < 1000000000);

            COMMENT ON TABLE public.movimientos_stock IS
                'Historia de stock: no se edita ni se borra. Para corregir, se escribe el movimiento contrario.';
        SQL);

        $this->crearGatillosDeInmutabilidad();
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS public.stock_registrar_movimiento()');
        DB::unprepared('DROP FUNCTION IF EXISTS public.stock_saldo_sin_movimiento()');

        Schema::dropIfExists('movimientos_stock');
    }

    /**
     * Gatillo que hace que el saldo no se pueda tocar a mano.
     *
     * El mecanismo es una variable de transacción: la aplicación la levanta
     * dentro de la misma transacción en que inserta el movimiento. Un
     * UPDATE que llegue sin la variable se rechaza.
     *
     * La razon de usar una variable y no buscar "el último movimiento"
     * en la tabla es que buscarlo no alcanza: el movimiento podria haberse
     * insertado en otra transacción. La variable es una declaracion
     * explicita de "este cambio de saldo viene acompanado de su
     * movimiento, en esta misma transacción".
     */
    private function crearGatillosDeInmutabilidad(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.stock_saldo_sin_movimiento() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF current_setting('app.stock_movimiento', true) IS DISTINCT FROM 'true' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'restrict_violation',
                        MESSAGE = 'El saldo de stock no se edita: se mueve. Este cambio no viene acompanado de un movimiento.',
                        HINT = 'Use App\Services\Stock\RegistroDeMovimientos, que escribe el movimiento y el saldo en la misma transaccion.';
                END IF;

                RETURN NEW;
            END
            $$;

            COMMENT ON FUNCTION public.stock_saldo_sin_movimiento() IS
                'Falla cerrado: sin la marca de movimiento, el saldo no se puede cambiar.';

            CREATE OR REPLACE FUNCTION public.stock_registrar_movimiento() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF current_setting('app.stock_movimiento', true) IS DISTINCT FROM 'true' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'restrict_violation',
                        MESSAGE = 'Los movimientos de stock no se editan ni se borran.',
                        HINT = 'Para corregir, escriba el movimiento contrario.';
                END IF;

                RETURN NEW;
            END
            $$;

            COMMENT ON FUNCTION public.stock_registrar_movimiento() IS
                'Los movimientos son la evidencia: no se editan ni se borran.';

            CREATE TRIGGER stock_sucursales_sin_movimiento
                BEFORE UPDATE ON public.stock_sucursales
                FOR EACH ROW EXECUTE FUNCTION public.stock_saldo_sin_movimiento();

            CREATE TRIGGER stock_sucursales_sin_borrado
                BEFORE DELETE ON public.stock_sucursales
                FOR EACH ROW EXECUTE FUNCTION public.stock_saldo_sin_movimiento();

            CREATE TRIGGER movimientos_stock_inmutables
                BEFORE UPDATE OR DELETE ON public.movimientos_stock
                FOR EACH ROW EXECUTE FUNCTION public.stock_registrar_movimiento();
        SQL);
    }
};