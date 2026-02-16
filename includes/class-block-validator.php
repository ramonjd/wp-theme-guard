<?php

declare( strict_types = 1 );

class WP_Theme_Guard_Block_Validator {

	public static function execute( array $input ): array {
		$content   = $input['content'] ?? '';
		$errors    = array();
		$warnings  = array();

		$blocks      = parse_blocks( $content );
		$block_count = 0;

		self::validate_blocks( $blocks, null, $errors, $warnings, $block_count );

		return array(
			'valid'       => empty( $errors ),
			'block_count' => $block_count,
			'errors'      => $errors,
			'warnings'    => $warnings,
		);
	}

	private static function validate_blocks(
		array $blocks,
		?string $parent_name,
		array &$errors,
		array &$warnings,
		int &$block_count,
		array $ancestors = array()
	): void {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			$block_count++;
			$name       = $block['blockName'];
			$block_type = $registry->get_registered( $name );

			if ( ! $block_type ) {
				$errors[] = array(
					'block'   => $name,
					'index'   => $block_count - 1,
					'type'    => 'unregistered_block',
					'message' => sprintf( 'Block type "%s" is not registered.', $name ),
				);
				continue;
			}

			if ( ! empty( $block_type->parent ) && $parent_name !== null ) {
				if ( ! in_array( $parent_name, $block_type->parent, true ) ) {
					$errors[] = array(
						'block'   => $name,
						'index'   => $block_count - 1,
						'type'    => 'invalid_parent',
						'message' => sprintf(
							'"%s" requires parent to be one of: %s. Found: "%s".',
							$name,
							implode( ', ', $block_type->parent ),
							$parent_name
						),
					);
				}
			} elseif ( ! empty( $block_type->parent ) && $parent_name === null ) {
				$errors[] = array(
					'block'   => $name,
					'index'   => $block_count - 1,
					'type'    => 'invalid_parent',
					'message' => sprintf(
						'"%s" requires parent to be one of: %s. Found at top level.',
						$name,
						implode( ', ', $block_type->parent )
					),
				);
			}

			if ( ! empty( $block_type->ancestor ) ) {
				$has_valid_ancestor = false;
				foreach ( $block_type->ancestor as $required_ancestor ) {
					if ( in_array( $required_ancestor, $ancestors, true ) ) {
						$has_valid_ancestor = true;
						break;
					}
				}
				if ( ! $has_valid_ancestor ) {
					$errors[] = array(
						'block'   => $name,
						'index'   => $block_count - 1,
						'type'    => 'invalid_ancestor',
						'message' => sprintf(
							'"%s" must be nested within one of: %s.',
							$name,
							implode( ', ', $block_type->ancestor )
						),
					);
				}
			}

			if ( $parent_name ) {
				$parent_type = $registry->get_registered( $parent_name );
				if ( $parent_type && ! empty( $parent_type->allowed_blocks ) && is_array( $parent_type->allowed_blocks ) ) {
					if ( ! in_array( $name, $parent_type->allowed_blocks, true ) ) {
						$errors[] = array(
							'block'   => $name,
							'index'   => $block_count - 1,
							'type'    => 'not_allowed_child',
							'message' => sprintf(
								'"%s" is not allowed as a child of "%s".',
								$name,
								$parent_name
							),
						);
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$new_ancestors = array_merge( $ancestors, array( $name ) );
				self::validate_blocks(
					$block['innerBlocks'],
					$name,
					$errors,
					$warnings,
					$block_count,
					$new_ancestors
				);
			}
		}
	}
}
