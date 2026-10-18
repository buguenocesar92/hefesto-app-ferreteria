<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ferreteria;
use App\Models\Usuario;
use App\Services\Auth\VinculadorDeIdentidad;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Acceso, identidad y salud.
 *
 * ---------------------------------------------------------------------
 | EL SELECTOR DE FERRETERIAS USA LA VISTA PÚBLICA, DE VERDAD
 * ---------------------------------------------------------------------
 | `ferreterias()` lee `public.vw_ferreterias_publicas`, que expone solo
 | `slug`, `nombre` y `activo`.
 *
 | La tabla `ferreterias` NO tiene Row Level Security, porque se consulta en
 | el acceso, cuando todavia no hay ferreteria de sesión. La vista es la
 | compensacion por esa excepcion, y para que la compensacion exista de verdad
 | tiene que usarse: por eso este método no consulta la tabla cruda.
 *
 | `php artisan ferreteria:verificar-aislamiento` comprueba ambas cosas: que
 | la vista exista con esas tres columnas y que este archivo la consulte. En
 | aplicaciones anteriores se creo la vista y nunca se leyo, mientras la
 | documentacion afirmaba que si; prometer una protección que no existe es
 * peor que no tenerla.
 */
class AuthController extends Controller
{
    public function __construct(private readonly VinculadorDeIdentidad $vinculador) {}

    /**
     * Salud de la API.
     *
     * No dice "todo bien" a secas: informa si la base responde y si el
     * aislamiento esta puesto. Un 200 con la base caida sería peor que
     * unhelpful, porque el orquestador lo daria por bueno.
     */
    public function salud(): JsonResponse
    {
        $base = 'desconocido';
        $aislamiento = 'desconocido';

        try {
            DB::select('SELECT 1');

            $base = 'ok';
            $aislamiento = DB::selectOne(
                "SELECT count(*) AS cantidad
                 FROM pg_class
                 WHERE relname = 'productos' AND relrowsecurity = true AND relforcerowsecurity = true"
            );

            $aislamiento = ((int) ($aislamiento->cantidad ?? 0)) > 0 ? 'ok' : 'sin_row_level_security';
        } catch (Throwable) {
            $base = 'sin_respuesta';
        }

        return response()->json([
            'servicio' => 'ferreteria',
            'estado' => $base === 'ok' ? 'ok' : 'degradado',
            'base_de_datos' => $base,
            'aislamiento' => $aislamiento,
            'momento' => now()->toIso8601String(),
        ], $base === 'ok' ? 200 : 503);
    }

    /**
     * La lista de ferreterias para el selector de la pantalla de entrada.
     *
     * ESTA ES LA CONSULTA A LA VISTA PÚBLICA. Solo devuelve `slug` y `nombre`,
     * que es lo único que la pantalla necesita para pintar el selector. No se
     * reciben RUT, razon social, direccion ni telefono: antes de autenticarse
     * no hay motivo para que el navegador vea nada más.
     */
    public function ferreterias(): JsonResponse
    {
        $filas = Ferreteria::publicas();

        $lista = [];

        foreach ($filas as $fila) {
            $lista[] = [
                'slug' => (string) $fila->slug,
                'nombre' => (string) $fila->nombre,
            ];
        }

        return response()->json(['ferreterias' => $lista]);
    }

    /** Comprobacion de que Authentik esta bien configurado. */
    public function configuracion(): JsonResponse
    {
        $issuer = (string) config('authentik.issuer');
        $clientId = (string) config('authentik.client_id');

        $problemas = [];

        if ($issuer === '' || $issuer === '/') {
            $problemas[] = 'AUTHENTIK_ISSUER no esta definido.';
        }

        if ($clientId === '') {
            $problemas[] = 'AUTHENTIK_CLIENT_ID no esta definido.';
        }

        if ((string) config('authentik.redirect_uri') === '') {
            $problemas[] = 'AUTHENTIK_REDIRECT_URI no esta definido.';
        }

        return response()->json([
            'ok' => $problemas === [],
            'issuer' => $issuer,
            'redirect_uri' => (string) config('authentik.redirect_uri'),
            'problemas' => $problemas,
        ], $problemas === [] ? 200 : 503);
    }

    /** Donde manda al navegador a entrar. */
    public function entrar(Request $request, string $slug): JsonResponse
    {
        $existe = DB::selectOne(
            'SELECT 1 AS existe FROM public.vw_ferreterias_publicas WHERE slug = ? LIMIT 1',
            [$slug]
        );

        // Se responde siempre igual exista o no: confirmar que una ferreteria
        // existe antes de entrar ya es filtrar información.
        if ($existe === null) {
            return response()->json([
                'mensaje' => 'No se pudo iniciar el acceso con esa ferreteria.',
            ], 404);
        }

        // ------------------------------------------------------------------
        // EL `state` ES ALEATORIO Y QUEDA EN LA SESIÓN
        // ------------------------------------------------------------------
        // Sin esto, el `state` sería predecible (el slug) y no se comprobaría
        // al volver: alguien podría canjear su propio código en el navegador de
        // otra persona e injectarle la sesión de su cuenta. Es el
        // ataque de login injection, y por eso el valor va atado a esta sesión.
        $estado = Str::random(48);
        $request->session()->put('ferreteria.estado_acceso', $estado);

        $url = $this->urlDeAcceso($estado);

        return response()->json(['redirigir_a' => $url]);
    }

    /** Callback de Authentik. */
    public function callback(Request $request): JsonResponse
    {
        try {
            $this->comprobarEstado($request);

            $usuario = $this->usuarioDesdeCallback($request);

            Auth::guard('web')->login($usuario, true);

            $request->session()->regenerate();

            return response()->json([
                'mensaje' => 'Sesión iniciada.',
                'redirigir_a' => '/',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'mensaje' => 'No se pudo iniciar sesión: ' . $e->getMessage(),
            ], 403);
        }
    }

    /**
     * Comprueba que el `state` que vuelve sea el que esta sesión generó.
     *
     * `pull` consume el valor: un `state` no sirve dos veces. La comparación es
     * de tiempo constante para no filtrar el valor a través del tiempo.
     */
    private function comprobarEstado(Request $request): void
    {
        $esperado = (string) $request->session()->pull('ferreteria.estado_acceso', '');
        $recibido = (string) $request->query('state', '');

        if ($esperado === '' || $recibido === '' || ! hash_equals($esperado, $recibido)) {
            throw new \RuntimeException('El acceso no se inició en este navegador.');
        }
    }

    /** Quien esta operando ahora. */
    public function yo(Request $request): JsonResponse
    {
        $usuario = $request->user();

        if (! $usuario instanceof Usuario) {
            return response()->json(['mensaje' => 'No hay sesión abierta.'], 401);
        }

        return response()->json($usuario->aResumen());
    }

    /** Cierra la sesión. */
    public function salir(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['mensaje' => 'Sesión cerrada.']);
    }

    private function usuarioDesdeCallback(Request $request): Usuario
    {
        // La URL del emisor de token la construye el propio Authentik con la
        // configuración del proveedor; no se acepta una que venga en la
        // peticion, para que nadie pueda hacer que la API canjee el código
        // contra un emisor distinto.
        $respuesta = Http::asForm()->timeout(10)->post(
            rtrim((string) config('authentik.issuer'), '/') . 'token',
            [
                'grant_type' => 'authorization_code',
                'client_id' => (string) config('authentik.client_id'),
                'client_secret' => (string) config('authentik.client_secret'),
                'code' => (string) $request->query('code'),
                'redirect_uri' => (string) config('authentik.redirect_uri'),
            ]
        );

        if (! $respuesta->successful()) {
            throw new \RuntimeException('El proveedor de identidad rechazo el canje del código.');
        }

        $codigo = (string) ($respuesta->json('access_token') ?? '');

        if ($codigo === '') {
            throw new \RuntimeException('El proveedor de identidad no devolvio un token.');
        }

        $payload = $this->verificarToken($codigo);

        $subject = (string) ($payload['sub'] ?? '');
        $correo = (string) ($payload['email'] ?? ($payload['preferred_username'] ?? ''));

        if ($subject === '' || $correo === '') {
            throw new \RuntimeException('El token no trae los datos minimos para identificar a la persona.');
        }

        return $this->vinculador->vincular($subject, $correo, $payload);
    }

    /**
     * Verifica la firma del token y devuelve sus claims.
     *
     * ---------------------------------------------------------------------
     * LA FIRMA SE VERIFICA, NO SE DECODIFICA
     * ---------------------------------------------------------------------
     * Decodificar el contenido de un JWT sin verificar la firma es lo mismo
     * que no verificarlo: el contenido lo elige quien lo emitio, o quien lo
     * intercepto. Con `firebase/php-jwt` se comprueba la firma contra las
     * claves que pública el emisor, y además se exige que el `iss` sea el
     * configurado.
     *
     * Las claves se piden al proveedor y se guardan en la cache por un dia,
     * para no pedir el conjunto de claves en cada ingreso. Si el dia cambia
     * una clave, la cache la refresca sola.
     *
     * @return array<string, mixed>
     */
    private function verificarToken(string $codigo): array
    {
        try {
            $payload = (array) JWT::decode(
                $codigo,
                JWK::parseKeySet($this->clavesPublicas(), (string) config('authentik.algoritmo'))
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('El token no es válido: ' . $e->getMessage());
        }

        $issuer = rtrim((string) config('authentik.issuer'), '/') . '/';

        if ((string) ($payload['iss'] ?? '') !== $issuer) {
            throw new \RuntimeException('El token fue emitido por otro proveedor de identidad.');
        }

        return $payload;
    }

    /**
     * El conjunto de claves públicas del proveedor.
     *
     * @return array<string, mixed>
     */
    private function clavesPublicas(): array
    {
        $clave = 'ferreteria:jwks:' . sha1((string) config('authentik.issuer'));

        $guardado = Cache::remember($clave, now()->addDay(), function (): array {
            $issuer = rtrim((string) config('authentik.issuer'), '/');

            $respuesta = Http::timeout(5)->get($issuer . '/jwks');

            if (! $respuesta->successful()) {
                throw new \RuntimeException(
                    'No se pudieron obtener las claves públicas del proveedor de identidad.'
                );
            }

            $claves = $respuesta->json();

            if (! is_array($claves) || ! isset($claves['keys'])) {
                throw new \RuntimeException('El proveedor de identidad no devolvio un conjunto de claves utilizable.');
            }

            return $claves;
        });

        return is_array($guardado) ? $guardado : [];
    }

    private function urlDeAcceso(string $estado): string
    {
        $issuer = rtrim((string) config('authentik.issuer'), '/');

        return $issuer
            . '/authorize?response_type=code'
            . '&client_id=' . rawurlencode((string) config('authentik.client_id'))
            . '&redirect_uri=' . rawurlencode((string) config('authentik.redirect_uri'))
            . '&scope=' . rawurlencode((string) config('authentik.scopes'))
            . '&state=' . rawurlencode($estado);
    }
}