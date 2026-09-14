/**
 * Privacy: the visitor's choice, kept and honoured.
 *
 * One small script, no dependencies, deferred. It reads the choice from
 * the visitor's own cookie — the page may be hours old out of a cache, so
 * only the browser knows who is looking — shows the banner when there is
 * no choice yet, and tells everything else on the page what was decided:
 * Google's Consent Mode, the marketing script, any snippet the site owner
 * parked in a template, and anyone listening for the `oc:consent` event.
 *
 * window.ocPrivacy = { allows(cat), state(), open(), accept(), reject() }
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-oc-privacy]' );

	if ( ! root ) {
		return;
	}

	var cfg;

	try {
		cfg = JSON.parse( root.getAttribute( 'data-oc-privacy' ) || '{}' );
	} catch ( e ) {
		return;
	}

	var CATS    = cfg.cats || [];
	var COOKIE  = 'oc_consent';
	var banner  = root.querySelector( '[data-oc-privacy-banner]' );
	var panel   = root.querySelector( '[data-oc-privacy-panel]' );
	var veil    = root.querySelector( '[data-oc-privacy-veil]' );
	var badge   = root.querySelector( '.oc-privacy__badge' );
	var state   = null;      // { preferences, analytics, marketing, id, t, pv }
	var mode    = cfg.mode;  // 'optin' | 'optout' | 'auto' (until resolved)
	var region  = '';
	var defaultSent = false;
	var lastFocus = null;

	/* ---------- cookies ---------- */

	function readCookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return m ? decodeURIComponent( m[ 1 ] ) : '';
	}

	function writeCookie( name, value, days ) {
		document.cookie = name + '=' + encodeURIComponent( value ) + ';path=/;max-age=' + ( days * 86400 ) + ';SameSite=Lax' + ( 'https:' === location.protocol ? ';Secure' : '' );
	}

	function uid() {
		if ( window.crypto && crypto.randomUUID ) {
			return crypto.randomUUID();
		}
		return 'c' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 10 );
	}

	// The stored answer, if it was given for the current policy.
	function stored() {
		var raw = readCookie( COOKIE );

		if ( ! raw ) {
			return null;
		}

		// The first banner stored a bare word; honour it until the visitor
		// answers the new one.
		if ( 'granted' === raw || 'denied' === raw ) {
			var yes = 'granted' === raw;
			return { preferences: yes, analytics: yes, marketing: yes, id: '', t: '', pv: '', legacy: true };
		}

		try {
			var d = JSON.parse( raw );

			if ( ! d || ! d.v || d.pv !== cfg.pv ) {
				return null;
			}

			return { preferences: !! d.p, analytics: !! d.a, marketing: !! d.m, id: d.id || '', t: d.t || '', pv: d.pv };
		} catch ( e ) {
			return null;
		}
	}

	/* ---------- telling the page ---------- */

	window.dataLayer = window.dataLayer || [];
	function gtag() { window.dataLayer.push( arguments ); }
	window.gtag = window.gtag || gtag;

	function googleState( s ) {
		var m = s.marketing ? 'granted' : 'denied';
		return {
			ad_storage: m,
			ad_user_data: m,
			ad_personalization: m,
			analytics_storage: s.analytics ? 'granted' : 'denied',
			functionality_storage: s.preferences ? 'granted' : 'denied',
			personalization_storage: s.preferences ? 'granted' : 'denied',
			security_storage: 'granted'
		};
	}

	// A category the site does not ask about is simply allowed.
	function asked( cat ) {
		return CATS.indexOf( cat ) !== -1;
	}

	function apply( s, why ) {
		state = s;

		var g = googleState( s );

		if ( ! defaultSent ) {
			g.wait_for_update = 500;
			gtag( 'consent', 'default', g );
			gtag( 'set', 'ads_data_redaction', true );
			gtag( 'set', 'url_passthrough', true );
			defaultSent = true;
		} else {
			gtag( 'consent', 'update', g );
		}

		// Pixels already on the page hear it too.
		if ( window.fbq ) {
			try { window.fbq( 'consent', s.marketing ? 'grant' : 'revoke' ); } catch ( e ) {}
		}
		if ( window.ttq ) {
			try { s.marketing ? window.ttq.grantConsent() : window.ttq.revokeConsent(); } catch ( e ) {}
		}

		release();

		document.dispatchEvent( new CustomEvent( 'oc:consent', { detail: { state: s, why: why || 'stored' } } ) );
	}

	// Lifts parked snippets out of their templates, once each, for the
	// categories the visitor allowed.
	function release() {
		[].forEach.call( document.querySelectorAll( 'template[data-oc-consent]' ), function ( tpl ) {
			var cat = tpl.getAttribute( 'data-oc-consent' );

			if ( tpl.__ocDone || ! window.ocPrivacy.allows( cat ) ) {
				return;
			}

			tpl.__ocDone = true;

			var frag = document.createRange().createContextualFragment( tpl.innerHTML );
			( tpl.parentNode === document.head ? document.head : document.body ).appendChild( frag );
		} );
	}

	/* ---------- the visitor's answer ---------- */

	function decide( picks, why ) {
		var s = {
			preferences: asked( 'preferences' ) ? !! picks.preferences : true,
			analytics:   asked( 'analytics' )   ? !! picks.analytics   : true,
			marketing:   asked( 'marketing' )   ? !! picks.marketing   : true,
			id: ( state && state.id ) || uid(),
			t: new Date().toISOString(),
			pv: cfg.pv
		};

		writeCookie( COOKIE, JSON.stringify( { v: 1, p: s.preferences ? 1 : 0, a: s.analytics ? 1 : 0, m: s.marketing ? 1 : 0, id: s.id, t: s.t, pv: s.pv } ), 365 );
		// State first: closing the panel looks at it to decide whether the
		// banner should come back.
		apply( s, why );
		hideBanner();
		closePanel();
		record( s );
	}

	// A line in the site's consent log: proof, with no name on it.
	function record( s ) {
		if ( ! cfg.log || ! cfg.rest ) {
			return;
		}

		var body = JSON.stringify( { _t: cfg.token, id: s.id, p: s.preferences ? 1 : 0, a: s.analytics ? 1 : 0, m: s.marketing ? 1 : 0, mode: mode, region: region, pv: s.pv, lang: cfg.lang || '' } );

		try {
			fetch( cfg.rest, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'omit' } ).catch( function () {} );
		} catch ( e ) {}
	}

	/* ---------- the banner and the panel ---------- */

	function showBanner() {
		root.hidden = false;
		if ( banner ) { banner.hidden = false; }
		root.classList.add( 'is-asking' );
	}

	function hideBanner() {
		if ( banner ) { banner.hidden = true; }
		root.classList.remove( 'is-asking' );
		if ( badge ) { badge.hidden = false; }
	}

	function fillPanel() {
		[].forEach.call( panel.querySelectorAll( '[data-oc-privacy-cat]' ), function ( box ) {
			var cat = box.getAttribute( 'data-oc-privacy-cat' );
			// Opt-out starts everything on; opt-in starts everything off.
			box.checked = state ? !! state[ cat ] : ( 'optout' === mode );
		} );
	}

	function openPanel() {
		if ( ! panel ) { return; }
		root.hidden = false;
		fillPanel();
		lastFocus = document.activeElement;
		veil.hidden = false;
		panel.hidden = false;
		root.classList.add( 'is-open' );
		document.documentElement.classList.add( 'oc-privacy-lock' );
		var first = panel.querySelector( 'button, input' );
		if ( first ) { first.focus(); }
	}

	function closePanel() {
		if ( ! panel || panel.hidden ) { return; }
		panel.hidden = true;
		veil.hidden = true;
		root.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'oc-privacy-lock' );
		if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
		// Closing without an answer, while the banner is still open, leaves
		// the banner where it was. Closing after an answer leaves nothing.
		if ( ! state && banner ) { banner.hidden = false; }
	}

	function fromPanel() {
		var picks = {};
		[].forEach.call( panel.querySelectorAll( '[data-oc-privacy-cat]' ), function ( box ) {
			picks[ box.getAttribute( 'data-oc-privacy-cat' ) ] = box.checked;
		} );
		return picks;
	}

	root.addEventListener( 'click', function ( e ) {
		var t = e.target;

		if ( t.closest( '[data-oc-privacy-accept]' ) ) {
			decide( { preferences: true, analytics: true, marketing: true }, 'accept' );
		} else if ( t.closest( '[data-oc-privacy-reject]' ) ) {
			decide( { preferences: false, analytics: false, marketing: false }, 'reject' );
		} else if ( t.closest( '[data-oc-privacy-save]' ) ) {
			decide( fromPanel(), 'save' );
		} else if ( t.closest( '[data-oc-privacy-manage]' ) ) {
			if ( banner ) { banner.hidden = true; }
			openPanel();
		} else if ( t.closest( '[data-oc-privacy-open]' ) ) {
			openPanel();
		} else if ( t.closest( '[data-oc-privacy-close]' ) || t === veil ) {
			closePanel();
		}
	} );

	// Any link or button anywhere on the page may reopen the panel: the
	// footer's "Privacy settings", the policy page's button, a menu item
	// pointing at #privacy-settings.
	document.addEventListener( 'click', function ( e ) {
		var open = e.target.closest( '[data-oc-privacy-open], a[href$="#privacy-settings"]' );

		if ( open && ! root.contains( open ) ) {
			e.preventDefault();
			openPanel();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && panel && ! panel.hidden ) {
			closePanel();
		}
	} );

	// The dialog keeps focus inside itself while it is open.
	if ( panel ) {
		panel.addEventListener( 'keydown', function ( e ) {
			if ( 'Tab' !== e.key ) { return; }
			var items = panel.querySelectorAll( 'button, input, a[href]' );
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
			else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
		} );
	}

	/* ---------- the public face ---------- */

	window.ocPrivacy = {
		allows: function ( cat ) {
			if ( ! asked( cat ) ) { return true; }
			if ( state ) { return !! state[ cat ]; }
			// No answer yet: only an opt-out region may run ahead of it.
			return 'optout' === mode;
		},
		state: function () { return state; },
		mode: function () { return mode; },
		google: !! cfg.google,
		open: openPanel,
		accept: function () { decide( { preferences: true, analytics: true, marketing: true }, 'accept' ); },
		reject: function () { decide( { preferences: false, analytics: false, marketing: false }, 'reject' ); }
	};

	/* ---------- start ---------- */

	function begin() {
		var s = stored();

		if ( s ) {
			// A stored choice: honour it, show nothing but the badge.
			root.hidden = false;
			if ( badge ) { badge.hidden = false; }
			apply( s, 'stored' );
			return;
		}

		// Global Privacy Control is a browser saying "no marketing" for
		// its user. Honoured as the starting point; the banner still asks.
		var gpc = cfg.gpc && navigator.globalPrivacyControl;

		if ( 'optout' === mode ) {
			apply( { preferences: true, analytics: true, marketing: ! gpc, id: '', t: '', pv: cfg.pv }, 'default' );
		} else {
			apply( { preferences: false, analytics: false, marketing: false, id: '', t: '', pv: cfg.pv }, 'default' );
		}

		state = null; // a default is not an answer
		showBanner();
	}

	function resolveMode( then ) {
		if ( 'auto' !== mode ) {
			then();
			return;
		}

		// The page cannot say where the visitor is — it may have been
		// cached for someone else. Ask once, remember for a month, and
		// hold everything back until the answer arrives.
		var known = readCookie( 'oc_region' );

		if ( known ) {
			region = known;
			mode = /^(AT|BE|BG|HR|CY|CZ|DK|EE|FI|FR|DE|GR|HU|IE|IT|LV|LT|LU|MT|NL|PL|PT|RO|SK|SI|ES|SE|IS|LI|NO|GB|CH)$/.test( known ) ? 'optin' : 'optout';
			then();
			return;
		}

		mode = 'optin';

		var done = false;

		function settle( country, m ) {
			if ( done ) { return; }
			done = true;
			region = country || '';
			mode = m;
			if ( country ) { writeCookie( 'oc_region', country, 30 ); }
			then();
		}

		// If the server does not answer (quickly, or at all), the time
		// zone is a fair hint: a European clock means opt-in, anything
		// else opt-out.
		function guess() {
			var tz = '';
			try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch ( e ) {}
			settle( '', /^Europe\//.test( tz ) ? 'optin' : 'optout' );
		}

		var timer = setTimeout( guess, 2500 );

		fetch( cfg.rest + '/region?_t=' + encodeURIComponent( cfg.token ), { credentials: 'omit', cache: 'no-store' } )
			.then( function ( r ) { if ( ! r.ok ) { throw new Error( 'no' ); } return r.json(); } )
			.then( function ( d ) { clearTimeout( timer ); settle( ( d && d.country ) || '', ( d && d.mode ) || 'optout' ); } )
			.catch( function () { clearTimeout( timer ); guess(); } );
	}

	// A stored choice needs no region; only a first visit does.
	if ( stored() ) {
		begin();
	} else {
		resolveMode( begin );
	}
}() );
