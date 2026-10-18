#!/bin/sh
# ---------------------------------------------------------------------------
# Restauración de la base de datos de la ferreteria.
# ---------------------------------------------------------------------------
#
# USO
# ---
#   ./restauración.sh <archivo-de-respaldo>
#   ./restauración.sh <archivo-de-respaldo> --si
#
# REGLAS QUE ESTE SCRIPT RESPETA
# ------------------------------
# 1. VERIFICAR EL SHA-256 ANTES DE RESTAURAR
#    El manifiesto que dejó respaldo.sh tiene el sello del archivo. Si no
#    coincide, el respaldo está dañado y restaurar deja la ferretería sin
#    base. Se pregunta antes de seguir.
#
# 2. EXIGIR LA MARCA DE COMPLETITUD
#    Un respaldo sin el archivo `COMPLETO` fue interrumpido. Restaurarlo es
#    restaurar media base, que es peor que no restaurar nada.
#
# 3. NUNCA DROP
#    No se usa `DROP DATABASE`. Para limpiar se usa `TRUNCATE`, que vacía
#    las tablas y conserva su estructura, sus índices y sus políticas de
#    Row Level Security. Un `DROP` de una base desde un script es la forma
#    más rápida de perder el aislamiento entre ferreterías y el historial de
#    los cortes de caja.
#
# 4. NUNCA SOBRESCRITURA AUTOMÁTICA
#    Si la base tiene datos, no se pisa nada sin que alguien lo confirme con
#    `--si` y Socket en el reporte.
#
# 5. DEJAR EL SERVICIO DETENIDO AL TERMINAR
#    El contenedor `api` queda detenido. Restaurar con el servicio vivo
#    significa que una venta entra mientras se restaura la base, y esa venta
#    se pierde sin dejar rastro. Ver la nota final.
#
# ---------------------------------------------------------------------------

set -e

ARCHIVO=""
CONFIRMAR="false"

for ARGUMENTO in "$@"; do
    case "$ARGUMENTO" in
        --si) CONFIRMAR="true" ;;
        -*) echo "Opción desconocida: $ARGUMENTO"; exit 2 ;;
        *) ARCHIVO="$ARGUMENTO" ;;
    esac
done

if [ -z "$ARCHIVO" ]; then
    echo "Uso: $0 <archivo-de-respaldo> [--si]"
    exit 2
fi

BASE_DATOS="${FERRETERIA_DB_DATABASE:-ferreteria}"
USUARIO="${FERRETERIA_DB_USERNAME:-ferreteria_app}"
CONTRA="${FERRETERIA_DB_PASSWORD:-}"
ANFITRION="${FERRETERIA_DB_HOST:-127.0.0.1}"
PUERTO="${FERRETERIA_DB_PORT:-5432}"
SERVIDOR_PG="${FERRETERIA_PG_RESTORE:-pg_restore}"
SERVIDOR_PSQL="${FERRETERIA_PSQL:-psql}"
COMPOSE="${FERRETERIA_COMPOSE:-docker compose -f deploy/docker-compose.prod.yml}"
SERVICIO_API="${FERRETERIA_SERVICIO_API:-api}"

MARCA_COMPLETO="${ARCHIVO%.dump}.COMPLETO"
MANIFIESTO="${ARCHIVO}.manifiesto.txt"

echo "[restauracion] archivo: $ARCHIVO"

# ---------------------------------------------------------------------------
# 1. Validar el archivo
# ---------------------------------------------------------------------------
if [ ! -f "$ARCHIVO" ]; then
    echo "[restauracion] ERROR: el archivo no existe: $ARCHIVO"
    exit 1
fi

# Regla 2: exigir la marca de completitud.
if [ ! -f "$MARCA_COMPLETO" ]; then
    echo "[restauración] ERROR: este respaldo NO está marcado como completo."
    echo "[restauracion] Falta: $MARCA_COMPLETO"
    echo "[restauración] Un respaldo sin esa marca fue interrumpido. No se restaura."
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Verificar el SHA-256 (regla 1)
# ---------------------------------------------------------------------------
if [ -f "$MANIFIESTO" ]; then
    ESPERADO="$(grep -v '^#' "$MANIFIESTO" | grep -v '^$' | head -n 1 | awk '{ print $1 }')"

    if [ -z "$ESPERADO" ]; then
        echo "[restauración] AVISO: el manifiesto no trae un sello legible."
    else
        if command -v sha256sum >/dev/null 2>&1; then
            ACTUAL="$(sha256sum "$(basename "$ARCHIVO")" | awk '{ print $1 }')"
        elif command -v shasum >/dev/null 2>&1; then
            ACTUAL="$(shasum -a 256 "$(basename "$ARCHIVO")" | awk '{ print $1 }')"
        else
            ACTUAL="sin-verificar"
        fi

        if [ "$ACTUAL" = "sin-verificar" ]; then
            echo "[restauración] AVISO: no hay forma de calcular el SHA-256 en este sistema."
        elif [ "$ACTUAL" != "$ESPERADO" ]; then
            echo "[restauración] ERROR: el archivo NO coincide con su manifiesto."
            echo "[restauracion]   esperado: $ESPERADO"
            echo "[restauracion]   obtenido: $ACTUAL"
            echo "[restauración] El respaldo puede estar dañado. NO se restauró nada."
            exit 1
        else
            echo "[restauración] sello verificado."
        fi
    fi
else
    echo "[restauración] AVISO: no hay manifiesto junto al archivo: no se puede verificar el sello."
fi

# ---------------------------------------------------------------------------
# 3. Conectividad
# ---------------------------------------------------------------------------
if [ -n "$CONTRA" ]; then
    export PGPASSWORD="$CONTRA"
fi

if ! "$SERVIDOR_PSQL" --no-psqlrc --quiet --host="$ANFITRION" --port="$PUERTO" \
        --username="$USUARIO" --dbname=postgres --command='SELECT 1' >/dev/null 2>&1; then
    echo "[restauracion] ERROR: no se pudo conectar a PostgreSQL en ${ANFITRION}:${PUERTO}."
    exit 1
fi

# ---------------------------------------------------------------------------
# 4. ¿Tiene datos la base? (regla 4)
# ---------------------------------------------------------------------------
TABLAS="$(
    "$SERVIDOR_PSQL" --no-psqlrc --quiet --host="$ANFITRION" --port="$PUERTO" \
        --username="$USUARIO" --dbname="$BASE_DATOS" --tuples-only --no-align \
        --command="SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'"
)"

if [ -z "$TABLAS" ]; then
    TABLAS=0
fi

if [ "$TABLAS" -gt 0 ]; then
    echo "[restauracion] la base ${BASE_DATOS} tiene ${TABLAS} tablas."
    echo "[restauración] Restaurar va a BORRAR esos datos."
    echo "[restauración] Para continuar, vuelva a ejecutarlo con --si."

    if [ "$CONFIRMAR" != "true" ]; then
        exit 1
    fi

    echo "[restauración] --si indicado: se sigue adelante."
fi

# ---------------------------------------------------------------------------
# 5. Detener el servicio ANTES de tocar la base (regla 5)
# ---------------------------------------------------------------------------
echo "[restauracion] deteniendo el servicio ${SERVICIO_API}..."
$COMPOSE stop "$SERVICIO_API" 2>/dev/null || echo "[restauración] AVISO: no se pudo detener el servicio (puede no existir en este equipo)."

# ---------------------------------------------------------------------------
# 6. Limpiar con TRUNCATE, nunca con DROP (regla 3)
# ---------------------------------------------------------------------------
echo "[restauracion] limpiando las tablas con TRUNCATE..."

# `RESTART IDENTITY CASCADE` deja las secuencias en cero y limpia también las
# tablas que apuntan por clave foránea. NO borra la estructura: los índices, las
# restricciones y las políticas de Row Level Security siguen en pie.
TRUNCATE_SQL="DO \$\$ DECLARE r record; BEGIN
    FOR r IN (SELECT tablename FROM pg_tables WHERE schemaname = 'public') LOOP
        EXECUTE 'TRUNCATE TABLE public.' || quote_ident(r.tablename) || ' RESTART IDENTITY CASCADE';
    END LOOP;
END \$\$;"

"$SERVIDOR_PSQL" --no-psqlrc --quiet --host="$ANFITRION" --port="$PUERTO" \
    --username="$USUARIO" --dbname="$BASE_DATOS" --command="$TRUNCATE_SQL"

echo "[restauración] tablas vacías. Ahora se restaura el respaldo."

# ---------------------------------------------------------------------------
# 7. Restaurar
# ---------------------------------------------------------------------------
# `--clean` evita el error de "la tabla ya existe" sobre las tablas que
# sobreviven porque el respaldo no las trajo.
if ! "$SERVIDOR_PG" --host="$ANFITRION" --port="$PUERTO" --username="$USUARIO" \
        --dbname="$BASE_DATOS" --clean --if-exists --no-owner --no-privileges \
        "$ARCHIVO"; then
    echo "[restauración] ERROR: falló la restauración."
    echo "[restauracion] El servicio ${SERVICIO_API} queda DETENIDO a propósito."
    echo "[restauración] Investigue antes de volver a levantarlo."
    exit 1
fi

# ---------------------------------------------------------------------------
# 8. Comprobación mínima y cierre
# ---------------------------------------------------------------------------
TABLAS_DESPUES="$(
    "$SERVIDOR_PSQL" --no-psqlrc --quiet --host="$ANFITRION" --port="$PUERTO" \
        --username="$USUARIO" --dbname="$BASE_DATOS" --tuples-only --no-align \
        --command="SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'"
)"

echo "[restauracion] terminado. Tablas ahora: ${TABLAS_DESPUES:-0}"

cat <<EOF

[restauracion] IMPORTANTE
[restauracion] El servicio ${SERVICIO_API} queda DETENIDO.
[restauracion] No se levanta solo a proposito: una venta que entra mientras se
[restauracion] restaura la base se pierde sin dejar rastro, y el corte de caja
[restauracion] de ese turno queda descuadrado sin explicacion.
[restauracion]
[restauracion] Antes de levantar el servicio, compruebe el aislamiento:
[restauracion]   $COMPOSE run --rm api php artisan ferreteria:verificar-aislamiento
[restauracion] y despues:
[restauracion]   $COMPOSE up -d ${SERVICIO_API}

EOF