<?php
/**
 * Taking products out of a category, from the products list.
 *
 * Bulk Edit has always been able to put many products into a category at
 * once; taking them out again meant opening each product. The categories
 * box gains a switch: tick it, and the categories you tick are removed
 * from the selected products instead of added to them. The box is outlined
 * in red while the switch is on, because the same clicks now mean the
 * opposite thing.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * "Remove from category" in Bulk Edit.
 */
final class Bulk_Category {

	const FIELD  = 'oc_rm_terms';
	const NOTICE = 'oc_rm_terms_done';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Before edit.php reads the request, so the ticked categories are
		// removed and never reach the code that would have added them.
		add_action( 'load-edit.php', array( $this, 'maybe_remove' ) );
		add_action( 'bulk_edit_custom_box', array( $this, 'box' ), 20, 2 );
		add_action( 'admin_footer-edit.php', array( $this, 'script' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * The switch. Rendered wherever the hook fires and moved under the
	 * categories list by the script, so it sits with what it changes.
	 *
	 * @param string $column    Column key.
	 * @param string $post_type Post type.
	 */
	public function box( $column, $post_type ): void {
		if ( 'oc_tile' !== $column || 'product' !== $post_type ) {
			return;
		}
		?>
		<div class="oc-rmcat" hidden>
			<label class="oc-rmcat__sw">
				<input type="checkbox" name="<?php echo esc_attr( self::FIELD ); ?>" value="1" />
				<span><?php esc_html_e( 'Remove from category', 'oc-theme' ); ?></span>
			</label>
			<span class="oc-rmcat__note"><?php esc_html_e( 'The categories you tick above will be taken off the selected products instead of added to them.', 'oc-theme' ); ?></span>
		</div>
		<?php
	}

	/**
	 * Do the removal, and take the categories out of the request so the
	 * bulk edit that runs next does not add back what we just removed.
	 */
	public function maybe_remove(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is checked below, before anything is written.
		if ( empty( $_REQUEST['bulk_edit'] ) || empty( $_REQUEST[ self::FIELD ] ) ) {
			return;
		}

		if ( 'product' !== ( isset( $_REQUEST['post_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_type'] ) ) : '' ) ) {
			return;
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'bulk-posts' ) ) {
			return;
		}

		$ids   = array_filter( array_map( 'absint', (array) ( $_REQUEST['post'] ?? array() ) ) );
		$terms = array_filter( array_map( 'absint', (array) ( $_REQUEST['tax_input']['product_cat'] ?? array() ) ) );

		// Whatever happens next, the bulk edit must not see these again.
		unset( $_REQUEST['tax_input']['product_cat'], $_POST['tax_input']['product_cat'], $_GET['tax_input']['product_cat'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $ids || ! $terms ) {
			return;
		}

		$touched = 0;

		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			$before = wp_get_object_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );

			if ( is_wp_error( $before ) || ! array_intersect( $terms, array_map( 'absint', $before ) ) ) {
				continue;
			}

			wp_remove_object_terms( $id, $terms, 'product_cat' );

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}

			++$touched;
		}

		set_transient( self::NOTICE . '_' . get_current_user_id(), array( $touched, count( $terms ) ), 60 );
	}

	/**
	 * What the removal did, once.
	 */
	public function notice(): void {
		$key  = self::NOTICE . '_' . get_current_user_id();
		$done = get_transient( $key );

		if ( ! is_array( $done ) ) {
			return;
		}

		delete_transient( $key );

		echo '<div class="notice notice-success is-dismissible"><p>';

		if ( $done[0] > 0 ) {
			printf(
				/* translators: 1: how many categories, 2: how many products. */
				esc_html__( '%1$d categories removed from %2$d products.', 'oc-theme' ),
				(int) $done[1],
				(int) $done[0]
			);
		} else {
			esc_html_e( 'Nothing to remove: none of the selected products was in those categories.', 'oc-theme' );
		}

		echo '</p></div>';
	}

	/**
	 * Move the switch under the categories list, and make it obvious that
	 * ticking a category now takes it off.
	 */
	public function script(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		?>
		<style>
			#bulk-edit .oc-rmcat { margin: 8px 0 0; padding: 8px 10px; border: 1px solid #dcdcde; border-radius: 4px; background: #fff; }
			#bulk-edit .oc-rmcat__sw { display: flex; align-items: center; gap: 6px; font-weight: 600; }
			#bulk-edit .oc-rmcat__note { display: none; margin-block-start: 4px; color: #b32d2e; font-size: 12px; line-height: 1.5; }
			#bulk-edit .oc-rmcat.is-on .oc-rmcat__sw { color: #b32d2e; }
			#bulk-edit .oc-rmcat.is-on .oc-rmcat__note { display: block; }
			#bulk-edit .cat-checklist.is-removing { outline: 2px solid #b32d2e; outline-offset: 2px; border-radius: 3px; background: #fcf0f1; }
			#bulk-edit .inline-edit-categories-label.is-removing { color: #b32d2e; font-weight: 700; }
		</style>
		<script>
		( function () {
			var moved = false;
			var wired = false;

			function box() {
				var row = document.getElementById( 'bulk-edit' );

				return row ? row.querySelector( '.oc-rmcat' ) : null;
			}

			// The list of category checkboxes inside the bulk row — the very
			// boxes the removal reads, so the switch cannot drift away from
			// what it reverses.
			function list() {
				var row = document.getElementById( 'bulk-edit' );

				if ( ! row ) {
					return null;
				}

				var one = row.querySelector( 'input[name="tax_input[product_cat][]"][type="checkbox"]' );
				var ul = one ? one.closest( 'ul' ) : null;

				return ul ? ul : row.querySelector( 'ul.product_cat-checklist, ul.cat-checklist' );
			}

			function wire( b, ul ) {
				if ( wired ) {
					return;
				}

				var cb = b.querySelector( 'input[type="checkbox"]' );

				if ( ! cb ) {
					return;
				}

				wired = true;

				cb.addEventListener( 'change', function () {
					b.classList.toggle( 'is-on', cb.checked );

					var target = ul || list();

					if ( target ) {
						target.classList.toggle( 'is-removing', cb.checked );

						var before = target.previousElementSibling;

						while ( before ) {
							if ( before.classList && before.classList.contains( 'inline-edit-categories-label' ) ) {
								before.classList.toggle( 'is-removing', cb.checked );
								break;
							}

							before = before.previousElementSibling;
						}
					}
				} );
			}

			// Shown straight away so it is never lost, and moved as soon as
			// the categories list is in the document — which, when Bulk Edit
			// has not been opened yet, may be later than this first run.
			function place() {
				var b = box();

				if ( ! b ) {
					return false;
				}

				b.hidden = false;

				if ( moved ) {
					return true;
				}

				var ul = list();

				if ( ! ul ) {
					wire( b, null );

					return false;
				}

				ul.parentNode.insertBefore( b, ul.nextSibling );
				moved = true;
				wire( b, ul );

				return true;
			}

			function chase() {
				if ( place() ) {
					return;
				}

				[ 50, 150, 400, 900, 1800 ].forEach( function ( ms ) {
					setTimeout( place, ms );
				} );
			}

			chase();
			document.addEventListener( 'DOMContentLoaded', chase );

			// Opening Bulk Edit moves the row into the table: look again.
			document.addEventListener( 'click', function ( e ) {
				var t = e.target;

				if ( t && ( 'doaction' === t.id || 'doaction2' === t.id || ( t.closest && t.closest( '#bulk-edit' ) ) ) ) {
					chase();
				}
			}, true );

			// And if anything rebuilds the rows, the switch follows.
			if ( window.MutationObserver ) {
				var host = document.getElementById( 'the-list' ) || document.body;

				new MutationObserver( function () {
					if ( ! moved ) {
						place();
					}
				} ).observe( host, { childList: true, subtree: true } );
			}
		} )();
		</script>
		<?php
	}
}
