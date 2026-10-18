<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;
use App\Models\UnidadMedida;

/**
 * Unidades de medida.
 *
 * Todo el mundo puede consultarlas, porque convert cantidades es parte del
 * dia a dia del mostrador. Cambiar una es decisión de administración: el
 * factor de una unidad aparece en el stock de todas las variantes que la
 * usan.
 */
class UnidadMedidaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, UnidadMedida $unidad): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    public function update(Usuario $usuario, UnidadMedida $unidad): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    /**
     * Desactivar una unidad que esta en uso deja variantes que no se pueden
     * convertir. Solo se puede desactivar si nadie la usa.
     */
    public function desactivar(Usuario $usuario, UnidadMedida $unidad): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }
}