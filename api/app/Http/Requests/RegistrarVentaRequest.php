<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Cantidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Venta de mostrador con varios renglones.
 *
 * ---------------------------------------------------------------------
 | LA PANTALLA MANDA VARIANTES, NO PRECIOS
 * ---------------------------------------------------------------------
 | El precio no viene en la peticion. Si lo mandara, un error de tipeo
 * cobraria mil pesos lo que vale cien y el corte de caja lo mostraria como si
 * estuviera bien. El precio lo resuelve el servicio, desde la lista activa o
 | desde el precio de la variante.
 *
 | La cantidad si viene en texto, por la misma razon que en las
 | transferencias: para que no pase por coma flotante.
 */
class RegistrarVentaRequest extends FormRequest
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
        $maximo = (int) config('ferreteria.mostrador.renglones_maximos', 200);

        return [
            'sucursal_id' => ['required', 'uuid', 'exists:sucursales,id'],
            'caja_id' => ['nullable', 'uuid', 'exists:cajas,id'],
            'lista_precios_id' => ['nullable', 'uuid', 'exists:listas_precios,id'],
            'cliente_nombre' => ['nullable', 'string', 'max:160'],
            'cliente_rut' => ['nullable', 'string', 'max:16', 'regex:/^[0-9Kk.-]{2,16}$/'],
            'items' => ['required', 'array', 'min:1', 'max:'.$maximo],
            'items.*.variante_id' => ['required', 'uuid', 'exists:variantes,id'],
            'items.*.cantidad' => ['required', 'string', 'max:32'],
            'items.*.descuento' => ['nullable', 'string', 'max:32'],
            'items.*.detalle' => ['nullable', 'string', 'max:240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sucursal_id.required' => 'Indique la sucursal en que se realizó la venta.',
            'items.required' => 'Una venta sin renglones no se puede cobrar.',
            'items.max' => 'La venta supera el máximo de renglones permitido en el mostrador.',
            'cliente_rut.regex' => 'El RUT solo admite números, puntos, guion y la K.',
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador): void {
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
                        'Esta variante ya aparece en otro renglón de la misma venta.',
                    );
                }

                $vistas[$varianteId] = true;
            }
        });
    }

    /**
     * @return array{
     *     sucursal_id: string,
     *     caja_id: string|null,
     *     lista_precios_id: string|null,
     *     cliente_nombre: string|null,
     *     cliente_rut: string|null,
     *     items: list<array{variante_id: string, cantidad: string, descuento: string, detalle: string|null}>
     * }
     */
    public function datosParaCrear(): array
    {
        $decimales = Cantidad::decimalesDeConfiguracion();
        $decimalesTotal = (int) config('ferreteria.venta.decimales_total', 2);

        $items = [];

        foreach ((array) $this->input('items', []) as $item) {
            $items[] = [
                'variante_id' => (string) $item['variante_id'],
                'cantidad' => Cantidad::desde((string) $item['cantidad'])->aEscala($decimales),
                'descuento' => Cantidad::desde((string) ($item['descuento'] ?? '0'))
                    ->redondearComercial($decimalesTotal)
                    ->aEscala($decimalesTotal),
                'detalle' => $item['detalle'] ?? null,
            ];
        }

        return [
            'sucursal_id' => (string) $this->input('sucursal_id'),
            'caja_id' => $this->input('caja_id'),
            'lista_precios_id' => $this->input('lista_precios_id'),
            'cliente_nombre' => $this->input('cliente_nombre'),
            'cliente_rut' => $this->input('cliente_rut'),
            'items' => $items,
        ];
    }
}