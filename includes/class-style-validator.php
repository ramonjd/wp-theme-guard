<?php

declare( strict_types = 1 );

class WP_Theme_Guard_Style_Validator {

	public static function execute( array $input ): array {
		$styles   = $input['styles'] ?? array();
		$errors   = array();
		$warnings = array();

		$settings = self::get_merged_settings();

		if ( isset( $styles['color'] ) ) {
			self::validate_colors( $styles['color'], $settings, $errors, $warnings );
		}

		if ( isset( $styles['typography'] ) ) {
			self::validate_typography( $styles['typography'], $settings, $errors, $warnings );
		}

		if ( isset( $styles['spacing'] ) ) {
			self::validate_spacing( $styles['spacing'], $settings, $errors, $warnings );
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	private static function get_merged_settings(): array {
		$theme_json = WP_Theme_JSON_Resolver::get_merged_data();
		return $theme_json->get_settings();
	}

	private static function validate_colors( array $colors, array $settings, array &$errors, array &$warnings ): void {
		$palette        = self::flatten_presets( $settings['color']['palette'] ?? array() );
		$custom_allowed = $settings['color']['custom'] ?? true;

		foreach ( $colors as $property => $value ) {
			if ( self::is_preset_reference( $value, 'color' ) ) {
				continue;
			}

			$match = self::find_closest_color( $value, $palette );

			if ( $match ) {
				$warnings[] = array(
					'property'   => 'color.' . $property,
					'value'      => $value,
					'message'    => 'Color not in palette but a close preset exists.',
					'suggestion' => 'var(--wp--preset--color--' . $match['slug'] . ')',
				);
			} elseif ( ! $custom_allowed ) {
				$errors[] = array(
					'property' => 'color.' . $property,
					'value'    => $value,
					'message'  => 'Custom colors are not allowed by the theme.',
				);
			}
		}
	}

	private static function validate_typography( array $typography, array $settings, array &$errors, array &$warnings ): void {
		if ( isset( $typography['fontSize'] ) ) {
			self::validate_font_size( $typography['fontSize'], $settings, $errors, $warnings );
		}

		if ( isset( $typography['fontFamily'] ) ) {
			self::validate_font_family( $typography['fontFamily'], $settings, $errors, $warnings );
		}
	}

	private static function validate_font_size( string $value, array $settings, array &$errors, array &$warnings ): void {
		if ( self::is_preset_reference( $value, 'font-size' ) ) {
			return;
		}

		$font_sizes     = self::flatten_presets( $settings['typography']['fontSizes'] ?? array() );
		$custom_allowed = $settings['typography']['customFontSize'] ?? true;

		$match = self::find_preset_by_value( $value, $font_sizes, 'size' );

		if ( $match ) {
			$warnings[] = array(
				'property'   => 'typography.fontSize',
				'value'      => $value,
				'message'    => 'Font size matches a preset. Use the preset reference instead.',
				'suggestion' => 'var(--wp--preset--font-size--' . $match['slug'] . ')',
			);
		} elseif ( ! $custom_allowed ) {
			$errors[] = array(
				'property' => 'typography.fontSize',
				'value'    => $value,
				'message'  => 'Custom font sizes are not allowed by the theme.',
			);
		}
	}

	private static function validate_font_family( string $value, array $settings, array &$errors, array &$warnings ): void {
		if ( self::is_preset_reference( $value, 'font-family' ) ) {
			return;
		}

		$font_families = self::flatten_presets( $settings['typography']['fontFamilies'] ?? array() );

		$match = self::find_preset_by_value( $value, $font_families, 'fontFamily' );

		if ( $match ) {
			$warnings[] = array(
				'property'   => 'typography.fontFamily',
				'value'      => $value,
				'message'    => 'Font family matches a preset. Use the preset reference instead.',
				'suggestion' => 'var(--wp--preset--font-family--' . $match['slug'] . ')',
			);
		} else {
			$errors[] = array(
				'property' => 'typography.fontFamily',
				'value'    => $value,
				'message'  => 'Font family not in theme presets.',
			);
		}
	}

	private static function validate_spacing( array $spacing, array $settings, array &$errors, array &$warnings ): void {
		$spacing_sizes  = self::flatten_presets( $settings['spacing']['spacingSizes'] ?? array() );
		$custom_allowed = $settings['spacing']['customSpacingSize'] ?? true;
		$allowed_units  = $settings['spacing']['units'] ?? array( 'px', 'em', 'rem', 'vh', 'vw', '%' );

		foreach ( $spacing as $property => $sides ) {
			if ( ! is_array( $sides ) ) {
				$sides = array( '' => $sides );
			}

			foreach ( $sides as $side => $value ) {
				$prop_path = $side ? "spacing.{$property}.{$side}" : "spacing.{$property}";

				if ( self::is_preset_reference( $value, 'spacing' ) ) {
					continue;
				}

				$unit = self::extract_unit( $value );
				if ( $unit && ! in_array( $unit, $allowed_units, true ) ) {
					$errors[] = array(
						'property' => $prop_path,
						'value'    => $value,
						'message'  => sprintf( 'Unit "%s" is not allowed. Allowed units: %s.', $unit, implode( ', ', $allowed_units ) ),
					);
					continue;
				}

				$match = self::find_preset_by_value( $value, $spacing_sizes, 'size' );

				if ( $match ) {
					$warnings[] = array(
						'property'   => $prop_path,
						'value'      => $value,
						'message'    => 'Spacing matches a preset. Use the preset reference instead.',
						'suggestion' => 'var(--wp--preset--spacing--' . $match['slug'] . ')',
					);
				} elseif ( ! $custom_allowed ) {
					$errors[] = array(
						'property' => $prop_path,
						'value'    => $value,
						'message'  => 'Custom spacing sizes are not allowed by the theme.',
					);
				}
			}
		}
	}

	private static function is_preset_reference( string $value, string $preset_type ): bool {
		return str_starts_with( $value, "var(--wp--preset--{$preset_type}--" );
	}

	private static function flatten_presets( array $presets ): array {
		$flat = array();
		foreach ( $presets as $key => $value ) {
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

	private static function extract_unit( string $value ): ?string {
		if ( preg_match( '/^[\d.]+([a-z%]+)$/i', $value, $matches ) ) {
			return $matches[1];
		}
		return null;
	}
}
