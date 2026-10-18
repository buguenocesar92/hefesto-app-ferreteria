<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;
use App\Models\Variante;

/**
 * Variantes: el precio y el stock viven aquí.
 *
 * Un vendedor consulta todas, y edita solo el precio de una variante a la
 * que tiene autorización por el rol. El stock no se edita nunca, ni por el
 * administrador: el stock se mueve por movimientos.
 */
class VariantePolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Variante $variante): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    public function update(Usuario $usuario, Variante $variante): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    /**
     * Desactivar una variante saca del catálogo una fila que puede tener
     * stock cargado. La variante no se borra, para no dejar saldos sin
     * origen.
     */
    public function desactivar(Usuario $usuario, Variante $variante): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    /** Consultar el stock de una variante es parte del dia a dia. */
    public function verStock(Usuario $usuario, Variante $variante): bool
    {
        return true;
    }
}