<?php
/**
 * Template part: header/nav.php
 *
 * Menu de navegação principal. Incluído via get_template_part() no header.php.
 * Usa o location 'primary' registrado em functions.php.
 * Se nenhum menu estiver atribuído, exibe um fallback com os links padrão do site.
 *
 * @package sal-theme
 */

if ( ! function_exists( 'sal_theme_fallback_nav' ) ) :
	/**
	 * Fallback para quando nenhum menu está atribuído ao location 'primary'.
	 * Exibe os três links principais definidos pelo protótipo.
	 */
	function sal_theme_fallback_nav() {
		$items = [
			__( 'O que é o #SAL', 'sal-theme' )     => '/quem-somos',
			__( 'Acompanhe o Balanço', 'sal-theme' ) => '/balanco',
			__( 'Clube de Vantagens', 'sal-theme' )  => '/clube-sal',
		];

		echo '<ul class="sal-nav__list">';
		foreach ( $items as $label => $path ) {
			printf(
				'<li><a href="%s">%s</a></li>',
				esc_url( home_url( $path ) ),
				esc_html( $label )
			);
		}
		echo '</ul>';
	}
endif;
?>

<nav id="sal-primary-nav"
     class="sal-nav"
     aria-label="<?php esc_attr_e( 'Menu principal', 'sal-theme' ); ?>">
	<?php
	wp_nav_menu( [
		'theme_location' => 'primary',
		'container'      => false,         // o <nav> já é o container
		'menu_class'     => 'sal-nav__list',
		'depth'          => 1,             // sem sub-menus nesta fase
		'fallback_cb'    => 'sal_theme_fallback_nav',
	] );
	?>
</nav><!-- #sal-primary-nav -->
