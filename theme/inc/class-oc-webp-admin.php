<?php
/**
 * The WebP screen: turn the pictures already in the library into WebP, and
 * decide what happens to the files they replace.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Media → Convert to WebP.
 */
final class Webp_Admin {

	/**
	 * The screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$nonce = wp_create_nonce( 'ocmc' );
		$able  = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		?>
		<div class="wrap ocmc ocwp">
			<h1><?php esc_html_e( 'Convert to WebP', 'oc-theme' ); ?></h1>

			<p class="ocmc__lede">
				<?php esc_html_e( 'WebP is the same picture at about a quarter of the weight. Every browser in use reads it.', 'oc-theme' ); ?>
			</p>

			<?php self::styles(); ?>

			<?php if ( ! $able ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'This server cannot write WebP, so nothing here will work. Ask the host to enable it.', 'oc-theme' ); ?></p></div>
			<?php endif; ?>

			<p class="ocmc__webp">
				<label>
					<input type="checkbox" id="ocmc-webp" <?php checked( (bool) get_option( Media_Clean::WEBP, false ) ); ?>>
					<b><?php esc_html_e( 'Convert pictures as they are uploaded', 'oc-theme' ); ?></b>
				</label>
				<span><?php esc_html_e( 'From now on every picture uploaded is written as WebP, at every size. The file you chose is kept beside it, untouched.', 'oc-theme' ); ?></span>
				<em id="ocmc-webp-said"></em>
			</p>

			<p class="ocmc__webp">
				<label>
					<input type="checkbox" id="ocmc-drop" <?php checked( (bool) get_option( Media_Clean::DROP, false ) ); ?>>
					<b><?php esc_html_e( 'Clear the file it replaces', 'oc-theme' ); ?></b>
				</label>
				<span><?php esc_html_e( 'After a conversion, remove the picture it replaced and its old sizes — unless that name is written somewhere on the site, in which case it is always kept.', 'oc-theme' ); ?></span>
				<em id="ocmc-drop-said"></em>
			</p>

			<nav class="ocmc__tabs">
				<button type="button" class="ocmc__tab is-on" data-ocwp-tab="no"><?php esc_html_e( 'To convert', 'oc-theme' ); ?></button>
				<button type="button" class="ocmc__tab" data-ocwp-tab="yes"><?php esc_html_e( 'Already WebP', 'oc-theme' ); ?></button>
			</nav>

			<p class="ocmc__note" data-ocwp-note="no">
				<?php esc_html_e( 'Pictures in the library that are still JPEG or PNG. Converting one rebuilds it and all its sizes as WebP and the site starts serving those. Nothing is deleted unless you ask above, so a conversion can always be undone.', 'oc-theme' ); ?>
			</p>
			<p class="ocmc__note" data-ocwp-note="yes" hidden>
				<?php esc_html_e( 'Pictures already being served as WebP. Where a conversion left the old file behind, it is offered for removal here.', 'oc-theme' ); ?>
			</p>

			<p class="ocmc__controls">
				<label class="ocmc__url">
					<?php esc_html_e( 'On one page (optional)', 'oc-theme' ); ?>
					<input type="url" id="ocwp-url" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" class="regular-text ltr">
				</label>
				<button type="button" class="button button-primary" id="ocwp-go"><?php esc_html_e( 'Look again', 'oc-theme' ); ?></button>
			</p>
			<p class="ocmc__hint"><?php esc_html_e( 'Leave the address empty for the whole library. Fill it in and only the pictures that page uses are listed.', 'oc-theme' ); ?></p>

			<div class="ocmc__bar" id="ocmc-bar" hidden><i></i><span></span></div>

			<div id="ocwp-out"></div>
		</div>

		<div class="ocmc__float" id="ocwp-float" hidden>
			<b id="ocwp-float-n"></b>
			<button type="button" class="button button-primary" id="ocwp-convert"><?php esc_html_e( 'Convert the ticked ones', 'oc-theme' ); ?></button>
			<button type="button" class="button" id="ocwp-drop"><?php esc_html_e( 'Clear what they replaced', 'oc-theme' ); ?></button>
		</div>
		<?php

		self::script( $nonce );
	}

	/**
	 * Looks — the cleanup screen's, plus what only this one needs.
	 */
	private static function styles(): void {
		?>
		<style>
		.ocwp .ocmc__tabs { display: flex; gap: 4px; margin: 18px 0 14px; border-block-end: 1px solid #dcdcde; }
		.ocwp .ocmc__tab { appearance: none; border: 1px solid transparent; border-block-end: 0; background: none; padding: 10px 18px; font: inherit; font-size: 14px; cursor: pointer; color: #646970; border-radius: 6px 6px 0 0; margin-block-end: -1px; }
		.ocwp .ocmc__tab.is-on { background: #fff; border-color: #dcdcde; color: #1d2327; font-weight: 600; }
		.ocmc__lede { max-inline-size: 760px; }
		.ocmc__note { color: #646970; max-inline-size: 760px; }
		.ocmc__hint { margin: -8px 0 18px; font-size: 12px; color: #646970; }
		.ocmc__controls { display: flex; align-items: end; gap: 14px; flex-wrap: wrap; margin: 14px 0 6px; }
		.ocmc__controls label { display: grid; gap: 4px; font-size: 12px; color: #646970; }
		.ocmc__url { flex: 1 1 340px; }
		.ocmc__url input { inline-size: 100%; }
		.ocmc__webp { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 12px 16px; margin: 0 0 12px; }
		.ocmc__webp span { font-size: 12px; color: #646970; flex: 1 1 320px; }
		.ocmc__webp em { font-style: normal; font-size: 12px; color: #007017; }
		.ocmc__hsum { margin: 14px 0 10px; font-size: 13px; }
		.ocmc__hpick { margin: 0 0 10px; display: flex; gap: 14px; align-items: center; }
		.ocmc__htable { inline-size: 100%; border-collapse: collapse; font-size: 13px; }
		.ocmc__htable th { text-align: start; font-size: 12px; color: #646970; font-weight: 600; padding: 6px 10px; border-block-end: 1px solid #dcdcde; }
		.ocmc__htable td { padding: 8px 10px; border-block-end: 1px solid #f0f0f1; vertical-align: middle; }
		.ocmc__htable td:first-child { inline-size: 1%; white-space: nowrap; }
		.ocmc__pic { display: flex; align-items: center; gap: 10px; }
		.ocmc__htable img { inline-size: 56px; block-size: 56px; object-fit: cover; border-radius: 4px; background: #f0f0f1; display: block; }
		.ocmc__hname { word-break: break-all; font-weight: 500; }
		.ocmc__hdim { color: #646970; font-size: 12px; }
		.ocmc__hbytes { white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 600; }
		.ocmc__hmsg { display: block; font-size: 11px; color: #646970; margin-block-start: 4px; max-inline-size: 260px; }
		.ocmc__bar { position: relative; block-size: 26px; background: #f0f0f1; border-radius: 13px; overflow: hidden; max-inline-size: 520px; margin: 14px 0; }
		.ocmc__bar i { position: absolute; inset-block: 0; inset-inline-start: 0; inline-size: 0; background: #2271b1; transition: inline-size .2s; }
		.ocmc__bar span { position: relative; display: grid; place-items: center; block-size: 100%; font-size: 12px; color: #1d2327; }
		.ocmc__float {
			position: fixed; inset-block-end: 24px; left: 50%; transform: translateX(-50%);
			display: flex; align-items: center; gap: 12px;
			background: #1d2327; color: #fff; padding: 10px 14px; border-radius: 999px;
			box-shadow: 0 8px 28px rgba(0,0,0,.28); z-index: 9999;
		}
		.ocmc__float b { font-size: 13px; font-weight: 600; padding-inline-start: 6px; }
		.ocmc__float .button { border-radius: 999px; padding: 2px 16px; }
		.ocwp__spare { color: #996800; font-size: 12px; }
		</style>
		<?php
	}

	/**
	 * The wiring.
	 *
	 * @param string $nonce Ajax nonce.
	 */
	private static function script( string $nonce ): void {
		$strings = array(
			'looking'  => __( 'Looking…', 'oc-theme' ),
			'failed'   => __( 'Something went wrong. Please try again.', 'oc-theme' ),
			'saved'    => __( 'Saved.', 'oc-theme' ),
			'none'     => __( 'Nothing is ticked.', 'oc-theme' ),
			'pick'     => __( 'Select all', 'oc-theme' ),
			'clear'    => __( 'Select none', 'oc-theme' ),
			/* translators: %d: number of pictures ticked. */
			'picked'   => __( '%d ticked', 'oc-theme' ),
			'file'     => __( 'File', 'oc-theme' ),
			'size'     => __( 'Size', 'oc-theme' ),
			'act'      => __( 'Action', 'oc-theme' ),
			'convert'  => __( 'Convert', 'oc-theme' ),
			'drop'     => __( 'Clear the old file', 'oc-theme' ),
			/* translators: 1: number of pictures, 2: their total size. */
			'sumno'    => __( '%1$d pictures are not WebP yet, %2$s between them.', 'oc-theme' ),
			/* translators: 1: number of pictures, 2: their total size. */
			'sumyes'   => __( '%1$d pictures are already WebP, %2$s between them.', 'oc-theme' ),
			/* translators: %d: number shown. */
			'showing'  => __( 'Showing the first %d.', 'oc-theme' ),
			'empty'    => __( 'Nothing here.', 'oc-theme' ),
			'working'  => __( 'Working…', 'oc-theme' ),
			/* translators: 1: size before, 2: size after. */
			'done'     => __( '%1$s → %2$s', 'oc-theme' ),
			/* translators: 1: files done, 2: files to do. */
			'prog'     => __( 'Converting — %1$d of %2$d', 'oc-theme' ),
			/* translators: %s: disk space, e.g. "5.4 MB". */
			'sumsaved' => __( 'Done. %s lighter.', 'oc-theme' ),
			/* translators: %d: number of pictures. */
			'ask'      => __( 'Convert %d pictures to WebP? The site starts serving the new files; the old ones stay unless you asked to clear them.', 'oc-theme' ),
			/* translators: %d: number of pictures. */
			'askdrop'  => __( 'Remove the files these %d replaced? Any name still written somewhere on the site is kept.', 'oc-theme' ),
			/* translators: %s: disk space. */
			'spare'    => __( '%s left over', 'oc-theme' ),
			'nospare'  => __( 'nothing left over', 'oc-theme' ),
			'kept'     => __( 'kept', 'oc-theme' ),
			'undo'     => __( 'Go back', 'oc-theme' ),
			'undone'   => __( 'Back to the old format', 'oc-theme' ),
		);
		?>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>,
				T     = <?php echo wp_json_encode( $strings ); ?>,
				bar   = document.getElementById( 'ocmc-bar' ),
				out   = document.getElementById( 'ocwp-out' ),
				have  = 'no';

			function post( action, data ) {
				var body = new FormData();
				body.append( 'action', action );
				body.append( '_ajax_nonce', nonce );
				Object.keys( data || {} ).forEach( function ( k ) { body.append( k, data[ k ] ); } );
				return fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } );
			}

			function sprintf( s ) {
				var args = [].slice.call( arguments, 1 ), i = 0;
				return s.replace( /%(\d)\$[ds]|%[ds]/g, function ( m, n ) {
					return n ? args[ n - 1 ] : args[ i++ ];
				} );
			}

			function esc( v ) {
				var d = document.createElement( 'div' );
				d.textContent = null === v || undefined === v ? '' : String( v );
				return d.innerHTML;
			}

			function kb( n ) {
				if ( n >= 1048576 ) { return ( n / 1048576 ).toFixed( 1 ) + ' MB'; }
				return Math.round( n / 1024 ) + ' KB';
			}

			function progress( pct, text ) {
				bar.hidden = false;
				bar.querySelector( 'i' ).style.inlineSize = pct + '%';
				bar.querySelector( 'span' ).textContent = text;
			}

			function floatBar() {
				var pill = document.getElementById( 'ocwp-float' );
				var n = document.querySelectorAll( '.ocwp__cb:checked' ).length;
				pill.hidden = 0 === n;
				document.getElementById( 'ocwp-float-n' ).textContent = sprintf( T.picked, n );
				document.getElementById( 'ocwp-convert' ).hidden = 'no' !== have;
				document.getElementById( 'ocwp-drop' ).hidden = 'yes' !== have;
			}

			function ticked() {
				return [].slice.call( document.querySelectorAll( '.ocwp__cb:checked' ) ).map( function ( cb ) {
					return cb.value;
				} );
			}

			function draw( d ) {
				if ( ! d.items.length ) {
					out.innerHTML = '<p class="ocmc__hsum">' + esc( T.empty ) + '</p>';
					floatBar();
					return;
				}

				var sum = sprintf( 'yes' === have ? T.sumyes : T.sumno, d.total, kb( d.bytes ) );
				if ( d.shown < d.total ) { sum += ' ' + sprintf( T.showing, d.shown ); }

				var rows = d.items.map( function ( it ) {
					var pic = it.thumb ? '<img src="' + esc( it.thumb ) + '" alt="" loading="lazy">' : '<img alt="">';
					var act = 'no' === have
						? '<button type="button" class="button" data-ocwp-one="' + it.id + '">' + esc( T.convert ) + '</button>'
						: ( it.olds
							? '<button type="button" class="button" data-ocwp-drop="' + it.id + '">' + esc( T.drop ) + '</button>'
								+ ' <button type="button" class="button-link" data-ocwp-undo="' + it.id + '">' + esc( T.undo ) + '</button>'
							: '' );
					var spare = 'yes' === have
						? '<br><span class="ocwp__spare">' + esc( it.olds ? sprintf( T.spare, kb( it.spare ) ) : T.nospare ) + '</span>'
						: '';
					return '<tr data-ocwp-row="' + it.id + '">'
						+ '<td><span class="ocmc__pic"><input type="checkbox" class="ocwp__cb" value="' + it.id + '">' + pic + '</span></td>'
						+ '<td><span class="ocmc__hname">' + ( it.link ? '<a href="' + esc( it.link ) + '">' + esc( it.name ) + '</a>' : esc( it.name ) ) + '</span>'
						+ '<br><span class="ocmc__hdim">' + esc( it.mime ) + '</span>' + spare + '</td>'
						+ '<td class="ocmc__hbytes" data-ocwp-size>' + esc( kb( it.bytes ) ) + '</td>'
						+ '<td>' + act + '<span class="ocmc__hmsg" data-ocwp-msg></span></td>'
						+ '</tr>';
				} ).join( '' );

				out.innerHTML = '<p class="ocmc__hsum">' + esc( sum ) + '</p>'
					+ '<p class="ocmc__hpick"><button type="button" class="button-link" data-ocwp-all="1">' + esc( T.pick )
					+ '</button><button type="button" class="button-link" data-ocwp-all="0">' + esc( T.clear ) + '</button></p>'
					+ '<table class="ocmc__htable"><thead><tr><th></th><th>' + esc( T.file ) + '</th><th>'
					+ esc( T.size ) + '</th><th>' + esc( T.act ) + '</th></tr></thead><tbody>' + rows + '</tbody></table>';
				floatBar();
			}

			function look() {
				out.innerHTML = '<p class="ocmc__hsum">' + esc( T.looking ) + '</p>';
				post( 'ocmc_formats', { have: have, url: document.getElementById( 'ocwp-url' ).value } ).then( function ( r ) {
					if ( r && r.success && ! r.data.why ) { draw( r.data ); }
					else { out.innerHTML = '<p class="ocmc__hsum">' + esc( ( r && r.data && r.data.why ) || T.failed ) + '</p>'; floatBar(); }
				} ).catch( function () {
					out.innerHTML = '<p class="ocmc__hsum">' + esc( T.failed ) + '</p>';
				} );
			}

			document.querySelectorAll( '[data-ocwp-tab]' ).forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					have = tab.getAttribute( 'data-ocwp-tab' );
					document.querySelectorAll( '[data-ocwp-tab]' ).forEach( function ( t ) {
						t.classList.toggle( 'is-on', t === tab );
					} );
					document.querySelectorAll( '[data-ocwp-note]' ).forEach( function ( n ) {
						n.hidden = n.getAttribute( 'data-ocwp-note' ) !== have;
					} );
					look();
				} );
			} );

			[ [ 'ocmc-webp', 'webp' ], [ 'ocmc-drop', 'drop' ] ].forEach( function ( pair ) {
				var box = document.getElementById( pair[ 0 ] );
				if ( ! box ) { return; }
				box.addEventListener( 'change', function () {
					var said = document.getElementById( pair[ 0 ] + '-said' );
					said.textContent = '';
					post( 'ocmc_webp', { on: box.checked ? '1' : '0', key: pair[ 1 ] } ).then( function ( r ) {
						said.textContent = r && r.success ? T.saved : T.failed;
					} ).catch( function () { said.textContent = T.failed; } );
				} );
			} );

			document.getElementById( 'ocwp-go' ).addEventListener( 'click', look );

			document.addEventListener( 'change', function ( e ) {
				if ( e.target.classList && e.target.classList.contains( 'ocwp__cb' ) ) { floatBar(); }
			} );

			document.addEventListener( 'click', function ( e ) {
				var all = e.target.closest( '[data-ocwp-all]' );
				if ( all ) {
					var on = '1' === all.getAttribute( 'data-ocwp-all' );
					document.querySelectorAll( '.ocwp__cb' ).forEach( function ( cb ) { cb.checked = on; } );
					floatBar();
					return;
				}

				var one = e.target.closest( '[data-ocwp-one], [data-ocwp-drop], [data-ocwp-undo]' );
				if ( ! one ) { return; }

				if ( one.hasAttribute( 'data-ocwp-undo' ) ) {
					run( [ one.getAttribute( 'data-ocwp-undo' ) ], 'undo' );
					return;
				}

				var drop = one.hasAttribute( 'data-ocwp-drop' );
				run( [ one.getAttribute( drop ? 'data-ocwp-drop' : 'data-ocwp-one' ) ], drop );
			} );

			function run( ids, drop ) {
				var at = 0, saved = 0;

				function next() {
					if ( at >= ids.length ) {
						progress( 100, sprintf( T.sumsaved, kb( saved ) ) );
						floatBar();
						return;
					}

					progress( Math.round( 100 * at / ids.length ), sprintf( T.prog, at, ids.length ) );

					var id = ids[ at++ ];
					var call = 'undo' === drop ? 'ocmc_undo' : ( drop ? 'ocmc_drop' : 'ocmc_convert' );
					post( call, { id: id } ).then( function ( r ) {
						var d = r && r.data ? r.data : {};
						var row = document.querySelector( '[data-ocwp-row="' + id + '"]' );
						if ( row ) {
							var msg = row.querySelector( '[data-ocwp-msg]' );
							var cell = row.querySelector( '[data-ocwp-size]' );
							if ( d.ok && 'undo' === drop ) {
								msg.textContent = T.undone;
								if ( cell ) { cell.textContent = kb( d.after ); }
							} else if ( d.ok && drop ) {
								saved += d.freed || 0;
								msg.textContent = sprintf( T.sumsaved, kb( d.freed || 0 ) );
							} else if ( d.ok ) {
								saved += ( d.before - d.after ) + ( d.freed || 0 );
								msg.textContent = sprintf( T.done, kb( d.before ), kb( d.after ) )
									+ ( d.dropped ? '' : ' · ' + T.kept );
								if ( cell ) { cell.textContent = kb( d.after ); }
							} else {
								msg.textContent = d.why || T.failed;
							}
						}
						next();
					} ).catch( next );
				}

				next();
			}

			[ [ 'ocwp-convert', false, T.ask ], [ 'ocwp-drop', true, T.askdrop ] ].forEach( function ( pair ) {
				document.getElementById( pair[ 0 ] ).addEventListener( 'click', function () {
					var ids = ticked();
					if ( ! ids.length ) { window.alert( T.none ); return; }
					if ( ! window.confirm( sprintf( pair[ 2 ], ids.length ) ) ) { return; }
					run( ids, pair[ 1 ] );
				} );
			} );

			look();
		}() );
		</script>
		<?php
	}
}
