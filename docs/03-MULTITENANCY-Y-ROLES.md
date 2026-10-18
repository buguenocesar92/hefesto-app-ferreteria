# 03. Multiempresa y roles

Una instalación puede atender a varias ferreterías. Este documento explica
cómo se aíslan y qué puede hacer cada rol.

---

## El patrón se reusa, no se reinventa

El aislamiento entre clientes está resuelto y escrito en toda la instalación de
Hefesto Studio. Se aplica igual aquí, sin variantes:

- columna `tenant_id` en cada tabla de negocio
- Row Level Security de PostgreSQL con `USING` y `WITH CHECK`
- `FORCE ROW LEVEL SECURITY`
- contexto con `set_config(..., true)`, de **alcance de transacción**
- una función que **falla cerrado con excepción**

Si hace falta cambiar este patrón, hay que cambiarlo en todas las
aplicaciones. No se modifica en una.

---

## Las cinco piezas

### 1. La columna `tenant_id`

Cada tabla de negocio tiene `tenant_id`, y la política se escribe contra esa
columna.

La migración `..._activar_row_level_security` verifica, antes de crear las
políticas, que todas las tablas de su lista tengan la columna. Si una no la
tiene, la migración **falla** en vez de dejarla sin protección.

### 2. La política

```sql
CREATE POLICY politica_aislamiento_variantes ON public.variantes
    FOR ALL
    TO PUBLIC
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

`USING` gobierna lo que se **lee**. `WITH CHECK` gobierna lo que se **escribe**,
incluidos los `UPDATE` que cambian el `tenant_id` de una fila a otra.

La segunda parte importa más de lo que parece: sin `WITH CHECK`, sería
posible leer lo propio y escribir filas que digaan pertenecer a otra empresa.

### 3. `FORCE ROW LEVEL SECURITY`

Sin `FORCE`, la política no se aplica al dueño de la tabla.

Eso sería aceptable en producción, donde el rol de la aplicación no es el
dueño, pero **en desarrollo el rol suele ser el dueño**, así que sin `FORCE` el
aislamiento sería decorativo justo donde se prueba.

### 4. El contexto, de alcance de transacción

`App\Services\Tenancy\ContextoTenant` es la **única** clase que escribe la
variable de sesión `app.tenant_id`, y lo hace con:

```php
DB::select('SELECT set_config(?, ?, true)', ['app.tenant_id', $tenantId]);
```

El `true` final es el alcance de **transacción**. Al confirmar o revertir,
PostgreSQL descarta el valor solo.

Por qué importa: con un `SET` normal, que es de alcance de sesión, el valor
sobreviviría a la petición, y el worker de colas —que reutiliza la misma
conexión para trabajos de ferreterías distintas— podría leer datos de otra.

Por eso `establecer()` **exige** estar dentro de una transacción y lanza
excepción si no lo está.

### 5. La función que falla cerrado

```sql
CREATE FUNCTION public.app_tenant_id() RETURNS uuid ...
    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION ... ERRCODE = 'insufficient_privilege'
```

No devuelve `NULL`. No devuelve cadena vacía. **Lanza una excepción.**

Falla de forma ruidosa, para que en el registro quede claro que una operación
se escapó del middleware.

Devolver cero filas habría sido más silencioso y mucho peor: un sistema que
parece vacío y no protegido es más peligroso que uno que se cae.

---

## El requisito de despliegue

**El rol que usa la aplicación NO puede ser superusuario ni tener `BYPASSRLS`.**

Con cualquiera de las dos, PostgreSQL ignora las políticas por completo. Todo
lo anterior quedaría decorativo.

Esto lo comprueba `php artisan ferreteria:verificar-aislamiento`, que lee
`pg_roles` para el usuario actual y falla si encuentra cualquiera de las dos
condiciones.

> En el compose de desarrollo de otras aplicaciones de la casa se afirma que
> el rol se crea sin superusuario. Eso no es lo que hace la imagen oficial de
> PostgreSQL: el usuario de `POSTGRES_USER` se crea con superusuario. Por eso
> este documento dice lo que hay que **comprobar**, y el comando lo comprueba,
> en vez de suponerlo.

---

## La tabla de empresas y su compensación

### Por qué `ferreterias` no tiene RLS

Porque se consulta en la **pantalla de entrada**, antes de que exista una
empresa de sesión. Con RLS activa y sin contexto, la consulta no devolvería
nada y el selector de acceso quedaría vacío.

Es una excepción deliberada, no un descuido.

### La compensación: `vw_ferreterias_publicas`

```sql
CREATE VIEW public.vw_ferreterias_publicas AS
SELECT slug, nombre, activo
FROM public.ferreterias
WHERE activo = true;
```

Tres columnas, solo empresas activas. Ni razón social, ni dirección, ni
teléfono: antes de autenticarse no hay motivo para que el navegador vea nada
más.

### Esa vista se usa de verdad

`AuthController::ferreterias()` lee la vista, no la tabla:

```php
public static function publicas(): array
{
    return DB::select(
        'SELECT slug, nombre, activo FROM public.vw_ferreterias_publicas ORDER BY nombre'
    );
}
```

Esto está escrito porque en aplicaciones anteriores del estudio se creó la
vista, se documentó como compensación, y **nunca se consultó**. Una protección
que no se usa no es una protección: es una promesa en la documentación.

Para que no se repita, hay dos comprobaciones:

1. `ferreteria:verificar-aislamiento` verifica que la vista exista y exponga
   exactamente tres columnas.
2. El mismo comando **lee el código de `AuthController`** y falla si no
   encuentra la referencia a la vista.

Esa segunda comprobación es unusual y a propósito: es la única forma de
detectar que alguien cambió la consulta por la tabla cruda sin que nadie lo
note.

### El mensaje de entrada es el mismo

Si se pide entrar con un identificador que no existe, la respuesta es la misma
que si estuviera desactivado. Confirmar que un identificador existe ya es
filtrar información.

---

## Por qué un permiso insuficiente es 404

Cuando una Policy rechaza, la respuesta es **404, no 403**.

```
403 Forbidden  ->  "esto existe, pero no puedes"
404 Not Found  ->  "esto no existe (para ti)"
```

Un 403 sobre un recurso de otra ferretería confirma que el recurso existe.
Esa es información que no le corresponde a quien pregunta.

El filtro está en `bootstrap/app.php`:

```php
$exceptions->render(function (AuthorizationException $e, $request) {
    if ($request->is('api/v1/*')) {
        return response()->json(['mensaje' => 'No encontrado.'], 404);
    }
    return null;
});
```

Y hay una segunda capa: la RLS filtra antes, así que el modelo **no encuentra**
la fila y la ruta da 404 por sí sola aunque la Policy llegara a permitirlo.

Las dos capas hacen lo mismo. La RLS protege los datos; la capa de
respuesta evita filtrar su existencia.

---

## El middleware que fija el contexto

`App\Http\Middleware\ResolverTenant` se aplica a todo el grupo `web`, **antes**
que el middleware `auth` de la ruta.

Por eso no puede usar `$request->user()`: resuelve el usuario por su cuenta
con el guard `web`, que ya lee la sesión.

Las peticiones sin sesión (acceso, callback, salud) pasan sin transacción. Si
esas rutas tocaran una tabla protegida, `public.app_tenant_id()` lanzaría
excepción y la petición fallaría: **el aislamiento falla cerrado, nunca
abierto**.

---

## Verificación

```bash
php artisan ferreteria:verificar-aislamiento
```

Comprueba:

1. Que `ferreterias` **no** tenga RLS, como corresponde.
2. Que las dieciocho tablas de negocio tengan RLS, `FORCE` y política completa
   con `USING` y `WITH CHECK`.
3. Que `public.app_tenant_id()` **lance excepción** sin contexto, y no
   devuelva un valor.
4. Que el rol de la aplicación no sea superusuario ni tenga `BYPASSRLS`.
5. Que la vista pública exista, tenga exactamente tres columnas y no muestre
   empresas inactivas.
6. Que **el código del acceso lea la vista** y no la tabla cruda.
7. Que existan los gatillos que impiden editar saldos y borrar movimientos.
8. Que con el contexto de una ferreteria se lean filas, y que **sin** contexto
   la lectura falle en vez de devolver datos.

Está programado **cada hora**, no solo en el despliegue. Un despliegue puede
pasar una vez y el aislamiento romperse después: un `ALTER TABLE`, una
restauración mal hecha, un rol cambiado.

---

## Los roles

El rol vive en `usuarios.rol`, **en la base**, y no en Authentik.

Authentik responde «quién es». Este rol responde «qué puede hacer acá». Son
cosas distintas: la misma persona puede ser administradora en una ferretería y
vendedora en otra, y separarlas permite cambiar el proveedor de identidad sin
reescribir permisos.

| Acción | administrador | gerente | vendedor | bodeguero |
|---|---|---|---|---|
| Ver catálogo, stock y variantes | sí | sí | sí | sí |
| Registrar una venta en mostrador | sí | sí | sí | no |
| Abrir caja | sí | sí | sí | no |
| Anotar cobros y retiros | sí | sí | sí | no |
| **Cerrar el turno** (arquear) | sí | sí | **no** | no |
| **Anular** ventas y transferencias | sí | sí | **no** | no |
| Ingreso de mercaderia | sí | sí | no | sí |
| Transferencias entre sucursales | sí | sí | no | sí |
| **Ajustar** stock | sí | sí | no | sí |
| Administrar catálogo y precios | sí | sí | no | no |
| Dar de alta y desactivar personas | sí | no | no | no |

### Por qué cerrar caja no es de cualquiera

El arqueo es el momento en que se declara cuánto hay y en que queda escrita la
diferencia del turno. Una diferencia la puede **explicar** cualquiera;
**borrarla** no.

Abrir la caja es lo primero que hace la persona al llegar y no tiene nada de
sensible, así que es de todos.

### La diferencia entre ver el stock y ajustarlo

Cualquiera puede consultar el stock: es la pregunta que se hace veinte veces
por hora.

Ajustarlo mueve dinero y mercadería. Por eso queda en bodega y gerencia, y por
eso exige glosa.

### Las Policies no son adorno

Cada acción de la tabla anterior tiene una Policy que la autoriza. La interfaz
recibe banderas para esconder botones, pero **la autorización real la hace la
API**: si alguien llama al endpoint a mano, la Policy lo rechaza con 404.

---

## Qué ve quien entra

La sesión devuelve lo que la interfaz necesita para armar el menú:

```json
{
  "id": "...",
  "nombre": "...",
  "rol": "vendedor",
  "empresa": "Ferretería de ejemplo",
  "sucursal_id": "...",
  "puede_administrar_catalogo": false,
  "puede_cerrar_caja": false,
  "puede_mover_entre_sucursales": false,
  "puede_anular": false,
  "puede_administrar_personas": false
}
```

Esas banderas son para la **interfaz**. No son una autorización: son una
cortesía para no mostrar botones que la API va a rechazar igual.

---

## Multiempresa en la práctica

Una ferretería es una fila de `ferreterias` con su `slug`.

Las dos entidades quedan sin RLS y con lo puesto:

| Tabla | RLS |
|---|---|
| `ferreterias` | **no**, y con vista pública que la reemplaza antes del acceso |
| `usuarios` | sí |
| `variantes` | sí |
| `stock_sucursales` | sí |
| `ventas` | sí |
| `cajas` | sí |
| … | sí |

Dar de alta una segunda ferretería es crear la fila, cargar su catálogo y dar de
alta sus personas. El aislamiento ya está: no hay que hacer nada especial en el
código.

---

## Los dos roles de PostgreSQL

La base de esta aplicación corre con **dos** roles, no con uno. La separación es
deliberada: es lo que hace que el aislamiento entre ferreterías funcione en vez
de quedar como decoración.

### Qué es cada rol

| Rol | Nombre | De dónde sale | Para qué sirve | ¿Lo usa la aplicación? |
|---|---|---|---|---|
| **Arranque** | `ferreteria_admin` | `POSTGRES_USER` | Crear la base `ferreteria` y las tareas administrativas del despliegue | **Nunca** |
| **Aplicación** | `ferreteria_app` | `DB_USERNAME`, creado por `deploy/postgres/inicializar-rol.sh` | Todo el tráfico de la API | **Sí, este y solo este** |

El rol de arranque no es una convención del estudio: la imagen oficial de
PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`, así que ese rol nace
**superusuario**. Por eso su contraseña va en `DB_ADMIN_PASSWORD`, separada de
`DB_PASSWORD`.

### Por qué con un solo rol no servía

La documentación de PostgreSQL 18 lo dice de forma textual:

> *«Superusers and roles with the `BYPASSRLS` attribute always bypass the row
> security system when accessing a table. Table owners normally bypass row
> security as well, though a table owner can choose to be subject to row
> security with `ALTER TABLE ... FORCE ROW LEVEL SECURITY`.*

La segunda frase es la clave. `FORCE ROW LEVEL SECURITY` sujeta al **dueño** de
la tabla, pero **no al superusuario**. Mientras la aplicación conectara con el
rol de `POSTGRES_USER`, ninguna política de este documento se iba a aplicar: una
ferretería habría visto el catálogo, los precios y los movimientos de stock de
las demás, sin error y sin aviso. El aislamiento entre ferreterías era
decorativo.

### Los atributos van todos escritos

`deploy/postgres/inicializar-rol.sh`, montado en
`/docker-entrypoint-initdb.d/`, crea el rol con `NOSUPERUSER`, `NOCREATEDB`,
`NOCREATEROLE`, `NOINHERIT` y `NOBYPASSRLS` **explícitos todos**, aunque
`NOBYPASSRLS` ya sea el valor por omisión.

Se escriben igual a propósito: un rol verificado no debería depender de un valor
por omisión que una versión nueva puede cambiar. El script también falla
ruidosamente si falta `POSTGRES_DB`, `APP_DB_ROLE` o `APP_DB_PASSWORD`.

### Por qué el rol de aplicación es dueño de la base

Justo después de crearlo, el script corre:

```sql
ALTER DATABASE ferreteria OWNER TO ferreteria_app;
```

Las migraciones de Laravel se ejecutan con el rol de la aplicación y necesitan
**crear tablas**. La única forma de darles ese permiso sin convertirlos en
superusuario es que ese rol sea el dueño de la base. Desde PostgreSQL 15 el
esquema `public` pertenece al dueño de la base, así que con esto el rol ya
puede crear tablas ahí.

Y este es el punto exacto del diseño: al ser el dueño, **las tablas quedan a su
nombre**, y por eso el `FORCE ROW LEVEL SECURITY` que usan las tablas sí lo
sujeto. Ser dueño no es saltarse la política; es lo que hace que la política lo
alcance. Un superusuario la saltearía siempre.

### Cómo se comprueba

```
php artisan ferreteria:verificar-aislamiento
```

El comando consulta `pg_roles` sobre el rol de la conexión y falla con código 1
si ese rol es superusuario o tiene `BYPASSRLS`. Aquí hay una particularidad de
esta aplicación: el comando también revisa la **inmutabilidad del stock**, o
sea, que los saldos no se puedan editar a mano. El aislamiento entre ferreterías
y el control del stock se comprueban en la misma pasada.

**Este comando no se ha ejecutado.** En el equipo donde se escribió este cambio
no había PostgreSQL ni Docker. El diseño es correcto y está sostenido en la
documentación oficial, pero nadie lo ha corrido. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).