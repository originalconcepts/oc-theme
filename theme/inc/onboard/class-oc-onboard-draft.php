<?php
/**
 * The draft: every answer, saved the moment it changes.
 *
 * One option holds the answers keyed by field id, each with the moment it
 * was written. The questionnaire patches a few keys at a time; nothing is
 * ever "submitted" as a whole form, so closing the tab loses nothing.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

defined( 'ABSPATH' ) || exit;

/**
 * Answers in progress.
 */
final class Draft {

	const OPTION = 'oc_onboard_draft';

	/**
	 * The whole draft: field id => [ 'v' => value, 't' => unix ].
	 *
	 * @return array<string,array{v:mixed,t:int}>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION );

		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Just the values, field id => value.
	 *
	 * @return array<string,mixed>
	 */
	public static function values(): array {
		$out = array();

		foreach ( self::all() as $id => $row ) {
			$out[ (string) $id ] = $row['v'] ?? null;
		}

		return $out;
	}

	/**
	 * The same, with every key and password taken out: these go to the
	 * browser, and a secret that was typed once has no business travelling
	 * back down the wire on every return visit. The screen says the field is
	 * already filled instead, and typing in it replaces what is kept.
	 *
	 * @return array<string,mixed>
	 */
	public static function shown(): array {
		$out = self::values();

		foreach ( self::held() as $id ) {
			$out[ $id ] = '';
		}

		return $out;
	}

	/**
	 * Which secrets are already filled in, for the screen to say so.
	 *
	 * @return array<int,string>
	 */
	public static function held(): array {
		$out = array();

		foreach ( Schema::fields() as $id => $f ) {
			if ( 'secret' !== (string) $f['type'] ) {
				continue;
			}

			if ( '' !== trim( (string) self::value( $id ) ) ) {
				$out[] = (string) $id;
			}
		}

		return $out;
	}

	/**
	 * One answer, or the schema default when it was never given.
	 *
	 * @param string $id Field id.
	 * @return mixed
	 */
	public static function value( string $id ) {
		$all = self::all();

		if ( array_key_exists( $id, $all ) ) {
			return $all[ $id ]['v'];
		}

		return Schema::default_of( $id );
	}

	/**
	 * Was this answered at all (even with an empty value)?
	 *
	 * @param string $id Field id.
	 */
	public static function answered( string $id ): bool {
		return array_key_exists( $id, self::all() );
	}

	/**
	 * Write a few answers. Each value is sanitised by its field's type;
	 * an id the schema does not know is dropped.
	 *
	 * @param array<string,mixed> $fields id => raw value.
	 * @return array<string,mixed> id => value as stored.
	 */
	public static function set( array $fields ): array {
		$all    = self::all();
		$stored = array();
		$now    = time();

		foreach ( $fields as $id => $raw ) {
			$id = (string) $id;

			if ( ! Schema::has( $id ) ) {
				continue;
			}

			$value = Schema::sanitize( $id, $raw );

			// An empty secret is the screen saying nothing new was typed,
			// not the customer asking for the stored one to be thrown away.
			if ( 'secret' === (string) ( Schema::field( $id )['type'] ?? '' ) && '' === $value ) {
				continue;
			}

			$all[ $id ]    = array(
				'v' => $value,
				't' => $now,
			);
			$stored[ $id ] = $value;
		}

		update_option( self::OPTION, $all, false );

		return $stored;
	}

	/**
	 * Start over.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * How many of the fields shown are answered — for the progress line.
	 *
	 * @return array{answered:int,total:int}
	 */
	public static function progress(): array {
		$values = self::values();
		$total  = 0;
		$done   = 0;

		$asked = Schema::asked();

		foreach ( Schema::fields() as $id => $f ) {
			if ( 'info' === $f['type'] || empty( $asked[ $id ] ) || ! Schema::shown( $id, $values ) ) {
				continue;
			}

			++$total;

			if ( array_key_exists( $id, $values ) && ! Schema::empty_value( $values[ $id ] ) ) {
				++$done;
			}
		}

		return array(
			'answered' => $done,
			'total'    => $total,
		);
	}
}
