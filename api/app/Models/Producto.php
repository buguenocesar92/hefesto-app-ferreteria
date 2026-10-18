<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto: el ARTICULO BASE del catálogo.
 *
 * ---------------------------------------------------------------------
 * LO QUE ESTE MODELO NO TIENE, Y POR QUE
 * ---------------------------------------------------------------------
 * No tiene `precio_venta` y no tiene `stock`. Un producto no se vende: lo
 * que se vende son sus variantes. "Tornillo" no tiene un precio, porque
 * depende de la medida, del material y de si viene en bolsa de 50 o en caja
 * de 500.
 *
 * Poner el precio aquí obligaria a inventar uno para el producto abstracto y
 * corregirlo a mano en cada variante, que es exactamente el error que hace
 * que el inventario de una ferreteria no cuadre. Ver docs/02-MODELO-DE-DATOS.
 */
class Producto extends Model
{
    protected $table = 'productos';

    protected $fillable = [
        'tenant_id',
        'sku',
        'nombre',
        'descripcion',
        'categoria_id',
        'marca_id',
        'unidad_base_id',
        'stock_minimo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            // El mínimo vive en la unidad base y se compara en esa unidad.
            'stock_minimo' => 'string',
            'activo' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(Marca::class, 'marca_id');
    }

    /** La unidad en que se mide el MATERIAL, no la que se vende. */
    public function unidadBase(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_base_id');
    }

    /** La matriz de variantes. */
    public function variantes(): HasMany
    {
        return $this->hasMany(Variante::class, 'producto_id');
    }

    /** Variantes que se pueden vender hoy. */
    public function variantesActivas(): HasMany
    {
        return $this->hasMany(Variante::class, 'producto_id')->where('activo', true);
    }

    /**
     * Etiqueta de los ejes de la matriz, para la ficha del producto.
     *
     * Devuelve solo los ejes que la linea de producto usa de verdad. Mostrar
     * "medida: no aplica" en cada fila es ruido que entrena al usuario a
     * ignorar la columna.
     */
    public function ejesEnUso(): array
    {
        $variantes = $this->relationLoaded('variantes')
            ? $this->variantes
            : $this->variantes()->get();

        $ejes = [
            'medida' => 'Medida',
            'material' => 'Material',
            'acabado' => 'Acabado',
            'color' => 'Color',
        ];

        $enUso = [];

        foreach ($ejes as $campo => $titulo) {
            if ($variantes->contains(fn (Variante $v): bool => $v->{$campo} !== null)) {
                $enUso[$campo] = $titulo;
            }
        }

        return $enUso;
    }
}