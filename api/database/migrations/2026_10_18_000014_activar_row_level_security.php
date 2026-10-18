<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AISLAMIENTO ENTRE FERRETERIAS: Row Level Security (RLS) de PostgreSQL.
 *
 * Patron tomado de la decisión ya escrita y probada en
 * `apps/portal-autoservicio/docs/03-MULTITENANCY.md` y aplicada igual en
 * `apps/almacen`. Se reutiliza tal cual, sin inventar un cuarto enfoque.
 *
 * MECANISMO
 * ---------
 * 1. `public.app_tenant_id()` lee el valor de la variable de sesión
 *    `app.tenant_id`. Si no está definida, LANZA un error: no devuelve
 *    NULL ni cadena vacia. Falla de forma ruidosa, para que en el log
 *    quede claro que una operación se escapo del middleware.
 * 2. Cada tabla de negocio tiene una política que exige
 *    `tenant_id = app_tenant_id()`, al leer (USING) y al escribir
 *    (WITH CHECK).
 * 3. `FORCE ROW LEVEL SECURITY` hace que la política aplique también al
 *    dueno de la tabla. Sin FORCE, el dueno la saltaria: y en desarrollo
 *    el rol de la aplicación suele ser el dueno, o sea que sin FORCE el
 *    aislamiento sería decorativo justo donde se prueba.
 * 4. La variable se fija con `set_config('app.tenant_id', ..., true)`, o
 *    sea con alcance de TRANSACCIÓN. Al confirmar o revertir,
 *    PostgreSQL la descarta sola, de modo que el worker de colas, que
 *    reutiliza la misma conexión, no puede arrastrar el tenant de otra
 *    ferreteria.
 *
 * LA TABLA `ferreterias` NO TIENE RLS, a propósito: se consulta en el acceso,
 * cuando todavia no hay empresa de sesión. Se compensa con la vista
 * `vw_ferreterias_publicas`, que expone solo tres columnas y solo ferreterias
 * activas.
 *
 * ESA VISTA SE USA DE VERDAD
 * --------------------------
 * La lee `AuthController::ferreterias()` para el selector de la pantalla de
 * entrada, y no la tabla cruda. Esta nota existe porque en aplicaciones
 * anteriores se creo la vista, se documento como compensacion y nunca se
 * consultó: una protección que no se usa no es una protección.
 * `php artisan ferreteria:verificar-aislamiento` comprueba que las columnas
 * de la vista sean exactamente tres y que la consulta del acceso la use.
 *
 * REQUISITO DE DESPLIEGUE: el rol que usa la aplicación NO puede ser
 * superusuario ni tener BYPASSRLS. Con superusuario, PostgreSQL ignora las
 * políticas por completo.
 */
return new class extends Migration
{
    /**
     * Tablas con datos de negocio, y por lo tanto con RLS.
     *
     * `ferreterias` NO esta en la lista, a propósito (ver cabecera del archivo).
     *
     * @var list<string>
     */
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

    public function up(): void
    {
        $this->verificarColumnaTenant();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                valor := current_setting('app.tenant_id', true);

                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'insufficient_privilege',
                        MESSAGE = 'No hay contexto de ferreteria en la conexion (app.tenant_id no esta definida). Se aborta la operacion para no exponer datos de otra ferreteria.',
                        HINT = 'La peticion no paso por App\Http\Middleware\ResolverTenant, o la operacion se ejecuto fuera de una transaccion.';
                END IF;

                RETURN valor::uuid;
            END
            $$;

            COMMENT ON FUNCTION public.app_tenant_id() IS
                'Devuelve la ferreteria de la peticion. Lanza excepcion si no hay contexto: el aislamiento falla cerrado.';
        SQL);

        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne('SELECT to_regclass(?) AS tabla', ['public.' . $tabla]);

            // to_regclass devuelve NULL si la tabla todavia no existe.
            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('ALTER TABLE public.' . $tabla . ' ENABLE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE public.' . $tabla . ' FORCE ROW LEVEL SECURITY');

            $nombre = 'politica_aislamiento_' . $tabla;

            DB::unprepared(<<<SQL
                DROP POLICY IF EXISTS {$nombre} ON public.{$tabla};

                CREATE POLICY {$nombre} ON public.{$tabla}
                    FOR ALL
                    TO PUBLIC
                    USING (tenant_id = public.app_tenant_id())
                    WITH CHECK (tenant_id = public.app_tenant_id());
            SQL);
        }

        $this->crearVistaPublica();
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.vw_ferreterias_publicas');

        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne('SELECT to_regclass(?) AS tabla', ['public.' . $tabla]);

            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('DROP POLICY IF EXISTS politica_aislamiento_' . $tabla . ' ON public.' . $tabla);
            DB::unprepared('ALTER TABLE public.' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE public.' . $tabla . ' DISABLE ROW LEVEL SECURITY');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS public.app_tenant_id()');
    }

    /**
     * Compensacion de la tabla sin RLS.
     *
     * Tres columnas y solo ferreterias activas. La tabla `ferreterias` tiene
     * además `razon_social`, y esa columna no sale de aquí: antes del acceso
     * no hay motivo para que el navegador vea datos de contacto.
     */
    private function crearVistaPublica(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW public.vw_ferreterias_publicas AS
            SELECT slug, nombre, activo
            FROM public.ferreterias
            WHERE activo = true;

            COMMENT ON VIEW public.vw_ferreterias_publicas IS
                'Lo unico que se puede leer de una ferreteria antes de autenticarse: tres columnas y solo las activas. La usa AuthController::ferreterias() para el selector del acceso.';
        SQL);
    }

    /**
     * Falla ruidosamente si alguna tabla de la lista no tiene
     * `tenant_id`. Sin esto, un error de tipeo dejaria una tabla de datos
     * de negocio sin política y la migración pasaria igual.
     */
    private function verificarColumnaTenant(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne(
                'SELECT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = ''public'' AND table_name = ? AND column_name = ''tenant_id''
                 ) AS tiene_columna',
                [$tabla]
            );

            if ($existe === null || ! $existe->tiene_columna) {
                throw new RuntimeException(
                    'La tabla public.' . $tabla . ' no tiene columna tenant_id y no puede protegerse con RLS. '
                    . 'Revise la migración antes de continuar.'
                );
            }
        }
    }
};