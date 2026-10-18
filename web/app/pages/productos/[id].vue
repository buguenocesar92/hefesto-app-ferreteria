<template>
  <section>
    <p class="volver"><NuxtLink to="/productos">Volver al catálogo</NuxtLink></p>

    <h1>{{ detalle?.producto.nombre ?? 'Producto' }}</h1>
    <p class="intro">
      Código {{ detalle?.producto.sku }}. Unidad base:
      <strong>{{ detalle?.producto.unidad_base?.nombre ?? '—' }}</strong>.
      Mínimo de reposición:
      <strong class="numero">{{ detalle?.producto.stock_minimo ?? '0' }}</strong>
      en {{ detalle?.producto.unidad_base?.codigo ?? '—' }}.
    </p>

    <p v-if="!detalle" class="aviso">
      Este producto no tiene variantes cargadas. Un producto sin variantes no
      se puede vender: el precio y el stock son de la variante.
    </p>

    <!--
        LA MATRIZ DE VARIANTES
        Se muestra como tabla y no como un formulario con celdas: cada fila
        es una variante real, con su precio y su stock. Si fuera una tabla de
        grados, habria que decidir en que celda cruzan el precio y el stock, y
        esa decisión es justamente la que produce inventarios que no cuadran.
    -->
    <h2>Matriz de variantes</h2>

    <p v-if="detalle" class="intro">
      Ejes que usa esta línea de producto:
      <template v-for="(titulo, campo) in detalle.ejes" :key="campo">
        {{ titulo }}
      </template>
      .
    </p>

    <table v-if="detalle && detalle.variantes.length > 0" class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Variante</th>
          <th v-for="titulo in detalle.ejes" :key="titulo">{{ titulo }}</th>
          <th>Presentación</th>
          <th>Unidad de venta</th>
          <th class="numero">Precio</th>
          <th class="numero">Disponible</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="variante in detalle.variantes" :key="variante.id">
          <td>{{ variante.sku }}</td>
          <td>{{ variante.nombre }}</td>
          <td v-for="campo in Object.keys(detalle.ejes)" :key="campo">
            {{ valorDeEje(variante, campo) }}
          </td>
          <td>
            {{ variante.contenido }} {{ variante.unidad_contenido.codigo }}
          </td>
          <td>{{ variante.unidad_venta.codigo }}</td>
          <td class="numero">{{ variante.precio_venta }}</td>
          <td class="numero">{{ disponibleDe(variante.id) ?? '—' }}</td>
        </tr>
      </tbody>
    </table>

    <h2>Conversión de unidades</h2>
    <p class="intro">
      Convierte una cantidad entre dos unidades del mismo producto. La
      conversión sale del factor declarado de cada unidad, no de una cuenta
      escrita en el código.
    </p>

    <form class="conversor" @submit.prevent="convertir">
      <label class="campo">
        <span>Cantidad</span>
        <input v-model="cantidad" inputmode="decimal" placeholder="12,5" />
      </label>

      <label class="campo">
        <span>Desde</span>
        <select v-model="desdeUnidad">
          <option v-for="unidad in unidades" :key="unidad.id" :value="unidad.id">
            {{ unidad.nombre }} ({{ unidad.codigo }})
          </option>
        </select>
      </label>

      <label class="campo">
        <span>Hacia</span>
        <select v-model="haciaUnidad">
          <option v-for="unidad in unidades" :key="unidad.id" :value="unidad.id">
            {{ unidad.nombre }} ({{ unidad.codigo }})
          </option>
        </select>
      </label>

      <button class="boton boton--principal" type="submit">Convertir</button>
    </form>

    <p v-if="error" class="error">{{ error }}</p>

    <p v-if="resultado" class="resultado">
      <strong class="numero">{{ resultado.cantidad }}</strong>
      {{ resultado.desde.nombre }}
      son
      <strong class="numero">{{ resultado.resultado_mostrado }}</strong>
      {{ resultado.hasta.nombre }}.
      <span class="pie">
        La unidad base del producto es {{ resultado.unidad_base.nombre }}.
      </span>
    </p>
  </section>
</template>

<script setup lang="ts">
import type { ProductoDetalle, SaldoStock, UnidadMedida } from '~/tipos/api';

definePageMeta({
  layout: 'default',
  middleware: 'auth',
});

const ruta = useRoute();
const productoId = String(ruta.params.id);

useHead({ title: 'Variantes · Gestión de Ferretería' });

const detalle = ref<ProductoDetalle | null>(null);
const saldos = ref<SaldoStock[]>([]);
const unidades = ref<UnidadMedida[]>([]);
const error = ref<string | null>(null);

const cantidad = ref('1');
const desdeUnidad = ref('');
const haciaUnidad = ref('');
const resultado = ref<{
  cantidad: string;
  desde: { nombre: string };
  hasta: { nombre: string };
  resultado_mostrado: string;
  unidad_base: { nombre: string };
} | null>(null);

async function cargar(): Promise<void> {
  const [ficha, stock, listaUnidades] = await Promise.all([
    $fetch<ProductoDetalle>(`/api/v1/productos/${productoId}`),
    $fetch<{ data: SaldoStock[] }>('/api/v1/stock', {
      query: { producto_id: productoId, por_pagina: 200 },
    }),
    $fetch<{ unidades: UnidadMedida[] }>('/api/v1/unidades'),
  ]);

  detalle.value = ficha;
  saldos.value = stock.data;
  unidades.value = listaUnidades.unidades;

  // Por omisión se convierte hacia la unidad base del producto, que es la
  // pregunta que más se hace: "esto son cuantos kilos".
  desdeUnidad.value = unidades.value.find((u) => u.es_base)?.id ?? '';
  haciaUnidad.value = desdeUnidad.value;
}

/**
 * El disponible de una variante, en la unidad en que se cuenta.
 *
 * Se muestra en la misma tabla que el precio para que quede a la vista que
 * el precio y el stock son de la misma fila.
 */
function disponibleDe(varianteId: string): string | null {
  const saldo = saldos.value.find((item) => item.variante_id === varianteId);

  if (!saldo) {
    return null;
  }

  return `${saldo.disponible} ${saldo.unidad ?? ''}`.trim();
}

function valorDeEje(variante: Record<string, unknown>, campo: string): string {
  const valor = variante[campo];

  return valor === null || valor === undefined || valor === '' ? '—' : String(valor);
}

async function convertir(): Promise<void> {
  resultado.value = null;
  error.value = null;

  try {
    resultado.value = await $fetch(`/api/v1/productos/${productoId}/convertir`, {
      query: {
        cantidad: cantidad.value,
        desde_unidad_id: desdeUnidad.value,
        hasta_unidad_id: haciaUnidad.value,
      },
    });
  } catch (fallo) {
    // La API responde 422 con `unidades_incompatibles` cuando las unidades
    // no son de la misma familia. El mensaje explica por que no hay
    // conversión, en vez de devolver un número cualquiera.
    error.value = mensajeDeError(fallo);
  }
}

function mensajeDeError(fallo: unknown): string {
  const respuesta = fallo as { data?: { mensaje?: string } };

  return respuesta?.data?.mensaje ?? 'No se pudo convertir la cantidad.';
}

await cargar();
</script>

<style scoped>
.volver,
.intro,
.pie {
  color: #627d98;
}

.conversor {
  display: flex;
  gap: 1rem;
  align-items: flex-end;
  flex-wrap: wrap;
  margin: 1rem 0;
}

.campo {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.85rem;
  font-weight: 600;
}

.resultado {
  padding: 0.75rem 1rem;
  border: 1px solid #e4e7eb;
  border-radius: 0.375rem;
  background: #f8fafc;
}
</style>