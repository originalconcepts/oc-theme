<?php
/**
 * The theme's own typed texts, declared for OC Lang: tab titles, the cart
 * drawer's words, the labels, the login drawer, the email openings. The
 * plugin translates the settings it is told about; this is where the
 * theme tells it, with labels for the Translations table.
 *
 * Both filters are the plugin's. On a site without it nothing here runs.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * What of the theme's settings holds words shoppers read.
 */
final class Translatable {

	/**
	 * WooCommerce's customer emails, whose settings carry the theme's
	 * opening words and their collection variants.
	 */
	private const EMAILS = array( 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_refunded_order', 'customer_invoice', 'customer_note', 'customer_reset_password', 'customer_new_account' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_filter( 'oclang_post_types', array( $this, 'post_types' ) );
		add_filter( 'oclang_taxonomies', array( $this, 'taxonomies' ) );
		add_filter( 'oclang_post_meta', array( $this, 'post_meta' ), 10, 2 );
		add_filter( 'oclang_options', array( $this, 'options' ) );
		add_filter( 'oclang_option_labels', array( $this, 'labels' ) );
		add_filter( 'oclang_option_groups', array( $this, 'groups' ) );
		add_filter( 'oclang_menu_item_meta', array( $this, 'menu_item_meta' ) );

		// A saved translation changes what a cached menu panel says: the
		// category names in it, the product names, the addresses.
		if ( class_exists( __NAMESPACE__ . '\\Menu_Panel' ) ) {
			add_action( 'oclang_saved', array( __NAMESPACE__ . '\\Menu_Panel', 'flush' ) );
		}
	}

	/**
	 * The theme mods that are the small labels on a product card: their
	 * own kind of text on the Translations screen, not settings prose.
	 */
	private const LABEL_MODS = array( 'oc_label_new_text', 'oc_label_stock_last', 'oc_label_stock_low', 'oc_label_stock_out', 'oc_label_strip_buy_text', 'oc_label_strip_cart_text' );

	/**
	 * The option theme mods live in: one per stylesheet.
	 */
	private static function mods_option(): string {
		return 'theme_mods_' . get_option( 'stylesheet' );
	}

	/**
	 * The theme mods that hold words, with their labels.
	 *
	 * @return array<string,string>
	 */
	private static function mods(): array {
		return array(
			'oc_related_title'         => __( 'Related products — heading', 'oc-theme' ),
			'oc_upsells_title'         => __( 'Complementary products — heading', 'oc-theme' ),
			'oc_xsell_title'           => __( 'Cross-sells — heading', 'oc-theme' ),
			'oc_label_new_text'        => __( '"New" label', 'oc-theme' ),
			'oc_label_stock_last'      => __( 'Last-one label', 'oc-theme' ),
			'oc_label_stock_low'       => __( 'Last-items label', 'oc-theme' ),
			'oc_label_stock_out'       => __( 'Out-of-stock label', 'oc-theme' ),
			'oc_label_strip_buy_text'  => __( '"In demand" strip', 'oc-theme' ),
			'oc_label_strip_cart_text' => __( '"Great choice" strip', 'oc-theme' ),
			'oc_login_title'           => __( 'Login — title', 'oc-theme' ),
			'oc_login_reg_title'       => __( 'Login — registration line', 'oc-theme' ),
			'oc_login_reg_perks'       => __( 'Login — registration perks', 'oc-theme' ),
			'oc_login_club_text'       => __( 'Login — club pitch', 'oc-theme' ),
			'oc_blog_disclaimer'       => __( 'Blog disclaimer', 'oc-theme' ),
			'oc_atc_icon_text_1'       => __( 'Under Add to cart — first message', 'oc-theme' ),
			'oc_atc_icon_text_2'       => __( 'Under Add to cart — second message', 'oc-theme' ),
			'oc_atc_icon_text_3'       => __( 'Under Add to cart — third message', 'oc-theme' ),
			'oc_atc_icon_text_4'       => __( 'Under Add to cart — fourth message', 'oc-theme' ),
			'oc_topbar_msg1'           => __( 'Announcement bar — line 1', 'oc-theme' ),
			'oc_topbar_msg2'           => __( 'Announcement bar — line 2', 'oc-theme' ),
			'oc_topbar_msg3'           => __( 'Announcement bar — line 3', 'oc-theme' ),
			'oc_footer_tagline'        => __( 'Footer — tagline', 'oc-theme' ),
			'oc_footer_news_h'         => __( 'Footer — newsletter heading', 'oc-theme' ),
			'oc_footer_news_t'         => __( 'Footer — newsletter text', 'oc-theme' ),
			'oc_footer_credit'         => __( 'Footer — credit line', 'oc-theme' ),
			'oc_footer_col1_h'         => __( 'Footer — column 1 heading', 'oc-theme' ),
			'oc_footer_col2_h'         => __( 'Footer — column 2 heading', 'oc-theme' ),
			'oc_footer_col3_h'         => __( 'Footer — column 3 heading', 'oc-theme' ),
			'oc_footer_col4_h'         => __( 'Footer — column 4 heading', 'oc-theme' ),
			'oc_bt_title'              => __( 'Bundle — heading', 'oc-theme' ),
			'oc_contact_title_text'    => __( 'Product contact — title', 'oc-theme' ),
			'oc_contact_name'          => __( 'Product contact — name', 'oc-theme' ),
			'oc_contact_role'          => __( 'Product contact — role', 'oc-theme' ),
			'oc_contact_msg'           => __( 'Product contact — message', 'oc-theme' ),
			'oc_contact_btn'           => __( 'Product contact — button', 'oc-theme' ),
		);
	}

	/**
	 * The cart drawer's words, with their labels.
	 *
	 * @return array<string,string>
	 */
	private static function cart(): array {
		return array(
			'title'      => __( 'Cart — title', 'oc-theme' ),
			'empty_text' => __( 'Cart — empty text', 'oc-theme' ),
			'ship_text'  => __( 'Cart — free-shipping progress', 'oc-theme' ),
			'ship_done'  => __( 'Cart — free-shipping reached', 'oc-theme' ),
			'up_title'   => __( 'Cart — upsells title', 'oc-theme' ),
			'btn_text'   => __( 'Cart — checkout button', 'oc-theme' ),
		);
	}

	/**
	 * The product tabs' words: the two built-in titles and every custom tab
	 * the shop added, by its place in the list.
	 *
	 * @return array<string,string>
	 */
	private static function tabs(): array {
		$out   = array(
			'short_title' => __( 'Short description tab — title', 'oc-theme' ),
			'desc_title'  => __( 'Description tab — title', 'oc-theme' ),
		);
		$saved = get_option( 'oc_tabs' );
		$rows  = is_array( $saved ) && isset( $saved['custom'] ) && is_array( $saved['custom'] ) ? $saved['custom'] : array();
		$n     = 0;

		// A tab is named by its own key, which it keeps for life; the label
		// is its title, because "Custom tab 2" tells a translator nothing.
		foreach ( $rows as $key => $row ) {
			++$n;

			$title = is_array( $row ) ? trim( (string) ( $row['title'] ?? '' ) ) : '';

			if ( '' === $title ) {
				/* translators: %d: the tab's place in the list */
				$title = sprintf( __( 'Custom tab %d', 'oc-theme' ), $n );
			}

			/* translators: %s: the tab's own title */
			$out[ 'custom/' . $key . '/title' ] = sprintf( __( '%s — tab title', 'oc-theme' ), $title );
			/* translators: %s: the tab's own title */
			$out[ 'custom/' . $key . '/content' ] = sprintf( __( '%s — tab content', 'oc-theme' ), $title );
		}

		return $out;
	}

	/**
	 * The typed words of the catalogue filters. A filter group's own title
	 * is not here: the groups are a list the admin reorders, and a
	 * translation tied to a place in a list lands on the wrong group the
	 * day the list moves. An untitled group shows the attribute's name,
	 * which is translated as an attribute.
	 *
	 * @return array<string,string>
	 */
	private static function filters(): array {
		return array(
			'brands_title' => __( 'Brand filter — title', 'oc-theme' ),
		);
	}

	/**
	 * The typed words of the search: the searches pinned under the box,
	 * one per line, each a word shoppers might look for.
	 *
	 * @return array<string,string>
	 */
	private static function search(): array {
		return array(
			'pinned' => __( 'Pinned searches (one per line)', 'oc-theme' ),
		);
	}

	/**
	 * The thank-you page's typed lines.
	 *
	 * @return array<string,string>
	 */
	private static function thankyou(): array {
		return array(
			'content'      => __( 'The service line', 'oc-theme' ),
			'social_title' => __( 'Follow us — title', 'oc-theme' ),
			'survey_q'     => __( 'Survey — question', 'oc-theme' ),
			'survey_sub'   => __( 'Survey — line under it', 'oc-theme' ),
		);
	}

	/**
	 * The checkout's typed lines.
	 *
	 * @return array<string,string>
	 */
	private static function checkout(): array {
		return array(
			'btn_text'     => __( 'Place-order button', 'oc-theme' ),
			'consent_text' => __( 'Marketing consent line', 'oc-theme' ),
			'help_text'    => __( 'Help line in the header', 'oc-theme' ),
		);
	}

	/**
	 * The opening words the theme adds to each customer email's settings.
	 *
	 * @return array<string,string>
	 */
	private static function emails(): array {
		return array(
			'oc_intro'          => __( 'Opening words', 'oc-theme' ),
			'oc_heading_pickup' => __( 'Heading — collection', 'oc-theme' ),
			'oc_intro_pickup'   => __( 'Opening words — collection', 'oc-theme' ),
		);
	}

	/**
	 * The branches are posts too: their names, and what they say about
	 * themselves, are read by shoppers at the checkout and on the map.
	 *
	 * @param string[]|mixed $types Post types the plugin translates.
	 * @return string[]
	 */
	public function post_types( $types ): array {
		$types = is_array( $types ) ? $types : array();

		if ( class_exists( '\\OC\\Blocks\\Branches' ) ) {
			$types[] = \OC\Blocks\Branches::CPT;
		}

		// The catalogue's own blocks: a banner's words are typed on the block.
		if ( class_exists( __NAMESPACE__ . '\\Blocks' ) ) {
			$types[] = Blocks::TYPE;
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * A branch's region is a heading on the branches page.
	 *
	 * @param string[]|mixed $taxonomies Taxonomies the plugin translates.
	 * @return string[]
	 */
	public function taxonomies( $taxonomies ): array {
		$taxonomies = is_array( $taxonomies ) ? $taxonomies : array();

		if ( class_exists( '\\OC\\Blocks\\Branches' ) ) {
			$taxonomies[] = \OC\Blocks\Branches::TAX;
		}

		// The brands: a name typed in Hebrew is read in Hebrew on /en/
		// until someone writes it the way the brand writes it.
		if ( class_exists( '\\OC\\Blocks\\Render' ) && '' !== \OC\Blocks\Render::brand_taxonomy() ) {
			$taxonomies[] = \OC\Blocks\Render::brand_taxonomy();
		}

		return array_values( array_unique( $taxonomies ) );
	}

	/**
	 * A product's own tabs — the ones typed on its edit screen — named row
	 * by row so a translation stays with the tab it was written for; and
	 * a branch's typed details.
	 *
	 * @param array<string,array<string,string>>|mixed $map     Meta key => ( path => label ).
	 * @param int|mixed                                $post_id Post id.
	 * @return array<string,array<string,string>>
	 */
	public function post_meta( $map, $post_id ): array {
		$map  = is_array( $map ) ? $map : array();
		$type = get_post_type( (int) $post_id );

		if ( class_exists( '\\OC\\Blocks\\Branches' ) && \OC\Blocks\Branches::CPT === $type ) {
			$map['_oc_br_pickup_name'] = array( '' => __( 'Name at the checkout', 'oc-theme' ) );
			$map['_oc_br_address']     = array( '' => __( 'Address', 'oc-theme' ) );
			$map['_oc_br_city']        = array( '' => __( 'City', 'oc-theme' ) );
			$map['_oc_br_hours']       = array( '' => __( 'Opening hours', 'oc-theme' ) );

			return $map;
		}

		if ( 'nav_menu_item' === $type ) {
			$map['_oc_badge'] = array( '' => __( 'Menu badge', 'oc-theme' ) );

			$panel = self::panel_fields( (int) $post_id );

			if ( ! empty( $panel ) ) {
				$map[ Menu_Panel::META ] = $panel;
			}

			return $map;
		}

		if ( class_exists( __NAMESPACE__ . '\\Blocks' ) && Blocks::TYPE === $type ) {
			$map['_oc_block_heading']    = array( '' => __( 'Heading', 'oc-theme' ) );
			$map['_oc_block_cta']        = array( '' => __( 'Button text', 'oc-theme' ) );
			$map['_oc_block_alt']        = array( '' => __( 'Image description (alt)', 'oc-theme' ) );
			$map['_oc_block_ps_heading'] = array( '' => __( 'Product slider — heading', 'oc-theme' ) );

			return $map;
		}

		if ( 'product' !== $type ) {
			return $map;
		}

		$rows   = get_post_meta( (int) $post_id, '_oc_product_tabs', true );
		$fields = array();
		$n      = 0;

		foreach ( is_array( $rows ) ? $rows : array() as $key => $row ) {
			++$n;

			if ( ! is_array( $row ) ) {
				continue;
			}

			$title = trim( (string) ( $row['title'] ?? '' ) );

			if ( '' === $title ) {
				/* translators: %d: the tab's place in the list */
				$title = sprintf( __( 'Custom tab %d', 'oc-theme' ), $n );
			}

			/* translators: %s: the tab's own title */
			$fields[ $key . '/title' ] = sprintf( __( '%s — tab title', 'oc-theme' ), $title );
			/* translators: %s: the tab's own title */
			$fields[ $key . '/content' ] = sprintf( __( '%s — tab content', 'oc-theme' ), $title );
		}

		if ( ! empty( $fields ) ) {
			$map['_oc_product_tabs'] = $fields;
		}

		return $map;
	}

	/**
	 * Where a menu item keeps typed words of its own, so the plugin lists
	 * the items that carry any: the badge, the panel's blocks.
	 *
	 * @param string[]|mixed $keys Meta keys.
	 * @return string[]
	 */
	public function menu_item_meta( $keys ): array {
		$keys   = is_array( $keys ) ? $keys : array();
		$keys[] = '_oc_badge';

		if ( class_exists( __NAMESPACE__ . '\\Menu_Panel' ) ) {
			$keys[] = Menu_Panel::META;
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * The typed words of an item's mega-menu panel, block by block: each
	 * block is found by its name (`#<uid>`) wherever it was dragged to.
	 *
	 * @param int $item_id Menu item id.
	 * @return array<string,string> Path => label.
	 */
	private static function panel_fields( int $item_id ): array {
		if ( ! class_exists( __NAMESPACE__ . '\\Menu_Panel' ) ) {
			return array();
		}

		$types  = Menu_Panel::types();
		$fields = array();
		$n      = 0;

		foreach ( Menu_Panel::blocks( $item_id ) as $block ) {
			++$n;

			$type = (string) ( $block['type'] ?? '' );

			if ( empty( $block['uid'] ) || ! isset( $types[ $type ] ) ) {
				continue;
			}

			foreach ( (array) ( $types[ $type ]['fields'] ?? array() ) as $key => $field ) {
				if ( 'text' !== (string) ( $field['type'] ?? '' ) ) {
					continue;
				}

				/* translators: 1: the block's place in the panel, 2: the kind of block, 3: the field */
				$fields[ '#' . $block['uid'] . '/' . $key ] = sprintf( __( 'Panel block %1$d (%2$s) — %3$s', 'oc-theme' ), $n, (string) ( $types[ $type ]['label'] ?? $type ), (string) ( $field['label'] ?? $key ) );
			}
		}

		return $fields;
	}

	/**
	 * The theme's settings, added to the plugin's manifest.
	 *
	 * @param array<string,string[]>|mixed $out Option name => the keys that hold text.
	 * @return array<string,string[]>
	 */
	public function options( $out ): array {
		$out = is_array( $out ) ? $out : array();

		$out[ self::mods_option() ] = array_keys( self::mods() );
		$out['oc_tabs']             = array_keys( self::tabs() );
		$out['oc_cart']             = array_keys( self::cart() );
		$out['oc_thankyou']         = array_keys( self::thankyou() );
		$out['oc_checkout']         = array_keys( self::checkout() );
		$out['oc_filters']          = array_keys( self::filters() );
		$out['oc_search']           = array_keys( self::search() );

		foreach ( self::EMAILS as $id ) {
			$name         = 'woocommerce_' . $id . '_settings';
			$out[ $name ] = array_values( array_unique( array_merge( (array) ( $out[ $name ] ?? array() ), array_keys( self::emails() ) ) ) );
		}

		return $out;
	}

	/**
	 * Where the theme's own settings belong on the Translations screen.
	 * The labels on a product card are their own kind; the rest of the
	 * theme's words are the site's texts.
	 *
	 * @param array<string,string|array<string,string>>|mixed $groups Option name => slug, or key => slug.
	 * @return array<string,string|array<string,string>>
	 */
	public function groups( $groups ): array {
		$groups = is_array( $groups ) ? $groups : array();
		$mods   = array( '' => 'theme' );

		foreach ( self::LABEL_MODS as $key ) {
			$mods[ $key ] = 'labels';
		}

		$groups[ self::mods_option() ] = $mods;
		$groups['oc_tabs']             = 'theme';
		$groups['oc_cart']             = 'theme';
		$groups['oc_thankyou']         = 'theme';
		$groups['oc_checkout']         = 'theme';
		$groups['oc_filters']          = 'theme';
		$groups['oc_search']           = 'theme';

		return $groups;
	}

	/**
	 * Labels for the Translations table.
	 *
	 * @param array<string,array<string,string>>|mixed $labels Option name => ( '' => the option's label, key => the field's ).
	 * @return array<string,array<string,string>>
	 */
	public function labels( $labels ): array {
		$labels = is_array( $labels ) ? $labels : array();

		$labels[ self::mods_option() ] = array( '' => __( 'Theme settings', 'oc-theme' ) ) + self::mods();
		$labels['oc_tabs']             = array( '' => __( 'Product tabs', 'oc-theme' ) ) + self::tabs();
		$labels['oc_cart']             = array( '' => __( 'Cart drawer', 'oc-theme' ) ) + self::cart();
		$labels['oc_thankyou']         = array( '' => __( 'Thank-you page', 'oc-theme' ) ) + self::thankyou();
		$labels['oc_checkout']         = array( '' => __( 'Checkout', 'oc-theme' ) ) + self::checkout();
		$labels['oc_filters']          = array( '' => __( 'Catalogue filters', 'oc-theme' ) ) + self::filters();
		$labels['oc_search']           = array( '' => __( 'Search', 'oc-theme' ) ) + self::search();

		foreach ( self::EMAILS as $id ) {
			$name            = 'woocommerce_' . $id . '_settings';
			$labels[ $name ] = (array) ( $labels[ $name ] ?? array() ) + self::emails();
		}

		return $labels;
	}
}
