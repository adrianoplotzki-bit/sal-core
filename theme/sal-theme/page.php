<?php
/**
 * Template: page.php
 *
 * Template para páginas estáticas do WordPress (post_type = page).
 * Estrutura: header → main.sal-container.sal-page → article.sal-prose → footer.
 *
 * A classe .sal-prose aplica tipografia de leitura longa:
 * max-width 70ch, line-height 1.7, espaçamento entre parágrafos e
 * estilos de h2, h3, ul, ol dentro do artigo.
 *
 * @package sal-theme
 */

get_header();
?>

<main id="sal-main" class="sal-container sal-page">

	<?php
	while ( have_posts() ) :
		the_post();
	?>

		<article id="post-<?php the_ID(); ?>" <?php post_class( 'sal-prose' ); ?>>

			<header class="sal-page__header">
				<h1 class="sal-page__title"><?php the_title(); ?></h1>
			</header><!-- .sal-page__header -->

			<div class="sal-prose__body">
				<?php the_content(); ?>
			</div><!-- .sal-prose__body -->

		</article><!-- #post-<?php the_ID(); ?> -->

	<?php endwhile; ?>

</main><!-- .sal-page -->

<?php
get_footer();
