<?php
/**
 * The feeds screen: make one, watch it build, copy its address.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Feeds;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Product feeds.
 */
final class Admin {

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'wp_ajax_ocfeed_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_ocfeed_drop', array( $this, 'ajax_drop' ) );
		add_action( 'wp_ajax_ocfeed_run', array( $this, 'ajax_run' ) );
		add_action( 'wp_ajax_ocfeed_state', array( $this, 'ajax_state' ) );
	}

	/**
	 * The menu entry.
	 */
	public function menu(): void {
		add_options_page(
			__( 'Product feeds', 'oc-theme' ),
			__( 'Product feeds', 'oc-theme' ),
			'manage_options',
			'oc-feeds',
			array( $this, 'render' )
		);
	}

	/**
	 * Who may act.
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'oc-theme' ) ), 403 );
		}

		check_ajax_referer( 'ocfeed' );
	}

	/**
	 * Create or update one feed.
	 */
	public function ajax_save(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$raw = isset( $_POST['feed'] ) ? (array) wp_unslash( $_POST['feed'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every member is sanitised by Feeds::clean().
		// phpcs:enable

		$fresh = '' === $key;
		$key   = $fresh ? Feeds::fresh_key() : $key;
		$was   = Feeds::get( $key );
		$feed  = array_merge( Feeds::defaults(), is_array( $was ) ? $was : array(), $raw );

		if ( '' === trim( (string) $feed['name'] ) ) {
			$feed['name'] = self::target_name( (string) $feed['target'] );
		}

		Feeds::put( $key, $feed );

		// A new feed is built at once: nobody wants to be told to come back
		// in an hour to find out whether the thing they just made works.
		Build::start( $key );
		Build::step( $key );

		wp_send_json_success( array( 'key' => $key ) );
	}

	/**
	 * Remove a feed.
	 */
	public function ajax_drop(): void {
		$this->guard();

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().

		Feeds::drop( $key );

		wp_send_json_success();
	}

	/**
	 * Build one batch, or begin a rebuild.
	 */
	public function ajax_run(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().
		$key   = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$again = isset( $_POST['again'] ) && '1' === sanitize_key( wp_unslash( $_POST['again'] ) );
		// phpcs:enable

		if ( $again ) {
			Build::start( $key );
		}

		Build::step( $key );

		wp_send_json_success( self::state( $key ) );
	}

	/**
	 * Where a feed has got to.
	 */
	public function ajax_state(): void {
		$this->guard();

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().

		wp_send_json_success( self::state( $key ) );
	}

	/**
	 * One feed's progress, for the screen.
	 *
	 * @param string $key Feed key.
	 * @return array<string,mixed>
	 */
	private static function state( string $key ): array {
		$feed = Feeds::get( $key );

		if ( null === $feed ) {
			return array( 'gone' => true );
		}

		$total = get_transient( 'oc_feed_ids_' . $key );

		return array(
			'state' => (string) $feed['state'],
			'items' => (int) $feed['items'],
			'done'  => (int) $feed['cursor'],
			'total' => is_array( $total ) ? count( $total ) : (int) $feed['cursor'],
			'made'  => (int) $feed['made'],
			'when'  => $feed['made'] > 0 ? (string) wp_date( 'd/m/Y H:i', (int) $feed['made'] ) : '',
			'url'   => Feeds::url( $key ),
			'error' => (string) $feed['error'],
		);
	}

	/**
	 * What a feed for this network is called when nobody names it.
	 *
	 * @param string $target Network.
	 */
	private static function target_name( string $target ): string {
		$names = array(
			'meta'   => __( 'Meta catalogue', 'oc-theme' ),
			'google' => __( 'Google Merchant Center', 'oc-theme' ),
			'zap'    => __( 'Zap', 'oc-theme' ),
		);

		return (string) ( $names[ $target ] ?? __( 'Product feed', 'oc-theme' ) );
	}

	/**
	 * The screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$feeds = Feeds::all();
		$cats  = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 300,
			)
		);
		?>
		<div class="wrap ocfeed">
			<h1><?php esc_html_e( 'Product feeds', 'oc-theme' ); ?></h1>
			<p class="ocfeed__lede">
				<?php esc_html_e( 'A feed is the file a network reads to know what you sell. Choose who it is for, give it a name, and everything else already carries the setting that suits a shop. Each feed has one address that never changes and always answers with the newest build.', 'oc-theme' ); ?>
			</p>

			<?php self::styles(); ?>

			<p>
				<button type="button" class="button button-primary button-hero" id="ocfeed-new"><?php esc_html_e( '+ New feed', 'oc-theme' ); ?></button>
			</p>

			<div id="ocfeed-list">
				<?php self::cards( $feeds ); ?>
			</div>

			<div class="ocfeed__sheet" id="ocfeed-sheet" hidden>
				<div class="ocfeed__card">
					<h2 id="ocfeed-title"><?php esc_html_e( 'New feed', 'oc-theme' ); ?></h2>

					<label class="ocfeed__f">
						<span><?php esc_html_e( 'Who is it for?', 'oc-theme' ); ?></span>
						<span class="ocfeed__pick">
							<button type="button" class="ocfeed__who is-on" data-ocfeed-who="meta"><?php esc_html_e( 'Meta', 'oc-theme' ); ?></button>
							<button type="button" class="ocfeed__who" data-ocfeed-who="google"><?php esc_html_e( 'Google', 'oc-theme' ); ?></button>
							<button type="button" class="ocfeed__who" data-ocfeed-who="zap"><?php esc_html_e( 'Zap', 'oc-theme' ); ?></button>
						</span>
					</label>

					<label class="ocfeed__f">
						<span><?php esc_html_e( 'Name', 'oc-theme' ); ?></span>
						<input type="text" id="ocfeed-name" class="regular-text">
					</label>

					<details class="ocfeed__more">
						<summary><?php esc_html_e( 'Settings', 'oc-theme' ); ?></summary>

						<label class="ocfeed__f">
							<span><?php esc_html_e( 'Rebuild', 'oc-theme' ); ?></span>
							<select id="ocfeed-every">
								<option value="hourly"><?php esc_html_e( 'Every hour (recommended)', 'oc-theme' ); ?></option>
								<option value="four"><?php esc_html_e( 'Every four hours', 'oc-theme' ); ?></option>
								<option value="daily"><?php esc_html_e( 'Once a day', 'oc-theme' ); ?></option>
							</select>
						</label>

						<label class="ocfeed__f ocfeed__check">
							<input type="checkbox" id="ocfeed-instock">
							<span><?php esc_html_e( 'Only products in stock', 'oc-theme' ); ?>
								<small><?php esc_html_e( 'Off is usually right: a network wants to be told an item is out of stock, and dropping it instead makes the catalogue look like it lost the product.', 'oc-theme' ); ?></small>
							</span>
						</label>

						<label class="ocfeed__f ocfeed__check">
							<input type="checkbox" id="ocfeed-variants" checked>
							<span><?php esc_html_e( 'Send each variation on its own', 'oc-theme' ); ?>
								<small><?php esc_html_e( 'Each colour and size becomes its own line, tied to the product. This is what lets a shopper land on the one that was advertised.', 'oc-theme' ); ?></small>
							</span>
						</label>

						<label class="ocfeed__f" data-ocfeed-only="meta google">
							<span><?php esc_html_e( 'Format', 'oc-theme' ); ?></span>
							<select id="ocfeed-format">
								<option value="xml"><?php esc_html_e( 'XML', 'oc-theme' ); ?></option>
								<option value="csv"><?php esc_html_e( 'CSV', 'oc-theme' ); ?></option>
							</select>
						</label>

						<label class="ocfeed__f">
							<span><?php esc_html_e( 'Description', 'oc-theme' ); ?></span>
							<select id="ocfeed-desc">
								<option value="short"><?php esc_html_e( 'Short description', 'oc-theme' ); ?></option>
								<option value="long"><?php esc_html_e( 'Full description', 'oc-theme' ); ?></option>
							</select>
						</label>

						<label class="ocfeed__f">
							<span><?php esc_html_e( 'Brand, when a product has none', 'oc-theme' ); ?></span>
							<input type="text" id="ocfeed-brand" class="regular-text" placeholder="<?php echo esc_attr( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>">
						</label>

						<label class="ocfeed__f" data-ocfeed-only="meta google">
							<span><?php esc_html_e( 'Google product category', 'oc-theme' ); ?></span>
							<input type="text" id="ocfeed-gcat" class="regular-text ltr" placeholder="<?php esc_attr_e( 'e.g. 604', 'oc-theme' ); ?>">
						</label>

						<label class="ocfeed__f" data-ocfeed-only="zap">
							<span><?php esc_html_e( 'Delivery time', 'oc-theme' ); ?></span>
							<input type="text" id="ocfeed-delivery" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. 3-5 working days', 'oc-theme' ); ?>">
						</label>

						<label class="ocfeed__f ocfeed__check" data-ocfeed-only="zap">
							<input type="checkbox" id="ocfeed-ship">
							<span><?php esc_html_e( 'Include the shipping price', 'oc-theme' ); ?></span>
						</label>

						<label class="ocfeed__f">
							<span><?php esc_html_e( 'Only these categories', 'oc-theme' ); ?>
								<small><?php esc_html_e( 'Leave empty for the whole shop.', 'oc-theme' ); ?></small>
							</span>
							<select id="ocfeed-cats" multiple size="6">
								<?php foreach ( (array) $cats as $term ) : ?>
									<?php if ( $term instanceof \WP_Term ) : ?>
										<option value="<?php echo esc_attr( (string) $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						</label>
					</details>

					<p class="ocfeed__go">
						<button type="button" class="button button-primary button-hero" id="ocfeed-make"><?php esc_html_e( 'Create feed', 'oc-theme' ); ?></button>
						<button type="button" class="button" id="ocfeed-cancel"><?php esc_html_e( 'Cancel', 'oc-theme' ); ?></button>
						<span class="ocfeed__said" id="ocfeed-said"></span>
					</p>
				</div>
			</div>
		</div>
		<?php

		self::script( wp_create_nonce( 'ocfeed' ) );
	}

	/**
	 * The feeds as cards.
	 *
	 * @param array<string,array<string,mixed>> $feeds Feeds.
	 */
	public static function cards( array $feeds ): void {
		if ( empty( $feeds ) ) {
			echo '<p class="ocfeed__empty">' . esc_html__( 'No feeds yet.', 'oc-theme' ) . '</p>';

			return;
		}

		$every = array(
			'hourly' => __( 'every hour', 'oc-theme' ),
			'four'   => __( 'every four hours', 'oc-theme' ),
			'daily'  => __( 'once a day', 'oc-theme' ),
		);

		foreach ( $feeds as $key => $feed ) {
			$url = Feeds::url( $key );
			?>
			<div class="ocfeed__row" data-ocfeed-row="<?php echo esc_attr( $key ); ?>">
				<div class="ocfeed__who-tag ocfeed__who-tag--<?php echo esc_attr( (string) $feed['target'] ); ?>">
					<?php echo esc_html( self::target_name( (string) $feed['target'] ) ); ?>
				</div>

				<div class="ocfeed__body">
					<b><?php echo esc_html( (string) $feed['name'] ); ?></b>
					<span class="ocfeed__meta" data-ocfeed-meta>
						<?php
						if ( 'ready' === $feed['state'] ) {
							printf(
								/* translators: 1: number of items, 2: date and time, 3: how often it rebuilds. */
								esc_html__( '%1$d items · built %2$s · %3$s', 'oc-theme' ),
								(int) $feed['items'],
								esc_html( (string) wp_date( 'd/m/Y H:i', (int) $feed['made'] ) ),
								esc_html( (string) ( $every[ $feed['every'] ] ?? '' ) )
							);
						} elseif ( 'running' === $feed['state'] ) {
							esc_html_e( 'Building…', 'oc-theme' );
						} else {
							esc_html_e( 'Not built yet', 'oc-theme' );
						}
						?>
					</span>
					<?php if ( ! empty( $feed['in_stock'] ) ) : ?>
						<span class="ocfeed__chip"><?php esc_html_e( 'in stock only', 'oc-theme' ); ?></span>
					<?php endif; ?>
					<?php if ( empty( $feed['variants'] ) ) : ?>
						<span class="ocfeed__chip"><?php esc_html_e( 'no variations', 'oc-theme' ); ?></span>
					<?php endif; ?>
				</div>

				<div class="ocfeed__link">
					<input type="text" readonly value="<?php echo esc_url( $url ); ?>" class="ltr" data-ocfeed-url>
				</div>

				<div class="ocfeed__acts">
					<button type="button" class="button" data-ocfeed-copy><?php esc_html_e( 'Copy', 'oc-theme' ); ?></button>
					<a class="button" href="<?php echo esc_url( $url ); ?>" download><?php esc_html_e( 'Download', 'oc-theme' ); ?></a>
					<button type="button" class="button" data-ocfeed-rebuild><?php esc_html_e( 'Rebuild', 'oc-theme' ); ?></button>
					<button type="button" class="button-link ocfeed__del" data-ocfeed-del><?php esc_html_e( 'Delete', 'oc-theme' ); ?></button>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Looks.
	 */
	private static function styles(): void {
		?>
		<style>
		.ocfeed__lede { max-inline-size: 780px; }
		.ocfeed__empty { color: #646970; }
		.ocfeed__row { display: grid; grid-template-columns: 150px minmax(180px, 1fr) minmax(220px, 1.2fr) auto; gap: 16px; align-items: center;
			background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px 16px; margin-block-end: 10px; }
		.ocfeed__who-tag { font-size: 12px; font-weight: 600; padding: 5px 10px; border-radius: 999px; text-align: center; }
		.ocfeed__who-tag--meta { background: #e7f0fd; color: #0b5cd5; }
		.ocfeed__who-tag--google { background: #e6f4ea; color: #137333; }
		.ocfeed__who-tag--zap { background: #fdecea; color: #b3261e; }
		.ocfeed__body b { display: block; font-size: 14px; }
		.ocfeed__meta { font-size: 12px; color: #646970; }
		.ocfeed__chip { display: inline-block; font-size: 11px; background: #f0f0f1; border-radius: 4px; padding: 1px 6px; margin-inline-start: 6px; color: #646970; }
		.ocfeed__link input { inline-size: 100%; font-size: 12px; background: #f6f7f7; }
		.ocfeed__acts { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
		.ocfeed__del { color: #b32d2e; }
		.ocfeed__sheet { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: grid; place-items: center; z-index: 9999; padding: 20px; }
		.ocfeed__sheet[hidden] { display: none; }
		.ocfeed__card { background: #fff; border-radius: 12px; padding: 26px 28px; inline-size: min(620px, 100%); max-block-size: 88vh; overflow: auto; }
		.ocfeed__card h2 { margin-block-start: 0; }
		.ocfeed__f { display: grid; gap: 6px; margin-block-end: 16px; }
		.ocfeed__f > span { font-size: 13px; font-weight: 600; }
		.ocfeed__f small { display: block; font-weight: 400; color: #646970; font-size: 12px; margin-block-start: 2px; }
		.ocfeed__check { grid-template-columns: auto 1fr; align-items: start; gap: 10px; }
		.ocfeed__pick { display: flex; gap: 8px; }
		.ocfeed__who { flex: 1; padding: 12px; border: 1.5px solid #dcdcde; background: #fff; border-radius: 8px; cursor: pointer; font: inherit; font-weight: 600; }
		.ocfeed__who.is-on { border-color: #2271b1; background: #f0f6fc; color: #0b5cd5; }
		.ocfeed__more { margin-block-end: 18px; }
		.ocfeed__more summary { cursor: pointer; font-weight: 600; margin-block-end: 14px; }
		.ocfeed__go { display: flex; align-items: center; gap: 10px; }
		.ocfeed__said { font-size: 13px; color: #646970; }
		[data-ocfeed-only] { display: grid; }
		[data-ocfeed-only][hidden] { display: none; }
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
			'making'   => __( 'Building the feed…', 'oc-theme' ),
			/* translators: 1: products done, 2: products in total. */
			'progress' => __( 'Building — %1$d of %2$d products', 'oc-theme' ),
			'ready'    => __( 'Ready.', 'oc-theme' ),
			'failed'   => __( 'Something went wrong. Please try again.', 'oc-theme' ),
			'copied'   => __( 'Copied', 'oc-theme' ),
			'sure'     => __( 'Delete this feed? The address stops working.', 'oc-theme' ),
			'newfeed'  => __( 'New feed', 'oc-theme' ),
		);
		?>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>,
				T     = <?php echo wp_json_encode( $strings ); ?>,
				who   = 'meta';

			function post( action, data ) {
				var body = new FormData();
				body.append( 'action', action );
				body.append( '_ajax_nonce', nonce );
				Object.keys( data || {} ).forEach( function ( k ) {
					if ( Array.isArray( data[ k ] ) ) {
						data[ k ].forEach( function ( v ) { body.append( k + '[]', v ); } );
					} else if ( 'object' === typeof data[ k ] && null !== data[ k ] ) {
						Object.keys( data[ k ] ).forEach( function ( j ) {
							if ( Array.isArray( data[ k ][ j ] ) ) {
								data[ k ][ j ].forEach( function ( v ) { body.append( k + '[' + j + '][]', v ); } );
							} else {
								body.append( k + '[' + j + ']', data[ k ][ j ] );
							}
						} );
					} else {
						body.append( k, data[ k ] );
					}
				} );
				return fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } );
			}

			function sprintf( s ) {
				var args = [].slice.call( arguments, 1 ), i = 0;
				return s.replace( /%(\d)\$[ds]|%[ds]/g, function ( m, n ) {
					return n ? args[ n - 1 ] : args[ i++ ];
				} );
			}

			var sheet = document.getElementById( 'ocfeed-sheet' );
			var said  = document.getElementById( 'ocfeed-said' );

			function onlyFor() {
				document.querySelectorAll( '[data-ocfeed-only]' ).forEach( function ( row ) {
					row.hidden = row.getAttribute( 'data-ocfeed-only' ).split( ' ' ).indexOf( who ) < 0;
				} );
			}

			document.querySelectorAll( '[data-ocfeed-who]' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					who = b.getAttribute( 'data-ocfeed-who' );
					document.querySelectorAll( '[data-ocfeed-who]' ).forEach( function ( o ) {
						o.classList.toggle( 'is-on', o === b );
					} );
					onlyFor();
				} );
			} );

			document.getElementById( 'ocfeed-new' ).addEventListener( 'click', function () {
				said.textContent = '';
				document.getElementById( 'ocfeed-title' ).textContent = T.newfeed;
				sheet.hidden = false;
				onlyFor();
			} );

			document.getElementById( 'ocfeed-cancel' ).addEventListener( 'click', function () {
				sheet.hidden = true;
			} );

			function chosen( id ) {
				return [].slice.call( document.getElementById( id ).selectedOptions ).map( function ( o ) { return o.value; } );
			}

			function watch( key ) {
				post( 'ocfeed_state', { key: key } ).then( function ( r ) {
					var d = r && r.data ? r.data : {};
					if ( 'running' === d.state ) {
						said.textContent = sprintf( T.progress, d.done, d.total );
						post( 'ocfeed_run', { key: key } ).then( function () { watch( key ); } );
						return;
					}
					said.textContent = T.ready;
					window.location.reload();
				} ).catch( function () { said.textContent = T.failed; } );
			}

			document.getElementById( 'ocfeed-make' ).addEventListener( 'click', function () {
				said.textContent = T.making;
				post( 'ocfeed_save', {
					key: '',
					feed: {
						target: who,
						name: document.getElementById( 'ocfeed-name' ).value,
						every: document.getElementById( 'ocfeed-every' ).value,
						format: document.getElementById( 'ocfeed-format' ).value,
						desc: document.getElementById( 'ocfeed-desc' ).value,
						brand: document.getElementById( 'ocfeed-brand' ).value,
						gcat: document.getElementById( 'ocfeed-gcat' ).value,
						delivery: document.getElementById( 'ocfeed-delivery' ).value,
						in_stock: document.getElementById( 'ocfeed-instock' ).checked ? 1 : 0,
						variants: document.getElementById( 'ocfeed-variants' ).checked ? 1 : 0,
						ship: document.getElementById( 'ocfeed-ship' ).checked ? 1 : 0,
						cats: chosen( 'ocfeed-cats' )
					}
				} ).then( function ( r ) {
					if ( r && r.success ) { watch( r.data.key ); }
					else { said.textContent = T.failed; }
				} ).catch( function () { said.textContent = T.failed; } );
			} );

			document.addEventListener( 'click', function ( e ) {
				var row = e.target.closest( '[data-ocfeed-row]' );
				if ( ! row ) { return; }
				var key = row.getAttribute( 'data-ocfeed-row' );

				if ( e.target.closest( '[data-ocfeed-copy]' ) ) {
					var box = row.querySelector( '[data-ocfeed-url]' );
					box.select();
					navigator.clipboard.writeText( box.value );
					e.target.textContent = T.copied;
					return;
				}

				if ( e.target.closest( '[data-ocfeed-rebuild]' ) ) {
					var meta = row.querySelector( '[data-ocfeed-meta]' );
					meta.textContent = T.making;
					post( 'ocfeed_run', { key: key, again: '1' } ).then( function step() {
						return post( 'ocfeed_state', { key: key } ).then( function ( r ) {
							var d = r && r.data ? r.data : {};
							if ( 'running' === d.state ) {
								meta.textContent = sprintf( T.progress, d.done, d.total );
								return post( 'ocfeed_run', { key: key } ).then( step );
							}
							window.location.reload();
						} );
					} );
					return;
				}

				if ( e.target.closest( '[data-ocfeed-del]' ) ) {
					if ( ! window.confirm( T.sure ) ) { return; }
					post( 'ocfeed_drop', { key: key } ).then( function () { row.remove(); } );
				}
			} );
		}() );
		</script>
		<?php
	}
}
