<?php

declare(strict_types=1);

use App\Console\Commands\FerreteriaAvisarStockBajo;
use App\Console\Commands\FerreteriaVerificarAislamiento;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| La revision de reposición corre temprano, antes de que la gente llegue.
| Si a las siete alguien ya vendio lo último que quedaba de una bolsa, el
| aviso de reposición llega con un dia de atraso; a las seis de la mañana
| todavia no paso.
|
| DOS SALVAGUARDAS CONTRA EJECUCIONES SIMULTANEAS
| ----------------------------------------------
| 1. `onOneServer()`: si por algun motivo se despliegan dos replicas de la
|    API, la tarea corre en una sola. Sin esto, cada replica contaria el
|    mismo stock y podrian duplicar avisos.
| 2. `withoutOverlapping()`: impide que una corrida lenta se pise con la
|    siguiente. El candado usa la cache (Redis).
|
| Y aun si las dos fallaran, la base sigue protegiendo: el indice único
| (variante, sucursal, periodo) no permite dos avisos del mismo dia. Las tres
| capas apuntan al mismo objetivo, y la última es la que no puede fallar.
*/

Schedule::command(FerreteriaAvisarStockBajo::class)
    ->dailyAt('06:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->name('ferreteria:revisa-reposicion');

/*
| La verificación del aislamiento corre en cada despliegue y cada hora.
| Si falla, el despliegue se detiene: es la última linea entre una
| ferreteria y los datos de otra.
|
| Esta tarea existe ADEMÁS del despliegue. Un despliegue puede pasar una vez
| y el aislamiento romperse después (un `ALTER TABLE`, una restauración mal
| hecha, un rol cambiado). Una comprobacion horaria lo detecta en una hora
| en vez de en el siguiente corte de caja.
*/
Schedule::command(FerreteriaVerificarAislamiento::class)
    ->hourly()
    ->name('ferreteria:verifica-aislamiento');