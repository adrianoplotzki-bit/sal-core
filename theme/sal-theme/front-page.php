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
 *   Etapa 6:  Grid .sal-home-grid col-esquerda (último vídeo + transparência)
 *   Etapa 7:  Grid .sal-home-grid col-direita  (sobre o SAL + comunidade + newsletter)
 *
 * @package sal-theme
 */

get_header();
?>

<main id="sal-main" class="sal-main">

	<?php
	/* ── Hero ───────────────────────────────────────────────────────────── */
	get_template_part( 'template-parts/home/hero' );
	?>

	<?php
	/*
	 * ── Grid da home (Etapas 6 e 7) ─────────────────────────────────────
	 *
	 * As duas colunas serão adicionadas aqui nas próximas etapas.
	 * A estrutura esperada é:
	 *
	 *   <div class="sal-container">
	 *     <div class="sal-home-grid">
	 *       <div class="sal-home-grid__col--left">
	 *         latest-video + transparency
	 *       </div>
	 *       <div class="sal-home-grid__col--right">
	 *         about-sal + community + newsletter
	 *       </div>
	 *     </div>
	 *   </div>
	 */
	?>

</main><!-- #sal-main -->

<?php
get_footer();
