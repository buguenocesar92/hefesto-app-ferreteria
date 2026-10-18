<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ListaPrecio;
use App\Models\Usuario;

/**
 * Listas de precios.
 *
 * Ver los precios es parte del mostrador. Crear y cambiar una lista es una
 * decisión comercial de la ferreteria: quien la puede cambiar puede dejar de
 * ganar plata en una linea sin que nadie lo note hasta el cierre del mes.
 */
class ListaPrecioPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, ListaPrecio $lista): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    public function update(Usuario $usuario, ListaPrecio $lista): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    public function desactivar(Usuario $usuario, ListaPrecio $lista): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }
}