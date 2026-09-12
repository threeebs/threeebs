#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

fail() { printf 'Threeebs: %s\n' "$1" >&2; exit 1; }
[[ $# -eq 1 ]] || fail 'uso: bash scripts/apply-project-migrations.sh OPERACAO_UUID'
operation_uuid="$1"
uuid_pattern='^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
[[ "$operation_uuid" =~ $uuid_pattern ]] || fail 'UUID da operação inválido.'

env_value() { sed -n "s/^$1=//p" .env | tail -n1; }
project_slug="$(env_value PROJECT_SLUG)"
[[ "$project_slug" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || fail 'PROJECT_SLUG inválido.'

control_query() {
    docker compose exec -T mysql sh -lc '
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql             --protocol=socket -uroot --default-character-set=utf8mb4 --raw -Nse "$1"
    ' sh "$1"
}

record="$(control_query "
SELECT CONCAT_WS(CHAR(9),p.uuid,a.uuid,a.slug,a.tipo,a.diretorio)
FROM threeebs_control.ambiente_operacoes ao
JOIN threeebs_control.ambientes a ON a.id=ao.ambiente_id
JOIN threeebs_control.projetos p ON p.id=a.projeto_id
WHERE ao.uuid='$operation_uuid'
  AND ao.tipo='database.migrations.apply'
  AND ao.status='executando'
  AND a.status='ativo' AND p.status='ativo'
LIMIT 1;
")"
[[ -n "$record" ]] || fail 'operação de migrations executável não encontrada.'

IFS=$'\t' read -r project_uuid environment_uuid environment_slug environment_type relative_root <<< "$record"
[[ "$environment_type" == sandbox && "$environment_slug" == sandbox ]]     || fail 'migrations pelo painel são permitidas somente no Sandbox.'
[[ "$relative_root" == "$project_uuid/$environment_slug" ]] || fail 'diretório do ambiente não é canônico.'

environment_root="$project_root/storage/projects/$relative_root"
resolved_projects="$(realpath "$project_root/storage/projects")"
resolved_environment="$(realpath "$environment_root")"
[[ "$resolved_environment" == "$resolved_projects/$relative_root" ]] || fail 'storage do ambiente inválido.'
migrations_directory="$resolved_environment/database/migrations"
[[ -d "$migrations_directory" && ! -L "$migrations_directory" ]] || fail 'pasta database/migrations não encontrada.'

secret_file="$project_root/storage/secrets/environments/$environment_uuid/database.env"
[[ -f "$secret_file" && ! -L "$secret_file" ]] || fail 'database do ambiente ainda não foi provisionado.'
[[ "$(stat -c '%a' "$secret_file")" == 600 ]] || fail 'permissão insegura no database.env.'

database_network="threeebs-${project_slug}-projects-runtime-network"
docker compose --profile runtime-foundation up -d projects-db >/dev/null

mysql_run() {
    docker run --rm --interactive --read-only --cap-drop ALL         --security-opt no-new-privileges:true         --tmpfs /tmp:rw,nosuid,nodev,noexec,size=16m         --network "$database_network"         --env-file "$secret_file"         mysql:8.4 sh -lc '
            set -eu
            export MYSQL_PWD="$DB_PASSWORD"
            exec mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT"                 -u "$DB_USERNAME" "$DB_DATABASE" --default-character-set=utf8mb4 "$@"
        ' sh "$@"
}

mysql_run -e "
CREATE TABLE IF NOT EXISTS _threeebs_migrations (
    migration VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
" >/dev/null

manifest="$(control_query "
SELECT CONCAT_WS(CHAR(9),manifest.name,manifest.sha256)
FROM threeebs_control.ambiente_operacoes ao
JOIN JSON_TABLE(
    ao.payload,
    '$.migrations[*]' COLUMNS(
        name VARCHAR(100) PATH '$.name',
        sha256 CHAR(64) PATH '$.sha256'
    )
) AS manifest
WHERE ao.uuid='$operation_uuid'
ORDER BY manifest.name;
")"
[[ -n "$manifest" ]] || fail 'manifesto de migrations vazio.'

current_manifest="$(docker compose exec -T sandbox php -r '
require "/var/www/shared/bootstrap.php";
require "/var/www/shared/project_migrations.php";
foreach (project_migration_manifest($argv[1]) as $migration) {
    echo $migration["name"], "\t", $migration["sha256"], "\n";
}
' "/var/www/projects/$relative_root")"
[[ "$current_manifest" == "$manifest" ]]     || fail 'manifesto atual difere do manifesto validado ou viola a política.'

applied=0
while IFS=$'\t' read -r name expected_checksum; do
    [[ "$name" =~ ^[0-9]{14}_[a-z0-9][a-z0-9_]{0,80}\.sql$ ]]         || fail 'nome de migration inválido no manifesto.'
    [[ "$expected_checksum" =~ ^[0-9a-f]{64}$ ]] || fail 'checksum inválido no manifesto.'
    file="$migrations_directory/$name"
    [[ -f "$file" && ! -L "$file" ]] || fail "migration ausente: $name"
    actual_checksum="$(sha256sum "$file")"
    actual_checksum="${actual_checksum%% *}"
    [[ "$actual_checksum" == "$expected_checksum" ]] || fail "migration alterada após validação: $name"

    current_checksum="$(mysql_run -Nse "
SELECT checksum FROM _threeebs_migrations WHERE migration='$name' LIMIT 1;
")"
    if [[ -n "$current_checksum" ]]; then
        [[ "$current_checksum" == "$expected_checksum" ]]             || fail "migration já aplicada foi modificada: $name"
        continue
    fi

    mysql_run < "$file"
    mysql_run -e "
INSERT INTO _threeebs_migrations (migration,checksum)
VALUES ('$name','$expected_checksum');
" >/dev/null
    applied=$((applied + 1))
done <<< "$manifest"

printf 'Threeebs: %d migration(s) aplicada(s) em %s/sandbox.\n' "$applied" "$project_uuid"
