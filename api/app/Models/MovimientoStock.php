<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoMovimientoStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimiento de stock. La historia.
 *
 * No se edita ni se borra: hay un gatillo en PostgreSQL que lo rechaza. Para
 * corregir algo se escribe el movimiento contrario.
 */
class MovimientoStock extends Model
{
    protected $table = 'movimientos_stock';

    protected $fillable = [
        'tenant_id',
        'variante_id',
        'sucursal_id',
        'unidad_medida_id',
        'tipo',
        'cantidad',
        'referencia_tipo',
        'referencia_id',
        'glosa',
        'usuario_id',
        'ocurrio_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoStock::class,
            'cantidad' => 'string',
            'ocurrio_en' => 'datetime',
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

    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_medida_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /** La venta o la transferencia que causo este movimiento, si hubo. */
    public function documento(): ?Model
    {
        return match ($this->referencia_tipo) {
            'venta' => $this->referencia_id === null ? null : Venta::find($this->referencia_id),
            'transferencia' => $this->referencia_id === null
                ? null
                : Transferencia::find($this->referencia_id),
            default => null,
        };
    }
}