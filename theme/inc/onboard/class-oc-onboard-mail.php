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
	 * The name on every mail, the address replies go to, and the pictures
	 * the signature wears (the same files Monday's mails use).
	 */
	const FROM_NAME = 'Original Concepts';
	const REPLY_TO  = 'george@originalconcepts.co.il';
	const LOGO      = 'https://drive.google.com/thumbnail?export=download&id=1DRFqEt3a0l-kDo0mR66Zlt_xNaQaHe3i';
	const PHOTO     = 'https://drive.google.com/thumbnail?export=download&id=1vf48ZJobbFRltM4FGS1D0U_v2Ko3a1px';
	const SIGN_NAME = 'ג׳ורג׳ שופאני';
	const SIGN_ROLE = 'בעלים ומנכ"ל';
	const SIGN_TEL  = '0774-510511 | 0544-570027';

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
			. '<p>' . esc_html__( 'My team and I welcome you to the Original Concepts family 👏. We have walked hundreds of businesses through opening a store that took their activity and their profits further, and we are glad to do the same for yours.', 'oc-theme' ) . '</p>'
			. '<p>' . wp_kses( __( 'The first step is <strong>filling in the site questionnaire</strong>. It takes about a quarter of an hour, and everything is saved as you go — you can stop and come back from your phone or your computer.', 'oc-theme' ), array( 'strong' => array() ) ) . '</p>'
			. self::button( $url, __( 'Click to start the questionnaire', 'oc-theme' ), '#ff7403' )
			. '<p>' . esc_html__( 'Once the questionnaire is in, we send you a link to a Drive folder for your pictures and files.', 'oc-theme' ) . '</p>'
			. '<p>' . esc_html__( 'Looking forward — the sooner the questionnaire is in, the sooner we start! 🚀', 'oc-theme' ) . '</p>'
			. '<p class="small">' . esc_html__( 'The link is personal; please do not forward it.', 'oc-theme' ) . '</p>';

		return self::note( 'invite', 'customer', self::send( $to, __( 'Here we go!!! 🚀', 'oc-theme' ), $body ) );
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

		return self::note( 'reminder' . $n, 'customer', self::send( $to, $subject, $body ) );
	}

	/**
	 * Day four: the customer went quiet, and the team gets everything it
	 * needs to pick up the phone — who, how far they got, their own link,
	 * and what to do with it.
	 */
	public static function nudge_team(): bool {
		$state = Onboard::state();
		$prog  = Draft::progress();
		$link  = self::link_for_mail();
		$name  = (string) $state['client']['name'];
		$pct   = $prog['total'] > 0 ? (int) round( 100 * $prog['answered'] / $prog['total'] ) : 0;
		$seen  = (int) $state['activity'];

		$steps = array(
			esc_html__( 'Call the customer today. Four days of quiet usually means a question they are stuck on, or the mail went to spam.', 'oc-theme' ),
			esc_html__( 'Offer to fill it in together, on the call: open their link on your computer and go through the screens with them. Everything is saved as you go.', 'oc-theme' ),
			esc_html__( 'Rather do it on their own? Send them the link on WhatsApp — the button below opens a chat with the message ready.', 'oc-theme' ),
			esc_html__( 'Or send the invitation mail again from the onboarding screen.', 'oc-theme' ),
		);

		$wa = self::whatsapp(
			(string) $state['client']['phone'],
			sprintf(
				/* translators: 1: the customer's first name, 2: the questionnaire link. */
				__( 'Hi %1$s, here is your personal link to the questionnaire for your new store: %2$s — everything is saved as you go.', 'oc-theme' ),
				self::first_name( $name ),
				$link
			)
		);

		$body = '<p>' . esc_html( sprintf( /* translators: 1: client name, 2: site */ __( '%1$s has not progressed with the questionnaire on %2$s for four days.', 'oc-theme' ), $name, home_url( '/' ) ) ) . '</p>'
			. self::client_block()
			. '<p>' . esc_html( sprintf( /* translators: 1: answered, 2: total, 3: percent */ __( 'Answered: %1$d of %2$d (%3$d%%).', 'oc-theme' ), $prog['answered'], $prog['total'], $pct ) )
			. ' ' . esc_html( $seen ? sprintf( /* translators: %s: how long ago */ __( 'Last activity %s ago.', 'oc-theme' ), human_time_diff( $seen ) ) : __( 'The link was never opened.', 'oc-theme' ) ) . '</p>'
			. ( '' !== $link ? '<p>' . esc_html__( 'Their personal questionnaire link (the same one as in Monday\'s "Questionnaire link" column):', 'oc-theme' ) . '<br><a href="' . esc_url( $link ) . '" dir="ltr" style="word-break:break-all">' . esc_html( $link ) . '</a></p>' : '' )
			. self::what_now( $steps )
			. ( '' !== $wa && '' !== $link ? self::button( $wa, __( 'Send the link on WhatsApp', 'oc-theme' ), '#25d366' ) : '' )
			. self::button( add_query_arg( 'oc_ask', 'resend', Admin::url() ), __( 'Send the invitation again', 'oc-theme' ) );

		return self::note( 'quiet', 'team', self::send( Onboard::COPY_TO, sprintf( /* translators: %s: site host */ __( 'No progress: %s', 'oc-theme' ), self::host() ), $body, false ) );
	}

	/**
	 * After the apply: the customer gets a thank-you, the team the report.
	 *
	 * @param array<int,array<string,string>> $report Rows.
	 */
	public static function done_team( array $report ): void {
		self::note( 'report', 'team', self::send( Onboard::COPY_TO, sprintf( /* translators: %s: site host */ __( 'Questionnaire done: %s', 'oc-theme' ), self::host() ), self::team_done_html( $report ), false ) );
	}

	/**
	 * The customer's list, with somewhere to upload each thing. Sent by
	 * Drive once the folders exist, never before.
	 */
	public static function done_customer(): bool {
		$to = (string) Onboard::state()['client']['email'];

		if ( ! is_email( $to ) ) {
			return false;
		}

		return self::note( 'done', 'customer', self::send( $to, __( 'We got it — your site is being built', 'oc-theme' ), self::customer_done_html() ) );
	}

	/**
	 * Two hours after the answers went in and still no folders: the team
	 * hears, once, and knows where the button is.
	 *
	 * @param int    $tries How many times the site asked.
	 * @param string $why   What the last miss said.
	 */
	public static function drive_late( int $tries, string $why ): bool {
		$state = Onboard::state();

		$body = '<p>' . esc_html( sprintf( /* translators: 1: client name, 2: site */ __( '%1$s finished the questionnaire on %2$s two hours ago, and the Drive folders are still not open.', 'oc-theme' ), (string) $state['client']['name'], home_url( '/' ) ) ) . '</p>'
			. '<p>' . esc_html( sprintf( /* translators: 1: how many tries, 2: the last error */ __( 'The site asked Make %1$d times; the last answer was: %2$s. It keeps trying every fifteen minutes.', 'oc-theme' ), $tries, '' === $why ? '—' : $why ) ) . '</p>'
			. '<p>' . esc_html__( 'The customer has not received their list yet — it waits for the folders.', 'oc-theme' ) . '</p>'
			. self::client_block()
			. self::what_now(
				array(
					esc_html__( 'On the onboarding screen press "Ask Make again now". If Make answers, the customer\'s mail goes out by itself.', 'oc-theme' ),
					esc_html__( 'Still nothing? Open the Make scenario\'s history and look for the error.', 'oc-theme' ),
					esc_html__( 'Or open the folders in Drive by hand and paste the link on the onboarding screen — the customer\'s mail goes out the moment it is saved.', 'oc-theme' ),
				)
			)
			. self::button( Admin::url(), __( 'Open the onboarding screen', 'oc-theme' ) );

		return self::note( 'late', 'team', self::send( Onboard::COPY_TO, sprintf( /* translators: %s: site host */ __( 'Drive folders late: %s', 'oc-theme' ), self::host() ), $body, false ) );
	}

	/**
	 * Remember that a mail went, so the screen can list them.
	 *
	 * @param string $key  invite | reminder1 | reminder2 | quiet | report | done | late.
	 * @param string $to   customer | team.
	 * @param bool   $sent What wp_mail said.
	 */
	private static function note( string $key, string $to, bool $sent ): bool {
		if ( $sent ) {
			$list   = (array) ( Onboard::state()['mails'] ?? array() );
			$list[] = array(
				'key'  => $key,
				'to'   => $to,
				'when' => time(),
			);
			Onboard::patch_state( array( 'mails' => $list ) );
		}

		return $sent;
	}

	/**
	 * The words for the screen, by key.
	 *
	 * @return array<string,string>
	 */
	public static function names(): array {
		return array(
			'invite'    => __( 'The invitation, with the link', 'oc-theme' ),
			'reminder1' => __( 'Reminder, day one', 'oc-theme' ),
			'reminder2' => __( 'Reminder, day three', 'oc-theme' ),
			'quiet'     => __( 'No progress for four days (to the team)', 'oc-theme' ),
			'report'    => __( 'The questionnaire is done (to the team)', 'oc-theme' ),
			'done'      => __( 'What is left for you, with the Drive folders', 'oc-theme' ),
			'late'      => __( 'The Drive folders are late (to the team)', 'oc-theme' ),
		);
	}

	/**
	 * The customer's thank-you, with what is theirs to do.
	 */
	public static function customer_done_html(): string {
		$state = Onboard::state();
		$mine  = '';

		foreach ( Onboard::todo() as $one ) {
			$url  = $one['url'];
			$link = $one['link'];

			// A thing that wants a file gets the folder it goes in.
			if ( '' === $url && '' !== $one['folder'] ) {
				$url  = Drive::folder( $one['folder'] );
				$link = __( 'Upload here', 'oc-theme' );
			}

			$mine .= '<li style="margin:0 0 10px">' . esc_html( $one['text'] )
				. ( '' === $url ? '' : ' <a href="' . esc_url( $url ) . '" style="font-weight:600;white-space:nowrap">' . esc_html( $link ) . ' &rsaquo;</a>' )
				. '</li>';
		}

		$root = Drive::links()['root'];

		return self::greeting( (string) $state['client']['name'] )
			. '<p>' . esc_html__( 'Thank you! Everything you answered is already written into your site.', 'oc-theme' ) . '</p>'
			. ( '' === $mine ? '' : '<p><strong>' . esc_html__( 'What is left for you', 'oc-theme' ) . '</strong></p><ul>' . $mine . '</ul>' )
			. ( '' === $root ? '' : '<p>' . esc_html__( 'All the folders sit in one shared Drive folder; anyone with the link can upload to it.', 'oc-theme' ) . ' <a href="' . esc_url( $root ) . '" style="font-weight:600">' . esc_html__( 'Your Drive folder', 'oc-theme' ) . ' &rsaquo;</a></p>' )
			. '<p>' . esc_html__( 'We go over everything that was built, load your products once you have them ready, and go over the site with you before it goes live.', 'oc-theme' ) . '</p>'
			. '<p class="small">' . esc_html__( 'Something to change? Write to us and we will do it — no need to fill anything in again.', 'oc-theme' ) . '</p>';
	}

	/**
	 * The team's report: what to pick up, then how the apply went.
	 *
	 * @param array<int,array<string,string>> $report Rows.
	 */
	public static function team_done_html( array $report ): string {
		$state = Onboard::state();
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

		return '<p>' . esc_html( sprintf( /* translators: 1: client, 2: site */ __( '%1$s finished the questionnaire on %2$s. The answers are applied.', 'oc-theme' ), (string) $state['client']['name'], home_url( '/' ) ) ) . '</p>'
			. self::my_turn( $sum )
			. '<p>' . esc_html( sprintf( /* translators: 1..5 counts */ __( 'Applied %1$d · to check %2$d · left as is %3$d · skipped %4$d · errors %5$d', 'oc-theme' ), $sum['applied'], $sum['check'], $sum['manual'], $sum['skipped'], $sum['error'] ) ) . '</p>'
			. ( $rows ? '<table cellpadding="6" style="border-collapse:collapse;font-size:14px"><tr><th>' . esc_html__( 'Field', 'oc-theme' ) . '</th><th>' . esc_html__( 'Target', 'oc-theme' ) . '</th><th>' . esc_html__( 'Result', 'oc-theme' ) . '</th><th>' . esc_html__( 'Note', 'oc-theme' ) . '</th></tr>' . $rows . '</table>' : '' )
			. self::client_block()
			. self::what_now(
				array(
					esc_html__( 'Open the full report and go over every row marked "Check" or "Error".', 'oc-theme' ),
					esc_html__( 'Call the customer: thank them, and go over what is left for them — the clearing account and the domain first, they take days.', 'oc-theme' ),
					esc_html__( 'Their list goes out with the Drive folders. If the folders are not open within two hours, a mail says so.', 'oc-theme' ),
				)
			)
			. self::button( Admin::url(), __( 'The full report', 'oc-theme' ) );
	}

	/**
	 * What is ours to do once a questionnaire lands: what the apply flagged,
	 * then what still stands between the shop and going live — read off the
	 * site, the same list the dashboard tile shows — and the standing work.
	 *
	 * @param array<string,int> $sum The report's counts.
	 */
	private static function my_turn( array $sum ): string {
		$html = '';
		$li   = static function ( string $text ): string {
			return '<li style="margin:0 0 6px">' . $text . '</li>';
		};

		$first = '';

		if ( $sum['error'] ) {
			$first .= $li( esc_html__( 'Something errored — read the report before anything else.', 'oc-theme' ) );
		}

		if ( $sum['check'] ) {
			/* translators: %d: how many rows are marked to check. */
			$first .= $li( esc_html( sprintf( _n( '%d thing was written with a general text — read it over and make it theirs.', '%d things were written with a general text — read them over and make them theirs.', $sum['check'], 'oc-theme' ), $sum['check'] ) ) );
		}

		if ( '' !== $first ) {
			$html .= '<p><strong>' . esc_html__( 'From the apply', 'oc-theme' ) . '</strong></p><ul>' . $first . '</ul>';
		}

		$gaps = Onboard::gaps();
		$row  = static function ( array $g ) use ( $li ): string {
			return $li(
				'<strong>' . esc_html( $g['label'] ) . '</strong>'
				. ( '' === $g['why'] ? '' : ' — ' . esc_html( $g['why'] ) )
				. ( '' === $g['fix'] ? '' : ' <a href="' . esc_url( $g['fix'] ) . '">' . esc_html__( 'Put it right', 'oc-theme' ) . '</a>' )
			);
		};

		$must = '';
		$nice = '';

		foreach ( $gaps as $g ) {
			if ( $g['must'] ) {
				$must .= $row( $g );
			} else {
				$nice .= $row( $g );
			}
		}

		if ( '' !== $must ) {
			$html .= '<p><strong style="color:#b32d2e">' . esc_html__( 'Cannot go live without', 'oc-theme' ) . '</strong></p><ul>' . $must . '</ul>';
		}

		if ( '' !== $nice ) {
			$html .= '<p><strong>' . esc_html__( 'Worth doing', 'oc-theme' ) . '</strong></p><ul>' . $nice . '</ul>';
		}

		if ( ! $gaps ) {
			$html .= '<p><strong style="color:#1a7f37">' . esc_html__( 'Everything is in place.', 'oc-theme' ) . '</strong></p>';
		}

		$html .= '<p><strong>' . esc_html__( 'And always', 'oc-theme' ) . '</strong></p><ul>'
			. $li( esc_html__( 'Go over what was built — the home page, the catalogue and the product page — and put the last touches to it.', 'oc-theme' ) )
			. $li( esc_html__( 'Their own list is in the mail they received, so you both know what is waiting on whom.', 'oc-theme' ) )
			. '</ul>';

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
		// Our name on the envelope, replies to George; the address itself
		// stays the site's, so the server's SPF and the DKIM key hold.
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::FROM_NAME . ' <' . self::from_address() . '>',
			'Reply-To: ' . self::FROM_NAME . ' <' . self::REPLY_TO . '>',
		);

		if ( $copy && Onboard::COPY_TO !== $to ) {
			$headers[] = 'Cc: ' . Onboard::COPY_TO;
		}

		// Mail clients ignore dir on <html>; the direction rides the body and the card as inline style.
		$dir  = is_rtl() || 0 === strpos( get_locale(), 'he' ) ? 'rtl' : 'ltr';
		$al   = 'rtl' === $dir ? 'right' : 'left';
		$html = '<!doctype html><html dir="' . $dir . '" lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><body dir="' . $dir . '" style="margin:0;padding:24px;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#1d1d1f;direction:' . $dir . ';text-align:' . $al . '">'
			. '<div dir="' . $dir . '" style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:28px 28px 20px;line-height:1.6;font-size:16px;direction:' . $dir . ';text-align:' . $al . '">'
			. '<p style="margin:0 0 18px;text-align:center"><img src="' . esc_url( self::LOGO ) . '" alt="' . esc_attr( self::FROM_NAME ) . '" style="height:90px;max-width:100%"></p>'
			. '<hr style="border:none;border-top:1px solid #d1d1d1;margin:0 0 18px">'
			. $body
			. ( $copy ? self::signature( $al ) : '' )
			. '</div><style>.small{font-size:13px;color:#666}</style></body></html>';

		return wp_mail( $to, $subject, $html, $headers );
	}

	/**
	 * The address the mail leaves from: WordPress's own on this host, so
	 * the server signs it. Only the name is ours.
	 */
	private static function from_address(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;

		return 'wordpress@' . $host;
	}

	/**
	 * George, at the foot of every mail to a customer.
	 *
	 * @param string $al left | right.
	 */
	private static function signature( string $al ): string {
		return '<p style="margin:18px 0 6px">' . esc_html__( 'Thank you,', 'oc-theme' ) . '</p>'
			. '<table cellpadding="0" cellspacing="0" border="0" style="margin-top:4px"><tr>'
			. '<td style="vertical-align:top;width:90px"><img src="' . esc_url( self::PHOTO ) . '" alt="" style="width:80px;height:80px;border-radius:50%"></td>'
			. '<td style="vertical-align:top;padding-' . ( 'right' === $al ? 'right' : 'left' ) . ':14px;font-size:15px;line-height:1.5;text-align:' . $al . '">'
			. '<strong style="font-size:17px">' . esc_html( self::SIGN_NAME ) . '</strong><br>' . esc_html( self::SIGN_ROLE ) . '<br><span dir="ltr">' . esc_html( self::SIGN_TEL ) . '</span>'
			. '</td></tr></table>';
	}

	/**
	 * Who the customer is, for a mail to the team: name, a phone that dials
	 * when tapped, an email that opens a new mail.
	 */
	private static function client_block(): string {
		$c     = (array) Onboard::state()['client'];
		$name  = (string) ( $c['name'] ?? '' );
		$phone = (string) ( $c['phone'] ?? '' );
		$email = (string) ( $c['email'] ?? '' );
		$dial  = preg_replace( '/[^0-9+]/', '', $phone );
		$lines = array();

		if ( '' !== $name ) {
			$lines[] = '<strong>' . esc_html( $name ) . '</strong>';
		}

		if ( '' !== $phone ) {
			$lines[] = esc_html__( 'Phone:', 'oc-theme' ) . ' <a href="' . esc_url( 'tel:' . $dial, array( 'tel' ) ) . '" dir="ltr">' . esc_html( $phone ) . '</a>';
		}

		if ( '' !== $email ) {
			$lines[] = esc_html__( 'Email:', 'oc-theme' ) . ' <a href="' . esc_url( 'mailto:' . $email, array( 'mailto' ) ) . '" dir="ltr">' . esc_html( $email ) . '</a>';
		}

		if ( ! $lines ) {
			return '';
		}

		return '<p style="margin:16px 0;padding:12px 14px;background:#f5f7fb;border-radius:8px"><span class="small">' . esc_html__( 'The customer', 'oc-theme' ) . '</span><br>' . implode( '<br>', $lines ) . '</p>';
	}

	/**
	 * The closing block of a team mail: what to do now, in order.
	 *
	 * @param array<int,string> $steps Each step, already escaped.
	 */
	private static function what_now( array $steps ): string {
		$li = '';

		foreach ( $steps as $step ) {
			$li .= '<li style="margin:0 0 8px">' . $step . '</li>';
		}

		return '<p style="margin:22px 0 6px"><strong>' . esc_html_x( 'What to do now', 'team mail heading', 'oc-theme' ) . '</strong></p><ol style="margin:0;padding:0 22px">' . $li . '</ol>';
	}

	/**
	 * A WhatsApp chat with the customer, the message already typed. An
	 * Israeli number written the local way (05x…) becomes 9725x….
	 *
	 * @param string $phone The number as the customer gave it.
	 * @param string $text  The message.
	 */
	private static function whatsapp( string $phone, string $text ): string {
		$digits = (string) preg_replace( '/\D/', '', $phone );

		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( 0 === strpos( $digits, '0' ) ) {
			$digits = '972' . substr( $digits, 1 );
		}

		if ( strlen( $digits ) < 9 ) {
			return '';
		}

		return 'https://wa.me/' . $digits . '?text=' . rawurlencode( $text );
	}

	/**
	 * The first name: a mail says "Hi Dana", not "Hi Dana Cohen".
	 *
	 * @param string $name The whole name.
	 */
	private static function first_name( string $name ): string {
		return trim( (string) strtok( trim( $name ), ' ' ) );
	}

	/**
	 * Hello, by name.
	 *
	 * @param string $name Client name.
	 */
	private static function greeting( string $name ): string {
		$first = self::first_name( $name );

		return '<p>' . esc_html( '' !== $first ? sprintf( /* translators: %s: name */ __( 'Hi %s,', 'oc-theme' ), $first ) : __( 'Hi,', 'oc-theme' ) ) . '</p>';
	}

	/**
	 * A button.
	 *
	 * @param string $url   Link.
	 * @param string $label Words.
	 * @param string $bg    Its colour.
	 */
	private static function button( string $url, string $label, string $bg = '#0143a5' ): string {
		return '<p style="margin:26px 0;text-align:center"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . esc_attr( $bg ) . ';color:#fff;text-decoration:none;padding:13px 26px;border-radius:6px;font-weight:700;font-size:16px">' . esc_html( $label ) . '</a></p>';
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
