<?php
/**
 * Plugin Name: Advanced WooCommerce CSV Product Importer
 * Plugin URI: https://github.com/advanced-wc-csv-importer
 * Description: Professional-grade, high-performance WooCommerce CSV product importer capable of processing 30k+ products in the background. Uses Action Scheduler, custom database logging, and features a premium UI.
 * Version: 1.0.0
 * Author: Antigravity AI
 * Author URI: https://github.com/antigravity
 * Text Domain: advanced-wc-csv-importer
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires At Least: 5.6
 * WC requires at least: 5.0
 * WC prefers at least: 8.0
 *
 * @package AdvancedWcCsvImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define Constants.
define( 'ADVANCED_WC_CSV_IMPORTER_VERSION', '1.0.0' );
define( 'ADVANCED_WC_CSV_IMPORTER_FILE', __FILE__ );
define( 'ADVANCED_WC_CSV_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'ADVANCED_WC_CSV_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

// Load Autoloader.
require_once ADVANCED_WC_CSV_IMPORTER_PATH . 'includes/Autoloader.php';

// Register Autoloader.
\AdvancedWcCsvImporter\Autoloader::register();

/**
 * Activate the plugin.
 */
function advanced_wc_csv_importer_activate() {
	// Initialize custom database tables.
	\AdvancedWcCsvImporter\Database::install();
}
register_activation_hook( __FILE__, 'advanced_wc_csv_importer_activate' );

/**
 * Deactivate the plugin.
 */
function advanced_wc_csv_importer_deactivate() {
	// Optional: Flush schedules or logs if necessary.
}
register_deactivation_hook( __FILE__, 'advanced_wc_csv_importer_deactivate' );

/**
 * Bootstrap the plugin.
 */
function advanced_wc_csv_importer_init() {
	// Check WooCommerce dependency.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'advanced_wc_csv_importer_missing_wc_notice' );
		return;
	}

	// Load the main plugin controller.
	\AdvancedWcCsvImporter\Plugin::get_instance();
}
add_action( 'plugins_loaded', 'advanced_wc_csv_importer_init' );

/**
 * Missing WooCommerce Admin Notice.
 */
function advanced_wc_csv_importer_missing_wc_notice() {
	?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'Advanced WooCommerce CSV Product Importer requires WooCommerce to be active. Please install and activate WooCommerce first.', 'advanced-wc-csv-importer' ); ?></p>
	</div>
	<?php
}
