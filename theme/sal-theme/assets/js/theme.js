/**
 * SAL Theme — theme.js
 *
 * Toggle do menu mobile. Nenhuma dependência externa.
 * Ativado como IIFE para não poluir o escopo global.
 *
 * Comportamento:
 *  - Clique em .sal-nav-toggle → alterna .is-open no <body>
 *  - Atualiza aria-expanded no botão
 *  - Fecha com tecla Escape (devolve foco ao toggle)
 *  - Fecha ao clicar em qualquer link do menu (mobile)
 *  - Fecha ao clicar fora do header (mobile)
 *
 * @package sal-theme
 */
( function () {
	'use strict';

	var BREAKPOINT = 768; // px — deve coincidir com o CSS

	var toggle = document.querySelector( '.sal-nav-toggle' );
	var nav    = document.getElementById( 'sal-primary-nav' );

	// Abortar se os elementos não existirem (páginas sem header padrão)
	if ( ! toggle || ! nav ) {
		return;
	}

	// Guarda o label original para restaurar ao fechar
	var labelOpen  = toggle.getAttribute( 'aria-label' ) || 'Abrir menu de navegação';
	var labelClose = 'Fechar menu de navegação';

	/**
	 * Abre o menu mobile.
	 */
	function openMenu() {
		document.body.classList.add( 'is-open' );
		toggle.setAttribute( 'aria-expanded', 'true' );
		toggle.setAttribute( 'aria-label', labelClose );
	}

	/**
	 * Fecha o menu mobile.
	 *
	 * @param {boolean} [returnFocus] Se true, devolve o foco ao toggle.
	 */
	function closeMenu( returnFocus ) {
		document.body.classList.remove( 'is-open' );
		toggle.setAttribute( 'aria-expanded', 'false' );
		toggle.setAttribute( 'aria-label', labelOpen );

		if ( returnFocus ) {
			toggle.focus();
		}
	}

	/**
	 * Retorna true quando o menu mobile está aberto.
	 */
	function isOpen() {
		return document.body.classList.contains( 'is-open' );
	}

	// ── Clique no hambúrguer ─────────────────────────────────────────────────
	toggle.addEventListener( 'click', function () {
		if ( isOpen() ) {
			closeMenu();
		} else {
			openMenu();
		}
	} );

	// ── Fechar com Escape ────────────────────────────────────────────────────
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && isOpen() ) {
			closeMenu( true );
		}
	} );

	// ── Fechar ao clicar em link do menu (mobile) ────────────────────────────
	nav.addEventListener( 'click', function ( e ) {
		if ( e.target.tagName === 'A' && window.innerWidth < BREAKPOINT ) {
			closeMenu();
		}
	} );

	// ── Fechar ao clicar fora do header ──────────────────────────────────────
	document.addEventListener( 'click', function ( e ) {
		if ( ! isOpen() ) {
			return;
		}
		var header = document.querySelector( '.sal-header' );
		if ( header && ! header.contains( e.target ) ) {
			closeMenu();
		}
	} );

	// ── Fechar ao redimensionar acima do breakpoint ──────────────────────────
	window.addEventListener( 'resize', function () {
		if ( window.innerWidth >= BREAKPOINT && isOpen() ) {
			closeMenu();
		}
	} );

}() );
