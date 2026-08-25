<?php
/**
 * Branches: real shops as content — managed in the admin, each with its own
 * page, pulled by the branches block the way stories and reviews are pulled.
 *
 * @package OC_Blocks
 */

declare( strict_types = 1 );

namespace OC\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * A public post type (each branch gets a page of its own), a region taxonomy,
 * the details and accessibility meta, and the single-page layout.
 */
final class Branches {

	/**
	 * The post type.
	 */
	const CPT = 'oc_branch';

	/**
	 * The region taxonomy.
	 */
	const TAX = 'oc_branch_region';

	/**
	 * The accessibility checklist, key => label.
	 *
	 * @return array<string,string>
	 */
	public static function access_items(): array {
		return array(
			'parking'  => __( 'Disabled parking', 'oc-blocks' ),
			'path'     => __( 'Accessible path from the parking to the door', 'oc-blocks' ),
			'door'     => __( 'Accessible entrance door', 'oc-blocks' ),
			'hearing'  => __( 'Hearing assistance device', 'oc-blocks' ),
			'checkout' => __( 'Accessible checkout', 'oc-blocks' ),
			'toilet'   => __( 'Accessible toilets in the branch or mall', 'oc-blocks' ),
			'seat'     => __( 'Accessible seat', 'oc-blocks' ),
			'elevator' => __( 'Elevator', 'oc-blocks' ),
		);
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'types' ) );
		add_action( 'add_meta_boxes_' . self::CPT, array( $this, 'boxes' ) );
		add_action( 'save_post_' . self::CPT, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_filter( 'the_content', array( $this, 'single' ), 9 );
	}

	/**
	 * The post type and its regions.
	 */
	public function types(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'        => array(
					'name'          => __( 'Branches', 'oc-blocks' ),
					'singular_name' => __( 'Branch', 'oc-blocks' ),
					'add_new_item'  => __( 'New branch', 'oc-blocks' ),
					'edit_item'     => __( 'Edit branch', 'oc-blocks' ),
				),
				'public'        => true,
				'has_archive'   => false,
				'menu_position' => 25,
				'menu_icon'     => 'dashicons-store',
				'supports'      => array( 'title', 'editor', 'thumbnail' ),
				'rewrite'       => array(
					'slug'       => 'branch',
					'with_front' => false,
				),
			)
		);

		register_taxonomy(
			self::TAX,
			self::CPT,
			array(
				'labels'            => array(
					'name'          => __( 'Regions', 'oc-blocks' ),
					'singular_name' => __( 'Region', 'oc-blocks' ),
				),
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
			)
		);
	}

	/**
	 * One branch's stored details.
	 *
	 * @param int $branch_id Branch id.
	 * @return array<string,mixed>
	 */
	public static function details( int $branch_id ): array {
		$access = get_post_meta( $branch_id, '_oc_br_access', true );

		return array(
			'address' => (string) get_post_meta( $branch_id, '_oc_br_address', true ),
			'city'    => (string) get_post_meta( $branch_id, '_oc_br_city', true ),
			'phone'   => (string) get_post_meta( $branch_id, '_oc_br_phone', true ),
			'phone2'  => (string) get_post_meta( $branch_id, '_oc_br_phone2', true ),
			'hours'   => (string) get_post_meta( $branch_id, '_oc_br_hours', true ),
			'access'  => is_array( $access ) ? $access : array(),
			'gallery' => array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $branch_id, '_oc_br_gallery', true ) ) ) ),
			'video'   => (string) get_post_meta( $branch_id, '_oc_br_video', true ),
		);
	}

	/**
	 * The edit screen's boxes.
	 */
	public function boxes(): void {
		add_meta_box( 'oc-branch-details', __( 'Branch details', 'oc-blocks' ), array( $this, 'box_details' ), self::CPT, 'normal', 'high' );
		add_meta_box( 'oc-branch-access', __( 'Accessibility', 'oc-blocks' ), array( $this, 'box_access' ), self::CPT, 'normal', 'default' );
		add_meta_box( 'oc-branch-media', __( 'Gallery & video', 'oc-blocks' ), array( $this, 'box_media' ), self::CPT, 'normal', 'default' );
	}

	/**
	 * Address, phones and hours.
	 *
	 * @param \WP_Post $post Branch.
	 */
	public function box_details( \WP_Post $post ): void {
		$d = self::details( $post->ID );
		wp_nonce_field( 'oc_branch_save', 'oc_branch_nonce' );
		?>
		<table class="form-table">
			<tr>
				<th><label for="oc_br_address"><?php esc_html_e( 'Address', 'oc-blocks' ); ?></label></th>
				<td><input type="text" class="regular-text" id="oc_br_address" name="oc_br_address" value="<?php echo esc_attr( $d['address'] ); ?>" placeholder="<?php esc_attr_e( 'Street and number, city', 'oc-blocks' ); ?>"></td>
			</tr>
			<tr>
				<th><label for="oc_br_city"><?php esc_html_e( 'City', 'oc-blocks' ); ?></label></th>
				<td><input type="text" class="regular-text" id="oc_br_city" name="oc_br_city" value="<?php echo esc_attr( $d['city'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="oc_br_phone"><?php esc_html_e( 'Phone', 'oc-blocks' ); ?></label></th>
				<td><input type="text" class="regular-text ltr" id="oc_br_phone" name="oc_br_phone" value="<?php echo esc_attr( $d['phone'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="oc_br_phone2"><?php esc_html_e( 'Another phone (optional)', 'oc-blocks' ); ?></label></th>
				<td><input type="text" class="regular-text ltr" id="oc_br_phone2" name="oc_br_phone2" value="<?php echo esc_attr( $d['phone2'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="oc_br_hours"><?php esc_html_e( 'Opening hours', 'oc-blocks' ); ?></label></th>
				<td><textarea class="large-text" rows="4" id="oc_br_hours" name="oc_br_hours" placeholder="<?php esc_attr_e( 'One line per day or range', 'oc-blocks' ); ?>"><?php echo esc_textarea( $d['hours'] ); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * The yes/no checklist.
	 *
	 * @param \WP_Post $post Branch.
	 */
	public function box_access( \WP_Post $post ): void {
		$d = self::details( $post->ID );

		echo '<p class="description">' . esc_html__( 'Tick what the branch has; anything left unticked shows as "no".', 'oc-blocks' ) . '</p>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:6px 20px">';

		foreach ( self::access_items() as $key => $label ) {
			echo '<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="oc_br_access[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $d['access'][ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}

		echo '</div>';
	}

	/**
	 * Gallery ids and a video address.
	 *
	 * @param \WP_Post $post Branch.
	 */
	public function box_media( \WP_Post $post ): void {
		$d = self::details( $post->ID );
		?>
		<p>
			<button type="button" class="button" id="oc-br-gallery-pick"><?php esc_html_e( 'Choose gallery pictures', 'oc-blocks' ); ?></button>
			<input type="hidden" id="oc_br_gallery" name="oc_br_gallery" value="<?php echo esc_attr( implode( ',', $d['gallery'] ) ); ?>">
		</p>
		<div id="oc-br-gallery-view" style="display:flex;gap:8px;flex-wrap:wrap">
			<?php foreach ( $d['gallery'] as $id ) : ?>
				<?php echo wp_get_attachment_image( $id, 'thumbnail', false, array( 'style' => 'width:80px;height:80px;object-fit:cover;border-radius:6px' ) ); ?>
			<?php endforeach; ?>
		</div>
		<p style="margin-top:14px">
			<label for="oc_br_video"><strong><?php esc_html_e( 'Video (optional, from the media library)', 'oc-blocks' ); ?></strong></label><br>
			<input type="url" class="large-text ltr" id="oc_br_video" name="oc_br_video" value="<?php echo esc_attr( $d['video'] ); ?>">
			<button type="button" class="button" id="oc-br-video-pick"><?php esc_html_e( 'Choose', 'oc-blocks' ); ?></button>
		</p>
		<script>
		jQuery( function ( $ ) {
			$( '#oc-br-gallery-pick' ).on( 'click', function () {
				var frame = wp.media( { multiple: true, library: { type: 'image' } } );
				frame.on( 'open', function () { frame.content.mode( 'browse' ); } );
				frame.on( 'select', function () {
					var picks = frame.state().get( 'selection' ).toJSON();
					$( '#oc_br_gallery' ).val( picks.map( function ( a ) { return a.id; } ).join( ',' ) );
					$( '#oc-br-gallery-view' ).html( picks.map( function ( a ) {
						var u = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
						return '<img src="' + u + '" style="width:80px;height:80px;object-fit:cover;border-radius:6px">';
					} ).join( '' ) );
				} );
				frame.open();
			} );
			$( '#oc-br-video-pick' ).on( 'click', function () {
				var frame = wp.media( { multiple: false, library: { type: 'video' } } );
				frame.on( 'open', function () { frame.content.mode( 'browse' ); } );
				frame.on( 'select', function () {
					$( '#oc_br_video' ).val( frame.state().get( 'selection' ).first().toJSON().url );
				} );
				frame.open();
			} );
		} );
		</script>
		<?php
	}

	/**
	 * The media picker needs wp.media on the branch screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public function admin_assets( string $hook ): void {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && self::CPT === get_current_screen()->post_type ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Keep the details.
	 *
	 * @param int $post_id Branch id.
	 */
	public function save( int $post_id ): void {
		if ( ! isset( $_POST['oc_branch_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['oc_branch_nonce'] ) ), 'oc_branch_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( array( 'address', 'city', 'phone', 'phone2' ) as $key ) {
			update_post_meta( $post_id, '_oc_br_' . $key, sanitize_text_field( wp_unslash( $_POST[ 'oc_br_' . $key ] ?? '' ) ) );
		}

		update_post_meta( $post_id, '_oc_br_hours', sanitize_textarea_field( wp_unslash( $_POST['oc_br_hours'] ?? '' ) ) );
		update_post_meta( $post_id, '_oc_br_video', esc_url_raw( wp_unslash( $_POST['oc_br_video'] ?? '' ) ) );

		$gallery = implode( ',', array_filter( array_map( 'absint', explode( ',', (string) wp_unslash( $_POST['oc_br_gallery'] ?? '' ) ) ) ) );
		update_post_meta( $post_id, '_oc_br_gallery', $gallery );

		$access = array();

		foreach ( array_keys( self::access_items() ) as $key ) {
			$access[ $key ] = empty( $_POST['oc_br_access'][ $key ] ) ? 0 : 1;
		}

		update_post_meta( $post_id, '_oc_br_access', $access );
	}

	/**
	 * The branch's own page: media, details, the accessibility table, a map,
	 * and the other branches underneath.
	 *
	 * @param string $content The branch's description.
	 * @return string
	 */
	public function single( $content ) {
		if ( ! is_singular( self::CPT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$id = (int) get_the_ID();
		$d  = self::details( $id );

		// Media: the featured picture leads, the gallery follows, the video rides along.
		$media = '';
		$thumb = (int) get_post_thumbnail_id( $id );
		$pics  = array_values( array_unique( array_filter( array_merge( array( $thumb ), $d['gallery'] ) ) ) );

		foreach ( $pics as $pic ) {
			$url = (string) wp_get_attachment_image_url( (int) $pic, 'large' );

			if ( '' !== $url ) {
				$media .= '<figure class="ocb-brs__shot"><img src="' . esc_url( $url ) . '" alt="" loading="lazy" decoding="async"></figure>';
			}
		}

		if ( '' !== $d['video'] ) {
			$media .= '<figure class="ocb-brs__shot ocb-brs__shot--vid"><video src="' . esc_url( $d['video'] ) . '" autoplay muted loop playsinline preload="metadata"></video></figure>';
		}

		if ( '' !== $media ) {
			$media = '<div class="ocb-brs__media">' . $media . '</div>';
		}

		// The details card.
		$info = '';

		if ( '' !== $d['address'] ) {
			$info .= '<p class="ocb-brs__row"><strong>' . esc_html__( 'Address', 'oc-blocks' ) . '</strong>' . esc_html( $d['address'] ) . '</p>';
		}

		foreach ( array( $d['phone'], $d['phone2'] ) as $phone ) {
			if ( '' !== $phone ) {
				$info .= '<p class="ocb-brs__row"><strong>' . esc_html__( 'Phone', 'oc-blocks' ) . '</strong><a href="tel:' . esc_attr( (string) preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a></p>';
			}
		}

		if ( '' !== $d['hours'] ) {
			$info .= '<p class="ocb-brs__row"><strong>' . esc_html__( 'Opening hours', 'oc-blocks' ) . '</strong>' . nl2br( esc_html( $d['hours'] ) ) . '</p>';
		}

		$map = '';

		if ( '' !== $d['address'] ) {
			$map = '<div class="ocb-brs__map"><iframe loading="lazy" title="' . esc_attr__( 'Map', 'oc-blocks' ) . '" src="' . esc_url( 'https://maps.google.com/maps?q=' . rawurlencode( $d['address'] ) . '&hl=' . substr( get_locale(), 0, 2 ) . '&output=embed' ) . '"></iframe></div>';
		}

		// Accessibility, the whole checklist — what there is and what there is not.
		$rows = '';

		foreach ( self::access_items() as $key => $label ) {
			$has   = ! empty( $d['access'][ $key ] );
			$rows .= '<div class="ocb-brs__acc' . ( $has ? ' is-yes' : '' ) . '"><i aria-hidden="true">' . ( $has ? '✓' : '✕' ) . '</i><span>' . esc_html( $label ) . '</span></div>';
		}

		$access = '<section class="ocb-brs__section"><h2>' . esc_html__( 'Accessibility', 'oc-blocks' ) . '</h2><div class="ocb-brs__accs">' . $rows . '</div></section>';

		// The other branches, so nobody reaches a dead end.
		$others = get_posts(
			array(
				'post_type'      => self::CPT,
				'posts_per_page' => 6,
				'post__not_in'   => array( $id ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$more = '';

		foreach ( $others as $other ) {
			$od    = self::details( (int) $other->ID );
			$photo = get_the_post_thumbnail( $other, 'medium', array( 'loading' => 'lazy' ) );
			$more .= '<a class="ocb-brs__other" href="' . esc_url( (string) get_permalink( $other ) ) . '">'
				. ( '' === $photo ? '' : '<span class="ocb-brs__other-pic">' . $photo . '</span>' )
				. '<span class="ocb-brs__other-name">' . esc_html( (string) $other->post_title ) . '</span>'
				. ( '' === $od['address'] ? '' : '<span class="ocb-brs__other-addr">' . esc_html( $od['address'] ) . '</span>' )
				. '</a>';
		}

		if ( '' !== $more ) {
			$more = '<section class="ocb-brs__section"><h2>' . esc_html__( 'More branches', 'oc-blocks' ) . '</h2><div class="ocb-brs__others">' . $more . '</div></section>';
		}

		return '<div class="ocb-brs">'
			. $media
			. '<div class="ocb-brs__split">'
			. '<div class="ocb-brs__info">' . ( '' === trim( $content ) ? '' : '<div class="ocb-brs__about">' . $content . '</div>' ) . $info . '</div>'
			. $map
			. '</div>'
			. $access
			. $more
			. '</div>';
	}
}
