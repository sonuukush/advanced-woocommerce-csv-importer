<?php
namespace AdvancedWcCsvImporter\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ProductImporter Class
 *
 * Coordinates data-to-post mapping, runs WC CRUD updates, handles taxonomy linking, and manages system hook suspension.
 */
class ProductImporter {

	/**
	 * Column mapping configuration.
	 *
	 * @var array
	 */
	private $column_mapping = array();

	/**
	 * Duplicate handling strategy.
	 *
	 * @var string
	 */
	private $duplicate_handle = 'skip';

	/**
	 * Import mode ('live' or 'dry_run').
	 *
	 * @var string
	 */
	private $mode = 'live';

	/**
	 * Image Importer instance.
	 *
	 * @var ImageImporter
	 */
	private $image_importer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->image_importer = new ImageImporter();
	}

	/**
	 * Initialize settings.
	 *
	 * @param array  $mapping Mapping configuration.
	 * @param string $duplicate_handle Strategy ('skip', 'update', 'replace', 'draft').
	 * @param string $mode Mode ('live', 'dry_run').
	 */
	public function init_settings( array $mapping, $duplicate_handle = 'skip', $mode = 'live' ) {
		$this->column_mapping   = $mapping;
		$this->duplicate_handle = $duplicate_handle;
		$this->mode             = $mode;
	}

	/**
	 * Suspend heavy hooks and speed up execution.
	 */
	public function suspend_hooks() {
		// WordPress global term counting suspension.
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		// Prevent search index updates from third party plugins if possible.
		remove_action( 'save_post', 'wp_save_post_revision_on_insert', 9 );

		// WooCommerce defer term count.
		if ( class_exists( 'WC_Term_Lists' ) && method_exists( 'WC_Term_Lists', 'defer_count_updating' ) ) {
			\WC_Term_Lists::defer_count_updating( true );
		}

		// Disable post save transients cleanup during execution.
		remove_action( 'save_post', 'wc_delete_product_transients', 10 );
		remove_action( 'clean_post_cache', 'wc_delete_product_transients', 10 );
	}

	/**
	 * Resume suspended hooks.
	 */
	public function resume_hooks() {
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		if ( class_exists( 'WC_Term_Lists' ) && method_exists( 'WC_Term_Lists', 'defer_count_updating' ) ) {
			\WC_Term_Lists::defer_count_updating( false );
		}

		// Flush WooCommerce cache transients.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
	}

	/**
	 * Process a single CSV row data.
	 *
	 * @param array $row CSV row data array (headers as keys).
	 * @return array Status report: array('status' => 'success/failed/skipped/updated', 'message' => '', 'sku' => '')
	 */
	public function import_row( array $row ) {
		// Find SKU.
		$sku_col = array_search( 'sku', $this->column_mapping, true );
		$sku = ( $sku_col !== false && isset( $row[ $sku_col ] ) ) ? trim( $row[ $sku_col ] ) : '';

		if ( empty( $sku ) ) {
			return array(
				'status'  => 'failed',
				'sku'     => '',
				'message' => __( 'Missing SKU in row.', 'advanced-wc-csv-importer' ),
			);
		}

		// Check duplicate SKU.
		$existing_id = wc_get_product_id_by_sku( $sku );

		if ( $existing_id ) {
			switch ( $this->duplicate_handle ) {
				case 'skip':
					return array(
						'status'  => 'skipped',
						'sku'     => $sku,
						'message' => sprintf( __( 'SKU "%s" already exists. Skipped.', 'advanced-wc-csv-importer' ), $sku ),
					);

				case 'replace':
					if ( $this->mode === 'live' ) {
						wp_delete_post( $existing_id, true );
						$existing_id = 0; // Set to 0 so a new product is created.
					}
					break;

				case 'draft':
					// We'll create a new product, but set status to draft.
					$existing_id = 0;
					$row['_force_draft_status'] = true;
					break;

				case 'update':
				default:
					// Proceed to update the existing product ID.
					break;
			}
		}

		// Map CSV values to WC variables.
		$mapped_data = $this->map_fields( $row );

		if ( $this->mode === 'dry_run' ) {
			$msg = $existing_id ? __( 'Dry Run: SKU exists, would update.', 'advanced-wc-csv-importer' ) : __( 'Dry Run: SKU is new, would create.', 'advanced-wc-csv-importer' );
			return array(
				'status'  => 'success',
				'sku'     => $sku,
				'message' => $msg,
			);
		}

		// Live import starts.
		try {
			$product = $this->save_product( $existing_id, $mapped_data, $row );
			if ( is_wp_error( $product ) ) {
				return array(
					'status'  => 'failed',
					'sku'     => $sku,
					'message' => $product->get_error_message(),
				);
			}

			$is_new = ! $existing_id;

			return array(
				'status'  => $is_new ? 'success' : 'updated',
				'sku'     => $sku,
				'message' => $is_new ? sprintf( __( 'Product created successfully. ID: %d', 'advanced-wc-csv-importer' ), $product->get_id() ) : sprintf( __( 'Product updated successfully. ID: %d', 'advanced-wc-csv-importer' ), $product->get_id() ),
			);
		} catch ( \Exception $e ) {
			return array(
				'status'  => 'failed',
				'sku'     => $sku,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Map CSV row data to WC-friendly schema array.
	 *
	 * @param array $row CSV raw array.
	 * @return array Mapped system schema.
	 */
	private function map_fields( array $row ) {
		$mapped = array();

		foreach ( $this->column_mapping as $csv_header => $wc_field ) {
			if ( ! isset( $row[ $csv_header ] ) ) {
				continue;
			}

			$val = trim( $row[ $csv_header ] );

			// Check custom attributes.
			if ( strpos( $wc_field, 'attribute_' ) === 0 ) {
				$attr_name = substr( $wc_field, 10 );
				$mapped['attributes'][ $attr_name ] = $val;
			}
			// Check custom meta.
			elseif ( strpos( $wc_field, 'meta_' ) === 0 ) {
				$meta_key = substr( $wc_field, 5 );
				$mapped['meta'][ $meta_key ] = $val;
			}
			// General fields mapping.
			else {
				$mapped[ $wc_field ] = $val;
			}
		}

		return $mapped;
	}

	/**
	 * Save product to database.
	 *
	 * @param int   $product_id Product ID (0 if new).
	 * @param array $data Mapped values.
	 * @param array $raw_row Raw CSV row.
	 * @return \WC_Product|\WP_Error Product object or WP_Error.
	 */
	private function save_product( $product_id, array $data, array $raw_row ) {
		$type = isset( $data['type'] ) ? strtolower( $data['type'] ) : 'simple';

		// Resolve type.
		if ( ! in_array( $type, array( 'simple', 'variable', 'grouped', 'external' ), true ) ) {
			$type = 'simple';
		}

		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return new \WP_Error( 'load_failed', __( 'Could not load existing product.', 'advanced-wc-csv-importer' ) );
			}
		} else {
			// Create new.
			$classname = \WC_Product_Factory::get_product_classname( $product_id, $type );
			$product   = new $classname();
		}

		// 1. Basic Fields.
		if ( isset( $data['sku'] ) ) {
			$product->set_sku( $data['sku'] );
		}

		if ( isset( $data['name'] ) ) {
			$product->set_name( $data['name'] );
		}

		if ( isset( $data['slug'] ) ) {
			$product->set_slug( sanitize_title( $data['slug'] ) );
		}

		if ( isset( $data['description'] ) ) {
			$product->set_description( $data['description'] );
		}

		if ( isset( $data['short_description'] ) ) {
			$product->set_short_description( $data['short_description'] );
		}

		// 2. Status & Visibility.
		$status = isset( $data['status'] ) ? strtolower( $data['status'] ) : 'publish';
		if ( isset( $raw_row['_force_draft_status'] ) && $raw_row['_force_draft_status'] ) {
			$status = 'draft';
		}
		if ( in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$product->set_status( $status );
		}

		$visibility = isset( $data['visibility'] ) ? strtolower( $data['visibility'] ) : 'visible';
		if ( in_array( $visibility, array( 'visible', 'catalog', 'search', 'hidden' ), true ) ) {
			$product->set_catalog_visibility( $visibility );
		}

		// 3. Pricing.
		if ( isset( $data['regular_price'] ) && $data['regular_price'] !== '' ) {
			$product->set_regular_price( $data['regular_price'] );
		}
		if ( isset( $data['sale_price'] ) && $data['sale_price'] !== '' ) {
			$product->set_sale_price( $data['sale_price'] );
		}

		// 4. Inventory.
		if ( isset( $data['stock'] ) && $data['stock'] !== '' ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( intval( $data['stock'] ) );
		} else {
			$product->set_manage_stock( false );
		}

		$stock_status = isset( $data['stock_status'] ) ? strtolower( $data['stock_status'] ) : 'instock';
		if ( in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			$product->set_stock_status( $stock_status );
		}

		// 5. Weight and Dimensions.
		if ( isset( $data['weight'] ) && $data['weight'] !== '' ) {
			$product->set_weight( $data['weight'] );
		}
		if ( isset( $data['length'] ) && $data['length'] !== '' ) {
			$product->set_length( $data['length'] );
		}
		if ( isset( $data['width'] ) && $data['width'] !== '' ) {
			$product->set_width( $data['width'] );
		}
		if ( isset( $data['height'] ) && $data['height'] !== '' ) {
			$product->set_height( $data['height'] );
		}

		// 6. Type Specifics (External).
		if ( 'external' === $type ) {
			if ( isset( $data['external_url'] ) ) {
				$product->set_product_url( $data['external_url'] );
			}
			if ( isset( $data['button_text'] ) ) {
				$product->set_button_text( $data['button_text'] );
			}
		}

		// Save basic properties first to acquire product ID if new.
		$product->save();
		$pid = $product->get_id();

		// 7. Categories & Tags & Brands (Taxonomies).
		if ( isset( $data['categories'] ) && ! empty( $data['categories'] ) ) {
			$cat_ids = $this->parse_hierarchical_terms( $data['categories'], 'product_cat' );
			$product->set_category_ids( $cat_ids );
		}

		if ( isset( $data['tags'] ) && ! empty( $data['tags'] ) ) {
			$tag_ids = $this->parse_hierarchical_terms( $data['tags'], 'product_tag' );
			$product->set_tag_ids( $tag_ids );
		}

		if ( isset( $data['brand'] ) && ! empty( $data['brand'] ) ) {
			$brand_ids = $this->parse_hierarchical_terms( $data['brand'], 'product_brand' );
			wp_set_object_terms( $pid, $brand_ids, 'product_brand' );
		}

		// 8. Attributes Processing.
		if ( isset( $data['attributes'] ) && ! empty( $data['attributes'] ) ) {
			$this->process_product_attributes( $product, $data['attributes'] );
		}

		// 9. Images Processing.
		if ( isset( $data['featured_image'] ) && ! empty( $data['featured_image'] ) ) {
			$feat_id = $this->image_importer->import( $data['featured_image'], $pid );
			if ( ! is_wp_error( $feat_id ) ) {
				$product->set_image_id( $feat_id );
			}
		}

		if ( isset( $data['gallery_images'] ) && ! empty( $data['gallery_images'] ) ) {
			$gallery_urls = array_map( 'trim', explode( ',', $data['gallery_images'] ) );
			$gallery_ids  = array();
			foreach ( $gallery_urls as $gurl ) {
				$gid = $this->image_importer->import( $gurl, $pid );
				if ( ! is_wp_error( $gid ) ) {
					$gallery_ids[] = $gid;
				}
			}
			$product->set_gallery_image_ids( $gallery_ids );
		}

		// 10. Custom Meta fields.
		if ( isset( $data['meta'] ) && ! empty( $data['meta'] ) ) {
			foreach ( $data['meta'] as $meta_key => $meta_val ) {
				update_post_meta( $pid, $meta_key, $meta_val );
			}
		}

		// 11. SEO Meta fields.
		if ( isset( $data['seo_title'] ) && ! empty( $data['seo_title'] ) ) {
			update_post_meta( $pid, '_yoast_wpseo_title', $data['seo_title'] );
			update_post_meta( $pid, '_rank_math_title', $data['seo_title'] );
		}
		if ( isset( $data['seo_description'] ) && ! empty( $data['seo_description'] ) ) {
			update_post_meta( $pid, '_yoast_wpseo_metadesc', $data['seo_description'] );
			update_post_meta( $pid, '_rank_math_description', $data['seo_description'] );
		}

		// Save again to sync taxonomies and attributes.
		$product->save();
		return $product;
	}

	/**
	 * Parse comma-separated list of terms, supporting hierarchical symbols (like "Electronics > Phones").
	 *
	 * @param string $string Raw terms string.
	 * @param string $taxonomy Taxonomy name (e.g., 'product_cat').
	 * @return array Array of term IDs.
	 */
	private function parse_hierarchical_terms( $string, $taxonomy ) {
		$term_ids = array();
		if ( ! taxonomy_exists( $taxonomy ) ) {
			// Register custom taxonomy product_brand if it doesn't exist to prevent crash.
			if ( 'product_brand' === $taxonomy ) {
				register_taxonomy( 'product_brand', 'product' );
			} else {
				return $term_ids;
			}
		}

		// Split on commas to support multiple categories/tags.
		$entries = array_map( 'trim', explode( ',', $string ) );

		foreach ( $entries as $entry ) {
			if ( empty( $entry ) ) {
				continue;
			}

			// If hierarchical "Cat1 > Cat2 > Cat3".
			if ( strpos( $entry, '>' ) !== false ) {
				$parts = array_map( 'trim', explode( '>', $entry ) );
				$parent_id = 0;

				foreach ( $parts as $part ) {
					$term = get_term_by( 'name', $part, $taxonomy );

					if ( $term ) {
						$parent_id = $term->term_id;
					} else {
						// Create term.
						$new_term = wp_insert_term( $part, $taxonomy, array( 'parent' => $parent_id ) );
						if ( ! is_wp_error( $new_term ) ) {
							$parent_id = $new_term['term_id'];
						} else {
							break;
						}
					}
				}

				if ( $parent_id ) {
					$term_ids[] = $parent_id;
				}
			} else {
				// Single level term.
				$term = get_term_by( 'name', $entry, $taxonomy );
				if ( $term ) {
					$term_ids[] = $term->term_id;
				} else {
					$new_term = wp_insert_term( $entry, $taxonomy );
					if ( ! is_wp_error( $new_term ) ) {
						$term_ids[] = $new_term['term_id'];
					}
				}
			}
		}

		return array_unique( array_map( 'intval', $term_ids ) );
	}

	/**
	 * Process and attach custom product attributes.
	 *
	 * @param \WC_Product $product WC Product instance.
	 * @param array       $attributes Mapped attribute values (name => options).
	 */
	private function process_product_attributes( $product, array $attributes ) {
		$product_attributes = array();

		foreach ( $attributes as $name => $val ) {
			if ( empty( $val ) ) {
				continue;
			}

			$options = array_map( 'trim', explode( '|', $val ) );

			// Setup attribute object.
			$attribute_object = new \WC_Product_Attribute();
			$attribute_object->set_name( $name );
			$attribute_object->set_options( $options );
			$attribute_object->set_visible( true );

			// If variable product, make attributes available for variations.
			if ( $product->is_type( 'variable' ) ) {
				$attribute_object->set_variation( true );
			} else {
				$attribute_object->set_variation( false );
			}

			$product_attributes[ sanitize_title( $name ) ] = $attribute_object;
		}

		$product->set_attributes( $product_attributes );
	}
}
