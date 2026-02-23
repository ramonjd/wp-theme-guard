# Test Agent Admin Page Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a standalone WordPress admin page that lets users chat with an AI agent to generate validated theme styles, then save them as global styles.

**Architecture:** Server-side agentic loop. Browser sends prompts to a REST endpoint, PHP calls Anthropic Messages API with tool definitions mapped to wp-theme-guard abilities, executes tools via `wp_execute_ability()`, loops until done or hits 5-round cap, returns full conversation trace. Separate endpoint writes validated styles to `wp_global_styles` CPT. Agent code is isolated in `includes/agent/` and only loads when `WP_THEME_GUARD_API_KEY` constant is defined — zero overhead on production sites.

**Tech Stack:** PHP 8.1, WordPress REST API, Anthropic Messages API (`claude-sonnet-4-20250514`), vanilla JS (no build step)

**Design doc:** `docs/plans/2026-02-23-test-agent-design.md`

---

### Task 1: Anthropic Client — tool definitions, system prompt, constructor

**Files:**
- Create: `includes/agent/class-anthropic-client.php`
- Create: `tests/Test_Anthropic_Client.php`

**Step 1: Write the failing tests**

Create `tests/Test_Anthropic_Client.php`:

```php
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
```

**Step 2: Run tests to verify they fail**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Anthropic_Client`

Expected: FAIL — class `WP_Theme_Guard_Anthropic_Client` not found.

**Step 3: Write minimal implementation**

Create directory and file `includes/agent/class-anthropic-client.php`:

```php
<?php

declare( strict_types = 1 );

/**
 * Anthropic API client for the test agent.
 *
 * Sends messages to the Anthropic Messages API with wp-theme-guard
 * abilities exposed as tools, then executes tool calls server-side.
 */
class WP_Theme_Guard_Anthropic_Client {

	/**
	 * Maps tool names to wp-theme-guard ability names.
	 */
	private const TOOL_ABILITY_MAP = array(
		'get_constraints' => 'wp-theme-guard/get-constraints',
		'validate_styles' => 'wp-theme-guard/validate-styles',
		'validate_blocks' => 'wp-theme-guard/validate-blocks',
	);

	private string $api_key;
	private string $model;
	private int $max_rounds;

	/** @var callable */
	private $tool_executor;

	public function __construct(
		string $api_key,
		string $model = 'claude-sonnet-4-20250514',
		int $max_rounds = 5,
		?callable $tool_executor = null
	) {
		$this->api_key       = $api_key;
		$this->model         = $model;
		$this->max_rounds    = $max_rounds;
		$this->tool_executor = $tool_executor ?? static function ( string $ability, array $input ) {
			return wp_execute_ability( $ability, $input );
		};
	}

	/**
	 * Tool definitions in Anthropic API format.
	 */
	public function get_tool_definitions(): array {
		return array(
			array(
				'name'         => 'get_constraints',
				'description'  => "Get the site's design rules: color palette, font sizes, spacing presets, block rules, and layout settings.",
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'include' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'styles', 'blocks', 'layout' ),
							),
							'description' => 'Which constraint types to return. Defaults to all.',
						),
					),
				),
			),
			array(
				'name'         => 'validate_styles',
				'description'  => "Validate a styles object against the site's theme.json design system. Returns errors and warnings.",
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'styles'    => array(
							'type'        => 'object',
							'description' => 'Style declarations following theme.json structure.',
						),
						'context'   => array(
							'type'        => 'string',
							'enum'        => array( 'block', 'element', 'global' ),
							'description' => 'Validation context. Defaults to block.',
						),
						'blockName' => array(
							'type'        => 'string',
							'description' => 'Block name (e.g. "core/paragraph") for block supports checking.',
						),
					),
					'required'   => array( 'styles' ),
				),
			),
			array(
				'name'         => 'validate_blocks',
				'description'  => 'Validate block markup against the block registry and nesting rules.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'content'   => array(
							'type'        => 'string',
							'description' => 'Block markup to validate.',
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Post type for allowed blocks check.',
						),
					),
					'required'   => array( 'content' ),
				),
			),
		);
	}

	/**
	 * System prompt for the style assistant.
	 */
	public function get_system_prompt(): string {
		return <<<'PROMPT'
You are a WordPress theme style assistant. You generate and modify CSS styles that are compatible with the site's design system.

## Workflow
1. ALWAYS call get_constraints first to learn the site's color palette, font sizes, spacing presets, and layout rules.
2. Generate styles using the theme.json structure shown below.
3. Call validate_styles to check your work.
4. If errors are returned, fix them and re-validate.
5. If warnings suggest preset alternatives, prefer using presets for design system consistency.

## Style Object Structure
{
  "color": { "text": "...", "background": "...", "gradient": "..." },
  "typography": { "fontSize": "...", "fontFamily": "...", "fontWeight": "...", "lineHeight": "..." },
  "spacing": { "padding": { "top": "...", "right": "...", "bottom": "...", "left": "..." }, "margin": { "top": "...", "bottom": "..." }, "blockGap": "..." },
  "border": { "color": "...", "width": "...", "style": "...", "radius": "..." }
}

## Values
- Preset references (preferred): var(--wp--preset--color--black), var(--wp--preset--font-size--large), var(--wp--preset--spacing--50)
- Custom CSS values (when theme permits): #ff6600, 18px, 2rem, bold

After producing valid styles, present the final styles JSON object clearly so the user can review before saving.
PROMPT;
	}
}
```

**Step 4: Run tests to verify they pass**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Anthropic_Client`

Expected: 3 tests, 3 assertions, OK.

**Step 5: Commit**

```bash
git add includes/agent/class-anthropic-client.php tests/Test_Anthropic_Client.php
git commit -m "feat(agent): add Anthropic client with tool definitions and system prompt"
```

---

### Task 2: Anthropic Client — chat method and agentic loop

**Files:**
- Modify: `includes/agent/class-anthropic-client.php`
- Modify: `tests/Test_Anthropic_Client.php`

**Step 1: Write the failing tests**

Append to `tests/Test_Anthropic_Client.php`:

```php
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
```

**Step 2: Run tests to verify they fail**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Anthropic_Client`

Expected: FAIL — method `chat` not found.

**Step 3: Write the chat method**

Add to `includes/agent/class-anthropic-client.php`, inside the class:

```php
	/**
	 * Send a message and run the agentic tool-use loop.
	 *
	 * @param string $message      The user's new message.
	 * @param array  $conversation Previous conversation messages (Anthropic format).
	 * @return array {
	 *     @type array      $conversation Updated conversation trace.
	 *     @type array|null $styles       Last successfully validated styles, or null.
	 *     @type int        $rounds       Number of API round-trips.
	 *     @type string     $error        Error message if something failed.
	 * }
	 */
	public function chat( string $message, array $conversation = array() ): array {
		$conversation[] = array( 'role' => 'user', 'content' => $message );

		$rounds = 0;
		$styles = null;

		while ( $rounds < $this->max_rounds ) {
			$rounds++;

			$response = $this->send_request( $conversation );

			if ( is_wp_error( $response ) ) {
				return array(
					'conversation' => $conversation,
					'error'        => $response->get_error_message(),
					'styles'       => null,
					'rounds'       => $rounds,
				);
			}

			$conversation[] = array(
				'role'    => 'assistant',
				'content' => $response['content'],
			);

			if ( 'tool_use' !== $response['stop_reason'] ) {
				break;
			}

			$tool_results = array();
			foreach ( $response['content'] as $block ) {
				if ( 'tool_use' !== $block['type'] ) {
					continue;
				}

				$result = $this->execute_tool( $block['name'], $block['input'] ?? array() );

				if ( 'validate_styles' === $block['name'] && ! empty( $result['valid'] ) ) {
					$styles = $block['input']['styles'] ?? null;
				}

				$tool_results[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $block['id'],
					'content'     => wp_json_encode( $result ),
				);
			}

			$conversation[] = array( 'role' => 'user', 'content' => $tool_results );
		}

		return array(
			'conversation' => $conversation,
			'styles'       => $styles,
			'rounds'       => $rounds,
		);
	}

	/**
	 * Send a request to the Anthropic Messages API.
	 */
	private function send_request( array $messages ): array|WP_Error {
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'headers' => array(
				'x-api-key'          => $this->api_key,
				'anthropic-version'  => '2023-06-01',
				'content-type'       => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'      => $this->model,
				'max_tokens' => 4096,
				'system'     => $this->get_system_prompt(),
				'messages'   => $messages,
				'tools'      => $this->get_tool_definitions(),
			) ),
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error(
				'anthropic_api_error',
				$body['error']['message'] ?? "API returned status {$code}"
			);
		}

		return $body;
	}

	/**
	 * Execute a tool by calling the corresponding wp-theme-guard ability.
	 */
	private function execute_tool( string $name, array $input ): array {
		$ability = self::TOOL_ABILITY_MAP[ $name ] ?? null;
		if ( ! $ability ) {
			return array( 'error' => "Unknown tool: {$name}" );
		}

		$result = ( $this->tool_executor )( $ability, $input );

		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}

		return $result;
	}
```

**Step 4: Run tests to verify they pass**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Anthropic_Client`

Expected: 10 tests, OK.

**Step 5: Commit**

```bash
git add includes/agent/class-anthropic-client.php tests/Test_Anthropic_Client.php
git commit -m "feat(agent): add agentic chat loop with tool execution"
```

---

### Task 3: Chat REST endpoint

**Files:**
- Create: `includes/agent/class-agent-rest.php`
- Create: `tests/Test_Agent_REST.php`

**Step 1: Write the failing tests**

Create `tests/Test_Agent_REST.php`:

```php
<?php

declare( strict_types = 1 );

class Test_Agent_REST extends WP_UnitTestCase {

	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();

		require_once dirname( __DIR__ ) . '/includes/agent/class-anthropic-client.php';
		require_once dirname( __DIR__ ) . '/includes/agent/class-agent-rest.php';

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		WP_Theme_Guard_Agent_REST::register_routes();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_chat_route_is_registered(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wp-theme-guard/v1/agent/chat', $routes );
	}

	public function test_save_styles_route_is_registered(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wp-theme-guard/v1/agent/save-styles', $routes );
	}

	public function test_chat_requires_edit_theme_options(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/wp-theme-guard/v1/agent/chat' );
		$request->set_body_params( array( 'message' => 'hello' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_chat_returns_error_without_api_key(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'POST', '/wp-theme-guard/v1/agent/chat' );
		$request->set_body_params( array( 'message' => 'hello' ) );
		$response = $this->server->dispatch( $request );

		// Without WP_THEME_GUARD_API_KEY defined, should return error.
		$this->assertSame( 500, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'missing_api_key', $data['code'] );
	}
}
```

**Step 2: Run tests to verify they fail**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Agent_REST`

Expected: FAIL — class `WP_Theme_Guard_Agent_REST` not found.

**Step 3: Write the implementation**

Create `includes/agent/class-agent-rest.php`:

```php
<?php

declare( strict_types = 1 );

/**
 * REST API endpoints for the test agent.
 */
class WP_Theme_Guard_Agent_REST {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( 'wp-theme-guard/v1', '/agent/chat', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_chat' ),
			'permission_callback' => array( __CLASS__, 'check_permissions' ),
			'args'                => array(
				'message'      => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'conversation' => array(
					'required' => false,
					'type'     => 'array',
					'default'  => array(),
				),
			),
		) );

		register_rest_route( 'wp-theme-guard/v1', '/agent/save-styles', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_save_styles' ),
			'permission_callback' => array( __CLASS__, 'check_permissions' ),
			'args'                => array(
				'styles' => array(
					'required' => true,
					'type'     => 'object',
				),
			),
		) );
	}

	public static function check_permissions(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Handle chat requests: send message through the Anthropic client.
	 */
	public static function handle_chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$api_key = defined( 'WP_THEME_GUARD_API_KEY' ) ? WP_THEME_GUARD_API_KEY : '';
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'missing_api_key',
				'WP_THEME_GUARD_API_KEY constant is not defined in wp-config.php.',
				array( 'status' => 500 )
			);
		}

		$client = new WP_Theme_Guard_Anthropic_Client( $api_key );
		$result = $client->chat(
			$request->get_param( 'message' ),
			$request->get_param( 'conversation' )
		);

		if ( isset( $result['error'] ) ) {
			return new WP_Error(
				'agent_error',
				$result['error'],
				array( 'status' => 502 )
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle save-styles requests: write to wp_global_styles CPT.
	 */
	public static function handle_save_styles( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$styles = $request->get_param( 'styles' );

		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'no_global_styles',
				'Could not find global styles post.',
				array( 'status' => 500 )
			);
		}

		$existing              = json_decode( $post->post_content, true ) ?: array();
		$existing['version']   = $existing['version'] ?? WP_Theme_JSON::LATEST_SCHEMA;
		$existing['styles']    = array_replace_recursive( $existing['styles'] ?? array(), $styles );

		$updated = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => wp_json_encode( $existing ),
		), true );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$revisions    = wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
		$revision_url = '';
		if ( $revisions ) {
			$revision     = reset( $revisions );
			$revision_url = admin_url( "revision.php?revision={$revision->ID}" );
		}

		return new WP_REST_Response( array(
			'success'      => true,
			'revision_url' => $revision_url,
		), 200 );
	}
}
```

**Step 4: Run tests to verify they pass**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Agent_REST`

Expected: 4 tests, OK.

**Step 5: Commit**

```bash
git add includes/agent/class-agent-rest.php tests/Test_Agent_REST.php
git commit -m "feat(agent): add chat and save-styles REST endpoints"
```

---

### Task 4: Admin page registration and conditional loading

**Files:**
- Create: `includes/agent/class-agent-page.php`
- Modify: `wp-theme-guard.php:30-45`

**Step 1: Create the admin page class**

Create `includes/agent/class-agent-page.php`:

```php
<?php

declare( strict_types = 1 );

/**
 * Registers the Theme Guard Agent admin page under Tools.
 */
class WP_Theme_Guard_Agent_Page {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function add_menu_page(): void {
		add_management_page(
			__( 'Theme Guard Agent', 'wp-theme-guard' ),
			__( 'Theme Guard Agent', 'wp-theme-guard' ),
			'edit_theme_options',
			'wp-theme-guard-agent',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'tools_page_wp-theme-guard-agent' !== $hook ) {
			return;
		}

		$plugin_url = plugins_url( '', dirname( __FILE__ ) );

		wp_enqueue_style(
			'wp-theme-guard-agent',
			$plugin_url . '/assets/agent-page.css',
			array(),
			WP_THEME_GUARD_VERSION
		);
		wp_enqueue_script(
			'wp-theme-guard-agent',
			$plugin_url . '/assets/agent-page.js',
			array(),
			WP_THEME_GUARD_VERSION,
			true
		);
		wp_localize_script( 'wp-theme-guard-agent', 'wpThemeGuardAgent', array(
			'restUrl'   => rest_url( 'wp-theme-guard/v1/agent/' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'hasApiKey' => defined( 'WP_THEME_GUARD_API_KEY' ),
		) );
	}

	public static function render_page(): void {
		$global_styles_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$revisions        = wp_get_post_revisions( $global_styles_id, array( 'numberposts' => 1 ) );
		$revision_url     = '';
		if ( $revisions ) {
			$revision     = reset( $revisions );
			$revision_url = admin_url( "revision.php?revision={$revision->ID}" );
		}
		?>
		<div class="wrap" id="wp-theme-guard-agent">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Theme Guard Agent', 'wp-theme-guard' ); ?>
			</h1>
			<?php if ( $revision_url ) : ?>
				<a href="<?php echo esc_url( $revision_url ); ?>" class="page-title-action">
					<?php esc_html_e( 'View Revisions', 'wp-theme-guard' ); ?>
				</a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php if ( ! defined( 'WP_THEME_GUARD_API_KEY' ) ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'Add your Anthropic API key to wp-config.php to enable the agent:', 'wp-theme-guard' ); ?>
					</p>
					<p><code>define( 'WP_THEME_GUARD_API_KEY', 'sk-ant-...' );</code></p>
				</div>
			<?php endif; ?>

			<div id="agent-conversation"></div>

			<div id="agent-input-area">
				<textarea
					id="agent-input"
					rows="3"
					placeholder="<?php esc_attr_e( 'Describe the styles you want...', 'wp-theme-guard' ); ?>"
					<?php disabled( ! defined( 'WP_THEME_GUARD_API_KEY' ) ); ?>
				></textarea>
				<button
					id="agent-send"
					class="button button-primary"
					<?php disabled( ! defined( 'WP_THEME_GUARD_API_KEY' ) ); ?>
				>
					<?php esc_html_e( 'Send', 'wp-theme-guard' ); ?>
				</button>
			</div>

			<div id="agent-actions" style="display:none;">
				<button id="agent-save" class="button button-primary">
					<?php esc_html_e( 'Save as Global Styles', 'wp-theme-guard' ); ?>
				</button>
				<span id="agent-save-status"></span>
			</div>
		</div>
		<?php
	}
}
```

**Step 2: Add conditional loading to the plugin entry point**

In `wp-theme-guard.php`, add the agent loader inside `wp_theme_guard_init()`, after line 44 (the MCP Adapter init):

```php
	// Load test agent when API key is configured.
	if ( defined( 'WP_THEME_GUARD_API_KEY' ) ) {
		require_once WP_THEME_GUARD_PATH . 'includes/agent/class-anthropic-client.php';
		require_once WP_THEME_GUARD_PATH . 'includes/agent/class-agent-rest.php';
		require_once WP_THEME_GUARD_PATH . 'includes/agent/class-agent-page.php';
		WP_Theme_Guard_Agent_REST::init();
		WP_Theme_Guard_Agent_Page::init();
	}
```

The full `wp_theme_guard_init()` function should now look like:

```php
function wp_theme_guard_init(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', 'wp_theme_guard_missing_abilities_notice' );
		return;
	}

	require_once WP_THEME_GUARD_PATH . 'includes/class-style-validator.php';
	require_once WP_THEME_GUARD_PATH . 'includes/class-block-validator.php';
	require_once WP_THEME_GUARD_PATH . 'includes/class-schema-provider.php';
	require_once WP_THEME_GUARD_PATH . 'includes/class-abilities.php';

	// Initialize MCP Adapter if available.
	if ( class_exists( \WP\MCP\Plugin::class ) ) {
		\WP\MCP\Plugin::instance();
	}

	// Load test agent when API key is configured.
	if ( defined( 'WP_THEME_GUARD_API_KEY' ) ) {
		require_once WP_THEME_GUARD_PATH . 'includes/agent/class-anthropic-client.php';
		require_once WP_THEME_GUARD_PATH . 'includes/agent/class-agent-rest.php';
		require_once WP_THEME_GUARD_PATH . 'includes/agent/class-agent-page.php';
		WP_Theme_Guard_Agent_REST::init();
		WP_Theme_Guard_Agent_Page::init();
	}
}
```

**Step 3: Run all tests to verify nothing is broken**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit`

Expected: All existing tests pass. (Agent tests already pass from Tasks 1-3.)

**Step 4: Commit**

```bash
git add includes/agent/class-agent-page.php wp-theme-guard.php
git commit -m "feat(agent): add admin page and conditional loading"
```

---

### Task 5: Frontend JavaScript

**Files:**
- Create: `assets/agent-page.js`

**Step 1: Create the conversation UI script**

Create `assets/agent-page.js`:

```js
( function () {
	'use strict';

	var config = window.wpThemeGuardAgent;
	var conversation = [];
	var lastStyles = null;
	var renderedCount = 0;

	var els = {
		conversation: document.getElementById( 'agent-conversation' ),
		input: document.getElementById( 'agent-input' ),
		send: document.getElementById( 'agent-send' ),
		actions: document.getElementById( 'agent-actions' ),
		save: document.getElementById( 'agent-save' ),
		saveStatus: document.getElementById( 'agent-save-status' ),
	};

	if ( config.hasApiKey ) {
		addMessage(
			'assistant',
			'Welcome! Describe the styles you want and I\u2019ll generate them for your theme.\n\n' +
			'Examples:\n' +
			'\u2022 "Make my headings use the primary color"\n' +
			'\u2022 "Set body text to 18px with comfortable line height"\n' +
			'\u2022 "Add a subtle border to all blocks"'
		);
	}

	els.send.addEventListener( 'click', sendMessage );
	els.input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			sendMessage();
		}
	} );
	els.save.addEventListener( 'click', saveStyles );

	function sendMessage() {
		var message = els.input.value.trim();
		if ( ! message ) {
			return;
		}

		els.input.value = '';
		setInputEnabled( false );
		addMessage( 'user', message );
		var loadingEl = addMessage( 'assistant', 'Thinking\u2026' );
		loadingEl.classList.add( 'agent-loading' );

		fetch( config.restUrl + 'chat', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( {
				message: message,
				conversation: conversation,
			} ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( data.message || 'Request failed' );
					}
					return data;
				} );
			} )
			.then( function ( data ) {
				conversation = data.conversation;
				lastStyles = data.styles;

				loadingEl.remove();
				renderConversation();

				if ( lastStyles ) {
					els.actions.style.display = '';
				}

				if ( data.rounds ) {
					var roundsEl = document.createElement( 'div' );
					roundsEl.className = 'agent-rounds';
					roundsEl.textContent = data.rounds + ' round' + ( data.rounds > 1 ? 's' : '' );
					els.conversation.appendChild( roundsEl );
				}
			} )
			.catch( function ( err ) {
				loadingEl.textContent = 'Error: ' + err.message;
				loadingEl.classList.remove( 'agent-loading' );
				loadingEl.classList.add( 'agent-error' );
			} )
			.finally( function () {
				setInputEnabled( true );
				els.input.focus();
			} );
	}

	function renderConversation() {
		els.conversation.innerHTML = '';

		for ( var i = 0; i < conversation.length; i++ ) {
			var msg = conversation[ i ];

			if ( msg.role === 'user' && typeof msg.content === 'string' ) {
				addMessage( 'user', msg.content );
			} else if ( msg.role === 'assistant' && Array.isArray( msg.content ) ) {
				for ( var j = 0; j < msg.content.length; j++ ) {
					var block = msg.content[ j ];
					if ( block.type === 'text' ) {
						addMessage( 'assistant', block.text );
					} else if ( block.type === 'tool_use' ) {
						addToolCall( block );
					}
				}
			}
			// Skip tool_result messages (rendered inside tool calls).
		}
	}

	function addMessage( role, text ) {
		var el = document.createElement( 'div' );
		el.className = 'agent-message agent-message-' + role;
		el.textContent = text;
		els.conversation.appendChild( el );
		els.conversation.scrollTop = els.conversation.scrollHeight;
		return el;
	}

	function addToolCall( block ) {
		var el = document.createElement( 'details' );
		el.className = 'agent-tool-call';

		var summary = document.createElement( 'summary' );
		summary.textContent = block.name + '()';
		el.appendChild( summary );

		var inputPre = document.createElement( 'pre' );
		inputPre.textContent = JSON.stringify( block.input, null, 2 );
		el.appendChild( inputPre );

		// Find matching tool_result in conversation.
		for ( var i = 0; i < conversation.length; i++ ) {
			var msg = conversation[ i ];
			if ( msg.role !== 'user' || ! Array.isArray( msg.content ) ) {
				continue;
			}
			for ( var j = 0; j < msg.content.length; j++ ) {
				var result = msg.content[ j ];
				if ( result.type === 'tool_result' && result.tool_use_id === block.id ) {
					var resultPre = document.createElement( 'pre' );
					resultPre.className = 'agent-tool-result';
					try {
						resultPre.textContent = JSON.stringify(
							JSON.parse( result.content ),
							null,
							2
						);
					} catch ( e ) {
						resultPre.textContent = result.content;
					}
					el.appendChild( resultPre );
				}
			}
		}

		els.conversation.appendChild( el );
		els.conversation.scrollTop = els.conversation.scrollHeight;
	}

	function saveStyles() {
		if ( ! lastStyles ) {
			return;
		}

		els.save.disabled = true;
		els.saveStatus.textContent = 'Saving\u2026';

		fetch( config.restUrl + 'save-styles', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( { styles: lastStyles } ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( data.message || 'Save failed' );
					}
					return data;
				} );
			} )
			.then( function ( data ) {
				els.saveStatus.innerHTML =
					'Saved! ' +
					( data.revision_url
						? '<a href="' + data.revision_url + '">View revision</a>'
						: '' );
			} )
			.catch( function ( err ) {
				els.saveStatus.textContent = 'Error: ' + err.message;
			} )
			.finally( function () {
				els.save.disabled = false;
			} );
	}

	function setInputEnabled( enabled ) {
		els.send.disabled = ! enabled;
		els.input.disabled = ! enabled;
	}
} )();
```

**Step 2: Verify the file was created**

Run: `ls -la assets/agent-page.js` (from project root)

Expected: File exists.

**Step 3: Commit**

```bash
git add assets/agent-page.js
git commit -m "feat(agent): add conversation UI JavaScript"
```

---

### Task 6: Frontend CSS and final verification

**Files:**
- Create: `assets/agent-page.css`

**Step 1: Create the stylesheet**

Create `assets/agent-page.css`:

```css
/* Theme Guard Agent — admin page styles. */

#agent-conversation {
	min-height: 300px;
	max-height: 500px;
	overflow-y: auto;
	border: 1px solid #c3c4c7;
	background: #fff;
	padding: 16px;
	margin: 16px 0;
	border-radius: 4px;
}

.agent-message {
	margin-bottom: 12px;
	padding: 10px 14px;
	border-radius: 8px;
	max-width: 80%;
	white-space: pre-wrap;
	word-wrap: break-word;
	line-height: 1.5;
}

.agent-message-user {
	margin-left: auto;
	background: #2271b1;
	color: #fff;
}

.agent-message-assistant {
	background: #f0f0f1;
	color: #1d2327;
}

.agent-loading {
	color: #646970;
	font-style: italic;
}

.agent-error {
	color: #d63638;
	background: #fcf0f1;
}

.agent-rounds {
	text-align: center;
	color: #646970;
	font-size: 12px;
	margin: 8px 0;
}

.agent-tool-call {
	margin-bottom: 12px;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	max-width: 80%;
}

.agent-tool-call summary {
	padding: 8px 12px;
	cursor: pointer;
	background: #f6f7f7;
	font-family: monospace;
	font-size: 13px;
}

.agent-tool-call pre {
	padding: 8px 12px;
	margin: 0;
	overflow-x: auto;
	font-size: 12px;
	max-height: 200px;
	overflow-y: auto;
	white-space: pre-wrap;
	word-wrap: break-word;
}

.agent-tool-result {
	border-top: 1px solid #c3c4c7;
	background: #f9f9f9;
}

#agent-input-area {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

#agent-input {
	flex: 1;
	resize: vertical;
}

#agent-actions {
	margin-top: 12px;
	display: flex;
	align-items: center;
	gap: 12px;
}

#agent-save-status {
	color: #646970;
}

#agent-save-status a {
	text-decoration: none;
	color: #2271b1;
}
```

**Step 2: Run all tests to verify nothing is broken**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit`

Expected: All tests pass.

**Step 3: Commit**

```bash
git add assets/agent-page.css
git commit -m "feat(agent): add admin page styles"
```

**Step 4: Manual smoke test**

1. Ensure wp-env is running: `npx wp-env start`
2. Add API key to wp-env config (or use WP-CLI):
   ```bash
   npx wp-env run cli -- wp config set WP_THEME_GUARD_API_KEY 'sk-ant-your-key-here' --type=constant
   ```
3. Visit `http://localhost:8890/wp-admin/tools.php?page=wp-theme-guard-agent`
4. Verify:
   - Page loads with welcome message and example prompts
   - "View Revisions" link appears in header
   - Text input and Send button are enabled
   - Send a test prompt like "Make my headings use the primary color"
   - Conversation renders with tool calls (collapsible)
   - "Save as Global Styles" button appears after valid styles are generated
   - Saving writes to global styles and shows revision link

**Step 5: Final commit with any fixes from smoke test**

```bash
git add -A
git commit -m "feat(agent): complete test agent admin page"
```
