<?php
/**
 * Updates for OC Blocks installed on its own.
 *
 * Under the OC theme the theme's own updater brings OC Blocks along with
 * every release. Installed on any other theme, nothing would — so here
 * the plugin asks GitHub itself: the public releases of oc-theme carry an
 * oc-blocks.zip, and WordPress is told about a newer one the usual way, in
 * Plugins and Dashboard → Updates. No token is needed or stored: the
 * repository and its release files are public.
 *
 * @package OC_Blocks
 */

declare( strict_types = 1 );

namespace OC\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * The update check.
 */
final class Updater {

	/**
	 * Where the releases are.
	 */
	private const API = 'https://api.github.com/repos/originalconcepts/oc-theme/releases/latest';

	/**
	 * The same answer from the website, for when the API refuses: GitHub
	 * allows 60 anonymous API calls an hour per address, and on shared
	 * hosting every site on the server spends from that one allowance.
	 */
	private const WEB = 'https://github.com/originalconcepts/oc-theme/releases';

	/**
	 * Cache of the last answer.
	 */
	private const CACHE = 'oc_blocks_release';

	/**
	 * Hook in — only where the OC theme is not (it updates the plugin itself).
	 */
	public function register(): void {
		if ( Host::is_oc() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'plugins_api', array( $this, 'info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 0 );
	}

	/**
	 * This plugin's basename.
	 */
	private static function basename(): string {
		return plugin_basename( OC_BLOCKS_DIR . 'oc-blocks.php' );
	}

	/**
	 * The newest release: version, package, page, notes. Cached six hours;
	 * a failed request is cached an hour, so a slow GitHub never slows the
	 * admin.
	 *
	 * @return array<string,string>
	 */
	private static function latest(): array {
		$cached = get_site_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = array();
		$res = wp_remote_get(
			self::API,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'oc-blocks/' . OC_BLOCKS_VERSION,
				),
			)
		);

		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );

			if ( is_array( $body ) ) {
				foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
					if ( is_array( $asset ) && 'oc-blocks.zip' === ( $asset['name'] ?? '' ) ) {
						$out = array(
							'version' => ltrim( (string) ( $body['tag_name'] ?? '' ), 'v' ),
							'package' => (string) ( $asset['browser_download_url'] ?? '' ),
							'url'     => (string) ( $body['html_url'] ?? '' ),
							'notes'   => (string) ( $body['body'] ?? '' ),
						);
					}
				}
			}
		}

		if ( ! $out ) {
			$out = self::from_web();
		}

		set_site_transient( self::CACHE, $out, $out ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * The newest release read off the website instead of the API: the
	 * "latest" page redirects to the release's tag, and the zip is only
	 * offered once it is really there (a release is published a minute
	 * before its files are uploaded).
	 *
	 * @return array<string,string>
	 */
	private static function from_web(): array {
		$args = array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array( 'User-Agent' => 'oc-blocks/' . OC_BLOCKS_VERSION ),
		);

		$res = wp_remote_head( self::WEB . '/latest', $args );

		if ( is_wp_error( $res ) || ! preg_match( '#/releases/tag/v?([0-9][0-9.]*)$#', (string) wp_remote_retrieve_header( $res, 'location' ), $m ) ) {
			return array();
		}

		$package = self::WEB . '/download/v' . $m[1] . '/oc-blocks.zip';
		$zip     = wp_remote_head( $package, $args );
		$code    = is_wp_error( $zip ) ? 0 : (int) wp_remote_retrieve_response_code( $zip );

		if ( $code < 200 || $code >= 400 ) {
			return array();
		}

		return array(
			'version' => $m[1],
			'package' => $package,
			'url'     => self::WEB . '/tag/v' . $m[1],
			'notes'   => '',
		);
	}

	/**
	 * Tell WordPress about a newer release.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$rel = self::latest();

		if ( empty( $rel['version'] ) || empty( $rel['package'] ) || ! version_compare( $rel['version'], OC_BLOCKS_VERSION, '>' ) ) {
			return $transient;
		}

		$transient->response[ self::basename() ] = (object) array(
			'slug'         => 'oc-blocks',
			'plugin'       => self::basename(),
			'new_version'  => $rel['version'],
			'package'      => $rel['package'],
			'url'          => $rel['url'],
			'requires_php' => '8.1',
		);

		return $transient;
	}

	/**
	 * The "View details" window.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action Action.
	 * @param object $args   Arguments.
	 * @return mixed
	 */
	public function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || 'oc-blocks' !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$rel = self::latest();

		if ( empty( $rel['version'] ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'OC Blocks',
			'slug'          => 'oc-blocks',
			'version'       => $rel['version'],
			'author'        => 'OC Pluginz',
			'homepage'      => $rel['url'],
			'download_link' => $rel['package'],
			'requires_php'  => '8.1',
			'sections'      => array(
				'changelog' => wpautop( esc_html( $rel['notes'] ) ),
			),
		);
	}

	/**
	 * After an update, ask again next time.
	 */
	public function forget(): void {
		delete_site_transient( self::CACHE );
	}
}
