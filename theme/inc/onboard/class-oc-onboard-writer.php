<?php
/**
 * The questionnaire's writer.
 *
 * A shop owner knows what makes their shop worth buying from and very
 * often cannot write it down. Asked for an "about us" they give three
 * bullet points, and those points used to land on the page exactly as
 * typed, with a note on our list saying somebody has to turn them into
 * prose. This turns them into prose while the owner is still sitting
 * there, so they read it, say "not like that, warmer", and press again.
 *
 * Nothing is written without being asked for, and nothing replaces an
 * answer on its own: the text arrives in the field and the customer
 * keeps it, edits it or throws it away.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

defined( 'ABSPATH' ) || exit;

/**
 * Turns what the customer told us into the words a page needs.
 */
final class Writer {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	const MODEL = 'claude-sonnet-5-5';

	/**
	 * Is there a key to write with?
	 */
	public static function can(): bool {
		return '' !== trim( (string) Onboard::settings()['claude_key'] );
	}

	/**
	 * Write one field.
	 *
	 * @param string $id   Field id.
	 * @param string $tone Empty, or what the customer asked to change.
	 * @return string|\WP_Error The words.
	 */
	public static function write( string $id, string $tone = '' ) {
		$f = Schema::field( $id );

		if ( ! $f || empty( $f['ai'] ) ) {
			return new \WP_Error( 'oc_no_ai', __( 'That question is not one we write.', 'oc-theme' ) );
		}

		if ( ! self::can() ) {
			return new \WP_Error( 'oc_no_key', __( 'Writing is not switched on for this site.', 'oc-theme' ) );
		}

		$spec = (array) $f['ai'];
		$said = self::said( (array) ( $spec['from'] ?? array() ) );

		if ( '' === trim( $said ) ) {
			return new \WP_Error( 'oc_nothing', __( 'Tell us a little about the shop first and we will have something to work from.', 'oc-theme' ) );
		}

		return self::ask( self::prompt( $spec, $said, $tone ) );
	}

	/**
	 * What the customer has already told us, as lines the model can read.
	 *
	 * @param array<int,string> $ids Field ids to gather.
	 */
	private static function said( array $ids ): string {
		$lines = array();

		foreach ( $ids as $one ) {
			$v = Draft::value( $one );

			if ( is_array( $v ) ) {
				$v = implode( ', ', array_filter( array_map( 'strval', $v ), 'is_string' ) );
			}

			$v = trim( (string) $v );

			if ( '' === $v ) {
				continue;
			}

			$f = Schema::field( $one );

			$lines[] = ( $f ? (string) $f['label'] : $one ) . ': ' . $v;
		}

		return implode( "\n", $lines );
	}

	/**
	 * The instruction, in the language the shop is being built in.
	 *
	 * @param array<string,mixed> $spec What this field wants written.
	 * @param string              $said What the customer told us.
	 * @param string              $tone What they asked to change, if anything.
	 */
	private static function prompt( array $spec, string $said, string $tone ): string {
		$words = max( 40, min( 400, (int) ( $spec['words'] ?? 120 ) ) );

		$out = sprintf(
			/* translators: 1: what is being written, e.g. the about page. 2: how many words. */
			__( 'Write %1$s for this shop, in about %2$d words.', 'oc-theme' ),
			(string) ( $spec['what'] ?? __( 'a short text', 'oc-theme' ) ),
			$words
		);

		$out .= "\n\n" . __( 'What the owner told us, in their own words:', 'oc-theme' ) . "\n" . $said;

		$out .= "\n\n" . __( 'Write it in the same language they used. Write it as the shop speaking to a customer — warm, plain, and specific to this shop. Use only what they told you: invent no history, no numbers, no awards and no promises. No headings, no bullet points, no quotation marks around it, and nothing about being an assistant. Return the text and nothing else.', 'oc-theme' );

		if ( '' !== trim( $tone ) ) {
			$out .= "\n\n" . __( 'They have read a first version and asked for this:', 'oc-theme' ) . ' ' . trim( $tone );
		}

		return $out;
	}

	/**
	 * Ask, and hand back the words or why not.
	 *
	 * @param string $prompt The instruction.
	 * @return string|\WP_Error
	 */
	private static function ask( string $prompt ) {
		$res = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 45,
				'headers' => array(
					'content-type'      => 'application/json',
					'anthropic-version' => '2023-06-01',
					'x-api-key'         => trim( (string) Onboard::settings()['claude_key'] ),
				),
				'body'    => (string) wp_json_encode(
					array(
						'model'      => self::MODEL,
						'max_tokens' => 1200,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'oc_unreachable', __( 'We could not reach the writer just now. Try again in a moment.', 'oc-theme' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			// The key and the account are ours, not the customer's. They are
			// told it did not work; why is kept for us, on the settings
			// screen, with nothing of the key or of the answers in it.
			update_option(
				'oc_onboard_write_error',
				array(
					'when' => time(),
					'code' => $code,
					'type' => (string) ( $body['error']['type'] ?? '' ),
				),
				false
			);

			return new \WP_Error( 'oc_refused', __( 'The writer could not do it this time. Write it yourself for now and we will tidy it with you.', 'oc-theme' ) );
		}

		delete_option( 'oc_onboard_write_error' );

		$text = '';

		foreach ( (array) ( $body['content'] ?? array() ) as $part ) {
			if ( 'text' === ( $part['type'] ?? '' ) ) {
				$text .= (string) $part['text'];
			}
		}

		$text = trim( $text );

		if ( '' === $text ) {
			return new \WP_Error( 'oc_empty', __( 'Nothing came back. Try again in a moment.', 'oc-theme' ) );
		}

		return $text;
	}
}
