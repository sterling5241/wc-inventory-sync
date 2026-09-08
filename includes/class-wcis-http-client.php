<?php
defined( 'ABSPATH' ) || exit;

/**
 * Signed outgoing HTTP requests, used by both the master (calling
 * subscribers) and subscribers (calling the master).
 */
class WCIS_Http_Client {

	/**
	 * Send a signed POST request.
	 *
	 * @return array { ok:bool, code:int, body:array|null, error:string }
	 */
	public static function post( $url, $key, $secret, $body_array, $timeout = 15 ) {
		$raw_body  = wp_json_encode( $body_array );
		$timestamp = (string) time();
		$signature = WCIS_Crypto::sign( $timestamp, $raw_body, $secret );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-WCIS-Key'       => (string) $key,
					'X-WCIS-Timestamp' => $timestamp,
					'X-WCIS-Signature' => $signature,
				),
				'body'    => $raw_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'body'  => null,
				'error' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : ( 'HTTP ' . $code );
			return array(
				'ok'    => false,
				'code'  => $code,
				'body'  => $data,
				'error' => $message,
			);
		}

		return array(
			'ok'    => true,
			'code'  => $code,
			'body'  => $data,
			'error' => '',
		);
	}

	/**
	 * Build the REST URL for one of our routes on a remote site.
	 */
	public static function build_url( $site_url, $route ) {
		return trailingslashit( $site_url ) . 'wp-json/' . WCIS_REST_API::NAMESPACE_V1 . '/' . ltrim( $route, '/' );
	}
}
