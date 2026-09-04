<?php
/**
 * OC Media cleanup — the screen.
 *
 * A scan with a progress bar, then the findings in three groups with the
 * pictures shown, so nothing is ever deleted sight unseen. Every item can be
 * unticked, the deleting happens in small batches, and each file is checked
 * once more the moment before it goes.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The cleanup room.
 */
final class Media_Clean_Admin {

	/**
	 * Draw it.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$results = Media_Clean::results();
		$nonce   = wp_create_nonce( 'ocmc' );
		$logged  = Media_Clean::log_count();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading a result summary, nothing acted on.
		$just_did = isset( $_GET['ocmc_done'] ) ? absint( $_GET['ocmc_done'] ) : -1;
		$freed    = isset( $_GET['ocmc_freed'] ) ? absint( $_GET['ocmc_freed'] ) : 0;
		$kept     = isset( $_GET['ocmc_kept'] ) ? absint( $_GET['ocmc_kept'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap ocmc">
			<h1><?php esc_html_e( 'Media cleanup', 'oc-theme' ); ?></h1>

			<?php if ( $just_did >= 0 ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: 1: number of files, 2: disk space, e.g. "5.4 MB". */
							esc_html( _n( '%1$d file deleted, %2$s freed.', '%1$d files deleted, %2$s freed.', $just_did, 'oc-theme' ) ),
							(int) $just_did,
							esc_html( size_format( $freed, 1 ) )
						);
						?>
						<?php if ( $kept > 0 ) : ?>
							<?php
							printf(
								/* translators: %d: number of files. */
								esc_html( _n( '%d was kept because something still uses it.', '%d were kept because something still uses them.', $kept, 'oc-theme' ) ),
								(int) $kept
							);
							?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<p class="ocmc__lede">
				<?php esc_html_e( 'Finds pictures and videos nothing points at any more. A file is treated as in use if it hangs off a post, if its ID appears in any field that holds media, or if its name is written anywhere at all — content, settings or the customizer. Anything in the least doubtful is kept.', 'oc-theme' ); ?>
			</p>

			<?php self::styles(); ?>

			<nav class="ocmc__tabs">
				<button type="button" class="ocmc__tab is-on" data-ocmc-tab="clean"><?php esc_html_e( 'Cleanup', 'oc-theme' ); ?></button>
				<button type="button" class="ocmc__tab" data-ocmc-tab="heavy"><?php esc_html_e( 'Heavy files', 'oc-theme' ); ?></button>
			</nav>

			<div class="ocmc__panel" data-ocmc-panel="clean">
			<p>
				<button type="button" class="button button-primary button-hero" id="ocmc-scan">
					<?php esc_html_e( 'Scan the media library', 'oc-theme' ); ?>
				</button>
				<?php if ( $logged > 0 ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocmc_csv' ), 'ocmc_csv' ) ); ?>">
						<?php
						printf(
							/* translators: %d: number of files. */
							esc_html( _n( 'Download the record (%d file)', 'Download the record (%d files)', $logged, 'oc-theme' ) ),
							(int) $logged
						);
						?>
					</a>
				<?php endif; ?>
			</p>

			<div class="ocmc__bar" id="ocmc-bar" hidden><i></i><span></span></div>

			<p class="ocmc__controls" id="ocmc-kind" hidden>
				<label>
					<?php esc_html_e( 'Show', 'oc-theme' ); ?>
					<select id="ocmc-kind-pick">
						<option value="all"><?php esc_html_e( 'Everything', 'oc-theme' ); ?></option>
						<option value="image"><?php esc_html_e( 'Pictures', 'oc-theme' ); ?></option>
						<option value="video"><?php esc_html_e( 'Films', 'oc-theme' ); ?></option>
						<option value="other"><?php esc_html_e( 'Documents and the rest', 'oc-theme' ); ?></option>
					</select>
				</label>
			</p>

			<div id="ocmc-out">
				<?php
				if ( $results ) {
					self::results( $results );
				}
				?>
			</div>
			</div>

			<?php self::heavy_panel(); ?>
		</div>
		<?php

		self::script( $nonce, $just_did >= 0 && ! isset( $_GET['ocmc_scanned'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The findings.
	 *
	 * @param array<string,mixed> $r Results from the scanner.
	 */
	public static function results( array $r ): void {
		$groups = array(
			'orphan' => array(
				'title' => __( 'Attached to nothing', 'oc-theme' ),
				'note'  => __( 'No post, no field and no page mentions these. Safe to remove.', 'oc-theme' ),
			),
			'draft'  => array(
				'title' => __( 'Belonging to drafts', 'oc-theme' ),
				'note'  => __( 'Uploaded to a product or page that was never published. Removing them empties those drafts.', 'oc-theme' ),
			),
			'trash'  => array(
				'title' => __( 'Belonging to the bin', 'oc-theme' ),
				'note'  => __( 'Uploaded to something already in the bin. If you might restore it, keep these.', 'oc-theme' ),
			),
		);

		$free = 0;
		foreach ( array_keys( $groups ) as $key ) {
			$free += (int) $r[ $key ]['bytes'];
		}

		?>
		<div class="ocmc__stats">
			<div class="ocmc__stat ocmc__stat--safe">
				<b><?php echo esc_html( (string) (int) $r['used'] ); ?></b>
				<span><?php esc_html_e( 'in use', 'oc-theme' ); ?></span>
			</div>
			<div class="ocmc__stat">
				<b><?php echo esc_html( (string) count( $r['orphan']['items'] ) ); ?></b>
				<span><?php esc_html_e( 'attached to nothing', 'oc-theme' ); ?></span>
			</div>
			<div class="ocmc__stat">
				<b><?php echo esc_html( (string) count( $r['draft']['items'] ) ); ?></b>
				<span><?php esc_html_e( 'on drafts', 'oc-theme' ); ?></span>
			</div>
			<div class="ocmc__stat">
				<b><?php echo esc_html( (string) count( $r['trash']['items'] ) ); ?></b>
				<span><?php esc_html_e( 'on binned items', 'oc-theme' ); ?></span>
			</div>
			<div class="ocmc__stat ocmc__stat--free">
				<b><?php echo esc_html( size_format( $free, 1 ) ); ?></b>
				<span><?php esc_html_e( 'can be freed', 'oc-theme' ); ?></span>
			</div>
		</div>

		<?php if ( (int) $r['recent'] > 0 ) : ?>
			<p class="ocmc__held">
				<?php
				printf(
					/* translators: %d: number of files. */
					esc_html( _n( '%d file uploaded in the last two days is held back, in case it is still being worked on.', '%d files uploaded in the last two days are held back, in case they are still being worked on.', (int) $r['recent'], 'oc-theme' ) ),
					(int) $r['recent']
				);
				?>
			</p>
		<?php endif; ?>

		<?php
		$any = false;
		foreach ( $groups as $key => $group ) {
			$items = (array) $r[ $key ]['items'];
			if ( ! $items ) {
				continue;
			}
			$any = true;
			?>
			<div class="ocmc__group" data-ocmc-group="<?php echo esc_attr( $key ); ?>">
				<h2>
					<?php echo esc_html( $group['title'] ); ?>
					<em><?php echo esc_html( size_format( (int) $r[ $key ]['bytes'], 1 ) ); ?></em>
				</h2>
				<p class="ocmc__note"><?php echo esc_html( $group['note'] ); ?></p>

				<p class="ocmc__pick">
					<button type="button" class="button-link" data-ocmc-all="1"><?php esc_html_e( 'Select all', 'oc-theme' ); ?></button>
					<button type="button" class="button-link" data-ocmc-all="0"><?php esc_html_e( 'Select none', 'oc-theme' ); ?></button>
				</p>

				<ul class="ocmc__grid">
					<?php foreach ( $items as $item ) : ?>
						<li class="ocmc__item" data-ocmc-mime="<?php echo esc_attr( (string) $item['mime'] ); ?>">
							<label>
								<input type="checkbox" class="ocmc__cb" value="<?php echo esc_attr( (string) $item['id'] ); ?>" <?php checked( 'orphan', $key ); ?> />
								<?php if ( $item['thumb'] ) : ?>
									<img src="<?php echo esc_url( (string) $item['thumb'] ); ?>" alt="" loading="lazy" />
								<?php else : ?>
									<span class="ocmc__nopic"><?php esc_html_e( 'no preview', 'oc-theme' ); ?></span>
								<?php endif; ?>
								<b><?php echo esc_html( (string) $item['name'] ); ?></b>
								<span><?php echo esc_html( size_format( (int) $item['bytes'], 1 ) ); ?></span>
							</label>
							<?php if ( $item['link'] ) : ?>
								<a href="<?php echo esc_url( (string) $item['link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'view', 'oc-theme' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}

		if ( ! $any ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Nothing to clean — every file in the library is spoken for.', 'oc-theme' ) . '</p></div>';
			return;
		}
		?>

		<div class="ocmc__actions">
			<button type="button" class="button button-primary" id="ocmc-del-orphan">
				<?php esc_html_e( 'Delete only what is attached to nothing', 'oc-theme' ); ?>
			</button>
			<button type="button" class="button" id="ocmc-del-all">
				<?php esc_html_e( 'Delete everything ticked', 'oc-theme' ); ?>
			</button>
			<span class="ocmc__warn"><?php esc_html_e( 'Deleting is permanent — the files leave the server.', 'oc-theme' ); ?></span>
		</div>
		<?php
	}

	/**
	 * The heavy-file finder: everything over a size you choose, biggest
	 * first, with what points at it.
	 */
	private static function heavy_panel(): void {
		?>
		<div class="ocmc__panel" data-ocmc-panel="heavy" hidden>
			<p class="ocmc__note">
				<?php esc_html_e( 'Which pictures and films weigh the most, and which pages they sit on. A heavy file is not a mistake — it is simply what every visitor to that page downloads.', 'oc-theme' ); ?>
			</p>

			<p class="ocmc__controls">
				<label>
					<?php esc_html_e( 'Larger than', 'oc-theme' ); ?>
					<select id="ocmc-min">
						<option value="200">200 KB</option>
						<option value="500" selected>500 KB</option>
						<option value="1024">1 MB</option>
						<option value="2048">2 MB</option>
						<option value="3072">3 MB</option>
						<option value="5120">5 MB</option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Kind', 'oc-theme' ); ?>
					<select id="ocmc-type">
						<option value="all"><?php esc_html_e( 'Everything', 'oc-theme' ); ?></option>
						<option value="image"><?php esc_html_e( 'Pictures', 'oc-theme' ); ?></option>
						<option value="video"><?php esc_html_e( 'Films', 'oc-theme' ); ?></option>
					</select>
				</label>
				<label class="ocmc__url">
					<?php esc_html_e( 'On one page (optional)', 'oc-theme' ); ?>
					<input type="url" id="ocmc-url" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" class="regular-text ltr">
				</label>
				<button type="button" class="button button-primary" id="ocmc-heavy"><?php esc_html_e( 'Find them', 'oc-theme' ); ?></button>
			</p>
			<p class="ocmc__hint"><?php esc_html_e( 'Leave the address empty to search the whole library. Fill it in and only what that page loads is weighed.', 'oc-theme' ); ?></p>

			<div id="ocmc-heavy-out"></div>
		</div>

		<div class="ocmc__float" id="ocmc-float" hidden>
			<b id="ocmc-float-n"></b>
			<button type="button" class="button button-primary" id="ocmc-heavy-shrink"><?php esc_html_e( 'Shrink the ticked ones', 'oc-theme' ); ?></button>
			<button type="button" class="button" id="ocmc-heavy-delete"><?php esc_html_e( 'Delete the ticked ones', 'oc-theme' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Looks.
	 */
	private static function styles(): void {
		?>
		<style>
		.ocmc__lede { max-width: 70ch; color: #50575e; }
		.ocmc__stats { display: flex; flex-wrap: wrap; gap: 10px; margin: 18px 0; }
		.ocmc__stat { flex: 1 1 140px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px 16px; }
		/* "5.4 MB" is a number followed by a Latin unit, which right-to-left
			text reorders into "MB 5.4". Isolating the run keeps it readable. */
		.ocmc__stat b,
		.ocmc__group h2 em,
		.ocmc__item span { direction: ltr; unicode-bidi: isolate; }
		.ocmc__stat b { display: block; font-size: 26px; line-height: 1.1; }
		.ocmc__stat span { color: #646970; font-size: 12px; }
		.ocmc__stat--safe b { color: #2271b1; }
		.ocmc__stat--free b { color: #007017; }
		.ocmc__held { color: #646970; font-style: italic; }
		.ocmc__group { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 6px 18px 18px; margin: 18px 0; }
		.ocmc__group h2 { display: flex; align-items: baseline; gap: 10px; }
		.ocmc__group h2 em { font-size: 13px; font-weight: 400; color: #646970; font-style: normal; }
		.ocmc__note { color: #646970; margin-top: -8px; }
		.ocmc__pick { display: flex; gap: 14px; }
		.ocmc__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; margin: 0; }
		.ocmc__item { margin: 0; position: relative; }
		.ocmc__item label { display: block; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 6px; padding: 8px; cursor: pointer; }
		.ocmc__item img { display: block; inline-size: 100%; block-size: 110px; object-fit: contain; background: #fff; }
		.ocmc__nopic { display: grid; place-items: center; block-size: 110px; background: #fff; color: #a7aaad; font-size: 12px; }
		.ocmc__item b { display: block; font-size: 11px; word-break: break-all; margin-block-start: 6px; font-weight: 500; }
		.ocmc__item span { font-size: 11px; color: #646970; }
		.ocmc__item > a { position: absolute; inset-block-start: 10px; inset-inline-end: 10px; background: #fff; border-radius: 4px; padding: 1px 6px; font-size: 11px; text-decoration: none; }
		.ocmc__cb { float: inline-start; margin-inline-end: 6px; }
		.ocmc__actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 18px 0 40px; }
		.ocmc__warn { color: #b32d2e; font-size: 12px; }
		.ocmc__bar { position: relative; block-size: 26px; background: #f0f0f1; border-radius: 13px; overflow: hidden; max-inline-size: 520px; margin: 14px 0; }
		.ocmc__bar i { position: absolute; inset-block: 0; inset-inline-start: 0; inline-size: 0; background: #2271b1; transition: inline-size .2s; }
		.ocmc__bar span { position: relative; display: grid; place-items: center; block-size: 100%; font-size: 12px; color: #1d2327; }
		.ocmc__heavy { margin-block-start: 44px; border-block-start: 1px solid #dcdcde; padding-block-start: 24px; }
		.ocmc__controls { display: flex; align-items: end; gap: 14px; flex-wrap: wrap; margin: 14px 0 18px; }
		.ocmc__controls label { display: grid; gap: 4px; font-size: 12px; color: #646970; }
		.ocmc__hsum { margin: 0 0 12px; font-size: 13px; }
		.ocmc__htable { inline-size: 100%; border-collapse: collapse; font-size: 13px; }
		.ocmc__htable th { text-align: start; font-size: 12px; color: #646970; font-weight: 600; padding: 6px 10px; border-block-end: 1px solid #dcdcde; }
		.ocmc__htable td { padding: 8px 10px; border-block-end: 1px solid #f0f0f1; vertical-align: top; }
		.ocmc__htable img { inline-size: 56px; block-size: 56px; object-fit: cover; border-radius: 4px; background: #f0f0f1; display: block; }
		.ocmc__hname { word-break: break-all; font-weight: 500; }
		.ocmc__hdim { color: #646970; font-size: 12px; }
		.ocmc__hbytes { white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 600; }
		.ocmc__hused { margin: 0; padding: 0; list-style: none; }
		.ocmc__hused li { margin-block-end: 2px; }
		.ocmc__hused em { color: #996800; font-style: normal; font-size: 11px; }
		.ocmc__hnone { color: #b32d2e; font-size: 12px; }
		.ocmc__hgo { white-space: nowrap; }
		.ocmc__hmsg { display: block; font-size: 11px; color: #646970; margin-block-start: 4px; max-inline-size: 240px; }
		.ocmc__tabs { display: flex; gap: 4px; margin: 18px 0 20px; border-block-end: 1px solid #dcdcde; }
		.ocmc__tab { appearance: none; border: 1px solid transparent; border-block-end: 0; background: none; padding: 10px 18px; font: inherit; font-size: 14px; cursor: pointer; color: #646970; border-radius: 6px 6px 0 0; margin-block-end: -1px; }
		.ocmc__tab.is-on { background: #fff; border-color: #dcdcde; color: #1d2327; font-weight: 600; }
		.ocmc__url { flex: 1 1 340px; }
		.ocmc__url input { inline-size: 100%; }
		.ocmc__htable td:first-child { inline-size: 1%; white-space: nowrap; }
		.ocmc__hcb { margin-inline-end: 8px; }
		.ocmc__used { color: #007017; font-size: 11px; }
		.ocmc__orph { color: #b32d2e; font-size: 11px; }
		.ocmc__hpick { margin: 0 0 10px; display: flex; gap: 14px; align-items: center; }
		.ocmc__hint { margin: -8px 0 18px; font-size: 12px; color: #646970; }
		.ocmc__pic { display: flex; align-items: center; gap: 10px; }
		.ocmc__hcb { margin: 0; flex: 0 0 auto; }
		/* left/transform, not the logical property: the pill is centred on the
		window, and a logical inset flips it off-centre under RTL. */
		.ocmc__float {
			position: fixed; inset-block-end: 24px; left: 50%; transform: translateX(-50%);
			display: flex; align-items: center; gap: 12px;
			background: #1d2327; color: #fff; padding: 10px 14px; border-radius: 999px;
			box-shadow: 0 8px 28px rgba(0,0,0,.28); z-index: 9999;
		}
		.ocmc__float b { font-size: 13px; font-weight: 600; padding-inline-start: 6px; }
		.ocmc__float .button { border-radius: 999px; padding: 2px 16px; }
		</style>
		<?php
	}

	/**
	 * The wiring.
	 *
	 * @param string $nonce   Ajax nonce.
	 * @param bool   $rescan  Start a fresh scan on load, after a deletion.
	 */
	private static function script( string $nonce, bool $rescan = false ): void {
		$strings = array(
			'scanning' => __( 'Scanning…', 'oc-theme' ),
			/* translators: 1: files checked so far, 2: total files to check. */
			'checking' => __( 'Checking file names — %1$d of %2$d', 'oc-theme' ),
			/* translators: 1: files deleted so far, 2: total files to delete. */
			'deleting' => __( 'Deleting — %1$d of %2$d', 'oc-theme' ),
			'none'     => __( 'Nothing is ticked.', 'oc-theme' ),
			/* translators: %d: number of files ticked for deletion. */
			'confirm'  => __( 'Delete %d files for good? This cannot be undone.', 'oc-theme' ),
			/* translators: 1: files deleted, 2: disk space freed, e.g. "5.4 MB", 3: files kept. */
			'done'     => __( 'Done: %1$d deleted, %2$s freed. %3$d were kept because something still uses them.', 'oc-theme' ),
			'failed'   => __( 'Something went wrong. Please try again.', 'oc-theme' ),
			'looking'  => __( 'Looking…', 'oc-theme' ),
			/* translators: 1: number of files, 2: their total size, 3: how many are listed. */
			'hsum'     => __( '%1$d files are over that size, %2$s between them. Showing the largest %3$d.', 'oc-theme' ),
			'hnone'    => __( 'Nothing in the library is that big.', 'oc-theme' ),
			'hfile'    => __( 'File', 'oc-theme' ),
			'hsize'    => __( 'Size', 'oc-theme' ),
			'hwhere'   => __( 'Where it is used', 'oc-theme' ),
			'hact'     => __( 'Action', 'oc-theme' ),
			'hused0'   => __( 'Nothing points at it', 'oc-theme' ),
			'hshrink'  => __( 'Shrink', 'oc-theme' ),
			'hundo'    => __( 'Put the original back', 'oc-theme' ),
			'hworking' => __( 'Working…', 'oc-theme' ),
			/* translators: 1: size before, 2: size after. */
			'hdone'    => __( '%1$s → %2$s', 'oc-theme' ),
			'hkept'    => __( 'Original kept', 'oc-theme' ),
			'hask'     => __( 'Re-save this picture at the size the site actually shows? The address does not change, and the original is kept so this can be undone.', 'oc-theme' ),
			'hpick'    => __( 'Select all', 'oc-theme' ),
			'hclear'   => __( 'Select none', 'oc-theme' ),
			/* translators: %d: number of pictures. */
			'hmany'    => __( 'Shrink %d pictures? Each keeps its original, so this can be undone.', 'oc-theme' ),
			/* translators: 1: files done, 2: files to do. */
			'hprog'    => __( 'Shrinking — %1$d of %2$d', 'oc-theme' ),
			/* translators: %s: disk space, e.g. "5.4 MB". */
			'hsaved'   => __( 'Done. %s saved.', 'oc-theme' ),
			'pnone'    => __( 'That page names no pictures or films we can weigh.', 'oc-theme' ),
			/* translators: 1: number of files, 2: their total size. */
			'psum'     => __( 'This page loads %1$d files, %2$s of pictures and film.', 'oc-theme' ),
			'pnotlib'  => __( 'not in the library', 'oc-theme' ),
			'preading' => __( 'Reading the page…', 'oc-theme' ),
			'inuse'    => __( 'in use', 'oc-theme' ),
			'notused'  => __( 'nothing points at it', 'oc-theme' ),
			/* translators: %d: number of files ticked. */
			'picked'   => __( '%d ticked', 'oc-theme' ),
			/* translators: 1: number of files over the size, 2: their total size. */
			'pover'    => __( '%1$d of them are over the size you asked for, %2$s.', 'oc-theme' ),
		);
		?>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>,
				T     = <?php echo wp_json_encode( $strings ); ?>,
				bar   = document.getElementById( 'ocmc-bar' ),
				out   = document.getElementById( 'ocmc-out' );

			function post( action, data ) {
				var body = new FormData();
				body.append( 'action', action );
				body.append( '_ajax_nonce', nonce );
				Object.keys( data || {} ).forEach( function ( k ) {
					if ( Array.isArray( data[ k ] ) ) {
						data[ k ].forEach( function ( v ) { body.append( k + '[]', v ); } );
					} else {
						body.append( k, data[ k ] );
					}
				} );
				return fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } );
			}

			function progress( pct, text ) {
				bar.hidden = false;
				bar.querySelector( 'i' ).style.inlineSize = Math.max( 2, pct ) + '%';
				bar.querySelector( 'span' ).textContent = text;
			}

			function sprintf( s ) {
				var args = [].slice.call( arguments, 1 ), i = 0;
				return s.replace( /%(\d)\$[ds]|%[ds]/g, function ( m, n ) {
					return n ? args[ n - 1 ] : args[ i++ ];
				} );
			}

			var scan = document.getElementById( 'ocmc-scan' );

			function startScan() {
				if ( scan ) { scan.disabled = true; }
				out.innerHTML = '';
				progress( 4, T.scanning );

				post( 'ocmc_start', {} ).then( function ( res ) {
					if ( ! res || ! res.success ) { throw 0; }
					var total = res.data.total;

					function step() {
						return post( 'ocmc_step', {} ).then( function ( r ) {
							if ( ! r || ! r.success ) { throw 0; }
							var pct = total ? Math.round( ( r.data.done / total ) * 100 ) : 100;
							progress( pct, sprintf( T.checking, r.data.done, total ) );
							return r.data.ready ? null : step();
						} );
					}

					return total ? step() : null;
				} ).then( function () {
					// Keep whatever the page is saying about a deletion, but
					// mark the scan as done so arriving here again does not
					// set another one going.
					var u = new URL( window.location.href );
					u.hash = '';
					u.searchParams.set( 'ocmc_scanned', '1' );
					window.location = u.toString();
				} ).catch( function () {
					if ( scan ) { scan.disabled = false; }
					progress( 100, T.failed );
				} );
			}

			if ( scan ) { scan.addEventListener( 'click', startScan ); }

			<?php if ( $rescan ) : ?>
			startScan();
			<?php endif; ?>

			document.addEventListener( 'click', function ( e ) {
				var pick = e.target.closest( '[data-ocmc-all]' );
				if ( ! pick ) { return; }
				var on = '1' === pick.getAttribute( 'data-ocmc-all' );
				pick.closest( '.ocmc__group' ).querySelectorAll( '.ocmc__cb' ).forEach( function ( cb ) {
					cb.checked = on;
				} );
			} );

			function gather( scope ) {
				var root = scope ? document.querySelector( '[data-ocmc-group="' + scope + '"]' ) : document;
				if ( ! root ) { return []; }
				return [].slice.call( root.querySelectorAll( '.ocmc__cb:checked' ) ).map( function ( cb ) {
					return cb.value;
				} );
			}

			function run( ids ) {
				if ( ! ids.length ) { window.alert( T.none ); return; }
				if ( ! window.confirm( sprintf( T.confirm, ids.length ) ) ) { return; }

				var total = ids.length, done = 0, deleted = 0, kept = 0, bytes = 0;

				function chunk() {
					var slice = ids.slice( done, done + 20 );
					if ( ! slice.length ) {
						// Straight back to a clean page, which re-scans on
						// arrival. The findings on screen are about a library
						// that no longer exists.
						var u = new URL( window.location.href );
						u.hash = '';
						u.searchParams.set( 'ocmc_done', deleted );
						u.searchParams.set( 'ocmc_freed', bytes );
						u.searchParams.set( 'ocmc_kept', kept );
						window.location = u.toString();
						return;
					}
					return post( 'ocmc_delete', { ids: slice } ).then( function ( r ) {
						if ( ! r || ! r.success ) { throw 0; }
						deleted += r.data.deleted;
						kept    += r.data.kept;
						bytes   += r.data.bytes;
						done    += slice.length;
						progress( Math.round( ( done / total ) * 100 ), sprintf( T.deleting, done, total ) );
						return chunk();
					} );
				}

				progress( 2, sprintf( T.deleting, 0, total ) );
				chunk().catch( function () { progress( 100, T.failed ); } );
			}

			var one = document.getElementById( 'ocmc-del-orphan' );
			if ( one ) { one.addEventListener( 'click', function () { run( gather( 'orphan' ) ); } ); }

			var all = document.getElementById( 'ocmc-del-all' );
			if ( all ) { all.addEventListener( 'click', function () { run( gather( null ) ); } ); }

			/* ---------- heavy files ---------- */

			var hOut = document.getElementById( 'ocmc-heavy-out' );
			var hGo  = document.getElementById( 'ocmc-heavy' );

			function esc( v ) {
				var d = document.createElement( 'div' );
				d.textContent = null === v || undefined === v ? '' : String( v );
				return d.innerHTML;
			}

			function kb( n ) {
				if ( n >= 1048576 ) { return ( n / 1048576 ).toFixed( 1 ) + ' MB'; }
				return Math.round( n / 1024 ) + ' KB';
			}

			function usedCell( item ) {
				if ( ! item.used.length ) {
					return '<span class="ocmc__hnone">' + esc( T.hused0 ) + '</span>';
				}
				return '<ul class="ocmc__hused">' + item.used.map( function ( u ) {
					var name = u.link ? '<a href="' + esc( u.link ) + '">' + esc( u.title ) + '</a>' : esc( u.title );
					return '<li>' + name + ( u.state ? ' <em>' + esc( u.state ) + '</em>' : '' ) + '</li>';
				} ).join( '' ) + '</ul>';
			}

			/* ---------- tabs ---------- */

			document.querySelectorAll( '[data-ocmc-tab]' ).forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					var want = tab.getAttribute( 'data-ocmc-tab' );
					document.querySelectorAll( '[data-ocmc-tab]' ).forEach( function ( t ) {
						t.classList.toggle( 'is-on', t === tab );
					} );
					document.querySelectorAll( '[data-ocmc-panel]' ).forEach( function ( p ) {
						p.hidden = p.getAttribute( 'data-ocmc-panel' ) !== want;
					} );
				} );
			} );

			/* ---------- kind filter on the cleanup lists ---------- */

			var kindPick = document.getElementById( 'ocmc-kind-pick' );

			function applyKind() {
				var want = kindPick.value;
				document.querySelectorAll( '.ocmc__item' ).forEach( function ( li ) {
					var mime = li.getAttribute( 'data-ocmc-mime' ) || '';
					var kind = 0 === mime.indexOf( 'image/' ) ? 'image' : ( 0 === mime.indexOf( 'video/' ) ? 'video' : 'other' );
					var show = 'all' === want || kind === want;
					li.hidden = ! show;
					if ( ! show ) {
						var cb = li.querySelector( '.ocmc__cb' );
						if ( cb ) { cb.checked = false; }
					}
				} );
			}

			if ( kindPick ) { kindPick.addEventListener( 'change', applyKind ); }

			function showKind() {
				var box = document.getElementById( 'ocmc-kind' );
				if ( box ) { box.hidden = ! document.querySelector( '.ocmc__item' ); }
			}

			showKind();

			/* ---------- shared: shrink a list of ids, one at a time ---------- */

			function shrinkMany( ids, done ) {
				var at = 0, saved = 0;
				function next() {
					if ( at >= ids.length ) {
						progress( 100, sprintf( T.hsaved, kb( saved ) ) );
						if ( done ) { done(); }
						return;
					}
					progress( Math.round( 100 * at / ids.length ), sprintf( T.hprog, at, ids.length ) );
					var id = ids[ at++ ];
					post( 'ocmc_shrink', { id: id } ).then( function ( r ) {
						var d = r && r.data ? r.data : {};
						var row = document.querySelector( '[data-ocmc-row="' + id + '"]' );
						if ( row ) {
							var msg = row.querySelector( '[data-ocmc-msg]' );
							var cell = row.querySelector( '[data-ocmc-size]' );
							if ( d.ok ) {
								saved += d.before - d.after;
								msg.textContent = sprintf( T.hdone, kb( d.before ), kb( d.after ) );
								cell.textContent = kb( d.after );
							} else {
								msg.textContent = d.why || T.failed;
							}
						}
						next();
					} ).catch( next );
				}
				next();
			}

			function floatBar() {
				var bar = document.getElementById( 'ocmc-float' );
				var n   = document.querySelectorAll( '.ocmc__hcb:checked' ).length;
				bar.hidden = 0 === n;
				document.getElementById( 'ocmc-float-n' ).textContent = sprintf( T.picked, n );
			}

			document.addEventListener( 'change', function ( e ) {
				if ( e.target.classList && e.target.classList.contains( 'ocmc__hcb' ) ) { floatBar(); }
			} );

			function ticked( root ) {
				return [].slice.call( root.querySelectorAll( '.ocmc__hcb:checked' ) ).map( function ( cb ) {
					return cb.value;
				} );
			}

			function pickRow( root ) {
				return '<p class="ocmc__hpick"><button type="button" class="button-link" data-ocmc-hall="1">'
					+ esc( T.hpick ) + '</button><button type="button" class="button-link" data-ocmc-hall="0">'
					+ esc( T.hclear ) + '</button></p>';
			}

			document.addEventListener( 'click', function ( e ) {
				var all = e.target.closest( '[data-ocmc-hall]' );
				if ( ! all ) { return; }
				var on = '1' === all.getAttribute( 'data-ocmc-hall' );
				all.closest( '[data-ocmc-panel]' ).querySelectorAll( '.ocmc__hcb' ).forEach( function ( cb ) {
					if ( ! cb.closest( 'tr' ).hidden ) { cb.checked = on; }
				} );
				floatBar();
			} );

			function drawHeavy( d ) {
				if ( ! d.items.length ) {
					hOut.innerHTML = '<p class="ocmc__hsum">' + esc( T.hnone ) + '</p>';
					return;
				}
				var rows = d.items.map( function ( it ) {
					var pic = it.thumb ? '<img src="' + esc( it.thumb ) + '" alt="" loading="lazy">' : '<img alt="">';
					var dim = it.w && it.h ? it.w + '×' + it.h : it.mime.replace( /^.*\// , '' );
					var act = it.shrink
						? '<button type="button" class="button ocmc__hgo" data-ocmc-shrink="' + it.id + '">' + esc( T.hshrink ) + '</button>'
						: '';
					if ( it.backup ) {
						act += ' <button type="button" class="button-link ocmc__hgo" data-ocmc-undo="' + it.id + '">' + esc( T.hundo ) + '</button>';
					}
					var cb = it.id ? '<input type="checkbox" class="ocmc__hcb" value="' + it.id + '">' : '';
					return '<tr data-ocmc-row="' + it.id + '" data-ocmc-mime="' + esc( it.mime ) + '">'
						+ '<td><span class="ocmc__pic">' + cb + pic + '</span></td>'
						+ '<td><span class="ocmc__hname">' + ( it.link ? '<a href="' + esc( it.link ) + '">' + esc( it.name ) + '</a>' : esc( it.name ) ) + '</span>'
						+ '<br><span class="ocmc__hdim">' + esc( dim ) + ' · ' + esc( it.mime ) + '</span></td>'
						+ '<td class="ocmc__hbytes" data-ocmc-size>' + esc( kb( it.bytes ) ) + '</td>'
						+ '<td>' + usedCell( it ) + '</td>'
						+ '<td>' + act + '<span class="ocmc__hmsg" data-ocmc-msg></span></td>'
						+ '</tr>';
				} ).join( '' );

				var sum = d.page
					? sprintf( T.psum, d.scanned, kb( d.total ) ) + ' ' + sprintf( T.pover, d.over, kb( d.bytes ) )
					: sprintf( T.hsum, d.over, kb( d.bytes ), d.shown );

				hOut.innerHTML = '<p class="ocmc__hsum">' + esc( sum ) + '</p>'
					+ pickRow()
					+ '<table class="ocmc__htable"><thead><tr><th></th><th>' + esc( T.hfile ) + '</th><th>'
					+ esc( T.hsize ) + '</th><th>' + esc( T.hwhere ) + '</th><th>' + esc( T.hact )
					+ '</th></tr></thead><tbody>' + rows + '</tbody></table>';
				floatBar();
			}

			if ( hGo ) {
				hGo.addEventListener( 'click', function () {
					hGo.disabled = true;
					hOut.innerHTML = '<p class="ocmc__hsum">' + esc( T.looking ) + '</p>';
					post( 'ocmc_heavy', {
						min: document.getElementById( 'ocmc-min' ).value,
						type: document.getElementById( 'ocmc-type' ).value,
						url: document.getElementById( 'ocmc-url' ).value
					} ).then( function ( r ) {
						hGo.disabled = false;
						if ( r && r.success && ! r.data.why ) { drawHeavy( r.data ); }
						else { hOut.innerHTML = '<p class="ocmc__hsum">' + esc( ( r && r.data && r.data.why ) || T.failed ) + '</p>'; floatBar(); }
					} ).catch( function () {
						hGo.disabled = false;
						hOut.innerHTML = '<p class="ocmc__hsum">' + esc( T.failed ) + '</p>';
					} );
				} );
			}

			[ 'ocmc-heavy-shrink' ].forEach( function ( id ) {
				var b = document.getElementById( id );
				if ( ! b ) { return; }
				b.addEventListener( 'click', function () {
					var ids = ticked( document );
					if ( ! ids.length ) { window.alert( T.none ); return; }
					if ( ! window.confirm( sprintf( T.hmany, ids.length ) ) ) { return; }
					b.disabled = true;
					shrinkMany( ids, function () { b.disabled = false; floatBar(); } );
				} );
			} );

			var hDel = document.getElementById( 'ocmc-heavy-delete' );

			if ( hDel ) {
				hDel.addEventListener( 'click', function () {
					run( ticked( document ) );
				} );
			}

			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '[data-ocmc-shrink], [data-ocmc-undo]' );
				if ( ! btn ) { return; }

				var undo = btn.hasAttribute( 'data-ocmc-undo' );
				var id   = btn.getAttribute( undo ? 'data-ocmc-undo' : 'data-ocmc-shrink' );
				var row  = btn.closest( '[data-ocmc-row]' );
				var msg  = row.querySelector( '[data-ocmc-msg]' );
				var cell = row.querySelector( '[data-ocmc-size]' );

				if ( ! undo && ! window.confirm( T.hask ) ) { return; }

				btn.disabled = true;
				msg.textContent = T.hworking;

				post( undo ? 'ocmc_restore' : 'ocmc_shrink', { id: id } ).then( function ( r ) {
					btn.disabled = false;
					var d = r && r.data ? r.data : {};
					if ( d.ok ) {
						if ( undo ) {
							msg.textContent = T.hkept;
							cell.textContent = kb( d.after );
							btn.remove();
						} else {
							msg.textContent = sprintf( T.hdone, kb( d.before ), kb( d.after ) );
							cell.textContent = kb( d.after );
						}
					} else {
						msg.textContent = d.why || T.failed;
					}
				} ).catch( function () {
					btn.disabled = false;
					msg.textContent = T.failed;
				} );
			} );
		}() );
		</script>
		<?php
	}
}
