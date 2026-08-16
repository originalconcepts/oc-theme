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
					'svg'   => '<svg class="oc-wf" viewBox="0 0 48 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="none" stroke="#2271b1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="20" cy="27" r="1.7"/><circle cx="29" cy="27" r="1.7"/><path d="M13 7h3l2.4 12.4a1.8 1.8 0 0 0 1.8 1.4h8.4a1.8 1.8 0 0 0 1.8-1.4L32.5 11H17"/></g></svg>',
				),
				'bag'    => array(
					'label' => __( 'Bag', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" viewBox="0 0 48 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="none" stroke="#2271b1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 11h18l-1.3 15.4a2 2 0 0 1-2 1.8H18.3a2 2 0 0 1-2-1.8Z"/><path d="M19.5 13.5V9.6a4.5 4.5 0 0 1 9 0v3.9"/></g></svg>',
				),
				'basket' => array(
					'label' => __( 'Basket', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" viewBox="0 0 48 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="none" stroke="#2271b1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 13h22l-2 12.6a2.2 2.2 0 0 1-2.2 1.9H17.2a2.2 2.2 0 0 1-2.2-1.9Z"/><path d="m19 13 4-8.5M29 13l-4-8.5"/><path d="M21 17.5v4.5M27 17.5v4.5"/></g></svg>',
				),
				'boni'   => array(
					'label' => __( 'Square bag (bonibrand)', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" viewBox="0 0 48 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="none" stroke="#2271b1" stroke-width="1.1" transform="translate(13.5 6.2)"><path d="M19 19H1V5H19V19Z" stroke-miterlimit="10" stroke-linecap="round"/><path d="M6 5V4.71746C6 2.66435 7.79085 0.999999 10 0.999999C12.2091 0.999999 14 2.66435 14 4.71746L14 5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/></g></svg>',
				),
				'amox'   => array(
					'label' => __( 'Classic cart (amox)', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" viewBox="0 0 48 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g fill="#2271b1" transform="translate(14 6.5)"><path d="M7.90874 19.2748C6.81061 19.2748 5.91718 18.3814 5.91718 17.2832C5.91718 16.1851 6.81061 15.292 7.90874 15.292C9.00686 15.292 9.9003 16.1851 9.9003 17.2832C9.9003 18.3814 9.00686 19.2748 7.90874 19.2748ZM7.90874 16.542C7.49999 16.542 7.16718 16.8745 7.16718 17.2832C7.16718 17.6923 7.49968 18.0248 7.90874 18.0248C8.3178 18.0248 8.6503 17.6923 8.6503 17.2832C8.6503 16.8745 8.3178 16.542 7.90874 16.542Z"/><path d="M14.8319 19.2748C13.7338 19.2748 12.8406 18.3814 12.8406 17.2832C12.8406 16.1851 13.7338 15.292 14.8319 15.292C15.93 15.292 16.8234 16.1851 16.8234 17.2832C16.8234 18.3814 15.9297 19.2748 14.8319 19.2748ZM14.8319 16.542C14.4231 16.542 14.0906 16.8745 14.0906 17.2832C14.0906 17.6923 14.4231 18.0248 14.8319 18.0248C15.241 18.0248 15.5734 17.6923 15.5734 17.2832C15.5734 16.8745 15.2406 16.542 14.8319 16.542Z"/><path d="M16.29 13.7482H6.47156C5.51624 13.7482 4.70031 13.0978 4.48718 12.1666L2.44937 3.26753C2.42249 3.15003 2.34124 3.0494 2.23187 2.99878L0.541557 2.21534C0.150307 2.03409 -0.020006 1.56972 0.161244 1.17815C0.342494 0.786903 0.807182 0.616591 1.19843 0.797841L2.88874 1.58128C3.43343 1.83347 3.83874 2.33347 3.97249 2.91878L6.01031 11.8185C6.05968 12.0347 6.24937 12.186 6.47156 12.186H16.29C16.5112 12.186 16.7006 12.0357 16.7512 11.82L18.3356 5.00347C18.3809 4.80972 18.2984 4.66878 18.2456 4.60222C18.1925 4.53534 18.0737 4.42347 17.875 4.42347H6.31249C5.88093 4.42347 5.53124 4.07378 5.53124 3.64222C5.53124 3.21065 5.88093 2.86097 6.31249 2.86097H17.875C18.4997 2.86097 19.0803 3.14128 19.4691 3.63034C19.8578 4.1194 19.9991 4.74878 19.8578 5.35722L18.2731 12.1741C18.0572 13.1007 17.2419 13.7482 16.29 13.7482Z"/></g></svg>',
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

		$this->number( $c, 'oc_content_width_px', 'oc_design', __( 'Content width (px)', 'oc-theme' ), 1280, 960, 1600 );

		$this->color( $c, 'oc_color_primary', 'oc_design', __( 'Primary colour', 'oc-theme' ) );
		$this->color( $c, 'oc_color_secondary', 'oc_design', __( 'Secondary colour', 'oc-theme' ) );
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

		$this->number( $c, 'oc_catalog_width_px', $section, __( 'Page width override (0 = inherit)', 'oc-theme' ), 0, 0, 1920 );
		$this->color( $c, 'oc_catalog_bg', $section, __( 'Page background override', 'oc-theme' ) );
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
				'classic'        => array(
					'label' => __( 'Classic', 'oc-theme' ),
					'hint'  => __( 'Border, centred info', 'oc-theme' ),
					'svg'   => self::wf( '0 0 80 100', self::rect( 1, 1, 78, 98, 'bd', 3 ) . self::rect( 7, 7, 66, 58, 'im', 2 ) . self::rect( 24, 72, 32, 3.4, 'ln' ) . self::rect( 31, 80, 18, 3.4, 'ac' ) ),
				),
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
			'oc_card_sale_badge',
			'oc_card',
			__( 'Sale badge', 'oc-theme' ),
			array(
				'none'    => __( 'None', 'oc-theme' ),
				'percent' => __( 'Percent off', 'oc-theme' ),
				'text'    => __( 'Text', 'oc-theme' ),
			),
			'percent'
		);

		$this->toggle( $c, 'oc_card_excerpt', 'oc_card', __( 'Show short description', 'oc-theme' ), false );
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

		$this->number( $c, 'oc_product_width_px', 'oc_product', __( 'Page width override (0 = inherit)', 'oc-theme' ), 0, 0, 1920 );
		$this->color( $c, 'oc_product_bg', 'oc_product', __( 'Page background override', 'oc-theme' ) );

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
					'hint'  => __( 'Full width, one under another', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 84', self::rect( 6, 4, 120, 37, 'im', 3 ) . self::rect( 6, 45, 120, 35, 'im', 3 ) ),
				),
			),
			'thumbs-side',
			'200px'
		);

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
			false
		);

		$thumbs_active = static function (): bool {
			return in_array( (string) get_theme_mod( 'oc_gallery_preset', 'thumbs-side' ), array( 'thumbs-side', 'thumbs-under' ), true );
		};

		$this->number( $c, 'oc_gallery_thumbs_max', 'oc_product', __( 'Max thumbnails', 'oc-theme' ), 5, 3, 10, $thumbs_active );

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
	 */
	private function toggle( \WP_Customize_Manager $c, string $id, string $section, string $label, bool $def ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => $def ? '1' : '',
				'sanitize_callback' => static function ( $value ): bool {
					return (bool) $value;
				},
			)
		);

		$c->add_control(
			new Customize\Toggle_Control(
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
				'sanitize_callback' => static function ( $value ) use ( $def, $min, $max ): int {
					$value = (int) $value;
					return ( $value >= $min && $value <= $max ) ? $value : $def;
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
