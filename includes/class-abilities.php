<?php

declare( strict_types = 1 );

/**
 * Registers wp-theme-guard abilities with the Abilities API.
 */
class WP_Theme_Guard_Abilities {

	/**
	 * Initialize ability registration.
	 */
	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register ability categories.
	 */
	public static function register_categories(): void {
		wp_register_ability_category( 'validation', array(
			'label'       => __( 'Validation', 'wp-theme-guard' ),
			'description' => __( 'Abilities for validating content against site rules.', 'wp-theme-guard' ),
		) );

		wp_register_ability_category( 'data-retrieval', array(
			'label'       => __( 'Data Retrieval', 'wp-theme-guard' ),
			'description' => __( 'Abilities for retrieving site configuration and constraints.', 'wp-theme-guard' ),
		) );
	}

	/**
	 * Register abilities.
	 */
	public static function register_abilities(): void {
		self::register_validate_styles();
		self::register_validate_blocks();
		self::register_get_constraints();
	}

	/**
	 * Register the validate-styles ability.
	 */
	private static function register_validate_styles(): void {
		wp_register_ability( 'wp-theme-guard/validate-styles', array(
			'label'               => __( 'Validate Styles', 'wp-theme-guard' ),
			'description'         => __( 'Validates style values against the site\'s theme.json design system.', 'wp-theme-guard' ),
			'category'            => 'validation',
			'execute_callback'    => array( WP_Theme_Guard_Style_Validator::class, 'execute' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'styles'  => array(
						'type'        => 'object',
						'description' => 'Style declarations to validate, following theme.json styles structure.',
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'block', 'element', 'global' ),
						'default'     => 'block',
						'description' => 'The context for validation.',
					),
					'blockName' => array(
						'type'        => 'string',
						'description' => 'Optional block name (e.g. "core/paragraph"). When provided, also checks block supports.',
					),
				),
				'required'   => array( 'styles' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'valid'    => array( 'type' => 'boolean' ),
					'errors'   => array( 'type' => 'array' ),
					'warnings' => array( 'type' => 'array' ),
				),
				'required'   => array( 'valid', 'errors', 'warnings' ),
			),
		) );
	}

	/**
	 * Register the validate-blocks ability.
	 */
	private static function register_validate_blocks(): void {
		wp_register_ability( 'wp-theme-guard/validate-blocks', array(
			'label'               => __( 'Validate Blocks', 'wp-theme-guard' ),
			'description'         => __( 'Validates block markup against the block registry and nesting rules.', 'wp-theme-guard' ),
			'category'            => 'validation',
			'execute_callback'    => array( WP_Theme_Guard_Block_Validator::class, 'execute' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'content'   => array(
						'type'        => 'string',
						'description' => 'Block markup string to validate.',
					),
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Optional post type to check allowed blocks against.',
					),
				),
				'required'   => array( 'content' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'valid'       => array( 'type' => 'boolean' ),
					'block_count' => array( 'type' => 'integer' ),
					'errors'      => array( 'type' => 'array' ),
					'warnings'    => array( 'type' => 'array' ),
				),
				'required'   => array( 'valid', 'block_count', 'errors', 'warnings' ),
			),
		) );
	}

	/**
	 * Register the get-constraints ability.
	 */
	private static function register_get_constraints(): void {
		wp_register_ability( 'wp-theme-guard/get-constraints', array(
			'label'               => __( 'Get Design Constraints', 'wp-theme-guard' ),
			'description'         => __( 'Exports the site\'s effective design rules as a structured snapshot for AI context.', 'wp-theme-guard' ),
			'category'            => 'data-retrieval',
			'execute_callback'    => array( WP_Theme_Guard_Schema_Provider::class, 'execute' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'include' => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'styles', 'blocks', 'layout' ),
						),
						'default'     => array( 'styles', 'blocks', 'layout' ),
						'description' => 'Which constraint types to return.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'styles' => array( 'type' => 'object' ),
					'blocks' => array( 'type' => 'object' ),
					'layout' => array( 'type' => 'object' ),
				),
			),
			'meta'                => array(
				'annotations' => array(
					'readonly' => true,
				),
			),
		) );
	}

	/**
	 * Permission callback: user can edit posts.
	 */
	public static function can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}
}

WP_Theme_Guard_Abilities::init();
