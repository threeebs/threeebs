# Instalação e atualização

## Instalação

~~~bash
git clone https://github.com/Tiao-gpt/threeebs.git
cd threeebs
bash scripts/install.sh
~~~

Na primeira execução, o instalador cria o `.env` e interrompe o processo. Edite o arquivo, substitua todos os valores iniciados por `TROQUE_` e execute novamente:

~~~bash
bash scripts/install.sh
~~~

Em uma máquina local ou servidor sem `systemd`:

~~~bash
bash scripts/install.sh --skip-operator
~~~

O instalador sincroniza novas variáveis do `.env.example`, inicia os serviços, aplica migrations idempotentes e verifica permissões e saúde dos containers.

## Segredos

Gere valores independentes para cada segredo:

~~~bash
openssl rand -hex 32
~~~

Não reutilize segredos entre desenvolvimento e produção. Nunca envie o `.env` ao GitHub.

## Verificação local

Com os valores padrão:

| Serviço | URL |
| --- | --- |
| Host/Preview | `http://localhost:6010` |
| Portal | `http://localhost:6011` |
| Admin | `http://localhost:6015` |
| Sandbox | `http://localhost:6016` |

~~~bash
docker compose ps
docker compose logs --tail=100
~~~

Depois da validação local, configure o [domínio](02-dominio-e-subdominios.md) e o [Cloudflare Tunnel](04-cloudflare-tunnel.md).

## Atualização segura

Revise o [CHANGELOG](../CHANGELOG.md) e as mudanças antes de atualizar:

~~~bash
git fetch origin
git log --oneline HEAD..origin/main
git pull --ff-only origin main
bash scripts/update.sh --check
bash scripts/update.sh
~~~

Use `--skip-operator` em ambientes sem `systemd`. O processo sincroniza novas variáveis sem apagar valores existentes, cria backup antes da atualização, aplica migrations e confirma os serviços essenciais.

## Operação

~~~bash
# Estado
docker compose ps

# Logs de um serviço
docker compose logs --tail=100 portal

# Reiniciar um serviço
docker compose restart portal

# Encerrar containers sem apagar volumes
docker compose down
~~~

Evite `docker compose down -v` em produção: a opção `-v` remove volumes.

## Diagnóstico rápido

| Problema | Ação |
| --- | --- |
| Container reiniciando | `docker compose logs <serviço>` |
| Portal local indisponível | Confirme `BIND_ADDRESS`, porta e estado do container |
| Migration falhou | Preserve o backup, leia o erro e não repita ações destrutivas |
| Domínio não abre | Separe diagnóstico de DNS, túnel e serviço interno |
| Disco cheio | Inspecione volumes, uploads, backups e logs antes de remover dados |

[Próximo: recursos em desenvolvimento](07-recursos-em-desenvolvimento.md)
