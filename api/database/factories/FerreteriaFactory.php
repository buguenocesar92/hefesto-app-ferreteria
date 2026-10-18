<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ferreteria;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ferreteria>
 */
class FerreteriaFactory extends Factory
{
    protected $model = Ferreteria::class;

    public function definition(): array
    {
        $nombre = 'Ferretería ' . $this->faker->company();

        return [
            'slug' => Str::slug($nombre) . '.' . Str::lower($this->faker->word()),
            'nombre' => $nombre,
            'razon_social' => $nombre . ' Ltda.',
            'activo' => true,
        ];
    }

    /** Ferreteria inactiva, para probar que no entra al selector de acceso. */
    public function inactiva(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}