<?php
/**
 * Plugin Name: WC Inventory Sync
 * Description: Keeps product stock quantities in sync between one master WooCommerce store and any number of subscriber stores, matched by SKU. Sales on a subscriber store are reported to the master and re-broadcast to every other subscriber; any stock change on the master is pushed to all subscribers.
 * Version: 1.1.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Author: dexabyte.ca
 * Author URI: https://dexabyte.ca
 * Text Domain: wc-inventory-sync
 *
 * @package WC_Inventory_Sync
 */

defined( 'ABSPATH' ) || exit;

define( 'WCIS_VERSION', '1.1.2' );
define( 'WCIS_FILE', __FILE__ );
define( 'WCIS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCIS_URL', plugin_dir_url( __FILE__ ) );
define( 'WCIS_DB_VERSION', '1' );

require_once WCIS_DIR . 'includes/class-wcis-install.php';
require_once WCIS_DIR . 'includes/class-wcis-logger.php';
require_once WCIS_DIR . 'includes/class-wcis-crypto.php';
require_once WCIS_DIR . 'includes/class-wcis-outbox.php';
require_once WCIS_DIR . 'includes/class-wcis-diagnostics.php';
require_once WCIS_DIR . 'includes/class-wcis-http-client.php';
require_once WCIS_DIR . 'includes/class-wcis-rest-api.php';
require_once WCIS_DIR . 'includes/class-wcis-master.php';
require_once WCIS_DIR . 'includes/class-wcis-subscriber.php';
require_once WCIS_DIR . 'includes/class-wcis-cron.php';
require_once WCIS_DIR . 'includes/class-wcis-google-sheets.php';
require_once WCIS_DIR . 'includes/class-wcis-admin.php';

// Self-hosted auto-updates via GitHub Releases (this plugin isn't on
// WordPress.org, so without this the Plugins page would never show an
// "update available" notice). See vendor/plugin-update-checker/README.md
// for how a new release gets picked up.
if ( file_exists( WCIS_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once WCIS_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	$wcis_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/sterling5241/wc-inventory-sync/',
		__FILE__,
		'wc-inventory-sync'
	);
	$wcis_update_checker->getVcsApi()->enableReleaseAssets( '/wc-inventory-sync\.zip$/i' );
}

register_activation_hook( __FILE__, array( 'WCIS_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCIS_Cron', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'WCIS_Google_Sheets', 'deactivate' ) );

/**
 * Main plugin bootstrap (singleton).
 */
final class WCIS_Plugin {

	/** @var WCIS_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
	}

	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCIS_FILE, true );
		}
	}

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		WCIS_Install::maybe_upgrade();
		WCIS_Cron::init();
		WCIS_Google_Sheets::init();
		WCIS_REST_API::init();
		WCIS_Admin::init();

		$role = get_option( 'wcis_role', '' );
		if ( 'master' === $role ) {
			WCIS_Master::init();
		} elseif ( 'subscriber' === $role ) {
			WCIS_Subscriber::init();
		}
	}

	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'WC Inventory Sync requires WooCommerce to be installed and active.', 'wc-inventory-sync' ) . '</p></div>';
	}
}

WCIS_Plugin::instance();
