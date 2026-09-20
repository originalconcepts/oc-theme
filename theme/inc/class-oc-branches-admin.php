<?php
/**
 * Branches: the shop's own say in whether it has any.
 *
 * Branches are content — a post type with a page each, managed by the blocks
 * plugin. Most shops have none, and for them the screen is a door onto an
 * empty room that sits in the admin menu forever. This is the one switch that
 * closes it.
 *
 * Closing it hides the screen and nothing else. The post type stays
 * registered, the branch pages that exist keep answering, and the branches
 * block keeps its place in the composer — a shop may well want that block for
 * something other than branches of its own, "where to find our products"
 * being the obvious one.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The settings screen behind Theme settings → Branches.
 */
final class Branches_Admin {

	/**
	 * The screen's slug.
	 */
	const PAGE = 'oc-branches';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( '\OC\Blocks\Branches' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_oc_branches_save', array( $this, 'save' ) );
	}

	/**
	 * Under Theme settings, beside the rest.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			__( 'Branches', 'oc-theme' ),
			__( 'Branches', 'oc-theme' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'admin_screen' )
		);
	}

	/**
	 * The screen.
	 */
	public function admin_screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s     = \OC\Blocks\Branches::settings();
		$count = (int) wp_count_posts( \OC\Blocks\Branches::CPT )->publish;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Branches', 'oc-theme' ) . '</h1>';

		if ( isset( $_GET['oc_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="oc_branches_save" />
			<?php wp_nonce_field( 'oc_branches_save' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'This shop has branches', 'oc-theme' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="oc_branches_menu" value="1" <?php checked( 1, $s['menu'] ); ?> />
							<?php esc_html_e( 'Show the Branches screen in the menu', 'oc-theme' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Turn this off in a shop with no branches and the screen steps out of the menu. Nothing is deleted: the branch pages that exist keep working, and the branches block stays available — it is just as useful for "where to find our products".', 'oc-theme' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Collection at the checkout', 'oc-theme' ); ?></th>
					<td>
						<?php if ( $count > 0 ) : ?>
							<p>
								<?php
								printf(
									/* translators: %d: how many branches are offered for collection. */
									esc_html( _n( '%d branch is offered when a shopper chooses collection.', '%d branches are offered when a shopper chooses collection.', count( \OC\Blocks\Branches::for_pickup() ), 'oc-theme' ) ),
									count( \OC\Blocks\Branches::for_pickup() )
								);
								?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Every branch is offered unless its own screen says otherwise, where you can also give it the name shoppers see. The branch a shopper picks travels onto the order, shows on the order screen and in the emails, and the orders list can be narrowed to one branch.', 'oc-theme' ); ?>
							</p>
						<?php else : ?>
							<p class="description">
								<?php esc_html_e( 'No branches yet. Add one and it is offered at the checkout to anyone choosing collection.', 'oc-theme' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
		echo '</div>';
	}

	/**
	 * Keep it.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( 'oc_branches_save' );

		update_option(
			\OC\Blocks\Branches::OPTION,
			array( 'menu' => empty( $_POST['oc_branches_menu'] ) ? 0 : 1 )
		);

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
