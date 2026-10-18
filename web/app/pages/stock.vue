<template>
  <section>
    <h1>Stock</h1>
    <p class="intro">
      El saldo no se edita: se mueve. Toda entrada, venta, traslado, merma o
      ajuste queda registrado como movimiento, con su motivo y su persona.
    </p>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="filtros">
      <label class="campo">
        <span>Sucursal</span>
        <select v-model="sucursalId" @change="cargar">
          <option value="">Todas</option>
          <option v-for="sucursal in sucursales" :key="sucursal.id" :value="sucursal.id">
            {{ sucursal.codigo }}
          </option>
        </select>
      </label>

      <label class="campo">
        <span>Buscar</span>
        <input v-model="termino" type="search" placeholder="Código o nombre" @input="cargar" />
      </label>
    </div>

    <h2>Saldos</h2>

    <table class="tabla">
      <thead>
        <tr>
          <th>Variante</th>
          <th>Sucursal</th>
          <th class="numero">Cantidad</th>
          <th class="numero">Disponible</th>
          <th>Unidad</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="saldo in saldos" :key="saldo.id">
          <td>
            {{ saldo.variante }}
            <span class="pie">{{ saldo.sku }}</span>
          </td>
          <td>{{ saldo.sucursal }}</td>
          <td class="numero">{{ saldo.cantidad }}</td>
          <td class="numero">{{ saldo.disponible }}</td>
          <td>{{ saldo.unidad }}</td>
        </tr>
        <tr v-if="saldos.length === 0">
          <td colspan="5" class="vacio">No hay saldos que coincidan.</td>
        </tr>
      </tbody>
    </table>

    <h2>Bajo el mínimo</h2>
    <p class="intro">
      El mínimo vive en el producto y está expresado en su unidad base; el
      disponible vive en la variante y está en su unidad de venta. La
      comparación se hace en la base, no a ojo.
    </p>

    <table class="tabla">
      <thead>
        <tr>
          <th>Sucursal</th>
          <th>Variante</th>
          <th class="numero">Disponible</th>
          <th class="numero">Mínimo</th>
          <th>Unidades</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="fila in bajoMinimo" :key="`${fila.sucursal_id}-${fila.variante_id}`">
          <td>{{ fila.sucursal }}</td>
          <td>
            {{ fila.variante }}
            <span class="pie">{{ fila.sku }}</span>
          </td>
          <td class="numero">{{ fila.disponible }} {{ fila.unidad }}</td>
          <td class="numero">{{ fila.stock_minimo }} {{ fila.unidad_minima }}</td>
          <td>
            <NuxtLink to="/reportes">Ver reporte</NuxtLink>
          </td>
        </tr>
        <tr v-if="bajoMinimo.length === 0">
          <td colspan="5" class="vacio">Nada bajo el mínimo en este momento.</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<script setup lang="ts">
import type { SaldoStock, Sucursal } from '~/tipos/api';

definePageMeta({
  layout: 'default',
  middleware: 'auth',
});

useHead({ title: 'Stock · Gestión de Ferretería' });

interface FilaBajoMinimo {
  variante_id: string;
  sucursal_id: string;
  sku: string;
  variante: string;
  sucursal: string;
  disponible: string;
  unidad: string;
  stock_minimo: string;
  unidad_minima: string;
}

const saldos = ref<SaldoStock[]>([]);
const bajoMinimo = ref<FilaBajoMinimo[]>([]);
const sucursales = ref<Sucursal[]>([]);
const sucursalId = ref('');
const termino = ref('');
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  try {
    const [stock, minimos, lista] = await Promise.all([
      $fetch<{ data: SaldoStock[] }>('/api/v1/stock', {
        query: {
          sucursal_id: sucursalId.value || undefined,
          buscar: termino.value || undefined,
          por_pagina: 200,
        },
      }),
      $fetch<{ bajo_minimo: FilaBajoMinimo[] }>('/api/v1/stock/bajo-minimo', {
        query: { sucursal_id: sucursalId.value || undefined },
      }),
      $fetch<{ sucursales: Sucursal[] }>('/api/v1/sucursales'),
    ]);

    saldos.value = stock.data;
    bajoMinimo.value = minimos.bajo_minimo;
    sucursales.value = lista.sucursales;
    error.value = null;
  } catch (fallo) {
    const respuesta = fallo as { data?: { mensaje?: string } };
    error.value = respuesta?.data?.mensaje ?? 'No se pudo cargar el stock.';
  }
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