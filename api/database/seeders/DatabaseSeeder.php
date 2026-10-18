<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de los datos de ejemplo.
 *
 * `FerreteriaSeeder` es idempotente: se puede correr más de una vez sin
 * duplicar nada. Ver docs/04-INSTALACION.md, paso de datos iniciales.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FerreteriaSeeder::class,
        ]);
    }
}