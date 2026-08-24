/**
 * OC Blocks — front behaviours.
 *
 * One small file for every block: the hero slider, shelf sliders, the
 * entrance engine, the marquee and the parallax. Vanilla, no dependencies,
 * written once and shared — a page of ten sections costs the same as one.
 */
( function () {
	'use strict';

	var reduced = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/* ---------- entrances: sections step in as they arrive ---------- */

	var seen = new IntersectionObserver( function ( entries ) {
		entries.forEach( function ( entry ) {
			if ( entry.isIntersecting ) {
				entry.target.classList.add( 'is-in' );
				seen.unobserve( entry.target );
			}
		} );
	}, { rootMargin: '0px 0px -8% 0px' } );

	document.querySelectorAll( '.ocb--in-fade, .ocb--in-rise, .ocb--in-stagger' ).forEach( function ( el ) {
		if ( reduced ) {
			el.classList.add( 'is-in' );
			return;
		}

		// Stagger numbers its children once, here, so the stylesheet only
		// needs one rule.
		if ( el.classList.contains( 'ocb--in-stagger' ) ) {
			var kids = el.querySelectorAll( '.ocb-cat, .ocb-brand, .ocb-post, li.product, .ocb-words > *' );

			kids.forEach( function ( kid, i ) {
				kid.style.setProperty( '--ocb-i', String( Math.min( i, 11 ) ) );
			} );
		}

		seen.observe( el );
	} );

	/* ---------- the hero ---------- */

	document.querySelectorAll( '.ocb-hero' ).forEach( function ( hero ) {
		var strip = hero.querySelector( '.ocb-hero__strip' );
		var slides = strip ? strip.children : [];

		if ( ! strip || slides.length < 2 ) {
			return;
		}

		var fade = hero.classList.contains( 'ocb-hero--fade' );
		var at = 0;
		var timer = null;

		function count() {
			return slides.length;
		}

		function paintDots() {
			var dots = hero.querySelector( '[data-ocb-dots]' );

			if ( ! dots ) {
				return;
			}

			if ( dots.children.length !== count() ) {
				dots.innerHTML = '';

				for ( var i = 0; i < count(); i++ ) {
					( function ( n ) {
						var d = document.createElement( 'button' );
						d.type = 'button';
						d.className = 'ocb-dots__dot';
						d.addEventListener( 'click', function () {
							go( n );
						} );
						dots.appendChild( d );
					}( i ) );
				}
			}

			[].forEach.call( dots.children, function ( d, i ) {
				d.classList.toggle( 'is-on', i === at );
			} );
		}

		function go( n, instant ) {
			at = ( n + count() ) % count();

			if ( fade ) {
				[].forEach.call( slides, function ( sl, i ) {
					sl.classList.toggle( 'is-on', i === at );
				} );
			} else {
				var rtl = getComputedStyle( strip ).direction === 'rtl';
				strip.scrollTo( { left: ( rtl ? -1 : 1 ) * at * strip.clientWidth, behavior: instant ? 'auto' : 'smooth' } );
			}

			paintDots();
		}

		function arm() {
			var secs = Number( hero.dataset.ocbAuto || 0 );

			if ( ! secs || reduced ) {
				return;
			}

			clearInterval( timer );
			timer = setInterval( function () {
				go( at + 1 );
			}, secs * 1000 );
		}

		if ( fade ) {
			slides[ 0 ].classList.add( 'is-on' );
		} else {
			// A finger's own scroll keeps the dots honest.
			var tick = false;

			strip.addEventListener( 'scroll', function () {
				if ( tick ) {
					return;
				}

				tick = true;
				requestAnimationFrame( function () {
					tick = false;
					var idx = Math.round( Math.abs( strip.scrollLeft ) / Math.max( 1, strip.clientWidth ) );

					if ( idx !== at ) {
						at = Math.min( idx, count() - 1 );
						paintDots();
						arm();
					}
				} );
			}, { passive: true } );
		}

		hero.addEventListener( 'click', function ( e ) {
			var arr = e.target.closest( '[data-ocb-go]' );

			if ( arr ) {
				go( at + Number( arr.dataset.ocbGo ) );
				arm();
			}
		} );

		paintDots();
		arm();

		hero.addEventListener( 'mouseenter', function () {
			clearInterval( timer );
		} );
		hero.addEventListener( 'mouseleave', arm );
	} );

	/* ---------- shelf sliders: products, categories, brands ---------- */

	document.querySelectorAll( '[data-ocb-shelf]' ).forEach( function ( shelf ) {
		var row = shelf.querySelector( '.ocb-cats__row, .ocb-brands__row, ul.products' );

		if ( ! row ) {
			return;
		}

		function step() {
			var item = row.querySelector( ':scope > *' );

			return item ? item.getBoundingClientRect().width + 24 : row.clientWidth;
		}

		shelf.addEventListener( 'click', function ( e ) {
			var arr = e.target.closest( '[data-ocb-go]' );

			if ( arr ) {
				row.scrollBy( { left: step() * 2 * Number( arr.dataset.ocbGo ), behavior: 'smooth' } );
			}
		} );

		// Arrows grey out at the ends.
		function truth() {
			var max = row.scrollWidth - row.clientWidth;
			var gone = Math.abs( row.scrollLeft );
			var rtl = getComputedStyle( row ).direction === 'rtl';
			var atStart = gone < 2;
			var atEnd = gone > max - 2;
			var prev = shelf.querySelector( '.ocb-arr--prev' );
			var next = shelf.querySelector( '.ocb-arr--next' );

			if ( prev ) {
				prev.classList.toggle( 'is-off', rtl ? atEnd : atStart );
			}
			if ( next ) {
				next.classList.toggle( 'is-off', rtl ? atStart : atEnd );
			}

			// Nothing to scroll at all: both arrows rest.
			shelf.classList.toggle( 'ocb--still', max < 4 );
		}

		row.addEventListener( 'scroll', function () {
			requestAnimationFrame( truth );
		}, { passive: true } );

		truth();
		setTimeout( truth, 600 );
	} );

	/* ---------- the marquee ---------- */

	document.querySelectorAll( '[data-ocb-mq]' ).forEach( function ( mq ) {
		var track = mq.querySelector( '.ocb-mq__track' );

		if ( ! track ) {
			return;
		}

		// The loop must be at least two viewport-widths long, or a gap
		// slides through; clone until it is.
		var guard = 0;

		while ( track.scrollWidth < mq.clientWidth * 2 && guard < 10 ) {
			track.innerHTML += track.innerHTML;
			guard++;
		}

		if ( reduced ) {
			track.style.animation = 'none';
		}
	} );

	/* ---------- parallax: the picture drifts slower than the page ---------- */

	var lax = document.querySelectorAll( '[data-ocb-parallax]' );

	if ( lax.length && ! reduced ) {
		var laxTick = false;

		var laxDraw = function () {
			laxTick = false;

			lax.forEach( function ( media ) {
				var box = media.parentElement.getBoundingClientRect();

				if ( box.bottom < 0 || box.top > window.innerHeight ) {
					return;
				}

				var mid = box.top + box.height / 2 - window.innerHeight / 2;
				media.style.transform = 'translateY(' + ( mid * -0.12 ).toFixed( 1 ) + 'px) scale(1.12)';
			} );
		};

		window.addEventListener( 'scroll', function () {
			if ( ! laxTick ) {
				laxTick = true;
				requestAnimationFrame( laxDraw );
			}
		}, { passive: true } );

		laxDraw();
	}
}() );
