<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Ferreteria;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

/**
 * Datos minimos para poder entrar y probar.
 *
 * ---------------------------------------------------------------------
 | LO QUE HACE Y LO QUE NO HACE
 * ---------------------------------------------------------------------
 | Crea una ferreteria, dos sucursales, las unidades de medida con sus
 | conversiones, un producto con su matriz de variantes y las dos listas de
 | precios (mostrador y mayorista).
 |
 | NO carga precios reales, ni márgenes, ni plazos. Los precios de ejemplo
 | son redondos y evidentes a propósito: si alguien los ve y cree que son los
 | precios del negocio, se cargó un número inventado. Los márgenes y las
 | condiciones los define cada ferretería.
 *
 | Para entrar hay que crear la persona con el rol que corresponda y
 * vincularla al proveedor de identidad. Ver docs/04-INSTALACION.md.
 */
class FerreteriaSeeder extends Seeder
{
    public function run(): void
    {
        $ferreteria = Ferreteria::firstOrCreate(
            ['slug' => 'ferreteria-ejemplo'],
            [
                'nombre' => 'Ferretería de ejemplo',
                'razon_social' => 'Ferretería de ejemplo Ltda.',
                'activo' => true,
            ]
        );

        $this->command?->info('Ferretería: ' . $ferreteria->nombre . ' (' . $ferreteria->slug . ')');

        $this->crearUnidades($ferreteria);
        $this->crearCatalogo($ferreteria);
        $this->crearSucursales($ferreteria);
        $this->crearAdministrador($ferreteria);

        $this->command?->info('Datos de ejemplo listos.');
    }

    /**
     * Unidades de medida con su factor hacia la base.
     *
     * El pie (0,3048 metros) esta a propósito: es el factor con más decimales
     * que aparece en la vida real de una ferreteria, y sirve para comprobar
     * que la conversión no se hace con coma flotante.
     */
    private function crearUnidades(Ferreteria $ferreteria): void
    {
        $unidades = [
            ['codigo' => 'UND', 'nombre' => 'Unidad', 'factor' => 1, 'base' => true],
            ['codigo' => 'KG', 'nombre' => 'Kilogramo', 'factor' => 1, 'base' => true],
            ['codigo' => 'BOLSA40', 'nombre' => 'Bolsa de 40 kg', 'factor' => 40, 'base' => 'KG'],
            ['codigo' => 'LT', 'nombre' => 'Litro', 'factor' => 1, 'base' => true],
            ['codigo' => 'M', 'nombre' => 'Metro', 'factor' => 1, 'base' => true],
            ['codigo' => 'CM', 'nombre' => 'Centimetro', 'factor' => 0.01, 'base' => 'M'],
            ['codigo' => 'M3', 'nombre' => 'Metro cubico', 'factor' => 1, 'base' => true],
        ];

        $base = [];

        // Primero las unidades base: sin base declarada, ninguna unidad
        // derivada puede decir a qué familia pertenece.
        foreach ($unidades as $definicion) {
            if ($definicion['base'] !== true) {
                continue;
            }

            $base[$definicion['codigo']] = \App\Models\UnidadMedida::firstOrCreate(
                ['tenant_id' => $ferreteria->id, 'codigo' => $definicion['codigo']],
                [
                    'nombre' => $definicion['nombre'],
                    'unidades_por_base' => '1.000000',
                    'unidad_base_id' => null,
                    'es_base' => true,
                    'activo' => true,
                ]
            );
        }

        foreach ($unidades as $definicion) {
            if ($definicion['base'] === true) {
                continue;
            }

            \App\Models\UnidadMedida::firstOrCreate(
                ['tenant_id' => $ferreteria->id, 'codigo' => $definicion['codigo']],
                [
                    'nombre' => $definicion['nombre'],
                    'unidades_por_base' => number_format((float) $definicion['factor'], 6, '.', ''),
                    'unidad_base_id' => $base[$definicion['base']]->id,
                    'es_base' => false,
                    'activo' => true,
                ]
            );
        }

        $this->command?->info('  Unidades: ' . count($unidades) . ' con sus factores hacia la base.');
    }

    /**
     * Un producto con la matriz de variantes completa.
     *
     * El tornillo tiene cuatro filas: dos medidas, dos materiales y dos
     * presentaciones. Es la matriz minima que muestra por que el precio y el
     * stock son de la variante y no del producto.
     */
    private function crearCatalogo(Ferreteria $ferreteria): void
    {
        $unidad = \App\Models\UnidadMedida::where('tenant_id', $ferreteria->id)
            ->where('codigo', 'UND')
            ->firstOrFail();

        $categoria = \App\Models\Categoria::firstOrCreate(
            ['tenant_id' => $ferreteria->id, 'codigo' => 'HERR'],
            ['nombre' => 'Herramienta y ferreteria', 'activo' => true]
        );

        $marca = \App\Models\Marca::firstOrCreate(
            ['tenant_id' => $ferreteria->id, 'codigo' => 'GEN'],
            ['nombre' => 'Generica', 'activo' => true]
        );

        $producto = \App\Models\Producto::firstOrCreate(
            ['tenant_id' => $ferreteria->id, 'sku' => 'TORNILLO'],
            [
                'nombre' => 'Tornillo',
                'descripcion' => 'Tornillo de acero para uso general.',
                'categoria_id' => $categoria->id,
                'marca_id' => $marca->id,
                'unidad_base_id' => $unidad->id,
                // Mínimo en UNIDADES, que es la unidad base de este producto.
                'stock_minimo' => '200.000',
                'activo' => true,
            ]
        );

        $bolsa50 = \App\Models\UnidadMedida::firstOrCreate(
            ['tenant_id' => $ferreteria->id, 'codigo' => 'BOLSA50'],
            [
                'nombre' => 'Bolsa de 50 unidades',
                'unidades_por_base' => '50.000000',
                'unidad_base_id' => $unidad->id,
                'es_base' => false,
                'activo' => true,
            ]
        );

        $caja500 = \App\Models\UnidadMedida::firstOrCreate(
            ['tenant_id' => $ferreteria->id, 'codigo' => 'CAJA500'],
            [
                'nombre' => 'Caja de 500 unidades',
                'unidades_por_base' => '500.000000',
                'unidad_base_id' => $unidad->id,
                'es_base' => false,
                'activo' => true,
            ]
        );

        /*
        | Las cuatro filas de la matriz. Los precios son de ejemplo y redondos
        | a propósito: un precio de 1.900 se reconoce de un vistazo como un
        | número de ejemplo y no como el precio de la ferreteria.
        |
        | Lo que se ve aquí es que el mismo producto tiene cuatro precios
        | distintos y cuatro saldos distintos, y que ninguno de los dos
        | pertenece al producto.
        */
        $variantes = [
            ['medida' => '1/2"', 'material' => 'Acero', 'acabado' => 'Zincado', 'unidad' => $bolsa50, 'precio' => '1900.00'],
            ['medida' => '1/2"', 'material' => 'Acero', 'acabado' => 'Zincado', 'unidad' => $caja500, 'precio' => '15000.00'],
            ['medida' => '3/4"', 'material' => 'Acero', 'acabado' => 'Pasivado', 'unidad' => $bolsa50, 'precio' => '2400.00'],
            ['medida' => '3/4"', 'material' => 'Acero', 'acabado' => 'Pasivado', 'unidad' => $caja500, 'precio' => '19000.00'],
        ];

        foreach ($variantes as $indice => $fila) {
            \App\Models\Variante::firstOrCreate(
                ['tenant_id' => $ferreteria->id, 'sku' => 'TORN-' . $this->slugDe($fila)],
                [
                    'producto_id' => $producto->id,
                    'nombre' => 'Tornillo ' . $fila['medida'] . ' ' . $fila['acabado'] . ' ' . $fila['unidad']->codigo,
                    'medida' => $fila['medida'],
                    'material' => $fila['material'],
                    'acabado' => $fila['acabado'],
                    'contenido' => $fila['unidad'] === $bolsa50 ? '50.000' : '500.000',
                    'unidad_contenido_id' => $fila['unidad']->id,
                    'unidad_venta_id' => $fila['unidad']->id,
                    // El código de barras identifica UNA variante. Repetirlo
                    // en las cuatro filas haria que el escaner del mostrador
                    // encontrara siempre la misma.
                    'codigo_barras' => '7800000000' . str_pad((string) ($indice + 1), 4, '0', STR_PAD_LEFT),
                    'precio_venta' => $fila['precio'],
                    'activo' => true,
                ]
            );
        }

        $this->command?->info('  Producto de ejemplo: Tornillo con ' . count($variantes) . ' variantes.');

        $this->crearListasDePrecios($ferreteria);
    }

    /**
     * Dos listas: mostrador y mayorista. Sin márgenes inventados.
     *
     * La lista de mayorista queda CREADA Y VACÍA, y esa es la decisión
     * consciente: los márgenes son de cada ferretería y un porcentaje
     * inventado sería un número que alguien podría tomar por real. Mientras
     * la lista no tenga filas, el mostrador cobra el precio de la variante,
     * que es el comportamiento correcto y está documentado en
     * App\Services\Precios\ResolutorDePrecios.
     */
    private function crearListasDePrecios(Ferreteria $ferreteria): void
    {
        \App\Models\ListaPrecio::firstOrCreate(
            ['tenant_id' => $ferreteria->id, 'nombre' => 'Mostrador'],
            ['tipo' => 'consumidor', 'activo' => true]
        );

        \App\Models\ListaPrecio::firstOrCreate(
            ['tenant_id' => $ferreteria->id, 'nombre' => 'Mayorista'],
            ['tipo' => 'mayorista', 'activo' => true]
        );

        $this->command?->info('  Listas de precios: Mostrador y Mayorista.');
        $this->command?->warn('  La lista mayorista esta vacia: defina los precios de su mayorista.');
    }

    private function crearSucursales(Ferreteria $ferreteria): void
    {
        foreach ([['PDX', 'Providencia'], ['SAN', 'Santiago centro']] as $sucursal) {
            \App\Models\Sucursal::firstOrCreate(
                ['tenant_id' => $ferreteria->id, 'codigo' => $sucursal[0]],
                ['nombre' => $sucursal[1], 'activo' => true]
            );
        }

        $this->command?->info('  Sucursales: 2.');
    }

    /**
     * Genera la primera persona de administración.
     *
     * NO se crea con una clave: la API no maneja contrasenas. La cuenta se
     * vincula al proveedor de identidad en el primer ingreso.
     */
    private function crearAdministrador(Ferreteria $ferreteria): void
    {
        $correo = 'administracion@' . $ferreteria->slug . '.ejemplo';

        $existe = Usuario::where('tenant_id', $ferreteria->id)->where('email', $correo)->exists();

        if ($existe) {
            return;
        }

        Usuario::create([
            'tenant_id' => $ferreteria->id,
            'nombre' => 'Administración',
            'email' => $correo,
            'rol' => \App\Enums\RolOperador::Administrador->value,
            'activo' => true,
        ]);

        $this->command?->info('  Administrador: ' . $correo . ' (vincule esta cuenta en Authentik).');
    }

    private function slugDe(array $fila): string
    {
        $texto = $fila['medida'] . $fila['acabado'] . $fila['unidad']->codigo;

        return strtoupper(str_replace(['"', '/'], ['PULG', ''], $texto));
    }
}