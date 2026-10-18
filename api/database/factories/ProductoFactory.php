<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ferreteria;
use App\Models\Producto;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Ferreteria::factory(),
            'sku' => 'PRD-' . $this->faker->unique()->numerify('####'),
            'nombre' => $this->faker->words(2, true),
            'descripcion' => $this->faker->sentence(),
            'categoria_id' => null,
            'marca_id' => null,
            'unidad_base_id' => UnidadMedida::factory(),
            'stock_minimo' => '0.000',
            'activo' => true,
        ];
    }

    public function de(Ferreteria $ferreteria): static
    {
        return $this->state(fn (): array => ['tenant_id' => $ferreteria->id]);
    }

    /**
     * Producto con su unidad base declarada.
     *
     * El mínimo se escribe SIEMPRE en la unidad base, y eso lo hace la
     * segunda unidad, no la presentación.
     */
    public function enUnidadBase(UnidadMedida $base, string $stockMinimo = '0'): static
    {
        return $this->state(fn (): array => [
            'unidad_base_id' => $base->id,
            'stock_minimo' => number_format((float) $stockMinimo, 3, '.', ''),
        ]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}