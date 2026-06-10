<?php
/**
 * Batch draft processor for published WooCommerce products.
 */

defined( 'ABSPATH' ) || exit;

class BSSDD_Draft_Processor {

	/**
	 * Process a batch of product IDs, setting published products to draft.
	 *
	 * @param int[] $product_ids Product IDs to process.
	 * @return array{
	 *   succeeded: int,
	 *   failed: int,
	 *   skipped: int,
	 *   errors: string[]
	 * }
	 */
	public static function process_batch( $product_ids ) {
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

			if ( 'publish' !== $product->get_status() ) {
				++$result['skipped'];
				continue;
			}

			$product->set_status( 'draft' );
			$save_id = $product->save();

			if ( $save_id && ! is_wp_error( $save_id ) ) {
				++$result['succeeded'];
			} else {
				++$result['failed'];
				$message = is_wp_error( $save_id ) ? $save_id->get_error_message() : __( 'Unknown error.', 'bulk-sku-search-draft-dragan' );
				$result['errors'][] = sprintf(
					/* translators: 1: product ID, 2: error message */
					__( 'Product #%1$d: %2$s', 'bulk-sku-search-draft-dragan' ),
					$product_id,
					$message
				);
			}
		}

		return $result;
	}
}
