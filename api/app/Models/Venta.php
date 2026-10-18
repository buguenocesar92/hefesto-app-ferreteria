<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoVenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Venta de mostrador.
 *
 * `subtotal`, `descuento` y `total` quedan guardados y NO se recalculan a
 * partir de los renglones. El total es el que se cobro; volver a calcularlo
 * sería mentir sobre lo que paso, sobre todo si el cobro incluyo un
 * redondeo o una promocion que ya no existe.
 */
class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = [
        'tenant_id',
        'folio',
        'caja_id',
        'sucursal_id',
        'lista_precios_id',
        'cliente_nombre',
        'cliente_rut',
        'subtotal',
        'descuento',
        'total',
        'estado',
        'usuario_id',
        'vendida_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'string',
            'descuento' => 'string',
            'total' => 'string',
            'estado' => EstadoVenta::class,
            'vendida_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class, 'venta_id');
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function listaPrecios(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class, 'lista_precios_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function estaAnulada(): bool
    {
        return $this->estado === EstadoVenta::Anulada;
    }

    public static function siguienteFolio(string $tenantId): string
    {
        $ultimo = self::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('folio')
            ->value('folio');

        return (string) ($ultimo === null ? 1 : ((int) $ultimo) + 1);
    }
}