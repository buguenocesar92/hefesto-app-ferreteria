<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Cantidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta o edicion de una unidad de medida.
 *
 * ---------------------------------------------------------------------
 | POR QUE EL FORMULARIO PIDE LA BASE Y NO LA ADIVINA
 * ---------------------------------------------------------------------
 | `unidad_base_id` es obligatorio en toda unidad que no sea la base. Sin el,
 | "miligramos" sería una unidad sin destino y el sistema no podria decir si
 | se puede convertir a gramos.
 *
 | El factor se válida con precisión: se escribe con coma o con punto, y se
 * guarda con seis decimales, que es lo que alcanza para el pie (0,3048
 * metros). Un factor con más decimales se perderia en silencio, así que se
 | rechaza en vez de truncar.
 */
class StoreUnidadMedidaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol->puedeAdministrarCatalogo() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $unidad = $this->route('unidad_medida');

        return [
            'codigo' => [
                'required',
                'string',
                'max:24',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('unidades_medida', 'codigo')
                    ->where('tenant_id', $this->usuario()->tenant_id)
                    ->ignore($unidad),
            ],
            'nombre' => ['required', 'string', 'max:80'],
            'unidades_por_base' => ['required', 'numeric', 'gt:0'],
            'unidad_base_id' => ['nullable', 'uuid', 'exists:unidades_medida,id'],
            'es_base' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'El código de la unidad es obligatorio.',
            'codigo.unique' => 'Ya existe una unidad con ese código en esta ferreteria.',
            'unidades_por_base.required' => 'Indique cuantas unidades base tiene una unidad de esta medida.',
            'unidades_por_base.gt' => 'El factor tiene que ser mayor que cero: con cero o negativo la conversion no existe.',
            'unidad_base_id.exists' => 'La unidad base indicada no existe.',
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador): void {
            if ($validador->errors()->isNotEmpty()) {
                return;
            }

            $esBase = $this->boolean('es_base');

            if ($esBase && $this->filled('unidad_base_id')) {
                $validador->errors()->add(
                    'unidad_base_id',
                    'La unidad base no declara otra unidad base: es la base de la ferreteria.'
                );
            }

            if (! $esBase && ! $this->filled('unidad_base_id')) {
                $validador->errors()->add(
                    'unidad_base_id',
                    'Indique a qué unidad base pertenece esta unidad. Sin eso no se puede convertir.'
                );
            }

            if (! $esBase && $this->input('unidad_base_id') === $this->route('unidad_medida')?->getKey()) {
                $validador->errors()->add(
                    'unidad_base_id',
                    'Una unidad no puede ser su propia base.',
                );
            }

            if ($esBase && (float) $this->input('unidades_por_base') !== 1.0) {
                $validador->errors()->add(
                    'unidades_por_base',
                    'La unidad base vale exactamente 1: no tiene conversion contra si misma.',
                );
            }

            if (! $esBase && (float) $this->input('unidades_por_base') === 1.0) {
                $validador->errors()->add(
                    'unidades_por_base',
                    'Una unidad que vale 1 respecto de la base ES la base. Marque la casilla de unidad base.',
                );
            }

            $this->comprobarPrecisionDelFactor($validador);
            $this->comprobarQueNoSeRompaUnaConversionExistente($validador);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function datosParaCrear(): array
    {
        $esBase = $this->boolean('es_base');

        return [
            'codigo' => mb_strtoupper($this->string('codigo')->trim()->value()),
            'nombre' => $this->string('nombre')->trim()->value(),
            'unidades_por_base' => $esBase
                ? '1.000000'
                : Cantidad::desde((string) $this->input('unidades_por_base'))
                    ->redondearComercial(6)
                    ->aEscala(6),
            'unidad_base_id' => $esBase ? null : $this->input('unidad_base_id'),
            'es_base' => $esBase,
            'activo' => $this->boolean('activo'),
        ];
    }

    /**
     * El factor se guarda con seis decimales.
     *
     * Si la persona escribe más precisión de la que se puede guardar, se
     * rechaza en vez de truncar en silencio: un factor truncado convierte
     * distinto de lo que dice la pantalla.
     */
    private function comprobarPrecisionDelFactor(Validator $validador): void
    {
        $texto = str_replace(',', '.', trim((string) $this->input('unidades_por_base')));

        $partes = explode('.', $texto);
        $decimales = isset($partes[1]) ? strlen($partes[1]) : 0;

        if ($decimales > 6) {
            $validador->errors()->add(
                'unidades_por_base',
                'El factor admite hasta seis decimales. Con más precisión se perderia al guardar, '
                . 'y la conversión haria una cuenta distinta a la que dice la pantalla.',
            );
        }
    }

    /**
     * Cambiar el factor de una unidad que ya se usa en el catálogo
     * reescribiria silenciosamente el significado de todos los saldos.
     */
    private function comprobarQueNoSeRompaUnaConversionExistente(Validator $validador): void
    {
        $unidad = $this->route('unidad_medida');

        if ($unidad === null) {
            return;
        }

        $cambio = (string) $unidad->unidades_por_base !== (string) $this->input('unidades_por_base');

        if (! $cambio) {
            return;
        }

        $enUso = \App\Models\Variante::query()
            ->where('unidad_venta_id', $unidad->id)
            ->orWhere('unidad_contenido_id', $unidad->id)
            ->exists();

        if ($enUso) {
            $validador->errors()->add(
                'unidades_por_base',
                'Esta unidad ya se usa en el catálogo y tiene stock registrado. '
                . 'Cambiar su factor reescribiria el significado de esos saldos: cree una unidad nueva.',
            );
        }
    }

    private function usuario(): \App\Models\Usuario
    {
        /** @var \App\Models\Usuario $usuario */
        $usuario = $this->user();

        return $usuario;
    }
}