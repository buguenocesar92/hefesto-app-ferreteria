<template>
  <main class="entrada">
    <section class="tarjeta">
      <h1>Gestión de Ferretería</h1>
      <p class="intro">
        Elija la ferretería con la que va a trabajar y entre con la cuenta que
        le crearon.
      </p>

      <!--
        El aviso de lo que el sistema NO hace, va en la pantalla de entrada y
        no escondido en una ayuda. Quien entra es una persona de un local:
        tiene que saber desde el primer momento que la herramienta organiza y
        registra, y que no reemplaza el criterio de quien compra y vende.
      -->
      <p class="advertencia">
        Esta aplicación organiza el catálogo, el stock por sucursal, el
        mostrador y el corte de caja. <strong>No fija precios ni márgenes</strong>:
        esos los define la ferretería, y esta herramienta los registra.
      </p>

      <p v-if="error" class="error">{{ error }}</p>

      <label for="ferreteria">Ferretería</label>
      <select id="ferreteria" v-model="seleccionada" class="selector">
        <option v-for="ferreteria in ferreterias" :key="ferreteria.slug" :value="ferreteria.slug">
          {{ ferreteria.nombre }}
        </option>
      </select>

      <button
        class="boton boton--principal"
        type="button"
        :disabled="!seleccionada || enviando"
        @click="ingresar"
      >
        {{ enviando ? 'Redirigiendo...' : 'Ingresar' }}
      </button>

      <p class="pie">
        Solo se muestran las ferreterías activas. Si no aparece la suya,
        pida a un administrador que la registre.
      </p>
    </section>
  </main>
</template>

<script setup lang="ts">
import type { FerreteriaPublica } from '~/tipos/api';

definePageMeta({
  layout: 'default',
});

useHead({ title: 'Ingresar · Gestión de Ferretería' });

/**
 * Pantalla de entrada.
 *
 * LA LISTA DE FERRETERIAS SALE DE UN ENDPOINT PÚBLICO
 * ---------------------------------------------------
 * `/api/v1/acceso/ferreterias` lee la vista `vw_ferreterias_publicas` de la
 * base, que expone SOLO `slug` y `nombre`. No se reciben RUT, razon social,
 * direccion ni telefono: antes del ingreso no hay motivo para que el
 * navegador vea nada más.
 *
 * La tabla de ferreterias no tiene Row Level Security, porque se consulta en
 * el acceso, cuando todavia no hay ferreteria de sesión. La vista es la
 * compensacion por esa excepcion, y se usa de verdad.
 */
const ruta = useRoute();
const error = ref<string | null>(
  typeof ruta.query.error === 'string' ? ruta.query.error : null,
);

const seleccionada = ref<string>('');
const enviando = ref(false);

const { data } = await useFetch<{ ferreterias: FerreteriaPublica[] }>(
  '/api/v1/acceso/ferreterias',
);

const ferreterias = computed(() => data.value?.ferreterias ?? []);

onMounted(() => {
  if (ferreterias.value.length > 0) {
    seleccionada.value = ferreterias.value[0]?.slug ?? '';
  }
});

async function ingresar(): Promise<void> {
  if (!seleccionada.value) {
    return;
  }

  enviando.value = true;
  error.value = null;

  try {
    const respuesta = await $fetch<{ redirigir_a: string }>(
      `/api/v1/acceso/entrar/${seleccionada.value}`,
    );

    await navigateTo(respuesta.redirigir_a, { external: true });
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
    enviando.value = false;
  }
}

/**
 * El mensaje que la API devuelve.
 *
 * La API responde 404 cuando la ferreteria no existe o esta inactiva, y el
 * mensaje es el mismo en los dos casos: no se confirma que el identificador
 * exista.
 */
function mensajeDeError(fallo: unknown): string {
  const respuesta = fallo as {
    data?: { mensaje?: string };
    message?: string;
  };

  return respuesta?.data?.mensaje ?? 'No se pudo iniciar el acceso.';
}
</script>

<style scoped>
.entrada {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.tarjeta {
  width: 100%;
  max-width: 26rem;
  background-color: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
  padding: 2rem;
}

h1 {
  font-size: 1.375rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
}

.intro {
  color: #627d98;
  font-size: 0.95rem;
  margin-top: 0;
}

.advertencia {
  font-size: 0.85rem;
  background: #fff8e6;
  border: 1px solid #f0c674;
  border-radius: 0.375rem;
  padding: 0.75rem;
}

label {
  display: block;
  font-size: 0.85rem;
  font-weight: 600;
  margin-bottom: 0.35rem;
}

.selector {
  width: 100%;
  margin-bottom: 1rem;
}

.boton {
  width: 100%;
}

.pie {
  font-size: 0.8rem;
  color: #627d98;
  margin-bottom: 0;
}
</style>