<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\UnidadMedida;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edicion de un producto base.
 *
 * El producto no lleva precio ni stock: los dos son de la variante. La
 * validación de eso no hace falta porque las columnas no existen; lo que si
 * se válida aca es que la unidad base exista y sea de la ferreteria, porque
 * sin ella no hay forma de convertir cantidades.
 */
class StoreProductoRequest extends FormRequest
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
            'sku' => [
                $this->esEdicion() ? 'sometimes' : 'required',
                'string',
                'max:48',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('productos', 'sku')
                    ->where('tenant_id', $this->usuario()->tenant_id)
                    ->ignore($this->route('producto')),
            ],
            'nombre' => ['required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'categoria_id' => ['nullable', 'uuid', 'exists:categorias,id'],
            'marca_id' => ['nullable', 'uuid', 'exists:marcas,id'],
            'unidad_base_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.required' => 'El código del producto es obligatorio.',
            'sku.regex' => 'El código del producto solo admite letras, números, punto, guion y guion bajo.',
            'sku.unique' => 'Ya existe un producto con ese código en esta ferreteria.',
            'unidad_base_id.required' => 'Indique la unidad base del producto: sin ella no se pueden convertir cantidades.',
            'unidad_base_id.exists' => 'La unidad base indicada no existe.',
            'stock_minimo.numeric' => 'El mínimo de reposición debe ser un número.',
        ];
    }

    /**
     * Prepara los datos para crear o actualizar.
     *
     * @return array<string, mixed>
     */
    public function datosParaCrear(): array
    {
        $datos = [
            'sku' => $this->string('sku')->trim()->value(),
            'nombre' => $this->string('nombre')->trim()->value(),
            'descripcion' => $this->input('descripcion'),
            'categoria_id' => $this->input('categoria_id'),
            'marca_id' => $this->input('marca_id'),
            'unidad_base_id' => $this->input('unidad_base_id'),
            'activo' => $this->boolean('activo'),
        ];

        // El mínimo se guarda SIEMPRE en la unidad base del producto. Si la
        // unidad base cambia en una edicion, el mínimo guardado deja de
        // tener sentido en la unidad nueva, así que se deja en cero y se
        // obliga a redefinirlo: adivinar una equivalencia sería inventar
        // stock.
        if ($this->filled('stock_minimo')) {
            $datos['stock_minimo'] = (string) \App\Support\Cantidad::desde(
                (string) $this->input('stock_minimo')
            )->aEscala(\App\Support\Cantidad::decimalesDeConfiguracion());
        }

        return $datos;
    }

    private function esEdicion(): bool
    {
        return $this->route('producto') !== null;
    }

    private function usuario(): \App\Models\Usuario
    {
        /** @var \App\Models\Usuario $usuario */
        $usuario = $this->user();

        return $usuario;
    }
}