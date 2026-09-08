<?php
defined( 'ABSPATH' ) || exit;

/**
 * Scheduled jobs: periodic full-catalog reconciliation (master only) and
 * outbox retry processing (both roles).
 */
class WCIS_Cron {

	const RECONCILE_HOOK = 'wcis_reconcile_event';
	const OUTBOX_HOOK     = 'wcis_outbox_retry_event';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );
		add_action( self::RECONCILE_HOOK, array( __CLASS__, 'run_reconcile' ) );
		add_action( self::OUTBOX_HOOK, array( __CLASS__, 'run_outbox' ) );

		self::schedule_events();
		self::maybe_reschedule_reconcile();
	}

	public static function add_schedules( $schedules ) {
		$schedules['wcis_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'wc-inventory-sync' ),
		);
		$minutes                              = max( 5, absint( get_option( 'wcis_reconcile_interval_minutes', 15 ) ) );
		$schedules['wcis_reconcile_interval'] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			/* translators: %d: minutes */
			'display'  => sprintf( __( 'Every %d minutes (WC Inventory Sync)', 'wc-inventory-sync' ), $minutes ),
		);
		return $schedules;
	}

	public static function schedule_events() {
		if ( ! wp_next_scheduled( self::OUTBOX_HOOK ) ) {
			wp_schedule_event( time() + 60, 'wcis_five_minutes', self::OUTBOX_HOOK );
		}
	}

	public static function maybe_reschedule_reconcile() {
		$minutes = max( 0, absint( get_option( 'wcis_reconcile_interval_minutes', 15 ) ) );
		$next    = wp_next_scheduled( self::RECONCILE_HOOK );

		if ( 0 === $minutes ) {
			if ( $next ) {
				wp_unschedule_event( $next, self::RECONCILE_HOOK );
			}
			return;
		}

		if ( ! $next ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'wcis_reconcile_interval', self::RECONCILE_HOOK );
		}
	}

	/**
	 * Call after the reconcile interval setting changes so the new
	 * interval takes effect immediately instead of on the next stale run.
	 */
	public static function reschedule_after_settings_change() {
		$next = wp_next_scheduled( self::RECONCILE_HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::RECONCILE_HOOK );
		}
		self::maybe_reschedule_reconcile();
	}

	public static function deactivate() {
		foreach ( array( self::RECONCILE_HOOK, self::OUTBOX_HOOK ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	public static function run_reconcile() {
		if ( 'master' !== get_option( 'wcis_role', '' ) ) {
			return;
		}
		WCIS_Master::full_sync();
	}

	public static function run_outbox() {
		$role  = get_option( 'wcis_role', '' );
		$items = WCIS_Outbox::due_items( 20 );

		foreach ( $items as $item ) {
			$payload  = json_decode( $item->payload, true );
			$attempts = (int) $item->attempts + 1;
			$result   = null;

			if ( 'master' === $role && $item->subscriber_id ) {
				$subscriber = WCIS_Master::get_subscriber( $item->subscriber_id );
				if ( ! $subscriber ) {
					WCIS_Outbox::mark_done( $item->id ); // Subscriber no longer exists; nothing to retry.
					continue;
				}
				$url    = WCIS_Http_Client::build_url( $subscriber->site_url, $item->endpoint );
				$result = WCIS_Http_Client::post( $url, $subscriber->api_key, $subscriber->api_secret, $payload, 20 );
			} elseif ( 'subscriber' === $role ) {
				$url    = WCIS_Http_Client::build_url( get_option( 'wcis_master_url', '' ), $item->endpoint );
				$result = WCIS_Http_Client::post(
					$url,
					get_option( 'wcis_master_key', '' ),
					get_option( 'wcis_master_secret', '' ),
					$payload,
					20
				);
			} else {
				continue; // Role changed since this was queued; leave it pending.
			}

			if ( $result['ok'] ) {
				WCIS_Outbox::mark_done( $item->id );
			} else {
				WCIS_Outbox::mark_retry( $item->id, $attempts, $result['error'] );
			}
		}
	}
}
