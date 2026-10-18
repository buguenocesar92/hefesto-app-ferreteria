<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de una venta.
 *
 * `precio_unitario` es el precio YA APLICADO: si la venta usa una lista de
 * mayorista, lo que queda aquí es lo que se cobro por unidad. Guardar el
 * precio de lista y aplicar el margen después, al calcular el subtotal, sería
 * aplicarlo dos veces.
 */
class VentaItem extends Model
{
    protected $table = 'venta_items';

    protected $fillable = [
        'tenant_id',
        'venta_id',
        'variante_id',
        'cantidad',
        'unidad_medida_id',
        'precio_unitario',
        'subtotal',
        'descuento',
        'detalle',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'string',
            'precio_unitario' => 'string',
            'subtotal' => 'string',
            'descuento' => 'string',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(Variante::class, 'variante_id');
    }

    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_medida_id');
    }
}