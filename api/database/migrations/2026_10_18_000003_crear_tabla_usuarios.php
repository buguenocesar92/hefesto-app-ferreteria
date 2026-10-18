<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas que operan la ferreteria.
 *
 * El ROL vive en la base y no en Authentik: el proveedor de identidad sabe
 * quien entra, la ferreteria sabe que puede hacer cada persona. La sucursal
 * por omisión permite acotar al mostrador de una tienda concreta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('ferreterias')->cascadeOnDelete();

            $tabla->string('nombre', 160);
            $tabla->string('email', 160);

            /*
            | Rol delinear. El orden va de más a menos permisos y es el que
            | usa la pantalla de ayuda al dar de alta a alguien.
            */
            $tabla->enum('rol', ['administrador', 'gerente', 'vendedor', 'bodeguero'])
                ->default('vendedor');

            $tabla->foreignUuid('sucursal_id')->nullable()
                ->constrained('sucursales')->nullOnDelete();

            // Identificador del sujeto en Authentik. Es lo que permite
            // cambiar el correo y que la persona siga entrando.
            $tabla->string('authentik_sub', 191)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampTz('ultimo_acceso_at')->nullable();
            $tabla->timestampsTz();

            // El correo es único dentro de la ferreteria, no en toda la
            // instalación: dos tiendas pueden tener a un "juan@".
            $tabla->unique(['tenant_id', 'email']);
            $tabla->index(['tenant_id', 'rol']);
            $tabla->index('authentik_sub');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};