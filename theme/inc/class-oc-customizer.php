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

		$this->design_panel( $wp_customize );
		$this->catalog_panel( $wp_customize );
		$this->card_section( $wp_customize );
		$this->product_section( $wp_customize );
	}

	/* ---------------------------------------------------------------------
	 * Panels and sections
	 * ------------------------------------------------------------------- */

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

		$this->number( $c, 'oc_content_width_px', 'oc_design', __( 'Content width (px)', 'oc-theme' ), 1280, 960, 1600 );
	}

	/**
	 * Catalogue: grid, ordering, page header.
	 *
	 * @param \WP_Customize_Manager $c Customizer manager.
	 */
	private function catalog_panel( \WP_Customize_Manager $c ): void {
		$c->add_section(
			'oc_catalog',
			array(
				'title'    => __( 'Catalogue & archive', 'oc-theme' ),
				'priority' => 11,
			)
		);

		$this->choice(
			$c,
			'oc_breadcrumbs_pos',
			'oc_catalog',
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
			'oc_catalog',
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
			'oc_catalog',
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
			'oc_catalog',
			__( 'Products per row — mobile', 'oc-theme' ),
			array(
				'1' => '1',
				'2' => '2',
				'3' => '3',
			),
			'2'
		);

		$this->number( $c, 'oc_catalog_per_page', 'oc_catalog', __( 'Products per page (-1 shows all)', 'oc-theme' ), 24, -1, 200 );
	}

	/**
	 * Product card: preset, image behaviour, contents.
	 *
	 * @param \WP_Customize_Manager $c Customizer manager.
	 */
	private function card_section( \WP_Customize_Manager $c ): void {
		$c->add_section(
			'oc_card',
			array(
				'title'    => __( 'Product card', 'oc-theme' ),
				'priority' => 12,
			)
		);

		$this->preset(
			$c,
			'oc_card_preset',
			'oc_card',
			__( 'Card preset', 'oc-theme' ),
			array(
				'classic' => array(
					'label' => __( 'Classic', 'oc-theme' ),
					'hint'  => __( 'Border, centred info', 'oc-theme' ),
					'svg'   => self::wf( '0 0 80 100', self::rect( 1, 1, 78, 98, 'bd', 3 ) . self::rect( 7, 7, 66, 58, 'im', 2 ) . self::rect( 24, 72, 32, 3.4, 'ln' ) . self::rect( 31, 80, 18, 3.4, 'ac' ) ),
				),
				'minimal' => array(
					'label' => __( 'Minimal', 'oc-theme' ),
					'hint'  => __( 'No border, start-aligned', 'oc-theme' ),
					'svg'   => self::wf( '0 0 80 100', self::rect( 3, 3, 74, 62, 'im', 2 ) . self::rect( 45, 72, 32, 3.4, 'ln' ) . self::rect( 59, 80, 18, 3.4, 'ac' ) ),
				),
				'card'    => array(
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

		$this->number( $c, 'oc_card_gallery_max', 'oc_card', __( 'Gallery: max images', 'oc-theme' ), 4, 2, 8 );

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
	 * @param \WP_Customize_Manager $c Customizer manager.
	 */
	private function product_section( \WP_Customize_Manager $c ): void {
		$c->add_section(
			'oc_product',
			array(
				'title'    => __( 'Product page', 'oc-theme' ),
				'priority' => 13,
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
				'mosaic'       => array(
					'label' => __( 'Mosaic', 'oc-theme' ),
					'hint'  => __( 'Pairs, one wide image', 'oc-theme' ),
					'svg'   => self::wf( '0 0 132 84', self::rect( 6, 4, 58, 34, 'im', 3 ) . self::rect( 68, 4, 58, 34, 'im', 3 ) . self::rect( 6, 42, 120, 38, 'ac', 3 ) ),
				),
			),
			'thumbs-side',
			'200px'
		);

		$this->choice(
			$c,
			'oc_gallery_mosaic_wide_pos',
			'oc_product',
			__( 'Mosaic: wide image position', 'oc-theme' ),
			array(
				'start' => __( 'First', 'oc-theme' ),
				'end'   => __( 'Last', 'oc-theme' ),
			),
			'end'
		);

		$this->number( $c, 'oc_gallery_thumbs_max', 'oc_product', __( 'Max thumbnails', 'oc-theme' ), 5, 3, 10 );
		$this->toggle( $c, 'oc_gallery_lightbox', 'oc_product', __( 'Open images in a lightbox', 'oc-theme' ), true );
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

		$this->toggle( $c, 'oc_product_related', 'oc_product', __( 'Show related products', 'oc-theme' ), true );
	}

	/* ---------------------------------------------------------------------
	 * Setting helpers — every setting gets a sanitiser
	 * ------------------------------------------------------------------- */

	/**
	 * Radio/select setting with a whitelist sanitiser.
	 *
	 * @param \WP_Customize_Manager $c       Manager.
	 * @param string                $id      Setting id.
	 * @param string                $section Section id.
	 * @param string                $label   Label.
	 * @param array<string,string>  $choices Choices.
	 * @param string                $default Default value.
	 */
	private function choice( \WP_Customize_Manager $c, string $id, string $section, string $label, array $choices, string $default ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => $default,
				'sanitize_callback' => static function ( $value ) use ( $choices, $default ): string {
					return array_key_exists( (string) $value, $choices ) ? (string) $value : $default;
				},
			)
		);

		$c->add_control(
			$id,
			array(
				'type'    => 'radio',
				'section' => $section,
				'label'   => $label,
				'choices' => $choices,
			)
		);
	}

	/**
	 * Drawn preset picker with a whitelist sanitiser.
	 *
	 * @param \WP_Customize_Manager               $c       Manager.
	 * @param string                              $id      Setting id.
	 * @param string                              $section Section id.
	 * @param string                              $label   Label.
	 * @param array<string,array<string,string>>  $presets Presets.
	 * @param string                              $default Default value.
	 * @param string                              $width   Item width.
	 */
	private function preset( \WP_Customize_Manager $c, string $id, string $section, string $label, array $presets, string $default, string $width ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => $default,
				'sanitize_callback' => static function ( $value ) use ( $presets, $default ): string {
					return array_key_exists( (string) $value, $presets ) ? (string) $value : $default;
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
	 * @param bool                  $default Default.
	 */
	private function toggle( \WP_Customize_Manager $c, string $id, string $section, string $label, bool $default ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => $default,
				'sanitize_callback' => static function ( $value ): bool {
					return (bool) $value;
				},
			)
		);

		$c->add_control(
			$id,
			array(
				'type'    => 'checkbox',
				'section' => $section,
				'label'   => $label,
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
	 * @param int                   $default Default.
	 * @param int                   $min     Minimum.
	 * @param int                   $max     Maximum.
	 */
	private function number( \WP_Customize_Manager $c, string $id, string $section, string $label, int $default, int $min, int $max ): void {
		$c->add_setting(
			$id,
			array(
				'default'           => $default,
				'sanitize_callback' => static function ( $value ) use ( $default, $min, $max ): int {
					$value = (int) $value;
					return ( $value >= $min && $value <= $max ) ? $value : $default;
				},
			)
		);

		$c->add_control(
			$id,
			array(
				'type'        => 'number',
				'section'     => $section,
				'label'       => $label,
				'input_attrs' => array(
					'min' => $min,
					'max' => $max,
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Wireframe vocabulary for preset drawings
	 * ------------------------------------------------------------------- */

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
