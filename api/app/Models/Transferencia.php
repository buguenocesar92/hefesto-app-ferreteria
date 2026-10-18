<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoTransferencia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transferencia entre sucursales: un documento, no un ajuste.
 *
 * Mover mercadería de una tienda a otra deja dos saldos que cambian. Si eso
 * se hiciera sin documento, cuando la tienda dijera "llegaron 8, no 12" no
 * habria nada que revisar. Con el documento, la pregunta tiene respuesta:
 * cuantas se mandaron, cuantas se recibieron, quien y cuando.
 */
class Transferencia extends Model
{
    protected $table = 'transferencias';

    protected $fillable = [
        'tenant_id',
        'folio',
        'sucursal_origen_id',
        'sucursal_destino_id',
        'estado',
        'fecha_despacho_at',
        'fecha_recepcion_at',
        'usuario_despacho_id',
        'usuario_recepcion_id',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoTransferencia::class,
            'fecha_despacho_at' => 'datetime',
            'fecha_recepcion_at' => 'datetime',
        ];
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_origen_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_destino_id');
    }

    public function usuarioDespacho(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_despacho_id');
    }

    public function usuarioRecepcion(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_recepcion_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferenciaItem::class, 'transferencia_id');
    }

    /** Esta mercadería esta en camino y la sucursal destino aun no la sumo. */
    public function estaEnCamino(): bool
    {
        return $this->estado === EstadoTransferencia::Despachada;
    }

    /** Siguiente folio para esta ferreteria. */
    public static function siguienteFolio(string $tenantId): string
    {
        $ultimo = self::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('folio')
            ->value('folio');

        $numero = $ultimo === null ? 1 : ((int) $ultimo) + 1;

        return (string) $numero;
    }
}