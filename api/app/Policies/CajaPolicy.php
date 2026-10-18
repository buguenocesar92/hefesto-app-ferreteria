<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Caja;
use App\Models\Usuario;

/**
 * Cortes de caja.
 *
 * ---------------------------------------------------------------------
 | ABRIR ES PARA TODOS, CERRAR NO
 * ---------------------------------------------------------------------
 | El mostrador abre la caja: es lo primero que hace la persona al llegar y
 | no tiene nada de sensible. Cerrarla es distinto: es el momento en que se
 * declara cuanto hay y en que queda escrita la diferencia del turno. Ahi
 * decide gerencia.
 *
 | Un vendedor no puede anular un corte. Una diferencia la puede explicar
 * cualquiera; borrarla, no.
 */
class CajaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Caja $caja): bool
    {
        return true;
    }

    /** Abrir la caja es parte del turno de cualquiera. */
    public function abrir(Usuario $usuario): bool
    {
        return $usuario->rol->puedeVender();
    }

    /** Anotar cobros y retiros lo hace quien esta en el turno. */
    public function mover(Usuario $usuario, Caja $caja): bool
    {
        return $caja->estaAbierta() && $usuario->rol->puedeVender();
    }

    /** Cerrar el turno es de gerencia. */
    public function arquear(Usuario $usuario, Caja $caja): bool
    {
        return $usuario->rol->puedeCerrarCaja();
    }

    public function anular(Usuario $usuario, Caja $caja): bool
    {
        return $usuario->rol->puedeAnular();
    }
}