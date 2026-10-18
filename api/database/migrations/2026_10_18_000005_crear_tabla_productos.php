<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Productos: el ARTICULO BASE del catálogo.
 *
 * QUE ES Y QUE NO ES UN PRODUCTO
 * -----------------------------
 * Un producto es la cosa que el cliente pide ("tornillo", "pintura blanca",
 * "arena"). NO tiene precio, NO tiene stock y NO se vende.
 *
 * Esas tres cosas viven en la variante. La razon es concreta: un tornillo
 * existe en 1/2 pulgada y en 1 pulgada, en acero y en acero galvanizado, en
 * bolsa de 50 y en caja de 500. Si el precio y el stock estuvieran en el
 * producto, habria que inventarlos para un producto que en realidad son
 * diez cosas distintas.
 *
 * Lo que si vive en el producto es lo que es comun a todas sus variantes:
 * la categoria, la marca de la linea, la unidad base en que se mide el
 * material y el mínimo de reposición.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();

            $tabla->string('sku', 48);
            $tabla->string('nombre', 180);
            $tabla->text('descripcion')->nullable();

            $tabla->foreignUuid('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $tabla->foreignUuid('marca_id')->nullable()->constrained('marcas')->nullOnDelete();

            /*
            |----------------------------------------------------------------------
            | Unidad base del producto
            |----------------------------------------------------------------------
            |
            | Es la unidad en que se mide el MATERIAL, no la unidad en que se
            | vende. El cemento tiene como base el kilogramo: se compra en
            | bolsas de 40 kg y se vende en kilos sueltos o en la bolsa. La
            | arena tiene como base el metro cubico. El tornillo tiene como
            | base la unidad.
            |
            | Todas las conversiones de este producto pasan por esta unidad.
            | Por eso es OBLIGATORIA y por eso tiene que existir una sola
            | unidad marcada como base en la ferreteria.
            |
            */
            $tabla->foreignUuid('unidad_base_id')->constrained('unidades_medida');

            /*
            | Mínimo de reposición, expresado SIEMPRE en la unidad base.
            |
            | Se guarda en la base y no en la unidad de la presentación para
            | que elminimo signifique lo mismo aunque cambie el tamanio de la
            | bolsa que compro el proveedor. Si el mínimo fuera "3 bolsas" y
            | la bolsa pasa de 40 a 25 kg, el mínimo bajaria solo, sin que
            | nadie lo decidiera.
            */
            $tabla->decimal('stock_minimo', 18, 3)->default(0);

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'sku']);
            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};