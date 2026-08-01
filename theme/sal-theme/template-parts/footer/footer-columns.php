<?php
/**
 * Template part: footer/footer-columns.php
 *
 * Quatro colunas do rodapé:
 *   1. Logo + domínio + missão
 *   2. Navegação (links internos)
 *   3. Participar (apoiar + newsletter + contato)
 *   4. Social (YouTube + Instagram com ícones SVG)
 *
 * Links confirmados pelo plano. URLs sociais apontam para os handles
 * oficiais do canal #SAL. Atualizar quando necessário.
 *
 * @package sal-theme
 */
?>

<div class="sal-footer__columns">

	<!-- Coluna 1: Identidade -->
	<div class="sal-footer__col">
		<a class="sal-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'HashtagSal — início', 'sal-theme' ); ?>">
			#SAL
		</a>
		<p class="sal-footer__domain">hashtagsal.com.br</p>
		<p class="sal-footer__mission">
			<?php esc_html_e(
				'Por um ponto de vista diferente, um alongamento com o pensamento para imaginar o quanto diferente a vida poderia ser.',
				'sal-theme'
			); ?>
		</p>
	</div>

	<!-- Coluna 2: Navegação -->
	<div class="sal-footer__col">
		<h3 class="sal-footer__heading"><?php esc_html_e( 'Navegação', 'sal-theme' ); ?></h3>
		<ul class="sal-footer__links">
			<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'sal-theme' ); ?></a></li>
			<li><a href="<?php echo esc_url( home_url( '/quem-somos' ) ); ?>"><?php esc_html_e( 'O que é o #SAL', 'sal-theme' ); ?></a></li>
			<li><a href="<?php echo esc_url( home_url( '/balanco' ) ); ?>"><?php esc_html_e( 'Acompanhe o Balanço', 'sal-theme' ); ?></a></li>
			<li><a href="<?php echo esc_url( home_url( '/clube-sal' ) ); ?>"><?php esc_html_e( 'Apoiadores do #SAL', 'sal-theme' ); ?></a></li>
		</ul>
	</div>

	<!-- Coluna 3: Participar -->
	<div class="sal-footer__col">
		<h3 class="sal-footer__heading"><?php esc_html_e( 'Participar', 'sal-theme' ); ?></h3>
		<ul class="sal-footer__links">
			<li>
				<a href="https://apoia.se/hashtagsal" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Apoiar o #SAL', 'sal-theme' ); ?>
				</a>
			</li>
			<li><a href="<?php echo esc_url( home_url( '/contato' ) ); ?>"><?php esc_html_e( 'Newsletter', 'sal-theme' ); ?></a></li>
			<li><a href="<?php echo esc_url( home_url( '/contato' ) ); ?>"><?php esc_html_e( 'Contato', 'sal-theme' ); ?></a></li>
		</ul>
	</div>

	<!-- Coluna 4: Social -->
	<div class="sal-footer__col">
		<h3 class="sal-footer__heading"><?php esc_html_e( 'Social', 'sal-theme' ); ?></h3>
		<ul class="sal-footer__links sal-footer__links--social">

			<!-- YouTube -->
			<li>
				<a href="https://youtube.com/@hashtagsal" target="_blank" rel="noopener noreferrer" class="sal-footer__social-link">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
						<path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
					</svg>
					<span>YouTube</span>
				</a>
			</li>

			<!-- Instagram -->
			<li>
				<a href="https://instagram.com/hashtagsal_oficial" target="_blank" rel="noopener noreferrer" class="sal-footer__social-link">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
						<path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>
					</svg>
					<span>Instagram</span>
				</a>
			</li>

		</ul>
	</div>

</div><!-- .sal-footer__columns -->
