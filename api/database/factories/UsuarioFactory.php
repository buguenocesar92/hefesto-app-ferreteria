<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RolOperador;
use App\Models\Ferreteria;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Ferreteria::factory(),
            'nombre' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'rol' => RolOperador::Vendedor->value,
            'activo' => true,
        ];
    }

    public function de(Ferreteria $ferreteria): static
    {
        return $this->state(fn (): array => ['tenant_id' => $ferreteria->id]);
    }

    public function administrador(): static
    {
        return $this->state(fn (): array => ['rol' => RolOperador::Administrador->value]);
    }

    public function gerente(): static
    {
        return $this->state(fn (): array => ['rol' => RolOperador::Gerente->value]);
    }

    public function vendedor(): static
    {
        return $this->state(fn (): array => ['rol' => RolOperador::Vendedor->value]);
    }

    public function bodeguero(): static
    {
        return $this->state(fn (): array => ['rol' => RolOperador::Bodeguero->value]);
    }

    /** Cuenta desactivada, para probar que no puede entrar. */
    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }

    /** Con el vinculo a Authentik ya hecho. */
    public function vinculado(): static
    {
        return $this->state(fn (): array => ['authentik_sub' => (string) Str::uuid()]);
    }
}