# 09. API

Cada endpoint, cada dato y cada código de error.

**Base:** `/api/v1`
**Formato:** JSON en las dos direcciones
**Autenticación:** cookie de sesión del mismo origen, con token CSRF en
`X-XSRF-TOKEN`

La interfaz nunca habla directo con la API: reenvía a través del servidor de
Nuxt ([01](01-ARQUITECTURA.md)).

---

## Convenciones

### Los números viajan como texto

Cantidades y montos se mandan y se reciben como **cadena**, no como número:

```json
{ "cantidad": "12.5", "precio": "2400.00" }
```

No es un descuido. Un número JSON es coma flotante: `12.5` no es
exactamente 12,5, y en un inventario la diferencia entre 12,499999999999998 y
12,5 es una merma que nadie puede explicar. Ver `App\Support\Cantidad`.

### Un recurso de otra ferretería es 404

No 403. Confirmar con un código de error que un recurso existe en otra empresa
ya es filtrar información.

---

## Códigos de respuesta

| Código | Cuándo |
|---|---|
| `200` | Todo bien |
| `404` | No existe, **o** es de otra ferretería, **o** el rol no alcanza |
| `401` | No hay sesión abierta |
| `403` | El proveedor de identidad rechazó el acceso |
| `422` | Los datos no pasan la validación |
| `422` con `codigo` | Regla del negocio incumplida |
| `500` | Error del servidor |

Dos `422` distintos: uno de **validación de forma** y otro de **regla de
fondo**. El primero trae el detalle campo por campo; el segundo trae un
`codigo` estable.

### La forma de un error de regla

```json
{
  "mensaje": "No hay stock suficiente de «Tornillo 3/4\" Pasivado BOLSA50» en esta sucursal. Disponible: 3 BOLSA50.",
  "codigo": "stock_insuficiente"
}
```

---

## Los códigos de regla

La interfaz reacciona al `codigo`, nunca al texto del mensaje.

### Unidades

| Código | Qué pasó |
|---|---|
| `unidades_incompatibles` | Se intentó convertir entre familias distintas |
| `unidad_sin_base` | La unidad no declara a qué unidad base pertenece |

### Stock

| Código | Qué pasó |
|---|---|
| `stock_insuficiente` | El saldo quedaría en cero |
| `stock_comprometido` | El saldo quedaría bajo lo reservado en pedidos |
| `cantidad_cero` | Un movimiento de cantidad cero no dice nada |
| `glosa_obligatoria` | Ese tipo de movimiento necesita glosa |
| `falta_documento` | Ese tipo de movimiento tiene que venir de un documento |

### Transferencias

| Código | Qué pasó |
|---|---|
| `misma_sucursal` | Origen y destino son la misma sucursal |
| `transferencia_vacia` | La transferencia no tiene renglones |
| `cantidad_invalida` | Un renglón tiene cantidad cero o negativa |
| `estado_invalido` | No se puede despachar, recibir ni anular en este estado |
| `lista_de_otra_sucursal` | La lista de precios pertenece a otra sucursal |

### Ventas

| Código | Qué pasó |
|---|---|
| `venta_vacia` | Una venta sin renglones no se cobra |
| `cantidad_invalida` | Un renglón tiene cantidad cero o negativa |
| `demasiados_renglones` | Superó el máximo del mostrador |
| `caja_cerrada` | La caja elegida ya se cerró |
| `venta_anulada` | La venta ya estaba anulada |

### Caja

| Código | Qué pasó |
|---|---|
| `caja_ya_abierta` | Esa sucursal ya tiene un corte abierto |
| `caja_cerrada` | El corte ya se cerró |
| `monto_cero` | Un movimiento de monto cero no cambia nada |
| `glosa_obligatoria` | Ese tipo de movimiento necesita glosa |

---

## Acceso y salud

Sin sesión. Se pueden llamar sin estar conectado.

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/salud` | Sonda de disponibilidad |
| `GET` | `/acceso/ferreterias` | Selector de ferretería. Lee la vista pública |
| `GET` | `/acceso/configuracion` | Dice **si falta** algo de la configuración, no qué |
| `GET` | `/acceso/entrar/{slug}` | Devuelve la URL a la que hay que ir |
| `GET` | `/acceso/callback` | Vuelve del proveedor de identidad |

### `GET /acceso/entrar/{slug}`

```json
{ "redirigir_a": "https://id.ejemplo.cl/application/o/ferreteria/authorize?..." }
```

Si la ferretería no existe, responde **igual** con el mismo mensaje y `404`.
Confirmar que una existe antes de entrar ya es filtrar información.

### `GET /acceso/callback`

Lleva `code` y `state`. El `state` tiene que ser el que esta sesión generó.
Responde:

```json
{ "mensaje": "Sesión iniciada.", "redirigir_a": "/" }
```

---

## Sesión

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/yo` | Quién está operando |
| `POST` | `/salir` | Cierra la sesión e invalida el identificador |

---

## Catálogo

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/productos` | Lista, con filtros |
| `POST` | `/productos` | Crea un producto |
| `GET` | `/productos/categorias` | Categorías |
| `GET` | `/productos/marcas` | Marcas |
| `GET` | `/productos/{id}` | Detalle con sus variantes |
| `PUT` | `/productos/{id}` | Actualiza |
| `POST` | `/productos/{id}/desactivar` | Baja lógica |
| `GET` | `/productos/{id}/convertir` | Convierte una cantidad entre unidades |
| `GET` | `/productos/{id}/variantes` | Las variantes |
| `POST` | `/productos/{id}/variantes` | Crea una variante |
| `GET` | `/variantes/{id}` | Detalle |
| `PUT` | `/variantes/{id}` | Actualiza |
| `POST` | `/variantes/{id}/desactivar` | Baja lógica |
| `GET` | `/variantes/barras/{codigo}` | Busca por código de barras |

### `POST /productos`

```json
{
  "sku": "TORN",
  "nombre": "Tornillo",
  "descripcion": "Tornillo de acero para uso general.",
  "categoria_id": "uuid",
  "marca_id": "uuid",
  "unidad_base_id": "uuid",
  "stock_minimo": 10
}
```

`unidad_base_id` es obligatoria. Sin unidad base no hay conversión posible, y
una ferretería que no puede convertir unidades es una ferretería que suma a
mano.

### `POST /productos/{id}/variantes`

```json
{
  "sku": "TORN-3/4-PAS-BOLSA50",
  "nombre": "Tornillo 3/4\" Pasivado BOLSA50",
  "medida": "3/4\"",
  "material": "Acero",
  "acabado": "Pasivado",
  "contenido": 50,
  "unidad_contenido_id": "uuid",
  "unidad_venta_id": "uuid",
  "codigo_barras": "7801234567890",
  "precio_venta": "2400.00"
}
```

Dos reglas que la base y el servidor hacen cumplir:

- **No se puede repetir** la combinación de medida, material, acabado y color.
- **La unidad de venta no se cambia** en una variante que ya tiene
  movimientos: el saldo guardado está en esa unidad.

### `GET /variantes/barras/{codigo}`

Es la consulta del mostrador. Devuelve la variante con su precio resuelto para
la lista indicada. Si el código no existe en esta ferretería, `404`.

---

## Unidades

| Método | Ruta |
|---|---|
| `GET` | `/unidades` |
| `POST` | `/unidades` |
| `GET` | `/unidades/{id}` |
| `PUT` | `/unidades/{id}` |
| `POST` | `/unidades/{id}/desactivar` |

### `POST /unidades`

```json
{
  "codigo": "BOLSA40",
  "nombre": "Bolsa de 40 kg",
  "unidades_por_base": 40,
  "unidad_base_id": "uuid",
  "es_base": false
}
```

Tres reglas:

1. `unidades_por_base` tiene que ser **mayor que cero**.
2. Una unidad base vale exactamente `1` y no declara base.
3. Una unidad que vale `1` respecto de la base **es** la base: hay que marcar la
   casilla, porque si no el sistema no sabría a cuál convertir.

Una unidad que ya se usa en el catálogo y tiene stock **no** cambia su factor:
el historial quedaría en otra unidad.

---

## Sucursales

| Método | Ruta |
|---|---|
| `GET` | `/sucursales` |
| `POST` | `/sucursales` |
| `GET` | `/sucursales/{id}` |
| `PUT` | `/sucursales/{id}` |
| `POST` | `/sucursales/{id}/desactivar` |

---

## Stock

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/stock` | Saldos, con filtros |
| `GET` | `/stock/resumen` | Totales por sucursal |
| `GET` | `/stock/bajo-minimo` | Lo que está bajo el mínimo |
| `GET` | `/stock/alertas` | Avisos de reposición |
| `POST` | `/stock/variantes/{id}/ingresar` | Ingreso |
| `POST` | `/stock/variantes/{id}/ajustar` | Ajuste con glosa |
| `GET` | `/stock/variantes/{id}/movimientos` | Historial |

### `POST /stock/variantes/{id}/ajustar`

```json
{
  "sucursal_id": "uuid",
  "cantidad": "-3",
  "glosa": "Merma por bolsa abierta en el patio"
}
```

La glosa es obligatoria y de **al menos diez caracteres**. «Ajuste» no dice
nada dentro de seis meses; «bolsa abierta en el patio» sí.

**No existe ningún endpoint que escriba el saldo.** Todo pasa por un
movimiento, y la base lo rechaza con un gatillo.

---

## Transferencias

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/transferencias` | Lista |
| `POST` | `/transferencias` | Crea en estado `borrador` |
| `GET` | `/transferencias/{id}` | Detalle |
| `POST` | `/transferencias/{id}/despachar` | `borrador` → `despachada`, sale el stock |
| `POST` | `/transferencias/{id}/recibir` | `despachada` → `recibida`, entra el stock |
| `GET` | `/transferencias/{id}/diferencias` | Lo enviado contra lo recibido |
| `POST` | `/transferencias/{id}/anular` | Anula y devuelve el stock |

### `POST /transferencias`

```json
{
  "sucursal_origen_id": "uuid",
  "sucursal_destino_id": "uuid",
  "observaciones": "Reposición de invierno",
  "items": [
    { "variante_id": "uuid", "cantidad": "10" }
  ]
}
```

### Por qué despacho y recepción están separados

Porque «en camino» es un estado real. Si todo fuera un solo paso, en el
tránsito no habría registro de qué se movió, y una diferencia entre lo enviado
y lo recibido no se podría atribuir a nadie.

`GET /transferencias/{id}/diferencias` existe para eso.

---

## Mostrador

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/ventas` | Lista |
| `POST` | `/ventas/cotizar` | Cotiza **sin** registrar nada |
| `POST` | `/ventas` | Registra la venta |
| `GET` | `/ventas/{id}` | Detalle |
| `POST` | `/ventas/{id}/anular` | Anula y devuelve el stock |

### `POST /ventas/cotizar`

```json
{
  "sucursal_id": "uuid",
  "lista_precios_id": "uuid",
  "items": [ { "variante_id": "uuid", "cantidad": "2" } ]
}
```

Devuelve los precios resueltos y el total, sin tocar nada.

### `POST /ventas`

La misma forma, y agrega `caja_id`, `cliente_nombre`, `cliente_rut`, y
`descuento` y `detalle` por renglón.

**El precio no se manda.** La pantalla manda variante y cantidad; el precio lo
decide `ResolutorDePrecios`. Si se aceptara el precio desde la pantalla,
cualquiera podría mandar un peso y la venta quedaría registrada como si fuera
real.

---

## Caja

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/cajas` | Cortes |
| `POST` | `/cajas` | Abre un corte |
| `GET` | `/cajas/cortes/{sucursal_id}` | Cortes de una sucursal |
| `GET` | `/cajas/{id}` | Detalle |
| `GET` | `/cajas/{id}/movimientos` | Movimientos del corte |
| `POST` | `/cajas/{id}/movimientos` | Suma un movimiento |
| `GET` | `/cajas/{id}/arqueo` | Calcula el arqueo **sin cerrar** |
| `POST` | `/cajas/{id}/cerrar` | Cierra el corte |

### `POST /cajas`

```json
{ "sucursal_id": "uuid", "monto_inicial": "50000", "observaciones": null }
```

Una ferretería tiene **una caja abierta por sucursal**: hay un índice único
parcial que lo garantiza en la base, no solo en la pantalla.

### `POST /cajas/{id}/cerrar`

```json
{ "monto_declarado": "184230.50", "observaciones": null }
```

Responde con los tres números:

```json
{
  "monto_declarado": "184230.50",
  "monto_sistema": "184215.00",
  "diferencia": "15.50",
  "estado": "requiere_revision"
}
```

**La diferencia se guarda completa y con su signo**, aunque esté dentro de la
tolerancia. `estado` solo etiqueta.

### `POST /cajas/{id}/movimientos`

```json
{ "tipo": "retiro", "monto": "20000", "glosa": "Retiro al banco" }
```

El monto va **en positivo**: el signo lo pone el tipo. Un retiro de 20.000 es
`"20000"` con tipo `retiro`, no `"-20000"`.

---

## Listas de precios

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/listas-precios` | Lista |
| `GET` | `/listas-precios/precio-de` | Resuelve el precio de una variante |
| `GET` | `/listas-precios/{id}` | Detalle con sus filas |
| `POST` | `/listas-precios/{id}/precios` | Carga o cambia un precio |

### Cómo se resuelve un precio

1. Si la lista elegida tiene precio para esa variante, se usa ese.
2. Si no, se cae al **precio de mostrador de la variante**.

Una lista vacía no rompe el mostrador.

---

## Reportes

| Método | Ruta | Pregunta que responde |
|---|---|---|
| `GET` | `/reportes/ventas-del-dia` | ¿Cuánto se vendió hoy? |
| `GET` | `/reportes/rotacion` | ¿Qué se vende y qué está quieto? |
| `GET` | `/reportes/diferencias-de-caja` | ¿Dónde hubo diferencias de caja? |
| `GET` | `/reportes/reposicion` | ¿Qué hay que comprar? |
| `GET` | `/reportes/movimientos` | ¿Qué se movió? |
| `GET` | `/reportes/sucursales` | ¿Cómo va cada tienda? |

---

## Comprobación rápida

```
curl -fsS https://api.ejemplo.cl/api/v1/salud
```

Y, con sesión:

```
curl -fsS -b cookies.txt https://api.ejemplo.cl/api/v1/yo
```

Sin la cookie, el segundo debe responder `401` y no datos de nadie.