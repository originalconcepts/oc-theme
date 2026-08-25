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
						'def'   => 560,
						'min'   => 200,
						'max'   => 1200,
						'group' => 'design',
					),
					'hm'       => array(
						'type'  => 'number',
						'label' => __( 'Height — mobile (px)', 'oc-blocks' ),
						'def'   => 440,
						'min'   => 160,
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
						'type'  => 'toggle',
						'label' => __( 'Parallax', 'oc-blocks' ),
						'def'   => 0,
						'group' => 'design',
					),
				),
			),

			'content'    => array(
				'label'  => __( 'Words', 'oc-blocks' ),
				'blurb'  => __( 'A heading, a few lines, a button.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><path d="M4 6h16M4 11h16M4 16h9" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".8"/></svg>',
				'fields' => array(
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
						'group'   => 'design',
					),
					'cols'    => array(
						'type'  => 'number',
						'label' => __( 'Per row — desktop', 'oc-blocks' ),
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
						'group'   => 'design',
					),
					'cols'    => array(
						'type'  => 'number',
						'label' => __( 'Per row — desktop', 'oc-blocks' ),
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
				'label'  => __( 'Shop the look', 'oc-blocks' ),
				'blurb'  => __( 'A picture with hot spots — every spot a product.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><rect x="2.5" y="4" width="12" height="16" rx="1.5" opacity=".4"/><circle cx="7" cy="10" r="2.2"/><circle cx="11" cy="15" r="2.2"/><rect x="16.5" y="7" width="5" height="10" rx="1" opacity=".55"/></svg>',
				'fields' => array(
					'img'   => array(
						'type'  => 'image',
						'label' => __( 'The picture — desktop', 'oc-blocks' ),
					),
					'imgm'  => array(
						'type'  => 'image',
						'label' => __( 'The picture — mobile (optional)', 'oc-blocks' ),
					),
					'spots' => array(
						'type'  => 'spots',
						'label' => __( 'Hot spots', 'oc-blocks' ),
						'hint'  => __( 'Click the picture to drop a spot, then choose its product.', 'oc-blocks' ),
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
				'label'  => __( 'Media grid', 'oc-blocks' ),
				'blurb'  => __( 'Pictures and video in an arrangement with character.', 'oc-blocks' ),
				'icon'   => '<svg viewBox="0 0 24 24"><rect x="2.5" y="4" width="9" height="16" rx="1.5" opacity=".45"/><rect x="13.5" y="4" width="8" height="7" rx="1.5" opacity=".45"/><rect x="13.5" y="13" width="8" height="7" rx="1.5" opacity=".45"/></svg>',
				'fields' => array(
					'preset'  => array(
						'type'    => 'seg',
						'label'   => __( 'Arrangement', 'oc-blocks' ),
						'choices' => array(
							'tall'  => __( 'Tall beside two', 'oc-blocks' ),
							'duo'   => __( 'Two uprights', 'oc-blocks' ),
							'trio'  => __( 'Three columns', 'oc-blocks' ),
							'inset' => __( 'Wide with an inset', 'oc-blocks' ),
						),
						'def'     => 'tall',
					),
					'm1'      => array(
						'type'  => 'image',
						'label' => __( 'Picture 1', 'oc-blocks' ),
					),
					'v1'      => array(
						'type'  => 'video',
						'label' => __( 'Or video 1', 'oc-blocks' ),
					),
					'm2'      => array(
						'type'  => 'image',
						'label' => __( 'Picture 2', 'oc-blocks' ),
					),
					'v2'      => array(
						'type'  => 'video',
						'label' => __( 'Or video 2', 'oc-blocks' ),
					),
					'm3'      => array(
						'type'  => 'image',
						'label' => __( 'Picture 3', 'oc-blocks' ),
						'when'  => array( 'preset' => array( 'tall', 'trio' ) ),
					),
					'v3'      => array(
						'type'  => 'video',
						'label' => __( 'Or video 3', 'oc-blocks' ),
						'when'  => array( 'preset' => array( 'tall', 'trio' ) ),
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
					'corners' => array(
						'type'    => 'seg',
						'label'   => __( 'Corners', 'oc-blocks' ),
						'choices' => array(
							'sharp' => __( 'Sharp', 'oc-blocks' ),
							'soft'  => __( 'Softened', 'oc-blocks' ),
							'round' => __( 'Round', 'oc-blocks' ),
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
					'placement' => array(
						'type'  => 'text',
						'label' => __( 'Placement id (from OC Story)', 'oc-blocks' ),
						'hint'  => __( 'Empty shows the default gallery; a placement id from the OC Story screen shows that one.', 'oc-blocks' ),
					),
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
