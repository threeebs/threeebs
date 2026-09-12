# Portas e segurança

O domínio chega ao Cloudflare em HTTPS e o `cloudflared` encaminha a requisição pela rede Docker. As portas `6010–6017` existem no host para acesso local, diagnóstico e integrações administrativas; elas não devem ficar abertas na internet.

## Mapa de portas

| Porta no host | Serviço | Porta no container | Uso | Publicar na internet? |
| ---: | --- | ---: | --- | --- |
| `6010` | `host` | `80` | Sites, previews e domínios de clientes | Não; use `host:80` pelo túnel |
| `6011` | `portal` | `80` | Portal de usuários | Não; use `portal:80` pelo túnel |
| `6012` | `mysql` | `3306` | Banco principal | Nunca |
| `6013` | `phpmyadmin` | `80` | Administração opcional do banco | Nunca diretamente |
| `6014` | `redis` | `6379` | Filas, cache e estado temporário | Nunca |
| `6015` | `admin` | `80` | Painel administrativo | Não; use `admin:80` e Access |
| `6016` | `sandbox` | `80` | Editor e sandbox | Não; use `sandbox:80` e Access |
| `6017` | `projects-db` | `3306` | Banco de ambientes isolados | Nunca |

Outros fluxos:

| Porta | Direção | Finalidade |
| ---: | --- | --- |
| `443` | Usuário → Cloudflare | HTTPS público; o certificado fica na borda |
| `7844` | `cloudflared` → Cloudflare | Saída do túnel em redes com firewall restritivo |
| `22` | Administrador → VPS | SSH do servidor, não do Threeebs; restrinja por chave/origem |
| `8080` | Rede interna de runtime | Aplicações PHP isoladas, sem mapeamento fixo no host |

O serviço `realtime` não possui porta publicada no host. Ele permanece na rede interna e é acessado apenas pelos componentes autorizados.

## Como o domínio realmente se conecta

~~~mermaid
flowchart TD
    B[Navegador :443] --> C[Cloudflare]
    C --> T[cloudflared]
    T --> P[portal:80]
    T --> H[host:80]
~~~

Não configure DNS com `:6010` ou `:6011`. DNS resolve nomes; a seleção de porta acontece no ingress do túnel.

## Política recomendada de firewall

| Regra | Recomendação |
| --- | --- |
| Entrada `6010–6017` | Bloquear |
| Entrada `3306` e `6379` | Bloquear |
| Entrada `22` | Permitir apenas para origens administrativas |
| Entrada web na VPS | Não é necessária quando todo acesso usa Tunnel |
| Saída do `cloudflared` | Permitir conexão ao Cloudflare |

## Controles adicionais

- mantenha `BIND_ADDRESS=127.0.0.1`;
- proteja Admin e Editor com Cloudflare Access e autenticação local;
- não versione `.env`, `config.yml` real nem credenciais JSON;
- use segredos longos e diferentes por ambiente;
- mantenha Docker, sistema e imagens atualizados;
- faça backups fora da VPS e teste restauração;
- execute o phpMyAdmin apenas durante uma intervenção;
- revise logs sem registrar senhas, tokens ou dados pessoais.

O Tunnel reduz a superfície de rede, mas não substitui autenticação, autorização, atualizações ou backups.

[Próximo: instalação e atualização](06-instalacao-e-atualizacao.md)
