#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$project_root"

fail() {
    printf 'Threeebs: %s\n' "$1" >&2
    exit 1
}

check_only=false
install_operator=true
case "${1:-}" in
    '') ;;
    --check) check_only=true ;;
    --skip-operator) install_operator=false ;;
    *) fail 'uso: bash scripts/update.sh [--check|--skip-operator]' ;;
esac
[[ $# -le 1 ]] || fail 'uso: bash scripts/update.sh [--check|--skip-operator]'

command -v git >/dev/null 2>&1 || fail 'Git não foi encontrado.'
command -v docker >/dev/null 2>&1 || fail 'Docker não foi encontrado.'
docker compose version >/dev/null 2>&1 || fail 'Docker Compose v2 não foi encontrado.'
[[ -f .env && ! -L .env ]] || fail 'arquivo .env não encontrado ou inválido.'

if ! git diff --quiet || ! git diff --cached --quiet; then
    fail 'existem alterações rastreadas no repositório; preserve-as antes da atualização.'
fi

for script in \
    scripts/install.sh \
    scripts/sync-env.sh \
    scripts/backup.sh \
    scripts/install-environment-operator.sh; do
    bash -n "$script"
done

if [[ "$check_only" == true ]]; then
    bash scripts/sync-env.sh --check
    docker compose config --quiet
    docker compose ps
    printf '%s\n' 'Threeebs: pré-validação da atualização concluída; nenhuma alteração foi aplicada.'
    exit 0
fi

sync_output="$(bash scripts/sync-env.sh)"
printf '%s\n' "$sync_output"
env_before_sync="$(sed -n 's/^THREEEBS_ENV_BACKUP=//p' <<< "$sync_output" | tail -n1)"
docker compose config --quiet || fail 'a configuração do Docker Compose é inválida após sincronizar o .env.'

previous_revision="$(cat storage/secrets/deployed-revision 2>/dev/null || printf unknown)"
backup_output="$(bash scripts/backup.sh)"
backup_path="$(sed -n 's/^THREEEBS_BACKUP_PATH=//p' <<< "$backup_output" | tail -n1)"
[[ -n "$backup_path" && -d "$backup_path" ]] || fail 'o backup pré-atualização não foi confirmado.'
printf '%s\n' "$backup_output"
if [[ -n "$env_before_sync" && -f "$env_before_sync" ]]; then
    install -m 0600 "$env_before_sync" "$backup_path/environment-before-sync.env"
    (
        cd "$backup_path"
        sha256sum environment-before-sync.env >> SHA256SUMS
    )
fi

project_slug="$(sed -n 's/^PROJECT_SLUG=//p' .env | tail -n1)"
[[ "$project_slug" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || fail 'PROJECT_SLUG inválido.'
operator_service="threeebs-${project_slug}-environment-operator.service"
operator_was_active=false

run_as_root() {
    if (( EUID == 0 )); then
        "$@"
    else
        command -v sudo >/dev/null 2>&1 || fail 'sudo não foi encontrado.'
        sudo "$@"
    fi
}

restore_operator_on_failure() {
    status="$?"
    if (( status != 0 )) && [[ "$operator_was_active" == true ]]; then
        run_as_root systemctl start "$operator_service" >/dev/null 2>&1 || true
        printf 'Threeebs: atualização interrompida; operador anterior reiniciado. Backup: %s\n' "$backup_path" >&2
    fi
    exit "$status"
}
trap restore_operator_on_failure EXIT

if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet "$operator_service"; then
    operator_was_active=true
    run_as_root systemctl stop "$operator_service"
fi

install_arguments=()
[[ "$install_operator" == true ]] || install_arguments+=(--skip-operator)
bash scripts/install.sh "${install_arguments[@]}"

core_services=(mysql redis portal admin sandbox host realtime)
for service in "${core_services[@]}"; do
    [[ -n "$(docker compose ps --status running -q "$service")" ]] \
        || fail "serviço não está em execução após a atualização: $service"
done

migration_state="$(docker compose exec -T mysql sh -lc '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot --raw -Nse "
SELECT IF(COUNT(*) = 6, 1, 0)
FROM threeebs_control.schema_migrations
WHERE migration REGEXP '\''^(015|016|017|018|019|020)_'\'';
SELECT IF(COUNT(*) = 1, 1, 0)
FROM threeebs_catalog.schema_migrations
WHERE migration = '\''006_create_item_storage_limits.sql'\'';
"
')"
[[ "$migration_state" == $'1\n1' ]] || fail 'as migrations esperadas não foram confirmadas.'

for service in mysql redis realtime; do
    container_id="$(docker compose ps -q "$service")"
    health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$container_id")"
    [[ "$health" == healthy ]] || fail "healthcheck não aprovado para $service: $health"
done

if [[ "$install_operator" == true ]]; then
    systemctl is-active --quiet "$operator_service" || fail "operador não está ativo: $operator_service"
    systemctl is-enabled --quiet "$operator_service" || fail "operador não está habilitado: $operator_service"
fi

trap - EXIT
current_revision="$(git rev-parse HEAD)"
printf 'Threeebs: atualização concluída de %s para %s.\n' "$previous_revision" "$current_revision"
printf 'Threeebs: backup pré-atualização: %s\n' "$backup_path"
