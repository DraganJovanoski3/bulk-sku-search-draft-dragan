<?php
/**
 * Bulk WooCommerce SKU lookup.
 */

defined( 'ABSPATH' ) || exit;

class BSSDD_SKU_Finder {

	/**
	 * Search WooCommerce for products matching the given SKUs.
	 *
	 * @param string[] $skus Original SKU strings (display order).
	 * @return array{
	 *   found: array<int, array<string, mixed>>,
	 *   not_found: string[],
	 *   summary: array<string, int>
	 * }
	 */
	public static function search( $skus ) {
		global $wpdb;

		$skus = array_values( array_filter( array_map( 'strval', $skus ) ) );

		if ( empty( $skus ) ) {
			return self::empty_result();
		}

		$lookup_values = array();

		foreach ( $skus as $sku ) {
			foreach ( BSSDD_SKU_Parser::sku_lookup_variants( $sku ) as $variant ) {
				$lookup_values[] = BSSDD_SKU_Parser::normalize_sku( $variant );
			}
		}

		$lookup_values = array_values( array_unique( $lookup_values ) );
		$placeholders  = implode( ', ', array_fill( 0, count( $lookup_values ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_status, p.post_type, pm.meta_value AS sku
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_sku'
			AND pm.meta_value != ''
			AND p.post_type IN ('product', 'product_variation')
			AND LOWER(TRIM(pm.meta_value)) IN ($placeholders)",
			...$lookup_values
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$matches_by_normalized = array();

		foreach ( $rows as $row ) {
			$normalized = BSSDD_SKU_Parser::normalize_sku( $row['sku'] ?? '' );
			if ( '' === $normalized ) {
				continue;
			}

			if ( ! isset( $matches_by_normalized[ $normalized ] ) ) {
				$matches_by_normalized[ $normalized ] = array();
			}

			$product_id = (int) $row['ID'];
			$product    = wc_get_product( $product_id );
			$view_link  = $product ? $product->get_permalink() : get_permalink( $product_id );

			$matches_by_normalized[ $normalized ][] = array(
				'product_id'   => $product_id,
				'sku'          => (string) $row['sku'],
				'title'        => (string) $row['post_title'],
				'status'       => (string) $row['post_status'],
				'post_type'    => (string) $row['post_type'],
				'edit_link'    => get_edit_post_link( $product_id, 'raw' ),
				'view_link'    => $view_link ? (string) $view_link : '',
				'is_draftable' => 'publish' === $row['post_status'],
			);
		}

		$found     = array();
		$not_found = array();

		foreach ( $skus as $input_sku ) {
			$matched_rows = array();
			$seen_rows    = array();

			foreach ( $matches_by_normalized as $matches ) {
				foreach ( $matches as $match ) {
					if ( ! BSSDD_SKU_Parser::skus_equivalent( $input_sku, $match['sku'] ?? '' ) ) {
						continue;
					}

					$row_key = (int) ( $match['product_id'] ?? 0 ) . ':' . BSSDD_SKU_Parser::normalize_sku( $input_sku );
					if ( isset( $seen_rows[ $row_key ] ) ) {
						continue;
					}

					$seen_rows[ $row_key ]    = true;
					$matched_rows[]           = $match;
				}
			}

			if ( empty( $matched_rows ) ) {
				$not_found[] = $input_sku;
				continue;
			}

			foreach ( $matched_rows as $match ) {
				$found[] = array_merge(
					$match,
					array(
						'input_sku' => $input_sku,
					)
				);
			}
		}

		return array(
			'found'     => $found,
			'not_found' => $not_found,
			'summary'   => self::build_summary( $skus, $found, $not_found ),
		);
	}

	/**
	 * Build summary counts from search results.
	 *
	 * @param string[]                         $skus Input SKUs.
	 * @param array<int, array<string, mixed>> $found Found product rows.
	 * @param string[]                         $not_found SKUs not in store.
	 * @return array<string, int>
	 */
	private static function build_summary( $skus, $found, $not_found ) {
		$draftable     = 0;
		$already_draft = 0;
		$other_status  = 0;

		foreach ( $found as $row ) {
			$status = $row['status'] ?? '';

			if ( 'publish' === $status ) {
				++$draftable;
			} elseif ( 'draft' === $status ) {
				++$already_draft;
			} else {
				++$other_status;
			}
		}

		return array(
			'total'         => count( $skus ),
			'found_skus'    => count( $skus ) - count( $not_found ),
			'found_rows'    => count( $found ),
			'not_found'     => count( $not_found ),
			'draftable'     => $draftable,
			'already_draft' => $already_draft,
			'other_status'  => $other_status,
		);
	}

	/**
	 * Empty search result structure.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_result() {
		return array(
			'found'     => array(),
			'not_found' => array(),
			'summary'   => array(
				'total'         => 0,
				'found_skus'    => 0,
				'found_rows'    => 0,
				'not_found'     => 0,
				'draftable'     => 0,
				'already_draft' => 0,
				'other_status'  => 0,
			),
		);
	}

	/**
	 * Get product IDs that are published and draftable from found rows.
	 *
	 * @param array<int, array<string, mixed>> $found Found rows.
	 * @return int[]
	 */
	public static function get_draftable_ids( $found ) {
		$ids = array();

		foreach ( $found as $row ) {
			if ( empty( $row['is_draftable'] ) || empty( $row['product_id'] ) ) {
				continue;
			}

			$ids[] = (int) $row['product_id'];
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}
}
