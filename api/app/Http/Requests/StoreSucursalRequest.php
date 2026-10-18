<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Cantidad;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta o edicion de una sucursal.
 *
 * El código es corto a propósito: es el que va en la etiqueta del cajon y en
 * el papel de la transferencia, y un nombre completo no cabe.
 */
class StoreSucursalRequest extends FormRequest
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
        return [
            'codigo' => [
                'required',
                'string',
                'max:16',
                'regex:/^[A-Za-z0-9-]+$/',
                'unique:sucursales,codigo',
            ],
            'nombre' => ['required', 'string', 'max:160'],
            'direccion' => ['nullable', 'string', 'max:240'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'El código de la sucursal es obligatorio.',
            'codigo.unique' => 'Ya existe una sucursal con ese código en esta ferreteria.',
            'codigo.regex' => 'El código solo admite letras, números y guion.',
            'nombre.required' => 'El nombre de la sucursal es obligatorio.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function datosParaCrear(): array
    {
        return [
            'codigo' => mb_strtoupper($this->string('codigo')->trim()->value()),
            'nombre' => $this->string('nombre')->trim()->value(),
            'direccion' => $this->puedeQuedarVacio($this->input('direccion')),
            'telefono' => $this->puedeQuedarVacio($this->input('telefono')),
            'activo' => $this->boolean('activo'),
        ];
    }

    private function puedeQuedarVacio(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}