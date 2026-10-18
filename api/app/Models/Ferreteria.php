<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Empresa: la ferreteria.
 *
 * Modelo SIN tenant_id y SIN RLS, y es deliberado: se consulta en el
 * acceso, antes de que exista una empresa de sesión. Lo que se puede leer
 * antes de autenticarse es la vista `vw_ferreterias_publicas`, que expone
 * solo slug, nombre y activo. Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
class Ferreteria extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'ferreterias';

    protected $fillable = [
        'slug',
        'nombre',
        'razon_social',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'tenant_id');
    }

    public function sucursales()
    {
        return $this->hasMany(Sucursal::class, 'tenant_id');
    }

    public function productos()
    {
        return $this->hasMany(Producto::class, 'tenant_id');
    }

    /**
     * Las ferreterias que se pueden mostrar en el selector de acceso.
     *
     * Lee la VISTA, no la tabla. Es el punto donde la compensacion por no
     * tener RLS se vuelve real: si alguien cambia esta consulta por
     * `self::query()`, la pantalla de acceso empieza a devolver columnas que
     * no debian salir y `ferreteria:verificar-aislamiento` lo detecta.
     */
    public static function publicas(): array
    {
        return DB::select(
            'SELECT slug, nombre, activo FROM public.vw_ferreterias_publicas ORDER BY nombre'
        );
    }
}