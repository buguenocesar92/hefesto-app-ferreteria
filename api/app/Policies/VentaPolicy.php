<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;
use App\Models\Venta;

/**
 * Ventas de mostrador.
 *
 * Registrar una venta es lo más frecuente que hay: cualquier persona en
 * mostrar puede cobrar. Anular es lo contrario de frecuente y es lo más
 * grave: deshace un cobro que ya pudo quedar en la caja, así que queda en
 * gerencia.
 */
class VentaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Venta $venta): bool
    {
        return true;
    }

    public function registrar(Usuario $usuario): bool
    {
        return $usuario->rol->puedeVender();
    }

    /** Cotizar no cobra nada: solo muestra cuanto sería. Todos pueden. */
    public function cotizar(Usuario $usuario): bool
    {
        return $usuario->rol->puedeVender();
    }

    public function anular(Usuario $usuario, Venta $venta): bool
    {
        return $usuario->rol->puedeAnular() && ! $venta->estaAnulada();
    }
}