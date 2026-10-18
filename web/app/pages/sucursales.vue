<template>
  <section>
    <h1>Sucursales</h1>
    <p class="intro">
      Cada sucursal tiene su propio stock y su propio corte de caja. El código
      es el que va en la etiqueta del cajón y en el papel de las
      transferencias.
    </p>

    <p v-if="error" class="error">{{ error }}</p>

    <table class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Nombre</th>
          <th>Dirección</th>
          <th>Teléfono</th>
          <th>Activa</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="sucursal in sucursales" :key="sucursal.id">
          <td>{{ sucursal.codigo }}</td>
          <td>{{ sucursal.nombre }}</td>
          <td>{{ sucursal.direccion ?? '—' }}</td>
          <td>{{ sucursal.telefono ?? '—' }}</td>
          <td>{{ sucursal.activo ? 'Sí' : 'No' }}</td>
        </tr>
        <tr v-if="sucursales.length === 0">
          <td colspan="5" class="vacio">No hay sucursales registradas.</td>
        </tr>
      </tbody>
    </table>

    <h2>Stock por sucursal</h2>
    <p class="intro">
      El saldo se cuenta en la unidad de venta de la variante. El disponible es
      lo que se puede vender: la cantidad menos lo comprometido.
    </p>

    <table class="tabla">
      <thead>
        <tr>
          <th>Sucursal</th>
          <th>Variante</th>
          <th class="numero">Cantidad</th>
          <th class="numero">Comprometido</th>
          <th class="numero">Disponible</th>
          <th>Unidad</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="saldo in saldos" :key="saldo.id">
          <td>{{ saldo.sucursal }}</td>
          <td>
            {{ saldo.variante }}
            <span class="pie">{{ saldo.sku }}</span>
          </td>
          <td class="numero">{{ saldo.cantidad }}</td>
          <td class="numero">{{ saldo.cantidad_reservada }}</td>
          <td class="numero">{{ saldo.disponible }}</td>
          <td>{{ saldo.unidad }}</td>
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

useHead({ title: 'Sucursales · Gestión de Ferretería' });

const sucursales = ref<Sucursal[]>([]);
const saldos = ref<SaldoStock[]>([]);
const error = ref<string | null>(null);

onMounted(async () => {
  try {
    const [lista, stock] = await Promise.all([
      $fetch<{ sucursales: Sucursal[] }>('/api/v1/sucursales'),
      $fetch<{ data: SaldoStock[] }>('/api/v1/stock', { query: { por_pagina: 200 } }),
    ]);

    sucursales.value = lista.sucursales;
    saldos.value = stock.data;
  } catch (fallo) {
    const respuesta = fallo as { data?: { mensaje?: string } };
    error.value = respuesta?.data?.mensaje ?? 'No se pudieron cargar las sucursales.';
  }
});
</script>

<style scoped>
.intro,
.pie,
.vacio {
  color: #627d98;
}
</style>