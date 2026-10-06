<?php
/**
 * The customer's Drive folders, opened by Make once the answers are in.
 *
 * The site knows what the shop still lacks (a logo, pictures for six
 * categories, a terms file); Make owns the Drive. So when the apply
 * finishes the site tells Make what is missing, Make opens one folder per
 * thing under the project's folder, shares them, and answers with the
 * links. Only then does the customer's "what is left" mail go out, with an
 * "upload here" button on every line. Nothing goes to the customer with a
 * list and nowhere to put the files.
 *
 * If Make is slow or down the site keeps asking, every quarter of an hour,
 * and after two hours tells the team. The team can also paste the folder
 * link by hand on the onboarding screen; that counts as an answer too.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

defined( 'ABSPATH' ) || exit;

/**
 * Ask Make for the folders, keep the answer, chase it when it is late.
 */
final class Drive {

	const CRON     = 'oc_onboard_drive';
	const EVERY    = 'oc_quarter';
	const PATIENCE = 2 * HOUR_IN_SECONDS;

	/**
	 * Where a new shop registers and buys its domain.
	 */
	const LIVEDNS_REGISTER = 'https://domains.livedns.co.il/register.aspx';
	const LIVEDNS_BUY      = 'https://livedns.co.il/';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'quarter' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- fifteen minutes, deliberately.
		add_action( self::CRON, array( __CLASS__, 'retry' ) );
	}

	/**
	 * A quarter of an hour, for the retry.
	 *
	 * @param array<string,array<string,mixed>> $schedules WordPress's list.
	 * @return array<string,array<string,mixed>>
	 */
	public function quarter( array $schedules ): array {
		$schedules[ self::EVERY ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every fifteen minutes', 'oc-theme' ),
		);

		return $schedules;
	}

	/* ---------------------------------------------------------------- state */

	/**
	 * The folders as we know them: root, one per key, the domain document.
	 *
	 * @return array{root:string,folders:array<string,string>,doc:string,when:int}
	 */
	public static function links(): array {
		$d = (array) ( Onboard::state()['drive'] ?? array() );

		return array(
			'root'    => (string) ( $d['root'] ?? '' ),
			'folders' => (array) ( $d['folders'] ?? array() ),
			'doc'     => (string) ( $d['doc'] ?? '' ),
			'when'    => (int) ( $d['when'] ?? 0 ),
		);
	}

	/**
	 * Do we have somewhere to send them?
	 */
	public static function ready(): bool {
		return '' !== self::links()['root'];
	}

	/**
	 * The folder for one kind of thing, falling back to the root.
	 *
	 * @param string $key logo | catpics | legal | about | products.
	 */
	public static function folder( string $key ): string {
		$l = self::links();

		return (string) ( $l['folders'][ $key ] ?? $l['root'] );
	}

	/**
	 * Which folders this shop needs, read off its gaps.
	 *
	 * The products folder always: nobody has their products to hand while
	 * answering. The domain document always: a new shop writes its new
	 * registrar login in it, an existing shop its current one.
	 *
	 * @return array<int,string>
	 */
	public static function needs(): array {
		$keys = array( 'products' );

		foreach ( Onboard::gaps() as $g ) {
			switch ( $g['key'] ) {
				case 'logo':
				case 'logolight':
					$keys[] = 'logo';
					break;
				case 'catpics':
					$keys[] = 'catpics';
					break;
				case 'terms':
				case 'privacy':
				case 'a11y':
					if ( in_array( $g['cause'], array( 'no_file', 'link_failed' ), true ) ) {
						$keys[] = 'legal';
					}
					break;
				case 'about':
					if ( 'link_failed' === $g['cause'] ) {
						$keys[] = 'about';
					}
					break;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/* -------------------------------------------------------------- the ask */

	/**
	 * Tell Make the answers are in and ask for the folders.
	 *
	 * Synchronous on purpose: Make answers the webhook with the links in
	 * the same call. A good answer stores the links and lets the customer
	 * mail out; anything else schedules the retry.
	 *
	 * @param array<string,string> $user The customer's login, if one was made.
	 */
	public static function request( array $user = array() ): bool {
		$hook = (string) ( Onboard::settings()['hook'] ?? '' );

		if ( '' === $hook ) {
			self::failed( 'no_hook' );

			return false;
		}

		$state = Onboard::state();
		$gaps  = array();

		foreach ( Onboard::gaps() as $g ) {
			$gaps[] = array(
				'key'   => $g['key'],
				'must'  => $g['must'],
				'label' => $g['label'],
				'cause' => $g['cause'],
				'items' => $g['items'],
			);
		}

		$body = array(
			'event'    => 'questionnaire_done',
			'site'     => home_url( '/' ),
			'host'     => wp_parse_url( home_url(), PHP_URL_HOST ),
			'admin'    => admin_url(),
			'quiz'     => home_url( '/quiz/' ),
			'client'   => $state['client'],
			'monday'   => $state['monday'],
			'existing' => 'yes' === (string) Draft::value( 'existing_has' ) ? 'yes' : 'no',
			'domain'   => (string) Draft::value( 'domain' ),
			'needs'    => self::needs(),
			'gaps'     => $gaps,
			'user'     => $user,
		);

		$res = wp_remote_post(
			$hook,
			array(
				'timeout' => 35,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			self::failed( $res->get_error_message() );

			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );

		if ( $code >= 300 || ! is_array( $data ) ) {
			self::failed( 'http ' . $code );

			return false;
		}

		return self::accept( $data );
	}

	/**
	 * Keep what Make (or a person) answered, if it holds a folder.
	 *
	 * @param array<string,mixed> $data root, folders{key: url}, doc.
	 */
	public static function accept( array $data ): bool {
		$ok   = static function ( $url ): string {
			$url = esc_url_raw( trim( (string) $url ) );

			return 0 === strpos( $url, 'https://' ) ? $url : '';
		};
		$root = $ok( $data['root'] ?? '' );

		if ( '' === $root ) {
			self::failed( 'no_root' );

			return false;
		}

		$folders = array();

		foreach ( (array) ( $data['folders'] ?? array() ) as $key => $url ) {
			$key = sanitize_key( (string) $key );
			$url = $ok( $url );

			if ( '' !== $key && '' !== $url ) {
				$folders[ $key ] = $url;
			}
		}

		Onboard::patch_state(
			array(
				'drive'      => array(
					'root'    => $root,
					'folders' => $folders,
					'doc'     => $ok( $data['doc'] ?? '' ),
					'when'    => time(),
				),
				'drive_fail' => 0,
				'drive_why'  => '',
			)
		);

		wp_clear_scheduled_hook( self::CRON );

		self::mail_customer();

		return true;
	}

	/**
	 * Note the miss, line up the next try, and after two hours say so.
	 *
	 * @param string $why What went wrong, for the screen.
	 */
	private static function failed( string $why ): void {
		$state = Onboard::state();
		$fails = (int) ( $state['drive_fail'] ?? 0 ) + 1;
		$patch = array(
			'drive_fail' => $fails,
			'drive_try'  => time(),
			'drive_why'  => sanitize_text_field( $why ),
		);

		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, self::EVERY, self::CRON );
		}

		$since = (int) ( $state['applied'] ?? 0 );

		if ( $since && ( time() - $since ) >= self::PATIENCE && empty( $state['drive_alert'] ) ) {
			Mail::drive_late( $fails, $why );
			$patch['drive_alert'] = time();
		}

		Onboard::patch_state( $patch );
	}

	/**
	 * The cron's try: only while the answers are in and the folders are not.
	 */
	public static function retry(): void {
		$state = Onboard::state();

		if ( 'applied' !== (string) $state['status'] || self::ready() ) {
			wp_clear_scheduled_hook( self::CRON );

			return;
		}

		self::request( array( 'login' => (string) ( $state['user'] ?? '' ) ) );
	}

	/**
	 * The customer's mail, once, and only with somewhere to upload to.
	 */
	public static function mail_customer(): void {
		$state = Onboard::state();

		if ( ! empty( $state['mailed'] ) || ! self::ready() ) {
			return;
		}

		if ( Mail::done_customer() ) {
			Onboard::patch_state( array( 'mailed' => time() ) );
		}
	}
}
