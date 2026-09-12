#!/usr/bin/env bash

set -Eeuo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

docker compose up -d mysql

docker compose exec -T mysql sh -lc '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql         --protocol=socket -uroot --default-character-set=utf8mb4
' < database/threeebs_control/migrations/020_create_environment_operations.sql

docker compose exec -T mysql sh -lc '
    set -eu
    case "$THREEEBS_SANDBOX_DB_USER" in
        ""|*[!a-zA-Z0-9_]*) echo "Usuário Sandbox inválido." >&2; exit 1 ;;
    esac
    account="$(printf "\047%s\047@\047%%\047" "$THREEEBS_SANDBOX_DB_USER")"
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot -e "
GRANT SELECT, INSERT ON threeebs_control.ambiente_operacoes TO ${account};
GRANT SELECT ON threeebs_control.ambiente_runtimes TO ${account};
GRANT SELECT ON threeebs_control.servicos_ambiente TO ${account};
FLUSH PRIVILEGES;
"
'

printf '%s\n' 'Threeebs: fila de operações de ambiente aplicada; nenhum provisionamento foi executado.'
