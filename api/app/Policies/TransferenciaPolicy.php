<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Transferencia;
use App\Models\Usuario;

/**
 * Transferencias entre sucursales.
 *
 * Despachar y recibir son de bodega: es la persona que carga y la que recibe.
 * Armar el papel también, porque es quien decide que se manda.
 *
 * Anular es de gerencia: deshace una operación que ya salio de la bodega, y
 * deja el stock como estaba. Es el tipo de accion que alguien tiene que poder
 * explicar después.
 */
class TransferenciaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Transferencia $transferencia): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeMoverEntreSucursales();
    }

    public function despachar(Usuario $usuario, Transferencia $transferencia): bool
    {
        return $usuario->rol->puedeMoverEntreSucursales();
    }

    public function recibir(Usuario $usuario, Transferencia $transferencia): bool
    {
        return $usuario->rol->puedeMoverEntreSucursales();
    }

    public function anular(Usuario $usuario, Transferencia $transferencia): bool
    {
        return $usuario->rol->puedeAnular();
    }
}