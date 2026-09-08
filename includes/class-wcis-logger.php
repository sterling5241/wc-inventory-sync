<?php
defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads the sync activity log (wp_wcis_log).
 */
class WCIS_Logger {

	public static function log( $args ) {
		global $wpdb;
		$defaults = array(
			'direction'   => '', // outgoing|incoming
			'event'       => '',
			'remote_name' => '',
			'sku'         => '',
			'product_id'  => null,
			'qty_before'  => null,
			'qty_after'   => null,
			'delta'       => null,
			'status'      => 'success', // success|error
			'message'     => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$wpdb->insert(
			$wpdb->prefix . 'wcis_log',
			array(
				'created_at'  => current_time( 'mysql' ),
				'direction'   => sanitize_text_field( $args['direction'] ),
				'event'       => sanitize_text_field( $args['event'] ),
				'remote_name' => sanitize_text_field( $args['remote_name'] ),
				'sku'         => sanitize_text_field( $args['sku'] ),
				'product_id'  => $args['product_id'] ? absint( $args['product_id'] ) : null,
				'qty_before'  => is_numeric( $args['qty_before'] ) ? intval( $args['qty_before'] ) : null,
				'qty_after'   => is_numeric( $args['qty_after'] ) ? intval( $args['qty_after'] ) : null,
				'delta'       => is_numeric( $args['delta'] ) ? intval( $args['delta'] ) : null,
				'status'      => sanitize_text_field( $args['status'] ),
				'message'     => sanitize_textarea_field( $args['message'] ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		// Light housekeeping so the log table doesn't grow forever.
		if ( 0 === wp_rand( 0, 200 ) ) {
			self::prune();
		}
	}

	public static function prune( $days = 60 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcis_log';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	public static function get_recent( $args = array() ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'wcis_log';
		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 50;
		$paged    = isset( $args['paged'] ) ? max( 1, absint( $args['paged'] ) ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$where  = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $args['sku'] ) ) {
			$where   .= ' AND sku = %s';
			$params[] = $args['sku'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}

		$sql      = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function count_total( $args = array() ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'wcis_log';
		$where  = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $args['sku'] ) ) {
			$where   .= ' AND sku = %s';
			$params[] = $args['sku'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}

		$sql = "SELECT COUNT(*) FROM {$table} {$where}";
		if ( $params ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}
		return (int) $wpdb->get_var( $sql );
	}
}
