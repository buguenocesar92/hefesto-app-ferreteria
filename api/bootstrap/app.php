<?php

declare(strict_types=1);

use App\Exceptions\ErrorDeFerreteria;
use App\Http\Middleware\AuditarOperacion;
use App\Http\Middleware\ResolverTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/**
 * Punto de arranque de la API de Gestión de Ferreteria.
 *
 * Nota sobre el esqueleto: este archivo reemplaza al que genera
 * `composer create-project laravel/laravel`. El resto del esqueleto
 * (artisan, public/index.php, storage, etc.) lo genera Composer.
 * Ver docs/04-INSTALACION.md, paso 2.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/api/v1/salud',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CSRF y sesión se aplican por el grupo `web` de Laravel.
        // La interfaz la sirve Nuxt, que actua como BFF: la cookie de sesión
        // viaja en la misma origen y el token CSRF se reenvia como cabecera
        // X-XSRF-TOKEN. Ver docs/07.
        $middleware->web(append: [
            ResolverTenant::class,
            AuditarOperacion::class,
        ]);

        $middleware->alias([
            'tenant.resuelto' => ResolverTenant::class,
        ]);

        // La API nunca debe quedar abierta a terceros desde el navegador.
        // El CORS lo resuelve Caddy; aun así, se deja explicito que las
        // peticiones de origen cruzado no se aceptan en la API.
        $middleware->validateCsrfTokens(except: [
            'api/v1/salud',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Un error de autorización entre ferreterias no debe revelar si el
        // recurso existe en la otra. Se responde 404, no 403.
        $exceptions->render(function (Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'mensaje' => 'No encontrado.',
                ], 404);
            }

            return null;
        });

        // Los errores de dominio de la ferreteria (no hay stock disponible,
        // la caja ya esta cerrada, la transferencia no esta despachada) se
        // devuelven como 422 con un código estable, para que la interfaz
        // pueda reaccionar sin adivinar. Ver docs/09.
        $exceptions->render(function (ErrorDeFerreteria $e, $request) {
            return response()->json([
                'mensaje' => $e->getMessage(),
                'codigo' => $e->codigo(),
            ], 422);
        });
    })
    ->create();