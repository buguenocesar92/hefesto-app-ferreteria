# 10. Pruebas y estado

Qué se verificó, qué no se ejecutó y qué queda pendiente.

Este documento se actualiza con cada cambio. **Nada se presenta como probado
que no se haya ejecutado.**

---

## El resumen, en una línea

**0 migraciones aplicadas. 0 pruebas corridas. 0 imágenes construidas.**

En esta máquina no hay PHP, ni Composer, ni Node, ni Docker, ni PostgreSQL. Todo
lo que sigue es análisis estático y consulta a registros públicos. Ninguna de
las afirmaciones de este documento dice que el sistema funcione: dicen qué se
revisó y cómo.

---

## Lo que SÍ se verificó

### 1. Estructura, por `tools/validar_apps.py`

```
python tools/validar_apps.py apps/ferreteria
```

Comprueba: `README` presente, sintaxis YAML del compose, sintaxis shell de los
tres scripts, barrido de caracteres no latinos, barrido de secretos, existencia
de Row Level Security y de `tenant_id`, que no se publique ningún puerto de base
de datos ni de caché en producción, que existan los once documentos numerados y
que no falte ninguna tilde.

Resultado actual: **11 OK / 0 avisos / 0 errores**.

### 2. Ortografía, por `tools/acentuar.py`

```
python tools/acentuar.py --aplicar apps/ferreteria
```

Además de la documentación, se corrigieron los **comentarios, los mensajes de
error y los mensajes de validación** del código, con un corrector escrito para
esto que solo toca prosa y nunca identificadores. Se verificó que el «esqueleto
de código» de 137 archivos (código, literales que no son prosa y literales de
prosa con el interior en blanco) quedó **idéntico** antes y después: ninguna
columna, regla de validación, ruta ni trozo de SQL cambió.

### 3. Sintaxis de los scripts de shell

`bash -n` sobre `entrypoint.sh`, `respaldo.sh` y `restauracion.sh`. El mismo
validador lo corre en cada ejecución.

### 4. Equilibrio sintáctico del PHP

Recuento de llaves, paréntesis y corchetes sobre 110 archivos, ignorando
cadenas y comentarios. Resultado: **0 desbalances**.

Esto **no** es `php -l`. No detecta un punto y coma faltante ni un tipo
devuelto incompatible. Es un control de escritura, no de corrección.

### 5. Modelos contra migraciones

Se comprobó que **cada columna** de la lista `$fillable` de los 19 modelos
existe en la migración que la crea. Un modelo que nombra una columna que nadie
crea no falla al escribir el código: falla en producción, en la primera fila.

### 6. Versiones de paquetes e imágenes

Consultadas directamente en Packagist, npm y Docker Hub el **18 de octubre de
2026**:

| Paquete | Versión | Paquete | Versión |
|---|---|---|---|
| `laravel/framework` | `v13.37.0` | `phpunit/phpunit` | `13.4.3` |
| `laravel/socialite` | `v5.31.0` | `fakerphp/faker` | `v1.24.1` |
| `laravel/tinker` | `v3.0.2` | `mockery/mockery` | `1.6.15` |
| `firebase/php-jwt` | `v7.2.1` | `nunomaduro/collision` | `v8.9.5` |
| `predis/predis` | `v3.7.0` | | |

| Paquete | Versión | Imagen | Etiqueta |
|---|---|---|---|
| `nuxt` | `4.6.2` | `php:8.4.26-cli-alpine3.23` | comprobada |
| `vue` | `3.5.44` | `php:8.4.26-fpm-alpine3.23` | comprobada |
| `vue-router` | `5.4.0` | `node:24.22.0-alpine3.24` | comprobada |
| `typescript` | `7.0.2` | `postgres:18.6` | comprobada |
| `vue-tsc` | `3.3.13` | `redis:8.8.3-alpine` | comprobada |
| | | `caddy:2.11.7-alpine` | comprobada |

El kit local `kits/hefesto-identidad-authentik` fija
`ghcr.io/goauthentik/server:2026.8.4`.

---

## Lo que NO se ejecutó

| Cosa | Estado |
|---|---|
| `composer install` | No ejecutado. **No existe `composer.lock`** |
| `npm install` | No ejecutado. **No existe `package-lock.json`** |
| `php artisan migrate` | **0 migraciones aplicadas** |
| `php artisan test` | **0 pruebas corridas** |
| `php -l` sobre cada archivo | No ejecutado |
| `vue-tsc` / `npm run build` | No ejecutado |
| `docker compose build` | **0 imágenes construidas** |
| `ferreteria:verificar-aislamiento` | No ejecutado: necesita base de datos |
| Pruebas de la API con `curl` | No ejecutadas: no hay servicio |

### Las pruebas existen, escritas pero sin correr

| Suite | Métodos | Qué cubre |
|---|---|---|
| `Unidades` | 13 | Conversión, familias incompatibles, bases |
| `Aislamiento` | 12 | Aislamiento entre ferreterías, vista pública |
| `Stock` | 11 | Ingresos, ajustes, gatillos, saldo insuficiente |
| `Transferencias` | 13 | Despacho, recepción, diferencias, anulación |
| `Catalogo` | 11 | Matriz de variantes, unicidad |
| `Mostrador` | 8 | Cotización, venta, anulación, precio resuelto |
| `Caja` | 15 | Apertura, movimientos, arqueo, diferencia |
| **Total** | **83** | |

`phpunit.xml` define las nueve suites y **fija `DB_CONNECTION=pgsql`**: en
SQLite las pruebas de RLS pasarían sin comprobar nada, porque SQLite no tiene
Row Level Security.

---

## Pendientes y por qué

### Antes de instalar

| Pendiente | Por qué importa |
|---|---|
| `composer install` | Sin esto no hay `vendor/` y no arranca nada |
| `npm install` | Sin esto no hay la interfaz |
| **Generar y versionar `composer.lock`** | Sin el archivo, cada instalación resuelve versiones distintas. Se fijó el rango en `composer.json`, pero el archivo exacto no existe |
| **Generar y versionar `package-lock.json`** | Por lo mismo |

### Antes de producción

| Pendiente | Por qué importa |
|---|---|
| `php artisan migrate` | 14 migraciones, nunca aplicadas |
| `php artisan test` | 83 pruebas, nunca corridas |
| **Fijar las imágenes por digest SHA-256** | Hoy están fijadas por **etiqueta exacta**, que es mucho mejor que `latest` pero **una etiqueta se puede mover**. El digest no |
| **Fijar la imagen `composer:2`** | Está en el `Dockerfile` **sin versión ni digest**. Es la única imagen sin fijar |
| Configurar el proveedor de identidad | El emisor, el cliente y el secreto no existen |
| Probar el ingreso de verdad | El flujo con `state` atado a sesión **nunca se ejecutó** |
| Revisar `APP_DEBUG` y `SESSION_SECURE_COOKIE` | Deben quedar en `false` y `true` |

### Antes de cargar datos reales

| Pendiente | Por qué importa |
|---|---|
| `ferreteria:verificar-aislamiento` | Es la comprobación que dice que el aislamiento está puesto. Si falla, la aplicación parece funcionar y **no aísla nada** |
| Copia de seguridad probada | Un respaldo que nunca se restauró no es un respaldo |

---

## Lo que este documento no puede decir

No puede decir que la aplicación funcione. No puede decir que las migraciones
se apliquen. No puede decir que las pruebas pasen.

Lo que sí puede decir es qué se revisó, con qué herramienta y qué falta. Eso es
lo que hay.

---

## Cómo actualizarlo

Después de la primera ejecución real, este documento cambia de titular. El
primero es el que más importa: **0 migraciones aplicadas, 0 pruebas corridas, 0
imágenes construidas**.

El día que deje de ser cierto, este documento está mintiendo.

---

## Los dos roles de PostgreSQL: escritos, no ejecutados

La separación entre el rol de arranque (`ferreteria_admin`) y el rol de la
aplicación (`ferreteria_app`) está escrita en el `compose`, en
`deploy/postgres/inicializar-rol.sh` y en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md). **No se ha ejecutado
ninguna vez.**

En el equipo donde se escribió este cambio no había PostgreSQL ni Docker. Queda
pendiente:

1. Levantar el `compose` con el volumen de PostgreSQL vacío y confirmar que el
   rol `ferreteria_app` se crea sin error.
2. Confirmar con `SELECT rolname, rolsuper, rolbypassrls FROM pg_roles` que el
   rol de la conexión no es superusuario ni salta la RLS.
3. Correr `php artisan ferreteria:verificar-aislamiento` y confirmar que sale
   con código 0. Este comando comprueba también la inmutabilidad del stock, así
   que un fallo puede ser de aislamiento o de saldo.
4. Probar el escenario que motiva todo el cambio: una ferretería intentando leer
   precios o movimientos de stock de otra.

Lo único verificado es estático: `bash -n` pasa sobre el script y el `compose`
es YAML válido, ambas cosas comprobadas por `tools/validar_apps.py`. El
razonamiento se apoya en la documentación oficial de PostgreSQL 18, no en una
ejecución.