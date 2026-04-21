<?php
/**
 * Template part: home/latest-video.php
 *
 * Card do último vídeo do canal. Usa sal_theme_get_latest_video() (functions.php)
 * para obter o ID e título via YouTube API v3 ou RSS.
 *
 * Se nenhum vídeo for encontrado, exibe o fallback-video.jpg com link para /videos.
 *
 * @package sal-theme
 */

$video     = function_exists( 'sal_theme_get_latest_video' ) ? sal_theme_get_latest_video() : [];
$has_video = ! empty( $video['id'] );

if ( $has_video ) {
	$thumb_url   = 'https://img.youtube.com/vi/' . esc_attr( $video['id'] ) . '/maxresdefault.jpg';
	$link_url    = 'https://www.youtube.com/watch?v=' . esc_attr( $video['id'] );
	$link_target = '_blank';
	$link_rel    = 'noopener noreferrer';
	$img_alt     = esc_attr( $video['title'] );
	$aria_label  = sprintf(
		/* translators: %s: título do vídeo */
		esc_attr__( 'Assistir: %s', 'sal-theme' ),
		esc_attr( $video['title'] )
	);
} else {
	$thumb_url   = get_template_directory_uri() . '/assets/img/fallback-video.jpg';
	$link_url    = esc_url( home_url( '/videos' ) );
	$link_target = '_self';
	$link_rel    = '';
	$img_alt     = esc_attr__( 'Ver todos os vídeos do canal', 'sal-theme' );
	$aria_label  = esc_attr__( 'Ver todos os vídeos do canal', 'sal-theme' );
}
?>

<section class="sal-latest-video">

	<h2 class="sal-section-title">
		<?php esc_html_e( 'Assista ao último vídeo', 'sal-theme' ); ?>
	</h2>

	<a class="sal-latest-video__card"
	   href="<?php echo esc_url( $link_url ); ?>"
	   target="<?php echo esc_attr( $link_target ); ?>"
	   <?php if ( $link_rel ) : ?>rel="<?php echo esc_attr( $link_rel ); ?>"<?php endif; ?>
	   aria-label="<?php echo $aria_label; ?>">

		<!-- Thumbnail -->
		<img
			class="sal-latest-video__thumb"
			src="<?php echo esc_url( $thumb_url ); ?>"
			alt="<?php echo $img_alt; ?>"
			loading="lazy"
			width="1280"
			height="720"
		>

		<!-- Overlay escuro + botão play -->
		<div class="sal-latest-video__overlay" aria-hidden="true">
			<div class="sal-latest-video__play">
				<!-- Ícone play (triângulo) -->
				<svg width="32" height="32" viewBox="0 0 24 24"
				     fill="currentColor" aria-hidden="true" focusable="false">
					<path d="M8 5v14l11-7z"/>
				</svg>
			</div>
		</div>

		<?php if ( $has_video && ! empty( $video['title'] ) ) : ?>
		<!-- Título do vídeo sobre a imagem (rodapé do card) -->
		<div class="sal-latest-video__caption">
			<?php echo esc_html( $video['title'] ); ?>
		</div>
		<?php endif; ?>

	</a>

</section><!-- .sal-latest-video -->
