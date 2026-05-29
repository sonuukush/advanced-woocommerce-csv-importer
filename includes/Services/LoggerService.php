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

	/**
	 * Send an email notification to the site administrator when an import job is completed.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True if mail sent.
	 */
	public function send_completion_email( $job_id ) {
		$job_repo = new \AdvancedWcCsvImporter\Repository\JobRepository();
		$job = $job_repo->get( $job_id );
		if ( ! $job ) {
			return false;
		}

		$to      = get_option( 'admin_email' );
		$subject = sprintf( __( '[WooCommerce Importer] Import Job #%d Completed', 'advanced-wc-csv-importer' ), $job_id );

		$upload_dir = wp_upload_dir();
		$import_dir = $upload_dir['basedir'] . '/wc-csv-imports';
		$log_file_path = $import_dir . '/job_' . $job_id . '_logs.csv';

		$attachments = array();
		if ( file_exists( $log_file_path ) ) {
			$attachments[] = $log_file_path;
		}

		$mode_str = strtoupper( $job->mode );
		$file_name = esc_html( basename( $job->file_path ) );

		// Beautiful HTML Body
		$body = '
		<html>
		<head>
			<style>
				body { font-family: Arial, sans-serif; line-height: 1.6; color: #1e293b; background-color: #f8fafc; padding: 20px; }
				.container { max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
				.header { text-align: center; border-bottom: 2px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 25px; }
				.header h2 { margin: 0; color: #6366f1; }
				.stats-grid { display: flex; justify-content: space-between; gap: 15px; margin: 20px 0; }
				.stat-card { background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #e2e8f0; width: 30%; }
				.stat-num { font-size: 20px; font-weight: bold; margin-bottom: 4px; }
				.stat-processed { color: #10b981; }
				.stat-failed { color: #ef4444; }
				.details-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
				.details-table td { padding: 10px; border-bottom: 1px solid #e2e8f0; }
				.details-table td:first-child { font-weight: bold; color: #64748b; }
				.footer { text-align: center; margin-top: 30px; font-size: 12px; color: #94a3b8; }
			</style>
		</head>
		<body>
			<div class="container">
				<div class="header">
					<h2>WooCommerce CSV Import Report</h2>
					<p>Your background product import job has completed.</p>
				</div>
				
				<div class="stats-grid">
					<div class="stat-card">
						<div class="stat-num">' . intval( $job->total_rows ) . '</div>
						<div style="font-size: 11px; text-transform: uppercase;">Total Rows</div>
					</div>
					<div class="stat-card">
						<div class="stat-num stat-processed">' . intval( $job->processed_rows ) . '</div>
						<div style="font-size: 11px; text-transform: uppercase;">Imported</div>
					</div>
					<div class="stat-card">
						<div class="stat-num stat-failed">' . intval( $job->failed_rows ) . '</div>
						<div style="font-size: 11px; text-transform: uppercase;">Failed</div>
					</div>
				</div>

				<table class="details-table">
					<tr>
						<td>Job ID</td>
						<td>#' . intval( $job_id ) . '</td>
					</tr>
					<tr>
						<td>File Name</td>
						<td>' . $file_name . '</td>
					</tr>
					<tr>
						<td>Run Mode</td>
						<td>' . $mode_str . '</td>
					</tr>
					<tr>
						<td>Duplicate SKUs</td>
						<td>' . strtoupper( $job->duplicate_handle ) . '</td>
					</tr>
					<tr>
						<td>Completion Status</td>
						<td>SUCCESS</td>
					</tr>
				</table>

				<p style="margin-top: 25px;">The full execution log is attached to this email as a CSV file for your records.</p>

				<div class="footer">
					<p>Sent by Advanced WooCommerce CSV Product Importer</p>
				</div>
			</div>
		</body>
		</html>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return wp_mail( $to, $subject, $body, $headers, $attachments );
	}
}

