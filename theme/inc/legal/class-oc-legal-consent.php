<?php
/**
 * The owner's consent before a legal page is written.
 *
 * The generated pages are a general template, not legal advice. Before any
 * of them is written the owner reads that in a dialog, confirms it, and the
 * confirmation — who, when, from where, which page, which options — is kept
 * in an option no screen shows, so there is a record if it is ever claimed
 * the pages came with a promise they never carried.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Legal;

defined( 'ABSPATH' ) || exit;

/**
 * Consent dialog, guard and log.
 */
final class Consent {

	const LOG = 'oc_legal_consents';

	/**
	 * Where a copy of every confirmation goes, so the record does not live
	 * only on the customer's server. Subject prefix and header are stable
	 * so a mailbox rule can file them.
	 */
	const COPY_TO = 'george@originalconcepts.co.il';
	const SUBJECT = '[OC Legal Consent]';

	/**
	 * Whether the dialog's style and script were printed on this page.
	 *
	 * @var bool
	 */
	private static $printed = false;

	/**
	 * The disclaimer, as the owner reads it.
	 */
	public static function text(): string {
		return __( 'This page is a general template drafted with the help of artificial intelligence by the theme\'s makers. It is a starting point for inspiration only — not legal advice, and not tailored to your business. Before publishing, have the text reviewed by a lawyer and adapted to your business, and read every line yourself. Using the page is the sole responsibility of the site owner; the theme\'s makers bear no responsibility for its content or for any claim that may arise from it. Clicking "Create the page" confirms that you have read and understood this.', 'oc-theme' );
	}

	/**
	 * A button that opens the consent dialog, and the dialog itself with the
	 * form that writes the page. Prints its own style and script once.
	 *
	 * @param string $action  admin-post action.
	 * @param string $nonce   Nonce action.
	 * @param string $label   Button text.
	 * @param string $extra   Extra fields inside the form (options), escaped HTML.
	 */
	public static function button( string $action, string $nonce, string $label, string $extra = '' ): void {
		$id = 'oc-legal-' . sanitize_html_class( $action );

		if ( ! self::$printed ) {
			self::$printed = true;
			?>
			<style>
				.oc-legal-dlg { border: 0; border-radius: 10px; padding: 0; max-width: 560px; width: calc(100% - 40px); box-shadow: 0 20px 60px rgba(0,0,0,.25); }
				.oc-legal-dlg::backdrop { background: rgba(0,0,0,.45); }
				.oc-legal-dlg form { padding: 22px 24px; }
				.oc-legal-dlg h2 { margin: 0 0 12px; font-size: 1.2em; }
				.oc-legal-dlg .oc-legal-warn { background: #fcf9e8; border-inline-start: 4px solid #dba617; padding: 10px 14px; margin: 0 0 14px; line-height: 1.6; }
				.oc-legal-dlg fieldset { margin: 0 0 12px; }
				.oc-legal-dlg fieldset legend { font-weight: 600; margin-bottom: 4px; }
				.oc-legal-dlg .oc-legal-ok { display: flex; gap: 8px; align-items: flex-start; margin: 14px 0; font-weight: 600; }
				.oc-legal-dlg .oc-legal-btns { display: flex; gap: 10px; }
			</style>
			<script>
				document.addEventListener( 'click', function ( e ) {
					var open = e.target.closest( '[data-oc-legal-open]' );
					if ( open ) { var d = document.getElementById( open.getAttribute( 'data-oc-legal-open' ) ); if ( d ) { d.showModal(); } return; }
					var close = e.target.closest( '[data-oc-legal-close]' );
					if ( close ) { close.closest( 'dialog' ).close(); }
				} );
				document.addEventListener( 'change', function ( e ) {
					if ( e.target.matches( '.oc-legal-dlg input[name="oc_consent"]' ) ) {
						e.target.closest( 'form' ).querySelector( 'button[type="submit"]' ).disabled = ! e.target.checked;
					}
				} );
			</script>
			<?php
		}
		?>
		<button type="button" class="button button-secondary" data-oc-legal-open="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></button>
		<dialog id="<?php echo esc_attr( $id ); ?>" class="oc-legal-dlg" aria-labelledby="<?php echo esc_attr( $id ); ?>-h">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
				<?php wp_nonce_field( $nonce ); ?>
				<h2 id="<?php echo esc_attr( $id ); ?>-h"><?php echo esc_html( $label ); ?></h2>
				<p class="oc-legal-warn"><?php echo esc_html( self::text() ); ?></p>
				<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by the caller. ?>
				<label class="oc-legal-ok"><input type="checkbox" name="oc_consent" value="1" /> <span><?php esc_html_e( 'I have read and understood: this is a template, not legal advice, and using it is my responsibility.', 'oc-theme' ); ?></span></label>
				<p class="oc-legal-btns">
					<button type="submit" class="button button-primary" disabled><?php esc_html_e( 'Create the page', 'oc-theme' ); ?></button>
					<button type="button" class="button" data-oc-legal-close><?php esc_html_e( 'Cancel', 'oc-theme' ); ?></button>
				</p>
			</form>
		</dialog>
		<?php
	}

	/**
	 * In a handler, after the nonce: was the box ticked? If so, record it.
	 * The caller has verified the nonce and the capability.
	 *
	 * @param string              $kind    terms | accessibility | privacy.
	 * @param array<string,mixed> $options Choices made in the dialog.
	 */
	public static function confirmed( string $kind, array $options = array() ): bool {
		if ( empty( $_POST['oc_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
			return false;
		}

		$user = wp_get_current_user();
		$log  = get_option( self::LOG, array() );
		$log  = is_array( $log ) ? $log : array();

		$entry = array(
			'when'     => gmdate( 'c' ),
			'kind'     => $kind,
			'options'  => $options,
			'user_id'  => (int) $user->ID,
			'login'    => (string) $user->user_login,
			'name'     => (string) $user->display_name,
			'email'    => (string) $user->user_email,
			'ip'       => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '',
			'agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 200 ) : '',
			'site'     => home_url( '/' ),
			'theme'    => defined( 'OC_THEME_VERSION' ) ? OC_THEME_VERSION : '',
			'text_sha' => substr( sha1( self::text() ), 0, 12 ), // Which wording was shown.
		);

		$log[] = $entry;

		// Never autoloaded and never shown: only code reads it.
		update_option( self::LOG, array_slice( $log, -200 ), false );

		self::mail_copy( $entry );

		return true;
	}

	/**
	 * A copy of the record by email. The option is the record of truth; the
	 * mail is the copy that survives the customer's server, so a failure to
	 * send never stops the page.
	 *
	 * @param array<string,mixed> $e The log entry.
	 */
	private static function mail_copy( array $e ): void {
		$labels = array(
			'terms'         => 'Terms of sale',
			'accessibility' => 'Accessibility statement',
			'privacy'       => 'Privacy policy',
		);
		$kind   = $labels[ $e['kind'] ] ?? $e['kind'];
		$host   = (string) wp_parse_url( (string) $e['site'], PHP_URL_HOST );
		$local  = get_date_from_gmt( gmdate( 'Y-m-d H:i:s' ), 'd/m/Y H:i' ) . ' (' . wp_timezone_string() . ')';
		$opts   = $e['options'] ? wp_json_encode( $e['options'], JSON_UNESCAPED_UNICODE ) : '-';

		$body = "Legal page template consent\n"
			. "===========================\n\n"
			. "Site:        {$e['site']}\n"
			. "Page:        {$kind} ({$e['kind']})\n"
			. "Options:     {$opts}\n"
			. "When:        {$local} / {$e['when']} UTC\n"
			. "User:        #{$e['user_id']} {$e['login']} — {$e['name']} <{$e['email']}>\n"
			. "IP:          {$e['ip']}\n"
			. "Browser:     {$e['agent']}\n"
			. "Theme:       oc-theme {$e['theme']}\n"
			. "Wording id:  {$e['text_sha']}\n\n"
			. "The text the user confirmed:\n"
			. "----------------------------\n"
			. self::text() . "\n\n"
			. "Ticked: \"" . __( 'I have read and understood: this is a template, not legal advice, and using it is my responsibility.', 'oc-theme' ) . "\"\n";

		wp_mail(
			self::COPY_TO,
			self::SUBJECT . ' ' . $kind . ' — ' . $host,
			$body,
			array(
				'Content-Type: text/plain; charset=UTF-8',
				'X-OC-Consent: ' . $e['kind'],
				'X-OC-Site: ' . $host,
			)
		);
	}
}
