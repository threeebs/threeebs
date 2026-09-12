#!/usr/bin/env bash

set -Eeuo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

root_mysql() {
    docker compose exec -T mysql sh -lc '
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql             --protocol=socket -uroot --default-character-set=utf8mb4
    '
}

root_mysql < database/threeebs_catalog/migrations/006_create_item_storage_limits.sql
root_mysql < database/threeebs_control/migrations/017_create_project_plans.sql

docker compose exec -T mysql sh -lc '
    set -eu
    for database_user in "$THREEEBS_ADMIN_DB_USER" "$THREEEBS_PORTAL_DB_USER"; do
        case "$database_user" in
            ""|*[!a-zA-Z0-9_]*) echo "Usuário MySQL inválido." >&2; exit 1 ;;
        esac
    done
    admin_account="$(printf "\\047%s\\047@\\047%%\\047" "$THREEEBS_ADMIN_DB_USER")"
    portal_account="$(printf "\\047%s\\047@\\047%%\\047" "$THREEEBS_PORTAL_DB_USER")"
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot -e "
GRANT SELECT, INSERT ON threeebs_catalog.itens TO ${admin_account};
GRANT SELECT, INSERT ON threeebs_catalog.precos TO ${admin_account};
GRANT SELECT, INSERT ON threeebs_catalog.item_limites_storage TO ${admin_account};
GRANT SELECT ON threeebs_control.projeto_storage_quotas TO ${portal_account};
GRANT SELECT ON threeebs_control.projeto_planos TO ${portal_account};
FLUSH PRIVILEGES;
"
'

echo "Threeebs: migrations e permissões de planos de storage aplicadas."
