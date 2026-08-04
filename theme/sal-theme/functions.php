<?php
/**
 * SAL Theme — functions.php
 *
 * Funções e configurações do tema. Os enqueues de CSS e JS serão
 * populados nas etapas seguintes (design system, header, etc.).
 *
 * @package sal-theme
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

define( 'SAL_THEME_VERSION', '1.1.2' );
define( 'SAL_THEME_URI', get_template_directory_uri() );

// ---------------------------------------------------------------------------
// Suporte do tema
// ---------------------------------------------------------------------------
add_action( 'after_setup_theme', 'sal_theme_setup' );

function sal_theme_setup() {
	// Permite que o WordPress gerencie o <title> da página.
	add_theme_support( 'title-tag' );

	// Suporte a imagens destacadas em posts/páginas.
	add_theme_support( 'post-thumbnails' );

	// HTML5 semântico para formulários e galerias.
	add_theme_support( 'html5', [
		'search-form',
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
		'style',
		'script',
	] );

	// Registra a localização do menu principal.
	register_nav_menus( [
		'primary' => __( 'Menu principal', 'sal-theme' ),
	] );
}

// ---------------------------------------------------------------------------
// Enqueue de scripts e estilos
// (CSS do design system será adicionado na Etapa 3)
// ---------------------------------------------------------------------------
add_action( 'wp_enqueue_scripts', 'sal_theme_enqueue_assets' );

function sal_theme_enqueue_assets() {
	// ── Design system (Etapa 3) ──────────────────────────────────────────────
	// tokens.css DEVE vir antes de base.css (custom properties usadas por base).
	wp_enqueue_style(
		'sal-tokens',
		SAL_THEME_URI . '/assets/css/tokens.css',
		[],
		SAL_THEME_VERSION
	);

	wp_enqueue_style(
		'sal-base',
		SAL_THEME_URI . '/assets/css/base.css',
		[ 'sal-tokens' ], // garante que tokens carregue primeiro
		SAL_THEME_VERSION
	);

	// ── Componentes visuais (Etapa 4) ───────────────────────────────────────
	// components.css: header, hero, cards, newsletter, footer.
	// Depende de sal-base (que por sua vez depende de sal-tokens).
	wp_enqueue_style(
		'sal-components',
		SAL_THEME_URI . '/assets/css/components.css',
		[ 'sal-base' ],
		SAL_THEME_VERSION
	);

	// ── JavaScript do tema (Etapa 4) ─────────────────────────────────────────
	// theme.js: toggle do menu mobile (IIFE, zero dependências).
	// Carregado no rodapé (true) para não bloquear o render.
	wp_enqueue_script(
		'sal-theme',
		SAL_THEME_URI . '/assets/js/theme.js',
		[],              // zero dependências (vanilla JS puro)
		SAL_THEME_VERSION,
		true             // footer: true (carrega após o DOM)
	);

	// ── Analytics (Umami, self-hosted) ───────────────────────────────────────
	// Sem cookie e sem dado pessoal — é por isso que não há banner de
	// consentimento. Ver INFRA.md §15 para a escolha.
	//
	// Não carrega para quem está logado: existe um único usuário no
	// WordPress, e contar as próprias visitas distorceria justamente os
	// números que o painel serve para ler.
	if ( ! is_user_logged_in() ) {
		wp_enqueue_script(
			'sal-analytics',
			'https://metricas.hashtagsal.com.br/script.js',
			[],
			null,   // null e não SAL_THEME_VERSION: o ?ver= é nosso, e o
			        // arquivo é servido pelo Umami com o cache dele.
			true
		);
	}
}

/**
 * Acrescenta os atributos que o Umami exige na tag do script.
 *
 * `data-website-id` identifica o site e não é segredo — ele aparece no HTML
 * de qualquer página. `defer` mantém a promessa do enqueue no rodapé: o
 * script nunca bloqueia o render.
 */
function sal_theme_tag_analytics( $tag, $handle ) {
	if ( 'sal-analytics' !== $handle ) {
		return $tag;
	}
	return str_replace(
		'<script ',
		'<script defer data-website-id="f30b1892-9ea3-442a-b972-6379d8168651" ',
		$tag
	);
}
add_filter( 'script_loader_tag', 'sal_theme_tag_analytics', 10, 2 );

// ---------------------------------------------------------------------------
// SEO: descrição, Open Graph, Twitter Card e dados estruturados
// ---------------------------------------------------------------------------
//
// Escrito à mão, no tema, e não com plugin de SEO. Yoast ou AIOSEO seriam
// uma dependência grande para o que aqui são umas poucas tags — e o
// `aioseo_activation_redirect` que sobrou no banco mostra que esse filme já
// passou uma vez neste site.
//
// O QUE ISTO RESOLVE, MEDIDO EM 2026-08-04
// ----------------------------------------
// O site não tinha NENHUMA meta description e NENHUMA tag Open Graph. Como
// ~95% do tráfego chega sem referrer (descrição do YouTube, WhatsApp,
// Instagram), colar o link em qualquer um desses lugares produzia texto
// pelado: sem imagem, sem título, sem descrição. Era onde se perdia quem já
// ia clicar.

/**
 * Descrição da página atual, em ~155 caracteres.
 *
 * Sai do conteúdo real da página, não de um campo inventado: o conteúdo vive
 * no banco (ver §14 do CLAUDE.md) e é o que de fato está na tela. Um resumo
 * escrito à parte envelhece separado da página e passa a mentir.
 */
function sal_theme_descricao() {
	if ( is_front_page() ) {
		return get_bloginfo( 'description' );
	}

	if ( is_singular() ) {
		$post = get_queried_object();
		$texto = has_excerpt( $post ) ? get_the_excerpt( $post ) : $post->post_content;
		$texto = wp_strip_all_tags( strip_shortcodes( $texto ), true );
		// Decodifica ANTES de cortar e antes do esc_attr do final. O conteúdo
		// no banco guarda aspas como `&quot;`, que o strip_tags não toca — e
		// escapar de novo publicaria `&amp;quot;` literalmente no resultado
		// do Google. Visto na /balanco/, que tem "sinais vitais" entre aspas.
		// Decodificar aqui também faz o corte de 155 contar CARACTERES de
		// verdade, e não os seis bytes de uma entidade.
		$texto = html_entity_decode( $texto, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$texto = trim( preg_replace( '/\s+/u', ' ', $texto ) );
		if ( $texto !== '' ) {
			// 155 caracteres é onde o Google costuma cortar. Cortar por
			// PALAVRA, nunca no meio de uma — descrição truncada em "manuten"
			// lê-se como site quebrado.
			if ( mb_strlen( $texto ) > 155 ) {
				$texto = mb_substr( $texto, 0, 155 );
				$corte = mb_strrpos( $texto, ' ' );
				if ( $corte > 80 ) {
					$texto = mb_substr( $texto, 0, $corte );
				}
				$texto .= '…';
			}
			return $texto;
		}
	}

	return get_bloginfo( 'description' );
}

/**
 * Imagem de compartilhamento.
 *
 * Imagem destacada da página quando existir; senão o hero, que é 1600×900 e
 * já está otimizado. **Sempre devolve alguma coisa** — card sem imagem no
 * WhatsApp encolhe para uma linha de texto e perde quase toda a atenção que
 * o link renderia.
 */
function sal_theme_imagem_social() {
	if ( is_singular() && has_post_thumbnail() ) {
		$url = get_the_post_thumbnail_url( get_queried_object_id(), 'full' );
		if ( $url ) {
			return $url;
		}
	}
	return SAL_THEME_URI . '/assets/img/hero.jpg';
}

/**
 * Título para compartilhamento, sem o sufixo do site.
 *
 * O `wp_get_document_title()` devolve "Vídeos – #SAL". No card social o nome
 * do site já aparece embaixo, em `og:site_name`, então repetir vira
 * "Vídeos – #SAL — #SAL".
 */
function sal_theme_titulo_social() {
	if ( is_front_page() ) {
		return get_bloginfo( 'name' ) . ' — ' . get_bloginfo( 'description' );
	}
	if ( is_singular() ) {
		return get_the_title( get_queried_object_id() );
	}
	return wp_get_document_title();
}

function sal_theme_seo_meta() {
	$descricao = sal_theme_descricao();
	$titulo    = sal_theme_titulo_social();
	$imagem    = sal_theme_imagem_social();
	$url       = is_singular() ? get_permalink( get_queried_object_id() ) : home_url( '/' );

	echo "\n<!-- SEO do sal-theme -->\n";

	printf( '<meta name="description" content="%s">' . "\n", esc_attr( $descricao ) );

	printf( '<meta property="og:type" content="%s">' . "\n", is_front_page() ? 'website' : 'article' );
	printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
	printf( '<meta property="og:locale" content="pt_BR">' . "\n" );
	printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $titulo ) );
	printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $descricao ) );
	printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
	printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $imagem ) );
	// Largura e altura deixam o WhatsApp e o Facebook reservarem o espaço do
	// card sem baixar a imagem antes — sem elas o preview às vezes aparece
	// sem foto na primeira vez que o link é colado.
	printf( '<meta property="og:image:width" content="1600">' . "\n" );
	printf( '<meta property="og:image:height" content="900">' . "\n" );

	printf( '<meta name="twitter:card" content="summary_large_image">' . "\n" );
	printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $titulo ) );
	printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $descricao ) );
	printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( $imagem ) );
}
add_action( 'wp_head', 'sal_theme_seo_meta', 5 );

/**
 * Dados estruturados (JSON-LD).
 *
 * Só na home, de propósito: `Organization` e `WebSite` descrevem o site
 * inteiro, e repeti-los em toda página não acrescenta nada e ainda dá ao
 * Google a chance de escolher a cópia errada como canônica.
 *
 * Nada aqui declara faturamento, doação ou qualquer número — o canal não
 * abre dados financeiros e não pretende abrir (§5 do CLAUDE.md).
 */
function sal_theme_json_ld() {
	if ( ! is_front_page() ) {
		return;
	}

	$dados = [
		'@context' => 'https://schema.org',
		'@graph'   => [
			[
				'@type'       => 'Organization',
				'@id'         => home_url( '/#organizacao' ),
				'name'        => get_bloginfo( 'name' ),
				'url'         => home_url( '/' ),
				'description' => get_bloginfo( 'description' ),
				'logo'        => [
					'@type'  => 'ImageObject',
					'url'    => SAL_THEME_URI . '/assets/img/logo-sal.png',
					'width'  => 264,
					'height' => 183,
				],
				'sameAs'      => [
					'https://www.youtube.com/user/hashtagsal',
					'https://apoia.se/hashtagsal',
				],
			],
			[
				'@type'           => 'WebSite',
				'@id'             => home_url( '/#site' ),
				'url'             => home_url( '/' ),
				'name'            => get_bloginfo( 'name' ),
				'description'     => get_bloginfo( 'description' ),
				'inLanguage'      => 'pt-BR',
				'publisher'       => [ '@id' => home_url( '/#organizacao' ) ],
			],
		],
	];

	echo "\n" . '<script type="application/ld+json">'
		// JSON_UNESCAPED_UNICODE: sem isso "oceano" e os acentos viram ã
		// no fonte — válido, mas ilegível para quem for depurar isto depois.
		. wp_json_encode( $dados, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. '</script>' . "\n";
}
add_action( 'wp_head', 'sal_theme_json_ld', 6 );

// ---------------------------------------------------------------------------
// Helper: último vídeo do canal (Etapa 6)
// ---------------------------------------------------------------------------

/**
 * Retorna os dados do vídeo mais recente do canal #SAL.
 *
 * Tenta a YouTube Data API v3 usando as mesmas options do plugin sal-core.
 * Se a API estiver indisponível ou as credenciais ausentes, cai no RSS
 * público do canal. O resultado é cacheado em transient por 1 hora para
 * não penalizar o TTFB a cada pageview.
 *
 * @return array{id: string, title: string}  Vazio se nenhum vídeo encontrado.
 */
function sal_theme_get_latest_video() {
	$cached = get_transient( 'sal_theme_latest_video' );
	if ( false !== $cached ) {
		return $cached;
	}

	$api_key     = trim( (string) get_option( 'sal_core_youtube_api_key', '' ) );
	$channel_id  = trim( (string) get_option( 'sal_core_youtube_channel_id', '' ) );
	$playlist_id = trim( (string) get_option( 'sal_core_youtube_playlist_id', '' ) );
	$video       = [];

	// ── Tenta YouTube Data API v3 ─────────────────────────────────────────
	// Preferência: playlist quando configurada; senão canal inteiro.
	if ( $api_key && $playlist_id ) {
		// Pega até 50 itens e ordena por contentDetails.videoPublishedAt desc
		// pra retornar o vídeo de upload mais recente que está na playlist,
		// independente da ordem manual da playlist no YouTube.
		$url = add_query_arg( [
			'part'       => 'snippet,contentDetails',
			'playlistId' => $playlist_id,
			'maxResults' => 50,
			'key'        => $api_key,
		], 'https://www.googleapis.com/youtube/v3/playlistItems' );

		$response = wp_remote_get( $url, [ 'timeout' => 8 ] );

		if ( ! is_wp_error( $response )
			&& 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data  = json_decode( wp_remote_retrieve_body( $response ), true );
			$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : [];
			usort( $items, function ( $a, $b ) {
				$da = $a['contentDetails']['videoPublishedAt'] ?? $a['snippet']['publishedAt'] ?? '';
				$db = $b['contentDetails']['videoPublishedAt'] ?? $b['snippet']['publishedAt'] ?? '';
				return strcmp( $db, $da );
			} );
			if ( ! empty( $items[0]['snippet']['resourceId']['videoId'] ) ) {
				$video = [
					'id'    => $items[0]['snippet']['resourceId']['videoId'],
					'title' => $items[0]['snippet']['title'] ?? '',
				];
			}
		}
	} elseif ( $api_key && $channel_id ) {
		$url = add_query_arg( [
			'part'       => 'snippet',
			'channelId'  => $channel_id,
			'order'      => 'date',
			'maxResults' => 1,
			'type'       => 'video',
			'key'        => $api_key,
		], 'https://www.googleapis.com/youtube/v3/search' );

		$response = wp_remote_get( $url, [ 'timeout' => 8 ] );

		if ( ! is_wp_error( $response )
			&& 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $data['items'][0]['id']['videoId'] ) ) {
				$video = [
					'id'    => $data['items'][0]['id']['videoId'],
					'title' => $data['items'][0]['snippet']['title'] ?? '',
				];
			}
		}
	}

	// ── Fallback: RSS público (playlist se configurada, senão canal) ─────
	if ( empty( $video ) ) {
		if ( $playlist_id ) {
			$feed_url = 'https://www.youtube.com/feeds/videos.xml?playlist_id='
			            . urlencode( $playlist_id );
		} else {
			$channel  = $channel_id ?: 'UCZ9bK5YKp6-sRPY1lgdPb5w';
			$feed_url = 'https://www.youtube.com/feeds/videos.xml?channel_id='
			            . urlencode( $channel );
		}

		$response = wp_remote_get( $feed_url, [ 'timeout' => 8 ] );

		if ( ! is_wp_error( $response )
			&& 200 === wp_remote_retrieve_response_code( $response ) ) {
			$xml = simplexml_load_string( wp_remote_retrieve_body( $response ) );
			if ( $xml && isset( $xml->entry[0] ) ) {
				$entry = $xml->entry[0];
				$ns    = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
				$video = [
					'id'    => (string) $ns->videoId,
					'title' => (string) $entry->title,
				];
			}
		}
	}

	// Cache por 1 hora (ou array vazio se falhou — tenta de novo na próxima hora).
	set_transient( 'sal_theme_latest_video', $video, HOUR_IN_SECONDS );

	return $video;
}
