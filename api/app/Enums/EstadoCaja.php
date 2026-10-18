<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un corte de caja.
 *
 * `abierta` es el único estado en que se le pueden agregar ventas, cobros y
 * retiros. Cerrada y anulada son terminales: un corte que ya se entrego no se
 * toca más, porque su diferencia es la evidencia del turno.
 */
enum EstadoCaja: string
{
    case Abierta = 'abierta';
    case Cerrada = 'cerrada';
    case Anulada = 'anulada';

    public function aceptaMovimientos(): bool
    {
        return $this === self::Abierta;
    }

    public function esFinal(): bool
    {
        return $this !== self::Abierta;
    }
}