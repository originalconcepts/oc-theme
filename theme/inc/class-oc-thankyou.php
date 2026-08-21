<?php
/**
 * Thank-you page: a warm, useful order-received screen.
 *
 * Same rule as the checkout (DECISIONS.md #7): no template overrides. The
 * native thankyou endpoint keeps running — gateway hooks included — and this
 * class hides Woo's dry defaults and renders its own sections through
 * woocommerce_before_thankyou: animated check, editable content, order
 * summary, and the optional WhatsApp / survey / referral / social blocks.
 *
 * What the shop's channels ARE lives in Store details (class Contact); this
 * screen only decides which of them this page shows.
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
				'content'        => '',   // Editor HTML; empty = the default service line.
				'contact'        => 1,    // Phone / email / WhatsApp icon links.
				'summary'        => 1,    // Order summary with images.
				'wa_group'       => 0,    // "Join our WhatsApp group" widget.
				'social'         => 0,    // Follow buttons.
				'social_title'   => '',
				'survey'         => 0,
				'survey_q'       => '',
				'referral'       => 0,
				'ref_friend_pct' => 10,   // The friend's discount.
				'ref_reward_pct' => 10,   // The referrer's reward coupon.
				'ref_days'       => 30,   // Both coupons' lifetime.
			)
		);
	}

	/**
	 * The default editable content: the line above the contact channels.
	 */
	public static function default_content(): string {
		return '<p>' . __( 'Our customer service team is here for you with any question:', 'oc-theme' ) . '</p>';
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

		if ( ! empty( $s['wa_group'] ) ) {
			$this->wa_group_block();
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
		echo '<p class="oc-ty__mailnote">' . esc_html__( 'The confirmation and order details were sent by email.', 'oc-theme' ) . '</p>';
		echo '</div>';
	}

	/**
	 * The editable text, then the contact channels as icon links. Both sit
	 * in the open, continuing the greeting rather than boxed away from it.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $s     Settings.
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

		$contact = empty( $s['contact'] ) ? '' : Contact::contact_row_html();

		if ( '' === trim( wp_strip_all_tags( $html ) ) && '' === $contact ) {
			return;
		}

		echo '<div class="oc-ty__intro">';
		echo do_shortcode( wpautop( wp_kses_post( $html ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses above.
		echo $contact; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</div>';
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
			$qty     = (int) $item->get_quantity();

			// A variation's attributes get the cart's treatment — swatch dot
			// and all. Anything else falls back to Woo's own meta list.
			$pairs = ( $product && $product->is_type( 'variation' ) ) ? (array) $product->get_attributes() : array();
			$meta  = $pairs ? Cart::attributes_html( $pairs ) : wc_display_item_meta( $item, array( 'echo' => false ) );

			echo '<div class="oc-ty__item">';
			echo '<span class="oc-ty__item-img">' . wp_kses_post( $thumb );

			// Quantity rides the image as a corner badge; a single unit needs
			// no announcement.
			if ( $qty > 1 ) {
				echo '<span class="oc-ty__item-badge">' . esc_html( (string) $qty ) . '</span>';
			}

			echo '</span>';
			echo '<span class="oc-ty__item-body">';
			echo '<span class="oc-ty__item-name">' . esc_html( $item->get_name() ) . '</span>';

			if ( $meta ) {
				echo '<span class="oc-ty__item-meta">' . wp_kses_post( $meta ) . '</span>';
			}

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
				__( 'This code is yours to share: a friend gets %1$s%% off a first order, and once they buy, a %2$s%% coupon is waiting for you.', 'oc-theme' ),
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
	 * Survey: five stars, then room for a few words.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $s     Settings.
	 */
	private function survey_block( \WC_Order $order, array $s ): void {
		$q       = '' !== trim( (string) $s['survey_q'] ) ? (string) $s['survey_q'] : __( 'How was your purchase experience?', 'oc-theme' );
		$rated   = (int) $order->get_meta( '_oc_ty_rating' );
		$comment = (string) $order->get_meta( '_oc_ty_comment' );

		echo '<div class="oc-ty__box oc-ty__survey" data-oc-ty-survey data-order="' . esc_attr( (string) $order->get_id() ) . '" data-key="' . esc_attr( $order->get_order_key() ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"' . ( $rated ? ' data-rated="' . esc_attr( (string) $rated ) . '"' : '' ) . ( '' !== $comment ? ' data-said="1"' : '' ) . '>';
		echo '<h2 class="oc-ty__h">' . esc_html( $q ) . '</h2>';
		echo '<div class="oc-ty__stars" role="radiogroup" aria-label="' . esc_attr( $q ) . '">';

		for ( $i = 1; $i <= 5; $i++ ) {
			echo '<button type="button" class="oc-ty__star' . ( $rated && $i <= $rated ? ' is-on' : '' ) . '" data-star="' . esc_attr( (string) $i ) . '"' . ( $rated ? ' disabled' : '' ) . ' aria-label="' . esc_attr( (string) $i ) . '"><svg viewBox="0 0 24 24"><path d="M12 2.6 15 9l7 .7-5.3 4.7 1.6 6.9L12 17.6 5.7 21.3l1.6-6.9L2 9.7 9 9Z"/></svg></button>';
		}

		echo '</div>';

		// The box opens once a rating lands, and stays closed for a visitor
		// who already said their piece.
		echo '<div class="oc-ty__say" data-oc-ty-say' . ( $rated && '' === $comment ? '' : ' hidden' ) . '>';
		echo '<textarea class="oc-ty__saybox" data-oc-ty-text rows="3" maxlength="600" placeholder="' . esc_attr__( 'A few words about the experience?', 'oc-theme' ) . '"></textarea>';
		echo '<button type="button" class="oc-ty__saybtn" data-oc-ty-send>' . esc_html__( 'Send', 'oc-theme' ) . '</button>';
		echo '</div>';

		if ( '' !== $comment ) {
			echo '<p class="oc-ty__said">' . esc_html( $comment ) . '</p>';
		}

		echo '<p class="oc-ty__thanks"' . ( $rated ? '' : ' hidden' ) . '>' . esc_html__( 'Thanks for the feedback!', 'oc-theme' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Store a rating and/or a comment: verified by the order key, once each.
	 */
	public function ajax_rate(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the order key below is the proof.
		$order_id = absint( $_POST['order'] ?? 0 );
		$key      = wc_clean( wp_unslash( $_POST['key'] ?? '' ) );
		$rating   = absint( $_POST['rating'] ?? 0 );
		$comment  = sanitize_textarea_field( wp_unslash( $_POST['comment'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order || ! hash_equals( $order->get_order_key(), (string) $key ) ) {
			wp_send_json_error();
		}

		$had     = (int) $order->get_meta( '_oc_ty_rating' );
		$changed = false;

		if ( $rating >= 1 && $rating <= 5 && ! $had ) {
			$order->update_meta_data( '_oc_ty_rating', $rating );
			$changed = true;

			$agg = get_option( 'oc_ty_survey' );
			$agg = is_array( $agg ) ? $agg : array(
				'count' => 0,
				'sum'   => 0,
			);

			$agg['count'] = (int) ( $agg['count'] ?? 0 ) + 1;
			$agg['sum']   = (int) ( $agg['sum'] ?? 0 ) + $rating;
			unset( $agg['recent'] ); // The list comes from the orders themselves.

			update_option( 'oc_ty_survey', $agg, false );
		}

		// A comment only counts alongside a rating, and only the first time.
		if ( '' !== $comment && ( $had || $changed ) && '' === (string) $order->get_meta( '_oc_ty_comment' ) ) {
			$order->update_meta_data( '_oc_ty_comment', mb_substr( $comment, 0, 600 ) );
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}

		wp_send_json_success();
	}

	/**
	 * The WhatsApp group invitation, on its own.
	 */
	private function wa_group_block(): void {
		$url = Contact::get( 'wa_group' );

		if ( ! $url ) {
			return;
		}

		echo '<a class="oc-ty__box oc-ty__wagroup" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">';
		echo '<span class="oc-ty__wagroup-i" aria-hidden="true">' . Contact::icon( 'whatsapp' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static svg.
		echo '<span class="oc-ty__wagroup-t">';
		echo '<b>' . esc_html__( 'Join our WhatsApp group', 'oc-theme' ) . '</b>';
		echo '<em>' . esc_html__( 'News and offers first', 'oc-theme' ) . '</em>';
		echo '</span>';
		echo '<span class="oc-ty__wagroup-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 6l6 6-6 6"/><path d="M20 12H4"/></svg></span>';
		echo '</a>';
	}

	/**
	 * Social follow buttons.
	 *
	 * @param array<string,mixed> $s Settings.
	 */
	private function social_block( array $s ): void {
		$row = Contact::social_row_html();

		if ( '' === $row ) {
			return;
		}

		$title = '' !== trim( (string) $s['social_title'] ) ? (string) $s['social_title'] : __( 'Want to follow us?', 'oc-theme' );

		echo '<div class="oc-ty__box oc-ty__social">';
		echo '<h2 class="oc-ty__h">' . esc_html( $title ) . '</h2>';
		echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</div>';
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
	 * The settings screen, with its ratings tab.
	 */
	public function admin_screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch only.
		$tab = isset( $_GET['tab'] ) && 'ratings' === $_GET['tab'] ? 'ratings' : 'settings';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Thank-you page', 'oc-theme' ) . '</h1>';

		echo '<h2 class="nav-tab-wrapper">';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=oc-thankyou' ) ) . '" class="nav-tab' . ( 'settings' === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Settings', 'oc-theme' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=oc-thankyou&tab=ratings' ) ) . '" class="nav-tab' . ( 'ratings' === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Ratings', 'oc-theme' ) . '</a>';
		echo '</h2>';

		if ( 'ratings' === $tab ) {
			$this->ratings_tab();
		} else {
			$this->settings_tab();
		}

		echo '</div>';
	}

	/**
	 * The settings form.
	 */
	private function settings_tab(): void {
		if ( isset( $_GET['oc_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}

		$s        = self::settings();
		$content  = '' !== trim( (string) $s['content'] ) ? (string) $s['content'] : self::default_content();
		$assets   = admin_url( 'admin.php?page=oc-contact' );
		$has_wa   = '' !== Contact::get( 'wa_group' );
		$has_soc  = (bool) Contact::social_links();
		$missing  = '<em> — <a href="' . esc_url( $assets ) . '">' . esc_html__( 'add it in Store details', 'oc-theme' ) . '</a></em>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="oc_thankyou_save" />
			<?php wp_nonce_field( 'oc_thankyou_save' ); ?>

			<h2><?php esc_html_e( 'Page content', 'oc-theme' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Shown under the greeting. Placeholders: {phone} {email} {whatsapp} {first_name} {order_number} — the channels come from Store details.', 'oc-theme' ); ?></p>
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

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Contact channels', 'oc-theme' ); ?></th>
					<td>
						<label><input type="checkbox" name="contact" value="1" <?php checked( 1, (int) $s['contact'] ); ?> /> <?php esc_html_e( 'Show phone, email and WhatsApp under the text', 'oc-theme' ); ?></label>
						<p class="description"><a href="<?php echo esc_url( $assets ); ?>"><?php esc_html_e( 'Set them in Store details', 'oc-theme' ); ?></a></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Order summary', 'oc-theme' ); ?></th>
					<td><label><input type="checkbox" name="summary" value="1" <?php checked( 1, (int) $s['summary'] ); ?> /> <?php esc_html_e( 'Show the products (with images) and totals', 'oc-theme' ); ?></label></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Blocks', 'oc-theme' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'WhatsApp group', 'oc-theme' ); ?></th>
					<td>
						<label><input type="checkbox" name="wa_group" value="1" <?php checked( 1, (int) $s['wa_group'] ); ?> /> <?php esc_html_e( 'Invite to join the group', 'oc-theme' ); ?></label>
						<?php
						if ( ! $has_wa ) {
							echo wp_kses_post( $missing );
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Social buttons', 'oc-theme' ); ?></th>
					<td>
						<label><input type="checkbox" name="social" value="1" <?php checked( 1, (int) $s['social'] ); ?> /> <?php esc_html_e( 'Invite customers to follow the store', 'oc-theme' ); ?></label>
						<?php
						if ( ! $has_soc ) {
							echo wp_kses_post( $missing );
						}
						?>
						<p style="margin:10px 0 0;"><input type="text" name="social_title" value="<?php echo esc_attr( (string) $s['social_title'] ); ?>" placeholder="<?php esc_attr_e( 'Want to follow us?', 'oc-theme' ); ?>" class="regular-text" /></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Purchase survey', 'oc-theme' ); ?></th>
					<td>
						<label><input type="checkbox" name="survey" value="1" <?php checked( 1, (int) $s['survey'] ); ?> /> <?php esc_html_e( 'Ask for a 1–5 star rating, then a few words', 'oc-theme' ); ?></label>
						<p style="margin:10px 0 0;"><input type="text" name="survey_q" value="<?php echo esc_attr( (string) $s['survey_q'] ); ?>" placeholder="<?php esc_attr_e( 'How was your purchase experience?', 'oc-theme' ); ?>" class="large-text" /></p>
					</td>
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
		<?php
	}

	/**
	 * Every rating a customer left, newest first.
	 */
	private function ratings_tab(): void {
		$agg = get_option( 'oc_ty_survey' );

		if ( is_array( $agg ) && ! empty( $agg['count'] ) ) {
			$avg = round( (int) $agg['sum'] / (int) $agg['count'], 1 );

			echo '<p style="font-size:15px;margin:16px 0;"><b>' . esc_html(
				sprintf(
					/* translators: 1: average rating, 2: number of ratings. */
					__( 'Average %1$s of 5, from %2$s ratings.', 'oc-theme' ),
					number_format_i18n( $avg, 1 ),
					number_format_i18n( (int) $agg['count'] )
				)
			) . '</b></p>';
		}

		$per   = 30;
		$page  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pagination only.
		$found = wc_get_orders(
			array(
				'limit'      => $per,
				'page'       => $page,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'paginate'   => true,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an admin screen.
					array(
						'key'     => '_oc_ty_rating',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$orders = is_object( $found ) ? $found->orders : (array) $found;

		if ( ! $orders ) {
			echo '<p><em>' . esc_html__( 'No ratings yet.', 'oc-theme' ) . '</em></p>';
			return;
		}

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th style="width:90px;">' . esc_html__( 'Order', 'oc-theme' ) . '</th>';
		echo '<th style="width:190px;">' . esc_html__( 'Customer', 'oc-theme' ) . '</th>';
		echo '<th style="width:110px;">' . esc_html__( 'Rating', 'oc-theme' ) . '</th>';
		echo '<th>' . esc_html__( 'In their words', 'oc-theme' ) . '</th>';
		echo '<th style="width:130px;">' . esc_html__( 'Date', 'oc-theme' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $orders as $order ) {
			$rating  = (int) $order->get_meta( '_oc_ty_rating' );
			$comment = (string) $order->get_meta( '_oc_ty_comment' );
			$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$created = $order->get_date_created();

			echo '<tr>';
			echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $name ) . '<br /><span style="color:#777;">' . esc_html( $order->get_billing_email() ) . '</span></td>';
			echo '<td style="color:#f5b301;font-size:15px;letter-spacing:2px;" title="' . esc_attr( (string) $rating ) . '/5">'
				. esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ) . '</td>';
			echo '<td>' . ( '' !== $comment ? esc_html( $comment ) : '<span style="color:#bbb;">—</span>' ) . '</td>';
			echo '<td>' . esc_html( $created ? $created->date_i18n( get_option( 'date_format' ) ) : '' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$pages = is_object( $found ) ? (int) $found->max_num_pages : 1;

		if ( $pages > 1 ) {
			echo '<p style="margin-top:14px;">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => admin_url( 'admin.php?page=oc-thankyou&tab=ratings&paged=%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			) . '</p>';
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
			'content'        => $content,
			'contact'        => empty( $_POST['contact'] ) ? 0 : 1,
			'summary'        => empty( $_POST['summary'] ) ? 0 : 1,
			'wa_group'       => empty( $_POST['wa_group'] ) ? 0 : 1,
			'social'         => empty( $_POST['social'] ) ? 0 : 1,
			'social_title'   => sanitize_text_field( wp_unslash( $_POST['social_title'] ?? '' ) ),
			'survey'         => empty( $_POST['survey'] ) ? 0 : 1,
			'survey_q'       => sanitize_text_field( wp_unslash( $_POST['survey_q'] ?? '' ) ),
			'referral'       => empty( $_POST['referral'] ) ? 0 : 1,
			'ref_friend_pct' => min( 100, max( 1, absint( $_POST['ref_friend_pct'] ?? 10 ) ) ),
			'ref_reward_pct' => min( 100, max( 1, absint( $_POST['ref_reward_pct'] ?? 10 ) ) ),
			'ref_days'       => min( 365, max( 1, absint( $_POST['ref_days'] ?? 30 ) ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( 'oc_thankyou', $s );

		wp_safe_redirect( add_query_arg( 'oc_saved', '1', admin_url( 'admin.php?page=oc-thankyou' ) ) );
		exit;
	}
}
