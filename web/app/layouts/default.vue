<template>
  <div class="ferreteria">
    <header class="ferreteria__cabecera">
      <div class="ferreteria__marca">
        <strong>Gestión de Ferretería</strong>
        <span v-if="usuario" class="ferreteria__empresa">{{ usuario.empresa }}</span>
      </div>

      <nav v-if="usuario" class="ferreteria__menu">
        <NuxtLink to="/">Tablero</NuxtLink>
        <NuxtLink to="/mostrador">Mostrador</NuxtLink>
        <NuxtLink to="/corte-de-caja">Corte de caja</NuxtLink>
        <NuxtLink to="/productos">Productos y variantes</NuxtLink>
        <NuxtLink to="/unidades">Unidades</NuxtLink>
        <NuxtLink to="/stock">Stock</NuxtLink>
        <NuxtLink to="/transferencias">Transferencias</NuxtLink>
        <NuxtLink to="/sucursales">Sucursales</NuxtLink>
        <NuxtLink to="/reportes">Reportes</NuxtLink>
      </nav>

      <div v-if="usuario" class="ferreteria__usuario">
        <span>{{ usuario.nombre }} ({{ usuario.rol }})</span>
        <button type="button" class="boton boton--discreto" @click="salir">Salir</button>
      </div>
    </header>

    <main class="ferreteria__contenido">
      <slot />
    </main>

    <footer class="ferreteria__pie">
      <p>
        Aplicación de Hefesto Studio. El precio y el stock son de la variante,
        nunca del producto. El saldo de stock no se edita: se mueve, y cada
        movimiento queda escrito con su motivo y su persona.
      </p>
    </footer>
  </div>
</template>

<script setup lang="ts">
const { usuario, cargar, salir } = useSesion();

// El menu depende de quien esta conectado. Se carga una sola vez por visita:
// el resto de las paginas ya la piden por su cuenta con el middleware.
await callOnce('cargar-sesion', cargar);
</script>

<style scoped>
.ferreteria {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  color: #1f2933;
}

.ferreteria__cabecera {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  flex-wrap: wrap;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid #d9e2ec;
  background: #ffffff;
}

.ferreteria__marca {
  display: flex;
  flex-direction: column;
}

.ferreteria__empresa {
  font-size: 0.85rem;
  color: #627d98;
}

.ferreteria__menu {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
}

.ferreteria__menu a {
  color: #334e68;
  text-decoration: none;
  padding: 0.25rem 0;
  border-bottom: 2px solid transparent;
}

.ferreteria__menu a.router-link-active {
  border-bottom-color: #2b6cb0;
  font-weight: 600;
}

.ferreteria__usuario {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.9rem;
}

.ferreteria__contenido {
  flex: 1;
  padding: 1.5rem;
  max-width: 72rem;
  width: 100%;
  margin: 0 auto;
}

.ferreteria__pie {
  border-top: 1px solid #d9e2ec;
  padding: 1rem 1.5rem;
  font-size: 0.85rem;
  color: #627d98;
}
</style>