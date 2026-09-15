<?php
/**
 * The banner on the page, the script that runs it, and the snippets it
 * holds back.
 *
 * Everything the visitor sees is printed in the footer of every page —
 * hidden — and the script decides whether to show it. That is deliberate:
 * a page may come out of a cache four hours old, so the server cannot
 * know who is looking at it. The browser can, from its own cookie.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * The visitor-facing half.
 */
final class Front {

	/**
	 * Hooks.
	 */
	public function register(): void {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 31 );
		add_action( 'wp_head', array( $this, 'head_snippets' ), 5 );
		add_action( 'wp_footer', array( $this, 'footer' ), 45 );
		add_filter( 'embed_oembed_html', array( $this, 'nocookie' ), 20 );
		add_action( 'oc_footer_legal', array( $this, 'print_footer_link' ) );
	}

	/**
	 * The script, deferred, with what it needs to decide.
	 */
	public function assets(): void {
		if ( ! Settings::on() ) {
			return;
		}

		wp_enqueue_script(
			'oc-privacy',
			OC_THEME_URI . oc_asset_min( '/assets/js/privacy.js' ),
			array(),
			oc_asset_version( '/assets/js/privacy.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * What the script is told. Nothing per-visitor here — it must be the
	 * same for everyone, or a cache would hand one visitor another's.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		$s    = Settings::get();
		$cats = array();

		foreach ( Settings::CATEGORIES as $cat ) {
			if ( ! empty( $s['cats'][ $cat ]['on'] ) ) {
				$cats[] = $cat;
			}
		}

		return array(
			'mode'   => $s['mode'],
			'pv'     => Settings::policy_version(),
			'cats'   => $cats,
			'gpc'    => (bool) $s['gpc'],
			'google' => (bool) $s['google'],
			'log'    => (bool) $s['log'],
			'rest'   => esc_url_raw( rest_url( 'oc/v1/consent' ) ),
			'token'  => Log::token(),
			'reopen' => $s['reopen'],
			'lang'   => substr( get_locale(), 0, 5 ),
		);
	}

	/**
	 * Snippets the site owner pasted for the head. Necessary ones print
	 * as they are; the rest wait in a template for the visitor's yes.
	 */
	public function head_snippets(): void {
		$this->snippets( 'head' );
	}

	/**
	 * The banner, the panel, the badge and the footer snippets.
	 */
	public function footer(): void {
		if ( ! Settings::on() ) {
			return;
		}

		$this->snippets( 'footer' );
		self::markup();
	}

	/**
	 * Prints the snippets for one place.
	 *
	 * @param string $where 'head' or 'footer'.
	 */
	private function snippets( string $where ): void {
		if ( ! Settings::on() ) {
			return;
		}

		foreach ( Settings::get()['scripts'] as $row ) {
			if ( $row['where'] !== $where ) {
				continue;
			}

			if ( 'necessary' === $row['cat'] ) {
				echo "\n" . $row['code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the site owner's own tag, printed as pasted.
				continue;
			}

			// A <template> is inert: nothing in it loads or runs until the
			// script lifts it out, and only for a category the visitor
			// allowed.
			echo "\n" . '<template data-oc-consent="' . esc_attr( $row['cat'] ) . '" data-oc-name="' . esc_attr( $row['name'] ) . '">' . $row['code'] . '</template>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the site owner's own tag, held inside an inert template.
		}
	}

	/**
	 * Everything the visitor may see, hidden until the script decides.
	 */
	public static function markup(): void {
		$s      = Settings::get();
		$cfg    = self::config();
		$policy = Settings::policy_url();
		$t      = static function ( string $key ): string {
			return Settings::text( $key );
		};
		?>
		<?php
		$style = ( '' !== $s['accent'] ? '--oc-privacy-accent:' . $s['accent'] . ';' : '' ) . ( '' !== $s['accent_tx'] ? '--oc-privacy-accent-tx:' . $s['accent_tx'] . ';' : '' );
		?>
		<div class="oc-privacy oc-privacy--<?php echo esc_attr( $s['layout'] ); ?> oc-privacy--<?php echo esc_attr( $s['position'] ); ?>" data-oc-privacy="<?php echo esc_attr( (string) wp_json_encode( $cfg ) ); ?>"<?php echo '' !== $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?> hidden>
			<section class="oc-privacy__banner" role="region" aria-label="<?php echo esc_attr( $t( 'title' ) ); ?>" data-oc-privacy-banner hidden>
				<div class="oc-privacy__words">
					<p class="oc-privacy__title"><?php echo esc_html( $t( 'title' ) ); ?></p>
					<p class="oc-privacy__text">
						<?php echo esc_html( $t( 'text' ) ); ?>
						<?php if ( '' !== $policy ) : ?>
							<a class="oc-privacy__policy" href="<?php echo esc_url( $policy ); ?>"><?php echo esc_html( $t( 'policy' ) ); ?></a>
						<?php endif; ?>
					</p>
				</div>
				<div class="oc-privacy__acts">
					<button type="button" class="oc-privacy__btn oc-privacy__btn--ghost" data-oc-privacy-manage><?php echo esc_html( $t( 'manage' ) ); ?></button>
					<button type="button" class="oc-privacy__btn oc-privacy__btn--ghost" data-oc-privacy-reject><?php echo esc_html( $t( 'reject' ) ); ?></button>
					<button type="button" class="oc-privacy__btn oc-privacy__btn--solid" data-oc-privacy-accept><?php echo esc_html( $t( 'accept' ) ); ?></button>
				</div>
			</section>

			<div class="oc-privacy__veil" data-oc-privacy-veil hidden></div>
			<div class="oc-privacy__panel" role="dialog" aria-modal="true" aria-labelledby="oc-privacy-h" data-oc-privacy-panel hidden>
				<div class="oc-privacy__panel-head">
					<h2 id="oc-privacy-h" class="oc-privacy__panel-title"><?php echo esc_html( $t( 'title' ) ); ?></h2>
					<button type="button" class="oc-privacy__close" data-oc-privacy-close aria-label="<?php esc_attr_e( 'Close', 'oc-theme' ); ?>">&times;</button>
				</div>
				<p class="oc-privacy__panel-text">
					<?php echo esc_html( $t( 'text' ) ); ?>
					<?php if ( '' !== $policy ) : ?>
						<a class="oc-privacy__policy" href="<?php echo esc_url( $policy ); ?>"><?php echo esc_html( $t( 'policy' ) ); ?></a>
					<?php endif; ?>
				</p>
				<ul class="oc-privacy__cats">
					<li class="oc-privacy__cat">
						<div class="oc-privacy__cat-row">
							<span class="oc-privacy__cat-name"><?php echo esc_html( Settings::cat_text( 'necessary', 'label' ) ); ?></span>
							<span class="oc-privacy__always"><?php esc_html_e( 'Always on', 'oc-theme' ); ?></span>
						</div>
						<p class="oc-privacy__cat-desc"><?php echo esc_html( Settings::cat_text( 'necessary', 'desc' ) ); ?></p>
					</li>
					<?php foreach ( $cfg['cats'] as $cat ) : ?>
						<li class="oc-privacy__cat">
							<div class="oc-privacy__cat-row">
								<label class="oc-privacy__cat-name" for="oc-privacy-<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( Settings::cat_text( $cat, 'label' ) ); ?></label>
								<span class="oc-privacy__switch">
									<input type="checkbox" id="oc-privacy-<?php echo esc_attr( $cat ); ?>" data-oc-privacy-cat="<?php echo esc_attr( $cat ); ?>" />
									<span class="oc-privacy__knob" aria-hidden="true"></span>
								</span>
							</div>
							<p class="oc-privacy__cat-desc"><?php echo esc_html( Settings::cat_text( $cat, 'desc' ) ); ?></p>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="oc-privacy__panel-acts">
					<button type="button" class="oc-privacy__btn oc-privacy__btn--ghost" data-oc-privacy-reject><?php echo esc_html( $t( 'reject' ) ); ?></button>
					<button type="button" class="oc-privacy__btn oc-privacy__btn--ghost" data-oc-privacy-save><?php echo esc_html( $t( 'save' ) ); ?></button>
					<button type="button" class="oc-privacy__btn oc-privacy__btn--solid" data-oc-privacy-accept><?php echo esc_html( $t( 'accept' ) ); ?></button>
				</div>
			</div>

			<?php if ( 'badge' === $s['reopen'] ) : ?>
				<button type="button" class="oc-privacy__badge" data-oc-privacy-open aria-label="<?php echo esc_attr( $t( 'badge' ) ); ?>" title="<?php echo esc_attr( $t( 'badge' ) ); ?>" hidden>
					<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.4-7 10-4-1.6-7-5.5-7-10V6l7-3z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg>
				</button>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A footer link that opens the panel, where the theme's footer asks
	 * for one.
	 */
	public function print_footer_link(): void {
		echo self::footer_link_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * The link's markup, or '' when the footer link is off.
	 */
	public static function footer_link_html(): string {
		$s = Settings::get();

		if ( ! Settings::on() || empty( $s['footer'] ) ) {
			return '';
		}

		return '<button type="button" class="oc-footer__privacy" data-oc-privacy-open>' . esc_html( Settings::text( 'badge' ) ) . '</button>';
	}

	/**
	 * A YouTube embed in post content asks the no-cookie host instead:
	 * nothing is stored until the visitor presses play.
	 *
	 * @param mixed $html Embed HTML.
	 * @return mixed
	 */
	public function nocookie( $html ) {
		if ( ! is_string( $html ) || empty( Settings::get()['nocookie'] ) ) {
			return $html;
		}

		return str_replace( array( 'https://www.youtube.com/embed/', 'https://youtube.com/embed/' ), 'https://www.youtube-nocookie.com/embed/', $html );
	}
}
