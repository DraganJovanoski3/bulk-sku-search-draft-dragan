<?php
/**
 * Parse SKU lists from textarea input.
 */

defined( 'ABSPATH' ) || exit;

class BSSDD_SKU_Parser {

	/**
	 * Normalize a SKU value for comparison.
	 *
	 * @param string $sku Raw SKU.
	 * @return string
	 */
	public static function normalize_sku( $sku ) {
		return strtolower( trim( (string) $sku ) );
	}

	/**
	 * Parse textarea input into a deduplicated SKU list.
	 *
	 * @param string $raw Raw textarea content.
	 * @return array{skus: string[], normalized: string[], count: int}|WP_Error
	 */
	public static function parse( $raw ) {
		$raw = sanitize_textarea_field( (string) $raw );

		if ( '' === trim( $raw ) ) {
			return new WP_Error(
				'bssdd_empty_input',
				__( 'Please enter at least one SKU.', 'bulk-sku-search-draft-dragan' )
			);
		}

		$lines = preg_split( '/[\r\n,]+/', $raw );
		if ( ! is_array( $lines ) ) {
			return new WP_Error(
				'bssdd_parse_failed',
				__( 'Could not parse the SKU input.', 'bulk-sku-search-draft-dragan' )
			);
		}

		$skus       = array();
		$normalized = array();
		$seen       = array();

		foreach ( $lines as $line ) {
			$sku = trim( (string) $line );
			if ( '' === $sku ) {
				continue;
			}

			$key = self::normalize_sku( $sku );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$skus[]       = $sku;
			$normalized[] = $key;
		}

		if ( empty( $skus ) ) {
			return new WP_Error(
				'bssdd_no_valid_skus',
				__( 'No valid SKUs found in the input.', 'bulk-sku-search-draft-dragan' )
			);
		}

		if ( count( $skus ) > BSSDD_MAX_SKUS ) {
			return new WP_Error(
				'bssdd_too_many_skus',
				sprintf(
					/* translators: %d: maximum SKU count */
					__( 'Too many SKUs. Maximum allowed is %d.', 'bulk-sku-search-draft-dragan' ),
					(int) BSSDD_MAX_SKUS
				)
			);
		}

		return array(
			'skus'       => $skus,
			'normalized' => $normalized,
			'count'      => count( $skus ),
		);
	}
}
