<?php
/**
 * Template part: home/community.php
 *
 * Seção "Da comunidade": mosaico de 3 fotos (grid 2×2, imagem inferior
 * ocupa as duas colunas) seguido de um card de quote.
 *
 * O texto do quote é editável via Customizer (Aparência → Personalizar)
 * com o texto padrão hardcoded como fallback.
 *
 * Estrutura do mosaico:
 *   [ 01.jpg ] [ 02.jpg ]   ← top-left / top-right
 *   [    03.jpg (span 2)  ] ← bottom, col-span 2
 *
 * @package sal-theme
 */

$img_dir = get_template_directory_uri() . '/assets/img/community/';

/* Texto da quote — editável via Customizer */
$quote = get_theme_mod(
	'sal_community_quote',
	/* translators: quote padrão da seção Da comunidade */
	__( '"Por um ponto de vista diferente, um alongamento com o pensamento para imaginar o quanto diferente a vida poderia ser."', 'sal-theme' )
);
?>

<section class="sal-community">

	<h2 class="sal-section-title">
		<?php esc_html_e( 'Da comunidade', 'sal-theme' ); ?>
	</h2>

	<!-- Mosaico fotográfico -->
	<div class="sal-community__mosaic" aria-hidden="true">

		<img
			class="sal-community__img sal-community__img--tl"
			src="<?php echo esc_url( $img_dir . '01.jpg' ); ?>"
			alt=""
			loading="lazy"
			width="800"
			height="450"
		>

		<img
			class="sal-community__img sal-community__img--tr"
			src="<?php echo esc_url( $img_dir . '02.jpg' ); ?>"
			alt=""
			loading="lazy"
			width="800"
			height="450"
		>

		<img
			class="sal-community__img sal-community__img--bottom"
			src="<?php echo esc_url( $img_dir . '03.jpg' ); ?>"
			alt=""
			loading="lazy"
			width="800"
			height="451"
		>

	</div><!-- .sal-community__mosaic -->

	<!-- Quote da comunidade -->
	<figure class="sal-community__quote">
		<blockquote>
			<p><?php echo esc_html( $quote ); ?></p>
		</blockquote>
	</figure>

</section><!-- .sal-community -->
