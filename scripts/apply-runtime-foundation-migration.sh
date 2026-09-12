#!/usr/bin/env bash

set -Eeuo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$project_root"

root_mysql() {
    docker compose exec -T mysql sh -lc '
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql \
            --protocol=socket \
            -uroot \
            --default-character-set=utf8mb4 "$@"
    ' sh "$@"
}

already_applied="$(root_mysql -Nse "
SELECT COUNT(*)
FROM threeebs_control.schema_migrations
WHERE migration='018_create_environment_runtime_foundation.sql';
")"

root_mysql < database/threeebs_control/migrations/018_create_environment_runtime_foundation.sql

docker compose exec -T mysql sh -lc '
    set -eu
    case "$THREEEBS_PORTAL_DB_USER" in
        ""|*[!a-zA-Z0-9_]*) echo "Usuário MySQL inválido." >&2; exit 1 ;;
    esac
    portal_account="$(printf "\\047%s\\047@\\047%%\\047" "$THREEEBS_PORTAL_DB_USER")"
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot -e "
GRANT INSERT ON threeebs_control.ambiente_runtimes TO ${portal_account};
FLUSH PRIVILEGES;
"
'

migration_count="$(root_mysql -Nse "
SELECT COUNT(*)
FROM threeebs_control.schema_migrations
WHERE migration='018_create_environment_runtime_foundation.sql';
")"
[[ "$migration_count" == 1 ]]

if [[ "$already_applied" == 0 ]]; then
    enabled_count="$(root_mysql -Nse "
SELECT COUNT(*)
FROM threeebs_control.ambiente_runtimes
WHERE execucao_habilitada <> 0;
")"
    [[ "$enabled_count" == 0 ]]
fi

printf '%s\n' 'Threeebs: fundação de runtime por ambiente aplicada; runtimes existentes foram preservados.'
