/**
 * The onboarding questionnaire: one script, no library.
 *
 * Draws the screens from the schema the page handed it, saves every change
 * the moment it settles, and never blocks on a field that is not required.
 */
( function () {
	'use strict';

	var C = window.ocOnboard;

	if ( ! C || ! C.schema ) {
		return;
	}

	var root   = document.getElementById( 'oc-onb' );
	var saveEl = document.getElementById( 'oc-onb-save' );
	var F      = C.schema.fields;
	var I      = C.i18n;
	var values = C.values || {};
	var screens = [];
	var pending = {};
	var saveTimer = null;
	var cameFrom = 0;    // The screen the review was opened from.
	var waiting = [];    // Typing that has not reached the draft yet.
	var looked  = false; // Whether we already went looking on their old site.
	var foundIn = {};    // Fields whose address we found rather than asked for.
	var at = -1; // -1 welcome, 0..n-1 screens, n summary, n+1 done.
	var far = -1; // The furthest screen they have opened in this sitting.

	var parts = C.schema.parts || {};

	C.schema.steps.forEach( function ( step, si ) {
		var part = step.part || 1;

		// The part that just ended gets a word of its own before the next one.
		if ( screens.length && part !== screens[ screens.length - 1 ].part ) {
			addGate( screens[ screens.length - 1 ].part );
		}

		step.screens.forEach( function ( sc ) {
			screens.push( { part: part, step: step, stepIndex: si, id: sc.id, title: sc.title, intro: sc.intro, intro_m: sc.intro_m || '', preview: sc.preview || '', fields: sc.fields } );
		} );
	} );

	/**
	 * The screen that hands one part over to the next. There is no such
	 * screen after the last part: what follows the final question is the
	 * review, and a button promising more would be a lie.
	 *
	 * @param {number} part The part that just ended.
	 */
	function addGate( part ) {
		var p = parts[ part ] || parts[ String( part ) ];

		if ( ! p || ! p.text ) { return; }

		screens.push( {
			part: part,
			gate: true,
			id: 'gate-' + part,
			title: p.done || '',
			intro: p.text,
			link: p.link || '',
			ahead: p.ahead || '',
			fields: [],
			next: p.next || I.next
		} );
	}

	// The gates are not questions, so they are not counted as steps.
	var asked = screens.filter( function ( s ) { return ! s.gate; } );

	/**
	 * The furthest screen the questionnaire has been taken to. Steps up to
	 * there can be reopened; nothing past it can be jumped to. A sitting
	 * that resumes an older one counts the screens that already hold
	 * answers, so going back does not wall off work already done.
	 *
	 * @return {number} The screen index.
	 */
	function reach() {
		var top = Math.max( far, at );

		screens.forEach( function ( s, i ) {
			if ( i <= top ) { return; }

			for ( var k = 0; k < s.fields.length; k++ ) {
				if ( Object.prototype.hasOwnProperty.call( values, s.fields[ k ] ) ) {
					top = i;
					return;
				}
			}
		} );

		return top;
	}

	/* ------------------------------------------------------------ values */

	function val( id ) {
		if ( Object.prototype.hasOwnProperty.call( values, id ) && values[ id ] !== null && values[ id ] !== undefined ) {
			return values[ id ];
		}
		return F[ id ] ? F[ id ]['default'] : '';
	}

	function isEmpty( v ) {
		if ( v === null || v === undefined || v === false ) { return true; }
		if ( Array.isArray( v ) ) { return v.length === 0; }
		if ( typeof v === 'object' ) { return Object.keys( v ).length === 0; }
		return String( v ).trim() === '';
	}

	function ruleHolds( rule ) {
		var deps = String( rule[0] ).split( '|' );
		var want = rule[1];
		for ( var i = 0; i < deps.length; i++ ) {
			var d = deps[ i ];
			if ( ! shown( d ) ) { continue; }
			var has = val( d );
			if ( want === 'filled' ) {
				if ( ! isEmpty( has ) ) { return true; }
				continue;
			}
			var list = Array.isArray( want ) ? want : [ want ];
			if ( list.map( String ).indexOf( String( has ) ) !== -1 ) { return true; }
		}
		return false;
	}

	function shown( id ) {
		var f = F[ id ];
		if ( ! f || ! f.when ) { return true; }
		if ( Array.isArray( f.when[0] ) ) {
			for ( var r = 0; r < f.when.length; r++ ) {
				if ( ! ruleHolds( f.when[ r ] ) ) { return false; }
			}
			return true;
		}
		return ruleHolds( f.when );
	}

	/**
	 * Is this one of a choice's options on offer? An option may carry a rule
	 * of its own, in the same shape as a field's.
	 *
	 * @param {Object} f The field.
	 * @param {string} k The option key.
	 */
	function optionShown( f, k ) {
		var rule = f.options_when && f.options_when[ k ];

		if ( ! rule ) { return true; }

		if ( Array.isArray( rule[0] ) ) {
			for ( var i = 0; i < rule.length; i++ ) {
				if ( ! ruleHolds( rule[ i ] ) ) { return false; }
			}
			return true;
		}

		return ruleHolds( rule );
	}

	function missingIn( ids ) {
		var out = [];
		ids.forEach( function ( id ) {
			var f = F[ id ];
			if ( ! f || ! f.required || ! shown( id ) ) { return; }
			var v = val( id );
			if ( f.type === 'consent' ) { if ( v !== true ) { out.push( id ); } return; }
			if ( f.type === 'layout' ) {
				// An arrangement is answered row by row: a part that cannot
				// be built without a word or a choice has to have it.
				if ( layoutGaps( f, v ).length ) { out.push( id ); }
				return;
			}
			if ( f.type === 'repeater' ) {
				if ( isEmpty( v ) ) { out.push( id ); return; }
				for ( var r = 0; r < v.length; r++ ) {
					for ( var k in f.fields ) {
						if ( f.fields[ k ].required && isEmpty( v[ r ][ k ] ) ) { out.push( id ); return; }
					}
				}
				return;
			}
			if ( isEmpty( v ) ) { out.push( id ); }
		} );
		return out;
	}

	/* ------------------------------------------------------------ saving */

	function api( path, opts ) {
		opts = opts || {};
		var headers = opts.headers || {};
		headers['X-OC-Token'] = C.token;
		if ( ! ( opts.body instanceof FormData ) ) {
			headers['Content-Type'] = 'application/json';
		}
		return fetch( C.rest + path, { method: opts.method || 'POST', headers: headers, body: opts.body, credentials: 'omit' } )
			.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, status: r.status, data: j }; } ); } );
	}

	function set( id, v ) {
		values[ id ] = v;
		pending[ id ] = v;
		fixChoices();
		queueSave();
		refreshVisibility();
		paintPreview();
	}

	/**
	 * An answer whose option has just stopped being on offer goes back to the
	 * default: a customer who says the old site is gone should not be left
	 * with "take it from my site" as their answer.
	 */
	function fixChoices() {
		Object.keys( F ).forEach( function ( id ) {
			var f = F[ id ];

			if ( 'choice' !== f.type || ! f.options_when ) { return; }

			var cur = String( val( id ) );

			if ( '' !== cur && f.options[ cur ] && ! optionShown( f, cur ) ) {
				values[ id ]  = f['default'];
				pending[ id ] = f['default'];
			}
		} );
	}

	function queueSave() {
		clearTimeout( saveTimer );
		note( I.saving, 'busy' );
		saveTimer = setTimeout( flush, 700 );
	}

	function flush() {
		commitAll();

		var batch = pending;
		pending = {};
		if ( Object.keys( batch ).length === 0 && ! flush.stepOnly ) { return; }
		var step = at >= 0 && at < screens.length ? screens[ at ].id : ( at >= screens.length ? 'summary' : '' );
		return api( '/draft', { body: JSON.stringify( { fields: batch, step: step } ) } )
			.then( function ( r ) {
				if ( ! r.ok ) { var err = new Error( 'save' ); err.stale = ( r.status === 401 || r.status === 403 ); throw err; }
				note( I.saved, 'ok' );
			} )
			.catch( function ( e ) {
				Object.keys( batch ).forEach( function ( k ) { if ( ! ( k in pending ) ) { pending[ k ] = batch[ k ]; } } );
				if ( e && e.stale ) { note( I.link_stale, 'err' ); return; }
				note( I.save_failed, 'err' );
				setTimeout( queueSave, 5000 );
			} );
	}

	function saveStep() {
		flush.stepOnly = true;
		var p = flush();
		flush.stepOnly = false;
		return p;
	}

	/* -------------------------------------------------- their current site */

	/**
	 * When the customer has a site already, their four pages are usually a
	 * click away on its home page. We look for them once, fill the addresses
	 * in, and say so. Anything the customer has already touched is left alone.
	 */
	function maybeDiscover() {
		if ( looked || String( val( 'existing_has' ) ) !== 'yes' || isEmpty( val( 'existing_url' ) ) ) { return; }

		looked = true;

		( flush() || Promise.resolve() )
			.then( function () { return api( '/discover', { body: '{}' } ); } )
			.then( function ( r ) {
				if ( ! r.ok || ! r.data || ! r.data.found || ! prefill( r.data.found ) ) { return; }
				note( I.found_pages, 'ok' );
				if ( at >= 0 && at < screens.length ) { renderScreen( at ); }
			} )
			.catch( function () {} );
	}

	/**
	 * Put the addresses we found into the pages that have not been answered.
	 *
	 * @param {Object} hit kind → address.
	 * @return {boolean} Whether anything was filled in.
	 */
	function prefill( hit ) {
		var any = false;

		[ 'about', 'terms', 'privacy', 'a11y' ].forEach( function ( kind ) {
			var url  = hit[ kind ];
			var mode = kind + '_mode';
			var uid  = kind + '_url';

			if ( ! url || ! F[ mode ] || ! F[ uid ] ) { return; }

			// Never argue with an answer the customer has already given.
			if ( Object.prototype.hasOwnProperty.call( values, mode ) || ! isEmpty( val( uid ) ) ) { return; }

			set( mode, 'link' );
			set( uid, url );
			foundIn[ uid ] = true;
			any = true;
		} );

		return any;
	}

	/**
	 * Hand over everything that is still being typed.
	 *
	 * A field waits a moment after the last keystroke before it saves, so
	 * that one word is not four requests. That moment is also a hole: press
	 * Next inside it and the last thing typed never reaches the draft. So
	 * anything mid-flight is written down first, by anyone about to leave
	 * the screen or send.
	 */
	function commitAll() {
		waiting.slice().forEach( function ( fn ) {
			try { fn(); } catch ( e ) {}
		} );
	}

	/**
	 * Remember a field's way of writing itself down now.
	 *
	 * @param {Function} fn Commit.
	 * @return {Function} How to forget it.
	 */
	function awaits( fn ) {
		waiting.push( fn );

		return function () {
			var i = waiting.indexOf( fn );
			if ( i > -1 ) { waiting.splice( i, 1 ); }
		};
	}

	function note( text, cls ) {
		if ( ! saveEl ) { return; }
		saveEl.textContent = text;
		saveEl.className = 'oc-onb-top__save is-' + cls;
	}

	/* ------------------------------------------------------------ dom */

	function el( tag, attrs, children ) {
		var e = document.createElement( tag );
		attrs = attrs || {};
		for ( var k in attrs ) {
			if ( k === 'class' ) { e.className = attrs[ k ]; }
			else if ( k === 'text' ) { e.textContent = attrs[ k ]; }
			else if ( k === 'html' ) { e.innerHTML = attrs[ k ]; }
			else if ( k.indexOf( 'on' ) === 0 ) { e.addEventListener( k.slice( 2 ), attrs[ k ] ); }
			else if ( attrs[ k ] !== null && attrs[ k ] !== false && attrs[ k ] !== undefined ) { e.setAttribute( k, attrs[ k ] ); }
		}
		( children || [] ).forEach( function ( c ) { if ( c ) { e.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c ); } } );
		return e;
	}

	function fmt( s ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return s.replace( /%(\d)\$[sd]|%[sd]/g, function ( m, n ) { return n ? args[ n - 1 ] : args.shift(); } );
	}

	/* ------------------------------------------------------------ fields */

	function fieldBox( id, f, inner ) {
		// A field that only appears because of the answer above it is drawn
		// as part of that answer, not as a question of its own.
		var dep = f.when ? ' is-dep' : '';
		var box = el( 'div', { 'class': 'oc-onb-f oc-onb-f--' + f.type + dep, 'data-field': id } );

		if ( 'consent' !== f.type && '' !== String( f.label || '' ) ) {
			box.appendChild( el( 'div', { 'class': 'oc-onb-f__label' }, [
				el( 'span', { text: f.label } ),
				f.required ? el( 'span', { 'class': 'oc-onb-f__req', text: ' *', 'aria-label': I.required } ) : null
			] ) );
		}
		if ( f.help ) { box.appendChild( el( 'p', { 'class': 'oc-onb-f__help', text: f.help } ) ); }
		if ( foundIn[ id ] ) { box.appendChild( el( 'p', { 'class': 'oc-onb-f__found', text: I.found_hint } ) ); }
		box.appendChild( inner );
		box.appendChild( el( 'p', { 'class': 'oc-onb-f__err', text: I.required } ) );
		if ( ! shown( id ) ) { box.hidden = true; }
		return box;
	}

	function inputFor( id, f, value, onChange ) {
		var type = { phone: 'tel', email: 'email', url: 'url', number: 'number' }[ f.type ] || 'text';
		var attrs = { type: type, value: value === null || value === undefined ? '' : value, 'class': 'oc-onb-in', autocomplete: 'off' };
		if ( f.dir ) { attrs.dir = f.dir; }
		if ( f.type === 'phone' || f.type === 'email' || f.type === 'url' ) { attrs.dir = 'ltr'; }
		if ( f.type === 'number' ) { if ( f.min !== undefined ) { attrs.min = f.min; } if ( f.max !== undefined ) { attrs.max = f.max; } attrs.inputmode = 'numeric'; }
		if ( f.type === 'phone' ) { attrs.inputmode = 'tel'; }
		if ( f.placeholder ) { attrs.placeholder = f.placeholder; }
		var e = el( 'input', attrs );

		if ( f.suffix ) { e.classList.add( 'oc-onb-in--unit' ); }

		var seen   = String( value === null || value === undefined ? '' : value );
		var commit = function () {
			clearTimeout( e._t );

			if ( String( e.value ) === seen ) { return; }

			seen = String( e.value );
			onChange( f.type === 'number' ? Number( e.value ) : e.value );
		};

		e.addEventListener( 'input', function () { clearTimeout( e._t ); e._t = setTimeout( commit, 150 ); } );
		e.addEventListener( 'change', commit );
		e.addEventListener( 'blur', commit );

		awaits( commit );

		if ( f.suffix ) {
			return el( 'div', { 'class': 'oc-onb-unit' }, [ e, el( 'span', { 'class': 'oc-onb-unit__t', text: f.suffix } ) ] );
		}

		return e;
	}

	function render_text( id, f ) {
		return inputFor( id, f, val( id ), function ( v ) { set( id, v ); } );
	}

	function render_textarea( id, f, value, onChange ) {
		var e = el( 'textarea', { 'class': 'oc-onb-in oc-onb-ta', rows: f.rows || 5 } );

		e.value = value === undefined ? ( val( id ) || '' ) : ( value || '' );

		var seen   = String( e.value );
		var commit = function () {
			clearTimeout( e._t );

			if ( String( e.value ) === seen ) { return; }

			seen = String( e.value );
			( onChange || function ( v ) { set( id, v ); } )( e.value );
		};

		e.addEventListener( 'input', function () { clearTimeout( e._t ); e._t = setTimeout( commit, 400 ); } );
		e.addEventListener( 'change', commit );
		e.addEventListener( 'blur', commit );

		awaits( commit );

		return e;
	}

	function render_choice( id, f ) {
		var cur  = String( val( id ) );
		var keys = Object.keys( f.options ).filter( function ( k ) { return optionShown( f, k ); } );
		var wide = keys.length === 2 && keys.every( function ( k ) { return String( f.options[ k ] ).length <= 24; } );
		var wrap = el( 'div', { 'class': 'oc-onb-choices' + ( wide ? ' oc-onb-choices--two' : '' ), role: 'radiogroup' } );

		// Has the customer actually answered this one, or is what they see
		// only our suggestion? The two should not look the same.
		var answered = Object.prototype.hasOwnProperty.call( values, id );

		// An option that is not on offer cannot be the answer either: taking
		// the page from a site they do not have, for instance.
		if ( keys.indexOf( cur ) === -1 ) {
			cur = String( f['default'] );
		}

		keys.forEach( function ( k ) {
			var on  = cur === k;
			var lab = el( 'label', { 'class': 'oc-onb-choice' + ( on ? ( answered ? ' is-on' : ' is-default' ) : '' ) } );
			var inp = el( 'input', { type: 'radio', name: 'f_' + id, value: k } );
			inp.checked = on;
			inp.addEventListener( 'change', function () {
				wrap.querySelectorAll( '.oc-onb-choice' ).forEach( function ( c ) { c.classList.remove( 'is-on', 'is-default' ); } );
				wrap.querySelectorAll( '.oc-onb-choice__tag' ).forEach( function ( t ) { t.remove(); } );
				lab.classList.add( 'is-on' );
				set( id, k );

				var consentId = id.replace( /_mode$/, '_consent' );

				if ( 'template' === k && F[ consentId ] && val( consentId ) !== true ) {
					askConsent( id, consentId );
				}
			} );
			lab.appendChild( inp );
			lab.appendChild( el( 'span', { 'class': 'oc-onb-choice__t', text: f.options[ k ] } ) );

			if ( on && ! answered ) {
				lab.appendChild( el( 'span', { 'class': 'oc-onb-choice__tag', text: I.suggested } ) );
			}

			wrap.appendChild( lab );
		} );
		return wrap;
	}

	/**
	 * A choice made of drawings. Same answer as a radio group, except that
	 * the customer is looking at the thing rather than reading about it.
	 *
	 * @param {string} id Field id.
	 * @param {Object} f  Field.
	 */
	function render_pick( id, f ) {
		var cur  = String( val( id ) );
		var keys = Object.keys( f.options ).filter( function ( k ) { return optionShown( f, k ); } );
		var wrap = el( 'div', { 'class': 'oc-onb-picks', role: 'radiogroup' } );

		if ( keys.indexOf( cur ) === -1 ) { cur = String( f['default'] ); }

		keys.forEach( function ( k ) {
			var on  = cur === k;
			var lab = el( 'label', { 'class': 'oc-onb-pick' + ( on ? ' is-on' : '' ) } );
			var inp = el( 'input', { type: 'radio', name: 'f_' + id, value: k } );
			var art = ( C.art || {} )[ ( f.art || {} )[ k ] ] || '';

			inp.checked = on;
			inp.addEventListener( 'change', function () {
				wrap.querySelectorAll( '.oc-onb-pick' ).forEach( function ( c ) { c.classList.remove( 'is-on' ); } );
				lab.classList.add( 'is-on' );
				set( id, k );
			} );

			lab.appendChild( inp );
			lab.appendChild( el( 'span', { 'class': 'oc-onb-pick__art', html: art, 'aria-hidden': 'true' } ) );
			lab.appendChild( el( 'span', { 'class': 'oc-onb-pick__t' }, [
				el( 'span', { 'class': 'oc-onb-pick__dot', 'aria-hidden': 'true' } ),
				el( 'span', { text: f.options[ k ] } )
			] ) );

			if ( f.notes && f.notes[ k ] ) {
				lab.appendChild( el( 'span', { 'class': 'oc-onb-pick__n', text: f.notes[ k ] } ) );
			}

			wrap.appendChild( lab );
		} );

		return wrap;
	}

	/**
	 * One example at a time, as big as the card allows, with a way to flip
	 * through them. Three small pictures side by side are too small to tell
	 * apart; this is the same question asked so it can be answered.
	 *
	 * @param {string} id Field id.
	 * @param {Object} f  Field.
	 */
	function render_gallery( id, f ) {
		var keys = Object.keys( f.options ).filter( function ( k ) { return optionShown( f, k ); } );
		var wrap = el( 'div', { 'class': 'oc-onb-gal' } );
		var at   = Math.max( 0, keys.indexOf( String( val( id ) ) ) );
		var stage = el( 'div', { 'class': 'oc-onb-gal__stage' } );
		var name  = el( 'div', { 'class': 'oc-onb-gal__name' } );
		var dots  = el( 'div', { 'class': 'oc-onb-gal__dots' } );

		function show( i ) {
			at = ( i + keys.length ) % keys.length;

			var k = keys[ at ];

			stage.innerHTML = '';
			stage.appendChild( f.show ? previewFor2( f.show, k ) : el( 'div', { 'class': 'oc-onb-pick__art', html: ( C.art || {} )[ ( f.art || {} )[ k ] ] || '' } ) );

			name.textContent = f.options[ k ];
			wrap.classList.toggle( 'is-chosen', String( val( id ) ) === k );

			dots.innerHTML = '';
			keys.forEach( function ( kk, j ) {
				dots.appendChild( el( 'span', { 'class': 'oc-onb-gal__dot' + ( j === at ? ' is-on' : '' ) + ( String( val( id ) ) === kk ? ' is-picked' : '' ) } ) );
			} );

			take.textContent = String( val( id ) ) === k ? I.gal_taken : I.gal_take;
			take.disabled    = String( val( id ) ) === k ? 'disabled' : null;
			take.classList.toggle( 'is-taken', String( val( id ) ) === k );
		}

		var take = el( 'button', { type: 'button', 'class': 'oc-onb-btn', text: I.gal_take } );

		take.addEventListener( 'click', function () {
			set( id, keys[ at ] );
			show( at );
		} );

		var skip = el( 'button', { type: 'button', 'class': 'oc-onb-btn oc-onb-btn--ghost', text: I.gal_next } );

		skip.addEventListener( 'click', function () {
			show( at + 1 );
			wrap.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		} );

		var prev = el( 'button', { type: 'button', 'class': 'oc-onb-gal__arrow oc-onb-gal__arrow--prev', 'aria-label': I.back, text: '›' } );
		var nextB = el( 'button', { type: 'button', 'class': 'oc-onb-gal__arrow oc-onb-gal__arrow--next', 'aria-label': I.next, text: '‹' } );

		prev.addEventListener( 'click', function () { show( at - 1 ); } );
		nextB.addEventListener( 'click', function () { show( at + 1 ); } );

		wrap.appendChild( el( 'div', { 'class': 'oc-onb-gal__frame' }, [ prev, stage, nextB ] ) );
		wrap.appendChild( el( 'div', { 'class': 'oc-onb-gal__bar' }, [ name, dots ] ) );
		wrap.appendChild( el( 'div', { 'class': 'oc-onb-gal__btns' }, [ take, skip ] ) );

		show( at );

		return wrap;
	}

	/**
	 * A drawing of one option, for the gallery.
	 *
	 * @param {string} kind What to draw.
	 * @param {string} k    Which option.
	 */
	function previewFor2( kind, k ) {
		if ( 'header' === kind ) {
			var was = values.home_header;
			values.home_header = k;
			var out = previewBanner();
			values.home_header = was;
			return out;
		}
		return el( 'div' );
	}

	/**
	 * A number with a minus and a plus. For a small range — how many
	 * products stand in a row — this is quicker than typing and it cannot
	 * be answered with nonsense.
	 *
	 * @param {string} id Field id.
	 * @param {Object} f  Field.
	 */
	function render_stepper( id, f ) {
		var min  = f.min === undefined ? 1 : Number( f.min );
		var max  = f.max === undefined ? 10 : Number( f.max );
		var out  = el( 'div', { 'class': 'oc-onb-step' } );
		var now  = el( 'span', { 'class': 'oc-onb-step__n' } );
		var less = el( 'button', { type: 'button', 'class': 'oc-onb-step__b', 'aria-label': I.less, text: '−' } );
		var more = el( 'button', { type: 'button', 'class': 'oc-onb-step__b', 'aria-label': I.more, text: '+' } );

		function paint() {
			var v = Math.max( min, Math.min( max, Number( val( id ) ) || min ) );
			now.textContent = String( v );
			less.disabled = v <= min ? 'disabled' : null;
			more.disabled = v >= max ? 'disabled' : null;
		}

		function step( by ) {
			var v = Math.max( min, Math.min( max, ( Number( val( id ) ) || min ) + by ) );
			set( id, String( v ) );
			paint();
		}

		less.addEventListener( 'click', function () { step( -1 ); } );
		more.addEventListener( 'click', function () { step( 1 ); } );

		out.appendChild( less );
		out.appendChild( now );
		out.appendChild( more );

		if ( f.suffix ) {
			out.appendChild( el( 'span', { 'class': 'oc-onb-step__u', text: f.suffix } ) );
		}

		paint();

		return out;
	}

	/**
	 * The heading a band shows when the customer has written none. The list
	 * offers it as the grey placeholder, the sketch draws it and the page is
	 * built with it, so the three never disagree.
	 *
	 * @param {Object} row The row.
	 * @param {number} nth Which one of its kind it is, counting from one.
	 * @return {string} The heading, or '' where a band has none.
	 */
	function bandTitle( row, nth ) {
		if ( 'products' === row.type ) {
			var named = { sale: I.wf_sale_h, sales: I.wf_best_h, manual: I.wf_pick_h, 'new': I.wf_new_h };

			return named[ row.variant ] || ( nth > 1 ? I.wf_sale_h : I.wf_new_h );
		}

		return {
			categories: I.wf_cats_h,
			look: I.wf_look_h,
			posts: I.wf_posts_h,
			brands: I.wf_brands_h,
			faq: I.wf_faq_h,
			scrolly: I.wf_story_h
		}[ row.type ] || '';
	}

	/**
	 * Which rows of an arrangement are still waiting for an answer: a
	 * running line with nothing to say, or a shelf with no shelf chosen.
	 *
	 * @param {Object} f The field.
	 * @param {Array}  v The rows.
	 * @return {Array} Their places in the list.
	 */
	function layoutGaps( f, v ) {
		var blocks = ( f || {} ).blocks || {};
		var gaps   = [];

		( Array.isArray( v ) ? v : [] ).forEach( function ( row, i ) {
			var b = blocks[ row.type ];

			if ( ! b || ! row.on ) { return; }

			if ( b.text && '' === String( row.text || '' ).trim() ) { gaps.push( i ); return; }
			if ( b.variants && b.blank && '' === String( row.variant || '' ) ) { gaps.push( i ); }
		} );

		return gaps;
	}

	/**
	 * The home page, as a list of its parts.
	 *
	 * Every row is one band of the page. A row can be hidden, moved, copied
	 * or thrown away, and the drawing beside the list follows every change.
	 * Anything deeper than a name — the pictures, the words — is asked on the
	 * screens that come after the arranging, so this screen stays a list of
	 * what the page is made of rather than a form.
	 *
	 * @param {string} id Field id.
	 * @param {Object} f  Field.
	 */
	function render_layout( id, f ) {
		var blocks = f.blocks || {};
		var wrap   = el( 'div', { 'class': 'oc-onb-lay' } );
		var list   = el( 'div', { 'class': 'oc-onb-lay__rows' } );

		function rows() {
			var v = val( id );
			return Array.isArray( v ) ? v : [];
		}

		function save( next ) {
			set( id, next );
			draw();
		}

		function move( from, to ) {
			var r = rows().slice();

			if ( to < 0 || to >= r.length ) { return; }

			r.splice( to, 0, r.splice( from, 1 )[0] );
			save( r );
		}

		/**
		 * The arrangement as one line of text, for telling it apart from
		 * the one we handed them.
		 *
		 * @param {Array} v The rows.
		 */
		function shape( v ) {
			return ( Array.isArray( v ) ? v : [] ).map( function ( row ) {
				return [ row.type, row.on ? 1 : 0, row.title || '', row.text || '', row.variant || '' ].join( '\u0001' );
			} ).join( '\u0002' );
		}

		function draw() {
			var r   = rows();
			var nth = {};

			// Nothing has been moved: there is nothing to put back, and an
			// offer to undo work nobody did is only a worry.
			back.hidden = shape( r ) === shape( f['default'] );

			list.innerHTML = '';

			// An empty page is a real answer, so there has to be a way back
			// from it: a customer who clears the list is not stranded.
			if ( ! r.length ) {
				list.appendChild( el( 'p', { 'class': 'oc-onb-lay__none', text: I.row_none } ) );
			}

			r.forEach( function ( row, i ) {
				var b = blocks[ row.type ] || { label: row.type };
				var line = el( 'div', { 'class': 'oc-onb-row' + ( row.on ? '' : ' is-off' ), draggable: 'true', 'data-i': i, 'data-row': i } );

				nth[ row.type ] = ( nth[ row.type ] || 0 ) + 1;

				line.addEventListener( 'dragstart', function ( e ) {
					e.dataTransfer.setData( 'text/plain', String( i ) );
					line.classList.add( 'is-dragging' );
				} );
				line.addEventListener( 'dragend', function () { line.classList.remove( 'is-dragging' ); } );
				line.addEventListener( 'dragover', function ( e ) { e.preventDefault(); line.classList.add( 'is-over' ); } );
				line.addEventListener( 'dragleave', function () { line.classList.remove( 'is-over' ); } );
				line.addEventListener( 'drop', function ( e ) {
					e.preventDefault();
					line.classList.remove( 'is-over' );
					move( parseInt( e.dataTransfer.getData( 'text/plain' ), 10 ), i );
				} );

				var head = el( 'div', { 'class': 'oc-onb-row__head' } );

				head.appendChild( el( 'span', { 'class': 'oc-onb-row__grip', 'aria-hidden': 'true', text: '⠿' } ) );
				head.appendChild( el( 'span', { 'class': 'oc-onb-row__name', text: b.label } ) );

				var tools = el( 'div', { 'class': 'oc-onb-row__tools' } );

				tools.appendChild( tool( '↑', I.row_up, function () { move( i, i - 1 ); }, i === 0 ) );
				tools.appendChild( tool( '↓', I.row_down, function () { move( i, i + 1 ); }, i === r.length - 1 ) );

				if ( ! b.once ) {
					tools.appendChild( tool( 'copy', I.row_copy, function () {
						var n = rows().slice();
						n.splice( i + 1, 0, merge( n[ i ], {} ) );
						save( n );
					} ) );
				}

				tools.appendChild( tool( 'bin', I.row_drop, function () {
					var n = rows().slice();
					n.splice( i, 1 );
					save( n );
				} ) );

				head.appendChild( tools );
				line.appendChild( head );

				// What a row lets you say from here: its own heading, the
				// words that run across, or which shape a content area takes.
				if ( b.title || b.text ) {
					var key = b.title ? 'title' : 'text';
					var inp = el( 'input', {
						type: 'text',
						'class': 'oc-onb-row__in',
						value: row[ key ] || '',
						placeholder: b.title ? ( bandTitle( row, nth[ row.type ] ) || I.row_title ) : I.row_text
					} );

					var was = String( row[ key ] || '' );
					var put = function () {
						clearTimeout( inp._t );

						if ( String( inp.value ) === was ) { return; }

						was = String( inp.value );

						var n     = rows().slice();
						var patch = {};

						patch[ key ] = inp.value;
						n[ i ]       = merge( n[ i ], patch );

						set( id, n );
					};

					inp.addEventListener( 'input', function () {
						clearTimeout( inp._t );
						line.classList.remove( 'is-missing' );
						inp._t = setTimeout( put, 150 );
					} );
					inp.addEventListener( 'blur', put );

					awaits( put );

					line.appendChild( inp );
				}

				if ( b.variants ) {
					var sel = el( 'select', { 'class': 'oc-onb-in oc-onb-row__sel' } );

					// A block that starts blank leads with the question, so
					// the list never answers it for them.
					if ( b.blank ) {
						sel.appendChild( el( 'option', { value: '', text: I.row_which, selected: '' === String( row.variant || '' ) ? 'selected' : null } ) );
					}

					Object.keys( b.variants ).forEach( function ( k ) {
						var o = el( 'option', { value: k, text: b.variants[ k ] } );
						if ( k === row.variant ) { o.selected = 'selected'; }
						sel.appendChild( o );
					} );

					sel.addEventListener( 'change', function () {
						var n = rows().slice();
						n[ i ] = merge( n[ i ], { variant: sel.value } );
						save( n );
					} );

					line.appendChild( sel );
				}

				if ( b.note ) {
					line.appendChild( el( 'p', { 'class': 'oc-onb-row__note', text: b.note } ) );
				}

				list.appendChild( line );
			} );

			paintPreview();
		}

		function tool( glyph, label, onclick, off ) {
			var b = el( 'button', { type: 'button', 'class': 'oc-onb-row__b', title: label, 'aria-label': label } );

			if ( 'bin' === glyph || 'copy' === glyph ) {
				b.classList.add( 'oc-onb-row__b--' + glyph );
				b.innerHTML = 'bin' === glyph
					? '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 3h6l.6 2H18v2H2V5h4.4L7 3Zm-2.3 6h10.6l-.8 9.2a1 1 0 0 1-1 .8H6.5a1 1 0 0 1-1-.8L4.7 9Z"/></svg>'
					: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 2h8a2 2 0 0 1 2 2v9h-2V4H7V2Z"/><path d="M4 6h8a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"/></svg>';
			} else {
				b.textContent = glyph;
			}

			if ( off ) { b.disabled = 'disabled'; }

			b.addEventListener( 'click', onclick );

			return b;
		}

		// Adding a part: a quiet row of everything on offer.
		var add = el( 'div', { 'class': 'oc-onb-lay__add' } );

		add.appendChild( el( 'span', { 'class': 'oc-onb-lay__addt', text: I.row_add } ) );

		Object.keys( blocks ).forEach( function ( type ) {
			var b = blocks[ type ];
			var btn = el( 'button', { type: 'button', 'class': 'oc-onb-chip', text: b.label } );

			btn.addEventListener( 'click', function () {
				var n = rows().slice();

				if ( b.once && n.some( function ( r ) { return r.type === type; } ) ) { return; }

				var row = { type: type, on: 1 };

				if ( b.title ) { row.title = ''; }
				if ( b.text ) { row.text = ''; }
				if ( b.variants ) { row.variant = b.blank ? '' : Object.keys( b.variants )[0]; }

				n.push( row );
				save( n );
			} );

			add.appendChild( btn );
		} );

		var back = el( 'button', { type: 'button', 'class': 'oc-onb-link oc-onb-lay__back', text: I.row_reset } );

		back.addEventListener( 'click', function () {
			confirmBox( I.row_reset, I.row_reset_warn, I.row_reset_go, function () {
				save( ( f['default'] || [] ).map( function ( row ) { return merge( row, {} ); } ) );
			} );
		} );

		add.appendChild( back );

		wrap.appendChild( list );
		wrap.appendChild( add );

		draw();

		return wrap;
	}

	/**
	 * A row with a change on top of it.
	 *
	 * @param {Object} row   The row.
	 * @param {Object} patch What changed.
	 */
	function merge( row, patch ) {
		var out = {};

		Object.keys( row || {} ).forEach( function ( k ) { out[ k ] = row[ k ]; } );
		Object.keys( patch || {} ).forEach( function ( k ) { out[ k ] = patch[ k ]; } );

		return out;
	}

	function render_checks( id, f, value, onChange ) {
		var cur = ( value === undefined ? val( id ) : value ) || [];
		var wrap = el( 'div', { 'class': 'oc-onb-checks' } );
		Object.keys( f.options ).forEach( function ( k ) {
			var lab = el( 'label', { 'class': 'oc-onb-check' + ( cur.indexOf( k ) !== -1 ? ' is-on' : '' ) } );
			var inp = el( 'input', { type: 'checkbox', value: k } );
			inp.checked = cur.indexOf( k ) !== -1;
			inp.addEventListener( 'change', function () {
				var next = Array.prototype.slice.call( wrap.querySelectorAll( 'input:checked' ) ).map( function ( i ) { return i.value; } );
				lab.classList.toggle( 'is-on', inp.checked );
				( onChange || function ( v ) { set( id, v ); } )( next );
			} );
			lab.appendChild( inp );
			lab.appendChild( el( 'span', { text: f.options[ k ] } ) );
			wrap.appendChild( lab );
		} );
		return wrap;
	}

	function render_consent( id, f ) {
		var wrap = el( 'div', { 'class': 'oc-onb-consent' } );
		var mode = id.replace( /_consent$/, '_mode' );

		function paint() {
			wrap.innerHTML = '';

			if ( val( id ) === true ) {
				wrap.appendChild( el( 'p', { 'class': 'oc-onb-agreed' }, [
					el( 'span', { 'class': 'oc-onb-agreed__v', 'aria-hidden': 'true', text: '✓' } ),
					el( 'span', { text: f.label } )
				] ) );
				return;
			}

			// Waiting on the dialog — which is also how a reopened link
			// gets back to it.
			wrap.appendChild( el( 'button', {
				type: 'button',
				'class': 'oc-onb-btn oc-onb-btn--ghost',
				text: I.consent_open,
				onclick: function () { askConsent( mode, id ); }
			} ) );
		}

		wrap.__paint = paint;
		paint();
		return wrap;
	}

	/**
	 * A question with a way out of it. Used where a press undoes work the
	 * customer has already done, so the undoing is never a surprise.
	 *
	 * @param {string}   title The heading.
	 * @param {string}   text  What is about to happen.
	 * @param {string}   okay  The word on the button that does it.
	 * @param {Function} then  What to do once they say yes.
	 */
	function confirmBox( title, text, okay, then ) {
		if ( document.querySelector( '.oc-onb-modal' ) ) { return; }

		var card = el( 'div', { 'class': 'oc-onb-modal__card', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'oc-onb-modal-h' } );
		var back = el( 'div', { 'class': 'oc-onb-modal' }, [ card ] );

		function close() {
			document.removeEventListener( 'keydown', key, true );
			back.remove();
		}

		function key( e ) {
			if ( e.key === 'Escape' ) { e.preventDefault(); e.stopPropagation(); close(); }
		}

		card.appendChild( el( 'h2', { id: 'oc-onb-modal-h', text: title } ) );
		card.appendChild( el( 'p', { 'class': 'oc-onb-modal__warn', text: text } ) );

		var yes = el( 'button', { type: 'button', 'class': 'oc-onb-btn', text: okay } );
		yes.addEventListener( 'click', function () { close(); then(); } );

		var no = el( 'button', { type: 'button', 'class': 'oc-onb-btn oc-onb-btn--ghost', text: I.cancel } );
		no.addEventListener( 'click', close );

		card.appendChild( el( 'div', { 'class': 'oc-onb-modal__btns' }, [ yes, no ] ) );
		document.body.appendChild( back );
		document.addEventListener( 'keydown', key, true );
		no.focus();
	}

	/**
	 * The disclaimer, as a dialog there is no way past: either the customer
	 * confirms it, or the choice goes back to uploading their own.
	 */
	function askConsent( modeId, consentId ) {
		if ( document.querySelector( '.oc-onb-modal' ) ) { return; }

		var card = el( 'div', { 'class': 'oc-onb-modal__card', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'oc-onb-modal-h' } );
		var back = el( 'div', { 'class': 'oc-onb-modal' }, [ card ] );

		function close() {
			document.removeEventListener( 'keydown', trap, true );
			back.remove();
		}

		function trap( e ) {
			if ( e.key === 'Escape' ) { e.preventDefault(); e.stopPropagation(); }
		}

		card.appendChild( el( 'h2', { id: 'oc-onb-modal-h', text: I.consent_title } ) );
		card.appendChild( el( 'p', { 'class': 'oc-onb-modal__warn', text: C.disclaimer } ) );

		var ok = el( 'button', { type: 'button', 'class': 'oc-onb-btn', text: F[ consentId ] ? F[ consentId ].label : I.consent_ok } );
		ok.addEventListener( 'click', function () {
			set( consentId, true );
			var box = root.querySelector( '[data-field="' + consentId + '"] .oc-onb-consent' );
			if ( box && box.__paint ) { box.__paint(); }
			close();
		} );

		var no = el( 'button', { type: 'button', 'class': 'oc-onb-btn oc-onb-btn--ghost', text: I.consent_upload } );
		no.addEventListener( 'click', function () {
			set( consentId, false );
			set( modeId, 'upload' );
			var pick = root.querySelector( '[data-field="' + modeId + '"] input[value="upload"]' );
			if ( pick ) { pick.checked = true; }
			root.querySelectorAll( '[data-field="' + modeId + '"] .oc-onb-choice' ).forEach( function ( c ) {
				c.classList.toggle( 'is-on', !! c.querySelector( 'input:checked' ) );
			} );
			close();
		} );

		card.appendChild( el( 'div', { 'class': 'oc-onb-modal__btns' }, [ ok, no ] ) );
		document.body.appendChild( back );
		document.addEventListener( 'keydown', trap, true );
		ok.focus();
	}

	var fileSeq = 0;

	/**
	 * A file control. `o` says where the answer goes: nothing for a plain
	 * field, or { row, sub, value, onChange } for one inside a repeater row.
	 */
	function render_file( id, f, o ) {
		o = o || {};
		var wrap = el( 'div', { 'class': 'oc-onb-file' } );
		var accept = f.accept === 'image' ? 'image/jpeg,image/png,image/webp,image/gif,image/svg+xml' : '.pdf,.docx,.doc,.txt';
		var dom = 'ocfile_' + ( ++fileSeq );
		var inp = el( 'input', { type: 'file', accept: accept, 'class': 'oc-onb-file__in', id: dom } );
		var btn = el( 'label', { 'class': 'oc-onb-btn oc-onb-btn--ghost', 'for': dom, text: I.choose_file } );
		var show = el( 'div', { 'class': 'oc-onb-file__show' } );
		var mine = o.value || null;

		function paint() {
			var v = o.sub ? mine : val( id );
			show.innerHTML = '';
			if ( ! v || ! v.url ) { return; }
			if ( f.accept === 'image' ) { show.appendChild( el( 'img', { src: v.thumb || v.url, alt: '' } ) ); }
			show.appendChild( el( 'span', { text: v.name || '' } ) );
			show.appendChild( el( 'button', { type: 'button', 'class': 'oc-onb-link', text: I.remove, onclick: function () {
				if ( o.sub ) { mine = null; o.onChange( null ); } else { set( id, null ); }
				paint();
			} } ) );
		}

		inp.addEventListener( 'change', function () {
			if ( ! inp.files || ! inp.files[0] ) { return; }
			var fd = new FormData();
			fd.append( 'file', inp.files[0] );
			fd.append( 'field', id );
			if ( o.sub ) { fd.append( 'row', o.row ); fd.append( 'sub', o.sub ); }
			note( I.uploading, 'busy' );
			api( '/upload', { body: fd } ).then( function ( r ) {
				if ( ! r.ok || ! r.data.file ) { throw new Error( 'up' ); }
				var file = r.data.file;
				file.thumb = r.data.thumb;
				if ( o.sub ) { mine = file; o.onChange( file, true ); } else { values[ id ] = file; }
				note( I.saved, 'ok' );
				paint();
				refreshVisibility();
			} ).catch( function () { note( I.upload_failed, 'err' ); } );
			inp.value = '';
		} );

		paint();
		wrap.appendChild( inp );
		wrap.appendChild( btn );
		wrap.appendChild( show );
		return wrap;
	}

	/**
	 * The accessibility checklist, one group per branch the customer named.
	 */
	function render_branch_access( id, f ) {
		var rows = val( f.of ) || [];
		var cur = val( id ) || {};

		if ( ! rows.length ) {
			return el( 'p', { 'class': 'oc-onb-f__help', text: I.branches_first } );
		}

		var wrap = el( 'div', { 'class': 'oc-onb-bacc' } );

		rows.forEach( function ( row, i ) {
			var ticked = cur[ i ] || cur[ String( i ) ] || [];
			var name = row.name || '';
			var one = el( 'div', { 'class': 'oc-onb-bacc__one' } );
			one.appendChild( el( 'h3', { 'class': 'oc-onb-bacc__n', text: ( name || ( I.rowword || '' ) + ' ' + ( i + 1 ) ) + ( row.city ? ' · ' + row.city : '' ) } ) );
			one.appendChild( render_checks( id + '_' + i, f, ticked, function ( v ) {
				var all = val( id ) || {};
				all[ i ] = v;
				set( id, all );
			} ) );
			wrap.appendChild( one );
		} );

		return wrap;
	}

	function render_hours( id, f ) {
		var wrap = el( 'div', { 'class': 'oc-onb-hours' } );
		var rows = ( val( id ) || [] ).map( function ( r ) { return { days: r.days.slice(), from: r.from, to: r.to }; } );
		if ( rows.length === 0 ) { rows.push( { days: [ 0, 1, 2, 3, 4 ], from: '09:00', to: '18:00' } ); }

		function commit() {
			set( id, rows.filter( function ( r ) { return r.days.length && r.from && r.to; } ) );
		}

		function paint() {
			wrap.innerHTML = '';
			rows.forEach( function ( r, ri ) {
				var row = el( 'div', { 'class': 'oc-onb-hours__row' } );
				var days = el( 'div', { 'class': 'oc-onb-days', 'aria-label': I.days } );
				C.days.forEach( function ( d ) {
					var b = el( 'button', { type: 'button', 'class': 'oc-onb-day' + ( r.days.indexOf( d.n ) !== -1 ? ' is-on' : '' ), text: d.label, title: d.full, 'aria-pressed': r.days.indexOf( d.n ) !== -1 ? 'true' : 'false' } );
					b.addEventListener( 'click', function () {
						var i = r.days.indexOf( d.n );
						if ( i === -1 ) { r.days.push( d.n ); } else { r.days.splice( i, 1 ); }
						b.classList.toggle( 'is-on', i === -1 );
						b.setAttribute( 'aria-pressed', i === -1 ? 'true' : 'false' );
						commit();
					} );
					days.appendChild( b );
				} );
				var from = el( 'input', { type: 'time', value: r.from, 'class': 'oc-onb-in oc-onb-in--time', 'aria-label': I.from } );
				var to   = el( 'input', { type: 'time', value: r.to, 'class': 'oc-onb-in oc-onb-in--time', 'aria-label': I.to } );
				from.addEventListener( 'change', function () { r.from = from.value; commit(); } );
				to.addEventListener( 'change', function () { r.to = to.value; commit(); } );
				var times = el( 'div', { 'class': 'oc-onb-hours__t' }, [ el( 'span', { text: I.from } ), from, el( 'span', { text: I.to } ), to ] );
				row.appendChild( days );
				row.appendChild( times );
				if ( rows.length > 1 ) {
					row.appendChild( el( 'button', { type: 'button', 'class': 'oc-onb-link', text: I.remove, onclick: function () { rows.splice( ri, 1 ); commit(); paint(); } } ) );
				}
				wrap.appendChild( row );
			} );
			wrap.appendChild( el( 'button', { type: 'button', 'class': 'oc-onb-btn oc-onb-btn--ghost', text: I.add_hours, onclick: function () { rows.push( { days: [ 5 ], from: '09:00', to: '13:00' } ); commit(); paint(); } } ) );
		}

		paint();
		return wrap;
	}

	function render_repeater( id, f ) {
		var wrap = el( 'div', { 'class': 'oc-onb-rep' } );
		var rows = ( val( id ) || [] ).map( function ( r ) { return Object.assign( {}, r ); } );
		if ( rows.length === 0 ) { rows.push( {} ); }

		function commit() { set( id, rows ); }

		function paint() {
			wrap.innerHTML = '';
			rows.forEach( function ( r, ri ) {
				var card = el( 'div', { 'class': 'oc-onb-rep__row' } );
				card.appendChild( el( 'div', { 'class': 'oc-onb-rep__h' }, [
					el( 'b', { text: ( f.row || '' ) + ' ' + ( ri + 1 ) } ),
					rows.length > 1 ? el( 'button', { type: 'button', 'class': 'oc-onb-link', text: I.remove, onclick: function () { rows.splice( ri, 1 ); commit(); paint(); } } ) : null
				] ) );
				Object.keys( f.fields ).forEach( function ( k ) {
					var sf = f.fields[ k ];
					var inner;
					var onChange = function ( v ) { r[ k ] = v; commit(); };
					if ( sf.type === 'textarea' ) { inner = render_textarea( id + '.' + k, sf, r[ k ] || '', onChange ); }
					else if ( sf.type === 'checks' ) { inner = render_checks( id + '.' + k, sf, r[ k ] || [], onChange ); }
					else if ( sf.type === 'file' ) { inner = render_file( id, sf, { row: ri, sub: k, value: r[ k ] || null, onChange: function ( v, saved ) { r[ k ] = v; if ( saved ) { values[ id ] = rows; } else { commit(); } } } ); }
					else { inner = inputFor( id + '.' + k, sf, r[ k ] || '', onChange ); }
					var box = el( 'div', { 'class': 'oc-onb-f oc-onb-f--sub' }, [
						el( 'div', { 'class': 'oc-onb-f__label' }, [ el( 'span', { text: sf.label } ), sf.required ? el( 'span', { 'class': 'oc-onb-f__req', text: ' *' } ) : null ] ),
						inner
					] );
					card.appendChild( box );
				} );
				wrap.appendChild( card );
			} );
			if ( rows.length < ( f.max || 20 ) ) {
				wrap.appendChild( el( 'button', { type: 'button', 'class': 'oc-onb-btn oc-onb-btn--ghost', text: f.add || '+', onclick: function () { rows.push( {} ); commit(); paint(); } } ) );
			}
		}

		paint();
		return wrap;
	}

	function renderField( id ) {
		var f = F[ id ];
		if ( ! f ) { return null; }
		var inner;
		switch ( f.type ) {
			case 'textarea': inner = render_textarea( id, f ); break;
			case 'choice':   inner = render_choice( id, f ); break;
			case 'pick':     inner = render_pick( id, f ); break;
			case 'gallery':  inner = render_gallery( id, f ); break;
			case 'stepper':  inner = render_stepper( id, f ); break;
			case 'layout':   inner = render_layout( id, f ); break;
			case 'checks':   inner = render_checks( id, f ); break;
			case 'consent':  inner = render_consent( id, f ); break;
			case 'file':     inner = render_file( id, f ); break;
			case 'branch_access': inner = render_branch_access( id, f ); break;
			case 'hours':    inner = render_hours( id, f ); break;
			case 'repeater': inner = render_repeater( id, f ); break;
			case 'info':     return el( 'div', { 'class': 'oc-onb-info', text: f.label } );
			default:         inner = render_text( id, f );
		}
		return fieldBox( id, f, inner );
	}

	function refreshVisibility() {
		root.querySelectorAll( '[data-field]' ).forEach( function ( box ) {
			box.hidden = ! shown( box.getAttribute( 'data-field' ) );
		} );

		// A heading with nothing under it is noise.
		root.querySelectorAll( '.oc-onb-grp' ).forEach( function ( grp ) {
			var any = false;

			grp.querySelectorAll( '[data-field]' ).forEach( function ( box ) {
				if ( ! box.hidden ) { any = true; }
			} );

			grp.hidden = ! any;
		} );
	}


	/* ------------------------------------------------------- the sketch */

	/**
	 * A drawing of the site that answers back. Every screen in part two
	 * shows one beside the questions, and it redraws on every change, so a
	 * customer sees what they are choosing instead of reading about it.
	 *
	 * Deliberately a sketch and not a screenshot: grey boxes, crossed
	 * rectangles where pictures go, and stand-in words. Nobody mistakes it
	 * for the finished site, and nothing in it has to be kept up to date.
	 */

	function w( cls, children, text ) {
		return el( 'div', { 'class': cls, text: text === undefined ? null : text }, children || [] );
	}

	/**
	 * A crossed rectangle: here a picture will go.
	 *
	 * @param {string} cls  Extra classes.
	 * @param {string} src  A real picture to show instead, if there is one.
	 */
	function wImg( cls, src ) {
		var box = el( 'div', { 'class': 'wf-img ' + ( cls || '' ) } );

		if ( src ) {
			box.classList.add( 'is-real' );
			box.style.backgroundImage = 'url(' + src + ')';
		}

		return box;
	}

	function wLine( width, cls ) {
		return el( 'div', { 'class': 'wf-ln ' + ( cls || '' ), style: 'width:' + width } );
	}

	/**
	 * One product card, with the labels the catalogue answers ask about.
	 *
	 * @param {number} n Which one, so the stand-in names differ.
	 */
	function wCard( n ) {
		var kids = [];
		var art  = wImg( 'wf-card__img' );

		if ( 'none' !== String( val( 'card_sale' ) ) ) {
			art.appendChild( el( 'span', { 'class': 'wf-tag wf-tag--sale', text: 'text' === String( val( 'card_sale' ) ) ? I.wf_sale : '20%-' } ) );
		}

		if ( 'yes' === String( val( 'card_new' ) ) ) {
			art.appendChild( el( 'span', { 'class': 'wf-tag wf-tag--new', text: I.wf_new } ) );
		}

		kids.push( art );
		kids.push( el( 'div', { 'class': 'wf-card__t', text: fmt( I.wf_product, n ) } ) );

		if ( 'yes' === String( val( 'card_excerpt' ) ) ) {
			kids.push( el( 'div', { 'class': 'wf-card__x', text: I.wf_excerpt } ) );
		}

		kids.push( el( 'div', { 'class': 'wf-card__p', text: '₪' + ( 80 + ( n * 35 ) ) + '.00' } ) );

		return w( 'wf-card', kids );
	}

	/**
	 * A row of product cards.
	 *
	 * @param {number} cols How many across.
	 * @param {number} from The first stand-in number.
	 */
	function wRow( cols, from ) {
		var n   = onPhone() ? Math.min( 2, cols ) : cols;
		var row = el( 'div', { 'class': 'wf-grid', style: '--wf-cols:' + n } );

		for ( var i = 0; i < n; i++ ) {
			row.appendChild( wCard( from + i ) );
		}

		return row;
	}

	/**
	 * The page's own furniture: a top bar and a foot.
	 *
	 * @param {Array}  kids  What sits between them.
	 * @param {Object} opts  over: the bar sits on the picture.
	 */
	function wPage( kids, opts ) {
		opts = opts || {};

		var top = w( 'wf-top', null, I.wf_top );
		var bar = w( 'wf-bar' + ( opts.over ? ' wf-bar--over' : '' ), [
			w( 'wf-bar__logo', null, C.site && C.site.name ? C.site.name : I.wf_logo ),
			w( 'wf-bar__nav', [ wLine( '34px' ), wLine( '28px' ), wLine( '40px' ), wLine( '24px' ) ] ),
			w( 'wf-bar__icons', [ wLine( '12px' ), wLine( '12px' ), wLine( '12px' ) ] )
		] );

		var foot = w( 'wf-foot', [ wLine( '60px' ), wLine( '40px' ), wLine( '52px' ) ] );

		var head = opts.over ? w( 'wf-head wf-head--over', [ bar ] ) : bar;

		return w( 'wf' + ( opts.over ? ' wf--over' : '' ), [ top, head ].concat( kids ).concat( [ foot ] ) );
	}

	/**
	 * The banner, as the customer is filling it in.
	 */
	function wBanner( tall ) {
		var pic   = val( 'home_banner' );
		var hero  = wImg( 'wf-hero' + ( tall ? ' wf-hero--tall' : '' ), pic && pic.url ? pic.url : '' );
		var title = String( val( 'banner_title' ) || '' ).trim() || 'NEW COLLECTION';
		var cta   = String( val( 'banner_cta' ) || '' ).trim() || 'SHOP NOW';

		hero.appendChild( w( 'wf-hero__in', [
			el( 'div', { 'class': 'wf-hero__h', text: title } ),
			el( 'div', { 'class': 'wf-hero__b', text: cta } )
		] ) );

		return hero;
	}

	/**
	 * A strip of category tiles.
	 */
	function wCats() {
		var names = ( I.wf_cats || [] ).slice( 0, onPhone() ? 2 : 4 );
		var row   = el( 'div', { 'class': 'wf-grid wf-grid--cats', style: '--wf-cols:' + ( onPhone() ? 2 : 4 ) } );

		names.forEach( function ( name ) {
			row.appendChild( w( 'wf-cat', [ wImg( 'wf-cat__img' ), el( 'div', { 'class': 'wf-cat__t', text: name } ) ] ) );
		} );

		return row;
	}

	/**
	 * One reason to buy: a mark, a line, a word under it.
	 *
	 * @param {string} kind truck | returns | shield.
	 * @param {string} text What it says.
	 */
	function wTrust( kind, text ) {
		var art = {
			truck: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h10v9H3V6Zm11 3h3.6l2.4 3v3h-6V9Z" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/></svg>',
			returns: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.6-5.9" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M20 3v5h-5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>',
			shield: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.2-2.9 7.8-7 9-4.1-1.2-7-4.8-7-9V6l7-3Z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m8.6 11.8 2.3 2.3 4.3-4.3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>'
		}[ kind ];

		return w( 'wf-trust__i', [
			el( 'span', { 'class': 'wf-trust__ic', html: art, 'aria-hidden': 'true' } ),
			el( 'div', { 'class': 'wf-trust__h', text: text } ),
			wLine( '70%' )
		] );
	}

	function wHeading( text ) {
		return el( 'div', { 'class': 'wf-h', text: text } );
	}

	/**
	 * A styled room with the products marked on it, the way the Shop the
	 * Look band really reads: a photograph you can press.
	 */
	function wSofa( cls ) {
		return w( 'wf-f wf-f--sofa ' + ( cls || '' ), [
			w( 'wf-f__back' ),
			w( 'wf-f__seat' ),
			w( 'wf-f__arm wf-f__arm--a' ),
			w( 'wf-f__arm wf-f__arm--b' ),
			w( 'wf-f__foot wf-f__foot--a' ),
			w( 'wf-f__foot wf-f__foot--b' )
		] );
	}

	function wLamp( cls ) {
		return w( 'wf-f wf-f--lamp ' + ( cls || '' ), [
			w( 'wf-f__shade' ),
			w( 'wf-f__stem' ),
			w( 'wf-f__base' )
		] );
	}

	function wPlant( cls ) {
		return w( 'wf-f wf-f--plant ' + ( cls || '' ), [
			w( 'wf-f__leaf wf-f__leaf--a' ),
			w( 'wf-f__leaf wf-f__leaf--b' ),
			w( 'wf-f__leaf wf-f__leaf--c' ),
			w( 'wf-f__pot' )
		] );
	}

	/**
	 * A room with the things in it marked, and the marked thing standing
	 * beside it as the product it is — the same drawing, so the eye joins
	 * the two without being told.
	 */
	function wLook() {
		var scene = w( 'wf-room', [
			w( 'wf-room__art' ),
			wPlant( 'wf-room__plant' ),
			wLamp( 'wf-room__lamp' ),
			wSofa( 'wf-room__sofa' ),
			el( 'span', { 'class': 'wf-spot wf-spot--sofa is-live' } ),
			el( 'span', { 'class': 'wf-spot wf-spot--lamp' } )
		] );

		var card = w( 'wf-card', [
			w( 'wf-card__img wf-card__img--draw', [ wSofa( 'wf-f--card' ) ] ),
			el( 'div', { 'class': 'wf-card__t', text: I.wf_sofa } ),
			el( 'div', { 'class': 'wf-card__p', text: '₪1,890.00' } )
		] );

		return w( 'wf-look', [ scene, w( 'wf-look__side', [ card ] ) ] );
	}

	/**
	 * One band of the home page, drawn.
	 *
	 * @param {Object} row The row the customer arranged.
	 * @param {number} nth Which one of its kind this is, counting from one.
	 */
	function wBand( row, nth ) {
		var title = String( row.title || '' ).trim();

		if ( 'banner' === row.type ) { return wBanner( false ); }

		if ( 'marquee' === row.type ) {
			var words = String( row.text || '' ).trim() || I.wf_marquee;
			var track = el( 'div', { 'class': 'wf-mq__t' } );

			// The same words over and over, the way the real strip is built:
			// the track slides by whole copies, so the line has no end to
			// show. How many copies it takes to cover the strip twice is a
			// question of width, and fillMarquees() tops them up once the
			// sketch is on the page.
			for ( var c = 0; c < 4; c++ ) {
				track.appendChild( el( 'span', { text: words } ) );
			}

			return w( 'wf-mq', [ track ] );
		}

		if ( 'products' === row.type ) {
			var other = 'sale' === row.variant || ( ! row.variant && nth > 1 );

			return w( 'wf-band', [ wHeading( title || bandTitle( row, nth ) ), wRow( 4, other ? 5 : 1 ) ] );
		}

		if ( 'categories' === row.type ) {
			return w( 'wf-band', [ wHeading( title || I.wf_cats_h ), wCats() ] );
		}

		if ( 'look' === row.type ) {
			return w( 'wf-band', [ wHeading( title || I.wf_look_h ), wLook() ] );
		}

		if ( 'posts' === row.type ) {
			return w( 'wf-band', [ wHeading( title || I.wf_posts_h ), wPosts() ] );
		}

		if ( 'icons' === row.type ) {
			return w( 'wf-trust', [
				wTrust( 'truck', I.wf_trust1 ),
				wTrust( 'returns', I.wf_trust2 ),
				wTrust( 'shield', I.wf_trust3 )
			] );
		}

		if ( 'brands' === row.type ) {
			var logos = el( 'div', { 'class': 'wf-logos' } );

			for ( var b = 0; b < 5; b++ ) { logos.appendChild( w( 'wf-logo' ) ); }

			return w( 'wf-band', [ wHeading( title || I.wf_brands_h ), logos ] );
		}

		if ( 'faq' === row.type ) {
			return w( 'wf-band', [ wHeading( title || I.wf_faq_h ), w( 'wf-faq', [
				w( 'wf-faq__q is-open', [ el( 'span', { text: I.wf_faq_1 } ), el( 'span', { 'class': 'wf-faq__s', text: '−' } ) ] ),
				w( 'wf-faq__a', [ wLine( '100%' ), wLine( '80%' ) ] ),
				w( 'wf-faq__q', [ el( 'span', { text: I.wf_faq_2 } ), el( 'span', { 'class': 'wf-faq__s', text: '+' } ) ] ),
				w( 'wf-faq__q', [ el( 'span', { text: I.wf_faq_3 } ), el( 'span', { 'class': 'wf-faq__s', text: '+' } ) ] )
			] ) ] );
		}

		if ( 'scrolly' === row.type ) {
			return w( 'wf-band', [ wHeading( title || I.wf_story_h ), w( 'wf-sticky', [
				w( 'wf-sticky__t', [ el( 'div', { 'class': 'wf-ed__eye', text: I.wf_eyebrow } ), wLine( '100%' ), wLine( '80%' ) ] ),
				w( 'wf-sticky__m', [ wImg( 'wf-sticky__img' ), wImg( 'wf-sticky__img' ) ] )
			] ) ] );
		}

		return wContent( String( row.variant || 'words' ) );
	}

	/**
	 * A content area, in whichever shape was chosen for it.
	 *
	 * @param {string} kind words | video | two | sticky.
	 */
	function wContent( kind ) {
		if ( 'words' === kind ) {
			return w( 'wf-words', [
				wHeading( I.wf_about_h ),
				w( 'wf-words__p', [ wLine( '100%' ), wLine( '94%' ), wLine( '60%' ) ] )
			] );
		}

		var words = w( 'wf-ed__t', [
			el( 'div', { 'class': 'wf-ed__eye', text: I.wf_eyebrow } ),
			wHeading( I.wf_about_h ),
			wLine( '100%' ),
			wLine( '92%' ),
			wLine( '64%' ),
			w( 'wf-ed__btn', null, I.wf_read )
		] );
		var media = w( 'wf-ed__m' );

		if ( 'duo' === kind ) {
			media.appendChild( wImg( 'wf-ed__tall' ) );
			media.appendChild( wImg( 'wf-ed__tall wf-ed__tall--step' ) );
		} else if ( 'canvas' === kind ) {
			var wide = wImg( 'wf-ed__wide' );

			wide.appendChild( wImg( 'wf-ed__guest' ) );
			media.appendChild( wide );
		} else if ( 'overlap' === kind ) {
			var tall = wImg( 'wf-ed__tall wf-ed__tall--off' );
			var film = wImg( 'wf-ed__film' );

			film.appendChild( el( 'span', { 'class': 'wf-play', 'aria-hidden': 'true', text: '▶' } ) );
			tall.appendChild( film );
			media.appendChild( tall );
		} else {
			var one = wImg( 'wf-ed__tall wf-ed__tall--one' );

			one.appendChild( el( 'span', { 'class': 'wf-play', 'aria-hidden': 'true', text: '▶' } ) );
			media.appendChild( one );
		}

		return w( 'wf-ed wf-ed--' + kind, [ words, media ] );
	}

	/**
	 * Three cards from the magazine.
	 */
	function wPosts() {
		var n   = onPhone() ? 1 : 3;
		var row = el( 'div', { 'class': 'wf-grid', style: '--wf-cols:' + n } );

		for ( var i = 1; i <= n; i++ ) {
			row.appendChild( w( 'wf-post', [
				wImg( 'wf-post__img' ),
				el( 'div', { 'class': 'wf-post__d', text: '02.10.2026' } ),
				el( 'div', { 'class': 'wf-card__t', text: fmt( I.wf_post, i ) } ),
				wLine( '90%' ),
				wLine( '70%' )
			] ) );
		}

		return row;
	}

	/**
	 * The home page, drawn from the rows the customer arranged.
	 */
	function previewHome() {
		var rows = val( 'home_layout' );
		var over = 'home' === String( val( 'home_header' ) );
		var mid  = [];

		var nth = {};

		( Array.isArray( rows ) ? rows : [] ).forEach( function ( row ) {
			nth[ row.type ] = ( nth[ row.type ] || 0 ) + 1;

			if ( ! row.on ) { return; }

			mid.push( wBand( row, nth[ row.type ] ) );
		} );

		return wPage( mid, { over: over } );
	}

	/**
	 * The banner on its own, large.
	 */
	function previewBanner() {
		return wPage( [ wBanner( false ), wHeading( I.wf_cats_h ), wCats() ], { over: 'home' === String( val( 'home_header' ) ) } );
	}

	/**
	 * The category page, as its answers describe it.
	 */
	function previewCategory() {
		var kind  = String( val( 'cat_hero' ) );
		var cols  = Math.max( 2, Math.min( 5, parseInt( val( 'cat_cols' ), 10 ) || 3 ) );
		var side  = 'sidebar' === String( val( 'cat_filters' ) );
		var top   = 'topbar' === String( val( 'cat_filters' ) );
		var mid   = [];

		if ( 'full' === kind ) {
			mid.push( w( 'wf-chero', [ wImg( 'wf-chero__img' ), w( 'wf-chero__in', [ el( 'div', { 'class': 'wf-hero__h', text: I.wf_cat_name } ) ] ) ] ) );
		} else if ( 'split' === kind ) {
			mid.push( w( 'wf-chero wf-chero--split', [
				wImg( 'wf-chero__img' ),
				w( 'wf-chero__words', [ el( 'div', { 'class': 'wf-h', text: I.wf_cat_name } ), wLine( '90%' ), wLine( '60%' ) ] )
			] ) );
		} else {
			mid.push( wHeading( I.wf_cat_name ) );
		}

		if ( top ) {
			mid.push( w( 'wf-filters wf-filters--top', [ wLine( '60px' ), wLine( '48px' ), wLine( '70px' ), wLine( '40px' ) ] ) );
		}

		var shelf = w( 'wf-shelf', [ wRow( cols, 1 ), wRow( cols, cols + 1 ) ] );

		if ( side ) {
			mid.push( w( 'wf-withside', [
				w( 'wf-filters wf-filters--side', [
					el( 'div', { 'class': 'wf-h wf-h--s', text: I.wf_filters } ),
					wLine( '100%' ), wLine( '80%' ), wLine( '90%' ), wLine( '60%' ), wLine( '85%' )
				] ),
				shelf
			] ) );
		} else {
			mid.push( shelf );
		}

		mid.push( 'numbers' === String( val( 'cat_paging' ) )
			? w( 'wf-pages', [ w( 'wf-pg is-on', null, '1' ), w( 'wf-pg', null, '2' ), w( 'wf-pg', null, '3' ) ] )
			: w( 'wf-more', null, I.wf_more ) );

		return wPage( mid, {} );
	}

	/**
	 * Which drawing a screen asks for.
	 *
	 * @param {string} kind home | banner | category.
	 */
	function previewFor( kind ) {
		if ( 'banner' === kind ) { return previewBanner(); }
		if ( 'category' === kind ) { return previewCategory(); }
		return previewHome( String( val( 'home_recipe' ) ) );
	}

	/**
	 * Draw it again, wherever it is standing.
	 */
	function paintPreview() {
		var holder = root.querySelector( '[data-preview]' );

		if ( ! holder ) { return; }

		holder.classList.toggle( 'is-phone', onPhone() );
		holder.innerHTML = '';
		holder.appendChild( previewFor( holder.getAttribute( 'data-preview' ) ) );
		fillMarquees( holder );
	}

	/**
	 * A running line loops without a seam only while the track is wider
	 * than two strips: it slides by half of itself, and whatever stands
	 * where it started has to be there again at the end. Short words need
	 * more copies than long ones, and only the page knows how wide they
	 * came out, so the copies are topped up here rather than guessed.
	 *
	 * @param {HTMLElement} holder The sketch, already on the page.
	 */
	function fillMarquees( holder ) {
		var strips = holder.querySelectorAll( '.wf-mq' );

		Array.prototype.forEach.call( strips, function ( strip ) {
			var track = strip.querySelector( '.wf-mq__t' );

			if ( ! track || ! track.firstChild ) { return; }

			// A sketch that is drawn while its tab is hidden measures zero;
			// a sensible floor keeps those strips long enough to loop.
			var want = Math.max( strip.clientWidth, 380 ) * 2.2;

			for ( var n = 0; n < 24 && track.scrollWidth < want; n++ ) {
				track.appendChild( track.firstChild.cloneNode( true ) );
			}

			// Half a track has to be whole copies, or the loop lands mid-word.
			if ( track.children.length % 2 ) {
				track.appendChild( track.firstChild.cloneNode( true ) );
			}
		} );
	}

	/**
	 * Is the questionnaire being filled in on a phone? The drawing then shows
	 * the phone's own page — a shrunken desktop would be a lie about what
	 * their customers will see.
	 */
	function onPhone() {
		return window.matchMedia && window.matchMedia( '(max-width: 900px)' ).matches;
	}

	/* ------------------------------------------------------------ screens */

	function progressBar( index ) {
		var here  = screens[ index ];
		var wrap  = el( 'div', { 'class': 'oc-onb-prog' } );
		var chips = el( 'div', { 'class': 'oc-onb-steps' } );
		var seen  = [];

		// The journey reads as the pages of the shop, not as a count of
		// screens: the step you are on, and where you are inside it.
		asked.forEach( function ( s ) {
			if ( seen.indexOf( s.step ) === -1 ) { seen.push( s.step ); }
		} );

		var high = reach();

		seen.forEach( function ( step ) {
			var at    = step === here.step;
			var first = -1;

			asked.forEach( function ( s, i ) {
				if ( s.step === step && first < 0 ) { first = i; }
			} );

			// A step you have already been inside is finished: it carries the
			// tick and its name reopens it, wherever you are standing now. A
			// step you have never reached is a label and nothing more.
			var done = ! at && screens.indexOf( asked[ first ] ) <= high;
			var chip = el( at || done ? 'button' : 'span', {
				type: at || done ? 'button' : null,
				'class': 'oc-onb-steps__i' + ( at ? ' is-on' : ( done ? ' is-done' : ' is-later' ) )
			} );

			if ( done ) {
				chip.appendChild( el( 'span', { 'class': 'oc-onb-steps__v', 'aria-hidden': 'true', text: '✓' } ) );
			}

			chip.appendChild( el( 'span', { text: step.title } ) );

			if ( done ) {
				chip.addEventListener( 'click', function () { go( screens.indexOf( asked[ first ] ) ); } );
			}

			chips.appendChild( chip );
		} );

		var mine  = asked.filter( function ( s ) { return s.step === here.step; } );
		var n     = mine.indexOf( here ) + 1;

		wrap.appendChild( chips );
		wrap.appendChild( el( 'div', { 'class': 'oc-onb-prog__t', text: mine.length > 1 ? fmt( I.screen_of, n, mine.length ) : here.step.title } ) );

		var bar  = el( 'div', { 'class': 'oc-onb-prog__bar' } );
		var all  = asked.length;
		var done = asked.indexOf( here ) + 1;

		bar.appendChild( el( 'i', { style: 'inline-size:' + Math.round( done / all * 100 ) + '%' } ) );
		wrap.appendChild( bar );

		return wrap;
	}

	function renderWelcome() {
		var started = Object.keys( values ).length > 0;
		root.innerHTML = '';
		root.appendChild( el( 'div', { 'class': 'oc-onb__card oc-onb__card--hello' }, [
			el( 'h1', { text: I.welcome_title } ),
			el( 'p', { text: I.welcome_text } ),
			el( 'button', { type: 'button', 'class': 'oc-onb-btn', text: started ? I['continue'] : I.start, onclick: function () {
				var idx = 0;
				if ( started && C.step ) { screens.forEach( function ( s, i ) { if ( s.id === C.step ) { idx = i; } } ); }
				go( idx );
			} } )
		] ) );
	}

	/**
	 * The screen between the two halves: what is behind us, what is ahead.
	 *
	 * @param {number} index Where it sits.
	 */
	function renderGate( index ) {
		var sc   = screens[ index ];
		var card = el( 'div', { 'class': 'oc-onb__card oc-onb__card--hello oc-onb__card--gate' } );

		root.innerHTML = '';

		card.appendChild( el( 'div', { 'class': 'oc-onb-done__tick', 'aria-hidden': 'true', text: '✓' } ) );
		card.appendChild( el( 'h1', { text: sc.title } ) );
		card.appendChild( el( 'p', { text: sc.intro } ) );

		// A quiet way to look back over what was just answered. The review
		// remembers where it was opened from, so Back returns here.
		if ( sc.link ) {
			card.appendChild( el( 'p', { 'class': 'oc-onb-gate__look' }, [
				el( 'button', { type: 'button', 'class': 'oc-onb-link', text: sc.link, onclick: function () { go( screens.length, index ); } } )
			] ) );
		}

		if ( sc.ahead ) {
			card.appendChild( el( 'p', { 'class': 'oc-onb-gate__ahead', text: sc.ahead } ) );
		}

		card.appendChild( el( 'div', { 'class': 'oc-onb-nav oc-onb-nav--one' }, [
			el( 'button', { type: 'button', 'class': 'oc-onb-btn oc-onb-btn--big', text: sc.next, onclick: function () { go( index + 1 ); } } ),
			el( 'button', { type: 'button', 'class': 'oc-onb-link', text: I.back, onclick: function () { go( index - 1 ); } } )
		] ) );

		root.appendChild( card );
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function renderScreen( index ) {
		var sc = screens[ index ];

		if ( sc.gate ) { renderGate( index ); return; }

		root.innerHTML = '';
		var card = el( 'div', { 'class': 'oc-onb__card' + ( sc.preview ? ' oc-onb__card--wide' : '' ) } );
		card.appendChild( progressBar( index ) );
		card.appendChild( el( 'h1', { text: sc.title } ) );
		if ( sc.intro ) { card.appendChild( el( 'p', { 'class': 'oc-onb__intro', text: sc.intro } ) ); }

		// The half of the explanation that is only true on a phone, where
		// the list and the drawing take turns instead of standing together.
		if ( sc.intro_m ) { card.appendChild( el( 'p', { 'class': 'oc-onb__intro oc-onb__intro--m', text: sc.intro_m } ) ); }

		var lastGroup = null;
		var qcol      = card;

		// On a screen that carries a drawing, the questions take one column
		// and the drawing the other, and it stays in view while you answer.
		if ( sc.preview ) {
			qcol = el( 'div', { 'class': 'oc-onb-split__q' } );

			var pane  = el( 'div', { 'class': 'oc-onb-split__p' }, [
				el( 'p', { 'class': 'oc-onb-side__t', text: onPhone() ? I.sketch_m : I.sketch } ),
				el( 'div', { 'class': 'oc-onb-side', 'data-preview': sc.preview } )
			] );
			var split = el( 'div', { 'class': 'oc-onb-split is-q' }, [ qcol, pane ] );

			// On a phone the two do not fit side by side, so one strip at the
			// top switches between them and stays in reach.
			var tabs = el( 'div', { 'class': 'oc-onb-tabs' } );
			var tq   = el( 'button', { type: 'button', 'class': 'oc-onb-tabs__b is-on', text: I.tab_fields } );
			var tp   = el( 'button', { type: 'button', 'class': 'oc-onb-tabs__b', text: onPhone() ? I.tab_sketch_m : I.tab_sketch_d } );

			tq.addEventListener( 'click', function () {
				split.classList.add( 'is-q' );
				tq.classList.add( 'is-on' );
				tp.classList.remove( 'is-on' );
			} );

			tp.addEventListener( 'click', function () {
				split.classList.remove( 'is-q' );
				tp.classList.add( 'is-on' );
				tq.classList.remove( 'is-on' );
			} );

			tabs.appendChild( tq );
			tabs.appendChild( tp );

			card.appendChild( tabs );
			card.appendChild( split );
		}

		var holder = qcol;

		sc.fields.forEach( function ( id ) {
			var f = F[ id ];
			if ( ! f ) { return; }

			var group = f.group || lastGroup || '';

			if ( group !== lastGroup ) {
				lastGroup = group;
				holder    = el( 'section', { 'class': 'oc-onb-grp' + ( group ? '' : ' oc-onb-grp--bare' ) } );

				if ( group ) {
					holder.appendChild( el( 'h2', { 'class': 'oc-onb-grp__h', text: group } ) );
				}

				qcol.appendChild( holder );
			}

			var box = renderField( id );
			if ( box ) { holder.appendChild( box ); }
		} );

		var nav = el( 'div', { 'class': 'oc-onb-nav' } );
		nav.appendChild( el( 'button', { type: 'button', 'class': 'oc-onb-btn oc-onb-btn--ghost', text: I.back, onclick: function () { go( index - 1 ); } } ) );
		nav.appendChild( el( 'button', { type: 'button', 'class': 'oc-onb-btn', text: I.next, onclick: function () {
			var miss = missingIn( sc.fields );
			if ( miss.length ) { mark( miss ); return; }
			go( index + 1 );
		} } ) );
		qcol.appendChild( nav );
		root.appendChild( card );
		refreshVisibility();
		paintPreview();
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function mark( ids ) {
		root.querySelectorAll( '.oc-onb-f.is-missing' ).forEach( function ( b ) { b.classList.remove( 'is-missing' ); } );
		root.querySelectorAll( '.oc-onb-row.is-missing' ).forEach( function ( b ) { b.classList.remove( 'is-missing' ); } );
		var first = null;
		ids.forEach( function ( id ) {
			var box = root.querySelector( '[data-field="' + id + '"]' );
			if ( ! box ) { return; }

			box.classList.add( 'is-missing' );

			// A whole arrangement in red says nothing: the rows that are
			// waiting are the ones to point at.
			if ( F[ id ] && 'layout' === F[ id ].type ) {
				layoutGaps( F[ id ], val( id ) ).forEach( function ( i ) {
					var line = box.querySelector( '.oc-onb-row[data-row="' + i + '"]' );

					if ( line ) { line.classList.add( 'is-missing' ); if ( ! first ) { first = line; } }
				} );
			}

			if ( ! first ) { first = box; }
		} );
		note( I.fill_required, 'err' );
		if ( first ) { first.scrollIntoView( { behavior: 'smooth', block: 'center' } ); }
	}

	function showValue( f, v ) {
		if ( isEmpty( v ) ) { return ''; }
		switch ( f.type ) {
			case 'choice':
			case 'pick':
			case 'gallery':  return f.options[ v ] || String( v );
			case 'checks':   return v.map( function ( k ) { return f.options[ k ] || k; } ).join( ', ' );
			case 'consent':  return v ? I.yes : I.no;
			case 'file':     return v.name || '';
			case 'hours':    return v.map( function ( r ) { return r.days.map( function ( d ) { return C.days[ d ] ? C.days[ d ].label : d; } ).join( ' ' ) + ' ' + r.from + '–' + r.to; } ).join( ' · ' );
			case 'repeater': return v.map( function ( r ) { return r.name || ''; } ).filter( Boolean ).join( ' · ' ) || fmt( I.rows, v.length );
			case 'branch_access': return Object.keys( v ).map( function ( k ) { return ( v[ k ] || [] ).length; } ).join( ' · ' );
			case 'layout':   return v.filter( function ( r ) { return r.on; } ).length + ' ' + I.rows_kept;
			default:         return String( v );
		}
	}

	function renderSummary() {
		root.innerHTML = '';
		var card = el( 'div', { 'class': 'oc-onb__card' } );
		card.appendChild( el( 'h1', { text: I.summary_title } ) );
		card.appendChild( el( 'p', { 'class': 'oc-onb__intro', text: I.summary_text } ) );

		var allMissing = [];
		screens.forEach( function ( sc, si ) {
			if ( sc.gate ) { return; }
			var sec = el( 'section', { 'class': 'oc-onb-sum' } );
			var miss = missingIn( sc.fields );
			allMissing = allMissing.concat( miss );
			sec.appendChild( el( 'h2', {}, [
				el( 'span', { text: sc.title } ),
				el( 'button', { type: 'button', 'class': 'oc-onb-link', text: I.edit, onclick: function () { go( si ); } } )
			] ) );
			var dl = el( 'dl' );
			sc.fields.forEach( function ( id ) {
				var f = F[ id ];
				if ( ! f || f.type === 'info' || ! shown( id ) ) { return; }
				var v = showValue( f, val( id ) );
				var isMiss = miss.indexOf( id ) !== -1;
				dl.appendChild( el( 'dt', { text: f.label } ) );
				dl.appendChild( el( 'dd', { 'class': isMiss ? 'is-missing' : ( v === '' ? 'is-empty' : '' ), text: v === '' ? I.not_answered : v } ) );
			} );
			sec.appendChild( dl );
			card.appendChild( sec );
		} );

		var nav = el( 'div', { 'class': 'oc-onb-nav' } );
		nav.appendChild( el( 'button', { type: 'button', 'class': 'oc-onb-btn oc-onb-btn--ghost', text: I.back, onclick: function () { go( cameFrom ); } } ) );
		var submit = el( 'button', { type: 'button', 'class': 'oc-onb-btn oc-onb-btn--big', text: I.submit, disabled: allMissing.length ? 'disabled' : null } );
		submit.addEventListener( 'click', function () {
			submit.disabled = true;
			submit.textContent = I.submitting;
			var go_ = function () {
				return api( '/submit', { body: '{}' } ).then( function ( r ) {
					if ( r.ok ) { at = screens.length + 1; renderDone(); return; }
					if ( r.status === 422 && r.data.missing ) {
						var id = r.data.missing[0];
						screens.forEach( function ( s, i ) { if ( s.fields.indexOf( id ) !== -1 ) { go( i ); setTimeout( function () { mark( r.data.missing ); }, 300 ); } } );
						return;
					}
					throw new Error( 'submit' );
				} );
			};
			( flush() || Promise.resolve() ).then( go_ ).catch( function () {
				submit.disabled = false;
				submit.textContent = I.submit;
				note( I.submit_failed, 'err' );
			} );
		} );
		nav.appendChild( submit );
		card.appendChild( nav );
		root.appendChild( card );
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function renderDone() {
		root.innerHTML = '';
		var next = el( 'ul', { 'class': 'oc-onb-next' } );
		( I.done_next_items || [] ).forEach( function ( t ) { next.appendChild( el( 'li', { text: t } ) ); } );
		root.appendChild( el( 'div', { 'class': 'oc-onb__card oc-onb__card--hello' }, [
			el( 'div', { 'class': 'oc-onb-done__tick', 'aria-hidden': 'true', text: '✓' } ),
			el( 'h1', { text: I.done_title } ),
			el( 'p', { text: I.done_text } ),
			el( 'h2', { 'class': 'oc-onb-next__h', text: I.done_next_title } ),
			next,
			el( 'p', { 'class': 'oc-onb__small', text: I.done_again } )
		] ) );
		note( '', 'ok' );
	}

	function go( index, from ) {
		commitAll();
		waiting = [];
		maybeDiscover();
		if ( index < 0 ) { at = -1; renderWelcome(); return; }
		if ( index >= screens.length ) {
			at       = screens.length;
			cameFrom = from === undefined ? screens.length - 1 : from;
			renderSummary();
			saveStep();
			return;
		}
		at  = index;
		far = Math.max( far, index );
		renderScreen( index );
		saveStep();
	}

	/* ------------------------------------------------------------ start */

	root.removeAttribute( 'data-loading' );
	maybeDiscover();

	var asked_at = ( window.location.search.match( /[?&]at=([a-z0-9-]+)/i ) || [] )[1];
	var jump     = -1;

	if ( asked_at ) {
		screens.forEach( function ( s, i ) { if ( s.id === asked_at ) { jump = i; } } );
	}

	if ( jump >= 0 ) {
		go( jump );
	} else if ( C.status === 'applied' && C.step === 'summary' ) {
		go( screens.length );
	} else {
		renderWelcome();
	}

	window.addEventListener( 'beforeunload', function () {
		commitAll();

		if ( Object.keys( pending ).length ) {
			clearTimeout( saveTimer );
			var step = at >= 0 && at < screens.length ? screens[ at ].id : '';
			navigator.sendBeacon && navigator.sendBeacon( C.rest + '/draft?t=' + encodeURIComponent( C.token ), new Blob( [ JSON.stringify( { fields: pending, step: step } ) ], { type: 'application/json' } ) );
		}
	} );
}() );
