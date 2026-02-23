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

		$plugin_url = plugins_url( '', dirname( __FILE__, 2 ) );

		wp_enqueue_style(
			'wp-theme-guard-agent',
			$plugin_url . '/assets/agent-page.css',
			array(),
			WP_THEME_GUARD_VERSION
		);
		wp_enqueue_script(
			'wp-theme-guard-agent',
			$plugin_url . '/assets/agent-page.js',
			array( 'wp-api-fetch' ),
			WP_THEME_GUARD_VERSION,
			true
		);
		wp_localize_script( 'wp-theme-guard-agent', 'wpThemeGuardAgent', array(
			'restUrl'        => rest_url( 'wp-theme-guard/v1/agent/' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'hasApiKey'      => defined( 'WP_THEME_GUARD_API_KEY' ),
			'globalStylesId' => WP_Theme_JSON_Resolver::get_user_global_styles_post_id(),
			'adminUrl'       => admin_url(),
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
