<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoAlertaStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aviso de que una variante quedo bajo el mínimo en una sucursal.
 *
 * Se genera con un comando programado. El estado `pendiente` no significa
 * "esperando que alguien lo mire": con `FERRETERIA_ALERTAS_ENVIO_ACTIVO` en
 * falso no hay transporte configurado y el aviso simplemente no sale.
 */
class AlertaStock extends Model
{
    protected $table = 'alertas_stock';

    protected $fillable = [
        'tenant_id',
        'variante_id',
        'sucursal_id',
        'stock_detectado',
        'stock_minimo',
        'periodo_clave',
        'estado',
        'error_texto',
        'enviada_at',
    ];

    protected function casts(): array
    {
        return [
            'stock_detectado' => 'string',
            'stock_minimo' => 'string',
            'periodo_clave' => 'string',
            'estado' => EstadoAlertaStock::class,
            'enviada_at' => 'datetime',
        ];
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(Variante::class, 'variante_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }
}