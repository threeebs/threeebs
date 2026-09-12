#!/usr/bin/env bash

set -Eeuo pipefail

: "${THREEEBS_SANDBOX_DB_USER:?THREEEBS_SANDBOX_DB_USER ausente}"
case "$THREEEBS_SANDBOX_DB_USER" in
    *[!a-zA-Z0-9_]*) printf '%s\n' 'Usuário Sandbox inválido.' >&2; exit 1 ;;
esac

account="$(printf "\047%s\047@\047%%\047" "$THREEEBS_SANDBOX_DB_USER")"
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot -e "
GRANT SELECT, INSERT ON threeebs_control.ambiente_operacoes TO ${account};
GRANT SELECT ON threeebs_control.ambiente_runtimes TO ${account};
GRANT SELECT ON threeebs_control.servicos_ambiente TO ${account};
FLUSH PRIVILEGES;
"
