<?php
defined( 'ABSPATH' ) || exit;

/**
 * wp-admin settings screens: Setup / Subscriber Stores / Master Connection /
 * Sync Log / Retry Queue, under WooCommerce → Inventory Sync.
 */
class WCIS_Admin {

	const PAGE_SLUG = 'wc-inventory-sync';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );

		add_action( 'admin_post_wcis_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_wcis_add_subscriber', array( __CLASS__, 'handle_add_subscriber' ) );
		add_action( 'admin_post_wcis_delete_subscriber', array( __CLASS__, 'handle_delete_subscriber' ) );
		add_action( 'admin_post_wcis_toggle_subscriber', array( __CLASS__, 'handle_toggle_subscriber' ) );
		add_action( 'admin_post_wcis_force_sync', array( __CLASS__, 'handle_force_sync' ) );
		add_action( 'admin_post_wcis_save_master_connection', array( __CLASS__, 'handle_save_master_connection' ) );
		add_action( 'admin_post_wcis_test_master_connection', array( __CLASS__, 'handle_test_master_connection' ) );
		add_action( 'admin_post_wcis_test_subscriber_connection', array( __CLASS__, 'handle_test_subscriber_connection' ) );
		add_action( 'admin_post_wcis_requeue_outbox', array( __CLASS__, 'handle_requeue_outbox' ) );
		add_action( 'admin_post_wcis_generate_sku', array( __CLASS__, 'handle_generate_sku' ) );
		add_action( 'admin_post_wcis_enable_stock_management', array( __CLASS__, 'handle_enable_stock_management' ) );
		add_action( 'admin_post_wcis_apply_local_sku', array( __CLASS__, 'handle_apply_local_sku' ) );
		add_action( 'admin_post_wcis_push_sku_to_subscriber', array( __CLASS__, 'handle_push_sku_to_subscriber' ) );
		add_action( 'admin_post_wcis_save_gsheets_settings', array( __CLASS__, 'handle_save_gsheets_settings' ) );
		add_action( 'admin_post_wcis_test_gsheets', array( __CLASS__, 'handle_test_gsheets' ) );
		add_action( 'admin_post_wcis_export_gsheets_now', array( __CLASS__, 'handle_export_gsheets_now' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( WCIS_FILE ), array( __CLASS__, 'add_settings_link' ) );
	}

	/**
	 * Adds a "Settings" link to this plugin's row on the Plugins page,
	 * next to Deactivate.
	 */
	public static function add_settings_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', 'wc-inventory-sync' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Inventory Sync', 'wc-inventory-sync' ),
			__( 'Inventory Sync', 'wc-inventory-sync' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	protected static function check_cap() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-inventory-sync' ) );
		}
	}

	protected static function redirect_back( $tab = '', $args = array() ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . ( $tab ? '&tab=' . $tab : '' ) );
		if ( $args ) {
			$url = add_query_arg( $args, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Handlers
	 * ---------------------------------------------------------------- */

	public static function handle_save_settings() {
		self::check_cap();
		check_admin_referer( 'wcis_save_settings' );

		$role = isset( $_POST['wcis_role'] ) ? sanitize_text_field( wp_unslash( $_POST['wcis_role'] ) ) : '';
		if ( ! in_array( $role, array( '', 'master', 'subscriber' ), true ) ) {
			$role = '';
		}
		update_option( 'wcis_role', $role );
		update_option(
			'wcis_site_label',
			isset( $_POST['wcis_site_label'] ) ? sanitize_text_field( wp_unslash( $_POST['wcis_site_label'] ) ) : get_bloginfo( 'name' )
		);
		update_option( 'wcis_uninstall_cleanup', isset( $_POST['wcis_uninstall_cleanup'] ) ? 1 : 0 );

		$minutes = isset( $_POST['wcis_reconcile_interval_minutes'] ) ? absint( $_POST['wcis_reconcile_interval_minutes'] ) : 15;
		update_option( 'wcis_reconcile_interval_minutes', $minutes );
		WCIS_Cron::reschedule_after_settings_change();

		self::redirect_back( '', array( 'wcis_notice' => 'settings_saved' ) );
	}

	public static function handle_add_subscriber() {
		self::check_cap();
		check_admin_referer( 'wcis_add_subscriber' );

		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';

		if ( $name && $site_url ) {
			WCIS_Master::add_subscriber( $name, $site_url );
			self::redirect_back( 'subscribers', array( 'wcis_notice' => 'subscriber_added' ) );
		}
		self::redirect_back( 'subscribers', array( 'wcis_notice' => 'error' ) );
	}

	public static function handle_delete_subscriber() {
		self::check_cap();
		check_admin_referer( 'wcis_delete_subscriber' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			WCIS_Master::delete_subscriber( $id );
		}
		self::redirect_back( 'subscribers', array( 'wcis_notice' => 'subscriber_deleted' ) );
	}

	public static function handle_toggle_subscriber() {
		self::check_cap();
		check_admin_referer( 'wcis_toggle_subscriber' );
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'active';
		if ( $id ) {
			WCIS_Master::set_subscriber_status( $id, 'active' === $status ? 'paused' : 'active' );
		}
		self::redirect_back( 'subscribers' );
	}

	public static function handle_force_sync() {
		self::check_cap();
		check_admin_referer( 'wcis_force_sync' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		WCIS_Master::full_sync( $id );
		self::redirect_back( 'subscribers', array( 'wcis_notice' => 'sync_started' ) );
	}

	public static function handle_save_master_connection() {
		self::check_cap();
		check_admin_referer( 'wcis_save_master_connection' );

		update_option( 'wcis_master_url', isset( $_POST['master_url'] ) ? esc_url_raw( wp_unslash( $_POST['master_url'] ) ) : '' );
		update_option( 'wcis_master_key', isset( $_POST['master_key'] ) ? sanitize_text_field( wp_unslash( $_POST['master_key'] ) ) : '' );

		$secret = isset( $_POST['master_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['master_secret'] ) ) : '';
		if ( $secret ) { // Only overwrite if a new value was actually entered.
			update_option( 'wcis_master_secret', $secret );
		}

		self::redirect_back( 'connection', array( 'wcis_notice' => 'settings_saved' ) );
	}

	public static function handle_test_master_connection() {
		self::check_cap();
		check_admin_referer( 'wcis_test_master_connection' );

		$url    = get_option( 'wcis_master_url', '' );
		$key    = get_option( 'wcis_master_key', '' );
		$secret = get_option( 'wcis_master_secret', '' );

		$result = WCIS_Http_Client::post( WCIS_Http_Client::build_url( $url, 'ping' ), $key, $secret, array() );

		update_option(
			'wcis_last_connection_check',
			array(
				'ok'      => $result['ok'],
				'message' => $result['ok'] ? ( 'Connected to ' . ( isset( $result['body']['label'] ) ? $result['body']['label'] : $url ) ) : $result['error'],
				'time'    => time(),
			)
		);

		self::redirect_back( 'connection', array( 'wcis_notice' => $result['ok'] ? 'connection_ok' : 'connection_failed' ) );
	}

	public static function handle_test_subscriber_connection() {
		self::check_cap();
		check_admin_referer( 'wcis_test_subscriber_connection' );

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$subscriber = WCIS_Master::get_subscriber( $id );
		if ( ! $subscriber ) {
			self::redirect_back( 'subscribers', array( 'wcis_notice' => 'error' ) );
		}

		$result = WCIS_Http_Client::post(
			WCIS_Http_Client::build_url( $subscriber->site_url, 'ping' ),
			$subscriber->api_key,
			$subscriber->api_secret,
			array()
		);

		self::redirect_back( 'subscribers', array( 'wcis_notice' => $result['ok'] ? 'connection_ok' : 'connection_failed' ) );
	}

	public static function handle_requeue_outbox() {
		self::check_cap();
		check_admin_referer( 'wcis_requeue_outbox' );
		WCIS_Outbox::requeue_failed();
		self::redirect_back( 'outbox', array( 'wcis_notice' => 'requeued' ) );
	}

	public static function handle_save_gsheets_settings() {
		self::check_cap();
		check_admin_referer( 'wcis_save_gsheets_settings' );

		update_option( 'wcis_gsheets_enabled', isset( $_POST['wcis_gsheets_enabled'] ) ? 1 : 0 );

		$spreadsheet_input = isset( $_POST['spreadsheet_id'] ) ? trim( wp_unslash( $_POST['spreadsheet_id'] ) ) : '';
		// Accept either a bare ID or the full Sheet URL and pull the ID out of it.
		if ( preg_match( '#/spreadsheets/d/([a-zA-Z0-9-_]+)#', $spreadsheet_input, $m ) ) {
			$spreadsheet_input = $m[1];
		}
		update_option( 'wcis_gsheets_spreadsheet_id', sanitize_text_field( $spreadsheet_input ) );
		update_option( 'wcis_gsheets_sheet_name', isset( $_POST['sheet_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sheet_name'] ) ) : 'Sync Log' );

		// Only overwrite the stored credentials if something was actually
		// pasted -- the textarea is left blank on reload so the private key
		// isn't echoed back onto the page every time.
		$json = isset( $_POST['service_account_json'] ) ? trim( wp_unslash( $_POST['service_account_json'] ) ) : '';
		if ( $json ) {
			update_option( 'wcis_gsheets_service_account_json', $json );
			delete_transient( WCIS_Google_Sheets::TOKEN_TRANSIENT );
		}

		self::redirect_back( 'gsheets', array( 'wcis_notice' => 'settings_saved' ) );
	}

	public static function handle_test_gsheets() {
		self::check_cap();
		check_admin_referer( 'wcis_test_gsheets' );

		$result = WCIS_Google_Sheets::test_connection();

		update_option(
			'wcis_gsheets_last_test',
			array(
				'ok'      => ! is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : 'A test row was added successfully.',
				'time'    => time(),
			)
		);

		self::redirect_back( 'gsheets', array( 'wcis_notice' => is_wp_error( $result ) ? 'gsheets_test_failed' : 'gsheets_test_ok' ) );
	}

	public static function handle_export_gsheets_now() {
		self::check_cap();
		check_admin_referer( 'wcis_export_gsheets_now' );
		WCIS_Google_Sheets::export_pending( 2000 );
		self::redirect_back( 'gsheets', array( 'wcis_notice' => 'gsheets_exported' ) );
	}

	/**
	 * Generate a placeholder SKU for a product that doesn't have one, so it
	 * becomes eligible to sync. This only sets the SKU on THIS site — it
	 * does not (and can't) touch the matching product on any other store,
	 * so the same value still needs to exist there too for sync to
	 * actually match them up. Format: AUTOGEN-<product ID>, which is always
	 * unique on this site by construction; still checked for a collision
	 * before saving, just in case something else already used it.
	 */
	public static function handle_generate_sku() {
		self::check_cap();
		check_admin_referer( 'wcis_generate_sku' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product ) {
			self::redirect_back( 'not_synced', array( 'wcis_notice' => 'error' ) );
		}
		if ( $product->get_sku() ) {
			self::redirect_back( 'not_synced', array( 'wcis_notice' => 'sku_exists' ) );
		}

		$candidate = 'AUTOGEN-' . $product_id;
		$taken_by  = wc_get_product_id_by_sku( $candidate );
		if ( $taken_by && (int) $taken_by !== $product_id ) {
			self::redirect_back( 'not_synced', array( 'wcis_notice' => 'error' ) );
		}

		$product->set_sku( $candidate );
		$product->save();

		self::redirect_back( 'not_synced', array( 'wcis_notice' => 'sku_generated' ) );
	}

	/**
	 * Turn on "Manage stock?" for a product and set its starting quantity in
	 * one step, right from the Not Synced tab. Uses the same
	 * wc_update_product_stock('set') path the sync engine itself uses, so
	 * on a master site this also immediately broadcasts the new quantity to
	 * every subscriber (as long as the product also has a SKU).
	 */
	public static function handle_enable_stock_management() {
		self::check_cap();
		check_admin_referer( 'wcis_enable_stock_management' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? intval( $_POST['quantity'] ) : null;
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product || null === $quantity || $quantity < 0 ) {
			self::redirect_back( 'not_synced', array( 'wcis_notice' => 'error' ) );
		}

		if ( ! $product->managing_stock() ) {
			$product->set_manage_stock( true );
		}
		$new_stock = wc_update_product_stock( $product, $quantity, 'set' );

		if ( false === $new_stock ) {
			self::redirect_back( 'not_synced', array( 'wcis_notice' => 'error' ) );
		}

		self::redirect_back( 'not_synced', array( 'wcis_notice' => 'stock_enabled' ) );
	}

	/**
	 * Subscriber-side half of Manual Match: apply a SKU value copied from
	 * the master's item straight to one of THIS site's own products. Pure
	 * local write, no REST call needed — the value was already fetched
	 * from the master to build the dropdown.
	 */
	public static function handle_apply_local_sku() {
		self::check_cap();
		check_admin_referer( 'wcis_apply_local_sku' );

		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$raw_data     = isset( $_POST['sku_data'] ) ? json_decode( wp_unslash( $_POST['sku_data'] ), true ) : null;
		$sku          = is_array( $raw_data ) && isset( $raw_data['sku'] ) ? sanitize_text_field( $raw_data['sku'] ) : '';
		$quantity     = is_array( $raw_data ) && isset( $raw_data['quantity'] ) && null !== $raw_data['quantity'] ? intval( $raw_data['quantity'] ) : null;
		$stock_status = is_array( $raw_data ) && ! empty( $raw_data['stock_status'] ) ? sanitize_text_field( $raw_data['stock_status'] ) : '';
		$product      = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product || ! $sku ) {
			self::redirect_back( 'manual_match', array( 'wcis_notice' => 'error' ) );
		}
		if ( $product->get_sku() ) {
			self::redirect_back( 'manual_match', array( 'wcis_notice' => 'sku_exists' ) );
		}
		$taken_by = wc_get_product_id_by_sku( $sku );
		if ( $taken_by && (int) $taken_by !== $product_id ) {
			self::redirect_back( 'manual_match', array( 'wcis_notice' => 'error' ) );
		}

		$product->set_sku( $sku );
		$product->save();

		// The SKU alone doesn't bring the quantity with it -- without this,
		// this site would sit at whatever stock it already had until the
		// next reconciliation cycle. Apply the master's quantity (captured
		// when the dropdown was built) right now so the match is complete.
		if ( null !== $quantity ) {
			if ( ! $product->managing_stock() ) {
				$product->set_manage_stock( true );
			}
			$new_stock = wc_update_product_stock( $product, $quantity, 'set' );
			if ( false !== $new_stock && $stock_status ) {
				$product = wc_get_product( $product_id );
				if ( $product && $product->get_stock_status() !== $stock_status ) {
					$product->set_stock_status( $stock_status );
					$product->save();
				}
			}
		}

		WCIS_Logger::log(
			array(
				'direction'  => 'incoming',
				'event'      => 'sku_match',
				'sku'        => $sku,
				'product_id' => $product_id,
				'qty_after'  => $quantity,
				'status'     => 'success',
				'message'    => 'SKU assigned via manual match against the master.',
			)
		);

		self::redirect_back( 'manual_match', array( 'wcis_notice' => 'match_applied' ) );
	}

	/**
	 * Master-side half of Manual Match: make sure this site's own chosen
	 * item has a SKU (generating one if it doesn't — a master is always
	 * allowed to assign its own SKUs), then push that value to a specific
	 * product on the chosen subscriber via the /apply-sku REST route.
	 */
	public static function handle_push_sku_to_subscriber() {
		self::check_cap();
		check_admin_referer( 'wcis_push_sku_to_subscriber' );

		$product_id        = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$subscriber_id     = isset( $_POST['subscriber_id'] ) ? absint( $_POST['subscriber_id'] ) : 0;
		$remote_product_id = isset( $_POST['remote_product_id'] ) ? absint( $_POST['remote_product_id'] ) : 0;

		$product    = $product_id ? wc_get_product( $product_id ) : false;
		$subscriber = $subscriber_id ? WCIS_Master::get_subscriber( $subscriber_id ) : false;

		if ( ! $product || ! $subscriber || ! $remote_product_id ) {
			self::redirect_back( 'manual_match', array( 'wcis_notice' => 'error', 'sub_id' => $subscriber_id ) );
		}

		$sku = $product->get_sku();
		if ( ! $sku ) {
			$candidate = 'AUTOGEN-' . $product_id;
			$taken_by  = wc_get_product_id_by_sku( $candidate );
			if ( $taken_by && (int) $taken_by !== $product_id ) {
				self::redirect_back( 'manual_match', array( 'wcis_notice' => 'error', 'sub_id' => $subscriber_id ) );
			}
			$product->set_sku( $candidate );
			$product->save();
			$sku = $candidate;
		}

		$result = WCIS_Http_Client::post(
			WCIS_Http_Client::build_url( $subscriber->site_url, 'apply-sku' ),
			$subscriber->api_key,
			$subscriber->api_secret,
			array(
				'product_id' => $remote_product_id,
				'sku'        => $sku,
			)
		);

		WCIS_Logger::log(
			array(
				'direction'   => 'outgoing',
				'event'       => 'sku_match',
				'remote_name' => $subscriber->name,
				'sku'         => $sku,
				'product_id'  => $product_id,
				'status'      => $result['ok'] ? 'success' : 'error',
				'message'     => $result['ok'] ? '' : $result['error'],
			)
		);

		// The SKU landing doesn't push a quantity on its own -- without
		// this, the subscriber sits at whatever stock it already had until
		// the next reconciliation cycle or the next time this product's
		// stock happens to change. Push the master's current quantity right
		// now so the match is actually complete, not just linked.
		if ( $result['ok'] && $product->managing_stock() ) {
			WCIS_Master::send_update_stock( $subscriber, $sku, (int) $product->get_stock_quantity(), $product->get_stock_status(), $product_id );
		}

		self::redirect_back(
			'manual_match',
			array(
				'wcis_notice' => $result['ok'] ? 'match_applied' : 'connection_failed',
				'sub_id'      => $subscriber_id,
			)
		);
	}

	public static function notices() {
		if ( empty( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] || empty( $_GET['wcis_notice'] ) ) {
			return;
		}
		$notice = sanitize_text_field( wp_unslash( $_GET['wcis_notice'] ) );
		$map    = array(
			'settings_saved'     => array( 'success', __( 'Settings saved.', 'wc-inventory-sync' ) ),
			'subscriber_added'   => array( 'success', __( "Subscriber store added. Copy its connection details below into that store's plugin settings.", 'wc-inventory-sync' ) ),
			'subscriber_deleted' => array( 'success', __( 'Subscriber store removed.', 'wc-inventory-sync' ) ),
			'sync_started'       => array( 'success', __( 'Full sync started.', 'wc-inventory-sync' ) ),
			'connection_ok'      => array( 'success', __( 'Connection successful.', 'wc-inventory-sync' ) ),
			'connection_failed'  => array( 'error', __( 'Connection failed. Check the URL and keys and try again.', 'wc-inventory-sync' ) ),
			'requeued'           => array( 'success', __( 'Failed items re-queued for retry.', 'wc-inventory-sync' ) ),
			'sku_generated'      => array( 'success', __( 'SKU generated on this site. Remember to set the same SKU on the matching product on your other store(s) — that\'s what actually links them for sync.', 'wc-inventory-sync' ) ),
			'sku_exists'         => array( 'error', __( 'That product already has a SKU.', 'wc-inventory-sync' ) ),
			'stock_enabled'      => array( 'success', __( 'Stock management enabled and quantity saved.', 'wc-inventory-sync' ) ),
			'match_applied'      => array( 'success', __( 'Matched — the SKU is now set on both products, so they\'ll sync from here on.', 'wc-inventory-sync' ) ),
			'gsheets_test_ok'    => array( 'success', __( 'Connected — check your sheet for the test row.', 'wc-inventory-sync' ) ),
			'gsheets_test_failed' => array( 'error', __( 'Could not write to the sheet — see the error below.', 'wc-inventory-sync' ) ),
			'gsheets_exported'   => array( 'success', __( 'Export ran. Check the sheet, and the status below.', 'wc-inventory-sync' ) ),
			'error'              => array( 'error', __( 'Something went wrong. Please check the form and try again.', 'wc-inventory-sync' ) ),
		);
		if ( isset( $map[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $notice ][0] ), esc_html( $map[ $notice ][1] ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	public static function render_page() {
		self::check_cap();
		$role = get_option( 'wcis_role', '' );
		$tab  = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		?>
		<div class="wrap wcis-wrap">
			<h1><?php esc_html_e( 'WC Inventory Sync', 'wc-inventory-sync' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="nav-tab <?php echo '' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Setup', 'wc-inventory-sync' ); ?></a>
				<?php if ( 'master' === $role ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=subscribers' ) ); ?>" class="nav-tab <?php echo 'subscribers' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Subscriber Stores', 'wc-inventory-sync' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=subscriber_issues' ) ); ?>" class="nav-tab <?php echo 'subscriber_issues' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Subscriber Issues', 'wc-inventory-sync' ); ?></a>
				<?php endif; ?>
				<?php if ( 'subscriber' === $role ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=connection' ) ); ?>" class="nav-tab <?php echo 'connection' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Master Connection', 'wc-inventory-sync' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=not_synced' ) ); ?>" class="nav-tab <?php echo 'not_synced' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Not Synced', 'wc-inventory-sync' ); ?></a>
				<?php if ( 'master' === $role || 'subscriber' === $role ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=manual_match' ) ); ?>" class="nav-tab <?php echo 'manual_match' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Manual Match', 'wc-inventory-sync' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=log' ) ); ?>" class="nav-tab <?php echo 'log' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Sync Log', 'wc-inventory-sync' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=outbox' ) ); ?>" class="nav-tab <?php echo 'outbox' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Retry Queue', 'wc-inventory-sync' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=gsheets' ) ); ?>" class="nav-tab <?php echo 'gsheets' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Google Sheets', 'wc-inventory-sync' ); ?></a>
			</h2>
			<div class="wcis-tab-content" style="margin-top:20px;">
				<?php
				switch ( $tab ) {
					case 'subscribers':
						self::render_subscribers_tab();
						break;
					case 'connection':
						self::render_connection_tab();
						break;
					case 'subscriber_issues':
						self::render_subscriber_issues_tab();
						break;
					case 'not_synced':
						self::render_not_synced_tab();
						break;
					case 'manual_match':
						self::render_manual_match_tab();
						break;
					case 'log':
						self::render_log_tab();
						break;
					case 'outbox':
						self::render_outbox_tab();
						break;
					case 'gsheets':
						self::render_gsheets_tab();
						break;
					default:
						self::render_setup_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	protected static function render_setup_tab() {
		$role    = get_option( 'wcis_role', '' );
		$label   = get_option( 'wcis_site_label', get_bloginfo( 'name' ) );
		$minutes = get_option( 'wcis_reconcile_interval_minutes', 15 );
		$cleanup = get_option( 'wcis_uninstall_cleanup', 0 );
		?>
		<p><?php esc_html_e( 'Choose the role this store plays in your inventory sync network, then configure it in the tab that appears above. Products are matched between stores by SKU — use the same SKU for the same product on every store.', 'wc-inventory-sync' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wcis_save_settings' ); ?>
			<input type="hidden" name="action" value="wcis_save_settings" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wcis_role"><?php esc_html_e( 'Role', 'wc-inventory-sync' ); ?></label></th>
					<td>
						<select name="wcis_role" id="wcis_role">
							<option value="" <?php selected( $role, '' ); ?>><?php esc_html_e( '— Not configured —', 'wc-inventory-sync' ); ?></option>
							<option value="master" <?php selected( $role, 'master' ); ?>><?php esc_html_e( 'Master (source of truth)', 'wc-inventory-sync' ); ?></option>
							<option value="subscriber" <?php selected( $role, 'subscriber' ); ?>><?php esc_html_e( 'Subscriber (receives stock from a master)', 'wc-inventory-sync' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wcis_site_label"><?php esc_html_e( 'Site label', 'wc-inventory-sync' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" name="wcis_site_label" id="wcis_site_label" value="<?php echo esc_attr( $label ); ?>" />
						<p class="description"><?php esc_html_e( 'A friendly name shown in the sync log on the other side of the connection.', 'wc-inventory-sync' ); ?></p>
					</td>
				</tr>
				<?php if ( 'master' === $role ) : ?>
				<tr>
					<th scope="row"><label for="wcis_reconcile_interval_minutes"><?php esc_html_e( 'Full reconciliation interval', 'wc-inventory-sync' ); ?></label></th>
					<td>
						<input type="number" min="0" step="1" name="wcis_reconcile_interval_minutes" id="wcis_reconcile_interval_minutes" value="<?php echo esc_attr( $minutes ); ?>" class="small-text" /> <?php esc_html_e( 'minutes (0 = disabled)', 'wc-inventory-sync' ); ?>
						<p class="description"><?php esc_html_e( 'In addition to instant, real-time pushes on every stock change, the master periodically pushes its full catalog to every subscriber as a safety net (e.g. after a subscriber was briefly offline). Not required for sync to work, but keeps everything self-healing.', 'wc-inventory-sync' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'On uninstall', 'wc-inventory-sync' ); ?></th>
					<td>
						<label><input type="checkbox" name="wcis_uninstall_cleanup" value="1" <?php checked( $cleanup, 1 ); ?> /> <?php esc_html_e( 'Delete all Inventory Sync data (connections, log, retry queue) when this plugin is deleted', 'wc-inventory-sync' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save', 'wc-inventory-sync' ) ); ?>
		</form>

		<?php if ( 'master' === $role ) : ?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=subscribers' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Add subscriber stores →', 'wc-inventory-sync' ); ?></a></p>
		<?php elseif ( 'subscriber' === $role ) : ?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=connection' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Connect to your master store →', 'wc-inventory-sync' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	protected static function render_subscribers_tab() {
		$subscribers = WCIS_Master::get_subscribers();
		?>
		<h2><?php esc_html_e( 'Add a subscriber store', 'wc-inventory-sync' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wcis_add_subscriber' ); ?>
			<input type="hidden" name="action" value="wcis_add_subscriber" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="name"><?php esc_html_e( 'Name', 'wc-inventory-sync' ); ?></label></th>
					<td><input type="text" class="regular-text" name="name" id="name" placeholder="<?php esc_attr_e( 'e.g. Downtown Store', 'wc-inventory-sync' ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="site_url"><?php esc_html_e( 'Site URL', 'wc-inventory-sync' ); ?></label></th>
					<td><input type="url" class="regular-text" name="site_url" id="site_url" placeholder="https://subscriber-store.example.com" required /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Add Subscriber Store', 'wc-inventory-sync' ) ); ?>
		</form>

		<hr />
		<h2><?php esc_html_e( 'Connected subscriber stores', 'wc-inventory-sync' ); ?></h2>
		<?php if ( empty( $subscribers ) ) : ?>
			<p><?php esc_html_e( 'No subscriber stores yet.', 'wc-inventory-sync' ); ?></p>
		<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Site URL', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Last success', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Last error', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Connection info', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wc-inventory-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $subscribers as $s ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $s->name ); ?></strong></td>
					<td><?php echo esc_html( $s->site_url ); ?></td>
					<td><?php echo esc_html( ucfirst( $s->status ) ); ?></td>
					<td><?php echo $s->last_success_at ? esc_html( human_time_diff( strtotime( $s->last_success_at ) ) . ' ago' ) : '—'; ?></td>
					<td><?php echo $s->last_error ? '<span style="color:#b32d2e">' . esc_html( $s->last_error ) . '</span>' : '—'; ?></td>
					<td>
						<details>
							<summary><?php esc_html_e( 'Show', 'wc-inventory-sync' ); ?></summary>
							<p>
								<?php esc_html_e( 'Master URL:', 'wc-inventory-sync' ); ?> <code><?php echo esc_html( home_url() ); ?></code><br />
								<?php esc_html_e( 'API Key:', 'wc-inventory-sync' ); ?> <code><?php echo esc_html( $s->api_key ); ?></code><br />
								<?php esc_html_e( 'API Secret:', 'wc-inventory-sync' ); ?> <code><?php echo esc_html( $s->api_secret ); ?></code>
							</p>
						</details>
					</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'wcis_test_subscriber_connection' ); ?>
							<input type="hidden" name="action" value="wcis_test_subscriber_connection" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $s->id ); ?>" />
							<button class="button"><?php esc_html_e( 'Test', 'wc-inventory-sync' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'wcis_force_sync' ); ?>
							<input type="hidden" name="action" value="wcis_force_sync" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $s->id ); ?>" />
							<button class="button"><?php esc_html_e( 'Full Sync Now', 'wc-inventory-sync' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'wcis_toggle_subscriber' ); ?>
							<input type="hidden" name="action" value="wcis_toggle_subscriber" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $s->id ); ?>" />
							<input type="hidden" name="status" value="<?php echo esc_attr( $s->status ); ?>" />
							<button class="button"><?php echo 'active' === $s->status ? esc_html__( 'Pause', 'wc-inventory-sync' ) : esc_html__( 'Activate', 'wc-inventory-sync' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this subscriber store?', 'wc-inventory-sync' ) ); ?>');">
							<?php wp_nonce_field( 'wcis_delete_subscriber' ); ?>
							<input type="hidden" name="action" value="wcis_delete_subscriber" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $s->id ); ?>" />
							<button class="button button-link-delete"><?php esc_html_e( 'Remove', 'wc-inventory-sync' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wcis_force_sync' ); ?>
				<input type="hidden" name="action" value="wcis_force_sync" />
				<input type="hidden" name="id" value="0" />
				<button class="button button-primary"><?php esc_html_e( 'Full Sync — All Subscribers Now', 'wc-inventory-sync' ); ?></button>
			</form>
		</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Master-only: pick a subscriber and see everything currently wrong
	 * involving just that store — recent error events (both directions:
	 * pushes the master sent it that failed, and sales/restocks it
	 * reported that the master couldn't apply) plus anything still stuck
	 * in the retry queue for it. WCIS_Logger rows are tagged with
	 * remote_name for both directions already, so filtering by that one
	 * field covers the whole relationship.
	 */
	protected static function render_subscriber_issues_tab() {
		$subscribers = WCIS_Master::get_subscribers();
		if ( empty( $subscribers ) ) {
			echo '<p>' . esc_html__( 'No subscriber stores yet.', 'wc-inventory-sync' ) . '</p>';
			return;
		}

		$selected_id = isset( $_GET['sub_id'] ) ? absint( $_GET['sub_id'] ) : (int) $subscribers[0]->id;
		$selected    = null;
		foreach ( $subscribers as $s ) {
			if ( (int) $s->id === $selected_id ) {
				$selected = $s;
				break;
			}
		}
		if ( ! $selected ) {
			$selected    = $subscribers[0];
			$selected_id = (int) $selected->id;
		}
		?>
		<p><?php esc_html_e( 'Pick a subscriber store to see everything currently wrong involving just that store — failed pushes to it, sales it reported that couldn\'t be applied, and anything still stuck retrying.', 'wc-inventory-sync' ); ?></p>

		<p class="subsubsub" style="margin-bottom:16px;">
			<?php foreach ( $subscribers as $i => $s ) : ?>
				<?php $error_count = (int) WCIS_Logger::count_total( array( 'remote_name' => $s->name, 'status' => 'error' ) ); ?>
				<a href="<?php echo esc_url( add_query_arg( 'sub_id', $s->id, admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=subscriber_issues' ) ) ); ?>" class="<?php echo (int) $s->id === $selected_id ? 'current' : ''; ?>">
					<?php echo esc_html( $s->name ); ?>
					<?php if ( $error_count > 0 ) : ?>
						<span class="count">(<?php echo esc_html( $error_count ); ?>)</span>
					<?php endif; ?>
				</a>
				<?php echo $i < count( $subscribers ) - 1 ? ' | ' : ''; ?>
			<?php endforeach; ?>
		</p>

		<h2>
			<?php echo esc_html( $selected->name ); ?>
			<span style="font-weight:normal;font-size:14px;">
				&mdash; <?php echo esc_html( $selected->site_url ); ?>
				&mdash; <?php echo esc_html( ucfirst( $selected->status ) ); ?>
			</span>
		</h2>

		<?php if ( $selected->last_error ) : ?>
			<p><strong><?php esc_html_e( 'Most recent connection error:', 'wc-inventory-sync' ); ?></strong> <span style="color:#b32d2e"><?php echo esc_html( $selected->last_error ); ?></span></p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Still queued for retry', 'wc-inventory-sync' ); ?></h3>
		<?php $outbox_items = WCIS_Outbox::get_for_subscriber( $selected_id ); ?>
		<?php if ( empty( $outbox_items ) ) : ?>
			<p><?php esc_html_e( 'Nothing queued.', 'wc-inventory-sync' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Queued', 'wc-inventory-sync' ); ?></th>
						<th><?php esc_html_e( 'Endpoint', 'wc-inventory-sync' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'wc-inventory-sync' ); ?></th>
						<th><?php esc_html_e( 'Next try', 'wc-inventory-sync' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wc-inventory-sync' ); ?></th>
						<th><?php esc_html_e( 'Last error', 'wc-inventory-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $outbox_items as $o ) : ?>
					<tr>
						<td><?php echo esc_html( $o->created_at ); ?></td>
						<td><code><?php echo esc_html( $o->endpoint ); ?></code></td>
						<td><?php echo esc_html( $o->attempts ); ?></td>
						<td><?php echo esc_html( $o->next_attempt_at ); ?></td>
						<td><?php echo esc_html( ucfirst( $o->status ) ); ?></td>
						<td><?php echo esc_html( $o->last_error ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Recent errors', 'wc-inventory-sync' ); ?></h3>
		<?php
		$error_rows = WCIS_Logger::get_recent(
			array(
				'remote_name' => $selected->name,
				'status'      => 'error',
				'per_page'    => 50,
			)
		);
		?>
		<?php if ( empty( $error_rows ) ) : ?>
			<p><?php esc_html_e( 'No errors logged for this store.', 'wc-inventory-sync' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'wc-inventory-sync' ); ?></th>
						<th><?php esc_html_e( 'Dir', 'wc-inventory-sync' ); ?></th>
						<th><?php esc_html_e( 'Event', 'wc-inventory-sync' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'wc-inventory-sync' ); ?></th>
						<th><?php esc_html_e( 'Message', 'wc-inventory-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $error_rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><?php echo esc_html( $row->direction ); ?></td>
						<td><?php echo esc_html( $row->event ); ?></td>
						<td><code><?php echo esc_html( $row->sku ); ?></code></td>
						<td><?php echo esc_html( $row->message ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Not synced on this store', 'wc-inventory-sync' ); ?></h3>
		<?php
		$remote = WCIS_Http_Client::post(
			WCIS_Http_Client::build_url( $selected->site_url, 'not-synced' ),
			$selected->api_key,
			$selected->api_secret,
			array(),
			20
		);
		?>
		<?php if ( ! $remote['ok'] ) : ?>
			<p><span style="color:#b32d2e"><?php esc_html_e( 'Could not fetch this list from the store right now:', 'wc-inventory-sync' ); ?></span> <?php echo esc_html( $remote['error'] ); ?></p>
		<?php else : ?>
			<?php $remote_issues = isset( $remote['body']['issues'] ) && is_array( $remote['body']['issues'] ) ? $remote['body']['issues'] : array(); ?>
			<?php if ( empty( $remote_issues ) ) : ?>
				<p><?php esc_html_e( 'Nothing found — every published, stock-managed product on this store has a SKU and is eligible to sync.', 'wc-inventory-sync' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: subscriber store name */
						esc_html__( 'Pulled live from %s just now. Fixing these has to happen on that store itself — use the link to jump to its own Not Synced tab.', 'wc-inventory-sync' ),
						esc_html( $selected->name )
					);
					?>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'wc-inventory-sync' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wc-inventory-sync' ); ?></th>
							<th><?php esc_html_e( 'SKU', 'wc-inventory-sync' ); ?></th>
							<th><?php esc_html_e( 'Manage stock', 'wc-inventory-sync' ); ?></th>
							<th><?php esc_html_e( 'Why it\'s skipped', 'wc-inventory-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $remote_issues as $item ) : ?>
						<tr>
							<td><?php echo esc_html( ! empty( $item['name'] ) ? $item['name'] : ( '#' . ( $item['id'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( $item['type'] ?? '' ); ?></td>
							<td><?php echo ! empty( $item['sku'] ) ? '<code>' . esc_html( $item['sku'] ) . '</code>' : '<em>' . esc_html__( 'missing', 'wc-inventory-sync' ) . '</em>'; ?></td>
							<td><?php echo ! empty( $item['manages'] ) ? esc_html__( 'Yes', 'wc-inventory-sync' ) : esc_html__( 'No', 'wc-inventory-sync' ); ?></td>
							<td><?php echo esc_html( implode( '; ', (array) ( $item['reasons'] ?? array() ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><a href="<?php echo esc_url( trailingslashit( $selected->site_url ) . 'wp-admin/admin.php?page=' . self::PAGE_SLUG . '&tab=not_synced' ); ?>" target="_blank" rel="noopener noreferrer" class="button"><?php echo esc_html( sprintf( __( 'Fix on %s →', 'wc-inventory-sync' ), $selected->name ) ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	protected static function render_connection_tab() {
		$master_url = get_option( 'wcis_master_url', '' );
		$master_key = get_option( 'wcis_master_key', '' );
		$last_check = get_option( 'wcis_last_connection_check', array() );
		?>
		<p><?php esc_html_e( 'Enter the connection details shown on the master store\'s "Subscriber Stores" tab when it added this store.', 'wc-inventory-sync' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wcis_save_master_connection' ); ?>
			<input type="hidden" name="action" value="wcis_save_master_connection" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="master_url"><?php esc_html_e( 'Master site URL', 'wc-inventory-sync' ); ?></label></th>
					<td><input type="url" class="regular-text" name="master_url" id="master_url" value="<?php echo esc_attr( $master_url ); ?>" placeholder="https://master-store.example.com" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="master_key"><?php esc_html_e( 'API Key', 'wc-inventory-sync' ); ?></label></th>
					<td><input type="text" class="regular-text" name="master_key" id="master_key" value="<?php echo esc_attr( $master_key ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="master_secret"><?php esc_html_e( 'API Secret', 'wc-inventory-sync' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" name="master_secret" id="master_secret" placeholder="<?php echo get_option( 'wcis_master_secret' ) ? esc_attr__( '(unchanged — leave blank to keep current secret)', 'wc-inventory-sync' ) : ''; ?>" />
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Connection', 'wc-inventory-sync' ) ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wcis_test_master_connection' ); ?>
			<input type="hidden" name="action" value="wcis_test_master_connection" />
			<button class="button"><?php esc_html_e( 'Test Connection', 'wc-inventory-sync' ); ?></button>
		</form>

		<?php if ( ! empty( $last_check ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: OK/Failed, 2: message, 3: time ago */
					esc_html__( 'Last check: %1$s — %2$s (%3$s ago)', 'wc-inventory-sync' ),
					$last_check['ok'] ? esc_html__( 'OK', 'wc-inventory-sync' ) : esc_html__( 'Failed', 'wc-inventory-sync' ),
					esc_html( $last_check['message'] ),
					esc_html( human_time_diff( $last_check['time'] ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	protected static function render_log_tab() {
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$sku      = isset( $_GET['sku'] ) ? sanitize_text_field( wp_unslash( $_GET['sku'] ) ) : '';
		$per_page = 50;

		$rows  = WCIS_Logger::get_recent( array( 'paged' => $paged, 'per_page' => $per_page, 'sku' => $sku ) );
		$total = WCIS_Logger::count_total( array( 'sku' => $sku ) );
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="log" />
			<p>
				<input type="text" name="sku" value="<?php echo esc_attr( $sku ); ?>" placeholder="<?php esc_attr_e( 'Filter by SKU', 'wc-inventory-sync' ); ?>" />
				<button class="button"><?php esc_html_e( 'Filter', 'wc-inventory-sync' ); ?></button>
			</p>
		</form>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Dir', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Event', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Remote', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Product', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Before → After', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Delta', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Message', 'wc-inventory-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="10"><?php esc_html_e( 'No sync activity yet.', 'wc-inventory-sync' ); ?></td></tr>
			<?php endif; ?>
			<?php
			$name_cache = array(); // product_id => name, avoids repeat lookups across rows on this page.
			foreach ( $rows as $row ) :
				$product_name = '—';
				if ( $row->product_id ) {
					if ( ! array_key_exists( $row->product_id, $name_cache ) ) {
						$p                             = wc_get_product( $row->product_id );
						$name_cache[ $row->product_id ] = $p ? $p->get_name() : '';
					}
					$product_name = $name_cache[ $row->product_id ] ? $name_cache[ $row->product_id ] : '—';
				}
				?>
				<tr>
					<td><?php echo esc_html( $row->created_at ); ?></td>
					<td><?php echo esc_html( $row->direction ); ?></td>
					<td><?php echo esc_html( $row->event ); ?></td>
					<td><?php echo esc_html( $row->remote_name ); ?></td>
					<td><?php echo esc_html( $product_name ); ?></td>
					<td><code><?php echo esc_html( $row->sku ); ?></code></td>
					<td><?php echo esc_html( ( null !== $row->qty_before ? $row->qty_before : '—' ) . ' → ' . ( null !== $row->qty_after ? $row->qty_after : '—' ) ); ?></td>
					<td><?php echo null !== $row->delta ? esc_html( $row->delta ) : '—'; ?></td>
					<td>
						<span style="color:<?php echo 'success' === $row->status ? '#1a7f37' : '#b32d2e'; ?>">
							<?php echo esc_html( ucfirst( $row->status ) ); ?>
						</span>
					</td>
					<td><?php echo esc_html( $row->message ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $total_pages > 1 ) {
			echo '<p>';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $total_pages,
					)
				)
			);
			echo '</p>';
		}
	}

	protected static function render_not_synced_tab() {
		$data   = WCIS_Diagnostics::get_unsynced_products();
		$issues = $data['issues'];

		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 50;
		$total    = count( $issues );
		$page_items = array_slice( $issues, ( $paged - 1 ) * $per_page, $per_page );
		?>
		<p><?php esc_html_e( 'Products are matched between stores by SKU, and only sync while "Manage stock" is enabled. These published products are missing one or both of those, so they are currently being skipped — fix them here and they will start syncing on the next stock change or full sync.', 'wc-inventory-sync' ); ?></p>
		<p class="description"><?php esc_html_e( '"Generate SKU" only sets a value on THIS site. Sync matches products by SKU across stores, so for it to actually link up, the same SKU still needs to exist on the matching product on your other store(s) too — check there once you\'ve generated or set one here.', 'wc-inventory-sync' ); ?></p>

		<?php if ( $data['truncated'] ) : ?>
			<p class="notice notice-warning" style="padding:8px 12px;">
				<?php
				printf(
					/* translators: %d: number of products scanned */
					esc_html__( 'This scan stops after the first %d published products for performance — there may be more unsynced products beyond that.', 'wc-inventory-sync' ),
					(int) $data['scanned']
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( empty( $issues ) ) : ?>
			<p><?php esc_html_e( 'Nothing found — every published, stock-managed product has a SKU and is eligible to sync.', 'wc-inventory-sync' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Type', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Manage stock', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Why it\'s skipped', 'wc-inventory-sync' ); ?></th>
					<th><?php esc_html_e( 'Quick fix', 'wc-inventory-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $page_items as $item ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $item['edit_id'], '' ) ); ?>"><?php echo esc_html( $item['name'] ? $item['name'] : ( '#' . $item['id'] ) ); ?></a></td>
					<td><?php echo esc_html( $item['type'] ); ?></td>
					<td><?php echo $item['sku'] ? '<code>' . esc_html( $item['sku'] ) . '</code>' : '<em>' . esc_html__( 'missing', 'wc-inventory-sync' ) . '</em>'; ?></td>
					<td><?php echo $item['manages'] ? esc_html__( 'Yes', 'wc-inventory-sync' ) : esc_html__( 'No', 'wc-inventory-sync' ); ?></td>
					<td><?php echo esc_html( implode( '; ', $item['reasons'] ) ); ?></td>
					<td>
						<?php if ( ! $item['sku'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:4px;">
								<?php wp_nonce_field( 'wcis_generate_sku' ); ?>
								<input type="hidden" name="action" value="wcis_generate_sku" />
								<input type="hidden" name="product_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
								<button class="button button-small" title="<?php esc_attr_e( 'Sets AUTOGEN-<id> as the SKU on this site only.', 'wc-inventory-sync' ); ?>"><?php esc_html_e( 'Generate SKU', 'wc-inventory-sync' ); ?></button>
							</form>
						<?php endif; ?>
						<?php if ( ! $item['manages'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'wcis_enable_stock_management' ); ?>
								<input type="hidden" name="action" value="wcis_enable_stock_management" />
								<input type="hidden" name="product_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
								<input type="number" min="0" step="1" name="quantity" placeholder="<?php esc_attr_e( 'Qty', 'wc-inventory-sync' ); ?>" required style="width:70px;" />
								<button class="button button-small"><?php esc_html_e( 'Enable + Save Qty', 'wc-inventory-sync' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $total_pages > 1 ) {
			echo '<p>';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $total_pages,
					)
				)
			);
			echo '</p>';
		}
	}

	/**
	 * Manual Match: pair up a not-synced item on this site with the
	 * matching item on the other side, so the same SKU gets assigned to
	 * both and they start syncing. Available on both roles, but only the
	 * master ever writes to another site's products — same trust
	 * direction as the rest of the plugin (report-change/update-stock) —
	 * so the two directions work differently:
	 *
	 * - Subscriber: browse the master's not-synced items (read-only,
	 *   fetched live) that already have a SKU, and copy one onto a local
	 *   not-synced item. Pure local write.
	 * - Master: pick a subscriber, browse ITS not-synced items (fetched
	 *   live), pick a local master item as the source (a SKU is generated
	 *   for it first if it doesn't have one — a master can always assign
	 *   its own SKUs), and push that value to the chosen subscriber
	 *   product over /apply-sku.
	 */
	protected static function render_manual_match_tab() {
		$role = get_option( 'wcis_role', '' );

		if ( 'subscriber' === $role ) {
			self::render_manual_match_as_subscriber();
		} elseif ( 'master' === $role ) {
			self::render_manual_match_as_master();
		} else {
			echo '<p>' . esc_html__( 'Set a role on the Setup tab first.', 'wc-inventory-sync' ) . '</p>';
		}
	}

	protected static function render_manual_match_as_subscriber() {
		$master_url    = get_option( 'wcis_master_url', '' );
		$master_key    = get_option( 'wcis_master_key', '' );
		$master_secret = get_option( 'wcis_master_secret', '' );

		if ( ! $master_url || ! $master_key || ! $master_secret ) {
			echo '<p>' . esc_html__( 'Connect to your master store on the Master Connection tab first.', 'wc-inventory-sync' ) . '</p>';
			return;
		}

		$local_data    = WCIS_Diagnostics::get_unsynced_products();
		$local_targets = array_values(
			array_filter(
				$local_data['issues'],
				function ( $item ) {
					return empty( $item['sku'] );
				}
			)
		);
		?>
		<p><?php esc_html_e( 'Pair one of this site\'s items with the matching item on the master, and its SKU gets copied here so they start syncing.', 'wc-inventory-sync' ); ?></p>

		<?php if ( empty( $local_targets ) ) : ?>
			<p><?php esc_html_e( 'Nothing on this site is missing a SKU right now.', 'wc-inventory-sync' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<?php $remote = WCIS_Http_Client::post( WCIS_Http_Client::build_url( $master_url, 'not-synced' ), $master_key, $master_secret, array(), 20 ); ?>
		<?php if ( ! $remote['ok'] ) : ?>
			<p><span style="color:#b32d2e"><?php esc_html_e( 'Could not fetch the master\'s list right now:', 'wc-inventory-sync' ); ?></span> <?php echo esc_html( $remote['error'] ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<?php
		$remote_issues   = isset( $remote['body']['issues'] ) && is_array( $remote['body']['issues'] ) ? $remote['body']['issues'] : array();
		$remote_with_sku = array_values(
			array_filter(
				$remote_issues,
				function ( $item ) {
					return ! empty( $item['sku'] );
				}
			)
		);
		?>
		<?php if ( empty( $remote_with_sku ) ) : ?>
			<p><?php esc_html_e( 'None of the master\'s not-synced items have a SKU to copy yet — set one on the master first (its own Not Synced tab), then come back here.', 'wc-inventory-sync' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wcis_apply_local_sku' ); ?>
			<input type="hidden" name="action" value="wcis_apply_local_sku" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wcis_master_item"><?php esc_html_e( "Master's item", 'wc-inventory-sync' ); ?></label></th>
					<td>
						<select name="sku_data" id="wcis_master_item" required>
							<option value=""><?php esc_html_e( '— choose —', 'wc-inventory-sync' ); ?></option>
							<?php
							foreach ( $remote_with_sku as $item ) :
								$data = wp_json_encode(
									array(
										'sku'          => $item['sku'],
										'quantity'     => isset( $item['quantity'] ) ? $item['quantity'] : null,
										'stock_status' => isset( $item['stock_status'] ) ? $item['stock_status'] : '',
									)
								);
								?>
								<option value="<?php echo esc_attr( $data ); ?>"><?php echo esc_html( ( ! empty( $item['name'] ) ? $item['name'] : '' ) . ' — ' . $item['sku'] . ( isset( $item['quantity'] ) && null !== $item['quantity'] ? ' (qty ' . $item['quantity'] . ')' : '' ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wcis_local_item"><?php esc_html_e( "This site's item", 'wc-inventory-sync' ); ?></label></th>
					<td>
						<select name="product_id" id="wcis_local_item" required>
							<option value=""><?php esc_html_e( '— choose —', 'wc-inventory-sync' ); ?></option>
							<?php foreach ( $local_targets as $item ) : ?>
								<option value="<?php echo esc_attr( $item['id'] ); ?>"><?php echo esc_html( $item['name'] ? $item['name'] : ( '#' . $item['id'] ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Match', 'wc-inventory-sync' ) ); ?>
		</form>
		<?php
	}

	protected static function render_manual_match_as_master() {
		$subscribers = WCIS_Master::get_subscribers();
		if ( empty( $subscribers ) ) {
			echo '<p>' . esc_html__( 'No subscriber stores yet.', 'wc-inventory-sync' ) . '</p>';
			return;
		}

		$selected_id = isset( $_GET['sub_id'] ) ? absint( $_GET['sub_id'] ) : (int) $subscribers[0]->id;
		$selected    = null;
		foreach ( $subscribers as $s ) {
			if ( (int) $s->id === $selected_id ) {
				$selected = $s;
				break;
			}
		}
		if ( ! $selected ) {
			$selected    = $subscribers[0];
			$selected_id = (int) $selected->id;
		}
		?>
		<p><?php esc_html_e( "Pair one of the master's items with the matching item on a subscriber. The master's SKU (generated first if it doesn't have one yet) gets pushed to that subscriber's product, so they start syncing.", 'wc-inventory-sync' ); ?></p>

		<form method="get" style="margin-bottom:16px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="manual_match" />
			<label for="wcis_sub_select"><?php esc_html_e( 'Subscriber store:', 'wc-inventory-sync' ); ?></label>
			<select name="sub_id" id="wcis_sub_select">
				<?php foreach ( $subscribers as $s ) : ?>
					<option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( (int) $s->id, $selected_id ); ?>><?php echo esc_html( $s->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Load', 'wc-inventory-sync' ); ?></button>
		</form>

		<?php
		// The master's whole catalog, not just its not-synced items -- a
		// product can already be working perfectly (SKU set, syncing fine
		// to every other subscriber) and still need to be picked here to
		// link it up to this particular one.
		$local_data  = WCIS_Diagnostics::get_all_products();
		$local_items = $local_data['items'];
		?>

		<?php if ( empty( $local_items ) ) : ?>
			<p><?php esc_html_e( 'No syncable products found on the master.', 'wc-inventory-sync' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<?php if ( $local_data['truncated'] ) : ?>
			<p class="notice notice-warning" style="padding:8px 12px;">
				<?php
				printf(
					/* translators: %d: number of products scanned */
					esc_html__( 'This list stops after the first %d products for performance — search the dropdown by typing to jump to a specific item, or use the SKU field on the product itself if it\'s not listed.', 'wc-inventory-sync' ),
					(int) $local_data['scanned']
				);
				?>
			</p>
		<?php endif; ?>

		<?php $remote = WCIS_Http_Client::post( WCIS_Http_Client::build_url( $selected->site_url, 'not-synced' ), $selected->api_key, $selected->api_secret, array(), 20 ); ?>
		<?php if ( ! $remote['ok'] ) : ?>
			<p><span style="color:#b32d2e"><?php echo esc_html( sprintf( __( 'Could not fetch %s\'s list right now:', 'wc-inventory-sync' ), $selected->name ) ); ?></span> <?php echo esc_html( $remote['error'] ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<?php
		$remote_issues  = isset( $remote['body']['issues'] ) && is_array( $remote['body']['issues'] ) ? $remote['body']['issues'] : array();
		$remote_targets = array_values(
			array_filter(
				$remote_issues,
				function ( $item ) {
					return empty( $item['sku'] );
				}
			)
		);
		?>
		<?php if ( empty( $remote_targets ) ) : ?>
			<p><?php echo esc_html( sprintf( __( 'Nothing on %s is missing a SKU right now.', 'wc-inventory-sync' ), $selected->name ) ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wcis_push_sku_to_subscriber' ); ?>
			<input type="hidden" name="action" value="wcis_push_sku_to_subscriber" />
			<input type="hidden" name="subscriber_id" value="<?php echo esc_attr( $selected_id ); ?>" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wcis_master_item2"><?php esc_html_e( "Master's item", 'wc-inventory-sync' ); ?></label></th>
					<td>
						<select name="product_id" id="wcis_master_item2" required>
							<option value=""><?php esc_html_e( '— choose —', 'wc-inventory-sync' ); ?></option>
							<?php foreach ( $local_items as $item ) : ?>
								<option value="<?php echo esc_attr( $item['id'] ); ?>">
									<?php
									echo esc_html(
										( $item['name'] ? $item['name'] : ( '#' . $item['id'] ) ) .
										( $item['sku'] ? ' — ' . $item['sku'] : ' — ' . __( 'no SKU yet, one will be generated', 'wc-inventory-sync' ) )
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wcis_remote_item"><?php echo esc_html( sprintf( __( "%s's item", 'wc-inventory-sync' ), $selected->name ) ); ?></label></th>
					<td>
						<select name="remote_product_id" id="wcis_remote_item" required>
							<option value=""><?php esc_html_e( '— choose —', 'wc-inventory-sync' ); ?></option>
							<?php foreach ( $remote_targets as $item ) : ?>
								<option value="<?php echo esc_attr( $item['id'] ?? '' ); ?>"><?php echo esc_html( ! empty( $item['name'] ) ? $item['name'] : ( '#' . ( $item['id'] ?? '' ) ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Match', 'wc-inventory-sync' ) ); ?>
		</form>
		<?php
	}

	protected static function render_outbox_tab() {
		$pending = WCIS_Outbox::count_pending();
		$failed  = WCIS_Outbox::count_failed();
		?>
		<p><?php esc_html_e( 'When a sync request cannot reach the other store right away (it was offline, timed out, etc.), it is queued here and automatically retried every 5 minutes with backoff.', 'wc-inventory-sync' ); ?></p>
		<p>
			<strong><?php esc_html_e( 'Pending:', 'wc-inventory-sync' ); ?></strong> <?php echo esc_html( $pending ); ?>
			&nbsp;&nbsp;
			<strong><?php esc_html_e( 'Failed (gave up after 10 attempts):', 'wc-inventory-sync' ); ?></strong> <?php echo esc_html( $failed ); ?>
		</p>
		<?php if ( $failed > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wcis_requeue_outbox' ); ?>
				<input type="hidden" name="action" value="wcis_requeue_outbox" />
				<button class="button"><?php esc_html_e( 'Retry Failed Items Now', 'wc-inventory-sync' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	protected static function render_gsheets_tab() {
		$enabled        = get_option( 'wcis_gsheets_enabled', 0 );
		$spreadsheet_id = get_option( 'wcis_gsheets_spreadsheet_id', '' );
		$sheet_name     = get_option( 'wcis_gsheets_sheet_name', 'Sync Log' );
		$has_creds      = (bool) get_option( 'wcis_gsheets_service_account_json' );
		$last_test      = get_option( 'wcis_gsheets_last_test', array() );
		$last_export_at = get_option( 'wcis_gsheets_last_export_at', '' );
		$last_error     = get_option( 'wcis_gsheets_last_error', '' );
		$last_id        = (int) get_option( 'wcis_gsheets_last_exported_id', 0 );

		global $wpdb;
		$pending_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wcis_log WHERE id > %d", $last_id ) );
		?>
		<p><?php esc_html_e( 'Every sync event logged here (the same rows as the Sync Log tab) is also batched out to a Google Sheet every 5 minutes, so you can view, share, or build reports on it outside of wp-admin.', 'wc-inventory-sync' ); ?></p>

		<h2><?php esc_html_e( 'One-time Google setup', 'wc-inventory-sync' ); ?></h2>
		<ol>
			<li><?php esc_html_e( 'In Google Cloud Console, create a project (or use an existing one) and enable the "Google Sheets API".', 'wc-inventory-sync' ); ?></li>
			<li><?php esc_html_e( 'Create a Service Account, then create a JSON key for it and download the file.', 'wc-inventory-sync' ); ?></li>
			<li><?php esc_html_e( 'Open the JSON file, copy its entire contents, and paste it below.', 'wc-inventory-sync' ); ?></li>
			<li><?php esc_html_e( 'In the JSON, find "client_email" — it looks like something@your-project.iam.gserviceaccount.com. Share your Google Sheet with that exact email address, as an Editor.', 'wc-inventory-sync' ); ?></li>
			<li><?php esc_html_e( 'In the spreadsheet, make sure a tab with the exact name you\'ll enter below already exists (Google\'s API won\'t create it for you) — then paste the Spreadsheet ID (or the full URL) and that tab name below.', 'wc-inventory-sync' ); ?></li>
		</ol>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wcis_save_gsheets_settings' ); ?>
			<input type="hidden" name="action" value="wcis_save_gsheets_settings" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled', 'wc-inventory-sync' ); ?></th>
					<td><label><input type="checkbox" name="wcis_gsheets_enabled" value="1" <?php checked( $enabled, 1 ); ?> /> <?php esc_html_e( 'Export to Google Sheets', 'wc-inventory-sync' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="spreadsheet_id"><?php esc_html_e( 'Spreadsheet ID or URL', 'wc-inventory-sync' ); ?></label></th>
					<td><input type="text" class="regular-text" name="spreadsheet_id" id="spreadsheet_id" value="<?php echo esc_attr( $spreadsheet_id ); ?>" placeholder="https://docs.google.com/spreadsheets/d/XXXXXXXX/edit" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sheet_name"><?php esc_html_e( 'Tab name', 'wc-inventory-sync' ); ?></label></th>
					<td><input type="text" class="regular-text" name="sheet_name" id="sheet_name" value="<?php echo esc_attr( $sheet_name ); ?>" placeholder="Sync Log" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="service_account_json"><?php esc_html_e( 'Service Account JSON', 'wc-inventory-sync' ); ?></label></th>
					<td>
						<textarea name="service_account_json" id="service_account_json" class="large-text code" rows="6" placeholder="<?php echo $has_creds ? esc_attr__( '(already saved — leave blank to keep it, paste new JSON to replace it)', 'wc-inventory-sync' ) : esc_attr__( 'Paste the full contents of the downloaded JSON key file here', 'wc-inventory-sync' ); ?>"></textarea>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save', 'wc-inventory-sync' ) ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php wp_nonce_field( 'wcis_test_gsheets' ); ?>
			<input type="hidden" name="action" value="wcis_test_gsheets" />
			<button class="button"><?php esc_html_e( 'Test Connection', 'wc-inventory-sync' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php wp_nonce_field( 'wcis_export_gsheets_now' ); ?>
			<input type="hidden" name="action" value="wcis_export_gsheets_now" />
			<button class="button"><?php esc_html_e( 'Export Now', 'wc-inventory-sync' ); ?></button>
		</form>

		<?php if ( ! empty( $last_test ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: OK/Failed, 2: message, 3: time ago */
					esc_html__( 'Last test: %1$s — %2$s (%3$s ago)', 'wc-inventory-sync' ),
					$last_test['ok'] ? esc_html__( 'OK', 'wc-inventory-sync' ) : esc_html__( 'Failed', 'wc-inventory-sync' ),
					esc_html( $last_test['message'] ),
					esc_html( human_time_diff( $last_test['time'] ) )
				);
				?>
			</p>
		<?php endif; ?>

		<p>
			<strong><?php esc_html_e( 'Rows waiting to export:', 'wc-inventory-sync' ); ?></strong> <?php echo esc_html( $pending_count ); ?>
			&nbsp;&nbsp;
			<strong><?php esc_html_e( 'Last successful export:', 'wc-inventory-sync' ); ?></strong> <?php echo $last_export_at ? esc_html( human_time_diff( strtotime( $last_export_at ) ) . ' ago' ) : esc_html__( 'never', 'wc-inventory-sync' ); ?>
		</p>
		<?php if ( $last_error ) : ?>
			<p><strong><?php esc_html_e( 'Last export error:', 'wc-inventory-sync' ); ?></strong> <span style="color:#b32d2e"><?php echo esc_html( $last_error ); ?></span></p>
		<?php endif; ?>

		<p class="description"><?php esc_html_e( 'Note: the Service Account JSON is stored in this site\'s database like the other connection secrets in this plugin — keep normal WordPress security hygiene (strong admin passwords, updated core/plugins) since anyone with database access could read it.', 'wc-inventory-sync' ); ?></p>
		<?php
	}
}
