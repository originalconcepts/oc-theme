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
	 * Classes the stylesheet reads.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function body_class( array $classes ): array {
		$classes[] = 'ocb-host-other';

		if ( self::full_template() ) {
			$classes[] = 'ocb-full';
		} elseif ( 'contained' === self::settings()['width'] ) {
			$classes[] = 'ocb-contained';
		}

		return $classes;
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
