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

	/* ---------- primary menu ---------- */

	/* CSS opens a panel on :hover all by itself, and that keeps working if
	 * this never runs. What the class adds is the two things :hover cannot
	 * do: hold the panel open for a moment after the cursor leaves, so a
	 * diagonal move towards its corner does not close it on the way, and
	 * tell the page to dim behind it. Pointer devices only — on a touch
	 * screen there is no cursor to have intent. */
	var ocNav = document.querySelector( '.oc-nav' );

	/* A panel's markup is in the page; its pictures are not, because lazy is
	 * what makes a panel nobody opens cost nothing. But a picture that starts
	 * loading when its panel opens arrives after it, and a blank rectangle
	 * under a heading is exactly the wait the panel was printed inline to
	 * avoid. So they start the moment the cursor reaches the menu at all —
	 * earlier than any one item, later than never. */
	var ocWarmed = false;

	function ocWarm() {
		if ( ocWarmed ) {
			return;
		}

		ocWarmed = true;

		Array.prototype.forEach.call( document.querySelectorAll( '.oc-mega img[loading="lazy"]' ), function ( img ) {
			img.setAttribute( 'loading', 'eager' );
		} );
	}

	if ( ocNav ) {
		ocNav.addEventListener( 'pointerenter', ocWarm );
	}

	if ( ocNav && window.matchMedia( '(hover: hover)' ).matches ) {
		var navOpen = null;
		var navTimer = null;

		function navShow( li ) {
			clearTimeout( navTimer );

			if ( navOpen && navOpen !== li ) {
				navOpen.classList.remove( 'is-open' );
			}

			navOpen = li;
			li.classList.add( 'is-open' );
			document.documentElement.classList.add( 'oc-menu-open' );
			navFit( li );
		}

		/* A panel that opens under its own link starts at that link's edge and
		 * runs on in the reading direction. On the last link in the bar there
		 * may not be that much page left, so it is pulled back inside. The
		 * sign is the same either way: negative moves the box back towards
		 * the middle, whichever edge it was heading for. */
		function navFit( li ) {
			if ( ! ocNav.classList.contains( 'oc-nav--w-menu' ) ) {
				return;
			}

			var mega = li.querySelector( '.oc-mega' );

			if ( ! mega ) {
				return;
			}

			mega.style.insetInlineStart = '';

			var box = mega.getBoundingClientRect();
			var over = Math.max( 8 - box.left, box.right - ( document.documentElement.clientWidth - 8 ), 0 );

			if ( over > 0 ) {
				mega.style.insetInlineStart = -Math.round( over ) + 'px';
			}
		}

		function navHide( delay ) {
			clearTimeout( navTimer );
			navTimer = setTimeout( function () {
				if ( navOpen ) {
					navOpen.classList.remove( 'is-open' );
					navOpen = null;
				}
				document.documentElement.classList.remove( 'oc-menu-open' );
			}, delay === undefined ? 180 : delay );
		}

		Array.prototype.forEach.call( ocNav.querySelectorAll( '.oc-nav__list > li' ), function ( li ) {
			if ( ! li.querySelector( '.sub-menu, .oc-mega' ) ) {
				return;
			}

			li.addEventListener( 'pointerenter', function () { navShow( li ); } );
			li.addEventListener( 'pointerleave', function () { navHide(); } );
			li.addEventListener( 'focusin', function () { navShow( li ); } );
			li.addEventListener( 'focusout', function ( event ) {
				if ( ! li.contains( event.relatedTarget ) ) {
					navHide( 0 );
				}
			} );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Escape' || ! navOpen ) {
				return;
			}

			var link = navOpen.querySelector( 'a' );
			navHide( 0 );

			if ( link ) {
				link.focus();
			}
		} );
	}

	/* ---------- the drawer ---------- */

	var burger = document.querySelector( '.oc-burger' );
	var drw = document.getElementById( 'oc-mobile-menu' );

	if ( burger && drw ) {
		var drwLast = null;

		function drwSet( open ) {
			burger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

			if ( open ) {
				drw.hidden = false;
				drw.classList.add( 'is-live' );
				/* A frame between display and transform, or the panel is
				 * already where it is going and never slides. */
				void drw.offsetWidth;
				drw.setAttribute( 'data-open', 'true' );
				document.documentElement.classList.add( 'oc-drw-open' );
				drwLast = document.activeElement;

				var first = drw.querySelector( '.oc-drw__x' );

				if ( first ) {
					first.focus();
				}

				return;
			}

			drw.setAttribute( 'data-open', 'false' );
			document.documentElement.classList.remove( 'oc-drw-open' );
			drwShut( drw );

			/* Wait for the slide out before taking the panel off the page. */
			setTimeout( function () {
				if ( drw.getAttribute( 'data-open' ) !== 'true' ) {
					drw.classList.remove( 'is-live' );
					drw.hidden = true;
				}
			}, 300 );

			if ( drwLast && drwLast.focus ) {
				drwLast.focus();
			}
		}

		/* Close every open branch, so reopening the drw starts at the top
		 * rather than wherever the last visit ended. */
		function drwShut( scope ) {
			Array.prototype.forEach.call( scope.querySelectorAll( '.oc-drw__i.is-open' ), function ( li ) {
				li.classList.remove( 'is-open' );
				var button = li.querySelector( ':scope > .oc-drw__row > .oc-drw__more' );

				if ( button ) {
					button.setAttribute( 'aria-expanded', 'false' );
				}
			} );
			drwDepth();
		}

		/* The top bar's back arrow only exists while there is somewhere to
		 * go back from. */
		function drwDepth() {
			drw.classList.toggle( 'oc-drw--in', !! drw.querySelector( '.oc-drw__i.is-open' ) );
		}

		/* One step out: the deepest open screen closes and its opener takes
		 * the focus back. */
		function drwStepBack() {
			var deepest = null;

			Array.prototype.forEach.call( drw.querySelectorAll( '.oc-drw__i.is-open' ), function ( li ) {
				deepest = li;
			} );

			if ( ! deepest ) {
				return false;
			}

			deepest.classList.remove( 'is-open' );

			var button = deepest.querySelector( ':scope > .oc-drw__row > .oc-drw__more' );

			if ( button ) {
				button.setAttribute( 'aria-expanded', 'false' );
				button.focus();
			}

			drwDepth();

			return true;
		}

		burger.addEventListener( 'click', function () {
			drwSet( burger.getAttribute( 'aria-expanded' ) !== 'true' );

			// Warming the pictures can wait until the panel has arrived —
			// a decode burst during the slide was eating the first taps.
			setTimeout( ocWarm, 450 );
		} );

		Array.prototype.forEach.call( drw.querySelectorAll( '[data-oc-drw-close]' ), function ( node ) {
			node.addEventListener( 'click', function () {
				drwSet( false );
			} );
		} );

		drw.addEventListener( 'click', function ( event ) {
			var more = event.target.closest( '.oc-drw__more' );

			if ( more ) {
				var li = more.closest( '.oc-drw__i' );
				var open = li.classList.contains( 'is-open' );

				/* Siblings close, so one branch is open at a time and the
				 * list cannot grow past what a thumb can reach. */
				Array.prototype.forEach.call( li.parentNode.children, function ( other ) {
					if ( other !== li ) {
						other.classList.remove( 'is-open' );
						var b = other.querySelector( ':scope > .oc-drw__row > .oc-drw__more' );

						if ( b ) {
							b.setAttribute( 'aria-expanded', 'false' );
						}
					}
				} );

				li.classList.toggle( 'is-open', ! open );
				more.setAttribute( 'aria-expanded', open ? 'false' : 'true' );

				if ( open ) {
					drwShut( li );
				}

				drwDepth();

				return;
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-oc-drw-back]' ) ) {
				drwStepBack();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Escape' || drw.hidden ) {
				return;
			}

			/* Escape steps back one level before it closes the whole thing. */
			if ( drwStepBack() ) {
				return;
			}

			drwSet( false );
			burger.focus();
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

	/* ---------- header search ----------
	 * Everything the panel needs is already in the page when it opens, so the
	 * first frame costs nothing. From the first keystroke it asks the server,
	 * but never for a question it has already asked and never for one the
	 * shopper has moved past.
	 */

	var ocSL = window.ocL10n || {};
	var searchToggles = document.querySelectorAll( '.oc-search-toggle' );
	var searchPanel = document.querySelector( '[data-oc-search]' );

	if ( searchPanel ) {
		// There can be two boxes: the panel's own, and one the header carries
		// when the site shows a field instead of an icon. They are the same
		// search, so they share a value and either one drives the panel.
		var sFields = Array.prototype.slice.call( document.querySelectorAll( '[data-oc-search-field]' ) );
		var sField = sFields[ 0 ];
		var sOut = searchPanel.querySelector( '[data-oc-search-out]' );
		var sIdle = searchPanel.querySelector( '[data-oc-search-idle]' );
		var sClear = searchPanel.querySelector( '[data-oc-search-clear]' );
		var sLive = searchPanel.querySelector( '[data-oc-search-live]' );
		var sMin = parseInt( searchPanel.dataset.min, 10 ) || 2;
		var sAction = searchPanel.dataset.action;
		var sCart = searchPanel.dataset.cart || sAction;
		var sCache = new Map();
		var sTimer = null;
		var sIdleTimer = null;
		var sAbort = null;
		var sTerm = '';
		var sMeant = '';

		var HIST_KEY = 'ocSearchHist';
		var HIST_MAX = parseInt( ocSL.searchHistMax || 8, 10 );

		/* -- the visitor's own searches, kept in their browser and nowhere else -- */

		function histRead() {
			try {
				var raw = JSON.parse( localStorage.getItem( HIST_KEY ) || '[]' );
				return Array.isArray( raw ) ? raw.slice( 0, HIST_MAX ) : [];
			} catch ( e ) {
				return [];
			}
		}

		function histWrite( list ) {
			try {
				localStorage.setItem( HIST_KEY, JSON.stringify( list.slice( 0, HIST_MAX ) ) );
			} catch ( e ) {}
		}

		function histAdd( term ) {
			term = ( term || '' ).trim();

			if ( ! term ) {
				return;
			}

			var list = histRead();

			// One attempt at a word, not the trail of keystrokes that led to
			// it: "לסל" and "לסלו" and "לסלון" are the same search, so only
			// the longest of them is kept.
			var longer = list.filter( function ( x ) {
				return 0 === x.indexOf( term ) && x !== term;
			} )[ 0 ];

			if ( longer ) {
				term = longer;
			}

			list = list.filter( function ( x ) {
				return x !== term && 0 !== term.indexOf( x );
			} );

			list.unshift( term );
			histWrite( list );
			histPaint();
		}

		function histPaint() {
			var box = searchPanel.querySelector( '[data-oc-search-history]' );
			var list = searchPanel.querySelector( '[data-oc-search-history-list]' );

			if ( ! box || ! list ) {
				return;
			}

			var items = histRead();

			box.hidden = ! items.length;
			list.innerHTML = '';

			items.forEach( function ( term ) {
				var li = document.createElement( 'li' );

				var go = document.createElement( 'button' );
				go.type = 'button';
				go.className = 'oc-search__histterm';
				go.textContent = term;
				go.setAttribute( 'data-oc-search-term', term );

				var del = document.createElement( 'button' );
				del.type = 'button';
				del.className = 'oc-search__histdel';
				del.setAttribute( 'aria-label', ocSL.searchForget || 'Remove' );
				del.textContent = '×';
				del.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					histWrite( histRead().filter( function ( x ) {
						return x !== term;
					} ) );
					histPaint();
				} );

				li.appendChild( go );
				li.appendChild( del );
				list.appendChild( li );
			} );
		}

		var histClear = searchPanel.querySelector( '[data-oc-search-history-clear]' );

		if ( histClear ) {
			histClear.addEventListener( 'click', function () {
				histWrite( [] );
				histPaint();
			} );
		}

		/* -- opening and closing -- */

		var sCloseTimer = null;

		function setSearchOpen( open, keepFocus ) {
			clearTimeout( sCloseTimer );

			document.documentElement.classList.toggle( 'oc-search-open', open );

			searchToggles.forEach( function ( t ) {
				t.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );

			sCloses.forEach( function ( button ) {
				if ( button.closest( '.oc-hsearch' ) ) {
					button.hidden = ! open;

					var sep = button.previousElementSibling;

					if ( sep && sep.classList.contains( 'oc-hsearch__sep' ) ) {
						sep.hidden = ! open;
					}
				}
			} );

			if ( open ) {
				searchPanel.hidden = false;
				histPaint();

				// Read a layout value first: that settles the closed state as the
				// starting point, so the transition has somewhere to come from
				// without waiting on a frame that may never be painted.
				void searchPanel.offsetWidth;
				searchPanel.classList.add( 'is-open' );

				// Focus goes to the panel's own box, unless the shopper is
				// already typing in the header's.
				if ( sField && ! keepFocus && ! searchPanel.querySelector( '.oc-search__bar[hidden]' ) ) {
					var visible = sFields.filter( function ( f ) {
						return f.offsetParent !== null;
					} )[ 0 ] || sField;

					visible.focus();
					visible.select();
				}

				return;
			}

			searchPanel.classList.remove( 'is-open' );

			// Let it fold away before it leaves the page.
			sCloseTimer = setTimeout( function () {
				searchPanel.hidden = true;
			}, 220 );
		}

		searchToggles.forEach( function ( toggle ) {
			toggle.addEventListener( 'click', function () {
				setSearchOpen( searchPanel.hidden );
			} );
		} );

		// The panel's own X, and the one the header carries when the search
		// lives there: one search, one way to leave it.
		var sCloses = Array.prototype.slice.call( document.querySelectorAll( '[data-oc-search-close]' ) );

		sCloses.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				setSearchOpen( false );
			} );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! searchPanel.hidden ) {
				setSearchOpen( false );
			}
		} );

		// A click outside the search closes it. "Outside" has to include the
		// header's own box, which lives in the header rather than the panel —
		// otherwise clicking it opened the panel and closed it in one gesture.
		document.addEventListener( 'click', function ( event ) {
			if ( searchPanel.hidden || searchPanel.contains( event.target ) ) {
				return;
			}

			for ( var i = 0; i < searchToggles.length; i++ ) {
				if ( searchToggles[ i ].contains( event.target ) ) {
					return;
				}
			}

			for ( var f = 0; f < sFields.length; f++ ) {
				var form = sFields[ f ].closest( 'form' ) || sFields[ f ];

				if ( form.contains( event.target ) ) {
					return;
				}
			}

			setSearchOpen( false );
		} );

		/* -- asking -- */

		function paint( data, term ) {
			if ( ! sOut ) {
				return;
			}

			if ( ! data || ! data.html ) {
				sOut.hidden = true;
				sOut.innerHTML = '';

				if ( sIdle ) {
					sIdle.hidden = false;
				}

				return;
			}

			sOut.innerHTML = data.html;
			sOut.hidden = false;

			if ( sIdle ) {
				sIdle.hidden = true;
			}

			if ( sField ) {
				sField.setAttribute( 'aria-expanded', 'true' );
			}

			if ( sLive ) {
				sLive.textContent = ( ocSL.searchFound || '%s results' ).replace( '%s', data.total || 0 );
			}

			bindResults();
		}

		var sGo = searchPanel.querySelector( '.oc-search__go' );

		function setBarState( term ) {
			var empty = ! term;

			// An empty field with dir="auto" is read as left-to-right, which
			// puts a Hebrew placeholder on the wrong side. A field follows the
			// page until it has words of its own to follow.
			sFields.forEach( function ( field ) {
				field.setAttribute( 'dir', empty ? ( 'rtl' === document.documentElement.dir ? 'rtl' : 'ltr' ) : 'auto' );

				if ( field.value !== term && document.activeElement !== field ) {
					field.value = term;
				}
			} );

			// The eraser only exists while there is something to erase; the
			// glass stays put and goes quiet, because it anchors the line.
			if ( sClear ) {
				sClear.hidden = empty;
			}

			if ( sGo ) {
				sGo.disabled = empty;
				sGo.classList.toggle( 'is-off', empty );
			}
		}

		function ask( term, log ) {
			var key = term + ( log ? '|log' : '' );

			if ( ! log && sCache.has( term ) ) {
				paint( sCache.get( term ), term );
				return Promise.resolve();
			}

			if ( sAbort ) {
				sAbort.abort();
			}

			sAbort = new AbortController();

			return fetch( sAction + '?oc_search=1&q=' + encodeURIComponent( term ) + ( log ? '&log=1' : '' ), {
				credentials: 'same-origin',
				signal: sAbort.signal
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( j ) {
					if ( ! j || ! j.success ) {
						return;
					}

					// The shopper may have typed on while this was in flight.
					if ( term !== sTerm ) {
						return;
					}

					sCache.set( term, j.data );
					paint( j.data, term );

					// A rescued search is remembered as the word it resolved to.
					if ( j.data && j.data.term ) {
						sMeant = j.data.term;
					}
				} )
				.catch( function () {} );
		}

		function run() {
			var live = sFields.filter( function ( f ) {
				return document.activeElement === f;
			} )[ 0 ] || sField;

			var term = ( live.value || '' ).trim();

			sTerm = term;

			setBarState( term );

			if ( term.length < sMin ) {
				paint( null, term );

				if ( sField ) {
					sField.setAttribute( 'aria-expanded', 'false' );
				}

				return;
			}

			ask( term, false );

			// Once the typing stops, the word is worth recording.
			clearTimeout( sIdleTimer );
			sIdleTimer = setTimeout( function () {
				if ( sTerm === term && term.length >= sMin ) {
					fetch( sAction + '?oc_search=1&q=' + encodeURIComponent( term ) + '&log=1&quiet=1', {
						credentials: 'same-origin'
					} ).catch( function () {} );
					histAdd( sMeant || term );
				}
			}, 1100 );
		}

		sFields.forEach( function ( field ) {
			field.addEventListener( 'input', function () {
				// The state answers the keystroke, not the pause after it —
				// the buttons should never lag behind the typing.
				setBarState( ( field.value || '' ).trim() );

				// A header box that starts a search opens the panel with it.
				if ( searchPanel.hidden ) {
					setSearchOpen( true, field );
				}

				clearTimeout( sTimer );
				sTimer = setTimeout( run, 120 );
			} );

			field.addEventListener( 'focus', function () {
				var typed = ( field.value || '' ).trim();

				// A panel that has something to say before a word is typed
				// says it the moment the box is touched. A plain one waits,
				// because opening it empty would be an empty box under a box.
				if ( searchPanel.hidden && ( 'min' !== searchPanel.dataset.mode || typed.length >= sMin ) ) {
					setSearchOpen( true, field );
				}

				if ( typed.length >= sMin ) {
					run();
				}
			} );
		} );

		if ( sClear ) {
			sClear.addEventListener( 'click', function () {
				sFields.forEach( function ( f ) {
					f.value = '';
				} );

				sField.focus();
				run();
			} );
		}

		/* -- a suggestion, a pill or a past search fills the box -- */

		searchPanel.addEventListener( 'click', function ( event ) {
			var pill = event.target.closest( '[data-oc-search-term]' );

			if ( ! pill ) {
				return;
			}

			var picked = pill.getAttribute( 'data-oc-search-term' ) || '';

			sFields.forEach( function ( f ) {
				f.value = picked;
			} );

			var visible = sFields.filter( function ( f ) {
				return f.offsetParent !== null;
			} )[ 0 ] || sField;

			visible.focus();
			run();
		} );

		/* -- moving through results from the keyboard -- */

		function items() {
			return Array.prototype.slice.call(
				searchPanel.querySelectorAll( '[data-oc-search-hit], .oc-search__pill, .oc-search__histterm' )
			);
		}

		searchPanel.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' !== event.key && 'ArrowUp' !== event.key ) {
				return;
			}

			var list = items();

			if ( ! list.length ) {
				return;
			}

			event.preventDefault();

			var at = list.indexOf( document.activeElement );
			var next = 'ArrowDown' === event.key ? at + 1 : at - 1;

			if ( next < 0 ) {
				sField.focus();
				return;
			}

			( list[ next ] || list[ 0 ] ).focus();
		} );

		/* -- what a result click teaches us -- */

		function bindResults( scope ) {
			scope = scope || sOut;

			scope.querySelectorAll( '[data-oc-search-hit]' ).forEach( function ( link ) {
				link.addEventListener( 'click', function () {
					histAdd( sMeant || sTerm );

					// Which answer was chosen, so the next shopper asking the
					// same thing is offered it sooner.
					var chosen = link.getAttribute( 'data-oc-id' ) || '';

					try {
						navigator.sendBeacon(
							sAction + '?oc_search=1&click=1&q=' + encodeURIComponent( sMeant || sTerm ) +
								( chosen ? '&id=' + encodeURIComponent( chosen ) : '' )
						);
					} catch ( e ) {}
				} );
			} );

			var more = scope.querySelector( '[data-oc-search-more]' );

			if ( more ) {
				more.addEventListener( 'click', function () {
					if ( more.classList.contains( 'is-busy' ) ) {
						return;
					}

					more.classList.add( 'is-busy' );

					fetch( sAction + '?oc_search=1&q=' + encodeURIComponent( sTerm ) + '&show=' + encodeURIComponent( more.getAttribute( 'data-oc-search-more' ) ), {
						credentials: 'same-origin'
					} )
						.then( function ( r ) {
							return r.json();
						} )
						.then( function ( j ) {
							if ( j && j.success ) {
								paint( j.data, sTerm );
							} else {
								more.classList.remove( 'is-busy' );
							}
						} )
						.catch( function () {
							more.classList.remove( 'is-busy' );
						} );
				} );
			}

			scope.querySelectorAll( '[data-oc-search-add]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var id = button.getAttribute( 'data-oc-search-add' );

					if ( ! id || button.disabled ) {
						return;
					}

					button.disabled = true;
					button.classList.add( 'is-busy' );

					var body = new URLSearchParams( {
						action: 'oc_add_to_cart',
						product_id: id,
						quantity: '1'
					} );

					fetch( sCart, {
						method: 'POST',
						credentials: 'same-origin',
						body: body
					} )
						.then( function ( r ) {
							return r.json();
						} )
						.then( function ( j ) {
							button.classList.remove( 'is-busy' );

							if ( j && j.success ) {
								button.classList.add( 'is-done' );
								button.textContent = ocSL.searchAdded || 'Added';
								document.body.dispatchEvent( new CustomEvent( 'oc:cart-changed' ) );

								if ( j.data && 'undefined' !== typeof j.data.count ) {
									document.querySelectorAll( '.oc-cart-count' ).forEach( function ( el ) {
										el.textContent = j.data.count;
									} );
								}
							} else {
								button.disabled = false;
							}
						} )
						.catch( function () {
							button.classList.remove( 'is-busy' );
							button.disabled = false;
						} );
				} );
			} );
		}

		histPaint();
		setBarState( sField ? ( sField.value || '' ).trim() : '' );

		// The products offered before a word is typed are as clickable as any
		// result: their add buttons need the same wiring.
		if ( sIdle ) {
			bindResults( sIdle );
		}
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
			// Each rebuilt slide keeps its wrapping link — without it, a
			// colour-swapped card silently stopped leading anywhere.
			strip.innerHTML = imgs.map( function ( src, i ) {
				return '<figure class="oc-card-media__item' + ( 0 === i ? ' is-first' : '' ) + '">' +
					'<a class="oc-card-media__link woocommerce-LoopProduct-link" href="' + item.dataset.url + '" aria-hidden="true" tabindex="-1">' +
					'<img src="' + src + '" alt="" loading="' + ( 0 === i ? 'eager' : 'lazy' ) + '" sizes="(max-width: 900px) 50vw, 25vw"></a></figure>';
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

				// Preset steps recount like any other value: a step with
				// nothing under it in this category goes grey (or away).
				if ( facets.price && facets.price.tiers ) {
					panel.querySelectorAll( '[data-flt-tier]' ).forEach( function ( btn ) {
						var n = facets.price.tiers[ btn.dataset.fltTier ] || 0;
						var active = btn.classList.contains( 'is-active' );
						var em = btn.querySelector( '[data-flt-count]' );

						if ( em ) {
							em.textContent = n;
						}

						var off = 0 === n && ! active;

						btn.classList.toggle( 'is-off', off && 'gray' === cfg.empty );
						btn.disabled = off && 'gray' === cfg.empty;
						btn.hidden = off && 'hide' === cfg.empty;
					} );
				}

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

	// The page behind the drawer must stand still. Hiding the root's overflow
	// alone does not hold on iOS — the body scrolls on regardless, which read
	// as a huge stray scroll under the panel — so the body is pinned at its
	// place and released to the same spot.
	var drawerY = 0;

	function openDrawer() {
		if ( ! drawer ) {
			return;
		}
		drawer.hidden = false;
		setTimeout( function () {
			drawer.classList.add( 'is-open' );
		}, 10 );

		drawerY = window.scrollY || window.pageYOffset || 0;
		document.body.style.top = ( -drawerY ) + 'px';
		document.documentElement.classList.add( 'oc-cart-lock' );
	}

	function closeDrawer() {
		if ( ! drawer ) {
			return;
		}
		drawer.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'oc-cart-lock' );
		document.body.style.top = '';
		window.scrollTo( 0, drawerY );
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
			} else if ( ! openOnAdd ) {
				// No drawer: the toast under the header carries the news.
				var card = btn.closest( 'li.product, .oc-cartup__item' );
				var cardTitle = card && card.querySelector( '.woocommerce-loop-product__title, .oc-cartup__name' );
				var cardImg = card && card.querySelector( 'img' );
				setTimeout( function () {
					cartToast(
						cardTitle ? cardTitle.textContent.trim() : '',
						cardImg ? cardImg.currentSrc || cardImg.src : ''
					);
				}, 900 );
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

		// A non-ajax add (a no-js fallback) lands back with ?add-to-cart /
		// added-to-cart in the url — open the drawer then too.
		if ( openOnAdd && /(?:^|[?&])(?:add|added)-to-cart=/.test( window.location.search ) ) {
			setTimeout( openDrawer, 350 );
		}

		/* -- "added to cart" toast, under the header's cart icon --
		 * Shows when the drawer is configured NOT to open: the product's
		 * image and name, gone after a few seconds with a quick fade. */

		function cartToast( name, imgSrc ) {
			var old = document.querySelector( '.oc-toast' );
			if ( old ) {
				old.remove();
			}

			var toast = document.createElement( 'div' );
			toast.className = 'oc-toast';
			toast.setAttribute( 'role', 'status' );
			toast.innerHTML =
				( imgSrc ? '<img alt="" />' : '' ) +
				'<div class="oc-toast__txt"><strong>' + ( ( window.ocL10n || {} ).addedToCart || 'Added to cart' ) + '</strong><span></span></div>';
			if ( imgSrc ) {
				toast.querySelector( 'img' ).src = imgSrc;
			}
			toast.querySelector( 'span' ).textContent = name || '';

			// Dock under the VISIBLE cart icon (headers carry several cart
			// links — text, icon, mobile — and hidden ones report no box).
			var link = null;
			document.querySelectorAll( '.oc-cart-link' ).forEach( function ( c ) {
				if ( ! link && c.offsetParent && c.getBoundingClientRect().width > 0 ) {
					link = c;
				}
			} );
			if ( link ) {
				var rect = link.getBoundingClientRect();
				if ( rect.left + rect.width / 2 < window.innerWidth / 2 ) {
					toast.style.left = Math.max( 10, rect.left ) + 'px';
					toast.style.right = 'auto';
				} else {
					toast.style.right = Math.max( 10, window.innerWidth - rect.right ) + 'px';
					toast.style.left = 'auto';
				}
				toast.style.top = ( rect.bottom + 10 ) + 'px';
			}

			document.body.appendChild( toast );
			requestAnimationFrame( function () {
				toast.classList.add( 'is-in' );
			} );
			setTimeout( function () {
				toast.classList.remove( 'is-in' );
				setTimeout( function () {
					toast.remove();
				}, 280 );
			}, 3500 );
		}

		/* -- product page add-to-cart goes over ajax: no page reload -- */

		document.addEventListener( 'submit', function ( event ) {
			var form = event.target.closest( '.single-product form.cart' );
			if ( ! form || form.dataset.ocNative ) {
				return;
			}

			// The product id lives on the button (simple) or a hidden field
			// (variable); a form without one is not an add-to-cart form.
			var btn = form.querySelector( '[name="add-to-cart"]' );
			var pidInput = form.querySelector( 'input[name="product_id"], input[name="add-to-cart"]' );
			var productId = ( btn && btn.value ) || ( pidInput && pidInput.value ) || '';
			if ( ! productId ) {
				return;
			}

			// A variable product with nothing chosen keeps Woo's own flow
			// (it explains what is missing).
			var varInput = form.querySelector( 'input[name="variation_id"]' );
			if ( varInput && ( ! varInput.value || '0' === varInput.value ) ) {
				return;
			}

			event.preventDefault();

			var data = new FormData( form );
			// Woo's own form handler runs on every request (wp_loaded) and
			// would add once for this field before oc_cart_add adds again.
			data.delete( 'add-to-cart' );
			data.append( 'action', 'oc_cart_add' );
			data.append( 'product_id', productId );

			var submitBtn = form.querySelector( '.single_add_to_cart_button' );
			var stickyBuy = document.querySelector( '[data-oc-sticky-add]' );

			if ( submitBtn ) {
				submitBtn.classList.add( 'is-loading' );
			}
			if ( stickyBuy ) {
				stickyBuy.classList.add( 'is-loading' );
			}

			fetch( ( window.ocL10n || {} ).ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( submitBtn ) {
						submitBtn.classList.remove( 'is-loading' );
					}
					if ( stickyBuy ) {
						stickyBuy.classList.remove( 'is-loading' );
					}

					if ( ! res || ! res.fragments ) {
						// Validation refused it (stock caps and friends) —
						// the classic flow explains why.
						form.dataset.ocNative = '1';
						form.submit();
						return;
					}

					Object.keys( res.fragments ).forEach( function ( selector ) {
						document.querySelectorAll( selector ).forEach( function ( el ) {
							var box = document.createElement( 'div' );
							box.innerHTML = res.fragments[ selector ];
							if ( box.firstElementChild ) {
								el.replaceWith( box.firstElementChild );
							}
						} );
					} );

					// The buttons answer with the catalogue's own tick —
					// the round icon's check, not a character.
					[ submitBtn, stickyBuy ].forEach( function ( b ) {
						if ( b ) {
							b.classList.add( 'oc-added' );
							setTimeout( function () { b.classList.remove( 'oc-added' ); }, 1600 );
						}
					} );

					if ( openOnAdd ) {
						openDrawer();
					} else {
						var title = document.querySelector( '.product_title' );
						var img = document.querySelector( '.woocommerce-product-gallery img' );
						cartToast( title ? title.textContent.trim() : '', img ? img.currentSrc || img.src : '' );
					}
				} )
				.catch( function () {
					if ( submitBtn ) {
						submitBtn.classList.remove( 'is-loading' );
					}
					if ( stickyBuy ) {
						stickyBuy.classList.remove( 'is-loading' );
					}
					form.dataset.ocNative = '1';
					form.submit();
				} );
		} );

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

		/* -- the savings breakdown folds behind the "you saved" line -- */

		document.addEventListener( 'click', function ( event ) {
			var saveToggle = event.target.closest( '[data-oc-save-toggle]' );
			if ( saveToggle ) {
				saveToggle.closest( '.oc-drawer__savewrap' ).classList.toggle( 'is-save-open' );
			}
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

		function sheetDragClose( card ) {
			// Pull the sheet down by its handle zone (or from a fully
			// scrolled-up list) to dismiss — the native bottom-sheet gesture.
			var startY = 0;
			var delta = 0;
			var dragging = false;

			card.addEventListener( 'touchstart', function ( e ) {
				if ( ! window.matchMedia( '(max-width: 782px)' ).matches ) {
					return;
				}
				startY = e.touches[ 0 ].clientY;
				delta = 0;
				// From the handle strip always; from the body only when the
				// list is scrolled to its top.
				dragging = ( startY - card.getBoundingClientRect().top ) < 36 || card.scrollTop <= 0;
			}, { passive: true } );

			card.addEventListener( 'touchmove', function ( e ) {
				if ( ! dragging ) {
					return;
				}
				delta = e.touches[ 0 ].clientY - startY;
				if ( delta > 0 && card.scrollTop <= 0 ) {
					card.style.transform = 'translateY(' + delta + 'px)';
					card.style.transition = 'none';
					e.preventDefault();
				} else {
					delta = 0;
					card.style.transform = '';
				}
			}, { passive: false } );

			card.addEventListener( 'touchend', function () {
				if ( ! dragging ) {
					return;
				}
				dragging = false;
				card.style.transition = 'transform .22s ease';
				if ( delta > 90 ) {
					card.style.transform = 'translateY(110%)';
					setTimeout( function () {
						closeVarModal();
						card.style.transform = '';
						card.style.transition = '';
					}, 220 );
				} else {
					card.style.transform = '';
					setTimeout( function () {
						card.style.transition = '';
					}, 240 );
				}
			} );
		}

		function openVarPicker( productId, productName, productImg, preloaded ) {
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
				sheetDragClose( varModal.querySelector( '.oc-nmodal__card' ) );
			}

			varModal.querySelector( '.oc-nmodal__title' ).textContent = productName || '';
			var list = varModal.querySelector( '.oc-vmodal__list' );
			varModal.hidden = false;

			// The product page already carries the variations JSON — render
			// instantly instead of waiting a round-trip.
			if ( preloaded && preloaded.length ) {
				list.innerHTML = '';
				preloaded.forEach( function ( v ) {
					varRow( list, productId, productName, productImg, v );
				} );
				return;
			}

			list.innerHTML = '<span class="oc-vmodal__loading">…</span>';

			var data = new FormData();
			data.append( 'action', 'oc_cart_vars' );
			data.append( 'product_id', productId );

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
						varRow( list, productId, productName, productImg, v );
					} );
				} );
		}

		function varRow( list, productId, productName, productImg, v ) {
			( function () {
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
							add.append( 'product_id', productId );
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
									// Picked from inside the open drawer: it
									// already shows the new line — stay quiet.
									if ( out && out.fragments && ! ( drawer && drawer.classList.contains( 'is-open' ) ) ) {
										if ( openOnAdd ) {
											openDrawer();
										} else {
											cartToast( ( productName ? productName + ' — ' : '' ) + v.label, productImg || '' );
										}
									}
								} );
						} );
				list.appendChild( row );
			}() );
		}

		// The sticky bar (outside this closure) opens the picker too.
		window.__ocOpenVarPicker = openVarPicker;
		window.__ocCartToast = cartToast;
		window.__ocOpenDrawer = openOnAdd ? openDrawer : null;

		document.addEventListener( 'click', function ( event ) {
			if ( varModal && ! varModal.hidden && ( event.target.closest( '.oc-nmodal__close' ) || event.target === varModal ) && event.target.closest( '.oc-vmodal' ) ) {
				closeVarModal();
				return;
			}

			var opener = event.target.closest( '[data-oc-up-var]' );
			if ( opener ) {
				var card = opener.closest( '.oc-cartup__item' );
				var cardImg = card ? card.querySelector( 'img' ) : null;
				openVarPicker( opener.dataset.ocUpVar, opener.dataset.name || '', cardImg ? cardImg.currentSrc || cardImg.src : '' );
			}
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
		var target = ( rtl ? -1 : 1 ) * index * mgWrap.clientWidth;

		if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			mgWrap.scrollLeft = target;
			mgUpdateDots( index );
			return;
		}

		// A veil: the stage dips for a breath, the jump happens unseen, and
		// the new slide settles in with a whisper of scale — paging reads as
		// a transition, not a lurch.
		mgWrap.style.transition = 'opacity .16s ease, transform .16s ease';
		mgWrap.style.opacity = '0';
		mgWrap.style.transform = 'scale(.985)';

		setTimeout( function () {
			mgWrap.scrollLeft = target;
			void mgWrap.offsetWidth;
			mgWrap.style.transition = 'opacity .3s cubic-bezier(.2, .7, .2, 1), transform .3s cubic-bezier(.2, .7, .2, 1)';
			mgWrap.style.opacity = '1';
			mgWrap.style.transform = 'scale(1)';

			setTimeout( function () {
				mgWrap.style.transition = '';
				mgWrap.style.transform = '';
			}, 320 );
		}, 170 );

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

	/* The token is this browser's proof that it made the signup: the key is
	 * only the shopper's own address, which anyone might know. */
	function ocMarkSigned( productId, variationId, key, token ) {
		var map = ocSignedMap();
		map[ productId + '|' + ( variationId || 0 ) ] = { k: key || '', t: token || '' };
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

							ocMarkSigned( ocNotifyModal.dataset.product, varsEl ? varsEl.value : 0, res.data && res.data.key ? res.data.key : '', res.data && res.data.token ? res.data.token : '' );
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
						keys.push( { k: map[ k ].k, t: map[ k ].t || '' } );
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

	/* ---------- hover image over a card video ----------
	 * The film's own compositor layer likes to repaint itself above the
	 * hover picture. While the cursor stands on the card the film pauses —
	 * a still stays under the picture — and it plays on when the cursor
	 * leaves. Hover-capable screens only. */

	if ( window.matchMedia( '(hover: hover)' ).matches ) {
		document.addEventListener( 'mouseover', function ( event ) {
			var li = event.target.closest( 'li.product' );

			if ( ! li || ( event.relatedTarget && li.contains( event.relatedTarget ) ) ) {
				return;
			}

			var film = li.querySelector( '.oc-card-media--hover video' );

			if ( film && film.pause ) {
				film.pause();
			}
		} );

		document.addEventListener( 'mouseout', function ( event ) {
			var li = event.target.closest( 'li.product' );

			if ( ! li || ( event.relatedTarget && li.contains( event.relatedTarget ) ) ) {
				return;
			}

			var film = li.querySelector( '.oc-card-media--hover video' );

			if ( film && film.play ) {
				film.play().catch( function () {} );
			}
		} );
	}

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

				/* A finger pages too. Un-zoomed only — zoomed, the pan owns
				 * the touch. In RTL the next picture stands to the left, so
				 * pushing the view rightward advances. */
				var ocSwipeX = 0;
				var ocSwipeY = 0;
				var ocSwipeOn = false;

				ocOverlay.addEventListener( 'pointerdown', function ( event ) {
					if ( 'mouse' === event.pointerType ) {
						return;
					}

					var img = ocOverlay.querySelector( '.oc-voverlay__media img' );

					if ( img && img.classList.contains( 'is-zoomed' ) ) {
						return;
					}

					ocSwipeOn = true;
					ocSwipeX = event.clientX;
					ocSwipeY = event.clientY;
				} );

				ocOverlay.addEventListener( 'pointerup', function ( event ) {
					if ( ! ocSwipeOn ) {
						return;
					}

					ocSwipeOn = false;

					var dx = event.clientX - ocSwipeX;
					var dy = event.clientY - ocSwipeY;

					if ( Math.abs( dx ) < 45 || Math.abs( dx ) < Math.abs( dy ) * 1.2 ) {
						return;
					}

					ocStepOverlay( dx > 0 ? 1 : -1 );
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
				if ( 'file' === ocVideo.kind ) {
					return '<video src="' + ocVideo.loopSrc + '" autoplay muted loop playsinline preload="metadata"></video>';
				}

				// A third-party iframe on first paint costs a connection and
				// drags the load event for seconds. The poster stands in and
				// the real embed swaps in right after the page settles.
				return '<img class="oc-vposter" data-oc-vdefer="' + ocVideo.loopSrc + '" src="' + ( ocVideo.thumb || ocVideoFallbackThumb ) + '" alt="" />';
			};

			var ocVideoDeferSwap = function () {
				document.querySelectorAll( '[data-oc-vdefer]' ).forEach( function ( poster ) {
					var frame = document.createElement( 'iframe' );
					frame.src = poster.dataset.ocVdefer;
					frame.setAttribute( 'allow', 'autoplay; fullscreen' );
					frame.setAttribute( 'tabindex', '-1' );
					frame.setAttribute( 'title', 'video' );
					poster.replaceWith( frame );
				} );
			};

			if ( 'complete' === document.readyState ) {
				setTimeout( ocVideoDeferSwap, 400 );
			} else {
				window.addEventListener( 'load', function () {
					setTimeout( ocVideoDeferSwap, 400 );
				} );
			}

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

	/* Stock verdict for one value of one variation attribute, against the
	 * form's other current choices. 'in' / 'out' only when the answer is
	 * certain; null when it genuinely depends on unchosen attributes (or
	 * the variations JSON is not inlined). Shared by the custom dropdowns
	 * and the button/swatch rows. */
	function ocVarStock( vForm, select, value ) {
		if ( ! vForm.__ocVars ) {
			try {
				vForm.__ocVars = JSON.parse( vForm.dataset.product_variations || 'null' ) || false;
			} catch ( err ) {
				vForm.__ocVars = false;
			}
		}

		var vars = vForm.__ocVars;
		if ( ! vars || ! vars.length ) {
			return null;
		}

		var attrName = select.getAttribute( 'name' );
		var chosen = {};
		Array.prototype.forEach.call( vForm.querySelectorAll( 'table.variations select' ), function ( s ) {
			if ( s !== select ) {
				chosen[ s.getAttribute( 'name' ) ] = s.value;
			}
		} );

		var cands = vars.filter( function ( v ) {
			var a = v.attributes || {};
			if ( '' !== ( a[ attrName ] || '' ) && a[ attrName ] !== value ) {
				return false;
			}
			return Object.keys( chosen ).every( function ( k ) {
				return ! chosen[ k ] || '' === ( a[ k ] || '' ) || a[ k ] === chosen[ k ];
			} );
		} );

		if ( ! cands.length ) {
			return 'out';
		}

		var anyIn = cands.some( function ( v ) {
			return false !== v.is_in_stock;
		} );

		if ( ! anyIn ) {
			return 'out';
		}

		var allIn = cands.every( function ( v ) {
			return false !== v.is_in_stock;
		} );

		var complete = Object.keys( chosen ).every( function ( k ) {
			return '' !== chosen[ k ];
		} );

		return ( complete || allIn ) ? 'in' : null;
	}

	/* ---------- custom dropdown for select-type variation attributes ----------
	 * The native select stays in the DOM (Woo's variation logic reads and
	 * rebuilds it); the visible UI is a listbox styled like the reference —
	 * value + chevron in a framed trigger, option rows with the stock status
	 * riding the row's far end. */

	document.querySelectorAll( '.single-product form.variations_form table.variations td.value > select' ).forEach( function ( select ) {
		var sib = select.nextElementSibling;
		if ( sib && ( sib.classList.contains( 'oc-var' ) || sib.classList.contains( 'oc-dd' ) ) ) {
			return;
		}

		var ddForm = select.closest( 'form.variations_form' );
		var ddVars = null;
		try {
			ddVars = JSON.parse( ddForm.dataset.product_variations || 'null' ) || null;
		} catch ( err ) {
			ddVars = null;
		}

		var L = window.ocL10n || {};
		var attrName = select.getAttribute( 'name' );
		var placeholder = select.options.length && ! select.options[ 0 ].value ? select.options[ 0 ].text : '';

		// Full option snapshot before Woo starts filtering the select down
		// to the currently possible combinations.
		var opts = Array.prototype.filter.call( select.options, function ( o ) {
			return '' !== o.value;
		} ).map( function ( o ) {
			return { value: o.value, label: o.text };
		} );

		if ( ! opts.length ) {
			return;
		}

		var dd = document.createElement( 'div' );
		dd.className = 'oc-dd';
		dd.innerHTML =
			'<button type="button" class="oc-dd__t" aria-haspopup="listbox" aria-expanded="false">' +
			'<span class="oc-dd__val"></span>' +
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>' +
			'</button>' +
			'<div class="oc-dd__panel" role="listbox" hidden></div>';
		select.insertAdjacentElement( 'afterend', dd );

		var ddRow = select.closest( 'tr' );
		if ( ddRow ) {
			ddRow.classList.add( 'oc-tr-dd' );
		}

		var trig = dd.querySelector( '.oc-dd__t' );
		var valEl = dd.querySelector( '.oc-dd__val' );
		var panel = dd.querySelector( '.oc-dd__panel' );

		function stockFor( value ) {
			var verdict = ocVarStock( ddForm, select, value );
			if ( 'out' === verdict ) {
				return { txt: L.outStock || 'Out of stock', off: true };
			}
			if ( 'in' === verdict ) {
				return { txt: L.inStock || 'In stock', off: false };
			}
			return null;
		}

		function render() {
			panel.innerHTML = '';

			// Values Woo currently allows (it prunes the select's options as
			// the other attributes get chosen).
			var avail = {};
			Array.prototype.forEach.call( select.options, function ( o ) {
				if ( o.value ) {
					avail[ o.value ] = true;
				}
			} );

			opts.forEach( function ( o ) {
				var st = stockFor( o.value );
				// Only a value Woo pruned (impossible combination) locks; an
				// out-of-stock one stays choosable — that is how the visitor
				// reaches the back-in-stock signup.
				var off = ! avail[ o.value ];

				var row = document.createElement( 'button' );
				row.type = 'button';
				row.className = 'oc-dd__opt' + ( off ? ' is-off' : '' ) + ( st && st.off ? ' is-oos' : '' ) + ( select.value === o.value ? ' is-selected' : '' );
				row.setAttribute( 'role', 'option' );
				row.innerHTML = '<span></span>' + ( st ? '<em class="oc-dd__stock' + ( st.off ? ' is-out' : '' ) + '"></em>' : '' );
				row.firstChild.textContent = o.label;
				if ( st ) {
					row.lastChild.textContent = st.txt;
				}

				if ( off ) {
					row.disabled = true;
				} else {
					row.addEventListener( 'click', function () {
						select.value = select.value === o.value ? '' : o.value;
						select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
						closeDd();
					} );
				}

				panel.appendChild( row );
			} );
		}

		function syncVal() {
			var current = null;
			opts.forEach( function ( o ) {
				if ( o.value === select.value ) {
					current = o;
				}
			} );
			valEl.textContent = current ? current.label : placeholder;
			dd.classList.toggle( 'is-empty', ! current );

			// A chosen value that is out of stock says so right on the
			// trigger, quietly.
			var oos = current && 'out' === ocVarStock( ddForm, select, current.value );
			var tag = trig.querySelector( '.oc-dd__t-stock' );
			if ( oos && ! tag ) {
				tag = document.createElement( 'em' );
				tag.className = 'oc-dd__t-stock';
				tag.textContent = L.outStock || 'Out of stock';
				valEl.insertAdjacentElement( 'afterend', tag );
			} else if ( ! oos && tag ) {
				tag.remove();
			}
		}

		function openDd() {
			render();
			panel.hidden = false;
			dd.classList.add( 'is-open' );
			trig.setAttribute( 'aria-expanded', 'true' );
		}

		function closeDd() {
			panel.hidden = true;
			dd.classList.remove( 'is-open' );
			trig.setAttribute( 'aria-expanded', 'false' );
		}

		trig.addEventListener( 'click', function () {
			if ( panel.hidden ) {
				openDd();
			} else {
				closeDd();
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! dd.contains( e.target ) ) {
				closeDd();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				closeDd();
			}
		} );

		// The verdict depends on the OTHER attributes too, so any change in
		// the form refreshes the trigger.
		ddForm.addEventListener( 'change', syncVal );
		syncVal();
	} );

	/* ---------- missing-choice guidance instead of the browser alert ----------
	 * Add-to-cart with an unchosen attribute: glide to the first empty row
	 * and say what is missing under it, in place of Woo's window.alert. */

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.single_add_to_cart_button' );
		if ( ! btn ) {
			return;
		}

		var vForm = btn.closest( 'form.variations_form' );
		if ( ! vForm ) {
			return;
		}

		var missing = [];
		Array.prototype.forEach.call( vForm.querySelectorAll( 'table.variations tr:not(.oc-row-auto) td.value > select' ), function ( sel ) {
			if ( '' === sel.value ) {
				missing.push( sel );
			}
		} );

		if ( ! missing.length ) {
			return;
		}

		// Ours now — Woo's handler (and its alert) never runs.
		event.preventDefault();
		event.stopPropagation();

		vForm.querySelectorAll( '.oc-var-need' ).forEach( function ( el ) {
			el.remove();
		} );
		vForm.querySelectorAll( 'tr.oc-tr-need' ).forEach( function ( el ) {
			el.classList.remove( 'oc-tr-need' );
		} );

		// Every unfilled attribute gets its note at once; the page glides
		// to the first of them.
		var firstRow = null;

		missing.forEach( function ( sel ) {
			var row = sel.closest( 'tr' );
			var cell = sel.closest( 'td.value' );
			var labelEl = row ? row.querySelector( 'th.label label' ) : null;
			var label = labelEl ? labelEl.textContent.trim() : '';

			if ( row ) {
				row.classList.add( 'oc-tr-need' );
				if ( ! firstRow ) {
					firstRow = row;
				}
			}

			var msg = document.createElement( 'p' );
			msg.className = 'oc-var-need';
			msg.textContent = ( ( window.ocL10n || {} ).varNeed || 'Please choose %s' ).replace( '%s', label );
			if ( cell ) {
				cell.appendChild( msg );
			}
		} );

		( firstRow || vForm ).scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}, true );

	// The moment the visitor picks something, the guidance goes away.
	document.addEventListener( 'change', function ( event ) {
		var sel = event.target.closest( 'form.variations_form table.variations select' );
		if ( ! sel || '' === sel.value ) {
			return;
		}
		var row = sel.closest( 'tr' );
		if ( row && row.classList.contains( 'oc-tr-need' ) ) {
			row.classList.remove( 'oc-tr-need' );
			row.querySelectorAll( '.oc-var-need' ).forEach( function ( el ) {
				el.remove();
			} );
		}
	} );

	// Two-per-row layout (a design setting) only makes sense when there
	// actually are two dropdown rows.
	document.querySelectorAll( '.single-product form.variations_form table.variations' ).forEach( function ( t ) {
		if ( t.querySelectorAll( 'tr.oc-tr-dd' ).length >= 2 ) {
			t.classList.add( 'oc-dd-2' );
		}
	} );

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
			var syncForm = box.closest( 'form.variations_form' );

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

				// Certainly out of stock: the Lanvin slash. Still clickable —
				// choosing it is the road to the back-in-stock signup.
				btn.classList.toggle( 'is-oos', ! btn.disabled && syncForm && 'out' === ocVarStock( syncForm, select, btn.dataset.value ) );
			} );
		}

		// Verdicts shift with the form's other choices too.
		var oosForm = box.closest( 'form.variations_form' );
		if ( oosForm ) {
			oosForm.addEventListener( 'change', function () {
				setTimeout( sync, 0 );
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

		// Only swatch rows echo the chosen value by the label — a dot says
		// nothing by itself. Buttons and dropdowns already show their text.
		if ( ! tr.querySelector( '.oc-var--swatch, .oc-var--swatch_image' ) ) {
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

		var isVariable = '1' === bar.dataset.variable;
		var buy = bar.querySelector( '[data-oc-sticky-add]' );
		var priceEl = bar.querySelector( '[data-oc-sticky-price]' );
		var basePrice = priceEl ? priceEl.innerHTML : '';
		var stickySelects = Array.prototype.slice.call( bar.querySelectorAll( '[data-oc-sticky-attr]' ) );
		var submit = form.querySelector( '[type="submit"]' );

		var variations = null;
		if ( isVariable ) {
			try {
				variations = JSON.parse( form.dataset.product_variations || 'null' );
			} catch ( err ) {
				variations = null;
			}
		}

		function formSelect( field ) {
			return form.querySelector( 'select[name="' + field + '"]' );
		}

		function pageImage() {
			var img = document.querySelector( '.woocommerce-product-gallery img' );
			return img ? img.currentSrc || img.src : '';
		}

		// The picker rows, built from the form's own variations JSON — the
		// sheet renders instantly, no round-trip. Labels come from the sticky
		// selects' option text, swatches from their slug→style maps.
		function preloadedVariations() {
			if ( ! variations || ! variations.length ) {
				return null;
			}

			var names = {};
			var maps = {};
			stickySelects.forEach( function ( sel ) {
				var m = {};
				Array.prototype.forEach.call( sel.options, function ( o ) {
					if ( o.value ) {
						m[ o.value ] = o.text;
					}
				} );
				names[ sel.dataset.ocStickyAttr ] = m;
				try {
					maps[ sel.dataset.ocStickyAttr ] = JSON.parse( sel.dataset.swatches || '{}' );
				} catch ( err ) {
					maps[ sel.dataset.ocStickyAttr ] = {};
				}
			} );

			var out = [];
			variations.forEach( function ( v ) {
				if ( false === v.is_in_stock || false === v.is_purchasable ) {
					return;
				}
				var parts = [];
				var swatch = '';
				Object.keys( v.attributes || {} ).forEach( function ( key ) {
					var slug = v.attributes[ key ];
					if ( ! slug ) {
						return;
					}
					parts.push( ( names[ key ] || {} )[ slug ] || slug );
					if ( ! swatch && maps[ key ] && maps[ key ][ slug ] ) {
						swatch = maps[ key ][ slug ];
					}
				} );

				var box = document.createElement( 'div' );
				box.innerHTML = v.price_html || '';
				box.querySelectorAll( '.oc-price-badge, .oc-sku' ).forEach( function ( el ) {
					el.remove();
				} );
				var ins = box.querySelector( 'ins' );

				out.push( {
					id: v.variation_id,
					label: parts.join( ' / ' ),
					price: ( ins || box ).textContent.trim(),
					swatch: swatch
				} );
			} );

			return out.length ? out : null;
		}

		function paintDot( sel ) {
			var dot = sel.parentElement.querySelector( '.oc-sticky-atc__dot' );
			if ( ! dot ) {
				return;
			}
			var map = {};
			try {
				map = JSON.parse( sel.dataset.swatches || '{}' );
			} catch ( err ) {
				map = {};
			}
			var style = sel.value && map[ sel.value ] ? map[ sel.value ] : '';
			dot.hidden = ! style;
			dot.setAttribute( 'style', style );
		}

		// The bar's selects mirror the form's — a fully chosen combination is
		// resolved locally against Woo's variations JSON.
		function resolveVariation() {
			if ( ! variations || ! stickySelects.length ) {
				return null;
			}
			var chosen = {};
			var complete = true;
			stickySelects.forEach( function ( sel ) {
				if ( ! sel.value ) {
					complete = false;
				}
				chosen[ sel.dataset.ocStickyAttr ] = sel.value;
			} );
			if ( ! complete ) {
				return null;
			}
			return variations.find( function ( v ) {
				return Object.keys( v.attributes ).every( function ( key ) {
					return '' === v.attributes[ key ] || v.attributes[ key ] === chosen[ key ];
				} );
			} ) || null;
		}

		function cleanPrice( html ) {
			// The product-page price filters ride sale badge and SKU along —
			// the sticky bar wants the bare amount.
			var box = document.createElement( 'div' );
			box.innerHTML = html;
			box.querySelectorAll( '.oc-price-badge, .oc-sku' ).forEach( function ( el ) {
				el.remove();
			} );
			return box.innerHTML;
		}

		function updatePrice() {
			if ( ! priceEl ) {
				return;
			}
			var v = resolveVariation();
			priceEl.innerHTML = cleanPrice( v && v.price_html ? v.price_html : basePrice );
		}

		if ( priceEl ) {
			priceEl.innerHTML = cleanPrice( basePrice );
		}

		stickySelects.forEach( function ( sel ) {
			var main = formSelect( sel.dataset.ocStickyAttr );

			// Adopt whatever the form already chose (per-product defaults).
			if ( main && main.value ) {
				sel.value = main.value;
			}
			paintDot( sel );

			sel.addEventListener( 'change', function () {
				if ( main && main.value !== sel.value ) {
					main.value = sel.value;
					// Woo's variation script listens for this and resolves
					// variation_id / gallery / stock on the main form.
					main.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
				paintDot( sel );
				updatePrice();

				// The form's swatch UI may veto the change (e.g. snap back to
				// a default) without an event — adopt whatever it settled on.
				if ( main ) {
					setTimeout( function () {
						if ( sel.value !== main.value ) {
							sel.value = main.value;
							paintDot( sel );
							updatePrice();
						}
					}, 150 );
				}
			} );

			if ( main ) {
				main.addEventListener( 'change', function () {
					if ( sel.value !== main.value ) {
						sel.value = main.value;
					}
					paintDot( sel );
					updatePrice();
				} );
			}
		} );

		updatePrice();

		if ( buy ) {
			buy.addEventListener( 'click', function () {
				if ( ! isVariable ) {
					// The form's own submit path already adds over ajax and
					// shows the toast / opens the drawer.
					if ( submit && ! submit.disabled ) {
						submit.click();
					}
					return;
				}

				var v = resolveVariation();
				var mobile = window.matchMedia( '(max-width: 782px)' ).matches;

				// Mobile always gets the picker sheet — silently buying a
				// preselected default is how wrong sizes get ordered.
				if ( mobile || ! v ) {
					if ( window.__ocOpenVarPicker ) {
						window.__ocOpenVarPicker( bar.dataset.product, buy.dataset.name || '', pageImage(), preloadedVariations() );
					} else {
						form.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					}
					return;
				}

				// Desktop with everything chosen: let Woo's form state settle,
				// then go through the form's ajax submit.
				setTimeout( function () {
					var varInput = form.querySelector( 'input[name="variation_id"]' );
					if ( varInput && ( ! varInput.value || '0' === varInput.value ) ) {
						varInput.value = String( v.variation_id );
					}
					if ( submit && ! submit.disabled ) {
						submit.click();
					} else if ( window.__ocOpenVarPicker ) {
						window.__ocOpenVarPicker( bar.dataset.product, buy.dataset.name || '', pageImage(), preloadedVariations() );
					}
				}, 60 );
			} );
		}
	}

	/* ---------- quick pick: a card's add-to-cart for a product with options ----------
	 * A quick view in the product page's own clothes: a full-width gallery
	 * with arrows and dots wearing the configured corners, the card labels
	 * over the title, the star rating (a door to the reviews section), price
	 * facing SKU, a rule, then the options — the same .oc-var swatches and
	 * buttons, the same .oc-colors sibling row. The foot pins the stock line
	 * above price, quantity and add; an out-of-stock product offers the
	 * back-in-stock signup instead. On a phone it is a sheet with a handle. */

	( function () {
		var L = window.ocL10n || {};
		var vp = null;
		var st = null;
		var vpWant = '';

		function vpEl( tag, cls, text ) {
			var n = document.createElement( tag );
			if ( cls ) { n.className = cls; }
			if ( text ) { n.textContent = text; }
			return n;
		}

		function vpBuild() {
			vp = vpEl( 'div', 'oc-vp oc-vp--' + ( L.vpSide === 'left' ? 'left' : 'right' ) + ' oc-vp--c-' + ( L.vpCorners || 'soft' ) + ' oc-vp--g-' + ( L.vpGallery || 'peek' ) );
			vp.hidden = true;
			vp.innerHTML =
				'<div class="oc-vp__dim" data-vp-close></div>' +
				'<aside class="oc-vp__panel" role="dialog" aria-modal="true">' +
					'<button type="button" class="oc-vp__close" data-vp-close aria-label="close">&times;</button>' +
					'<div class="oc-vp__skel" aria-hidden="true">' +
						'<div class="oc-vp__skel-img"></div>' +
						'<div class="oc-vp__skel-line" style="inline-size:60%"></div>' +
						'<div class="oc-vp__skel-line" style="inline-size:35%"></div>' +
						'<div class="oc-vp__skel-line" style="inline-size:78%"></div>' +
					'</div>' +
					'<div class="oc-vp__scroll">' +
						'<div class="oc-vp__gal">' +
							'<div class="oc-vp__strip"></div>' +
							'<button type="button" class="oc-vp__arr oc-vp__arr--prev" data-vp-go="-1" aria-label="prev"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg></button>' +
							'<button type="button" class="oc-vp__arr oc-vp__arr--next" data-vp-go="1" aria-label="next"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg></button>' +
							'<div class="oc-vp__dots"></div>' +
						'</div>' +
						'<div class="oc-vp__body">' +
							'<div class="oc-vp__top">' +
							'<img class="oc-vp__thumb" alt="" hidden />' +
							'<div class="oc-vp__idcol">' +
							'<div class="oc-vp__flags"></div>' +
							'<a class="oc-vp__name" href="#"></a>' +
							'<a class="oc-vp__stars" href="#"></a>' +
							'<div class="oc-vp__priceline">' +
								'<div class="oc-vp__price"></div>' +
								'<span class="oc-vp__sku"></span>' +
							'</div>' +
							'</div>' +
							'</div>' +
							'<div class="oc-vp__blurb"></div>' +
							'<hr class="oc-vp__sep" />' +
							'<div class="oc-vp__groups"></div>' +
							'<a class="oc-vp__go" href="#"></a>' +
						'</div>' +
					'</div>' +
					'<div class="oc-vp__foot">' +
						'<div class="oc-vp__stock"></div>' +
						'<div class="oc-vp__ctrls">' +
							'<div class="oc-vp__fprice"></div>' +
							'<div class="oc-vp__qty" hidden>' +
								'<button type="button" class="oc-qty-btn" data-vp-q="-1">&minus;</button>' +
								'<input type="number" class="qty" min="1" value="1" inputmode="numeric" />' +
								'<button type="button" class="oc-qty-btn" data-vp-q="1">+</button>' +
							'</div>' +
							'<button type="button" class="oc-vp__add"></button>' +
						'</div>' +
					'</div>' +
				'</aside>';
			document.body.appendChild( vp );

			vp.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '[data-vp-close]' ) ) {
					vpClose();
				}

				var q = e.target.closest( '[data-vp-q]' );

				if ( q ) {
					var input = vp.querySelector( '.oc-vp__qty input' );
					input.value = String( Math.max( 1, ( Number( input.value ) || 1 ) + Number( q.dataset.vpQ ) ) );
				}

				var arr = e.target.closest( '[data-vp-go]' );

				if ( arr ) {
					var strip = vp.querySelector( '.oc-vp__strip' );
					// Physical: the left arrow always shows what stands to
					// the left. scrollBy speaks pixels, not reading order.
					strip.scrollBy( { left: vpStep() * Number( arr.dataset.vpGo ), behavior: 'smooth' } );
				}

				/* A colour sibling is another product wearing this panel:
				 * switching colour re-dresses the panel, no page leaves. */
				var sib = e.target.closest( '.oc-vp .oc-colors__item' );

				if ( sib ) {
					e.preventDefault();

					if ( ! sib.classList.contains( 'is-current' ) && sib.dataset.pid ) {
						vpLoad( sib.dataset.pid, true );
					}
				}
			} );

			var galStrip = vp.querySelector( '.oc-vp__strip' );
			var dotTick = false;

			galStrip.addEventListener( 'scroll', function () {
				if ( ! dotTick ) {
					dotTick = true;
					requestAnimationFrame( function () {
						dotTick = false;
						vpDots();
					} );
				}
			}, { passive: true } );

			document.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' && vp && ! vp.hidden ) {
					vpClose();
				}
			} );

			vp.querySelector( '.oc-vp__add' ).addEventListener( 'click', function () {
				var btn = vp.querySelector( '.oc-vp__add' );

				if ( btn.dataset.mode === 'notify' ) {
					if ( typeof ocOpenNotify === 'function' ) {
						ocOpenNotify( st.id, vp.querySelector( '.oc-vp__name' ).textContent, ! st.simple, st.notifyVar || 0 );
					}
					return;
				}

				vpAdd();
			} );

			vpDrag( vp.querySelector( '.oc-vp__panel' ) );
		}

		/* The sheet's native gesture: pull it down by its handle, or from a
		 * body scrolled to its top, and it goes home. */
		function vpDrag( panel ) {
			var startY = 0;
			var startX = 0;
			var delta = 0;
			var dragging = false;
			var decided = false;
			var scroll = panel.querySelector( '.oc-vp__scroll' );

			panel.addEventListener( 'touchstart', function ( e ) {
				if ( ! window.matchMedia( '(max-width: 782px)' ).matches ) {
					return;
				}

				startY = e.touches[ 0 ].clientY;
				startX = e.touches[ 0 ].clientX;
				delta = 0;
				decided = false;

				// The gallery strip scrolls sideways on its own — a touch
				// born there belongs to it, not to the sheet.
				var handle = ( startY - panel.getBoundingClientRect().top ) < 40;
				dragging = handle || ( scroll.scrollTop <= 0 && ! e.target.closest( '.oc-vp__strip' ) );
			}, { passive: true } );

			panel.addEventListener( 'touchmove', function ( e ) {
				if ( ! dragging ) {
					return;
				}

				delta = e.touches[ 0 ].clientY - startY;

				// The first clear direction wins the gesture: sideways means
				// it was never a pull.
				if ( ! decided ) {
					var dx = Math.abs( e.touches[ 0 ].clientX - startX );

					if ( dx < 6 && Math.abs( delta ) < 6 ) {
						return;
					}

					decided = true;

					if ( dx > Math.abs( delta ) ) {
						dragging = false;
						return;
					}
				}

				if ( delta > 0 && scroll.scrollTop <= 0 ) {
					panel.style.transform = 'translateY(' + delta + 'px)';
					panel.style.transition = 'none';
					e.preventDefault();
				} else {
					delta = 0;
					panel.style.transform = '';
				}
			}, { passive: false } );

			panel.addEventListener( 'touchend', function () {
				if ( ! dragging ) {
					return;
				}

				dragging = false;
				panel.style.transition = '';

				if ( delta > 90 ) {
					vpClose();
				}

				panel.style.transform = '';
			} );
		}

		function vpClose() {
			if ( vp ) {
				vp.classList.remove( 'is-open' );
				setTimeout( function () { vp.hidden = true; }, 240 );
			}
		}

		/* ----- the gallery ----- */

		function vpSlides() {
			return [].filter.call( vp.querySelectorAll( '.oc-vp__slide' ), function ( sl ) {
				return ! sl.hidden;
			} );
		}

		/* One slide's stride: its width plus the gap between slides. */
		function vpStep() {
			var slide = vp.querySelector( '.oc-vp__slide:not([hidden])' );

			return slide ? slide.offsetWidth + 10 : vp.querySelector( '.oc-vp__strip' ).clientWidth;
		}

		function vpDots() {
			var strip = vp.querySelector( '.oc-vp__strip' );
			var dots = vp.querySelector( '.oc-vp__dots' );
			var slides = vpSlides();

			if ( dots.children.length !== slides.length ) {
				dots.innerHTML = '';
				slides.forEach( function ( sl, i ) {
					var d = vpEl( 'button', 'oc-vp__dot' );
					d.type = 'button';
					d.addEventListener( 'click', function () {
						var rtl = getComputedStyle( strip ).direction === 'rtl';
						strip.scrollTo( { left: ( rtl ? -1 : 1 ) * i * vpStep(), behavior: 'smooth' } );
					} );
					dots.appendChild( d );
				} );
			}

			var at = Math.round( Math.abs( strip.scrollLeft ) / Math.max( 1, vpStep() ) );

			[].forEach.call( dots.children, function ( d, i ) {
				d.classList.toggle( 'is-on', i === Math.min( at, dots.children.length - 1 ) );
			} );

			vp.querySelector( '.oc-vp__gal' ).classList.toggle( 'oc-vp__gal--one', slides.length < 2 );

			/* An arrow with nowhere left to go steps back into grey. In RTL
			 * the strip scrolls into negative territory — the absolute
			 * distance from the start is the honest measure. */
			var max = strip.scrollWidth - strip.clientWidth;
			var gone = Math.abs( strip.scrollLeft );
			var rtl = getComputedStyle( strip ).direction === 'rtl';
			var atStart = gone < 2;
			var atEnd = gone > max - 2;

			vp.querySelector( '.oc-vp__arr--prev' ).classList.toggle( 'is-off', rtl ? atEnd : atStart );
			vp.querySelector( '.oc-vp__arr--next' ).classList.toggle( 'is-off', rtl ? atStart : atEnd );
		}

		function vpGallery( imgs ) {
			var strip = vp.querySelector( '.oc-vp__strip' );
			strip.innerHTML = '';

			// Slide zero belongs to the resolved variation, silent until one
			// brings its own picture.
			var vslide = vpEl( 'div', 'oc-vp__slide oc-vp__slide--var' );
			vslide.hidden = true;
			vslide.appendChild( vpEl( 'img' ) );
			strip.appendChild( vslide );

			( imgs || [] ).forEach( function ( u, i ) {
				var sl = vpEl( 'div', 'oc-vp__slide' );
				var im = vpEl( 'img' );
				// Decoding on the main thread mid-entrance is the stutter;
				// the slides past the first can also wait their turn.
				im.decoding = 'async';
				if ( i > 0 ) { im.loading = 'lazy'; }
				im.src = u;
				im.alt = '';
				sl.appendChild( im );
				strip.appendChild( sl );
			} );

			strip.scrollLeft = 0;
			vpDots();
		}

		function vpVarImage( url ) {
			var strip = vp.querySelector( '.oc-vp__strip' );
			var vslide = strip.querySelector( '.oc-vp__slide--var' );
			var show = !! url && st.imgs.indexOf( url ) === -1;

			if ( show ) {
				vslide.querySelector( 'img' ).src = url;
			}

			if ( vslide.hidden !== ! show ) {
				vslide.hidden = ! show;
			}

			vpDots();

			// Sideways only. scrollIntoView also drags every scrolling
			// ancestor upward to the gallery — the chip just clicked would
			// yank the whole panel to the top.
			if ( show ) {
				strip.scrollTo( { left: 0, behavior: 'smooth' } );
			}
		}

		/* ----- selection ----- */

		/* The variations a partial selection still allows, one group held
		 * out. Asked twice per chip: any at all, and any still in stock —
		 * the difference is the Lanvin slash. */
		function vpAllows( skipKey, withValue, needStock ) {
			return st.vars.some( function ( v ) {
				if ( needStock && ! v.stock ) {
					return false;
				}

				return st.groups.every( function ( g ) {
					var want = g.key === skipKey ? withValue : st.sel[ g.key ];
					return ! want || ! v.attrs[ g.key ] || v.attrs[ g.key ] === want;
				} );
			} );
		}

		function vpMatch( needStock ) {
			if ( st.simple ) {
				return st.buy || ! needStock ? { id: 0, price: '', img: '', stock: st.buy } : null;
			}

			if ( ! st.groups.every( function ( g ) { return st.sel[ g.key ]; } ) ) {
				return null;
			}

			return st.vars.find( function ( v ) {
				return ( ! needStock || v.stock ) && st.groups.every( function ( g ) {
					return ! v.attrs[ g.key ] || v.attrs[ g.key ] === st.sel[ g.key ];
				} );
			} ) || null;
		}

		function vpButton( mode, varId ) {
			var btn = vp.querySelector( '.oc-vp__add' );

			st.notifyVar = varId || 0;
			btn.classList.toggle( 'oc-vp__add--notify', mode === 'notify' );

			if ( mode === 'notify' ) {
				btn.dataset.mode = 'notify';
				btn.textContent = L.notifyButton || L.vpAdd || '';
				btn.disabled = false;
			} else {
				delete btn.dataset.mode;
				btn.textContent = L.vpAdd || 'Add to cart';

				/* Full colour even before a choice is made — a click with an
				 * unanswered question gets guidance, not a greyed-out shrug. */
				btn.disabled = false;
			}

			// A stopped clock needs no quantity.
			vp.querySelector( '.oc-vp__qty' ).hidden = ! st.qty || mode === 'notify';
		}

		function vpPaint() {
			vp.querySelectorAll( '[data-k]' ).forEach( function ( chip ) {
				var any = vpAllows( chip.dataset.k, chip.dataset.v, false );
				var live = vpAllows( chip.dataset.k, chip.dataset.v, true );

				chip.classList.toggle( 'is-selected', st.sel[ chip.dataset.k ] === chip.dataset.v );
				chip.classList.toggle( 'is-off', ! any );
				chip.classList.toggle( 'is-oos', any && ! live );
			} );

			var live = vpMatch( true );
			var any = live || vpMatch( false );
			var price = vp.querySelector( '.oc-vp__price' );

			// WooCommerce answers '' for a variation priced like its
			// siblings — the product's own price line covers that silence.
			price.innerHTML = ( any && any.price ) ? any.price : st.price;
			vp.querySelector( '.oc-vp__fprice' ).innerHTML = price.innerHTML;
			vpVarImage( any && any.img ? any.img : '' );

			if ( ! st.buy ) {
				vpButton( 'notify', 0 );
			} else if ( live ) {
				vpButton( 'add', 0 );
			} else if ( any ) {
				// Chosen, exists, and gone: the road to the signup.
				vpButton( 'notify', any.id );
			} else {
				vpButton( 'wait', 0 );
			}
		}

		function vpRender( d, productId ) {
			vp.classList.remove( 'is-loading' );
			st = { id: productId, sel: {}, groups: d.groups, vars: d.vars, price: d.price, img: d.img, imgs: d.imgs || [], simple: !! d.simple, buy: !! d.buy, qty: !! d.qty, notifyVar: 0 };

			vp.querySelector( '.oc-vp__name' ).textContent = d.name;
			vp.querySelector( '.oc-vp__name' ).href = d.url;
			vp.querySelector( '.oc-vp__go' ).textContent = L.vpGo || '';
			vp.querySelector( '.oc-vp__go' ).href = d.url;
			vp.querySelector( '.oc-vp__flags' ).innerHTML = d.flags || '';
			vp.querySelector( '.oc-vp__blurb' ).innerHTML = d.blurb || '';
			vp.querySelector( '.oc-vp__stock' ).innerHTML = d.stock || '';
			vp.querySelector( '.oc-vp__qty input' ).value = '1';

			var thumb = vp.querySelector( '.oc-vp__thumb' );
			thumb.hidden = ! ( L.vpGallery === 'small' && d.img );
			thumb.src = d.img || '';

			var stars = vp.querySelector( '.oc-vp__stars' );

			if ( d.rating && d.rating.count > 0 ) {
				stars.innerHTML = d.rating.html + '<span>' + ( L.vpReviews || '%s' ).replace( '%s', String( d.rating.count ) ) + '</span>';
				stars.href = d.rating.url;
				stars.hidden = false;
			} else {
				stars.hidden = true;
			}

			var sku = vp.querySelector( '.oc-vp__sku' );
			sku.textContent = d.sku ? ( L.vpSku || 'SKU:' ) + ' ' + d.sku : '';
			sku.hidden = ! d.sku;

			vpGallery( st.imgs );

			var box = vp.querySelector( '.oc-vp__groups' );
			box.innerHTML = '';

			/* The colour siblings stand first, exactly the row the product
			 * page shows, under the same label. */
			if ( d.colors && d.colors.row ) {
				var cwrap = vpEl( 'div', 'oc-vp__group' );
				cwrap.appendChild( vpEl( 'h4', 'oc-vp__glabel', d.colors.label || '' ) );
				var crow = vpEl( 'div' );
				crow.innerHTML = d.colors.row;
				cwrap.appendChild( crow.firstElementChild );
				box.appendChild( cwrap );
			}

			d.groups.forEach( function ( g ) {
				// A single-value colour on a product whose colours live as
				// siblings: answered silently, the siblings row speaks.
				if ( g.auto ) {
					st.sel[ g.key ] = g.options[ 0 ].slug;
					return;
				}

				var wrap = vpEl( 'div', 'oc-vp__group' );
				wrap.dataset.g = g.key;
				wrap.appendChild( vpEl( 'h4', 'oc-vp__glabel', g.label ) );
				var row = vpEl( 'div', 'oc-var oc-var--' + ( g.type === 'swatch' ? 'swatch' : 'button' ) );

				g.options.forEach( function ( o ) {
					var chip;

					if ( g.type === 'swatch' && o.swatch ) {
						chip = vpEl( 'button', 'oc-var__swatch' );
						chip.setAttribute( 'style', o.swatch );
						chip.title = o.label;
						chip.setAttribute( 'aria-label', o.label );
					} else if ( g.type === 'swatch' ) {
						chip = vpEl( 'button', 'oc-var__swatch oc-var__swatch--txt', o.label.charAt( 0 ) );
						chip.title = o.label;
					} else {
						chip = vpEl( 'button', 'oc-var__btn', o.label );
					}

					chip.type = 'button';
					chip.dataset.k = g.key;
					chip.dataset.v = o.slug;
					chip.addEventListener( 'click', function () {
						st.sel[ g.key ] = st.sel[ g.key ] === o.slug ? '' : o.slug;
						vpPaint();

						if ( st.sel[ g.key ] ) {
							wrap.classList.remove( 'oc-tr-need' );
							wrap.querySelectorAll( '.oc-var-need' ).forEach( function ( el ) { el.remove(); } );
						}

						// Answering one question reveals the next: whatever
						// follows this group slides up into view.
						var after = chip.closest( '.oc-vp__group' );
						after = after && after.nextElementSibling;

						if ( after ) {
							after.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
						}
					} );
					row.appendChild( chip );
				} );

				wrap.appendChild( row );
				box.appendChild( wrap );

				// One option is no question — answer it.
				if ( g.options.length === 1 ) {
					st.sel[ g.key ] = g.options[ 0 ].slug;
				}
			} );

			/* The colour arrives answered: the card's current colour when it
			 * matches, the first otherwise — the same default the catalogue
			 * and the product page show. */
			st.groups.forEach( function ( g ) {
				if ( g.auto || 'swatch' !== g.type || st.sel[ g.key ] || ! g.options.length ) {
					return;
				}

				var want = '';

				g.options.forEach( function ( o ) {
					if ( o.slug === vpWant ) {
						want = o.slug;
					}
				} );

				st.sel[ g.key ] = want || g.options[ 0 ].slug;
			} );
			vpWant = '';

			vpPaint();
		}

		/* The product page's manners, worn by the panel: every unanswered
		 * attribute gets a red note under its row and the panel glides to
		 * the first of them — in place of a silent nothing. */
		function vpNeed() {
			vp.querySelectorAll( '.oc-var-need' ).forEach( function ( el ) { el.remove(); } );
			vp.querySelectorAll( '.oc-vp__group.oc-tr-need' ).forEach( function ( el ) { el.classList.remove( 'oc-tr-need' ); } );

			var first = null;

			st.groups.forEach( function ( g ) {
				if ( g.auto || st.sel[ g.key ] ) { return; }

				var wrap = vp.querySelector( '.oc-vp__group[data-g="' + g.key + '"]' );

				if ( ! wrap ) { return; }

				wrap.classList.add( 'oc-tr-need' );

				var msg = document.createElement( 'p' );
				msg.className = 'oc-var-need';
				msg.textContent = ( L.varNeed || 'Please choose %s' ).replace( '%s', g.label );
				wrap.appendChild( msg );

				if ( ! first ) { first = wrap; }
			} );

			if ( first ) { first.scrollIntoView( { behavior: 'smooth', block: 'center' } ); }
		}

		function vpAdd() {
			var hit = vpMatch( true );

			if ( ! hit ) {
				if ( ! st.simple && ! st.groups.every( function ( g ) { return g.auto || st.sel[ g.key ]; } ) ) {
					vpNeed();
				}

				return;
			}

			var btn = vp.querySelector( '.oc-vp__add' );
			btn.classList.add( 'is-busy' );

			var data = new FormData();
			data.append( 'action', 'oc_cart_add' );
			data.append( 'product_id', st.id );
			data.append( 'quantity', vp.querySelector( '.oc-vp__qty input' ).value || '1' );

			if ( ! st.simple ) {
				data.append( 'variation_id', String( hit.id ) );
				st.groups.forEach( function ( g ) {
					data.append( g.key, st.sel[ g.key ] );
				} );
			}

			fetch( L.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( out ) {
					btn.classList.remove( 'is-busy' );

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

						// The button answers with a tick first; the panel
						// bows out a breath later.
						btn.classList.add( 'oc-added' );

						// Anyone listening (the shop-the-look strip counts
						// its own products) hears which product landed.
						document.dispatchEvent( new CustomEvent( 'oc:added', { detail: { productId: String( st.id ) } } ) );

						setTimeout( function () {
							btn.classList.remove( 'oc-added' );
							vpClose();

							if ( window.__ocOpenDrawer ) {
								window.__ocOpenDrawer();
							} else if ( window.__ocCartToast ) {
								window.__ocCartToast( vp.querySelector( '.oc-vp__name' ).textContent, st.img || '' );
							}
						}, 750 );
					}
				} );
		}

		var vpOpenedAt = 0;

		function vpLoad( productId, keep ) {
			// A fresh open starts from a blank slate — the last product must
			// not greet the next. A colour switch keeps the panel dressed
			// and only swaps the clothes when the answer lands: blanking it
			// mid-view read as a jump.
			if ( ! keep ) {
				vp.classList.add( 'is-loading' );
				vp.querySelector( '.oc-vp__groups' ).innerHTML = '';
				vp.querySelector( '.oc-vp__strip' ).innerHTML = '';
				vp.querySelector( '.oc-vp__dots' ).innerHTML = '';
				vp.querySelector( '.oc-vp__name' ).textContent = '';
				vp.querySelector( '.oc-vp__price' ).innerHTML = '';
				vp.querySelector( '.oc-vp__fprice' ).innerHTML = '';
				vp.querySelector( '.oc-vp__flags' ).innerHTML = '';
				vp.querySelector( '.oc-vp__blurb' ).innerHTML = '';
				vp.querySelector( '.oc-vp__stock' ).innerHTML = '';
				vp.querySelector( '.oc-vp__stars' ).hidden = true;
				vp.querySelector( '.oc-vp__sku' ).hidden = true;
				vp.querySelector( '.oc-vp__thumb' ).hidden = true;
			}

			vp.querySelector( '.oc-vp__add' ).disabled = true;

			var data = new FormData();
			data.append( 'action', 'oc_card_picker' );
			data.append( 'product_id', productId );

			fetch( L.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						vpClose();
						return;
					}

					// Rendering mid-slide stalls the entrance — the answer
					// waits for the panel to settle before dressing it.
					var settle = Math.max( 0, 340 - ( Date.now() - vpOpenedAt ) );

					setTimeout( function () {
						vpRender( res.data, productId );
					}, settle );
				} );
		}

		function vpOpen( productId, preSlug ) {
			if ( ! vp ) {
				vpBuild();
			}

			// A colour already chosen outside (a card swatch) arrives marked —
			// the panel's own vpWant machinery picks it up during the build.
			if ( preSlug ) {
				vpWant = preSlug;
			}

			vp.hidden = false;
			vpOpenedAt = Date.now();
			// A freshly appended panel must be laid out once before the
			// class flips, or the first entrance jumps instead of gliding.
			void vp.offsetWidth;
			requestAnimationFrame( function () { vp.classList.add( 'is-open' ); } );
			vpLoad( productId );
		}

		/* The theme's own card icon: a simple product carries Woo's ajax
		 * classes and answers with its built-in tick; anything with options
		 * carries only oc-card-atc — that click opens the panel instead of
		 * leaving for the product page. */
		document.addEventListener( 'click', function ( e ) {
			var a = e.target.closest( 'a.oc-card-atc:not(.ajax_add_to_cart), a.add_to_cart_button.product_type_variable' );

			if ( ! a ) {
				return;
			}

			e.preventDefault();
			e.stopPropagation();

			// The colour the card is showing travels into the panel.
			var host = a.closest( 'li.product, .ocb-look__card' );
			var curDot = host && host.querySelector( '.oc-colors__item--term.is-current' );
			vpWant = curDot ? ( curDot.dataset.slug || '' ) : '';

			vpOpen( a.dataset.product_id || a.getAttribute( 'data-product_id' ) || '' );
		}, true );

		/* The search panel's "choose" opens the same door: the search steps
		 * aside, the quick pick steps in. */
		document.addEventListener( 'click', function ( e ) {
			var pick = e.target.closest( '[data-oc-search-pick]' );

			if ( ! pick ) {
				return;
			}

			var box = pick.closest( '.oc-searchbox' );
			var close = box && box.querySelector( '[data-oc-search-close]' );

			if ( close ) {
				close.click();
			}

			vpOpen( pick.dataset.ocSearchPick );
		} );

		window.__ocQuickPick = vpOpen;
	}() );

	/* ---------- reading progress (single post) ---------- */

	var prog = document.querySelector( '.oc-progress i' );

	if ( prog ) {
		var art = document.querySelector( '.oc-bsingle article' );
		var progTick = false;

		var progDraw = function () {
			progTick = false;

			if ( ! art ) {
				return;
			}

			var rect = art.getBoundingClientRect();
			var run = rect.height - window.innerHeight;
			var done = run > 0 ? Math.min( 1, Math.max( 0, -rect.top / run ) ) : 1;

			prog.style.transform = 'scaleX(' + done + ')';
		};

		window.addEventListener( 'scroll', function () {
			if ( ! progTick ) {
				progTick = true;
				requestAnimationFrame( progDraw );
			}
		}, { passive: true } );

		progDraw();
	}

	/* ---------- copy a link (blog share row) ---------- */

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-oc-copy]' );

		if ( ! btn || ! navigator.clipboard ) {
			return;
		}

		navigator.clipboard.writeText( btn.dataset.ocCopy ).then( function () {
			btn.classList.add( 'is-done' );
			setTimeout( function () { btn.classList.remove( 'is-done' ); }, 1400 );
		} );
	} );
}() );


/* ---------- the login drawer ----------
 * Phone first. A recognised number gets its six digits; a new one fills
 * its details once. Google is a plain link — the server does the dance. */

( function () {
	var root = null;
	var L = window.ocL10n || {};
	var phone = '';
	var timer = null;

	function el( sel ) {
		return root ? root.querySelector( sel ) : null;
	}

	function say( form, msg ) {
		var err = form.querySelector( '.oc-auth__err' );

		if ( err ) {
			err.textContent = msg || '';
			err.hidden = ! msg;
		}
	}

	function step( name ) {
		root.querySelectorAll( '.oc-auth__step' ).forEach( function ( s ) {
			s.hidden = s.dataset.step !== name;
		} );

		var pitch = el( '[data-auth-signup]' );

		if ( pitch ) { pitch.hidden = 'register' === name; }

		// The header: the title on the front step, the way back elsewhere.
		var title = el( '.oc-auth__name' );
		var backlink = el( '.oc-auth__backlink' );

		if ( title && backlink ) {
			title.hidden = 'phone' !== name;

			// The way back belongs to the screens the visitor CHOSE — signing
			// up, or the password door. Typing the code is the phone journey
			// carrying on, and it has its own way back already.
			backlink.hidden = 'register' !== name && 'email' !== name && 'reset' !== name;
		}

		if ( 'code' === name ) {
			var first = el( '.oc-auth__boxes input' );
			if ( first ) { first.focus(); }
		}
	}

	/* The registration form's time trap — set whenever the step opens. */
	function armRegister() {
		var form = el( '[data-step="register"] form' );
		var ts = form.querySelector( '[name="ts"]' );

		if ( ! ts ) {
			ts = document.createElement( 'input' );
			ts.type = 'hidden';
			ts.name = 'ts';
			form.appendChild( ts );
		}

		ts.value = String( Math.floor( Date.now() / 1000 ) );
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', root.dataset.nonce );
		Object.keys( data ).forEach( function ( k ) { body.append( k, data[ k ] ); } );

		return fetch( L.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } );
	}

	function countdown( seconds ) {
		var resend = el( '[data-auth-resend]' );
		var emailBtn = el( '[data-auth-email]' );
		var label = el( '[data-auth-timer]' );
		var left = seconds;

		clearInterval( timer );
		resend.hidden = true;
		if ( emailBtn ) { emailBtn.hidden = true; }

		function tick() {
			if ( left < 1 ) {
				clearInterval( timer );
				label.textContent = '';
				resend.hidden = false;
				if ( emailBtn && '1' === root.dataset.hasEmail ) { emailBtn.hidden = false; }
				return;
			}

			label.textContent = ( L.authResend || 'Resend in %ds' ).replace( '%d', left );
			left--;
		}

		tick();
		timer = setInterval( tick, 1000 );
	}

	function open() {
		root.hidden = false;
		void root.offsetWidth;
		requestAnimationFrame( function () { root.classList.add( 'is-open' ); } );
		step( 'phone' );

		// Desktop only: on a phone the keyboard would bury the other
		// sign-in options the moment the drawer opens.
		if ( window.matchMedia( '(min-width: 783px)' ).matches ) {
			var tel = el( '[data-step="phone"] .oc-auth__tel' );
			if ( tel ) { tel.focus(); }
		}

		/* Android reads the SMS by itself when the browser plays along. */
		if ( 'OTPCredential' in window ) {
			navigator.credentials.get( { otp: { transport: [ 'sms' ] }, signal: undefined } ).then( function ( otp ) {
				if ( otp && otp.code ) { fillCode( otp.code ); }
			} ).catch( function () {} );
		}
	}

	function close() {
		clearInterval( timer );
		root.classList.remove( 'is-open' );
		setTimeout( function () { root.hidden = true; }, 300 );
	}

	function fillCode( code ) {
		var boxes = root.querySelectorAll( '.oc-auth__boxes input' );

		code.replace( /\D/g, '' ).slice( 0, 6 ).split( '' ).forEach( function ( d, i ) {
			if ( boxes[ i ] ) { boxes[ i ].value = d; }
		} );

		if ( boxes[ 5 ] && boxes[ 5 ].value ) { boxes[ 5 ].focus(); }

		maybeVerify();
	}

	/* Six digits in — the check runs itself, no button to press. */
	function maybeVerify() {
		if ( 6 !== codeValue().length ) { return; }

		var form = el( '[data-step="code"] form' );

		if ( form ) { form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) ); }
	}

	function codeValue() {
		return Array.prototype.map.call( root.querySelectorAll( '.oc-auth__boxes input' ), function ( b ) { return b.value; } ).join( '' );
	}

	document.addEventListener( 'click', function ( e ) {
		var opener = e.target.closest( '[data-oc-auth]' );

		if ( opener ) {
			root = root || document.querySelector( '.oc-auth' );

			if ( root ) {
				e.preventDefault();
				open();
			}

			return;
		}

		if ( ! root || root.hidden ) { return; }

		if ( e.target.closest( '[data-auth-close]' ) ) { close(); }

		var goto_ = e.target.closest( '[data-auth-goto]' );

		if ( goto_ ) {
			if ( 'reset' === goto_.dataset.authGoto ) {
				var rform = el( '[data-step="reset"] form' );
				var sent = el( '[data-auth-sent]' );

				say( rform, '' );
				if ( sent ) { sent.hidden = true; }

				step( 'reset' );

				if ( window.matchMedia( '(min-width: 783px)' ).matches ) {
					var rem = el( '[data-step="reset"] [name="email"]' );
					if ( rem ) { rem.focus(); }
				}
			} else if ( 'email' === goto_.dataset.authGoto ) {
				step( 'email' );

				if ( window.matchMedia( '(min-width: 783px)' ).matches ) {
					var em = el( '[data-step="email"] [name="email"]' );
					if ( em ) { em.focus(); }
				}
			} else if ( 'register' === goto_.dataset.authGoto ) {
				// Direct sign-up: the phone is theirs to type, and the pitch
				// speaks perks, not an unrecognised number.
				var show = el( '[data-step="register"] [name="phone_show"]' );

				if ( show ) {
					show.readOnly = false;
					show.value = '';
					show.placeholder = ( window.ocL10n || {} ).authPhone || '';
				}

				var change = el( '[data-step="register"] [data-auth-change]' );
				if ( change ) { change.hidden = true; }

				var flowMsg = el( '[data-reg-flow]' );
				var perks = el( '[data-reg-direct]' );

				if ( flowMsg ) { flowMsg.hidden = true; }
				if ( perks ) { perks.hidden = false; }

				armRegister();
				step( 'register' );
			}

			return;
		}

		if ( e.target.closest( '[data-auth-change]' ) ) {
			clearInterval( timer );
			step( 'phone' );
			var tel = el( '[data-step="phone"] .oc-auth__tel' );
			if ( tel ) { tel.focus(); tel.select && tel.select(); }
		}

		if ( e.target.closest( '[data-auth-resend]' ) ) {
			post( 'oc_auth_start', { phone: phone } ).then( function ( out ) {
				var form = el( '[data-step="code"] form' );

				if ( out && out.success ) {
					say( form, '' );
					countdown( out.data.wait || 60 );
				} else {
					say( form, out && out.data ? out.data.msg : '' );
				}
			} );
		}

		if ( e.target.closest( '[data-auth-email]' ) ) {
			post( 'oc_auth_email_code', { phone: phone } ).then( function ( out ) {
				var form = el( '[data-step="code"] form' );
				say( form, out && out.success ? ( L.authMailed || 'The code is in your inbox.' ) : ( out && out.data ? out.data.msg : '' ) );

				if ( out && out.success ) { countdown( out.data.wait || 60 ); }
			} );
		}
	} );

	/* The six boxes: type and hop, paste and spread, erase and step back. */
	document.addEventListener( 'input', function ( e ) {
		var box = e.target.closest( '.oc-auth__boxes input' );
		if ( ! box ) { return; }

		if ( box.value.length > 1 ) { fillCode( box.value ); return; }

		if ( box.value && box.nextElementSibling ) { box.nextElementSibling.focus(); }

		maybeVerify();
	} );

	document.addEventListener( 'keydown', function ( e ) {
		var box = e.target.closest && e.target.closest( '.oc-auth__boxes input' );

		if ( box && 'Backspace' === e.key && ! box.value && box.previousElementSibling ) {
			box.previousElementSibling.focus();
		}
	} );

	document.addEventListener( 'paste', function ( e ) {
		if ( e.target.closest && e.target.closest( '.oc-auth__boxes' ) ) {
			e.preventDefault();
			fillCode( ( e.clipboardData || window.clipboardData ).getData( 'text' ) || '' );
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '.oc-auth__form' );
		if ( ! form ) { return; }

		e.preventDefault();

		var kind = form.dataset.authForm;
		var cta = form.querySelector( '.oc-auth__cta' );

		if ( cta ) { cta.classList.add( 'is-busy' ); }

		function done() { if ( cta ) { cta.classList.remove( 'is-busy' ); } }

		if ( 'start' === kind ) {
			phone = form.querySelector( '[name="phone"]' ).value.trim();

			post( 'oc_auth_start', { phone: phone } ).then( function ( out ) {
				done();

				if ( ! out || ! out.success ) { say( form, out && out.data ? out.data.msg : '' ); return; }

				say( form, '' );
				phone = out.data.phone;

				if ( 'code' === out.data.step ) {
					root.dataset.hasEmail = out.data.email ? '1' : '0';
					el( '[data-auth-pretty]' ).textContent = out.data.pretty;
					root.querySelectorAll( '.oc-auth__boxes input' ).forEach( function ( b ) { b.value = ''; } );
					step( 'code' );
					countdown( out.data.wait || 60 );
				} else {
					var show = el( '[data-step="register"] [name="phone_show"]' );

					if ( show ) {
						show.value = out.data.pretty;
						show.readOnly = true;
					}

					var change = el( '[data-step="register"] [data-auth-change]' );
					if ( change ) { change.hidden = false; }

					var flowMsg = el( '[data-reg-flow]' );
					var perks = el( '[data-reg-direct]' );

					if ( flowMsg ) { flowMsg.hidden = false; }
					if ( perks ) { perks.hidden = true; }

					armRegister();
					step( 'register' );
				}
			} );
		}

		if ( 'verify' === kind ) {
			var boxes = root.querySelectorAll( '.oc-auth__boxes input' );
			boxes.forEach( function ( b ) { b.disabled = true; } );

			post( 'oc_auth_verify', { phone: phone, code: codeValue() } ).then( function ( out ) {
				done();
				boxes.forEach( function ( b ) { b.disabled = false; } );

				if ( out && out.success ) { window.location.reload(); return; }

				say( form, out && out.data ? out.data.msg : '' );
				boxes.forEach( function ( b ) { b.value = ''; } );
				if ( boxes[ 0 ] ) { boxes[ 0 ].focus(); }
			} );
		}

		if ( 'reset' === kind ) {
			post( 'oc_auth_reset', { email: form.querySelector( '[name="email"]' ).value.trim() } ).then( function ( out ) {
				done();

				var sent = el( '[data-auth-sent]' );

				if ( out && out.success ) {
					say( form, '' );

					if ( sent ) {
						sent.textContent = out.data && out.data.msg ? out.data.msg : '';
						sent.hidden = false;
					}

					return;
				}

				if ( sent ) { sent.hidden = true; }
				say( form, out && out.data ? out.data.msg : '' );
			} );
		}

		if ( 'login' === kind ) {
			post( 'oc_auth_email_login', {
				email: form.querySelector( '[name="email"]' ).value.trim(),
				password: form.querySelector( '[name="password"]' ).value
			} ).then( function ( out ) {
				done();

				if ( out && out.success ) { window.location.reload(); return; }

				say( form, out && out.data ? out.data.msg : '' );
			} );
		}

		if ( 'register' === kind ) {
			var shown = form.querySelector( '[name="phone_show"]' );

			post( 'oc_auth_register', {
				phone: shown && shown.value.trim() ? shown.value.trim() : phone,
				first: form.querySelector( '[name="first"]' ).value.trim(),
				last: form.querySelector( '[name="last"]' ).value.trim(),
				email: form.querySelector( '[name="email"]' ).value.trim(),
				consent: form.querySelector( '[name="consent"]' ).checked ? '1' : '',
				website: form.querySelector( '[name="website"]' ).value,
				ts: ( form.querySelector( '[name="ts"]' ) || { value: '0' } ).value
			} ).then( function ( out ) {
				done();

				if ( out && out.success ) {
					// The number has to prove itself before the account is
					// real: the details wait while a code goes out.
					if ( out.data && 'code' === out.data.step ) {
						say( form, '' );
						phone = out.data.phone;
						root.dataset.hasEmail = '0';
						el( '[data-auth-pretty]' ).textContent = out.data.pretty;
						root.querySelectorAll( '.oc-auth__boxes input' ).forEach( function ( b ) { b.value = ''; } );
						step( 'code' );
						countdown( out.data.wait || 60 );
						return;
					}

					window.location.reload();
					return;
				}

				say( form, out && out.data ? out.data.msg : '' );
			} );
		}
	} );
}() );

/* ---------- account deletion: the warning stands between ---------- */

( function () {
	document.addEventListener( 'click', function ( e ) {
		var open = e.target.closest( '.oc-delacc__open' );

		if ( open ) {
			var dim = open.closest( '.oc-delacc' ).querySelector( '.oc-delacc__dim' );
			dim.hidden = false;
			return;
		}

		if ( e.target.closest( '[data-delacc-close]' ) || ( e.target.classList && e.target.classList.contains( 'oc-delacc__dim' ) ) ) {
			var shade = e.target.closest( '.oc-delacc__dim' ) || e.target;
			shade.hidden = true;
		}
	} );
}() );


/* ---------- the goodbye toast: signed out, counting down ---------- */

( function () {
	if ( -1 === window.location.search.indexOf( 'oc_bye=1' ) ) { return; }

	window.history.replaceState( null, '', window.location.pathname );

	var L = window.ocL10n || {};
	var toast = document.createElement( 'div' );
	toast.className = 'oc-bye';
	document.body.appendChild( toast );

	var left = 3;

	function paint() {
		toast.textContent = ( L.byeMsg || 'Signed out' ) + ' (' + left + ')';
	}

	paint();

	var tick = setInterval( function () {
		left--;

		if ( left < 1 ) {
			clearInterval( tick );
			toast.classList.add( 'is-out' );
			setTimeout( function () { toast.remove(); }, 400 );
			return;
		}

		paint();
	}, 1000 );
}() );


/* ---------- the signed-in account menu: one click to anywhere ---------- */

( function () {
	document.addEventListener( 'click', function ( e ) {
		var menu = document.querySelector( '.oc-accmenu' );

		if ( ! menu ) { return; }

		var icon = e.target.closest( '[data-oc-accmenu]' );

		if ( icon ) {
			e.preventDefault();
			menu.hidden = ! menu.hidden;

			if ( ! menu.hidden ) {
				var r = icon.getBoundingClientRect();
				var w = menu.offsetWidth;
				var left = Math.min( Math.max( 8, r.left + r.width / 2 - w / 2 ), window.innerWidth - w - 8 );
				menu.style.top = ( r.bottom + 10 ) + 'px';
				menu.style.left = left + 'px';
			}

			return;
		}

		if ( ! menu.hidden && ! e.target.closest( '.oc-accmenu' ) ) {
			menu.hidden = true;
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			var menu = document.querySelector( '.oc-accmenu' );
			if ( menu ) { menu.hidden = true; }
		}
	} );
}() );


/* ---------- signing out asks first ---------- */

( function () {
	var box = null;
	var pending = '';

	function build() {
		var L = window.ocL10n || {};
		box = document.createElement( 'div' );
		box.className = 'oc-delacc__dim';
		box.hidden = true;
		box.innerHTML =
			'<div class="oc-delacc__box" role="alertdialog" aria-modal="true">' +
				'<h4>' + ( L.byeAsk || 'Sign out of the account?' ) + '</h4>' +
				'<div class="oc-delacc__row">' +
					'<button type="button" class="oc-delacc__cancel" data-bye-no>' + ( L.byeNo || 'Cancel' ) + '</button>' +
					'<button type="button" class="oc-delacc__yes" data-bye-yes>' + ( L.byeYes || 'Sign out' ) + '</button>' +
				'</div>' +
			'</div>';
		document.body.appendChild( box );

		box.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-bye-yes]' ) && pending ) {
				window.location.href = pending;
				return;
			}

			if ( e.target.closest( '[data-bye-no]' ) || e.target === box ) {
				box.hidden = true;
				pending = '';
			}
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( 'a[href*="customer-logout"]' );

		if ( ! link ) { return; }

		e.preventDefault();
		e.stopPropagation();

		if ( ! box ) { build(); }

		pending = link.href;
		box.hidden = false;
	}, true );
}() );

/* ---------- my-account fields: the label floats like at checkout ---------- */

( function () {
	var scope = document.querySelector( '.woocommerce-MyAccount-content, .woocommerce-account:not(.logged-in) .woocommerce' );

	if ( ! scope ) { return; }

	function fill( field ) {
		var row = field.closest( '.form-row' );
		if ( row ) { row.classList.toggle( 'is-filled', '' !== field.value.trim() ); }
	}

	function all() {
		scope.querySelectorAll( '.input-text, select, textarea' ).forEach( fill );
	}

	all();
	setTimeout( all, 600 ); // autofill lands late

	scope.addEventListener( 'input', function ( e ) {
		if ( e.target.matches( '.input-text, select, textarea' ) ) { fill( e.target ); }
	} );
	scope.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( 'select' ) ) { fill( e.target ); }
	} );
}() );

/* -- address-book form: label chips (my-account) -- */
( function () {
	var chips = document.querySelector( '[data-oc-abook-chips]' );
	if ( ! chips ) { return; }

	var form = chips.closest( '[data-oc-abook-form]' );
	var custom = form.querySelector( '[data-oc-chip-input]' );
	var hidden = form.querySelector( '[data-oc-abook-label]' );

	chips.addEventListener( 'click', function ( e ) {
		var chip = e.target.closest( '[data-oc-chip]' );
		if ( ! chip ) { return; }
		chips.querySelectorAll( '[data-oc-chip]' ).forEach( function ( c ) { c.classList.remove( 'is-on' ); } );
		chip.classList.add( 'is-on' );

		if ( 'custom' === chip.dataset.ocChip ) {
			if ( custom ) {
				custom.hidden = false;
				custom.focus();
				if ( hidden ) { hidden.value = custom.value.trim(); }
			}
		} else {
			if ( custom ) { custom.hidden = true; }
			if ( hidden ) { hidden.value = chip.dataset.ocChip; }
		}
	} );

	if ( custom && hidden ) {
		custom.addEventListener( 'input', function () { hidden.value = custom.value.trim(); } );
	}
}() );

/* -- account details: pair email with phone, reveal password behind a button -- */
( function () {
	var form = document.querySelector( 'form.edit-account, form.woocommerce-EditAccountForm' );
	if ( ! form ) { return; }

	// The display name is derived, not edited — hide its row (kept in the DOM
	// so it still submits its value).
	var dn = form.querySelector( '#account_display_name' );
	if ( dn ) {
		var drow = dn.closest( '.form-row' );
		if ( drow ) { drow.style.display = 'none'; }
	}

	// Pair email (shrunk to half width) with the phone beside it. The phone is
	// printed on the woocommerce_edit_account_form hook, which sits AFTER the
	// password fieldset — so move its row up to right after the email.
	var email = form.querySelector( '#account_email' );
	var phone = form.querySelector( '#account_phone' );
	if ( email ) {
		var erow = email.closest( '.form-row' );
		if ( erow ) {
			erow.classList.remove( 'form-row-wide', 'woocommerce-form-row--wide' );
			erow.classList.add( 'form-row-first', 'woocommerce-form-row--first' );
			if ( phone ) {
				var prow = phone.closest( '.form-row' );
				if ( prow ) { erow.parentNode.insertBefore( prow, erow.nextSibling ); }
			}
		}
	}

	var fs = form.querySelector( 'fieldset' );
	if ( ! fs ) { return; }

	var legend = fs.querySelector( 'legend' );
	var label = legend ? legend.textContent.trim() : 'שינוי סיסמה';

	var btn = document.createElement( 'button' );
	btn.type = 'button';
	btn.className = 'oc-pwtoggle';
	btn.innerHTML = '<span>' + label + '</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
	btn.setAttribute( 'aria-expanded', 'false' );

	fs.parentNode.insertBefore( btn, fs );
	fs.hidden = true;

	btn.addEventListener( 'click', function () {
		var open = fs.hidden;
		fs.hidden = ! open;
		btn.classList.toggle( 'is-open', open );
		btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			var first = fs.querySelector( 'input' );
			if ( first ) { first.focus(); }
		}
	} );
}() );

/* -- orders list: "order again" with a cart-merge choice -- */
( function () {
	if ( ! document.querySelector( '[data-oc-reorder]' ) ) { return; }

	var L = window.ocL10n || {};
	var ajaxUrl = L.ajaxUrl || '/wp-admin/admin-ajax.php';

	function post( id, nonce, mode ) {
		var b = new FormData();
		b.append( 'action', 'oc_reorder' );
		b.append( 'order_id', id );
		b.append( 'nonce', nonce );
		b.append( 'mode', mode );
		return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: b } ).then( function ( r ) { return r.json(); } );
	}

	function go( out ) {
		if ( out && out.success && out.data && out.data.redirect ) {
			window.location.assign( out.data.redirect );
			return true;
		}
		return false;
	}

	function modal( id, nonce ) {
		var dim = document.createElement( 'div' );
		dim.className = 'oc-reorder-dim';
		dim.innerHTML =
			'<div class="oc-reorder-box" role="alertdialog" aria-modal="true">' +
			'<h4>' + ( L.reorderTitle || 'You already have items in your cart' ) + '</h4>' +
			'<p>' + ( L.reorderBody || 'Add these products as well, or empty the cart and add only them?' ) + '</p>' +
			'<div class="oc-reorder-acts">' +
			'<button type="button" class="button oc-reorder-add">' + ( L.reorderAdd || 'Add as well' ) + '</button>' +
			'<button type="button" class="button oc-reorder-replace">' + ( L.reorderReplace || 'Empty and add' ) + '</button>' +
			'</div>' +
			'<button type="button" class="oc-reorder-close" aria-label="' + ( L.reorderCancel || 'Cancel' ) + '">&times;</button>' +
			'</div>';
		document.body.appendChild( dim );

		function close() { dim.remove(); }
		function run( mode, btn ) {
			btn.disabled = true;
			post( id, nonce, mode ).then( function ( out ) { if ( ! go( out ) ) { btn.disabled = false; close(); } } );
		}

		dim.querySelector( '.oc-reorder-add' ).addEventListener( 'click', function () { run( 'add', this ); } );
		dim.querySelector( '.oc-reorder-replace' ).addEventListener( 'click', function () { run( 'replace', this ); } );
		dim.querySelector( '.oc-reorder-close' ).addEventListener( 'click', close );
		dim.addEventListener( 'click', function ( e ) { if ( e.target === dim ) { close(); } } );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-oc-reorder]' );
		if ( ! btn ) { return; }
		e.preventDefault();
		btn.disabled = true;
		post( btn.dataset.ocReorder, btn.dataset.nonce, 'ask' ).then( function ( out ) {
			btn.disabled = false;
			if ( out && out.success && out.data && out.data.choice ) {
				modal( btn.dataset.ocReorder, btn.dataset.nonce );
			} else if ( ! go( out ) && out && out.data && out.data.msg ) {
				window.alert( out.data.msg );
			}
		} );
	} );
}() );

/* -- footer newsletter sign-up (reuses the oc_blocks_subscribe endpoint) -- */
( function () {
	var form = document.querySelector( '[data-oc-subscribe]' );
	if ( ! form ) { return; }

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		if ( form.querySelector( '.oc-footer__news-trap' ).value ) { return; } // honeypot
		var go = form.querySelector( '.oc-footer__news-go' );
		var mail = form.querySelector( '.oc-footer__news-mail' );
		if ( go ) { go.disabled = true; }
		fetch( form.getAttribute( 'action' ), {
			method: 'POST',
			credentials: 'same-origin',
			body: new FormData( form )
		} ).then( function ( r ) { return r.json(); } ).then( function ( out ) {
			if ( go ) { go.disabled = false; }
			if ( out && out.success ) {
				var t = form.querySelector( '.oc-footer__news-thanks' );
				if ( t ) { t.hidden = false; }
				if ( mail ) { mail.value = ''; }
			} else if ( out && out.data && out.data.msg ) {
				window.alert( out.data.msg );
			}
		} ).catch( function () { if ( go ) { go.disabled = false; } } );
	} );
}() );

/* -- footer link columns: accordion on phones -- */
( function () {
	var footer = document.querySelector( '.oc-footer--m-accordion' );
	if ( ! footer ) { return; }

	footer.addEventListener( 'click', function ( e ) {
		if ( window.matchMedia( '(min-width: 701px)' ).matches ) { return; }
		var head = e.target.closest( '.oc-footer__col-h' );
		if ( ! head ) { return; }
		var col = head.closest( '.oc-footer__col' );
		if ( col ) { col.classList.toggle( 'is-open' ); }
	} );
}() );

/* -- sub-category card slider: drag to scroll with a mouse (touch uses the
 *    native free-scroll) -- */
( function () {
	var sliders = document.querySelectorAll( '[data-oc-slider]' );
	if ( ! sliders.length ) { return; }

	Array.prototype.forEach.call( sliders, function ( el ) {
		var down = false, startX = 0, startScroll = 0, moved = false;

		// Nudge the first card to the reading side (RTL scroll start), touching
		// only this container's scroll — never the page.
		( function alignStart() {
			var f = el.firstElementChild;
			if ( ! f ) { return; }
			var rtl = 'rtl' === getComputedStyle( el ).direction;
			var pad = parseFloat( getComputedStyle( el ).paddingInlineStart ) || 0;
			function off() {
				var c = el.getBoundingClientRect(), r = f.getBoundingClientRect();
				return rtl ? ( r.right - ( c.right - pad ) ) : ( r.left - ( c.left + pad ) );
			}
			var d = off();
			if ( Math.abs( d ) < 1 ) { return; }
			var base = el.scrollLeft;
			el.scrollLeft = base + d;
			if ( Math.abs( off() ) > Math.abs( d ) ) { el.scrollLeft = base - d; }
		}() );

		el.addEventListener( 'pointerdown', function ( e ) {
			if ( 'touch' === e.pointerType ) { return; }
			down = true; moved = false;
			startX = e.clientX; startScroll = el.scrollLeft;
			el.classList.add( 'is-drag' );
		} );
		el.addEventListener( 'pointermove', function ( e ) {
			if ( ! down ) { return; }
			var dx = e.clientX - startX;
			if ( Math.abs( dx ) > 3 ) { moved = true; }
			el.scrollLeft = startScroll - dx;
		} );
		function end() {
			if ( ! down ) { return; }
			down = false;
			el.classList.remove( 'is-drag' );
		}
		el.addEventListener( 'pointerup', end );
		el.addEventListener( 'pointerleave', end );
		el.addEventListener( 'pointercancel', end );
		// A drag must not also open the card's link.
		el.addEventListener( 'click', function ( e ) {
			if ( moved ) { e.preventDefault(); }
		}, true );
	} );
}() );

/* ---------- cross-sells on a product page ----------
 *
 * Ticking a row reveals its quantity and marks it; the tick itself is a
 * real checkbox inside the product's form, so what is ticked travels with
 * the add-to-cart submission and the server adds it alongside. Tapping a
 * picture or a name opens the same quick-pick panel the catalogue uses. */
( function () {
	var blocks = document.querySelectorAll( '.oc-xsell' );
	if ( ! blocks.length ) { return; }

	Array.prototype.forEach.call( blocks, function ( block ) {
		// The tick, and the quantity it reveals.
		block.addEventListener( 'change', function ( e ) {
			var box = e.target.closest( '[data-oc-xs-on]' );
			if ( ! box ) { return; }

			var row = box.closest( '.oc-xsell__row, .oc-xsell__tile' );
			if ( ! row ) { return; }

			var qty = row.querySelector( '[data-oc-xs-qty]' );

			row.classList.toggle( 'is-on', box.checked );
			if ( qty ) { qty.hidden = ! box.checked; }
		} );

		// Plus and minus on the quantity.
		block.addEventListener( 'click', function ( e ) {
			var step = e.target.closest( '[data-oc-xs-step]' );
			if ( ! step ) { return; }

			var field = step.parentNode.querySelector( '.oc-xsell__n' );
			if ( ! field ) { return; }

			var next = ( parseInt( field.value, 10 ) || 1 ) + parseInt( step.dataset.ocXsStep, 10 );
			field.value = Math.max( 1, next );
		} );

		// A picture or a name opens the quick pick.
		block.addEventListener( 'click', function ( e ) {
			var open = e.target.closest( '[data-oc-xs-open]' );
			if ( ! open ) { return; }

			e.preventDefault();

			if ( window.__ocQuickPick ) {
				window.__ocQuickPick( open.dataset.ocXsOpen );
			} else {
				// No panel on the page (it builds with the cart): the product
				// page is a fair second best.
				var row = open.closest( '[data-oc-xs]' );
				if ( row ) { window.location = '/?p=' + row.dataset.ocXs; }
			}
		} );

		// Choosing an option implies wanting the product.
		block.addEventListener( 'change', function ( e ) {
			var opt = e.target.closest( '[data-oc-xs-attr]' );
			if ( ! opt || ! opt.value ) { return; }

			var row = opt.closest( '.oc-xsell__row' );
			var box = row && row.querySelector( '[data-oc-xs-on]' );

			if ( box && ! box.checked ) {
				box.checked = true;
				box.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		} );
	} );
}() );

/* ---------- bought together ----------
 *
 * Unticking answers straight away: the total, what it saves, and the
 * button's own words all follow what is still ticked. The main product
 * cannot be untitcked -- it is the page you are on. */
( function () {
	var block = document.querySelector( '[data-oc-bt]' );
	if ( ! block ) { return; }

	var L        = window.ocL10n || {},
		items    = block.querySelectorAll( '[data-oc-bt-item]' ),
		wasEl    = block.querySelector( '[data-oc-bt-was]' ),
		nowEl    = block.querySelector( '[data-oc-bt-now]' ),
		savedEl  = block.querySelector( '[data-oc-bt-saved]' ),
		btn      = block.querySelector( '[data-oc-bt-add]' ),
		note     = block.querySelector( '[data-oc-bt-note]' ),
		kind     = block.dataset.kind,
		amount   = parseFloat( block.dataset.amount ) || 0,
		money    = {};

	try { money = JSON.parse( block.dataset.money || '{}' ); } catch ( e ) { money = {}; }

	function price( n ) {
		var dec = ( 'undefined' === typeof money.decimals ) ? 2 : money.decimals,
			s   = ( Math.round( n * 100 ) / 100 ).toFixed( dec ),
			bits = s.split( '.' ),
			whole = bits[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, money.thousand || ',' ),
			out = whole + ( bits[ 1 ] ? ( money.dot || '.' ) + bits[ 1 ] : '' ),
			sym = money.symbol || '';

		switch ( money.format ) {
			case 'right':       return out + sym;
			case 'left_space':  return sym + ' ' + out;
			case 'right_space': return out + ' ' + sym;
			default:            return sym + out;
		}
	}

	function chosen() {
		var picked = [];

		Array.prototype.forEach.call( items, function ( li ) {
			var box = li.querySelector( '[data-oc-bt-on]' );
			if ( box && box.checked ) { picked.push( li ); }
		} );

		return picked;
	}

	function draw() {
		var picked = chosen(),
			full   = 0;

		picked.forEach( function ( li ) { full += parseFloat( li.dataset.price ) || 0; } );

		var off = 0;
		// A discount is for taking more than one thing.
		if ( picked.length > 1 && amount > 0 ) {
			off = 'percent' === kind ? full * ( amount / 100 ) : amount;
			off = Math.min( off, full );
		}

		var pay = full - off;

		nowEl.textContent = price( pay );

		if ( off > 0 ) {
			wasEl.textContent = price( full );
			wasEl.hidden = false;
			savedEl.textContent = ( L.btSaved || 'You save %s' ).replace( '%s', price( off ) );
			savedEl.hidden = false;
		} else {
			wasEl.hidden = true;
			savedEl.hidden = true;
		}

		if ( ! picked.length ) {
			btn.disabled = true;
			btn.textContent = L.btNone || 'Pick at least one';
		} else if ( 1 === picked.length ) {
			btn.disabled = false;
			btn.textContent = L.btOne || 'Add to cart';
		} else {
			btn.disabled = false;
			btn.textContent = ( L.btAll || 'Add all to cart' );
		}
	}

	block.addEventListener( 'change', function ( e ) {
		var box = e.target.closest( '[data-oc-bt-on]' );
		if ( ! box ) { return; }

		var li = box.closest( '[data-oc-bt-item]' );
		if ( li ) { li.classList.toggle( 'is-on', box.checked ); }

		draw();
	} );

	btn.addEventListener( 'click', function () {
		var picked = chosen();
		if ( ! picked.length ) { return; }

		btn.disabled = true;
		btn.classList.add( 'is-busy' );
		note.textContent = '';

		var body = new FormData();
		body.append( 'action', 'oc_bt_add' );
		body.append( 'nonce', L.btNonce || '' );
		body.append( 'main', block.dataset.ocBt );
		picked.forEach( function ( li ) { body.append( 'ids[]', li.dataset.ocBtItem ); } );

		fetch( L.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				btn.classList.remove( 'is-busy' );
				btn.disabled = false;

				if ( ! res || ! res.success ) {
					note.textContent = ( res && res.data && res.data.msg ) || '';
					return;
				}

				note.textContent = res.data.msg || '';

				// Let the rest of the theme know, so the cart drawer and the
				// counter catch up the way they do for any other add.
				document.body.dispatchEvent( new CustomEvent( 'wc_fragment_refresh' ) );

				if ( window.jQuery ) {
					window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
					window.jQuery( document.body ).trigger( 'added_to_cart', [ res.data.fragments || {}, '', window.jQuery( btn ) ] );
				}
			} )
			.catch( function () {
				btn.classList.remove( 'is-busy' );
				btn.disabled = false;
			} );
	} );

	draw();
}() );
