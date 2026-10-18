#!/bin/sh
# ---------------------------------------------------------------------------
# Entrada del contenedor de la API de la ferreteria.
# ---------------------------------------------------------------------------
# El punto de este script es hacer FALLAR el despliegue con un mensaje claro
# cuando algo quedo mal configurado, en vez de levantar un contenedor que
# responde con errores 500 a todas partes.
#
# Lo que comprueba:
#   1. que APP_KEY este definida
#   2. que la API pueda conectarse a PostgreSQL
#   3. que el aislamiento entre ferreterias este puesto
#
# Por que el paso 3 es aviso y no falla: si se hiciera en cada arranque, un
# contenedor que arranca con la base de datos todavia levantandose caeria en
# bucle. El comando se ejecuta aparte en el despliegue (ver docs/04 y docs/07),
# donde si detiene la puesta en marcha.
# ---------------------------------------------------------------------------

set -e

cd /var/www/html

echo "[entrada] PHP $(php -v | head -n 1)"

# ---------------------------------------------------------------------------
# 1. Configuración minima
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY}" ]; then
    echo "[entrada] ERROR: APP_KEY no esta definida."
    echo "[entrada] Genere una con:  docker compose run --rm api php artisan key:generate --show"
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Espera a la base de datos y aplicación de migraciones
# ---------------------------------------------------------------------------
echo "[entrada] esperando a PostgreSQL..."
intentos=0
hasta="${FERRETERIA_INTENTOS_CONEXION:-30}"
while [ "$intentos" -lt "$hasta" ]; do
    if php -r 'exit(@pg_connect("host=".(getenv("DB_HOST") ?: "127.0.0.1")." port=".(getenv("DB_PORT") ?: "5432")." dbname=".getenv("DB_DATABASE")." user=".getenv("DB_USERNAME")." connect_timeout=2")) ? 0 : 1);'; then
        echo "[entrada] PostgreSQL responde."
        break
    fi

    intentos=$((intentos + 1))
    echo "[entrada] todavia no responde (intento $intentos de $hasta)"
    sleep 2
done

if [ "$intentos" -ge "$hasta" ]; then
    echo "[entrada] ERROR: PostgreSQL no respondio a tiempo."
    exit 1
fi

if [ "${FERRETERIA_CORRER_MIGRACIONES:-false}" = "true" ]; then
    echo "[entrada] aplicando migraciones..."
    php artisan migrate --force --no-interaction
    php artisan config:cache
    php artisan route:cache
fi

# ---------------------------------------------------------------------------
# 3. Aviso sobre el aislamiento entre ferreterias (no bloqueante)
# ---------------------------------------------------------------------------
# En esta aplicación el aislamiento es la linea entre una ferreteria y los
# datos de otra. El comando comprueba además que el rol de la base no sea
# superusuario ni tenga BYPASSRLS, que es el caso en que PostgreSQL ignora
# las políticas por completo.
echo "[entrada] revisando el aislamiento entre ferreterias..."
if php artisan ferreteria:verificar-aislamiento; then
    echo "[entrada] aislamiento verificado."
else
    echo "[entrada] AVISO: el aislamiento entre ferreterias NO esta bien puesto."
    echo "[entrada] La API puede arrancar, pero NO debe recibir personas hasta corregirlo:"
    echo "[entrada] una ferreteria podria ver los datos de otra."
fi

exec "$@"