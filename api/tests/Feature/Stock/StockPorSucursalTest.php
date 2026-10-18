<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Exceptions\ErrorDeFerreteria;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\StockSucursal;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Stock\RegistroDeMovimientos;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stock por sucursal.
 *
 * Lo que se comprueba aca es el defecto más caro de una ferreteria: que el
 * stock no cuadre. Concretamente, que cada sucursal tenga SUYO, que el saldo
 * no se pueda editar a mano y que el disponible sea la cifra que se puede
 * vender.
 */
class StockPorSucursalTest extends TestCase
{
    private FerreteriaStub $contexto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contexto = new FerreteriaStub();
        $this->contexto->preparar();
    }

    public function test_cada_sucursal_tiene_su_propio_saldo_de_la_misma_variante(): void
    {
        $variante = $this->contexto->variante();

        app(RegistroDeMovimientos::class)->ingresar(
            $variante,
            $this->contexto->sucursalA->id,
            '100',
            $this->contexto->usuarioBodeguero->id,
            'Guia 1234'
        );

        app(RegistroDeMovimientos::class)->ingresar(
            $variante,
            $this->contexto->sucursalB->id,
            '30',
            $this->contexto->usuarioBodeguero->id,
            'Guia 1235'
        );

        $enA = $this->contexto->saldo($variante, $this->contexto->sucursalA);
        $enB = $this->contexto->saldo($variante, $this->contexto->sucursalB);

        // Un solo saldo en el producto habria mostrado 130 en las dos
        // tiendas. Con un saldo por terna, cada una tiene lo suyo.
        $this->assertSame('100.000', $enA->cantidad);
        $this->assertSame('30.000', $enB->cantidad);
    }

    public function test_el_saldo_es_la_suma_de_sus_movimientos(): void
    {
        $variante = $this->contexto->variante();
        $registro = app(RegistroDeMovimientos::class);

        $registro->ingresar($variante, $this->contexto->sucursalA->id, '100', $this->contexto->usuarioBodeguero->id, 'Guia 1');
        $registro->ingresar($variante, $this->contexto->sucursalA->id, '50', $this->contexto->usuarioBodeguero->id, 'Guia 2');
        $registro->registrar(
            variante: $variante,
            sucursalId: $this->contexto->sucursalA->id,
            tipo: \App\Enums\TipoMovimientoStock::Ajuste,
            cantidad: '30',
            usuarioId: $this->contexto->usuarioBodeguero->id,
            glosa: 'Conteo fisico del dia: sobraban treinta menos'
        );

        $saldo = $this->contexto->saldo($variante, $this->contexto->sucursalA);

        // 100 + 50 - 30 = 120. El ajuste resta porque su signo lo decide el
        // motivo, no como se escribio la cantidad.
        $this->assertSame('120.000', $saldo->cantidad);
    }

    public function test_el_disponible_es_la_cantidad_menos_lo_comprometido(): void
    {
        $variante = $this->contexto->variante();
        $registro = app(RegistroDeMovimientos::class);

        $registro->ingresar($variante, $this->contexto->sucursalA->id, '100', $this->contexto->usuarioBodeguero->id, 'Guia 1');

        $saldo = $this->contexto->saldo($variante, $this->contexto->sucursalA);

        // Sin nada comprometido, el disponible es la cantidad entera.
        $this->assertSame('100.000', $saldo->disponible());

        // Con 40 comprometidas, se pueden vender 60. El disponible NO es una
        // columna: se deriva, para que no pueda quedar desincronizado.
        $this->comoFerreteria($this->contexto->ferreteria->id, function () use ($saldo): void {
            DB::statement("SELECT set_config('app.stock_movimiento', 'true', true)");

            $saldo->forceFill(['cantidad_reservada' => '40.000'])->save();
        });

        $this->assertSame('60.000', $saldo->refresh()->disponible());
    }

    public function test_el_saldo_no_se_puede_editar_sin_movimiento(): void
    {
        $variante = $this->contexto->variante();

        app(RegistroDeMovimientos::class)->ingresar(
            $variante,
            $this->contexto->sucursalA->id,
            '100',
            $this->contexto->usuarioBodeguero->id,
            'Guia 1'
        );

        // Un `UPDATE` escrito a mano, como lo haria alguien conectado al
        // `psql` con la intention de "corregir" el número. La base lo
        // rechaza: no hay movimiento que lo justifique.
        $this->expectException(\Throwable::class);

        DB::statement(
            "UPDATE stock_sucursales SET cantidad = '9999' WHERE variante_id = ? AND sucursal_id = ?",
            [$variante->id, $this->contexto->sucursalA->id]
        );
    }

    public function test_un_movimiento_no_se_puede_editar_ni_borrar(): void
    {
        $variante = $this->contexto->variante();

        app(RegistroDeMovimientos::class)->ingresar(
            $variante,
            $this->contexto->sucursalA->id,
            '100',
            $this->contexto->usuarioBodeguero->id,
            'Guia 1'
        );

        $movimiento = $this->comoFerreteria(
            $this->contexto->ferreteria->id,
            static fn () => MovimientoStock::query()->firstOrFail()
        );

        $this->expectException(\Throwable::class);

        DB::statement(
            "UPDATE movimientos_stock SET cantidad = '1' WHERE id = ?",
            [$movimiento->id]
        );
    }

    public function test_no_se_puede_dejar_el_saldo_en_negativo(): void
    {
        $variante = $this->contexto->variante();
        $registro = app(RegistroDeMovimientos::class);

        $registro->ingresar($variante, $this->contexto->sucursalA->id, '10', $this->contexto->usuarioBodeguero->id, 'Guia 1');

        $this->expectException(ErrorDeFerreteria::class);

        $registro->registrar(
            variante: $variante,
            sucursalId: $this->contexto->sucursalA->id,
            tipo: \App\Enums\TipoMovimientoStock::Merma,
            cantidad: '25',
            usuarioId: $this->contexto->usuarioBodeguero->id,
            glosa: 'Se rompieron durante la manipulacion'
        );
    }

    public function test_un_movimiento_de_motivo_sin_documento_exige_glosa(): void
    {
        $variante = $this->contexto->variante();

        $this->expectException(ErrorDeFerreteria::class);

        app(RegistroDeMovimientos::class)->registrar(
            variante: $variante,
            sucursalId: $this->contexto->sucursalA->id,
            tipo: \App\Enums\TipoMovimientoStock::Ajuste,
            cantidad: '5',
            usuarioId: $this->contexto->usuarioBodeguero->id
        );
    }

    public function test_un_movimiento_de_venta_exige_el_documento_de_venta(): void
    {
        $variante = $this->contexto->variante();

        $this->expectException(ErrorDeFerreteria::class);

        // Una venta sin documento dejaria stock menos sin que exista una
        // venta que lo explique.
        app(RegistroDeMovimientos::class)->registrar(
            variante: $variante,
            sucursalId: $this->contexto->sucursalA->id,
            tipo: \App\Enums\TipoMovimientoStock::Venta,
            cantidad: '5',
            usuarioId: $this->contexto->usuarioBodeguero->id
        );
    }

    public function test_el_ajuste_no_deja_el_stock_bajo_lo_comprometido(): void
    {
        $variante = $this->contexto->variante();
        $registro = app(RegistroDeMovimientos::class);

        $registro->ingresar($variante, $this->contexto->sucursalA->id, '100', $this->contexto->usuarioBodeguero->id, 'Guia 1');

        $saldo = $this->contexto->saldo($variante, $this->contexto->sucursalA);

        $this->comoFerreteria($this->contexto->ferreteria->id, function () use ($saldo): void {
            DB::statement("SELECT set_config('app.stock_movimiento', 'true', true)");

            $saldo->forceFill(['cantidad_reservada' => '80.000'])->save();
        });

        $this->expectException(ErrorDeFerreteria::class);

        // Quedaria en 50, por debajo de las 80 comprometidas.
        $registro->ajustar(
            $variante,
            $this->contexto->sucursalA->id,
            '50',
            $this->contexto->usuarioBodeguero->id,
            'Correccion de conteo mal tomado'
        );
    }

    public function test_la_fila_de_stock_hereda_la_unidad_de_venta_de_la_variante(): void
    {
        $variante = $this->contexto->variante();

        app(RegistroDeMovimientos::class)->ingresar(
            $variante,
            $this->contexto->sucursalA->id,
            '5',
            $this->contexto->usuarioBodeguero->id,
            'Guia 1'
        );

        $saldo = $this->contexto->saldo($variante, $this->contexto->sucursalA);

        // El saldo se cuenta en la unidad en que el mostrador pide la
        // cantidad, que es la unidad de venta de la variante.
        $this->assertSame($variante->unidad_venta_id, $saldo->unidad_medida_id);
    }

    public function test_no_hay_dos_filas_para_la_misma_terna(): void
    {
        $variante = $this->contexto->variante();

        $primera = app(RegistroDeMovimientos::class)->saldoDe($variante, $this->contexto->sucursalA->id);
        $segunda = app(RegistroDeMovimientos::class)->saldoDe($variante, $this->contexto->sucursalA->id);

        // Dos filas para la misma terna pelearian el mismo stock y la suma
        // de las dos daria más de lo que hay.
        $this->assertSame($primera->id, $segunda->id);
    }
}

/**
 * Datos compartidos por las pruebas de stock.
 *
 * Existe como clase aparte para que cada prueba no tenga que repetir la
 * misma construccion, y para que quede claro que las dos sucursales y la
 * variante son SIEMPRE las mismas al comparar saldos.
 */
class FerreteriaStub
{
    public \App\Models\Ferreteria $ferreteria;
    public UnidadMedida $unidadBase;
    public UnidadMedida $bolsa50;
    public Producto $producto;
    public Variante $variante;
    public Sucursal $sucursalA;
    public Sucursal $sucursalB;
    public Usuario $usuarioBodeguero;

    public function preparar(): void
    {
        $contexto = app(\App\Services\Tenancy\ContextoTenant::class);

        $this->ferreteria = \App\Models\Ferreteria::factory()->create();

        $contexto->ejecutarAislado($this->ferreteria->id, function (): void {
            $this->unidadBase = UnidadMedida::factory()->base()->create();
            $this->bolsa50 = UnidadMedida::factory()->derivada(
                $this->unidadBase,
                '50',
                'BOLSA50',
                'Bolsa de 50'
            )->create();

            $this->producto = Producto::factory()->enUnidadBase($this->unidadBase, '100')->create();

            $this->variante = Variante::factory()
                ->conUnidades($this->producto, $this->bolsa50, $this->bolsa50, '50')
                ->conPrecio('1900.00')
                ->create();

            $this->sucursalA = Sucursal::factory()->conCodigo('AAA', 'Sucursal A')->create();
            $this->sucursalB = Sucursal::factory()->conCodigo('BBB', 'Sucursal B')->create();

            $this->usuarioBodeguero = Usuario::factory()->bodeguero()->create();
        });
    }

    public function variante(): Variante
    {
        return $this->variante->refresh();
    }

    public function saldo(Variante $variante, Sucursal $sucursal): StockSucursal
    {
        return StockSucursal::query()
            ->where('variante_id', $variante->id)
            ->where('sucursal_id', $sucursal->id)
            ->firstOrFail();
    }
}