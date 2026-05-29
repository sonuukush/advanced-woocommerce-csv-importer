<?php
namespace AdvancedWcCsvImporter\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ImageImporter Class
 *
 * Downloads external image URLs, validates format/headers, and prevents duplicate media uploads using MD5 hash matching.
 */
class ImageImporter {

	/**
	 * Download a remote image URL and attach it to a product (or just load it into media library).
	 *
	 * @param string $url Remote image URL.
	 * @param int    $product_id Product ID to attach (optional).
	 * @return int|\WP_Error Attachment ID on success, WP_Error on failure.
	 */
	public function import( $url, $product_id = 0 ) {
		$url = trim( $url );
		if ( empty( $url ) || filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
			return new \WP_Error( 'invalid_url', __( 'Invalid image URL provided.', 'advanced-wc-csv-importer' ) );
		}

		// Clean up the URL to prevent query parameter issues in filename.
		$clean_url = strtok( $url, '?' );
		$filename  = basename( $clean_url );

		// Check if image already exists by source URL or filename matching.
		$existing_id = $this->find_existing_image( $url );
		if ( $existing_id ) {
			return $existing_id;
		}

		// Download file to temp folder.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$tmp_file = download_url( $url );
		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		// Generate file hash to prevent duplicate contents.
		$file_hash = md5_file( $tmp_file );
		if ( $file_hash ) {
			$duplicate_id = $this->find_image_by_hash( $file_hash );
			if ( $duplicate_id ) {
				unlink( $tmp_file ); // Delete temp file.
				return $duplicate_id;
			}
		}

		// Validate file type.
		$file_arr = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);
		$file_type = wp_check_filetype( $filename );
		if ( ! $file_type['type'] || strpos( $file_type['type'], 'image/' ) !== 0 ) {
			unlink( $tmp_file );
			return new \WP_Error( 'invalid_file_type', __( 'The downloaded file is not a valid image.', 'advanced-wc-csv-importer' ) );
		}

		// Move physical file to WP uploads directory.
		$overrides = array(
			'test_form' => false,
			'test_size' => true,
		);
		$moved_file = wp_handle_sideload( $file_arr, $overrides );

		if ( isset( $moved_file['error'] ) ) {
			return new \WP_Error( 'upload_error', $moved_file['error'] );
		}

		$attachment_url = $moved_file['url'];
		$attachment_path = $moved_file['file'];

		// Prepare attachment metadata.
		$attachment = array(
			'post_mime_type' => $moved_file['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		// Insert attachment.
		$attach_id = wp_insert_attachment( $attachment, $attachment_path, $product_id );
		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}

		// Generate metadata and sizes.
		$attach_data = wp_generate_attachment_metadata( $attach_id, $attachment_path );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		// Save meta flags for deduplication.
		update_post_meta( $attach_id, '_source_image_url', $url );
		if ( $file_hash ) {
			update_post_meta( $attach_id, '_source_image_hash', $file_hash );
		}

		return $attach_id;
	}

	/**
	 * Find existing attachment ID by matching source URL.
	 *
	 * @param string $url Source URL.
	 * @return int|false Attachment ID, or false if not found.
	 */
	private function find_existing_image( $url ) {
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_source_image_url' AND meta_value = %s LIMIT 1",
			$url
		);
		$result = $wpdb->get_var( $query );
		return $result ? intval( $result ) : false;
	}

	/**
	 * Find existing attachment ID by matching file MD5 hash.
	 *
	 * @param string $hash MD5 hash.
	 * @return int|false Attachment ID, or false if not found.
	 */
	private function find_image_by_hash( $hash ) {
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_source_image_hash' AND meta_value = %s LIMIT 1",
			$hash
		);
		$result = $wpdb->get_var( $query );
		return $result ? intval( $result ) : false;
	}
}
