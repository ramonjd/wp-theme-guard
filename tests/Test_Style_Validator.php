<?php

class Test_Style_Validator extends WP_UnitTestCase {

	public function test_valid_palette_color() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => 'var(--wp--preset--color--black)' ),
			),
		) );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	public function test_custom_color_with_custom_allowed() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => '#abcdef' ),
			),
		) );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
		$this->assertNotEmpty( $result['warnings'] );
		$this->assertSame( 'color.background', $result['warnings'][0]['property'] );
	}

	public function test_preset_reference_is_valid() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array(
					'background' => 'var(--wp--preset--color--vivid-red)',
					'text'       => 'var(--wp--preset--color--white)',
				),
			),
		) );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}
}
