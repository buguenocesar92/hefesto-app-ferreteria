# 01. Arquitectura

> PostgreSQL corre con dos roles: uno de arranque y otro de aplicación. Solo el
> de aplicación conecta con el sistema, y es el que las políticas de
> aislamiento pueden alcanzar. Ver [03](03-MULTITENANCY-Y-ROLES.md).

Qué piezas tiene el sistema, cómo se hablan entre ellas y por qué están
divididas así.

---

## Las cuatro piezas

```
Navegador
   |
   |  mismo origen, cookie de sesion, token CSRF
   v
Caddy  (el unico puerto abierto)
   |
   +-- /api/**  --->  API Laravel  --->  PostgreSQL
   |                                     |
   |                                 Redis (cache y colas)
   |
   +-- resto    --->  Interfaz Nuxt (BFF)  --->  API Laravel
                                     |
                                 Authentik
```

| Pieza | Qué es | Por qué existe |
|---|---|---|
| Caddy | Proxy inverso | Único punto de entrada. Reparte entre API e interfaz y termina TLS |
| Interfaz Nuxt | Backend for frontend | Sirve las pantallas y reenvía las llamadas a la API. Nunca se habla directo con la base |
| API Laravel | Autoridad de los datos | Reglas de negocio, precios, stock, caja y aislamiento entre ferreterías |
| PostgreSQL | Autoridad final | Guarda los datos y, sobre todo, **hace cumplir el aislamiento** |
| Redis | Caché y colas | Sessiones, candados de tareas programadas y cola de avisos |
| Authentik | Identidad | Resuelve quién es la persona. La API decide qué puede hacer |

---

## Arquitectura BFF: por qué el navegador no habla con la API

La interfaz es un «backend for frontend»: recibe las peticiones del navegador
y las reenvía a la API desde el servidor. El navegador nunca ve la URL
interna de la API.

El motivo es concreto:

La sesión viaja en una cookie **HttpOnly**. El navegador no la puede leer,
pero sí la puede reenviar. Si la API respondiera en **otro origen**, habría
que resolver CORS con credenciales, que obliga a exponer cabeceras
permitidas y duplica el riesgo de falsificación de peticiones. Al meter todo
en un solo origen, la cookie viaja sola y el token CSRF se resuelve con la
cabecera `X-XSRF-TOKEN`.

En una ferreteria esto no es purismo. **Cobrar una venta, cerrar una caja y
despachar una transferencia escriben en el stock y en el dinero.** Que esas
acciones no puedan falsificarse con una petición suelta desde otro sitio es
lo que las hace confiables.

El reenvío está en `web/server/api/[...ruta].ts` y hace cuatro cosas:

1. Copia las cookies del navegador hacia la API.
2. Copia la cookie `XSRF-TOKEN` como cabecera `X-XSRF-TOKEN`.
3. Copia de vuelta las cookies que la API haya puesto.
4. Convierte la respuesta del callback de Authentik en una navegación, porque
   en el navegador eso es una navegación y no un JSON.

---

## El aislamiento vive en la base, no en la aplicación

Es la decisión más importante del sistema y por eso va primera en cualquier
revisión.

Una instalación puede atender a varias ferreterías. Cada tabla de negocio
tiene una columna `tenant_id` y una política de **Row Level Security** de
PostgreSQL que exige que coincida con la empresa de la petición, tanto al
leer (`USING`) como al escribir (`WITH CHECK`).

La consecuencia práctica es la que importa: **un descuido en el código no
expone los datos de otra ferretería**. Si un endpoint consulta una tabla sin
filtrar, PostgreSQL no devuelve la fila, porque la política la oculta.

Si el aislamiento estuviera en el código, bastaría un `->where` olvidado en
un endpoint nuevo para abrir una fuga, y nadie lo notaría hasta que pasara.

Detalle completo en [03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md).

---

## Por qué el stock se mueve y no se edita

Hay dos gatillos en PostgreSQL que hacen dos cosas distintas:

| Gatillo | Qué rechaza |
|---|---|
| `stock_sucursales_sin_movimiento` | Un `UPDATE` o un `DELETE` sobre el saldo que no venga acompañado de su movimiento |
| `movimientos_stock_inmutables` | Cualquier `UPDATE` o `DELETE` sobre un movimiento |

La marca de que el cambio viene acompañado es una variable de transacción
(`app.stock_movimiento`) que la aplicación levanta dentro de la misma
transacción en que inserta el movimiento.

La razón de usar una variable y no buscar «el último movimiento» en la tabla
es que buscarlo no alcanza: el movimiento podría haberse insertado en otra
transacción. La variable es una declaración explícita de **«este cambio de
saldo viene acompañado de su movimiento, en esta misma transacción»**.

Consecuencia: para corregir un saldo se escribe el movimiento contrario. La
historia queda completa, y el arqueo de caja y el contraste de inventario
tienen contra qué revisar.

---

## Tareas programadas

| Tarea | Cuándo | Qué hace |
|---|---|---|
| Revisión de reposición | Todos los días a las 06:00 | Detecta variantes bajo el mínimo y genera avisos |
| Verificación del aislamiento | Cada hora | Comprueba que la RLS y las políticas sigan puestas |

La revisión de reposición corre temprano porque si se corre a las siete, ya
alguien pudo haber vendido lo último que quedaba. A las seis de la mañana
todavía no pasó.

Las dos tienen tres capas contra la ejecución doble: `onOneServer()` para que
corra una sola vez si hay dos réplicas, `withoutOverlapping()` para que una
corrida lenta no se pise con la siguiente, y un **índice único en la base**
que es la única que no puede fallar.

La verificación del aislamiento corre **además** del despliegue, porque un
despliegue puede pasar una vez y el aislamiento romperse después: un
`ALTER TABLE`, una restauración mal hecha, un rol cambiado. Una comprobación
horaria lo detecta en una hora y no en el siguiente corte de caja.

---

## Estructura del repositorio

```
apps/ferreteria/
├── api/
│   ├── app/
│   │   ├── Console/Commands/     ferreteria:verificar-aislamiento
│   │   │                         ferreteria:avisar-stock-bajo
│   │   ├── Enums/                roles, motivos, estados
│   │   ├── Exceptions/           ErrorDeFerreteria
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   ├── Middleware/       ResolverTenant, AuditarOperacion
│   │   │   └── Requests/         validacion y reglas de dominio
│   │   ├── Jobs/                 EnviarAvisoStockBajo
│   │   ├── Models/
│   │   ├── Policies/             que puede hacer cada rol
│   │   ├── Services/
│   │   │   ├── Auth/             VinculadorDeIdentidad
│   │   │   ├── Caja/             CorteDeCaja
│   │   │   ├── Precios/          ResolutorDePrecios
│   │   │   ├── Stock/            RegistroDeMovimientos
│   │   │   ├── Tenancy/          ContextoTenant
│   │   │   ├── Transferencias/   TransferenciasEntreSucursales
│   │   │   ├── Unidades/         ConversorDeUnidades
│   │   │   └── Ventas/           Mostrador
│   │   └── Support/              Cantidad (aritmetica sin coma flotante)
│   ├── bootstrap/app.php
│   ├── config/                   ferreteria.php, authentik.php
│   ├── database/
│   │   ├── factories/
│   │   ├── migrations/
│   │   └── seeders/
│   ├── routes/                   web.php, console.php
│   └── tests/
├── web/
│   ├── app/
│   │   ├── composables/          useSesion
│   │   ├── layouts/              default
│   │   ├── middleware/           auth
│   │   ├── pages/                pantallas del dominio
│   │   └── tipos/                formas de la API
│   └── server/api/               el BFF
├── deploy/
│   ├── api/                      Dockerfile y configuracion del contenedor
│   ├── caddy/                    Caddyfile
│   ├── respaldos/                respaldo.sh y restauracion.sh
│   ├── web/                      Dockerfile
│   └── docker-compose.dev.yml
│   └── docker-compose.prod.yml
└── docs/
```

---

## Puertos

| Servicio | Puerto interno | ¿Publicado en producción? |
|---|---|---|
| Caddy | 80 y 443 | **Sí** |
| API | 8080 | No |
| Interfaz | 3000 | No |
| PostgreSQL | 5432 | No |
| Redis | 6379 | No |
| Authentik | 9000 | No |

En desarrollo los puertos de datos se publican **solo en `127.0.0.1`**, para
poder entrar con una herramienta de escritorio sin exponerlos a la red.

Para entrar a PostgreSQL o a la interfaz del proveedor de identidad en
producción hay que usar un túnel SSH. Ver [07-SEGURIDAD.md](07-SEGURIDAD.md).