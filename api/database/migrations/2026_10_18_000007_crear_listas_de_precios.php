<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listas de precios: el margen distinto por linea.
 *
 * ---------------------------------------------------------------------
 * POR QUE NO ES UN SEGUNDO PRECIO EN LA VARIANTE
 * ---------------------------------------------------------------------
 * Un cliente mayorista no compra al mismo precio que el consumidor final, y
 * además hay más de un mayorista y más de una condicion. Si el precio
 * estuviera en la variante, habria que agregar "precio_mayorista" y, al
 * siguiente tipo de cliente, "precio_constructor", y así sucesivamente.
 *
 * Con una tabla de listas, el precio depende de DOS cosas: la lista y la
 * variante. La lista dice a quien, la variante dice de que. Un precio
 * especial es un INSERT en `listas_precios_detalle`, no una columna nueva ni
 * un cambio de código.
 *
 * La lista puedeAtender una sucursal en particular: en la práctica los
 * precios del mayorista no son los mismos en todas las tiendas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listas_precios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();

            $tabla->string('nombre', 120);

            /*
            | `consumidor` es la lista de mostrador, la que se usa si no se
            | elige ninguna. `mayorista` es la de los clientes que compran por
            | volumen. Los tipos son pocos y fijos a propósito: un tipo libre
            | por lista termina siendo un cartel sin significado.
            */
            $tabla->enum('tipo', ['consumidor', 'mayorista'])->default('consumidor');

            // Vacio significa que la lista sirve en todas las sucursales.
            $tabla->foreignUuid('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();

            $tabla->boolean('activo')->default(true);

            // Vigencia. Nulas significan "sin límite".
            $tabla->date('vigente_desde')->nullable();
            $tabla->date('vigente_hasta')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'nombre']);
            $tabla->index(['tenant_id', 'tipo', 'activo']);
        });

        Schema::create('listas_precios_detalle', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();
            $tabla->foreignUuid('lista_precios_id')->constrained('listas_precios')->cascadeOnDelete();
            $tabla->foreignUuid('variante_id')->constrained('variantes')->cascadeOnDelete();

            // El precio de esta variante en esta lista, en la unidad de venta
            // de la variante. La unidad NO se guarda aquí a propósito: si la
            // unidad de venta de la variante cambiara, un precio guardado en
            // la lista quedaria con una unidad distinta de la que se cree.
            $tabla->decimal('precio', 14, 2);

            $tabla->timestampsTz();

            // Un precio por par lista + variante. Sin esto se podrian cargar
            // dos precios para la misma combinacion y el resultado dependeria
            // del orden en que los leyera la base.
            $tabla->unique(['lista_precios_id', 'variante_id']);
            $tabla->index('variante_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listas_precios_detalle');
        Schema::dropIfExists('listas_precios');
    }
};