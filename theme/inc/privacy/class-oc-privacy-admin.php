<?php
/**
 * Settings ← Privacy: one screen, and the site is covered.
 *
 * The mode, the words, the categories, snippets that must wait for a
 * yes, the policy page (written for the site in one click), the consent
 * log with its export, and the button that asks everyone again.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * The admin screen.
 */
final class Admin {

	const NONCE = 'ocprv';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_ocprv_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ocprv_page', array( $this, 'handle_page' ) );
		add_action( 'admin_post_ocprv_reask', array( $this, 'handle_reask' ) );
		add_action( 'admin_post_ocprv_csv', array( $this, 'handle_csv' ) );
		add_action( 'admin_post_ocprv_purge', array( $this, 'handle_purge' ) );
	}

	/**
	 * The screen's address.
	 */
	public static function url(): string {
		return admin_url( 'options-general.php?page=oc-privacy' );
	}

	/**
	 * Under Settings.
	 */
	public function menu(): void {
		add_options_page( __( 'Privacy', 'oc-theme' ), __( 'Privacy', 'oc-theme' ), 'manage_options', 'oc-privacy', array( $this, 'render' ) );
	}

	/**
	 * The screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$s      = Settings::get();
		$d      = Settings::defaults();
		$cd     = Settings::cat_defaults();
		$msg    = isset( $_GET['ocprv_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ocprv_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice, nothing acted on.
		$policy = Settings::policy_page_id();
		$mkt    = class_exists( '\OC\Theme\Marketing\Settings' ) ? \OC\Theme\Marketing\Settings::get() : null;

		$text = static function ( string $name, string $value, string $label, string $placeholder, string $hint = '' ): void {
			echo '<label><span>' . esc_html( $label ) . '</span><input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" />';
			if ( '' !== $hint ) {
				echo '<small>' . esc_html( $hint ) . '</small>';
			}
			echo '</label>';
		};

		echo '<div class="wrap ocprv"><h1>' . esc_html__( 'Privacy', 'oc-theme' ) . '</h1>';

		if ( '' !== $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ocprv_save" />
			<?php wp_nonce_field( self::NONCE ); ?>

			<div class="ocprv-card ocprv-card--switch">
				<label class="ocprv-switch"><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?> /><strong><?php esc_html_e( 'Consent layer is on', 'oc-theme' ); ?></strong></label>
				<p class="description"><?php esc_html_e( 'Every visitor sees a short, honest notice with equal buttons to accept or decline, can change their mind at any time from the footer, and their choice is recorded. The marketing tags, Google Consent Mode and any snippet you park below all follow that choice.', 'oc-theme' ); ?></p>
				<div class="ocprv-grid">
					<label>
						<span><?php esc_html_e( 'How consent starts', 'oc-theme' ); ?></span>
						<select name="mode">
							<option value="auto" <?php selected( 'auto', $s['mode'] ); ?>><?php esc_html_e( 'By region — opt-in in the EU/UK/Switzerland, opt-out elsewhere (recommended)', 'oc-theme' ); ?></option>
							<option value="optout" <?php selected( 'optout', $s['mode'] ); ?>><?php esc_html_e( 'Opt-out everywhere — tags run until the visitor declines', 'oc-theme' ); ?></option>
							<option value="optin" <?php selected( 'optin', $s['mode'] ); ?>><?php esc_html_e( 'Opt-in everywhere — nothing non-essential runs until accepted', 'oc-theme' ); ?></option>
						</select>
						<small><?php esc_html_e( 'Israeli law requires an informed notice and a real way to refuse; EU law requires a yes before anything runs. "By region" gives each visitor what their law asks.', 'oc-theme' ); ?></small>
					</label>
					<label>
						<span><?php esc_html_e( 'Banner', 'oc-theme' ); ?></span>
						<select name="layout">
							<option value="card" <?php selected( 'card', $s['layout'] ); ?>><?php esc_html_e( 'A small card in a corner', 'oc-theme' ); ?></option>
							<option value="bar" <?php selected( 'bar', $s['layout'] ); ?>><?php esc_html_e( 'A bar along the bottom', 'oc-theme' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Card position', 'oc-theme' ); ?></span>
						<select name="position">
							<option value="start" <?php selected( 'start', $s['position'] ); ?>><?php esc_html_e( 'Start side (right in Hebrew)', 'oc-theme' ); ?></option>
							<option value="end" <?php selected( 'end', $s['position'] ); ?>><?php esc_html_e( 'End side', 'oc-theme' ); ?></option>
							<option value="center" <?php selected( 'center', $s['position'] ); ?>><?php esc_html_e( 'Centre', 'oc-theme' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Reopening the choice', 'oc-theme' ); ?></span>
						<select name="reopen">
							<option value="badge" <?php selected( 'badge', $s['reopen'] ); ?>><?php esc_html_e( 'A small floating badge', 'oc-theme' ); ?></option>
							<option value="link" <?php selected( 'link', $s['reopen'] ); ?>><?php esc_html_e( 'Footer link only', 'oc-theme' ); ?></option>
							<option value="none" <?php selected( 'none', $s['reopen'] ); ?>><?php esc_html_e( 'Neither (a menu link to #privacy-settings still works)', 'oc-theme' ); ?></option>
						</select>
						<small><?php esc_html_e( 'The law asks that withdrawing consent is as easy as giving it.', 'oc-theme' ); ?></small>
					</label>
					<label>
						<span><?php esc_html_e( 'Badge position', 'oc-theme' ); ?></span>
						<select name="badge_pos">
							<option value="start" <?php selected( 'start', $s['badge_pos'] ); ?>><?php esc_html_e( 'Start side (right in Hebrew)', 'oc-theme' ); ?></option>
							<option value="end" <?php selected( 'end', $s['badge_pos'] ); ?>><?php esc_html_e( 'End side', 'oc-theme' ); ?></option>
							<option value="center" <?php selected( 'center', $s['badge_pos'] ); ?>><?php esc_html_e( 'Centre', 'oc-theme' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Badge distance from the bottom (px)', 'oc-theme' ); ?></span>
						<input type="number" name="badge_gap" min="0" max="200" value="<?php echo esc_attr( (string) $s['badge_gap'] ); ?>" />
						<small><?php esc_html_e( 'Raise it above a sticky bar or a chat button.', 'oc-theme' ); ?></small>
					</label>
				</div>
				<label class="ocprv-check"><input type="checkbox" name="footer" value="1" <?php checked( $s['footer'] ); ?> /><span><?php esc_html_e( 'Add a "Privacy settings" link to the footer', 'oc-theme' ); ?></span></label>
				<label class="ocprv-check"><input type="checkbox" name="gpc" value="1" <?php checked( $s['gpc'] ); ?> /><span><?php esc_html_e( 'Honour the browser\'s Global Privacy Control signal (start with marketing off)', 'oc-theme' ); ?></span></label>
				<label class="ocprv-check"><input type="checkbox" name="nocookie" value="1" <?php checked( $s['nocookie'] ); ?> /><span><?php esc_html_e( 'YouTube embeds use the no-cookie player', 'oc-theme' ); ?></span></label>
				<label class="ocprv-check"><input type="checkbox" name="google" value="1" <?php checked( $s['google'] ); ?> /><span><?php esc_html_e( 'Advanced Consent Mode: load Google tags before consent, in denied state (cookieless pings)', 'oc-theme' ); ?></span></label>
				<p class="description"><?php esc_html_e( 'Off, Google tags load only after a yes (the safest reading of EU law). On, they load at once but store nothing until allowed — Google\'s recommended setup, which lets it model the conversions it cannot see.', 'oc-theme' ); ?></p>
			</div>

			<div class="ocprv-card">
				<h2><?php esc_html_e( 'Words', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Leave a field empty to use the built-in text in the site\'s language (Hebrew on a Hebrew site, English otherwise).', 'oc-theme' ); ?></p>
				<div class="ocprv-grid">
					<?php $text( 'texts[title]', $s['texts']['title'], __( 'Title', 'oc-theme' ), $d['title'] ); ?>
					<?php $text( 'texts[accept]', $s['texts']['accept'], __( 'Accept button', 'oc-theme' ), $d['accept'] ); ?>
					<?php $text( 'texts[reject]', $s['texts']['reject'], __( 'Decline button', 'oc-theme' ), $d['reject'] ); ?>
					<?php $text( 'texts[manage]', $s['texts']['manage'], __( 'Choose button', 'oc-theme' ), $d['manage'] ); ?>
					<?php $text( 'texts[save]', $s['texts']['save'], __( 'Save button', 'oc-theme' ), $d['save'] ); ?>
					<?php $text( 'texts[policy]', $s['texts']['policy'], __( 'Policy link', 'oc-theme' ), $d['policy'] ); ?>
					<?php $text( 'texts[badge]', $s['texts']['badge'], __( 'Badge / footer link', 'oc-theme' ), $d['badge'] ); ?>
				</div>
				<div class="ocprv-grid">
					<label>
						<span><?php esc_html_e( 'Accept button colour', 'oc-theme' ); ?></span>
						<span class="ocprv-color"><input type="color" value="<?php echo esc_attr( '' !== $s['accent'] ? $s['accent'] : '#2f4e7a' ); ?>" data-ocprv-pick /><input type="text" name="accent" class="ltr" value="<?php echo esc_attr( $s['accent'] ); ?>" placeholder="#" data-ocprv-hex /><button type="button" class="button-link" data-ocprv-clear><?php esc_html_e( 'Theme colour', 'oc-theme' ); ?></button></span>
						<small><?php esc_html_e( 'Empty = the theme\'s call-to-action colour (Customizer → Design); when that is empty too, the primary colour.', 'oc-theme' ); ?></small>
					</label>
					<label>
						<span><?php esc_html_e( 'Accept button text colour', 'oc-theme' ); ?></span>
						<span class="ocprv-color"><input type="color" value="<?php echo esc_attr( '' !== $s['accent_tx'] ? $s['accent_tx'] : '#ffffff' ); ?>" data-ocprv-pick /><input type="text" name="accent_tx" class="ltr" value="<?php echo esc_attr( $s['accent_tx'] ); ?>" placeholder="#" data-ocprv-hex /><button type="button" class="button-link" data-ocprv-clear><?php esc_html_e( 'White', 'oc-theme' ); ?></button></span>
					</label>
				</div>
				<label class="ocprv-wide"><span><?php esc_html_e( 'Message', 'oc-theme' ); ?></span><textarea name="texts[text]" rows="3" placeholder="<?php echo esc_attr( $d['text'] ); ?>"><?php echo esc_textarea( $s['texts']['text'] ); ?></textarea></label>
			</div>

			<div class="ocprv-card">
				<h2><?php esc_html_e( 'Categories', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( '"Necessary" is always on and is not a choice. Switch off a category the site never uses and it disappears from the panel.', 'oc-theme' ); ?></p>
				<?php foreach ( Settings::CATEGORIES as $cat ) : ?>
					<div class="ocprv-cat">
						<label class="ocprv-check"><input type="checkbox" name="cats[<?php echo esc_attr( $cat ); ?>][on]" value="1" <?php checked( $s['cats'][ $cat ]['on'] ); ?> /><strong><?php echo esc_html( $cd[ $cat ]['label'] ); ?></strong></label>
						<div class="ocprv-grid">
							<?php $text( 'cats[' . $cat . '][label]', $s['cats'][ $cat ]['label'], __( 'Label', 'oc-theme' ), $cd[ $cat ]['label'] ); ?>
							<?php $text( 'cats[' . $cat . '][desc]', $s['cats'][ $cat ]['desc'], __( 'Description', 'oc-theme' ), $cd[ $cat ]['desc'] ); ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="ocprv-card">
				<h2><?php esc_html_e( 'What already follows the choice', 'oc-theme' ); ?></h2>
				<ul class="ocprv-list">
					<li><?php esc_html_e( 'Google Analytics, Google Ads and Tag Manager — through Consent Mode v2.', 'oc-theme' ); ?> <?php echo $mkt && ( '' !== $mkt['ga4']['id'] || '' !== $mkt['gads']['id'] || '' !== $mkt['gtm']['id'] ) ? '<span class="ocprv-on">' . esc_html__( 'configured', 'oc-theme' ) . '</span>' : '<span class="ocprv-off">' . esc_html__( 'not configured', 'oc-theme' ) . '</span>'; ?></li>
					<li><?php esc_html_e( 'Meta pixel and TikTok pixel — loaded only with marketing consent; the Conversions APIs follow the same flag.', 'oc-theme' ); ?> <?php echo $mkt && ( '' !== $mkt['fb']['pixel'] || '' !== $mkt['tiktok']['pixel'] ) ? '<span class="ocprv-on">' . esc_html__( 'configured', 'oc-theme' ) . '</span>' : '<span class="ocprv-off">' . esc_html__( 'not configured', 'oc-theme' ) . '</span>'; ?></li>
					<li><?php esc_html_e( 'Flashy — held back until marketing consent when its deferral is on in Settings → Marketing.', 'oc-theme' ); ?></li>
					<li><?php esc_html_e( 'WooCommerce cart and session cookies, sign-in — necessary, always on.', 'oc-theme' ); ?></li>
				</ul>
				<p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: link to the marketing screen. */ __( 'The IDs themselves live in %s.', 'oc-theme' ), '<a href="' . esc_url( admin_url( 'options-general.php?page=oc-marketing' ) ) . '">' . esc_html__( 'Settings → Marketing', 'oc-theme' ) . '</a>' ) ); ?></p>
			</div>

			<div class="ocprv-card">
				<h2><?php esc_html_e( 'Other tags that must wait for a yes', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Paste any tag a vendor gave you (a chat widget, a heatmap, another pixel), pick its category, and it will not load until the visitor allows that category. A "necessary" tag prints at once. The name appears in the policy\'s cookie table.', 'oc-theme' ); ?></p>
				<div class="ocprv-scripts" data-ocprv-scripts>
					<?php
					$rows   = $s['scripts'];
					$rows[] = array(
						'name'  => '',
						'cat'   => 'marketing',
						'where' => 'footer',
						'code'  => '',
					);
					foreach ( $rows as $i => $row ) :
						?>
						<div class="ocprv-script">
							<div class="ocprv-grid ocprv-grid--3">
								<label><span><?php esc_html_e( 'Name', 'oc-theme' ); ?></span><input type="text" name="scripts[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $row['name'] ); ?>" placeholder="Hotjar" /></label>
								<label><span><?php esc_html_e( 'Category', 'oc-theme' ); ?></span>
									<select name="scripts[<?php echo (int) $i; ?>][cat]">
										<option value="necessary" <?php selected( 'necessary', $row['cat'] ); ?>><?php echo esc_html( $cd['necessary']['label'] ); ?></option>
										<?php foreach ( Settings::CATEGORIES as $cat ) : ?>
											<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $cat, $row['cat'] ); ?>><?php echo esc_html( $cd[ $cat ]['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<label><span><?php esc_html_e( 'Where', 'oc-theme' ); ?></span>
									<select name="scripts[<?php echo (int) $i; ?>][where]">
										<option value="footer" <?php selected( 'footer', $row['where'] ); ?>><?php esc_html_e( 'Footer', 'oc-theme' ); ?></option>
										<option value="head" <?php selected( 'head', $row['where'] ); ?>><?php esc_html_e( 'Head', 'oc-theme' ); ?></option>
									</select>
								</label>
							</div>
							<textarea name="scripts[<?php echo (int) $i; ?>][code]" rows="4" class="ltr code" placeholder="<script>…</script>"><?php echo esc_textarea( $row['code'] ); ?></textarea>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Leave the last one empty. To remove a tag, clear its code and save.', 'oc-theme' ); ?></p>
			</div>

			<div class="ocprv-card">
				<h2><?php esc_html_e( 'Consent log', 'oc-theme' ); ?></h2>
				<label class="ocprv-check"><input type="checkbox" name="log" value="1" <?php checked( $s['log'] ); ?> /><span><?php esc_html_e( 'Record every answer (id, time, choices, region, policy version — no names, no full addresses)', 'oc-theme' ); ?></span></label>
				<div class="ocprv-grid">
					<?php $text( 'log_days', (string) $s['log_days'], __( 'Keep records for (days)', 'oc-theme' ), '400' ); ?>
				</div>
			</div>

			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'oc-theme' ); ?></button></p>
		</form>

		<div class="ocprv-card">
			<h2><?php esc_html_e( 'Privacy policy page', 'oc-theme' ); ?></h2>
			<?php if ( $policy > 0 ) : ?>
				<p><?php echo wp_kses_post( sprintf( /* translators: 1: page title link. */ __( 'The banner links to %1$s. Every site form and the checkout link there too.', 'oc-theme' ), '<a href="' . esc_url( (string) get_permalink( $policy ) ) . '" target="_blank" rel="noopener">' . esc_html( (string) get_the_title( $policy ) ) . '</a>' ) ); ?> <a href="<?php echo esc_url( (string) get_edit_post_link( $policy ) ); ?>"><?php esc_html_e( 'Edit', 'oc-theme' ); ?></a></p>
			<?php else : ?>
				<p class="ocprv-warn"><?php esc_html_e( 'No privacy policy page is set. The banner shows without a link until there is one.', 'oc-theme' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ocprv-inline">
				<input type="hidden" name="action" value="ocprv_page" />
				<?php wp_nonce_field( self::NONCE ); ?>
				<button type="submit" class="button"><?php echo (int) get_option( 'oc_privacy_page', 0 ) > 0 ? esc_html__( 'Rewrite the generated policy page', 'oc-theme' ) : esc_html__( 'Write a privacy policy page for this site', 'oc-theme' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'A complete policy in the site\'s language with the shop\'s name and contact details filled in, the live cookie table, and a form for data requests. It covers the Israeli Protection of Privacy Law (including the Amendment 13 notice at collection) and the GDPR for European visitors. Read it once before launch — a lawyer should confirm anything specific to your business.', 'oc-theme' ); ?></p>
			<p class="description"><code>[oc_cookie_table]</code> — <?php esc_html_e( 'the live list of cookies and services, for any page.', 'oc-theme' ); ?> <code>[oc_privacy_request]</code> — <?php esc_html_e( 'a form to request a copy or deletion of personal data; requests arrive under Tools.', 'oc-theme' ); ?></p>
		</div>

		<div class="ocprv-card">
			<h2><?php esc_html_e( 'Ask everyone again', 'oc-theme' ); ?></h2>
			<p class="description"><?php esc_html_e( 'After a material change to the policy or the tags, every visitor should choose again. Editing the policy page does this by itself; this button does it without an edit.', 'oc-theme' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ocprv-inline">
				<input type="hidden" name="action" value="ocprv_reask" />
				<?php wp_nonce_field( self::NONCE ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Ask all visitors again', 'oc-theme' ); ?></button>
				<span class="description"><?php echo esc_html( sprintf( /* translators: %s: version stamp. */ __( 'Current consent version: %s', 'oc-theme' ), Settings::policy_version() ) ); ?></span>
			</form>
		</div>

		<div class="ocprv-card">
			<h2><?php esc_html_e( 'Recorded answers', 'oc-theme' ); ?> <span class="ocprv-count"><?php echo (int) Log::count(); ?></span></h2>
			<?php $rows = Log::recent( 30 ); ?>
			<?php if ( $rows ) : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'When', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Preferences', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Statistics', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Marketing', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Mode', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Region', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Version', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Id', 'oc-theme' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'j.n.Y H:i', (int) strtotime( (string) $r['given_at'] . ' UTC' ) ) ); ?></td>
							<td><?php echo $r['prefs'] ? '✓' : '—'; ?></td>
							<td><?php echo $r['analytics'] ? '✓' : '—'; ?></td>
							<td><?php echo $r['marketing'] ? '✓' : '—'; ?></td>
							<td><?php echo esc_html( (string) $r['mode'] ); ?></td>
							<td><?php echo esc_html( (string) $r['region'] ); ?></td>
							<td class="ltr"><?php echo esc_html( (string) $r['policy'] ); ?></td>
							<td class="ltr"><code><?php echo esc_html( substr( (string) $r['consent_id'], 0, 13 ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="ocprv-inline">
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocprv_csv' ), self::NONCE ) ); ?>"><?php esc_html_e( 'Download everything as CSV', 'oc-theme' ); ?></a>
					<a class="button ocprv-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ocprv_purge' ), self::NONCE ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete every recorded answer?', 'oc-theme' ) ); ?>');"><?php esc_html_e( 'Empty the log', 'oc-theme' ); ?></a>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'Nothing recorded yet.', 'oc-theme' ); ?></p>
			<?php endif; ?>
			<p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: link. */ __( 'Requests for a copy or deletion of personal data arrive under %s.', 'oc-theme' ), '<a href="' . esc_url( admin_url( 'export-personal-data.php' ) ) . '">' . esc_html__( 'Tools → Export / Erase personal data', 'oc-theme' ) . '</a>' ) ); ?></p>
		</div>

		<style>
			.ocprv .ltr { direction: ltr; text-align: left; }
			.ocprv-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 18px 20px; margin: 16px 0; max-width: 1000px; }
			.ocprv-card h2 { margin: 0 0 10px; font-size: 1.1em; }
			.ocprv-card--switch { border-color: #2271b1; }
			.ocprv-switch { display: flex; align-items: center; gap: 10px; font-size: 1.05em; }
			.ocprv-warn { color: #8a4b00; background: #fcf9e8; border-inline-start: 3px solid #dba617; padding: 8px 10px; }
			.ocprv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px 18px; margin: 10px 0; }
			.ocprv-grid--3 { grid-template-columns: 2fr 1fr 1fr; }
			.ocprv-grid label > span, .ocprv-wide > span { display: block; font-weight: 600; margin-bottom: 4px; }
			.ocprv-grid label input[type="text"], .ocprv-grid label select, .ocprv-wide textarea, .ocprv-script textarea { width: 100%; }
			.ocprv-grid small { display: block; color: #646970; margin-top: 3px; }
			.ocprv-check { display: flex; align-items: center; gap: 8px; margin: 6px 0; }
			.ocprv-cat { border-top: 1px solid #f0f0f1; padding-top: 8px; margin-top: 8px; }
			.ocprv-script { border: 1px dashed #dcdcde; border-radius: 6px; padding: 10px 12px; margin: 8px 0; }
			.ocprv-script textarea { font-family: monospace; font-size: 12px; }
			.ocprv-list { list-style: disc; padding-inline-start: 20px; }
			.ocprv-on { color: #00794b; font-weight: 600; } .ocprv-off { color: #646970; }
			.ocprv-inline { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
			.ocprv-danger { color: #b32d2e; border-color: #b32d2e; }
			.ocprv-count { font-weight: 400; color: #646970; font-size: .9em; }
			.ocprv code { background: none; padding: 0; font-size: 12px; }
			.ocprv-color { display: flex; align-items: center; gap: 8px; }
			.ocprv-color input[type="color"] { inline-size: 40px; block-size: 32px; padding: 2px; border: 1px solid #dcdcde; border-radius: 4px; background: #fff; }
			.ocprv-color input[type="text"] { flex: 1; }
		</style>
		<script>
		( function () {
			// The swatch and the hex field are one control: either writes
			// the other; "theme colour" empties both.
			document.querySelectorAll( '.ocprv-color' ).forEach( function ( box ) {
				var pick = box.querySelector( '[data-ocprv-pick]' );
				var hex = box.querySelector( '[data-ocprv-hex]' );
				pick.addEventListener( 'input', function () { hex.value = pick.value; } );
				hex.addEventListener( 'input', function () { if ( /^#[0-9a-f]{6}$/i.test( hex.value ) ) { pick.value = hex.value; } } );
				box.querySelector( '[data-ocprv-clear]' ).addEventListener( 'click', function () { hex.value = ''; } );
			} );
		}() );
		</script>
		</div>
		<?php
	}

	/**
	 * Everything the guarded actions need first.
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( self::NONCE );
	}

	/**
	 * Back to the screen with a word.
	 *
	 * @param string $msg Notice.
	 */
	private function back( string $msg ): void {
		wp_safe_redirect( add_query_arg( 'ocprv_msg', rawurlencode( $msg ), self::url() ) );
		exit;
	}

	/**
	 * Save.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- check_admin_referer() ran; every field is unslashed and sanitized here or typed and bounded in Settings::normalize().
		$scripts = array();

		foreach ( (array) ( $_POST['scripts'] ?? array() ) as $row ) {
			$row       = (array) $row;
			$scripts[] = array(
				'name'  => sanitize_text_field( (string) wp_unslash( $row['name'] ?? '' ) ),
				'cat'   => sanitize_key( (string) wp_unslash( $row['cat'] ?? '' ) ),
				'where' => sanitize_key( (string) wp_unslash( $row['where'] ?? '' ) ),
				// A vendor's tag, as pasted: only an administrator reaches
				// this screen, the same trust WordPress gives a widget.
				'code'  => (string) wp_unslash( $row['code'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- see above.
			);
		}

		$was = Settings::get();

		$raw = array(
			'enabled'   => ! empty( $_POST['enabled'] ),
			'mode'      => sanitize_key( (string) wp_unslash( $_POST['mode'] ?? 'auto' ) ),
			'layout'    => sanitize_key( (string) wp_unslash( $_POST['layout'] ?? 'card' ) ),
			'position'  => sanitize_key( (string) wp_unslash( $_POST['position'] ?? 'start' ) ),
			'reopen'    => sanitize_key( (string) wp_unslash( $_POST['reopen'] ?? 'none' ) ),
			'badge_pos' => sanitize_key( (string) wp_unslash( $_POST['badge_pos'] ?? 'start' ) ),
			'badge_gap' => absint( wp_unslash( $_POST['badge_gap'] ?? 14 ) ),
			'footer'    => ! empty( $_POST['footer'] ),
			'gpc'       => ! empty( $_POST['gpc'] ),
			'nocookie'  => ! empty( $_POST['nocookie'] ),
			'google'    => ! empty( $_POST['google'] ),
			'log'       => ! empty( $_POST['log'] ),
			'log_days'  => absint( wp_unslash( $_POST['log_days'] ?? 400 ) ),
			'reconsent' => $was['reconsent'],
			'policy'    => $was['policy'],
			'texts'     => array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['texts'] ?? array() ) ) ),
			'accent'    => sanitize_text_field( (string) wp_unslash( $_POST['accent'] ?? '' ) ),
			'accent_tx' => sanitize_text_field( (string) wp_unslash( $_POST['accent_tx'] ?? '' ) ),
			'cats'      => array(),
			'scripts'   => $scripts,
		);

		foreach ( Settings::CATEGORIES as $cat ) {
			$one                 = (array) ( $_POST['cats'][ $cat ] ?? array() );
			$raw['cats'][ $cat ] = array(
				'on'    => ! empty( $one['on'] ),
				'label' => sanitize_text_field( (string) wp_unslash( $one['label'] ?? '' ) ),
				'desc'  => sanitize_text_field( (string) wp_unslash( $one['desc'] ?? '' ) ),
			);
		}

		// phpcs:enable

		Settings::save( $raw );
		$this->flush();
		$this->back( __( 'Saved.', 'oc-theme' ) );
	}

	/**
	 * Writes (or rewrites) the policy page.
	 */
	public function handle_page(): void {
		$this->guard();

		$id = Policy::create_page();
		$this->flush();
		$this->back( $id > 0 ? __( 'The policy page is written and set as the site\'s privacy page. Read it once before launch.', 'oc-theme' ) : __( 'The page could not be written.', 'oc-theme' ) );
	}

	/**
	 * Bumps the consent version.
	 */
	public function handle_reask(): void {
		$this->guard();

		$s = Settings::get();
		++$s['reconsent'];
		Settings::save( $s );
		$this->flush();
		$this->back( __( 'Every visitor will be asked again on their next visit.', 'oc-theme' ) );
	}

	/**
	 * The whole log, as a file.
	 */
	public function handle_csv(): void {
		$this->guard();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="consent-log-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming a download.
		fputcsv( $out, array( 'id', 'consent_id', 'given_at_utc', 'preferences', 'analytics', 'marketing', 'mode', 'region', 'policy_version', 'network', 'agent_hash', 'lang' ) );

		foreach ( Log::all() as $r ) {
			fputcsv( $out, array( $r['id'], $r['consent_id'], $r['given_at'], $r['prefs'], $r['analytics'], $r['marketing'], $r['mode'], $r['region'], $r['policy'], $r['net'], $r['agent'], $r['lang'] ) );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Empties the log.
	 */
	public function handle_purge(): void {
		$this->guard();

		Log::purge();
		$this->back( __( 'The log is empty.', 'oc-theme' ) );
	}

	/**
	 * Composed pages carry the banner's texts; a change must reach them.
	 */
	private function flush(): void {
		if ( class_exists( '\OC\Blocks\Render' ) ) {
			( new \OC\Blocks\Render() )->flush();
		}
	}
}
