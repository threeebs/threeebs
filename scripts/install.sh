#!/usr/bin/env bash

set -Eeuo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$project_root"

fail() {
    printf 'Threeebs: %s\n' "$1" >&2
    exit 1
}

install_operator=true
case "${1:-}" in
    '') ;;
    --skip-operator) install_operator=false ;;
    *) fail 'uso: bash scripts/install.sh [--skip-operator]' ;;
esac
[[ $# -le 1 ]] || fail 'uso: bash scripts/install.sh [--skip-operator]'

command -v docker >/dev/null 2>&1 || fail 'Docker não foi encontrado.'
docker compose version >/dev/null 2>&1 || fail 'Docker Compose v2 não foi encontrado.'

if [[ ! -f .env ]]; then
    cp .env.example .env
    chmod 600 .env
    fail 'o arquivo .env foi criado. Configure-o e execute novamente.'
fi

bash scripts/sync-env.sh
chmod 600 .env
if grep -nE '=(TROQUE|CHANGE_ME|COLE_AQUI)' .env; then
    fail 'substitua todos os valores padrão exibidos no .env.'
fi

required_variables=(
    PROJECT_SLUG TZ APP_ENV BASE_DOMAIN PORTAL_URL ADMIN_URL SANDBOX_URL HOST_URL
    BIND_ADDRESS HOST_PORT PORTAL_PORT MYSQL_PORT REDIS_PORT ADMIN_PORT SANDBOX_PORT
    MYSQL_ROOT_PASSWORD THREEEBS_ADMIN_DB_USER THREEEBS_ADMIN_DB_PASSWORD
    THREEEBS_PORTAL_DB_USER THREEEBS_PORTAL_DB_PASSWORD
    THREEEBS_SANDBOX_DB_USER THREEEBS_SANDBOX_DB_PASSWORD
    THREEEBS_HOST_DB_USER THREEEBS_HOST_DB_PASSWORD
    THREEEBS_MIGRATOR_DB_USER THREEEBS_MIGRATOR_DB_PASSWORD
    PROJECTS_MYSQL_ROOT_PASSWORD REALTIME_INTERNAL_SECRET
    REDIS_PASSWORD THREEEBS_SETUP_KEY
)

for variable_name in "${required_variables[@]}"; do
    grep -qE "^${variable_name}=.+" .env || fail "a variável ${variable_name} está ausente ou vazia no .env."
done

docker compose config --quiet || fail 'a configuração do Docker Compose é inválida.'

startup_timeout="${THREEEBS_STARTUP_TIMEOUT_SECONDS:-180}"
[[ "$startup_timeout" =~ ^[1-9][0-9]{0,3}$ ]] \
    || fail 'THREEEBS_STARTUP_TIMEOUT_SECONDS precisa ser um inteiro entre 1 e 9999.'

install_user="${SUDO_USER:-$(id -un)}"
install_group="$(id -gn "$install_user")"

run_as_root() {
    if (( EUID == 0 )); then
        "$@"
        return
    fi
    command -v sudo >/dev/null 2>&1 \
        || fail 'a instalação requer privilégios administrativos e sudo não está disponível.'
    sudo "$@"
}

ensure_directory() {
    local mode="$1" directory="$2"
    [[ -d "$directory" ]] && return
    if ! install -d -m "$mode" "$directory" 2>/dev/null; then
        run_as_root install -d -m "$mode" -o "$install_user" -g "$install_group" "$directory"
    fi
}

for directory in \
    storage/projects \
    storage/logs/admin \
    storage/logs/portal \
    storage/logs/sandbox \
    storage/logs/host \
    storage/backups/database \
    storage/backups/projects; do
    ensure_directory 0775 "$directory"
done

ensure_directory 0700 storage/secrets
ensure_directory 0700 storage/secrets/environments

if [[ ! -O storage/secrets || ! -w storage/secrets ]] \
    || find storage/secrets ! -user "$install_user" -print -quit | grep -q .; then
    run_as_root chown -R "$install_user:$install_group" storage/secrets
fi
find storage/secrets -type d -exec chmod 0700 {} +
find storage/secrets -type f -exec chmod 0600 {} +

printf '%s\n' 'Threeebs: construindo e iniciando os serviços...'
docker compose up -d --build --wait --wait-timeout "$startup_timeout"

printf '%s\n' 'Threeebs: aplicando migrations e permissões idempotentes...'
migration_scripts=(
    scripts/apply-auth-access-upgrade.sh
    scripts/apply-partner-migration.sh
    scripts/apply-storage-migration.sh
    scripts/apply-project-plans-migration.sh
    scripts/apply-runtime-foundation-migration.sh
    scripts/apply-php-runtime-migration.sh
    scripts/apply-environment-operations-migration.sh
)
for migration_script in "${migration_scripts[@]}"; do
    bash "$migration_script"
done

docker compose exec -T admin sh -lc 'test -w /var/www/projects' \
    || fail 'o Admin não possui escrita em storage/projects.'
docker compose exec -T portal sh -lc 'test -w /var/www/projects' \
    || fail 'o Portal não possui escrita em storage/projects.'
docker compose exec -T sandbox sh -lc 'test -w /var/www/projects' \
    || fail 'o Sandbox não possui escrita em storage/projects.'
docker compose exec -T host sh -lc \
    'if su -s /bin/sh -c "touch /var/www/projects/.threeebs-host-write-test" www-data 2>/dev/null; then rm -f /var/www/projects/.threeebs-host-write-test; exit 1; fi' \
    || fail 'o Host possui escrita indevida em storage/projects.'

if [[ "$install_operator" == true ]]; then
    bash scripts/install-environment-operator.sh
else
    printf '%s\n' 'Threeebs: operador não instalado (--skip-operator); as operações do painel permanecerão na fila.' >&2
fi

if command -v git >/dev/null 2>&1; then
    git rev-parse HEAD > storage/secrets/deployed-revision
    chmod 0600 storage/secrets/deployed-revision
fi

printf '%s\n' 'Threeebs: instalação concluída com sucesso.'
docker compose ps
