<?php
/**
 * Instant navigation: the next page is fetched — in Chromium, rendered —
 * while the finger is still on its way to the link, and pages change
 * places with a short cross-fade instead of a white flash.
 *
 * Three browser features, each harmless where it is not understood:
 *
 * - Speculation rules (Chromium 121+). WordPress prints them itself since
 *   6.8, in its most careful mode (prefetch, conservative); the theme
 *   turns the dial to prerender on "moderate" eagerness — a hover of
 *   200ms, or the first touch — and lists the pages that must never be
 *   rendered ahead: cart, checkout, account, anything that acts.
 * - Cross-document view transitions (Chromium 126+, Safari 18.2+): the old
 *   page stays on screen until the new one has painted, then fades.
 * - The scripts that count a visit (statistics, heat map, pixels, recently
 *   viewed) run only when the page is actually shown — theme.js's
 *   ocWhenShown — so a prerender nobody opened is not a visit. Server
 *   events skip speculative requests too (Marketing\Events::speculative).
 *
 * @package OC\Theme
 */

namespace OC\Theme;

/**
 * Instant navigation.
 */
final class Instant {

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'wp_speculation_rules_configuration', array( $this, 'configuration' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'exclude' ) );
		add_action( 'wp_head', array( $this, 'head' ), 3 );
		add_action( 'wp_footer', array( $this, 'rules_fallback' ), 90 );
		add_action( 'init', array( $this, 'serve_worker' ), 0 );
	}

	/**
	 * The service worker's address: at the site root (a worker may only
	 * control the path it is served from) and carrying the theme version,
	 * so every release is a new worker and nothing stale survives.
	 *
	 * @return string
	 */
	public static function worker_url(): string {
		return home_url( '/?oc_sw=' . rawurlencode( (string) OC_THEME_VERSION ) );
	}

	/**
	 * The pages a worker must never keep or hand out: the ones that are
	 * the shopper's alone or act on their behalf. Paths are decoded, as the
	 * worker compares them.
	 *
	 * @return array{paths:string[],keys:string[]}
	 */
	public static function worker_rules(): array {
		$paths = array( '/wp-admin', '/wp-login.php', '/wp-json', '/ocadmin', '/oc-feed', '/feed' );

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );

				if ( $id > 0 ) {
					$path = rawurldecode( (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH ) );

					if ( '' !== $path && '/' !== $path ) {
						$paths[] = rtrim( $path, '/' );
					}
				}
			}
		}

		$keys = array( 'add-to-cart', 'remove_item', 'undo_item', 'wc-ajax', 'wc-api', 's', 'key', '_wpnonce', 'action', 'logout', 'preview', 'customize_changeset_uuid', 'oc_pay', 'oc_auth', 'oc_addr', 'oc_addr_del', 'oc_addr_def', 'oc_consent', 'oc_privacy_req', 'oc_ask', 'oc_feed', 'unsubscribe', 'unsub', 'oc_sw' );

		return array(
			'paths' => array_values( array_unique( $paths ) ),
			'keys'  => $keys,
		);
	}

	/**
	 * Serve the worker itself (/?oc_sw=<version>).
	 */
	public function serve_worker(): void {
		if ( ! isset( $_GET['oc_sw'] ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public script, read-only.
			return;
		}

		if ( ! self::on() ) {
			status_header( 404 );
			exit;
		}

		$rules = self::worker_rules();
		$cfg   = array(
			'ver'     => (string) OC_THEME_VERSION,
			'ttl'     => 10 * MINUTE_IN_SECONDS * 1000,
			'again'   => 45 * 1000,
			'exclude' => $rules['paths'],
			'keys'    => $rules['keys'],
		);

		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Service-Worker-Allowed: /' );
		header( 'X-Robots-Tag: noindex' );

		echo str_replace( '__CFG__', (string) wp_json_encode( $cfg ), self::WORKER ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a script of ours; the config is JSON-encoded.
		exit;
	}

	/**
	 * The worker. Pages only (navigations, GET, same origin, not excluded);
	 * every other request passes untouched. A page is kept for ten minutes
	 * and handed out at once on the next navigation to it, with a refresh
	 * behind it when it is older than 45s; a page that shows a signed-in
	 * visitor is never kept, and seeing one empties the shelf. The page
	 * asks for a prefetch on a touch or a hover (theme.js) where the
	 * browser has no speculation rules of its own — Safari, Firefox.
	 */
	const WORKER = <<<'JS'
/* OC Theme — instant navigation worker. Config from the theme. */
var CFG = __CFG__;
var CACHE = 'oc-pages-' + CFG.ver;

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
	e.waitUntil(caches.keys().then(function (keys) {
		return Promise.all(keys.filter(function (k) { return k.indexOf('oc-pages-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
	}).then(function () { return self.clients.claim(); }));
});

function pageUrl(u) {
	var url;
	try { url = new URL(u, self.location.origin); } catch (e) { return null; }
	if (url.origin !== self.location.origin) { return null; }
	url.hash = '';
	var p;
	try { p = decodeURIComponent(url.pathname); } catch (e) { p = url.pathname; }
	for (var i = 0; i < CFG.exclude.length; i++) {
		var ex = CFG.exclude[i];
		if (p === ex || p === ex + '/' || p.indexOf(ex + '/') === 0) { return null; }
	}
	if (/\.(php|xml|txt|json|pdf|zip|jpe?g|png|webp|avif|svg|gif|css|js|map|woff2?|mp4|webm)$/i.test(p) || p.indexOf('/wp-') === 0) { return null; }
	var keys = [];
	url.searchParams.forEach(function (v, k) { keys.push(k); });
	for (var j = 0; j < keys.length; j++) {
		if (CFG.keys.indexOf(keys[j]) > -1) { return null; }
	}
	return url.href;
}

function guest(text) {
	return !/<body[^>]*\bclass="[^"]*\blogged-in\b/.test(text.slice(0, 60000));
}

function store(url, res) {
	if (!res || res.status !== 200 || res.redirected || (res.type !== 'basic' && res.type !== 'default')) { return Promise.resolve(false); }
	// The host's DDoS layer answers a visitor it wants to re-check with its
	// own page, marked by this header — sometimes as a 200. Shelving it
	// would hand the next click a "checking your browser" screen.
	if (res.headers.get('proginter-validator')) { return Promise.resolve(false); }
	var ct = res.headers.get('content-type') || '';
	if (ct.indexOf('text/html') < 0) { return Promise.resolve(false); }
	return res.clone().text().then(function (text) {
		if (!guest(text)) { return caches.delete(CACHE).then(function () { return false; }); }
		var h = new Headers();
		h.set('content-type', ct);
		h.set('x-oc-cached-at', String(Date.now()));
		return caches.open(CACHE).then(function (c) {
			return c.put(url, new Response(text, { status: 200, headers: h }));
		}).then(function () { return true; });
	}).catch(function () { return false; });
}

function kept(url) {
	return caches.open(CACHE).then(function (c) {
		return c.match(url).then(function (hit) {
			if (!hit) { return null; }
			var at = parseInt(hit.headers.get('x-oc-cached-at') || '0', 10);
			if (Date.now() - at > CFG.ttl) { c.delete(url); return null; }
			hit.ocAge = Date.now() - at;
			return hit;
		});
	});
}

function pull(url) {
	return fetch(url, { credentials: 'same-origin', redirect: 'follow', headers: { 'X-OC-Prefetch': '1' } })
		.then(function (res) { return store(url, res); })
		.catch(function () { return false; });
}

self.addEventListener('message', function (e) {
	var d = e.data || {};
	if (d.type === 'oc-clear') { e.waitUntil(caches.delete(CACHE)); return; }
	if (d.type === 'oc-prefetch') {
		var url = pageUrl(d.url);
		if (!url) { return; }
		e.waitUntil(kept(url).then(function (hit) { return hit ? null : pull(url); }));
	}
});

self.addEventListener('fetch', function (e) {
	var req = e.request;
	if (req.method !== 'GET' || req.mode !== 'navigate') { return; }
	var url = pageUrl(req.url);
	if (!url) { return; }
	e.respondWith(kept(url).then(function (hit) {
		if (hit) {
			if (hit.ocAge > CFG.again) { e.waitUntil(pull(url)); }
			return hit;
		}
		return fetch(req).then(function (res) {
			e.waitUntil(store(url, res.clone()));
			return res;
		});
	}));
});
JS;

	/**
	 * Is the feature on for this request?
	 *
	 * @return bool
	 */
	public static function on(): bool {
		if ( is_admin() || is_customize_preview() ) {
			return false;
		}

		/**
		 * Lets a site switch instant navigation off in code.
		 *
		 * @param bool $on Whether the feature is on.
		 */
		return (bool) apply_filters( 'oc_instant_nav', (bool) get_theme_mod( 'oc_instant_nav', true ) );
	}

	/**
	 * Core's speculation rules: render the next page ahead of the click.
	 *
	 * @param mixed $config Core's configuration (mode, eagerness), or null.
	 * @return mixed
	 */
	public function configuration( $config ) {
		if ( ! self::on() ) {
			return $config;
		}

		return array(
			'mode'      => 'prerender',
			'eagerness' => 'moderate',
		);
	}

	/**
	 * Paths a browser must never fetch ahead of a click — the ones that act
	 * on the shopper's behalf or are theirs alone. WooCommerce adds its own
	 * when it knows the filter; these are the theme's and the shop's pages
	 * by their real (translated) slugs.
	 *
	 * @param mixed $paths Core's list.
	 * @return mixed
	 */
	public function exclude( $paths ) {
		if ( ! is_array( $paths ) ) {
			$paths = array();
		}

		// Core keeps every address with a query string out of a prerender,
		// on the chance that it acts. In this shop a query string is a
		// colour (the catalogue card's ?attribute_…), a sort or a filter —
		// pages, and the ones people click most. The addresses that do act
		// are named below instead.
		$paths = array_values(
			array_filter(
				$paths,
				static function ( $path ): bool {
					return '/*\\?(.+)' !== (string) $path;
				}
			)
		);

		return array_values( array_unique( array_merge( $paths, self::excluded_paths() ) ) );
	}

	/**
	 * The exclusion list (URL patterns, as the speculation rules read them).
	 *
	 * @return string[]
	 */
	public static function excluded_paths(): array {
		$paths = array(
			'/wp-admin/*',
			'/wp-login.php*',
			'/wp-json/*',
			'/ocadmin*',
			'/oc-feed/*',
			'/feed/*',
			'/*\\?*add-to-cart=*',
			'/*\\?*remove_item=*',
			'/*\\?*undo_item=*',
			'/*\\?*wc-ajax=*',
			'/*\\?*s=*',
			'/*\\?*oc_pay=*',
			'/*\\?*oc_auth=*',
			'/*\\?*key=*',
			'/*\\?*_wpnonce=*',
			'/*\\?*action=*',
			'/*\\?*wc-api=*',
			'/*\\?*logout*',
			'/*\\?*unsub*',
			'/*\\?*preview=*',
			'/*\\?*customize_*',
			'/*\\?*oc_addr*',
			'/*\\?*oc_consent=*',
			'/*\\?*oc_privacy_req=*',
			'/*\\?*oc_ask=*',
			'/*\\?*oc_feed=*',
		);

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );

				if ( $id > 0 ) {
					$path = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );

					if ( '' !== $path && '/' !== $path ) {
						$paths[] = rtrim( $path, '/' ) . '/*';
						$paths[] = rtrim( $path, '/' );
					}
				}
			}
		}

		return $paths;
	}

	/**
	 * The cross-fade between documents, and the header that stays put.
	 */
	public function head(): void {
		if ( ! self::on() ) {
			return;
		}

		echo '<style id="oc-instant">@view-transition{navigation:auto}::view-transition-old(root),::view-transition-new(root){animation-duration:.16s}@media (prefers-reduced-motion:reduce){@view-transition{navigation:none}}</style>' . "\n";
	}

	/**
	 * Before WordPress 6.8 there is no core printer: the theme prints the
	 * same rules itself.
	 */
	public function rules_fallback(): void {
		if ( ! self::on() || function_exists( 'wp_print_speculation_rules' ) ) {
			return;
		}

		$where = array(
			'and' => array(
				array( 'href_matches' => '/*' ),
				array( 'not' => array( 'href_matches' => self::excluded_paths() ) ),
				array( 'not' => array( 'selector_matches' => '[rel~="nofollow"], [download], [target="_blank"], .no-prefetch, .no-prerender' ) ),
			),
		);

		$rules = array(
			'prerender' => array(
				array(
					'source'    => 'document',
					'where'     => $where,
					'eagerness' => 'moderate',
				),
			),
			'prefetch'  => array(
				array(
					'source'    => 'document',
					'where'     => $where,
					'eagerness' => 'moderate',
				),
			),
		);

		echo '<script type="speculationrules">' . wp_json_encode( $rules ) . '</script>' . "\n";
	}
}
