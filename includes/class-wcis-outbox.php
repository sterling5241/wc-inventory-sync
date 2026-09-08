<?php
defined( 'ABSPATH' ) || exit;

/**
 * Retry queue (wp_wcis_outbox) for sync requests that failed on the first try.
 */
class WCIS_Outbox {

	/**
	 * Queue a request for later retry after an immediate send failed.
	 *
	 * @param int    $subscriber_id Target subscriber row ID (master role), or 0 (subscriber role, target is always "the master").
	 * @param string $endpoint      REST path relative to the wc-inventory-sync/v1 namespace, e.g. 'update-stock'.
	 * @param array  $payload       Request body to resend.
	 */
	public static function enqueue( $subscriber_id, $endpoint, $payload ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'wcis_outbox',
			array(
				'created_at'      => current_time( 'mysql' ),
				'subscriber_id'   => $subscriber_id ? absint( $subscriber_id ) : null,
				'endpoint'        => sanitize_text_field( $endpoint ),
				'payload'         => wp_json_encode( $payload ),
				'attempts'        => 0,
				'next_attempt_at' => current_time( 'mysql' ),
				'status'          => 'pending',
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	public static function due_items( $limit = 20 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcis_outbox';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d",
				current_time( 'mysql' ),
				$limit
			)
		);
	}

	public static function mark_done( $id ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wcis_outbox', array( 'status' => 'done' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	public static function mark_retry( $id, $attempts, $error ) {
		global $wpdb;
		$backoff_minutes = min( 60, 5 * max( 1, $attempts ) ); // 5, 10, 15 ... capped at 60 min.
		$max_attempts     = 10;
		$status           = $attempts >= $max_attempts ? 'failed' : 'pending';

		$wpdb->update(
			$wpdb->prefix . 'wcis_outbox',
			array(
				'attempts'        => $attempts,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $backoff_minutes * MINUTE_IN_SECONDS ),
				'last_error'      => is_string( $error ) ? substr( $error, 0, 1000 ) : '',
				'status'          => $status,
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Pending/failed items still queued for one subscriber (master role) —
	 * used by the per-subscriber issues tab.
	 */
	public static function get_for_subscriber( $subscriber_id, $limit = 100 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcis_outbox';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE subscriber_id = %d AND status IN ('pending','failed') ORDER BY id DESC LIMIT %d",
				$subscriber_id,
				$limit
			)
		);
	}

	public static function count_pending() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcis_outbox WHERE status = 'pending'" );
	}

	public static function count_failed() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcis_outbox WHERE status = 'failed'" );
	}

	public static function requeue_failed() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wcis_outbox SET status = 'pending', attempts = 0, next_attempt_at = %s WHERE status = 'failed'",
				current_time( 'mysql' )
			)
		);
	}
}
