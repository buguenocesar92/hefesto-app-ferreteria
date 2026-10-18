<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;
use App\Models\Usuario as Persona;

/**
 * Personas que operan la ferreteria.
 *
 * Solo administración da de alta o desactiva. Un vendedor ve la lista para
 * saber a quien llamar cuando hay que hacer un traspaso, pero no la modifica.
 */
class UsuarioPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Persona $persona): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarPersonas();
    }

    public function update(Usuario $usuario, Persona $persona): bool
    {
        return $usuario->rol->puedeAdministrarPersonas();
    }

    /**
     * Desactivar a alguien es sacarlo del acceso.
     *
     * La sesión abierta se revoca en el mismo paso: si la cuenta queda
     * desactivada pero la cookie sigue viva, la persona sigue entrando hasta
     * que expire.
     */
    public function desactivar(Usuario $usuario, Persona $persona): bool
    {
        return $usuario->rol->puedeAdministrarPersonas();
    }
}