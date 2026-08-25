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

		// The arrows sit on the middle of the pictures, not of the whole
		// card — the words under a product would drag them too low.
		function midline() {
			var img = row.querySelector( 'img' );

			if ( ! img ) {
				return;
			}

			var top = img.getBoundingClientRect().top - shelf.getBoundingClientRect().top + img.getBoundingClientRect().height / 2;

			if ( top > 20 ) {
				shelf.style.setProperty( '--ocb-arr-mid', top.toFixed( 0 ) + 'px' );
			}
		}

		window.addEventListener( 'resize', midline, { passive: true } );

		truth();
		midline();
		setTimeout( function () {
			truth();
			midline();
		}, 600 );
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

	/* ---------- shop the look ---------- */

	document.querySelectorAll( '[data-ocb-look]' ).forEach( function ( look ) {
		var spots = [].slice.call( look.querySelectorAll( '[data-ocb-spot]' ) );
		var cards = [].slice.call( look.querySelectorAll( '[data-ocb-card]' ) );
		var count = look.querySelector( '.ocb-look__count b' );
		var at = 0;

		if ( ! cards.length ) {
			return;
		}

		function show( n ) {
			at = ( n + cards.length ) % cards.length;

			spots.forEach( function ( sp, i ) {
				sp.classList.toggle( 'is-on', i === at );
			} );
			cards.forEach( function ( c, i ) {
				c.classList.toggle( 'is-on', i === at );
			} );

			if ( count ) {
				count.textContent = String( at + 1 );
			}
		}

		look.addEventListener( 'click', function ( e ) {
			var spot = e.target.closest( '[data-ocb-spot]' );

			if ( spot ) {
				show( Number( spot.dataset.ocbSpot ) );

				// On a phone the spot also opens the sheet.
				if ( window.matchMedia( '(max-width: 782px)' ).matches ) {
					look.classList.add( 'is-open' );
				}

				return;
			}

			if ( e.target.closest( '[data-ocb-look-open]' ) ) {
				look.classList.toggle( 'is-open' );
				return;
			}

			var arr = e.target.closest( '[data-ocb-go]' );

			if ( arr && e.target.closest( '.ocb-look__nav' ) ) {
				show( at + Number( arr.dataset.ocbGo ) );
			}
		} );

		// A tap outside the sheet folds it.
		document.addEventListener( 'click', function ( e ) {
			if ( look.classList.contains( 'is-open' ) && ! look.contains( e.target ) ) {
				look.classList.remove( 'is-open' );
			}
		} );
	} );

	/* ---------- the scrolling story ---------- */

	document.querySelectorAll( '[data-ocb-sc]' ).forEach( function ( sc ) {
		var frames = sc.querySelectorAll( '[data-ocb-frame]' );
		var steps = sc.querySelectorAll( '[data-ocb-step]' );

		if ( frames.length < 2 ) {
			return;
		}

		// A chapter takes the stage when it crosses the middle of the view.
		var mid = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					return;
				}

				var at = Number( entry.target.dataset.ocbStep );

				frames.forEach( function ( fr, i ) {
					fr.classList.toggle( 'is-on', i === at );
				} );
			} );
		}, { rootMargin: '-46% 0px -46% 0px', threshold: 0 } );

		steps.forEach( function ( step ) {
			mid.observe( step );
		} );
	} );

	/* ---------- questions & answers ---------- */

	document.querySelectorAll( '[data-ocb-faq]' ).forEach( function ( faq ) {
		faq.addEventListener( 'click', function ( e ) {
			var q = e.target.closest( '.ocb-faq__q' );

			if ( ! q ) {
				return;
			}

			// One at a time: opening a question folds whichever was open.
			var item = q.parentElement;
			var wasOpen = item.classList.contains( 'is-open' );

			faq.querySelectorAll( '.ocb-faq__item.is-open' ).forEach( function ( other ) {
				other.classList.remove( 'is-open' );
				other.querySelector( '.ocb-faq__q' ).setAttribute( 'aria-expanded', 'false' );
			} );

			if ( ! wasOpen ) {
				item.classList.add( 'is-open' );
				q.setAttribute( 'aria-expanded', 'true' );
			}
		} );
	} );

	/* ---------- countdown ---------- */

	document.querySelectorAll( '[data-ocb-cd]' ).forEach( function ( cd ) {
		var end = Number( cd.dataset.ocbCd ) * 1000;
		var cells = {};

		cd.querySelectorAll( '[data-ocb-cd-u]' ).forEach( function ( n ) {
			cells[ n.dataset.ocbCdU ] = n;
		} );

		var timer = 0;

		var tick = function () {
			var left = end - Date.now();

			if ( left <= 0 ) {
				clearInterval( timer );

				var done = cd.dataset.ocbCdDone || '';
				var out = cd.querySelector( '.ocb-cd__done' );

				if ( done && out ) {
					cd.querySelector( '.ocb-cd__cells' ).hidden = true;
					out.textContent = done;
					out.hidden = false;
				} else {
					// Nothing to say: the whole section stands down.
					var section = cd.closest( '.ocb' );

					if ( section ) {
						section.style.display = 'none';
					}
				}

				return;
			}

			var s = Math.floor( left / 1000 );

			var show = {
				d: Math.floor( s / 86400 ),
				h: Math.floor( ( s % 86400 ) / 3600 ),
				m: Math.floor( ( s % 3600 ) / 60 ),
				s: s % 60
			};

			Object.keys( show ).forEach( function ( unit ) {
				if ( cells[ unit ] ) {
					cells[ unit ].textContent = String( show[ unit ] ).padStart( 2, '0' );
				}
			} );
		};

		tick();
		timer = setInterval( tick, 1000 );
	} );

	/* ---------- branches: the map follows the chosen card ---------- */

	document.querySelectorAll( '[data-ocb-br]' ).forEach( function ( br ) {
		var frame = br.querySelector( '.ocb-br__map iframe' );
		var cards = [].slice.call( br.querySelectorAll( '[data-ocb-br-addr]' ) );

		if ( frame ) {
			cards.forEach( function ( card ) {
				card.addEventListener( 'click', function ( e ) {
					// A link inside the card keeps being a link.
					if ( e.target.closest( 'a' ) ) {
						return;
					}

					var addr = card.dataset.ocbBrAddr;

					if ( ! addr || card.classList.contains( 'is-on' ) ) {
						return;
					}

					cards.forEach( function ( on ) {
						on.classList.remove( 'is-on' );
					} );
					card.classList.add( 'is-on' );

					var src = new URL( frame.src );
					src.searchParams.set( 'q', addr );
					frame.src = src.toString();
				} );
			} );
		}

		// The search box hides whichever cards do not carry the words.
		var seek = ( br.parentElement || br ).parentElement.querySelector( '[data-ocb-br-q]' ) || document.querySelector( '[data-ocb-br-q]' );

		if ( seek ) {
			seek.addEventListener( 'input', function () {
				var needle = seek.value.trim();

				cards.forEach( function ( card ) {
					card.hidden = '' !== needle && card.textContent.indexOf( needle ) === -1;
				} );
			} );
		}
	} );

	/* ---------- newsletter ---------- */

	document.querySelectorAll( '[data-ocb-news]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var go = form.querySelector( '.ocb-news__go' );

			go.disabled = true;

			// getAttribute, not .action: the hidden "action" field shadows the
			// form's own property with itself.
			fetch( form.getAttribute( 'action' ), { method: 'POST', body: new FormData( form ) } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					if ( data && data.success ) {
						form.classList.add( 'is-done' );
						form.querySelector( '.ocb-news__thanks' ).hidden = false;
					} else {
						go.disabled = false;

						var mail = form.querySelector( '.ocb-news__mail' );
						mail.setCustomValidity( ( data && data.data && data.data.msg ) || '…' );
						mail.reportValidity();
						mail.addEventListener( 'input', function () {
							mail.setCustomValidity( '' );
						}, { once: true } );
					}
				} )
				.catch( function () {
					go.disabled = false;
				} );
		} );
	} );

	/* ---------- contact / leads form ---------- */

	document.querySelectorAll( '[data-ocb-lead]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var go = form.querySelector( '.ocb-lead__go' );

			go.disabled = true;

			// getAttribute, not .action: the hidden "action" field shadows
			// the form's own property with itself.
			fetch( form.getAttribute( 'action' ), { method: 'POST', body: new FormData( form ) } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					if ( data && data.success ) {
						form.classList.add( 'is-done' );
						form.querySelector( '.ocb-lead__thanks' ).hidden = false;
					} else {
						go.disabled = false;

						var first = form.querySelector( 'input[name="name"]' );
						first.setCustomValidity( ( data && data.data && data.data.msg ) || '…' );
						first.reportValidity();
						first.addEventListener( 'input', function () {
							first.setCustomValidity( '' );
						}, { once: true } );
					}
				} )
				.catch( function () {
					go.disabled = false;
				} );
		} );
	} );

	/* ---------- parallax: the picture drifts slower than the page ---------- */

	var lax = document.querySelectorAll( '[data-ocb-parallax]' );

	if ( lax.length && ! reduced ) {
		var laxDraw = function () {
			lax.forEach( function ( media ) {
				var box = media.parentElement.getBoundingClientRect();

				if ( box.bottom < 0 || box.top > window.innerHeight ) {
					return;
				}

				// Strength (1..100, old yes/no markup means 30) sets how far
				// the picture falls behind the page. The travel is mapped to
				// the banner's whole journey across the viewport, so it moves
				// the entire time — no clamp, no dead stop — and lands on
				// exactly the headroom the zoom buys at either end.
				var pct = parseInt( media.dataset.ocbParallax, 10 ) || 30;
				var capFrac = 0.03 + ( pct / 100 ) * 0.15;
				var span = ( window.innerHeight + box.height ) / 2;
				var mid = box.top + box.height / 2 - window.innerHeight / 2;
				var shift = ( mid / span ) * -capFrac * box.height;
				var zoom = ( 1 + capFrac * 2 + 0.01 ).toFixed( 3 );

				media.style.transform = 'translateY(' + shift.toFixed( 1 ) + 'px) scale(' + zoom + ')';
			} );
		};

		// Drawn straight from the scroll event, not via requestAnimationFrame:
		// browsers starve rAF inside transform-scaled iframes — exactly where
		// the composer preview lives — and the picture froze there.
		window.addEventListener( 'scroll', laxDraw, { passive: true } );
		window.addEventListener( 'resize', laxDraw, { passive: true } );

		laxDraw();
		setTimeout( laxDraw, 400 );
		setTimeout( laxDraw, 1200 );
	}
}() );
