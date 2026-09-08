<?php
defined( 'ABSPATH' ) || exit;

/**
 * Subscriber-role behavior: report local sales/restocks to the master, and
 * apply absolute stock values the master pushes down.
 *
 * Only order-driven stock changes (a sale, or a cancellation/refund that
 * restores stock) are reported upstream. Manually editing stock quantity
 * directly on a subscriber store is not supported by design — the master
 * is the single source of truth, and any manual edit here will simply be
 * overwritten by the next real-time push or periodic reconciliation from
 * the master. This keeps the delta accounting unambiguous: WooCommerce's
 * own 'woocommerce_reduce_order_item_stock' / 'woocommerce_restore_order_item_stock'
 * hooks report the exact quantity that changed for a known reason, rather
 * than trying to infer intent from an arbitrary stock write.
 */
class WCIS_Subscriber {

	public static function init() {
		add_action( 'woocommerce_reduce_order_item_stock', array( __CLASS__, 'on_item_stock_reduced' ), 10, 3 );
		add_action( 'woocommerce_restore_order_item_stock', array( __CLASS__, 'on_item_stock_restored' ), 10, 4 );
	}

	/**
	 * A sale just reduced stock for one order line item on this store.
	 * Report the delta to the master.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param array                 $change { product: WC_Product, from: int, to: int }
	 * @param WC_Order              $order
	 */
	public static function on_item_stock_reduced( $item, $change, $order ) {
		if ( empty( $change['product'] ) || ! $change['product'] instanceof WC_Product ) {
			return;
		}
		$product = $change['product'];
		$sku     = $product->get_sku();
		if ( ! $sku ) {
			return;
		}
		$qty = isset( $change['from'], $change['to'] ) ? ( (int) $change['from'] - (int) $change['to'] ) : 0;
		if ( $qty <= 0 ) {
			return;
		}
		self::report_change( $sku, -$qty, 'sale', $order ? $order->get_id() : 0, $product->get_id() );
	}

	/**
	 * An order was cancelled/refunded and stock restored on this store.
	 * Report the restock to the master.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param int                   $new_stock
	 * @param int                   $old_stock
	 * @param WC_Order              $order
	 */
	public static function on_item_stock_restored( $item, $new_stock, $old_stock, $order ) {
		$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$sku = $product->get_sku();
		if ( ! $sku ) {
			return;
		}
		$qty = (int) $new_stock - (int) $old_stock;
		if ( $qty <= 0 ) {
			return;
		}
		self::report_change( $sku, $qty, 'restock', $order ? $order->get_id() : 0, $product->get_id() );
	}

	public static function report_change( $sku, $delta, $event, $order_id = 0, $product_id = 0 ) {
		$master_url    = get_option( 'wcis_master_url', '' );
		$master_key    = get_option( 'wcis_master_key', '' );
		$master_secret = get_option( 'wcis_master_secret', '' );

		$body = array(
			'sku'      => $sku,
			'delta'    => $delta,
			'event'    => $event,
			'order_id' => $order_id,
		);

		if ( ! $master_url || ! $master_key || ! $master_secret ) {
			WCIS_Logger::log(
				array(
					'direction'  => 'outgoing',
					'event'      => $event,
					'sku'        => $sku,
					'product_id' => $product_id,
					'delta'      => $delta,
					'status'     => 'error',
					'message'    => 'Master site connection is not configured.',
				)
			);
			return;
		}

		$url    = WCIS_Http_Client::build_url( $master_url, 'report-change' );
		$result = WCIS_Http_Client::post( $url, $master_key, $master_secret, $body );

		WCIS_Logger::log(
			array(
				'direction'  => 'outgoing',
				'event'      => $event,
				'sku'        => $sku,
				'product_id' => $product_id,
				'delta'      => $delta,
				'status'     => $result['ok'] ? 'success' : 'error',
				'message'    => $result['ok'] ? '' : $result['error'],
			)
		);

		if ( ! $result['ok'] ) {
			// Retry later so a temporary outage on the master doesn't lose the sale.
			WCIS_Outbox::enqueue( 0, 'report-change', $body );
		}
	}

	/**
	 * Apply an absolute stock quantity pushed from the master.
	 *
	 * @return array|WP_Error { sku, new_quantity }
	 */
	public static function apply_incoming_stock( $params ) {
		$sku = isset( $params['sku'] ) ? sanitize_text_field( $params['sku'] ) : '';
		if ( ! $sku || ! isset( $params['quantity'] ) ) {
			return new WP_Error( 'wcis_bad_request', 'sku and quantity are required.', array( 'status' => 400 ) );
		}

		$quantity     = intval( $params['quantity'] );
		$stock_status = isset( $params['stock_status'] ) ? sanitize_text_field( $params['stock_status'] ) : '';

		$product_id = wc_get_product_id_by_sku( $sku );
		if ( ! $product_id ) {
			WCIS_Logger::log(
				array(
					'direction' => 'incoming',
					'event'     => 'update_stock',
					'sku'       => $sku,
					'qty_after' => $quantity,
					'status'    => 'error',
					'message'   => 'No product with this SKU found on this store.',
				)
			);
			return new WP_Error( 'wcis_unknown_sku', 'No product with this SKU found on this store.', array( 'status' => 404 ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'wcis_unknown_sku', 'Product could not be loaded.', array( 'status' => 404 ) );
		}

		$before = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;

		// Make sure stock management is on for this product so the quantity actually sticks.
		if ( ! $product->managing_stock() ) {
			$product->set_manage_stock( true );
		}

		$new_stock = wc_update_product_stock( $product, $quantity, 'set' );

		if ( false === $new_stock ) {
			$message = 'Could not update stock for this product on this store.';
			WCIS_Logger::log(
				array(
					'direction'  => 'incoming',
					'event'      => 'update_stock',
					'sku'        => $sku,
					'product_id' => $product_id,
					'qty_before' => $before,
					'qty_after'  => $quantity,
					'status'     => 'error',
					'message'    => $message,
				)
			);
			return new WP_Error( 'wcis_stock_update_failed', $message, array( 'status' => 500 ) );
		}

		if ( $stock_status ) {
			$product = wc_get_product( $product_id );
			if ( $product && $product->get_stock_status() !== $stock_status ) {
				$product->set_stock_status( $stock_status );
				$product->save();
			}
		}

		WCIS_Logger::log(
			array(
				'direction'  => 'incoming',
				'event'      => 'update_stock',
				'sku'        => $sku,
				'product_id' => $product_id,
				'qty_before' => $before,
				'qty_after'  => $new_stock,
				'status'     => 'success',
			)
		);

		return array(
			'sku'          => $sku,
			'new_quantity' => (int) $new_stock,
		);
	}

	/**
	 * Apply a SKU the master assigned to one of THIS site's own products,
	 * from the master's Manual Match tab (used when the same product exists
	 * on both stores under different — or no — SKUs, so it never matched up
	 * automatically). Refuses to overwrite a product that already has a
	 * SKU, and refuses a SKU already used by a different product here —
	 * both cases need a human to sort out rather than silently clobbering
	 * something.
	 */
	public static function apply_matched_sku( $params ) {
		$product_id = isset( $params['product_id'] ) ? absint( $params['product_id'] ) : 0;
		$sku        = isset( $params['sku'] ) ? sanitize_text_field( $params['sku'] ) : '';

		if ( ! $product_id || ! $sku ) {
			return new WP_Error( 'wcis_bad_request', 'product_id and sku are required.', array( 'status' => 400 ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'wcis_unknown_product', 'No product with this ID found on this store.', array( 'status' => 404 ) );
		}

		if ( $product->get_sku() ) {
			return new WP_Error( 'wcis_sku_exists', 'This product already has a SKU on this store.', array( 'status' => 409 ) );
		}

		$taken_by = wc_get_product_id_by_sku( $sku );
		if ( $taken_by && (int) $taken_by !== $product_id ) {
			return new WP_Error( 'wcis_sku_taken', 'That SKU is already used by a different product on this store.', array( 'status' => 409 ) );
		}

		$product->set_sku( $sku );
		$product->save();

		WCIS_Logger::log(
			array(
				'direction'  => 'incoming',
				'event'      => 'sku_match',
				'sku'        => $sku,
				'product_id' => $product_id,
				'status'     => 'success',
				'message'    => 'SKU assigned via manual match from the master.',
			)
		);

		return array( 'sku' => $sku, 'product_id' => $product_id );
	}
}
