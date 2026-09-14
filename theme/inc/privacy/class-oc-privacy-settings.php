<?php
/**
 * Privacy settings: how the site asks for consent and what it tells.
 *
 * One option, versioned. The screen edits it; the banner, the script and
 * the policy page read it. Every text here has a built-in default in the
 * site's language, so a site that never touches the screen is still
 * complete.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Privacy;

if ( ! defined( 'ABSPATH' ) && ! defined( 'OC_TESTS' ) ) {
	exit;
}

/**
 * Loads, shapes and stores the settings.
 */
final class Settings {

	const OPTION  = 'oc_privacy';
	const VERSION = 1;

	/**
	 * The three categories a visitor decides about. "Necessary" is not a
	 * choice — the shop cannot run without its cart and session cookies.
	 *
	 * @var string[]
	 */
	const CATEGORIES = array( 'preferences', 'analytics', 'marketing' );

	/**
	 * Read once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cached = null;

	/**
	 * The settings, whole and typed.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		if ( null !== self::$cached ) {
			return self::$cached;
		}

		$raw          = get_option( self::OPTION, array() );
		self::$cached = self::normalize( is_array( $raw ) ? $raw : array() );

		return self::$cached;
	}

	/**
	 * Store, normalized.
	 *
	 * @param array<string,mixed> $s Settings.
	 */
	public static function save( array $s ): void {
		update_option( self::OPTION, self::normalize( $s ), false );
		self::$cached = null;
	}

	/**
	 * Is the consent layer on at all?
	 */
	public static function on(): bool {
		return ! empty( self::get()['enabled'] );
	}

	/**
	 * Every field present and typed.
	 *
	 * @param array<string,mixed> $raw Raw.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $raw ): array {
		$str = static function ( $v ): string {
			return trim( (string) $v );
		};

		$texts = (array) ( $raw['texts'] ?? array() );
		$cats  = (array) ( $raw['cats'] ?? array() );
		$mode  = $str( $raw['mode'] ?? 'auto' );
		$look  = $str( $raw['layout'] ?? 'card' );
		$where = $str( $raw['position'] ?? 'start' );
		$open  = $str( $raw['reopen'] ?? 'badge' );

		$scripts = array();

		foreach ( (array) ( $raw['scripts'] ?? array() ) as $row ) {
			$row  = (array) $row;
			$code = trim( (string) ( $row['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			$cat = $str( $row['cat'] ?? 'marketing' );

			$scripts[] = array(
				'name'  => $str( $row['name'] ?? '' ),
				'cat'   => in_array( $cat, array_merge( array( 'necessary' ), self::CATEGORIES ), true ) ? $cat : 'marketing',
				'where' => 'head' === $str( $row['where'] ?? 'footer' ) ? 'head' : 'footer',
				'code'  => $code,
			);
		}

		$out = array(
			'version'   => self::VERSION,
			// On by default: a site that never opens the screen still
			// tells its visitors what it does and lets them choose.
			'enabled'   => ! isset( $raw['enabled'] ) || ! empty( $raw['enabled'] ),
			'mode'      => in_array( $mode, array( 'auto', 'optin', 'optout' ), true ) ? $mode : 'auto',
			'layout'    => in_array( $look, array( 'card', 'bar' ), true ) ? $look : 'card',
			'position'  => in_array( $where, array( 'start', 'end', 'center' ), true ) ? $where : 'start',
			'reopen'    => in_array( $open, array( 'badge', 'link', 'none' ), true ) ? $open : 'badge',
			'footer'    => ! isset( $raw['footer'] ) || ! empty( $raw['footer'] ),
			'google'    => ! empty( $raw['google'] ),
			'gpc'       => ! isset( $raw['gpc'] ) || ! empty( $raw['gpc'] ),
			'nocookie'  => ! isset( $raw['nocookie'] ) || ! empty( $raw['nocookie'] ),
			'log'       => ! isset( $raw['log'] ) || ! empty( $raw['log'] ),
			'log_days'  => max( 30, min( 3650, (int) ( $raw['log_days'] ?? 400 ) ) ),
			'reconsent' => max( 0, (int) ( $raw['reconsent'] ?? 0 ) ),
			'policy'    => max( 0, (int) ( $raw['policy'] ?? 0 ) ),
			'texts'     => array(
				'title'  => $str( $texts['title'] ?? '' ),
				'text'   => $str( $texts['text'] ?? '' ),
				'accept' => $str( $texts['accept'] ?? '' ),
				'reject' => $str( $texts['reject'] ?? '' ),
				'manage' => $str( $texts['manage'] ?? '' ),
				'save'   => $str( $texts['save'] ?? '' ),
				'policy' => $str( $texts['policy'] ?? '' ),
				'badge'  => $str( $texts['badge'] ?? '' ),
			),
			'cats'      => array(),
			'scripts'   => $scripts,
		);

		foreach ( self::CATEGORIES as $cat ) {
			$one = (array) ( $cats[ $cat ] ?? array() );

			$out['cats'][ $cat ] = array(
				'on'    => ! isset( $one['on'] ) || ! empty( $one['on'] ),
				'label' => $str( $one['label'] ?? '' ),
				'desc'  => $str( $one['desc'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * A text as the visitor sees it: the site's own words, or the built-in
	 * default in the site's language.
	 *
	 * @param string $key Text key.
	 */
	public static function text( string $key ): string {
		$s   = self::get();
		$own = (string) ( $s['texts'][ $key ] ?? '' );

		if ( '' !== $own ) {
			return $own;
		}

		return (string) ( self::defaults()[ $key ] ?? '' );
	}

	/**
	 * A category's label or description, own words or default.
	 *
	 * @param string $cat   Category.
	 * @param string $which 'label' or 'desc'.
	 */
	public static function cat_text( string $cat, string $which ): string {
		$s   = self::get();
		$own = (string) ( $s['cats'][ $cat ][ $which ] ?? '' );

		if ( '' !== $own ) {
			return $own;
		}

		$d = self::cat_defaults();

		return (string) ( $d[ $cat ][ $which ] ?? '' );
	}

	/**
	 * The built-in banner texts. Plain, short, and honest: what the site
	 * does, and two equal ways to answer.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		return array(
			'title'  => __( 'Your privacy', 'oc-theme' ),
			'text'   => __( 'We use cookies to run the shop, to understand how it is used and to show you relevant offers. You choose what to allow, and you can change your mind at any time.', 'oc-theme' ),
			'accept' => __( 'Accept all', 'oc-theme' ),
			'reject' => __( 'Only necessary', 'oc-theme' ),
			'manage' => __( 'Choose', 'oc-theme' ),
			'save'   => __( 'Save my choices', 'oc-theme' ),
			'policy' => __( 'Privacy policy', 'oc-theme' ),
			'badge'  => __( 'Privacy settings', 'oc-theme' ),
		);
	}

	/**
	 * The categories as the visitor reads them.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function cat_defaults(): array {
		return array(
			'necessary'   => array(
				'label' => __( 'Necessary', 'oc-theme' ),
				'desc'  => __( 'Keeps the shop working: your cart, your sign-in and your choices in this form. Always on.', 'oc-theme' ),
			),
			'preferences' => array(
				'label' => __( 'Preferences', 'oc-theme' ),
				'desc'  => __( 'Remembers your settings, such as a language or a recently viewed product.', 'oc-theme' ),
			),
			'analytics'   => array(
				'label' => __( 'Statistics', 'oc-theme' ),
				'desc'  => __( 'Helps us understand how the site is used, so we can make it better. The data is aggregated.', 'oc-theme' ),
			),
			'marketing'   => array(
				'label' => __( 'Marketing', 'oc-theme' ),
				'desc'  => __( 'Lets advertising networks show you offers that fit what you looked at here, and measure whether their ads work.', 'oc-theme' ),
			),
		);
	}

	/**
	 * The version stamp a stored consent must match. Changes when the
	 * site asks everyone again, and when the policy page is edited — a
	 * new policy is a new thing to agree to.
	 */
	public static function policy_version(): string {
		$s    = self::get();
		$page = self::policy_page_id();
		$mod  = $page > 0 ? (string) get_post_field( 'post_modified_gmt', $page ) : '';

		return (string) $s['reconsent'] . ':' . substr( md5( $mod ), 0, 8 );
	}

	/**
	 * The policy page: the one chosen here, or WordPress's own setting.
	 */
	public static function policy_page_id(): int {
		$s = self::get();

		if ( $s['policy'] > 0 && 'publish' === get_post_status( $s['policy'] ) ) {
			return (int) $s['policy'];
		}

		// WordPress creates its privacy page as a DRAFT and points the
		// option at it; a link to a draft is a 404 for every visitor.
		$wp = (int) get_option( 'wp_page_for_privacy_policy', 0 );

		return $wp > 0 && 'publish' === get_post_status( $wp ) ? $wp : 0;
	}

	/**
	 * The policy page's address, or '' when there is none.
	 */
	public static function policy_url(): string {
		$id = self::policy_page_id();

		return $id > 0 ? (string) get_permalink( $id ) : '';
	}
}
