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
			$ability_obj = wp_get_ability( $ability );
			if ( ! $ability_obj ) {
				return new \WP_Error( 'ability_not_found', "Ability '{$ability}' not found." );
			}
			return $ability_obj->execute( $input );
		};
	}

	/**
	 * Tool definitions in Anthropic API format.
	 */
	public function get_tool_definitions(): array {
		return array(
			array(
				'name'         => 'get_constraints',
				'description'  => "Get the site's design rules and theme.json structure guide: targeting hierarchy, style properties, color palette, font sizes, spacing presets, block rules, and layout settings.",
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'include' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'structure', 'styles', 'blocks', 'layout' ),
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
1. ALWAYS call get_constraints first to learn the theme.json structure, available presets, and design rules.
2. Study the complete_example in the structure guide — it shows how the final styles object must be structured with blocks, elements, and global styles.
3. Use the common_aliases mapping to translate user terms (e.g. "buttons" → core/button, "container" → core/group).
4. Generate styles following the targeting hierarchy.
5. Call validate_styles for EACH target separately:
   - For a specific block: set blockName (e.g. blockName: "core/button") and pass only that block's style properties.
   - For global styles: omit blockName and pass the style properties directly.
   - IMPORTANT: validate_styles takes flat style properties (color, typography, etc.), NOT the full tree with blocks/elements keys.
6. If errors are returned, fix them and re-validate.
7. If warnings suggest preset alternatives, prefer using presets for design system consistency.

## Completion
When you have valid styles ready, present a summary to the user:
- What was changed and which blocks/elements were targeted.
- The final complete styles object (matching the complete_example structure from get_constraints).
- Ask the user to confirm before they save.
PROMPT;
	}

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
		$styles = array();

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
					$fragment   = $block['input']['styles'] ?? array();
					$block_name = $block['input']['blockName'] ?? '';

					if ( $block_name ) {
						$styles['blocks'][ $block_name ] = array_merge(
							$styles['blocks'][ $block_name ] ?? array(),
							$fragment
						);
					} else {
						$styles = array_merge( $styles, $fragment );
					}
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
			'styles'       => ! empty( $styles ) ? $styles : null,
			'rounds'       => $rounds,
		);
	}

	/**
	 * Send a request to the Anthropic Messages API.
	 */
	private function send_request( array $messages ): array|\WP_Error {
		// Ensure tool_use input fields are JSON objects, not arrays.
		// PHP's json_decode turns {} into [] which json_encode sends as [].
		$normalized = array_map( static function ( $msg ) {
			if ( ! is_array( $msg['content'] ?? null ) ) {
				return $msg;
			}
			$msg['content'] = array_map( static function ( $block ) {
				if ( 'tool_use' === ( $block['type'] ?? '' ) && array_key_exists( 'input', $block ) ) {
					$block['input'] = (object) $block['input'];
				}
				return $block;
			}, $msg['content'] );
			return $msg;
		}, $messages );

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
				'messages'   => $normalized,
				'tools'      => $this->get_tool_definitions(),
			) ),
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'anthropic_api_error',
				'Invalid JSON in API response'
			);
		}

		if ( 200 !== $code ) {
			return new \WP_Error(
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
}
