<?php
/**
 * The rotating messages above the header.
 *
 * These are the shop's day-to-day marketing line — a sale, a shipping
 * promise, a holiday cut-off — so they belong where the shop works, not in
 * the Customizer beside the colours. The values stay exactly where the
 * theme has always read them from: the `oc_topbar_msg1..3` theme mods.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Announcement-bar messages, edited from the admin.
 */
final class Announce {

	/**
	 * How many message slots the bar rotates through.
	 */
	private const SLOTS = 3;

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 56 );
		add_action( 'admin_post_oc_announce_save', array( $this, 'save' ) );
	}

	/**
	 * Submenu under theme settings.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			__( 'Announcement bar', 'oc-theme' ),
			__( 'Announcement bar', 'oc-theme' ),
			'manage_woocommerce',
			'oc-announce',
			array( $this, 'screen' )
		);
	}

	/**
	 * The messages screen.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['oc_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}

		$on = (bool) get_theme_mod( 'oc_topbar', false );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Announcement bar', 'oc-theme' ); ?></h1>
			<p>
				<?php esc_html_e( 'The lines that rotate above the header. Leave a slot empty to skip it.', 'oc-theme' ); ?>
				<?php
				printf(
					' <a href="%1$s">%2$s</a>',
					esc_url( admin_url( 'customize.php?autofocus[section]=oc_topbar' ) ),
					esc_html__( 'Colours, rotation speed and whether the bar shows at all are in the Customizer.', 'oc-theme' )
				);
				?>
			</p>

			<?php if ( ! $on ) : ?>
				<div class="notice notice-warning inline" style="margin:14px 0;"><p>
					<?php esc_html_e( 'The bar is currently switched off, so these messages are not showing.', 'oc-theme' ); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_announce_save" />
				<?php wp_nonce_field( 'oc_announce_save' ); ?>
				<table class="form-table" role="presentation">
					<?php for ( $i = 1; $i <= self::SLOTS; $i++ ) : ?>
						<tr>
							<th scope="row">
								<?php
								/* translators: %d: message number. */
								echo esc_html( sprintf( __( 'Message %d', 'oc-theme' ), $i ) );
								?>
							</th>
							<td><input type="text" name="msg<?php echo esc_attr( (string) $i ); ?>" value="<?php echo esc_attr( (string) get_theme_mod( 'oc_topbar_msg' . $i, '' ) ); ?>" class="large-text" /></td>
						</tr>
					<?php endfor; ?>
				</table>
				<?php submit_button( __( 'Save settings', 'oc-theme' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist. The theme mods are the same ones the Customizer used to write,
	 * so the header keeps reading exactly what it always read.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_announce_save' );

		for ( $i = 1; $i <= self::SLOTS; $i++ ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			set_theme_mod( 'oc_topbar_msg' . $i, sanitize_text_field( wp_unslash( $_POST[ 'msg' . $i ] ?? '' ) ) );
		}

		wp_safe_redirect( add_query_arg( 'oc_saved', '1', admin_url( 'admin.php?page=oc-announce' ) ) );
		exit;
	}
}
