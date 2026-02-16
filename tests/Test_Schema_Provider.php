<?php

class Test_Schema_Provider extends WP_UnitTestCase {

	public function test_returns_all_constraint_types_by_default() {
		$result = WP_Theme_Guard_Schema_Provider::execute( array() );

		$this->assertArrayHasKey( 'styles', $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertArrayHasKey( 'layout', $result );
	}

	public function test_filters_by_include_parameter() {
		$result = WP_Theme_Guard_Schema_Provider::execute( array(
			'include' => array( 'styles' ),
		) );

		$this->assertArrayHasKey( 'styles', $result );
		$this->assertArrayNotHasKey( 'blocks', $result );
		$this->assertArrayNotHasKey( 'layout', $result );
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
