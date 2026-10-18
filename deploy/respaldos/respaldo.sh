#!/bin/sh
# ---------------------------------------------------------------------------
# Respaldo de la base de datos de la ferreteria.
# ---------------------------------------------------------------------------
#
# USO
# ---
#   ./respaldo.sh <destino>
#   ./respaldo.sh <destino> --force
#
# REGLAS QUE ESTE SCRIPT RESPETA, Y POR QUE
# ----------------------------------------
# 1. MANIFIESTO CON SHA-256 DE CADA ARCHIVO
#    Un respaldo sin comprobacion es una carpeta con la esperanza de que
#    este bien. El manifiesto permite verificar antes de restaurar, que es
#    el momento en que de verdad importa.
#
# 2. MARCAR EL RESPALDO COMO COMPLETO SOLO AL FINAL
#    El archivo `COMPLETO` se escribe al termino, después de calcular el
#    manifiesto. Un respaldo interrumpido no lo tiene, y restauración.sh lo
#    rechaza. Es lo que impide restaurar media base.
#
# 3. VALIDAR RUTAS Y ESPACIO ANTES DE ESCRIBIR
#    Antes de tomar el respaldo se comprueba que el destino existe, que hay
#    espacio suficiente y que el destino NO esta ocupado. Un respaldo que
#    falla a mitad de camino porque el disco se lleno es peor que uno que no
#    empezó.
#
# 4. RECHAZAR DESTINO OCUPADO SALVO --force
#    Sobre escribir un respaldo anterior se pierde la única copia buena. Sin
#    `--force` el script se niega.
#
# 5. NUNCA DROP, NUNCA SOBRESCRITURA AUTOMÁTICA
#    Si hay que limpiar tablas, se usa `TRUNCATE`, que borra filas pero
#    conserva la estructura y las políticas de RLS. Un `DROP` de la base
#    desde un script de respaldo es la forma más rapida de perder el
#    historial de una ferreteria.
#
# 6. DEJAR EL SERVICIO DETENIDO TRAS RESTAURAR
#    Ver restauración.sh.
#
# ---------------------------------------------------------------------------

set -e

DESTINO=""
FORZAR="false"

for ARGUMENTO in "$@"; do
    case "$ARGUMENTO" in
        --force) FORZAR="true" ;;
        -*) echo "Opción desconocida: $ARGUMENTO"; exit 2 ;;
        *) DESTINO="$ARGUMENTO" ;;
    esac
done

if [ -z "$DESTINO" ]; then
    echo "Uso: $0 <destino> [--force]"
    exit 2
fi

# ---------------------------------------------------------------------------
# Configuración. Se puede sobreescribir con el entorno.
# ---------------------------------------------------------------------------
BASE_DATOS="${FERRETERIA_DB_DATABASE:-ferreteria}"
USUARIO="${FERRETERIA_DB_USERNAME:-ferreteria_app}"
CONTRA="${FERRETERIA_DB_PASSWORD:-}"
ANFITRION="${FERRETERIA_DB_HOST:-127.0.0.1}"
PUERTO="${FERRETERIA_DB_PORT:-5432}"
SERVIDOR_PG="${FERRETERIA_PG_DUMP:-pg_dump}"
SERVIDOR_PSQL="${FERRETERIA_PSQL:-psql}"

# Espacio mínimo estimado, en kilobytes. Es una reserva: el respaldo comprimido
# ocupa mucho menos que la base, pero calcularlo sobre el archivo comprimido
# sería medir lo que todavia no existe.
ESPACIO_MINIMO_KB="${FERRETERIA_ESPACIO_MINIMO_KB:-20480}"

MARCA_FECHA="$(date +%Y%m%d-%H%M%S)"
ARCHIVO="${DESTINO}/ferreteria-${MARCA_FECHA}.dump"
MANIFIESTO="${ARCHIVO}.manifiesto.txt"
MARCA_COMPLETO="${DESTINO}/ferreteria-${MARCA_FECHA}.COMPLETO"

echo "[respaldo] base: ${BASE_DATOS}, destino: ${DESTINO}"

# ---------------------------------------------------------------------------
# 1. Validar el destino antes de escribir nada
# ---------------------------------------------------------------------------
if [ ! -d "$DESTINO" ]; then
    echo "[respaldo] ERROR: el destino no existe: $DESTINO"
    echo "[respaldo] Créelo primero y vuelva a intentarlo."
    exit 1
fi

if [ ! -w "$DESTINO" ]; then
    echo "[respaldo] ERROR: no se puede escribir en el destino: $DESTINO"
    exit 1
fi

# Regla 4: destino ocupado.
if [ -e "$ARCHIVO" ]; then
    if [ "$FORZAR" != "true" ]; then
        echo "[respaldo] ERROR: ya existe un respaldo en $ARCHIVO"
        echo "[respaldo] Use --force solo si está seguro de sobrescribirlo."
        exit 1
    fi

    echo "[respaldo] AVISO: sobrescribiendo $ARCHIVO porque se indicó --force."
    rm -f "$ARCHIVO" "$MANIFIESTO" "$MARCA_COMPLETO"
fi

# ---------------------------------------------------------------------------
# 2. Comprobar que la base responde antes de respaldar
# ---------------------------------------------------------------------------
if ! "$SERVIDOR_PSQL" --no-psqlrc --quiet --host="$ANFITRION" --port="$PUERTO" \
        --username="$USUARIO" --dbname=postgres --command='SELECT 1' >/dev/null 2>&1; then
    echo "[respaldo] ERROR: no se pudo conectar a PostgreSQL en ${ANFITRION}:${PUERTO}."
    echo "[respaldo] Revise que el servicio esté arriba y las credenciales."
    exit 1
fi

TAMANO_BASE_KB="$(
    "$SERVIDOR_PSQL" --no-psqlrc --quiet --host="$ANFITRION" --port="$PUERTO" \
        --username="$USUARIO" --dbname="$BASE_DATOS" --tuples-only --no-align \
        --command="SELECT pg_database_size(current_database()) / 1024"
)"

if [ -z "$TAMANO_BASE_KB" ]; then
    echo "[respaldo] ERROR: no se pudo calcular el tamaño de la base."
    exit 1
fi

# Regla 3: comprobar el espacio antes de escribir, no después.
DISPONIBLE_KB="$(df -Pk "$DESTINO" | awk 'NR == 2 { print $4 }')"

if [ -z "$DISPONIBLE_KB" ]; then
    echo "[respaldo] ERROR: no se pudo leer el espacio disponible en $DESTINO."
    exit 1
fi

REQUERIDO_KB=$((TAMANO_BASE_KB / 3))

if [ "$REQUERIDO_KB" -lt "$ESPACIO_MINIMO_KB" ]; then
    REQUERIDO_KB="$ESPACIO_MINIMO_KB"
fi

echo "[respaldo] base: ${TAMANO_BASE_KB} KB, espacio libre: ${DISPONIBLE_KB} KB, se requieren: ${REQUERIDO_KB} KB"

if [ "$DISPONIBLE_KB" -lt "$REQUERIDO_KB" ]; then
    echo "[respaldo] ERROR: no hay espacio suficiente en $DESTINO."
    echo "[respaldo] Faltan $((REQUERIDO_KB - DISPONIBLE_KB)) KB. NO se escribió nada."
    exit 1
fi

# ---------------------------------------------------------------------------
# 3. Respaldar. Formato personalizado de PostgreSQL, comprimido.
# ---------------------------------------------------------------------------
echo "[respaldo] volcando la base, esto puede tardar..."

if [ -n "$CONTRA" ]; then
    export PGPASSWORD="$CONTRA"
fi

if ! "$SERVIDOR_PG" --host="$ANFITRION" --port="$PUERTO" --username="$USUARIO" \
        --dbname="$BASE_DATOS" --format=custom --compress=9 --file="$ARCHIVO"; then
    echo "[respaldo] ERROR: falló el volcado. Se elimina el archivo incompleto."
    rm -f "$ARCHIVO"
    exit 1
fi

# ---------------------------------------------------------------------------
# 4. Manifiesto con el SHA-256 de cada archivo (regla 1)
# ---------------------------------------------------------------------------
{
    echo "# Respaldo de la ferreteria"
    echo "fecha: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "base: ${BASE_DATOS}"
    echo "servidor: ${ANFITRION}:${PUERTO}"
    echo "formato: pg_dump custom comprimido"
    echo ""
    echo "# SHA-256 de los archivos del respaldo"
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$(basename "$ARCHIVO")"
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$(basename "$ARCHIVO")"
    else
        echo "NINGUNO: ni sha256sum ni shasum están disponibles en este sistema."
    fi
} > "$MANIFIESTO"

# ---------------------------------------------------------------------------
# 5. Marcar el respaldo como completo SOLO al final (regla 2)
# ---------------------------------------------------------------------------
cat > "$MARCA_COMPLETO" <<EOF
Respaldo completo de ${BASE_DATOS}.
Fecha (UTC): $(date -u +%Y-%m-%dT%H:%M:%SZ)
Archivo:     $(basename "$ARCHIVO")
Manifiesto:  $(basename "$MANIFIESTO")

Para verificar antes de restaurar:
  cd ${DESTINO} && sha256sum -c $(basename "$MANIFIESTO")
EOF

echo "[respaldo] terminado."
echo "[respaldo] archivo:    $ARCHIVO"
echo "[respaldo] manifiesto: $MANIFIESTO"
echo "[respaldo] completo:   $MARCA_COMPLETO"