<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ferreteria;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UnidadMedida>
 */
class UnidadMedidaFactory extends Factory
{
    protected $model = UnidadMedida::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Ferreteria::factory(),
            'codigo' => Str::upper(Str::random(4)),
            'nombre' => $this->faker->word(),
            'unidades_por_base' => '1.000000',
            'unidad_base_id' => null,
            'es_base' => false,
            'activo' => true,
        ];
    }

    public function de(Ferreteria $ferreteria): static
    {
        return $this->state(fn (): array => ['tenant_id' => $ferreteria->id]);
    }

    /** La unidad base de la ferreteria: piezas. */
    public function base(): static
    {
        return $this->state(fn (): array => [
            'codigo' => 'UND',
            'nombre' => 'Unidad',
            'unidades_por_base' => '1.000000',
            'unidad_base_id' => null,
            'es_base' => true,
        ]);
    }

    /**
     * Una unidad derivada de la base, con su factor declarado.
     *
     * El factor se escribe como cadena a propósito: la columna es `numeric`
     * y la conversión se hace exacto con dígitos, nunca con coma flotante.
     */
    public function derivada(UnidadMedida $base, string $factor, string $codigo, string $nombre): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $base->tenant_id,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'unidades_por_base' => number_format((float) $factor, 6, '.', ''),
            'unidad_base_id' => $base->id,
            'es_base' => false,
        ]);
    }

    /** Bolsa de 40 kilogramos, con la base en kilogramo. */
    public function bolsaDeKilos(UnidadMedida $baseKilo): static
    {
        return $this->derivada($baseKilo, '40', 'BOLSA40', 'Bolsa de 40 kg');
    }

    public function inactiva(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}