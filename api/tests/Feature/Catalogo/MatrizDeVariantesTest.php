<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\Variante;
use App\Services\Unidades\ConversorDeUnidades;
use Tests\Feature\Stock\FerreteriaStub;
use Tests\TestCase;

/**
 * La matriz de variantes y la conversión de unidades.
 *
 * Son las dos cosas que un cliente de una ferreteria va a cuestionar primero:
 * "este tornillo es el mismo que el de la caja" y "esto son cuantos kilos".
 */
class MatrizDeVariantesTest extends TestCase
{
    private FerreteriaStub $contexto;
    private ConversorDeUnidades $conversor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contexto = new FerreteriaStub();
        $this->contexto->preparar();

        $this->conversor = app(ConversorDeUnidades::class);
    }

    public function test_el_producto_no_tiene_precio_ni_stock(): void
    {
        // Es la primera pregunta que hace un cliente: "donde esta el precio
        // del tornillo". La respuesta es que no hay un precio del tornillo:
        // hay cuatro, uno por variante.
        $columnas = \Illuminate\Support\Facades\Schema::getColumnListing('productos');

        $this->assertNotContains('precio_venta', $columnas);
        $this->assertNotContains('cantidad', $columnas);

        $columnasVariante = \Illuminate\Support\Facades\Schema::getColumnListing('variantes');

        $this->assertContains('precio_venta', $columnasVariante);
    }

    public function test_cada_fila_de_la_matriz_tiene_su_propio_precio(): void
    {
        $variantes = $this->matrizDeTornillos();

        $precios = $variantes->mapWithKeys(fn (Variante $v): array => [
            $v->medida . '|' . $v->acabado . '|' . $v->contenido => $v->precio_venta,
        ]);

        // Los cuatro precios son distintos, y ninguno pertenece al producto.
        $this->assertCount(4, $precios);
        $this->assertCount(4, $precios->unique());

        $this->assertSame('1900.00', $precios['1/2"|Zincado|50.000']);
        $this->assertSame('15000.00', $precios['1/2"|Zincado|500.000']);
        $this->assertSame('2400.00', $precios['3/4"|Pasivado|50.000']);
        $this->assertSame('19000.00', $precios['3/4"|Pasivado|500.000']);
    }

    public function test_no_se_pueden_crear_dos_variantes_con_la_misma_combinacion_de_ejes(): void
    {
        $variante = $this->contexto->variante;

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->actingAs($this->contexto->usuarioBodeguero)
            ->postJson('/api/v1/productos/' . $variante->producto_id . '/variantes', [
                'producto_id' => $variante->producto_id,
                'sku' => 'TORNILLO-DUPLICADO',
                'nombre' => 'Tornillo duplicado',
                'medida' => null,
                'material' => null,
                'acabado' => null,
                'color' => null,
                'contenido' => '50',
                'unidad_contenido_id' => $variante->unidad_contenido_id,
                'unidad_venta_id' => $variante->unidad_venta_id,
                'precio_venta' => '2500.00',
            ]);
    }

    public function test_la_presentacion_se_convierte_a_unidades_de_venta(): void
    {
        $variante = $this->contexto->variante;

        // La bolsa de 50, con unidad de venta en bolsa: una presentación es
        // una unidad de venta.
        $this->assertSame('1.000', $this->conversor->presentacionEnUnidadesDeVenta($variante));
    }

    public function test_la_bolsa_de_cemento_se_convierte_en_kilos(): void
    {
        $base = UnidadMedida::factory()->base()->create(['codigo' => 'KILO', 'nombre' => 'Kilogramo']);
        $bolsa = UnidadMedida::factory()->derivada($base, '40', 'BOLSA40', 'Bolsa de 40 kg')->create();

        $producto = Producto::factory()->enUnidadBase($base, '500')->create();

        $variante = Variante::factory()
            ->conUnidades($producto, $bolsa, $bolsa, '40')
            ->create();

        $variante->refresh();

        // El producto se mide en kilos y la unidad de venta es la bolsa: una
        // bolsa de 40 son 40 unidades de venta.
        $this->assertSame(
            '40.000',
            $this->conversor->presentacionEnUnidadesDeVenta($variante)
        );

        // Y 12,5 bolsas son 500 kilos exactos.
        $kilos = $this->conversor->convertir($bolsa, $base, '12.5', $producto);

        $this->assertSame('500.000', $kilos);
    }

    public function test_dos_unidades_de_familias_distintas_no_se_pueden_convertir(): void
    {
        $kilo = UnidadMedida::factory()->base()->create(['codigo' => 'KGX', 'nombre' => 'Kilogramo']);
        $metro = UnidadMedida::factory()->base()->create(['codigo' => 'MTX', 'nombre' => 'Metro']);

        $producto = Producto::factory()->enUnidadBase($kilo)->create();

        // Devolver un número cualquiera sería inventar mercadería: no hay
        // relacion entre kilos y metros. Falla con error.
        $this->expectException(\App\Exceptions\ErrorDeFerreteria::class);

        $this->conversor->convertir($kilo, $metro, '1', $producto);
    }

    public function test_la_conversion_usa_el_factor_declarado_y_no_un_numero_magico(): void
    {
        $metro = UnidadMedida::factory()->base()->create(['codigo' => 'MTX', 'nombre' => 'Metro']);
        $pie = UnidadMedida::factory()->derivada($metro, '0.3048', 'PIE', 'Pie')->create();

        $producto = Producto::factory()->enUnidadBase($metro)->create();

        // El pie son 0,3048 metros. El único lugar donde ese número existe es
        // la columna `unidades_por_base`.
        $resultado = $this->conversor->convertir($pie, $metro, '1', $producto);

        $this->assertSame('0.305', $resultado);

        $this->assertSame('0.304800', (string) $pie->unidades_por_base);
    }

    public function test_la_descripcion_trae_las_dos_unidades_juntas(): void
    {
        $descripcion = $this->conversor->describir($this->contexto->variante, '3');

        // "3 bolsas" y al lado "150 unidades": el mostrador pide las dos
        // cifras para no tener que hacer la cuenta de cabeza.
        $this->assertSame('3.000', $descripcion['cantidad']);
        $this->assertSame('BOLSA50', $descripcion['unidad_venta_codigo']);
        $this->assertSame('UND', $descripcion['unidad_base_codigo']);
        $this->assertSame('150.000', $descripcion['cantidad_en_base']);
    }

    public function test_los_ejes_en_uso_se_declaran_segun_la_linea_de_producto(): void
    {
        $producto = $this->contexto->variante->producto;

        // Este producto no usa color: la columna tiene que salir de la ficha
        // para que el usuario noice que ahi no hay nada.
        $ejes = $producto->ejesEnUso();

        $this->assertArrayNotHasKey('color', $ejes);
    }

    public function test_no_se_puede_cambiar_la_unidad_de_venta_de_una_variante_con_movimientos(): void
    {
        $variante = $this->contexto->variante();

        app(\App\Services\Stock\RegistroDeMovimientos::class)->ingresar(
            $variante,
            $this->contexto->sucursalA->id,
            '10',
            $this->contexto->usuarioBodeguero->id,
            'Guia 1'
        );

        $this->assertTrue($variante->tieneMovimientos());

        $otroProducto = Producto::factory()->enUnidadBase($this->contexto->unidadBase)->create();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        // Cambiar la unidad de venta dejaria los saldos historicos diciendo
        // una cosa y significando otra, sin convertir ninguno de los dos.
        $this->actingAs($this->contexto->usuarioBodeguero)
            ->putJson('/api/v1/variantes/' . $variante->id, [
                'producto_id' => $otroProducto->id,
                'sku' => $variante->sku,
                'nombre' => $variante->nombre,
                'contenido' => '50',
                'unidad_contenido_id' => $variante->unidad_contenido_id,
                'unidad_venta_id' => $otroProducto->unidad_base_id,
                'precio_venta' => '1900.00',
            ]);
    }

    public function test_una_unidad_con_mas_de_seis_decimales_se_rechaza(): void
    {
        $this->actingAs($this->contexto->usuarioBodeguero)
            ->postJson('/api/v1/unidades', [
                'codigo' => 'PULGADA',
                'nombre' => 'Pulgada',
                'unidades_por_base' => '0.025400123',
                'unidad_base_id' => $this->contexto->unidadBase->id,
            ])
            ->assertJsonValidationErrors('unidades_por_base');
    }

    private function matrizDeTornillos()
    {
        return Variante::query()
            ->where('tenant_id', $this->contexto->ferreteria->id)
            ->get();
    }
}