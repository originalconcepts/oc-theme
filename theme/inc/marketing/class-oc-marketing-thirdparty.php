<?php
/**
 * Holding a third-party marketing tag back until the page has drawn.
 *
 * Flashy's own snippet injects its library the moment the head is parsed.
 * The file is small, but running it costs the phone a fifth of a second on
 * the main thread, in the window where the largest picture is trying to
 * appear — measured at 224ms against jQuery's 137ms on a throttled device.
 *
 * Nothing is lost by waiting. The snippet builds a queue and the library,
 * on arrival, drains whatever it finds there:
 *
 *     const { queue: t = [] } = v.flashy || {};
 *     for ( ; t.length; ) { ... "init" ... "setCustomer" ... }
 *
 * So this puts the queue up first — which makes the plugin's own snippet
 * skip its injection, since it only acts when window.flashy is absent —
 * and fetches the library once the page is done and the visitor idle, or
 * the moment they touch anything, whichever comes first.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Deferred loading for the Flashy tag.
 */
final class Third_Party {

	/**
	 * Where the library lives. Filterable: this address belongs to the
	 * plugin's snippet, and the plugin could one day change it.
	 */
	private const SRC = 'https://js.flashyapp.com/thunder.js';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'stub' ), 0 );
		add_action( 'wp_footer', array( $this, 'loader' ), 99 );
	}

	/**
	 * Is the deferral wanted, and is there anything to defer?
	 */
	private static function on(): bool {
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}

		$s = Settings::get();

		if ( empty( $s['thirdparty']['flashy'] ) ) {
			return false;
		}

		return self::plugged();
	}

	/**
	 * Is the plugin that prints the snippet actually active?
	 *
	 * It announces itself through no constant, class or function of its
	 * own — checked, on the shop, and it defines none of them — so the
	 * active list is what there is to go on. Matching the folder rather
	 * than the exact file survives the plugin being updated or renamed.
	 */
	private static function plugged(): bool {
		foreach ( (array) get_option( 'active_plugins', array() ) as $one ) {
			if ( false !== stripos( (string) $one, 'flashy' ) ) {
				return true;
			}
		}

		// A network activation keeps its list somewhere else entirely.
		if ( is_multisite() ) {
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $one ) {
				if ( false !== stripos( (string) $one, 'flashy' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The queue, printed before the plugin's snippet runs.
	 *
	 * Its own loader begins `if ( ! a.flashy )`, so finding this here it
	 * adds nothing and injects nothing — but its `flashy( 'init', … )` and
	 * the `flashy( 'PageView' )` after it still land in the queue.
	 */
	public function stub(): void {
		if ( ! self::on() ) {
			return;
		}

		?>
		<script id="oc-flashy-stub">
		window.flashy = window.flashy || function () { window.flashy.queue.push( arguments ); };
		window.flashy.queue = window.flashy.queue || [];
		</script>
		<?php
	}

	/**
	 * Fetch the library once the page is out of the way.
	 */
	public function loader(): void {
		if ( ! self::on() ) {
			return;
		}

		/**
		 * The library's address.
		 *
		 * @param string $src Script URL.
		 */
		$src = (string) apply_filters( 'oc_flashy_src', self::SRC );

		$wait = (int) Settings::get()['thirdparty']['wait'];

		?>
		<script id="oc-flashy-loader">
		( function () {
			var src  = <?php echo wp_json_encode( $src ); ?>;
			var wait = <?php echo (int) $wait; ?>;
			var went = false;
			var evs  = [ 'pointerdown', 'keydown', 'touchstart', 'wheel', 'scroll' ];

			function go() {
				if ( went ) { return; }
				went = true;

				evs.forEach( function ( e ) {
					window.removeEventListener( e, go, { passive: true, capture: true } );
				} );

				var s = document.createElement( 'script' );
				s.src = src;
				s.async = true;
				document.head.appendChild( s );
			}

			// Anyone who touches the page is here on purpose: load at once.
			evs.forEach( function ( e ) {
				window.addEventListener( e, go, { passive: true, capture: true } );
			} );

			// And for the visitor who only reads: after the page has
			// finished, at the first quiet moment, and in any case before
			// the wait is out. Idle alone would fire inside the very window
			// this is meant to keep clear, so it waits for load first.
			function arm() {
				if ( window.requestIdleCallback ) {
					window.requestIdleCallback( go, { timeout: wait } );
				} else {
					window.setTimeout( go, wait );
				}
			}

			if ( 'complete' === document.readyState ) { arm(); }
			else { window.addEventListener( 'load', arm, { once: true } ); }
		} )();
		</script>
		<?php
	}
}
