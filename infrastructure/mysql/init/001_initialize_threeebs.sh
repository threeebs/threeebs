#!/usr/bin/env bash

# Executado pelo entrypoint oficial do MySQL somente com /var/lib/mysql vazio.
(
    set -Eeuo pipefail
    shopt -s nullglob

    required_variables=(
        MYSQL_ROOT_PASSWORD
        THREEEBS_ADMIN_DB_USER
        THREEEBS_ADMIN_DB_PASSWORD
        THREEEBS_PORTAL_DB_USER
        THREEEBS_PORTAL_DB_PASSWORD
        THREEEBS_SANDBOX_DB_USER
        THREEEBS_SANDBOX_DB_PASSWORD
        THREEEBS_HOST_DB_USER
        THREEEBS_HOST_DB_PASSWORD
        THREEEBS_MIGRATOR_DB_USER
        THREEEBS_MIGRATOR_DB_PASSWORD
    )

    for variable_name in "${required_variables[@]}"; do
        if [[ -z "${!variable_name:-}" ]]; then
            echo "Threeebs: variável obrigatória ausente: ${variable_name}" >&2
            exit 1
        fi
        if [[ "${!variable_name}" == CHANGE_ME* || "${!variable_name}" == TROQUE* || "${!variable_name}" == COLE_AQUI* ]]; then
            echo "Threeebs: substitua ${variable_name} no .env" >&2
            exit 1
        fi
    done

    database_users=(
        "$THREEEBS_ADMIN_DB_USER"
        "$THREEEBS_PORTAL_DB_USER"
        "$THREEEBS_SANDBOX_DB_USER"
        "$THREEEBS_HOST_DB_USER"
        "$THREEEBS_MIGRATOR_DB_USER"
    )
    for database_user in "${database_users[@]}"; do
        if [[ ! "$database_user" =~ ^[a-zA-Z0-9_]+$ ]]; then
            echo "Threeebs: usuário MySQL inválido: ${database_user}" >&2
            exit 1
        fi
    done
    if [[ "$(printf '%s\n' "${database_users[@]}" | sort -u | grep -c .)" != "5" ]]; then
        echo "Threeebs: cada serviço deve utilizar um usuário MySQL distinto." >&2
        exit 1
    fi

    sql_literal() {
        local value="$1"
        value="${value//\\/\\\\}"
        value="${value//\'/\'\'}"
        printf "'%s'" "$value"
    }

    admin_password_sql="$(sql_literal "$THREEEBS_ADMIN_DB_PASSWORD")"
    portal_password_sql="$(sql_literal "$THREEEBS_PORTAL_DB_PASSWORD")"
    sandbox_password_sql="$(sql_literal "$THREEEBS_SANDBOX_DB_PASSWORD")"
    host_password_sql="$(sql_literal "$THREEEBS_HOST_DB_PASSWORD")"
    migrator_password_sql="$(sql_literal "$THREEEBS_MIGRATOR_DB_PASSWORD")"

    root_mysql() {
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot --default-character-set=utf8mb4 "$@"
    }

    root_mysql < /opt/threeebs/database/bootstrap/001_create_databases.sql

    root_mysql <<SQL
CREATE USER IF NOT EXISTS '${THREEEBS_ADMIN_DB_USER}'@'%';
CREATE USER IF NOT EXISTS '${THREEEBS_PORTAL_DB_USER}'@'%';
CREATE USER IF NOT EXISTS '${THREEEBS_SANDBOX_DB_USER}'@'%';
CREATE USER IF NOT EXISTS '${THREEEBS_HOST_DB_USER}'@'%';
CREATE USER IF NOT EXISTS '${THREEEBS_MIGRATOR_DB_USER}'@'%';

ALTER USER '${THREEEBS_ADMIN_DB_USER}'@'%'
    IDENTIFIED WITH caching_sha2_password BY ${admin_password_sql};
ALTER USER '${THREEEBS_PORTAL_DB_USER}'@'%'
    IDENTIFIED WITH caching_sha2_password BY ${portal_password_sql};
ALTER USER '${THREEEBS_SANDBOX_DB_USER}'@'%'
    IDENTIFIED WITH caching_sha2_password BY ${sandbox_password_sql};
ALTER USER '${THREEEBS_HOST_DB_USER}'@'%'
    IDENTIFIED WITH caching_sha2_password BY ${host_password_sql};
ALTER USER '${THREEEBS_MIGRATOR_DB_USER}'@'%'
    IDENTIFIED WITH caching_sha2_password BY ${migrator_password_sql};

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON threeebs_identity.* TO '${THREEEBS_MIGRATOR_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON threeebs_control.* TO '${THREEEBS_MIGRATOR_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON threeebs_work.* TO '${THREEEBS_MIGRATOR_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON threeebs_catalog.* TO '${THREEEBS_MIGRATOR_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON threeebs_finance.* TO '${THREEEBS_MIGRATOR_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON threeebs_audit.* TO '${THREEEBS_MIGRATOR_DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL

    domains=(threeebs_identity threeebs_control threeebs_work threeebs_catalog threeebs_finance threeebs_audit)
    for domain in "${domains[@]}"; do
        for migration in "/opt/threeebs/database/${domain}/migrations/"*.sql; do
            echo "Threeebs migration: ${domain}/$(basename "$migration")"
            root_mysql < "$migration"
        done
    done

    for seed in /opt/threeebs/database/threeebs_control/seeds/*.sql; do
        echo "Threeebs seed: threeebs_control/$(basename "$seed")"
        root_mysql < "$seed"
    done

    root_mysql <<SQL
GRANT SELECT, INSERT, UPDATE ON threeebs_identity.* TO '${THREEEBS_ADMIN_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE ON threeebs_control.* TO '${THREEEBS_ADMIN_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE ON threeebs_work.* TO '${THREEEBS_ADMIN_DB_USER}'@'%';
GRANT SELECT, INSERT ON threeebs_catalog.itens TO '${THREEEBS_ADMIN_DB_USER}'@'%';
GRANT SELECT, INSERT ON threeebs_catalog.precos TO '${THREEEBS_ADMIN_DB_USER}'@'%';
GRANT SELECT, INSERT ON threeebs_catalog.item_limites_storage TO '${THREEEBS_ADMIN_DB_USER}'@'%';
GRANT INSERT ON threeebs_audit.eventos TO '${THREEEBS_ADMIN_DB_USER}'@'%';

GRANT SELECT ON threeebs_identity.usuarios TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT INSERT ON threeebs_identity.usuarios TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_identity.credenciais TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT INSERT ON threeebs_identity.credenciais TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT UPDATE ON threeebs_identity.credenciais TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE ON threeebs_identity.tokens TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT UPDATE ON threeebs_identity.sessoes TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE ON threeebs_identity.convites TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT, INSERT ON threeebs_identity.eventos_autenticacao TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT UPDATE (ultimo_login_em, bloqueado_ate, bloqueio_motivo, email_verificado_em)
    ON threeebs_identity.usuarios TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.clientes TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.cliente_usuarios TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.projetos TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.projeto_storage_quotas TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.projeto_planos TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.projeto_usuarios TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.ambientes TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.rotas_web TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.plataforma_usuarios TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT INSERT ON threeebs_control.interessados TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE ON threeebs_control.parceiro_candidaturas TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.parceiros TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT INSERT ON threeebs_control.clientes TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT INSERT ON threeebs_control.cliente_usuarios TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT INSERT ON threeebs_control.projetos TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT INSERT ON threeebs_control.projeto_usuarios TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT INSERT ON threeebs_control.ambientes TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT INSERT ON threeebs_control.ambiente_runtimes TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT INSERT ON threeebs_control.rotas_web TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.servidores TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT INSERT ON threeebs_audit.eventos TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_work.quadros TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_work.colunas TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_work.tarefas TO '${THREEEBS_PORTAL_DB_USER}'@'%';
GRANT SELECT ON threeebs_work.tarefa_responsaveis TO '${THREEEBS_PORTAL_DB_USER}'@'%';

GRANT SELECT ON threeebs_identity.usuarios TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT ON threeebs_identity.credenciais TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT, INSERT ON threeebs_identity.eventos_autenticacao TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT UPDATE (ultimo_login_em, bloqueado_ate, bloqueio_motivo)
    ON threeebs_identity.usuarios TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.clientes TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.cliente_usuarios TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.projetos TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.projeto_usuarios TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.ambientes TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.rotas_web TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.plataforma_usuarios TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE ON threeebs_control.projeto_storage_quotas TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON threeebs_control.projeto_arquivos TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE ON threeebs_control.projeto_storage_reservas TO '${THREEEBS_SANDBOX_DB_USER}'@'%';
GRANT INSERT ON threeebs_audit.eventos TO '${THREEEBS_SANDBOX_DB_USER}'@'%';

GRANT SELECT ON threeebs_control.rotas_web TO '${THREEEBS_HOST_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.ambientes TO '${THREEEBS_HOST_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.ambiente_runtimes TO '${THREEEBS_HOST_DB_USER}'@'%';
GRANT SELECT ON threeebs_control.projetos TO '${THREEEBS_HOST_DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL

    account_count="$(root_mysql -Nse "
        SELECT COUNT(*)
        FROM mysql.user
        WHERE Host = '%'
          AND User IN (
              '${THREEEBS_ADMIN_DB_USER}',
              '${THREEEBS_PORTAL_DB_USER}',
              '${THREEEBS_SANDBOX_DB_USER}',
              '${THREEEBS_HOST_DB_USER}',
              '${THREEEBS_MIGRATOR_DB_USER}'
          );
    ")"
    if [[ "$account_count" != "5" ]]; then
        echo "Threeebs: contas MySQL esperadas não foram criadas." >&2
        exit 1
    fi

    touch /var/lib/mysql/.threeebs_initialized
    echo "Threeebs: seis bancos, migrations, seed e permissões por serviço inicializados."
)
