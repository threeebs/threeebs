# Cloudflare Tunnel

O Cloudflare Tunnel conecta a VPS ao Cloudflare por uma conexão iniciada pelo `cloudflared`. Assim, Portal, Admin, Sandbox e Host podem ser publicados sem abrir suas portas locais para a internet.

~~~mermaid
sequenceDiagram
    participant U as Usuário
    participant C as Cloudflare
    participant T as cloudflared
    participant H as Serviço Docker
    U->>C: HTTPS
    C->>T: Túnel
    T->>H: HTTP interno
    H-->>U: Resposta
~~~

## Modelo usado pelo projeto

O perfil `tunnel` do Compose está preparado para um túnel **gerenciado localmente**, com `config.yml` e arquivo JSON de credenciais montados no container. O Cloudflare recomenda túneis gerenciados remotamente para a maioria dos novos casos; mantenha o modelo local enquanto usar esta integração ou adapte o Compose conscientemente.

## 1. Criar o túnel

Com o domínio já ativo no Cloudflare e o `cloudflared` instalado na máquina administrativa:

~~~bash
cloudflared tunnel login
cloudflared tunnel create threeebs
cloudflared tunnel list
~~~

O comando de criação informa o UUID e grava um arquivo `<UUID>.json`. Trate esse arquivo como segredo.

## 2. Preparar os arquivos

~~~bash
cp infrastructure/cloudflared/config.yml.example infrastructure/cloudflared/config.yml
mkdir -p infrastructure/cloudflared/credentials
~~~

Copie o JSON para `infrastructure/cloudflared/credentials/<UUID>.json` e ajuste o `config.yml`:

~~~yaml
tunnel: SUBSTITUA_PELO_TUNNEL_UUID
credentials-file: /etc/cloudflared/credentials/SUBSTITUA_PELO_TUNNEL_UUID.json

ingress:
  - hostname: painel.seudominio.com
    service: http://portal:80

  - hostname: admin.seudominio.com
    service: http://admin:80

  - hostname: editor.seudominio.com
    service: http://sandbox:80

  - hostname: "*.seudominio.com"
    service: http://host:80

  - service: http_status:404
~~~

As regras específicas devem vir antes do wildcard. A regra final responde `404` para qualquer hostname inesperado.

## 3. Criar as rotas DNS

Para hostnames explícitos:

~~~bash
cloudflared tunnel route dns SUBSTITUA_PELO_TUNNEL_UUID painel.seudominio.com
cloudflared tunnel route dns SUBSTITUA_PELO_TUNNEL_UUID admin.seudominio.com
cloudflared tunnel route dns SUBSTITUA_PELO_TUNNEL_UUID editor.seudominio.com
~~~

Para os projetos, crie no painel DNS um CNAME proxied com nome `*` apontando para:

~~~text
SUBSTITUA_PELO_TUNNEL_UUID.cfargotunnel.com
~~~

Vários hostnames podem apontar para o mesmo túnel. DNS e túnel são objetos independentes: o registro pode existir enquanto o túnel está parado, mas o serviço não responderá corretamente.

## 4. Validar e iniciar

~~~bash
docker compose --profile tunnel run --rm cloudflared tunnel ingress validate
docker compose --profile tunnel up -d cloudflared
docker compose logs --tail=100 cloudflared
~~~

Valide `painel`, `admin`, `editor` e um subdomínio de cliente. Proteja Admin e Editor com Cloudflare Access e autenticação da própria aplicação.

## Diagnóstico

| Sintoma | Verificação |
| --- | --- |
| DNS resolve, mas retorna erro | Confirme se o container `cloudflared` está ativo |
| Um painel abre o Host | Revise a ordem: regras específicas antes do wildcard |
| Erro de credenciais | Confira UUID, caminho e nome do JSON |
| Túnel não conecta | Libere saída para o Cloudflare, inclusive porta `7844` |
| Serviço recusado | Use o nome e a porta do container, como `portal:80` |

## Referências oficiais

- [Criar um túnel gerenciado localmente](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/do-more-with-tunnels/local-management/create-local-tunnel/)
- [Criar registros DNS para o túnel](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/routing-to-tunnel/dns/)
- [Publicar aplicações](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/routing-to-tunnel/)

[Próximo: portas e segurança](05-portas-e-seguranca.md)
