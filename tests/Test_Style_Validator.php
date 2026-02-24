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

	/**
	 * Layer 3: value-sanitization
	 */
	public function test_safe_css_values_pass() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color'      => array( 'background' => '#ff0000' ),
				'typography' => array( 'fontSize' => 'clamp(1rem, 2vw, 2rem)' ),
				'spacing'    => array( 'padding' => array( 'top' => '10px' ) ),
			),
		) );

		$sanitization_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'value-sanitization' === $e['layer']
		);
		$this->assertEmpty( $sanitization_errors );
	}

	public function test_unsafe_css_values_produce_errors() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array(
					'background' => 'expression(alert(1))',
					'text'       => 'var(--wp--preset--color--black)',
				),
			),
		) );

		$sanitization_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'value-sanitization' === $e['layer']
		);
		$this->assertCount( 1, $sanitization_errors );

		$error = array_values( $sanitization_errors )[0];
		$this->assertSame( 'color.background', $error['property'] );
	}

	public function test_empty_values_produce_errors() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => '' ),
			),
		) );

		$sanitization_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'value-sanitization' === $e['layer']
		);
		$this->assertCount( 1, $sanitization_errors );
	}

	public function test_preset_references_always_pass_sanitization() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color'      => array(
					'background' => 'var(--wp--preset--color--vivid-red)',
					'text'       => 'var(--wp--preset--color--white)',
				),
				'typography' => array(
					'fontSize' => 'var(--wp--preset--font-size--medium)',
				),
			),
		) );

		$sanitization_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'value-sanitization' === $e['layer']
		);
		$this->assertEmpty( $sanitization_errors );
	}

	/**
	 * Layer 4: theme-settings
	 */
	public function test_disabled_setting_produces_error() {
		WP_Theme_Guard_Style_Validator::set_test_settings( array(
			'color' => array(
				'text'   => false,
				'custom' => true,
			),
		) );

		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'text' => '#333333' ),
			),
		) );

		WP_Theme_Guard_Style_Validator::set_test_settings( null );

		$settings_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'theme-settings' === $e['layer']
		);
		$this->assertCount( 1, $settings_errors );
	}

	public function test_enabled_setting_passes() {
		WP_Theme_Guard_Style_Validator::set_test_settings( array(
			'color' => array(
				'text'   => true,
				'custom' => true,
			),
		) );

		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'text' => '#333333' ),
			),
		) );

		WP_Theme_Guard_Style_Validator::set_test_settings( null );

		$settings_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'theme-settings' === $e['layer']
		);
		$this->assertEmpty( $settings_errors );
	}

	public function test_custom_values_blocked_but_presets_allowed() {
		WP_Theme_Guard_Style_Validator::set_test_settings( array(
			'color' => array(
				'background' => true,
				'custom'     => false,
			),
		) );

		$result_custom = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => '#ff0000' ),
			),
		) );

		$result_preset = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => 'var(--wp--preset--color--vivid-red)' ),
			),
		) );

		WP_Theme_Guard_Style_Validator::set_test_settings( null );

		// Custom value should error.
		$custom_errors = array_filter(
			$result_custom['errors'],
			fn( $e ) => 'theme-settings' === $e['layer']
		);
		$this->assertCount( 1, $custom_errors );

		// Preset reference should pass.
		$preset_errors = array_filter(
			$result_preset['errors'],
			fn( $e ) => 'theme-settings' === $e['layer']
		);
		$this->assertEmpty( $preset_errors );
	}

	public function test_custom_font_size_blocked() {
		WP_Theme_Guard_Style_Validator::set_test_settings( array(
			'typography' => array(
				'customFontSize' => false,
			),
		) );

		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'typography' => array( 'fontSize' => '16px' ),
			),
		) );

		WP_Theme_Guard_Style_Validator::set_test_settings( null );

		$settings_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'theme-settings' === $e['layer']
		);
		$this->assertCount( 1, $settings_errors );
	}

	/**
	 * Layer 5: block-supports
	 */
	public function test_supported_block_style_passes() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles'    => array(
				'color' => array( 'text' => 'var(--wp--preset--color--black)' ),
			),
			'blockName' => 'core/paragraph',
		) );

		$support_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'block-supports' === $e['layer']
		);
		$this->assertEmpty( $support_errors );
	}

	public function test_unsupported_block_style_produces_error() {
		// core/image has color.text explicitly set to false.
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles'    => array(
				'color' => array( 'text' => '#333333' ),
			),
			'blockName' => 'core/image',
		) );

		$support_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'block-supports' === $e['layer']
		);
		$this->assertCount( 1, $support_errors );
	}

	public function test_unregistered_block_produces_error() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles'    => array(
				'color' => array( 'background' => '#ff0000' ),
			),
			'blockName' => 'fake/nonexistent',
		) );

		$support_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'block-supports' === $e['layer']
		);
		$this->assertCount( 1, $support_errors );
		$this->assertStringContainsString( 'not registered', array_values( $support_errors )[0]['message'] );
	}

	public function test_block_shadow_support() {
		// core/group supports shadow.
		$result_group = WP_Theme_Guard_Style_Validator::execute( array(
			'styles'    => array(
				'shadow' => '0 1px 2px #000',
			),
			'blockName' => 'core/group',
		) );

		$group_errors = array_filter(
			$result_group['errors'],
			fn( $e ) => 'block-supports' === $e['layer']
		);
		$this->assertEmpty( $group_errors );
	}

	/**
	 * Layer 6: preset-suggestion
	 */
	public function test_close_color_suggests_preset() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => '#cc2e2e' ),
			),
		) );

		$suggestions = array_filter(
			$result['warnings'],
			fn( $w ) => 'preset-suggestion' === $w['layer']
		);
		$this->assertNotEmpty( $suggestions );
		$suggestion = array_values( $suggestions )[0];
		$this->assertStringContainsString( 'var(--wp--preset--color--', $suggestion['suggestion'] );
	}

	public function test_preset_reference_gets_no_suggestion() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => 'var(--wp--preset--color--vivid-red)' ),
			),
		) );

		$suggestions = array_filter(
			$result['warnings'],
			fn( $w ) => 'preset-suggestion' === $w['layer']
		);
		$this->assertEmpty( $suggestions );
	}

	/**
	 * Layer 0: schema-structure
	 */
	public function test_blocks_key_in_fragment_produces_schema_error() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'blocks' => array(
					'core/group' => array(
						'css' => '& .inner { display: grid; }',
					),
				),
			),
		) );

		$this->assertFalse( $result['valid'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( 'schema-structure', $result['errors'][0]['layer'] );
		$this->assertStringContainsString( 'structural key', $result['errors'][0]['message'] );
	}

	public function test_elements_key_in_fragment_produces_schema_error() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'elements' => array(
					'button' => array(
						'color' => array( 'text' => '#fff' ),
					),
				),
			),
		) );

		$this->assertFalse( $result['valid'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( 'schema-structure', $result['errors'][0]['layer'] );
		$this->assertStringContainsString( 'structural key', $result['errors'][0]['message'] );
	}

	public function test_both_structural_keys_produce_two_errors() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'blocks'   => array( 'core/group' => array( 'css' => '& { }' ) ),
				'elements' => array( 'button' => array( 'color' => array( 'text' => '#fff' ) ) ),
			),
		) );

		$this->assertFalse( $result['valid'] );
		$this->assertCount( 2, $result['errors'] );
	}

	public function test_flat_css_property_passes_without_schema_error() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'css' => '.wp-site-blocks { scroll-margin-top: 100px; }',
			),
		) );

		$schema_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'schema-structure' === $e['layer']
		);
		$this->assertEmpty( $schema_errors );
		$this->assertTrue( $result['valid'] );
	}

	public function test_matching_font_size_suggests_preset() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'typography' => array( 'fontSize' => '13px' ),
			),
		) );

		$suggestions = array_filter(
			$result['warnings'],
			fn( $w ) => 'preset-suggestion' === $w['layer']
		);
		$this->assertNotEmpty( $suggestions );
		$this->assertStringContainsString( 'var(--wp--preset--font-size--', array_values( $suggestions )[0]['suggestion'] );
	}
}
