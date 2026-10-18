<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Variantes: DONDE VIVEN EL PRECIO Y EL STOCK.
 *
 * ---------------------------------------------------------------------
 * LA MATRIZ DE VARIANTES
 * ---------------------------------------------------------------------
 * Un producto base ("tornillo") se vende en muchas formas concrete: medida,
 * material, acabado y presentación. Cada combinacion es una fila de esta
 * tabla, y es UNA fila real, no una celda de una tabla de grados.
 *
 *   producto  | medida | material | acabado | contenido | precio | stock
 *   --------- | ------ | -------- | ------- | --------- | ------ | -----
 *   tornillo  | 1/2"   | acero    | zincado | 50 und    | p1     | s1
 *   tornillo  | 1/2"   | acero    | zincado | 500 und   | p2     | s2
 *   tornillo  | 3/4"   | acero    | pasivado| 50 und    | p3     | s3
 *   tornillo  | 3/4"   | acero    | pasivado| 500 und   | p4     | s4
 *
 * Por que filas y no grados: porque el precio y el stock son de la variante.
 * Con una tabla de grados, el precio sería un par (medida, material) y el
 * stock otro, y quedaria que decidir en que celda se cruzan. Con una tabla
 * de variantes, el precio y el stock son de la MISMA fila: no hay nada que
 * decidir y no se puede desalinear.
 *
 * ---------------------------------------------------------------------
 * LA PRESENTACIÓN Y LA UNIDAD BASE
 * ---------------------------------------------------------------------
 * `contenido` más `unidad_contenido_id` describen la presentación: "caja de
 * 500", "bolsa de 40 kg", "rollo de 100 m". Son la forma en que llega la
 * mercadería del proveedor.
 *
 * El stock NO se guarda en esa presentación. Se guarda en la unidad del
 * renglón de venta, que es lo que el mostrador ve. Laconversion entre las
 * dos se lee de `unidades_medida.unidades_por_base` y nunca de un número
 * escrito en el código.
 *
 * ---------------------------------------------------------------------
 * POR QUE `precio_venta` ES UNA MONEDA Y NO UN PORCENTAJE
 * ---------------------------------------------------------------------
 * El precio de mostrador se guarda como monto, no como margen. El margen
 * lo define la ferreteria al fija la lista de precios (ver
 * `listas_precios`), no el producto: el mismo tornillo puede estar al mismo
 * precio en dos tiendas y a precios distintos en dos listas de la misma
 * tienda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variantes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();
            $tabla->foreignUuid('producto_id')->constrained('productos')->cascadeOnDelete();

            // SKU propio de la variante. Es el código que se escanea en el
            // mostrador y el que va en la etiqueta del repiso.
            $tabla->string('sku', 64);

            // Como se llama esta combinacion en el repiso. Lo escribe quien
            // conoce el local: "3/4 zincado bolsa 50".
            $tabla->string('nombre', 180);

            /*
            |----------------------------------------------------------------------
            | Los ejes de la matriz
            |----------------------------------------------------------------------
            |
            | Los cuatro son texto libre y ninguno es obligatorio. Se guardan
            | aparte y no como columnas "por tamano" o "por material", porque
            | en una ferreteria cada linea de producto decide sus propios
            | ejes: el cemento tiene presentación, el tornillo tiene medida,
            | material y acabado, la pintura tiene volumen y color.
            |
            | Ejes genericos obligarían a inventar una columna "medida" para
            | productos que no la tienen, y el valor "no aplica" se terminaria
            | usando como dato para agrupar.
            */
            $tabla->string('medida', 60)->nullable();
            $tabla->string('material', 80)->nullable();
            $tabla->string('acabado', 80)->nullable();
            $tabla->string('color', 60)->nullable();

            // Presentación: "caja de 500", "bolsa de 40 kg", "rollo de 100 m".
            $tabla->decimal('contenido', 18, 3);
            $tabla->foreignUuid('unidad_contenido_id')->constrained('unidades_medida');

            /*
            |----------------------------------------------------------------------
            | Unidad de venta: en que se cuenta y en que se vende esta variante
            |----------------------------------------------------------------------
            |
            | Es la tercera unidad del triangulo, y no es la misma que la
            | presentación:
            |
            |   contenido        "bolsa de 40 kg"  (como llega del proveedor)
            |   unidad_contenido  kilogramo
            |   unidad_venta      kilogramo        (como se vende al cliente)
            |
            | Para el cemento la presentación es la bolsa y la unidad de venta
            | es el kilo: se compra en bolsas y se vende en kilos. Para un
            | tornillo de 1/2 pulgada, la presentación puede ser la bolsa de 50
            | y la unidad de venta la bolsa de 50 también, porque no se
            | vende suelto.
            |
            | El STOCK de la variante se cuenta en la unidad de venta, que es
            | la que el mostrador pide ("cuantos kilos de cemento quedan") y
            | la que la conversión a la unidad base del producto usa.
            |
            */
            $tabla->foreignUuid('unidad_venta_id')->constrained('unidades_medida');

            $tabla->string('codigo_barras', 64)->nullable();

            /*
            |----------------------------------------------------------------------
            | Precio y stock
            |----------------------------------------------------------------------
            |
            | `precio_venta` es el precio de MOSTRADOR de esta variante, en la
            | unidad de venta declarada más abajo. El precio del mayorista vive en
            | `listas_precios_detalle`, porque no es una propiedad de la
            | variante sino de la combinacion variante + lista.
            |
            | `costo_referencia` es el último costo conocido. No se usa para
            | calcular el precio: el precio lo decide la ferreteria. Se guarda
            | para que el reporte de margen pueda mostrarlo sin ir a buscarlo.
            */
            $tabla->decimal('precio_venta', 14, 2);
            $tabla->decimal('costo_referencia', 14, 2)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'sku']);
            $tabla->index(['producto_id', 'activo']);
            $tabla->index('codigo_barras');

            /*
            | Un producto no puede tener dos variantes con la misma
            | combinacion de ejes. Sin esto, el mostrador podria tener dos
            | filas identicas con precios distintos y nadie sabria cual
            | vender: es la forma más comun de desalinear el inventario.
            |
            | NULL no se considera igual a NULL en este indice, así que la
            | restriccion se aplica solo a las combinaciones completas, que
            | son las que de verdad colisionan.
            */
            $tabla->unique(
                ['producto_id', 'medida', 'material', 'acabado', 'color', 'contenido', 'unidad_contenido_id'],
                'variantes_combinacion_unica'
            );
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.variantes
                ADD CONSTRAINT variantes_contenido_valido CHECK (contenido > 0);

            ALTER TABLE public.variantes
                ADD CONSTRAINT variantes_precio_valido CHECK (precio_venta >= 0);

            COMMENT ON TABLE public.variantes IS
                'Precio y stock son de la variante, nunca del producto base. Ver docs/02-MODELO-DE-DATOS.md.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('variantes');
    }
};