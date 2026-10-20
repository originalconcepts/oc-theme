<?php
/**
 * Wishlist: the lists, who owns them, and every change to them.
 *
 * Two tables rather than user meta, because the shop wants to ask
 * questions across all of its customers — which products are saved most,
 * from where, and which of them were later bought — and a serialized
 * array per user cannot answer any of that.
 *
 *   {prefix}oc_wl_lists  one row per list. A guest has exactly one, the
 *                        main list, owned by a random key in a cookie; a
 *                        signed-in customer may have many.
 *   {prefix}oc_wl_items  one row per product in a list. A removal stamps
 *                        `removed` instead of deleting, and a purchase
 *                        stamps `bought`, so the history survives for the
 *                        report.
 *
 * A guest's list follows them into their account the moment they sign in.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Wishlist;

defined( 'ABSPATH' ) || exit;

// Every query in this file reads or writes the feature's own tables, which
// have no WordPress API and no object-cache layer of their own.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Storage and settings.
 */
final class Wishlist {

	/**
	 * Settings option.
	 */
	public const OPTION = 'oc_wishlist';

	/**
	 * Schema version.
	 */
	private const DB_VERSION = '1';

	/**
	 * Cookie holding a guest's key.
	 */
	public const COOKIE = 'oc_wl';

	/**
	 * How many products a list may hold; a list is a choice, not a catalogue.
	 */
	public const MAX_ITEMS = 300;

	/**
	 * How many lists one customer may keep.
	 */
	public const MAX_LISTS = 30;

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// The tables appear only on a shop that switched the feature on.
		if ( self::on() ) {
			add_action( 'admin_init', array( self::class, 'install' ) );
		}

		add_action( 'set_auth_cookie', array( $this, 'merge_on_login' ), 10, 4 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'remember_owner' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'mark_bought' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'mark_bought' ), 10, 1 );
		add_action( 'oc_waitlist_sent', array( $this, 'log_notice' ), 10, 3 );
	}

	/* ------------------------------------------------------------ settings */

	/**
	 * The settings, with what a shop gets before it has chosen anything.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'on'             => 0,
			'header_desktop' => 1,
			'header_mobile'  => 1,
			'header_count'   => 1,
			'card_desktop'   => 'hover',
			'card_mobile'    => 'always',
			'card_side'      => 'left',
			'product_place'  => 'button',
			'page'           => 0,
		);
	}

	/**
	 * The settings as saved, cleaned.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::defaults();

		foreach ( array( 'on', 'header_desktop', 'header_mobile', 'header_count' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$out[ $key ] = empty( $raw[ $key ] ) ? 0 : 1;
			}
		}

		$pick = array(
			'card_desktop'  => array( 'none', 'hover', 'always' ),
			'card_mobile'   => array( 'none', 'always' ),
			'card_side'     => array( 'left', 'right' ),
			'product_place' => array( 'none', 'button', 'image-right', 'image-left' ),
		);

		foreach ( $pick as $key => $allowed ) {
			if ( isset( $raw[ $key ] ) && in_array( (string) $raw[ $key ], $allowed, true ) ) {
				$out[ $key ] = (string) $raw[ $key ];
			}
		}

		$out['page'] = absint( $raw['page'] ?? 0 );

		return $out;
	}

	/**
	 * Is the feature switched on?
	 */
	public static function on(): bool {
		return class_exists( 'WooCommerce' ) && ! empty( self::settings()['on'] );
	}

	/**
	 * The wishlist page's address, creating the page the first time.
	 */
	public static function page_url(): string {
		$id = self::page_id();

		return $id ? (string) get_permalink( $id ) : home_url( '/' );
	}

	/**
	 * The wishlist page, made once and remembered.
	 *
	 * Created only by someone who may: a visitor never causes a page to be
	 * written.
	 */
	public static function page_id(): int {
		$set = self::settings();
		$id  = (int) $set['page'];

		if ( $id && 'publish' === get_post_status( $id ) ) {
			return $id;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return 0;
		}

		$id = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'My lists', 'oc-theme' ),
				'post_name'    => 'wishlist',
				'post_content' => '[oc_wishlist]',
			)
		);

		if ( $id ) {
			$raw         = get_option( self::OPTION, array() );
			$raw         = is_array( $raw ) ? $raw : array();
			$raw['page'] = $id;
			update_option( self::OPTION, $raw, false );
		}

		return $id;
	}

	/* -------------------------------------------------------------- tables */

	/**
	 * Table names.
	 *
	 * @param string $which 'lists', 'items' or 'notices'.
	 */
	public static function table( string $which ): string {
		global $wpdb;

		return $wpdb->prefix . 'oc_wl_' . $which;
	}

	/**
	 * Create or update the tables.
	 */
	public static function install(): void {
		if ( self::DB_VERSION === (string) get_option( 'oc_wl_db' ) ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'lists' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			owner VARCHAR(64) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(190) NOT NULL DEFAULT '',
			is_main TINYINT(1) NOT NULL DEFAULT 0,
			share VARCHAR(32) NOT NULL DEFAULT '',
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			deleted DATETIME NULL,
			PRIMARY KEY  (id),
			KEY owner (owner),
			KEY user_id (user_id),
			KEY share (share)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'items' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			list_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			owner VARCHAR(64) NOT NULL DEFAULT '',
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(16) NOT NULL DEFAULT '',
			added DATETIME NOT NULL,
			removed DATETIME NULL,
			bought DATETIME NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY list_id (list_id),
			KEY owner (owner),
			KEY product_id (product_id),
			KEY added (added)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'notices' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			contact CHAR(64) NOT NULL DEFAULT '',
			sent DATETIME NOT NULL,
			bought DATETIME NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY contact (contact),
			KEY sent (sent)
			) $charset;"
		);

		update_option( 'oc_wl_db', self::DB_VERSION, false );
	}

	/**
	 * Are the tables there? A front-end request must not run dbDelta, so
	 * it asks instead, once per request.
	 */
	public static function ready(): bool {
		static $ready = null;

		if ( null === $ready ) {
			$ready = self::DB_VERSION === (string) get_option( 'oc_wl_db' );
		}

		return $ready;
	}

	/* --------------------------------------------------------------- owner */

	/**
	 * Who the current visitor is to the wishlist: "u<id>" for a customer,
	 * "g<key>" for a guest with a cookie, '' for a guest who has saved
	 * nothing yet.
	 *
	 * @param bool $create Give a guest a key if they have none.
	 */
	public static function owner( bool $create = false ): string {
		$user = get_current_user_id();

		if ( $user ) {
			return 'u' . $user;
		}

		$key = self::guest_key();

		if ( '' !== $key ) {
			return 'g' . $key;
		}

		if ( ! $create ) {
			return '';
		}

		$key = bin2hex( random_bytes( 16 ) );

		$_COOKIE[ self::COOKIE ] = $key;

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$key,
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => rtrim( (string) COOKIEPATH, '/' ) . '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		return 'g' . $key;
	}

	/**
	 * The guest key from the cookie, if it is one we could have issued.
	 */
	private static function guest_key(): string {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';

		return preg_match( '/^[a-f0-9]{32}$/', $raw ) ? $raw : '';
	}

	/* --------------------------------------------------------------- lists */

	/**
	 * Every live list of an owner, main list first.
	 *
	 * @param string $owner Owner.
	 * @return array<int,object>
	 */
	public static function lists( string $owner ): array {
		if ( '' === $owner || ! self::ready() ) {
			return array();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'lists' ) . ' WHERE owner = %s AND deleted IS NULL ORDER BY is_main DESC, id ASC', $owner ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One list, only if it belongs to the owner.
	 *
	 * @param int    $id    List id.
	 * @param string $owner Owner.
	 */
	public static function list_of( int $id, string $owner ): ?object {
		foreach ( self::lists( $owner ) as $row ) {
			if ( (int) $row->id === $id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The owner's main list, made when first needed.
	 *
	 * @param string $owner Owner.
	 */
	public static function main_list( string $owner ): int {
		foreach ( self::lists( $owner ) as $row ) {
			if ( (int) $row->is_main ) {
				return (int) $row->id;
			}
		}

		return self::create_list( $owner, '', true );
	}

	/**
	 * Make a list.
	 *
	 * @param string $owner Owner.
	 * @param string $name  Name; the main list carries none and is named in the language of whoever reads it.
	 * @param bool   $main  Whether this is the main list.
	 * @return int List id, or 0.
	 */
	public static function create_list( string $owner, string $name, bool $main = false ): int {
		if ( '' === $owner || ! self::ready() ) {
			return 0;
		}

		if ( ! $main && count( self::lists( $owner ) ) >= self::MAX_LISTS ) {
			return 0;
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
		$wpdb->insert(
			self::table( 'lists' ),
			array(
				'owner'   => $owner,
				'user_id' => 'u' === $owner[0] ? (int) substr( $owner, 1 ) : 0,
				'name'    => $main ? '' : mb_substr( $name, 0, 60 ),
				'is_main' => $main ? 1 : 0,
				'created' => $now,
				'updated' => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Rename, share, stop sharing or delete a list.
	 *
	 * @param int    $id    List id.
	 * @param string $owner Owner.
	 * @param string $what  'rename', 'share', 'unshare' or 'delete'.
	 * @param string $name  New name, for a rename.
	 * @return string The share token after a share, otherwise ''.
	 */
	public static function change_list( int $id, string $owner, string $what, string $name = '' ): string {
		$list = self::list_of( $id, $owner );

		if ( ! $list ) {
			return '';
		}

		global $wpdb;

		$now = current_time( 'mysql', true );
		$set = array( 'updated' => $now );
		$out = '';

		if ( 'rename' === $what && ! (int) $list->is_main && '' !== trim( $name ) ) {
			$set['name'] = mb_substr( trim( $name ), 0, 60 );
		} elseif ( 'share' === $what ) {
			$out          = '' !== (string) $list->share ? (string) $list->share : bin2hex( random_bytes( 12 ) );
			$set['share'] = $out;
		} elseif ( 'unshare' === $what ) {
			$set['share'] = '';
		} elseif ( 'delete' === $what && ! (int) $list->is_main ) {
			$set['deleted'] = $now;
			$set['share']   = '';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'items' ) . ' SET removed = %s WHERE list_id = %d AND removed IS NULL', $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.
		} else {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
		$wpdb->update( self::table( 'lists' ), $set, array( 'id' => $id ) );

		return $out;
	}

	/**
	 * A shared list by its token.
	 *
	 * @param string $token Share token.
	 */
	public static function shared( string $token ): ?object {
		if ( ! preg_match( '/^[a-f0-9]{24}$/', $token ) || ! self::ready() ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'lists' ) . ' WHERE share = %s AND deleted IS NULL LIMIT 1', $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.

		return $row ? $row : null;
	}

	/**
	 * What a list is called, to whoever is reading.
	 *
	 * @param object $row List row.
	 */
	public static function name_of( object $row ): string {
		return (int) $row->is_main || '' === (string) $row->name ? __( 'My favourites', 'oc-theme' ) : (string) $row->name;
	}

	/* --------------------------------------------------------------- items */

	/**
	 * Live items of a list, newest first.
	 *
	 * @param int $list_id List.
	 * @return array<int,object>
	 */
	public static function items( int $list_id ): array {
		if ( ! self::ready() ) {
			return array();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'items' ) . ' WHERE list_id = %d AND removed IS NULL ORDER BY added DESC, id DESC LIMIT %d', $list_id, self::MAX_ITEMS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every live item an owner has, across their lists.
	 *
	 * @param string $owner Owner.
	 * @return array<int,object>
	 */
	public static function owner_items( string $owner ): array {
		if ( '' === $owner || ! self::ready() ) {
			return array();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT i.* FROM ' . self::table( 'items' ) . ' i JOIN ' . self::table( 'lists' ) . ' l ON l.id = i.list_id WHERE i.owner = %s AND i.removed IS NULL AND l.deleted IS NULL ORDER BY i.added DESC', $owner ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are ours.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Put a product in a list. Saying it twice changes nothing.
	 *
	 * @param string $owner     Owner.
	 * @param int    $list_id   List; 0 for the main list.
	 * @param int    $product   Product.
	 * @param int    $variation Variation, or 0.
	 * @param string $source    Where it was saved from.
	 * @return int The list it went into, or 0.
	 */
	public static function add( string $owner, int $list_id, int $product, int $variation = 0, string $source = '' ): int {
		if ( '' === $owner || ! self::ready() || ! wc_get_product( $product ) ) {
			return 0;
		}

		// A guest has one list, whatever the request asks for.
		$list_id = ( $list_id && 'u' === $owner[0] && self::list_of( $list_id, $owner ) ) ? $list_id : self::main_list( $owner );

		if ( ! $list_id ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
		$have = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'items' ) . ' WHERE list_id = %d AND product_id = %d AND variation_id = %d AND removed IS NULL LIMIT 1', $list_id, $product, $variation ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.

		if ( $have ) {
			return $list_id;
		}

		if ( count( self::items( $list_id ) ) >= self::MAX_ITEMS ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
		$wpdb->insert(
			self::table( 'items' ),
			array(
				'list_id'      => $list_id,
				'owner'        => $owner,
				'product_id'   => $product,
				'variation_id' => $variation,
				'source'       => in_array( $source, array( 'card', 'product', 'cart', 'popup', 'list' ), true ) ? $source : '',
				'added'        => current_time( 'mysql', true ),
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
		$wpdb->update( self::table( 'lists' ), array( 'updated' => current_time( 'mysql', true ) ), array( 'id' => $list_id ) );

		do_action( 'oc_wishlist_added', $product, $variation, $owner, $source );

		return $list_id;
	}

	/**
	 * Take a product out of one list, or out of all of the owner's lists.
	 *
	 * Every variation of it goes with it: a heart on a card stands for the
	 * product, whichever size was saved.
	 *
	 * @param string $owner   Owner.
	 * @param int    $product Product.
	 * @param int    $list_id List, or 0 for every list.
	 */
	public static function remove( string $owner, int $product, int $list_id = 0 ): void {
		if ( '' === $owner || ! self::ready() ) {
			return;
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		if ( $list_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'items' ) . ' SET removed = %s WHERE owner = %s AND list_id = %d AND product_id = %d AND removed IS NULL', $now, $owner, $list_id, $product ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'items' ) . ' SET removed = %s WHERE owner = %s AND product_id = %d AND removed IS NULL', $now, $owner, $product ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.
	}

	/* ------------------------------------------------------------ handover */

	/**
	 * A guest who signs in takes their saved products with them.
	 *
	 * Runs whenever WordPress sets the login cookie — the theme's own phone
	 * login, Google, the account form and registration all pass through it.
	 *
	 * @param string $cookie     Cookie value (unused).
	 * @param int    $expire     Expiry (unused).
	 * @param int    $expiration Expiration (unused).
	 * @param int    $user_id    The customer who signed in.
	 */
	public function merge_on_login( $cookie, $expire, $expiration, $user_id ): void {
		$key = self::guest_key();

		if ( '' === $key || ! $user_id || ! self::ready() ) {
			return;
		}

		$guest = 'g' . $key;
		$user  = 'u' . (int) $user_id;
		$items = self::owner_items( $guest );

		if ( $items ) {
			$main = self::main_list( $user );

			foreach ( $items as $item ) {
				self::add( $user, $main, (int) $item->product_id, (int) $item->variation_id, (string) $item->source );
			}

			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'lists' ) . ' SET deleted = %s WHERE owner = %s AND deleted IS NULL', current_time( 'mysql', true ), $guest ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.
		}

		unset( $_COOKIE[ self::COOKIE ] );

		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, rtrim( (string) COOKIEPATH, '/' ) . '/', COOKIE_DOMAIN, is_ssl(), true );
		}
	}

	/* -------------------------------------------------------------- report */

	/**
	 * Note on the order who placed it, as the wishlist knows them, so a
	 * guest's purchase can be matched to what that guest had saved.
	 *
	 * @param \WC_Order $order Order being created.
	 */
	public function remember_owner( $order ): void {
		$owner = self::owner();

		if ( '' !== $owner && $order instanceof \WC_Order ) {
			$order->update_meta_data( '_oc_wl_owner', $owner );
		}
	}

	/**
	 * A paid order: stamp the saved products it bought, and any
	 * back-in-stock notices it answered.
	 *
	 * @param int $order_id Order.
	 */
	public function mark_bought( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || ! self::ready() || '' !== (string) $order->get_meta( '_oc_wl_counted' ) ) {
			return;
		}

		$order->update_meta_data( '_oc_wl_counted', '1' );
		$order->save();

		$owners = array_filter(
			array(
				$order->get_customer_id() ? 'u' . $order->get_customer_id() : '',
				(string) $order->get_meta( '_oc_wl_owner' ),
			)
		);

		$contacts = array_filter(
			array(
				self::contact_hash( (string) $order->get_billing_email() ),
				self::contact_hash( (string) $order->get_billing_phone() ),
			)
		);

		$now = current_time( 'mysql', true );

		global $wpdb;

		foreach ( $order->get_items() as $line ) {
			if ( ! $line instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = (int) $line->get_product_id();

			foreach ( array_unique( $owners ) as $owner ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'items' ) . ' SET bought = %s, order_id = %d WHERE owner = %s AND product_id = %d AND bought IS NULL', $now, $order->get_id(), $owner, $product ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.
			}

			foreach ( array_unique( $contacts ) as $contact ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'notices' ) . ' SET bought = %s, order_id = %d WHERE contact = %s AND product_id = %d AND bought IS NULL AND sent > %s', $now, $order->get_id(), $contact, $product, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.
			}
		}
	}

	/**
	 * Remember that a back-in-stock notice went out, for the report.
	 *
	 * @param int         $product_id Product holding the waitlist.
	 * @param array       $entry      The signup.
	 * @param \WC_Product $target     Product or variation that came back.
	 */
	public function log_notice( $product_id, $entry, $target ): void {
		if ( ! self::ready() || ! is_array( $entry ) ) {
			return;
		}

		$contact = self::contact_hash( (string) ( $entry['email'] ?? '' ) );
		$contact = '' !== $contact ? $contact : self::contact_hash( (string) ( $entry['phone'] ?? '' ) );

		if ( '' === $contact ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the feature's own table.
		$wpdb->insert(
			self::table( 'notices' ),
			array(
				'product_id'   => (int) $product_id,
				'variation_id' => $target instanceof \WC_Product_Variation ? $target->get_id() : 0,
				'contact'      => $contact,
				'sent'         => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * An email or a phone number, reduced to something comparable and not
	 * readable: the report needs to match people, not to know them.
	 *
	 * @param string $raw Email or phone.
	 */
	public static function contact_hash( string $raw ): string {
		$raw = strtolower( trim( $raw ) );

		if ( '' === $raw ) {
			return '';
		}

		if ( false === strpos( $raw, '@' ) ) {
			$digits = preg_replace( '/\D+/', '', $raw );
			$digits = (string) preg_replace( '/^(972|0)/', '', (string) $digits );

			if ( strlen( $digits ) < 7 ) {
				return '';
			}

			$raw = 'tel:' . $digits;
		}

		return hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) );
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
