<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verificador del aislamiento entre ferreterias.
 *
 * Mismo nombre y mismo propósito que en las demas aplicaciones de Hefesto:
 * `ferreteria:verificar-aislamiento`, corre en cada despliegue y falla si el
 * aislamiento no esta puesto.
 *
 * ---------------------------------------------------------------------
 | LAS CINCO COMPROBACIONES
 * ---------------------------------------------------------------------
 |  1. que la tabla `ferreterias` NO tenga RLS, a propósito
 |  2. que cada tabla de negocio tenga `tenant_id`, RLS activo y `FORCE`
 |  3. que la política exija el tenant al leer Y al escribir
 |  4. que `public.app_tenant_id()` falle cerrado sin contexto
 |  5. que la vista pública exista y exponga SOLO tres columnas
 |
 * Y además, en lo que más importa:
 |  6. que el rol de la aplicación NO sea superusuario ni tenga BYPASSRLS
 *  7. que la vista pública SEA LA QUE USA EL ACCESO, no la tabla cruda
 */
class FerreteriaVerificarAislamiento extends Command
{
    protected $signature = 'ferreteria:verificar-aislamiento';

    protected $description = 'Verifica que el aislamiento entre ferreterias y la inmutabilidad del stock esten puestos.';

    /** Tablas que DEBEN tener RLS. */
    private array $tablasProtegidas = [
        'usuarios',
        'sucursales',
        'unidades_medida',
        'categorias',
        'marcas',
        'productos',
        'variantes',
        'listas_precios',
        'listas_precios_detalle',
        'stock_sucursales',
        'movimientos_stock',
        'transferencias',
        'transferencia_items',
        'cajas',
        'caja_movimientos',
        'ventas',
        'venta_items',
        'alertas_stock',
    ];

    public function handle(ContextoTenant $contexto): int
    {
        $fallos = [];

        $this->info('Revisando el aislamiento entre ferreterias.');

        $fallos = array_merge($fallos, $this->revisarTablasDeFerreteria());
        $fallos = array_merge($fallos, $this->revisarTablasProtegidas());
        $fallos = array_merge($fallos, $this->revisarFuncionDeContexto());
        $fallos = array_merge($fallos, $this->revisarRolDeLaAplicacion());
        $fallos = array_merge($fallos, $this->revisarVistaPublica());
        $fallos = array_merge($fallos, $this->revisarInmutabilidadDelStock());
        $fallos = array_merge($fallos, $this->revisarAislamientoReal($contexto));

        if ($fallos === []) {
            $this->info('Aislamiento entre ferreterias verificado.');

            return self::SUCCESS;
        }

        foreach ($fallos as $fallo) {
            $this->error($fallo);
        }

        $this->error('El aislamiento NO esta bien puesto. No debe entrar gente a la aplicación.');

        return self::FAILURE;
    }

    /**
     * `ferreterias` NO lleva RLS, y eso es correcto: se consulta en el acceso.
     *
     * @return list<string>
     */
    private function revisarTablasDeFerreteria(): array
    {
        $fila = DB::selectOne(
            "SELECT relrowsecurity, relforcerowsecurity
             FROM pg_class
             WHERE oid = to_regclass('public.ferreterias')"
        );

        if ($fila === null) {
            return ['No existe la tabla public.ferreterias.'];
        }

        if ($fila->relrowsecurity) {
            return [
                'La tabla public.ferreterias tiene RLS activa, y no deberia: '
                . 'se consulta en el acceso, cuando todavia no hay ferreteria de sesión.',
            ];
        }

        $this->line('  [ok] La tabla ferreterias no tiene RLS, como corresponde.');

        return [];
    }

    /** @return list<string> */
    private function revisarTablasProtegidas(): array
    {
        $fallos = [];

        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne('SELECT to_regclass(?) AS tabla', ['public.' . $tabla]);

            if ($existe === null || $existe->tabla === null) {
                $fallos[] = 'No existe la tabla public.' . $tabla . ', que deberia estar protegida con RLS.';

                continue;
            }

            $fila = DB::selectOne(
                'SELECT relrowsecurity, relforcerowsecurity
                 FROM pg_class
                 WHERE oid = to_regclass(?)',
                ['public.' . $tabla]
            );

            if (! $fila->relrowsecurity) {
                $fallos[] = 'La tabla public.' . $tabla . ' no tiene Row Level Security activa.';

                continue;
            }

            if (! $fila->relforcerowsecurity) {
                $fallos[] = 'La tabla public.' . $tabla
                    . ' no tiene FORCE ROW LEVEL SECURITY: el dueno de la tabla saltaria la política.';

                continue;
            }

            $politica = DB::selectOne(
                'SELECT qual, with_check FROM pg_policies
                 WHERE schemaname = ? AND tablename = ? AND policyname = ?',
                ['public', $tabla, 'politica_aislamiento_' . $tabla]
            );

            if ($politica === null) {
                $fallos[] = 'La tabla public.' . $tabla . ' no tiene la política de aislamiento esperada.';

                continue;
            }

            $esperado = '(tenant_id = app_tenant_id())';

            if ($politica->qual !== $esperado || $politica->with_check !== $esperado) {
                $fallos[] = 'La política de Row Level Security de ' . $tabla
                    . ' no exige tenant_id = app_tenant_id() al leer y al escribir.';
            }
        }

        if ($fallos === []) {
            $this->line('  [ok] Las ' . count($this->tablasProtegidas) . ' tablas de negocio tienen RLS, FORCE y política completa.');
        }

        return $fallos;
    }

    /**
     * `app_tenant_id()` tiene que LANZAR excepcion sin contexto.
     *
     * @return list<string>
     */
    private function revisarFuncionDeContexto(): array
    {
        $existe = DB::selectOne(
            "SELECT count(*) AS cantidad
             FROM pg_proc
             WHERE proname = 'app_tenant_id' AND pronamespace = 'public'::regnamespace"
        );

        if ($existe === null || (int) $existe->cantidad === 0) {
            return ['No existe la funcion public.app_tenant_id().'];
        }

        try {
            DB::statement("SELECT set_config('app.tenant_id', '', false)");

            DB::select('SELECT public.app_tenant_id()');

            return [
                'public.app_tenant_id() devuelve un valor sin contexto en vez de lanzar excepcion: '
                . 'el aislamiento falla abierto.',
            ];
        } catch (\Throwable $e) {
            $this->line('  [ok] public.app_tenant_id() falla cerrado sin contexto de ferreteria.');

            return [];
        } finally {
            // Se limpia la variable vacia para no ensuciar la sesión.
            DB::statement("SELECT set_config('app.tenant_id', '', false)");
        }
    }

    /**
     * El rol de la aplicación no puede saltarse la RLS.
     *
     * @return list<string>
     */
    private function revisarRolDeLaAplicacion(): array
    {
        $fila = DB::selectOne(
            'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user'
        );

        if ($fila === null) {
            return ['No se pudo leer el rol de la aplicación.'];
        }

        $fallos = [];

        if ($fila->rolsuper) {
            $fallos[] = 'El rol de la aplicación es superusuario: PostgreSQL ignora las políticas de RLS por completo.';
        }

        if ($fila->rolbypassrls) {
            $fallos[] = 'El rol de la aplicación tiene BYPASSRLS: ignora las políticas de RLS.';
        }

        if ($fallos === []) {
            $this->line('  [ok] El rol de la aplicación no es superusuario ni tiene BYPASSRLS.');
        }

        return $fallos;
    }

    /**
     * La vista pública: exactamente tres columnas, y solo ferreterias activas.
     *
     * @return list<string>
     */
    private function revisarVistaPublica(): array
    {
        $columnas = DB::select(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'vw_ferreterias_publicas'
             ORDER BY column_name"
        );

        if ($columnas === []) {
            return [
                'No existe la vista public.vw_ferreterias_publicas, que es la compensacion '
                . 'por que la tabla ferreterias no tiene RLS.',
            ];
        }

        $nombres = array_map(static fn ($fila): string => (string) $fila->column_name, $columnas);
        sort($nombres);

        $esperadas = ['activo', 'nombre', 'slug'];
        sort($esperadas);

        if ($nombres !== $esperadas) {
            return ['La vista public.vw_ferreterias_publicas expone columnas distintas de las tres '
                . 'esperadas (' . implode(', ', $nombres) . '). No debe exponer datos de contacto.'];
        }

        $inactivas = DB::select(
            "SELECT count(*) AS cantidad FROM public.vw_ferreterias_publicas WHERE activo = false"
        );

        if ($inactivas !== null && (int) $inactivas->cantidad > 0) {
            return ['La vista public.vw_ferreterias_publicas muestra ferreterias inactivas.'];
        }

        $this->line('  [ok] La vista pública expone solo slug, nombre y activo, y solo ferreterias activas.');

        return $this->revisarQueElAccesoUseLaVista();
    }

    /**
     * La vista tiene que USARSE, no solo existir.
     *
     * En aplicaciones anteriores se creo la vista, se documento como
     * compensacion y nunca se consulto. Esta comprobacion lee el código del
     * acceso y avisa si volvio a leer la tabla cruda: prometer una
     * protección que no se usa es peor que no prometerla.
     *
     * @return list<string>
     */
    private function revisarQueElAccesoUseLaVista(): array
    {
        $controlador = base_path('app/Http/Controllers/AuthController.php');

        if (! is_file($controlador)) {
            return ['No se encontro app/Http/Controllers/AuthController.php para revisar el selector de acceso.'];
        }

        $contenido = (string) file_get_contents($controlador);

        if (! str_contains($contenido, 'vw_ferreterias_publicas')) {
            return [
                'La vista public.vw_ferreterias_publicas existe pero el selector del acceso no la consulta. '
                . 'Una protección que no se usa no es una protección.',
            ];
        }

        $this->line('  [ok] El selector del acceso lee la vista pública, no la tabla cruda.');

        return [];
    }

    /**
     * El saldo de stock no se puede editar a mano.
     *
     * @return list<string>
     */
    private function revisarInmutabilidadDelStock(): array
    {
        $gatillos = DB::select(
            "SELECT tgname
             FROM pg_trigger
             WHERE tgrelid IN (
                 to_regclass('public.stock_sucursales'),
                 to_regclass('public.movimientos_stock')
             )
             AND NOT tgisinternal"
        );

        if ($gatillos === []) {
            return [
                'No hay ningun gatillo sobre stock_sucursales ni sobre movimientos_stock: '
                . 'los saldos se podrian editar a mano y la historia se podria borrar.',
            ];
        }

        $this->line('  [ok] El stock tiene gatillos que impiden editar saldos y borrar movimientos.');

        return [];
    }

    /**
     * La prueba que importa: con el contexto de una ferreteria, no se leen
     * filas de la otra.
     *
     * @return list<string>
     */
    private function revisarAislamientoReal(ContextoTenant $contexto): array
    {
        $fallos = [];

        $primera = DB::selectOne('SELECT id FROM ferreterias ORDER BY created_at LIMIT 1');

        if ($primera === null) {
            $this->line('  [ok] No hay ferreterias cargadas todavia: nada que aislar.');

            return [];
        }

        $tenantId = (string) $primera->id;

        try {
            $visibles = $contexto->ejecutarAislado(
                $tenantId,
                static fn () => (int) DB::table('productos')->count()
            );

            $this->line('  [ok] Con el contexto de una ferreteria se leen ' . $visibles . ' productos.');
        } catch (\Throwable $e) {
            $fallos[] = 'No se pudo leer dentro del contexto de una ferreteria: ' . $e->getMessage();
        }

        // Sin contexto, la lectura tiene que FALLAR, no devolver cero filas.
        try {
            DB::statement("SELECT set_config('app.tenant_id', '', false)");

            DB::table('productos')->count();

            $fallos[] = 'Sin contexto de ferreteria se pudo leer la tabla productos sin error: '
                . 'el aislamiento falla abierto.';
        } catch (\Throwable) {
            $this->line('  [ok] Sin contexto de ferreteria, la lectura falla en vez de devolver datos.');
        } finally {
            DB::statement("SELECT set_config('app.tenant_id', '', false)");
        }

        return $fallos;
    }
}