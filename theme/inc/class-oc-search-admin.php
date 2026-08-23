<?php
/**
 * Running the search: what it looks in, what it favours, and what it learned.
 *
 * Appearance belongs to the Customizer — an agency sets it once. Everything
 * here is the shop's own work: the words it should also answer to, the
 * products it wants in front, and the report of what people asked for and
 * did not find.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The admin side of search.
 */
final class Search_Admin {

	const PAGE = 'oc-search';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'brand_taxonomy' ), 5 );
		add_action( 'admin_menu', array( $this, 'menu' ), 58 );
		add_action( 'admin_post_oc_search_save', array( $this, 'save' ) );
		add_action( 'wp_ajax_oc_search_build', array( $this, 'ajax_build' ) );
		add_action( 'admin_post_oc_search_build_step', array( $this, 'build_step' ) );

		// A word list on every kind of term the search reads — including
		// whichever taxonomy this site uses for brands.
		foreach ( array_filter( array( 'product_cat', 'product_tag', 'oc_brand', 'product_brand', 'pwb-brand' ) ) as $tax ) {
			add_action( $tax . '_edit_form_fields', array( $this, 'term_field' ), 20 );
			add_action( 'edited_' . $tax, array( $this, 'save_term' ) );
		}

		add_action( 'admin_init', array( $this, 'attribute_terms' ) );

		add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'product_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ) );
	}

	/**
	 * Brands, if nothing else on the site provides them.
	 *
	 * WooCommerce ships its own brand taxonomy in recent versions and some
	 * plugins bring one too; this only fills a gap.
	 */
	public function brand_taxonomy(): void {
		if ( taxonomy_exists( 'product_brand' ) || taxonomy_exists( 'pwb-brand' ) ) {
			return;
		}

		register_taxonomy(
			'oc_brand',
			'product',
			array(
				'labels'            => array(
					'name'          => __( 'Brands', 'oc-theme' ),
					'singular_name' => __( 'Brand', 'oc-theme' ),
					'add_new_item'  => __( 'Add a brand', 'oc-theme' ),
					'edit_item'     => __( 'Edit brand', 'oc-theme' ),
					'search_items'  => __( 'Search brands', 'oc-theme' ),
					'not_found'     => __( 'No brands yet.', 'oc-theme' ),
				),
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'brand' ),
			)
		);
	}

	/**
	 * Attribute terms carry synonyms too — that is where colours live.
	 */
	public function attribute_terms(): void {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$tax = wc_attribute_taxonomy_name( $attribute->attribute_name );

			if ( ! $tax ) {
				continue;
			}

			add_action( $tax . '_edit_form_fields', array( $this, 'term_field' ), 20 );
			add_action( 'edited_' . $tax, array( $this, 'save_term' ) );
		}
	}

	/* --------------------------------------------------------------- terms */

	/**
	 * "Also answers to" on a term.
	 *
	 * @param \WP_Term $term Term.
	 */
	public function term_field( $term ): void {
		$value = (string) get_term_meta( (int) $term->term_id, '_oc_syn', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="oc_syn"><?php esc_html_e( 'Also answers to', 'oc-theme' ); ?></label></th>
			<td>
				<input type="text" name="oc_syn" id="oc_syn" value="<?php echo esc_attr( $value ); ?>" class="large-text" />
				<p class="description" style="max-inline-size:640px;">
					<?php esc_html_e( 'Words separated by commas. A shopper who types any of them finds everything carrying this one — so on the colour red, write: אדומה, אדומים, אדומות.', 'oc-theme' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save it, and rebuild what it touches.
	 *
	 * @param int $term_id Term id.
	 */
	public function save_term( $term_id ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the term nonce first.
		$value = isset( $_POST['oc_syn'] ) ? sanitize_text_field( wp_unslash( $_POST['oc_syn'] ) ) : '';

		if ( '' === $value ) {
			delete_term_meta( $term_id, '_oc_syn' );
		} else {
			update_term_meta( $term_id, '_oc_syn', $value );
		}

		$this->requeue_term( (int) $term_id );
	}

	/**
	 * The products that carry a term need their words written again.
	 *
	 * @param int $term_id Term id.
	 */
	private function requeue_term( int $term_id ): void {
		$term = get_term( $term_id );

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => $term->taxonomy,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			Search_Index::index_product( (int) $id );
		}
	}

	/* ------------------------------------------------------------- product */

	/**
	 * A search tab on the product.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function product_tab( $tabs ) {
		$tabs['oc_search'] = array(
			'label'    => __( 'Search', 'oc-theme' ),
			'target'   => 'oc_search_panel',
			'class'    => array(),
			'priority' => 72,
		);

		return $tabs;
	}

	/**
	 * Its fields.
	 */
	public function product_panel(): void {
		global $post;

		$syn   = (string) get_post_meta( (int) $post->ID, '_oc_syn', true );
		$boost = (int) get_post_meta( (int) $post->ID, '_oc_search_boost', true );
		$hide  = (bool) get_post_meta( (int) $post->ID, '_oc_search_hide', true );
		?>
		<div id="oc_search_panel" class="panel woocommerce_options_panel">
			<div style="padding:12px;">
				<p style="margin:0 0 4px;">
					<label style="float:none;display:block;margin:0 0 4px;"><?php esc_html_e( 'Also answers to', 'oc-theme' ); ?></label>
					<input type="text" name="oc_syn" value="<?php echo esc_attr( $syn ); ?>" style="inline-size:100%;" />
				</p>
				<p class="description" style="margin:0 0 18px;"><?php esc_html_e( 'Words separated by commas — a nickname, a former name, the English spelling.', 'oc-theme' ); ?></p>

				<p style="display:flex;align-items:center;gap:10px;margin:0 0 4px;">
					<label style="float:none;inline-size:auto;margin:0;"><?php esc_html_e( 'Position in results', 'oc-theme' ); ?></label>
					<input type="number" name="oc_search_boost" min="-100" max="100" value="<?php echo esc_attr( (string) $boost ); ?>" style="inline-size:90px;" />
				</p>
				<p class="description" style="margin:0 0 18px;"><?php esc_html_e( 'Above zero pushes this product up in every search; below zero holds it back. 0 leaves it to the ranking.', 'oc-theme' ); ?></p>

				<p style="margin:0;">
					<label><input type="checkbox" name="oc_search_hide" value="1" <?php checked( true, $hide ); ?> /> <?php esc_html_e( 'Keep this product out of search results', 'oc-theme' ); ?></label>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Save them.
	 *
	 * @param int $product_id Product id.
	 */
	public function save_product( $product_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies before this fires.
		$syn = isset( $_POST['oc_syn'] ) ? sanitize_text_field( wp_unslash( $_POST['oc_syn'] ) ) : '';

		if ( '' === $syn ) {
			delete_post_meta( $product_id, '_oc_syn' );
		} else {
			update_post_meta( $product_id, '_oc_syn', $syn );
		}

		update_post_meta( $product_id, '_oc_search_boost', max( -100, min( 100, (int) ( $_POST['oc_search_boost'] ?? 0 ) ) ) );
		update_post_meta( $product_id, '_oc_search_hide', empty( $_POST['oc_search_hide'] ) ? '' : 1 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/* -------------------------------------------------------------- screen */

	/**
	 * The menu entry.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			__( 'Search', 'oc-theme' ),
			__( 'Search', 'oc-theme' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'screen' )
		);
	}

	/**
	 * Which tab is open.
	 */
	private function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'where';

		return in_array( $tab, array( 'where', 'popular', 'boost', 'synonyms', 'reports', 'index' ), true ) ? $tab : 'where';
	}

	/**
	 * The screen.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s   = Search::settings();
		$tab = $this->tab();

		$tabs = array(
			'where'    => __( 'Where to look', 'oc-theme' ),
			'popular'  => __( 'Popular', 'oc-theme' ),
			'boost'    => __( 'Promotion', 'oc-theme' ),
			'synonyms' => __( 'Synonyms', 'oc-theme' ),
			'reports'  => __( 'Reports', 'oc-theme' ),
			'index'    => __( 'Index', 'oc-theme' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['oc_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search', 'oc-theme' ); ?></h1>

			<h2 class="nav-tab-wrapper" style="margin-block-end:20px;">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $key ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'reports' === $tab ) : ?>
				<?php $this->reports(); ?>
			<?php elseif ( 'index' === $tab ) : ?>
				<?php $this->index_tab(); ?>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'oc_search_save' ); ?>
					<input type="hidden" name="action" value="oc_search_save" />
					<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />

					<?php
					switch ( $tab ) {
						case 'popular':
							$this->tab_popular( $s );
							break;
						case 'boost':
							$this->tab_boost( $s );
							break;
						case 'synonyms':
							$this->tab_synonyms();
							break;
						default:
							$this->tab_where( $s );
					}
					?>

					<?php submit_button( __( 'Save settings', 'oc-theme' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Where to look.
	 *
	 * @param array $s Settings.
	 */
	private function tab_where( array $s ): void {
		$fields = array(
			'f_sku'   => array( __( 'Product code', 'oc-theme' ), 'w_sku' ),
			'f_desc'  => array( __( 'Description', 'oc-theme' ), 'w_desc' ),
			'f_tag'   => array( __( 'Tags', 'oc-theme' ), 'w_tag' ),
			'f_attr'  => array( __( 'Attributes', 'oc-theme' ), 'w_attr' ),
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Fields', 'oc-theme' ); ?></th>
				<td>
					<p style="margin:0 0 10px;"><label><input type="checkbox" checked disabled /> <strong><?php esc_html_e( 'Product name', 'oc-theme' ); ?></strong> — <?php esc_html_e( 'always searched, always weighted highest', 'oc-theme' ); ?></label>
						<input type="number" name="w_title" min="1" max="30" value="<?php echo esc_attr( (string) $s['w_title'] ); ?>" style="inline-size:70px;margin-inline-start:10px;" /></p>

					<?php foreach ( $fields as $key => $meta ) : ?>
						<p style="margin:0 0 10px;">
							<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( 1, (int) $s[ $key ] ); ?> /> <?php echo esc_html( $meta[0] ); ?></label>
							<input type="number" name="<?php echo esc_attr( $meta[1] ); ?>" min="1" max="30" value="<?php echo esc_attr( (string) $s[ $meta[1] ] ); ?>" style="inline-size:70px;margin-inline-start:10px;" />
						</p>
					<?php endforeach; ?>

					<p style="margin:0 0 10px;"><?php esc_html_e( 'Category', 'oc-theme' ); ?>
						<input type="number" name="w_cat" min="1" max="30" value="<?php echo esc_attr( (string) $s['w_cat'] ); ?>" style="inline-size:70px;margin-inline-start:10px;" /></p>
					<p style="margin:0 0 10px;"><?php esc_html_e( 'Brand', 'oc-theme' ); ?>
						<input type="number" name="w_brand" min="1" max="30" value="<?php echo esc_attr( (string) $s['w_brand'] ); ?>" style="inline-size:70px;margin-inline-start:10px;" /></p>
					<p style="margin:0;"><?php esc_html_e( 'Synonym', 'oc-theme' ); ?>
						<input type="number" name="w_syn" min="1" max="30" value="<?php echo esc_attr( (string) $s['w_syn'] ); ?>" style="inline-size:70px;margin-inline-start:10px;" /></p>
					<p class="description" style="margin-block-start:10px;"><?php esc_html_e( 'The number beside each field is what a match there is worth. A word found in the product name outranks the same word found in a description.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Also search', 'oc-theme' ); ?></th>
				<td>
					<label style="display:block;margin-block-end:6px;"><input type="checkbox" name="f_posts" value="1" <?php checked( 1, (int) $s['f_posts'] ); ?> /> <?php esc_html_e( 'Blog articles', 'oc-theme' ); ?></label>
					<label style="display:block;"><input type="checkbox" name="f_pages" value="1" <?php checked( 1, (int) $s['f_pages'] ); ?> /> <?php esc_html_e( 'Pages — delivery, returns, about', 'oc-theme' ); ?></label>
					<p class="description"><?php esc_html_e( 'They appear under the products, never mixed into them.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pop_mix"><?php esc_html_e( 'Relevance or popularity', 'oc-theme' ); ?></label></th>
				<td>
					<input type="range" id="pop_mix" name="pop_mix" min="0" max="100" value="<?php echo esc_attr( (string) $s['pop_mix'] ); ?>" style="inline-size:280px;vertical-align:middle;" oninput="document.getElementById('pop_mix_out').textContent=this.value+'%'" />
					<output id="pop_mix_out" style="margin-inline-start:10px;"><?php echo esc_html( (string) $s['pop_mix'] ); ?>%</output>
					<p class="description"><?php esc_html_e( 'At zero the best match always wins. Higher, and what sells well is allowed to climb.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sold out', 'oc-theme' ); ?></th>
				<td>
					<?php
					foreach ( array(
						'sink'   => __( 'Show them last', 'oc-theme' ),
						'hide'   => __( 'Leave them out', 'oc-theme' ),
						'normal' => __( 'Treat them like any other product', 'oc-theme' ),
					) as $key => $label ) :
						?>
						<label style="display:block;margin-block-end:6px;"><input type="radio" name="oos" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $s['oos'] ); ?> /> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'When nothing matches', 'oc-theme' ); ?></th>
				<td>
					<label style="display:block;margin-block-end:6px;"><input type="checkbox" name="kbd" value="1" <?php checked( 1, (int) $s['kbd'] ); ?> /> <?php esc_html_e( 'Read the words again on the other keyboard layout', 'oc-theme' ); ?></label>
					<label style="display:block;"><input type="checkbox" name="typo" value="1" <?php checked( 1, (int) $s['typo'] ); ?> /> <?php esc_html_e( 'Try the closest word the shop actually has', 'oc-theme' ); ?></label>
					<p class="description"><?php esc_html_e( 'Both run only after a search came back empty, so a search that worked never pays for them.', 'oc-theme' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Popular searches and products.
	 *
	 * @param array $s Settings.
	 */
	private function tab_popular( array $s ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Popular searches', 'oc-theme' ); ?></th>
				<td>
					<p style="margin:0 0 8px;">
						<?php esc_html_e( 'Counted over the last', 'oc-theme' ); ?>
						<input type="number" name="pop_days" min="1" max="90" value="<?php echo esc_attr( (string) $s['pop_days'] ); ?>" style="inline-size:70px;" />
						<?php esc_html_e( 'days, showing', 'oc-theme' ); ?>
						<input type="number" name="pop_count" min="0" max="12" value="<?php echo esc_attr( (string) $s['pop_count'] ); ?>" style="inline-size:70px;" />
						<?php esc_html_e( 'of them', 'oc-theme' ); ?>
					</p>
					<p style="margin:0 0 8px;">
						<?php esc_html_e( 'Only after a word has been searched at least', 'oc-theme' ); ?>
						<input type="number" name="pop_min" min="1" max="50" value="<?php echo esc_attr( (string) $s['pop_min'] ); ?>" style="inline-size:70px;" />
						<?php esc_html_e( 'times', 'oc-theme' ); ?>
					</p>
					<p style="margin:0;">
						<label style="display:block;margin-block-end:4px;"><?php esc_html_e( 'Never show these words', 'oc-theme' ); ?></label>
						<input type="text" name="pop_block" value="<?php echo esc_attr( (string) $s['pop_block'] ); ?>" class="large-text" />
					</p>
					<p class="description" style="max-inline-size:700px;">
						<?php esc_html_e( 'A half-typed word is never offered back: "שול" counts towards "שולחן" and the finished word is what appears.', 'oc-theme' ); ?>
					</p>
					<p class="description"><?php esc_html_e( 'Only the word and the day are kept. Nothing identifies who searched.', 'oc-theme' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Popular products', 'oc-theme' ); ?></th>
				<td>
					<?php
					foreach ( array(
						'sales'    => __( 'The best sellers', 'oc-theme' ),
						'searches' => __( 'What people search for most', 'oc-theme' ),
						'manual'   => __( 'The ones I choose', 'oc-theme' ),
						'random'   => __( 'A different handful each time', 'oc-theme' ),
					) as $key => $label ) :
						?>
						<label style="display:block;margin-block-end:6px;"><input type="radio" name="prod_mode" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $s['prod_mode'] ); ?> /> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>

					<p style="margin:10px 0 4px;"><label><?php esc_html_e( 'Product ids, in the order you want them', 'oc-theme' ); ?></label></p>
					<input type="text" name="prod_ids" value="<?php echo esc_attr( (string) $s['prod_ids'] ); ?>" class="large-text" placeholder="128, 64, 91" />

					<p style="margin:10px 0 0;">
						<?php esc_html_e( 'How many to show', 'oc-theme' ); ?>
						<input type="number" name="prod_count" min="2" max="12" value="<?php echo esc_attr( (string) $s['prod_count'] ); ?>" style="inline-size:70px;" />
					</p>
					<p class="description"><?php esc_html_e( 'Nothing sold yet and nothing chosen? The newest products stand in, so the panel is never empty.', 'oc-theme' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Pinned results.
	 *
	 * @param array $s Settings.
	 */
	private function tab_boost( array $s ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pinned"><?php esc_html_e( 'Pinned results', 'oc-theme' ); ?></label></th>
				<td>
					<textarea name="pinned" id="pinned" rows="8" class="large-text code" dir="auto"><?php echo esc_textarea( (string) $s['pinned'] ); ?></textarea>
					<p class="description" style="max-inline-size:700px;">
						<?php esc_html_e( 'One rule per line, written as: the search = product ids. Those products come first, in the order written, and the rest of the ranking follows.', 'oc-theme' ); ?><br />
						<code dir="ltr">ויטמין = 412, 98</code><br />
						<?php esc_html_e( 'The rule also fires while the word is still being typed, so a shopper sees the pin from the third letter on.', 'oc-theme' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'One product at a time', 'oc-theme' ); ?></th>
				<td>
					<p class="description" style="max-inline-size:700px;margin:0;">
						<?php esc_html_e( 'To push a single product up in every search, or to keep it out of search entirely, use the Search tab on the product itself.', 'oc-theme' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * The shop-wide word list.
	 */
	private function tab_synonyms(): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="syn"><?php esc_html_e( 'Words that mean the same thing', 'oc-theme' ); ?></label></th>
				<td>
					<textarea name="synonyms" id="syn" rows="12" class="large-text code" dir="auto"><?php echo esc_textarea( (string) get_option( 'oc_search_synonyms', '' ) ); ?></textarea>
					<p class="description" style="max-inline-size:700px;">
						<?php esc_html_e( 'One group per line, separated by commas. Every word in a line finds everything the others find.', 'oc-theme' ); ?><br />
						<code dir="ltr">ספה, סופה, מושב</code><br />
						<code dir="ltr">מוטי, mutti</code>
					</p>
					<p class="description" style="max-inline-size:700px;">
						<strong><?php esc_html_e( 'For a colour, a size or a category, write the words on the term itself', 'oc-theme' ); ?></strong> —
						<?php esc_html_e( 'on the colour red, write אדומה, אדומים, אדומות, and every red product answers to all of them. That is what makes "שמלה אדומה" find a dress whose title never says red.', 'oc-theme' ); ?>
					</p>
					<p class="description"><?php esc_html_e( 'Saving here rebuilds the index in the background.', 'oc-theme' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/* ------------------------------------------------------------- reports */

	/**
	 * What people searched for.
	 */
	private function reports(): void {
		$s     = Search::settings();
		$days  = max( 1, (int) $s['pop_days'] );
		$found = Search::popular_terms( 30, 25 );
		$empty = Search::popular_terms( 30, 25, true );
		?>
		<h2 style="margin-block-start:0;"><?php esc_html_e( 'Searched and found nothing', 'oc-theme' ); ?></h2>
		<p class="description" style="max-inline-size:760px;">
			<?php esc_html_e( 'The most useful list on this screen. Every line is a shopper who wanted something and left with an empty panel — and almost every one of them is a missing synonym.', 'oc-theme' ); ?>
		</p>

		<?php if ( $empty ) : ?>
			<table class="widefat striped" style="max-inline-size:760px;margin-block-end:32px;">
				<thead><tr>
					<th><?php esc_html_e( 'Searched for', 'oc-theme' ); ?></th>
					<th style="inline-size:120px;"><?php esc_html_e( 'Times', 'oc-theme' ); ?></th>
					<th style="inline-size:220px;"></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $empty as $row ) : ?>
						<tr>
							<td><strong dir="auto"><?php echo esc_html( $row->term ); ?></strong></td>
							<td><?php echo esc_html( (string) (int) $row->searches ); ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=synonyms' ) ); ?>"><?php esc_html_e( 'Add it as a synonym', 'oc-theme' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p style="margin-block-end:32px;"><em><?php esc_html_e( 'Nothing yet — either the search is answering everyone, or it has not been used much.', 'oc-theme' ); ?></em></p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'What people search for', 'oc-theme' ); ?></h2>
		<?php if ( $found ) : ?>
			<table class="widefat striped" style="max-inline-size:760px;">
				<thead><tr>
					<th><?php esc_html_e( 'Searched for', 'oc-theme' ); ?></th>
					<th style="inline-size:120px;"><?php esc_html_e( 'Times', 'oc-theme' ); ?></th>
					<th style="inline-size:120px;"><?php esc_html_e( 'Clicked', 'oc-theme' ); ?></th>
					<th style="inline-size:120px;"><?php esc_html_e( 'Results', 'oc-theme' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $found as $row ) : ?>
						<tr>
							<td><strong dir="auto"><?php echo esc_html( $row->term ); ?></strong></td>
							<td><?php echo esc_html( (string) (int) $row->searches ); ?></td>
							<td><?php echo esc_html( (string) (int) $row->clicks ); ?></td>
							<td><?php echo esc_html( (string) (int) $row->hits ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No searches recorded yet.', 'oc-theme' ); ?></em></p>
		<?php endif; ?>

		<p class="description" style="margin-block-start:18px;">
			<?php
			printf(
				/* translators: %d: number of days. */
				esc_html__( 'The panel shows the last %d days; this screen shows the last 30.', 'oc-theme' ),
				(int) $days
			);
			?>
		</p>
		<?php
	}

	/**
	 * The index tab.
	 */
	private function index_tab(): void {
		$status = Search_Index::status();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading progress, not acting.
		$running = isset( $_GET['building'] );
		$left    = isset( $_GET['left'] ) ? absint( $_GET['left'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$done = $status['total'] > 0 ? $status['total'] - $left : 0;
		?>
		<table class="widefat striped" style="max-inline-size:520px;margin-block-end:20px;">
			<tbody>
				<tr><td><?php esc_html_e( 'Products and pages indexed', 'oc-theme' ); ?></td><td><strong><?php echo esc_html( number_format_i18n( $status['objects'] ) ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Words held', 'oc-theme' ); ?></td><td><strong><?php echo esc_html( number_format_i18n( $status['words'] ) ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Last built', 'oc-theme' ); ?></td><td><strong><?php echo esc_html( $status['built'] ? wp_date( 'j.n.Y H:i', $status['built'] ) : __( 'never', 'oc-theme' ) ); ?></strong></td></tr>
			</tbody>
		</table>

		<?php if ( $running && $left > 0 ) : ?>
			<?php
			// Each batch redirects back here, so a shop of any size finishes
			// without one long request and without needing scripts to work.
			$next = admin_url( 'admin-post.php?action=oc_search_build_step&_wpnonce=' . rawurlencode( wp_create_nonce( 'oc_search_build_step' ) ) );
			?>
			<meta http-equiv="refresh" content="0;url=<?php echo esc_url( $next ); ?>" />
			<p style="font-size:15px;">
				<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span>
				<?php
				printf(
					/* translators: 1: how many are done, 2: how many in total. */
					esc_html__( 'Indexing — %1$s of %2$s', 'oc-theme' ),
					esc_html( number_format_i18n( max( 0, $done ) ) ),
					esc_html( number_format_i18n( $status['total'] ) )
				);
				?>
			</p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=index' ) ); ?>"><?php esc_html_e( 'Stop', 'oc-theme' ); ?></a></p>
		<?php else : ?>
			<?php if ( $running ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'The index is up to date.', 'oc-theme' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'oc_search_build_step' ); ?>
				<input type="hidden" name="action" value="oc_search_build_step" />
				<input type="hidden" name="start" value="1" />
				<?php submit_button( __( 'Rebuild the index', 'oc-theme' ), 'primary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<p class="description" style="max-inline-size:700px;margin-block-start:14px;">
			<?php esc_html_e( 'A rebuild runs in batches, so a shop of any size finishes without a timeout. Search keeps working from the words already held while it runs.', 'oc-theme' ); ?>
		</p>
		<?php
	}

	/**
	 * One batch, then straight back to the screen.
	 */
	public function build_step(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( 'oc_search_build_step' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( ! empty( $_POST['start'] ) ) {
			Search_Index::rebuild_start();
		}

		$state = Search_Index::rebuild_batch( 40 );

		wp_safe_redirect(
			admin_url(
				'admin.php?page=' . self::PAGE . '&tab=index&building=1&left=' . (int) $state['left']
			)
		);
		exit;
	}

	/**
	 * Index one batch.
	 */
	public function ajax_build(): void {
		check_ajax_referer( 'oc_search_build', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( ! empty( $_POST['start'] ) ) {
			Search_Index::rebuild_start();
		}

		$state  = Search_Index::rebuild_batch( 40 );
		$status = Search_Index::status();

		wp_send_json_success(
			array(
				'left'    => $state['left'],
				'objects' => number_format_i18n( $status['objects'] ),
				'words'   => number_format_i18n( $status['words'] ),
			)
		);
	}

	/* ---------------------------------------------------------------- save */

	/**
	 * Save whichever tab was open, leaving the others alone.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( 'oc_search_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$tab  = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'where';
		$s    = Search::settings();
		$post = wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$rebuild = false;

		if ( 'where' === $tab ) {
			foreach ( array( 'f_sku', 'f_desc', 'f_tag', 'f_attr', 'f_posts', 'f_pages' ) as $key ) {
				$was       = (int) $s[ $key ];
				$s[ $key ] = empty( $post[ $key ] ) ? 0 : 1;
				$rebuild   = $rebuild || $was !== $s[ $key ];
			}

			foreach ( array( 'w_title', 'w_sku', 'w_cat', 'w_attr', 'w_tag', 'w_syn', 'w_desc', 'w_brand' ) as $key ) {
				$s[ $key ] = max( 1, min( 30, (int) ( $post[ $key ] ?? $s[ $key ] ) ) );
			}

			$s['pop_mix'] = max( 0, min( 100, (int) ( $post['pop_mix'] ?? 30 ) ) );
			$s['oos']     = in_array( $post['oos'] ?? '', array( 'sink', 'hide', 'normal' ), true ) ? $post['oos'] : 'sink';
			$s['kbd']     = empty( $post['kbd'] ) ? 0 : 1;
			$s['typo']    = empty( $post['typo'] ) ? 0 : 1;
		}

		if ( 'popular' === $tab ) {
			$s['pop_days']   = max( 1, min( 90, (int) ( $post['pop_days'] ?? 7 ) ) );
			$s['pop_count']  = max( 0, min( 12, (int) ( $post['pop_count'] ?? 6 ) ) );
			$s['pop_block']  = sanitize_text_field( (string) ( $post['pop_block'] ?? '' ) );
			$s['pop_min']    = max( 1, min( 50, (int) ( $post['pop_min'] ?? 3 ) ) );
			$s['prod_mode']  = in_array( $post['prod_mode'] ?? '', array( 'sales', 'searches', 'manual', 'random' ), true ) ? $post['prod_mode'] : 'sales';
			$s['prod_ids']   = sanitize_text_field( (string) ( $post['prod_ids'] ?? '' ) );
			$s['prod_count'] = max( 2, min( 12, (int) ( $post['prod_count'] ?? 8 ) ) );
		}

		if ( 'boost' === $tab ) {
			$s['pinned'] = sanitize_textarea_field( (string) ( $post['pinned'] ?? '' ) );
		}

		if ( 'synonyms' === $tab ) {
			$before = (string) get_option( 'oc_search_synonyms', '' );
			$after  = sanitize_textarea_field( (string) ( $post['synonyms'] ?? '' ) );

			update_option( 'oc_search_synonyms', $after );

			$rebuild = $rebuild || $before !== $after;
		}

		update_option( 'oc_search', $s );

		// Anything that changes what a word means changes the whole index.
		if ( $rebuild ) {
			Search_Index::rebuild_start();
			wp_schedule_single_event( time() + 5, 'oc_search_rebuild' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $tab . '&oc_saved=1' ) );
		exit;
	}
}
