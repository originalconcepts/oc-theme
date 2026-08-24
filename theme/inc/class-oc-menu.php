<?php
/**
 * The site menu.
 *
 * Structure — which links, in what order, which under which — stays on
 * WordPress's own Menus screen, because that is where a shop owner already
 * knows to look and because its drag-and-drop is better than anything worth
 * rebuilding. What this class adds is the two things that screen has no
 * opinion about: how the menu reads, and what opens underneath a link.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end presentation for the primary menu.
 */
final class Menu {

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'body_class', array( $this, 'body_class' ) );

		add_filter( 'nav_menu_css_class', array( $this, 'item_class' ), 10, 4 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'link_atts' ), 10, 2 );
		add_filter( 'nav_menu_item_title', array( $this, 'item_title' ), 10, 2 );
		add_filter( 'walker_nav_menu_start_el', array( $this, 'panel' ), 10, 4 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'drop_hidden' ), 10, 2 );

		// Deleting an item, or reordering the menu, can leave a panel cached
		// for something that is no longer there. And the row limit is baked
		// into the cached markup, so saving the Customizer must retire it too.
		add_action( 'wp_update_nav_menu', array( 'OC\\Theme\\Menu_Panel', 'flush' ) );
		add_action( 'customize_save_after', array( 'OC\\Theme\\Menu_Panel', 'flush' ) );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'item_fields' ) );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save_item' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
	}

	/**
	 * Mark the body when a panel is meant to dim the page, so the CSS can
	 * paint the overlay without the JS knowing anything about the setting.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function body_class( array $classes ): array {
		if ( get_theme_mod( 'oc_menu_dim', false ) ) {
			$classes[] = 'oc-menu-dim';
		}

		return $classes;
	}

	/**
	 * Hover treatments. The key becomes a class on the nav.
	 *
	 * @return array<string,string>
	 */
	public static function hovers(): array {
		return array(
			'fill'  => __( 'A line draws itself', 'oc-theme' ),
			'slide' => __( 'A line slides through', 'oc-theme' ),
			'lift'  => __( 'The word lifts', 'oc-theme' ),
			'plain' => __( 'Only the colour changes', 'oc-theme' ),
		);
	}

	/**
	 * Classes for the primary <nav>, carrying the settings the CSS needs to
	 * know about. A class rather than a custom property because these switch
	 * whole rules on and off, not single values.
	 *
	 * @return string
	 */
	public static function nav_class(): string {
		$hover  = (string) get_theme_mod( 'oc_menu_hover', 'fill' );
		$motion = (string) get_theme_mod( 'oc_menu_motion', 'stagger' );

		if ( ! array_key_exists( $hover, self::hovers() ) ) {
			$hover = 'fill';
		}
		if ( ! in_array( $motion, array( 'stagger', 'fade', 'none' ), true ) ) {
			$motion = 'stagger';
		}

		$width = (string) get_theme_mod( 'oc_mega_width', 'content' );

		if ( ! in_array( $width, array( 'full', 'content', 'menu' ), true ) ) {
			$width = 'content';
		}

		$classes = array(
			'oc-nav',
			'oc-nav--hv-' . $hover,
			'oc-nav--mo-' . $motion,
			'oc-nav--w-' . $width,
		);

		// Picture height zero means "as tall as the columns". The mode rides
		// the nav rather than the cached panel markup, like the width does.
		if ( 0 === absint( get_theme_mod( 'oc_mega_img_h', 360 ) ) ) {
			$classes[] = 'oc-nav--imgfit';
		}

		if ( get_theme_mod( 'oc_menu_dim', false ) ) {
			$classes[] = 'oc-nav--dim';
		}

		return implode( ' ', array_map( 'sanitize_html_class', $classes ) );
	}

	/**
	 * How deep a plain drop-down may go.
	 *
	 * @return int
	 */
	public static function depth(): int {
		return 3 === (int) get_theme_mod( 'oc_menu_depth', 2 ) ? 3 : 2;
	}

	/*
	 * Per-item settings.
	 *
	 * These live on the Menus screen rather than in the Customizer, because
	 * they belong to one link rather than to the menu: a red SALE and a badge
	 * that expires on Sunday are content, not design.
	 */

	/**
	 * The fields one menu item carries, and how to clean each one.
	 *
	 * @return array<string,string>
	 */
	private static function fields(): array {
		return array(
			'_oc_color'      => 'colour',
			'_oc_badge'      => 'text',
			'_oc_badge_bg'   => 'colour',
			'_oc_badge_tx'   => 'colour',
			'_oc_badge_from' => 'date',
			'_oc_badge_to'   => 'date',
			'_oc_img'        => 'id',
			'_oc_hide'       => 'hide',
		);
	}

	/**
	 * Fields under one item on the Menus screen.
	 *
	 * @param int $id Menu item id.
	 */
	public function item_fields( $id ): void {
		$id = (int) $id;

		$color = (string) get_post_meta( $id, '_oc_color', true );
		$badge = (string) get_post_meta( $id, '_oc_badge', true );
		$bg    = (string) get_post_meta( $id, '_oc_badge_bg', true );
		$tx    = (string) get_post_meta( $id, '_oc_badge_tx', true );
		$from  = (string) get_post_meta( $id, '_oc_badge_from', true );
		$to    = (string) get_post_meta( $id, '_oc_badge_to', true );
		$img   = (int) get_post_meta( $id, '_oc_img', true );
		$hide  = (string) get_post_meta( $id, '_oc_hide', true );

		$thumb = $img > 0 ? wp_get_attachment_image( $img, 'thumbnail' ) : '';
		?>
		<div class="oc-mi" data-oc-mi>
			<p class="oc-mi__row">
				<label>
					<?php esc_html_e( 'Link colour', 'oc-theme' ); ?><br>
					<input type="text" class="oc-mi__c" name="oc_mi[<?php echo esc_attr( (string) $id ); ?>][_oc_color]" value="<?php echo esc_attr( $color ); ?>" placeholder="<?php esc_attr_e( 'Inherited', 'oc-theme' ); ?>">
				</label>
				<label>
					<?php esc_html_e( 'Badge', 'oc-theme' ); ?><br>
					<input type="text" maxlength="14" name="oc_mi[<?php echo esc_attr( (string) $id ); ?>][_oc_badge]" value="<?php echo esc_attr( $badge ); ?>" placeholder="<?php esc_attr_e( 'New', 'oc-theme' ); ?>">
				</label>
			</p>

			<p class="oc-mi__row oc-mi__badge" <?php echo '' === $badge ? 'style="display:none"' : ''; ?>>
				<label>
					<?php esc_html_e( 'Badge background', 'oc-theme' ); ?><br>
					<input type="text" class="oc-mi__c" name="oc_mi[<?php echo esc_attr( (string) $id ); ?>][_oc_badge_bg]" value="<?php echo esc_attr( $bg ); ?>">
				</label>
				<label>
					<?php esc_html_e( 'Badge text', 'oc-theme' ); ?><br>
					<input type="text" class="oc-mi__c" name="oc_mi[<?php echo esc_attr( (string) $id ); ?>][_oc_badge_tx]" value="<?php echo esc_attr( $tx ); ?>">
				</label>
				<label>
					<?php esc_html_e( 'Show from', 'oc-theme' ); ?><br>
					<input type="date" name="oc_mi[<?php echo esc_attr( (string) $id ); ?>][_oc_badge_from]" value="<?php echo esc_attr( $from ); ?>">
				</label>
				<label>
					<?php esc_html_e( 'Show until', 'oc-theme' ); ?><br>
					<input type="date" name="oc_mi[<?php echo esc_attr( (string) $id ); ?>][_oc_badge_to]" value="<?php echo esc_attr( $to ); ?>">
				</label>
			</p>

			<p class="oc-mi__row">
				<span class="oc-mi__img" data-oc-miimg>
					<?php esc_html_e( 'Picture beside the link', 'oc-theme' ); ?><br>
					<input type="hidden" name="oc_mi[<?php echo esc_attr( (string) $id ); ?>][_oc_img]" value="<?php echo esc_attr( (string) $img ); ?>">
					<span class="oc-mi__prev"><?php echo wp_kses_post( $thumb ); ?></span>
					<button type="button" class="button oc-mi__pick"><?php esc_html_e( 'Choose', 'oc-theme' ); ?></button>
					<button type="button" class="button-link oc-mi__clear"<?php echo $img > 0 ? '' : ' style="display:none"'; ?>><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button>
					<span class="oc-mi__note"><?php esc_html_e( 'Shown in the drawer, not in the open desktop menu.', 'oc-theme' ); ?></span>
				</span>
				<label>
					<?php esc_html_e( 'Where this link appears', 'oc-theme' ); ?><br>
					<select name="oc_mi[<?php echo esc_attr( (string) $id ); ?>][_oc_hide]">
						<option value=""><?php esc_html_e( 'Everywhere', 'oc-theme' ); ?></option>
						<option value="mobile" <?php selected( $hide, 'mobile' ); ?>><?php esc_html_e( 'Desktop only', 'oc-theme' ); ?></option>
						<option value="desktop" <?php selected( $hide, 'desktop' ); ?>><?php esc_html_e( 'Drawer only', 'oc-theme' ); ?></option>
						<option value="all" <?php selected( $hide, 'all' ); ?>><?php esc_html_e( 'Nowhere — hidden', 'oc-theme' ); ?></option>
					</select>
				</label>
			</p>
		</div>
		<?php
	}

	/**
	 * Save one item's fields.
	 *
	 * WordPress calls this once per item, and the whole form posts at once,
	 * so the item's own id is the key to read.
	 *
	 * @param int $menu_id Menu id.
	 * @param int $item_id Menu item id.
	 */
	public function save_item( $menu_id, $item_id ): void {
		unset( $menu_id );
		$item_id = (int) $item_id;

		// Nonce and capability are checked by wp-admin/nav-menus.php before
		// wp_update_nav_menu_item() runs; this hook fires inside that.
		if ( ! isset( $_POST['oc_mi'][ $item_id ] ) || ! is_array( $_POST['oc_mi'][ $item_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$raw = wp_unslash( $_POST['oc_mi'][ $item_id ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		foreach ( self::fields() as $key => $kind ) {
			$value = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';

			switch ( $kind ) {
				case 'colour':
					$value = (string) sanitize_hex_color( $value );
					break;
				case 'id':
					$value = absint( $value ) > 0 ? (string) absint( $value ) : '';
					break;
				case 'date':
					$value = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
					break;
				case 'hide':
					$value = in_array( $value, array( 'desktop', 'mobile', 'all' ), true ) ? $value : '';
					break;
				default:
					$value = sanitize_text_field( $value );
			}

			if ( '' === $value ) {
				delete_post_meta( $item_id, $key );
				continue;
			}

			update_post_meta( $item_id, $key, $value );
		}
	}

	/**
	 * Colour picker, media frame and the badge fields' show/hide, on the
	 * Menus screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function admin_assets( string $hook ): void {
		if ( 'nav-menus.php' !== $hook ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		?>
		<style>
		.oc-mi { margin: 8px 0 4px; padding: 10px 12px; border: 1px solid #dcdcde; border-radius: 4px; background: #fff; }
		.oc-mi__row { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; margin: 0 0 10px; }
		.oc-mi__row:last-child { margin-block-end: 0; }
		.oc-mi__row label { font-weight: 600; }
		.oc-mi__row input[type="text"],
		.oc-mi__row input[type="date"],
		.oc-mi__row select { font-weight: 400; }
		.oc-mi__img { font-weight: 600; }
		.oc-mi__prev { display: inline-block; vertical-align: middle; margin-inline-end: 6px; }
		.oc-mi__prev img { inline-size: 40px; block-size: 40px; object-fit: cover; border-radius: 4px; display: block; }
		.oc-mi__note { display: block; font-weight: 400; color: #646970; font-size: 12px; margin-block-start: 4px; }
		</style>
		<?php
		add_action( 'admin_print_footer_scripts', array( $this, 'admin_script' ) );
	}

	/**
	 * Wiring for the per-item fields.
	 */
	public function admin_script(): void {
		?>
		<script>
		( function () {
			function ready( box ) {
				if ( box.dataset.ocReady ) { return; }
				box.dataset.ocReady = '1';

				if ( window.jQuery && jQuery.fn.wpColorPicker ) {
					jQuery( box ).find( '.oc-mi__c' ).wpColorPicker();
				}

				/* The badge's own settings are noise until there is a badge. */
				var text = box.querySelector( 'input[name*="[_oc_badge]"]' ),
					more = box.querySelector( '.oc-mi__badge' );

				if ( text && more ) {
					text.addEventListener( 'input', function () {
						more.style.display = text.value.trim() ? '' : 'none';
					} );
				}

				var pick = box.querySelector( '[data-oc-miimg]' );

				if ( pick ) {
					var field = pick.querySelector( 'input[type="hidden"]' ),
						prev = pick.querySelector( '.oc-mi__prev' ),
						clear = pick.querySelector( '.oc-mi__clear' ),
						frame;

					pick.querySelector( '.oc-mi__pick' ).addEventListener( 'click', function () {
						if ( ! frame ) {
							frame = wp.media( { library: { type: 'image' }, multiple: false } );
							frame.on( 'select', function () {
								var img = frame.state().get( 'selection' ).first().toJSON();
								field.value = img.id;
								prev.innerHTML = '<img src="' + ( img.sizes && img.sizes.thumbnail ? img.sizes.thumbnail.url : img.url ) + '" />';
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
				}
			}

			/* Items are opened one at a time and new ones arrive over ajax,
			 * so bind on demand rather than once at load. */
			function sweep() {
				document.querySelectorAll( '[data-oc-mi]' ).forEach( ready );
			}

			sweep();
			document.addEventListener( 'click', function () { setTimeout( sweep, 60 ); } );

			if ( window.jQuery ) {
				jQuery( document ).ajaxComplete( function () { setTimeout( sweep, 60 ); } );
			}
		}() );
		</script>
		<?php
	}

	/*
	 * Front end.
	 */

	/**
	 * Whether a badge is inside its date window. An empty end date means it
	 * never expires, which is the honest default — but the field is there so
	 * that "New" can stop being new without anyone remembering to remove it.
	 *
	 * @param int $id Menu item id.
	 * @return bool
	 */
	private static function badge_due( int $id ): bool {
		$today = current_time( 'Y-m-d' );
		$from  = (string) get_post_meta( $id, '_oc_badge_from', true );
		$to    = (string) get_post_meta( $id, '_oc_badge_to', true );

		if ( '' !== $from && $today < $from ) {
			return false;
		}

		if ( '' !== $to && $today > $to ) {
			return false;
		}

		return true;
	}

	/**
	 * Items that are not meant to be in this menu at all.
	 *
	 * @param array<int,\WP_Post> $items Menu items.
	 * @param object|null         $args  wp_nav_menu arguments.
	 * @return array<int,\WP_Post>
	 */
	public function drop_hidden( $items, $args = null ): array {
		if ( empty( $args->oc_panels ) ) {
			return (array) $items;
		}

		return array_values(
			array_filter(
				(array) $items,
				static function ( $item ): bool {
					return ! self::hidden( (int) $item->ID, 'nav' );
				}
			)
		);
	}

	/**
	 * Hide classes on the item, and the mark that says a panel opens here.
	 *
	 * @param array<int,string> $classes Item classes.
	 * @param \WP_Post          $item    Menu item.
	 * @param object|null       $args    wp_nav_menu arguments.
	 * @param int               $depth   Depth.
	 * @return array<int,string>
	 */
	public function item_class( $classes, $item, $args = null, $depth = 0 ): array {
		$classes = (array) $classes;
		$hide    = (string) get_post_meta( (int) $item->ID, '_oc_hide', true );

		// A panel replaces the plain drop-down rather than joining it, so the
		// stylesheet needs to know before it decides what to show.
		if ( 0 === (int) $depth && ! empty( $args->oc_panels ) && Menu_Panel::is_panel( (int) $item->ID ) ) {
			$classes[] = 'oc-has-panel';
		}

		if ( 'desktop' === $hide ) {
			$classes[] = 'oc-mi--no-desktop';
		} elseif ( 'mobile' === $hide ) {
			$classes[] = 'oc-mi--no-mobile';
		} elseif ( 'all' === $hide ) {
			$classes[] = 'oc-mi--off';
		}

		return $classes;
	}

	/**
	 * A link's own colour, as a custom property rather than a colour, so the
	 * stylesheet keeps deciding which places that colour applies to.
	 *
	 * @param array<string,string> $atts Link attributes.
	 * @param \WP_Post             $item Menu item.
	 * @return array<string,string>
	 */
	public function link_atts( $atts, $item ): array {
		$atts  = (array) $atts;
		$color = (string) get_post_meta( (int) $item->ID, '_oc_color', true );

		if ( '' !== $color ) {
			$atts['style'] = trim( ( $atts['style'] ?? '' ) . ';--oc-link-c:' . $color . ';', ';' );
		}

		return $atts;
	}

	/**
	 * The badge, and the picture the drawer shows beside a link.
	 *
	 * @param string   $title Item title.
	 * @param \WP_Post $item  Menu item.
	 * @return string
	 */
	public function item_title( $title, $item ): string {
		$id    = (int) $item->ID;
		$badge = trim( (string) get_post_meta( $id, '_oc_badge', true ) );
		$img   = (int) get_post_meta( $id, '_oc_img', true );
		$out   = '';

		// An item with neither keeps the markup WordPress would have produced
		// on its own. Every menu on the site passes through here, and a
		// wrapper span nobody asked for is a wrapper span that will one day
		// break somebody's stylesheet.
		if ( '' === $badge && $img < 1 ) {
			return (string) $title;
		}

		if ( $img > 0 ) {
			$out .= '<span class="oc-mi__pic" aria-hidden="true">' . wp_get_attachment_image( $img, 'thumbnail', false, array( 'loading' => 'lazy' ) ) . '</span>';
		}

		$out .= '<span class="oc-mi__t">' . $title . '</span>';

		if ( '' !== $badge && self::badge_due( $id ) ) {
			$style = '';
			$bg    = (string) get_post_meta( $id, '_oc_badge_bg', true );
			$tx    = (string) get_post_meta( $id, '_oc_badge_tx', true );

			if ( '' !== $bg ) {
				$style .= '--oc-badge-bg:' . $bg . ';';
			}
			if ( '' !== $tx ) {
				$style .= '--oc-badge-tx:' . $tx . ';';
			}

			$out .= '<span class="oc-mbadge"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>' . esc_html( $badge ) . '</span>';
		}

		return $out;
	}

	/**
	 * The panel, printed inside the item right after its link.
	 *
	 * It goes into the page rather than being fetched on hover: a panel is a
	 * few kilobytes, and a request on hover is a wait the visitor feels every
	 * single time. Its pictures are lazy, so a panel nobody opens costs the
	 * bytes of its markup and nothing more.
	 *
	 * @param string      $output Item markup so far.
	 * @param \WP_Post    $item   Menu item.
	 * @param int         $depth  Depth.
	 * @param object|null $args   wp_nav_menu arguments.
	 * @return string
	 */
	public function panel( $output, $item, $depth, $args = null ): string {
		if ( 0 !== (int) $depth || empty( $args->oc_panels ) ) {
			return (string) $output;
		}

		return (string) $output . Menu_Panel::html( (int) $item->ID );
	}

	/*
	 * The drawer.
	 *
	 * One drawer serves the phone and the desktop hamburger, because they are
	 * the same thing at different widths. It is built here rather than by
	 * wp_nav_menu(), because the sub-level has to hold two different kinds of
	 * content at once — the item's own children and the blocks of its panel —
	 * and a walker cannot see the second.
	 */

	/**
	 * Whether an item is hidden in a given place.
	 *
	 * Decided when the markup is written rather than by a media query: the
	 * desktop hamburger shows the drawer on a wide screen, so "hidden on
	 * desktop" and "hidden in the drawer" are not the same question and a
	 * breakpoint cannot tell them apart.
	 *
	 * @param int    $id    Menu item id.
	 * @param string $where Either 'nav' or 'drawer'.
	 * @return bool
	 */
	public static function hidden( int $id, string $where ): bool {
		$hide = (string) get_post_meta( $id, '_oc_hide', true );

		if ( 'all' === $hide ) {
			return true;
		}

		return 'drawer' === $where ? 'mobile' === $hide : 'desktop' === $hide;
	}

	/**
	 * Classes for the drawer, carrying its shape.
	 *
	 * @return string
	 */
	public static function drawer_class(): string {
		$side   = 'left' === get_theme_mod( 'oc_drw_side', 'right' ) ? 'left' : 'right';
		$sub    = 'slide' === get_theme_mod( 'oc_drw_sub', 'accordion' ) ? 'slide' : 'accordion';
		$motion = (string) get_theme_mod( 'oc_menu_motion', 'stagger' );

		if ( ! in_array( $motion, array( 'stagger', 'fade', 'none' ), true ) ) {
			$motion = 'stagger';
		}

		// The same setting governs both. A drop-down of two rows staggered
		// forty milliseconds apart is a difference nobody can see; the drawer
		// is where a menu has enough rows for the effect to read at all.
		$classes = array( 'oc-drw', 'oc-drw--' . $side, 'oc-drw--' . $sub, 'oc-drw--mo-' . $motion );

		if ( get_theme_mod( 'oc_drw_overlay', true ) ) {
			$classes[] = 'oc-drw--dim';
		}

		// Whether any link carries a picture decides the rhythm of every row:
		// rows without one take the same height, so the list reads as one
		// list rather than tall rows with short ones squeezed between.
		if ( self::drawer_has_pictures() ) {
			$classes[] = 'oc-drw--pics';
		}

		return implode( ' ', array_map( 'sanitize_html_class', $classes ) );
	}

	/**
	 * Whether any link the drawer will show carries a picture.
	 *
	 * @return bool
	 */
	private static function drawer_has_pictures(): bool {
		$locations = get_nav_menu_locations();
		$menu      = isset( $locations['primary'] ) ? (int) $locations['primary'] : 0;
		$items     = $menu > 0 ? wp_get_nav_menu_items( $menu ) : array();

		foreach ( (array) $items as $item ) {
			$id = (int) $item->ID;

			if ( ! self::hidden( $id, 'drawer' ) && (int) get_post_meta( $id, '_oc_img', true ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The drawer's contents.
	 *
	 * @return string
	 */
	public static function drawer_html(): string {
		$locations = get_nav_menu_locations();
		$menu      = isset( $locations['primary'] ) ? (int) $locations['primary'] : 0;
		$items     = $menu > 0 ? wp_get_nav_menu_items( $menu ) : array();

		if ( ! is_array( $items ) || empty( $items ) ) {
			return '';
		}

		$kids = array();

		foreach ( $items as $item ) {
			$kids[ (int) $item->menu_item_parent ][] = $item;
		}

		return '<ul class="oc-drw__list">' . self::drawer_level( $kids, 0, 1 ) . '</ul>';
	}

	/**
	 * One level of the drawer, and whatever hangs off it.
	 *
	 * @param array<int,array<int,\WP_Post>> $kids      Items by parent.
	 * @param int                            $parent_id Parent id.
	 * @param int                            $depth     Current depth, one-based.
	 * @return string
	 */
	private static function drawer_level( array $kids, int $parent_id, int $depth ): string {
		// Three levels, whatever the drop-down setting says. That setting is
		// about a drop-down hanging in mid-air, where a third level has
		// nowhere to go; a drawer is a stack of screens and the third level
		// is simply the next one.
		if ( empty( $kids[ $parent_id ] ) || $depth > 3 ) {
			return '';
		}

		$out = '';

		foreach ( $kids[ $parent_id ] as $item ) {
			$id = (int) $item->ID;

			if ( self::hidden( $id, 'drawer' ) ) {
				continue;
			}

			$panel = 1 === $depth ? Menu_Panel::html( $id, 'drawer' ) : '';
			$below = self::drawer_level( $kids, $id, $depth + 1 );
			$more  = '' !== $panel || '' !== $below;

			$title = (string) apply_filters( 'nav_menu_item_title', $item->title, $item, null, $depth - 1 );
			$style = (string) get_post_meta( $id, '_oc_color', true );

			$out .= '<li class="oc-drw__i' . ( $more ? ' has-more' : '' ) . '">';
			$out .= '<div class="oc-drw__row">';
			$out .= '<a class="oc-drw__a" href="' . esc_url( (string) $item->url ) . '"' . ( '' !== $style ? ' style="--oc-link-c:' . esc_attr( $style ) . '"' : '' ) . '>' . $title . '</a>';

			if ( $more ) {
				$out .= '<button type="button" class="oc-drw__more" aria-expanded="false" aria-label="' . esc_attr(
					sprintf(
						/* translators: %s: menu item name. */
						__( 'Open %s', 'oc-theme' ),
						wp_strip_all_tags( (string) $item->title )
					)
				) . '"><span aria-hidden="true"></span></button>';
			}

			$out .= '</div>';

			if ( $more ) {
				// One wrapper inside, always. Opening in place animates the
				// height with a 0fr-to-1fr grid track, and that only collapses
				// a single child — with two the second sits in an implicit
				// auto row and the section never closes.
				// The screen opens with the category as a heading — a link,
				// twice over: the name and a plain "show all" at the far end
				// both lead to everything in it, for whoever does not want to
				// pick a child first. The way back lives up in the drawer's
				// own top bar, beside the close.
				$out .= '<div class="oc-drw__sub">';
				$out .= '<div class="oc-drw__head">';
				$out .= '<a class="oc-drw__title" href="' . esc_url( (string) $item->url ) . '">' . esc_html( wp_strip_all_tags( (string) $item->title ) ) . '</a>';
				$out .= '<a class="oc-drw__all" href="' . esc_url( (string) $item->url ) . '">' . esc_html__( 'Show all', 'oc-theme' ) . '</a>';
				$out .= '</div>';
				$out .= '<div class="oc-drw__subin">';

				if ( '' !== $below ) {
					$out .= '<ul class="oc-drw__list oc-drw__list--sub">' . $below . '</ul>';
				}

				$out .= $panel;
				$out .= '</div></div>';
			}

			$out .= '</li>';
		}

		return $out;
	}
}
