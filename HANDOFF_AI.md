# Handoff técnico do site #SAL (para desenvolvimento com outra IA)

Este documento consolida as informações essenciais do projeto para permitir que outra IA continue o desenvolvimento com contexto completo.

## 1) Resumo do projeto

- **Tipo**: plugin WordPress customizado.
- **Nome do plugin**: `SAL Core`.
- **Arquivo principal**: `sal-core.php`.
- **Objetivo funcional**:
  - Exibir conteúdo de **YouTube** e **Instagram** via shortcodes.
  - Renderizar formulário de **newsletter** (Mailchimp).
  - Verificar status de apoiador no **Apoia.se**.
  - Ingerir e exibir telemetria do barco (SignalK) via REST + dashboard de balanço (mapa + gráfico).
  - Automatizar setup inicial de páginas/menu no WordPress.

## 2) Estrutura de arquivos

- `sal-core.php`
  - Núcleo do plugin (hooks, endpoints REST, shortcodes, settings/admin e setup automatizado).
- `js/balance.js`
  - Front-end do shortcode de balanço (`[sal_balance]`) com Leaflet + Chart.js.
- `css/sal-core.css`
  - Estilos base para grids de conteúdo e formulários.

## 3) Dependências externas

### Front-end
- **Leaflet 1.9.4** (CDN unpkg) para mapa.
- **Chart.js v4** (CDN jsdelivr) para gráfico de velocidade.
- **OpenStreetMap tile server** para camadas do mapa.

### Integrações/serviços
- **YouTube Data API v3** (opcional, com fallback em RSS do canal).
- **Feed RSS do YouTube** (`/feeds/videos.xml`) como fallback.
- **Instagram via oEmbed** do WordPress para embeds.
- **Mailchimp** via URL de action de formulário embed.
- **Apoia.se API** para consulta de apoiadores.

## 4) Banco de dados e telemetria

Na ativação, o plugin cria a tabela `{prefix}_sal_track` com os campos:

- `id`, `ts`
- `lat`, `lon`
- `sog`, `cog`
- `awa`, `aws`
- `waterspeed`, `heading`
- `batt`, `depth`
- `ais` (LONGTEXT em JSON)
- `src`

Uso:
- Recebe dados via endpoint REST `POST /wp-json/sal/v1/sk`.
- Consulta último ponto via `GET /wp-json/sal/v1/last`.

## 5) Endpoints REST disponíveis

### `POST /wp-json/sal/v1/sk`
- Callback: `sal_core_handle_sk_data`.
- Permissão: pública (`__return_true`).
- Body esperado: JSON com campos como `ts`, `lat`, `lon`, `sog`, `cog`, `awa`, `aws`, `waterspeed`, `heading`, `batt`, `ais`, `depth`, `src`.
- Comportamento:
  - Campos ausentes viram `null`.
  - `ts` é convertido para UTC (`Y-m-d H:i:s`) com fallback em `current_time('mysql', 1)`.
  - Retorno: `{ "ok": true, "id": <insert_id> }`.

### `GET /wp-json/sal/v1/last`
- Callback: `sal_core_get_last_point`.
- Permissão: pública (`__return_true`).
- Retorna o ponto mais recente por `ORDER BY ts DESC, id DESC LIMIT 1`.
- Se não houver dados: HTTP 404 com mensagem `Sem dados`.

## 6) Configurações no WP Admin

Página: **Configurações → SAL Core**

Opções salvas:
- `sal_core_youtube_api_key`
- `sal_core_youtube_channel_id`
- `sal_core_instagram_posts` (lista CSV de URLs)
- `sal_core_mailchimp_action`
- `sal_core_apoia_campaign`
- `sal_core_apoia_key`
- `sal_core_apoia_secret`

Também existe link rápido de **Configurações** na lista de plugins.

## 7) Shortcodes existentes

### `[sal_balance]`
- Enfileira Leaflet + Chart.js + `js/balance.js`.
- Injeta containers:
  - `#sal-balance-map`
  - `#sal-balance-speed`
- Expõe endpoint `salCore.restLast` para polling.

### `[sal_youtube count="6"]`
- Busca vídeos por API YouTube quando `api_key + channel_id` existem.
- Fallback automático para RSS do canal.
- Renderiza grid `.sal-youtube-grid` com iframes.

### `[sal_instagram url="..."]`
- Embed único via oEmbed.
- Fallback para link clicável se embed falhar.

### `[sal_instagram_list count="6" columns="3"]`
- Usa URLs da opção `sal_core_instagram_posts`.
- Cacheia oEmbed em transients por 12h (`sal_ig_<md5_url>`).
- Fallback para link clicável por item.

### `[sal_newsletter]`
- Renderiza form Mailchimp com `action` configurável.
- Se action ausente, exibe mensagem de configuração.

### `[sal_club]`
- Form para consulta de e-mail de apoiador.
- Usa `sal_core_check_supporter_status` para chamar Apoia.se.
- Exibe nome/email/status/nível/data quando disponível.

## 8) Setup automático do site

Página: **Ferramentas → Setup #SAL**

Ação `sal_core_do_setup` cria/atualiza:
- `home`
- `quem-somos`
- `clube-sal`
- `balanco`
- `videos`
- `contato`
- `privacidade`

Também:
- Define `home` como página inicial estática.
- Cria (ou reutiliza) menu `Principal`.
- Adiciona páginas no menu.
- Vincula ao location `primary` quando existir no tema.

## 9) Fluxo de front-end do balanço (`js/balance.js`)

- Inicializa mapa Leaflet com visão inicial `[0,0]`, zoom 2.
- Inicializa line chart (SOG) com eixo temporal.
- Polling de `salCore.restLast` a cada 30s.
- Atualiza marcador/mapa e histórico do gráfico (janela de até 60 pontos).

## 10) Estilos existentes (`css/sal-core.css`)

- `.sal-youtube-grid` para vídeos responsivos.
- `.sal-instagram-embed`, `.sal-ig-grid`, `.sal-ig-item`, `.sal-ig-fallback`.
- Responsividade do Instagram para telas até 640px.
- `.sal-newsletter` e inputs associados.
- Ajustes simples para `.sal-club-form`.

## 11) Pontos de atenção (dívida técnica e riscos)

1. **Segurança dos endpoints REST**
   - `POST /sal/v1/sk` é público sem autenticação.
   - Recomendação: adicionar assinatura/HMAC, API key ou JWT para ingestão.

2. **Validação de dados de telemetria**
   - Hoje há cast básico (`floatval`, `sanitize_text_field`).
   - Recomendação: validar ranges (lat/lon, velocidade, etc.) para evitar lixo e abuso.

3. **Apoia.se: uso parcial de configuração**
   - A opção `sal_core_apoia_campaign` é obrigatória, mas não é usada para filtrar/consultar endpoint atual.
   - Recomendação: revisar contrato da API e alinhar com a campanha correta.

4. **Privacidade/LGPD**
   - Tabela de tracking + consulta de e-mail de apoiador exigem política clara de retenção e consentimento.

5. **Observabilidade**
   - Não há logging estruturado para falhas de API externa.
   - Recomendação: adicionar logs com níveis e controles por ambiente.

## 12) Backlog recomendado para a próxima IA

### Prioridade alta
- Implementar autenticação no endpoint de ingestão (`/sal/v1/sk`).
- Criar nonce/CSRF hardening adicional em fluxos administrativos sensíveis.
- Adicionar sanitização/validação robusta dos payloads de telemetria.

### Prioridade média
- Criar camada de serviço para integrações (YouTube, Apoia.se) com tratamento de erros e retries.
- Melhorar UX da página de configurações com validação inline.
- Adicionar paginação/filtro para histórico de telemetria (novo endpoint).

### Prioridade baixa
- Internacionalização completa (`__()`, `_e()`) de todas strings.
- Refino visual e redução de estilos inline nos shortcodes.
- Testes automatizados (PHPUnit + testes de integração REST).

## 13) Prompt pronto para usar com outra IA

Use este prompt como base:

> Você está trabalhando no plugin WordPress `SAL Core`.
> 
> Contexto:
> - Arquivos: `sal-core.php`, `js/balance.js`, `css/sal-core.css`.
> - O plugin expõe os endpoints REST `POST /wp-json/sal/v1/sk` (ingestão) e `GET /wp-json/sal/v1/last` (último ponto).
> - Há shortcodes: `[sal_balance]`, `[sal_youtube]`, `[sal_instagram]`, `[sal_instagram_list]`, `[sal_newsletter]`, `[sal_club]`.
> - Existe setup automático em `Ferramentas → Setup #SAL` para criar páginas e menu.
> 
> Objetivo:
> 1) [descreva aqui a funcionalidade desejada]
> 2) Manter compatibilidade com WordPress.
> 3) Preservar os shortcodes existentes.
> 4) Não remover fallback de YouTube RSS.
> 
> Restrições:
> - Priorizar segurança no endpoint público de ingestão.
> - Não quebrar setup automático.
> - Manter código organizado e comentado.
> 
> Entrega esperada:
> - Dif com alterações de código.
> - Explicação técnica curta.
> - Checklist de testes manuais no WordPress.

## 14) Checklist rápido de handoff

- [ ] Confirmar versão do WordPress alvo.
- [ ] Confirmar versão mínima do PHP alvo.
- [ ] Validar chaves/API reais em ambiente de staging.
- [ ] Testar todos os shortcodes em páginas reais.
- [ ] Testar endpoint de ingestão com carga baixa e média.
- [ ] Revisar requisitos legais (privacidade/LGPD).

