<?php

declare( strict_types = 1 );

class Test_Anthropic_Client extends WP_UnitTestCase {

	private WP_Theme_Guard_Anthropic_Client $client;

	public function set_up(): void {
		parent::set_up();
		require_once dirname( __DIR__ ) . '/includes/agent/class-anthropic-client.php';
		$this->client = new WP_Theme_Guard_Anthropic_Client( 'test-api-key' );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
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

	public function test_chat_returns_text_response(): void {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			if ( ! str_contains( $url, 'api.anthropic.com' ) ) {
				return $pre;
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'id'          => 'msg_test',
					'type'        => 'message',
					'role'        => 'assistant',
					'content'     => array( array( 'type' => 'text', 'text' => 'Hello!' ) ),
					'stop_reason' => 'end_turn',
				) ),
			);
		}, 10, 3 );

		$result = $this->client->chat( 'Hi' );

		$this->assertArrayHasKey( 'conversation', $result );
		$this->assertArrayHasKey( 'rounds', $result );
		$this->assertCount( 2, $result['conversation'] );
		$this->assertSame( 'user', $result['conversation'][0]['role'] );
		$this->assertSame( 'assistant', $result['conversation'][1]['role'] );
		$this->assertSame( 1, $result['rounds'] );
	}

	public function test_chat_executes_tools_and_loops(): void {
		$call_count = 0;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$call_count ) {
			if ( ! str_contains( $url, 'api.anthropic.com' ) ) {
				return $pre;
			}
			$call_count++;
			if ( 1 === $call_count ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array(
						'id'          => 'msg_1',
						'type'        => 'message',
						'role'        => 'assistant',
						'content'     => array(
							array(
								'type'  => 'tool_use',
								'id'    => 'toolu_1',
								'name'  => 'get_constraints',
								'input' => array( 'include' => array( 'styles' ) ),
							),
						),
						'stop_reason' => 'tool_use',
					) ),
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'id'          => 'msg_2',
					'type'        => 'message',
					'role'        => 'assistant',
					'content'     => array( array( 'type' => 'text', 'text' => 'Done!' ) ),
					'stop_reason' => 'end_turn',
				) ),
			);
		}, 10, 3 );

		$mock_executor = function ( string $ability, array $input ): array {
			return array( 'styles' => array( 'colors' => array( 'palette' => array() ) ) );
		};
		$client = new WP_Theme_Guard_Anthropic_Client( 'test-key', 'test-model', 5, $mock_executor );
		$result = $client->chat( 'Get constraints' );

		$this->assertSame( 2, $call_count );
		$this->assertSame( 2, $result['rounds'] );
		// user + assistant(tool_use) + user(tool_result) + assistant(text) = 4 messages
		$this->assertCount( 4, $result['conversation'] );
		// Third message is the tool result
		$this->assertSame( 'user', $result['conversation'][2]['role'] );
		$this->assertSame( 'tool_result', $result['conversation'][2]['content'][0]['type'] );
	}

	public function test_chat_tracks_last_valid_styles(): void {
		$call_count = 0;
		$validated_styles = array( 'color' => array( 'text' => '#000' ) );

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$call_count, $validated_styles ) {
			if ( ! str_contains( $url, 'api.anthropic.com' ) ) {
				return $pre;
			}
			$call_count++;
			if ( 1 === $call_count ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array(
						'id'          => 'msg_1',
						'type'        => 'message',
						'role'        => 'assistant',
						'content'     => array(
							array(
								'type'  => 'tool_use',
								'id'    => 'toolu_v',
								'name'  => 'validate_styles',
								'input' => array( 'styles' => $validated_styles ),
							),
						),
						'stop_reason' => 'tool_use',
					) ),
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'id'          => 'msg_2',
					'type'        => 'message',
					'role'        => 'assistant',
					'content'     => array( array( 'type' => 'text', 'text' => 'Valid!' ) ),
					'stop_reason' => 'end_turn',
				) ),
			);
		}, 10, 3 );

		$mock_executor = function ( string $ability, array $input ): array {
			return array( 'valid' => true, 'errors' => array(), 'warnings' => array() );
		};
		$client = new WP_Theme_Guard_Anthropic_Client( 'test-key', 'test-model', 5, $mock_executor );
		$result = $client->chat( 'Validate' );

		$this->assertSame( $validated_styles, $result['styles'] );
	}

	public function test_chat_nests_block_styles_under_blocks_key(): void {
		$call_count = 0;

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$call_count ) {
			if ( ! str_contains( $url, 'api.anthropic.com' ) ) {
				return $pre;
			}
			$call_count++;
			if ( 1 === $call_count ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array(
						'id'          => 'msg_1',
						'type'        => 'message',
						'role'        => 'assistant',
						'content'     => array(
							array(
								'type'  => 'tool_use',
								'id'    => 'toolu_b',
								'name'  => 'validate_styles',
								'input' => array(
									'styles'    => array( 'color' => array( 'background' => '#fff' ) ),
									'blockName' => 'core/button',
								),
							),
						),
						'stop_reason' => 'tool_use',
					) ),
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'id'          => 'msg_2',
					'type'        => 'message',
					'role'        => 'assistant',
					'content'     => array( array( 'type' => 'text', 'text' => 'Done!' ) ),
					'stop_reason' => 'end_turn',
				) ),
			);
		}, 10, 3 );

		$mock_executor = function ( string $ability, array $input ): array {
			return array( 'valid' => true, 'errors' => array(), 'warnings' => array() );
		};
		$client = new WP_Theme_Guard_Anthropic_Client( 'test-key', 'test-model', 5, $mock_executor );
		$result = $client->chat( 'Style buttons' );

		$this->assertSame(
			array(
				'blocks' => array(
					'core/button' => array( 'color' => array( 'background' => '#fff' ) ),
				),
			),
			$result['styles']
		);
	}

	public function test_chat_respects_max_rounds(): void {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			if ( ! str_contains( $url, 'api.anthropic.com' ) ) {
				return $pre;
			}
			// Always return tool_use to force looping.
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'id'          => 'msg_loop',
					'type'        => 'message',
					'role'        => 'assistant',
					'content'     => array(
						array(
							'type'  => 'tool_use',
							'id'    => 'toolu_' . wp_rand(),
							'name'  => 'get_constraints',
							'input' => array(),
						),
					),
					'stop_reason' => 'tool_use',
				) ),
			);
		}, 10, 3 );

		$mock_executor = function ( string $ability, array $input ): array {
			return array( 'data' => 'ok' );
		};
		$client = new WP_Theme_Guard_Anthropic_Client( 'test-key', 'test-model', 3, $mock_executor );
		$result = $client->chat( 'Loop forever' );

		$this->assertSame( 3, $result['rounds'] );
	}

	public function test_chat_handles_api_error(): void {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			if ( ! str_contains( $url, 'api.anthropic.com' ) ) {
				return $pre;
			}
			return array(
				'response' => array( 'code' => 401 ),
				'body'     => wp_json_encode( array(
					'type'  => 'error',
					'error' => array( 'type' => 'authentication_error', 'message' => 'Invalid API key' ),
				) ),
			);
		}, 10, 3 );

		$result = $this->client->chat( 'Hi' );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Invalid API key', $result['error'] );
	}

	public function test_chat_sends_correct_request_format(): void {
		$captured_body = null;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured_body ) {
			if ( ! str_contains( $url, 'api.anthropic.com' ) ) {
				return $pre;
			}
			$captured_body = json_decode( $args['body'], true );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'id'          => 'msg_t',
					'type'        => 'message',
					'role'        => 'assistant',
					'content'     => array( array( 'type' => 'text', 'text' => 'Ok' ) ),
					'stop_reason' => 'end_turn',
				) ),
			);
		}, 10, 3 );

		$this->client->chat( 'Hello' );

		$this->assertSame( 'claude-sonnet-4-20250514', $captured_body['model'] );
		$this->assertArrayHasKey( 'system', $captured_body );
		$this->assertCount( 3, $captured_body['tools'] );
		$this->assertCount( 1, $captured_body['messages'] );
		$this->assertSame( 'user', $captured_body['messages'][0]['role'] );
		$this->assertSame( 'Hello', $captured_body['messages'][0]['content'] );
	}

	public function test_chat_continues_existing_conversation(): void {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			if ( ! str_contains( $url, 'api.anthropic.com' ) ) {
				return $pre;
			}
			$body = json_decode( $args['body'], true );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'id'          => 'msg_c',
					'type'        => 'message',
					'role'        => 'assistant',
					'content'     => array( array( 'type' => 'text', 'text' => 'Response ' . count( $body['messages'] ) ) ),
					'stop_reason' => 'end_turn',
				) ),
			);
		}, 10, 3 );

		$existing = array(
			array( 'role' => 'user', 'content' => 'First message' ),
			array( 'role' => 'assistant', 'content' => array( array( 'type' => 'text', 'text' => 'First response' ) ) ),
		);

		$result = $this->client->chat( 'Second message', $existing );

		// existing 2 + new user message = 3, then + assistant = 4
		$this->assertCount( 4, $result['conversation'] );
		$this->assertSame( 'Second message', $result['conversation'][2]['content'] );
	}
}
