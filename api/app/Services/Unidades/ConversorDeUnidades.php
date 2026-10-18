<?php

declare(strict_types=1);

namespace App\Services\Unidades;

use App\Exceptions\ErrorDeFerreteria;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\Variante;
use App\Support\Cantidad;

/**
 * Conversión entre unidades de medida.
 *
 * ---------------------------------------------------------------------
 * LA REGLA QUE RESUELVE ESTE MODULO
 * ---------------------------------------------------------------------
 * No hay ningun número magico. Una conversión SIEMPRE se lee de
 * `unidades_medida.unidades_por_base`: el factor que multiplica una cantidad
 * de esa unidad para obtenerla en la base a la que pertenece.
 *
 * Toda conversión pasa por la unidad base. No se va de una unidad a otra
 * directamente: el kilogramo y la bolsa de 40 kilogramos se relacionan por un
 * factor, y pasar por la base es lo que hace la cuenta auditable.
 *
 * ---------------------------------------------------------------------
 | FAMILIAS: POR QUE UNA CONVERSIÓN PUEDE FALLAR
 * ---------------------------------------------------------------------
 * Cada unidad declara su `unidad_base_id`. Dos unidades son convertibles si
 * y solo si comparten esa base.
 *
 * Un kilogramo y una bolsa de 40 kg comparten base (el kilogramo): se
 * convierten. Un kilogramo y un metro no: no hay relacion que los una, y
 * devolver un número cualquiera sería inventar mercadería. En ese caso se
 * lanza `ErrorDeFerreteria` con el código `unidades_incompatibles`.
 *
 * ---------------------------------------------------------------------
 * COMO SE APLICA EL FACTOR SIN PERDER EXACTITUD
 * ---------------------------------------------------------------------
 | `unidades_por_base` se guarda con seis decimales, o sea que es una fraccion
 | con denominador 1000000. Convertir no es multiplicar por un decimal de
 | coma flotante: es multiplicar por el numerador y dividir por el denominador,
 | con division entera sobre la cadena de dígitos. Ver
 | `Cantidad::multiplicaPorFraccion()`.
 */
class ConversorDeUnidades
{
    /**
     * Convierte una cantidad de una unidad a otra, pasando por la base.
     *
     * @throws ErrorDeFerreteria si las unidades no son convertibles.
     */
    public function convertir(
        UnidadMedida $origen,
        UnidadMedida $destino,
        string $cantidad,
        Producto $producto,
    ): string {
        $this->comprobarConvertibles($origen, $destino, $producto);

        if ($origen->id === $destino->id) {
            return $this->cuantizar($cantidad);
        }

        $enBase = $this->aBase($origen, $cantidad, $producto);

        return $this->desdeBase($destino, $enBase);
    }

    /**
     * Pasa una cantidad a la unidad base del producto.
     *
     * @throws ErrorDeFerreteria si la unidad no pertenece a esa familia.
     */
    public function aBase(UnidadMedida $unidad, string $cantidad, Producto $producto): string
    {
        $this->comprobarConvertibles($unidad, $producto->unidadBase, $producto);

        return $this->multiplicarPorFactor($unidad, $cantidad);
    }

    /** Pasa una cantidad desde la unidad base a la unidad destino. */
    public function desdeBase(UnidadMedida $unidad, string $cantidad): string
    {
        return $this->dividirPorFactor($unidad, $cantidad);
    }

    /**
     * Describe una cantidad en la unidad de venta de la variante y, al lado,
     * en la unidad base del producto.
     *
     * Es la pregunta que se hace en el mostrador: "tengo 120 kilos de
     * cemento, eso es 3 bolsas". La respuesta trae las dos cifras con su
     * unidad, para que nadie tenga que hacer la cuenta de cabeza.
     */
    public function describir(Variante $variante, string $cantidad): array
    {
        $unidadVenta = $variante->unidadVenta;
        $unidadBase = $variante->producto->unidadBase;

        return [
            'unidad_venta_id' => $unidadVenta->id,
            'unidad_venta_codigo' => $unidadVenta->codigo,
            'unidad_venta_nombre' => $unidadVenta->nombre,
            'cantidad' => $this->cuantizar($cantidad),
            'unidad_base_id' => $unidadBase->id,
            'unidad_base_codigo' => $unidadBase->codigo,
            'unidad_base_nombre' => $unidadBase->nombre,
            'cantidad_en_base' => $this->aBase($unidadVenta, $cantidad, $variante->producto),
            'presentacion' => $this->presentacionEnUnidadesDeVenta($variante),
        ];
    }

    /**
     * Cuantas unidades de venta hay en la presentación de la variante.
     *
     * Una bolsa de 40 kg con unidad de venta en kilos da 40. Una bolsa de 50
     * con unidad de venta en bolsas da 1. Responde "si tengo 3 bolsas, cuantas
     * unidades de venta son", que es lo que hace falta para cargar el stock
     * cuando llega mercadería del proveedor.
     *
     * @throws ErrorDeFerreteria si la presentación no es de la familia del producto.
     */
    public function presentacionEnUnidadesDeVenta(Variante $variante): string
    {
        if ($variante->unidad_contenido_id === $variante->unidad_venta_id) {
            return $this->cuantizar('1');
        }

        return $this->convertir(
            $variante->unidadContenido,
            $variante->unidadVenta,
            $variante->contenido,
            $variante->producto,
        );
    }

    /**
     * Factor de la unidad, devuelto como fraccion de enteros.
     *
     * `unidades_por_base` = 0,3048 se devuelve como [3048, 1000000]. Esa es
     * la única forma de que el factor entre en la aritmética sin coma
     * flotante: la escala es fija, porque la columna es `numeric(18,6)`.
     *
     * @return array{0: int, 1: int}
     */
    public function fraccionDelFactor(UnidadMedida $unidad): array
    {
        $escala = 6;

        $texto = Cantidad::desde($unidad->unidades_por_base)->aEscala($escala);

        $digitos = str_replace('.', '', $texto);
        $negativo = str_starts_with($digitos, '-');

        $absoluto = ltrim($negativo ? substr($digitos, 1) : $digitos, '0');

        $absoluto = $absoluto === '' ? '0' : $absoluto;

        $numerador = (int) $absoluto;

        return $negativo
            ? [-$numerador, 10 ** $escala]
            : [$numerador, 10 ** $escala];
    }

    /**
     * Aplica el factor de la unidad a una cantidad.
     *
     * Internamente pasa por una escala de trabajo de seis decimales, que es
     * la precisión con que estan guardados los factores. El resultado se
     * cuantiza a los decimales de configuración.
     */
    public function multiplicarPorFactor(UnidadMedida $unidad, string $cantidad): string
    {
        [$numerador, $denominador] = $this->fraccionDelFactor($unidad);

        $resultado = Cantidad::desde($cantidad)->multiplicaPorFraccion(
            $numerador,
            $denominador,
            $this->decimalesDeTrabajo()
        );

        return $resultado->aEscala(Cantidad::decimalesDeConfiguracion());
    }

    /** Divide una cantidad por el factor de la unidad. */
    public function dividirPorFactor(UnidadMedida $unidad, string $cantidad): string
    {
        [$numerador, $denominador] = $this->fraccionDelFactor($unidad);

        // Dividir por (n/d) es multiplicar por (d/n).
        $resultado = Cantidad::desde($cantidad)->multiplicaPorFraccion(
            $denominador,
            $numerador,
            $this->decimalesDeTrabajo()
        );

        return $resultado->aEscala(Cantidad::decimalesDeConfiguracion());
    }

    /** Normaliza una cantidad a los decimales con que se guarda el stock. */
    public function cuantizar(string $cantidad): string
    {
        return Cantidad::desde($cantidad)->aEscala(Cantidad::decimalesDeConfiguracion());
    }

    /** Normaliza una cantidad para mostrarla convertida. */
    public function formatear(string $cantidad): string
    {
        return Cantidad::desde($cantidad)->aEscala(Cantidad::decimalesDeConversion());
    }

    /** La unidad base declarada por el producto. */
    public function baseDe(Producto $producto): UnidadMedida
    {
        return $producto->unidadBase;
    }

    /**
     * @throws ErrorDeFerreteria si las unidades no comparten familia.
     */
    private function comprobarConvertibles(
        UnidadMedida $origen,
        UnidadMedida $destino,
        Producto $producto,
    ): void {
        $familia = $this->familiaDe($origen);

        if ($this->familiaDe($destino) !== $familia) {
            throw new ErrorDeFerreteria(
                'No se puede convertir de ' . $origen->nombre . ' a ' . $destino->nombre
                . ': no son unidades de la misma familia. El producto mide en '
                . $producto->unidadBase->nombre . '.',
                'unidades_incompatibles'
            );
        }
    }

    /**
     * Familia de una unidad: la unidad base a la que pertenece.
     *
     * La unidad base se declara ella misma, con `unidad_base_id` nulo. El
     * indice parcial `unidades_medida_unica_base` garantiza que solo haya
     * una por ferreteria.
     *
     * @throws ErrorDeFerreteria si la unidad no declara su base.
     */
    private function familiaDe(UnidadMedida $unidad): string
    {
        if ($unidad->es_base) {
            return $unidad->id;
        }

        if ($unidad->unidad_base_id === null) {
            throw new ErrorDeFerreteria(
                'La unidad «' . $unidad->nombre . '» no declara a qué unidad base '
                . 'pertenece, así que no se puede convertir.',
                'unidad_sin_base'
            );
        }

        return $unidad->unidad_base_id;
    }

    /** Decimales intermedios con que se hace la conversión. */
    private function decimalesDeTrabajo(): int
    {
        return Cantidad::decimalesDeConfiguracion() + 6;
    }
}