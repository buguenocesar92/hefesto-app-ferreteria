<template>
  <section>
    <h1>Mostrador</h1>
    <p class="intro">
      Agregue los renglones, revise el total y cobre. El precio lo pone el
      sistema: la pantalla no lo escribe, para que un error de tipeo no se
      convierta en un cobro que el corte de caja muestra como correcto.
    </p>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje" class="aviso">{{ mensaje }}</p>

    <div class="filtros">
      <label class="campo">
        <span>Sucursal</span>
        <select v-model="sucursalId">
          <option v-for="sucursal in sucursales" :key="sucursal.id" :value="sucursal.id">
            {{ sucursal.codigo }} - {{ sucursal.nombre }}
          </option>
        </select>
      </label>

      <label class="campo">
        <span>Caja abierta</span>
        <select v-model="cajaId">
          <option value="">Sin caja</option>
          <option v-for="caja in cajasAbiertas" :key="caja.id" :value="caja.id">
            {{ caja.folio }} (esperado {{ caja.monto_esperado ?? '—' }})
          </option>
        </select>
      </label>

      <label class="campo">
        <span>Lista de precios</span>
        <select v-model="listaId">
          <option value="">La de mostrador</option>
          <option v-for="lista in listas" :key="lista.id" :value="lista.id">
            {{ lista.nombre }}
          </option>
        </select>
      </label>
    </div>

    <form class="formulario" @submit.prevent="agregar">
      <label class="campo campo--ancho">
        <span>Código de barras o SKU de la variante</span>
        <input
          v-model="busqueda"
          type="search"
          placeholder="Escanee o escriba el código"
          @keyup.enter.prevent="buscarVariante"
        />
      </label>

      <p v-if="encontrada" class="aviso">
        {{ encontrada.nombre }} — {{ encontrada.precio_venta }}
        {{ encontrada.unidad_venta.codigo }}.
        <strong>Disponible:</strong>
        {{ disponibleDe(encontrada.id) ?? 'sin registro de stock' }}
        {{ encontrada.unidad_venta.codigo }}
      </p>

      <div class="fila-cobro">
        <label class="campo">
          <span>Cantidad</span>
          <input v-model="cantidad" inputmode="decimal" placeholder="1" />
        </label>

        <button class="boton boton--principal" type="submit">Agregar</button>
      </div>
    </form>

    <h2>Renglones</h2>

    <table v-if="renglones.length > 0" class="tabla">
      <thead>
        <tr>
          <th>Variante</th>
          <th class="numero">Cantidad</th>
          <th>Unidad</th>
          <th class="numero">Precio</th>
          <th class="numero">Origen</th>
          <th class="numero">Subtotal</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(renglón, indice) in renglones" :key="renglon.variante_id">
          <td>
            {{ renglon.nombre }}
            <span class="pie">{{ renglon.sku }}</span>
          </td>
          <td class="numero">{{ renglon.cantidad }}</td>
          <td>{{ renglon.unidad }}</td>
          <td class="numero">{{ renglon.precio_unitario }}</td>
          <td class="numero">{{ renglon.precio_desde === 'lista' ? 'Lista' : 'Mostrador' }}</td>
          <td class="numero">{{ renglon.subtotal }}</td>
          <td>
            <button class="boton boton--discreto" type="button" @click="quitar(indice)">
              Quitar
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <p v-else class="vacio">Todavía no hay renglones en esta venta.</p>

    <div class="totales">
      <p>
        Subtotal:
        <strong class="numero">{{ cotizacion?.subtotal ?? '0.00' }}</strong>
      </p>
      <p class="total">
        Total:
        <strong class="numero">{{ cotizacion?.total ?? '0.00' }}</strong>
      </p>
    </div>

    <div class="fila-cobro">
      <label class="campo">
        <span>Cliente (opcional)</span>
        <input v-model="clienteNombre" type="text" maxlength="160" />
      </label>

      <button
        class="boton boton--principal"
        type="button"
        :disabled="renglones.length === 0 || cobrando"
        @click="cobrar"
      >
        {{ cobrando ? 'Cobrando...' : 'Cobrar' }}
      </button>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { Caja, Cotizacion, ListaPrecio, SaldoStock, Sucursal, Variante } from '~/tipos/api';

definePageMeta({
  layout: 'default',
  middleware: 'auth',
});

useHead({ title: 'Mostrador · Gestión de Ferretería' });

const sucursales = ref<Sucursal[]>([]);
const listas = ref<ListaPrecio[]>([]);
const cajasAbiertas = ref<Caja[]>([]);
const saldos = ref<SaldoStock[]>([]);

const sucursalId = ref('');
const cajaId = ref('');
const listaId = ref('');
const busqueda = ref('');
const cantidad = ref('1');
const encontrada = ref<Variante | null>(null);
const clienteNombre = ref('');
const cotizacion = ref<Cotizacion | null>(null);
const renglones = ref<Array<{ variante_id: string; cantidad: string }>>([]);
const error = ref<string | null>(null);
const mensaje = ref<string | null>(null);
const cobrando = ref(false);

async function cargarContexto(): Promise<void> {
  const [tiendas, precios, saldosRespuesta] = await Promise.all([
    $fetch<{ sucursales: Sucursal[] }>('/api/v1/sucursales'),
    $fetch<{ listas: ListaPrecio[] }>('/api/v1/reportes/listas').catch(() => ({ listas: [] })),
    $fetch<{ data: SaldoStock[] }>('/api/v1/stock', { query: { por_pagina: 200 } }),
  ]);

  sucursales.value = tiendas.sucursales;
  listas.value = precios.listas;
  saldos.value = saldos_respuesta.data;

  if (!sucursalId.value && tiendas.sucursales.length > 0) {
    sucursalId.value = tiendas.sucursales[0]!.id;
  }

  await cargarCajas();
}

async function cargarCajas(): Promise<void> {
  if (!sucursalId.value) {
    cajasAbiertas.value = [];

    return;
  }

  const respuesta = await $fetch<{ data: Caja[] }>('/api/v1/cajas', {
    query: { sucursal: sucursalId.value, estado: 'abierta' },
  });

  cajasAbiertas.value = respuesta.data;

  if (respuesta.data.length > 0 && !cajaId.value) {
    cajaId.value = respuesta.data[0]!.id;
  }
}

/**
 * Busca la variante por código de barras.
 *
 * La API responde 404 cuando el código no existe. No se distingue de una
 * peticion sin permiso, y eso es a propósito.
 */
async function buscarVariante(): Promise<void> {
  error.value = null;
  encontrada.value = null;

  try {
    const respuesta = await $fetch<{ variante: Variante }>(
      `/api/v1/variantes/barras/${encodeURIComponent(busqueda.value.trim())}`,
    );

    encontrada.value = respuesta.variante;
  } catch {
    // Un código desconocido no es un error que se quiera mostrar con
    // alarido: en un mostrador se escanea mal seguido.
    encontrada.value = null;
  }
}

function agregar(): void {
  error.value = null;

  if (!encontrada.value) {
    error.value = 'Escanee o busque una variante antes de agregar.';

    return;
  }

  const yaEsta = renglones.value.findIndex(
    (renglon) => renglon.variante_id === encontrada.value!.id,
  );

  if (yaEsta >= 0) {
    renglones.value[yaEsta]!.cantidad = String(
      Number(renglones.value[yaEsta]!.cantidad) + (Number(cantidad.value) || 1),
    );
  } else {
    renglones.value.push({
      variante_id: encontrada.value.id,
      cantidad: String(cantidad.value).replace(',', '.'),
    });
  }

  busqueda.value = '';
  cantidad.value = '1';
  encontrada.value = null;

  void recalcular();
}

/**
 * Pide el total al servidor en vez de sumarlo en el navegador.
 *
 * La API calcula el subtotal con aritmética exacta de decimales. Si esta capa
 * sumara con `Number`, la pantalla mostraría un total que no es el que se
 * cobra, y esa diferencia apareceria como una diferencia de caja.
 */
async function recalcular(): Promise<void> {
  if (renglones.value.length === 0) {
    cotizacion.value = null;

    return;
  }

  try {
    cotizacion.value = await $fetch<Cotizacion>('/api/v1/ventas/cotizar', {
      method: 'POST',
      body: cuerpoDeVenta(),
    });
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  }
}

async function cobrar(): Promise<void> {
  cobrando.value = true;
  error.value = null;
  mensaje.value = null;

  try {
    const venta = await $fetch<{ folio: string; total: string }>('/api/v1/ventas', {
      method: 'POST',
      body: {
        ...cuerpoDeVenta(),
        caja_id: cajaId.value || null,
        cliente_nombre: clienteNombre.value || null,
      },
    });

    mensaje.value = `Venta ${venta.folio} registrada por ${venta.total}.`;

    renglones.value = [];
    clienteNombre.value = '';
    cotizacion.value = null;
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  } finally {
    cobrando.value = false;
  }
}

function cuerpoDeVenta() {
  return {
    sucursal_id: sucursalId.value,
    lista_precios_id: listaId.value || null,
    items: renglones.value,
  };
}

function quitar(indice: number): void {
  renglones.value.splice(indice, 1);

  void recalcular();
}

function disponibleDe(varianteId: string): string | null {
  const saldo = saldos.value.find((item) => item.variante_id === varianteId);

  return saldo ? saldo.disponible : null;
}

function mensajeDeError(fallo: unknown): string {
  const respuesta = fallo as { data?: { mensaje?: string; errors?: Record<string, string[]> } };

  if (respuesta?.data?.mensaje) {
    return respuesta.data.mensaje;
  }

  const errores = respuesta?.data?.errors;

  if (errores) {
    const primero = Object.values(errores)[0]?.[0];

    if (primero) {
      return primero;
    }
  }

  return 'No se pudo completar la venta.';
}

await cargarContexto();
</script>

<style scoped>
.intro,
.vacio,
.pie {
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

.campo--ancho {
  width: 100%;
}

.formulario {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.fila-cobro {
  display: flex;
  gap: 1rem;
  align-items: flex-end;
  flex-wrap: wrap;
  margin: 1rem 0;
}

.totales {
  text-align: right;
  border-top: 2px solid var(--color-linea);
  padding-top: 0.75rem;
  margin-top: 1rem;
}

.total {
  font-size: 1.2rem;
}
</style>