<?php
namespace AdvancedWcCsvImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader Class
 *
 * Provides PSR-4-like custom autoloading for AdvancedWcCsvImporter classes.
 */
class Autoloader {

	/**
	 * Register the autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload class files.
	 *
	 * @param string $class Class name.
	 */
	public static function autoload( $class ) {
		// Namespace prefix.
		$prefix = 'AdvancedWcCsvImporter\\';

		// Does the class use our namespace prefix?
		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		// Get relative class name.
		$relative_class = substr( $class, $len );

		// Base directory path.
		$base_dir = ADVANCED_WC_CSV_IMPORTER_PATH;

		// Map namespace directories.
		if ( strncmp( 'Admin\\', $relative_class, 6 ) === 0 ) {
			// Map AdvancedWcCsvImporter\Admin\ to /admin/
			$file_path = $base_dir . 'admin/' . str_replace( '\\', '/', substr( $relative_class, 6 ) ) . '.php';
		} elseif ( strncmp( 'Cli\\', $relative_class, 4 ) === 0 ) {
			// Map AdvancedWcCsvImporter\Cli\ to /cli/
			$file_path = $base_dir . 'cli/' . str_replace( '\\', '/', substr( $relative_class, 4 ) ) . '.php';
		} else {
			// Map AdvancedWcCsvImporter\ to /includes/
			$file_path = $base_dir . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';
		}

		// If the file exists, require it.
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
}
