<?php
/**
 * How a product picture sits in a frame — everywhere.
 *
 * A shop's pictures come in every proportion: a studio shot of a handle
 * is wide, a floor lamp is tall, a supplier's catalogue crop is square.
 * Every frame on the site — the catalogue card, the gallery at a uniform
 * height, the thumbnail rail, the search panel, the cart — has a shape
 * of its own, and a picture that does not match it was cropped: the
 * handle lost its ends, the lamp its shade.
 *
 * The answer that looks right is decided once per picture, on the
 * server, and written into the markup, so nothing measures or jumps in
 * the browser. Each picture is looked at along its edges. A picture on a
 * plain ground — the studio shot on white, the packshot on grey, a PNG
 * cut out on transparency — is shown whole inside every frame, with the
 * frame painted in that very ground colour, so the padding is invisible
 * and the product simply sits in its box. A picture with a busy edge — a
 * room, a model, a lifestyle scene — fills the frame and is cropped: a
 * scene survives a crop, a product does not.
 *
 * The decision travels as a class and a colour on the <img> itself, put
 * there by the one filter every attachment image passes through, so the
 * card, the gallery, the rail, the search results, the cart, the menu
 * panel and the blocks all agree without each being taught separately.
 * WooCommerce's own square-cropped thumbnail size is swapped for an
 * uncropped one wherever a picture is to be shown whole — a crop baked
 * into the file cannot be undone by a stylesheet.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

if ( ! defined( 'ABSPATH' ) && ! defined( 'OC_TESTS' ) ) {
	exit;
}

/**
 * Per-picture fit: analysis, storage, markup.
 */
final class Image_Fit {

	/**
	 * Attachment meta holding the verdict.
	 */
	public const META = '_oc_fit';

	/**
	 * Bump when the analysis changes, so old verdicts are redone.
	 */
	public const VERSION = 1;

	/**
	 * Cron hook for the background backfill.
	 */
	public const CRON = 'oc_image_fit_backfill';

	/**
	 * Fresh analyses allowed in one request before the rest is left to cron.
	 */
	private const BUDGET = 12;

	/**
	 * Share of edge pixels that must match the ground for it to count as plain.
	 */
	private const PLAIN = 0.9;

	/**
	 * Per-channel distance (0–255) within which a pixel is "the same" colour.
	 */
	private const TOLERANCE = 22;

	/**
	 * Analyses done in this request.
	 *
	 * @var int
	 */
	private static int $spent = 0;

	/**
	 * True while the src filter is asking for another size (no recursion).
	 *
	 * @var bool
	 */
	private static bool $swapping = false;

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'attributes' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'uncropped_src' ), 20, 3 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_upload' ), 20, 2 );
		add_action( self::CRON, array( __CLASS__, 'backfill' ) );
	}

	/*
	 * ------------------------------------------------------------ settings
	 */

	/**
	 * The shop's policy: smart (per picture), contain (always whole) or
	 * cover (always fill, crop).
	 */
	public static function policy(): string {
		$policy = (string) get_theme_mod( 'oc_image_fit', 'smart' );

		return in_array( $policy, array( 'smart', 'contain', 'cover' ), true ) ? $policy : 'smart';
	}

	/**
	 * How this picture should sit in a frame: 'contain', 'cover', or '' when
	 * it has not been looked at yet (the markup is then left as it was).
	 *
	 * @param int $attachment_id Attachment.
	 */
	public static function mode( int $attachment_id ): string {
		$policy = self::policy();

		if ( 'smart' !== $policy ) {
			return $policy;
		}

		$fit = self::verdict( $attachment_id );

		if ( null === $fit ) {
			return '';
		}

		return $fit['plain'] ? 'contain' : 'cover';
	}

	/**
	 * The ground colour a whole picture is padded with, as a CSS colour, or
	 * '' for the surface colour.
	 *
	 * @param int $attachment_id Attachment.
	 */
	public static function ground( int $attachment_id ): string {
		$fit = self::verdict( $attachment_id );

		return null !== $fit ? (string) $fit['bg'] : '';
	}

	/**
	 * Class and style for a hand-built <img>, for the few places that do
	 * not go through wp_get_attachment_image().
	 *
	 * @param int $attachment_id Attachment.
	 * @return array{class:string,style:string}
	 */
	public static function attrs( int $attachment_id ): array {
		$mode = self::mode( $attachment_id );

		if ( '' === $mode ) {
			return array(
				'class' => '',
				'style' => '',
			);
		}

		$bg = 'contain' === $mode ? self::ground( $attachment_id ) : '';

		return array(
			'class' => 'oc-fit oc-fit--' . $mode,
			'style' => '' !== $bg ? '--oc-fit-bg:' . $bg . ';' : '',
		);
	}

	/*
	 * ------------------------------------------------------------ filters
	 */

	/**
	 * Every attachment image carries its fit.
	 *
	 * @param array<string,string> $attr       Attributes.
	 * @param \WP_Post             $attachment The attachment.
	 * @return array<string,string>
	 */
	public function attributes( $attr, $attachment ) {
		if ( ! $attachment instanceof \WP_Post || ! self::is_raster( (string) $attachment->post_mime_type ) ) {
			return $attr;
		}

		$add = self::attrs( (int) $attachment->ID );

		if ( '' === $add['class'] ) {
			return $attr;
		}

		$attr['class'] = trim( (string) ( $attr['class'] ?? '' ) . ' ' . $add['class'] );

		if ( '' !== $add['style'] ) {
			$attr['style'] = trim( (string) ( $attr['style'] ?? '' ) ) . $add['style'];
		}

		return $attr;
	}

	/**
	 * A picture to be shown whole must not come from WooCommerce's cropped
	 * thumbnail file: the crop is in the pixels. Ask for an uncropped size
	 * of about the same width instead.
	 *
	 * @param array|false  $image         [url, width, height, is_intermediate].
	 * @param int          $attachment_id Attachment.
	 * @param string|int[] $size          Requested size.
	 * @return array|false
	 */
	public function uncropped_src( $image, $attachment_id, $size ) {
		if ( self::$swapping || ! is_array( $image ) || ! is_string( $size ) ) {
			return $image;
		}

		if ( ! in_array( $size, array( 'woocommerce_thumbnail', 'woocommerce_gallery_thumbnail' ), true ) ) {
			return $image;
		}

		if ( ! function_exists( 'wc_get_image_size' ) || empty( wc_get_image_size( $size )['crop'] ) ) {
			return $image;
		}

		if ( 'contain' !== self::mode( (int) $attachment_id ) ) {
			return $image;
		}

		self::$swapping = true;
		$swap           = wp_get_attachment_image_src( (int) $attachment_id, 'woocommerce_gallery_thumbnail' === $size ? 'medium' : 'medium_large' );
		self::$swapping = false;

		return is_array( $swap ) ? $swap : $image;
	}

	/**
	 * A new upload is judged as its sizes are made. A filter: the metadata
	 * goes back untouched.
	 *
	 * @param array<string,mixed> $metadata      Attachment metadata.
	 * @param int                 $attachment_id Attachment.
	 * @return array<string,mixed>
	 */
	public function on_upload( $metadata, $attachment_id ) {
		if ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) {
			self::analyse( (int) $attachment_id );
		}

		return $metadata;
	}

	/*
	 * ------------------------------------------------------------ verdicts
	 */

	/**
	 * The stored verdict, made on the spot when the request still has
	 * budget for it, otherwise left to the background job.
	 *
	 * @param int $attachment_id Attachment.
	 * @return array{plain:bool,bg:string}|null
	 */
	public static function verdict( int $attachment_id ): ?array {
		$fit = get_post_meta( $attachment_id, self::META, true );

		if ( is_array( $fit ) && (int) ( $fit['v'] ?? 0 ) === self::VERSION ) {
			return array(
				'plain' => ! empty( $fit['plain'] ),
				'bg'    => (string) ( $fit['bg'] ?? '' ),
			);
		}

		if ( self::$spent >= self::BUDGET ) {
			self::book();

			return null;
		}

		++self::$spent;

		return self::analyse( $attachment_id );
	}

	/**
	 * Look at the picture and remember what was seen.
	 *
	 * @param int $attachment_id Attachment.
	 * @return array{plain:bool,bg:string}
	 */
	public static function analyse( int $attachment_id ): array {
		$file = self::small_file( $attachment_id );
		$seen = '' !== $file ? self::look( $file ) : null;

		// A picture that cannot be read is remembered as busy, so it is
		// not opened again on every request; a later upload of the same
		// attachment (on_upload) forces a fresh look.
		$fit = array(
			'v'     => self::VERSION,
			'plain' => null !== $seen && $seen['plain'],
			'bg'    => null !== $seen ? $seen['bg'] : '',
			't'     => time(),
		);

		update_post_meta( $attachment_id, self::META, $fit );

		return array(
			'plain' => (bool) $fit['plain'],
			'bg'    => (string) $fit['bg'],
		);
	}

	/**
	 * The smallest file of the attachment worth reading — the edges of a
	 * 150px thumbnail say the same as the edges of the original.
	 *
	 * @param int $attachment_id Attachment.
	 */
	private static function small_file( int $attachment_id ): string {
		$file = (string) get_attached_file( $attachment_id );

		if ( '' === $file ) {
			return '';
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$dir  = dirname( $file );

		$full = ! empty( $meta['width'] ) && ! empty( $meta['height'] )
			? (int) $meta['width'] / max( 1, (int) $meta['height'] )
			: 0.0;

		foreach ( array( 'thumbnail', 'medium', 'medium_large' ) as $size ) {
			$one  = (array) ( $meta['sizes'][ $size ] ?? array() );
			$name = (string) ( $one['file'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			// A size cropped to another shape has no edges of its own: the
			// classic WordPress thumbnail is cropped square, so it is used
			// only when the picture itself is square.
			if ( $full > 0 && ! empty( $one['width'] ) && ! empty( $one['height'] ) ) {
				$ratio = (int) $one['width'] / max( 1, (int) $one['height'] );

				if ( abs( $ratio - $full ) > 0.05 ) {
					continue;
				}
			}

			if ( file_exists( $dir . '/' . $name ) ) {
				return $dir . '/' . $name;
			}
		}

		return file_exists( $file ) ? $file : '';
	}

	/**
	 * Read the edge of a picture file.
	 *
	 * @param string $file Absolute path.
	 * @return array{plain:bool,bg:string}|null
	 */
	private static function look( string $file ): ?array {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}

		$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file, not a URL.

		if ( false === $bytes || '' === $bytes ) {
			return null;
		}

		$img = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corrupt file must not print.

		if ( false === $img ) {
			return null;
		}

		$w = imagesx( $img );
		$h = imagesy( $img );

		if ( $w < 4 || $h < 4 ) {
			imagedestroy( $img );

			return null;
		}

		// Up to ~160 pixels per edge, one pixel in from the border (the
		// outermost row is where JPEG ringing lives).
		$samples = array();
		$step_x  = max( 1, (int) floor( $w / 160 ) );
		$step_y  = max( 1, (int) floor( $h / 160 ) );

		for ( $x = 1; $x < $w - 1; $x += $step_x ) {
			$samples[] = self::pixel( $img, $x, 1 );
			$samples[] = self::pixel( $img, $x, $h - 2 );
		}

		for ( $y = 1; $y < $h - 1; $y += $step_y ) {
			$samples[] = self::pixel( $img, 1, $y );
			$samples[] = self::pixel( $img, $w - 2, $y );
		}

		imagedestroy( $img );

		return self::judge( $samples );
	}

	/**
	 * One pixel as [r, g, b, alpha 0–127].
	 *
	 * @param \GdImage|resource $img GD image.
	 * @param int               $x   Column.
	 * @param int               $y   Row.
	 * @return int[]
	 */
	private static function pixel( $img, int $x, int $y ): array {
		$c = imagecolorsforindex( $img, imagecolorat( $img, $x, $y ) );

		return array( (int) $c['red'], (int) $c['green'], (int) $c['blue'], (int) $c['alpha'] );
	}

	/**
	 * From edge samples to a verdict. Pure, so it can be tested.
	 *
	 * Transparent edges are a plain ground of no colour (the surface shows
	 * through). Otherwise the ground is the per-channel median of the
	 * samples, and the picture is plain when nine in ten samples sit
	 * within tolerance of it.
	 *
	 * @param array<int,int[]> $samples [r, g, b, alpha 0–127] each.
	 * @return array{plain:bool,bg:string}
	 */
	public static function judge( array $samples ): array {
		$count = count( $samples );

		if ( $count < 8 ) {
			return array(
				'plain' => false,
				'bg'    => '',
			);
		}

		$clear = 0;

		foreach ( $samples as $s ) {
			if ( ( $s[3] ?? 0 ) >= 64 ) {
				++$clear;
			}
		}

		if ( $clear / $count >= self::PLAIN ) {
			return array(
				'plain' => true,
				'bg'    => '',
			);
		}

		$r = array_column( $samples, 0 );
		$g = array_column( $samples, 1 );
		$b = array_column( $samples, 2 );
		sort( $r );
		sort( $g );
		sort( $b );

		$mid = (int) floor( $count / 2 );
		$med = array( $r[ $mid ], $g[ $mid ], $b[ $mid ] );
		$hit = 0;

		foreach ( $samples as $s ) {
			if ( abs( $s[0] - $med[0] ) <= self::TOLERANCE && abs( $s[1] - $med[1] ) <= self::TOLERANCE && abs( $s[2] - $med[2] ) <= self::TOLERANCE ) {
				++$hit;
			}
		}

		$plain = $hit / $count >= self::PLAIN;

		return array(
			'plain' => $plain,
			'bg'    => $plain ? sprintf( '#%02x%02x%02x', $med[0], $med[1], $med[2] ) : '',
		);
	}

	/*
	 * ------------------------------------------------------------ backfill
	 */

	/**
	 * Ask for a background pass soon.
	 */
	private static function book(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 30, self::CRON );
		}
	}

	/**
	 * Judge a batch of pictures nobody has looked at yet, and book the
	 * next batch while any remain.
	 */
	public static function backfill(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a one-off maintenance sweep over the media library.
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/jpeg','image/png','image/webp')
				 AND ( m.meta_id IS NULL OR m.meta_value NOT LIKE %s )
				 ORDER BY p.ID DESC LIMIT 150",
				self::META,
				'%"v";i:' . self::VERSION . ';%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$began = microtime( true );

		foreach ( $ids as $id ) {
			self::analyse( (int) $id );

			if ( microtime( true ) - $began > 20 ) {
				break;
			}
		}

		if ( count( $ids ) >= 150 || microtime( true ) - $began > 20 ) {
			wp_schedule_single_event( time() + 60, self::CRON );
		}
	}

	/**
	 * A bitmap we can read.
	 *
	 * @param string $mime Mime type.
	 */
	private static function is_raster( string $mime ): bool {
		return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true );
	}
}
