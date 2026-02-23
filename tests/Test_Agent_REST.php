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
		WP_Theme_Guard_Agent_REST::init();
		do_action( 'rest_api_init', $this->server );
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
