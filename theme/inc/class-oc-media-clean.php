<?php
/**
 * OC Media cleanup — the careful part.
 *
 * Finds media nothing points at any more, and is deliberately shy about it:
 * anything that might still be in use is left alone. The rule throughout is
 * that a missed file costs disk space, while a wrongly deleted one costs a
 * picture off the shop, so every doubt is resolved in favour of keeping.
 *
 * An attachment is considered spoken for when any of these hold:
 *
 *   1. It hangs off a post (post_parent) that still exists.
 *   2. Its ID sits in a meta value under a key that plausibly holds media —
 *      _thumbnail_id, the Woo gallery, every _oc_*_img, and anything from a
 *      plugin whose key mentions an image, a logo, a gallery and so on.
 *   3. Its ID sits in term meta, user meta or an option of the same kind —
 *      the category hero, the site logo, the customizer.
 *   4. Its ID appears in post content as wp-image-N, an attachment_N anchor
 *      or a gallery shortcode.
 *   5. Its file name appears anywhere at all — content, meta, options. This
 *      is the safety net that catches hand-written <img src>, custom CSS and
 *      page builders that store URLs rather than IDs.
 *
 * Note what is *not* done: a blanket sweep of every numeric meta value. On a
 * shop that would read _price 1200 as a reference to attachment 1200 and the
 * report would be nonsense. Keys are matched by name instead.
 *
 * Every reference also remembers who made it, because "in use" and "wanted
 * only by a draft" are different answers. A picture held by nothing but an
 * unpublished product belongs in the drafts group, where it can be cleared
 * out on purpose; the same picture on a published product is untouchable.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The scanner and the deleter.
 */
final class Media_Clean {

	/**
	 * Where a scan in progress keeps its findings.
	 */
	private const STORE = 'oc_mclean_scan';

	/**
	 * Where the record of what was deleted lives.
	 */
	private const LOG = 'oc_mclean_log';

	/**
	 * How many attachments one step of the scan checks by name.
	 */
	private const STEP = 40;

	/**
	 * Freshly uploaded media is never offered, in hours.
	 *
	 * Someone may be part-way through building a page with it.
	 */
	private const GRACE_HOURS = 48;

	/**
	 * Meta and option keys that plausibly hold an attachment ID.
	 *
	 * Matched with LIKE, so each entry is a fragment. Being generous here is
	 * safe: a key wrongly included only means an extra picture is kept.
	 *
	 * @var string[]
	 */
	private const KEY_HINTS = array(
		'img',
		'image',
		'logo',
		'photo',
		'pic',
		'media',
		'gallery',
		'thumb',
		'avatar',
		'banner',
		'poster',
		'attach',
		'icon',
		'background',
		'_bg',
		'video',
		'file',
		'upload',
		'cover',
		'slide',
		'hero',
		'_id',
	);

	/**
	 * Keys that hold media but whose names give no hint of it.
	 *
	 * @var string[]
	 */
	private const KEY_EXTRA = array(
		'_oc_sections',
		'_product_image_gallery',
	);

	/**
	 * Keys that describe an attachment rather than point at one.
	 *
	 * These matter more than they look. An attachment's own metadata is a
	 * blob of widths, heights and file sizes — numbers like 509 and 644,
	 * sitting squarely in the range attachment IDs occupy. Read as
	 * references they mark half the library as spoken for and bury the real
	 * orphans, so they are skipped. None of them ever names another file.
	 *
	 * @var string[]
	 */
	private const KEY_SELF = array(
		'_wp_attachment_metadata',
		'_wp_attached_file',
		'_wp_attachment_backup_sizes',
		'_wp_attachment_image_alt',
		'_wp_attachment_is_custom_background',
		'_edit_lock',
		'_edit_last',
	);

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'wp_ajax_ocmc_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_ocmc_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_ocmc_delete', array( $this, 'ajax_delete' ) );
		add_action( 'admin_post_ocmc_csv', array( $this, 'handle_csv' ) );
	}

	/**
	 * The room's address.
	 *
	 * @param array<string,string> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'upload.php?page=oc-media-clean' ) );
	}

	/**
	 * Under Media, where someone looking to tidy up would go.
	 */
	public function menu(): void {
		add_submenu_page(
			'upload.php',
			__( 'Media cleanup', 'oc-theme' ),
			__( 'Media cleanup', 'oc-theme' ),
			'manage_options',
			'oc-media-clean',
			array( $this, 'render' )
		);
	}

	/*
	 * -------------------------------------------------------- the reference set
	 */

	/**
	 * Who points at what.
	 *
	 * Returns a map of attachment ID to the things that mention it. A post
	 * is recorded by its own ID, so that a picture spoken for only by an
	 * unpublished product can be told apart from one on the live shop —
	 * which is the whole difference between the drafts group and the
	 * untouchable one. Everything outside the posts table (a category, a
	 * user, an option, the customizer) is recorded as a bare tag, and any
	 * one of those is enough to make a file untouchable.
	 *
	 * @return array<int,array<string,bool>>
	 */
	public static function reference_map(): array {
		global $wpdb;

		$found = array();

		$soak = static function ( $value, string $source ) use ( &$found ): void {
			if ( ! is_string( $value ) || '' === $value ) {
				return;
			}
			if ( preg_match_all( '/\d+/', $value, $m ) ) {
				foreach ( $m[0] as $n ) {
					$id = (int) $n;
					if ( $id > 0 ) {
						$found[ $id ][ $source ] = true;
					}
				}
			}
		};

		// Meta values under keys that sound like they hold media.
		$where = self::key_clause( 'meta_key' );

		$rows = (array) $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as $row ) {
			$soak( $row->meta_value, 'post:' . (int) $row->post_id );
		}

		foreach ( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->termmeta} WHERE {$where}" ) as $value ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$soak( $value, 'term' );
		}
		foreach ( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta} WHERE {$where}" ) as $value ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$soak( $value, 'user' );
		}

		// Options: the customizer, the logo, our own settings.
		$opts = $wpdb->get_col(
			"SELECT option_value FROM {$wpdb->options}
			 WHERE option_name NOT LIKE '\_transient%'
			   AND option_name NOT LIKE '\_site\_transient%'
			   AND ( option_name LIKE 'theme\_mods\_%'
			      OR option_name LIKE 'oc\_%'
			      OR option_name LIKE 'woocommerce\_%'
			      OR option_name LIKE '%image%'
			      OR option_name LIKE '%logo%'
			      OR option_name LIKE '%icon%'
			      OR option_name LIKE '%media%' )"
		);
		foreach ( $opts as $value ) {
			$soak( $value, 'option' );
		}

		// Content: what the editor wrote into a page.
		$rows = $wpdb->get_results(
			"SELECT ID, post_content FROM {$wpdb->posts}
			 WHERE post_type <> 'revision'
			   AND ( post_content LIKE '%wp-image-%'
			      OR post_content LIKE '%attachment_%'
			      OR post_content LIKE '%[gallery%'
			      OR post_content LIKE '%wp:image%'
			      OR post_content LIKE '%ids=%' )"
		);
		foreach ( $rows as $row ) {
			if ( preg_match_all( '/wp-image-(\d+)|attachment_(\d+)|["\']?ids["\']?\s*[:=]\s*["\']?([\d,\s]+)|"id"\s*:\s*(\d+)/i', (string) $row->post_content, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					$blob = trim( $hit[1] . $hit[2] . ( $hit[3] ?? '' ) . ( $hit[4] ?? '' ) );
					foreach ( preg_split( '/[,\s]+/', $blob ) as $n ) {
						$id = (int) $n;
						if ( $id > 0 ) {
							$found[ $id ][ 'post:' . (int) $row->ID ] = true;
						}
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Just the IDs, for callers that only need to know "is this spoken for".
	 *
	 * @return array<int,bool>
	 */
	public static function referenced_ids(): array {
		$out = array();
		foreach ( array_keys( self::reference_map() ) as $id ) {
			$out[ (int) $id ] = true;
		}

		return $out;
	}

	/**
	 * Post statuses that mean a picture is on the live shop.
	 *
	 * 'inherit' covers revisions, which carry a copy of their parent's
	 * featured image and therefore stand for the parent.
	 *
	 * @var string[]
	 */
	private const LIVE = array( 'publish', 'future', 'private', 'inherit' );

	/**
	 * A WHERE fragment matching every key that might hold media.
	 *
	 * @param string $column Column to match on.
	 */
	private static function key_clause( string $column ): string {
		global $wpdb;

		$parts = array();
		foreach ( self::KEY_HINTS as $hint ) {
			$parts[] = $wpdb->prepare( "{$column} LIKE %s", '%' . $wpdb->esc_like( $hint ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		foreach ( self::KEY_EXTRA as $key ) {
			$parts[] = $wpdb->prepare( "{$column} = %s", $key ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$skip = array();
		foreach ( self::KEY_SELF as $key ) {
			$skip[] = $wpdb->prepare( "{$column} <> %s", $key ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return '( ' . implode( ' OR ', $parts ) . ' ) AND ( ' . implode( ' AND ', $skip ) . ' )';
	}

	/**
	 * Is this file's name written down anywhere?
	 *
	 * The last line of defence, for media referenced by URL rather than by
	 * ID — a hand-written <img>, a background in custom CSS, a builder that
	 * stores paths. The stem is searched without its size suffix, so that
	 * sofa-800x600.jpg is found by looking for "sofa".
	 *
	 * @param int  $id        Attachment ID.
	 * @param bool $live_only Weigh only published posts, for files already
	 *                        known to belong to a draft or to the bin. The
	 *                        orphan group is checked without this, so that
	 *                        a mention absolutely anywhere protects it.
	 */
	public static function name_referenced( int $id, bool $live_only = false ): bool {
		global $wpdb;

		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $file ) {
			return true; // No file recorded: leave it alone.
		}

		$stem = pathinfo( $file, PATHINFO_FILENAME );
		$stem = (string) preg_replace( '/-\d+x\d+$/', '', $stem );
		$stem = (string) preg_replace( '/-scaled$/', '', $stem );

		if ( strlen( $stem ) < 4 ) {
			return true; // Too short to search for safely.
		}

		$like = '%' . $wpdb->esc_like( $stem ) . '%';

		$live = $live_only
			? " AND post_status IN ( '" . implode( "', '", self::LIVE ) . "' )"
			: '';

		$hit = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->posts}
				 WHERE post_type <> 'attachment' AND post_type <> 'revision'
				   AND ( post_content LIKE %s OR post_excerpt LIKE %s )
				   {$live}
				 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like,
				$like
			)
		);
		if ( $hit ) {
			return true;
		}

		$hit = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.post_id <> %d AND m.meta_value LIKE %s
				   {$live}
				 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id,
				$like
			)
		);
		if ( $hit ) {
			return true;
		}

		$hit = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$wpdb->termmeta} WHERE meta_value LIKE %s LIMIT 1", $like )
		);
		if ( $hit ) {
			return true;
		}

		$hit = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->options}
				 WHERE option_name NOT LIKE '\_transient%%'
				   AND option_name NOT LIKE '\_site\_transient%%'
				   AND option_value LIKE %s LIMIT 1",
				$like
			)
		);

		return (bool) $hit;
	}

	/*
	 * ------------------------------------------------------------- classifying
	 */

	/**
	 * What bucket does one attachment belong in?
	 *
	 * Returns one of: used, draft, trash, orphan, recent.
	 *
	 * @param object            $row  Row with ID, post_parent, post_date_gmt.
	 * @param array<int,array<string,bool>> $refs Map from reference_map().
	 * @param array<int,object> $posts Parent lookup.
	 */
	public static function bucket( object $row, array $refs, array $posts ): string {
		$id = (int) $row->ID;

		// Who mentions it, and are any of them live?
		$draft = false;
		$trash = false;

		foreach ( array_keys( (array) ( $refs[ $id ] ?? array() ) ) as $source ) {
			$source = (string) $source;

			// A category, a user, an option or the customizer. No status to
			// weigh, so any of these settles it.
			if ( 0 !== strpos( $source, 'post:' ) ) {
				return 'used';
			}

			$pid = (int) substr( $source, 5 );

			// A post we cannot see is treated as live, not as absent.
			if ( ! isset( $posts[ $pid ] ) ) {
				return 'used';
			}

			$status = (string) $posts[ $pid ]->post_status;

			if ( in_array( $status, self::LIVE, true ) ) {
				return 'used';
			}
			if ( 'trash' === $status ) {
				$trash = true;
			} else {
				$draft = true;
			}
		}

		// And what it hangs off, which is the same question again.
		$found  = 'orphan';
		$parent = (int) $row->post_parent;

		if ( $parent > 0 && isset( $posts[ $parent ] ) ) {
			$status = (string) $posts[ $parent ]->post_status;

			if ( 'trash' === $status ) {
				$trash = true;
			} elseif ( in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
				$draft = true;
			} else {
				return 'used';
			}
		}

		// Wanted by an unpublished thing beats wanted by a binned one: a
		// draft may yet be published, so it is the more careful label.
		if ( $draft ) {
			$found = 'draft';
		} elseif ( $trash ) {
			$found = 'trash';
		}

		// Deletable on the face of it — but uploaded a moment ago, so
		// someone may still be part-way through putting it to work. The
		// grace period is applied last, so that a picture already in use
		// is reported as in use rather than merely young.
		$age = time() - (int) strtotime( (string) $row->post_date_gmt . ' UTC' );
		if ( $age < self::GRACE_HOURS * HOUR_IN_SECONDS ) {
			return 'recent';
		}

		return $found;
	}

	/**
	 * Every attachment, with what we need to judge it.
	 *
	 * @return object[]
	 */
	public static function attachments(): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			"SELECT ID, post_parent, post_date_gmt, post_title
			 FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID ASC"
		);
	}

	/**
	 * Parent posts, by ID.
	 *
	 * @return array<int,object>
	 */
	public static function parents(): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			"SELECT ID, post_type, post_status, post_title FROM {$wpdb->posts}"
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->ID ] = $row;
		}

		return $out;
	}

	/**
	 * Disk taken by one attachment, original plus every generated size.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function bytes( int $id ): int {
		$file = get_attached_file( $id );
		if ( ! $file ) {
			return 0;
		}

		$total = file_exists( $file ) ? (int) filesize( $file ) : 0;
		$dir   = dirname( $file );
		$meta  = wp_get_attachment_metadata( $id );

		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( empty( $size['file'] ) ) {
						continue;
					}
					$path = $dir . '/' . $size['file'];
					if ( file_exists( $path ) ) {
						$total += (int) filesize( $path );
					}
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$path = $dir . '/' . $meta['original_image'];
				if ( file_exists( $path ) ) {
					$total += (int) filesize( $path );
				}
			}
		}

		return $total;
	}

	/*
	 * ----------------------------------------------------------------- the scan
	 */

	/**
	 * Begin a scan: build the reference set, list the candidates.
	 */
	public function ajax_start(): void {
		$this->guard();

		$refs    = self::reference_map();
		$posts   = self::parents();
		$rows    = self::attachments();
		$queue   = array();
		$report  = array(
			'used'   => array(),
			'draft'  => array(),
			'trash'  => array(),
			'orphan' => array(),
			'recent' => array(),
		);

		foreach ( $rows as $row ) {
			$bucket = self::bucket( $row, $refs, $posts );
			$id     = (int) $row->ID;

			if ( 'used' === $bucket || 'recent' === $bucket ) {
				$report[ $bucket ][] = $id;
				continue;
			}

			// Everything still in play gets its name checked, one step at a time.
			$queue[] = array(
				'id'     => $id,
				'bucket' => $bucket,
			);
		}

		set_transient(
			self::STORE,
			array(
				'queue'  => $queue,
				'report' => $report,
				'done'   => 0,
			),
			HOUR_IN_SECONDS
		);

		wp_send_json_success(
			array(
				'total' => count( $queue ),
				'used'  => count( $report['used'] ),
				'held'  => count( $report['recent'] ),
			)
		);
	}

	/**
	 * One step: check the next batch of candidates by file name.
	 */
	public function ajax_step(): void {
		$this->guard();

		$state = get_transient( self::STORE );
		if ( ! is_array( $state ) ) {
			wp_send_json_error( array( 'message' => __( 'The scan expired. Please run it again.', 'oc-theme' ) ) );
		}

		$queue = (array) $state['queue'];
		$done  = (int) $state['done'];
		$stop  = min( $done + self::STEP, count( $queue ) );

		for ( ; $done < $stop; $done++ ) {
			$item = $queue[ $done ];
			$id   = (int) $item['id'];

			// The orphan group claims nothing at all points at the file, so
			// it is held by a mention anywhere. The draft and bin groups
			// only promise that nothing live wants it.
			if ( self::name_referenced( $id, 'orphan' !== $item['bucket'] ) ) {
				$state['report']['used'][] = $id;
				continue;
			}

			$state['report'][ $item['bucket'] ][] = $id;
		}

		$state['done'] = $done;
		set_transient( self::STORE, $state, HOUR_IN_SECONDS );

		wp_send_json_success(
			array(
				'done'  => $done,
				'total' => count( $queue ),
				'ready' => $done >= count( $queue ),
			)
		);
	}

	/**
	 * The findings of the last scan, dressed for the screen.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function results(): ?array {
		$state = get_transient( self::STORE );
		if ( ! is_array( $state ) || $state['done'] < count( (array) $state['queue'] ) ) {
			return null;
		}

		$report = (array) $state['report'];
		$out    = array();

		foreach ( array( 'orphan', 'draft', 'trash' ) as $bucket ) {
			$items = array();
			$bytes = 0;

			foreach ( (array) $report[ $bucket ] as $id ) {
				$id     = (int) $id;
				$size   = self::bytes( $id );
				$bytes += $size;

				$items[] = array(
					'id'    => $id,
					'thumb' => wp_get_attachment_image_url( $id, 'thumbnail' ),
					'name'  => wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
					'bytes' => $size,
					'link'  => get_edit_post_link( $id, 'raw' ),
				);
			}

			$out[ $bucket ] = array(
				'items' => $items,
				'bytes' => $bytes,
			);
		}

		$out['used']   = count( (array) $report['used'] );
		$out['recent'] = count( (array) $report['recent'] );

		return $out;
	}

	/*
	 * ------------------------------------------------------------- the deleting
	 */

	/**
	 * Delete a batch, re-checking each one first.
	 */
	public function ajax_delete(): void {
		$this->guard();

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();

		wp_send_json_success( self::delete_batch( array_filter( $ids ) ) );
	}

	/**
	 * Remove a batch, checking each one over again first.
	 *
	 * Separate from the ajax handler so the guard can be exercised on its
	 * own: hand it a picture that is in use and it must come back kept.
	 *
	 * @param int[] $ids Attachment IDs.
	 *
	 * @return array{deleted:int,kept:int,bytes:int}
	 */
	public static function delete_batch( array $ids ): array {
		if ( ! $ids ) {
			return array(
				'deleted' => 0,
				'kept'    => 0,
				'bytes'   => 0,
			);
		}

		// The scan may be minutes old. Anything that has since been put to
		// use must survive, so the reference set is rebuilt for this batch.
		$refs  = self::reference_map();
		$posts = self::parents();
		$log   = (array) get_option( self::LOG, array() );

		$deleted = 0;
		$kept    = 0;
		$freed   = 0;

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				continue;
			}

			// Judged again from scratch, by the same rules the screen used.
			$bucket = self::bucket( $post, $refs, $posts );

			if ( ! in_array( $bucket, array( 'orphan', 'draft', 'trash' ), true ) ) {
				$kept++;
				continue;
			}

			if ( self::name_referenced( $id, 'orphan' !== $bucket ) ) {
				$kept++;
				continue;
			}

			$bytes = self::bytes( $id );
			$name  = wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );

			if ( wp_delete_attachment( $id, true ) ) {
				$deleted++;
				$freed += $bytes;

				$log[] = array(
					'id'    => $id,
					'name'  => $name,
					'bytes' => $bytes,
					'when'  => gmdate( 'Y-m-d H:i:s' ),
					'who'   => wp_get_current_user()->user_login,
				);
			} else {
				$kept++;
			}
		}

		if ( count( $log ) > 2000 ) {
			$log = array_slice( $log, -2000 );
		}
		update_option( self::LOG, $log, false );

		// The findings on screen describe a library that no longer exists.
		// Throwing them away is what stops the deleted files lingering as
		// broken thumbnails until the transient happens to expire.
		delete_transient( self::STORE );

		return array(
			'deleted' => $deleted,
			'kept'    => $kept,
			'bytes'   => $freed,
		);
	}

	/**
	 * The record of what was removed, as a spreadsheet.
	 */
	public function handle_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}
		check_admin_referer( 'ocmc_csv' );

		$log = (array) get_option( self::LOG, array() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=oc-media-deleted.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'ID', 'File', 'Bytes', 'Deleted (UTC)', 'By' ) );

		foreach ( array_reverse( $log ) as $line ) {
			fputcsv(
				$out,
				array(
					$line['id'] ?? '',
					$line['name'] ?? '',
					$line['bytes'] ?? 0,
					$line['when'] ?? '',
					$line['who'] ?? '',
				)
			);
		}

		fclose( $out );
		exit;
	}

	/**
	 * How many files were removed all told.
	 */
	public static function log_count(): int {
		return count( (array) get_option( self::LOG, array() ) );
	}

	/**
	 * Administrators only, and only with a fresh nonce.
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'oc-theme' ) ), 403 );
		}
		check_ajax_referer( 'ocmc' );
	}

	/**
	 * The screen itself lives next door.
	 */
	public function render(): void {
		Media_Clean_Admin::render();
	}
}
