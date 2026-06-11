<?php
/**
 * Update product SKU via WooCommerce API.
 */

defined( 'ABSPATH' ) || exit;

class BSSDD_SKU_Updater {

	/**
	 * Whether a SKU should get a leading zero prefix.
	 *
	 * @param string $sku SKU value.
	 * @return bool
	 */
	public static function needs_leading_zero( $sku ) {
		$sku = trim( (string) $sku );

		return BSSDD_SKU_Parser::is_numeric_sku( $sku ) && '0' !== $sku[0] && strlen( $sku ) < 100;
	}

	/**
	 * Prefix a SKU with a single leading zero.
	 *
	 * @param string $sku SKU value.
	 * @return string
	 */
	public static function add_leading_zero( $sku ) {
		$sku = trim( (string) $sku );

		if ( ! self::needs_leading_zero( $sku ) ) {
			return $sku;
		}

		return '0' . $sku;
	}

	/**
	 * Get product IDs from found rows that need a leading zero.
	 *
	 * @param array<int, array<string, mixed>> $found Found product rows.
	 * @return int[]
	 */
	public static function get_prefixable_ids( $found ) {
		$ids = array();

		foreach ( (array) $found as $row ) {
			$product_id = (int) ( $row['product_id'] ?? 0 );
			$sku        = (string) ( $row['sku'] ?? '' );

			if ( $product_id > 0 && self::needs_leading_zero( $sku ) ) {
				$ids[] = $product_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Add a leading zero to SKUs for a batch of products.
	 *
	 * @param int[] $product_ids Product IDs to process.
	 * @return array{
	 *   succeeded: int,
	 *   failed: int,
	 *   skipped: int,
	 *   errors: string[]
	 * }
	 */
	public static function process_leading_zero_batch( $product_ids ) {
		$product_ids = array_values(
			array_unique(
				array_filter( array_map( 'absint', (array) $product_ids ) )
			)
		);

		$result = array(
			'succeeded' => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'errors'    => array(),
		);

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				++$result['failed'];
				$result['errors'][] = sprintf(
					/* translators: %d: product ID */
					__( 'Product #%d not found.', 'bulk-sku-search-draft-dragan' ),
					$product_id
				);
				continue;
			}

			$current_sku = self::get_stored_sku( $product_id );
			$new_sku     = self::add_leading_zero( $current_sku );

			if ( $new_sku === $current_sku ) {
				++$result['skipped'];
				continue;
			}

			$update = self::update( $product_id, $new_sku );

			if ( $update['success'] ) {
				self::update_cached_results( $product_id, $update['sku'] );
				++$result['succeeded'];
				continue;
			}

			++$result['failed'];
			$result['errors'][] = sprintf(
				/* translators: 1: product ID, 2: error message */
				__( 'Product #%1$d: %2$s', 'bulk-sku-search-draft-dragan' ),
				$product_id,
				$update['message']
			);
		}

		return $result;
	}

	/**
	 * Update a product SKU.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $new_sku    New SKU value.
	 * @return array{success: bool, sku: string, message: string}
	 */
	/**
	 * Sanitize a SKU while preserving leading zeros.
	 *
	 * @param string $sku Raw SKU.
	 * @return string
	 */
	private static function sanitize_sku( $sku ) {
		return sanitize_text_field( wp_unslash( (string) $sku ) );
	}

	/**
	 * Read the stored SKU directly from product meta.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private static function get_stored_sku( $product_id ) {
		return (string) get_post_meta( absint( $product_id ), '_sku', true );
	}

	/**
	 * Find a product ID by exact SKU match.
	 *
	 * @param string $sku        SKU value.
	 * @param int    $exclude_id Product ID to ignore.
	 * @return int
	 */
	private static function find_product_id_by_exact_sku( $sku, $exclude_id = 0 ) {
		global $wpdb;

		$sku        = self::sanitize_sku( $sku );
		$exclude_id = absint( $exclude_id );

		if ( '' === $sku ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$product_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_sku'
				AND pm.meta_value = %s
				AND p.post_type IN ('product', 'product_variation')
				AND pm.post_id != %d
				LIMIT 1",
				$sku,
				$exclude_id
			)
		);

		return $product_id > 0 ? $product_id : 0;
	}

	/**
	 * Persist a SKU and verify the stored value.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $new_sku    SKU value.
	 * @return bool
	 */
	private static function persist_sku( $product_id, $new_sku ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		$product->set_sku( $new_sku );
		$product->save();

		update_post_meta( $product_id, '_sku', $new_sku );
		wc_delete_product_transients( $product_id );

		if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
			wc_update_product_lookup_tables( $product_id );
		}

		return self::get_stored_sku( $product_id ) === $new_sku;
	}

	public static function update( $product_id, $new_sku ) {
		$product_id = absint( $product_id );
		$new_sku    = self::sanitize_sku( $new_sku );

		if ( $product_id < 1 ) {
			return array(
				'success' => false,
				'sku'     => '',
				'message' => __( 'Invalid product ID.', 'bulk-sku-search-draft-dragan' ),
			);
		}

		if ( '' === $new_sku ) {
			return array(
				'success' => false,
				'sku'     => '',
				'message' => __( 'SKU cannot be empty.', 'bulk-sku-search-draft-dragan' ),
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return array(
				'success' => false,
				'sku'     => '',
				'message' => __( 'Product not found.', 'bulk-sku-search-draft-dragan' ),
			);
		}

		$current_sku = self::get_stored_sku( $product_id );

		if ( $current_sku === $new_sku ) {
			return array(
				'success' => true,
				'sku'     => $new_sku,
				'message' => __( 'SKU unchanged.', 'bulk-sku-search-draft-dragan' ),
			);
		}

		$existing_id = self::find_product_id_by_exact_sku( $new_sku, $product_id );
		if ( $existing_id ) {
			return array(
				'success' => false,
				'sku'     => $current_sku,
				'message' => sprintf(
					/* translators: %s: SKU value */
					__( 'SKU "%s" is already used by another product.', 'bulk-sku-search-draft-dragan' ),
					$new_sku
				),
			);
		}

		if ( ! self::persist_sku( $product_id, $new_sku ) ) {
			return array(
				'success' => false,
				'sku'     => self::get_stored_sku( $product_id ),
				'message' => __( 'Could not save SKU.', 'bulk-sku-search-draft-dragan' ),
			);
		}

		return array(
			'success' => true,
			'sku'     => $new_sku,
			'message' => __( 'SKU updated.', 'bulk-sku-search-draft-dragan' ),
		);
	}

	/**
	 * Update cached search results after a SKU change.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $new_sku    Updated SKU.
	 */
	public static function update_cached_results( $product_id, $new_sku ) {
		$cached = get_transient( BSSDD_TRANSIENT_KEY );
		if ( ! is_array( $cached ) || empty( $cached['found'] ) ) {
			return;
		}

		foreach ( $cached['found'] as $index => $row ) {
			if ( (int) ( $row['product_id'] ?? 0 ) !== $product_id ) {
				continue;
			}

			$cached['found'][ $index ]['sku'] = $new_sku;
		}

		set_transient( BSSDD_TRANSIENT_KEY, $cached, HOUR_IN_SECONDS );
	}
}
