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
	<!-- Bebas Neue (logo, menus e destaques): 400 -->
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;600;800&display=swap">

	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="sal-skip-link" href="#sal-main">
	<?php esc_html_e( 'Pular para o conteúdo', 'sal-theme' ); ?>
</a>

<header class="sal-header" role="banner">
	<div class="sal-container sal-header__inner">

		<!-- Logo -->
		<?php
		/*
		 * O logo é a IMAGEM do arquivo real, nunca "#SAL" desenhado numa
		 * fonte parecida. Até 2026-08-02 esta linha era texto em Bebas Neue
		 * — perto do logo de verdade, e por isso mesmo enganoso: o traço do
		 * "#" da marca não é o da fonte.
		 *
		 * `width`/`height` são os do ARQUIVO (264×183), não os exibidos: eles
		 * existem para o navegador reservar o espaço antes de a imagem
		 * chegar e não empurrar o menu para o lado. O tamanho na tela vem do
		 * CSS.
		 */
		?>
		<a class="sal-logo"
		   href="<?php echo esc_url( home_url( '/' ) ); ?>"
		   aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?> — início">
			<img src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/logo-sal.png' ); ?>"
			     alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
			     width="264" height="183">
		</a>

		<!-- Menu principal (extraído para template-part) -->
		<?php get_template_part( 'template-parts/header/nav' ); ?>

		<!-- CTA: apoiar -->
		<a class="sal-btn sal-btn--primary sal-header__cta"
		   href="https://apoia.se/hashtagsal"
		   target="_blank"
		   rel="noopener noreferrer">
			<?php esc_html_e( 'Apoiar o #SAL', 'sal-theme' ); ?>
		</a>

		<!-- Botão hambúrguer (visível apenas em mobile) -->
		<button class="sal-nav-toggle"
		        aria-expanded="false"
		        aria-controls="sal-primary-nav"
		        aria-label="<?php esc_attr_e( 'Abrir menu de navegação', 'sal-theme' ); ?>">
			<span class="sal-nav-toggle__bar"></span>
			<span class="sal-nav-toggle__bar"></span>
			<span class="sal-nav-toggle__bar"></span>
			<span class="sal-sr-only"><?php esc_html_e( 'Menu', 'sal-theme' ); ?></span>
		</button>

	</div><!-- .sal-header__inner -->
</header><!-- .sal-header -->
