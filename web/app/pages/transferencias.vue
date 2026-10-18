<template>
  <section>
    <h1>Transferencias entre sucursales</h1>
    <p class="intro">
      Mover mercadería es un documento, no un ajuste: queda el folio, quién
      despachó, quién recibió y qué se recibió de lo que se mandó.
    </p>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje" class="aviso">{{ mensaje }}</p>

    <h2>Documentos</h2>

    <table class="tabla">
      <thead>
        <tr>
          <th>Folio</th>
          <th>Origen</th>
          <th>Destino</th>
          <th>Estado</th>
          <th class="numero">Renglones</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="transferencia in transferencias" :key="transferencia.id">
          <td>{{ transferencia.folio }}</td>
          <td>{{ transferencia.origen.codigo }}</td>
          <td>{{ transferencia.destino.codigo }}</td>
          <td>{{ estadoEnTexto(transferencia.estado) }}</td>
          <td class="numero">{{ transferencia.items.length }}</td>
          <td class="acciones">
            <button
              v-if="transferencia.estado === 'borrador'"
              class="boton boton--discreto"
              type="button"
              @click="despachar(transferencia)"
            >
              Despachar
            </button>
            <button
              v-if="transferencia.estado === 'despachada'"
              class="boton boton--principal"
              type="button"
              @click="recibir(transferencia)"
            >
              Recibir todo
            </button>
            <button
              v-if="puedeAnular && transferencia.estado !== 'anulada' && transferencia.estado !== 'recibida'"
              class="boton boton--peligro"
              type="button"
              @click="anular(transferencia)"
            >
              Anular
            </button>
          </td>
        </tr>
        <tr v-if="transferencias.length === 0">
          <td colspan="6" class="vacio">No hay transferencias registradas.</td>
        </tr>
      </tbody>
    </table>

    <h2>Nueva transferencia</h2>

    <form class="formulario" @submit.prevent="crear">
      <label class="campo">
        <span>Sucursal de origen</span>
        <select v-model="nueva.origen">
          <option v-for="sucursal in sucursales" :key="sucursal.id" :value="sucursal.id">
            {{ sucursal.codigo }} - {{ sucursal.nombre }}
          </option>
        </select>
      </label>

      <label class="campo">
        <span>Sucursal de destino</span>
        <select v-model="nueva.destino">
          <option v-for="sucursal in sucursales" :key="sucursal.id" :value="sucursal.id">
            {{ sucursal.codigo }} - {{ sucursal.nombre }}
          </option>
        </select>
      </label>

      <label class="campo campo--ancho">
        <span>Observaciones</span>
        <input v-model="nueva.observaciones" type="text" maxlength="500" />
      </label>

      <fieldset class="items">
        <legend>Renglones</legend>

        <div v-for="(item, indice) in nueva.items" :key="indice" class="item">
          <select v-model="item.variante_id">
            <option v-for="saldo in saldos" :key="saldo.variante_id" :value="saldo.variante_id">
              {{ saldo.variante }} ({{ saldo.sku }})
            </option>
          </select>

          <input
            v-model="item.cantidad"
            inputmode="decimal"
            placeholder="Cantidad"
            aria-label="Cantidad"
          />

          <button class="boton boton--discreto" type="button" @click="quitarRenglon(indice)">
            Quitar
          </button>
        </div>

        <button class="boton boton--discreto" type="button" @click="agregarRenglon">
          Agregar renglón
        </button>
      </fieldset>

      <button class="boton boton--principal" type="submit" :disabled="enviando">
        {{ enviando ? 'Guardando...' : 'Guardar en borrador' }}
      </button>
    </form>
  </section>
</template>

<script setup lang="ts">
import type { SaldoStock, Sucursal, Transferencia } from '~/tipos/api';

definePageMeta({
  layout: 'default',
  middleware: 'auth',
});

useHead({ title: 'Transferencias · Gestión de Ferretería' });

const { puedeAnular } = useSesion();

const transferencias = ref<Transferencia[]>([]);
const sucursales = ref<Sucursal[]>([]);
const saldos = ref<SaldoStock[]>([]);
const error = ref<string | null>(null);
const mensaje = ref<string | null>(null);
const enviando = ref(false);

const nueva = reactive({
  origen: '',
  destino: '',
  observaciones: '',
  items: [{ variante_id: '', cantidad: '' }],
});

async function cargar(): Promise<void> {
  try {
    const [lista, tiendas, stock] = await Promise.all([
      $fetch<{ data: Transferencia[] }>('/api/v1/transferencias', { query: { por_pagina: 50 } }),
      $fetch<{ sucursales: Sucursal[] }>('/api/v1/sucursales'),
      $fetch<{ data: SaldoStock[] }>('/api/v1/stock', { query: { por_pagina: 200 } }),
    ]);

    transferencias.value = lista.data;
    sucursales.value = tiendas.sucursales;
    saldos.value = stock.data;

    if (!nueva.origen && tiendas.sucursales.length > 0) {
      nueva.origen = tiendas.sucursales[0]!.id;
      nueva.destino = tiendas.sucursales[1]?.id ?? tiendas.sucursales[0]!.id;
    }
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  }
}

async function crear(): Promise<void> {
  enviando.value = true;
  error.value = null;
  mensaje.value = null;

  try {
    const creada = await $fetch<Transferencia>('/api/v1/transferencias', {
      method: 'POST',
      body: {
        sucursal_origen_id: nueva.origen,
        sucursal_destino_id: nueva.destino,
        observaciones: nueva.observaciones || null,
        items: nueva.items
          .filter((item) => item.variante_id && item.cantidad)
          .map((item) => ({
            variante_id: item.variante_id,
            cantidad: String(item.cantidad).replace(',', '.'),
          })),
      },
    });

    mensaje.value = `Transferencia ${creada.folio} guardada en borrador.`;

    nueva.items = [{ variante_id: '', cantidad: '' }];
    nueva.observaciones = '';

    await cargar();
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  } finally {
    enviando.value = false;
  }
}

/**
 * Despachar descuenta el stock del origen. La mercadería queda EN CAMINO: no
 * se suma en el destino hasta que alguien la recibe.
 */
async function despachar(transferencia: Transferencia): Promise<void> {
  await actuar(
    () =>
      $fetch<Transferencia>(`/api/v1/transferencias/${transferencia.id}/despachar`, {
        method: 'POST',
      }),
    `Transferencia ${transferencia.folio} despachada. El stock salió de ${transferencia.origen.codigo}.`,
  );
}

/**
 * Recibir suma lo que llegó en el destino.
 *
 * Si se recibe incompleto, el movimiento queda escrito con lo que se recibió
 * de lo que se mandó, para que la sucursal de origen lo vea.
 */
async function recibir(transferencia: Transferencia): Promise<void> {
  await actuar(
    () =>
      $fetch<Transferencia>(`/api/v1/transferencias/${transferencia.id}/recibir`, {
        method: 'POST',
        body: {},
      }),
    `Transferencia ${transferencia.folio} recibida en ${transferencia.destino.codigo}.`,
  );
}

async function anular(transferencia: Transferencia): Promise<void> {
  const motivo = window.prompt(
    'Explique por qué se anula la transferencia. El motivo queda escrito en el movimiento.',
  );

  if (!motivo) {
    return;
  }

  await actuar(
    () =>
      $fetch<Transferencia>(`/api/v1/transferencias/${transferencia.id}/anular`, {
        method: 'POST',
        body: { motivo },
      }),
    `Transferencia ${transferencia.folio} anulada.`,
  );
}

async function actuar(operacion: () => Promise<unknown>, exito: string): Promise<void> {
  error.value = null;
  mensaje.value = null;

  try {
    await operacion();

    mensaje.value = exito;

    await cargar();
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  }
}

function agregarRenglon(): void {
  nueva.items.push({ variante_id: '', cantidad: '' });
}

function quitarRenglon(indice: number): void {
  nueva.items.splice(indice, 1);

  if (nueva.items.length === 0) {
    nueva.items.push({ variante_id: '', cantidad: '' });
  }
}

function estadoEnTexto(estado: Transferencia['estado']): string {
  switch (estado) {
    case 'borrador':
      return 'Borrador';
    case 'despachada':
      return 'En camino';
    case 'recibida':
      return 'Recibida';
    case 'anulada':
      return 'Anulada';
    default:
      return estado;
  }
}

function mensajeDeError(fallo: unknown): string {
  const respuesta = fallo as { data?: { mensaje?: string } };

  return respuesta?.data?.mensaje ?? 'No se pudo completar la operación.';
}

await cargar();
</script>

<style scoped>
.intro,
.vacio {
  color: #627d98;
}

.acciones {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.formulario {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  align-items: flex-start;
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

.items {
  width: 100%;
  border: 1px solid var(--color-linea);
  border-radius: 0.375rem;
  padding: 0.75rem 1rem;
}

.item {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
  flex-wrap: wrap;
}

.item select {
  flex: 2;
  min-width: 16rem;
}

.item input {
  flex: 1;
  min-width: 8rem;
}
</style>