<?php
/**
 * Wishlist on the shop's pages: the hearts, the one endpoint behind them,
 * the lists page, and the account menu entry.
 *
 * Everything a visitor sees is filled in by the browser from one small
 * request. The shop's pages are held in a full-page cache, and a heart
 * printed filled into cached HTML would be filled for everyone who opened
 * that page afterwards.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Wishlist;

defined( 'ABSPATH' ) || exit;

/**
 * Front of the wishlist.
 */
final class Front {

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// The page shortcode answers even while the feature is off, so a
		// page that was created never shows a raw [oc_wishlist].
		add_shortcode( 'oc_wishlist', array( $this, 'page' ) );

		if ( ! Wishlist::on() ) {
			return;
		}

		// WooCommerce's front-end endpoint: the cards it returns are priced
		// as the shop prices them. Promotion plugins step aside whenever
		// is_admin() is true, which admin-ajax always is. The admin-ajax
		// pair stays for pages cached before the switch.
		add_action( 'wc_ajax_oc_wl', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_oc_wl', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_oc_wl', array( $this, 'ajax' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 20 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'oc_header_icons_before_cart', array( $this, 'header' ) );
		add_action( 'oc_card_media_overlay', array( $this, 'card' ) );
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'product_button' ), 30 );
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'product_overlay' ), 21 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'account_item' ), 30 );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'account_url' ), 10, 2 );
	}

	/* -------------------------------------------------------------- hearts */

	/**
	 * The heart, outlined; the stylesheet fills it once it is saved.
	 *
	 * @param int $size Pixels.
	 */
	public static function icon( int $size = 20 ): string {
		return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M12 20.3s-7.4-4.5-9.2-9C1.6 8 3.5 4.6 7 4.6c2 0 3.6 1.1 5 3 1.4-1.9 3-3 5-3 3.5 0 5.4 3.4 4.2 6.7-1.8 4.5-9.2 9-9.2 9Z"/></svg>';
	}

	/**
	 * A heart button for one product.
	 *
	 * @param int    $product Product.
	 * @param string $where   card | product | image.
	 */
	private static function heart( int $product, string $where ): string {
		return '<button type="button" class="oc-wl-heart oc-wl-heart--' . esc_attr( $where ) . '" data-oc-wl="' . $product . '" data-oc-wl-src="' . esc_attr( 'image' === $where ? 'product' : $where ) . '"'
			. ' aria-pressed="false" aria-label="' . esc_attr__( 'Save to my favourites', 'oc-theme' ) . '">'
			. self::icon( 'product' === $where ? 22 : 20 ) . '</button>';
	}

	/**
	 * The header icon: a link to the lists page with a count.
	 */
	public function header(): void {
		$set = Wishlist::settings();

		if ( empty( $set['header_desktop'] ) && empty( $set['header_mobile'] ) ) {
			return;
		}

		printf(
			'<a class="oc-hicon oc-wl-link" href="%1$s">%2$s<span class="screen-reader-text">%3$s</span>%4$s</a>',
			esc_url( Wishlist::page_url() ),
			self::icon(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			esc_html__( 'My lists', 'oc-theme' ),
			! empty( $set['header_count'] ) ? '<span class="oc-wl-count" data-oc-wl-count hidden>0</span>' : ''
		);
	}

	/**
	 * The heart on a catalogue card's picture.
	 *
	 * @param mixed $product Product.
	 */
	public function card( $product ): void {
		$set = Wishlist::settings();

		if ( ! $product instanceof \WC_Product || ( 'none' === $set['card_desktop'] && 'none' === $set['card_mobile'] ) ) {
			return;
		}

		echo self::heart( $product->get_id(), 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * The heart beside the add-to-cart button.
	 */
	public function product_button(): void {
		global $product;

		if ( 'button' !== Wishlist::settings()['product_place'] || ! $product instanceof \WC_Product ) {
			return;
		}

		echo self::heart( $product->get_id(), 'product' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * The heart on the product's picture. Printed after the gallery and set
	 * into it by the script: WooCommerce's slider treats every child of the
	 * gallery as a slide, so it cannot be printed inside.
	 */
	public function product_overlay(): void {
		global $product;

		$place = (string) Wishlist::settings()['product_place'];

		if ( 0 !== strpos( $place, 'image-' ) || ! $product instanceof \WC_Product ) {
			return;
		}

		echo '<template data-oc-wl-gallery="' . esc_attr( substr( $place, 6 ) ) . '">' . self::heart( $product->get_id(), 'image' ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Classes the stylesheet reads to place and show the hearts.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function body_class( array $classes ): array {
		$set = Wishlist::settings();

		$classes[] = 'oc-wl-on';
		$classes[] = 'oc-wl-cd-' . $set['card_desktop'];
		$classes[] = 'oc-wl-cm-' . $set['card_mobile'];
		$classes[] = 'oc-wl-side-' . $set['card_side'];
		$classes[] = 'oc-wl-pp-' . $set['product_place'];
		$classes[] = 'oc-wl-pl-' . $set['page_layout'];

		if ( empty( $set['header_desktop'] ) ) {
			$classes[] = 'oc-wl-hd-off';
		}

		if ( empty( $set['header_mobile'] ) ) {
			$classes[] = 'oc-wl-hm-off';
		}

		return $classes;
	}

	/* -------------------------------------------------------------- assets */

	/**
	 * The script, and the words it speaks.
	 */
	public function assets(): void {
		$min = function_exists( 'oc_asset_min' ) ? oc_asset_min( '/assets/js/wishlist.js' ) : '/assets/js/wishlist.js';
		$ver = function_exists( 'oc_asset_version' ) ? oc_asset_version( $min ) : OC_THEME_VERSION;

		wp_enqueue_script(
			'oc-wishlist',
			OC_THEME_URI . $min,
			array( 'oc-theme' ),
			$ver,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_localize_script(
			'oc-wishlist',
			'ocWl',
			array(
				'ajax'       => \WC_AJAX::get_endpoint( 'oc_wl' ),
				't'          => self::token(),
				'logged'     => is_user_logged_in() ? 1 : 0,
				'tools'      => Wishlist::settings()['tools_style'],
				'page'       => Wishlist::page_url(),
				'main'       => __( 'My favourites', 'oc-theme' ),
				/* translators: %s: the list's name. */
				'added'      => __( 'Added to: %s', 'oc-theme' ),
				/* translators: %s: the list's name. */
				'removed'    => __( 'Removed from: %s', 'oc-theme' ),
				'change'     => __( 'Change', 'oc-theme' ),
				'undo'       => __( 'Undo', 'oc-theme' ),
				'guest'      => __( 'Saved on this device. Sign in to keep it and to create lists.', 'oc-theme' ),
				'signIn'     => __( 'Sign in', 'oc-theme' ),
				'popTitle'   => __( 'Save to a list', 'oc-theme' ),
				'newList'    => __( 'New list', 'oc-theme' ),
				'listName'   => __( 'List name', 'oc-theme' ),
				'create'     => __( 'Create', 'oc-theme' ),
				'close'      => __( 'Close', 'oc-theme' ),
				'guestPop'   => __( 'Sign in to create more lists and to keep them on every device.', 'oc-theme' ),
				'one'        => __( '1 product', 'oc-theme' ),
				/* translators: %d: number of products. */
				'many'       => __( '%d products', 'oc-theme' ),
				'empty'      => __( 'Empty', 'oc-theme' ),
				'copied'     => __( 'Link copied', 'oc-theme' ),
				'confirmDl'  => __( 'Delete this list and the products in it?', 'oc-theme' ),
				'save'       => __( 'Save', 'oc-theme' ),
				'cancel'     => __( 'Cancel', 'oc-theme' ),
				'failed'     => __( 'That did not work. Please try again.', 'oc-theme' ),
				'full'       => __( 'This list is full.', 'oc-theme' ),
				'share'      => __( 'Share the list', 'oc-theme' ),
				'unshare'    => __( 'Stop sharing', 'oc-theme' ),
				'moveTo'     => __( 'Move to another list', 'oc-theme' ),
				'move'       => __( 'Move to a list', 'oc-theme' ),
				'removeIt'   => __( 'Remove from this list', 'oc-theme' ),
				/* translators: %s: list name. */
				'movedTo'    => __( 'Moved to: %s', 'oc-theme' ),
				'noOther'    => __( 'No other list yet. Name one and the product moves there.', 'oc-theme' ),
				'sharedNote' => __( 'This list is shared. Anyone with the link can see it.', 'oc-theme' ),
				'shareLink'  => __( 'Link to the list', 'oc-theme' ),
				'copy'       => __( 'Copy link', 'oc-theme' ),
				'shareVia'   => __( 'More ways to share', 'oc-theme' ),
				'rename'     => __( 'Rename', 'oc-theme' ),
				'deleteList' => __( 'Delete list', 'oc-theme' ),
			)
		);
	}

	/**
	 * A token that changes daily, so a cached page can still write.
	 *
	 * A WordPress nonce printed into a page that stays in the page cache for
	 * days goes stale; this accepts today's and yesterday's value.
	 *
	 * @param int $days_ago 0 for today, 1 for yesterday.
	 */
	public static function token( int $days_ago = 0 ): string {
		return substr( hash_hmac( 'sha256', 'oc_wl|' . gmdate( 'Y-m-d', time() - $days_ago * DAY_IN_SECONDS ), wp_salt( 'nonce' ) ), 0, 20 );
	}

	/* ---------------------------------------------------------------- ajax */

	/**
	 * Is this request allowed to touch the wishlist?
	 *
	 * The day token, a request that comes from this site, and a cap per
	 * network so a script cannot fill the tables.
	 */
	private static function permitted(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the day token below is the check.
		$t = isset( $_POST['t'] ) ? sanitize_key( wp_unslash( $_POST['t'] ) ) : '';

		if ( ! hash_equals( self::token(), $t ) && ! hash_equals( self::token( 1 ), $t ) ) {
			return false;
		}

		$from = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : ( isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only its host is compared.
		$host = (string) wp_parse_url( $from, PHP_URL_HOST );

		if ( '' === $host || strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return false;
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'oc_wl_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );

		if ( $n > 120 ) {
			return false;
		}

		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * The one endpoint: read the state, change a list, render the page.
	 */
	public function ajax(): void {
		nocache_headers();

		if ( ! self::permitted() || ! Wishlist::ready() ) {
			wp_send_json_error( array( 'code' => 'denied' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- permitted() checked the day token.
		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'state';
		$product = isset( $_POST['p'] ) ? absint( $_POST['p'] ) : 0;
		$vary    = isset( $_POST['v'] ) ? absint( $_POST['v'] ) : 0;
		$list    = isset( $_POST['l'] ) ? absint( $_POST['l'] ) : 0;
		$source  = isset( $_POST['s'] ) ? sanitize_key( wp_unslash( $_POST['s'] ) ) : '';
		$name    = isset( $_POST['n'] ) ? sanitize_text_field( wp_unslash( $_POST['n'] ) ) : '';
		$share   = isset( $_POST['share'] ) ? sanitize_key( wp_unslash( $_POST['share'] ) ) : '';
		$to      = isset( $_POST['to'] ) ? absint( $_POST['to'] ) : 0;
		// phpcs:enable

		$logged = is_user_logged_in();
		$extra  = array();

		switch ( $op ) {
			case 'add':
				$owner = Wishlist::owner( true );
				$into  = Wishlist::add( $owner, $list, $product, $vary, $source );

				if ( ! $into ) {
					wp_send_json_error( array( 'code' => 'full' ) );
				}

				$extra['list'] = $into;
				break;

			case 'remove':
				Wishlist::remove( Wishlist::owner(), $product, $list );
				break;

			case 'create':
				if ( ! $logged ) {
					wp_send_json_error( array( 'code' => 'login' ) );
				}

				$new = Wishlist::create_list( Wishlist::owner(), '' !== $name ? $name : __( 'New list', 'oc-theme' ) );

				if ( ! $new ) {
					wp_send_json_error( array( 'code' => 'full' ) );
				}

				if ( $product ) {
					Wishlist::add( Wishlist::owner(), $new, $product, $vary, 'popup' );
				}

				$extra['list'] = $new;
				break;

			case 'move':
				if ( ! $logged ) {
					wp_send_json_error( array( 'code' => 'login' ) );
				}

				if ( ! Wishlist::move( Wishlist::owner(), $product, $list, $to ) ) {
					wp_send_json_error( array( 'code' => 'failed' ) );
				}

				$extra['list'] = $to;
				break;

			case 'rename':
			case 'share':
			case 'unshare':
			case 'delete':
				if ( ! $logged ) {
					wp_send_json_error( array( 'code' => 'login' ) );
				}

				$token = Wishlist::change_list( $list, Wishlist::owner(), $op, $name );

				if ( 'share' === $op && '' !== $token ) {
					$extra['url'] = add_query_arg( 'wl', $token, Wishlist::page_url() );
				}
				break;

			case 'page':
				wp_send_json_success( '' !== $share ? self::shared_page( $share ) : self::own_page( $list ) );
		}

		wp_send_json_success( array_merge( self::state(), $extra ) );
	}

	/**
	 * Everything the hearts and the header need to know about the visitor.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$owner = Wishlist::owner();
		$lists = array();
		$has   = array();
		$all   = array();

		foreach ( Wishlist::lists( $owner ) as $row ) {
			$items = Wishlist::items( (int) $row->id );
			$thumb = array();

			foreach ( array_slice( $items, 0, 3 ) as $item ) {
				$thumb[] = self::thumb( (int) ( $item->variation_id ? $item->variation_id : $item->product_id ) );
			}

			foreach ( $items as $item ) {
				$has[ (int) $item->product_id ][] = (int) $row->id;
				$all[ (int) $item->product_id ]   = true;
			}

			$lists[] = array(
				'id'    => (int) $row->id,
				'name'  => Wishlist::name_of( $row ),
				'main'  => (int) $row->is_main,
				'count' => count( $items ),
				'thumb' => array_values( array_filter( $thumb ) ),
				'share' => '' !== (string) $row->share ? add_query_arg( 'wl', (string) $row->share, Wishlist::page_url() ) : '',
			);
		}

		foreach ( $has as $pid => $ids ) {
			$has[ $pid ] = array_values( array_unique( $ids ) );
		}

		return array(
			'logged' => is_user_logged_in() ? 1 : 0,
			'lists'  => $lists,
			'has'    => (object) $has,
			'count'  => count( $all ),
		);
	}

	/**
	 * A small picture of a product, or ''.
	 *
	 * @param int $id Product or variation.
	 */
	private static function thumb( int $id ): string {
		$product = wc_get_product( $id );

		if ( ! $product ) {
			return '';
		}

		$image = (int) $product->get_image_id();

		if ( ! $image && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$image  = $parent ? (int) $parent->get_image_id() : 0;
		}

		$url = $image ? wp_get_attachment_image_url( $image, 'woocommerce_gallery_thumbnail' ) : '';

		return is_string( $url ) ? $url : '';
	}

	/* ---------------------------------------------------------------- page */

	/**
	 * The page shortcode: a shell the script fills.
	 */
	public function page(): string {
		if ( ! Wishlist::on() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only address.
		$share = isset( $_GET['wl'] ) ? sanitize_key( wp_unslash( $_GET['wl'] ) ) : '';

		return '<div class="oc-wlp" data-oc-wl-page data-share="' . esc_attr( $share ) . '" aria-live="polite">'
			. '<div class="oc-wlp__loading" aria-hidden="true"><span></span><span></span><span></span></div>'
			. '</div>';
	}

	/**
	 * The visitor's own lists, one of them opened.
	 *
	 * @param int $open List to open; 0 for the main list.
	 * @return array<string,mixed>
	 */
	private static function own_page( int $open ): array {
		$owner = Wishlist::owner();
		$rows  = Wishlist::lists( $owner );

		if ( ! $rows ) {
			return array(
				'html'   => self::empty_html( true ),
				'state'  => self::state(),
				'list'   => 0,
				'shared' => 0,
			);
		}

		$current = $rows[0];

		foreach ( $rows as $row ) {
			if ( (int) $row->id === $open ) {
				$current = $row;
			}
		}

		return array(
			'html'   => self::grid( Wishlist::items( (int) $current->id ) ),
			'state'  => self::state(),
			'list'   => (int) $current->id,
			'shared' => 0,
		);
	}

	/**
	 * A list someone shared, read-only.
	 *
	 * @param string $token Share token.
	 * @return array<string,mixed>
	 */
	private static function shared_page( string $token ): array {
		$row = Wishlist::shared( $token );

		if ( ! $row ) {
			return array(
				'html'   => '<div class="oc-wlp__empty"><p class="oc-wlp__empty-h">' . esc_html__( 'This list is no longer shared', 'oc-theme' ) . '</p></div>',
				'list'   => 0,
				'shared' => 1,
				'title'  => '',
			);
		}

		$who  = (int) $row->user_id ? get_userdata( (int) $row->user_id ) : false;
		$name = $who ? trim( (string) $who->first_name ) : '';

		// "My favourites" is the owner's word; a friend reads whose they are.
		if ( (int) $row->is_main ) {
			/* translators: %s: the first name of the person who shared the list. */
			$title = '' !== $name ? sprintf( __( '%s\'s favourites', 'oc-theme' ), $name ) : __( 'A favourites list', 'oc-theme' );
		} else {
			$title = Wishlist::name_of( $row );
		}

		return array(
			'html'   => self::grid( Wishlist::items( (int) $row->id ) ),
			'list'   => (int) $row->id,
			'shared' => 1,
			'title'  => $title,
			/* translators: %s: the first name of the person who shared the list. */
			'by'     => '' !== $name ? sprintf( __( 'Shared with you by %s', 'oc-theme' ), $name ) : __( 'A list shared with you', 'oc-theme' ),
		);
	}

	/**
	 * Products as the shop's own cards.
	 *
	 * @param array<int,object> $items Items.
	 */
	private static function grid( array $items ): string {
		if ( ! $items ) {
			return self::empty_html( false );
		}

		$ids = array();

		foreach ( $items as $item ) {
			$ids[] = (int) $item->product_id;
		}

		$ids = array_values( array_unique( $ids ) );

		$query = new \WP_Query(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'post__in'            => $ids,
				'orderby'             => 'post__in',
				'posts_per_page'      => count( $ids ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		if ( ! $query->have_posts() ) {
			return self::empty_html( false );
		}

		global $post;

		ob_start();

		wc_set_loop_prop( 'name', 'oc-wishlist' );
		wc_set_loop_prop( 'columns', '4' );

		// The theme's card grid hangs off a .woocommerce ancestor, which a plain page lacks.
		echo '<div class="woocommerce">';
		woocommerce_product_loop_start();

		while ( $query->have_posts() ) {
			$query->the_post();
			$GLOBALS['product'] = wc_get_product( $post->ID ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- template contract.
			wc_get_template_part( 'content', 'product' );
		}

		woocommerce_product_loop_end();
		echo '</div>';
		wp_reset_postdata();
		wc_reset_loop();

		return (string) ob_get_clean();
	}

	/**
	 * Nothing here yet.
	 *
	 * @param bool $none The visitor has no lists at all.
	 */
	private static function empty_html( bool $none ): string {
		return '<div class="oc-wlp__empty">'
			. '<span class="oc-wlp__empty-ic">' . self::icon( 34 ) . '</span>'
			. '<p class="oc-wlp__empty-h">' . esc_html( $none ? __( 'Nothing saved yet', 'oc-theme' ) : __( 'This list is empty', 'oc-theme' ) ) . '</p>'
			. '<p class="oc-wlp__empty-t">' . esc_html__( 'Tap the heart on any product to save it here.', 'oc-theme' ) . '</p>'
			. '<a class="oc-wlp__empty-cta" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'To the shop', 'oc-theme' ) . '</a>'
			. '</div>';
	}

	/* ------------------------------------------------------------- account */

	/**
	 * "My lists" in the account menu, after the orders.
	 *
	 * @param array<string,string> $items Menu items.
	 * @return array<string,string>
	 */
	public function account_item( $items ): array {
		$items = (array) $items;
		$out   = array();

		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'orders' === $key ) {
				$out['oc-wishlist'] = __( 'My lists', 'oc-theme' );
			}
		}

		if ( ! isset( $out['oc-wishlist'] ) ) {
			$out = array_slice( $out, 0, 1, true ) + array( 'oc-wishlist' => __( 'My lists', 'oc-theme' ) ) + array_slice( $out, 1, null, true );
		}

		return $out;
	}

	/**
	 * That menu item goes to the lists page, not to an account endpoint.
	 *
	 * @param string $url      URL.
	 * @param string $endpoint Endpoint.
	 */
	public function account_url( $url, $endpoint ): string {
		return 'oc-wishlist' === $endpoint ? Wishlist::page_url() : (string) $url;
	}
}
