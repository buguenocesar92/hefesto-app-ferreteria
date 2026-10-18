<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precio de una variante en una lista.
 *
 * NO se guarda la unidad. El precio es por la unidad de venta de la variante,
 * y esa unidad no se duplica aquí a propósito: si se guardara, un cambio
 * posterior de la unidad de venta dejaria precios viejos que dicen una cosa y
 * la variante otra.
 */
class ListaPrecioDetalle extends Model
{
    protected $table = 'listas_precios_detalle';

    protected $fillable = [
        'tenant_id',
        'lista_precios_id',
        'variante_id',
        'precio',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'string',
        ];
    }

    public function lista(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class, 'lista_precios_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(Variante::class, 'variante_id');
    }
}