<?php
namespace AdvancedWcCsvImporter\Admin;

use AdvancedWcCsvImporter\Repository\JobRepository;
use AdvancedWcCsvImporter\Services\CsvParser;
use AdvancedWcCsvImporter\Services\ImportValidator;
use AdvancedWcCsvImporter\Services\QueueManager;
use AdvancedWcCsvImporter\Services\RetryService;
use AdvancedWcCsvImporter\Services\LoggerService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AjaxHandler Class
 *
 * Implements AJAX endpoints for the import wizard, handling file uploads, progress polling, state management, and file exports securely.
 */
class AjaxHandler {

	/**
	 * Job Repository.
	 *
	 * @var JobRepository
	 */
	private $job_repo;

	/**
	 * Queue Manager.
	 *
	 * @var QueueManager
	 */
	private $queue_manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->job_repo      = new JobRepository();
		$this->queue_manager = new QueueManager();
	}

	/**
	 * Register AJAX hooks.
	 */
	public function register_hooks() {
		$actions = array(
			'wc_csv_upload_file',
			'wc_csv_save_mapping_validate',
			'wc_csv_trigger_import',
			'wc_csv_get_progress',
			'wc_csv_toggle_job',
			'wc_csv_cancel_job',
			'wc_csv_retry_failed_job',
			'wc_csv_download_failed_logs',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, $action ) );
		}
	}

	/**
	 * Verify permissions and nonces securely.
	 *
	 * @param string $action Nonce action.
	 */
	private function verify_request( $action = 'wc_csv_import_nonce' ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Access denied. You do not have permissions to manage WooCommerce imports.', 'advanced-wc-csv-importer' ), 403 );
		}

		check_ajax_referer( $action, 'security' );
	}

	/**
	 * Handle CSV file upload.
	 */
	public function wc_csv_upload_file() {
		$this->verify_request();

		if ( empty( $_FILES['csv_file'] ) ) {
			wp_send_json_error( __( 'No file was uploaded.', 'advanced-wc-csv-importer' ) );
		}

		$file = $_FILES['csv_file'];

		// Verify extension.
		$file_ext = pathinfo( $file['name'], PATHINFO_EXTENSION );
		if ( 'csv' !== strtolower( $file_ext ) ) {
			wp_send_json_error( __( 'Invalid file format. Please upload a CSV file only.', 'advanced-wc-csv-importer' ) );
		}

		// Move upload securely to imports directory.
		$upload_dir = wp_upload_dir();
		$import_dir = $upload_dir['basedir'] . '/wc-csv-imports';
		if ( ! file_exists( $import_dir ) ) {
			wp_mkdir_p( $import_dir );
		}

		$filename  = 'import_' . time() . '_' . sanitize_file_name( $file['name'] );
		$file_path = $import_dir . '/' . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $file_path ) ) {
			wp_send_json_error( __( 'Failed to save the uploaded file on the server.', 'advanced-wc-csv-importer' ) );
		}

		// Create Import Job in DB.
		$job_id = $this->job_repo->create( $file_path );
		if ( ! $job_id ) {
			unlink( $file_path );
			wp_send_json_error( __( 'Failed to create import job record in the database.', 'advanced-wc-csv-importer' ) );
		}

		// Parse Headers.
		$parser = new CsvParser();
		$headers = $parser->get_headers( $file_path );

		if ( empty( $headers ) ) {
			unlink( $file_path );
			$this->job_repo->delete( $job_id );
			wp_send_json_error( __( 'The uploaded CSV file does not contain any headers or rows.', 'advanced-wc-csv-importer' ) );
		}

		wp_send_json_success( array(
			'job_id'  => $job_id,
			'headers' => $headers,
		) );
	}

	/**
	 * Save Mapping Configuration and Run CSV Pre-Validation.
	 */
	public function wc_csv_save_mapping_validate() {
		$this->verify_request();

		$job_id           = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;
		$mapping_raw      = isset( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$duplicate_handle = isset( $_POST['duplicate_handle'] ) ? sanitize_text_field( $_POST['duplicate_handle'] ) : 'skip';
		$mode             = isset( $_POST['import_mode'] ) ? sanitize_text_field( $_POST['import_mode'] ) : 'live';

		$mapping = json_decode( $mapping_raw, true );

		if ( ! $job_id || empty( $mapping ) ) {
			wp_send_json_error( __( 'Missing required parameters.', 'advanced-wc-csv-importer' ) );
		}

		$job = $this->job_repo->get( $job_id );
		if ( ! $job ) {
			wp_send_json_error( __( 'Import job not found.', 'advanced-wc-csv-importer' ) );
		}

		// Save Mapping Details to DB.
		$this->job_repo->update( $job_id, array(
			'column_mapping'   => wp_json_encode( $mapping ),
			'duplicate_handle' => $duplicate_handle,
			'mode'             => $mode,
			'status'           => 'validating',
		) );

		// Run Pre-Import Schema Scan.
		$validator = new ImportValidator();
		$validator->set_mapping( $mapping );
		$report = $validator->validate_file( $job_id, $job->file_path );

		// Save total rows to job record.
		$this->job_repo->update( $job_id, array(
			'total_rows' => $report['total_rows'],
		) );

		wp_send_json_success( array(
			'validation_report' => $report,
		) );
	}

	/**
	 * Start the background Action Scheduler Import Process.
	 */
	public function wc_csv_trigger_import() {
		$this->verify_request();

		$job_id     = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;
		$batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 250;

		if ( ! $job_id ) {
			wp_send_json_error( __( 'Missing Job ID parameter.', 'advanced-wc-csv-importer' ) );
		}

		$success = $this->queue_manager->start_import( $job_id, $batch_size );

		if ( ! $success ) {
			wp_send_json_error( __( 'Failed to queue the background import process.', 'advanced-wc-csv-importer' ) );
		}

		wp_send_json_success( __( 'Import started successfully in the background.', 'advanced-wc-csv-importer' ) );
	}

	/**
	 * Fetch Current Import Job Progress Metrics.
	 */
	public function wc_csv_get_progress() {
		$this->verify_request();

		$job_id = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;
		if ( ! $job_id ) {
			wp_send_json_error( __( 'Missing Job ID.', 'advanced-wc-csv-importer' ) );
		}

		$job = $this->job_repo->get( $job_id );
		if ( ! $job ) {
			wp_send_json_error( __( 'Import job record not found.', 'advanced-wc-csv-importer' ) );
		}

		wp_send_json_success( $job );
	}

	/**
	 * Toggle Import Job between Processing and Paused.
	 */
	public function wc_csv_toggle_job() {
		$this->verify_request();

		$job_id     = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;
		$batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 250;

		$job = $this->job_repo->get( $job_id );
		if ( ! $job ) {
			wp_send_json_error( __( 'Job record not found.', 'advanced-wc-csv-importer' ) );
		}

		if ( 'processing' === $job->status ) {
			$this->queue_manager->pause( $job_id );
		} elseif ( 'paused' === $job->status ) {
			$this->queue_manager->resume( $job_id, $batch_size );
		}

		wp_send_json_success();
	}

	/**
	 * Cancel/Stop Running Import Job.
	 */
	public function wc_csv_cancel_job() {
		$this->verify_request();

		$job_id = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;

		$success = $this->queue_manager->cancel( $job_id );

		if ( ! $success ) {
			wp_send_json_error();
		}

		wp_send_json_success();
	}

	/**
	 * Trigger Background Retry for all Failed Rows in a Job.
	 */
	public function wc_csv_retry_failed_job() {
		$this->verify_request();

		$job_id = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;

		$retry_service = new RetryService();
		$success = $retry_service->start_retry( $job_id, 100 );

		if ( ! $success ) {
			wp_send_json_error( __( 'No failed logs found to retry or scheduler failed.', 'advanced-wc-csv-importer' ) );
		}

		wp_send_json_success();
	}

	/**
	 * Export and Trigger Browser Redirect/Download of Failed Log CSVs.
	 */
	public function wc_csv_download_failed_logs() {
		// Verify GET requests manually.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'advanced-wc-csv-importer' ) );
		}

		check_admin_referer( 'wc_csv_import_nonce', 'security' );

		$job_id = isset( $_GET['job_id'] ) ? intval( $_GET['job_id'] ) : 0;
		if ( ! $job_id ) {
			wp_die( esc_html__( 'Missing Job ID.', 'advanced-wc-csv-importer' ) );
		}

		$logger = new LoggerService();
		$file_url = $logger->export_csv( $job_id );

		if ( $file_url ) {
			wp_redirect( $file_url );
			exit;
		}

		wp_die( esc_html__( 'Failed to export log CSV.', 'advanced-wc-csv-importer' ) );
	}
}
