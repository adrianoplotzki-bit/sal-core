<?php
/**
 * Template: front-page.php
 *
 * Home do site #SAL. Usado quando uma página estática está definida como
 * "página inicial" em Configurações → Leitura, OU quando existe esta
 * template no tema (WP prefere front-page.php sobre index.php para a home).
 *
 * Estrutura por etapa:
 *   Etapa 5:  Hero
 *   Etapa 6:  Grid .sal-home-grid — coluna esquerda (último vídeo + transparência)
 *   Etapa 7:  Grid .sal-home-grid — coluna direita  (sobre o SAL + comunidade + newsletter)
 *
 * @package sal-theme
 */

get_header();
?>

<main id="sal-main" class="sal-main">

	<?php /* ── Hero ──────────────────────────────────────────────────────── */ ?>
	<?php get_template_part( 'template-parts/home/hero' ); ?>

	<?php /* ── Grid principal da home ─────────────────────────────────────── */ ?>
	<section class="sal-home-section">
		<div class="sal-container">
			<div class="sal-home-grid">

				<?php /* ── Coluna esquerda (7fr) — vídeo + transparência ── */ ?>
				<div class="sal-home-grid__col--left">
					<?php get_template_part( 'template-parts/home/latest-video' ); ?>
					<?php get_template_part( 'template-parts/home/transparency' ); ?>
				</div><!-- .sal-home-grid__col--left -->

				<?php
				/*
				 * ── Coluna direita (5fr) — Etapa 7 ──────────────────────
				 *
				 * about-sal + community + newsletter serão adicionados aqui.
				 */
				?>
				<div class="sal-home-grid__col--right">
					<!-- Conteúdo da coluna direita será adicionado na Etapa 7 -->
				</div><!-- .sal-home-grid__col--right -->

			</div><!-- .sal-home-grid -->
		</div><!-- .sal-container -->
	</section><!-- .sal-home-section -->

</main><!-- #sal-main -->

<?php
get_footer();
