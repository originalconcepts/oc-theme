<?php
/**
 * The apply engine: answers become settings, pages and records.
 *
 * Every field's target is written by a handler for its kind. A handler
 * is careful in one way above all: it never writes over a value that a
 * person changed by hand after the last apply. The log remembers what
 * the engine wrote last time; if the site now holds something else, the
 * field is skipped and the report says so. So a customer can re-open the
 * questionnaire and change one answer without undoing the team's work.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

use OC\Theme\Contact;
use OC\Theme\Legal;
use OC\Theme\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the answers into the site.
 */
final class Apply {

	const LOG = 'oc_onboard_log';

	/**
	 * The report being built: one row per target.
	 *
	 * @var array<int,array<string,string>>
	 */
	private $report = array();

	/**
	 * The answers, id => value (defaults where unanswered).
	 *
	 * @var array<string,mixed>
	 */
	private $v = array();

	/**
	 * What the engine wrote last time, target key => value.
	 *
	 * @var array<string,mixed>
	 */
	private $log = array();

	/**
	 * Which `call` handlers already ran this pass.
	 *
	 * @var array<string,bool>
	 */
	private $ran = array();

	/**
	 * Run the whole thing.
	 *
	 * @return array<int,array<string,string>> The report rows.
	 */
	public function run(): array {
		$this->report = array();
		$this->ran    = array();
		$this->log    = self::log_all();

		foreach ( Schema::fields() as $id => $f ) {
			$this->v[ $id ] = Draft::value( $id );
		}

		foreach ( Schema::fields() as $id => $f ) {
			if ( null === $f['target'] || ! Schema::shown( $id, $this->v ) ) {
				continue;
			}

			$target = (array) $f['target'];
			$kind   = (string) ( $target[0] ?? '' );

			try {
				switch ( $kind ) {
					case 'mod':
						$this->write_mod( $id, (string) $target[1], $this->v[ $id ] );
						break;

					case 'option':
						$this->write_option_key( $id, (string) $target[1], (string) $target[2], $this->v[ $id ] );
						break;

					case 'woo':
						$this->write_plain( $id, (string) $target[1], $this->v[ $id ] );
						break;

					case 'state':
						Onboard::patch_state( array( (string) $target[1] => $this->v[ $id ] ) );
						$this->row( $id, __( 'Invitation record', 'oc-theme' ), 'applied' );
						break;

					case 'call':
						$name = (string) $target[1];

						if ( empty( $this->ran[ $name ] ) && method_exists( $this, 'apply_' . $name ) ) {
							$this->ran[ $name ] = true;
							$this->{'apply_' . $name}();
						}
						break;
				}
			} catch ( \Throwable $e ) {
				$this->row( $id, $kind, 'error', $e->getMessage() );
			}
		}

		update_option( self::LOG, $this->log, false );

		self::flush_caches();

		return $this->report;
	}

	/* ------------------------------------------------------------ report */

	/**
	 * One line in the report.
	 *
	 * @param string $id     Field id (or a handler name).
	 * @param string $target What was written, in words.
	 * @param string $result applied | skipped | manual | check | error.
	 * @param string $note   Why, when it matters.
	 */
	private function row( string $id, string $target, string $result, string $note = '' ): void {
		$f = Schema::field( $id );

		$this->report[] = array(
			'id'     => $id,
			'label'  => $f ? (string) $f['label'] : $id,
			'target' => $target,
			'result' => $result,
			'note'   => $note,
		);
	}

	/**
	 * The log as stored.
	 *
	 * @return array<string,mixed>
	 */
	public static function log_all(): array {
		$saved = get_option( self::LOG );

		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Was this target changed by a person since we last wrote it?
	 *
	 * @param string $key     Target key in the log.
	 * @param mixed  $current What the site holds now.
	 */
	private function changed_by_hand( string $key, $current ): bool {
		if ( ! array_key_exists( $key, $this->log ) ) {
			return false;
		}

		return self::norm( $this->log[ $key ] ) !== self::norm( $current );
	}

	/**
	 * Values compared as strings, so '1' and 1 and true agree.
	 *
	 * @param mixed $v Value.
	 */
	private static function norm( $v ): string {
		if ( is_bool( $v ) ) {
			return $v ? '1' : '';
		}

		if ( is_array( $v ) ) {
			return (string) wp_json_encode( $v );
		}

		return trim( (string) $v );
	}

	/**
	 * Remember what we wrote.
	 *
	 * @param string $key   Target key.
	 * @param mixed  $value Value written.
	 */
	private function remember( string $key, $value ): void {
		$this->log[ $key ] = $value;
	}

	/* ------------------------------------------------------------ kinds */

	/**
	 * A theme mod.
	 *
	 * @param string $id    Field id.
	 * @param string $key   Mod key.
	 * @param mixed  $value Value.
	 */
	private function write_mod( string $id, string $key, $value ): void {
		$lk = 'mod:' . $key;

		// Yes/no choices land in flag mods as 1/0.
		if ( 'yes' === $value || 'no' === $value ) {
			$value = 'yes' === $value ? 1 : 0;
		}

		if ( $this->changed_by_hand( $lk, get_theme_mod( $key ) ) ) {
			$this->row( $id, $key, 'manual', __( 'Changed by hand since the last apply; left as it is.', 'oc-theme' ) );
			return;
		}

		set_theme_mod( $key, $value );
		$this->remember( $lk, $value );
		$this->row( $id, $key, 'applied' );
	}

	/**
	 * One key inside a serialised option.
	 *
	 * @param string $id     Field id.
	 * @param string $option Option name.
	 * @param string $key    Key inside it.
	 * @param mixed  $value  Value.
	 */
	private function write_option_key( string $id, string $option, string $key, $value ): void {
		$lk  = 'opt:' . $option . '.' . $key;
		$all = get_option( $option );
		$all = is_array( $all ) ? $all : array();

		if ( 'oc_contact' === $option ) {
			$all = Contact::settings();
		} elseif ( 'oc_thankyou' === $option ) {
			$all = \OC\Theme\Thankyou::settings();
		}

		// Yes/no choices land in flag options as 1/0.
		if ( 'yes' === $value || 'no' === $value ) {
			$value = 'yes' === $value ? 1 : 0;
		}

		if ( $this->changed_by_hand( $lk, $all[ $key ] ?? null ) ) {
			$this->row( $id, $option . ' → ' . $key, 'manual', __( 'Changed by hand since the last apply; left as it is.', 'oc-theme' ) );
			return;
		}

		$all[ $key ] = $value;
		update_option( $option, $all );
		$this->remember( $lk, $value );
		$this->row( $id, $option . ' → ' . $key, 'applied' );
	}

	/**
	 * A plain option (WooCommerce and WordPress settings).
	 *
	 * @param string $id    Field id.
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	private function write_plain( string $id, string $name, $value ): void {
		$lk = 'plain:' . $name;

		if ( $this->changed_by_hand( $lk, get_option( $name ) ) ) {
			$this->row( $id, $name, 'manual', __( 'Changed by hand since the last apply; left as it is.', 'oc-theme' ) );
			return;
		}

		update_option( $name, $value );
		$this->remember( $lk, $value );
		$this->row( $id, $name, 'applied' );
	}

	/* ------------------------------------------------------------ handlers */

	/**
	 * The brand name: the site title and the sender of every email.
	 */
	private function apply_brand_name(): void {
		$name = trim( (string) $this->v['brand_name'] );

		if ( '' === $name ) {
			return;
		}

		$this->write_plain( 'brand_name', 'blogname', $name );
		$this->write_plain( 'brand_name', 'woocommerce_email_from_name', $name );
	}

	/**
	 * The service email: shown on the site and the sender address.
	 */
	private function apply_email_service(): void {
		$email = (string) $this->v['email_service'];

		if ( '' === $email ) {
			return;
		}

		$this->write_option_key( 'email_service', 'oc_contact', 'email', $email );
		$this->write_plain( 'email_service', 'woocommerce_email_from_address', $email );
	}

	/**
	 * Where new-order notices go.
	 */
	private function apply_email_orders(): void {
		$email = (string) $this->v['email_orders'];

		if ( '' === $email ) {
			$email = (string) $this->v['email_service'];
		}

		if ( '' === $email ) {
			return;
		}

		$lk  = 'plain:woocommerce_new_order_settings.recipient';
		$all = get_option( 'woocommerce_new_order_settings' );
		$all = is_array( $all ) ? $all : array();

		if ( $this->changed_by_hand( $lk, $all['recipient'] ?? null ) ) {
			$this->row( 'email_orders', 'woocommerce_new_order_settings → recipient', 'manual', __( 'Changed by hand since the last apply; left as it is.', 'oc-theme' ) );
			return;
		}

		$all['recipient'] = $email;
		update_option( 'woocommerce_new_order_settings', $all );
		$this->remember( $lk, $email );
		$this->row( 'email_orders', 'woocommerce_new_order_settings → recipient', 'applied' );
	}

	/**
	 * A store open to the public, or not.
	 */
	private function apply_has_store(): void {
		$this->write_option_key( 'has_store', 'oc_contact', 'a11y_physical', 'yes' === $this->v['has_store'] ? 1 : 0 );
	}

	/**
	 * The address: the store details and WooCommerce's own fields.
	 */
	private function apply_address(): void {
		$street = trim( (string) $this->v['address_street'] );
		$city   = trim( (string) $this->v['address_city'] );

		if ( '' === $street && '' === $city ) {
			return;
		}

		$this->write_option_key( 'address_street', 'oc_contact', 'address', trim( $street . ( $city ? ', ' . $city : '' ), ', ' ) );
		$this->write_plain( 'address_street', 'woocommerce_store_address', $street );
		$this->write_plain( 'address_city', 'woocommerce_store_city', $city );
	}

	/**
	 * Opening hours: the text lines for the store details, and the
	 * product contact card's days and window.
	 */
	private function apply_hours(): void {
		$rows = (array) $this->v['hours'];

		if ( empty( $rows ) ) {
			return;
		}

		$this->write_option_key( 'hours', 'oc_contact', 'hours', self::hours_text( $rows ) );

		$days = array();
		$from = '';
		$to   = '';

		foreach ( $rows as $row ) {
			$days = array_merge( $days, (array) $row['days'] );
			$from = '' === $from || $row['from'] < $from ? (string) $row['from'] : $from;
			$to   = '' === $to || $row['to'] > $to ? (string) $row['to'] : $to;
		}

		$days = array_values( array_unique( array_map( 'intval', $days ) ) );
		sort( $days );

		$this->write_mod( 'hours', 'oc_contact_days', implode( ',', $days ) );
		$this->write_mod( 'hours', 'oc_contact_from', $from );
		$this->write_mod( 'hours', 'oc_contact_to', $to );
	}

	/**
	 * Hour rows as the lines a person would write: "Sunday–Thursday 09:00–18:00".
	 *
	 * @param array<int,array{days:array<int,int>,from:string,to:string}> $rows Rows.
	 */
	public static function hours_text( array $rows ): string {
		global $wp_locale;

		$lines = array();

		foreach ( $rows as $row ) {
			$days = array_values( array_unique( array_map( 'intval', (array) $row['days'] ) ) );
			sort( $days );

			if ( ! $days ) {
				continue;
			}

			// Runs of consecutive days read as a range.
			$parts = array();
			$start = $days[0];
			$prev  = $days[0];

			for ( $i = 1, $n = count( $days ); $i <= $n; $i++ ) {
				$d = $days[ $i ] ?? null;

				if ( null !== $d && $d === $prev + 1 ) {
					$prev = $d;
					continue;
				}

				$a = $wp_locale->get_weekday( $start );
				$b = $wp_locale->get_weekday( $prev );

				$parts[] = $start === $prev ? $a : $a . '–' . $b;

				if ( null !== $d ) {
					$start = $d;
					$prev  = $d;
				}
			}

			$lines[] = implode( ', ', $parts ) . ' ' . $row['from'] . '–' . $row['to'];
		}

		return implode( "\n", $lines );
	}


	/**
	 * One store: the premises checklist on the store details.
	 */
	private function apply_a11y_access(): void {
		$ticked = (array) $this->v['a11y_access'];
		$value  = array();

		foreach ( array_keys( Contact::access_items() ) as $key ) {
			$value[ $key ] = in_array( $key, $ticked, true ) ? 1 : 0;
		}

		$this->write_option_key( 'a11y_access', 'oc_contact', 'a11y_access', $value );
	}

	/**
	 * Several branches: the branches module switches on and each branch
	 * becomes a post with its own accessibility checklist.
	 */
	private function apply_branches(): void {
		if ( 'many' !== $this->v['branches_mode'] ) {
			return;
		}

		if ( ! class_exists( '\\OC\\Blocks\\Branches' ) || ! post_type_exists( 'oc_branch' ) ) {
			$this->row( 'branches', 'oc_branch', 'check', __( 'The branches module is not installed; the branches were not created.', 'oc-theme' ) );
			return;
		}

		$rows = (array) $this->v['branches'];

		if ( empty( $rows ) ) {
			return;
		}

		update_option( \OC\Blocks\Branches::OPTION, array( 'menu' => 1 ) );

		$made       = 0;
		$kept       = (array) ( $this->log['branches:ids'] ?? array() );
		$ids        = array();
		$per_branch = (array) $this->v['branch_access'];

		foreach ( $rows as $i => $row ) {
			$name = trim( (string) ( $row['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$existing = (int) ( $kept[ $i ] ?? 0 );

			if ( $existing && 'oc_branch' === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) {
				$id = wp_update_post(
					array(
						'ID'         => $existing,
						'post_title' => $name,
					),
					true
				);
			} else {
				$id = wp_insert_post(
					array(
						'post_type'   => 'oc_branch',
						'post_status' => 'publish',
						'post_title'  => $name,
					),
					true
				);
			}

			if ( ! is_wp_error( $id ) && '' !== trim( (string) ( $row['about'] ?? '' ) ) ) {
				wp_update_post(
					array(
						'ID'           => (int) $id,
						'post_content' => wpautop( esc_html( (string) $row['about'] ) ),
					)
				);
			}

			if ( is_wp_error( $id ) || (int) $id < 1 ) {
				continue;
			}

			$id     = (int) $id;
			$ids[]  = $id;
			$ticked = (array) ( $per_branch[ $i ] ?? $per_branch[ (string) $i ] ?? array() );
			$access = array();

			foreach ( array_keys( Contact::access_items() ) as $key ) {
				$access[ $key ] = in_array( $key, $ticked, true ) ? 1 : 0;
			}

			update_post_meta( $id, '_oc_br_address', (string) ( $row['address'] ?? '' ) );
			update_post_meta( $id, '_oc_br_city', (string) ( $row['city'] ?? '' ) );
			update_post_meta( $id, '_oc_br_phone', (string) ( $row['phone'] ?? '' ) );
			update_post_meta( $id, '_oc_br_hours', (string) ( $row['hours'] ?? '' ) );
			update_post_meta( $id, '_oc_br_access', $access );
			update_post_meta( $id, '_oc_br_pickup', '1' );

			$picture = $row['image'] ?? null;

			if ( is_array( $picture ) && ! empty( $picture['id'] ) ) {
				set_post_thumbnail( $id, (int) $picture['id'] );
			}

			++$made;
		}

		$this->remember( 'branches:ids', $ids );
		$this->row( 'branches', 'oc_branch', 'applied', sprintf( /* translators: %d: number of branches */ _n( '%d branch', '%d branches', $made, 'oc-theme' ), $made ) );

		$this->branches_page();
	}

	/**
	 * The About page.
	 */
	private function apply_about(): void {
		$mode = (string) $this->v['about_mode'];

		if ( 'link' === $mode ) {
			$id = $this->page_from_url( 'about', __( 'About us', 'oc-theme' ), (string) $this->v['about_url'] );

			$this->row( 'about_url', __( 'About page', 'oc-theme' ), $id ? 'check' : 'error', $id ? __( 'Taken from the current site; read it over once.', 'oc-theme' ) : __( 'The page could not be read from that address.', 'oc-theme' ) );

			if ( $id ) {
				Onboard::patch_state( array( 'about_page' => $id ) );
			}

			return;
		}

		$text = 'paste' === $mode ? (string) $this->v['about_text'] : (string) $this->v['about_points'];

		if ( '' === trim( $text ) ) {
			return;
		}

		$image = $this->v['about_image'];
		$img   = is_array( $image ) ? (int) $image['id'] : 0;
		$id    = $this->page( 'about', __( 'About us', 'oc-theme' ), wpautop( esc_html( $text ) ), $img );

		if ( 'write' === $mode ) {
			$this->row( 'about_points', __( 'About page', 'oc-theme' ), 'check', __( 'The customer gave points, not a text: the page holds the points and needs writing.', 'oc-theme' ) );
		} else {
			$this->row( 'about_text', __( 'About page', 'oc-theme' ), $id ? 'applied' : 'error' );
		}

		Onboard::patch_state( array( 'about_page' => $id ) );
	}

	/**
	 * The terms of sale: the template, with the customer's consent on
	 * record, or the file they uploaded as a page.
	 */
	private function apply_legal_terms(): void {
		if ( 'link' === $this->v['terms_mode'] ) {
			$id = $this->page_from_url( 'terms', __( 'Terms of sale', 'oc-theme' ), (string) $this->v['terms_url'] );

			$this->terms_page_wired( $id );
			$this->row( 'terms_url', __( 'Terms page', 'oc-theme' ), $id ? 'check' : 'error', $id ? __( 'Taken from the current site; read it over once.', 'oc-theme' ) : __( 'The page could not be read from that address.', 'oc-theme' ) );

			return;
		}

		if ( 'upload' === $this->v['terms_mode'] ) {
			$id = $this->page_from_file( 'terms', __( 'Terms of sale', 'oc-theme' ), $this->v['terms_file'] );

			$this->terms_page_wired( $id );

			$this->row( 'terms_file', __( 'Terms page', 'oc-theme' ), $id ? 'check' : 'error', __( 'Written from the uploaded file; check the layout once.', 'oc-theme' ) );
			return;
		}

		if ( true !== $this->v['terms_consent'] ) {
			$this->row( 'terms_consent', __( 'Terms page', 'oc-theme' ), 'skipped', __( 'The customer did not confirm the template terms.', 'oc-theme' ) );
			return;
		}

		$options = array(
			'terms_kind'   => 'food' === $this->v['terms_kind'] ? 'food' : 'general',
			'terms_custom' => 'yes' === $this->v['terms_custom'] ? 1 : 0,
			'terms_bulky'  => 'yes' === $this->v['terms_bulky'] ? 1 : 0,
		);

		update_option( 'oc_contact', array_merge( Contact::settings(), $options ) );

		$this->consent( 'terms', $options );

		$id = Legal\Terms::create_page();

		$this->row( 'terms_consent', __( 'Terms page', 'oc-theme' ), $id ? 'check' : 'error', __( 'Template written; read it once before going live.', 'oc-theme' ) );
	}

	/**
	 * The privacy policy.
	 */
	private function apply_legal_privacy(): void {
		if ( 'link' === $this->v['privacy_mode'] ) {
			$id = $this->page_from_url( 'privacy-policy', __( 'Privacy policy', 'oc-theme' ), (string) $this->v['privacy_url'] );

			$this->privacy_page_wired( $id );
			$this->row( 'privacy_url', __( 'Privacy page', 'oc-theme' ), $id ? 'check' : 'error', $id ? __( 'Taken from the current site; read it over once.', 'oc-theme' ) : __( 'The page could not be read from that address.', 'oc-theme' ) );

			return;
		}

		if ( 'upload' === $this->v['privacy_mode'] ) {
			$id = $this->page_from_file( 'privacy-policy', __( 'Privacy policy', 'oc-theme' ), $this->v['privacy_file'] );

			$this->privacy_page_wired( $id );

			$this->row( 'privacy_file', __( 'Privacy page', 'oc-theme' ), $id ? 'check' : 'error', __( 'Written from the uploaded file; check the layout once.', 'oc-theme' ) );
			return;
		}

		if ( true !== $this->v['privacy_consent'] ) {
			$this->row( 'privacy_consent', __( 'Privacy page', 'oc-theme' ), 'skipped', __( 'The customer did not confirm the template terms.', 'oc-theme' ) );
			return;
		}

		$this->consent( 'privacy', array() );

		$id = Privacy\Policy::create_page();

		$this->row( 'privacy_consent', __( 'Privacy page', 'oc-theme' ), $id ? 'check' : 'error', __( 'Template written; read it once before going live.', 'oc-theme' ) );
	}

	/**
	 * The accessibility statement.
	 */
	private function apply_legal_a11y(): void {
		if ( 'link' === $this->v['a11y_mode'] ) {
			$id = $this->page_from_url( 'accessibility-statement', __( 'Accessibility statement', 'oc-theme' ), (string) $this->v['a11y_url'] );

			$this->a11y_page_wired( $id );
			$this->row( 'a11y_url', __( 'Accessibility page', 'oc-theme' ), $id ? 'check' : 'error', $id ? __( 'Taken from the current site; read it over once.', 'oc-theme' ) : __( 'The page could not be read from that address.', 'oc-theme' ) );

			return;
		}

		if ( 'upload' === $this->v['a11y_mode'] ) {
			$id = $this->page_from_file( 'accessibility-statement', __( 'Accessibility statement', 'oc-theme' ), $this->v['a11y_file'] );

			$this->a11y_page_wired( $id );

			$this->row( 'a11y_file', __( 'Accessibility page', 'oc-theme' ), $id ? 'check' : 'error', __( 'Written from the uploaded file; check the layout once.', 'oc-theme' ) );
			return;
		}

		if ( true !== $this->v['a11y_consent'] ) {
			$this->row( 'a11y_consent', __( 'Accessibility page', 'oc-theme' ), 'skipped', __( 'The customer did not confirm the template terms.', 'oc-theme' ) );
			return;
		}

		$this->consent( 'accessibility', array() );

		$id = Legal\Accessibility::create_page();

		$this->row( 'a11y_consent', __( 'Accessibility page', 'oc-theme' ), $id ? 'check' : 'error', __( 'Template written; read it once before going live.', 'oc-theme' ) );
	}

	/**
	 * The page that shows them all, built from the branches block so the
	 * team can rearrange it in the composer like any other page.
	 */
	private function branches_page(): void {
		if ( ! class_exists( '\\OC\\Blocks\\Registry' ) ) {
			return;
		}

		$id = $this->page( 'branches', __( 'Our branches', 'oc-theme' ), '' );

		if ( ! $id ) {
			return;
		}

		$sections = \OC\Blocks\Registry::clean(
			array(
				array(
					'type'    => 'branches',
					'heading' => __( 'Our branches', 'oc-theme' ),
					'source'  => 'all',
					'map'     => 1,
					'search'  => 1,
				),
			)
		);

		update_post_meta( $id, \OC\Blocks\Registry::META, $sections );

		$this->row( 'branches', __( 'Branches page', 'oc-theme' ), 'applied' );
	}

	/**
	 * A terms page, wherever it came from, becomes the checkout's terms page
	 * unless the shop already points at one we did not write.
	 *
	 * @param int $id The page.
	 */
	private function terms_page_wired( int $id ): void {
		if ( ! $id ) {
			return;
		}

		// The theme keeps its own note of which page is the terms page: the
		// settings screen reads it, and without it the screen would offer to
		// write a second one next to the page we just made.
		update_option( Legal\Terms::OPTION, $id, false );

		if ( ! (int) get_option( 'woocommerce_terms_page_id' ) ) {
			update_option( 'woocommerce_terms_page_id', $id );
		}
	}

	/**
	 * The privacy page WordPress itself points at.
	 *
	 * @param int $id The page.
	 */
	private function privacy_page_wired( int $id ): void {
		if ( $id ) {
			update_option( 'wp_page_for_privacy_policy', $id );
		}
	}

	/**
	 * The statement the footer links to.
	 *
	 * @param int $id The page.
	 */
	private function a11y_page_wired( int $id ): void {
		if ( $id ) {
			update_option( Legal\Accessibility::OPTION, $id, false );
		}
	}

	/**
	 * A page whose body is read off the customer's current site.
	 *
	 * @param string $slug  Page slug.
	 * @param string $title Title.
	 * @param string $url   The address on their site.
	 * @return int The page id, 0 when nothing could be read.
	 */
	private function page_from_url( string $slug, string $title, string $url ): int {
		if ( '' === trim( $url ) ) {
			return 0;
		}

		$got = Fetch::article( $url );

		if ( '' === trim( (string) $got['html'] ) ) {
			return 0;
		}

		return $this->page( $slug, '' !== $got['title'] ? $got['title'] : $title, $got['html'] );
	}

	/**
	 * The home page: a front page, composed of blocks, from the recipe the
	 * customer arranged. The pictures and the words are asked for on the
	 * screens after it; this is the shape of the page.
	 */
	private function apply_home(): void {
		if ( ! class_exists( '\\OC\\Blocks\\Registry' ) ) {
			$this->row( 'home_layout', __( 'Home page', 'oc-theme' ), 'skipped', __( 'The blocks plugin is not active on this site.', 'oc-theme' ) );
			return;
		}

		$id = $this->front_page();

		if ( ! $id ) {
			$this->row( 'home_layout', __( 'Home page', 'oc-theme' ), 'error' );
			return;
		}

		$sections = \OC\Blocks\Registry::clean( $this->recipe() );

		if ( $this->changed_by_hand( 'home:sections', wp_json_encode( (array) get_post_meta( $id, \OC\Blocks\Registry::META, true ) ) ) ) {
			$this->row( 'home_layout', __( 'Home page', 'oc-theme' ), 'manual', __( 'The home page was edited by hand since the last apply; left as it is.', 'oc-theme' ) );
			return;
		}

		update_post_meta( $id, \OC\Blocks\Registry::META, $sections );
		$this->remember( 'home:sections', wp_json_encode( $sections ) );

		update_option( 'oc_blocks_ver', (int) get_option( 'oc_blocks_ver', 0 ) + 1, false );

		$this->row( 'home_layout', __( 'Home page', 'oc-theme' ), 'check', __( 'The page is laid out and waiting for its pictures.', 'oc-theme' ) );
	}

	/**
	 * The page the site opens on, made if there is none.
	 */
	private function front_page(): int {
		$id = (int) get_option( 'page_on_front' );

		if ( $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
			return $id;
		}

		$id = $this->page( 'home', __( 'Home', 'oc-theme' ), '' );

		if ( $id ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $id );
		}

		return $id;
	}

	/**
	 * The page, as the customer arranged it: one oc-blocks section per row
	 * they kept, in their order. A row they hid is left out rather than
	 * written switched off, so the page stays as short as they made it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function recipe(): array {
		$rows = $this->v['home_layout'];
		$rows = is_array( $rows ) ? $rows : array();
		$out  = array();
		$nth  = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['on'] ) ) {
				continue;
			}

			$type         = (string) ( $row['type'] ?? '' );
			$nth[ $type ] = ( $nth[ $type ] ?? 0 ) + 1;

			$made = $this->band( (array) $row, $nth[ $type ] );

			if ( $made ) {
				$out[] = $made;
			}
		}

		return $out;
	}

	/**
	 * One row of the arrangement, as a section.
	 *
	 * @param array<string,mixed> $row The row.
	 * @param int                 $nth Which one of its kind this is, from one.
	 * @return array<string,mixed>|null
	 */
	private function band( array $row, int $nth = 1 ) {
		$type  = (string) ( $row['type'] ?? '' );
		$title = trim( (string) ( $row['title'] ?? '' ) );

		if ( 'banner' === $type ) {
			return $this->hero_section();
		}

		if ( 'marquee' === $type ) {
			return array(
				'type' => 'marquee',
				'text' => trim( (string) ( $row['text'] ?? '' ) ),
			);
		}

		if ( 'products' === $type ) {
			// Which shelf this is, is theirs to say. Said nothing, they get
			// the shelf the sketch showed them in that place on the page:
			// the newest first, then whatever is on offer.
			$mode = (string) ( $row['variant'] ?? '' );
			$fall = $nth > 1 ? 'sale' : 'new';

			return array(
				'type'    => 'products',
				'heading' => $title,
				'mode'    => in_array( $mode, array( 'new', 'sale', 'sales', 'manual' ), true ) ? $mode : $fall,
				'count'   => 8,
				'layout'  => 'slider',
			);
		}

		if ( 'categories' === $type ) {
			return array(
				'type'    => 'categories',
				'heading' => $title,
				'layout'  => 'slider',
			);
		}

		if ( 'look' === $type ) {
			return array(
				'type'   => 'look',
				'scenes' => array(
					array( 'heading' => $title ),
				),
			);
		}

		if ( 'posts' === $type ) {
			return array(
				'type'    => 'posts',
				'heading' => $title,
				'mode'    => 'latest',
				'count'   => 3,
			);
		}

		if ( 'icons' === $type ) {
			return array(
				'type'  => 'icons',
				'items' => array(
					array(
						'icon'    => 'truck',
						'heading' => __( 'Delivery across the country', 'oc-theme' ),
						'text'    => __( 'Write here how long it takes and from what amount it is free.', 'oc-theme' ),
					),
					array(
						'icon'    => 'returns',
						'heading' => __( 'Returns within 14 days', 'oc-theme' ),
						'text'    => __( 'The short version of what your terms say.', 'oc-theme' ),
					),
					array(
						'icon'    => 'shield',
						'heading' => __( 'A secure purchase', 'oc-theme' ),
						'text'    => __( 'Payment through a certified clearing house.', 'oc-theme' ),
					),
				),
			);
		}

		if ( 'brands' === $type ) {
			return array(
				'type'    => 'brands',
				'heading' => $title,
			);
		}

		if ( 'faq' === $type ) {
			return array(
				'type'    => 'faq',
				'heading' => $title,
				'items'   => array(
					array(
						'q' => __( 'How long does delivery take?', 'oc-theme' ),
						'a' => '',
					),
					array(
						'q' => __( 'Can I return a product?', 'oc-theme' ),
						'a' => '',
					),
				),
			);
		}

		if ( 'scrolly' === $type ) {
			return array(
				'type'  => 'scrolly',
				'steps' => array(
					array( 'heading' => $title ),
				),
			);
		}

		if ( 'content' === $type ) {
			return $this->content_section( (string) ( $row['variant'] ?? 'words' ) );
		}

		return null;
	}

	/**
	 * The banner, from the answers given on its own screen.
	 *
	 * @return array<string,mixed>
	 */
	private function hero_section(): array {
		$brand = trim( (string) $this->v['brand_name'] );
		$shot  = $this->v['home_banner'];
		$film  = 'video' === $this->v['banner_media'] ? trim( (string) $this->v['banner_video'] ) : '';
		$shop  = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' );
		$where = 'url' === $this->v['banner_link'] ? trim( (string) $this->v['banner_url'] ) : '';
		$head  = trim( (string) $this->v['banner_title'] );
		$press = trim( (string) $this->v['banner_cta'] );

		return array(
			'type'   => 'hero',
			'slides' => array(
				array(
					'img'     => '' === $film && is_array( $shot ) ? (int) $shot['id'] : 0,
					'vid'     => $film,
					'heading' => '' !== $head ? $head : $brand,
					'cta'     => '' !== $press ? $press : __( 'To the shop', 'oc-theme' ),
					'url'     => '' !== $where ? $where : $shop,
				),
			),
			'pos'    => 'cc',
			'h'      => 560,
			'hm'     => 440,
		);
	}

	/**
	 * A content area, in the shape the customer chose for it. The words and
	 * the pictures are asked for on a later screen; this lays out the band.
	 *
	 * @param string $kind words | single | overlap | duo | canvas.
	 * @return array<string,mixed>
	 */
	private function content_section( string $kind ): array {
		$brand = trim( (string) $this->v['brand_name'] );
		$head  = '' !== $brand ? sprintf( /* translators: %s: the brand name. */ __( 'About %s', 'oc-theme' ), $brand ) : __( 'About us', 'oc-theme' );
		$text  = wp_trim_words( wp_strip_all_tags( (string) $this->v['about_text'] ), 45 );

		if ( 'words' === $kind ) {
			return array(
				'type'    => 'content',
				'heading' => $head,
				'text'    => $text,
				'align'   => 'center',
			);
		}

		return array(
			'type'    => 'media',
			'preset'  => in_array( $kind, array( 'single', 'overlap', 'duo', 'canvas' ), true ) ? $kind : 'overlap',
			'heading' => $head,
			'text'    => $text,
		);
	}

	/**
	 * A "delivery and returns" tab on every product, opened with a short
	 * text the shop can rewrite. One row in the tabs option, written once.
	 */
	private function apply_ship_tab(): void {
		if ( 'yes' !== $this->v['prod_ship_tab'] || ! class_exists( '\\OC\\Theme\\Tabs' ) ) {
			return;
		}

		$all    = \OC\Theme\Tabs::settings();
		$custom = isset( $all['custom'] ) && is_array( $all['custom'] ) ? $all['custom'] : array();
		$uid    = (string) ( $this->log['tab:ship'] ?? '' );

		if ( '' !== $uid && isset( $custom[ $uid ] ) ) {
			$this->row( 'prod_ship_tab', __( 'Delivery and returns tab', 'oc-theme' ), 'skipped', __( 'The tab is already there.', 'oc-theme' ) );
			return;
		}

		$uid = bin2hex( random_bytes( 16 ) );

		$custom[ $uid ] = array(
			'on'      => 1,
			'order'   => 30,
			'title'   => __( 'Delivery and returns', 'oc-theme' ),
			'content' => wpautop(
				__( 'Delivery across the country. Write here how long an order takes to arrive and from what amount delivery is free.', 'oc-theme' ) . "\n\n" .
				__( 'Returns within 14 days of receiving the order, as long as the product has not been used and its packaging is whole. The full details are in the terms of sale.', 'oc-theme' )
			),
			'scope'   => 'all',
			'ids'     => '',
			'cats'    => array(),
			'attrs'   => array(),
			'ex_cats' => array(),
			'ex_ids'  => '',
		);

		$all['custom'] = $custom;

		update_option( 'oc_tabs', $all );
		$this->remember( 'tab:ship', $uid );

		$this->row( 'prod_ship_tab', __( 'Delivery and returns tab', 'oc-theme' ), 'check', __( 'Written with a general text — read it over and make it yours.', 'oc-theme' ) );
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * The customer's confirmation, recorded once per questionnaire: a
	 * second apply rewrites the page but does not log a second consent.
	 *
	 * @param string              $kind    terms | privacy | accessibility.
	 * @param array<string,mixed> $options Choices made with it.
	 */
	private function consent( string $kind, array $options ): void {
		$lk = 'consent:' . $kind;

		if ( ! empty( $this->log[ $lk ] ) ) {
			return;
		}

		Legal\Consent::record( $kind, $options, $this->actor() );
		$this->remember( $lk, time() );
	}

	/**
	 * Who confirmed: the customer, as they introduced themselves.
	 *
	 * @return array<string,string>
	 */
	private function actor(): array {
		$client = (array) Onboard::state()['client'];

		return array(
			'name'  => (string) ( $client['name'] ?? '' ),
			'email' => (string) ( $client['email'] ?? '' ),
			'phone' => (string) ( $client['phone'] ?? '' ),
			'via'   => 'questionnaire',
		);
	}

	/**
	 * A page by slug: ours to rewrite when we made it, never a customer's.
	 *
	 * @param string $slug    Page slug.
	 * @param string $title   Title.
	 * @param string $content HTML.
	 * @param int    $image   Featured image id.
	 * @return int Page id, 0 on failure.
	 */
	private function page( string $slug, string $title, string $content, int $image = 0 ): int {
		$lk       = 'page:' . $slug;
		$existing = (int) ( $this->log[ $lk ] ?? 0 );

		if ( $existing && 'page' === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) {
			$id = wp_update_post(
				array(
					'ID'           => $existing,
					'post_title'   => $title,
					'post_content' => $content,
				),
				true
			);
		} else {
			$other = get_page_by_path( $slug );

			if ( $other instanceof \WP_Post && 'trash' !== $other->post_status ) {
				// A page with that address exists and is not ours: sit beside it.
				$slug .= '-' . wp_rand( 100, 999 );
			}

			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => $content,
				),
				true
			);
		}

		if ( is_wp_error( $id ) || (int) $id < 1 ) {
			return 0;
		}

		if ( $image ) {
			set_post_thumbnail( (int) $id, $image );
		}

		$this->remember( $lk, (int) $id );

		return (int) $id;
	}

	/**
	 * A page whose body is a document the customer uploaded: Word is read
	 * into paragraphs, a PDF is embedded with a download link.
	 *
	 * @param string $slug  Page slug.
	 * @param string $title Title.
	 * @param mixed  $file  The file value {id,url,name,type}.
	 * @return int Page id, 0 when there is no file.
	 */
	private function page_from_file( string $slug, string $title, $file ): int {
		if ( ! is_array( $file ) || empty( $file['id'] ) ) {
			return 0;
		}

		$path = (string) get_attached_file( (int) $file['id'] );
		$url  = (string) $file['url'];
		$type = (string) $file['type'];
		$text = '';

		if ( $path && false !== strpos( $type, 'wordprocessingml' ) ) {
			$text = self::docx_text( $path );
		} elseif ( $path && 'text/plain' === $type ) {
			$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
		}

		if ( '' !== trim( $text ) ) {
			$html = wpautop( esc_html( $text ) );
		} else {
			$html = '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . ' (' . esc_html( (string) $file['name'] ) . ')</a></p>';

			if ( 'application/pdf' === $type ) {
				$html .= '<iframe src="' . esc_url( $url ) . '" style="width:100%;min-height:80vh;border:0" title="' . esc_attr( $title ) . '"></iframe>';
			}
		}

		return $this->page( $slug, $title, $html );
	}

	/**
	 * The text of a .docx: paragraphs from word/document.xml.
	 *
	 * @param string $path File path.
	 */
	public static function docx_text( string $path ): string {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return '';
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $path ) ) {
			return '';
		}

		$xml = (string) $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( '' === $xml ) {
			return '';
		}

		$xml = preg_replace( '~</w:p>~', "\n", $xml );
		$xml = preg_replace( '~<w:tab/>~', "\t", (string) $xml );
		$xml = preg_replace( '~<w:br[^>]*/>~', "\n", (string) $xml );
		$txt = html_entity_decode( wp_strip_all_tags( (string) $xml ), ENT_QUOTES | ENT_XML1, 'UTF-8' );

		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $txt ) );
	}

	/**
	 * After a pass: the composer's cache, the page cache in front of us.
	 */
	public static function flush_caches(): void {
		// The composer's cache generation, the way its own save turns it.
		if ( class_exists( '\\OC\\Blocks\\Render' ) ) {
			update_option( 'oc_blocks_ver', (int) get_option( 'oc_blocks_ver', 0 ) + 1, false );
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		do_action( 'oc_onboard_applied' );
	}
}
