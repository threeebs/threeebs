#!/usr/bin/env bash

set -Eeuo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

docker compose exec -T mysql sh -lc '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql \
        --protocol=socket -uroot --default-character-set=utf8mb4
' < database/threeebs_control/migrations/016_create_project_storage.sql

docker compose exec -T mysql sh -lc '
    set -eu
    case "$THREEEBS_SANDBOX_DB_USER" in
        ""|*[!a-zA-Z0-9_]*) echo "Usuário MySQL do Sandbox inválido." >&2; exit 1 ;;
    esac
    account="$(printf "\\047%s\\047@\\047%%\\047" "$THREEEBS_SANDBOX_DB_USER")"
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot -e "
GRANT SELECT, INSERT, UPDATE ON threeebs_control.projeto_storage_quotas TO ${account};
GRANT SELECT, INSERT, UPDATE, DELETE ON threeebs_control.projeto_arquivos TO ${account};
GRANT SELECT, INSERT, UPDATE ON threeebs_control.projeto_storage_reservas TO ${account};
FLUSH PRIVILEGES;
"
'

docker compose exec -T sandbox php /dev/stdin < scripts/reconcile-storage.php

echo "Threeebs: migration, permissões mínimas e reconciliação de storage aplicadas."
