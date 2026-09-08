<?php
defined( 'ABSPATH' ) || exit;

/**
 * Batches local Sync Log rows out to a Google Sheet via a Service Account
 * (server-to-server — no OAuth consent screen; the service account is just
 * added as an Editor on the target spreadsheet). Runs on its own
 * configurable-interval cron rather than exporting live from
 * WCIS_Logger::log(), so a big reconciliation burst (hundreds of rows)
 * becomes one batched API call instead of hundreds of live ones, and
 * normal sync operations never wait on Google's API.
 */
class WCIS_Google_Sheets {

	const TOKEN_TRANSIENT = 'wcis_gsheets_access_token';
	const SCOPE           = 'https://www.googleapis.com/auth/spreadsheets';
	const CRON_HOOK       = 'wcis_gsheets_export_event';
	const SCHEDULE_KEY    = 'wcis_gsheets_interval';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'export_pending' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 120, self::SCHEDULE_KEY, self::CRON_HOOK );
		}
	}

	public static function add_schedule( $schedules ) {
		$minutes                        = max( 5, absint( get_option( 'wcis_gsheets_export_interval_minutes', 15 ) ) );
		$schedules[ self::SCHEDULE_KEY ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			/* translators: %d: minutes */
			'display'  => sprintf( __( 'Every %d minutes (WC Inventory Sync — Google Sheets)', 'wc-inventory-sync' ), $minutes ),
		);
		return $schedules;
	}

	/**
	 * Call after the export-interval setting changes so the new interval
	 * takes effect immediately instead of on the next stale run.
	 */
	public static function reschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_schedule_event( time() + 60, self::SCHEDULE_KEY, self::CRON_HOOK );
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public static function is_configured() {
		return get_option( 'wcis_gsheets_enabled' ) && get_option( 'wcis_gsheets_spreadsheet_id' ) && get_option( 'wcis_gsheets_service_account_json' );
	}

	/* ------------------------------------------------------------------
	 * Auth (service account JWT bearer flow — no external library needed)
	 * ---------------------------------------------------------------- */

	protected static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	protected static function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}

		$json = json_decode( get_option( 'wcis_gsheets_service_account_json', '' ), true );
		if ( ! is_array( $json ) || empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
			return new WP_Error( 'wcis_gsheets_bad_credentials', 'Service account JSON is missing client_email or private_key.' );
		}

		$now    = time();
		$header = self::base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::base64url(
			wp_json_encode(
				array(
					'iss'   => $json['client_email'],
					'scope' => self::SCOPE,
					'aud'   => 'https://oauth2.googleapis.com/token',
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$signing_input = $header . '.' . $claims;

		$private_key = openssl_pkey_get_private( $json['private_key'] );
		if ( ! $private_key ) {
			return new WP_Error( 'wcis_gsheets_bad_key', 'Could not read the private key in the service account JSON.' );
		}

		$signature = '';
		$signed    = openssl_sign( $signing_input, $signature, $private_key, 'sha256WithRSAEncryption' );
		if ( ! $signed ) {
			return new WP_Error( 'wcis_gsheets_sign_failed', 'Could not sign the token request (openssl_sign failed).' );
		}

		$jwt = $signing_input . '.' . self::base64url( $signature );

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$message = is_array( $body ) && isset( $body['error_description'] ) ? $body['error_description'] : 'Google did not return an access token.';
			return new WP_Error( 'wcis_gsheets_token_failed', $message );
		}

		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 120 ) : 3400;
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $ttl );

		return $body['access_token'];
	}

	/* ------------------------------------------------------------------
	 * Sheets API
	 * ---------------------------------------------------------------- */

	protected static function append_rows( array $rows ) {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$spreadsheet_id = get_option( 'wcis_gsheets_spreadsheet_id', '' );
		$sheet_name     = get_option( 'wcis_gsheets_sheet_name', 'Sync Log' );
		$range          = rawurlencode( $sheet_name );

		$url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet_id}/values/{$range}:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS";

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'values' => $rows ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'wcis_gsheets_append_failed', $message );
		}

		return true;
	}

	protected static function header_row() {
		return array( 'Site', 'Time', 'Direction', 'Event', 'Remote', 'Product', 'SKU', 'Qty Before', 'Qty After', 'Delta', 'Status', 'Message' );
	}

	protected static function row_for_log_entry( $row, &$name_cache ) {
		$product_name = '';
		if ( $row->product_id ) {
			if ( ! array_key_exists( $row->product_id, $name_cache ) ) {
				$p                               = wc_get_product( $row->product_id );
				$name_cache[ $row->product_id ]  = $p ? $p->get_name() : '';
			}
			$product_name = $name_cache[ $row->product_id ];
		}

		return array(
			get_option( 'wcis_site_label', get_bloginfo( 'name' ) ),
			$row->created_at,
			$row->direction,
			$row->event,
			$row->remote_name,
			$product_name,
			$row->sku,
			null === $row->qty_before ? '' : $row->qty_before,
			null === $row->qty_after ? '' : $row->qty_after,
			null === $row->delta ? '' : $row->delta,
			$row->status,
			$row->message,
		);
	}

	/**
	 * Send any log rows newer than the last export. Runs every 5 minutes;
	 * also callable directly for the "Export Now" button.
	 */
	public static function export_pending( $limit = 500 ) {
		if ( ! self::is_configured() ) {
			return;
		}

		global $wpdb;
		$last_id = (int) get_option( 'wcis_gsheets_last_exported_id', 0 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wcis_log WHERE id > %d ORDER BY id ASC LIMIT %d",
				$last_id,
				$limit
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		$name_cache = array();
		$values     = array();

		if ( ! get_option( 'wcis_gsheets_header_written' ) ) {
			$values[] = self::header_row();
		}
		foreach ( $rows as $row ) {
			$values[] = self::row_for_log_entry( $row, $name_cache );
		}

		$result = self::append_rows( $values );

		if ( is_wp_error( $result ) ) {
			update_option( 'wcis_gsheets_last_error', $result->get_error_message() );
			return;
		}

		$last_row = end( $rows );
		update_option( 'wcis_gsheets_header_written', 1 );
		update_option( 'wcis_gsheets_last_exported_id', (int) $last_row->id );
		update_option( 'wcis_gsheets_last_export_at', current_time( 'mysql' ) );
		update_option( 'wcis_gsheets_last_error', '' );
	}

	/**
	 * Write one test row without touching the normal export cursor, so
	 * testing the connection never marks real log rows as already sent.
	 *
	 * @return true|WP_Error
	 */
	public static function test_connection() {
		if ( ! get_option( 'wcis_gsheets_spreadsheet_id' ) || ! get_option( 'wcis_gsheets_service_account_json' ) ) {
			return new WP_Error( 'wcis_gsheets_not_configured', 'Fill in the Spreadsheet ID and Service Account JSON first.' );
		}

		return self::append_rows(
			array(
				self::header_row(),
				array(
					get_option( 'wcis_site_label', get_bloginfo( 'name' ) ),
					current_time( 'mysql' ),
					'test',
					'test_connection',
					'',
					'',
					'',
					'',
					'',
					'',
					'success',
					'Test row from WC Inventory Sync — safe to delete.',
				),
			)
		);
	}
}
