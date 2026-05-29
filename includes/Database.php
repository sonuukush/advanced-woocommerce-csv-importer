<?php
namespace AdvancedWcCsvImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database Class
 *
 * Manages database schemas, table installations, and index optimization.
 */
class Database {

	/**
	 * Get the Import Jobs table name.
	 *
	 * @return string Table name with prefix.
	 */
	public static function get_jobs_table() {
		global $wpdb;
		return $wpdb->prefix . 'wc_csv_import_jobs';
	}

	/**
	 * Get the Import Logs table name.
	 *
	 * @return string Table name with prefix.
	 */
	public static function get_logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'wc_csv_import_logs';
	}

	/**
	 * Install/Upgrade Database schemas.
	 */
	public static function install() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$jobs_table = self::get_jobs_table();
		$logs_table = self::get_logs_table();

		// Schema for Jobs Table.
		// Note the double space after PRIMARY KEY for dbDelta compliance.
		$sql_jobs = "CREATE TABLE $jobs_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			status varchar(50) NOT NULL DEFAULT 'pending',
			file_path varchar(255) NOT NULL,
			mode varchar(20) NOT NULL DEFAULT 'live',
			duplicate_handle varchar(20) NOT NULL DEFAULT 'skip',
			column_mapping text NOT NULL,
			total_rows int(11) NOT NULL DEFAULT 0,
			processed_rows int(11) NOT NULL DEFAULT 0,
			failed_rows int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset_collate;";

		// Schema for Logs Table.
		$sql_logs = "CREATE TABLE $logs_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			job_id bigint(20) NOT NULL,
			row_index int(11) NOT NULL,
			sku varchar(100) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'failed',
			row_data longtext NOT NULL,
			message text NOT NULL,
			retry_count int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY sku (sku),
			KEY status (status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_jobs );
		dbDelta( $sql_logs );
	}
}
