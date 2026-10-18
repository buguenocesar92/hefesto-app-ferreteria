<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ferreteria;
use App\Models\Sucursal;
use App\Models\Usuario;

/**
 * Sucursales.
 *
 * Verlas es parte del dia a dia de cualquiera: el mostrador tiene que saber
 * en que tienda esta. Darlas de alta es de gerencia.
 */
class SucursalPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Sucursal $sucursal): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    public function update(Usuario $usuario, Sucursal $sucursal): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    /**
     * Desactivar una sucursal no la borra: sus ventas, sus cortes y sus
     * movimientos tienen que seguir existiendo para que los cierres del ano
     * pasado sigan cuadrando.
     */
    public function desactivar(Usuario $usuario, Sucursal $sucursal): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }
}