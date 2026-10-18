<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Cantidad;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Arqueo y cierre de un corte.
 *
 * ---------------------------------------------------------------------
 | LO QUE SE ESCRIBE Y LO QUE SE CALCULA
 * ---------------------------------------------------------------------
 | La persona escribe SOLO el monto que conto: `monto_declarado`. Lo demas lo
 | calcula el sistema y se guarda al cerrar.
 *
 * No se acepta que la pantalla mande la diferencia. Si se aceptara, el
 | arqueo sería lo que la pantalla quiso y no lo que hay en la caja, que es
 * justamente el número que hay que mirar.
 */
class ArquearCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol->puedeCerrarCaja() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'monto_declarado' => ['required', 'string', 'max:32'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monto_declarado.required' => 'Indique cuanto dinero conto.',
            'monto_declarado.numeric' => 'El monto declarado debe ser un número.',
        ];
    }

    public function montoDeclarado(): string
    {
        return Cantidad::desde((string) $this->input('monto_declarado'))->aEscala(2);
    }
}