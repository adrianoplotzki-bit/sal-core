<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="sal-header">
	<div class="sal-container">
		<a class="sal-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">#SAL</a>
		<?php
		wp_nav_menu( [
			'theme_location' => 'primary',
			'container'      => 'nav',
			'container_attr' => [ 'class' => 'sal-nav', 'aria-label' => __( 'Menu principal', 'sal-theme' ) ],
			'menu_class'     => 'sal-nav__list',
			'fallback_cb'    => false,
		] );
		?>
	</div><!-- .sal-container -->
</header><!-- .sal-header -->
