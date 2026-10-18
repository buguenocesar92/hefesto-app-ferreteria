<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una venta de mostrador.
 *
 * Anular NO borra la venta: la deja en `anulada` y escribe el movimiento de
 * stock en sentido contrario. Borrar una venta sería borrar la evidencia de
 * que en algum momento se cobro por esos articulos, y el corte de caja de
 * ese turno ya esta cerrado con ese total adentro.
 */
enum EstadoVenta: string
{
    case Registrada = 'registrada';
    case Anulada = 'anulada';
}