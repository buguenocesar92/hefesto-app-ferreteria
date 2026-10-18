<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Cantidad;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ajuste de stock con justificacion.
 *
 * ---------------------------------------------------------------------
 | POR QUE EL AJUSTE EXIGE GLOSA Y EL INGRESO NO
 * ---------------------------------------------------------------------
 | Un ingreso tiene una guia del proveedor: ya dice de donde viene. Un ajuste
 | no tiene documento: si no se explica, "el stock cambio" es una frase que
 | no sirve para nada cuando aparece una diferencia.
 *
 | Este endpoint NO edita un saldo. Escribe un movimiento de tipo `ajuste`,
 * que es lo único que puede mover stock fuera de una venta o una
 * transferencia.
 */
class AjusteStockRequest extends FormRequest
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
            'variante_id' => ['required', 'uuid', 'exists:variantes,id'],
            'sucursal_id' => ['required', 'uuid', 'exists:sucursales,id'],
            'cantidad' => ['required', 'string', 'max:32'],
            'glosa' => ['required', 'string', 'min:10', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cantidad.required' => 'Indique cuanto se ajusta.',
            'glosa.required' => 'Un ajuste necesita una glosa que explique por que se corrige el stock.',
            'glosa.min' => 'Explique en al menos diez caracteres por que se corrige el stock.',
        ];
    }

    /**
     * @return array{variante_id: string, sucursal_id: string, cantidad: string, glosa: string}
     */
    public function datosParaCrear(): array
    {
        return [
            'variante_id' => (string) $this->input('variante_id'),
            'sucursal_id' => (string) $this->input('sucursal_id'),
            'cantidad' => Cantidad::desde((string) $this->input('cantidad'))
                ->aEscala(Cantidad::decimalesDeConfiguracion()),
            'glosa' => trim((string) $this->input('glosa')),
        ];
    }
}