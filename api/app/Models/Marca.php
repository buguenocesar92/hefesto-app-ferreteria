<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Marca del producto.
 *
 * El mostrador de una ferreteria funciona por marcas: el cliente pide "algo
 * Bosch" y no "algo de la marca que se parece a Bosch". Por eso la marca es
 * un dato de primera clase y no un texto libre dentro del producto.
 */
class Marca extends Model
{
    protected $table = 'marcas';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'origen',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'marca_id');
    }
}