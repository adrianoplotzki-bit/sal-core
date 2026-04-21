<?php
/**
 * Template: front-page.php
 *
 * Home do site #SAL. Usado quando uma página estática está definida como
 * "página inicial" em Configurações → Leitura, OU quando existe esta
 * template no tema (WP prefere front-page.php sobre index.php para a home).
 *
 * Estrutura:
 *   Hero
 *   Grid 7fr/5fr:
 *     Col esquerda: último vídeo + transparência
 *     Col direita:  sobre o SAL + comunidade + newsletter
 *
 * @package sal-theme
 */

get_header();
?>

<main id="sal-main" class="sal-main">

	<?php /* ── Hero ──────────────────────────────────────────────────────── */ ?>
	<?php get_template_part( 'template-parts/home/hero' ); ?>

	<?php /* ── Grid principal ───────────────────────────────────────────── */ ?>
	<section class="sal-home-section">
		<div class="sal-container">
			<div class="sal-home-grid">

				<!-- Coluna esquerda (7fr): vídeo + transparência -->
				<div class="sal-home-grid__col--left">
					<?php get_template_part( 'template-parts/home/latest-video' ); ?>
					<?php get_template_part( 'template-parts/home/transparency' ); ?>
				</div><!-- .sal-home-grid__col--left -->

				<!-- Coluna direita (5fr): sobre o SAL + comunidade + newsletter -->
				<div class="sal-home-grid__col--right">
					<?php get_template_part( 'template-parts/home/about-sal' ); ?>
					<?php get_template_part( 'template-parts/home/community' ); ?>
					<?php get_template_part( 'template-parts/home/newsletter' ); ?>
				</div><!-- .sal-home-grid__col--right -->

			</div><!-- .sal-home-grid -->
		</div><!-- .sal-container -->
	</section><!-- .sal-home-section -->

</main><!-- #sal-main -->

<?php
get_footer();
