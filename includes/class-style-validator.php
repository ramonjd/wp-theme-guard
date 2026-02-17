<?php

declare( strict_types = 1 );

class WP_Theme_Guard_Style_Validator {

	private static ?array $test_settings = null;

	public static function set_test_settings( ?array $override ): void {
		self::$test_settings = $override;
	}

	/**
	 * Map from style paths to their settings toggle paths.
	 * Only entries that don't follow the default convention (category.property -> settings.category.property).
	 */
	private const SETTINGS_EXCEPTIONS = array(
		'color.gradient'     => array( 'color', 'customGradient' ),
		'typography.fontSize' => array( 'typography', 'customFontSize' ),
	);

	/**
	 * Settings that control whether custom values are allowed for a whole category.
	 * When false, only preset references are permitted.
	 */
	private const CUSTOM_VALUE_SETTINGS = array(
		'color'   => array( 'color', 'custom' ),
		'spacing' => array( 'spacing', 'customSpacingSize' ),
	);

	/**
	 * Map from style paths to block support paths.
	 * Only entries where the support path differs from the style path.
	 */
	private const BLOCK_SUPPORT_MAP = array(
		'border.color'              => array( '__experimentalBorder', 'color' ),
		'border.radius'             => array( '__experimentalBorder', 'radius' ),
		'border.style'              => array( '__experimentalBorder', 'style' ),
		'border.width'              => array( '__experimentalBorder', 'width' ),
		'border.top.color'          => array( '__experimentalBorder', 'color' ),
		'border.top.style'          => array( '__experimentalBorder', 'style' ),
		'border.top.width'          => array( '__experimentalBorder', 'width' ),
		'border.right.color'        => array( '__experimentalBorder', 'color' ),
		'border.right.style'        => array( '__experimentalBorder', 'style' ),
		'border.right.width'        => array( '__experimentalBorder', 'width' ),
		'border.bottom.color'       => array( '__experimentalBorder', 'color' ),
		'border.bottom.style'       => array( '__experimentalBorder', 'style' ),
		'border.bottom.width'       => array( '__experimentalBorder', 'width' ),
		'border.left.color'         => array( '__experimentalBorder', 'color' ),
		'border.left.style'         => array( '__experimentalBorder', 'style' ),
		'border.left.width'         => array( '__experimentalBorder', 'width' ),
	);

	public static function execute( array $input ): array {
		$styles     = $input['styles'] ?? array();
		$block_name = $input['blockName'] ?? '';
		$errors     = array();
		$warnings   = array();

		// Layer 1: Normalize — flatten styles into (path, value) pairs.
		$pairs = self::normalize( $styles );

		// Layer 2: Property validity — check paths against VALID_STYLES.
		$valid_pairs = self::check_property_validity( $pairs, $errors );

		// Layer 3: Value sanitization.
		$safe_pairs = self::check_value_sanitization( $valid_pairs, $errors );

		// Layer 4: Theme settings gate.
		self::check_theme_settings( $safe_pairs, $errors );

		// Layer 5: Block supports gate (optional).
		if ( $block_name ) {
			self::check_block_supports( $safe_pairs, $block_name, $errors );
		}

		// Layer 6: Preset suggestions.
		self::suggest_presets( $safe_pairs, $warnings );

		return array(
			'valid'    => empty( $errors ),
			'errors'   => array_values( $errors ),
			'warnings' => array_values( $warnings ),
		);
	}

	private static function normalize( array $styles, string $prefix = '' ): array {
		$pairs = array();
		foreach ( $styles as $key => $value ) {
			$path = $prefix ? "{$prefix}.{$key}" : $key;
			if ( is_array( $value ) ) {
				$pairs = array_merge( $pairs, self::normalize( $value, $path ) );
			} else {
				$pairs[ $path ] = (string) $value;
			}
		}
		return $pairs;
	}

	private static function check_property_validity( array $pairs, array &$errors ): array {
		$valid_styles = WP_Theme_JSON::VALID_STYLES;
		$valid_pairs  = array();

		foreach ( $pairs as $path => $value ) {
			if ( self::is_valid_style_path( $path, $valid_styles ) ) {
				$valid_pairs[ $path ] = $value;
			} else {
				$errors[] = array(
					'property' => $path,
					'value'    => $value,
					'message'  => sprintf( 'Unknown style property "%s".', $path ),
					'layer'    => 'property-validity',
				);
			}
		}

		return $valid_pairs;
	}

	private static function is_valid_style_path( string $path, array $schema ): bool {
		$parts   = explode( '.', $path );
		$current = $schema;

		foreach ( $parts as $part ) {
			if ( null === $current ) {
				// A null node in the schema means any sub-path is valid.
				return true;
			}
			if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) ) {
				return false;
			}
			$current = $current[ $part ];
		}

		return true;
	}

	private static function check_value_sanitization( array $pairs, array &$errors ): array {
		$path_to_css = self::build_path_to_css_map();
		$safe_pairs  = array();

		foreach ( $pairs as $path => $value ) {
			if ( '' === trim( $value ) ) {
				$errors[] = array(
					'property' => $path,
					'value'    => $value,
					'message'  => 'Empty value.',
					'layer'    => 'value-sanitization',
				);
				continue;
			}

			$css_property = $path_to_css[ $path ] ?? self::fallback_css_property( $path );
			$test_string  = "{$css_property}: {$value}";
			$filtered     = safecss_filter_attr( $test_string );

			if ( empty( trim( $filtered ) ) ) {
				$errors[] = array(
					'property' => $path,
					'value'    => $value,
					'message'  => sprintf( 'Value "%s" is not valid CSS for property "%s".', $value, $css_property ),
					'layer'    => 'value-sanitization',
				);
				continue;
			}

			$safe_pairs[ $path ] = $value;
		}

		return $safe_pairs;
	}

	private static function build_path_to_css_map(): array {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}

		$map = array();
		foreach ( WP_Theme_JSON::PROPERTIES_METADATA as $css_prop => $style_path ) {
			if ( str_starts_with( $css_prop, '--wp--style--root--' ) ) {
				continue;
			}
			$key = implode( '.', $style_path );
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = $css_prop;
			}
		}
		return $map;
	}

	private static function fallback_css_property( string $path ): string {
		$parts = explode( '.', $path );
		$last  = end( $parts );
		$kebab = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $last ) );
		return $kebab;
	}

	private static function check_theme_settings( array $pairs, array &$errors ): void {
		$settings = self::get_merged_settings();

		foreach ( $pairs as $path => $value ) {
			$setting_path = self::get_settings_path_for_style( $path );
			if ( null === $setting_path ) {
				continue;
			}

			$setting_value = self::array_get( $settings, $setting_path );

			if ( false === $setting_value ) {
				$errors[] = array(
					'property' => $path,
					'value'    => $value,
					'message'  => sprintf( '"%s" is disabled by the theme.', $path ),
					'layer'    => 'theme-settings',
				);
				continue;
			}

			if ( self::is_preset_reference( $value ) ) {
				continue;
			}

			$category          = explode( '.', $path )[0];
			$custom_value_path = self::CUSTOM_VALUE_SETTINGS[ $category ] ?? null;
			if ( $custom_value_path ) {
				$custom_allowed = self::array_get( $settings, $custom_value_path );
				if ( false === $custom_allowed ) {
					$errors[] = array(
						'property' => $path,
						'value'    => $value,
						'message'  => sprintf( 'Custom values for "%s" are not allowed. Use a preset reference.', $category ),
						'layer'    => 'theme-settings',
					);
				}
			}
		}
	}

	private static function get_settings_path_for_style( string $path ): ?array {
		if ( isset( self::SETTINGS_EXCEPTIONS[ $path ] ) ) {
			return self::SETTINGS_EXCEPTIONS[ $path ];
		}

		$parts = explode( '.', $path );
		if ( 'spacing' === $parts[0] && isset( $parts[1] ) && in_array( $parts[1], array( 'margin', 'padding', 'blockGap' ), true ) ) {
			return array( 'spacing', $parts[1] );
		}

		if ( count( $parts ) >= 2 ) {
			return array( $parts[0], $parts[1] );
		}

		return null;
	}

	private static function check_block_supports( array $pairs, string $block_name, array &$errors ): void {
		$registry   = WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( $block_name );

		if ( ! $block_type ) {
			$errors[] = array(
				'property' => '',
				'value'    => $block_name,
				'message'  => sprintf( 'Block "%s" is not registered.', $block_name ),
				'layer'    => 'block-supports',
			);
			return;
		}

		foreach ( $pairs as $path => $value ) {
			$support_path = self::get_block_support_path( $path );
			if ( null === $support_path ) {
				continue;
			}

			$parent = array( $support_path[0] );
			if ( ! block_has_support( $block_type, $parent, false ) ) {
				$errors[] = array(
					'property' => $path,
					'value'    => $value,
					'message'  => sprintf( 'Block "%s" does not support "%s".', $block_name, $support_path[0] ),
					'layer'    => 'block-supports',
				);
				continue;
			}

			if ( count( $support_path ) > 1 && ! block_has_support( $block_type, $support_path, true ) ) {
				$errors[] = array(
					'property' => $path,
					'value'    => $value,
					'message'  => sprintf( 'Block "%s" does not support "%s".', $block_name, $path ),
					'layer'    => 'block-supports',
				);
			}
		}
	}

	private static function get_block_support_path( string $path ): ?array {
		if ( isset( self::BLOCK_SUPPORT_MAP[ $path ] ) ) {
			return self::BLOCK_SUPPORT_MAP[ $path ];
		}

		$parts = explode( '.', $path );

		if ( count( $parts ) === 1 ) {
			return $parts;
		}

		if ( 'spacing' === $parts[0] && count( $parts ) === 3 ) {
			return array( $parts[0], $parts[1] );
		}

		return array_slice( $parts, 0, 2 );
	}

	private static function suggest_presets( array $pairs, array &$warnings ): void {
		$settings = self::get_merged_settings();

		foreach ( $pairs as $path => $value ) {
			if ( self::is_preset_reference( $value ) ) {
				continue;
			}

			$category = explode( '.', $path )[0];

			if ( 'color' === $category ) {
				$palette = self::flatten_presets( $settings['color']['palette'] ?? array() );
				$match   = self::find_closest_color( $value, $palette );
				if ( $match ) {
					$warnings[] = array(
						'property'   => $path,
						'value'      => $value,
						'message'    => 'Color not in palette but a close preset exists.',
						'suggestion' => 'var(--wp--preset--color--' . $match['slug'] . ')',
						'layer'      => 'preset-suggestion',
					);
				}
			} elseif ( 'typography' === $category ) {
				$property = explode( '.', $path )[1] ?? '';
				if ( 'fontSize' === $property ) {
					$font_sizes = self::flatten_presets( $settings['typography']['fontSizes'] ?? array() );
					$match      = self::find_preset_by_value( $value, $font_sizes, 'size' );
					if ( $match ) {
						$warnings[] = array(
							'property'   => $path,
							'value'      => $value,
							'message'    => 'Font size matches a preset. Use the preset reference instead.',
							'suggestion' => 'var(--wp--preset--font-size--' . $match['slug'] . ')',
							'layer'      => 'preset-suggestion',
						);
					}
				} elseif ( 'fontFamily' === $property ) {
					$families = self::flatten_presets( $settings['typography']['fontFamilies'] ?? array() );
					$match    = self::find_preset_by_value( $value, $families, 'fontFamily' );
					if ( $match ) {
						$warnings[] = array(
							'property'   => $path,
							'value'      => $value,
							'message'    => 'Font family matches a preset. Use the preset reference instead.',
							'suggestion' => 'var(--wp--preset--font-family--' . $match['slug'] . ')',
							'layer'      => 'preset-suggestion',
						);
					}
				}
			} elseif ( 'spacing' === $category ) {
				$spacing_sizes = self::flatten_presets( $settings['spacing']['spacingSizes'] ?? array() );
				$match         = self::find_preset_by_value( $value, $spacing_sizes, 'size' );
				if ( $match ) {
					$warnings[] = array(
						'property'   => $path,
						'value'      => $value,
						'message'    => 'Spacing matches a preset. Use the preset reference instead.',
						'suggestion' => 'var(--wp--preset--spacing--' . $match['slug'] . ')',
						'layer'      => 'preset-suggestion',
					);
				}
			}
		}
	}

	// --- Helpers ---

	private static function get_merged_settings(): array {
		if ( null !== self::$test_settings ) {
			return self::$test_settings;
		}
		return WP_Theme_JSON_Resolver::get_merged_data()->get_settings();
	}

	private static function is_preset_reference( string $value ): bool {
		return str_starts_with( $value, 'var(--wp--preset--' );
	}

	private static function array_get( array $array, array $path ) {
		$current = $array;
		foreach ( $path as $key ) {
			if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
				return null;
			}
			$current = $current[ $key ];
		}
		return $current;
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

	private static function find_preset_by_value( string $value, array $presets, string $value_key ): ?array {
		foreach ( $presets as $preset ) {
			if ( isset( $preset[ $value_key ] ) && (string) $preset[ $value_key ] === $value ) {
				return $preset;
			}
		}
		return null;
	}

	private static function find_closest_color( string $hex_color, array $palette ): ?array {
		$rgb = self::hex_to_rgb( $hex_color );
		if ( ! $rgb ) {
			return null;
		}

		$closest          = null;
		$closest_distance = PHP_FLOAT_MAX;
		$threshold        = 50.0;

		foreach ( $palette as $preset ) {
			$preset_rgb = self::hex_to_rgb( $preset['color'] ?? '' );
			if ( ! $preset_rgb ) {
				continue;
			}

			$distance = sqrt(
				pow( $rgb[0] - $preset_rgb[0], 2 ) +
				pow( $rgb[1] - $preset_rgb[1], 2 ) +
				pow( $rgb[2] - $preset_rgb[2], 2 )
			);

			if ( $distance < $closest_distance && $distance <= $threshold ) {
				$closest          = $preset;
				$closest_distance = $distance;
			}
		}

		return $closest;
	}

	private static function hex_to_rgb( string $hex ): ?array {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) {
			return null;
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}
}
