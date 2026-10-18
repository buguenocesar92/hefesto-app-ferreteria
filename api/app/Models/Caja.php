<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoCaja;
use App\Support\Cantidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Corte de caja: apertura, movimientos, arqueo y diferencia.
 *
 * ---------------------------------------------------------------------
 | LA DIFERENCIA
 * ---------------------------------------------------------------------
 * `diferencia` es `monto_declarado - monto_sistema`, con su signo. Se guarda
 | SIEMPRE y COMPLETA, aunque sea de un peso.
 |
 | La tolerancia de `config/ferreteria.php` NO la oculta ni la redondea: lo
 | único que hace es dejar escrito si el corte quedo dentro o fuera de ella.
 | El dueño mira esta cifra todas las noches y necesita el número entero, no
 | un "0" que le diga que no paso nada.
 */
class Caja extends Model
{
    protected $table = 'cajas';

    protected $fillable = [
        'tenant_id',
        'sucursal_id',
        'folio',
        'fecha_apertura_at',
        'monto_inicial',
        'fecha_cierre_at',
        'monto_declarado',
        'monto_sistema',
        'diferencia',
        'revision',
        'estado',
        'usuario_apertura_id',
        'usuario_cierre_id',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCaja::class,
            'monto_inicial' => 'string',
            'monto_declarado' => 'string',
            'monto_sistema' => 'string',
            'diferencia' => 'string',
            'fecha_apertura_at' => 'datetime',
            'fecha_cierre_at' => 'datetime',
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(CajaMovimiento::class, 'caja_id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'caja_id');
    }

    public function usuarioApertura(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_apertura_id');
    }

    public function usuarioCierre(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_cierre_id');
    }

    public function estaAbierta(): bool
    {
        return $this->estado === EstadoCaja::Abierta;
    }

    /**
     * Cuanto dinero deberia haber en la caja ahora.
     *
     * Es el monto inicial más la suma con signo de los movimientos. Es una
     * suma de montos, no de ventas: por eso un retiro del dueño baja el
     * total y la caja sigue cuadrando al cierre.
     */
    public function montoEsperado(): string
    {
        $total = Cantidad::desde($this->monto_inicial, 2);

        $movimientos = $this->relationLoaded('movimientos')
            ? $this->movimientos
            : $this->movimientos()->get();

        foreach ($movimientos as $movimiento) {
            $total = $total->suma(Cantidad::desde($movimiento->monto, 2));
        }

        return $total->aEscala(2);
    }

    /**
     * El arqueo.
     *
     * Devuelve los tres números y la diferencia con su signo. No redondea la
     * diferencia a cero: la tolerance decide la etiqueta `revision`, nunca el
     * número.
     */
    public function arquear(string $montoDeclarado): array
    {
        $sistema = $this->montoEsperado();

        $diferencia = Cantidad::desde($montoDeclarado, 2)->resta(Cantidad::desde($sistema, 2));

        $tolerancia = Cantidad::desde((string) config('ferreteria.caja.tolerancia', 0), 2);

        // La tolerancia se compara en valor absoluto: que falten cinco pesos
        // y que sobren cinco son el mismo tipo de problema para el dueño.
        $magnitud = Cantidad::desde($diferencia->absoluto(), 2);
        $limite = Cantidad::desde($tolerancia->absoluto(), 2);

        $dentroDeTolerancia = $magnitud->comparar($limite) <= 0;

        return [
            'monto_declarado' => Cantidad::desde($montoDeclarado, 2)->aEscala(2),
            'monto_sistema' => $sistema,
            'diferencia' => $diferencia->aEscala(2),
            'diferencia_sobrante' => $diferencia->esPositivo(),
            'diferencia_faltante' => $diferencia->esNegativo(),
            'tolerancia' => $tolerancia->aEscala(2),
            'revision' => $dentroDeTolerancia ? 'dentro_de_tolerancia' : 'requiere_revision',
        ];
    }
}