<?php
namespace AdvancedWcCsvImporter\Services;

use AdvancedWcCsvImporter\Repository\JobRepository;
use AdvancedWcCsvImporter\Repository\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RetryService Class
 *
 * Manages auditing failed rows from custom logs and re-scheduling them through Action Scheduler.
 */
class RetryService {

	/**
	 * Job Repository.
	 *
	 * @var JobRepository
	 */
	private $job_repo;

	/**
	 * Log Repository.
	 *
	 * @var LogRepository
	 */
	private $log_repo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->job_repo = new JobRepository();
		$this->log_repo = new LogRepository();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'advanced_wc_csv_import_retry_batch', array( $this, 'process_retry_batch' ), 10, 2 );
	}

	/**
	 * Spawn retry actions for a job.
	 *
	 * @param int $job_id Job ID.
	 * @param int $batch_size Configuration batch size.
	 * @return bool True if enqueued successfully.
	 */
	public function start_retry( $job_id, $batch_size = 100 ) {
		$job = $this->job_repo->get( $job_id );
		if ( ! $job ) {
			return false;
		}

		$failed_logs = $this->log_repo->get_failed_logs( $job_id );
		if ( empty( $failed_logs ) ) {
			return false;
		}

		// Update job status.
		$this->job_repo->update( $job_id, array(
			'status' => 'processing',
		) );

		return $this->enqueue_retry_batch( $job_id, $batch_size );
	}

	/**
	 * Enqueue retry batch in Action Scheduler.
	 *
	 * @param int $job_id Job ID.
	 * @param int $batch_size Batch size.
	 * @return bool True on success.
	 */
	public function enqueue_retry_batch( $job_id, $batch_size ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		$args = array(
			'job_id'     => intval( $job_id ),
			'batch_size' => intval( $batch_size ),
		);

		$action_id = as_enqueue_async_action( 'advanced_wc_csv_import_retry_batch', $args, 'wc-csv-imports' );

		return (bool) $action_id;
	}

	/**
	 * Action Scheduler callback to run a specific CSV retry batch.
	 *
	 * @param int $job_id Job ID.
	 * @param int $batch_size Retry batch size.
	 */
	public function process_retry_batch( $job_id, $batch_size ) {
		$job = $this->job_repo->get( $job_id );
		if ( ! $job || 'processing' !== $job->status ) {
			return;
		}

		$failed_logs = $this->log_repo->get_failed_logs( $job_id );
		if ( empty( $failed_logs ) ) {
			// Finished all retries.
			$this->job_repo->update( $job_id, array(
				'status' => 'completed',
			) );
			$logger = new LoggerService();
			$logger->export_csv( $job_id );
			$logger->send_completion_email( $job_id );
			return;
		}

		// Slice batch.
		$batch = array_slice( $failed_logs, 0, $batch_size );

		$mapping = json_decode( $job->column_mapping, true );
		if ( ! is_array( $mapping ) ) {
			return;
		}

		$importer = new ProductImporter();
		$importer->init_settings( $mapping, $job->duplicate_handle, $job->mode );
		$importer->suspend_hooks();

		$processed_delta = 0;
		$failed_delta    = 0;

		global $wpdb;
		$logs_table = \AdvancedWcCsvImporter\Database::get_logs_table();

		foreach ( $batch as $failed_log ) {
			$row_data = json_decode( $failed_log->row_data, true );
			if ( ! is_array( $row_data ) ) {
				continue;
			}

			// Run import.
			$result = $importer->import_row( $row_data );

			if ( 'failed' === $result['status'] ) {
				// Failed again, increment retry counter and update error message.
				$failed_delta++;
				$wpdb->update(
					$logs_table,
					array(
						'retry_count' => $failed_log->retry_count + 1,
						'message'     => sanitize_textarea_field( $result['message'] ),
						'created_at'  => current_time( 'mysql' ),
					),
					array( 'id' => $failed_log->id ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				// Re-imported successfully!
				$processed_delta++;
				// Update log status to successfully retried.
				$wpdb->update(
					$logs_table,
					array(
						'status'      => 'success',
						'message'     => __( 'Retried successfully.', 'advanced-wc-csv-importer' ),
						'retry_count' => $failed_log->retry_count + 1,
						'created_at'  => current_time( 'mysql' ),
					),
					array( 'id' => $failed_log->id ),
					array( '%s', '%s', '%d', '%s' ),
					array( '%d' )
				);
			}
		}

		$importer->resume_hooks();

		// Refresh job and write new counts.
		$job = $this->job_repo->get( $job_id );
		if ( ! $job ) {
			return;
		}

		// When retried successfully, decrement failed_rows and increment processed_rows.
		$new_processed = $job->processed_rows + $processed_delta;
		$new_failed    = max( 0, $job->failed_rows - $processed_delta ); // Note: failed rows are decremented by successfully retried ones.

		$this->job_repo->update( $job_id, array(
			'processed_rows' => $new_processed,
			'failed_rows'    => $new_failed,
		) );

		// Check if there are more failed records to process.
		$remaining_failed = $this->log_repo->get_failed_logs( $job_id );

		if ( empty( $remaining_failed ) ) {
			$this->job_repo->update( $job_id, array(
				'status' => 'completed',
			) );

			// Regenerate logs export.
			$logger = new LoggerService();
			$logger->export_csv( $job_id );
			$logger->send_completion_email( $job_id );
		} else {
			if ( 'processing' === $job->status ) {
				$this->enqueue_retry_batch( $job_id, $batch_size );
			}
		}
	}
}
