<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ferreteria;
use App\Models\ListaPrecio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListaPrecio>
 */
class ListaPrecioFactory extends Factory
{
    protected $model = ListaPrecio::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Ferreteria::factory(),
            'nombre' => 'Lista ' . $this->faker->word(),
            'tipo' => 'consumidor',
            'sucursal_id' => null,
            'activo' => true,
            'vigente_desde' => null,
            'vigente_hasta' => null,
        ];
    }

    public function de(Ferreteria $ferreteria): static
    {
        return $this->state(fn (): array => ['tenant_id' => $ferreteria->id]);
    }

    /** La lista de mostrador: la que se usa si no se elige ninguna. */
    public function mostrador(): static
    {
        return $this->state(fn (): array => [
            'nombre' => 'Mostrador',
            'tipo' => 'consumidor',
        ]);
    }

    /** La lista del mayorista: el margen distinto por linea. */
    public function mayorista(): static
    {
        return $this->state(fn (): array => [
            'nombre' => 'Mayorista',
            'tipo' => 'mayorista',
        ]);
    }

    /** Lista atada a una sucursal. */
    public function deSucursal(string $sucursalId): static
    {
        return $this->state(fn (): array => ['sucursal_id' => $sucursalId]);
    }
}