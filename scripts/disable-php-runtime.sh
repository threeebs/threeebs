#!/usr/bin/env bash
set -Eeuo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"
fail() { printf 'Threeebs: %s\n' "$1" >&2; exit 1; }
[[ $# -eq 1 ]] || fail 'uso: bash scripts/disable-php-runtime.sh AMBIENTE_UUID'
environment_uuid="$1"
[[ "$environment_uuid" =~ ^[0-9a-fA-F-]{36}$ ]] || fail 'UUID do ambiente inválido.'
project_slug="$(sed -n 's/^PROJECT_SLUG=//p' .env | tail -n1)"
[[ "$project_slug" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || fail 'PROJECT_SLUG inválido.'
environment_hex="${environment_uuid//-/}"
container="threeebs-${project_slug}-php-${environment_hex}"

if docker container inspect "$container" >/dev/null 2>&1; then
    docker rm -f "$container" >/dev/null
fi

docker compose exec -T mysql sh -lc '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot         --default-character-set=utf8mb4 -e "$1"
' sh "
UPDATE threeebs_control.ambiente_runtimes ar
JOIN threeebs_control.ambientes a ON a.id=ar.ambiente_id
SET ar.execucao_habilitada=0,ar.status='planejado',
    ar.configuracao=JSON_OBJECT('document_root','/var/www/project')
WHERE a.uuid='$environment_uuid';
"

printf 'Threeebs: runtime PHP desativado para %s.\n' "$environment_uuid"
