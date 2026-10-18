<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Cantidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo de una variante en una sucursal.
 *
 * ---------------------------------------------------------------------
 * QUE ES Y QUE NO ES UNA FILA
 * ---------------------------------------------------------------------
 * Una fila es una terna (variante, sucursal). Si la fila no existe, es que
 * de esa variante nunca se registro stock en esa tienda. Que es distinto de
 * que el stock sea cero, y la diferencia importa: una fila que falta se
 * puede crear con un ingreso, un saldo de cero se llega ajustando.
 *
 * ---------------------------------------------------------------------
 * LO QUE SE CALCULA Y LO QUE SE GUARDA
 * ---------------------------------------------------------------------
 * `cantidad` y `cantidad_reservada` se guardan. `disponible` NO: se deriva
 * como la diferencia entre las dos. Si `disponible` fuera columna, habria que
 * recalcularla en cada movimiento y terminaria desincronizada, que es
 * exactamente como un local termina prometiendo mercadería que ya salio.
 */
class StockSucursal extends Model
{
    protected $table = 'stock_sucursales';

    protected $fillable = [
        'tenant_id',
        'variante_id',
        'sucursal_id',
        'unidad_medida_id',
        'cantidad',
        'cantidad_reservada',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'string',
            'cantidad_reservada' => 'string',
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

    /** Lo que se puede vender: la cantidad menos lo comprometido. */
    public function disponible(): string
    {
        return Cantidad::desde($this->cantidad)
            ->resta(Cantidad::desde($this->cantidad_reservada))
            ->aEscala(Cantidad::decimalesDeConfiguracion());
    }

    /** La misma cifra, como número, para ordenar y comparar en memoria. */
    public function disponibleComoNumero(): float
    {
        return (float) $this->disponible();
    }

    /** Esta fila esta bajo el mínimo declarado por el producto. */
    public function bajoMinimo(Producto $producto): bool
    {
        $minimo = Cantidad::desde($producto->stock_minimo);

        if ($minimo->esCero()) {
            return false;
        }

        return Cantidad::desde($this->disponible())->comparar($minimo) < 0;
    }
}