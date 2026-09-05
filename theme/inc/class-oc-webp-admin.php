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

			<nav class="ocmc__tabs">
				<button type="button" class="ocmc__tab is-on" data-ocwp-tab="set"><?php esc_html_e( 'Settings', 'oc-theme' ); ?></button>
				<button type="button" class="ocmc__tab" data-ocwp-tab="no"><?php esc_html_e( 'To convert', 'oc-theme' ); ?></button>
				<button type="button" class="ocmc__tab" data-ocwp-tab="yes"><?php esc_html_e( 'Already WebP', 'oc-theme' ); ?></button>
			</nav>

			<div data-ocwp-panel="set">
				<p class="ocmc__webp">
					<label>
						<input type="checkbox" id="ocmc-webp" <?php checked( (bool) get_option( Media_Clean::WEBP, false ) ); ?>>
						<b><?php esc_html_e( 'Convert pictures as they are uploaded', 'oc-theme' ); ?></b>
					</label>
					<span><?php esc_html_e( 'From now on every picture uploaded is written as WebP, at every size. The file you chose is kept beside it, untouched.', 'oc-theme' ); ?></span>
				</p>

				<p class="ocmc__webp">
					<label>
						<input type="checkbox" id="ocmc-drop" <?php checked( (bool) get_option( Media_Clean::DROP, false ) ); ?>>
						<b><?php esc_html_e( 'Clear the file it replaces', 'oc-theme' ); ?></b>
					</label>
					<span><?php esc_html_e( 'After a conversion, remove the picture it replaced and its old sizes — unless that name is written somewhere on the site, in which case it is always kept. Clearing is what gives up the way back.', 'oc-theme' ); ?></span>
				</p>

				<p class="submit">
					<button type="button" class="button button-primary" id="ocwp-save"><?php esc_html_e( 'Save', 'oc-theme' ); ?></button>
					<em id="ocwp-said" class="ocwp__said"></em>
				</p>
			</div>

			<div data-ocwp-panel="list" hidden>
				<p class="ocmc__note" data-ocwp-note="no">
					<?php esc_html_e( 'Pictures in the library that are still JPEG or PNG. Converting one rebuilds it and all its sizes as WebP and the site starts serving those. Nothing is deleted unless you asked for it in the settings, so a conversion can be undone.', 'oc-theme' ); ?>
				</p>
				<p class="ocmc__note" data-ocwp-note="yes" hidden>
					<?php esc_html_e( 'Pictures already being served as WebP. Where a conversion left the old file behind, it is offered for removal here.', 'oc-theme' ); ?>
				</p>

				<p class="ocmc__controls">
					<label>
						<?php esc_html_e( 'Heavier than', 'oc-theme' ); ?>
						<select id="ocwp-floor">
							<option value="0"><?php esc_html_e( 'Everything', 'oc-theme' ); ?></option>
							<option value="100">100 KB</option>
							<option value="200" selected>200 KB</option>
							<option value="500">500 KB</option>
							<option value="1024">1 MB</option>
						</select>
					</label>
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
		/* display:flex above beats the hidden attribute on its own, which is
		why the bar used to stand there before anything was ticked. */
		.ocmc__float[hidden] { display: none; }
		.ocmc__float b { font-size: 13px; font-weight: 600; padding-inline-start: 6px; }
		.ocmc__float .button { border-radius: 999px; padding: 2px 16px; border: 0; }
		.ocmc__float .button[hidden] { display: none; }
		.ocmc__float .button:not(.button-primary) { background: #fff; color: #2271b1; }
		.ocmc__float .button:not(.button-primary):hover { background: #f0f6fc; color: #135e96; }
		.ocmc__float .button:disabled { opacity: .45; }
		.ocwp__spare { color: #996800; font-size: 12px; }
		.ocwp__said { font-style: normal; color: #007017; font-size: 13px; margin-inline-start: 10px; }
		.ocwp__run { margin: 18px 0 24px; padding: 16px 18px; border: 1px solid #c3c4c7; border-inline-start: 4px solid #2271b1; background: #f6f7f7; border-radius: 4px; }
		.ocwp__run > b { display: block; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #50575e; }
		.ocwp__run p { margin: 8px 0 0; }
		.ocwp__runbar { position: relative; block-size: 18px; background: #dcdcde; border-radius: 9px; overflow: hidden; }
		.ocwp__runbar i { position: absolute; inset-block: 0; inset-inline-start: 0; inline-size: 0; background: #2271b1; transition: inline-size .4s ease; }
		.ocwp__runbar span { position: relative; display: block; text-align: center; font-size: 11px; line-height: 18px; color: #1d2327; }
		.ocwp__runsaid { font-weight: 600; color: #135e96; }
		.ocwp__load { display: flex; align-items: center; gap: 10px; color: #646970; font-size: 13px; margin: 20px 0; }
		.ocwp__spin { inline-size: 18px; block-size: 18px; border: 2px solid #dcdcde; border-block-start-color: #2271b1; border-radius: 50%; animation: ocwp-spin .8s linear infinite; }
		@keyframes ocwp-spin { to { transform: rotate(360deg); } }
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
			'rtitle'   => __( 'Convert all of these on the server', 'oc-theme' ),
			'rlede'    => __( 'Thousands of pictures is hours of work. Start it here and it carries on by itself — close the tab, come back whenever. Whatever is converted before you stop stays converted.', 'oc-theme' ),
			/* translators: %d: number of pictures the run will work through. */
			'rgo'      => __( 'Convert all %d in the background', 'oc-theme' ),
			'rstopbtn' => __( 'Stop', 'oc-theme' ),
			/* translators: 1: pictures done, 2: pictures in the run, 3: space saved. */
			'rgoing'   => __( 'Running — %1$d of %2$d converted, %3$s lighter so far. You can close this page.', 'oc-theme' ),
			/* translators: 1: pictures converted, 2: space saved. */
			'rdone'    => __( 'Finished. %1$d pictures converted, %2$s lighter.', 'oc-theme' ),
			/* translators: 1: pictures converted, 2: space saved. */
			'rstopped' => __( 'Stopped after %1$d pictures, %2$s lighter. The ones already done stay done.', 'oc-theme' ),
			/* translators: %d: number of pictures that could not be converted. */
			'rfail'    => __( '%d could not be converted and were passed over.', 'oc-theme' ),
			'rwait'    => __( 'Booked. The first batch starts within a minute.', 'oc-theme' ),
			'rask'     => __( 'Convert them in the background? It runs on the server for as long as it takes, and you can stop it at any time.', 'oc-theme' ),
			'rstopask' => __( 'Stop the run? Everything converted so far stays converted.', 'oc-theme' ),
			'nospare'  => __( 'nothing left over', 'oc-theme' ),
			'kept'     => __( 'kept', 'oc-theme' ),
			'settings' => __( 'Settings', 'oc-theme' ),
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
				seen  = {},
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
				var ticks = document.querySelectorAll( '.ocwp__cb:checked' );
				var listOpen = ! document.querySelector( '[data-ocwp-panel="list"]' ).hidden;

				// Only what is already WebP *and* still has an old file behind
				// it can have anything cleared, so the button follows that.
				var withOld = 0;
				ticks.forEach( function ( cb ) {
					if ( '1' === cb.getAttribute( 'data-ocwp-olds' ) ) { withOld++; }
				} );

				var convert = document.getElementById( 'ocwp-convert' );
				var drop = document.getElementById( 'ocwp-drop' );

				convert.hidden = 'no' !== have;
				drop.hidden = 'yes' !== have || 0 === withOld;

				pill.hidden = ! listOpen || 0 === ticks.length || ( convert.hidden && drop.hidden );
				document.getElementById( 'ocwp-float-n' ).textContent = sprintf( T.picked, ticks.length );
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
						+ '<td><span class="ocmc__pic"><input type="checkbox" class="ocwp__cb" value="' + it.id
						+ '" data-ocwp-olds="' + ( it.olds ? '1' : '0' ) + '">' + pic + '</span></td>'
						+ '<td><span class="ocmc__hname">' + ( it.link ? '<a href="' + esc( it.link ) + '">' + esc( it.name ) + '</a>' : esc( it.name ) ) + '</span>'
						+ '<br><span class="ocmc__hdim">' + esc( it.mime ) + '</span>' + spare + '</td>'
						+ '<td class="ocmc__hbytes" data-ocwp-size>' + esc( kb( it.bytes ) ) + '</td>'
						+ '<td>' + act + '<span class="ocmc__hmsg" data-ocwp-msg></span></td>'
						+ '</tr>';
				} ).join( '' );

				out.innerHTML = '<p class="ocmc__hsum">' + esc( sum ) + '</p>'
					+ runBox( d )
					+ '<p class="ocmc__hpick"><button type="button" class="button-link" data-ocwp-all="1">' + esc( T.pick )
					+ '</button><button type="button" class="button-link" data-ocwp-all="0">' + esc( T.clear ) + '</button></p>'
					+ '<table class="ocmc__htable"><thead><tr><th></th><th>' + esc( T.file ) + '</th><th>'
					+ esc( T.size ) + '</th><th>' + esc( T.act ) + '</th></tr></thead><tbody>' + rows + '</tbody></table>';
				floatBar();

				// The box was just rebuilt: if a run is already going, say so.
				if ( document.getElementById( 'ocwp-run' ) ) {
					if ( rLast ) { runDraw( rLast ); }
					runPoll();
				}
			}

			function look() {
				seen[ have ] = true;
				out.innerHTML = '<p class="ocwp__load"><span class="ocwp__spin" aria-hidden="true"></span>' + esc( T.looking ) + '</p>';
				floatBar();
				post( 'ocmc_formats', {
					have: have,
					url: document.getElementById( 'ocwp-url' ).value,
					floor: document.getElementById( 'ocwp-floor' ).value
				} ).then( function ( r ) {
					if ( r && r.success && ! r.data.why ) { draw( r.data ); }
					else { out.innerHTML = '<p class="ocmc__hsum">' + esc( ( r && r.data && r.data.why ) || T.failed ) + '</p>'; floatBar(); }
				} ).catch( function () {
					out.innerHTML = '<p class="ocmc__hsum">' + esc( T.failed ) + '</p>';
				} );
			}

			document.querySelectorAll( '[data-ocwp-tab]' ).forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					var want = tab.getAttribute( 'data-ocwp-tab' );

					document.querySelectorAll( '[data-ocwp-tab]' ).forEach( function ( t ) {
						t.classList.toggle( 'is-on', t === tab );
					} );

					var list = 'set' !== want;
					document.querySelector( '[data-ocwp-panel="set"]' ).hidden = list;
					document.querySelector( '[data-ocwp-panel="list"]' ).hidden = ! list;

					if ( ! list ) {
						floatBar();
						return;
					}

					var same = have === want;
					have = want;

					document.querySelectorAll( '[data-ocwp-note]' ).forEach( function ( n ) {
						n.hidden = n.getAttribute( 'data-ocwp-note' ) !== have;
					} );

					// Each list is looked up the first time it is opened, and
					// kept after that; the whole library takes a moment.
					if ( ! same || ! seen[ have ] ) { look(); }
				} );
			} );

			var save = document.getElementById( 'ocwp-save' );

			save.addEventListener( 'click', function () {
				var said = document.getElementById( 'ocwp-said' );
				said.textContent = '';
				save.disabled = true;

				Promise.all( [
					post( 'ocmc_webp', { on: document.getElementById( 'ocmc-webp' ).checked ? '1' : '0', key: 'webp' } ),
					post( 'ocmc_webp', { on: document.getElementById( 'ocmc-drop' ).checked ? '1' : '0', key: 'drop' } )
				] ).then( function ( rs ) {
					save.disabled = false;
					said.textContent = rs.every( function ( r ) { return r && r.success; } ) ? T.saved : T.failed;
				} ).catch( function () {
					save.disabled = false;
					said.textContent = T.failed;
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

			/* ---------- the background run ---------- */

			var rTimer = null;
			var rLast  = null;

			/*
			 * Built into the results, under the line the search prints. Only
			 * the to-convert list over the whole library can offer it: a run
			 * works on the library, not on one page.
			 */
			function runBox( d ) {
				if ( 'no' !== have || d.page || ! d.total ) { return ''; }

				return '<div class="ocwp__run" id="ocwp-run">'
					+ '<b>' + esc( T.rtitle ) + '</b>'
					+ '<p>' + esc( T.rlede ) + '</p>'
					+ '<p class="ocwp__runbar" hidden><i></i><span></span></p>'
					+ '<p class="ocwp__runsaid" id="ocwp-run-said"></p>'
					+ '<p><button type="button" class="button button-primary" id="ocwp-run-go">'
					+ esc( sprintf( T.rgo, d.total ) ) + '</button>'
					+ '<button type="button" class="button" id="ocwp-run-stop" hidden>' + esc( T.rstopbtn ) + '</button></p>'
					+ '</div>';
			}

			function runDraw( d ) {
				rLast = d;

				var box = document.getElementById( 'ocwp-run' );
				if ( ! box ) { return; }

				var bar  = box.querySelector( '.ocwp__runbar' );
				var said = box.querySelector( '#ocwp-run-said' );
				var go   = box.querySelector( '#ocwp-run-go' );
				var stop = box.querySelector( '#ocwp-run-stop' );

				if ( ! d || 'idle' === d.state ) {
					bar.hidden = true;
					said.textContent = '';
					go.hidden = false;
					stop.hidden = true;
					return;
				}

				var total = d.total || 0;
				var pct   = total ? Math.round( 100 * ( d.cursor || 0 ) / total ) : 0;
				var lite  = kb( d.saved || 0 );

				if ( 'running' === d.state ) {
					bar.hidden = false;
					bar.querySelector( 'i' ).style.inlineSize = Math.max( 0, Math.min( 100, pct ) ) + '%';
					bar.querySelector( 'span' ).textContent = pct + '%';
					said.textContent = sprintf( T.rgoing, d.done || 0, total, lite );
					go.hidden = true;
					stop.hidden = false;
				} else {
					bar.hidden = true;
					said.textContent = 'done' === d.state
						? sprintf( T.rdone, d.done || 0, lite )
						: sprintf( T.rstopped, d.done || 0, lite );
					go.hidden = false;
					stop.hidden = true;
				}

				if ( d.failed ) { said.textContent += ' ' + sprintf( T.rfail, d.failed ); }

				if ( 'running' === d.state ) {
					if ( ! rTimer ) { rTimer = window.setInterval( runPoll, 5000 ); }
				} else if ( rTimer ) {
					window.clearInterval( rTimer );
					rTimer = null;
				}
			}

			function runPoll() {
				post( 'ocmc_run_state', {} )
					.then( function ( r ) { if ( r && r.success ) { runDraw( r.data ); } } )
					.catch( function () {} );
			}

			document.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '#ocwp-run-go' ) ) {
					if ( ! window.confirm( T.rask ) ) { return; }
					var go = document.getElementById( 'ocwp-run-go' );
					go.disabled = true;
					document.getElementById( 'ocwp-run-said' ).textContent = T.rwait;
					post( 'ocmc_run_start', { floor: document.getElementById( 'ocwp-floor' ).value } )
						.then( function ( r ) {
							go.disabled = false;
							if ( r && r.success ) { runDraw( r.data ); }
							else { document.getElementById( 'ocwp-run-said' ).textContent = ( r && r.data && r.data.why ) || T.failed; }
						} )
						.catch( function () { go.disabled = false; } );
					return;
				}

				if ( e.target.closest( '#ocwp-run-stop' ) ) {
					if ( ! window.confirm( T.rstopask ) ) { return; }
					post( 'ocmc_run_stop', {} ).then( function ( r ) {
						if ( r && r.success ) { runDraw( r.data ); }
					} ).catch( function () {} );
				}
			} );

			[ [ 'ocwp-convert', false, T.ask ], [ 'ocwp-drop', true, T.askdrop ] ].forEach( function ( pair ) {
				document.getElementById( pair[ 0 ] ).addEventListener( 'click', function () {
					var ids = ticked();
					if ( ! ids.length ) { window.alert( T.none ); return; }
					if ( ! window.confirm( sprintf( pair[ 2 ], ids.length ) ) ) { return; }
					run( ids, pair[ 1 ] );
				} );
			} );

			floatBar();
		}() );
		</script>
		<?php
	}
}
