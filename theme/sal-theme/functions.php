<?php
/**
 * SAL Theme — functions.php
 *
 * Funções e configurações do tema. Os enqueues de CSS e JS serão
 * populados nas etapas seguintes (design system, header, etc.).
 *
 * @package sal-theme
 * @version 0.1.0
 */

defined( 'ABSPATH' ) || exit;

define( 'SAL_THEME_VERSION', '0.1.0' );
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
}

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

	$api_key    = trim( (string) get_option( 'sal_core_youtube_api_key', '' ) );
	$channel_id = trim( (string) get_option( 'sal_core_youtube_channel_id', '' ) );
	$video      = [];

	// ── Tenta YouTube Data API v3 ─────────────────────────────────────────
	if ( $api_key && $channel_id ) {
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

	// ── Fallback: RSS público do canal ────────────────────────────────────
	if ( empty( $video ) ) {
		// Canal padrão do #SAL caso a option ainda não esteja preenchida.
		$channel  = $channel_id ?: 'UCZ9bK5YKp6-sRPY1lgdPb5w';
		$feed_url = 'https://www.youtube.com/feeds/videos.xml?channel_id='
		            . urlencode( $channel );

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
