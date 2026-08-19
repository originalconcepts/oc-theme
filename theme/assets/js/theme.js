/**
 * Theme front end.
 *
 * Vanilla. No jQuery, no Slick, no Swiper (DECISIONS.md). Block behaviour
 * ships with each block via block.json viewScript, so this file stays small:
 * mobile menu, card-gallery dots, tabs→accordion, sticky add-to-cart.
 */
( function () {
	'use strict';

	document.documentElement.classList.add( 'oc-ready' );

	/* ---------- product columns: inner sticky wrapper ----------
	 * Chromium clamps a sticky GRID ITEM to the whole grid, not to its own
	 * grid area — so a pinned column slid past its row into the related
	 * products. Wrapping each column's content and pinning the wrapper
	 * instead clamps it to the column box, which spans exactly row one.
	 * Runs before any other gallery code so parentElement chains hold. */

	document.querySelectorAll(
		'.single-product div.product > div.images, .single-product div.product > div.summary'
	).forEach( function ( col ) {
		var inner = document.createElement( 'div' );
		inner.className = 'oc-stick-inner';
		while ( col.firstChild ) {
			inner.appendChild( col.firstChild );
		}
		col.appendChild( inner );
	} );

	/* ---------- mobile menu ---------- */

	var burger = document.querySelector( '.oc-burger' );
	var menu = document.getElementById( 'oc-mobile-menu' );

	function setMenu( open ) {
		burger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		menu.hidden = ! open;
		menu.setAttribute( 'data-open', open ? 'true' : 'false' );
	}

	if ( burger && menu ) {
		burger.addEventListener( 'click', function () {
			setMenu( burger.getAttribute( 'aria-expanded' ) !== 'true' );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && ! menu.hidden ) {
				setMenu( false );
				burger.focus();
			}
		} );
	}

	/* ---------- top bar: rotating messages ---------- */

	var topbar = document.querySelector( '.oc-topbar' );

	if ( topbar ) {
		var tbMsgs = topbar.querySelectorAll( '.oc-topbar__msg' );
		var tbIdx = 0;
		var tbTimer = null;

		function tbShow( i ) {
			tbIdx = ( i + tbMsgs.length ) % tbMsgs.length;
			tbMsgs.forEach( function ( m, j ) {
				m.classList.toggle( 'is-current', j === tbIdx );
			} );
		}

		function tbAuto() {
			clearInterval( tbTimer );
			if ( tbMsgs.length > 1 ) {
				tbTimer = setInterval( function () {
					tbShow( tbIdx + 1 );
				}, 5000 );
			}
		}

		if ( tbMsgs.length > 1 ) {
			topbar.querySelectorAll( '.oc-topbar__nav' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					tbShow( tbIdx + ( btn.classList.contains( 'oc-topbar__nav--next' ) ? 1 : -1 ) );
					tbAuto();
				} );
			} );
			tbAuto();
		}
	}

	/* ---------- transparent header: solid once scrolled ---------- */

	var siteHeader = document.querySelector( '.oc-header' );

	if ( siteHeader && document.body.classList.contains( 'oc-htrans' ) ) {
		var updateHeaderScroll = function () {
			siteHeader.classList.toggle( 'is-scrolled', window.scrollY > 12 );
		};
		window.addEventListener( 'scroll', updateHeaderScroll, { passive: true } );
		updateHeaderScroll();
	}

	/* ---------- catalogue: remember & restore the clicked card ----------
	 * Independent of the paging mode and of pagination existing — a filtered
	 * single-page view restores just the same. */

	// Archive pages only — a product page's related-products grid must not
	// swallow the saved return point.
	var ocCatGrid = ( document.body.classList.contains( 'archive' ) || document.body.classList.contains( 'woocommerce-shop' ) )
		? document.querySelector( 'ul.products' )
		: null;

	if ( ocCatGrid ) {
		// A deliberate click on the category link must open it from the top.
		// The browser's automatic restoration drops the visitor back
		// mid-scroll on any same-URL navigation (and our replaceState page
		// tracking makes that common) — so restoration is ours alone.
		if ( 'scrollRestoration' in window.history ) {
			window.history.scrollRestoration = 'manual';
		}

		ocCatGrid.querySelectorAll( 'li.product' ).forEach( function ( li ) {
			li.dataset.ocpg = window.location.href;
		} );

		var ocBackNav = false;
		try {
			var navEntries = performance.getEntriesByType( 'navigation' );
			ocBackNav = navEntries && navEntries[ 0 ]
				? 'back_forward' === navEntries[ 0 ].type
				: !! ( performance.navigation && 2 === performance.navigation.type );
		} catch ( e ) {}

		var ocCatReturned = false;
		try {
			var ocReturn = JSON.parse( sessionStorage.getItem( 'ocReturn' ) || 'null' );
			if ( ocBackNav && ocReturn && ocReturn.postClass &&
				new URL( ocReturn.url ).pathname === window.location.pathname ) {
				var backTarget = ocCatGrid.querySelector( 'li.' + ocReturn.postClass );
				if ( backTarget ) {
					ocCatReturned = true;

					// Images loading in above the card shift the layout after
					// the first jump — especially on mobile — so the anchor
					// re-asserts a few times, backing off the moment the
					// visitor moves on their own.
					var anchorDone = false;
					[ 'touchstart', 'wheel', 'keydown' ].forEach( function ( evt ) {
						window.addEventListener( evt, function () {
							anchorDone = true;
						}, { once: true, passive: true } );
					} );

					[ 150, 500, 1100, 2000 ].forEach( function ( delay ) {
						setTimeout( function () {
							if ( ! anchorDone ) {
								backTarget.scrollIntoView( { block: 'center' } );
							}
						}, delay );
					} );
				}
			}
			sessionStorage.removeItem( 'ocReturn' );
		} catch ( e ) {}

		if ( ! ocCatReturned ) {
			window.scrollTo( 0, 0 );
		}

		ocCatGrid.addEventListener( 'click', function ( event ) {
			var li = event.target.closest( 'li.product' );
			if ( ! li ) {
				return;
			}
			var postClass = '';
			li.classList.forEach( function ( c ) {
				if ( 0 === c.indexOf( 'post-' ) ) {
					postClass = c;
				}
			} );
			try {
				sessionStorage.setItem( 'ocReturn', JSON.stringify( {
					url: li.dataset.ocpg || window.location.href,
					postClass: postClass
				} ) );
			} catch ( e ) {}
		} );
	}

	/* ---------- catalogue: load-more / infinite paging ---------- */

	var pagingMode = document.body.classList.contains( 'oc-paging-load-more' ) ? 'more' :
		document.body.classList.contains( 'oc-paging-infinite' ) ? 'auto' : null;

	if ( pagingMode ) {
		var pagingUl = document.querySelector( 'ul.products' );
		var pagingNav = document.querySelector( '.woocommerce-pagination' );
		var pagingNext = pagingNav ? pagingNav.querySelector( 'a.next' ) : null;
		var pagingBtn = null;
		var pagingBusy = false;
		var pagingState = window.location.href;

		if ( pagingUl && pagingNav ) {
			// Numbers never show in these modes — including on the last page,
			// where there is no next link at all.
			pagingNav.style.display = 'none';

			// The address bar follows the page currently in view (cards carry
			// their page URL from the restore module above).
			function updatePagingState() {
				var lis = pagingUl.querySelectorAll( 'li.product' );
				for ( var i = 0; i < lis.length; i++ ) {
					if ( lis[ i ].getBoundingClientRect().bottom > 120 ) {
						var url = lis[ i ].dataset.ocpg;
						if ( url && url !== pagingState ) {
							pagingState = url;
							window.history.replaceState( null, '', url );
						}
						return;
					}
				}
			}

			window.addEventListener( 'scroll', updatePagingState, { passive: true } );

			// Landing mid-catalogue (back from a product on page N): quietly
			// pull every earlier page in above the grid, keeping the view
			// anchored, so scrolling up reaches the whole catalogue.
			var prevChain = pagingNav.querySelector( 'a.prev' );

			function loadPrevChain() {
				if ( ! prevChain || document.documentElement.dataset.ocFlt ) {
					return;
				}
				var prevUrl = prevChain.href;

				fetch( prevUrl )
					.then( function ( r ) {
						return r.text();
					} )
					.then( function ( html ) {
						var doc = new DOMParser().parseFromString( html, 'text/html' );
						var anchor = pagingUl.querySelector( 'li.product' );
						var beforeTop = anchor ? anchor.getBoundingClientRect().top : 0;
						var firstExisting = pagingUl.firstChild;

						doc.querySelectorAll( 'ul.products > li.product' ).forEach( function ( li ) {
							var node = document.importNode( li, true );
							node.dataset.ocpg = prevUrl;
							pagingUl.insertBefore( node, firstExisting );
							node.querySelectorAll( '.oc-card-media--gallery' ).forEach( bindCardGallery );
							ocLazyVideos( node );
						} );

						if ( anchor ) {
							window.scrollBy( 0, anchor.getBoundingClientRect().top - beforeTop );
						}

						prevChain = doc.querySelector( '.woocommerce-pagination a.prev' );
						loadPrevChain();
					} )
					.catch( function () {} );
			}

			setTimeout( loadPrevChain, 700 );

			var loadNextPage = function () {
				// Once the filter module owns the grid, the legacy paging
				// must not append unfiltered pages under it.
				if ( document.documentElement.dataset.ocFlt || pagingBusy || ! pagingNext ) {
					return;
				}
				pagingBusy = true;
				if ( pagingBtn ) {
					pagingBtn.classList.add( 'loading' );
				}

				var loadedUrl = pagingNext.href;

				fetch( loadedUrl )
					.then( function ( r ) {
						return r.text();
					} )
					.then( function ( html ) {
						var doc = new DOMParser().parseFromString( html, 'text/html' );

						doc.querySelectorAll( 'ul.products > li.product' ).forEach( function ( li ) {
							var node = document.importNode( li, true );
							node.dataset.ocpg = loadedUrl;
							pagingUl.appendChild( node );
							node.querySelectorAll( '.oc-card-media--gallery' ).forEach( bindCardGallery );
							ocLazyVideos( node );
						} );

						pagingNext = doc.querySelector( '.woocommerce-pagination a.next' );

						if ( ! pagingNext && pagingBtn ) {
							pagingBtn.remove();
						}

						pagingBusy = false;
						if ( pagingBtn ) {
							pagingBtn.classList.remove( 'loading' );
						}
					} )
					.catch( function () {
						pagingBusy = false;
					} );
			};

			if ( pagingNext ) {
				if ( 'more' === pagingMode ) {
					pagingBtn = document.createElement( 'button' );
					pagingBtn.type = 'button';
					pagingBtn.className = 'button oc-load-more';
					pagingBtn.textContent = ( window.ocL10n && window.ocL10n.loadMore ) || 'Show more';
					pagingUl.parentElement.insertBefore( pagingBtn, pagingUl.nextSibling );
					pagingBtn.addEventListener( 'click', loadNextPage );
				} else {
					window.addEventListener( 'scroll', function () {
						if ( pagingNext && pagingUl.getBoundingClientRect().bottom < window.innerHeight + 600 ) {
							loadNextPage();
						}
					}, { passive: true } );
				}
			}
		}
	}

	/* ---------- header: search toggle ---------- */

	var searchToggles = document.querySelectorAll( '.oc-search-toggle' );
	var searchPanel = document.getElementById( 'oc-header-search' );

	if ( searchToggles.length && searchPanel ) {
		function setSearchOpen( open ) {
			searchPanel.hidden = ! open;
			searchToggles.forEach( function ( t ) {
				t.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
			if ( open ) {
				var field = searchPanel.querySelector( 'input[type="search"], input[type="text"]' );
				if ( field ) {
					field.focus();
				}
			}
		}

		searchToggles.forEach( function ( toggle ) {
			toggle.addEventListener( 'click', function () {
				setSearchOpen( searchPanel.hidden );
			} );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && ! searchPanel.hidden ) {
				setSearchOpen( false );
			}
		} );
	}

	/* ---------- card gallery: hover arrows drive the scroll-snap strip ---------- */

	function bindCardGallery( media ) {
		var strip = media.querySelector( '.oc-card-media__strip' );

		if ( ! strip ) {
			return;
		}

		var slideGap = parseFloat( getComputedStyle( strip ).columnGap ) || 0;

		// Read live, not at bind time — a colour-sibling swap replaces the
		// slides under the same listeners.
		function count() {
			return strip.children.length;
		}

		// Index-based with wrap-around: both arrows always page, whichever the
		// visitor reaches for first. The step includes the inter-slide gap —
		// bare clientWidth drifted 2px per slide and the mandatory snap
		// corrected it with a visible jump on the way back.
		function currentIndex() {
			return count() < 2 ? 0 :
				Math.round( Math.abs( strip.scrollLeft ) / ( strip.clientWidth + slideGap ) );
		}

		function goTo( index, jump ) {
			var slide = strip.children[ index ];
			if ( ! slide ) {
				return;
			}
			// Scroll to the slide itself — exact in RTL and gap-proof. A
			// wrap-around switches instantly (like the lightbox loop); block
			// "nearest" keeps the page from scrolling vertically.
			slide.scrollIntoView( {
				behavior: jump ? 'auto' : 'smooth',
				block: 'nearest',
				inline: 'start'
			} );
		}

		media.querySelectorAll( '.oc-card-media__nav' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();

				var dir = btn.classList.contains( 'oc-card-media__nav--next' ) ? 1 : -1;
				var target = ( currentIndex() + dir + count() ) % count();
				goTo( target, Math.abs( target - currentIndex() ) > 1 );
			} );
		} );
	}

	document.querySelectorAll( '.oc-card-media--gallery' ).forEach( bindCardGallery );

	/* ---------- colour siblings on catalogue cards ----------
	 * A click swaps the card in place — gallery, links, title and price become
	 * the sibling's — so the visitor flips through colours without leaving the
	 * catalogue. Delegated, so cards loaded by the infinite scroll join in. */

	document.addEventListener( 'click', function ( event ) {
		var item = event.target.closest( '.oc-colors--loop .oc-colors__item' );

		if ( ! item || ! item.dataset.url ) {
			return;
		}

		event.preventDefault();

		var li = item.closest( 'li.product' );
		if ( ! li || item.classList.contains( 'is-current' ) ) {
			return;
		}

		var imgs = [];
		try {
			imgs = JSON.parse( item.dataset.imgs || '[]' );
		} catch ( err ) {
			imgs = [];
		}

		var strip = li.querySelector( '.oc-card-media__strip' );
		if ( strip && imgs.length ) {
			strip.innerHTML = imgs.map( function ( src, i ) {
				return '<figure class="oc-card-media__item' + ( 0 === i ? ' is-first' : '' ) + '">' +
					'<img src="' + src + '" alt="" loading="' + ( 0 === i ? 'eager' : 'lazy' ) + '" sizes="(max-width: 900px) 50vw, 25vw"></figure>';
			} ).join( '' );
			strip.scrollLeft = 0;
		}

		li.querySelectorAll( 'a[href]' ).forEach( function ( a ) {
			if ( a.closest( '.oc-colors' ) ) {
				return;
			}
			if ( a.classList.contains( 'oc-card-atc' ) ) {
				a.href = a.href.indexOf( 'add-to-cart=' ) > -1
					? '?add-to-cart=' + item.dataset.pid
					: item.dataset.url;
				a.dataset.product_id = item.dataset.pid;
				return;
			}
			if ( a.href.indexOf( '/product/' ) > -1 ) {
				a.href = item.dataset.url;
			}
		} );

		var title = li.querySelector( '.woocommerce-loop-product__title' );
		if ( title && item.dataset.name ) {
			title.textContent = item.dataset.name;
		}

		var price = li.querySelector( '.price' );
		if ( price && item.dataset.price ) {
			price.innerHTML = item.dataset.price;
		}

		// Term swatches carry no badge data — the card keeps its own badge.
		// The sale badge lives inside the card's labels container now.
		if ( undefined !== item.dataset.badge ) {
			var badge = li.querySelector( '.onsale' );
			if ( badge ) {
				badge.remove();
			}
			if ( item.dataset.badge ) {
				var flags = li.querySelector( '.oc-flags[data-sale]' ) || li.querySelector( '.oc-flags' );
				if ( flags ) {
					flags.insertAdjacentHTML( 'afterbegin', item.dataset.badge );
				} else {
					li.insertAdjacentHTML( 'afterbegin', item.dataset.badge );
				}
			}
		}

		// The sibling's stock state travels with the swap: the notify bar and
		// the sold-out corner label appear or leave with it.
		if ( undefined !== item.dataset.oos ) {
			var L2 = window.ocL10n || {};
			var isOos = '1' === item.dataset.oos;
			var media = li.querySelector( '.oc-card-media' ) || li;
			var nbar = li.querySelector( '.oc-notify-bar' );
			var cstrip = li.querySelector( '.oc-strip' );
			var oflag = li.querySelector( '.oc-flag--oos' );

			if ( isOos ) {
				if ( ! nbar ) {
					nbar = document.createElement( 'button' );
					nbar.type = 'button';
					nbar.className = 'oc-notify-bar oc-notify-open';
					media.appendChild( nbar );
				}
				nbar.disabled = false;
				nbar.classList.remove( 'oc-signed' );
				nbar.textContent = L2.notifyButton || 'Notify me when it is back';
				nbar.dataset.product = item.dataset.pid;
				nbar.dataset.name = item.dataset.name || '';
				if ( '1' === item.dataset.var ) {
					nbar.dataset.variable = '1';
				} else {
					delete nbar.dataset.variable;
				}
				if ( cstrip ) {
					cstrip.hidden = true;
				}
				if ( ! oflag && L2.oosFlagText ) {
					var fcol = li.querySelector( '.oc-flags--' + ( L2.oosFlagSide || 'left' ) ) || li.querySelector( '.oc-flags' );
					if ( fcol ) {
						var fl = document.createElement( 'span' );
						fl.className = 'oc-flag oc-flag--oos';
						fl.setAttribute( 'style', L2.oosFlagStyle || '' );
						fl.textContent = L2.oosFlagText;
						fcol.appendChild( fl );
					}
				}
				ocRefreshSigned();
			} else {
				if ( nbar ) {
					nbar.remove();
				}
				if ( oflag ) {
					oflag.remove();
				}
				if ( cstrip ) {
					cstrip.hidden = false;
				}
			}
		}

		li.querySelectorAll( '.oc-colors__item' ).forEach( function ( sib ) {
			sib.classList.remove( 'is-current' );
			sib.removeAttribute( 'aria-current' );
		} );
		item.classList.add( 'is-current' );
		item.setAttribute( 'aria-current', 'true' );
	} );

	/* ---------- smart lead-image refresh ----------
	 * Never while the visitor is looking: each fresh render of the catalogue
	 * gives ~20% of the products this browser saw-but-skipped a different
	 * gallery shot as their lead. "Skipped" = the card sat in view for a
	 * second and drew no gallery paging and no click. An alternate face that
	 * is itself skipped twice gives way to the next shot; once every shot has
	 * had its chance, the product returns to its own lead and rests.
	 * Everything lives in this browser's localStorage — no server writes. */

	( function () {
		var Lf = window.ocL10n || {};
		var grid = document.querySelector( 'ul.products' );

		if ( 'smart' !== Lf.freshMode || ! grid ) {
			return;
		}

		var ledger = {};
		try {
			ledger = JSON.parse( localStorage.getItem( 'ocFresh' ) || '{}' );
			if ( ! ledger || 'object' !== typeof ledger ) {
				ledger = {};
			}
		} catch ( e ) {
			ledger = {};
		}

		// The on-show flag is per view, never carried over.
		Object.keys( ledger ).forEach( function ( k ) {
			delete ledger[ k ].sw;
		} );

		function saveLedger() {
			var keys = Object.keys( ledger );
			if ( keys.length > 300 ) {
				keys.sort( function ( a, b ) {
					return ( ledger[ a ].ts || 0 ) - ( ledger[ b ].ts || 0 );
				} );
				keys.slice( 0, keys.length - 300 ).forEach( function ( k ) {
					delete ledger[ k ];
				} );
			}
			try {
				localStorage.setItem( 'ocFresh', JSON.stringify( ledger ) );
			} catch ( e ) {}
		}

		function rec( pid ) {
			if ( ! ledger[ pid ] ) {
				ledger[ pid ] = { m: 0, i: 0, t: 0, sm: 0 };
			}
			ledger[ pid ].ts = Date.now();
			return ledger[ pid ];
		}

		function cardPid( li ) {
			return ( li.className.match( /post-(\d+)/ ) || [] )[ 1 ] || '';
		}

		function cardImgs( li ) {
			return li.querySelectorAll( '.oc-card-media__item img' );
		}

		var cards = [].slice.call( grid.querySelectorAll( 'li.product' ) );

		/* -- swap phase: a different face for ~20% of the skipped -- */
		var candidates = cards.filter( function ( li ) {
			var pid = cardPid( li );
			var r = pid && ledger[ pid ];
			var imgs = cardImgs( li );
			return r && ! r.i && r.m >= 1 &&
				imgs.length >= 2 && r.t < imgs.length - 1 &&
				! li.querySelector( '.oc-card-media__item--video' );
		} );

		candidates.sort( function () {
			return Math.random() - 0.5;
		} );

		candidates.slice( 0, Math.max( 2, Math.round( candidates.length * 0.2 ) ) ).forEach( function ( li ) {
			var r = ledger[ cardPid( li ) ];
			var imgs = cardImgs( li );
			var alt = 1 + ( r.t % ( imgs.length - 1 ) );

			// Swap the faces, not the DOM — arrows, swipe and the sibling
			// colour swap keep working untouched.
			[ 'src', 'srcset', 'alt' ].forEach( function ( attr ) {
				var tmp = imgs[ 0 ].getAttribute( attr ) || '';
				imgs[ 0 ].setAttribute( attr, imgs[ alt ].getAttribute( attr ) || '' );
				imgs[ alt ].setAttribute( attr, tmp );
			} );

			r.sw = 1;
		} );

		/* -- seen: half the card, one full second -- */
		var seen = {};
		var judged = {};
		var timers = {};

		var freshIO = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				var pid = cardPid( entry.target );
				if ( ! pid ) {
					return;
				}
				if ( entry.isIntersecting ) {
					if ( ! timers[ pid ] ) {
						timers[ pid ] = setTimeout( function () {
							seen[ pid ] = 1;
						}, 1000 );
					}
				} else if ( timers[ pid ] ) {
					clearTimeout( timers[ pid ] );
					delete timers[ pid ];
				}
			} );
		}, { threshold: 0.5 } );

		cards.forEach( function ( li ) {
			freshIO.observe( li );
		} );

		// Belt and braces: a slow direct measure marks visible cards too, for
		// engines whose observer pipeline stalls (same fallback as the lazy
		// card videos).
		var freshTick = setInterval( function () {
			var vh = window.innerHeight;
			cards.forEach( function ( li ) {
				var pid = cardPid( li );
				if ( ! pid || seen[ pid ] ) {
					return;
				}
				var rect = li.getBoundingClientRect();
				var visible = Math.min( rect.bottom, vh ) - Math.max( rect.top, 0 );
				if ( rect.height > 0 && visible >= rect.height * 0.5 ) {
					seen[ pid ] = 1;
				}
			} );
		}, 1200 );

		window.addEventListener( 'pagehide', function () {
			clearInterval( freshTick );
		} );

		/* -- interest: paging the card's gallery by any means, or a click.
		 * A bare hover doesn't change the image in this card design, so per
		 * the spec it counts for nothing. -- */
		function interest( li ) {
			var pid = cardPid( li );
			if ( pid ) {
				rec( pid ).i = 1;
			}
		}

		cards.forEach( function ( li ) {
			var strip = li.querySelector( '.oc-card-media__strip' );
			if ( strip ) {
				strip.addEventListener( 'scroll', function () {
					interest( li );
				}, { passive: true, once: true } );
			}
		} );

		grid.addEventListener( 'click', function ( event ) {
			// The filters' colour sync clicks card dots programmatically —
			// that is not the visitor showing interest.
			if ( window.__ocDotSync ) {
				return;
			}
			var li = event.target.closest( 'li.product' );
			if ( li ) {
				interest( li );
				flush();
			}
		} );

		/* -- the verdict, once per card per view -- */
		function flush() {
			Object.keys( seen ).forEach( function ( pid ) {
				if ( judged[ pid ] ) {
					return;
				}
				judged[ pid ] = 1;

				var r = rec( pid );
				if ( r.i ) {
					return;
				}

				r.m++;
				if ( r.sw ) {
					r.sm++;
					// Two skipped showings for this alternate: next one.
					if ( r.sm >= 2 ) {
						r.t++;
						r.sm = 0;
					}
				}
			} );
			saveLedger();
		}

		window.addEventListener( 'pagehide', flush );
		document.addEventListener( 'visibilitychange', function () {
			if ( 'hidden' === document.visibilityState ) {
				flush();
			}
		} );

		// Cards the infinite scroll appends later join the tracking (their
		// swap chance comes on the next render, like everyone else's).
		new MutationObserver( function ( muts ) {
			muts.forEach( function ( mut ) {
				[].forEach.call( mut.addedNodes, function ( node ) {
					if ( 1 !== node.nodeType || ! node.matches || ! node.matches( 'li.product' ) ) {
						return;
					}
					cards.push( node );
					freshIO.observe( node );
					var strip = node.querySelector( '.oc-card-media__strip' );
					if ( strip ) {
						strip.addEventListener( 'scroll', function () {
							interest( node );
						}, { passive: true, once: true } );
					}
				} );
			} );
		} ).observe( grid, { childList: true } );
	} )();

	/* ---------- catalogue filters ----------
	 * Instant faceted filtering: every control is a button (nothing for bots
	 * to crawl), state lives in replaceState'd params, and a slim ajax call
	 * returns just the cards plus recounted facets. */

	( function () {
		var cfgEl = document.getElementById( 'oc-flt-config' );
		var grid = document.querySelector( 'ul.products' );

		if ( ! cfgEl || ! grid ) {
			return;
		}

		var cfg = {};
		try {
			cfg = JSON.parse( cfgEl.textContent || '{}' );
		} catch ( e ) {
			return;
		}

		var L = window.ocL10n || {};
		var panels = [].slice.call( document.querySelectorAll( '[data-flt-panel]' ) );
		var state = { attrs: {}, cats: {}, brands: [], min: null, max: null, instock: false };
		var engaged = false;
		var moreBtn = null;
		var page = 1;
		var pages = 1;
		var busy = false;
		var applyTimer = null;

		/* -- state <-> URL -- */

		( function initFromUrl() {
			var params = new URLSearchParams( window.location.search );
			params.forEach( function ( value, key ) {
				var m;
				if ( ( m = key.match( /^fa_(\d+)$/ ) ) ) {
					state.attrs[ m[ 1 ] ] = value.split( ',' ).filter( Boolean );
				} else if ( ( m = key.match( /^fc_(\d+)$/ ) ) ) {
					state.cats[ m[ 1 ] ] = value.split( ',' ).filter( Boolean );
				} else if ( 'fb' === key ) {
					state.brands = value.split( ',' ).filter( Boolean );
				} else if ( 'fmin' === key && '' !== value ) {
					state.min = parseFloat( value );
				} else if ( 'fmax' === key && '' !== value ) {
					state.max = parseFloat( value );
				} else if ( 'fin' === key ) {
					state.instock = true;
				}
			} );
		} )();

		function stateParams() {
			var params = new URLSearchParams();
			Object.keys( state.attrs ).forEach( function ( id ) {
				if ( state.attrs[ id ].length ) {
					params.set( 'fa_' + id, state.attrs[ id ].join( ',' ) );
				}
			} );
			Object.keys( state.cats ).forEach( function ( i ) {
				if ( state.cats[ i ].length ) {
					params.set( 'fc_' + i, state.cats[ i ].join( ',' ) );
				}
			} );
			if ( state.brands.length ) {
				params.set( 'fb', state.brands.join( ',' ) );
			}
			if ( null !== state.min ) {
				params.set( 'fmin', String( state.min ) );
			}
			if ( null !== state.max ) {
				params.set( 'fmax', String( state.max ) );
			}
			if ( state.instock ) {
				params.set( 'fin', '1' );
			}
			return params;
		}

		function activeCount() {
			var n = 0;
			Object.keys( state.attrs ).forEach( function ( id ) {
				n += state.attrs[ id ].length;
			} );
			Object.keys( state.cats ).forEach( function ( i ) {
				n += state.cats[ i ].length;
			} );
			n += state.brands.length;
			if ( null !== state.min || null !== state.max ) {
				n++;
			}
			if ( state.instock ) {
				n++;
			}
			return n;
		}

		function groupList( key ) {
			var m;
			if ( ( m = key.match( /^fa_(\d+)$/ ) ) ) {
				state.attrs[ m[ 1 ] ] = state.attrs[ m[ 1 ] ] || [];
				return state.attrs[ m[ 1 ] ];
			}
			if ( ( m = key.match( /^fc_(\d+)$/ ) ) ) {
				state.cats[ m[ 1 ] ] = state.cats[ m[ 1 ] ] || [];
				return state.cats[ m[ 1 ] ];
			}
			if ( 'fb' === key ) {
				return state.brands;
			}
			return null;
		}

		/* -- fetch & render -- */

		function currentOrderby() {
			return new URLSearchParams( window.location.search ).get( 'orderby' ) || '';
		}

		function apply( keepPage ) {
			clearTimeout( applyTimer );
			applyTimer = setTimeout( function () {
				run( keepPage ? page : 1, false );
			}, 150 );
			syncUi();
			pushUrl();
		}

		function run( toPage, append ) {
			if ( busy ) {
				return;
			}
			busy = true;
			engaged = true;
			document.documentElement.dataset.ocFlt = '1';
			grid.classList.add( 'oc-flt-loading' );

			var params = stateParams();
			params.set( 'action', 'oc_filter' );
			params.set( 'cat', String( cfg.category || 0 ) );
			params.set( 'pg', String( toPage ) );
			var ob = currentOrderby();
			if ( ob ) {
				params.set( 'orderby', ob );
			}

			fetch( ( L.ajaxUrl || '/wp-admin/admin-ajax.php' ) + '?' + params.toString() )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					busy = false;
					grid.classList.remove( 'oc-flt-loading' );

					if ( ! res || ! res.success ) {
						return;
					}

					page = res.data.page;
					pages = res.data.pages;

					var tmp = document.createElement( 'ul' );
					tmp.innerHTML = res.data.html;

					if ( ! append ) {
						grid.innerHTML = '';
					}

					// A combination can still bottom out (a price range set
					// earlier, narrowed away later) — never a blank grid.
					if ( 0 === res.data.found && ! append ) {
						var emptyLi = document.createElement( 'li' );
						emptyLi.className = 'oc-flt-none';
						var msg = document.createElement( 'p' );
						msg.textContent = L.fltNone || 'No products match this combination.';
						var clearBtn = document.createElement( 'button' );
						clearBtn.type = 'button';
						clearBtn.className = 'oc-flt__clear';
						clearBtn.setAttribute( 'data-flt-clear', '' );
						clearBtn.textContent = L.fltClear || 'Clear all';
						clearBtn.hidden = false;
						emptyLi.appendChild( msg );
						emptyLi.appendChild( clearBtn );
						grid.appendChild( emptyLi );
					}

					[].slice.call( tmp.children ).forEach( function ( li ) {
						grid.appendChild( li );
						li.querySelectorAll( '.oc-card-media--gallery' ).forEach( bindCardGallery );
						ocLazyVideos( li );
					} );

					ocRefreshSigned();
					syncCardColors();
					updateFacets( res.data.facets || {} );
					manageMore();

					document.querySelectorAll( '[data-flt-rescount]' ).forEach( function ( el ) {
						el.textContent = ( L.fltResults || '%s results' ).replace( '%s', res.data.found );
					} );

					// Native pagination belongs to the unfiltered page; the result
					// count stays and follows the filtered total.
					document.querySelectorAll( '.woocommerce-pagination' ).forEach( function ( nav ) {
						nav.hidden = true;
					} );

					var count = document.querySelector( '.woocommerce-result-count' );
					if ( count ) {
						count.hidden = false;
						count.textContent = ( L.fltResults || '%s results' ).replace( '%s', res.data.found );
					}
				} )
				.catch( function () {
					busy = false;
					grid.classList.remove( 'oc-flt-loading' );
				} );
		}

		function manageMore() {
			if ( ! moreBtn ) {
				moreBtn = document.createElement( 'button' );
				moreBtn.type = 'button';
				moreBtn.className = 'button oc-load-more oc-load-more--flt';
				moreBtn.textContent = L.loadMore || 'Show more';
				moreBtn.addEventListener( 'click', function () {
					run( page + 1, true );
				} );
				grid.parentElement.insertBefore( moreBtn, grid.nextSibling );
			}
			moreBtn.hidden = page >= pages;

			var oldBtn = document.querySelector( '.oc-load-more:not(.oc-load-more--flt)' );
			if ( oldBtn ) {
				oldBtn.remove();
			}
		}

		function pushUrl() {
			var params = stateParams();
			var qs = params.toString();
			var ob = currentOrderby();
			if ( ob ) {
				qs += ( qs ? '&' : '' ) + 'orderby=' + encodeURIComponent( ob );
			}
			// Filtered results restart at page one — a lingering /page/N/
			// from the scroll tracking would 404 on the way back.
			var path = window.location.pathname.replace( /\/page\/\d+\/?$/, '/' );
			try {
				window.history.replaceState( null, '', path + ( qs ? '?' + qs : '' ) );
			} catch ( e ) {}
		}

		/* -- filtered colour carries into the cards ----
		 * Filtering by a colour should SHOW that colour: every card whose
		 * colour dots include an active filter value flips its gallery to
		 * that colour, exactly as a click on the dot would. Cards without
		 * the colour keep their own look. */

		function syncCardColors() {
			var active = [];
			Object.keys( state.attrs ).forEach( function ( id ) {
				active = active.concat( state.attrs[ id ] );
			} );

			if ( ! active.length ) {
				return;
			}

			// These synthetic dot clicks must not read as "the visitor
			// clicked outside the filter bar" (or as product interest).
			window.__ocDotSync = true;

			grid.querySelectorAll( 'li.product' ).forEach( function ( li ) {
				var dots = li.querySelectorAll( '.oc-colors__item--term[data-slug]' );

				for ( var i = 0; i < dots.length; i++ ) {
					if ( active.indexOf( dots[ i ].dataset.slug ) > -1 ) {
						if ( ! dots[ i ].classList.contains( 'is-current' ) ) {
							dots[ i ].click();
						}
						return;
					}
				}
			} );

			window.__ocDotSync = false;
		}

		/* -- facet ui sync -- */

		function updateFacets( facets ) {
			panels.forEach( function ( panel ) {
				panel.querySelectorAll( '[data-flt-group]' ).forEach( function ( groupEl ) {
					var key = groupEl.dataset.fltGroup;
					if ( 'price' === key || 'fin' === key ) {
						return;
					}
					// A group absent from the payload has no products at all
					// under the other filters — every value goes grey.
					var counts = facets[ key ] || {};

					groupEl.querySelectorAll( '[data-flt-val]' ).forEach( function ( btn ) {
						var v = btn.dataset.fltVal;
						var n = counts[ v ] || 0;
						var active = btn.classList.contains( 'is-active' );
						var em = btn.querySelector( '[data-flt-count]' );

						if ( em ) {
							em.textContent = n;
						}

						var off = 0 === n && ! active;

						// Category groups never grey out — an empty department
						// simply is not offered.
						if ( 0 === key.indexOf( 'fc_' ) ) {
							btn.hidden = off;
							btn.disabled = false;
							btn.classList.remove( 'is-off' );
						} else {
							btn.classList.toggle( 'is-off', off && 'gray' === cfg.empty );
							btn.disabled = off && 'gray' === cfg.empty;
							btn.hidden = off && 'hide' === cfg.empty;
						}
					} );

					// A category group with nothing left steps aside entirely.
					if ( 0 === key.indexOf( 'fc_' ) ) {
						var anyVisible = [].some.call( groupEl.querySelectorAll( '[data-flt-val]' ), function ( b ) {
							return ! b.hidden;
						} );
						groupEl.hidden = ! anyVisible;
					}
				} );

				// Price bounds narrow with the other filters. While the price
				// filter itself is untouched, the slider follows the fresh
				// bounds — so it can never offer a range with nothing in it.
				if ( facets.price ) {
					var priceGroup = panel.querySelector( '[data-flt-group="price"]' );
					if ( priceGroup ) {
						priceGroup.dataset.lo = facets.price.lo;
						priceGroup.dataset.hi = facets.price.hi;

						var slider = priceGroup.querySelector( '[data-flt-slider]' );
						if ( slider && null === state.min && null === state.max ) {
							var rlo = slider.querySelector( '[data-flt-rlo]' );
							var rhi = slider.querySelector( '[data-flt-rhi]' );
							[ rlo, rhi ].forEach( function ( input ) {
								input.min = facets.price.lo;
								input.max = facets.price.hi;
							} );
							rlo.value = facets.price.lo;
							rhi.value = facets.price.hi;
							if ( slider.__ocDraw ) {
								slider.__ocDraw();
							}
						}
					}
				}
			} );
		}

		function syncUi() {
			var total = activeCount();

			panels.forEach( function ( panel ) {
				panel.querySelectorAll( '[data-flt-group]' ).forEach( function ( groupEl ) {
					var key = groupEl.dataset.fltGroup;
					var num = groupEl.querySelector( '[data-flt-num]' );
					var n = 0;

					if ( 'price' === key ) {
						n = null !== state.min || null !== state.max ? 1 : 0;
					} else if ( 'fin' !== key ) {
						var list = groupList( key );
						n = list ? list.length : 0;
					}

					if ( num ) {
						num.textContent = '(' + n + ')';
						num.hidden = 0 === n;
					}

					groupEl.querySelectorAll( '[data-flt-val]' ).forEach( function ( btn ) {
						var list = groupList( key );
						btn.classList.toggle( 'is-active', !! list && list.indexOf( btn.dataset.fltVal ) > -1 );
					} );

					groupEl.querySelectorAll( '[data-flt-tier]' ).forEach( function ( btn ) {
						btn.classList.toggle( 'is-active', null === state.min && null !== state.max && parseFloat( btn.dataset.fltTier ) === state.max );
					} );
				} );
			} );

			document.querySelectorAll( '[data-flt-badge]' ).forEach( function ( badge ) {
				badge.textContent = total;
				badge.hidden = 0 === total;
			} );

			document.querySelectorAll( '[data-flt-clear]' ).forEach( function ( btn ) {
				btn.hidden = 0 === total;
			} );

			// The "view results" foot earns its place once something is filtered.
			document.querySelectorAll( '[data-flt-foot]' ).forEach( function ( foot ) {
				foot.hidden = 0 === total;
			} );

			renderChips();
		}

		function renderChips() {
			var wraps = [].slice.call( document.querySelectorAll( '[data-flt-chips]' ) );
			var groupWraps = {};
			[].forEach.call( document.querySelectorAll( '[data-flt-chips-group]' ), function ( w ) {
				groupWraps[ w.dataset.fltChipsGroup ] = w;
				w.innerHTML = '';
			} );
			if ( ! wraps.length ) {
				return;
			}

			wraps.forEach( function ( w ) {
				w.innerHTML = '';
			} );
			var any = false;

			function chip( label, remove, swatchStyle, groupKey ) {
				var mode = cfg.chipSwatch || 'off';
				var only = swatchStyle && 'only' === mode;
				// A chip whose group has its own slot renders only there;
				// everything else lands in every general container.
				var targets = groupKey && groupWraps[ groupKey ] ? [ groupWraps[ groupKey ] ] : wraps;
				any = true;
				targets.forEach( function ( w ) {
					var b = document.createElement( 'button' );
					b.type = 'button';
					b.className = 'oc-flt__chip' + ( only ? ' oc-flt__chip--dot' : '' );
					if ( swatchStyle ) {
						var dot = document.createElement( 'i' );
						dot.className = 'oc-flt__swatch';
						dot.setAttribute( 'style', swatchStyle );
						b.appendChild( dot );
					}
					if ( ! only ) {
						var text = document.createElement( 'span' );
						text.textContent = label;
						b.appendChild( text );
					}
					b.setAttribute( 'aria-label', label );
					var x = document.createElement( 'i' );
					x.className = 'oc-flt__x';
					x.setAttribute( 'aria-hidden', 'true' );
					x.innerHTML = '&times;';
					b.appendChild( x );
					b.addEventListener( 'click', remove );
					w.appendChild( b );
				} );
			}

			panels[ 0 ].querySelectorAll( '[data-flt-group]' ).forEach( function ( groupEl ) {
				var key = groupEl.dataset.fltGroup;
				var list = groupList( key );
				if ( ! list || ! list.length ) {
					return;
				}
				list.slice().forEach( function ( v ) {
					var btn = groupEl.querySelector( '[data-flt-val="' + v + '"]' );
					var swatch = 'off' !== ( cfg.chipSwatch || 'off' ) && btn ? btn.querySelector( '.oc-flt__swatch' ) : null;
					chip( btn ? btn.dataset.label : v, function () {
						var idx = list.indexOf( v );
						if ( idx > -1 ) {
							list.splice( idx, 1 );
						}
						apply();
					}, swatch ? swatch.getAttribute( 'style' ) : null, key );
				} );
			} );

			if ( null !== state.min || null !== state.max ) {
				var lbl = ( cfg.currency || '' ) + ( null !== state.min ? state.min : '' ) + ' — ' + ( cfg.currency || '' ) + ( null !== state.max ? state.max : '' );
				chip( lbl, function () {
					state.min = null;
					state.max = null;
					apply();
				}, null, 'price' );
			}

			// No chip for the in-stock toggle: it is a visible switch already,
			// flipping it off is self-explanatory.

			wraps.forEach( function ( w ) {
				if ( any ) {
					var clear = document.createElement( 'button' );
					clear.type = 'button';
					clear.className = 'oc-flt__clear';
					clear.setAttribute( 'data-flt-clear', '' );
					clear.textContent = L.fltClear || 'Clear all';
					w.appendChild( clear );
				}
				w.hidden = ! any;
			} );

			Object.keys( groupWraps ).forEach( function ( k ) {
				groupWraps[ k ].hidden = ! groupWraps[ k ].childElementCount;
			} );
		}

		/* -- events -- */

		document.addEventListener( 'click', function ( event ) {
			var toggle = event.target.closest( '[data-flt-toggle]' );
			if ( toggle ) {
				var groupEl = toggle.closest( '[data-flt-group]' );
				var wasOpen = groupEl.classList.contains( 'is-open' );

				// The top bar behaves like a menu: one open at a time.
				if ( toggle.closest( '.oc-flt--top' ) ) {
					toggle.closest( '.oc-flt--top' ).querySelectorAll( '.is-open' ).forEach( function ( other ) {
						other.classList.remove( 'is-open' );
					} );
				}
				groupEl.classList.toggle( 'is-open', ! wasOpen );
				return;
			}

			var val = event.target.closest( '[data-flt-val]' );
			if ( val && ! val.disabled ) {
				var list = groupList( val.closest( '[data-flt-group]' ).dataset.fltGroup );
				if ( list ) {
					var i = list.indexOf( val.dataset.fltVal );
					if ( i > -1 ) {
						list.splice( i, 1 );
					} else {
						list.push( val.dataset.fltVal );
					}
					apply();
				}
				return;
			}

			var tier = event.target.closest( '[data-flt-tier]' );
			if ( tier ) {
				var t = parseFloat( tier.dataset.fltTier );
				if ( null === state.min && state.max === t ) {
					state.max = null;
				} else {
					state.min = null;
					state.max = t;
				}
				apply();
				return;
			}

			if ( event.target.closest( '[data-flt-clear]' ) ) {
				state = { attrs: {}, cats: {}, brands: [], min: null, max: null, instock: false };
				document.querySelectorAll( '[data-flt-instock]' ).forEach( function ( t ) {
					t.checked = false;
				} );
				apply();
				return;
			}

			if ( event.target.closest( '[data-flt-open]' ) ) {
				document.body.classList.add( 'oc-flt-m-open' );
				return;
			}

			if ( event.target.closest( '[data-flt-close]' ) || event.target.closest( '[data-flt-overlay]' ) || event.target.closest( '[data-flt-close-apply]' ) ) {
				document.body.classList.remove( 'oc-flt-m-open' );
				return;
			}

			// A click outside the top bar closes its open dropdown — desktop
			// only: on mobile the bar is an accordion panel and outside
			// clicks close the panel, never its groups. Synthetic card-dot
			// clicks from the colour sync do not count as outside clicks.
			if ( ! window.__ocDotSync && window.matchMedia( '(min-width: 901px)' ).matches && ! event.target.closest( '.oc-flt--top' ) ) {
				document.querySelectorAll( '.oc-flt--top .is-open' ).forEach( function ( other ) {
					other.classList.remove( 'is-open' );
				} );
			}

			var papply = event.target.closest( '[data-flt-papply]' );
			if ( papply ) {
				var box = papply.closest( '[data-flt-group]' );
				var lo = parseFloat( ( box.querySelector( '[data-flt-ilo]' ) || {} ).value );
				var hi = parseFloat( ( box.querySelector( '[data-flt-ihi]' ) || {} ).value );
				state.min = isNaN( lo ) ? null : lo;
				state.max = isNaN( hi ) ? null : hi;
				apply();
			}
		} );

		document.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '[data-flt-instock]' ) ) {
				state.instock = event.target.checked;
				document.querySelectorAll( '[data-flt-instock]' ).forEach( function ( t ) {
					t.checked = state.instock;
				} );
				apply();
			}
		} );

		/* -- price slider: two thumbs, apply on release -- */

		document.querySelectorAll( '[data-flt-slider]' ).forEach( function ( slider ) {
			var lo = slider.querySelector( '[data-flt-rlo]' );
			var hi = slider.querySelector( '[data-flt-rhi]' );
			var fill = slider.querySelector( '[data-flt-fill]' );
			var box = slider.closest( '[data-flt-group]' );
			var plo = box.querySelector( '[data-flt-plo]' );
			var phi = box.querySelector( '[data-flt-phi]' );

			function draw() {
				var min = parseFloat( lo.min );
				var max = parseFloat( lo.max );
				var a = Math.min( parseFloat( lo.value ), parseFloat( hi.value ) );
				var b = Math.max( parseFloat( lo.value ), parseFloat( hi.value ) );
				var span = Math.max( 1, max - min );

				if ( fill ) {
					fill.style.insetInlineStart = ( ( a - min ) / span * 100 ) + '%';
					fill.style.inlineSize = ( ( b - a ) / span * 100 ) + '%';
				}
				if ( plo ) {
					plo.textContent = ( cfg.currency || '' ) + a;
				}
				if ( phi ) {
					phi.textContent = ( cfg.currency || '' ) + b;
				}
			}

			[ lo, hi ].forEach( function ( input ) {
				input.addEventListener( 'input', draw );
				input.addEventListener( 'change', function () {
					var a = Math.min( parseFloat( lo.value ), parseFloat( hi.value ) );
					var b = Math.max( parseFloat( lo.value ), parseFloat( hi.value ) );
					state.min = a <= parseFloat( lo.min ) ? null : a;
					state.max = b >= parseFloat( lo.max ) ? null : b;
					apply();
				} );
			} );

			slider.__ocDraw = draw;
			draw();
		} );

		/* -- sort bottom sheet: the native select's options, app-style -- */

		( function () {
			var sheet = document.querySelector( '[data-oc-sortsheet]' );
			var overlay = document.querySelector( '[data-oc-sort-overlay]' );
			var listEl = sheet ? sheet.querySelector( '[data-oc-sortlist]' ) : null;

			if ( ! sheet || ! listEl ) {
				return;
			}

			function nativeSelect() {
				return document.querySelector( '.woocommerce-ordering select.orderby, form.woocommerce-ordering select' );
			}

			function closeSheet() {
				document.body.classList.remove( 'oc-sort-open' );
			}

			// "Sort: <active>" on the desktop bars — from the native select.
			( function () {
				var sel = nativeSelect();
				var opt = sel && sel.options[ sel.selectedIndex ];
				if ( opt ) {
					[].forEach.call( document.querySelectorAll( '[data-oc-sortcur]' ), function ( el ) {
						el.textContent = ': ' + opt.textContent;
					} );
				}
			} )();

			document.addEventListener( 'click', function ( event ) {
				if ( event.target.closest( '[data-oc-sort-open]' ) ) {
					var sel = nativeSelect();
					if ( ! sel ) {
						return;
					}
					listEl.innerHTML = '';
					[].forEach.call( sel.options, function ( opt ) {
						var b = document.createElement( 'button' );
						b.type = 'button';
						b.className = 'oc-sortsheet__opt' + ( opt.selected ? ' is-active' : '' );
						b.textContent = opt.textContent;
						b.addEventListener( 'click', function () {
							sel.value = opt.value;
							closeSheet();
							// Woo's ordering form re-posts the query string
							// from hidden fields snapshotted at page load —
							// stale the moment filters change without a
							// reload (cleared filters came back). Navigate
							// from the LIVE url instead, back to page 1.
							var url = new URL( window.location.href );
							url.searchParams.set( 'orderby', opt.value );
							url.searchParams.delete( 'paged' );
							url.pathname = url.pathname.replace( /\/page\/\d+\/?$/, '/' );
							window.location.assign( url.toString() );
						} );
						listEl.appendChild( b );
					} );

					// Desktop: pin the sheet as a dropdown under whichever
					// bar holds the opener; mobile keeps the bottom sheet.
					if ( sheet && window.matchMedia && window.matchMedia( '(min-width: 901px)' ).matches ) {
						var opener = event.target.closest( '[data-oc-sort-open]' );
						var bar = opener.closest( '.oc-flt__mbar, .oc-flt--top, .oc-flt__sidebar-row' );
						if ( bar ) {
							sheet.classList.add( 'oc-sortsheet--drop' );
							sheet.classList.toggle( 'oc-sortsheet--end', ! ! opener.closest( '.oc-flt--top, .oc-flt__sidebar-row' ) );
							sheet.style.insetBlockStart = ( bar.offsetTop + bar.offsetHeight + 1 ) + 'px';
						}
					}

					document.body.classList.add( 'oc-sort-open' );
					return;
				}

				if ( event.target.closest( '[data-oc-sort-close]' ) || event.target.closest( '[data-oc-sort-overlay]' ) ) {
					closeSheet();
				}
			} );
		} )();

		syncUi();
	} )();

	/* ---------- card add-to-cart icon → cart drawer ---------- */

	var drawer = document.querySelector( '[data-oc-cart-drawer]' );

	function openDrawer() {
		if ( ! drawer ) {
			return;
		}
		drawer.hidden = false;
		setTimeout( function () {
			drawer.classList.add( 'is-open' );
		}, 10 );
		document.documentElement.style.overflow = 'hidden';
	}

	function closeDrawer() {
		if ( ! drawer ) {
			return;
		}
		drawer.classList.remove( 'is-open' );
		document.documentElement.style.overflow = '';
		setTimeout( function () {
			drawer.hidden = true;
		}, 220 );
	}

	// The same machinery serves the drawer AND the full cart page — the
	// page reuses every component, it just has no sliding panel around it.
	var cartRoot = drawer || document.querySelector( '.oc-cartpage' );

	if ( cartRoot ) {
		if ( drawer ) {
			drawer.addEventListener( 'click', function ( event ) {
				if ( event.target.closest( '[data-oc-drawer-close]' ) ) {
					closeDrawer();
				}
			} );

			// The header cart icon opens the drawer instead of leaving the page.
			document.addEventListener( 'click', function ( event ) {
				var link = event.target.closest( '.oc-cart-link' );
				if ( link ) {
					event.preventDefault();
					openDrawer();
				}
			} );

			document.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Escape' && ! drawer.hidden ) {
					closeDrawer();
				}
			} );
		}

		// Simple products add through Woo's own ajax handler; open the drawer
		// right away (when configured to) and let cart fragments fill it
		// when the add completes.
		var openOnAdd = 1 === Number( ( window.ocL10n || {} ).cartOpenOnAdd );

		document.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '.oc-card-atc.ajax_add_to_cart, .oc-cartup__add.ajax_add_to_cart' );
			if ( ! btn ) {
				return;
			}
			btn.classList.add( 'loading' );
			if ( openOnAdd && ! btn.closest( '[data-oc-cart-drawer]' ) ) {
				openDrawer();
			}
			// Woo's add-to-cart JS handles the request itself.
			setTimeout( function () {
				btn.classList.remove( 'loading' );
				btn.classList.add( 'added' );
			}, 900 );
			setTimeout( function () {
				btn.classList.remove( 'added' );
			}, 2600 );
		} );

		// A non-ajax add (the single product form) lands back with
		// ?add-to-cart / added-to-cart in the url — open the drawer then too.
		if ( openOnAdd && /(?:^|[?&])(?:add|added)-to-cart=/.test( window.location.search ) ) {
			setTimeout( openDrawer, 350 );
		}

		/* -- live quantity: steppers post the new count, the answer is the
		 *    same fragment payload Woo's own add uses -- */

		var qtyTimer = null;

		function sendQty( wrap, qty ) {
			wrap.classList.add( 'is-busy' );
			var data = new FormData();
			data.append( 'action', 'oc_cart_qty' );
			data.append( 'key', wrap.dataset.key );
			data.append( 'qty', String( qty ) );

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
						// Let Woo's fragments cache pick the change up too.
						if ( window.sessionStorage && window.wc_cart_fragments_params ) {
							try {
								sessionStorage.setItem( wc_cart_fragments_params.fragment_name, JSON.stringify( res.fragments ) );
							} catch ( e ) { /* storage full — cosmetic only */ }
						}
					}
				} )
				.catch( function () {
					wrap.classList.remove( 'is-busy' );
				} );
		}

		function showConfirm( item ) {
			var confirm = item && item.querySelector( '[data-oc-confirm]' );
			if ( confirm ) {
				confirm.hidden = false;
			}
		}

		var minusBursts = [];

		document.addEventListener( 'click', function ( event ) {
			var minus = event.target.closest( '[data-oc-qty-minus]' );
			var plus = event.target.closest( '[data-oc-qty-plus]' );
			var trash = event.target.closest( '[data-oc-qty-trash]' );
			var yes = event.target.closest( '[data-oc-confirm-yes]' );
			var no = event.target.closest( '[data-oc-confirm-no]' );

			// One unit left: the trash can asks before removing.
			if ( trash ) {
				showConfirm( trash.closest( '.oc-mcart__item' ) );
				return;
			}

			if ( yes ) {
				clearTimeout( qtyTimer );
				sendQty( yes, 0 );
				return;
			}

			if ( no ) {
				var confirmBox = no.closest( '[data-oc-confirm]' );
				confirmBox.hidden = true;
				// A paused quantity change (mash path) resumes now.
				var pausedWrap = confirmBox.closest( '.oc-mcart__item' ).querySelector( '[data-oc-qty]' );
				if ( pausedWrap ) {
					var pausedInput = pausedWrap.querySelector( 'input' );
					sendQty( pausedWrap, Math.max( 1, parseInt( pausedInput.value, 10 ) || 1 ) );
				}
				return;
			}

			if ( ! minus && ! plus ) {
				return;
			}

			var wrap = ( minus || plus ).closest( '[data-oc-qty]' );
			var input = wrap.querySelector( 'input' );
			var next = Math.max( 1, ( parseInt( input.value, 10 ) || 1 ) + ( plus ? 1 : -1 ) );
			input.value = String( next );

			// Mashing minus reads as "I want this gone" — surface the removal
			// question instead of making them count all the way down. The
			// pending quantity update pauses so its refresh cannot wipe the
			// question away; answering "No" resumes it.
			if ( minus ) {
				var now = Date.now();
				minusBursts = minusBursts.filter( function ( t ) {
					return now - t < 1300;
				} );
				minusBursts.push( now );
				if ( minusBursts.length >= 3 ) {
					minusBursts = [];
					clearTimeout( qtyTimer );
					showConfirm( wrap.closest( '.oc-mcart__item' ) );
					return;
				}
			}

			clearTimeout( qtyTimer );
			qtyTimer = setTimeout( function () {
				sendQty( wrap, next );
			}, 350 );
		} );

		/* -- the quiet clear-all link asks for a second tap -- */

		document.addEventListener( 'click', function ( event ) {
			var clear = event.target.closest( '[data-oc-cart-clear]' );
			if ( ! clear ) {
				return;
			}

			if ( ! clear.dataset.armed ) {
				clear.dataset.armed = '1';
				clear.dataset.label = clear.textContent;
				clear.textContent = clear.dataset.arm || clear.textContent;
				setTimeout( function () {
					if ( clear.isConnected && clear.dataset.armed ) {
						delete clear.dataset.armed;
						clear.textContent = clear.dataset.label;
					}
				}, 3500 );
				return;
			}

			var data = new FormData();
			data.append( 'action', 'oc_cart_qty' );
			data.append( 'clear', '1' );
			clear.classList.add( 'is-busy' );

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
				} );
		} );

		document.addEventListener( 'change', function ( event ) {
			var input = event.target.closest( '[data-oc-qty] input' );
			if ( ! input ) {
				return;
			}
			var wrap = input.closest( '[data-oc-qty]' );
			sendQty( wrap, Math.max( 0, parseInt( input.value, 10 ) || 1 ) );
		} );

		/* -- coupon field opens behind its quiet trigger -- */

		document.addEventListener( 'click', function ( event ) {
			var couponToggle = event.target.closest( '[data-oc-coupon-toggle]' );
			if ( ! couponToggle ) {
				return;
			}
			var couponWrap = couponToggle.closest( '.oc-drawer__coupon-wrap' );
			var open = couponWrap.classList.toggle( 'is-open' );
			if ( open ) {
				var field = couponWrap.querySelector( 'input[name="coupon_code"]' );
				setTimeout( function () {
					if ( field ) {
						field.focus();
					}
				}, 260 );
			}
		} );

		/* -- horizontal upsell scrollers: arrows, and drag with the mouse
		 *    (fingers scroll natively) -- */

		document.addEventListener( 'click', function ( event ) {
			var arrow = event.target.closest( '[data-oc-up-prev], [data-oc-up-next]' );
			if ( ! arrow ) {
				return;
			}
			var track = arrow.closest( '.oc-cartup__body' ).querySelector( '[data-oc-up-track]' );
			var item = track.querySelector( '.oc-cartup__item' );
			var step = item ? item.getBoundingClientRect().width + 12 : 200;
			var dir = arrow.hasAttribute( 'data-oc-up-next' ) ? 1 : -1;
			// RTL: visual next means scrolling further negative.
			if ( 'rtl' === getComputedStyle( track ).direction ) {
				dir = -dir;
			}
			track.scrollBy( { left: dir * step * 2, behavior: 'smooth' } );
		} );

		function updateUpArrows() {
			document.querySelectorAll( '[data-oc-up-track].oc-cartup__items--h' ).forEach( function ( track ) {
				var body = track.closest( '.oc-cartup__body' );
				if ( ! body ) {
					return;
				}
				var prev = body.querySelector( '[data-oc-up-prev]' );
				var next = body.querySelector( '[data-oc-up-next]' );
				var max = track.scrollWidth - track.clientWidth;
				var pos = Math.abs( track.scrollLeft );
				var hidePrev = pos <= 2;
				var hideNext = pos >= max - 2;
				if ( prev && prev.hidden !== hidePrev ) {
					prev.hidden = hidePrev;
				}
				if ( next && next.hidden !== hideNext ) {
					next.hidden = hideNext;
				}
				// A soft fade on the far edge whispers "there is more".
				track.classList.toggle( 'oc-fade-end', ! hideNext );
			} );
		}

		document.addEventListener( 'scroll', function ( event ) {
			if ( event.target instanceof Element && event.target.matches( '[data-oc-up-track]' ) ) {
				updateUpArrows();
			}
		}, true );

		// The mouse wheel scrolls the horizontal track too.
		document.addEventListener( 'wheel', function ( event ) {
			var track = event.target instanceof Element && event.target.closest( '[data-oc-up-track].oc-cartup__items--h' );
			if ( ! track || Math.abs( event.deltaY ) <= Math.abs( event.deltaX ) ) {
				return;
			}
			if ( track.scrollWidth <= track.clientWidth ) {
				return;
			}
			event.preventDefault();
			track.scrollLeft += ( 'rtl' === getComputedStyle( track ).direction ? -1 : 1 ) * event.deltaY;
		}, { passive: false } );

		updateUpArrows();

		( function () {
			var dragTrack = null;
			var dragStartX = 0;
			var dragStartScroll = 0;
			var dragged = false;

			document.addEventListener( 'pointerdown', function ( event ) {
				if ( 'mouse' !== event.pointerType ) {
					return;
				}
				var track = event.target.closest( '[data-oc-up-track].oc-cartup__items--h' );
				if ( ! track ) {
					return;
				}
				dragTrack = track;
				dragStartX = event.clientX;
				dragStartScroll = track.scrollLeft;
				dragged = false;
			} );

			document.addEventListener( 'pointermove', function ( event ) {
				if ( ! dragTrack ) {
					return;
				}
				var dx = event.clientX - dragStartX;
				if ( Math.abs( dx ) > 4 ) {
					dragged = true;
					dragTrack.classList.add( 'is-dragging' );
				}
				dragTrack.scrollLeft = dragStartScroll - dx;
			} );

			document.addEventListener( 'pointerup', function () {
				if ( dragTrack ) {
					dragTrack.classList.remove( 'is-dragging' );
					dragTrack = null;
				}
			} );

			// A drag must not count as a click on a card inside.
			document.addEventListener( 'click', function ( event ) {
				if ( dragged && event.target.closest( '[data-oc-up-track]' ) ) {
					event.preventDefault();
					event.stopPropagation();
					dragged = false;
				}
			}, true );
		} )();

		/* -- the minimizable upsell block remembers its state -- */

		document.addEventListener( 'click', function ( event ) {
			var block = event.target.closest( '.oc-cartup--collapse' );
			if ( ! block ) {
				return;
			}

			var min;

			if ( block.classList.contains( 'is-min' ) ) {
				// Minimized: the whole block is the open button.
				min = false;
			} else if ( event.target.closest( '[data-oc-up-toggle]' ) || event.target.closest( '.oc-cartup__title' ) ) {
				// Open: the title or the chevron folds it away.
				min = true;
			} else {
				return;
			}

			block.classList.toggle( 'is-min', min );
			try {
				localStorage.setItem( 'oc-cartup-min', min ? '1' : '0' );
			} catch ( e ) { /* private mode */ }
		} );

		function restoreUpsellState() {
			try {
				if ( '1' === localStorage.getItem( 'oc-cartup-min' ) ) {
					document.querySelectorAll( '.oc-cartup--collapse:not(.is-min)' ).forEach( function ( el ) {
						el.classList.add( 'is-min' );
					} );
				}
			} catch ( e ) { /* private mode */ }
		}

		restoreUpsellState();

		/* -- reaching free shipping deserves a small, tasteful celebration -- */

		var shipWasDone = ! ! document.querySelector( '.oc-shipbar.is-done' );

		function confettiBurst( host ) {
			var colors = [ '#e6b84c', '#b4453c', '#2f7d4f', '#3b6ea5', '#8a5ab5' ];
			for ( var i = 0; i < 26; i++ ) {
				var bit = document.createElement( 'i' );
				bit.className = 'oc-confetti';
				bit.style.setProperty( '--cx', ( Math.random() * 100 ) + '%' );
				bit.style.setProperty( '--cdx', ( ( Math.random() - 0.5 ) * 90 ) + 'px' );
				bit.style.setProperty( '--crot', ( Math.random() * 540 - 270 ) + 'deg' );
				bit.style.setProperty( '--cdur', ( 0.9 + Math.random() * 0.8 ) + 's' );
				bit.style.background = colors[ i % colors.length ];
				if ( Math.random() > 0.5 ) {
					bit.style.borderRadius = '50%';
				}
				host.appendChild( bit );
			}
			setTimeout( function () {
				host.querySelectorAll( '.oc-confetti' ).forEach( function ( b ) {
					b.remove();
				} );
			}, 2100 );
		}

		var drawerTickQueued = false;

		function watchDrawer() {
			// One pass per frame, however many mutations arrive — the pass
			// itself mutates (arrow visibility, is-min, confetti), so running
			// it synchronously per mutation can feed back into a freeze.
			if ( drawerTickQueued ) {
				return;
			}
			drawerTickQueued = true;
			requestAnimationFrame( function () {
				drawerTickQueued = false;
				restoreUpsellState();
				updateUpArrows();

				var bar = document.querySelector( '.oc-shipbar' );
				var done = ! ! ( bar && bar.classList.contains( 'is-done' ) );
				if ( done && ! shipWasDone && ( ! drawer || ! drawer.hidden ) ) {
					confettiBurst( bar );
				}
				shipWasDone = done;
			} );
		}

		// Fragments replace drawer pieces — re-apply remembered state and
		// notice the free-shipping moment. Two narrow observers: subtree
		// CONTENT changes, and open/close flips on the drawer root only —
		// never subtree attributes, which watchDrawer itself writes.
		new MutationObserver( watchDrawer ).observe( cartRoot, { subtree: true, childList: true } );
		new MutationObserver( watchDrawer ).observe( cartRoot, { attributes: true, attributeFilter: [ 'class', 'hidden' ] } );

		/* -- upsell plus on a variable product: a small picker asks which -- */

		var varModal = null;

		function closeVarModal() {
			if ( varModal ) {
				varModal.hidden = true;
			}
		}

		/* -- "participating products" popup for a group promotion -- */

		var promoModal = null;

		document.addEventListener( 'click', function ( event ) {
			if ( promoModal && ! promoModal.hidden && ( event.target.closest( '.oc-pmodal .oc-nmodal__close' ) || ( event.target === promoModal ) ) ) {
				promoModal.hidden = true;
				return;
			}

			var promoLink = event.target.closest( '[data-oc-promo-list]' );
			if ( ! promoLink ) {
				return;
			}

			if ( ! promoModal ) {
				promoModal = document.createElement( 'div' );
				promoModal.className = 'oc-nmodal oc-pmodal';
				promoModal.hidden = true;
				promoModal.innerHTML =
					'<div class="oc-nmodal__card">' +
					'<button type="button" class="oc-nmodal__close" aria-label="close">&times;</button>' +
					'<h3 class="oc-nmodal__title"></h3>' +
					'<div class="oc-pmodal__list"></div>' +
					'</div>';
				document.body.appendChild( promoModal );
			}

			promoModal.querySelector( '.oc-nmodal__title' ).textContent = promoLink.dataset.name || '';
			var plist = promoModal.querySelector( '.oc-pmodal__list' );
			plist.innerHTML = '<span class="oc-vmodal__loading">…</span>';
			promoModal.hidden = false;

			var data = new FormData();
			data.append( 'action', 'oc_cart_promo_products' );
			data.append( 'promo_id', promoLink.dataset.ocPromoList );

			fetch( ( window.ocL10n || {} ).ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					plist.innerHTML = '';
					if ( ! res || ! res.success || ! res.data.products.length ) {
						promoModal.hidden = true;
						return;
					}
					res.data.products.forEach( function ( pr ) {
						var row = document.createElement( 'a' );
						row.className = 'oc-pmodal__row';
						row.href = pr.url;
						row.innerHTML = ( pr.img ? '<img src="' + pr.img + '" alt="" loading="lazy" />' : '' ) + '<span class="oc-pmodal__name"></span><em></em>';
						row.querySelector( '.oc-pmodal__name' ).textContent = pr.name;
						row.querySelector( 'em' ).textContent = pr.price;
						plist.appendChild( row );
					} );
				} );
		} );

		/* -- a single-variation product adds straight away, no questions -- */

		document.addEventListener( 'click', function ( event ) {
			var single = event.target.closest( '[data-oc-up-single]' );
			if ( ! single ) {
				return;
			}
			single.classList.add( 'loading' );
			var data = new FormData();
			data.append( 'action', 'oc_cart_add' );
			data.append( 'product_id', single.dataset.ocUpSingle );
			data.append( 'variation_id', single.dataset.variation );
			fetch( ( window.ocL10n || {} ).ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( out ) {
					if ( out && out.fragments ) {
						Object.keys( out.fragments ).forEach( function ( selector ) {
							document.querySelectorAll( selector ).forEach( function ( el ) {
								var box = document.createElement( 'div' );
								box.innerHTML = out.fragments[ selector ];
								if ( box.firstElementChild ) {
									el.replaceWith( box.firstElementChild );
								}
							} );
						} );
					}
				} );
		} );

		/* -- coupon: applies inside the panel, no page leaves -- */

		function couponRequest( code, remove, onError ) {
			var data = new FormData();
			data.append( 'action', 'oc_cart_coupon' );
			data.append( 'code', code );
			if ( remove ) {
				data.append( 'remove', '1' );
			}
			return fetch( ( window.ocL10n || {} ).ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( out ) {
					if ( out && out.fragments ) {
						Object.keys( out.fragments ).forEach( function ( selector ) {
							document.querySelectorAll( selector ).forEach( function ( el ) {
								var box = document.createElement( 'div' );
								box.innerHTML = out.fragments[ selector ];
								if ( box.firstElementChild ) {
									el.replaceWith( box.firstElementChild );
								}
							} );
						} );
					} else if ( out && ! out.success && onError ) {
						onError( out.data && out.data.message ? out.data.message : '' );
					}
				} );
		}

		document.addEventListener( 'submit', function ( event ) {
			var form = event.target.closest( '[data-oc-coupon-form]' );
			if ( ! form ) {
				return;
			}
			event.preventDefault();
			var input = form.querySelector( 'input[name="coupon_code"]' );
			var msg = form.querySelector( '[data-oc-coupon-msg]' );
			var code = ( input.value || '' ).trim();
			if ( ! code ) {
				return;
			}
			form.classList.add( 'is-busy' );
			couponRequest( code, false, function ( text ) {
				form.classList.remove( 'is-busy' );
				if ( msg ) {
					msg.textContent = text;
					msg.hidden = ! text;
				}
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			var removeCoupon = event.target.closest( '[data-oc-coupon-remove]' );
			if ( removeCoupon ) {
				couponRequest( removeCoupon.dataset.code, true );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			var opener = event.target.closest( '[data-oc-up-var]' );

			if ( varModal && ! varModal.hidden && ( event.target.closest( '.oc-nmodal__close' ) || event.target === varModal ) && event.target.closest( '.oc-vmodal' ) ) {
				closeVarModal();
				return;
			}

			if ( ! opener ) {
				return;
			}

			if ( ! varModal ) {
				varModal = document.createElement( 'div' );
				varModal.className = 'oc-nmodal oc-vmodal';
				varModal.hidden = true;
				varModal.innerHTML =
					'<div class="oc-nmodal__card">' +
					'<button type="button" class="oc-nmodal__close" aria-label="close">&times;</button>' +
					'<h3 class="oc-nmodal__title"></h3>' +
					'<p class="oc-nmodal__intro">' + ( ( window.ocL10n || {} ).cartVarPick || 'Choose an option' ) + '</p>' +
					'<div class="oc-vmodal__list"></div>' +
					'</div>';
				document.body.appendChild( varModal );
			}

			varModal.querySelector( '.oc-nmodal__title' ).textContent = opener.dataset.name || '';
			var list = varModal.querySelector( '.oc-vmodal__list' );
			list.innerHTML = '<span class="oc-vmodal__loading">…</span>';
			varModal.hidden = false;

			var data = new FormData();
			data.append( 'action', 'oc_cart_vars' );
			data.append( 'product_id', opener.dataset.ocUpVar );

			fetch( ( window.ocL10n || {} ).ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					list.innerHTML = '';
					if ( ! res || ! res.success || ! res.data.variations.length ) {
						closeVarModal();
						return;
					}
					res.data.variations.forEach( function ( v ) {
						var row = document.createElement( 'button' );
						row.type = 'button';
						row.className = 'oc-vmodal__opt';
						row.innerHTML = '<span class="oc-vmodal__optname"></span><em></em>';
						if ( v.swatch ) {
							var dot = document.createElement( 'i' );
							dot.className = 'oc-flt__swatch oc-vmodal__swatch';
							dot.setAttribute( 'style', v.swatch );
							row.insertBefore( dot, row.firstChild );
						}
						row.querySelector( 'span' ).textContent = v.label;
						row.querySelector( 'em' ).textContent = v.price;
						row.addEventListener( 'click', function () {
							row.classList.add( 'is-busy' );
							var add = new FormData();
							add.append( 'action', 'oc_cart_add' );
							add.append( 'product_id', opener.dataset.ocUpVar );
							add.append( 'variation_id', String( v.id ) );
							fetch( ( window.ocL10n || {} ).ajaxUrl || '/wp-admin/admin-ajax.php', {
								method: 'POST',
								credentials: 'same-origin',
								body: add
							} )
								.then( function ( r ) { return r.json(); } )
								.then( function ( out ) {
									if ( out && out.fragments ) {
										Object.keys( out.fragments ).forEach( function ( selector ) {
											document.querySelectorAll( selector ).forEach( function ( el ) {
												var box = document.createElement( 'div' );
												box.innerHTML = out.fragments[ selector ];
												if ( box.firstElementChild ) {
													el.replaceWith( box.firstElementChild );
												}
											} );
										} );
									}
									closeVarModal();
								} );
						} );
						list.appendChild( row );
					} );
				} );
		} );
	}

	/* ---------- product tabs → accordion ---------- */

	if ( document.body.classList.contains( 'oc-tabs-accordion' ) ) {
		// Scoped to the real tabs wrapper: product content pasted from other
		// sites can carry .woocommerce-Tabs-panel markup of its own (it once
		// produced an accordion head named "ui-id-1").
		document.querySelectorAll( '.woocommerce-tabs .woocommerce-Tabs-panel' ).forEach( function ( panel ) {
			panel.closest( '.woocommerce-tabs' ).classList.add( 'oc-acc-init' );
			var heading = panel.querySelector( 'h2' );
			var title = heading ? heading.textContent : '';

			// No heading, no accordion — never fall back to element ids.
			if ( ! title.trim() ) {
				return;
			}

			// Open-by-default tabs arrive as a comma list of panel keys.
			var openKeys = String( ( window.ocL10n || {} ).accOpen || '' ).split( ',' );
			var open = -1 !== openKeys.indexOf( ( panel.id || '' ).replace( /^tab-/, '' ) );

			var head = document.createElement( 'button' );
			head.type = 'button';
			head.className = 'oc-acc-head';
			head.textContent = title || '';
			head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

			// Grid-rows animation: the browser interpolates 0fr↔1fr — no
			// measuring, no timers, smooth at any content height.
			var body = document.createElement( 'div' );
			body.className = 'oc-acc-body' + ( open ? ' is-open' : '' );
			var inner = document.createElement( 'div' );
			inner.className = 'oc-acc-in';
			while ( panel.firstChild ) {
				inner.appendChild( panel.firstChild );
			}
			body.appendChild( inner );
			panel.appendChild( head );
			panel.appendChild( body );

			head.addEventListener( 'click', function () {
				var isOpen = body.classList.toggle( 'is-open' );
				head.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
			} );
		} );
	}

	/* ---------- native product gallery thumbs (no flexslider) ----------
	 * Thumbs presets: rail switches the active slide; "max thumbnails" caps
	 * how many are visible and arrows page the rest. Stacked preset: rail
	 * scrolls to the image (asceno-style) and tracks the one in view. */

	var galleryWrap = document.querySelector( '.woocommerce-product-gallery__wrapper' );
	var galleryBody = document.body;
	var railChevrons = {
		left: '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M 70,0 L 20,50 L 70,100 L 80,90 L 40,50 L 80,10 Z"/></svg>',
		right: '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M 30,0 L 80,50 L 30,100 L 20,90 L 60,50 L 20,10 Z"/></svg>',
		up: '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M 0,70 L 50,20 L 100,70 L 90,80 L 50,40 L 10,80 Z"/></svg>',
		down: '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M 0,30 L 50,80 L 100,30 L 90,20 L 50,60 L 10,20 Z"/></svg>',
		thinLeft: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5l-7 7 7 7"/></svg>',
		thinRight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 5l7 7-7 7"/></svg>'
	};

	var galleryMode = galleryBody.classList.contains( 'oc-gallery-thumbs-side' ) ? 'side' :
		galleryBody.classList.contains( 'oc-gallery-thumbs-under' ) ? 'under' :
		galleryBody.classList.contains( 'oc-gallery-stacked' ) ? 'stacked' : null;

	// Rebuildable: a colour-gallery swap replaces the slides and calls
	// buildGalleryRail() again, so everything derives from the live DOM and
	// window-level listeners attach once, reading the current rail/slides.
	var gSlides = [];
	var gRail = null;
	var gRailLastScrolled = -1;
	var gRailLock = 0;

	function setActiveThumb( index ) {
		if ( ! gRail ) {
			return;
		}
		gRail.querySelectorAll( 'button' ).forEach( function ( other, j ) {
			other.setAttribute( 'aria-current', j === index ? 'true' : 'false' );
		} );

		// Keep the active thumb in view: paging the main image scrolls the
		// rail along with it, in every rail flavour. Deduplicated and
		// lockable — the stacked preset's scroll tracker passes through
		// every intermediate slide during a smooth page glide, and syncing
		// the rail on each one made it bob up and down.
		if ( index !== gRailLastScrolled && Date.now() >= gRailLock ) {
			gRailLastScrolled = index;
			var li = gRail.children[ index ];
			if ( li && li.scrollIntoView ) {
				li.scrollIntoView( { block: 'nearest', inline: 'nearest', behavior: 'smooth' } );
			}
		}

		if ( ocPauseManualVideo ) {
			ocPauseManualVideo( gSlides[ index ] );
		}
	}

	function activateSlide( index ) {
		if ( 'stacked' === galleryMode ) {
			gSlides[ index ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
			// One rail sync now, then hold it still while the page glides —
			// the scroll tracker would drag it through every slide en route.
			setActiveThumb( index );
			gRailLock = Date.now() + 900;
			return;
		}

		gSlides.forEach( function ( other ) {
			other.classList.remove( 'is-active' );
		} );
		gSlides[ index ].classList.add( 'is-active' );
		setActiveThumb( index );
	}

	function sizeRail() {
		if ( ! gRail ) {
			return;
		}
		var maxThumbs = parseInt( galleryBody.dataset.ocThumbsMax || '10', 10 );
		var vertical = 'side' === galleryMode || 'stacked' === galleryMode;
		var first = gRail.querySelector( 'li' );
		if ( ! first || gSlides.length <= maxThumbs ) {
			return;
		}
		var step = ( vertical ? first.offsetHeight : first.offsetWidth ) + 10;
		if ( vertical ) {
			gRail.style.maxBlockSize = ( step * maxThumbs - 10 ) + 'px';
		} else {
			gRail.style.maxInlineSize = ( step * maxThumbs - 10 ) + 'px';
		}
		gRail.classList.add( 'is-capped' );
	}

	function buildGalleryRail() {
		if ( ! galleryWrap || ! galleryMode ) {
			return;
		}

		var parent = galleryWrap.parentElement;
		parent.querySelectorAll( '.oc-thumbscol, .oc-gnav--desktop' ).forEach( function ( node ) {
			node.remove();
		} );
		galleryWrap.querySelectorAll( '.oc-enav' ).forEach( function ( node ) {
			node.remove();
		} );
		gRail = null;

		gSlides = Array.prototype.slice.call(
			galleryWrap.querySelectorAll( '.woocommerce-product-gallery__image' )
		);

		if ( gSlides.length < 2 ) {
			return;
		}

		var maxThumbs = parseInt( galleryBody.dataset.ocThumbsMax || '10', 10 );
		var vertical = 'side' === galleryMode || 'stacked' === galleryMode;

		var railCol = document.createElement( 'div' );
		railCol.className = 'oc-thumbscol';

		var rail = document.createElement( 'ol' );
		rail.className = 'oc-thumbs';
		railCol.appendChild( rail );
		gRail = rail;

		if ( 'stacked' !== galleryMode ) {
			gSlides.forEach( function ( other ) {
				other.classList.remove( 'is-active' );
			} );
			gSlides[ 0 ].classList.add( 'is-active' );
		}

		gSlides.forEach( function ( slide, i ) {
			// A video slide shows a frozen first frame in the rail (a muted
			// metadata-only video element); embeds fall back to their poster.
			var src = slide.querySelector( 'img' );
			var thumbSrc = slide.dataset.thumb || ( src && ( src.currentSrc || src.src ) );

			if ( ! thumbSrc && ! slide.dataset.vsrc ) {
				return;
			}

			var li = document.createElement( 'li' );
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.setAttribute( 'aria-current', 0 === i ? 'true' : 'false' );

			var thumb;
			if ( slide.dataset.vsrc ) {
				thumb = document.createElement( 'video' );
				thumb.src = slide.dataset.vsrc + '#t=0.001';
				thumb.muted = true;
				thumb.playsInline = true;
				thumb.preload = 'metadata';
			} else {
				thumb = document.createElement( 'img' );
				thumb.src = thumbSrc;
				thumb.alt = '';
				thumb.loading = 'lazy';
			}

			btn.appendChild( thumb );

			// The video's rail thumb always wears a small play badge — the
			// shopper should know it is a video before choosing it —
			// whatever the autoplay setting does on the big one.
			if ( slide.classList.contains( 'oc-vslide' ) ) {
				var vbadge = document.createElement( 'span' );
				vbadge.className = 'oc-vplay oc-vplay--xs';
				vbadge.setAttribute( 'aria-hidden', 'true' );
				btn.appendChild( vbadge );
			}
			li.appendChild( btn );
			rail.appendChild( li );

			btn.addEventListener( 'click', function () {
				activateSlide( i );
			} );
		} );

		parent.appendChild( railCol );

		// Cap the visible thumbs; arrows page the rail when there are more.
		// In the under preset they are small white circles straddling the
		// THUMBS strip's edges; the other presets keep their bare arrows.
		if ( gSlides.length > maxThumbs ) {
			if ( 'under' === galleryMode ) {
				var railBox = document.createElement( 'span' );
				railBox.className = 'oc-railbox';
				railCol.appendChild( railBox );
				railBox.appendChild( rail );

				[ [ 'prev', 'thinLeft', -1 ], [ 'next', 'thinRight', 1 ] ].forEach( function ( def ) {
					var b = document.createElement( 'button' );
					b.type = 'button';
					b.className = 'oc-tnav oc-tnav--' + def[ 0 ];
					b.setAttribute( 'aria-label', def[ 0 ] );
					b.innerHTML = railChevrons[ def[ 1 ] ];
					b.addEventListener( 'click', function () {
						var first = rail.querySelector( 'li' );
						var step = ( first.offsetWidth + 10 ) * def[ 2 ];
						var rtl = 'rtl' === getComputedStyle( rail ).direction;
						rail.scrollLeft += rtl ? -step : step;
					} );
					railBox.appendChild( b );
				} );
			} else {
				var nav = document.createElement( 'div' );
				nav.className = 'oc-thumbnav' + ( vertical ? ' oc-thumbnav--v' : '' );

				[ [ 'prev', vertical ? 'up' : 'right', -1 ], [ 'next', vertical ? 'down' : 'left', 1 ] ].forEach( function ( def ) {
					var b = document.createElement( 'button' );
					b.type = 'button';
					b.className = 'oc-thumbnav__btn oc-thumbnav__' + def[ 0 ];
					b.setAttribute( 'aria-label', def[ 0 ] );
					b.innerHTML = railChevrons[ def[ 1 ] ];
					b.addEventListener( 'click', function () {
						var first = rail.querySelector( 'li' );
						var step = ( ( vertical ? first.offsetHeight : first.offsetWidth ) + 10 ) * def[ 2 ];
						if ( vertical ) {
							rail.scrollTop += step;
						} else {
							var rtl = 'rtl' === getComputedStyle( rail ).direction;
							rail.scrollLeft += rtl ? -step : step;
						}
					} );
					nav.appendChild( b );
				} );

				railCol.appendChild( nav );
			}
			sizeRail();
		}

		// Desktop arrows on the main image, for the thumbs presets.
		if ( 'stacked' !== galleryMode && galleryBody.classList.contains( 'oc-gdesk-arrows' ) ) {
			[ [ 'prev', 'right', -1 ], [ 'next', 'left', 1 ] ].forEach( function ( def ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'oc-gnav oc-gnav--desktop oc-gnav--' + def[ 0 ];
				b.setAttribute( 'aria-label', def[ 0 ] );
				b.innerHTML = 'prev' === def[ 0 ] ? railChevrons.left : railChevrons.right;
				b.addEventListener( 'click', function () {
					var currentSlide = 0;
					rail.querySelectorAll( 'button' ).forEach( function ( other, j ) {
						if ( 'true' === other.getAttribute( 'aria-current' ) ) {
							currentSlide = j;
						}
					} );
					activateSlide( ( currentSlide + def[ 2 ] + gSlides.length ) % gSlides.length );
				} );
				parent.appendChild( b );
			} );
		}
	}

	if ( galleryWrap && galleryMode ) {
		buildGalleryRail();
		window.addEventListener( 'load', sizeRail );

		// Stacked: highlight the thumb of the image currently in view.
		if ( 'stacked' === galleryMode ) {
			window.addEventListener( 'scroll', function () {
				for ( var i = 0; i < gSlides.length; i++ ) {
					var r = gSlides[ i ].getBoundingClientRect();
					if ( r.bottom > window.innerHeight * 0.35 ) {
						setActiveThumb( i );
						return;
					}
				}
			}, { passive: true } );
		}
	}

	/* ---------- category description: clamp with read-more ---------- */

	document.querySelectorAll( '.term-description, .oc-archive-desc' ).forEach( function ( box ) {
		// Clamp to two lines, then keep the clamp only if something was
		// actually cut off.
		box.classList.add( 'oc-clamped' );
		if ( box.scrollHeight <= box.clientHeight + 4 ) {
			box.classList.remove( 'oc-clamped' );
			return;
		}

		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'oc-readmore';
		toggle.textContent = ( window.ocL10n && window.ocL10n.readMore ) || 'Read more';
		box.after( toggle );

		toggle.addEventListener( 'click', function () {
			var open = box.classList.toggle( 'is-open' );
			toggle.textContent = open
				? ( ( window.ocL10n && window.ocL10n.readLess ) || 'Read less' )
				: ( ( window.ocL10n && window.ocL10n.readMore ) || 'Read more' );
		} );
	} );

	/* ---------- mobile gallery: swipe strip with dots / optional arrows ---------- */

	var mgWrap = galleryWrap;
	var mgDots = null;

	function mgIndex() {
		return ! mgWrap || mgWrap.clientWidth === 0 ? 0 :
			Math.round( Math.abs( mgWrap.scrollLeft ) / mgWrap.clientWidth );
	}

	function mgUpdateDots( idx ) {
		if ( ! mgDots ) {
			return;
		}
		mgDots.querySelectorAll( 'button' ).forEach( function ( b, i ) {
			b.setAttribute( 'aria-current', i === idx ? 'true' : 'false' );
		} );

		if ( ocPauseManualVideo && mgWrap ) {
			ocPauseManualVideo( mgWrap.querySelectorAll( '.woocommerce-product-gallery__image' )[ idx ] );
		}
	}

	function mgGoTo( index ) {
		var rtl = getComputedStyle( mgWrap ).direction === 'rtl';
		mgWrap.scrollLeft = ( rtl ? -1 : 1 ) * index * mgWrap.clientWidth;
		// Direct update as well — scroll events lag (or never fire in
		// frozen pipelines) on programmatic scrolls.
		mgUpdateDots( index );
	}

	function buildMobileDots() {
		if ( ! mgWrap || ! document.body.classList.contains( 'oc-gm-dots' ) ) {
			return;
		}

		var mgGallery = mgWrap.parentElement;
		mgGallery.querySelectorAll( '.oc-gdots, .oc-gnav:not(.oc-gnav--desktop)' ).forEach( function ( node ) {
			node.remove();
		} );
		mgDots = null;

		var mgSlides = mgWrap.querySelectorAll( '.woocommerce-product-gallery__image' );
		var mgCount = mgSlides.length;

		if ( mgCount < 2 ) {
			return;
		}

		mgDots = document.createElement( 'ol' );
		mgDots.className = 'oc-gdots';

		mgSlides.forEach( function ( _, i ) {
			var li = document.createElement( 'li' );
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.setAttribute( 'aria-label', String( i + 1 ) );
			b.setAttribute( 'aria-current', 0 === i ? 'true' : 'false' );
			b.addEventListener( 'click', function () {
				mgGoTo( i );
			} );
			li.appendChild( b );
			mgDots.appendChild( li );
		} );

		mgGallery.appendChild( mgDots );

		if ( document.body.classList.contains( 'oc-gm-arrows' ) ) {
			var mgLeft = '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M 70,0 L 20,50 L 70,100 L 80,90 L 40,50 L 80,10 Z"/></svg>';
			var mgRight = '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M 30,0 L 80,50 L 30,100 L 20,90 L 60,50 L 20,10 Z"/></svg>';

			[ [ 'prev', mgLeft, -1 ], [ 'next', mgRight, 1 ] ].forEach( function ( def ) {
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'oc-gnav oc-gnav--' + def[ 0 ];
				btn.setAttribute( 'aria-label', def[ 0 ] );
				btn.innerHTML = def[ 1 ];
				btn.addEventListener( 'click', function () {
					mgGoTo( ( mgIndex() + def[ 2 ] + mgCount ) % mgCount );
				} );
				mgGallery.appendChild( btn );
			} );
		}
	}

	if ( mgWrap && document.body.classList.contains( 'oc-gm-dots' ) ) {
		buildMobileDots();

		mgWrap.addEventListener( 'scroll', function () {
			mgUpdateDots( mgIndex() );
		}, { passive: true } );
	}

	/* ---------- colour galleries: a swatch click swaps the whole gallery ----------
	 * The map is printed server-side as ready-made slide markup, so the swap is
	 * instant and request-free. Rail, dots and sticky columns rebuild after. */

	var ocColorGalleries = null;
	var ocGalleryOriginal = galleryWrap ? galleryWrap.innerHTML : '';
	var ocGalleriesTag = document.getElementById( 'oc-color-galleries' );

	if ( ocGalleriesTag ) {
		try {
			ocColorGalleries = JSON.parse( ocGalleriesTag.textContent );
		} catch ( err ) {
			ocColorGalleries = null;
		}
	}

	// Assigned by the video module: re-inserts the gallery video slide after
	// a colour-gallery swap replaces the slides.
	var ocReinsertVideo = null;

	// Assigned by the video module: paging away from a manually-started
	// video pauses it and hands the play badge back.
	var ocPauseManualVideo = null;

	function ocSwapGallery( slidesHtml ) {
		if ( ! galleryWrap ) {
			return;
		}
		galleryWrap.innerHTML = slidesHtml ? slidesHtml.join( '' ) : ocGalleryOriginal;
		galleryWrap.scrollLeft = 0;
		if ( ocReinsertVideo ) {
			ocReinsertVideo();
		}
		buildGalleryRail();
		buildMobileDots();
		window.dispatchEvent( new Event( 'resize' ) );
	}

	function ocMaybeSwapGallery( value ) {
		if ( ! ocColorGalleries ) {
			return;
		}
		if ( value && ocColorGalleries[ value ] ) {
			ocSwapGallery( ocColorGalleries[ value ] );
		} else if ( ! value ) {
			ocSwapGallery( null );
		}
	}

	/* ---------- quantity pill: minus / value / plus ---------- */

	document.querySelectorAll( 'form.cart div.quantity' ).forEach( function ( box ) {
		var input = box.querySelector( 'input.qty, input[name="quantity"]' );

		// Max-one products get a HIDDEN quantity input from Woo — no pill,
		// no phantom box squeezing the button off its line.
		if ( ! input || 'hidden' === input.type ) {
			if ( input && 'hidden' === input.type ) {
				box.classList.add( 'oc-qty-hidden' );
			}
			return;
		}

		if ( box.querySelector( '.oc-qty-btn' ) ) {
			return;
		}

		var step = parseFloat( input.step ) || 1;

		var mk = function ( label, dir ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'oc-qty-btn oc-qty-btn--' + ( dir > 0 ? 'plus' : 'minus' );
			b.setAttribute( 'aria-label', label );
			b.textContent = dir > 0 ? '+' : '−';
			b.addEventListener( 'click', function () {
				var val = parseFloat( input.value ) || 0;
				var min = parseFloat( input.min );
				var max = parseFloat( input.max );
				val += dir * step;
				if ( ! isNaN( min ) && val < min ) {
					val = min;
				}
				if ( ! isNaN( max ) && max > 0 && val > max ) {
					val = max;
				}
				input.value = val;
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
			return b;
		};

		box.insertBefore( mk( 'minus', -1 ), input );
		box.appendChild( mk( 'plus', 1 ) );
	} );

	/* ---------- back-in-stock popup ----------
	 * One popup for every trigger — product page and catalogue cards. The
	 * details only appear after the click; phone (WhatsApp) or email, one
	 * of the two is enough. */

	var ocNotifyModal = null;
	var ocNotifyVarsCache = {};
	var ocNotifyTimer = null;

	function ocCloseNotify() {
		if ( ocNotifyTimer ) {
			clearInterval( ocNotifyTimer );
			ocNotifyTimer = null;
		}
		if ( ocNotifyModal ) {
			ocNotifyModal.hidden = true;
		}
	}

	/* Which signups this browser already made — powers the "you are signed
	 * up" button state and the marked options in the picker. */
	function ocSignedMap() {
		try {
			return JSON.parse( localStorage.getItem( 'ocNotifySigned' ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function ocSaveSignedMap( map ) {
		try {
			localStorage.setItem( 'ocNotifySigned', JSON.stringify( map ) );
		} catch ( e ) {}
	}

	// Entries store the server-issued signup key so the shopper can remove
	// themselves later; records from before that existed are dropped.
	( function () {
		var map = ocSignedMap();
		var changed = false;
		Object.keys( map ).forEach( function ( k ) {
			if ( 'object' !== typeof map[ k ] ) {
				delete map[ k ];
				changed = true;
			}
		} );
		if ( changed ) {
			ocSaveSignedMap( map );
		}
	} )();

	function ocMarkSigned( productId, variationId, key ) {
		var map = ocSignedMap();
		map[ productId + '|' + ( variationId || 0 ) ] = { k: key || '' };
		ocSaveSignedMap( map );
	}

	function ocUnmarkProduct( productId ) {
		var map = ocSignedMap();
		Object.keys( map ).forEach( function ( k ) {
			if ( 0 === k.indexOf( productId + '|' ) ) {
				delete map[ k ];
			}
		} );
		ocSaveSignedMap( map );
	}

	function ocSignedCount( productId ) {
		var map = ocSignedMap();
		var count = 0;
		Object.keys( map ).forEach( function ( k ) {
			if ( 0 === k.indexOf( productId + '|' ) ) {
				count++;
			}
		} );
		return count;
	}

	/* The variation list arrives lazily, only when a variable product's
	 * trigger is clicked — catalogue cards stay cheap. */
	function ocFetchNotifyVars( productId ) {
		var L = window.ocL10n || {};

		if ( ocNotifyVarsCache[ productId ] ) {
			return Promise.resolve( ocNotifyVarsCache[ productId ] );
		}

		return fetch( ( L.ajaxUrl || '/wp-admin/admin-ajax.php' ) + '?action=oc_notify_vars&product=' + encodeURIComponent( productId ) )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( res && res.success && res.data.length ) {
					ocNotifyVarsCache[ productId ] = res.data;
					return res.data;
				}
				return null;
			} )
			.catch( function () {
				return null;
			} );
	}

	function ocLoadNotifyVars( productId, box, selected ) {
		var L = window.ocL10n || {};

		ocFetchNotifyVars( productId ).then( function ( list ) {
			if ( ! list ) {
				return;
			}

			var map = ocSignedMap();
			var select = document.createElement( 'select' );
			select.name = 'variation';

			var first = document.createElement( 'option' );
			first.value = '';
			first.textContent = L.notifyVarPick || 'Choose the variation';
			select.appendChild( first );

			list.forEach( function ( v ) {
				var opt = document.createElement( 'option' );
				opt.value = v.id;
				opt.textContent = map[ productId + '|' + v.id ]
					? v.label + ' — ' + ( L.notifySignedOpt || 'signed up' )
					: v.label;
				opt.disabled = !! map[ productId + '|' + v.id ];
				select.appendChild( opt );
			} );

			box.innerHTML = '';
			box.appendChild( select );
			box.hidden = false;

			// The variation the shopper picked on the page arrives pre-chosen;
			// a single-variation product needs no choosing at all.
			if ( selected && ! map[ productId + '|' + selected ] &&
				select.querySelector( 'option[value="' + selected + '"]' ) ) {
				select.value = String( selected );
			} else if ( 1 === list.length && ! map[ productId + '|' + list[ 0 ].id ] ) {
				select.value = String( list[ 0 ].id );
			}
		} );
	}

	/* A trigger whose signup already happened tells the shopper so — and stays
	 * clickable: it opens the popup's signed view (details, another variation,
	 * self-removal). */
	function ocSwapSignedTrigger( trigger ) {
		var L = window.ocL10n || {};
		trigger.textContent = '✓ ' + ( L.notifySigned || 'Signed up for updates' );
		trigger.classList.add( 'oc-signed' );
	}

	function ocUnswapTriggers( productId ) {
		var L = window.ocL10n || {};
		document.querySelectorAll( '.oc-notify-open[data-product="' + productId + '"]' ).forEach( function ( trigger ) {
			trigger.classList.remove( 'oc-signed' );
			trigger.disabled = false;
			trigger.textContent = L.notifyButton || 'Notify me when it is back';
			var hint = trigger.parentNode.querySelector( '.oc-oos__hint' );
			if ( hint ) {
				hint.remove();
			}
		} );
	}

	function ocRefreshSigned() {
		var map = ocSignedMap();

		document.querySelectorAll( '.oc-notify-open' ).forEach( function ( trigger ) {
			if ( trigger.classList.contains( 'oc-signed' ) ) {
				return;
			}

			var pid = trigger.dataset.product;

			if ( '1' !== trigger.dataset.variable ) {
				if ( map[ pid + '|0' ] ) {
					ocSwapSignedTrigger( trigger );
				}
				return;
			}

			// Variable: with every variation signed the button flips — on the
			// product page and on catalogue cards alike; a partial signup
			// hints below the product-page button only. Only products this
			// browser signed up for ever trigger the (cached) lookup.
			if ( ocSignedCount( pid ) > 0 ) {
				ocFetchNotifyVars( pid ).then( function ( list ) {
					if ( ! list ) {
						return;
					}
					var m = ocSignedMap();
					var all = list.every( function ( v ) {
						return m[ pid + '|' + v.id ];
					} );

					if ( all ) {
						ocSwapSignedTrigger( trigger );
						return;
					}
					if ( trigger.classList.contains( 'oc-oos__notify' ) &&
						! trigger.parentNode.querySelector( '.oc-oos__hint' ) ) {
						var hint = document.createElement( 'span' );
						hint.className = 'oc-oos__hint';
						hint.textContent = '✓ ' + ( ( window.ocL10n || {} ).notifySignedSome || '' );
						trigger.insertAdjacentElement( 'afterend', hint );
					}
				} );
			}
		} );
	}

	function ocOpenNotify( productId, productName, isVariable, selectedVar ) {
		var L = window.ocL10n || {};

		if ( ! ocNotifyModal ) {
			var channel = L.notifyChannel || 'both';
			var consentText = ( L.notifyConsentPre || 'I agree to the ' ) +
				( L.privacyUrl
					? '<a href="' + L.privacyUrl + '" target="_blank" rel="noopener">' + ( L.notifyConsentLink || 'privacy policy' ) + '</a>'
					: ( L.notifyConsentLink || 'privacy policy' ) );

			ocNotifyModal = document.createElement( 'div' );
			ocNotifyModal.className = 'oc-nmodal';
			ocNotifyModal.hidden = true;
			ocNotifyModal.innerHTML =
				'<div class="oc-nmodal__card">' +
				'<button type="button" class="oc-nmodal__close" aria-label="close">&times;</button>' +
				'<h3 class="oc-nmodal__title">' +
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 9a6 6 0 1 0-12 0c0 6-2.5 7-2.5 7h17S18 15 18 9z"/><path d="M10 19.5a2.2 2.2 0 0 0 4 0"/></svg>' +
				( L.notifyTitle || 'Notify me' ) + '</h3>' +
				'<p class="oc-nmodal__intro">' + ( L.notifyIntro || '' ) + '</p>' +
				'<p class="oc-nmodal__product"></p>' +
				'<form class="oc-nmodal__form">' +
				'<div class="oc-nmodal__vars" hidden></div>' +
				( 'email' === channel ? '' : '<input type="tel" name="phone" placeholder="' + ( L.notifyPhone || 'Phone' ) + '" />' ) +
				( 'whatsapp' === channel ? '' : '<input type="email" name="email" placeholder="' + ( L.notifyEmail || 'Email' ) + '" />' ) +
				'<label class="oc-nmodal__consent"><input type="checkbox" name="consent" /><span>' + consentText + '</span></label>' +
				'<p class="oc-nmodal__error" hidden>' + ( L.notifyMissing || '' ) + '</p>' +
				'<button type="submit">' + ( L.notifyButton || 'Notify me' ) + '</button>' +
				'</form>' +
				'<p class="oc-nmodal__foot">' + ( L.notifyFoot || '' ) + '</p>' +
				'<div class="oc-nmodal__success" hidden>' +
				'<svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="21" fill="none" stroke="currentColor" stroke-width="3"/><path d="M15 24.5l6.5 6.5L33 18.5" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
				'<p class="oc-nmodal__done">' + ( L.notifyDone || '' ) + '</p>' +
				'<p class="oc-nmodal__count"></p>' +
				'</div>' +
				'<div class="oc-nmodal__signedview" hidden>' +
				'<svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="21" fill="none" stroke="currentColor" stroke-width="3"/><path d="M15 24.5l6.5 6.5L33 18.5" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
				'<p class="oc-nmodal__signedmsg">' + ( L.notifySignedMsg || '' ) + '</p>' +
				'<p class="oc-nmodal__signedvars"></p>' +
				'<button type="button" class="oc-nmodal__more" hidden>' + ( L.notifyMore || '' ) + '</button>' +
				'<button type="button" class="oc-nmodal__unsub">' + ( L.notifyUnsub || '' ) + '</button>' +
				( L.isLoggedIn && L.accountAlertsUrl
					? '<p class="oc-nmodal__manage"><a href="' + L.accountAlertsUrl + '">' + ( L.notifyManage || '' ) + '</a></p>'
					: '' ) +
				'</div>' +
				'</div>';
			document.body.appendChild( ocNotifyModal );

			ocNotifyModal.addEventListener( 'click', function ( event ) {
				if ( event.target === ocNotifyModal || event.target.closest( '.oc-nmodal__close' ) ) {
					ocCloseNotify();
				}
			} );

			document.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key && ! ocNotifyModal.hidden ) {
					ocCloseNotify();
				}
			} );

			ocNotifyModal.querySelector( '.oc-nmodal__form' ).addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var form = event.target;
				var phoneEl = form.querySelector( '[name="phone"]' );
				var emailEl = form.querySelector( '[name="email"]' );
				var varsEl = form.querySelector( '.oc-nmodal__vars select' );
				var phone = phoneEl ? phoneEl.value.trim() : '';
				var email = emailEl ? emailEl.value.trim() : '';
				var consent = form.querySelector( '[name="consent"]' );
				var error = ocNotifyModal.querySelector( '.oc-nmodal__error' );

				if ( varsEl && ! varsEl.value ) {
					error.textContent = L.notifyVarMissing || '';
					error.hidden = false;
					return;
				}
				if ( consent && ! consent.checked ) {
					error.textContent = L.notifyConsentMissing || '';
					error.hidden = false;
					return;
				}
				if ( ! phone && ! email ) {
					error.textContent = L.notifyMissing || '';
					error.hidden = false;
					return;
				}
				error.hidden = true;

				var data = new FormData();
				data.append( 'action', 'oc_notify' );
				data.append( 'nonce', L.notifyNonce || '' );
				data.append( 'product', ocNotifyModal.dataset.product );
				data.append( 'phone', phone );
				data.append( 'email', email );
				data.append( 'consent', '1' );
				if ( varsEl ) {
					data.append( 'variation', varsEl.value );
				}

				var submit = form.querySelector( 'button' );
				submit.disabled = true;

				fetch( L.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: data } )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						submit.disabled = false;
						if ( res && res.success ) {
							// The whole card becomes the confirmation: a big
							// check, the message, and a 5s self-close.
							ocNotifyModal.querySelector( '.oc-nmodal__done' ).textContent = L.notifyDone || '';
							ocNotifyModal.querySelector( '.oc-nmodal__title' ).hidden = true;
							ocNotifyModal.querySelector( '.oc-nmodal__intro' ).hidden = true;
							ocNotifyModal.querySelector( '.oc-nmodal__product' ).hidden = true;
							form.hidden = true;
							ocNotifyModal.querySelector( '.oc-nmodal__foot' ).hidden = true;
							ocNotifyModal.querySelector( '.oc-nmodal__success' ).hidden = false;

							var count = ocNotifyModal.querySelector( '.oc-nmodal__count' );
							var left = 5;
							var tick = function () {
								count.textContent = ( L.notifyClosing || 'Closes in %d' ).replace( '%d', left );
								if ( left <= 0 ) {
									ocCloseNotify();
									return;
								}
								left--;
							};
							tick();
							ocNotifyTimer = setInterval( tick, 1000 );

							// From now on the consent box opens pre-checked
							// for this browsing session.
							try {
								sessionStorage.setItem( 'ocNotifyOk', '1' );
							} catch ( e ) {}

							ocMarkSigned( ocNotifyModal.dataset.product, varsEl ? varsEl.value : 0, res.data && res.data.key ? res.data.key : '' );
							ocRefreshSigned();
						}
					} )
					.catch( function () {
						submit.disabled = false;
					} );
			} );

			// Signed view: another variation reopens the form; removal asks the
			// server to drop this browser's signups for the product.
			ocNotifyModal.querySelector( '.oc-nmodal__more' ).addEventListener( 'click', function () {
				ocOpenNotify( ocNotifyModal.dataset.product, ocNotifyModal.dataset.name || '', true, '' );
			} );

			ocNotifyModal.querySelector( '.oc-nmodal__unsub' ).addEventListener( 'click', function () {
				var unsub = this;
				var pid = ocNotifyModal.dataset.product;
				var map = ocSignedMap();
				var keys = [];

				Object.keys( map ).forEach( function ( k ) {
					if ( 0 === k.indexOf( pid + '|' ) && map[ k ] && map[ k ].k ) {
						keys.push( map[ k ].k );
					}
				} );

				unsub.disabled = true;

				var data = new FormData();
				data.append( 'action', 'oc_notify_remove' );
				data.append( 'nonce', L.notifyNonce || '' );
				data.append( 'product', pid );
				data.append( 'entries', JSON.stringify( keys ) );

				fetch( L.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: data } )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						unsub.disabled = false;
						if ( res && res.success ) {
							ocUnmarkProduct( pid );
							ocUnswapTriggers( pid );
							ocNotifyModal.querySelector( '.oc-nmodal__signedview' ).hidden = true;
							var done = ocNotifyModal.querySelector( '.oc-nmodal__success' );
							done.querySelector( '.oc-nmodal__done' ).textContent = L.notifyUnsubDone || '';
							done.querySelector( '.oc-nmodal__count' ).textContent = '';
							done.hidden = false;
							ocNotifyTimer = setInterval( ocCloseNotify, 1800 );
						}
					} )
					.catch( function () {
						unsub.disabled = false;
					} );
			} );
		}

		if ( ocNotifyTimer ) {
			clearInterval( ocNotifyTimer );
			ocNotifyTimer = null;
		}

		ocNotifyModal.dataset.product = productId;
		ocNotifyModal.dataset.name = productName || '';
		ocNotifyModal.querySelector( '.oc-nmodal__title' ).hidden = false;
		ocNotifyModal.querySelector( '.oc-nmodal__intro' ).hidden = false;
		ocNotifyModal.querySelector( '.oc-nmodal__product' ).hidden = false;
		ocNotifyModal.querySelector( '.oc-nmodal__product' ).textContent = productName || '';
		ocNotifyModal.querySelector( '.oc-nmodal__form' ).hidden = false;
		ocNotifyModal.querySelector( '.oc-nmodal__foot' ).hidden = false;
		ocNotifyModal.querySelector( '.oc-nmodal__success' ).hidden = true;
		ocNotifyModal.querySelector( '.oc-nmodal__signedview' ).hidden = true;
		ocNotifyModal.querySelector( '.oc-nmodal__error' ).hidden = true;

		var varsBox = ocNotifyModal.querySelector( '.oc-nmodal__vars' );
		varsBox.hidden = true;
		varsBox.innerHTML = '';
		if ( isVariable ) {
			ocLoadNotifyVars( productId, varsBox, selectedVar || '' );
		}

		// Unchecked only on the very first signup of the session.
		var consentBox = ocNotifyModal.querySelector( '[name="consent"]' );
		if ( consentBox ) {
			try {
				consentBox.checked = '1' === sessionStorage.getItem( 'ocNotifyOk' );
			} catch ( e ) {
				consentBox.checked = false;
			}
		}

		ocNotifyModal.hidden = false;
		var firstField = ocNotifyModal.querySelector( '.oc-nmodal__form input:not([type="checkbox"])' );
		if ( firstField ) {
			firstField.focus();
		}
	}

	/* The signed view: what this browser signed up for, a way out, and — for
	 * variable products with uncovered variations — a way to add one. */
	function ocOpenNotifySigned( productId, productName, isVariable ) {
		var L = window.ocL10n || {};

		ocOpenNotify( productId, productName, isVariable, '' );

		ocNotifyModal.querySelector( '.oc-nmodal__intro' ).hidden = true;
		ocNotifyModal.querySelector( '.oc-nmodal__form' ).hidden = true;
		ocNotifyModal.querySelector( '.oc-nmodal__foot' ).hidden = true;

		var view = ocNotifyModal.querySelector( '.oc-nmodal__signedview' );
		var vars = view.querySelector( '.oc-nmodal__signedvars' );
		var more = view.querySelector( '.oc-nmodal__more' );

		vars.textContent = '';
		more.hidden = true;
		view.querySelector( '.oc-nmodal__unsub' ).disabled = false;
		view.hidden = false;

		if ( isVariable ) {
			ocFetchNotifyVars( productId ).then( function ( list ) {
				if ( ! list ) {
					return;
				}
				var map = ocSignedMap();
				var mine = list.filter( function ( v ) {
					return map[ productId + '|' + v.id ];
				} );

				if ( mine.length ) {
					vars.textContent = ( L.notifySignedVars || '' ) + ' ' + mine.map( function ( v ) {
						return v.label;
					} ).join( ', ' );
				}
				more.hidden = mine.length >= list.length;
			} );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest( '.oc-notify-open' );
		if ( trigger ) {
			event.preventDefault();
			event.stopPropagation();
			if ( trigger.classList.contains( 'oc-signed' ) ) {
				ocOpenNotifySigned( trigger.dataset.product, trigger.dataset.name, '1' === trigger.dataset.variable );
			} else {
				ocOpenNotify( trigger.dataset.product, trigger.dataset.name, '1' === trigger.dataset.variable, trigger.dataset.selected || '' );
			}
		}
	} );

	ocRefreshSigned();

	/* ---------- partial sold-out: watch the variation picker ----------
	 * An in-stock variable product carries a hidden sold-out block; the
	 * moment the shopper lands on an out-of-stock variation it surfaces,
	 * remembers that variation for the popup, and swaps out Woo's dead
	 * add-to-cart row. */

	( function () {
		var blocks = document.querySelectorAll( '.oc-oos' );
		var vForm = document.querySelector( 'form.variations_form' );

		if ( ! blocks.length || ! vForm ) {
			return;
		}

		var watch = document.querySelector( '.oc-oos--watch' );

		var applyWatch = function () {
			var idInput = vForm.querySelector( 'input[name="variation_id"]' );
			var vid = idInput ? String( idInput.value || '' ) : '';
			var valid = '' !== vid && '0' !== vid;

			// On a fully sold-out product any resolved variation is the one
			// the shopper means — the popup opens with it chosen.
			blocks.forEach( function ( block ) {
				if ( block === watch ) {
					return;
				}
				var btn = block.querySelector( '.oc-notify-open' );
				if ( btn ) {
					btn.dataset.selected = valid ? vid : '';
				}
			} );

			if ( watch ) {
				var oos = !! vForm.querySelector( '.single_variation .stock.out-of-stock, .woocommerce-variation-availability .out-of-stock' );
				var show = valid && oos;

				watch.hidden = ! show;
				vForm.classList.toggle( 'oc-var-oos', show );

				var wbtn = watch.querySelector( '.oc-notify-open' );
				if ( wbtn ) {
					wbtn.dataset.selected = show ? vid : '';
				}
			}
		};

		new MutationObserver( applyWatch ).observe( vForm, {
			subtree: true,
			childList: true,
			attributes: true,
			attributeFilter: [ 'class', 'style' ],
		} );
		applyWatch();
	} )();

	/* ---------- price-line SKU follows the chosen variation ----------
	 * Woo already resolves a variation's empty SKU to the parent's, so the
	 * inline JSON's sku field is always the right answer; no selection means
	 * back to the parent SKU, and an empty value hides the span entirely. */

	( function () {
		var sku = document.querySelector( '.summary .oc-sku' );
		var vForm = document.querySelector( 'form.variations_form' );

		if ( ! sku || ! vForm ) {
			return;
		}

		var val = sku.querySelector( '.oc-sku__v' );
		var parentSku = val ? val.textContent : '';
		var list = null;

		var variations = function () {
			if ( null === list ) {
				try {
					list = JSON.parse( vForm.dataset.product_variations || 'false' ) || [];
				} catch ( e ) {
					list = [];
				}
			}
			return list;
		};

		var applySku = function () {
			var idInput = vForm.querySelector( 'input[name="variation_id"]' );
			var vid = idInput ? parseInt( idInput.value, 10 ) || 0 : 0;
			var shown = parentSku;

			if ( vid ) {
				variations().some( function ( v ) {
					if ( Number( v.variation_id ) === vid ) {
						shown = v.sku || parentSku;
						return true;
					}
					return false;
				} );
			}

			if ( val ) {
				val.textContent = shown;
			}
			sku.hidden = '' === String( shown || '' );
		};

		new MutationObserver( applySku ).observe( vForm, {
			subtree: true,
			childList: true,
			attributes: true,
			attributeFilter: [ 'class', 'style' ],
		} );
		vForm.addEventListener( 'change', function () {
			setTimeout( applySku, 80 );
		} );
		applySku();
	} )();

	/* ---------- product meta chips: the +N pill reveals the rest ---------- */

	document.querySelectorAll( '.oc-pmeta__more' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var wrap = btn.closest( '.oc-pmeta' );
			if ( wrap ) {
				wrap.querySelectorAll( '.oc-pmeta__chip[hidden]' ).forEach( function ( chip ) {
					chip.hidden = false;
				} );
			}
			btn.hidden = true;
		} );
	} );

	/* ---------- add-to-cart: a loader takes the label until the add lands ---------- */

	document.querySelectorAll( 'form.cart' ).forEach( function ( cartForm ) {
		cartForm.addEventListener( 'submit', function () {
			var btn = cartForm.querySelector( '.single_add_to_cart_button' );
			if ( btn && ! btn.classList.contains( 'disabled' ) ) {
				btn.classList.add( 'is-loading' );
			}
		} );
	} );

	/* ---------- lazy card videos ----------
	 * Catalogue videos carry data-oc-vsrc and no source: each one loads and
	 * plays only as its card nears the viewport, and pauses off screen — a
	 * catalogue full of videos costs nothing up front. */

	var ocVideoObserver = null;

	function ocLazyVideos( root ) {
		var targets = ( root || document ).querySelectorAll( '[data-oc-vsrc]' );

		if ( ! targets.length ) {
			return;
		}

		if ( ! ( 'IntersectionObserver' in window ) ) {
			targets.forEach( function ( el ) {
				el.src = el.dataset.ocVsrc;
			} );
			return;
		}

		if ( ! ocVideoObserver ) {
			ocVideoObserver = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					var el = entry.target;
					if ( entry.isIntersecting ) {
						if ( ! el.dataset.ocLoaded ) {
							// The #t fragment seeks to the first frame, so a
							// phone that refuses autoplay (low power mode)
							// still paints the video instead of a blank box.
							el.src = 'VIDEO' === el.tagName ? el.dataset.ocVsrc + '#t=0.001' : el.dataset.ocVsrc;
							if ( 'VIDEO' === el.tagName ) {
								el.preload = 'metadata';
							}
							el.dataset.ocLoaded = '1';
						}
						if ( el.play ) {
							el.play().catch( function () {} );
						}
					} else if ( el.pause ) {
						el.pause();
					}
				} );
			}, { rootMargin: '200px' } );
		}

		targets.forEach( function ( el ) {
			ocVideoObserver.observe( el );
		} );
	}

	ocLazyVideos( document );

	// Insurance: if the observer never fires (frozen pipelines, odd
	// embedded browsers), anything already on screen loads after a beat.
	setTimeout( function () {
		document.querySelectorAll( '[data-oc-vsrc]' ).forEach( function ( el ) {
			if ( el.dataset.ocLoaded ) {
				return;
			}
			var rect = el.getBoundingClientRect();
			if ( rect.width && rect.top < window.innerHeight + 200 && rect.bottom > -200 ) {
				el.src = 'VIDEO' === el.tagName ? el.dataset.ocVsrc + '#t=0.001' : el.dataset.ocVsrc;
				if ( 'VIDEO' === el.tagName ) {
					el.preload = 'metadata';
				}
				el.dataset.ocLoaded = '1';
				if ( el.play ) {
					el.play().catch( function () {} );
				}
			}
		} );
	}, 2500 );

	/* ---------- product video + the ONE lightbox ----------
	 * A single white lightbox serves the whole gallery — images and the
	 * product video alike — in the reference style: bottom-centre circle
	 * buttons (prev, close, next), a counter, a soft open animation. */

	var ocVideoTag = document.getElementById( 'oc-video' );
	var ocVideo = null;

	if ( ocVideoTag ) {
		try {
			ocVideo = JSON.parse( ocVideoTag.textContent );
		} catch ( err ) {
			ocVideo = null;
		}
	}

	if ( galleryWrap ) {
		var ocOverlay = null;
		var ocOverlayItems = [];
		var ocOverlayIndex = 0;
		var ocLbEase = 'cubic-bezier(.2,.7,.2,1)';

		// Assigned by the video section below.
		var ocAttachVideoChips = null;
		var ocTrySoundOn = function () {};
		var ocResetPageVideos = function () {};
		var ocResumePageVideos = function () {};

		// No native controls anywhere — the theme's own chips (pause at the
		// right, sound at the left) are the video UI.
		var ocVideoFullHtml = function () {
			return 'file' === ocVideo.kind
				? '<div class="oc-vwrap"><video src="' + ocVideo.fullSrc + '" autoplay loop playsinline></video></div>'
				: '<iframe src="' + ocVideo.fullSrc + '" allow="autoplay; fullscreen" title="video"></iframe>';
		};

		var ocOverlayList = function () {
			var items = [];
			galleryWrap.querySelectorAll( '.woocommerce-product-gallery__image' ).forEach( function ( slide ) {
				if ( slide.classList.contains( 'oc-vslide' ) ) {
					if ( ocVideo ) {
						items.push( { type: 'video', slide: slide } );
					}
					return;
				}
				var link = slide.querySelector( 'a' );
				var img = slide.querySelector( 'img' );
				var preview = img ? ( img.currentSrc || img.src ) : '';
				var src = ( link && link.href ) || preview;
				if ( src ) {
					items.push( { type: 'image', src: src, preview: preview || src, slide: slide } );
				}
			} );

			return items;
		};

		var ocRenderOverlay = function () {
			var item = ocOverlayItems[ ocOverlayIndex ];

			if ( ! item ) {
				return;
			}

			var media = ocOverlay.querySelector( '.oc-voverlay__media' );

			if ( 'video' === item.type ) {
				media.innerHTML = ocVideoFullHtml();
				var lbVid = media.querySelector( 'video' );
				if ( lbVid && ocAttachVideoChips ) {
					ocAttachVideoChips( media.querySelector( '.oc-vwrap' ), lbVid );
					ocTrySoundOn( lbVid );
				}
			} else {
				// The preview paints instantly with the right geometry; the
				// full-size file replaces it in place once it has loaded —
				// with the rendered box frozen first, so nothing jumps.
				media.innerHTML = '<img src="' + item.preview + '" alt="" />';
				if ( item.src !== item.preview ) {
					var full = new Image();
					var target = media.querySelector( 'img' );
					full.onload = function () {
						if ( target.isConnected ) {
							// Layout size, NOT getBoundingClientRect — the
							// rect is measured AFTER the zoom transform, and
							// freezing that ballooned the image 1.9x the
							// moment the zoom settled.
							if ( target.offsetWidth ) {
								target.style.width = target.offsetWidth + 'px';
								target.style.height = target.offsetHeight + 'px';
							}
							target.src = item.src;
						}
					};
					full.src = item.src;
				}
			}

			var zoomTarget = media.querySelector( 'img' );
			if ( zoomTarget ) {
				ocBindZoomPan( zoomTarget );
			}

			ocOverlay.classList.toggle( 'has-nav', ocOverlayItems.length > 1 );
			ocOverlay.querySelector( '.oc-voverlay__count' ).textContent =
				ocOverlayItems.length > 1 ? ( ocOverlayIndex + 1 ) + ' / ' + ocOverlayItems.length : '';
		};

		var ocPanHandler = null;

		// In-lightbox zoom, rebuilt for silk: origin stays centred and the
		// zoom travels via translate+scale — so zooming in, out, and
		// re-zooming elsewhere all glide (a moving transform-origin snapped).
		// While zoomed: on desktop the image pans by following the MOUSE;
		// on touch it pans by drag, clamped to its own edges.
		var ocBindZoomPan = function ( img ) {
			var SCALE = 1.9;
			var zoomed = false;
			var tx = 0;
			var ty = 0;
			var dragging = false;
			var movedFar = false;
			var startX = 0;
			var startY = 0;
			var baseX = 0;
			var baseY = 0;

			img.draggable = false;

			var finePointer = window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches;

			var apply = function ( animate ) {
				img.style.transition = animate ? 'transform .45s ' + ocLbEase : 'none';
				img.style.transform = zoomed ? 'translate(' + tx + 'px,' + ty + 'px) scale(' + SCALE + ')' : 'none';
			};

			var clampPan = function () {
				var maxX = Math.max( 0, ( img.offsetWidth * SCALE - window.innerWidth ) / 2 + 40 );
				var maxY = Math.max( 0, ( img.offsetHeight * SCALE - window.innerHeight ) / 2 + 40 );
				tx = Math.min( maxX, Math.max( -maxX, tx ) );
				ty = Math.min( maxY, Math.max( -maxY, ty ) );
			};

			// Desktop: the cursor steers the pan — the viewport maps onto the
			// zoomed image, edge to edge.
			var hoverPan = function ( mx, my ) {
				var maxX = Math.max( 0, ( img.offsetWidth * SCALE - window.innerWidth ) / 2 );
				var maxY = Math.max( 0, ( img.offsetHeight * SCALE - window.innerHeight ) / 2 );
				tx = ( 0.5 - mx / window.innerWidth ) * 2 * maxX;
				ty = ( 0.5 - my / window.innerHeight ) * 2 * maxY;
			};

			if ( finePointer ) {
				if ( ocPanHandler ) {
					ocOverlay.removeEventListener( 'mousemove', ocPanHandler );
				}
				ocPanHandler = function ( event ) {
					if ( ! zoomed || ! img.isConnected ) {
						return;
					}
					hoverPan( event.clientX, event.clientY );
					apply( false );
				};
				ocOverlay.addEventListener( 'mousemove', ocPanHandler );
			}

			img.addEventListener( 'pointerdown', function ( event ) {
				if ( ! zoomed || 'mouse' === event.pointerType ) {
					return;
				}
				dragging = true;
				movedFar = false;
				startX = event.clientX;
				startY = event.clientY;
				baseX = tx;
				baseY = ty;
				if ( img.setPointerCapture ) {
					img.setPointerCapture( event.pointerId );
				}
				event.preventDefault();
			} );

			img.addEventListener( 'pointermove', function ( event ) {
				if ( ! dragging ) {
					return;
				}
				tx = baseX + ( event.clientX - startX );
				ty = baseY + ( event.clientY - startY );
				if ( Math.abs( event.clientX - startX ) + Math.abs( event.clientY - startY ) > 6 ) {
					movedFar = true;
				}
				clampPan();
				apply( false );
			} );

			img.addEventListener( 'pointerup', function () {
				dragging = false;
			} );

			img.addEventListener( 'click', function ( event ) {
				event.stopPropagation();

				// A drag is not a toggle.
				if ( movedFar ) {
					movedFar = false;
					return;
				}

				if ( zoomed ) {
					zoomed = false;
					tx = 0;
					ty = 0;
					apply( true );
					img.classList.remove( 'is-zoomed' );
					return;
				}

				zoomed = true;
				if ( finePointer ) {
					// Same map the mouse-follow uses — so the hand-off from
					// the zoom-in to the first mouse move is seamless.
					hoverPan( event.clientX, event.clientY );
				} else {
					var box = img.getBoundingClientRect();
					tx = ( ( box.left + box.width / 2 ) - event.clientX ) * ( SCALE - 1 );
					ty = ( ( box.top + box.height / 2 ) - event.clientY ) * ( SCALE - 1 );
					clampPan();
				}
				apply( true );
				img.classList.add( 'is-zoomed' );
			} );
		};

		// Paging: the current piece slips out toward the direction of travel
		// while the next settles in from the other side.
		var ocStepOverlay = function ( dir ) {
			var media = ocOverlay.querySelector( '.oc-voverlay__media' );
			media.style.transition = 'opacity .15s ease, transform .15s ease';
			media.style.opacity = '0';
			media.style.transform = 'translateX(' + ( -dir * 22 ) + 'px)';

			setTimeout( function () {
				ocOverlayIndex = ( ocOverlayIndex + dir + ocOverlayItems.length ) % ocOverlayItems.length;
				ocRenderOverlay();
				media.style.transition = 'none';
				media.style.transform = 'translateX(' + ( dir * 26 ) + 'px)';
				void media.offsetWidth;
				media.style.transition = 'opacity .3s ' + ocLbEase + ', transform .3s ' + ocLbEase;
				media.style.opacity = '1';
				media.style.transform = 'translateX(0)';
			}, 150 );
		};

		var ocCloseOverlay = function () {
			ocOverlay.classList.remove( 'is-open' );
			setTimeout( function () {
				ocOverlay.hidden = true;
				ocOverlay.querySelector( '.oc-voverlay__media' ).innerHTML = '';
			}, 220 );
			document.body.style.overflow = '';
			ocResumePageVideos();
		};

		// Thin, fine icons — 1.5px strokes, like the reference.
		var ocLbIcons = {
			left: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5l-7 7 7 7"/></svg>',
			right: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 5l7 7-7 7"/></svg>',
			close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg>'
		};

		var ocOpenOverlayWith = function ( items, index, originSlide ) {
			if ( ! items.length ) {
				return;
			}

			if ( ! ocOverlay ) {
				ocOverlay = document.createElement( 'div' );
				ocOverlay.className = 'oc-voverlay';
				ocOverlay.hidden = true;
				ocOverlay.innerHTML =
					'<span class="oc-voverlay__count"></span>' +
					'<div class="oc-voverlay__media"></div>' +
					'<div class="oc-voverlay__bar">' +
					'<button type="button" class="oc-voverlay__btn oc-voverlay__nav--prev" aria-label="prev">' + ocLbIcons.left + '</button>' +
					'<button type="button" class="oc-voverlay__btn oc-voverlay__btn--lg oc-voverlay__close" aria-label="close">' + ocLbIcons.close + '</button>' +
					'<button type="button" class="oc-voverlay__btn oc-voverlay__nav--next" aria-label="next">' + ocLbIcons.right + '</button>' +
					'</div>';
				document.body.appendChild( ocOverlay );

				ocOverlay.addEventListener( 'click', function ( event ) {
					if ( event.target.closest( '.oc-voverlay__nav--prev' ) ) {
						ocStepOverlay( -1 );
						return;
					}
					if ( event.target.closest( '.oc-voverlay__nav--next' ) ) {
						ocStepOverlay( 1 );
						return;
					}
					if ( event.target === ocOverlay || event.target.closest( '.oc-voverlay__close' ) ) {
						ocCloseOverlay();
					}
				} );

				document.addEventListener( 'keydown', function ( event ) {
					if ( ocOverlay.hidden ) {
						return;
					}
					if ( 'Escape' === event.key ) {
						ocCloseOverlay();
					} else if ( 'ArrowLeft' === event.key ) {
						ocStepOverlay( -1 );
					} else if ( 'ArrowRight' === event.key ) {
						ocStepOverlay( 1 );
					}
				} );
			}

			ocOverlayItems = items;
			ocOverlayIndex = Math.max( 0, index );

			// One stage at a time: the page's own video stops and rewinds
			// while the lightbox is up.
			ocResetPageVideos();

			var media = ocOverlay.querySelector( '.oc-voverlay__media' );
			media.style.transition = 'none';
			media.style.opacity = '1';
			media.style.transform = 'none';

			ocRenderOverlay();
			ocOverlay.hidden = false;
			setTimeout( function () {
				ocOverlay.classList.add( 'is-open' );
			}, 20 );
			document.body.style.overflow = 'hidden';

			// The signature open: whatever was clicked — image or video —
			// swells from its spot on the page into the lightbox while the
			// white ground fades in. Videos travel as a snapshot of their
			// current frame; embeds as their poster.
			var ghostSpec = null;

			if ( originSlide ) {
				if ( originSlide.classList.contains( 'oc-vslide' ) ) {
					var vid = originSlide.querySelector( 'video' );
					var ghostSrc = null;
					var vRatio = 16 / 9;

					if ( vid && vid.videoWidth ) {
						vRatio = vid.videoWidth / vid.videoHeight;
						try {
							var cv = document.createElement( 'canvas' );
							cv.width = vid.videoWidth;
							cv.height = vid.videoHeight;
							cv.getContext( '2d' ).drawImage( vid, 0, 0 );
							ghostSrc = cv.toDataURL( 'image/jpeg', 0.72 );
						} catch ( snapErr ) {
							ghostSrc = null;
						}
					}
					if ( ! ghostSrc && ocVideo && ocVideo.thumb ) {
						ghostSrc = ocVideo.thumb;
					}

					if ( ghostSrc ) {
						var vToW;
						var vToH;
						if ( ocVideo && 'file' === ocVideo.kind ) {
							// Never past the file's native size — a small video
							// blown to full height would snap back down when
							// the real element takes over.
							vToH = Math.min(
								window.innerHeight * 0.92,
								( window.innerWidth * 0.92 ) / vRatio,
								( vid && vid.videoHeight ) || Infinity
							);
							vToW = vToH * vRatio;
						} else {
							vToW = Math.min( window.innerWidth * 0.88, 1100 );
							vToH = vToW / ( 16 / 9 );
						}
						ghostSpec = { src: ghostSrc, el: originSlide, toW: vToW, toH: vToH };
					}
				} else {
					var originImg = originSlide.querySelector( 'img' );
					if ( originImg && originImg.getBoundingClientRect().width ) {
						var ratio = ( originImg.naturalWidth || 1 ) / ( originImg.naturalHeight || 1 );
						// Capped at the image's own size: the lightbox never
						// upscales, so the ghost must not either.
						var toH = Math.min(
							window.innerHeight,
							( window.innerWidth * 0.92 ) / ratio,
							originImg.naturalHeight || Infinity
						);
						ghostSpec = { src: originImg.currentSrc || originImg.src, el: originImg, toW: toH * ratio, toH: toH };
					}
				}
			}

			if ( ghostSpec ) {
				var from = ghostSpec.el.getBoundingClientRect();
				var ghost = document.createElement( 'img' );
				ghost.src = ghostSpec.src;
				ghost.className = 'oc-lb-ghost';
				ghost.style.top = from.top + 'px';
				ghost.style.left = from.left + 'px';
				ghost.style.width = from.width + 'px';
				ghost.style.height = from.height + 'px';
				document.body.appendChild( ghost );

				media.style.opacity = '0';

				setTimeout( function () {
					ghost.style.top = ( ( window.innerHeight - ghostSpec.toH ) / 2 ) + 'px';
					ghost.style.left = ( ( window.innerWidth - ghostSpec.toW ) / 2 ) + 'px';
					ghost.style.width = ghostSpec.toW + 'px';
					ghost.style.height = ghostSpec.toH + 'px';
				}, 30 );

				setTimeout( function () {
					media.style.transition = 'none';
					media.style.opacity = '1';
					ghost.remove();
				}, 500 );
			}
		};

		var ocOpenAtSlide = function ( slide ) {
			var items = ocOverlayList();
			var index = 0;
			items.forEach( function ( item, i ) {
				if ( item.slide === slide ) {
					index = i;
				}
			} );
			ocOpenOverlayWith( items, index, slide );
		};

		// One delegated click serves every slide: the video always opens the
		// lightbox; images open it unless the lightbox is switched off.
		galleryWrap.addEventListener( 'click', function ( event ) {
			var slide = event.target.closest( '.woocommerce-product-gallery__image' );

			if ( ! slide || ! galleryWrap.contains( slide ) || event.target.closest( '.oc-vfab' ) ) {
				return;
			}

			if ( slide.classList.contains( 'oc-vslide' ) ) {
				event.preventDefault();

				// Manual mode: the first click starts the loop in place; the
				// play badge yields to the zoom plus, and the next click
				// opens the lightbox.
				if ( slide.classList.contains( 'oc-vslide--manual' ) && ! slide.classList.contains( 'is-playing' ) ) {
					slide.classList.add( 'is-playing' );
					var frozen = slide.querySelector( 'video' );
					if ( frozen ) {
						frozen.loop = true;
						// The click is a gesture — sound may start on.
						frozen.muted = false;
						frozen.play().catch( function () {
							frozen.muted = true;
							frozen.play().catch( function () {} );
						} );
						if ( 'function' === typeof ocAttachSound ) {
							ocAttachSound( slide );
						}
					} else if ( ocVideo ) {
						var poster = slide.querySelector( '.oc-vposter' );
						if ( poster ) {
							poster.remove();
						}
						slide.insertAdjacentHTML( 'afterbegin', ocVideoLoopHtml() );
					}
					return;
				}

				ocOpenAtSlide( slide );
				return;
			}

			if ( document.body.classList.contains( 'oc-no-lightbox' ) ) {
				return;
			}

			event.preventDefault();
			ocOpenAtSlide( slide );
		} );

		if ( ocVideo ) {
			var ocVideoFallbackThumb = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 80 80%22%3E%3Crect width=%2280%22 height=%2280%22 fill=%22%231c1c1c%22/%3E%3Ccircle cx=%2240%22 cy=%2240%22 r=%2215%22 fill=%22none%22 stroke=%22%23fff%22 stroke-width=%222%22/%3E%3Cpath d=%22M36 33l12 7-12 7z%22 fill=%22%23fff%22/%3E%3C/svg%3E';

			var ocVideoLoopHtml = function () {
				return 'file' === ocVideo.kind
					? '<video src="' + ocVideo.loopSrc + '" autoplay muted loop playsinline preload="metadata"></video>'
					: '<iframe src="' + ocVideo.loopSrc + '" loading="lazy" allow="autoplay; fullscreen" tabindex="-1" title="video"></iframe>';
			};

			// Manual mode starts frozen: a first frame for files, the poster
			// for embeds — the click brings it to life.
			var ocVideoFrozenHtml = function () {
				return 'file' === ocVideo.kind
					? '<video src="' + ocVideo.loopSrc + '#t=0.001" muted playsinline preload="metadata"></video>'
					: '<img class="oc-vposter" src="' + ( ocVideo.thumb || ocVideoFallbackThumb ) + '" alt="" />';
			};

			// The video chips: pause/play at the bottom-RIGHT, and — when the
			// file carries audio — a speaker at the bottom-LEFT. White
			// circles, matching the lightbox buttons. Same chips on the
			// product page and inside the lightbox.
			var ocSoundIcons =
				'<svg class="off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4z"/><path d="M16.5 9.5l5 5M21.5 9.5l-5 5"/></svg>' +
				'<svg class="on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4z"/><path d="M16.5 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12"/></svg>';

			var ocPauseIcons =
				'<svg class="pp-pause" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M9 6v12M15 6v12"/></svg>' +
				'<svg class="pp-play" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true"><path d="M8.5 5.5l10 6.5-10 6.5z"/></svg>';

			var ocVideoHasAudio = function ( vid ) {
				if ( vid.mozHasAudio ||
					( 'number' === typeof vid.webkitAudioDecodedByteCount && vid.webkitAudioDecodedByteCount > 0 ) ||
					( vid.audioTracks && vid.audioTracks.length > 0 ) ) {
					return true;
				}

				// The dependable route: the element's captured stream reports
				// its audio tracks without needing any playback.
				try {
					var grab = vid.captureStream || vid.mozCaptureStream || vid.webkitCaptureStream;
					if ( grab && vid.readyState >= 2 ) {
						return grab.call( vid ).getAudioTracks().length > 0;
					}
				} catch ( err ) {
					return false;
				}

				return false;
			};

			// Sound on by default; when the browser refuses unmuted playback
			// (no gesture yet), fall back to muted without missing a frame.
			ocTrySoundOn = function ( vid ) {
				vid.muted = false;
				var attempt = vid.paused ? vid.play() : null;
				if ( attempt && attempt.catch ) {
					attempt.catch( function () {
						vid.muted = true;
						vid.play().catch( function () {} );
					} );
				}
				setTimeout( function () {
					if ( vid.paused && ! vid.muted ) {
						vid.muted = true;
						vid.play().catch( function () {} );
					}
				}, 250 );
			};

			ocAttachVideoChips = function ( host, vid ) {
				if ( ! host || host.querySelector( '.oc-vpause' ) ) {
					return;
				}

				var pauseChip = document.createElement( 'button' );
				pauseChip.type = 'button';
				pauseChip.className = 'oc-vchip oc-vpause';
				pauseChip.setAttribute( 'aria-label', 'pause' );
				pauseChip.innerHTML = ocPauseIcons;
				pauseChip.addEventListener( 'click', function ( event ) {
					event.stopPropagation();
					event.preventDefault();
					if ( vid.paused ) {
						vid.play().catch( function () {} );
					} else {
						vid.pause();
					}
				} );
				host.appendChild( pauseChip );

				vid.addEventListener( 'play', function () {
					pauseChip.classList.remove( 'is-paused' );
					// The chip only joins once the video has started — until
					// then the big play badge is the affordance.
					pauseChip.classList.add( 'is-revealed' );
				} );
				vid.addEventListener( 'pause', function () {
					pauseChip.classList.add( 'is-paused' );
				} );
				pauseChip.classList.toggle( 'is-paused', vid.paused );
				pauseChip.classList.toggle( 'is-revealed', ! vid.paused );

				// The speaker joins only when the file actually has audio.
				var tries = 0;

				var addSound = function () {
					if ( host.querySelector( '.oc-vsound' ) ) {
						return;
					}
					var chip = document.createElement( 'button' );
					chip.type = 'button';
					chip.className = 'oc-vchip oc-vsound';
					chip.setAttribute( 'aria-label', 'sound' );
					chip.innerHTML = ocSoundIcons;
					chip.addEventListener( 'click', function ( event ) {
						event.stopPropagation();
						event.preventDefault();
						vid.muted = ! vid.muted;
					} );
					host.appendChild( chip );
					// With a speaker present, pause slides one spot left.
					host.classList.add( 'oc-has-sound' );

					vid.addEventListener( 'volumechange', function () {
						chip.classList.toggle( 'is-on', ! vid.muted );
					} );
					chip.classList.toggle( 'is-on', ! vid.muted );
				};

				var check = function () {
					if ( ocVideoHasAudio( vid ) ) {
						addSound();
						vid.removeEventListener( 'timeupdate', check );
						vid.removeEventListener( 'loadeddata', check );
					} else if ( ++tries > 10 ) {
						vid.removeEventListener( 'timeupdate', check );
						vid.removeEventListener( 'loadeddata', check );
					}
				};

				vid.addEventListener( 'loadeddata', check );
				vid.addEventListener( 'timeupdate', check );
				check();
			};

			// No sound attempt here: a page-load unmute once played AUDIO from
			// a video whose slide was hidden — sound starts only from a real
			// gesture (the chip, a manual play click, or the lightbox).
			var ocAttachSound = function ( slide ) {
				if ( 'file' !== ocVideo.kind ) {
					return;
				}
				var vid = slide.querySelector( 'video' );
				if ( vid ) {
					ocAttachVideoChips( slide, vid );
				}
			};

			// The lightbox is the only stage while it is up: the page video
			// stops, rewinds and falls silent — and comes back (muted) for
			// the autoplay mode once the lightbox closes.
			ocResetPageVideos = function () {
				document.querySelectorAll( '.oc-vslide video, .oc-vfab video' ).forEach( function ( vid ) {
					vid.pause();
					try {
						vid.currentTime = 0;
					} catch ( err ) { /* not seekable yet */ }
					vid.muted = true;
				} );
				// Back to the resting look: big play badge, no pause chip.
				document.querySelectorAll( '.oc-vslide .oc-vpause' ).forEach( function ( chip ) {
					chip.classList.remove( 'is-revealed' );
				} );
				var manual = galleryWrap.querySelector( '.oc-vslide--manual.is-playing' );
				if ( manual ) {
					manual.classList.remove( 'is-playing' );
				}
			};

			ocResumePageVideos = function () {
				// The visible-slide rule decides who may run again: with an
				// is-active system, only the slide on show; without one
				// (stacked, mobile strip) the video itself may loop, muted.
				var active = galleryWrap.querySelector( '.woocommerce-product-gallery__image.is-active' ) ||
					galleryWrap.querySelector( '.oc-vslide--auto' );
				ocPauseManualVideo( active );

				var fab = document.querySelector( '.oc-vfab video' );
				if ( fab ) {
					fab.muted = true;
					fab.play().catch( function () {} );
				}
			};

			// One rule: a gallery video runs ONLY while its own slide is the
			// one on show — anything else pauses, falls silent, and (manual
			// mode) takes its play badge back. A hidden slide must never
			// play, and must never make a sound.
			ocPauseManualVideo = function ( currentSlide ) {
				galleryWrap.querySelectorAll( '.oc-vslide video' ).forEach( function ( vid ) {
					var host = vid.closest( '.oc-vslide' );

					if ( host === currentSlide ) {
						// The video's turn: the auto loop runs (muted until
						// the visitor asks for sound).
						if ( host.classList.contains( 'oc-vslide--auto' ) && vid.paused ) {
							vid.play().catch( function () {} );
						}
						return;
					}

					if ( ! vid.muted ) {
						vid.muted = true;
						var chip = host.querySelector( '.oc-vsound' );
						if ( chip ) {
							chip.classList.remove( 'is-on' );
						}
					}
					if ( ! vid.paused ) {
						vid.pause();
					}
				} );

				var playing = galleryWrap.querySelector( '.oc-vslide--manual.is-playing' );

				if ( ! playing || playing === currentSlide ) {
					return;
				}

				playing.classList.remove( 'is-playing' );

				var vid = playing.querySelector( 'video' );
				if ( vid ) {
					// The big play badge is back — the chip steps aside.
					var chip = playing.querySelector( '.oc-vpause' );
					if ( chip ) {
						chip.classList.remove( 'is-revealed' );
					}
				} else {
					playing.innerHTML = ocVideoFrozenHtml() + '<span class="oc-vplay" aria-hidden="true"></span>';
				}
			};

			if ( 'gallery' === ocVideo.placement ) {
				var ocInsertSlide = function () {
					if ( galleryWrap.querySelector( '.oc-vslide' ) ) {
						return;
					}
					var slide = document.createElement( 'div' );
					slide.className = 'woocommerce-product-gallery__image oc-vslide ' +
						( ocVideo.autoplay ? 'oc-vslide--auto' : 'oc-vslide--manual' );
					slide.dataset.thumb = ocVideo.thumb || ocVideoFallbackThumb;
					if ( 'file' === ocVideo.kind ) {
						// The rail freezes this into a first-frame thumbnail.
						slide.dataset.vsrc = ocVideo.loopSrc;
					}
					slide.innerHTML = ( ocVideo.autoplay ? ocVideoLoopHtml() : ocVideoFrozenHtml() ) +
						'<span class="oc-vplay" aria-hidden="true"></span>';
					var at = Math.min( Math.max( ocVideo.position, 1 ), galleryWrap.children.length + 1 ) - 1;
					galleryWrap.insertBefore( slide, galleryWrap.children[ at ] || null );
					ocAttachSound( slide );

					// Autoplay lands whenever the file is ready — if another
					// slide holds the stage at that moment, stop it cold. A
					// hidden slide never plays.
					var slideVid = slide.querySelector( 'video' );
					if ( slideVid ) {
						slideVid.addEventListener( 'play', function () {
							var act = galleryWrap.querySelector( '.woocommerce-product-gallery__image.is-active' );
							if ( act && act !== slide ) {
								slideVid.pause();
							}
						} );
					}
				};

				ocReinsertVideo = ocInsertSlide;
				ocInsertSlide();
				buildGalleryRail();
				buildMobileDots();
				window.dispatchEvent( new Event( 'resize' ) );

				// If another slide holds the stage right now, the video must
				// not start behind it — autoplay would run it hidden.
				var activeNow = galleryWrap.querySelector( '.woocommerce-product-gallery__image.is-active' );
				if ( activeNow && ! activeNow.classList.contains( 'oc-vslide' ) ) {
					ocPauseManualVideo( activeNow );
				}
			} else {
				var fab = document.createElement( 'button' );
				fab.type = 'button';
				fab.className = 'oc-vfab oc-vfab--' + ( 'float-start' === ocVideo.placement ? 'start' : 'end' );
				fab.setAttribute( 'aria-label', 'video' );
				fab.innerHTML = ocVideoLoopHtml() + '<span class="oc-vplay oc-vplay--sm" aria-hidden="true"></span>';
				fab.addEventListener( 'click', function ( event ) {
					event.stopPropagation();
					// A floating video is not part of the gallery — it opens alone.
					ocOpenOverlayWith( [ { type: 'video' } ], 0 );
				} );

				// Anchored to the FIRST image — in the grid and stacked
				// presets the gallery is a tall column, so pinning to the
				// container bottom would drop it out of sight.
				var ocAttachFab = function () {
					var host = galleryWrap.querySelector( '.woocommerce-product-gallery__image' );
					if ( host && ! galleryWrap.querySelector( '.oc-vfab' ) ) {
						host.classList.add( 'oc-has-vfab' );
						host.appendChild( fab );
					}
				};

				ocReinsertVideo = ocAttachFab;
				ocAttachFab();
			}
		}
	}

	/* ---------- variation buttons and swatches ----------
	 * The rendered UI drives Woo's own hidden selects, so stock, prices and
	 * variation images keep working untouched. A MutationObserver mirrors
	 * Woo's option pruning back onto the buttons. */

	document.querySelectorAll( '.oc-var' ).forEach( function ( box ) {
		var select = document.getElementById( box.dataset.for );

		if ( ! select ) {
			select = box.parentElement.querySelector( 'select' );
		}

		if ( ! select ) {
			return;
		}

		// A product whose colours are linked sibling products: its own solo
		// colour value is chosen for the visitor and the row disappears — the
		// "Colours" thumbs are the only colour UI on the page.
		if ( box.dataset.auto ) {
			var autoRow = box.closest( 'tr' );
			if ( autoRow ) {
				autoRow.classList.add( 'oc-row-auto' );
			}

			select.value = box.dataset.auto;
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			// The auto-picked colour shows its own gallery too.
			ocMaybeSwapGallery( select.value );

			// Woo's "clear" link would empty the hidden row and strand the
			// form, so re-apply after it runs.
			select.addEventListener( 'change', function () {
				if ( '' === select.value ) {
					setTimeout( function () {
						select.value = box.dataset.auto;
						// Dispatch only if the option took — a missing option
						// re-firing would loop.
						if ( select.value === box.dataset.auto ) {
							select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
						}
					}, 0 );
				}
			} );

			var form = box.closest( 'form.variations_form' );
			if ( form &&
				form.querySelectorAll( 'table.variations tr' ).length ===
				form.querySelectorAll( 'table.variations tr.oc-row-auto' ).length ) {
				form.classList.add( 'oc-vars-allauto' );
			}
			return;
		}

		var buttons = box.querySelectorAll( 'button' );

		function sync() {
			var options = Array.prototype.slice.call( select.options );

			buttons.forEach( function ( btn ) {
				var opt = null;
				options.forEach( function ( o ) {
					if ( o.value === btn.dataset.value ) {
						opt = o;
					}
				} );

				btn.classList.toggle( 'is-selected', select.value === btn.dataset.value && '' !== select.value );
				btn.disabled = ! opt || opt.disabled;
				btn.classList.toggle( 'is-off', btn.disabled );
			} );
		}

		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( btn.disabled ) {
					return;
				}
				select.value = select.value === btn.dataset.value ? '' : btn.dataset.value;
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				sync();
				ocMaybeSwapGallery( select.value );
			} );
		} );

		select.addEventListener( 'change', sync );
		new MutationObserver( sync ).observe( select, { childList: true, subtree: true, attributes: true } );
		sync();

		// A default-selected colour shows its own gallery from the start.
		if ( select.value ) {
			ocMaybeSwapGallery( select.value );
		}
	} );

	/* ---------- variation rows: "Label: value" ----------
	 * Every attribute row reads like the reference sites — the label carries
	 * the chosen value ("Colour: Black") and updates live. */

	document.querySelectorAll( 'form.variations_form table.variations tr' ).forEach( function ( tr ) {
		var select = tr.querySelector( 'td.value select' );
		var label = tr.querySelector( 'th.label label' ) || tr.querySelector( 'th.label' );

		if ( ! select || ! label ) {
			return;
		}

		var choice = document.createElement( 'span' );
		choice.className = 'oc-choice';
		label.appendChild( choice );

		function updateChoice() {
			var opt = select.selectedOptions[ 0 ];
			choice.textContent = select.value && opt ? opt.textContent : '';
		}

		select.addEventListener( 'change', updateChoice );
		new MutationObserver( updateChoice ).observe( select, { childList: true, subtree: true, attributes: true } );
		updateChoice();
	} );

	/* ---------- native gallery zoom ----------
	 * Woo's own zoom binds $images.first() only, so with the slider removed
	 * every image after the first stayed static. This binds them all. */

	var zoomGallery = document.querySelector( '.woocommerce-product-gallery' );

	if ( zoomGallery && document.body.classList.contains( 'oc-zoom' ) &&
		window.matchMedia( '(hover: hover)' ).matches ) {

		zoomGallery.addEventListener( 'mousemove', function ( event ) {
			var box = event.target.closest( '.woocommerce-product-gallery__image' );
			if ( ! box || event.target.closest( '.oc-thumbs' ) ) {
				return;
			}

			var img = box.querySelector( 'img' );
			if ( ! img ) {
				return;
			}

			var rect = box.getBoundingClientRect();
			// Zoom to roughly natural size, clamped so small and huge
			// originals both stay usable.
			var scale = img.naturalWidth > 0 ?
				Math.max( 1.6, Math.min( 2.5, img.naturalWidth / rect.width ) ) : 2;

			img.style.transformOrigin =
				( ( event.clientX - rect.left ) / rect.width * 100 ) + '% ' +
				( ( event.clientY - rect.top ) / rect.height * 100 ) + '%';
			img.style.transform = 'scale(' + scale + ')';
		} );

		zoomGallery.addEventListener( 'mouseout', function ( event ) {
			var box = event.target.closest( '.woocommerce-product-gallery__image' );
			if ( box && ! ( event.relatedTarget && box.contains( event.relatedTarget ) ) ) {
				var img = box.querySelector( 'img' );
				if ( img ) {
					img.style.transform = '';
				}
			}
		} );
	}

	/* The gallery's zoom-plus affordance is a NATIVE svg cursor now (see the
	 * stylesheet) — no follower element, no mousemove work, zero lag. Video
	 * slides keep their own pointer + play badge. */

	/* ---------- sticky product columns ----------
	 * Pin only a column that fits inside the viewport. A taller-than-viewport
	 * sticky column pins immediately and freezes on screen while the page
	 * scrolls past it — the "page refuses to scroll" bug. */

	var stickCols = document.querySelectorAll(
		'.single-product div.product > div.images, .single-product div.product > div.summary'
	);

	if ( stickCols.length ) {
		var updateStickCols = function () {
			// Mobile columns are position:relative — an inline inset there is a
			// plain downward shift (it pushed the gallery 93px under the header
			// and over the title). Sticky pinning is desktop-only.
			if ( window.innerWidth <= 900 ) {
				stickCols.forEach( function ( col ) {
					var inner = col.querySelector( ':scope > .oc-stick-inner' );
					if ( inner ) {
						inner.style.insetBlockStart = '';
					}
				} );
				return;
			}

			// Pin offset = the sticky header's real height (a two-row layout is
			// taller than the height token) plus breathing room.
			var stickyHeader = document.querySelector( '.oc-header.is-sticky' );
			var pinTop = stickyHeader ? stickyHeader.offsetHeight + 20 : 24;

			stickCols.forEach( function ( col ) {
				var inner = col.querySelector( ':scope > .oc-stick-inner' ) || col;

				// A column that fits pins below the header. A taller one pins by
				// its bottom edge instead (negative top): it scrolls naturally
				// until its end is visible, then holds — never the page-freeze
				// that top-pinning a tall column caused.
				var fits = inner.offsetHeight <= window.innerHeight - pinTop;
				inner.style.insetBlockStart = fits
					? pinTop + 'px'
					: ( window.innerHeight - inner.offsetHeight - 16 ) + 'px';
				col.classList.add( 'oc-col-stick' );
			} );
		};

		window.addEventListener( 'resize', updateStickCols );
		// Image loads change column heights after DOMContentLoaded, and the
		// accordion panels change the summary height on every toggle.
		window.addEventListener( 'load', updateStickCols );
		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '.oc-acc-head' ) ) {
				setTimeout( updateStickCols, 60 );
			}
		} );
		updateStickCols();
	}

	/* ---------- sticky add-to-cart ---------- */

	var bar = document.querySelector( '[data-oc-sticky-atc]' );
	var form = document.querySelector( 'form.cart' );

	if ( bar && form ) {
		bar.hidden = false;

		function updateBar() {
			// Show once the buy form has scrolled above the viewport. One rect
			// read and a class toggle — cheap enough to run unthrottled.
			bar.classList.toggle( 'is-visible', form.getBoundingClientRect().bottom < 0 );
		}

		window.addEventListener( 'scroll', updateBar, { passive: true } );
		updateBar();

		var proxy = bar.querySelector( '.oc-sticky-atc__btn' );
		var submit = form.querySelector( '[type="submit"]' );

		if ( proxy ) {
			proxy.addEventListener( 'click', function () {
				// Simple products buy straight away; variable products need the
				// visitor to pick options first, so scroll them to the form.
				if ( submit && ! submit.disabled && ! form.classList.contains( 'variations_form' ) ) {
					submit.click();
					return;
				}

				form.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			} );
		}
	}
}() );
