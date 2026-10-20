<?php
/**
 * Theme settings → Wishlist: the switch, where the hearts go, and what the
 * shop has learned from them.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Wishlist;

use OC\Theme\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * The screen.
 */
final class Admin {

	const PAGE = 'oc-wishlist';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ), 61 );
		add_action( 'admin_post_oc_wishlist_save', array( $this, 'save' ) );
	}

	/**
	 * Under Theme settings.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			__( 'Wishlist', 'oc-theme' ),
			__( 'Wishlist', 'oc-theme' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'screen' )
		);
	}

	/**
	 * Save.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( 'oc_wishlist_save' );

		$in  = isset( $_POST['wl'] ) && is_array( $_POST['wl'] ) ? map_deep( wp_unslash( $_POST['wl'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by map_deep.
		$set = Wishlist::settings();

		foreach ( array( 'on', 'header_desktop', 'header_mobile', 'header_count' ) as $key ) {
			$set[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
		}

		foreach ( array( 'card_desktop', 'card_mobile', 'card_side', 'product_place', 'page_layout', 'tools_style' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$set[ $key ] = (string) $in[ $key ];
			}
		}

		update_option( Wishlist::OPTION, $set, false );

		// Cleaned on the way back in, and the page made now that someone
		// who may make it has switched the feature on.
		update_option( Wishlist::OPTION, Wishlist::settings(), false );

		if ( ! empty( $set['on'] ) ) {
			Wishlist::install();
			Wishlist::page_id();
		}

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

	/**
	 * The screen.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$tab = isset( $_GET['tab'] ) && 'data' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'data' : 'settings';
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<div class="wrap ocwl-admin">
			<h1><?php esc_html_e( 'Wishlist', 'oc-theme' ); ?></h1>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice flag. ?>
			<?php if ( isset( $_GET['oc_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'oc-theme' ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab<?php echo 'settings' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'oc-theme' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'data', $url ) ); ?>" class="nav-tab<?php echo 'data' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Data', 'oc-theme' ); ?></a>
			</nav>
			<?php
			self::styles();

			if ( 'data' === $tab ) {
				self::data();
			} else {
				self::form();
			}
			?>
		</div>
		<?php
	}

	/**
	 * The settings form.
	 */
	private static function form(): void {
		$s = Wishlist::settings();

		$radio = static function ( string $key, array $choices ) use ( $s ): void {
			foreach ( $choices as $value => $label ) {
				printf(
					'<label class="ocwl-opt"><input type="radio" name="wl[%1$s]" value="%2$s"%3$s> %4$s</label>',
					esc_attr( $key ),
					esc_attr( $value ),
					checked( (string) $s[ $key ], (string) $value, false ),
					esc_html( $label )
				);
			}
		};

		$check = static function ( string $key, string $label ) use ( $s ): void {
			printf(
				'<label class="ocwl-opt"><input type="checkbox" name="wl[%1$s]" value="1"%2$s> %3$s</label>',
				esc_attr( $key ),
				checked( ! empty( $s[ $key ] ), true, false ),
				esc_html( $label )
			);
		};
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="oc_wishlist_save">
			<?php wp_nonce_field( 'oc_wishlist_save' ); ?>

			<div class="ocwl-card">
				<h2><?php esc_html_e( 'Wishlist', 'oc-theme' ); ?></h2>
				<?php $check( 'on', __( 'Customers can save products to lists', 'oc-theme' ) ); ?>
				<p class="description"><?php esc_html_e( 'A guest saves to one list on their device and is invited to sign in to keep it and to create more lists; a signed-in customer keeps every list on every device. Removing a product from the cart also offers to move it to the favourites.', 'oc-theme' ); ?></p>
				<?php if ( ! empty( $s['on'] ) && $s['page'] ) : ?>
					<p><?php esc_html_e( 'The lists page:', 'oc-theme' ); ?> <a href="<?php echo esc_url( (string) get_permalink( (int) $s['page'] ) ); ?>" target="_blank"><?php echo esc_html( (string) get_permalink( (int) $s['page'] ) ); ?></a></p>
				<?php endif; ?>
			</div>

			<div class="ocwl-card">
				<h2><?php esc_html_e( 'Header', 'oc-theme' ); ?></h2>
				<?php
				$check( 'header_desktop', __( 'Show the heart on desktop', 'oc-theme' ) );
				$check( 'header_mobile', __( 'Show the heart on mobile', 'oc-theme' ) );
				$check( 'header_count', __( 'Show how many products are saved', 'oc-theme' ) );
				?>
			</div>

			<div class="ocwl-card">
				<h2><?php esc_html_e( 'On the catalogue picture', 'oc-theme' ); ?></h2>
				<p class="ocwl-h"><?php esc_html_e( 'Desktop', 'oc-theme' ); ?></p>
				<?php
				$radio(
					'card_desktop',
					array(
						'none'   => __( 'Not shown', 'oc-theme' ),
						'hover'  => __( 'On hover', 'oc-theme' ),
						'always' => __( 'Always', 'oc-theme' ),
					)
				);
				?>
				<p class="ocwl-h"><?php esc_html_e( 'Mobile', 'oc-theme' ); ?></p>
				<?php
				$radio(
					'card_mobile',
					array(
						'none'   => __( 'Not shown', 'oc-theme' ),
						'always' => __( 'Always', 'oc-theme' ),
					)
				);
				?>
				<p class="ocwl-h"><?php esc_html_e( 'Corner', 'oc-theme' ); ?></p>
				<?php
				$radio(
					'card_side',
					array(
						'right' => __( 'Top right', 'oc-theme' ),
						'left'  => __( 'Top left', 'oc-theme' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'A label already in that corner moves down to make room.', 'oc-theme' ); ?></p>
			</div>

			<div class="ocwl-card">
				<h2><?php esc_html_e( 'On the product page', 'oc-theme' ); ?></h2>
				<?php
				$radio(
					'product_place',
					array(
						'button'      => __( 'Beside the cart button', 'oc-theme' ),
						'image-right' => __( 'On the picture, top right', 'oc-theme' ),
						'image-left'  => __( 'On the picture, top left', 'oc-theme' ),
						'none'        => __( 'Not shown', 'oc-theme' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Beside the button it takes the same corners as the shop\'s buttons.', 'oc-theme' ); ?></p>
			</div>

			<div class="ocwl-card">
				<h2><?php esc_html_e( 'The lists page', 'oc-theme' ); ?></h2>
				<p class="ocwl-h"><?php esc_html_e( 'Where the lists sit on desktop', 'oc-theme' ); ?></p>
				<?php
				$radio(
					'page_layout',
					array(
						'side' => __( 'In a column beside the products', 'oc-theme' ),
						'top'  => __( 'In a row above the products, the last one "+ New list"', 'oc-theme' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'On a phone the lists always sit in a row above the products.', 'oc-theme' ); ?></p>
				<p class="ocwl-h"><?php esc_html_e( 'The list buttons: share, rename, delete', 'oc-theme' ); ?></p>
				<?php
				$radio(
					'tools_style',
					array(
						'text' => __( 'Text only', 'oc-theme' ),
						'icon' => __( 'Icon only', 'oc-theme' ),
						'both' => __( 'Icon and text', 'oc-theme' ),
					)
				);
				?>
			</div>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------- data */

	/**
	 * What the lists say.
	 */
	private static function data(): void {
		if ( ! Wishlist::ready() ) {
			echo '<div class="ocwl-card"><p>' . esc_html__( 'Nothing to show yet: switch the wishlist on in Settings.', 'oc-theme' ) . '</p></div>';
			return;
		}

		global $wpdb;

		$items   = Wishlist::table( 'items' );
		$lists   = Wishlist::table( 'lists' );
		$notices = Wishlist::table( 'notices' );
		$since   = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the feature's own tables, names built by the class.
		$now = $wpdb->get_row( "SELECT COUNT(*) saved, COUNT(DISTINCT product_id) products, COUNT(DISTINCT owner) owners, COUNT(DISTINCT CASE WHEN owner LIKE 'u%' THEN owner END) customers FROM {$items} WHERE removed IS NULL" );
		$new = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items} WHERE added > %s", $since ) );
		$buy = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) adds, SUM(bought IS NOT NULL) bought FROM {$items} WHERE added > %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		$own = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lists} WHERE deleted IS NULL AND is_main = 0" );
		$src = $wpdb->get_results( $wpdb->prepare( "SELECT source, COUNT(*) n FROM {$items} WHERE added > %s GROUP BY source ORDER BY n DESC", $since ) );
		$top = $wpdb->get_results( "SELECT product_id, SUM(removed IS NULL) now_saved, COUNT(*) adds, SUM(bought IS NOT NULL) bought FROM {$items} GROUP BY product_id ORDER BY now_saved DESC, adds DESC LIMIT 25" );
		$ids = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$items} WHERE removed IS NULL LIMIT 1000" );
		$ntc = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) sent, SUM(bought IS NOT NULL) bought FROM {$notices} WHERE sent > %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		$ntp = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, COUNT(*) sent, SUM(bought IS NOT NULL) bought FROM {$notices} WHERE sent > %s GROUP BY product_id ORDER BY sent DESC LIMIT 15", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		// phpcs:enable

		$adds90   = (int) ( $buy->adds ?? 0 );
		$bought90 = (int) ( $buy->bought ?? 0 );
		$sent     = (int) ( $ntc->sent ?? 0 );
		$sold     = (int) ( $ntc->bought ?? 0 );

		$tiles = array(
			array( number_format_i18n( (int) ( $now->saved ?? 0 ) ), __( 'Products saved now', 'oc-theme' ), sprintf( /* translators: %s: number of different products. */ __( '%s different products', 'oc-theme' ), number_format_i18n( (int) ( $now->products ?? 0 ) ) ) ),
			array( number_format_i18n( (int) ( $now->owners ?? 0 ) ), __( 'People saving', 'oc-theme' ), sprintf( /* translators: %s: number of signed-in customers. */ __( '%s of them signed in', 'oc-theme' ), number_format_i18n( (int) ( $now->customers ?? 0 ) ) ) ),
			array( number_format_i18n( $new ), __( 'Saved in the last 30 days', 'oc-theme' ), sprintf( /* translators: %s: number of lists. */ __( '%s lists customers made', 'oc-theme' ), number_format_i18n( $own ) ) ),
			array( $adds90 ? round( 100 * $bought90 / $adds90, 1 ) . '%' : '—', __( 'Saved, then bought', 'oc-theme' ), sprintf( /* translators: 1: bought, 2: saved. */ __( '%1$s of %2$s saves in 90 days', 'oc-theme' ), number_format_i18n( $bought90 ), number_format_i18n( $adds90 ) ) ),
			array( $sent ? round( 100 * $sold / $sent, 1 ) . '%' : '—', __( 'Back-in-stock notice, then bought', 'oc-theme' ), sprintf( /* translators: 1: purchases, 2: notices sent. */ __( '%1$s purchases from %2$s notices in 90 days', 'oc-theme' ), number_format_i18n( $sold ), number_format_i18n( $sent ) ) ),
		);
		?>
		<div class="ocwl-tiles">
			<?php foreach ( $tiles as $t ) : ?>
				<div class="ocwl-tile"><b><?php echo esc_html( (string) $t[0] ); ?></b><span><?php echo esc_html( $t[1] ); ?></span><small><?php echo esc_html( $t[2] ); ?></small></div>
			<?php endforeach; ?>
		</div>

		<div class="ocwl-cols">
			<div class="ocwl-card">
				<h2><?php esc_html_e( 'Where products are saved from', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Last 30 days.', 'oc-theme' ); ?></p>
				<?php
				$names = array(
					'card'    => __( 'Catalogue', 'oc-theme' ),
					'product' => __( 'Product page', 'oc-theme' ),
					'cart'    => __( 'Moved from the cart', 'oc-theme' ),
					'popup'   => __( 'The lists window', 'oc-theme' ),
					'list'    => __( 'The lists page', 'oc-theme' ),
					''        => __( 'Other', 'oc-theme' ),
				);
				self::bars(
					array_map(
						static function ( $r ) use ( $names ) {
							return array( (string) ( $names[ (string) $r->source ] ?? $r->source ), (int) $r->n );
						},
						(array) $src
					)
				);
				?>
			</div>
			<div class="ocwl-card">
				<h2><?php esc_html_e( 'Categories saved from most', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'What is in the lists now.', 'oc-theme' ); ?></p>
				<?php self::bars( self::categories( array_map( 'intval', (array) $ids ) ) ); ?>
			</div>
		</div>

		<div class="ocwl-card">
			<h2><?php esc_html_e( 'Products saved most', 'oc-theme' ); ?></h2>
			<table class="widefat striped ocwl-table">
				<thead><tr>
					<th><?php esc_html_e( 'Product', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'In lists now', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Saved, ever', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Bought after saving', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Waiting for back-in-stock', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Stock', 'oc-theme' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $top ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Nothing has been saved yet.', 'oc-theme' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( (array) $top as $r ) : ?>
					<?php $p = wc_get_product( (int) $r->product_id ); ?>
					<tr>
						<td><?php echo $p ? '<a href="' . esc_url( (string) get_edit_post_link( $p->get_id() ) ) . '">' . esc_html( $p->get_name() ) . '</a>' : '#' . (int) $r->product_id; ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r->now_saved ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r->adds ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r->bought ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( self::waiting( (int) $r->product_id ) ) ); ?></td>
						<td><?php echo $p ? ( $p->is_in_stock() ? esc_html__( 'In stock', 'oc-theme' ) : '<span class="ocwl-oos">' . esc_html__( 'Sold out', 'oc-theme' ) . '</span>' ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'A product that is saved a lot and sold out is worth restocking first; one with many waiting for back-in-stock is a ready audience for that notice.', 'oc-theme' ); ?></p>
		</div>

		<div class="ocwl-card">
			<h2><?php esc_html_e( 'Back-in-stock notices against purchases', 'oc-theme' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Notices sent in the last 90 days, and how many of those people bought the product within 30 days of the notice.', 'oc-theme' ); ?></p>
			<table class="widefat striped ocwl-table">
				<thead><tr>
					<th><?php esc_html_e( 'Product', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Notices sent', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Bought', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'In lists now', 'oc-theme' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $ntp ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No notices have been sent since this report began.', 'oc-theme' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( (array) $ntp as $r ) : ?>
					<?php $p = wc_get_product( (int) $r->product_id ); ?>
					<tr>
						<td><?php echo $p ? esc_html( $p->get_name() ) : '#' . (int) $r->product_id; ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r->sent ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r->bought ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( self::saved_now( (int) $r->product_id ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Horizontal bars: label, count.
	 *
	 * @param array<int,array{0:string,1:int}> $rows Rows.
	 */
	private static function bars( array $rows ): void {
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing yet.', 'oc-theme' ) . '</p>';
			return;
		}

		$max = max( 1, max( array_column( $rows, 1 ) ) );

		echo '<div class="ocwl-bars">';

		foreach ( $rows as $row ) {
			printf(
				'<div class="ocwl-bar"><span class="ocwl-bar__l">%1$s</span><span class="ocwl-bar__t"><span style="inline-size:%2$s%%"></span></span><span class="ocwl-bar__n">%3$s</span></div>',
				esc_html( $row[0] ),
				esc_attr( (string) round( 100 * $row[1] / $max ) ),
				esc_html( number_format_i18n( $row[1] ) )
			);
		}

		echo '</div>';
	}

	/**
	 * The categories the saved products sit in, most first.
	 *
	 * @param array<int,int> $ids Products.
	 * @return array<int,array{0:string,1:int}>
	 */
	private static function categories( array $ids ): array {
		$count = array();

		foreach ( $ids as $id ) {
			$terms = get_the_terms( $id, 'product_cat' );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$count[ $term->name ] = ( $count[ $term->name ] ?? 0 ) + 1;
			}
		}

		arsort( $count );

		$out = array();

		foreach ( array_slice( $count, 0, 10, true ) as $name => $n ) {
			$out[] = array( (string) $name, (int) $n );
		}

		return $out;
	}

	/**
	 * How many people wait for this product to come back.
	 *
	 * @param int $product Product.
	 */
	private static function waiting( int $product ): int {
		$list = get_post_meta( $product, '_oc_notify_list', true );

		return is_array( $list ) ? count( $list ) : 0;
	}

	/**
	 * How many lists hold this product now.
	 *
	 * @param int $product Product.
	 */
	private static function saved_now( int $product ): int {
		global $wpdb;

		$table = Wishlist::table( 'items' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND removed IS NULL", $product ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the feature's own table, its name built by the class.
	}

	/**
	 * Looks.
	 */
	private static function styles(): void {
		?>
		<style>
		.ocwl-admin .ocwl-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 6px 20px 16px; margin: 16px 0; max-inline-size: 980px; }
		.ocwl-admin .ocwl-card h2 { margin-block: 14px 8px; }
		.ocwl-admin .ocwl-opt { display: block; margin: 6px 0; }
		.ocwl-admin .ocwl-h { font-weight: 600; margin: 14px 0 2px; }
		.ocwl-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 16px 0; max-inline-size: 1000px; }
		.ocwl-tile { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px 16px; display: flex; flex-direction: column; gap: 3px; }
		.ocwl-tile b { font-size: 24px; line-height: 1.2; }
		.ocwl-tile span { font-weight: 600; }
		.ocwl-tile small { color: #646970; }
		.ocwl-cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 0 16px; max-inline-size: 1000px; }
		.ocwl-cols .ocwl-card { margin-block: 0 16px; }
		.ocwl-bars { display: flex; flex-direction: column; gap: 8px; margin-block-start: 8px; }
		.ocwl-bar { display: grid; grid-template-columns: 130px 1fr 44px; gap: 10px; align-items: center; }
		.ocwl-bar__t { block-size: 10px; background: #f0f0f1; border-radius: 6px; overflow: hidden; }
		.ocwl-bar__t span { display: block; block-size: 100%; background: #2271b1; border-radius: 6px; }
		.ocwl-bar__n { text-align: end; font-variant-numeric: tabular-nums; }
		.ocwl-table td, .ocwl-table th { vertical-align: middle; }
		.ocwl-oos { color: #b32d2e; font-weight: 600; }
		</style>
		<?php
	}
}
