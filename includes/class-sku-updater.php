<?php
/**
 * Update product SKU via WooCommerce API.
 */

defined( 'ABSPATH' ) || exit;

class BSSDD_SKU_Updater {

	/**
	 * Update a product SKU.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $new_sku    New SKU value.
	 * @return array{success: bool, sku: string, message: string}
	 */
	public static function update( $product_id, $new_sku ) {
		$product_id = absint( $product_id );
		$new_sku    = wc_clean( (string) $new_sku );

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

		if ( $product->get_sku() === $new_sku ) {
			return array(
				'success' => true,
				'sku'     => $new_sku,
				'message' => __( 'SKU unchanged.', 'bulk-sku-search-draft-dragan' ),
			);
		}

		$existing_id = wc_get_product_id_by_sku( $new_sku );
		if ( $existing_id && (int) $existing_id !== $product_id ) {
			return array(
				'success' => false,
				'sku'     => $product->get_sku(),
				'message' => sprintf(
					/* translators: %s: SKU value */
					__( 'SKU "%s" is already used by another product.', 'bulk-sku-search-draft-dragan' ),
					$new_sku
				),
			);
		}

		$product->set_sku( $new_sku );
		$save_id = $product->save();

		if ( ! $save_id || is_wp_error( $save_id ) ) {
			$message = is_wp_error( $save_id ) ? $save_id->get_error_message() : __( 'Could not save SKU.', 'bulk-sku-search-draft-dragan' );

			return array(
				'success' => false,
				'sku'     => $product->get_sku(),
				'message' => $message,
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
		$cached = get_transient( bssdd_get_transient_key() );
		if ( ! is_array( $cached ) || empty( $cached['found'] ) ) {
			return;
		}

		foreach ( $cached['found'] as $index => $row ) {
			if ( (int) ( $row['product_id'] ?? 0 ) !== $product_id ) {
				continue;
			}

			$cached['found'][ $index ]['sku'] = $new_sku;
		}

		set_transient( bssdd_get_transient_key(), $cached, HOUR_IN_SECONDS );
	}
}
