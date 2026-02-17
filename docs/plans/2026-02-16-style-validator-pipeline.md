# Style Validator Pipeline Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Restructure validate-styles as a layered pipeline that checks property validity, value safety, theme settings, block supports, and preset suggestions.

**Architecture:** The validator flattens incoming styles into `(path, value)` pairs, then runs them through 5 independent check layers. Each layer appends to shared `$errors`/`$warnings` arrays with a `layer` tag. Core constants (`WP_Theme_JSON::VALID_STYLES`, `PROPERTIES_METADATA`, `safecss_filter_attr()`, `block_has_support()`) do the heavy lifting.

**Tech Stack:** PHP 8.1, WordPress trunk (7.0-alpha), PHPUnit 9.6, wp-env

---

### Task 1: Normalize layer + property validity layer

Rewrite the validator entry point. Replace the old per-category methods with a normalize step that flattens `styles` into `(path, value)` pairs, then a property validity check against `WP_Theme_JSON::VALID_STYLES`.

**Files:**
- Modify: `includes/class-style-validator.php` (full rewrite)
- Modify: `tests/Test_Style_Validator.php` (replace existing tests)

**Step 1: Write the failing tests**

Replace the contents of `tests/Test_Style_Validator.php` with:

```php
<?php

class Test_Style_Validator extends WP_UnitTestCase {

	/**
	 * Layer 2: property-validity
	 */
	public function test_valid_property_paths_pass() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color'      => array( 'background' => '#000000' ),
				'typography' => array( 'fontSize' => '16px' ),
				'spacing'    => array( 'padding' => array( 'top' => '10px' ) ),
				'border'     => array( 'color' => '#333333' ),
				'shadow'     => '0 1px 2px rgba(0,0,0,.1)',
				'dimensions' => array( 'minHeight' => '100px' ),
			),
		) );

		// No property-validity errors expected.
		$property_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'property-validity' === $e['layer']
		);
		$this->assertEmpty( $property_errors );
	}

	public function test_invalid_property_paths_produce_errors() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'opacity' => '0.5' ),
				'fake'  => array( 'whatever' => 'value' ),
			),
		) );

		$this->assertFalse( $result['valid'] );

		$property_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'property-validity' === $e['layer']
		);
		$this->assertCount( 2, $property_errors );
	}

	public function test_nested_border_sub_properties_validated() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'border' => array(
					'top' => array( 'color' => '#333333' ),
				),
			),
		) );

		$property_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'property-validity' === $e['layer']
		);
		$this->assertEmpty( $property_errors );
	}
}
```

**Step 2: Run tests to verify they fail**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Style_Validator`
Expected: FAIL — no `layer` key in results yet.

**Step 3: Rewrite the validator with normalize + property validity**

Replace the contents of `includes/class-style-validator.php` with:

```php
<?php

declare( strict_types = 1 );

class WP_Theme_Guard_Style_Validator {

	/**
	 * Map from style paths to their settings toggle paths.
	 * Only entries that don't follow the default convention (category.property → settings.category.property).
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

	/**
	 * Layer 1: Flatten nested styles into dot-separated (path => value) pairs.
	 *
	 * @param array  $styles Nested styles array.
	 * @param string $prefix Current path prefix.
	 * @return array<string, string> Flat path => value pairs.
	 */
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

	/**
	 * Layer 2: Validate property paths against WP_Theme_JSON::VALID_STYLES.
	 *
	 * @param array<string, string> $pairs  Flat path => value pairs.
	 * @param array                 $errors Errors array (by reference).
	 * @return array<string, string> Only the pairs with valid paths.
	 */
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

	/**
	 * Check if a dot-separated path exists in the VALID_STYLES schema.
	 *
	 * @param string $path   Dot-separated property path (e.g. "color.background").
	 * @param array  $schema The VALID_STYLES schema (or a sub-tree).
	 * @return bool
	 */
	private static function is_valid_style_path( string $path, array $schema ): bool {
		$parts   = explode( '.', $path );
		$current = $schema;

		foreach ( $parts as $part ) {
			if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) ) {
				return false;
			}
			$current = $current[ $part ];
		}

		return true;
	}

	/**
	 * Layer 3: Validate values via safecss_filter_attr().
	 *
	 * @param array<string, string> $pairs  Valid path => value pairs.
	 * @param array                 $errors Errors array (by reference).
	 * @return array<string, string> Only the pairs with safe values.
	 */
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

	/**
	 * Build a map from style paths to CSS property names using PROPERTIES_METADATA.
	 *
	 * @return array<string, string> style path => CSS property name.
	 */
	private static function build_path_to_css_map(): array {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}

		$map = array();
		foreach ( WP_Theme_JSON::PROPERTIES_METADATA as $css_prop => $style_path ) {
			// Skip root padding custom properties.
			if ( str_starts_with( $css_prop, '--wp--style--root--' ) ) {
				continue;
			}
			$key = implode( '.', $style_path );
			// First match wins (shortest/most canonical CSS property).
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = $css_prop;
			}
		}
		return $map;
	}

	/**
	 * Fallback: convert a style path to a plausible CSS property name.
	 * e.g. "background.backgroundImage" → "background-image"
	 *
	 * @param string $path Style path.
	 * @return string CSS property name.
	 */
	private static function fallback_css_property( string $path ): string {
		$parts    = explode( '.', $path );
		$last     = end( $parts );
		$kebab    = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $last ) );
		return $kebab;
	}

	/**
	 * Layer 4: Check properties against merged theme.json settings.
	 *
	 * @param array<string, string> $pairs  Safe path => value pairs.
	 * @param array                 $errors Errors array (by reference).
	 */
	private static function check_theme_settings( array $pairs, array &$errors ): void {
		$settings = self::get_merged_settings();

		foreach ( $pairs as $path => $value ) {
			$setting_path = self::get_settings_path_for_style( $path );
			if ( null === $setting_path ) {
				continue; // No corresponding setting — always allowed.
			}

			$setting_value = self::array_get( $settings, $setting_path );

			// Explicit false means the property is disabled.
			if ( false === $setting_value ) {
				$errors[] = array(
					'property' => $path,
					'value'    => $value,
					'message'  => sprintf( '"%s" is disabled by the theme.', $path ),
					'layer'    => 'theme-settings',
				);
				continue;
			}

			// Check custom-value toggles: property is enabled but custom values may not be.
			if ( self::is_preset_reference( $value ) ) {
				continue; // Preset references are always OK if the property is enabled.
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

	/**
	 * Get the settings toggle path for a given style path.
	 *
	 * @param string $path Style path (e.g. "color.text").
	 * @return array|null Settings path as array, or null if no toggle exists.
	 */
	private static function get_settings_path_for_style( string $path ): ?array {
		// Check explicit exceptions first.
		if ( isset( self::SETTINGS_EXCEPTIONS[ $path ] ) ) {
			return self::SETTINGS_EXCEPTIONS[ $path ];
		}

		// Spacing sub-properties (margin, padding, blockGap) have their own toggles.
		// e.g. spacing.padding.top → settings.spacing.padding
		$parts = explode( '.', $path );
		if ( 'spacing' === $parts[0] && isset( $parts[1] ) && in_array( $parts[1], array( 'margin', 'padding', 'blockGap' ), true ) ) {
			return array( 'spacing', $parts[1] );
		}

		// Default convention: style path maps directly to settings path.
		// e.g. color.text → settings.color.text
		// e.g. typography.lineHeight → settings.typography.lineHeight
		if ( count( $parts ) >= 2 ) {
			return array( $parts[0], $parts[1] );
		}

		return null;
	}

	/**
	 * Layer 5: Check properties against block supports.
	 *
	 * @param array<string, string> $pairs      Safe path => value pairs.
	 * @param string                $block_name Block name (e.g. "core/paragraph").
	 * @param array                 $errors     Errors array (by reference).
	 */
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

			// Two-step check: parent must exist, then sub-property defaults to true.
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

			// If there's a sub-property, check it (defaults to true when parent exists).
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

	/**
	 * Get the block support path for a given style path.
	 *
	 * @param string $path Style path.
	 * @return array|null Support path as array, or null if no mapping.
	 */
	private static function get_block_support_path( string $path ): ?array {
		if ( isset( self::BLOCK_SUPPORT_MAP[ $path ] ) ) {
			return self::BLOCK_SUPPORT_MAP[ $path ];
		}

		// Default: style path maps to support path.
		// e.g. color.text → ['color', 'text']
		// e.g. spacing.padding → ['spacing', 'padding']
		$parts = explode( '.', $path );

		// Top-level properties like "shadow".
		if ( count( $parts ) === 1 ) {
			return $parts;
		}

		// Spacing sub-sides (padding.top) → check the parent (spacing.padding).
		if ( 'spacing' === $parts[0] && count( $parts ) === 3 ) {
			return array( $parts[0], $parts[1] );
		}

		return array_slice( $parts, 0, 2 );
	}

	/**
	 * Layer 6: Suggest preset references for values that are close to presets.
	 *
	 * @param array<string, string> $pairs    Safe path => value pairs.
	 * @param array                 $warnings Warnings array (by reference).
	 */
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
		static $settings = null;
		if ( null === $settings ) {
			$settings = WP_Theme_JSON_Resolver::get_merged_data()->get_settings();
		}
		return $settings;
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
```

**Step 4: Run tests to verify they pass**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Style_Validator`
Expected: 3 tests, all PASS.

**Step 5: Commit**

```bash
git add includes/class-style-validator.php tests/Test_Style_Validator.php
git commit -m "refactor: rewrite style validator as layered pipeline with property validity"
```

---

### Task 2: Value sanitization tests

Add tests for Layer 3 (safecss_filter_attr gate).

**Files:**
- Modify: `tests/Test_Style_Validator.php` (add test methods)

**Step 1: Write the failing tests**

Append to the `Test_Style_Validator` class:

```php
	/**
	 * Layer 3: value-sanitization
	 */
	public function test_safe_css_values_pass() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color'      => array( 'background' => '#ff0000' ),
				'typography' => array( 'fontSize' => 'clamp(1rem, 2vw, 2rem)' ),
				'spacing'    => array( 'padding' => array( 'top' => '10px' ) ),
			),
		) );

		$sanitization_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'value-sanitization' === $e['layer']
		);
		$this->assertEmpty( $sanitization_errors );
	}

	public function test_unsafe_css_values_produce_errors() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array(
					'background' => 'expression(alert(1))',
					'text'       => 'var(--wp--preset--color--black)',
				),
			),
		) );

		$sanitization_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'value-sanitization' === $e['layer']
		);
		$this->assertCount( 1, $sanitization_errors );

		$error = array_values( $sanitization_errors )[0];
		$this->assertSame( 'color.background', $error['property'] );
	}

	public function test_empty_values_produce_errors() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => '' ),
			),
		) );

		$sanitization_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'value-sanitization' === $e['layer']
		);
		$this->assertCount( 1, $sanitization_errors );
	}

	public function test_preset_references_always_pass_sanitization() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color'      => array(
					'background' => 'var(--wp--preset--color--vivid-red)',
					'text'       => 'var(--wp--preset--color--white)',
				),
				'typography' => array(
					'fontSize' => 'var(--wp--preset--font-size--medium)',
				),
			),
		) );

		$sanitization_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'value-sanitization' === $e['layer']
		);
		$this->assertEmpty( $sanitization_errors );
	}
```

**Step 2: Run tests**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Style_Validator`
Expected: 7 tests, all PASS (the implementation from Task 1 already includes Layer 3).

**Step 3: Commit**

```bash
git add tests/Test_Style_Validator.php
git commit -m "test: add value sanitization tests for style validator"
```

---

### Task 3: Theme settings gate tests

Add tests for Layer 4. These tests need to override theme.json settings to simulate a theme that disables specific properties.

**Files:**
- Modify: `tests/Test_Style_Validator.php` (add test methods)
- Modify: `includes/class-style-validator.php` (minor: make settings injectable for testing)

**Step 1: Write the failing tests**

The issue: `get_merged_settings()` caches a static result from the live theme. For testing, we need a way to inject settings. Add a static method `set_test_settings()` to the validator.

Add this to `class-style-validator.php` after the `get_merged_settings` method:

```php
	/**
	 * Override settings for testing. Pass null to reset.
	 *
	 * @param array|null $override Settings array or null to clear.
	 */
	public static function set_test_settings( ?array $override ): void {
		static $original = null;
		// Store original on first call, restore on null.
		if ( null === $override ) {
			// Reset the static cache by re-fetching.
			$ref = new ReflectionMethod( self::class, 'get_merged_settings' );
			// The static variable can't be reset from outside, so we use a flag.
			self::$test_settings = null;
			return;
		}
		self::$test_settings = $override;
	}

	private static ?array $test_settings = null;
```

And update `get_merged_settings()`:

```php
	private static function get_merged_settings(): array {
		if ( null !== self::$test_settings ) {
			return self::$test_settings;
		}
		return WP_Theme_JSON_Resolver::get_merged_data()->get_settings();
	}
```

Remove the static cache variable from the original `get_merged_settings` (the `static $settings = null` pattern) since test overrides need to take effect immediately.

Then append these tests:

```php
	/**
	 * Layer 4: theme-settings
	 */
	public function test_disabled_setting_produces_error() {
		WP_Theme_Guard_Style_Validator::set_test_settings( array(
			'color' => array(
				'text'   => false,
				'custom' => true,
			),
		) );

		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'text' => '#333333' ),
			),
		) );

		WP_Theme_Guard_Style_Validator::set_test_settings( null );

		$settings_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'theme-settings' === $e['layer']
		);
		$this->assertCount( 1, $settings_errors );
	}

	public function test_enabled_setting_passes() {
		WP_Theme_Guard_Style_Validator::set_test_settings( array(
			'color' => array(
				'text'   => true,
				'custom' => true,
			),
		) );

		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'text' => '#333333' ),
			),
		) );

		WP_Theme_Guard_Style_Validator::set_test_settings( null );

		$settings_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'theme-settings' === $e['layer']
		);
		$this->assertEmpty( $settings_errors );
	}

	public function test_custom_values_blocked_but_presets_allowed() {
		WP_Theme_Guard_Style_Validator::set_test_settings( array(
			'color' => array(
				'background' => true,
				'custom'     => false,
			),
		) );

		$result_custom = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => '#ff0000' ),
			),
		) );

		$result_preset = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => 'var(--wp--preset--color--vivid-red)' ),
			),
		) );

		WP_Theme_Guard_Style_Validator::set_test_settings( null );

		// Custom value should error.
		$custom_errors = array_filter(
			$result_custom['errors'],
			fn( $e ) => 'theme-settings' === $e['layer']
		);
		$this->assertCount( 1, $custom_errors );

		// Preset reference should pass.
		$preset_errors = array_filter(
			$result_preset['errors'],
			fn( $e ) => 'theme-settings' === $e['layer']
		);
		$this->assertEmpty( $preset_errors );
	}

	public function test_custom_font_size_blocked() {
		WP_Theme_Guard_Style_Validator::set_test_settings( array(
			'typography' => array(
				'customFontSize' => false,
			),
		) );

		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'typography' => array( 'fontSize' => '16px' ),
			),
		) );

		WP_Theme_Guard_Style_Validator::set_test_settings( null );

		$settings_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'theme-settings' === $e['layer']
		);
		$this->assertCount( 1, $settings_errors );
	}
```

**Step 2: Run tests to verify they fail**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Style_Validator`
Expected: Some FAIL because `set_test_settings` and `$test_settings` don't exist yet.

**Step 3: Implement the test settings override**

Apply the changes to `includes/class-style-validator.php` described above:
1. Add `private static ?array $test_settings = null;` property.
2. Add `set_test_settings()` method.
3. Update `get_merged_settings()` to check `self::$test_settings` first and remove static cache.

**Step 4: Run tests to verify they pass**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Style_Validator`
Expected: 11 tests, all PASS.

**Step 5: Commit**

```bash
git add includes/class-style-validator.php tests/Test_Style_Validator.php
git commit -m "feat: add theme settings gate with test overrides"
```

---

### Task 4: Block supports gate tests

Add tests for Layer 5 (optional, when `blockName` is provided).

**Files:**
- Modify: `tests/Test_Style_Validator.php` (add test methods)
- Modify: `includes/class-abilities.php` (add `blockName` to input schema)

**Step 1: Write the failing tests**

Append to `Test_Style_Validator`:

```php
	/**
	 * Layer 5: block-supports
	 */
	public function test_supported_block_style_passes() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles'    => array(
				'color' => array( 'text' => 'var(--wp--preset--color--black)' ),
			),
			'blockName' => 'core/paragraph',
		) );

		$support_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'block-supports' === $e['layer']
		);
		$this->assertEmpty( $support_errors );
	}

	public function test_unsupported_block_style_produces_error() {
		// core/image has color.text explicitly set to false.
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles'    => array(
				'color' => array( 'text' => '#333333' ),
			),
			'blockName' => 'core/image',
		) );

		$support_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'block-supports' === $e['layer']
		);
		$this->assertCount( 1, $support_errors );
	}

	public function test_unregistered_block_produces_error() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles'    => array(
				'color' => array( 'background' => '#ff0000' ),
			),
			'blockName' => 'fake/nonexistent',
		) );

		$support_errors = array_filter(
			$result['errors'],
			fn( $e ) => 'block-supports' === $e['layer']
		);
		$this->assertCount( 1, $support_errors );
		$this->assertStringContainsString( 'not registered', $support_errors[0]['message'] ?? array_values( $support_errors )[0]['message'] );
	}

	public function test_block_shadow_support() {
		// core/group supports shadow, core/paragraph does not.
		$result_group = WP_Theme_Guard_Style_Validator::execute( array(
			'styles'    => array(
				'shadow' => '0 1px 2px #000',
			),
			'blockName' => 'core/group',
		) );

		$group_errors = array_filter(
			$result_group['errors'],
			fn( $e ) => 'block-supports' === $e['layer']
		);
		$this->assertEmpty( $group_errors );
	}
```

**Step 2: Run tests**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Style_Validator`
Expected: All PASS (implementation from Task 1 already includes Layer 5).

**Step 3: Update ability input schema**

In `includes/class-abilities.php`, update the `register_validate_styles()` method to add `blockName` to the input schema. Inside the `properties` array, after the `context` entry, add:

```php
					'blockName' => array(
						'type'        => 'string',
						'description' => 'Optional block name (e.g. "core/paragraph"). When provided, also checks block supports.',
					),
```

**Step 4: Run all tests**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit`
Expected: All tests pass (style validator + block validator + schema provider + integration).

**Step 5: Commit**

```bash
git add tests/Test_Style_Validator.php includes/class-abilities.php
git commit -m "feat: add block supports gate and blockName input schema"
```

---

### Task 5: Preset suggestion tests (carry forward)

Verify the existing preset suggestion logic works with the new pipeline and add the `layer` tag.

**Files:**
- Modify: `tests/Test_Style_Validator.php` (add test methods)

**Step 1: Write tests**

Append to `Test_Style_Validator`:

```php
	/**
	 * Layer 6: preset-suggestion
	 */
	public function test_close_color_suggests_preset() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => '#cc2e2e' ),
			),
		) );

		$suggestions = array_filter(
			$result['warnings'],
			fn( $w ) => 'preset-suggestion' === $w['layer']
		);
		$this->assertNotEmpty( $suggestions );
		$suggestion = array_values( $suggestions )[0];
		$this->assertStringContainsString( 'var(--wp--preset--color--', $suggestion['suggestion'] );
	}

	public function test_preset_reference_gets_no_suggestion() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'color' => array( 'background' => 'var(--wp--preset--color--vivid-red)' ),
			),
		) );

		$suggestions = array_filter(
			$result['warnings'],
			fn( $w ) => 'preset-suggestion' === $w['layer']
		);
		$this->assertEmpty( $suggestions );
	}

	public function test_matching_font_size_suggests_preset() {
		$result = WP_Theme_Guard_Style_Validator::execute( array(
			'styles' => array(
				'typography' => array( 'fontSize' => '13px' ),
			),
		) );

		$suggestions = array_filter(
			$result['warnings'],
			fn( $w ) => 'preset-suggestion' === $w['layer']
		);
		$this->assertNotEmpty( $suggestions );
		$this->assertStringContainsString( 'var(--wp--preset--font-size--', array_values( $suggestions )[0]['suggestion'] );
	}
```

**Step 2: Run tests**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit --filter Test_Style_Validator`
Expected: All PASS.

**Step 3: Commit**

```bash
git add tests/Test_Style_Validator.php
git commit -m "test: add preset suggestion tests for style validator pipeline"
```

---

### Task 6: Integration test updates

Update the existing integration test for validate-styles to work with the new output format.

**Files:**
- Modify: `tests/Test_Plugin_Integration.php`

**Step 1: Read current integration test**

Read `tests/Test_Plugin_Integration.php` and find the `test_validate_styles_end_to_end` method.

**Step 2: Update integration test**

The integration test calls `WP_Theme_Guard_Style_Validator::execute()` and checks the output. Update it to verify that `layer` keys are present in errors/warnings. The test should still pass with the same basic assertions (valid=true/false, errors/warnings present). Add an assertion that each error has a `layer` key.

**Step 3: Run all tests**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit`
Expected: All tests pass.

**Step 4: Commit**

```bash
git add tests/Test_Plugin_Integration.php
git commit -m "test: update integration tests for style validator pipeline output"
```

---

### Task 7: Final validation

Run the full test suite and verify all abilities work end-to-end via wp-cli.

**Files:** None (verification only)

**Step 1: Run full test suite**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit`
Expected: ~20 tests, all PASS.

**Step 2: Manual smoke test via wp-cli**

Run each of these and verify sensible output:

```bash
# Test property validity error
npx wp-env run cli -- wp eval "
\$ab = WP_Abilities_Registry::get_instance()->get_registered('wp-theme-guard/validate-styles');
echo json_encode(\$ab->execute(array(
    'styles' => array('fake' => array('invalid' => 'value')),
)), JSON_PRETTY_PRINT);
" --user=admin

# Test value sanitization error
npx wp-env run cli -- wp eval "
\$ab = WP_Abilities_Registry::get_instance()->get_registered('wp-theme-guard/validate-styles');
echo json_encode(\$ab->execute(array(
    'styles' => array('color' => array('background' => 'expression(alert(1))')),
)), JSON_PRETTY_PRINT);
" --user=admin

# Test block supports
npx wp-env run cli -- wp eval "
\$ab = WP_Abilities_Registry::get_instance()->get_registered('wp-theme-guard/validate-styles');
echo json_encode(\$ab->execute(array(
    'styles' => array('color' => array('text' => '#333')),
    'blockName' => 'core/image',
)), JSON_PRETTY_PRINT);
" --user=admin

# Test valid styles pass
npx wp-env run cli -- wp eval "
\$ab = WP_Abilities_Registry::get_instance()->get_registered('wp-theme-guard/validate-styles');
echo json_encode(\$ab->execute(array(
    'styles' => array('color' => array('background' => 'var(--wp--preset--color--black)')),
)), JSON_PRETTY_PRINT);
" --user=admin
```

**Step 3: Commit (if any fixes needed)**

```bash
git add -A
git commit -m "fix: address issues found in final validation"
```
