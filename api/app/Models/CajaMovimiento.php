<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoMovimientoCaja;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada o salida de dinero de un corte.
 *
 * El monto va con signo: negativo para lo que sale (un retiro, un ajuste en
 * contra). Guardar el tipo y el signo por separado duplicaria la verdad y
 * bastaria un retiro con signo positivo para que el arqueo quedara mal.
 */
class CajaMovimiento extends Model
{
    protected $table = 'caja_movimientos';

    protected $fillable = [
        'tenant_id',
        'caja_id',
        'tipo',
        'monto',
        'referencia_tipo',
        'referencia_id',
        'glosa',
        'usuario_id',
        'ocurrio_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoCaja::class,
            'monto' => 'string',
            'ocurrio_en' => 'datetime',
        ];
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'referencia_id');
    }
}