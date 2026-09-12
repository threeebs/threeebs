#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

fail() {
    printf 'Threeebs: %s\n' "$1" >&2
    exit 1
}

[[ $# -eq 3 ]] || fail 'uso: bash scripts/provision-project-database.sh PROJETO_UUID AMBIENTE_UUID AMBIENTE_SLUG'

project_uuid="$1"
environment_uuid="$2"
environment_slug="$3"

uuid_pattern='^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
[[ "$project_uuid" =~ $uuid_pattern ]] || fail 'UUID do projeto inválido.'
[[ "$environment_uuid" =~ $uuid_pattern ]] || fail 'UUID do ambiente inválido.'
[[ "$environment_slug" =~ ^[a-z0-9][a-z0-9-]{0,49}$ ]] || fail 'slug do ambiente inválido.'

projects_root_line=''
if [[ -f .env ]]; then
    projects_root_line="$(sed -n 's/^PROJECTS_MYSQL_ROOT_PASSWORD=//p' .env | tail -n 1)"
fi
[[ -n "$projects_root_line" && "$projects_root_line" != TROQUE* ]] \
    || fail 'configure PROJECTS_MYSQL_ROOT_PASSWORD no .env.'

docker compose --profile runtime-foundation up -d projects-db

control_query() {
    docker compose exec -T mysql sh -lc '
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql \
            --protocol=socket -uroot --default-character-set=utf8mb4 -Nse "$1"
    ' sh "$1"
}

control_record="$(control_query "
SELECT CONCAT(p.uuid, ':', a.uuid, ':', a.slug)
  FROM threeebs_control.ambientes a
  JOIN threeebs_control.projetos p ON p.id = a.projeto_id
 WHERE p.uuid = '$project_uuid'
   AND a.uuid = '$environment_uuid'
   AND a.slug = '$environment_slug'
   AND p.status = 'ativo'
   AND a.status = 'ativo'
 LIMIT 1;
")"
[[ "$control_record" == "$project_uuid:$environment_uuid:$environment_slug" ]] \
    || fail 'projeto/ambiente ativo não encontrado ou relação inválida.'

environment_hex="${environment_uuid//-/}"
environment_digest="$(printf '%s' "$environment_uuid" | sha256sum)"
database_name="p_${environment_hex}"
database_user="u_${environment_digest:0:30}"
secret_directory="storage/secrets/environments/$environment_uuid"
secret_file="$secret_directory/database.env"
credential_ref="file:$secret_file"

prepare_secret_directory() {
    if install -d -m 0700 \
        storage/secrets storage/secrets/environments "$secret_directory" \
        2>/dev/null; then
        return
    fi

    local host_uid host_gid
    host_uid="$(id -u)"
    host_gid="$(id -g)"

    docker run --rm \
        --user 0:0 \
        --env "THREEEBS_HOST_UID=$host_uid" \
        --env "THREEEBS_HOST_GID=$host_gid" \
        --env "THREEEBS_ENVIRONMENT_UUID=$environment_uuid" \
        --volume "$project_root/storage:/storage" \
        mysql:8.4 \
        sh -lc '
            set -eu
            case "$THREEEBS_HOST_UID:$THREEEBS_HOST_GID" in
                *[!0-9:]*) echo "UID/GID inválido." >&2; exit 1 ;;
            esac
            case "$THREEEBS_ENVIRONMENT_UUID" in
                *[!0-9a-fA-F-]*) echo "UUID de ambiente inválido." >&2; exit 1 ;;
            esac
            secret_root=/storage/secrets
            environment_root="$secret_root/environments"
            secret_directory="$environment_root/$THREEEBS_ENVIRONMENT_UUID"
            install -d -m 0700 \
                "$secret_root" "$environment_root" "$secret_directory"
            chown "$THREEEBS_HOST_UID:$THREEEBS_HOST_GID" \
                "$secret_root" "$environment_root" "$secret_directory"
            chmod 0700 "$secret_root" "$environment_root" "$secret_directory"
        '

    [[ -d "$secret_directory" && -w "$secret_directory" ]] \
        || fail 'não foi possível preparar o diretório protegido de segredos.'
}

prepare_secret_directory

if [[ -f "$secret_file" ]]; then
    # O arquivo é gerado por este provisionador e contém somente valores com formato validado.
    # shellcheck disable=SC1090
    source "$secret_file"
    [[ "${DB_DATABASE:-}" == "$database_name" ]] || fail 'database.env pertence a outro database.'
    [[ "${DB_USERNAME:-}" == "$database_user" ]] || fail 'database.env pertence a outro usuário.'
    [[ "${DB_PASSWORD:-}" =~ ^[0-9a-f]{64}$ ]] || fail 'senha armazenada inválida.'
    database_password="$DB_PASSWORD"
else
    database_password="$(openssl rand -hex 32)"
    {
        printf 'DB_HOST=projects-db\n'
        printf 'DB_PORT=3306\n'
        printf 'DB_DATABASE=%s\n' "$database_name"
        printf 'DB_USERNAME=%s\n' "$database_user"
        printf 'DB_PASSWORD=%s\n' "$database_password"
    } > "$secret_file"
    chmod 0600 "$secret_file"
fi

docker compose exec -T projects-db sh -lc '
    set -eu
    database_name="$1"
    database_user="$2"
    database_password="$3"
    case "$database_name:$database_user:$database_password" in
        *[!a-zA-Z0-9_:]*) echo "Identificador ou segredo inválido." >&2; exit 1 ;;
    esac
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$database_name\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS '\''$database_user'\''@'\''%'\'';
ALTER USER '\''$database_user'\''@'\''%'\''
    IDENTIFIED WITH caching_sha2_password BY '\''$database_password'\'';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '\''$database_user'\''@'\''%'\'';
GRANT ALL PRIVILEGES ON \`$database_name\`.* TO '\''$database_user'\''@'\''%'\'';
FLUSH PRIVILEGES;
SQL
' sh "$database_name" "$database_user" "$database_password"

service_uuid="$(cat /proc/sys/kernel/random/uuid)"
control_query "
INSERT INTO threeebs_control.ambiente_runtimes
    (uuid, ambiente_id, tipo, execucao_habilitada, mount_target, status, configuracao)
SELECT UUID(), a.id, 'runtime.static', 0, '/var/www/project', 'planejado',
       JSON_OBJECT('document_root', '/var/www/project')
FROM threeebs_control.ambientes a
WHERE a.uuid = '$environment_uuid'
ON DUPLICATE KEY UPDATE
    mount_target = VALUES(mount_target);

INSERT INTO threeebs_control.servicos_ambiente
    (uuid, ambiente_id, tipo, nome, status, configuracao, credential_ref)
SELECT
    '$service_uuid',
    a.id,
    'database.mysql.shared',
    'default',
    'ativo',
    JSON_OBJECT(
        'host', 'projects-db',
        'port', 3306,
        'database', '$database_name',
        'username', '$database_user'
    ),
    '$credential_ref'
FROM threeebs_control.ambientes a
WHERE a.uuid = '$environment_uuid'
ON DUPLICATE KEY UPDATE
    status = VALUES(status),
    configuracao = VALUES(configuracao),
    credential_ref = VALUES(credential_ref);

INSERT INTO threeebs_control.ambiente_variaveis
    (uuid, ambiente_id, chave, valor_texto, secreto, credential_ref)
SELECT UUID(), a.id, values_to_add.chave, values_to_add.valor_texto, 0, NULL
FROM threeebs_control.ambientes a
JOIN (
    SELECT 'APP_ENV' chave, '$environment_slug' valor_texto
    UNION ALL SELECT 'DB_HOST', 'projects-db'
    UNION ALL SELECT 'DB_PORT', '3306'
    UNION ALL SELECT 'DB_DATABASE', '$database_name'
    UNION ALL SELECT 'DB_USERNAME', '$database_user'
) values_to_add
WHERE a.uuid = '$environment_uuid'
ON DUPLICATE KEY UPDATE
    valor_texto = VALUES(valor_texto),
    secreto = 0,
    credential_ref = NULL;

INSERT INTO threeebs_control.ambiente_variaveis
    (uuid, ambiente_id, chave, valor_texto, secreto, credential_ref)
SELECT UUID(), a.id, 'DB_PASSWORD', NULL, 1, '$credential_ref#DB_PASSWORD'
FROM threeebs_control.ambientes a
WHERE a.uuid = '$environment_uuid'
ON DUPLICATE KEY UPDATE
    valor_texto = NULL,
    secreto = 1,
    credential_ref = VALUES(credential_ref);
" >/dev/null

printf 'Threeebs: database provisionado para %s/%s.\n' "$project_uuid" "$environment_slug"
printf 'Threeebs: credencial armazenada em referência protegida; senha não exibida.\n'
