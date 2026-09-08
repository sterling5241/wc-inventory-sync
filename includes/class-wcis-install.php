<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles table creation/upgrades and default options.
 */
class WCIS_Install {

	public static function activate() {
		self::create_tables();
		update_option( 'wcis_db_version', WCIS_DB_VERSION );

		if ( false === get_option( 'wcis_role', false ) ) {
			add_option( 'wcis_role', '' );
		}
		if ( false === get_option( 'wcis_site_label', false ) ) {
			add_option( 'wcis_site_label', get_bloginfo( 'name' ) );
		}
		if ( false === get_option( 'wcis_reconcile_interval_minutes', false ) ) {
			add_option( 'wcis_reconcile_interval_minutes', 15 );
		}
		if ( false === get_option( 'wcis_uninstall_cleanup', false ) ) {
			add_option( 'wcis_uninstall_cleanup', 0 );
		}

		WCIS_Cron::schedule_events();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'wcis_db_version' ) !== WCIS_DB_VERSION ) {
			self::create_tables();
			update_option( 'wcis_db_version', WCIS_DB_VERSION );
		}
	}

	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$subscribers = $wpdb->prefix . 'wcis_subscribers';
		$log         = $wpdb->prefix . 'wcis_log';
		$outbox      = $wpdb->prefix . 'wcis_outbox';

		$sql = "CREATE TABLE {$subscribers} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL DEFAULT '',
			site_url VARCHAR(191) NOT NULL DEFAULT '',
			api_key VARCHAR(64) NOT NULL DEFAULT '',
			api_secret VARCHAR(128) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			last_success_at DATETIME NULL DEFAULT NULL,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY api_key (api_key)
		) {$charset_collate};

CREATE TABLE {$log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			direction VARCHAR(10) NOT NULL DEFAULT '',
			event VARCHAR(30) NOT NULL DEFAULT '',
			remote_name VARCHAR(191) NOT NULL DEFAULT '',
			sku VARCHAR(100) NOT NULL DEFAULT '',
			product_id BIGINT UNSIGNED NULL DEFAULT NULL,
			qty_before INT NULL DEFAULT NULL,
			qty_after INT NULL DEFAULT NULL,
			delta INT NULL DEFAULT NULL,
			status VARCHAR(10) NOT NULL DEFAULT '',
			message TEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY sku (sku)
		) {$charset_collate};

CREATE TABLE {$outbox} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			subscriber_id BIGINT UNSIGNED NULL DEFAULT NULL,
			endpoint VARCHAR(40) NOT NULL DEFAULT '',
			payload LONGTEXT NOT NULL,
			attempts INT NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NOT NULL,
			last_error TEXT NULL,
			status VARCHAR(10) NOT NULL DEFAULT 'pending',
			PRIMARY KEY  (id),
			KEY status_next (status, next_attempt_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
