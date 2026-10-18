<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de regla de la ferreteria.
 *
 * Son las situaciones en que la operación es válida en forma pero inválida
 * en fondo: no hay stock disponible en esa sucursal, la caja ya esta cerrada,
 * la transferencia no esta despachada, la unidad no es compatible con la del
 * producto.
 *
 * Se distinguen de los errores de validación (422 de Laravel) y de los
 * de autorización (404) porque la interfaz necesita reaccionar a cada uno
 * de forma distinta. Todas llevan un `codigo` estable.
 */
class ErrorDeFerreteria extends RuntimeException
{
    /**
     * @param  string  $código  Identificador estable para la interfaz.
     *                          Ver la lista en docs/09-API.md.
     */
    public function __construct(
        string $mensaje,
        private readonly string $codigo = 'regla_de_ferreteria',
    ) {
        parent::__construct($mensaje);
    }

    public function codigo(): string
    {
        return $this->codigo;
    }
}