<?php

declare(strict_types=1);

namespace Tests\Feature\Caja;

use App\Enums\EstadoCaja;
use App\Enums\TipoMovimientoCaja;
use App\Exceptions\ErrorDeFerreteria;
use App\Models\Caja;
use App\Models\Usuario;
use App\Services\Caja\CorteDeCaja;
use Tests\Feature\Stock\FerreteriaStub;
use Tests\TestCase;

/**
 * Corte de caja y diferencia.
 *
 * ---------------------------------------------------------------------
 | POR QUE ESTA SUITE EXISTE
 * ---------------------------------------------------------------------
 | La diferencia de caja es el número que el dueño mira todas las noches.
 * Esta comprueba tres cosas:
 *
 *   1. que la diferencia se guarde COMPLETA y con su signo, nunca redondeada
 *      a cero por una tolerancia;
 *   2. que el saldo se calcule sumando movimientos, para que un retiro del
 *      dueño no descuadre el corte;
 *   3. que no se pueda mover una caja ya cerrada.
 */
class CorteDeCajaTest extends TestCase
{
    private FerreteriaStub $contexto;
    private CorteDeCaja $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contexto = new FerreteriaStub();
        $this->contexto->preparar();

        $this->servicio = app(CorteDeCaja::class);
    }

    private function gerente(): Usuario
    {
        return $this->comoFerreteria(
            $this->contexto->ferreteria->id,
            static fn () => Usuario::factory()->gerente()->create()
        );
    }

    private function abrir(string $montoInicial = '50000'): Caja
    {
        return $this->servicio->abrir(
            $this->gerente(),
            $this->contexto->sucursalA->id,
            $montoInicial
        );
    }

    public function test_una_caja_abierta_reporta_el_monto_inicial_como_esperado(): void
    {
        $caja = $this->abrir('50000');

        $estado = $this->servicio->estado($caja);

        $this->assertSame('abierta', $estado['estado']);
        $this->assertSame('50000.00', $estado['monto_esperado']);
    }

    public function test_el_arqueo_sin_diferencia_guarda_cero_y_lo_dice(): void
    {
        $caja = $this->abrir('50000');

        $cerrada = $this->servicio->cerrar($caja, $this->gerente(), '50000');

        $this->assertSame('cerrada', $cerrada->estado->value);
        $this->assertSame('50000.00', $cerrada->monto_declarado);
        $this->assertSame('50000.00', $cerrada->monto_sistema);
        $this->assertSame('0.00', $cerrada->diferencia);
        $this->assertSame('dentro_de_tolerancia', $cerrada->revision);
    }

    public function test_una_diferencia_a_favor_se_guarda_con_signo_positivo(): void
    {
        $caja = $this->abrir('50000');

        $cerrada = $this->servicio->cerrar($caja, $this->gerente(), '52000');

        // Sobrante: hay más dinero del que el sistema esperaba.
        $this->assertSame('2000.00', $cerrada->diferencia);
        $this->assertSame('requiere_revision', $cerrada->revision);
    }

    public function test_una_diferencia_en_contra_se_guarda_con_signo_negativo(): void
    {
        $caja = $this->abrir('50000');

        $cerrada = $this->servicio->cerrar($caja, $this->gerente(), '48000');

        // Faltante.
        $this->assertSame('-2000.00', $cerrada->diferencia);
        $this->assertSame('requiere_revision', $cerrada->revision);
    }

    public function test_una_diferencia_de_un_peso_no_se_oculta(): void
    {
        $caja = $this->abrir('50000');

        $cerrada = $this->servicio->cerrar($caja, $this->gerente(), '49999');

        // La tolerancia por omisión es cero, así que un peso ya es revision.
        // Lo importante es que la cifra se guarde COMPLETA: nunca se
        // redondea a "0" para que la pantalla muestre que no paso nada.
        $this->assertSame('-1.00', $cerrada->diferencia);
        $this->assertSame('requiere_revision', $cerrada->revision);
    }

    public function test_la_diferencia_se_guarda_aunque_dentro_de_tolerancia(): void
    {
        config(['ferreteria.caja.tolerancia' => 500]);

        $caja = $this->abrir('50000');

        $cerrada = $this->servicio->cerrar($caja, $this->gerente(), '50300');

        // Dentro de la tolerancia, pero la diferencia NO desaparece: el
        // dueño necesita ver el número completo todas las noches.
        $this->assertSame('300.00', $cerrada->diferencia);
        $this->assertSame('dentro_de_tolerancia', $cerrada->revision);
    }

    public function test_un_retiro_del_dueno_baja_el_monto_esperado_del_corte(): void
    {
        $caja = $this->abrir('50000');

        $this->servicio->mover(
            $caja,
            $this->gerente(),
            TipoMovimientoCaja::Retiro,
            '20000',
            'Pago al proveedor'
        );

        $cerrada = $this->servicio->cerrar($caja, $this->gerente(), '30000');

        // El saldo no se deriva de las ventas: es la suma de los
        // movimientos. Por eso un retiro lo baja y el corte sigue
        // cuadrando al cierre.
        $this->assertSame('30000.00', $cerrada->monto_sistema);
        $this->assertSame('0.00', $cerrada->diferencia);
    }

    public function test_un_retiro_exige_glosa(): void
    {
        $caja = $this->abrir('50000');

        $this->expectException(ErrorDeFerreteria::class);

        // "El dinero salio y no dice por que" es la peor forma de cerrar un
        // turno.
        $this->servicio->mover(
            $caja,
            $this->gerente(),
            TipoMovimientoCaja::Retiro,
            '20000'
        );
    }

    public function test_una_caja_cerrada_no_acepta_mas_movimientos(): void
    {
        $caja = $this->abrir('50000');
        $cerrada = $this->servicio->cerrar($caja, $this->gerente(), '50000');

        $this->expectException(ErrorDeFerreteria::class);

        // El corte entregado ya es evidencia: no se toca más.
        $this->servicio->mover(
            $cerrada,
            $this->gerente(),
            TipoMovimientoCaja::Ajuste,
            '1000',
            'Correccion posterior'
        );
    }

    public function test_no_se_puede_cerrar_dos_veces_el_mismo_corte(): void
    {
        $caja = $this->abrir('50000');
        $cerrada = $this->servicio->cerrar($caja, $this->gerente(), '50000');

        $this->expectException(ErrorDeFerreteria::class);

        $this->servicio->cerrar($cerrada, $this->gerente(), '49000');
    }

    public function test_una_sucursal_no_puede_tener_dos_cajas_abiertas(): void
    {
        $this->abrir('50000');

        $this->expectException(ErrorDeFerreteria::class);

        // Dos cajas abiertas mezclarian las ventas de dos turnos y la
        // diferencia sería imposible de repartir.
        $this->abrir('20000');
    }

    public function test_una_sucursal_puede_tener_varios_cortes_cerrados(): void
    {
        $primera = $this->abrir('50000');
        $this->servicio->cerrar($primera, $this->gerente(), '50000');

        $segunda = $this->abrir('50000');

        // El indice que limita a una caja abierta es PARCIAL: mira solo las
        // abiertas, así que los cortes cerrados se acumulan sin problema.
        $this->assertSame(EstadoCaja::Abierta, $segunda->estado);
        $this->assertSame('2', $segunda->folio);
    }

    public function test_el_movimiento_de_retiro_se_guarda_con_signo_negativo(): void
    {
        $caja = $this->abrir('50000');

        $this->servicio->mover(
            $caja,
            $this->gerente(),
            TipoMovimientoCaja::Retiro,
            '20000',
            'Pago al proveedor'
        );

        $movimiento = $caja->movimientos()->firstOrFail();

        // La pantalla escribe el monto en positivo y el servicio pone el
        // signo según el tipo. Si el signo lo mandara la pantalla, un doble
        // clic con el menos puestoeria dos veces.
        $this->assertSame('-20000.00', $movimiento->monto);
    }

    public function test_el_estado_del_corte_resume_los_movimientos_por_tipo(): void
    {
        $caja = $this->abrir('50000');

        $this->servicio->mover($caja, $this->gerente(), TipoMovimientoCaja::Cobro, '30000', 'Cobro con tarjeta');
        $this->servicio->mover($caja, $this->gerente(), TipoMovimientoCaja::Retiro, '10000', 'Retiro para el proveedor');

        $estado = $this->servicio->estado($caja->refresh());

        $this->assertSame('30000.00', $estado['movimientos_por_tipo']['cobro']);
        $this->assertSame('-10000.00', $estado['movimientos_por_tipo']['retiro']);
        $this->assertSame('70000.00', $estado['monto_esperado']);
    }

    public function test_un_movimiento_de_monto_cero_se_rechaza(): void
    {
        $caja = $this->abrir('50000');

        $this->expectException(ErrorDeFerreteria::class);

        $this->servicio->mover(
            $caja,
            $this->gerente(),
            TipoMovimientoCaja::Deposito,
            '0',
            'Un movimiento de cero'
        );
    }
}