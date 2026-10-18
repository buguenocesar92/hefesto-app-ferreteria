<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Enums\TipoMovimientoStock;
use App\Exceptions\ErrorDeFerreteria;
use App\Models\MovimientoStock;
use App\Models\StockSucursal;
use App\Models\Variante;
use App\Support\Cantidad;
use Illuminate\Support\Facades\DB;

/**
 * Escritura de stock: la única puerta por la que se mueve un saldo.
 *
 * ---------------------------------------------------------------------
 | POR QUE NO HAY NINGUN OTRO CAMINO
 * ---------------------------------------------------------------------
 | Ni la pantalla, ni un comando de artisan, ni un `psql` pueden cambiar un
 | saldo sin escribir el movimiento que lo justifica. En esta clase estan las
 | dos mitades de la operación, en la misma transacción y con la marca que el
 | gatillo de PostgreSQL exige.
 *
 * El orden importa y es deliberate:
 *
 |   1. se levanta la marca `app.stock_movimiento`
 |   2. se inserta el movimiento (con su signo)
 |   3. se actualiza el saldo de la terna
 *
 | Si algo falla entre medio, la transacción se revierte entera: no queda un
 | movimiento sin saldo ni un saldo sin movimiento.
 *
 * ---------------------------------------------------------------------
 | LA UNIDAD DEL MOVIMIENTO
 * ---------------------------------------------------------------------
 | El saldo se cuenta en la unidad de venta de la variante. El movimiento va
 | en esa misma unidad, sin conversiones: si el mostrador vendio en otra
 | unidad, la conversión se hizo ANTES, al armar el renglón de la venta, y lo
 | que entra aquí ya esta en la unidad del saldo.
 */
class RegistroDeMovimientos
{
    /** Marca que el gatillo de la base exige para tocar un saldo. */
    public const MARCA = 'app.stock_movimiento';

    /**
     * Registra un movimiento y ajusta el saldo.
     *
     * @param  string  $cantidad  Con signo: positiva suma, negativa resta.
     * @throws ErrorDeFerreteria si el saldo quedaria negativo.
     */
    public function registrar(
        Variante $variante,
        string $sucursalId,
        TipoMovimientoStock $tipo,
        string $cantidad,
        string $usuarioId,
        ?string $glosa = null,
        ?string $referenciaTipo = null,
        ?string $referenciaId = null,
        ?\DateTimeInterface $ocurrioEn = null,
    ): MovimientoStock {
        $cantidad = Cantidad::desde($cantidad, Cantidad::decimalesDeConfiguracion());

        if ($cantidad->esCero()) {
            throw new ErrorDeFerreteria(
                'Un movimiento de cantidad cero no dice nada y solo ensucia la historia.',
                'cantidad_cero'
            );
        }

        if ($tipo->exigeGlosa() && ($glosa === null || trim($glosa) === '')) {
            throw new ErrorDeFerreteria(
                'Un movimiento de tipo «' . $tipo->value . '» necesita una glosa que explique por qué se movió el stock.',
                'glosa_obligatoria'
            );
        }

        if ($tipo->vieneDeDocumento() && ($referenciaTipo === null || $referenciaId === null)) {
            throw new ErrorDeFerreteria(
                'Un movimiento de tipo «' . $tipo->value . '» tiene que venir de un documento.',
                'falta_documento'
            );
        }

        // El signo sale del motivo, no de como se escribio la cantidad. Así el
        // tipo y la cantidad nunca se contradicen entre si.
        $conSigno = $tipo->suma() ? $cantidad : $cantidad->negado();

        return DB::transaction(function () use (
            $variante,
            $sucursalId,
            $tipo,
            $conSigno,
            $usuarioId,
            $glosa,
            $referenciaTipo,
            $referenciaId,
            $ocurrioEn
        ): MovimientoStock {
            DB::select('SELECT set_config(?, ?, true)', [self::MARCA, 'true']);

            $saldo = $this->saldoDe($variante, $sucursalId);

            $actual = Cantidad::desde($saldo->cantidad);
            $reservado = Cantidad::desde($saldo->cantidad_reservada);

            $nuevoSaldo = $actual->suma($conSigno);

            if ($nuevoSaldo->esNegativo()) {
                throw new ErrorDeFerreteria(
                    'No hay stock suficiente de «' . $variante->nombre . '» en esta sucursal. '
                    . 'Disponible: ' . $actual->aEscala(Cantidad::decimalesDeConfiguracion())
                    . ' ' . $variante->unidadVenta->codigo . '.',
                    'stock_insuficiente'
                );
            }

            if ($nuevoSaldo->comparar($reservado) < 0) {
                throw new ErrorDeFerreteria(
                    'El movimiento dejaría el stock por debajo de lo comprometido en pedidos.',
                    'stock_comprometido'
                );
            }

            $movimiento = MovimientoStock::create([
                'tenant_id' => $variante->tenant_id,
                'variante_id' => $variante->id,
                'sucursal_id' => $sucursalId,
                'unidad_medida_id' => $variante->unidad_venta_id,
                'tipo' => $tipo->value,
                'cantidad' => $conSigno->aEscala(Cantidad::decimalesDeConfiguracion()),
                'referencia_tipo' => $referenciaTipo,
                'referencia_id' => $referenciaId,
                'glosa' => $glosa,
                'usuario_id' => $usuarioId,
                'ocurrio_en' => $ocurrioEn ?? now(),
            ]);

            $saldo->cantidad = $nuevoSaldo->aEscala(Cantidad::decimalesDeConfiguracion());
            $saldo->save();

            return $movimiento;
        });
    }

    /** Ingreso de mercadería: suma stock, con la glosa del proveedor. */
    public function ingresar(
        Variante $variante,
        string $sucursalId,
        string $cantidad,
        string $usuarioId,
        string $glosa,
        ?\DateTimeInterface $ocurrioEn = null,
    ): MovimientoStock {
        return $this->registrar(
            $variante,
            $sucursalId,
            TipoMovimientoStock::Ingreso,
            $cantidad,
            $usuarioId,
            $glosa,
            null,
            null,
            $ocurrioEn
        );
    }

    /**
     * Ajuste con justificacion.
     *
     * Es el único camino para corregir un saldo sin documento. Se escribe
     * como movimiento de tipo `ajuste` con su glosa, no editando el saldo.
     */
    public function ajustar(
        Variante $variante,
        string $sucursalId,
        string $cantidad,
        string $usuarioId,
        string $glosa,
    ): MovimientoStock {
        return $this->registrar(
            $variante,
            $sucursalId,
            TipoMovimientoStock::Ajuste,
            $cantidad,
            $usuarioId,
            $glosa
        );
    }

    /**
     * Devuelve el saldo de la terna, creando la fila si no existe.
     *
     * La fila se crea con cantidad cero para que el primer ingreso sea un
     * `UPDATE` y no tenga que hacer el camino especial de insertar un saldo
     * nuevo.
     */
    public function saldoDe(Variante $variante, string $sucursalId): StockSucursal
    {
        return StockSucursal::firstOrCreate(
            [
                'tenant_id' => $variante->tenant_id,
                'variante_id' => $variante->id,
                'sucursal_id' => $sucursalId,
            ],
            [
                'unidad_medida_id' => $variante->unidad_venta_id,
                'cantidad' => '0',
                'cantidad_reservada' => '0',
            ]
        );
    }
}