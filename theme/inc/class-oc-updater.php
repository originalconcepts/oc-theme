<?php
/**
 * Theme updates from GitHub releases.
 *
 * Replaces the Bitbucket updater in oc-main-theme, which could not work:
 * it always downloaded master.zip instead of the tagged version, its
 * upgrader_post_install() callback had the wrong signature (so it read the
 * wrong array and returned null, breaking the upgrader contract), its rename
 * guard bailed whenever the destination existed — which is always — and it
 * shipped a Bitbucket app password and API token in plain text to every
 * client site.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Checks GitHub releases and hands WordPress a package to install.
 */
final class Updater {

	private const TRANSIENT = 'oc_release_';
	private const TTL       = 6 * HOUR_IN_SECONDS;
	private const API       = 'https://api.github.com/repos/%s/releases/latest';

	/**
	 * Theme directory name, e.g. "oc-theme".
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Installed version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * GitHub "owner/repo".
	 *
	 * @var string
	 */
	private string $repo;

	/**
	 * "theme" or "plugin". The release is looked up the same way for both;
	 * only the transient WordPress keeps its answer in, and the shape of the
	 * answer, differ.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * For a plugin, the file WordPress knows it by, e.g.
	 * "oc-blocks/oc-blocks.php". Empty for a theme.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Store the update source.
	 *
	 * @param string $slug    Theme or plugin directory name.
	 * @param string $version Installed version.
	 * @param string $repo    GitHub owner/repo.
	 * @param string $kind    "theme" or "plugin".
	 * @param string $file    Plugin file, for a plugin.
	 */
	public function __construct( string $slug, string $version, string $repo, string $kind = 'theme', string $file = '' ) {
		$this->slug    = $slug;
		$this->version = $version;
		$this->repo    = $repo;
		$this->kind    = 'plugin' === $kind ? 'plugin' : 'theme';
		$this->file    = $file;
	}

	/**
	 * Where this instance keeps its answer. Keyed by slug, because the theme
	 * and the plugin come from one release but take a different asset out of
	 * it — one cache for both would hand the plugin the theme's zip.
	 *
	 * @return string
	 */
	private function cache_key(): string {
		return self::TRANSIENT . str_replace( '-', '_', $this->slug );
	}

	/**
	 * Register hooks. Never on a front-end request — but an update check is
	 * not only a person in wp-admin: the host's update runner comes through
	 * WP-CLI and cron, where is_admin() is false, and an updater that does
	 * not exist there reports "nothing to update" while GitHub holds a newer
	 * release.
	 */
	public function register(): void {
		$cli = defined( 'WP_CLI' ) && WP_CLI;

		if ( ! is_admin() && ! wp_doing_cron() && ! $cli ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_' . $this->kind . 's', array( $this, 'inject_update' ) );
		add_filter( 'upgrader_source_selection', array( $this, 'normalise_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 2 );
	}

	/**
	 * Add our theme to WordPress's update list when a newer release exists.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( null === $release ) {
			return $transient;
		}

		if ( version_compare( $release['version'], $this->version, '<=' ) ) {
			return $transient;
		}

		// A theme's entry is an array keyed by directory; a plugin's is an
		// object keyed by the plugin file. WordPress reads them differently
		// and silently ignores the wrong shape.
		if ( 'plugin' === $this->kind ) {
			$offer              = new \stdClass();
			$offer->slug        = $this->slug;
			$offer->plugin      = $this->file;
			$offer->new_version = $release['version'];
			$offer->url         = $release['url'];
			$offer->package     = $release['package'];

			$transient->response[ $this->file ] = $offer;

			return $transient;
		}

		$transient->response[ $this->slug ] = array(
			'theme'       => $this->slug,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
		);

		return $transient;
	}

	/**
	 * GitHub names the extracted folder after the zip, which is not always the
	 * theme slug. Rename it before WordPress moves it into place.
	 *
	 * Unlike the old updater this runs *before* installation, so there is no
	 * "destination already exists" problem, and it always returns a value the
	 * upgrader can use.
	 *
	 * @param string|\WP_Error $source        Extracted path.
	 * @param string           $remote_source Working directory.
	 * @param \WP_Upgrader     $upgrader      Upgrader instance.
	 * @param array            $args          Hook extra.
	 * @return string|\WP_Error
	 */
	public function normalise_folder( $source, $remote_source, $upgrader, $args = array() ) {
		global $wp_filesystem;

		$named = $args[ $this->kind ] ?? '';

		if ( 'plugin' === $this->kind ) {
			$named = '' === $named ? '' : dirname( (string) $named );
		}

		if ( is_wp_error( $source ) || $named !== $this->slug ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;

		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}

		if ( ! $wp_filesystem->move( $source, $desired ) ) {
			return new \WP_Error(
				'oc_rename_failed',
				__( 'Could not prepare the downloaded folder.', 'oc-theme' )
			);
		}

		return trailingslashit( $desired );
	}

	/**
	 * Drop the cached release after any update runs.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $data     Update context.
	 */
	public function flush( $upgrader, $data ): void {
		if ( isset( $data['type'] ) && $this->kind === $data['type'] ) {
			delete_site_transient( $this->cache_key() );
		}
	}

	/**
	 * Latest release, cached. Returns null when unavailable — a GitHub outage
	 * must never block wp-admin.
	 *
	 * @return array{version:string,package:string,url:string}|null
	 */
	private function latest_release(): ?array {
		$cached = get_site_transient( $this->cache_key() );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			sprintf( self::API, $this->repo ),
			array(
				'timeout' => 8,
				'headers' => $this->headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly so a broken token does not hammer the API.
			set_site_transient( $this->cache_key(), 'none', 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return null;
		}

		$package = '';
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['name'] ) && $this->slug . '.zip' === $asset['name'] ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}

		if ( '' === $package ) {
			return null;
		}

		$release = array(
			'version' => ltrim( (string) $body['tag_name'], 'v' ),
			'package' => $package,
			'url'     => (string) ( $body['html_url'] ?? '' ),
		);

		set_site_transient( $this->cache_key(), $release, self::TTL );

		return $release;
	}

	/**
	 * Request headers. A token is read from wp-config.php when the repository
	 * is private — it is never stored in this repository or in the database.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'oc-theme/' . $this->version,
		);

		if ( defined( 'OC_UPDATE_TOKEN' ) && is_string( OC_UPDATE_TOKEN ) && '' !== OC_UPDATE_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . OC_UPDATE_TOKEN;
		}

		return $headers;
	}
}
