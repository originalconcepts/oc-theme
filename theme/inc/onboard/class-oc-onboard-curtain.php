<?php
/**
 * The curtain: while the shop is being built, a visitor who stumbles onto
 * the address sees a friendly screen instead of an empty store.
 *
 * Anyone who can edit the site walks straight through, so the team and the
 * shop owner see the real thing; so does the questionnaire at /start/, which
 * is drawn before this ever runs. The answer is a 503 with Retry-After, so a
 * search engine treats it as a site that is not open yet rather than a page
 * worth remembering.
 *
 * Off by default: a live shop that updates the theme must never wake up
 * behind a curtain. The base site carries it switched on, and every clone
 * inherits it until the day it goes live.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

use OC\Theme\Contact;
use OC\Theme\Product_Contact;

defined( 'ABSPATH' ) || exit;

/**
 * The under-construction screen.
 */
final class Curtain {

	const OPTION = 'oc_curtain';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_draw' ), 3 );
		add_action( 'admin_post_oc_onboard_curtain', array( $this, 'save' ) );
	}

	/**
	 * Settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'on'    => 0,
				'title' => '',
				'text'  => '',
			)
		);
	}

	/**
	 * Is the curtain down for this visitor?
	 */
	public static function on(): bool {
		return ! empty( self::settings()['on'] );
	}

	/**
	 * Draw it, unless this request is one of the ways through.
	 */
	public function maybe_draw(): void {
		if ( ! self::on() ) {
			return;
		}

		// The people building the site see the site — unless they asked to
		// look at the screen itself from the settings page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a preview of our own screen, nothing is written.
		$peek = isset( $_GET['oc_curtain_peek'] );

		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) && ! $peek ) {
			return;
		}

		// The questionnaire has already answered and exited by now; this is
		// the belt to that brace.
		if ( Onboard::is_page() ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Retry-After: 86400' );
		status_header( 503 );

		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		exit;
	}

	/**
	 * The screen. It carries the shop's own logo and colour, and its phone
	 * and WhatsApp when they are known — someone who found the address
	 * should still be able to reach the shop.
	 */
	public static function html(): string {
		$s       = self::settings();
		$title   = trim( (string) $s['title'] );
		$text    = trim( (string) $s['text'] );
		$title   = '' !== $title ? $title : __( 'The site is being built', 'oc-theme' );
		$text    = '' !== $text ? $text : __( 'We are putting the finishing touches on the new store. Come back soon.', 'oc-theme' );
		$logo_id = (int) get_theme_mod( 'custom_logo', 0 );
		$logo    = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$colour  = sanitize_hex_color( (string) get_theme_mod( 'oc_color_primary', '' ) );
		$colour  = $colour ? $colour : '#0143a5';
		$phone   = Contact::get( 'phone' );
		$wa      = Contact::get( 'whatsapp' );
		$mail    = Contact::get( 'email' );

		$links = '';

		if ( $phone ) {
			$links .= '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>';
		}

		if ( $wa ) {
			$links .= '<a href="https://wa.me/' . esc_attr( Product_Contact::digits( $wa ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'WhatsApp', 'oc-theme' ) . '</a>';
		}

		if ( $mail ) {
			$links .= '<a href="mailto:' . esc_attr( $mail ) . '">' . esc_html( $mail ) . '</a>';
		}

		$head = $logo
			? '<img class="oc-curtain__logo" src="' . esc_url( $logo ) . '" alt="' . esc_attr( (string) get_bloginfo( 'name' ) ) . '">'
			: '<p class="oc-curtain__name">' . esc_html( (string) get_bloginfo( 'name' ) ) . '</p>';

		return '<!doctype html><html lang="' . esc_attr( (string) get_bloginfo( 'language' ) ) . '" dir="' . ( is_rtl() ? 'rtl' : 'ltr' ) . '">'
			. '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . esc_html( $title . ' · ' . get_bloginfo( 'name' ) ) . '</title>'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- a page of its own, printed outside wp_head.
			. '<link rel="stylesheet" href="' . esc_url( OC_THEME_URI . '/assets/fonts/assistant.css' ) . '">'
			. '<style>'
			. 'body{margin:0;min-height:100dvh;display:grid;place-items:center;padding:32px;'
			. 'background:#f5f5f7;color:#1d1d1f;font:16px/1.6 Assistant,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;text-align:center}'
			. '.oc-curtain{max-width:520px}'
			. '.oc-curtain__logo{max-height:64px;max-width:220px;object-fit:contain;margin-bottom:22px}'
			. '.oc-curtain__name{font-size:22px;font-weight:700;margin:0 0 18px}'
			. '.oc-curtain h1{font-size:clamp(24px,5vw,32px);line-height:1.2;margin:0 0 12px}'
			. '.oc-curtain p{color:#5f6368;margin:0 auto;max-width:42ch}'
			. '.oc-curtain__links{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:26px}'
			. '.oc-curtain__links a{display:inline-block;padding:10px 20px;border-radius:999px;background:' . esc_attr( $colour ) . ';'
			. 'color:#fff;text-decoration:none;font-weight:600;font-size:15px}'
			. '</style></head><body><div class="oc-curtain">'
			. $head
			. '<h1>' . esc_html( $title ) . '</h1>'
			. '<p>' . esc_html( $text ) . '</p>'
			. ( $links ? '<div class="oc-curtain__links">' . $links . '</div>' : '' )
			. '</div></body></html>';
	}

	/**
	 * The card on the onboarding screen.
	 */
	public static function card(): void {
		$s = self::settings();
		?>
		<div class="card">
			<h2><?php esc_html_e( 'While the site is being built', 'oc-theme' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A visitor who reaches the address sees a friendly screen instead of an empty shop. Everyone who can edit the site sees the real thing, and the questionnaire link keeps working. Switch it off on the day the shop opens.', 'oc-theme' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_onboard_curtain" />
				<?php wp_nonce_field( 'oc_onboard_curtain' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'The screen', 'oc-theme' ); ?></th>
						<td><label><input type="checkbox" name="on" value="1" <?php checked( 1, (int) $s['on'] ); ?> /> <?php esc_html_e( 'Show it to everyone who is not signed in as staff', 'oc-theme' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc-curtain-t"><?php esc_html_e( 'Headline', 'oc-theme' ); ?></label></th>
						<td><input type="text" id="oc-curtain-t" name="title" class="regular-text" value="<?php echo esc_attr( (string) $s['title'] ); ?>" placeholder="<?php esc_attr_e( 'The site is being built', 'oc-theme' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc-curtain-x"><?php esc_html_e( 'The line under it', 'oc-theme' ); ?></label></th>
						<td><input type="text" id="oc-curtain-x" name="text" class="large-text" value="<?php echo esc_attr( (string) $s['text'] ); ?>" placeholder="<?php esc_attr_e( 'We are putting the finishing touches on the new store. Come back soon.', 'oc-theme' ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'oc-theme' ), 'secondary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'oc_curtain_peek', '1', home_url( '/' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See it', 'oc-theme' ); ?></a>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_onboard_curtain' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		update_option(
			self::OPTION,
			array(
				'on'    => empty( $_POST['on'] ) ? 0 : 1,
				'title' => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
				'text'  => sanitize_text_field( wp_unslash( $_POST['text'] ?? '' ) ),
			),
			false
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect( add_query_arg( 'oc_done', 'curtain', Admin::url() ) );
		exit;
	}
}
