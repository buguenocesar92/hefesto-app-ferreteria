<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ferreteria;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sucursal>
 */
class SucursalFactory extends Factory
{
    protected $model = Sucursal::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Ferreteria::factory(),
            'codigo' => Str::upper(Str::random(3)),
            'nombre' => 'Sucursal ' . $this->faker->city(),
            'direccion' => $this->faker->streetAddress(),
            'telefono' => $this->faker->numerify('+56 9 #### ####'),
            'activo' => true,
        ];
    }

    public function de(Ferreteria $ferreteria): static
    {
        return $this->state(fn (): array => ['tenant_id' => $ferreteria->id]);
    }

    public function conCodigo(string $codigo, string $nombre): static
    {
        return $this->state(fn (): array => ['codigo' => $codigo, 'nombre' => $nombre]);
    }

    public function inactiva(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}