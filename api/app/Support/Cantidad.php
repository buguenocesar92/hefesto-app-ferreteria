<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Aritmética de cantidades y montos, sin coma flotante.
 *
 * ---------------------------------------------------------------------
 * POR QUE NO SE USA `float`
 * ---------------------------------------------------------------------
 * Un `float` no puede representar 0,1 de forma exacta, y el error se acumula
 * con cada operación. En una ferreteria eso se ve en tres sitios concretos:
 *
 *   - 12,5 kg de cemento convertible a gramos da 12499,999999999998
 *   - la suma de cien renglones de $1,190 no da $119,00 sino $118,9999999
 *   - el saldo de stock se va alejando del movimiento que lo produjo y
 *     la conciliación nocturna empieza a reportar diferencias que no existen
 *
 * ---------------------------------------------------------------------
 * QUE HACE ESTA CLASE
 * ---------------------------------------------------------------------
 * Trabaja con CADENAS de dígitos y un exponente, que es como representa
 * internamente los `numeric` de PostgreSQL. Las operaciones son sumar, restar
 * y multiplicar por un entero. El redondeo es explicito y se pide por
 * separado, con la regla declarada en cada llamada.
 *
 * El resultado se serializa con `aEscala()`, que devuelve la cadena que se
 * guarda en la columna `numeric` de la base. Esa cadena es la única que
 * viaja: no se castea a `float` en ningun punto del camino.
 */
final class Cantidad
{
    /**
     * @param  string  $dígitos  Dígitos enteros con signo, sin punto decimal.
     * @param  int  $escala  Cuantos decimales representa la última cifra.
     */
    private function __construct(
        private readonly string $digitos,
        private readonly int $escala,
    ) {}

    /**
     * Construye una cantidad desde un texto.
     *
     * Acepta coma o punto como separador decimal, porque en un mostrador se
     * escriben las dos formas. Rechaza cualquier otra cosa en vez de
     * convertir en cero: un "12,5,5" convertido en 12 sería peor que un
     * error, porque el error se ve y el número falso se cobra.
     */
    public static function desde(string|int|float $valor, ?int $escala = null): self
    {
        if (is_int($valor)) {
            $cantidad = new self((string) $valor, 0);

            return $escala === null ? $cantidad : $cantidad->redondear($escala);
        }

        if (is_float($valor)) {
            // Un float ya viene con error. Se recupera su forma decimal más
            // corta, que es lo más cercano al número que alguien quiso
            // escribir, y el resto del proceso es exacto.
            $texto = rtrim(rtrim(number_format($valor, 10, '.', ''), '0'), '.');
        } else {
            $texto = trim(str_replace([' ', "\u{00A0}", "\u{2009}"], '', $valor));
        }

        if ($texto === '' || $texto === '.' || $texto === '-' || $texto === '+') {
            throw new InvalidArgumentException('La cantidad no puede quedar vacia.');
        }

        $normalizado = str_replace(',', '.', $texto);

        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d+))?$/', $normalizado, $partes)) {
            throw new InvalidArgumentException(
                'La cantidad «' . $valor . '» no es un número válido.'
            );
        }

        $entero = $partes[2] === '' ? '0' : $partes[2];
        $decimal = $partes[3] ?? '';

        if ($entero === '' && $decimal === '') {
            throw new InvalidArgumentException(
                'La cantidad «' . $valor . '» no es un número válido.'
            );
        }

        $digitos = $partes[1] . $entero . $decimal;

        // Se quitan los ceros de la izquierda conservando el signo.
        $digitos = preg_replace('/^([+-]?)0+(?=\d)/', '$1', $digitos) ?? $digitos;

        $cantidad = new self($digitos, strlen($decimal));

        return $escala === null ? $cantidad : $cantidad->redondear($escala);
    }

    public static function cero(int $escala = 0): self
    {
        return new self('0', $escala);
    }

    public static function desdeEscala(string|int|float $valor, int $escala): self
    {
        return self::desde($valor, $escala);
    }

    /** Parte entera, como cadena. */
    public function entero(): string
    {
        return $this->conEscala(0)->digitos;
    }

    public function esNegativo(): bool
    {
        return str_starts_with($this->digitos, '-') && $this->absoluto() !== '0';
    }

    public function esCero(): bool
    {
        return $this->absoluto() === '0';
    }

    public function esPositivo(): bool
    {
        return ! $this->esNegativo() && ! $this->esCero();
    }

    /** Valor absoluto, como cadena de dígitos. */
    public function absoluto(): string
    {
        $digitos = str_starts_with($this->digitos, '-')
            ? substr($this->digitos, 1)
            : $this->digitos;

        return ltrim($digitos, '0') ?: '0';
    }

    /** Compara dos cantidades. Devuelve -1, 0 o 1. */
    public function comparar(self $otra): int
    {
        $propioNegativo = $this->esNegativo();
        $otroNegativo = $otra->esNegativo();

        if ($propioNegativo !== $otroNegativo) {
            return $propioNegativo ? -1 : 1;
        }

        $magnitud = self::compararAbsolutos($this->absoluto(), $otra->absoluto());

        return $propioNegativo ? -$magnitud : $magnitud;
    }

    public function suma(self $otro): self
    {
        $escala = max($this->escala, $otro->escala);

        $resultado = $this->alineadoCon($otro, $escala);

        return (new self($resultado, $escala))->redondear($escala);
    }

    public function resta(self $otro): self
    {
        return $this->suma($otro->negado());
    }

    public function negado(): self
    {
        if ($this->esCero()) {
            return $this;
        }

        return new self(
            str_starts_with($this->digitos, '-') ? substr($this->digitos, 1) : '-' . $this->digitos,
            $this->escala
        );
    }

    /**
     * Multiplica por un entero.
     *
     * El entero se usa para reescalar (por ejemplo, multiplicar el factor
     * `unidades_por_base` por mil, que es la escala con que se guardan los
     * factores, y obtener el valor exacto). Multiplicar dos cantidades
     * fraccionarias entre si no hace falta en ningun cálculo de la
     * ferreteria, así que no esta implementado.
     */
    public function multiplicaPorEntero(int $factor): self
    {
        if ($factor === 0 || $this->esCero()) {
            return new self('0', $this->escala);
        }

        $negativo = str_starts_with($this->digitos, '-');
        $absoluto = $negativo ? substr($this->digitos, 1) : $this->digitos;

        $resultado = $absoluto;

        if ($factor < 0) {
            $negativo = ! $negativo;
            $factor = -$factor;
        }

        $resultado = self::multiplicaDigitos($resultado, $factor);

        $digitos = $negativo && $resultado !== '0' ? '-' . $resultado : $resultado;

        return (new self($digitos, $this->escala))->redondear($this->escala);
    }

    /**
     * Multiplica por una fraccion `numerador / denominador` de enteros.
     *
     * Es la operación que hace la conversión entre unidades: el factor
     * `unidades_por_base` esta guardado con seis decimales, o sea que es una
     * fraccion con denominador 1000000, y multiplicar por esa fraccion es
     * exactamente esto. El orden importa: primero se multiplica (que no
     * pierde nada) y después se divide (que puede dejar resto), nunca al
     * reves.
     *
     * @param  int  $decimales  Decimales del resultado.
     */
    public function multiplicaPorFraccion(int $numerador, int $denominador, int $decimales): self
    {
        if ($denominador === 0) {
            throw new InvalidArgumentException('El denominador de una fraccion no puede ser cero.');
        }

        $negativo = (($numerador < 0) !== ($denominador < 0));

        $numerador = abs($numerador);
        $denominador = abs($denominador);

        $resultado = $this->multiplicaPorEntero($numerador);

        if ($denominador !== 1) {
            $resultado = $resultado->dividirPorEntero($denominador, $decimales);
        } else {
            $resultado = $resultado->redondear($decimales);
        }

        return $negativo ? $resultado->negado() : $resultado;
    }

    /**
     * Division exacta por un entero, calculada con division larga sobre la
     * cadena de dígitos.
     *
     * NO se hace con `/` ni con `intdiv` sobre un entero de PHP: una
     * cantidad con seis decimales puede pesar más que el entero más grande
     * de 64 bits, y al pasarla por un `int` se pierde. Aquí cada paso
     * trabaja con un dígito y un resto que siempre cabe en un entero chico.
     *
     * El resultado se redondea a `decimales` dígitos. El resto que sobre no
     * se propaga: se descarta, y el redondeo decide si eso sube o baja.
     */
    public function dividirPorEntero(int $divisor, int $decimales): self
    {
        if ($divisor === 0) {
            throw new InvalidArgumentException('No se puede dividir por cero.');
        }

        if ($decimales < 0) {
            throw new InvalidArgumentException('La cantidad de decimales no puede ser negativa.');
        }

        if ($divisor === 1) {
            return $this->redondear($decimales);
        }

        $negativo = ($divisor < 0) !== $this->esNegativo();
        $divisor = abs($divisor);

        // Se agregan `decimales` ceros a la derecha antes de dividir, que es
        // equivalente a multiplicar por 10^decimales y conservar esos
        // decimales en el cociente.
        $numerador = $this->absoluto() . str_repeat('0', $decimales);

        [$cociente, $resto] = self::divisionLarga($numerador, $divisor);

        $digitos = $negativo && $cociente !== '0' ? '-' . $cociente : $cociente;

        // La escala real es la original más los decimales agregados. El
        // redondeo final trabaja sobre esa escala y deja el resultado con
        // los decimales pedidos.
        $resultado = new self($digitos, $this->escala + $decimales);

        return $resultado->redondear($decimales);
    }

    /**
     * Division larga de una cadena de dígitos por un entero positivo.
     *
     * @return array{0: string, 1: int}  Cociente y resto.
     */
    private static function divisionLarga(string $dividendo, int $divisor): array
    {
        $cociente = '';
        $resto = 0;

        $largo = strlen($dividendo);

        for ($i = 0; $i < $largo; $i++) {
            // El resto anterior siempre es menor que el divisor, así que
            // este número cabe en un entero normal sin desbordarse.
            $parcial = $resto * 10 + (int) $dividendo[$i];

            $cociente .= (string) intdiv($parcial, $divisor);
            $resto = $parcial % $divisor;
        }

        $cociente = ltrim($cociente, '0') ?: '0';

        return [$cociente, $resto];
    }

    /**
     * Redondeo apartando ceros al número de decimales indicado.
     *
     * 12,005 con tres decimales queda 12,005 y no 12,01: se mira la primera
     * cifra que se descarta, y si es 5 se sigue mirando hacia el resto. Es
     * el redondeo que corresponde a una cantidad fisica, donde no se quiere
     * inventar medio gramo.
     */
    public function redondear(int $decimales): self
    {
        return $this->redondearA($decimales, apartandoCeros: true);
    }

    /**
     * Redondeo comercial: si la cifra siguiente es 5 o más, sube.
     *
     * Se usa SOLO para los montos de una venta, donde el redondeo de medio
     * peso arriba es lo que espera el cliente. No se usa para cantidades
     * fisicas.
     */
    public function redondearComercial(int $decimales): self
    {
        return $this->redondearA($decimales, apartandoCeros: false);
    }

    /** Devuelve la cantidad en una escala mayor sin cambiar su valor. */
    public function aEscalaMayor(int $decimales): self
    {
        if ($decimales < $this->escala) {
            return $this->redondear($decimales);
        }

        $relleno = str_repeat('0', $decimales - $this->escala);

        return new self($this->digitos . $relleno, $decimales);
    }

    /**
     * La cadena que se guarda en la columna `numeric`.
     *
     * Siempre con punto decimal y con la cantidad de decimales pedidos, que
     * es lo que la base espera y lo que evita que un `119` y un `119,00`
     * ocupen el mismo lugar en el papel.
     */
    public function aEscala(int $decimales): string
    {
        $texto = $this->redondearA($decimales, apartandoCeros: false)->digitos;

        $signo = '';

        if (str_starts_with($texto, '-')) {
            $signo = '-';
            $texto = substr($texto, 1);
        }

        $texto = str_pad($texto, $decimales + 1, '0', STR_PAD_LEFT);

        if ($decimales === 0) {
            return $signo . $texto;
        }

        $entero = substr($texto, 0, -$decimales);
        $fraccion = substr($texto, -$decimales);

        return $signo . $entero . '.' . $fraccion;
    }

    public function __toString(): string
    {
        return $this->aEscala($this->escala);
    }

    private function conEscala(int $escala): self
    {
        return $this->redondearA($escala, apartandoCeros: false);
    }

    private function redondearA(int $decimales, bool $apartandoCeros): self
    {
        if ($decimales < 0) {
            throw new InvalidArgumentException('La cantidad de decimales no puede ser negativa.');
        }

        if ($decimales === $this->escala) {
            return $this;
        }

        if ($decimales > $this->escala) {
            // Agregar ceros de relleno no cambia el valor.
            $relleno = str_repeat('0', $decimales - $this->escala);

            return new self($this->digitos . $relleno, $decimales);
        }

        $negativo = $this->esNegativo();
        $absoluto = $this->absoluto();

        $aDescartar = $this->escala - $decimales;
        $absoluto = str_pad($absoluto, $aDescartar + 1, '0', STR_PAD_LEFT);

        $mantenida = substr($absoluto, 0, -$aDescartar);
        $descartada = substr($absoluto, -$aDescartar);

        $sube = false;

        if ($descartada !== '') {
            $primera = $descartada[0];

            if ($primera > '5') {
                $sube = true;
            } elseif ($primera === '5') {
                if ($apartandoCeros) {
                    // Sube solo si hay algo distinto de cero más adelante.
                    $resto = substr($descartada, 1);

                    $sube = trim($resto, '0') !== '';
                } else {
                    $sube = true;
                }
            }
        }

        if ($sube) {
            $mantenida = self::incrementar($mantenida);
        }

        $mantenida = ltrim($mantenida, '0') ?: '0';

        $digitos = $negativo && $mantenida !== '0' ? '-' . $mantenida : $mantenida;

        return new self($digitos, $decimales);
    }

    /** Pon las dos cantidades en la misma escala y aplica la operación. */
    private function alineadoCon(self $otra, int $escala): string
    {
        $a = $this->digitos;
        $b = $otra->digitos;

        if ($this->escala < $escala) {
            $a .= str_repeat('0', $escala - $this->escala);
        }

        if ($otro->escala < $escala) {
            $b .= str_repeat('0', $escala - $otro->escala);
        }

        $aNegativo = $this->esNegativo();
        $bNegativo = $otra->esNegativo();

        $aAbsoluto = ltrim($aNegativo ? substr($a, 1) : $a, '0') ?: '0';
        $bAbsoluto = ltrim($bNegativo ? substr($b, 1) : $b, '0') ?: '0';

        if ($aNegativo === $bNegativo) {
            $magnitud = self::sumarAbsolutos($aAbsoluto, $bAbsoluto);
            $signo = $aNegativo ? '-' : '';

            return $signo . $magnitud;
        }

        $comparacion = self::compararAbsolutos($aAbsoluto, $bAbsoluto);

        if ($comparacion === 0) {
            return '0';
        }

        if ($comparacion > 0) {
            $magnitud = self::restarAbsolutos($aAbsoluto, $bAbsoluto);

            return ($aNegativo ? '-' : '') . $magnitud;
        }

        $magnitud = self::restarAbsolutos($bAbsoluto, $aAbsoluto);

        return ($bNegativo ? '-' : '') . $magnitud;
    }

    /** Multiplica una cadena de dígitos por un entero positivo. */
    private static function multiplicaDigitos(string $digitos, int $factor): string
    {
        $reves = strrev($digitos);

        $acarreo = 0;
        $salida = '';

        for ($i = 0, $largo = strlen($reves); $i < $largo; $i++) {
            $producto = (int) $reves[$i] * $factor + $acarreo;

            $salida .= (string) ($producto % 10);
            $acarreo = intdiv($producto, 10);
        }

        while ($acarreo > 0) {
            $salida .= (string) ($acarreo % 10);
            $acarreo = intdiv($acarreo, 10);
        }

        $salida = strrev($salida);

        return ltrim($salida, '0') ?: '0';
    }

    private static function sumarAbsolutos(string $a, string $b): string
    {
        $a = strrev($a);
        $b = strrev($b);

        $acarreo = 0;
        $salida = '';

        for ($i = 0, $largo = max(strlen($a), strlen($b)); $i < $largo; $i++) {
            $suma = $acarreo + (int) ($a[$i] ?? '0') + (int) ($b[$i] ?? '0');

            $salida .= (string) ($suma % 10);
            $acarreo = intdiv($suma, 10);
        }

        if ($acarreo > 0) {
            $salida .= (string) $acarreo;
        }

        $salida = strrev($salida);

        return ltrim($salida, '0') ?: '0';
    }

    /** Resta dos cadenas de dígitos. `$a` debe ser mayor o igual que `$b`. */
    private static function restarAbsolutos(string $a, string $b): string
    {
        $a = strrev($a);
        $b = strrev($b);

        $presta = 0;
        $salida = '';

        for ($i = 0, $largo = strlen($a); $i < $largo; $i++) {
            $diferencia = (int) $a[$i] - $presta - (int) ($b[$i] ?? '0');

            if ($diferencia < 0) {
                $diferencia += 10;
                $presta = 1;
            } else {
                $presta = 0;
            }

            $salida .= (string) $diferencia;
        }

        $salida = strrev($salida);

        return ltrim($salida, '0') ?: '0';
    }

    private static function compararAbsolutos(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';

        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }

    private static function incrementar(string $digitos): string
    {
        return self::sumarAbsolutos($digitos, '1');
    }

    /** Decimales con que se guardan las cantidades, desde la configuración. */
    public static function decimalesDeConfiguracion(): int
    {
        return (int) config('ferreteria.cantidad.decimales', 3);
    }

    /** Decimales con que se muestran las conversiones, desde la configuración. */
    public static function decimalesDeConversion(): int
    {
        return (int) config('ferreteria.cantidad.decimales_conversion', 4);
    }
}