<?php
/**
 * Product tabs: what shows, in what order, plus shop-manager custom tabs —
 * and the "Theme settings" admin menu that gathers every OC screen.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Tabs engine + admin.
 */
final class Tabs {

	const MENU = 'oc-tabs';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 55 );
		add_action( 'admin_post_oc_tabs_save', array( $this, 'save_settings' ) );

		add_filter( 'woocommerce_product_tabs', array( $this, 'tabs' ), 40 );
		add_action( 'init', array( $this, 'placement' ) );
	}

	/**
	 * Settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$saved = get_option( 'oc_tabs' );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'short_tab'  => 0,
				'short_open' => 0,
				'desc_place' => 'tab',   // tab | below.
				'desc_order' => 10,
				'additional' => 1,
				'add_order'  => 20,
				'custom'     => array(),
			)
		);
	}

	/* -------------------------------------------------------------- front */

	/**
	 * Placement side effects that must hook early: the short description
	 * leaving the summary, and the full description below the tabs.
	 */
	public function placement(): void {
		if ( is_admin() ) {
			return;
		}

		$settings = self::settings();

		// Short description moves out of the summary when it becomes a tab.
		if ( ! empty( $settings['short_tab'] ) ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
		}

		if ( 'below' === $settings['desc_place'] ) {
			add_action( 'woocommerce_after_single_product_summary', array( $this, 'description_below' ), 12 );
		}
	}

	/**
	 * The tab set, rebuilt: reviews leave for good, the built-ins follow the
	 * settings, and matching custom tabs join in their configured order.
	 *
	 * @param array<string,array<string,mixed>> $tabs Woo tabs.
	 * @return array<string,array<string,mixed>>
	 */
	public function tabs( array $tabs ): array {
		global $product;

		$settings = self::settings();

		// WordPress reviews never render as a tab — a dedicated mechanism
		// replaces them later.
		unset( $tabs['reviews'] );

		if ( empty( $settings['additional'] ) ) {
			unset( $tabs['additional_information'] );
		} elseif ( isset( $tabs['additional_information'] ) ) {
			$tabs['additional_information']['priority'] = (int) $settings['add_order'];
		}

		if ( 'below' === $settings['desc_place'] ) {
			unset( $tabs['description'] );
		} elseif ( isset( $tabs['description'] ) ) {
			$tabs['description']['priority'] = (int) $settings['desc_order'];
		}

		if ( ! empty( $settings['short_tab'] ) && $product instanceof \WC_Product && '' !== $product->get_short_description() ) {
			$tabs['oc_short'] = array(
				'title'    => __( 'Overview', 'oc-theme' ),
				'priority' => 1,
				'callback' => static function () use ( $product ) {
					echo '<div class="oc-tab-short">' . wp_kses_post( wpautop( do_shortcode( $product->get_short_description() ) ) ) . '</div>';
				},
			);
		}

		if ( $product instanceof \WC_Product ) {
			foreach ( (array) $settings['custom'] as $index => $tab ) {
				if ( empty( $tab['on'] ) || '' === (string) ( $tab['title'] ?? '' ) ) {
					continue;
				}
				if ( ! $this->tab_matches( $tab, $product ) ) {
					continue;
				}

				$content = (string) ( $tab['content'] ?? '' );

				$tabs[ 'oc_custom_' . $index ] = array(
					'title'    => (string) $tab['title'],
					'priority' => (int) ( $tab['order'] ?? 30 ),
					'callback' => static function () use ( $content ) {
						echo '<div class="oc-tab-custom">' . wp_kses_post( wpautop( do_shortcode( $content ) ) ) . '</div>';
					},
				);
			}
		}

		return $tabs;
	}

	/**
	 * Does a custom tab apply to this product? Scope first, exclusions last.
	 *
	 * @param array<string,mixed> $tab     Tab row.
	 * @param \WC_Product         $product The product.
	 */
	private function tab_matches( array $tab, \WC_Product $product ): bool {
		$product_id = (int) $product->get_id();

		// Product categories with ancestors — a rule on a parent covers children.
		$cats = wc_get_product_term_ids( $product_id, 'product_cat' );
		foreach ( $cats as $cat_id ) {
			$cats = array_merge( $cats, get_ancestors( (int) $cat_id, 'product_cat' ) );
		}
		$cats = array_map( 'intval', array_unique( $cats ) );

		// Exclusions win.
		$ex_ids = array_filter( array_map( 'absint', explode( ',', (string) ( $tab['ex_ids'] ?? '' ) ) ) );
		if ( in_array( $product_id, $ex_ids, true ) ) {
			return false;
		}

		$ex_cats = array_filter( array_map( 'absint', (array) ( $tab['ex_cats'] ?? array() ) ) );
		if ( $ex_cats && array_intersect( $ex_cats, $cats ) ) {
			return false;
		}

		$scope = (string) ( $tab['scope'] ?? 'all' );

		if ( 'all' === $scope ) {
			return true;
		}

		if ( 'products' === $scope ) {
			$ids = array_filter( array_map( 'absint', explode( ',', (string) ( $tab['ids'] ?? '' ) ) ) );
			return in_array( $product_id, $ids, true );
		}

		if ( 'categories' === $scope ) {
			$chosen = array_filter( array_map( 'absint', (array) ( $tab['cats'] ?? array() ) ) );
			return (bool) array_intersect( $chosen, $cats );
		}

		if ( 'attributes' === $scope ) {
			$term_ids = array_filter( array_map( 'absint', (array) ( $tab['attrs'] ?? array() ) ) );
			foreach ( $term_ids as $term_id ) {
				$term = get_term( $term_id );
				if ( $term instanceof \WP_Term && has_term( $term_id, $term->taxonomy, $product_id ) ) {
					return true;
				}
			}
			return false;
		}

		return false;
	}

	/**
	 * The full description below the tab area, when configured out of them.
	 */
	public function description_below(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$content = get_the_content( null, false, $product->get_id() );

		if ( '' === trim( (string) $content ) ) {
			return;
		}

		echo '<div class="oc-desc-below">' . wp_kses_post( wpautop( do_shortcode( (string) $content ) ) ) . '</div>';
	}

	/* --------------------------------------------------------------- admin */

	/**
	 * "Theme settings" — one home for every OC admin screen. This class
	 * carries the parent; the filters and waitlist screens re-parent here.
	 */
	public function menu(): void {
		add_menu_page(
			__( 'Theme settings', 'oc-theme' ),
			__( 'Theme settings', 'oc-theme' ),
			'manage_woocommerce',
			self::MENU,
			array( $this, 'admin_screen' ),
			'dashicons-layout',
			57
		);

		add_submenu_page(
			self::MENU,
			__( 'Product tabs', 'oc-theme' ),
			__( 'Product tabs', 'oc-theme' ),
			'manage_woocommerce',
			self::MENU,
			array( $this, 'admin_screen' )
		);
	}

	/**
	 * The tabs admin screen.
	 */
	public function admin_screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['oc_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}

		$settings = self::settings();
		$custom   = array_values( (array) $settings['custom'] );

		$all_cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		$attr_terms = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = 'pa_' . $attribute->attribute_name;
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			foreach ( (array) $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					$attr_terms[] = array(
						'id'    => (int) $term->term_id,
						'label' => $attribute->attribute_label . ': ' . $term->name,
					);
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Product tabs', 'oc-theme' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_tabs_save" />
				<?php wp_nonce_field( 'oc_tabs_save' ); ?>

				<h2><?php esc_html_e( 'Built-in tabs', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Short description', 'oc-theme' ); ?></th>
						<td>
							<label><input type="checkbox" name="short_tab" value="1" <?php checked( 1, (int) $settings['short_tab'] ); ?> /> <?php esc_html_e( 'Show as the first tab (instead of in the summary)', 'oc-theme' ); ?></label><br />
							<label style="margin-block-start:6px;display:inline-block;"><input type="checkbox" name="short_open" value="1" <?php checked( 1, (int) $settings['short_open'] ); ?> /> <?php esc_html_e( 'Open by default', 'oc-theme' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Full description', 'oc-theme' ); ?></th>
						<td>
							<select name="desc_place">
								<option value="tab" <?php selected( 'tab', $settings['desc_place'] ); ?>><?php esc_html_e( 'Inside a tab', 'oc-theme' ); ?></option>
								<option value="below" <?php selected( 'below', $settings['desc_place'] ); ?>><?php esc_html_e( 'Outside — below the tabs', 'oc-theme' ); ?></option>
							</select>
							<label style="margin-inline-start:12px;"><?php esc_html_e( 'Order', 'oc-theme' ); ?> <input type="number" name="desc_order" value="<?php echo esc_attr( (string) $settings['desc_order'] ); ?>" style="width:60px;" /></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Additional information', 'oc-theme' ); ?></th>
						<td>
							<label><input type="checkbox" name="additional" value="1" <?php checked( 1, (int) $settings['additional'] ); ?> /> <?php esc_html_e( 'Show the attributes table', 'oc-theme' ); ?></label>
							<label style="margin-inline-start:12px;"><?php esc_html_e( 'Order', 'oc-theme' ); ?> <input type="number" name="add_order" value="<?php echo esc_attr( (string) $settings['add_order'] ); ?>" style="width:60px;" /></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Reviews', 'oc-theme' ); ?></th>
						<td><p class="description"><?php esc_html_e( 'WordPress reviews never render as a tab — a dedicated mechanism replaces them.', 'oc-theme' ); ?></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Custom tabs', 'oc-theme' ); ?></h2>
				<div id="oc-tabs-rows">
					<?php
					$rows = $custom ? $custom : array();
					foreach ( $rows as $i => $row ) {
						$this->custom_row( $i, $row, $all_cats, $attr_terms );
					}
					?>
				</div>
				<p><button type="button" class="button" id="oc-tabs-add">+ <?php esc_html_e( 'Add a tab', 'oc-theme' ); ?></button></p>

				<template id="oc-tabs-template">
					<?php $this->custom_row( '__i__', array(), $all_cats, $attr_terms ); ?>
				</template>

				<script>
				( function () {
					var wrap = document.getElementById( 'oc-tabs-rows' );
					var tpl = document.getElementById( 'oc-tabs-template' );
					document.getElementById( 'oc-tabs-add' ).addEventListener( 'click', function () {
						var index = Date.now();
						var html = tpl.innerHTML.split( '__i__' ).join( index );
						var box = document.createElement( 'div' );
						box.innerHTML = html;
						while ( box.firstChild ) { wrap.appendChild( box.firstChild ); }
					} );
					wrap.addEventListener( 'click', function ( e ) {
						var del = e.target.closest( '.oc-tab-remove' );
						if ( del ) { del.closest( '.oc-tab-row' ).remove(); }
					} );
				} )();
				</script>

				<p style="margin-block-start:18px;"><button class="button button-primary"><?php esc_html_e( 'Save settings', 'oc-theme' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * One custom-tab editor row.
	 *
	 * @param int|string                     $i          Row index (or template placeholder).
	 * @param array<string,mixed>            $row        Row data.
	 * @param array<int,\WP_Term>            $all_cats   Product categories.
	 * @param array<int,array<string,mixed>> $attr_terms Attribute terms.
	 */
	private function custom_row( $i, array $row, array $all_cats, array $attr_terms ): void {
		$scope = (string) ( $row['scope'] ?? 'all' );
		?>
		<div class="oc-tab-row card" style="max-width:880px;padding:12px 20px 16px;margin-block-end:14px;">
			<p style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
				<label><input type="checkbox" name="ct_on[<?php echo esc_attr( (string) $i ); ?>]" value="1" <?php checked( 1, (int) ( $row['on'] ?? 1 ) ); ?> /> <?php esc_html_e( 'Active', 'oc-theme' ); ?></label>
				<label><?php esc_html_e( 'Order', 'oc-theme' ); ?> <input type="number" name="ct_order[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['order'] ?? 30 ) ); ?>" style="width:60px;" /></label>
				<input type="text" name="ct_title[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['title'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Tab title', 'oc-theme' ); ?>" class="regular-text" />
				<button type="button" class="button-link-delete oc-tab-remove" style="margin-inline-start:auto;"><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button>
			</p>
			<textarea name="ct_content[<?php echo esc_attr( (string) $i ); ?>]" rows="4" style="width:100%;" placeholder="<?php esc_attr_e( 'Tab content — text, HTML and shortcodes', 'oc-theme' ); ?>"><?php echo esc_textarea( (string) ( $row['content'] ?? '' ) ); ?></textarea>
			<p style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;margin-block-end:0;">
				<label><?php esc_html_e( 'Shown on', 'oc-theme' ); ?><br />
					<select name="ct_scope[<?php echo esc_attr( (string) $i ); ?>]">
						<option value="all" <?php selected( 'all', $scope ); ?>><?php esc_html_e( 'All products', 'oc-theme' ); ?></option>
						<option value="products" <?php selected( 'products', $scope ); ?>><?php esc_html_e( 'Specific products', 'oc-theme' ); ?></option>
						<option value="categories" <?php selected( 'categories', $scope ); ?>><?php esc_html_e( 'Specific categories', 'oc-theme' ); ?></option>
						<option value="attributes" <?php selected( 'attributes', $scope ); ?>><?php esc_html_e( 'Specific attributes', 'oc-theme' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Product IDs (comma-separated)', 'oc-theme' ); ?><br />
					<input type="text" name="ct_ids[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['ids'] ?? '' ) ); ?>" dir="ltr" />
				</label>
				<label><?php esc_html_e( 'Categories', 'oc-theme' ); ?><br />
					<select name="ct_cats[<?php echo esc_attr( (string) $i ); ?>][]" multiple size="4">
						<?php foreach ( $all_cats as $cat ) : ?>
							<option value="<?php echo absint( $cat->term_id ); ?>" <?php echo in_array( (int) $cat->term_id, array_map( 'intval', (array) ( $row['cats'] ?? array() ) ), true ) ? 'selected' : ''; ?>><?php echo esc_html( $cat->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Attribute values', 'oc-theme' ); ?><br />
					<select name="ct_attrs[<?php echo esc_attr( (string) $i ); ?>][]" multiple size="4">
						<?php foreach ( $attr_terms as $term ) : ?>
							<option value="<?php echo absint( $term['id'] ); ?>" <?php echo in_array( (int) $term['id'], array_map( 'intval', (array) ( $row['attrs'] ?? array() ) ), true ) ? 'selected' : ''; ?>><?php echo esc_html( $term['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Exclude categories', 'oc-theme' ); ?><br />
					<select name="ct_ex_cats[<?php echo esc_attr( (string) $i ); ?>][]" multiple size="4">
						<?php foreach ( $all_cats as $cat ) : ?>
							<option value="<?php echo absint( $cat->term_id ); ?>" <?php echo in_array( (int) $cat->term_id, array_map( 'intval', (array) ( $row['ex_cats'] ?? array() ) ), true ) ? 'selected' : ''; ?>><?php echo esc_html( $cat->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Exclude product IDs', 'oc-theme' ); ?><br />
					<input type="text" name="ct_ex_ids[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['ex_ids'] ?? '' ) ); ?>" dir="ltr" />
				</label>
			</p>
		</div>
		<?php
	}

	/**
	 * Persist the screen.
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}
		check_admin_referer( 'oc_tabs_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$custom = array();

		foreach ( (array) ( $_POST['ct_title'] ?? array() ) as $i => $title ) {
			$title = sanitize_text_field( wp_unslash( (string) $title ) );
			$body  = wp_kses_post( wp_unslash( (string) ( $_POST['ct_content'][ $i ] ?? '' ) ) );

			if ( '' === $title && '' === trim( $body ) ) {
				continue;
			}

			$custom[] = array(
				'on'      => empty( $_POST['ct_on'][ $i ] ) ? 0 : 1,
				'order'   => (int) ( $_POST['ct_order'][ $i ] ?? 30 ),
				'title'   => $title,
				'content' => $body,
				'scope'   => in_array( $_POST['ct_scope'][ $i ] ?? 'all', array( 'all', 'products', 'categories', 'attributes' ), true ) ? sanitize_key( $_POST['ct_scope'][ $i ] ) : 'all',
				'ids'     => sanitize_text_field( wp_unslash( (string) ( $_POST['ct_ids'][ $i ] ?? '' ) ) ),
				'cats'    => array_values( array_filter( array_map( 'absint', (array) ( $_POST['ct_cats'][ $i ] ?? array() ) ) ) ),
				'attrs'   => array_values( array_filter( array_map( 'absint', (array) ( $_POST['ct_attrs'][ $i ] ?? array() ) ) ) ),
				'ex_cats' => array_values( array_filter( array_map( 'absint', (array) ( $_POST['ct_ex_cats'][ $i ] ?? array() ) ) ) ),
				'ex_ids'  => sanitize_text_field( wp_unslash( (string) ( $_POST['ct_ex_ids'][ $i ] ?? '' ) ) ),
			);
		}

		update_option(
			'oc_tabs',
			array(
				'short_tab'  => empty( $_POST['short_tab'] ) ? 0 : 1,
				'short_open' => empty( $_POST['short_open'] ) ? 0 : 1,
				'desc_place' => 'below' === ( $_POST['desc_place'] ?? 'tab' ) ? 'below' : 'tab',
				'desc_order' => (int) ( $_POST['desc_order'] ?? 10 ),
				'additional' => empty( $_POST['additional'] ) ? 0 : 1,
				'add_order'  => (int) ( $_POST['add_order'] ?? 20 ),
				'custom'     => $custom,
			),
			false
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'oc_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
