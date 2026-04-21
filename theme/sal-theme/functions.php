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
