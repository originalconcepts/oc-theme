<?php
/**
 * The composer's live preview: a real front-end render of the draft.
 *
 * The preview is 1:1 with the site because it IS the site rendering it —
 * the theme's stylesheet, its fonts, its design tokens and the blocks' own
 * assets, around the exact sections standing in the editor. No simulation.
 *
 * @package OC_Blocks
 */

declare( strict_types = 1 );

namespace OC\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Draft rendering behind ?oc_compose_preview.
 */
final class Preview {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
	}

	/**
	 * Draw the draft and stop.
	 */
	public function maybe_render(): void {
		if ( ! isset( $_GET['oc_compose_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
			return;
		}

		if ( ! current_user_can( 'edit_pages' ) || ! check_ajax_referer( 'oc_blocks', 'nonce', false ) ) {
			status_header( 403 );
			exit;
		}

		$key      = isset( $_GET['draft'] ) ? sanitize_key( wp_unslash( $_GET['draft'] ) ) : '';
		$sections = array();

		if ( '' !== $key ) {
			$draft = get_transient( 'oc_compose_draft_' . $key );

			if ( is_array( $draft ) ) {
				$sections = Registry::clean( $draft );
			}
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		// Stories cut their stylesheet to the surfaces a page asked for by
		// now — the draft's sections will ask mid-render, long after that
		// decision, so every surface is requested up front. Same call the
		// front end makes (Render::integration_assets).
		if ( class_exists( '\\OCS\\Display\\Injector' ) ) {
			add_filter( 'ocs_force_assets', '__return_true' );

			if ( class_exists( '\\OCS\\Surfaces\\SurfaceManager' ) ) {
				foreach ( (array) \OCS\Surfaces\SurfaceManager::ids() as $id ) {
					\OCS\Display\Injector::need( (string) $id );
				}
			}
		}

		// The real asset pipeline, not a hand-picked imitation: every
		// plugin's registrations, localisations and inline styles land
		// exactly as they do on the site, and the preview stays 1:1.
		do_action( 'wp_enqueue_scripts' );

		// The blocks' own assets, in case nothing above brought them.
		$css = oc_blocks_asset( 'assets/blocks.css' );
		$js  = oc_blocks_asset( 'assets/blocks.js' );
		wp_enqueue_style( 'oc-blocks', OC_BLOCKS_URI . $css, array(), (string) filemtime( OC_BLOCKS_DIR . $css ) );
		wp_enqueue_script( 'oc-blocks', OC_BLOCKS_URI . $js, array(), (string) filemtime( OC_BLOCKS_DIR . $js ), array( 'in_footer' => true ) );

		if ( function_exists( 'oc_asset_min' ) && defined( 'OC_THEME_URI' ) ) {
			$css = oc_asset_min( '/assets/css/theme.css' );
			wp_enqueue_style( 'oc-theme', OC_THEME_URI . $css, array(), oc_asset_version( $css ) );
		}

		$tokens = '';

		if ( class_exists( 'OC\\Theme\\Assets' ) && method_exists( 'OC\\Theme\\Assets', 'tokens_css' ) ) {
			$tokens = ( new \OC\Theme\Assets() )->tokens_css();
		}

		$fonts = '';

		if ( defined( 'OC_THEME_URI' ) ) {
			$fonts = '<link rel="stylesheet" href="' . esc_url( OC_THEME_URI . '/assets/fonts/assistant.css' ) . '">';
		}

		// The body renders FIRST: integration blocks (stories, reviews)
		// enqueue their own assets mid-render, and the head must have seen
		// them before it prints.
		$body = empty( $sections )
			? '<p style="padding:60px 30px;text-align:center;opacity:.55;font-size:15px;">' . esc_html__( 'Add a section and it will appear here, exactly as on the site.', 'oc-blocks' ) . '</p>'
			: Render::sections_html( $sections );

		echo '<!doctype html><html dir="' . ( is_rtl() ? 'rtl' : 'ltr' ) . '" lang="' . esc_attr( get_locale() ) . '"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo $fonts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		wp_print_styles();
		echo '<style>:root{' . $tokens . '}body{margin:0;background:var(--oc-bg-user,var(--oc-surface,#fff));color:var(--oc-ink,#1c1c1c);font-family:var(--oc-font-body,system-ui,sans-serif);line-height:1.55}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS custom properties from the theme's own generator.
		echo '</head><body class="oc-compose-preview">';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		wp_print_scripts();
		// A click on a section tells the composer to open its card — links,
		// buttons and form fields keep doing their own job.
		echo '<script>document.addEventListener("click",function(e){if(e.target.closest("a,button,input,textarea,select,label,form,iframe"))return;var s=e.target.closest("[data-ocb-n]");if(s&&window.parent!==window){window.parent.postMessage({ocbPick:Number(s.dataset.ocbN)},"*");}});</script>';
		echo '</body></html>';
		exit;
	}
}
