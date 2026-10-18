<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorias y marcas: los dos catalogos que ordenan el Showroom.
 *
 * No son decorativos. La marca se usa para agrupar la lista de precios del
 * mayorista (el cliente compra por marca antes que por categoria) y la
 * categoria para el orden de repizado y para los reportes de rotation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();

            $tabla->string('codigo', 24);
            $tabla->string('nombre', 120);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
        });

        Schema::create('marcas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();

            $tabla->string('codigo', 24);
            $tabla->string('nombre', 120);

            // La marca se compra, no se fabrica en el local. Se guarda igual
            // para poder listar "lo que compro de esta marca" en una compra.
            $tabla->string('origen', 80)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marcas');
        Schema::dropIfExists('categorias');
    }
};