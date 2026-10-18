# 05. Configuración

Qué significa cada variable de entorno, cuál es su valor por omisión y qué se
rompe si se cambia mal.

Los archivos `.env.example` no tienen secretos: son la lista de lo que hay que
llenar. Copiarlos a `.env` es el primer paso de la instalación
([04](04-INSTALACION.md)).

---

## Las dos listas

| Archivo | Lo que configura |
|---|---|
| `api/.env.example` | La API de Laravel: base de datos, sesión, proveedor de identidad y las reglas del negocio |
| `web/.env.example` | La interfaz de Nuxt: a qué API habla y con qué nombre se sirve |

Las dos están separadas a propósito. La interfaz nunca ve la contraseña de la
base ni el secreto del proveedor de identidad: quien los tiene es el servidor
de la API.

---

## API: aplicación

| Variable | Por omisión | Qué decide |
|---|---|---|
| `APP_NAME` | `Ferretería Hefesto` | Nombre que aparece en la interfaz |
| `APP_ENV` | `local` | `local`, `production` o `testing` |
| `APP_KEY` | vacío | **Obligatoria.** Firma cookies y cifrado |
| `APP_DEBUG` | `true` | Pila de errores. **En producción, `false`** |
| `APP_URL` | `http://localhost:8000` | URL de la API |
| `APP_WEB_URL` | `http://localhost:3000` | URL de la interfaz, la que vuelve tras entrar |

`APP_KEY` se genera con:

```
php artisan key:generate --show
```

**`APP_DEBUG=true` en producción** muestra la ruta exacta de los archivos y a
veces la consulta SQL al visitante. No es una molestia menor: es entregarle la
estructura interna a cualquiera que rompa una URL.

---

## API: base de datos

| Variable | Por omisión | Qué decide |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | **No se cambia.** Con otro motor no hay RLS y el aislamiento no existe |
| `DB_DATABASE` | `ferreteria` | Nombre de la base |
| `DB_USERNAME` | `ferreteria_app` | Rol de la aplicación |
| `DB_PASSWORD` | vacío | Contraseña del rol |
| `DB_HOST` | `postgres` | Nombre del servicio en Compose |
| `DB_PORT` | `5432` | Puerto interno |

### La regla del rol

El rol de esta conexión **no puede ser superusuario ni tener `BYPASSRLS`**.
PostgreSQL ignora por completo las políticas de Row Level Security para un rol
con cualquiera de las dos cosas: el aislamiento parecería funcionar y no
filtraría nada.

El kit `hefesto-identidad-authentik` de esta instalación crea el rol de la
aplicación **sin superusuario**, a diferencia de lo que hace la imagen oficial
de PostgreSQL con `POSTGRES_USER`. Ver
[03](03-MULTITENANCY-Y-ROLES.md).

Se comprueba con:

```
php artisan ferreteria:verificar-aislamiento
```

---

## API: caché, cola y sesión

| Variable | Por omisión | Qué decide |
|---|---|---|
| `CACHE_STORE` | `redis` | Dónde viven las cachés |
| `QUEUE_CONNECTION` | `redis` | Dónde viven los trabajos encolados |
| `SESSION_DRIVER` | `database` | Dónde vive la sesión abierta |
| `SESSION_SECURE_COOKIE` | `false` | En producción, `true` |
| `REDIS_HOST` | `redis` | Nombre del servicio |
| `REDIS_PORT` | `6379` | Puerto interno |
| `REDIS_PASSWORD` | vacío | **En producción, obligatoria** |

`SESSION_DRIVER=database` es lo que permite revocar una sesión desde la base si
alguien pierde el equipo: se borra la fila y la cookie deja de servir.

`SESSION_SECURE_COOKIE=false` es correcto solo en `http://localhost`. En
producción, con HTTPS, la cookie de sesión tiene que ser `Secure`.

---

## API: proveedor de identidad (Authentik)

| Variable | Por omisión | Qué decide |
|---|---|---|
| `AUTHENTIK_ISSUER` | vacío | **Obligatoria.** Emisor del token |
| `AUTHENTIK_CLIENT_ID` | vacío | **Obligatoria** |
| `AUTHENTIK_CLIENT_SECRET` | vacío | **Obligatoria** |
| `AUTHENTIK_REDIRECT_URI` | vacío | Debe coincidir con el registrado |
| `AUTHENTIK_SCOPES` | `openid profile email` | Qué se pide al entrar |
| `AUTHENTIK_ALGORITMO` | `RS256` | Algoritmo de firma del token |
| `AUTHENTIK_GRUPO_ADMIN` | `ferreteria-administradores` | Grupo que marca a la administración |
| `AUTHENTIK_GRUPO_OPERADORES` | `ferreteria-operadores` | Grupo que autoriza a entrar |

El emisor se normaliza con una barra final, porque Authentik lo pública así y
una barra de diferencia hace que todos los tokens parezcan venir de otro
emisor. Está en `config/authentik.php`.

**El grupo no da permisos por sí solo.** El rol con el que se autoriza cada
acción vive en `usuarios.rol`, que es de la ferretería. El grupo sirve para
que el alta de personas sea auditable en el proveedor de identidad.

Quien no está en `AUTHENTIK_GRUPO_OPERADORES` no entra, aunque exista en la
base: la base dice que trabaja acá, Authentik que ya no es de la empresa.

---

## API: reglas del negocio

Estas seis variables son las únicas que cambian el comportamiento observable
del negocio. Todas tienen valor por omisión y todas son opcionales.

| Variable | Por omisión | Qué decide |
|---|---|---|
| `FERRETERIA_DECIMALES_CANTIDAD` | `3` | Con cuántos decimales se guarda una cantidad |
| `FERRETERIA_DECIMALES_CONVERSION` | `4` | Con cuántos decimales se **muestra** una conversión |
| `FERRETERIA_RENGOLONES_MAXIMOS` | `200` | Máximo de renglones de una venta de mostrador |
| `FERRETERIA_TOLERANCIA_CAJA` | `0` | Umbral para etiquetar un corte |
| `FERRETERIA_DECIMALES_TOTAL_VENTA` | `2` | Redondeo del total de una venta |
| `FERRETERIA_ALERTAS_ENVIO_ACTIVO` | `false` | Si el aviso de reposición sale a internet |

### Por qué tres decimales de cantidad

Con dos, media bolsa de arena no se puede registrar: el sistema tendría que
elegir entre 0,5 y 0,51 y ninguna es la respuesta. Con tres, 12,5 kilos se
guardan 12,5.

### Por qué cuatro decimales de conversión

1 metro son 100 centímetros exactos, pero 1 libra son 0,45359237 kilogramos. Si
la conversión se redondea a dos decimales, la cuenta deja de cuadrar en el
papel y el dueño termina desconfiando del sistema.

### Por qué la tolerancia de caja no esconde nada

`FERRETERIA_TOLERANCIA_CAJA` **no decide si la diferencia se reporta**. La
diferencia se guarda siempre, completa y con su signo. Lo único que hace la
tolerancia es etiquetar el corte como `dentro_de_tolerancia` o
`requiere_revision`.

El valor por omisión es `0`: con cero tolerancia, cualquier error de cambio se
reporta, que es justo lo que el dueño quiere ver todas las noches.

### Por qué los avisos no se envían

`FERRETERIA_ALERTAS_ENVIO_ACTIVO=false` no apaga el aviso: **se genera y se ve
en pantalla**. Lo que se apaga es el envío, porque esta aplicación no trae
transporte de correo configurado. Un aviso que dice que se envió sin que haya
salido nada es peor que un aviso visible y pendiente.

---

## Interfaz (Nuxt)

| Variable | Por omisión | Qué decide |
|---|---|---|
| `NUXT_API_BASE` | `http://localhost:8000` | A qué API reenvía el servidor de Nuxt |
| `NUXT_PUBLIC_BASE` | `http://localhost:3000` | Con qué nombre se sirve la interfaz |

`NUXT_API_BASE` es configuración **privada**: solo la usa el servidor de Nuxt
para reenviar las llamadas. El navegador nunca la ve, y por eso nunca se
escribe como `NUXT_PUBLIC_*`.

---

## Cómo se aplica un cambio

| Cambio | Dónde |
|---|---|
| Un valor de `.env` | Reiniciar la API. `.env` se lee al arrancar, no en caliente |
| `config/ferreteria.php` o `config/authentik.php` | `php artisan config:clear` |
| `php.ini` o `supervisord.conf` | Reconstruir la imagen de la API |
| `Caddyfile` | `docker compose ... up -d` del proxy |

`php artisan config:clear` importa: si la configuración quedó cacheada, cambiar
`.env` no se nota y el comportamiento viejo sigue sirviendo sin que nadie sepa
por qué.

---

## Comprobación

Después de configurar, en orden:

```
php artisan ferreteria:verificar-aislamiento
curl -fsS https://api.ejemplo.cl/api/v1/salud
```

El primero comprueba el aislamiento. El segundo que la API responde. Los dos
tienen que funcionar **antes** de cargar datos reales.