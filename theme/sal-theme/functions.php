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
	// Etapa 3: tokens.css e base.css serão enfileirados aqui.
	// Etapa 4: components.css e theme.js serão enfileirados aqui.
}
