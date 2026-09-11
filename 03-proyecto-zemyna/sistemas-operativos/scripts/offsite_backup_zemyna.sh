#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

CONFIG_FILE="${ZEMYNA_OFFSITE_CONFIG:-/etc/zemyna/offsite-backup.conf}"
if [[ ! -r "$CONFIG_FILE" || "$(stat -c '%a' "$CONFIG_FILE")" != "600" ]]; then
    printf 'ERROR: la configuracion offsite debe existir y tener permisos 600.\n' >&2
    exit 78
fi
# shellcheck source=/dev/null
source "$CONFIG_FILE"

: "${BACKUP_DIR:=/var/backups/zemyna}"
: "${RCLONE_CONFIG:=/etc/zemyna/rclone.conf}"
: "${REMOTE_NAME:=}"
: "${REMOTE_BASE_PATH:=}"
: "${LOG_FILE:=/var/log/zemyna/offsite-backup.log}"
: "${ENABLE_REMOTE_RETENTION:=false}"
: "${DAILY_RETENTION_DAYS:=7}"
: "${WEEKLY_RETENTION_WEEKS:=5}"
: "${MONTHLY_RETENTION_DAYS:=365}"

fail_configuration() {
    printf 'ERROR: configuracion offsite invalida.\n' >&2
    exit 78
}

[[ -d "$BACKUP_DIR" ]] || fail_configuration
[[ -r "$RCLONE_CONFIG" && "$(stat -c '%a' "$RCLONE_CONFIG")" == "600" ]] || fail_configuration
[[ "$REMOTE_NAME" =~ ^[A-Za-z0-9][A-Za-z0-9_-]*$ ]] || fail_configuration
REMOTE_BASE_PATH="${REMOTE_BASE_PATH#/}"
REMOTE_BASE_PATH="${REMOTE_BASE_PATH%/}"
[[ -n "$REMOTE_BASE_PATH" && "$REMOTE_BASE_PATH" != "." ]] || fail_configuration
[[ "$REMOTE_BASE_PATH" != *..* && "$REMOTE_BASE_PATH" != *:* ]] || fail_configuration
[[ "$ENABLE_REMOTE_RETENTION" == "true" || "$ENABLE_REMOTE_RETENTION" == "false" ]] || fail_configuration
[[ "$DAILY_RETENTION_DAYS" =~ ^[1-9][0-9]*$ ]] || fail_configuration
[[ "$WEEKLY_RETENTION_WEEKS" =~ ^[1-9][0-9]*$ ]] || fail_configuration
[[ "$MONTHLY_RETENTION_DAYS" =~ ^[1-9][0-9]*$ ]] || fail_configuration

mkdir -p -- "$(dirname "$LOG_FILE")"
chmod 700 -- "$(dirname "$LOG_FILE")"
touch -- "$LOG_FILE"
chmod 600 -- "$LOG_FILE"

log_event() {
    printf '%s level=%s message=%q\n' \
        "$(TZ=America/Montevideo date --iso-8601=seconds)" "$1" "$2" >> "$LOG_FILE"
}

LOCK_FILE="/run/lock/zemyna-offsite-backup.lock"
exec 9>"$LOCK_FILE"
chmod 600 -- "$LOCK_FILE"
if ! flock -n 9; then
    log_event WARN "copia offsite omitida: existe otra ejecucion en curso"
    exit 75
fi

manifest_temp=""
cleanup() {
    local exit_code="$?"
    [[ -z "$manifest_temp" || ! -f "$manifest_temp" ]] || rm -f -- "$manifest_temp"
    (( exit_code == 0 )) || log_event ERROR "copia offsite finalizada con error"
}
trap cleanup EXIT

latest_backup=""
shopt -s nullglob
for candidate in "$BACKUP_DIR"/zemyna_*.sql.gz; do
    [[ -z "$latest_backup" || "$candidate" -nt "$latest_backup" ]] && latest_backup="$candidate"
done
shopt -u nullglob
[[ -n "$latest_backup" ]] || { log_event ERROR "no hay backup local para subir"; exit 66; }

validate_backup() {
    [[ -s "$1" ]] || return 1
    gzip -t -- "$1" || return 1
    gzip -cd -- "$1" | awk '
        /^CREATE TABLE / { create_table = 1 }
        /^INSERT INTO / { insert_into = 1 }
        END { exit !(create_table && insert_into) }
    '
}

validate_backup "$latest_backup" || {
    log_event ERROR "el backup local mas reciente no es valido"
    exit 65
}

backup_name="$(basename "$latest_backup")"
manifest_file="${latest_backup}.sha256"
manifest_temp="$(mktemp "${BACKUP_DIR%/}/.offsite_manifest_XXXXXX.tmp")"
(
    cd -- "$(dirname "$latest_backup")"
    sha256sum -- "$backup_name"
) > "$manifest_temp"
chmod 600 -- "$manifest_temp"
mv -- "$manifest_temp" "$manifest_file"
chmod 600 -- "$manifest_file"

rclone_copy_pair() {
    local destination="$1"
    rclone --config "$RCLONE_CONFIG" copyto "$latest_backup" \
        "${REMOTE_NAME}:${REMOTE_BASE_PATH}/${destination}/${backup_name}"
    rclone --config "$RCLONE_CONFIG" copyto "$manifest_file" \
        "${REMOTE_NAME}:${REMOTE_BASE_PATH}/${destination}/${backup_name}.sha256"
}

calendar_date="$(TZ=America/Montevideo date '+%Y-%m-%d')"
rclone_copy_pair "daily/${calendar_date}"

if [[ "$(TZ=America/Montevideo date '+%u')" == "7" ]]; then
    rclone_copy_pair "weekly/$(TZ=America/Montevideo date '+%G-W%V')"
fi

if [[ "$(TZ=America/Montevideo date '+%d')" == "01" ]]; then
    rclone_copy_pair "monthly/$(TZ=America/Montevideo date '+%Y-%m')"
fi

if [[ "$ENABLE_REMOTE_RETENTION" == "true" ]]; then
    weekly_days=$((WEEKLY_RETENTION_WEEKS * 7))
    for retention in \
        "daily:${DAILY_RETENTION_DAYS}d" \
        "weekly:${weekly_days}d" \
        "monthly:${MONTHLY_RETENTION_DAYS}d"; do
        category="${retention%%:*}"
        minimum_age="${retention#*:}"
        rclone --config "$RCLONE_CONFIG" delete \
            "${REMOTE_NAME}:${REMOTE_BASE_PATH}/${category}" \
            --min-age "$minimum_age" --include '*.sql.gz' --include '*.sha256' \
            --drive-use-trash=true
    done
fi

log_event INFO "copia offsite completada correctamente"
