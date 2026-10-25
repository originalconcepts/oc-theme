<?php
/**
 * The things that float over every page: the back-to-top arrow, a WhatsApp
 * button with the visitor's first message already written, and the
 * accessibility widget.
 *
 * The arrow is a look, so it lives in the Customizer under the catalogue.
 * The other two are the shop's own channels — a number, a sentence, an
 * embed code — so they live on the Store details screen, next to the
 * numbers they use, and are written into the one option that screen owns.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Back to top, floating WhatsApp, accessibility widget.
 */
final class Floating {

	/**
	 * The UserWay account the widget loads when the screen does not name one.
	 */
	const UW_ACCOUNT = 'T1PAnIqMjH';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'wp_footer', array( $this, 'footer' ), 30 );
	}

	/**
	 * A side, whitelisted.
	 *
	 * @param mixed  $value Raw.
	 * @param string $def   Fallback.
	 */
	public static function side( $value, string $def ): string {
		return in_array( $value, array( 'left', 'right' ), true ) ? (string) $value : $def;
	}

	/**
	 * A distance in pixels, kept on the screen.
	 *
	 * @param mixed $value Raw.
	 * @param int   $def   Fallback.
	 */
	public static function px( $value, int $def ): int {
		return '' === (string) $value ? $def : (int) min( max( (int) $value, 0 ), 400 );
	}

	/**
	 * The default first message of the WhatsApp button.
	 */
	public static function default_text(): string {
		return __( 'Hi, I need help', 'oc-theme' );
	}

	/**
	 * The words on the rectangular button when the shop wrote none.
	 */
	public static function default_label(): string {
		return __( 'Need help?', 'oc-theme' );
	}

	/**
	 * Everything the front end adds, once, at the end of the page.
	 */
	public function footer(): void {
		if ( is_feed() || is_embed() ) {
			return;
		}

		$out = $this->arrow() . $this->whatsapp();

		if ( '' !== $out ) {
			echo $out . $this->script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts below.
		}

		$this->accessibility();
	}

	/**
	 * The two distances as custom properties.
	 *
	 * @param int $desk Pixels from the bottom on a wide screen.
	 * @param int $mob  Pixels from the bottom on a phone.
	 */
	private static function vars( int $desk, int $mob ): string {
		return '--oc-fl-b:' . $desk . 'px;--oc-fl-bm:' . $mob . 'px';
	}

	/**
	 * The back-to-top arrow.
	 */
	private function arrow(): string {
		if ( ! get_theme_mod( 'oc_totop_on', false ) ) {
			return '';
		}

		$side = self::side( get_theme_mod( 'oc_totop_side', 'left' ), 'left' );

		return '<button type="button" class="oc-fl oc-fl--top oc-fl--' . esc_attr( $side ) . '" data-oc-totop style="' . esc_attr( self::vars( self::px( get_theme_mod( 'oc_totop_bd', 24 ), 24 ), self::px( get_theme_mod( 'oc_totop_bm', 16 ), 16 ) ) ) . '" aria-label="' . esc_attr__( 'Back to top', 'oc-theme' ) . '">'
			. '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5.5 11.5 12 5l6.5 6.5"/></svg>'
			. '</button>';
	}

	/**
	 * The floating WhatsApp button.
	 */
	private function whatsapp(): string {
		$s = Contact::settings();

		if ( empty( $s['wa_float'] ) ) {
			return '';
		}

		$digits = Contact::wa_digits( Contact::whatsapp() );

		if ( '' === $digits ) {
			return '';
		}

		$text = trim( (string) $s['wa_float_text'] );
		$text = '' !== $text ? $text : self::default_text();
		$base = 'https://wa.me/' . $digits;
		$url  = ! empty( $s['wa_float_url'] );

		$pill  = 'pill' === $s['wa_float_shape'];
		$label = trim( (string) $s['wa_float_label'] );
		$label = '' !== $label ? $label : self::default_label();
		$name  = $pill ? $label . ' — ' . __( 'WhatsApp', 'oc-theme' ) : __( 'Chat with us on WhatsApp', 'oc-theme' );

		return '<a class="oc-fl oc-fl--wa' . ( $pill ? ' oc-fl--pill' : '' ) . ' oc-fl--' . esc_attr( self::side( $s['wa_float_side'], 'right' ) ) . '" data-oc-wa data-base="' . esc_url( $base ) . '" data-text="' . esc_attr( $text ) . '"' . ( $url ? ' data-url="1"' : '' )
			. ' href="' . esc_url( $base . '?text=' . rawurlencode( $text ) ) . '" target="_blank" rel="noopener" style="' . esc_attr( self::vars( self::px( $s['wa_float_bd'], 24 ), self::px( $s['wa_float_bm'], 16 ) ) ) . '" aria-label="' . esc_attr( $name ) . '">'
			. ( $pill ? '<span class="oc-fl__t" dir="auto">' . esc_html( $label ) . '</span>' : '' )
			. Contact::icon( 'whatsapp' )
			. '</a>';
	}

	/**
	 * The little script both buttons share: the arrow shows after a screen
	 * of scrolling, and the WhatsApp link takes the address of the page the
	 * visitor is on at the moment they touch it — written by script because
	 * the page itself may come from a cache that serves one copy to every
	 * address.
	 */
	private function script(): string {
		return '<script>(function(){var d=document,t=d.querySelector("[data-oc-totop]"),w=d.querySelector("[data-oc-wa]");'
			. 'if(t){var on=function(){t.classList.toggle("is-on",(window.pageYOffset||d.documentElement.scrollTop)>500)};addEventListener("scroll",on,{passive:true});on();'
			. 't.addEventListener("click",function(){var r=matchMedia("(prefers-reduced-motion: reduce)").matches;scrollTo({top:0,behavior:r?"auto":"smooth"});t.blur()})}'
			. 'if(w&&w.getAttribute("data-url")){var set=function(){var m=w.getAttribute("data-text")||"";w.href=w.getAttribute("data-base")+"?text="+encodeURIComponent((m?m+"\n":"")+location.href.split("#")[0])};'
			. '["mouseenter","focus","touchstart","click"].forEach(function(e){w.addEventListener(e,set,{passive:true})})}})()</script>';
	}

	/**
	 * The accessibility widget: ours, or the embed code the shop was given.
	 */
	private function accessibility(): void {
		if ( is_customize_preview() ) {
			return;
		}

		$s = Contact::settings();

		if ( ! empty( $s['a11y_other'] ) ) {
			$code = trim( (string) $s['a11y_code'] );

			if ( '' !== $code ) {
				echo $code . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the shop's own embed code, saved only by a user who may post unfiltered HTML.
			}

			return;
		}

		if ( empty( $s['a11y_widget'] ) ) {
			return;
		}

		$account = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $s['a11y_uw_account'] );
		$account = '' !== $account ? $account : self::UW_ACCOUNT;
		$color   = (string) sanitize_hex_color( (string) $s['a11y_uw_color'] );
		$side    = self::side( $s['a11y_side'], 'left' );
		$other   = 'left' === $side ? 'right' : 'left';
		$desk    = self::px( $s['a11y_bd'], 24 );
		$mob     = self::px( $s['a11y_bm'], 16 );

		// The widget places itself; the side and the height are ours to say,
		// so the three floating things on a page can be set not to meet.
		echo '<style id="oc-a11y-widget">.userway_buttons_wrapper{top:auto!important;' . esc_html( $other ) . ':auto!important;' . esc_html( $side ) . ':20px!important;bottom:calc(' . (int) $desk . 'px + var(--oc-fl-lift,0px))!important}'
			. '@media(max-width:900px){.userway_buttons_wrapper{' . esc_html( $side ) . ':14px!important;bottom:calc(' . (int) $mob . 'px + var(--oc-fl-lift,0px))!important}}</style>' . "\n";
		echo '<script data-account="' . esc_attr( $account ) . '"' . ( '' !== $color ? ' data-color="' . esc_attr( $color ) . '"' : '' ) . ' src="https://cdn.userway.org/widget.js" defer></script>' . "\n";
	}

	/**
	 * The defaults the Store details screen adds to its option.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'wa_float'        => 0,
			'wa_float_text'   => '',
			'wa_float_shape'  => 'round',
			'wa_float_label'  => '',
			'wa_float_url'    => 1,
			'wa_float_side'   => 'right',
			'wa_float_bd'     => 24,
			'wa_float_bm'     => 16,
			'a11y_widget'     => 0,
			'a11y_uw_account' => self::UW_ACCOUNT,
			'a11y_uw_color'   => '',
			'a11y_side'       => 'left',
			'a11y_bd'         => 24,
			'a11y_bm'         => 16,
			'a11y_other'      => 0,
			'a11y_code'       => '',
		);
	}

	/**
	 * The fields of both sections from the posted form. The caller has
	 * already checked the nonce and the capability.
	 *
	 * @param array<string,mixed> $before What was saved until now.
	 * @return array<string,mixed>
	 */
	public static function from_post( array $before ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$post = static function ( string $key ): string {
			return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by each caller below.
		};

		$account = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $post( 'a11y_uw_account' ) );

		return array(
			'wa_float'        => empty( $_POST['wa_float'] ) ? 0 : 1,
			'wa_float_text'   => mb_substr( sanitize_textarea_field( $post( 'wa_float_text' ) ), 0, 300 ),
			'wa_float_shape'  => 'pill' === $post( 'wa_float_shape' ) ? 'pill' : 'round',
			'wa_float_label'  => mb_substr( sanitize_text_field( $post( 'wa_float_label' ) ), 0, 40 ),
			'wa_float_url'    => empty( $_POST['wa_float_url'] ) ? 0 : 1,
			'wa_float_side'   => self::side( $post( 'wa_float_side' ), 'right' ),
			'wa_float_bd'     => self::px( $post( 'wa_float_bd' ), 24 ),
			'wa_float_bm'     => self::px( $post( 'wa_float_bm' ), 16 ),
			'a11y_widget'     => empty( $_POST['a11y_widget'] ) ? 0 : 1,
			'a11y_uw_account' => '' !== $account ? $account : self::UW_ACCOUNT,
			'a11y_uw_color'   => (string) sanitize_hex_color( $post( 'a11y_uw_color' ) ),
			'a11y_side'       => self::side( $post( 'a11y_side' ), 'left' ),
			'a11y_bd'         => self::px( $post( 'a11y_bd' ), 24 ),
			'a11y_bm'         => self::px( $post( 'a11y_bm' ), 16 ),
			'a11y_other'      => empty( $_POST['a11y_other'] ) ? 0 : 1,
			// An embed code is script: only someone WordPress already trusts with that may change it.
			'a11y_code'       => current_user_can( 'unfiltered_html' ) ? trim( $post( 'a11y_code' ) ) : (string) ( $before['a11y_code'] ?? '' ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * A side picker and the two distances, as table rows.
	 *
	 * @param string $name  Field prefix.
	 * @param string $side  Saved side.
	 * @param int    $desk  Saved distance on a wide screen.
	 * @param int    $mob   Saved distance on a phone.
	 */
	private static function placement_rows( string $name, string $side, int $desk, int $mob ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Side of the screen', 'oc-theme' ); ?></th>
			<td>
				<label><input type="radio" name="<?php echo esc_attr( $name ); ?>_side" value="right" <?php checked( 'right', $side ); ?> /> <?php esc_html_e( 'Right', 'oc-theme' ); ?></label>
				&nbsp;
				<label><input type="radio" name="<?php echo esc_attr( $name ); ?>_side" value="left" <?php checked( 'left', $side ); ?> /> <?php esc_html_e( 'Left', 'oc-theme' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Distance from the bottom', 'oc-theme' ); ?></th>
			<td>
				<label><?php esc_html_e( 'Computer', 'oc-theme' ); ?> <input type="number" name="<?php echo esc_attr( $name ); ?>_bd" value="<?php echo (int) $desk; ?>" min="0" max="400" step="1" class="small-text" /> px</label>
				&nbsp;
				<label><?php esc_html_e( 'Phone', 'oc-theme' ); ?> <input type="number" name="<?php echo esc_attr( $name ); ?>_bm" value="<?php echo (int) $mob; ?>" min="0" max="400" step="1" class="small-text" /> px</label>
				<p class="description"><?php esc_html_e( 'Raise one of the floating buttons when it sits on top of another — the back-to-top arrow, the WhatsApp button and the accessibility widget each have their own height.', 'oc-theme' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * The floating WhatsApp section of the Store details screen.
	 *
	 * @param array<string,mixed> $s Saved settings.
	 */
	public static function whatsapp_section( array $s ): void {
		?>
		<h2><?php esc_html_e( 'Floating WhatsApp button', 'oc-theme' ); ?></h2>
		<p class="description"><?php esc_html_e( 'A round WhatsApp button over every page. It opens a chat with the WhatsApp number above, with the first message already written.', 'oc-theme' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Show the button', 'oc-theme' ); ?></th>
				<td>
					<label><input type="checkbox" name="wa_float" value="1" <?php checked( ! empty( $s['wa_float'] ) ); ?> /> <?php esc_html_e( 'Show a floating WhatsApp button', 'oc-theme' ); ?></label>
					<?php if ( '' === Contact::wa_digits( Contact::whatsapp() ) ) : ?>
						<p class="description" style="color:#b32d2e"><?php esc_html_e( 'Fill in the WhatsApp number above first — without it the button does not appear.', 'oc-theme' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Shape', 'oc-theme' ); ?></th>
				<td>
					<label><input type="radio" name="wa_float_shape" value="round" <?php checked( 'pill' !== $s['wa_float_shape'] ); ?> onchange="document.getElementById('oc-wa-label-row').hidden=true" /> <?php esc_html_e( 'Round — the icon alone', 'oc-theme' ); ?></label>
					&nbsp;
					<label><input type="radio" name="wa_float_shape" value="pill" <?php checked( 'pill' === $s['wa_float_shape'] ); ?> onchange="document.getElementById('oc-wa-label-row').hidden=false" /> <?php esc_html_e( 'Rectangle — words beside the icon', 'oc-theme' ); ?></label>
				</td>
			</tr>
			<tr id="oc-wa-label-row" <?php echo 'pill' === $s['wa_float_shape'] ? '' : 'hidden'; ?>>
				<th scope="row"><label for="oc-wa-label"><?php esc_html_e( 'Words on the button', 'oc-theme' ); ?></label></th>
				<td>
					<input type="text" id="oc-wa-label" name="wa_float_label" maxlength="40" value="<?php echo esc_attr( (string) $s['wa_float_label'] ); ?>" placeholder="<?php echo esc_attr( self::default_label() ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Shown to the left of the icon. Empty = the sentence shown in grey.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="oc-wa-text"><?php esc_html_e( 'First message', 'oc-theme' ); ?></label></th>
				<td>
					<textarea id="oc-wa-text" name="wa_float_text" rows="2" maxlength="300" class="regular-text" placeholder="<?php echo esc_attr( self::default_text() ); ?>"><?php echo esc_textarea( (string) $s['wa_float_text'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'What the visitor finds written when WhatsApp opens. They can change it before sending. Empty = the sentence shown in grey.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'The page', 'oc-theme' ); ?></th>
				<td>
					<label><input type="checkbox" name="wa_float_url" value="1" <?php checked( ! empty( $s['wa_float_url'] ) ); ?> /> <?php esc_html_e( 'Add the address of the page the visitor is on to the message', 'oc-theme' ); ?></label>
					<p class="description"><?php esc_html_e( 'So you know which product or page the question is about.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<?php self::placement_rows( 'wa_float', self::side( $s['wa_float_side'], 'right' ), self::px( $s['wa_float_bd'], 24 ), self::px( $s['wa_float_bm'], 16 ) ); ?>
		</table>
		<?php
	}

	/**
	 * The accessibility widget part of the Accessibility section.
	 *
	 * @param array<string,mixed> $s Saved settings.
	 */
	public static function accessibility_section( array $s ): void {
		$can = current_user_can( 'unfiltered_html' );
		?>
		<h3><?php esc_html_e( 'Accessibility widget', 'oc-theme' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Show the widget', 'oc-theme' ); ?></th>
				<td>
					<label><input type="checkbox" name="a11y_widget" value="1" <?php checked( ! empty( $s['a11y_widget'] ) ); ?> /> <?php esc_html_e( 'Show the accessibility widget', 'oc-theme' ); ?></label>
					<p class="description"><?php esc_html_e( 'The UserWay button that lets a visitor enlarge text, raise contrast, stop animation and more. It is an adjustment on top of the site, not a replacement for the accessibility statement below.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="oc-uw-account"><?php esc_html_e( 'UserWay account', 'oc-theme' ); ?></label></th>
				<td>
					<input type="text" id="oc-uw-account" name="a11y_uw_account" dir="ltr" value="<?php echo esc_attr( (string) $s['a11y_uw_account'] ); ?>" placeholder="<?php echo esc_attr( self::UW_ACCOUNT ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'The account number from the UserWay script. Empty = the studio\'s account.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="oc-uw-color"><?php esc_html_e( 'Button colour', 'oc-theme' ); ?></label></th>
				<td>
					<input type="color" id="oc-uw-color" name="a11y_uw_color" value="<?php echo esc_attr( '' !== (string) $s['a11y_uw_color'] ? (string) $s['a11y_uw_color'] : '#000000' ); ?>" <?php disabled( '' === (string) $s['a11y_uw_color'] ); ?> />
					<label style="margin-inline-start:12px"><input type="checkbox" onchange="document.getElementById('oc-uw-color').disabled=this.checked" <?php checked( '' === (string) $s['a11y_uw_color'] ); ?> /> <?php esc_html_e( 'The widget\'s own colour', 'oc-theme' ); ?></label>
				</td>
			</tr>
			<?php self::placement_rows( 'a11y', self::side( $s['a11y_side'], 'left' ), self::px( $s['a11y_bd'], 24 ), self::px( $s['a11y_bm'], 16 ) ); ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'A different widget', 'oc-theme' ); ?></th>
				<td>
					<label><input type="checkbox" name="a11y_other" value="1" <?php checked( ! empty( $s['a11y_other'] ) ); ?> onchange="document.getElementById('oc-a11y-code-row').hidden=!this.checked" /> <?php esc_html_e( 'Use a different accessibility widget — paste its code', 'oc-theme' ); ?></label>
					<div id="oc-a11y-code-row" <?php echo empty( $s['a11y_other'] ) ? 'hidden' : ''; ?> style="margin-top:8px">
						<textarea name="a11y_code" rows="5" dir="ltr" class="large-text code" placeholder="&lt;script src=&quot;https://…&quot;&gt;&lt;/script&gt;" <?php disabled( ! $can ); ?>><?php echo esc_textarea( (string) $s['a11y_code'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Usually the code the customer\'s accessibility provider sent. It is added at the end of every page, and replaces the widget above while this box is ticked.', 'oc-theme' ); ?>
							<?php if ( ! $can ) : ?>
								<strong><?php esc_html_e( 'Only an administrator can change this code.', 'oc-theme' ); ?></strong>
							<?php endif; ?>
						</p>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}
}
