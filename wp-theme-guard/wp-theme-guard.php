<?php
/**
 * Plugin Name: WP Theme Guard
 * Description: Validates AI-generated content against your site's design system and block rules via the Abilities API.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author: The WordPress Contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_THEME_GUARD_VERSION', '0.1.0' );
define( 'WP_THEME_GUARD_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Check that the Abilities API is available before loading.
 */
function wp_theme_guard_init(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', 'wp_theme_guard_missing_abilities_notice' );
		return;
	}

	require_once WP_THEME_GUARD_PATH . 'includes/class-style-validator.php';
	require_once WP_THEME_GUARD_PATH . 'includes/class-block-validator.php';
	require_once WP_THEME_GUARD_PATH . 'includes/class-schema-provider.php';
	require_once WP_THEME_GUARD_PATH . 'includes/class-abilities.php';
}
add_action( 'plugins_loaded', 'wp_theme_guard_init' );

/**
 * Admin notice when Abilities API is not available.
 */
function wp_theme_guard_missing_abilities_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'WP Theme Guard requires WordPress 7.0+ with the Abilities API.', 'wp-theme-guard' );
	echo '</p></div>';
}
