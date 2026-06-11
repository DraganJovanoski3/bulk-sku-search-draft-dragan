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
	 * Whether a SKU contains digits only.
	 *
	 * @param string $sku Raw SKU.
	 * @return bool
	 */
	public static function is_numeric_sku( $sku ) {
		$sku = trim( (string) $sku );

		return '' !== $sku && preg_match( '/^\d+$/', $sku );
	}

	/**
	 * Compare numeric SKUs ignoring a single leading-zero difference.
	 *
	 * @param string $left  First SKU.
	 * @param string $right Second SKU.
	 * @return bool
	 */
	public static function skus_equivalent( $left, $right ) {
		$left  = self::normalize_sku( $left );
		$right = self::normalize_sku( $right );

		if ( $left === $right ) {
			return true;
		}

		if ( ! self::is_numeric_sku( $left ) || ! self::is_numeric_sku( $right ) ) {
			return false;
		}

		return ltrim( $left, '0' ) === ltrim( $right, '0' );
	}

	/**
	 * Build lookup variants for numeric SKUs with or without a leading zero.
	 *
	 * @param string $sku Raw SKU.
	 * @return string[]
	 */
	public static function sku_lookup_variants( $sku ) {
		$sku = trim( (string) $sku );

		if ( '' === $sku ) {
			return array();
		}

		$variants = array( $sku );

		if ( ! self::is_numeric_sku( $sku ) ) {
			return $variants;
		}

		$core = ltrim( $sku, '0' );
		if ( '' === $core ) {
			$core = '0';
		}

		$variants[] = $core;

		if ( '0' !== $sku[0] ) {
			$variants[] = '0' . $sku;
		}

		return array_values( array_unique( $variants ) );
	}

	/**
	 * Deduplication key for parsed SKU lists.
	 *
	 * @param string $sku Raw SKU.
	 * @return string
	 */
	private static function dedupe_key( $sku ) {
		if ( self::is_numeric_sku( $sku ) ) {
			return 'num:' . ltrim( $sku, '0' );
		}

		return self::normalize_sku( $sku );
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

			$key = self::dedupe_key( $sku );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$skus[]       = $sku;
			$normalized[] = self::normalize_sku( $sku );
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
