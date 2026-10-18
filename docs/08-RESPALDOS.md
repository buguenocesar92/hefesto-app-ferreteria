# 08. Respaldos

Cómo respaldar, cómo restaurar y qué reglas no se negocian.

Los dos scripts están en `deploy/respaldos/`. No dependan de un plan de
respaldo: son la forma de recuperar la ferretería.

---

## Las seis reglas

Estas reglas no son preferencias. Cada una existe porque su ausencia provoca
una pérdida concreta:

| # | Regla | Qué pasa si no está |
|---|---|---|
| 1 | Manifiesto con SHA-256 de cada archivo | Se restaura un archivo dañado y se pierde la base |
| 2 | Marcar el respaldo como completo **solo al final** | Se restaura media base, que es peor que no restaurar |
| 3 | Validar rutas y espacio antes de escribir | El respaldo falla a mitad de camino porque el disco se llenó |
| 4 | Rechazar destino ocupado salvo `--force` | Se pisa la única copia buena |
| 5 | Nunca `DROP`, usar `TRUNCATE` | Se pierden las políticas de RLS y con ellas el aislamiento |
| 6 | Dejar el servicio detenido tras restaurar | Una venta entra mientras se restaura y se pierde sin rastro |

---

## Tomar un respaldo

```
./respaldo.sh /var/respaldo/ferreteria
```

Con `--force` para escribir sobre un destino ocupado:

```
./respaldo.sh /var/respaldo/ferreteria --force
```

### Qué se crea

| Archivo | Qué es |
|---|---|
| `ferreteria-<fecha>.dump` | El volcado, formato `pg_dump` custom comprimido |
| `ferreteria-<fecha>.dump.manifiesto.txt` | El sello SHA-256 de cada archivo |
| `ferreteria-<fecha>.COMPLETO` | La marca de que terminó bien |

### El orden importa

1. Se valida el destino: existe, se puede escribir y no está ocupado.
2. Se comprueba el **espacio disponible** contra el tamaño estimado de la base.
3. Se hace el volcado.
4. Se calcula el manifiesto.
5. **Al final** se escribe `COMPLETO`.

Si el volcado falla, el archivo incompleto se elimina. Un respaldo sin
`COMPLETO` es un respaldo interrumpido, y `restauracion.sh` lo rechaza.

### Por qué el manifiesto

Un respaldo sin comprobación es una carpeta con la esperanza de que esté bien.
El manifiesto permite verificar **antes de restaurar**, que es el momento en que
de verdad importa: descubrir que el archivo estaba dañado cuando ya se sobrescribió
la base es tarde.

El propio script verifica el sello al terminar:

```
cd /var/respaldo/ferreteria && sha256sum -c ferreteria-<fecha>.dump.manifiesto.txt
```

---

## Restaurar

```
./restauracion.sh /var/respaldo/ferreteria/ferreteria-<fecha>.dump
```

Si la base tiene datos, el script **se niega** y pide confirmación:

```
./restauracion.sh /var/respaldo/ferreteria/ferreteria-<fecha>.dump --si
```

`--si` no es un detalle de forma: significa que alguien leyó qué se va a
borrar y decidió seguir.

### Qué hace, en orden

1. **Verifica el SHA-256** contra el manifiesto. Si no coincide, pregunta.
2. **Exige la marca `COMPLETO`.** Sin ella, no restaura.
3. **Comprueba si la base tiene datos.** Si los tiene, para sin `--si`.
4. **Detiene el servicio de la API.**
5. **Vacia las tablas con `TRUNCATE`**, no con `DROP`.
6. **Restaura** con `pg_restore`.
7. **Deja el servicio detenido** y lo dice.

### Por qué `TRUNCATE` y no `DROP`

`TRUNCATE` borra filas y conserva la estructura: índices, restricciones y **las
políticas de Row Level Security**.

Un `DROP DATABASE` seguido de un `CREATE` deja la base nueva, sin RLS, sin
funciones y sin vista pública. El sistema volvería a parecer que funciona
mientras **no aísla nada**, y eso es peor que una base caída porque no se nota.

### Por qué el servicio queda detenido

Restaurar con el servicio vivo significa que una venta entra mientras se
restaura la base. Esa venta se pierde sin dejar rastro, y el corte de caja de
ese turno queda descuadrado sin explicación.

Por eso el script **no levanta el servicio al final**. Se levanta a mano, y
después se comprueba el aislamiento:

```
docker compose -f deploy/docker-compose.prod.yml run --rm api \
    php artisan ferreteria:verificar-aislamiento

docker compose -f deploy/docker-compose.prod.yml up -d api
```

El orden importa: primero se comprueba que el aislamiento quedó puesto, después
se deja entrar a la gente.

---

## Con variables de entorno

Los scripts aceptan sobrescribir sus supuestos, para que funcionen en otro
equipo sin editar el archivo:

| Variable | Por omisión | Qué decide |
|---|---|---|
| `FERRETERIA_COMPOSE` | `docker compose -f deploy/docker-compose.prod.yml` | Cómo se habla con Compose |
| `FERRETERIA_SERVICIO_API` | `api` | Qué servicio se detiene |
| `FERRETERIA_PG_DUMP` | `pg_dump` | Ruta al volcador |
| `FERRETERIA_PG_RESTORE` | `pg_restore` | Ruta al restaurador |

---

## Cuándo respaldar

No hay un número mágico. Lo que sí es cierto:

- **Antes de cualquier cambio de esquema.** Una migración puede salir mal y la
  vuelta atrás tiene que existir.
- **Antes de restaurar.** Un respaldo del estado actual, por si el anterior
  estaba mal.
- **Cada noche**, con el respaldo anterior conservado. Un solo archivo que se
  sobrescribe cada día no es un respaldo: es una apuesta.

Y conviene **copiar el respaldo fuera del servidor**. Un respaldo en el mismo
disco que la base no sobrevive al incendio que motivó el respaldo.

---

## Lo que este respaldo NO incluye

- **Los archivos subidos por la interfaz.** No los hay en esta versión: la
  aplicación no los usa todavía.
- **La configuración.** `.env` no está en el respaldo. Sin él, la base se
  recupera pero la aplicación no arranca. Se guarda aparte, en el gestor de
  secretos del equipo.
- **Las imágenes de contenedor.** Se reconstruyen desde el código.

---

## Comprobación

Un respaldo que nunca se restauró **no es un respaldo**. Hay que probarlo, en
algún momento, aunque sea sobre una base de prueba:

1. `sha256sum -c` sobre el manifiesto.
2. Restaurar sin `--si` y confirmar que **se niega**.
3. Restaurar con `--si` sobre una base vacía.
4. `ferreteria:verificar-aislamiento` después de restaurar.

Si los cuatro pasos no se hicieron nunca, no se sabe si el respaldo sirve.