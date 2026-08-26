<?php
/**
 * OC SEO — automatic ALT, unique per image.
 *
 * Every image on the site carries an ALT, derived from what the page
 * already knows, never identical to its neighbour's. Computed at render
 * time (so a renamed product renames its ALTs by itself); persisted only
 * on upload or by the bulk tool.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The picture describes itself.
 */
final class Seo_Alt {

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( Seo::yoast_active() ) {
			return;
		}

		$settings = Seo::settings();

		if ( empty( $settings['alt_on'] ) ) {
			return;
		}

		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'attributes' ), 20, 2 );

		if ( ! empty( $settings['alt_content'] ) ) {
			add_filter( 'the_content', array( $this, 'content' ), 20 );
		}

		if ( ! empty( $settings['alt_upload'] ) ) {
			add_action( 'add_attachment', array( $this, 'on_upload' ) );
		}

		// After a WP All Import row lands, the featured image and the
		// gallery get their ALTs written for real.
		add_action( 'pmxi_saved_post', array( $this, 'after_import' ), 20 );
	}

	/**
	 * The rendered <img>'s alt — filled when empty, replaced in force mode.
	 *
	 * @param array<string,string> $attr       The attributes.
	 * @param \WP_Post             $attachment The image.
	 * @return array<string,string>
	 */
	public function attributes( $attr, $attachment ) {
		$has = isset( $attr['alt'] ) && '' !== trim( (string) $attr['alt'] );

		if ( $has && 'force' !== Seo::settings()['alt_mode'] ) {
			return $attr;
		}

		$parent = in_the_loop() ? (int) get_the_ID() : 0;
		$alt    = Seo::alt_for( (int) $attachment->ID, $parent );

		if ( '' !== $alt ) {
			$attr['alt'] = $alt;
		}

		return $attr;
	}

	/**
	 * Images pasted into the editor without an ALT get one on the way out.
	 *
	 * @param string $content The post content.
	 */
	public function content( $content ) {
		if ( false === stripos( (string) $content, '<img' ) ) {
			return $content;
		}

		$parent = (int) get_the_ID();

		return (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( $m ) use ( $parent ) {
				$tag = (string) $m[0];

				if ( preg_match( '/\balt=["\'][^"\']+["\']/i', $tag ) ) {
					return $tag; // Already spoken for.
				}

				// The attachment id, when the editor left one behind.
				$id = 0;

				if ( preg_match( '/wp-image-(\d+)/', $tag, $mm ) ) {
					$id = (int) $mm[1];
				}

				$alt = Seo::alt_for( $id, $parent );

				if ( '' === $alt ) {
					return $tag;
				}

				$tag = (string) preg_replace( '/\balt=["\']["\']/i', '', $tag );

				return (string) preg_replace( '/<img\b/i', '<img alt="' . esc_attr( $alt ) . '"', $tag, 1 );
			},
			(string) $content
		);
	}

	/**
	 * A fresh upload gets its ALT written into the library there and then.
	 *
	 * @param int $attachment_id The new file.
	 */
	public function on_upload( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		if ( '' !== trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) ) {
			return;
		}

		self::persist( $attachment_id );
	}

	/**
	 * An imported product's featured image and gallery, ALT-completed.
	 *
	 * @param int $post_id The imported post.
	 */
	public function after_import( $post_id ): void {
		$post_id = (int) $post_id;
		$ids     = array( (int) get_post_thumbnail_id( $post_id ) );

		$gallery = (string) get_post_meta( $post_id, '_product_image_gallery', true );

		foreach ( array_filter( array_map( 'absint', explode( ',', $gallery ) ) ) as $id ) {
			$ids[] = $id;
		}

		foreach ( array_filter( array_unique( $ids ) ) as $at => $id ) {
			if ( '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
				self::persist( $id, $post_id, $at + 1 );
			}
		}
	}

	/**
	 * Write the computed ALT into the media library for real.
	 *
	 * @param int $attachment_id The image.
	 * @param int $parent_id     The post it belongs to.
	 * @param int $index         Gallery position.
	 * @return string The ALT written ('' when nothing came out).
	 */
	public static function persist( int $attachment_id, int $parent_id = 0, int $index = 0 ): string {
		$alt = Seo::alt_for( $attachment_id, $parent_id, $index );

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		return $alt;
	}
}
