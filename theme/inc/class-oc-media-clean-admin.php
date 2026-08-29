<?php
/**
 * OC Media cleanup — the screen.
 *
 * A scan with a progress bar, then the findings in three groups with the
 * pictures shown, so nothing is ever deleted sight unseen. Every item can be
 * unticked, the deleting happens in small batches, and each file is checked
 * once more the moment before it goes.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The cleanup room.
 */
final class Media_Clean_Admin {

	/**
	 * Draw it.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$results = Media_Clean::results();
		$nonce   = wp_create_nonce( 'ocmc' );
		$logged  = Media_Clean::log_count();

		?>
		<div class="wrap ocmc">
			<h1><?php esc_html_e( 'Media cleanup', 'oc-theme' ); ?></h1>

			<p class="ocmc__lede">
				<?php esc_html_e( 'Finds pictures and videos nothing points at any more. A file is treated as in use if it hangs off a post, if its ID appears in any field that holds media, or if its name is written anywhere at all — content, settings or the customizer. Anything in the least doubtful is kept.', 'oc-theme' ); ?>
			</p>

			<?php self::styles(); ?>

			<p>
				<button type="button" class="button button-primary button-hero" id="ocmc-scan">
					<?php esc_html_e( 'Scan the media library', 'oc-theme' ); ?>
				</button>
				<?php if ( $logged > 0 ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocmc_csv' ), 'ocmc_csv' ) ); ?>">
						<?php
						printf(
							/* translators: %d: number of files. */
							esc_html( _n( 'Download the record (%d file)', 'Download the record (%d files)', $logged, 'oc-theme' ) ),
							(int) $logged
						);
						?>
					</a>
				<?php endif; ?>
			</p>

			<div class="ocmc__bar" id="ocmc-bar" hidden><i></i><span></span></div>

			<div id="ocmc-out">
				<?php
				if ( $results ) {
					self::results( $results );
				}
				?>
			</div>
		</div>
		<?php

		self::script( $nonce );
	}

	/**
	 * The findings.
	 *
	 * @param array<string,mixed> $r Results from the scanner.
	 */
	public static function results( array $r ): void {
		$groups = array(
			'orphan' => array(
				'title' => __( 'Attached to nothing', 'oc-theme' ),
				'note'  => __( 'No post, no field and no page mentions these. Safe to remove.', 'oc-theme' ),
			),
			'draft'  => array(
				'title' => __( 'Belonging to drafts', 'oc-theme' ),
				'note'  => __( 'Uploaded to a product or page that was never published. Removing them empties those drafts.', 'oc-theme' ),
			),
			'trash'  => array(
				'title' => __( 'Belonging to the bin', 'oc-theme' ),
				'note'  => __( 'Uploaded to something already in the bin. If you might restore it, keep these.', 'oc-theme' ),
			),
		);

		$free = 0;
		foreach ( array_keys( $groups ) as $key ) {
			$free += (int) $r[ $key ]['bytes'];
		}

		?>
		<div class="ocmc__stats">
			<div class="ocmc__stat ocmc__stat--safe">
				<b><?php echo esc_html( (string) (int) $r['used'] ); ?></b>
				<span><?php esc_html_e( 'in use', 'oc-theme' ); ?></span>
			</div>
			<div class="ocmc__stat">
				<b><?php echo esc_html( (string) count( $r['orphan']['items'] ) ); ?></b>
				<span><?php esc_html_e( 'attached to nothing', 'oc-theme' ); ?></span>
			</div>
			<div class="ocmc__stat">
				<b><?php echo esc_html( (string) count( $r['draft']['items'] ) ); ?></b>
				<span><?php esc_html_e( 'on drafts', 'oc-theme' ); ?></span>
			</div>
			<div class="ocmc__stat">
				<b><?php echo esc_html( (string) count( $r['trash']['items'] ) ); ?></b>
				<span><?php esc_html_e( 'on binned items', 'oc-theme' ); ?></span>
			</div>
			<div class="ocmc__stat ocmc__stat--free">
				<b><?php echo esc_html( size_format( $free, 1 ) ); ?></b>
				<span><?php esc_html_e( 'can be freed', 'oc-theme' ); ?></span>
			</div>
		</div>

		<?php if ( (int) $r['recent'] > 0 ) : ?>
			<p class="ocmc__held">
				<?php
				printf(
					/* translators: %d: number of files. */
					esc_html( _n( '%d file uploaded in the last two days is held back, in case it is still being worked on.', '%d files uploaded in the last two days are held back, in case they are still being worked on.', (int) $r['recent'], 'oc-theme' ) ),
					(int) $r['recent']
				);
				?>
			</p>
		<?php endif; ?>

		<?php
		$any = false;
		foreach ( $groups as $key => $group ) {
			$items = (array) $r[ $key ]['items'];
			if ( ! $items ) {
				continue;
			}
			$any = true;
			?>
			<div class="ocmc__group" data-ocmc-group="<?php echo esc_attr( $key ); ?>">
				<h2>
					<?php echo esc_html( $group['title'] ); ?>
					<em><?php echo esc_html( size_format( (int) $r[ $key ]['bytes'], 1 ) ); ?></em>
				</h2>
				<p class="ocmc__note"><?php echo esc_html( $group['note'] ); ?></p>

				<p class="ocmc__pick">
					<button type="button" class="button-link" data-ocmc-all="1"><?php esc_html_e( 'Select all', 'oc-theme' ); ?></button>
					<button type="button" class="button-link" data-ocmc-all="0"><?php esc_html_e( 'Select none', 'oc-theme' ); ?></button>
				</p>

				<ul class="ocmc__grid">
					<?php foreach ( $items as $item ) : ?>
						<li class="ocmc__item">
							<label>
								<input type="checkbox" class="ocmc__cb" value="<?php echo esc_attr( (string) $item['id'] ); ?>" <?php checked( 'orphan', $key ); ?> />
								<?php if ( $item['thumb'] ) : ?>
									<img src="<?php echo esc_url( (string) $item['thumb'] ); ?>" alt="" loading="lazy" />
								<?php else : ?>
									<span class="ocmc__nopic"><?php esc_html_e( 'no preview', 'oc-theme' ); ?></span>
								<?php endif; ?>
								<b><?php echo esc_html( (string) $item['name'] ); ?></b>
								<span><?php echo esc_html( size_format( (int) $item['bytes'], 1 ) ); ?></span>
							</label>
							<?php if ( $item['link'] ) : ?>
								<a href="<?php echo esc_url( (string) $item['link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'view', 'oc-theme' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}

		if ( ! $any ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Nothing to clean — every file in the library is spoken for.', 'oc-theme' ) . '</p></div>';
			return;
		}
		?>

		<div class="ocmc__actions">
			<button type="button" class="button button-primary" id="ocmc-del-orphan">
				<?php esc_html_e( 'Delete only what is attached to nothing', 'oc-theme' ); ?>
			</button>
			<button type="button" class="button" id="ocmc-del-all">
				<?php esc_html_e( 'Delete everything ticked', 'oc-theme' ); ?>
			</button>
			<span class="ocmc__warn"><?php esc_html_e( 'Deleting is permanent — the files leave the server.', 'oc-theme' ); ?></span>
		</div>
		<?php
	}

	/**
	 * Looks.
	 */
	private static function styles(): void {
		?>
		<style>
		.ocmc__lede { max-width: 70ch; color: #50575e; }
		.ocmc__stats { display: flex; flex-wrap: wrap; gap: 10px; margin: 18px 0; }
		.ocmc__stat { flex: 1 1 140px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px 16px; }
		/* "5.4 MB" is a number followed by a Latin unit, which right-to-left
		   text reorders into "MB 5.4". Isolating the run keeps it readable. */
		.ocmc__stat b,
		.ocmc__group h2 em,
		.ocmc__item span { direction: ltr; unicode-bidi: isolate; }
		.ocmc__stat b { display: block; font-size: 26px; line-height: 1.1; }
		.ocmc__stat span { color: #646970; font-size: 12px; }
		.ocmc__stat--safe b { color: #2271b1; }
		.ocmc__stat--free b { color: #007017; }
		.ocmc__held { color: #646970; font-style: italic; }
		.ocmc__group { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 6px 18px 18px; margin: 18px 0; }
		.ocmc__group h2 { display: flex; align-items: baseline; gap: 10px; }
		.ocmc__group h2 em { font-size: 13px; font-weight: 400; color: #646970; font-style: normal; }
		.ocmc__note { color: #646970; margin-top: -8px; }
		.ocmc__pick { display: flex; gap: 14px; }
		.ocmc__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; margin: 0; }
		.ocmc__item { margin: 0; position: relative; }
		.ocmc__item label { display: block; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 6px; padding: 8px; cursor: pointer; }
		.ocmc__item img { display: block; inline-size: 100%; block-size: 110px; object-fit: contain; background: #fff; }
		.ocmc__nopic { display: grid; place-items: center; block-size: 110px; background: #fff; color: #a7aaad; font-size: 12px; }
		.ocmc__item b { display: block; font-size: 11px; word-break: break-all; margin-block-start: 6px; font-weight: 500; }
		.ocmc__item span { font-size: 11px; color: #646970; }
		.ocmc__item > a { position: absolute; inset-block-start: 10px; inset-inline-end: 10px; background: #fff; border-radius: 4px; padding: 1px 6px; font-size: 11px; text-decoration: none; }
		.ocmc__cb { float: inline-start; margin-inline-end: 6px; }
		.ocmc__actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 18px 0 40px; }
		.ocmc__warn { color: #b32d2e; font-size: 12px; }
		.ocmc__bar { position: relative; block-size: 26px; background: #f0f0f1; border-radius: 13px; overflow: hidden; max-inline-size: 520px; margin: 14px 0; }
		.ocmc__bar i { position: absolute; inset-block: 0; inset-inline-start: 0; inline-size: 0; background: #2271b1; transition: inline-size .2s; }
		.ocmc__bar span { position: relative; display: grid; place-items: center; block-size: 100%; font-size: 12px; color: #1d2327; }
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
			'scanning' => __( 'Scanning…', 'oc-theme' ),
			'checking' => __( 'Checking file names — %1$d of %2$d', 'oc-theme' ),
			'deleting' => __( 'Deleting — %1$d of %2$d', 'oc-theme' ),
			'none'     => __( 'Nothing is ticked.', 'oc-theme' ),
			'confirm'  => __( 'Delete %d files for good? This cannot be undone.', 'oc-theme' ),
			'done'     => __( 'Done: %1$d deleted, %2$s freed. %3$d were kept because something still uses them.', 'oc-theme' ),
			'failed'   => __( 'Something went wrong. Please try again.', 'oc-theme' ),
		);
		?>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>,
				T     = <?php echo wp_json_encode( $strings ); ?>,
				bar   = document.getElementById( 'ocmc-bar' ),
				out   = document.getElementById( 'ocmc-out' );

			function post( action, data ) {
				var body = new FormData();
				body.append( 'action', action );
				body.append( '_ajax_nonce', nonce );
				Object.keys( data || {} ).forEach( function ( k ) {
					if ( Array.isArray( data[ k ] ) ) {
						data[ k ].forEach( function ( v ) { body.append( k + '[]', v ); } );
					} else {
						body.append( k, data[ k ] );
					}
				} );
				return fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } );
			}

			function progress( pct, text ) {
				bar.hidden = false;
				bar.querySelector( 'i' ).style.inlineSize = Math.max( 2, pct ) + '%';
				bar.querySelector( 'span' ).textContent = text;
			}

			function sprintf( s ) {
				var args = [].slice.call( arguments, 1 ), i = 0;
				return s.replace( /%(\d)\$[ds]|%[ds]/g, function ( m, n ) {
					return n ? args[ n - 1 ] : args[ i++ ];
				} );
			}

			var scan = document.getElementById( 'ocmc-scan' );
			if ( scan ) {
				scan.addEventListener( 'click', function () {
					scan.disabled = true;
					out.innerHTML = '';
					progress( 4, T.scanning );

					post( 'ocmc_start', {} ).then( function ( res ) {
						if ( ! res || ! res.success ) { throw 0; }
						var total = res.data.total;

						function step() {
							return post( 'ocmc_step', {} ).then( function ( r ) {
								if ( ! r || ! r.success ) { throw 0; }
								var pct = total ? Math.round( ( r.data.done / total ) * 100 ) : 100;
								progress( pct, sprintf( T.checking, r.data.done, total ) );
								return r.data.ready ? null : step();
							} );
						}

						return total ? step() : null;
					} ).then( function () {
						window.location = window.location.href.split( '#' )[ 0 ];
					} ).catch( function () {
						scan.disabled = false;
						progress( 100, T.failed );
					} );
				} );
			}

			document.addEventListener( 'click', function ( e ) {
				var pick = e.target.closest( '[data-ocmc-all]' );
				if ( ! pick ) { return; }
				var on = '1' === pick.getAttribute( 'data-ocmc-all' );
				pick.closest( '.ocmc__group' ).querySelectorAll( '.ocmc__cb' ).forEach( function ( cb ) {
					cb.checked = on;
				} );
			} );

			function gather( scope ) {
				var root = scope ? document.querySelector( '[data-ocmc-group="' + scope + '"]' ) : document;
				if ( ! root ) { return []; }
				return [].slice.call( root.querySelectorAll( '.ocmc__cb:checked' ) ).map( function ( cb ) {
					return cb.value;
				} );
			}

			function run( ids ) {
				if ( ! ids.length ) { window.alert( T.none ); return; }
				if ( ! window.confirm( sprintf( T.confirm, ids.length ) ) ) { return; }

				var total = ids.length, done = 0, deleted = 0, kept = 0, bytes = 0;

				function chunk() {
					var slice = ids.slice( done, done + 20 );
					if ( ! slice.length ) {
						window.alert( sprintf( T.done, deleted, ( bytes / 1048576 ).toFixed( 1 ) + ' MB', kept ) );
						window.location = window.location.href.split( '#' )[ 0 ];
						return;
					}
					return post( 'ocmc_delete', { ids: slice } ).then( function ( r ) {
						if ( ! r || ! r.success ) { throw 0; }
						deleted += r.data.deleted;
						kept    += r.data.kept;
						bytes   += r.data.bytes;
						done    += slice.length;
						progress( Math.round( ( done / total ) * 100 ), sprintf( T.deleting, done, total ) );
						return chunk();
					} );
				}

				progress( 2, sprintf( T.deleting, 0, total ) );
				chunk().catch( function () { progress( 100, T.failed ); } );
			}

			var one = document.getElementById( 'ocmc-del-orphan' );
			if ( one ) { one.addEventListener( 'click', function () { run( gather( 'orphan' ) ); } ); }

			var all = document.getElementById( 'ocmc-del-all' );
			if ( all ) { all.addEventListener( 'click', function () { run( gather( null ) ); } ); }
		}() );
		</script>
		<?php
	}
}
