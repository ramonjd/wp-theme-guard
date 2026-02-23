<?php

declare( strict_types = 1 );

class WP_Theme_Guard_Schema_Provider {

	public static function execute( array $input ): array {
		$include = $input['include'] ?? array( 'styles', 'blocks', 'layout', 'structure' );
		$result  = array();

		if ( in_array( 'structure', $include, true ) ) {
			$result['structure'] = self::get_structure_guide();
		}

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

	private static function get_structure_guide(): array {
		return array(
			'description'      => 'How to structure style objects for theme.json. Styles can target globally, per-block, per-element, or per-element within a block.',
			'targeting'        => array(
				'global'        => 'Top-level style properties apply site-wide.',
				'block'         => 'styles.blocks.<blockName> targets a specific block type.',
				'element'       => 'styles.elements.<element> targets an HTML element site-wide.',
				'block_element' => 'styles.blocks.<blockName>.elements.<element> targets an element within a specific block.',
				'valid_elements' => array( 'button', 'caption', 'cite', 'heading', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'link' ),
			),
			'complete_example' => array(
				'description' => 'A complete styles object showing global, block, and element targeting together. This is what the final output should look like.',
				'styles'      => array(
					'color'      => array(
						'text' => 'var(--wp--preset--color--contrast)',
					),
					'typography' => array(
						'fontSize'   => 'var(--wp--preset--font-size--medium)',
						'lineHeight' => '1.6',
					),
					'css'        => '.wp-site-blocks { scroll-margin-top: 100px; }',
					'elements'   => array(
						'button' => array(
							'color' => array(
								'background' => 'var(--wp--preset--color--primary)',
								'text'       => 'var(--wp--preset--color--base)',
							),
						),
						'link'   => array(
							'color' => array(
								'text' => 'var(--wp--preset--color--primary)',
							),
						),
					),
					'blocks'     => array(
						'core/heading' => array(
							'color'      => array(
								'text' => 'var(--wp--preset--color--primary)',
							),
							'typography' => array(
								'fontWeight' => 'bold',
							),
						),
						'core/button'  => array(
							'border' => array(
								'radius' => '4px',
							),
						),
						'core/group'   => array(
							'spacing'  => array(
								'padding' => array(
									'top'    => 'var(--wp--preset--spacing--50)',
									'bottom' => 'var(--wp--preset--spacing--50)',
								),
							),
							'css'      => '& .custom-layout { display: grid; gap: 1rem; }',
							'elements' => array(
								'link' => array(
									'color' => array(
										'text' => 'var(--wp--preset--color--secondary)',
									),
								),
							),
						),
					),
				),
			),
			'common_aliases'   => array(
				'description' => 'Users often refer to blocks by casual names. Always map to the correct core/* block name when targeting styles.',
				'mappings'    => array(
					'button'     => 'core/button',
					'buttons'    => 'core/buttons',
					'container'  => 'core/group',
					'group'      => 'core/group',
					'section'    => 'core/group',
					'wrapper'    => 'core/group',
					'image'      => 'core/image',
					'paragraph'  => 'core/paragraph',
					'text'       => 'core/paragraph',
					'heading'    => 'core/heading',
					'list'       => 'core/list',
					'quote'      => 'core/quote',
					'columns'    => 'core/columns',
					'column'     => 'core/column',
					'separator'  => 'core/separator',
					'spacer'     => 'core/spacer',
					'cover'      => 'core/cover',
					'navigation' => 'core/navigation',
					'table'      => 'core/table',
					'code'       => 'core/code',
				),
			),
			'style_properties' => array(
				'color'      => array( 'text', 'background', 'gradient' ),
				'typography' => array( 'fontSize', 'fontFamily', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing', 'textDecoration', 'textTransform', 'writingMode' ),
				'spacing'    => array( 'padding (top/right/bottom/left)', 'margin (top/right/bottom/left)', 'blockGap' ),
				'border'     => array( 'color', 'width', 'style', 'radius', 'top', 'right', 'bottom', 'left' ),
				'outline'    => array( 'color', 'width', 'style', 'offset' ),
				'dimensions' => array( 'minHeight', 'aspectRatio' ),
				'shadow'     => 'preset slug or custom CSS shadow value',
				'css'        => 'Raw CSS string for styling not covered by other properties. Supports & nesting syntax.',
			),
			'css_property'     => array(
				'description'    => 'The css property is an escape hatch for styling beyond declarative theme.json properties. It accepts a raw CSS string and is not subject to theme preset constraints.',
				'placement'      => 'Can appear at global (styles.css), block (styles.blocks.<blockName>.css), or element (styles.elements.<element>.css) level.',
				'nesting_syntax' => 'Use & to reference the current selector: "& .inner { color: red; }" or "& > p { margin: 0; }". Root-level rules (without &) apply directly to the target.',
				'example_global' => 'styles.css = ".wp-site-blocks { scroll-margin-top: 100px; }"',
				'example_block'  => 'styles.blocks.core/group.css = "& .custom-layout { display: grid; gap: 1rem; }"',
			),
			'values'           => array(
				'presets'    => 'var(--wp--preset--<category>--<slug>) — preferred for design system consistency',
				'custom_css' => 'Raw CSS values (e.g. #ff6600, 18px, 2rem, bold) — when theme permits',
			),
			'schema_reference' => 'https://schemas.wp.org/trunk/theme.json',
		);
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
