<?php
/**
 * SVG in the media library, cleaned on the way in.
 *
 * A logo is a drawing, and a drawing belongs in a vector file: an SVG logo
 * is a few kilobytes and stays sharp on every screen, where a PNG big
 * enough for a high-density display is hundreds. WordPress refuses SVG for
 * a good reason -- it is a text file that can carry script, and a script
 * served from the site's own address runs with the site's own trust.
 *
 * So the file is allowed, and the script is taken out of it before it is
 * ever stored. Only for people who could already run code on the site by
 * other means, which is what unfiltered_html marks.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an SVG in, minus anything that could act.
 */
final class SVG_Upload {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_filter( 'upload_mimes', array( $this, 'allow' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'name_it' ), 10, 4 );
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'clean' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'measure' ), 10, 2 );
		add_action( 'admin_head', array( $this, 'show_in_list' ) );
	}

	/**
	 * Who may.
	 */
	private function may(): bool {
		return current_user_can( 'unfiltered_html' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Add the type, for those people only.
	 *
	 * @param array<string,string> $mimes Allowed types.
	 * @return array<string,string>
	 */
	public function allow( $mimes ) {
		if ( ! is_array( $mimes ) || ! $this->may() ) {
			return $mimes;
		}

		$mimes['svg'] = 'image/svg+xml';

		return $mimes;
	}

	/**
	 * WordPress sniffs the bytes of an upload and an SVG looks like plain
	 * text, so without this it is refused as "not matching its extension"
	 * however the type is allowed above.
	 *
	 * @param array<string,mixed>       $check    What WordPress decided.
	 * @param string                    $file     Path to the file.
	 * @param string                    $filename Its name.
	 * @param array<string,string>|null $mimes    Allowed types.
	 * @return array<string,mixed>
	 */
	public function name_it( $check, $file, $filename, $mimes ) {
		unset( $file, $mimes );

		if ( ! is_array( $check ) || ! $this->may() ) {
			return $check;
		}

		if ( ! empty( $check['ext'] ) && ! empty( $check['type'] ) ) {
			return $check;
		}

		if ( 'svg' === strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) ) ) {
			$check['ext']  = 'svg';
			$check['type'] = 'image/svg+xml';
		}

		return $check;
	}

	/**
	 * Take out everything that can act, before the file is stored.
	 *
	 * The same three things the questionnaire's own upload route takes out:
	 * a script element, an inline handler, and a foreignObject, which is a
	 * window back into HTML inside the drawing.
	 *
	 * @param array<string,mixed> $file The upload.
	 * @return array<string,mixed>
	 */
	public function clean( $file ) {
		if ( ! is_array( $file ) || 'image/svg+xml' !== ( $file['type'] ?? '' ) || empty( $file['tmp_name'] ) ) {
			return $file;
		}

		if ( ! $this->may() ) {
			$file['error'] = __( 'Only an administrator may upload an SVG.', 'oc-theme' );

			return $file;
		}

		$svg = (string) file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the upload's own temp file.

		if ( '' === trim( $svg ) || false === stripos( $svg, '<svg' ) ) {
			$file['error'] = __( 'That does not look like an SVG.', 'oc-theme' );

			return $file;
		}

		$svg = (string) preg_replace( '~<script\b[^>]*>.*?</script>~is', '', $svg );
		$svg = (string) preg_replace( '~<script\b[^>]*/>~is', '', $svg );
		$svg = (string) preg_replace( '~\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')~i', '', $svg );
		$svg = (string) preg_replace( '~<foreignObject\b[^>]*>.*?</foreignObject>~is', '', $svg );

		// An external entity is how a text file reads other files off the
		// server, and a drawing has no business declaring one.
		$svg = (string) preg_replace( '~<!DOCTYPE[^>]*\[.*?\]>~is', '', $svg );
		$svg = (string) preg_replace( '~<!ENTITY[^>]*>~is', '', $svg );

		// javascript: in a link or a style, however it is spelled.
		$svg = (string) preg_replace( '~(href|xlink:href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2~i', '', $svg );

		file_put_contents( (string) $file['tmp_name'], $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the same temp file.

		return $file;
	}

	/**
	 * Give it a size.
	 *
	 * WordPress measures a raster file itself and cannot measure this one,
	 * so without this the media library shows no dimensions and a theme has
	 * no shape to reserve while the picture arrives.
	 *
	 * @param array<string,mixed> $meta What WordPress worked out.
	 * @param int                 $id   Attachment id.
	 * @return array<string,mixed>
	 */
	public function measure( $meta, $id ) {
		if ( ! is_array( $meta ) || 'image/svg+xml' !== get_post_mime_type( (int) $id ) ) {
			return $meta;
		}

		$path = get_attached_file( (int) $id );

		if ( ! $path || ! file_exists( $path ) ) {
			return $meta;
		}

		$size = $this->size( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own stored file.

		if ( ! $size ) {
			return $meta;
		}

		$meta['width']  = $size[0];
		$meta['height'] = $size[1];
		$meta['file']   = _wp_relative_upload_path( $path );
		$meta['sizes']  = array();

		return $meta;
	}

	/**
	 * The drawing's own idea of how big it is: its width and height when it
	 * states them, otherwise the shape of its viewBox.
	 *
	 * @param string $svg The file.
	 * @return array{0:int,1:int}|null
	 */
	private function size( string $svg ): ?array {
		if ( preg_match( '~<svg\b[^>]*\bwidth\s*=\s*["\']([\d.]+)~i', $svg, $w ) && preg_match( '~<svg\b[^>]*\bheight\s*=\s*["\']([\d.]+)~i', $svg, $h ) ) {
			return array( (int) round( (float) $w[1] ), (int) round( (float) $h[1] ) );
		}

		if ( preg_match( '~viewBox\s*=\s*["\']\s*[\d.-]+[ ,]+[\d.-]+[ ,]+([\d.]+)[ ,]+([\d.]+)~i', $svg, $v ) ) {
			return array( (int) round( (float) $v[1] ), (int) round( (float) $v[2] ) );
		}

		return null;
	}

	/**
	 * The media library lays its grid out with CSS meant for a raster
	 * thumbnail, and an SVG with no intrinsic size collapses to nothing.
	 */
	public function show_in_list(): void {
		if ( ! $this->may() ) {
			return;
		}

		echo '<style>.media-icon img[src$=".svg"],.attachment-266x266 img[src$=".svg"],td.media-icon img[src$=".svg"]{inline-size:100%;block-size:auto}</style>';
	}
}
