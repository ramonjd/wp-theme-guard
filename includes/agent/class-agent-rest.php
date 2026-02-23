<?php

declare( strict_types = 1 );

/**
 * REST API endpoint for the test agent chat.
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
					'required'          => false,
					'type'              => 'array',
					'default'           => array(),
					'validate_callback' => static function ( $value ): bool {
						if ( ! is_array( $value ) ) {
							return false;
						}
						$allowed_roles = array( 'user', 'assistant' );
						foreach ( $value as $entry ) {
							if ( ! is_array( $entry ) || ! isset( $entry['role'], $entry['content'] ) ) {
								return false;
							}
							if ( ! in_array( $entry['role'], $allowed_roles, true ) ) {
								return false;
							}
						}
						return true;
					},
				),
			),
		) );

		register_rest_route( 'wp-theme-guard/v1', '/agent/reset-styles', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_reset_styles' ),
			'permission_callback' => array( __CLASS__, 'check_permissions' ),
		) );
	}

	public static function check_permissions(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Handle reset: replace the global styles CPT with the base theme.json object.
	 */
	public static function handle_reset_styles(): WP_REST_Response|WP_Error {
		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();

		$base_content = wp_json_encode( array(
			'version'                    => WP_Theme_JSON::LATEST_SCHEMA,
			'isGlobalStylesUserThemeJSON' => true,
			'settings'                   => new \stdClass(),
			'styles'                     => new \stdClass(),
		) );

		$result = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $base_content,
		), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'reset' => true ), 200 );
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
}
