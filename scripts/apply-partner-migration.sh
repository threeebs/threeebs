#!/usr/bin/env bash

set -Eeuo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

docker compose exec -T mysql sh -lc '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql \
        --protocol=socket -uroot --default-character-set=utf8mb4
' < database/threeebs_control/migrations/015_create_parceiros.sql

docker compose exec -T mysql sh -lc '
    set -eu
    case "$THREEEBS_PORTAL_DB_USER" in
        ""|*[!a-zA-Z0-9_]*) echo "Usuário MySQL do Portal inválido." >&2; exit 1 ;;
    esac
    account="$(printf "\\047%s\\047@\\047%%\\047" "$THREEEBS_PORTAL_DB_USER")"
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot -e "
GRANT INSERT ON threeebs_identity.usuarios TO ${account};
GRANT UPDATE (email_verificado_em) ON threeebs_identity.usuarios TO ${account};
GRANT INSERT ON threeebs_identity.credenciais TO ${account};
GRANT SELECT, INSERT, UPDATE ON threeebs_identity.convites TO ${account};
GRANT SELECT, INSERT, UPDATE ON threeebs_control.parceiro_candidaturas TO ${account};
GRANT SELECT ON threeebs_control.parceiros TO ${account};
GRANT INSERT ON threeebs_control.clientes TO ${account};
GRANT INSERT ON threeebs_control.cliente_usuarios TO ${account};
GRANT INSERT ON threeebs_control.projetos TO ${account};
GRANT INSERT ON threeebs_control.projeto_usuarios TO ${account};
GRANT INSERT ON threeebs_control.ambientes TO ${account};
GRANT INSERT ON threeebs_control.rotas_web TO ${account};
GRANT SELECT ON threeebs_control.servidores TO ${account};
GRANT INSERT ON threeebs_audit.eventos TO ${account};
FLUSH PRIVILEGES;
"
'

printf '%s\n' 'Threeebs: migration e permissões de parceiros aplicadas.'
