<?php

class Test_Block_Validator extends WP_UnitTestCase {

	public function test_valid_block_markup() {
		$result = WP_Theme_Guard_Block_Validator::execute( array(
			'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
		) );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 1, $result['block_count'] );
		$this->assertEmpty( $result['errors'] );
	}

	public function test_unregistered_block() {
		$result = WP_Theme_Guard_Block_Validator::execute( array(
			'content' => '<!-- wp:nonexistent/fake-block --><div>content</div><!-- /wp:nonexistent/fake-block -->',
		) );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 'unregistered_block', $result['errors'][0]['type'] );
	}

	public function test_invalid_nesting() {
		$result = WP_Theme_Guard_Block_Validator::execute( array(
			'content' => '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></div><!-- /wp:column -->',
		) );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 'invalid_parent', $result['errors'][0]['type'] );
	}

	public function test_valid_nested_blocks() {
		$content = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->';

		$result = WP_Theme_Guard_Block_Validator::execute( array(
			'content' => $content,
		) );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 3, $result['block_count'] );
	}
}
