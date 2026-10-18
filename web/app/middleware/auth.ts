/**
 * Protege las pantallas de la ferreteria.
 *
 * Si no hay sesión, manda a la pantalla de entrada. No es una protección de
 * seguridad: la seguridad esta en la API, que devuelve 404 ante un permiso
 * insuficiente y no entrega datos de otra ferreteria. Esto es solo para que
 * la persona no vea una pantalla vacia sin saber que hacer.
 */
export default defineNuxtRouteMiddleware(async (to) => {
  const { usuario, cargar } = useSesion();

  const quien = await cargar();

  if (!quien && to.path !== '/entrar') {
    return navigateTo('/entrar');
  }

  if (quien && to.path === '/entrar') {
    return navigateTo('/');
  }

  return usuario.value !== null || to.path === '/entrar';
});