<template>
  <section>
    <h1>Productos y variantes</h1>
    <p class="intro">
      Un producto es el artículo base. El precio y el stock son de sus
      variantes, nunca del producto.
    </p>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="filtros">
      <label class="campo">
        <span>Buscar</span>
        <input v-model="termino" type="search" placeholder="Código o nombre" @input="buscar" />
      </label>

      <label class="campo">
        <span>Página</span>
        <select v-model.number="pagina" @change="cargar">
          <option :value="1">1</option>
          <option :value="2">2</option>
          <option :value="3">3</option>
        </select>
      </label>
    </div>

    <table class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Producto</th>
          <th>Categoría</th>
          <th>Unidad base</th>
          <th class="numero">Mínimo</th>
          <th class="numero">Variantes</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="producto in productos" :key="producto.id">
          <td>{{ producto.sku }}</td>
          <td>{{ producto.nombre }}</td>
          <td>{{ producto.categoria?.nombre ?? '—' }}</td>
          <td>{{ producto.unidad_base?.codigo ?? '—' }}</td>
          <td class="numero">{{ producto.stock_minimo }}</td>
          <td class="numero">{{ producto.variantes ?? 0 }}</td>
          <td>
            <NuxtLink class="boton boton--discreto" :to="`/productos/${producto.id}`">
              Ver matriz
            </NuxtLink>
          </td>
        </tr>
        <tr v-if="productos.length === 0">
          <td colspan="7" class="vacio">No hay productos que coincidan con la búsqueda.</td>
        </tr>
      </tbody>
    </table>

    <p v-if="total > 0" class="pie">Mostrando {{ productos.length }} de {{ total }} productos.</p>
  </section>
</template>

<script setup lang="ts">
import type { Paginado, Producto } from '~/tipos/api';

definePageMeta({
  layout: 'default',
  middleware: 'auth',
});

useHead({ title: 'Productos · Gestión de Ferretería' });

const productos = ref<Producto[]>([]);
const total = ref(0);
const pagina = ref(1);
const termino = ref('');
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  try {
    const respuesta = await $fetch<Paginado<Producto>>('/api/v1/productos', {
      query: {
        pagina: pagina.value,
        por_pagina: 25,
        buscar: termino.value || undefined,
      },
    });

    productos.value = respuesta.data;
    total.value = respuesta.total;
    error.value = null;
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  }
}

/**
 * El backend NO acepta el precio en la peticion.
 *
 * Escribir un precio desde la pantalla permitiria cobrar mil pesos lo que
 * vale cien, y el corte de caja lo mostraria como si estuviera bien. Esa
 * clase de error se descubre al mes. Ver docs/09-API.md.
 */
function mensajeDeError(fallo: unknown): string {
  const respuesta = fallo as { data?: { mensaje?: string } };

  return respuesta?.data?.mensaje ?? 'No se pudo cargar el catálogo.';
}

async function buscar(): Promise<void> {
  pagina.value = 1;
  await cargar();
}

await cargar();
</script>

<style scoped>
.intro,
.pie,
.vacio {
  color: #627d98;
}

.filtros {
  display: flex;
  gap: 1rem;
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
</style>