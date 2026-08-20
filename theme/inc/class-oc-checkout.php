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
				'country_mode'    => 'auto',  // auto | hide.
				'send_other'      => 1,       // "I'm sending to someone else" toggle.
				'phone2_required' => 0,       // Recipient's second phone required.
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
		add_filter( 'woocommerce_get_country_locale', array( $this, 'country_locale' ), 20 );
		add_filter( 'woocommerce_form_field_oc_co_shipping', array( $this, 'shipping_section' ), 10, 2 );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		// Our login block replaces the quiet default notice.
		add_action(
			'init',
			static function (): void {
				remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
			},
			20
		);
		add_action( 'woocommerce_before_checkout_form', array( $this, 'login_block' ), 5 );

		add_action( 'woocommerce_review_order_before_submit', array( $this, 'consent_checkbox' ), 15 );
		add_filter( 'woocommerce_order_button_text', array( $this, 'button_text' ) );

		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_meta' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'admin_meta' ) );
		add_action( 'woocommerce_email_order_meta', array( $this, 'email_meta' ), 10, 3 );

		add_action( 'wp_footer', array( $this, 'legal_footer' ) );
		add_action( 'wp_body_open', array( $this, 'help_line' ) );

		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_oc_checkout_save', array( $this, 'save_settings' ) );
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
		$b['billing_city']['class']    = array( 'form-row-wide' );
		$b['billing_city']['label']    = __( 'City', 'oc-theme' );

		$b['billing_address_1']['priority']    = 60;
		$b['billing_address_1']['class']       = array( 'form-row-first' );
		$b['billing_address_1']['label']       = __( 'Street and house number', 'oc-theme' );
		$b['billing_address_1']['placeholder'] = '';

		$b['billing_address_2']['priority'] = 65;
		$b['billing_address_2']['class']    = array( 'form-row-last' );
		$b['billing_address_2']['label']    = __( 'Apartment', 'oc-theme' );
		$b['billing_address_2']['label_class'] = array();
		$b['billing_address_2']['required'] = false;
		$b['billing_address_2']['placeholder'] = '';

		$b['billing_oc_floor'] = array(
			'type'     => 'text',
			'label'    => __( 'Floor (optional)', 'oc-theme' ),
			'required' => false,
			'priority' => 70,
			'class'    => array( 'form-row-first' ),
		);

		$b['billing_oc_entry'] = array(
			'type'     => 'text',
			'label'    => __( 'Entry code (optional)', 'oc-theme' ),
			'required' => false,
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

		$packages = WC()->shipping()->get_packages();
		$chosen   = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();

		ob_start();
		?>
		<div class="form-row oc-co-shipwrap" data-priority="45">
		<div class="oc-co-section oc-co-methods">
			<h3 class="oc-co-h"><?php esc_html_e( 'Delivery method', 'oc-theme' ); ?></h3>
			<?php foreach ( $packages as $i => $package ) : ?>
				<?php
				$rates   = (array) ( $package['rates'] ?? array() );
				$current = (string) ( $chosen[ $i ] ?? '' );
				if ( '' === $current && $rates ) {
					$first   = reset( $rates );
					$current = $first->get_id();
				}
				?>
				<div class="oc-co-rates" data-oc-co-rates>
					<?php foreach ( $rates as $rate ) : ?>
						<label class="oc-co-rate<?php echo $rate->get_id() === $current ? ' is-on' : ''; ?>">
							<input type="radio" name="shipping_method[<?php echo absint( $i ); ?>]" value="<?php echo esc_attr( $rate->get_id() ); ?>" class="shipping_method" <?php checked( $rate->get_id(), $current ); ?> />
							<span class="oc-co-rate__name"><?php echo esc_html( $rate->get_label() ); ?></span>
							<span class="oc-co-rate__cost">
								<?php
								$cost = (float) $rate->get_cost() + array_sum( array_map( 'floatval', $rate->get_taxes() ) );
								echo $cost > 0 ? wp_kses_post( wc_price( $cost ) ) : esc_html__( 'Free', 'oc-theme' );
								?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
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
							<label for="oc_recip_phone2"><?php echo esc_html( ! empty( $s['phone2_required'] ) ? __( 'Additional phone', 'oc-theme' ) : __( 'Additional phone (optional)', 'oc-theme' ) ); ?></label>
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

	/* -------------------------------------------------------------- extra */

	/**
	 * Prominent login block for guests, above the form.
	 */
	public function login_block(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		?>
		<div class="oc-co-login" data-oc-co-login>
			<div class="oc-co-login__bar">
				<span><?php esc_html_e( 'Already have an account?', 'oc-theme' ); ?></span>
				<button type="button" class="oc-co-login__t" data-oc-co-login-t>
					<?php esc_html_e( 'Log in — your details will fill in automatically', 'oc-theme' ); ?>
				</button>
			</div>
			<div class="oc-co-login__body" hidden>
				<?php woocommerce_login_form( array( 'redirect' => wc_get_checkout_url() ) ); ?>
			</div>
		</div>
		<?php
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
			'country_mode'    => 'hide' === ( $_POST['country_mode'] ?? 'auto' ) ? 'hide' : 'auto',
			'send_other'      => empty( $_POST['send_other'] ) ? 0 : 1,
			'phone2_required' => empty( $_POST['phone2_required'] ) ? 0 : 1,
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
