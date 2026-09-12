#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$project_root"

fail() {
    printf 'Threeebs: %s\n' "$1" >&2
    exit 1
}

env_value() {
    sed -n "s/^$1=//p" .env | tail -n1
}

backup_root=''
case "${1:-}" in
    '') ;;
    --output-dir)
        [[ $# -eq 2 ]] || fail 'uso: bash scripts/backup.sh [--output-dir DIRETORIO]'
        backup_root="$2"
        ;;
    *) fail 'uso: bash scripts/backup.sh [--output-dir DIRETORIO]' ;;
esac

command -v docker >/dev/null 2>&1 || fail 'Docker não foi encontrado.'
docker compose version >/dev/null 2>&1 || fail 'Docker Compose v2 não foi encontrado.'
command -v tar >/dev/null 2>&1 || fail 'tar não foi encontrado.'
command -v gzip >/dev/null 2>&1 || fail 'gzip não foi encontrado.'
command -v sha256sum >/dev/null 2>&1 || fail 'sha256sum não foi encontrado.'
[[ -f .env && ! -L .env ]] || fail 'arquivo .env não encontrado ou inválido.'

project_slug="$(env_value PROJECT_SLUG)"
[[ "$project_slug" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || fail 'PROJECT_SLUG inválido.'
docker compose config --quiet || fail 'a configuração do Docker Compose é inválida.'

if [[ -z "$backup_root" ]]; then
    backup_root="${THREEEBS_BACKUP_ROOT:-$(dirname -- "$project_root")/backups/threeebs-$project_slug}"
fi
[[ "$backup_root" == /* ]] || backup_root="$project_root/$backup_root"
mkdir -p -- "$backup_root"
chmod 0700 "$backup_root"
backup_root="$(cd -- "$backup_root" && pwd -P)"

case "$backup_root" in
    "$project_root"|"$project_root"/*) fail 'o diretório de backup precisa ficar fora do repositório.' ;;
esac

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
revision="$(git rev-parse --short=12 HEAD 2>/dev/null || printf unknown)"
final_directory="$backup_root/${timestamp}-${revision}"
[[ ! -e "$final_directory" ]] || fail "backup já existe: $final_directory"
temporary_directory="$(mktemp -d "$backup_root/.threeebs-backup.XXXXXX")"
trap 'rm -rf -- "$temporary_directory"' EXIT

install -m 0600 .env "$temporary_directory/environment.env"
{
    printf 'created_at=%s\n' "$timestamp"
    printf 'git_revision=%s\n' "$revision"
    printf 'project_slug=%s\n' "$project_slug"
    printf 'project_root=%s\n' "$project_root"
} > "$temporary_directory/metadata.txt"

mysql_container="$(docker compose ps --status running -q mysql)"
[[ -n "$mysql_container" ]] || fail 'o MySQL principal precisa estar em execução para criar o backup.'
docker compose exec -T mysql sh -lc '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump \
        --protocol=socket -uroot --all-databases --single-transaction \
        --no-tablespaces --routines --triggers --events
' > "$temporary_directory/mysql-control.sql"
[[ -s "$temporary_directory/mysql-control.sql" ]] || fail 'o dump do MySQL principal ficou vazio.'

database_services="$(docker compose exec -T mysql sh -lc '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot --raw -Nse "
SELECT COUNT(*)
FROM threeebs_control.servicos_ambiente
WHERE tipo = '\''database.mysql.shared'\'' AND status = '\''ativo'\'';
" 2>/dev/null || printf 0
')"

projects_db_container="$(docker compose --profile runtime-foundation ps --status running -q projects-db)"
if [[ "$database_services" =~ ^[0-9]+$ ]] && (( database_services > 0 )); then
    [[ -n "$projects_db_container" ]] \
        || fail 'existem databases de projetos, mas projects-db não está em execução; inicie-o antes do backup.'
fi
if [[ -n "$projects_db_container" ]]; then
    docker compose --profile runtime-foundation exec -T projects-db sh -lc '
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump \
            --protocol=socket -uroot --all-databases --single-transaction \
            --no-tablespaces --routines --triggers --events
    ' > "$temporary_directory/mysql-projects.sql"
    [[ -s "$temporary_directory/mysql-projects.sql" ]] || fail 'o dump do MySQL de projetos ficou vazio.'
fi

storage_items=()
[[ -d storage/projects ]] && storage_items+=(storage/projects)
[[ -d storage/secrets ]] && storage_items+=(storage/secrets)
if [[ ${#storage_items[@]} -gt 0 ]]; then
    if ! tar --acls --xattrs -czf "$temporary_directory/project-storage.tar.gz" "${storage_items[@]}" 2>/dev/null; then
        command -v sudo >/dev/null 2>&1 || fail 'não foi possível ler o storage para o backup.'
        sudo tar --acls --xattrs -czf "$temporary_directory/project-storage.tar.gz" "${storage_items[@]}"
        sudo chown "$(id -u):$(id -g)" "$temporary_directory/project-storage.tar.gz"
    fi
    gzip -t "$temporary_directory/project-storage.tar.gz"
fi

(
    cd "$temporary_directory"
    sha256sum environment.env metadata.txt mysql-control.sql \
        ${projects_db_container:+mysql-projects.sql} \
        ${storage_items[*]:+project-storage.tar.gz} > SHA256SUMS
)
chmod 0600 "$temporary_directory"/*
mv -- "$temporary_directory" "$final_directory"
trap - EXIT

printf 'THREEEBS_BACKUP_PATH=%s\n' "$final_directory"
