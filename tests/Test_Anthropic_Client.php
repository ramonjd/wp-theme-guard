<?php

declare( strict_types = 1 );

class Test_Anthropic_Client extends WP_UnitTestCase {

	private WP_Theme_Guard_Anthropic_Client $client;

	public function set_up(): void {
		parent::set_up();
		require_once dirname( __DIR__ ) . '/includes/agent/class-anthropic-client.php';
		$this->client = new WP_Theme_Guard_Anthropic_Client( 'test-api-key' );
	}

	public function test_get_tool_definitions_returns_three_tools(): void {
		$tools = $this->client->get_tool_definitions();
		$this->assertCount( 3, $tools );
		$names = array_column( $tools, 'name' );
		$this->assertContains( 'get_constraints', $names );
		$this->assertContains( 'validate_styles', $names );
		$this->assertContains( 'validate_blocks', $names );
	}

	public function test_each_tool_has_input_schema(): void {
		$tools = $this->client->get_tool_definitions();
		foreach ( $tools as $tool ) {
			$this->assertArrayHasKey( 'input_schema', $tool, "Tool {$tool['name']} missing input_schema" );
			$this->assertSame( 'object', $tool['input_schema']['type'] );
		}
	}

	public function test_get_system_prompt_is_non_empty_string(): void {
		$prompt = $this->client->get_system_prompt();
		$this->assertIsString( $prompt );
		$this->assertNotEmpty( $prompt );
		$this->assertStringContainsString( 'get_constraints', $prompt );
	}
}
