<?php
/**
 * Thank-you page: a warm, useful order-received screen.
 *
 * Same rule as the checkout (DECISIONS.md #7): no template overrides. The
 * native thankyou endpoint keeps running — gateway hooks included — and this
 * class hides Woo's dry defaults and renders its own sections through
 * woocommerce_before_thankyou: animated check, editable content, order
 * summary, and the optional social / survey / referral blocks.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Order-received page content, settings and the referral engine.
 */
final class Thankyou {

	/**
	 * Settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$saved = get_option( 'oc_thankyou' );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'content'          => '',   // Editor HTML; empty = the default service block.
				'summary'          => 1,    // Order summary with images.
				'social'           => 0,
				'social_title'     => '',
				'social_instagram' => '',
				'social_facebook'  => '',
				'social_tiktok'    => '',
				'social_whatsapp'  => '',
				'social_youtube'   => '',
				'survey'           => 0,
				'survey_q'         => '',
				'referral'         => 0,
				'ref_friend_pct'   => 10,   // The friend's discount.
				'ref_reward_pct'   => 10,   // The referrer's reward coupon.
				'ref_days'         => 30,   // Both coupons' lifetime.
			)
		);
	}

	/**
	 * The default editable content — the service block.
	 */
	public static function default_content(): string {
		return '<p>' . __( 'Our customer service team is here for you with any question:', 'oc-theme' ) . '</p>'
			. '<p>' . __( 'Phone', 'oc-theme' ) . ' - {phone}<br />'
			. __( 'Email', 'oc-theme' ) . ' - {email}</p>';
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'received_text' ), 20, 2 );
		add_action( 'woocommerce_before_thankyou', array( $this, 'render' ), 5 );

		// Woo's order table + addresses give way to our summary; gateway
		// instructions (woocommerce_thankyou_{method}) keep their spot.
		add_action(
			'wp',
			static function (): void {
				if ( is_wc_endpoint_url( 'order-received' ) ) {
					remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
				}
			}
		);

		add_action( 'wp_ajax_oc_ty_rate', array( $this, 'ajax_rate' ) );
		add_action( 'wp_ajax_nopriv_oc_ty_rate', array( $this, 'ajax_rate' ) );

		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_reward_referrer' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_reward_referrer' ) );

		add_action( 'admin_menu', array( $this, 'menu' ), 61 );
		add_action( 'admin_post_oc_thankyou_save', array( $this, 'save_settings' ) );
	}

	/**
	 * The order being viewed, verified against the key in the URL.
	 *
	 * @return \WC_Order|null
	 */
	private function current_order(): ?\WC_Order {
		$order_id = absint( get_query_var( 'order-received' ) );

		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Woo's own endpoint key.

		if ( ! $order || ! hash_equals( $order->get_order_key(), (string) $key ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Mark the page for CSS.
	 *
	 * @param array $classes Body classes.
	 */
	public function body_class( array $classes ): array {
		if ( is_wc_endpoint_url( 'order-received' ) ) {
			$order = $this->current_order();

			if ( $order && ! $order->has_status( 'failed' ) ) {
				$classes[] = 'oc-ty-page';
			}
		}

		return $classes;
	}

	/**
	 * Our hero replaces the default one-liner.
	 *
	 * @param string         $text  Default text.
	 * @param \WC_Order|null $order Order.
	 */
	public function received_text( $text, $order ): string {
		if ( $order && ! $order->has_status( 'failed' ) ) {
			return '';
		}

		return (string) $text;
	}

	/**
	 * The whole page, rendered above Woo's (now empty) defaults.
	 *
	 * @param int $order_id Order id.
	 */
	public function render( $order_id ): void {
		$order = $this->current_order();

		if ( ! $order || $order->has_status( 'failed' ) || (int) $order->get_id() !== (int) $order_id ) {
			return;
		}

		$s = self::settings();

		echo '<div class="oc-ty">';

		$this->hero( $order );
		$this->content_block( $order, $s );

		if ( ! empty( $s['summary'] ) ) {
			$this->summary( $order );
		}

		if ( ! empty( $s['referral'] ) ) {
			$this->referral_block( $order, $s );
		}

		if ( ! empty( $s['survey'] ) ) {
			$this->survey_block( $order, $s );
		}

		if ( ! empty( $s['social'] ) ) {
			$this->social_block( $s );
		}

		echo '</div>';
	}

	/**
	 * Animated check, greeting, order number.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function hero( \WC_Order $order ): void {
		$first = trim( $order->get_billing_first_name() );

		echo '<div class="oc-ty__hero">';
		echo '<span class="oc-ty__check" aria-hidden="true"><svg viewBox="0 0 64 64"><circle class="oc-ty__ring" cx="32" cy="32" r="29" /><path class="oc-ty__tick" d="M20 33.5 28.5 42 45 24" /></svg></span>';

		if ( $first ) {
			/* translators: %s: customer first name. */
			echo '<h1 class="oc-ty__title">' . esc_html( sprintf( __( 'Thank you %s', 'oc-theme' ), $first ) ) . '</h1>';
		} else {
			echo '<h1 class="oc-ty__title">' . esc_html__( 'Thank you!', 'oc-theme' ) . '</h1>';
		}

		echo '<p class="oc-ty__sub">' . esc_html__( 'Your order has been received and will be handled shortly.', 'oc-theme' ) . '</p>';
		echo '<p class="oc-ty__num">' . esc_html__( 'Your order number is:', 'oc-theme' ) . ' <b>' . esc_html( $order->get_order_number() ) . '</b></p>';
		echo '<p class="oc-ty__mailnote">' . esc_html__( 'A confirmation with the order details was sent to your email.', 'oc-theme' ) . '</p>';
		echo '</div>';
	}

	/**
	 * The editable content, placeholders filled.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string,mixed>  $s     Settings.
	 */
	private function content_block( \WC_Order $order, array $s ): void {
		$html = trim( (string) $s['content'] );

		if ( '' === $html ) {
			$html = self::default_content();
		}

		$html = str_replace(
			array( '{first_name}', '{order_number}' ),
			array( esc_html( $order->get_billing_first_name() ), esc_html( $order->get_order_number() ) ),
			Contact::fill( $html )
		);

		echo '<div class="oc-ty__box oc-ty__content">' . do_shortcode( wpautop( wp_kses_post( $html ) ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses above.
	}

	/**
	 * Order summary: items with images, then totals.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function summary( \WC_Order $order ): void {
		echo '<div class="oc-ty__box oc-ty__sum">';
		echo '<h2 class="oc-ty__h">' . esc_html__( 'Order summary', 'oc-theme' ) . '</h2>';

		foreach ( $order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			$thumb   = $product ? $product->get_image( array( 68, 68 ) ) : '';
			$meta    = wc_display_item_meta( $item, array( 'echo' => false ) );

			echo '<div class="oc-ty__item">';
			echo '<span class="oc-ty__item-img">' . wp_kses_post( $thumb ) . '</span>';
			echo '<span class="oc-ty__item-body">';
			echo '<span class="oc-ty__item-name">' . esc_html( $item->get_name() ) . '</span>';

			if ( $meta ) {
				echo '<span class="oc-ty__item-meta">' . wp_kses_post( $meta ) . '</span>';
			}

			echo '<span class="oc-ty__item-qty">' . esc_html( sprintf( /* translators: %s: quantity. */ __( 'Qty: %s', 'oc-theme' ), $item->get_quantity() ) ) . '</span>';
			echo '</span>';
			echo '<span class="oc-ty__item-total">' . wp_kses_post( $order->get_formatted_line_subtotal( $item ) ) . '</span>';
			echo '</div>';
		}

		echo '<div class="oc-ty__totals">';

		foreach ( $order->get_order_item_totals() as $key => $row ) {
			if ( 'payment_method' === $key ) {
				continue;
			}

			echo '<div class="oc-ty__trow' . ( 'order_total' === $key ? ' oc-ty__trow--total' : '' ) . '">';
			echo '<span>' . esc_html( wp_strip_all_tags( (string) $row['label'] ) ) . '</span>';
			echo '<span>' . wp_kses_post( $row['value'] ) . '</span>';
			echo '</div>';
		}

		echo '</div></div>';
	}

	/**
	 * Referral: the shareable friend coupon.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $s     Settings.
	 */
	private function referral_block( \WC_Order $order, array $s ): void {
		$code = $this->friend_coupon( $order, $s );

		if ( ! $code ) {
			return;
		}

		$friend = (int) $s['ref_friend_pct'];
		$reward = (int) $s['ref_reward_pct'];

		$share_text = sprintf(
			/* translators: 1: discount percent, 2: coupon code, 3: shop url. */
			__( 'I have a gift for you: %1$s%% off at %3$s with the code %2$s', 'oc-theme' ),
			$friend,
			$code,
			home_url( '/' )
		);

		echo '<div class="oc-ty__box oc-ty__ref">';
		echo '<h2 class="oc-ty__h">' . esc_html__( 'A gift for a friend', 'oc-theme' ) . '</h2>';
		echo '<p class="oc-ty__ref-txt">' . esc_html(
			sprintf(
				/* translators: 1: friend discount percent, 2: reward percent. */
				__( 'Share this code with a friend — they get %1$s%% off their first order, and once they buy you get a %2$s%% coupon of your own.', 'oc-theme' ),
				$friend,
				$reward
			)
		) . '</p>';
		echo '<div class="oc-ty__code-row">';
		echo '<span class="oc-ty__code" data-oc-ty-code>' . esc_html( $code ) . '</span>';
		echo '<button type="button" class="oc-ty__copy" data-oc-ty-copy data-done="' . esc_attr__( 'Copied', 'oc-theme' ) . '">' . esc_html__( 'Copy', 'oc-theme' ) . '</button>';
		echo '</div>';
		echo '<a class="oc-ty__wa" target="_blank" rel="noopener" href="https://wa.me/?text=' . rawurlencode( $share_text ) . '">' . esc_html__( 'Share on WhatsApp', 'oc-theme' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Get or create this order's friend coupon.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $s     Settings.
	 */
	private function friend_coupon( \WC_Order $order, array $s ): string {
		$code = (string) $order->get_meta( '_oc_ty_ref_code' );

		if ( $code ) {
			return $code;
		}

		$email = strtolower( (string) $order->get_billing_email() );

		if ( ! $email ) {
			return '';
		}

		$code = 'GIFT-' . strtoupper( wp_generate_password( 6, false, false ) );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( (float) max( 1, (int) $s['ref_friend_pct'] ) );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );

		$days = max( 1, (int) $s['ref_days'] );
		$coupon->set_date_expires( time() + $days * DAY_IN_SECONDS );

		$coupon->update_meta_data( '_oc_ref_referrer_email', $email );
		$coupon->update_meta_data( '_oc_ref_source_order', (string) $order->get_id() );
		$coupon->save();

		$order->update_meta_data( '_oc_ty_ref_code', $code );
		$order->save();

		return $code;
	}

	/**
	 * A friend's order came through on a referral coupon — reward the referrer.
	 *
	 * @param int $order_id Order id.
	 */
	public function maybe_reward_referrer( $order_id ): void {
		$s = self::settings();

		if ( empty( $s['referral'] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_coupon_codes() as $used ) {
			$coupon = new \WC_Coupon( $used );
			$ref    = (string) $coupon->get_meta( '_oc_ref_referrer_email' );

			if ( ! $ref || $coupon->get_meta( '_oc_ref_rewarded' ) ) {
				continue;
			}

			// Using your own code on your own next order earns nothing.
			if ( strtolower( (string) $order->get_billing_email() ) === $ref ) {
				continue;
			}

			$days   = max( 1, (int) $s['ref_days'] );
			$reward = 'TNX-' . strtoupper( wp_generate_password( 6, false, false ) );

			$rc = new \WC_Coupon();
			$rc->set_code( $reward );
			$rc->set_discount_type( 'percent' );
			$rc->set_amount( (float) max( 1, (int) $s['ref_reward_pct'] ) );
			$rc->set_individual_use( true );
			$rc->set_usage_limit( 1 );
			$rc->set_email_restrictions( array( $ref ) );
			$rc->set_date_expires( time() + $days * DAY_IN_SECONDS );
			$rc->update_meta_data( '_oc_ref_reward_for', (string) $order->get_id() );
			$rc->save();

			$coupon->update_meta_data( '_oc_ref_rewarded', (string) $order->get_id() );
			$coupon->save();

			$this->reward_email( $ref, $reward, (int) $s['ref_reward_pct'], $days );
		}
	}

	/**
	 * Tell the referrer their coupon is waiting.
	 *
	 * @param string $to      Referrer email.
	 * @param string $code    Reward coupon code.
	 * @param int    $percent Reward percent.
	 * @param int    $days    Validity in days.
	 */
	private function reward_email( string $to, string $code, int $percent, int $days ): void {
		$mailer = WC()->mailer();

		/* translators: %s: discount percent. */
		$subject = sprintf( __( 'Your friend just ordered — here is your %s%% coupon', 'oc-theme' ), $percent );

		$body  = '<p>' . esc_html__( 'The friend you shared your code with has placed an order. As promised, a coupon of your own:', 'oc-theme' ) . '</p>';
		$body .= '<p style="font-size:22px;font-weight:700;letter-spacing:.08em;">' . esc_html( $code ) . '</p>';
		/* translators: %s: number of days. */
		$body .= '<p>' . esc_html( sprintf( __( 'Valid for %s days. See you soon!', 'oc-theme' ), $days ) ) . '</p>';

		$mailer->send( $to, $subject, $mailer->wrap_message( $subject, $body ), 'Content-Type: text/html; charset=UTF-8' );
	}

	/**
	 * Survey: five stars, one vote per order.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $s     Settings.
	 */
	private function survey_block( \WC_Order $order, array $s ): void {
		$q     = '' !== trim( (string) $s['survey_q'] ) ? (string) $s['survey_q'] : __( 'Rate your purchase experience', 'oc-theme' );
		$rated = (int) $order->get_meta( '_oc_ty_rating' );

		echo '<div class="oc-ty__box oc-ty__survey" data-oc-ty-survey data-order="' . esc_attr( (string) $order->get_id() ) . '" data-key="' . esc_attr( $order->get_order_key() ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"' . ( $rated ? ' data-rated="' . esc_attr( (string) $rated ) . '"' : '' ) . '>';
		echo '<h2 class="oc-ty__h">' . esc_html( $q ) . '</h2>';
		echo '<div class="oc-ty__stars" role="radiogroup" aria-label="' . esc_attr( $q ) . '">';

		for ( $i = 1; $i <= 5; $i++ ) {
			echo '<button type="button" class="oc-ty__star' . ( $rated && $i <= $rated ? ' is-on' : '' ) . '" data-star="' . esc_attr( (string) $i ) . '"' . ( $rated ? ' disabled' : '' ) . ' aria-label="' . esc_attr( (string) $i ) . '"><svg viewBox="0 0 24 24"><path d="M12 2.6 15 9l7 .7-5.3 4.7 1.6 6.9L12 17.6 5.7 21.3l1.6-6.9L2 9.7 9 9Z"/></svg></button>';
		}

		echo '</div>';
		echo '<p class="oc-ty__thanks"' . ( $rated ? '' : ' hidden' ) . '>' . esc_html__( 'Thanks for the feedback!', 'oc-theme' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Store a rating: verified by the order key, once per order.
	 */
	public function ajax_rate(): void {
		$order_id = absint( $_POST['order'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- order key verified below.
		$key      = wc_clean( wp_unslash( $_POST['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$rating   = absint( $_POST['rating'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order || ! hash_equals( $order->get_order_key(), (string) $key ) || $rating < 1 || $rating > 5 ) {
			wp_send_json_error();
		}

		if ( (int) $order->get_meta( '_oc_ty_rating' ) ) {
			wp_send_json_success(); // Already voted — silently fine.
		}

		$order->update_meta_data( '_oc_ty_rating', $rating );
		$order->save();

		$agg = get_option( 'oc_ty_survey' );
		$agg = is_array( $agg ) ? $agg : array(
			'count'  => 0,
			'sum'    => 0,
			'recent' => array(),
		);

		$agg['count']++;
		$agg['sum'] += $rating;
		array_unshift(
			$agg['recent'],
			array(
				'order'  => $order_id,
				'rating' => $rating,
				't'      => time(),
			)
		);
		$agg['recent'] = array_slice( $agg['recent'], 0, 20 );

		update_option( 'oc_ty_survey', $agg, false );

		wp_send_json_success();
	}

	/**
	 * Social follow buttons.
	 *
	 * @param array<string,mixed> $s Settings.
	 */
	private function social_block( array $s ): void {
		$nets = array(
			'instagram' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5.4" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17.2" cy="6.8" r="1.3" fill="currentColor"/></svg>',
			'facebook'  => '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M13.5 21v-7h2.4l.4-2.9h-2.8V9.2c0-.8.3-1.4 1.5-1.4h1.4V5.2c-.3 0-1.1-.1-2-.1-2 0-3.4 1.2-3.4 3.5v2.5H8.5V14H11v7Z"/></svg>',
			'tiktok'    => '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M16.6 3c.3 1.7 1.4 3 3.4 3.3v2.7c-1.3 0-2.5-.4-3.4-1v6.4c0 3.2-2.2 5.6-5.4 5.6A5.3 5.3 0 0 1 5.8 14.6c0-3 2.4-5.3 5.5-5.1v2.8c-1.5-.3-2.8.7-2.8 2.2 0 1.4 1 2.5 2.5 2.5s2.6-1.1 2.6-2.7V3Z"/></svg>',
			'whatsapp'  => '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Zm0 1.8a7.2 7.2 0 1 1-3.7 13.4l-.4-.2-2.5.7.7-2.5-.3-.4A7.2 7.2 0 0 1 12 4.8Zm-2.6 3.5c-.2 0-.5 0-.7.3-.2.3-.9.9-.9 2.1s.9 2.5 1 2.6c.1.2 1.8 2.8 4.4 3.8 2.1.9 2.6.7 3 .7.5 0 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2l-.5-.3-1.9-.9c-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a5.9 5.9 0 0 1-3-2.6c-.1-.2 0-.4.1-.5l.6-.7c.2-.2.2-.4.1-.6l-.8-2c-.2-.4-.4-.5-.6-.5Z"/></svg>',
			'youtube'   => '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M21.6 7.4a2.5 2.5 0 0 0-1.8-1.8C18.2 5.2 12 5.2 12 5.2s-6.2 0-7.8.4A2.5 2.5 0 0 0 2.4 7.4 26.5 26.5 0 0 0 2 12c0 1.6.1 3.1.4 4.6a2.5 2.5 0 0 0 1.8 1.8c1.6.4 7.8.4 7.8.4s6.2 0 7.8-.4a2.5 2.5 0 0 0 1.8-1.8c.3-1.5.4-3 .4-4.6s-.1-3.1-.4-4.6ZM10.2 15V9l5.2 3Z"/></svg>',
		);

		$links = array();

		foreach ( array_keys( $nets ) as $net ) {
			$url = trim( (string) $s[ 'social_' . $net ] );

			if ( $url ) {
				$links[ $net ] = $url;
			}
		}

		if ( ! $links ) {
			return;
		}

		$title = '' !== trim( (string) $s['social_title'] ) ? (string) $s['social_title'] : __( 'Follow us', 'oc-theme' );

		echo '<div class="oc-ty__box oc-ty__social">';
		echo '<h2 class="oc-ty__h">' . esc_html( $title ) . '</h2>';
		echo '<div class="oc-ty__soc-row">';

		foreach ( $links as $net => $url ) {
			echo '<a class="oc-ty__soc" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( $net ) . '">' . $nets[ $net ] . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static svg.
		}

		echo '</div></div>';
	}

	/* ---------------------------------------------------------------- admin */

	/**
	 * Submenu under theme settings.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			__( 'Thank-you page', 'oc-theme' ),
			__( 'Thank-you page', 'oc-theme' ),
			'manage_woocommerce',
			'oc-thankyou',
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

		$s       = self::settings();
		$content = '' !== trim( (string) $s['content'] ) ? (string) $s['content'] : self::default_content();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Thank-you page', 'oc-theme' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_thankyou_save" />
				<?php wp_nonce_field( 'oc_thankyou_save' ); ?>

				<h2><?php esc_html_e( 'Page content', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Shown under the greeting. Placeholders: {phone} {email} {first_name} {order_number} — phone and email come from Contact details.', 'oc-theme' ); ?></p>
				<?php
				wp_editor(
					$content,
					'oc_ty_content',
					array(
						'textarea_name' => 'content',
						'textarea_rows' => 8,
						'media_buttons' => true,
					)
				);
				?>

				<h2><?php esc_html_e( 'Order summary', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Order summary', 'oc-theme' ); ?></th>
						<td><label><input type="checkbox" name="summary" value="1" <?php checked( 1, (int) $s['summary'] ); ?> /> <?php esc_html_e( 'Show the products (with images) and totals', 'oc-theme' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Social buttons', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show', 'oc-theme' ); ?></th>
						<td>
							<label><input type="checkbox" name="social" value="1" <?php checked( 1, (int) $s['social'] ); ?> /> <?php esc_html_e( 'Invite customers to follow the store', 'oc-theme' ); ?></label>
							<p style="margin:10px 0 0;"><input type="text" name="social_title" value="<?php echo esc_attr( (string) $s['social_title'] ); ?>" placeholder="<?php esc_attr_e( 'Follow us', 'oc-theme' ); ?>" class="regular-text" /></p>
						</td>
					</tr>
					<?php
					foreach ( array(
						'social_instagram' => 'Instagram',
						'social_facebook'  => 'Facebook',
						'social_tiktok'    => 'TikTok',
						'social_whatsapp'  => 'WhatsApp',
						'social_youtube'   => 'YouTube',
					) as $field => $label ) :
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td><input type="url" name="<?php echo esc_attr( $field ); ?>" dir="ltr" value="<?php echo esc_attr( (string) $s[ $field ] ); ?>" class="regular-text" placeholder="https://" /></td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Purchase survey', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show', 'oc-theme' ); ?></th>
						<td>
							<label><input type="checkbox" name="survey" value="1" <?php checked( 1, (int) $s['survey'] ); ?> /> <?php esc_html_e( 'Ask for a 1–5 star rating', 'oc-theme' ); ?></label>
							<p style="margin:10px 0 0;"><input type="text" name="survey_q" value="<?php echo esc_attr( (string) $s['survey_q'] ); ?>" placeholder="<?php esc_attr_e( 'Rate your purchase experience', 'oc-theme' ); ?>" class="large-text" /></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Results', 'oc-theme' ); ?></th>
						<td><?php $this->survey_results(); ?></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Refer a friend', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show', 'oc-theme' ); ?></th>
						<td><label><input type="checkbox" name="referral" value="1" <?php checked( 1, (int) $s['referral'] ); ?> /> <?php esc_html_e( 'Offer a shareable coupon; the referrer earns one back when the friend buys', 'oc-theme' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Percentages', 'oc-theme' ); ?></th>
						<td>
							<label><?php esc_html_e( "Friend's discount", 'oc-theme' ); ?> <input type="number" name="ref_friend_pct" value="<?php echo esc_attr( (string) $s['ref_friend_pct'] ); ?>" min="1" max="100" style="width:70px;" />%</label>
							<label style="margin-inline-start:16px;"><?php esc_html_e( "Referrer's reward", 'oc-theme' ); ?> <input type="number" name="ref_reward_pct" value="<?php echo esc_attr( (string) $s['ref_reward_pct'] ); ?>" min="1" max="100" style="width:70px;" />%</label>
							<label style="margin-inline-start:16px;"><?php esc_html_e( 'Valid for', 'oc-theme' ); ?> <input type="number" name="ref_days" value="<?php echo esc_attr( (string) $s['ref_days'] ); ?>" min="1" max="365" style="width:70px;" /> <?php esc_html_e( 'days', 'oc-theme' ); ?></label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'oc-theme' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Aggregate survey results, inline in the settings screen.
	 */
	private function survey_results(): void {
		$agg = get_option( 'oc_ty_survey' );

		if ( ! is_array( $agg ) || empty( $agg['count'] ) ) {
			echo '<em>' . esc_html__( 'No ratings yet.', 'oc-theme' ) . '</em>';
			return;
		}

		$avg = round( $agg['sum'] / $agg['count'], 1 );

		/* translators: 1: average rating, 2: number of ratings. */
		echo '<p style="margin:0 0 8px;"><b>' . esc_html( sprintf( __( 'Average %1$s of 5, from %2$s ratings.', 'oc-theme' ), number_format_i18n( $avg, 1 ), number_format_i18n( (int) $agg['count'] ) ) ) . '</b></p>';

		if ( ! empty( $agg['recent'] ) ) {
			echo '<ul style="margin:0;">';

			foreach ( array_slice( (array) $agg['recent'], 0, 10 ) as $row ) {
				$link = admin_url( 'post.php?post=' . absint( $row['order'] ) . '&action=edit' );
				echo '<li>' . esc_html( str_repeat( '★', (int) $row['rating'] ) . str_repeat( '☆', 5 - (int) $row['rating'] ) )
					. ' — <a href="' . esc_url( $link ) . '">#' . esc_html( (string) $row['order'] ) . '</a>'
					. ' <span style="color:#777;">' . esc_html( date_i18n( get_option( 'date_format' ), (int) $row['t'] ) ) . '</span></li>';
			}

			echo '</ul>';
		}
	}

	/**
	 * Persist.
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_thankyou_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );

		// Saving the untouched default keeps the option empty, so future
		// translation/default changes flow through.
		if ( trim( $content ) === trim( self::default_content() ) ) {
			$content = '';
		}

		$s = array(
			'content'          => $content,
			'summary'          => empty( $_POST['summary'] ) ? 0 : 1,
			'social'           => empty( $_POST['social'] ) ? 0 : 1,
			'social_title'     => sanitize_text_field( wp_unslash( $_POST['social_title'] ?? '' ) ),
			'social_instagram' => esc_url_raw( wp_unslash( $_POST['social_instagram'] ?? '' ) ),
			'social_facebook'  => esc_url_raw( wp_unslash( $_POST['social_facebook'] ?? '' ) ),
			'social_tiktok'    => esc_url_raw( wp_unslash( $_POST['social_tiktok'] ?? '' ) ),
			'social_whatsapp'  => esc_url_raw( wp_unslash( $_POST['social_whatsapp'] ?? '' ) ),
			'social_youtube'   => esc_url_raw( wp_unslash( $_POST['social_youtube'] ?? '' ) ),
			'survey'           => empty( $_POST['survey'] ) ? 0 : 1,
			'survey_q'         => sanitize_text_field( wp_unslash( $_POST['survey_q'] ?? '' ) ),
			'referral'         => empty( $_POST['referral'] ) ? 0 : 1,
			'ref_friend_pct'   => min( 100, max( 1, absint( $_POST['ref_friend_pct'] ?? 10 ) ) ),
			'ref_reward_pct'   => min( 100, max( 1, absint( $_POST['ref_reward_pct'] ?? 10 ) ) ),
			'ref_days'         => min( 365, max( 1, absint( $_POST['ref_days'] ?? 30 ) ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( 'oc_thankyou', $s );

		wp_safe_redirect( add_query_arg( 'oc_saved', '1', admin_url( 'admin.php?page=oc-thankyou' ) ) );
		exit;
	}
}
