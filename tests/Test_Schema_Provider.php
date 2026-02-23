<?php

class Test_Schema_Provider extends WP_UnitTestCase {

	public function test_returns_all_constraint_types_by_default() {
		$result = WP_Theme_Guard_Schema_Provider::execute( array() );

		$this->assertArrayHasKey( 'structure', $result );
		$this->assertArrayHasKey( 'styles', $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertArrayHasKey( 'layout', $result );
	}

	public function test_filters_by_include_parameter() {
		$result = WP_Theme_Guard_Schema_Provider::execute( array(
			'include' => array( 'styles' ),
		) );

		$this->assertArrayHasKey( 'styles', $result );
		$this->assertArrayNotHasKey( 'structure', $result );
		$this->assertArrayNotHasKey( 'blocks', $result );
		$this->assertArrayNotHasKey( 'layout', $result );
	}

	public function test_structure_guide_contains_targeting_hierarchy() {
		$result = WP_Theme_Guard_Schema_Provider::execute( array(
			'include' => array( 'structure' ),
		) );

		$this->assertArrayHasKey( 'structure', $result );
		$structure = $result['structure'];
		$this->assertArrayHasKey( 'targeting', $structure );
		$this->assertArrayHasKey( 'global', $structure['targeting'] );
		$this->assertArrayHasKey( 'block', $structure['targeting'] );
		$this->assertArrayHasKey( 'element', $structure['targeting'] );
		$this->assertArrayHasKey( 'block_element', $structure['targeting'] );
		$this->assertArrayHasKey( 'style_properties', $structure );
		$this->assertArrayHasKey( 'values', $structure );
	}

	public function test_structure_guide_lists_valid_elements() {
		$result   = WP_Theme_Guard_Schema_Provider::execute( array(
			'include' => array( 'structure' ),
		) );
		$elements = $result['structure']['targeting']['valid_elements'];

		$this->assertContains( 'button', $elements );
		$this->assertContains( 'heading', $elements );
		$this->assertContains( 'link', $elements );
		$this->assertContains( 'h1', $elements );
	}

	public function test_structure_guide_has_complete_example() {
		$result  = WP_Theme_Guard_Schema_Provider::execute( array(
			'include' => array( 'structure' ),
		) );
		$example = $result['structure']['complete_example']['styles'];

		$this->assertArrayHasKey( 'color', $example );
		$this->assertArrayHasKey( 'blocks', $example );
		$this->assertArrayHasKey( 'elements', $example );
		$this->assertArrayHasKey( 'core/heading', $example['blocks'] );
		$this->assertArrayHasKey( 'core/button', $example['blocks'] );
		$this->assertArrayHasKey( 'button', $example['elements'] );
	}

	public function test_structure_guide_includes_block_name_aliases() {
		$result   = WP_Theme_Guard_Schema_Provider::execute( array(
			'include' => array( 'structure' ),
		) );
		$aliases  = $result['structure']['common_aliases']['mappings'];

		$this->assertSame( 'core/button', $aliases['button'] );
		$this->assertSame( 'core/group', $aliases['container'] );
		$this->assertSame( 'core/heading', $aliases['heading'] );
		$this->assertSame( 'core/paragraph', $aliases['text'] );
	}

	public function test_styles_contains_color_palette() {
		$result = WP_Theme_Guard_Schema_Provider::execute( array(
			'include' => array( 'styles' ),
		) );

		$this->assertArrayHasKey( 'colors', $result['styles'] );
		$this->assertArrayHasKey( 'palette', $result['styles']['colors'] );
		$this->assertArrayHasKey( 'custom_allowed', $result['styles']['colors'] );
	}

	public function test_blocks_contains_registered_list() {
		$result = WP_Theme_Guard_Schema_Provider::execute( array(
			'include' => array( 'blocks' ),
		) );

		$this->assertArrayHasKey( 'registered', $result['blocks'] );
		$this->assertContains( 'core/paragraph', $result['blocks']['registered'] );
	}

	public function test_layout_contains_content_size() {
		$result = WP_Theme_Guard_Schema_Provider::execute( array(
			'include' => array( 'layout' ),
		) );

		$this->assertArrayHasKey( 'content_size', $result['layout'] );
		$this->assertArrayHasKey( 'wide_size', $result['layout'] );
	}
}
