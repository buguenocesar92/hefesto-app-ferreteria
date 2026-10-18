<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockSucursal;
use App\Models\Usuario;

/**
 * Stock por sucursal.
 *
 * ---------------------------------------------------------------------
 | POR QUE `ajustar` NO ES LO MISMO QUE `viewAny`
 * ---------------------------------------------------------------------
 | Cualquiera puede consultar el stock: es la pregunta que se hace veinte
 | veces por hora. Ajustarlo es otra cosa: mueve dinero, así que queda en
 | bodega y gerencia.
 *
 | No hay permiso para "editar el saldo". El saldo no se edita: se mueve con
 | un movimiento, y ese movimiento exige glosa.
 */
class StockSucursalPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, StockSucursal $saldo): bool
    {
        return true;
    }

    /** Registrar una entrada de mercadería es trabajo de bodega. */
    public function ingresar(Usuario $usuario): bool
    {
        return $usuario->rol->puedeMoverEntreSucursales();
    }

    /** Ajustar un saldo exige justificacion y queda registrado. */
    public function ajustar(Usuario $usuario, StockSucursal $saldo): bool
    {
        return $usuario->rol->puedeMoverEntreSucursales();
    }
}