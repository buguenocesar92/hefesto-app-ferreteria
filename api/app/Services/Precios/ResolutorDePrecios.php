<?php

declare(strict_types=1);

namespace App\Services\Precios;

use App\Models\ListaPrecio;
use App\Models\ListaPrecioDetalle;
use App\Models\Variante;
use App\Support\Cantidad;

/**
 * Resolución del precio de una variante según la lista.
 *
 * ---------------------------------------------------------------------
 | LA REGLA, Y POR QUE ESTA EN UN SERVICIO Y NO EN EL CONTROLADOR
 * ---------------------------------------------------------------------
 | El precio que se cobra sale de aquí, no de la pantalla. El orden de
 | busqueda es:
 |
 |   1. la lista elegida tiene un precio para esa variante -> ese
 |   2. no lo tiene -> el precio de mostrador de la variante
 |
 | El precio de la lista gana siempre, aunque salga más caro que el de
 | mostrador. La lista es una decisión de la ferreteria y puede servir para
 | subir un precio sin tocar cada variante.
 *
 * El paso 2 es el que hace que el mostrador nunca se quede sin cobrar: una
 | variante recien cargada, sin precios cargados en ninguna lista, se vende al
 | precio que tiene.
 */
class ResolutorDePrecios
{
    /** Devuelve el precio unitario que corresponde a esta variante. */
    public function precioDe(Variante $variante, ?ListaPrecio $lista): string
    {
        if ($lista !== null) {
            $precio = $this->precioEnLaLista($lista, $variante);

            if ($precio !== null) {
                return $precio;
            }
        }

        return $this->cantizar($variante->precio_venta);
    }

    /** El precio de esta variante en esta lista, o `null` si no tiene. */
    public function precioEnLaLista(ListaPrecio $lista, Variante $variante): ?string
    {
        $fila = ListaPrecioDetalle::query()
            ->where('lista_precios_id', $lista->id)
            ->where('variante_id', $variante->id)
            ->first();

        return $fila === null ? null : $this->cantizar($fila->precio);
    }

    /**
     * De donde sale el precio.
     *
     * El mostrador necesita saberlo: si cobrar 1.900 en vez de 2.400 es por
     * la lista de mayorista, la caja tiene que poder explicarlo.
     *
     * @return array{precio: string, origen: string, lista_id: string|null, lista_nombre: string|null}
     */
    public function resolverConOrigen(Variante $variante, ?ListaPrecio $lista): array
    {
        if ($lista !== null) {
            $precio = $this->precioEnLaLista($lista, $variante);

            if ($precio !== null) {
                return [
                    'precio' => $precio,
                    'origen' => 'lista',
                    'lista_id' => $lista->id,
                    'lista_nombre' => $lista->nombre,
                ];
            }
        }

        return [
            'precio' => $this->cantizar($variante->precio_venta),
            'origen' => 'mostrador',
            'lista_id' => null,
            'lista_nombre' => null,
        ];
    }

    /**
     * Multiplica precio por cantidad, sin coma flotante.
     *
     * El precio tiene dos decimales y la cantidad tres, así que el producto
     * tiene hasta cinco. Se calcula entero por entero y se redondea UNA sola
     * vez, aquí, que es el único lugar donde el redondeo puede pasar. Si
     * redondeara por renglón y después al total, la suma de lo redondeado
     * podria no cuadrar con el total, y esa diferencia sería un problema
     * nuevo, del mismo tipo que se trato de evitar.
     */
    public function subtotal(string $precioUnitario, string $cantidad): string
    {
        $decimalesCantidad = Cantidad::decimalesDeConfiguracion();

        $precio = Cantidad::desde($precioUnitario)->aEscala(2);

        $resultado = $precio->multiplicaPorEntero(
            $this->enteroDe($cantidad, $decimalesCantidad)
        );

        return $resultado->aEscala($this->decimalesDeTotal());
    }

    /**
     * La lista de mostrador vigente para una sucursal.
     *
     * Es la que se usa si la pantalla no eligio ninguna. Se busca la de tipo
     * `consumidor` que este activa y vigente, de la sucursal o general, y se
     * ordena para que siempre devuelva la misma si hay varias.
     */
    public function listaDeMostrador(string $tenantId, ?string $sucursalId = null): ?ListaPrecio
    {
        $fecha = now()->toDateString();

        return ListaPrecio::query()
            ->where('tenant_id', $tenantId)
            ->where('tipo', 'consumidor')
            ->where('activo', true)
            ->where(function ($consulta) use ($sucursalId): void {
                $consulta->whereNull('sucursal_id');

                if ($sucursalId !== null) {
                    $consulta->orWhere('sucursal_id', $sucursalId);
                }
            })
            ->where(function ($consulta) use ($fecha): void {
                $consulta->whereNull('vigente_desde')->orWhere('vigente_desde', '<=', $fecha);
            })
            ->where(function ($consulta) use ($fecha): void {
                $consulta->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $fecha);
            })
            ->orderByRaw('sucursal_id IS NULL')  // primero la de la sucursal
            ->orderBy('nombre')
            ->first();
    }

    /**
     * Confirma que una lista se puede aplicar a una sucursal.
     *
     * Una lista atada a una sucursal no se puede usar en otra: el precio del
     * mayorista no es el mismo en todas las tiendas, y aplicarlo igual sería
     * vender al precio equivocado sin que nadie lo note.
     */
    public function esAplicable(ListaPrecio $lista, string $sucursalId): bool
    {
        if ($lista->sucursal_id === null) {
            return true;
        }

        return $lista->sucursal_id === $sucursalId;
    }

    private function cantizar(string $precio): string
    {
        return Cantidad::desde($precio)->aEscala(2);
    }

    private function decimalesDeTotal(): int
    {
        return (int) config('ferreteria.venta.decimales_total', 2);
    }

    /**
     * Pasa un número decimal a entero con sus decimales de más.
     *
     * "12,500" con tres decimales se convierte en 12500. Multiplicar ese
     * entero por un precio evita por completo la coma flotante: el producto
     * sale exacto y después se redondea una sola vez.
     */
    private function enteroDe(string $valor, int $decimales): int
    {
        $texto = Cantidad::desde($valor)->aEscala($decimales);

        $sinPunto = str_replace('.', '', $texto);

        return (int) $sinPunto;
    }
}