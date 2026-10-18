<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empresas (ferreterias).
 *
 * Esta es la tabla SIN Row Level Security, y es a propósito: se consulta en
 * el acceso, cuando todavia no hay empresa de sesión, y con RLS activa no se
 * podria leer nada. La compensacion es la vista `vw_ferreterias_publicas`, que
 * expone solo tres columnas. Ver migración `..._activar_row_level_security`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ferreterias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->string('slug', 80)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('razon_social', 160)->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ferreterias');
    }
};