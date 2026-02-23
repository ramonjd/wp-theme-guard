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
	private function send_request( array $messages ): array|\WP_Error {
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
