#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

fail() { printf 'Threeebs: %s\n' "$1" >&2; exit 1; }
[[ $# -ge 3 && $# -le 4 ]] || fail 'uso: bash scripts/provision-php-runtime.sh PROJETO_UUID AMBIENTE_UUID AMBIENTE_SLUG [--enable-production]'

project_uuid="$1"
environment_uuid="$2"
environment_slug="$3"
production_confirmation="${4:-}"

uuid_pattern='^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
[[ "$project_uuid" =~ $uuid_pattern ]] || fail 'UUID do projeto inválido.'
[[ "$environment_uuid" =~ $uuid_pattern ]] || fail 'UUID do ambiente inválido.'
[[ "$environment_slug" =~ ^[a-z0-9][a-z0-9-]{0,49}$ ]] || fail 'slug do ambiente inválido.'

env_value() {
    sed -n "s/^$1=//p" .env | tail -n1
}

project_slug="$(env_value PROJECT_SLUG)"
runtime_memory="$(env_value PHP_RUNTIME_MEMORY)"
runtime_cpus="$(env_value PHP_RUNTIME_CPUS)"
runtime_pids_limit="$(env_value PHP_RUNTIME_PIDS_LIMIT)"
runtime_memory="${runtime_memory:-256m}"
runtime_cpus="${runtime_cpus:-0.50}"
runtime_pids_limit="${runtime_pids_limit:-64}"

[[ "$project_slug" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || fail 'PROJECT_SLUG inválido.'
[[ "$runtime_memory" =~ ^[1-9][0-9]*[mMgG]$ ]] || fail 'PHP_RUNTIME_MEMORY inválido.'
[[ "$runtime_cpus" =~ ^[0-9]+([.][0-9]+)?$ && "$runtime_cpus" != 0 && "$runtime_cpus" != 0.0 ]]     || fail 'PHP_RUNTIME_CPUS inválido.'
[[ "$runtime_pids_limit" =~ ^[0-9]+$ && "$runtime_pids_limit" -ge 16 && "$runtime_pids_limit" -le 1024 ]]     || fail 'PHP_RUNTIME_PIDS_LIMIT inválido.'

control_query() {
    docker compose exec -T mysql sh -lc '
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot             --default-character-set=utf8mb4 -Nse "$1"
    ' sh "$1"
}

bash scripts/apply-php-runtime-migration.sh
record="$(control_query "
SELECT CONCAT(p.uuid,':',a.uuid,':',a.slug,':',a.tipo)
FROM threeebs_control.ambientes a
JOIN threeebs_control.projetos p ON p.id=a.projeto_id
WHERE p.uuid='$project_uuid' AND a.uuid='$environment_uuid'
  AND a.slug='$environment_slug' AND p.status='ativo' AND a.status='ativo'
LIMIT 1;
")"
IFS=: read -r found_project found_environment found_slug environment_type <<< "$record"
[[ "$found_project:$found_environment:$found_slug" == "$project_uuid:$environment_uuid:$environment_slug" ]]     || fail 'projeto/ambiente ativo não encontrado.'
if [[ "$environment_type" == production && "$production_confirmation" != --enable-production ]]; then
    fail 'Production exige confirmação explícita: --enable-production'
fi
[[ "$environment_type" == sandbox || "$environment_type" == production ]] || fail 'tipo de ambiente inválido.'

relative_root="$project_uuid/$environment_slug"
environment_root="$project_root/storage/projects/$relative_root"
[[ -d "$environment_root" ]] || fail 'diretório do ambiente não existe.'
resolved_projects="$(realpath "$project_root/storage/projects")"
resolved_environment="$(realpath "$environment_root")"
[[ "$resolved_environment" == "$resolved_projects/$relative_root" ]] || fail 'mount do ambiente não é canônico.'

environment_hex="${environment_uuid//-/}"
container="threeebs-${project_slug}-php-${environment_hex}"
image="threeebs-${project_slug}-php-runtime:latest"
ingress_network="threeebs-${project_slug}-runtime-ingress-network"
database_network="threeebs-${project_slug}-projects-runtime-network"
secret_file="storage/secrets/environments/$environment_uuid/database.env"

docker compose up -d host
docker build --pull -f infrastructure/runtime/php/Dockerfile -t "$image" . \
    || fail "não foi possível construir a imagem do runtime PHP."

if docker container inspect "$container" >/dev/null 2>&1; then
    docker rm -f "$container" >/dev/null
fi

args=(
    --name "$container"
    --restart unless-stopped
    --label threeebs.managed=php-runtime
    --label "threeebs.project_uuid=$project_uuid"
    --label "threeebs.environment_uuid=$environment_uuid"
    --read-only
    --cap-drop ALL
    --security-opt no-new-privileges:true
    --memory "$runtime_memory"
    --cpus "$runtime_cpus"
    --pids-limit "$runtime_pids_limit"
    --tmpfs /tmp:rw,nosuid,nodev,noexec,size=32m,mode=1777
    --network "$ingress_network"
    --mount "type=bind,src=$resolved_environment,dst=/var/www/project,readonly"
    --env "APP_ENV=$environment_slug"
)
if [[ -f "$secret_file" ]]; then
    [[ "$(stat -c '%a' "$secret_file")" == 600 ]] || fail 'permissão insegura no database.env.'
    args+=(--env-file "$secret_file")
fi

docker create "${args[@]}" "$image" >/dev/null
cleanup_on_error=true
trap 'if [[ "${cleanup_on_error:-false}" == true ]]; then docker rm -f "$container" >/dev/null 2>&1 || true; fi' EXIT

if [[ -f "$secret_file" ]]; then
    docker compose --profile runtime-foundation up -d projects-db
    docker network connect "$database_network" "$container"
fi
docker start "$container" >/dev/null

health=starting
for _ in $(seq 1 30); do
    health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$container")"
    [[ "$health" == healthy ]] && break
    [[ "$health" == unhealthy ]] && break
    sleep 1
done
[[ "$health" == healthy ]] || fail "runtime não ficou saudável: $health"

state="$(docker inspect --format '{{.State.Status}}:{{.HostConfig.ReadonlyRootfs}}' "$container")"
[[ "$state" == running:true ]] || fail "runtime não iniciou com rootfs read-only: $state"

control_query "
UPDATE threeebs_control.ambiente_runtimes ar
JOIN threeebs_control.ambientes a ON a.id=ar.ambiente_id
SET ar.tipo='runtime.php',
    ar.execucao_habilitada=1,
    ar.status='ativo',
    ar.configuracao=JSON_OBJECT(
        'document_root','/var/www/project',
        'container','$container',
        'image','$image'
    )
WHERE a.uuid='$environment_uuid' AND a.projeto_id=(
    SELECT id FROM threeebs_control.projetos WHERE uuid='$project_uuid'
);
" >/dev/null

cleanup_on_error=false
trap - EXIT
printf 'Threeebs: runtime PHP isolado ativo para %s/%s em %s.\n' "$project_uuid" "$environment_slug" "$container"
