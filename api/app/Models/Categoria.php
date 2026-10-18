<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoria de producto: herreria, pintura y material de construccion.
 *
 * Es el orden con que se revisa el repiso y el que usa el reporte de
 * rotacion. No es un dato decorativo.
 */
class Categoria extends Model
{
    protected $table = 'categorias';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
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
        return $this->hasMany(Producto::class, 'categoria_id');
    }
}