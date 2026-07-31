<?php
/**
 * Template part: home/hero.php
 *
 * Hero full-width da home: imagem de fundo, overlay escuro, título,
 * subtítulo e dois botões de ação.
 *
 * Markup:
 *   section.sal-hero
 *     img.sal-hero__bg          (imagem de fundo, object-fit: cover)
 *     div.sal-hero__overlay     (rgba 40% preto via ::before no CSS)
 *     div.sal-hero__content     (texto + botões, z-index acima do overlay)
 *
 * @package sal-theme
 */

$hero_img = get_template_directory_uri() . '/assets/img/hero.jpg';
$apoia_url = 'https://apoia.se/hashtagsal';
$balanco_url = esc_url( home_url( '/balanco' ) );
?>

<section class="sal-hero" aria-label="<?php esc_attr_e( 'Apresentação do #SAL', 'sal-theme' ); ?>">

	<!-- Imagem de fundo -->
	<img
		class="sal-hero__bg"
		src="<?php echo esc_url( $hero_img ); ?>"
		alt=""
		aria-hidden="true"
		width="1600"
		height="900"
	>

	<!-- Overlay escuro (também reforçado via CSS ::before) -->
	<div class="sal-hero__overlay" aria-hidden="true"></div>

	<!-- Conteúdo -->
	<div class="sal-container sal-hero__content">

		<h1 class="sal-hero__title">
			<?php esc_html_e( 'A vida de quem escolheu o oceano como lar', 'sal-theme' ); ?>
		</h1>

		<p class="sal-hero__subtitle">
			<?php esc_html_e( 'Videos no YouTube que mostram a costa brasileira e nos fazem repensar o jeito que vivemos.', 'sal-theme' ); ?>
		</p>

		<div class="sal-hero__actions">

			<!-- Botão primário: apoiar -->
			<a class="sal-btn sal-btn--primary"
			   href="<?php echo esc_url( $apoia_url ); ?>"
			   target="_blank"
			   rel="noopener noreferrer">
				<?php esc_html_e( 'Apoiar agora', 'sal-theme' ); ?>
			</a>

			<!-- Botão secundário: transparência (ghost branco com ícone de gráfico) -->
			<a class="sal-btn sal-btn--ghost"
			   href="<?php echo esc_url( $balanco_url ); ?>">
				<!-- Ícone âncora / localização (onde está o Balanço) -->
				<svg aria-hidden="true" focusable="false"
				     width="20" height="20" viewBox="0 0 24 24"
				     fill="none" stroke="currentColor"
				     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="12" cy="5" r="3"/>
					<line x1="12" y1="8" x2="12" y2="21"/>
					<path d="M5 12H2a10 10 0 0 0 20 0h-3"/>
				</svg>
				<?php esc_html_e( 'Descubra onde está o Balanço', 'sal-theme' ); ?>
			</a>

		</div><!-- .sal-hero__actions -->

	</div><!-- .sal-hero__content -->

</section><!-- .sal-hero -->
