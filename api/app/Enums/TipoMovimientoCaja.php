<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de movimiento de caja.
 *
 * La diferencia entre `cobro` y `venta` importa: un cobro es una entrada que
 * no corresponde a una venta de mostrador (por ejemplo, se cobra una venta
 * del dia anterior que quedo pendiente). Si se mezclaran, el arqueo no podria
 * decir cuanto de la caja es venta de hoy.
 *
 * `ajuste` corrige un cobro mal hecho y exige glosa: es el único motivo por el
 * que el dinero puede cambiar sin que exista un documento de venta.
 */
enum TipoMovimientoCaja: string
{
    case Venta = 'venta';
    case Cobro = 'cobro';
    case Retiro = 'retiro';
    case Deposito = 'deposito';
    case Ajuste = 'ajuste';

    /** Suma a la caja. */
    public function suma(): bool
    {
        return $this !== self::Retiro;
    }

    /** Exige saber por que: es dinero que entra o sale sin documento. */
    public function exigeGlosa(): bool
    {
        return $this === self::Retiro || $this === self::Ajuste;
    }
}