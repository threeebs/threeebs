#!/usr/bin/env bash

set -Eeuo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

docker compose exec -T mysql sh -lc '
    set -eu
    case "$THREEEBS_PORTAL_DB_USER" in
        ""|*[!a-zA-Z0-9_]*) echo "Usuário MySQL do Portal inválido." >&2; exit 1 ;;
    esac
    account="$(printf "\\047%s\\047@\\047%%\\047" "$THREEEBS_PORTAL_DB_USER")"
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot -e "
GRANT SELECT, INSERT, UPDATE ON threeebs_identity.tokens TO ${account};
GRANT UPDATE ON threeebs_identity.credenciais TO ${account};
GRANT UPDATE ON threeebs_identity.sessoes TO ${account};
FLUSH PRIVILEGES;
"
'

printf '%s\n' 'Threeebs: permissões de reset de senha e primeiro acesso aplicadas.'
