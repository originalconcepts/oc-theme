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

		var count = strip.children.length;
		var slideGap = parseFloat( getComputedStyle( strip ).columnGap ) || 0;

		// Index-based with wrap-around: both arrows always page, whichever the
		// visitor reaches for first. The step includes the inter-slide gap —
		// bare clientWidth drifted 2px per slide and the mandatory snap
		// corrected it with a visible jump on the way back.
		function currentIndex() {
			return count < 2 ? 0 :
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
				var target = ( currentIndex() + dir + count ) % count;
				goTo( target, Math.abs( target - currentIndex() ) > 1 );
			} );
		} );
	}

	document.querySelectorAll( '.oc-card-media--gallery' ).forEach( bindCardGallery );

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

			panel.prepend( head );

			if ( ! open ) {
				panel.setAttribute( 'data-oc-closed', '' );
			}

			head.addEventListener( 'click', function () {
				var closed = panel.hasAttribute( 'data-oc-closed' );

				if ( closed ) {
					panel.removeAttribute( 'data-oc-closed' );
				} else {
					panel.setAttribute( 'data-oc-closed', '' );
				}

				head.setAttribute( 'aria-expanded', closed ? 'true' : 'false' );
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

	if ( galleryWrap && galleryMode ) {
		var slides = Array.prototype.slice.call(
			galleryWrap.querySelectorAll( '.woocommerce-product-gallery__image' )
		);

		if ( slides.length > 1 ) {
			var maxThumbs = parseInt( galleryBody.dataset.ocThumbsMax || '10', 10 );
			var vertical = 'side' === galleryMode || 'stacked' === galleryMode;

			var railCol = document.createElement( 'div' );
			railCol.className = 'oc-thumbscol';

			var rail = document.createElement( 'ol' );
			rail.className = 'oc-thumbs';
			railCol.appendChild( rail );

			function setActiveThumb( index ) {
				rail.querySelectorAll( 'button' ).forEach( function ( other, j ) {
					other.setAttribute( 'aria-current', j === index ? 'true' : 'false' );
				} );
			}

			function activateSlide( index ) {
				if ( 'stacked' === galleryMode ) {
					slides[ index ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
				} else {
					slides.forEach( function ( other ) {
						other.classList.remove( 'is-active' );
					} );
					slides[ index ].classList.add( 'is-active' );
				}
				setActiveThumb( index );
			}

			if ( 'stacked' !== galleryMode ) {
				slides[ 0 ].classList.add( 'is-active' );
			}

			slides.forEach( function ( slide, i ) {
				var src = slide.querySelector( 'img' );
				if ( ! src ) {
					return;
				}

				var li = document.createElement( 'li' );
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.setAttribute( 'aria-current', 0 === i ? 'true' : 'false' );

				var thumb = document.createElement( 'img' );
				thumb.src = slide.dataset.thumb || src.currentSrc || src.src;
				thumb.alt = '';
				thumb.loading = 'lazy';

				btn.appendChild( thumb );
				li.appendChild( btn );
				rail.appendChild( li );

				btn.addEventListener( 'click', function () {
					activateSlide( i );
				} );
			} );

			galleryWrap.parentElement.appendChild( railCol );

			// Cap the visible thumbs; arrows page the rail when there are more.
			function sizeRail() {
				var first = rail.querySelector( 'li' );
				if ( ! first || slides.length <= maxThumbs ) {
					return;
				}
				var step = ( vertical ? first.offsetHeight : first.offsetWidth ) + 10;
				if ( vertical ) {
					rail.style.maxBlockSize = ( step * maxThumbs - 10 ) + 'px';
				} else {
					rail.style.maxInlineSize = ( step * maxThumbs - 10 ) + 'px';
				}
				rail.classList.add( 'is-capped' );
			}

			if ( slides.length > maxThumbs ) {
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
				window.addEventListener( 'load', sizeRail );
				sizeRail();
			}

			// Stacked: highlight the thumb of the image currently in view.
			if ( 'stacked' === galleryMode ) {
				window.addEventListener( 'scroll', function () {
					for ( var i = 0; i < slides.length; i++ ) {
						var r = slides[ i ].getBoundingClientRect();
						if ( r.bottom > window.innerHeight * 0.35 ) {
							setActiveThumb( i );
							return;
						}
					}
				}, { passive: true } );
			}

			// Desktop arrows on the main image, for the thumbs presets.
			if ( 'stacked' !== galleryMode && galleryBody.classList.contains( 'oc-gdesk-arrows' ) ) {
				var currentSlide = 0;

				[ [ 'prev', 'right', -1 ], [ 'next', 'left', 1 ] ].forEach( function ( def ) {
					var b = document.createElement( 'button' );
					b.type = 'button';
					b.className = 'oc-gnav oc-gnav--desktop oc-gnav--' + def[ 0 ];
					b.setAttribute( 'aria-label', def[ 0 ] );
					b.innerHTML = 'prev' === def[ 0 ] ? railChevrons.left : railChevrons.right;
					b.addEventListener( 'click', function () {
						rail.querySelectorAll( 'button' ).forEach( function ( other, j ) {
							if ( 'true' === other.getAttribute( 'aria-current' ) ) {
								currentSlide = j;
							}
						} );
						activateSlide( ( currentSlide + def[ 2 ] + slides.length ) % slides.length );
					} );
					galleryWrap.parentElement.appendChild( b );
				} );
			}
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

	var mgWrap = document.querySelector( '.woocommerce-product-gallery__wrapper' );

	if ( mgWrap && document.body.classList.contains( 'oc-gm-dots' ) ) {
		var mgSlides = mgWrap.querySelectorAll( '.woocommerce-product-gallery__image' );
		var mgCount = mgSlides.length;

		if ( mgCount > 1 ) {
			var mgGallery = mgWrap.parentElement;

			function mgIndex() {
				return mgWrap.clientWidth === 0 ? 0 :
					Math.round( Math.abs( mgWrap.scrollLeft ) / mgWrap.clientWidth );
			}

			var mgDots = document.createElement( 'ol' );
			mgDots.className = 'oc-gdots';

			function mgUpdateDots( idx ) {
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

			mgWrap.addEventListener( 'scroll', function () {
				mgUpdateDots( mgIndex() );
			}, { passive: true } );

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
	}

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

	/* ---------- gallery: plus circle follows the cursor (asceno-style) ---------- */

	var gallery = document.querySelector( '.woocommerce-product-gallery' );

	if ( gallery && ! document.body.classList.contains( 'oc-no-lightbox' ) &&
		window.matchMedia( '(hover: hover)' ).matches ) {

		var plus = document.createElement( 'span' );
		plus.className = 'oc-cursor-plus';
		plus.setAttribute( 'aria-hidden', 'true' );
		document.body.appendChild( plus );

		gallery.addEventListener( 'mousemove', function ( event ) {
			if ( event.target.closest( '.oc-thumbs' ) ) {
				plus.classList.remove( 'is-on' );
				return;
			}
			if ( event.target.closest( '.woocommerce-product-gallery__image' ) ) {
				plus.classList.add( 'is-on' );
				// Physical top/left: clientX/Y are physical, and inline-start
				// mirrored the badge away from the cursor in RTL (QA round 4).
				plus.style.top = event.clientY + 'px';
				plus.style.left = event.clientX + 'px';
			} else {
				plus.classList.remove( 'is-on' );
			}
		} );

		gallery.addEventListener( 'mouseleave', function () {
			plus.classList.remove( 'is-on' );
		} );
	}

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
