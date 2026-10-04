<?php
/**
 * The mails around the questionnaire, and the clock behind them.
 *
 * The invitation itself usually goes out from Monday; this class sends it
 * when asked to (a re-send from the admin screen, or the invite route
 * told to). The reminders are the site's own: a day after the link went
 * out with no progress, again two days later, and on the fourth day a
 * note to the team instead of the customer. Every mail copies the team.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

defined( 'ABSPATH' ) || exit;

/**
 * Invitation, reminders, the done note and the report.
 */
final class Mail {

	const CRON = 'oc_onboard_tick';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
	}

	/**
	 * A daily tick while an invitation is open.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON );
		}
	}

	/**
	 * Reminders, by the calendar: day 1 and day 3 to the customer, day 4
	 * to the team — each once, each only while nothing moved.
	 */
	public static function tick(): void {
		$state = Onboard::state();

		if ( 'draft' !== $state['status'] || ! $state['created'] ) {
			return;
		}

		$age  = Onboard::age_days();
		$sent = (array) $state['reminders'];
		$idle = ! $state['activity'] || ( time() - (int) $state['activity'] ) > DAY_IN_SECONDS;

		if ( $age >= 4 && empty( $sent['g'] ) ) {
			self::nudge_team();
			$sent['g'] = time();
		} elseif ( $age >= 3 && empty( $sent['r2'] ) && $idle ) {
			self::reminder( 2 );
			$sent['r2'] = time();
		} elseif ( $age >= 1 && empty( $sent['r1'] ) && $idle ) {
			self::reminder( 1 );
			$sent['r1'] = time();
		} else {
			return;
		}

		Onboard::patch_state( array( 'reminders' => $sent ) );
	}

	/* ------------------------------------------------------------ mails */

	/**
	 * The link, to the customer.
	 *
	 * @param string $url The questionnaire link.
	 */
	public static function invitation( string $url ): bool {
		$state = Onboard::state();
		$to    = (string) $state['client']['email'];

		if ( ! is_email( $to ) ) {
			return false;
		}

		$name = (string) $state['client']['name'];

		$body = self::greeting( $name )
			. '<p>' . esc_html__( 'We are starting on your new store. The first step is a short questionnaire about your business, your pages and how you would like things to look.', 'oc-theme' ) . '</p>'
			. '<p>' . esc_html__( 'Everything is saved as you go, so you can stop and come back whenever you like, from your phone or your computer.', 'oc-theme' ) . '</p>'
			. self::button( $url, __( 'Open the questionnaire', 'oc-theme' ) )
			. '<p class="small">' . esc_html__( 'The link is personal; please do not forward it.', 'oc-theme' ) . '</p>';

		return self::send( $to, __( 'Let\'s set up your store', 'oc-theme' ), $body );
	}

	/**
	 * A nudge to the customer.
	 *
	 * @param int $n 1 or 2.
	 */
	public static function reminder( int $n ): bool {
		$state = Onboard::state();
		$to    = (string) $state['client']['email'];

		if ( ! is_email( $to ) ) {
			return false;
		}

		$url  = self::link_for_mail();
		$name = (string) $state['client']['name'];
		$prog = Draft::progress();

		$line = $prog['answered'] > 0
			? sprintf( /* translators: 1: answered, 2: total */ esc_html__( 'You have answered %1$d of %2$d questions — a few minutes more and your site can be built.', 'oc-theme' ), $prog['answered'], $prog['total'] )
			: esc_html__( 'The questionnaire is waiting for you. It takes about fifteen minutes, and everything is saved as you go.', 'oc-theme' );

		$body = self::greeting( $name )
			. '<p>' . $line . '</p>'
			. ( $url ? self::button( $url, __( 'Continue the questionnaire', 'oc-theme' ) ) : '' )
			. '<p class="small">' . esc_html__( 'Stuck on a question? Reply to this email and we will help.', 'oc-theme' ) . '</p>';

		$subject = 2 === $n
			? __( 'Your store is waiting for a few answers', 'oc-theme' )
			: __( 'A reminder: the questionnaire for your new store', 'oc-theme' );

		return self::send( $to, $subject, $body );
	}

	/**
	 * Day four: the customer went quiet.
	 */
	public static function nudge_team(): bool {
		$state = Onboard::state();
		$prog  = Draft::progress();

		$body = '<p>' . esc_html( sprintf( /* translators: 1: client name, 2: site */ __( '%1$s has not progressed with the questionnaire on %2$s for four days.', 'oc-theme' ), (string) $state['client']['name'], home_url( '/' ) ) ) . '</p>'
			. '<p>' . esc_html( sprintf( /* translators: 1: answered, 2: total */ __( 'Answered: %1$d of %2$d.', 'oc-theme' ), $prog['answered'], $prog['total'] ) ) . '</p>'
			. '<p>' . esc_html( (string) $state['client']['phone'] ) . ' · ' . esc_html( (string) $state['client']['email'] ) . '</p>'
			. self::button( admin_url( 'admin.php?page=oc-onboard' ), __( 'Open the onboarding screen', 'oc-theme' ) );

		return self::send( Onboard::COPY_TO, sprintf( /* translators: %s: site host */ __( 'No progress: %s', 'oc-theme' ), self::host() ), $body, false );
	}

	/**
	 * After the apply: the customer gets a thank-you, the team the report.
	 *
	 * @param array<int,array<string,string>> $report Rows.
	 */
	public static function done( array $report ): void {
		$state = Onboard::state();
		$to    = (string) $state['client']['email'];

		$todo = Onboard::todo();
		$mine = '';

		foreach ( $todo as $one ) {
			$mine .= '<li>' . esc_html( $one ) . '</li>';
		}

		if ( is_email( $to ) ) {
			self::send(
				$to,
				__( 'We got it — your site is being built', 'oc-theme' ),
				self::greeting( (string) $state['client']['name'] )
				. '<p>' . esc_html__( 'Thank you! Everything you answered is already written into your site.', 'oc-theme' ) . '</p>'
				. ( '' === $mine ? '' : '<p><strong>' . esc_html__( 'What is left for you', 'oc-theme' ) . '</strong></p><ul>' . $mine . '</ul>' )
				. '<p>' . esc_html__( 'We go over everything that was built, load your products once you have them ready, and go over the site with you before it goes live.', 'oc-theme' ) . '</p>'
				. '<p class="small">' . esc_html__( 'Something to change? Write to us and we will do it — no need to fill anything in again.', 'oc-theme' ) . '</p>'
			);
		}

		$sum   = Rest::report_summary( $report );
		$rows  = '';
		$words = array(
			'applied' => __( 'Applied', 'oc-theme' ),
			'check'   => __( 'Check', 'oc-theme' ),
			'manual'  => __( 'Left as is', 'oc-theme' ),
			'skipped' => __( 'Skipped', 'oc-theme' ),
			'error'   => __( 'Error', 'oc-theme' ),
		);

		foreach ( $report as $row ) {
			if ( 'applied' === $row['result'] ) {
				continue;
			}

			$rows .= '<tr><td>' . esc_html( (string) $row['label'] ) . '</td><td>' . esc_html( (string) $row['target'] ) . '</td><td>' . esc_html( $words[ $row['result'] ] ?? (string) $row['result'] ) . '</td><td>' . esc_html( (string) $row['note'] ) . '</td></tr>';
		}

		$body = '<p>' . esc_html( sprintf( /* translators: 1: client, 2: site */ __( '%1$s finished the questionnaire on %2$s. The answers are applied.', 'oc-theme' ), (string) $state['client']['name'], home_url( '/' ) ) ) . '</p>'
			. '<p><strong>' . esc_html__( 'What to do now', 'oc-theme' ) . '</strong></p><ul>' . self::my_turn( $sum, $todo ) . '</ul>'
			. '<p>' . esc_html( sprintf( /* translators: 1..5 counts */ __( 'Applied %1$d · to check %2$d · left as is %3$d · skipped %4$d · errors %5$d', 'oc-theme' ), $sum['applied'], $sum['check'], $sum['manual'], $sum['skipped'], $sum['error'] ) ) . '</p>'
			. ( $rows ? '<table cellpadding="6" style="border-collapse:collapse;font-size:14px"><tr><th>' . esc_html__( 'Field', 'oc-theme' ) . '</th><th>' . esc_html__( 'Target', 'oc-theme' ) . '</th><th>' . esc_html__( 'Result', 'oc-theme' ) . '</th><th>' . esc_html__( 'Note', 'oc-theme' ) . '</th></tr>' . $rows . '</table>' : '' )
			. self::button( admin_url( 'admin.php?page=oc-onboard' ), __( 'The full report', 'oc-theme' ) );

		self::send( Onboard::COPY_TO, sprintf( /* translators: %s: site host */ __( 'Questionnaire done: %s', 'oc-theme' ), self::host() ), $body, false );
	}

	/**
	 * What is ours to do once a questionnaire lands, in the order it wants
	 * doing. A report of counts says how it went; this says what to pick up.
	 *
	 * @param array<string,int> $sum  The report's counts.
	 * @param array<int,string> $todo What is theirs to do, so we know what to chase.
	 */
	private static function my_turn( array $sum, array $todo ): string {
		$out = array();
		$pay = (array) get_option( 'oc_onboard_pay', array() );
		$gw  = (string) ( $pay['gateway'] ?? '' );

		if ( $sum['error'] ) {
			$out[] = __( 'Something errored — read the report before anything else.', 'oc-theme' );
		}

		if ( $sum['check'] ) {
			/* translators: %d: how many rows are marked to check. */
			$out[] = sprintf( _n( '%d thing was written with a general text — read it over and make it theirs.', '%d things were written with a general text — read them over and make them theirs.', $sum['check'], 'oc-theme' ), $sum['check'] );
		}

		if ( 'none' === $gw ) {
			$out[] = __( 'Send them the PayPlus link so they can open an account. PayPlus is already installed on the site, switched off, waiting for the keys.', 'oc-theme' );
		} elseif ( 'other' === $gw ) {
			$out[] = __( 'They use a clearing company we do not install ourselves — get its details and connect it by hand. Both of our gateway plugins were taken off the site.', 'oc-theme' );
		} elseif ( ! empty( $pay['fill_later'] ) ) {
			$out[] = __( 'They left the clearing details for later. Chase them, put them in, and switch the gateway on.', 'oc-theme' );
		} else {
			$out[] = __( 'The clearing company is set up and on. Put a real order through it before the site goes live.', 'oc-theme' );
		}

		// They asked for the menu to stand on the banner and had no light
		// logo to give. The regular one is standing on the picture until
		// one exists, which on a dark photograph is a logo nobody can see.
		$light = (array) Draft::value( 'logo_light' );

		if ( 'home' === (string) Draft::value( 'home_header' ) && empty( $light['id'] ) ) {
			$out[] = __( 'Make the light version of their logo — they had none. The menu stands on their banner, so the regular logo is standing on the picture meanwhile. It goes in Customize, under the header.', 'oc-theme' );
		}

		$out[] = __( 'Go over what was built — the home page, the catalogue and the product page — and put the last touches to it.', 'oc-theme' );
		$out[] = __( 'Get their products and load them.', 'oc-theme' );

		if ( $todo ) {
			$out[] = __( 'Their own list is in the mail they received, so you both know what is waiting on whom.', 'oc-theme' );
		}

		$html = '';

		foreach ( $out as $one ) {
			$html .= '<li>' . esc_html( $one ) . '</li>';
		}

		return $html;
	}

	/* ------------------------------------------------------------ pieces */

	/**
	 * Send one HTML mail, copying the team unless told not to.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Inner HTML.
	 * @param bool   $copy    Copy the team.
	 */
	private static function send( string $to, string $subject, string $body, bool $copy = true ): bool {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( $copy && Onboard::COPY_TO !== $to ) {
			$headers[] = 'Cc: ' . Onboard::COPY_TO;
		}

		// Mail clients ignore dir on <html>; the direction rides the body and the card as inline style.
		$dir  = is_rtl() || 0 === strpos( get_locale(), 'he' ) ? 'rtl' : 'ltr';
		$al   = 'rtl' === $dir ? 'right' : 'left';
		$html = '<!doctype html><html dir="' . $dir . '" lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><body dir="' . $dir . '" style="margin:0;padding:24px;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#1d1d1f;direction:' . $dir . ';text-align:' . $al . '">'
			. '<div dir="' . $dir . '" style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:28px 28px 20px;line-height:1.6;font-size:16px;direction:' . $dir . ';text-align:' . $al . '">'
			. '<p style="margin:0 0 18px;font-weight:700;font-size:18px">' . esc_html( (string) get_bloginfo( 'name' ) ) . '</p>'
			. $body
			. '</div><style>.small{font-size:13px;color:#666}</style></body></html>';

		return wp_mail( $to, $subject, $html, $headers );
	}

	/**
	 * Hello, by name.
	 *
	 * @param string $name Client name.
	 */
	private static function greeting( string $name ): string {
		return '<p>' . esc_html( '' !== trim( $name ) ? sprintf( /* translators: %s: name */ __( 'Hi %s,', 'oc-theme' ), $name ) : __( 'Hi,', 'oc-theme' ) ) . '</p>';
	}

	/**
	 * A button.
	 *
	 * @param string $url   Link.
	 * @param string $label Words.
	 */
	private static function button( string $url, string $label ): string {
		return '<p style="margin:22px 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#0143a5;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * The site's host, for subjects.
	 */
	private static function host(): string {
		return (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	}

	/**
	 * The link the customer already holds.
	 */
	private static function link_for_mail(): string {
		return (string) Onboard::state()['link'];
	}
}
