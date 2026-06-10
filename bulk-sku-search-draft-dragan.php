<?php
/**
 * Plugin Name: Bulk SKU Search & Draft Dragan
 * Description: Search WooCommerce products by up to 500 SKUs at once and bulk-set published matches to draft.
 * Version: 1.1.0
 * Author: Dragan Jovanoski
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * Text Domain: bulk-sku-search-draft-dragan
 */

defined( 'ABSPATH' ) || exit;

define( 'BSSDD_VERSION', '1.1.0' );
define( 'BSSDD_PLUGIN_FILE', __FILE__ );
define( 'BSSDD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BSSDD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BSSDD_MAX_SKUS', apply_filters( 'bssdd_max_skus', 500 ) );
define( 'BSSDD_BATCH_SIZE', apply_filters( 'bssdd_batch_size', 50 ) );
define( 'BSSDD_TRANSIENT_KEY', 'bssdd_search_results' );

require_once BSSDD_PLUGIN_DIR . 'includes/class-sku-parser.php';
require_once BSSDD_PLUGIN_DIR . 'includes/class-sku-finder.php';
require_once BSSDD_PLUGIN_DIR . 'includes/class-draft-processor.php';
require_once BSSDD_PLUGIN_DIR . 'includes/class-sku-updater.php';
require_once BSSDD_PLUGIN_DIR . 'includes/class-admin-page.php';

/**
 * Bootstrap the plugin after plugins are loaded.
 */
function bssdd_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'bssdd_woocommerce_missing_notice' );
		return;
	}

	BSSDD_Admin_Page::instance();
}
add_action( 'plugins_loaded', 'bssdd_init' );

/**
 * Show notice when WooCommerce is not active.
 */
function bssdd_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Bulk SKU Search & Draft Dragan requires WooCommerce to be installed and active.', 'bulk-sku-search-draft-dragan' )
	);
}
