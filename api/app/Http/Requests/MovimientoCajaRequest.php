<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TipoMovimientoCaja;
use App\Support\Cantidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Movimiento de dinero dentro de un corte abierto.
 *
 * ---------------------------------------------------------------------
 | EL MONTO ENTRA POSITIVO Y EL SERVICIO PONE EL SIGNO
 * ---------------------------------------------------------------------
 | Nadie escribe "retiro de menos 50.000". La pantalla pide el monto en
 | positivo y el servicio lo resta si el tipo es `retiro`. Si se aceptara el
 * signo desde la pantalla, un doble clic con el menos puestoeria dos veces.
 *
 | `retiro` y `ajuste` exigen glosa: son los dos casos en que el dinero se
 * mueve sin que exista una venta que lo explique.
 */
class MovimientoCajaRequest extends FormRequest
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
            'tipo' => ['required', 'string', 'in:'.implode(',', array_column(TipoMovimientoCaja::cases(), 'value'))],
            'monto' => ['required', 'string', 'max:32'],
            'glosa' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Indique que tipo de movimiento es.',
            'tipo.in' => 'Ese tipo de movimiento no existe.',
            'monto.required' => 'Indique el monto del movimiento.',
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador): void {
            $monto = Cantidad::desde((string) $this->input('monto'));

            if (! $monto->esPositivo()) {
                $validador->errors()->add(
                    'monto',
                    'El monto se escribe en positivo: si es un retiro, el signo lo pone el tipo de movimiento.',
                );
            }

            $tipo = $this->tipo();

            if ($tipo !== null && $tipo->exigeGlosa() && trim((string) $this->input('glosa', '')) === '') {
                $validador->errors()->add(
                    'glosa',
                    'Un movimiento de tipo «' . $tipo->value . '» necesita una glosa que explique el por que.',
                );
            }
        });
    }

    public function tipo(): ?TipoMovimientoCaja
    {
        $valor = $this->input('tipo');

        return is_string($valor) ? TipoMovimientoCaja::tryFrom($valor) : null;
    }

    /** El monto en positivo, como lo escribe la persona. */
    public function monto(): string
    {
        return Cantidad::desde((string) $this->input('monto'))->aEscala(2);
    }
}