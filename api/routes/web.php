<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\ListaPrecioController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\TransferenciaController;
use App\Http\Controllers\UnidadMedidaController;
use App\Http\Controllers\VarianteController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de la API
|--------------------------------------------------------------------------
|
| Todas viven bajo `api/v1`. La interfaz las consume por el mismo origen, a
| traves del BFF de Nuxt (ver docs/01, seccion "Arquitectura BFF").
|
| CUATRO COSAS QUE ESTAS RUTAS TIENEN QUE RESPETAR
| -------------------------------------------------
| 1. El contexto de tenant lo fija el middleware `ResolverTenant` (ver
|    bootstrap/app.php), no cada ruta.
|
| 2. Un recurso de otra ferreteria NO se encuentra: se responde 404. La RLS
|    filtra en la base y el modelo no lo encuentra, así que la respuesta es
|    la misma que si el recurso no existiera. Confirmar con un 403 que algo
|    existe en otra empresa es filtrar información.
|
| 3. Un permiso insuficiente también se responde 404, por la misma razon.
|    El filtro de autorización esta en bootstrap/app.php.
|
| 4. Las rutas de acceso y salud NO pasan por sesión: se pueden llamar sin
|    estar conectado.
|
*/

/*
|--------------------------------------------------------------------------
| Salud y acceso (sin sesión)
|--------------------------------------------------------------------------
*/

Route::get('salud', [AuthController::class, 'salud']);
Route::get('acceso/ferreterias', [AuthController::class, 'ferreterias']);
Route::get('acceso/configuracion', [AuthController::class, 'configuracion']);
Route::get('acceso/entrar/{slug}', [AuthController::class, 'entrar']);
Route::get('acceso/callback', [AuthController::class, 'callback']);

/*
|--------------------------------------------------------------------------
| Sesión
|--------------------------------------------------------------------------
*/

Route::middleware('auth:web')->group(function (): void {
    Route::get('yo', [AuthController::class, 'yo']);
    Route::post('salir', [AuthController::class, 'salir']);
});

/*
|--------------------------------------------------------------------------
| Catálogo: productos y matriz de variantes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:web')->group(function (): void {
    Route::get('productos', [ProductoController::class, 'index']);
    Route::post('productos', [ProductoController::class, 'store']);
    Route::get('productos/categorias', [ProductoController::class, 'categorias']);
    Route::get('productos/marcas', [ProductoController::class, 'marcas']);
    Route::get('productos/{producto}', [ProductoController::class, 'show']);
    Route::put('productos/{producto}', [ProductoController::class, 'update']);
    Route::post('productos/{producto}/desactivar', [ProductoController::class, 'desactivar']);
    Route::get('productos/{producto}/convertir', [ProductoController::class, 'convertir']);

    Route::get('productos/{producto}/variantes', [VarianteController::class, 'index']);
    Route::post('productos/{producto}/variantes', [VarianteController::class, 'store']);

    Route::get('variantes/barras/{codigo}', [VarianteController::class, 'porCodigoDeBarras']);
    Route::get('variantes/{variante}', [VarianteController::class, 'show']);
    Route::put('variantes/{variante}', [VarianteController::class, 'update']);
    Route::post('variantes/{variante}/desactivar', [VarianteController::class, 'desactivar']);
});

/*
|--------------------------------------------------------------------------
| Unidades de medida
|--------------------------------------------------------------------------
*/

Route::middleware('auth:web')->group(function (): void {
    Route::get('unidades', [UnidadMedidaController::class, 'index']);
    Route::post('unidades', [UnidadMedidaController::class, 'store']);
    Route::get('unidades/{unidad_medida}', [UnidadMedidaController::class, 'show']);
    Route::put('unidades/{unidad_medida}', [UnidadMedidaController::class, 'update']);
    Route::post('unidades/{unidad_medida}/desactivar', [UnidadMedidaController::class, 'desactivar']);
});

/*
|--------------------------------------------------------------------------
| Sucursales
|--------------------------------------------------------------------------
*/

Route::middleware('auth:web')->group(function (): void {
    Route::get('sucursales', [SucursalController::class, 'index']);
    Route::post('sucursales', [SucursalController::class, 'store']);
    Route::get('sucursales/{sucursal}', [SucursalController::class, 'show']);
    Route::put('sucursales/{sucursal}', [SucursalController::class, 'update']);
    Route::post('sucursales/{sucursal}/desactivar', [SucursalController::class, 'desactivar']);
});

/*
|--------------------------------------------------------------------------
| Stock por sucursal
|--------------------------------------------------------------------------
*/

Route::middleware('auth:web')->group(function (): void {
    Route::get('stock', [StockController::class, 'index']);
    Route::get('stock/resumen', [StockController::class, 'resumen']);
    Route::get('stock/bajo-minimo', [StockController::class, 'bajoMinimo']);
    Route::get('stock/alertas', [StockController::class, 'alertas']);
    Route::post('stock/variantes/{variante}/ingresar', [StockController::class, 'ingresar']);
    Route::post('stock/variantes/{variante}/ajustar', [StockController::class, 'ajustar']);
    Route::get('stock/variantes/{variante}/movimientos', [StockController::class, 'movimientos']);
});

/*
|--------------------------------------------------------------------------
| Transferencias entre sucursales
|--------------------------------------------------------------------------
*/

Route::middleware('auth:web')->group(function (): void {
    Route::get('transferencias', [TransferenciaController::class, 'index']);
    Route::post('transferencias', [TransferenciaController::class, 'store']);
    Route::get('transferencias/{transferencia}', [TransferenciaController::class, 'show']);
    Route::post('transferencias/{transferencia}/despachar', [TransferenciaController::class, 'despachar']);
    Route::post('transferencias/{transferencia}/recibir', [TransferenciaController::class, 'recibir']);
    Route::get('transferencias/{transferencia}/diferencias', [TransferenciaController::class, 'diferencias']);
    Route::post('transferencias/{transferencia}/anular', [TransferenciaController::class, 'anular']);
});

/*
|--------------------------------------------------------------------------
| Mostrador
|--------------------------------------------------------------------------
*/

Route::middleware('auth:web')->group(function (): void {
    Route::get('ventas', [VentaController::class, 'index']);
    Route::post('ventas/cotizar', [VentaController::class, 'cotizar']);
    Route::post('ventas', [VentaController::class, 'store']);
    Route::get('ventas/{venta}', [VentaController::class, 'show']);
    Route::post('ventas/{venta}/anular', [VentaController::class, 'anular']);
});

/*
|--------------------------------------------------------------------------
| Corte de caja
|--------------------------------------------------------------------------
*/

Route::middleware('auth:web')->group(function (): void {
    Route::get('cajas', [CajaController::class, 'index']);
    Route::post('cajas', [CajaController::class, 'store']);
    Route::get('cajas/cortes/{sucursalId}', [CajaController::class, 'cortes']);
    Route::get('cajas/{caja}', [CajaController::class, 'show']);
    Route::get('cajas/{caja}/movimientos', [CajaController::class, 'movimientos']);
    Route::post('cajas/{caja}/movimientos', [CajaController::class, 'mover']);
    Route::get('cajas/{caja}/arqueo', [CajaController::class, 'arqueo']);
    Route::post('cajas/{caja}/cerrar', [CajaController::class, 'cerrar']);
});

/*
|--------------------------------------------------------------------------
| Listas de precios: el margen distinto por linea
|--------------------------------------------------------------------------
*/

Route::middleware('auth:web')->group(function (): void {
    Route::get('listas-precios', [ListaPrecioController::class, 'index']);
    Route::get('listas-precios/precio-de', [ListaPrecioController::class, 'precioDe']);
    Route::get('listas-precios/{lista_precios}', [ListaPrecioController::class, 'show']);
    Route::post('listas-precios/{lista_precios}/precios', [ListaPrecioController::class, 'guardarPrecio']);
});

/*
|--------------------------------------------------------------------------
| Reportes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:web')->group(function (): void {
    Route::get('reportes/ventas-del-dia', [ReporteController::class, 'ventasDelDia']);
    Route::get('reportes/rotacion', [ReporteController::class, 'rotacion']);
    Route::get('reportes/diferencias-de-caja', [ReporteController::class, 'diferenciasDeCaja']);
    Route::get('reportes/reposicion', [ReporteController::class, 'reposicion']);
    Route::get('reportes/movimientos', [ReporteController::class, 'movimientos']);
    Route::get('reportes/sucursales', [ReporteController::class, 'sucursales']);
});