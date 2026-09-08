<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read-only diagnostics about THIS site's own catalog. Shared by the "Not
 * Synced" admin tab (local view) and the /not-synced REST route (so a
 * master can pull this same list from a subscriber's Subscriber Issues tab
 * without direct database access to that store).
 */
class WCIS_Diagnostics {

	/**
	 * Scan published products/variations for ones that can't participate in
	 * sync yet — same two conditions WCIS_Master/WCIS_Subscriber already
	 * require (a SKU, and stock management enabled) — so the admin can find
	 * and fix them instead of wondering why a product never shows up in the
	 * Sync Log.
	 */
	public static function get_unsynced_products( $limit_scan = 2000 ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type IN ('product','product_variation')
				 AND post_status = 'publish'
				 ORDER BY ID ASC
				 LIMIT %d",
				$limit_scan
			)
		);

		$issues = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}

			// Grouped/external products have no inventory concept at all in
			// WooCommerce (their edit screen never shows stock fields) — skip.
			if ( $product->is_type( array( 'grouped', 'external' ) ) ) {
				continue;
			}

			// A variable product can manage stock two ways: a shared quantity
			// set on the parent itself (applies to any variation that doesn't
			// override it — WooCommerce shows this explicitly: "Settings
			// below apply to all variations without manual stock management
			// enabled"), or per-variation via manage_stock on each variation
			// individually. Only flag the parent when NEITHER is in use
			// anywhere in the family — that's the one case where nothing
			// here can ever sync. A missing SKU on the parent is checked
			// separately, and only matters if the parent itself is the one
			// supposed to be managing/syncing stock.
			if ( $product->is_type( 'variable' ) ) {
				$family_manages_stock = $product->managing_stock();
				if ( ! $family_manages_stock ) {
					foreach ( $product->get_children() as $variation_id ) {
						$variation = wc_get_product( $variation_id );
						if ( $variation && $variation->managing_stock() ) {
							$family_manages_stock = true;
							break;
						}
					}
				}

				if ( ! $family_manages_stock ) {
					$issues[] = array(
						'id'      => $product->get_id(),
						'edit_id' => $product->get_id(),
						'name'    => $product->get_name(),
						'type'    => $product->get_type(),
						'sku'     => $product->get_sku(),
						'manages' => false,
						'reasons' => array( __( 'Neither this product nor any of its variations has stock management enabled', 'wc-inventory-sync' ) ),
					);
				} elseif ( $product->managing_stock() && ! $product->get_sku() ) {
					$issues[] = array(
						'id'      => $product->get_id(),
						'edit_id' => $product->get_id(),
						'name'    => $product->get_name(),
						'type'    => $product->get_type(),
						'sku'     => '',
						'manages' => true,
						'reasons' => array( __( 'No SKU set', 'wc-inventory-sync' ) ),
					);
				}
				continue;
			}

			$reasons = array();
			if ( ! $product->get_sku() ) {
				$reasons[] = __( 'No SKU set', 'wc-inventory-sync' );
			}
			if ( ! $product->managing_stock() ) {
				$reasons[] = __( 'Stock management not enabled', 'wc-inventory-sync' );
			}

			if ( $reasons ) {
				$issues[] = array(
					'id'      => $product->get_id(),
					'edit_id' => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
					'name'    => $product->get_name(),
					'type'    => $product->get_type(),
					'sku'     => $product->get_sku(),
					'manages' => $product->managing_stock(),
					'reasons' => $reasons,
				);
			}
		}

		return array(
			'issues'    => $issues,
			'scanned'   => count( $ids ),
			'truncated' => count( $ids ) === $limit_scan,
		);
	}
}
