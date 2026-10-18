<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sucursales y unidades de medida.
 *
 * Estas dos tablas van juntas porque son la base de lo que hace una
 * ferreteria distinta de un almacen generico: cada tienda tiene su propio
 * stock, y cada material se compra en una unidad y se vende en otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();

            // El código es el que se usa en el papel: "PDX", "SAN", "CON".
            $tabla->string('codigo', 16);
            $tabla->string('nombre', 160);
            $tabla->string('direccion', 240)->nullable();
            $tabla->string('telefono', 32)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
        });

        /*
        |----------------------------------------------------------------------
        | Unidades de medida y su conversión
        |----------------------------------------------------------------------
        |
        | Cada unidad declara, con `unidad_base_id`, a qué base pertenece, y
        | `unidades_por_base` es el factor que multiplica una cantidad de esta
        | unidad para obtenerla en esa base.
        |
        | Es explicito a propósito. Guardar solo el factor obliga a adivinar
        | de que unidad es cada factor al convertir, y adivinar mal devuelve
        | un número que no corresponde. Con la base declarada, la pregunta
        | "estas dos unidades se pueden convertir entre si" se responde
        | comparando un identificador, y la respuesta es auditable.
        |
        | La familia de una unidad es su `unidad_base_id`, y dos unidades solo
        | sonconvertibles si comparten familia. Un kilogramo y una bolsa de
        | 40 kilogramos son de la misma familia; un kilogramo y un metro no,
        | y la conversión falla con error en vez de devolver un número.
        |
        | `unidades_por_base` se guarda con seis decimales, que es lo que
        | alcanza para el caso real más comun: el pie, que es 0,3048 metros.
        |
        */
        Schema::create('unidades_medida', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();

            $tabla->string('codigo', 24);
            $tabla->string('nombre', 80);
            $tabla->decimal('unidades_por_base', 18, 6);

            // Auto-referencia: la base de esta unidad. Nulo en la unidad base.
            $tabla->foreignUuid('unidad_base_id')->nullable()
                ->constrained('unidades_medida')->nullOnDelete();

            // Una sola unidad base por ferreteria. El indice parcial de más
            // abajo lo garantiza en la base, no solo en el formulario.
            $tabla->boolean('es_base')->default(false);

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->index(['tenant_id', 'activo']);
            $tabla->index('unidad_base_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX unidades_medida_unica_base
                ON public.unidades_medida (tenant_id)
                WHERE es_base = true;

            COMMENT ON INDEX public.unidades_medida_unica_base IS
                'Una sola unidad base por ferreteria: sin ella, "convertir a la base" no tendria una respuesta unica.';
        SQL);

        /*
        | El factor tiene que ser positivo, la unidad base vale 1 por
        | definicion, y una unidad no puede ser su propia base. Un factor en
        | cero dividiria por cero al convertir; uno negativo daria cantidades
        | negativas sin explicación.
        */
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.unidades_medida
                ADD CONSTRAINT unidades_medida_factor_valido
                CHECK (unidades_por_base > 0);

            ALTER TABLE public.unidades_medida
                ADD CONSTRAINT unidades_medida_base_valida
                CHECK (
                    (es_base = true  AND unidades_por_base = 1 AND unidad_base_id IS NULL)
                 OR (es_base = false AND unidades_por_base <> 1 AND unidad_base_id IS NOT NULL)
                );

            ALTER TABLE public.unidades_medida
                ADD CONSTRAINT unidades_medida_no_es_propia_base
                CHECK (unidad_base_id IS NULL OR unidad_base_id <> id);

            COMMENT ON COLUMN public.unidades_medida.unidades_por_base IS
                'Factor para llevar una cantidad de esta unidad a su unidad base. Ejemplo: la bolsa de 40 kg tiene 40 si la base es el kilogramo.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_medida');
        Schema::dropIfExists('sucursales');
    }
};