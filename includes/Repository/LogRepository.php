<?php
namespace AdvancedWcCsvImporter\Repository;

use AdvancedWcCsvImporter\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LogRepository Class
 *
 * Manages access, persistence, and batch exports for the custom logs database table.
 */
class LogRepository {

	/**
	 * Log a row action.
	 *
	 * @param int    $job_id Job ID.
	 * @param int    $row_index CSV row index.
	 * @param string $sku Product SKU.
	 * @param string $status Log status ('success', 'failed', 'warning', 'skipped', 'updated').
	 * @param array  $row_data Raw row CSV array data.
	 * @param string $message Error message or details.
	 * @param int    $retry_count Optional retry count.
	 * @return int|false Inserted ID, or false on failure.
	 */
	public function add( $job_id, $row_index, $sku, $status, array $row_data, $message, $retry_count = 0 ) {
		global $wpdb;
		$table = Database::get_logs_table();

		// Clean up inputs.
		$data = array(
			'job_id'      => intval( $job_id ),
			'row_index'   => intval( $row_index ),
			'sku'         => sanitize_text_field( $sku ),
			'status'      => sanitize_text_field( $status ),
			'row_data'    => wp_json_encode( $row_data ),
			'message'     => sanitize_textarea_field( $message ),
			'retry_count' => intval( $retry_count ),
			'created_at'  => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( $table, $data );

		if ( $result === false ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Get logs for a given job, optionally filtered by status.
	 *
	 * @param int         $job_id Job ID.
	 * @param string|null $status Optional status.
	 * @param int         $limit Page limit.
	 * @param int         $offset Page offset.
	 * @return array Array of logs.
	 */
	public function get_logs_by_job( $job_id, $status = null, $limit = 100, $offset = 0 ) {
		global $wpdb;
		$table = Database::get_logs_table();

		if ( $status ) {
			$query = $wpdb->prepare(
				"SELECT * FROM $table WHERE job_id = %d AND status = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$job_id,
				$status,
				$limit,
				$offset
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT * FROM $table WHERE job_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$job_id,
				$limit,
				$offset
			);
		}

		return $wpdb->get_results( $query );
	}

	/**
	 * Get all failed logs for retry purposes.
	 *
	 * @param int $job_id Job ID.
	 * @return array Failed logs.
	 */
	public function get_failed_logs( $job_id ) {
		global $wpdb;
		$table = Database::get_logs_table();
		$query = $wpdb->prepare(
			"SELECT * FROM $table WHERE job_id = %d AND status = 'failed' ORDER BY id ASC",
			$job_id
		);
		return $wpdb->get_results( $query );
	}

	/**
	 * Fetch log by row index and job ID.
	 *
	 * @param int $job_id Job ID.
	 * @param int $row_index CSV row index.
	 * @return \stdClass|null Log record or null.
	 */
	public function get_row_log( $job_id, $row_index ) {
		global $wpdb;
		$table = Database::get_logs_table();
		$query = $wpdb->prepare(
			"SELECT * FROM $table WHERE job_id = %d AND row_index = %d",
			$job_id,
			$row_index
		);
		return $wpdb->get_row( $query );
	}

	/**
	 * Delete logs associated with a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True on success, false on failure.
	 */
	public function clear_job_logs( $job_id ) {
		global $wpdb;
		$table = Database::get_logs_table();
		$result = $wpdb->delete( $table, array( 'job_id' => $job_id ), array( '%d' ) );
		return $result !== false;
	}

	/**
	 * Increment retry count on a failed row.
	 *
	 * @param int $job_id Job ID.
	 * @param int $row_index CSV row index.
	 * @return bool True on success.
	 */
	public function increment_retry( $job_id, $row_index ) {
		global $wpdb;
		$table = Database::get_logs_table();
		$query = $wpdb->prepare(
			"UPDATE $table SET retry_count = retry_count + 1, status = 'failed' WHERE job_id = %d AND row_index = %d",
			$job_id,
			$row_index
		);
		return $wpdb->query( $query ) !== false;
	}
}
