<?php
/**
 * Content blocks inside a product listing.
 *
 * A banner, and later a slider or a carousel, sitting between the products
 * of a category. Deliberately NOT a product type: a fake product enters the
 * catalogue query and quietly corrupts the result count, the pagination,
 * the filters, price sorting, search and the product feed. A block is its
 * own small post type, and where it appears is recorded separately — on the
 * category — so the product query is never touched.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The block post type, its placements, and the injection into the loop.
 */
final class Blocks {

	/**
	 * Post type name.
	 */
	const TYPE = 'oc_block';

	/**
	 * Position of the product currently being rendered, 1-based and absolute
	 * within the listing (page 2 of 24 starts at 25).
	 *
	 * @var int
	 */
	private $index = 0;

	/**
	 * Placements that apply to this request, keyed by index.
	 *
	 * @var array<int,array<int,array>>|null
	 */
	private $plan = null;

	/**
	 * Tile sizes a block can take.
	 *
	 * @return array<string,string>
	 */
	public static function sizes(): array {
		return array(
			''     => __( 'Normal — one cell', 'oc-theme' ),
			'wide' => __( 'Wide — 2 columns', 'oc-theme' ),
			'big'  => __( 'Large — 2×2', 'oc-theme' ),
			'row'  => __( 'Full row', 'oc-theme' ),
		);
	}

	/**
	 * Where a placement shows.
	 *
	 * @return array<string,string>
	 */
	public static function devices(): array {
		return array(
			'both'    => __( 'Desktop and mobile', 'oc-theme' ),
			'desktop' => __( 'Desktop only', 'oc-theme' ),
			'mobile'  => __( 'Mobile only', 'oc-theme' ),
		);
	}

	/**
	 * The global layout: the rhythm, and the placements used by any category
	 * that has not defined its own.
	 *
	 * @return array<string,mixed>
	 */
	public static function layout(): array {
		$saved = get_option( 'oc_catalog_layout' );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'wide_every' => 0,   // 0 = off. Every Nth product becomes wide.
				'big_every'  => 0,
				'places'     => array(),
			)
		);
	}

	/**
	 * The rhythm in force for the listing being rendered.
	 *
	 * A category may set its own pace, or leave a field empty to keep the
	 * shop-wide one. Empty means inherit; 0 means off.
	 *
	 * @return array{wide_every:int,big_every:int}
	 */
	public static function rhythm(): array {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$layout = self::layout();
		$out    = array(
			'wide_every' => (int) $layout['wide_every'],
			'big_every'  => (int) $layout['big_every'],
		);

		if ( is_tax( 'product_cat' ) ) {
			$term = get_queried_object();
			$own  = $term instanceof \WP_Term ? get_term_meta( $term->term_id, '_oc_rhythm', true ) : '';

			if ( is_array( $own ) ) {
				foreach ( array( 'wide_every', 'big_every' ) as $key ) {
					if ( isset( $own[ $key ] ) && '' !== $own[ $key ] ) {
						$out[ $key ] = (int) $own[ $key ];
					}
				}
			}
		}

		$cache = $out;

		return $cache;
	}

	/**
	 * Hook in.
	 */
	/**
	 * The registered instance, so the ajax renderer can drive the same
	 * counter rather than keeping a second one of its own.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * The instance, once registered.
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	public function register(): void {
		self::$instance = $this;

		add_action( 'init', array( $this, 'post_type' ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( $this, 'save_block' ) );

		// Injection: one counter, two render paths (normal and the filter ajax).
		add_action( 'woocommerce_before_shop_loop', array( $this, 'start' ), 1 );
		add_action( 'woocommerce_shop_loop', array( $this, 'before_product' ) );
		add_filter( 'woocommerce_product_loop_end', array( $this, 'tail' ) );
		add_filter( 'woocommerce_post_class', array( $this, 'rhythm_class' ), 11, 2 );

		add_action( 'product_cat_edit_form_fields', array( $this, 'term_fields' ), 20 );
		add_action( 'edited_product_cat', array( $this, 'save_term' ) );

		add_action( 'admin_menu', array( $this, 'menu' ), 57 );
		add_action( 'admin_post_oc_layout_save', array( $this, 'save_layout' ) );

		// The block screens live under the theme menu, on the layout tab.
		add_filter( 'parent_file', array( $this, 'menu_parent' ) );
		add_filter( 'submenu_file', array( $this, 'menu_child' ) );
		add_action( 'all_admin_notices', array( $this, 'block_screen_tabs' ) );
	}

	/* ----------------------------------------------------------- the type */

	/**
	 * A small post type, shown under the theme's own menu.
	 */
	public function post_type(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Catalogue blocks', 'oc-theme' ),
					'singular_name' => __( 'Block', 'oc-theme' ),
					'add_new_item'  => __( 'Add a block', 'oc-theme' ),
					'edit_item'     => __( 'Edit block', 'oc-theme' ),
					'search_items'  => __( 'Search blocks', 'oc-theme' ),
					'not_found'     => __( 'No blocks yet.', 'oc-theme' ),
				),
				'public'          => false,
				'show_ui'         => true,
				// Reached through the layout screen's own tab rather than a
				// second menu entry: a block and its placement are one job.
				'show_in_menu'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * A block's settings.
	 *
	 * @param int $id Block id.
	 * @return array<string,mixed>
	 */
	public static function block( int $id ): array {
		return array(
			'img'     => (int) get_post_meta( $id, '_oc_block_img', true ),
			'img_m'   => (int) get_post_meta( $id, '_oc_block_img_m', true ),
			'link'    => (string) get_post_meta( $id, '_oc_block_link', true ),
			'blank'   => (bool) get_post_meta( $id, '_oc_block_blank', true ),
			'alt'     => (string) get_post_meta( $id, '_oc_block_alt', true ),
			'heading' => (string) get_post_meta( $id, '_oc_block_heading', true ),
			'cta'     => (string) get_post_meta( $id, '_oc_block_cta', true ),
			'ink'     => 'dark' === get_post_meta( $id, '_oc_block_ink', true ) ? 'dark' : 'light',
			'from'    => (string) get_post_meta( $id, '_oc_block_from', true ),
			'to'      => (string) get_post_meta( $id, '_oc_block_to', true ),
			'focus'   => self::read_focus( $id ),
		);
	}

	/**
	 * How far down the picture the interesting part sits, as a percentage.
	 *
	 * A banner is cropped harder than anything else in the grid — a full row
	 * asks a picture to be four times as wide as it is tall — so the block
	 * says which band of it to keep.
	 *
	 * @param int $id Block id.
	 */
	private static function read_focus( int $id ): int {
		$focus = get_post_meta( $id, '_oc_block_focus', true );

		return '' === $focus ? 50 : max( 0, min( 100, (int) $focus ) );
	}

	/**
	 * The editing box.
	 */
	public function meta_box(): void {
		add_meta_box( 'oc_block_box', __( 'Block', 'oc-theme' ), array( $this, 'box' ), self::TYPE, 'normal', 'high' );
	}

	/**
	 * Its fields.
	 *
	 * @param \WP_Post $post Block.
	 */
	public function box( $post ): void {
		$b = self::block( (int) $post->ID );
		wp_enqueue_media();
		wp_nonce_field( 'oc_block_save', 'oc_block_nonce' );
		?>
		<style>
			.oc-bf { display:flex; gap:18px; flex-wrap:wrap; margin-block-end:16px; }
			.oc-bf label { display:block; font-weight:600; margin-block-end:4px; }
			.oc-bf > div { flex:1; min-inline-size:220px; }
			.oc-bimg { border:1px dashed #c3c4c7; border-radius:8px; padding:10px; text-align:center; }
			.oc-bimg img { max-inline-size:100%; block-size:auto; border-radius:6px; }
		</style>
		<div class="oc-bf">
			<?php foreach ( array( 'img' => __( 'Image — desktop', 'oc-theme' ), 'img_m' => __( 'Image — mobile', 'oc-theme' ) ) as $key => $label ) : ?>
				<div>
					<label><?php echo esc_html( $label ); ?></label>
					<div class="oc-bimg" data-oc-bimg="<?php echo esc_attr( $key ); ?>">
						<div class="oc-bimg__prev">
							<?php
							if ( $b[ $key ] ) {
								echo wp_get_attachment_image( $b[ $key ], 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
							}
							?>
						</div>
						<input type="hidden" name="oc_block_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) ( $b[ $key ] ?: '' ) ); ?>" />
						<p style="margin:8px 0 0;">
							<button type="button" class="button oc-bimg__pick"><?php esc_html_e( 'Choose image', 'oc-theme' ); ?></button>
							<button type="button" class="button-link-delete oc-bimg__clear" style="<?php echo $b[ $key ] ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button>
						</p>
						<?php if ( 'img_m' === $key ) : ?>
							<p class="description" style="margin:6px 0 0;"><?php esc_html_e( 'Empty = the desktop image.', 'oc-theme' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		$focus_src = $b['img'] ? (string) wp_get_attachment_image_url( $b['img'], 'large' ) : '';
		?>
		<div class="oc-bf">
			<div style="flex-basis:100%;">
				<label for="oc_block_focus"><?php esc_html_e( 'Picture position', 'oc-theme' ); ?></label>
				<p style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 6px;">
					<input type="range" id="oc_block_focus" name="oc_block_focus" min="0" max="100" step="1" value="<?php echo esc_attr( (string) $b['focus'] ); ?>" style="inline-size:260px;" />
					<output id="oc_block_focus_out" style="min-inline-size:44px;"><?php echo esc_html( (string) $b['focus'] ); ?>%</output>
					<button type="button" class="button-link" id="oc_block_focus_reset"><?php esc_html_e( 'Centre', 'oc-theme' ); ?></button>
				</p>
				<p class="description" style="margin:0 0 8px;"><?php esc_html_e( '0% keeps the top of the picture, 100% keeps the bottom. The previews show each banner size.', 'oc-theme' ); ?></p>

				<?php if ( $focus_src ) : ?>
					<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;">
						<?php
						foreach ( array(
							array( __( 'Normal — one cell', 'oc-theme' ), '140px', '140px' ),
							array( __( 'Wide — 2 columns', 'oc-theme' ), '280px', '140px' ),
							array( __( 'Full row', 'oc-theme' ), '400px', '100px' ),
						) as $box ) :
							?>
							<div>
								<div class="oc-bfocus" style="inline-size:<?php echo esc_attr( $box[1] ); ?>;block-size:<?php echo esc_attr( $box[2] ); ?>;border-radius:6px;overflow:hidden;background:#f1f1f1;">
									<img src="<?php echo esc_url( $focus_src ); ?>" alt="" style="inline-size:100%;block-size:100%;object-fit:cover;object-position:50% <?php echo esc_attr( (string) $b['focus'] ); ?>%;" />
								</div>
								<p class="description" style="margin:4px 0 0;text-align:center;"><?php echo esc_html( $box[0] ); ?></p>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="oc-bf">
			<div>
				<label for="oc_block_link"><?php esc_html_e( 'Link', 'oc-theme' ); ?></label>
				<input type="url" id="oc_block_link" name="oc_block_link" value="<?php echo esc_url( $b['link'] ); ?>" class="widefat" placeholder="https://" />
				<label style="font-weight:400;margin-block-start:6px;"><input type="checkbox" name="oc_block_blank" value="1" <?php checked( true, $b['blank'] ); ?> /> <?php esc_html_e( 'Open in a new tab', 'oc-theme' ); ?></label>
			</div>
			<div>
				<label for="oc_block_alt"><?php esc_html_e( 'Text alternative', 'oc-theme' ); ?></label>
				<input type="text" id="oc_block_alt" name="oc_block_alt" value="<?php echo esc_attr( $b['alt'] ); ?>" class="widefat" />
				<p class="description"><?php esc_html_e( 'What the image says, for screen readers.', 'oc-theme' ); ?></p>
			</div>
		</div>

		<div class="oc-bf">
			<div>
				<label for="oc_block_heading"><?php esc_html_e( 'Heading over the image', 'oc-theme' ); ?></label>
				<input type="text" id="oc_block_heading" name="oc_block_heading" value="<?php echo esc_attr( $b['heading'] ); ?>" class="widefat" />
			</div>
			<div>
				<label for="oc_block_cta"><?php esc_html_e( 'Button text', 'oc-theme' ); ?></label>
				<input type="text" id="oc_block_cta" name="oc_block_cta" value="<?php echo esc_attr( $b['cta'] ); ?>" class="widefat" />
			</div>
			<div>
				<label for="oc_block_ink"><?php esc_html_e( 'Text colour', 'oc-theme' ); ?></label>
				<select id="oc_block_ink" name="oc_block_ink" class="widefat">
					<option value="light" <?php selected( 'light', $b['ink'] ); ?>><?php esc_html_e( 'Light — for a dark image', 'oc-theme' ); ?></option>
					<option value="dark" <?php selected( 'dark', $b['ink'] ); ?>><?php esc_html_e( 'Dark — for a light image', 'oc-theme' ); ?></option>
				</select>
			</div>
		</div>

		<div class="oc-bf">
			<div>
				<label for="oc_block_from"><?php esc_html_e( 'Shown from', 'oc-theme' ); ?></label>
				<input type="date" id="oc_block_from" name="oc_block_from" value="<?php echo esc_attr( $b['from'] ); ?>" />
			</div>
			<div>
				<label for="oc_block_to"><?php esc_html_e( 'Shown until', 'oc-theme' ); ?></label>
				<input type="date" id="oc_block_to" name="oc_block_to" value="<?php echo esc_attr( $b['to'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Both empty = always shown.', 'oc-theme' ); ?></p>
			</div>
		</div>
		<script>
		( function () {
			var focus = document.getElementById( 'oc_block_focus' ),
				out = document.getElementById( 'oc_block_focus_out' ),
				reset = document.getElementById( 'oc_block_focus_reset' );

			if ( ! focus ) {
				return;
			}

			function paint() {
				out.textContent = focus.value + '%';

				document.querySelectorAll( '.oc-bfocus img' ).forEach( function ( img ) {
					img.style.objectPosition = '50% ' + focus.value + '%';
				} );
			}

			focus.addEventListener( 'input', paint );
			reset.addEventListener( 'click', function () {
				focus.value = 50;
				paint();
			} );
		}() );
		</script>
		<script>
		( function () {
			document.querySelectorAll( '[data-oc-bimg]' ).forEach( function ( box ) {
				var field = box.querySelector( 'input[type="hidden"]' ),
					prev = box.querySelector( '.oc-bimg__prev' ),
					clear = box.querySelector( '.oc-bimg__clear' ),
					frame;

				box.querySelector( '.oc-bimg__pick' ).addEventListener( 'click', function () {
					if ( ! frame ) {
						frame = wp.media( { library: { type: 'image' }, multiple: false } );
						frame.on( 'select', function () {
							var img = frame.state().get( 'selection' ).first().toJSON();
							field.value = img.id;
							prev.innerHTML = '<img src="' + ( img.sizes && img.sizes.medium ? img.sizes.medium.url : img.url ) + '" />';
							clear.style.display = '';
						} );
					}
					frame.open();
				} );

				clear.addEventListener( 'click', function () {
					field.value = '';
					prev.innerHTML = '';
					clear.style.display = 'none';
				} );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Save a block.
	 *
	 * @param int $id Block id.
	 */
	public function save_block( $id ): void {
		if ( ! isset( $_POST['oc_block_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['oc_block_nonce'] ) ), 'oc_block_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		update_post_meta( $id, '_oc_block_img', absint( $_POST['oc_block_img'] ?? 0 ) );
		update_post_meta( $id, '_oc_block_img_m', absint( $_POST['oc_block_img_m'] ?? 0 ) );
		update_post_meta( $id, '_oc_block_link', esc_url_raw( wp_unslash( $_POST['oc_block_link'] ?? '' ) ) );
		update_post_meta( $id, '_oc_block_blank', empty( $_POST['oc_block_blank'] ) ? '' : 1 );
		update_post_meta( $id, '_oc_block_alt', sanitize_text_field( wp_unslash( $_POST['oc_block_alt'] ?? '' ) ) );
		update_post_meta( $id, '_oc_block_heading', sanitize_text_field( wp_unslash( $_POST['oc_block_heading'] ?? '' ) ) );
		update_post_meta( $id, '_oc_block_cta', sanitize_text_field( wp_unslash( $_POST['oc_block_cta'] ?? '' ) ) );
		update_post_meta( $id, '_oc_block_ink', 'dark' === ( $_POST['oc_block_ink'] ?? '' ) ? 'dark' : 'light' );
		update_post_meta( $id, '_oc_block_from', sanitize_text_field( wp_unslash( $_POST['oc_block_from'] ?? '' ) ) );
		update_post_meta( $id, '_oc_block_to', sanitize_text_field( wp_unslash( $_POST['oc_block_to'] ?? '' ) ) );

		$focus = max( 0, min( 100, absint( $_POST['oc_block_focus'] ?? 50 ) ) );
		update_post_meta( $id, '_oc_block_focus', 50 === $focus ? '' : $focus );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/* ----------------------------------------------------------- the plan */

	/**
	 * Is this a plain listing — the order the shop intended?
	 *
	 * Placements are pinned to that order. Once the shopper filters or
	 * re-sorts, position 7 means something else entirely, so the blocks
	 * stand down and the listing is products only.
	 */
	private function plain(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading public listing params.
		if ( ! empty( $_GET['orderby'] ) || ! empty( $_GET['s'] ) || ! empty( $_GET['fb'] ) || ! empty( $_GET['fin'] ) ) {
			return false;
		}

		if ( isset( $_GET['fmin'] ) && '' !== $_GET['fmin'] ) {
			return false;
		}

		if ( isset( $_GET['fmax'] ) && '' !== $_GET['fmax'] ) {
			return false;
		}

		foreach ( array_keys( $_GET ) as $key ) {
			if ( preg_match( '/^f[ac]_\d+$/', (string) $key ) ) {
				return false;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return true;
	}

	/**
	 * The placements that apply here, grouped by the index they sit before.
	 *
	 * @return array<int,array<int,array>>
	 */
	private function plan(): array {
		if ( null !== $this->plan ) {
			return $this->plan;
		}

		$this->plan = array();

		if ( ! $this->plain() ) {
			return $this->plan;
		}

		$global = self::layout();
		$places = $global['places'];

		if ( is_tax( 'product_cat' ) ) {
			$term  = get_queried_object();
			$own   = $term instanceof \WP_Term ? get_term_meta( $term->term_id, '_oc_layout', true ) : '';
			$own   = is_array( $own ) ? $own : array();
			$places = $own ? $own : $places;
		}

		if ( ! $places ) {
			return $this->plan;
		}

		$ids = array();

		foreach ( $places as $place ) {
			$id = (int) ( $place['block'] ?? 0 );

			if ( ! $id || 'publish' !== get_post_status( $id ) ) {
				continue;
			}

			$index = max( 1, (int) ( $place['index'] ?? 1 ) );

			$this->plan[ $index ][] = array(
				'block'   => $id,
				'size'    => (string) ( $place['size'] ?? '' ),
				'devices' => (string) ( $place['devices'] ?? 'both' ),
			);

			$ids[] = $id;
		}

		// One query for the blocks, one for their images — never per block.
		if ( $ids ) {
			$ids = array_values( array_unique( $ids ) );
			_prime_post_caches( $ids, false, true );

			$images = array();

			foreach ( $ids as $id ) {
				foreach ( array( '_oc_block_img', '_oc_block_img_m' ) as $key ) {
					$image = (int) get_post_meta( $id, $key, true );

					if ( $image ) {
						$images[] = $image;
					}
				}
			}

			if ( $images ) {
				_prime_post_caches( array_unique( $images ), false, true );
			}
		}

		return $this->plan;
	}

	/* -------------------------------------------------------- the listing */

	/**
	 * Where this page starts in the listing.
	 */
	public function start(): void {
		$this->page_start( max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) ) );
	}

	/**
	 * Set the counter for a page. The filter ajax renders its own loop and
	 * knows its own page number, so it calls this directly.
	 *
	 * @param int $paged Page number, 1-based.
	 */
	public function page_start( int $paged ): void {
		$per_page = (int) get_theme_mod( 'oc_catalog_per_page', 24 );

		$this->index = ( max( 1, $paged ) - 1 ) * max( 1, $per_page );
	}

	/**
	 * Before each product: emit anything pinned to this position.
	 */
	public function before_product(): void {
		if ( ! Catalog::catalogue_loop() ) {
			return;
		}

		++$this->index;

		foreach ( $this->plan()[ $this->index ] ?? array() as $place ) {
			echo $this->block_html( $place ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}

	/**
	 * After the last product on the page, so a block can close a row.
	 *
	 * @param string $html Loop-closing markup.
	 */
	public function tail( $html ) {
		return $this->tail_html() . $html;
	}

	/**
	 * Blocks pinned just past the last product on this page.
	 */
	public function tail_html(): string {
		if ( ! Catalog::catalogue_loop() ) {
			return '';
		}

		$out = '';

		foreach ( $this->plan()[ $this->index + 1 ] ?? array() as $place ) {
			$out .= $this->block_html( $place );
		}

		return $out;
	}

	/**
	 * The rhythm: a product with no size of its own may still be enlarged,
	 * so a shop gets a varied catalogue without placing anything by hand.
	 *
	 * @param array       $classes Classes.
	 * @param \WC_Product $product Product.
	 */
	public function rhythm_class( $classes, $product ) {
		if ( ! Catalog::catalogue_loop() || ! $product instanceof \WC_Product || ! $this->plain() ) {
			return $classes;
		}

		// An explicit choice on the product always wins.
		foreach ( $classes as $class ) {
			if ( 0 === strpos( (string) $class, 'oc-tile--' ) ) {
				return $classes;
			}
		}

		$rhythm = self::rhythm();
		$big    = $rhythm['big_every'];
		$wide   = $rhythm['wide_every'];
		$at     = $this->index; // the counter already points at this product.

		if ( $big > 0 && 0 === $at % $big ) {
			$classes[] = 'oc-tile--big';
		} elseif ( $wide > 0 && 0 === $at % $wide ) {
			$classes[] = 'oc-tile--wide';
		}

		return $classes;
	}

	/**
	 * One block as a grid item.
	 *
	 * @param array<string,mixed> $place Placement.
	 */
	private function block_html( array $place ): string {
		$id = (int) $place['block'];
		$b  = self::block( $id );

		if ( ! $b['img'] ) {
			return '';
		}

		$today = current_time( 'Y-m-d' );

		if ( ( '' !== $b['from'] && $today < $b['from'] ) || ( '' !== $b['to'] && $today > $b['to'] ) ) {
			return '';
		}

		$size    = array_key_exists( $place['size'], self::sizes() ) ? $place['size'] : '';
		$devices = (string) $place['devices'];

		$class  = 'oc-block oc-block--banner';
		$class .= '' !== $size ? ' oc-block--' . $size : '';
		$class .= 'desktop' === $devices ? ' oc-block--desk' : '';
		$class .= 'mobile' === $devices ? ' oc-block--mob' : '';
		$class .= ' oc-block--' . $b['ink'];

		$alt   = '' !== $b['alt'] ? $b['alt'] : get_the_title( $id );
		$attrs = array(
			'alt'     => $alt,
			'loading' => 'lazy',
			'sizes'   => '(max-width: 900px) 100vw, 50vw',
		);

		$img = wp_get_attachment_image( $b['img'], 'large', false, $attrs + array( 'class' => 'oc-block__img oc-block__img--d' ) );

		if ( $b['img_m'] ) {
			$img .= wp_get_attachment_image( $b['img_m'], 'large', false, $attrs + array( 'class' => 'oc-block__img oc-block__img--m' ) );
		}

		$body = '';

		if ( '' !== $b['heading'] || '' !== $b['cta'] ) {
			$body .= '<span class="oc-block__body">';
			$body .= '' !== $b['heading'] ? '<span class="oc-block__h">' . esc_html( $b['heading'] ) . '</span>' : '';
			$body .= '' !== $b['cta'] ? '<span class="oc-block__cta">' . esc_html( $b['cta'] ) . '</span>' : '';
			$body .= '</span>';
		}

		$inner = '<span class="oc-block__media"'
			. ( 50 === $b['focus'] ? '' : ' style="--oc-block-focus:' . esc_attr( (string) $b['focus'] ) . '%"' )
			. '>' . $img . '</span>' . $body;

		if ( '' !== $b['link'] ) {
			$inner = '<a class="oc-block__link" href="' . esc_url( $b['link'] ) . '"'
				. ( $b['blank'] ? ' target="_blank" rel="noopener"' : '' ) . '>' . $inner . '</a>';
		}

		return '<li class="' . esc_attr( $class ) . '">' . $inner . '</li>';
	}

	/* -------------------------------------------------------------- admin */

	/**
	 * The placement table, used on a category and on the global screen.
	 *
	 * @param array  $places Placements.
	 * @param string $name   Field name prefix.
	 */
	private function table( array $places, string $name ): void {
		$blocks = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$places[] = array(
			'block'   => 0,
			'index'   => '',
			'size'    => '',
			'devices' => 'both',
		);
		?>
		<table class="widefat" id="oc-places" style="max-inline-size:760px;">
			<thead><tr>
				<th style="inline-size:34%;"><?php esc_html_e( 'Block', 'oc-theme' ); ?></th>
				<th style="inline-size:18%;"><?php esc_html_e( 'Position', 'oc-theme' ); ?></th>
				<th style="inline-size:24%;"><?php esc_html_e( 'Size', 'oc-theme' ); ?></th>
				<th style="inline-size:24%;"><?php esc_html_e( 'Shows on', 'oc-theme' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $places as $i => $place ) : ?>
				<tr>
					<td>
						<select name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( (string) $i ); ?>][block]" style="inline-size:100%;">
							<option value="0"><?php esc_html_e( '— None —', 'oc-theme' ); ?></option>
							<?php foreach ( $blocks as $block ) : ?>
								<option value="<?php echo esc_attr( (string) $block->ID ); ?>" <?php selected( (int) ( $place['block'] ?? 0 ), $block->ID ); ?>><?php echo esc_html( get_the_title( $block ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><input type="number" min="1" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( (string) $i ); ?>][index]" value="<?php echo esc_attr( (string) ( $place['index'] ?? '' ) ); ?>" style="inline-size:90px;" /></td>
					<td>
						<select name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( (string) $i ); ?>][size]" style="inline-size:100%;">
							<?php foreach ( self::sizes() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $place['size'] ?? '' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<select name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( (string) $i ); ?>][devices]" style="inline-size:100%;">
							<?php foreach ( self::devices() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $place['devices'] ?? 'both' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin:8px 0 4px;">
			<button type="button" class="button" id="oc-places-add"><?php esc_html_e( 'Add a placement', 'oc-theme' ); ?></button>
		</p>
		<p class="description" style="max-inline-size:760px;">
			<?php esc_html_e( 'Position 1 places the block before the first product. Set the block to "None" to remove a row.', 'oc-theme' ); ?>
		</p>
		<script>
		( function () {
			var table = document.getElementById( 'oc-places' ),
				add = document.getElementById( 'oc-places-add' );

			if ( ! table || ! add || add.dataset.ready ) {
				return;
			}

			add.dataset.ready = '1';

			add.addEventListener( 'click', function () {
				var body = table.tBodies[ 0 ],
					last = body.rows[ body.rows.length - 1 ],
					row = last.cloneNode( true ),
					next = body.rows.length;

				// Re-key the clone, so the browser posts it as its own row.
				row.querySelectorAll( '[name]' ).forEach( function ( field ) {
					field.name = field.name.replace( /\[(\d+)\]/, '[' + next + ']' );

					if ( 'SELECT' === field.tagName ) {
						field.selectedIndex = 0;
					} else {
						field.value = '';
					}
				} );

				body.appendChild( row );
				row.querySelector( 'select, input' ).focus();
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Read a submitted placement table.
	 *
	 * @param array $raw Raw rows.
	 * @return array<int,array>
	 */
	private function read_table( array $raw ): array {
		$out = array();

		foreach ( $raw as $row ) {
			$block = absint( $row['block'] ?? 0 );
			$index = absint( $row['index'] ?? 0 );

			if ( ! $block || ! $index ) {
				continue;
			}

			$size    = sanitize_key( $row['size'] ?? '' );
			$devices = sanitize_key( $row['devices'] ?? 'both' );

			$out[] = array(
				'block'   => $block,
				'index'   => $index,
				'size'    => array_key_exists( $size, self::sizes() ) ? $size : '',
				'devices' => array_key_exists( $devices, self::devices() ) ? $devices : 'both',
			);
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return $a['index'] <=> $b['index'];
			}
		);

		return $out;
	}

	/**
	 * The category's own layout.
	 *
	 * @param \WP_Term $term Category.
	 */
	public function term_fields( $term ): void {
		$own = get_term_meta( $term->term_id, '_oc_layout', true );
		$own = is_array( $own ) ? $own : array();

		$pace   = get_term_meta( $term->term_id, '_oc_rhythm', true );
		$pace   = is_array( $pace ) ? $pace : array();
		$global = self::layout();
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Rhythm', 'oc-theme' ); ?></label></th>
			<td>
				<?php
				foreach ( array(
					'wide_every' => __( 'Wide tile — every', 'oc-theme' ),
					'big_every'  => __( 'Large tile — every', 'oc-theme' ),
				) as $key => $label ) :
					$inherited = (int) $global[ $key ];
					?>
					<p style="margin:0 0 6px;">
						<label style="display:inline-block;min-inline-size:150px;"><?php echo esc_html( $label ); ?></label>
						<input
							type="number" min="0" max="99"
							name="oc_rhythm[<?php echo esc_attr( $key ); ?>]"
							value="<?php echo esc_attr( (string) ( $pace[ $key ] ?? '' ) ); ?>"
							placeholder="<?php echo esc_attr( $inherited > 0 ? (string) $inherited : '0' ); ?>"
							style="inline-size:70px;" />
						<?php esc_html_e( 'products', 'oc-theme' ); ?>
					</p>
				<?php endforeach; ?>
				<p class="description" style="max-inline-size:760px;">
					<?php
					printf(
						/* translators: 1: wide-tile pace, 2: large-tile pace. */
						esc_html__( 'Leave a field empty to keep the shop-wide pace (wide: %1$s, large: %2$s). 0 turns it off for this category alone.', 'oc-theme' ),
						esc_html( (int) $global['wide_every'] > 0 ? sprintf( /* translators: %d: a number of products. */ __( 'every %d', 'oc-theme' ), (int) $global['wide_every'] ) : __( 'off', 'oc-theme' ) ),
						esc_html( (int) $global['big_every'] > 0 ? sprintf( /* translators: %d: a number of products. */ __( 'every %d', 'oc-theme' ), (int) $global['big_every'] ) : __( 'off', 'oc-theme' ) )
					);
					?>
				</p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Catalogue blocks', 'oc-theme' ); ?></label></th>
			<td>
				<?php $this->table( $own, 'oc_places' ); ?>
				<p class="description" style="max-inline-size:760px;">
					<?php
					printf(
						/* translators: %s: link to the global layout screen. */
						esc_html__( 'Leave the table empty to use the shop-wide layout set in %s.', 'oc-theme' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=oc-layout' ) ) . '">' . esc_html__( 'Catalogue layout', 'oc-theme' ) . '</a>'
					);
					?>
				</p>
				<?php if ( ! $own && ! empty( $global['places'] ) ) : ?>
					<p class="description" style="max-inline-size:760px;">
						<strong><?php esc_html_e( 'Inherited now:', 'oc-theme' ); ?></strong>
						<?php
						$lines = array();

						foreach ( $global['places'] as $place ) {
							$id = (int) ( $place['block'] ?? 0 );

							if ( ! $id || 'publish' !== get_post_status( $id ) ) {
								continue;
							}

							$lines[] = sprintf(
								/* translators: 1: a position number, 2: a block title. */
								__( 'position %1$s — %2$s', 'oc-theme' ),
								(string) ( $place['index'] ?? '' ),
								get_the_title( $id )
							);
						}

						echo esc_html( $lines ? implode( ' · ', $lines ) : __( 'nothing', 'oc-theme' ) );
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the category's layout.
	 *
	 * @param int $term_id Category id.
	 */
	public function save_term( $term_id ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the term-edit nonce first.
		$raw = isset( $_POST['oc_places'] ) ? (array) wp_unslash( $_POST['oc_places'] ) : array();

		$places = $this->read_table( $raw );

		if ( $places ) {
			update_term_meta( $term_id, '_oc_layout', $places );
		} else {
			delete_term_meta( $term_id, '_oc_layout' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the term-edit nonce first.
		$pace_raw = isset( $_POST['oc_rhythm'] ) ? (array) wp_unslash( $_POST['oc_rhythm'] ) : array();
		$pace     = array();

		foreach ( array( 'wide_every', 'big_every' ) as $key ) {
			$value = isset( $pace_raw[ $key ] ) ? trim( (string) $pace_raw[ $key ] ) : '';

			if ( '' !== $value ) {
				$pace[ $key ] = (string) min( 99, absint( $value ) );
			}
		}

		if ( $pace ) {
			update_term_meta( $term_id, '_oc_rhythm', $pace );
		} else {
			delete_term_meta( $term_id, '_oc_rhythm' );
		}
	}

	/**
	 * The shop-wide layout screen.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			__( 'Catalogue layout', 'oc-theme' ),
			__( 'Catalogue layout', 'oc-theme' ),
			'manage_woocommerce',
			'oc-layout',
			array( $this, 'screen' )
		);
	}

	/**
	 * Keep the theme menu open while a block screen is showing.
	 *
	 * @param string $parent Parent file.
	 * @return string
	 */
	public function menu_parent( $parent ) {
		return $this->on_block_screen() ? Tabs::MENU : $parent;
	}

	/**
	 * And highlight the layout entry, which owns the tab.
	 *
	 * @param string|null $submenu Submenu file.
	 * @return string|null
	 */
	public function menu_child( $submenu ) {
		return $this->on_block_screen() ? 'oc-layout' : $submenu;
	}

	/**
	 * Is a block list or editor on screen?
	 */
	private function on_block_screen(): bool {
		global $typenow;

		return self::TYPE === $typenow;
	}

	/**
	 * The two tabs, drawn on both screens so they read as one.
	 *
	 * @param string $current Which tab is open: 'layout' or 'blocks'.
	 */
	private function tabs( string $current ): void {
		$tabs = array(
			'layout' => array( _x( 'Layout', 'catalogue layout tab', 'oc-theme' ), admin_url( 'admin.php?page=oc-layout' ) ),
			'blocks' => array( __( 'Blocks', 'oc-theme' ), admin_url( 'edit.php?post_type=' . self::TYPE ) ),
		);

		echo '<h2 class="nav-tab-wrapper" style="margin-block-end:16px;">';

		foreach ( $tabs as $key => $tab ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $tab[1] ),
				$key === $current ? ' nav-tab-active' : '',
				esc_html( $tab[0] )
			);
		}

		echo '</h2>';
	}

	/**
	 * The same tabs above the block list and editor.
	 */
	public function block_screen_tabs(): void {
		if ( ! $this->on_block_screen() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="wrap" style="margin-block-end:0;padding-block-start:8px;">';
		echo '<h1 style="margin-block-end:8px;">' . esc_html__( 'Catalogue layout', 'oc-theme' ) . '</h1>';
		$this->tabs( 'blocks' );
		echo '</div>';
	}

	/**
	 * Its form.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['oc_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}

		$layout = self::layout();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Catalogue layout', 'oc-theme' ); ?></h1>
			<?php $this->tabs( 'layout' ); ?>
			<p><?php esc_html_e( 'A listing where every card is the same size reads as a spreadsheet. These rules apply to the shop and to every category that has not set its own.', 'oc-theme' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_layout_save" />
				<?php wp_nonce_field( 'oc_layout_save' ); ?>

				<h2><?php esc_html_e( 'Rhythm', 'oc-theme' ); ?></h2>
				<p class="description" style="margin-block-start:0;"><?php esc_html_e( 'Enlarges products automatically, so a catalogue of any size looks varied without placing anything by hand. A size chosen on the product itself always wins. 0 = off.', 'oc-theme' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Wide tile', 'oc-theme' ); ?></th>
						<td>
							<?php esc_html_e( 'Every', 'oc-theme' ); ?>
							<input type="number" min="0" max="99" name="wide_every" value="<?php echo esc_attr( (string) $layout['wide_every'] ); ?>" style="inline-size:70px;" />
							<?php esc_html_e( 'products', 'oc-theme' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Large tile', 'oc-theme' ); ?></th>
						<td>
							<?php esc_html_e( 'Every', 'oc-theme' ); ?>
							<input type="number" min="0" max="99" name="big_every" value="<?php echo esc_attr( (string) $layout['big_every'] ); ?>" style="inline-size:70px;" />
							<?php esc_html_e( 'products', 'oc-theme' ); ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Blocks', 'oc-theme' ); ?></h2>
				<?php $this->table( $layout['places'], 'oc_places' ); ?>

				<?php submit_button( __( 'Save settings', 'oc-theme' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist the shop-wide layout.
	 */
	public function save_layout(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_layout_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$raw = isset( $_POST['oc_places'] ) ? (array) wp_unslash( $_POST['oc_places'] ) : array();

		update_option(
			'oc_catalog_layout',
			array(
				'wide_every' => min( 99, absint( $_POST['wide_every'] ?? 0 ) ),
				'big_every'  => min( 99, absint( $_POST['big_every'] ?? 0 ) ),
				'places'     => $this->read_table( $raw ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect( add_query_arg( 'oc_saved', '1', admin_url( 'admin.php?page=oc-layout' ) ) );
		exit;
	}
}
