<?php

declare(strict_types=1);

namespace Tests\Unit\Unidades;

use App\Support\Cantidad;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Conversión entre unidades de medida.
 *
 * ---------------------------------------------------------------------
 | POR QUE ESTA SUITE NO NECESITA BASE DE DATOS
 * ---------------------------------------------------------------------
 | Porque la conversión es aritmética sobre el factor declarado. La parte
 | que depende de la base (que dos unidades sean de la misma familia) se
 | prueba en `tests/Feature`, donde si hay `unidades_medida` reales.
 *
 | Lo que se comprueba aca es lo que más se rompe sin que nadie lo note: la
 * aritmética con decimales. Un `float` haria pasar casi todos estos casos y
 * fallaria en el que importa.
 */
class ConversionDeUnidadesTest extends TestCase
{
    /**
     * La aritmética de cantidades no acumula error de coma flotante.
     */
    public function test_la_suma_de_decimales_es_exacta(): void
    {
        $resultado = Cantidad::desde('0.1')->suma(Cantidad::desde('0.2'));

        // Con `float`, 0.1 + 0.2 = 0.30000000000000004.
        $this->assertSame('0.3', $resultado->aEscala(1));
    }

    public function test_la_suma_de_cien_precios_no_se_desvia(): void
    {
        $total = Cantidad::cero(2);

        for ($i = 0; $i < 100; $i++) {
            $total = $total->suma(Cantidad::desde('1.19'));
        }

        // Con `float`, este total es 118,99999999999996 y se muestra como
        // 119,00 hasta que se le resta algo.
        $this->assertSame('119.00', $total->aEscala(2));
    }

    public function test_los_tres_ejes_de_unidad_se_convierten_exactos(): void
    {
        // Bolsa de 40 kg: 1 bolsa = 40 kg, porque el factor es 40.
        $kilos = Cantidad::desde('1')->multiplicaPorFraccion(40, 1, 9);

        $this->assertSame('40.000000000', $kilos->aEscala(9));

        // 12,5 kilos son 12500 gramos, y esa cuenta tiene que dar exacta.
        $gramos = Cantidad::desde('12.5')->multiplicaPorFraccion(1000, 1, 3);

        $this->assertSame('12500.000', $gramos->aEscala(3));
    }

    public function test_la_conversion_con_factor_fraccionario_no_usa_coma_flotante(): void
    {
        // Un pie son 0,3048 metros: factor 3048/1000000.
        $resultado = Cantidad::desde('1')->multiplicaPorFraccion(3048, 1000000, 6);

        $this->assertSame('0.304800', $resultado->aEscala(6));

        // Doce pies: 12 x 0,3048 = 3,6576 metros. Exacto.
        $doce = Cantidad::desde('12')->multiplicaPorFraccion(3048, 1000000, 6);

        $this->assertSame('3.657600', $doce->aEscala(6));
    }

    public function test_la_division_entera_no_depende_del_tamano_del_entero(): void
    {
        // Con una cantidad de seis decimales, un `int` de PHP se quedaria
        // corto. La division se hace sobre la cadena de dígitos.
        $resultado = Cantidad::desde('1')->dividirPorEntero(3, 10);

        $this->assertSame('0.3333333333', $resultado->aEscala(10));

        // Y la de ida y vuelta conserva lo que se puede conservar.
        $vuelta = $resultado->multiplicaPorEntero(3);

        $this->assertSame('0.9999999999', $vuelta->aEscala(10));
    }

    public function test_la_resta_detecta_el_signo_correcto(): void
    {
        $falta = Cantidad::desde('100.00')->resta(Cantidad::desde('118.00'));

        $this->assertTrue($falta->esNegativo());
        $this->assertSame('-18.00', $falta->aEscala(2));

        $sobra = Cantidad::desde('120.00')->resta(Cantidad::desde('118.00'));

        $this->assertTrue($sobra->esPositivo());
        $this->assertSame('2.00', $sobra->aEscala(2));
    }

    public function test_el_redondeo_de_cantidades_aparta_ceros(): void
    {
        // Una cantidad fisica no inventa medio gramo.
        $resultado = Cantidad::desde('12.005')->redondear(3);

        $this->assertSame('12.005', $resultado->aEscala(3));

        // El redondeo comercial si sube cuando hay algo distinto de cero
        // después del cinco. Es el que se usa para montos.
        $monto = Cantidad::desde('12.005')->redondearComercial(2);

        $this->assertSame('12.01', $monto->aEscala(2));
    }

    public function test_el_redondeo_agrupa_a_lo_mas_alto_no_a_lo_mas_bajo(): void
    {
        $resultado = Cantidad::desde('2.3456')->redondearComercial(2);

        $this->assertSame('2.35', $resultado->aEscala(2));
    }

    public function test_acepta_coma_o_punto_como_separador_decimal(): void
    {
        $this->assertSame('12.5', Cantidad::desde('12,5')->aEscala(1));
        $this->assertSame('12.5', Cantidad::desde('12.5')->aEscala(1));
    }

    public function test_rechaza_una_cantidad_que_no_es_numero_en_vez_de_convertirla_en_cero(): void
    {
        // Un "12,5,5" convertido en 12 sería peor que un error: el error se
        // ve y el número falso se cobra.
        $this->expectException(InvalidArgumentException::class);

        Cantidad::desde('12,5,5');
    }

    public function test_rechaza_una_cantidad_vacia(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cantidad::desde('   ');
    }

    public function test_la_comparacion_distingue_igual_menos_y_mas(): void
    {
        $this->assertSame(0, Cantidad::desde('10')->comparar(Cantidad::desde('10.00')));
        $this->assertSame(-1, Cantidad::desde('9')->comparar(Cantidad::desde('10')));
        $this->assertSame(1, Cantidad::desde('10.001')->comparar(Cantidad::desde('10')));
    }

    public function test_multiplicar_precios_cantidades_no_produce_error_de_redondeo_acumulado(): void
    {
        // El caso real del mostrador: 1.190,00 por 3,333 unidades de cemento.
        $precio = Cantidad::desde('1190.00');
        $cantidad = Cantidad::desde('3.333');

        $resultado = $precio->multiplicaPorEntero(3333)->dividirPorEntero(1000, 2);

        // Con coma flotante: 1190 * 3.333 = 3966.2700000000005.
        $this->assertSame('3966.27', $resultado->aEscala(2));
    }
}