<?php
/**
 * Variation display types and linked colour products.
 *
 * The previous theme proved the model — display type per attribute, swatch
 * colour/image per term — and also proved what to avoid: its 1,600-line
 * ajax gallery-swap. Here a colour click NAVIGATES to the sibling product
 * (own URL, own schema, own analytics), and the variation UI drives Woo's
 * own hidden selects, so stock, prices and images keep working untouched.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Attribute display types, term swatches, linked colour products.
 */
final class Variations {

	/**
	 * Where the attribute guard records that it fired.
	 */
	private const GUARD_LOG = 'oc_save_guard';

	/**
	 * Registered instance, for the sticky bar's colour-sibling row.
	 *
	 * @var Variations|null
	 */
	private static $me = null;

	/**
	 * Colour-sibling row for the sticky add-to-cart bar, with the same label
	 * resolution as the summary row. Empty 'row' when the product has no
	 * linked colour products.
	 *
	 * @param \WC_Product $product The product shown.
	 * @return array{row: string, label: string}
	 */
	public static function sticky_colors( \WC_Product $product ): array {
		$out = array(
			'row'   => '',
			'label' => __( 'Colours', 'oc-theme' ),
		);

		if ( ! self::$me instanceof self ) {
			return $out;
		}

		$out['row'] = self::$me->colors_row( $product->get_id(), 'oc-colors--sticky' );

		if ( '' === $out['row'] ) {
			return $out;
		}

		$attr = self::$me->swatch_attr( $product );

		if ( null !== $attr ) {
			$out['label'] = $attr['label'];
		}

		return $out;
	}

	/**
	 * Colour-sibling row for the quick-pick panel — the same circles as the
	 * page, each carrying its product id so a click can re-dress the panel.
	 *
	 * @param \WC_Product $product The product shown.
	 * @return array{row: string, label: string}
	 */
	public static function panel_colors( \WC_Product $product ): array {
		$out = self::sticky_colors( $product );

		if ( self::$me instanceof self && '' !== $out['row'] ) {
			$out['row'] = self::$me->colors_row( $product->get_id(), 'oc-colors--vp', true );
		}

		return $out;
	}

	/**
	 * The display type of an attribute, for whoever draws options outside
	 * the product form — the quick-pick panel, say.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 */
	public static function display_type( string $taxonomy ): string {
		return self::$me instanceof self ? self::$me->attr_type( rawurldecode( $taxonomy ) ) : 'select';
	}

	/**
	 * A term's swatch style through the product page's own chain, so the
	 * same colour never draws two different circles.
	 *
	 * @param \WC_Product $product  Product.
	 * @param string      $taxonomy Attribute taxonomy.
	 * @param \WP_Term    $term     Term.
	 */
	public static function swatch_css( \WC_Product $product, string $taxonomy, \WP_Term $term ): string {
		if ( ! self::$me instanceof self ) {
			return '';
		}

		$type = self::$me->attr_type( rawurldecode( $taxonomy ) );

		if ( ! in_array( $type, array( 'swatch', 'swatch_image' ), true ) ) {
			return '';
		}

		return self::$me->swatch_style( $product, $taxonomy, $term, $type );
	}

	/**
	 * One attribute as the quick-pick panel draws it: the display type and,
	 * per value the select can hold, its label and swatch — a local
	 * attribute answering exactly like a taxonomy one.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $attr    Attribute name, as get_variation_attributes() keys it.
	 * @return array{type:string, options:array<string, array{label:string, swatch:string}>}
	 */
	public static function panel_attr( \WC_Product $product, string $attr ): array {
		$out = array(
			'type'    => 'select',
			'options' => array(),
		);

		if ( ! self::$me instanceof self ) {
			return $out;
		}

		$a = self::$me->product_attrs( $product )[ sanitize_title( $attr ) ] ?? null;

		if ( null === $a ) {
			return $out;
		}

		$out['type'] = $a['type'];
		$is_swatch   = in_array( $a['type'], array( 'swatch', 'swatch_image' ), true );

		foreach ( $a['values'] as $val ) {
			$out['options'][ $val['value'] ] = array(
				'label'  => $val['name'],
				'swatch' => $is_swatch ? self::$me->value_style( $product, $a, $val, $a['type'] ) : '',
			);
		}

		return $out;
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		self::$me = $this;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// "Buttons" and "Swatch" join Woo's own attribute-type dropdown.
		add_filter( 'product_attributes_type_selector', array( $this, 'attribute_types' ) );

		// WooCommerce renders the term picker on the product's attribute row
		// ONLY for the built-in 'select' type; custom types must draw their
		// own via this hook. Without it the row has no values control, every
		// save posts the attribute empty, and Woo strips it — which is
		// exactly the vanishing-attributes bug this restores.
		add_action( 'woocommerce_product_option_terms', array( $this, 'option_terms' ), 10, 3 );

		// Swatch attributes get colour/image fields on their term screens.
		add_action( 'admin_init', array( $this, 'term_field_hooks' ) );
		add_action( 'created_term', array( $this, 'save_term_swatch' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'save_term_swatch' ), 10, 3 );

		// The rendered select stays as Woo's source of truth; buttons and
		// swatches are appended beside it and drive it.
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( $this, 'variation_ui' ), 20, 2 );

		// Linked colour products: one field, synced in both directions.
		add_action( 'woocommerce_product_options_related', array( $this, 'links_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_links' ) );

		// A save whose form lost the attribute fields would silently strip a
		// variable product of its attributes (and orphan every variation).
		// Restore them from the database instead, and log the event.
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'guard_attributes' ), 5 );

		// Per-colour galleries on regular variable products: pick a colour,
		// attach its images; a swatch click swaps the gallery immediately.
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'galleries_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'galleries_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_galleries' ) );
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'galleries_json' ) );

		// Colour siblings sit in the variations area, like every attribute row.
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'product_colors' ) );

		// Both positions hook in; the choice is read at render time, so the
		// customizer preview reflects it immediately — a hook picked when the
		// theme loads would only see the saved value.
		//
		// "Above" renders INSIDE the text box (right after it opens, before
		// the title) rather than as the card's own grid row: a shared subgrid
		// track made every card in the row reserve swatch height when one
		// neighbour had swatches. Inside the box each card packs itself —
		// the same decision the reviews row got.
		// Inside the card's head, after the brand (see WooCommerce::card_head_open).
		add_action( 'woocommerce_shop_loop_item_title', array( $this, 'loop_colors_above' ), -1 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'loop_colors_below' ), 30 );

		// The card's default colour travels: every card link carries the
		// first colour's attribute, so the product page opens with the same
		// swatch selected the visitor was just looking at.
		add_filter( 'woocommerce_loop_product_link', array( $this, 'carry_default_color' ), 10, 2 );

		// And a product entered with nothing chosen still opens on its first
		// variation — the same default the catalogue card shows.
		add_filter( 'woocommerce_product_get_default_attributes', array( $this, 'first_variation_default' ), 10, 2 );

		// A colour with its own gallery lends its first picture to the
		// variation data, so Woo's own image update agrees with the gallery
		// instead of putting the product's main photo back over it.
		add_filter( 'woocommerce_available_variation', array( $this, 'variation_gallery_image' ), 20, 3 );

		// The page opens straight on the colour it was entered with — the
		// card's swatch, the address, the default — with that colour's gallery
		// in the markup itself. Before, the product's own gallery showed
		// first and the script swapped the colour's in: a visible jump.
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'open_gallery_on' ), 19 );
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'open_gallery_off' ), 21 );

		// Products whose main photo belongs to no colour gallery, listed.
		add_action( 'admin_menu', array( $this, 'report_menu' ), 60 );
	}

	/**
	 * The catalogue card's link, carrying the default colour.
	 *
	 * @param string $link    The permalink.
	 * @param mixed  $product The product.
	 * @return string
	 */
	public function carry_default_color( $link, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $link;
		}

		$d = $this->default_term( $product );

		return null === $d ? $link : add_query_arg( 'attribute_' . rawurlencode( $d['key'] ), $d['value'], (string) $link );
	}

	/**
	 * A variable product with no chosen defaults opens on a real variation
	 * in the colour the catalogue card pre-marks — the colour whose gallery
	 * holds the product's main photo, else the first. A default the shop
	 * owner set per product always wins, and a product marked "open with
	 * nothing chosen" gets none.
	 *
	 * @param array $defaults Saved defaults.
	 * @param mixed $product  The product.
	 * @return array
	 */
	public function first_variation_default( $defaults, $product ) {
		if ( ! empty( $defaults ) || is_admin() || ! $product instanceof \WC_Product_Variable ) {
			return $defaults;
		}

		// This filter fires on every property read — remember the answer.
		static $cache = array();

		$id = $product->get_id();

		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}

		$cache[ $id ] = array();

		if ( self::no_default( $id ) ) {
			return $cache[ $id ];
		}

		$lead = $this->default_term( $product ) ?? $this->gallery_lead( $product );
		$pick = array();

		// A main photo that belongs to none of the colour galleries: the
		// page shows that photo and marks no colour (see default_term()).
		if ( null === $lead && self::galleries_have_pictures( $this->galleries_meta( $id ) ) ) {
			return $cache[ $id ];
		}

		// A combination that exists: the first variation in the lead colour
		// (or open to any colour), else simply the first variation.
		foreach ( $product->get_visible_children() as $index => $child_id ) {
			$attrs = wc_get_product_variation_attributes( (int) $child_id );

			if ( 0 === $index ) {
				$pick = $attrs;
			}

			if ( null === $lead ) {
				break;
			}

			$have = (string) ( $attrs[ 'attribute_' . $lead['key'] ] ?? '' );

			if ( '' === $have || $have === $lead['value'] ) {
				$pick = $attrs;
				break;
			}
		}

		foreach ( $product->get_variation_attributes() as $name => $options ) {
			$key   = sanitize_title( (string) $name );
			$value = (string) ( $pick[ 'attribute_' . $key ] ?? '' );

			if ( '' === $value && null !== $lead && $lead['key'] === $key ) {
				$value = $lead['value'];
			}

			if ( '' === $value && ! empty( $options ) ) {
				$value = (string) reset( $options );
			}

			if ( '' !== $value ) {
				$cache[ $id ][ $key ] = $value;
			}
		}

		return $cache[ $id ];
	}

	/**
	 * The card's default colour: the first term the swatch row would draw.
	 * Null for simple products, colour-sibling products, and products whose
	 * colours are not a swatch attribute.
	 *
	 * @param \WC_Product $product The product.
	 * @return array{key:string,value:string}|null
	 */
	private function default_term( \WC_Product $product ): ?array {
		static $cache = array();

		$id = $product->get_id();

		if ( array_key_exists( $id, $cache ) ) {
			return $cache[ $id ];
		}

		$cache[ $id ] = null;

		if ( ! $product->is_type( 'variable' ) ) {
			return null;
		}

		// Colour-sibling products carry their colour in the product itself.
		if ( ! empty( array_filter( array_map( 'absint', (array) get_post_meta( $id, '_oc_color_links', true ) ) ) ) ) {
			return null;
		}

		if ( self::no_default( $id ) ) {
			return null;
		}

		$attr = $this->swatch_attr( $product );

		if ( null === $attr ) {
			return null;
		}

		$values = $this->sold_values( $product, $attr );

		if ( count( $values ) < 2 ) {
			return null;
		}

		// The colour the product's main photo belongs to leads: the card
		// shows that photo, so it must mark that colour, and the product
		// page opens on it. Otherwise the first colour that can be drawn.
		$main      = (int) $product->get_image_id();
		$galleries = $this->galleries_meta( $id );
		$lead      = null;
		$owned     = false;

		foreach ( $values as $val ) {
			if ( '' === $this->value_style( $product, $attr, $val, $attr['type'] ) ) {
				continue;
			}

			if ( null === $lead ) {
				$lead = $val;
			}

			if ( $main && in_array( $main, array_map( 'intval', $galleries[ $val['slug'] ]['imgs'] ?? array() ), true ) ) {
				$lead  = $val;
				$owned = true;
				break;
			}
		}

		// The card shows the main photo. When colour galleries exist and
		// none of them holds that photo, marking a colour beside it says
		// "this is what you see" about a colour it is not — the black bin
		// with a grey dot. No mark, then; the product page opens with
		// nothing chosen, and the photo stays the product's own.
		if ( null === $lead || ( ! $owned && self::galleries_have_pictures( $galleries ) ) ) {
			return null;
		}

		$cache[ $id ] = array(
			'key'   => $attr['key'],
			'value' => $lead['value'],
		);

		return $cache[ $id ];
	}

	/**
	 * The values of an attribute that a published variation is sold in.
	 *
	 * A value left attached to the product with no variation behind it would
	 * show on the card and then be nowhere on the product page. Woo answers
	 * from the published variations, and folds an "any" into every value.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $attr    From product_attrs().
	 * @return array<int,array{value:string,slug:string,name:string,term:?\WP_Term}>
	 */
	private function sold_values( \WC_Product $product, array $attr ): array {
		if ( ! $product instanceof \WC_Product_Variable ) {
			return $attr['values'];
		}

		$used = array_map(
			static function ( $v ) {
				return rawurldecode( (string) $v );
			},
			(array) ( $product->get_variation_attributes()[ $attr['name'] ] ?? array() )
		);

		if ( empty( $used ) ) {
			return $attr['values'];
		}

		return array_values(
			array_filter(
				$attr['values'],
				static function ( $val ) use ( $used ) {
					return in_array( rawurldecode( (string) $val['value'] ), $used, true ) || in_array( rawurldecode( (string) $val['slug'] ), $used, true );
				}
			)
		);
	}

	/**
	 * The value whose colour gallery holds the product's main photo, on any
	 * attribute with galleries — so a colour drawn as a dropdown or buttons
	 * opens on the photo's colour too, not only a swatch row.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{key:string,value:string}|null
	 */
	private function gallery_lead( \WC_Product $product ): ?array {
		$main      = (int) $product->get_image_id();
		$galleries = $this->galleries_meta( $product->get_id() );

		if ( ! $main || empty( $galleries ) || self::no_default( $product->get_id() ) ) {
			return null;
		}

		foreach ( $this->product_attrs( $product ) as $attr ) {
			foreach ( $this->sold_values( $product, $attr ) as $val ) {
				if ( in_array( $main, array_map( 'intval', $galleries[ $val['slug'] ]['imgs'] ?? array() ), true ) ) {
					return array(
						'key'   => $attr['key'],
						'value' => $val['value'],
					);
				}
			}
		}

		return null;
	}

	/**
	 * Whether the shop asked this product to open with nothing chosen.
	 *
	 * @param int $product_id Product id.
	 */
	public static function no_default( int $product_id ): bool {
		return 'yes' === get_post_meta( $product_id, '_oc_no_default', true );
	}

	/**
	 * The catalogue cap: at most N swatches, the rest folded into a +N chip
	 * that leads to the product. The current colour never disappears — when
	 * it falls past the cap it takes the last visible slot.
	 *
	 * @param array<int,string> $anchors    The built swatch anchors.
	 * @param int               $at_current Index of the current item, -1 when none.
	 * @param string            $permalink  Where +N leads.
	 * @return array<int,string>
	 */
	private static function capped( array $anchors, int $at_current, string $permalink ): array {
		$count = count( $anchors );
		$desk  = absint( get_theme_mod( 'oc_swatch_loop_max', 0 ) );
		$phone = absint( get_theme_mod( 'oc_swatch_loop_max_m', 0 ) );

		// 0 on the desktop means every colour; 0 on the phone means "as on
		// the desktop".
		$desk  = $desk > 0 ? $desk : $count;
		$phone = $phone > 0 ? $phone : $desk;

		if ( $count <= $desk && $count <= $phone ) {
			return $anchors;
		}

		// The current colour never disappears: past the smaller cap it swaps
		// into that cap's last slot, so every screen still shows it.
		$least = min( $desk, $phone );

		if ( $at_current >= $least && isset( $anchors[ $at_current ] ) ) {
			$moved                  = $anchors[ $least - 1 ];
			$anchors[ $least - 1 ]  = $anchors[ $at_current ];
			$anchors[ $at_current ] = $moved;
		}

		$out = array();

		foreach ( $anchors as $i => $html ) {
			if ( $i >= max( $desk, $phone ) ) {
				break;
			}

			// Past one screen's cap but inside the other's: drawn, and hidden
			// on the screen that has no room for it.
			$hide = trim( ( $i >= $desk ? 'oc-sw-hide-d ' : '' ) . ( $i >= $phone ? 'oc-sw-hide-m' : '' ) );

			$out[] = '' === $hide ? $html : (string) preg_replace( '/class="/', 'class="' . $hide . ' ', $html, 1 );
		}

		$more = static function ( int $extra, string $only ) use ( $permalink ): string {
			return '<a class="oc-colors__more' . ( '' !== $only ? ' ' . $only : '' ) . '" href="' . esc_url( $permalink ) . '">+' . $extra . '</a>';
		};

		if ( $desk === $phone ) {
			$out[] = $more( $count - $desk, '' );
		} else {
			if ( $count > $desk ) {
				$out[] = $more( $count - $desk, 'oc-sw-only-d' );
			}

			if ( $count > $phone ) {
				$out[] = $more( $count - $phone, 'oc-sw-only-m' );
			}
		}

		return $out;
	}

	/**
	 * Card swatches above the title, when so configured.
	 */
	public function loop_colors_above(): void {
		if ( 'above' === get_theme_mod( 'oc_colors_loop_pos', 'below' ) ) {
			$this->loop_colors();
		}
	}

	/**
	 * Card swatches below the content, when so configured.
	 */
	public function loop_colors_below(): void {
		if ( 'above' !== get_theme_mod( 'oc_colors_loop_pos', 'below' ) ) {
			$this->loop_colors();
		}
	}

	/**
	 * Extra attribute display types.
	 *
	 * @param array $types Type map.
	 * @return array
	 */
	public function attribute_types( array $types ): array {
		$types['button']       = __( 'Buttons', 'oc-theme' );
		$types['swatch']       = __( 'Swatch — colour', 'oc-theme' );
		$types['swatch_image'] = __( 'Swatch — image', 'oc-theme' );

		return $types;
	}

	/**
	 * Display type for an attribute taxonomy, e.g. pa_color.
	 *
	 * The type doubles as the default look: every term stores both a colour
	 * and an image, and the attribute type decides which one leads. Stored in
	 * Woo's own attribute_type column — no extra table, no extra query.
	 *
	 * @param string $taxonomy Attribute taxonomy name.
	 * @return string select|button|swatch|swatch_image
	 */
	private function attr_type( string $taxonomy ): string {
		// Product-attribute keys arrive percent-encoded for Hebrew taxonomies.
		$taxonomy = rawurldecode( $taxonomy );

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( 'pa_' . $attribute->attribute_name === $taxonomy ) {
				return in_array( $attribute->attribute_type, array( 'button', 'swatch', 'swatch_image' ), true )
					? $attribute->attribute_type
					: 'select';
			}
		}

		return 'select';
	}

	/**
	 * The global attribute that stands in for a local one of the same name.
	 *
	 * An imported catalogue carries its colours as per-product attributes —
	 * "צבע: לבן, שחור" typed into the product, no taxonomy behind them. The
	 * shop owner still sets up a global "צבע" attribute as a swatch and
	 * gives its values colours; that is the twin, and the local attribute
	 * borrows its display type and, value by value, its swatches.
	 *
	 * @param string $name Local attribute name, as typed.
	 * @return string Taxonomy, or '' when no global attribute matches.
	 */
	private function twin_taxonomy( string $name ): string {
		static $twins = array();

		$want = mb_strtolower( trim( $name ) );

		if ( '' === $want ) {
			return '';
		}

		if ( ! array_key_exists( $want, $twins ) ) {
			$twins[ $want ] = '';

			foreach ( wc_get_attribute_taxonomies() as $attribute ) {
				if ( mb_strtolower( trim( (string) $attribute->attribute_label ) ) === $want
					|| sanitize_title( $name ) === (string) $attribute->attribute_name ) {
					$twins[ $want ] = 'pa_' . $attribute->attribute_name;
					break;
				}
			}
		}

		return $twins[ $want ];
	}

	/**
	 * The global term standing in for a local value: same name, or same slug.
	 *
	 * @param string $taxonomy Twin taxonomy.
	 * @param string $value    Local value, as typed.
	 */
	private function twin_term( string $taxonomy, string $value ): ?\WP_Term {
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$term = get_term_by( 'name', $value, $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		}

		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * Per-product display overrides, attribute key => type.
	 *
	 * @param int $product_id Product.
	 * @return array<string,string>
	 */
	private function type_overrides( int $product_id ): array {
		$raw = get_post_meta( $product_id, '_oc_attr_types', true );
		$out = array();

		foreach ( ( is_array( $raw ) ? $raw : array() ) as $key => $type ) {
			if ( in_array( (string) $type, array( 'select', 'button', 'swatch', 'swatch_image' ), true ) ) {
				$out[ (string) $key ] = (string) $type;
			}
		}

		return $out;
	}

	/**
	 * What an attribute shows with no per-product override: a taxonomy
	 * answers for itself; a local attribute borrows from its twin.
	 *
	 * @param \WC_Product_Attribute $attribute The attribute.
	 */
	private function inherited_type( \WC_Product_Attribute $attribute ): string {
		if ( $attribute->is_taxonomy() ) {
			return $this->attr_type( (string) $attribute->get_name() );
		}

		$twin = $this->twin_taxonomy( (string) $attribute->get_name() );

		return '' === $twin ? 'select' : $this->attr_type( $twin );
	}

	/**
	 * The product's variation attributes, taxonomy and local alike, in one
	 * shape — so every swatch, gallery and card row reads the same thing.
	 *
	 * Keyed by the sanitize_title form of the attribute name, which is what
	 * Woo uses for the select (attribute_<key>) and the variation meta.
	 * Each value carries `value` — what the select holds: a term slug for a
	 * taxonomy, the typed text for a local attribute — and `slug`, the
	 * sanitize_title form the galleries meta is keyed by.
	 *
	 * @param \WC_Product $product The product.
	 * @return array<string, array{key:string,name:string,label:string,tax:string,twin:string,type:string,inherited:string,values:array<int,array{value:string,slug:string,name:string,term:?\WP_Term}>}>
	 */
	private function product_attrs( \WC_Product $product ): array {
		static $cache = array();

		$id = $product->get_id();

		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}

		$overrides = $this->type_overrides( $id );
		$out       = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}

			$name      = (string) $attribute->get_name();
			$key       = sanitize_title( $name );
			$tax       = $attribute->is_taxonomy() ? $name : '';
			$twin      = '' === $tax ? $this->twin_taxonomy( $name ) : '';
			$inherited = $this->inherited_type( $attribute );
			$values    = array();

			if ( '' !== $tax ) {
				foreach ( (array) wc_get_product_terms( $id, $tax, array( 'fields' => 'all' ) ) as $term ) {
					if ( $term instanceof \WP_Term ) {
						$values[] = array(
							'value' => $term->slug,
							'slug'  => $term->slug,
							'name'  => $term->name,
							'term'  => $term,
						);
					}
				}
			} else {
				foreach ( (array) $attribute->get_options() as $option ) {
					$option = trim( (string) $option );

					if ( '' === $option ) {
						continue;
					}

					$values[] = array(
						'value' => $option,
						'slug'  => sanitize_title( $option ),
						'name'  => $option,
						'term'  => $this->twin_term( $twin, $option ),
					);
				}
			}

			$out[ $key ] = array(
				'key'       => $key,
				'name'      => $name,
				'label'     => wc_attribute_label( $name, $product ),
				'tax'       => $tax,
				'twin'      => $twin,
				'type'      => $overrides[ $key ] ?? $inherited,
				'inherited' => $inherited,
				'values'    => $values,
			);
		}

		$cache[ $id ] = $out;

		return $out;
	}

	/**
	 * The first attribute drawn as swatches — the product's colour.
	 *
	 * @param \WC_Product $product The product.
	 * @return array|null
	 */
	private function swatch_attr( \WC_Product $product ): ?array {
		foreach ( $this->product_attrs( $product ) as $attr ) {
			if ( in_array( $attr['type'], array( 'swatch', 'swatch_image' ), true ) ) {
				return $attr;
			}
		}

		return null;
	}

	/**
	 * Attach the swatch fields to every swatch-type attribute taxonomy.
	 */
	public function term_field_hooks(): void {
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( ! in_array( $attribute->attribute_type, array( 'swatch', 'swatch_image' ), true ) ) {
				continue;
			}

			$taxonomy = 'pa_' . $attribute->attribute_name;
			add_action( $taxonomy . '_add_form_fields', array( $this, 'term_fields_add' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'term_fields_edit' ) );

			// A swatch preview column in the terms table — see every value at
			// a glance without opening it.
			add_filter( 'manage_edit-' . $taxonomy . '_columns', array( $this, 'term_column' ) );
			add_filter( 'manage_' . $taxonomy . '_custom_column', array( $this, 'term_column_value' ), 10, 3 );
		}
	}

	/**
	 * Swatch column header, right after the checkbox.
	 *
	 * @param array $columns Terms table columns.
	 * @return array
	 */
	public function term_column( array $columns ): array {
		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'cb' === $key ) {
				$out['oc_swatch'] = __( 'Swatch', 'oc-theme' );
			}
		}

		return $out;
	}

	/**
	 * Swatch column value: the term's circle, colour or image.
	 *
	 * @param string $output  Column output.
	 * @param string $column  Column key.
	 * @param int    $term_id Term id.
	 * @return string
	 */
	public function term_column_value( $output, $column, $term_id ): string {
		if ( 'oc_swatch' !== $column ) {
			return (string) $output;
		}

		$image = (string) get_term_meta( $term_id, 'oc_swatch_image', true );
		$color = (string) get_term_meta( $term_id, 'oc_swatch_color', true );
		$style = 'display:inline-block;inline-size:24px;block-size:24px;border-radius:50%;border:1px solid #ccd0d4;vertical-align:middle;';

		if ( '' !== $image ) {
			$style .= 'background-image:url(' . esc_url( $image ) . ');background-size:cover;';
		} else {
			$style .= 'background-color:' . ( '' !== $color ? $color : '#f0f0f1' ) . ';';
		}

		return sprintf( '<span style="%s"></span>', esc_attr( $style ) );
	}

	/**
	 * Swatch fields on the "add term" form.
	 */
	public function term_fields_add(): void {
		?>
		<div class="form-field">
			<label for="oc_swatch_color"><?php esc_html_e( 'Swatch colour', 'oc-theme' ); ?></label>
			<input type="color" id="oc_swatch_color" name="oc_swatch_color" value="#cccccc" />
		</div>
		<div class="form-field">
			<label for="oc_swatch_image"><?php esc_html_e( 'Swatch image', 'oc-theme' ); ?></label>
			<?php $this->image_picker( '' ); ?>
			<p><?php esc_html_e( 'The attribute type decides which one leads; the other is the fallback.', 'oc-theme' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Swatch fields on the "edit term" form.
	 *
	 * @param \WP_Term $term Term being edited.
	 */
	public function term_fields_edit( \WP_Term $term ): void {
		$color = (string) get_term_meta( $term->term_id, 'oc_swatch_color', true );
		$image = (string) get_term_meta( $term->term_id, 'oc_swatch_image', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="oc_swatch_color"><?php esc_html_e( 'Swatch colour', 'oc-theme' ); ?></label></th>
			<td><input type="color" id="oc_swatch_color" name="oc_swatch_color" value="<?php echo esc_attr( '' !== $color ? $color : '#cccccc' ); ?>" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="oc_swatch_image"><?php esc_html_e( 'Swatch image', 'oc-theme' ); ?></label></th>
			<td>
				<?php $this->image_picker( $image ); ?>
				<p class="description"><?php esc_html_e( 'The attribute type decides which one leads; the other is the fallback.', 'oc-theme' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Media-library picker for the swatch image: preview, choose, remove.
	 * The value stays a plain URL, so nothing changes for existing terms.
	 *
	 * @param string $image Current image URL.
	 */
	private function image_picker( string $image ): void {
		wp_enqueue_media();
		?>
		<span class="oc-swatch-picker">
			<img class="oc-swatch-picker__preview" src="<?php echo esc_url( $image ); ?>" alt="" style="inline-size:32px;block-size:32px;border-radius:50%;object-fit:cover;vertical-align:middle;border:1px solid #ccd0d4;<?php echo '' === $image ? 'display:none;' : ''; ?>" />
			<input type="hidden" id="oc_swatch_image" name="oc_swatch_image" value="<?php echo esc_url( $image ); ?>" />
			<button type="button" class="button oc-swatch-picker__choose"><?php esc_html_e( 'Choose image', 'oc-theme' ); ?></button>
			<button type="button" class="button oc-swatch-picker__remove" <?php echo '' === $image ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button>
		</span>
		<?php
		$this->picker_script();
	}

	/**
	 * One shared script for every picker on the screen.
	 */
	private function picker_script(): void {
		static $printed = false;

		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		document.addEventListener( 'click', function ( event ) {
			var wrap = event.target.closest( '.oc-swatch-picker' );
			if ( ! wrap || ! window.wp || ! wp.media ) {
				return;
			}
			var input   = wrap.querySelector( 'input' );
			var preview = wrap.querySelector( '.oc-swatch-picker__preview' );
			var remove  = wrap.querySelector( '.oc-swatch-picker__remove' );
			if ( event.target.closest( '.oc-swatch-picker__remove' ) ) {
				input.value = '';
				preview.style.display = 'none';
				remove.style.display = 'none';
				return;
			}
			if ( ! event.target.closest( '.oc-swatch-picker__choose' ) ) {
				return;
			}
			var frame = wp.media( { title: wrap.dataset.title || '', multiple: false, library: { type: 'image' } } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
				input.value = url;
				preview.src = url;
				preview.style.display = '';
				remove.style.display = '';
			} );
			frame.open();
		} );
		</script>
		<?php
	}

	/**
	 * Persist the swatch term meta.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function save_term_swatch( $term_id, $tt_id, $taxonomy ): void {
		unset( $tt_id );

		if ( 0 !== strpos( (string) $taxonomy, 'pa_' ) ) {
			return;
		}

		// Woo's own term screens carry their nonces; these fields ride along.
		if ( isset( $_POST['oc_swatch_color'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_term_meta( $term_id, 'oc_swatch_color', sanitize_hex_color( wp_unslash( $_POST['oc_swatch_color'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( isset( $_POST['oc_swatch_image'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_term_meta( $term_id, 'oc_swatch_image', esc_url_raw( wp_unslash( $_POST['oc_swatch_image'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
	}

	/**
	 * Buttons / swatches rendered beside the hidden select.
	 *
	 * @param string $html Select html.
	 * @param array  $args Dropdown args.
	 * @return string
	 */
	public function variation_ui( $html, $args ): string {
		$product = $args['product'] ?? null;

		if ( ! $product instanceof \WC_Product ) {
			return (string) $html;
		}

		$attr = $this->product_attrs( $product )[ sanitize_title( (string) $args['attribute'] ) ] ?? null;

		if ( null === $attr || 'select' === $attr['type'] ) {
			return (string) $html;
		}

		$type     = $attr['type'];
		$options  = (array) $args['options'];
		$selected = (string) $args['selected'];

		if ( empty( $options ) ) {
			return (string) $html;
		}

		// A product whose colours live as linked sibling products must show one
		// colour UI only: when its own colour attribute holds a single value,
		// auto-select it and hide the row — the "Colours" thumbs take over.
		if ( 1 === count( $options )
			&& ! empty( array_filter( array_map( 'absint', (array) get_post_meta( $product->get_id(), '_oc_color_links', true ) ) ) ) ) {
			return $html . sprintf(
				'<div class="oc-var oc-var--auto" data-for="%s" data-auto="%s"></div>',
				esc_attr( $attr['key'] ),
				esc_attr( (string) reset( $options ) )
			);
		}

		$by_value = array();

		foreach ( $attr['values'] as $val ) {
			$by_value[ $val['value'] ] = $val;
		}

		$items = '';

		foreach ( $options as $option ) {
			$option = (string) $option;
			$val    = $by_value[ $option ] ?? null;

			if ( null === $val ) {
				// An option the attribute does not list any more (a term
				// renamed under a live variation, say): still clickable.
				$term = '' !== $attr['tax'] ? get_term_by( 'slug', $option, $attr['tax'] ) : null;
				$val  = array(
					'value' => $option,
					'slug'  => sanitize_title( $option ),
					'name'  => $term instanceof \WP_Term ? $term->name : $option,
					'term'  => $term instanceof \WP_Term ? $term : null,
				);
			}

			$is_sel = $selected === $option ? ' is-selected' : '';

			if ( in_array( $type, array( 'swatch', 'swatch_image' ), true ) ) {
				$style = $this->value_style( $product, $attr, $val, $type );

				if ( '' === $style ) {
					// Nothing to draw — the value's initial stands in, so an
					// unfilled value is still clickable, never a grey mystery.
					$items .= sprintf(
						'<button type="button" class="oc-var__swatch oc-var__swatch--txt%s" data-value="%s" title="%s" aria-label="%s">%s</button>',
						$is_sel,
						esc_attr( $option ),
						esc_attr( $val['name'] ),
						esc_attr( $val['name'] ),
						esc_html( mb_substr( $val['name'], 0, 1 ) )
					);
				} else {
					$items .= sprintf(
						'<button type="button" class="oc-var__swatch%s" data-value="%s" style="%s" title="%s" aria-label="%s"></button>',
						$is_sel,
						esc_attr( $option ),
						$style,
						esc_attr( $val['name'] ),
						esc_attr( $val['name'] )
					);
				}
			} else {
				$items .= sprintf(
					'<button type="button" class="oc-var__btn%s" data-value="%s">%s</button>',
					$is_sel,
					esc_attr( $option ),
					esc_html( $val['name'] )
				);
			}
		}

		if ( '' === $items ) {
			return (string) $html;
		}

		return $html . sprintf(
			'<div class="oc-var oc-var--%s" data-for="%s">%s</div>',
			esc_attr( $type ),
			esc_attr( $attr['key'] ),
			$items
		);
	}

	/**
	 * Inline style for a swatch button, resolved in confidence order:
	 * per-product override, then the value's term (its own, or its twin's)
	 * leading medium by attribute type, the other medium, and finally the
	 * variation's own image.
	 *
	 * @param mixed  $product Product, when the caller carried one.
	 * @param array  $attr    Attribute, from product_attrs().
	 * @param array  $val     One of its values.
	 * @param string $type    swatch|swatch_image.
	 * @return string Empty when nothing is available.
	 */
	private function value_style( $product, array $attr, array $val, string $type ): string {
		$term  = $val['term'] instanceof \WP_Term ? $val['term'] : null;
		$color = $term ? (string) get_term_meta( $term->term_id, 'oc_swatch_color', true ) : '';
		$image = $term ? (string) get_term_meta( $term->term_id, 'oc_swatch_image', true ) : '';

		if ( $product instanceof \WC_Product ) {
			$galleries = $this->galleries_meta( $product->get_id() );

			// A per-product swatch image is an explicit choice for this very
			// product — it wins over everything, whatever the attribute type.
			$override = (string) ( $galleries[ $val['slug'] ]['swatch'] ?? '' );
			if ( '' !== $override ) {
				return 'background-image:url(' . esc_url( $override ) . ');background-size:cover;';
			}

			// A per-product shade replaces the value's store-wide colour.
			$shade = (string) ( $galleries[ $val['slug'] ]['color'] ?? '' );
			if ( '' !== $shade ) {
				$color = $shade;
			}
		}

		$use_image = 'swatch_image' === $type ? '' !== $image : '' !== $image && '' === $color;

		if ( ! $use_image && '' === $color && '' === $image && $product instanceof \WC_Product ) {
			$image     = $this->variation_image( $product, rawurldecode( $attr['key'] ), $val['value'] );
			$use_image = '' !== $image;
		}

		if ( $use_image || ( '' === $color && '' !== $image ) ) {
			return 'background-image:url(' . esc_url( $image ) . ');background-size:cover;';
		}

		if ( '' !== $color ) {
			return 'background-color:' . esc_attr( $color ) . ';';
		}

		return '';
	}

	/**
	 * The same chain for a taxonomy term, for the callers that hold one.
	 *
	 * @param mixed    $product  Product, when the dropdown args carried one.
	 * @param string   $taxonomy Attribute taxonomy.
	 * @param \WP_Term $term     Term.
	 * @param string   $type     swatch|swatch_image.
	 * @return string Empty when nothing is available.
	 */
	private function swatch_style( $product, string $taxonomy, \WP_Term $term, string $type ): string {
		$attr = array(
			'key' => sanitize_title( $taxonomy ),
			'tax' => $taxonomy,
		);
		$val  = array(
			'value' => $term->slug,
			'slug'  => $term->slug,
			'name'  => $term->name,
			'term'  => $term,
		);

		return $this->value_style( $product, $attr, $val, $type );
	}

	/**
	 * The image of a variation carrying this attribute value — saves the
	 * admin a duplicate upload when the variation photos already exist.
	 * One pass over the children per product, memoised for the request.
	 *
	 * @param \WC_Product $product  Variable product.
	 * @param string      $taxonomy Attribute key, decoded (taxonomy, or a local attribute's name).
	 * @param string      $slug     The value as the variation stores it (term slug, or the typed text).
	 * @param string      $size     Image size to resolve.
	 * @return string Image URL or ''.
	 */
	private function variation_image( \WC_Product $product, string $taxonomy, string $slug, string $size = 'thumbnail' ): string {
		static $maps = array();

		$pid = $product->get_id();

		if ( ! isset( $maps[ $pid ] ) ) {
			$maps[ $pid ] = array();

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$variation = wc_get_product( $child_id );

					if ( ! $variation || ! $variation->get_image_id() ) {
						continue;
					}

					foreach ( $variation->get_attributes() as $attr_tax => $attr_slug ) {
						// Keys arrive percent-encoded for Hebrew taxonomies;
						// the callers ask in decoded form — speak one tongue.
						$key = rawurldecode( (string) $attr_tax ) . ':' . $attr_slug;
						if ( '' !== (string) $attr_slug && ! isset( $maps[ $pid ][ $key ] ) ) {
							$maps[ $pid ][ $key ] = (int) $variation->get_image_id();
						}
					}
				}
			}
		}

		$image_id = (int) ( $maps[ $pid ][ $taxonomy . ':' . $slug ] ?? 0 );

		return $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, $size ) : '';
	}

	/**
	 * Per-product colour galleries meta, normalised.
	 *
	 * @param int $product_id Product id.
	 * @return array slug => { imgs: int[], swatch: string }
	 */
	private function galleries_meta( int $product_id ): array {
		$raw = get_post_meta( $product_id, '_oc_color_galleries', true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $slug => $entry ) {
			$imgs   = array_filter( array_map( 'absint', (array) ( $entry['imgs'] ?? array() ) ) );
			$swatch = (string) ( $entry['swatch'] ?? '' );
			$color  = (string) ( $entry['color'] ?? '' );

			if ( ! empty( $imgs ) || '' !== $swatch || '' !== $color ) {
				$out[ (string) $slug ] = array(
					'imgs'   => array_values( $imgs ),
					'swatch' => $swatch,
					'color'  => $color,
				);
			}
		}

		return $out;
	}

	/**
	 * Linked colour products field, in the Linked Products panel.
	 */
	public function links_field(): void {
		global $post;

		$links = array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, '_oc_color_links', true ) ) );
		?>
		<p class="form-field">
			<label for="oc_color_links"><?php esc_html_e( 'Colour siblings', 'oc-theme' ); ?></label>
			<select class="wc-product-search" multiple="multiple" style="width: 50%;" id="oc_color_links" name="oc_color_links[]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'oc-theme' ); ?>" data-action="woocommerce_json_search_products" data-exclude="<?php echo absint( $post->ID ); ?>">
				<?php foreach ( $links as $link_id ) : ?>
					<?php $link_product = wc_get_product( $link_id ); ?>
					<?php if ( $link_product ) : ?>
						<option value="<?php echo absint( $link_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $link_product->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
			<?php echo wc_help_tip( esc_html__( 'The same product in other colours. Links sync both ways: connect black and grey here and each of them links back automatically.', 'oc-theme' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() returns escaped markup. ?>
		</p>
		<?php
	}

	/**
	 * The term picker for our attribute types on the product's attribute
	 * row — the same multiselect WooCommerce draws for its 'select' type,
	 * so search, "select all" and term creation keep working.
	 *
	 * @param object                     $attribute_taxonomy Attribute taxonomy row.
	 * @param int                        $i                  Row index.
	 * @param \WC_Product_Attribute|null $attribute   Attribute being rendered.
	 */
	public function option_terms( $attribute_taxonomy, $i, $attribute = null ): void {
		if ( ! in_array( $attribute_taxonomy->attribute_type, array( 'button', 'swatch', 'swatch_image' ), true ) ) {
			return;
		}

		$taxonomy = wc_attribute_taxonomy_name( (string) $attribute_taxonomy->attribute_name );
		?>
		<select multiple="multiple" data-minimum_input_length="0" data-limit="50" data-return_id="id"
			data-placeholder="<?php esc_attr_e( 'Select values', 'oc-theme' ); ?>"
			data-orderby="<?php echo esc_attr( $attribute_taxonomy->attribute_orderby ?? 'name' ); ?>"
			class="multiselect attribute_values wc-taxonomy-term-search"
			name="attribute_values[<?php echo esc_attr( (string) $i ); ?>][]"
			data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
			<?php
			$selected_terms = $attribute instanceof \WC_Product_Attribute ? $attribute->get_terms() : array();

			if ( $selected_terms ) {
				foreach ( $selected_terms as $selected_term ) {
					echo '<option value="' . esc_attr( (string) $selected_term->term_id ) . '" selected="selected">'
						. esc_html( apply_filters( 'woocommerce_product_attribute_term_name', $selected_term->name, $selected_term ) )
						. '</option>';
				}
			}
			?>
		</select>
		<button class="button plus select_all_attributes"><?php esc_html_e( 'Select all', 'oc-theme' ); ?></button>
		<button class="button minus select_no_attributes"><?php esc_html_e( 'Select none', 'oc-theme' ); ?></button>
		<button class="button fr plus add_new_attribute"><?php esc_html_e( 'Create value', 'oc-theme' ); ?></button>
		<?php
	}

	/**
	 * Keep a variable product's attributes when a save posts none.
	 *
	 * WooCommerce reads the attribute fields from the submitted form and
	 * treats their absence as "remove everything" — so a form that lost
	 * those fields (a broken edit screen, a script error, a truncated
	 * request) silently wipes the attributes and orphans every variation.
	 * When that shape appears, restore the stored attributes and note that it
	 * happened, so a recurrence is visible without a trace of every save.
	 *
	 * @param \WC_Product $product Product being saved.
	 */
	public function guard_attributes( $product ): void {
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		if ( ! empty( $product->get_attributes() ) ) {
			return;
		}

		// The save is about to leave a variable product with zero attributes —
		// whatever emptied them (unposted fields, values Woo discarded), that
		// is never what an edit intends. The database still holds the
		// pre-save state here; put it back.
		$stored = wc_get_product( $product->get_id() );

		if ( $stored && ! empty( $stored->get_attributes() ) ) {
			$product->set_attributes( $stored->get_attributes() );
			$this->save_log( sprintf( 'GUARD restored %d attributes on product=%d', count( $stored->get_attributes() ), $product->get_id() ) );
		}
	}

	/**
	 * Note that the guard had to step in.
	 *
	 * This used to append a line per save to wp-content/uploads, a file the
	 * whole internet could read. It held no secrets, but a public trace of
	 * how the admin saves products is a map drawn for the wrong reader, and
	 * the per-save detail was scaffolding for a bug the guard now handles.
	 * What is worth keeping is how often it fires, which lives in an option.
	 *
	 * @param string $line What happened.
	 */
	private function save_log( string $line ): void {
		$seen = (array) get_option( self::GUARD_LOG, array() );

		update_option(
			self::GUARD_LOG,
			array(
				'count' => (int) ( $seen['count'] ?? 0 ) + 1,
				'last'  => gmdate( 'c' ) . ' ' . $line,
			),
			false
		);
	}

	/**
	 * "Colour galleries" tab in the Product Data box.
	 *
	 * @param array $tabs Product data tabs.
	 * @return array
	 */
	public function galleries_tab( array $tabs ): array {
		$tabs['oc_color_galleries'] = array(
			'label'    => __( 'Colour galleries', 'oc-theme' ),
			'target'   => 'oc_color_galleries_panel',
			'class'    => array( 'show_if_variable' ),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * The panel: one block per colour value the product uses — a gallery to
	 * show when that colour is picked, and an optional swatch override.
	 */
	public function galleries_panel(): void {
		global $post;

		$product = wc_get_product( $post->ID );
		$attrs   = $product instanceof \WC_Product ? $this->product_attrs( $product ) : array();
		$saved   = $this->galleries_meta( $post->ID );
		$types   = $this->type_overrides( $post->ID );
		$names   = array(
			'select'       => __( 'Dropdown', 'oc-theme' ),
			'button'       => __( 'Buttons', 'oc-theme' ),
			'swatch'       => __( 'Swatch — colour', 'oc-theme' ),
			'swatch_image' => __( 'Swatch — image', 'oc-theme' ),
		);

		$any_swatch = false;
		foreach ( $attrs as $attr ) {
			if ( in_array( $attr['type'], array( 'swatch', 'swatch_image' ), true ) ) {
				$any_swatch = true;
			}
		}

		wp_enqueue_media();
		echo '<div id="oc_color_galleries_panel" class="panel woocommerce_options_panel">';

		// Whether the page opens on a colour at all. With no default form
		// values the theme opens on the colour of the main photo; this
		// says "nothing".
		echo '<input type="hidden" name="oc_no_default_seen" value="1" />';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_oc_no_default',
				'label'       => __( 'Open with nothing chosen', 'oc-theme' ),
				'description' => __( 'The product page opens with no colour or size selected, and catalogue cards mark no colour. Unticked, a product without default form values opens on the colour of its main photo.', 'oc-theme' ),
				'value'       => self::no_default( (int) $post->ID ) ? 'yes' : 'no',
			)
		);

		if ( empty( $attrs ) ) {
			echo '<p class="form-field">' . esc_html__( 'Add an attribute used for variations (for example: colour), save, and its values appear here.', 'oc-theme' ) . '</p>';
		}

		$first = true;

		foreach ( $attrs as $attr ) {
			$is_swatch = in_array( $attr['type'], array( 'swatch', 'swatch_image' ), true );
			$open      = $is_swatch || ( $first && ! $any_swatch );
			$first     = false;

			// Where the default display comes from, so the choice reads plainly.
			if ( '' !== $attr['tax'] ) {
				$source = __( 'from the attribute settings', 'oc-theme' );
			} elseif ( '' !== $attr['twin'] ) {
				/* translators: %s: global attribute label. */
				$source = sprintf( __( 'from the global attribute “%s”', 'oc-theme' ), wc_attribute_label( $attr['twin'] ) );
			} else {
				$source = __( 'no global attribute of this name', 'oc-theme' );
			}

			echo '<details class="oc-cgal-attr" data-attr="' . esc_attr( $attr['key'] ) . '"' . ( $open ? ' open' : '' ) . ' style="border-block-end:1px solid #ddd;">';
			echo '<summary style="padding:10px 12px;cursor:pointer;font-weight:600;">' . esc_html( $attr['label'] ) . ' <span style="font-weight:400;color:#757575;">(' . esc_html( $names[ $attr['type'] ] ?? $attr['type'] ) . ')</span></summary>';

			// How this attribute is drawn on the product page. A local
			// attribute has no settings screen of its own; this is it.
			echo '<p class="form-field" style="display:flex;align-items:center;gap:8px;">';
			echo '<label for="oc_attr_type_' . esc_attr( $attr['key'] ) . '" style="float:none;inline-size:auto;margin:0;">' . esc_html__( 'Display', 'oc-theme' ) . '</label>';
			echo '<select id="oc_attr_type_' . esc_attr( $attr['key'] ) . '" name="oc_attr_type[' . esc_attr( $attr['key'] ) . ']" style="inline-size:auto;">';
			/* translators: 1: display type, 2: where it comes from. */
			echo '<option value="">' . esc_html( sprintf( __( 'Default — %1$s (%2$s)', 'oc-theme' ), $names[ $attr['inherited'] ] ?? $attr['inherited'], $source ) ) . '</option>';
			foreach ( $names as $slug => $name ) {
				echo '<option value="' . esc_attr( $slug ) . '"' . selected( $types[ $attr['key'] ] ?? '', $slug, false ) . '>' . esc_html( $name ) . '</option>';
			}
			echo '</select></p>';

			foreach ( $attr['values'] as $val ) {
				$entry  = $saved[ $val['slug'] ] ?? array(
					'imgs'   => array(),
					'swatch' => '',
					'color'  => '',
				);
				$swatch = (string) $entry['swatch'];
				$color  = (string) ( $entry['color'] ?? '' );

				$term_color = $val['term'] instanceof \WP_Term ? (string) get_term_meta( $val['term']->term_id, 'oc_swatch_color', true ) : '';
				$term_color = '' !== $term_color ? $term_color : '#cccccc';

				echo '<div class="oc-cgal" data-slug="' . esc_attr( $val['slug'] ) . '" style="border-block-start:1px solid #eee;padding:12px;">';
				echo '<strong style="display:block;margin-block-end:8px;">' . esc_html( $val['name'] ) . '</strong>';

				// Gallery ids + sortable previews: drag to reorder, × removes one,
				// and the add button sits on its own line under the images.
				echo '<input type="hidden" name="oc_cgal[' . esc_attr( $val['slug'] ) . '][imgs]" value="' . esc_attr( implode( ',', $entry['imgs'] ) ) . '" class="oc-cgal__ids" />';
				echo '<span class="oc-cgal__thumbs" style="display:flex;flex-wrap:wrap;gap:6px;' . ( empty( $entry['imgs'] ) ? '' : 'margin-block-end:8px;' ) . '">';
				foreach ( $entry['imgs'] as $img_id ) {
					$url = wp_get_attachment_image_url( $img_id, 'thumbnail' );
					if ( $url ) {
						echo $this->gallery_chip( $img_id, $url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
					}
				}
				echo '</span>';
				echo '<span style="display:block;margin-block-start:10px;"><button type="button" class="button oc-cgal__pick">' . esc_html__( 'Add images', 'oc-theme' ) . '</button></span>';

				// Optional colour-shade override for this product only: the value
				// keeps its store-wide colour; this product may fine-tune it, and
				// reset returns to the value's own colour.
				echo '<span style="display:flex;align-items:center;gap:8px;margin-block-start:8px;">';
				echo '<label style="float:none;inline-size:auto;margin:0;display:inline-block;">' . esc_html__( 'Colour shade (this product only)', 'oc-theme' ) . '</label>';
				echo '<input type="hidden" name="oc_cgal[' . esc_attr( $val['slug'] ) . '][color]" value="' . esc_attr( $color ) . '" class="oc-cgal__colval" />';
				echo '<input type="color" class="oc-cgal__color" value="' . esc_attr( '' !== $color ? $color : $term_color ) . '" data-def="' . esc_attr( $term_color ) . '" />';
				echo '<button type="button" class="button oc-cgal__colreset"' . ( '' === $color ? ' style="display:none;"' : '' ) . '>' . esc_html__( 'Reset', 'oc-theme' ) . '</button>';
				echo '</span>';

				// Optional swatch override for this product only — the chosen image
				// becomes a chip with the same red x as the gallery ones, and the
				// choose button hides while an override is set.
				echo '<span style="display:flex;align-items:center;gap:8px;margin-block-start:8px;">';
				echo '<label style="float:none;inline-size:auto;margin:0;display:inline-block;">' . esc_html__( 'Swatch image (this product only)', 'oc-theme' ) . '</label>';
				echo '<input type="hidden" name="oc_cgal[' . esc_attr( $val['slug'] ) . '][swatch]" value="' . esc_url( $swatch ) . '" class="oc-cgal__sw" />';
				echo '<span class="oc-cgal__swchip" style="position:relative;display:' . ( '' === $swatch ? 'none' : 'inline-block' ) . ';">';
				echo '<img class="oc-cgal__swprev" src="' . esc_url( $swatch ) . '" alt="" style="inline-size:28px;block-size:28px;border-radius:50%;object-fit:cover;border:1px solid #ccd0d4;display:block;" />';
				echo '<button type="button" class="oc-cgal__swx" aria-label="' . esc_attr__( 'Remove', 'oc-theme' ) . '" style="position:absolute;inset-block-start:-6px;inset-inline-end:-6px;inline-size:18px;block-size:18px;border-radius:50%;border:none;background:#d63638;color:#fff;font-size:12px;line-height:1;cursor:pointer;padding:0;">&times;</button>';
				echo '</span>';
				echo '<button type="button" class="button oc-cgal__swpick"' . ( '' === $swatch ? '' : ' style="display:none;"' ) . '>' . esc_html__( 'Choose image', 'oc-theme' ) . '</button>';
				echo '</span>';

				echo '</div>';
			}

			echo '</details>';
		}

		$this->galleries_panel_script();
		echo '</div>';
	}

	/**
	 * One sortable preview chip: drag to reorder, × removes just this image.
	 *
	 * @param int    $img_id Attachment id.
	 * @param string $url    Thumbnail URL.
	 * @return string
	 */
	private function gallery_chip( int $img_id, string $url ): string {
		return sprintf(
			'<span class="oc-cgal__chip" draggable="true" data-id="%d" style="position:relative;display:inline-block;cursor:grab;">' .
			'<img src="%s" alt="" style="inline-size:48px;block-size:48px;object-fit:cover;border-radius:4px;display:block;border:1px solid #ccd0d4;" />' .
			'<button type="button" class="oc-cgal__x" aria-label="%s" style="position:absolute;inset-block-start:-6px;inset-inline-end:-6px;inline-size:18px;block-size:18px;border-radius:50%%;border:none;background:#d63638;color:#fff;font-size:12px;line-height:1;cursor:pointer;padding:0;">&times;</button>' .
			'</span>',
			absint( $img_id ),
			esc_url( $url ),
			esc_attr__( 'Remove', 'oc-theme' )
		);
	}

	/**
	 * One shared script for the galleries panel: pickers, per-image removal
	 * and drag-to-reorder, all synced into the hidden ids field.
	 */
	private function galleries_panel_script(): void {
		?>
		<script>
		( function () {
			function chipHtml( id, url ) {
				return '<span class="oc-cgal__chip" draggable="true" data-id="' + id + '" style="position:relative;display:inline-block;cursor:grab;">' +
					'<img src="' + url + '" alt="" style="inline-size:48px;block-size:48px;object-fit:cover;border-radius:4px;display:block;border:1px solid #ccd0d4;" />' +
					'<button type="button" class="oc-cgal__x" aria-label="x" style="position:absolute;inset-block-start:-6px;inset-inline-end:-6px;inline-size:18px;block-size:18px;border-radius:50%;border:none;background:#d63638;color:#fff;font-size:12px;line-height:1;cursor:pointer;padding:0;">&times;</button>' +
					'</span>';
			}

			function syncIds( row ) {
				var ids = [];
				row.querySelectorAll( '.oc-cgal__chip' ).forEach( function ( chip ) {
					ids.push( chip.dataset.id );
				} );
				row.querySelector( '.oc-cgal__ids' ).value = ids.join( ',' );
			}

			var dragChip = null;

			document.addEventListener( 'dragstart', function ( event ) {
				var chip = event.target.closest ? event.target.closest( '.oc-cgal__chip' ) : null;
				if ( chip ) {
					dragChip = chip;
					event.dataTransfer.effectAllowed = 'move';
				}
			} );

			document.addEventListener( 'dragover', function ( event ) {
				if ( ! dragChip ) {
					return;
				}
				var over = event.target.closest ? event.target.closest( '.oc-cgal__chip' ) : null;
				if ( ! over || over === dragChip || over.parentElement !== dragChip.parentElement ) {
					return;
				}
				event.preventDefault();
				var rect = over.getBoundingClientRect();
				var rtl = 'rtl' === getComputedStyle( over ).direction;
				var firstHalf = ( event.clientX - rect.left ) / rect.width < 0.5;
				var before = rtl ? ! firstHalf : firstHalf;
				over.parentElement.insertBefore( dragChip, before ? over : over.nextSibling );
			} );

			document.addEventListener( 'dragend', function () {
				if ( dragChip ) {
					syncIds( dragChip.closest( '.oc-cgal' ) );
					dragChip = null;
				}
			} );

			// A picked shade lands in the hidden field; reset returns to the
			// value's own colour.
			document.addEventListener( 'input', function ( event ) {
				var picker = event.target.closest ? event.target.closest( '.oc-cgal__color' ) : null;
				if ( ! picker ) {
					return;
				}
				var row = picker.closest( '.oc-cgal' );
				row.querySelector( '.oc-cgal__colval' ).value = picker.value;
				row.querySelector( '.oc-cgal__colreset' ).style.display = '';
			} );

			document.addEventListener( 'click', function ( event ) {
				var row = event.target.closest( '.oc-cgal' );
				if ( ! row ) {
					return;
				}

				if ( event.target.closest( '.oc-cgal__x' ) ) {
					event.target.closest( '.oc-cgal__chip' ).remove();
					syncIds( row );
					return;
				}

				if ( event.target.closest( '.oc-cgal__colreset' ) ) {
					var shade = row.querySelector( '.oc-cgal__color' );
					shade.value = shade.dataset.def;
					row.querySelector( '.oc-cgal__colval' ).value = '';
					event.target.closest( '.oc-cgal__colreset' ).style.display = 'none';
					return;
				}

				if ( event.target.closest( '.oc-cgal__swx' ) ) {
					row.querySelector( '.oc-cgal__sw' ).value = '';
					row.querySelector( '.oc-cgal__swchip' ).style.display = 'none';
					row.querySelector( '.oc-cgal__swpick' ).style.display = '';
					return;
				}

				if ( ! window.wp || ! wp.media ) {
					return;
				}

				if ( event.target.closest( '.oc-cgal__pick' ) ) {
					var frame = wp.media( { multiple: 'add', library: { type: 'image' } } );
					frame.on( 'select', function () {
						var box = row.querySelector( '.oc-cgal__thumbs' );
						var have = [];
						box.querySelectorAll( '.oc-cgal__chip' ).forEach( function ( chip ) {
							have.push( chip.dataset.id );
						} );
						frame.state().get( 'selection' ).forEach( function ( att ) {
							var a = att.toJSON();
							if ( have.indexOf( String( a.id ) ) > -1 ) {
								return;
							}
							var u = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
							box.insertAdjacentHTML( 'beforeend', chipHtml( a.id, u ) );
						} );
						syncIds( row );
					} );
					frame.open();
					return;
				}

				if ( event.target.closest( '.oc-cgal__swpick' ) ) {
					var swFrame = wp.media( { multiple: false, library: { type: 'image' } } );
					swFrame.on( 'select', function () {
						var a = swFrame.state().get( 'selection' ).first().toJSON();
						var u = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
						row.querySelector( '.oc-cgal__sw' ).value = u;
						row.querySelector( '.oc-cgal__swprev' ).src = u;
						row.querySelector( '.oc-cgal__swchip' ).style.display = 'inline-block';
						row.querySelector( '.oc-cgal__swpick' ).style.display = 'none';
					} );
					swFrame.open();
					return;
				}
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Persist the colour galleries.
	 *
	 * @param int $post_id Product id.
	 */
	public function save_galleries( $post_id ): void {
		// Woo verified its own nonce before this hook fires.
		if ( isset( $_POST['oc_no_default_seen'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST['_oc_no_default'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				update_post_meta( $post_id, '_oc_no_default', 'yes' );
			} else {
				delete_post_meta( $post_id, '_oc_no_default' );
			}
		}

		if ( isset( $_POST['oc_attr_type'] ) && is_array( $_POST['oc_attr_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$types = array();

			foreach ( wp_unslash( (array) $_POST['oc_attr_type'] ) as $key => $type ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( in_array( (string) $type, array( 'select', 'button', 'swatch', 'swatch_image' ), true ) ) {
					$types[ sanitize_title( (string) $key ) ] = (string) $type;
				}
			}

			if ( empty( $types ) ) {
				delete_post_meta( $post_id, '_oc_attr_types' );
			} else {
				update_post_meta( $post_id, '_oc_attr_types', $types );
			}
		}

		if ( ! isset( $_POST['oc_cgal'] ) || ! is_array( $_POST['oc_cgal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$clean = array();

		foreach ( wp_unslash( (array) $_POST['oc_cgal'] ) as $slug => $entry ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$imgs   = array_filter( array_map( 'absint', explode( ',', (string) ( $entry['imgs'] ?? '' ) ) ) );
			$swatch = esc_url_raw( (string) ( $entry['swatch'] ?? '' ) );
			$color  = (string) sanitize_hex_color( (string) ( $entry['color'] ?? '' ) );

			if ( ! empty( $imgs ) || '' !== $swatch || '' !== $color ) {
				$clean[ sanitize_title( (string) $slug ) ] = array(
					'imgs'   => array_values( $imgs ),
					'swatch' => $swatch,
					'color'  => $color,
				);
			}
		}

		if ( empty( $clean ) ) {
			delete_post_meta( $post_id, '_oc_color_galleries' );
		} else {
			update_post_meta( $post_id, '_oc_color_galleries', $clean );
		}
	}

	/**
	 * Drop the data-thumb / data-thumb-srcset attributes Woo prints for its
	 * flexslider thumbnails. The slider is removed here and nothing reads
	 * them — but data-thumb-srcset repeats the whole srcset string per
	 * slide, which alone was a third of the colour-gallery payload.
	 *
	 * @param string $html Gallery image html.
	 * @return string
	 */
	public static function strip_thumb_data( string $html ): string {
		return (string) preg_replace( '/\sdata-thumb(?:-alt|-srcset)?="[^"]*"/', '', $html );
	}

	/**
	 * The colour → gallery map for the front end: ready-made slide markup per
	 * colour, so a swatch click swaps the gallery with zero requests.
	 */
	public function galleries_json(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || ! function_exists( 'wc_get_gallery_image_html' ) ) {
			return;
		}

		$galleries = $this->galleries_meta( $product->get_id() );

		if ( empty( $galleries ) ) {
			return;
		}

		// Keyed by what the select holds — a term's slug, a local value's
		// typed text — which is what the script looks up on every change.
		$map  = array();
		$from = '';

		foreach ( $this->product_attrs( $product ) as $attr ) {
			foreach ( $attr['values'] as $val ) {
				$entry = $galleries[ $val['slug'] ] ?? null;

				if ( empty( $entry['imgs'] ) || isset( $map[ $val['value'] ] ) ) {
					continue;
				}

				if ( '' === $from ) {
					$from = $attr['key'];
				}

				$slides = array();
				foreach ( $entry['imgs'] as $i => $img_id ) {
					$slides[] = self::strip_thumb_data( wc_get_gallery_image_html( $img_id, 0 === $i ) );
				}

				$map[ $val['value'] ] = $slides;
			}
		}

		if ( empty( $map ) ) {
			return;
		}

		// The page's markup already holds the opening colour's gallery, so
		// the product's own slides travel here under '' — the answer to
		// "nothing chosen" — and the script starts from that colour.
		$open = $this->opening_gallery( $product );

		if ( null !== $open ) {
			$own    = array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) );
			$slides = array();

			foreach ( array_values( $own ) as $i => $img_id ) {
				$slides[] = self::strip_thumb_data( wc_get_gallery_image_html( $img_id, 0 === $i ) );
			}

			$map[''] = $slides;
		}

		printf(
			'<script type="application/json" id="oc-color-galleries" data-attr="%s"%s>%s</script>',
			esc_attr( 'attribute_' . $from ),
			null === $open ? '' : ' data-open="' . esc_attr( $open['value'] ) . '"',
			wp_json_encode( $map ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in a non-executed script tag.
		);
	}

	/**
	 * Whether any colour gallery actually holds pictures.
	 *
	 * @param array<string,array{imgs:array<int,int>,swatch:string,color:string}> $galleries From galleries_meta().
	 */
	private static function galleries_have_pictures( array $galleries ): bool {
		foreach ( $galleries as $entry ) {
			if ( ! empty( $entry['imgs'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The colour the product page opens on, when it has a gallery: the one
	 * in the address (a card swatch, a shared link), else the default.
	 *
	 * @param \WC_Product $product The product.
	 * @return array{value:string,imgs:array<int,int>}|null
	 */
	private function opening_gallery( \WC_Product $product ): ?array {
		static $cache = array();

		$id = $product->get_id();

		if ( array_key_exists( $id, $cache ) ) {
			return $cache[ $id ];
		}

		$cache[ $id ] = null;
		$galleries    = $this->galleries_meta( $id );

		if ( ! $product instanceof \WC_Product_Variable || ! self::galleries_have_pictures( $galleries ) ) {
			return null;
		}

		$defaults = (array) $product->get_default_attributes();

		foreach ( $this->product_attrs( $product ) as $attr ) {
			$field = 'attribute_' . $attr['key'];
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a colour in the address, read the way Woo reads it.
			$asked = isset( $_REQUEST[ $field ] ) ? wc_clean( wp_unslash( (string) $_REQUEST[ $field ] ) ) : (string) ( $defaults[ $attr['key'] ] ?? '' );

			if ( '' === $asked ) {
				continue;
			}

			foreach ( $attr['values'] as $val ) {
				if ( ! self::same_value( $asked, $val ) ) {
					continue;
				}

				$imgs = array_values( array_filter( array_map( 'intval', $galleries[ $val['slug'] ]['imgs'] ?? array() ) ) );

				if ( $imgs ) {
					$cache[ $id ] = array(
						'value' => $val['value'],
						'imgs'  => $imgs,
					);

					return $cache[ $id ];
				}
			}
		}

		return null;
	}

	/**
	 * Whether an asked-for value names this attribute value — as the term
	 * slug, its percent-decoded form, or the typed text of a local value.
	 *
	 * @param string $asked The value asked for.
	 * @param array  $val   One of product_attrs()' values.
	 */
	private static function same_value( string $asked, array $val ): bool {
		$a = rawurldecode( $asked );

		return $a === rawurldecode( (string) $val['value'] )
			|| $a === rawurldecode( (string) $val['slug'] )
			|| sanitize_title( $a ) === (string) $val['slug'];
	}

	/**
	 * Before the gallery renders: the opening colour's pictures stand in
	 * for the product's own, for the gallery template alone.
	 */
	public function open_gallery_on(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || null === $this->opening_gallery( $product ) ) {
			return;
		}

		add_filter( 'woocommerce_product_get_image_id', array( $this, 'open_image_id' ), 10, 2 );
		add_filter( 'woocommerce_product_get_gallery_image_ids', array( $this, 'open_gallery_ids' ), 10, 2 );
	}

	/**
	 * After the gallery rendered: the product's own pictures again.
	 */
	public function open_gallery_off(): void {
		remove_filter( 'woocommerce_product_get_image_id', array( $this, 'open_image_id' ), 10 );
		remove_filter( 'woocommerce_product_get_gallery_image_ids', array( $this, 'open_gallery_ids' ), 10 );
	}

	/**
	 * The opening colour's first picture as the main image.
	 *
	 * @param mixed $value   Stored image id.
	 * @param mixed $product The product.
	 * @return mixed
	 */
	public function open_image_id( $value, $product ) {
		$open = $product instanceof \WC_Product ? $this->opening_gallery( $product ) : null;

		return null === $open ? $value : $open['imgs'][0];
	}

	/**
	 * The opening colour's other pictures as the gallery.
	 *
	 * @param mixed $ids     Stored gallery ids.
	 * @param mixed $product The product.
	 * @return mixed
	 */
	public function open_gallery_ids( $ids, $product ) {
		$open = $product instanceof \WC_Product ? $this->opening_gallery( $product ) : null;

		return null === $open ? $ids : array_slice( $open['imgs'], 1 );
	}

	/**
	 * Under Products: the report.
	 */
	public function report_menu(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Main photo without a colour', 'oc-theme' ),
			__( 'Main photo without a colour', 'oc-theme' ),
			'manage_woocommerce',
			'oc-color-report',
			array( $this, 'report_page' )
		);
	}

	/**
	 * Products with colour galleries whose main photo sits in none of them.
	 *
	 * @return array<int,array{id:int,colours:array<int,string>}>
	 */
	private function unowned_products(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_oc_color_galleries', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- an admin report, run on request.
				'no_found_rows'  => true,
			)
		);

		$out = array();

		foreach ( $ids as $id ) {
			$id        = (int) $id;
			$galleries = $this->galleries_meta( $id );
			$main      = (int) get_post_thumbnail_id( $id );

			if ( ! self::galleries_have_pictures( $galleries ) ) {
				continue;
			}

			$colours = array();
			$owned   = false;

			foreach ( $galleries as $slug => $entry ) {
				if ( empty( $entry['imgs'] ) ) {
					continue;
				}

				$colours[] = rawurldecode( (string) $slug );

				if ( $main && in_array( $main, $entry['imgs'], true ) ) {
					$owned = true;
				}
			}

			if ( ! $owned ) {
				$out[] = array(
					'id'      => $id,
					'colours' => $colours,
				);
			}
		}

		return $out;
	}

	/**
	 * The report screen.
	 */
	public function report_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$rows = $this->unowned_products();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Main photo without a colour', 'oc-theme' ); ?></h1>
			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'These products have colour galleries, but their main photo sits in none of them. Their catalogue card marks no colour and the product page opens with nothing chosen, so the photo never contradicts the swatch. To have a colour marked again, add the main photo to that colour\'s gallery (Product data → Colour galleries), or make one of the gallery pictures the main photo.', 'oc-theme' ); ?>
			</p>
			<p>
				<?php
				/* translators: %s: how many products. */
				echo esc_html( sprintf( __( 'Products found: %s', 'oc-theme' ), number_format_i18n( count( $rows ) ) ) );
				?>
			</p>
			<table class="widefat striped" style="max-width:960px;">
				<thead>
					<tr>
						<th style="width:70px;"><?php esc_html_e( 'Main photo', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Product', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Colours with a gallery', 'oc-theme' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'None — every main photo belongs to a colour.', 'oc-theme' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo get_the_post_thumbnail( $row['id'], array( 56, 56 ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $row['id'] ) ); ?>"><strong><?php echo esc_html( get_the_title( $row['id'] ) ); ?></strong></a>
								<a href="<?php echo esc_url( (string) get_permalink( $row['id'] ) ); ?>" target="_blank" rel="noopener" style="margin-inline-start:8px;font-size:12px;"><?php esc_html_e( 'view', 'oc-theme' ); ?></a>
							</td>
							<td><?php echo esc_html( implode( ', ', $row['colours'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * A colour with its own gallery lends its first picture to the variation.
	 *
	 * Woo writes the chosen variation's image into the gallery's first slide,
	 * and a variation without an image inherits the product's main photo — so
	 * a white variation put the yellow photo back over the white gallery the
	 * moment it was chosen. The colour gallery is the shop's own answer to
	 * "what does this colour look like", so it wins.
	 *
	 * WooCommerce's own per-variation gallery is taken over here too. Woo
	 * shows it by replacing the whole gallery element with its plain markup,
	 * which drops the theme's layout, rail and active slide — the gallery
	 * showed for a moment and vanished. The slides travel as `oc_gallery`
	 * and the theme swaps them in the way it swaps a colour gallery; a
	 * colour gallery set in the theme's tab still wins.
	 *
	 * @param mixed $data      Variation data for the form.
	 * @param mixed $product   Parent product.
	 * @param mixed $variation The variation.
	 * @return mixed
	 */
	public function variation_gallery_image( $data, $product, $variation ) {
		if ( ! is_array( $data ) || ! $product instanceof \WC_Product || ! $variation instanceof \WC_Product_Variation ) {
			return $data;
		}

		$galleries   = $this->galleries_meta( $product->get_id() );
		$from_colour = false;

		if ( ! empty( $galleries ) ) {
			$chosen = $variation->get_attributes();

			foreach ( $this->product_attrs( $product ) as $attr ) {
				$value = (string) ( $chosen[ $attr['key'] ] ?? '' );
				$img   = '' === $value ? 0 : (int) ( $galleries[ sanitize_title( $value ) ]['imgs'][0] ?? 0 );

				if ( $img > 0 && wp_attachment_is_image( $img ) ) {
					$data['image']    = wc_get_product_attachment_props( $img, $variation );
					$data['image_id'] = $img;
					$from_colour      = true;
					break;
				}
			}
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) ( $data['gallery_image_ids'] ?? array() ) ) ) );

		if ( empty( $data['gallery_images_html'] ) && empty( $ids ) ) {
			return $data;
		}

		// Never let Woo replace the gallery element itself.
		$data['gallery_images_html'] = '';

		if ( $from_colour || empty( $ids ) ) {
			return $data;
		}

		// As Woo shows it: the variation's image, then its own gallery.
		$slides = array();
		$order  = array_values( array_unique( array_filter( array_merge( array( (int) ( $data['image_id'] ?? 0 ) ), $ids ) ) ) );

		foreach ( $order as $i => $img_id ) {
			$slides[] = self::strip_thumb_data( wc_get_gallery_image_html( (int) $img_id, 0 === $i ) );
		}

		$data['oc_gallery'] = $slides;

		return $data;
	}

	/**
	 * Save the linked group and keep every member in sync.
	 *
	 * @param int $post_id Product id.
	 */
	public function save_links( $post_id ): void {
		// Woo verified its own nonce before this hook fires.
		$new = isset( $_POST['oc_color_links'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['oc_color_links'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$old = array_filter( array_map( 'absint', (array) get_post_meta( $post_id, '_oc_color_links', true ) ) );

		$group = array_values( array_unique( array_merge( array( (int) $post_id ), $new ) ) );

		foreach ( $group as $member ) {
			$others = array_values( array_diff( $group, array( $member ) ) );
			update_post_meta( $member, '_oc_color_links', $others );
		}

		// A sibling removed here forgets this product too.
		foreach ( array_diff( $old, $new ) as $removed ) {
			$kept = array_values(
				array_diff(
					array_filter( array_map( 'absint', (array) get_post_meta( $removed, '_oc_color_links', true ) ) ),
					array( (int) $post_id )
				)
			);
			update_post_meta( $removed, '_oc_color_links', $kept );
		}
	}

	/**
	 * Colour sibling thumbs on the product page — a click navigates. Renders
	 * in the variations area, in the same "Label: value" format as every
	 * attribute row: the label comes from the product's swatch attribute and
	 * the value is this product's own colour.
	 */
	public function product_colors(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$row = $this->colors_row( $product->get_id(), 'oc-colors--product' );

		if ( '' === $row ) {
			return;
		}

		$label = __( 'Colours', 'oc-theme' );
		$value = '';

		$attr = $this->swatch_attr( $product );

		if ( null !== $attr && 1 === count( $attr['values'] ) ) {
			$label = $attr['label'];
			$value = $attr['values'][0]['name'];
		}

		printf(
			'<div class="oc-colors-wrap"><span class="oc-colors-label">%s%s</span>%s</div>',
			esc_html( $label ),
			'' !== $value ? '<span class="oc-choice">' . esc_html( $value ) . '</span>' : '',
			$row // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}

	/**
	 * Colour swatches under the card. Linked siblings when the product has
	 * them; otherwise the colour variation terms of a regular variable
	 * product — the exact same behaviour: a click swaps the card's gallery
	 * in place, and the card links carry the colour so the product page
	 * opens with it selected.
	 */
	public function loop_colors(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$row = $this->colors_row( $product->get_id(), 'oc-colors--loop', true );

		if ( '' === $row ) {
			$row = $this->loop_term_colors( $product );
		}

		echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Term swatches for a variable product's colour attribute on the card.
	 *
	 * @param \WC_Product $product Product.
	 * @return string Empty when there is no swatch attribute with 2+ values.
	 */
	private function loop_term_colors( \WC_Product $product ): string {
		if ( ! $product instanceof \WC_Product_Variable ) {
			return '';
		}

		$attr = $this->swatch_attr( $product );

		if ( null === $attr ) {
			return '';
		}

		// Only the colours a variation is actually sold in.
		$attr['values'] = $this->sold_values( $product, $attr );

		if ( count( $attr['values'] ) < 2 ) {
			return '';
		}

		$type      = $attr['type'];
		$galleries = $this->galleries_meta( $product->get_id() );
		// Same rule as the card itself: inside a sideways row there is one
		// picture, so the swatch must not hand the script four to rebuild.
		$max       = ( 'gallery' === get_theme_mod( 'oc_card_image_mode', 'single' ) && ! WooCommerce::in_slider() ) ? max( 2, (int) get_theme_mod( 'oc_card_gallery_max', 4 ) ) : 1;
		$permalink = get_permalink( $product->get_id() );
		$list      = array();

		// Woo reads the preselection from the sanitize_title form of the
		// attribute name — for a Hebrew attribute that means the
		// percent-encoded key, not the readable one.
		// rawurlencode keeps the sanitize_title percents literal in the
		// URL, so PHP's own decode hands Woo exactly the key it expects.
		$qkey = 'attribute_' . rawurlencode( $attr['key'] );

		// The lead colour is pre-marked (none for a product that opens with
		// nothing chosen) — the same one the card links carry.
		$lead = $this->default_term( $product );
		$lead = null === $lead ? null : $lead['value'];
		$at   = -1;

		foreach ( $attr['values'] as $val ) {
			$style = $this->value_style( $product, $attr, $val, $type );

			if ( '' === $style ) {
				continue;
			}

			// The colour's own gallery drives the card; a variation image
			// stands in when no gallery was attached.
			$imgs = array();
			foreach ( array_slice( $galleries[ $val['slug'] ]['imgs'] ?? array(), 0, $max ) as $img_id ) {
				$img_url = wp_get_attachment_image_url( $img_id, 'large' );
				if ( $img_url ) {
					$imgs[] = $img_url;
				}
			}

			// A variation's own picture stands in when no gallery was attached.
			// One it only inherits is the product's main photo: that is no
			// picture of this colour, so the card shows the product's own
			// gallery instead, as the product page does.
			if ( empty( $imgs ) ) {
				$var_img = $this->variation_image( $product, rawurldecode( $attr['key'] ), $val['value'], 'large' );
				if ( '' !== $var_img && wp_get_attachment_image_url( (int) $product->get_image_id(), 'large' ) !== $var_img ) {
					$imgs[] = $var_img;
				}
			}

			$is_lead = null !== $lead && $val['value'] === $lead;

			if ( $is_lead ) {
				$at = count( $list );
			}

			$list[] = sprintf(
				'<a class="oc-colors__item oc-colors__item--term%s" href="%s" style="%s" title="%s" aria-label="%s"%s data-url="%s" data-pid="%d" data-imgs="%s" data-slug="%s"></a>',
				$is_lead ? ' is-current' : '',
				esc_url( add_query_arg( $qkey, $val['value'], $permalink ) ),
				$style, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				esc_attr( $val['name'] ),
				esc_attr( $val['name'] ),
				$is_lead ? ' aria-current="true"' : '',
				esc_url( add_query_arg( $qkey, $val['value'], $permalink ) ),
				absint( $product->get_id() ),
				esc_attr( (string) wp_json_encode( $imgs ) ),
				esc_attr( $val['value'] )
			);
		}

		if ( empty( $list ) ) {
			return '';
		}

		return '<div class="oc-colors oc-colors--loop">' . implode( '', self::capped( $list, $at, $permalink ) ) . '</div>';
	}

	/**
	 * A row of colour-sibling thumbnails, the current product marked.
	 *
	 * Siblings render in id order on every member's page — a stable order, so
	 * the row never reshuffles as the visitor moves between colours.
	 *
	 * @param int    $product_id Current product.
	 * @param string $css_class  Context class.
	 * @param bool   $with_data  Attach card-swap data (catalogue cards).
	 * @return string Empty when the product has no siblings.
	 */
	private function colors_row( int $product_id, string $css_class, bool $with_data = false ): string {
		$links = array_filter( array_map( 'absint', (array) get_post_meta( $product_id, '_oc_color_links', true ) ) );

		if ( empty( $links ) ) {
			return '';
		}

		$ids = array_unique( array_merge( array( $product_id ), $links ) );
		sort( $ids );

		// In a smart "on sale" category, a colour that is NOT on promotion
		// must not be offered — a shopper switching to it would land on a
		// full-price product inside the sale section.
		$sale_context = $with_data && Filters::sale_context();

		$list          = array();
		$at_current    = -1;
		$sale_siblings = 0;

		foreach ( $ids as $id ) {
			$sibling = wc_get_product( $id );

			if ( ! $sibling || 'publish' !== $sibling->get_status() ) {
				continue;
			}

			if ( $sale_context && ! Filters::product_promoted( $sibling ) ) {
				continue;
			}

			if ( $sale_context && $id !== $product_id ) {
				++$sale_siblings;
			}

			// The sibling's own colour value decides the circle, through the
			// same chain as every swatch — attribute type first, product
			// thumbnail only as the last fallback. So a colour-led attribute
			// shows colours here too, not product photos.
			$style = $this->sibling_style( $sibling );
			$thumb = '' !== $style ? '' : wp_get_attachment_image_url( (int) $sibling->get_image_id(), 'thumbnail' );

			if ( '' === $style && ! $thumb ) {
				continue;
			}

			$current = $id === $product_id;
			$data    = '';

			if ( $with_data ) {
				$badge = $sibling->is_on_sale()
					// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Woo's own badge string; reuse its translation.
					? (string) apply_filters( 'woocommerce_sale_flash', '<span class="onsale">' . esc_html__( 'Sale!', 'woocommerce' ) . '</span>', get_post( $id ), $sibling )
					: '';

				$data = sprintf(
					' data-url="%s" data-pid="%d" data-name="%s" data-price="%s" data-badge="%s" data-imgs="%s" data-oos="%s" data-var="%s"',
					esc_url( get_permalink( $id ) ),
					absint( $id ),
					esc_attr( $sibling->get_name() ),
					esc_attr( $sibling->get_price_html() ),
					esc_attr( $badge ),
					esc_attr( (string) wp_json_encode( $this->card_image_urls( $sibling ) ) ),
					$sibling->is_in_stock() ? '0' : '1',
					$sibling->is_type( 'variable' ) ? '1' : '0'
				);
			}

			if ( $current ) {
				$at_current = count( $list );
			}

			$list[] = sprintf(
				'<a class="oc-colors__item%s" href="%s" title="%s" aria-label="%s"%s%s%s>%s</a>',
				$current ? ' is-current' : '',
				esc_url( $current ? '#' : get_permalink( $id ) ),
				esc_attr( $sibling->get_name() ),
				esc_attr( $sibling->get_name() ),
				$current ? ' aria-current="true"' : '',
				$data, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				'' !== $style ? ' style="' . $style . '"' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				'' !== $style ? '' : '<img src="' . esc_url( (string) $thumb ) . '" alt="" loading="lazy" />'
			);
		}

		if ( empty( $list ) ) {
			return '';
		}

		// Only its own colour survived the sale filter: no row at all.
		if ( $sale_context && 0 === $sale_siblings ) {
			return '';
		}

		// The catalogue row is capped; anywhere else shows everything.
		if ( false !== strpos( $css_class, 'loop' ) ) {
			$list = self::capped( $list, $at_current, get_permalink( $product_id ) );
		}

		return '<div class="oc-colors ' . esc_attr( $css_class ) . '">' . implode( '', $list ) . '</div>';
	}

	/**
	 * Swatch style for a colour-sibling product, resolved from its own solo
	 * colour value through the standard chain. Empty when the product has no
	 * swatch attribute or nothing to draw — the caller falls back to the
	 * product thumbnail.
	 *
	 * @param \WC_Product $sibling Sibling product.
	 * @return string
	 */
	private function sibling_style( \WC_Product $sibling ): string {
		$attr = $this->swatch_attr( $sibling );

		if ( null !== $attr && 1 === count( $attr['values'] ) ) {
			return $this->value_style( $sibling, $attr, $attr['values'][0], $attr['type'] );
		}

		return '';
	}

	/**
	 * The image URLs a catalogue card would show for a product, mirroring the
	 * card-media rules (mode and gallery cap).
	 *
	 * @param \WC_Product $product Product.
	 * @return string[]
	 */
	private function card_image_urls( \WC_Product $product ): array {
		$mode = (string) get_theme_mod( 'oc_card_image_mode', 'single' );
		$max  = ( 'gallery' === $mode && ! WooCommerce::in_slider() ) ? max( 2, (int) get_theme_mod( 'oc_card_gallery_max', 4 ) ) : 1;

		$ids = array_merge(
			array( (int) $product->get_image_id() ),
			array_map( 'intval', $product->get_gallery_image_ids() )
		);
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, $max );

		$urls = array();
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_image_url( $id, 'large' );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}
}
