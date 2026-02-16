<?php

declare( strict_types = 1 );

class WP_Theme_Guard_Schema_Provider {

	public static function execute( array $input ): array {
		$include = $input['include'] ?? array( 'styles', 'blocks', 'layout' );
		$result  = array();

		if ( in_array( 'styles', $include, true ) ) {
			$result['styles'] = self::get_style_constraints();
		}

		if ( in_array( 'blocks', $include, true ) ) {
			$result['blocks'] = self::get_block_constraints();
		}

		if ( in_array( 'layout', $include, true ) ) {
			$result['layout'] = self::get_layout_constraints();
		}

		return $result;
	}

	private static function get_style_constraints(): array {
		$theme_json = WP_Theme_JSON_Resolver::get_merged_data();
		$settings   = $theme_json->get_settings();

		return array(
			'colors'     => array(
				'palette'        => self::flatten_presets( $settings['color']['palette'] ?? array() ),
				'custom_allowed' => $settings['color']['custom'] ?? true,
				'gradients'      => self::flatten_presets( $settings['color']['gradients'] ?? array() ),
				'duotone'        => self::flatten_presets( $settings['color']['duotone'] ?? array() ),
			),
			'typography' => array(
				'font_sizes'       => self::flatten_presets( $settings['typography']['fontSizes'] ?? array() ),
				'font_families'    => self::flatten_presets( $settings['typography']['fontFamilies'] ?? array() ),
				'custom_font_size' => $settings['typography']['customFontSize'] ?? true,
			),
			'spacing'    => array(
				'units'   => $settings['spacing']['units'] ?? array( 'px', 'em', 'rem', 'vh', 'vw', '%' ),
				'padding' => $settings['spacing']['padding'] ?? false,
				'margin'  => $settings['spacing']['margin'] ?? false,
				'presets' => self::flatten_presets( $settings['spacing']['spacingSizes'] ?? array() ),
			),
		);
	}

	private static function get_block_constraints(): array {
		$registry      = WP_Block_Type_Registry::get_instance();
		$all_blocks    = $registry->get_all_registered();
		$registered    = array();
		$nesting_rules = array();

		foreach ( $all_blocks as $name => $block_type ) {
			$registered[] = $name;

			$rules = array();
			if ( ! empty( $block_type->parent ) ) {
				$rules['parent'] = $block_type->parent;
			}
			if ( ! empty( $block_type->ancestor ) ) {
				$rules['ancestor'] = $block_type->ancestor;
			}
			if ( ! empty( $block_type->allowed_blocks ) && is_array( $block_type->allowed_blocks ) ) {
				$rules['allowedBlocks'] = $block_type->allowed_blocks;
			}

			if ( ! empty( $rules ) ) {
				$nesting_rules[ $name ] = $rules;
			}
		}

		sort( $registered );

		return array(
			'registered'    => $registered,
			'nesting_rules' => $nesting_rules,
		);
	}

	private static function get_layout_constraints(): array {
		$theme_json = WP_Theme_JSON_Resolver::get_merged_data();
		$settings   = $theme_json->get_settings();
		$raw        = $theme_json->get_raw_data();

		return array(
			'content_size' => $raw['settings']['layout']['contentSize'] ?? '',
			'wide_size'    => $raw['settings']['layout']['wideSize'] ?? '',
		);
	}

	private static function flatten_presets( array $presets ): array {
		$flat = array();
		foreach ( $presets as $value ) {
			if ( is_array( $value ) && isset( $value[0] ) ) {
				$flat = array_merge( $flat, $value );
			} elseif ( is_array( $value ) && isset( $value['slug'] ) ) {
				$flat[] = $value;
			} elseif ( is_array( $value ) ) {
				$flat = array_merge( $flat, self::flatten_presets( $value ) );
			}
		}
		return $flat;
	}
}
