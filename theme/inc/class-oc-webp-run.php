<?php
/**
 * Converting a whole library to WebP without anyone watching.
 *
 * Seventeen thousand pictures is hours of work. Driving that from the
 * screen means a tab that must stay open and a person who must stay awake,
 * so the run lives on the server: a cursor in an option, a batch booked
 * against the next, and a screen that only reports what it finds.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The background WebP run.
 */
final class Webp_Run {

	/**
	 * Where the run's state lives.
	 */
	private const STATE = 'oc_webp_run';

	/**
	 * The list of ids for this run.
	 */
	private const IDS = 'oc_webp_run_ids';

	/**
	 * The scheduled step.
	 */
	private const STEP = 'oc_webp_step';

	/**
	 * The lock, so two workers never share a run.
	 */
	private const LOCK = 'oc_webp_run_lock';

	/**
	 * Longest a batch may work before booking the next one.
	 */
	private const SECONDS = 20;

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( self::STEP, array( __CLASS__, 'step' ) );

		add_action( 'wp_ajax_ocmc_run_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_ocmc_run_stop', array( $this, 'ajax_stop' ) );
		add_action( 'wp_ajax_ocmc_run_state', array( $this, 'ajax_state' ) );
	}

	/**
	 * The run as the screen needs to see it.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$now = get_option( self::STATE, array() );

		if ( ! is_array( $now ) || empty( $now['state'] ) ) {
			return array( 'state' => 'idle' );
		}

		// A run that is supposedly going but has nothing booked has been
		// dropped by cron; say so rather than showing a frozen bar.
		if ( 'running' === $now['state'] && ! wp_next_scheduled( self::STEP ) ) {
			$now['stalled'] = true;
		}

		return $now;
	}

	/**
	 * Begin a run over everything that is still JPEG or PNG.
	 *
	 * @param int $floor Smallest file worth converting, in bytes.
	 * @return array<string,mixed>
	 */
	public static function start( int $floor = 0 ): array {
		global $wpdb;

		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'This server cannot write WebP.', 'oc-theme' ),
			);
		}

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one maintenance sweep at the start of a run; the result is stored, not repeated.
			"SELECT p.ID, m.meta_value AS file
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type IN ( 'image/jpeg', 'image/png' )
			 ORDER BY p.ID DESC"
		);

		$ids = array();

		foreach ( $rows as $row ) {
			$ext = strtolower( (string) pathinfo( (string) $row->file, PATHINFO_EXTENSION ) );

			// The mime column can lag behind the file; the name is the truth.
			if ( 'webp' === $ext || 'svg' === $ext || '' === $ext ) {
				continue;
			}

			$ids[] = (int) $row->ID;
		}

		if ( empty( $ids ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'Everything in the library is already WebP.', 'oc-theme' ),
			);
		}

		set_transient( self::IDS, $ids, WEEK_IN_SECONDS );

		$state = array(
			'state'  => 'running',
			'total'  => count( $ids ),
			'cursor' => 0,
			'done'   => 0,
			'failed' => 0,
			'saved'  => 0,
			'floor'  => max( 0, $floor ),
			'began'  => time(),
		);

		update_option( self::STATE, $state, false );
		self::book( 1 );

		return array( 'ok' => true ) + $state;
	}

	/**
	 * Stop, keeping everything already converted.
	 */
	public static function stop(): void {
		$now = get_option( self::STATE, array() );

		if ( is_array( $now ) && ! empty( $now['state'] ) ) {
			$now['state']   = 'stopped';
			$now['stopped'] = time();
			update_option( self::STATE, $now, false );
		}

		delete_transient( self::IDS );
		self::unlock();

		$booked = wp_next_scheduled( self::STEP );

		if ( $booked ) {
			wp_unschedule_event( $booked, self::STEP );
		}
	}

	/**
	 * Book the next batch.
	 *
	 * @param int $delay Seconds from now.
	 */
	private static function book( int $delay = 10 ): void {
		if ( ! wp_next_scheduled( self::STEP ) ) {
			wp_schedule_single_event( time() + max( 1, $delay ), self::STEP );
		}
	}

	/**
	 * One batch: convert until the clock says stop, then book the next.
	 */
	public static function step(): void {
		$now = get_option( self::STATE, array() );

		if ( ! is_array( $now ) || 'running' !== ( $now['state'] ?? '' ) ) {
			return;
		}

		if ( ! self::lock() ) {
			return;
		}

		$ids = get_transient( self::IDS );

		if ( ! is_array( $ids ) ) {
			// The list expired under a long run. Everything converted so far
			// stays converted; work out what is left and carry on.
			self::unlock();
			$again = self::start( (int) ( $now['floor'] ?? 0 ) );

			if ( empty( $again['ok'] ) ) {
				$now['state'] = 'done';
				$now['ended'] = time();
				update_option( self::STATE, $now, false );
			}

			return;
		}

		$began = microtime( true );
		$at    = (int) $now['cursor'];
		$count = count( $ids );
		$floor = (int) ( $now['floor'] ?? 0 );

		while ( $at < $count && ( microtime( true ) - $began ) < self::SECONDS ) {
			$id = (int) $ids[ $at ];
			++$at;

			if ( $floor > 0 && Media_Clean::bytes( $id ) < $floor ) {
				continue;
			}

			$out = Media_Clean::to_webp( $id );

			if ( empty( $out['ok'] ) ) {
				++$now['failed'];
				continue;
			}

			++$now['done'];
			$now['saved'] += max( 0, (int) ( $out['before'] ?? 0 ) - (int) ( $out['after'] ?? 0 ) );

			if ( get_option( Media_Clean::DROP, false ) ) {
				Media_Clean::drop_old( $id );
			}
		}

		$now['cursor'] = $at;
		$now['ticked'] = time();

		if ( $at >= $count ) {
			$now['state'] = 'done';
			$now['ended'] = time();
			delete_transient( self::IDS );
		}

		update_option( self::STATE, $now, false );
		self::unlock();

		if ( 'running' === $now['state'] ) {
			self::book( 5 );
		}
	}

	/**
	 * Take the lock, or say it is taken.
	 */
	private static function lock(): bool {
		if ( get_transient( self::LOCK ) ) {
			return false;
		}

		// Long enough to cover a batch that dies mid-way, short enough that
		// a crash does not wedge the run for good.
		set_transient( self::LOCK, time(), 5 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Give the lock back.
	 */
	private static function unlock(): void {
		delete_transient( self::LOCK );
	}

	/**
	 * Guard shared by the three ajax entry points.
	 */
	private function guard(): void {
		check_ajax_referer( 'ocmc', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'why' => __( 'Not allowed.', 'oc-theme' ) ), 403 );
		}
	}

	/**
	 * Start, over ajax.
	 */
	public function ajax_start(): void {
		$this->guard();

		$floor = isset( $_POST['floor'] ) ? absint( wp_unslash( $_POST['floor'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().

		$out = self::start( $floor * KB_IN_BYTES );

		if ( empty( $out['ok'] ) ) {
			wp_send_json_error( $out );
		}

		wp_send_json_success( $out );
	}

	/**
	 * Stop, over ajax.
	 */
	public function ajax_stop(): void {
		$this->guard();
		self::stop();
		wp_send_json_success( self::state() );
	}

	/**
	 * Report, over ajax.
	 */
	public function ajax_state(): void {
		$this->guard();

		$now = self::state();

		// A stalled run is one cron missed, not a broken one: nudge it.
		if ( ! empty( $now['stalled'] ) ) {
			self::book( 1 );
		}

		wp_send_json_success( $now );
	}
}
