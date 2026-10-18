<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base de las pruebas de la API.
 *
 * ---------------------------------------------------------------------
 | POR QUE LAS PRUEBAS CORRIEN SIEMPRE CON PostgreSQL
 * ---------------------------------------------------------------------
 | Porque el aislamiento entre ferreterias esta implementado con Row Level
 | Security y la inmutabilidad del stock con gatillos. En SQLite esas dos
 | cosas no existen, y las pruebas passarian sin comprobar nada: un conjunto
 | de pruebas que pasa sin verificar el comportamiento es peor que no
 | tener pruebas, porque da confianza falsa.
 |
 | Por eso `phpunit.xml` fija `DB_CONNECTION=pgsql` de forma explicita. Ver
 | docs/10-PRUEBAS-Y-ESTADO.md.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Ejecuta una operación con el contexto de una ferreteria fijado.
     *
     * Es el equivalente en pruebas de `ContextoTenant::ejecutarAislado()`, y
     * se usa para crear datos de más de una empresa en una misma prueba: la
     * RLS no permite leer una fila de otra empresa, ni siquiera desde las
     * pruebas.
     *
     * @template T
     *
     * @param  callable(): T  $operación
     * @return T
     */
    protected function comoFerreteria(string $tenantId, callable $operacion): mixed
    {
        return app(ContextoTenant::class)->ejecutarAislado($tenantId, $operacion);
    }

    /** Limpia el contexto de la conexión entre pruebas. */
    protected function tearDown(): void
    {
        // La variable de tenant tiene alcance de transacción, pero si una
        // prueba dejo una transacción abierta, el valor sobrevive. Se
        // revierte a propósito para no arrastrarlo a la prueba siguiente.
        Connection::rollBack();

        parent::tearDown();
    }
}