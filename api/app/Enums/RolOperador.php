<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol de la persona dentro de la ferreteria.
 *
 * EL ROL VIVE EN LA BASE, NO EN AuthentIK
 * --------------------------------------
 * Authentik responde "quien eres". Este rol responde "que puedes hacer aca".
 * Son cosas distintas: la misma persona puede ser administradora en una
 * ferreteria y vendedora en otra, y separarlas permite que el proveedor de
 * identidad se cambie sin reescribir permisos.
 *
 * El orden del enum va de más a menos permisos y es el que usa la pantalla
 * de ayuda al dar de alta a alguien.
 */
enum RolOperador: string
{
    case Administrador = 'administrador';
    case Gerente = 'gerente';
    case Vendedor = 'vendedor';
    case Bodeguero = 'bodeguero';

    /** Puede entrar al mostrador y cobrar. */
    public function puedeVender(): bool
    {
        return true;
    }

    /** Puede dar de alta productos, variantes, precios y sucursales. */
    public function puedeAdministrarCatalogo(): bool
    {
        return match ($this) {
            self::Administrador, self::Gerente => true,
            self::Vendedor, self::Bodeguero => false,
        };
    }

    /** Puede abrir y cerrar caja, y ver la diferencia de los turnos. */
    public function puedeCerrarCaja(): bool
    {
        return match ($this) {
            self::Administrador, self::Gerente => true,
            self::Vendedor, self::Bodeguero => false,
        };
    }

    /** Puede despachar y recibir transferencias entre sucursales. */
    public function puedeMoverEntreSucursales(): bool
    {
        return match ($this) {
            self::Administrador, self::Gerente, self::Bodeguero => true,
            self::Vendedor => false,
        };
    }

    /** Puede anular ventas, cajas y transferencias. Anular deja rastro. */
    public function puedeAnular(): bool
    {
        return match ($this) {
            self::Administrador, self::Gerente => true,
            self::Vendedor, self::Bodeguero => false,
        };
    }

    /** Puede dar de alta y desactivar personas. */
    public function puedeAdministrarPersonas(): bool
    {
        return $this === self::Administrador;
    }

    /** Descripcion para la ayuda de la pantalla de acceso. */
    public function descripcion(): string
    {
        return match ($this) {
            self::Administrador => 'Control total: catalogo, precios, personas, caja y reportes.',
            self::Gerente => 'Catálogo, precios, caja, transferencias y reportes de la ferreteria.',
            self::Vendedor => 'Mostrador: consulta el catalogo, cobra y registra ventas.',
            self::Bodeguero => 'Bodega: ingresos de mercaderia, transferencias y ajuste de stock.',
        };
    }
}