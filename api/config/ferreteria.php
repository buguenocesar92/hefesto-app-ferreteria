<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cantidades
    |--------------------------------------------------------------------------
    |
    | DECIMALES_CANTIDAD es la precisión con que se guardan las cantidades en
    | la base. Tres decimales es lo habitual en materiales de construccion,
    | donde media bolsa de arena existe y no es un error de tipeo.
    |
    | DECIMALES_CONVERSION es con cuantos decimales se MUESTRA el resultado
    | de convertir entre unidades. Es mayor a propósito: 1 metro son
    | 100 centímetros exactos, pero 1 libra son 0,45359237 kilogramos, y al
    | redondear a dos decimales la cuenta deja de cuadrar en el papel.
    |
    | Los dos valores se leen de `contexto` y se aplican con redondeo
    | apartado del último dígito, nunca con el método de coma flotante de
    | PHP, que acumula error. Ver App\Support\Cantidad.
    |
    */

    'cantidad' => [
        'decimales' => (int) env('FERRETERIA_DECIMALES_CANTIDAD', 3),

        'decimales_conversion' => (int) env('FERRETERIA_DECIMALES_CONVERSION', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mostrador
    |--------------------------------------------------------------------------
    |
    | Máximo de renglones que acepta una venta en mostrador. Es una protección
    | de la pantalla, no una regla del negocio: una venta con 400 renglones
    | en un mostrador es una transferencia mal digitada. El límite se avisa
    | con un código de error propio para que la interfaz diga que fue eso.
    |
    */

    'mostrador' => [
        'renglones_maximos' => (int) env('FERRETERIA_RENGOLONES_MAXIMOS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Corte de caja
    |--------------------------------------------------------------------------
    |
    | La diferencia es el número que el dueño mira todas las noches: lo que
    | se declaró menos lo que el sistema dice que hay.
    |
    | `tolerancia` NO decide si la diferencia se reporta. La diferencia
    | siempre se guarda y siempre se muestra, completa, con su signo. Lo
    | único que hace la tolerancia es marcar el corte como `dentro_de_tolerancia`
    | o `requiere_revision`, para que el detalle del corte lo diga sin que
    | nadie tenga que decidir si un peso de diferencia importa.
    |
    | Cambiar la tolerancia nunca borra ni esconde una diferencia.
    |
    */

    'caja' => [
        'tolerancia' => (int) env('FERRETERIA_TOLERANCIA_CAJA', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Venta
    |--------------------------------------------------------------------------
    |
    | Decimales con que se redondea el total ANTES de cobrarlo. El redondeo
    | se aplica una sola vez, al final, y el total redondeado queda escrito
    | en la venta: no se recalcula nunca a partir de los renglones.
    |
    | Si se redondeara por renglón, la suma de lo redondeado podria no
    | cuadrar con el total, y esa diferencia sería de nuevo un problema
    | silencioso, solo que en el papel en vez de en la base.
    |
    */

    'venta' => [
        'decimales_total' => (int) env('FERRETERIA_DECIMALES_TOTAL_VENTA', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alertas de reposición
    |--------------------------------------------------------------------------
    |
    | El aviso de "esta variante esta bajo el mínimo" se GENERA siempre y se
    | ve en pantalla. Lo que este interruptor decide es si sale a internet.
    |
    | En `false` (que es el valor por omisión) no hay transporte configurado:
    | el aviso queda en estado `pendiente` y se puede reintentar. No se
    | finge un envío que no existe.
    |
    */

    'alertas' => [
        'envio_activo' => filter_var(
            env('FERRETERIA_ALERTAS_ENVIO_ACTIVO', false),
            FILTER_VALIDATE_BOOL
        ),
    ],

];