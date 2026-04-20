<?php
/**
 * index.php — Template fallback genérico do SAL Theme.
 *
 * Este arquivo é o fallback obrigatório do WordPress quando nenhum
 * template mais específico (front-page.php, page.php, single.php, etc.)
 * for encontrado.
 *
 * @package sal-theme
 */

get_header();
?>

<main class="sal-main sal-container">

	<?php if ( have_posts() ) : ?>

		<?php while ( have_posts() ) : the_post(); ?>

			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

				<header class="entry-header">
					<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
				</header>

				<div class="entry-content">
					<?php the_content(); ?>
				</div>

			</article>

		<?php endwhile; ?>

	<?php else : ?>

		<p><?php esc_html_e( 'Nenhum conteúdo encontrado.', 'sal-theme' ); ?></p>

	<?php endif; ?>

</main><!-- .sal-main -->

<?php get_footer(); ?>
