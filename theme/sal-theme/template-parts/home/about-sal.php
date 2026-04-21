<?php
/**
 * Template part: home/about-sal.php
 *
 * Card "O que é o SAL": título, foto, parágrafo introdutório e link
 * para a página /quem-somos.
 *
 * @package sal-theme
 */

$img_url     = get_template_directory_uri() . '/assets/img/about-sal.jpg';
$quem_url    = esc_url( home_url( '/quem-somos' ) );
?>

<section class="sal-about-card">

	<h2 class="sal-section-title sal-about-card__title">
		<?php esc_html_e( 'O que é o SAL', 'sal-theme' ); ?>
	</h2>

	<img
		class="sal-about-card__img"
		src="<?php echo esc_url( $img_url ); ?>"
		alt="<?php esc_attr_e( 'Adriano Plotzki, repórter do #SAL', 'sal-theme' ); ?>"
		loading="lazy"
		width="800"
		height="765"
	>

	<div class="sal-about-card__body">

		<p class="sal-about-card__text">
			<?php esc_html_e(
				'Faça parte de uma comunidade apaixonada por histórias autênticas. Exploramos temas reais com profundidade, transparência e compromisso com quem nos apoia.',
				'sal-theme'
			); ?>
		</p>

		<a class="sal-about-card__link" href="<?php echo $quem_url; ?>">
			<?php esc_html_e( 'Conheça o projeto', 'sal-theme' ); ?>
		</a>

	</div><!-- .sal-about-card__body -->

</section><!-- .sal-about-card -->
