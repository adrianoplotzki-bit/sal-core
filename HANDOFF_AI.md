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

---

## 15) Tema `sal-theme` (v1.0.0)

O site passou por um rebrand completo com a criação do tema WordPress custom `sal-theme`. Este tema é parte do mesmo repositório `adrianoplotzki-bit/sal-core` e é publicado por um segundo step FTP no GitHub Actions (branch `staging` → `wp-content/themes/sal-theme/`).

### Estrutura de pastas

```
theme/sal-theme/
├── style.css                        ← Header WP (Theme Name, Version, Text Domain)
├── functions.php                    ← Enqueues, suporte, register_nav_menus, SAL_THEME_VERSION
├── index.php                        ← Fallback genérico (não usado quando front-page.php existe)
├── front-page.php                   ← Home: hero → grid 7fr/5fr
├── page.php                         ← Páginas estáticas: main.sal-container.sal-page > article.sal-prose
├── single.php                       ← Posts individuais (mesma estrutura que page.php)
├── header.php                       ← DOCTYPE, meta viewport, Google Fonts, wp_head(), .sal-header
├── footer.php                       ← .sal-footer, wp_footer(), </body></html>
├── assets/
│   ├── css/
│   │   ├── tokens.css               ← Design tokens (cores, tipografia, espaçamentos, sombras)
│   │   ├── base.css                 ← Reset moderno, tipografia global, .sal-container, .sal-btn
│   │   └── components.css           ← §0 A11y, §1 Header, §2 Hero, §3–8 Home, §9 Footer, §10 Páginas
│   ├── img/
│   │   ├── hero.jpg                 ← 1600×900px, ≤300 KB (foto Adriano no barco)
│   │   ├── fallback-video.jpg       ← 1280×720px, thumbnail fallback do YouTube
│   │   ├── about-sal.jpg            ← 800×765px, foto "O que é o SAL"
│   │   └── community/
│   │       ├── 01.jpg               ← Mosaico comunidade (convertida de PNG 24 MB → 54 KB)
│   │       ├── 02.jpg
│   │       └── 03.jpg
│   └── js/
│       └── theme.js                 ← Toggle menu mobile (IIFE, zero dependências)
└── template-parts/
    ├── header/
    │   └── nav.php                  ← wp_nav_menu (location: primary) + fallback hardcoded
    ├── home/
    │   ├── hero.php                 ← Hero full-width, overlay 40%, título, 2 botões
    │   ├── latest-video.php         ← Card 16:9 com thumbnail YouTube + play overlay
    │   ├── transparency.php         ← 2 cards: Balanço (azul) + Prestação de Contas (verde)
    │   ├── about-sal.php            ← Card branco com foto + parágrafo + link
    │   ├── community.php            ← Mosaico 3 fotos + quote (editável via theme_mod)
    │   └── newsletter.php           ← Form Mailchimp sem shortcode (lê get_option direto)
    └── footer/
        └── footer-columns.php       ← 4 colunas: identidade, navegação, participar, social
```

### Como ativar o tema

1. Faça push em `staging` (o GitHub Actions publica o tema via FTP automaticamente).
2. No WP Admin do staging: **Aparência → Temas → SAL Theme → Ativar**.
3. Execute **Ferramentas → Setup #SAL** para vincular o menu `Principal` ao location `primary` do tema.
4. Configure as opções do plugin em **Configurações → SAL Core** (API YouTube, Mailchimp, etc.).

### Dependências do plugin `sal-core`

O tema **depende** do plugin `sal-core` estar ativo para:

| Funcionalidade | Option lida pelo tema | Fallback se ausente |
|---|---|---|
| Último vídeo | `sal_core_youtube_api_key` + `sal_core_youtube_channel_id` | Thumbnail estático `fallback-video.jpg` |
| Newsletter | `sal_core_mailchimp_action` | Placeholder "Configure em SAL Core → Configurações" |
| Menu principal | Criado por `sal_core_do_setup()` | Links hardcoded (quem-somos, balanco, clube-sal) |

O tema **não** depende dos shortcodes do plugin para renderizar a home — todas as chamadas são diretas (função helper `sal_theme_get_latest_video()` em `functions.php`, `get_option()` para o newsletter). Os shortcodes continuam funcionando nas páginas internas que os usam (ex.: `[sal_balance]` na página `/balanco`).

### Pontos de customização

**Via WP Admin (sem código):**
- **Menu Principal** (`Aparência → Menus`): altera os links do header e footer.
- **Quote da comunidade** (`Aparência → Personalizar`): editável via `get_theme_mod('sal_community_quote')`.
- **Configurações → SAL Core**: API Key YouTube, Channel ID, URL de action do Mailchimp.

**Via código (arquivos do tema):**
- `assets/css/tokens.css`: todos os valores de cor, tipografia e espaçamento.
- `template-parts/home/*.php`: reordenar ou substituir seções da home.
- `template-parts/footer/footer-columns.php`: colunas e links do footer.

### Notas de QA (v1.0.0)

**Lighthouse (estimativa estática — não foi possível rodar contra o staging do sandbox por restrição de egress de rede):**
- Performance: ~85–90 esperado (imagens otimizadas ≤300 KB, lazy-load em todas as imagens below-the-fold, zero JS bloqueante, fontes via `display=swap`).
- Acessibilidade: ~90–95 esperado (skip link implementado, todos os `aria-*` corretos, `role="banner"`, `role="contentinfo"`, focus visível em todos os elementos interativos, `prefers-reduced-motion` respeitado).
- Best Practices: ~90+ esperado (sem console errors, HTTPS, meta viewport correto).

**Breakpoints revisados (análise de código):**
- **360px**: hero colapsa para 480px, botões full-width, footer em 1 coluna, hambúrguer visível.
- **768px**: header expande para menu horizontal, hero retorna ao tamanho pleno.
- **1024px**: grid 7fr/5fr da home ativo, footer em 4 colunas.
- **1440px**: container capped em 1280px, conteúdo centralizado com gutter de 3rem.

**HTML revisado:** todas as tags fechadas, atributos `alt` corretos (vazio + `aria-hidden="true"` nos decorativos, descritivo nas fotos de conteúdo), `lang` definido via `language_attributes()`.

### Débitos técnicos remanescentes

| # | Débito | Prioridade |
|---|---|---|
| DT-01 | **Refatorar `[sal_newsletter]`** no plugin: remover estilos inline e aplicar classes `.sal-newsletter__input`, `.sal-newsletter__button`. Quando feito, substituir o markup manual em `newsletter.php` por `do_shortcode('[sal_newsletter]')`. | Alta |
| DT-02 | **`screenshot.png` do tema** é um placeholder cinza (1200×900). Gerar com screenshot real da home após ativar o tema. | Baixa |
| DT-03 | **Lighthouse real** não foi rodado — o domínio staging é bloqueado pelo egress do sandbox (restrição conhecida). Rodar manualmente via DevTools após ativar o tema. | Alta (validação) |
| DT-04 | **Página `/prestacao-de-contas`** não existe — o card "Prestação de Contas" na seção Transparência aponta para `/balanco` (solução temporária). Criar página dedicada quando houver conteúdo editorial. | Média |
| DT-05 | **`get_theme_mod('sal_community_quote')`** ainda não tem painel de Personalizar configurado. Registrar no Customizer com `add_action('customize_register')`. | Baixa |
| DT-06 | **Internacionalização**: todas as strings usam `esc_html_e()` / `esc_attr_e()` com text domain `sal-theme`, mas o arquivo `.pot` não foi gerado. Rodar `wp i18n make-pot` quando necessário. | Baixa |
| DT-07 | **`theme.json`** não foi criado — as cores e tipografia não aparecem no editor de blocos Gutenberg. Criar `theme.json` com a paleta do design system para quem usar o editor visual. | Média |

