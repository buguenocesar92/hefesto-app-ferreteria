<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una transferencia entre sucursales.
 *
 * `despachada` sin `recibida` NO es un estado incompleto: es la situacion
 * normal de una mercadería que va en camino, y es información que la tienda
 * necesita ("les llega 12 el martes"). Por eso `recibida` no se exige para
 * que el documento deje de molestar.
 *
 * Anular es distinto de cancelar: un documento despachado no se borra, se
 * anula y queda el rastro con los dos movimientos contrarios.
 */
enum EstadoTransferencia: string
{
    case Borrador = 'borrador';
    case Despachada = 'despachada';
    case Recibida = 'recibida';
    case Anulada = 'anulada';

    /** Se puede despachar desde este estado. */
    public function puedeDespacharse(): bool
    {
        return $this === self::Borrador;
    }

    /** Se puede recibir desde este estado. */
    public function puedeRecibirse(): bool
    {
        return $this === self::Despachada;
    }

    /** El documento ya no se puede mover. */
    public function esFinal(): bool
    {
        return $this === self::Recibida || $this === self::Anulada;
    }
}