<?php
namespace AdvancedWcCsvImporter\Services;

use AdvancedWcCsvImporter\Repository\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LoggerService Class
 *
 * Provides organized logging functions and generates downloadable execution logs in CSV format.
 */
class LoggerService {

	/**
	 * Log Repository.
	 *
	 * @var LogRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository = new LogRepository();
	}

	/**
	 * Log a successful row import.
	 *
	 * @param int    $job_id Job ID.
	 * @param int    $row_index Row number.
	 * @param string $sku SKU.
	 * @param string $message Log message.
	 */
	public function log_success( $job_id, $row_index, $sku, $message ) {
		$this->repository->add( $job_id, $row_index, $sku, 'success', array(), $message );
	}

	/**
	 * Log an updated row.
	 *
	 * @param int    $job_id Job ID.
	 * @param int    $row_index Row number.
	 * @param string $sku SKU.
	 * @param string $message Log message.
	 */
	public function log_updated( $job_id, $row_index, $sku, $message ) {
		$this->repository->add( $job_id, $row_index, $sku, 'updated', array(), $message );
	}

	/**
	 * Log a skipped row.
	 *
	 * @param int    $job_id Job ID.
	 * @param int    $row_index Row number.
	 * @param string $sku SKU.
	 * @param string $message Log message.
	 */
	public function log_skipped( $job_id, $row_index, $sku, $message ) {
		$this->repository->add( $job_id, $row_index, $sku, 'skipped', array(), $message );
	}

	/**
	 * Log a warning.
	 *
	 * @param int    $job_id Job ID.
	 * @param int    $row_index Row number.
	 * @param string $sku SKU.
	 * @param string $message Log message.
	 */
	public function log_warning( $job_id, $row_index, $sku, $message ) {
		$this->repository->add( $job_id, $row_index, $sku, 'warning', array(), $message );
	}

	/**
	 * Log a failure.
	 *
	 * @param int    $job_id Job ID.
	 * @param int    $row_index Row number.
	 * @param string $sku SKU.
	 * @param string $message Error message.
	 * @param array  $row_data CSV row values.
	 */
	public function log_failed( $job_id, $row_index, $sku, $message, array $row_data ) {
		$this->repository->add( $job_id, $row_index, $sku, 'failed', $row_data, $message );
	}

	/**
	 * Export Job Logs to a CSV file.
	 *
	 * @param int $job_id Job ID.
	 * @return string|false Downloadable file URL, or false on error.
	 */
	public function export_csv( $job_id ) {
		$upload_dir = wp_upload_dir();
		$import_dir = $upload_dir['basedir'] . '/wc-csv-imports';
		if ( ! file_exists( $import_dir ) ) {
			wp_mkdir_p( $import_dir );
		}

		$log_file_path = $import_dir . '/job_' . $job_id . '_logs.csv';
		$handle = fopen( $log_file_path, 'w' );
		if ( ! $handle ) {
			return false;
		}

		// Write UTF-8 BOM.
		fwrite( $handle, "\xEF\xBB\xBF" );

		// Header.
		fputcsv( $handle, array( 'Log ID', 'Row Index', 'SKU', 'Status', 'Message', 'Retry Count', 'Timestamp' ) );

		$limit = 1000;
		$offset = 0;

		while ( true ) {
			$logs = $this->repository->get_logs_by_job( $job_id, null, $limit, $offset );
			if ( empty( $logs ) ) {
				break;
			}

			foreach ( $logs as $log ) {
				fputcsv( $handle, array(
					$log->id,
					$log->row_index,
					$log->sku,
					strtoupper( $log->status ),
					$log->message,
					$log->retry_count,
					$log->created_at,
				) );
			}

			$offset += $limit;
		}

		fclose( $handle );

		return $upload_dir['baseurl'] . '/wc-csv-imports/job_' . $job_id . '_logs.csv';
	}
}
