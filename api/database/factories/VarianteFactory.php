<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\Variante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variante>
 */
class VarianteFactory extends Factory
{
    protected $model = Variante::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Producto::factory(),
            'producto_id' => Producto::factory(),
            'sku' => 'VAR-' . $this->faker->unique()->numerify('#####'),
            'nombre' => $this->faker->words(2, true),
            'medida' => null,
            'material' => null,
            'acabado' => null,
            'color' => null,
            'contenido' => '1.000',
            'unidad_contenido_id' => UnidadMedida::factory(),
            'unidad_venta_id' => UnidadMedida::factory(),
            'codigo_barras' => $this->faker->unique()->numerify('78###########'),
            'precio_venta' => '0.00',
            'costo_referencia' => null,
            'activo' => true,
        ];
    }

    public function de(Producto $producto): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $producto->tenant_id,
            'producto_id' => $producto->id,
        ]);
    }

    /**
     * Una variante completa con sus tres unidades.
     *
     * Es el caso general de una ferreteria: la presentación es como llega del
     * proveedor y la unidad de venta es como se cuenta.
     */
    public function conUnidades(
        Producto $producto,
        UnidadMedida $unidadContenido,
        UnidadMedida $unidadVenta,
        string $contenido = '1',
    ): static {
        return $this->state(fn (): array => [
            'tenant_id' => $producto->tenant_id,
            'producto_id' => $producto->id,
            'contenido' => number_format((float) $contenido, 3, '.', ''),
            'unidad_contenido_id' => $unidadContenido->id,
            'unidad_venta_id' => $unidadVenta->id,
        ]);
    }

    /** Fila de la matriz: medida, material, acabado y contenido. */
    public function matriz(
        Producto $producto,
        string $medida,
        string $material,
        string $acabado,
        string $contenido,
        string $precio,
    ): static {
        return $this->state(fn (): array => [
            'tenant_id' => $producto->tenant_id,
            'producto_id' => $producto->id,
            'medida' => $medida,
            'material' => $material,
            'acabado' => $acabado,
            'contenido' => number_format((float) $contenido, 3, '.', ''),
            'nombre' => 'Tornillo ' . $medida . ' ' . $material . ' ' . $acabado,
            'precio_venta' => $precio,
        ]);
    }

    public function conPrecio(string $precio): static
    {
        return $this->state(fn (): array => ['precio_venta' => number_format((float) $precio, 2, '.', '')]);
    }

    public function inactiva(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}