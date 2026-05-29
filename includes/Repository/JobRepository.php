<?php
namespace AdvancedWcCsvImporter\Repository;

use AdvancedWcCsvImporter\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JobRepository Class
 *
 * Implements the Repository pattern for import jobs in the custom jobs table.
 */
class JobRepository {

	/**
	 * Create a new import job.
	 *
	 * @param string $file_path File path of the uploaded CSV.
	 * @param string $mode Import mode ('live' or 'dry_run').
	 * @param string $duplicate_handle Duplicate handler strategy.
	 * @return int|false Inserted job ID, or false on failure.
	 */
	public function create( $file_path, $mode = 'live', $duplicate_handle = 'skip' ) {
		global $wpdb;
		$table = Database::get_jobs_table();

		$data = array(
			'status'           => 'pending',
			'file_path'        => sanitize_text_field( $file_path ),
			'mode'             => sanitize_text_field( $mode ),
			'duplicate_handle' => sanitize_text_field( $duplicate_handle ),
			'column_mapping'   => '',
			'total_rows'       => 0,
			'processed_rows'   => 0,
			'failed_rows'      => 0,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( $table, $data );

		if ( $result === false ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Fetch a single job by ID.
	 *
	 * @param int $id Job ID.
	 * @return \stdClass|null Job object or null if not found.
	 */
	public function get( $id ) {
		global $wpdb;
		$table = Database::get_jobs_table();
		$query = $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id );
		return $wpdb->get_row( $query );
	}

	/**
	 * Update an import job.
	 *
	 * @param int   $id Job ID.
	 * @param array $data Data array to update.
	 * @return bool True on success, false on failure.
	 */
	public function update( $id, array $data ) {
		global $wpdb;
		$table = Database::get_jobs_table();

		$data['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update(
			$table,
			$data,
			array( 'id' => $id ),
			null,
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Delete a job and its associated logs.
	 *
	 * @param int $id Job ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $id ) {
		global $wpdb;
		$jobs_table = Database::get_jobs_table();
		$logs_table = Database::get_logs_table();

		// Start Transaction.
		$wpdb->query( 'START TRANSACTION' );

		$del_logs = $wpdb->delete( $logs_table, array( 'job_id' => $id ), array( '%d' ) );
		$del_job  = $wpdb->delete( $jobs_table, array( 'id' => $id ), array( '%d' ) );

		if ( $del_job === false || $del_logs === false ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$wpdb->query( 'COMMIT' );
		return true;
	}

	/**
	 * Get history of import jobs.
	 *
	 * @param int $limit Number of items to fetch.
	 * @param int $offset Offset query parameter.
	 * @return array Array of jobs.
	 */
	public function get_all( $limit = 20, $offset = 0 ) {
		global $wpdb;
		$table = Database::get_jobs_table();
		$query = $wpdb->prepare(
			"SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d",
			$limit,
			$offset
		);
		return $wpdb->get_results( $query );
	}

	/**
	 * Get active jobs (validating, processing, paused).
	 *
	 * @return array Active job records.
	 */
	public function get_active() {
		global $wpdb;
		$table = Database::get_jobs_table();
		$query = "SELECT * FROM $table WHERE status IN ('validating', 'processing', 'paused') ORDER BY id DESC";
		return $wpdb->get_results( $query );
	}
}
