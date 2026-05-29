<?php
namespace AdvancedWcCsvImporter\Cli;

use AdvancedWcCsvImporter\Repository\JobRepository;
use AdvancedWcCsvImporter\Services\CsvParser;
use AdvancedWcCsvImporter\Services\ProductImporter;
use AdvancedWcCsvImporter\Services\LoggerService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CliController Class
 *
 * Implements advanced WP-CLI command triggers for high-volume product imports directly from command-line terminals.
 */
class CliController {

	/**
	 * Import products from a CSV file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The path to the CSV file to import.
	 *
	 * [--batch-size=<100|250|500>]
	 * : Number of products to process per batch.
	 * ---
	 * default: 250
	 * ---
	 *
	 * [--mode=<live|dry_run>]
	 * : Run the import live or as a simulation.
	 * ---
	 * default: live
	 * ---
	 *
	 * [--duplicate=<skip|update|replace|draft>]
	 * : Duplicate SKU handling strategy.
	 * ---
	 * default: skip
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc-import products.csv --batch-size=250 --mode=live --duplicate=update
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$file_path = $args[0];

		if ( ! file_exists( $file_path ) ) {
			\WP_CLI::error( sprintf( 'File not found at path: %s', $file_path ) );
		}

		$batch_size = intval( $assoc_args['batch-size'] );
		$mode       = sanitize_text_field( $assoc_args['mode'] );
		$duplicate  = sanitize_text_field( $assoc_args['duplicate'] );

		\WP_CLI::log( '-----------------------------------------------' );
		\WP_CLI::log( '  Advanced WooCommerce CSV Product Importer' );
		\WP_CLI::log( '-----------------------------------------------' );
		\WP_CLI::log( sprintf( 'File:      %s', basename( $file_path ) ) );
		\WP_CLI::log( sprintf( 'Batch:     %d products', $batch_size ) );
		\WP_CLI::log( sprintf( 'Mode:      %s', strtoupper( $mode ) ) );
		\WP_CLI::log( sprintf( 'Duplicate: %s', strtoupper( $duplicate ) ) );
		\WP_CLI::log( '-----------------------------------------------' );

		// Parse headers.
		$parser = new CsvParser();
		$headers = $parser->get_headers( $file_path );
		if ( empty( $headers ) ) {
			\WP_CLI::error( 'The CSV file does not contain valid headers.' );
		}

		// Auto-map headers.
		$mapping = $this->auto_map_headers( $headers );
		\WP_CLI::log( sprintf( 'Auto-matched %d out of %d columns.', count( array_filter( $mapping ) ), count( $headers ) ) );

		// Count total rows.
		\WP_CLI::log( 'Calculating total rows...' );
		$total_rows = $parser->get_total_rows( $file_path );
		\WP_CLI::log( sprintf( 'Total rows found: %d', $total_rows ) );

		if ( $total_rows <= 0 ) {
			\WP_CLI::error( 'No rows to process.' );
		}

		// Create job audit in database.
		$job_repo = new JobRepository();
		$job_id = $job_repo->create( realpath( $file_path ), $mode, $duplicate );
		if ( ! $job_id ) {
			\WP_CLI::error( 'Failed to create import job record in the database.' );
		}

		$job_repo->update( $job_id, array(
			'column_mapping' => wp_json_encode( $mapping ),
			'total_rows'     => $total_rows,
			'status'         => 'processing',
		) );

		// Initialize services.
		$importer = new ProductImporter();
		$importer->init_settings( $mapping, $duplicate, $mode );
		$logger = new LoggerService();

		// Suspend hook operations.
		$importer->suspend_hooks();

		$processed = 0;
		$failed    = 0;
		$offset    = 0;

		$progress_bar = \WP_CLI\Utils\make_progress_bar( 'Importing Products', $total_rows );

		while ( $offset < $total_rows ) {
			$rows = $parser->get_rows( realpath( $file_path ), $offset, $batch_size );
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$result = $importer->import_row( $row['data'] );

				if ( 'failed' === $result['status'] ) {
					$failed++;
					$logger->log_failed( $job_id, $row['index'], $result['sku'], $result['message'], $row['data'] );
				} elseif ( 'skipped' === $result['status'] ) {
					$processed++;
					$logger->log_skipped( $job_id, $row['index'], $result['sku'], $result['message'] );
				} elseif ( 'updated' === $result['status'] ) {
					$processed++;
					$logger->log_updated( $job_id, $row['index'], $result['sku'], $result['message'] );
				} else {
					$processed++;
					$logger->log_success( $job_id, $row['index'], $result['sku'], $result['message'] );
				}

				$progress_bar->tick();
			}

			$offset += $batch_size;

			// Sync progress periodically back to database job audit.
			$job_repo->update( $job_id, array(
				'processed_rows' => $processed,
				'failed_rows'    => $failed,
			) );
		}

		$progress_bar->finish();

		// Resume hook operations.
		$importer->resume_hooks();

		// Complete Job audit.
		$job_repo->update( $job_id, array(
			'status' => 'completed',
		) );

		// Generate export report logs.
		$logger->export_csv( $job_id );

		\WP_CLI::log( '-----------------------------------------------' );
		\WP_CLI::success( 'Import complete!' );
		\WP_CLI::log( sprintf( 'Success / Updated: %d', $processed ) );
		\WP_CLI::log( sprintf( 'Failed / Errors:   %d', $failed ) );
		\WP_CLI::log( '-----------------------------------------------' );
	}

	/**
	 * Automatically maps standard headers to WC attributes based on string matching.
	 *
	 * @param array $headers CSV headers array.
	 * @return array Auto mapped fields.
	 */
	private function auto_map_headers( array $headers ) {
		$mapping = array();

		$fields = array(
			'name'              => array( 'name', 'product name', 'title', 'product title' ),
			'sku'               => array( 'sku', 'product sku', 'item code' ),
			'slug'              => array( 'slug', 'product slug' ),
			'type'              => array( 'type', 'product type' ),
			'description'       => array( 'description', 'desc', 'product description' ),
			'short_description' => array( 'short description', 'short desc' ),
			'regular_price'     => array( 'regular price', 'price', 'retail price', 'regular_price' ),
			'sale_price'        => array( 'sale price', 'sale_price', 'promo price' ),
			'stock'             => array( 'stock', 'stock qty', 'quantity', 'qty' ),
			'stock_status'      => array( 'stock status', 'stock_status', 'availability' ),
			'categories'        => array( 'categories', 'category', 'product categories', 'cats' ),
			'tags'              => array( 'tags', 'product tags' ),
			'weight'            => array( 'weight', 'product weight' ),
			'length'            => array( 'length', 'length (dimensions)' ),
			'width'             => array( 'width', 'width (dimensions)' ),
			'height'            => array( 'height', 'height (dimensions)' ),
			'featured_image'    => array( 'featured image', 'image', 'featured_image', 'image url', 'primary image' ),
			'gallery_images'    => array( 'gallery images', 'images', 'gallery_images', 'image gallery' ),
		);

		foreach ( $headers as $header ) {
			$matched = '';
			$header_clean = strtolower( trim( $header ) );

			foreach ( $fields as $field_key => $synonyms ) {
				if ( in_array( $header_clean, $synonyms, true ) ) {
					$matched = $field_key;
					break;
				}
			}

			// Fallback: If starts with Attribute:
			if ( ! $matched && strpos( $header_clean, 'attribute:' ) === 0 ) {
				$matched = 'attribute_' . trim( substr( $header_clean, 10 ) );
			}
			// Fallback: If starts with Meta:
			elseif ( ! $matched && strpos( $header_clean, 'meta:' ) === 0 ) {
				$matched = 'meta_' . trim( substr( $header_clean, 5 ) );
			}

			$mapping[ $header ] = $matched;
		}

		return $mapping;
	}
}
