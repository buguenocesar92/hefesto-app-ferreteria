<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un aviso de reposición.
 *
 * `error` NO vuelve a `pendiente` solo: queda en `error` para que el tablero
 * muestre que el aviso NO salio, en vez de fingir que sigue esperando.
 */
enum EstadoAlertaStock: string
{
    case Pendiente = 'pendiente';
    case Enviada = 'enviada';
    case Error = 'error';
}