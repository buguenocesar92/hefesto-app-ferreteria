<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Cantidad;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Apertura de un corte de caja.
 *
 * El monto inicial es el dinero con el que arranca el turno. Se escribe
 * POSITIVO: nadie abre una caja con menos cero, y el signo lo decide el
 * servicio según el tipo de movimiento.
 */
class AperturaCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol->puedeVender() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sucursal_id' => ['required', 'uuid', 'exists:sucursales,id'],
            'monto_inicial' => ['required', 'string', 'max:32'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sucursal_id.required' => 'Indique en que sucursal se abre la caja.',
            'monto_inicial.required' => 'Indique con cuanto dinero abre el turno.',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validador): void
    {
        $validador->after(function (\Illuminate\Validation\Validator $validador): void {
            $monto = Cantidad::desde((string) $this->input('monto_inicial'));

            if ($monto->esNegativo()) {
                $validador->errors()->add(
                    'monto_inicial',
                    'El monto inicial no puede ser negativo.',
                );
            }

            if (strlen($monto->aEscala(2)) > 16) {
                $validador->errors()->add(
                    'monto_inicial',
                    'El monto inicial es demasiado grande para el campo disponible.',
                );
            }
        });
    }

    public function montoInicial(): string
    {
        return Cantidad::desde((string) $this->input('monto_inicial'))->aEscala(2);
    }
}