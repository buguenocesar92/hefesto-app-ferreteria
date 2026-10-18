<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Ferreteria;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Variante;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Aislamiento entre ferreterias.
 *
 * ---------------------------------------------------------------------
 | QUE COMPRUEBA ESTA SUITE Y POR QUE ES LA MÁS IMPORTANTE
 * ---------------------------------------------------------------------
 | Que una ferreteria no pueda ver, ni leer, ni escribir datos de otra. Si
 | esto falla, todo lo demas da igual: el inventario, el corte de caja y los
 | precios pueden estar perfectos y de nada sirven si un local ve los saldos
 | de la competencia que esta en la misma instalación.
 *
 | Las pruebas se ejecutan SIEMPRE contra PostgreSQL, porque el aislamiento
 | esta en la base de datos. Ver `phpunit.xml`.
 */
class AislamientoDeDatosTest extends TestCase
{
    private Ferreteria $una;
    private Ferreteria $otra;
    private Sucursal $sucursalDeUna;
    private Variante $varianteDeUna;

    protected function setUp(): void
    {
        parent::setUp();

        // `ferreterias` no tiene RLS: se puede crear sin contexto.
        $this->una = Ferreteria::factory()->create();
        $this->otra = Ferreteria::factory()->create();

        $unidad = $this->comoFerreteria($this->una->id, function (): UnidadMedida {
            return UnidadMedida::factory()->base()->create();
        });

        $producto = $this->comoFerreteria($this->una->id, fn (): Producto => Producto::factory()
            ->enUnidadBase($unidad, '100')
            ->create());

        $this->sucursalDeUna = $this->comoFerreteria(
            $this->una->id,
            fn (): Sucursal => Sucursal::factory()->create()
        );

        $this->varianteDeUna = $this->comoFerreteria($this->una->id, fn (): Variante => Variante::factory()
            ->conUnidades($producto, $unidad, $unidad)
            ->create());
    }

    public function test_una_ferreteria_no_ve_los_productos_de_otra(): void
    {
        $productoDeOtra = $this->comoFerreteria($this->otra->id, function (): Producto {
            $unidad = UnidadMedida::factory()->base()->create();

            return Producto::factory()->enUnidadBase($unidad)->create();
        });

        $vistosPorUna = $this->comoFerreteria(
            $this->una->id,
            static fn () => Producto::query()->pluck('id')->all()
        );

        $this->assertContains($this->varianteDeUna->producto_id, $vistosPorUna);
        $this->assertNotContains($productoDeOtra->id, $vistosPorUna);
    }

    public function test_una_ferreteria_no_lee_una_variante_de_otra(): void
    {
        $varianteDeOtra = $this->comoFerreteria($this->otra->id, function (): Variante {
            $unidad = UnidadMedida::factory()->base()->create();
            $producto = Producto::factory()->enUnidadBase($unidad)->create();

            return Variante::factory()->conUnidades($producto, $unidad, $unidad)->create();
        });

        $encontrada = $this->comoFerreteria(
            $this->una->id,
            static fn () => Variante::find($varianteDeOtra->id)
        );

        // La RLS filtra en la base: la consulta no da error, simplemente no
        // encuentra la fila. Por eso el recurso de otra ferreteria se
        // responde como 404 y no como 403.
        $this->assertNull($encontrada);
    }

    public function test_una_ferreteria_no_puede_insertar_una_fila_de_otra(): void
    {
        $unidad = UnidadMedida::where('codigo', 'UND')->where('es_base', true)->first();

        $this->expectException(\Throwable::class);

        // Con el contexto de "una" se intenta escribir una fila que dice
        // pertenecer a "otra". El `WITH CHECK` de la política lo rechaza.
        $this->comoFerreteria($this->una->id, function () use ($unidad): void {
            Sucursal::create([
                'tenant_id' => $this->otra->id,
                'codigo' => 'XXX',
                'nombre' => 'Sucursal intrusa',
                'activo' => true,
            ]);
        });
    }

    public function test_sin_contexto_la_lectura_falla_y_no_devuelve_cero_filas(): void
    {
        $this->expectException(\Throwable::class);

        // Sin `app.tenant_id`, `public.app_tenant_id()` LANZA excepcion. Un
        // sistema que devolviera cero filas en vez de fallar sería un sistema
        // que parece vacio y no protegido.
        Producto::query()->count();
    }

    public function test_el_contexto_se_descarta_al_terminar_la_transaccion(): void
    {
        $this->comoFerreteria($this->una->id, static fn () => Producto::query()->count());

        // Al salir de la transacción, PostgreSQL descarta el valor. Una
        // lectura nueva tiene que fallar, no devolver filas de la ferreteria
        // anterior.
        $this->expectException(\Throwable::class);

        Producto::query()->count();
    }

    public function test_las_tablas_de_negocio_tienen_row_level_security_forzado(): void
    {
        $tablas = DB::select(
            "SELECT relname, relrowsecurity, relforcerowsecurity
             FROM pg_class
             WHERE relname IN ('productos', 'variantes', 'stock_sucursales',
                               'movimientos_stock', 'ventas', 'cajas',
                               'transferencias')
             ORDER BY relname"
        );

        $this->assertCount(7, $tablas, 'Deberian existir las siete tablas de negocio.');

        foreach ($tablas as $tabla) {
            $this->assertTrue((bool) $tabla->relrowsecurity, $tabla->relname . ' deberia tener RLS activa.');
            $this->assertTrue(
                (bool) $tabla->relforcerowsecurity,
                $tabla->relname . ' deberia tener FORCE ROW LEVEL SECURITY.'
            );
        }
    }

    public function test_la_politica_exige_el_tenant_al_leer_y_al_escribir(): void
    {
        $politica = DB::selectOne(
            "SELECT qual, with_check FROM pg_policies
             WHERE schemaname = 'public' AND tablename = 'variantes'"
        );

        $this->assertNotNull($politica);
        $this->assertSame('(tenant_id = app_tenant_id())', $politica->qual);
        $this->assertSame('(tenant_id = app_tenant_id())', $politica->with_check);
    }

    public function test_la_tabla_de_ferreterias_no_tiene_row_level_security(): void
    {
        $fila = DB::selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE oid = to_regclass('public.ferreterias')"
        );

        // Se consulta en el acceso, cuando todavia no hay ferreteria de
        // sesión. Con RLS activa no se podria leer nada y el selector de
        // acceso quedaria vacio.
        $this->assertFalse((bool) $fila->relrowsecurity);
    }

    public function test_la_vista_publica_expone_solo_tres_columnas(): void
    {
        $columnas = DB::select(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'vw_ferreterias_publicas'"
        );

        $nombres = array_map(static fn ($f): string => (string) $f->column_name, $columnas);
        sort($nombres);

        // Ni `razon_social`, ni `id`, ni nada más. La compensacion por no
        // tener RLS tiene que ser una compensacion de verdad.
        $this->assertSame(['activo', 'nombre', 'slug'], $nombres);
    }

    public function test_la_vista_publica_solo_muestra_ferreterias_activas(): void
    {
        Ferreteria::factory()->inactiva()->create();

        $visibles = DB::select('SELECT slug FROM public.vw_ferreterias_publicas');

        $slugs = array_map(static fn ($f): string => (string) $f->slug, $visibles);

        $this->assertContains($this->una->slug, $slugs);

        foreach ($visibles as $fila) {
            $this->assertNotNull($fila->slug);
        }

        $this->assertCount(
            count(Ferreteria::where('activo', true)->get()),
            $visibles
        );
    }

    public function test_el_seleccion_de_acceso_usa_la_vista_y_no_la_tabla_cruda(): void
    {
        $controlador = file_get_contents(
            dirname(__DIR__, 3) . '/app/Http/Controllers/AuthController.php'
        );

        $this->assertIsString($controlador);
        $this->assertStringContainsString(
            'vw_ferreterias_publicas',
            $controlador,
            'El selector del acceso debe leer la vista pública.'
        );
    }

    public function test_el_comando_de_verificacion_pasa_en_una_instalacion_sana(): void
    {
        $this->artisan('ferreteria:verificar-aislamiento')
            ->assertExitCode(0);
    }
}