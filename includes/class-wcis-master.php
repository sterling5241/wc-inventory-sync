<?php
defined( 'ABSPATH' ) || exit;

/**
 * Master-role behavior: broadcast any stock change to every subscriber,
 * and apply changes subscribers report back (sales/restocks).
 *
 * Hook coverage note: WooCommerce fires 'woocommerce_product_set_stock' /
 * 'woocommerce_variation_set_stock' only via wc_update_product_stock()
 * (order sales/restocks, and our own apply_reported_change() below) — a
 * regular admin "Edit Product" save does NOT go through that function, so
 * it would be missed. To also catch manual stock edits, CSV imports, and
 * REST API edits, we additionally hook 'woocommerce_update_product' /
 * 'woocommerce_new_product' (simple/variable parents) and
 * 'woocommerce_update_product_variation' / 'woocommerce_new_product_variation'
 * (variations), which fire on every WC_Product::save(). Both families can
 * fire for the same underlying change in one request, so broadcasts are
 * de-duplicated per product ID per request.
 */
class WCIS_Master {

	/** @var array<int,bool> Product IDs already broadcast during this request. */
	protected static $broadcast_dispatched = array();

	public static function init() {
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock_object' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_object' ), 10, 1 );

		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_saved_object' ), 10, 2 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_saved_object' ), 10, 2 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_saved_object' ), 10, 2 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'on_saved_object' ), 10, 2 );
	}

	public static function on_stock_object( $product ) {
		if ( $product instanceof WC_Product ) {
			self::maybe_broadcast_product( $product );
		}
	}

	public static function on_saved_object( $id, $product ) {
		if ( $product instanceof WC_Product ) {
			self::maybe_broadcast_product( $product );
		}
	}

	protected static function maybe_broadcast_product( WC_Product $product ) {
		$id = $product->get_id();

		if ( isset( self::$broadcast_dispatched[ $id ] ) ) {
			return; // Already broadcast this product during this request.
		}
		if ( ! $product->managing_stock() ) {
			return;
		}
		$sku = $product->get_sku();
		if ( ! $sku ) {
			return; // Nothing to match on other stores without a SKU.
		}

		self::$broadcast_dispatched[ $id ] = true;
		self::broadcast_stock( $sku, (int) $product->get_stock_quantity(), $product->get_stock_status(), $id );
	}

	public static function broadcast_stock( $sku, $quantity, $stock_status, $product_id = 0, $exclude_subscriber_id = 0 ) {
		foreach ( self::get_subscribers( 'active' ) as $subscriber ) {
			if ( $exclude_subscriber_id && (int) $subscriber->id === (int) $exclude_subscriber_id ) {
				continue;
			}
			self::send_update_stock( $subscriber, $sku, $quantity, $stock_status, $product_id );
		}
	}

	protected static function send_update_stock( $subscriber, $sku, $quantity, $stock_status, $product_id = 0 ) {
		$body = array(
			'sku'          => $sku,
			'quantity'     => $quantity,
			'stock_status' => $stock_status,
		);

		$url    = WCIS_Http_Client::build_url( $subscriber->site_url, 'update-stock' );
		$result = WCIS_Http_Client::post( $url, $subscriber->api_key, $subscriber->api_secret, $body );

		self::record_result( $subscriber, 'update_stock', $sku, $product_id, null, $quantity, null, $result );

		if ( ! $result['ok'] ) {
			WCIS_Outbox::enqueue( $subscriber->id, 'update-stock', $body );
		}

		return $result;
	}

	/**
	 * A subscriber reported a change (sale = negative delta, restock =
	 * positive delta). Apply it atomically via WooCommerce's own
	 * increase/decrease stock operation (avoids read-modify-write races
	 * with other simultaneous sales), then let the resulting
	 * woocommerce_product_set_stock hook broadcast the new authoritative
	 * quantity to every subscriber — including re-confirming to the one
	 * that just reported, which is a harmless no-op for them and doubles
	 * as self-healing if their local value had drifted.
	 */
	public static function apply_reported_change( $sku, $delta, $subscriber, $params = array() ) {
		$product_id = wc_get_product_id_by_sku( $sku );
		if ( ! $product_id ) {
			WCIS_Logger::log(
				array(
					'direction'   => 'incoming',
					'event'       => 'report_change',
					'remote_name' => $subscriber->name,
					'sku'         => $sku,
					'delta'       => $delta,
					'status'      => 'error',
					'message'     => 'No product with this SKU found on the master site.',
				)
			);
			return new WP_Error( 'wcis_unknown_sku', 'No product with this SKU found on the master site.', array( 'status' => 404 ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->managing_stock() ) {
			WCIS_Logger::log(
				array(
					'direction'   => 'incoming',
					'event'       => 'report_change',
					'remote_name' => $subscriber->name,
					'sku'         => $sku,
					'product_id'  => $product_id,
					'delta'       => $delta,
					'status'      => 'error',
					'message'     => 'This product does not have stock management enabled on the master site.',
				)
			);
			return new WP_Error( 'wcis_not_managed', 'This product does not have stock management enabled on the master site.', array( 'status' => 400 ) );
		}

		$before    = (int) $product->get_stock_quantity();
		$operation = $delta < 0 ? 'decrease' : 'increase';
		$new_stock = wc_update_product_stock( $product, abs( $delta ), $operation );

		if ( false === $new_stock ) {
			$message = 'Could not update stock for this product on the master site.';
			WCIS_Logger::log(
				array(
					'direction'   => 'incoming',
					'event'       => 'report_change',
					'remote_name' => $subscriber->name,
					'sku'         => $sku,
					'product_id'  => $product_id,
					'delta'       => $delta,
					'status'      => 'error',
					'message'     => $message,
				)
			);
			return new WP_Error( 'wcis_stock_update_failed', $message, array( 'status' => 500 ) );
		}

		WCIS_Logger::log(
			array(
				'direction'   => 'incoming',
				'event'       => 'report_change',
				'remote_name' => $subscriber->name,
				'sku'         => $sku,
				'product_id'  => $product_id,
				'qty_before'  => $before,
				'qty_after'   => $new_stock,
				'delta'       => $delta,
				'status'      => 'success',
				'message'     => isset( $params['event'] ) ? sanitize_text_field( $params['event'] ) : '',
			)
		);

		return array(
			'ok'           => true,
			'sku'          => $sku,
			'new_quantity' => (int) $new_stock,
		);
	}

	/* ---------------------------------------------------------------------
	 * Subscriber (store) CRUD
	 * ------------------------------------------------------------------- */

	public static function get_subscribers( $status = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcis_subscribers';
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY name ASC", $status ) );
		}
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );
	}

	public static function get_subscriber( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcis_subscribers WHERE id = %d", $id ) );
	}

	public static function add_subscriber( $name, $site_url ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'wcis_subscribers',
			array(
				'name'       => sanitize_text_field( $name ),
				'site_url'   => esc_url_raw( untrailingslashit( $site_url ) ),
				'api_key'    => WCIS_Crypto::generate_key(),
				'api_secret' => WCIS_Crypto::generate_secret(),
				'status'     => 'active',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	public static function set_subscriber_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wcis_subscribers', array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	public static function delete_subscriber( $id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'wcis_subscribers', array( 'id' => $id ), array( '%d' ) );
	}

	protected static function record_result( $subscriber, $event, $sku, $product_id, $qty_before, $qty_after, $delta, $result ) {
		global $wpdb;
		if ( $result['ok'] ) {
			$wpdb->update(
				$wpdb->prefix . 'wcis_subscribers',
				array(
					'last_success_at' => current_time( 'mysql' ),
					'last_error'      => '',
				),
				array( 'id' => $subscriber->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->update(
				$wpdb->prefix . 'wcis_subscribers',
				array( 'last_error' => $result['error'] ),
				array( 'id' => $subscriber->id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		WCIS_Logger::log(
			array(
				'direction'   => 'outgoing',
				'event'       => $event,
				'remote_name' => $subscriber->name,
				'sku'         => $sku,
				'product_id'  => $product_id,
				'qty_before'  => $qty_before,
				'qty_after'   => $qty_after,
				'delta'       => $delta,
				'status'      => $result['ok'] ? 'success' : 'error',
				'message'     => $result['ok'] ? '' : $result['error'],
			)
		);
	}

	/**
	 * Push the full current catalog (all SKU'd, stock-managed products &
	 * variations) to one subscriber, or all of them. Used by the
	 * reconciliation cron and the "Full Sync Now" admin action — this is
	 * the self-healing safety net on top of the real-time push above.
	 */
	public static function full_sync( $only_subscriber_id = 0, $batch_size = 200 ) {
		$subscribers = $only_subscriber_id
			? array_filter( array( self::get_subscriber( $only_subscriber_id ) ) )
			: self::get_subscribers( 'active' );

		if ( empty( $subscribers ) ) {
			return;
		}

		$items = self::collect_catalog_snapshot();

		foreach ( $subscribers as $subscriber ) {
			foreach ( array_chunk( $items, $batch_size ) as $chunk ) {
				$url  = WCIS_Http_Client::build_url( $subscriber->site_url, 'bulk-update-stock' );
				$body = array( 'items' => $chunk );

				$result = WCIS_Http_Client::post( $url, $subscriber->api_key, $subscriber->api_secret, $body, 30 );

				if ( ! $result['ok'] ) {
					WCIS_Outbox::enqueue( $subscriber->id, 'bulk-update-stock', $body );
				}

				WCIS_Logger::log(
					array(
						'direction'   => 'outgoing',
						'event'       => 'full_sync',
						'remote_name' => $subscriber->name,
						'sku'         => '(' . count( $chunk ) . ' SKUs)',
						'status'      => $result['ok'] ? 'success' : 'error',
						'message'     => $result['ok'] ? '' : $result['error'],
					)
				);
			}
		}
	}

	protected static function collect_catalog_snapshot() {
		global $wpdb;
		$items = array();

		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type IN ('product','product_variation')
			 AND post_status = 'publish'"
		);

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}
			$sku = $product->get_sku();
			if ( ! $sku ) {
				continue;
			}
			$items[] = array(
				'sku'          => $sku,
				'quantity'     => (int) $product->get_stock_quantity(),
				'stock_status' => $product->get_stock_status(),
			);
		}

		return $items;
	}
}
