/* Offers screen: the typed fields, the rules filter, the bulk bar. */
( function ( $ ) {
	'use strict';

	var cfg = window.ocOffersAdmin || {};

	// One field that takes a category, a brand or a product by name.
	$( '.oc-of-pick' ).each( function () {
		var $el = $( this );

		$el.selectWoo( {
			minimumInputLength: 2,
			placeholder: $el.data( 'placeholder' ),
			width: '100%',
			ajax: {
				url: window.ajaxurl,
				dataType: 'json',
				delay: 250,
				data: function ( params ) {
					return {
						action: 'oc_offers_search',
						term: params.term,
						kinds: $el.data( 'kinds' ),
						_wpnonce: cfg.nonce
					};
				},
				processResults: function ( out ) {
					return { results: out && out.success ? out.data : [] };
				},
				cache: true
			}
		} );
	} );

	// All / active / inactive, without a round trip.
	document.querySelectorAll( '[data-oc-of-filter]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var want = btn.getAttribute( 'data-oc-of-filter' );

			document.querySelectorAll( '[data-oc-of-filter]' ).forEach( function ( b ) {
				b.setAttribute( 'aria-pressed', b === btn ? 'true' : 'false' );
			} );

			document.querySelectorAll( '.oc-of__rule[data-oc-of-on]' ).forEach( function ( card ) {
				var on = card.getAttribute( 'data-oc-of-on' ) === '1';
				card.classList.toggle( 'is-hidden', ( want === 'on' && ! on ) || ( want === 'off' && on ) );
			} );
		} );
	} );

	// A deletion asks once.
	document.querySelectorAll( '[data-oc-of-confirm]' ).forEach( function ( a ) {
		a.addEventListener( 'click', function ( e ) {
			if ( ! window.confirm( cfg.delete || 'Delete?' ) ) {
				e.preventDefault();
			}
		} );
	} );

	// The editor opens with its first field in view.
	if ( window.location.hash === '#oc-of-editor' ) {
		var ed = document.getElementById( 'oc-of-editor' );
		if ( ed ) {
			ed.scrollIntoView( { block: 'start' } );
			var first = ed.querySelector( 'input[name="name"]' );
			if ( first ) {
				first.focus( { preventScroll: true } );
			}
		}
	}

	// By product: the bulk bar follows the ticks.
	var bar = document.querySelector( '[data-oc-of-bulk]' );
	if ( bar ) {
		var boxes = document.querySelectorAll( '[data-oc-of-pick-row]' );
		var all = document.querySelector( '[data-oc-of-pick-all]' );
		var count = bar.querySelector( '[data-oc-of-count]' );
		var hidden = bar.querySelector( '[data-oc-of-ids]' );

		var sync = function () {
			var ids = [];
			boxes.forEach( function ( b ) {
				if ( b.checked ) {
					ids.push( b.value );
				}
			} );
			bar.hidden = ! ids.length;
			if ( count ) {
				count.textContent = ids.length;
			}
			if ( hidden ) {
				hidden.value = ids.join( ',' );
			}
		};

		boxes.forEach( function ( b ) {
			b.addEventListener( 'change', sync );
		} );
		if ( all ) {
			all.addEventListener( 'change', function () {
				boxes.forEach( function ( b ) {
					b.checked = all.checked;
				} );
				sync();
			} );
		}
		sync();
	}
}( window.jQuery ) );
