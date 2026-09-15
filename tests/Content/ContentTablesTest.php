<?php
/**
 * Pasted tables get a scroll box; the shop's own tables do not.
 */

declare( strict_types = 1 );

namespace OC\Theme\Tests\Content;

use OC\Theme\Content_Tables;
use PHPUnit\Framework\TestCase;

/**
 * Content_Tables::wrap().
 */
final class ContentTablesTest extends TestCase {

	public function test_a_bare_table_is_wrapped(): void {
		$in = '<p>Spec</p><table style="width: 50%"><tbody><tr><td>a</td></tr></tbody></table><p>after</p>';

		$this->assertSame(
			'<p>Spec</p><div class="oc-tbl"><table style="width: 50%"><tbody><tr><td>a</td></tr></tbody></table></div><p>after</p>',
			Content_Tables::wrap( $in )
		);
	}

	public function test_two_tables_each_get_their_own_box(): void {
		$out = Content_Tables::wrap( "<table><tr><td>1</td></tr></table>\n<table>\n<tr><td>2</td></tr></table>" );

		$this->assertSame( 2, substr_count( $out, '<div class="oc-tbl">' ) );
		$this->assertSame( 2, substr_count( $out, '</table></div>' ) );
	}

	public function test_wrapped_and_own_tables_are_left_alone(): void {
		$wrapped = '<div class="oc-tbl"><table><tr><td>a</td></tr></table></div>';
		$woo     = '<table class="woocommerce-product-attributes"><tr><td>a</td></tr></table>';
		$vars    = '<table class="variations"><tr><td>a</td></tr></table>';
		$block   = '<table class="ocb-x"><tr><td>a</td></tr></table>';

		$this->assertSame( $wrapped, Content_Tables::wrap( $wrapped ) );
		$this->assertSame( $woo, Content_Tables::wrap( $woo ) );
		$this->assertSame( $vars, Content_Tables::wrap( $vars ) );
		$this->assertSame( $block, Content_Tables::wrap( $block ) );
	}

	public function test_a_bold_first_row_is_marked_as_a_heading(): void {
		$bold  = '<table><tbody><tr><td><p><strong>Type</strong></p><hr></td><td><strong>Size</strong></td></tr><tr><td>a</td><td>b</td></tr></tbody></table>';
		$mixed = '<table><tbody><tr><td><strong>Type</strong></td><td>Size</td></tr></tbody></table>';
		$thead = '<table><thead><tr><th>Type</th></tr></thead><tbody><tr><td><strong>x</strong></td></tr></tbody></table>';

		$this->assertStringStartsWith( '<div class="oc-tbl oc-tbl--h1">', Content_Tables::wrap( $bold ) );
		$this->assertStringStartsWith( '<div class="oc-tbl">', Content_Tables::wrap( $mixed ) );
		$this->assertStringStartsWith( '<div class="oc-tbl">', Content_Tables::wrap( $thead ) );
	}

	public function test_no_table_no_change(): void {
		$this->assertSame( '<p>plain</p>', Content_Tables::wrap( '<p>plain</p>' ) );
		$this->assertNull( Content_Tables::wrap( null ) );
	}
}
