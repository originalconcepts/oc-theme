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

			// Every card remembers which page URL it belongs to; the address
			// bar follows the page currently in view, so a click, a back, a
			// refresh or analytics all see the right page.
			pagingUl.querySelectorAll( 'li.product' ).forEach( function ( li ) {
				li.dataset.ocpg = window.location.href;
			} );

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

			// Returning from a product: jump back to the card that was clicked.
			try {
				var ocReturn = JSON.parse( sessionStorage.getItem( 'ocReturn' ) || 'null' );
				if ( ocReturn && ocReturn.postClass &&
					new URL( ocReturn.url ).pathname === window.location.pathname ) {
					var backTarget = pagingUl.querySelector( 'li.' + ocReturn.postClass );
					if ( backTarget ) {
						setTimeout( function () {
							backTarget.scrollIntoView( { block: 'center' } );
						}, 150 );
					}
				}
				sessionStorage.removeItem( 'ocReturn' );
			} catch ( e ) {}

			pagingUl.addEventListener( 'click', function ( event ) {
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

			// Landing mid-catalogue (back from a product on page N): quietly
			// pull every earlier page in above the grid, keeping the view
			// anchored, so scrolling up reaches the whole catalogue.
			var prevChain = pagingNav.querySelector( 'a.prev' );

			function loadPrevChain() {
				if ( ! prevChain ) {
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
				if ( pagingBusy || ! pagingNext ) {
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
		if ( undefined !== item.dataset.badge ) {
			var badge = li.querySelector( '.onsale' );
			if ( badge ) {
				badge.remove();
			}
			if ( item.dataset.badge ) {
				li.insertAdjacentHTML( 'afterbegin', item.dataset.badge );
			}
		}

		li.querySelectorAll( '.oc-colors__item' ).forEach( function ( sib ) {
			sib.classList.remove( 'is-current' );
			sib.removeAttribute( 'aria-current' );
		} );
		item.classList.add( 'is-current' );
		item.setAttribute( 'aria-current', 'true' );
	} );

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

		// Simple products add through Woo's own ajax handler; open the drawer
		// right away and let cart fragments fill it when the add completes.
		document.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '.oc-card-atc.ajax_add_to_cart' );
			if ( ! btn ) {
				return;
			}
			btn.classList.add( 'loading' );
			openDrawer();
			// Woo's add-to-cart JS handles the request itself.
			setTimeout( function () {
				btn.classList.remove( 'loading' );
				btn.classList.add( 'added' );
			}, 900 );
			setTimeout( function () {
				btn.classList.remove( 'added' );
			}, 2600 );
		} );
	}

	/* ---------- product tabs → accordion ---------- */

	if ( document.body.classList.contains( 'oc-tabs-accordion' ) ) {
		document.querySelectorAll( '.woocommerce-Tabs-panel' ).forEach( function ( panel, i ) {
			var heading = panel.querySelector( 'h2' );
			var title = heading ? heading.textContent : panel.getAttribute( 'aria-labelledby' );
			var open = 0 === i;

			var head = document.createElement( 'button' );
			head.type = 'button';
			head.className = 'oc-acc-head';
			head.textContent = title || '';
			head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

			// The panel content moves into a wrapper so opening and closing can
			// animate its height.
			var body = document.createElement( 'div' );
			body.className = 'oc-acc-body';
			while ( panel.firstChild ) {
				body.appendChild( panel.firstChild );
			}
			panel.appendChild( head );
			panel.appendChild( body );

			if ( ! open ) {
				body.style.maxBlockSize = '0px';
			}

			head.addEventListener( 'click', function () {
				var isOpen = head.getAttribute( 'aria-expanded' ) === 'true';

				if ( isOpen ) {
					// From auto to 0: fix the current height first so the
					// transition has a starting point.
					body.style.maxBlockSize = body.scrollHeight + 'px';
					body.offsetHeight;
					body.style.maxBlockSize = '0px';
				} else {
					body.style.maxBlockSize = body.scrollHeight + 'px';
					setTimeout( function () {
						// Release the clamp so nested content can grow later.
						if ( head.getAttribute( 'aria-expanded' ) === 'true' ) {
							body.style.maxBlockSize = '';
						}
					}, 320 );
				}

				head.setAttribute( 'aria-expanded', isOpen ? 'false' : 'true' );
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
		down: '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M 0,30 L 50,80 L 100,30 L 90,20 L 50,60 L 10,20 Z"/></svg>'
	};

	var galleryMode = galleryBody.classList.contains( 'oc-gallery-thumbs-side' ) ? 'side' :
		galleryBody.classList.contains( 'oc-gallery-thumbs-under' ) ? 'under' :
		galleryBody.classList.contains( 'oc-gallery-stacked' ) ? 'stacked' : null;

	// Rebuildable: a colour-gallery swap replaces the slides and calls
	// buildGalleryRail() again, so everything derives from the live DOM and
	// window-level listeners attach once, reading the current rail/slides.
	var gSlides = [];
	var gRail = null;

	function setActiveThumb( index ) {
		if ( ! gRail ) {
			return;
		}
		gRail.querySelectorAll( 'button' ).forEach( function ( other, j ) {
			other.setAttribute( 'aria-current', j === index ? 'true' : 'false' );
		} );
	}

	function activateSlide( index ) {
		if ( 'stacked' === galleryMode ) {
			gSlides[ index ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
		} else {
			gSlides.forEach( function ( other ) {
				other.classList.remove( 'is-active' );
			} );
			gSlides[ index ].classList.add( 'is-active' );
		}
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
			// A video slide carries its thumb in data-thumb instead of an img.
			var src = slide.querySelector( 'img' );
			var thumbSrc = slide.dataset.thumb || ( src && ( src.currentSrc || src.src ) );
			if ( ! thumbSrc ) {
				return;
			}

			var li = document.createElement( 'li' );
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.setAttribute( 'aria-current', 0 === i ? 'true' : 'false' );

			var thumb = document.createElement( 'img' );
			thumb.src = thumbSrc;
			thumb.alt = '';
			thumb.loading = 'lazy';

			btn.appendChild( thumb );
			li.appendChild( btn );
			rail.appendChild( li );

			btn.addEventListener( 'click', function () {
				activateSlide( i );
			} );
		} );

		parent.appendChild( railCol );

		// Cap the visible thumbs; arrows page the rail when there are more.
		if ( gSlides.length > maxThumbs ) {
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
		if ( box.scrollHeight <= box.clientHeight + 12 && box.scrollHeight < 130 ) {
			return;
		}
		box.classList.add( 'oc-clamped' );
		if ( box.scrollHeight <= box.clientHeight + 8 ) {
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
			box.style.maxBlockSize = open ? box.scrollHeight + 'px' : '';
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
							el.src = el.dataset.ocVsrc;
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
				el.src = el.dataset.ocVsrc;
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

		var ocVideoFullHtml = function () {
			return 'file' === ocVideo.kind
				? '<video src="' + ocVideo.fullSrc + '" autoplay loop playsinline controls></video>'
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
			} else {
				// The preview paints instantly with the right geometry; the
				// full-size file replaces it in place once it has loaded.
				media.innerHTML = '<img src="' + item.preview + '" alt="" />';
				if ( item.src !== item.preview ) {
					var full = new Image();
					var target = media.querySelector( 'img' );
					full.onload = function () {
						if ( target.isConnected ) {
							target.src = item.src;
						}
					};
					full.src = item.src;
				}
			}

			ocOverlay.classList.toggle( 'has-nav', ocOverlayItems.length > 1 );
			ocOverlay.querySelector( '.oc-voverlay__count' ).textContent =
				ocOverlayItems.length > 1 ? ( ocOverlayIndex + 1 ) + ' / ' + ocOverlayItems.length : '';
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
					// Click on the image zooms in around the point; a second
					// click zooms back out.
					var zoomImg = event.target.closest( '.oc-voverlay__media img' );
					if ( zoomImg ) {
						if ( zoomImg.classList.contains( 'is-zoomed' ) ) {
							zoomImg.classList.remove( 'is-zoomed' );
						} else {
							var box = zoomImg.getBoundingClientRect();
							zoomImg.style.transformOrigin =
								( ( event.clientX - box.left ) / box.width * 100 ) + '% ' +
								( ( event.clientY - box.top ) / box.height * 100 ) + '%';
							zoomImg.classList.add( 'is-zoomed' );
						}
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

			// The signature open: the clicked image swells from its spot on
			// the page into the lightbox while the white ground fades in.
			var originImg = originSlide && ! originSlide.classList.contains( 'oc-vslide' )
				? originSlide.querySelector( 'img' )
				: null;

			if ( originImg && originImg.getBoundingClientRect().width ) {
				var from = originImg.getBoundingClientRect();
				var ratio = ( originImg.naturalWidth || from.width ) / ( originImg.naturalHeight || from.height );
				var toH = Math.min( window.innerHeight, ( window.innerWidth * 0.92 ) / ratio );
				var toW = toH * ratio;

				var ghost = document.createElement( 'img' );
				ghost.src = originImg.currentSrc || originImg.src;
				ghost.className = 'oc-lb-ghost';
				ghost.style.top = from.top + 'px';
				ghost.style.left = from.left + 'px';
				ghost.style.width = from.width + 'px';
				ghost.style.height = from.height + 'px';
				document.body.appendChild( ghost );

				media.style.opacity = '0';

				setTimeout( function () {
					ghost.style.top = ( ( window.innerHeight - toH ) / 2 ) + 'px';
					ghost.style.left = ( ( window.innerWidth - toW ) / 2 ) + 'px';
					ghost.style.width = toW + 'px';
					ghost.style.height = toH + 'px';
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

			if ( 'gallery' === ocVideo.placement ) {
				var ocInsertSlide = function () {
					if ( galleryWrap.querySelector( '.oc-vslide' ) ) {
						return;
					}
					var slide = document.createElement( 'div' );
					slide.className = 'woocommerce-product-gallery__image oc-vslide';
					slide.dataset.thumb = ocVideo.thumb || ocVideoFallbackThumb;
					slide.innerHTML = ocVideoLoopHtml() + '<span class="oc-vplay" aria-hidden="true"></span>';
					var at = Math.min( Math.max( ocVideo.position, 1 ), galleryWrap.children.length + 1 ) - 1;
					galleryWrap.insertBefore( slide, galleryWrap.children[ at ] || null );
				};

				ocReinsertVideo = ocInsertSlide;
				ocInsertSlide();
				buildGalleryRail();
				buildMobileDots();
				window.dispatchEvent( new Event( 'resize' ) );
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
