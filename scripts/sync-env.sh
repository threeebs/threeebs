#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"

check_only=false
if [[ "${1:-}" == --check ]]; then
    check_only=true
    shift
fi

case "$#" in
    0)
        env_file="$project_root/.env"
        example_file="$project_root/.env.example"
        ;;
    2)
        env_file="$1"
        example_file="$2"
        ;;
    *)
        printf '%s\n' 'uso: bash scripts/sync-env.sh [--check] [ARQUIVO_ENV ARQUIVO_EXEMPLO]' >&2
        exit 1
        ;;
esac

fail() {
    printf 'Threeebs: %s\n' "$1" >&2
    exit 1
}

[[ -f "$env_file" && ! -L "$env_file" ]] || fail "arquivo de ambiente inválido: $env_file"
[[ -f "$example_file" && ! -L "$example_file" ]] || fail "arquivo de exemplo inválido: $example_file"

declare -A existing_keys=()
while IFS= read -r line || [[ -n "$line" ]]; do
    if [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)= ]]; then
        existing_keys["${BASH_REMATCH[1]}"]=1
    fi
done < "$env_file"

missing_keys=()
while IFS= read -r line || [[ -n "$line" ]]; do
    if [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
        variable_name="${BASH_REMATCH[1]}"
        [[ -n "${existing_keys[$variable_name]:-}" ]] || missing_keys+=("$variable_name")
    fi
done < "$example_file"

if [[ ${#missing_keys[@]} -eq 0 ]]; then
    [[ "$check_only" == true ]] || chmod 0600 "$env_file"
    printf '%s\n' 'Threeebs: .env já contém todas as variáveis conhecidas.'
    exit 0
fi

if [[ "$check_only" == true ]]; then
    printf 'Threeebs: variáveis ausentes no .env:' >&2
    printf ' %s' "${missing_keys[@]}" >&2
    printf '\n' >&2
    exit 2
fi

command -v openssl >/dev/null 2>&1 || fail 'OpenSSL não foi encontrado para gerar novos segredos.'

env_backup="${env_file}.before-sync-$(date -u +%Y%m%dT%H%M%SZ)"
install -m 0600 -- "$env_file" "$env_backup"
temporary_file="$(mktemp "$(dirname -- "$env_file")/.threeebs-env.XXXXXX")"
trap 'rm -f "$temporary_file"' EXIT
cp -- "$env_file" "$temporary_file"

{
    printf '\n# Variáveis adicionadas automaticamente a partir do .env.example\n'
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]] || continue
        variable_name="${BASH_REMATCH[1]}"
        [[ -z "${existing_keys[$variable_name]:-}" ]] || continue

        case "$variable_name" in
            PROJECTS_MYSQL_ROOT_PASSWORD|REALTIME_INTERNAL_SECRET)
                printf '%s=%s\n' "$variable_name" "$(openssl rand -hex 32)"
                ;;
            *)
                printf '%s\n' "$line"
                ;;
        esac
    done < "$example_file"
} >> "$temporary_file"

chmod 0600 "$temporary_file"
mv -f -- "$temporary_file" "$env_file"
trap - EXIT

printf 'Threeebs: .env preservado; variáveis adicionadas:'
printf ' %s' "${missing_keys[@]}"
printf '\n'
printf 'THREEEBS_ENV_BACKUP=%s\n' "$env_backup"
