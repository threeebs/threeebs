# Servidor e Docker

O Docker Compose agrupa os serviços da plataforma e cria redes internas para comunicação entre eles.

## Topologia

~~~mermaid
flowchart TD
    T[cloudflared] --> F[Portal / Admin / Sandbox]
    T --> H[Host]
    F --> D[(MySQL)]
    F --> R[(Redis)]
    H --> P[Ambientes de projetos]
~~~

| Grupo | Serviços principais | Responsabilidade |
| --- | --- | --- |
| Interface | `portal`, `admin` e `sandbox` | Cadastro, administração e edição |
| Entrega | `host` | Resolve hostnames e entrega ambientes |
| Dados | `mysql` e `redis` | Persistência e estado temporário |
| Tempo real | `realtime` | Canal interno de colaboração |
| Runtime | `projects-db` e probes | Fundação opcional para ambientes isolados |
| Borda | `cloudflared` | Conecta a rede Compose ao Cloudflare |

## Preparar a VPS

1. Atualize o sistema e crie um usuário administrativo sem usar `root` no trabalho diário.
2. Configure autenticação SSH por chave e um firewall.
3. Instale Docker Engine e Docker Compose v2 pelos pacotes oficiais da sua distribuição.
4. Confirme:

~~~bash
docker --version
docker compose version
git --version
openssl version
~~~

5. Clone o repositório em um diretório dedicado:

~~~bash
git clone https://github.com/Tiao-gpt/threeebs.git
cd threeebs
~~~

## Redes e nomes de serviço

Dentro de uma rede Compose, containers encontram outros serviços pelo nome. Por isso, o túnel usa `http://portal:80`, não `http://localhost:6011`.

| Origem | Destino correto |
| --- | --- |
| Navegador na própria VPS | `http://127.0.0.1:6011` |
| `cloudflared` no Compose | `http://portal:80` |
| Aplicação para o MySQL | `mysql:3306` |
| Aplicação para o Redis | `redis:6379` |

As redes `realtime`, `projects-runtime` e `runtime-ingress` limitam a comunicação entre grupos de containers. Não remova `internal: true` sem revisar o impacto de segurança.

## Perfis opcionais

~~~bash
# Ferramenta de banco, apenas quando necessário
docker compose --profile tools up -d phpmyadmin

# Cloudflare Tunnel
docker compose --profile tunnel up -d cloudflared

# Fundação de runtime isolado
docker compose --profile runtime-foundation up -d
~~~

Não deixe o phpMyAdmin publicado permanentemente. Prefira acesso temporário por loopback ou túnel administrativo protegido.

## Persistência e backup

Volumes Docker guardam banco, cache e dados de runtime. `docker compose down` preserva volumes; `docker compose down -v` os remove e pode destruir dados. Faça backup consistente antes de atualizações e teste a restauração.

## Referência oficial

- [Rede no Docker Compose](https://docs.docker.com/compose/how-tos/networking/)

[Próximo: Cloudflare Tunnel](04-cloudflare-tunnel.md)
