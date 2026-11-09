/* Offers screen: open fields that save as you pick, instant rows, the
 * rules filter, the typed rule fields, the bulk bar. */
( function ( $ ) {
	'use strict';

	var cfg = window.ocOffersAdmin || {};
	var ajaxurl = window.ajaxurl || '/wp-admin/admin-ajax.php';

	/* ---- a product search field: type, see pictures, pick ---- */

	function esc( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function search( term, exclude ) {
		var url = ajaxurl + '?action=oc_offers_search&kinds=p&term=' + encodeURIComponent( term ) + '&_wpnonce=' + encodeURIComponent( cfg.nonce || '' );
		return fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( out ) {
				var rows = out && out.success ? out.data : [];
				return rows.filter( function ( row ) { return row.pid && row.pid !== exclude; } );
			} );
	}

	function wirePick( box ) {
		var input = box.querySelector( 'input' );
		var menu = box.querySelector( '.oc-of__pickmenu' );
		var timer = null;
		var last = '';
		var onPick = box.ocOnPick || function () {};
		var exclude = parseInt( ( box.closest( '[data-product]' ) || {} ).getAttribute ? box.closest( '[data-product]' ).getAttribute( 'data-product' ) : 0, 10 ) || 0;

		function close() {
			menu.hidden = true;
			menu.innerHTML = '';
		}

		function render( rows ) {
			if ( ! rows.length ) {
				menu.innerHTML = '<div class="oc-of__pickempty">' + esc( cfg.none || 'No product found' ) + '</div>';
				menu.hidden = false;
				return;
			}
			menu.innerHTML = rows.map( function ( row, i ) {
				return '<button type="button" class="oc-of__pickrow' + ( i === 0 ? ' is-active' : '' ) + '" data-pid="' + row.pid + '" data-name="' + esc( row.name ) + '">'
					+ ( row.img ? '<img src="' + esc( row.img ) + '" alt="" />' : '<span class="oc-of__noimg"></span>' )
					+ '<span><strong>' + esc( row.name ) + '</strong><small>' + esc( row.price || '' ) + '</small></span></button>';
			} ).join( '' );
			menu.hidden = false;
		}

		input.addEventListener( 'input', function () {
			var term = input.value.trim();
			clearTimeout( timer );
			if ( term.length < 2 ) {
				close();
				return;
			}
			timer = setTimeout( function () {
				last = term;
				search( term, exclude ).then( function ( rows ) {
					if ( term === last ) {
						render( rows );
					}
				} );
			}, 220 );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			var rows = Array.prototype.slice.call( menu.querySelectorAll( '.oc-of__pickrow' ) );
			var at = rows.findIndex( function ( r ) { return r.classList.contains( 'is-active' ); } );
			if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
				if ( ! rows.length ) { return; }
				e.preventDefault();
				var next = e.key === 'ArrowDown' ? Math.min( rows.length - 1, at + 1 ) : Math.max( 0, at - 1 );
				rows.forEach( function ( r, i ) { r.classList.toggle( 'is-active', i === next ); } );
			} else if ( e.key === 'Enter' ) {
				if ( rows[ at ] ) {
					e.preventDefault();
					rows[ at ].click();
				}
			} else if ( e.key === 'Escape' ) {
				close();
			}
		} );

		menu.addEventListener( 'click', function ( e ) {
			var row = e.target.closest( '.oc-of__pickrow' );
			if ( ! row ) { return; }
			onPick( parseInt( row.getAttribute( 'data-pid' ), 10 ), row.getAttribute( 'data-name' ) );
			close();
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! box.contains( e.target ) ) { close(); }
		} );

		return { input: input };
	}

	/* ---- the lists: pick → saved at once; × → removed at once ---- */

	function link( what, kind, product, target ) {
		var data = new FormData();
		data.append( 'action', 'oc_offers_link' );
		data.append( '_wpnonce', cfg.link || '' );
		data.append( 'what', what );
		data.append( 'kind', kind );
		data.append( 'product', product );
		data.append( 'target', target );
		return fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } ).then( function ( r ) { return r.json(); } );
	}

	function flash( box ) {
		var el = box.querySelector( '[data-oc-of-saved]' );
		if ( ! el ) { return; }
		el.textContent = cfg.saved || 'Saved';
		el.classList.add( 'is-on' );
		clearTimeout( el.ocT );
		el.ocT = setTimeout( function () { el.classList.remove( 'is-on' ); el.textContent = ''; }, 1600 );
	}

	function afterChange( box, out ) {
		var kind = box.getAttribute( 'data-oc-of-list' );
		var row = box.closest( '.oc-of__detailrow' ) ? box.closest( '.oc-of__detailrow' ).previousElementSibling : null;
		var cell = row ? row.querySelector( '[data-oc-of-cell="' + kind + '"]' ) : null;
		if ( cell && out.cell ) { cell.innerHTML = out.cell; }
		var fb = box.querySelector( '[data-oc-of-fallback]' );
		if ( fb ) { fb.hidden = out.count > 0; }
		flash( box );
	}

	document.querySelectorAll( '[data-oc-of-list]' ).forEach( function ( box ) {
		var kind = box.getAttribute( 'data-oc-of-list' );
		var product = parseInt( box.getAttribute( 'data-product' ), 10 );
		var chips = box.querySelector( '[data-oc-of-chips]' );
		var pick = box.querySelector( '[data-oc-of-pick]' );

		pick.ocOnPick = function ( pid ) {
			if ( chips.querySelector( '[data-oc-of-chip="' + pid + '"]' ) ) { return; }
			link( 'add', kind, product, pid ).then( function ( out ) {
				if ( out && out.success ) {
					if ( out.data.chip ) { chips.insertAdjacentHTML( 'beforeend', out.data.chip ); }
					afterChange( box, out.data );
					pick.querySelector( 'input' ).value = '';
					pick.querySelector( 'input' ).focus();
				}
			} );
		};
		wirePick( pick );

		chips.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-oc-of-unlink]' );
			if ( ! btn ) { return; }
			var pid = parseInt( btn.getAttribute( 'data-oc-of-unlink' ), 10 );
			var chip = btn.closest( '[data-oc-of-chip]' );
			chip.classList.add( 'is-busy' );
			link( 'remove', kind, product, pid ).then( function ( out ) {
				if ( out && out.success ) {
					chip.remove();
					afterChange( box, out.data );
				} else {
					chip.classList.remove( 'is-busy' );
				}
			} );
		} );
	} );

	/* ---- rows open in place ---- */

	document.querySelectorAll( '[data-oc-of-open]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var row = btn.closest( 'tr' );
			var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
			var open = btn.getAttribute( 'aria-expanded' ) === 'true';
			btn.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
			panel.hidden = open;
			row.classList.toggle( 'is-open', ! open );
			if ( ! open ) {
				var first = panel.querySelector( '[data-oc-of-pick] input' );
				if ( first ) { first.focus( { preventScroll: true } ); }
			}
		} );
	} );

	/* ---- the bulk bar ---- */

	var bar = document.querySelector( '[data-oc-of-bulk]' );
	if ( bar ) {
		var boxes = document.querySelectorAll( '[data-oc-of-pick-row]' );
		var all = document.querySelector( '[data-oc-of-pick-all]' );
		var count = bar.querySelector( '[data-oc-of-count]' );
		var hidden = bar.querySelector( '[data-oc-of-ids]' );
		var target = bar.querySelector( '[data-oc-of-bulk-target]' );
		var addBtn = bar.querySelector( '[data-oc-of-bulk-add]' );
		var pick = bar.querySelector( '[data-oc-of-pick]' );

		var sync = function () {
			var ids = [];
			boxes.forEach( function ( b ) { if ( b.checked ) { ids.push( b.value ); } } );
			bar.hidden = ! ids.length;
			if ( count ) { count.textContent = ids.length; }
			if ( hidden ) { hidden.value = ids.join( ',' ); }
		};

		boxes.forEach( function ( b ) { b.addEventListener( 'change', sync ); } );
		if ( all ) {
			all.addEventListener( 'change', function () {
				boxes.forEach( function ( b ) { b.checked = all.checked; } );
				sync();
			} );
		}
		if ( pick ) {
			pick.ocOnPick = function ( pid, name ) {
				target.value = pid;
				pick.querySelector( 'input' ).value = name;
				addBtn.disabled = false;
			};
			wirePick( pick );
			pick.querySelector( 'input' ).addEventListener( 'input', function () {
				target.value = '';
				addBtn.disabled = true;
			} );
		}
		sync();
	}

	/* ---- the rule editor: typed fields with pictures ---- */

	$( '.oc-of-pick' ).each( function () {
		var $el = $( this );

		$el.selectWoo( {
			minimumInputLength: 2,
			placeholder: $el.data( 'placeholder' ),
			width: '100%',
			templateResult: function ( item ) {
				if ( ! item.id ) { return item.text; }
				var $row = $( '<span class="oc-of__s2row"></span>' );
				if ( item.img ) {
					$row.append( $( '<img alt="" />' ).attr( 'src', item.img ) );
				}
				$row.append( $( '<span></span>' ).text( item.text ) );
				return $row;
			},
			ajax: {
				url: ajaxurl,
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

	// What the rule does: the sections follow the choice.
	document.querySelectorAll( '[data-oc-of-kind]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			var kind = radio.value;
			document.querySelectorAll( '[data-oc-of-only]' ).forEach( function ( box ) {
				box.hidden = box.getAttribute( 'data-oc-of-only' ) !== kind;
			} );
			document.querySelectorAll( '.oc-of__kind' ).forEach( function ( card ) {
				card.classList.toggle( 'is-on', card.contains( radio ) );
			} );
			var lab = document.querySelector( '[data-oc-of-when-label]' );
			if ( lab ) {
				lab.textContent = lab.getAttribute( 'data-' + kind ) || lab.textContent;
			}
		} );
	} );

	// The places: the pill follows its box.
	document.querySelectorAll( '[data-oc-of-place]' ).forEach( function ( box ) {
		box.addEventListener( 'change', function () {
			box.closest( '.oc-of__place' ).classList.toggle( 'is-on', box.checked );
		} );
	} );

	/* ---- the rules list ---- */

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

	document.querySelectorAll( '[data-oc-of-confirm]' ).forEach( function ( a ) {
		a.addEventListener( 'click', function ( e ) {
			if ( ! window.confirm( cfg.delete || 'Delete?' ) ) {
				e.preventDefault();
			}
		} );
	} );

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
}( window.jQuery ) );
