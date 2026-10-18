# Gestión de Ferretería

Aplicación propia de Hefesto Studio para administrar una **ferretería y local
de materiales de construcción**: catálogo con variantes, unidades de medida,
stock por sucursal, transferencias entre tiendas, mostrador y corte de caja.

No es un kit de despliegue de una herramienta de terceros. El código es
original y está en este repositorio.

---

## El problema que resuelve

El problema de una ferretería no es saber qué productos existen. Es **saber
qué forma concreta de cada producto hay, cuánto hay de cada una, en qué
tienda y a qué precio**.

Una ferretería no vende «tornillo». Vende «3/4 pasivado en bolsa de 50», y
ese artículo tiene su propio precio, su propio código de barras, su propio
saldo en cada tienda y su propia historia. El mismo producto base puede tener
diez variantes, con diez precios distintos, y la mitad puede estar agotada en
una sucursal y sobrando en la otra.

El segundo problema es silencioso y caro: **el inventario que no cuadra**.
Nadie mira las diferencias a diario, y cuando se miran ya son grandes y nadie
sabe cuándo quedaron así.

El tercero es el que el dueño mira todas las noches: **la diferencia de caja**.

Esta aplicación responde a las tres:

1. **Qué forma concreta hay y cuánto**, con precio y stock de la variante, y
   con el saldo separado por sucursal.
2. **Por qué el número es el que es**, con cada movimiento registrado, con su
   motivo, su fecha y su persona. El saldo no se edita: hay un gatillo en la
   base que lo rechaza.
3. **Cuánto dinero hubo y cuánto se declaró**, con la diferencia completa y
   con su signo, en todos los cortes.

---

## Las cuatro decisiones que definen el producto

### 1. El precio y el stock son de la variante, nunca del producto

```
producto   tornillo        (no tiene precio, no se vende, no tiene stock)
variante   3/4" acero pasivado bolsa 50    precio 2.400   stock por tienda
variante   3/4" acero pasivado caja 500    precio 19.000  stock por tienda
```

Cada combinación de medida, material, acabado y presentación es **una fila
real**, no una celda de una tabla de grados. Por qué: el precio y el stock
tienen que ser de la misma fila. Con una tabla de grados, el precio sería un
par (medida, material) y el stock otro, y quedaría pendiente decidir en qué
celda se cruzan. Con filas, no hay nada que decidir y no se pueden
desalinear.

> Poner el precio en el producto base obliga a inventar uno para un producto
> que en realidad son diez cosas distintas. Es el error de diseño que hace
> que el inventario de una ferretería deje de cuadrar.

### 2. Las unidades se convierten con el factor declarado, nunca con un número escrito en el código

Se compra en una unidad y se vende en otra. El cemento se compra en bolsas de
40 kilos y se vende en kilos; el alambre se vende en metros y se mide en
centímetros. Cada unidad declara **a qué unidad base pertenece** y **cuántas
unidades base tiene**, y toda conversión pasa por esa base.

Si dos unidades no son de la misma familia, la conversión **falla con error**.
Devolver un número cualquiera sería inventar mercaderia, y esa es la forma más
rápida de que un inventario deje de cuadrar sin que nadie lo note.

Además, la aritmética no usa coma flotante: 12,5 kilos son exactamente 12.500
gramos, no 12.499,999999999998.

### 3. El stock no se edita: se mueve

No hay ninguna pantalla donde escribir un saldo. El stock cambia por
**movimientos**, y cada movimiento tiene motivo.

No es una convención del código: hay **gatillos en PostgreSQL** que rechazan
cualquier escritura sobre el saldo que no venga acompañada de su movimiento,
y que rechazan editar o borrar un movimiento ya escrito. Un `UPDATE` a mano
desde un `psql` tampoco pasa. Para corregir algo se escribe el movimiento
contrario.

El saldo es el saldo **de una terna (variante, sucursal)**. Una fila por
variante en el producto no permite responder «cuánto hay en Providencia» sin
adivinar.

### 4. La diferencia de caja se guarda completa, con su signo

Es `monto_declarado - monto_sistema`. Se guarda siempre, aunque sea de un
peso, y con los tres números por separado: lo declarado, lo que el sistema
decía y la diferencia.

La tolerancia configurable **no oculta ni redondea la diferencia**. Lo único
que hace es etiquetar el corte como `dentro_de_tolerancia` o
`requiere_revision`. El dueño mira esta cifra todas las noches y necesita el
número completo, no un «0» que le diga que no pasó nada.

---

## Qué hay adentro

| Carpeta  | Qué es                                                  |
|----------|---------------------------------------------------------|
| `api/`   | API Laravel: catálogo, stock, mostrador, caja y aislamiento |
| `web/`   | Interfaz Nuxt, que funciona como BFF hacia la API        |
| `deploy/`| Imagenes, compose de desarrollo y producción, respaldos  |
| `docs/`  | Documentacion numerada, de `00` a `10`                   |

Empiece por `docs/00-INDICE.md`.

Si va a mirar una sola cosa del modelo de datos, lea
`docs/02-MODELO-DE-DATOS.md`: ahi se explica la matriz de variantes y la
conversion de unidades, que es lo que un cliente va a cuestionar primero.

---

## Multiempresa

Una instalación puede atender a varias ferreterias. El aislamiento se hace en
la **base de datos**, no en el código de la aplicación: cada tabla de negocio
tiene `tenant_id` y una política de Row Level Security de PostgreSQL que
exige que coincida con la empresa de la peticion, tanto al leer como al
escribir.

La tabla de ferreterias es la única excepcion, y es a propósito: se consulta
en la pantalla de entrada, cuando todavia no hay empresa de sesión. Se compensa
con la vista `vw_ferreterias_publicas`, que expone solo tres columnas y que
**se usa de verdad** para pintar el selector.

Un recurso de otra ferreteria se responde **404, no 403**: confirmar con un
código de error que algo existe en otra empresa ya es filtrar información.

Ver `docs/03-MULTITENANCY-Y-ROLES.md`.

---

## Estado de este código

**Nada de esto se ha ejecutado.** No se aplicó ninguna migración, no se corrió
ninguna prueba, no se construyó ninguna imagen. Las versiones de los paquetes
y de las imágenes se verificaron contra los registros oficiales el 18 de
octubre de 2026, y eso es lo único que se puede afirmar.

`docs/10-PRUEBAS-Y-ESTADO.md` dice exactamente que se verifico, que no y que
queda pendiente.

---

## Documentación

| Documento | Para qué sirve |
|---|---|
| [00-INDICE](docs/00-INDICE.md) | Mapa de la documentación |
| [01-ARQUITECTURA](docs/01-ARQUITECTURA.md) | Cómo se arma el sistema y por qué |
| [02-MODELO-DE-DATOS](docs/02-MODELO-DE-DATOS.md) | La matriz de variantes y las unidades |
| [03-MULTITENANCY-Y-ROLES](docs/03-MULTITENANCY-Y-ROLES.md) | Cómo se aíslan las ferreterías |
| [04-INSTALACION](docs/04-INSTALACION.md) | Cómo se levanta |
| [05-CONFIGURACION](docs/05-CONFIGURACION.md) | Cada variable de entorno |
| [06-PRIMER-PRODUCTO](docs/06-PRIMER-PRODUCTO.md) | El recorrido completo, de la unidad base a la venta |
| [07-SEGURIDAD](docs/07-SEGURIDAD.md) | Autenticación, CSRF y puerto de error |
| [08-RESPALDOS](docs/08-RESPALDOS.md) | Respaldo y restauración |
| [09-API](docs/09-API.md) | Cada endpoint y cada código de error |
| [10-PRUEBAS-Y-ESTADO](docs/10-PRUEBAS-Y-ESTADO.md) | Qué se verificó y qué no |

---

## Licencia

Propietaria de Hefesto Studio.