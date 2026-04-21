<?php
/**
 * Template: single.php
 *
 * Template para posts individuais do WordPress (post_type = post).
 * Estrutura idêntica ao page.php:
 * header → main.sal-container.sal-page → article.sal-prose → footer.
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
