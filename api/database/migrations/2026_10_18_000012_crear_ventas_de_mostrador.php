<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas de mostrador.
 *
 * ---------------------------------------------------------------------
 * UN RENGON ES UNA VARIANTE, NO UN PRODUCTO
 * ---------------------------------------------------------------------
 * El mostrador vende "3/4 zincado bolsa de 50", no "tornillo". Por eso el
 * renglón apunta a `variantes` y guarda la cantidad en la unidad en que se
 * vendio: si el mostrador pidio en kilos y hay stock en bolsas, el renglón
 * queda en kilos y el movimiento descuenta en la unidad de la variante.
 *
 * `unidad_medida_id` se guarda en el renglón a propósito, aunque normalmente
 * sea la unidad de venta de la variante. Si un dia se permite vender en una
 * unidad distinta, el renglón deja de depender de como este la variante hoy,
 * y el papel guardado hace falta para entender la venta de hace un ano.
 *
 * ---------------------------------------------------------------------
 * EL TOTAL SE ESCRIBE, NO SE CALCULA
 * ---------------------------------------------------------------------
 * `subtotal`, `descuento` y `total` quedan guardados en la venta. No se
 * recalculan nunca a partir de los renglones: el total es el que se cobro, y
 * lo que se cobro puede incluir un redondeo, una promocion que ya no existe o
 | un descuento entregado. Volver a calcularlo sería mentir sobre el pasado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();

            $tabla->string('folio', 24);

            // La caja abierta en el momento de la venta. Una venta pertenece
            // a un corte: es lo que permite que el arqueo del turno tenga
            // sentido.
            $tabla->foreignUuid('caja_id')->nullable()->constrained('cajas')->nullOnDelete();
            $tabla->foreignUuid('sucursal_id')->constrained('sucursales');

            // Que lista se uso. Se guarda aunque la venta sea de mostrador:
            // el total ya esta redondeado y aplicado con esa lista.
            $tabla->foreignUuid('lista_precios_id')->nullable()->constrained('listas_precios')->nullOnDelete();

            /*
            | Datos del cliente. Son opcionales a propósito: en un mostrador
            | la mitad de las ventas son de alguien que no da su nombre, y
            | hacerlos escribir el RUT para no cobrarle más sería una forma
            | de espantarlos.
            */
            $tabla->string('cliente_nombre', 160)->nullable();
            $tabla->string('cliente_rut', 16)->nullable();

            $tabla->decimal('subtotal', 14, 2);
            $tabla->decimal('descuento', 14, 2)->default(0);
            $tabla->decimal('total', 14, 2);

            $tabla->enum('estado', ['registrada', 'anulada'])->default('registrada');

            $tabla->foreignUuid('usuario_id')->constrained('usuarios');

            $tabla->timestampTz('vendida_at');
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'folio']);
            $tabla->index(['sucursal_id', 'vendida_at']);
            $tabla->index('caja_id');
        });

        Schema::create('venta_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();
            $tabla->foreignUuid('venta_id')->constrained('ventas')->cascadeOnDelete();
            $tabla->foreignUuid('variante_id')->constrained('variantes');

            $tabla->decimal('cantidad', 18, 3);
            $tabla->foreignUuid('unidad_medida_id')->constrained('unidades_medida');

            // Precio unitario YA APLICADO: si se aplico un margen de lista, lo
            // que queda es el precio que se cobro por unidad. Volver a
            // aplicar el margen al valor guardado sería aplicarlo dos veces.
            $tabla->decimal('precio_unitario', 14, 2);
            $tabla->decimal('subtotal', 14, 2);
            $tabla->decimal('descuento', 14, 2)->default(0);

            // Como se escribio el renglón en la pantalla, para que un pedido
            // con apuntes manuales sea recuperable tal cual se dicto.
            $tabla->string('detalle', 240)->nullable();

            $tabla->timestampsTz();

            $tabla->index(['venta_id', 'variante_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.venta_items
                ADD CONSTRAINT venta_items_cantidad_valida CHECK (cantidad > 0);

            ALTER TABLE public.venta_items
                ADD CONSTRAINT venta_items_subtotal_coincide
                CHECK (subtotal >= 0);

            ALTER TABLE public.ventas
                ADD CONSTRAINT ventas_total_no_negativo CHECK (total >= 0);

            COMMENT ON TABLE public.ventas IS
                'El total queda escrito en el momento del cobro y no se recalcula despues.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_items');
        Schema::dropIfExists('ventas');
    }
};