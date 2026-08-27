<?php
/**
 * The block registry: every section type described once.
 *
 * One declaration drives three things — the composer's form, the sanitiser
 * and the renderer. Adding a block means describing it, never editing three
 * files. The same idea that runs the theme's menu panels, grown to page size.
 *
 * @package OC_Blocks
 */

declare( strict_types = 1 );

namespace OC\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Section types and their fields.
 */
final class Registry {

	/**
	 * Meta key holding a page's sections.
	 */
	public const META = '_oc_sections';

	/**
	 * The shell every section wears: visibility, width, background, spacing
	 * and entrance. Declared once, inherited by every type.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function shell(): array {
		return array(
			'on'      => array(
				'type'  => 'toggle',
				'label' => __( 'Section is on', 'oc-blocks' ),
				'def'   => 1,
			),
			'dev'     => array(
				'type'    => 'seg',
				'label'   => __( 'Shown on', 'oc-blocks' ),
				'choices' => array(
					'both'    => __( 'Everywhere', 'oc-blocks' ),
					'desktop' => __( 'Desktop', 'oc-blocks' ),
					'mobile'  => __( 'Mobile', 'oc-blocks' ),
				),
				'def'     => 'both',
			),
			'w'       => array(
				'type'    => 'seg',
				'label'   => __( 'Width', 'oc-blocks' ),
				'choices' => array(
					'full'    => __( 'Edge to edge', 'oc-blocks' ),
					'content' => __( 'Content width', 'oc-blocks' ),
					'custom'  => __( 'Custom', 'oc-blocks' ),
				),
				'def'     => 'content',
			),
			'wpx'     => array(
				'type'  => 'number',
				'label' => __( 'Custom width (px)', 'oc-blocks' ),
				'def'   => 900,
				'min'   => 300,
				'max'   => 2400,
				'when'  => array( 'w' => array( 'custom' ) ),
			),
			'bg'      => array(
				'type'    => 'seg',
				'label'   => __( 'Background', 'oc-blocks' ),
				'choices' => array(
					'none'     => __( 'None', 'oc-blocks' ),
					'color'    => __( 'Colour', 'oc-blocks' ),
					'gradient' => __( 'Gradient', 'oc-blocks' ),
					'image'    => __( 'Image', 'oc-blocks' ),
					'video'    => __( 'Video', 'oc-blocks' ),
				),
				'def'     => 'none',
			),
			'bg1'     => array(
				'type'  => 'color',
				'label' => __( 'Colour', 'oc-blocks' ),
				'when'  => array( 'bg' => array( 'color', 'gradient' ) ),
			),
			'bg2'     => array(
				'type'  => 'color',
				'label' => __( 'Second colour', 'oc-blocks' ),
				'when'  => array( 'bg' => array( 'gradient' ) ),
			),
			'bga'     => array(
				'type'  => 'number',
				'label' => __( 'Gradient angle', 'oc-blocks' ),
				'def'   => 160,
				'min'   => 0,
				'max'   => 360,
				'when'  => array( 'bg' => array( 'gradient' ) ),
			),
			'bgimg'   => array(
				'type'  => 'image',
				'label' => __( 'Background image', 'oc-blocks' ),
				'when'  => array( 'bg' => array( 'image' ) ),
			),
			'bgvid'   => array(
				'type'  => 'video',
				'label' => __( 'Background video', 'oc-blocks' ),
				'when'  => array( 'bg' => array( 'video' ) ),
			),
			'overlay' => array(
				'type'  => 'range',
				'label' => __( 'Darken the background (%)', 'oc-blocks' ),
				'def'   => 0,
				'when'  => array( 'bg' => array( 'image', 'video' ) ),
			),
			'pt'      => array(
				'type'    => 'seg',
				'label'   => __( 'Space above', 'oc-blocks' ),
				'choices' => self::spaces(),
				'def'     => 'm',
			),
			'pb'      => array(
				'type'    => 'seg',
				'label'   => __( 'Space below', 'oc-blocks' ),
				'choices' => self::spaces(),
				'def'     => 'm',
			),
			'enter'   => array(
				'type'    => 'seg',
				'label'   => __( 'Entrance', 'oc-blocks' ),
				'choices' => array(
					'none'    => __( 'None', 'oc-blocks' ),
					'fade'    => __( 'Fade', 'oc-blocks' ),
					'rise'    => __( 'Rise', 'oc-blocks' ),
					'stagger' => __( 'One by one', 'oc-blocks' ),
				),
				'def'     => 'fade',
			),
		);
	}

	/**
	 * Spacing steps.
	 *
	 * @return array<string,string>
	 */
	private static function spaces(): array {
		return array(
			'0' => __( 'None', 'oc-blocks' ),
			's' => __( 'Small', 'oc-blocks' ),
			'm' => __( 'Normal', 'oc-blocks' ),
			'l' => __( 'Large', 'oc-blocks' ),
		);
	}

	/**
	 * Where words may stand on a banner.
	 *
	 * @return array<string,string>
	 */
	private static function positions(): array {
		return array(
			'cc' => __( 'Centre', 'oc-blocks' ),
			'cs' => __( 'Centre, reading side', 'oc-blocks' ),
			'bc' => __( 'Bottom centre', 'oc-blocks' ),
			'bs' => __( 'Bottom, reading side', 'oc-blocks' ),
			'ts' => __( 'Top, reading side', 'oc-blocks' ),
		);
	}

	/**
	 * The stories block's gallery picker.
	 *
	 * When OC Story is active its galleries are offered by name in a
	 * dropdown; without it the field falls back to a typed id, so a saved
	 * choice survives the plugin being switched off and on.
	 *
	 * @return array<string,mixed>
	 */
	private static function story_gallery_field(): array {
		if ( ! class_exists( '\\OCS\\Model\\Placement' ) ) {
			return array(
				'type'  => 'text',
				'label' => __( 'Placement id (from OC Story)', 'oc-blocks' ),
				'hint'  => __( 'Empty shows the default gallery; a placement id from the OC Story screen shows that one.', 'oc-blocks' ),
			);
		}

		$surfaces = array(
			'circles'  => __( 'circles', 'oc-blocks' ),
			'slider'   => __( 'slider', 'oc-blocks' ),
			'grid'     => __( 'grid', 'oc-blocks' ),
			'product'  => __( 'product cards', 'oc-blocks' ),
			'floating' => __( 'floating video', 'oc-blocks' ),
		);

		$choices = array( '' => __( 'The default gallery — all stories, as circles', 'oc-blocks' ) );

		foreach ( \OCS\Model\Placement::all() as $id => $placement ) {
			if ( empty( $placement['enabled'] ) ) {
				continue;
			}

			$label   = '' !== (string) $placement['label'] ? (string) $placement['label'] : $id;
			$surface = (string) ( $placement['surface'] ?? 'circles' );

			$choices[ $id ] = isset( $surfaces[ $surface ] )
				? $label . ' · ' . $surfaces[ $surface ]
				: $label;
		}

		return array(
			'type'    => 'select',
			'label'   => __( 'Which gallery', 'oc-blocks' ),
			'choices' => $choices,
			'def'     => '',
			'hint'    => __( 'The galleries defined in OC Story. How it looks — circles, slider, grid — travels with the gallery.', 'oc-blocks' ),
		);
	}

	/**
	 * The branches block's region picker — the site's own regions, by name.
	 *
	 * @return array<string,mixed>
	 */
	private static function branch_region_field(): array {
		$choices = array( '' => __( '— choose a region —', 'oc-blocks' ) );

		if ( taxonomy_exists( Branches::TAX ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => Branches::TAX,
					'hide_empty' => false,
				)
			);

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( $term instanceof \WP_Term ) {
						$choices[ (string) $term->term_id ] = $term->name;
					}
				}
			}
		}

		return array(
			'type'    => 'select',
			'label'   => __( 'Which region', 'oc-blocks' ),
			'choices' => $choices,
			'def'     => '',
			'when'    => array( 'source' => array( 'region' ) ),
		);
	}

	/**
	 * The icon library's names, for the picker.
	 *
	 * @return array<string,string>
	 */
	private static function icon_choices(): array {
		return array(
			'truck'    => __( 'Delivery truck', 'oc-blocks' ),
			'returns'  => __( 'Easy returns', 'oc-blocks' ),
			'badge'    => __( 'Trust badge', 'oc-blocks' ),
			'shield'   => __( 'Warranty shield', 'oc-blocks' ),
			'card'     => __( 'Payment card', 'oc-blocks' ),
			'support'  => __( 'Support headset', 'oc-blocks' ),
			'gift'     => __( 'Gift', 'oc-blocks' ),
			'star'     => __( 'Star', 'oc-blocks' ),
			'heart'    => __( 'Heart', 'oc-blocks' ),
			'leaf'     => __( 'Leaf', 'oc-blocks' ),
			'clock'    => __( 'Clock', 'oc-blocks' ),
			'box'      => __( 'Package', 'oc-blocks' ),
			'phone'    => __( 'Handset', 'oc-blocks' ),
			'tools'    => __( 'Assembly tools', 'oc-blocks' ),
			'armchair' => __( 'Armchair', 'oc-blocks' ),
			'ruler'    => __( 'Measuring tape', 'oc-blocks' ),
			'sparkle'  => __( 'Sparkle', 'oc-blocks' ),
		);
	}

	/**
	 * Every section type.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types(): array {
		$types = array(

			'hero'       => array(
				'label'  => __( 'Banner / slider', 'oc-blocks' ),
				'blurb'  => __( 'One picture is a banner; several are a slider.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2" opacity=".4"/><path d="M6 12h0M18 12h0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="10" cy="19.5" r="0" /><path d="M9 21.5h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".6"/></svg>',
				'fields' => array(
					'slides'   => array(
						'type'   => 'slides',
						'label'  => __( 'Slides', 'oc-blocks' ),
						'sub'    => array(
							'img'     => array(
								'type'  => 'image',
								'label' => __( 'Picture — desktop', 'oc-blocks' ),
							),
							'imgm'    => array(
								'type'  => 'image',
								'label' => __( 'Picture — mobile (optional)', 'oc-blocks' ),
							),
							'vid'     => array(
								'type'  => 'video',
								'label' => __( 'Or a video', 'oc-blocks' ),
							),
							'heading' => array(
								'type'  => 'text',
								'label' => __( 'Heading', 'oc-blocks' ),
							),
							'text'    => array(
								'type'  => 'text',
								'label' => __( 'A line under it', 'oc-blocks' ),
							),
							'cta'     => array(
								'type'  => 'text',
								'label' => __( 'Button words', 'oc-blocks' ),
							),
							'url'     => array(
								'type'  => 'url',
								'label' => __( 'Leads to', 'oc-blocks' ),
							),
							'cta2'    => array(
								'type'  => 'text',
								'label' => __( 'Second button — outline (optional)', 'oc-blocks' ),
							),
							'url2'    => array(
								'type'  => 'url',
								'label' => __( 'The second button leads to', 'oc-blocks' ),
							),
							'cd'      => array(
								'type'  => 'text',
								'label' => __( 'Countdown until (optional)', 'oc-blocks' ),
								'hint'  => __( 'Date and time, like: 2026-09-30 23:59 — a ticking clock joins the slide.', 'oc-blocks' ),
							),
						),
					),
					'pos'      => array(
						'type'    => 'seg',
						'label'   => __( 'Words sit', 'oc-blocks' ),
						'choices' => self::positions(),
						'def'     => 'cc',
						'group'   => 'design',
					),
					'tone'     => array(
						'type'    => 'seg',
						'label'   => __( 'Words are', 'oc-blocks' ),
						'choices' => array(
							'light' => __( 'Light', 'oc-blocks' ),
							'dark'  => __( 'Dark', 'oc-blocks' ),
						),
						'def'     => 'light',
						'group'   => 'design',
					),
					'shade'    => array(
						'type'  => 'range',
						'label' => __( 'Darken the picture (%)', 'oc-blocks' ),
						'def'   => 20,
						'group' => 'design',
					),
					'h'        => array(
						'type'  => 'number',
						'label' => __( 'Height — desktop (px)', 'oc-blocks' ),
						'hint'  => __( '0 shows the picture at its natural height.', 'oc-blocks' ),
						'def'   => 560,
						'min'   => 0,
						'max'   => 1200,
						'group' => 'design',
					),
					'hm'       => array(
						'type'  => 'number',
						'label' => __( 'Height — mobile (px)', 'oc-blocks' ),
						'hint'  => __( '0 shows the picture at its natural height.', 'oc-blocks' ),
						'def'   => 440,
						'min'   => 0,
						'max'   => 900,
						'group' => 'design',
					),
					'effect'   => array(
						'type'    => 'seg',
						'label'   => __( 'Slide change', 'oc-blocks' ),
						'choices' => array(
							'slide' => __( 'Slide', 'oc-blocks' ),
							'fade'  => __( 'Fade', 'oc-blocks' ),
						),
						'def'     => 'slide',
						'group'   => 'design',
					),
					'auto'     => array(
						'type'  => 'number',
						'label' => __( 'Change every (seconds, 0 off)', 'oc-blocks' ),
						'def'   => 5,
						'min'   => 0,
						'max'   => 20,
						'group' => 'design',
					),
					'arrows'   => array(
						'type'  => 'toggle',
						'label' => __( 'Arrows', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
					'dots'     => array(
						'type'  => 'toggle',
						'label' => __( 'Dots', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
					'parallax' => array(
						'type'  => 'range',
						'label' => __( 'Parallax strength', 'oc-blocks' ),
						'hint'  => __( '0 is off; around 30 is gentle; at 100 the picture stands still and the page glides over it.', 'oc-blocks' ),
						'def'   => 0,
						'group' => 'design',
					),
					'txtc'     => array(
						'type'  => 'color',
						'label' => __( 'Text colour', 'oc-blocks' ),
						'hint'  => __( 'Empty follows the light/dark tone.', 'oc-blocks' ),
						'group' => 'design',
					),
					'ctac'     => array(
						'type'  => 'color',
						'label' => __( 'Button colour', 'oc-blocks' ),
						'group' => 'design',
					),
					'ctat'     => array(
						'type'  => 'color',
						'label' => __( 'Button text colour', 'oc-blocks' ),
						'group' => 'design',
					),
					'fadebg'   => array(
						'type'  => 'color',
						'label' => __( 'The colour between slides (fade)', 'oc-blocks' ),
						'hint'  => __( 'The slide breathes out into this before the next one arrives. Empty is near-black.', 'oc-blocks' ),
						'when'  => array( 'effect' => array( 'fade' ) ),
						'group' => 'design',
					),
					'cdpos'    => array(
						'type'    => 'seg',
						'label'   => __( 'Countdown sits', 'oc-blocks' ),
						'choices' => array(
							'under' => __( 'Under the words', 'oc-blocks' ),
							'side'  => __( 'On the other side', 'oc-blocks' ),
						),
						'def'     => 'side',
						'group'   => 'design',
					),
				),
			),

			'content'    => array(
				'label'  => __( 'Words', 'oc-blocks' ),
				'blurb'  => __( 'A heading, a few lines, a button.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><path d="M4 6h16M4 11h16M4 16h9" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".8"/></svg>',
				'fields' => array(
					'eyebrow' => array(
						'type'  => 'text',
						'label' => __( 'Little line above', 'oc-blocks' ),
					),
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'text'    => array(
						'type'  => 'textarea',
						'label' => __( 'The words', 'oc-blocks' ),
					),
					'cta'     => array(
						'type'  => 'text',
						'label' => __( 'Button words', 'oc-blocks' ),
					),
					'url'     => array(
						'type'  => 'url',
						'label' => __( 'Leads to', 'oc-blocks' ),
					),
					'align'   => array(
						'type'    => 'seg',
						'label'   => __( 'Aligned', 'oc-blocks' ),
						'choices' => array(
							'start'  => __( 'To the side', 'oc-blocks' ),
							'center' => __( 'Centred', 'oc-blocks' ),
						),
						'def'     => 'center',
						'group'   => 'design',
					),
					'btn'     => array(
						'type'    => 'seg',
						'label'   => __( 'Button style', 'oc-blocks' ),
						'choices' => array(
							'theme'   => __( 'Theme button', 'oc-blocks' ),
							'minimal' => __( 'Underlined word', 'oc-blocks' ),
						),
						'def'     => 'theme',
						'group'   => 'design',
					),
				),
			),

			'products'   => array(
				'label'  => __( 'Products', 'oc-blocks' ),
				'blurb'  => __( 'A shelf of products, grid or slider.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="8" height="9" rx="1.5" opacity=".45"/><rect x="13" y="4" width="8" height="9" rx="1.5" opacity=".45"/><path d="M3 16h8M13 16h8M3 19.5h5M13 19.5h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
				'fields' => array(
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'mode'    => array(
						'type'    => 'seg',
						'label'   => __( 'Which products', 'oc-blocks' ),
						'choices' => array(
							'cat'    => __( 'From a category', 'oc-blocks' ),
							'manual' => __( 'The ones I choose', 'oc-blocks' ),
							'sales'  => __( 'Best sellers', 'oc-blocks' ),
							'new'    => __( 'Newest', 'oc-blocks' ),
							'sale'   => __( 'On sale', 'oc-blocks' ),
							'viewed' => __( 'Products they viewed', 'oc-blocks' ),
						),
						'def'     => 'cat',
					),
					'cat'     => array(
						'type'  => 'category',
						'label' => __( 'The category', 'oc-blocks' ),
						'when'  => array( 'mode' => array( 'cat' ) ),
					),
					'picks'   => array(
						'type'  => 'products',
						'label' => __( 'The products', 'oc-blocks' ),
						'when'  => array( 'mode' => array( 'manual' ) ),
					),
					'count'   => array(
						'type'  => 'number',
						'label' => __( 'How many', 'oc-blocks' ),
						'def'   => 8,
						'min'   => 2,
						'max'   => 24,
					),
					'layout'  => array(
						'type'    => 'seg',
						'label'   => __( 'Laid as', 'oc-blocks' ),
						'choices' => array(
							'slider' => __( 'Slider', 'oc-blocks' ),
							'grid'   => __( 'Grid', 'oc-blocks' ),
						),
						'def'     => 'slider',
						'dev'     => 'd',
						'group'   => 'design',
					),
					'cols'    => array(
						'type'  => 'number',
						'label' => __( 'Per row — desktop', 'oc-blocks' ),
						'dev'   => 'd',
						'def'   => 4,
						'min'   => 2,
						'max'   => 6,
						'group' => 'design',
					),
					'gap'     => array(
						'type'    => 'seg',
						'label'   => __( 'Space between', 'oc-blocks' ),
						'choices' => array(
							'normal' => __( 'Normal', 'oc-blocks' ),
							'small'  => __( 'Small', 'oc-blocks' ),
							'tight'  => __( 'Touching', 'oc-blocks' ),
						),
						'def'     => 'normal',
						'group'   => 'design',
					),
					'all'     => array(
						'type'  => 'toggle',
						'label' => __( '"All products" button', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
					'mcols'   => array(
						'type'    => 'seg',
						'label'   => __( 'Mobile slider shows', 'oc-blocks' ),
						'choices' => array(
							'1' => __( 'One product and a peek', 'oc-blocks' ),
							'2' => __( 'Two products and a peek', 'oc-blocks' ),
						),
						'def'     => '1',
						'dev'     => 'm',
						'group'   => 'design',
					),
				'allurl'  => array(
						'type'  => 'url',
						'label' => __( 'It leads to (empty: the category)', 'oc-blocks' ),
						'when'  => array( 'all' => array( '1' ) ),
						'group' => 'design',
					),
				),
			),

			'categories' => array(
				'label'  => __( 'Categories', 'oc-blocks' ),
				'blurb'  => __( 'Doors to the aisles — circles, cards, a slider.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><circle cx="7" cy="7" r="4" opacity=".45"/><circle cx="17" cy="7" r="4" opacity=".45"/><circle cx="7" cy="17" r="4" opacity=".45"/><circle cx="17" cy="17" r="4" opacity=".45"/></svg>',
				'fields' => array(
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'cats'    => array(
						'type'  => 'cats',
						'label' => __( 'Which categories', 'oc-blocks' ),
						'hint'  => __( 'Tick in the order they should stand.', 'oc-blocks' ),
					),
					'shape'   => array(
						'type'    => 'seg',
						'label'   => __( 'Shape', 'oc-blocks' ),
						'choices' => array(
							'circle'   => __( 'Circles', 'oc-blocks' ),
							'square'   => __( 'Squares', 'oc-blocks' ),
							'portrait' => __( 'Upright', 'oc-blocks' ),
						),
						'def'     => 'circle',
						'group'   => 'design',
					),
					'corners' => array(
						'type'    => 'seg',
						'label'   => __( 'Corners', 'oc-blocks' ),
						'choices' => array(
							'sharp' => __( 'Sharp', 'oc-blocks' ),
							'soft'  => __( 'Softened', 'oc-blocks' ),
						),
						'def'     => 'soft',
						'when'    => array( 'shape' => array( 'square', 'portrait' ) ),
						'group'   => 'design',
					),
					'words'   => array(
						'type'    => 'seg',
						'label'   => __( 'The name sits', 'oc-blocks' ),
						'choices' => array(
							'under'  => __( 'Under', 'oc-blocks' ),
							'center' => __( 'On it, centred', 'oc-blocks' ),
							'bottom' => __( 'On it, at the bottom', 'oc-blocks' ),
						),
						'def'     => 'under',
						'group'   => 'design',
					),
					'layout'  => array(
						'type'    => 'seg',
						'label'   => __( 'Laid as', 'oc-blocks' ),
						'choices' => array(
							'slider' => __( 'Slider', 'oc-blocks' ),
							'grid'   => __( 'Grid', 'oc-blocks' ),
						),
						'def'     => 'slider',
						'dev'     => 'd',
						'group'   => 'design',
					),
					'cols'    => array(
						'type'  => 'number',
						'label' => __( 'Per row — desktop', 'oc-blocks' ),
						'dev'   => 'd',
						'def'   => 5,
						'min'   => 2,
						'max'   => 8,
						'group' => 'design',
					),
					'gap'     => array(
						'type'    => 'seg',
						'label'   => __( 'Space between', 'oc-blocks' ),
						'choices' => array(
							'normal' => __( 'Normal', 'oc-blocks' ),
							'small'  => __( 'Small', 'oc-blocks' ),
							'large'  => __( 'Airy', 'oc-blocks' ),
							'none'   => __( 'No space', 'oc-blocks' ),
						),
						'def'     => 'normal',
						'group'   => 'design',
					),
					'hover'   => array(
						'type'    => 'seg',
						'label'   => __( 'Under the cursor', 'oc-blocks' ),
						'choices' => array(
							'zoom' => __( 'Gentle zoom', 'oc-blocks' ),
							'none' => __( 'Nothing', 'oc-blocks' ),
						),
						'def'     => 'zoom',
						'group'   => 'design',
					),
					'mlay'    => array(
						'type'    => 'seg',
						'label'   => __( 'Mobile layout', 'oc-blocks' ),
						'choices' => array(
							'1'      => __( 'One per row', 'oc-blocks' ),
							'2'      => __( 'Two per row', 'oc-blocks' ),
							'3'      => __( 'Three per row', 'oc-blocks' ),
							'slider' => __( 'Slider', 'oc-blocks' ),
						),
						'def'     => '2',
						'dev'     => 'm',
						'group'   => 'design',
					),
				),
			),

			'marquee'    => array(
				'label'  => __( 'Running line', 'oc-blocks' ),
				'blurb'  => __( 'A line of words on the move.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><path d="M2 12h14M13 8l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity=".8"/><path d="M20 5v14" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".3"/></svg>',
				'fields' => array(
					'text'  => array(
						'type'  => 'text',
						'label' => __( 'The words', 'oc-blocks' ),
						'def'   => '',
					),
					'size'  => array(
						'type'  => 'number',
						'label' => __( 'Letter size (px)', 'oc-blocks' ),
						'def'   => 42,
						'min'   => 14,
						'max'   => 160,
						'group' => 'design',
					),
					'sizem' => array(
						'type'  => 'number',
						'label' => __( 'Letter size — mobile (px)', 'oc-blocks' ),
						'def'   => 0,
						'min'   => 0,
						'max'   => 160,
						'hint'  => __( '0 = same as above. Smaller letters make a thinner strip.', 'oc-blocks' ),
						'group' => 'design',
					),
					'color' => array(
						'type'  => 'color',
						'label' => __( 'Letter colour', 'oc-blocks' ),
						'group' => 'design',
					),
					'speed' => array(
						'type'  => 'number',
						'label' => __( 'Speed (seconds per round)', 'oc-blocks' ),
						'def'   => 20,
						'min'   => 4,
						'max'   => 90,
						'group' => 'design',
					),
					'dir'   => array(
						'type'    => 'seg',
						'label'   => __( 'Moves', 'oc-blocks' ),
						'choices' => array(
							'rtl' => __( 'Right to left', 'oc-blocks' ),
							'ltr' => __( 'Left to right', 'oc-blocks' ),
						),
						'def'     => 'rtl',
						'group'   => 'design',
					),
					'angle' => array(
						'type'  => 'number',
						'label' => __( 'Angle (degrees)', 'oc-blocks' ),
						'def'   => 0,
						'min'   => -10,
						'max'   => 10,
						'group' => 'design',
					),
					'bgc'   => array(
						'type'  => 'color',
						'label' => __( 'Strip background', 'oc-blocks' ),
						'hint'  => __( 'Rides the angle with the words — a diagonal ribbon.', 'oc-blocks' ),
						'group' => 'design',
					),
				),
			),

			'brands'     => array(
				'label'  => __( 'Brands', 'oc-blocks' ),
				'blurb'  => __( 'The logos, in a quiet row.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><rect x="2.5" y="8" width="5.5" height="8" rx="1" opacity=".45"/><rect x="9.5" y="8" width="5.5" height="8" rx="1" opacity=".45"/><rect x="16.5" y="8" width="5.5" height="8" rx="1" opacity=".45"/></svg>',
				'fields' => array(
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'layout'  => array(
						'type'    => 'seg',
						'label'   => __( 'Laid as', 'oc-blocks' ),
						'choices' => array(
							'grid'   => __( 'Grid', 'oc-blocks' ),
							'slider' => __( 'Slider', 'oc-blocks' ),
						),
						'def'     => 'grid',
						'group'   => 'design',
					),
					'cols'    => array(
						'type'  => 'number',
						'label' => __( 'Per row — desktop', 'oc-blocks' ),
						'dev'   => 'd',
						'def'   => 5,
						'min'   => 2,
						'max'   => 8,
						'group' => 'design',
					),
					'gap'     => array(
						'type'    => 'seg',
						'label'   => __( 'Space between', 'oc-blocks' ),
						'choices' => array(
							'normal' => __( 'Normal', 'oc-blocks' ),
							'small'  => __( 'Small', 'oc-blocks' ),
							'large'  => __( 'Airy', 'oc-blocks' ),
							'none'   => __( 'No space', 'oc-blocks' ),
						),
						'def'     => 'normal',
						'group'   => 'design',
					),
				),
			),

			'posts'      => array(
				'label'  => __( 'From the blog', 'oc-blocks' ),
				'blurb'  => __( 'The latest posts, or chosen ones.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="5" rx="1.5" opacity=".45"/><path d="M3 13h18M3 17h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" opacity=".7"/></svg>',
				'fields' => array(
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'mode'    => array(
						'type'    => 'seg',
						'label'   => __( 'Which posts', 'oc-blocks' ),
						'choices' => array(
							'latest' => __( 'Latest', 'oc-blocks' ),
							'manual' => __( 'The ones I choose', 'oc-blocks' ),
						),
						'def'     => 'latest',
					),
					'picks'   => array(
						'type'  => 'posts',
						'label' => __( 'The posts', 'oc-blocks' ),
						'when'  => array( 'mode' => array( 'manual' ) ),
					),
					'count'   => array(
						'type'  => 'number',
						'label' => __( 'How many', 'oc-blocks' ),
						'def'   => 3,
						'min'   => 1,
						'max'   => 9,
					),
					'date'    => array(
						'type'  => 'toggle',
						'label' => __( 'Publish date', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
					'excerpt' => array(
						'type'  => 'toggle',
						'label' => __( 'Short description', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
				'layout'  => array(
						'type'    => 'seg',
						'label'   => __( 'Laid as', 'oc-blocks' ),
						'choices' => array(
							'grid'   => __( 'Grid', 'oc-blocks' ),
							'slider' => __( 'Slider', 'oc-blocks' ),
						),
						'def'     => 'grid',
						'dev'     => 'd',
						'group'   => 'design',
					),
					'corners' => array(
						'type'    => 'seg',
						'label'   => __( 'Picture corners', 'oc-blocks' ),
						'choices' => array(
							'soft'  => __( 'Softened', 'oc-blocks' ),
							'sharp' => __( 'Sharp', 'oc-blocks' ),
						),
						'def'     => 'soft',
						'group'   => 'design',
					),
					'mlay'    => array(
						'type'    => 'seg',
						'label'   => __( 'Mobile layout', 'oc-blocks' ),
						'choices' => array(
							'1'      => __( 'One per row', 'oc-blocks' ),
							'2'      => __( 'Two per row', 'oc-blocks' ),
							'slider' => __( 'Slider', 'oc-blocks' ),
						),
						'def'     => '1',
						'dev'     => 'm',
						'group'   => 'design',
					),
					'all'     => array(
						'type'  => 'toggle',
						'label' => __( '"To the blog" button', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
					'alltext' => array(
						'type'  => 'text',
						'label' => __( 'Its words', 'oc-blocks' ),
						'when'  => array( 'all' => array( '1' ) ),
						'group' => 'design',
					),
				),
			),
			'look'       => array(
				'label'  => __( 'Shop the Look', 'oc-blocks' ),
				'blurb'  => __( 'Rooms with tagged products — spots on the picture, cards beside it.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><rect x="2.5" y="4" width="19" height="16" rx="2" opacity=".35"/><circle cx="9" cy="10" r="2.2" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="15.5" cy="14.5" r="2.2" fill="none" stroke="currentColor" stroke-width="1.6" opacity=".7"/></svg>',
				'fields' => array(
					'scenes' => array(
						'type'  => 'slides',
						'label' => __( 'The rooms', 'oc-blocks' ),
						'def'   => array(),
						'sub'   => array(
							'heading' => array(
								'type'  => 'text',
								'label' => __( 'Room name (optional)', 'oc-blocks' ),
							),
							'img'     => array(
								'type'  => 'image',
								'label' => __( 'Picture — desktop', 'oc-blocks' ),
							),
							'imgm'    => array(
								'type'  => 'image',
								'label' => __( 'Picture — mobile (optional)', 'oc-blocks' ),
							),
							'spots'   => array(
								'type'  => 'spots',
								'label' => __( 'The products on it', 'oc-blocks' ),
								'def'   => array(),
							),
						),
					),
					'side'   => array(
						'type'    => 'seg',
						'label'   => __( 'The picture stands', 'oc-blocks' ),
						'choices' => array(
							'start' => __( 'On the right', 'oc-blocks' ),
							'end'   => __( 'On the left', 'oc-blocks' ),
						),
						'def'     => 'start',
						'group'   => 'design',
					),
				),
			),

			'scrolly'    => array(
				'label'  => __( 'Scrolling story', 'oc-blocks' ),
				'blurb'  => __( 'The picture stays; the words scroll past it, chapter by chapter.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="10" height="14" rx="1.5" opacity=".45"/><path d="M16 7h5.5M16 11h5.5M16 15h4M16 19h3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" opacity=".7"/></svg>',
				'fields' => array(
					'steps' => array(
						'type'  => 'slides',
						'label' => __( 'Chapters', 'oc-blocks' ),
						'sub'   => array(
							'img'     => array(
								'type'  => 'image',
								'label' => __( 'Picture', 'oc-blocks' ),
							),
							'vid'     => array(
								'type'  => 'video',
								'label' => __( 'Or a video', 'oc-blocks' ),
							),
							'heading' => array(
								'type'  => 'text',
								'label' => __( 'Heading', 'oc-blocks' ),
							),
							'text'    => array(
								'type'  => 'textarea',
								'label' => __( 'The words', 'oc-blocks' ),
							),
						),
					),
					'side'  => array(
						'type'    => 'seg',
						'label'   => __( 'The picture stands', 'oc-blocks' ),
						'choices' => array(
							'start' => __( 'Reading side', 'oc-blocks' ),
							'end'   => __( 'Far side', 'oc-blocks' ),
						),
						'def'     => 'start',
						'group'   => 'design',
					),
				),
			),

			'media'      => array(
				'label'  => __( 'Picture & words', 'oc-blocks' ),
				'blurb'  => __( 'An editorial split: pictures on one side, a story on the other.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><rect x="2.5" y="4" width="9.5" height="16" rx="1.5" opacity=".45"/><path d="M15 7h6.5M15 11h6.5M15 15h4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".7"/></svg>',
				'fields' => array(
					'preset'  => array(
						'type'    => 'seg',
						'label'   => __( 'Arrangement', 'oc-blocks' ),
						'choices' => array(
							'single'  => __( 'One picture or film', 'oc-blocks' ),
							'overlap' => __( 'Picture with a little film', 'oc-blocks' ),
							'duo'     => __( 'Two pictures, staggered', 'oc-blocks' ),
							'canvas'  => __( 'Wide picture, small guest', 'oc-blocks' ),
						),
						'def'     => 'overlap',
					),
					'eyebrow' => array(
						'type'  => 'text',
						'label' => __( 'Little line above', 'oc-blocks' ),
						'hint'  => __( 'A collection name, a chapter — small letters over the heading.', 'oc-blocks' ),
					),
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'text'    => array(
						'type'  => 'textarea',
						'label' => __( 'The story', 'oc-blocks' ),
					),
					'cta'     => array(
						'type'  => 'text',
						'label' => __( 'Button (optional)', 'oc-blocks' ),
					),
					'url'     => array(
						'type'  => 'url',
						'label' => __( 'The button leads to', 'oc-blocks' ),
					),
					'img1'    => array(
						'type'  => 'image',
						'label' => __( 'The main picture', 'oc-blocks' ),
					),
					'img2'    => array(
						'type'  => 'image',
						'label' => __( 'The second picture', 'oc-blocks' ),
						'when'  => array( 'preset' => array( 'duo', 'canvas' ) ),
					),
					'vid'     => array(
						'type'  => 'video',
						'label' => __( 'Video (plays on its own, silent)', 'oc-blocks' ),
						'hint'  => __( 'In "one picture or film" it stands in for the picture; in "picture with a little film" it rides on top of it.', 'oc-blocks' ),
						'when'  => array( 'preset' => array( 'overlap', 'single' ) ),
					),
					'side'    => array(
						'type'    => 'seg',
						'label'   => __( 'The pictures stand', 'oc-blocks' ),
						'choices' => array(
							'start' => __( 'On the right', 'oc-blocks' ),
							'end'   => __( 'On the left', 'oc-blocks' ),
						),
						'def'     => 'end',
						'group'   => 'design',
					),
					'corners' => array(
						'type'    => 'seg',
						'label'   => __( 'Picture corners', 'oc-blocks' ),
						'choices' => array(
							'soft'  => __( 'Softened', 'oc-blocks' ),
							'sharp' => __( 'Sharp', 'oc-blocks' ),
						),
						'def'     => 'soft',
						'group'   => 'design',
					),
				),
			),
			'story'      => array(
				'label'  => __( 'Stories', 'oc-blocks' ),
				'blurb'  => __( 'An OC Story gallery, placed by hand.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><circle cx="6" cy="12" r="3.4" opacity=".55"/><circle cx="14" cy="12" r="3.4" opacity=".4"/><circle cx="21" cy="12" r="2.4" opacity=".25"/></svg>',
				'fields' => array(
					'heading'   => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'placement' => self::story_gallery_field(),
					'size'      => array(
						'type'  => 'number',
						'label' => __( 'Circle size (px, 0 = as set)', 'oc-blocks' ),
						'def'   => 0,
						'min'   => 0,
						'max'   => 200,
						'group' => 'design',
					),
					'labels'    => array(
						'type'  => 'toggle',
						'label' => __( 'Names under the circles', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
					'max'       => array(
						'type'  => 'number',
						'label' => __( 'At most (0 = all)', 'oc-blocks' ),
						'def'   => 0,
						'min'   => 0,
						'max'   => 30,
						'group' => 'design',
					),
					'align'   => array(
						'type'    => 'seg',
						'label'   => __( 'Sits', 'oc-blocks' ),
						'choices' => array(
							'start'  => __( 'To the reading side', 'oc-blocks' ),
							'center' => __( 'Centred', 'oc-blocks' ),
						),
						'def'     => 'start',
						'group'   => 'design',
					),
				),
			),

			'reviews'    => array(
				'label'  => __( 'Reviews', 'oc-blocks' ),
				'blurb'  => __( 'What buyers said — slider, grid or wall.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><path d="M12 3l2.6 5.3 5.9.9-4.2 4.1 1 5.8L12 16.3 6.7 19l1-5.8L3.5 9.2l5.9-.9z" opacity=".55"/></svg>',
				'fields' => array(
					'heading'    => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'layout'     => array(
						'type'    => 'seg',
						'label'   => __( 'Laid as', 'oc-blocks' ),
						'choices' => array(
							'slider' => __( 'Slider', 'oc-blocks' ),
							'grid'   => __( 'Grid', 'oc-blocks' ),
							'wall'   => __( 'Photo wall', 'oc-blocks' ),
						),
						'def'     => 'slider',
					),
					'source'     => array(
						'type'    => 'seg',
						'label'   => __( 'Which reviews', 'oc-blocks' ),
						'choices' => array(
							'all'      => __( 'All', 'oc-blocks' ),
							'featured' => __( 'Featured', 'oc-blocks' ),
							'category' => __( 'From a category', 'oc-blocks' ),
						),
						'def'     => 'all',
					),
					'cat'        => array(
						'type'  => 'category',
						'label' => __( 'The category', 'oc-blocks' ),
						'when'  => array( 'source' => array( 'category' ) ),
					),
					'count'      => array(
						'type'  => 'number',
						'label' => __( 'How many', 'oc-blocks' ),
						'def'   => 8,
						'min'   => 2,
						'max'   => 24,
					),
					'cols'       => array(
						'type'  => 'number',
						'label' => __( 'Per row — desktop', 'oc-blocks' ),
						'def'   => 3,
						'min'   => 2,
						'max'   => 5,
						'group' => 'design',
					),
					'min_rating' => array(
						'type'  => 'number',
						'label' => __( 'Minimum stars', 'oc-blocks' ),
						'def'   => 4,
						'min'   => 1,
						'max'   => 5,
						'group' => 'design',
					),
					'media'      => array(
						'type'  => 'toggle',
						'label' => __( 'Only reviews with photos', 'oc-blocks' ),
						'def'   => 0,
						'group' => 'design',
					),
					'autoplay'   => array(
						'type'  => 'number',
						'label' => __( 'Slider moves every (seconds, 0 off)', 'oc-blocks' ),
						'def'   => 0,
						'min'   => 0,
						'max'   => 20,
						'group' => 'design',
					),
					'align'   => array(
						'type'    => 'seg',
						'label'   => __( 'Sits', 'oc-blocks' ),
						'choices' => array(
							'start'  => __( 'To the reading side', 'oc-blocks' ),
							'center' => __( 'Centred', 'oc-blocks' ),
						),
						'def'     => 'start',
						'group'   => 'design',
					),
				),
			),
			'faq'        => array(
				'label'  => __( 'Questions & answers', 'oc-blocks' ),
				'blurb'  => __( 'An accordion of common questions, marked up for Google.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><path d="M8.6 9a3.4 3.4 0 1 1 5.2 2.9c-1.1.7-1.8 1.3-1.8 2.6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".75"/><circle cx="12" cy="18.4" r="1.3" opacity=".75"/></svg>',
				'fields' => array(
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'items'   => array(
						'type'  => 'slides',
						'label' => __( 'The questions', 'oc-blocks' ),
						'def'   => array(),
						'sub'   => array(
							'q' => array(
								'type'  => 'text',
								'label' => __( 'The question', 'oc-blocks' ),
							),
							'a' => array(
								'type'  => 'textarea',
								'label' => __( 'The answer', 'oc-blocks' ),
							),
						),
					),
					'open'    => array(
						'type'    => 'seg',
						'label'   => __( 'To begin with', 'oc-blocks' ),
						'choices' => array(
							'first' => __( 'First one open', 'oc-blocks' ),
							'none'  => __( 'All closed', 'oc-blocks' ),
						),
						'def'     => 'first',
						'group'   => 'design',
					),
					'schema'  => array(
						'type'  => 'toggle',
						'label' => __( 'FAQ markup for Google', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
				),
			),

			'logos'      => array(
				'label'  => __( 'As featured in', 'oc-blocks' ),
				'blurb'  => __( 'A quiet row of press and partner logos.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><rect x="2" y="9" width="5.5" height="6" rx="1" opacity=".7"/><rect x="9.2" y="9" width="5.6" height="6" rx="1" opacity=".45"/><rect x="16.5" y="9" width="5.5" height="6" rx="1" opacity=".25"/></svg>',
				'fields' => array(
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'items'   => array(
						'type'  => 'slides',
						'label' => __( 'The logos', 'oc-blocks' ),
						'def'   => array(),
						'sub'   => array(
							'img' => array(
								'type'  => 'image',
								'label' => __( 'Logo', 'oc-blocks' ),
								'def'   => 0,
							),
							'url' => array(
								'type'  => 'url',
								'label' => __( 'Link (optional)', 'oc-blocks' ),
							),
						),
					),
					'size'    => array(
						'type'  => 'number',
						'label' => __( 'Logo height (px)', 'oc-blocks' ),
						'def'   => 44,
						'min'   => 20,
						'max'   => 120,
						'group' => 'design',
					),
					'gray'    => array(
						'type'  => 'toggle',
						'label' => __( 'Grey until hovered', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
					'move'    => array(
						'type'    => 'seg',
						'label'   => __( 'The row', 'oc-blocks' ),
						'choices' => array(
							'still'   => __( 'Stands still', 'oc-blocks' ),
							'marquee' => __( 'Drifts along', 'oc-blocks' ),
						),
						'def'     => 'still',
						'group'   => 'design',
					),
					'speed'   => array(
						'type'  => 'number',
						'label' => __( 'Speed (seconds per round)', 'oc-blocks' ),
						'def'   => 30,
						'min'   => 6,
						'max'   => 90,
						'when'  => array( 'move' => array( 'marquee' ) ),
						'group' => 'design',
					),
				),
			),

			'news'       => array(
				'label'  => __( 'Newsletter', 'oc-blocks' ),
				'blurb'  => __( 'An email signup. Addresses collect under Tools.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="2" opacity=".6"/><path d="M4.5 8.5l7.5 5.5 7.5-5.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".8"/></svg>',
				'fields' => array(
					'heading'     => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'text'        => array(
						'type'  => 'textarea',
						'label' => __( 'A few words above the field', 'oc-blocks' ),
					),
					'placeholder' => array(
						'type'  => 'text',
						'label' => __( 'Inside the email field', 'oc-blocks' ),
						'hint'  => __( 'Empty shows "Your email".', 'oc-blocks' ),
					),
					'button'      => array(
						'type'  => 'text',
						'label' => __( 'On the button', 'oc-blocks' ),
						'hint'  => __( 'Empty shows "Sign up".', 'oc-blocks' ),
					),
					'note'        => array(
						'type'  => 'text',
						'label' => __( 'Small print underneath', 'oc-blocks' ),
					),
				),
			),

			'countdown'  => array(
				'label'  => __( 'Countdown', 'oc-blocks' ),
				'blurb'  => __( 'Days, hours and minutes until a sale ends.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><circle cx="12" cy="13" r="8" fill="none" stroke="currentColor" stroke-width="2" opacity=".6"/><path d="M12 9.5v3.8l2.8 1.8M9.5 2.8h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".8"/></svg>',
				'fields' => array(
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'until'   => array(
						'type'  => 'text',
						'label' => __( 'Counting down to', 'oc-blocks' ),
						'hint'  => __( 'Date and time, like: 2026-09-30 23:59', 'oc-blocks' ),
					),
					'done'    => array(
						'type'  => 'text',
						'label' => __( 'When it reaches zero', 'oc-blocks' ),
						'hint'  => __( 'Shown instead of the numbers. Empty hides the whole section.', 'oc-blocks' ),
					),
					'size'    => array(
						'type'    => 'seg',
						'label'   => __( 'Number size', 'oc-blocks' ),
						'choices' => array(
							's' => __( 'Small', 'oc-blocks' ),
							'm' => __( 'Normal', 'oc-blocks' ),
							'l' => __( 'Large', 'oc-blocks' ),
						),
						'def'     => 'm',
						'group'   => 'design',
					),
				),
			),

			'branches'   => array(
				'label'  => __( 'Branches', 'oc-blocks' ),
				'blurb'  => __( 'The branches themselves, pulled from the Branches screen.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><path d="M12 21s-7-6.1-7-11a7 7 0 1 1 14 0c0 4.9-7 11-7 11z" fill="none" stroke="currentColor" stroke-width="2" opacity=".65"/><circle cx="12" cy="10" r="2.6" opacity=".7"/></svg>',
				'fields' => array(
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'source'  => array(
						'type'    => 'seg',
						'label'   => __( 'Which branches', 'oc-blocks' ),
						'choices' => array(
							'all'    => __( 'All of them', 'oc-blocks' ),
							'region' => __( 'One region', 'oc-blocks' ),
						),
						'def'     => 'all',
					),
					'region'  => self::branch_region_field(),
					'search'  => array(
						'type'  => 'toggle',
						'label' => __( 'Search box', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
					'icon'    => array(
						'type'    => 'seg',
						'label'   => __( 'Little icon by the name', 'oc-blocks' ),
						'choices' => array(
							'pin'   => __( 'Map pin', 'oc-blocks' ),
							'shop'  => __( 'Shopfront', 'oc-blocks' ),
							'tree'  => __( 'Tree', 'oc-blocks' ),
							'phone' => __( 'Handset', 'oc-blocks' ),
							'none'  => __( 'None', 'oc-blocks' ),
						),
						'def'     => 'pin',
						'group'   => 'design',
					),
					'map'     => array(
						'type'  => 'toggle',
						'label' => __( 'Show a map', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
					'strip'   => array(
						'type'  => 'toggle',
						'label' => __( 'Branch photos underneath', 'oc-blocks' ),
						'def'   => 1,
						'group' => 'design',
					),
				),
			),

			'contact'    => array(
				'label'  => __( 'Contact form', 'oc-blocks' ),
				'blurb'  => __( 'Name, phone, a message — straight into the Leads screen.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><path d="M21 3L10.5 13.5M21 3l-6.5 18-4-7.5L3 9.5 21 3z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" opacity=".75"/></svg>',
				'fields' => array(
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'text'    => array(
						'type'  => 'textarea',
						'label' => __( 'A few words above the form', 'oc-blocks' ),
					),
					'phone'   => array(
						'type'  => 'toggle',
						'label' => __( 'Phone field', 'oc-blocks' ),
						'def'   => 1,
					),
					'email'   => array(
						'type'  => 'toggle',
						'label' => __( 'Email field', 'oc-blocks' ),
						'def'   => 1,
					),
					'msg'     => array(
						'type'  => 'toggle',
						'label' => __( 'Message field', 'oc-blocks' ),
						'def'   => 1,
					),
					'button'  => array(
						'type'  => 'text',
						'label' => __( 'On the button', 'oc-blocks' ),
						'hint'  => __( 'Empty shows "Send".', 'oc-blocks' ),
					),
					'thanks'  => array(
						'type'  => 'text',
						'label' => __( 'After it is sent', 'oc-blocks' ),
						'hint'  => __( 'Empty shows "Thank you — we will be in touch shortly."', 'oc-blocks' ),
					),
					'consent' => array(
						'type'  => 'toggle',
						'label' => __( 'Required consent checkbox', 'oc-blocks' ),
						'def'   => 1,
					),
					'consent_text' => array(
						'type'  => 'text',
						'label' => __( 'The consent wording', 'oc-blocks' ),
						'hint'  => __( 'Empty shows "I have read and accept the privacy policy." — the policy words link to the privacy page.', 'oc-blocks' ),
						'when'  => array( 'consent' => array( '1' ) ),
					),
				),
			),
			'icons'      => array(
				'label'  => __( 'Icon columns', 'oc-blocks' ),
				'blurb'  => __( 'Little promises in a row — shipping, returns, service.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><circle cx="5" cy="7" r="2.4" opacity=".7"/><circle cx="12" cy="7" r="2.4" opacity=".5"/><circle cx="19" cy="7" r="2.4" opacity=".3"/><path d="M3 13h4M10 13h4M17 13h4M3.5 17h3M10.5 17h3M17.5 17h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" opacity=".5"/></svg>',
				'fields' => array(
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-blocks' ),
					),
					'items'   => array(
						'type'  => 'slides',
						'label' => __( 'The columns', 'oc-blocks' ),
						'def'   => array(),
						'sub'   => array(
							'icon'    => array(
								'type'    => 'select',
								'label'   => __( 'The icon', 'oc-blocks' ),
								'choices' => self::icon_choices(),
								'def'     => 'truck',
							),
							'heading' => array(
								'type'  => 'text',
								'label' => __( 'Little heading', 'oc-blocks' ),
							),
							'text'    => array(
								'type'  => 'textarea',
								'label' => __( 'A line or two', 'oc-blocks' ),
							),
						),
					),
					'size'    => array(
						'type'    => 'seg',
						'label'   => __( 'Icon size', 'oc-blocks' ),
						'choices' => array(
							's' => __( 'Small', 'oc-blocks' ),
							'm' => __( 'Normal', 'oc-blocks' ),
							'l' => __( 'Large', 'oc-blocks' ),
						),
						'def'     => 'm',
						'group'   => 'design',
					),
					'bg'      => array(
						'type'  => 'toggle',
						'label' => __( 'Round background behind the icon', 'oc-blocks' ),
						'def'   => 0,
						'group' => 'design',
					),
					'bgc'     => array(
						'type'  => 'color',
						'label' => __( 'Circle colour', 'oc-blocks' ),
						'hint'  => __( 'Empty takes a soft tone from the theme.', 'oc-blocks' ),
						'when'  => array( 'bg' => array( '1' ) ),
						'group' => 'design',
					),
					'ic'      => array(
						'type'  => 'color',
						'label' => __( 'Icon colour', 'oc-blocks' ),
						'group' => 'design',
					),
					'mlay'    => array(
						'type'    => 'seg',
						'label'   => __( 'Mobile layout', 'oc-blocks' ),
						'choices' => array(
							'1'      => __( 'One per row', 'oc-blocks' ),
							'2'      => __( 'Two per row', 'oc-blocks' ),
							'slider' => __( 'One at a time, rotating', 'oc-blocks' ),
						),
						'def'     => '1',
						'dev'     => 'm',
						'group'   => 'design',
					),
				),
			),
		);

		/**
		 * Section types a page may hold.
		 *
		 * @param array<string,array<string,mixed>> $types Types.
		 */
		return (array) apply_filters( 'oc_blocks_types', $types );
	}

	/**
	 * Bring a stored or posted structure back to something the renderer can
	 * trust: known types, known values, and nothing else.
	 *
	 * @param array<int|string,mixed> $raw Sections.
	 * @return array<int,array<string,mixed>>
	 */
	public static function clean( array $raw ): array {
		$types = self::types();
		$shell = self::shell();
		$out   = array();

		foreach ( $raw as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$type = isset( $section['type'] ) ? (string) $section['type'] : '';

			if ( ! isset( $types[ $type ] ) ) {
				continue;
			}

			$clean = array( 'type' => $type );

			foreach ( $shell as $key => $field ) {
				$clean[ $key ] = self::clean_field( $field, $section[ $key ] ?? ( $field['def'] ?? '' ) );
			}

			foreach ( (array) $types[ $type ]['fields'] as $key => $field ) {
				$clean[ $key ] = self::clean_field( $field, $section[ $key ] ?? ( $field['def'] ?? '' ) );
			}

			$out[] = $clean;

			if ( count( $out ) >= 40 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * One field, cleaned by its declared kind.
	 *
	 * @param array<string,mixed> $field Field declaration.
	 * @param mixed               $value Raw value.
	 * @return mixed
	 */
	private static function clean_field( array $field, $value ) {
		switch ( (string) $field['type'] ) {
			case 'image':
				return absint( is_scalar( $value ) ? $value : 0 );

			case 'video':
			case 'url':
				return esc_url_raw( trim( (string) ( is_scalar( $value ) ? $value : '' ) ) );

			case 'toggle':
				return empty( $value ) ? 0 : 1;

			case 'range':
				return max( 0, min( 100, absint( is_scalar( $value ) ? $value : 0 ) ) );

			case 'number':
				$min = (int) ( $field['min'] ?? 0 );
				$max = (int) ( $field['max'] ?? 9999 );

				return max( $min, min( $max, (int) ( is_numeric( $value ) ? $value : ( $field['def'] ?? $min ) ) ) );

			case 'color':
				$color = sanitize_hex_color( (string) ( is_scalar( $value ) ? $value : '' ) );

				return null === $color ? '' : $color;

			case 'category':
				return absint( is_scalar( $value ) ? $value : 0 );

			case 'products':
			case 'posts':
			case 'cats':
				$ids = array();

				foreach ( (array) $value as $one ) {
					$id = absint( is_array( $one ) ? ( $one['id'] ?? 0 ) : $one );

					if ( $id > 0 ) {
						$ids[] = $id;
					}

					if ( count( $ids ) >= 40 ) {
						break;
					}
				}

				return array_values( array_unique( $ids ) );

			case 'seg':
			case 'select':
				$choices = (array) ( $field['choices'] ?? array() );
				$def     = (string) ( $field['def'] ?? (string) array_key_first( $choices ) );

				return isset( $choices[ (string) ( is_scalar( $value ) ? $value : '' ) ] ) ? (string) $value : $def;

			case 'spots':
				$rows = array();

				foreach ( (array) $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$rows[] = array(
						'x'  => max( 0, min( 100, absint( $row['x'] ?? 50 ) ) ),
						'y'  => max( 0, min( 100, absint( $row['y'] ?? 50 ) ) ),
						'id' => absint( $row['id'] ?? 0 ),
					);

					if ( count( $rows ) >= 12 ) {
						break;
					}
				}

				return $rows;

			case 'slides':
				$rows = array();

				foreach ( (array) $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$clean = array();

					foreach ( (array) ( $field['sub'] ?? array() ) as $key => $sub ) {
						$clean[ $key ] = self::clean_field( $sub, $row[ $key ] ?? ( $sub['def'] ?? '' ) );
					}

					$rows[] = $clean;

					if ( count( $rows ) >= 12 ) {
						break;
					}
				}

				return $rows;

			case 'textarea':
				return sanitize_textarea_field( (string) ( is_scalar( $value ) ? $value : '' ) );

			default:
				return sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) );
		}
	}

	/**
	 * A page's sections, cleaned.
	 *
	 * @param int $page_id Page id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sections( int $page_id ): array {
		$raw = get_post_meta( $page_id, self::META, true );

		return is_array( $raw ) ? self::clean( $raw ) : array();
	}
}
