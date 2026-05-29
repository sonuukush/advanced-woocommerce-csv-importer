<?php
namespace AdvancedWcCsvImporter\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CsvParser Class
 *
 * Stream-reads CSV files dynamically using fgetcsv to prevent memory exhaustion on large datasets.
 */
class CsvParser {

	/**
	 * Delimiter character.
	 *
	 * @var string
	 */
	private $delimiter = ',';

	/**
	 * Class constructor.
	 *
	 * @param string $delimiter CSV delimiter.
	 */
	public function __construct( $delimiter = ',' ) {
		$this->delimiter = $delimiter;
	}

	/**
	 * Auto-detect delimiter based on the first line.
	 *
	 * @param string $file_path Path to the CSV file.
	 * @return string Detected delimiter.
	 */
	public function detect_delimiter( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return $this->delimiter;
		}

		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return $this->delimiter;
		}

		$first_line = fgets( $handle );
		fclose( $handle );

		if ( ! $first_line ) {
			return $this->delimiter;
		}

		$delimiters = array( ',' => 0, ';' => 0, "\t" => 0, '|' => 0 );
		foreach ( $delimiters as $delim => &$count ) {
			$count = substr_count( $first_line, $delim );
		}

		arsort( $delimiters );
		$detected = key( $delimiters );

		if ( $delimiters[ $detected ] > 0 ) {
			$this->delimiter = $detected;
		}

		return $this->delimiter;
	}

	/**
	 * Read and return headers.
	 *
	 * @param string $file_path Path to the CSV file.
	 * @return array Headers list.
	 */
	public function get_headers( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return array();
		}

		$this->detect_delimiter( $file_path );
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return array();
		}

		$this->skip_bom( $handle );
		$headers = fgetcsv( $handle, 0, $this->delimiter );
		fclose( $handle );

		if ( ! is_array( $headers ) ) {
			return array();
		}

		return array_map( 'trim', $headers );
	}

	/**
	 * Count total records in the CSV file (excluding header).
	 *
	 * @param string $file_path Path to the CSV file.
	 * @return int Total records count.
	 */
	public function get_total_rows( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return 0;
		}

		$this->detect_delimiter( $file_path );
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return 0;
		}

		$this->skip_bom( $handle );

		// Skip header.
		fgetcsv( $handle, 0, $this->delimiter );

		$total = 0;
		while ( fgetcsv( $handle, 0, $this->delimiter ) !== false ) {
			$total++;
		}

		fclose( $handle );
		return $total;
	}

	/**
	 * Fetch a chunk/slice of CSV rows.
	 *
	 * @param string $file_path Path to the CSV file.
	 * @param int    $offset Start reading index (0-indexed, excluding header).
	 * @param int    $limit Max number of records to read.
	 * @return array Array of rows: each item contains 'index' (1-based row number) and 'data' (mapped array).
	 */
	public function get_rows( $file_path, $offset, $limit ) {
		if ( ! file_exists( $file_path ) ) {
			return array();
		}

		$this->detect_delimiter( $file_path );
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return array();
		}

		$this->skip_bom( $handle );
		$headers = fgetcsv( $handle, 0, $this->delimiter );

		if ( ! is_array( $headers ) ) {
			fclose( $handle );
			return array();
		}

		$headers = array_map( 'trim', $headers );

		$rows = array();
		$current_row = 0;

		// Skip rows until we reach the offset.
		while ( $current_row < $offset && ! feof( $handle ) ) {
			fgetcsv( $handle, 0, $this->delimiter );
			$current_row++;
		}

		// Read up to limit.
		$count = 0;
		while ( $count < $limit && ( $data = fgetcsv( $handle, 0, $this->delimiter ) ) !== false ) {
			// Skip empty lines.
			if ( count( $data ) === 1 && empty( $data[0] ) ) {
				continue;
			}

			$mapped_row = array();
			foreach ( $headers as $i => $header ) {
				if ( empty( $header ) ) {
					continue;
				}
				$mapped_row[ $header ] = isset( $data[ $i ] ) ? trim( $data[ $i ] ) : '';
			}

			$rows[] = array(
				'index' => $offset + $count + 1, // 1-indexed row number.
				'data'  => $mapped_row,
			);
			$count++;
		}

		fclose( $handle );
		return $rows;
	}

	/**
	 * Skips the UTF-8 BOM if present.
	 *
	 * @param resource $handle File handle.
	 */
	private function skip_bom( $handle ) {
		$bom = fread( $handle, 3 );
		if ( $bom !== "\xEF\xBB\xBF" ) {
			fseek( $handle, 0 );
		}
	}
}
