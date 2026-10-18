<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lista de precios: el margen distinto por linea.
 *
 * La lista no tiene precio propio. El precio vive en
 * `listas_precios_detalle`, una fila por variante: el precio depende de la
 * combinacion de lista y variante, no de una de las dos.
 */
class ListaPrecio extends Model
{
    protected $table = 'listas_precios';

    protected $fillable = [
        'tenant_id',
        'nombre',
        'tipo',
        'sucursal_id',
        'activo',
        'vigente_desde',
        'vigente_hasta',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(ListaPrecioDetalle::class, 'lista_precios_id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'lista_precios_id');
    }

    /** Esta lista sirve hoy, según su vigencia. */
    public function vigenteEn(?string $fecha = null): bool
    {
        $fecha = $fecha ?? now()->toDateString();

        if ($this->vigente_desde !== null && $fecha < $this->vigente_desde->toDateString()) {
            return false;
        }

        if ($this->vigente_hasta !== null && $fecha > $this->vigente_hasta->toDateString()) {
            return false;
        }

        return true;
    }
}