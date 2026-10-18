<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Arranque de la aplicación para las pruebas.
 *
 * Es el equivalente al `bootstrap/app.php` de la aplicación real. Laravel lo
 * genera con `make:test`, y este archivo esta versionado para que el
 * esqueleto de pruebas no dependa de que alguien lo ejecute.
 */
trait CreatesApplication
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}