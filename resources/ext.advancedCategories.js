'use strict';

( () => {
	const scroll_current_letter = () => {
		const current = document.querySelector(
			'#advancedcategories-pages-app .advancedcategories-az-index__letter--current'
		);
		if ( current ) {
			current.scrollIntoView( {
				inline: 'center',
				block: 'nearest'
			} );
		}
	};

	const parse_hrefs = ( raw ) => {
		try {
			const parsed = JSON.parse( raw );
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch {
			return {};
		}
	};

	const parse_page = ( value ) => {
		const trimmed = String( value ).trim();
		if ( !/^[0-9]+$/.test( trimmed ) ) {
			return null;
		}
		const page = Number.parseInt( trimmed, 10 );
		return Number.isFinite( page ) ? page : null;
	};

	const bind_jump = ( pager ) => {
		const hrefs = parse_hrefs( pager.getAttribute( 'data-hrefs' ) || '{}' );
		const current = Number.parseInt( pager.getAttribute( 'data-current' ) || '1', 10 );
		const page_count = Number.parseInt( pager.getAttribute( 'data-page-count' ) || '1', 10 );

		pager.querySelectorAll( '.advancedcategories-pager__jump-slot' ).forEach( ( slot ) => {
			const button = slot.querySelector( '.advancedcategories-pager__ellipsis' );
			if ( !button || button.dataset.jumpBound ) {
				return;
			}
			button.dataset.jumpBound = '1';

			const parsed_size = Number.parseInt( button.getAttribute( 'data-jump-size' ) || '', 10 );
			const jump_size = Math.max( 2, parsed_size || String( page_count ).length );
			const jump_label = button.getAttribute( 'aria-label' ) || '';

			const close = () => {
				if ( button.parentNode !== slot ) {
					slot.replaceChildren( button );
				}
			};

			const go = ( value ) => {
				const page = parse_page( value );
				if ( page === null ) {
					return false;
				}
				const next = Math.min( page_count, Math.max( 1, page ) );
				if ( next === current ) {
					close();
					button.focus();
					return true;
				}
				const href = hrefs[ String( next ) ];
				if ( href ) {
					window.location.assign( href );
					return true;
				}
				return false;
			};

			const open = () => {
				const form = document.createElement( 'form' );
				form.className = 'advancedcategories-pager__jump';

				const input = document.createElement( 'input' );
				input.className = 'advancedcategories-pager__jump-input';
				input.type = 'text';
				input.inputMode = 'numeric';
				input.autocomplete = 'off';
				input.spellcheck = false;
				input.enterKeyHint = 'go';
				input.size = jump_size;
				input.maxLength = jump_size;
				input.setAttribute( 'aria-label', jump_label );
				input.style.width = 'calc( ' + jump_size + 'ch + var(--space-xs) * 2 )';
				form.appendChild( input );

				form.addEventListener( 'submit', ( event ) => {
					event.preventDefault();
					go( input.value );
				} );
				input.addEventListener( 'keydown', ( event ) => {
					if ( event.key === 'Escape' ) {
						event.preventDefault();
						close();
						button.focus();
					}
				} );

				slot.replaceChildren( form );
				window.setTimeout( () => {
					input.focus();
					input.addEventListener( 'blur', close );
				}, 0 );
			};

			button.addEventListener( 'click', open );
		} );
	};

	const init = () => {
		scroll_current_letter();
		document.querySelectorAll( '#advancedcategories-pages-app .advancedcategories-pager' )
			.forEach( bind_jump );
	};

	if ( typeof mw !== 'undefined' && mw.hook ) {
		mw.hook( 'wikipage.content' ).add( init );
	} else if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
