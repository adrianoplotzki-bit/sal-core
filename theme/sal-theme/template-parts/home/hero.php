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
			<?php esc_html_e( 'Jornalismo independente, transparente e comunitário.', 'sal-theme' ); ?>
		</h1>

		<p class="sal-hero__subtitle">
			<?php esc_html_e( 'Reportagens que prestam contas e convidam você a participar.', 'sal-theme' ); ?>
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
			   href="<?php echo $balanco_url; ?>">
				<!-- Ícone mini-gráfico de barras (SVG inline, acessível via aria-hidden) -->
				<svg aria-hidden="true" focusable="false"
				     width="20" height="20" viewBox="0 0 24 24"
				     fill="none" stroke="currentColor"
				     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M9 19v-6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2z"/>
					<path d="M15 19V9a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v10"/>
					<path d="M21 19V5a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2z"/>
				</svg>
				<?php esc_html_e( 'Transparência em tempo real', 'sal-theme' ); ?>
			</a>

		</div><!-- .sal-hero__actions -->

	</div><!-- .sal-hero__content -->

</section><!-- .sal-hero -->
