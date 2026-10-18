# 00. Índice de la documentación

Documentación de la **Gestión de Ferretería y Local de Materiales de
Construcción** de Hefesto Studio.

Se lee en orden la primera vez. Después se entra por el problema que se tenga
frente.

---

## Los documentos

| # | Documento | Qué responde |
|---|---|---|
| 01 | [ARQUITECTURA](01-ARQUITECTURA.md) | De qué piezas se arma el sistema y por qué el navegador no habla directo con la API |
| 02 | [MODELO DE DATOS](02-MODELO-DE-DATOS.md) | Qué tabla representa qué, por qué el precio es de la variante y cómo se convierte entre unidades |
| 03 | [MULTITENANCY Y ROLES](03-MULTITENANCY-Y-ROLES.md) | Cómo se aíslan las ferreterías en la base y qué puede hacer cada rol |
| 04 | [INSTALACIÓN](04-INSTALACION.md) | Cómo se levanta el sistema en desarrollo y en producción |
| 05 | [CONFIGURACIÓN](05-CONFIGURACION.md) | Qué significa cada variable de entorno y cuál es su valor por omisión |
| 06 | [PRIMER PRODUCTO](06-PRIMER-PRODUCTO.md) | El recorrido completo: de la unidad base a la venta, paso por paso |
| 07 | [SEGURIDAD](07-SEGURIDAD.md) | Autenticación, CSRF, cookies, puerto de error y superficie expuesta |
| 08 | [RESPALDOS](08-RESPALDOS.md) | Cómo respaldar, cómo restaurar y qué reglas no se negocian |
| 09 | [API](09-API.md) | Cada endpoint, cada dato y cada código de error |
| 10 | [PRUEBAS Y ESTADO](10-PRUEBAS-Y-ESTADO.md) | Qué se verificó, qué no se ejecutó y qué queda pendiente |

---

## Por dónde empezar según el problema que se tenga

**"El inventario no me cuadra"** → [02](02-MODELO-DE-DATOS.md), sección
«Stock por sucursal» y «Movimientos». Después
[09](09-API.md#stock-por-sucursal) para ver cómo consultar el historial.

**"El cliente pregunta a qué precio vendo esto"** → [02](02-MODELO-DE-DATOS.md),
sección «Matriz de variantes», y [01](01-ARQUITECTURA.md) para entender por
qué el precio no está en el producto.

**"La caja quedó descuadrada"** → [02](02-MODELO-DE-DATOS.md), sección «Corte
de caja», y la pantalla de reportes para el histórico de diferencias.

**"No entiendo por qué no puedo convertir kilos a metros"** →
[02](02-MODELO-DE-DATOS.md), sección «Unidades de medida».

**"Quiero instalar esto"** → [04](04-INSTALACION.md), después
[05](05-CONFIGURACION.md).

**"Quiero cambiar algo y romperlo"** → [03](03-MULTITENANCY-Y-ROLES.md) y
[07](07-SEGURIDAD.md). El aislamiento no se puede relajar sin entender qué
protege.

---

## Reglas de la casa que se aplican a toda la documentación

1. **Nada se presenta como probado que no se haya ejecutado.**
   [10](10-PRUEBAS-Y-ESTADO.md) es el documento que lleva la cuenta de eso, y
   se actualiza con cada cambio.

2. **El aislamiento entre ferreterías se reusa, no se reinventa.**
   El patrón está escrito y aplicado en toda la instalación de Hefesto Studio.
   Si se necesita cambiarlo, se cambia en todas las aplicaciones, no en una.

3. **En producción solo el proxy pública puertos.** PostgreSQL, Redis y la
   interfaz del proveedor de identidad no son accesibles desde la red.

4. **Un permiso insuficiente se responde 404, no 403.** Confirmar con un
   código de error que un recurso existe en otra empresa ya es filtrar
   información.

5. **Los respaldos nunca hacen `DROP` y nunca sobrescriben sin que alguien lo
   confirme.** Ver [08](08-RESPALDOS.md).

---

## Un aviso sobre los precios

Esta aplicación **no fija precios ni márgenes**. Los registra.

Los precios que aparecen en los datos de ejemplo son redondos y
deliberadamente evidentes, para que nadie los tome por los precios de una
ferretería real. Los márgenes y las condiciones los define cada ferretería, en
sus listas de precios.

Ver [06](06-PRIMER-PRODUCTO.md), sección «La lista de mayorista».