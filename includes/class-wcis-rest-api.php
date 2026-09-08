<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST routes used for site-to-site sync (not for browser/user auth).
 * Authentication is a per-connection HMAC key+secret, not WordPress cookies
 * or application passwords, since the caller is another site, not a user.
 */
class WCIS_REST_API {

	const NAMESPACE_V1 = 'wc-inventory-sync/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/ping',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_ping' ),
				'permission_callback' => array( __CLASS__, 'verify_request' ),
			)
		);

		// Master-side: a subscriber reports that it sold/restocked something.
		register_rest_route(
			self::NAMESPACE_V1,
			'/report-change',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_report_change' ),
				'permission_callback' => array( __CLASS__, 'verify_request' ),
			)
		);

		// Subscriber-side: master pushes an absolute stock value for one SKU.
		register_rest_route(
			self::NAMESPACE_V1,
			'/update-stock',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_update_stock' ),
				'permission_callback' => array( __CLASS__, 'verify_request' ),
			)
		);

		// Subscriber-side: master pushes a batch of absolute stock values (reconciliation).
		register_rest_route(
			self::NAMESPACE_V1,
			'/bulk-update-stock',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_bulk_update_stock' ),
				'permission_callback' => array( __CLASS__, 'verify_request' ),
			)
		);
	}

	/**
	 * Shared permission callback for every route. This site's configured
	 * role decides how to interpret the key: a master looks the key up
	 * among its subscribers; a subscriber compares it to its one stored
	 * master key. Either way, the HMAC signature (computed over the raw
	 * body + timestamp) and a 5-minute replay window are enforced.
	 */
	public static function verify_request( WP_REST_Request $request ) {
		$key       = $request->get_header( 'x-wcis-key' );
		$timestamp = $request->get_header( 'x-wcis-timestamp' );
		$signature = $request->get_header( 'x-wcis-signature' );
		$raw_body  = $request->get_body();

		if ( ! $key || ! $timestamp || ! $signature ) {
			return new WP_Error( 'wcis_auth_missing', 'Missing authentication headers.', array( 'status' => 401 ) );
		}

		$role = get_option( 'wcis_role', '' );

		if ( 'master' === $role ) {
			global $wpdb;
			$subscriber = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcis_subscribers WHERE api_key = %s", $key )
			);
			if ( ! $subscriber || 'active' !== $subscriber->status ) {
				return new WP_Error( 'wcis_auth_unknown_key', 'Unknown or inactive subscriber key.', array( 'status' => 403 ) );
			}
			if ( ! WCIS_Crypto::verify( $timestamp, $raw_body, $subscriber->api_secret, $signature ) ) {
				return new WP_Error( 'wcis_auth_bad_signature', 'Invalid signature.', array( 'status' => 403 ) );
			}
			$request->set_param( '_wcis_subscriber', $subscriber );
			return true;
		}

		if ( 'subscriber' === $role ) {
			$master_key    = get_option( 'wcis_master_key', '' );
			$master_secret = get_option( 'wcis_master_secret', '' );
			if ( ! $master_key || ! hash_equals( $master_key, (string) $key ) ) {
				return new WP_Error( 'wcis_auth_unknown_key', 'Unknown key.', array( 'status' => 403 ) );
			}
			if ( ! WCIS_Crypto::verify( $timestamp, $raw_body, $master_secret, $signature ) ) {
				return new WP_Error( 'wcis_auth_bad_signature', 'Invalid signature.', array( 'status' => 403 ) );
			}
			return true;
		}

		return new WP_Error( 'wcis_role_not_configured', 'This site has no Inventory Sync role configured.', array( 'status' => 403 ) );
	}

	public static function handle_ping( WP_REST_Request $request ) {
		return new WP_REST_Response(
			array(
				'ok'    => true,
				'role'  => get_option( 'wcis_role', '' ),
				'label' => get_option( 'wcis_site_label', get_bloginfo( 'name' ) ),
				'time'  => time(),
			),
			200
		);
	}

	public static function handle_report_change( WP_REST_Request $request ) {
		if ( 'master' !== get_option( 'wcis_role', '' ) ) {
			return new WP_Error( 'wcis_wrong_role', 'This site is not configured as a master.', array( 'status' => 400 ) );
		}

		$subscriber = $request->get_param( '_wcis_subscriber' );
		$params     = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$sku   = isset( $params['sku'] ) ? sanitize_text_field( $params['sku'] ) : '';
		$delta = isset( $params['delta'] ) ? intval( $params['delta'] ) : 0;

		if ( ! $sku || 0 === $delta ) {
			return new WP_Error( 'wcis_bad_request', 'sku and a non-zero delta are required.', array( 'status' => 400 ) );
		}

		$result = WCIS_Master::apply_reported_change( $sku, $delta, $subscriber, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	public static function handle_update_stock( WP_REST_Request $request ) {
		if ( 'subscriber' !== get_option( 'wcis_role', '' ) ) {
			return new WP_Error( 'wcis_wrong_role', 'This site is not configured as a subscriber.', array( 'status' => 400 ) );
		}

		$params = $request->get_json_params();
		$result = WCIS_Subscriber::apply_incoming_stock( is_array( $params ) ? $params : array() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	public static function handle_bulk_update_stock( WP_REST_Request $request ) {
		if ( 'subscriber' !== get_option( 'wcis_role', '' ) ) {
			return new WP_Error( 'wcis_wrong_role', 'This site is not configured as a subscriber.', array( 'status' => 400 ) );
		}

		$params = $request->get_json_params();
		$items  = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();

		$results = array();
		foreach ( $items as $item ) {
			$r         = WCIS_Subscriber::apply_incoming_stock( is_array( $item ) ? $item : array() );
			$results[] = is_wp_error( $r )
				? array(
					'sku'     => isset( $item['sku'] ) ? $item['sku'] : '',
					'ok'      => false,
					'message' => $r->get_error_message(),
				)
				: array_merge( array( 'ok' => true ), $r );
		}

		return new WP_REST_Response( array( 'ok' => true, 'results' => $results ), 200 );
	}
}
