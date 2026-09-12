#!/usr/bin/env bash
set -Eeuo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

docker compose up -d mysql

docker compose exec -T mysql sh -lc '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql         --protocol=socket -uroot --default-character-set=utf8mb4
' < database/threeebs_control/migrations/019_enable_isolated_php_runtime.sql

docker compose exec -T mysql sh -lc '
    set -eu
    case "$THREEEBS_HOST_DB_USER" in
        ""|*[!a-zA-Z0-9_]*) echo "Usuário Host inválido." >&2; exit 1 ;;
    esac
    account="$(printf "\047%s\047@\047%%\047" "$THREEEBS_HOST_DB_USER")"
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot -e "
GRANT SELECT ON threeebs_control.ambiente_runtimes TO ${account};
FLUSH PRIVILEGES;
"
'

printf '%s\n' 'Threeebs: migration da execução PHP isolada aplicada; nenhum runtime foi ativado automaticamente.'
