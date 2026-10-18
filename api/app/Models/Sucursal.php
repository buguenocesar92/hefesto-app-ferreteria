<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sucursal: una tienda de la ferreteria.
 *
 * El código es el que se usa en el papel y en la etiqueta del repiso
 * ("PDX"), porque el nombre completo no cabe en un cajon.
 */
class Sucursal extends Model
{
    protected $table = 'sucursales';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'direccion',
        'telefono',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'sucursal_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(StockSucursal::class, 'sucursal_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoStock::class, 'sucursal_id');
    }

    public function cajas(): HasMany
    {
        return $this->hasMany(Caja::class, 'sucursal_id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'sucursal_id');
    }

    /** Transferencias que salen de esta sucursal. */
    public function transferenciasEnviadas(): HasMany
    {
        return $this->hasMany(Transferencia::class, 'sucursal_origen_id');
    }

    /** Transferencias que llegan a esta sucursal. */
    public function transferenciasRecibidas(): HasMany
    {
        return $this->hasMany(Transferencia::class, 'sucursal_destino_id');
    }

    /** Listas de precios que aplican solo en esta sucursal. */
    public function listasDePrecios(): HasMany
    {
        return $this->hasMany(ListaPrecio::class, 'sucursal_id');
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(AlertaStock::class, 'sucursal_id');
    }
}