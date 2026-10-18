<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Cantidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta o edicion de una variante.
 *
 * ---------------------------------------------------------------------
 | LAS TRES UNIDADES Y POR QUE SE VALIDAN JUNTAS
 * ---------------------------------------------------------------------
 | `unidad_contenido_id` es como llega del proveedor, `unidad_venta_id` es
 | como se cuenta y se vende, y la del producto es como se mide el material.
 |
 | Se comprueba que las tres pertenezcan a la misma familia antes de aceptar.
 | Si no, la variante queda creada y es IMPOSIBLE venderla o mover su stock:
 | la conversión falla en cada operación, y el mostrador descubre el error
 | con el cliente esperando.
 */
class StoreVarianteRequest extends FormRequest
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
        $variante = $this->route('variante');

        return [
            'producto_id' => ['required', 'uuid', 'exists:productos,id'],
            'sku' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('variantes', 'sku')
                    ->where('tenant_id', $this->usuario()->tenant_id)
                    ->ignore($variante),
            ],
            'nombre' => ['required', 'string', 'max:180'],
            'medida' => ['nullable', 'string', 'max:60'],
            'material' => ['nullable', 'string', 'max:80'],
            'acabado' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:60'],
            'contenido' => ['required', 'numeric', 'gt:0'],
            'unidad_contenido_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
            'unidad_venta_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
            'codigo_barras' => ['nullable', 'string', 'max:64'],
            'precio_venta' => ['required', 'numeric', 'min:0'],
            'costo_referencia' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.required' => 'El código de la variante es obligatorio: es el que se escanea en el mostrador.',
            'sku.unique' => 'Ya existe una variante con ese código en esta ferreteria.',
            'contenido.gt' => 'La presentación tiene que ser mayor que cero.',
            'unidad_venta_id.required' => 'Indique la unidad en que se cuenta y se vende esta variante.',
            'precio_venta.required' => 'El precio de venta es obligatorio: el precio es de la variante, no del producto.',
        ];
    }

    /**
     * Comprueba que las tres unidades sean de la misma familia y que la
     * combinacion de ejes no este repetida dentro del producto.
     */
    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador): void {
            if ($validador->errors()->isNotEmpty()) {
                return;
            }

            $this->comprobarFamiliaDeUnidades($validador);
            $this->comprobarCombinacionRepetida($validador);
            $this->comprobarUnidadDeVentaNoCambia($validador);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function datosParaCrear(): array
    {
        $decimales = Cantidad::decimalesDeConfiguracion();

        return [
            'producto_id' => $this->input('producto_id'),
            'sku' => $this->string('sku')->trim()->value(),
            'nombre' => $this->string('nombre')->trim()->value(),
            'medida' => $this->puedeQuedarVacia($this->input('medida')),
            'material' => $this->puedeQuedarVacia($this->input('material')),
            'acabado' => $this->puedeQuedarVacia($this->input('acabado')),
            'color' => $this->puedeQuedarVacia($this->input('color')),
            'contenido' => Cantidad::desde((string) $this->input('contenido'))->aEscala($decimales),
            'unidad_contenido_id' => $this->input('unidad_contenido_id'),
            'unidad_venta_id' => $this->input('unidad_venta_id'),
            'codigo_barras' => $this->puedeQuedarVacia($this->input('codigo_barras')),
            'precio_venta' => Cantidad::desde((string) $this->input('precio_venta'))->aEscala(2),
            'costo_referencia' => $this->input('costo_referencia') === null
                ? null
                : Cantidad::desde((string) $this->input('costo_referencia'))->aEscala(2),
            'activo' => $this->boolean('activo'),
        ];
    }

    /** Las tres unidades tienen que pertenecer a la misma familia. */
    private function comprobarFamiliaDeUnidades(Validator $validador): void
    {
        $producto = \App\Models\Producto::find($this->input('producto_id'));

        if ($producto === null) {
            return;
        }

        $familias = [];

        foreach (['unidad_contenido_id', 'unidad_venta_id'] as $campo) {
            $unidad = \App\Models\UnidadMedida::find($this->input($campo));

            if ($unidad === null) {
                return;
            }

            $familias[] = $unidad->es_base ? $unidad->id : (string) $unidad->unidad_base_id;
        }

        $base = $producto->unidad_base_id;

        if (count(array_unique(array_merge($familias, [$base]))) > 1) {
            $validador->errors()->add(
                'unidad_venta_id',
                'La unidad de presentación, la unidad de venta y la unidad base del producto '
                . 'tienen que ser de la misma familia. Si no lo son, la variante no se podria vender.'
            );
        }
    }

    /** Dos variantes del mismo producto no pueden tener los mismos ejes. */
    private function comprobarCombinacionRepetida(Validator $validador): void
    {
        $variante = $this->route('variante');

        $repetida = \App\Models\Variante::query()
            ->where('producto_id', $this->input('producto_id'))
            ->where('medida', $this->puedeQuedarVacia($this->input('medida')))
            ->where('material', $this->puedeQuedarVacia($this->input('material')))
            ->where('acabado', $this->puedeQuedarVacia($this->input('acabado')))
            ->where('color', $this->puedeQuedarVacia($this->input('color')))
            ->when($variante !== null, fn ($consulta) => $consulta->whereKeyNot($variante->getKey()))
            ->exists();

        if ($repetida) {
            $validador->errors()->add(
                'nombre',
                'Este producto ya tiene una variante con la misma medida, material, acabado y color. '
                . 'Dos filas identicas con precios distintos son la forma más comun de desalinear el inventario.'
            );
        }
    }

    /**
     * La unidad de venta no se cambia en una variante que ya tiene
     * movimientos.
     *
     * Cambiarla dejaria saldos que dicen una cosa y que a partir de ese
     * momento significan otra, sin convertir ninguno de los dos.
     */
    private function comprobarUnidadDeVentaNoCambia(Validator $validador): void
    {
        $variante = $this->route('variante');

        if ($variante === null) {
            return;
        }

        $nueva = $this->input('unidad_venta_id');

        if ((string) $variante->unidad_venta_id === (string) $nueva) {
            return;
        }

        if ($variante->tieneMovimientos()) {
            $validador->errors()->add(
                'unidad_venta_id',
                'No se puede cambiar la unidad de venta de una variante que ya tiene movimientos: '
                . 'los saldos historicos quedarían diciendo una cosa y significarían otra.'
            );
        }
    }

    /** Una cadena vacia se guarda como `null`, no como cadena vacia. */
    private function puedeQuedarVacia(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function usuario(): \App\Models\Usuario
    {
        /** @var \App\Models\Usuario $usuario */
        $usuario = $this->user();

        return $usuario;
    }
}