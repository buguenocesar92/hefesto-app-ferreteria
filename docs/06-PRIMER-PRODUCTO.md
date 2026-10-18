# 06. El primer producto

El recorrido completo, de la unidad base hasta la venta, en el orden en que hay
que hacerlo. Cada paso dice qué pantalla es, qué guarda la base y por qué va
antes que el siguiente.

El ejemplo es el mismo que usa el seeder: **tornillo**. Un producto que en un
catálogo común sería una sola fila y que acá son cuatro.

---

## Por qué este orden

El orden no es caprichoso. Cada paso crea algo de lo que el siguiente depende:

```
unidades de medida  ->  categoria y marca  ->  producto
                                              |
                                              v
                                          variantes
                                              |
                              +---------------+---------------+
                              v                               v
                     lista de precios                  ingreso de stock
                              |                               |
                              +---------------+---------------+
                                              v
                                    venta en mostrador
                                              v
                                      corte de caja
```

Si se intenta crear la variante sin la unidad de contenido, la pantalla pide un
dato que todavía no existe. Por eso las unidades van primero, aunque el dueño
no piense en ellas hasta que ve el error.

---

## Paso 1. Las unidades de medida

**Pantalla:** Unidades de medida

Siete unidades para empezar. No es un capricho del ejemplo: son las que
aparecen en cualquier ferretería el primer día.

| Código | Nombre | Factor | Unidad base |
|---|---|---|---|
| `UND` | Unidad | 1 | sí, es base |
| `KG` | Kilogramo | 1 | sí, es base |
| `BOLSA40` | Bolsa de 40 kg | 40 | `KG` |
| `LT` | Litro | 1 | sí, es base |
| `M` | Metro | 1 | sí, es base |
| `CM` | Centímetro | 0,01 | `M` |
| `M3` | Metro cúbico | 1 | sí, es base |

### Qué guarda la base

Cada unidad declara **a qué unidad base pertenece** (`unidad_base_id`) y
**cuántas unidades base tiene** (`unidades_por_base`). No hay un concepto
separado de "familia": la familia es la base que declara.

`BOLSA40` declara base `KG` y factor `40`. Eso significa que **una bolsa de 40
kilos son 40 kilogramos**, y no que haya que confiar en el nombre.

### Por qué el factor y no el nombre

Porque el nombre se equivoca. "Bolsa de 40 kg" da 40 en una bolsa de cemento y
50 en una de gravilla; "Caja de 500" da 500 tornillos y 500 clavos y 12
planchas. El factor es el dato, el nombre es la etiqueta.

Con esto, el cemento se compra en bolsas y se vende en kilos sin que nadie
haga la cuenta a mano, y la cuenta no depende de que el nombre esté bien
escrito.

### Lo que no se puede hacer

Convertir de `KG` a `M`. La API responde `unidades_incompatibles` y explica que
el producto mide en la unidad base que le corresponde. No devuelve un cero ni
un número inventado, porque un número inventado en un inventario es una merma
que nadie va a explicar.

Ver la sección «Unidades de medida» de
[02](02-MODELO-DE-DATOS.md).

---

## Paso 2. Categoría y marca

**Pantalla:** dentro del catálogo, antes de crear el producto

- Categoría `HERR` — Herramienta y ferretería
- Marca `GEN` — Genérica

Son dos tablas propias, con su código. Un producto **debe** tener las dos:
categoría porque es como el dueño piensa al elegir ("¿mostrame los
herramientales?"), marca porque es como busca cuando tiene algo en la mano
("¿tenís marca X?").

Ninguna de las dos tiene precio ni stock.

---

## Paso 3. El producto

**Pantalla:** Productos, botón nuevo

```
Nombre:        Tornillo
Codigo:        TORN
Categoria:     Herramienta y ferretería
Marca:         Generica
Unidad base:   Unidad
```

**Qué guarda la base:** la fila en `productos`. Lo que tiene son las cosas que
**todos** los tornillos comparten: la categoría, la marca y la unidad en que se
miden.

**Qué NO guarda:** precio, stock y código de barras. Esos van en la variante, y
esta es la decisión que define el producto.

> **Por qué.** El precio en el producto obliga a inventar uno para algo que en
> realidad son diez cosas distintas. Un tornillo 1/2 zincado y uno 3/4 pasivado
> no valen lo mismo, y el que no tiene precio definido no se puede vender. Con
> el precio en la variante no hay nada que decidir: cada fila es una cosa real
> con su número.

Un producto sin variantes **no aparece en el mostrador** y no se puede vender.
No es un error: es un producto que todavía no se terminó de cargar.

Ver la sección «Matriz de variantes» de [02](02-MODELO-DE-DATOS.md).

---

## Paso 4. Las variantes

**Pantalla:** Productos, abrir Tornillo, pestaña Variantes

Primero dos unidades de contenido, porque son unidades del **producto**, no de
la ferretería:

| Código | Nombre | Unidad base | Factor |
|---|---|---|---|
| `BOLSA50` | Bolsa de 50 unidades | `UND` | 50 |
| `CAJA500` | Caja de 500 unidades | `UND` | 500 |

Después, las cuatro filas de la matriz:

| Medida | Material | Acabado | Unidad de venta | Precio de mostrador |
|---|---|---|---|---|
| 1/2" | Acero | Zincado | `BOLSA50` | 1.900 |
| 1/2" | Acero | Zincado | `CAJA500` | 15.000 |
| 3/4" | Acero | Pasivado | `BOLSA50` | 2.400 |
| 3/4" | Acero | Pasivado | `CAJA500` | 19.000 |

Cada fila es una **variante** real, con su código, su nombre, su unidad de
contenido, su unidad de venta y su precio.

### Los cuatro precios en una pantalla

Ese es el momento en que el dueño entiende la decisión. El mismo producto
tiene cuatro precios, y no hay forma de que el precio de la bolsa y el de la
caja se crucen con el stock equivocado: **están en la misma fila**.

Si el precio estuviera en el producto, habría que inventar uno, y el precio de
la bolsa y el de la caja serían datos aparte del stock, que es exactamente la
forma de que el inventario deje de cuadrar sin que nadie sepa por qué.

### Sobre estos precios

Son redondos y evidentes a propósito: un precio de 1.900 se reconoce de un
vistazo como un número de ejemplo. **Esta aplicación no fija precios ni
márgenes.** Los define cada ferretería, en sus listas.

---

## Paso 5. Las listas de precios

**Pantalla:** Listas de precios

Dos listas:

- **Mostrador**: es la que se usa por omisión.
- **Mayorista**: se crea **vacía**.

### Por qué la de mayorista queda vacía

Porque un margen inventado es un número que alguien va a tomar por real. La
lista existe para que el dueño la cargue con sus condiciones, no para que esta
aplicación se las sugiera.

### Cómo se cobra cuando la lista está vacía

`ResolutorDePrecios` resuelve en este orden:

1. Si la lista elegida tiene un precio para esa variante, se usa ese.
2. Si no, se cae al **precio de mostrador de la variante**.

Es decir: una lista vacía no rompe el mostrador, cobra el precio de la
variante. La lista vacía es un estado válido y útil.

---

## Paso 6. Las sucursales y el ingreso de stock

**Pantalla:** Sucursales, y después Stock

Dos sucursales. El stock **no tiene una fila por producto**: tiene una fila por
**(variante, sucursal)**.

```
Tornillo 3/4" Pasivado BOLSA50   ->  Providencia:  40
Tornillo 3/4" Pasivado BOLSA50   ->  Maipú:         0
```

El ingreso inicial es un movimiento de tipo `ingreso`, con su glosa. **Nunca es
escribir el saldo.** No hay pantalla para eso, y un `UPDATE` a mano desde un
`psql` lo rechaza un gatillo de la base.

Ver «Stock por sucursal» de [02](02-MODELO-DE-DATOS.md).

### El detalle que hace que cuadre

La fila de Maipú **no existe**, y eso no es lo mismo que tener cero. Se ve en
la diferencia entre «no hay nada» y «nunca se registró nada». Una fila que
falta se puede crear con un ingreso; un saldo en cero con una fila ya creada
puede significar que alguien lo dejó así a propósito.

---

## Paso 7. La venta de mostrador

**Pantalla:** Mostrador

1. Se escanea o se busca el código de barras de una variante.
2. La pantalla **cotiza**: pide cantidad y devuelve el precio **resuelto por el
   servidor**.
3. Se cobra.

### El backend nunca acepta el precio

La pantalla manda **variante y cantidad**. El precio lo decide
`ResolutorDePrecios`. Si la pantalla mandara el precio, cualquiera podría
mandar 1 peso y la venta quedaría registrada como si fuera real.

Un mapeo de precio alterado es un error de tipeo que no se nota hasta el mes.

### Qué pasa al cobrar

En una sola transacción:

- Se descuenta el stock de la sucursal, con su movimiento.
- Se escribe la venta y sus renglones.
- Si hay una caja abierta en esa sucursal, se registra el cobro en el corte.

Si algo falla, no queda nada a medias.

### Sobre el redondeo

El total se redondea **una sola vez, al final**, y el total redondeado queda
escrito en la venta. Si se redondeara renglón por renglón, la suma de lo
redondeado podría no cuadrar con el total: la misma diferencia silenciosa del
inventario, pero en el papel.

---

## Paso 8. El corte de caja

**Pantalla:** Corte de caja

Al cerrar el corte se comparan tres números:

```
Declarado   lo que el cajero dice que hay
Sistema     lo que el sistema dice que hay
Diferencia  declarado - sistema
```

**La diferencia se guarda siempre, completa y con su signo**, aunque sea de un
peso y aunque esté dentro de la tolerancia.

La tolerancia no redondea ni oculta: solo etiqueta el corte como
`dentro_de_tolerancia` o `requiere_revision`.

> **Por qué.** El dueño mira esta cifra todas las noches. Lo que necesita es el
> número completo, no un «0» que le diga que no pasó nada. Un sistema que
> esconde las diferencias pequeñas es un sistema que el dueño deja de mirar
> justo cuando empiezan a importar.

---

## Lo que queda después de este recorrido

| Falta | Cómo se hace |
|---|---|
| La persona que entra | Vinculación con el proveedor de identidad. Ver [07](07-SEGURIDAD.md) |
| Más stock | Transferencia entre sucursales, no ajuste manual |
| Avisos de reposición | Se generan solos al bajar del mínimo |
| Precios de mayorista | El dueño los carga en la lista |

---

## Comprobar que quedó bien

Tres preguntas. Si las tres responden lo que corresponde, el recorrido está
completo:

1. **¿El mismo producto aparece con cuatro precios distintos?** Si aparece uno
   solo, el precio quedó en el producto y el diseño está mal.
2. **¿La venta de un kilo de cemento se guarda en kilos o en bolsas?** Si se
   guardó en bolsas, la conversión no pasó por la unidad base.
3. **¿El corte de caja muestra la diferencia completa?** Si muestra cero y el
   declarado era distinto, la diferencia se está escondiendo.