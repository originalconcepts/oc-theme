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
	 * The longest side a stored original is ever asked to keep.
	 *
	 * Nothing on the site shows a picture wider than the widest layout on a
	 * high-density screen; anything beyond this is weight the visitor pays
	 * for and never sees.
	 */
	private const MAX_SIDE = 2560;

	/**
	 * Where a shrunk file's untouched original is remembered.
	 */
	private const BACKUP = '_oc_mclean_original';

	/**
	 * Whether new uploads have their sizes built as WebP.
	 */
	public const WEBP = 'oc_mclean_webp';

	/**
	 * Whether a conversion also clears the files it replaces.
	 */
	public const DROP = 'oc_mclean_drop';

	/**
	 * Where a converted picture's previous shape is remembered.
	 */
	private const PREWEBP = '_oc_mclean_prewebp';

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
		add_action( 'wp_ajax_ocmc_heavy', array( $this, 'ajax_heavy' ) );
		add_action( 'wp_ajax_ocmc_webp', array( $this, 'ajax_webp' ) );
		add_action( 'wp_ajax_ocmc_formats', array( $this, 'ajax_formats' ) );
		add_action( 'wp_ajax_ocmc_convert', array( $this, 'ajax_convert' ) );
		add_action( 'wp_ajax_ocmc_drop', array( $this, 'ajax_drop' ) );
		add_action( 'wp_ajax_ocmc_undo', array( $this, 'ajax_undo' ) );
		add_filter( 'image_editor_output_format', array( $this, 'webp_sizes' ) );
		add_action( 'wp_ajax_ocmc_shrink', array( $this, 'ajax_shrink' ) );
		add_action( 'wp_ajax_ocmc_restore', array( $this, 'ajax_restore' ) );
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

		add_submenu_page(
			'upload.php',
			__( 'Convert to WebP', 'oc-theme' ),
			__( 'Convert to WebP', 'oc-theme' ),
			'manage_options',
			'oc-media-webp',
			array( $this, 'render_webp' )
		);
	}

	/**
	 * The WebP screen lives next door too.
	 */
	public function render_webp(): void {
		Webp_Admin::render();
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

		// phpcs:disable WordPress.DB -- maintenance scan over core tables: the key clause is prepared piecewise in key_clause(), table names come from $wpdb, and live counts cannot cache.
		$rows = (array) $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE {$where}" );
		foreach ( $rows as $row ) {
			$soak( $row->meta_value, 'post:' . (int) $row->post_id );
		}

		foreach ( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->termmeta} WHERE {$where}" ) as $value ) {
			$soak( $value, 'term' );
		}
		foreach ( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta} WHERE {$where}" ) as $value ) {
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
		// phpcs:enable WordPress.DB
		foreach ( $rows as $row ) {
			if ( preg_match_all( '/wp-image-(\d+)|attachment_(\d+)|["\']?ids["\']?\s*[:=]\s*["\']?([\d,\s]+)|"id"\s*:\s*(\d+)/i', (string) $row->post_content, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					$blob = trim( ( $hit[1] ?? '' ) . ( $hit[2] ?? '' ) . ( $hit[3] ?? '' ) . ( $hit[4] ?? '' ) );
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

		// phpcs:disable WordPress.DB -- reference hunt over core tables: the only interpolations are $wpdb table names and the status list built from the LIVE const, the LIKE wildcards are literal, and live checks cannot cache.
		$hit = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->posts}
				 WHERE post_type <> 'attachment' AND post_type <> 'revision'
				   AND ( post_content LIKE %s OR post_excerpt LIKE %s )
				   {$live}
				 LIMIT 1",
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
				 LIMIT 1",
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
		// phpcs:enable WordPress.DB

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
	 * @param object                        $row  Row with ID, post_parent, post_date_gmt.
	 * @param array<int,array<string,bool>> $refs Map from reference_map().
	 * @param array<int,object>             $posts Parent lookup.
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

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- maintenance scan; live counts cannot cache.
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

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- maintenance scan; live counts cannot cache.
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

		/**
		 * Core's documented shape omits original_image, which is present for
		 * scaled images since WP 5.3 — widen to what the call really returns.
		 *
		 * @var array<string,mixed>|false $meta
		 */
		$meta = wp_get_attachment_metadata( $id );

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

		$refs   = self::reference_map();
		$posts  = self::parents();
		$rows   = self::attachments();
		$queue  = array();
		$report = array(
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
					'mime'  => (string) get_post_mime_type( $id ),
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
	 * --------------------------------------------------------- the heavy files
	 */

	/**
	 * The biggest files in the library, and what points at them.
	 *
	 * Sizes come from the attachment's own metadata, where WordPress records
	 * the bytes of the original and of every size it generated — asking the
	 * filesystem eighteen thousand times would take longer than the request
	 * is allowed to live. Anything whose metadata predates that (or lost it)
	 * is measured on disk, up to a fixed number per scan.
	 *
	 * @param int    $min   Smallest total size to report, in bytes.
	 * @param string $type  'all', 'image' or 'video'.
	 * @param string $url   One page to look at; empty means the whole library.
	 * @param int    $limit Longest list to return.
	 * @return array<string,mixed>
	 */
	public static function heavy( int $min, string $type, string $url = '', int $limit = 200 ): array {
		global $wpdb;

		if ( '' !== trim( $url ) ) {
			return self::heavy_on_page( $min, $type, $url, $limit );
		}

		$sql = "SELECT p.ID, p.post_title, p.post_mime_type, p.post_parent, p.post_date_gmt, m.meta_value AS meta
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata'
			 WHERE p.post_type = 'attachment'";

		if ( 'image' === $type || 'video' === $type ) {
			$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- maintenance scan; live counts cannot cache.
				$wpdb->prepare( $sql . ' AND p.post_mime_type LIKE %s', $wpdb->esc_like( $type . '/' ) . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a literal built above.
			);
		} else {
			$rows = (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- $sql is a literal built above.
		}

		$found   = array();
		$stats   = 0;
		$total   = 0;
		$scanned = 0;

		foreach ( $rows as $row ) {
			++$scanned;
			$meta  = is_string( $row->meta ) ? maybe_unserialize( $row->meta ) : array();
			$bytes = 0;

			if ( is_array( $meta ) ) {
				$bytes = (int) ( $meta['filesize'] ?? 0 );

				foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
					$bytes += (int) ( $size['filesize'] ?? 0 );
				}
			}

			// No recorded size: ask the disk, but only so many times.
			if ( 0 === $bytes && $stats < 400 ) {
				++$stats;
				$bytes = self::bytes( (int) $row->ID );
			}

			$total += $bytes;

			$found[] = array(
				'id'     => (int) $row->ID,
				'bytes'  => $bytes,
				'mime'   => (string) $row->post_mime_type,
				'w'      => (int) ( $meta['width'] ?? 0 ),
				'h'      => (int) ( $meta['height'] ?? 0 ),
				'parent' => (int) $row->post_parent,
				'date'   => (string) $row->post_date_gmt,
			);
		}

		// A threshold of zero means "heavier than the average file", which
		// can only be known once every file has been weighed.
		if ( $min < 1 ) {
			$min = $scanned > 0 ? (int) round( $total / $scanned ) : 0;
		}

		$found = array_values(
			array_filter(
				$found,
				static function ( array $one ) use ( $min ): bool {
					return $one['bytes'] >= $min;
				}
			)
		);

		usort(
			$found,
			static function ( array $a, array $b ): int {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		$over = count( $found );
		$sum  = 0;

		foreach ( $found as $one ) {
			$sum += $one['bytes'];
		}

		$found = array_slice( $found, 0, max( 1, $limit ) );
		$refs  = self::reference_map();
		$posts = self::parents();
		$items = array();

		foreach ( $found as $one ) {
			$id   = $one['id'];
			$file = (string) get_post_meta( $id, '_wp_attached_file', true );
			$verd = self::bucket(
				(object) array(
					'ID'            => $id,
					'post_parent'   => $one['parent'],
					'post_date_gmt' => $one['date'],
				),
				$refs,
				$posts
			);

			// The cleanup half checks the name too before calling anything an
			// orphan; do the same here so the two never disagree.
			if ( 'orphan' === $verd && self::name_referenced( $id, false ) ) {
				$verd = 'used';
			}

			$items[] = array_merge(
				$one,
				array(
					'thumb'  => wp_get_attachment_image_url( $id, 'thumbnail' ),
					'name'   => '' === $file ? (string) get_the_title( $id ) : wp_basename( $file ),
					'link'   => get_edit_post_link( $id, 'raw' ),
					'used'   => self::used_by( $id, $refs, $posts, (int) $one['parent'] ),
					'shrink' => self::shrinkable( $one['mime'] ),
					'backup' => '' !== (string) get_post_meta( $id, self::BACKUP, true ),
					'verd'   => $verd,
				)
			);
		}

		return array(
			'items'   => $items,
			'over'    => $over,
			'bytes'   => $sum,
			'library' => $total,
			'scanned' => $scanned,
			'shown'   => count( $items ),
			'min'     => $min,
			'avg'     => $scanned > 0 ? (int) round( $total / $scanned ) : 0,
			'webp'    => self::webp_gain( $items ),
		);
	}

	/**
	 * The same list, narrowed to one page.
	 *
	 * @param int    $min   Smallest size to report, in bytes.
	 * @param string $type  'all', 'image' or 'video'.
	 * @param string $url   The page.
	 * @param int    $limit Longest list to return.
	 * @return array<string,mixed>
	 */
	private static function heavy_on_page( int $min, string $type, string $url, int $limit ): array {
		$page = self::page_media( $url );

		if ( empty( $page['ok'] ) ) {
			return array(
				'items'   => array(),
				'over'    => 0,
				'bytes'   => 0,
				'shown'   => 0,
				'scanned' => 0,
				'why'     => (string) ( $page['why'] ?? '' ),
			);
		}

		$refs  = self::reference_map();
		$posts = self::parents();
		$items = array();
		$sum   = 0;

		foreach ( (array) $page['items'] as $one ) {
			$mime = (string) $one['mime'];

			if ( 'image' === $type && 0 !== strpos( $mime, 'image/' ) ) {
				continue;
			}

			if ( 'video' === $type && 0 !== strpos( $mime, 'video/' ) ) {
				continue;
			}

			if ( $min > 0 && (int) $one['bytes'] < $min ) {
				continue;
			}

			$id   = (int) $one['id'];
			$meta = $id > 0 ? (array) wp_get_attachment_metadata( $id ) : array();
			$sum += (int) $one['bytes'];

			$items[] = array(
				'id'     => $id,
				'bytes'  => (int) $one['bytes'],
				'eager'  => (bool) ( $one['eager'] ?? true ),
				'mime'   => $mime,
				'w'      => (int) ( $meta['width'] ?? 0 ),
				'h'      => (int) ( $meta['height'] ?? 0 ),
				'name'   => (string) $one['name'],
				'thumb'  => (string) $one['thumb'],
				'link'   => (string) $one['link'],
				'url'    => (string) $one['url'],
				'used'   => $id > 0 ? self::used_by( $id, $refs, $posts, (int) get_post_field( 'post_parent', $id ) ) : array(),
				'shrink' => (bool) $one['shrink'],
				'backup' => (bool) $one['backup'],
				'verd'   => $id > 0 ? 'used' : 'outside',
			);

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		// "Heavier than average" is measured against this page, not the
		// library: on a page it is the page's own mix that matters.
		if ( $min < 1 && $items ) {
			$avg   = (int) round( $sum / count( $items ) );
			$items = array_values(
				array_filter(
					$items,
					static function ( array $one ) use ( $avg ): bool {
						return (int) $one['bytes'] >= $avg;
					}
				)
			);

			$sum = 0;

			foreach ( $items as $one ) {
				$sum += (int) $one['bytes'];
			}

			$min = $avg;
		}

		return array(
			'items'   => $items,
			'over'    => count( $items ),
			'bytes'   => $sum,
			'shown'   => count( $items ),
			'scanned' => (int) $page['count'],
			'page'    => (string) $page['url'],
			'total'   => (int) $page['bytes'],
			'min'     => $min,
			'webp'    => self::webp_gain( $items ),
		);
	}

	/**
	 * Whether this file is one the shrinker may touch.
	 *
	 * Only what it can re-encode without changing the address every
	 * reference already uses: JPEG and PNG. Whether re-encoding actually
	 * wins anything is not decided here — shrink() puts the original back
	 * when it does not, which is a truthful answer no guess can give. A
	 * four-megabyte PNG at 1400px is a photograph saved in the wrong
	 * format, and it is worth letting the shrinker try.
	 *
	 * @param string $mime Mime type.
	 */
	private static function shrinkable( string $mime ): bool {
		return in_array( $mime, array( 'image/jpeg', 'image/png' ), true );
	}

	/**
	 * The human answer to "where is this used?".
	 *
	 * Must agree with bucket(), which is what the cleanup half of this screen
	 * reports — and bucket() weighs the file's parent as well as the
	 * reference map. Reading only the map told us a video attached to a
	 * published product was pointed at by nothing, which is exactly the
	 * answer that gets something deleted that should not be.
	 *
	 * @param int                           $id     Attachment ID.
	 * @param array<int,array<string,bool>> $refs   Reference map: id => set of sources.
	 * @param array<int,object>             $posts  Posts by ID.
	 * @param int                           $owner  The file's post_parent.
	 * @return array<int,array<string,string>>
	 */
	private static function used_by( int $id, array $refs, array $posts, int $owner = 0 ): array {
		$out  = array();
		$seen = array();

		$sources = array_keys( (array) ( $refs[ $id ] ?? array() ) );

		if ( $owner > 0 ) {
			$sources[] = 'post:' . $owner;
		}

		// The map is a set — the provenance is in the keys, as bucket() reads it.
		foreach ( $sources as $source ) {
			if ( 0 !== strpos( (string) $source, 'post:' ) ) {
				$label = (string) $source;

				if ( ! isset( $seen[ $label ] ) ) {
					$seen[ $label ] = true;
					$out[]          = array(
						'title' => 'term' === $label ? __( 'A category or brand', 'oc-theme' ) : __( 'A site setting', 'oc-theme' ),
						'link'  => '',
						'state' => '',
					);
				}

				continue;
			}

			$pid = (int) substr( (string) $source, 5 );
			$row = $posts[ $pid ] ?? null;

			if ( ! $row || isset( $seen[ 'p' . $pid ] ) ) {
				continue;
			}

			$seen[ 'p' . $pid ] = true;
			$out[]              = array(
				'title' => '' !== (string) $row->post_title ? (string) $row->post_title : '#' . $pid,
				'link'  => (string) get_edit_post_link( $pid, 'raw' ),
				'state' => 'publish' === $row->post_status ? '' : (string) $row->post_status,
			);

			if ( count( $out ) >= 6 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * What these pictures would weigh as WebP, and what that buys.
	 *
	 * The ratio is not guessed. A handful of the listed files are really
	 * re-encoded in memory, at the quality WordPress would use, and the
	 * middle result of each format is applied to the rest — so a library
	 * of flat PNG illustrations and one of photographs get their own
	 * honest number instead of a shared rule of thumb.
	 *
	 * @param array<int,array<string,mixed>> $items Rows from heavy().
	 * @param int                            $per   Files to sample per format.
	 * @return array<string,mixed>
	 */
	public static function webp_gain( array $items, int $per = 5 ): array {
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return array( 'able' => false );
		}

		$ratios = array();
		$tried  = array();

		foreach ( $items as $one ) {
			$mime = (string) ( $one['mime'] ?? '' );

			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				continue;
			}
			if ( count( $tried[ $mime ] ?? array() ) >= $per ) {
				continue;
			}

			$ratio = self::webp_ratio( (int) $one['id'] );

			if ( null === $ratio ) {
				continue;
			}

			$tried[ $mime ][]  = true;
			$ratios[ $mime ][] = $ratio;
		}

		// The middle sample, not the mean: one pathological file cannot
		// drag the forecast for a whole library.
		$mid = array();

		foreach ( $ratios as $mime => $list ) {
			sort( $list );
			$mid[ $mime ] = $list[ (int) floor( ( count( $list ) - 1 ) / 2 ) ];
		}

		$now   = 0;
		$after = 0;
		$eager = 0;
		$n     = 0;

		foreach ( $items as $one ) {
			$mime  = (string) ( $one['mime'] ?? '' );
			$bytes = (int) ( $one['bytes'] ?? 0 );

			if ( ! isset( $mid[ $mime ] ) ) {
				continue;
			}

			$cut = $bytes - (int) round( $bytes * $mid[ $mime ] );

			++$n;
			$now   += $bytes;
			$after += (int) round( $bytes * $mid[ $mime ] );

			// Only what the browser fetches straight away can move the
			// moment the page finishes drawing. A picture waiting for a
			// scroll saves the visitor's data, not their time.
			if ( false !== ( $one['eager'] ?? true ) ) {
				$eager += max( 0, $cut );
			}
		}

		return array(
			'able'    => true,
			'n'       => $n,
			'now'     => $now,
			'after'   => $after,
			'saved'   => max( 0, $now - $after ),
			'upfront' => $eager,
			'samples' => array_map( 'count', $ratios ),
			'ratio'   => $mid,
		);
	}

	/**
	 * How much of itself one picture keeps when written as WebP.
	 *
	 * Measured on the largest size the page would actually serve, not on
	 * the untouched upload — that is the file the visitor waits for.
	 *
	 * @param int $id Attachment ID.
	 * @return float|null Kept fraction, or null if it could not be read.
	 */
	private static function webp_ratio( int $id ): ?float {
		$file = (string) get_attached_file( $id );

		if ( '' === $file || ! file_exists( $file ) ) {
			return null;
		}

		$was = (int) filesize( $file );

		if ( $was < 1 ) {
			return null;
		}

		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return null;
		}

		$tmp = get_temp_dir() . uniqid( 'ocwebp', true ) . '.webp';

		$done = $editor->save( $tmp, 'image/webp' );

		$now = ! is_wp_error( $done ) && ! empty( $done['path'] ) && file_exists( $done['path'] )
			? (int) filesize( $done['path'] )
			: 0;

		if ( ! is_wp_error( $done ) && ! empty( $done['path'] ) ) {
			wp_delete_file( (string) $done['path'] );
		}

		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		return $now > 0 ? min( 1.0, $now / $was ) : null;
	}

	/**
	 * What the saved weight is worth on the speed report.
	 *
	 * Two steps, both borrowed from the tool that will grade the page.
	 * Lighthouse simulates a phone on 1.6 Mbit/s, so bytes divide straight
	 * into seconds; and it grades Largest Contentful Paint on a published
	 * log-normal curve, which is reproduced here exactly. Nothing is
	 * invented — but it is still a forecast about one metric, and the
	 * screen says so.
	 *
	 * @param int   $saved Bytes no longer downloaded.
	 * @param float $lcp   The page's LCP today, in seconds. 0 to skip.
	 * @param float $floor Fastest LCP the forecast may claim. 0 for the default.
	 * @return array<string,mixed>
	 */
	public static function speed_gain( int $saved, float $lcp = 0.0, float $floor = 0.0 ): array {
		// Lighthouse's simulated mobile link: 1.6 Mbit/s, less the 5% it
		// holds back for protocol overhead.
		$throughput = 1600 * 1024 / 8 * 0.95;
		$seconds    = $saved / max( 1.0, $throughput );

		$out = array( 'seconds' => round( $seconds, 1 ) );

		if ( $lcp <= 0 ) {
			return $out;
		}

		// Weight is not the only thing LCP waits for. The server still has
		// to answer and the page still has to draw, and no amount of saved
		// bytes goes below that. Without a measured First Contentful Paint
		// to stand on, hold the forecast at a second and a half — near the
		// best a WordPress page reaches on a phone — so the arithmetic can
		// never promise an instant page.
		$floor = $floor > 0 ? $floor : 1.5;
		$after = max( $floor, $lcp - $seconds );

		$was = self::lcp_score( $lcp );
		$now = self::lcp_score( $after );

		$out['lcp_now']   = round( $lcp, 1 );
		$out['lcp_after'] = round( $after, 1 );
		$out['floored']   = $lcp - $seconds < $floor;

		// LCP carries a quarter of the performance score.
		$out['points'] = (int) round( ( $now - $was ) * 25 );

		return $out;
	}

	/**
	 * Lighthouse's LCP curve: p10 at 2.5s, median at 4s.
	 *
	 * @param float $seconds Largest Contentful Paint.
	 * @return float 0 to 1.
	 */
	private static function lcp_score( float $seconds ): float {
		$location = log( 4.0 );
		$shape    = abs( log( 2.5 ) - $location ) / ( M_SQRT2 * 0.9061938024368232 );
		$x        = ( log( max( 0.01, $seconds ) ) - $location ) / ( M_SQRT2 * $shape );

		return max( 0.0, min( 1.0, ( 1 - self::erf( $x ) ) / 2 ) );
	}

	/**
	 * The error function, to the accuracy the curve above needs.
	 *
	 * Abramowitz and Stegun 7.1.26.
	 *
	 * @param float $x Argument.
	 */
	private static function erf( float $x ): float {
		$sign = $x < 0 ? -1 : 1;
		$x    = abs( $x );

		$t = 1 / ( 1 + 0.3275911 * $x );
		$y = 1 - ( ( ( ( ( 1.061405429 * $t - 1.453152027 ) * $t ) + 1.421413741 ) * $t - 0.284496736 ) * $t + 0.254829592 ) * $t * exp( -$x * $x );

		return $sign * $y;
	}

	/**
	 * Re-encode one picture in place: never wider than the site can show,
	 * and no heavier than it needs to be at that width.
	 *
	 * The address does not change — every reference in the shop keeps
	 * working — and the untouched original is kept beside it, so a shrink
	 * can always be undone.
	 *
	 * @param int $id      Attachment ID.
	 * @param int $quality JPEG quality.
	 * @return array<string,mixed>
	 */
	public static function shrink( int $id, int $quality = 82 ): array {
		$file = (string) get_attached_file( $id );
		$mime = (string) get_post_mime_type( $id );

		if ( '' === $file || ! file_exists( $file ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'The file is not on the server.', 'oc-theme' ),
			);
		}

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'Only JPEG and PNG pictures can be shrunk here.', 'oc-theme' ),
			);
		}

		$before = (int) filesize( $file );
		$backup = (string) get_post_meta( $id, self::BACKUP, true );

		// The original is copied aside once, on the first shrink only, so a
		// second pass never overwrites the good copy with a shrunk one.
		if ( '' === $backup ) {
			$backup = $file . '.ocfull';

			if ( ! copy( $file, $backup ) ) {
				return array(
					'ok'  => false,
					'why' => __( 'The original could not be copied aside, so nothing was changed.', 'oc-theme' ),
				);
			}

			update_post_meta( $id, self::BACKUP, $backup );
		}

		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return array(
				'ok'  => false,
				'why' => $editor->get_error_message(),
			);
		}

		$size = $editor->get_size();

		if ( max( (int) $size['width'], (int) $size['height'] ) > self::MAX_SIDE ) {
			$editor->resize( self::MAX_SIDE, self::MAX_SIDE, false );
		}

		$editor->set_quality( max( 40, min( 100, $quality ) ) );
		$saved = $editor->save( $file, $mime );

		if ( is_wp_error( $saved ) ) {
			return array(
				'ok'  => false,
				'why' => $saved->get_error_message(),
			);
		}

		clearstatcache( true, $file );
		$after = (int) filesize( $file );

		// A PNG of a photograph barely moves; when the new file is no better
		// than the old one, put the original straight back.
		if ( $after >= $before ) {
			copy( $backup, $file );
			wp_delete_file( $backup );
			delete_post_meta( $id, self::BACKUP );
			clearstatcache( true, $file );

			return array(
				'ok'     => false,
				'why'    => __( 'Re-encoding saved nothing, so the original was kept. A PNG photograph only gets smaller by becoming a JPEG or WebP, which changes its address.', 'oc-theme' ),
				'before' => $before,
				'after'  => $before,
			);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );

		return array(
			'ok'     => true,
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * Put a shrunk picture back the way it was.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string,mixed>
	 */
	public static function restore( int $id ): array {
		$backup = (string) get_post_meta( $id, self::BACKUP, true );
		$file   = (string) get_attached_file( $id );

		if ( '' === $backup || ! file_exists( $backup ) || '' === $file ) {
			return array(
				'ok'  => false,
				'why' => __( 'There is no original kept for this one.', 'oc-theme' ),
			);
		}

		if ( ! copy( $backup, $file ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'The original could not be put back.', 'oc-theme' ),
			);
		}

		wp_delete_file( $backup );
		delete_post_meta( $id, self::BACKUP );
		clearstatcache( true, $file );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );

		return array(
			'ok'    => true,
			'after' => (int) filesize( $file ),
		);
	}

	/**
	 * What one page actually makes a visitor download.
	 *
	 * The page is fetched and read the way a browser reads it — every
	 * picture, film and poster it names — because that, and not what the
	 * library holds, is the number the visitor feels. Files are matched
	 * back to the library where they came from it, so a heavy one can be
	 * shrunk from here.
	 *
	 * @param string $url Address on this site.
	 * @return array<string,mixed>
	 */
	public static function page_media( string $url ): array {
		$url  = esc_url_raw( trim( $url ) );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $url || $host !== $home ) {
			return array(
				'ok'  => false,
				'why' => __( 'That address is not on this site.', 'oc-theme' ),
			);
		}

		$answer = wp_remote_get(
			$url,
			array(
				'timeout'    => 25,
				'user-agent' => 'Mozilla/5.0 (Linux; Android 11) AppleWebKit/537.36 Chrome/152.0 Mobile Safari/537.36',
			)
		);

		if ( is_wp_error( $answer ) ) {
			return array(
				'ok'  => false,
				'why' => $answer->get_error_message(),
			);
		}

		$html = (string) wp_remote_retrieve_body( $answer );

		if ( '' === $html ) {
			return array(
				'ok'  => false,
				'why' => __( 'The page returned nothing.', 'oc-theme' ),
			);
		}

		$hits = array();

		// src, poster and the addresses inside inline styles. srcset is left
		// alone on purpose: it offers a browser many widths and the browser
		// takes one, so counting them all would invent weight nobody loads.
		// A picture held back until the visitor scrolls is not what they
		// wait for, so each address also remembers whether anything on the
		// page asks for it straight away. Eager wins: one eager mention is
		// enough, however many lazy ones sit beside it.
		$eager = array();

		if ( preg_match_all( '/<(?:img|video|source|iframe)\b[^>]*>/i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( ! preg_match( '/\b(?:src|poster)\s*=\s*["\']([^"\']+)/i', $tag, $hit ) ) {
					continue;
				}

				$one = html_entity_decode( trim( $hit[1] ), ENT_QUOTES );

				if ( 0 === strpos( $one, 'data:' ) || '' === $one ) {
					continue;
				}

				$key           = strtok( $one, '?' );
				$hits[ $key ]  = true;
				$lazy          = (bool) preg_match( '/\bloading\s*=\s*["\']?lazy/i', $tag );
				$eager[ $key ] = ( $eager[ $key ] ?? false ) || ! $lazy;
			}
		}

		// Backgrounds in inline styles: no loading attribute exists, and the
		// browser fetches them as soon as the rule applies.
		if ( preg_match_all( '/url\(\s*["\']?([^"\')]+\.(?:jpe?g|png|gif|webp|avif|svg|mp4|webm))/i', $html, $m ) ) {
			foreach ( $m[1] as $one ) {
				$one = html_entity_decode( trim( $one ), ENT_QUOTES );

				if ( 0 === strpos( $one, 'data:' ) || '' === $one ) {
					continue;
				}

				$key           = strtok( $one, '?' );
				$hits[ $key ]  = true;
				$eager[ $key ] = true;
			}
		}

		$uploads = wp_get_upload_dir();
		$items   = array();
		$total   = 0;

		foreach ( array_keys( $hits ) as $link ) {
			$now  = (bool) ( $eager[ $link ] ?? true );
			$full = 0 === strpos( $link, '//' ) ? 'https:' . $link : $link;

			if ( 0 === strpos( $full, '/' ) ) {
				$full = home_url( $full );
			}

			if ( wp_parse_url( $full, PHP_URL_HOST ) !== $home ) {
				continue;
			}

			$path = self::disk_path( $full, $uploads );

			if ( '' === $path || ! file_exists( $path ) ) {
				continue;
			}

			$bytes  = (int) filesize( $path );
			$total += $bytes;
			$id     = 0 === strpos( $full, (string) $uploads['baseurl'] ) ? self::attachment_of( $full ) : 0;

			$items[] = array(
				'url'    => $full,
				'name'   => wp_basename( $path ),
				'bytes'  => $bytes,
				'eager'  => $now,
				'id'     => $id,
				'mime'   => (string) ( wp_check_filetype( $path )['type'] ?? '' ),
				'thumb'  => $id > 0 ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '',
				'link'   => $id > 0 ? get_edit_post_link( $id, 'raw' ) : '',
				'shrink' => $id > 0 && self::shrinkable( (string) ( wp_check_filetype( $path )['type'] ?? '' ) ),
				'backup' => $id > 0 && '' !== (string) get_post_meta( $id, self::BACKUP, true ),
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		return array(
			'ok'    => true,
			'items' => array_slice( $items, 0, 60 ),
			'bytes' => $total,
			'count' => count( $items ),
			'html'  => strlen( $html ),
			'url'   => $url,
		);
	}

	/**
	 * Where a URL on this site lands on disk, or '' when it is not ours.
	 *
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $uploads Upload directory info.
	 */
	private static function disk_path( string $url, array $uploads ): string {
		foreach ( array(
			array( (string) $uploads['baseurl'], (string) $uploads['basedir'] ),
			array( content_url(), (string) WP_CONTENT_DIR ),
		) as $pair ) {
			if ( 0 === strpos( $url, $pair[0] ) ) {
				return $pair[1] . substr( $url, strlen( $pair[0] ) );
			}
		}

		return '';
	}

	/**
	 * The library item a served picture came from, size suffix and all.
	 *
	 * @param string $url Absolute URL inside the uploads folder.
	 */
	private static function attachment_of( string $url ): int {
		$id = attachment_url_to_postid( $url );

		if ( $id > 0 ) {
			return $id;
		}

		// "photo-600x750.png" is a size WordPress generated from "photo.png".
		$bare = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url );

		return $bare === $url ? 0 : attachment_url_to_postid( $bare );
	}

	/**
	 * Have WordPress build every new picture's sizes as WebP.
	 *
	 * Only the generated sizes change, and only for pictures uploaded from
	 * now on: the original keeps its own format and its own address, and
	 * nothing already in the shop is touched. It is the one place a format
	 * can be changed without rewriting a single reference, because the
	 * references are written afterwards.
	 *
	 * @param array<string,string> $formats Mime => mime.
	 * @return array<string,string>
	 */
	public function webp_sizes( array $formats ): array {
		if ( ! get_option( self::WEBP, false ) ) {
			return $formats;
		}

		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return $formats;
		}

		$formats['image/jpeg'] = 'image/webp';
		$formats['image/png']  = 'image/webp';

		return $formats;
	}

	/**
	 * Turn one picture already in the library into WebP.
	 *
	 * Nothing is deleted. WordPress rebuilds the file and all its sizes in
	 * the new format and starts serving those; the old files stay where
	 * they are, so a hand-written address somewhere in the shop still
	 * answers, and the change can be undone exactly.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string,mixed>
	 */
	public static function to_webp( int $id ): array {
		$mime = (string) get_post_mime_type( $id );

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'Only JPEG and PNG pictures can become WebP.', 'oc-theme' ),
			);
		}

		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'This server cannot write WebP.', 'oc-theme' ),
			);
		}

		$source = (string) wp_get_original_image_path( $id );
		$source = '' !== $source && file_exists( $source ) ? $source : (string) get_attached_file( $id );

		if ( '' === $source || ! file_exists( $source ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'The file is not on the server.', 'oc-theme' ),
			);
		}

		$before = self::bytes( $id );

		// Everything needed to put it back exactly as it was.
		// An array, not JSON: WordPress strips the backslashes out of a meta
		// string, and `\u05d1` becoming `u05d1` turns every Hebrew filename
		// here into one that does not exist.
		if ( ! is_array( get_post_meta( $id, self::PREWEBP, true ) ) ) {
			update_post_meta(
				$id,
				self::PREWEBP,
				array(
					'file' => (string) get_post_meta( $id, '_wp_attached_file', true ),
					'meta' => (array) wp_get_attachment_metadata( $id ),
				)
			);
		}

		$force = static function ( array $formats ): array {
			$formats['image/jpeg'] = 'image/webp';
			$formats['image/png']  = 'image/webp';

			return $formats;
		};

		require_once ABSPATH . 'wp-admin/includes/image.php';

		add_filter( 'image_editor_output_format', $force, 99 );
		$meta = wp_create_image_subsizes( $source, $id );
		remove_filter( 'image_editor_output_format', $force, 99 );

		if ( empty( $meta['file'] ) || 'webp' !== strtolower( (string) pathinfo( (string) $meta['file'], PATHINFO_EXTENSION ) ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'WordPress did not produce a WebP file.', 'oc-theme' ),
			);
		}

		wp_update_attachment_metadata( $id, $meta );

		// The row still called itself a PNG. Anything that asks WordPress what
		// this attachment is — the library's type filter, a block deciding
		// what it may do with it — was being told the old answer.
		wp_update_post(
			array(
				'ID'             => $id,
				'post_mime_type' => 'image/webp',
			)
		);

		$after = self::bytes( $id );

		return array(
			'ok'     => true,
			'before' => $before,
			'after'  => $after,
			'name'   => wp_basename( (string) $meta['file'] ),
			'olds'   => count( self::old_files( $id ) ),
		);
	}

	/**
	 * The files a converted picture left behind: its own previous format,
	 * every size it used to have, and the untouched upload.
	 *
	 * @param int $id Attachment ID.
	 * @return array<int,string> Absolute paths.
	 */
	public static function old_files( int $id ): array {
		$was = get_post_meta( $id, self::PREWEBP, true );

		if ( ! is_array( $was ) ) {
			return array();
		}

		$uploads = wp_get_upload_dir();
		$base    = (string) $uploads['basedir'];
		$now     = (string) get_post_meta( $id, '_wp_attached_file', true );
		$live    = array( $now );

		foreach ( (array) ( wp_get_attachment_metadata( $id )['sizes'] ?? array() ) as $size ) {
			$live[] = dirname( $now ) . '/' . (string) ( $size['file'] ?? '' );
		}

		$old  = array();
		$file = (string) ( $was['file'] ?? '' );

		if ( '' !== $file ) {
			$old[] = $file;

			foreach ( (array) ( $was['meta']['sizes'] ?? array() ) as $size ) {
				$old[] = dirname( $file ) . '/' . (string) ( $size['file'] ?? '' );
			}

			$original = (string) ( $was['meta']['original_image'] ?? '' );

			if ( '' !== $original ) {
				$old[] = dirname( $file ) . '/' . $original;
			}
		}

		$out = array();

		foreach ( array_unique( $old ) as $one ) {
			if ( in_array( $one, $live, true ) ) {
				continue;
			}

			$path = $base . '/' . ltrim( $one, '/' );

			if ( file_exists( $path ) ) {
				$out[] = $path;
			}
		}

		return $out;
	}

	/**
	 * Remove what a conversion left behind — but only when nothing anywhere
	 * writes that name, because a hand-written address is the one reference
	 * the metadata cannot tell us about.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string,mixed>
	 */
	public static function drop_old( int $id ): array {
		$files = self::old_files( $id );

		if ( empty( $files ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'There is nothing left over for this one.', 'oc-theme' ),
			);
		}

		if ( self::name_referenced( $id, false ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'The old name is written somewhere on the site, so the old file was kept.', 'oc-theme' ),
			);
		}

		$freed = 0;
		$dir   = dirname( (string) get_attached_file( $id ) );

		/**
		 * As in bytes(): core's documented shape omits original_image, which
		 * is exactly the key this has to look at.
		 *
		 * @var array<string,mixed> $meta
		 */
		$meta = (array) wp_get_attachment_metadata( $id );

		foreach ( $files as $path ) {
			$freed += (int) filesize( $path );
			wp_delete_file( $path );

			// The file WordPress still calls this picture's original may be
			// one of these; the key has to go with it or wp_get_original_
			// image_path() hands out an address that answers nothing.
			if ( ! empty( $meta['original_image'] ) && $dir . '/' . (string) $meta['original_image'] === $path ) {
				unset( $meta['original_image'] );
				wp_update_attachment_metadata( $id, $meta );
			}
		}

		delete_post_meta( $id, self::PREWEBP );

		return array(
			'ok'    => true,
			'freed' => $freed,
			'gone'  => count( $files ),
		);
	}

	/**
	 * Put a converted picture back the way it was.
	 *
	 * Only possible while the files it replaced are still there — which is
	 * exactly what "clear the file it replaces" gives up.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string,mixed>
	 */
	public static function undo_webp( int $id ): array {
		$was = get_post_meta( $id, self::PREWEBP, true );

		if ( ! is_array( $was ) || empty( $was['file'] ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'There is nothing to go back to for this one.', 'oc-theme' ),
			);
		}

		$uploads = wp_get_upload_dir();
		$path    = (string) $uploads['basedir'] . '/' . ltrim( (string) $was['file'], '/' );

		if ( ! file_exists( $path ) ) {
			return array(
				'ok'  => false,
				'why' => __( 'The file it replaced is gone, so this cannot be undone.', 'oc-theme' ),
			);
		}

		// The WebP files go; the old ones were never moved, only left behind.
		foreach ( self::live_files( $id ) as $gone ) {
			wp_delete_file( $gone );
		}

		update_post_meta( $id, '_wp_attached_file', (string) $was['file'] );
		wp_update_attachment_metadata( $id, (array) $was['meta'] );

		// Put the row's own idea of its format back with the file, or the
		// picture is a PNG again while WordPress still calls it a WebP.
		$type = (string) ( wp_check_filetype( (string) $was['file'] )['type'] ?? '' );

		if ( '' !== $type ) {
			wp_update_post(
				array(
					'ID'             => $id,
					'post_mime_type' => $type,
				)
			);
		}

		delete_post_meta( $id, self::PREWEBP );

		return array(
			'ok'    => true,
			'after' => self::bytes( $id ),
			'name'  => wp_basename( (string) $was['file'] ),
		);
	}

	/**
	 * The files an attachment is serving right now.
	 *
	 * @param int $id Attachment ID.
	 * @return array<int,string> Absolute paths.
	 */
	private static function live_files( int $id ): array {
		$file = (string) get_attached_file( $id );

		if ( '' === $file ) {
			return array();
		}

		$dir  = dirname( $file );
		$out  = file_exists( $file ) ? array( $file ) : array();
		$meta = (array) wp_get_attachment_metadata( $id );

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			$path = $dir . '/' . (string) ( $size['file'] ?? '' );

			if ( '' !== (string) ( $size['file'] ?? '' ) && file_exists( $path ) ) {
				$out[] = $path;
			}
		}

		return $out;
	}

	/**
	 * Undo one conversion, over ajax.
	 */
	public function ajax_undo(): void {
		$this->guard();

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().

		wp_send_json_success( self::undo_webp( $id ) );
	}

	/**
	 * The library split by format: what is not WebP yet, and what is.
	 *
	 * @param string $have  'no' for pictures still to convert, 'yes' for the converted.
	 * @param string $url   One page to look at; empty means the whole library.
	 * @param int    $limit Longest list to return.
	 * @return array<string,mixed>
	 */
	public static function by_format( string $have, string $url = '', int $limit = 300, int $floor = 0 ): array {
		global $wpdb;

		$want = 'yes' === $have;
		$ids  = array();

		if ( '' !== trim( $url ) ) {
			$page = self::page_media( $url );

			if ( empty( $page['ok'] ) ) {
				return array(
					'items' => array(),
					'total' => 0,
					'bytes' => 0,
					'why'   => (string) ( $page['why'] ?? '' ),
				);
			}

			foreach ( (array) $page['items'] as $one ) {
				if ( (int) $one['id'] > 0 ) {
					$ids[] = (int) $one['id'];
				}
			}

			$ids = array_values( array_unique( $ids ) );

			if ( empty( $ids ) ) {
				return array(
					'items' => array(),
					'total' => 0,
					'bytes' => 0,
				);
			}

			// A page names a few dozen files at most, so each one is asked for
			// by id rather than built into a query of its own.
			$rows = array();

			foreach ( $ids as $one ) {
				$rows[] = (object) array(
					'ID'             => $one,
					'post_mime_type' => (string) get_post_mime_type( $one ),
				);
			}
		} else {
			// The file name and the stored metadata come along with the row.
			// Asking for them per picture is a query each, and on a library
			// of seventeen thousand that alone is what made this screen slow.
			$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- maintenance scan; live counts cannot cache.
				"SELECT p.ID, p.post_mime_type, f.meta_value AS file, d.meta_value AS meta
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
				 LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_wp_attachment_metadata'
				 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
				 ORDER BY p.ID DESC"
			);
		}

		$items = array();
		$total = 0;
		$bytes = 0;

		foreach ( $rows as $row ) {
			$id   = (int) $row->ID;
			$file = isset( $row->file ) ? (string) $row->file : (string) get_post_meta( $id, '_wp_attached_file', true );
			$ext  = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );

			if ( 'svg' === $ext || '' === $ext ) {
				continue;
			}

			$is = 'webp' === $ext;

			if ( $is !== $want ) {
				continue;
			}

			// Only what this tool can actually act on.
			if ( ! $want && ! in_array( (string) $row->post_mime_type, array( 'image/jpeg', 'image/png' ), true ) ) {
				continue;
			}

			// The weight the row is judged and counted by. It comes from what
			// the upload recorded, so asking for a whole library costs no
			// disk at all; only the few that get listed are really weighed.
			$weight = isset( $row->meta ) ? self::meta_bytes( (string) $row->meta, $id ) : self::bytes( $id );

			if ( $floor > 0 && $weight < $floor ) {
				continue;
			}

			++$total;

			// Every row counts towards the weight, or the screen reports the
			// size of the page it is showing and calls it the library's.
			// Beyond the listed few that figure comes from the metadata the
			// upload already wrote down: asking the disk for a hundred
			// thousand files is what a headline number is not worth.
			if ( count( $items ) >= $limit ) {
				$bytes += $weight;
				continue;
			}

			$size   = self::bytes( $id );
			$bytes += $size;
			$olds   = $want ? self::old_files( $id ) : array();
			$spare  = 0;

			foreach ( $olds as $path ) {
				$spare += (int) filesize( $path );
			}

			$items[] = array(
				'id'    => $id,
				'name'  => wp_basename( $file ),
				'bytes' => $size,
				'thumb' => wp_get_attachment_image_url( $id, 'thumbnail' ),
				'link'  => get_edit_post_link( $id, 'raw' ),
				'mime'  => (string) $row->post_mime_type,
				'olds'  => count( $olds ),
				'spare' => $spare,
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
			'bytes' => $bytes,
			'shown' => count( $items ),
			'page'  => trim( $url ),
			'floor' => $floor,
		);
	}

	/**
	 * What one picture weighs according to what the upload wrote down.
	 *
	 * The stored metadata carries a filesize for the picture and for each
	 * size made from it. Reading those is free; reaching for the disk is
	 * not, so that is kept for the handful the screen actually lists.
	 *
	 * @param string $blob Serialised attachment metadata.
	 * @param int    $id   Attachment id, for the fallback.
	 */
	private static function meta_bytes( string $blob, int $id ): int {
		$meta = '' !== $blob ? maybe_unserialize( $blob ) : null;

		if ( ! is_array( $meta ) ) {
			return 0;
		}

		$total = (int) ( $meta['filesize'] ?? 0 );

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			$total += (int) ( $size['filesize'] ?? 0 );
		}

		// Older uploads recorded no size at all. One look at the disk is
		// better than a headline that quietly leaves them out.
		return $total > 0 ? $total : self::bytes( $id );
	}

	/**
	 * The format lists, over ajax.
	 */
	public function ajax_formats(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().
		$have  = isset( $_POST['have'] ) ? sanitize_key( wp_unslash( $_POST['have'] ) ) : 'no';
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$floor = isset( $_POST['floor'] ) ? absint( wp_unslash( $_POST['floor'] ) ) : 0;
		// phpcs:enable

		wp_send_json_success( self::by_format( 'yes' === $have ? 'yes' : 'no', $url, 300, $floor * KB_IN_BYTES ) );
	}

	/**
	 * Convert one picture, over ajax.
	 */
	public function ajax_convert(): void {
		$this->guard();

		$id  = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().
		$out = self::to_webp( $id );

		if ( ! empty( $out['ok'] ) && get_option( self::DROP, false ) ) {
			$gone = self::drop_old( $id );

			$out['dropped'] = ! empty( $gone['ok'] );
			$out['freed']   = (int) ( $gone['freed'] ?? 0 );
		}

		wp_send_json_success( $out );
	}

	/**
	 * Clear one picture's leftovers, over ajax.
	 */
	public function ajax_drop(): void {
		$this->guard();

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().

		wp_send_json_success( self::drop_old( $id ) );
	}

	/**
	 * Turn WebP sizes on or off, over ajax.
	 */
	public function ajax_webp(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().
		$on  = isset( $_POST['on'] ) && '1' === sanitize_key( wp_unslash( $_POST['on'] ) );
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : 'webp';
		// phpcs:enable

		update_option( 'drop' === $key ? self::DROP : self::WEBP, $on ? 1 : 0, false );

		wp_send_json_success( array( 'on' => $on ) );
	}

	/**
	 * The heavy list, over ajax.
	 */
	public function ajax_heavy(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().
		$raw  = isset( $_POST['min'] ) ? sanitize_key( wp_unslash( $_POST['min'] ) ) : '500';
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'all';
		$lcp  = isset( $_POST['lcp'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['lcp'] ) ) : 0.0;
		// phpcs:enable

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().

		$type = in_array( $type, array( 'all', 'image', 'video' ), true ) ? $type : 'all';

		// Zero is the signal for "heavier than average"; heavy() works it out.
		$min = 'avg' === $raw ? 0 : absint( $raw ) * KB_IN_BYTES;
		$out = self::heavy( $min, $type, $url );

		// The seconds come from the weight the visitor actually waits for.
		$upfront = $out['webp']['upfront'] ?? $out['webp']['saved'] ?? 0;

		$out['speed'] = self::speed_gain( (int) $upfront, max( 0.0, min( 60.0, $lcp ) ) );

		wp_send_json_success( $out );
	}

	/**
	 * Shrink one picture, over ajax.
	 */
	public function ajax_shrink(): void {
		$this->guard();

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().

		wp_send_json_success( self::shrink( $id ) );
	}

	/**
	 * Undo one shrink, over ajax.
	 */
	public function ajax_restore(): void {
		$this->guard();

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().

		wp_send_json_success( self::restore( $id ) );
	}

	/*
	 * ------------------------------------------------------------- the deleting
	 */

	/**
	 * Delete a batch, re-checking each one first.
	 */
	public function ajax_delete(): void {
		$this->guard();

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above ran check_ajax_referer().

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
				++$kept;
				continue;
			}

			if ( self::name_referenced( $id, 'orphan' !== $bucket ) ) {
				++$kept;
				continue;
			}

			$bytes = self::bytes( $id );
			$name  = wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );

			if ( wp_delete_attachment( $id, true ) ) {
				++$deleted;
				$freed += $bytes;

				$log[] = array(
					'id'    => $id,
					'name'  => $name,
					'bytes' => $bytes,
					'when'  => gmdate( 'Y-m-d H:i:s' ),
					'who'   => wp_get_current_user()->user_login,
				);
			} else {
				++$kept;
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

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming CSV to php://output; no file is touched.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- UTF-8 BOM so Excel reads Hebrew; still the php://output stream.
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

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- closes the php://output stream.
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
