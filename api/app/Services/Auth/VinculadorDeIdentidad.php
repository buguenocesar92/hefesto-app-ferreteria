<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Ferreteria;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Vincula la identidad de Authentik con la fila de `usuarios`.
 *
 * ---------------------------------------------------------------------
 | POR QUE LA API NO MANEJA CONTRASENAS
 * ---------------------------------------------------------------------
 | Authentik resuelve QUIEN es la persona. La API decide QUE PUEDE HACER: el
 | rol vive en `usuarios.rol`, que es de la ferreteria, no del proveedor de
 | identidad. Así, cambiar de proveedor de identidad no obliga a reescribir
 | permisos, y una persona puede tener roles distintos en ferreterias
 | distintas.
 *
 * ---------------------------------------------------------------------
 | EL VINCULO ES EL IDENTIFICADOR DEL SUJETO, NO EL CORREO
 * ---------------------------------------------------------------------
 | Se guarda `authentik_sub` a propósito. Si el vinculo fuera por correo,
 | cambiar el correo de una persona sería crear otra cuenta, y la anterior
 | quedaria huerfana con todos sus permisos.
 */
class VinculadorDeIdentidad
{
    /**
     * Devuelve la persona que corresponde a esta identidad de Authentik.
     *
     * @param  array<string, mixed>  $atributos  Claims del token.
     */
    public function vincular(string $subject, string $email, array $atributos): Usuario
    {
        $usuario = Usuario::query()
            ->where('authentik_sub', $subject)
            ->first();

        if ($usuario !== null) {
            $this->registrarAcceso($usuario);

            return $usuario;
        }

        // No hay vinculo por sujeto: se busca por correo dentro de la
        // ferreteria elegida, que es lo que la persona marco en la pantalla
        // de acceso.
        $slug = $this->slugDe($atributos);

        if ($slug === null) {
            throw new RuntimeException(
                'La identidad de Authentik no trae la ferreteria seleccionada. '
                . 'Sin ese dato no se puede saber a qué empresa pertenece la persona.'
            );
        }

        $ferreteria = Ferreteria::query()->where('slug', $slug)->first();

        if ($ferreteria === null) {
            throw new RuntimeException(
                'La ferreteria «' . $slug . '» no existe o esta desactivada.'
            );
        }

        // La consulta va envuelta en el contexto de la ferreteria: la RLS no
        // deja ver un correo de otra empresa, y esta consulta tiene que
        // respetar la misma regla que el resto.
        $contexto = app(\App\Services\Tenancy\ContextoTenant::class);

        $usuario = $contexto->ejecutarAislado(
            $ferreteria->id,
            static fn () => Usuario::query()->where('email', $email)->first()
        );

        if ($usuario === null) {
            throw new RuntimeException(
                'No existe ninguna persona con el correo ' . $email . ' en la ferreteria «' . $slug . '». '
                . 'Pida a un administrador que la de de alta.'
            );
        }

        if (! $usuario->activo) {
            throw new RuntimeException('La cuenta esta desactivada.');
        }

        $usuario->forceFill([
            'authentik_sub' => $subject,
            'ultimo_acceso_at' => now(),
        ])->save();

        return $usuario;
    }

    private function registrarAcceso(Usuario $usuario): void
    {
        $contexto = app(\App\Services\Tenancy\ContextoTenant::class);

        $contexto->ejecutarAislado(
            $usuario->tenant_id,
            static function () use ($usuario): void {
                $usuario->forceFill(['ultimo_acceso_at' => now()])->save();
            }
        );
    }

    /** @param  array<string, mixed>  $atributos */
    private function slugDe(array $atributos): ?string
    {
        $slug = $atributos['ferreteria'] ?? $atributos['slug'] ?? null;

        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        return trim($slug);
    }
}