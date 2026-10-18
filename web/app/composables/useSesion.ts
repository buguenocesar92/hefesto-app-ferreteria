import type { FerreteriaSession } from '~/tipos/api';

/**
 * Sesión de la persona que esta operando la ferreteria.
 *
 * El rol decide que se ve en el menu, pero NO decide que se puede hacer: la
 * autorización real la hace la API. Esto es solo para no mostrar botones que
 * van a ser rechazados: es una cortesia, no una protección.
 *
 * Lo que la API responde ante un permiso insuficiente es 404, no 403, para no
 * confirmar que el recurso existe. Ver docs/07-SEGURIDAD.md.
 */
export function useSesion() {
  const usuario = useState<FerreteriaSession | null>('sesion:usuario', () => null);
  const cargado = useState<boolean>('sesion:cargada', () => false);

  async function cargar(): Promise<FerreteriaSession | null> {
    if (cargado.value) {
      return usuario.value;
    }

    try {
      usuario.value = await $fetch<FerreteriaSession>('/api/v1/yo');
    } catch {
      usuario.value = null;
    }

    cargado.value = true;

    return usuario.value;
  }

  async function salir(): Promise<void> {
    await $fetch('/api/v1/salir', { method: 'POST' });

    usuario.value = null;
    cargado.value = true;

    await navigateTo('/entrar');
  }

  return {
    usuario,
    cargado,
    cargar,
    salir,
    esAdministrador: computed(() => usuario.value?.rol === 'administrador'),
    esGerente: computed(
      () => usuario.value?.rol === 'administrador' || usuario.value?.rol === 'gerente',
    ),
    puedeAdministrarCatalogo: computed(() => usuario.value?.puede_administrar_catalogo === true),
    puedeCerrarCaja: computed(() => usuario.value?.puede_cerrar_caja === true),
    puedeMoverEntreSucursales: computed(
      () => usuario.value?.puede_mover_entre_sucursales === true,
    ),
    puedeAnular: computed(() => usuario.value?.puede_anular === true),
  };
}