<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unidad de medida y su relacion con la base.
 *
 * ---------------------------------------------------------------------
 * DONDE VIVE LA CONVERSIÓN
 * ---------------------------------------------------------------------
 * En `unidades_por_base`, y en ningun otro lado. No hay números magicos en
 * el código: si el kilogramo vale 1 y la bolsa de 40 kilogramos vale 40, eso
 * esta en esta tabla y en ninguna parte más.
 *
 * `unidad_base_id` declara la familia. Dos unidades son convertibles si
 * comparten ese identificador. Ver App\Services\Unidades\ConversorDeUnidades.
 */
class UnidadMedida extends Model
{
    protected $table = 'unidades_medida';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'unidades_por_base',
        'unidad_base_id',
        'es_base',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            // Con cadena, no con float: el factor se usa en aritmética exacta.
            'unidades_por_base' => 'string',
            'es_base' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** La base a la que pertenece esta unidad. Nula si esta es la base. */
    public function base(): BelongsTo
    {
        return $this->belongsTo(self::class, 'unidad_base_id');
    }

    /** Unidades que se convertien a esta. */
    public function derivadas(): HasMany
    {
        return $this->hasMany(self::class, 'unidad_base_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'unidad_base_id');
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(Variante::class, 'unidad_venta_id');
    }

    /**
     * Formato para mostrar junto a una cantidad.
     *
     * "12,5 kg" y no "12,5" suelto: una cantidad sin unidad en una ferreteria
     * es una cantidad ambigua, y el error de unidad es el más caro que hay.
     */
    public function conUnidad(string $cantidad): string
    {
        return $cantidad . ' ' . mb_strtolower($this->codigo);
    }
}