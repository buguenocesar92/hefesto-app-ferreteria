<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Producto;
use App\Models\Usuario;

/**
 * Productos y sus variantes.
 *
 * Un operador puede ver el catálogo. Crear o desactivar un producto es
 * decisión de administración: desactivar mal un producto que tiene ventas
 * abiertas deja esas ventas sin surtir.
 */
class ProductoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Producto $producto): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    public function update(Usuario $usuario, Producto $producto): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    /**
     * Desactivar es más delicado que editar: saca el producto del catálogo
     * nuevo sin borrar el historico de sus variantes.
     */
    public function desactivar(Usuario $usuario, Producto $producto): bool
    {
        return $usuario->rol->puedeAdministrarCatalogo();
    }

    /** Convertir cantidades es consulta: todos pueden. */
    public function convertir(Usuario $usuario, Producto $producto): bool
    {
        return true;
    }
}