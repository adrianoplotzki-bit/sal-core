<?php
/**
 * Template part: home/newsletter.php
 *
 * Card de newsletter com formulário Mailchimp.
 * NÃO usa o shortcode [sal_newsletter] para evitar os estilos inline do plugin.
 * Lê a action URL diretamente de get_option('sal_core_mailchimp_action').
 *
 * Débito técnico (§8 do plano): quando o shortcode for refatorado para usar
 * classes CSS, substituir este markup pelo do_shortcode('[sal_newsletter]').
 *
 * Classes aplicadas ao form: .sal-newsletter
 * Classes dos campos: .sal-newsletter__input, .sal-newsletter__button
 *
 * @package sal-theme
 */

$mailchimp_action = trim( (string) get_option( 'sal_core_mailchimp_action', '' ) );
?>

<section class="sal-newsletter-card">

	<h3 class="sal-newsletter-card__title">
		<?php esc_html_e( 'Receba nosso boletim', 'sal-theme' ); ?>
	</h3>

	<p class="sal-newsletter-card__desc">
		<?php esc_html_e( 'Novidades, bastidores e relatórios mensais.', 'sal-theme' ); ?>
	</p>

	<?php if ( $mailchimp_action ) : ?>

		<form
			class="sal-newsletter"
			action="<?php echo esc_url( $mailchimp_action ); ?>"
			method="post"
			target="_blank"
			novalidate
		>
			<input
				class="sal-newsletter__input"
				type="email"
				name="EMAIL"
				placeholder="<?php esc_attr_e( 'Digite seu e-mail', 'sal-theme' ); ?>"
				required
				autocomplete="email"
			>

			<!--
				Campo honeypot anti-bot exigido pelo Mailchimp.
				Deve estar fora da viewport — escondido via CSS absoluto.
			-->
			<div style="position:absolute;left:-5000px;" aria-hidden="true">
				<input type="text" name="b_<?php echo esc_attr( wp_rand() ); ?>" tabindex="-1" value="">
			</div>

			<button class="sal-newsletter__button" type="submit">
				<?php esc_html_e( 'Assinar', 'sal-theme' ); ?>
			</button>

		</form><!-- .sal-newsletter -->

	<?php else : ?>

		<p class="sal-newsletter__placeholder">
			<?php esc_html_e(
				'Configure o formulário em SAL Core → Configurações.',
				'sal-theme'
			); ?>
		</p>

	<?php endif; ?>

</section><!-- .sal-newsletter-card -->
