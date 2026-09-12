#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$project_root"

fail() {
    printf 'Threeebs: %s\n' "$1" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail 'Docker não foi encontrado.'
command -v systemctl >/dev/null 2>&1 || fail 'systemd não foi encontrado; use scripts/install.sh --skip-operator somente em desenvolvimento.'
command -v systemd-analyze >/dev/null 2>&1 || fail 'systemd-analyze não foi encontrado.'
[[ -d /run/systemd/system ]] || fail 'systemd não está ativo; use scripts/install.sh --skip-operator somente em desenvolvimento.'
[[ -f .env ]] || fail 'arquivo .env não encontrado.'

env_value() { sed -n "s/^$1=//p" .env | tail -n1; }
project_slug="$(env_value PROJECT_SLUG)"
[[ "$project_slug" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || fail 'PROJECT_SLUG inválido.'

operator_user="${THREEEBS_OPERATOR_USER:-${SUDO_USER:-$(id -un)}}"
id "$operator_user" >/dev/null 2>&1 || fail "usuário do operador não existe: $operator_user"
[[ "$operator_user" != root ]] || fail 'defina THREEEBS_OPERATOR_USER com um usuário não-root que possua acesso ao Docker.'
id -nG "$operator_user" | tr ' ' '\n' | grep -qx docker \
    || fail "o usuário $operator_user precisa pertencer ao grupo docker."

bash_path="$(command -v bash)"
docker_path="$(command -v docker)"
operator_script="$project_root/scripts/process-environment-operations.sh"
[[ -f "$operator_script" ]] || fail 'script do operador não encontrado.'

for unit_path in "$project_root" "$bash_path" "$docker_path" "$operator_script"; do
    [[ "$unit_path" =~ ^/[a-zA-Z0-9._/+:-]+$ ]] \
        || fail "caminho incompatível com uma unidade systemd: $unit_path"
done

systemd_quote() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//%/%%}"
    printf '"%s"' "$value"
}

service_name="threeebs-${project_slug}-environment-operator.service"
unit_file="$(mktemp --suffix=.service)"
trap 'rm -f "$unit_file"' EXIT

docker_directory="$(dirname -- "$docker_path")"
cat >"$unit_file" <<UNIT
[Unit]
Description=Threeebs environment operation worker (${project_slug})
Requires=docker.service
After=docker.service network-online.target

[Service]
Type=simple
User=${operator_user}
WorkingDirectory=${project_root}
Environment=$(systemd_quote "PATH=${docker_directory}:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin")
ExecStart=${bash_path} ${operator_script} --watch
Restart=always
RestartSec=5
UMask=0077
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
UNIT

if [[ "${1:-}" == --print ]]; then
    cat "$unit_file"
    exit 0
fi
[[ $# -eq 0 ]] || fail 'uso: bash scripts/install-environment-operator.sh [--print]'

if (( EUID == 0 )); then
    root_command=()
else
    command -v sudo >/dev/null 2>&1 || fail 'sudo não foi encontrado para instalar a unidade systemd.'
    root_command=(sudo)
fi

systemd-analyze verify "$unit_file"

legacy_service='threeebs-environment-operator.service'
legacy_root="$(systemctl show "$legacy_service" --property=WorkingDirectory --value 2>/dev/null || true)"
if [[ "$legacy_root" == "$project_root" ]]; then
    printf 'Threeebs: desativando unidade legada %s.\n' "$legacy_service"
    "${root_command[@]}" systemctl disable --now "$legacy_service"
fi

"${root_command[@]}" install -m 0644 "$unit_file" "/etc/systemd/system/$service_name"
"${root_command[@]}" systemctl daemon-reload
"${root_command[@]}" systemctl enable "$service_name"
"${root_command[@]}" systemctl restart "$service_name"
systemctl is-active --quiet "$service_name" || fail "o serviço $service_name não iniciou."
systemctl is-enabled --quiet "$service_name" || fail "o serviço $service_name não foi habilitado."

printf 'Threeebs: operador instalado e ativo: %s\n' "$service_name"
