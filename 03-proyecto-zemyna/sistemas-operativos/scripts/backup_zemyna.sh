#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

CONFIG_FILE="${ZEMYNA_BACKUP_CONFIG:-/etc/zemyna/backup.conf}"
if [[ ! -r "$CONFIG_FILE" ]]; then
    printf 'ERROR: no se puede leer la configuracion de backup.\n' >&2
    exit 78
fi
# shellcheck source=/dev/null
source "$CONFIG_FILE"

: "${PROJECT_DIR:=/var/www/html/03-proyecto-zemyna/programacion}"
: "${COMPOSE_PROJECT_NAME:=zemyna_compose_test}"
: "${COMPOSE_FILE:=compose.yaml}"
: "${ENV_FILE:=.env.docker}"
: "${BACKUP_DIR:=/var/backups/zemyna}"
: "${LOG_FILE:=/var/log/zemyna/backup.log}"
: "${RETENTION_DAYS:=7}"

[[ "$COMPOSE_PROJECT_NAME" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]] || {
    printf 'ERROR: COMPOSE_PROJECT_NAME no es valido.\n' >&2; exit 78;
}
[[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || {
    printf 'ERROR: RETENTION_DAYS debe ser un entero no negativo.\n' >&2; exit 78;
}

resolve_project_path() {
    [[ "$1" = /* ]] && printf '%s\n' "$1" || printf '%s/%s\n' "${PROJECT_DIR%/}" "$1"
}
COMPOSE_FILE_PATH="$(resolve_project_path "$COMPOSE_FILE")"
ENV_FILE_PATH="$(resolve_project_path "$ENV_FILE")"
[[ -d "$PROJECT_DIR" && -r "$COMPOSE_FILE_PATH" && -r "$ENV_FILE_PATH" ]] || {
    printf 'ERROR: proyecto, Compose o archivo de entorno no disponibles.\n' >&2; exit 78;
}

create_private_directory() {
    mkdir -p -- "$1"
    chmod 700 -- "$1"
}
create_private_directory "$BACKUP_DIR"
create_private_directory "$(dirname "$LOG_FILE")"
touch -- "$LOG_FILE"
chmod 600 -- "$LOG_FILE"

log_event() {
    printf '%s level=%s message=%q\n' \
        "$(TZ=America/Montevideo date --iso-8601=seconds)" "$1" "$2" >> "$LOG_FILE"
}

LOCK_FILE="${BACKUP_DIR%/}/.backup.lock"
exec 9>"$LOCK_FILE"
chmod 600 -- "$LOCK_FILE"
if ! flock -n 9; then
    log_event WARN "backup omitido: existe otra ejecucion en curso"
    exit 75
fi

TEMP_FILE=""
cleanup() {
    local exit_code="$1"
    [[ -z "$TEMP_FILE" || ! -f "$TEMP_FILE" ]] || rm -f -- "$TEMP_FILE"
    (( exit_code == 0 )) || log_event ERROR "backup finalizado con error"
}
trap 'cleanup "$?"' EXIT
trap 'exit 1' ERR

is_valid_backup() {
    [[ -s "$1" ]] || return 1
    gzip -t -- "$1" || return 1
    gzip -cd -- "$1" | awk '
        /^CREATE TABLE / { create_table = 1 }
        /^INSERT INTO / { insert_into = 1 }
        END { exit !(create_table && insert_into) }
    '
}

timestamp="$(TZ=America/Montevideo date '+%Y%m%d_%H%M%S')"
FINAL_FILE="${BACKUP_DIR%/}/zemyna_${timestamp}.sql.gz"
[[ ! -e "$FINAL_FILE" ]] || { log_event ERROR "backup final ya existente"; exit 73; }
TEMP_FILE="$(mktemp "${BACKUP_DIR%/}/.zemyna_${timestamp}_XXXXXX.sql.gz.tmp")"
log_event INFO "inicio de backup"

docker compose --project-name "$COMPOSE_PROJECT_NAME" \
    --env-file "$ENV_FILE_PATH" -f "$COMPOSE_FILE_PATH" exec -T db sh -eu -c '
        : "${MARIADB_DATABASE:?}"; : "${MARIADB_USER:?}"; : "${MARIADB_PASSWORD:?}"
        exec mariadb-dump --host=127.0.0.1 --user="$MARIADB_USER" \
            --password="$MARIADB_PASSWORD" --single-transaction --routines \
            --triggers --events --databases "$MARIADB_DATABASE"
    ' | gzip -c > "$TEMP_FILE"

is_valid_backup "$TEMP_FILE" || {
    log_event ERROR "el archivo temporal no supero las validaciones"; exit 65;
}
chmod 600 -- "$TEMP_FILE"
mv -- "$TEMP_FILE" "$FINAL_FILE"
TEMP_FILE=""
chmod 600 -- "$FINAL_FILE"

while IFS= read -r -d '' old_backup; do
    if is_valid_backup "$old_backup"; then
        rm -f -- "$old_backup"
        log_event INFO "backup vencido eliminado"
    else
        log_event WARN "backup vencido invalido conservado para revision"
    fi
done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'zemyna_*.sql.gz' \
    -mtime "+$RETENTION_DAYS" -print0)
log_event INFO "backup completado correctamente"
