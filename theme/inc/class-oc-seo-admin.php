<?php
/**
 * OC SEO — the admin side.
 *
 * One settings screen under Settings (the main menu stays light), the same
 * "SEO and sharing" box under every editor — posts, pages, products, every
 * CPT, and every public taxonomy term — with live previews and the computed
 * automatic value showing as the placeholder. Plus the tools: one-time
 * Yoast migration, the bulk ALT run, and the import-keys table.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The SEO screens.
 */
final class Seo_Admin {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_ocseo_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_ocseo_migrate', array( $this, 'migrate_yoast' ) );
		add_action( 'wp_ajax_ocseo_alt_batch', array( $this, 'alt_batch' ) );

		add_action( 'add_meta_boxes', array( $this, 'boxes' ) );
		add_action( 'save_post', array( $this, 'save_post' ) );

		// Taxonomies register on init — attach their edit screens after.
		add_action( 'init', array( $this, 'attach_term_fields' ), 99 );
		add_action( 'edited_term', array( $this, 'save_term' ), 10, 3 );
	}

	/**
	 * The room's address.
	 */
	public static function url(): string {
		return admin_url( 'options-general.php?page=oc-seo' );
	}

	/**
	 * Every public post type the box belongs on.
	 *
	 * @return string[]
	 */
	private static function post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );

		return array_values( $types );
	}

	/**
	 * Every public taxonomy — categories, tags, product categories, brands.
	 *
	 * @return string[]
	 */
	private static function taxonomies(): array {
		$taxes = get_taxonomies( array( 'public' => true ), 'names' );
		unset( $taxes['post_format'] );

		return array_values( $taxes );
	}

	/**
	 * Every public taxonomy's edit screen gets the box.
	 */
	public function attach_term_fields(): void {
		foreach ( self::taxonomies() as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'term_fields' ), 20 );
		}
	}

	/**
	 * Under Settings, next to the redirects.
	 */
	public function menu(): void {
		add_options_page(
			__( 'SEO', 'oc-theme' ),
			__( 'SEO', 'oc-theme' ),
			'manage_options',
			'oc-seo',
			array( $this, 'render' )
		);
	}

	/*
	 * ------------------------------------------------------ settings screen
	 */

	/**
	 * The screen: tabs for general, types, taxonomies, archives, social,
	 * ALT, and tools.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings = Seo::settings();

		$tabs = array(
			'general'  => __( 'General', 'oc-theme' ),
			'types'    => __( 'Content types', 'oc-theme' ),
			'taxes'    => __( 'Taxonomies', 'oc-theme' ),
			'archives' => __( 'Archives', 'oc-theme' ),
			'social'   => __( 'Social', 'oc-theme' ),
			'alt'      => __( 'Automatic ALT', 'oc-theme' ),
			'tools'    => __( 'Import and tools', 'oc-theme' ),
		);

		echo '<div class="wrap ocseo"><h1>' . esc_html__( 'SEO', 'oc-theme' ) . '</h1>';

		if ( isset( $_GET['ocseo_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['ocseo_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', $key, self::url() ) ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '</nav>';

		if ( 'tools' === $tab ) {
			$this->tools_tab();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ocseo' );
		echo '<input type="hidden" name="action" value="ocseo_settings">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';

		switch ( $tab ) {
			case 'types':
				$this->rows_tab( 'types', self::post_types() );
				break;
			case 'taxes':
				$this->rows_tab( 'taxes', self::taxonomies() );
				break;
			case 'archives':
				$this->archives_tab( $settings );
				break;
			case 'social':
				$this->social_tab( $settings );
				break;
			case 'alt':
				$this->alt_tab( $settings );
				break;
			default:
				$this->general_tab( $settings );
		}

		echo '<p><button class="button button-primary">' . esc_html__( 'Save', 'oc-theme' ) . '</button></p></form>';
		$this->variables_help();
		echo '</div>';
	}

	/**
	 * General: separator, the site templates, the front page.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function general_tab( array $settings ): void {
		?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Separator', 'oc-theme' ); ?></th>
				<td><input type="text" name="sep" class="small-text" value="<?php echo esc_attr( (string) $settings['sep'] ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default title template', 'oc-theme' ); ?></th>
				<td>
					<input type="text" name="title_tpl" class="large-text ltr" value="<?php echo esc_attr( (string) $settings['title_tpl'] ); ?>">
					<p class="description"><?php esc_html_e( 'Every page always has a value — freshly imported content included.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default description template', 'oc-theme' ); ?></th>
				<td>
					<input type="text" name="desc_tpl" class="large-text ltr" value="<?php echo esc_attr( (string) $settings['desc_tpl'] ); ?>">
					<p class="description"><?php esc_html_e( 'Empty means: the excerpt, and failing that the content\'s first 155 characters.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Front page title', 'oc-theme' ); ?></th>
				<td><input type="text" name="home_title" class="large-text" value="<?php echo esc_attr( (string) $settings['home_title'] ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Front page description', 'oc-theme' ); ?></th>
				<td><textarea name="home_desc" rows="2" class="large-text"><?php echo esc_textarea( (string) $settings['home_desc'] ); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * A template row per content type or taxonomy.
	 *
	 * @param string   $kind  'types' or 'taxes'.
	 * @param string[] $names The names.
	 */
	private function rows_tab( string $kind, array $names ): void {
		?>
		<table class="widefat striped" style="margin-top:16px">
			<thead>
				<tr>
					<th></th>
					<th><?php esc_html_e( 'Title template', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Description template', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Noindex by default', 'oc-theme' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $names as $name ) : ?>
					<?php
					$row   = Seo::type_row( $kind, $name );
					$label = 'types' === $kind
						? ( get_post_type_object( $name )->labels->name ?? $name )
						: ( get_taxonomy( $name )->labels->name ?? $name );
					?>
					<tr>
						<th><?php echo esc_html( (string) $label ); ?> <code><?php echo esc_html( $name ); ?></code></th>
						<td><input type="text" class="large-text ltr" name="rows[<?php echo esc_attr( $name ); ?>][title]" placeholder="%%title%% %%sep%% %%sitename%%" value="<?php echo esc_attr( $row['title'] ); ?>"></td>
						<td><input type="text" class="large-text ltr" name="rows[<?php echo esc_attr( $name ); ?>][desc]" value="<?php echo esc_attr( $row['desc'] ); ?>"></td>
						<td><input type="checkbox" name="rows[<?php echo esc_attr( $name ); ?>][noindex]" value="1" <?php checked( 1, $row['noindex'] ); ?>></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Archives: what stays out of the index.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function archives_tab( array $settings ): void {
		$flags = array(
			'search_noindex' => __( 'Search results', 'oc-theme' ),
			'date_noindex'   => __( 'Date archives', 'oc-theme' ),
			'author_noindex' => __( 'Author archives', 'oc-theme' ),
			'paged_noindex'  => __( 'Page 2 and onward', 'oc-theme' ),
		);
		?>
		<table class="form-table">
			<?php foreach ( $flags as $flag => $label ) : ?>
				<tr>
					<th><?php echo esc_html( $label ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $flag ); ?>" value="1" <?php checked( ! empty( $settings[ $flag ] ) ); ?>> <?php esc_html_e( 'Keep out of the index', 'oc-theme' ); ?></label></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Social: the default share image, card type, app id.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function social_tab( array $settings ): void {
		?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Default share image', 'oc-theme' ); ?></th>
				<td>
					<input type="text" name="og_default" class="large-text ltr" value="<?php echo esc_attr( (string) $settings['og_default'] ); ?>" placeholder="https://... <?php esc_attr_e( 'or media id', 'oc-theme' ); ?>">
					<p class="description"><?php esc_html_e( 'Used when a page has no image of its own — a full URL or a media library id.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Twitter card type', 'oc-theme' ); ?></th>
				<td>
					<select name="tw_card">
						<option value="summary_large_image" <?php selected( 'summary_large_image', $settings['tw_card'] ); ?>>summary_large_image</option>
						<option value="summary" <?php selected( 'summary', $settings['tw_card'] ); ?>>summary</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Facebook app id', 'oc-theme' ); ?></th>
				<td><input type="text" name="fb_app_id" class="regular-text ltr" value="<?php echo esc_attr( (string) $settings['fb_app_id'] ); ?>"></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * ALT: the switch, the mode, the templates, the bulk run.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function alt_tab( array $settings ): void {
		?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Automatic ALT', 'oc-theme' ); ?></th>
				<td><label><input type="checkbox" name="alt_on" value="1" <?php checked( ! empty( $settings['alt_on'] ) ); ?>> <?php esc_html_e( 'Every image carries an ALT, unique within its page', 'oc-theme' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Mode', 'oc-theme' ); ?></th>
				<td>
					<label><input type="radio" name="alt_mode" value="fill" <?php checked( 'fill', $settings['alt_mode'] ); ?>> <?php esc_html_e( 'Complete only where ALT is missing', 'oc-theme' ); ?></label><br>
					<label><input type="radio" name="alt_mode" value="force" <?php checked( 'force', $settings['alt_mode'] ); ?>> <?php esc_html_e( 'Override everything', 'oc-theme' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'ALT template — content pages', 'oc-theme' ); ?></th>
				<td><input type="text" name="alt_tpl" class="large-text ltr" value="<?php echo esc_attr( (string) $settings['alt_tpl'] ); ?>" placeholder="%%title%%"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'ALT template — taxonomy pages', 'oc-theme' ); ?></th>
				<td>
					<input type="text" name="alt_tpl_tax" class="large-text ltr" value="<?php echo esc_attr( (string) $settings['alt_tpl_tax'] ); ?>" placeholder="%%term_title%%">
					<p class="description"><?php esc_html_e( 'The regular variables work here, plus %%filename%% and %%index%%.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Content images', 'oc-theme' ); ?></th>
				<td><label><input type="checkbox" name="alt_content" value="1" <?php checked( ! empty( $settings['alt_content'] ) ); ?>> <?php esc_html_e( 'Fix images embedded in the editor without an ALT', 'oc-theme' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'On upload', 'oc-theme' ); ?></th>
				<td><label><input type="checkbox" name="alt_upload" value="1" <?php checked( ! empty( $settings['alt_upload'] ) ); ?>> <?php esc_html_e( 'Write the ALT into the library the moment a file is uploaded', 'oc-theme' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Whole library', 'oc-theme' ); ?></th>
				<td>
					<button type="button" class="button" id="ocseo-alt-run"><?php esc_html_e( 'Complete ALT for the entire media library', 'oc-theme' ); ?></button>
					<span id="ocseo-alt-progress"></span>
					<p class="description"><?php esc_html_e( 'Runs in batches of 50 — for exports and third-party tools that read the library directly.', 'oc-theme' ); ?></p>
				</td>
			</tr>
		</table>
		<script>
		( function () {
			var btn = document.getElementById( 'ocseo-alt-run' );
			var bar = document.getElementById( 'ocseo-alt-progress' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				var done = 0;
				( function step( offset ) {
					var body = new FormData();
					body.append( 'action', 'ocseo_alt_batch' );
					body.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( 'ocseo' ) ); ?> );
					body.append( 'offset', offset );
					fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( r ) {
							if ( ! r || ! r.success ) { bar.textContent = 'ERR'; btn.disabled = false; return; }
							done += r.data.batch;
							bar.textContent = done + ' / ' + r.data.total;
							if ( r.data.more ) { step( offset + 50 ); } else { btn.disabled = false; bar.textContent += ' ✓'; }
						} );
				}( 0 ) );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Tools: the import keys, and the one-time Yoast migration.
	 */
	private function tools_tab(): void {
		$keys = array(
			'_ocseo_title'     => __( 'Meta title', 'oc-theme' ),
			'_ocseo_desc'      => __( 'Meta description', 'oc-theme' ),
			'_ocseo_noindex'   => __( 'Noindex (1/0, yes/no — every spelling accepted)', 'oc-theme' ),
			'_ocseo_nofollow'  => __( 'Nofollow', 'oc-theme' ),
			'_ocseo_canonical' => __( 'Canonical', 'oc-theme' ),
			'_ocseo_og_image'  => __( 'Share image (URL or media id)', 'oc-theme' ),
			'_ocseo_og_title'  => __( 'Share title', 'oc-theme' ),
			'_ocseo_og_desc'   => __( 'Share description', 'oc-theme' ),
			'_ocseo_tw_image'  => __( 'Twitter image', 'oc-theme' ),
			'_ocseo_tw_title'  => __( 'Twitter title', 'oc-theme' ),
			'_ocseo_tw_desc'   => __( 'Twitter description', 'oc-theme' ),
		);
		?>
		<h2><?php esc_html_e( 'WP All Import keys', 'oc-theme' ); ?></h2>
		<p><?php esc_html_e( 'Map these directly in the Custom Fields screen. An empty column never deletes a value — it returns the field to automatic. The image ALT imports to the core key _wp_attachment_image_alt.', 'oc-theme' ); ?></p>
		<table class="widefat striped" style="max-width:720px">
			<tbody>
				<?php foreach ( $keys as $key => $label ) : ?>
					<tr><td class="ltr" style="direction:ltr;text-align:left"><code><?php echo esc_html( $key ); ?></code></td><td><?php echo esc_html( $label ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<hr>
		<h2><?php esc_html_e( 'Migrate from Yoast', 'oc-theme' ); ?></h2>
		<p><?php esc_html_e( 'Copies titles, descriptions, robots and social fields — posts and taxonomies — onto our keys. Nothing is deleted from Yoast; run it again whenever, and going back stays possible.', 'oc-theme' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocseo_migrate' ), 'ocseo' ) ); ?>"><?php esc_html_e( 'Run migration', 'oc-theme' ); ?></a>
		</p>
		<?php
	}

	/**
	 * The variables, spelled out under every tab.
	 */
	private function variables_help(): void {
		?>
		<details style="margin-top:18px;max-width:760px">
			<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'Available variables', 'oc-theme' ); ?></summary>
			<p style="direction:ltr;text-align:left"><code>%%title%% %%sitename%% %%sep%% %%excerpt%% %%category%% %%parent_title%% %%sku%% %%price%% %%brand%% %%term_title%% %%term_desc%% %%cf_xxx%% %%tax_xxx%% %%page%% %%currentyear%%</code></p>
		</details>
		<?php
	}

	/**
	 * Save whichever tab was posted.
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( 'ocseo' );

		$settings = Seo::settings();
		$tab      = sanitize_key( (string) ( $_POST['tab'] ?? 'general' ) );

		if ( 'general' === $tab ) {
			foreach ( array( 'sep', 'title_tpl', 'desc_tpl', 'home_title' ) as $field ) {
				$settings[ $field ] = sanitize_text_field( (string) wp_unslash( $_POST[ $field ] ?? '' ) );
			}

			$settings['home_desc'] = sanitize_textarea_field( (string) wp_unslash( $_POST['home_desc'] ?? '' ) );

			if ( '' === trim( (string) $settings['title_tpl'] ) ) {
				$settings['title_tpl'] = '%%title%% %%sep%% %%sitename%%';
			}
		}

		if ( 'types' === $tab || 'taxes' === $tab ) {
			$rows = array();

			foreach ( (array) ( $_POST['rows'] ?? array() ) as $name => $row ) {
				$rows[ sanitize_key( (string) $name ) ] = array(
					'title'   => sanitize_text_field( (string) wp_unslash( $row['title'] ?? '' ) ),
					'desc'    => sanitize_text_field( (string) wp_unslash( $row['desc'] ?? '' ) ),
					'noindex' => empty( $row['noindex'] ) ? 0 : 1,
				);
			}

			$settings[ $tab ] = $rows;
		}

		if ( 'archives' === $tab ) {
			foreach ( array( 'search_noindex', 'date_noindex', 'author_noindex', 'paged_noindex' ) as $flag ) {
				$settings[ $flag ] = empty( $_POST[ $flag ] ) ? 0 : 1;
			}
		}

		if ( 'social' === $tab ) {
			$settings['og_default'] = sanitize_text_field( (string) wp_unslash( $_POST['og_default'] ?? '' ) );
			$settings['tw_card']    = in_array( $_POST['tw_card'] ?? '', array( 'summary', 'summary_large_image' ), true ) ? (string) $_POST['tw_card'] : 'summary_large_image';
			$settings['fb_app_id']  = sanitize_text_field( (string) wp_unslash( $_POST['fb_app_id'] ?? '' ) );
		}

		if ( 'alt' === $tab ) {
			$settings['alt_on']      = empty( $_POST['alt_on'] ) ? 0 : 1;
			$settings['alt_mode']    = 'force' === ( $_POST['alt_mode'] ?? '' ) ? 'force' : 'fill';
			$settings['alt_tpl']     = sanitize_text_field( (string) wp_unslash( $_POST['alt_tpl'] ?? '' ) );
			$settings['alt_tpl_tax'] = sanitize_text_field( (string) wp_unslash( $_POST['alt_tpl_tax'] ?? '' ) );
			$settings['alt_content'] = empty( $_POST['alt_content'] ) ? 0 : 1;
			$settings['alt_upload']  = empty( $_POST['alt_upload'] ) ? 0 : 1;
		}

		update_option( Seo::SETTINGS, $settings, false );
		wp_safe_redirect( add_query_arg( array( 'tab' => $tab, 'ocseo_msg' => __( 'Saved.', 'oc-theme' ) ), self::url() ) );
		exit;
	}

	/*
	 * ------------------------------------------------------------ meta box
	 */

	/**
	 * The one box, under every editor.
	 */
	public function boxes(): void {
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'ocseo', __( 'SEO and sharing', 'oc-theme' ), array( $this, 'box' ), $type, 'normal', 'default' );
		}
	}

	/**
	 * The box itself: previews, counters, the automatic value as placeholder.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function box( \WP_Post $post ): void {
		wp_nonce_field( 'ocseo_box', 'ocseo_nonce' );

		$auto_title = Seo::auto_title( $post );
		$auto_desc  = Seo::auto_desc( $post );
		$noindex    = Seo::field( $post, '_ocseo_noindex' );
		$indexed    = '1' !== $noindex;
		$url        = (string) get_permalink( $post );
		?>
		<div class="ocseo-box">
			<div class="ocseo-preview">
				<div class="ocseo-g">
					<span class="ocseo-g__url"><?php echo esc_html( $url ); ?></span>
					<span class="ocseo-g__title" id="ocseo-pv-title"><?php echo esc_html( '' !== Seo::field( $post, '_ocseo_title' ) ? Seo::render( Seo::field( $post, '_ocseo_title' ), $post ) : $auto_title ); ?></span>
					<span class="ocseo-g__desc" id="ocseo-pv-desc"><?php echo esc_html( '' !== Seo::field( $post, '_ocseo_desc' ) ? Seo::render( Seo::field( $post, '_ocseo_desc' ), $post ) : $auto_desc ); ?></span>
				</div>
				<div class="ocseo-fb">
					<?php $share = Seo::auto_image( $post ); ?>
					<div class="ocseo-fb__img"<?php echo '' !== $share ? ' style="background-image:url(' . esc_url( $share ) . ')"' : ''; ?>></div>
					<div class="ocseo-fb__body">
						<span class="ocseo-fb__site"><?php echo esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></span>
						<span class="ocseo-fb__title" id="ocseo-pv-ogtitle"><?php echo esc_html( $auto_title ); ?></span>
					</div>
				</div>
			</div>

			<p>
				<label class="ocseo-lbl" for="ocseo-title"><?php esc_html_e( 'Meta title', 'oc-theme' ); ?> <span class="ocseo-count" data-max="60"></span></label>
				<input type="text" id="ocseo-title" name="_ocseo_title" class="large-text" data-preview="ocseo-pv-title" placeholder="<?php echo esc_attr( $auto_title ); ?>" value="<?php echo esc_attr( Seo::field( $post, '_ocseo_title' ) ); ?>">
			</p>
			<p>
				<label class="ocseo-lbl" for="ocseo-desc"><?php esc_html_e( 'Meta description', 'oc-theme' ); ?> <span class="ocseo-count" data-max="155"></span></label>
				<textarea id="ocseo-desc" name="_ocseo_desc" rows="2" class="large-text" data-preview="ocseo-pv-desc" placeholder="<?php echo esc_attr( $auto_desc ); ?>"><?php echo esc_textarea( Seo::field( $post, '_ocseo_desc' ) ); ?></textarea>
			</p>
			<p>
				<label><input type="checkbox" name="ocseo_indexed" value="1" <?php checked( $indexed ); ?>> <?php esc_html_e( 'Show this page in search results', 'oc-theme' ); ?></label>
			</p>

			<details class="ocseo-more">
				<summary><?php esc_html_e( 'Sharing and advanced', 'oc-theme' ); ?></summary>
				<p>
					<label class="ocseo-lbl"><?php esc_html_e( 'Share image (URL or media id)', 'oc-theme' ); ?></label>
					<input type="text" name="_ocseo_og_image" class="large-text ltr" value="<?php echo esc_attr( Seo::field( $post, '_ocseo_og_image' ) ); ?>">
				</p>
				<p>
					<label class="ocseo-lbl"><?php esc_html_e( 'Share title', 'oc-theme' ); ?></label>
					<input type="text" name="_ocseo_og_title" class="large-text" data-preview="ocseo-pv-ogtitle" placeholder="<?php echo esc_attr( $auto_title ); ?>" value="<?php echo esc_attr( Seo::field( $post, '_ocseo_og_title' ) ); ?>">
				</p>
				<p>
					<label class="ocseo-lbl"><?php esc_html_e( 'Share description', 'oc-theme' ); ?></label>
					<input type="text" name="_ocseo_og_desc" class="large-text" placeholder="<?php echo esc_attr( $auto_desc ); ?>" value="<?php echo esc_attr( Seo::field( $post, '_ocseo_og_desc' ) ); ?>">
				</p>
				<p>
					<label class="ocseo-lbl"><?php esc_html_e( 'Canonical', 'oc-theme' ); ?></label>
					<input type="text" name="_ocseo_canonical" class="large-text ltr" placeholder="<?php echo esc_attr( $url ); ?>" value="<?php echo esc_attr( Seo::field( $post, '_ocseo_canonical' ) ); ?>">
				</p>
				<p>
					<label><input type="checkbox" name="_ocseo_nofollow" value="1" <?php checked( '1', Seo::field( $post, '_ocseo_nofollow' ) ); ?>> <?php esc_html_e( 'nofollow — do not follow links from this page', 'oc-theme' ); ?></label>
				</p>
				<p>
					<label class="ocseo-lbl"><?php esc_html_e( 'Twitter: image / title / description (falls back to the share values)', 'oc-theme' ); ?></label>
					<input type="text" name="_ocseo_tw_image" class="large-text ltr" value="<?php echo esc_attr( Seo::field( $post, '_ocseo_tw_image' ) ); ?>">
					<input type="text" name="_ocseo_tw_title" class="large-text" value="<?php echo esc_attr( Seo::field( $post, '_ocseo_tw_title' ) ); ?>">
					<input type="text" name="_ocseo_tw_desc" class="large-text" value="<?php echo esc_attr( Seo::field( $post, '_ocseo_tw_desc' ) ); ?>">
				</p>
			</details>
		</div>
		<style>
			.ocseo-lbl { display:block; font-weight:600; margin-bottom:2px; }
			.ocseo-preview { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:12px; }
			.ocseo-g { max-width:480px; padding:12px 14px; border:1px solid #dcdcde; border-radius:8px; background:#fff; direction:ltr; text-align:left; }
			.ocseo-g__url { display:block; color:#202124; font-size:12px; }
			.ocseo-g__title { display:block; color:#1a0dab; font-size:18px; line-height:1.3; margin:2px 0; }
			.ocseo-g__desc { display:block; color:#4d5156; font-size:13px; }
			.ocseo-fb { width:280px; border:1px solid #dcdcde; border-radius:8px; overflow:hidden; background:#fff; }
			.ocseo-fb__img { height:120px; background:#eee center/cover no-repeat; }
			.ocseo-fb__body { padding:8px 10px; }
			.ocseo-fb__site { color:#65676b; font-size:11px; text-transform:uppercase; }
			.ocseo-fb__title { display:block; font-weight:600; font-size:14px; }
			.ocseo-count { color:#646970; font-weight:400; }
			.ocseo-count.is-over { color:#b32d2e; font-weight:600; }
			.ocseo-more summary { cursor:pointer; font-weight:600; margin:8px 0; }
		</style>
		<script>
		( function () {
			document.querySelectorAll( '.ocseo-box [data-preview]' ).forEach( function ( field ) {
				var target = document.getElementById( field.dataset.preview );
				field.addEventListener( 'input', function () {
					if ( target ) { target.textContent = field.value.trim() || field.placeholder; }
				} );
			} );
			document.querySelectorAll( '.ocseo-box .ocseo-count' ).forEach( function ( counter ) {
				var field = counter.closest( 'p' ).querySelector( 'input, textarea' );
				var max = parseInt( counter.dataset.max, 10 );
				function paint() {
					var len = ( field.value || '' ).length;
					counter.textContent = len + '/' + max;
					counter.classList.toggle( 'is-over', len > max );
				}
				field.addEventListener( 'input', paint );
				paint();
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Save the box. An emptied field returns to automatic — its meta row goes.
	 *
	 * @param int $post_id The post.
	 */
	public function save_post( $post_id ): void {
		if ( ! isset( $_POST['ocseo_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ocseo_nonce'] ) ), 'ocseo_box' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}

		foreach ( Seo::KEYS as $key ) {
			if ( '_ocseo_noindex' === $key ) {
				// The box speaks positive — "show in results" — storage negative.
				if ( empty( $_POST['ocseo_indexed'] ) ) {
					update_post_meta( (int) $post_id, $key, '1' );
				} else {
					delete_post_meta( (int) $post_id, $key );
				}
				continue;
			}

			if ( '_ocseo_nofollow' === $key ) {
				if ( empty( $_POST[ $key ] ) ) {
					delete_post_meta( (int) $post_id, $key );
				} else {
					update_post_meta( (int) $post_id, $key, '1' );
				}
				continue;
			}

			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = sanitize_textarea_field( (string) wp_unslash( $_POST[ $key ] ) );

			if ( '' === trim( $value ) ) {
				delete_post_meta( (int) $post_id, $key );
			} else {
				update_post_meta( (int) $post_id, $key, $value );
			}
		}
	}

	/*
	 * ----------------------------------------------------------- term box
	 */

	/**
	 * The same fields on a term's edit screen.
	 *
	 * @param \WP_Term $term The term being edited.
	 */
	public function term_fields( \WP_Term $term ): void {
		$auto_title = Seo::auto_title( $term );
		$auto_desc  = Seo::auto_desc( $term );
		$indexed    = '1' !== Seo::field( $term, '_ocseo_noindex' );
		?>
		<tr class="form-field">
			<th><h2 style="margin:0"><?php esc_html_e( 'SEO and sharing', 'oc-theme' ); ?></h2></th>
			<td><?php wp_nonce_field( 'ocseo_box', 'ocseo_nonce' ); ?></td>
		</tr>
		<tr class="form-field">
			<th><label for="ocseo-t-title"><?php esc_html_e( 'Meta title', 'oc-theme' ); ?></label></th>
			<td><input type="text" id="ocseo-t-title" name="_ocseo_title" placeholder="<?php echo esc_attr( $auto_title ); ?>" value="<?php echo esc_attr( Seo::field( $term, '_ocseo_title' ) ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th><label for="ocseo-t-desc"><?php esc_html_e( 'Meta description', 'oc-theme' ); ?></label></th>
			<td><textarea id="ocseo-t-desc" name="_ocseo_desc" rows="2" placeholder="<?php echo esc_attr( $auto_desc ); ?>"><?php echo esc_textarea( Seo::field( $term, '_ocseo_desc' ) ); ?></textarea></td>
		</tr>
		<tr class="form-field">
			<th><?php esc_html_e( 'Indexing', 'oc-theme' ); ?></th>
			<td><label><input type="checkbox" name="ocseo_indexed" value="1" <?php checked( $indexed ); ?>> <?php esc_html_e( 'Show this page in search results', 'oc-theme' ); ?></label></td>
		</tr>
		<tr class="form-field">
			<th><label><?php esc_html_e( 'Share image (URL or media id)', 'oc-theme' ); ?></label></th>
			<td><input type="text" name="_ocseo_og_image" class="ltr" value="<?php echo esc_attr( Seo::field( $term, '_ocseo_og_image' ) ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th><label><?php esc_html_e( 'Canonical', 'oc-theme' ); ?></label></th>
			<td><input type="text" name="_ocseo_canonical" class="ltr" value="<?php echo esc_attr( Seo::field( $term, '_ocseo_canonical' ) ); ?>"></td>
		</tr>
		<?php
	}

	/**
	 * Save a term's fields, same emptying rule as posts.
	 *
	 * @param int    $term_id  The term.
	 * @param int    $tt_id    Term taxonomy id (unused).
	 * @param string $taxonomy The taxonomy.
	 */
	public function save_term( $term_id, $tt_id, $taxonomy ): void {
		if ( ! isset( $_POST['ocseo_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ocseo_nonce'] ) ), 'ocseo_box' ) ) {
			return;
		}

		if ( ! in_array( $taxonomy, self::taxonomies(), true ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		if ( empty( $_POST['ocseo_indexed'] ) ) {
			update_term_meta( (int) $term_id, '_ocseo_noindex', '1' );
		} else {
			delete_term_meta( (int) $term_id, '_ocseo_noindex' );
		}

		foreach ( array( '_ocseo_title', '_ocseo_desc', '_ocseo_og_image', '_ocseo_canonical' ) as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = sanitize_textarea_field( (string) wp_unslash( $_POST[ $key ] ) );

			if ( '' === trim( $value ) ) {
				delete_term_meta( (int) $term_id, $key );
			} else {
				update_term_meta( (int) $term_id, $key, $value );
			}
		}
	}

	/*
	 * --------------------------------------------------------------- tools
	 */

	/**
	 * The bulk ALT run, one batch of 50 per call.
	 */
	public function alt_batch(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ocseo', 'nonce', false ) ) {
			wp_send_json_error();
		}

		$offset = absint( $_POST['offset'] ?? 0 );
		$force  = 'force' === Seo::settings()['alt_mode'];

		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => 50,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		foreach ( $query->posts as $id ) {
			$id = (int) $id;

			if ( ! $force && '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
				continue;
			}

			Seo_Alt::persist( $id );
		}

		wp_send_json_success(
			array(
				'batch' => count( $query->posts ),
				'total' => (int) $query->found_posts,
				'more'  => $offset + 50 < (int) $query->found_posts,
			)
		);
	}

	/**
	 * One-time Yoast migration: posts, terms, and the title templates.
	 * Copies only where our key is still empty; deletes nothing of Yoast's.
	 */
	public function migrate_yoast(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( 'ocseo' );

		global $wpdb;

		$map = array(
			'_yoast_wpseo_title'                 => '_ocseo_title',
			'_yoast_wpseo_metadesc'              => '_ocseo_desc',
			'_yoast_wpseo_meta-robots-noindex'   => '_ocseo_noindex',
			'_yoast_wpseo_meta-robots-nofollow'  => '_ocseo_nofollow',
			'_yoast_wpseo_canonical'             => '_ocseo_canonical',
			'_yoast_wpseo_opengraph-image'       => '_ocseo_og_image',
			'_yoast_wpseo_opengraph-title'       => '_ocseo_og_title',
			'_yoast_wpseo_opengraph-description' => '_ocseo_og_desc',
			'_yoast_wpseo_twitter-image'         => '_ocseo_tw_image',
			'_yoast_wpseo_twitter-title'         => '_ocseo_tw_title',
			'_yoast_wpseo_twitter-description'   => '_ocseo_tw_desc',
		);

		$copied = 0;

		foreach ( $map as $theirs => $ours ) {
			$copied += (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				 SELECT y.post_id, %s, y.meta_value FROM {$wpdb->postmeta} y
				 WHERE y.meta_key = %s AND y.meta_value != ''
				   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} o WHERE o.post_id = y.post_id AND o.meta_key = %s )",
				$ours,
				$theirs,
				$ours
			) );
		}

		// Yoast keeps taxonomy fields in one option, not in term meta.
		$tax_meta = get_option( 'wpseo_taxonomy_meta', array() );
		$tax_map  = array(
			'wpseo_title'                => '_ocseo_title',
			'wpseo_desc'                 => '_ocseo_desc',
			'wpseo_noindex'              => '_ocseo_noindex',
			'wpseo_canonical'            => '_ocseo_canonical',
			'wpseo_opengraph-image'      => '_ocseo_og_image',
			'wpseo_opengraph-title'      => '_ocseo_og_title',
			'wpseo_opengraph-description'=> '_ocseo_og_desc',
		);

		foreach ( (array) $tax_meta as $terms ) {
			foreach ( (array) $terms as $term_id => $fields ) {
				foreach ( $tax_map as $theirs => $ours ) {
					$value = trim( (string) ( $fields[ $theirs ] ?? '' ) );

					if ( 'wpseo_noindex' === $theirs ) {
						$value = 'noindex' === $value ? '1' : '';
					}

					if ( '' !== $value && '' === trim( (string) get_term_meta( (int) $term_id, $ours, true ) ) ) {
						update_term_meta( (int) $term_id, $ours, $value );
						$copied++;
					}
				}
			}
		}

		// The templates: Yoast's separator and per-type patterns, same syntax.
		$titles = get_option( 'wpseo_titles', array() );

		if ( is_array( $titles ) && ! empty( $titles ) ) {
			$settings = Seo::settings();

			$seps = array( 'sc-dash' => '-', 'sc-ndash' => '–', 'sc-mdash' => '—', 'sc-pipe' => '|', 'sc-bull' => '•', 'sc-middot' => '·' );

			if ( ! empty( $titles['separator'] ) && isset( $seps[ $titles['separator'] ] ) ) {
				$settings['sep'] = $seps[ $titles['separator'] ];
			}

			foreach ( self::post_types() as $type ) {
				$row = Seo::type_row( 'types', $type );

				if ( '' === $row['title'] && ! empty( $titles[ 'title-' . $type ] ) ) {
					$row['title'] = self::convert_vars( (string) $titles[ 'title-' . $type ] );
				}

				if ( '' === $row['desc'] && ! empty( $titles[ 'metadesc-' . $type ] ) ) {
					$row['desc'] = self::convert_vars( (string) $titles[ 'metadesc-' . $type ] );
				}

				$settings['types'][ $type ] = $row;
			}

			foreach ( self::taxonomies() as $taxonomy ) {
				$row = Seo::type_row( 'taxes', $taxonomy );

				if ( '' === $row['title'] && ! empty( $titles[ 'title-tax-' . $taxonomy ] ) ) {
					$row['title'] = self::convert_vars( (string) $titles[ 'title-tax-' . $taxonomy ] );
				}

				if ( '' === $row['desc'] && ! empty( $titles[ 'metadesc-tax-' . $taxonomy ] ) ) {
					$row['desc'] = self::convert_vars( (string) $titles[ 'metadesc-tax-' . $taxonomy ] );
				}

				$settings['taxes'][ $taxonomy ] = $row;
			}

			update_option( Seo::SETTINGS, $settings, false );
		}

		wp_safe_redirect( add_query_arg(
			array(
				'tab'       => 'tools',
				/* translators: %s: how many values. */
				'ocseo_msg' => sprintf( __( 'Migration done — %s values copied.', 'oc-theme' ), number_format_i18n( $copied ) ),
			),
			self::url()
		) );
		exit;
	}

	/**
	 * Yoast's template variables into ours — mostly a straight rename.
	 *
	 * @param string $template A Yoast template.
	 */
	private static function convert_vars( string $template ): string {
		return str_replace(
			array( '%%sitedesc%%', '%%primary_category%%', '%%pt_single%%', '%%pt_plural%%', '%%term_description%%' ),
			array( '', '%%category%%', '', '', '%%term_desc%%' ),
			$template
		);
	}
}
