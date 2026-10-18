# 02. Modelo de datos

Este es el documento que un cliente cuestiona primero. Explica qué tabla
representa qué, por qué el precio y el stock son de la variante, y cómo se
convierten las unidades.

---

## Las diecinueve tablas

| Tabla | Qué representa |
|---|---|
| `ferreterias` | La empresa. Sin RLS, y con una vista pública que la reemplaza antes del acceso |
| `usuarios` | Las personas que operan |
| `sucursales` | Las tiendas |
| `unidades_medida` | Las unidades y su relación con la base |
| `categorias` | El orden del repiso |
| `marcas` | Las marcas que se venden |
| `productos` | El artículo base. **Sin precio y sin stock** |
| `variantes` | La fila real del catálogo. **Con precio y con stock** |
| `listas_precios` | A quién se le vende: mostrador o mayorista |
| `listas_precios_detalle` | El precio de una variante en una lista |
| `stock_sucursales` | El saldo de una variante en una tienda |
| `movimientos_stock` | La historia del stock |
| `transferencias` | El documento de un traslado entre tiendas |
| `transferencia_items` | Los renglones del documento |
| `ventas` | Lo que se cobró en el mostrador |
| `venta_items` | Los renglones de la venta |
| `cajas` | El corte de un turno |
| `caja_movimientos` | El dinero que entró y salió del corte |
| `alertas_stock` | Avisos de reposición |

Dieciocho de esas diecinueve tienen `tenant_id` y Row Level Security. La
excepción es `ferreterias`, y el motivo está en
[03](03-MULTITENANCY-Y-ROLES.md).

---

# La matriz de variantes

## El problema

Una ferretería no vende «tornillo». Vende formas concretas:

- tornillo de 1/2 pulgada, acero, zincado, bolsa de 50
- tornillo de 1/2 pulgada, acero, zincado, caja de 500
- tornillo de 3/4 pulgada, acero, pasivado, bolsa de 50
- tornillo de 3/4 pulgada, acero, pasivado, caja de 500

Son **cuatro cosas distintas**: distinto precio, distinto código de barras,
distinto stock. Un solo producto llamado «tornillo» no puede representar las
cuatro.

## La solución: filas, no grados

`productos` y `variantes` son dos tablas, y cada combinación de la matriz es
**una fila de `variantes`**:

```
productos
+----+-----------+----------------+--------------+
| id | sku       | nombre         | unidad_base  |
+----+-----------+----------------+--------------+
|  1 | TORNILLO  | Tornillo       | UND (unidad) |
+----+-----------+----------------+--------------+

variantes
+----+--------+---------+----------+---------+-----------+-----------+---------+----------+
| id | sku    | medida  | material | acabado | contenido | unidad   | precio  | unidad_   |
|    |        |         |          |         |           | contenido|          | de venta  |
+----+--------+---------+----------+---------+-----------+-----------+---------+----------+
| 11 | T-...  | 1/2"    | Acero    | Zincado | 50        | BOLSA50  | 1.900   | BOLSA50   |
| 12 | T-...  | 1/2"    | Acero    | Zincado | 500       | CAJA500  | 15.000  | CAJA500   |
| 13 | T-...  | 3/4"    | Acero    | Pasivado| 50        | BOLSA50  | 2.400   | BOLSA50   |
| 14 | T-...  | 3/4"    | Acero    | Pasivado| 500       | CAJA500  | 19.000  | CAJA500   |
+----+--------+---------+----------+---------+-----------+-----------+---------+----------+
```

## Por qué filas y no una tabla de grados

Porque **el precio y el stock tienen que ser de la misma fila**.

Con una tabla de grados, el precio sería una celda de una tabla de
`(medida, material)` y el stock sería otra tabla de `(medida, material)`. Quedaría
pendiente decidir **en qué celda se cruzan**, y esa decisión es:
- un lugar más donde equivocarse,
- imposible de registrar en una sola fila,
- y la fuente clásica de inventarios que no cuadran.

Con filas, el precio y el stock son de la misma fila. No hay nada que decidir y
no se pueden desalinear.

## Por qué `productos` no tiene columna `precio_venta`

No es un olvido. Un producto abstracto no tiene un precio: depende de la
medida, del material, del acabado y de si viene en bolsa o en caja.

Si el precio estuviera en el producto, habría que inventar uno para el
producto abstracto y corregirlo a mano en cada variante. Ese precio inventado
se convertiría en el precio «real» de la fila que nadie corrigió, y la
diferencia aparecería en el inventario como una merma que nadie abrió.

## Qué sí vive en el producto

Lo que es común a todas sus variantes:

| Columna | Qué es |
|---|---|
| `sku`, `nombre`, `descripcion` | Cómo se identifica la línea de producto |
| `categoria_id`, `marca_id` | El orden del repiso |
| `unidad_base_id` | En qué unidad se mide **el material** |
| `stock_minimo` | Mínimo de reposición, **en la unidad base** |
| `activo` | Si aparece en el catálogo nuevo |

## Los ejes de la matriz

`medida`, `material`, `acabado` y `color` son **texto libre**, y ninguno es
obligatorio.

No hay columnas «por tamaño» o «por material» porque cada línea de producto
decide sus propios ejes: el cemento tiene presentación, el tornillo tiene
medida, material y acabado, la pintura tiene volumen y color.

Ejes genéricos obligarían a inventar una columna `medida` para productos que
no la tienen, y el valor «no aplica» terminaría usándose como dato para
agrupar.

La columna `unidades` solo muestra los ejes que esa línea **usa de verdad**, y
por eso el catálogo no enseña una columna «medida» vacía que entrena al
usuario a ignorar lo que dice.

## No puede haber dos filas iguales

Un producto no puede tener dos variantes con la misma combinación de ejes,
contenido y unidad. Sin esa restricción, el mostrador podría tener dos filas
idénticas con precios distintos y nadie sabría cuál vender.

La restricción es un índice único en la base, no una validación del
formulario: el índice no se puede saltar.

---

# Unidades de medida

## El problema

Se compra en una unidad y se vende en otra.

- El cemento se compra en bolsas de 40 kilos y se vende en kilos.
- El alambre se mide en centimetros y se vende en metros.
- Los tornillos se compran en bolsas de 50 y se venden en bolsas de 50.

Si el sistema no sabe distinguir «un kilo» de «una bolsa de 40 kilos», el
mostrador termina vendiendo 40 kilos cuando el cliente pidió uno, o Cobrando
una bolsa cuando pidió un kilo. Ese error se descubre cuando el inventario no
cuadra, que es semanas después.

## Las tres unidades de una variante

Una variante tiene **tres** referencias a unidades, y cada una responde una
pregunta distinta:

| Columna | Pregunta que responde | Ejemplo |
|---|---|---|
| `unidad_contenido_id` | ¿Cómo llega del proveedor? | Kilogramo (la bolsa pesa 40) |
| `unidad_venta_id` | ¿Cómo se cuenta y se vende? | Kilogramo |
| `producto.unidad_base_id` | ¿Cómo se mide el material? | Kilogramo |

Para el cemento, las tres coinciden. Para una bolsa de 50 tornillos que solo
se vende en bolsa, las tres son distintas de la del producto si el producto se
mide en unidades sueltas.

## Cómo se declara una conversión

Cada unidad declara dos cosas:

| Columna | Qué es |
|---|---|
| `unidad_base_id` | A qué unidad base pertenece |
| `unidades_por_base` | Cuántas unidades base tiene una de esta |

Ejemplo con la base en el metro:

| Unidad | `es_base` | `unidad_base_id` | `unidades_por_base` |
|---|---|---|---|
| Metro | sí | nulo | 1 |
| Centímetro | no | Metro | 0,01 |
| Pie | no | Metro | 0,3048 |
| Kilómetro | no | Metro | 1000 |
| Kilogramo | sí | nulo | 1 |

**La base se declara, no se adivina.** Guardar solo el factor obligaría a
adivinar de qué unidad es cada factor al convertir, y adivinar mal devuelve un
número que no corresponde. Con la base declarada, la pregunta «estas dos
unidades se pueden convertir entre sí» se responde comparando un
identificador, y la respuesta es auditable.

## Cómo funciona una conversión

Toda conversión **pasa por la unidad base**. No se va de una unidad a otra
directamente.

```
12,5 cajas  ->  (x 40)  ->  500 kilos
```

El algoritmo, en `App\Services\Unidades\ConversorDeUnidades`:

1. Comprobar que las dos unidades comparten familia (misma
   `unidad_base_id`).
2. Multiplicar por el factor de origen, que lleva a la base.
3. Dividir por el factor de destino, que sale de la base.

## Por qué la conversión puede fallar

Si las unidades no son de la misma familia, la conversión **lanza error**.

Un kilogramo y un metro no tienen relación entre sí. Devolver un cero o un
uno sería inventar mercaderia, y el inventario dejaría de cuadrar de una forma
que nadie puede rastrear.

El código de error es `unidades_incompatibles` y el mensaje lo dice claro:
«No se puede convertir de Kilogramo a Metro: no son unidades de la misma
familia. El producto mide en Litro.»

## Por qué no hay coma flotante

Un `float` no puede representar 0,1 de forma exacta, y el error se acumula.

| Operación | Con coma flotante | Con la aritmética exacta |
|---|---|---|
| 0,1 + 0,2 | 0,30000000000000004 | 0,3 |
| 100 veces 1,190 | 118,99999999999996 | 119,00 |
| 12,5 kilos a gramos | 12499,999999999998 | 12500 |

La diferencia más pequeña se vería en el papel como un número que cuadra, y
al compararlo con el saldo del sistema daría una diferencia que no existe.

`App\Support\Cantidad` trabaja con cadenas de dígitos y un exponente, que es
como PostgreSQL representa internamente los `numeric`. El factor
`0,3048` se guarda como la fracción 3048/1000000 y se aplica multiplicando por
el numerador y dividiendo por el denominador, con división entera sobre la
cadena de dígitos.

La cantidad en pantalla nunca se convierte a `Number` en la interfaz, por la
misma razón.

## El mínimo de reposición está en la unidad base

`productos.stock_minimo` se guarda **en la unidad base del producto**, no en
la unidad de la presentación.

Si el mínimo fuera «3 bolsas» y el proveedor pasa de bolsas de 40 kilos a
bolsas de 25, el mínimo bajaría solo, sin que nadie lo decidiera. Al
guardarlo en la unidad base, significa lo mismo aunque cambie el tamaño de la
bolsa.

## Por qué la unidad de venta no se puede cambiar

Una variante que **ya tiene movimientos** no puede cambiar su
`unidad_venta_id`.

El saldo está expresado en esa unidad. Cambiarla dejaría los saldos históricos
diciendo una cosa y significando otra, sin convertir ninguno de los dos. El
saldo es la evidencia de lo que se dijo que había; dejarlo de significar eso es
peor que tener una columna más.

El sistema no lo bloquea por gusto: la regla está en el formulario y avisa con
un mensaje que explica el motivo.

---

# Stock por sucursal

## Una fila por terna

`stock_sucursales` tiene una fila por **(variante, sucursal)**.

Si el stock viviera en el producto, «cuánto hay en Providencia» y «cuánto hay
en Concepción» serían el mismo número, y con dos locales el inventario deja
de cuadrar sin que nadie note nada. La fila por terna hace que la pregunta del
mostrador sea una consulta y no un filtro mental.

El índice único sobre `(tenant_id, variante_id, sucursal_id)` impide que
haya dos filas pelando por el mismo stock.

## Qué se guarda y qué se deriva

| Columna | Estado |
|---|---|
| `cantidad` | Se guarda. El saldo actual |
| `cantidad_reservada` | Se guarda. Lo comprometido |
| `disponible` | **Se deriva**: `cantidad - cantidad_reservada` |

`disponible` no es columna. Si lo fuera, habría que recalcularlo en cada
movimiento y terminaría desincronizado, que es exactamente como un local
termina prometiendo mercadería que ya salió.

## La fila que falta es distinta del saldo cero

Si la fila no existe, es que de esa variante **nunca se registró stock** en
esa tienda. Si existe con cero, es que se registró y se consumió o se ajustó.

No es lo mismo. Una fila que falta se puede crear con un ingreso; un saldo en
cero se llega ajustando, y el ajuste necesita glosa.

---

# Movimientos

## El stock no se edita

No hay ninguna pantalla donde escribir un saldo. El stock cambia por
movimientos, y cada movimiento tiene motivo.

Esto no es una convención del código: hay **gatillos en PostgreSQL** que
rechazan la escritura directa sobre el saldo, y que rechazan editar o borrar
un movimiento.

## Los motivos

| Motivo | Suma o resta | Viene de un documento | Exige glosa |
|---|---|---|---|
| `ingreso` | suma | no | no |
| `venta` | resta | sí | no |
| `traslado_salida` | resta | sí | no |
| `traslado_entrada` | suma | sí | no |
| `ajuste` | resta | no | **sí** |
| `devolucion` | suma | no | **sí** |
| `merma` | resta | no | **sí** |
| `conteo` | suma | no | **sí** |

El signo lo decide **el motivo**, no cómo se escribió la cantidad. Así el tipo
y la cantidad nunca se contradicen entre sí.

Un ajuste exige glosa porque es el único caso en que el stock cambia sin que
exista un documento que lo explique. Sin explicación, «el stock cambió» es una
frase que no sirve de nada cuando aparece una diferencia.

## El saldo es la suma de sus movimientos

Esa es la prueba de que el inventario cuadra: la suma de los movimientos de
una terna tiene que dar exactamente el saldo de esa terna.

Si algún día no cuadra, hay una fila que cambió sin movimiento, y eso es un
fallo, no una diferencia de inventario.

---

# Listas de precios

## El precio depende de dos cosas

De la **lista** (a quién se le vende) y de la **variante** (de qué).

El precio no está en la variante como «precio mayorista» porque al siguiente
tipo de cliente habría que agregar «precio constructor», y al siguiente
«precio obra». Con una tabla de listas, un precio especial es **una fila** en
`listas_precios_detalle`, no una columna nueva.

## La regla de resolución

```
1. la lista elegida tiene un precio para esa variante  ->  ese precio
2. no lo tiene                                        ->  el precio de mostrador
```

El precio de la lista gana aunque salga más caro que el de mostrador: la lista
es una decisión de la ferretería y puede servir para subir un precio sin tocar
cada variante.

El paso 2 es lo que hace que el mostrador nunca se quede sin cobrar: una
variante recién cargada, sin precios en ninguna lista, se vende al precio que
tiene.

## La lista puede ser de una sola sucursal

En la práctica los precios del mayorista no son los mismos en todas las
tiendas. Una lista atada a una sucursal **no se puede aplicar en otra**, y el
mostrador lo rechaza con un error claro en vez de cobrar al precio que no
corresponde.

---

# Transferencias entre sucursales

## Un traslado es un hecho, no un ajuste

Mover 12 cajas de la bodega central a la tienda deja dos saldos que cambian.

Si eso se hiciera ajustando los saldos sin documento, habría dos números que
cambian sin explicación, y cuando la tienda dijera «llegaron 8, no 12» no
habría nada que revisar.

Con el documento hay una respuesta: cuántas se mandaron, cuántas se
recibieron, quién recibió y cuándo.

## Los estados

```
borrador   se arma el papel, no toca stock
   |
despachada se descuenta en el ORIGEN. La mercadería está en camino
   |
recibida   se suma en el DESTINO
```

Que `despachada` y `recibida` sean pasos separados es lo que hace útil el
estado intermedio: el destino sabe que le llega algo que todavía no está en
su stock, que es información distinta de «no tengo nada».

## Recibir incompleto

La recepción acepta cantidades distintas a las enviadas. El sistema suma lo
que llegó y **deja escrito en el movimiento cuánto se recibió de lo que se
mandó**.

Eso permite que la sucursal de origen vea que algo salió pero no volvió, sin
depender de que alguien lo recuerde.

---

# Mostrador

## El renglón es una variante

El mostrador vende «3/4 pasivado bolsa de 50», no «tornillo». El renglón apunta
a `variantes`.

## El precio no viene de la pantalla

El backend no acepta el precio en la petición.

Si lo aceptara, un error de tipeo cobraría mil pesos lo que vale cien, y el
corte de caja lo mostraría como si estuviera bien. Esa clase de error se
descubre al mes, cuando ya no se puede saber qué pasó.

## El total se escribe, no se recalcula

`subtotal`, `descuento` y `total` quedan guardados en la venta y **no se
vuelven a calcular** a partir de los renglones.

El total es el que se cobró, y lo que se cobró puede incluir un redondeo, una
promoción que ya no existe o un descuento entregado. Volver a calcularlo sería
mentir sobre lo que pasó.

## Anular no es borrar

Anular devuelve el stock con un movimiento contrario y deja la venta en estado
`anulada`.

Borrar una venta cuya caja ya está cerrada dejaría el corte descuadrado sin
explicación.

---

# Corte de caja

## Un corte es un turno

Empieza cuando alguien abre la caja con un monto inicial y termina cuando
entrega el dinero y cuenta lo que hay.

Una sucursal no puede tener dos cajas abiertas: las ventas de una se sumarían
al arqueo de la otra y la diferencia sería imposible de repartir.

## La diferencia

```
diferencia = monto_declarado - monto_sistema
```

Positivo, sobrante. Negativo, faltante.

Se guarda **siempre y completa**, con su signo, aunque sea de un peso.

## Por qué se guardan los tres números

`monto_declarado`, `monto_sistema` y `diferencia`.

Guardar solo la diferencia impediría saber después si el error fue de **conteo**
o de **venta**, que son problemas muy distintos: uno es del turno y el otro es
del mostrador.

## La tolerancia no esconde nada

La tolerancia de configuración no oculta ni redondea la diferencia. Lo único
que hace es etiquetar el corte como `dentro_de_tolerancia` o
`requiere_revision`.

El dueño mira esta cifra todas las noches y necesita el número completo, no un
«0» que le diga que no pasó nada.

## El saldo es una suma de movimientos

No es un campo que se actualiza. Es el monto inicial más la suma con signo de
los movimientos del corte.

Si fuera un campo, un movimiento mal aplicado quedaría invisible. Si es una
suma, siempre se puede rehacer la cuenta y ver qué movimiento la cambia.

## El signo lo pone el tipo de movimiento

La pantalla pide el monto en positivo, porque nadie escribe «retiro de menos
50.000». El servicio lo resta si el tipo es `retiro`.

Si el signo lo mandara la pantalla, un doble clic con el menos puesto haría el
retiro dos veces.

---

# Catálogos simples

`categorias` y `marcas` son catálogos de apoyo.

No son decorativos: la marca es lo que el cliente pide en el mostrador («algo
Bosch»), y la categoría es el orden del repiso y la rotación.