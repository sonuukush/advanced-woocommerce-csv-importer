<?php
namespace AdvancedWcCsvImporter\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ImportValidator Class
 *
 * Implements full schema scanning, custom validations, and exports of validation failure logs.
 */
class ImportValidator {

	/**
	 * Map of CSV headers to WooCommerce fields.
	 *
	 * @var array
	 */
	private $column_mapping = array();

	/**
	 * Set the column mapping configuration.
	 *
	 * @param array $mapping Mapping array.
	 */
	public function set_mapping( array $mapping ) {
		$this->column_mapping = $mapping;
	}

	/**
	 * Validate Headers configuration.
	 *
	 * @param array $headers Headers array from CSV.
	 * @return array Array of string errors or empty if valid.
	 */
	public function validate_headers( array $headers ) {
		$errors = array();

		// Check duplicate headers.
		$duplicates = array_unique( array_diff_assoc( $headers, array_unique( $headers ) ) );
		if ( ! empty( $duplicates ) ) {
			$errors[] = sprintf(
				/* translators: %s: duplicate headers names */
				__( 'Duplicate columns found in CSV: %s', 'advanced-wc-csv-importer' ),
				implode( ', ', $duplicates )
			);
		}

		return $errors;
	}

	/**
	 * Validate the CSV file line-by-line and write invalid rows to a separate downloadable CSV file.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $file_path Path to the CSV file.
	 * @return array Validation report summary.
	 */
	public function validate_file( $job_id, $file_path ) {
		$parser = new CsvParser();
		$total_rows = $parser->get_total_rows( $file_path );

		$valid_count = 0;
		$invalid_count = 0;

		// Create paths for invalid rows CSV.
		$upload_dir = wp_upload_dir();
		$import_dir = $upload_dir['basedir'] . '/wc-csv-imports';
		if ( ! file_exists( $import_dir ) ) {
			wp_mkdir_p( $import_dir );
		}

		$invalid_file_path = $import_dir . '/job_' . $job_id . '_invalid.csv';
		$invalid_handle = fopen( $invalid_file_path, 'w' );

		// Retrieve headers to output in the invalid CSV.
		$headers = $parser->get_headers( $file_path );
		if ( $invalid_handle && ! empty( $headers ) ) {
			$invalid_headers = array_merge( array( 'Row Number', 'Validation Error' ), $headers );
			// Write UTF-8 BOM for Excel compatibility.
			fwrite( $invalid_handle, "\xEF\xBB\xBF" );
			fputcsv( $invalid_handle, $invalid_headers );
		}

		// Read in batches of 1000 to keep memory flat.
		$batch_size = 1000;
		$offset = 0;

		// Find mapped field CSV column names.
		$name_col = array_search( 'name', $this->column_mapping, true );
		$sku_col  = array_search( 'sku', $this->column_mapping, true );
		$price_col = array_search( 'regular_price', $this->column_mapping, true );
		$sale_col  = array_search( 'sale_price', $this->column_mapping, true );
		$img_col   = array_search( 'featured_image', $this->column_mapping, true );

		while ( $offset < $total_rows ) {
			$rows = $parser->get_rows( $file_path, $offset, $batch_size );
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$row_index = $row['index'];
				$row_data = $row['data'];
				$row_errors = array();

				// 1. Validate Product Name (required).
				$name_val = $name_col !== false && isset( $row_data[ $name_col ] ) ? trim( $row_data[ $name_col ] ) : '';
				if ( empty( $name_val ) ) {
					$row_errors[] = __( 'Missing Product Name', 'advanced-wc-csv-importer' );
				}

				// 2. Validate SKU.
				$sku_val = $sku_col !== false && isset( $row_data[ $sku_col ] ) ? trim( $row_data[ $sku_col ] ) : '';
				if ( empty( $sku_val ) ) {
					$row_errors[] = __( 'Missing Product SKU', 'advanced-wc-csv-importer' );
				} elseif ( ! preg_match( '/^[a-zA-Z0-9_\-\s]+$/', $sku_val ) ) {
					$row_errors[] = __( 'Invalid SKU format (special characters not allowed)', 'advanced-wc-csv-importer' );
				}

				// 3. Validate Prices.
				$price_val = $price_col !== false && isset( $row_data[ $price_col ] ) ? trim( $row_data[ $price_col ] ) : '';
				if ( ! empty( $price_val ) && ! is_numeric( $price_val ) ) {
					$row_errors[] = __( 'Invalid regular price (must be numeric)', 'advanced-wc-csv-importer' );
				} elseif ( ! empty( $price_val ) && floatval( $price_val ) < 0 ) {
					$row_errors[] = __( 'Regular price cannot be negative', 'advanced-wc-csv-importer' );
				}

				$sale_val = $sale_col !== false && isset( $row_data[ $sale_col ] ) ? trim( $row_data[ $sale_col ] ) : '';
				if ( ! empty( $sale_val ) && ! is_numeric( $sale_val ) ) {
					$row_errors[] = __( 'Invalid sale price (must be numeric)', 'advanced-wc-csv-importer' );
				}

				// 4. Validate Featured Image URL.
				$img_val = $img_col !== false && isset( $row_data[ $img_col ] ) ? trim( $row_data[ $img_col ] ) : '';
				if ( ! empty( $img_val ) ) {
					$urls = array_map( 'trim', explode( ',', $img_val ) );
					foreach ( $urls as $url ) {
						if ( filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
							$row_errors[] = sprintf(
								/* translators: %s: invalid image URL */
								__( 'Malformed image URL: %s', 'advanced-wc-csv-importer' ),
								$url
							);
						}
					}
				}

				// Compile results.
				if ( ! empty( $row_errors ) ) {
					$invalid_count++;
					if ( $invalid_handle ) {
						$csv_out = array_merge(
							array( $row_index, implode( ' | ', $row_errors ) ),
							array_values( $row_data )
						);
						fputcsv( $invalid_handle, $csv_out );
					}
				} else {
					$valid_count++;
				}
			}

			$offset += $batch_size;
		}

		if ( $invalid_handle ) {
			fclose( $invalid_handle );
		}

		// Delete invalid file if there are no errors.
		if ( $invalid_count === 0 && file_exists( $invalid_file_path ) ) {
			unlink( $invalid_file_path );
		}

		$invalid_csv_url = $invalid_count > 0 ? $upload_dir['baseurl'] . '/wc-csv-imports/job_' . $job_id . '_invalid.csv' : '';

		return array(
			'total_rows'      => $total_rows,
			'valid_rows'      => $valid_count,
			'invalid_rows'    => $invalid_count,
			'invalid_csv_url' => $invalid_csv_url,
		);
	}
}
