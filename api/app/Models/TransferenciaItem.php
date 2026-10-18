<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de una transferencia.
 *
 * La cantidad va en la unidad de venta de la variante, que es la misma en que
 * esta su stock. Guardar "12 cajas" con su unidad obligaria a convertir en
 * cada movimiento, y una conversión por movimiento es una oportunidad más de
 * que el número se desvíe.
 */
class TransferenciaItem extends Model
{
    protected $table = 'transferencia_items';

    protected $fillable = [
        'tenant_id',
        'transferencia_id',
        'variante_id',
        'cantidad',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'string',
        ];
    }

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(Transferencia::class, 'transferencia_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(Variante::class, 'variante_id');
    }
}