<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Motivo de un movimiento de stock.
 *
 * El enum NO decide el signo: el signo lo lleva la cantidad. Un enum con
 * signo duplicaria la verdad en dos lugares y bastaria un ingreso escrito
 * con signo negativo para que el saldo quedara mal sin que nada lo note.
 *
 * Lo que si sabe el enum es que motivos como `ajuste` o `merma` exigen una
 * glosa escrita: son los casos en que la diferencia necesita explicación.
 */
enum TipoMovimientoStock: string
{
    case Ingreso = 'ingreso';
    case Venta = 'venta';
    case TrasladoSalida = 'traslado_salida';
    case TrasladoEntrada = 'traslado_entrada';
    case Ajuste = 'ajuste';
    case Devolucion = 'devolucion';
    case Merma = 'merma';
    case Conteo = 'conteo';

    /** Suma al saldo. */
    public function suma(): bool
    {
        return match ($this) {
            self::Ingreso, self::Devolucion, self::TrasladoEntrada, self::Conteo => true,
            self::Venta, self::TrasladoSalida, self::Ajuste, self::Merma => false,
        };
    }

    /**
     * Si el motivo obliga a explicar por que se movio el stock.
     *
     * Sin documento de por medio, "el stock cambio" no dice nada. Estos son
     * los motivos en que la glosa es parte del dato y no un extra.
     */
    public function exigeGlosa(): bool
    {
        return match ($this) {
            self::Ajuste, self::Merma, self::Devolucion, self::Conteo => true,
            self::Ingreso, self::Venta, self::TrasladoSalida, self::TrasladoEntrada => false,
        };
    }

    /** Este motivo viene de un documento y no se puede capturar suelto. */
    public function vieneDeDocumento(): bool
    {
        return $this === self::Venta
            || $this === self::TrasladoSalida
            || $this === self::TrasladoEntrada;
    }
}