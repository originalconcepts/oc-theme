/**
 * The panel editor, on the Menus screen.
 *
 * It opens over that screen rather than replacing it: the columns of a panel
 * are the item's own sub-items, sitting right there underneath, so arranging
 * pictures around them belongs on the same screen and not on another one.
 *
 * Everything on show is drawn from the description of the block types the
 * server hands over, so a new type appears here without a line being changed.
 *
 * Text fields write straight into the model and never trigger a repaint —
 * repainting a field while somebody is typing in it takes their cursor away,
 * and that is the single fastest way to make an editor feel hostile.
 */
/* The dialog is printed by the Menus screen's own footer, which lands after
 * this file in the document. Trusting that order once cost an evening; the
 * document telling us it is ready costs nothing. */
( function ( boot ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( function () {
	'use strict';

	var modal = document.getElementById( 'oc-mp-modal' );
	var root = document.getElementById( 'oc-mp-root' );

	if ( ! modal || ! root ) {
		return;
	}

	var D = JSON.parse( modal.dataset.ocMp );
	var T = D.i18n;

	var state = {
		item: 0,
		button: null,
		blocks: [],
		open: null,
		dirty: false
	};

	var CHEV_L = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M10.5 2.6 5.1 8l5.4 5.4 1-1L7.1 8l4.4-4.4z"/></svg>';
	var CHEV_R = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M5.5 2.6 10.9 8l-5.4 5.4-1-1L8.9 8 4.5 3.6z"/></svg>';

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
		var block = { type: type, w: 'normal', dev: 'both', push: 0 };
		var fields = D.types[ type ].fields;

		Object.keys( fields ).forEach( function ( key ) {
			var kind = fields[ key ].type;
			block[ key ] = ( kind === 'rows' || kind === 'groups' ) ? [] : ( fields[ key ].def || ( kind === 'image' ? 0 : '' ) );
		} );

		return block;
	}

	/* A card needs a name. The block's own heading is the truest one; failing
	 * that, the type it is. */
	function nameOf( block ) {
		var own = ( block.title || block.heading || '' ).trim();

		if ( ! own && block.type === 'menu' && ( block.groups || [] ).length ) {
			var first = catName( block.groups[ 0 ].c );
			own = first ? first.replace( /^(— )+/, '' ) : '';
		}

		return own || D.types[ block.type ].label;
	}

	function catName( id ) {
		var hit = ( D.cats || [] ).filter( function ( c ) { return c.id === Number( id ); } )[ 0 ];
		return hit ? hit.label : '';
	}

	function summaryOf( block ) {
		var bits = [ D.widths[ block.w ].label ];

		if ( block.type === 'links' ) {
			bits.push( ( block.rows || [] ).length + '' );
		}

		if ( block.type === 'menu' ) {
			bits.push( ( block.groups || [] ).length + '' );
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
				/* Drawn, not typed. The chevron characters are bidi-mirrored,
				 * so in Hebrew the browser flips them and both buttons point
				 * the opposite way from what they do. An SVG points where it
				 * is drawn to point. Earlier in the row means further along
				 * the reading direction, which in Hebrew is to the right. */
				el( 'button', {
					type: 'button',
					'class': 'oc-mpc__mv',
					'aria-label': T.moveBack,
					html: D.rtl ? CHEV_R : CHEV_L,
					onclick: function () { move( i, i - 1 ); }
				} ),
				el( 'button', {
					type: 'button',
					'class': 'oc-mpc__mv',
					'aria-label': T.moveOn,
					html: D.rtl ? CHEV_L : CHEV_R,
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

		var push = el( 'input', {
			type: 'checkbox',
			checked: block.push ? 'checked' : null,
			onchange: function () {
				block.push = push.checked ? 1 : 0;
				touch();
			}
		} );

		/* The content first, the dials last. Every drop-down — width, where
		 * the words sit, the corners, all of them — gathers into one row at
		 * the bottom, right above Save, so tuning the block is one place to
		 * look instead of a column to scroll. */
		Object.keys( fields ).forEach( function ( key ) {
			if ( fields[ key ].type !== 'select' ) {
				box.appendChild( field( block, key, fields[ key ] ) );
			}
		} );

		var dials = el( 'div', { 'class': 'oc-mp__srow oc-mp__srow--end' } );

		dials.appendChild( select( T.width, D.widths, block.w, function ( v ) {
			block.w = v;
			touch();
		}, true ) );

		Object.keys( fields ).forEach( function ( key ) {
			if ( fields[ key ].type === 'select' ) {
				dials.appendChild( field( block, key, fields[ key ] ) );
			}
		} );

		dials.appendChild( select( T.device, D.devices, block.dev, function ( v ) {
			block.dev = v;
			touch();
		} ) );

		dials.appendChild( el( 'label', { 'class': 'oc-mp__check' }, [ push, el( 'span', { text: T.push } ) ] ) );

		box.appendChild( dials );
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

		if ( def.type === 'range' ) {
			var current = block[ key ] === undefined ? ( def.def || 50 ) : block[ key ];
			var shown = el( 'span', { 'class': 'oc-mp__rangeval', text: current + '%' } );
			var slider = el( 'input', {
				type: 'range',
				min: '0',
				max: '100',
				value: current,
				oninput: function () {
					block[ key ] = Number( slider.value );
					shown.textContent = slider.value + '%';
					touch();
				}
			} );

			return el( 'label', { 'class': 'oc-mp__f oc-mp__f--wide' }, [
				el( 'span', { text: def.label } ),
				el( 'span', { 'class': 'oc-mp__range' }, [ slider, shown ] ),
				def.hint ? el( 'small', { text: def.hint } ) : null
			] );
		}

		if ( def.type === 'rows' ) {
			return rowsField( block, key, def );
		}

		if ( def.type === 'groups' ) {
			return groupsField( block, key, def );
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

	function groupsField( block, key, def ) {
		var list = el( 'div', { 'class': 'oc-mp__rows' } );

		block[ key ] = block[ key ] || [];

		function draw() {
			list.innerHTML = '';

			block[ key ].forEach( function ( group, i ) {
				var choose = el( 'select', {
					onchange: function () {
						group.c = Number( choose.value );
						draw();
						touch();
					}
				}, [ el( 'option', { value: '0', text: '— ' + T.pickCat + ' —' } ) ] );

				( D.cats || [] ).forEach( function ( cat ) {
					choose.appendChild( el( 'option', {
						value: cat.id,
						selected: Number( group.c ) === cat.id ? 'selected' : null,
						text: cat.label
					} ) );
				} );

				var sub = el( 'input', {
					type: 'checkbox',
					checked: group.sub ? 'checked' : null,
					onchange: function () {
						group.sub = sub.checked ? 1 : 0;
						touch();
					}
				} );

				list.appendChild( el( 'div', { 'class': 'oc-mp__group' }, [
					choose,
					el( 'label', { 'class': 'oc-mp__check' }, [ sub, el( 'span', { text: T.showSub } ) ] ),
					el( 'button', {
						type: 'button',
						'class': 'oc-mpc__x',
						'aria-label': T.remove,
						text: '\u00d7',
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
				text: '+ ' + T.addCat,
				onclick: function () {
					block[ key ].push( { c: 0, sub: 0 } );
					draw();
					touch();
				}
			} ) );
		}

		draw();

		return el( 'div', { 'class': 'oc-mp__f oc-mp__f--wide' }, [
			el( 'span', { text: def.label } ),
			list,
			def.hint ? el( 'small', { text: def.hint } ) : null
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
		body.append( 'item', state.item );
		body.append( 'blocks', JSON.stringify( state.blocks ) );

		fetch( D.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				if ( ! r.success ) {
					throw new Error();
				}

				state.dirty = false;
				setStatus( T.saved, 'ok' );

				/* The screen behind is now out of date about this item. The
				 * button lives in the item's handle, so the state line is
				 * found from the item, not from beside the button. */
				if ( state.button ) {
					state.button.dataset.ocBlocks = JSON.stringify( r.data.blocks );
					state.button.dataset.ocThumbs = JSON.stringify( r.data.thumbs );

					var item = state.button.closest( 'li.menu-item' );
					var line = item && item.querySelector( '.oc-mi__state' );

					if ( line ) {
						line.textContent = r.data.state;
					}
				}
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
		body.append( 'item', state.item );
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
			'<style>:root{' + ( D.tokens || '' ) + '}</style>' +
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

	/* ---------- opening, closing ---------- */

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

	function open( button ) {
		state.item = Number( button.dataset.ocPanel );
		state.button = button;
		state.blocks = JSON.parse( button.dataset.ocBlocks || '[]' );
		state.open = state.blocks.length ? 0 : null;
		state.dirty = false;

		D.thumbs = JSON.parse( button.dataset.ocThumbs || '{}' );

		document.getElementById( 'oc-mp-modal-title' ).textContent = button.dataset.ocName || '';
		modal.hidden = false;
		document.body.classList.add( 'oc-mp-locked' );

		setStatus( '' );
		paintStrip();
		paintSettings();
		preview();
	}

	function close() {
		if ( state.dirty && ! window.confirm( T.leave ) ) {
			return;
		}

		modal.hidden = true;
		document.body.classList.remove( 'oc-mp-locked' );

		if ( state.button ) {
			state.button.focus();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.oc-mi__edit' );

		if ( button ) {
			event.preventDefault();
			open( button );
			return;
		}

		if ( event.target.closest( '[data-oc-mp-close]' ) ) {
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' && ! modal.hidden ) {
			close();
		}
	} );

	/* The way in rides the item's title bar, appearing on hover next to the
	 * link's kind — one glance and one click, without opening the item. It is
	 * printed inside the item's body (which still works with no JS) and
	 * seated up here; new items arrive over ajax, so keep looking. */
	function seat() {
		document.querySelectorAll( '.oc-mi__panel .oc-mi__edit' ).forEach( function ( button ) {
			var item = button.closest( 'li.menu-item' );
			var controls = item && item.querySelector( '.menu-item-handle .item-controls' );

			if ( controls ) {
				controls.insertBefore( button, controls.firstChild );
			}
		} );
	}

	seat();

	if ( window.jQuery ) {
		window.jQuery( document ).ajaxComplete( function () {
			setTimeout( seat, 60 );
		} );
	}
} ) );
