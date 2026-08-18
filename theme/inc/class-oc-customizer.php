<?php
/**
 * Theme settings.
 *
 * Four panels in the Customizer — global design, catalogue, product card and
 * product page — with drawn preset pickers instead of dropdowns. The measured
 * ceiling from the specification work is ~99 settings across the whole theme
 * (DECISIONS.md #5); every setting here carries a sanitiser and a default that
 * matches the approved mockup.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Registers panels, sections and settings.
 */
final class Customizer {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'customize_register', array( $this, 'build' ) );
	}

	/**
	 * Register everything.
	 *
	 * @param \WP_Customize_Manager $wp_customize Customizer manager.
	 */
	public function build( \WP_Customize_Manager $wp_customize ): void {
		require_once OC_THEME_DIR . '/inc/class-oc-preset-control.php';
		require_once OC_THEME_DIR . '/inc/class-oc-segmented-control.php';
		require_once OC_THEME_DIR . '/inc/class-oc-toggle-control.php';

		// Shop sections live inside WooCommerce's own Customizer panel, which
		// is where a shop owner looks for them. Global design stays top-level
		// because it applies to the whole site, not just the shop.
		$shop_panel = $wp_customize->get_panel( 'woocommerce' ) ? 'woocommerce' : '';

		$this->design_panel( $wp_customize );
		$this->header_section( $wp_customize );
		$this->topbar_section( $wp_customize );
		$this->footer_section( $wp_customize );
		$this->catalog_panel( $wp_customize, $shop_panel );
		$this->card_section( $wp_customize, $shop_panel );
		$this->product_section( $wp_customize, $shop_panel );
		$this->swatches_section( $wp_customize, $shop_panel );
		$this->labels_section( $wp_customize, $shop_panel );
	}

	/**
	 * Header: preset, behaviour, icons.
	 *
	 * @param \WP_Customize_Manager $c Customizer manager.
	 */
	private function header_section( \WP_Customize_Manager $c ): void {
		$c->add_section(
			'oc_header',
			array(
				'title'       => __( 'Header', 'oc-theme' ),
				'description' => __( 'The logo comes from Site Identity; menus from the Menus screen.', 'oc-theme' ),
				'priority'    => 11,
			)
		);

		$this->preset(
			$c,
			'oc_header_preset',
			'oc_header',
			__( 'Header layout', 'oc-theme' ),
			array(
				'classic'     => array(
					'label' => __( 'Logo and menu together', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 36', self::rect( 4, 12, 26, 12, 'ac', 2 ) . self::rect( 36, 15, 14, 5, 'ln' ) . self::rect( 54, 15, 14, 5, 'ln' ) . self::rect( 72, 15, 14, 5, 'ln' ) . self::rect( 112, 14, 7, 7, 'dt', 3.5 ) . self::rect( 122, 14, 7, 7, 'dt', 3.5 ) ),
				),
				'menu-center' => array(
					'label' => __( 'Menu in the centre', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 36', self::rect( 4, 12, 26, 12, 'ac', 2 ) . self::rect( 48, 15, 12, 5, 'ln' ) . self::rect( 64, 15, 12, 5, 'ln' ) . self::rect( 80, 15, 12, 5, 'ln' ) . self::rect( 112, 14, 7, 7, 'dt', 3.5 ) . self::rect( 122, 14, 7, 7, 'dt', 3.5 ) ),
				),
				'centred'     => array(
					'label' => __( 'Logo above the menu', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 36', self::rect( 53, 3, 26, 11, 'ac', 2 ) . self::rect( 36, 22, 14, 5, 'ln' ) . self::rect( 54, 22, 14, 5, 'ln' ) . self::rect( 72, 22, 14, 5, 'ln' ) . self::rect( 92, 22, 14, 5, 'ln' ) ),
				),
				'split'       => array(
					'label' => __( 'Menu, logo in the centre, icons', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 36', self::rect( 4, 15, 12, 5, 'ln' ) . self::rect( 20, 15, 12, 5, 'ln' ) . self::rect( 36, 15, 12, 5, 'ln' ) . self::rect( 53, 12, 26, 12, 'ac', 2 ) . self::rect( 112, 14, 7, 7, 'dt', 3.5 ) . self::rect( 122, 14, 7, 7, 'dt', 3.5 ) ),
				),
				'burger'      => array(
					'label' => __( 'Hamburger, logo in the centre', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 36', self::rect( 5, 12, 12, 2.4, 'ln' ) . self::rect( 5, 17, 12, 2.4, 'ln' ) . self::rect( 5, 22, 12, 2.4, 'ln' ) . self::rect( 53, 12, 26, 12, 'ac', 2 ) . self::rect( 112, 14, 7, 7, 'dt', 3.5 ) . self::rect( 122, 14, 7, 7, 'dt', 3.5 ) ),
				),
			),
			'classic',
			'200px'
		);

		$this->preset(
			$c,
			'oc_header_mobile',
			'oc_header',
			__( 'Header layout — mobile', 'oc-theme' ),
			array(
				'plain'    => array(
					'label' => __( 'Hamburger and logo together', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 36', self::rect( 118, 12, 9, 2.2, 'ln' ) . self::rect( 118, 16.5, 9, 2.2, 'ln' ) . self::rect( 118, 21, 9, 2.2, 'ln' ) . self::rect( 88, 12, 24, 11, 'ac', 2 ) . self::rect( 6, 14, 7, 7, 'dt', 3.5 ) . self::rect( 16, 14, 7, 7, 'dt', 3.5 ) ),
				),
				'centered' => array(
					'label' => __( 'Logo in the centre', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 36', self::rect( 118, 12, 9, 2.2, 'ln' ) . self::rect( 118, 16.5, 9, 2.2, 'ln' ) . self::rect( 118, 21, 9, 2.2, 'ln' ) . self::rect( 105, 14, 7, 7, 'dt', 3.5 ) . self::rect( 54, 12, 24, 11, 'ac', 2 ) . self::rect( 6, 14, 7, 7, 'dt', 3.5 ) . self::rect( 16, 14, 7, 7, 'dt', 3.5 ) ),
				),
			),
			'plain',
			'200px'
		);

		$this->toggle( $c, 'oc_header_sticky', 'oc_header', __( 'Sticky header', 'oc-theme' ), true );

		$this->choice(
			$c,
			'oc_header_height',
			'oc_header',
			__( 'Header height', 'oc-theme' ),
			array(
				'60' => __( 'Compact', 'oc-theme' ),
				'72' => __( 'Regular', 'oc-theme' ),
				'88' => __( 'Tall', 'oc-theme' ),
			),
			'72'
		);

		$this->number( $c, 'oc_logo_h', 'oc_header', __( 'Logo height — desktop (px)', 'oc-theme' ), 48, 20, 120 );
		$this->number( $c, 'oc_logo_h_mobile', 'oc_header', __( 'Logo height — mobile (px)', 'oc-theme' ), 40, 16, 100 );
		$this->number( $c, 'oc_menu_font_px', 'oc_header', __( 'Menu text size (px)', 'oc-theme' ), 16, 12, 24 );

		$this->color( $c, 'oc_header_bg', 'oc_header', __( 'Header background', 'oc-theme' ) );
		$this->color( $c, 'oc_header_tx', 'oc_header', __( 'Header text and icons colour', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_header_transparent',
			'oc_header',
			__( 'Transparent header over the content', 'oc-theme' ),
			array(
				'none' => __( 'Off', 'oc-theme' ),
				'home' => __( 'Homepage only', 'oc-theme' ),
				'all'  => __( 'Whole site', 'oc-theme' ),
			),
			'none'
		);

		$this->toggle( $c, 'oc_header_border', 'oc_header', __( 'Bottom border line', 'oc-theme' ), true );
		$this->toggle( $c, 'oc_header_search', 'oc_header', __( 'Search icon', 'oc-theme' ), true );
		$this->toggle( $c, 'oc_header_account', 'oc_header', __( 'Account icon', 'oc-theme' ), true );
		$this->toggle( $c, 'oc_header_cart', 'oc_header', __( 'Cart icon with counter', 'oc-theme' ), true );

		$this->preset(
			$c,
			'oc_header_cart_icon',
			'oc_header',
			__( 'Cart icon style', 'oc-theme' ),
			array(
				'cart'   => array(
					'label' => __( 'Cart', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="68" viewBox="0 0 48 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="none" stroke="#2271b1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" transform="translate(12 5)"><circle cx="9" cy="20" r="1.6"/><circle cx="17" cy="20" r="1.6"/><path d="M3 3h2.5l2.2 11.2a1.6 1.6 0 0 0 1.6 1.3h7.6a1.6 1.6 0 0 0 1.6-1.3L20 7H6"/></g></svg>',
				),
				'bag'    => array(
					'label' => __( 'Bag', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="68" viewBox="0 0 48 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="none" stroke="#2271b1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" transform="translate(12 5)"><path d="M5 8h14l-1.2 12.2a1.8 1.8 0 0 1-1.8 1.6H8a1.8 1.8 0 0 1-1.8-1.6Z"/><path d="M8.5 10V6.5a3.5 3.5 0 0 1 7 0V10"/></g></svg>',
				),
				'basket' => array(
					'label' => __( 'Basket', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="68" viewBox="0 0 48 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="none" stroke="#2271b1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" transform="translate(12 5)"><path d="M3.5 10h17l-1.6 9a2 2 0 0 1-2 1.6H7.1a2 2 0 0 1-2-1.6Z"/><path d="m8 10 3-6.5M16 10l-3-6.5"/><path d="M9.5 13.5v3.5M14.5 13.5v3.5"/></g></svg>',
				),
				'boni'   => array(
					'label' => __( 'Square bag', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="68" viewBox="0 0 48 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="none" stroke="#2271b1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" transform="translate(12 5)"><path d="M20.5 20.5h-17V7h17Z"/><path d="M8.6 7v-.4a3.4 3.4 0 0 1 6.8 0V7"/></g></svg>',
				),
				'amox'   => array(
					'label' => __( 'Rounded bag', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="68" viewBox="0 0 48 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="none" stroke="#2271b1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" transform="translate(12 5)"><path d="M4.4 8.2h15.2l-1.4 10.9a2 2 0 0 1-2 1.7H7.8a2 2 0 0 1-2-1.7Z"/><path d="M7.5 8.2a4.5 4.5 0 0 1 9 0"/></g></svg>',
				),
			),
			'cart',
			'128px'
		);

		$this->choice(
			$c,
			'oc_header_icon_size',
			'oc_header',
			__( 'Icon size', 'oc-theme' ),
			array(
				'18' => __( 'Small', 'oc-theme' ),
				'20' => __( 'Regular', 'oc-theme' ),
				'24' => __( 'Large', 'oc-theme' ),
			),
			'20'
		);

		$this->choice(
			$c,
			'oc_header_icon_weight',
			'oc_header',
			__( 'Icon stroke weight', 'oc-theme' ),
			array(
				'1.4' => __( 'Thin', 'oc-theme' ),
				'1.8' => __( 'Regular', 'oc-theme' ),
				'2.2' => __( 'Bold', 'oc-theme' ),
			),
			'1.8'
		);

		$this->choice(
			$c,
			'oc_header_search_style',
			'oc_header',
			__( 'Search — desktop', 'oc-theme' ),
			array(
				'icon'  => __( 'Icon that opens a bar', 'oc-theme' ),
				'field' => __( 'Inline search field', 'oc-theme' ),
			),
			'icon'
		);

		$this->choice(
			$c,
			'oc_header_icons_style',
			'oc_header',
			__( 'Account, search and cart — desktop', 'oc-theme' ),
			array(
				'icons' => __( 'Icons', 'oc-theme' ),
				'text'  => __( 'Text links', 'oc-theme' ),
			),
			'icons'
		);
	}

	/**
	 * Top bar: rotating messages above the header.
	 *
	 * @param \WP_Customize_Manager $c Customizer manager.
	 */
	private function topbar_section( \WP_Customize_Manager $c ): void {
		$c->add_section(
			'oc_topbar',
			array(
				'title'       => __( 'Top bar', 'oc-theme' ),
				'description' => __( 'A strip above the header: rotating messages in the centre, an optional side menu (assign it in the Menus screen).', 'oc-theme' ),
				'priority'    => 11,
			)
		);

		$this->toggle( $c, 'oc_topbar', 'oc_topbar', __( 'Show the top bar', 'oc-theme' ), false );

		$this->text( $c, 'oc_topbar_msg1', 'oc_topbar', __( 'Message 1', 'oc-theme' ) );
		$this->text( $c, 'oc_topbar_msg2', 'oc_topbar', __( 'Message 2', 'oc-theme' ) );
		$this->text( $c, 'oc_topbar_msg3', 'oc_topbar', __( 'Message 3', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_topbar_effect',
			'oc_topbar',
			__( 'Message transition', 'oc-theme' ),
			array(
				'fade'  => __( 'Fade', 'oc-theme' ),
				'slide' => __( 'Slide', 'oc-theme' ),
			),
			'fade'
		);

		$this->color( $c, 'oc_topbar_bg', 'oc_topbar', __( 'Top bar background', 'oc-theme' ) );
		$this->color( $c, 'oc_topbar_tx', 'oc_topbar', __( 'Top bar text colour', 'oc-theme' ) );
	}

	/**
	 * Footer: layout, colours, credit line.
	 *
	 * @param \WP_Customize_Manager $c Customizer manager.
	 */
	private function footer_section( \WP_Customize_Manager $c ): void {
		$c->add_section(
			'oc_footer',
			array(
				'title'       => __( 'Footer', 'oc-theme' ),
				'description' => __( 'Content columns are widget areas — fill them under Appearance → Widgets.', 'oc-theme' ),
				'priority'    => 12,
			)
		);

		$this->choice(
			$c,
			'oc_footer_layout',
			'oc_footer',
			__( 'Bottom bar layout', 'oc-theme' ),
			array(
				'inline'   => __( 'Menu and credit on one line', 'oc-theme' ),
				'centered' => __( 'Centred, stacked', 'oc-theme' ),
			),
			'inline'
		);

		$this->color( $c, 'oc_footer_bg', 'oc_footer', __( 'Footer background', 'oc-theme' ) );
		$this->text( $c, 'oc_footer_credit', 'oc_footer', __( 'Credit line (empty = © year and site name)', 'oc-theme' ) );
	}

	/*
	 * Panels and sections.
	 */

	/**
	 * Global design: fonts, radius, density, widths.
	 *
	 * @param \WP_Customize_Manager $c Customizer manager.
	 */
	private function design_panel( \WP_Customize_Manager $c ): void {
		$c->add_section(
			'oc_design',
			array(
				'title'    => __( 'Global design', 'oc-theme' ),
				'priority' => 10,
			)
		);

		$this->choice(
			$c,
			'oc_radius',
			'oc_design',
			__( 'Corner rounding', 'oc-theme' ),
			array(
				'0px'  => __( 'Sharp', 'oc-theme' ),
				'8px'  => __( 'Soft', 'oc-theme' ),
				'16px' => __( 'Round', 'oc-theme' ),
			),
			'8px'
		);

		$this->choice(
			$c,
			'oc_density',
			'oc_design',
			__( 'Spacing density', 'oc-theme' ),
			array(
				'0.8' => __( 'Compact', 'oc-theme' ),
				'1'   => __( 'Regular', 'oc-theme' ),
				'1.2' => __( 'Airy', 'oc-theme' ),
			),
			'1'
		);

		$density_control = $c->get_control( 'oc_density' );
		if ( null !== $density_control ) {
			$density_control->description = __( 'Page margins, grid gaps and card padding. Text size is not affected.', 'oc-theme' );
		}

		$this->number( $c, 'oc_content_width_px', 'oc_design', __( 'Content width (px)', 'oc-theme' ), 1280, 960, 1920 );

		$this->color( $c, 'oc_color_primary', 'oc_design', __( 'Primary colour', 'oc-theme' ) );
		$this->color( $c, 'oc_color_secondary', 'oc_design', __( 'Secondary colour', 'oc-theme' ) );
		$this->color( $c, 'oc_color_sale', 'oc_design', __( 'Sale colour', 'oc-theme' ) );
		$this->color( $c, 'oc_bg_color', 'oc_design', __( 'Site background colour', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_button_style',
			'oc_design',
			__( 'Button style', 'oc-theme' ),
			array(
				'filled'  => __( 'Filled', 'oc-theme' ),
				'outline' => __( 'Outline', 'oc-theme' ),
			),
			'filled'
		);

		// Call-to-action buttons: add to cart, proceed to checkout, place
		// order — one look for the whole funnel. Their corners follow the
		// global corner-rounding above.
		$this->heading( $c, 'oc_h_design_cta', 'oc_design', __( 'Call-to-action buttons', 'oc-theme' ) );

		$this->color( $c, 'oc_cta_color', 'oc_design', __( 'Call-to-action colour (empty = primary)', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_cta_hover',
			'oc_design',
			__( 'Hover effect', 'oc-theme' ),
			array(
				'none'        => __( 'None', 'oc-theme' ),
				'invert'      => __( 'Negative', 'oc-theme' ),
				'sweep-ltr'   => __( 'Negative, left to right', 'oc-theme' ),
				'sweep-rtl'   => __( 'Negative, right to left', 'oc-theme' ),
			),
			'none'
		);

		$this->choice(
			$c,
			'oc_cta_radius',
			'oc_design',
			__( 'Button corners', 'oc-theme' ),
			array(
				'0px'   => __( 'Sharp', 'oc-theme' ),
				'8px'   => __( 'Soft', 'oc-theme' ),
				'999px' => __( 'Round (pill)', 'oc-theme' ),
			),
			'8px'
		);

		$this->number( $c, 'oc_cta_height', 'oc_design', __( 'Button height (px)', 'oc-theme' ), 48, 40, 64 );

		$fonts = array(
			''             => __( 'System', 'oc-theme' ),
			'Assistant'    => 'Assistant',
			'Heebo'        => 'Heebo',
			'Rubik'        => 'Rubik',
			'Varela Round' => 'Varela Round',
			'Secular One'  => 'Secular One',
		);

		$this->select( $c, 'oc_font_display', 'oc_design', __( 'Heading font', 'oc-theme' ), $fonts );
		$this->select( $c, 'oc_font_body', 'oc_design', __( 'Body font', 'oc-theme' ), $fonts );
	}

	/**
	 * Catalogue: grid, ordering, page header.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Parent panel id.
	 */
	private function catalog_panel( \WP_Customize_Manager $c, string $panel ): void {
		// WooCommerce already has a "Product Catalog" section — our catalogue
		// controls join it under the plugin's own, rather than a second
		// near-identical section. Falls back to our own section without Woo.
		if ( $c->get_section( 'woocommerce_product_catalog' ) ) {
			$section = 'woocommerce_product_catalog';
		} else {
			$section = 'oc_catalog';
			$c->add_section(
				'oc_catalog',
				array(
					'title'    => __( 'Catalogue & archive', 'oc-theme' ),
					'panel'    => $panel,
					'priority' => 8,
				)
			);
		}

		$this->heading( $c, 'oc_h_cat_structure', $section, __( 'Page structure', 'oc-theme' ) );

		$this->number( $c, 'oc_catalog_width_px', $section, __( 'Page width override (0 = inherit)', 'oc-theme' ), 0, 0, 1920 );
		$this->color( $c, 'oc_catalog_bg', $section, __( 'Page background override', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_breadcrumbs_pos',
			$section,
			__( 'Breadcrumbs position', 'oc-theme' ),
			array(
				'above'  => __( 'Above the title', 'oc-theme' ),
				'below'  => __( 'Below the title', 'oc-theme' ),
				'hidden' => __( 'Hidden', 'oc-theme' ),
			),
			'above'
		);

		$this->choice(
			$c,
			'oc_catalog_title_align',
			$section,
			__( 'Title alignment', 'oc-theme' ),
			array(
				'start'  => __( 'Start', 'oc-theme' ),
				'center' => __( 'Centre', 'oc-theme' ),
			),
			'start'
		);

		$this->choice(
			$c,
			'oc_catalog_desc_pos',
			$section,
			__( 'Category description position', 'oc-theme' ),
			array(
				'top'    => __( 'Under the title', 'oc-theme' ),
				'bottom' => __( 'Under the products', 'oc-theme' ),
			),
			'top'
		);

		$this->number( $c, 'oc_catalog_fs', $section, __( 'Body text size (px)', 'oc-theme' ), 16, 12, 20 );

		$this->choice(
			$c,
			'oc_catalog_lh',
			$section,
			__( 'Body text line height', 'oc-theme' ),
			array(
				'1.4'  => __( 'Tight', 'oc-theme' ),
				'1.55' => __( 'Regular', 'oc-theme' ),
				'1.75' => __( 'Loose', 'oc-theme' ),
			),
			'1.55'
		);

		$this->heading( $c, 'oc_h_cat_grid', $section, __( 'Product grid', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_catalog_cols',
			$section,
			__( 'Products per row — desktop', 'oc-theme' ),
			array(
				'2' => '2',
				'3' => '3',
				'4' => '4',
				'5' => '5',
			),
			'4'
		);

		$this->choice(
			$c,
			'oc_catalog_cols_mobile',
			$section,
			__( 'Products per row — mobile', 'oc-theme' ),
			array(
				'1' => '1',
				'2' => '2',
				'3' => '3',
			),
			'2'
		);

		$this->choice(
			$c,
			'oc_card_gap',
			$section,
			__( 'Space between products', 'oc-theme' ),
			array(
				'0px'  => __( 'None', 'oc-theme' ),
				'8px'  => __( 'Small', 'oc-theme' ),
				'20px' => __( 'Regular', 'oc-theme' ),
				'32px' => __( 'Large', 'oc-theme' ),
			),
			'20px'
		);

		$this->choice(
			$c,
			'oc_catalog_products_width',
			$section,
			__( 'Products area width', 'oc-theme' ),
			array(
				'page' => __( 'Page width', 'oc-theme' ),
				'full' => __( 'Edge to edge', 'oc-theme' ),
			),
			'page'
		);

		$this->heading( $c, 'oc_h_cat_paging', $section, __( 'Loading & paging', 'oc-theme' ) );

		$this->number( $c, 'oc_catalog_per_page', $section, __( 'Products per page (-1 shows all)', 'oc-theme' ), 24, -1, 200 );

		$this->choice(
			$c,
			'oc_catalog_paging',
			$section,
			__( 'Loading more products', 'oc-theme' ),
			array(
				'numbers'   => __( 'Page numbers', 'oc-theme' ),
				'load-more' => __( '"Show more" button', 'oc-theme' ),
				'infinite'  => __( 'Automatic on scroll', 'oc-theme' ),
			),
			'numbers'
		);

		$this->choice(
			$c,
			'oc_paging_shape',
			$section,
			__( 'Page numbers shape', 'oc-theme' ),
			array(
				'circle' => __( 'Circles', 'oc-theme' ),
				'square' => __( 'Squares', 'oc-theme' ),
			),
			'circle'
		);

	}

	/**
	 * All swatch settings in one dedicated section — product page and
	 * catalogue side by side.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Parent panel id.
	 */
	private function swatches_section( \WP_Customize_Manager $c, string $panel ): void {
		$c->add_section(
			'oc_swatches',
			array(
				'title'    => __( 'Colour swatches', 'oc-theme' ),
				'panel'    => $panel,
				'priority' => 11,
			)
		);

		$this->heading( $c, 'oc_h_sw_product', 'oc_swatches', __( 'Product page', 'oc-theme' ) );

		$this->number( $c, 'oc_swatch_size', 'oc_swatches', __( 'Swatch size — product page (px)', 'oc-theme' ), 32, 20, 56 );

		$this->choice(
			$c,
			'oc_swatch_shape',
			'oc_swatches',
			__( 'Swatch shape', 'oc-theme' ),
			array(
				'circle' => __( 'Round', 'oc-theme' ),
				'square' => __( 'Square', 'oc-theme' ),
			),
			'circle'
		);

		$this->toggle( $c, 'oc_swatch_check', 'oc_swatches', __( 'Check mark on the selected swatch', 'oc-theme' ), true );

		$this->heading( $c, 'oc_h_sw_cat', 'oc_swatches', __( 'Catalogue', 'oc-theme' ) );

		$this->number( $c, 'oc_swatch_size_cat', 'oc_swatches', __( 'Swatch size — catalogue (px)', 'oc-theme' ), 22, 14, 40 );

		$this->choice(
			$c,
			'oc_swatch_shape_cat',
			'oc_swatches',
			__( 'Swatch shape — catalogue', 'oc-theme' ),
			array(
				'circle' => __( 'Round', 'oc-theme' ),
				'square' => __( 'Square', 'oc-theme' ),
			),
			'circle'
		);

		$this->choice(
			$c,
			'oc_colors_loop_pos',
			'oc_swatches',
			__( 'Colour swatches on the card', 'oc-theme' ),
			array(
				'above' => __( 'Above the title', 'oc-theme' ),
				'below' => __( 'Below the content', 'oc-theme' ),
			),
			'below'
		);
	}

	/**
	 * Product card: preset, image behaviour, contents.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Parent panel id.
	 */
	private function card_section( \WP_Customize_Manager $c, string $panel ): void {
		$c->add_section(
			'oc_card',
			array(
				'title'    => __( 'Product card', 'oc-theme' ),
				'panel'    => $panel,
				'priority' => 9,
			)
		);

		$this->preset(
			$c,
			'oc_card_preset',
			'oc_card',
			__( 'Card preset', 'oc-theme' ),
			array(
				'minimal'        => array(
					'label' => __( 'Minimal', 'oc-theme' ),
					'hint'  => __( 'No border, start-aligned', 'oc-theme' ),
					'svg'   => self::wf( '0 0 80 100', self::rect( 3, 3, 74, 62, 'im', 2 ) . self::rect( 45, 72, 32, 3.4, 'ln' ) . self::rect( 59, 80, 18, 3.4, 'ac' ) ),
				),
				'minimal-center' => array(
					'label' => __( 'Minimal centred', 'oc-theme' ),
					'hint'  => __( 'No border, centred', 'oc-theme' ),
					'svg'   => self::wf( '0 0 80 100', self::rect( 3, 3, 74, 62, 'im', 2 ) . self::rect( 24, 72, 32, 3.4, 'ln' ) . self::rect( 31, 80, 18, 3.4, 'ac' ) ),
				),
				'classic'        => array(
					'label' => __( 'Classic', 'oc-theme' ),
					'hint'  => __( 'Border, centred info', 'oc-theme' ),
					'svg'   => self::wf( '0 0 80 100', self::rect( 1, 1, 78, 98, 'bd', 3 ) . self::rect( 7, 7, 66, 58, 'im', 2 ) . self::rect( 24, 72, 32, 3.4, 'ln' ) . self::rect( 31, 80, 18, 3.4, 'ac' ) ),
				),
				'card'           => array(
					'label' => __( 'Card', 'oc-theme' ),
					'hint'  => __( 'Background, shadow, button', 'oc-theme' ),
					'svg'   => self::wf( '0 0 80 100', self::rect( 3, 3, 74, 94, 'bd', 7 ) . self::rect( 9, 9, 62, 52, 'im', 3 ) . self::rect( 24, 68, 32, 3.4, 'ln' ) . self::rect( 18, 84, 44, 9, 'ac', 4 ) ),
				),
			),
			'classic',
			'150px'
		);

		$this->preset(
			$c,
			'oc_card_image_mode',
			'oc_card',
			__( 'Image behaviour', 'oc-theme' ),
			array(
				'single'  => array(
					'label' => __( 'Single image', 'oc-theme' ),
					'svg'   => self::wf( '0 0 80 62', self::rect( 6, 5, 68, 52, 'im', 3 ) ),
				),
				'hover'   => array(
					'label' => __( 'Swap on hover', 'oc-theme' ),
					'svg'   => self::wf( '0 0 80 62', self::rect( 12, 3, 64, 50, 'bd', 3 ) . self::rect( 4, 9, 64, 50, 'im', 3 ) ),
				),
				'gallery' => array(
					'label' => __( 'Scrollable gallery', 'oc-theme' ),
					'hint'  => __( 'Browse product images on the card', 'oc-theme' ),
					'svg'   => self::wf( '0 0 80 62', self::rect( 6, 3, 68, 44, 'im', 3 ) . self::dots( 40, 55, 4 ) ),
				),
			),
			'single',
			'162px'
		);

		$this->number(
			$c,
			'oc_card_gallery_max',
			'oc_card',
			__( 'Gallery: max images', 'oc-theme' ),
			4,
			2,
			8,
			static function (): bool {
				return 'gallery' === get_theme_mod( 'oc_card_image_mode', 'single' );
			}
		);

		$this->choice(
			$c,
			'oc_card_ratio',
			'oc_card',
			__( 'Image ratio', 'oc-theme' ),
			array(
				'1/1' => '1:1',
				'3/4' => '3:4',
				'4/3' => '4:3',
			),
			'1/1'
		);

		$this->choice(
			$c,
			'oc_card_atc',
			'oc_card',
			__( 'Add to cart button', 'oc-theme' ),
			array(
				'always' => __( 'Always visible', 'oc-theme' ),
				'hover'  => __( 'On hover', 'oc-theme' ),
				'none'   => __( 'Hidden', 'oc-theme' ),
			),
			'always'
		);

		$this->choice(
			$c,
			'oc_card_atc_mobile',
			'oc_card',
			__( 'Add to cart button — mobile', 'oc-theme' ),
			array(
				'none'   => __( 'Hidden', 'oc-theme' ),
				'always' => __( 'Always visible', 'oc-theme' ),
			),
			'none'
		);

		$this->toggle( $c, 'oc_card_excerpt', 'oc_card', __( 'Show short description', 'oc-theme' ), false );
	}

	/**
	 * Labels: everything that rides the catalogue card — sale badge, stock
	 * labels, "new", and the bottom strip — in one place.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Parent panel id.
	 */
	private function labels_section( \WP_Customize_Manager $c, string $panel ): void {
		$c->add_section(
			'oc_labels',
			array(
				'title'    => __( 'Labels', 'oc-theme' ),
				'panel'    => $panel,
				'priority' => 12,
			)
		);

		$this->heading( $c, 'oc_h_lbl_sale', 'oc_labels', __( 'Sale badge', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_label_sale_pos',
			'oc_labels',
			__( 'Sale badge side', 'oc-theme' ),
			array(
				'left'  => __( 'Left', 'oc-theme' ),
				'right' => __( 'Right', 'oc-theme' ),
			),
			'left'
		);

		$this->choice(
			$c,
			'oc_card_sale_badge',
			'oc_labels',
			__( 'Sale badge', 'oc-theme' ),
			array(
				'none'    => __( 'None', 'oc-theme' ),
				'percent' => __( 'Percent off', 'oc-theme' ),
				'text'    => __( 'Text', 'oc-theme' ),
			),
			'percent'
		);

		$this->choice(
			$c,
			'oc_sale_badge_style',
			'oc_labels',
			__( 'Sale badge style', 'oc-theme' ),
			array(
				'badge' => __( 'Badge', 'oc-theme' ),
				'plain' => __( 'Text only, no background', 'oc-theme' ),
			),
			'badge'
		);

		$this->color( $c, 'oc_sale_badge_bg', 'oc_labels', __( 'Sale badge background', 'oc-theme' ) );
		$this->color( $c, 'oc_sale_badge_tx', 'oc_labels', __( 'Sale badge text colour', 'oc-theme' ) );

		$this->heading( $c, 'oc_h_lbl_stock', 'oc_labels', __( 'Stock labels', 'oc-theme' ) );

		$this->toggle( $c, 'oc_label_stock', 'oc_labels', __( 'Show stock labels', 'oc-theme' ), false );

		$this->choice(
			$c,
			'oc_label_stock_pos',
			'oc_labels',
			__( 'Stock labels side', 'oc-theme' ),
			array(
				'left'  => __( 'Left', 'oc-theme' ),
				'right' => __( 'Right', 'oc-theme' ),
			),
			'left'
		);
		$this->text( $c, 'oc_label_stock_last', 'oc_labels', __( 'Last-one text', 'oc-theme' ) );
		$this->text( $c, 'oc_label_stock_low', 'oc_labels', __( 'Last-items text', 'oc-theme' ) );
		$this->text( $c, 'oc_label_stock_out', 'oc_labels', __( 'Out-of-stock text', 'oc-theme' ) );
		$this->color( $c, 'oc_label_stock_bg', 'oc_labels', __( 'Stock label background', 'oc-theme' ) );
		$this->color( $c, 'oc_label_stock_tx', 'oc_labels', __( 'Stock label text colour', 'oc-theme' ) );

		$this->heading( $c, 'oc_h_lbl_new', 'oc_labels', __( '"New" label', 'oc-theme' ) );

		$this->toggle( $c, 'oc_label_new', 'oc_labels', __( 'Show the "new" label', 'oc-theme' ), false );

		$this->choice(
			$c,
			'oc_label_new_pos',
			'oc_labels',
			__( '"New" label side', 'oc-theme' ),
			array(
				'left'  => __( 'Left', 'oc-theme' ),
				'right' => __( 'Right', 'oc-theme' ),
			),
			'left'
		);
		$this->number( $c, 'oc_label_new_days', 'oc_labels', __( 'How long a product counts as new (days)', 'oc-theme' ), 30, 1, 365 );
		$this->text( $c, 'oc_label_new_text', 'oc_labels', __( '"New" text', 'oc-theme' ) );
		$this->color( $c, 'oc_label_new_bg', 'oc_labels', __( '"New" background', 'oc-theme' ) );
		$this->color( $c, 'oc_label_new_tx', 'oc_labels', __( '"New" text colour', 'oc-theme' ) );

		$this->heading( $c, 'oc_h_lbl_strip', 'oc_labels', __( 'Bottom strip', 'oc-theme' ) );

		$this->toggle( $c, 'oc_label_strip', 'oc_labels', __( 'Show the bottom strip', 'oc-theme' ), false );
		$this->number( $c, 'oc_label_strip_buy_min', 'oc_labels', __( 'Show "in demand" from this many purchases', 'oc-theme' ), 10, 1, 10000 );
		$this->text( $c, 'oc_label_strip_buy_text', 'oc_labels', __( '"In demand" text (%d = the number)', 'oc-theme' ) );
		$this->number( $c, 'oc_label_strip_cart_min', 'oc_labels', __( 'Show "great choice" from this many cart adds', 'oc-theme' ), 50, 1, 10000 );
		$this->text( $c, 'oc_label_strip_cart_text', 'oc_labels', __( '"Great choice" text (%d = the number)', 'oc-theme' ) );
		$this->color( $c, 'oc_label_strip_bg', 'oc_labels', __( 'Strip background', 'oc-theme' ) );
		$this->color( $c, 'oc_label_strip_tx', 'oc_labels', __( 'Strip text colour', 'oc-theme' ) );

		$this->heading( $c, 'oc_h_lbl_notify', 'oc_labels', __( 'Back-in-stock buttons', 'oc-theme' ) );

		$this->color( $c, 'oc_notify_bg', 'oc_labels', __( 'Notify colour (empty = CTA colour)', 'oc-theme' ) );
		$this->color( $c, 'oc_notify_tx', 'oc_labels', __( 'Notify text colour (empty = white)', 'oc-theme' ) );
	}

	/**
	 * Product page: layout, gallery, buy area.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Parent panel id.
	 */
	private function product_section( \WP_Customize_Manager $c, string $panel ): void {
		$c->add_section(
			'oc_product',
			array(
				'title'    => __( 'Product page', 'oc-theme' ),
				'panel'    => $panel,
				'priority' => 10,
			)
		);

		$this->heading( $c, 'oc_h_prod_layout', 'oc_product', __( 'Layout', 'oc-theme' ) );

		$this->number( $c, 'oc_product_width_px', 'oc_product', __( 'Page width override (0 = inherit)', 'oc-theme' ), 0, 0, 1920 );
		$this->color( $c, 'oc_product_bg', 'oc_product', __( 'Page background override', 'oc-theme' ) );
		$this->number( $c, 'oc_product_colgap_px', 'oc_product', __( 'Gallery-to-content gap (px, 0 = default)', 'oc-theme' ), 0, 0, 220 );

		$this->preset(
			$c,
			'oc_product_layout_side',
			'oc_product',
			__( 'Gallery and content sides', 'oc-theme' ),
			array(
				'gallery-start' => array(
					'label' => __( 'Gallery at the start', 'oc-theme' ),
					'svg'   => self::wf( '0 0 136 74', self::rect( 72, 4, 60, 66, 'im', 3 ) . self::rect( 8, 12, 52, 3, 'ln' ) . self::rect( 8, 20, 52, 3, 'ln' ) . self::rect( 8, 42, 34, 7, 'ac', 2 ) ),
				),
				'gallery-end'   => array(
					'label' => __( 'Gallery at the end', 'oc-theme' ),
					'svg'   => self::wf( '0 0 136 74', self::rect( 4, 4, 60, 66, 'im', 3 ) . self::rect( 76, 12, 52, 3, 'ln' ) . self::rect( 76, 20, 52, 3, 'ln' ) . self::rect( 94, 42, 34, 7, 'ac', 2 ) ),
				),
			),
			'gallery-start',
			'212px'
		);

		$this->choice(
			$c,
			'oc_product_cols_ratio',
			'oc_product',
			__( 'Gallery / content width', 'oc-theme' ),
			array(
				'50-50' => __( 'Equal', 'oc-theme' ),
				'60-40' => __( 'Wide gallery', 'oc-theme' ),
				'40-60' => __( 'Wide content', 'oc-theme' ),
			),
			'50-50'
		);

		$this->heading( $c, 'oc_h_prod_gallery', 'oc_product', __( 'Gallery — desktop', 'oc-theme' ) );

		$this->preset(
			$c,
			'oc_gallery_preset',
			'oc_product',
			__( 'Gallery — desktop', 'oc-theme' ),
			array(
				'thumbs-side'  => array(
					'label' => __( 'Thumbnails beside', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 84', self::rect( 34, 4, 94, 76, 'im', 3 ) . self::rect( 6, 4, 24, 17, 'bd', 2 ) . self::rect( 6, 24, 24, 17, 'bd', 2 ) . self::rect( 6, 44, 24, 17, 'bd', 2 ) . self::rect( 6, 64, 24, 16, 'bd', 2 ) ),
				),
				'thumbs-under' => array(
					'label' => __( 'Thumbnails under', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 84', self::rect( 6, 4, 120, 56, 'im', 3 ) . self::rect( 6, 64, 27, 16, 'bd', 2 ) . self::rect( 37, 64, 27, 16, 'bd', 2 ) . self::rect( 68, 64, 27, 16, 'bd', 2 ) . self::rect( 99, 64, 27, 16, 'bd', 2 ) ),
				),
				'grid'         => array(
					'label' => __( 'Two-column grid', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 84', self::rect( 6, 4, 58, 37, 'im', 3 ) . self::rect( 68, 4, 58, 37, 'im', 3 ) . self::rect( 6, 45, 58, 35, 'im', 3 ) . self::rect( 68, 45, 58, 35, 'im', 3 ) ),
				),
				'stacked'      => array(
					'label' => __( 'Stacked', 'oc-theme' ),
					'hint'  => __( 'Full width, thumbnails beside', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 84', self::rect( 34, 4, 94, 37, 'im', 3 ) . self::rect( 34, 45, 94, 35, 'im', 3 ) . self::rect( 6, 4, 24, 17, 'bd', 2 ) . self::rect( 6, 24, 24, 17, 'bd', 2 ) . self::rect( 6, 44, 24, 17, 'bd', 2 ) ),
				),
			),
			'thumbs-side',
			'200px'
		);

		$thumbs_active = static function (): bool {
			return in_array( (string) get_theme_mod( 'oc_gallery_preset', 'thumbs-side' ), array( 'thumbs-side', 'thumbs-under' ), true );
		};

		$this->number( $c, 'oc_gallery_thumbs_max', 'oc_product', __( 'Visible thumbnails (arrows page the rest)', 'oc-theme' ), 5, 2, 10, $thumbs_active );

		$this->toggle(
			$c,
			'oc_gallery_desktop_arrows',
			'oc_product',
			__( 'Arrows on the main image — desktop', 'oc-theme' ),
			false,
			$thumbs_active
		);

		$this->choice(
			$c,
			'oc_gallery_thumb_size',
			'oc_product',
			__( 'Thumbnail size', 'oc-theme' ),
			array(
				'56'  => __( 'Small', 'oc-theme' ),
				'80'  => __( 'Medium', 'oc-theme' ),
				'104' => __( 'Large', 'oc-theme' ),
			),
			'80',
			$thumbs_active
		);

		$this->choice(
			$c,
			'oc_gallery_img_height',
			'oc_product',
			__( 'Gallery image height', 'oc-theme' ),
			array(
				'auto'  => __( 'Original', 'oc-theme' ),
				'fixed' => __( 'Uniform', 'oc-theme' ),
			),
			'auto'
		);

		$this->number(
			$c,
			'oc_gallery_img_height_px',
			'oc_product',
			__( 'Uniform height (px)', 'oc-theme' ),
			600,
			240,
			1000,
			static function (): bool {
				return 'fixed' === get_theme_mod( 'oc_gallery_img_height', 'auto' );
			}
		);

		$this->toggle( $c, 'oc_gallery_lightbox', 'oc_product', __( 'Open images in a lightbox', 'oc-theme' ), true );
		$this->toggle( $c, 'oc_gallery_zoom', 'oc_product', __( 'Zoom on hover', 'oc-theme' ), true );

		$this->heading( $c, 'oc_h_prod_mobile', 'oc_product', __( 'Gallery — mobile', 'oc-theme' ) );

		$this->preset(
			$c,
			'oc_gallery_mobile',
			'oc_product',
			__( 'Gallery — mobile', 'oc-theme' ),
			array(
				'dots' => array(
					'label' => __( 'Full width with dots', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 84', self::rect( 0, 4, 132, 66, 'im', 0 ) . self::dots( 66, 78, 4 ) ),
				),
				'peek' => array(
					'label' => __( 'Peek at the next image', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 84', self::rect( 6, 4, 96, 76, 'im', 3 ) . self::rect( 108, 4, 24, 76, 'bd', 3 ) ),
				),
			),
			'dots',
			'200px'
		);

		$this->toggle(
			$c,
			'oc_gallery_mobile_arrows',
			'oc_product',
			__( 'Arrows on the mobile gallery', 'oc-theme' ),
			false,
			static function (): bool {
				return 'dots' === get_theme_mod( 'oc_gallery_mobile', 'dots' );
			}
		);

		$this->number( $c, 'oc_gallery_img_height_mobile_px', 'oc_product', __( 'Uniform height — mobile (px, 0 = auto)', 'oc-theme' ), 0, 0, 900 );

		$this->heading( $c, 'oc_h_prod_text', 'oc_product', __( 'Text', 'oc-theme' ) );

		$this->number( $c, 'oc_product_fs', 'oc_product', __( 'Body text size (px)', 'oc-theme' ), 16, 12, 20 );

		$this->choice(
			$c,
			'oc_product_lh',
			'oc_product',
			__( 'Body text line height', 'oc-theme' ),
			array(
				'1.4'  => __( 'Tight', 'oc-theme' ),
				'1.55' => __( 'Regular', 'oc-theme' ),
				'1.75' => __( 'Loose', 'oc-theme' ),
			),
			'1.55'
		);

		$this->heading( $c, 'oc_h_prod_atc', 'oc_product', __( 'Add-to-cart area', 'oc-theme' ) );

		$this->toggle( $c, 'oc_atc_qty', 'oc_product', __( 'Show the quantity beside the button', 'oc-theme' ), true );
		$this->toggle( $c, 'oc_stock_indicator', 'oc_product', __( 'Stock line above the button', 'oc-theme' ), true );

		$this->choice(
			$c,
			'oc_atc_icons_layout',
			'oc_product',
			__( 'Icons under the button', 'oc-theme' ),
			array(
				'row'   => __( 'In one row', 'oc-theme' ),
				'stack' => __( 'One under another', 'oc-theme' ),
			),
			'row'
		);

		$icon_choices = array(
			''         => __( 'None', 'oc-theme' ),
			'truck'    => __( 'Shipping — truck', 'oc-theme' ),
			'plane'    => __( 'Shipping — plane', 'oc-theme' ),
			'scooter'  => __( 'Shipping — scooter', 'oc-theme' ),
			'box'      => __( 'Shipping — box', 'oc-theme' ),
			'returns'  => __( 'Returns', 'oc-theme' ),
			'warranty' => __( 'Warranty', 'oc-theme' ),
			'question' => __( 'Product question', 'oc-theme' ),
			'gift'     => __( 'Gift wrap', 'oc-theme' ),
			'secure'   => __( 'Secure order', 'oc-theme' ),
			'discount' => __( 'Newsletter discount', 'oc-theme' ),
		);

		for ( $i = 1; $i <= 4; $i++ ) {
			/* translators: %d: icon slot number. */
			$this->select( $c, 'oc_atc_icon_' . $i, 'oc_product', sprintf( __( 'Icon %d', 'oc-theme' ), $i ), $icon_choices );
			/* translators: %d: icon slot number. */
			$this->text( $c, 'oc_atc_icon_text_' . $i, 'oc_product', sprintf( __( 'Icon %d text', 'oc-theme' ), $i ) );
		}

		$this->heading( $c, 'oc_h_prod_content', 'oc_product', __( 'Information & content', 'oc-theme' ) );

		$this->toggle( $c, 'oc_product_sticky_atc', 'oc_product', __( 'Sticky add-to-cart bar', 'oc-theme' ), true );

		$this->choice(
			$c,
			'oc_product_tabs',
			'oc_product',
			__( 'Product information style', 'oc-theme' ),
			array(
				'tabs'      => __( 'Tabs', 'oc-theme' ),
				'accordion' => __( 'Accordion', 'oc-theme' ),
			),
			'accordion'
		);

		$this->choice(
			$c,
			'oc_product_tabs_pos',
			'oc_product',
			__( 'Product info position — desktop', 'oc-theme' ),
			array(
				'below' => __( 'Full width below', 'oc-theme' ),
				'side'  => __( 'Beside the gallery', 'oc-theme' ),
			),
			'below'
		);

		$this->toggle( $c, 'oc_product_related', 'oc_product', __( 'Show similar products', 'oc-theme' ), true );
		$this->text( $c, 'oc_related_title', 'oc_product', __( 'Similar products title', 'oc-theme' ) );
		$this->toggle( $c, 'oc_product_upsells', 'oc_product', __( 'Show complementary products', 'oc-theme' ), true );
		$this->text( $c, 'oc_upsells_title', 'oc_product', __( 'Complementary products title', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_products_heading_align',
			'oc_product',
			__( 'Section headings alignment', 'oc-theme' ),
			array(
				'start'  => __( 'Start', 'oc-theme' ),
				'center' => __( 'Centre', 'oc-theme' ),
			),
			'start'
		);
	}

	/**
	 * A styled group heading between controls — sections stay scannable as
	 * they grow.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $id      Heading id.
	 * @param string                $section Section id.
	 * @param string                $label   Heading text.
	 */
	private function heading( \WP_Customize_Manager $c, string $id, string $section, string $label ): void {
		require_once __DIR__ . '/class-oc-heading-control.php';

		$c->add_setting( $id, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		$c->add_control(
			new Heading_Control(
				$c,
				$id,
				array(
					'section' => $section,
					'label'   => $label,
				)
			)
		);
	}

	/*
	 * Setting helpers — every setting gets a sanitiser.
	 */

	/**
	 * Radio/select setting with a whitelist sanitiser.
	 *
	 * @param \WP_Customize_Manager    $c       Manager.
	 * @param string                   $id      Setting id.
	 * @param string                   $section Section id.
	 * @param string                   $label   Label.
	 * @param array<int|string,string> $choices Choices. Numeric keys arrive as
	 *                                          ints because PHP coerces them.
	 * @param string                   $def     Default value.
	 * @param callable|null            $active  Optional visibility callback.
	 */
	private function choice( \WP_Customize_Manager $c, string $id, string $section, string $label, array $choices, string $def, ?callable $active = null ): void {
		$keys = array_map( 'strval', array_keys( $choices ) );

		$c->add_setting(
			$id,
			array(
				'default'           => $def,
				'sanitize_callback' => static function ( $value ) use ( $keys, $def ): string {
					return in_array( (string) $value, $keys, true ) ? (string) $value : $def;
				},
			)
		);

		$args = array(
			'section' => $section,
			'label'   => $label,
			'choices' => $choices,
		);
		if ( null !== $active ) {
			$args['active_callback'] = $active;
		}

		$c->add_control( new Customize\Segmented_Control( $c, $id, $args ) );
	}

	/**
	 * Native colour picker.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $id      Setting id.
	 * @param string                $section Section id.
	 * @param string                $label   Label.
	 */
	private function color( \WP_Customize_Manager $c, string $id, string $section, string $label ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_hex_color',
			)
		);

		$c->add_control(
			new \WP_Customize_Color_Control(
				$c,
				$id,
				array(
					'section' => $section,
					'label'   => $label,
				)
			)
		);
	}

	/**
	 * Native select, for lists too long for segmented buttons.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $id      Setting id.
	 * @param string                $section Section id.
	 * @param string                $label   Label.
	 * @param array<string,string>  $choices Choices.
	 */
	private function select( \WP_Customize_Manager $c, string $id, string $section, string $label, array $choices ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => '',
				'sanitize_callback' => static function ( $value ) use ( $choices ): string {
					return array_key_exists( (string) $value, $choices ) ? (string) $value : '';
				},
			)
		);

		$c->add_control(
			$id,
			array(
				'type'    => 'select',
				'section' => $section,
				'label'   => $label,
				'choices' => $choices,
			)
		);
	}

	/**
	 * Plain text setting.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $id      Setting id.
	 * @param string                $section Section id.
	 * @param string                $label   Label.
	 */
	private function text( \WP_Customize_Manager $c, string $id, string $section, string $label ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		$c->add_control(
			$id,
			array(
				'type'    => 'text',
				'section' => $section,
				'label'   => $label,
			)
		);
	}

	/**
	 * Drawn preset picker with a whitelist sanitiser.
	 *
	 * @param \WP_Customize_Manager              $c       Manager.
	 * @param string                             $id      Setting id.
	 * @param string                             $section Section id.
	 * @param string                             $label   Label.
	 * @param array<string,array<string,string>> $presets Presets.
	 * @param string                             $def     Default value.
	 * @param string                             $width   Item width.
	 */
	private function preset( \WP_Customize_Manager $c, string $id, string $section, string $label, array $presets, string $def, string $width ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => $def,
				'sanitize_callback' => static function ( $value ) use ( $presets, $def ): string {
					return array_key_exists( (string) $value, $presets ) ? (string) $value : $def;
				},
			)
		);

		$c->add_control(
			new Customize\Preset_Control(
				$c,
				$id,
				array(
					'section'    => $section,
					'label'      => $label,
					'presets'    => $presets,
					'item_width' => $width,
				)
			)
		);
	}

	/**
	 * Checkbox setting.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $id      Setting id.
	 * @param string                $section Section id.
	 * @param string                $label   Label.
	 * @param bool                  $def     Default.
	 * @param callable|null         $active  Optional visibility callback.
	 */
	private function toggle( \WP_Customize_Manager $c, string $id, string $section, string $label, bool $def, ?callable $active = null ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => $def ? '1' : '',
				'sanitize_callback' => static function ( $value ): bool {
					return (bool) $value;
				},
			)
		);

		$args = array(
			'section' => $section,
			'label'   => $label,
		);
		if ( null !== $active ) {
			$args['active_callback'] = $active;
		}

		$c->add_control( new Customize\Toggle_Control( $c, $id, $args ) );
	}

	/**
	 * Bounded integer setting.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $id      Setting id.
	 * @param string                $section Section id.
	 * @param string                $label   Label.
	 * @param int                   $def     Default.
	 * @param int                   $min     Minimum.
	 * @param int                   $max     Maximum.
	 * @param callable|null         $active  Optional visibility callback.
	 */
	private function number( \WP_Customize_Manager $c, string $id, string $section, string $label, int $def, int $min, int $max, ?callable $active = null ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => (string) $def,
				'sanitize_callback' => static function ( $value ) use ( $min, $max ): int {
					return (int) min( max( (int) $value, $min ), $max );
				},
			)
		);

		$args = array(
			'type'        => 'number',
			'section'     => $section,
			'label'       => $label,
			'input_attrs' => array(
				'min' => $min,
				'max' => $max,
			),
		);
		if ( null !== $active ) {
			$args['active_callback'] = $active;
		}

		$c->add_control( $id, $args );
	}

	/*
	 * Wireframe vocabulary for preset drawings.
	 */

	/**
	 * Wrap shapes in an SVG.
	 *
	 * @param string $viewbox ViewBox.
	 * @param string $body    Shape markup.
	 * @return string
	 */
	private static function wf( string $viewbox, string $body ): string {
		return '<svg class="oc-wf" viewBox="' . $viewbox . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">' . $body . '</svg>';
	}

	/**
	 * Rectangle shape.
	 *
	 * @param float  $x  X.
	 * @param float  $y  Y.
	 * @param float  $w  Width.
	 * @param float  $h  Height.
	 * @param string $cl Class.
	 * @param float  $rx Corner radius.
	 * @return string
	 */
	private static function rect( float $x, float $y, float $w, float $h, string $cl, float $rx = 1.5 ): string {
		return sprintf( '<rect x="%s" y="%s" width="%s" height="%s" rx="%s" class="%s"/>', $x, $y, $w, $h, $rx, $cl );
	}

	/**
	 * Pager-dot row.
	 *
	 * @param float $cx Centre x.
	 * @param float $cy Centre y.
	 * @param int   $n  Dot count.
	 * @return string
	 */
	private static function dots( float $cx, float $cy, int $n ): string {
		$out = '';
		for ( $i = 0; $i < $n; $i++ ) {
			$x    = $cx + ( $i - ( $n - 1 ) / 2 ) * 6;
			$out .= sprintf( '<circle cx="%s" cy="%s" r="1.7" class="%s"/>', $x, $cy, 0 === $i ? 'on' : 'dt' );
		}
		return $out;
	}
}
