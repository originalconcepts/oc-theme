<?php
/**
 * Speed readiness: what this site still needs before it measures like the
 * reference shop, and a button beside everything the theme can do itself.
 *
 * The theme's own speed work (critical CSS, the stylesheet in pieces, late
 * scripts, instant navigation) arrives with every release. What does not is
 * the per-site part: pictures still JPEG, a home banner four thousand
 * pixels wide, pixels firing before the first paint, a cache plugin fighting
 * the host's cache. This screen lists those as a traffic light, fixes the
 * safe ones on a click, and says exactly where the rest is done.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

use OC\Theme\Marketing\Settings as Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Speed.
 */
final class Speed {

	/**
	 * Widest a desktop banner picture needs to be, and a phone one.
	 */
	private const HERO_W   = 1983;
	private const HERO_W_M = 1024;

	/**
	 * Heaviest a banner picture should weigh, in bytes.
	 */
	private const HERO_BYTES   = 180 * 1024;
	private const HERO_BYTES_M = 120 * 1024;

	/**
	 * WebP quality for a re-encoded banner picture: what the reference shop
	 * uses, judged by eye on a phone and a desktop.
	 */
	private const HERO_Q = 62;

	/**
	 * Plugins that fight the theme or the host. Slug fragment → why.
	 *
	 * @var array<string,string>
	 */
	private const CLASHES = array(
		'wp-rocket'        => 'cache',
		'autoptimize'      => 'cache',
		'w3-total-cache'   => 'cache',
		'wp-super-cache'   => 'cache',
		'litespeed-cache'  => 'cache',
		'wp-fastest-cache' => 'cache',
		'wp-optimize'      => 'cache',
		'hummingbird'      => 'cache',
		'nitropack'        => 'cache',
		'perfmatters'      => 'cache',
		'jetpack-boost'    => 'cache',
		'sg-cachepress'    => 'cache',
		'wp-smushit'       => 'images',
		'ewww-image'       => 'images',
		'shortpixel'       => 'images',
		'imagify'          => 'images',
		'webp-express'     => 'images',
	);

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 56 );
		add_action( 'admin_post_oc_speed_holds', array( $this, 'holds' ) );
		add_action( 'admin_post_oc_speed_instant', array( $this, 'instant' ) );
		add_action( 'admin_post_oc_speed_upload', array( $this, 'upload' ) );
		add_action( 'admin_post_oc_speed_hero', array( $this, 'hero' ) );
	}

	/**
	 * Submenu under theme settings.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			__( 'Speed', 'oc-theme' ),
			__( 'Speed', 'oc-theme' ),
			'manage_woocommerce',
			'oc-speed',
			array( $this, 'screen' )
		);
	}

	/* ------------------------------------------------------------ checks */

	/**
	 * Every check, in the order they matter.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function checks(): array {
		return array_filter(
			array(
				$this->check_hero(),
				$this->check_pictures(),
				$this->check_upload(),
				$this->check_holds(),
				$this->check_instant(),
				$this->check_critical(),
				$this->check_object_cache(),
				$this->check_page_cache(),
				$this->check_plugins(),
			)
		);
	}

	/**
	 * One row of the table.
	 *
	 * @param string $state  ok | warn | bad | info.
	 * @param string $title  What was checked.
	 * @param string $detail What was found.
	 * @param array  $action Optional: button => label, do => admin_post action, or href => link (+ new => true).
	 * @return array<string,mixed>
	 */
	private static function row( string $state, string $title, string $detail, array $action = array() ): array {
		return array(
			'state'  => $state,
			'title'  => $title,
			'detail' => $detail,
			'action' => $action,
		);
	}

	/**
	 * The banner pictures on the front page: the first thing a phone paints.
	 *
	 * @return array<string,mixed>
	 */
	private function check_hero(): array {
		$bad = array();
		$n   = 0;

		foreach ( self::hero_pictures() as $p ) {
			++$n;
			$why = self::hero_problem( $p );

			if ( '' !== $why ) {
				$bad[] = $why;
			}
		}

		if ( 0 === $n ) {
			return self::row( 'info', __( 'Front page banner', 'oc-theme' ), __( 'The front page does not open on a banner, or the composer is not in use.', 'oc-theme' ) );
		}

		if ( ! $bad ) {
			/* translators: %d: number of pictures. */
			return self::row( 'ok', __( 'Front page banner', 'oc-theme' ), sprintf( _n( '%d picture, WebP, the right size and weight.', '%d pictures, WebP, the right size and weight.', $n, 'oc-theme' ), $n ) );
		}

		$can = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );

		return self::row(
			'bad',
			__( 'Front page banner', 'oc-theme' ),
			implode( ' ', $bad ) . ' ' . __( 'The banner is the largest thing on the first screen; on a phone line every 100 KB here is about half a second.', 'oc-theme' ),
			$can ? array(
				'button' => __( 'Re-encode the banner pictures', 'oc-theme' ),
				'do'     => 'oc_speed_hero',
				'note'   => __( 'WebP, capped at 1983 px on a desktop and 1024 px on a phone. The originals are copied to wp-content/upgrade first.', 'oc-theme' ),
			) : array()
		);
	}

	/**
	 * What is wrong with one banner picture, or nothing.
	 *
	 * @param array<string,mixed> $p From hero_pictures().
	 */
	private static function hero_problem( array $p ): string {
		$max_w = $p['phone'] ? self::HERO_W_M : self::HERO_W;
		$max_b = $p['phone'] ? self::HERO_BYTES_M : self::HERO_BYTES;
		$name  = basename( (string) $p['file'] );
		$why   = array();

		if ( 'image/webp' !== $p['mime'] ) {
			$why[] = __( 'not WebP', 'oc-theme' );
		}

		if ( $p['w'] > $max_w ) {
			/* translators: 1: width in px, 2: the cap. */
			$why[] = sprintf( __( '%1$d px wide (%2$d is enough)', 'oc-theme' ), $p['w'], $max_w );
		}

		if ( $p['bytes'] > $max_b ) {
			/* translators: 1: weight, 2: the cap. */
			$why[] = sprintf( __( '%1$s (%2$s would do)', 'oc-theme' ), size_format( $p['bytes'] ), size_format( $max_b ) );
		}

		if ( ! $why ) {
			return '';
		}

		/* translators: 1: file name, 2: what is wrong. */
		return sprintf( __( '%1$s: %2$s.', 'oc-theme' ), $name, implode( ', ', $why ) );
	}

	/**
	 * The pictures of the front page's opening banner, with their facts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function hero_pictures(): array {
		if ( ! class_exists( '\OC\Blocks\Registry' ) ) {
			return array();
		}

		$front = (int) get_option( 'page_on_front' );

		if ( $front < 1 ) {
			return array();
		}

		$sections = get_post_meta( $front, \OC\Blocks\Registry::META, true );
		$out      = array();
		$seen     = array();

		foreach ( is_array( $sections ) ? $sections : array() as $section ) {
			if ( empty( $section['on'] ) ) {
				continue;
			}

			if ( 'hero' !== (string) ( $section['type'] ?? '' ) ) {
				break; // Only a banner that opens the page is the first paint.
			}

			foreach ( (array) ( $section['slides'] ?? array() ) as $slide ) {
				$keys = array(
					'img'    => false,
					'poster' => false,
					'imgm'   => true,
				);

				foreach ( $keys as $key => $phone ) {
					$id = absint( $slide[ $key ] ?? 0 );

					if ( $id < 1 || isset( $seen[ $id ] ) ) {
						continue;
					}

					$seen[ $id ] = true;
					$file        = (string) get_attached_file( $id );
					$meta        = wp_get_attachment_metadata( $id );

					if ( '' === $file || ! file_exists( $file ) ) {
						continue;
					}

					$out[] = array(
						'id'    => $id,
						'phone' => $phone,
						'file'  => $file,
						'mime'  => (string) get_post_mime_type( $id ),
						'w'     => (int) ( is_array( $meta ) ? ( $meta['width'] ?? 0 ) : 0 ),
						'bytes' => (int) filesize( $file ),
					);
				}
			}

			break;
		}

		return $out;
	}

	/**
	 * Pictures in the library that are still JPEG or PNG.
	 *
	 * @return array<string,mixed>
	 */
	private function check_pictures(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one count over the posts table, on an admin screen.
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png')" );
		$link = array(
			'button' => __( 'Convert', 'oc-theme' ),
			'href'   => admin_url( 'upload.php?page=oc-media-webp' ),
		);

		if ( 0 === $left ) {
			return self::row( 'ok', __( 'Pictures as WebP', 'oc-theme' ), __( 'Every picture in the library is WebP.', 'oc-theme' ) );
		}

		return self::row(
			$left > 50 ? 'bad' : 'warn',
			__( 'Pictures as WebP', 'oc-theme' ),
			/* translators: %s: number of pictures. */
			sprintf( __( '%s pictures are still JPEG or PNG. WebP is the same picture at about a quarter of the weight; the conversion keeps the original beside it.', 'oc-theme' ), number_format_i18n( $left ) ),
			$link
		);
	}

	/**
	 * Whether new uploads become WebP on their own.
	 *
	 * @return array<string,mixed>
	 */
	private function check_upload(): array {
		if ( get_option( Media_Clean::WEBP, false ) ) {
			return self::row( 'ok', __( 'New uploads', 'oc-theme' ), __( 'Every picture uploaded from now on is written as WebP.', 'oc-theme' ) );
		}

		return self::row(
			'warn',
			__( 'New uploads', 'oc-theme' ),
			__( 'Pictures uploaded from now on stay JPEG or PNG, so the library slowly fills with heavy files again.', 'oc-theme' ),
			array(
				'button' => __( 'Convert on upload', 'oc-theme' ),
				'do'     => 'oc_speed_upload',
			)
		);
	}

	/**
	 * Pixels and chat widgets held back until the visitor moves.
	 *
	 * @return array<string,mixed>|null
	 */
	private function check_holds(): ?array {
		if ( ! class_exists( '\OC\Theme\Marketing\Settings' ) ) {
			return null;
		}

		$m = Marketing::get();

		if ( empty( $m['enabled'] ) ) {
			return self::row( 'info', __( 'Pixels held back', 'oc-theme' ), __( 'Marketing is off, so there is nothing to hold back.', 'oc-theme' ) );
		}

		$third = (array) ( $m['thirdparty'] ?? array() );
		$off   = array();

		$names = array(
			'pixels' => __( 'Meta / TikTok / ChatGPT', 'oc-theme' ),
			'google' => __( 'Google', 'oc-theme' ),
			'flashy' => __( 'Flashy', 'oc-theme' ),
		);

		foreach ( $names as $key => $label ) {
			if ( empty( $third[ $key ] ) ) {
				$off[] = $label;
			}
		}

		if ( ! $off ) {
			return self::row( 'ok', __( 'Pixels held back', 'oc-theme' ), __( 'Every pixel waits for the first touch; events queue and replay, nothing is lost.', 'oc-theme' ) );
		}

		return self::row(
			'warn',
			__( 'Pixels held back', 'oc-theme' ),
			/* translators: %s: list of networks. */
			sprintf( __( 'Loading with the page: %s. Each one fetches its library from another host before the first paint and costs 3–8 points on a phone.', 'oc-theme' ), implode( ', ', $off ) ),
			array(
				'button' => __( 'Hold them back', 'oc-theme' ),
				'do'     => 'oc_speed_holds',
			)
		);
	}

	/**
	 * Instant navigation.
	 *
	 * @return array<string,mixed>
	 */
	private function check_instant(): array {
		if ( get_theme_mod( 'oc_instant_nav', true ) ) {
			return self::row( 'ok', __( 'Instant navigation', 'oc-theme' ), __( 'Pages are prepared as the visitor hovers or touches, so the next click is instant.', 'oc-theme' ) );
		}

		return self::row(
			'warn',
			__( 'Instant navigation', 'oc-theme' ),
			__( 'Switched off in the Customizer.', 'oc-theme' ),
			array(
				'button' => __( 'Switch on', 'oc-theme' ),
				'do'     => 'oc_speed_instant',
			)
		);
	}

	/**
	 * The critical CSS learner: which page types have taught it.
	 *
	 * @return array<string,mixed>
	 */
	private function check_critical(): array {
		if ( ! apply_filters( 'oc_critical_css', true ) ) {
			return self::row( 'bad', __( 'First-screen CSS', 'oc-theme' ), __( 'Switched off by a filter in a plugin or a child theme. Every page waits for the stylesheets before it paints.', 'oc-theme' ) );
		}

		$learned = get_option( 'oc_critical_learned', array() );
		$types   = is_array( $learned ) ? array_keys( array_filter( $learned ) ) : array();
		$want    = array( 'front', 'catalogue', 'product', 'cart', 'checkout' );
		$missing = array_diff( $want, $types );

		if ( ! $missing ) {
			return self::row( 'ok', __( 'First-screen CSS', 'oc-theme' ), __( 'Inlined on every page, and every kind of page has taught it what its scripts build.', 'oc-theme' ) );
		}

		return self::row(
			'info',
			__( 'First-screen CSS', 'oc-theme' ),
			/* translators: %s: page types. */
			sprintf( __( 'Inlined on every page. Not yet taught by: %s — open one of each in a browser once and it learns.', 'oc-theme' ), implode( ', ', $missing ) )
		);
	}

	/**
	 * Redis (or any persistent object cache).
	 *
	 * @return array<string,mixed>
	 */
	private function check_object_cache(): array {
		if ( wp_using_ext_object_cache() ) {
			return self::row( 'ok', __( 'Object cache', 'oc-theme' ), __( 'Redis (or another persistent cache) answers the database questions a page asks.', 'oc-theme' ) );
		}

		return self::row(
			'bad',
			__( 'Object cache', 'oc-theme' ),
			__( 'No persistent object cache. Every uncached page runs its queries from scratch. On Proginter: Manage Website → Redis → activate, then WordPress → Object Cache → enable.', 'oc-theme' )
		);
	}

	/**
	 * The host's page cache.
	 *
	 * @return array<string,mixed>
	 */
	private function check_page_cache(): array {
		if ( is_callable( array( '\Proginter_Optimizer_MU', 'purge_uri' ) ) ) {
			return self::row( 'ok', __( 'Page cache', 'oc-theme' ), __( "Proginter's page cache is in place and the theme clears a page from it the moment it changes.", 'oc-theme' ) );
		}

		return self::row(
			'warn',
			__( 'Page cache', 'oc-theme' ),
			__( "No Proginter page cache seen. A page not served from a cache costs a visitor about a second before anything shows. If the site is elsewhere, use the host's cache, not a plugin.", 'oc-theme' )
		);
	}

	/**
	 * Plugins known to fight the theme's own optimisation.
	 *
	 * @return array<string,mixed>
	 */
	private function check_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		$cache  = array();
		$images = array();

		foreach ( $active as $path ) {
			foreach ( self::CLASHES as $needle => $kind ) {
				if ( false !== strpos( (string) $path, $needle ) ) {
					$name = dirname( (string) $path );

					if ( 'cache' === $kind ) {
						$cache[] = $name;
					} else {
						$images[] = $name;
					}
				}
			}
		}

		if ( ! $cache && ! $images ) {
			return self::row( 'ok', __( 'Plugins', 'oc-theme' ), __( 'No cache or image plugin is doubling what the theme and the host already do.', 'oc-theme' ) );
		}

		$text = array();

		if ( $cache ) {
			/* translators: %s: plugin folders. */
			$text[] = sprintf( __( 'Cache / optimiser plugins: %s. They minify what is already minified, cache behind the host\'s cache and break the first-screen CSS. Deactivate them.', 'oc-theme' ), implode( ', ', $cache ) );
		}

		if ( $images ) {
			/* translators: %s: plugin folders. */
			$text[] = sprintf( __( 'Image plugins: %s. The theme already converts to WebP; two converters fight over the same files.', 'oc-theme' ), implode( ', ', $images ) );
		}

		return self::row(
			$cache ? 'bad' : 'warn',
			__( 'Plugins', 'oc-theme' ),
			implode( ' ', $text ),
			array(
				'button' => __( 'Open plugins', 'oc-theme' ),
				'href'   => admin_url( 'plugins.php' ),
			)
		);
	}

	/* ------------------------------------------------------------ screen */

	/**
	 * The screen.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- notices only.
		if ( isset( $_GET['oc_done'] ) ) {
			$said = sanitize_text_field( wp_unslash( (string) $_GET['oc_done'] ) );
			echo '<div class="notice notice-success"><p>' . esc_html( $said ) . '</p></div>';
		}
		if ( isset( $_GET['oc_fail'] ) ) {
			$said = sanitize_text_field( wp_unslash( (string) $_GET['oc_fail'] ) );
			echo '<div class="notice notice-error"><p>' . esc_html( $said ) . '</p></div>';
		}
		// phpcs:enable

		$rows  = $this->checks();
		$count = array(
			'ok'   => 0,
			'warn' => 0,
			'bad'  => 0,
			'info' => 0,
		);

		foreach ( $rows as $r ) {
			++$count[ $r['state'] ];
		}

		$home = home_url( '/' );
		$psi  = 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( $home );
		?>
		<style>
			.ocsp { max-width: 980px; }
			.ocsp__sum { display: flex; gap: 18px; margin: 10px 0 18px; font-size: 14px; }
			.ocsp__sum b { font-size: 20px; margin-inline-end: 4px; }
			.ocsp table { border-collapse: collapse; width: 100%; background: #fff; border: 1px solid #c3c4c7; }
			.ocsp td, .ocsp th { padding: 12px 14px; border-top: 1px solid #dcdcde; vertical-align: top; text-align: start; }
			.ocsp th { width: 190px; font-weight: 600; }
			.ocsp__dot { display: inline-block; inline-size: 12px; block-size: 12px; border-radius: 50%; margin-inline-end: 8px; vertical-align: -1px; }
			.ocsp__dot--ok { background: #00a32a; }
			.ocsp__dot--warn { background: #dba617; }
			.ocsp__dot--bad { background: #d63638; }
			.ocsp__dot--info { background: #72aee6; }
			.ocsp__act { white-space: nowrap; width: 1%; }
			.ocsp__note { display: block; color: #646970; font-size: 12px; max-width: 260px; white-space: normal; margin-top: 6px; }
			.ocsp__foot { margin-top: 18px; color: #646970; }
		</style>
		<div class="wrap ocsp">
			<h1><?php esc_html_e( 'Speed', 'oc-theme' ); ?></h1>
			<p>
				<?php esc_html_e( 'What the theme does for speed arrives with every update. This is the part that belongs to this site: its pictures, its settings, its host. Green needs nothing; a button fixes what the theme can fix; the rest says where it is done.', 'oc-theme' ); ?>
			</p>
			<div class="ocsp__sum">
				<span><span class="ocsp__dot ocsp__dot--ok"></span><b><?php echo esc_html( (string) $count['ok'] ); ?></b><?php esc_html_e( 'fine', 'oc-theme' ); ?></span>
				<span><span class="ocsp__dot ocsp__dot--warn"></span><b><?php echo esc_html( (string) $count['warn'] ); ?></b><?php esc_html_e( 'worth doing', 'oc-theme' ); ?></span>
				<span><span class="ocsp__dot ocsp__dot--bad"></span><b><?php echo esc_html( (string) $count['bad'] ); ?></b><?php esc_html_e( 'costing points', 'oc-theme' ); ?></span>
			</div>
			<table>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<th scope="row"><span class="ocsp__dot ocsp__dot--<?php echo esc_attr( $r['state'] ); ?>"></span><?php echo esc_html( $r['title'] ); ?></th>
						<td><?php echo esc_html( $r['detail'] ); ?></td>
						<td class="ocsp__act">
							<?php if ( ! empty( $r['action']['do'] ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( $r['action']['do'] ); ?>" />
									<?php wp_nonce_field( $r['action']['do'] ); ?>
									<button type="submit" class="button button-primary"><?php echo esc_html( $r['action']['button'] ); ?></button>
									<?php if ( ! empty( $r['action']['note'] ) ) : ?>
										<span class="ocsp__note"><?php echo esc_html( $r['action']['note'] ); ?></span>
									<?php endif; ?>
								</form>
							<?php elseif ( ! empty( $r['action']['href'] ) ) : ?>
								<a class="button" href="<?php echo esc_url( $r['action']['href'] ); ?>"><?php echo esc_html( $r['action']['button'] ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="ocsp__foot">
				<a class="button" href="<?php echo esc_url( $psi ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Measure with Google PageSpeed', 'oc-theme' ); ?></a>
				<?php esc_html_e( 'Measure the address with www if the site uses it: a redirect costs 0.8 s on a phone and up to 10 points. The first run after a cache clear measures a cold page; trust the second.', 'oc-theme' ); ?>
			</p>
			<p class="ocsp__foot">
				<?php esc_html_e( 'Not seen from here: the DDoS protection level and the cache warm-up rate. Both are in the Proginter panel.', 'oc-theme' ); ?>
			</p>
		</div>
		<?php
	}

	/* ----------------------------------------------------------- actions */

	/**
	 * Common gate for the buttons.
	 *
	 * @param string $action The nonce action.
	 */
	private function gate( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( $action );
	}

	/**
	 * Back to the screen with a word.
	 *
	 * @param string $said What happened.
	 * @param bool   $ok   Whether it went well.
	 */
	private function back( string $said, bool $ok = true ): void {
		wp_safe_redirect( add_query_arg( $ok ? 'oc_done' : 'oc_fail', rawurlencode( $said ), admin_url( 'admin.php?page=oc-speed' ) ) );
		exit;
	}

	/**
	 * Hold every third-party library back until the first touch.
	 */
	public function holds(): void {
		$this->gate( 'oc_speed_holds' );

		$m = Marketing::get();

		$m['thirdparty'] = array_merge(
			(array) ( $m['thirdparty'] ?? array() ),
			array(
				'pixels' => true,
				'google' => true,
				'flashy' => true,
			)
		);

		Marketing::save( $m );
		$this->back( __( 'The pixels now wait for the first touch.', 'oc-theme' ) );
	}

	/**
	 * Instant navigation on.
	 */
	public function instant(): void {
		$this->gate( 'oc_speed_instant' );
		set_theme_mod( 'oc_instant_nav', true );
		$this->back( __( 'Instant navigation is on.', 'oc-theme' ) );
	}

	/**
	 * New uploads become WebP.
	 */
	public function upload(): void {
		$this->gate( 'oc_speed_upload' );
		update_option( Media_Clean::WEBP, true );
		$this->back( __( 'Pictures uploaded from now on are written as WebP.', 'oc-theme' ) );
	}

	/**
	 * The banner pictures: WebP, capped in width, at the reference quality.
	 * The attachment keeps its id, so the composer, the page and every
	 * cached address keep pointing at the same picture; the original file
	 * is copied aside first.
	 */
	public function hero(): void {
		$this->gate( 'oc_speed_hero' );

		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$this->back( __( 'This server cannot write WebP.', 'oc-theme' ), false );
		}

		$bak = WP_CONTENT_DIR . '/upgrade/oc-hero-bak-' . gmdate( 'Ymd' );
		wp_mkdir_p( $bak );

		$done = 0;
		$fail = array();

		foreach ( self::hero_pictures() as $p ) {
			if ( '' === self::hero_problem( $p ) ) {
				continue;
			}

			$r = self::shrink( (int) $p['id'], (bool) $p['phone'], $bak );

			if ( true === $r ) {
				++$done;
			} else {
				$fail[] = basename( (string) $p['file'] ) . ': ' . $r;
			}
		}

		if ( class_exists( '\OC\Theme\Cache' ) && method_exists( '\OC\Theme\Cache', 'forget' ) ) {
			Cache::forget( array( home_url( '/' ) ) );
		}

		if ( $fail ) {
			$this->back( implode( ' ', $fail ), false );
		}

		/* translators: 1: number of pictures, 2: folder. */
		$this->back( sprintf( __( '%1$d banner pictures re-encoded. The originals are in %2$s.', 'oc-theme' ), $done, str_replace( WP_CONTENT_DIR, 'wp-content', $bak ) ) );
	}

	/**
	 * One picture: copy the original aside, resize if wider than the cap,
	 * write WebP at the reference quality over the attachment, rebuild its
	 * sizes.
	 *
	 * @param int    $id    Attachment.
	 * @param bool   $phone Whether it is the phone picture.
	 * @param string $bak   Backup folder.
	 * @return true|string True, or why not.
	 */
	private static function shrink( int $id, bool $phone, string $bak ) {
		$file = (string) get_attached_file( $id );

		if ( '' === $file || ! file_exists( $file ) ) {
			return __( 'file missing', 'oc-theme' );
		}

		if ( ! copy( $file, trailingslashit( $bak ) . $id . '-' . basename( $file ) ) ) {
			return __( 'could not copy the original aside', 'oc-theme' );
		}

		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return $editor->get_error_message();
		}

		$size  = $editor->get_size();
		$max_w = $phone ? self::HERO_W_M : self::HERO_W;

		if ( (int) ( $size['width'] ?? 0 ) > $max_w ) {
			$r = $editor->resize( $max_w, null );

			if ( is_wp_error( $r ) ) {
				return $r->get_error_message();
			}
		}

		$editor->set_quality( self::HERO_Q );

		// The same base name, as .webp — beside the original, which stays
		// in the backup folder (and on disk, if it was a different name).
		$target = preg_replace( '/\.[A-Za-z0-9]+$/', '', $file ) . '.webp';
		$saved  = $editor->save( $target, 'image/webp' );

		if ( is_wp_error( $saved ) ) {
			return $saved->get_error_message();
		}

		$path = (string) ( $saved['path'] ?? $target );

		update_attached_file( $id, $path );
		wp_update_post(
			array(
				'ID'             => $id,
				'post_mime_type' => 'image/webp',
			)
		);

		// Every intermediate size is rebuilt from the new file; the old
		// sizes of a different name stay on disk, harmless and undoable.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );

		return true;
	}
}
