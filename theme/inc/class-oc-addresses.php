<?php
/**
 * Address book: a per-user list of shipping addresses kept in our own user
 * meta (`_oc_addresses`), never in WooCommerce core — so a Woo/WP update
 * can't drop it. Drives the packed logged-in checkout (address selector +
 * "add address") and the my-account addresses screen.
 *
 * An address holds only the place — city, street, apartment, floor, entry
 * and a label. Name and phone belong to the orderer, not the address.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The user's saved addresses.
 */
final class Addresses {

	const META = '_oc_addresses';

	/**
	 * Is the multi-address / packed-checkout experience turned on?
	 */
	public static function enabled(): bool {
		$s = Checkout::settings();

		return ! empty( $s['multi_address'] );
	}

	/**
	 * The built-in chip labels (custom labels pass straight through).
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'home'    => _x( 'Home', 'address label', 'oc-theme' ),
			'work'    => _x( 'Work', 'address label', 'oc-theme' ),
			'parents' => _x( 'Parents', 'address label', 'oc-theme' ),
		);
	}

	/**
	 * Human label for a stored label key.
	 *
	 * @param string $label Stored label.
	 * @return string
	 */
	public static function label_text( string $label ): string {
		$known = self::labels();

		return $known[ $label ] ?? $label;
	}

	/**
	 * Only the truly stored addresses, default first.
	 *
	 * @param int $uid User id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( int $uid ): array {
		$list = get_user_meta( $uid, self::META, true );
		$list = is_array( $list ) ? array_values( $list ) : array();

		usort(
			$list,
			static function ( $a, $b ) {
				return (int) ! empty( $b['is_default'] ) <=> (int) ! empty( $a['is_default'] );
			}
		);

		return $list;
	}

	/**
	 * The book to show — the stored list, or a single seed drawn from the
	 * user's WooCommerce billing address when the book is still empty. The
	 * seed lets a returning customer see a packed card on day one; the moment
	 * they save anything it becomes a real, editable entry.
	 *
	 * @param int $uid User id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function book( int $uid ): array {
		$list = self::all( $uid );

		if ( $list ) {
			return $list;
		}

		$seed = self::from_wc_billing( $uid );

		return $seed ? array( $seed ) : array();
	}

	/**
	 * A one-off address built from the user's Woo billing meta.
	 *
	 * @param int $uid User id.
	 * @return array<string,mixed>|null
	 */
	public static function from_wc_billing( int $uid ): ?array {
		$street = (string) get_user_meta( $uid, 'billing_address_1', true );

		if ( '' === trim( $street ) ) {
			return null;
		}

		return array(
			'id'         => 'wc',
			'label'      => 'home',
			'city'       => (string) get_user_meta( $uid, 'billing_city', true ),
			'address_1'  => $street,
			'address_2'  => (string) get_user_meta( $uid, 'billing_address_2', true ),
			'floor'      => '',
			'entry'      => '',
			'is_default' => true,
		);
	}

	/**
	 * One address by id (searches the shown book, seed included).
	 *
	 * @param int    $uid User id.
	 * @param string $id  Address id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $uid, string $id ): ?array {
		foreach ( self::book( $uid ) as $a ) {
			if ( (string) ( $a['id'] ?? '' ) === $id ) {
				return $a;
			}
		}

		return null;
	}

	/**
	 * The default address (or the first one) from the shown book.
	 *
	 * @param int $uid User id.
	 * @return array<string,mixed>|null
	 */
	public static function default_addr( int $uid ): ?array {
		$book = self::book( $uid );

		foreach ( $book as $a ) {
			if ( ! empty( $a['is_default'] ) ) {
				return $a;
			}
		}

		return $book[0] ?? null;
	}

	/**
	 * Insert or update an address; returns its id. A brand-new entry (or one
	 * carrying the synthetic 'wc' seed id) is given a fresh uuid so the seed
	 * turns into a stored address on first save.
	 *
	 * @param int                 $uid User id.
	 * @param array<string,mixed> $in  Raw address input.
	 * @return string
	 */
	public static function save( int $uid, array $in ): string {
		$list = self::all( $uid );

		// First real write while only the billing seed was on show, and it is
		// not the seed itself being saved — adopt the seed as a real entry so
		// the billing address survives beside the address just added.
		if ( ! $list && ( ! isset( $in['id'] ) || 'wc' !== (string) $in['id'] ) ) {
			$seed = self::from_wc_billing( $uid );
			if ( $seed ) {
				$seed['id'] = wp_generate_uuid4();
				$list[]     = $seed;
			}
		}

		$id = isset( $in['id'] ) && '' !== (string) $in['id'] && 'wc' !== $in['id']
			? (string) $in['id']
			: wp_generate_uuid4();

		$addr = array(
			'id'         => $id,
			'label'      => sanitize_text_field( (string) ( $in['label'] ?? '' ) ),
			'city'       => sanitize_text_field( (string) ( $in['city'] ?? '' ) ),
			'address_1'  => sanitize_text_field( (string) ( $in['address_1'] ?? '' ) ),
			'address_2'  => sanitize_text_field( (string) ( $in['address_2'] ?? '' ) ),
			'floor'      => sanitize_text_field( (string) ( $in['floor'] ?? '' ) ),
			'entry'      => sanitize_text_field( (string) ( $in['entry'] ?? '' ) ),
			'is_default' => ! empty( $in['is_default'] ),
		);

		$found = false;
		foreach ( $list as &$a ) {
			if ( (string) ( $a['id'] ?? '' ) === $id ) {
				$a     = $addr;
				$found = true;
			}
		}
		unset( $a );

		if ( ! $found ) {
			$list[] = $addr;
		}

		// Exactly one default at all times.
		if ( $addr['is_default'] ) {
			foreach ( $list as &$a ) {
				$a['is_default'] = ( (string) $a['id'] === $id );
			}
			unset( $a );
		} elseif ( ! self::has_default( $list ) && $list ) {
			$list[0]['is_default'] = true;
		}

		update_user_meta( $uid, self::META, array_values( $list ) );

		return $id;
	}

	/**
	 * Remove an address; if the default went, the first survivor takes over.
	 *
	 * @param int    $uid User id.
	 * @param string $id  Address id.
	 */
	public static function delete( int $uid, string $id ): void {
		$list = array_values(
			array_filter(
				self::all( $uid ),
				static function ( $a ) use ( $id ) {
					return (string) ( $a['id'] ?? '' ) !== $id;
				}
			)
		);

		if ( $list && ! self::has_default( $list ) ) {
			$list[0]['is_default'] = true;
		}

		update_user_meta( $uid, self::META, $list );
	}

	/**
	 * Make one address the default.
	 *
	 * @param int    $uid User id.
	 * @param string $id  Address id.
	 */
	public static function set_default( int $uid, string $id ): void {
		$list = self::all( $uid );

		// Nothing stored yet but a seed is showing — persist it first.
		if ( ! $list && 'wc' === $id ) {
			$seed = self::from_wc_billing( $uid );
			if ( $seed ) {
				self::save( $uid, array( $seed ) + array( 'is_default' => true ) );
			}
			return;
		}

		foreach ( $list as &$a ) {
			$a['is_default'] = ( (string) ( $a['id'] ?? '' ) === $id );
		}
		unset( $a );

		update_user_meta( $uid, self::META, array_values( $list ) );
	}

	/**
	 * One-line rendering of an address for cards and packed rows.
	 *
	 * @param array<string,mixed> $a Address.
	 * @return string
	 */
	public static function format( array $a ): string {
		$line = trim( (string) ( $a['address_1'] ?? '' ) );

		if ( ! empty( $a['city'] ) ) {
			$line .= ( '' !== $line ? ', ' : '' ) . $a['city'];
		}

		$extra = array();
		if ( ! empty( $a['address_2'] ) ) {
			/* translators: %s: apartment number. */
			$extra[] = sprintf( __( 'Apt %s', 'oc-theme' ), $a['address_2'] );
		}
		if ( ! empty( $a['floor'] ) ) {
			/* translators: %s: floor. */
			$extra[] = sprintf( __( 'Floor %s', 'oc-theme' ), $a['floor'] );
		}

		if ( $extra ) {
			$line .= ' · ' . implode( ' · ', $extra );
		}

		return $line;
	}

	/**
	 * Does the list already carry a default?
	 *
	 * @param array<int,array<string,mixed>> $list Addresses.
	 * @return bool
	 */
	private static function has_default( array $list ): bool {
		foreach ( $list as $a ) {
			if ( ! empty( $a['is_default'] ) ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------- my-account management */

	/**
	 * Which address fields are required, honouring the store's checkout rules.
	 *
	 * @return array<string,array{label:string,req:bool}>
	 */
	public static function field_rules(): array {
		$s = class_exists( '\OC\Theme\Checkout' ) ? Checkout::settings() : array();

		return array(
			'address_1' => array(
				'label' => __( 'Street and house number', 'oc-theme' ),
				'req'   => true,
			),
			'city'      => array(
				'label' => __( 'City', 'oc-theme' ),
				'req'   => true,
			),
			'address_2' => array(
				'label' => __( 'Apartment', 'oc-theme' ),
				'req'   => ! empty( $s['apt_required'] ),
			),
			'floor'     => array(
				'label' => __( 'Floor', 'oc-theme' ),
				'req'   => ! empty( $s['floor_required'] ),
			),
			'entry'     => array(
				'label' => __( 'Entry code', 'oc-theme' ),
				'req'   => ! empty( $s['entry_required'] ),
			),
		);
	}

	/**
	 * The base URL of the addresses screen.
	 */
	private static function account_url(): string {
		return function_exists( 'wc_get_account_endpoint_url' )
			? (string) wc_get_account_endpoint_url( 'edit-address' )
			: '';
	}

	/**
	 * Process a save / delete / set-default before anything renders.
	 */
	public static function handle_account(): void {
		if ( ! is_user_logged_in() || ! self::enabled() || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		$uid = get_current_user_id();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- each branch verifies its own nonce below.
		if ( isset( $_POST['oc_addr_save'] ) && check_admin_referer( 'oc_addr_save' ) ) {
			$in      = array();
			$missing = false;
			foreach ( self::field_rules() as $key => $rule ) {
				$in[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
				if ( $rule['req'] && '' === trim( $in[ $key ] ) ) {
					/* translators: %s: field label. */
					wc_add_notice( sprintf( __( '%s is required.', 'oc-theme' ), $rule['label'] ), 'error' );
					$missing = true;
				}
			}

			if ( ! $missing ) {
				self::save(
					$uid,
					array(
						'id'         => sanitize_text_field( wp_unslash( $_POST['oc_addr_id'] ?? '' ) ),
						'label'      => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
						'city'       => $in['city'],
						'address_1'  => $in['address_1'],
						'address_2'  => $in['address_2'],
						'floor'      => $in['floor'],
						'entry'      => $in['entry'],
						'is_default' => ! empty( $_POST['is_default'] ),
					)
				);
				wc_add_notice( __( 'Address saved.', 'oc-theme' ) );
				wp_safe_redirect( self::account_url() );
				exit;
			}
		}

		if ( isset( $_GET['oc_addr_del'] ) && isset( $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'oc_addr_del' ) ) {
			self::delete( $uid, sanitize_text_field( wp_unslash( $_GET['oc_addr_del'] ) ) );
			wc_add_notice( __( 'Address removed.', 'oc-theme' ) );
			wp_safe_redirect( self::account_url() );
			exit;
		}

		if ( isset( $_GET['oc_addr_def'] ) && isset( $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'oc_addr_def' ) ) {
			self::set_default( $uid, sanitize_text_field( wp_unslash( $_GET['oc_addr_def'] ) ) );
			wc_add_notice( __( 'Default address updated.', 'oc-theme' ) );
			wp_safe_redirect( self::account_url() );
			exit;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Render the addresses screen in place of Woo's billing/shipping forms.
	 */
	public static function render_account(): void {
		$uid = get_current_user_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only routing.
		$editing = isset( $_GET['oc_addr'] ) ? sanitize_text_field( wp_unslash( $_GET['oc_addr'] ) ) : '';

		if ( '' !== $editing ) {
			self::render_form( $uid, $editing );
			return;
		}

		$book = self::book( $uid );
		?>
		<div class="oc-abook">
			<div class="oc-abook__grid">
				<?php foreach ( $book as $a ) : ?>
					<?php
					$id      = (string) ( $a['id'] ?? '' );
					$is_def  = ! empty( $a['is_default'] );
					$del_url = wp_nonce_url( add_query_arg( 'oc_addr_del', $id, self::account_url() ), 'oc_addr_del' );
					$def_url = wp_nonce_url( add_query_arg( 'oc_addr_def', $id, self::account_url() ), 'oc_addr_def' );
					$edit    = add_query_arg( 'oc_addr', $id, self::account_url() );
					?>
					<div class="oc-abook__card<?php echo $is_def ? ' is-default' : ''; ?>">
						<div class="oc-abook__top">
							<span class="oc-abook__label"><?php echo esc_html( self::label_text( (string) ( $a['label'] ?? '' ) ) ); ?></span>
							<?php if ( $is_def ) : ?>
								<span class="oc-abook__pill"><?php esc_html_e( 'Default', 'oc-theme' ); ?></span>
							<?php endif; ?>
						</div>
						<p class="oc-abook__line"><?php echo esc_html( self::format( $a ) ); ?></p>
						<div class="oc-abook__acts">
							<a class="oc-abook__act" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'oc-theme' ); ?></a>
							<?php if ( ! $is_def ) : ?>
								<a class="oc-abook__act" href="<?php echo esc_url( $def_url ); ?>"><?php esc_html_e( 'Make default', 'oc-theme' ); ?></a>
								<a class="oc-abook__act oc-abook__act--del" href="<?php echo esc_url( $del_url ); ?>"><?php esc_html_e( 'Remove', 'oc-theme' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>

				<a class="oc-abook__add" href="<?php echo esc_url( add_query_arg( 'oc_addr', 'new', self::account_url() ) ); ?>">
					<span class="oc-abook__add-plus" aria-hidden="true">＋</span>
					<span><?php esc_html_e( 'Add an address', 'oc-theme' ); ?></span>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * The add / edit form.
	 *
	 * @param int    $uid User id.
	 * @param string $id  Address id, or 'new'.
	 */
	private static function render_form( int $uid, string $id ): void {
		$rules = self::field_rules();
		$addr  = 'new' === $id ? array() : ( self::get( $uid, $id ) ?? array() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- redisplay of the user's own just-submitted values; the save path already verified the nonce.
		$posted = isset( $_POST['oc_addr_save'] );

		$get = static function ( $k ) use ( $posted, $addr ) {
			if ( $posted && isset( $_POST[ $k ] ) ) {
				return esc_attr( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) );
			}
			return esc_attr( (string) ( $addr[ $k ] ?? '' ) );
		};

		$label_raw = $posted && isset( $_POST['label'] )
			? sanitize_text_field( wp_unslash( $_POST['label'] ) )
			: (string) ( $addr['label'] ?? 'home' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$current = $label_raw;
		if ( '' === $current || ! array_key_exists( $current, self::labels() ) ) {
			$is_custom = '' !== $label_raw && ! array_key_exists( $label_raw, self::labels() );
			$current   = $is_custom ? 'custom' : 'home';
		}

		$row = static function ( $key, $id_attr ) use ( $rules, $get ) {
			$rule = $rules[ $key ];
			?>
			<p class="oc-abook__row">
				<label for="<?php echo esc_attr( $id_attr ); ?>"><?php echo esc_html( $rule['label'] ); ?><?php echo $rule['req'] ? ' <span class="oc-abook__req" aria-hidden="true">*</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></label>
				<input type="text" id="<?php echo esc_attr( $id_attr ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo $get( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr in $get. ?>"<?php echo $rule['req'] ? ' required' : ''; ?> />
			</p>
			<?php
		};
		?>
		<div class="oc-abook oc-abook--form">
			<form method="post" class="oc-abook__form" data-oc-abook-form>
				<?php wp_nonce_field( 'oc_addr_save' ); ?>
				<input type="hidden" name="oc_addr_id" value="<?php echo esc_attr( 'new' === $id ? '' : $id ); ?>" />

				<div class="oc-abook__chips" data-oc-abook-chips>
					<?php foreach ( self::labels() as $key => $text ) : ?>
						<button type="button" class="oc-co-chip<?php echo $key === $current ? ' is-on' : ''; ?>" data-oc-chip="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $text ); ?></button>
					<?php endforeach; ?>
					<button type="button" class="oc-co-chip<?php echo 'custom' === $current ? ' is-on' : ''; ?>" data-oc-chip="custom"><?php esc_html_e( 'Other…', 'oc-theme' ); ?></button>
				</div>
				<input type="text" class="oc-abook__custom" data-oc-chip-input placeholder="<?php esc_attr_e( 'Label — e.g. Grandma', 'oc-theme' ); ?>" value="<?php echo 'custom' === $current ? esc_attr( $label_raw ) : ''; ?>" <?php echo 'custom' === $current ? '' : 'hidden'; ?> />
				<input type="hidden" name="label" data-oc-abook-label value="<?php echo 'custom' === $current ? esc_attr( $label_raw ) : esc_attr( $current ); ?>" />

				<?php $row( 'address_1', 'oc_ab_a1' ); ?>
				<?php $row( 'city', 'oc_ab_city' ); ?>
				<div class="oc-abook__grid3">
					<?php $row( 'address_2', 'oc_ab_a2' ); ?>
					<?php $row( 'floor', 'oc_ab_floor' ); ?>
					<?php $row( 'entry', 'oc_ab_entry' ); ?>
				</div>

				<label class="oc-abook__def">
					<input type="checkbox" name="is_default" value="1" <?php checked( ! empty( $addr['is_default'] ) ); ?> />
					<span><?php esc_html_e( 'Use as my default address', 'oc-theme' ); ?></span>
				</label>

				<div class="oc-abook__formacts">
					<button type="submit" name="oc_addr_save" value="1" class="oc-abook__save"><?php esc_html_e( 'Save address', 'oc-theme' ); ?></button>
					<a class="oc-abook__cancel" href="<?php echo esc_url( self::account_url() ); ?>"><?php esc_html_e( 'Cancel', 'oc-theme' ); ?></a>
				</div>
			</form>
		</div>
		<?php
	}
}
