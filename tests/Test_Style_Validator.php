<?php

class Test_Style_Validator extends WP_UnitTestCase {

	/**
	 * Layer 2: property-validity
	 */
	public function test_valid_property_paths_pass() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color'      => array( 'background' => '#000000' ),
				'typography' => array( 'fontSize' => '16px' ),
				'spacing'    => array( 'padding' => array( 'top' => '10px' ) ),
				'border'     => array( 'color' => '#333333' ),
				'shadow'     => '0 1px 2px rgba(0,0,0,.1)',
				'dimensions' => array( 'minHeight' => '100px' ),
			),
		) );

		// No property-validity errors expected.
		$property_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'property-validity' === $e['layer']
		);
		$this->assertEmpty( $property_errors );
	}

	public function test_invalid_property_paths_produce_errors() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'opacity' => '0.5' ),
				'fake'  => array( 'whatever' => 'value' ),
			),
		) );

		$this->assertFalse( $result['valid'] );

		$property_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'property-validity' === $e['layer']
		);
		$this->assertCount( 2, $property_errors );
	}

	public function test_nested_border_sub_properties_validated() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'border' => array(
					'top' => array( 'color' => '#333333' ),
				),
			),
		) );

		$property_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'property-validity' === $e['layer']
		);
		$this->assertEmpty( $property_errors );
	}
}
