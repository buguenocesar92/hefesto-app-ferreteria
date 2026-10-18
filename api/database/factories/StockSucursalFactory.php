<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ferreteria;
use App\Models\Sucursal;
use App\Models\Variante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockSucursal>
 */
class StockSucursalFactory extends Factory
{
    protected $model = \App\Models\StockSucursal::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Ferreteria::factory(),
            'variante_id' => Variante::factory(),
            'sucursal_id' => Sucursal::factory(),
            'unidad_medida_id' => null,
            'cantidad' => '0.000',
            'cantidad_reservada' => '0.000',
        ];
    }

    /**
     * Saldo de una variante en una sucursal.
     *
     * La unidad sale de la variante: el saldo SIEMPRE se cuenta en la unidad
     * de venta, que es la que el mostrador pide y la que se descuenta.
     */
    public function de(Variante $variante, Sucursal $sucursal, string $cantidad = '0'): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $variante->tenant_id,
            'variante_id' => $variante->id,
            'sucursal_id' => $sucursal->id,
            'unidad_medida_id' => $variante->unidad_venta_id,
            'cantidad' => number_format((float) $cantidad, 3, '.', ''),
        ]);
    }

    public function reservado(string $cantidad): static
    {
        return $this->state(fn (): array => [
            'cantidad_reservada' => number_format((float) $cantidad, 3, '.', ''),
        ]);
    }
}