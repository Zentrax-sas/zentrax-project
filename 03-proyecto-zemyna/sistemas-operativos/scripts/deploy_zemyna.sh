#!/bin/bash
set -Eeuo pipefail
umask 077

if [[ "$EUID" -ne 0 ]]; then
    printf 'ERROR: el despliegue debe ejecutarse como root.\n' >&2
    exit 77
fi

CONFIG_FILE="${ZEMYNA_DEPLOY_CONFIG:-/etc/zemyna/deploy.conf}"
if [[ ! -r "$CONFIG_FILE" || "$(stat -c '%a' "$CONFIG_FILE")" != "600" ]]; then
    printf 'ERROR: deploy.conf debe existir y tener permisos 600.\n' >&2
    exit 78
fi
# shellcheck source=/dev/null
source "$CONFIG_FILE"

: "${REPO_DIR:=}"
: "${APP_DIR:=}"
: "${DEPLOY_USER:=}"
: "${EXPECTED_ORIGIN:=}"
: "${COMPOSE_PROJECT_NAME:=}"
: "${COMPOSE_FILE:=}"
: "${ENV_FILE:=}"
: "${APP_HEALTH_URL:=}"
: "${LOG_FILE:=/var/log/zemyna/deploy.log}"
: "${HEALTH_TIMEOUT_SECONDS:=180}"

configuration_error() {
    printf 'ERROR: configuracion de despliegue invalida.\n' >&2
    exit 78
}

[[ -n "$REPO_DIR" && -n "$APP_DIR" && -n "$DEPLOY_USER" ]] || configuration_error
[[ -n "$EXPECTED_ORIGIN" && -n "$COMPOSE_PROJECT_NAME" ]] || configuration_error
[[ -n "$COMPOSE_FILE" && -n "$ENV_FILE" && -n "$APP_HEALTH_URL" ]] || configuration_error
[[ "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || configuration_error
[[ "$COMPOSE_PROJECT_NAME" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]] || configuration_error
[[ "$HEALTH_TIMEOUT_SECONDS" =~ ^[1-9][0-9]*$ ]] || configuration_error
[[ "$APP_HEALTH_URL" =~ ^http://(127\.0\.0\.1|localhost):[0-9]{1,5}/[^[:space:]]+$ ]] || configuration_error
[[ -d "$REPO_DIR/.git" && -d "$APP_DIR" ]] || configuration_error

REPO_DIR="$(realpath "$REPO_DIR")"
APP_DIR="$(realpath "$APP_DIR")"
[[ "$APP_DIR" == "$REPO_DIR/"* ]] || configuration_error

resolve_app_path() {
    [[ "$1" = /* ]] && printf '%s\n' "$1" || printf '%s/%s\n' "${APP_DIR%/}" "$1"
}
COMPOSE_FILE_PATH="$(resolve_app_path "$COMPOSE_FILE")"
ENV_FILE_PATH="$(resolve_app_path "$ENV_FILE")"
[[ -f "$COMPOSE_FILE_PATH" && -r "$COMPOSE_FILE_PATH" ]] || configuration_error
[[ -f "$ENV_FILE_PATH" && -r "$ENV_FILE_PATH" ]] || configuration_error

for command_name in git docker runuser flock curl realpath; do
    command -v "$command_name" >/dev/null 2>&1 || configuration_error
done
id "$DEPLOY_USER" >/dev/null 2>&1 || configuration_error

mkdir -p -- "$(dirname "$LOG_FILE")"
chmod 700 -- "$(dirname "$LOG_FILE")"
touch -- "$LOG_FILE"
chmod 600 -- "$LOG_FILE"

log_event() {
    printf '%s level=%s message=%q\n' \
        "$(TZ=America/Montevideo date --iso-8601=seconds)" "$1" "$2" >> "$LOG_FILE"
}

LOCK_FILE="/run/lock/zemyna-deploy.lock"
exec 9>"$LOCK_FILE"
chmod 600 -- "$LOCK_FILE"
if ! flock -n 9; then
    log_event WARN "despliegue omitido: existe otra ejecucion en curso"
    exit 75
fi

deploy_completed=false
on_exit() {
    local exit_code="$?"
    if [[ "$deploy_completed" != "true" ]]; then
        log_event ERROR "despliegue finalizado con error"
    fi
    exit "$exit_code"
}
trap on_exit EXIT

docker info >/dev/null
docker compose version >/dev/null

origin_url="$(runuser -u "$DEPLOY_USER" -- git -C "$REPO_DIR" remote get-url origin)"
[[ "$origin_url" == "$EXPECTED_ORIGIN" ]] || {
    log_event ERROR "el remoto origin no coincide con el repositorio esperado"
    exit 65
}

if [[ -n "$(runuser -u "$DEPLOY_USER" -- git -C "$REPO_DIR" status --porcelain --untracked-files=normal)" ]]; then
    log_event ERROR "el arbol Git contiene cambios locales"
    exit 65
fi

branch="$(runuser -u "$DEPLOY_USER" -- git -C "$REPO_DIR" symbolic-ref --quiet --short HEAD)"
[[ -n "$branch" ]] || {
    log_event ERROR "el repositorio no esta sobre una rama"
    exit 65
}

previous_commit="$(runuser -u "$DEPLOY_USER" -- git -C "$REPO_DIR" rev-parse HEAD)"
log_event INFO "inicio de despliegue commit_anterior=${previous_commit}"

runuser -u "$DEPLOY_USER" -- git -C "$REPO_DIR" fetch origin "$branch"
runuser -u "$DEPLOY_USER" -- git -C "$REPO_DIR" pull --ff-only origin "$branch"

new_commit="$(runuser -u "$DEPLOY_USER" -- git -C "$REPO_DIR" rev-parse HEAD)"
if [[ -n "$(runuser -u "$DEPLOY_USER" -- git -C "$REPO_DIR" status --porcelain --untracked-files=normal)" ]]; then
    log_event ERROR "el arbol Git dejo de estar limpio despues de actualizar"
    exit 65
fi
log_event INFO "codigo actualizado commit_nuevo=${new_commit}"

compose=(
    docker compose
    --project-name "$COMPOSE_PROJECT_NAME"
    --env-file "$ENV_FILE_PATH"
    -f "$COMPOSE_FILE_PATH"
)

"${compose[@]}" config --quiet
"${compose[@]}" up -d --build

service_is_healthy() {
    local service="$1"
    local container_id
    local state

    container_id="$("${compose[@]}" ps -q "$service")"
    [[ -n "$container_id" ]] || return 1
    state="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id")"
    [[ "$state" == "healthy" ]]
}

deadline=$((SECONDS + HEALTH_TIMEOUT_SECONDS))
while (( SECONDS < deadline )); do
    if service_is_healthy db && service_is_healthy app && service_is_healthy phpmyadmin; then
        break
    fi
    sleep 5
done

for service in db app phpmyadmin; do
    if ! service_is_healthy "$service"; then
        log_event ERROR "un servicio Docker no alcanzo estado healthy"
        exit 69
    fi
done

curl --fail --silent --show-error --location --max-time 15 \
    "$APP_HEALTH_URL" >/dev/null

deploy_completed=true
log_event INFO "despliegue completado commit_anterior=${previous_commit} commit_nuevo=${new_commit}"
