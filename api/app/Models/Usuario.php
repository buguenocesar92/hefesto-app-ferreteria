<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolOperador;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Persona que opera la ferreteria.
 *
 * `tenant_id` es la columna que la RLS exige. Toda consulta de este modelo
 * pasa por la política `politica_aislamiento_usuarios`.
 */
class Usuario extends Authenticatable
{
    protected $table = 'usuarios';

    protected $fillable = [
        'tenant_id',
        'nombre',
        'email',
        'rol',
        'sucursal_id',
        'authentik_sub',
        'activo',
    ];

    protected $hidden = [
        'authentik_sub',
    ];

    protected function casts(): array
    {
        return [
            'rol' => RolOperador::class,
            'activo' => 'boolean',
            'ultimo_acceso_at' => 'datetime',
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function ferreteria(): BelongsTo
    {
        return $this->belongsTo(Ferreteria::class, 'tenant_id');
    }

    /**
     * Una persona sin empresa no puede operar. El middleware `ResolverTenant`
     * ya la corta antes de tocar la base, pero el método queda disponible
     * para cuando el permiso se consulta desde otro lado.
     */
    public function puedeOperar(): bool
    {
        return $this->activo
            && $this->tenant_id !== null
            && $this->rol !== null;
    }

    /** Nombre de la empresa, para la cabecera de la interfaz. */
    public function nombreEmpresa(): string
    {
        return $this->ferreteria?->nombre ?? '';
    }

    /**
     * Lo que la interfaz necesita saber de esta persona.
     *
     * Los permisos viajan como banderas y NO como una decisión de seguridad:
     * son para esconder botones que la API va a rechazar igual. La
     * autorización real la hace la API.
     */
    public function aResumen(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'email' => $this->email,
            'rol' => $this->rol->value,
            'empresa' => $this->nombreEmpresa(),
            'sucursal_id' => $this->sucursal_id,
            'puede_administrar_catalogo' => $this->rol->puedeAdministrarCatalogo(),
            'puede_cerrar_caja' => $this->rol->puedeCerrarCaja(),
            'puede_mover_entre_sucursales' => $this->rol->puedeMoverEntreSucursales(),
            'puede_anular' => $this->rol->puedeAnular(),
            'puede_administrar_personas' => $this->rol->puedeAdministrarPersonas(),
        ];
    }
}