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
	/**
	 * Controls that only apply when another setting holds a given value.
	 *
	 * @var array<string,array{setting:string,values:string[]}>
	 */
	private $deps = array();

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'customize_register', array( $this, 'build' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'controls_js' ) );
	}

	/**
	 * The dependency rules, bound to the live setting values.
	 */
	public function controls_js(): void {
		$rel = '/assets/js/customizer-controls.js';

		wp_enqueue_script(
			'oc-customize-controls',
			OC_THEME_URI . $rel,
			array( 'customize-controls' ),
			oc_asset_version( $rel ),
			true
		);

		// build() has already run by now, so every rule is collected.
		wp_add_inline_script(
			'oc-customize-controls',
			'window.ocCustomizeDeps=' . wp_json_encode( (object) $this->deps ) . ';',
			'before'
		);
	}

	/**
	 * Record a rule and hand back the matching server-side test, so the
	 * first paint and every later change agree.
	 *
	 * @param string $id  Control id.
	 * @param array  $dep array{setting:string,values:array}.
	 */
	private function depend( string $id, array $dep ): callable {
		$setting = (string) $dep['setting'];
		$values  = array_map( 'strval', (array) $dep['values'] );

		$this->deps[ $id ] = array(
			'setting' => $setting,
			'values'  => $values,
		);

		return static function () use ( $setting, $values ): bool {
			return in_array( (string) self::setting_value( $setting ), $values, true );
		};
	}

	/**
	 * The stored value of a setting, defaults included.
	 *
	 * @param string $id Setting id, either a theme mod or option[key].
	 * @return mixed
	 */
	private static function setting_value( string $id ) {
		if ( ! preg_match( '/^([a-z_]+)\[([a-z0-9_]+)\]$/', $id, $m ) ) {
			return get_theme_mod( $id, '' );
		}

		switch ( $m[1] ) {
			case 'oc_checkout':
				$all = Checkout::settings();
				break;
			case 'oc_tabs':
				$all = Tabs::settings();
				break;
			case 'oc_cart':
				$all = Cart::settings();
				break;
			case 'oc_filters':
				$all = Filters::settings();
				break;
			case 'oc_thankyou':
				$all = Thankyou::settings();
				break;
			default:
				$all = (array) get_option( $m[1], array() );
		}

		return $all[ $m[2] ] ?? '';
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
		$this->menu_section( $wp_customize );
		$this->topbar_section( $wp_customize );
		$this->footer_section( $wp_customize );
		$this->catalog_panel( $wp_customize, $shop_panel );
		$this->filters_section( $wp_customize, $shop_panel );
		$this->card_section( $wp_customize, $shop_panel );
		$this->product_section( $wp_customize, $shop_panel );
		$this->swatches_section( $wp_customize, $shop_panel );
		$this->labels_section( $wp_customize, $shop_panel );
		$this->checkout_section( $wp_customize, $shop_panel );
		$this->tabs_section( $wp_customize, $shop_panel );
		$this->thankyou_section( $wp_customize, $shop_panel );
		$this->cart_section( $wp_customize, $shop_panel );
		$this->search_section( $wp_customize, $shop_panel );
		$this->brands_section( $wp_customize, $shop_panel );
		$this->blog_section( $wp_customize );
		$this->login_section( $wp_customize );
	}

	/**
	 * The login panel: how the drawer looks. The machinery and the keys
	 * live under Settings — no secret ever rides a theme mod.
	 *
	 * @param \WP_Customize_Manager $c Customizer manager.
	 */
	private function login_section( \WP_Customize_Manager $c ): void {
		$c->add_section(
			'oc_login_panel',
			array(
				'title'    => __( 'Login panel', 'oc-theme' ),
				'priority' => 168,
			)
		);

		$this->choice(
			$c,
			'oc_login_side',
			'oc_login_panel',
			__( 'Opens from', 'oc-theme' ),
			array(
				'right' => __( 'Right', 'oc-theme' ),
				'left'  => __( 'Left', 'oc-theme' ),
			),
			'right'
		);

		$this->number( $c, 'oc_login_width', 'oc_login_panel', __( 'Panel width (px)', 'oc-theme' ), 480, 320, 720 );

		$this->text( $c, 'oc_login_title', 'oc_login_panel', __( 'Title — the phone step', 'oc-theme' ) );

		$this->text( $c, 'oc_login_club_text', 'oc_login_panel', __( 'Club pitch — the drawer\'s foot', 'oc-theme' ) );

		$this->text( $c, 'oc_login_reg_title', 'oc_login_panel', __( 'Registration — the line above the perks', 'oc-theme' ) );

		$c->add_setting(
			'oc_login_reg_perks',
			array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);
		$c->add_control(
			'oc_login_reg_perks',
			array(
				'type'        => 'textarea',
				'section'     => 'oc_login_panel',
				'label'       => __( 'Registration — the perks, one per line', 'oc-theme' ),
				'description' => __( 'Each line becomes a ✓ item.', 'oc-theme' ),
			)
		);

		$this->choice(
			$c,
			'oc_login_align',
			'oc_login_panel',
			__( 'Content alignment', 'oc-theme' ),
			array(
				'center' => __( 'Centred', 'oc-theme' ),
				'start'  => __( 'Aligned to the start', 'oc-theme' ),
			),
			'center'
		);

		$this->choice(
			$c,
			'oc_login_btn_shape',
			'oc_login_panel',
			__( 'Buttons and fields', 'oc-theme' ),
			array(
				'inherit' => __( 'Like the rest of the theme', 'oc-theme' ),
				'sharp'   => __( 'Sharp', 'oc-theme' ),
				'soft'    => __( 'Soft', 'oc-theme' ),
				'round'   => __( 'Round', 'oc-theme' ),
			),
			'inherit'
		);
	}

	/**
	 * The blog: how the index carries its cards.
	 *
	 * @param \WP_Customize_Manager $c Customizer manager.
	 */
	private function blog_section( \WP_Customize_Manager $c ): void {
		$c->add_section(
			'oc_blog',
			array(
				'title'    => __( 'Blog', 'oc-theme' ),
				'priority' => 41,
			)
		);

		$this->number( $c, 'oc_blog_cols', 'oc_blog', __( 'Posts per row', 'oc-theme' ), 3, 1, 4 );

		$this->heading( $c, 'oc_h_blog_card', 'oc_blog', __( 'On each card', 'oc-theme' ) );

		$this->toggle( $c, 'oc_blog_date', 'oc_blog', __( 'Publish date', 'oc-theme' ), true );
		$this->toggle( $c, 'oc_blog_excerpt', 'oc_blog', __( 'Short description', 'oc-theme' ), true );
		$this->toggle( $c, 'oc_blog_comments', 'oc_blog', __( 'Comment count', 'oc-theme' ), true );

		$this->heading( $c, 'oc_h_blog_cmt', 'oc_blog', __( 'Comments', 'oc-theme' ) );

		$c->add_setting(
			'oc_blog_disclaimer',
			array(
				'default'           => __( 'Comments reflect their writers alone. Keep it kind — offensive or promotional comments are removed. Your email stays private and is never shown.', 'oc-theme' ),
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);

		$c->add_control(
			'oc_blog_disclaimer',
			array(
				'type'        => 'textarea',
				'section'     => 'oc_blog',
				'label'       => __( 'Note under the comment form', 'oc-theme' ),
				'description' => __( 'Empty hides it.', 'oc-theme' ),
			)
		);
	}

	/**
	 * The brands page at /brands/ — its background, width, and posture.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Panel id.
	 */
	private function brands_section( \WP_Customize_Manager $c, string $panel ): void {
		$c->add_section(
			'oc_brands',
			array(
				'title'       => __( 'The brands page', 'oc-theme' ),
				'description' => __( 'Every brand on one page, at /brands/. Brand archives breadcrumb through it.', 'oc-theme' ),
				'panel'       => $panel,
				'priority'    => 56,
			)
		);

		$this->color( $c, 'oc_brands_bg', 'oc_brands', __( 'Page background', 'oc-theme' ) );

		$this->number(
			$c,
			'oc_brands_width',
			'oc_brands',
			__( 'Page width (px)', 'oc-theme' ),
			1160,
			0,
			2400,
			null,
			__( '0 keeps the site content width.', 'oc-theme' )
		);

		$this->choice(
			$c,
			'oc_brands_view',
			'oc_brands',
			__( 'Shown as', 'oc-theme' ),
			array(
				'letters' => __( 'Split by letters', 'oc-theme' ),
				'logos'   => __( 'Brand pictures, names under them', 'oc-theme' ),
			),
			'letters'
		);

		$this->number( $c, 'oc_brands_cols', 'oc_brands', __( 'Brands per row — desktop', 'oc-theme' ), 4, 1, 8 );
		$this->number( $c, 'oc_brands_cols_m', 'oc_brands', __( 'Brands per row — phone', 'oc-theme' ), 2, 1, 4 );
	}

	/**
	 * Search: how the panel looks and what it offers.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Panel id.
	 */
	private function search_section( \WP_Customize_Manager $c, string $panel ): void {
		$c->add_section(
			'oc_search_look',
			array(
				'title'       => __( 'Search', 'oc-theme' ),
				'description' => __( 'How the search panel presents itself. What it searches, and the words it answers to, live under Theme settings.', 'oc-theme' ),
				'priority'    => 55,
				'panel'       => $panel,
			)
		);

		$this->preset(
			$c,
			'oc_search_panel',
			'oc_search_look',
			__( 'When the search opens', 'oc-theme' ),
			array(
				'full' => array(
					'label' => __( 'A panel with suggestions', 'oc-theme' ),
					'hint'  => __( 'Popular searches and products, before a word is typed', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="120" height="76" viewBox="0 0 60 38" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="4" y="3" width="52" height="7" rx="3.5"/><rect x="40" y="14" width="16" height="3" rx="1.5" opacity=".35"/><rect x="40" y="20" width="12" height="3" rx="1.5" opacity=".35"/><rect x="40" y="26" width="14" height="3" rx="1.5" opacity=".35"/><rect x="4" y="14" width="15" height="15" rx="2" opacity=".55"/><rect x="21" y="14" width="15" height="15" rx="2" opacity=".55"/></svg>',
				),
				'min'  => array(
					'label' => __( 'Only the search box', 'oc-theme' ),
					'hint'  => __( 'Results appear as soon as typing starts', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="120" height="76" viewBox="0 0 60 38" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="4" y="3" width="52" height="7" rx="3.5"/></svg>',
				),
			),
			'full',
			'150px'
		);

		$this->choice(
			$c,
			'oc_search_layout',
			'oc_search_look',
			__( 'A result looks like', 'oc-theme' ),
			array(
				'list' => __( 'A row — picture beside the words', 'oc-theme' ),
				'card' => __( 'A card — picture above the words', 'oc-theme' ),
			),
			'list',
			array(
				'setting' => 'oc_search_panel',
				'values'  => array( 'full' ),
			)
		);

		$this->number( $c, 'oc_search_limit', 'oc_search_look', __( 'Results in the panel', 'oc-theme' ), 6, 2, 16 );
		$this->number( $c, 'oc_search_min', 'oc_search_look', __( 'Start searching after this many letters', 'oc-theme' ), 2, 1, 4 );

		$this->heading( $c, 'oc_h_search_more', 'oc_search_look', __( 'Beside the products', 'oc-theme' ) );

		$this->toggle( $c, 'oc_search_show_cat', 'oc_search_look', __( 'Matching categories', 'oc-theme' ), true );
		$this->toggle( $c, 'oc_search_show_brand', 'oc_search_look', __( 'Matching brands', 'oc-theme' ), true );
		$this->choice(
			$c,
			'oc_search_brand_style',
			'oc_search_look',
			__( 'Brands appear as', 'oc-theme' ),
			array(
				'text' => __( 'Their name', 'oc-theme' ),
				'logo' => __( 'Their logo', 'oc-theme' ),
			),
			'text',
			array(
				'setting' => 'oc_search_show_brand',
				'values'  => array( '1', 'true' ),
			)
		);
		$this->toggle( $c, 'oc_search_show_tag', 'oc_search_look', __( 'Matching tags', 'oc-theme' ), false );

		$this->choice(
			$c,
			'oc_search_link_cat',
			'oc_search_look',
			__( 'A category there leads to', 'oc-theme' ),
			array(
				'narrow'  => __( 'These results, in that category', 'oc-theme' ),
				'archive' => __( 'The whole category', 'oc-theme' ),
			),
			'narrow',
			array(
				'setting' => 'oc_search_show_cat',
				'values'  => array( '1', 'true' ),
			)
		);

		$this->choice(
			$c,
			'oc_search_link_brand',
			'oc_search_look',
			__( 'A brand there leads to', 'oc-theme' ),
			array(
				'narrow'  => __( 'These results, from that brand', 'oc-theme' ),
				'archive' => __( 'Everything the brand makes', 'oc-theme' ),
			),
			'narrow',
			array(
				'setting' => 'oc_search_show_brand',
				'values'  => array( '1', 'true' ),
			)
		);
		$this->toggle( $c, 'oc_search_show_post', 'oc_search_look', __( 'Articles and pages', 'oc-theme' ), true );

		$this->heading( $c, 'oc_h_search_shop', 'oc_search_look', __( 'Behaviour', 'oc-theme' ) );

		$this->toggle( $c, 'oc_search_quickadd', 'oc_search_look', __( 'An add button on each result', 'oc-theme' ), true );
		$this->toggle( $c, 'oc_search_history', 'oc_search_look', __( 'Remember this visitor\'s own searches', 'oc-theme' ), true );
		$this->number(
			$c,
			'oc_search_history_max',
			'oc_search_look',
			__( 'How many of them to keep', 'oc-theme' ),
			8,
			3,
			12,
			array(
				'setting' => 'oc_search_history',
				'values'  => array( '1', 'true' ),
			)
		);
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

		// The transparent state may need its own face: a light logo and a
		// light ink for the icons, until the first scroll brings the solid bar.
		$c->add_setting(
			'oc_logo_transparent',
			array(
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			)
		);
		$c->add_control(
			new \WP_Customize_Image_Control(
				$c,
				'oc_logo_transparent',
				array(
					'section'     => 'oc_header',
					'label'       => __( 'Logo for the transparent state', 'oc-theme' ),
					'description' => __( 'Usually the light version. Swaps back to the regular logo once the page scrolls.', 'oc-theme' ),
				)
			)
		);

		$this->color( $c, 'oc_header_tr_tx', 'oc_header', __( 'Text and icons colour — transparent state', 'oc-theme' ) );

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
	 * Menu: how the primary menu reads, opens and moves.
	 *
	 * Ordered the way you meet the thing: the bar, then what a link does
	 * under the cursor, then how a panel arrives, then the panel, then the
	 * drawer. Every colour says which of the three surfaces it paints,
	 * because "background" three times over tells you nothing once the
	 * heading above it has scrolled away.
	 *
	 * @param \WP_Customize_Manager $c Customizer manager.
	 */
	private function menu_section( \WP_Customize_Manager $c ): void {
		$c->add_section(
			'oc_menu',
			array(
				'title'       => __( 'Menu', 'oc-theme' ),
				'description' => __( 'The links themselves come from the Menus screen. Whether the desktop shows an open menu or a hamburger is the header layout, above.', 'oc-theme' ),
				'priority'    => 12,
			)
		);

		/* ---------- the bar ---------- */

		$this->heading( $c, 'oc_h_menu_type', 'oc_menu', __( 'The menu bar', 'oc-theme' ) );

		$this->number( $c, 'oc_menu_font_px', 'oc_menu', __( 'Text size (px)', 'oc-theme' ), 16, 11, 26 );

		$this->choice(
			$c,
			'oc_menu_weight',
			'oc_menu',
			__( 'Text weight', 'oc-theme' ),
			array(
				'400' => __( 'Light', 'oc-theme' ),
				'500' => __( 'Regular', 'oc-theme' ),
				'600' => __( 'Medium', 'oc-theme' ),
				'700' => __( 'Bold', 'oc-theme' ),
			),
			'500'
		);

		$this->choice(
			$c,
			'oc_menu_case',
			'oc_menu',
			__( 'Letter case', 'oc-theme' ),
			array(
				'none'  => __( 'As written', 'oc-theme' ),
				'upper' => __( 'Capitals', 'oc-theme' ),
			),
			'none'
		);

		$this->number( $c, 'oc_menu_track', 'oc_menu', __( 'Space between letters', 'oc-theme' ), 0, 0, 20, null, __( 'Zero is normal. Each step is one hundredth of the letter height.', 'oc-theme' ) );
		$this->number( $c, 'oc_menu_gap', 'oc_menu', __( 'Space between links (px)', 'oc-theme' ), 22, 6, 60 );

		$this->color( $c, 'oc_menu_tx', 'oc_menu', __( 'Link colour', 'oc-theme' ) );
		$this->color( $c, 'oc_menu_tx_h', 'oc_menu', __( 'Link colour under the cursor', 'oc-theme' ) );
		$this->color( $c, 'oc_menu_bar_bg', 'oc_menu', __( 'Background behind the links', 'oc-theme' ), __( 'Only visible when the menu sits on a row of its own, apart from the logo.', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_menu_depth',
			'oc_menu',
			__( 'Depth of a plain drop-down', 'oc-theme' ),
			array(
				'2' => __( 'Two — a link and its children', 'oc-theme' ),
				'3' => __( 'Three — a grandchild as well', 'oc-theme' ),
			),
			'2',
			null,
			__( 'Applies to a link that has no mega panel of its own.', 'oc-theme' )
		);

		/* ---------- under the cursor ---------- */

		$this->heading( $c, 'oc_h_menu_hover', 'oc_menu', __( 'Under the cursor', 'oc-theme' ) );

		$this->preset(
			$c,
			'oc_menu_hover',
			'oc_menu',
			__( 'What happens under the cursor', 'oc-theme' ),
			array(
				'fill'  => array(
					'label' => __( 'A line draws itself', 'oc-theme' ),
					'hint'  => __( 'Grows from the start of the word', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="118" height="42" viewBox="0 0 59 21" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="14" y="6" width="31" height="4" rx="2" opacity=".55"/><rect x="26" y="14" width="19" height="2" rx="1"/></svg>',
				),
				'slide' => array(
					'label' => __( 'A line slides through', 'oc-theme' ),
					'hint'  => __( 'One leaves as the next arrives', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="118" height="42" viewBox="0 0 59 21" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="14" y="6" width="31" height="4" rx="2" opacity=".55"/><rect x="14" y="14" width="12" height="2" rx="1" opacity=".3"/><rect x="29" y="14" width="16" height="2" rx="1"/></svg>',
				),
				'lift'  => array(
					'label' => __( 'The word lifts', 'oc-theme' ),
					'hint'  => __( 'Two pixels up, nothing else', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="118" height="42" viewBox="0 0 59 21" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="14" y="4" width="31" height="4" rx="2"/><path d="M27 13 h5 M29.5 11 l-2.5 2.5 h5 z" opacity=".35"/></svg>',
				),
				'plain' => array(
					'label' => __( 'Only the colour changes', 'oc-theme' ),
					'hint'  => __( 'For quiet sites', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="118" height="42" viewBox="0 0 59 21" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="14" y="8" width="31" height="4" rx="2"/></svg>',
				),
			),
			'fill',
			'118px'
		);
		$this->color(
			$c,
			'oc_menu_ul',
			'oc_menu',
			__( 'Colour of the line', 'oc-theme' ),
			'',
			array(
				'setting' => 'oc_menu_hover',
				'values'  => array( 'fill', 'slide' ),
			)
		);

		$this->number(
			$c,
			'oc_menu_ul_w',
			'oc_menu',
			__( 'Thickness of the line (px)', 'oc-theme' ),
			2,
			1,
			6,
			array(
				'setting' => 'oc_menu_hover',
				'values'  => array( 'fill', 'slide' ),
			)
		);

		/* ---------- movement ---------- */

		$this->heading( $c, 'oc_h_menu_open', 'oc_menu', __( 'Movement', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_menu_motion',
			'oc_menu',
			__( 'How a panel arrives', 'oc-theme' ),
			array(
				'stagger' => __( 'Piece by piece', 'oc-theme' ),
				'fade'    => __( 'All at once', 'oc-theme' ),
				'none'    => __( 'No movement', 'oc-theme' ),
			),
			'stagger',
			null,
			__( 'Governs the drawer on a phone as well as the panel on a desktop.', 'oc-theme' )
		);

		$this->choice(
			$c,
			'oc_menu_enter',
			'oc_menu',
			__( 'The pieces arrive', 'oc-theme' ),
			array(
				'side' => __( 'From the side', 'oc-theme' ),
				'down' => __( 'From above', 'oc-theme' ),
			),
			'side',
			array(
				'setting' => 'oc_menu_motion',
				'values'  => array( 'stagger' ),
			)
		);

		$this->number(
			$c,
			'oc_menu_stagger',
			'oc_menu',
			__( 'Pause between pieces (ms)', 'oc-theme' ),
			40,
			10,
			140,
			array(
				'setting' => 'oc_menu_motion',
				'values'  => array( 'stagger' ),
			)
		);

		$this->toggle( $c, 'oc_menu_dim', 'oc_menu', __( 'Darken the page behind an open panel', 'oc-theme' ), false );

		/* ---------- the mega panel ---------- */

		$this->heading( $c, 'oc_h_mega', 'oc_menu', __( 'The mega panel — desktop', 'oc-theme' ) );

		$this->preset(
			$c,
			'oc_mega_width',
			'oc_menu',
			__( 'How wide it opens', 'oc-theme' ),
			array(
				'full'    => array(
					'label' => __( 'The whole page', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="132" height="46" viewBox="0 0 66 23" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="62" height="4" rx="2" opacity=".35"/><rect x="0" y="9" width="66" height="13" rx="1.5"/></svg>',
				),
				'content' => array(
					'label' => __( 'The width of the content', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="132" height="46" viewBox="0 0 66 23" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="62" height="4" rx="2" opacity=".35"/><rect x="6" y="9" width="54" height="13" rx="1.5"/></svg>',
				),
				'menu'    => array(
					'label' => __( 'Under the link itself', 'oc-theme' ),
					'hint'  => __( 'And leftwards, in Hebrew', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="132" height="46" viewBox="0 0 66 23" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="62" height="4" rx="2" opacity=".35"/><rect x="44" y="1.5" width="12" height="5" rx="2.5"/><rect x="20" y="9" width="36" height="13" rx="1.5"/></svg>',
				),
			),
			'content',
			'132px',
			__( 'The content width comes from the site\'s own; on a wide layout it can look the same as the whole page.', 'oc-theme' )
		);
		$this->number( $c, 'oc_mega_pad', 'oc_menu', __( 'Space inside the panel (px)', 'oc-theme' ), 28, 8, 72, null, __( 'Between the edge of the panel and what is in it.', 'oc-theme' ) );
		$this->number( $c, 'oc_mega_gap', 'oc_menu', __( 'Space between the columns (px)', 'oc-theme' ), 48, 8, 96 );
		$this->number( $c, 'oc_mega_rowgap', 'oc_menu', __( 'Space between links in a column (px)', 'oc-theme' ), 7, 0, 24 );
		$this->number( $c, 'oc_mega_rt', 'oc_menu', __( 'Rounding, top corners (px)', 'oc-theme' ), 0, 0, 32, null, __( 'Corners show when the panel stops short of the screen edges — mostly when it opens under the link.', 'oc-theme' ) );
		$this->number( $c, 'oc_mega_rb', 'oc_menu', __( 'Rounding, bottom corners (px)', 'oc-theme' ), 0, 0, 32 );

		$this->color( $c, 'oc_mega_bg', 'oc_menu', __( 'Panel background', 'oc-theme' ) );
		$this->color( $c, 'oc_mega_head', 'oc_menu', __( 'Colour of the column headings', 'oc-theme' ) );
		$this->color( $c, 'oc_mega_tx', 'oc_menu', __( 'Colour of the links inside', 'oc-theme' ) );
		$this->color( $c, 'oc_mega_tx_h', 'oc_menu', __( 'Those links under the cursor', 'oc-theme' ) );
		$this->number( $c, 'oc_mega_hov', 'oc_menu', __( 'How far they step under the cursor (px)', 'oc-theme' ), 3, 0, 12, null, __( 'Forwards — leftwards in Hebrew. Zero holds them still.', 'oc-theme' ) );
		$this->number( $c, 'oc_mega_fs', 'oc_menu', __( 'Text size in the panel and the drop-down (px)', 'oc-theme' ), 15, 11, 22 );

		$this->number( $c, 'oc_mega_rows', 'oc_menu', __( 'Rows in a column before it splits', 'oc-theme' ), 8, 3, 20, null, __( 'A longer list continues in a second column standing close beside it.', 'oc-theme' ) );
		$this->number( $c, 'oc_mega_img_h', 'oc_menu', __( 'Picture height in the panel (px)', 'oc-theme' ), 360, 0, 640, null, __( 'Zero matches the picture to the height of the columns beside it.', 'oc-theme' ) );
		$this->color( $c, 'oc_mega_line_t', 'oc_menu', __( 'Line above the panel', 'oc-theme' ), __( 'What separates it from the header.', 'oc-theme' ) );
		$this->color( $c, 'oc_mega_line_b', 'oc_menu', __( 'Line below the panel', 'oc-theme' ), __( 'What separates it from the page.', 'oc-theme' ) );

		/* ---------- the drawer ---------- */

		$this->heading( $c, 'oc_h_drw', 'oc_menu', __( 'The drawer — phone and hamburger', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_drw_side',
			'oc_menu',
			__( 'It slides in from', 'oc-theme' ),
			array(
				'right' => __( 'The right', 'oc-theme' ),
				'left'  => __( 'The left', 'oc-theme' ),
			),
			'right'
		);

		$this->number( $c, 'oc_drw_w', 'oc_menu', __( 'Drawer width (px)', 'oc-theme' ), 360, 260, 520 );
		$this->toggle( $c, 'oc_drw_overlay', 'oc_menu', __( 'Darken the page behind it', 'oc-theme' ), true );

		$this->preset(
			$c,
			'oc_drw_sub',
			'oc_menu',
			__( 'A sub-category opens', 'oc-theme' ),
			array(
				'accordion' => array(
					'label' => __( 'In place', 'oc-theme' ),
					'hint'  => __( 'The list grows downwards', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="118" height="60" viewBox="0 0 59 30" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="8" y="3" width="43" height="3.4" rx="1.7"/><rect x="8" y="9.5" width="43" height="3.4" rx="1.7"/><rect x="16" y="16" width="35" height="2.6" rx="1.3" opacity=".4"/><rect x="16" y="21" width="35" height="2.6" rx="1.3" opacity=".4"/><rect x="8" y="26" width="43" height="3.4" rx="1.7"/></svg>',
				),
				'slide'     => array(
					'label' => __( 'Over the top', 'oc-theme' ),
					'hint'  => __( 'A screen of its own, with a way back', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="118" height="60" viewBox="0 0 59 30" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="4" y="3" width="30" height="3.4" rx="1.7" opacity=".25"/><rect x="4" y="9.5" width="30" height="3.4" rx="1.7" opacity=".25"/><rect x="4" y="16" width="30" height="3.4" rx="1.7" opacity=".25"/><rect x="20" y="1" width="35" height="28" rx="2" fill="none" stroke="currentColor" stroke-width="1"/><rect x="24" y="5" width="12" height="2.6" rx="1.3" opacity=".5"/><rect x="24" y="11" width="27" height="3.4" rx="1.7"/><rect x="24" y="17.5" width="27" height="3.4" rx="1.7"/></svg>',
				),
			),
			'accordion',
			'118px'
		);
		$this->color( $c, 'oc_drw_bg', 'oc_menu', __( 'Drawer background', 'oc-theme' ) );
		$this->color( $c, 'oc_drw_tx', 'oc_menu', __( 'Text colour', 'oc-theme' ) );
		$this->color( $c, 'oc_drw_line', 'oc_menu', __( 'Colour of the line between rows', 'oc-theme' ) );
		$this->number( $c, 'oc_drw_fs', 'oc_menu', __( 'Text size (px)', 'oc-theme' ), 17, 12, 26 );
		$this->number( $c, 'oc_drw_gap', 'oc_menu', __( 'Row height (px)', 'oc-theme' ), 14, 6, 30, null, __( 'The air above and below each row.', 'oc-theme' ) );
		$this->number( $c, 'oc_drw_minrow', 'oc_menu', __( 'Least height of a bare row (px)', 'oc-theme' ), 34, 20, 72, null, __( 'A row with no picture never gets shorter than this. When any link carries a picture, bare rows match the picture height instead, so the list stays even.', 'oc-theme' ) );

		/* ---------- one level in ---------- */

		$this->heading( $c, 'oc_h_drw2', 'oc_menu', __( 'The drawer — one level in', 'oc-theme' ) );

		$this->color( $c, 'oc_drw_bg2', 'oc_menu', __( 'Background', 'oc-theme' ) );
		$this->color( $c, 'oc_drw_tx2', 'oc_menu', __( 'Text colour', 'oc-theme' ) );
		$this->color( $c, 'oc_drw_line2', 'oc_menu', __( 'Colour of the line between rows', 'oc-theme' ) );
		$this->number( $c, 'oc_drw_fs2', 'oc_menu', __( 'Text size (px)', 'oc-theme' ), 15, 11, 24 );
		$this->number( $c, 'oc_drw_gap2', 'oc_menu', __( 'Row height (px)', 'oc-theme' ), 11, 4, 26 );

		/* ---------- the pictures ---------- */

		$this->heading( $c, 'oc_h_drw_pic', 'oc_menu', __( 'Pictures beside the links, in the drawer', 'oc-theme' ) );

		$this->number( $c, 'oc_drw_pic', 'oc_menu', __( 'Picture size (px)', 'oc-theme' ), 38, 24, 72 );

		$this->choice(
			$c,
			'oc_drw_pic_r',
			'oc_menu',
			__( 'Picture corners', 'oc-theme' ),
			array(
				'sharp' => __( 'Sharp', 'oc-theme' ),
				'soft'  => __( 'Softened', 'oc-theme' ),
				'round' => __( 'Round', 'oc-theme' ),
			),
			'soft'
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
				'description' => __( 'A strip above the header: how it looks and behaves. The messages themselves are written under Theme settings → Announcement bar.', 'oc-theme' ),
				'priority'    => 11,
			)
		);

		$this->toggle( $c, 'oc_topbar', 'oc_topbar', __( 'Show the top bar', 'oc-theme' ), false );

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
				'description' => __( 'A ready-made footer preset — pick it, then fill the blanks. Link columns are menus (Appearance → Menus → assign to "Footer column 1–4"); each column\'s heading is set below. The bottom bar uses the "Footer menu" for its legal links.', 'oc-theme' ),
				'priority'    => 12,
			)
		);

		$this->choice(
			$c,
			'oc_footer_preset',
			'oc_footer',
			__( 'Footer preset', 'oc-theme' ),
			array(
				'columns' => __( 'Columns — brand, link columns, newsletter', 'oc-theme' ),
				'minimal' => __( 'Minimal — menu and credit only', 'oc-theme' ),
			),
			'columns'
		);

		// Brand.
		$this->heading( $c, 'oc_h_ft_brand', 'oc_footer', __( 'Brand column', 'oc-theme' ) );
		$this->choice(
			$c,
			'oc_footer_logo_src',
			'oc_footer',
			__( 'Logo', 'oc-theme' ),
			array(
				'regular'     => __( 'Regular site logo', 'oc-theme' ),
				'transparent' => __( 'Transparent-header logo (usually the light one)', 'oc-theme' ),
				'custom'      => __( 'A custom logo (upload below)', 'oc-theme' ),
				'none'        => __( 'No logo', 'oc-theme' ),
			),
			'regular'
		);
		$c->add_setting( 'oc_footer_logo_img', array( 'default' => '', 'sanitize_callback' => 'esc_url_raw' ) );
		$c->add_control(
			new \WP_Customize_Image_Control(
				$c,
				'oc_footer_logo_img',
				array(
					'section'         => 'oc_footer',
					'label'           => __( 'Custom footer logo', 'oc-theme' ),
					'active_callback' => static function () {
						return 'custom' === get_theme_mod( 'oc_footer_logo_src', 'regular' );
					},
				)
			)
		);
		$this->text( $c, 'oc_footer_tagline', 'oc_footer', __( 'Tagline under the logo', 'oc-theme' ) );

		// Column headings.
		$this->heading( $c, 'oc_h_ft_cols', 'oc_footer', __( 'Link-column headings', 'oc-theme' ) );
		for ( $i = 1; $i <= 4; $i++ ) {
			/* translators: %d: column number. */
			$this->text( $c, 'oc_footer_col' . $i . '_h', 'oc_footer', sprintf( __( 'Column %d heading', 'oc-theme' ), $i ) );
		}

		// Newsletter.
		$this->heading( $c, 'oc_h_ft_news', 'oc_footer', __( 'Newsletter column', 'oc-theme' ) );
		$this->toggle( $c, 'oc_footer_news', 'oc_footer', __( 'Show the newsletter sign-up', 'oc-theme' ), true );
		$this->text( $c, 'oc_footer_news_h', 'oc_footer', __( 'Heading', 'oc-theme' ) );
		$this->text( $c, 'oc_footer_news_t', 'oc_footer', __( 'Text', 'oc-theme' ) );

		// Colours & bottom bar. (Social icons come from Settings → Store details.)
		$this->heading( $c, 'oc_h_ft_bottom', 'oc_footer', __( 'Colours & bottom bar', 'oc-theme' ) );
		$this->color( $c, 'oc_footer_bg', 'oc_footer', __( 'Background', 'oc-theme' ) );
		$this->toggle( $c, 'oc_footer_dark', 'oc_footer', __( 'Light text (quick preset for a dark background)', 'oc-theme' ), false );
		$this->color( $c, 'oc_footer_tx', 'oc_footer', __( 'Text & icons colour', 'oc-theme' ), __( 'Overrides the preset above.', 'oc-theme' ) );
		$this->color( $c, 'oc_footer_head', 'oc_footer', __( 'Heading colour (empty = same as text)', 'oc-theme' ) );
		$this->text( $c, 'oc_footer_credit', 'oc_footer', __( 'Credit line (empty = © year and site name)', 'oc-theme' ) );
		$this->text( $c, 'oc_footer_oc_url', 'oc_footer', __( 'Builder credit link (Original Concepts)', 'oc-theme' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			$this->toggle( $c, 'oc_footer_country', 'oc_footer', __( 'Show the country / currency in the bottom bar', 'oc-theme' ), false );
		}
		$this->choice(
			$c,
			'oc_footer_layout',
			'oc_footer',
			__( 'Bottom bar layout', 'oc-theme' ),
			array(
				'inline'   => __( 'On one line', 'oc-theme' ),
				'centered' => __( 'Centred, stacked', 'oc-theme' ),
			),
			'inline'
		);
		$this->choice(
			$c,
			'oc_footer_mobile',
			'oc_footer',
			__( 'Link columns on phones', 'oc-theme' ),
			array(
				'accordion' => __( 'Accordion — tap a heading to open', 'oc-theme' ),
				'open'      => __( 'Open — stacked, all visible', 'oc-theme' ),
				'two'       => __( 'Two per row', 'oc-theme' ),
			),
			'accordion'
		);
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
				'none'      => __( 'None', 'oc-theme' ),
				'invert'    => __( 'Negative', 'oc-theme' ),
				'sweep-ltr' => __( 'Negative, left to right', 'oc-theme' ),
				'sweep-rtl' => __( 'Negative, right to left', 'oc-theme' ),
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

		$this->choice(
			$c,
			'oc_cta_incomplete',
			'oc_design',
			__( 'Button colour before options are chosen', 'oc-theme' ),
			array(
				'faded' => __( 'Faded', 'oc-theme' ),
				'full'  => __( 'Full colour', 'oc-theme' ),
			),
			'faded'
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

		$this->toggle( $c, 'oc_catalog_oos_last', $section, __( 'Sold-out products drop to the end', 'oc-theme' ), false );

		$this->choice(
			$c,
			'oc_catalog_fresh',
			$section,
			__( 'Lead image refresh', 'oc-theme' ),
			array(
				'off'   => __( 'Off', 'oc-theme' ),
				'daily' => __( 'Daily wave — a quarter of the catalogue rotates each day', 'oc-theme' ),
				'smart' => __( 'Smart — products a visitor skipped return from a new angle', 'oc-theme' ),
			),
			'off'
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

		$this->heading( $c, 'oc_h_sw_dd', 'oc_swatches', __( 'Dropdowns', 'oc-theme' ) );

		$this->choice(
			$c,
			'oc_dd_width',
			'oc_swatches',
			__( 'Single dropdown width', 'oc-theme' ),
			array(
				'full' => __( 'Full width', 'oc-theme' ),
				'half' => __( 'Half width', 'oc-theme' ),
			),
			'full'
		);

		$this->toggle( $c, 'oc_dd_pair', 'oc_swatches', __( 'Two dropdowns side by side', 'oc-theme' ), false );

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

		$this->number( $c, 'oc_swatch_loop_max', 'oc_swatches', __( 'Max swatches on the card (0 = all; the rest become +N)', 'oc-theme' ), 0, 0, 12 );
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
			array(
				'setting' => 'oc_card_image_mode',
				'values'  => array( 'gallery' ),
			)
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

		$this->choice(
			$c,
			'oc_card_atc_icon',
			'oc_card',
			__( 'Button icon', 'oc-theme' ),
			array(
				'cart' => __( 'Cart', 'oc-theme' ),
				'plus' => __( 'Plus', 'oc-theme' ),
			),
			'cart'
		);

		$this->choice(
			$c,
			'oc_card_atc_shape',
			'oc_card',
			__( 'Button shape', 'oc-theme' ),
			array(
				'circle' => __( 'Round', 'oc-theme' ),
				'square' => __( 'Square', 'oc-theme' ),
				'wide'   => __( 'Wide, with the words', 'oc-theme' ),
				'under'  => __( 'Under the description', 'oc-theme' ),
			),
			'circle'
		);

		$this->toggle( $c, 'oc_card_excerpt', 'oc_card', __( 'Show short description', 'oc-theme' ), false );

		$this->heading( $c, 'oc_h_card_lines', 'oc_card', __( 'Text lines', 'oc-theme' ) );
		$this->number( $c, 'oc_card_title_lines', 'oc_card', __( 'Title — maximum lines', 'oc-theme' ), 2, 1, 5 );
		$this->number(
			$c,
			'oc_card_excerpt_lines',
			'oc_card',
			__( 'Short description — maximum lines', 'oc-theme' ),
			2,
			1,
			5,
			array(
				'setting' => 'oc_card_excerpt',
				'values'  => array( '1', 'true' ),
			)
		);
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
		/* translators: %d is typed literally by the admin; it is replaced with the number at render time. */
		$this->text( $c, 'oc_label_strip_buy_text', 'oc_labels', __( '"In demand" text (%d = the number)', 'oc-theme' ) );
		$this->number( $c, 'oc_label_strip_cart_min', 'oc_labels', __( 'Show "great choice" from this many cart adds', 'oc-theme' ), 50, 1, 10000 );
		/* translators: %d is typed literally by the admin; it is replaced with the number at render time. */
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

		$thumbs_active = array(
			'setting' => 'oc_gallery_preset',
			'values'  => array( 'thumbs-side', 'thumbs-under' ),
		);

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
			array(
				'setting' => 'oc_gallery_img_height',
				'values'  => array( 'fixed' ),
			)
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
			array(
				'setting' => 'oc_gallery_mobile',
				'values'  => array( 'dots' ),
			)
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
			'oc_vpanel_side',
			'oc_product',
			__( 'Quick pick opens from', 'oc-theme' ),
			array(
				'right' => __( 'Right', 'oc-theme' ),
				'left'  => __( 'Left', 'oc-theme' ),
			),
			'right',
			null,
			__( 'The panel a catalogue card opens for a product with options. On a phone it rises from the bottom.', 'oc-theme' )
		);

		$this->choice(
			$c,
			'oc_vpanel_gallery',
			'oc_product',
			__( 'Quick-pick gallery', 'oc-theme' ),
			array(
				'peek'   => __( 'Two pictures, the next one peeking', 'oc-theme' ),
				'center' => __( 'One picture, centred', 'oc-theme' ),
				'small'  => __( 'A small picture, no gallery', 'oc-theme' ),
			),
			'peek'
		);

		$this->choice(
			$c,
			'oc_vpanel_corners',
			'oc_product',
			__( 'Quick-pick picture corners', 'oc-theme' ),
			array(
				'sharp' => __( 'Sharp', 'oc-theme' ),
				'soft'  => __( 'Softened', 'oc-theme' ),
				'round' => __( 'Round', 'oc-theme' ),
			),
			'soft'
		);

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
				'below'   => __( 'Full width below', 'oc-theme' ),
				'side'    => __( 'Beside the gallery', 'oc-theme' ),
				'gallery' => __( 'Under the gallery', 'oc-theme' ),
			),
			'below'
		);

		$this->toggle( $c, 'oc_product_related', 'oc_product', __( 'Show similar products', 'oc-theme' ), true );
		$this->text( $c, 'oc_related_title', 'oc_product', __( 'Similar products title', 'oc-theme' ) );

		$this->select(
			$c,
			'oc_related_scope',
			'oc_product',
			__( 'Which products count as similar', 'oc-theme' ),
			array(
				'all'  => __( 'Every category the product is in', 'oc-theme' ),
				'leaf' => __( 'Its sub-category only', 'oc-theme' ),
			),
			'all',
			array(
				'setting' => 'oc_product_related',
				'values'  => array( '1' ),
			),
			__( 'A product filed under a sub-category, its parent and a shelf like NEW pulls neighbours from all three, and the loosest one wins. The narrow setting keeps only the most specific category — and falls back to what it has if there is nothing deeper.', 'oc-theme' )
		);

		$this->preset(
			$c,
			'oc_related_layout',
			'oc_product',
			__( 'Similar products layout', 'oc-theme' ),
			array(
				'grid'   => array(
					'label' => __( 'Grid', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 52', self::rect( 8, 6, 26, 18, 'ac', 2 ) . self::rect( 38, 6, 26, 18, 'ac', 2 ) . self::rect( 68, 6, 26, 18, 'ac', 2 ) . self::rect( 98, 6, 26, 18, 'ac', 2 ) . self::rect( 8, 28, 26, 18, 'ac', 2 ) . self::rect( 38, 28, 26, 18, 'ac', 2 ) . self::rect( 68, 28, 26, 18, 'ac', 2 ) . self::rect( 98, 28, 26, 18, 'ac', 2 ) ),
				),
				'slider' => array(
					'label' => __( 'Free slider', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 52', self::rect( 8, 14, 30, 24, 'ac', 2 ) . self::rect( 42, 14, 30, 24, 'ac', 2 ) . self::rect( 76, 14, 30, 24, 'ac', 2 ) . self::rect( 110, 14, 22, 24, 'dt', 2 ) ),
				),
			),
			'grid',
			'150px',
			'',
			array(
				'setting' => 'oc_product_related',
				'values'  => array( '1' ),
			)
		);

		$this->number(
			$c,
			'oc_related_cols',
			'oc_product',
			__( 'Similar products per row', 'oc-theme' ),
			4,
			2,
			6,
			array(
				'setting' => 'oc_product_related',
				'values'  => array( '1' ),
			)
		);

		$this->select(
			$c,
			'oc_related_align',
			'oc_product',
			__( 'Similar products alignment', 'oc-theme' ),
			array(
				'start'  => __( 'From the start of the row', 'oc-theme' ),
				'center' => __( 'Centred', 'oc-theme' ),
			),
			'start',
			array(
				'setting' => 'oc_product_related',
				'values'  => array( '1' ),
			),
			__( 'Only tells when there are too few cards to fill the row.', 'oc-theme' )
		);
		$this->toggle(
			$c,
			'oc_bt_on',
			'oc_product',
			__( 'Show the bought-together bundle', 'oc-theme' ),
			true,
			null,
			__( 'Appears only on products that have a bundle set, on their own Linked Products tab.', 'oc-theme' )
		);

		$this->text( $c, 'oc_bt_title', 'oc_product', __( 'Bundle heading', 'oc-theme' ) );

		$this->toggle(
			$c,
			'oc_xsell_on',
			'oc_product',
			__( 'Show products that go with this one', 'oc-theme' ),
			false,
			null,
			__( 'The Cross-sells list on the product\'s own Linked Products tab. WooCommerce only shows these in the cart; this brings them onto the product page.', 'oc-theme' )
		);

		$this->text( $c, 'oc_xsell_title', 'oc_product', __( 'Goes-with title', 'oc-theme' ) );

		$this->preset(
			$c,
			'oc_xsell_place',
			'oc_product',
			__( 'Where they appear', 'oc-theme' ),
			array(
				'cart'    => array(
					'label' => __( 'Beside the add-to-cart button', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 52', self::rect( 8, 6, 44, 40, 'dt', 2 ) . self::rect( 60, 8, 30, 5, 'ln' ) . self::rect( 60, 17, 20, 4, 'ln' ) . self::rect( 60, 26, 64, 7, 'ac', 2 ) . self::rect( 60, 36, 64, 7, 'ac', 2 ) ),
				),
				'tabs'    => array(
					'label' => __( 'After the tabs', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 52', self::rect( 8, 4, 116, 14, 'dt', 2 ) . self::rect( 8, 22, 26, 5, 'ln' ) . self::rect( 8, 32, 116, 14, 'ac', 2 ) ),
				),
				'summary' => array(
					'label' => __( 'Under the gallery and details', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 52', self::rect( 8, 4, 44, 24, 'dt', 2 ) . self::rect( 60, 6, 40, 5, 'ln' ) . self::rect( 60, 15, 28, 4, 'ln' ) . self::rect( 8, 34, 26, 12, 'ac', 2 ) . self::rect( 38, 34, 26, 12, 'ac', 2 ) . self::rect( 68, 34, 26, 12, 'ac', 2 ) . self::rect( 98, 34, 26, 12, 'ac', 2 ) ),
				),
			),
			'summary',
			'150px',
			'',
			array(
				'setting' => 'oc_xsell_on',
				'values'  => array( '1' ),
			)
		);

		$this->preset(
			$c,
			'oc_xsell_style_cart',
			'oc_product',
			__( 'Shape beside the button', 'oc-theme' ),
			array(
				'rows' => array(
					'label' => __( 'Rows with a tick box', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 52', self::rect( 8, 8, 8, 8, 'ac', 2 ) . self::rect( 20, 6, 12, 12, 'dt', 2 ) . self::rect( 36, 9, 60, 6, 'ln' ) . self::rect( 8, 22, 8, 8, 'ac', 2 ) . self::rect( 20, 20, 12, 12, 'dt', 2 ) . self::rect( 36, 23, 60, 6, 'ln' ) . self::rect( 8, 36, 8, 8, 'ac', 2 ) . self::rect( 20, 34, 12, 12, 'dt', 2 ) . self::rect( 36, 37, 60, 6, 'ln' ) ),
				),
				'grid' => array(
					'label' => __( 'Squares you can tick', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 52', self::rect( 8, 10, 34, 32, 'dt', 2 ) . self::rect( 12, 14, 8, 8, 'ac', 2 ) . self::rect( 48, 10, 34, 32, 'dt', 2 ) . self::rect( 52, 14, 8, 8, 'ac', 2 ) . self::rect( 88, 10, 34, 32, 'dt', 2 ) . self::rect( 92, 14, 8, 8, 'ac', 2 ) ),
				),
			),
			'rows',
			'150px',
			__( 'Whatever is ticked is added together with the product itself.', 'oc-theme' ),
			array(
				'setting' => 'oc_xsell_place',
				'values'  => array( 'cart' ),
			)
		);

		$this->preset(
			$c,
			'oc_xsell_style_tabs',
			'oc_product',
			__( 'Shape after the tabs', 'oc-theme' ),
			array(
				'wide' => array(
					'label' => __( 'One across the width', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 52', self::rect( 8, 12, 30, 28, 'dt', 2 ) . self::rect( 44, 16, 44, 6, 'ln' ) . self::rect( 44, 26, 28, 5, 'ln' ) . self::rect( 96, 20, 28, 12, 'ac', 2 ) ),
				),
				'grid' => array(
					'label' => __( 'Cards in a row', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 52', self::rect( 8, 8, 26, 36, 'dt', 2 ) . self::rect( 38, 8, 26, 36, 'dt', 2 ) . self::rect( 68, 8, 26, 36, 'dt', 2 ) . self::rect( 98, 8, 26, 36, 'dt', 2 ) ),
				),
			),
			'wide',
			'150px',
			__( 'Each product carries its own add-to-cart button here.', 'oc-theme' ),
			array(
				'setting' => 'oc_xsell_place',
				'values'  => array( 'tabs' ),
			)
		);

		$this->preset(
			$c,
			'oc_xsell_style_sum',
			'oc_product',
			__( 'Shape under the details', 'oc-theme' ),
			array(
				'grid'   => array(
					'label' => __( 'Grid', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 52', self::rect( 8, 6, 26, 18, 'ac', 2 ) . self::rect( 38, 6, 26, 18, 'ac', 2 ) . self::rect( 68, 6, 26, 18, 'ac', 2 ) . self::rect( 98, 6, 26, 18, 'ac', 2 ) . self::rect( 8, 28, 26, 18, 'ac', 2 ) . self::rect( 38, 28, 26, 18, 'ac', 2 ) . self::rect( 68, 28, 26, 18, 'ac', 2 ) . self::rect( 98, 28, 26, 18, 'ac', 2 ) ),
				),
				'slider' => array(
					'label' => __( 'Free slider', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 52', self::rect( 8, 14, 30, 24, 'ac', 2 ) . self::rect( 42, 14, 30, 24, 'ac', 2 ) . self::rect( 76, 14, 30, 24, 'ac', 2 ) . self::rect( 110, 14, 22, 24, 'dt', 2 ) ),
				),
			),
			'grid',
			'150px',
			__( 'The catalogue\'s own cards, so the add button follows the card setting.', 'oc-theme' ),
			array(
				'setting' => 'oc_xsell_place',
				'values'  => array( 'summary' ),
			)
		);

		$this->number( $c, 'oc_xsell_cols', 'oc_product', __( 'Goes-with products per row', 'oc-theme' ), 4, 2, 6, array(
				'setting' => 'oc_xsell_on',
				'values'  => array( '1' ),
			) );

		$this->select(
			$c,
			'oc_xsell_align',
			'oc_product',
			__( 'Goes-with alignment', 'oc-theme' ),
			array(
				'start'  => __( 'From the start of the row', 'oc-theme' ),
				'center' => __( 'Centred', 'oc-theme' ),
			),
			'start',
			array(
				'setting' => 'oc_xsell_on',
				'values'  => array( '1' ),
			)
		);

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
	 * @param array|null               $dep     Optional visibility rule.
	 * @param string                   $hint    Helper text under the control.
	 */
	private function choice( \WP_Customize_Manager $c, string $id, string $section, string $label, array $choices, string $def, ?array $dep = null, string $hint = '' ): void {
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
			'section'     => $section,
			'label'       => $label,
			'description' => $hint,
			'choices'     => $choices,
		);
		if ( null !== $dep ) {
			$args['active_callback'] = $this->depend( $id, $dep );
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
	 * @param string                $hint    Helper text under the control.
	 * @param array|null            $dep     Optional visibility rule.
	 */
	private function color( \WP_Customize_Manager $c, string $id, string $section, string $label, string $hint = '', ?array $dep = null ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_hex_color',
			)
		);

		$args = array(
			'section'     => $section,
			'label'       => $label,
			'description' => $hint,
		);

		if ( null !== $dep ) {
			$args['active_callback'] = $this->depend( $id, $dep );
		}

		$c->add_control( new \WP_Customize_Color_Control( $c, $id, $args ) );
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
	private function select( \WP_Customize_Manager $c, string $id, string $section, string $label, array $choices, string $def = '', ?array $dep = null, string $hint = '' ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => $def,
				'sanitize_callback' => static function ( $value ) use ( $choices, $def ): string {
					return array_key_exists( (string) $value, $choices ) ? (string) $value : $def;
				},
			)
		);

		$args = array(
			'type'        => 'select',
			'section'     => $section,
			'label'       => $label,
			'description' => $hint,
			'choices'     => $choices,
		);

		if ( null !== $dep ) {
			$args['active_callback'] = $this->depend( $id, $dep );
		}

		$c->add_control( $id, $args );
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
	 * @param string                             $hint    Helper text under the control.
	 */
	private function preset( \WP_Customize_Manager $c, string $id, string $section, string $label, array $presets, string $def, string $width, string $hint = '', ?array $dep = null ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => $def,
				'sanitize_callback' => static function ( $value ) use ( $presets, $def ): string {
					return array_key_exists( (string) $value, $presets ) ? (string) $value : $def;
				},
			)
		);

		$args = array(
			'section'     => $section,
			'label'       => $label,
			'description' => $hint,
			'presets'     => $presets,
			'item_width'  => $width,
		);

		if ( null !== $dep ) {
			$args['active_callback'] = $this->depend( $id, $dep );
		}

		$c->add_control( new Customize\Preset_Control( $c, $id, $args ) );
	}

	/**
	 * Checkbox setting.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $id      Setting id.
	 * @param string                $section Section id.
	 * @param string                $label   Label.
	 * @param bool                  $def     Default.
	 * @param array|null            $dep     Optional visibility rule.
	 * @param string                $hint    Helper text under the control.
	 */
	private function toggle( \WP_Customize_Manager $c, string $id, string $section, string $label, bool $def, ?array $dep = null, string $hint = '' ): void {
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
			'section'     => $section,
			'label'       => $label,
			'description' => $hint,
		);
		if ( null !== $dep ) {
			$args['active_callback'] = $this->depend( $id, $dep );
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
	 * @param array|null            $dep     Optional visibility rule.
	 * @param string                $hint    Helper text under the control.
	 */
	private function number( \WP_Customize_Manager $c, string $id, string $section, string $label, int $def, int $min, int $max, ?array $dep = null, string $hint = '' ): void {
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
			'description' => $hint,
			'input_attrs' => array(
				'min' => $min,
				'max' => $max,
			),
		);
		if ( null !== $dep ) {
			$args['active_callback'] = $this->depend( $id, $dep );
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

	/**
	 * Checkout: the flow's shape, which is decided when the shop is built.
	 *
	 * Every control here binds straight into the existing `oc_checkout`
	 * option — the same array the Checkout admin screen reads and writes. The
	 * storage does not move, so nothing needs migrating and no saved value is
	 * at risk; only where the knobs live changes.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Panel to nest under.
	 */
	private function checkout_section( \WP_Customize_Manager $c, string $panel ): void {
		$c->add_section(
			'oc_checkout',
			array(
				'title'       => __( 'Checkout', 'oc-theme' ),
				'description' => __( 'How the checkout is laid out. Texts the shop edits day to day stay under Theme settings.', 'oc-theme' ),
				'priority'    => 13,
				'panel'       => $panel,
			)
		);

		$o = 'oc_checkout';

		$this->heading( $c, 'oc_h_ck_layout', 'oc_checkout', __( 'Layout', 'oc-theme' ) );
		$this->opt_toggle( $c, $o, 'summary', 'oc_checkout', __( 'Show the product list', 'oc-theme' ), true );
		$this->opt_toggle(
			$c,
			$o,
			'summary_fold',
			'oc_checkout',
			__( 'Start the summary folded on desktop', 'oc-theme' ),
			true,
			array(
				'setting' => 'oc_checkout[summary]',
				'values'  => array( '1', 'true' ),
			)
		);
		$this->opt_color( $c, $o, 'side_bg', 'oc_checkout', __( 'Summary column colour', 'oc-theme' ) );
		$this->opt_select(
			$c,
			$o,
			'coupon_mode',
			'oc_checkout',
			__( 'Coupon field', 'oc-theme' ),
			array(
				'open'   => __( 'Open field', 'oc-theme' ),
				'button' => __( '"Have a coupon?" opens the field', 'oc-theme' ),
				'hide'   => __( 'Hidden', 'oc-theme' ),
			),
			'open'
		);
		$this->opt_select(
			$c,
			$o,
			'country_mode',
			'oc_checkout',
			__( 'Country field', 'oc-theme' ),
			array(
				'auto' => __( 'Automatic — hidden when the store ships to one country', 'oc-theme' ),
				'hide' => __( 'Always hidden', 'oc-theme' ),
			),
			'auto'
		);

		$this->heading( $c, 'oc_h_ck_other', 'oc_checkout', __( 'Sending to someone else', 'oc-theme' ) );
		$this->opt_toggle( $c, $o, 'send_other', 'oc_checkout', __( 'Show "I\'m sending to someone else"', 'oc-theme' ), true );
		$this->opt_toggle(
			$c,
			$o,
			'phone2_required',
			'oc_checkout',
			__( "Recipient's additional phone is required", 'oc-theme' ),
			false,
			array(
				'setting' => 'oc_checkout[send_other]',
				'values'  => array( '1', 'true' ),
			)
		);

		$this->heading( $c, 'oc_h_ck_addr', 'oc_checkout', __( 'Address fields', 'oc-theme' ) );
		$this->opt_toggle( $c, $o, 'apt_required', 'oc_checkout', __( 'Apartment is required', 'oc-theme' ), false );
		$this->opt_toggle( $c, $o, 'floor_required', 'oc_checkout', __( 'Floor is required', 'oc-theme' ), false );
		$this->opt_toggle( $c, $o, 'entry_required', 'oc_checkout', __( 'Entry code is required', 'oc-theme' ), false );

		$this->heading( $c, 'oc_h_ck_pack', 'oc_checkout', __( 'Signed-in checkout & address book', 'oc-theme' ) );
		$this->opt_toggle( $c, $o, 'multi_address', 'oc_checkout', __( 'Pack a signed-in shopper\'s details and let them keep several saved addresses', 'oc-theme' ), false );
		$this->opt_toggle( $c, $o, 'reorder', 'oc_checkout', __( 'Show an "Order again" button on the orders list', 'oc-theme' ), true );

		$this->heading( $c, 'oc_h_ck_phone', 'oc_checkout', __( 'Phone validation', 'oc-theme' ) );
		$this->opt_number( $c, $o, 'phone_min', 'oc_checkout', __( 'Digits from', 'oc-theme' ), 9, 0, 20 );
		$this->opt_number( $c, $o, 'phone_max', 'oc_checkout', __( 'Digits to', 'oc-theme' ), 10, 0, 20 );

		$this->heading( $c, 'oc_h_ck_fields', 'oc_checkout', __( 'Fields & extras', 'oc-theme' ) );
		$this->opt_toggle( $c, $o, 'notes', 'oc_checkout', __( 'Order notes', 'oc-theme' ), true );
		$this->opt_toggle( $c, $o, 'consent', 'oc_checkout', __( 'Marketing consent checkbox', 'oc-theme' ), true );
		$this->opt_text(
			$c,
			$o,
			'consent_text',
			'oc_checkout',
			__( 'Consent wording', 'oc-theme' ),
			array(
				'setting' => 'oc_checkout[consent]',
				'values'  => array( '1', 'true' ),
			)
		);
		$this->opt_toggle( $c, $o, 'btn_total', 'oc_checkout', __( 'Show the total on the place-order button', 'oc-theme' ), true );
		$this->opt_text( $c, $o, 'btn_text', 'oc_checkout', __( 'Place-order button label', 'oc-theme' ) );
		$this->opt_text( $c, $o, 'help_text', 'oc_checkout', __( 'Help line in the header', 'oc-theme' ) );
	}

	/*
	---------- controls bound to a key inside an existing option ----------
	 * WordPress can address one key of a serialized option directly, so a
	 * control can move into the Customizer while its value stays exactly
	 * where the rest of the theme already reads it from.
	 */

	/**
	 * Shared arguments for an option-backed setting.
	 *
	 * @param string   $option Option name.
	 * @param string   $key    Key inside it.
	 * @param mixed    $def    Default.
	 * @param callable $san    Sanitizer.
	 * @return array{0:string,1:array}
	 */
	private function opt_args( string $option, string $key, $def, callable $san ): array {
		return array(
			$option . '[' . $key . ']',
			array(
				'type'              => 'option',
				'default'           => $def,
				'sanitize_callback' => $san,
			),
		);
	}

	/**
	 * Option-backed on/off.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $option  Option name.
	 * @param string                $key     Key.
	 * @param string                $section Section.
	 * @param string                $label   Label.
	 * @param bool                  $def     Default.
	 * @param array|null            $dep     Visibility rule.
	 */
	private function opt_toggle( \WP_Customize_Manager $c, string $option, string $key, string $section, string $label, bool $def, ?array $dep = null ): void {
		list( $id, $args ) = $this->opt_args(
			$option,
			$key,
			$def ? 1 : 0,
			// Stored as 1/0 so the value is byte-identical to what the admin
			// screen writes.
			static function ( $value ): int {
				return $value ? 1 : 0;
			}
		);

		$c->add_setting( $id, $args );

		$control = array(
			'section' => $section,
			'label'   => $label,
		);
		if ( null !== $dep ) {
			$control['active_callback'] = $this->depend( $id, $dep );
		}

		$c->add_control( new Customize\Toggle_Control( $c, $id, $control ) );
	}

	/**
	 * Option-backed select.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $option  Option name.
	 * @param string                $key     Key.
	 * @param string                $section Section.
	 * @param string                $label   Label.
	 * @param array                 $choices Choices.
	 * @param string                $def     Default.
	 * @param array|null            $dep     Optional visibility rule.
	 */
	private function opt_select( \WP_Customize_Manager $c, string $option, string $key, string $section, string $label, array $choices, string $def, ?array $dep = null ): void {
		list( $id, $args ) = $this->opt_args(
			$option,
			$key,
			$def,
			static function ( $value ) use ( $choices, $def ): string {
				return array_key_exists( (string) $value, $choices ) ? (string) $value : $def;
			}
		);

		$c->add_setting( $id, $args );

		$control = array(
			'type'    => 'select',
			'section' => $section,
			'label'   => $label,
			'choices' => $choices,
		);
		if ( null !== $dep ) {
			$control['active_callback'] = $this->depend( $id, $dep );
		}

		$c->add_control( $id, $control );
	}

	/**
	 * Option-backed number.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $option  Option name.
	 * @param string                $key     Key.
	 * @param string                $section Section.
	 * @param string                $label   Label.
	 * @param int                   $def     Default.
	 * @param int                   $min     Minimum.
	 * @param int                   $max     Maximum.
	 * @param array|null            $dep     Optional visibility rule.
	 */
	private function opt_number( \WP_Customize_Manager $c, string $option, string $key, string $section, string $label, int $def, int $min, int $max, ?array $dep = null ): void {
		list( $id, $args ) = $this->opt_args(
			$option,
			$key,
			$def,
			static function ( $value ) use ( $min, $max ): int {
				return (int) min( max( (int) $value, $min ), $max );
			}
		);

		$c->add_setting( $id, $args );

		$control = array(
			'type'        => 'number',
			'section'     => $section,
			'label'       => $label,
			'input_attrs' => array(
				'min' => $min,
				'max' => $max,
			),
		);
		if ( null !== $dep ) {
			$control['active_callback'] = $this->depend( $id, $dep );
		}

		$c->add_control( $id, $control );
	}

	/**
	 * Option-backed single line of text.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $option  Option name.
	 * @param string                $key     Key.
	 * @param string                $section Section.
	 * @param string                $label   Label.
	 * @param array|null            $dep     Visibility rule.
	 */
	private function opt_text( \WP_Customize_Manager $c, string $option, string $key, string $section, string $label, ?array $dep = null ): void {
		list( $id, $args ) = $this->opt_args( $option, $key, '', 'sanitize_text_field' );

		$c->add_setting( $id, $args );

		$control = array(
			'type'    => 'text',
			'section' => $section,
			'label'   => $label,
		);
		if ( null !== $dep ) {
			$control['active_callback'] = $this->depend( $id, $dep );
		}

		$c->add_control( $id, $control );
	}

	/**
	 * Option-backed colour.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $option  Option name.
	 * @param string                $key     Key.
	 * @param string                $section Section.
	 * @param string                $label   Label.
	 */
	private function opt_color( \WP_Customize_Manager $c, string $option, string $key, string $section, string $label ): void {
		list( $id, $args ) = $this->opt_args( $option, $key, '', 'sanitize_hex_color' );

		$c->add_setting( $id, $args );
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
	 * Product tabs: which built-in tabs appear and in what order. The tab
	 * builder itself stays under Theme settings — it is content.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Panel to nest under.
	 */
	private function tabs_section( \WP_Customize_Manager $c, string $panel ): void {
		$c->add_section(
			'oc_tabs_cfg',
			array(
				'title'       => __( 'Product tabs', 'oc-theme' ),
				'description' => __( 'The built-in tabs. Tab titles and the custom tabs stay under Theme settings.', 'oc-theme' ),
				'priority'    => 14,
				'panel'       => $panel,
			)
		);

		$o = 'oc_tabs';

		$this->heading( $c, 'oc_h_tb_short', 'oc_tabs_cfg', __( 'Short description', 'oc-theme' ) );
		$this->opt_toggle( $c, $o, 'short_tab', 'oc_tabs_cfg', __( 'Show as the first tab (instead of in the summary)', 'oc-theme' ), false );
		$this->opt_toggle(
			$c,
			$o,
			'short_open',
			'oc_tabs_cfg',
			__( 'Open by default', 'oc-theme' ),
			false,
			array(
				'setting' => 'oc_tabs[short_tab]',
				'values'  => array( '1', 'true' ),
			)
		);

		$this->heading( $c, 'oc_h_tb_desc', 'oc_tabs_cfg', __( 'Full description', 'oc-theme' ) );
		$this->opt_select(
			$c,
			$o,
			'desc_place',
			'oc_tabs_cfg',
			__( 'Placement', 'oc-theme' ),
			array(
				'tab'   => __( 'Inside a tab', 'oc-theme' ),
				'below' => __( 'Outside — below the tabs', 'oc-theme' ),
			),
			'tab'
		);
		$this->opt_number( $c, $o, 'desc_order', 'oc_tabs_cfg', __( 'Position', 'oc-theme' ), 10, 0, 99 );
		$this->opt_toggle(
			$c,
			$o,
			'desc_open',
			'oc_tabs_cfg',
			__( 'Open by default', 'oc-theme' ),
			false,
			array(
				'setting' => 'oc_tabs[desc_place]',
				'values'  => array( 'tab' ),
			)
		);

		$this->heading( $c, 'oc_h_tb_add', 'oc_tabs_cfg', __( 'Additional information', 'oc-theme' ) );
		$this->opt_toggle( $c, $o, 'additional', 'oc_tabs_cfg', __( 'Show the attributes table', 'oc-theme' ), true );
		$this->opt_number(
			$c,
			$o,
			'add_order',
			'oc_tabs_cfg',
			__( 'Position', 'oc-theme' ),
			20,
			0,
			99,
			array(
				'setting' => 'oc_tabs[additional]',
				'values'  => array( '1', 'true' ),
			)
		);
	}

	/**
	 * Thank-you page: its shape. The words on it, the survey and the referral
	 * terms are the shop's own and stay under Theme settings.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Panel to nest under.
	 */
	private function thankyou_section( \WP_Customize_Manager $c, string $panel ): void {
		$c->add_section(
			'oc_thankyou_cfg',
			array(
				'title'       => __( 'Thank-you page', 'oc-theme' ),
				'description' => __( 'How the order-received page is laid out.', 'oc-theme' ),
				'priority'    => 15,
				'panel'       => $panel,
			)
		);

		$o = 'oc_thankyou';

		$this->opt_select(
			$c,
			$o,
			'layout',
			'oc_thankyou_cfg',
			__( 'Layout', 'oc-theme' ),
			array(
				'stack' => __( 'One column — everything under the greeting', 'oc-theme' ),
				'split' => __( 'Two columns — greeting and summary on one side, the rest beside them', 'oc-theme' ),
			),
			'stack'
		);
		$this->opt_toggle( $c, $o, 'contact', 'oc_thankyou_cfg', __( 'Show phone, email and WhatsApp under the text', 'oc-theme' ), true );
		$this->opt_toggle( $c, $o, 'summary', 'oc_thankyou_cfg', __( 'Show the products (with images) and totals', 'oc-theme' ), true );
	}


	/**
	 * Cart drawer: where it sits and what it carries. Copy and the upsell
	 * merchandising rules stay under Theme settings.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Panel to nest under.
	 */
	private function cart_section( \WP_Customize_Manager $c, string $panel ): void {
		$c->add_section(
			'oc_cart_cfg',
			array(
				'title'       => __( 'Cart & mini-cart', 'oc-theme' ),
				'description' => __( 'The drawer\'s shape. Titles, free-shipping threshold and upsell rules stay under Theme settings.', 'oc-theme' ),
				'priority'    => 16,
				'panel'       => $panel,
			)
		);

		$o = 'oc_cart';

		$this->heading( $c, 'oc_h_ct_panel', 'oc_cart_cfg', __( 'Panel', 'oc-theme' ) );
		$this->opt_select(
			$c,
			$o,
			'side',
			'oc_cart_cfg',
			__( 'Opens from', 'oc-theme' ),
			array(
				'left'  => __( 'Left', 'oc-theme' ),
				'right' => __( 'Right', 'oc-theme' ),
			),
			'left'
		);
		$this->opt_number( $c, $o, 'width', 'oc_cart_cfg', __( 'Width (px)', 'oc-theme' ), 560, 320, 800 );
		$this->opt_toggle( $c, $o, 'open_on_add', 'oc_cart_cfg', __( 'Open the panel automatically', 'oc-theme' ), true );
		$this->opt_select(
			$c,
			$o,
			'count_method',
			'oc_cart_cfg',
			__( 'Header counter', 'oc-theme' ),
			array(
				'total' => __( 'Total units', 'oc-theme' ),
				'rows'  => __( 'Distinct products', 'oc-theme' ),
			),
			'total'
		);

		$this->heading( $c, 'oc_h_ct_ship', 'oc_cart_cfg', __( 'Free shipping', 'oc-theme' ) );
		$this->opt_toggle( $c, $o, 'ship_bar', 'oc_cart_cfg', __( 'Show progress toward free shipping', 'oc-theme' ), true );

		$this->heading( $c, 'oc_h_ct_up', 'oc_cart_cfg', __( 'Upsells', 'oc-theme' ) );
		$this->opt_preset(
			$c,
			$o,
			'up_style',
			'oc_cart_cfg',
			__( 'Where they appear', 'oc-theme' ),
			array(
				'side'     => array(
					'label' => __( 'Side strip inside the panel', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="64" viewBox="0 0 48 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="10" height="28" rx="2"/><rect x="16" y="2" width="30" height="8" rx="2" opacity=".35"/><rect x="16" y="14" width="30" height="8" rx="2" opacity=".35"/><rect x="16" y="26" width="30" height="4" rx="2" opacity=".35"/></svg>',
				),
				'list'     => array(
					'label' => __( 'After the cart items', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="64" viewBox="0 0 48 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="44" height="6" rx="2" opacity=".35"/><rect x="2" y="12" width="8" height="6" rx="1.5"/><rect x="13" y="13.5" width="33" height="3" rx="1.5" opacity=".55"/><rect x="2" y="21" width="8" height="6" rx="1.5"/><rect x="13" y="22.5" width="33" height="3" rx="1.5" opacity=".55"/></svg>',
				),
				'slider'   => array(
					'label' => __( 'Horizontal slider', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="64" viewBox="0 0 48 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="44" height="7" rx="2" opacity=".35"/><rect x="8" y="14" width="12" height="14" rx="2"/><rect x="23" y="14" width="12" height="14" rx="2"/><rect x="38" y="14" width="8" height="14" rx="2" opacity=".55"/><path d="M2 21l3-2.5L2 16z"/></svg>',
				),
				'collapse' => array(
					'label' => __( 'Above the total — minimizable', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="64" viewBox="0 0 48 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="44" height="12" rx="2" opacity=".35"/><rect x="2" y="18" width="21" height="12" rx="2"/><circle cx="19" cy="24" r="2.6" fill="#fff"/><rect x="26" y="18" width="20" height="12" rx="2" opacity=".55"/><circle cx="42" cy="24" r="2.6" fill="#fff"/></svg>',
				),
			),
			'side',
			'110px'
		);
		$this->opt_color( $c, $o, 'up_bg', 'oc_cart_cfg', __( 'Upsell background', 'oc-theme' ) );

		$this->heading( $c, 'oc_h_ct_foot', 'oc_cart_cfg', __( 'Panel footer', 'oc-theme' ) );
		$this->opt_toggle( $c, $o, 'btn_total', 'oc_cart_cfg', __( 'Show the total on the button', 'oc-theme' ), false );
		$this->opt_toggle( $c, $o, 'continue', 'oc_cart_cfg', __( 'Show a "Continue shopping" button beneath', 'oc-theme' ), false );
		$this->opt_toggle( $c, $o, 'coupon', 'oc_cart_cfg', __( 'Show a coupon field', 'oc-theme' ), false );
		$this->opt_toggle( $c, $o, 'cart_link', 'oc_cart_cfg', __( 'Link to the cart page', 'oc-theme' ), false );
	}

	/**
	 * Catalogue filtering: how the filter UI looks and behaves. Which
	 * attributes are offered stays under Theme settings — that is
	 * merchandising, and it changes with the catalogue.
	 *
	 * @param \WP_Customize_Manager $c     Customizer manager.
	 * @param string                $panel Panel to nest under.
	 */
	private function filters_section( \WP_Customize_Manager $c, string $panel ): void {
		$c->add_section(
			'oc_filters_cfg',
			array(
				'title'       => __( 'Catalogue filters', 'oc-theme' ),
				'description' => __( 'How filtering looks. The filter groups themselves stay under Theme settings.', 'oc-theme' ),
				// Right behind the catalogue section — which, when WooCommerce
				// is active, is WooCommerce's own at priority 10. Same
				// priority plus a later registration puts this just after it.
				'priority'    => 10,
				'panel'       => $panel,
			)
		);

		$o = 'oc_filters';

		$this->opt_toggle( $c, $o, 'enabled', 'oc_filters_cfg', __( 'Enable filtering', 'oc-theme' ), false );
		$this->opt_preset(
			$c,
			$o,
			'layout',
			'oc_filters_cfg',
			__( 'Layout', 'oc-theme' ),
			array(
				'sidebar' => array(
					'label' => __( 'Side column', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="64" viewBox="0 0 48 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="34" y="2" width="12" height="28" rx="2"/><rect x="2" y="2" width="28" height="8" rx="2" opacity=".35"/><rect x="2" y="14" width="28" height="16" rx="2" opacity=".35"/></svg>',
				),
				'topbar'  => array(
					'label' => __( 'Bar above the products', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="64" viewBox="0 0 48 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="44" height="7" rx="2"/><rect x="2" y="13" width="44" height="17" rx="2" opacity=".35"/></svg>',
				),
				'drawer'  => array(
					'label' => __( 'Filter button opening a panel', 'oc-theme' ),
					'svg'   => '<svg class="oc-wf" width="96" height="64" viewBox="0 0 48 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="36" y="2" width="10" height="6" rx="2"/><rect x="2" y="12" width="44" height="18" rx="2" opacity=".35"/></svg>',
				),
			),
			'sidebar',
			'110px'
		);
		$this->opt_select(
			$c,
			$o,
			'topbar_style',
			'oc_filters_cfg',
			__( 'Bar group opens', 'oc-theme' ),
			array(
				'drop' => __( 'Opens under the value', 'oc-theme' ),
				'full' => __( 'Opens full width', 'oc-theme' ),
			),
			'drop',
			array(
				'setting' => 'oc_filters[layout]',
				'values'  => array( 'topbar' ),
			)
		);
		$this->opt_select(
			$c,
			$o,
			'chips_pos',
			'oc_filters_cfg',
			__( 'Chosen values row', 'oc-theme' ),
			array(
				'start'  => __( 'Below the bar, at the start', 'oc-theme' ),
				'center' => __( 'Below the bar, centred', 'oc-theme' ),
				'inline' => __( 'Inside the bar, after the groups', 'oc-theme' ),
				'group'  => __( 'Inside the bar, beside each group', 'oc-theme' ),
			),
			'start',
			array(
				'setting' => 'oc_filters[layout]',
				'values'  => array( 'topbar' ),
			)
		);
		$this->opt_select(
			$c,
			$o,
			'choice',
			'oc_filters_cfg',
			__( 'Choice mark', 'oc-theme' ),
			array(
				'check' => __( 'Checkboxes', 'oc-theme' ),
				'dot'   => __( 'Dot marks', 'oc-theme' ),
			),
			'check'
		);
		$this->opt_select(
			$c,
			$o,
			'chip_swatch',
			'oc_filters_cfg',
			__( 'Swatch value in the chips row', 'oc-theme' ),
			array(
				'off'  => __( 'Name only', 'oc-theme' ),
				'both' => __( 'Swatch and name', 'oc-theme' ),
				'only' => __( 'Swatch only', 'oc-theme' ),
			),
			'off'
		);
		$this->opt_toggle( $c, $o, 'swatch_names', 'oc_filters_cfg', __( 'Value names beside swatches', 'oc-theme' ), true );
		$this->opt_toggle( $c, $o, 'swatch_names_m', 'oc_filters_cfg', __( 'Value names beside swatches on mobile', 'oc-theme' ), true );
		$this->opt_toggle( $c, $o, 'counts', 'oc_filters_cfg', __( 'Show how many products match', 'oc-theme' ), true );
		$this->opt_select(
			$c,
			$o,
			'empty',
			'oc_filters_cfg',
			__( 'Values with no products', 'oc-theme' ),
			array(
				'gray' => __( 'Grey and unclickable', 'oc-theme' ),
				'hide' => __( 'Hidden entirely', 'oc-theme' ),
			),
			'gray'
		);
		$this->opt_toggle( $c, $o, 'instock', 'oc_filters_cfg', __( 'Offer an "in stock only" filter', 'oc-theme' ), true );

		$this->heading( $c, 'oc_h_fl_price', 'oc_filters_cfg', __( 'Price', 'oc-theme' ) );
		$this->opt_select(
			$c,
			$o,
			'price_mode',
			'oc_filters_cfg',
			__( 'Price filter', 'oc-theme' ),
			array(
				'range' => __( 'From price to price', 'oc-theme' ),
				'tiers' => __( 'Preset "up to" steps', 'oc-theme' ),
				'off'   => __( 'Off', 'oc-theme' ),
			),
			'range'
		);
		$this->opt_select(
			$c,
			$o,
			'price_ui',
			'oc_filters_cfg',
			__( 'Range control', 'oc-theme' ),
			array(
				'slider' => __( 'Slider', 'oc-theme' ),
				'inputs' => __( 'Input fields', 'oc-theme' ),
			),
			'slider',
			array(
				'setting' => 'oc_filters[price_mode]',
				'values'  => array( 'range' ),
			)
		);
	}

	/**
	 * Option-backed picture picker, same control the design presets use.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $option  Option name.
	 * @param string                $key     Key.
	 * @param string                $section Section.
	 * @param string                $label   Label.
	 * @param array                 $presets value => array{label,svg}.
	 * @param string                $def     Default.
	 * @param string                $width   Item width.
	 * @param string                $hint    Helper text under the control.
	 */
	private function opt_preset( \WP_Customize_Manager $c, string $option, string $key, string $section, string $label, array $presets, string $def, string $width, string $hint = '' ): void {
		list( $id, $args ) = $this->opt_args(
			$option,
			$key,
			$def,
			static function ( $value ) use ( $presets, $def ): string {
				return array_key_exists( (string) $value, $presets ) ? (string) $value : $def;
			}
		);

		$c->add_setting( $id, $args );
		$c->add_control(
			new Customize\Preset_Control(
				$c,
				$id,
				array(
					'section'     => $section,
					'label'       => $label,
					'description' => $hint,
					'presets'     => $presets,
					'item_width'  => $width,
				)
			)
		);
	}
}
