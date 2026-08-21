/**
 * Checkout behaviours — loaded on the checkout page only.
 *
 * Split out of theme.js so every other page skips its parse cost. Same
 * rules as theme.js: no jQuery of our own (window.jQuery is only touched
 * to talk to Woo's checkout machinery).
 */
( function () {
	'use strict';

	var coForm = document.querySelector( 'body.oc-checkout form.checkout' );

	if ( coForm ) {
		var coL = window.ocL10n || {};

		if ( '0' === coL.coSummary ) {
			document.body.classList.add( 'oc-co-nosummary' );
		} else {
			document.body.classList.add( 'oc-co-fold' );
		}

		/* -- login fold -- */
		document.addEventListener( 'click', function ( e ) {
			var t = e.target.closest( '[data-oc-co-login-t]' );
			if ( ! t ) {
				return;
			}
			var box = t.closest( '[data-oc-co-login]' ) || t.parentElement.parentElement;
			var body = box.querySelector( '.oc-co-login__body' );
			if ( ! body ) {
				return;
			}
			body.hidden = ! body.hidden;
			if ( ! body.hidden ) {
				var u = body.querySelector( 'input[name="username"]' );
				if ( u ) {
					u.focus();
				}
			}
		} );

		/* -- pickup hides the address; method cards mark their state. The
		 * cards are proxies: Woo's real (hidden) shipping_method radios in
		 * the review get checked programmatically, so the two groups never
		 * fight over the checked state. -- */
		function coSyncMethod() {
			var checked = coForm.querySelector( '.oc-co-rate input:checked' );
			document.body.classList.toggle( 'oc-co-pickup', !! ( checked && /local_pickup/.test( checked.value ) ) );
			coForm.querySelectorAll( '.oc-co-rate' ).forEach( function ( card ) {
				var input = card.querySelector( 'input' );
				card.classList.toggle( 'is-on', !! ( input && input.checked ) );
			} );
		}

		coForm.addEventListener( 'change', function ( e ) {
			if ( e.target.matches( '.oc-co-rate input' ) ) {
				coSyncMethod();
				var real = document.querySelector( '#order_review input.shipping_method[value="' + ( window.CSS && CSS.escape ? CSS.escape( e.target.value ) : e.target.value ) + '"]' );
				if ( real && ! real.checked ) {
					real.checked = true;
					real.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			}
			if ( 'oc_send_other' === e.target.id ) {
				var block = coForm.querySelector( '[data-oc-co-recipient]' );
				if ( block ) {
					block.hidden = ! e.target.checked;
				}
			}
		} );

		coSyncMethod();

		/* -- live field validation: green when good, red when left bad -- */
		function coFieldRule( input ) {
			var id = input.id || '';
			if ( 'email' === input.type ) {
				return 'email';
			}
			if ( 'tel' === input.type || 'billing_phone' === id ) {
				return 'phone';
			}
			return 'text';
		}

		function coValidValue( input ) {
			var v = input.value.trim();
			var row = input.closest( '.form-row, .oc-co-rrow' );
			var required = row && row.classList.contains( 'validate-required' );

			if ( '' === v ) {
				return required ? false : null;
			}

			var rule = coFieldRule( input );

			if ( 'email' === rule ) {
				return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( v );
			}

			if ( 'phone' === rule ) {
				var digits = v.replace( /\D/g, '' ).length;
				var min = parseInt( coL.coPhoneMin || '0', 10 );
				var max = parseInt( coL.coPhoneMax || '0', 10 );
				if ( min && digits < min ) {
					return false;
				}
				if ( max && digits > max ) {
					return false;
				}
			}

			return true;
		}

		function coMsgFor( input ) {
			var v = input.value.trim();
			if ( '' === v ) {
				return coL.coRequired || 'Required field';
			}
			return 'email' === coFieldRule( input ) ? ( coL.coEmail || 'Invalid email' ) : ( coL.coPhone || 'Invalid phone' );
		}

		function coPaint( input, showError ) {
			var row = input.closest( '.form-row, .oc-co-rrow' );
			if ( ! row ) {
				return true;
			}

			var ok = coValidValue( input );
			var msg = row.querySelector( '.oc-f-msg' );

			row.classList.toggle( 'oc-f-ok', true === ok );
			row.classList.toggle( 'oc-f-bad', false === ok && showError );

			if ( false === ok && showError ) {
				if ( ! msg ) {
					msg = document.createElement( 'span' );
					msg.className = 'oc-f-msg';
					row.appendChild( msg );
				}
				msg.textContent = coMsgFor( input );
			} else if ( msg ) {
				msg.remove();
			}

			return false !== ok;
		}

		/* -- the form remembers itself: every change is stashed in the
		 *    session, so a trip back to the cart returns to a filled form -- */
		var coStashTimer = null;

		function coStashNow() {
			var data = new FormData();
			data.append( 'action', 'oc_co_stash' );

			coForm.querySelectorAll( 'input[name], select[name], textarea[name]' ).forEach( function ( el ) {
				if ( ! el.name || 'hidden' === el.type || 'password' === el.type ) {
					return;
				}
				if ( 'checkbox' === el.type || 'radio' === el.type ) {
					if ( 'oc_send_other' === el.name ) {
						data.append( 'fields[oc_send_other]', el.checked ? '1' : '' );
					}
					return;
				}
				data.append( 'fields[' + el.name + ']', el.value );
			} );

			fetch( ( window.ocL10n || {} ).ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} );
		}

		coForm.addEventListener( 'input', function () {
			clearTimeout( coStashTimer );
			coStashTimer = setTimeout( coStashNow, 700 );
		} );

		coForm.addEventListener( 'change', function () {
			clearTimeout( coStashTimer );
			coStashTimer = setTimeout( coStashNow, 250 );
		} );

		// Fields that are ours, not Woo's, come back from the same stash.
		( function () {
			var tag = document.getElementById( 'oc-co-stash' );
			if ( ! tag ) {
				return;
			}

			var mine = {};
			try {
				mine = JSON.parse( tag.textContent || '{}' );
			} catch ( err ) {
				return;
			}

			if ( mine.oc_send_other ) {
				var toggle = document.getElementById( 'oc_send_other' );
				if ( toggle && ! toggle.checked ) {
					toggle.checked = true;
					toggle.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			}

			[ 'oc_recip_first', 'oc_recip_last', 'oc_recip_phone', 'oc_recip_phone2' ].forEach( function ( id ) {
				var el = document.getElementById( id );
				if ( el && mine[ id ] ) {
					el.value = mine[ id ];
				}
			} );
		}() );

		/* -- tab order follows the eye: the visual layout is driven by flex
		 *    `order`, so the DOM sequence the browser would tab through is
		 *    not the sequence anyone sees -- */
		function coTabOrder() {
			var rtl = 'rtl' === getComputedStyle( document.documentElement ).direction;
			var seq = [];

			// Anything hidden since the last pass would otherwise keep a
			// stale index and collide with a live one.
			document.querySelectorAll( '[data-oc-tab]' ).forEach( function ( el ) {
				el.removeAttribute( 'tabindex' );
				el.removeAttribute( 'data-oc-tab' );
			} );

			function collect( root ) {
				if ( ! root ) {
					return;
				}

				var found = [];

				root.querySelectorAll( 'input:not([type="hidden"]), select:not(.select2-hidden-accessible), textarea, button, a[href], .select2-selection' ).forEach( function ( el ) {
					if ( ! el.offsetParent || el.disabled ) {
						return;
					}
					found.push( el );
				} );

				// Within a region, the eye reads top to bottom and then along
				// the reading direction.
				found.sort( function ( a, b ) {
					var ra = a.getBoundingClientRect();
					var rb = b.getBoundingClientRect();
					var rowA = Math.round( ra.top / 10 );
					var rowB = Math.round( rb.top / 10 );

					if ( rowA !== rowB ) {
						return rowA - rowB;
					}

					return rtl ? rb.right - ra.right : ra.left - rb.left;
				} );

				seq = seq.concat( found );
			}

			// The details column is walked whole before the summary — they
			// sit side by side, so a purely geometric sort would zig-zag
			// between the two.
			collect( coForm.querySelector( '#customer_details' ) );
			collect( document.getElementById( 'order_review' ) );

			seq.forEach( function ( el, i ) {
				el.setAttribute( 'tabindex', String( i + 1 ) );
				el.setAttribute( 'data-oc-tab', '1' );
			} );
		}

		coTabOrder();
		window.addEventListener( 'load', coTabOrder );

		/* -- inner floating labels: is-filled keeps the label up -- */
		function coFillState( input ) {
			var row = input.closest( '.form-row, .oc-co-rrow' );
			if ( row ) {
				row.classList.toggle( 'is-filled', '' !== input.value.trim() );
			}
		}

		function coFillAll() {
			coForm.querySelectorAll( '.input-text, select, textarea' ).forEach( coFillState );
		}

		coFillAll();
		// Browser autofill lands a beat after load.
		setTimeout( coFillAll, 600 );

		coForm.addEventListener( 'focusout', function ( e ) {
			if ( e.target.matches( '.input-text, select' ) && e.target.offsetParent ) {
				var touched = e.target.closest( '.form-row, .oc-co-rrow' );
				if ( touched ) {
					touched.classList.add( 'is-touched' );
				}
				coPaint( e.target, true );
			}
			if ( e.target.matches( '.input-text, select, textarea' ) ) {
				coFillState( e.target );
			}
		} );

		coForm.addEventListener( 'input', function ( e ) {
			if ( ! e.target.matches( '.input-text, select' ) ) {
				return;
			}
			coFillState( e.target );
			// While typing: promote to green the moment it turns valid; a
			// field already flagged red clears as soon as it is fixed.
			var row = e.target.closest( '.form-row' );
			coPaint( e.target, !! ( row && row.classList.contains( 'oc-f-bad' ) ) );
		} );

		/* -- required checkboxes (terms, privacy) speak inline too -- */
		function coCheckBox( input, showError ) {
			var row = input.closest( '.form-row, p' );
			if ( ! row ) {
				return true;
			}

			var ok = input.checked;
			var msg = row.querySelector( '.oc-f-msg' );

			row.classList.toggle( 'oc-f-bad', ! ok && showError );

			if ( ! ok && showError ) {
				if ( ! msg ) {
					msg = document.createElement( 'span' );
					msg.className = 'oc-f-msg';
					row.appendChild( msg );
				}
				msg.textContent = coL.coTick || 'Please tick this box to continue';
			} else if ( msg ) {
				msg.remove();
			}

			return ok;
		}

		document.addEventListener( 'change', function ( e ) {
			if ( e.target.matches( '#terms, [name="oc_privacy_consent"]' ) ) {
				coCheckBox( e.target, true );
			}
		} );

		/* -- place order: validate visible fields first, glide to the bad one -- */
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '#place_order' );
			if ( ! btn ) {
				return;
			}

			var firstBad = null;

			coForm.querySelectorAll( '.form-row.validate-required .input-text, .oc-co-rrow.validate-required .input-text, .form-row.validate-required select, input[type="email"].input-text, input[type="tel"].input-text' ).forEach( function ( input ) {
				if ( ! input.offsetParent ) {
					return; // Hidden (pickup address, folded recipient).
				}
				if ( ! coPaint( input, true ) && ! firstBad ) {
					firstBad = input;
				}
			} );

			document.querySelectorAll( '#terms, [name="oc_privacy_consent"]' ).forEach( function ( box ) {
				if ( ! box.offsetParent ) {
					return;
				}
				if ( ! coCheckBox( box, true ) && ! firstBad ) {
					firstBad = box;
				}
			} );

			if ( firstBad ) {
				e.preventDefault();
				e.stopPropagation();
				( firstBad.closest( '.form-row, .oc-co-rrow, p' ) || firstBad ).scrollIntoView( { behavior: 'smooth', block: 'center' } );
				firstBad.focus( { preventScroll: true } );
			}
		}, true );

		/* -- the total rides the button; re-applied on every review refresh.
		 * Woo swaps the review/payment nodes wholesale on update_checkout, so
		 * the observer sits on the FORM (stable) and everything is re-queried
		 * fresh on each pass. -- */

		function coPaintButton() {
			var btn = document.getElementById( 'place_order' );
			if ( ! btn || '1' !== coL.coBtnTotal ) {
				return;
			}

			if ( ! btn.dataset.ocBase ) {
				btn.dataset.ocBase = btn.textContent.trim();
			}

			var review = document.getElementById( 'order_review' );
			var amount = review ? review.querySelector( 'tr.order-total .woocommerce-Price-amount' ) : null;
			var label = btn.dataset.ocBase + ( amount ? ' · ' + amount.textContent.trim() : '' );

			if ( btn.textContent.trim() !== label ) {
				btn.textContent = label;
			}
		}

		function coTrustLine() {
			var btn = document.getElementById( 'place_order' );
			var wrap = btn ? btn.closest( '.place-order' ) : null;
			if ( ! wrap || wrap.querySelector( '.oc-co-trust' ) ) {
				return;
			}
			var trust = document.createElement( 'div' );
			trust.className = 'oc-co-trust';
			trust.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><span></span>';
			trust.querySelector( 'span' ).textContent = coL.coSecure || 'Secure encrypted payment';
			wrap.appendChild( trust );
		}

		var coTick = null;
		new MutationObserver( function () {
			if ( coTick ) {
				return;
			}
			// setTimeout, not rAF: rAF never fires in a background tab, and
			// Woo's ajax refresh may finish exactly there.
			coTick = setTimeout( function () {
				coTick = null;
				coPaintButton();
				coTrustLine();
				coSyncMethod();
				coSumHead();
				coTabOrder();
			}, 60 );
		} ).observe( coForm, { childList: true, subtree: true } );

		coPaintButton();
		coTrustLine();

		/* -- summary quantity steppers: the mini-cart's ajax + Woo refresh -- */
		var coQtyTimer = null;

		document.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-oc-co-qty] .oc-co-qty__b' );
			if ( ! b ) {
				return;
			}

			var wrap = b.closest( '[data-oc-co-qty]' );
			var num = wrap.querySelector( '.oc-co-qty__n' );
			var next = Math.max( 0, parseInt( num.textContent, 10 ) + parseInt( b.dataset.d, 10 ) );
			num.textContent = String( next );

			clearTimeout( coQtyTimer );
			coQtyTimer = setTimeout( function () {
				wrap.classList.add( 'is-busy' );
				var data = new FormData();
				data.append( 'action', 'oc_cart_qty' );
				data.append( 'key', wrap.dataset.key );
				data.append( 'qty', String( next ) );
				fetch( ( window.ocL10n || {} ).ajaxUrl || '/wp-admin/admin-ajax.php', {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.fragments ) {
							Object.keys( res.fragments ).forEach( function ( selector ) {
								document.querySelectorAll( selector ).forEach( function ( el ) {
									var box = document.createElement( 'div' );
									box.innerHTML = res.fragments[ selector ];
									if ( box.firstElementChild ) {
										el.replaceWith( box.firstElementChild );
									}
								} );
							} );
						}
						// Woo re-renders the review table (and with it our
						// steppers) with fresh totals.
						if ( window.jQuery ) {
							window.jQuery( document.body ).trigger( 'update_checkout' );
						}
					} );
			}, 450 );
		} );

		/* -- Select2 places its list from a box it measures itself, and with
		 *    our taller field that lands ~33px off. Pin it to the field's
		 *    real rect instead, on open and while scrolling. -- */
		if ( window.jQuery ) {
			var coPinList = function () {
				var open = document.querySelector( 'body > .select2-container--open' );
				var inline = coForm.querySelector( '.select2-container--open .select2-selection' );
				if ( ! open || ! inline ) {
					return;
				}

				var list = open.querySelector( '.select2-dropdown' );
				if ( ! list ) {
					return;
				}

				var rect = inline.getBoundingClientRect();
				var above = list.classList.contains( 'select2-dropdown--above' );

				open.style.inlineSize = rect.width + 'px';
				open.style.insetInlineStart = 'auto';
				open.style.left = ( rect.left + window.scrollX ) + 'px';
				open.style.top = ( above
					? rect.top + window.scrollY - list.offsetHeight
					: rect.bottom + window.scrollY ) + 'px';

				// The list carries its own inner offset, so the first pass
				// still lands a couple of dozen pixels out. Measure what
				// actually rendered and close the remaining gap.
				var seen = list.getBoundingClientRect();
				var delta = above ? ( rect.top - seen.bottom ) : ( rect.bottom - seen.top );

				if ( delta ) {
					open.style.top = ( ( parseFloat( open.style.top ) || 0 ) + delta ) + 'px';
				}
			};

			window.jQuery( document ).on( 'select2:open', function () {
				setTimeout( coPinList, 0 );
			} );

			window.addEventListener( 'scroll', function () {
				if ( document.querySelector( 'body > .select2-container--open' ) ) {
					coPinList();
				}
			}, { passive: true } );
		}

		/* -- "have a coupon?" unfolds the field -- */
		document.addEventListener( 'click', function ( e ) {
			var t = e.target.closest( '[data-oc-co-coupon-t]' );
			if ( ! t ) {
				return;
			}
			var box = t.parentElement.querySelector( '[data-oc-co-coupon]' );
			if ( box ) {
				box.hidden = ! box.hidden;
				t.classList.toggle( 'is-open', ! box.hidden );
				if ( ! box.hidden ) {
					box.querySelector( 'input' ).focus();
				}
			}
		} );

		/* -- summary coupon: the drawer's endpoint + Woo refresh -- */
		document.addEventListener( 'click', function ( e ) {
			var apply = e.target.closest( '[data-oc-co-coupon] button' );
			if ( ! apply ) {
				return;
			}

			var box = apply.closest( '[data-oc-co-coupon]' );
			var input = box.querySelector( 'input' );
			var msg = box.parentElement.querySelector( '[data-oc-co-coupon-msg]' );
			var code = input.value.trim();

			if ( '' === code ) {
				input.focus();
				return;
			}

			apply.disabled = true;

			var data = new FormData();
			data.append( 'action', 'oc_cart_coupon' );
			data.append( 'code', code );

			coCouponRequest( data, apply, msg, input );
		} );

		/* -- removing an applied coupon, straight from the summary row -- */
		document.addEventListener( 'click', function ( e ) {
			var x = e.target.closest( '[data-oc-co-coupon-x]' );
			if ( ! x ) {
				return;
			}

			var gone = new FormData();
			gone.append( 'action', 'oc_cart_coupon' );
			gone.append( 'code', x.dataset.code );
			gone.append( 'remove', '1' );
			x.disabled = true;
			coCouponRequest( gone, x, null, null );
		} );

		// The endpoint answers with Woo's fragment payload on success and
		// {success:false, data:{message}} on refusal — never a bare error.
		function coCouponRequest( data, btn, msg, input ) {
			fetch( ( window.ocL10n || {} ).ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					btn.disabled = false;

					var ok = !! ( res && ( res.fragments || res.success ) );

					if ( ok ) {
						if ( msg ) {
							msg.hidden = true;
						}
						if ( input ) {
							input.value = '';
						}
						if ( window.jQuery ) {
							window.jQuery( document.body ).trigger( 'update_checkout' );
						}
						return;
					}

					if ( msg ) {
						msg.textContent = ( res && res.data && res.data.message )
							? res.data.message
							: ( coL.coCouponBad || 'This coupon cannot be applied.' );
						msg.hidden = false;
					}
				} )
				.catch( function () {
					btn.disabled = false;
					if ( msg ) {
						msg.textContent = coL.coCouponBad || 'This coupon cannot be applied.';
						msg.hidden = false;
					}
				} );
		}

		/* -- mobile: the summary heading is the fold handle and carries the
		 *    running total, so a collapsed summary still says what it costs -- */
		var sumHead = document.getElementById( 'order_review_heading' );

		function coSumHead() {
			if ( ! sumHead ) {
				return;
			}

			// The compact heading belongs to any folded summary — phones
			// always, desktop when the setting asks for it.
			var phone = window.matchMedia( '(max-width: 900px)' ).matches
				|| document.body.classList.contains( 'oc-co-dfold' );
			var chev = sumHead.querySelector( '.oc-co-sumchev' );
			var tot = sumHead.querySelector( '.oc-co-sumtotal' );

			var head = sumHead.querySelector( '.oc-co-sumhead' );

			if ( ! phone ) {
				if ( chev ) {
					chev.remove();
				}
				if ( tot ) {
					tot.remove();
				}
				if ( head ) {
					sumHead.textContent = sumHead.dataset.ocLabel || head.querySelector( 'strong' ).textContent;
				}
				return;
			}

			// "Total · N items" on one side, the amount on the other.
			if ( ! head ) {
				sumHead.dataset.ocLabel = sumHead.textContent.trim();
				sumHead.textContent = '';
				head = document.createElement( 'span' );
				head.className = 'oc-co-sumhead';
				head.innerHTML = '<strong></strong><em></em>';
				sumHead.appendChild( head );
				chev = null;
				tot = null;
			}

			head.querySelector( 'strong' ).textContent = coL.coTotal || 'Total';

			var units = 0;
			document.querySelectorAll( '#order_review .oc-co-qty__n' ).forEach( function ( n ) {
				units += parseInt( n.textContent, 10 ) || 0;
			} );

			head.querySelector( 'em' ).textContent = units
				? ( coL.coItems || '%d items' ).replace( '%d', units )
				: '';

			if ( ! tot ) {
				tot = document.createElement( 'span' );
				tot.className = 'oc-co-sumtotal';
				sumHead.appendChild( tot );
			}

			if ( ! chev ) {
				chev = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
				chev.setAttribute( 'class', 'oc-co-sumchev' );
				chev.setAttribute( 'viewBox', '0 0 24 24' );
				chev.setAttribute( 'fill', 'none' );
				chev.setAttribute( 'stroke', 'currentColor' );
				chev.setAttribute( 'stroke-width', '1.8' );
				chev.setAttribute( 'stroke-linecap', 'round' );
				chev.innerHTML = '<path d="M6 9l6 6 6-6"/>';
				sumHead.appendChild( chev );
			}

			var review = document.getElementById( 'order_review' );
			var amount = review ? review.querySelector( 'tr.order-total .woocommerce-Price-amount' ) : null;
			tot.textContent = amount ? amount.textContent.trim() : '';
		}

		if ( sumHead ) {
			sumHead.addEventListener( 'click', function () {
				// Phones always fold; desktop only when the setting says so.
				if ( window.matchMedia( '(max-width: 900px)' ).matches || document.body.classList.contains( 'oc-co-dfold' ) ) {
					document.body.classList.toggle( 'oc-co-sumopen' );
				}
			} );

			coSumHead();
			window.addEventListener( 'resize', coSumHead );
		}

		/* -- terms and privacy open in a panel, never a new tab -- */
		var legalPanel = null;

		function coLegal( which ) {
			if ( ! legalPanel ) {
				legalPanel = document.createElement( 'div' );
				legalPanel.className = 'oc-legal';
				legalPanel.hidden = true;
				legalPanel.innerHTML =
					'<div class="oc-legal__overlay" data-oc-legal-close></div>' +
					'<div class="oc-legal__panel" role="dialog" aria-modal="true">' +
					'<div class="oc-legal__head"><h3></h3>' +
					'<button type="button" class="oc-legal__x" data-oc-legal-close aria-label="close">&times;</button></div>' +
					'<div class="oc-legal__body"></div>' +
					'</div>';
				document.body.appendChild( legalPanel );
				sheetDragCloseLegal( legalPanel.querySelector( '.oc-legal__panel' ) );
			}

			var body = legalPanel.querySelector( '.oc-legal__body' );
			legalPanel.querySelector( 'h3' ).textContent = '';
			body.innerHTML = '<p class="oc-legal__loading">…</p>';
			legalPanel.hidden = false;
			document.documentElement.style.overflow = 'hidden';
			setTimeout( function () {
				legalPanel.classList.add( 'is-open' );
			}, 10 );

			var data = new FormData();
			data.append( 'action', 'oc_co_legal' );
			data.append( 'which', which );

			fetch( ( window.ocL10n || {} ).ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						body.innerHTML = '';
						return;
					}
					legalPanel.querySelector( 'h3' ).textContent = res.data.title;
					body.innerHTML = res.data.content;
				} );
		}

		function coLegalClose() {
			if ( ! legalPanel ) {
				return;
			}
			legalPanel.classList.remove( 'is-open' );
			document.documentElement.style.overflow = '';
			setTimeout( function () {
				legalPanel.hidden = true;
			}, 260 );
		}

		// Pull-down dismiss for the phone sheet.
		function sheetDragCloseLegal( panel ) {
			var startY = 0;
			var delta = 0;
			var dragging = false;

			panel.addEventListener( 'touchstart', function ( e ) {
				if ( ! window.matchMedia( '(max-width: 782px)' ).matches ) {
					return;
				}
				startY = e.touches[ 0 ].clientY;
				delta = 0;
				dragging = ( startY - panel.getBoundingClientRect().top ) < 60 || panel.scrollTop <= 0;
			}, { passive: true } );

			panel.addEventListener( 'touchmove', function ( e ) {
				if ( ! dragging ) {
					return;
				}
				delta = e.touches[ 0 ].clientY - startY;
				if ( delta > 0 && panel.scrollTop <= 0 ) {
					panel.style.transform = 'translateY(' + delta + 'px)';
					panel.style.transition = 'none';
					e.preventDefault();
				}
			}, { passive: false } );

			panel.addEventListener( 'touchend', function () {
				if ( ! dragging ) {
					return;
				}
				dragging = false;
				panel.style.transition = '';
				panel.style.transform = '';
				if ( delta > 90 ) {
					coLegalClose();
				}
			} );
		}

		// Capture phase: Woo's own handler for the terms link sits on
		// document.body and swallows the event before it reaches us.
		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-oc-legal-close]' ) ) {
				coLegalClose();
				return;
			}

			var link = e.target.closest( '#payment a[href], .oc-co-privacy a[href]' );
			if ( ! link ) {
				return;
			}

			var privacy = link.classList.contains( 'woocommerce-privacy-policy-link' )
				|| !! link.closest( '.oc-co-privacy' );
			var terms = link.classList.contains( 'woocommerce-terms-and-conditions-link' );

			if ( ! privacy && ! terms ) {
				return;
			}

			e.preventDefault();
			e.stopPropagation();
			coLegal( privacy ? 'privacy' : 'terms' );
		}, true );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				coLegalClose();
			}
		} );
	}

}() );
