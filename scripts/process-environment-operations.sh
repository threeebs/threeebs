#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

mode="${1:---once}"
[[ "$mode" == --once || "$mode" == --watch ]] || {
    printf '%s\n' 'uso: bash scripts/process-environment-operations.sh [--once|--watch]' >&2
    exit 1
}

control_query() {
    docker compose exec -T mysql sh -lc '
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql             --protocol=socket -uroot --default-character-set=utf8mb4 --raw -Nse "$1"
    ' sh "$1"
}

sql_quote() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\'/\'\'}"
    printf '%s' "$value"
}

complete_job() {
    local job_uuid="$1" status="$2" error_code="$3" result_json="$4"
    control_query "
UPDATE threeebs_control.ambiente_operacoes
SET status='$(sql_quote "$status")',
    erro_codigo=$( [[ -n "$error_code" ]] && printf "'%s'" "$(sql_quote "$error_code")" || printf NULL ),
    resultado=CAST('$(sql_quote "$result_json")' AS JSON),
    concluido_em=UTC_TIMESTAMP(6),
    worker_token=NULL
WHERE uuid='$(sql_quote "$job_uuid")' AND status='executando';

INSERT INTO threeebs_audit.eventos
    (uuid,ator_tipo,ator_uuid,origem,acao,entidade_tipo,entidade_uuid,
     cliente_uuid,projeto_uuid,ambiente_uuid,request_id,ip_hash,detalhes)
SELECT
    UUID(),'sistema',NULL,'environment-operator',
    CONCAT('ambiente.operacao_', '$(sql_quote "$status")'),
    'ambiente_operacao',ao.uuid,c.uuid,p.uuid,a.uuid,NULL,NULL,
    JSON_OBJECT(
        'tipo',ao.tipo,
        'erro_codigo',$( [[ -n "$error_code" ]] && printf "'%s'" "$(sql_quote "$error_code")" || printf NULL )
    )
FROM threeebs_control.ambiente_operacoes ao
JOIN threeebs_control.ambientes a ON a.id=ao.ambiente_id
JOIN threeebs_control.projetos p ON p.id=a.projeto_id
JOIN threeebs_control.clientes c ON c.id=p.cliente_id
WHERE ao.uuid='$(sql_quote "$job_uuid")';
" >/dev/null
}

process_one() {
    local worker_token record
    worker_token="$(cat /proc/sys/kernel/random/uuid)"
    control_query "
UPDATE threeebs_control.ambiente_operacoes
SET status='executando',worker_token='$worker_token',
    tentativas=tentativas+1,iniciado_em=UTC_TIMESTAMP(6),erro_codigo=NULL
WHERE status='pendente'
ORDER BY id
LIMIT 1;
" >/dev/null

    record="$(control_query "
SELECT CONCAT_WS(CHAR(9),ao.uuid,ao.tipo,p.uuid,a.uuid,a.slug,a.tipo)
FROM threeebs_control.ambiente_operacoes ao
JOIN threeebs_control.ambientes a ON a.id=ao.ambiente_id
JOIN threeebs_control.projetos p ON p.id=a.projeto_id
WHERE ao.worker_token='$worker_token' AND ao.status='executando'
LIMIT 1;
")"
    [[ -n "$record" ]] || return 1

    local job_uuid operation project_uuid environment_uuid environment_slug environment_type
    IFS=$'\t' read -r job_uuid operation project_uuid environment_uuid environment_slug environment_type <<< "$record"
    local -a command
    case "$operation" in
        database.provision)
            command=(bash scripts/provision-project-database.sh "$project_uuid" "$environment_uuid" "$environment_slug")
            ;;
        runtime.php.activate)
            command=(bash scripts/provision-php-runtime.sh "$project_uuid" "$environment_uuid" "$environment_slug")
            [[ "$environment_type" == production ]] && command+=(--enable-production)
            ;;
        database.migrations.apply)
            command=(bash scripts/apply-project-migrations.sh "$job_uuid")
            ;;
        *)
            complete_job "$job_uuid" falhou operation_not_supported '{"success":false}'
            return 0
            ;;
    esac

    local log_file
    log_file="$(mktemp)"
    if ! "${command[@]}" >"$log_file" 2>&1; then
        sed -n '1,20p' "$log_file" >&2
        rm -f "$log_file"
        complete_job "$job_uuid" falhou provisioning_failed '{"success":false}'
        return 0
    fi
    rm -f "$log_file"

    if [[ "$operation" == database.provision ]]; then
        local php_active
        php_active="$(control_query "
SELECT COUNT(*)
FROM threeebs_control.ambiente_runtimes ar
JOIN threeebs_control.ambientes a ON a.id=ar.ambiente_id
WHERE a.uuid='$environment_uuid' AND ar.tipo='runtime.php'
  AND ar.execucao_habilitada=1 AND ar.status='ativo';
")"
        if [[ "$php_active" == 1 ]]; then
            command=(bash scripts/provision-php-runtime.sh "$project_uuid" "$environment_uuid" "$environment_slug")
            [[ "$environment_type" == production ]] && command+=(--enable-production)
            if ! "${command[@]}" >/dev/null 2>&1; then
                complete_job "$job_uuid" falhou runtime_reconciliation_failed '{"success":false}'
                return 0
            fi
        fi
    fi

    complete_job "$job_uuid" concluida '' '{"success":true}'
    return 0
}

bash scripts/apply-environment-operations-migration.sh >/dev/null
control_query "
UPDATE threeebs_control.ambiente_operacoes
SET status=IF(tentativas >= 3,'falhou','pendente'),
    erro_codigo=IF(tentativas >= 3,'worker_timeout',NULL),
    worker_token=NULL,
    concluido_em=IF(tentativas >= 3,UTC_TIMESTAMP(6),NULL)
WHERE status='executando'
  AND iniciado_em < UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE;
" >/dev/null

while true; do
    if ! process_one; then
        [[ "$mode" == --once ]] && exit 0
        sleep 5
        continue
    fi
    [[ "$mode" == --once ]] && exit 0
done
