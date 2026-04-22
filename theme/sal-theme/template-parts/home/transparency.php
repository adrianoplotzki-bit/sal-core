<?php
/**
 * Template part: home/transparency.php
 *
 * Seção de transparência com dois cards:
 *   A) Acompanhe o Balanço  (azul) → /balanco
 *   B) Prestação de Contas  (verde) → /balanco (nesta fase; futuro: /prestacao-de-contas)
 *
 * Ícones SVG inline para evitar dependência de sprite ou icon font.
 * Cores via tokens: --sal-blue-tint / --sal-blue-accent / --sal-green-tint / --sal-green.
 *
 * @package sal-theme
 */

$balanco_url = esc_url( home_url( '/balanco' ) );
?>

<section class="sal-transparency">

	<h2 class="sal-section-title">
		<?php esc_html_e( 'Acompanhe', 'sal-theme' ); ?>
	</h2>

	<div class="sal-transparency__grid">

		<!-- Card A: Acompanhe o Balanço (azul) -->
		<article class="sal-trans-card sal-trans-card--blue">

			<div class="sal-trans-card__icon" aria-hidden="true">
				<!-- Ícone: moeda / círculo monetário -->
				<svg width="24" height="24" viewBox="0 0 24 24"
				     fill="none" stroke="currentColor"
				     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
				     aria-hidden="true" focusable="false">
					<circle cx="12" cy="12" r="10"/>
					<path d="M12 6v2m0 8v2M9.5 9a2.5 2.5 0 0 1 5 0c0 1.5-2.5 2-2.5 3.5M12 15.5h.01"/>
				</svg>
			</div>

			<h3 class="sal-trans-card__title">
				<?php esc_html_e( 'Acompanhe o Balanço', 'sal-theme' ); ?>
			</h3>

			<p class="sal-trans-card__desc">
				<?php esc_html_e( 'Saúde financeira do canal, receitas e despesas abertas.', 'sal-theme' ); ?>
			</p>

			<a class="sal-trans-card__link sal-trans-card__link--blue"
			   href="<?php echo $balanco_url; ?>">
				<?php esc_html_e( 'Acompanhar', 'sal-theme' ); ?> &rarr;
			</a>

		</article>

		<!-- Card B: Descubra onde está o Balanço (verde) -->
		<article class="sal-trans-card sal-trans-card--green">

			<div class="sal-trans-card__icon" aria-hidden="true">
				<!-- Ícone: âncora / localização do veleiro -->
				<svg width="24" height="24" viewBox="0 0 24 24"
				     fill="none" stroke="currentColor"
				     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
				     aria-hidden="true" focusable="false">
					<circle cx="12" cy="5" r="3"/>
					<line x1="12" y1="8" x2="12" y2="21"/>
					<path d="M5 12H2a10 10 0 0 0 20 0h-3"/>
				</svg>
			</div>

			<h3 class="sal-trans-card__title">
				<?php esc_html_e( 'Descubra onde está o Balanço', 'sal-theme' ); ?>
			</h3>

			<p class="sal-trans-card__desc">
				<?php esc_html_e( 'Veja a posição e rota do veleiro em tempo real.', 'sal-theme' ); ?>
			</p>

			<a class="sal-trans-card__link sal-trans-card__link--green"
			   href="<?php echo $balanco_url; ?>">
				<?php esc_html_e( 'Ver no mapa', 'sal-theme' ); ?> &rarr;
			</a>

		</article>

	</div><!-- .sal-transparency__grid -->

</section><!-- .sal-transparency -->
