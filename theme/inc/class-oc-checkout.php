<?php
/**
 * Checkout: a custom-looking flow on WooCommerce's real checkout form.
 *
 * No template overrides (DECISIONS.md #7). The layout is the native
 * form.checkout re-ordered through woocommerce_checkout_fields, sectioned by
 * a pseudo-field (delivery methods + recipient block) and dressed in CSS/JS —
 * so payment gateways and third-party checkout plugins keep rendering
 * exactly where they expect to.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout flow, fields, settings and order meta.
 */
final class Checkout {

	/**
	 * Settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$saved = get_option( 'oc_checkout' );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'summary'         => 1,       // Product list in the order summary.
				'coupon_mode'     => 'open',  // open | button | hide.
				'country_mode'    => 'auto',  // auto | hide.
				'send_other'      => 1,       // "I'm sending to someone else" toggle.
				'phone2_required' => 0,       // Recipient's second phone required.
				'apt_required'    => 0,       // Apartment number required.
				'floor_required'  => 0,       // Floor required.
				'entry_required'  => 0,       // Entry code required.
				'phone_min'       => 9,       // 0 = no minimum.
				'phone_max'       => 10,      // 0 = no maximum.
				'btn_total'       => 1,       // Total on the place-order button.
				'btn_text'        => '',      // Override for the button label.
				'notes'           => 1,       // Order notes field.
				'consent'         => 1,       // Marketing-consent checkbox.
				'consent_text'    => '',      // Override for its label.
				'help_text'       => '',      // Header help line ("Need help? 077…").
			)
		);
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_checkout_fields', array( $this, 'fields' ), 20 );
		add_filter( 'woocommerce_package_rates', array( $this, 'free_hides_paid' ), 20 );
		add_filter( 'woocommerce_get_country_locale', array( $this, 'country_locale' ), 20 );
		add_filter( 'woocommerce_form_field_oc_co_shipping', array( $this, 'shipping_section' ), 10, 2 );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'rates_fragment' ) );
		add_filter( 'woocommerce_cart_shipping_method_full_label', array( $this, 'free_label' ), 10, 2 );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		// Our login block replaces the quiet default notice.
		add_action(
			'init',
			static function (): void {
				remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
			},
			20
		);
		add_action(
			'init',
			static function (): void {
				remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
			},
			20
		);
		add_action( 'woocommerce_before_checkout_form', array( $this, 'brand_row' ), 3 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'login_block' ), 5 );
		add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'orderer_heading' ) );
		add_action( 'woocommerce_review_order_after_cart_contents', array( $this, 'summary_coupon_row' ) );
		add_action( 'woocommerce_review_order_after_shipping', array( $this, 'shipping_row' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'savings_row' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'vat_note_row' ) );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'payment_heading' ) );
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'privacy_note' ), 12 );

		add_action( 'woocommerce_review_order_before_submit', array( $this, 'consent_checkbox' ), 15 );
		add_filter( 'woocommerce_order_button_text', array( $this, 'button_text' ) );

		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_meta' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'admin_meta' ) );
		add_action( 'woocommerce_email_order_meta', array( $this, 'email_meta' ), 10, 3 );

		add_filter( 'woocommerce_cart_item_name', array( $this, 'review_item_name' ), 10, 3 );
		add_filter( 'woocommerce_checkout_cart_item_quantity', array( $this, 'review_item_qty' ), 10, 3 );



		add_action( 'wp_ajax_oc_co_stash', array( $this, 'ajax_stash' ) );
		add_action( 'wp_ajax_nopriv_oc_co_stash', array( $this, 'ajax_stash' ) );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'stashed_value' ), 10, 2 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'stash_script' ), 4 );

		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_oc_checkout_save', array( $this, 'save_settings' ) );
	}

	/* ------------------------------------------------------------- stash */

	/**
	 * Everything the visitor typed, kept in the session so a trip back to
	 * the cart (or a reload) never empties the form. Woo only persists the
	 * address fields it needs for shipping — name, email, phone and our own
	 * fields would otherwise be gone.
	 */
	public function ajax_stash(): void {
		if ( ! WC()->session ) {
			wp_send_json_success();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- session-scoped scratch data, no privileges.
		$raw = isset( $_POST['fields'] ) ? (array) wp_unslash( $_POST['fields'] ) : array();
		$out = array();

		foreach ( $raw as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$out[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
		}

		WC()->session->set( 'oc_co_stash', $out );

		wp_send_json_success();
	}

	/**
	 * The stash, or an empty array.
	 *
	 * @return array<string,string>
	 */
	private function stash(): array {
		$stash = WC()->session ? WC()->session->get( 'oc_co_stash' ) : array();

		return is_array( $stash ) ? $stash : array();
	}

	/**
	 * Prefill Woo's own fields from the stash.
	 *
	 * @param mixed  $value Woo's value.
	 * @param string $key   Field key.
	 * @return mixed
	 */
	public function stashed_value( $value, $key ) {
		if ( '' !== (string) $value && null !== $value ) {
			return $value;
		}

		$stash = $this->stash();

		return isset( $stash[ $key ] ) ? $stash[ $key ] : $value;
	}

	/**
	 * The stash for fields that are ours, not Woo's (recipient block, the
	 * toggle) — restored client-side.
	 */
	public function stash_script(): void {
		$stash = $this->stash();

		$mine = array();
		foreach ( array( 'oc_send_other', 'oc_recip_first', 'oc_recip_last', 'oc_recip_phone', 'oc_recip_phone2' ) as $key ) {
			if ( isset( $stash[ $key ] ) ) {
				$mine[ $key ] = $stash[ $key ];
			}
		}

		echo '<script id="oc-co-stash" type="application/json">' . wp_json_encode( $mine ) . '</script>';
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * Is this checkout run (render or submit) a local-pickup order?
	 */
	private function is_pickup(): bool {
		$method = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only peek at Woo's own posted field.
		if ( isset( $_POST['shipping_method'] ) ) {
			$posted = wp_unslash( $_POST['shipping_method'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$method = is_array( $posted ) ? (string) reset( $posted ) : (string) $posted;
		} elseif ( WC()->session ) {
			$chosen = (array) WC()->session->get( 'chosen_shipping_methods' );
			$method = (string) reset( $chosen );
		}

		return 0 === strpos( $method, 'local_pickup' );
	}

	/**
	 * Whether the "sending to someone else" toggle is on in this submit.
	 */
	private function sending_to_other(): bool {
		$s = self::settings();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- flag only, validated with the rest of checkout.
		return ! empty( $s['send_other'] ) && ! empty( $_POST['oc_send_other'] );
	}

	/**
	 * Digits-count validation per the settings. Empty limits skip.
	 */
	private function phone_ok( string $phone ): bool {
		$s      = self::settings();
		$digits = strlen( preg_replace( '/\D/', '', $phone ) );
		$min    = absint( $s['phone_min'] );
		$max    = absint( $s['phone_max'] );

		if ( $min && $digits < $min ) {
			return false;
		}
		if ( $max && $digits > $max ) {
			return false;
		}

		return true;
	}

	/**
	 * Once free shipping qualifies, paid delivery disappears — pickup stays.
	 *
	 * @param array $rates Package rates.
	 * @return array
	 */
	public function free_hides_paid( array $rates ): array {
		$has_free = false;

		foreach ( $rates as $rate ) {
			if ( 'free_shipping' === $rate->get_method_id() ) {
				$has_free = true;
				break;
			}
		}

		if ( ! $has_free ) {
			return $rates;
		}

		foreach ( $rates as $key => $rate ) {
			if ( ! in_array( $rate->get_method_id(), array( 'free_shipping', 'local_pickup' ), true ) ) {
				unset( $rates[ $key ] );
			}
		}

		return $rates;
	}

	/**
	 * The shipping totals row says "Free" like any other price.
	 *
	 * @param string           $label Method label.
	 * @param \WC_Shipping_Rate $rate  Rate.
	 * @return string
	 */
	public function free_label( $label, $rate ): string {
		$cost = (float) $rate->get_cost() + array_sum( array_map( 'floatval', $rate->get_taxes() ) );

		if ( $cost <= 0 && is_checkout() ) {
			$label .= '<span class="oc-co-freetag">' . esc_html__( 'Free', 'oc-theme' ) . '</span>';
		}

		return (string) $label;
	}

	/* ------------------------------------------------------------- fields */

	/**
	 * The whole field map: contact first, then the delivery section
	 * pseudo-field, then an Israeli-style address.
	 *
	 * @param array $fields Woo's checkout fields.
	 * @return array
	 */
	public function fields( array $fields ): array {
		$s      = self::settings();
		$pickup = $this->is_pickup();

		$b =& $fields['billing'];

		unset( $b['billing_company'] );

		// Contact block.
		$b['billing_first_name']['priority'] = 10;
		$b['billing_last_name']['priority']  = 20;
		$b['billing_email']['priority']      = 30;
		$b['billing_email']['class']         = array( 'form-row-first' );
		$b['billing_phone']['priority']      = 40;
		$b['billing_phone']['class']         = array( 'form-row-last' );
		$b['billing_phone']['required']      = true;

		$b['billing_first_name']['autocomplete'] = 'given-name';
		$b['billing_last_name']['autocomplete']  = 'family-name';
		$b['billing_email']['autocomplete']      = 'email';
		$b['billing_phone']['autocomplete']      = 'tel';
		$b['billing_phone']['custom_attributes'] = array( 'inputmode' => 'tel' );

		// Delivery methods + recipient toggle, rendered between contact and
		// address by a pseudo-field so everything stays inside Woo's form.
		$b['oc_co_shipping'] = array(
			'type'     => 'oc_co_shipping',
			'label'    => '',
			'required' => false,
			'priority' => 45,
			'class'    => array( 'form-row-wide', 'oc-co-shiprow' ),
		);

		// Address block — the furniture layout. On pickup nothing here is
		// required (the whole block hides client-side too).
		$b['billing_country']['priority'] = 50;
		$b['billing_state']['priority']   = 52;

		$b['billing_city']['priority'] = 55;
		$b['billing_city']['class']    = array( 'form-row-first' );
		$b['billing_city']['label']    = __( 'City', 'oc-theme' );

		$b['billing_address_1']['priority']    = 60;
		$b['billing_address_1']['class']       = array( 'form-row-last' );
		$b['billing_address_1']['label']       = __( 'Street and house number', 'oc-theme' );
		$b['billing_address_1']['placeholder'] = '';

		$b['billing_address_2']['priority'] = 65;
		$b['billing_address_2']['class']    = array( 'form-row-last' );
		$b['billing_address_2']['label']    = __( 'Apartment', 'oc-theme' );
		$b['billing_address_2']['label_class'] = array();
		$b['billing_address_2']['required'] = false;
		$b['billing_address_2']['placeholder'] = '';

		$b['billing_address_2']['required'] = ! empty( $s['apt_required'] ) && ! $pickup;

		$b['billing_oc_floor'] = array(
			'type'     => 'text',
			'label'    => __( 'Floor', 'oc-theme' ),
			'required' => ! empty( $s['floor_required'] ) && ! $pickup,
			'priority' => 70,
			'class'    => array( 'form-row-first' ),
		);

		$b['billing_oc_entry'] = array(
			'type'     => 'text',
			'label'    => __( 'Entry code', 'oc-theme' ),
			'required' => ! empty( $s['entry_required'] ) && ! $pickup,
			'priority' => 75,
			'class'    => array( 'form-row-last' ),
		);

		$b['billing_postcode']['priority'] = 57;

		// The pickup state hides the whole address block client-side.
		foreach ( array( 'billing_country', 'billing_state', 'billing_city', 'billing_address_1', 'billing_address_2', 'billing_oc_floor', 'billing_oc_entry', 'billing_postcode' ) as $key ) {
			if ( isset( $b[ $key ] ) ) {
				$b[ $key ]['class'][] = 'oc-co-addr';
			}
		}

		// Country: hidden entirely when asked for, or left to Woo's own
		// single-country auto-hide in auto mode.
		if ( 'hide' === $s['country_mode'] ) {
			$b['billing_country']['class'][] = 'oc-co-hidden';
		}

		if ( $pickup ) {
			foreach ( array( 'billing_city', 'billing_address_1', 'billing_postcode', 'billing_state', 'billing_country' ) as $key ) {
				if ( isset( $b[ $key ] ) ) {
					$b[ $key ]['required'] = false;
				}
			}
		}

		// Order notes per setting.
		if ( empty( $s['notes'] ) && isset( $fields['order']['order_comments'] ) ) {
			unset( $fields['order']['order_comments'] );
		}

		// The shipping-address group is unused: one address set, recipient
		// details ride order meta instead.
		$fields['shipping'] = array();

		return $fields;
	}

	/**
	 * Israel: no postcode, ever. Everything else keeps Woo's per-country
	 * rules (state/zip appear for countries that use them).
	 *
	 * @param array $locale Country locale rules.
	 * @return array
	 */
	public function country_locale( array $locale ): array {
		// Woo's address-i18n re-sorts the address rows client-side from this
		// very locale data — so the layout order must live HERE, not only on
		// the server fields.
		$layout = array(
			'phone'     => array( 'required' => true, 'priority' => 40 ),
			'city'      => array(
				'priority' => 55,
				'label'    => __( 'City', 'oc-theme' ),
			),
			'address_1' => array(
				'priority' => 60,
				'label'    => __( 'Street and house number', 'oc-theme' ),
			),
			'address_2' => array(
				'priority' => 65,
				'label'    => __( 'Apartment', 'oc-theme' ),
			),
			'postcode'  => array( 'priority' => 57 ),
			'state'     => array( 'priority' => 52 ),
		);

		$locale['default'] = array_replace_recursive( (array) ( $locale['default'] ?? array() ), $layout );
		$locale['IL']      = array_replace_recursive( (array) ( $locale['IL'] ?? array() ), $layout );

		$locale['IL']['postcode']['required'] = false;
		$locale['IL']['postcode']['hidden']   = true;

		return $locale;
	}

	/**
	 * The pseudo-field: delivery-method cards + the "sending to someone
	 * else" toggle with its recipient block.
	 *
	 * @param string $html Empty incoming html.
	 * @param string $key  Field key.
	 * @return string
	 */
	public function shipping_section( $html, $key ): string {
		$s = self::settings();

		if ( ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return '';
		}

		ob_start();
		?>
		<div class="form-row oc-co-shipwrap" data-priority="45">
		<div class="oc-co-section oc-co-methods">
			<h3 class="oc-co-h"><?php esc_html_e( 'Delivery method', 'oc-theme' ); ?></h3>
			<?php echo $this->rates_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
		</div>

		<div class="oc-co-section oc-co-addrhead" data-oc-co-address-head>
			<h3 class="oc-co-h"><?php esc_html_e( 'Delivery address', 'oc-theme' ); ?></h3>

			<?php if ( ! empty( $s['send_other'] ) ) : ?>
				<label class="oc-co-toggle">
					<input type="checkbox" name="oc_send_other" id="oc_send_other" value="1" />
					<span><?php esc_html_e( "I'm sending to someone else", 'oc-theme' ); ?></span>
				</label>

				<div class="oc-co-recipient" data-oc-co-recipient hidden>
					<p class="oc-co-recipient__intro"><?php esc_html_e( 'Recipient details', 'oc-theme' ); ?></p>
					<div class="oc-co-recipient__grid">
						<p class="oc-co-rrow oc-co-rrow--first validate-required">
							<label for="oc_recip_first"><?php esc_html_e( 'First name', 'oc-theme' ); ?></label>
							<input type="text" class="input-text" name="oc_recip_first" id="oc_recip_first" autocomplete="off" />
						</p>
						<p class="oc-co-rrow oc-co-rrow--last validate-required">
							<label for="oc_recip_last"><?php esc_html_e( 'Last name', 'oc-theme' ); ?></label>
							<input type="text" class="input-text" name="oc_recip_last" id="oc_recip_last" autocomplete="off" />
						</p>
						<p class="oc-co-rrow oc-co-rrow--first validate-required">
							<label for="oc_recip_phone"><?php esc_html_e( 'Phone', 'oc-theme' ); ?></label>
							<input type="tel" inputmode="tel" class="input-text" name="oc_recip_phone" id="oc_recip_phone" autocomplete="off" />
						</p>
						<p class="oc-co-rrow oc-co-rrow--last<?php echo ! empty( $s['phone2_required'] ) ? ' validate-required' : ''; ?>">
							<label for="oc_recip_phone2"><?php esc_html_e( 'Additional phone', 'oc-theme' ); ?></label>
							<input type="tel" inputmode="tel" class="input-text" name="oc_recip_phone2" id="oc_recip_phone2" autocomplete="off" />
						</p>
					</div>
				</div>
			<?php endif; ?>
		</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The method cards — also served as an update_order_review fragment, so
	 * a threshold crossing (free shipping appearing mid-checkout) re-renders
	 * them live.
	 */
	private function rates_html(): string {
		$packages = WC()->shipping()->get_packages();
		$chosen   = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();

		ob_start();
		?>
		<div class="oc-co-rates" data-oc-co-rates>
			<?php foreach ( $packages as $i => $package ) : ?>
				<?php
				$rates   = (array) ( $package['rates'] ?? array() );
				$current = (string) ( $chosen[ $i ] ?? '' );
				if ( ( '' === $current || ! isset( $rates[ $current ] ) ) && $rates ) {
					$first   = reset( $rates );
					$current = $first->get_id();
				}
				?>
				<?php foreach ( $rates as $rate ) : ?>
					<label class="oc-co-rate<?php echo $rate->get_id() === $current ? ' is-on' : ''; ?>">
						<input type="radio" name="oc_ship_pick[<?php echo absint( $i ); ?>]" value="<?php echo esc_attr( $rate->get_id() ); ?>" <?php checked( $rate->get_id(), $current ); ?> />
						<?php echo 'local_pickup' === $rate->get_method_id() ? '<svg class="oc-co-rate__icon" xmlns="http://www.w3.org/2000/svg" width="19" height="16" viewBox="0 0 18.197 15.729" aria-hidden="true"><path d="M70.769,133.684H53.2a.312.312,0,0,1-.271-.461l2.674-4.788a1.845,1.845,0,0,1,1.608-.935h9.55a1.845,1.845,0,0,1,1.608.935l2.674,4.788a.312.312,0,0,1-.271.461Zm-17.041-.623h16.52l-2.428-4.32a1.219,1.219,0,0,0-1.063-.623h-9.55a1.219,1.219,0,0,0-1.063.623Z" transform="translate(-52.883 -127.49)" fill="currentColor"/><path d="M411.221,133.131h-5.86a.31.31,0,0,1-.305-.368l1.054-5.57a.312.312,0,0,1,.312-.256h3.762a.312.312,0,0,1,.312.256l1.038,5.57a.31.31,0,0,1-.312.368Zm-5.483-.623h5.105l-.919-4.947h-3.267Z" transform="translate(-399.193 -126.937)" fill="currentColor"/><path d="M55.934,464.805a3.245,3.245,0,0,1-3.242-3.242.312.312,0,0,1,.312-.312H58.86a.312.312,0,0,1,.312.312,3.242,3.242,0,0,1-3.239,3.242Zm-2.6-2.93a2.615,2.615,0,0,0,5.194,0Z" transform="translate(-52.692 -455.694)" fill="currentColor"/><path d="M760.737,465.537a3.242,3.242,0,0,1-3.239-3.229.312.312,0,0,1,.312-.312h5.857a.312.312,0,0,1,.312.312,3.245,3.245,0,0,1-3.242,3.229Zm-2.6-2.93a2.615,2.615,0,0,0,5.2,0Z" transform="translate(-745.782 -456.426)" fill="currentColor"/><path d="M177.477,644.782h-13.9a.312.312,0,0,1-.312-.312v-6.617a.312.312,0,1,1,.623,0v6.305h13.272v-6.305a.312.312,0,1,1,.623,0v6.617a.312.312,0,0,1-.312.312Z" transform="translate(-161.432 -629.053)" fill="currentColor"/><path d="M388.572,752.011H383.66a1.163,1.163,0,0,1-1.16-1.153v-.82a1.163,1.163,0,0,1,1.16-1.163h4.912a1.163,1.163,0,0,1,1.16,1.163v.82a1.163,1.163,0,0,1-1.16,1.153ZM383.66,749.5a.536.536,0,0,0-.536.539v.82a.539.539,0,0,0,.536.53h4.912a.539.539,0,0,0,.536-.53v-.82a.536.536,0,0,0-.536-.539Z" transform="translate(-377.017 -738.536)" fill="currentColor"/></svg>' : '<svg class="oc-co-rate__icon" xmlns="http://www.w3.org/2000/svg" width="20" height="16" viewBox="0 0 19.78 15.729" aria-hidden="true"><path d="M869.642,396.84a.362.362,0,0,0-.362.362v3a.362.362,0,0,0,.362.362h2.273a.362.362,0,0,0,.362-.362v-.722a2.639,2.639,0,0,0-2.635-2.635Zm1.911,3H870V397.6a1.915,1.915,0,0,1,1.551,1.877Z" transform="translate(-854.342 -392.782)" fill="currentColor"/><path d="M91.734,190.254v-2.849a5.235,5.235,0,0,0-5.234-5.234H84.661a1.063,1.063,0,0,0-.317.049v-.495a1.49,1.49,0,0,0-1.488-1.488H73.444a1.49,1.49,0,0,0-1.488,1.488v11.165a1,1,0,0,0,.994.994h.668a2.444,2.444,0,0,0,4.834,0h6.7a2.444,2.444,0,0,0,4.834,0h.76a1,1,0,0,0,.994-.994v-2.527a.593.593,0,0,0-.007-.11Zm-7.388-7.044a.317.317,0,0,1,.317-.317H86.5a4.513,4.513,0,0,1,4.51,4.51v2a1.035,1.035,0,0,0-.265-.036h-6.4ZM72.68,181.726a.765.765,0,0,1,.764-.764h9.413a.765.765,0,0,1,.764.764v7.642H72.95a.993.993,0,0,0-.272.038v-7.68Zm3.357,13.519a1.72,1.72,0,1,1,1.72-1.72A1.721,1.721,0,0,1,76.037,195.245Zm11.531,0a1.72,1.72,0,1,1,1.72-1.72A1.721,1.721,0,0,1,87.568,195.245Zm3.449-2.354a.272.272,0,0,1-.272.272h-.76a2.444,2.444,0,0,0-4.834,0h-6.7a2.444,2.444,0,0,0-4.834,0h-.668a.272.272,0,0,1-.272-.272v-2.527a.272.272,0,0,1,.272-.272h17.8a.272.272,0,0,1,.265.214v1.927a.308.308,0,0,0,.007.065v.591Z" transform="translate(-71.956 -180.238)" fill="currentColor"/></svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static svg. ?>
						<span class="oc-co-rate__name"><?php echo esc_html( $rate->get_label() ); ?></span>
						<?php $cost = (float) $rate->get_cost() + array_sum( array_map( 'floatval', $rate->get_taxes() ) ); ?>
						<?php if ( $cost > 0 ) : ?>
							<span class="oc-co-rate__cost"><?php echo wp_kses_post( wc_price( $cost ) ); ?></span>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Fresh method cards on every review refresh.
	 *
	 * @param array $fragments Fragments.
	 * @return array
	 */
	public function rates_fragment( array $fragments ): array {
		$fragments['[data-oc-co-rates]'] = $this->rates_html();

		return $fragments;
	}

	/* -------------------------------------------------------------- extra */

	/**
	 * Shopify-style brand row inside the form column: cart at one end, the
	 * logo centred — the site header itself never renders here.
	 */
	public function brand_row(): void {
		$s = self::settings();

		echo '<div class="oc-co-brand"><div class="oc-co-brand__in">';

		echo '<a class="oc-co-brand__cart" href="' . esc_url( wc_get_cart_url() ) . '" aria-label="' . esc_attr__( 'Back to cart', 'oc-theme' ) . '">';
		echo oc_cart_icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		echo '</a>';

		echo '<div class="oc-co-brand__logo">';
		if ( has_custom_logo() ) {
			the_custom_logo();
		} else {
			echo '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>';
		}
		echo '</div>';

		echo '<span class="oc-co-brand__help">' . esc_html( trim( (string) $s['help_text'] ) ) . '</span>';

		echo '</div></div>';
	}

	/**
	 * Section heading above the contact fields.
	 */
	public function orderer_heading(): void {
		echo '<div class="oc-co-headrow">';
		echo '<h3 class="oc-co-h oc-co-h--first">' . esc_html__( 'Your details', 'oc-theme' ) . '</h3>';

		if ( ! is_user_logged_in() ) {
			echo '<button type="button" class="oc-co-login__t" data-oc-co-login-t>' . esc_html__( 'Log in to your account', 'oc-theme' ) . '</button>';
		}

		echo '</div>';

		if ( ! is_user_logged_in() ) {
			echo '<div class="oc-co-login" data-oc-co-login><div class="oc-co-login__body" hidden>';
			woocommerce_login_form( array( 'redirect' => wc_get_checkout_url() ) );
			echo '</div></div>';
		}
	}

	/**
	 * Coupon line in the summary: after the items, before the totals.
	 */
	public function summary_coupon_row(): void {
		$s = self::settings();

		if ( ! wc_coupons_enabled() || 'hide' === $s['coupon_mode'] ) {
			return;
		}

		$folded = 'button' === $s['coupon_mode'];
		?>
		<tr class="oc-co-couponrow">
			<td colspan="2">
				<?php if ( $folded ) : ?>
					<button type="button" class="oc-co-coupon__t" data-oc-co-coupon-t><?php esc_html_e( 'Have a coupon?', 'oc-theme' ); ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
				<?php endif; ?>
				<div class="oc-co-coupon" data-oc-co-coupon <?php echo $folded ? 'hidden' : ''; ?>>
					<input type="text" placeholder="<?php esc_attr_e( 'Coupon code', 'oc-theme' ); ?>" />
					<button type="button" aria-label="<?php esc_attr_e( 'Apply', 'oc-theme' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5L19.5 7"/></svg></button>
				</div>
				<p class="oc-co-coupon__msg" data-oc-co-coupon-msg hidden></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Our own quiet shipping row: chosen method at the start, its price (or
	 * "Free") at the price side. The native row — whose hidden radios fight
	 * the form's cards over the checked state — hides entirely.
	 */
	public function shipping_row(): void {
		$packages = WC()->shipping()->get_packages();
		$chosen   = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();

		foreach ( $packages as $i => $package ) {
			$rates   = (array) ( $package['rates'] ?? array() );
			$current = (string) ( $chosen[ $i ] ?? '' );

			if ( ( '' === $current || ! isset( $rates[ $current ] ) ) && $rates ) {
				$first   = reset( $rates );
				$current = $first->get_id();
			}

			if ( ! isset( $rates[ $current ] ) ) {
				continue;
			}

			$rate = $rates[ $current ];
			$cost = (float) $rate->get_cost() + array_sum( array_map( 'floatval', $rate->get_taxes() ) );

			echo '<tr class="oc-co-shiprow2"><th>' . esc_html__( 'Shipping', 'woocommerce' ) . '</th><td>';
			echo '<span class="oc-co-shiprow2__in"><span>' . esc_html( $rate->get_label() ) . '</span>';
			if ( $cost > 0 ) {
				echo '<strong>' . wp_kses_post( wc_price( $cost ) ) . '</strong>';
			} elseif ( 'free_shipping' !== $rate->get_method_id() ) {
				echo '<strong>' . esc_html__( 'Free', 'oc-theme' ) . '</strong>';
			}
			echo '</span>';
			echo '</td></tr>';
		}
	}

	/**
	 * The mini-cart's savings line: "you saved X" with the breakdown folded
	 * behind it, promos and coupons summed.
	 */
	public function savings_row(): void {
		$rows  = array();
		$saved = 0.0;

		if ( class_exists( '\\PromoEngine\\Cart' ) && method_exists( '\\PromoEngine\\Cart', 'instance' ) ) {
			$pcart   = \PromoEngine\Cart::instance();
			$summary = $pcart && method_exists( $pcart, 'savings_summary' ) ? $pcart->savings_summary() : null;
			if ( is_array( $summary ) ) {
				foreach ( (array) ( $summary['items'] ?? array() ) as $row ) {
					$saved += (float) $row['saved'];
					$rows[] = array( (string) $row['name'], (float) $row['saved'] );
				}
			}
		}

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$amount = (float) WC()->cart->get_coupon_discount_amount( $code, false );
			$saved += $amount;
			/* translators: %s: coupon code. */
			$rows[] = array( sprintf( __( 'Coupon %s', 'oc-theme' ), $code ), $amount, $code );
		}

		if ( $saved <= 0 ) {
			return;
		}

		foreach ( $rows as $row ) {
			$remove = isset( $row[2] )
				? ' <button type="button" class="oc-co-disc__x" data-oc-co-coupon-x data-code="' . esc_attr( $row[2] ) . '" aria-label="' . esc_attr__( 'Remove', 'oc-theme' ) . '">&times;</button>'
				: '';

			echo '<tr class="oc-co-disc"><th>' . esc_html( $row[0] ) . $remove . '</th><td>&minus;' . wp_kses_post( wc_price( $row[1] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}

	/**
	 * Quiet VAT note under the total.
	 */
	public function vat_note_row(): void {
		echo '<tr class="oc-co-vat"><td colspan="2">' . esc_html__( 'Including VAT', 'oc-theme' ) . '</td></tr>';
	}

	/**
	 * Section heading above the gateway list.
	 */
	public function payment_heading(): void {
		echo '<h3 class="oc-co-h oc-co-h--pay">' . esc_html__( 'Payment method', 'oc-theme' ) . '</h3>';
	}

	/**
	 * Short privacy note with the policy link, above the button.
	 */
	public function privacy_note(): void {
		$url = get_privacy_policy_url();

		$link = $url
			? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'oc-theme' ) . '</a>'
			: esc_html__( 'privacy policy', 'oc-theme' );

		echo '<p class="form-row oc-co-privacy validate-required">';
		echo '<label class="woocommerce-form__label-for-checkbox checkbox">';
		echo '<input type="checkbox" class="woocommerce-form__input-checkbox" name="oc_privacy_consent" value="1" /> ';
		echo '<span>' . sprintf(
			/* translators: %s: privacy policy link. */
			esc_html__( 'The details you provide will be used to process and deliver your order, for billing and customer service, in line with our %s. Providing them is not required by law, but without them the order cannot be completed.', 'oc-theme' ),
			$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		) . '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'woocommerce' ) . '">*</abbr></span>';
		echo '</label></p>';
	}

	/**
	 * Prominent login block for guests, above the form.
	 */
	public function login_block(): void {
		// The trigger lives in the section heading now (orderer_heading).
	}

	/**
	 * Marketing-consent checkbox above the place-order button.
	 */
	public function consent_checkbox(): void {
		$s = self::settings();

		if ( empty( $s['consent'] ) ) {
			return;
		}

		$label = '' !== trim( (string) $s['consent_text'] )
			? (string) $s['consent_text']
			: __( 'I agree to receive updates and offers by email and SMS', 'oc-theme' );
		?>
		<p class="form-row oc-co-consent">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="oc_marketing_consent" value="1" />
				<span><?php echo esc_html( $label ); ?></span>
			</label>
		</p>
		<?php
	}

	/**
	 * Place-order button label override.
	 *
	 * @param string $text Woo's label.
	 * @return string
	 */
	public function button_text( $text ): string {
		$s = self::settings();

		return '' !== trim( (string) $s['btn_text'] ) ? (string) $s['btn_text'] : (string) $text;
	}

	/* --------------------------------------------------------- validation */

	/**
	 * Phone-length rules and recipient requirements.
	 *
	 * @param array     $data   Posted checkout data.
	 * @param \WP_Error $errors Error bag.
	 */
	public function validate( $data, $errors ): void {
		$s = self::settings();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- inside Woo's own checkout submit.
		if ( empty( $_POST['oc_privacy_consent'] ) ) {
			$errors->add( 'oc_privacy', __( 'Please confirm the privacy policy notice.', 'oc-theme' ) );
		}

		$phone = (string) ( $data['billing_phone'] ?? '' );
		if ( '' !== $phone && ! $this->phone_ok( $phone ) ) {
			$errors->add( 'oc_phone', $this->phone_error( __( 'Phone', 'oc-theme' ) ) );
		}

		if ( ! $this->sending_to_other() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- inside Woo's own checkout submit.
		$first  = sanitize_text_field( wp_unslash( $_POST['oc_recip_first'] ?? '' ) );
		$last   = sanitize_text_field( wp_unslash( $_POST['oc_recip_last'] ?? '' ) );
		$rphone = sanitize_text_field( wp_unslash( $_POST['oc_recip_phone'] ?? '' ) );
		$phone2 = sanitize_text_field( wp_unslash( $_POST['oc_recip_phone2'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $first || '' === $last || '' === $rphone ) {
			$errors->add( 'oc_recipient', __( 'Please fill in the recipient details.', 'oc-theme' ) );
		}

		if ( '' !== $rphone && ! $this->phone_ok( $rphone ) ) {
			$errors->add( 'oc_recip_phone', $this->phone_error( __( "Recipient's phone", 'oc-theme' ) ) );
		}

		if ( ! empty( $s['phone2_required'] ) && '' === $phone2 ) {
			$errors->add( 'oc_recip_phone2', __( "Please fill in the recipient's additional phone.", 'oc-theme' ) );
		} elseif ( '' !== $phone2 && ! $this->phone_ok( $phone2 ) ) {
			$errors->add( 'oc_recip_phone2', $this->phone_error( __( "Recipient's additional phone", 'oc-theme' ) ) );
		}
	}

	/**
	 * Human phone-rule message, range-aware.
	 */
	private function phone_error( string $field ): string {
		$s   = self::settings();
		$min = absint( $s['phone_min'] );
		$max = absint( $s['phone_max'] );

		if ( $min && $max && $min !== $max ) {
			/* translators: 1: field name, 2: min digits, 3: max digits. */
			return sprintf( __( '%1$s must have %2$d–%3$d digits.', 'oc-theme' ), $field, $min, $max );
		}
		if ( $min && $min === $max ) {
			/* translators: 1: field name, 2: digits. */
			return sprintf( __( '%1$s must have %2$d digits.', 'oc-theme' ), $field, $min );
		}
		if ( $min ) {
			/* translators: 1: field name, 2: min digits. */
			return sprintf( __( '%1$s must have at least %2$d digits.', 'oc-theme' ), $field, $min );
		}
		/* translators: 1: field name, 2: max digits. */
		return sprintf( __( '%1$s must have at most %2$d digits.', 'oc-theme' ), $field, $max );
	}

	/* --------------------------------------------------------- order meta */

	/**
	 * Everything custom rides order meta.
	 *
	 * @param int   $order_id Order.
	 * @param array $data     Posted data.
	 */
	public function save_meta( $order_id, $data ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- inside Woo's own checkout submit.
		$map = array(
			'billing_oc_floor' => '_oc_floor',
			'billing_oc_entry' => '_oc_entry',
		);

		foreach ( $map as $posted => $meta ) {
			$value = sanitize_text_field( wp_unslash( $_POST[ $posted ] ?? '' ) );
			if ( '' !== $value ) {
				$order->update_meta_data( $meta, $value );
			}
		}

		if ( $this->sending_to_other() ) {
			$order->update_meta_data( '_oc_recipient_first', sanitize_text_field( wp_unslash( $_POST['oc_recip_first'] ?? '' ) ) );
			$order->update_meta_data( '_oc_recipient_last', sanitize_text_field( wp_unslash( $_POST['oc_recip_last'] ?? '' ) ) );
			$order->update_meta_data( '_oc_recipient_phone', sanitize_text_field( wp_unslash( $_POST['oc_recip_phone'] ?? '' ) ) );
			$order->update_meta_data( '_oc_recipient_phone2', sanitize_text_field( wp_unslash( $_POST['oc_recip_phone2'] ?? '' ) ) );
		}

		if ( ! empty( $_POST['oc_marketing_consent'] ) ) {
			$order->update_meta_data( '_oc_marketing_consent', 'yes' );

			$user_id = $order->get_customer_id();
			if ( $user_id ) {
				update_user_meta( $user_id, 'oc_marketing_consent', 'yes' );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$order->save();
	}

	/**
	 * Recipient + address extras in the admin order screen.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function admin_meta( $order ): void {
		$rows = $this->meta_rows( $order );

		if ( ! $rows ) {
			return;
		}

		echo '<div class="oc-admin-ometa"><h3>' . esc_html__( 'Delivery details', 'oc-theme' ) . '</h3><p>';
		foreach ( $rows as $label => $value ) {
			echo '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '<br />';
		}
		echo '</p></div>';
	}

	/**
	 * Same rows inside order emails.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Admin copy?.
	 * @param bool      $plain_text    Plain text mail?.
	 */
	public function email_meta( $order, $sent_to_admin, $plain_text ): void {
		$rows = $this->meta_rows( $order );

		if ( ! $rows ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Delivery details', 'oc-theme' ) . "\n";
			foreach ( $rows as $label => $value ) {
				echo esc_html( $label . ': ' . $value ) . "\n";
			}
			return;
		}

		echo '<h2>' . esc_html__( 'Delivery details', 'oc-theme' ) . '</h2><p>';
		foreach ( $rows as $label => $value ) {
			echo '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '<br />';
		}
		echo '</p>';
	}

	/**
	 * Label → value rows of our custom order meta.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,string>
	 */
	private function meta_rows( $order ): array {
		$rows = array();

		$first = (string) $order->get_meta( '_oc_recipient_first' );
		if ( '' !== $first ) {
			$rows[ __( 'Recipient', 'oc-theme' ) ] = trim( $first . ' ' . $order->get_meta( '_oc_recipient_last' ) );

			$phone = (string) $order->get_meta( '_oc_recipient_phone' );
			if ( '' !== $phone ) {
				$rows[ __( "Recipient's phone", 'oc-theme' ) ] = $phone;
			}

			$phone2 = (string) $order->get_meta( '_oc_recipient_phone2' );
			if ( '' !== $phone2 ) {
				$rows[ __( "Recipient's additional phone", 'oc-theme' ) ] = $phone2;
			}
		}

		$floor = (string) $order->get_meta( '_oc_floor' );
		if ( '' !== $floor ) {
			$rows[ __( 'Floor', 'oc-theme' ) ] = $floor;
		}

		$entry = (string) $order->get_meta( '_oc_entry' );
		if ( '' !== $entry ) {
			$rows[ __( 'Entry code', 'oc-theme' ) ] = $entry;
		}

		if ( 'yes' === $order->get_meta( '_oc_marketing_consent' ) ) {
			$rows[ __( 'Marketing consent', 'oc-theme' ) ] = __( 'Yes', 'oc-theme' );
		}

		return $rows;
	}

	/**
	 * Summary rows carry the product image (checkout only).
	 *
	 * @param string $name      Item name html.
	 * @param array  $cart_item Cart item.
	 * @param string $key       Cart item key.
	 * @return string
	 */
	public function review_item_name( $name, $cart_item, $key = '' ): string {
		if ( ! is_checkout() || is_cart() || ! isset( $cart_item['data'] ) ) {
			return (string) $name;
		}

		$product = $cart_item['data'];
		if ( ! $product instanceof \WC_Product ) {
			return (string) $name;
		}

		$image = wp_get_attachment_image( (int) $product->get_image_id(), 'woocommerce_gallery_thumbnail', false, array( 'class' => 'oc-co-item__img' ) );

		// Chosen attributes exactly like the mini-cart rows (swatch dot,
		// label: bold value); Woo's own dl.variation hides in CSS.
		$attrs = is_array( $cart_item ) ? Cart::item_attributes_html( $cart_item ) : '';

		return '<span class="oc-co-item">' . $image
			. '<span class="oc-co-item__body"><span class="oc-co-item__name">' . $name . '</span>'
			. ( '' !== $attrs ? '<span class="oc-co-item__attrs">' . $attrs . '</span>' : '' )
			. '</span></span>';
	}

	/**
	 * Quantity stepper on the summary rows — same ajax the mini-cart uses.
	 *
	 * @param string $html      Woo's "× 2" html.
	 * @param array  $cart_item Cart item.
	 * @param string $key       Cart item key.
	 * @return string
	 */
	public function review_item_qty( $html, $cart_item, $key ): string {
		$qty = (int) ( $cart_item['quantity'] ?? 1 );

		$trash = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>';

		// The mini-cart's anatomy exactly: LTR box, minus (or trash) at the
		// left, plus at the right.
		return '<span class="oc-co-qty" data-oc-co-qty data-key="' . esc_attr( $key ) . '">'
			. '<button type="button" class="oc-co-qty__b" data-d="-1" aria-label="' . esc_attr__( 'Decrease', 'oc-theme' ) . '">' . ( 1 === $qty ? $trash : '&minus;' ) . '</button>'
			. '<span class="oc-co-qty__n">' . absint( $qty ) . '</span>'
			. '<button type="button" class="oc-co-qty__b" data-d="1" aria-label="' . esc_attr__( 'Increase', 'oc-theme' ) . '">+</button>'
			. '</span>';
	}

	/* -------------------------------------------------------------- shell */

	/**
	 * Checkout body class (thank-you keeps the full site chrome).
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public function body_class( array $classes ): array {
		if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			$classes[] = 'oc-checkout';
		}

		return $classes;
	}

	/**
	 * Slim legal footer: terms + privacy, nothing else.
	 */
	public function legal_footer(): void {
		if ( ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$links = array();

		$terms_id = wc_terms_and_conditions_page_id();
		if ( $terms_id ) {
			$links[] = '<a href="' . esc_url( (string) get_permalink( $terms_id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms & conditions', 'oc-theme' ) . '</a>';
		}

		$privacy = get_privacy_policy_url();
		if ( $privacy ) {
			$links[] = '<a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy policy', 'oc-theme' ) . '</a>';
		}

		if ( ! $links ) {
			return;
		}

		echo '<div class="oc-co-legal">' . wp_kses_post( implode( '<span aria-hidden="true"> · </span>', $links ) ) . '</div>';
	}

	/**
	 * Help line riding the minimal header.
	 */
	public function help_line(): void {
		if ( ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$s    = self::settings();
		$text = trim( (string) $s['help_text'] );

		if ( '' === $text ) {
			return;
		}

		echo '<div class="oc-co-help">' . esc_html( $text ) . '</div>';
	}

	/* -------------------------------------------------------------- admin */

	/**
	 * Submenu under Theme settings.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			__( 'Checkout', 'oc-theme' ),
			__( 'Checkout', 'oc-theme' ),
			'manage_woocommerce',
			'oc-checkout',
			array( $this, 'admin_screen' )
		);
	}

	/**
	 * The settings screen.
	 */
	public function admin_screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['oc_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}

		$s = self::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Checkout', 'oc-theme' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_checkout_save" />
				<?php wp_nonce_field( 'oc_checkout_save' ); ?>

				<h2><?php esc_html_e( 'Layout', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Order summary', 'oc-theme' ); ?></th>
						<td><label><input type="checkbox" name="summary" value="1" <?php checked( 1, (int) $s['summary'] ); ?> /> <?php esc_html_e( 'Show the product list (totals always show)', 'oc-theme' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Coupon field', 'oc-theme' ); ?></th>
						<td>
							<select name="coupon_mode">
								<option value="open" <?php selected( 'open', $s['coupon_mode'] ); ?>><?php esc_html_e( 'Open field', 'oc-theme' ); ?></option>
								<option value="button" <?php selected( 'button', $s['coupon_mode'] ); ?>><?php esc_html_e( '"Have a coupon?" opens the field', 'oc-theme' ); ?></option>
								<option value="hide" <?php selected( 'hide', $s['coupon_mode'] ); ?>><?php esc_html_e( 'Hidden', 'oc-theme' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Country field', 'oc-theme' ); ?></th>
						<td>
							<select name="country_mode">
								<option value="auto" <?php selected( 'auto', $s['country_mode'] ); ?>><?php esc_html_e( 'Automatic — hidden when the store ships to one country', 'oc-theme' ); ?></option>
								<option value="hide" <?php selected( 'hide', $s['country_mode'] ); ?>><?php esc_html_e( 'Always hidden', 'oc-theme' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Help line in the header', 'oc-theme' ); ?></th>
						<td><input type="text" name="help_text" value="<?php echo esc_attr( (string) $s['help_text'] ); ?>" placeholder="<?php esc_attr_e( 'Need help? Call us — 077-0000000', 'oc-theme' ); ?>" class="regular-text" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Sending to someone else', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Toggle', 'oc-theme' ); ?></th>
						<td><label><input type="checkbox" name="send_other" value="1" <?php checked( 1, (int) $s['send_other'] ); ?> /> <?php esc_html_e( 'Show "I\'m sending to someone else"', 'oc-theme' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( "Recipient's additional phone", 'oc-theme' ); ?></th>
						<td><label><input type="checkbox" name="phone2_required" value="1" <?php checked( 1, (int) $s['phone2_required'] ); ?> /> <?php esc_html_e( 'Required', 'oc-theme' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Address fields', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Required fields', 'oc-theme' ); ?></th>
						<td>
							<label><input type="checkbox" name="apt_required" value="1" <?php checked( 1, (int) $s['apt_required'] ); ?> /> <?php esc_html_e( 'Apartment', 'oc-theme' ); ?></label><br />
							<label><input type="checkbox" name="floor_required" value="1" <?php checked( 1, (int) $s['floor_required'] ); ?> /> <?php esc_html_e( 'Floor', 'oc-theme' ); ?></label><br />
							<label><input type="checkbox" name="entry_required" value="1" <?php checked( 1, (int) $s['entry_required'] ); ?> /> <?php esc_html_e( 'Entry code', 'oc-theme' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Phone validation', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Digits', 'oc-theme' ); ?></th>
						<td>
							<label><?php esc_html_e( 'From', 'oc-theme' ); ?> <input type="number" name="phone_min" value="<?php echo esc_attr( (string) $s['phone_min'] ); ?>" min="0" max="20" style="width:70px;" /></label>
							<label style="margin-inline-start:12px;"><?php esc_html_e( 'To', 'oc-theme' ); ?> <input type="number" name="phone_max" value="<?php echo esc_attr( (string) $s['phone_max'] ); ?>" min="0" max="20" style="width:70px;" /></label>
							<p class="description"><?php esc_html_e( '0 = no limit.', 'oc-theme' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Fields & extras', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Order notes', 'oc-theme' ); ?></th>
						<td><label><input type="checkbox" name="notes" value="1" <?php checked( 1, (int) $s['notes'] ); ?> /> <?php esc_html_e( 'Show', 'oc-theme' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Marketing consent', 'oc-theme' ); ?></th>
						<td>
							<label><input type="checkbox" name="consent" value="1" <?php checked( 1, (int) $s['consent'] ); ?> /> <?php esc_html_e( 'Show the consent checkbox', 'oc-theme' ); ?></label>
							<p style="margin:10px 0 0;"><input type="text" name="consent_text" value="<?php echo esc_attr( (string) $s['consent_text'] ); ?>" placeholder="<?php esc_attr_e( 'I agree to receive updates and offers by email and SMS', 'oc-theme' ); ?>" class="large-text" /></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Place-order button', 'oc-theme' ); ?></th>
						<td>
							<label><input type="checkbox" name="btn_total" value="1" <?php checked( 1, (int) $s['btn_total'] ); ?> /> <?php esc_html_e( 'Show the total on the button', 'oc-theme' ); ?></label>
							<p style="margin:10px 0 0;"><input type="text" name="btn_text" value="<?php echo esc_attr( (string) $s['btn_text'] ); ?>" placeholder="<?php esc_attr_e( 'Place order', 'oc-theme' ); ?>" class="regular-text" /></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'oc-theme' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist.
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_checkout_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$s = array(
			'summary'         => empty( $_POST['summary'] ) ? 0 : 1,
			'coupon_mode'     => in_array( $_POST['coupon_mode'] ?? 'open', array( 'open', 'button', 'hide' ), true ) ? sanitize_key( $_POST['coupon_mode'] ?? 'open' ) : 'open',
			'country_mode'    => 'hide' === ( $_POST['country_mode'] ?? 'auto' ) ? 'hide' : 'auto',
			'send_other'      => empty( $_POST['send_other'] ) ? 0 : 1,
			'phone2_required' => empty( $_POST['phone2_required'] ) ? 0 : 1,
			'apt_required'    => empty( $_POST['apt_required'] ) ? 0 : 1,
			'floor_required'  => empty( $_POST['floor_required'] ) ? 0 : 1,
			'entry_required'  => empty( $_POST['entry_required'] ) ? 0 : 1,
			'phone_min'       => absint( $_POST['phone_min'] ?? 9 ),
			'phone_max'       => absint( $_POST['phone_max'] ?? 10 ),
			'btn_total'       => empty( $_POST['btn_total'] ) ? 0 : 1,
			'btn_text'        => sanitize_text_field( wp_unslash( $_POST['btn_text'] ?? '' ) ),
			'notes'           => empty( $_POST['notes'] ) ? 0 : 1,
			'consent'         => empty( $_POST['consent'] ) ? 0 : 1,
			'consent_text'    => sanitize_text_field( wp_unslash( $_POST['consent_text'] ?? '' ) ),
			'help_text'       => sanitize_text_field( wp_unslash( $_POST['help_text'] ?? '' ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( 'oc_checkout', $s );

		wp_safe_redirect( add_query_arg( 'oc_saved', '1', admin_url( 'admin.php?page=oc-checkout' ) ) );
		exit;
	}
}
