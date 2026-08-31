<?php
/**
 * OC Redirects — the admin room.
 *
 * One table like the products table, three ways to create a redirect, a 404
 * journal with a one-click fix, batches that can be undone whole, and the
 * mapper's approval screen. Administrators only; every row remembers who
 * made it and when.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The redirects screens.
 */
final class Redirects_Admin {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );

		add_action( 'admin_post_ocrd_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ocrd_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_ocrd_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_ocrd_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_ocrd_confirm_import', array( $this, 'handle_confirm_import' ) );
		add_action( 'admin_post_ocrd_confirm_map', array( $this, 'handle_confirm_map' ) );
		add_action( 'admin_post_ocrd_undo_batch', array( $this, 'handle_undo_batch' ) );
		add_action( 'admin_post_ocrd_settings', array( $this, 'handle_settings' ) );
		add_action( 'admin_post_ocrd_dictionary', array( $this, 'handle_dictionary' ) );
		add_action( 'admin_post_ocrd_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_ocrd_import_plugin', array( $this, 'handle_import_plugin' ) );

		add_action( 'wp_ajax_ocrd_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_ocrd_find', array( $this, 'ajax_find' ) );
	}

	/**
	 * The room's address.
	 *
	 * @param array<string,string|int> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'options-general.php?page=oc-redirects' ) );
	}

	/**
	 * Under Settings — the main menu stays light.
	 */
	public function menu(): void {
		add_options_page(
			__( '301 redirects', 'oc-theme' ),
			__( '301 redirects', 'oc-theme' ),
			'manage_options',
			'oc-redirects',
			array( $this, 'render' )
		);
	}

	/*
	 * ------------------------------------------------------------ rendering
	 */

	/**
	 * The whole room: tabs and their screens.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap ocrd">';
		echo '<h1>' . esc_html__( '301 redirects', 'oc-theme' ) . '</h1>';

		if ( isset( $_GET['ocrd_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['ocrd_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$tabs = array(
			'list'     => __( 'Redirects', 'oc-theme' ),
			'import'   => __( 'Import', 'oc-theme' ),
			'map'      => __( 'Auto mapping', 'oc-theme' ),
			'log'      => __( '404 journal', 'oc-theme' ),
			'batches'  => __( 'Batches', 'oc-theme' ),
			'settings' => __( 'Settings', 'oc-theme' ),
		);

		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '</nav>';

		switch ( $tab ) {
			case 'import':
				$this->screen_import();
				break;
			case 'map':
				$this->screen_map();
				break;
			case 'log':
				$this->screen_log();
				break;
			case 'batches':
				$this->screen_batches();
				break;
			case 'settings':
				$this->screen_settings();
				break;
			default:
				$this->screen_list();
		}

		$this->footer_assets();
		echo '</div>';
	}

	/**
	 * The main table, and the little form above it.
	 */
	private function screen_list(): void {
		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$origin = isset( $_GET['origin'] ) ? sanitize_key( wp_unslash( $_GET['origin'] ) ) : '';
		$batch  = isset( $_GET['batch'] ) ? absint( $_GET['batch'] ) : 0;
		$order  = isset( $_GET['orderby'] ) && 'hits' === $_GET['orderby'] ? 'hits DESC' : 'id DESC';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$edit   = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$from   = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		// phpcs:enable

		$where = array( '1=1' );
		$prep  = array();

		if ( '' !== $search ) {
			$where[] = '(source LIKE %s OR target LIKE %s OR note LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			array_push( $prep, $like, $like, $like );
		}

		if ( in_array( $origin, array( 'manual', 'import', 'auto' ), true ) ) {
			$where[] = 'origin = %s';
			$prep[]  = $origin;
		}

		if ( $batch > 0 ) {
			$where[] = 'batch_id = %d';
			$prep[]  = $batch;
		}

		$per   = 50;
		$sql   = 'FROM ' . Redirects::table() . ' WHERE ' . implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $prep ? $wpdb->prepare( "SELECT COUNT(*) $sql", $prep ) : "SELECT COUNT(*) $sql" ); // phpcs:ignore WordPress.DB
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * $sql ORDER BY $order LIMIT %d OFFSET %d", array_merge( $prep, array( $per, ( $paged - 1 ) * $per ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$editing = $edit > 0 ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Redirects::table() . ' WHERE id = %d', $edit ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB

		// The short form: from, to, type, note.
		?>
		<form class="ocrd-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ocrd' ); ?>
			<input type="hidden" name="action" value="ocrd_save">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) ( $editing['id'] ?? 0 ) ); ?>">
			<div class="ocrd-form__row">
				<label><span><?php esc_html_e( 'From address', 'oc-theme' ); ?></span>
					<input type="text" name="source" required class="regular-text ltr" placeholder="/old-page/" value="<?php echo esc_attr( (string) ( $editing['source'] ?? $from ) ); ?>">
				</label>
				<label><span><?php esc_html_e( 'To address', 'oc-theme' ); ?></span>
					<input type="text" name="target" required class="regular-text ltr" list="ocrd-found" autocomplete="off" id="ocrd-target" placeholder="/new-page/" value="<?php echo esc_attr( (string) ( $editing['target'] ?? '' ) ); ?>">
					<datalist id="ocrd-found"></datalist>
				</label>
				<label><span><?php esc_html_e( 'Type', 'oc-theme' ); ?></span>
					<select name="type">
						<?php
						foreach ( array(
							301 => __( '301 permanent', 'oc-theme' ),
							302 => __( '302 temporary', 'oc-theme' ),
							410 => __( '410 gone', 'oc-theme' ),
						) as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (int) ( $editing['type'] ?? 301 ), $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Note', 'oc-theme' ); ?></span>
					<input type="text" name="note" class="regular-text" value="<?php echo esc_attr( (string) ( $editing['note'] ?? '' ) ); ?>">
				</label>
				<button class="button button-primary"><?php echo esc_html( $editing ? __( 'Update', 'oc-theme' ) : __( 'Add redirect', 'oc-theme' ) ); ?></button>
			</div>
			<p class="description"><?php esc_html_e( 'A full URL or a relative path; a trailing * makes a prefix rule. The target field searches the site as you type.', 'oc-theme' ); ?></p>
		</form>

		<form method="get" class="ocrd-filters">
			<input type="hidden" name="page" value="oc-redirects">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'oc-theme' ); ?>">
			<select name="origin">
				<option value=""><?php esc_html_e( 'Every origin', 'oc-theme' ); ?></option>
				<?php
				foreach ( array(
					'manual' => __( 'Manual', 'oc-theme' ),
					'import' => __( 'Imported', 'oc-theme' ),
					'auto'   => __( 'Automatic', 'oc-theme' ),
				) as $key => $label ) :
					?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $origin, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="orderby">
				<option value=""><?php esc_html_e( 'Newest first', 'oc-theme' ); ?></option>
				<option value="hits" <?php selected( 'hits DESC', $order ); ?>><?php esc_html_e( 'Most hits first', 'oc-theme' ); ?></option>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'oc-theme' ); ?></button>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocrd_export' ), 'ocrd' ) ); ?>"><?php esc_html_e( 'Export CSV', 'oc-theme' ); ?></a>
			<span class="ocrd-count">
				<?php
				/* translators: %s: how many rules. */
				echo esc_html( sprintf( __( '%s rules', 'oc-theme' ), number_format_i18n( $total ) ) );
				?>
			</span>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ocrd' ); ?>
			<input type="hidden" name="action" value="ocrd_bulk">
			<table class="widefat striped ocrd-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.ocrd-cb').forEach(c=>c.checked=this.checked)"></td>
						<th><?php esc_html_e( 'From address', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'To address', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Type', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Origin', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Last used', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'On', 'oc-theme' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'No rules yet — the form above makes the first one.', 'oc-theme' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( (array) $rows as $row ) : ?>
						<tr>
							<th class="check-column"><input class="ocrd-cb" type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>"></th>
							<td class="ltr"><code><?php echo esc_html( (string) $row['source'] ); ?></code></td>
							<td class="ltr"><code><?php echo esc_html( (string) $row['target'] ); ?></code></td>
							<td><?php echo esc_html( (string) $row['type'] ); ?></td>
							<td>
								<?php
								$origins = array(
									'manual' => __( 'Manual', 'oc-theme' ),
									'import' => __( 'Imported', 'oc-theme' ),
									'auto'   => __( 'Automatic', 'oc-theme' ),
								);
								echo esc_html( $origins[ $row['origin'] ] ?? (string) $row['origin'] );

								if ( (int) $row['batch_id'] > 0 ) {
									echo ' <a href="' . esc_url( self::url( array( 'batch' => (int) $row['batch_id'] ) ) ) . '">#' . esc_html( (string) $row['batch_id'] ) . '</a>';
								}
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
							<td><?php echo esc_html( $row['last_hit'] ? (string) mysql2date( (string) get_option( 'date_format' ), (string) $row['last_hit'] ) : __( 'Never', 'oc-theme' ) ); ?></td>
							<td>
								<input type="checkbox" class="ocrd-toggle" data-id="<?php echo esc_attr( (string) $row['id'] ); ?>" <?php checked( (int) $row['active'], 1 ); ?>>
							</td>
							<td>
								<a href="<?php echo esc_url( self::url( array( 'edit' => (int) $row['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'oc-theme' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<select name="doing">
					<option value="delete"><?php esc_html_e( 'Delete selected', 'oc-theme' ); ?></option>
					<option value="off"><?php esc_html_e( 'Turn selected off', 'oc-theme' ); ?></option>
					<option value="on"><?php esc_html_e( 'Turn selected on', 'oc-theme' ); ?></option>
				</select>
				<button class="button"><?php esc_html_e( 'Apply', 'oc-theme' ); ?></button>
			</p>
		</form>
		<?php
		$pages = (int) ceil( $total / $per );

		if ( $pages > 1 ) {
			echo '<p class="ocrd-pages">';

			for ( $i = 1; $i <= $pages; $i++ ) {
				$link = self::url(
					array_filter(
						array(
							's'      => $search,
							'origin' => $origin,
							'batch'  => $batch,
							'paged'  => $i,
						)
					)
				);
				echo $i === $paged ? '<strong>' . esc_html( (string) $i ) . '</strong> ' : '<a href="' . esc_url( $link ) . '">' . esc_html( (string) $i ) . '</a> ';
			}

			echo '</p>';
		}
	}

	/**
	 * Mode B: the two-column file, previewed before a single row is written.
	 */
	private function screen_import(): void {
		$token   = isset( $_GET['preview'] ) ? sanitize_key( wp_unslash( $_GET['preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preview = '' !== $token ? get_transient( 'ocrd_preview_' . $token ) : null;

		if ( is_array( $preview ) ) {
			$this->preview_table( $token, $preview );
			return;
		}
		?>
		<h2><?php esc_html_e( 'Two-column file', 'oc-theme' ); ?></h2>
		<p><?php esc_html_e( 'Column one is the old address, column two the new one, an optional third column the type. CSV or XLSX; a header row is skipped on its own. Nothing is written before you approve the preview.', 'oc-theme' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ocrd' ); ?>
			<input type="hidden" name="action" value="ocrd_upload">
			<input type="hidden" name="mode" value="pairs">
			<p><input type="file" name="file" accept=".csv,.xlsx,.txt" required></p>
			<p><button class="button button-primary"><?php esc_html_e( 'Upload and preview', 'oc-theme' ); ?></button></p>
		</form>

		<hr>
		<h2><?php esc_html_e( 'From another plugin', 'oc-theme' ); ?></h2>
		<p><?php esc_html_e( 'One click brings the existing rules over from Redirection or Rank Math, if their tables are still in the database.', 'oc-theme' ); ?></p>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocrd_import_plugin&which=redirection' ), 'ocrd' ) ); ?>"><?php esc_html_e( 'Import from Redirection', 'oc-theme' ); ?></a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocrd_import_plugin&which=rankmath' ), 'ocrd' ) ); ?>"><?php esc_html_e( 'Import from Rank Math', 'oc-theme' ); ?></a>
		</p>
		<?php
	}

	/**
	 * The preview of a parsed two-column file: four groups, all optional.
	 *
	 * @param string              $token   Preview token.
	 * @param array<string,mixed> $preview Parsed groups.
	 */
	private function preview_table( string $token, array $preview ): void {
		$groups = array(
			'new'      => __( 'New — will be created', 'oc-theme' ),
			'existing' => __( 'Existing — choose below whether to update', 'oc-theme' ),
			'problem'  => __( 'Problematic — target answers 404, or a loop', 'oc-theme' ),
			'rejected' => __( 'Rejected — empty, malformed or another domain', 'oc-theme' ),
		);
		?>
		<h2><?php esc_html_e( 'Preview', 'oc-theme' ); ?> — <?php echo esc_html( (string) ( $preview['file'] ?? '' ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ocrd' ); ?>
			<input type="hidden" name="action" value="ocrd_confirm_import">
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
			<?php foreach ( $groups as $key => $label ) : ?>
				<?php
				$rows = (array) ( $preview[ $key ] ?? array() );

				if ( empty( $rows ) ) {
					continue;
				}
				?>
				<h3><?php echo esc_html( $label ); ?> (<?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?>)</h3>
				<table class="widefat striped">
					<tbody>
						<?php foreach ( $rows as $at => $row ) : ?>
							<tr>
								<?php if ( 'rejected' !== $key ) : ?>
									<th class="check-column"><input type="checkbox" name="pick[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( (string) $at ); ?>" checked></th>
								<?php else : ?>
									<th class="check-column"></th>
								<?php endif; ?>
								<td class="ltr"><code><?php echo esc_html( (string) $row['source'] ); ?></code></td>
								<td class="ltr"><code><?php echo esc_html( (string) ( $row['target'] ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( (string) ( $row['why'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
			<p>
				<label><input type="checkbox" name="update_existing" value="1" checked> <?php esc_html_e( 'Update the target of existing rules', 'oc-theme' ); ?></label>
			</p>
			<p><button class="button button-primary"><?php esc_html_e( 'Approve and import', 'oc-theme' ); ?></button>
			<a class="button" href="<?php echo esc_url( self::url( array( 'tab' => 'import' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'oc-theme' ); ?></a></p>
		</form>
		<?php
	}

	/**
	 * Mode C: the auto-mapper — upload, review by confidence, approve.
	 */
	private function screen_map(): void {
		$token   = isset( $_GET['preview'] ) ? sanitize_key( wp_unslash( $_GET['preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preview = '' !== $token ? get_transient( 'ocrd_map_' . $token ) : null;

		if ( is_array( $preview ) ) {
			$this->map_review( $token, $preview );
			return;
		}

		$dictionary = (array) get_option( Redirects::DICTIONARY, array() );
		?>
		<h2><?php esc_html_e( 'Auto mapping from the old site', 'oc-theme' ); ?></h2>
		<p><?php esc_html_e( 'Upload only the old site\'s addresses — a CSV/XLSX with a URL column (extra columns like name, SKU and type sharpen the match), a sitemap.xml, or a pasted list. Every address walks the matching ladder and comes back with a proposed target and a confidence level. Nothing is written before your approval.', 'oc-theme' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ocrd' ); ?>
			<input type="hidden" name="action" value="ocrd_upload">
			<input type="hidden" name="mode" value="map">
			<p><input type="file" name="file" accept=".csv,.xlsx,.xml,.txt"></p>
			<p><textarea name="pasted" rows="5" class="large-text ltr" placeholder="<?php esc_attr_e( 'Or paste addresses here, one per line', 'oc-theme' ); ?>"></textarea></p>
			<p><button class="button button-primary"><?php esc_html_e( 'Map against the new site', 'oc-theme' ); ?></button></p>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Replacement dictionary', 'oc-theme' ); ?></h2>
		<p><?php esc_html_e( 'A rule filled in once per site — "/women/ becomes /clothes/" — applies to the address and to everything beneath it, on every run.', 'oc-theme' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ocrd' ); ?>
			<input type="hidden" name="action" value="ocrd_dictionary">
			<p>
				<input type="text" name="from" class="regular-text ltr" placeholder="/women/">
				&larr;
				<input type="text" name="to" class="regular-text ltr" placeholder="/clothes/">
				<button class="button"><?php esc_html_e( 'Add rule', 'oc-theme' ); ?></button>
			</p>
		</form>
		<?php if ( ! empty( $dictionary ) ) : ?>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<?php foreach ( $dictionary as $from => $to ) : ?>
						<tr>
							<td class="ltr"><code><?php echo esc_html( (string) $from ); ?></code></td>
							<td class="ltr"><code><?php echo esc_html( (string) $to ); ?></code></td>
							<td><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocrd_dictionary&drop=' . rawurlencode( (string) $from ) ), 'ocrd' ) ); ?>"><?php esc_html_e( 'Remove', 'oc-theme' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;
	}

	/**
	 * The mapper's approval screen: filter by confidence, fix, approve.
	 *
	 * @param string              $token   Preview token.
	 * @param array<string,mixed> $preview Proposals.
	 */
	private function map_review( string $token, array $preview ): void {
		$rows   = (array) ( $preview['rows'] ?? array() );
		$filter = isset( $_GET['confidence'] ) ? sanitize_key( wp_unslash( $_GET['confidence'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$counts = array(
			'certain'  => 0,
			'high'     => 0,
			'medium'   => 0,
			'fallback' => 0,
		);

		foreach ( $rows as $row ) {
			$key = (string) ( $row['confidence'] ?? 'fallback' );

			if ( isset( $counts[ $key ] ) ) {
				++$counts[ $key ];
			}
		}

		$labels = array(
			'certain'  => __( 'Certain', 'oc-theme' ),
			'high'     => __( 'High', 'oc-theme' ),
			'medium'   => __( 'To approve', 'oc-theme' ),
			'fallback' => __( 'Fallback', 'oc-theme' ),
		);

		$rules = array(
			'sku'        => __( 'Same SKU', 'oc-theme' ),
			'slug'       => __( 'Same slug', 'oc-theme' ),
			'name'       => __( 'Same name', 'oc-theme' ),
			'normalized' => __( 'Normalised match', 'oc-theme' ),
			'dictionary' => __( 'Dictionary', 'oc-theme' ),
			'similar'    => __( 'Similar words', 'oc-theme' ),
			'parent'     => __( 'Climbed to parent', 'oc-theme' ),
			'none'       => __( 'No match found', 'oc-theme' ),
		);
		?>
		<h2>
			<?php
			/* translators: %s: how many addresses. */
			echo esc_html( sprintf( __( 'Auto mapping · %s addresses', 'oc-theme' ), number_format_i18n( count( $rows ) ) ) );
			?>
		</h2>
		<p>
			<?php foreach ( $counts as $key => $count ) : ?>
				<a class="button<?php echo $filter === $key ? ' button-primary' : ''; ?>" href="
				<?php
				echo esc_url(
					self::url(
						array(
							'tab'        => 'map',
							'preview'    => $token,
							'confidence' => $filter === $key ? '' : $key,
						)
					)
				);
				?>
				">
					<?php echo esc_html( $labels[ $key ] . ' · ' . number_format_i18n( $count ) ); ?>
				</a>
			<?php endforeach; ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ocrd' ); ?>
			<input type="hidden" name="action" value="ocrd_confirm_map">
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
			<table class="widefat striped ocrd-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.ocrd-cb').forEach(c=>c.checked=this.checked)"></td>
						<th><?php esc_html_e( 'Old address', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Proposed target (editable)', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'How', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Confidence', 'oc-theme' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $at => $row ) : ?>
						<?php
						$confidence = (string) ( $row['confidence'] ?? 'fallback' );

						if ( '' !== $filter && $filter !== $confidence ) {
							continue;
						}
						?>
						<tr>
							<th class="check-column"><input class="ocrd-cb" type="checkbox" name="pick[]" value="<?php echo esc_attr( (string) $at ); ?>" checked></th>
							<td class="ltr"><code><?php echo esc_html( (string) $row['source'] ); ?></code></td>
							<td><input type="text" class="regular-text ltr ocrd-map-target" name="target[<?php echo esc_attr( (string) $at ); ?>]" list="ocrd-found" value="<?php echo esc_attr( (string) $row['target'] ); ?>"></td>
							<td><?php echo esc_html( $rules[ (string) ( $row['rule'] ?? 'none' ) ] ?? '' ); ?></td>
							<td><span class="ocrd-conf ocrd-conf--<?php echo esc_attr( $confidence ); ?>"><?php echo esc_html( $labels[ $confidence ] ?? $confidence ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<datalist id="ocrd-found"></datalist>
			<p>
				<label><?php esc_html_e( 'Apply one target to all checked rows:', 'oc-theme' ); ?>
					<input type="text" name="bulk_target" class="regular-text ltr" list="ocrd-found">
				</label>
			</p>
			<p>
				<button class="button button-primary" name="scope" value="picked"><?php esc_html_e( 'Approve the checked rows', 'oc-theme' ); ?></button>
				<button class="button" name="scope" value="certain"><?php esc_html_e( 'Approve every certain row', 'oc-theme' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocrd_export&map=' . rawurlencode( $token ) ), 'ocrd' ) ); ?>"><?php esc_html_e( 'Export mapping CSV', 'oc-theme' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::url( array( 'tab' => 'map' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'oc-theme' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * The 404 journal.
	 */
	private function screen_log(): void {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT * FROM ' . Redirects::log_table() . ' ORDER BY hits DESC LIMIT 200', ARRAY_A ); // phpcs:ignore WordPress.DB
		?>
		<h2><?php esc_html_e( '404 journal', 'oc-theme' ); ?></h2>
		<p><?php esc_html_e( 'Addresses that answered 404, how often, and where the visitor came from. Entries older than 30 days clear themselves.', 'oc-theme' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocrd_clear_log' ), 'ocrd' ) ); ?>"><?php esc_html_e( 'Clear the journal', 'oc-theme' ); ?></a></p>
		<table class="widefat striped ocrd-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Address', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Hits', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Last seen', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Came from', 'oc-theme' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Quiet here — no unanswered 404s.', 'oc-theme' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( (array) $rows as $row ) : ?>
					<tr>
						<td class="ltr"><code><?php echo esc_html( (string) $row['path'] ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
						<td><?php echo esc_html( (string) mysql2date( (string) get_option( 'date_format' ), (string) $row['last_hit'] ) ); ?></td>
						<td class="ltr"><?php echo esc_html( (string) $row['referer'] ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( self::url( array( 'from' => (string) $row['path'] ) ) ); ?>"><?php esc_html_e( 'Create redirect', 'oc-theme' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Import batches, each undoable whole.
	 */
	private function screen_batches(): void {
		$batches = (array) get_option( 'ocrd_batches', array() );
		?>
		<h2><?php esc_html_e( 'Import batches', 'oc-theme' ); ?></h2>
		<p><?php esc_html_e( 'Every import is remembered as a batch; one click takes the whole batch back out if something went wrong.', 'oc-theme' ); ?></p>
		<table class="widefat striped" style="max-width:820px">
			<thead>
				<tr>
					<th>#</th>
					<th><?php esc_html_e( 'File', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Date', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'Rules', 'oc-theme' ); ?></th>
					<th><?php esc_html_e( 'By', 'oc-theme' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $batches ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No imports yet.', 'oc-theme' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( array_reverse( $batches, true ) as $id => $batch ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( self::url( array( 'batch' => (int) $id ) ) ); ?>">#<?php echo esc_html( (string) $id ); ?></a></td>
						<td><?php echo esc_html( (string) ( $batch['file'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $batch['date'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $batch['count'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $batch['by'] ?? '' ) ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocrd_undo_batch&batch=' . (int) $id ), 'ocrd' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove every rule from this batch?', 'oc-theme' ) ); ?>')"><?php esc_html_e( 'Undo batch', 'oc-theme' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The automatic behaviour, one switch per case.
	 */
	private function screen_settings(): void {
		$settings = Redirects::settings();
		?>
		<h2><?php esc_html_e( 'Automatic redirects', 'oc-theme' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ocrd' ); ?>
			<input type="hidden" name="action" value="ocrd_settings">
			<table class="form-table">
				<?php
				$flags = array(
					'auto_product' => array( __( 'Deleted product', 'oc-theme' ), 'fixed_product' ),
					'auto_term'    => array( __( 'Deleted category', 'oc-theme' ), 'fixed_term' ),
					'auto_post'    => array( __( 'Deleted post', 'oc-theme' ), 'fixed_post' ),
					'auto_page'    => array( __( 'Deleted page', 'oc-theme' ), 'fixed_page' ),
				);
				?>
				<?php foreach ( $flags as $flag => $pair ) : ?>
					<tr>
						<th><?php echo esc_html( $pair[0] ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $flag ); ?>" value="1" <?php checked( ! empty( $settings[ $flag ] ) ); ?>> <?php esc_html_e( 'Create a redirect automatically', 'oc-theme' ); ?></label>
							<p><input type="text" class="regular-text ltr" name="<?php echo esc_attr( $pair[1] ); ?>" value="<?php echo esc_attr( (string) $settings[ $pair[1] ] ); ?>" placeholder="<?php esc_attr_e( 'Fixed target (optional) — overrides the step-up logic', 'oc-theme' ); ?>"></p>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th><?php esc_html_e( 'Address changes', 'oc-theme' ); ?></th>
					<td><label><input type="checkbox" name="auto_slug" value="1" <?php checked( ! empty( $settings['auto_slug'] ) ); ?>> <?php esc_html_e( 'Redirect the old address when a slug changes', 'oc-theme' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( '404 journal', 'oc-theme' ); ?></th>
					<td><label><input type="checkbox" name="log404" value="1" <?php checked( ! empty( $settings['log404'] ) ); ?>> <?php esc_html_e( 'Keep the journal', 'oc-theme' ); ?></label></td>
				</tr>
			</table>
			<p><button class="button button-primary"><?php esc_html_e( 'Save', 'oc-theme' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * A pinch of style and behaviour, printed on our screens only.
	 */
	private function footer_assets(): void {
		?>
		<style>
			.ocrd .ltr { direction: ltr; text-align: left; }
			.ocrd-form { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 14px 16px; margin: 16px 0; }
			.ocrd-form__row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
			.ocrd-form__row label span { display: block; font-weight: 600; margin-bottom: 4px; }
			.ocrd-filters { display: flex; gap: 8px; align-items: center; margin: 12px 0; }
			.ocrd-count { margin-inline-start: auto; color: #646970; }
			.ocrd-table code { background: none; padding: 0; }
			.ocrd-conf { display: inline-block; padding: 2px 10px; border-radius: 99px; font-size: 12px; }
			.ocrd-conf--certain { background: #d5f5e3; }
			.ocrd-conf--high { background: #d6eaf8; }
			.ocrd-conf--medium { background: #fdf2d0; }
			.ocrd-conf--fallback { background: #fadbd8; }
			.ocrd-pages a, .ocrd-pages strong { margin-inline-end: 6px; }
		</style>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'ocrd' ) ); ?>;
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			// The on/off switch saves itself.
			document.querySelectorAll( '.ocrd-toggle' ).forEach( function ( box ) {
				box.addEventListener( 'change', function () {
					var body = new FormData();
					body.append( 'action', 'ocrd_toggle' );
					body.append( 'nonce', nonce );
					body.append( 'id', box.dataset.id );
					body.append( 'on', box.checked ? '1' : '0' );
					fetch( ajax, { method: 'POST', credentials: 'same-origin', body: body } );
				} );
			} );

			// The target fields search the site as you type.
			var list = document.getElementById( 'ocrd-found' );
			var timer = null;

			function seek( q ) {
				clearTimeout( timer );
				timer = setTimeout( function () {
					if ( q.length < 2 || ! list ) { return; }
					var body = new FormData();
					body.append( 'action', 'ocrd_find' );
					body.append( 'nonce', nonce );
					body.append( 'q', q );
					fetch( ajax, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( r ) {
							if ( ! r || ! r.success ) { return; }
							list.innerHTML = '';
							r.data.hits.forEach( function ( hit ) {
								var opt = document.createElement( 'option' );
								opt.value = hit.path;
								opt.label = hit.label;
								list.appendChild( opt );
							} );
						} );
				}, 250 );
			}

			document.addEventListener( 'input', function ( e ) {
				if ( e.target.matches( '#ocrd-target, .ocrd-map-target, input[name="bulk_target"]' ) ) {
					seek( e.target.value.trim() );
				}
			} );
		}() );
		</script>
		<?php
	}

	/*
	 * ------------------------------------------------------------- handlers
	 */

	/**
	 * Everything a handler needs before touching anything.
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( 'ocrd' );
	}

	/**
	 * Back to the room with a word.
	 *
	 * @param string               $message What happened.
	 * @param array<string,string> $args    Extra query args.
	 */
	private function back( string $message, array $args = array() ): void {
		wp_safe_redirect( self::url( array_merge( $args, array( 'ocrd_msg' => $message ) ) ) );
		exit;
	}

	/**
	 * One rule from the form.
	 */
	public function handle_save(): void {
		$this->guard();

		global $wpdb;

		// Editing may change the source itself; the old row must not linger.
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().

		if ( $id > 0 ) {
			$was = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT source FROM ' . Redirects::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB

			$raw = (string) wp_unslash( $_POST['source'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); normalized on the next line.
			$now = Redirects::normalize( $raw );

			if ( '*' === substr( rtrim( $raw ), -1 ) ) {
				$now = str_replace( '//*', '/*', $now . '/*' );
			}

			if ( '' !== $was && $now !== $was ) {
				Redirects::delete( array( $id ) );
			}
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); Redirects::save() normalizes and whitelists every field.
		$saved = Redirects::save(
			array(
				'source' => (string) wp_unslash( $_POST['source'] ?? '' ),
				'target' => (string) wp_unslash( $_POST['target'] ?? '' ),
				'type'   => absint( $_POST['type'] ?? 301 ),
				'note'   => (string) wp_unslash( $_POST['note'] ?? '' ),
				'origin' => 'manual',
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

		if ( is_wp_error( $saved ) ) {
			$this->back( $saved->get_error_message() );
		}

		$this->back( __( 'Saved.', 'oc-theme' ) );
	}

	/**
	 * Bulk delete / on / off.
	 */
	public function handle_bulk(): void {
		$this->guard();

		global $wpdb;

		$ids   = array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); absint() bounds every id.
		$doing = sanitize_key( (string) wp_unslash( $_POST['doing'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().

		if ( empty( $ids ) ) {
			$this->back( __( 'Nothing was selected.', 'oc-theme' ) );
		}

		if ( 'delete' === $doing ) {
			Redirects::delete( $ids );
		} else {
			$wpdb->query( 'UPDATE ' . Redirects::table() . ' SET active = ' . ( 'on' === $doing ? 1 : 0 ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB
			Redirects::rebuild_cache();
		}

		$this->back( __( 'Done.', 'oc-theme' ) );
	}

	/**
	 * The row switch, without leaving the page.
	 */
	public function ajax_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ocrd', 'nonce', false ) ) {
			wp_send_json_error();
		}

		global $wpdb;

		$wpdb->update( Redirects::table(), array( 'active' => absint( $_POST['on'] ?? 0 ) ), array( 'id' => absint( $_POST['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB
		Redirects::rebuild_cache();
		wp_send_json_success();
	}

	/**
	 * The target field's live search: pages, products, categories, posts.
	 */
	public function ajax_find(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ocrd', 'nonce', false ) ) {
			wp_send_json_error();
		}

		$q    = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		$hits = array();

		foreach ( (array) get_posts(
			array(
				'post_type'      => array( 'page', 'product', 'post' ),
				'post_status'    => 'publish',
				's'              => $q,
				'posts_per_page' => 8,
				'no_found_rows'  => true,
			)
		) as $post ) {
			$hits[] = array(
				'label' => $post->post_title,
				'path'  => Redirects::normalize( (string) get_permalink( $post ) ),
			);
		}

		foreach ( array_filter( array( 'product_cat', 'category', Search::brand_taxonomy() ) ) as $taxonomy ) {
			foreach ( (array) get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'name__like' => $q,
					'number'     => 5,
				)
			) as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$link = get_term_link( $term );

				if ( ! is_wp_error( $link ) ) {
					$hits[] = array(
						'label' => $term->name,
						'path'  => Redirects::normalize( (string) $link ),
					);
				}
			}
		}

		wp_send_json_success( array( 'hits' => array_slice( $hits, 0, 12 ) ) );
	}

	/*
	 * ----------------------------------------------------- files and parsing
	 */

	/**
	 * Rows out of an uploaded CSV / XLSX / XML / text file.
	 *
	 * @param string $tmp  Temp path.
	 * @param string $name Original file name.
	 * @return array<int,array<int,string>>
	 */
	private function read_file( string $tmp, string $name ): array {
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$rows = array();

		if ( 'xlsx' === $ext && class_exists( '\ZipArchive' ) ) {
			$rows = $this->read_xlsx( $tmp );
		} elseif ( 'xml' === $ext ) {
			$xml = simplexml_load_string( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false !== $xml ) {
				foreach ( $xml->url as $entry ) {
					$rows[] = array( (string) $entry->loc );
				}

				foreach ( $xml->sitemap as $entry ) {
					$rows[] = array( (string) $entry->loc );
				}
			}
		} else {
			$handle = fopen( $tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( $handle ) {
				while ( false !== ( $line = fgetcsv( $handle ) ) ) { // phpcs:ignore
					$rows[] = array_map( 'strval', $line );
				}

				fclose( $handle ); // phpcs:ignore
			}
		}

		return $rows;
	}

	/**
	 * The barest possible XLSX reader: first sheet, shared strings honoured.
	 *
	 * @param string $tmp Temp path.
	 * @return array<int,array<int,string>>
	 */
	private function read_xlsx( string $tmp ): array {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $tmp ) ) {
			return array();
		}

		$shared = array();
		$raw    = $zip->getFromName( 'xl/sharedStrings.xml' );

		if ( false !== $raw ) {
			$xml = simplexml_load_string( $raw );

			if ( false !== $xml ) {
				foreach ( $xml->si as $si ) {
					$text = '';

					if ( isset( $si->t ) ) {
						$text = (string) $si->t;
					} else {
						foreach ( $si->r as $run ) {
							$text .= (string) $run->t;
						}
					}

					$shared[] = $text;
				}
			}
		}

		$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();

		if ( false === $sheet ) {
			return array();
		}

		$xml = simplexml_load_string( $sheet );

		if ( false === $xml ) {
			return array();
		}

		$rows = array();

		foreach ( $xml->sheetData->row as $line ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the XLSX schema names this node.
			$cells = array();

			foreach ( $line->c as $cell ) {
				$value = isset( $cell->v ) ? (string) $cell->v : '';

				if ( 's' === (string) $cell['t'] && isset( $shared[ (int) $value ] ) ) {
					$value = $shared[ (int) $value ];
				}

				// The column letter keeps the cells in their places.
				$ref = (string) $cell['r'];
				$col = 0;

				foreach ( str_split( (string) preg_replace( '/\d+/', '', $ref ) ) as $letter ) {
					$col = $col * 26 + ( ord( $letter ) - 64 );
				}

				$cells[ max( 0, $col - 1 ) ] = $value;
			}

			if ( ! empty( $cells ) ) {
				ksort( $cells );
				$rows[] = array_values( $cells );
			}
		}

		return $rows;
	}

	/**
	 * A row of columns into named fields, header-aware.
	 *
	 * @param array<int,array<int,string>> $rows Raw rows.
	 * @return array{rows:array<int,array<string,string>>}
	 */
	private function detect_columns( array $rows ): array {
		$map = array(
			'url'  => 0,
			'name' => -1,
			'sku'  => -1,
		);

		// A WooCommerce export or any headed file names its own columns.
		if ( ! empty( $rows ) ) {
			$head = array_map( 'mb_strtolower', array_map( 'trim', $rows[0] ) );

			foreach ( $head as $at => $title ) {
				if ( in_array( $title, array( 'url', 'permalink', 'address', 'כתובת', 'link' ), true ) ) {
					$map['url'] = $at;
				}

				if ( in_array( $title, array( 'name', 'title', 'שם', 'post_title' ), true ) ) {
					$map['name'] = $at;
				}

				if ( in_array( $title, array( 'sku', 'מק"ט', 'מקט' ), true ) ) {
					$map['sku'] = $at;
				}
			}

			// A header row never looks like an address.
			if ( false === strpos( (string) ( $rows[0][ $map['url'] ] ?? '' ), '/' ) ) {
				array_shift( $rows );
			}
		}

		$out = array();

		foreach ( $rows as $row ) {
			$url = trim( (string) ( $row[ $map['url'] ] ?? '' ) );

			if ( '' === $url ) {
				continue;
			}

			$out[] = array(
				'url'  => $url,
				'name' => $map['name'] >= 0 ? trim( (string) ( $row[ $map['name'] ] ?? '' ) ) : '',
				'sku'  => $map['sku'] >= 0 ? trim( (string) ( $row[ $map['sku'] ] ?? '' ) ) : '',
			);
		}

		return array( 'rows' => $out );
	}

	/**
	 * Upload for either mode: parse, sort, park in a preview.
	 */
	public function handle_upload(): void {
		$this->guard();

		$mode   = sanitize_key( (string) wp_unslash( $_POST['mode'] ?? 'pairs' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		$pasted = trim( (string) wp_unslash( $_POST['pasted'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); every line is normalized as it is parsed.
		$name   = '';
		$rows   = array();

		if ( ! empty( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
			$name = sanitize_file_name( (string) $_FILES['file']['name'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); $_FILES is never slashed, and sanitize_file_name() cleans it.
			$rows = $this->read_file( (string) $_FILES['file']['tmp_name'], $name ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); tmp_name is a server-made path.
		} elseif ( '' !== $pasted ) {
			$name  = __( 'Pasted list', 'oc-theme' );
			$lines = preg_split( '/\r?\n/', $pasted );

			if ( ! is_array( $lines ) ) {
				$lines = array();
			}

			foreach ( $lines as $line ) {
				$rows[] = array( trim( $line ) );
			}
		}

		if ( empty( $rows ) ) {
			$this->back( __( 'The file came up empty.', 'oc-theme' ), array( 'tab' => 'pairs' === $mode ? 'import' : 'map' ) );
		}

		$token = substr( md5( uniqid( 'ocrd', true ) ), 0, 10 );

		if ( 'pairs' === $mode ) {
			set_transient( 'ocrd_preview_' . $token, $this->sort_pairs( $rows, $name ), HOUR_IN_SECONDS * 6 );
			$this->back(
				__( 'Parsed — review below.', 'oc-theme' ),
				array(
					'tab'     => 'import',
					'preview' => $token,
				)
			);
		}

		set_transient( 'ocrd_map_' . $token, $this->run_mapper( $rows, $name ), HOUR_IN_SECONDS * 6 );
		$this->back(
			__( 'Mapped — review below.', 'oc-theme' ),
			array(
				'tab'     => 'map',
				'preview' => $token,
			)
		);
	}

	/**
	 * Mode B rows into the four preview groups.
	 *
	 * @param array<int,array<int,string>> $rows Raw rows.
	 * @param string                       $file File name.
	 * @return array<string,mixed>
	 */
	private function sort_pairs( array $rows, string $file ): array {
		global $wpdb;

		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$out  = array(
			'file'     => $file,
			'new'      => array(),
			'existing' => array(),
			'problem'  => array(),
			'rejected' => array(),
		);

		// A header row never looks like an address.
		if ( ! empty( $rows ) && false === strpos( (string) ( $rows[0][0] ?? '' ), '/' ) ) {
			array_shift( $rows );
		}

		$existing = $wpdb->get_col( 'SELECT source FROM ' . Redirects::table() ); // phpcs:ignore WordPress.DB
		$existing = array_flip( array_map( 'strval', (array) $existing ) );

		foreach ( $rows as $row ) {
			$old = trim( (string) ( $row[0] ?? '' ) );
			$new = trim( (string) ( $row[1] ?? '' ) );
			$typ = absint( $row[2] ?? 301 );

			if ( '' === $old || '' === $new ) {
				$out['rejected'][] = array(
					'source' => $old,
					'target' => $new,
					'why'    => __( 'An empty column', 'oc-theme' ),
				);
				continue;
			}

			$host = (string) wp_parse_url( $old, PHP_URL_HOST );

			if ( '' !== $host && false === stripos( $host, $home ) && false === stripos( $home, $host ) ) {
				$out['rejected'][] = array(
					'source' => $old,
					'target' => $new,
					'why'    => __( 'Another domain', 'oc-theme' ),
				);
				continue;
			}

			$source = Redirects::normalize( $old );
			$target = Redirects::normalize( $new );
			$entry  = array(
				'source' => $source,
				'target' => 0 === strpos( $new, 'http' ) && '' !== (string) wp_parse_url( $new, PHP_URL_HOST ) && false === stripos( (string) wp_parse_url( $new, PHP_URL_HOST ), $home ) ? $new : $target,
				'type'   => in_array( $typ, array( 301, 302, 410 ), true ) ? $typ : 301,
				'why'    => '',
			);

			if ( $source === $target ) {
				$entry['why']     = __( 'Points at itself', 'oc-theme' );
				$out['problem'][] = $entry;
			} elseif ( isset( $existing[ $source ] ) ) {
				$entry['why']      = __( 'Already in the table', 'oc-theme' );
				$out['existing'][] = $entry;
			} else {
				$out['new'][] = $entry;
			}
		}

		return $out;
	}

	/**
	 * Approve mode B.
	 */
	public function handle_confirm_import(): void {
		$this->guard();

		$token   = sanitize_key( (string) wp_unslash( $_POST['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		$preview = get_transient( 'ocrd_preview_' . $token );

		if ( ! is_array( $preview ) ) {
			$this->back( __( 'The preview expired — upload again.', 'oc-theme' ), array( 'tab' => 'import' ) );
		}

		$batch  = $this->new_batch( (string) $preview['file'] );
		$picks  = (array) ( $_POST['pick'] ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); every pick is absint()-bound below.
		$update = ! empty( $_POST['update_existing'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		$made   = 0;

		foreach ( array( 'new', 'existing', 'problem' ) as $group ) {
			if ( 'existing' === $group && ! $update ) {
				continue;
			}

			foreach ( array_map( 'absint', (array) ( $picks[ $group ] ?? array() ) ) as $at ) {
				$row = $preview[ $group ][ $at ] ?? null;

				if ( ! is_array( $row ) ) {
					continue;
				}

				$saved = Redirects::save(
					array(
						'source'   => (string) $row['source'],
						'target'   => (string) $row['target'],
						'type'     => (int) ( $row['type'] ?? 301 ),
						'origin'   => 'import',
						'batch_id' => $batch,
					)
				);

				if ( ! is_wp_error( $saved ) ) {
					++$made;
				}
			}
		}

		$this->seal_batch( $batch, $made );
		delete_transient( 'ocrd_preview_' . $token );

		/* translators: %s: how many rules. */
		$this->back( sprintf( __( 'Imported %s rules.', 'oc-theme' ), number_format_i18n( $made ) ) );
	}

	/**
	 * Mode C: run every old address down the ladder.
	 *
	 * @param array<int,array<int,string>> $rows Raw rows.
	 * @param string                       $file File name.
	 * @return array<string,mixed>
	 */
	private function run_mapper( array $rows, string $file ): array {
		global $wpdb;

		$named = $this->detect_columns( $rows );
		$index = Redirects::site_index();

		// Manual rows are never touched again; their choices also feed the
		// parent-climb, so a child lands where its parent was sent.
		$mapped = array();

		foreach ( (array) $wpdb->get_results( 'SELECT source, target, origin FROM ' . Redirects::table(), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$mapped[ (string) $row['source'] ] = (string) $row['target'];
		}

		// Parents before children, so percolation has something to climb to.
		usort(
			$named['rows'],
			static function ( $a, $b ) {
				return substr_count( (string) $a['url'], '/' ) <=> substr_count( (string) $b['url'], '/' );
			}
		);

		$out = array();

		foreach ( $named['rows'] as $row ) {
			$path = Redirects::normalize( (string) $row['url'] );

			if ( '' === $path || '/' === $path ) {
				continue;
			}

			$proposal = Redirects::propose( $path, $row, $index, $mapped );

			$mapped[ $path ] = (string) $proposal['target'];

			$out[] = array(
				'source'     => $path,
				'target'     => $proposal['target'],
				'rule'       => $proposal['rule'],
				'confidence' => $proposal['confidence'],
			);
		}

		return array(
			'file' => $file,
			'rows' => $out,
		);
	}

	/**
	 * Approve mode C.
	 */
	public function handle_confirm_map(): void {
		$this->guard();

		global $wpdb;

		$token   = sanitize_key( (string) wp_unslash( $_POST['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		$preview = get_transient( 'ocrd_map_' . $token );

		if ( ! is_array( $preview ) ) {
			$this->back( __( 'The mapping expired — upload again.', 'oc-theme' ), array( 'tab' => 'map' ) );
		}

		$scope   = sanitize_key( (string) wp_unslash( $_POST['scope'] ?? 'picked' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		$picks   = array_flip( array_map( 'absint', (array) ( $_POST['pick'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); absint() bounds every pick.
		$targets = (array) ( $_POST['target'] ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); each target is unslashed and normalized at use.
		$bulk    = Redirects::normalize( (string) wp_unslash( $_POST['bulk_target'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); normalized.
		$batch   = $this->new_batch( (string) $preview['file'] );
		$made    = 0;

		// Manual rows stand; only robot fallbacks may be improved on a re-run.
		$standing = array();

		foreach ( (array) $wpdb->get_results( 'SELECT source, origin, confidence FROM ' . Redirects::table(), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$standing[ (string) $row['source'] ] = array( (string) $row['origin'], (string) $row['confidence'] );
		}

		foreach ( (array) $preview['rows'] as $at => $row ) {
			$confidence = (string) $row['confidence'];

			if ( 'certain' === $scope && 'certain' !== $confidence ) {
				continue;
			}

			if ( 'picked' === $scope && ! isset( $picks[ $at ] ) ) {
				continue;
			}

			$source = (string) $row['source'];

			if ( isset( $standing[ $source ] ) ) {
				list( $origin, $had ) = $standing[ $source ];

				// Only a fallback made by a robot is worth re-deciding.
				if ( 'manual' === $origin || 'fallback' !== $had ) {
					continue;
				}
			}

			$target = isset( $targets[ $at ] ) ? Redirects::normalize( (string) wp_unslash( $targets[ $at ] ) ) : (string) $row['target'];

			if ( 'picked' === $scope && '' !== $bulk && isset( $picks[ $at ] ) && '' !== (string) wp_unslash( $_POST['bulk_target'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); an emptiness check only.
				$target = $bulk;
			}

			$saved = Redirects::save(
				array(
					'source'     => $source,
					'target'     => '' === $target ? '/' : $target,
					'origin'     => 'import',
					'batch_id'   => $batch,
					'match_rule' => (string) $row['rule'],
					'confidence' => $confidence,
				)
			);

			if ( ! is_wp_error( $saved ) ) {
				++$made;
			}
		}

		$this->seal_batch( $batch, $made );

		/* translators: %s: how many rules. */
		$this->back( sprintf( __( 'Imported %s rules.', 'oc-theme' ), number_format_i18n( $made ) ) );
	}

	/*
	 * ------------------------------------------------------ everything else
	 */

	/**
	 * Open a new batch, return its id.
	 *
	 * @param string $file The file behind it.
	 */
	private function new_batch( string $file ): int {
		$batches = (array) get_option( 'ocrd_batches', array() );
		$id      = empty( $batches ) ? 1 : max( array_map( 'intval', array_keys( $batches ) ) ) + 1;
		$user    = wp_get_current_user();

		$batches[ $id ] = array(
			'file'  => $file,
			'date'  => current_time( 'mysql' ),
			'count' => 0,
			'by'    => $user instanceof \WP_User ? $user->user_login : '',
		);

		update_option( 'ocrd_batches', $batches, false );

		return $id;
	}

	/**
	 * Stamp the batch with its final count.
	 *
	 * @param int $batch Batch id.
	 * @param int $count Rules written.
	 */
	private function seal_batch( int $batch, int $count ): void {
		$batches = (array) get_option( 'ocrd_batches', array() );

		if ( isset( $batches[ $batch ] ) ) {
			$batches[ $batch ]['count'] = $count;
			update_option( 'ocrd_batches', $batches, false );
		}
	}

	/**
	 * Take a whole batch back out.
	 */
	public function handle_undo_batch(): void {
		$this->guard();

		global $wpdb;

		$batch = absint( $_GET['batch'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() ran check_admin_referer().
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Redirects::table() . ' WHERE batch_id = %d', $batch ) ); // phpcs:ignore WordPress.DB
		Redirects::rebuild_cache();

		$batches = (array) get_option( 'ocrd_batches', array() );
		unset( $batches[ $batch ] );
		update_option( 'ocrd_batches', $batches, false );

		$this->back( __( 'The batch was taken out.', 'oc-theme' ), array( 'tab' => 'batches' ) );
	}

	/**
	 * The table as CSV — or a pending mapping, when asked.
	 */
	public function handle_export(): void {
		$this->guard();

		global $wpdb;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=redirects-' . gmdate( 'Y-m-d' ) . '.csv' );
		echo "\xEF\xBB\xBF";

		$map = isset( $_GET['map'] ) ? sanitize_key( wp_unslash( $_GET['map'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() ran check_admin_referer().

		if ( '' !== $map ) {
			$preview = get_transient( 'ocrd_map_' . $map );
			echo "source,target,rule,confidence\n";

			foreach ( (array) ( $preview['rows'] ?? array() ) as $row ) {
				echo '"' . str_replace( '"', '""', (string) $row['source'] ) . '","' . str_replace( '"', '""', (string) $row['target'] ) . '",' . esc_html( (string) $row['rule'] ) . ',' . esc_html( (string) $row['confidence'] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			}

			exit;
		}

		echo "source,target,type,origin,hits,active,note\n";

		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Redirects::table() . ' ORDER BY id', ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			echo '"' . str_replace( '"', '""', (string) $row['source'] ) . '","' . str_replace( '"', '""', (string) $row['target'] ) . '",' . (int) $row['type'] . ',' . esc_html( (string) $row['origin'] ) . ',' . (int) $row['hits'] . ',' . (int) $row['active'] . ',"' . str_replace( '"', '""', (string) $row['note'] ) . "\"\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		exit;
	}

	/**
	 * Settings.
	 */
	public function handle_settings(): void {
		$this->guard();

		$settings = array();

		foreach ( array( 'log404', 'auto_product', 'auto_term', 'auto_post', 'auto_page', 'auto_slug' ) as $flag ) {
			$settings[ $flag ] = empty( $_POST[ $flag ] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		}

		foreach ( array( 'fixed_product', 'fixed_term', 'fixed_post', 'fixed_page' ) as $field ) {
			$raw                = trim( (string) wp_unslash( $_POST[ $field ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); normalized on the next line.
			$settings[ $field ] = '' === $raw ? '' : Redirects::normalize( $raw );
		}

		update_option( Redirects::SETTINGS, $settings, false );
		$this->back( __( 'Saved.', 'oc-theme' ), array( 'tab' => 'settings' ) );
	}

	/**
	 * The dictionary: add a pair, or drop one.
	 */
	public function handle_dictionary(): void {
		$this->guard();

		$dictionary = (array) get_option( Redirects::DICTIONARY, array() );
		$drop       = isset( $_GET['drop'] ) ? (string) rawurldecode( (string) wp_unslash( $_GET['drop'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); used only as an exact array key.

		if ( '' !== $drop ) {
			unset( $dictionary[ $drop ] );
		} else {
			$from = Redirects::normalize( (string) wp_unslash( $_POST['from'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); normalized.
			$to   = Redirects::normalize( (string) wp_unslash( $_POST['to'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() ran check_admin_referer(); normalized.

			if ( '' !== $from && '' !== $to && $from !== $to ) {
				$dictionary[ $from ] = $to;
			}
		}

		update_option( Redirects::DICTIONARY, $dictionary, false );
		$this->back( __( 'Saved.', 'oc-theme' ), array( 'tab' => 'map' ) );
	}

	/**
	 * Empty the 404 journal.
	 */
	public function handle_clear_log(): void {
		$this->guard();

		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . Redirects::log_table() ); // phpcs:ignore WordPress.DB
		$this->back( __( 'The journal was cleared.', 'oc-theme' ), array( 'tab' => 'log' ) );
	}

	/**
	 * One click over from Redirection or Rank Math, plain rules only.
	 */
	public function handle_import_plugin(): void {
		$this->guard();

		global $wpdb;

		$which = sanitize_key( (string) wp_unslash( $_GET['which'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() ran check_admin_referer().
		$made  = 0;
		$batch = $this->new_batch( 'redirection' === $which ? 'Redirection' : 'Rank Math' );

		if ( 'redirection' === $which ) {
			$table = $wpdb->prefix . 'redirection_items';

			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
				foreach ( (array) $wpdb->get_results( "SELECT url, action_data, action_code FROM $table WHERE status = 'enabled' AND regex = 0 AND action_type = 'url'", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
					$saved = Redirects::save(
						array(
							'source'   => (string) $row['url'],
							'target'   => (string) $row['action_data'],
							'type'     => (int) $row['action_code'],
							'origin'   => 'import',
							'batch_id' => $batch,
						),
						false
					);

					if ( ! is_wp_error( $saved ) ) {
						++$made;
					}
				}
			}
		}

		if ( 'rankmath' === $which ) {
			$table = $wpdb->prefix . 'rank_math_redirections';

			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
				foreach ( (array) $wpdb->get_results( "SELECT sources, url_to, header_code FROM $table WHERE status = 'active'", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
					$sources = maybe_unserialize( $row['sources'] );

					foreach ( (array) $sources as $one ) {
						if ( ! is_array( $one ) || 'exact' !== ( $one['comparison'] ?? '' ) ) {
							continue;
						}

						$saved = Redirects::save(
							array(
								'source'   => (string) $one['pattern'],
								'target'   => (string) $row['url_to'],
								'type'     => (int) $row['header_code'],
								'origin'   => 'import',
								'batch_id' => $batch,
							),
							false
						);

						if ( ! is_wp_error( $saved ) ) {
							++$made;
						}
					}
				}
			}
		}

		$this->seal_batch( $batch, $made );

		/* translators: %s: how many rules. */
		$this->back( sprintf( __( 'Imported %s rules.', 'oc-theme' ), number_format_i18n( $made ) ) );
	}
}
