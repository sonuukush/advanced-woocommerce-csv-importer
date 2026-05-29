<?php
namespace AdvancedWcCsvImporter\Admin;

use AdvancedWcCsvImporter\Repository\JobRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller Class
 *
 * Manages admin menu hooks, script loading, and localization variables.
 */
class Controller {

	/**
	 * Job Repository.
	 *
	 * @var JobRepository
	 */
	private $job_repo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->job_repo = new JobRepository();
	}

	/**
	 * Initialize admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register Admin Submenu.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Advanced WooCommerce CSV Importer', 'advanced-wc-csv-importer' ),
			__( 'CSV Importer', 'advanced-wc-csv-importer' ),
			'manage_woocommerce',
			'wc-csv-importer',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue Styles and Scripts.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_wc-csv-importer' !== $hook ) {
			return;
		}

		// Enqueue Font.
		wp_enqueue_style( 'google-font-outfit', 'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap', array(), ADVANCED_WC_CSV_IMPORTER_VERSION );

		// Stylesheet.
		wp_enqueue_style(
			'advanced-wc-csv-importer-style',
			ADVANCED_WC_CSV_IMPORTER_URL . 'admin/assets/css/admin-style.css',
			array(),
			ADVANCED_WC_CSV_IMPORTER_VERSION
		);

		// Script.
		wp_enqueue_script(
			'advanced-wc-csv-importer-script',
			ADVANCED_WC_CSV_IMPORTER_URL . 'admin/assets/js/admin-script.js',
			array( 'jquery' ),
			ADVANCED_WC_CSV_IMPORTER_VERSION,
			true
		);

		// Localize AJAX config.
		wp_localize_script( 'advanced-wc-csv-importer-script', 'wcCsvImporter', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'wc_csv_import_nonce' ),
			'i18n'      => array(
				'uploading'   => __( 'Uploading CSV...', 'advanced-wc-csv-importer' ),
				'validating'  => __( 'Scanning & Validating...', 'advanced-wc-csv-importer' ),
				'mapping'     => __( 'Parsing structure...', 'advanced-wc-csv-importer' ),
				'processing'  => __( 'Importing products...', 'advanced-wc-csv-importer' ),
				'complete'    => __( 'Finished successfully!', 'advanced-wc-csv-importer' ),
				'failed'      => __( 'Import process aborted.', 'advanced-wc-csv-importer' ),
				'emptyFile'   => __( 'Please select a valid CSV file first.', 'advanced-wc-csv-importer' ),
				'confirmStop' => __( 'Are you sure you want to stop this import?', 'advanced-wc-csv-importer' ),
			),
		) );
	}

	/**
	 * Render the main Admin Interface.
	 */
	public function render_admin_page() {
		// Fetch jobs history.
		$jobs = $this->job_repo->get_all( 10 );

		// Load wizard wrapper.
		include ADVANCED_WC_CSV_IMPORTER_PATH . 'admin/templates/import-wizard.php';
	}
}
