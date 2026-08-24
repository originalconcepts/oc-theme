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
		// A shop feature on a site with no shop. The theme's contract is to
		// run as plain WordPress with an admin notice, never to fatal — the
		// demo always has WooCommerce, so only a fresh site can catch this.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ), 55 );
		add_action( 'admin_post_oc_tabs_save', array( $this, 'save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		add_filter( 'woocommerce_product_tabs', array( $this, 'tabs' ), 40 );
		add_action( 'init', array( $this, 'placement' ) );

		// Per-product tabs, managed straight from the product edit screen.
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'product_data_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'product_tabs_save' ) );
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
				'short_tab'   => 0,
				'short_open'  => 0,
				'short_title' => '',
				'desc_place'  => 'tab',   // tab | below.
				'desc_open'   => 0,
				'desc_order'  => 10,
				'desc_title'  => '',
				'additional'  => 1,
				'add_order'   => 20,
				'custom'      => array(),
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

		if ( '' !== (string) $settings['desc_title'] ) {
			add_filter(
				'woocommerce_product_description_heading',
				static function () use ( $settings ) {
					return (string) $settings['desc_title'];
				}
			);
		}
	}

	/**
	 * Editor, media and product-search assets for the tabs screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function admin_assets( $hook ): void {
		// Product edit screens render editors server-side, which never defines
		// wp.editor.getDefaultSettings — and without it wp.editor.initialize
		// is a silent no-op. The per-product tab editors need the full kit.
		if ( in_array( (string) $hook, array( 'post.php', 'post-new.php' ), true ) && 'product' === get_post_type() ) {
			wp_enqueue_editor();
			return;
		}

		if ( false === strpos( (string) $hook, self::MENU ) ) {
			return;
		}

		wp_enqueue_editor();
		wp_enqueue_media();
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
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
			if ( '' !== (string) $settings['desc_title'] ) {
				$tabs['description']['title'] = (string) $settings['desc_title'];
			}
		}

		if ( ! empty( $settings['short_tab'] ) && $product instanceof \WC_Product && '' !== $product->get_short_description() ) {
			$short_title       = '' !== (string) $settings['short_title'] ? (string) $settings['short_title'] : __( 'About this item', 'oc-theme' );
			$tabs['oc_short'] = array(
				'title'    => $short_title,
				'priority' => 1,
				'callback' => static function () use ( $product, $short_title ) {
					// The heading feeds the accordion's title (CSS hides it).
					echo '<h2>' . esc_html( $short_title ) . '</h2>';
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
				$title   = (string) $tab['title'];

				$tabs[ 'oc_custom_' . $index ] = array(
					'title'    => $title,
					'priority' => (int) ( $tab['order'] ?? 30 ),
					'callback' => static function () use ( $content, $title ) {
						// The heading feeds the accordion's title (CSS hides it).
						echo '<h2>' . esc_html( $title ) . '</h2>';
						echo '<div class="oc-tab-custom">' . wp_kses_post( wpautop( do_shortcode( $content ) ) ) . '</div>';
					},
				);
			}

			// This product's own tabs, saved on its edit screen.
			$own = $product->get_meta( '_oc_product_tabs' );
			foreach ( ( is_array( $own ) ? $own : array() ) as $index => $tab ) {
				if ( ! is_array( $tab ) ) {
					continue;
				}
				$title   = (string) ( $tab['title'] ?? '' );
				$content = (string) ( $tab['content'] ?? '' );

				if ( '' === $title ) {
					continue;
				}

				$tabs[ 'oc_ptab_' . $index ] = array(
					'title'    => $title,
					'priority' => (int) ( $tab['order'] ?? 30 ),
					'callback' => static function () use ( $content, $title ) {
						// The heading feeds the accordion's title (CSS hides it).
						echo '<h2>' . esc_html( $title ) . '</h2>';
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

		$heading = (string) apply_filters( 'woocommerce_product_description_heading', __( 'Description', 'woocommerce' ) );

		echo '<div class="oc-desc-below">';
		if ( '' !== $heading ) {
			echo '<h2 class="oc-desc-below__title">' . esc_html( $heading ) . '</h2>';
		}
		echo wp_kses_post( wpautop( do_shortcode( (string) $content ) ) );
		echo '</div>';
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

				<style>
				/* checkbox → modern toggle; WP admin's own checkbox styling
				 * (border, tick ::before, 1rem sizing) is fully overridden. */
				.oc-tgl { position: relative; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
				.oc-tgl input[type=checkbox] {
					appearance: none !important;
					-webkit-appearance: none !important;
					width: 36px !important;
					height: 20px !important;
					min-width: 36px;
					border: 0 !important;
					border-radius: 999px !important;
					background: #cfcfcf !important;
					box-shadow: none !important;
					margin: 0 !important;
					position: relative;
					cursor: pointer;
					transition: background .18s ease;
					vertical-align: middle;
				}
				.oc-tgl input[type=checkbox]::before,
				.oc-tgl input[type=checkbox]:checked::before {
					content: "" !important;
					position: absolute;
					top: 2px;
					inset-inline-start: 2px;
					width: 16px !important;
					height: 16px !important;
					margin: 0 !important;
					float: none !important;
					border-radius: 50%;
					background: #fff;
					box-shadow: 0 1px 3px rgb(0 0 0 / .3);
					transition: translate .18s ease;
				}
				.oc-tgl input[type=checkbox]:checked { background: #1c1c1c !important; }
				.oc-tgl input[type=checkbox]:checked::before { translate: 16px 0; }
				body.rtl .oc-tgl input[type=checkbox]:checked::before { translate: -16px 0; }

				/* custom-tab rows: the same framed cards as the per-product
				 * panel — title alone, position, toggle, remove at the end,
				 * the editor filling the frame. */
				.oc-tab-row {
					display: flow-root;
					border: 1px solid #dcdcde;
					border-radius: 6px;
					background: #fff;
					padding: 14px 16px;
					margin: 0 0 12px;
					max-width: 880px;
				}
				.oc-ct-head {
					display: flex;
					gap: 14px;
					align-items: center;
					flex-wrap: wrap;
					margin: 0 0 12px;
				}
				.oc-ct-head input[type=text] { width: 320px; max-width: 100%; }
				.oc-ct-head input[type=number] { width: 70px; }
				.oc-tab-row .wp-editor-wrap,
				.oc-tab-row textarea.oc-ct-editor { width: 100%; }
				.oc-tab-remove { margin-inline-start: auto; }
				.oc-ct-scope {
					display: flex;
					gap: 14px;
					flex-wrap: wrap;
					align-items: flex-start;
					margin: 12px 0 0;
				}
				#oc-tabs-add { margin: 2px 0 14px; }
				</style>

				<h2><?php esc_html_e( 'Tab titles', 'oc-theme' ); ?></h2>
				<p class="description" style="margin-block-start:0;">
					<?php
					printf(
						/* translators: %s: link to the Customizer section. */
						esc_html__( 'Which built-in tabs appear, and in what order, is set in %s.', 'oc-theme' ),
						'<a href="' . esc_url( admin_url( 'customize.php?autofocus[section]=oc_tabs_cfg' ) ) . '">' . esc_html__( 'Customize → Product tabs', 'oc-theme' ) . '</a>'
					);
					?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Short description', 'oc-theme' ); ?></th>
						<td><input type="text" name="short_title" value="<?php echo esc_attr( (string) $settings['short_title'] ); ?>" placeholder="<?php esc_attr_e( 'About this item', 'oc-theme' ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Full description', 'oc-theme' ); ?></th>
						<td><input type="text" name="desc_title" value="<?php echo esc_attr( (string) $settings['desc_title'] ); ?>" placeholder="<?php esc_attr_e( 'Tab title (empty = default)', 'oc-theme' ); ?>" class="regular-text" /></td>
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

					function initRow( scope ) {
						scope.querySelectorAll( '.oc-ct-editor' ).forEach( function ( area ) {
							if ( area.dataset.ready || ! window.wp || ! wp.editor ) { return; }
							area.dataset.ready = '1';
							wp.editor.initialize( area.id, {
								tinymce: { toolbar1: 'formatselect,bold,italic,underline,link,unlink,bullist,numlist,alignleft,aligncenter,alignright,image', height: 180 },
								quicktags: true,
								mediaButtons: true
							} );
						} );
						if ( window.jQuery ) { jQuery( document.body ).trigger( 'wc-enhanced-select-init' ); }
					}

					// The editor scripts load in the footer — wait for them.
					( function whenReady() {
						if ( window.wp && wp.editor && window.tinymce ) {
							initRow( wrap );
						} else {
							setTimeout( whenReady, 200 );
						}
					} )();

					document.getElementById( 'oc-tabs-add' ).addEventListener( 'click', function () {
						var index = Date.now();
						var html = tpl.innerHTML.split( '__i__' ).join( index );
						var box = document.createElement( 'div' );
						box.innerHTML = html;
						while ( box.firstChild ) { wrap.appendChild( box.firstChild ); }
						initRow( wrap );
					} );

					wrap.addEventListener( 'click', function ( e ) {
						var del = e.target.closest( '.oc-tab-remove' );
						if ( del ) { del.closest( '.oc-tab-row' ).remove(); }
					} );

					// TinyMCE keeps content in its iframe — sync back on submit.
					document.querySelector( 'form input[name=action][value=oc_tabs_save]' ).closest( 'form' ).addEventListener( 'submit', function () {
						if ( window.tinymce ) { tinymce.triggerSave(); }
					} );

					var shortTab = document.getElementById( 'oc-short-tab' );
					var shortExtra = document.getElementById( 'oc-short-extra' );
					shortTab.addEventListener( 'change', function () {
						shortExtra.style.display = shortTab.checked ? 'block' : 'none';
					} );

					// Open-by-default only makes sense while the description
					// actually is a tab.
					var descPlace = document.getElementById( 'oc-desc-place' );
					var descOpen = document.getElementById( 'oc-desc-open' );
					descPlace.addEventListener( 'change', function () {
						descOpen.style.display = 'tab' === descPlace.value ? 'block' : 'none';
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
		<div class="oc-tab-row">
			<div class="oc-ct-head">
				<input type="text" name="ct_title[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['title'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Tab title', 'oc-theme' ); ?>" />
				<label><?php esc_html_e( 'Position', 'oc-theme' ); ?> <input type="number" name="ct_order[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['order'] ?? 30 ) ); ?>" /></label>
				<label class="oc-tgl"><input type="checkbox" name="ct_on[<?php echo esc_attr( (string) $i ); ?>]" value="1" <?php checked( 1, (int) ( $row['on'] ?? 1 ) ); ?> /> <?php esc_html_e( 'Active', 'oc-theme' ); ?></label>
				<button type="button" class="button-link-delete oc-tab-remove"><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button>
			</div>
			<textarea name="ct_content[<?php echo esc_attr( (string) $i ); ?>]" id="oc-ct-content-<?php echo esc_attr( (string) $i ); ?>" class="oc-ct-editor" rows="6" placeholder="<?php esc_attr_e( 'Tab content — text, HTML and shortcodes', 'oc-theme' ); ?>"><?php echo esc_textarea( (string) ( $row['content'] ?? '' ) ); ?></textarea>
			<div class="oc-ct-scope">
				<label><?php esc_html_e( 'Shown on', 'oc-theme' ); ?><br />
					<select name="ct_scope[<?php echo esc_attr( (string) $i ); ?>]">
						<option value="all" <?php selected( 'all', $scope ); ?>><?php esc_html_e( 'All products', 'oc-theme' ); ?></option>
						<option value="products" <?php selected( 'products', $scope ); ?>><?php esc_html_e( 'Specific products', 'oc-theme' ); ?></option>
						<option value="categories" <?php selected( 'categories', $scope ); ?>><?php esc_html_e( 'Specific categories', 'oc-theme' ); ?></option>
						<option value="attributes" <?php selected( 'attributes', $scope ); ?>><?php esc_html_e( 'Specific attributes', 'oc-theme' ); ?></option>
					</select>
				</label>
				<label style="min-width:230px;"><?php esc_html_e( 'Products', 'oc-theme' ); ?><br />
					<select class="wc-product-search" multiple style="width:230px;" name="ct_ids[<?php echo esc_attr( (string) $i ); ?>][]" data-placeholder="<?php esc_attr_e( 'Search products…', 'oc-theme' ); ?>" data-action="woocommerce_json_search_products_and_variations">
						<?php foreach ( array_filter( array_map( 'absint', explode( ',', (string) ( $row['ids'] ?? '' ) ) ) ) as $pid ) : ?>
							<?php $p_obj = wc_get_product( $pid ); ?>
							<?php if ( $p_obj ) : ?>
								<option value="<?php echo absint( $pid ); ?>" selected><?php echo esc_html( $p_obj->get_name() . ' (#' . $pid . ')' ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
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
				<label style="min-width:230px;"><?php esc_html_e( 'Exclude products', 'oc-theme' ); ?><br />
					<select class="wc-product-search" multiple style="width:230px;" name="ct_ex_ids[<?php echo esc_attr( (string) $i ); ?>][]" data-placeholder="<?php esc_attr_e( 'Search products…', 'oc-theme' ); ?>" data-action="woocommerce_json_search_products_and_variations">
						<?php foreach ( array_filter( array_map( 'absint', explode( ',', (string) ( $row['ex_ids'] ?? '' ) ) ) ) as $pid ) : ?>
							<?php $p_obj = wc_get_product( $pid ); ?>
							<?php if ( $p_obj ) : ?>
								<option value="<?php echo absint( $pid ); ?>" selected><?php echo esc_html( $p_obj->get_name() . ' (#' . $pid . ')' ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
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
				'ids'     => implode( ',', array_filter( array_map( 'absint', (array) ( $_POST['ct_ids'][ $i ] ?? array() ) ) ) ),
				'cats'    => array_values( array_filter( array_map( 'absint', (array) ( $_POST['ct_cats'][ $i ] ?? array() ) ) ) ),
				'attrs'   => array_values( array_filter( array_map( 'absint', (array) ( $_POST['ct_attrs'][ $i ] ?? array() ) ) ) ),
				'ex_cats' => array_values( array_filter( array_map( 'absint', (array) ( $_POST['ct_ex_cats'][ $i ] ?? array() ) ) ) ),
				'ex_ids'  => implode( ',', array_filter( array_map( 'absint', (array) ( $_POST['ct_ex_ids'][ $i ] ?? array() ) ) ) ),
			);
		}

		// Only the keys this form still owns are written; the rest of the
		// array belongs to the Customizer and must survive untouched.
		update_option(
			'oc_tabs',
			array_merge(
				self::settings(),
				array(
					'short_title' => sanitize_text_field( wp_unslash( (string) ( $_POST['short_title'] ?? '' ) ) ),
					'desc_title'  => sanitize_text_field( wp_unslash( (string) ( $_POST['desc_title'] ?? '' ) ) ),
					'custom'      => $custom,
				)
			),
			false
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'oc_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* -------------------------------------------- per-product tabs (admin) */

	/**
	 * "Tabs" tab in the product-data metabox.
	 *
	 * @param array<string,array<string,mixed>> $tabs Product-data tabs.
	 * @return array<string,array<string,mixed>>
	 */
	public function product_data_tab( array $tabs ): array {
		// 67: right below the Video tab (66).
		$tabs['oc_tabs'] = array(
			'label'    => __( 'Tabs', 'oc-theme' ),
			'target'   => 'oc_product_tabs_data',
			'class'    => array(),
			'priority' => 67,
		);

		return $tabs;
	}

	/**
	 * The panel: this product's own tabs — title, content, position — that
	 * join the global set on its page.
	 */
	public function product_data_panel(): void {
		global $post;

		$saved = get_post_meta( (int) $post->ID, '_oc_product_tabs', true );
		$rows  = is_array( $saved ) ? array_values( array_filter( $saved, 'is_array' ) ) : array();
		?>
		<div id="oc_product_tabs_data" class="panel woocommerce_options_panel hidden">
			<style>
			/* Woo's options-panel floats labels and half-widths textareas —
			 * inside a tab row that collapsed the frame around the content.
			 * Everything here lays out on its own terms. */
			#oc_product_tabs_data .oc-pt-row {
				display: flow-root;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				background: #fff;
				padding: 14px 16px;
				margin: 0 0 12px;
			}
			#oc_product_tabs_data .oc-pt-head {
				display: flex;
				gap: 14px;
				align-items: center;
				flex-wrap: wrap;
				margin: 0 0 12px;
				padding: 0;
			}
			#oc_product_tabs_data .oc-pt-row label {
				float: none;
				width: auto;
				margin: 0;
				padding: 0;
				display: inline-flex;
				align-items: center;
				gap: 7px;
			}
			#oc_product_tabs_data .oc-pt-row input[type=text] {
				width: 320px;
				max-width: 100%;
				float: none;
			}
			#oc_product_tabs_data .oc-pt-row input[type=number] {
				width: 70px;
				float: none;
			}
			#oc_product_tabs_data .oc-pt-row textarea.oc-pt-editor,
			#oc_product_tabs_data .oc-pt-row .wp-editor-wrap {
				float: none;
				width: 100%;
			}
			#oc_product_tabs_data .oc-pt-remove { margin-inline-start: auto; }
			#oc_product_tabs_data .oc-pt-add { margin: 2px 0 14px; }
			</style>
			<div style="padding:12px 14px 4px;">
				<p class="description" style="margin-block-start:0;margin-block-end:12px;"><?php esc_html_e( 'Tabs for this product only — they join the global tabs. A lower position number appears earlier: description 10, additional information 20.', 'oc-theme' ); ?></p>
				<div id="oc-ptabs-rows">
					<?php
					foreach ( $rows as $i => $row ) {
						$this->product_tab_row( $i, $row );
					}
					?>
				</div>
				<button type="button" class="button oc-pt-add" id="oc-ptabs-add">+ <?php esc_html_e( 'Add a tab', 'oc-theme' ); ?></button>
			</div>

			<template id="oc-ptabs-template">
				<?php $this->product_tab_row( '__i__', array() ); ?>
			</template>

			<script>
			( function () {
				var wrap = document.getElementById( 'oc-ptabs-rows' );
				var tpl = document.getElementById( 'oc-ptabs-template' );

				function initRows() {
					// The editor scripts may still be loading — wait for them.
					if ( ! window.wp || ! wp.editor || ! window.tinymce ) {
						setTimeout( initRows, 200 );
						return;
					}
					wrap.querySelectorAll( '.oc-pt-editor' ).forEach( function ( area ) {
						if ( area.dataset.ready ) { return; }
						area.dataset.ready = '1';
						wp.editor.initialize( area.id, {
							tinymce: { toolbar1: 'formatselect,bold,italic,underline,link,unlink,bullist,numlist,alignleft,aligncenter,alignright,image', height: 180 },
							quicktags: true,
							mediaButtons: true
						} );
					} );
				}

				// TinyMCE inside a hidden panel renders broken — initialize
				// only once the panel is actually shown (and for rows added
				// later, right away, since the panel is visible by then).
				document.querySelectorAll( '.oc_tabs_options a, a[href="#oc_product_tabs_data"]' ).forEach( function ( link ) {
					link.addEventListener( 'click', function () {
						setTimeout( initRows, 60 );
					} );
				} );

				document.getElementById( 'oc-ptabs-add' ).addEventListener( 'click', function () {
					var index = Date.now();
					var html = tpl.innerHTML.split( '__i__' ).join( index );
					var box = document.createElement( 'div' );
					box.innerHTML = html;
					while ( box.firstChild ) { wrap.appendChild( box.firstChild ); }
					initRows();
				} );

				wrap.addEventListener( 'click', function ( e ) {
					var del = e.target.closest( '.oc-pt-remove' );
					if ( del ) { del.closest( '.oc-pt-row' ).remove(); }
				} );

				// TinyMCE keeps content in its iframe — sync back on save.
				var form = document.getElementById( 'post' );
				if ( form ) {
					form.addEventListener( 'submit', function () {
						if ( window.tinymce ) { tinymce.triggerSave(); }
					} );
				}
			} )();
			</script>
		</div>
		<?php
	}

	/**
	 * One per-product tab row.
	 *
	 * @param int|string          $i   Row index (or template placeholder).
	 * @param array<string,mixed> $row Row data.
	 */
	private function product_tab_row( $i, array $row ): void {
		?>
		<div class="oc-pt-row">
			<div class="oc-pt-head">
				<input type="text" name="pt_title[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['title'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Tab title', 'oc-theme' ); ?>" />
				<label><?php esc_html_e( 'Position', 'oc-theme' ); ?> <input type="number" name="pt_order[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['order'] ?? 30 ) ); ?>" /></label>
				<button type="button" class="button-link-delete oc-pt-remove"><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button>
			</div>
			<textarea name="pt_content[<?php echo esc_attr( (string) $i ); ?>]" id="oc-pt-content-<?php echo esc_attr( (string) $i ); ?>" class="oc-pt-editor" rows="6" placeholder="<?php esc_attr_e( 'Tab content — text, HTML and shortcodes', 'oc-theme' ); ?>"><?php echo esc_textarea( (string) ( $row['content'] ?? '' ) ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Persist this product's tabs with the product itself.
	 *
	 * @param \WC_Product $product Product being saved.
	 */
	public function product_tabs_save( $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Woo verified the product-save nonce already.
		if ( ! isset( $_POST['pt_title'] ) && ! isset( $_POST['pt_content'] ) ) {
			return; // Quick-edit and API saves never touch the rows.
		}

		$rows = array();

		foreach ( (array) ( $_POST['pt_title'] ?? array() ) as $i => $title ) {
			$title = sanitize_text_field( wp_unslash( (string) $title ) );
			$body  = wp_kses_post( wp_unslash( (string) ( $_POST['pt_content'][ $i ] ?? '' ) ) );

			if ( '' === $title && '' === trim( $body ) ) {
				continue;
			}

			$rows[] = array(
				'title'   => $title,
				'order'   => (int) ( $_POST['pt_order'][ $i ] ?? 30 ),
				'content' => $body,
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $rows ) {
			$product->update_meta_data( '_oc_product_tabs', $rows );
		} else {
			$product->delete_meta_data( '_oc_product_tabs' );
		}
	}
}
