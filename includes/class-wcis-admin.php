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
				<?php endif; ?>
				<?php if ( 'subscriber' === $role ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=connection' ) ); ?>" class="nav-tab <?php echo 'connection' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Master Connection', 'wc-inventory-sync' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=not_synced' ) ); ?>" class="nav-tab <?php echo 'not_synced' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Not Synced', 'wc-inventory-sync' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=log' ) ); ?>" class="nav-tab <?php echo 'log' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Sync Log', 'wc-inventory-sync' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=outbox' ) ); ?>" class="nav-tab <?php echo 'outbox' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Retry Queue', 'wc-inventory-sync' ); ?></a>
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
					case 'not_synced':
						self::render_not_synced_tab();
						break;
					case 'log':
						self::render_log_tab();
						break;
					case 'outbox':
						self::render_outbox_tab();
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

	/**
	 * Scan published products/variations for ones that can't participate in
	 * sync yet — same two conditions WCIS_Master/WCIS_Subscriber already
	 * require (a SKU, and stock management enabled) — so the admin can find
	 * and fix them instead of wondering why a product never shows up in the
	 * Sync Log.
	 */
	protected static function get_unsynced_products( $limit_scan = 2000 ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type IN ('product','product_variation')
				 AND post_status = 'publish'
				 ORDER BY ID ASC
				 LIMIT %d",
				$limit_scan
			)
		);

		$issues = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}

			// Grouped/external products have no inventory concept at all in
			// WooCommerce (their edit screen never shows stock fields) — skip.
			if ( $product->is_type( array( 'grouped', 'external' ) ) ) {
				continue;
			}

			// A variable product can manage stock two ways: a shared quantity
			// set on the parent itself (applies to any variation that doesn't
			// override it — WooCommerce shows this explicitly: "Settings
			// below apply to all variations without manual stock management
			// enabled"), or per-variation via manage_stock on each variation
			// individually. Only flag the parent when NEITHER is in use
			// anywhere in the family — that's the one case where nothing
			// here can ever sync. A missing SKU on the parent is checked
			// separately, and only matters if the parent itself is the one
			// supposed to be managing/syncing stock.
			if ( $product->is_type( 'variable' ) ) {
				$family_manages_stock = $product->managing_stock();
				if ( ! $family_manages_stock ) {
					foreach ( $product->get_children() as $variation_id ) {
						$variation = wc_get_product( $variation_id );
						if ( $variation && $variation->managing_stock() ) {
							$family_manages_stock = true;
							break;
						}
					}
				}

				if ( ! $family_manages_stock ) {
					$issues[] = array(
						'id'      => $product->get_id(),
						'edit_id' => $product->get_id(),
						'name'    => $product->get_name(),
						'type'    => $product->get_type(),
						'sku'     => $product->get_sku(),
						'manages' => false,
						'reasons' => array( __( "Neither this product nor any of its variations has stock management enabled", 'wc-inventory-sync' ) ),
					);
				} elseif ( $product->managing_stock() && ! $product->get_sku() ) {
					$issues[] = array(
						'id'      => $product->get_id(),
						'edit_id' => $product->get_id(),
						'name'    => $product->get_name(),
						'type'    => $product->get_type(),
						'sku'     => '',
						'manages' => true,
						'reasons' => array( __( 'No SKU set', 'wc-inventory-sync' ) ),
					);
				}
				continue;
			}

			$reasons = array();
			if ( ! $product->get_sku() ) {
				$reasons[] = __( 'No SKU set', 'wc-inventory-sync' );
			}
			if ( ! $product->managing_stock() ) {
				$reasons[] = __( 'Stock management not enabled', 'wc-inventory-sync' );
			}

			if ( $reasons ) {
				$issues[] = array(
					'id'        => $product->get_id(),
					'edit_id'   => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
					'name'      => $product->get_name(),
					'type'      => $product->get_type(),
					'sku'       => $product->get_sku(),
					'manages'   => $product->managing_stock(),
					'reasons'   => $reasons,
				);
			}
		}

		return array(
			'issues'    => $issues,
			'scanned'   => count( $ids ),
			'truncated' => count( $ids ) === $limit_scan,
		);
	}

	protected static function render_not_synced_tab() {
		$data   = self::get_unsynced_products();
		$issues = $data['issues'];

		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 50;
		$total    = count( $issues );
		$page_items = array_slice( $issues, ( $paged - 1 ) * $per_page, $per_page );
		?>
		<p><?php esc_html_e( 'Products are matched between stores by SKU, and only sync while "Manage stock" is enabled. These published products are missing one or both of those, so they are currently being skipped — fix them here and they will start syncing on the next stock change or full sync.', 'wc-inventory-sync' ); ?></p>

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
}
