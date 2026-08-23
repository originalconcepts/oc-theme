<?php
/**
 * Front-end assets.
 *
 * The old theme enqueued 29 separate JavaScript files on every page — product,
 * checkout, wishlist and variation scripts loaded on the blog — each with a
 * time() version so nothing could be cached, plus Slick from cdnjs, Swiper
 * from jsDelivr and Flickity locally. Three carousel libraries and jQuery.
 *
 * Here: no jQuery, no carousel library, and per-block assets are registered by
 * block.json so they load only where the block appears.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and enqueues theme assets.
 */
final class Assets {

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'front_end' ) );
		add_action( 'wp_head', array( $this, 'design_tokens' ), 1 );
		add_action( 'wp_head', array( $this, 'seo_fallback' ), 2 );
	}

	/**
	 * Meta description and Open Graph tags — only while no SEO plugin runs.
	 * Search and AI crawlers both read these; WooCommerce already prints the
	 * Product JSON-LD.
	 */
	public function seo_fallback(): void {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath\Helper' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
			return;
		}

		$desc = '';
		$url  = home_url( '/' );
		$img  = '';
		$type = 'website';

		if ( is_singular() ) {
			$post_obj = get_queried_object();

			if ( $post_obj instanceof \WP_Post ) {
				$desc = '' !== $post_obj->post_excerpt
					? $post_obj->post_excerpt
					: wp_trim_words( wp_strip_all_tags( $post_obj->post_content ), 28, '…' );
				$url  = (string) get_permalink( $post_obj );
				$img  = (string) get_the_post_thumbnail_url( $post_obj, 'large' );
				$type = 'article';

				if ( function_exists( 'is_product' ) && is_product() ) {
					$type    = 'product';
					$product = wc_get_product( $post_obj->ID );

					if ( $product && '' !== $product->get_short_description() ) {
						$desc = wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 28, '…' );
					}
				}
			}
		} elseif ( is_tax() || is_category() || is_tag() ) {
			$desc = wp_strip_all_tags( term_description() );
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );
				$url  = is_wp_error( $link ) ? $url : $link;
			}
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$url = (string) wc_get_page_permalink( 'shop' );
		}

		if ( '' === trim( $desc ) ) {
			$desc = (string) get_bloginfo( 'description' );
		}

		if ( '' !== trim( $desc ) ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
			printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $desc ) );
		}

		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( wp_get_document_title() ) );
		printf( '<meta property="og:type" content="%s">' . "\n", esc_attr( $type ) );
		printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
		printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
		printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( get_locale() ) );

		if ( '' !== $img ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $img ) );
			echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		}
	}

	/**
	 * Enqueue the front-end bundle.
	 */
	public function front_end(): void {
		$this->fonts();

		$css = oc_asset_min( '/assets/css/theme.css' );
		$js  = oc_asset_min( '/assets/js/theme.js' );

		wp_enqueue_style(
			'oc-theme',
			OC_THEME_URI . $css,
			array(),
			oc_asset_version( $css )
		);

		// The order-received page shares the bundle: its thank-you module
		// (stars, copy button) guards on its own DOM, like the checkout does.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$co = oc_asset_min( '/assets/js/checkout.js' );

			wp_enqueue_script(
				'oc-checkout',
				OC_THEME_URI . $co,
				array( 'oc-theme' ),
				oc_asset_version( $co ),
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
		}

		wp_enqueue_script(
			'oc-theme',
			OC_THEME_URI . $js,
			array(),
			oc_asset_version( $js ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		$fresh_mode     = (string) get_theme_mod( 'oc_catalog_fresh', 'off' );
		$notify_channel = Waitlist::settings()['channel'];
		$notify_missing = array(
			'whatsapp' => __( 'Fill in a phone number.', 'oc-theme' ),
			'email'    => __( 'Fill in an email address.', 'oc-theme' ),
			'both'     => __( 'Fill in a phone number or an email address.', 'oc-theme' ),
		);

		wp_localize_script(
			'oc-theme',
			'ocL10n',
			array(
				'addToCart' => __( 'Add to cart', 'oc-theme' ),
				'loadMore'  => __( 'Show more', 'oc-theme' ),
				'loadPrev'  => __( 'Show previous products', 'oc-theme' ),
				'readMore'  => __( 'Read more', 'oc-theme' ),
				'readLess'  => __( 'Read less', 'oc-theme' ),
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'cartOpenOnAdd' => (int) Cart::settings()['open_on_add'],
				'cartVarPick'   => __( 'Choose an option', 'oc-theme' ),
				'inStock'       => __( 'In stock', 'oc-theme' ),
				'outStock'      => __( 'Out of stock', 'oc-theme' ),
				'varNeed'       => __( 'Please choose %s', 'oc-theme' ),
				'coRequired'    => __( 'Required field', 'oc-theme' ),
				'coEmail'       => __( 'Please enter a valid email address', 'oc-theme' ),
				'coPhone'       => __( 'Please enter a valid phone number', 'oc-theme' ),
				'coPhoneMin'    => (string) absint( \OC\Theme\Checkout::settings()['phone_min'] ?? 0 ),
				'coPhoneMax'    => (string) absint( \OC\Theme\Checkout::settings()['phone_max'] ?? 0 ),
				'coBtnTotal'    => empty( \OC\Theme\Checkout::settings()['btn_total'] ) ? '0' : '1',
				'coSummary'     => empty( \OC\Theme\Checkout::settings()['summary'] ) ? '0' : '1',
				'coSecure'      => __( 'Secure encrypted payment', 'oc-theme' ),
				'coTick'        => __( 'Please tick this box to continue', 'oc-theme' ),
				'coCouponBad'   => __( 'This coupon cannot be applied.', 'oc-theme' ),
				'coTotal'       => __( 'Total', 'oc-theme' ),
				'coItems'       => __( '%d items', 'oc-theme' ),
				'addedToCart'   => __( 'Added to cart', 'oc-theme' ),
				'notifyNonce'   => wp_create_nonce( 'oc_notify' ),
				'notifyTitle'   => __( 'Notify me', 'oc-theme' ),
				'notifyIntro'   => __( 'Sign up and we will let you know the moment this product is back in stock.', 'oc-theme' ),
				'notifyPhone'   => __( 'Phone (WhatsApp)', 'oc-theme' ),
				'notifyEmail'   => __( 'Email address', 'oc-theme' ),
				'notifyButton'  => __( 'Notify me when it is back', 'oc-theme' ),
				'notifyFoot'    => __( 'No spam — a single update about this product only.', 'oc-theme' ),
				'notifyDone'    => __( 'You are on the list — we will let you know the moment it is back.', 'oc-theme' ),
				'notifyMissing' => $notify_missing[ $notify_channel ] ?? $notify_missing['both'],
				'notifyChannel' => $notify_channel,
				'privacyUrl'    => (string) get_privacy_policy_url(),
				'notifyConsentPre'     => __( 'I agree to the ', 'oc-theme' ),
				'notifyConsentLink'    => __( 'privacy policy', 'oc-theme' ),
				'notifyConsentMissing' => __( 'Please confirm the privacy policy to sign up.', 'oc-theme' ),
				'notifyVarPick'        => __( 'Choose the variation you are waiting for', 'oc-theme' ),
				'notifyVarMissing'     => __( 'Choose a variation first.', 'oc-theme' ),
				'notifySigned'         => __( 'You are signed up for an update', 'oc-theme' ),
				'notifySignedSome'     => __( 'You are already signed up for one of the options', 'oc-theme' ),
				'notifySignedOpt'      => __( 'signed up', 'oc-theme' ),
				'notifySignedMsg'  => __( 'You are signed up for an update on this product.', 'oc-theme' ),
				'notifySignedVars' => __( 'Signed up for:', 'oc-theme' ),
				'notifyUnsub'      => __( 'Remove me from the list', 'oc-theme' ),
				'notifyUnsubDone'  => __( 'Removed — we will not send an update.', 'oc-theme' ),
				'notifyMore'       => __( 'Sign up for another variation', 'oc-theme' ),
				'notifyManage'     => __( 'Manage all your alerts in your account', 'oc-theme' ),
				'accountAlertsUrl' => is_user_logged_in() ? wc_get_endpoint_url( 'stock-alerts', '', wc_get_page_permalink( 'myaccount' ) ) : '',
				'isLoggedIn'       => is_user_logged_in() ? 1 : 0,
				'freshMode' => in_array( $fresh_mode, array( 'daily', 'smart' ), true ) ? $fresh_mode : 'off',
				'searchHistMax' => (int) get_theme_mod( 'oc_search_history_max', 8 ),
				/* translators: %s: number of results. */
				'searchFound'   => __( '%s results', 'oc-theme' ),
				'searchAdded'   => __( 'Added', 'oc-theme' ),
				'searchForget'  => __( 'Remove from your searches', 'oc-theme' ),
				'fltInstock' => __( 'In stock only', 'oc-theme' ),
				'fltNone'    => __( 'No products match this combination — try removing one of the filters.', 'oc-theme' ),
				'fltClear'   => __( 'Clear all', 'oc-theme' ),
				/* translators: %s: number of products. */
				'fltResults' => __( '%s results', 'oc-theme' ),
				// Panels start closed; tabs configured to open by default are
				// listed by key (panel ids are tab-{key}).
				'accOpen' => implode(
					',',
					array_merge(
						( Tabs::settings()['short_tab'] && Tabs::settings()['short_open'] ) ? array( 'oc_short' ) : array(),
						( 'tab' === Tabs::settings()['desc_place'] && ! empty( Tabs::settings()['desc_open'] ) ) ? array( 'description' ) : array()
					)
				),
				// The colour-sibling card swap rebuilds the sold-out pieces.
				'oosFlagText'  => get_theme_mod( 'oc_label_stock', false ) ? (string) get_theme_mod( 'oc_label_stock_out', __( 'Out of stock', 'oc-theme' ) ) : '',
				'oosFlagStyle' => WooCommerce::flag_colors( 'oc_label_stock_bg', 'oc_label_stock_tx' ),
				'oosFlagSide'  => 'right' === get_theme_mod( 'oc_label_stock_pos', 'left' ) ? 'right' : 'left',
				/* translators: %d: seconds until the popup closes itself. */
				'notifyClosing'        => __( 'Closes automatically in %d seconds', 'oc-theme' ),
			)
		);
	}

	/**
	 * Emit design tokens as custom properties.
	 *
	 * One small block of CSS derived from a handful of settings, replacing the
	 * 7,460-line inline stylesheet the old theme rebuilt on every request from
	 * 613 get_theme_mod() calls.
	 */
	public function design_tokens(): void {
		$display = (string) get_theme_mod( 'oc_font_display', '' );
		$body    = (string) get_theme_mod( 'oc_font_body', '' );

		$tokens = apply_filters(
			'oc_design_tokens',
			array(
				'--oc-font-body'      => '' !== $body ? '"' . $body . '", system-ui, sans-serif' : 'system-ui, sans-serif',
				'--oc-font-display'   => '' !== $display ? '"' . $display . '", system-ui, sans-serif' : 'inherit',
				'--oc-radius'         => get_theme_mod( 'oc_radius', '8px' ),
				'--oc-density'        => get_theme_mod( 'oc_density', '1' ),
				'--oc-content-width'  => absint( get_theme_mod( 'oc_content_width_px', 1280 ) ) . 'px',
				'--oc-card-ratio'     => (string) get_theme_mod( 'oc_card_ratio', '1/1' ),
				// The same ratio as a bare number, so a tile that spans two
				// columns can be given the height of a one-column tile.
				'--oc-card-ratio-n'   => self::ratio_number( (string) get_theme_mod( 'oc_card_ratio', '1/1' ) ),
				'--oc-card-title-lines'   => (string) max( 1, (int) get_theme_mod( 'oc_card_title_lines', 2 ) ),
				'--oc-card-excerpt-lines' => (string) max( 1, (int) get_theme_mod( 'oc_card_excerpt_lines', 2 ) ),
				'--oc-thumbs-w'       => absint( get_theme_mod( 'oc_gallery_thumb_size', 80 ) ) . 'px',
				'--oc-gimg-h'         => absint( get_theme_mod( 'oc_gallery_img_height_px', 600 ) ) . 'px',
				'--oc-header-h'       => absint( get_theme_mod( 'oc_header_height', 72 ) ) . 'px',
				'--oc-header-bg'      => (string) get_theme_mod( 'oc_header_bg', '' ),
				'--oc-header-tx'      => (string) get_theme_mod( 'oc_header_tx', '' ),
				'--oc-hicon'          => absint( get_theme_mod( 'oc_header_icon_size', 20 ) ) . 'px',
				'--oc-hicon-sw'       => (string) (float) get_theme_mod( 'oc_header_icon_weight', 1.8 ),
				'--oc-logo-h'         => absint( get_theme_mod( 'oc_logo_h', 48 ) ) . 'px',
				'--oc-menu-fs'        => absint( get_theme_mod( 'oc_menu_font_px', 16 ) ) . 'px',
				'--oc-menu-fw'        => (string) absint( get_theme_mod( 'oc_menu_weight', 500 ) ),
				'--oc-menu-tt'        => 'upper' === get_theme_mod( 'oc_menu_case', 'none' ) ? 'uppercase' : 'none',
				// Stored in hundredths of an em so the Customizer can use a spin box.
				'--oc-menu-track'     => ( absint( get_theme_mod( 'oc_menu_track', 0 ) ) / 100 ) . 'em',
				'--oc-menu-gap'       => absint( get_theme_mod( 'oc_menu_gap', 22 ) ) . 'px',
				'--oc-menu-pt'        => absint( get_theme_mod( 'oc_menu_pad_t', 10 ) ) . 'px',
				'--oc-menu-pb'        => absint( get_theme_mod( 'oc_menu_pad_b', 10 ) ) . 'px',
				'--oc-menu-tx'        => (string) get_theme_mod( 'oc_menu_tx', '' ),
				'--oc-menu-tx-h'      => (string) get_theme_mod( 'oc_menu_tx_h', '' ),
				'--oc-menu-bar-bg'    => (string) get_theme_mod( 'oc_menu_bar_bg', '' ),
				'--oc-menu-ul'        => (string) get_theme_mod( 'oc_menu_ul', '' ),
				'--oc-menu-ul-w'      => absint( get_theme_mod( 'oc_menu_ul_w', 2 ) ) . 'px',
				'--oc-menu-step'      => absint( get_theme_mod( 'oc_menu_stagger', 40 ) ) . 'ms',
				'--oc-logo-h-m'       => absint( get_theme_mod( 'oc_logo_h_mobile', 40 ) ) . 'px',
				'--oc-topbar-bg'      => (string) get_theme_mod( 'oc_topbar_bg', '' ),
				'--oc-topbar-tx'      => (string) get_theme_mod( 'oc_topbar_tx', '' ),
				'--oc-footer-bg'      => (string) get_theme_mod( 'oc_footer_bg', '' ),
				'--oc-primary-user'   => (string) get_theme_mod( 'oc_color_primary', '' ),
				'--oc-secondary-user' => (string) get_theme_mod( 'oc_color_secondary', '' ),
				'--oc-sale-user'      => (string) get_theme_mod( 'oc_color_sale', '' ),
				'--oc-cta-user'       => (string) get_theme_mod( 'oc_cta_color', '' ),
				'--oc-cta-h'          => absint( get_theme_mod( 'oc_cta_height', 48 ) ) . 'px',
				'--oc-cta-r'          => (string) get_theme_mod( 'oc_cta_radius', '8px' ),
				'--oc-notify-user'    => (string) get_theme_mod( 'oc_notify_bg', '' ),
				'--oc-notify-tx-user' => (string) get_theme_mod( 'oc_notify_tx', '' ),
				'--oc-swatch'         => absint( get_theme_mod( 'oc_swatch_size', 32 ) ) . 'px',
				'--oc-swatch-cat'     => absint( get_theme_mod( 'oc_swatch_size_cat', 22 ) ) . 'px',
				'--oc-bg-user'        => (string) get_theme_mod( 'oc_bg_color', '' ),
				'--oc-grid-gap'       => (string) get_theme_mod( 'oc_card_gap', '' ),
			)
		);

		$css = '';
		foreach ( $tokens as $name => $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$css .= sprintf( '%s:%s;', $this->safe_property( $name ), $this->safe_value( $value ) );
		}

		$css .= $this->context_overrides();

		if ( '' === $css ) {
			return;
		}

		// Values passed through safe_property()/safe_value(), which strip
		// anything that could escape the declaration. esc_html() here would
		// turn font-name quotes into &quot; and void the declarations — the
		// bug that made the font settings appear dead.
		echo "<style id='oc-tokens'>:root{" . $css . "}</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Page-scoped overrides: catalogue, product and checkout pages may carry
	 * their own width and background, beating the global values.
	 *
	 * @return string Extra declarations, possibly empty.
	 */
	private function context_overrides(): string {
		$width = 0;
		$bg    = '';

		$lh = '';
		$fs = 0;

		if ( function_exists( 'is_product' ) && is_product() ) {
			$width = absint( get_theme_mod( 'oc_product_width_px', 0 ) );
			$bg    = (string) get_theme_mod( 'oc_product_bg', '' );
			$lh    = (string) get_theme_mod( 'oc_product_lh', '' );
			$fs    = absint( get_theme_mod( 'oc_product_fs', 0 ) );

			$colgap = absint( get_theme_mod( 'oc_product_colgap_px', 0 ) );
			if ( $colgap > 0 ) {
				$out_extra = '--oc-prod-colgap:' . $colgap . 'px;';
			}

			$gimg_m = absint( get_theme_mod( 'oc_gallery_img_height_mobile_px', 0 ) );
			if ( $gimg_m > 0 ) {
				$out_extra = ( $out_extra ?? '' ) . '--oc-gimg-h-m:' . $gimg_m . 'px;';
			}
		} elseif ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
			$width = absint( get_theme_mod( 'oc_catalog_width_px', 0 ) );
			$bg    = (string) get_theme_mod( 'oc_catalog_bg', '' );
			$lh    = (string) get_theme_mod( 'oc_catalog_lh', '' );
			$fs    = absint( get_theme_mod( 'oc_catalog_fs', 0 ) );
		} elseif ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) ) {
			$bg = (string) get_theme_mod( 'oc_checkout_bg', '' );
		}

		$out = $out_extra ?? '';
		if ( '' !== $lh ) {
			$out .= '--oc-lh:' . $this->safe_value( $lh ) . ';';
		}
		if ( $fs > 0 ) {
			$out .= '--oc-fs:' . $fs . 'px;';
		}
		if ( $width > 0 ) {
			// The per-page width narrows the CONTENT containers only — the
			// header, top bar and footer hold the global width.
			$out .= '--oc-main-width:' . $width . 'px;';
		}
		if ( '' !== $bg ) {
			$out .= '--oc-bg-user:' . $this->safe_value( $bg ) . ';';
		}

		return $out;
	}

	/**
	 * Load the chosen Google fonts in one request. Hebrew subsets included.
	 */
	private function fonts(): void {
		$families = array_filter(
			array_unique(
				array(
					(string) get_theme_mod( 'oc_font_display', '' ),
					(string) get_theme_mod( 'oc_font_body', '' ),
				)
			)
		);

		if ( empty( $families ) ) {
			return;
		}

		// Self-hosted: no third-party origin, no connection setup, and the
		// hebrew regular of the body font is preloaded so text paints with
		// the right face on the first frame.
		$preloaded = false;

		foreach ( $families as $family ) {
			$slug = strtolower( str_replace( ' ', '-', $family ) );
			$rel  = '/assets/fonts/' . $slug . '.css';

			if ( ! file_exists( OC_THEME_DIR . $rel ) ) {
				continue;
			}

			wp_enqueue_style( 'oc-font-' . $slug, OC_THEME_URI . $rel, array(), oc_asset_version( $rel ) );

			if ( ! $preloaded && file_exists( OC_THEME_DIR . '/assets/fonts/' . $slug . '-400-hebrew.woff2' ) ) {
				$preloaded = true;
				$woff      = OC_THEME_URI . '/assets/fonts/' . $slug . '-400-hebrew.woff2';
				add_action(
					'wp_head',
					static function () use ( $woff ): void {
						echo '<link rel="preload" href="' . esc_url( $woff ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
					},
					2
				);
			}
		}
	}

	/**
	 * Allow only custom-property names.
	 *
	 * @param string $name Property name.
	 * @return string
	 */
	private function safe_property( string $name ): string {
		return (string) preg_replace( '/[^a-z0-9\-]/i', '', $name );
	}

	/**
	 * Strip anything that could break out of a declaration.
	 *
	 * @param string $value Property value.
	 * @return string
	 */
	private function safe_value( string $value ): string {
		return trim( (string) preg_replace( '/[<>{};]/', '', $value ) );
	}

	/**
	 * A CSS ratio token ("3/4") as the number CSS calc() can divide by.
	 *
	 * @param string $ratio Ratio token.
	 */
	private static function ratio_number( string $ratio ): string {
		$parts = array_map( 'trim', explode( '/', str_replace( ' ', '', $ratio ) ) );
		$w     = (float) ( $parts[0] ?? 1 );
		$h     = (float) ( $parts[1] ?? 1 );

		if ( $w <= 0 || $h <= 0 ) {
			return '1';
		}

		return rtrim( rtrim( number_format( $w / $h, 4, '.', '' ), '0' ), '.' );
	}

}
