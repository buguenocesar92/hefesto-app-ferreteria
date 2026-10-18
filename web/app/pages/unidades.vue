<template>
  <section>
    <h1>Unidades de medida</h1>
    <p class="intro">
      Cada unidad declara a qué unidad base pertenece y cuántas unidades base
      tiene. Toda conversión sale de esos dos datos: no hay ningún número
      escrito en el código.
    </p>

    <p v-if="error" class="error">{{ error }}</p>

    <div v-for="(unidades, base) in porBase" :key="base" class="grupo">
      <h2>{{ base }}</h2>

      <table class="tabla">
        <thead>
          <tr>
            <th>Código</th>
            <th>Nombre</th>
            <th class="numero">Unidades por base</th>
            <th>Es base</th>
            <th>Activa</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="unidad in unidades" :key="unidad.id">
            <td>{{ unidad.codigo }}</td>
            <td>{{ unidad.nombre }}</td>
            <td class="numero">{{ unidad.unidades_por_base }}</td>
            <td>{{ unidad.es_base ? 'Sí' : 'No' }}</td>
            <td>{{ unidad.activo ? 'Sí' : 'No' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="Object.keys(porBase).length === 0" class="aviso">
      No hay unidades de medida cargadas. Sin al menos una unidad marcada como
      base no hay forma de convertir cantidades, y la aplicación lo avisa en
      lugar de adivinar.
    </div>
  </section>
</template>

<script setup lang="ts">
import type { UnidadMedida } from '~/tipos/api';

definePageMeta({
  layout: 'default',
  middleware: 'auth',
});

useHead({ title: 'Unidades · Gestión de Ferretería' });

const unidades = ref<UnidadMedida[]>([]);
const error = ref<string | null>(null);

/**
 * Las unidades agrupadas por base.
 *
 * La API ya devuelve el agrupamiento porque "kilogramo" y "bolsa de 40
 * kilogramos" no son comparables entre sí, pero en la misma familia que el
 * kilogramo. Ver docs/02-MODELO-DE-DATOS.md.
 */
const porBase = computed<Record<string, UnidadMedida[]>>(() => {
  const grupos: Record<string, UnidadMedida[]> = {};

  for (const unidad of unidades.value) {
    const clave = unidad.es_base ? unidad.nombre : baseDe(unidad);

    if (!grupos[clave]) {
      grupos[clave] = [];
    }

    grupos[clave]!.push(unidad);
  }

  return grupos;
});

function baseDe(unidad: UnidadMedida): string {
  const base = unidades.value.find((otra) => otra.id === unidad.unidad_base_id);

  return base?.nombre ?? 'Sin base declarada';
}

onMounted(async () => {
  try {
    const respuesta = await $fetch<{ unidades: UnidadMedida[] }>('/api/v1/unidades');
    unidades.value = respuesta.unidades;
  } catch (fallo) {
    const respuesta = fallo as { data?: { mensaje?: string } };
    error.value = respuesta?.data?.mensaje ?? 'No se pudieron cargar las unidades.';
  }
});
</script>

<style scoped>
.intro {
  color: #627d98;
}

.grupo {
  margin-bottom: 2rem;
}
</style>