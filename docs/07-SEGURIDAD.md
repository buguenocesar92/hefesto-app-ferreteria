# 07. Seguridad

Qué protege esta aplicación, dónde está cada protección y qué se decidiu no
hacer.

La idea que ordena todo lo demás: **la frontera real entre dos ferreterías está
en la base de datos**, no en el código de la aplicación. Todo lo demás son
capas que evitan tener que llega hasta esa frontera.

---

## Las cuatro capas

| Capa | Qué evita |
|---|---|
| El proxy (Caddy) | Que alguien alcance la base de datos o la API desde la red |
| La sesión y las cookies | Que un tercero se haga pasar por una persona |
| Las políticas y la RLS | Que una consulta de una ferretería vea datos de otra |
| El rol en `usuarios.rol` | Que una persona haga más de lo que le corresponde |

Cada capa falla sola. Que la fila de contexto se pierda no desactiva la RLS: la
función que la lee **falla cerrado**, con excepción.

---

## 1. Quién entra

### El proveedor de identidad

La ferretería **no maneja contraseñas**. Authentik resuelve quién es la persona;
esta aplicación solo decide qué puede hacer.

El flujo es el de autorización con código:

```
navegador  ->  /acceso/entrar/{slug}      la API responde con la URL de Authentik
navegador  ->  Authentik                  la persona se identifica allá
Authentik  ->  /acceso/callback?code=...&state=...   vuelve con el código
API        ->  {issuer}token              canjea el código por un token
```

### El parámetro `state` está atado a la sesión

Cuando la API manda al navegador a Authentik genera un valor aleatorio de 48
caracteres, lo guarda en la sesión y lo manda como `state`. Al volver, lo
comprueba contra el de la sesión con `hash_equals`, y lo consume: un `state` no
sirve dos veces.

**Por qué importa.** Sin esto, el `state` sería predecible y nadie lo
comprobaría. Alguien podría entrar con su propia cuenta en el navegador de otra
persona e inyectarle esa sesión: lo que en Authentication se llama *login
injection*. Es un ataque corto de explicar y con consecuencias reales, porque la
víctima terminaría trabajando en la ferretería del atacante sin saberlo.

### El token se verifica, no se decodifica

La firma se comprueba contra las claves que pública el emisor, con
`firebase/php-jwt`, y además se exige que el `iss` sea exactamente el
configurado.

> Decodificar el contenido de un JWT sin verificar la firma es lo mismo que no
> verificarlo: el contenido lo elige quien lo emitió, o quien lo interceptó.

Las claves se piden al proveedor una vez y se guardan en caché por un día. Si el
proveedor rota las claves, la caché se refresca sola.

### La URL de canje no se acepta desde la petición

La URL contra la que se canjea el código se construye con la configuración del
servidor. No se usa ninguna que venga en la petición, para que nadie pueda
hacer que la API canjee el código contra un emisor distinto.

### Después de entrar

La sesión se regenera al iniciar sesión y se invalida al salir. Sin eso, un
identificador de sesión que ya se usó podría volver a servir.

---

## 2. La sesión y las cookies

| Ajuste | Valor | Por qué |
|---|---|---|
| `SESSION_DRIVER` | `database` | Permite revocar una sesión desde la base si alguien pierde el equipo |
| `session.cookie_httponly` | `1` | El JavaScript de la página no puede leer la cookie |
| `session.use_strict_mode` | `1` | El servidor no acepta un identificador de sesión que él no emitió |
| `session.use_only_cookies` | `1` | La sesión no viaja en la URL, que se cachea y se comparte |
| `SESSION_SECURE_COOKIE` | `true` en producción | La cookie solo viaja por HTTPS |

`use_strict_mode` evita que alguien fabrique un identificador de sesión y lo
use sin que la base lo haya visto nunca.

---

## 3. El forgery de peticiones en el navegador

La API usa el grupo `web` de Laravel, que aplica el token CSRF. La interfaz lo
reenvía en la cabecera `X-XSRF-TOKEN`.

Solo queda fuera la ruta de salud, que no cambia nada:

```php
$middleware->validateCsrfTokens(except: ['api/v1/salud']);
```

### Por qué el navegador no habla directo con la API

La interfaz es un *backend for frontend*. El navegador pide a Nuxt y Nuxt
reenvía a la API. Dos razones:

1. `NUXT_API_BASE` es configuración **privada**: el navegador nunca ve la URL
   interna de la API, ni la contraseña de la base, ni el secreto del proveedor
   de identidad.
2. Un backend for frontend es el lugar natural para reenviar la cookie de
   sesión en el mismo origen, que es lo que hace que CSRF y cookies dejen de ser
   un problema de origen cruzado.

Ver [01](01-ARQUITECTURA.md).

---

## 4. El aislamiento entre ferreterías

Esta es la parte que no se puede relajar sin entender qué protege. Está en
detalle en [03](03-MULTITENANCY-Y-ROLES.md); el resumen:

- Cada tabla de negocio tiene `tenant_id` y una política de RLS con `USING` y
  `WITH CHECK`.
- `FORCE ROW LEVEL SECURITY`: también el dueño de la tabla queda sujeto.
- El contexto se fija con `set_config(..., true)`, que es **de alcance
  transaccional**: al cerrar la transacción desaparece solo.
- La función que lo lee **falla cerrado**. Si no hay contexto, lanza excepción;
  no devuelve «todos» ni «ninguno».

### El rol de la aplicación

No puede ser superusuario ni tener `BYPASSRLS`. Con cualquiera de las dos
cosas, PostgreSQL ignora las políticas y el aislamiento **parecería funcionar
mientras no filtra nada**.

Es un error fácil de cometer y muy difícil de ver, porque la aplicación sigue
respondiendo bien. Por eso existe `ferreteria:verificar-aislamiento`, que lo
comprueba.

### Por qué 404 y no 403

Un `403` sobre un recurso de otra ferretería confirma que **el recurso existe**.
Eso ya es filtrar información: basta con adivinar identificadores.

Por eso un permiso insuficiente también se responde `404`, en
`bootstrap/app.php`.

---

## 5. El stock no se edita

No hay ninguna pantalla donde escribir un saldo, y no es una convención: hay
**gatillos en PostgreSQL**.

- `stock_saldo_sin_movimiento` rechaza `UPDATE` y `DELETE` sobre el saldo, salvo
  que se levante la marca transaccional `app.stock_movimiento`.
- `movimientos_stock_inmutables` rechaza editar o borrar un movimiento ya
  escrito.

Un `UPDATE` manual desde un `psql` **no pasa**. Para corregir algo se escribe el
movimiento de ajuste, que queda en la historia.

### Por qué importa

Un saldo corregido a mano es un saldo que nadie puede explicar después. Cuando
el inventario no cuadra, la pregunta siempre es la misma: ¿cuándo cambió esto y
por qué? Un saldo que solo se puede cambiar con un movimiento tiene respuesta.

---

## 6. La superficie expuesta

### En producción

El **único** servicio que pública puertos es el proxy:

| Puerto | Servicio |
|---|---|
| 80 | HTTP, que redirige a HTTPS |
| 443 | HTTPS |
| 443/udp | HTTP/3 |

Ni la base de datos, ni Redis, ni el proveedor de identidad son accesibles desde
la red. En desarrollo los datos se publican **solo en `127.0.0.1`**, que no es
accesible desde la red.

### Qué es público y por qué

| Ruta | Por qué es pública |
|---|---|
| `api/v1/salud` | Sonda de disponibilidad |
| `api/v1/acceso/ferreterias` | El selector se dibuja antes de entrar |
| `api/v1/acceso/configuracion` | Dice si falta algo de la configuración, para diagnosticar |
| `api/v1/acceso/entrar/{slug}` | Inicia el acceso |
| `api/v1/acceso/callback` | Vuelve del proveedor |

`acceso/configuracion` responde con la **presencia** de la configuración, no con
los valores: dice que falta `AUTHENTIK_ISSUER`, no cuál es.

### El selector de ferreterías

La tabla `ferreterias` no tiene RLS porque se consulta antes de saber quién
entra. Se compensa con la vista `vw_ferreterias_publicas`, de **tres columnas**:
`slug`, `nombre` y `activo`.

Que la vista exista no basta: tiene que **usarse**. `AuthController` la consulta
de verdad, y `ferreteria:verificar-aislamiento` falla si deja de hacerlo. En
aplicaciones anteriores de esta instalación se creó la vista y nunca se leyó,
mientras la documentación afirmaba que sí.

---

## 7. La auditoría

El middleware `AuditarOperacion` registra quién hizo qué y desde dónde. Los
cambios de stock y de caja dejan además un registro de negocio: el movimiento y
el corte, con su persona y su fecha.

El registro de aplicación **no reemplaza** al de la base. Si alguien entra
directamente por `psql` con el rol de la aplicación, los gatillos lo detienen en
las tablas críticas, pero no todo queda anotado.

---

## 8. Lo que esta aplicación NO hace

Decirlo es parte de la documentación:

- **No cifra datos en reposo.** Si el disco del servidor se lo llevan, se lo
  llevaron. El cifrado del volumen es del servidor donde se instala.
- **No limita intentos de acceso.** La protección de fuerza bruta es del
  proveedor de identidad, no de aquí.
- **No manda correos.** Los avisos de reposición se generan y se ven en
  pantalla; no hay transporte configurado y no se finge un envío que no existe.
- **No audita a nivel de base de datos.** La auditoría vive en la aplicación.
- **No reemplaza al proveedor de identidad.** Si Authentik cae, nadie entra.

---

## Comprobaciones

En orden, después de instalar:

```
php artisan ferreteria:verificar-aislamiento
```

```
curl -fsS https://api.ejemplo.cl/api/v1/salud
```

Y a mano, desde fuera del servidor:

1. `nmap` o `ss -ltn` **no** muestra 5432, 6379 ni el puerto de la API.
2. Un recurso de otra ferretería responde **404**, no 403.
3. `POST` a cualquier ruta sin el token CSRF se rechaza.
4. La cookie de sesión no aparece en `document.cookie`.
5. Una petición sin contexto de ferretería **lanza excepción**, no devuelve
   datos.