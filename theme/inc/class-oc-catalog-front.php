<?php
/**
 * How big a product is in the catalogue, decided while looking at it.
 *
 * The size of a tile and how its picture is cropped are set on the product
 * edit screen, which means changing one and seeing what it did is a walk
 * between two screens. In arranging mode the catalogue says it about itself:
 * three sizes on every card, the one it is now marked, whether the picture
 * is shown whole or cropped, and — for a cropped one, which is the only kind
 * there is anything to choose about — two arrows that move it in its frame.
 * Every change shows at once and is kept straight away.
 *
 * Nothing here reaches a shopper. The script and the stylesheet are put on
 * the page only for somebody who can manage the shop and has asked to arrange
 * it, exactly as the arranging itself is.
 *
 * @package OC_Theme
 */

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The size and the crop, from the catalogue itself.
 */
final class Catalog_Front {

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 11 );
		add_action( 'rest_api_init', array( $this, 'rest' ) );

		// Which of the product's pictures leads its card, chosen on the card.
		add_action( 'oc_card_media_overlay', array( $this, 'pictures' ) );
	}

	/**
	 * The product's pictures, on its card, for the arranging script to page
	 * through. A shopper's page never carries this: the first check is the
	 * query flag, and it is false for everyone who did not ask to arrange.
	 *
	 * @param \WC_Product $product The card's product.
	 */
	public function pictures( $product ): void {
		// The catalogue's next page arrives through the filter request, not a
		// page view, so it is not "the shop" to WordPress. It says it is
		// being arranged with the same flag (see theme.js), and the same
		// capability decides.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view of your own catalogue, gated on the capability.
		$ajax = wp_doing_ajax() && isset( $_GET['oc_sort'] ) && current_user_can( self::cap() );

		if ( ! $product instanceof \WC_Product || ( ! $ajax && ! self::wanted() ) ) {
			return;
		}

		$main = (int) $product->get_image_id();
		$ids  = array_merge( array( $main ), array_map( 'intval', $product->get_gallery_image_ids() ) );
		$ids  = array_values( array_unique( array_filter( $ids ) ) );

		if ( count( $ids ) < 2 ) {
			return;
		}

		$pics = array();

		foreach ( $ids as $id ) {
			$src = (string) wp_get_attachment_image_url( $id, 'large' );

			if ( '' === $src ) {
				continue;
			}

			$srcset = wp_get_attachment_image_srcset( $id, 'large' );

			$pics[] = array(
				'id'     => $id,
				'src'    => $src,
				'srcset' => is_string( $srcset ) ? $srcset : '',
			);
		}

		if ( count( $pics ) < 2 ) {
			return;
		}

		printf(
			'<script type="application/json" class="octile__pics">%s</script>',
			wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < > & encoded.
				array(
					'main' => $main,
					'now'  => Catalog::tile( $product->get_id() )['image'],
					'pics' => $pics,
				),
				JSON_HEX_TAG | JSON_HEX_AMP
			)
		);
	}

	/**
	 * Who may.
	 */
	private static function cap(): string {
		return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Is the shop being arranged by somebody allowed to?
	 */
	private static function wanted(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a view of your own catalogue, gated on the capability.
		return isset( $_GET['oc_sort'] ) && current_user_can( self::cap() ) && ( is_product_category() || is_shop() );
	}

	/**
	 * The controls, for the one person who asked for them.
	 */
	public function assets(): void {
		if ( ! self::wanted() ) {
			return;
		}

		$dir = get_template_directory_uri() . '/assets';
		$ver = defined( 'OC_THEME_VERSION' ) ? OC_THEME_VERSION : '1';

		wp_enqueue_style( 'oc-catalog-front', $dir . '/css/catalog-front.css', array( 'oc-order-front' ), $ver );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the phone preview loads the same page inside a frame.
		if ( isset( $_GET['oc_frame'] ) ) {
			add_filter( 'show_admin_bar', '__return_false' );
		}
		wp_enqueue_script( 'oc-catalog-front', $dir . '/js/catalog-front.js', array( 'oc-order-front' ), $ver, true );

		wp_add_inline_script(
			'oc-catalog-front',
			'window.ocTile = ' . wp_json_encode(
				array(
					'rest'  => rest_url( 'oc/v1/tile' ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					// phpcs:disable WordPress.Security.NonceVerification.Recommended -- which view of your own catalogue you asked for.
					'dev'   => isset( $_GET['oc_dev'] ) && 'm' === $_GET['oc_dev'] ? 'm' : 'd',
					'frame' => isset( $_GET['oc_frame'] ) ? 1 : 0,
					// phpcs:enable
					'width' => 390,
					'i18n'  => array(
						'size'   => __( 'How big in the catalogue', 'oc-theme' ),
						'plain'  => __( 'One cell', 'oc-theme' ),
						'wide'   => __( 'Two across', 'oc-theme' ),
						'big'    => __( 'Two by two', 'oc-theme' ),
						'whole'  => __( 'Show the whole picture', 'oc-theme' ),
						'crop'   => __( 'Fill the card and crop', 'oc-theme' ),
						'up'     => __( 'Move the picture up', 'oc-theme' ),
						'down'   => __( 'Move the picture down', 'oc-theme' ),
						'middle' => __( 'Back to the middle', 'oc-theme' ),
						'onD'    => __( 'On a desktop', 'oc-theme' ),
						'onM'    => __( 'On a phone', 'oc-theme' ),
						'same'   => __( 'Same as the desktop', 'oc-theme' ),
						'phone'  => __( 'Phone view', 'oc-theme' ),
						'leaveP' => __( 'Back to the desktop view', 'oc-theme' ),
						'pic'    => __( 'Which picture leads the card', 'oc-theme' ),
						'prevP'  => __( 'Previous picture', 'oc-theme' ),
						'nextP'  => __( 'Next picture', 'oc-theme' ),
						'mainP'  => __( 'Back to the product\'s own main image', 'oc-theme' ),
						'saved'  => __( 'Saved', 'oc-theme' ),
						'failed' => __( 'Could not save. Nothing was changed.', 'oc-theme' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The one route behind it.
	 */
	public function rest(): void {
		register_rest_route(
			'oc/v1',
			'/tile',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return current_user_can( self::cap() );
				},
				'callback'            => array( $this, 'save' ),
			)
		);
	}

	/**
	 * Keep what the card was just told.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function save( \WP_REST_Request $req ) {
		$id = absint( $req->get_param( 'id' ) );

		if ( ! $id || 'product' !== get_post_type( $id ) ) {
			return new \WP_Error( 'oc_tile_no_product', __( 'No such product.', 'oc-theme' ), array( 'status' => 404 ) );
		}

		if ( null !== $req->get_param( 'size' ) ) {
			$size = sanitize_key( (string) $req->get_param( 'size' ) );
			$size = array_key_exists( $size, Catalog::sizes() ) ? $size : '';

			if ( '' === $size ) {
				delete_post_meta( $id, '_oc_tile_size' );
			} else {
				update_post_meta( $id, '_oc_tile_size', $size );
			}
		}

		// A phone may be told something of its own, and "" there means it
		// simply does whatever the desktop does.
		if ( null !== $req->get_param( 'size_m' ) ) {
			$size_m = sanitize_key( (string) $req->get_param( 'size_m' ) );
			$size_m = array_key_exists( $size_m, Catalog::sizes_m() ) ? $size_m : '';

			if ( '' === $size_m ) {
				delete_post_meta( $id, '_oc_tile_size_m' );
			} else {
				update_post_meta( $id, '_oc_tile_size_m', $size_m );
			}

			delete_post_meta( $id, '_oc_tile_flat_m' );
		}

		// Whether the card shows the whole picture or crops it. Empty puts
		// the product back under the theme's own judgement.
		if ( null !== $req->get_param( 'fit' ) ) {
			$fit = sanitize_key( (string) $req->get_param( 'fit' ) );
			$fit = array_key_exists( $fit, Catalog::fits() ) ? $fit : '';

			if ( '' === $fit ) {
				delete_post_meta( $id, '_oc_tile_fit' );
			} else {
				update_post_meta( $id, '_oc_tile_fit', $fit );
			}
		}

		// Which picture leads the card: one of the product's own, or 0 for
		// its main image — the same meta the product's Catalogue display
		// tab writes, so the two never disagree.
		if ( null !== $req->get_param( 'image' ) ) {
			$image   = absint( $req->get_param( 'image' ) );
			$product = wc_get_product( $id );
			$own     = $product ? array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) : array();

			if ( $image && ! in_array( $image, $own, true ) ) {
				return new \WP_Error( 'oc_tile_no_image', __( 'That picture does not belong to this product.', 'oc-theme' ), array( 'status' => 400 ) );
			}

			if ( ! $image || ( $product && $image === (int) $product->get_image_id() ) ) {
				delete_post_meta( $id, '_oc_tile_image' );
			} else {
				update_post_meta( $id, '_oc_tile_image', $image );
			}
		}

		if ( null !== $req->get_param( 'focus' ) ) {
			$focus = max( 0, min( 100, absint( $req->get_param( 'focus' ) ) ) );

			if ( 50 === $focus ) {
				delete_post_meta( $id, '_oc_tile_focus' );
			} else {
				update_post_meta( $id, '_oc_tile_focus', $focus );
			}
		}

		Cache::forget_product( $id );

		$tile = Catalog::tile( $id );

		return rest_ensure_response(
			array(
				'size'   => $tile['size'],
				'size_m' => $tile['size_m'],
				'image'  => $tile['image'],
				'focus'  => $tile['focus'],
				'fit'    => $tile['fit'],
			)
		);
	}
}
