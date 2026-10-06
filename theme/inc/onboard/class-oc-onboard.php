<?php
/**
 * The onboarding questionnaire: the door, the key and the state.
 *
 * A new shop is born as a clone of the base site. The clone mints one
 * signed link, /start/<token>, and the customer fills the questionnaire
 * on the new site itself — no account, no wp-admin, every answer saved
 * as it is typed. On "I'm done" the answers are written straight into
 * the theme's settings, the store details, WooCommerce and the pages.
 *
 * This class owns the invitation (who, when, which token, what status)
 * and the virtual route. The questions live in Schema, the saving in
 * Draft, the writing in Apply, the page in Front, the screen in Admin.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

use OC\Theme\Contact;
use OC\Theme\Legal;

defined( 'ABSPATH' ) || exit;

/**
 * Invitation, token and route.
 */
final class Onboard {

	const OPTION   = 'oc_onboard';
	const SETTINGS = 'oc_onboard_settings';
	const TTL_DAYS = 90;
	const QUERY    = 'oc_start';
	const QUIZ     = 'oc_quiz';

	/**
	 * Where a copy of every mail goes and where the report lands.
	 */
	const COPY_TO = 'george@originalconcepts.co.il';

	/**
	 * Where a shop with no clearing company opens one: our PayPlus link.
	 */
	const PAYPLUS = 'https://aff.pays.plus/2ed72e6e-e9b2-420e-b3a2-50cf476309d2?ref=';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'route' ) );
		add_filter( 'query_vars', array( $this, 'vars' ) );
		add_action( 'template_redirect', array( $this, 'quiz' ), 0 );
		add_filter( 'redirect_canonical', array( $this, 'no_canonical' ), 10, 2 );

		( new Rest() )->register();
		( new Front() )->register();
		( new Mail() )->register();
		( new Drive() )->register();
		( new Curtain() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
		}
	}

	/* ---------------------------------------------------------------- state */

	/**
	 * The invitation record with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$saved = get_option( self::OPTION );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'status'      => 'none',   // none | draft | submitted | applied | cancelled.
				'token_hash'  => '',
				'link'        => '',       // The link itself, for re-sends and reminders.
				'created'     => 0,        // Unix, when the link was minted.
				'expires'     => 0,
				'client'      => array(
					'name'  => '',
					'phone' => '',
					'email' => '',
				),
				// The card this customer came from, so a later notice can name
				// it. The item id alone is enough: it is unique across the account.
				'monday'      => array(
					'item' => '',
				),
				'opened'      => 0,        // First time the link was opened.
				'activity'    => 0,        // Last change to the draft.
				'step'        => '',       // Last screen the customer was on.
				'far'         => '',       // Furthest screen they ever opened.
				'reminders'   => array(),  // r1 | r2 | g => unix.
				'submitted'   => 0,
				'applied'     => 0,
				'report'      => array(),  // The apply report, one row per target.
				'existing'    => '',       // The customer's current site, if any.
				'user'        => '',       // The customer's own login, once made.
				'drive'       => array(),  // root | folders | doc | when, from Make.
				'drive_fail'  => 0,        // Misses in a row asking for them.
				'drive_try'   => 0,        // The last ask.
				'drive_why'   => '',       // What the last miss said.
				'drive_alert' => 0,        // When the team was told it is late.
				'mailed'      => 0,        // When the customer's done mail went.
				'mails'       => array(),  // Every mail that went: {key, to, when}.
			)
		);
	}

	/**
	 * Persist the record. Never autoloaded: it carries no front-end value.
	 *
	 * @param array<string,mixed> $state The record.
	 */
	public static function save_state( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Change a few keys and save.
	 *
	 * @param array<string,mixed> $patch Keys to change.
	 * @return array<string,mixed> The record after.
	 */
	public static function patch_state( array $patch ): array {
		$state = array_merge( self::state(), $patch );
		self::save_state( $state );

		return $state;
	}

	/**
	 * Settings the team fills once on the base site: keys the questionnaire
	 * uses to talk to Monday and to the writing model. Never theme mods,
	 * which the Customizer would export.
	 *
	 * @return array<string,string>
	 */
	public static function settings(): array {
		$saved = get_option( self::SETTINGS );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'claude_key' => '',
				// Make's webhook: told when the answers are in, answers
				// with the Drive folders.
				'hook'       => '',
			)
		);
	}

	/* ---------------------------------------------------------------- token */

	/**
	 * Mint an invitation. A new token replaces the old one; the answers
	 * already given stay, so a re-issued link picks up where it stopped.
	 *
	 * @param array<string,string> $client name | phone | email.
	 * @param array<string,string> $monday item | board.
	 * @return string The full link.
	 */
	public static function invite( array $client, array $monday = array() ): string {
		$token = bin2hex( random_bytes( 20 ) ); // 40 url-safe characters.
		$state = self::state();
		$fresh = in_array( $state['status'], array( 'none', 'cancelled', 'applied' ), true );

		$state['status']     = 'draft';
		$state['token_hash'] = self::hash( $token );
		$state['link']       = self::url( $token );
		$state['created']    = time();
		$state['expires']    = time() + self::TTL_DAYS * DAY_IN_SECONDS;
		$state['client']     = array(
			'name'  => sanitize_text_field( $client['name'] ?? '' ),
			'phone' => sanitize_text_field( $client['phone'] ?? '' ),
			'email' => sanitize_email( $client['email'] ?? '' ),
		);
		$state['monday']     = array(
			'item' => sanitize_text_field( $monday['item'] ?? ( $state['monday']['item'] ?? '' ) ),
		);
		$state['reminders']  = array();
		$state['opened']     = 0;

		if ( $fresh ) {
			$state['activity']  = 0;
			$state['step']      = '';
			$state['far']       = '';
			$state['submitted'] = 0;
			$state['applied']   = 0;
			$state['report']    = array();
			Draft::clear();
		}

		self::save_state( $state );

		return self::url( $token );
	}

	/**
	 * The two things to start on today, for the last screen.
	 *
	 * The screen after "build my site" names only what needs no file and
	 * no folder: the clearing account, because it takes days, and the
	 * domain, because it is theirs to buy. Everything that wants a folder
	 * to upload into waits for the mail, which goes out once the folders
	 * exist. So this screen is complete the moment it is drawn and never
	 * waits on anybody.
	 *
	 * @return array<int,array{key:string,title:string,text:string,links:array<int,array{url:string,label:string}>}>
	 */
	public static function next(): array {
		$out  = array();
		$gaps = array();

		foreach ( self::gaps() as $g ) {
			$gaps[ $g['key'] ] = $g;
		}

		if ( isset( $gaps['payments'] ) && 'no_company' === $gaps['payments']['cause'] ) {
			$out[] = array(
				'key'   => 'payplus',
				'title' => __( 'Open a clearing account', 'oc-theme' ),
				'text'  => __( 'So the shop can take credit cards. We work with PayPlus, an Israeli clearing company that is already connected to your site: open the link, leave your details, and they call you back. Worth starting today — it is the one thing with a waiting time, and the shop cannot open without it.', 'oc-theme' ),
				'links' => array(
					array(
						'url'   => self::PAYPLUS,
						'label' => __( 'Open an account at PayPlus', 'oc-theme' ),
					),
				),
			);
		}

		$out[] = self::domain_step();

		return $out;
	}

	/**
	 * The domain, as a step: buy one, or tell us about the one you have.
	 *
	 * @return array{key:string,title:string,text:string,links:array<int,array{url:string,label:string}>}
	 */
	public static function domain_step(): array {
		if ( 'yes' === (string) Draft::value( 'existing_has' ) ) {
			return array(
				'key'   => 'domain',
				'title' => __( 'Your domain', 'oc-theme' ),
				'text'  => __( 'The shop opens at the domain you already have. We will send you a short document to fill in with where it is registered and the login for it, so we can point it at the new site when the time comes.', 'oc-theme' ),
				'links' => array(),
			);
		}

		return array(
			'key'   => 'domain',
			'title' => __( 'Buy your domain', 'oc-theme' ),
			'text'  => __( 'The address of the shop is yours to own. Register at LiveDNS, an Israeli registrar, then search for the name you want and buy it — it takes a few minutes. We will send you a short document to fill in with the login, so we can point the domain at the new site.', 'oc-theme' ),
			'links' => array(
				array(
					'url'   => Drive::LIVEDNS_REGISTER,
					'label' => __( 'Register at LiveDNS', 'oc-theme' ),
				),
				array(
					'url'   => Drive::LIVEDNS_BUY,
					'label' => __( 'Find and buy the domain', 'oc-theme' ),
				),
			),
		);
	}

	/**
	 * What is theirs to do, in their words, from the same facts the tile
	 * reads. Each item is a sentence, some carry a link (the one place to
	 * open a clearing account, say), and the ones that want a file name
	 * the Drive folder it goes in, so the mail can offer "upload here".
	 *
	 * @return array<int,array{text:string,url:string,link:string,folder:string}>
	 */
	public static function todo(): array {
		$out  = array();
		$gaps = array();

		foreach ( self::gaps() as $g ) {
			$gaps[ $g['key'] ] = $g;
		}

		$say = static function ( string $text, string $url = '', string $link = '', string $folder = '' ) use ( &$out ) {
			$out[] = array(
				'text'   => $text,
				'url'    => $url,
				'link'   => $link,
				'folder' => $folder,
			);
		};

		// Money first: nothing else they do matters until the shop can be
		// paid, and opening an account takes days, not minutes.
		if ( isset( $gaps['payments'] ) ) {
			switch ( $gaps['payments']['cause'] ) {
				case 'no_company':
					$say(
						__( 'Open a clearing account, so the shop can take credit cards. We work with PayPlus, an Israeli clearing company that is already connected to your site: open the link, leave your details, and they call you back. Worth starting today — it is the one thing with a waiting time, and the shop cannot open without it.', 'oc-theme' ),
						self::PAYPLUS,
						__( 'Open an account at PayPlus', 'oc-theme' )
					);
					break;
				case 'other_company':
					$say( __( 'Send us the details of your clearing company and we connect it. It is not one of the two we install ourselves, so that part is by hand.', 'oc-theme' ) );
					break;
				case 'fill_later':
					$say( __( 'Send us your clearing details — the terminal and the API keys from your clearing company. The gateway is installed and waiting for them, switched off until they are in.', 'oc-theme' ) );
					break;
			}
		}

		// The domain, second: it is the other thing that takes real time.
		$dom  = self::domain_step();
		$doc  = Drive::links()['doc'];
		$text = $dom['text'];

		if ( '' !== $doc ) {
			$text .= ' ' . __( 'The document is in your Drive folder.', 'oc-theme' );
		}

		$say( $text, '' !== $doc ? $doc : (string) ( $dom['links'][0]['url'] ?? '' ), '' !== $doc ? __( 'Domain details document', 'oc-theme' ) : (string) ( $dom['links'][0]['label'] ?? '' ) );

		if ( isset( $gaps['logo'] ) ) {
			$say( __( 'Upload your logo — the file itself, PNG with a see-through background or SVG, as large as you have it.', 'oc-theme' ), '', '', 'logo' );
		}

		if ( isset( $gaps['logolight'] ) ) {
			$say( __( 'And a light version of the logo, if you have one: your menu stands on the banner picture, and a dark logo cannot be seen there. No light version? We make one.', 'oc-theme' ), '', '', 'logo' );
		}

		if ( isset( $gaps['catpics'] ) ) {
			$names = (array) $gaps['catpics']['items'];
			$shown = array_slice( $names, 0, 8 );
			$more  = count( $names ) - count( $shown );

			$say(
				sprintf(
					/* translators: %s: the category names. */
					__( 'Upload one picture for each of these categories — it becomes the banner at the top of the category and its tile on the home page: %s', 'oc-theme' ),
					implode( ', ', $shown ) . ( $more > 0 ? sprintf( /* translators: %d: how many more. */ __( ' and %d more', 'oc-theme' ), $more ) : '' )
				),
				'',
				'',
				'catpics'
			);
		}

		$legal = array(
			'terms'   => __( 'the terms of sale', 'oc-theme' ),
			'privacy' => __( 'the privacy policy', 'oc-theme' ),
			'a11y'    => __( 'the accessibility statement', 'oc-theme' ),
		);

		foreach ( $legal as $key => $what ) {
			if ( ! isset( $gaps[ $key ] ) ) {
				continue;
			}

			if ( 'no_file' === $gaps[ $key ]['cause'] ) {
				/* translators: %s: which page. */
				$say( sprintf( __( 'Upload %s — you said you had your own, and the file did not arrive.', 'oc-theme' ), $what ), '', '', 'legal' );
			} elseif ( 'link_failed' === $gaps[ $key ]['cause'] ) {
				/* translators: %s: which page. */
				$say( sprintf( __( 'Upload the text of %s — we could not read it from your current site.', 'oc-theme' ), $what ), '', '', 'legal' );
			}
		}

		if ( isset( $gaps['about'] ) && 'link_failed' === ( $gaps['about']['cause'] ?? '' ) ) {
			$say( __( 'Upload the text for the About page — we could not read it from your current site.', 'oc-theme' ), '', '', 'about' );
		}

		if ( isset( $gaps['social'] ) ) {
			$say( __( 'Send us the links to your social profiles, so the footer and the thank-you page can point to them.', 'oc-theme' ) );
		}

		// A shop with nothing on its shelves is not a shop, and this is the
		// one thing nobody else can do for them.
		$say( __( 'Gather your products — the names, the prices and a picture of each — and put them in the products folder. There is a spreadsheet in it to fill in. That is the one thing we cannot do without you.', 'oc-theme' ), '', '', 'products' );

		return $out;
	}

	/**
	 * What still stands between this shop and going live.
	 *
	 * Read off the site itself, not off the answers: a logo uploaded by hand
	 * in Customize after the questionnaire counts, and a page somebody
	 * deleted since does not. Each gap says what is missing, why if we know,
	 * and where to put it right. The ones a shop cannot open without come
	 * first.
	 *
	 * @return array<int,array{key:string,must:bool,label:string,why:string,fix:string,cause:string,items:array<int,string>}>
	 */
	public static function gaps(): array {
		$out = array();
		$v   = static function ( string $id ) {
			return Draft::value( $id );
		};
		$add = static function ( string $key, bool $must, string $label, string $why, string $fix, string $cause = '', array $items = array() ) use ( &$out ) {
			$out[] = array(
				'key'   => $key,
				'must'  => $must,
				'label' => $label,
				'why'   => $why,
				'fix'   => $fix,
				// A word for the code to branch on; 'why' is for people.
				'cause' => $cause,
				// Names, where a count alone would not say what to send.
				'items' => $items,
			);
		};

		$page_ok = static function ( int $id ): bool {
			return $id > 0
				&& 'page' === get_post_type( $id )
				&& 'publish' === get_post_status( $id )
				&& ( '' !== trim( (string) get_post_field( 'post_content', $id ) ) || metadata_exists( 'post', $id, '_oc_sections' ) );
		};

		// --- the ones a shop cannot open without ---

		if ( ! get_theme_mod( 'custom_logo' ) ) {
			$add( 'logo', true, __( 'Logo', 'oc-theme' ), __( 'None was uploaded; the site is wearing its name in text.', 'oc-theme' ), admin_url( 'customize.php?autofocus[control]=custom_logo' ) );
		}

		$legal = array(
			'terms'   => array( (int) get_option( Legal\Terms::OPTION, 0 ), (string) $v( 'terms_mode' ), $v( 'terms_file' ), __( 'Terms of sale', 'oc-theme' ) ),
			'privacy' => array( (int) get_option( 'wp_page_for_privacy_policy', 0 ), (string) $v( 'privacy_mode' ), $v( 'privacy_file' ), __( 'Privacy policy', 'oc-theme' ) ),
			'a11y'    => array( (int) get_option( Legal\Accessibility::OPTION, 0 ), (string) $v( 'a11y_mode' ), $v( 'a11y_file' ), __( 'Accessibility statement', 'oc-theme' ) ),
		);

		foreach ( $legal as $key => $one ) {
			list( $id, $mode, $file, $label ) = $one;

			if ( $page_ok( $id ) ) {
				continue;
			}

			if ( 'upload' === $mode && empty( $file['id'] ) ) {
				$why   = __( 'They said they would upload their own and did not.', 'oc-theme' );
				$cause = 'no_file';
			} elseif ( 'link' === $mode ) {
				$why   = __( 'It was to be taken from their current site, and the page could not be read.', 'oc-theme' );
				$cause = 'link_failed';
			} else {
				$why   = __( 'The page is not on the site.', 'oc-theme' );
				$cause = 'missing';
			}

			$add( $key, true, $label, $why, $id > 0 ? get_edit_post_link( $id, 'raw' ) : admin_url( 'edit.php?post_type=page' ), $cause );
		}

		$ship = false;

		if ( class_exists( '\\WC_Shipping_Zones' ) ) {
			$zones   = \WC_Shipping_Zones::get_zones();
			$zones[] = array( 'zone_id' => 0 );

			foreach ( $zones as $z ) {
				foreach ( \WC_Shipping_Zones::get_zone( (int) $z['zone_id'] )->get_shipping_methods( true ) as $m ) {
					$ship = true;
				}
			}
		}

		if ( ! $ship ) {
			$add( 'shipping', true, __( 'Delivery', 'oc-theme' ), __( 'No delivery or collection is switched on, so nothing can be ordered.', 'oc-theme' ), admin_url( 'admin.php?page=wc-settings&tab=shipping' ) );
		}

		$pay = false;

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $g ) {
				// Cash at the door and a bank transfer are ways to be paid,
				// not ways to take a card: a shop with only those cannot
				// sell to a stranger on the internet.
				if ( 'yes' === $g->enabled && ! in_array( (string) $g->id, array( 'cod', 'bacs', 'cheque' ), true ) ) {
					$pay = true;
				}
			}
		}

		if ( ! $pay ) {
			$kept  = (array) get_option( 'oc_onboard_pay', array() );
			$gw    = (string) ( $kept['gateway'] ?? '' );
			$cause = ! empty( $kept['fill_later'] ) ? 'fill_later' : ( 'none' === $gw ? 'no_company' : ( 'other' === $gw ? 'other_company' : 'off' ) );
			$why   = array(
				'fill_later'    => __( 'They left the clearing details for later; the gateway is installed and off until they are in.', 'oc-theme' ),
				'no_company'    => __( 'They have no clearing company yet.', 'oc-theme' ),
				'other_company' => __( 'They use a clearing company we do not install ourselves.', 'oc-theme' ),
				'off'           => __( 'No way to pay is switched on.', 'oc-theme' ),
			);

			$add( 'payments', true, __( 'Taking the money', 'oc-theme' ), $why[ $cause ], admin_url( 'admin.php?page=wc-settings&tab=checkout' ), $cause );
		}

		$real = 0;
		$demo = 0;

		$all = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		foreach ( $all as $pid ) {
			if ( get_post_meta( (int) $pid, '_oc_demo', true ) ) {
				++$demo;
			} else {
				++$real;
			}
		}

		if ( 0 === $real ) {
			$add(
				'products',
				true,
				__( 'Products', 'oc-theme' ),
				$demo > 0 ? __( 'Only test products are on the shop.', 'oc-theme' ) : __( 'There are no products yet.', 'oc-theme' ),
				admin_url( 'edit.php?post_type=product' ),
				$demo > 0 ? 'demo_only' : 'none'
			);
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '' !== $host && ( str_ends_with( $host, '.mywebsite.co.il' ) || str_ends_with( $host, '.proginter.dev' ) ) ) {
			$want = trim( (string) ( self::state()['domain'] ?? '' ) );

			$add(
				'domain',
				true,
				__( 'The address', 'oc-theme' ),
				'' !== $want
					/* translators: %s: the domain they gave. */
					? sprintf( __( 'The site is still at its temporary address; it was to open at %s.', 'oc-theme' ), $want )
					: __( 'The site is still at its temporary address, and no domain was given.', 'oc-theme' ),
				'',
				'' !== $want ? 'ours' : 'no_domain'
			);
		}

		// --- worth doing, not a wall ---

		// The menu stands on the banner and the logo has no light version:
		// a dark mark on a dark photograph.
		if ( 'home' === (string) get_theme_mod( 'oc_header_transparent', 'none' ) && '' === (string) get_theme_mod( 'oc_logo_transparent', '' ) ) {
			$add( 'logolight', false, __( 'A light version of the logo', 'oc-theme' ), __( 'The menu stands on the banner, so the logo stands on the picture; a dark one cannot be seen there.', 'oc-theme' ), admin_url( 'customize.php?autofocus[control]=oc_logo_transparent' ) );
		}

		$c = class_exists( '\\OC\\Theme\\Contact' ) ? (array) Contact::settings() : array();

		$social = false;

		foreach ( array_keys( Contact::networks() ) as $net ) {
			if ( '' !== trim( (string) ( $c[ $net ] ?? '' ) ) ) {
				$social = true;
			}
		}

		if ( ! $social ) {
			$add( 'social', false, __( 'Social profiles', 'oc-theme' ), __( 'Not one was given; the footer and the thank-you page have nothing to link to.', 'oc-theme' ), admin_url( 'admin.php?page=oc-contact' ) );
		}

		if ( '' === trim( (string) ( $c['phone'] ?? '' ) ) ) {
			$add( 'phone', false, __( 'Phone', 'oc-theme' ), __( 'No phone number; the contact page and the product card have nobody to call.', 'oc-theme' ), admin_url( 'admin.php?page=oc-contact' ) );
		}

		$about = (int) ( self::state()['about_page'] ?? 0 );

		if ( ! $page_ok( $about ) ) {
			$mode = (string) $v( 'about_mode' );
			$why  = 'link' === $mode
				? __( 'It was to be taken from their current site, and the page could not be read.', 'oc-theme' )
				: ( 'write' === $mode && '' === trim( (string) $v( 'about_written' ) )
					? __( 'They gave points and the text was never written.', 'oc-theme' )
					: __( 'The page is not on the site.', 'oc-theme' ) );

			$add( 'about', false, __( 'About page', 'oc-theme' ), $why, $about > 0 ? get_edit_post_link( $about, 'raw' ) : admin_url( 'edit.php?post_type=page' ) );
		}

		$bare = array();
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		foreach ( is_wp_error( $cats ) ? array() : $cats as $cat ) {
			if ( (int) $cat->term_id !== (int) get_option( 'default_product_cat' ) && ! get_term_meta( (int) $cat->term_id, 'thumbnail_id', true ) ) {
				$bare[] = (string) $cat->name;
			}
		}

		if ( $bare ) {
			$n = count( $bare );

			/* translators: %d: how many categories have no picture. */
			$add( 'catpics', false, __( 'Category pictures', 'oc-theme' ), sprintf( _n( '%d category has no main picture, so no banner and no tile.', '%d categories have no main picture, so no banner and no tile.', $n, 'oc-theme' ), $n ), admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ), '', $bare );
		}

		return $out;
	}

	/**
	 * The link for a token.
	 *
	 * @param string $token The raw token.
	 */
	public static function url( string $token ): string {
		return home_url( '/start/' . rawurlencode( $token ) . '/' );
	}

	/**
	 * A token is stored only as a hash: the option is not the key.
	 *
	 * @param string $token The raw token.
	 */
	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Is this the current, unexpired, uncancelled token?
	 *
	 * @param string $token The raw token.
	 */
	public static function token_ok( string $token ): bool {
		$state = self::state();

		if ( '' === $token || '' === $state['token_hash'] ) {
			return false;
		}

		if ( 'cancelled' === $state['status'] || 'none' === $state['status'] ) {
			return false;
		}

		if ( $state['expires'] > 0 && time() > (int) $state['expires'] ) {
			return false;
		}

		return hash_equals( $state['token_hash'], self::hash( $token ) );
	}

	/**
	 * The provisioning secret: a constant in wp-config on the base site,
	 * cloned with it, known to the script that clones. The invite route
	 * answers to it and to nothing else.
	 *
	 * @param string $key What the request carried.
	 */
	public static function provision_ok( string $key ): bool {
		if ( ! defined( 'OC_PROVISION_KEY' ) || '' === (string) OC_PROVISION_KEY ) {
			return false;
		}

		return '' !== $key && hash_equals( (string) OC_PROVISION_KEY, $key );
	}

	/**
	 * The token on this request, wherever it rides: the route, a header,
	 * or a query parameter.
	 *
	 * @param \WP_REST_Request|null $req A REST request, when there is one.
	 */
	public static function request_token( ?\WP_REST_Request $req = null ): string {
		if ( $req ) {
			$t = (string) $req->get_header( 'x-oc-token' );

			if ( '' === $t ) {
				$t = (string) $req->get_param( 't' );
			}

			return sanitize_text_field( $t );
		}

		return sanitize_text_field( (string) get_query_var( self::QUERY ) );
	}

	/* ---------------------------------------------------------------- route */

	/**
	 * /start/<token>/ answers without a page behind it.
	 */
	public function route(): void {
		add_rewrite_rule( '^start/([A-Za-z0-9_-]{20,64})/?$', 'index.php?' . self::QUERY . '=$matches[1]', 'top' );

		// /quiz/ is our own short way to the screen, now that the menu no
		// longer shows it. Nobody else gets anything from it.
		add_rewrite_rule( '^quiz/?$', 'index.php?' . self::QUIZ . '=1', 'top' );

		if ( '3' !== (string) get_option( 'oc_onboard_rw' ) ) {
			flush_rewrite_rules();
			update_option( 'oc_onboard_rw', '3', false );
		}
	}

	/**
	 * /quiz/: straight to the screen for someone who may open it, the
	 * login first for someone who is not signed in, and nothing at all for
	 * anyone else.
	 */
	public function quiz(): void {
		if ( '1' !== (string) get_query_var( self::QUIZ ) ) {
			return;
		}

		nocache_headers();

		if ( current_user_can( 'manage_woocommerce' ) ) {
			wp_safe_redirect( Admin::url() );
			exit;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/quiz/' ) ) );
			exit;
		}

		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
	}

	/**
	 * The query var that carries the route.
	 *
	 * @param array<int,string> $vars Query vars.
	 * @return array<int,string>
	 */
	public function vars( array $vars ): array {
		$vars[] = self::QUERY;
		$vars[] = self::QUIZ;

		return $vars;
	}

	/**
	 * WordPress must not "fix" the address of a page it does not know.
	 *
	 * @param string|false $redirect Where canonical wants to go.
	 * @param string       $requested The address asked for.
	 * @return string|false
	 */
	public function no_canonical( $redirect, string $requested ) {
		return false !== strpos( $requested, '/start/' ) ? false : $redirect;
	}

	/**
	 * Whether this request is the questionnaire.
	 */
	public static function is_page(): bool {
		return '' !== (string) get_query_var( self::QUERY );
	}

	/* ---------------------------------------------------------------- misc */

	/**
	 * Note that the customer did something now.
	 *
	 * @param string $step The screen, when known.
	 */
	public static function touch( string $step = '' ): void {
		$state             = self::state();
		$state['activity'] = time();

		if ( ! $state['opened'] ) {
			$state['opened'] = time();
		}

		if ( '' !== $step ) {
			$state['step'] = $step;

			// How far they have been is not the same as where they are: a
			// customer who walks back to an early screen has still seen
			// everything up to here, and the menu says so.
			$order = Schema::screen_order();
			$here  = array_search( $step, $order, true );
			$was   = array_search( (string) ( $state['far'] ?? '' ), $order, true );

			if ( false !== $here && ( false === $was || $here > $was ) ) {
				$state['far'] = $step;
			}
		}

		self::save_state( $state );
	}

	/**
	 * Days since the link was minted, whole.
	 */
	public static function age_days(): int {
		$state = self::state();

		return $state['created'] ? (int) floor( ( time() - (int) $state['created'] ) / DAY_IN_SECONDS ) : 0;
	}
}
