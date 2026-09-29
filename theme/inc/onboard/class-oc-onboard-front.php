<?php
/**
 * The questionnaire page: /start/<token>/.
 *
 * A page of its own, not a theme template: no header, no footer, no menu,
 * nothing the shop is not yet. It carries the site's name, logo and
 * primary colour so it already feels like the customer's, and hands the
 * schema, the draft and the words to one script that draws the steps.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

use OC\Theme\Legal\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * The page.
 */
final class Front {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'render' ), 1 );
	}

	/**
	 * Draw the page, or the "this link is no longer valid" note.
	 */
	public function render(): void {
		if ( ! Onboard::is_page() ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		global $wp_query;

		$wp_query->is_404 = false;

		$token = Onboard::request_token();

		if ( ! Onboard::token_ok( $token ) ) {
			status_header( 410 );
			$this->shell( $this->expired_html(), null );
			exit;
		}

		status_header( 200 );

		$state = Onboard::state();

		if ( ! $state['opened'] ) {
			Onboard::touch();
		}

		$this->shell( '<div id="oc-onb" class="oc-onb" data-loading="1"></div>', $this->config( $token ) );
		exit;
	}

	/**
	 * Everything the script needs, once.
	 *
	 * @param string $token The raw token.
	 * @return array<string,mixed>
	 */
	private function config( string $token ): array {
		global $wp_locale;

		$state = Onboard::state();
		$days  = array();

		for ( $d = 0; $d <= 6; $d++ ) {
			$days[] = array(
				'n'     => $d,
				'label' => $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $d ) ),
				'full'  => $wp_locale->get_weekday( $d ),
			);
		}

		return array(
			'rest'       => esc_url_raw( rest_url( Rest::NS . '/onboard' ) ),
			'token'      => $token,
			'status'     => (string) $state['status'],
			'step'       => (string) $state['step'],
			'schema'     => Schema::for_js(),
			'values'     => Draft::values(),
			'days'       => $days,
			'disclaimer' => Consent::text(),
			'site'       => array(
				'name' => (string) get_bloginfo( 'name' ),
				'home' => home_url( '/' ),
			),
			'i18n'       => array(
				'welcome_title' => __( 'Let\'s set up your store', 'oc-theme' ),
				'welcome_text'  => __( 'A few screens about your business, your pages and your preferences. Everything is saved as you go — close the page and come back whenever you like, from any device.', 'oc-theme' ),
				'start'         => __( 'Start', 'oc-theme' ),
				'continue'      => __( 'Continue where I stopped', 'oc-theme' ),
				'next'          => __( 'Next', 'oc-theme' ),
				'back'          => __( 'Back', 'oc-theme' ),
				'saving'        => __( 'Saving…', 'oc-theme' ),
				'saved'         => __( 'Saved', 'oc-theme' ),
				'save_failed'   => __( 'Could not save. Check the connection and try again.', 'oc-theme' ),
				'step_of'       => /* translators: 1: step number, 2: total */ __( 'Step %1$s of %2$s', 'oc-theme' ),
				'required'      => __( 'Required', 'oc-theme' ),
				'fill_required' => __( 'A few required fields are still empty.', 'oc-theme' ),
				'optional'      => __( 'Optional', 'oc-theme' ),
				'choose_file'   => __( 'Choose a file', 'oc-theme' ),
				'uploading'     => __( 'Uploading…', 'oc-theme' ),
				'upload_failed' => __( 'The file could not be uploaded. Allowed: images up to 8MB, or Word / PDF.', 'oc-theme' ),
				'remove'        => __( 'Remove', 'oc-theme' ),
				'add_hours'     => __( 'Add a line', 'oc-theme' ),
				'from'          => __( 'From', 'oc-theme' ),
				'to'            => __( 'To', 'oc-theme' ),
				'days'          => __( 'Days', 'oc-theme' ),
				'summary_title' => __( 'Almost done', 'oc-theme' ),
				'summary_text'  => __( 'Here is everything you told us. Anything missing is marked; tap it to go back.', 'oc-theme' ),
				'submit'        => __( 'I\'m done, build my site', 'oc-theme' ),
				'submitting'    => __( 'Setting things up…', 'oc-theme' ),
				'done_title'    => __( 'Thank you!', 'oc-theme' ),
				'done_text'     => __( 'Your details are in. Our team goes over everything and comes back to you with the next step: the products.', 'oc-theme' ),
				'done_again'    => __( 'You can reopen this link any time to change an answer.', 'oc-theme' ),
				'submit_failed' => __( 'Something went wrong. Nothing was lost — try again in a moment.', 'oc-theme' ),
				'not_answered'  => __( 'Not answered', 'oc-theme' ),
				'yes'           => __( 'Yes', 'oc-theme' ),
				'no'            => __( 'No', 'oc-theme' ),
				'edit'          => __( 'Edit', 'oc-theme' ),
				'rows'          => /* translators: %d: rows */ __( '%d entries', 'oc-theme' ),
				'consent_read'  => __( 'Read the full text', 'oc-theme' ),
			),
		);
	}

	/**
	 * The HTML around the app.
	 *
	 * @param string                    $body   The inner HTML.
	 * @param array<string,mixed>|null  $config Config for the script, or null for a static page.
	 */
	private function shell( string $body, ?array $config ): void {
		$primary = sanitize_hex_color( (string) get_theme_mod( 'oc_color_primary', '' ) );
		$cta     = sanitize_hex_color( (string) get_theme_mod( 'oc_cta_color', '' ) );
		$logo_id = (int) get_theme_mod( 'custom_logo', 0 );
		$logo    = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$dir     = is_rtl() ? 'rtl' : 'ltr';
		$ver     = defined( 'OC_THEME_VERSION' ) ? OC_THEME_VERSION : '1';
		$fonts   = OC_THEME_URI . '/assets/fonts/assistant.css';
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Setting up %s', 'oc-theme' ), (string) get_bloginfo( 'name' ) ) ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $fonts ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( OC_THEME_URI . oc_asset_min( '/assets/css/onboard.css' ) . '?v=' . $ver ); ?>">
	<style>:root{--onb-primary:<?php echo esc_html( $primary ? $primary : '#1d1d1f' ); ?>;--onb-cta:<?php echo esc_html( $cta ? $cta : ( $primary ? $primary : '#1d1d1f' ) ); ?>}</style>
</head>
<body class="oc-onb-body">
	<header class="oc-onb-top">
		<?php if ( $logo ) : ?>
			<img class="oc-onb-top__logo" src="<?php echo esc_url( $logo ); ?>" alt="">
		<?php else : ?>
			<span class="oc-onb-top__name"><?php echo esc_html( (string) get_bloginfo( 'name' ) ); ?></span>
		<?php endif; ?>
		<span class="oc-onb-top__save" id="oc-onb-save" aria-live="polite"></span>
	</header>
	<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
	<?php if ( null !== $config ) : ?>
		<script>window.ocOnboard = <?php echo wp_json_encode( $config, JSON_UNESCAPED_UNICODE ); ?>;</script>
		<script src="<?php echo esc_url( OC_THEME_URI . oc_asset_min( '/assets/js/onboard.js' ) . '?v=' . $ver ); ?>" defer></script>
	<?php endif; ?>
</body>
</html>
		<?php
	}

	/**
	 * When the link is spent.
	 */
	private function expired_html(): string {
		return '<div class="oc-onb oc-onb--static"><div class="oc-onb__card"><h1>' . esc_html__( 'This link is no longer active', 'oc-theme' ) . '</h1><p>' . esc_html__( 'It may have expired or been replaced. Write to us and we will send a fresh one.', 'oc-theme' ) . '</p></div></div>';
	}
}
