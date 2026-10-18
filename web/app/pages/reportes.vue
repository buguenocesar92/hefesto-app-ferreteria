<template>
  <section>
    <h1>Reportes</h1>
    <p class="intro">
      Cada reporte responde una pregunta que alguien tiene que responder:
      cuánto se vendió, qué hay que reponer, cuánto se perdió en el mostrador y
      qué diferencias hay que mirar.
    </p>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="pestanas">
      <button
        v-for="opcion in pestanas"
        :key="opcion.clave"
        type="button"
        class="boton boton--discreto"
        :class="{ activo: activa === opcion.clave }"
        @click="cambiar(opcion.clave)"
      >
        {{ opcion.titulo }}
      </button>
    </div>

    <!--
        VENTAS DEL DIA
    -->
    <section v-if="activa === 'ventas'">
      <h2>Ventas del día</h2>
      <p class="pie">Fecha: {{ ventas.fecha ?? '' }}</p>

      <p>
        Ventas registradas: <strong class="numero">{{ ventas.ventas ?? 0 }}</strong>
        por <strong class="numero">{{ ventas.total ?? '0.00' }}</strong>
      </p>

      <table class="tabla">
        <thead>
          <tr>
            <th>Sucursal</th>
            <th class="numero">Ventas</th>
            <th class="numero">Total</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="fila in ventas.por_sucursal ?? []" :key="fila.sucursal_id">
            <td>{{ fila.sucursal }}</td>
            <td class="numero">{{ fila.ventas }}</td>
            <td class="numero">{{ fila.total }}</td>
          </tr>
        </tbody>
      </table>

      <h2>Rotación</h2>
      <p class="pie">Qué se está llevando la gente, por número de unidades.</p>

      <table class="tabla">
        <thead>
          <tr>
            <th>Código</th>
            <th>Variante</th>
            <th class="numero">Unidades</th>
            <th class="numero">Monto</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="fila in rotacion" :key="fila.variante_id">
            <td>{{ fila.sku }}</td>
            <td>{{ fila.nombre }}</td>
            <td class="numero">{{ fila.unidades }}</td>
            <td class="numero">{{ fila.monto }}</td>
          </tr>
          <tr v-if="rotacion.length === 0">
            <td colspan="4" class="vacio">Sin ventas en el período consultado.</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!--
        DIFERENCIAS DE CAJA
        Este es el reporte del dueño. Trae el número completo con su signo.
    -->
    <section v-if="activa === 'caja'">
      <h2>Diferencias de caja</h2>
      <p class="pie">
        Del {{ diferencias.desde ?? '' }} al {{ diferencias.hasta ?? '' }}.
      </p>

      <p>
        Cortes cerrados: <strong class="numero">{{ diferencias.cortes ?? 0 }}</strong>,
        de los cuales
        <strong class="numero">
          {{ diferencias.cortes_que_requieren_revision ?? 0 }}
        </strong>
        requieren revisión.
      </p>

      <p>
        Diferencia acumulada:
        <strong class="numero" :class="claseDeDiferencia(diferencias.diferencia_total)">
          {{ diferencias.diferencia_total ?? '0.00' }}
        </strong>
      </p>

      <table class="tabla">
        <thead>
          <tr>
            <th>Sucursal</th>
            <th>Folio</th>
            <th>Cierre</th>
            <th class="numero">Declarado</th>
            <th class="numero">Sistema</th>
            <th class="numero">Diferencia</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="corte in diferencias.detalle ?? []" :key="corte.id">
            <td>{{ corte.sucursal }}</td>
            <td>{{ corte.folio }}</td>
            <td>{{ corte.fecha_cierre_at?.slice(0, 16).replace('T', ' ') ?? '' }}</td>
            <td class="numero">{{ corte.monto_declarado }}</td>
            <td class="numero">{{ corte.monto_sistema }}</td>
            <td class="numero" :class="claseDeDiferencia(corte.diferencia)">
              {{ corte.diferencia }}
            </td>
          </tr>
          <tr v-if="(diferencias.detalle ?? []).length === 0">
            <td colspan="6" class="vacio">No hay cortes cerrados en el período.</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!--
        REPOSICIÓN
    -->
    <section v-if="activa === 'reposicion'">
      <h2>Reposición</h2>
      <p>
        Variantes bajo el mínimo:
        <strong class="numero">{{ reposicion.bajo_minimo ?? 0 }}</strong>,
        con
        <strong class="numero">{{ reposicion.alertas_pendientes ?? 0 }}</strong>
        avisos pendientes.
      </p>

      <table class="tabla">
        <thead>
          <tr>
            <th>Sucursal</th>
            <th>Variante</th>
            <th class="numero">Disponible</th>
            <th class="numero">Mínimo</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="fila in reposicion.items ?? []" :key="`${fila.sucursal}-${fila.sku}`">
            <td>{{ fila.sucursal }}</td>
            <td>
              {{ fila.variante }}
              <span class="pie">{{ fila.sku }}</span>
            </td>
            <td class="numero">{{ fila.disponible }} {{ fila.unidad }}</td>
            <td class="numero">{{ fila.minimo }} {{ fila.unidad_minimo }}</td>
          </tr>
          <tr v-if="(reposicion.items ?? []).length === 0">
            <td colspan="4" class="vacio">Nada bajo el mínimo.</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!--
        MOVIMIENTOS
        Es la historia, no el saldo. Cuando aparece una diferencia, lo que
        sirve son los movimientos que la explican.
    -->
    <section v-if="activa === 'movimientos'">
      <h2>Movimientos de stock</h2>

      <div class="filtros">
        <label class="campo">
          <span>Desde</span>
          <input v-model="desde" type="date" @change="cargarMovimientos" />
        </label>

        <label class="campo">
          <span>Hasta</span>
          <input v-model="hasta" type="date" @change="cargarMovimientos" />
        </label>
      </div>

      <table class="tabla">
        <thead>
          <tr>
            <th>Cuándo</th>
            <th>Variante</th>
            <th>Sucursal</th>
            <th>Motivo</th>
            <th class="numero">Cantidad</th>
            <th>Glosa</th>
            <th>Quién</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="movimiento in movimientos" :key="movimiento.id">
            <td>{{ movimiento.ocurrido_en.slice(0, 16).replace('T', ' ') }}</td>
            <td>{{ movimiento.variante }}</td>
            <td>{{ movimiento.sucursal }}</td>
            <td>{{ movimiento.tipo }}</td>
            <td class="numero" :class="claseDeMonto(movimiento.cantidad)">
              {{ movimiento.cantidad }} {{ movimiento.unidad }}
            </td>
            <td>{{ movimiento.glosa ?? '—' }}</td>
            <td>{{ movimiento.usuario ?? '—' }}</td>
          </tr>
          <tr v-if="movimientos.length === 0">
            <td colspan="7" class="vacio">Sin movimientos en el período.</td>
          </tr>
        </tbody>
      </table>
    </section>
  </section>
</template>

<script setup lang="ts">
import type { MovimientoStock } from '~/tipos/api';

definePageMeta({
  layout: 'default',
  middleware: 'auth',
});

useHead({ title: 'Reportes · Gestión de Ferretería' });

interface FilaSucursal {
  sucursal_id: string;
  sucursal: string;
  ventas: number;
  total: string;
}

interface FilaRotacion {
  variante_id: string;
  sku: string;
  nombre: string;
  unidades: string;
  monto: string;
}

interface FilaCorte {
  id: string;
  sucursal: string;
  folio: string;
  fecha_cierre_at: string | null;
  monto_declarado: string | null;
  monto_sistema: string | null;
  diferencia: string | null;
}

interface FilaReposicion {
  sucursal: string;
  sku: string;
  variante: string;
  disponible: string;
  unidad: string;
  minimo: string;
  unidad_minimo: string;
}

const pestanas = [
  { clave: 'ventas', titulo: 'Ventas' },
  { clave: 'caja', titulo: 'Diferencias de caja' },
  { clave: 'reposicion', titulo: 'Reposición' },
  { clave: 'movimientos', titulo: 'Movimientos' },
] as const;

const activa = ref<(typeof pestanas)[number]['clave']>('ventas');

const ventas = ref<{ fecha: string; ventas: number; total: string; por_sucursal: FilaSucursal[] }>({
  fecha: '',
  ventas: 0,
  total: '0.00',
  por_sucursal: [],
});

const rotacion = ref<FilaRotacion[]>([]);
const diferencias = ref<{
  desde: string;
  hasta: string;
  cortes: number;
  diferencia_total: string;
  cortes_que_requieren_revision: number;
  detalle: FilaCorte[];
}>({ desde: '', hasta: '', cortes: 0, diferencia_total: '0.00', cortes_que_requieren_revision: 0, detalle: [] });

const reposicion = ref<{
  bajo_minimo: number;
  alertas_pendientes: number;
  items: FilaReposicion[];
}>({ bajo_minimo: 0, alertas_pendientes: 0, items: [] });

const movimientos = ref<MovimientoStock[]>([]);
const desde = ref('');
const hasta = ref('');
const error = ref<string | null>(null);

async function cargarVentas(): Promise<void> {
  const respuesta = await $fetch<typeof ventas.value>('/api/v1/reportes/ventas-del-dia');
  ventas.value = respuesta;

  const detalleRotacion = await $fetch<{ rotacion: FilaRotacion[] }>('/api/v1/reportes/rotacion');
  rotacion.value = detalleRotacion.rotacion;
}

async function cargarDiferencias(): Promise<void> {
  const respuesta = await $fetch<typeof diferencias.value>(
    '/api/v1/reportes/diferencias-de-caja',
  );

  diferencias.value = respuesta;
}

async function cargarReposicion(): Promise<void> {
  const respuesta = await $fetch<typeof reposicion.value>('/api/v1/reportes/reposicion');
  reposicion.value = respuesta;
}

async function cargarMovimientos(): Promise<void> {
  const respuesta = await $fetch<{ movimientos: MovimientoStock[] }>(
    '/api/v1/reportes/movimientos',
    {
      query: {
        desde: desde.value || undefined,
        hasta: hasta.value || undefined,
        limite: 200,
      },
    },
  );

  movimientos.value = respuesta.movimientos;
}

async function cargar(): Promise<void> {
  error.value = null;

  try {
    if (activa.value === 'ventas') {
      await cargarVentas();
    }

    if (activa.value === 'caja') {
      await cargarDiferencias();
    }

    if (activa.value === 'reposicion') {
      await cargarReposicion();
    }

    if (activa.value === 'movimientos') {
      await cargarMovimientos();
    }
  } catch (fallo) {
    const respuesta = fallo as { data?: { mensaje?: string } };
    error.value = respuesta?.data?.mensaje ?? 'No se pudo cargar el reporte.';
  }
}

async function cambiar(clave: (typeof pestanas)[number]['clave']): Promise<void> {
  activa.value = clave;

  await cargar();
}

function claseDeDiferencia(diferencia: string | null | undefined): string {
  if (!diferencia || diferencia === '0.00') {
    return 'sin-diferencia';
  }

  return diferencia.trim().startsWith('-') ? 'faltante' : 'sobrante';
}

function claseDeMonto(monto: string): string {
  return monto.trim().startsWith('-') ? 'faltante' : 'sobrante';
}

await cargar();
</script>

<style scoped>
.intro,
.pie,
.vacio {
  color: #627d98;
}

.pestanas {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin: 1rem 0 1.5rem;
}

.pestanas .activo {
  border-color: var(--color-primario);
  font-weight: 700;
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