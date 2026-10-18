<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Cantidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Armado de una transferencia entre sucursales.
 *
 * ---------------------------------------------------------------------
 | POR QUE LA CANTIDAD ES TEXTO Y NO NÚMERO
 * ---------------------------------------------------------------------
 | Si el campo llegara como `numeric`, Laravel lo convertiria a `float` y la
 | conversión pasaria por coma flotante antes de que la cantidad llegue al
 | servicio. Con texto, la cadena viaja intacta hasta `Cantidad`, que trabaja
 | con dígitos. Un stock que se desvía por una coma flotante se desvía siempre
 * hacia el mismo lado y no se nota hasta que es grande.
 */
class GuardarTransferenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol->puedeMoverEntreSucursales() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sucursal_origen_id' => ['required', 'uuid', 'exists:sucursales,id'],
            'sucursal_destino_id' => ['required', 'uuid', 'exists:sucursales,id'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variante_id' => ['required', 'uuid', 'exists:variantes,id'],
            'items.*.cantidad' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sucursal_origen_id.required' => 'Indique la sucursal de la que sale la mercadería.',
            'sucursal_destino_id.required' => 'Indique la sucursal a la que llega.',
            'items.required' => 'Una transferencia sin renglones no transporta nada.',
            'items.min' => 'Una transferencia sin renglones no transporta nada.',
            'items.*.cantidad.required' => 'Cada renglón necesita su cantidad.',
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador): void {
            $origen = $this->input('sucursal_origen_id');
            $destino = $this->input('sucursal_destino_id');

            if ($origen !== null && $origen === $destino) {
                $validador->errors()->add(
                    'sucursal_destino_id',
                    'Una sucursal no puede transferirse mercadería a si misma.',
                );
            }

            $vistas = [];

            foreach ((array) $this->input('items', []) as $indice => $item) {
                $cantidad = Cantidad::desde((string) ($item['cantidad'] ?? '0'));

                if (! $cantidad->esPositivo()) {
                    $validador->errors()->add(
                        'items.' . $indice . '.cantidad',
                        'La cantidad de cada renglón tiene que ser mayor que cero.',
                    );
                }

                $varianteId = (string) ($item['variante_id'] ?? '');

                if ($varianteId === '') {
                    continue;
                }

                if (isset($vistas[$varianteId])) {
                    $validador->errors()->add(
                        'items.' . $indice . '.variante_id',
                        'Esta variante ya aparece en otro renglón de la misma transferencia.',
                    );
                }

                $vistas[$varianteId] = true;
            }
        });
    }

    /**
     * @return array{sucursal_origen_id: string, sucursal_destino_id: string, items: list<array{variante_id: string, cantidad: string}>, observaciones: string|null}
     */
    public function datosParaCrear(): array
    {
        $items = [];

        foreach ((array) $this->input('items', []) as $item) {
            $items[] = [
                'variante_id' => (string) $item['variante_id'],
                'cantidad' => Cantidad::desde((string) $item['cantidad'])
                    ->aEscala(Cantidad::decimalesDeConfiguracion()),
            ];
        }

        return [
            'sucursal_origen_id' => (string) $this->input('sucursal_origen_id'),
            'sucursal_destino_id' => (string) $this->input('sucursal_destino_id'),
            'items' => $items,
            'observaciones' => $this->input('observaciones'),
        ];
    }
}