<?php
/**
 * A person to ask, on the product page.
 *
 * A card under the add-to-cart icons (or under the tabs): a face or three,
 * a name and a role, and one button — WhatsApp with a message already
 * written, or a call. A green dot on the face says someone is there right
 * now, drawn from the hours the shop keeps; outside them the dot simply
 * goes, and a shop that takes calls can hand the button to WhatsApp
 * instead until the morning.
 *
 * Every press is counted per product, so the shop can see which pages
 * make people reach for a human.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

if ( ! defined( 'ABSPATH' ) && ! defined( 'OC_TESTS' ) ) {
	exit;
}

/**
 * Settings, markup, hours, links and the click log.
 */
final class Product_Contact {

	/**
	 * Click table, without prefix.
	 */
	public const TABLE = 'oc_contact_clicks';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'wp', array( $this, 'place' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
	}

	/*
	 * ------------------------------------------------------------ settings
	 */

	/**
	 * Everything the block needs, read once.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$mod = static function ( string $key, $def = '' ) {
			return get_theme_mod( 'oc_contact_' . $key, $def );
		};

		$images = array();

		foreach ( array( 1, 2, 3 ) as $i ) {
			$id = absint( $mod( 'img_' . $i, 0 ) );

			if ( $id > 0 && wp_attachment_is_image( $id ) ) {
				$images[] = $id;
			}
		}

		$channel = 'phone' === (string) $mod( 'channel', 'whatsapp' ) ? 'phone' : 'whatsapp';
		$days    = array_values(
			array_filter(
				array_map( 'intval', explode( ',', (string) $mod( 'days', '0,1,2,3,4' ) ) ),
				static fn( $d ) => $d >= 0 && $d <= 6
			)
		);

		return array(
			'on'       => (bool) $mod( 'on', false ),
			'title'    => (string) $mod( 'title', 'plain' ),
			'text'     => (string) $mod( 'title_text', '' ),
			'name'     => (string) $mod( 'name', '' ),
			'role'     => (string) $mod( 'role', '' ),
			'images'   => $images,
			'phone'    => (string) $mod( 'phone', '' ),
			'channel'  => $channel,
			'fallback' => (bool) $mod( 'fallback', true ),
			'online'   => (bool) $mod( 'online', true ),
			'from'     => self::clock( (string) $mod( 'from', '09:00' ), '09:00' ),
			'to'       => self::clock( (string) $mod( 'to', '18:00' ), '18:00' ),
			'days'     => $days,
			'msg'      => (string) $mod( 'msg', '' ),
			'btn'      => (string) $mod( 'btn', '' ),
			'place'    => 'tabs' === (string) $mod( 'place', 'atc' ) ? 'tabs' : 'atc',
			'frame'    => in_array( (string) $mod( 'frame', 'shadow' ), array( 'shadow', 'line', 'none' ), true ) ? (string) $mod( 'frame', 'shadow' ) : 'shadow',
			'bg'       => (string) $mod( 'bg', '' ),
			'tx'       => (string) $mod( 'tx', '' ),
			'focus'    => max( 0, min( 100, (int) $mod( 'focus', 35 ) ) ),
			'now'      => (string) $mod( 'now', '' ),
			'tel'      => (bool) $mod( 'show_phone', true ),
			'hide'     => (bool) $mod( 'hide_off', false ),
		);
	}

	/**
	 * A time of day as HH:MM, or the fallback.
	 *
	 * @param string $value Stored value.
	 * @param string $def   Fallback.
	 */
	public static function clock( string $value, string $def ): string {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $def;
	}

	/*
	 * ------------------------------------------------------------ pure parts
	 */

	/**
	 * Is the shop taking questions at this moment?
	 *
	 * A window that ends before it starts runs over midnight: 20:00 to
	 * 02:00 is open at 23:00 and at 01:00. The day is checked against
	 * the window's first day only, which is how people read it.
	 *
	 * @param string $from  HH:MM.
	 * @param string $to    HH:MM.
	 * @param string $now   HH:MM.
	 * @param int[]  $days  Weekdays kept, Sunday 0.
	 * @param int    $today Weekday now, Sunday 0.
	 */
	public static function open_now( string $from, string $to, string $now, array $days, int $today ): bool {
		$m = static function ( string $hm ): int {
			$p = explode( ':', $hm );

			return (int) $p[0] * 60 + (int) ( $p[1] ?? 0 );
		};

		$f = $m( $from );
		$t = $m( $to );
		$n = $m( $now );

		if ( $f === $t ) {
			return false;
		}

		if ( $f < $t ) {
			return in_array( $today, $days, true ) && $n >= $f && $n < $t;
		}

		// Over midnight: the late half belongs to the day it started on,
		// the early half to the day before.
		if ( $n >= $f ) {
			return in_array( $today, $days, true );
		}

		return $n < $t && in_array( ( $today + 6 ) % 7, $days, true );
	}

	/**
	 * The message with its placeholders filled. Both spellings are honoured:
	 * the tokens the field documents, and the Hebrew words a shopkeeper
	 * would type.
	 *
	 * @param string $template What the shop wrote.
	 * @param string $product  Product name.
	 * @param string $link     Product address.
	 */
	public static function message( string $template, string $product, string $link ): string {
		$out = str_replace( array( '[product]', '[שם המוצר]' ), $product, $template );

		return str_replace( array( '[link]', '[קישור המוצר]', '[קישור]' ), $link, $out );
	}

	/**
	 * A phone as WhatsApp wants it: digits only, international, no plus.
	 * A local number (leading zero) takes the shop's country code.
	 *
	 * @param string $phone As typed.
	 * @param string $cc    Country calling code, digits.
	 */
	public static function digits( string $phone, string $cc = '972' ): string {
		$d = (string) preg_replace( '/\D+/', '', $phone );

		if ( '' === $d ) {
			return '';
		}

		if ( 0 === strpos( $d, '00' ) ) {
			return substr( $d, 2 );
		}

		if ( '0' === $d[0] ) {
			return $cc . substr( $d, 1 );
		}

		return $d;
	}

	/**
	 * The WhatsApp link.
	 *
	 * @param string $phone As typed.
	 * @param string $text  Message, plain.
	 * @param string $cc    Country calling code.
	 */
	public static function wa_link( string $phone, string $text, string $cc = '972' ): string {
		$d = self::digits( $phone, $cc );

		if ( '' === $d ) {
			return '';
		}

		return 'https://wa.me/' . $d . ( '' !== $text ? '?text=' . rawurlencode( $text ) : '' );
	}

	/**
	 * The call link.
	 *
	 * @param string $phone As typed.
	 * @param string $cc    Country calling code.
	 */
	public static function tel_link( string $phone, string $cc = '972' ): string {
		$d = self::digits( $phone, $cc );

		return '' === $d ? '' : 'tel:+' . $d;
	}

	/*
	 * ------------------------------------------------------------ front
	 */

	/**
	 * Where the card goes: under the add-to-cart icons, or under the tabs
	 * and whatever upsell follows them.
	 */
	public function place(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$s = self::settings();

		if ( ! $s['on'] || '' === $s['phone'] ) {
			return;
		}

		if ( 'tabs' === $s['place'] ) {
			// Tabs laid out below everything: the card follows them and the
			// upsells that come after. Tabs beside the gallery or under it
			// sit in a column of their own — there the card rides inside the
			// tabs box, so it keeps to that column wherever the column is.
			if ( 'below' === get_theme_mod( 'oc_product_tabs_pos', 'below' ) ) {
				add_action( 'woocommerce_after_single_product_summary', array( $this, 'render' ), 16 );
			} else {
				add_action( 'woocommerce_product_after_tabs', array( $this, 'render' ) );
			}
			return;
		}

		// Under the icons that follow the add-to-cart form. A sold-out
		// product has no form; there the card follows the sold-out block.
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;

		if ( $product instanceof \WC_Product && ! $product->is_in_stock() ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'render' ), 31 );
			return;
		}

		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'render' ), 20 );
	}

	/**
	 * The shop's country calling code, for a number typed the local way.
	 */
	public static function cc(): string {
		$base = function_exists( 'wc_get_base_location' ) ? (string) ( wc_get_base_location()['country'] ?? '' ) : '';
		$map  = array(
			'IL' => '972',
			'US' => '1',
			'GB' => '44',
			'DE' => '49',
			'FR' => '33',
		);

		return (string) apply_filters( 'oc_contact_country_code', $map[ $base ] ?? '972', $base );
	}

	/**
	 * Print the card.
	 */
	public function render(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$s = self::settings();

		$name = (string) $product->get_name();
		$url  = (string) $product->get_permalink();
		$cc   = self::cc();

		// The default is two lines, joined here rather than in one string:
		// a newline inside a translated string is one escaping mishap away
		// from vanishing, and it did. A backslash-n typed into the field by
		// hand becomes a real line break too.
		$template = '' !== $s['msg']
			? str_replace( '\\n', "\n", $s['msg'] )
			: __( "Hi, I'd like some help with [product]", 'oc-theme' ) . "\n" . __( 'Link: [link]', 'oc-theme' );

		$text = self::message( $template, $name, $url );
		$wa   = self::wa_link( $s['phone'], $text, $cc );
		$tel  = self::tel_link( $s['phone'], $cc );

		if ( '' === $wa || '' === $tel ) {
			return;
		}

		switch ( $s['title'] ) {
			case 'product':
				/* translators: %s: product name. */
				$title = sprintf( __( 'Have a question about %s?', 'oc-theme' ), $name );
				break;
			case 'custom':
				$title = self::message( $s['text'], $name, $url );
				break;
			case 'none':
				$title = '';
				break;
			default:
				$title = __( 'Have a question?', 'oc-theme' );
		}

		$open = self::open_now( $s['from'], $s['to'], (string) current_time( 'H:i' ), $s['days'], (int) current_time( 'w' ) );

		// Which button, right now. A call outside the hours becomes WhatsApp
		// when the shop allows it; the script re-decides on the visitor's
		// clock in the shop's time zone, so a page from a cache is right too.
		$channel = $s['channel'];

		if ( 'phone' === $channel && ! $open && $s['fallback'] ) {
			$channel = 'whatsapp';
		}

		$labels = array(
			'whatsapp' => '' !== $s['btn'] && 'whatsapp' === $s['channel'] ? $s['btn'] : __( 'WhatsApp', 'oc-theme' ),
			'phone'    => '' !== $s['btn'] && 'phone' === $s['channel'] ? $s['btn'] : __( 'Call now', 'oc-theme' ),
		);

		$style = '';

		if ( '' !== $s['bg'] ) {
			$style .= '--oc-pcon-bg:' . $s['bg'] . ';';
		}

		if ( '' !== $s['tx'] ) {
			$style .= '--oc-pcon-tx:' . $s['tx'] . ';';
		}

		$style .= '--oc-pcon-focus:' . $s['focus'] . '%;';

		$faces = '';

		foreach ( $s['images'] as $id ) {
			// The 'medium' size, never 'thumbnail': WordPress crops the
			// thumbnail to a square from the centre, and a portrait's face
			// was gone before any focus could keep it.
			$faces .= wp_get_attachment_image(
				$id,
				'medium',
				false,
				array(
					'class'   => 'oc-pcon__face',
					'alt'     => $s['name'],
					'loading' => 'lazy',
				)
			);
		}

		$config = array(
			'from'     => $s['from'],
			'to'       => $s['to'],
			'days'     => $s['days'],
			'tz'       => wp_timezone_string(),
			'online'   => $s['online'],
			'channel'  => $s['channel'],
			'fallback' => $s['fallback'],
			'wa'       => $wa,
			'tel'      => $tel,
			'labels'   => $labels,
			'hide'     => $s['hide'],
			'rest'     => esc_url_raw( rest_url( 'oc/v1/contact-click' ) ),
			'token'    => self::token(),
			'id'       => (int) $product->get_id(),
		);

		// Outside the hours a shop may want no card at all. It is still
		// printed, hidden, so the script can bring it back when the day
		// starts without a reload — and a cached page shows it right.
		printf(
			'<div class="oc-pcon oc-pcon--%1$s oc-pcon--%2$s%3$s" style="%4$s" data-oc-pcon="%5$s"%6$s>',
			esc_attr( $s['frame'] ),
			esc_attr( $s['place'] ),
			$open && $s['online'] ? ' is-open' : '',
			esc_attr( $style ),
			esc_attr( (string) wp_json_encode( $config ) ),
			$s['hide'] && ! $open ? ' hidden' : ''
		);

		if ( '' !== $title ) {
			echo '<h3 class="oc-pcon__title">' . esc_html( $title ) . '</h3>';
		}

		echo '<div class="oc-pcon__card">';

		if ( '' !== $faces ) {
			echo '<span class="oc-pcon__faces oc-pcon__faces--' . count( $s['images'] ) . '">' . $faces . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
		}

		echo '<span class="oc-pcon__who">';

		if ( '' !== $s['name'] ) {
			echo '<span class="oc-pcon__name">' . esc_html( $s['name'] ) . '</span>';
		}

		if ( '' !== $s['role'] ) {
			echo '<span class="oc-pcon__role">' . esc_html( $s['role'] ) . '</span>';
		}

		if ( $s['tel'] ) {
			echo '<a class="oc-pcon__tel" href="' . esc_url( $tel ) . '" dir="ltr">' . esc_html( $s['phone'] ) . '</a>';
		}

		// The green dot lives beside the words, and both show only while
		// the hours say someone is there. The words are the shop's: one
		// adviser is "available", a desk is "we are available".
		if ( $s['online'] ) {
			echo '<span class="oc-pcon__now"><i class="oc-pcon__dot" aria-hidden="true"></i>'
				. esc_html( '' !== $s['now'] ? $s['now'] : __( 'Online now', 'oc-theme' ) ) . '</span>';
		}

		echo '</span>';

		// Both icons ride along; the stylesheet shows the one the button's
		// class names, so the script can turn a call into WhatsApp at
		// closing time by changing a class.
		// The WhatsApp address is escaped as an attribute, not as a URL:
		// esc_url() strips %0A out of any address (a header-injection
		// guard), and %0A is exactly the line break between the message
		// and the link. The address is ours end to end — https://wa.me/,
		// digits, and a raw-url-encoded text — so there is nothing in it
		// for the URL cleaner to catch.
		printf(
			'<a class="oc-pcon__btn oc-pcon__btn--%1$s" href="%2$s"%3$s data-oc-pcon-go="%1$s">%4$s<span>%5$s</span></a>',
			esc_attr( $channel ),
			'whatsapp' === $channel ? esc_attr( $wa ) : esc_url( $tel ),
			'whatsapp' === $channel ? ' target="_blank" rel="noopener"' : '',
			self::icon( 'whatsapp' ) . self::icon( 'phone' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			esc_html( $labels[ $channel ] )
		);

		echo '</div></div>';
	}

	/**
	 * The two icons.
	 *
	 * @param string $channel whatsapp or phone.
	 */
	private static function icon( string $channel ): string {
		if ( 'whatsapp' === $channel ) {
			return '<svg class="oc-pcon__ico oc-pcon__ico--whatsapp" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 1.8a8.2 8.2 0 1 1-4.2 15.3l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 0 1 12 3.8zm-3.2 4.3c-.2 0-.5.1-.7.3-.3.3-1 1-1 2.3s1 2.7 1.2 2.9c.1.2 2 3.1 4.9 4.3 2.4 1 2.9.8 3.4.7.5 0 1.7-.7 1.9-1.4.2-.7.2-1.2.2-1.4-.1-.1-.3-.2-.6-.3l-2-1c-.3-.1-.5-.2-.7.2l-.9 1.1c-.2.2-.3.2-.6.1-.3-.2-1.2-.5-2.3-1.5-.9-.8-1.5-1.7-1.6-2-.2-.3 0-.5.1-.6l.4-.5.3-.5c.1-.2 0-.4 0-.5l-.9-2.2c-.2-.5-.4-.5-.6-.5h-.5z"/></svg>';
		}

		return '<svg class="oc-pcon__ico oc-pcon__ico--phone" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h3.5l1.6 4-2 1.4a11 11 0 0 0 6.5 6.5l1.4-2 4 1.6V19a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg>';
	}

	/*
	 * ------------------------------------------------------------ clicks
	 */

	/**
	 * The table, with its prefix.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Creates the table the first time a click arrives.
	 */
	public static function install(): void {
		if ( '1' === (string) get_option( 'oc_contact_table', '' ) ) {
			return;
		}

		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				product_id bigint(20) unsigned NOT NULL,
				channel varchar(8) NOT NULL,
				t datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY product_id (product_id),
				KEY t (t)
			) {$collate};"
		);

		update_option( 'oc_contact_table', '1', false );
	}

	/**
	 * A day token, so only pages we served can report a click.
	 *
	 * @param int $shift Days back.
	 */
	public static function token( int $shift = 0 ): string {
		$day = gmdate( 'Y-m-d', time() - $shift * DAY_IN_SECONDS );

		return substr( hash_hmac( 'sha256', 'oc_contact|' . $day, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * The route.
	 */
	public function rest(): void {
		register_rest_route(
			'oc/v1',
			'/contact-click',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permit' ),
				'callback'            => array( $this, 'rest_click' ),
			)
		);
	}

	/**
	 * Today's or yesterday's token, and a ceiling per network.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function permit( \WP_REST_Request $req ): bool {
		$t = (string) $req->get_param( '_t' );

		if ( '' === $t || ! ( hash_equals( self::token(), $t ) || hash_equals( self::token( 1 ), $t ) ) ) {
			return false;
		}

		$net = class_exists( '\OC\Theme\Privacy\Log' ) ? \OC\Theme\Privacy\Log::net() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed below.
		$key = 'oc_contact_rl_' . md5( $net );
		$n   = (int) get_transient( $key );

		if ( $n >= 300 ) {
			return false;
		}

		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Count one press.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function rest_click( \WP_REST_Request $req ): \WP_REST_Response {
		$id      = absint( $req->get_param( 'p' ) );
		$channel = 'phone' === (string) $req->get_param( 'c' ) ? 'phone' : 'whatsapp';

		if ( $id < 1 || 'product' !== get_post_type( $id ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 400 );
		}

		self::install();

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->insert(
			self::table(),
			array(
				'product_id' => $id,
				'channel'    => $channel,
				't'          => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s' )
		);

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Clicks per product in a period, most pressed first.
	 *
	 * @param string $from Y-m-d (site time), inclusive.
	 * @param string $to   Y-m-d (site time), inclusive.
	 * @return array<int,array{product_id:int,whatsapp:int,phone:int,total:int}>
	 */
	public static function stats( string $from, string $to ): array {
		if ( '1' !== (string) get_option( 'oc_contact_table', '' ) ) {
			return array();
		}

		global $wpdb;

		$tz    = wp_timezone();
		$start = ( new \DateTimeImmutable( $from . ' 00:00:00', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$end   = ( new \DateTimeImmutable( $to . ' 23:59:59', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, name from the prefix.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, channel, COUNT(*) AS n FROM {$table} WHERE t BETWEEN %s AND %s GROUP BY product_id, channel",
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();

		foreach ( $rows as $r ) {
			$pid = (int) $r['product_id'];

			if ( ! isset( $out[ $pid ] ) ) {
				$out[ $pid ] = array(
					'product_id' => $pid,
					'whatsapp'   => 0,
					'phone'      => 0,
					'total'      => 0,
				);
			}

			$out[ $pid ][ 'phone' === $r['channel'] ? 'phone' : 'whatsapp' ] += (int) $r['n'];
			$out[ $pid ]['total'] += (int) $r['n'];
		}

		usort( $out, static fn( $a, $b ) => $b['total'] <=> $a['total'] );

		return array_values( $out );
	}

	/**
	 * Clicks per day in a period, for the small chart.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<string,int> day => clicks.
	 */
	public static function by_day( string $from, string $to ): array {
		if ( '1' !== (string) get_option( 'oc_contact_table', '' ) ) {
			return array();
		}

		global $wpdb;

		$tz    = wp_timezone();
		$start = ( new \DateTimeImmutable( $from . ' 00:00:00', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$end   = ( new \DateTimeImmutable( $to . ' 23:59:59', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT t FROM {$table} WHERE t BETWEEN %s AND %s", $start, $end ),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();

		foreach ( $rows as $r ) {
			$day = ( new \DateTimeImmutable( (string) $r['t'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d' );

			$out[ $day ] = ( $out[ $day ] ?? 0 ) + 1;
		}

		ksort( $out );

		return $out;
	}
}
