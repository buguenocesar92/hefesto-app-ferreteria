# 04. Instalación

Cómo levantar el sistema. Hay dos caminos: desarrollo y producción.

---

## Requisitos

| Componente | Versión | Para qué |
|---|---|---|
| PHP | 8.4 o superior | La API |
| Composer | 2 | Dependencias de la API |
| Node.js | 22.22.3 o superior | La interfaz |
| PostgreSQL | 16 o superior | La base |
| Docker | con compose | Desarrollo y producción |

Las versiones de las **imágenes** están fijadas por etiqueta exacta y se
verificaron el 18 de octubre de 2026. Las versiones de los paquetes PHP y
JavaScript están en `composer.json` y `package.json`.

---

# Desarrollo

## 1. Obtener el código

```bash
git clone <repositorio>
cd apps/ferreteria
```

## 2. Variables de la API

```bash
cd api
cp .env.example .env
php artisan key:generate
```

`APP_KEY` es un secreto. Se genera, no se escribe.

## 3. Base de datos

Dos opciones.

### Con Docker

```bash
docker compose -f deploy/docker-compose.dev.yml up -d postgres redis
```

### Sin Docker

Servidor PostgreSQL local con una base vacía y un rol que **no** sea
superusuario:

```bash
createdb ferreteria
createuser ferreteria_app --pwprompt
psql -d ferreteria -c "CREATE EXTENSION IF NOT EXISTS pgcrypto"
```

Rellena en `api/.env` las variables `DB_*`.

## 4. Dependencias

```bash
cd api
composer install

cd ../web
npm install
```

## 5. Migraciones y datos de ejemplo

```bash
cd api
php artisan migrate
php artisan db:seed
```

El seeder crea:

- una ferretería de ejemplo,
- dos sucursales,
- siete unidades de medida con sus conversiones (incluido el pie, que es
  0,3048 metros y sirve para comprobar que la conversión no usa coma
  flotante),
- un producto «Tornillo» con cuatro variantes que forman la matriz,
- dos listas de precios, mostrador y mayorista,
- una cuenta de administración **sin clave**.

Los precios del seeder son redondos y evidentes a propósito. **No son precios
reales** y no devem ser tomados como tales.

## 6. Comprobar el aislamiento

```bash
php artisan ferreteria:verificar-aislamiento
```

Tiene que terminar con `Aislamiento entre ferreterias verificado`.

**Este comando es el que detiene el despliegue.** Si falla, no se entra a
producción. Ver [03](03-MULTITENANCY-Y-ROLES.md).

## 7. Levantar

En dos terminales:

```bash
cd api && php artisan serve
cd web && npm run dev
```

- Interfaz: http://localhost:3000
- API: http://localhost:8000
- Salud de la API: http://localhost:8000/api/v1/salud

### O todo con Docker

```bash
docker compose -f deploy/docker-compose.dev.yml up -d
```

En desarrollo las migraciones **sí** se aplican al arrancar. Los puertos de
datos se publican solo en `127.0.0.1`.

---

## 8. La primera persona que entra

La API no maneja contraseñas. El acceso se hace con el proveedor de identidad.

1. En Authentik, cree un usuario con el correo que creó el seeder
   (`administracion@ferreteria-ejemplo.ejemplo`).
2. Agruéguelo al grupo `ferreteria-operadores` (y a
   `ferreteria-administradores` si va a administrar personas).
3. Cree en Authentik una aplicación de tipo **Proveedor OpenID** con:
   - URI de redirección: `http://localhost:8000/api/v1/acceso/callback`
   - URI de salida: vacía
   - ámbito: `openid profile email`
5. Copie `client_id` y `client_secret` en `api/.env`:

```
AUTHENTIK_ISSUER=https://id.ejemplo.cl/application/o/ferreteria/
AUTHENTIK_CLIENT_ID=...
AUTHENTIK_CLIENT_SECRET=...
```

6. Levante la aplicación en Authentik con la URL de la interfaz como origen
   permitido.
7. Entre por la interfaz, elija la ferretería e ingrese.

**El `issuer` debe terminar en barra.** Authentik lo pública así, y una
diferencia de una barra hace que todos los tokens parezcan de otro emisor.

---

# Producción

## 1. Construir las imágenes

```bash
cd api   && docker build -t ferreteria-api:1.0.0 .
cd ../web && docker build -t ferreteria-web:1.0.0 .
```

Las imágenes deben quedar en un registro al que el servidor pueda llegar.

## 2. El archivo `.env.prod`

Está en la raíz de la aplicación y **no se versiona**. Créalo desde la
plantilla de [05-CONFIGURACIÓN](05-CONFIGURACION.md).

Lo mínimo obligatorio:

```bash
APP_KEY=                    # php artisan key:generate --show
APP_URL=https://ferreteria.hefestostudio.cl
APP_WEB_URL=https://ferreteria.hefestostudio.cl
ACME_EMAIL=admin@hefestostudio.cl

DB_DATABASE=ferreteria
DB_USERNAME=ferreteria_app
DB_PASSWORD=<contraseña>
REDIS_PASSWORD=<contraseña>

AUTHENTIK_ISSUER=https://id.ejemplo.cl/application/o/ferreteria/
AUTHENTIK_CLIENT_ID=<id>
AUTHENTIK_CLIENT_SECRET=<secreto>
AUTHENTIK_REDIRECT_URI=https://ferreteria.hefestostudio.cl/api/v1/acceso/callback
```

Genera las contraseñas con:

```bash
openssl rand -base64 32
```

## 3. Apuntar el dominio

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod up -d caddy
```

El dominio debe apuntar al servidor. Caddy pide el certificado y lo renueva solo.

## 4. Migraciones

**Las migraciones no se aplican en cada arranque.** Se aplican una vez, a mano.

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm api php artisan migrate --force
```

Un despliegue que cambia el esquema sin que nadie lo decida es un despliegue
que puede cortar el servicio.

## 5. Comprobar el aislamiento

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm api php artisan ferreteria:verificar-aislamiento
```

**Si esto falla, el despliegue se detiene.** No hay una excepción para «solo
esta vez».

## 6. Levantar todo

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod up -d
```

## 7. Comprobar

```bash
curl -s https://ferreteria.hefestostudio.cl/api/v1/salud
```

Debe responder `estado: ok` y `aislamiento: ok`.

---

## La secuencia de despliegue, en orden

```
construir imágenes
        |
        v
levantar solo postgres y esperar que esté sana
        |
        v
aplicar migraciones (a mano, con confirmación)
        |
        v
verificar el aislamiento  ---- si falla, aquí se detiene
        |
        v
levantar api, web y caddy
        |
        v
comprobar /api/v1/salud
```

---

## Problemas frecuentes

**«El rol de la aplicación es superusuario»**

La comprobación del aislamiento falla. Significa que la Row Level Security se
está ignorando por completo, porque con superusuario PostgreSQL no aplica las
políticas.

La causa habitual es usar el mismo rol que creó la base. Hay que crear un rol
aparte, sin superusuario y sin `BYPASSRLS`, y ponerlo en `.env.prod`.

**«No hay contexto de empresa en la conexión»**

Una operación se ejecutó fuera de una transacción con contexto. Casi siempre es
código nuevo que consulta un modelo sin pasar por el middleware, o una tarea
programada que no usa `ContextoTenant::ejecutarAislado()`.

**«El issuer no está definido» o «el token no es válido»**

El `issuer` no termina en barra, o el `redirect_uri` no coincide con el que
está configurado en la aplicación de Authentik. El detalle está en
[07-SEGURIDAD.md](07-SEGURIDAD.md).

**«La caja ya está abierta»**

Hay un corte abierto en esa sucursal. Se cierra antes de abrir otro: dos cajas
abiertas mezclarían las ventas de dos turnos.

**«No se puede convertir de X a Y»**

Las unidades no son de la misma familia. Es el comportamiento correcto: no hay
relación entre ellas y devolver un número sería inventar mercadería. Ver
[02](02-MODELO-DE-DATOS.md), sección «Por qué la conversión puede fallar».

---

## Qué queda pendiente de verificar

- **Las imágenes no se fijan por `digest`.** Se fijan por etiqueta exacta, lo
  que ya evita que una etiqueta móvil cambie el contenido, pero una etiqueta
  puede volver a publicarse. Antes de producción hay que fijar por `sha256`.
- **La imagen `composer:2` del `Dockerfile` no está fijada** ni por versión ni
  por `digest`, porque su versión no se pudo verificar. Hay que reemplazarla.
- **No hay `composer.lock` ni `package-lock.json` versionados.** Se generan al
  instalar. Para builds reproducibles hay que versionarlos.

Ver [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).