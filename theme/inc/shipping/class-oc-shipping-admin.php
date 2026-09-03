<?php
/**
 * Settings ← Shipping: one screen that says how delivery is priced.
 *
 * Three cards — the basics, the groups, the regions — a switch that hands
 * WooCommerce's zone to the theme or back, and a simulator that prices any
 * parcel to any address and says why. Everything it saves is the one rules
 * option the calculator reads; nothing here computes a price of its own.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

use OC\Theme\Shipping\Engage;
use OC\Theme\Shipping\Quote;
use OC\Theme\Shipping\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * The shipping screen.
 */
final class Shipping_Admin {

	const NONCE = 'ocship';

	/**
	 * Hooks.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_ocship_save', array( $this, 'handle_save' ) );
		add_action( 'wp_ajax_ocship_sim', array( $this, 'ajax_simulate' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * The room's address.
	 */
	public static function url(): string {
		return admin_url( 'options-general.php?page=oc-shipping' );
	}

	/**
	 * Under Settings, beside the other OC rooms.
	 */
	public function menu(): void {
		add_options_page(
			__( 'Shipping', 'oc-theme' ),
			__( 'Shipping', 'oc-theme' ),
			'manage_woocommerce',
			'oc-shipping',
			array( $this, 'render' )
		);
	}

	/**
	 * WooCommerce's product search, for the simulator.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( string $hook ): void {
		if ( 'settings_page_oc-shipping' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
	}

	/**
	 * The screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$rules  = Rules::get();
		$status = Engage::status();
		$msg    = isset( $_GET['ocship_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ocship_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice to show, nothing acted on.
		$sym    = get_woocommerce_currency_symbol();

		echo '<div class="wrap ocship">';
		echo '<h1>' . esc_html__( 'Shipping', 'oc-theme' ) . '</h1>';

		if ( '' !== $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ocship-form">
			<input type="hidden" name="action" value="ocship_save" />
			<?php wp_nonce_field( self::NONCE ); ?>

			<div class="ocship-card ocship-card--switch">
				<label class="ocship-switch">
					<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $rules['enabled'] ) ); ?> />
					<strong><?php esc_html_e( 'The theme prices delivery', 'oc-theme' ); ?></strong>
				</label>
				<p class="description">
					<?php if ( ! empty( $rules['enabled'] ) ) : ?>
						<?php
						printf(
							/* translators: %s: zone name. */
							esc_html__( 'On. Every parcel is priced by the rules below; “OC shipping” is the delivery method in the “%s” zone.', 'oc-theme' ),
							esc_html( $status['zone'] )
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Off. WooCommerce prices delivery with its own methods. Switching on pauses those methods in the shop’s zone and prices every parcel by the rules below; switching off gives them back exactly as they were.', 'oc-theme' ); ?>
						<?php if ( $status['others'] ) : ?>
							<br /><?php echo esc_html( sprintf( /* translators: %s: method names. */ __( 'Active now: %s', 'oc-theme' ), implode( ', ', $status['others'] ) ) ); ?>
						<?php endif; ?>
					<?php endif; ?>
				</p>
				<?php if ( empty( $rules['enabled'] ) && $status['ours'] ) : ?>
					<p class="description ocship-warn"><?php esc_html_e( '“OC shipping” was added to the zone by hand while this switch is off: it prices parcels by the rules below as they stand. Switch on to make the rules the single source, or remove the method from the zone.', 'oc-theme' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="ocship-card">
				<h2><?php esc_html_e( 'The basics', 'oc-theme' ); ?></h2>
				<div class="ocship-grid">
					<label>
						<span><?php esc_html_e( 'Delivery price', 'oc-theme' ); ?> (<?php echo esc_html( $sym ); ?>)</span>
						<input type="number" step="0.01" min="0" name="base" value="<?php echo esc_attr( (string) $rules['base'] ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'Free delivery over', 'oc-theme' ); ?> (<?php echo esc_html( $sym ); ?>)</span>
						<input type="number" step="0.01" min="0" name="free_over" value="<?php echo esc_attr( (string) $rules['free_over'] ); ?>" />
						<small><?php esc_html_e( '0 = never free.', 'oc-theme' ); ?></small>
					</label>
					<label>
						<span><?php esc_html_e( 'Name at the checkout', 'oc-theme' ); ?></span>
						<input type="text" name="label" value="<?php echo esc_attr( (string) $rules['label'] ); ?>" placeholder="<?php esc_attr_e( 'Home delivery', 'oc-theme' ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'Name when free', 'oc-theme' ); ?></span>
						<input type="text" name="free_label" value="<?php echo esc_attr( (string) $rules['free_label'] ); ?>" placeholder="<?php esc_attr_e( 'Free shipping', 'oc-theme' ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'When several prices apply', 'oc-theme' ); ?></span>
						<select name="mode">
							<option value="max" <?php selected( 'max', $rules['mode'] ); ?>><?php esc_html_e( 'The highest one is charged', 'oc-theme' ); ?></option>
							<option value="sum" <?php selected( 'sum', $rules['mode'] ); ?>><?php esc_html_e( 'They add up', 'oc-theme' ); ?></option>
						</select>
					</label>
					<label class="ocship-check">
						<input type="checkbox" name="free_ignore_coupons" value="1" <?php checked( ! empty( $rules['free_ignore_coupons'] ) ); ?> />
						<span><?php esc_html_e( 'Count the cart before coupons toward free delivery', 'oc-theme' ); ?></span>
					</label>
				</div>
			</div>

			<div class="ocship-card">
				<h2><?php esc_html_e( 'Product groups', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Products that ship at a price of their own. Each group is a WooCommerce shipping class — assign it on the product (Shipping tab) or in bulk edit. A group left out of free delivery is charged even when the rest of the cart earned it.', 'oc-theme' ); ?></p>
				<table class="widefat striped ocship-table" data-ocship-rows="groups">
					<thead><tr>
						<th><?php esc_html_e( 'Group', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Delivery price', 'oc-theme' ); ?> (<?php echo esc_html( $sym ); ?>)</th>
						<th><?php esc_html_e( 'Included in free delivery', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Products', 'oc-theme' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php $i = 0; ?>
					<?php foreach ( $rules['groups'] as $g ) : ?>
						<tr>
							<td><input type="hidden" name="groups[<?php echo (int) $i; ?>][slug]" value="<?php echo esc_attr( $g['slug'] ); ?>" /><input type="text" name="groups[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $g['name'] ); ?>" required /></td>
							<td><input type="number" step="0.01" min="0" name="groups[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( (string) $g['price'] ); ?>" /></td>
							<td><input type="checkbox" name="groups[<?php echo (int) $i; ?>][in_free]" value="1" <?php checked( $g['in_free'] ); ?> /></td>
							<td><?php echo (int) self::class_count( $g['slug'] ); ?></td>
							<td><button type="button" class="button-link-delete" data-ocship-remove><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button></td>
						</tr>
						<?php ++$i; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<template data-ocship-template="groups">
					<tr>
						<td><input type="hidden" name="groups[__i__][slug]" value="" /><input type="text" name="groups[__i__][name]" value="" required placeholder="<?php esc_attr_e( 'e.g. Kitchen appliances', 'oc-theme' ); ?>" /></td>
						<td><input type="number" step="0.01" min="0" name="groups[__i__][price]" value="" /></td>
						<td><input type="checkbox" name="groups[__i__][in_free]" value="1" /></td>
						<td>0</td>
						<td><button type="button" class="button-link-delete" data-ocship-remove><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button></td>
					</tr>
				</template>
				<p><button type="button" class="button" data-ocship-add="groups"><?php esc_html_e( 'Add a group', 'oc-theme' ); ?></button></p>
			</div>

			<div class="ocship-card">
				<h2><?php esc_html_e( 'Regions', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Places that cost differently. Match by postcode — an exact code, a range like 88000-88999, or a prefix like 88* — and/or by city name as the customer types it at the checkout (several names, comma-separated). The first matching region wins.', 'oc-theme' ); ?></p>
				<table class="widefat striped ocship-table" data-ocship-rows="regions">
					<thead><tr>
						<th><?php esc_html_e( 'Region', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Postcodes', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Cities', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Delivery price', 'oc-theme' ); ?> (<?php echo esc_html( $sym ); ?>)</th>
						<th><?php esc_html_e( 'Free delivery', 'oc-theme' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php $i = 0; ?>
					<?php foreach ( $rules['regions'] as $r ) : ?>
						<tr>
							<td><input type="text" name="regions[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $r['name'] ); ?>" required /></td>
							<td><input type="text" class="ltr" name="regions[<?php echo (int) $i; ?>][postcodes]" value="<?php echo esc_attr( implode( ', ', $r['postcodes'] ) ); ?>" /></td>
							<td><input type="text" name="regions[<?php echo (int) $i; ?>][cities]" value="<?php echo esc_attr( implode( ', ', $r['cities'] ) ); ?>" /></td>
							<td><input type="number" step="0.01" min="0" name="regions[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( (string) $r['price'] ); ?>" /></td>
							<td><select name="regions[<?php echo (int) $i; ?>][free]">
								<option value="inherit" <?php selected( 'inherit', $r['free'] ); ?>><?php esc_html_e( 'As everywhere', 'oc-theme' ); ?></option>
								<option value="no" <?php selected( 'no', $r['free'] ); ?>><?php esc_html_e( 'Never free here', 'oc-theme' ); ?></option>
							</select></td>
							<td><button type="button" class="button-link-delete" data-ocship-remove><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button></td>
						</tr>
						<?php ++$i; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<template data-ocship-template="regions">
					<tr>
						<td><input type="text" name="regions[__i__][name]" value="" required placeholder="<?php esc_attr_e( 'e.g. Eilat', 'oc-theme' ); ?>" /></td>
						<td><input type="text" class="ltr" name="regions[__i__][postcodes]" value="" placeholder="88000-88999" /></td>
						<td><input type="text" name="regions[__i__][cities]" value="" placeholder="<?php esc_attr_e( 'Eilat', 'oc-theme' ); ?>" /></td>
						<td><input type="number" step="0.01" min="0" name="regions[__i__][price]" value="" /></td>
						<td><select name="regions[__i__][free]"><option value="inherit"><?php esc_html_e( 'As everywhere', 'oc-theme' ); ?></option><option value="no"><?php esc_html_e( 'Never free here', 'oc-theme' ); ?></option></select></td>
						<td><button type="button" class="button-link-delete" data-ocship-remove><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button></td>
					</tr>
				</template>
				<p><button type="button" class="button" data-ocship-add="regions"><?php esc_html_e( 'Add a region', 'oc-theme' ); ?></button></p>
			</div>

			<p class="submit"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save shipping rules', 'oc-theme' ); ?></button></p>
		</form>

		<div class="ocship-card ocship-sim" data-ocship-sim>
			<h2><?php esc_html_e( 'Check a price', 'oc-theme' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Pick products and an address; the price and the reasons come from the very same calculator the checkout uses — with the rules as saved above.', 'oc-theme' ); ?></p>
			<div class="ocship-grid">
				<label class="ocship-grid__wide">
					<span><?php esc_html_e( 'Products', 'oc-theme' ); ?></span>
					<select class="wc-product-search" multiple="multiple" style="width:100%" data-action="woocommerce_json_search_products_and_variations" data-placeholder="<?php esc_attr_e( 'Start typing a product name…', 'oc-theme' ); ?>" data-ocship-products></select>
				</label>
				<label><span><?php esc_html_e( 'Postcode', 'oc-theme' ); ?></span><input type="text" class="ltr" data-ocship-postcode /></label>
				<label><span><?php esc_html_e( 'City', 'oc-theme' ); ?></span><input type="text" data-ocship-city /></label>
			</div>
			<p><button type="button" class="button" data-ocship-run><?php esc_html_e( 'Calculate', 'oc-theme' ); ?></button></p>
			<div class="ocship-sim__out" data-ocship-out hidden></div>
		</div>
		<?php

		$this->footer_assets();
		echo '</div>';
	}

	/**
	 * How many products carry a shipping class.
	 *
	 * @param string $slug Class slug.
	 */
	private static function class_count( string $slug ): int {
		$term = get_term_by( 'slug', $slug, 'product_shipping_class' );

		return $term instanceof \WP_Term ? (int) $term->count : 0;
	}

	/**
	 * Styles and the little scripts: rows that add and go, and the simulator.
	 */
	private function footer_assets(): void {
		?>
		<style>
			.ocship .ltr { direction: ltr; text-align: left; }
			.ocship-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 18px 20px; margin: 16px 0; max-width: 1100px; }
			.ocship-card h2 { margin: 0 0 6px; font-size: 1.1em; }
			.ocship-card > .description { margin: 0 0 14px; }
			.ocship-card--switch { display: flex; flex-direction: column; gap: 6px; border-color: #2271b1; }
			.ocship-switch { display: flex; align-items: center; gap: 10px; font-size: 1.05em; }
			.ocship-warn { color: #8a4b00; background: #fcf9e8; border-inline-start: 3px solid #dba617; padding: 8px 10px; }
			.ocship-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px 18px; }
			.ocship-grid label > span { display: block; font-weight: 600; margin-bottom: 4px; }
			.ocship-grid label input[type="text"], .ocship-grid label input[type="number"], .ocship-grid label select { width: 100%; }
			.ocship-grid small { display: block; color: #646970; margin-top: 3px; }
			.ocship-grid__wide { grid-column: 1 / -1; }
			.ocship-check { display: flex; align-items: center; gap: 8px; align-self: end; }
			.ocship-check span { font-weight: 400 !important; }
			.ocship-table { margin: 8px 0; }
			.ocship-table input[type="text"], .ocship-table input[type="number"] { width: 100%; }
			.ocship-table input[type="number"] { max-width: 120px; }
			.ocship-sim__out { margin-top: 12px; padding: 12px 14px; border-radius: 6px; background: #f6f7f7; border: 1px solid #dcdcde; }
			.ocship-sim__out strong.ocship-price { font-size: 1.3em; }
			.ocship-sim__out ul { margin: 8px 0 0 0; padding-inline-start: 18px; }
		</style>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var ajax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			document.querySelectorAll( '[data-ocship-add]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var kind = btn.dataset.ocshipAdd;
					var body = document.querySelector( '[data-ocship-rows="' + kind + '"] tbody' );
					var tpl  = document.querySelector( '[data-ocship-template="' + kind + '"]' );
					var i    = Date.now();
					var html = tpl.innerHTML.split( '__i__' ).join( String( i ) );
					body.insertAdjacentHTML( 'beforeend', html );
				} );
			} );

			document.addEventListener( 'click', function ( e ) {
				var rm = e.target.closest( '[data-ocship-remove]' );
				if ( rm ) { rm.closest( 'tr' ).remove(); }
			} );

			var sim = document.querySelector( '[data-ocship-sim]' );
			if ( ! sim ) { return; }

			sim.querySelector( '[data-ocship-run]' ).addEventListener( 'click', function () {
				var out  = sim.querySelector( '[data-ocship-out]' );
				var sel  = sim.querySelector( '[data-ocship-products]' );
				var ids  = Array.prototype.map.call( sel.selectedOptions, function ( o ) { return o.value; } );
				var body = new FormData();
				body.append( 'action', 'ocship_sim' );
				body.append( 'nonce', nonce );
				body.append( 'postcode', sim.querySelector( '[data-ocship-postcode]' ).value );
				body.append( 'city', sim.querySelector( '[data-ocship-city]' ).value );
				ids.forEach( function ( id ) { body.append( 'ids[]', id ); } );
				out.hidden = false;
				out.textContent = '…';
				fetch( ajax, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( r ) {
						if ( ! r || ! r.success ) { out.textContent = ( r && r.data ) || 'error'; return; }
						out.innerHTML = r.data.html;
					} );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Save the rules; sync the groups to shipping classes; throw the switch
	 * if it moved.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( self::NONCE );

		$before = Rules::get();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- check_admin_referer() ran above; Rules::normalize() types and bounds every field.
		$raw = array(
			'enabled'             => ! empty( $_POST['enabled'] ),
			'base'                => (float) ( $_POST['base'] ?? 0 ),
			'free_over'           => (float) ( $_POST['free_over'] ?? 0 ),
			'free_ignore_coupons' => ! empty( $_POST['free_ignore_coupons'] ),
			'mode'                => sanitize_key( (string) wp_unslash( $_POST['mode'] ?? 'max' ) ),
			'label'               => sanitize_text_field( (string) wp_unslash( $_POST['label'] ?? '' ) ),
			'free_label'          => sanitize_text_field( (string) wp_unslash( $_POST['free_label'] ?? '' ) ),
			'groups'              => array(),
			'regions'             => array(),
		);

		foreach ( (array) ( $_POST['groups'] ?? array() ) as $g ) {
			$name = sanitize_text_field( (string) wp_unslash( $g['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$slug = sanitize_title( (string) wp_unslash( $g['slug'] ?? '' ) );

			$raw['groups'][] = array(
				'slug'    => '' !== $slug ? $slug : sanitize_title( $name ),
				'name'    => $name,
				'price'   => (float) ( $g['price'] ?? 0 ),
				'in_free' => ! empty( $g['in_free'] ),
			);
		}

		foreach ( (array) ( $_POST['regions'] ?? array() ) as $r ) {
			$raw['regions'][] = array(
				'name'      => sanitize_text_field( (string) wp_unslash( $r['name'] ?? '' ) ),
				'postcodes' => sanitize_text_field( (string) wp_unslash( $r['postcodes'] ?? '' ) ),
				'cities'    => sanitize_text_field( (string) wp_unslash( $r['cities'] ?? '' ) ),
				'price'     => (float) ( $r['price'] ?? 0 ),
				'free'      => sanitize_key( (string) wp_unslash( $r['free'] ?? 'inherit' ) ),
			);
		}
		// phpcs:enable

		Rules::save( $raw );
		$rules = Rules::get();

		// Every group is a shipping class the product editor can assign.
		foreach ( $rules['groups'] as $g ) {
			if ( ! term_exists( $g['slug'], 'product_shipping_class' ) ) {
				wp_insert_term( $g['name'], 'product_shipping_class', array( 'slug' => $g['slug'] ) );
			}
		}

		$msg = __( 'Shipping rules saved.', 'oc-theme' );

		if ( $rules['enabled'] && ! $before['enabled'] ) {
			$msg .= ' ' . Engage::on();
		} elseif ( ! $rules['enabled'] && $before['enabled'] ) {
			$msg .= ' ' . Engage::off();
		} else {
			Engage::flush();
		}

		wp_safe_redirect( add_query_arg( 'ocship_msg', rawurlencode( $msg ), self::url() ) );
		exit;
	}

	/**
	 * The simulator: products and an address in, a price and its reasons out.
	 */
	public function ajax_simulate(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( __( 'Not allowed.', 'oc-theme' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_ajax_referer() ran above.
		$ids  = array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ) );
		$dest = array(
			'country'  => (string) WC()->countries->get_base_country(),
			'postcode' => sanitize_text_field( (string) wp_unslash( $_POST['postcode'] ?? '' ) ),
			'city'     => sanitize_text_field( (string) wp_unslash( $_POST['city'] ?? '' ) ),
		);
		// phpcs:enable

		$rules = Rules::get();
		$lines = array();
		$named = array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$lines[] = array(
				'subtotal' => (float) wc_get_price_to_display( $product ),
				'group'    => (string) $product->get_shipping_class(),
				'qty'      => 1,
			);

			$named[] = sprintf(
				'%s — %s%s',
				$product->get_name(),
				wp_strip_all_tags( wc_price( (float) wc_get_price_to_display( $product ) ) ),
				'' !== $product->get_shipping_class() ? ' (' . $product->get_shipping_class_id() . ' ' . ( $rules['groups'][ $product->get_shipping_class() ]['name'] ?? $product->get_shipping_class() ) . ')' : ''
			);
		}

		$quote = Quote::calculate( $lines, $dest, $rules );

		$html  = '<p><strong class="ocship-price">' . esc_html( $quote['free'] ? Shipping::free_label( $rules ) : wp_strip_all_tags( wc_price( $quote['cost'] ) ) ) . '</strong>';
		$html .= $quote['free'] ? '' : ' <span>' . esc_html( Shipping::label( $rules ) ) . '</span>';
		$html .= '</p>';

		$why = Shipping::explain( $quote );

		if ( '' !== $why ) {
			$html .= '<p>' . esc_html( $why ) . '</p>';
		}

		if ( $quote['missing'] > 0 ) {
			/* translators: %s: sum still missing for free delivery. */
			$html .= '<p>' . esc_html( sprintf( __( '%s more (in eligible products) would make delivery free.', 'oc-theme' ), wp_strip_all_tags( wc_price( $quote['missing'] ) ) ) ) . '</p>';
		}

		if ( $named ) {
			$html .= '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $named ) ) . '</li></ul>';
		} else {
			$html .= '<p>' . esc_html__( 'An empty parcel: the base price.', 'oc-theme' ) . '</p>';
		}

		wp_send_json_success( array( 'html' => $html ) );
	}
}
