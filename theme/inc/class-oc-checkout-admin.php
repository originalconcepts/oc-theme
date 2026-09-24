<?php
/**
 * Theme settings → Checkout: the rules of the checkout, as opposed to its
 * look, which stays in the Customizer. Which address fields are required,
 * how many digits a phone must have, and whether a shopper's own unpaid
 * order may stand in their way.
 *
 * Every control writes into the same `oc_checkout` option the Customizer
 * and the checkout read, so nothing moves and nothing needs migrating.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The screen.
 */
final class Checkout_Admin {

	const PAGE = 'oc-checkout';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_oc_checkout_save', array( $this, 'save' ) );
	}

	/**
	 * Under Theme settings.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			_x( 'Checkout', 'settings screen', 'oc-theme' ),
			_x( 'Checkout', 'settings screen', 'oc-theme' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'screen' )
		);
	}

	/**
	 * The settings screen.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['oc_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}

		$s       = Checkout::settings();
		$minutes = (int) get_option( 'woocommerce_hold_stock_minutes', 60 );
		$manage  = 'yes' === get_option( 'woocommerce_manage_stock', 'yes' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( _x( 'Checkout', 'settings screen', 'oc-theme' ) ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_checkout_save" />
				<?php wp_nonce_field( 'oc_checkout_save' ); ?>

				<p class="description" style="margin-block-start:0;">
					<?php
					printf(
						/* translators: %s: link to the Customizer section. */
						esc_html__( 'How the checkout looks — the summary column, the coupon field, the button — is set in %s.', 'oc-theme' ),
						'<a href="' . esc_url( admin_url( 'customize.php?autofocus[section]=oc_checkout' ) ) . '">' . esc_html__( 'Customize → Checkout page', 'oc-theme' ) . '</a>'
					);
					?>
				</p>

				<h2><?php esc_html_e( 'Address fields', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Required for delivery', 'oc-theme' ); ?></th>
						<td>
							<label style="display:block;margin-block-end:6px;"><input type="checkbox" name="apt_required" value="1" <?php checked( 1, (int) $s['apt_required'] ); ?> /> <?php esc_html_e( 'Apartment is required', 'oc-theme' ); ?></label>
							<label style="display:block;margin-block-end:6px;"><input type="checkbox" name="floor_required" value="1" <?php checked( 1, (int) $s['floor_required'] ); ?> /> <?php esc_html_e( 'Floor is required', 'oc-theme' ); ?></label>
							<label style="display:block;"><input type="checkbox" name="entry_required" value="1" <?php checked( 1, (int) $s['entry_required'] ); ?> /> <?php esc_html_e( 'Entry code is required', 'oc-theme' ); ?></label>
							<p class="description"><?php esc_html_e( 'The fields are always shown; this is only whether they may be left empty. Collecting from a branch never requires them.', 'oc-theme' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Phone validation', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Digits', 'oc-theme' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Digits from', 'oc-theme' ); ?> <input type="number" name="phone_min" value="<?php echo esc_attr( (string) $s['phone_min'] ); ?>" min="0" max="20" style="width:70px;" /></label>
							<label style="margin-inline-start:14px;"><?php esc_html_e( 'Digits to', 'oc-theme' ); ?> <input type="number" name="phone_max" value="<?php echo esc_attr( (string) $s['phone_max'] ); ?>" min="0" max="20" style="width:70px;" /></label>
							<p class="description"><?php esc_html_e( 'Counted after everything that is not a digit is dropped, so "052-123 4567" is ten digits. 0 on either side means no limit there. Applies to the shopper\'s phone and to a recipient\'s phones.', 'oc-theme' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Stock held by an unpaid order', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'The shopper\'s own hold', 'oc-theme' ); ?></th>
						<td>
							<label><input type="checkbox" name="hold_guard" value="1" <?php checked( 1, (int) $s['hold_guard'] ); ?> /> <?php esc_html_e( 'A shopper\'s own unpaid order never blocks them from ordering again', 'oc-theme' ); ?></label>
							<p class="description">
								<?php esc_html_e( 'When "place order" is pressed, WooCommerce creates an unpaid order and holds its stock for a while. A shopper who comes back from the payment page to add something to the cart is still holding that stock through their own order, and with one unit left WooCommerce refuses the new order: "not enough in stock". With this on, the earlier unpaid order of the same shopper is cancelled quietly (no e-mail) the moment the new one is created, and its hold is released. Works with every payment method, since it happens before payment.', 'oc-theme' ); ?>
							</p>
							<p class="description">
								<?php
								if ( $manage ) {
									printf(
										/* translators: 1: minutes, 2: link to WooCommerce inventory settings. */
										esc_html__( 'WooCommerce holds stock for %1$d minutes per unpaid order (%2$s).', 'oc-theme' ),
										(int) $minutes,
										'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' ) ) . '">' . esc_html__( 'WooCommerce → Settings → Products → Inventory', 'oc-theme' ) . '</a>'
									);
								} else {
									esc_html_e( 'Stock management is off in WooCommerce, so no stock is held and this setting has nothing to do.', 'oc-theme' );
								}
								?>
							</p>
						</td>
					</tr>
				</table>

				<p style="margin-block-start:18px;"><button class="button button-primary"><?php esc_html_e( 'Save settings', 'oc-theme' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist the screen: only the keys it shows, the rest of the option
	 * belongs to the Customizer and survives untouched.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}
		check_admin_referer( 'oc_checkout_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		update_option(
			'oc_checkout',
			array_merge(
				Checkout::settings(),
				array(
					'apt_required'   => empty( $_POST['apt_required'] ) ? 0 : 1,
					'floor_required' => empty( $_POST['floor_required'] ) ? 0 : 1,
					'entry_required' => empty( $_POST['entry_required'] ) ? 0 : 1,
					'phone_min'      => min( 20, max( 0, (int) ( $_POST['phone_min'] ?? 0 ) ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to int.
					'phone_max'      => min( 20, max( 0, (int) ( $_POST['phone_max'] ?? 0 ) ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to int.
					'hold_guard'     => empty( $_POST['hold_guard'] ) ? 0 : 1,
				)
			),
			false
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE,
					'oc_saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
