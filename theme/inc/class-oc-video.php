<?php
/**
 * Product video: an uploaded file, YouTube or Vimeo — muted loops on the
 * catalogue card and the product page, a full-screen overlay on click.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Product video source, placement and rendering.
 */
final class Video {

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_product_data_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );

		add_action( 'wp_footer', array( $this, 'product_json' ) );
	}

	/**
	 * The stored video config for a product.
	 *
	 * @param int $product_id Product id.
	 * @return array{url:string,catalog:bool,placement:string,position:int,autoplay:bool}|null
	 */
	public static function meta( int $product_id ): ?array {
		$raw = get_post_meta( $product_id, '_oc_video', true );

		if ( ! is_array( $raw ) || empty( $raw['url'] ) ) {
			return null;
		}

		return array(
			'url'       => (string) $raw['url'],
			'catalog'   => ! empty( $raw['catalog'] ),
			'placement' => in_array( $raw['placement'] ?? '', array( 'gallery', 'float-end', 'float-start' ), true ) ? (string) $raw['placement'] : 'gallery',
			'position'  => max( 1, (int) ( $raw['position'] ?? 1 ) ),
			// Pre-existing configs never saved the key — they keep autoplaying.
			'autoplay'  => ! array_key_exists( 'autoplay', $raw ) || ! empty( $raw['autoplay'] ),
		);
	}

	/**
	 * Split a video URL into a playable source description.
	 *
	 * @param string $url  Video URL.
	 * @param bool   $loop Muted-loop context (true) or full overlay (false).
	 * @return array{kind:string,src:string,thumb:string}
	 */
	public static function source( string $url, bool $loop ): array {
		if ( preg_match( '~(?:youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([\w-]{6,20})~i', $url, $m ) ) {
			$base = 'https://www.youtube-nocookie.com/embed/' . $m[1]
				. '?playsinline=1&rel=0&modestbranding=1&iv_load_policy=3&disablekb=1&controls=0&loop=1&playlist=' . $m[1]
				. '&autoplay=1&mute=' . ( $loop ? '1' : '0' );

			return array(
				'kind'  => 'embed',
				'src'   => $base,
				'thumb' => 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg',
			);
		}

		if ( preg_match( '~vimeo\.com/(?:video/)?(\d+)~i', $url, $m ) ) {
			$src = 'https://player.vimeo.com/video/' . $m[1] . (
				$loop
					? '?background=1&autoplay=1&muted=1&loop=1'
					: '?autoplay=1&muted=0&loop=1&controls=0&title=0&byline=0&portrait=0'
			);

			return array(
				'kind'  => 'embed',
				'src'   => $src,
				'thumb' => '',
			);
		}

		return array(
			'kind'  => 'file',
			'src'   => $url,
			'thumb' => '',
		);
	}

	/**
	 * The muted-loop markup for a video, used by the catalogue card and the
	 * product-page loops. No controls anywhere; sound never plays here.
	 *
	 * @param string $url   Video URL.
	 * @param string $class Element class.
	 * @param bool   $lazy  Defer loading until the element nears the viewport
	 *                      (catalogue cards) — the front script wires it up.
	 * @return string
	 */
	public static function loop_html( string $url, string $class, bool $lazy = false ): string {
		$source = self::source( $url, true );

		if ( 'file' === $source['kind'] ) {
			if ( $lazy ) {
				return sprintf(
					'<video class="%s" data-oc-vsrc="%s" muted loop playsinline preload="none"></video>',
					esc_attr( $class ),
					esc_url( $source['src'] )
				);
			}

			return sprintf(
				'<video class="%s" src="%s" autoplay muted loop playsinline preload="metadata"></video>',
				esc_attr( $class ),
				esc_url( $source['src'] )
			);
		}

		if ( $lazy ) {
			return sprintf(
				'<iframe class="%s" data-oc-vsrc="%s" src="about:blank" loading="lazy" allow="autoplay; fullscreen" tabindex="-1" title="%s"></iframe>',
				esc_attr( $class ),
				esc_url( $source['src'] ),
				esc_attr__( 'Product video', 'oc-theme' )
			);
		}

		return sprintf(
			'<iframe class="%s" src="%s" loading="lazy" allow="autoplay; fullscreen" tabindex="-1" title="%s"></iframe>',
			esc_attr( $class ),
			esc_url( $source['src'] ),
			esc_attr__( 'Product video', 'oc-theme' )
		);
	}

	/**
	 * "Video" tab in the Product Data box.
	 *
	 * @param array $tabs Product data tabs.
	 * @return array
	 */
	public function tab( array $tabs ): array {
		$tabs['oc_video'] = array(
			'label'    => __( 'Video', 'oc-theme' ),
			'target'   => 'oc_video_panel',
			'class'    => array(),
			'priority' => 66,
		);

		return $tabs;
	}

	/**
	 * The panel: source, catalogue toggle, product-page placement.
	 */
	public function panel(): void {
		global $post;

		$meta = self::meta( $post->ID ) ?? array(
			'url'       => '',
			'catalog'   => false,
			'placement' => 'gallery',
			'position'  => 1,
			'autoplay'  => true,
		);

		wp_enqueue_media();
		?>
		<div id="oc_video_panel" class="panel woocommerce_options_panel">
			<div style="padding:12px;">
				<span style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
					<label style="float:none;inline-size:auto;margin:0;"><?php esc_html_e( 'Video', 'oc-theme' ); ?></label>
					<input type="url" name="oc_video_url" id="oc_video_url" value="<?php echo esc_url( $meta['url'] ); ?>" placeholder="YouTube / Vimeo / mp4" style="inline-size:340px;" />
					<button type="button" class="button" id="oc_video_pick"><?php esc_html_e( 'Choose from library', 'oc-theme' ); ?></button>
				</span>
				<p class="description" style="margin-block-start:6px;"><?php esc_html_e( 'A YouTube or Vimeo link, or a video file from the media library. Embeds play without controls.', 'oc-theme' ); ?></p>

				<p class="form-field" style="padding:0;margin-block-start:14px;">
					<label style="float:none;inline-size:auto;margin:0;display:inline;">
						<input type="checkbox" name="oc_video_catalog" value="1" <?php checked( $meta['catalog'] ); ?> />
						<?php esc_html_e( 'Show the video on the catalogue card (primary, always muted)', 'oc-theme' ); ?>
					</label>
				</p>

				<p class="form-field" style="padding:0;margin-block-start:8px;">
					<label style="float:none;inline-size:auto;margin:0;display:inline;">
						<input type="checkbox" name="oc_video_autoplay" value="1" <?php checked( $meta['autoplay'] ); ?> />
						<?php esc_html_e( 'Autoplay on the product page — no play button, hover shows the zoom plus. Off: a frozen frame with a play button; a click plays it in place.', 'oc-theme' ); ?>
					</label>
				</p>

				<span style="display:flex;align-items:center;gap:8px;margin-block-start:10px;">
					<label style="float:none;inline-size:auto;margin:0;"><?php esc_html_e( 'Product page placement', 'oc-theme' ); ?></label>
					<select name="oc_video_placement" id="oc_video_placement">
						<option value="gallery" <?php selected( $meta['placement'], 'gallery' ); ?>><?php esc_html_e( 'Inside the gallery', 'oc-theme' ); ?></option>
						<option value="float-end" <?php selected( $meta['placement'], 'float-end' ); ?>><?php esc_html_e( 'Floating — bottom right', 'oc-theme' ); ?></option>
						<option value="float-start" <?php selected( $meta['placement'], 'float-start' ); ?>><?php esc_html_e( 'Floating — bottom left', 'oc-theme' ); ?></option>
					</select>
					<span id="oc_video_pos_wrap" style="<?php echo 'gallery' === $meta['placement'] ? '' : 'display:none;'; ?>">
						<label style="float:none;inline-size:auto;margin:0;"><?php esc_html_e( 'Position in the gallery', 'oc-theme' ); ?></label>
						<input type="number" name="oc_video_position" value="<?php echo absint( $meta['position'] ); ?>" min="1" max="20" style="inline-size:70px;" />
					</span>
				</span>
				<p class="description" style="margin-block-start:6px;"><?php esc_html_e( 'The floating video loops quietly over the main image; a click opens it full screen.', 'oc-theme' ); ?></p>
			</div>
			<script>
			document.addEventListener( 'click', function ( event ) {
				if ( ! event.target.closest || ! event.target.closest( '#oc_video_pick' ) || ! window.wp || ! wp.media ) {
					return;
				}
				var frame = wp.media( { multiple: false, library: { type: 'video' } } );
				frame.on( 'select', function () {
					document.getElementById( 'oc_video_url' ).value = frame.state().get( 'selection' ).first().toJSON().url;
				} );
				frame.open();
			} );
			document.addEventListener( 'change', function ( event ) {
				if ( event.target && 'oc_video_placement' === event.target.id ) {
					document.getElementById( 'oc_video_pos_wrap' ).style.display = 'gallery' === event.target.value ? '' : 'none';
				}
			} );
			</script>
		</div>
		<?php
	}

	/**
	 * Persist the video config.
	 *
	 * @param int $post_id Product id.
	 */
	public function save( $post_id ): void {
		// Woo verified its own nonce before this hook fires.
		if ( ! isset( $_POST['oc_video_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$url = esc_url_raw( wp_unslash( (string) $_POST['oc_video_url'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $url ) {
			delete_post_meta( $post_id, '_oc_video' );
			return;
		}

		update_post_meta(
			$post_id,
			'_oc_video',
			array(
				'url'       => $url,
				'catalog'   => isset( $_POST['oc_video_catalog'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'placement' => in_array( $_POST['oc_video_placement'] ?? '', array( 'gallery', 'float-end', 'float-start' ), true ) ? sanitize_text_field( wp_unslash( (string) $_POST['oc_video_placement'] ) ) : 'gallery', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'position'  => max( 1, absint( $_POST['oc_video_position'] ?? 1 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'autoplay'  => isset( $_POST['oc_video_autoplay'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);
	}

	/**
	 * The product-page video config, printed as JSON for the front script.
	 */
	public function product_json(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );

		if ( ! $product ) {
			return;
		}

		$meta = self::meta( $product->get_id() );

		if ( null === $meta ) {
			return;
		}

		$loop = self::source( $meta['url'], true );
		$full = self::source( $meta['url'], false );

		printf(
			'<script type="application/json" id="oc-video">%s</script>',
			wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in a non-executed script tag.
				array(
					'placement' => $meta['placement'],
					'position'  => $meta['position'],
					'autoplay'  => $meta['autoplay'],
					'kind'      => $loop['kind'],
					'loopSrc'   => $loop['src'],
					'fullSrc'   => $full['src'],
					'thumb'     => $loop['thumb'],
				)
			)
		);
	}
}
