<?php
/**
 * The questionnaire's REST routes.
 *
 * Nothing here uses a cookie or a nonce: the page can sit open for days,
 * so every call carries the invitation token instead, and the invite
 * route carries the provisioning secret. A rate limit per address keeps a
 * script from filling the draft or the media library.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under oc/v1/onboard.
 */
final class Rest {

	const NS = 'oc/v1';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * The routes.
	 */
	public function routes(): void {
		register_rest_route(
			self::NS,
			'/onboard/invite',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permit_provision' ),
				'callback'            => array( $this, 'invite' ),
			)
		);

		register_rest_route(
			self::NS,
			'/onboard/schema',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'permit_token' ),
				'callback'            => array( $this, 'schema' ),
			)
		);

		register_rest_route(
			self::NS,
			'/onboard/draft',
			array(
				'methods'             => 'POST, PATCH',
				'permission_callback' => array( __CLASS__, 'permit_token' ),
				'callback'            => array( $this, 'draft' ),
			)
		);

		register_rest_route(
			self::NS,
			'/onboard/upload',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permit_token' ),
				'callback'            => array( $this, 'upload' ),
			)
		);

		register_rest_route(
			self::NS,
			'/onboard/discover',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permit_token' ),
				'callback'            => array( $this, 'discover' ),
			)
		);

		register_rest_route(
			self::NS,
			'/onboard/write',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permit_token' ),
				'callback'            => array( $this, 'write' ),
			)
		);

		register_rest_route(
			self::NS,
			'/onboard/submit',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permit_token' ),
				'callback'            => array( $this, 'submit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/onboard/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'permit_status' ),
				'callback'            => array( $this, 'status' ),
			)
		);
	}

	/* ------------------------------------------------------------ guards */

	/**
	 * The provisioning secret, in a header.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function permit_provision( \WP_REST_Request $req ): bool {
		return self::rate_ok( 'prov', 60 ) && Onboard::provision_ok( (string) $req->get_header( 'x-oc-provision' ) );
	}

	/**
	 * The invitation token.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function permit_token( \WP_REST_Request $req ): bool {
		// The token is the guard; the ceiling only stops a script hammering.
		// It has to be generous: the page saves on every change, a customer
		// may fill the questionnaire twice, and a whole office shares one
		// address — a real customer must never meet it.
		return Onboard::token_ok( Onboard::request_token( $req ) ) && self::rate_ok( 'tok', 5000 );
	}

	/**
	 * The token, or a signed-in manager.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function permit_status( \WP_REST_Request $req ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return self::permit_token( $req );
	}

	/**
	 * So many calls an hour from one address, per bucket.
	 *
	 * @param string $bucket Which limit.
	 * @param int    $limit  Calls per hour.
	 */
	private static function rate_ok( string $bucket, int $limit ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'oc_onb_rl_' . $bucket . '_' . md5( $ip );
		$now = time();
		$bag = get_transient( $key );

		// The window is anchored to its first request. Extending the life on
		// every hit, as this did at first, means the count never rolls over:
		// a busy hour locks the address out until a full quiet one passes.
		if ( ! is_array( $bag ) || empty( $bag['from'] ) || $now - (int) $bag['from'] >= HOUR_IN_SECONDS ) {
			$bag = array(
				'from' => $now,
				'n'    => 0,
			);
		}

		if ( (int) $bag['n'] >= $limit ) {
			return false;
		}

		++$bag['n'];

		set_transient( $key, $bag, HOUR_IN_SECONDS - ( $now - (int) $bag['from'] ) );

		return true;
	}

	/**
	 * Every answer is private: never cached, never indexed.
	 *
	 * @param array<string,mixed> $data Body.
	 * @param int                 $code Status.
	 */
	private static function answer( array $data, int $code = 200 ): \WP_REST_Response {
		$res = new \WP_REST_Response( $data, $code );

		$res->header( 'Cache-Control', 'no-store, private' );

		return $res;
	}

	/* ------------------------------------------------------------ routes */

	/**
	 * Mint the link. Called once by the script that clones the site.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function invite( \WP_REST_Request $req ): \WP_REST_Response {
		$url = Onboard::invite(
			array(
				'name'  => (string) $req->get_param( 'name' ),
				'phone' => (string) $req->get_param( 'phone' ),
				'email' => (string) $req->get_param( 'email' ),
			),
			array(
				'item' => (string) $req->get_param( 'monday_item' ),
			)
		);

		if ( $req->get_param( 'send' ) ) {
			Mail::invitation( $url );
		}

		return self::answer(
			array(
				'url'     => $url,
				'expires' => (int) Onboard::state()['expires'],
			)
		);
	}

	/**
	 * Write one answer for them, from what they have already told us.
	 *
	 * The words are handed back and not saved: the customer reads them in
	 * the field and decides. Saving is the ordinary draft call, the same as
	 * if they had typed it.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function write( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! self::rate_ok( 'write', 30 ) ) {
			return self::answer( array( 'error' => 'slow' ), 429 );
		}

		$text = Writer::write(
			sanitize_key( (string) $req->get_param( 'field' ) ),
			sanitize_textarea_field( (string) $req->get_param( 'tone' ) )
		);

		Onboard::touch();

		if ( is_wp_error( $text ) ) {
			return self::answer( array( 'error' => $text->get_error_message() ), 200 );
		}

		return self::answer( array( 'text' => $text ) );
	}

	/**
	 * Everything the page needs to draw itself.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function schema( \WP_REST_Request $req ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress's signature.
		$state = Onboard::state();

		if ( ! $state['opened'] ) {
			Onboard::touch();
		}

		return self::answer(
			array(
				'schema'   => Schema::for_js(),
				'values'   => Draft::values(),
				'status'   => (string) $state['status'],
				'step'     => (string) $state['step'],
				'progress' => Draft::progress(),
			)
		);
	}

	/**
	 * Save a few answers.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function draft( \WP_REST_Request $req ): \WP_REST_Response {
		$fields = $req->get_param( 'fields' );
		$stored = is_array( $fields ) ? Draft::set( $fields ) : array();

		Onboard::touch( sanitize_key( (string) $req->get_param( 'step' ) ) );

		return self::answer(
			array(
				'saved'    => $stored,
				'at'       => time(),
				'progress' => Draft::progress(),
			)
		);
	}

	/**
	 * One file into the media library.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function upload( \WP_REST_Request $req ): \WP_REST_Response {
		$field = sanitize_key( (string) $req->get_param( 'field' ) );
		$sub   = sanitize_key( (string) $req->get_param( 'sub' ) );
		$row   = (int) $req->get_param( 'row' );
		$f     = Schema::field( $field );

		if ( ! $f ) {
			return self::answer( array( 'error' => 'field' ), 400 );
		}

		// A picture inside a repeater row names the row and the sub-field.
		if ( '' !== $sub ) {
			if ( 'repeater' !== $f['type'] || empty( $f['fields'][ $sub ] ) || 'file' !== ( $f['fields'][ $sub ]['type'] ?? '' ) ) {
				return self::answer( array( 'error' => 'field' ), 400 );
			}

			$f = $f['fields'][ $sub ];
		} elseif ( 'file' !== $f['type'] ) {
			return self::answer( array( 'error' => 'field' ), 400 );
		}

		$files = $req->get_file_params();

		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return self::answer( array( 'error' => 'nofile' ), 400 );
		}

		$file = $files['file'];

		// A film is heavier than anything else a questionnaire asks for.
		$cap = 'video' === $f['accept'] ? 32 : 8;

		if ( (int) ( $file['size'] ?? 0 ) > $cap * MB_IN_BYTES ) {
			return self::answer( array( 'error' => 'size' ), 413 );
		}

		$images = array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'gif'      => 'image/gif',
			'svg'      => 'image/svg+xml',
		);

		if ( 'image' === $f['accept'] ) {
			$mimes = $images;
		} elseif ( 'video' === $f['accept'] ) {
			// A customer with no film yet is told to put a picture here, so
			// the field takes both and the page uses whichever it was given.
			$mimes = array_merge(
				$images,
				array(
					'mp4'  => 'video/mp4',
					'm4v'  => 'video/mp4',
					'webm' => 'video/webm',
				)
			);
		} else {
			$mimes = array(
				'pdf'  => 'application/pdf',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'doc'  => 'application/msword',
				'txt'  => 'text/plain',
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// A logo may be an SVG; scripts inside one have no business on a site.
		if ( 'image' === $f['accept'] && 0 === strpos( (string) ( $file['type'] ?? '' ), 'image/svg' ) && ! empty( $file['tmp_name'] ) ) {
			$svg = (string) file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the upload itself.
			$svg = (string) preg_replace( '~<script\b[^>]*>.*?</script>~is', '', $svg );
			$svg = (string) preg_replace( '~\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')~i', '', $svg );
			$svg = (string) preg_replace( '~<foreignObject\b[^>]*>.*?</foreignObject>~is', '', $svg );
			file_put_contents( (string) $file['tmp_name'], $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the same temp file.
		}

		$_FILES['oc_onboard_file'] = $file; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- token-guarded route.

		$id = media_handle_upload(
			'oc_onboard_file',
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);

		unset( $_FILES['oc_onboard_file'] );

		if ( is_wp_error( $id ) ) {
			return self::answer( array( 'error' => $id->get_error_message() ), 415 );
		}

		$id   = (int) $id;
		$one  = array(
			'id'   => $id,
			'name' => (string) ( $file['name'] ?? '' ),
		);
		$kept = null;

		if ( '' !== $sub ) {
			$rows = (array) Draft::value( $field );

			if ( ! isset( $rows[ $row ] ) || ! is_array( $rows[ $row ] ) ) {
				$rows[ $row ] = array();
			}

			$rows[ $row ][ $sub ] = $one;
			$saved                = Draft::set( array( $field => $rows ) );
			$kept                 = $saved[ $field ][ $row ][ $sub ] ?? null;
		} else {
			$saved = Draft::set( array( $field => $one ) );
			$kept  = $saved[ $field ] ?? null;
		}

		Onboard::touch();

		$thumb = wp_get_attachment_image_url( $id, 'medium' );

		return self::answer(
			array(
				'file'  => $kept,
				'thumb' => $thumb ? $thumb : '',
			)
		);
	}

	/**
	 * The four content pages, found on the site the customer already has,
	 * so nobody has to hunt for an address.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function discover( \WP_REST_Request $req ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress's signature.
		$home = (string) Draft::value( 'existing_url' );

		if ( '' === trim( $home ) ) {
			return self::answer( array( 'found' => array() ) );
		}

		$key   = 'oc_onb_disc_' . md5( $home );
		$found = get_transient( $key );

		if ( ! is_array( $found ) ) {
			$found = Fetch::discover( $home );
			set_transient( $key, $found, DAY_IN_SECONDS );
		}

		return self::answer( array( 'found' => $found ) );
	}

	/**
	 * "I'm done": check, lock, write, tell everyone.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function submit( \WP_REST_Request $req ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress's signature.
		$values = array();

		foreach ( Schema::fields() as $id => $f ) {
			$values[ $id ] = Draft::value( $id );
		}

		$missing = Schema::missing( $values );

		if ( $missing ) {
			return self::answer(
				array(
					'error'   => 'missing',
					'missing' => $missing,
				),
				422
			);
		}

		Onboard::patch_state(
			array(
				'status'    => 'submitted',
				'submitted' => time(),
				'activity'  => time(),
			)
		);

		$report = ( new Apply() )->run();

		Onboard::patch_state(
			array(
				'status'  => 'applied',
				'applied' => time(),
				'report'  => $report,
			)
		);

		Mail::done( $report );

		return self::answer(
			array(
				'ok'     => true,
				'report' => self::report_summary( $report ),
				'todo'   => Onboard::todo(),
			)
		);
	}

	/**
	 * Where things stand.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function status( \WP_REST_Request $req ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress's signature.
		$state = Onboard::state();

		return self::answer(
			array(
				'status'    => (string) $state['status'],
				'created'   => (int) $state['created'],
				'expires'   => (int) $state['expires'],
				'opened'    => (int) $state['opened'],
				'activity'  => (int) $state['activity'],
				'step'      => (string) $state['step'],
				'submitted' => (int) $state['submitted'],
				'applied'   => (int) $state['applied'],
				'progress'  => Draft::progress(),
				'report'    => self::report_summary( (array) $state['report'] ),
			)
		);
	}

	/**
	 * Counts per result.
	 *
	 * @param array<int,array<string,string>> $report Rows.
	 * @return array<string,int>
	 */
	public static function report_summary( array $report ): array {
		$out = array(
			'applied' => 0,
			'check'   => 0,
			'manual'  => 0,
			'skipped' => 0,
			'error'   => 0,
		);

		foreach ( $report as $row ) {
			$r = (string) ( $row['result'] ?? '' );

			if ( isset( $out[ $r ] ) ) {
				++$out[ $r ];
			}
		}

		return $out;
	}
}
