<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock por sucursal: DONDE ESTA CADA VARIANTE.
 *
 * ---------------------------------------------------------------------
 * LA CLAVE DEL DISENO: EL STOCK ES DE LA VARIANTE, NO DEL PRODUCTO
 * ---------------------------------------------------------------------
 * La fila identifica una terna (variante, sucursal). No hay fila por
 * producto, porque el producto no tiene stock: tiene variantes, y cada una
 * tiene la suya.
 *
 * ---------------------------------------------------------------------
 * UNA FILA POR PAR, NO UN SALDO EN EL PRODUCTO
 * ---------------------------------------------------------------------
 * Si el stock viviera en el producto, "cuanto hay en Providencia" y "cuanto
 * hay en Concepcion" serian el mismo número, y con dos locales el inventario
 * deja de cuadrar sin que nadie note nada. La fila por par hace que la
 * pregunta del mostrador sea una consulta y no un filtro mental.
 *
 * ---------------------------------------------------------------------
 * UNA SOLA FILA, ACTUALIZADA POR MOVIMIENTOS
 * ---------------------------------------------------------------------
 * `cantidad` es el saldo ACTUAL y se actualiza por movimientos (ver
 * `movimientos_stock`). No se edita a mano: hay un gatillo que lo rechaza.
 * `disponible` NO es una columna, se deriva: es lo que se puede vender, o
 * sea la cantidad menos lo comprometido.
 *
 * `stock_sucursales` es el saldo; `movimientos_stock` es la historia. El
 * saldo sin la historia no sirve para nada: cuando aparece una diferencia,
 * lo que se necesita son los movimientos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_sucursales', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();
            $tabla->foreignUuid('variante_id')->constrained('variantes')->cascadeOnDelete();
            $tabla->foreignUuid('sucursal_id')->constrained('sucursales')->cascadeOnDelete();

            /*
            | La cantidad de esta fila se expresa en la UNIDAD DE VENTA de la
            | variante, y esa unidad se anota aquí de nuevo. Es una copia
            | deliberada de `variantes.unidad_venta_id` en el momento en que
            | se creo la fila: si alguna vez la unidad de venta de una variante
            | cambiara, este saldo historico queda explicito en su unidad y no
            | pasa a significar otra cosa en silencio.
            |
            | En la práctica la unidad de venta NO cambia una vez que hay
            | stock cargado: cambiarla exigiria convertir todos los saldos ya
            | registrados, y esos saldos son la evidencia de lo que se dijo
            | que había. Por eso la aplicación no deja cambiar la unidad de
            | venta de una variante que ya tiene movimientos.
            */
            $tabla->foreignUuid('unidad_medida_id')->constrained('unidades_medida');

            $tabla->decimal('cantidad', 18, 3)->default(0);
            $tabla->decimal('cantidad_reservada', 18, 3)->default(0);

            $tabla->timestampsTz();

            // Un saldo por terna. Es lo que impide que dos filas pelen por
            // el mismo stock y que la suma de las dos de más.
            $tabla->unique(['tenant_id', 'variante_id', 'sucursal_id'], 'stock_sucursales_par_unico');

            $tabla->index(['sucursal_id', 'variante_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.stock_sucursales
                ADD CONSTRAINT stock_sucursales_no_negativo
                CHECK (cantidad >= 0);

            ALTER TABLE public.stock_sucursales
                ADD CONSTRAINT stock_sucursales_reserva_no_negativa
                CHECK (cantidad_reservada >= 0);

            ALTER TABLE public.stock_sucursales
                ADD CONSTRAINT stock_sucursales_reserva_menor
                CHECK (cantidad_reservada <= cantidad);

            COMMENT ON COLUMN public.stock_sucursales.cantidad IS
                'Saldo en la unidad de venta de la variante. No se edita: se mueve por movimientos_stock.';

            COMMENT ON COLUMN public.stock_sucursales.cantidad_reservada IS
                'Comprometido a pedidos o ventas no despachadas. El disponible es cantidad - cantidad_reservada, y se deriva.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_sucursales');
    }
};