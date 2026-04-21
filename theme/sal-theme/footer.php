<footer class="sal-footer" role="contentinfo">

	<!-- Quatro colunas: identidade, navegação, participar, social -->
	<div class="sal-container">
		<?php get_template_part( 'template-parts/footer/footer-columns' ); ?>
	</div>

	<!-- Barra inferior: copyright + privacidade -->
	<div class="sal-footer__bar">
		<div class="sal-container sal-footer__bar-inner">
			<p class="sal-footer__copyright">
				&copy; <?php echo esc_html( date( 'Y' ) ); ?> #SAL &mdash; <?php esc_html_e( 'Jornalismo independente', 'sal-theme' ); ?>
			</p>
			<a class="sal-footer__privacy" href="<?php echo esc_url( home_url( '/privacidade' ) ); ?>">
				<?php esc_html_e( 'Política de Privacidade', 'sal-theme' ); ?>
			</a>
		</div>
	</div><!-- .sal-footer__bar -->

</footer><!-- .sal-footer -->

<?php wp_footer(); ?>
</body>
</html>
