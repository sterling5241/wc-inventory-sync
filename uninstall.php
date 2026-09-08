<?php
// Only runs when the plugin is deleted from wp-admin (not on deactivate).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Data is kept by default. The admin must have explicitly opted in via the
// "Delete all Inventory Sync data on uninstall" checkbox on the Setup tab.
if ( ! get_option( 'wcis_uninstall_cleanup' ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcis_subscribers" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcis_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcis_outbox" );

$options = array(
	'wcis_role',
	'wcis_site_label',
	'wcis_reconcile_interval_minutes',
	'wcis_db_version',
	'wcis_master_url',
	'wcis_master_key',
	'wcis_master_secret',
	'wcis_last_connection_check',
	'wcis_uninstall_cleanup',
	'wcis_gsheets_enabled',
	'wcis_gsheets_spreadsheet_id',
	'wcis_gsheets_sheet_name',
	'wcis_gsheets_service_account_json',
	'wcis_gsheets_header_written',
	'wcis_gsheets_last_exported_id',
	'wcis_gsheets_last_export_at',
	'wcis_gsheets_last_error',
	'wcis_gsheets_last_test',
);
delete_transient( 'wcis_gsheets_access_token' );
foreach ( $options as $option ) {
	delete_option( $option );
}
