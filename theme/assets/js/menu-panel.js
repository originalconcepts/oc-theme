/**
 * The panel builder.
 *
 * Everything on screen is drawn from the description of the block types the
 * server hands over, so a new type appears here without a line being changed.
 *
 * Text fields write straight into the model and never trigger a repaint —
 * repainting a field while somebody is typing in it takes their cursor away,
 * and that is the single fastest way to make an editor feel hostile.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'oc-mp-root' );

	if ( ! root ) {
		return;
	}

	var D = JSON.parse( root.dataset.ocMp );
	var T = D.i18n;

	var state = {
		blocks: Array.isArray( D.blocks ) ? D.blocks : [],
		open: null,
		dirty: false
	};

	var els = {};
	var previewTimer = null;
	var dragFrom = null;

	/* ---------- small helpers ---------- */

	function el( tag, attrs, kids ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else if ( key === 'html' ) {
				node.innerHTML = attrs[ key ];
			} else if ( key.indexOf( 'on' ) === 0 ) {
				node.addEventListener( key.slice( 2 ), attrs[ key ] );
			} else if ( attrs[ key ] !== null && attrs[ key ] !== undefined ) {
				node.setAttribute( key, attrs[ key ] );
			}
		} );

		( kids || [] ).forEach( function ( kid ) {
			if ( kid ) {
				node.appendChild( kid );
			}
		} );

		return node;
	}

	function blank( type ) {
		var block = { type: type, w: 'normal', dev: 'both' };
		var fields = D.types[ type ].fields;

		Object.keys( fields ).forEach( function ( key ) {
			block[ key ] = fields[ key ].type === 'rows' ? [] : ( fields[ key ].def || ( fields[ key ].type === 'image' ? 0 : '' ) );
		} );

		return block;
	}

	/* A card needs a name. The block's own heading is the truest one; failing
	 * that, the type it is. */
	function nameOf( block ) {
		return ( block.title || block.heading || '' ).trim() || D.types[ block.type ].label;
	}

	function summaryOf( block ) {
		var bits = [ D.widths[ block.w ].label ];

		if ( block.type === 'links' ) {
			bits.push( ( block.rows || [] ).length + '' );
		}

		if ( block.dev !== 'both' ) {
			bits.push( D.devices[ block.dev ] );
		}

		return bits.join( ' · ' );
	}

	function touch() {
		state.dirty = true;
		setStatus( '' );
		paintStrip();
		schedulePreview();
	}

	/* ---------- the strip of cards ---------- */

	function paintStrip() {
		var strip = els.strip;

		strip.innerHTML = '';

		if ( ! state.blocks.length ) {
			strip.appendChild( el( 'p', { 'class': 'oc-mp__empty', text: T.empty } ) );
		}

		state.blocks.forEach( function ( block, i ) {
			strip.appendChild( card( block, i ) );
		} );

		strip.appendChild( adder() );
	}

	function card( block, i ) {
		var node = el( 'div', {
			'class': 'oc-mpc' + ( state.open === i ? ' is-on' : '' ),
			draggable: 'true',
			ondragstart: function ( e ) {
				dragFrom = i;
				e.dataTransfer.effectAllowed = 'move';
			},
			ondragover: function ( e ) {
				e.preventDefault();
				node.classList.add( 'is-over' );
			},
			ondragleave: function () {
				node.classList.remove( 'is-over' );
			},
			ondrop: function ( e ) {
				e.preventDefault();
				node.classList.remove( 'is-over' );
				move( dragFrom, i );
			}
		}, [
			el( 'button', {
				type: 'button',
				'class': 'oc-mpc__face',
				onclick: function () {
					state.open = state.open === i ? null : i;
					paintStrip();
					paintSettings();
				}
			}, [
				el( 'span', { 'class': 'oc-mpc__icon', html: D.types[ block.type ].icon } ),
				el( 'b', { text: nameOf( block ) } ),
				el( 'small', { text: summaryOf( block ) } )
			] ),
			el( 'span', { 'class': 'oc-mpc__tools' }, [
				el( 'button', {
					type: 'button',
					'class': 'oc-mpc__mv',
					'aria-label': T.move,
					text: D.rtl ? '›' : '‹',
					onclick: function () { move( i, i - 1 ); }
				} ),
				el( 'button', {
					type: 'button',
					'class': 'oc-mpc__mv',
					'aria-label': T.move,
					text: D.rtl ? '‹' : '›',
					onclick: function () { move( i, i + 1 ); }
				} ),
				el( 'button', {
					type: 'button',
					'class': 'oc-mpc__x',
					'aria-label': T.remove,
					text: '×',
					onclick: function () {
						if ( ! window.confirm( T.confirm ) ) {
							return;
						}

						state.blocks.splice( i, 1 );
						state.open = null;
						paintSettings();
						touch();
					}
				} )
			] )
		] );

		return node;
	}

	function move( from, to ) {
		if ( from === null || to < 0 || to >= state.blocks.length || from === to ) {
			return;
		}

		var block = state.blocks.splice( from, 1 )[ 0 ];

		state.blocks.splice( to, 0, block );
		state.open = to;
		paintSettings();
		touch();
	}

	function adder() {
		var wrap = el( 'div', { 'class': 'oc-mp__add' } );
		var full = state.blocks.length >= D.max;

		var button = el( 'button', {
			type: 'button',
			'class': 'oc-mp__addbtn',
			text: '+ ' + T.add,
			disabled: full ? 'disabled' : null,
			title: full ? T.full : null,
			onclick: function () {
				wrap.classList.toggle( 'is-open' );
			}
		} );

		var list = el( 'div', { 'class': 'oc-mp__types' } );

		Object.keys( D.types ).forEach( function ( key ) {
			list.appendChild( el( 'button', {
				type: 'button',
				onclick: function () {
					state.blocks.push( blank( key ) );
					state.open = state.blocks.length - 1;
					wrap.classList.remove( 'is-open' );
					paintSettings();
					touch();
				}
			}, [
				el( 'span', { 'class': 'oc-mpc__icon', html: D.types[ key ].icon } ),
				el( 'b', { text: D.types[ key ].label } ),
				el( 'small', { text: D.types[ key ].blurb } )
			] ) );
		} );

		wrap.appendChild( button );
		wrap.appendChild( list );

		return wrap;
	}

	/* ---------- one block's settings ---------- */

	function paintSettings() {
		var box = els.settings;

		box.innerHTML = '';

		if ( state.open === null || ! state.blocks[ state.open ] ) {
			box.hidden = true;
			return;
		}

		box.hidden = false;

		var block = state.blocks[ state.open ];
		var fields = D.types[ block.type ].fields;

		box.appendChild( el( 'div', { 'class': 'oc-mp__srow' }, [
			select( T.width, D.widths, block.w, function ( v ) {
				block.w = v;
				touch();
			}, true ),
			select( T.device, D.devices, block.dev, function ( v ) {
				block.dev = v;
				touch();
			} )
		] ) );

		Object.keys( fields ).forEach( function ( key ) {
			box.appendChild( field( block, key, fields[ key ] ) );
		} );
	}

	function select( label, choices, value, onchange, useLabelKey ) {
		var node = el( 'select', {
			onchange: function () {
				onchange( node.value );
			}
		} );

		Object.keys( choices ).forEach( function ( key ) {
			var text = useLabelKey ? choices[ key ].label : choices[ key ];

			node.appendChild( el( 'option', {
				value: key,
				selected: key === value ? 'selected' : null,
				text: text
			} ) );
		} );

		return el( 'label', { 'class': 'oc-mp__f' }, [
			el( 'span', { text: label } ),
			node
		] );
	}

	function field( block, key, def ) {
		if ( def.type === 'select' ) {
			return select( def.label, def.choices, block[ key ] || def.def, function ( v ) {
				block[ key ] = v;
				touch();
			} );
		}

		if ( def.type === 'image' ) {
			return imageField( block, key, def );
		}

		if ( def.type === 'rows' ) {
			return rowsField( block, key, def );
		}

		var input = el( 'input', {
			type: 'text',
			value: block[ key ] || '',
			oninput: function () {
				block[ key ] = input.value;
				touch();
			}
		} );

		var kids = [ el( 'span', { text: def.label } ), input ];

		if ( def.type === 'url' ) {
			kids.push( suggest( input, function ( hit ) {
				block[ key ] = hit.url;
				input.value = hit.url;
				touch();
			} ) );
		}

		if ( def.hint ) {
			kids.push( el( 'small', { text: def.hint } ) );
		}

		return el( 'label', { 'class': 'oc-mp__f oc-mp__f--wide' }, kids );
	}

	function imageField( block, key, def ) {
		var prev = el( 'span', { 'class': 'oc-mp__prev' } );
		var frame;

		function draw() {
			prev.innerHTML = '';

			if ( block[ key ] && D.thumbs && D.thumbs[ block[ key ] ] ) {
				prev.appendChild( el( 'img', { src: D.thumbs[ block[ key ] ] } ) );
			} else if ( block[ key ] ) {
				prev.appendChild( el( 'span', { 'class': 'oc-mp__prevnum', text: '#' + block[ key ] } ) );
			}
		}

		D.thumbs = D.thumbs || {};
		draw();

		return el( 'label', { 'class': 'oc-mp__f oc-mp__f--wide' }, [
			el( 'span', { text: def.label } ),
			el( 'span', { 'class': 'oc-mp__img' }, [
				prev,
				el( 'button', {
					type: 'button',
					'class': 'button',
					text: T.choose,
					onclick: function () {
						if ( ! frame ) {
							frame = wp.media( { library: { type: 'image' }, multiple: false } );
							frame.on( 'select', function () {
								var img = frame.state().get( 'selection' ).first().toJSON();

								block[ key ] = img.id;
								D.thumbs[ img.id ] = img.sizes && img.sizes.thumbnail ? img.sizes.thumbnail.url : img.url;
								draw();
								touch();
							} );
						}

						frame.open();
					}
				} ),
				el( 'button', {
					type: 'button',
					'class': 'button-link',
					text: T.clear,
					onclick: function () {
						block[ key ] = 0;
						draw();
						touch();
					}
				} )
			] )
		] );
	}

	function rowsField( block, key, def ) {
		var list = el( 'div', { 'class': 'oc-mp__rows' } );

		block[ key ] = block[ key ] || [];

		function draw() {
			list.innerHTML = '';

			block[ key ].forEach( function ( row, i ) {
				var text = el( 'input', {
					type: 'text',
					placeholder: T.linkText,
					value: row.t || '',
					oninput: function () {
						row.t = text.value;
						touch();
					}
				} );

				var url = el( 'input', {
					type: 'text',
					placeholder: T.linkUrl,
					value: row.u || '',
					oninput: function () {
						row.u = url.value;
						touch();
					}
				} );

				var tag = el( 'input', {
					type: 'text',
					'class': 'oc-mp__tag',
					placeholder: T.linkTag,
					maxlength: '14',
					value: row.b || '',
					oninput: function () {
						row.b = tag.value;
						touch();
					}
				} );

				list.appendChild( el( 'div', { 'class': 'oc-mp__row' }, [
					el( 'span', { 'class': 'oc-mp__grip', text: '⋮⋮' } ),
					text,
					el( 'span', { 'class': 'oc-mp__urlwrap' }, [
						url,
						suggest( url, function ( hit ) {
							row.u = hit.url;
							url.value = hit.url;

							if ( ! row.t ) {
								row.t = hit.label;
								text.value = hit.label;
							}

							touch();
						} )
					] ),
					tag,
					el( 'button', {
						type: 'button',
						'class': 'oc-mpc__x',
						'aria-label': T.remove,
						text: '×',
						onclick: function () {
							block[ key ].splice( i, 1 );
							draw();
							touch();
						}
					} )
				] ) );
			} );

			list.appendChild( el( 'button', {
				type: 'button',
				'class': 'button oc-mp__addrow',
				text: '+ ' + T.addLink,
				onclick: function () {
					block[ key ].push( { t: '', u: '', b: '' } );
					draw();
					touch();
				}
			} ) );
		}

		draw();

		return el( 'div', { 'class': 'oc-mp__f oc-mp__f--wide' }, [
			el( 'span', { text: def.label } ),
			list
		] );
	}

	/* ---------- picking an address without typing one ---------- */

	function suggest( input, onpick ) {
		var box = el( 'div', { 'class': 'oc-mp__sug' } );
		var timer = null;

		function close() {
			box.innerHTML = '';
			box.classList.remove( 'is-open' );
		}

		input.addEventListener( 'input', function () {
			clearTimeout( timer );

			var term = input.value.trim();

			/* Somebody pasting a URL is not searching for one. */
			if ( term.length < 2 || term.indexOf( 'http' ) === 0 || term.indexOf( '/' ) === 0 ) {
				close();
				return;
			}

			timer = setTimeout( function () {
				var body = new FormData();

				body.append( 'action', 'oc_menu_link_search' );
				body.append( 'nonce', D.nonce );
				body.append( 's', term );

				fetch( D.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( r ) {
						if ( ! r.success || ! r.data.hits.length ) {
							close();
							return;
						}

						box.innerHTML = '';

						r.data.hits.forEach( function ( hit ) {
							box.appendChild( el( 'button', {
								type: 'button',
								onclick: function () {
									onpick( hit );
									close();
								}
							}, [
								el( 'b', { text: hit.label } ),
								el( 'small', { text: hit.kind } )
							] ) );
						} );

						box.classList.add( 'is-open' );
					} )
					.catch( close );
			}, 240 );
		} );

		/* Typing a category name and walking away leaves the name sitting in
		 * an address field, where it is not an address — the row then renders
		 * as plain text and the link silently is not one. Say so, right where
		 * it happened. */
		input.addEventListener( 'blur', function () {
			setTimeout( close, 180 );

			var value = input.value.trim();
			var looksLikeOne = value === '' ||
				value.indexOf( 'http' ) === 0 ||
				value.indexOf( '/' ) === 0 ||
				value.indexOf( '#' ) === 0 ||
				value.indexOf( 'mailto:' ) === 0 ||
				value.indexOf( 'tel:' ) === 0;

			input.classList.toggle( 'is-bad', ! looksLikeOne );
			input.title = looksLikeOne ? '' : T.notAnAddress;
		} );

		input.addEventListener( 'focus', function () {
			input.classList.remove( 'is-bad' );
		} );

		return box;
	}

	/* ---------- saving, and seeing ---------- */

	function setStatus( text, kind ) {
		els.status.textContent = text;
		els.status.className = 'oc-mp__status' + ( kind ? ' is-' + kind : '' );
	}

	function save() {
		setStatus( T.saving );

		var body = new FormData();

		body.append( 'action', 'oc_menu_panel_save' );
		body.append( 'nonce', D.nonce );
		body.append( 'item', D.item );
		body.append( 'blocks', JSON.stringify( state.blocks ) );

		fetch( D.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				if ( ! r.success ) {
					throw new Error();
				}

				state.dirty = false;
				setStatus( T.saved, 'ok' );
			} )
			.catch( function () {
				setStatus( T.failed, 'bad' );
			} );
	}

	function schedulePreview() {
		clearTimeout( previewTimer );
		previewTimer = setTimeout( preview, 400 );
	}

	function preview() {
		var body = new FormData();

		body.append( 'action', 'oc_menu_panel_preview' );
		body.append( 'nonce', D.nonce );
		body.append( 'blocks', JSON.stringify( state.blocks ) );

		fetch( D.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				if ( ! r.success ) {
					return;
				}

				paintPreview( r.data.html );
			} )
			.catch( function () {} );
	}

	/* The panel is drawn by the site's own stylesheet, in an iframe, so what
	 * is on this screen is the thing itself rather than an impression of it.
	 * A handful of overrides undo the parts that only make sense on hover. */
	function paintPreview( html ) {
		var frame = els.frame;

		frame.srcdoc = '<!doctype html><html dir="' + ( D.rtl ? 'rtl' : 'ltr' ) + '"><head>' +
			'<meta charset="utf-8">' +
			'<link rel="stylesheet" href="' + D.css + '">' +
			'<style>' +
				'html,body{margin:0;padding:0;background:#fff}' +
				'.oc-mega{position:static!important;opacity:1!important;visibility:visible!important;transform:none!important;box-shadow:none!important;max-inline-size:none!important;border-inline:0!important}' +
				'.oc-mega .oc-mb{opacity:1!important;transform:none!important;animation:none!important}' +
			'</style></head><body>' + ( html || '' ) + '</body></html>';

		frame.onload = function () {
			try {
				var doc = frame.contentDocument;

				frame.style.height = Math.max( 80, doc.body.scrollHeight ) + 'px';
			} catch ( e ) {}
		};
	}

	/* ---------- build the screen once ---------- */

	els.strip = el( 'div', { 'class': 'oc-mp__strip' } );
	els.settings = el( 'div', { 'class': 'oc-mp__settings', hidden: 'hidden' } );
	els.status = el( 'span', { 'class': 'oc-mp__status' } );
	els.frame = el( 'iframe', { 'class': 'oc-mp__frame', title: T.preview } );

	root.appendChild( els.strip );
	root.appendChild( els.settings );
	root.appendChild( el( 'p', { 'class': 'oc-mp__bar' }, [
		el( 'button', {
			type: 'button',
			'class': 'button button-primary',
			text: T.save,
			onclick: save
		} ),
		els.status
	] ) );
	root.appendChild( el( 'h2', { 'class': 'oc-mp__ph', text: T.preview } ) );
	root.appendChild( els.frame );

	paintStrip();
	paintSettings();
	preview();

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( state.dirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );
}() );
