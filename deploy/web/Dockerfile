# ---------------------------------------------------------------------------
# Imagen de la interfaz (Nuxt)
# ---------------------------------------------------------------------------
# El tag esta fijado exacto, no `latest`. Ver docs/10-PRUEBAS-Y-ESTADO.md.
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# Etapa 1: construccion.
# ---------------------------------------------------------------------------
FROM node:24.22.0-alpine3.24 AS construccion

WORKDIR /app

# Se copian solo los manifiestos primero para que la capa se reutilice cuando
# no cambian las dependencias.
COPY package.json package-lock.json* ./

# `--ignore-scripts` evita que corra `nuxt prepare` antes de que exista el
# código de la aplicación.
RUN npm install --no-audit --no-fund --ignore-scripts

COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Etapa 2: ejecución.
# ---------------------------------------------------------------------------
FROM node:24.22.0-alpine3.24 AS app

WORKDIR /app

ENV NODE_ENV=production \
    HOST=0.0.0.0 \
    PORT=3000

COPY --from=construccion /app/.output ./.output

# El usuario `node` viene en la imagen oficial y no es root.
USER node

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]