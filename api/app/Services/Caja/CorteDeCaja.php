<?php

declare(strict_types=1);

namespace App\Services\Caja;

use App\Enums\EstadoCaja;
use App\Enums\TipoMovimientoCaja;
use App\Exceptions\ErrorDeFerreteria;
use App\Models\Caja;
use App\Models\CajaMovimiento;
use App\Models\Usuario;
use App\Support\Cantidad;
use Illuminate\Support\Facades\DB;

/**
 * Apertura, movimientos y arqueo del corte de caja.
 *
 * ---------------------------------------------------------------------
 | LA DIFERENCIA, Y POR QUE SE GUARDA COMPLETA
 * ---------------------------------------------------------------------
 | Es `monto_declarado - monto_sistema`, con su signo. Positivo, sobrante.
 | Negativo, falta.
 *
 | Se guarda siempre y con todos sus decimales, aunque sea de un peso. La
 | tolerancia de `config/ferreteria.php` no la oculta ni la redondea: deja
 | escrito si el corte quedo `dentro_de_tolerancia` o `requiere_revision`, y
 | nada más. El dueño mira esta cifra todas las noches y necesita el número
 | completo, no un cero que le diga que no paso nada.
 *
 * ---------------------------------------------------------------------
 | POR QUE EL SALDO ES UNA SUMA Y NO UN CAMPO
 * ---------------------------------------------------------------------
 | El dinero entra y sale: ventas, cobros, el retiro del dueño para pagar al
 | proveedor, un cobro mal hecho que hay que corregir. Si el saldo fuera un
 | campo que se actualiza, un movimiento mal aplicado quedaria invisible. Si
 | es una suma de `caja_movimientos`, siempre se puede rehacer la cuenta y
 | ver que movimiento la cambia.
 */
class CorteDeCaja
{
    /**
     * Abre un corte en la sucursal.
     *
     * Una sucursal no puede tener dos cajas abiertas: las ventas de una se
     * sumarian al arqueo de la otra y la diferencia sería imposible de
     * repartir. El indice parcial `caja_abierta_por_sucursal` lo impide en la
     * base; esta comprobacion es para dar un mensaje claro en vez de una
     * violacion de indice.
     *
     * @throws ErrorDeFerreteria si ya hay un corte abierto en la sucursal.
     */
    public function abrir(
        Usuario $usuario,
        string $sucursalId,
        string $montoInicial = '0',
        ?string $observaciones = null,
    ): Caja {
        $abierta = Caja::query()
            ->where('sucursal_id', $sucursalId)
            ->where('estado', EstadoCaja::Abierta->value)
            ->first();

        if ($abierta !== null) {
            throw new ErrorDeFerreteria(
                'Esta sucursal ya tiene un corte abierto. Ciérrelo antes de abrir otro.',
                'caja_ya_abierta'
            );
        }

        return DB::transaction(function () use ($usuario, $sucursalId, $montoInicial, $observaciones): Caja {
            return Caja::create([
                'tenant_id' => $usuario->tenant_id,
                'sucursal_id' => $sucursalId,
                'folio' => $this->siguienteFolio($usuario->tenant_id, $sucursalId),
                'fecha_apertura_at' => now(),
                'monto_inicial' => Cantidad::desde($montoInicial)->aEscala(2),
                'estado' => EstadoCaja::Abierta->value,
                'usuario_apertura_id' => $usuario->id,
                'observaciones' => $observaciones,
            ]);
        });
    }

    /**
     * Anota un movimiento de dinero.
     *
     * El monto entra con el signo que corresponde al tipo: un retiro se
     * guarda negativo. La pantalla manda la cifra en positivo, porque nadie
     * piensa "escribame menos cinco mil"; el servicio decide el signo.
     *
     * @throws ErrorDeFerreteria si el corte ya esta cerrado.
     */
    public function mover(
        Caja $caja,
        Usuario $usuario,
        TipoMovimientoCaja $tipo,
        string $monto,
        ?string $glosa = null,
        ?string $referenciaTipo = null,
        ?string $referenciaId = null,
        ?\DateTimeInterface $ocurrioEn = null,
    ): CajaMovimiento {
        if (! $caja->estaAbierta()) {
            throw new ErrorDeFerreteria(
                'El corte ya está cerrado: no se le pueden agregar movimientos.',
                'caja_cerrada'
            );
        }

        $valor = Cantidad::desde($monto);

        if ($valor->esCero()) {
            throw new ErrorDeFerreteria(
                'Un movimiento de monto cero no cambia nada y solo ensucia el corte.',
                'monto_cero'
            );
        }

        if ($tipo->exigeGlosa() && ($glosa === null || trim($glosa) === '')) {
            throw new ErrorDeFerreteria(
                'Un movimiento de tipo «' . $tipo->value . '» necesita una glosa que explique el por qué.',
                'glosa_obligatoria'
            );
        }

        $conSigno = $tipo->suma() ? $valor : $valor->negado();

        return CajaMovimiento::create([
            'tenant_id' => $caja->tenant_id,
            'caja_id' => $caja->id,
            'tipo' => $tipo->value,
            'monto' => $conSigno->aEscala(2),
            'referencia_tipo' => $referenciaTipo,
            'referencia_id' => $referenciaId,
            'glosa' => $glosa,
            'usuario_id' => $usuario->id,
            'ocurrio_en' => $ocurrioEn ?? now(),
        ]);
    }

    /**
     * Arquea y cierra el corte.
     *
     * Guarda los TRES números: lo declarado, lo que el sistema decia y la
     * diferencia. Guardar solo la diferencia impediria saber después si el
     * error fue de conteo o de venta, que son problemas muy distintos.
     *
     * @throws ErrorDeFerreteria si el corte ya no esta abierto.
     */
    public function cerrar(Caja $caja, Usuario $usuario, string $montoDeclarado, ?string $observaciones = null): Caja
    {
        if (! $caja->estaAbierta()) {
            throw new ErrorDeFerreteria(
                'El corte ya está cerrado.',
                'caja_cerrada'
            );
        }

        return DB::transaction(function () use ($caja, $usuario, $montoDeclarado, $observaciones): Caja {
            $arqueo = $caja->arqueear($montoDeclarado);

            $caja->forceFill([
                'estado' => EstadoCaja::Cerrada->value,
                'fecha_cierre_at' => now(),
                'monto_declarado' => $arqueo['monto_declarado'],
                'monto_sistema' => $arqueo['monto_sistema'],
                'diferencia' => $arqueo['diferencia'],
                'revision' => $arqueo['revision'],
                'usuario_cierre_id' => $usuario->id,
                'observaciones' => $this->unirObservaciones($caja->observaciones, $observaciones),
            ])->save();

            return $caja->refresh();
        });
    }

    /**
     * Como va el corte ahora, sin cerrarlo.
     *
     * Es lo que muestra la pantalla mientras el turno trabaja: cuanto deberia
     * haber en caja. No escribe nada.
     */
    public function estado(Caja $caja): array
    {
        $movimientos = $caja->movimientos()->orderBy('ocurrio_en')->get();

        $porTipo = [];

        foreach ($movimientos as $movimiento) {
            $clave = $movimiento->tipo->value;

            $porTipo[$clave] = isset($porTipo[$clave])
                ? $porTipo[$clave]->suma(Cantidad::desde($movimiento->monto, 2))
                : Cantidad::desde($movimiento->monto, 2);
        }

        $resumen = [];

        foreach ($porTipo as $tipo => $total) {
            $resumen[$tipo] = $total->aEscala(2);
        }

        return [
            'id' => $caja->id,
            'folio' => $caja->folio,
            'estado' => $caja->estado->value,
            'fecha_apertura_at' => $caja->fecha_apertura_at?->toIso8601String(),
            'monto_inicial' => Cantidad::desde($caja->monto_inicial)->aEscala(2),
            'movimientos_por_tipo' => $resumen,
            'movimientos' => $movimientos->count(),
            'monto_esperado' => $caja->montoEsperado(),
            'diferencia' => $caja->diferencia,
        ];
    }

    /** Los cortes de una sucursal, del más nuevo al más viejo. */
    public function cortes(string $sucursalId, int $limite = 30)
    {
        return Caja::query()
            ->with(['usuarioApertura', 'usuarioCierre'])
            ->where('sucursal_id', $sucursalId)
            ->orderByDesc('fecha_apertura_at')
            ->limit($limite)
            ->get();
    }

    private function siguienteFolio(string $tenantId, string $sucursalId): string
    {
        $ultimo = Caja::query()
            ->where('tenant_id', $tenantId)
            ->where('sucursal_id', $sucursalId)
            ->orderByDesc('folio')
            ->value('folio');

        return (string) ($ultimo === null ? 1 : ((int) $ultimo) + 1);
    }

    private function unirObservaciones(?string $actual, ?string $nueva): ?string
    {
        if ($nueva === null || trim($nueva) === '') {
            return $actual;
        }

        if ($actual === null || trim($actual) === '') {
            return $nueva;
        }

        return trim($actual . ' | ' . $nueva);
    }
}