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
		<?php esc_html_e( 'Transparência', 'sal-theme' ); ?>
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
				<?php esc_html_e( 'Veja detalhadamente a nossa saúde financeira.', 'sal-theme' ); ?>
			</p>

			<a class="sal-trans-card__link sal-trans-card__link--blue"
			   href="<?php echo $balanco_url; ?>">
				<?php esc_html_e( 'Ver números', 'sal-theme' ); ?> &rarr;
			</a>

		</article>

		<!-- Card B: Prestação de Contas (verde) -->
		<article class="sal-trans-card sal-trans-card--green">

			<div class="sal-trans-card__icon" aria-hidden="true">
				<!-- Ícone: documento / lista -->
				<svg width="24" height="24" viewBox="0 0 24 24"
				     fill="none" stroke="currentColor"
				     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
				     aria-hidden="true" focusable="false">
					<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
					<polyline points="14 2 14 8 20 8"/>
					<line x1="9" y1="13" x2="15" y2="13"/>
					<line x1="9" y1="17" x2="15" y2="17"/>
					<polyline points="9 9 10 9 11 9"/>
				</svg>
			</div>

			<h3 class="sal-trans-card__title">
				<?php esc_html_e( 'Prestação de Contas', 'sal-theme' ); ?>
			</h3>

			<p class="sal-trans-card__desc">
				<?php esc_html_e( 'Entenda nossas metas e estratégias editoriais.', 'sal-theme' ); ?>
			</p>

			<a class="sal-trans-card__link sal-trans-card__link--green"
			   href="<?php echo $balanco_url; ?>">
				<?php esc_html_e( 'Entenda a meta', 'sal-theme' ); ?> &rarr;
			</a>

		</article>

	</div><!-- .sal-transparency__grid -->

</section><!-- .sal-transparency -->
