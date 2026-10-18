<?php

declare(strict_types=1);

namespace Tests\Feature\Mostrador;

use App\Exceptions\ErrorDeFerreteria;
use App\Models\ListaPrecio;
use App\Models\ListaPrecioDetalle;
use App\Models\StockSucursal;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Stock\RegistroDeMovimientos;
use App\Services\Ventas\Mostrador;
use Tests\Feature\Stock\FerreteriaStub;
use Tests\TestCase;

/**
 * Venta de mostrador con varios renglones.
 *
 * Lo que se comprueba aca es lo que más caro sale cuando falla: que el
 * precio lo decida el sistema y no la pantalla, y que una venta sea una
 * transacción completa o no sea nada.
 */
class VentaDeMostradorTest extends TestCase
{
    private FerreteriaStub $contexto;
    private Mostrador $mostrador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contexto = new FerreteriaStub();
        $this->contexto->preparar();

        $this->mostrador = app(Mostrador::class);

        app(RegistroDeMovimientos::class)->ingresar(
            $this->contexto->variante(),
            $this->contexto->sucursalA->id,
            '100',
            $this->contexto->usuarioBodeguero->id,
            'Guia de compra inicial'
        );
    }

    private function vendedor(): Usuario
    {
        return $this->comoFerreteria(
            $this->contexto->ferreteria->id,
            static fn () => Usuario::factory()->vendedor()->create()
        );
    }

    public function test_una_venta_descuenta_el_stock_de_la_variante_vendida(): void
    {
        $vendedor = $this->vendedor();

        $venta = $this->mostrador->registrar(
            $vendedor,
            $this->contexto->sucursalA->id,
            [['variante_id' => $this->contexto->variante->id, 'cantidad' => '3']]
        );

        $saldo = StockSucursal::query()
            ->where('variante_id', $this->contexto->variante->id)
            ->where('sucursal_id', $this->contexto->sucursalA->id)
            ->firstOrFail();

        // 100 - 3 = 97.
        $this->assertSame('97.000', $saldo->cantidad);
        $this->assertSame('registrada', $venta->estado->value);
    }

    public function test_el_total_se_calcula_con_el_precio_de_la_variante_y_no_con_el_que_manda_la_pantalla(): void
    {
        $vendedor = $this->vendedor();

        $cotizacion = $this->mostrador->cotizar([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '2'],
        ]);

        // 2 bolsas a 1.900 = 3.800.
        $this->assertSame('1900.00', $cotizacion['renglones'][0]['precio_unitario']);
        $this->assertSame('3800.00', $cotizacion['total']);
        $this->assertSame('mostrador', $cotizacion['renglones'][0]['precio_desde']);
    }

    public function test_la_lista_de_mayorista_cambia_el_precio_sin_tocar_la_variante(): void
    {
        $vendedor = $this->vendedor();

        $mayorista = $this->comoFerreteria($this->contexto->ferreteria->id, function (): ListaPrecio {
            $lista = ListaPrecio::factory()->mayorista()->create();

            ListaPrecioDetalle::create([
                'tenant_id' => $this->contexto->ferreteria->id,
                'lista_precios_id' => $lista->id,
                'variante_id' => $this->contexto->variante->id,
                'precio' => '1700.00',
            ]);

            return $lista;
        });

        $cotizacion = $this->mostrador->cotizar(
            [['variante_id' => $this->contexto->variante->id, 'cantidad' => '2']],
            $mayorista
        );

        // El precio de la lista gana sobre el de la variante, y el origen
        // queda declarado para que la caja pueda explicarlo.
        $this->assertSame('1700.00', $cotizacion['renglones'][0]['precio_unitario']);
        $this->assertSame('lista', $cotizacion['renglones'][0]['precio_desde']);
        $this->assertSame('3400.00', $cotizacion['total']);

        // La variante sigue con su precio de mostrador intacto.
        $this->assertSame('1900.00', $this->contexto->variante()->precio_venta);
    }

    public function test_una_venta_sin_stock_suficiente_no_deja_rastro(): void
    {
        $vendedor = $this->vendedor();

        $foliosAntes = Venta::query()->count();

        try {
            $this->mostrador->registrar(
                $vendedor,
                $this->contexto->sucursalA->id,
                [['variante_id' => $this->contexto->variante->id, 'cantidad' => '500']]
            );

            $this->fail('Se esperaba un error de stock insuficiente.');
        } catch (ErrorDeFerreteria $e) {
            $this->assertSame('stock_insuficiente', $e->codigo());
        }

        // La venta se revierte entera: no queda una venta a medias que
        // alguien tenga que arreglar a mano.
        $this->assertSame($foliosAntes, Venta::query()->count());
    }

    public function test_una_venta_con_varios_renglones_suma_el_stock_de_cada_variante(): void
    {
        $segundaVariante = $this->comoFerreteria(
            $this->contexto->ferreteria->id,
            function (): \App\Models\Variante {
                $otra = $this->contexto->variante();

                $variante = \App\Models\Variante::factory()
                    ->conUnidades(
                        $this->contexto->producto,
                        $this->contexto->bolsa50,
                        $this->contexto->bolsa50,
                        '50'
                    )
                    ->matriz(
                        $this->contexto->producto,
                        '3/4"',
                        'Acero',
                        'Pasivado',
                        '50',
                        '2400.00'
                    )
                    ->create();

                app(RegistroDeMovimientos::class)->ingresar(
                    $otra->refresh(),
                    $this->contexto->sucursalA->id,
                    '100',
                    $this->contexto->usuarioBodeguero->id,
                    'Guia de compra inicial'
                );

                return $variante;
            }
        );

        app(RegistroDeMovimientos::class)->ingresar(
            $segundaVariante,
            $this->contexto->sucursalA->id,
            '100',
            $this->contexto->usuarioBodeguero->id,
            'Guia de compra inicial'
        );

        $venta = $this->mostrador->registrar(
            $this->vendedor(),
            $this->contexto->sucursalA->id,
            [
                ['variante_id' => $this->contexto->variante->id, 'cantidad' => '2'],
                ['variante_id' => $segundaVariante->id, 'cantidad' => '1'],
            ]
        );

        // 2 x 1.900 + 1 x 2.400 = 6.200.
        $this->assertSame('6200.00', $venta->total);
        $this->assertCount(2, $venta->items);
    }

    public function test_anular_una_venta_devuelve_el_stock_y_no_borra_la_venta(): void
    {
        $vendedor = $this->vendedor();

        $venta = $this->mostrador->registrar(
            $vendedor,
            $this->contexto->sucursalA->id,
            [['variante_id' => $this->contexto->variante->id, 'cantidad' => '10']]
        );

        $this->mostrador->anular($venta, $vendedor, 'El cliente devolvio el material');

        $saldo = StockSucursal::query()
            ->where('variante_id', $this->contexto->variante->id)
            ->where('sucursal_id', $this->contexto->sucursalA->id)
            ->firstOrFail();

        // 100 - 10 + 10 = 100. Y la venta sigue existiendo: es la evidencia
        // de que en algun momento se cobro por esos articulos.
        $this->assertSame('100.000', $saldo->cantidad);
        $this->assertSame('anulada', $venta->refresh()->estado->value);
    }

    public function test_el_precio_unitario_guardado_es_el_que_se_cobro(): void
    {
        $mayorista = $this->comoFerreteria($this->contexto->ferreteria->id, function (): ListaPrecio {
            $lista = ListaPrecio::factory()->mayorista()->create();

            ListaPrecioDetalle::create([
                'tenant_id' => $this->contexto->ferreteria->id,
                'lista_precios_id' => $lista->id,
                'variante_id' => $this->contexto->variante->id,
                'precio' => '1700.00',
            ]);

            return $lista;
        });

        $venta = $this->mostrador->registrar(
            $this->vendedor(),
            $this->contexto->sucursalA->id,
            [['variante_id' => $this->contexto->variante->id, 'cantidad' => '2']],
            $mayorista
        );

        $item = $venta->items()->firstOrFail();

        // Lo que queda en el renglón es el precio cobrado, no el de lista.
        // Aplicar el margen otra vez sobre este valor sería aplicarlo dos
        // veces.
        $this->assertSame('1700.00', $item->precio_unitario);
        $this->assertSame('3400.00', $item->subtotal);
        $this->assertSame('3400.00', $venta->total);
    }

    public function test_una_venta_sin_renglones_se_rechaza(): void
    {
        $this->expectException(ErrorDeFerreteria::class);

        $this->mostrador->registrar(
            $this->vendedor(),
            $this->contexto->sucursalA->id,
            []
        );
    }
}