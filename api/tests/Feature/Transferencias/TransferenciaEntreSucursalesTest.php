<?php

declare(strict_types=1);

namespace Tests\Feature\Transferencias;

use App\Enums\EstadoTransferencia;
use App\Exceptions\ErrorDeFerreteria;
use App\Models\MovimientoStock;
use App\Models\StockSucursal;
use App\Models\Transferencia;
use App\Services\Stock\RegistroDeMovimientos;
use App\Services\Transferencias\TransferenciasEntreSucursales;
use Tests\Feature\Stock\FerreteriaStub;
use Tests\TestCase;

/**
 * Transferencias entre sucursales.
 *
 * Lo que se comprueba aca es que un traslado sea un DOCUMENTO y no un
 * ajuste invisible: que deje rastro en las dos sucursales, que pase por
 * estados y que lo que no llega quede escrito.
 */
class TransferenciaEntreSucursalesTest extends TestCase
{
    private FerreteriaStub $contexto;
    private TransferenciasEntreSucursales $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contexto = new FerreteriaStub();
        $this->contexto->preparar();

        $this->servicio = app(TransferenciasEntreSucursales::class);

        app(RegistroDeMovimientos::class)->ingresar(
            $this->contexto->variante(),
            $this->contexto->sucursalA->id,
            '100',
            $this->contexto->usuarioBodeguero->id,
            'Guia de compra inicial'
        );
    }

    private function crearTransferencia(array $items): Transferencia
    {
        return $this->servicio->crear(
            $this->contexto->ferreteria->id,
            $this->contexto->sucursalA->id,
            $this->contexto->sucursalB->id,
            $items,
            $this->contexto->usuarioBodeguero->id,
            'Reposición de la bodega central'
        );
    }

    public function test_la_transferencia_nace_en_borrador_y_no_toca_stock(): void
    {
        $transferencia = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '20'],
        ]);

        $this->assertSame(EstadoTransferencia::Borrador, $transferencia->estado);

        // Armar el papel no mueve nada: el stock sigue igual en las dos
        // sucursales.
        $this->assertSame('100.000', $this->saldoEnA()->cantidad);
        $this->assertNull($this->saldoEnB());
    }

    public function test_despachar_descuenta_en_el_origen_y_no_suma_en_el_destino(): void
    {
        $transferencia = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '30'],
        ]);

        $this->servicio->despachar($transferencia, $this->contexto->usuarioBodeguero->id);

        // 100 - 30 = 70 en el origen.
        $this->assertSame('70.000', $this->saldoEnA()->cantidad);

        // El destino sigue sin tener la mercadería: va en camino, no esta
        // recibida. Un solo endpoint para las dos cosas habria hecho que
        // durante el trayecto no hubiera diferencia entre "va en camino" y
        // "no fue".
        $this->assertNull($this->saldoEnB());

        $this->assertSame(EstadoTransferencia::Despachada, $transferencia->refresh()->estado);
        $this->assertTrue($transferencia->estaEnCamino());
    }

    public function test_recibir_suma_en_el_destino_y_deja_el_saldo_final_conservado(): void
    {
        $transferencia = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '30'],
        ]);

        $this->servicio->despachar($transferencia, $this->contexto->usuarioBodeguero->id);
        $this->servicio->recibir($transferencia, $this->contexto->usuarioBodeguero->id);

        // 100 - 30 = 70 en el origen, 0 + 30 = 30 en el destino. El total de
        // las dos sucursales sigue siendo 100.
        $this->assertSame('70.000', $this->saldoEnA()->cantidad);
        $this->assertSame('30.000', $this->saldoEnB()->cantidad);

        $this->assertSame(EstadoTransferencia::Recibida, $transferencia->refresh()->estado);
    }

    public function test_recibir_menos_de_lo_enviado_suma_lo_que_llego_y_lo_deja_escrito(): void
    {
        $transferencia = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '30'],
        ]);

        $this->servicio->despachar($transferencia, $this->contexto->usuarioBodeguero->id);

        // La tienda dice que llegaron 8 de 30.
        $this->servicio->recibir(
            $transferencia,
            $this->contexto->usuarioBodeguero->id,
            [$this->contexto->variante->id => '8']
        );

        $this->assertSame('8.000', $this->saldoEnB()->cantidad);

        // La diferencia queda escrita en el movimiento del destino: la
        // sucursal de origen puede ver que salio algo que no volvio, sin
        // depender de que alguien lo recuerde.
        $movimiento = $this->comoFerreteria(
            $this->contexto->ferreteria->id,
            static fn () => MovimientoStock::query()
                ->where('tipo', 'traslado_entrada')
                ->firstOrFail()
        );

        $this->assertStringContainsString('Recibido 8.000 de 30.000', (string) $movimiento->glosa);
    }

    public function test_el_documento_no_puede_recibirse_sin_haber_sido_despachado(): void
    {
        $transferencia = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '30'],
        ]);

        $this->expectException(ErrorDeFerreteria::class);

        $this->servicio->recibir($transferencia, $this->contexto->usuarioBodeguero->id);
    }

    public function test_no_se_puede_despachar_dos_veces_la_misma_transferencia(): void
    {
        $transferencia = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '30'],
        ]);

        $this->servicio->despachar($transferencia, $this->contexto->usuarioBodeguero->id);

        $this->expectException(ErrorDeFerreteria::class);

        // El segundo despacho descontaria otra vez las mismas 30.
        $this->servicio->despachar($transferencia->refresh(), $this->contexto->usuarioBodeguero->id);
    }

    public function test_no_se_puede_trasladar_mas_de_lo_que_hay_en_el_origen(): void
    {
        $transferencia = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '150'],
        ]);

        $this->expectException(ErrorDeFerreteria::class);

        // 100 disponibles, 150 pedidos. La excepcion revierte la transacción
        // entera: no hay traslado a medias.
        $this->servicio->despachar($transferencia, $this->contexto->usuarioBodeguero->id);
    }

    public function test_anular_una_transferencia_despachada_devuelve_el_stock_al_origen(): void
    {
        $transferencia = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '30'],
        ]);

        $this->servicio->despachar($transferencia, $this->contexto->usuarioBodeguero->id);
        $this->servicio->anular(
            $transferencia->refresh(),
            $this->contexto->usuarioBodeguero->id,
            'Se devolvio la mercadería al proveedor'
        );

        // 70 + 30 = 100. Anular no borra nada: escribe el movimiento de
        // vuelta, con su motivo.
        $this->assertSame('100.000', $this->saldoEnA()->cantidad);
        $this->assertSame(EstadoTransferencia::Anulada, $transferencia->refresh()->estado);
    }

    public function test_anular_una_transferencia_recibida_saca_la_mercaderia_del_destino(): void
    {
        $transferencia = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '30'],
        ]);

        $this->servicio->despachar($transferencia, $this->contexto->usuarioBodeguero->id);
        $this->servicio->recibir($transferencia, $this->contexto->usuarioBodeguero->id);

        $this->servicio->anular(
            $transferencia->refresh(),
            $this->contexto->usuarioBodeguero->id,
            'Mercadería devuelta por el proveedor'
        );

        $this->assertSame('0.000', $this->saldoEnB()->cantidad);
    }

    public function test_una_sucursal_no_se_puede_transferir_a_si_misma(): void
    {
        $this->expectException(ErrorDeFerreteria::class);

        $this->servicio->crear(
            $this->contexto->ferreteria->id,
            $this->contexto->sucursalA->id,
            $this->contexto->sucursalA->id,
            [['variante_id' => $this->contexto->variante->id, 'cantidad' => '5']],
            $this->contexto->usuarioBodeguero->id
        );
    }

    public function test_una_transferencia_sin_renglones_se_rechaza(): void
    {
        $this->expectException(ErrorDeFerreteria::class);

        $this->servicio->crear(
            $this->contexto->ferreteria->id,
            $this->contexto->sucursalA->id,
            $this->contexto->sucursalB->id,
            [],
            $this->contexto->usuarioBodeguero->id
        );
    }

    public function test_las_diferencias_de_recepcion_se_pueden_consultar_antes_de_confirmar(): void
    {
        $transferencia = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '30'],
        ]);

        $diferencias = $this->servicio->diferencias(
            $transferencia,
            [$this->contexto->variante->id => '8']
        );

        $this->assertCount(1, $diferencias);
        $this->assertSame('30.000', $diferencias[0]['enviado']);
        $this->assertSame('8.000', $diferencias[0]['recibido']);
        $this->assertSame('-22.000', $diferencias[0]['diferencia']);
    }

    public function test_el_folio_es_correlativo_dentro_de_la_ferreteria(): void
    {
        $primera = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '5'],
        ]);

        $segunda = $this->crearTransferencia([
            ['variante_id' => $this->contexto->variante->id, 'cantidad' => '5'],
        ]);

        $this->assertSame('1', $primera->folio);
        $this->assertSame('2', $segunda->folio);
    }

    private function saldoEnA(): StockSucursal
    {
        return $this->saldo($this->contexto->sucursalA);
    }

    private function saldoEnB(): ?StockSucursal
    {
        return $this->saldo($this->contexto->sucursalB);
    }

    private function saldo($sucursal): ?StockSucursal
    {
        return StockSucursal::query()
            ->where('variante_id', $this->contexto->variante->id)
            ->where('sucursal_id', $sucursal->id)
            ->first();
    }
}