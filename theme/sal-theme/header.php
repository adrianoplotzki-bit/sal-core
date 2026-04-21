<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">

	<!-- Google Fonts: preconnect para reduzir latência -->
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<!-- Inter (corpo + títulos): 400 Regular, 600 SemiBold, 800 ExtraBold -->
	<!-- Bebas Neue (menus + destaques): 400 -->
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;600;800&display=swap">

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
