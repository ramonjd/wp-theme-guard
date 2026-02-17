<?php

class Test_Plugin_Integration extends WP_UnitTestCase {

	public function test_classes_exist() {
		$this->assertTrue( class_exists( 'WP_Theme_Guard_Style_Validator' ) );
		$this->assertTrue( class_exists( 'WP_Theme_Guard_Block_Validator' ) );
		$this->assertTrue( class_exists( 'WP_Theme_Guard_Schema_Provider' ) );
		$this->assertTrue( class_exists( 'WP_Theme_Guard_Abilities' ) );
	}

	public function test_validate_styles_end_to_end() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'text' => 'var(--wp--preset--color--black)' ),
			),
		) );

		$this->assertTrue( $result['valid'] );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertEmpty( $result['errors'] );
	}

	public function test_validate_styles_error_includes_layer() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'fake' => array( 'invalid' => 'value' ),
			),
		) );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );

		foreach ( $result['errors'] as $error ) {
			$this->assertArrayHasKey( 'layer', $error );
		}
	}

	public function test_validate_blocks_end_to_end() {
		$result = WP_Theme_Guard_Block_Validator::execute( array(
			'content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
		) );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 1, $result['block_count'] );
	}

	public function test_get_constraints_end_to_end() {
		$result = WP_Theme_Guard_Schema_Provider::execute( array() );

		$this->assertArrayHasKey( 'styles', $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertArrayHasKey( 'layout', $result );
		$this->assertNotEmpty( $result['blocks']['registered'] );
	}
}
