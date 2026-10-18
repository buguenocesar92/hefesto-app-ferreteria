<template>
  <section>
    <h1>Corte de caja</h1>
    <p class="intro">
      La diferencia es el número que el dueño mira todas las noches. Se guarda
      completa y con su signo, aunque sea de un peso.
    </p>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje" class="aviso">{{ mensaje }}</p>

    <div class="filtros">
      <label class="campo">
        <span>Sucursal</span>
        <select v-model="sucursalId" @change="cargar">
          <option v-for="sucursal in sucursales" :key="sucursal.id" :value="sucursal.id">
            {{ sucursal.codigo }} - {{ sucursal.nombre }}
          </option>
        </select>
      </label>
    </div>

    <h2>Caja abierta</h2>

    <p v-if="!cajaAbierta" class="vacio">
      Esta sucursal no tiene un corte abierto. Ábralo para poder registrar
      ventas, cobros y retiros.
    </p>

    <div v-else class="panel">
      <p>
        Folio <strong>{{ cajaAbierta.folio }}</strong>, abierta el
        {{ cajaAbierta.fecha_apertura_at?.slice(0, 16).replace('T', ' ') ?? '' }}.
      </p>

      <p>
        Monto inicial:
        <strong class="numero">{{ cajaAbierta.monto_inicial }}</strong>
      </p>

      <p>
        Esperado ahora:
        <strong class="numero">{{ cajaAbierta.monto_esperado ?? '—' }}</strong>
      </p>

      <h3>Movimientos del turno</h3>
      <table v-if="movimientos.length > 0" class="tabla">
        <thead>
          <tr>
            <th>Tipo</th>
            <th class="numero">Monto</th>
            <th>Glosa</th>
            <th>Quién</th>
            <th>Cuándo</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="movimiento in movimientos" :key="movimiento.id">
            <td>{{ movimiento.tipo }}</td>
            <td class="numero" :class="claseDeMonto(movimiento.monto)">
              {{ movimiento.monto }}
            </td>
            <td>{{ movimiento.glosa ?? '—' }}</td>
            <td>{{ movimiento.usuario ?? '—' }}</td>
            <td>{{ movimiento.ocurrido_en.slice(0, 16).replace('T', ' ') }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="vacio">Todavía no hay movimientos en este turno.</p>

      <h3>Registrar movimiento</h3>
      <form class="fila-cobro" @submit.prevent="registrarMovimiento">
        <label class="campo">
          <span>Tipo</span>
          <select v-model="movimiento.tipo">
            <option value="cobro">Cobro</option>
            <option value="deposito">Depósito</option>
            <option value="retiro">Retiro</option>
            <option value="ajuste">Ajuste</option>
          </select>
        </label>

        <label class="campo">
          <span>Monto</span>
          <input v-model="movimiento.monto" inputmode="decimal" placeholder="0" />
        </label>

        <label class="campo campo--ancho">
          <span>Glosa</span>
          <input
            v-model="movimiento.glosa"
            type="text"
            maxlength="300"
            placeholder="Obligatoria para retiros y ajustes"
          />
        </label>

        <button class="boton boton--principal" type="submit">Registrar</button>
      </form>

      <h3>Arqueo</h3>
      <form class="fila-cobro" @submit.prevent="cerrar">
        <label class="campo">
          <span>Monto contado y entregado</span>
          <input v-model="declarado" inputmode="decimal" placeholder="0.00" />
        </label>

        <button
          class="boton boton--discreto"
          type="button"
          :disabled="!declarado || puedeCerrarCaja === false"
          @click="previsualizar"
        >
          Ver diferencia
        </button>

        <button
          class="boton boton--principal"
          type="submit"
          :disabled="!declarado || puedeCerrarCaja === false"
        >
          Cerrar el turno
        </button>
      </form>

      <p v-if="!puedeCerrarCaja" class="pie">
        Su rol puede registrar movimientos, pero no cerrar el turno: el cierre
        lo hace gerencia.
      </p>

      <p v-if="arqueo" class="arqueo">
        <span>
          Declarado <strong class="numero">{{ arqueo.monto_declarado }}</strong>
        </span>
        <span>
          Sistema <strong class="numero">{{ arqueo.monto_sistema }}</strong>
        </span>
        <span>
          Diferencia
          <strong class="numero" :class="claseDeDiferencia(arqueo.diferencia)">
            {{ arqueo.diferencia }}
          </strong>
        </span>
        <span class="pie">
          Tolerancia {{ arqueo.tolerancia }}: este corte queda
          {{ arqueo.revision === 'requiere_revision' ? 'marcado para revisión' : 'dentro de tolerancia' }}.
        </span>
      </p>
    </div>

    <div v-if="!cajaAbierta" class="panel">
      <h3>Abrir un turno</h3>
      <form class="fila-cobro" @submit.prevent="abrir">
        <label class="campo">
          <span>Monto con el que abre</span>
          <input v-model="montoInicial" inputmode="decimal" placeholder="0.00" />
        </label>

        <button class="boton boton--principal" type="submit">Abrir caja</button>
      </form>
    </div>

    <h2>Cortes anteriores</h2>

    <table class="tabla">
      <thead>
        <tr>
          <th>Folio</th>
          <th>Estado</th>
          <th>Cierre</th>
          <th class="numero">Declarado</th>
          <th class="numero">Sistema</th>
          <th class="numero">Diferencia</th>
          <th>Cerró</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="corte in cortes" :key="corte.id">
          <td>{{ corte.folio }}</td>
          <td>{{ corte.estado }}</td>
          <td>{{ corte.fecha_cierre_at?.slice(0, 16).replace('T', ' ') ?? '—' }}</td>
          <td class="numero">{{ corte.monto_declarado ?? '—' }}</td>
          <td class="numero">{{ corte.monto_sistema ?? '—' }}</td>
          <td class="numero" :class="claseDeDiferencia(corte.diferencia)">
            {{ corte.diferencia ?? '—' }}
          </td>
          <td>{{ corte.cerro ?? '—' }}</td>
        </tr>
        <tr v-if="cortes.length === 0">
          <td colspan="7" class="vacio">Esta sucursal no tiene cortes todavía.</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<script setup lang="ts">
import type { ArqueoCaja, Caja, CajaMovimiento, Sucursal } from '~/tipos/api';

definePageMeta({
  layout: 'default',
  middleware: 'auth',
});

useHead({ title: 'Corte de caja · Gestión de Ferretería' });

const { puedeCerrarCaja } = useSesion();

const sucursales = ref<Sucursal[]>([]);
const sucursalId = ref('');
const cortes = ref<Caja[]>([]);
const cajaAbierta = ref<Caja | null>(null);
const movimientos = ref<CajaMovimiento[]>([]);
const arqueo = ref<ArqueoCaja | null>(null);
const montoInicial = ref('0.00');
const declarado = ref('');
const error = ref<string | null>(null);
const mensaje = ref<string | null>(null);

const movimiento = reactive({ tipo: 'cobro', monto: '', glosa: '' });

async function cargar(): Promise<void> {
  if (!sucursalId.value) {
    return;
  }

  error.value = null;

  try {
    const abiertas = await $fetch<{ data: Caja[] }>('/api/v1/cajas', {
      query: { sucursal: sucursalId.value, estado: 'abierta' },
    });

    cajaAbierta.value = abiertas.data[0] ?? null;
    arqueo.value = null;
    declarado.value = '';

    if (cajaAbierta.value) {
      const detalle = await $fetch<{ movimientos: CajaMovimiento[] }>(
        `/api/v1/cajas/${cajaAbierta.value.id}/movimientos`,
      );

      movimientos.value = detalle.movimientos;
    }

    const historiales = await $fetch<{ cortes: Caja[] }>(
      `/api/v1/cajas/cortes/${sucursalId.value}`,
    );

    cortes.value = historiales.cortes;
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  }
}

async function cargarSucursales(): Promise<void> {
  const respuesta = await $fetch<{ sucursales: Sucursal[] }>('/api/v1/sucursales');

  sucursales.value = respuesta.sucursales;

  if (!sucursalId.value && respuesta.sucursales.length > 0) {
    sucursalId.value = respuesta.sucursales[0]!.id;
  }
}

async function abrir(): Promise<void> {
  error.value = null;
  mensaje.value = null;

  try {
    await $fetch('/api/v1/cajas', {
      method: 'POST',
      body: {
        sucursal_id: sucursalId.value,
        monto_inicial: String(montoInicial.value).replace(',', '.'),
      },
    });

    mensaje.value = 'Caja abierta. Ya se pueden registrar ventas y movimientos.';

    await cargar();
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  }
}

async function registrarMovimiento(): Promise<void> {
  if (!cajaAbierta.value) {
    return;
  }

  error.value = null;
  mensaje.value = null;

  try {
    // El monto viaja en positivo: el servidor pone el signo según el tipo.
    // Si el signo lo mandara la pantalla, un doble clic con el menos
    // puestoeria dos veces.
    await $fetch(`/api/v1/cajas/${cajaAbierta.value.id}/movimientos`, {
      method: 'POST',
      body: {
        tipo: movimiento.tipo,
        monto: String(movimiento.monto).replace(',', '.'),
        glosa: movimiento.glosa || null,
      },
    });

    movimiento.monto = '';
    movimiento.glosa = '';

    await cargar();
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  }
}

/**
 * Calcula el arqueo SIN cerrar el turno.
 *
 * Sirve para que la persona vea "hay 118.000, declaró 120.000, la diferencia
 * son 2.000" antes de confirmar. No escribe nada.
 */
async function previsualizar(): Promise<void> {
  if (!cajaAbierta.value) {
    return;
  }

  error.value = null;

  try {
    arqueo.value = await $fetch<ArqueoCaja>(`/api/v1/cajas/${cajaAbierta.value.id}/arqueo`, {
      query: { monto_declarado: String(declarado.value).replace(',', '.') },
    });
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  }
}

async function cerrar(): Promise<void> {
  if (!cajaAbierta.value) {
    return;
  }

  error.value = null;
  mensaje.value = null;

  try {
    const cerrada = await $fetch<Caja>(`/api/v1/cajas/${cajaAbierta.value.id}/cerrar`, {
      method: 'POST',
      body: { monto_declarado: String(declarado.value).replace(',', '.') },
    });

    mensaje.value = `Corte ${cerrada.folio} cerrado. Diferencia: ${cerrada.diferencia}.`;

    await cargar();
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  }
}

/**
 * El color del signo.
 *
 * Positivo es sobrante y negativo es faltante. Un cero NO se pinta de verde:
 * con la tolerancia en cero, un "0.00" significa que la caja cuadró, y eso
 * es una buena noticia, no una diferencia escondida.
 */
function claseDeDiferencia(diferencia: string | null): string {
  if (diferencia === null) {
    return 'sin-diferencia';
  }

  if (diferencia.trim().startsWith('-')) {
    return 'faltante';
  }

  return diferencia === '0.00' ? 'sin-diferencia' : 'sobrante';
}

function claseDeMonto(monto: string): string {
  return monto.trim().startsWith('-') ? 'faltante' : 'sobrante';
}

function mensajeDeError(fallo: unknown): string {
  const respuesta = fallo as { data?: { mensaje?: string; errors?: Record<string, string[]> } };

  if (respuesta?.data?.mensaje) {
    return respuesta.data.mensaje;
  }

  const errores = respuesta?.data?.errors;

  if (errores) {
    return Object.values(errores)[0]?.[0] ?? 'No se pudo completar la operación.';
  }

  return 'No se pudo completar la operación.';
}

await cargarSucursales();
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

.campo--ancho {
  min-width: 18rem;
}

.panel {
  border: 1px solid var(--color-linea);
  border-radius: 0.5rem;
  padding: 1rem 1.25rem;
  margin-bottom: 2rem;
}

.fila-cobro {
  display: flex;
  gap: 1rem;
  align-items: flex-end;
  flex-wrap: wrap;
  margin: 1rem 0;
}

.arqueo {
  display: flex;
  gap: 1.5rem;
  flex-wrap: wrap;
  padding: 0.75rem 1rem;
  border: 1px solid #e4e7eb;
  border-radius: 0.375rem;
  background: #f8fafc;
  font-size: 0.95rem;
}
</style>