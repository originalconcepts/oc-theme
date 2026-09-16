<?php
/**
 * Settings → Product contact: which products make people press the button.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The statistics screen.
 */
final class Product_Contact_Admin {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	/**
	 * Under Settings.
	 */
	public function menu(): void {
		add_options_page( __( 'Product contact', 'oc-theme' ), __( 'Product contact', 'oc-theme' ), 'manage_woocommerce', 'oc-contact', array( $this, 'render' ) );
	}

	/**
	 * The period asked for: a preset or two dates.
	 *
	 * @return array{from:string,to:string,preset:string}
	 */
	private static function period(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only report filter.
		$preset = sanitize_key( (string) ( $_GET['period'] ?? '30' ) );
		$from   = sanitize_text_field( wp_unslash( (string) ( $_GET['from'] ?? '' ) ) );
		$to     = sanitize_text_field( wp_unslash( (string) ( $_GET['to'] ?? '' ) ) );
		// phpcs:enable

		$today = (string) current_time( 'Y-m-d' );
		$ok    = static fn( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );

		if ( 'custom' === $preset && $ok( $from ) && $ok( $to ) ) {
			return array(
				'from'   => $from <= $to ? $from : $to,
				'to'     => $from <= $to ? $to : $from,
				'preset' => 'custom',
			);
		}

		$days = array(
			'today' => 0,
			'7'     => 6,
			'30'    => 29,
			'90'    => 89,
			'365'   => 364,
			'all'   => 3650,
		);

		if ( ! isset( $days[ $preset ] ) ) {
			$preset = '30';
		}

		return array(
			'from'   => (string) wp_date( 'Y-m-d', strtotime( $today . ' -' . $days[ $preset ] . ' days' ) ),
			'to'     => $today,
			'preset' => $preset,
		);
	}

	/**
	 * The screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$p    = self::period();
		$rows = Product_Contact::stats( $p['from'], $p['to'] );
		$days = Product_Contact::by_day( $p['from'], $p['to'] );
		$on   = (bool) get_theme_mod( 'oc_contact_on', false );
		$sum  = array(
			'whatsapp' => 0,
			'phone'    => 0,
			'total'    => 0,
		);

		foreach ( $rows as $r ) {
			$sum['whatsapp'] += $r['whatsapp'];
			$sum['phone']    += $r['phone'];
			$sum['total']    += $r['total'];
		}

		$presets = array(
			'today' => __( 'Today', 'oc-theme' ),
			'7'     => __( 'Last 7 days', 'oc-theme' ),
			'30'    => __( 'Last 30 days', 'oc-theme' ),
			'90'    => __( 'Last 90 days', 'oc-theme' ),
			'365'   => __( 'Last year', 'oc-theme' ),
			'all'   => __( 'All time', 'oc-theme' ),
		);

		$peak = max( 1, $days ? max( $days ) : 1 );
		?>
		<div class="wrap occon">
			<h1><?php esc_html_e( 'Product contact', 'oc-theme' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Every press of the WhatsApp or call button on a product page, by product.', 'oc-theme' ); ?>
				<?php if ( ! $on ) : ?>
					<strong><?php esc_html_e( 'The block is switched off right now — turn it on under Customize → Product page → Contact.', 'oc-theme' ); ?></strong>
				<?php else : ?>
					<a href="<?php echo esc_url( admin_url( 'customize.php?autofocus[section]=oc_contact' ) ); ?>"><?php esc_html_e( 'Edit the block', 'oc-theme' ); ?></a>
				<?php endif; ?>
			</p>

			<form method="get" class="occon-filter">
				<input type="hidden" name="page" value="oc-contact">
				<select name="period">
					<?php foreach ( $presets as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $p['preset'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
					<option value="custom" <?php selected( $p['preset'], 'custom' ); ?>><?php esc_html_e( 'Between dates', 'oc-theme' ); ?></option>
				</select>
				<input type="date" name="from" value="<?php echo esc_attr( $p['from'] ); ?>">
				<input type="date" name="to" value="<?php echo esc_attr( $p['to'] ); ?>">
				<button class="button"><?php esc_html_e( 'Show', 'oc-theme' ); ?></button>
			</form>

			<div class="occon-cards">
				<div class="occon-card"><span><?php esc_html_e( 'All presses', 'oc-theme' ); ?></span><strong><?php echo esc_html( number_format_i18n( $sum['total'] ) ); ?></strong></div>
				<div class="occon-card"><span><?php esc_html_e( 'WhatsApp', 'oc-theme' ); ?></span><strong><?php echo esc_html( number_format_i18n( $sum['whatsapp'] ) ); ?></strong></div>
				<div class="occon-card"><span><?php esc_html_e( 'Calls', 'oc-theme' ); ?></span><strong><?php echo esc_html( number_format_i18n( $sum['phone'] ) ); ?></strong></div>
				<div class="occon-card"><span><?php esc_html_e( 'Products pressed on', 'oc-theme' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?></strong></div>
			</div>

			<?php if ( $days ) : ?>
				<div class="occon-chart" aria-label="<?php esc_attr_e( 'Presses per day', 'oc-theme' ); ?>">
					<?php foreach ( $days as $day => $n ) : ?>
						<span class="occon-bar" style="--h:<?php echo esc_attr( (string) round( $n / $peak * 100 ) ); ?>%" title="<?php echo esc_attr( wp_date( 'j.n', strtotime( $day ) ) . ' · ' . $n ); ?>"></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<table class="widefat striped occon-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'oc-theme' ); ?></th>
						<th class="num"><?php esc_html_e( 'WhatsApp', 'oc-theme' ); ?></th>
						<th class="num"><?php esc_html_e( 'Calls', 'oc-theme' ); ?></th>
						<th class="num"><?php esc_html_e( 'Total', 'oc-theme' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No presses in this period.', 'oc-theme' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td>
								<?php $title = get_the_title( $r['product_id'] ); ?>
								<a href="<?php echo esc_url( (string) get_permalink( $r['product_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== $title ? $title : '#' . $r['product_id'] ); ?></a>
								<a class="occon-edit" href="<?php echo esc_url( (string) get_edit_post_link( $r['product_id'] ) ); ?>"><?php esc_html_e( 'edit', 'oc-theme' ); ?></a>
							</td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r['whatsapp'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r['phone'] ) ); ?></td>
							<td class="num"><strong><?php echo esc_html( number_format_i18n( $r['total'] ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<style>
			.occon-filter { display: flex; gap: 8px; align-items: center; margin: 12px 0 16px; flex-wrap: wrap; }
			.occon-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-block-end: 16px; max-inline-size: 900px; }
			.occon-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; }
			.occon-card span { color: #646970; font-size: 12px; }
			.occon-card strong { font-size: 24px; line-height: 1.1; }
			.occon-chart { display: flex; align-items: flex-end; gap: 3px; block-size: 80px; max-inline-size: 900px; margin-block-end: 18px; padding: 6px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; }
			.occon-bar { flex: 1 1 0; min-inline-size: 3px; block-size: max(2px, var(--h)); background: #2271b1; border-radius: 2px 2px 0 0; }
			.occon-table { max-inline-size: 900px; }
			.occon-table .num { text-align: end; inline-size: 110px; }
			.occon-edit { margin-inline-start: 8px; font-size: 12px; color: #646970; }
		</style>
		<?php
	}
}
