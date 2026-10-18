<template>
  <section>
    <h1>Tablero</h1>
    <p class="intro">
      Ferretería: {{ usuario?.empresa }}. Sesión de {{ usuario?.nombre }}
      ({{ usuario?.rol }}).
    </p>

    <!--
        Las tres cifras de la mañana. Son las tres que el dueño mira primero
        y las tres que hay que mirar antes de abrir la puerta.
    -->
    <div class="cifras">
      <article class="cifra">
        <span class="cifra__etiqueta">Variantes bajo el mínimo</span>
        <strong class="cifra__valor numero">{{ bajoMinimo.total }}</strong>
        <NuxtLink class="cifra__enlace" to="/stock">Ir al stock</NuxtLink>
      </article>

      <article class="cifra">
        <span class="cifra__etiqueta">Saldos con mercadería</span>
        <strong class="cifra__valor numero">{{ saldos.total }}</strong>
        <NuxtLink class="cifra__enlace" to="/stock">Ver saldos</NuxtLink>
      </article>

      <article class="cifra">
        <span class="cifra__etiqueta">Ventas del día</span>
        <strong class="cifra__valor numero">{{ ventasDelDia.ventas }}</strong>
        <span class="cifra__detalle numero">{{ ventasDelDia.total }}</span>
        <NuxtLink class="cifra__enlace" to="/mostrador">Ir al mostrador</NuxtLink>
      </article>
    </div>

    <h2>Cortes que necesitan revisión</h2>
    <p v-if="!hayCortesConRevision" class="vacio">
      Ningún corte fuera de tolerancia en el período consultado.
    </p>

    <table v-else class="tabla">
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
        <tr v-for="corte in cortesConRevision" :key="corte.id">
          <td>{{ corte.sucursal }}</td>
          <td>{{ corte.folio }}</td>
          <td>{{ corte.fecha_cierre_at?.slice(0, 16).replace('T', ' ') ?? '' }}</td>
          <td class="numero">{{ corte.monto_declarado }}</td>
          <td class="numero">{{ corte.monto_sistema }}</td>
          <td
            class="numero"
            :class="claseDeDiferencia(corte.diferencia)"
          >
            {{ corte.diferencia }}
          </td>
        </tr>
      </tbody>
    </table>

    <h2>Transferencias en camino</h2>
    <p v-if="enCamino.length === 0" class="vacio">
      No hay mercadería despachada que esté esperando ser recibida.
    </p>

    <ul v-else class="lista">
      <li v-for="transferencia in enCamino" :key="transferencia.id">
        Folio {{ transferencia.folio }}:
        {{ transferencia.origen.codigo }} hacia
        {{ transferencia.destino.codigo }}
        ({{ transferencia.items.length }} renglones),
        despachada el
        {{ transferencia.fecha_despacho_at?.slice(0, 10) ?? '' }}
        <NuxtLink to="/transferencias">recibir</NuxtLink>
      </li>
    </ul>
  </section>
</template>

<script setup lang="ts">
import type { Caja, Transferencia } from '~/tipos/api';

definePageMeta({
  layout: 'default',
  middleware: 'auth',
});

useHead({ title: 'Tablero · Gestión de Ferretería' });

const { usuario } = useSesion();

const { data: saldos } = await useFetch<{ por_sucursal: Array<{ variantes: number }> }>(
  '/api/v1/stock/resumen',
);

const { data: bajoMinimo } = await useFetch<{ bajo_minimo: unknown[] }>(
  '/api/v1/stock/bajo-minimo',
);

const { data: ventasDelDia } = await useFetch<{ ventas: number; total: string }>(
  '/api/v1/reportes/ventas-del-dia',
);

const { data: diferencias } = await useFetch<{ detalle: Caja[]; cortes_que_requieren_revision: number }>(
  '/api/v1/reportes/diferencias-de-caja',
);

const { data: transferencias } = await useFetch<{ data: Transferencia[] }>(
  '/api/v1/transferencias?estado=despachada',
);

const cortesConRevision = computed(() =>
  (diferencias.value?.detalle ?? []).filter((corte) => corte.revision === 'requiere_revision'),
);

const hayCortesConRevision = computed(() => cortesConRevision.value.length > 0);

const enCamino = computed(() => transferencias.value?.data ?? []);

/**
 * El color de la diferencia.
 *
 * Positivo es sobrante y negativo es faltante. Un cero NO se pinta de verde:
 * un "0" con la tolerance por omisión significa que no se declaró el monto, no
 * que la caja cuadrara.
 */
function claseDeDiferencia(diferencia: string | null): string {
  if (diferencia === null || diferencia === '0.00') {
    return 'sin-diferencia';
  }

  return diferencia.trim().startsWith('-') ? 'faltante' : 'sobrante';
}
</script>

<style scoped>
.intro {
  color: #627d98;
}

.cifras {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 1rem;
  margin: 1.5rem 0;
}

.cifra {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 1rem;
  border: 1px solid #e4e7eb;
  border-radius: 0.5rem;
  background: #ffffff;
}

.cifra__etiqueta {
  font-size: 0.85rem;
  color: #627d98;
}

.cifra__valor {
  font-size: 1.75rem;
}

.cifra__detalle {
  font-size: 0.9rem;
  color: #627d98;
}

.cifra__enlace {
  font-size: 0.85rem;
}

.vacio {
  color: #627d98;
}

.lista {
  padding-left: 1.25rem;
}

.lista li {
  margin-bottom: 0.5rem;
}
</style>