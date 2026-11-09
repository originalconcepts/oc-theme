<?php
/**
 * Offers: the quantity-tier table on the product edit screen.
 *
 * A tab under Product data: a switch for "its own tiers", then rows of
 * quantity, unit price, percent off, total, label and which one the page
 * opens on. Unit price and percent are two views of one number — type
 * either and the other follows. The same table shape serves the rule
 * editor and the bulk action on the by-product tab.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

defined( 'ABSPATH' ) || exit;

/**
 * Product-data tab.
 */
final class Tiers_Admin {

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_product_data_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
	}

	/**
	 * The tab.
	 *
	 * @param array<string,array<string,mixed>> $tabs Product-data tabs.
	 * @return array<string,array<string,mixed>>
	 */
	public function tab( $tabs ): array {
		$tabs = (array) $tabs;

		$tabs['oc_tiers'] = array(
			'label'    => __( 'Quantity tiers', 'oc-theme' ),
			'target'   => 'oc_tiers_data',
			'class'    => array( 'show_if_simple' ),
			'priority' => 68,
		);

		return $tabs;
	}

	/**
	 * The table rows' markup, shared with the rule editor and the bulk bar.
	 *
	 * @param string                                         $prefix     Field name prefix (tier for the product, rt for a rule, bt for the bulk bar).
	 * @param array<int,array{qty:int,pct:int,label:string}> $tiers      Rows.
	 * @param int                                            $default    Quantity the page opens on, 1 for none.
	 * @param float                                          $price      Unit price, or 0 when it varies (no price columns then).
	 * @param bool                                           $with_label Whether to show the label and default columns.
	 * @return string Escaped markup.
	 */
	public static function table( string $prefix, array $tiers, int $default, float $price, bool $with_label = true ): string {
		$labels = array(
			''        => __( 'No label', 'oc-theme' ),
			'popular' => __( 'Most popular', 'oc-theme' ),
			'best'    => __( 'Best value', 'oc-theme' ),
		);

		$html  = '<div class="oc-tt" data-oc-tt data-price="' . esc_attr( (string) $price ) . '" data-prefix="' . esc_attr( $prefix ) . '">';
		$html .= '<table class="oc-tt__table"><thead><tr>';
		$html .= '<th>' . esc_html__( 'Quantity', 'oc-theme' ) . '</th>';

		if ( $price > 0 ) {
			$html .= '<th>' . esc_html__( 'Price per unit', 'oc-theme' ) . '</th>';
		}

		$html .= '<th>' . esc_html__( 'Percent off', 'oc-theme' ) . '</th>';

		if ( $price > 0 ) {
			$html .= '<th>' . esc_html__( 'Total', 'oc-theme' ) . '</th>';
		}

		if ( $with_label ) {
			$html .= '<th>' . esc_html__( 'Label', 'oc-theme' ) . '</th>';
			$html .= '<th>' . esc_html__( 'Selected on opening', 'oc-theme' ) . '</th>';
		}

		$html .= '<th></th></tr></thead><tbody>';

		// The single unit is the price itself, shown so the table reads
		// top to bottom like the page will.
		$html .= '<tr class="oc-tt__one"><td><input type="text" value="1" disabled /></td>';

		if ( $price > 0 ) {
			$html .= '<td><input type="text" value="' . esc_attr( wc_format_localized_price( (string) $price ) ) . '" disabled /></td>';
		}

		$html .= '<td><span class="oc-tt__muted">' . esc_html__( 'none', 'oc-theme' ) . '</span></td>';

		if ( $price > 0 ) {
			$html .= '<td><strong>' . wp_kses_post( wc_price( $price ) ) . '</strong></td>';
		}

		if ( $with_label ) {
			$html .= '<td><span class="oc-tt__muted">' . esc_html__( 'none', 'oc-theme' ) . '</span></td>';
			$html .= '<td><input type="radio" name="' . esc_attr( $prefix ) . '_default" value="1" ' . checked( 1, $default, false ) . ' aria-label="' . esc_attr__( 'One unit selected on opening', 'oc-theme' ) . '" /></td>';
		}

		$html .= '<td></td></tr>';

		foreach ( $tiers as $tier ) {
			$html .= self::row( $prefix, $tier, $default, $price, $with_label, $labels );
		}

		$html .= '</tbody></table>';
		$html .= '<template data-oc-tt-row>' . self::row( $prefix, array( 'qty' => 0, 'pct' => 0, 'label' => '' ), 0, $price, $with_label, $labels ) . '</template>';
		$html .= '<p class="oc-tt__foot"><button type="button" class="button" data-oc-tt-add>' . esc_html__( 'Add a tier', 'oc-theme' ) . '</button>';

		if ( $price > 0 ) {
			$html .= ' <span class="oc-tt__muted">' . esc_html__( 'Type a price per unit or a percent; the other follows.', 'oc-theme' ) . '</span>';
		}

		$html .= '</p></div>';

		return $html;
	}

	/**
	 * One editable row.
	 *
	 * @param string                                $prefix     Field prefix.
	 * @param array{qty:int,pct:int,label:string}   $tier       The tier (qty 0 = the template row).
	 * @param int                                   $default    Opening quantity.
	 * @param float                                 $price      Unit price or 0.
	 * @param bool                                  $with_label Label and default columns.
	 * @param array<string,string>                  $labels     Label names.
	 */
	private static function row( string $prefix, array $tier, int $default, float $price, bool $with_label, array $labels ): string {
		$qty  = $tier['qty'] > 0 ? (string) $tier['qty'] : '';
		$pct  = $tier['qty'] > 0 ? (string) $tier['pct'] : '';
		$unit = $tier['qty'] > 0 && $price > 0 ? wc_format_localized_price( (string) Rules::unit_at( $price, $tier['pct'], wc_get_price_decimals() ) ) : '';

		$html  = '<tr class="oc-tt__row">';
		$html .= '<td><input type="number" min="2" max="9999" step="1" name="' . esc_attr( $prefix ) . '_qty[]" value="' . esc_attr( $qty ) . '" data-oc-tt-qty aria-label="' . esc_attr__( 'Quantity', 'oc-theme' ) . '" /></td>';

		if ( $price > 0 ) {
			$html .= '<td><input type="text" inputmode="decimal" value="' . esc_attr( $unit ) . '" data-oc-tt-unit aria-label="' . esc_attr__( 'Price per unit', 'oc-theme' ) . '" /></td>';
		}

		$html .= '<td><span class="oc-tt__pct"><input type="number" min="0" max="' . esc_attr( (string) Rules::MAX_PERCENT ) . '" step="1" name="' . esc_attr( $prefix ) . '_pct[]" value="' . esc_attr( $pct ) . '" data-oc-tt-pct aria-label="' . esc_attr__( 'Percent off', 'oc-theme' ) . '" />%</span></td>';

		if ( $price > 0 ) {
			$html .= '<td><strong data-oc-tt-total>' . ( '' !== $qty ? wp_kses_post( wc_price( round( Rules::unit_at( $price, $tier['pct'], wc_get_price_decimals() ) * $tier['qty'], wc_get_price_decimals() ) ) ) : '' ) . '</strong></td>';
		}

		if ( $with_label ) {
			$html .= '<td><select name="' . esc_attr( $prefix ) . '_label[]" aria-label="' . esc_attr__( 'Label', 'oc-theme' ) . '">';

			foreach ( $labels as $key => $name ) {
				$html .= '<option value="' . esc_attr( $key ) . '" ' . selected( $key, $tier['label'], false ) . '>' . esc_html( $name ) . '</option>';
			}

			$html .= '</select></td>';
			$html .= '<td><input type="radio" name="' . esc_attr( $prefix ) . '_default" value="' . esc_attr( $qty ) . '" ' . checked( $tier['qty'] > 0 && $tier['qty'] === $default, true, false ) . ' data-oc-tt-default aria-label="' . esc_attr__( 'Selected on opening', 'oc-theme' ) . '" /></td>';
		}

		$html .= '<td><button type="button" class="oc-tt__x" data-oc-tt-remove aria-label="' . esc_attr__( 'Remove this tier', 'oc-theme' ) . '">&times;</button></td>';
		$html .= '</tr>';

		return $html;
	}

	/**
	 * The script that keeps unit price, percent and total in step, adds
	 * and removes rows. Printed once per screen that carries a table.
	 */
	public static function script(): string {
		return '<script>(function(){
	if (window.ocTT) { return; } window.ocTT = true;
	function money(n){ try { return new Intl.NumberFormat(document.documentElement.lang || "he", {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(n); } catch (e) { return n.toFixed(2); } }
	function wire(box){
		var price = parseFloat(box.getAttribute("data-price")) || 0;
		function sync(row, from){
			var qty = parseInt((row.querySelector("[data-oc-tt-qty]")||{}).value, 10) || 0;
			var pctEl = row.querySelector("[data-oc-tt-pct]"), unitEl = row.querySelector("[data-oc-tt-unit]"), totEl = row.querySelector("[data-oc-tt-total]"), defEl = row.querySelector("[data-oc-tt-default]");
			var pct = parseFloat(pctEl ? pctEl.value : 0) || 0;
			if (price > 0 && unitEl) {
				if (from === "unit") { var u = parseFloat(String(unitEl.value).replace(/[^0-9.]/g, "")) || 0; pct = u > 0 ? Math.max(0, Math.min(90, Math.round((1 - u / price) * 100))) : 0; if (pctEl) { pctEl.value = pct; } }
				else { unitEl.value = money(price * (1 - pct / 100)); }
				if (totEl) { totEl.textContent = qty ? money(price * (1 - pct / 100) * qty) : ""; }
			}
			if (defEl) { defEl.value = qty || ""; }
		}
		box.addEventListener("input", function(e){
			var row = e.target.closest(".oc-tt__row"); if (!row) { return; }
			sync(row, e.target.hasAttribute("data-oc-tt-unit") ? "unit" : "pct");
		});
		box.addEventListener("click", function(e){
			if (e.target.closest("[data-oc-tt-remove]")) { e.target.closest("tr").remove(); return; }
			if (e.target.closest("[data-oc-tt-add]")) {
				var tpl = box.querySelector("[data-oc-tt-row]"); if (!tpl) { return; }
				var tb = box.querySelector("tbody"); tb.insertAdjacentHTML("beforeend", tpl.innerHTML);
				var r = tb.lastElementChild; var q = r.querySelector("[data-oc-tt-qty]"); if (q) { q.focus(); }
			}
		});
	}
	document.querySelectorAll("[data-oc-tt]").forEach(wire);
	document.addEventListener("oc-tt-wire", function(e){ if (e.target && e.target.matches && e.target.matches("[data-oc-tt]")) { wire(e.target); } });
})();</script>';
	}

	/**
	 * The shared styles for the table, admin side.
	 */
	public static function style(): string {
		return '<style>
.oc-tt__table{border-collapse:collapse;width:auto;min-width:520px}
.oc-tt__table th{text-align:start;font-weight:600;font-size:12px;color:#646970;padding:6px 8px}
.oc-tt__table td{padding:6px 8px;vertical-align:middle;border-top:1px solid #eceef1}
.oc-tt__table input[type=number],.oc-tt__table input[type=text]{width:84px!important;min-height:34px;text-align:center}
.oc-tt__table select{min-height:34px}
.oc-tt__pct{white-space:nowrap}
.oc-tt__pct input{width:64px!important;margin-inline-end:4px}
.oc-tt__one td{color:#646970}
.oc-tt__muted{color:#8c8f94;font-size:12px}
.oc-tt__x{border:0;background:transparent;color:#646970;font-size:18px;cursor:pointer;line-height:1;padding:4px 8px}
.oc-tt__x:hover{color:#b32d2e}
.oc-tt__foot{margin:10px 0 0}
</style>';
	}

	/**
	 * The product tab's panel.
	 */
	public function panel(): void {
		global $post;

		$product = wc_get_product( (int) $post->ID );
		$own     = Tiers::own( (int) $post->ID );
		$price   = $product instanceof \WC_Product ? (float) $product->get_price() : 0.0;
		$s       = Offers::settings();
		$rule    = $product instanceof \WC_Product ? Rules::pick_tiers( $s['rules'], Offers::facts( (int) $post->ID ) ) : null;
		?>
		<div id="oc_tiers_data" class="panel woocommerce_options_panel hidden">
			<input type="hidden" name="oc_tiers_panel" value="1" />
			<?php echo self::style(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static styles. ?>
			<style>
			#oc_tiers_data{padding:14px 16px}
			#oc_tiers_data .oc-tt__own{display:flex;gap:10px;align-items:flex-start;margin:0 0 14px;padding:12px 14px;border-radius:8px;background:#fdf7ef;border:1px solid #f1e3cf}
			#oc_tiers_data .oc-tt__own input{margin-top:3px}
			#oc_tiers_data p.oc-tt__note{color:#646970;margin:12px 0 0}
			</style>
			<label class="oc-tt__own">
				<input type="checkbox" name="tier_own" value="1" <?php checked( 1, $own['own'] ); ?> />
				<span>
					<strong><?php esc_html_e( 'This product has tiers of its own', 'oc-theme' ); ?></strong><br />
					<?php
					if ( null !== $rule ) {
						/* translators: %s: rule name. */
						echo esc_html( sprintf( __( 'Unticked, it takes the tiers of the rule "%s".', 'oc-theme' ), (string) $s['rules'][ $rule ]['name'] ) );
					} else {
						echo esc_html__( 'Unticked, it has no tiers: no rule under Theme settings → Offers covers it.', 'oc-theme' );
					}
					?>
				</span>
			</label>
			<?php echo self::table( 'tier', $own['tiers'], $own['default'], $price ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
			<p class="oc-tt__note"><?php esc_html_e( 'Tiers show on the product page as cards beside the quantity, and the cart prices every quantity by the tier it reaches. Simple products only.', 'oc-theme' ); ?></p>
			<?php echo self::script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script. ?>
		</div>
		<?php
	}

	/**
	 * Rows from a posted table.
	 *
	 * @param string $prefix Field prefix.
	 * @return array{tiers:array<int,array{qty:int,pct:int,label:string}>,default:int}
	 */
	public static function posted( string $prefix ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the callers verified their nonces.
		$qtys   = isset( $_POST[ $prefix . '_qty' ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ $prefix . '_qty' ] ) ) : array();
		$pcts   = isset( $_POST[ $prefix . '_pct' ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ $prefix . '_pct' ] ) ) : array();
		$labels = isset( $_POST[ $prefix . '_label' ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST[ $prefix . '_label' ] ) ) : array();
		$def    = isset( $_POST[ $prefix . '_default' ] ) ? absint( $_POST[ $prefix . '_default' ] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$rows = array();

		foreach ( array_values( $qtys ) as $i => $qty ) {
			$rows[] = array(
				'qty'   => (int) $qty,
				'pct'   => (int) ( array_values( $pcts )[ $i ] ?? 0 ),
				'label' => (string) ( array_values( $labels )[ $i ] ?? '' ),
			);
		}

		$tiers = Rules::clean_tiers( $rows );

		return array(
			'tiers'   => $tiers,
			'default' => Rules::clean_default( $def, $tiers ),
		);
	}

	/**
	 * Save the product's tiers.
	 *
	 * @param \WC_Product $product The product being saved.
	 */
	public function save( $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo verified the product-save nonce already.
		if ( ! isset( $_POST['oc_tiers_panel'] ) || ! $product instanceof \WC_Product ) {
			return;
		}

		$posted = self::posted( 'tier' );

		$product->update_meta_data(
			Tiers::META,
			array(
				'own'     => empty( $_POST['tier_own'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'tiers'   => $posted['tiers'],
				'default' => $posted['default'],
			)
		);

		Tiers::forget();
	}
}
