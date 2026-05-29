<?php
namespace AdvancedWcCsvImporter\Services;

use AdvancedWcCsvImporter\Repository\JobRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * QueueManager Class
 *
 * Integrates directly with WooCommerce Action Scheduler to schedule and run background batches sequentially.
 */
class QueueManager {

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
	 * Register Action Scheduler Hooks.
	 */
	public function register_hooks() {
		add_action( 'advanced_wc_csv_import_batch', array( $this, 'process_batch_queue' ), 10, 3 );
	}

	/**
	 * Trigger the background import process.
	 *
	 * @param int $job_id Job ID.
	 * @param int $batch_size Configuration batch size.
	 * @return bool True if enqueued.
	 */
	public function start_import( $job_id, $batch_size = 250 ) {
		$job = $this->job_repo->get( $job_id );
		if ( ! $job ) {
			return false;
		}

		// Update state to processing.
		$this->job_repo->update( $job_id, array(
			'status' => 'processing',
		) );

		return $this->enqueue_next_batch( $job_id, 0, $batch_size );
	}

	/**
	 * Enqueue a batch action in Action Scheduler.
	 *
	 * @param int $job_id Job ID.
	 * @param int $offset Offset start row index.
	 * @param int $batch_size Batch size.
	 * @return bool True on success.
	 */
	public function enqueue_next_batch( $job_id, $offset, $batch_size ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		$args = array(
			'job_id'     => intval( $job_id ),
			'offset'     => intval( $offset ),
			'batch_size' => intval( $batch_size ),
		);

		$action_id = as_enqueue_async_action( 'advanced_wc_csv_import_batch', $args, 'wc-csv-imports' );

		return (bool) $action_id;
	}

	/**
	 * Action Scheduler callback to run a specific CSV import batch.
	 *
	 * @param int $job_id Job ID.
	 * @param int $offset CSV Row Offset.
	 * @param int $batch_size Product Batch Size.
	 */
	public function process_batch_queue( $job_id, $offset, $batch_size ) {
		$job = $this->job_repo->get( $job_id );
		if ( ! $job ) {
			return;
		}

		// If job is no longer processing (paused, cancelled, failed), abort processing.
		if ( 'processing' !== $job->status ) {
			return;
		}

		$mapping = json_decode( $job->column_mapping, true );
		if ( ! is_array( $mapping ) ) {
			return;
		}

		// Services.
		$parser    = new CsvParser();
		$importer  = new ProductImporter();
		$logger    = new LoggerService();

		// Configure importer.
		$importer->init_settings( $mapping, $job->duplicate_handle, $job->mode );

		// Retrieve chunk rows.
		$rows = $parser->get_rows( $job->file_path, $offset, $batch_size );

		if ( empty( $rows ) ) {
			// All rows processed, complete job.
			$this->job_repo->update( $job_id, array(
				'status' => 'completed',
			) );
			return;
		}

		// Suspend caching and hooks to accelerate inserts.
		$importer->suspend_hooks();

		$processed_delta = 0;
		$failed_delta    = 0;

		foreach ( $rows as $row ) {
			// Double-check job status in loop to react immediately to pause/cancel.
			if ( $processed_delta > 0 && $processed_delta % 10 === 0 ) {
				$current_job = $this->job_repo->get( $job_id );
				if ( ! $current_job || 'processing' !== $current_job->status ) {
					break;
				}
			}

			$result = $importer->import_row( $row['data'] );

			if ( 'failed' === $result['status'] ) {
				$failed_delta++;
				$logger->log_failed( $job_id, $row['index'], $result['sku'], $result['message'], $row['data'] );
			} elseif ( 'skipped' === $result['status'] ) {
				$processed_delta++;
				$logger->log_skipped( $job_id, $row['index'], $result['sku'], $result['message'] );
			} elseif ( 'updated' === $result['status'] ) {
				$processed_delta++;
				$logger->log_updated( $job_id, $row['index'], $result['sku'], $result['message'] );
			} else {
				$processed_delta++;
				$logger->log_success( $job_id, $row['index'], $result['sku'], $result['message'] );
			}
		}

		// Restore hooks.
		$importer->resume_hooks();

		// Reload job to write precise progress increments safely.
		$job = $this->job_repo->get( $job_id );
		if ( ! $job ) {
			return;
		}

		$new_processed = $job->processed_rows + $processed_delta;
		$new_failed    = $job->failed_rows + $failed_delta;
		$new_total_run = $new_processed + $new_failed;

		$this->job_repo->update( $job_id, array(
			'processed_rows' => $new_processed,
			'failed_rows'    => $new_failed,
		) );

		// Check if we are finished.
		if ( $new_total_run >= $job->total_rows ) {
			$this->job_repo->update( $job_id, array(
				'status' => 'completed',
			) );

			// Generate export files once complete.
			$logger->export_csv( $job_id );
		} else {
			// Check if we can proceed.
			if ( 'processing' === $job->status ) {
				$next_offset = $offset + count( $rows );
				$this->enqueue_next_batch( $job_id, $next_offset, $batch_size );
			}
		}
	}

	/**
	 * Pause an active import job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True on success.
	 */
	public function pause( $job_id ) {
		return $this->job_repo->update( $job_id, array( 'status' => 'paused' ) );
	}

	/**
	 * Resume a paused import job.
	 *
	 * @param int $job_id Job ID.
	 * @param int $batch_size Batch size config.
	 * @return bool True on success.
	 */
	public function resume( $job_id, $batch_size = 250 ) {
		$job = $this->job_repo->get( $job_id );
		if ( ! $job || 'paused' !== $job->status ) {
			return false;
		}

		$this->job_repo->update( $job_id, array( 'status' => 'processing' ) );

		// Start index offset.
		$next_offset = $job->processed_rows + $job->failed_rows;

		return $this->enqueue_next_batch( $job_id, $next_offset, $batch_size );
	}

	/**
	 * Cancel an active import job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True on success.
	 */
	public function cancel( $job_id ) {
		return $this->job_repo->update( $job_id, array( 'status' => 'failed' ) );
	}
}
