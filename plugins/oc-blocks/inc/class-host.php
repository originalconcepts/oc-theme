<?php
/**
 * OC Blocks on a theme that is not OC's.
 *
 * Under the OC theme the composer leans on it: its design tokens colour
 * the sections, its page wrapper lets a section run edge to edge, its
 * cards carry the subgrid a product slider counts on. Any other theme has
 * none of that, so this class supplies what is missing — and stays silent
 * where the OC theme is active, which already does all of it.
 *
 *   - the page width: inside the theme's content column by default, or
 *     the "OC Blocks — full width" template (the theme's header and
 *     footer, no title, no sidebar), or the old edge-to-edge breakout;
 *   - the colours and corners, from Settings → OC Blocks;
 *   - body classes the stylesheet reads (ocb-host-other, ocb-contained,
 *     ocb-full).
 *
 * @package OC_Blocks
 */

declare( strict_types = 1 );

namespace OC\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * The host theme's stand-in.
 */
final class Host {

	/**
	 * Settings option.
	 */
	public const OPTION = 'oc_blocks_host';

	/**
	 * The plugin's own page template (a key, not a theme file).
	 */
	public const TEMPLATE = 'oc-blocks-full-width.php';

	/**
	 * Settings page slug.
	 */
	public const PAGE = 'oc-blocks-settings';

	/**
	 * The theme's content column, measured on the site, per page template:
	 * array( template => array( 'w' => column px, 'vw' => screen px ) ).
	 */
	public const COLS = 'oc_blocks_cols';

	/**
	 * Is the OC theme carrying the composer?
	 */
	public static function is_oc(): bool {
		return defined( 'OC_THEME_VERSION' );
	}

	/**
	 * What a shop gets before it has chosen anything.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		return array(
			'width'   => 'contained',
			'title'   => 'hide',
			'primary' => '',
			'ink'     => '',
			'surface' => '',
			'radius'  => '',
			'btn_r'   => '',
		);
	}

	/**
	 * The settings, cleaned.
	 *
	 * @return array<string,string>
	 */
	public static function settings(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::defaults();

		if ( isset( $raw['width'] ) && in_array( (string) $raw['width'], array( 'contained', 'full' ), true ) ) {
			$out['width'] = (string) $raw['width'];
		}

		if ( isset( $raw['title'] ) && in_array( (string) $raw['title'], array( 'hide', 'show' ), true ) ) {
			$out['title'] = (string) $raw['title'];
		}

		foreach ( array( 'primary', 'ink', 'surface' ) as $key ) {
			$out[ $key ] = (string) sanitize_hex_color( (string) ( $raw[ $key ] ?? '' ) );
		}

		foreach ( array( 'radius', 'btn_r' ) as $key ) {
			$v           = trim( (string) ( $raw[ $key ] ?? '' ) );
			$out[ $key ] = '' === $v ? '' : (string) min( 60, absint( $v ) );
		}

		return $out;
	}

	/**
	 * Hook in — only where the OC theme is not.
	 */
	public function register(): void {
		if ( self::is_oc() ) {
			return;
		}

		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'tokens' ), 20 );

		foreach ( Render::composable_types() as $type ) {
			add_filter( 'theme_' . $type . '_templates', array( $this, 'templates' ) );
		}

		add_filter( 'template_include', array( $this, 'template' ), 99 );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_oc_blocks_host_save', array( $this, 'save' ) );
		add_action( 'admin_post_oc_blocks_template', array( $this, 'switch_template' ) );
		add_action( 'wp_footer', array( $this, 'measure' ), 99 );
		add_action( 'wp_ajax_oc_blocks_col', array( $this, 'save_col' ) );

		if ( self::is_deliz() ) {
			add_filter( 'oc_blocks_product_cards', array( $this, 'deliz_cards' ) );
		}
	}

	/* ------------------------------------------------------- host themes */

	/**
	 * Is the host Deliz (OC's restaurant theme)?
	 */
	public static function is_deliz(): bool {
		return 0 === strpos( (string) get_template(), 'deliz' );
	}

	/**
	 * Deliz draws its product cards (white, a soft shadow, centred words)
	 * only inside its own products box. Its brands page reaches the same
	 * look with the box's classes and data-ed-no-menu-products, which
	 * tells its script this is not the box a category loads into — the
	 * same here, so a products section shows Deliz's cards exactly.
	 *
	 * @param string $cards The products loop.
	 */
	public function deliz_cards( $cards ): string {
		return '<div class="ed-mp-products-wrap ocb-hostcards"><div class="ed-mp__products" data-ed-no-menu-products="1">' . (string) $cards . '</div></div>';
	}

	/* -------------------------------------------------- the column width */

	/**
	 * The key a page's measurement is kept under: its template.
	 *
	 * @param int $page_id Page.
	 */
	private static function col_key( int $page_id ): string {
		$slug = (string) get_page_template_slug( $page_id );

		return sanitize_key( '' === $slug ? 'default' : $slug );
	}

	/**
	 * The column a page's sections stand in, as last measured on the site
	 * (empty until an editor has opened a page on that template).
	 *
	 * @param int $page_id Page.
	 * @return array<string,int>
	 */
	public static function col( int $page_id ): array {
		if ( self::is_oc() || $page_id < 1 ) {
			return array();
		}

		$all = get_option( self::COLS, array() );
		$one = is_array( $all ) ? ( $all[ self::col_key( $page_id ) ] ?? null ) : null;

		if ( ! is_array( $one ) || empty( $one['w'] ) ) {
			return array();
		}

		return array(
			'w'  => (int) $one['w'],
			'vw' => (int) ( $one['vw'] ?? 0 ),
		);
	}

	/**
	 * On a composed page an editor is looking at, at desktop width: measure
	 * the column the sections stand in, for the composer's preview. Only
	 * for editors — a visitor's browser never sends anything.
	 */
	public function measure(): void {
		$id = (int) get_queried_object_id();

		if ( ! is_page() || $id < 1 || ! Render::is_composed( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}

		$was = self::col( $id );
		$cfg = array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'oc_blocks_col' ),
			'page'  => $id,
			'w'     => (int) ( $was['w'] ?? 0 ),
			'vw'    => (int) ( $was['vw'] ?? 0 ),
		);
		?>
		<script>
		( function ( cfg ) {
			window.addEventListener( 'load', function () {
				var box = document.querySelector( '.oc-compose' );
				var vw = window.innerWidth;
				var w = box ? Math.round( box.getBoundingClientRect().width ) : 0;

				if ( vw < 1100 || w < 200 || ( Math.abs( w - cfg.w ) < 4 && vw === cfg.vw ) ) {
					return;
				}

				var body = new FormData();
				body.append( 'action', 'oc_blocks_col' );
				body.append( 'nonce', cfg.nonce );
				body.append( 'page', cfg.page );
				body.append( 'w', w );
				body.append( 'vw', vw );
				fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } ).catch( function () {} );
			} );
		}( <?php echo wp_json_encode( $cfg ); ?> ) );
		</script>
		<?php
	}

	/**
	 * Keep a measurement.
	 */
	public function save_col(): void {
		check_ajax_referer( 'oc_blocks_col', 'nonce' );

		$id = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;

		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array(), 403 );
		}

		$w  = isset( $_POST['w'] ) ? absint( $_POST['w'] ) : 0;
		$vw = isset( $_POST['vw'] ) ? absint( $_POST['vw'] ) : 0;

		if ( $w < 200 || $vw < 1100 || $w > $vw ) {
			wp_send_json_error( array(), 400 );
		}

		$all = get_option( self::COLS, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ self::col_key( $id ) ] = array(
			'w'  => min( 4000, $w ),
			'vw' => min( 4000, $vw ),
		);

		update_option( self::COLS, $all, false );
		wp_send_json_success();
	}

	/* ------------------------------------------------------------- front */

	/**
	 * Does the current page use the full-width template?
	 */
	private static function full_template(): bool {
		$id = (int) get_queried_object_id();

		return $id > 0 && is_singular() && self::TEMPLATE === get_page_template_slug( $id );
	}

	/**
	 * The classes the stylesheet reads — none under the OC theme. The
	 * composer's preview frame asks for them too: it is a document of its
	 * own, and without them a host theme's product grid rules would reach
	 * the sections there the way they never do on the page.
	 *
	 * @return array<int,string>
	 */
	public static function classes(): array {
		if ( self::is_oc() ) {
			return array();
		}

		$out = array( 'ocb-host-other' );

		if ( self::full_template() ) {
			$out[] = 'ocb-full';
		} elseif ( 'contained' === self::settings()['width'] ) {
			$out[] = 'ocb-contained';
		}

		// A page built with the composer opens with its own sections — the
		// theme's page title above them ("Home") is noise. Kept in the page
		// for screen readers and search engines, only not drawn.
		if ( 'hide' === self::settings()['title'] ) {
			$out[] = 'ocb-no-title';
		}

		return $out;
	}

	/**
	 * Classes the stylesheet reads.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function body_class( array $classes ): array {
		return array_merge( $classes, self::classes() );
	}

	/**
	 * Does the template this page is drawn with leave its content out?
	 *
	 * Some themes give the home page a template of their own that prints
	 * the theme's sliders and product boxes and never the page's content —
	 * there the sections are saved, shown in the preview, and missing from
	 * the page. Read from the template file itself: a file that neither
	 * prints the content nor hands off to another file (a template part,
	 * an include) cannot be showing it. Block themes are left alone; their
	 * templates are blocks, not files to read.
	 *
	 * @param int $page_id Page.
	 */
	public static function hides_content( int $page_id ): bool {
		if ( self::is_oc() || $page_id < 1 || wp_is_block_theme() ) {
			return false;
		}

		$slug = (string) get_page_template_slug( $page_id );

		if ( self::TEMPLATE === $slug ) {
			return false;
		}

		$file = '';

		if ( (int) get_option( 'page_on_front' ) === $page_id && 'page' === get_option( 'show_on_front' ) ) {
			$file = (string) locate_template( 'front-page.php' );
		}

		if ( '' === $file && '' !== $slug && 'default' !== $slug ) {
			$file = (string) locate_template( $slug );
		}

		if ( '' === $file ) {
			return ! self::shows_content( (string) locate_template( array( 'page.php', 'singular.php', 'index.php' ) ) );
		}

		return ! self::shows_content( $file );
	}

	/**
	 * Does the theme's plain page template print the content? (The way
	 * back offered when a page's own template does not.)
	 */
	public static function standard_shows_content(): bool {
		return self::shows_content( (string) locate_template( array( 'page.php', 'singular.php', 'index.php' ) ) );
	}

	/**
	 * Does a template file print the content, or might it (it hands off)?
	 *
	 * @param string $file Template file.
	 */
	private static function shows_content( string $file ): bool {
		if ( '' === $file || ! is_readable( $file ) ) {
			return true;
		}

		$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local theme file, read to look at, not to run.

		return (bool) preg_match( '/the_content|get_template_part|woocommerce_content|locate_template|load_template|\b(?:include|require)(?:_once)?\b/', $src );
	}

	/**
	 * The shop's colours and corners, as the tokens the sections read. A
	 * value left empty is not printed, so the stylesheet's fallback (or the
	 * theme's own variable, if it happens to define one) stands.
	 */
	public function tokens(): void {
		if ( ! wp_style_is( 'oc-blocks', 'enqueued' ) ) {
			return;
		}

		$s   = self::settings();
		$css = '';

		if ( '' !== $s['primary'] ) {
			$css .= '--oc-primary:' . $s['primary'] . ';--oc-cta:' . $s['primary'] . ';';
		}

		if ( '' !== $s['ink'] ) {
			$css .= '--oc-ink:' . $s['ink'] . ';';
		}

		if ( '' !== $s['surface'] ) {
			$css .= '--oc-surface:' . $s['surface'] . ';';
		}

		if ( '' !== $s['radius'] ) {
			$css .= '--oc-radius:' . $s['radius'] . 'px;';
		}

		if ( '' !== $s['btn_r'] ) {
			$css .= '--oc-cta-r:' . $s['btn_r'] . 'px;';
		}

		if ( '' !== $css ) {
			wp_add_inline_style( 'oc-blocks', ':root{' . $css . '}' );
		}
	}

	/**
	 * The template in the page's Template menu.
	 *
	 * @param array<string,string> $templates Templates.
	 * @return array<string,string>
	 */
	public function templates( $templates ): array {
		$templates                   = (array) $templates;
		$templates[ self::TEMPLATE ] = __( 'OC Blocks — full width', 'oc-blocks' );

		return $templates;
	}

	/**
	 * The plugin's template for a page that chose it.
	 *
	 * @param string $template Template file.
	 */
	public function template( $template ): string {
		if ( self::full_template() ) {
			return OC_BLOCKS_DIR . 'templates/full-width.php';
		}

		return (string) $template;
	}

	/* ------------------------------------------------------------- admin */

	/**
	 * Settings → OC Blocks.
	 */
	public function menu(): void {
		add_options_page(
			__( 'OC Blocks', 'oc-blocks' ),
			__( 'OC Blocks', 'oc-blocks' ),
			'manage_options',
			self::PAGE,
			array( $this, 'screen' )
		);
	}

	/**
	 * Save.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-blocks' ) );
		}

		check_admin_referer( 'oc_blocks_host_save' );

		$in = isset( $_POST['ocb'] ) && is_array( $_POST['ocb'] ) ? map_deep( wp_unslash( $_POST['ocb'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by map_deep.

		update_option( self::OPTION, $in, false );
		update_option( self::OPTION, self::settings(), false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE,
					'ocb_saved' => 1,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * The composer's "this template leaves the content out" notice, one
	 * click from a template that shows it.
	 */
	public function switch_template(): void {
		$page_id = isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0;
		$to      = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( $_GET['to'] ) ) : '';

		if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-blocks' ) );
		}

		check_admin_referer( 'oc_blocks_template_' . $page_id );

		update_post_meta( $page_id, '_wp_page_template', 'full' === $to ? self::TEMPLATE : 'default' );

		wp_safe_redirect( Editor::url( $page_id ) );
		exit;
	}

	/**
	 * The page's layout for the composer: which one it has, the way to the
	 * others, and — when its template leaves the content out — the warning.
	 * Empty under the OC theme, which has one layout of its own.
	 *
	 * @param int $page_id Page.
	 * @return array<string,string>
	 */
	public static function notice( int $page_id ): array {
		if ( self::is_oc() || $page_id < 1 ) {
			return array();
		}

		// A raw URL, not wp_nonce_url(): that one is escaped for HTML
		// (&amp;), and handed to the script as an href it reached the server
		// as "amp;page_id" — no page, so "Not allowed".
		$go = static function ( string $to ) use ( $page_id ): string {
			return add_query_arg(
				array(
					'action'   => 'oc_blocks_template',
					'page_id'  => $page_id,
					'to'       => $to,
					'_wpnonce' => wp_create_nonce( 'oc_blocks_template_' . $page_id ),
				),
				admin_url( 'admin-post.php' )
			);
		};

		$slug = (string) get_page_template_slug( $page_id );
		$now  = 'other';

		if ( self::TEMPLATE === $slug ) {
			$now = 'full';
		} elseif ( '' === $slug || 'default' === $slug ) {
			$now = 'std';
		}

		$other = '';

		if ( 'other' === $now ) {
			$names = wp_get_theme()->get_page_templates( get_post( $page_id ) );
			$other = (string) ( $names[ $slug ] ?? $slug );
		}

		return array(
			'text'   => self::hides_content( $page_id ) ? __( 'The template this page uses does not show the page content — the sections below will not appear on the site. Choose a template that shows them:', 'oc-blocks' ) : '',
			'label'  => __( 'Page layout', 'oc-blocks' ),
			'now'    => $now,
			'std'    => self::standard_shows_content() ? $go( 'default' ) : '',
			'stdT'   => __( 'The theme\'s standard page (with its menus and cart)', 'oc-blocks' ),
			'full'   => $go( 'full' ),
			'fullT'  => __( 'OC Blocks — full width', 'oc-blocks' ),
			'otherT' => $other,
		);
	}

	/**
	 * The screen.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = self::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OC Blocks', 'oc-blocks' ); ?></h1>
			<?php if ( isset( $_GET['ocb_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display flag. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'oc-blocks' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_blocks_host_save">
				<?php wp_nonce_field( 'oc_blocks_host_save' ); ?>

				<h2><?php esc_html_e( 'Page width', 'oc-blocks' ); ?></h2>
				<p>
					<label><input type="radio" name="ocb[width]" value="contained" <?php checked( 'contained', $s['width'] ); ?>> <?php esc_html_e( 'Inside the theme\'s content area (safe with any theme, sidebars included)', 'oc-blocks' ); ?></label><br>
					<label><input type="radio" name="ocb[width]" value="full" <?php checked( 'full', $s['width'] ); ?>> <?php esc_html_e( 'Edge to edge of the screen (for themes with one centred content column)', 'oc-blocks' ); ?></label>
				</p>
				<p class="description"><?php esc_html_e( 'For a page that should run edge to edge with no title, choose the "OC Blocks — full width" template on that page: the theme\'s header and footer stay, the page title and sidebars go.', 'oc-blocks' ); ?></p>

				<h2><?php esc_html_e( 'Page title', 'oc-blocks' ); ?></h2>
				<p>
					<label><input type="radio" name="ocb[title]" value="hide" <?php checked( 'hide', $s['title'] ); ?>> <?php esc_html_e( 'Hide the theme\'s page title on pages built with OC Blocks', 'oc-blocks' ); ?></label><br>
					<label><input type="radio" name="ocb[title]" value="show" <?php checked( 'show', $s['title'] ); ?>> <?php esc_html_e( 'Show it', 'oc-blocks' ); ?></label>
				</p>

				<h2><?php esc_html_e( 'Colours and corners', 'oc-blocks' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Left empty, each one keeps the sections\' neutral default.', 'oc-blocks' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$rows = array(
						'primary' => __( 'Brand colour (buttons, accents)', 'oc-blocks' ),
						'ink'     => __( 'Text colour', 'oc-blocks' ),
						'surface' => __( 'Section background', 'oc-blocks' ),
					);

					foreach ( $rows as $key => $label ) :
						?>
						<tr>
							<th scope="row"><label for="ocb-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input type="text" id="ocb-<?php echo esc_attr( $key ); ?>" name="ocb[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $s[ $key ] ); ?>" placeholder="#000000" pattern="#[0-9a-fA-F]{3,6}" class="regular-text" style="inline-size:9em"></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="ocb-radius"><?php esc_html_e( 'Corner of pictures and boxes (px)', 'oc-blocks' ); ?></label></th>
						<td><input type="number" min="0" max="60" id="ocb-radius" name="ocb[radius]" value="<?php echo esc_attr( $s['radius'] ); ?>" style="inline-size:6em"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ocb-btn"><?php esc_html_e( 'Corner of buttons (px)', 'oc-blocks' ); ?></label></th>
						<td><input type="number" min="0" max="60" id="ocb-btn" name="ocb[btn_r]" value="<?php echo esc_attr( $s['btn_r'] ); ?>" style="inline-size:6em"></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
