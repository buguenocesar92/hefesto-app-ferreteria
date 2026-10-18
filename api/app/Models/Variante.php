<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Variante: la fila real de la matriz.
 *
 * ---------------------------------------------------------------------
 * EL PRECIO Y EL STOCK SON DE ESTA TABLA
 * ---------------------------------------------------------------------
 * Es la fila que se escanea en el mostrador, la que tiene precio y la que
 * tiene saldo por sucursal. No hay celda de una tabla de grados donde cruce
 * el precio con el stock: son la misma fila.
 *
 * ---------------------------------------------------------------------
 * LOS TRES EJES DE LA UNIDAD
 * ---------------------------------------------------------------------
 * `contenido` + `unidad_contenido_id`  como llega del proveedor (la bolsa)
 * `unidad_venta_id`                    como se cuenta y se vende
 * `producto.unidad_base_id`            como se mide el material
 *
 * Los tres pueden coincidir (un tornillo que solo se vende en bolsas) o no
 * (cemento que se compra en bolsas de 40 y se vende en kilos). Ver
 * App\Services\Unidades\ConversorDeUnidades.
 */
class Variante extends Model
{
    protected $table = 'variantes';

    protected $fillable = [
        'tenant_id',
        'producto_id',
        'sku',
        'nombre',
        'medida',
        'material',
        'acabado',
        'color',
        'contenido',
        'unidad_contenido_id',
        'unidad_venta_id',
        'codigo_barras',
        'precio_venta',
        'costo_referencia',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            // String y no float: el precio se multiplica por cantidades
            // antes de sumar. Ver App\Support\Cantidad.
            'contenido' => 'string',
            'precio_venta' => 'string',
            'costo_referencia' => 'string',
            'activo' => 'boolean',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function unidadContenido(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_contenido_id');
    }

    public function unidadVenta(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_venta_id');
    }

    public function stockPorSucursal(): HasMany
    {
        return $this->hasMany(StockSucursal::class, 'variante_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoStock::class, 'variante_id');
    }

    public function preciosDeLista(): HasMany
    {
        return $this->hasMany(ListaPrecioDetalle::class, 'variante_id');
    }

    /**
     * El stock de esta variante en una sucursal.
     *
     * Devuelve `null` si la fila no existe todavia: que no haya fila
     * significa que nunca se registro stock de esta variante en esa tienda,
     * que es distinto de que el stock sea cero.
     */
    public function stockEn(string $sucursalId): ?StockSucursal
    {
        return $this->stockPorSucursal()
            ->where('sucursal_id', $sucursalId)
            ->first();
    }

    /** Los tres ejes de la matriz, en texto, para la etiqueta del repiso. */
    public function etiquetaDeVariante(): string
    {
        $partes = array_filter([
            $this->medida,
            $this->material,
            $this->acabado,
            $this->color,
        ], static fn (?string $valor): bool => $valor !== null && $valor !== '');

        if ($partes === []) {
            return $this->nombre;
        }

        return trim($this->nombre . ' (' . implode(' / ', $partes) . ')');
    }

    /**
     * Esta variante ya tiene historia?
     *
     * Sirve para una regla concreta del catálogo: la unidad de venta NO se
     * puede cambiar de una variante que ya tenga movimientos, porque cambiar
     * la dejaria con saldos que dicen una cosa y que a partir de ese momento
     * significan otra.
     */
    public function tieneMovimientos(): bool
    {
        return DB::table('movimientos_stock')
            ->where('variante_id', $this->id)
            ->exists();
    }
}