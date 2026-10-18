// Configuración de Nuxt (capa web) de Gestión de Ferreteria.
//
// ARQUITECTURA BFF
// ----------------
// Esta aplicación es un "backend for frontend": la API de Laravel es la
// autoridad de la sesión y el navegador nunca habla directo con ella.
//
// El motivo es concreto y tiene que ver con la ferreteria, no con la moda: la
// sesión vive en una cookie HttpOnly. Si el navegador llamara a otro origen,
// habria que resolver CORS con credenciales, que duplica el riesgo de CSRF
// justo en la aplicación que cobra y que descuenta stock. Al pasar todo por
// el mismo origen, la cookie se mantiene en una sola parte y el token CSRF
// se reenvia como cabecera `X-XSRF-TOKEN`.
//
// Ver docs/01-ARQUITECTURA.md, seccion "Arquitectura BFF".

export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',

  ssr: true,

  devtools: { enabled: false },

  // Estilos comunes: botones grandes, números alineados y contraste alto,
  // pensados para usar la pantalla en el mostrador, con apuro y a veces de
  // pie.
  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    // URL de la API de Laravel. Es config PRIVADA: solo la usa el servidor.
    // Nunca debe exponerse al navegador.
    apiBase: process.env.NUXT_API_BASE || 'http://localhost:8000',

    public: {
      // Origen de entrada de la ferreteria, por ejemplo
      // https://ferreteria.hefestostudio.cl
      basePublico: process.env.NUXT_PUBLIC_BASE || 'http://localhost:3000',
    },
  },

  app: {
    head: {
      htmlAttrs: { lang: 'es-CL' },
      title: 'Gestión de Ferretería',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        {
          name: 'description',
          content:
            'Gestión de ferretería: catálogo con variantes, unidades de medida, stock por sucursal, transferencias, mostrador y corte de caja.',
        },
      ],
    },
  },

  // Las solicitudes de datos pasan por el BFF de `server/api`, no por el
  // navegador. El cliente solo llama a rutas del mismo origen.
  nitro: {
    routeRules: {
      '/api/**': { cors: false },
    },
  },
});