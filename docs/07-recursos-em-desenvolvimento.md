# Recursos em desenvolvimento

O Threeebs possui um canal Edge mais avançado, onde novas fundações são integradas e validadas antes de uma promoção para o repositório público. A presença de código ou documentação no canal Edge não representa disponibilidade estável nem compromisso de prazo.

~~~mermaid
stateDiagram-v2
    [*] --> Ideia
    Ideia --> Fundação
    Fundação --> Edge
    Edge --> VersãoPública: validação concluída
    Edge --> Fundação: ajustes necessários
~~~

## Frentes atuais

| Frente | Objetivo | Estado documental |
| --- | --- | --- |
| Automações de e-mail | Confirmações, notificações e mensagens de acesso | Em desenvolvimento |
| Webhooks e integrações | Conectar eventos a ferramentas de automação | Em desenvolvimento |
| Fluxos de parceiros | Organizar indicações e operações compartilhadas | Em validação |
| Edição em tempo real | Colaboração entre usuários autorizados | Fundação presente, ainda Alpha |
| Runtime isolado | Separar execução e banco por ambiente | Fundação opcional |
| Operador de ambientes | Provisionamento e manutenção assíncrona | Experimental |
| Recuperação e primeiro acesso | Fluxos seguros de identidade | Em validação |

## Critério de promoção

Um recurso só deve ser apresentado como público e estável depois de:

1. ter configuração segura por padrão;
2. possuir migrations e atualização idempotentes;
3. passar por validação no canal Edge;
4. receber documentação operacional;
5. ser promovido deliberadamente para uma versão pública.

Enquanto isso, trate as funcionalidades desta página como experimentais. Evite prometer disponibilidade, compatibilidade ou prazo a clientes.

[Voltar ao índice](README.md)
