<?php

/**
 * HivePress Converter Class.
 *
 * @since     2.2.0
 * @package   GeoDir_Converter
 */

namespace GeoDir_Converter\Importers;

use WP_Error;
use GeoDir_Media;
use GeoDir_Pricing_Package;
use GeoDir_Converter\GeoDir_Converter_Utils;
use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Main converter class for importing from HivePress.
 *
 * @since 2.2.0
 */
class GeoDir_Converter_HivePress extends GeoDir_Converter_Importer {

	/**
	 * Post type identifier for listings.
	 *
	 * @var string
	 */
	const POST_TYPE_LISTING = 'hp_listing';

	/**
	 * Post type identifier for vendors.
	 *
	 * @var string
	 */
	const POST_TYPE_VENDOR = 'hp_vendor';

	/**
	 * Post type identifier for packages.
	 *
	 * @var string
	 */
	const POST_TYPE_PACKAGE = 'hp_listing_package';

	/**
	 * Taxonomy identifier for listing categories.
	 *
	 * @var string
	 */
	const TAX_LISTING_CATEGORY = 'hp_listing_category';

	/**
	 * The single instance of the class.
	 *
	 * @var static
	 */
	protected static $instance;

	/**
	 * The importer ID.
	 *
	 * @var string
	 */
	protected $importer_id = 'hivepress';

	/**
	 * The import listing status ID.
	 *
	 * @var array
	 */
	protected $post_statuses = array( 'publish', 'pending', 'draft', 'private' );

	/**
	 * Batch size for processing items.
	 *
	 * @var int
	 */
	private $batch_size = 50;

	/**
	 * Initialize hooks.
	 *
	 * @since 2.2.0
	 */
	protected function init() {}

	/**
	 * Get class instance.
	 *
	 * @return static
	 */
	public static function instance() {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	/**
	 * Get importer title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'HivePress', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Import listings from your HivePress installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @return string
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/hivepress.png';
	}

	/**
	 * Get importer task action.
	 *
	 * @return string
	 */
	public function get_action() {
		return self::ACTION_IMPORT_CATEGORIES;
	}

	/**
	 * Render importer settings.
	 */
	public function render_settings() {
		?>
		<form class="geodir-converter-settings-form" method="post">
			<h6 class="fs-base"><?php esc_html_e( 'HivePress Importer Settings', 'geodir-converter' ); ?></h6>

			<?php
			$this->display_post_type_select();
			$this->display_author_select( true );
			$this->display_test_mode_checkbox();
			$this->display_progress();
			$this->display_logs( $this->get_logs() );
			$this->display_error_alert();
			?>

			<div class="geodir-converter-actions mt-3">
				<button type="button" class="btn btn-primary btn-sm geodir-converter-import me-2"><?php esc_html_e( 'Start Import', 'geodir-converter' ); ?></button>
				<button type="button" class="btn btn-outline-danger btn-sm geodir-converter-abort"><?php esc_html_e( 'Abort', 'geodir-converter' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * Validate importer settings.
	 *
	 * @param array $settings The settings to validate.
	 * @param array $files    The files to validate.
	 *
	 * @return array Validated and sanitized settings.
	 */
	public function validate_settings( array $settings, array $files = array() ) {
		$post_types = geodir_get_posttypes();
		$errors     = array();

		$settings['gd_post_type'] = isset( $settings['gd_post_type'] ) && ! empty( $settings['gd_post_type'] ) ? sanitize_text_field( $settings['gd_post_type'] ) : 'gd_place';
		$settings['wp_author_id'] = ( isset( $settings['wp_author_id'] ) && ! empty( $settings['wp_author_id'] ) ) ? absint( $settings['wp_author_id'] ) : get_current_user_id();
		$settings['test_mode']    = ( isset( $settings['test_mode'] ) && ! empty( $settings['test_mode'] ) && $settings['test_mode'] != 'no' ) ? 'yes' : 'no';

		if ( ! in_array( $settings['gd_post_type'], $post_types, true ) ) {
			$errors[] = esc_html__( 'The selected post type is invalid. Please choose a valid post type.', 'geodir-converter' );
		}

		if ( empty( $settings['wp_author_id'] ) || ! get_userdata( (int) $settings['wp_author_id'] ) ) {
			$errors[] = esc_html__( 'The selected WordPress author is invalid. Please select a valid author to import listings to.', 'geodir-converter' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'invalid_import_settings', implode( '<br>', $errors ) );
		}

		return $settings;
	}

	/**
	 * Get next task.
	 *
	 * @param array $task The current task.
	 * @param bool  $reset_offset Whether to reset the offset.
	 *
	 * @return array|false The next task or false if all tasks are completed.
	 */
	public function next_task( $task, $reset_offset = false ) {
		$task['imported'] = 0;
		$task['failed']   = 0;
		$task['skipped']  = 0;
		$task['updated']  = 0;

		if ( $reset_offset ) {
			$task['offset'] = 0;
		}

		$tasks = array(
			self::ACTION_IMPORT_CATEGORIES,
			self::ACTION_IMPORT_PACKAGES,
			self::ACTION_IMPORT_FIELDS,
			self::ACTION_PARSE_LISTINGS,
		);

		$key = array_search( $task['action'], $tasks, true );
		if ( false !== $key && $key + 1 < count( $tasks ) ) {
			$task['action'] = $tasks[ $key + 1 ];
			return $task;
		}

		return false;
	}

	/**
	 * Calculate the total number of items to be imported.
	 */
	public function set_import_total() {
		global $wpdb;

		$total_items = 0;

		// Count categories.
		$categories   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_LISTING_CATEGORY ) );
		$total_items += $categories;

		// Count packages.
		$packages     = (int) $this->count_packages();
		$total_items += $packages;

		// Count custom fields.
		$custom_fields = $this->get_custom_fields();
		$total_items  += (int) count( $custom_fields );

		// Count listings.
		$total_items += (int) $this->count_listings();

		$this->increase_imports_total( $total_items );
	}

	/**
	 * Import categories from HivePress to GeoDirectory.
	 *
	 * @since 2.2.0
	 * @param array $task Import task.
	 *
	 * @return array Result of the import operation.
	 */
	public function task_import_categories( $task ) {
		global $wpdb;

		// Set total number of items to import.
		$this->set_import_total();

		// Log import started.
		$this->log( esc_html__( 'Categories: Import started.', 'geodir-converter' ) );

		if ( 0 === (int) wp_count_terms( self::TAX_LISTING_CATEGORY ) ) {
			$this->log( esc_html__( 'Categories: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$post_type = $this->get_import_post_type();

		$categories = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, tt.*
				FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				self::TAX_LISTING_CATEGORY
			)
		);

		if ( empty( $categories ) || is_wp_error( $categories ) ) {
			$this->log( esc_html__( 'Categories: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		if ( $this->is_test_mode() ) {
			$this->log(
				sprintf(
					esc_html__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
					count( $categories ),
					0
				),
				'success'
			);
			return $this->next_task( $task );
		}

		$result = $this->import_taxonomy_terms( $categories, $post_type . 'category', 'ct_cat_top_desc' );

		$this->increase_succeed_imports( (int) $result['imported'] );
		$this->increase_failed_imports( (int) $result['failed'] );

		$this->log(
			sprintf(
				esc_html__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$result['imported'],
				$result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Get custom fields for HivePress listings.
	 *
	 * @return array The custom fields.
	 */
	private function get_custom_fields() {
		global $wpdb;

		$fields = array(
			array(
				'type'           => 'number',
				'field_key'      => $this->importer_id . '_id',
				'label'          => __( 'HivePress ID', 'geodir-converter' ),
				'description'    => __( 'Original HivePress Listing ID.', 'geodir-converter' ),
				'placeholder'    => __( 'HivePress ID', 'geodir-converter' ),
				'icon'           => 'far fa-id-card',
				'only_for_admin' => 1,
				'required'       => 0,
			),
			array(
				'type'        => 'checkbox',
				'field_key'   => 'featured',
				'label'       => __( 'Is Featured?', 'geodir-converter' ),
				'description' => __( 'Mark listing as featured.', 'geodir-converter' ),
				'icon'        => 'fas fa-certificate',
				'required'    => 0,
			),
			array(
				'type'        => 'checkbox',
				'field_key'   => 'verified',
				'label'       => __( 'Is Verified?', 'geodir-converter' ),
				'description' => __( 'Mark listing as verified.', 'geodir-converter' ),
				'icon'        => 'fas fa-check-circle',
				'required'    => 0,
			),
			array(
				'type'        => 'datepicker',
				'field_key'   => 'expire_date',
				'label'       => __( 'Expiration Date', 'geodir-converter' ),
				'description' => __( 'Listing expiration date.', 'geodir-converter' ),
				'placeholder' => __( 'Expiration Date', 'geodir-converter' ),
				'icon'        => 'fas fa-calendar-times',
				'required'    => 0,
			),
		);

		// Discover custom fields from listing meta keys.
		$meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE p.post_type = %s
				AND pm.meta_key LIKE %s
				AND pm.meta_key NOT LIKE %s
				AND pm.meta_key NOT LIKE %s
				AND pm.meta_key NOT LIKE %s
				ORDER BY pm.meta_key",
				self::POST_TYPE_LISTING,
				'hp_%',
				'_transient%',
				'%_cache%',
				'%_version'
			)
		);

		$skip_meta_keys = array(
			'hp_featured',
			'hp_verified',
			'hp_expired_time',
			'hp_featured_time',
			'hp_latitude',
			'hp_longitude',
			'hp_rating',
			'hp_rating_count',
			'hp_views',
			'hp_models',
		);

		foreach ( $meta_keys as $meta_key ) {
			// Skip system fields that are already handled elsewhere.
			if ( in_array( $meta_key, $skip_meta_keys, true ) ) {
				continue;
			}

			// Generate field key.
			$field_key = str_replace( 'hp_', '', $meta_key );
			$field_key = str_replace( '-', '_', $field_key );

			// Generate label from key.
			$label = str_replace( array( 'hp_', '_', '-' ), array( '', ' ', ' ' ), $meta_key );
			$label = ucwords( $label );

			// Get sample value to determine type.
			$sample_value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm.meta_value
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					WHERE p.post_type = %s
					AND pm.meta_key = %s
					AND pm.meta_value != ''
					LIMIT 1",
					self::POST_TYPE_LISTING,
					$meta_key
				)
			);

			// Determine field type from sample value.
			$field_type = $this->detect_field_type( $sample_value );

			$fields[] = array(
				'type'        => $field_type,
				'field_key'   => $field_key,
				'label'       => $label,
				'description' => sprintf( __( 'Imported from %s', 'geodir-converter' ), $meta_key ),
				'icon'        => $this->get_icon_for_field( $field_key ),
				'required'    => 0,
			);
		}

		// Query database for HivePress attributes.
		$hp_attributes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_name, p.menu_order
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				WHERE p.post_type = %s
				AND p.post_status = 'publish'
				AND (tt.taxonomy = %s AND t.slug = 'listing' OR tt.taxonomy IS NULL)
				GROUP BY p.ID
				ORDER BY p.menu_order ASC",
				'hp_attribute',
				'hp_attribute_model'
			)
		);

		// Fallback: get all published hp_attribute posts.
		if ( empty( $hp_attributes ) ) {
			$hp_attributes = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_name, menu_order
					FROM {$wpdb->posts}
					WHERE post_type = %s
					AND post_status = 'publish'
					ORDER BY menu_order ASC",
					'hp_attribute'
				)
			);
		}

		if ( ! empty( $hp_attributes ) ) {
			foreach ( $hp_attributes as $attribute ) {
				// Get field type from post meta.
				$field_type_raw = get_post_meta( $attribute->ID, 'hp_edit_field_type', true );
				$field_type     = $this->map_field_type( $field_type_raw );

				if ( ! $field_type ) {
					continue;
				}

				// Get field settings.
				$label       = get_post_meta( $attribute->ID, 'hp_edit_field_label', true );
				$description = get_post_meta( $attribute->ID, 'hp_edit_field_description', true );
				$required    = get_post_meta( $attribute->ID, 'hp_edit_field_required', true );
				$icon        = get_post_meta( $attribute->ID, 'hp_icon', true );

				// Use attribute slug as field key (removing hp_ prefix if present).
				$field_key = str_replace( 'hp_', '', $attribute->post_name );
				$field_key = str_replace( '-', '_', $field_key );

				$field = array(
					'type'        => $field_type,
					'field_key'   => $field_key,
					'label'       => ! empty( $label ) ? $label : $attribute->post_title,
					'description' => $description ? $description : '',
					'icon'        => $icon ? 'fas fa-' . $icon : 'fas fa-info-circle',
					'required'    => $required ? 1 : 0,
				);

				// Handle select/radio/checkbox options - check if it's a taxonomy-based field.
				$taxonomy_name = 'hp_listing_' . $field_key;
				if ( in_array( $field_type, array( 'select', 'multiselect', 'radio' ) ) && taxonomy_exists( $taxonomy_name ) ) {
					// Get terms for this taxonomy.
					$terms = get_terms(
						array(
							'taxonomy'   => $taxonomy_name,
							'hide_empty' => false,
						)
					);

					if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
						$options = array();
						foreach ( $terms as $term ) {
							$options[] = $term->slug . ':' . $term->name;
						}
						$field['options'] = implode( ',', $options );
					}
				} else {
					// Try to get options from post meta.
					$options_meta = get_post_meta( $attribute->ID, 'hp_edit_field_options', true );
					if ( ! empty( $options_meta ) && is_array( $options_meta ) ) {
						$options = array();
						foreach ( $options_meta as $key => $value ) {
							$options[] = $key . ':' . $value;
						}
						$field['options'] = implode( ',', $options );
					}
				}

				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Detect field type from sample value.
	 *
	 * @param mixed $sample_value Sample value.
	 * @return string Field type.
	 */
	private function detect_field_type( $sample_value ) {
		if ( empty( $sample_value ) ) {
			return 'text';
		}

		// Check if it's a URL.
		if ( filter_var( $sample_value, FILTER_VALIDATE_URL ) ) {
			return 'url';
		}

		// Check if it's an email.
		if ( filter_var( $sample_value, FILTER_VALIDATE_EMAIL ) ) {
			return 'email';
		}

		// Check if it's a number.
		if ( is_numeric( $sample_value ) ) {
			// Check if it's a decimal.
			if ( strpos( $sample_value, '.' ) !== false ) {
				return 'text'; // Use text for decimal numbers.
			}
			return 'text'; // Use text for integers too.
		}

		// Check if it's a date.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $sample_value ) ) {
			return 'datepicker';
		}

		// Check if it's a long text (textarea).
		if ( strlen( $sample_value ) > 200 ) {
			return 'textarea';
		}

		// Default to text.
		return 'text';
	}

	/**
	 * Get appropriate icon for field based on field key.
	 *
	 * @param string $field_key Field key.
	 * @return string Icon class.
	 */
	private function get_icon_for_field( $field_key ) {
		$icon_map = array(
			'phone'       => 'fas fa-phone',
			'email'       => 'fas fa-envelope',
			'website'     => 'fas fa-globe',
			'address'     => 'fas fa-map-marker-alt',
			'location'    => 'fas fa-map-marker-alt',
			'price'       => 'fas fa-dollar-sign',
			'rate'        => 'fas fa-dollar-sign',
			'hourly_rate' => 'fas fa-dollar-sign',
			'rating'      => 'fas fa-star',
			'facebook'    => 'fab fa-facebook',
			'twitter'     => 'fab fa-twitter',
			'instagram'   => 'fab fa-instagram',
			'linkedin'    => 'fab fa-linkedin',
			'youtube'     => 'fab fa-youtube',
			'whatsapp'    => 'fab fa-whatsapp',
		);

		foreach ( $icon_map as $keyword => $icon ) {
			if ( strpos( $field_key, $keyword ) !== false ) {
				return $icon;
			}
		}

		return 'fas fa-info-circle';
	}

	/**
	 * Map HivePress field type to GeoDirectory field type.
	 *
	 * @param string $hp_type HivePress field type.
	 * @return string|false GeoDirectory field type or false if not supported.
	 */
	private function map_field_type( $hp_type ) {
		$type_map = array(
			'text'              => 'text',
			'textarea'          => 'textarea',
			'email'             => 'email',
			'url'               => 'url',
			'number'            => 'text',
			'phone'             => 'phone',
			'date'              => 'datepicker',
			'time'              => 'time',
			'select'            => 'select',
			'radio'             => 'radio',
			'checkbox'          => 'checkbox',
			'checkboxes'        => 'multiselect',
			'file'              => 'file',
			'attachment_upload' => 'file',
		);

		return isset( $type_map[ $hp_type ] ) ? $type_map[ $hp_type ] : false;
	}

	/**
	 * Get database data type for field type.
	 *
	 * @param string $field_type Field type.
	 * @return string Data type.
	 */
	private function map_data_type( $field_type ) {
		$type_map = array(
			'text'        => 'VARCHAR',
			'email'       => 'VARCHAR',
			'url'         => 'TEXT',
			'phone'       => 'VARCHAR',
			'textarea'    => 'TEXT',
			'checkbox'    => 'TINYINT',
			'datepicker'  => 'DATE',
			'time'        => 'VARCHAR',
			'radio'       => 'VARCHAR',
			'select'      => 'VARCHAR',
			'multiselect' => 'VARCHAR',
			'file'        => 'TEXT',
		);

		return isset( $type_map[ $field_type ] ) ? $type_map[ $field_type ] : 'VARCHAR';
	}

	/**
	 * Import fields from HivePress to GeoDirectory.
	 *
	 * @since 2.2.0
	 * @param array $task Task details.
	 * @return array Result of the import operation.
	 */
	public function task_import_fields( array $task ) {
		$this->log( esc_html__( 'Importing custom fields...', 'geodir-converter' ) );

		$post_type   = $this->get_import_post_type();
		$fields      = $this->get_custom_fields();
		$package_ids = $this->get_package_ids( $post_type );

		if ( empty( $fields ) ) {
			$this->log( sprintf( __( 'No custom fields found for post type: %s', 'geodir-converter' ), $post_type ), 'warning' );
			return $this->next_task( $task );
		}

		$imported = $updated = $skipped = $failed = 0;

		foreach ( $fields as $field ) {
			$gd_field = $this->prepare_single_field( $field, $post_type, $package_ids );

			if ( $this->should_skip_field( $gd_field['htmlvar_name'] ) ) {
				++$skipped;
				$this->log( sprintf( __( 'Skipped custom field: %s', 'geodir-converter' ), $field['label'] ), 'warning' );
				continue;
			}

			$field_exists = ! empty( $gd_field['field_id'] );

			if ( $this->is_test_mode() ) {
				$field_exists ? $updated++ : $imported++;
				continue;
			}

			$result = geodir_custom_field_save( $gd_field );

			if ( $result && ! is_wp_error( $result ) ) {
				if ( $field_exists ) {
					++$updated;
				} else {
					++$imported;
				}
			} else {
				++$failed;
				$error_msg = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown error', 'geodir-converter' );
				$this->log( sprintf( __( 'Failed to import custom field: %1$s - %2$s', 'geodir-converter' ), $field['label'], $error_msg ), 'error' );
			}
		}

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_skipped_imports( $skipped );
		$this->increase_failed_imports( $failed );

		$this->log(
			sprintf(
				__( 'Listing fields import completed: %1$d imported, %2$d updated, %3$d skipped, %4$d failed.', 'geodir-converter' ),
				$imported,
				$updated,
				$skipped,
				$failed
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Prepare single field for GeoDirectory.
	 *
	 * @param array  $field HivePress field data.
	 * @param string $post_type Post type.
	 * @param array  $package_ids Package IDs.
	 * @return array GeoDirectory field data.
	 */
	private function prepare_single_field( $field, $post_type, $package_ids = array() ) {
		$field_type = isset( $field['type'] ) ? $field['type'] : 'text';
		$field_id   = $this->field_exists( $field['field_key'], $post_type );

		$gd_field = array(
			'post_type'         => $post_type,
			'data_type'         => $this->map_data_type( $field_type ),
			'field_type'        => $field_type,
			'htmlvar_name'      => $field['field_key'],
			'admin_title'       => $field['label'],
			'frontend_title'    => $field['label'],
			'frontend_desc'     => isset( $field['description'] ) ? $field['description'] : '',
			'placeholder_value' => isset( $field['placeholder'] ) ? $field['placeholder'] : '',
			'default_value'     => '',
			'is_active'         => '1',
			'for_admin_use'     => in_array( $field['field_key'], array( $this->importer_id . '_id' ), true ) ? 1 : 0,
			'is_required'       => isset( $field['required'] ) && 1 === $field['required'] ? 1 : 0,
			'show_in'           => '[detail]',
			'show_on_pkg'       => $package_ids,
			'clabels'           => $field['label'],
			'option_values'     => isset( $field['options'] ) ? $field['options'] : '',
			'field_icon'        => isset( $field['icon'] ) ? $field['icon'] : 'fas fa-info-circle',
		);

		if ( $field_id ) {
			$gd_field['field_id'] = $field_id;
		}

		return $gd_field;
	}

	/**
	 * Parse and batch listings for background import.
	 *
	 * @since 2.2.0
	 * @param array $task The task to import.
	 * @return array Result of the import operation.
	 */
	public function task_parse_listings( array $task ) {
		global $wpdb;

		$offset         = isset( $task['offset'] ) ? (int) $task['offset'] : 0;
		$total_listings = isset( $task['total_listings'] ) ? (int) $task['total_listings'] : 0;
		$batch_size     = (int) $this->get_batch_size();

		if ( ! isset( $task['total_listings'] ) ) {
			$total_listings         = $this->count_listings();
			$task['total_listings'] = $total_listings;
		}

		if ( 0 === $offset ) {
			$this->log( __( 'Starting listings parsing process...', 'geodir-converter' ) );
		}

		if ( 0 === $total_listings ) {
			$this->log( __( 'No listings found for parsing. Skipping process.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		wp_suspend_cache_addition( false );

		$listings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_status
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_status IN (" . implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) ) . ')
				LIMIT %d OFFSET %d',
				array_merge(
					array( self::POST_TYPE_LISTING ),
					$this->post_statuses,
					array( $batch_size, $offset )
				)
			)
		);

		if ( empty( $listings ) ) {
			$this->log( __( 'Import process completed. No more listings found.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$batched_tasks = array_chunk( $listings, $this->batch_size, true );
		$import_tasks  = array();
		foreach ( $batched_tasks as $batch ) {
			$import_tasks[] = array(
				'action'   => GeoDir_Converter_Importer::ACTION_IMPORT_LISTINGS,
				'listings' => $batch,
			);
		}

		$this->background_process->add_import_tasks( $import_tasks );

		$complete = ( $offset + $batch_size >= $total_listings );

		if ( ! $complete ) {
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		return $this->next_task( $task, true );
	}

	/**
	 * Import a batch of listings (called by background process).
	 *
	 * @since 2.2.0
	 * @param array $task The task to import.
	 * @return bool Result of the import operation.
	 */
	public function task_import_listings( $task ) {
		$listings = isset( $task['listings'] ) && ! empty( $task['listings'] ) ? (array) $task['listings'] : array();

		foreach ( $listings as $listing ) {
			$title  = $listing->post_title;
			$status = $this->import_single_listing( $listing );

			switch ( $status ) {
				case self::IMPORT_STATUS_SUCCESS:
				case self::IMPORT_STATUS_UPDATED:
					if ( self::IMPORT_STATUS_SUCCESS === $status ) {
						$this->log( sprintf( self::LOG_TEMPLATE_SUCCESS, 'listing', $title ), 'success' );
					} else {
						$this->log( sprintf( self::LOG_TEMPLATE_UPDATED, 'listing', $title ), 'warning' );
					}

					$this->increase_succeed_imports( 1 );
					break;
				case self::IMPORT_STATUS_SKIPPED:
					$this->log( sprintf( self::LOG_TEMPLATE_SKIPPED, 'listing', $title ), 'warning' );
					$this->increase_skipped_imports( 1 );
					break;
				case self::IMPORT_STATUS_FAILED:
				default:
					$this->log( sprintf( self::LOG_TEMPLATE_FAILED, 'listing', $title ), 'warning' );
					$this->increase_failed_imports( 1 );
					break;
			}
		}

		return false;
	}

	/**
	 * Convert a single HivePress listing to GeoDirectory format.
	 *
	 * @since 2.2.0
	 * @param  object $listing The post object to convert.
	 * @return int Import status.
	 */
	private function import_single_listing( $listing ) {
		$post             = get_post( $listing->ID );
		$post_type        = $this->get_import_post_type();
		$gd_post_id       = ! $this->is_test_mode() ? $this->get_gd_listing_id( $post->ID, $this->importer_id . '_id', $post_type ) : false;
		$is_update        = ! empty( $gd_post_id );
		$post_meta        = $this->get_post_meta( $post->ID );
		$default_location = $this->get_default_location();

		// Get categories.
		$categories = $this->get_listings_terms( $post->ID, self::TAX_LISTING_CATEGORY );

		// Location data.
		$location = $default_location;

		// HivePress may store location data differently depending on extensions.
		$has_coordinates = isset( $post_meta['hp_latitude'], $post_meta['hp_longitude'] ) && ! empty( $post_meta['hp_latitude'] ) && ! empty( $post_meta['hp_longitude'] );
		$latitude        = isset( $post_meta['hp_latitude'] ) && ! empty( $post_meta['hp_latitude'] ) ? $post_meta['hp_latitude'] : $location['latitude'];
		$longitude       = isset( $post_meta['hp_longitude'] ) && ! empty( $post_meta['hp_longitude'] ) ? $post_meta['hp_longitude'] : $location['longitude'];
		$address         = isset( $post_meta['hp_address'] ) && ! empty( $post_meta['hp_address'] ) ? $post_meta['hp_address'] : '';

		if ( $has_coordinates ) {
			$this->log( 'Pulling listing address from coordinates: ' . $latitude . ', ' . $longitude, 'info' );

			$location_lookup = GeoDir_Converter_Utils::get_location_from_coords( $latitude, $longitude );

			if ( ! is_wp_error( $location_lookup ) ) {
				$location = array_merge( $location, $location_lookup );
			} else {
				$location['latitude']  = $latitude;
				$location['longitude'] = $longitude;
			}
		}

		// Prepare the listing data.
		$listing_data = array(
			// Standard WP Fields.
			'post_author'              => $post->post_author ? $post->post_author : get_current_user_id(),
			'post_title'               => $post->post_title,
			'post_content'             => $post->post_content ? $post->post_content : '',
			'post_content_filtered'    => $post->post_content,
			'post_excerpt'             => $post->post_excerpt ? $post->post_excerpt : '',
			'post_status'              => $post->post_status,
			'post_type'                => $post_type,
			'comment_status'           => $post->comment_status,
			'ping_status'              => $post->ping_status,
			'post_name'                => $post->post_name ? $post->post_name : 'listing-' . $post->ID,
			'post_date_gmt'            => $post->post_date_gmt,
			'post_date'                => $post->post_date,
			'post_modified_gmt'        => $post->post_modified_gmt,
			'post_modified'            => $post->post_modified,
			'tax_input'                => array(
				"{$post_type}category" => $categories,
			),

			// GD fields.
			'default_category'         => ! empty( $categories ) ? $categories[0] : 0,
			'featured_image'           => $this->get_featured_image( $post->ID ),
			'submit_ip'                => '',
			'overall_rating'           => 0,
			'rating_count'             => 0,

			'street'                   => $address,
			'street2'                  => '',
			'city'                     => isset( $location['city'] ) ? $location['city'] : '',
			'region'                   => isset( $location['region'] ) ? $location['region'] : '',
			'country'                  => isset( $location['country'] ) ? $location['country'] : '',
			'zip'                      => isset( $location['zip'] ) ? $location['zip'] : '',
			'latitude'                 => isset( $location['latitude'] ) ? $location['latitude'] : '',
			'longitude'                => isset( $location['longitude'] ) ? $location['longitude'] : '',
			'mapview'                  => '',
			'mapzoom'                  => '',

			// HivePress standard fields.
			$this->importer_id . '_id' => $post->ID,
			'featured'                 => ! empty( $post_meta['hp_featured'] ) ? 1 : 0,
			'verified'                 => ! empty( $post_meta['hp_verified'] ) ? 1 : 0,
		);

		// Process expiration.
		if ( ! empty( $post_meta['hp_expired_time'] ) ) {
			$listing_data['expire_date'] = date( 'Y-m-d', absint( $post_meta['hp_expired_time'] ) );
		}

		// Handle test mode.
		if ( $this->is_test_mode() ) {
			return self::IMPORT_STATUS_SUCCESS;
		}

		// Delete existing media if updating.
		if ( $is_update ) {
			GeoDir_Media::delete_files( (int) $gd_post_id, 'post_images' );
		}

		// Process gallery images.
		$listing_data['post_images'] = $this->get_post_images( $post->ID );

		// Process custom attributes.
		$custom_fields = $this->process_custom_attributes( $post, $post_meta );

		if ( ! empty( $custom_fields ) ) {
			// Core fields that should not be overridden by custom attributes.
			$core_fields = array(
				'post_author',
				'post_title',
				'post_content',
				'post_status',
				'post_type',
				'post_name',
				'post_date',
				'default_category',
				'latitude',
				'longitude',
			);

			foreach ( $custom_fields as $key => $value ) {
				// Skip if it's a core field and already has a value.
				if ( in_array( $key, $core_fields, true ) && ! empty( $listing_data[ $key ] ) ) {
					continue;
				}

				// Add the custom field value.
				$listing_data[ $key ] = $value;
			}
		}

		// Insert or update the post.
		if ( $is_update ) {
			$gd_post_id = wp_update_post( array_merge( array( 'ID' => $gd_post_id ), $listing_data ), true );
		} else {
			$gd_post_id = wp_insert_post( $listing_data, true );
		}

		// Handle errors during post insertion/update.
		if ( is_wp_error( $gd_post_id ) ) {
			$this->log( $gd_post_id->get_error_message() );
			return self::IMPORT_STATUS_FAILED;
		}

		// Import comments/reviews.
		$this->import_comments( $post->ID, $gd_post_id );

		return $is_update ? self::IMPORT_STATUS_UPDATED : self::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Import comments from HivePress listing to GeoDirectory listing.
	 *
	 * @since 2.2.0
	 * @param int $hp_listing_id HivePress listing ID.
	 * @param int $gd_post_id GeoDirectory post ID.
	 * @return void
	 */
	private function import_comments( $hp_listing_id, $gd_post_id ) {
		global $wpdb;

		if ( $this->is_test_mode() ) {
			return;
		}

		// Get all comments for the HivePress listing.
		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_post_ID, comment_author, comment_author_email, 
				        comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
				        comment_content, comment_karma, comment_approved, comment_agent,
				        comment_type, comment_parent, user_id
				FROM {$wpdb->comments}
				WHERE comment_post_ID = %d",
				$hp_listing_id
			)
		);

		if ( empty( $comments ) ) {
			return;
		}

		foreach ( $comments as $comment ) {
			// Check if comment already reassigned.
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT comment_ID FROM {$wpdb->comments}
					WHERE comment_post_ID = %d
					AND comment_date = %s
					AND comment_author_email = %s",
					$gd_post_id,
					$comment->comment_date,
					$comment->comment_author_email
				)
			);

			if ( $existing ) {
				continue;
			}

			// Update comment to point to new listing.
			$wpdb->update(
				$wpdb->comments,
				array( 'comment_post_ID' => $gd_post_id ),
				array( 'comment_ID' => $comment->comment_ID ),
				array( '%d' ),
				array( '%d' )
			);

			// If it's a review with rating, ensure rating meta is properly set.
			if ( $comment->comment_type === 'review' || strpos( $comment->comment_type, 'review' ) !== false ) {
				// Check if rating exists in comment meta.
				$rating = get_comment_meta( $comment->comment_ID, 'rating', true );

				if ( $rating && class_exists( 'GeoDir_Comments' ) ) {
					$_REQUEST['geodir_overallrating'] = absint( $rating );
					GeoDir_Comments::save_rating( $comment->comment_ID );
					unset( $_REQUEST['geodir_overallrating'] );
				}
			}
		}

		// Update comment count for the new post.
		wp_update_comment_count( $gd_post_id );
	}

	/**
	 * Process HivePress custom attributes.
	 *
	 * @param object $post Post object.
	 * @param array  $post_meta Post meta data.
	 * @return array Processed custom fields.
	 */
	private function process_custom_attributes( $post, $post_meta ) {
		$fields = array();

		// System fields that are already handled or should not be imported.
		$skip_meta_keys = array(
			'hp_featured',
			'hp_verified',
			'hp_expired_time',
			'hp_featured_time',
			'hp_latitude',
			'hp_longitude',
			'hp_rating',
			'hp_rating_count',
			'hp_views',
			'hp_models',
		);

		// Process all hp_* meta keys.
		foreach ( $post_meta as $meta_key => $meta_value ) {
			// Only process hp_* keys.
			if ( strpos( $meta_key, 'hp_' ) !== 0 ) {
				continue;
			}

			// Skip system fields that are handled elsewhere.
			if ( in_array( $meta_key, $skip_meta_keys, true ) ) {
				continue;
			}

			// Generate field key.
			$field_key = str_replace( 'hp_', '', $meta_key );
			$field_key = str_replace( '-', '_', $field_key );

			$value = $meta_value;

			// Handle serialized data.
			if ( is_string( $value ) && is_serialized( $value ) ) {
				$value = maybe_unserialize( $value );
			}

			// Handle arrays for multi-select fields.
			if ( is_array( $value ) ) {
				$value = implode( ',', array_map( 'trim', $value ) );
			}

			// Handle boolean values for checkboxes.
			if ( is_bool( $value ) ) {
				$value = $value ? 1 : 0;
			}

			// Skip empty values.
			if ( ! empty( $value ) || $value === '0' || $value === 0 ) {
				$fields[ $field_key ] = $value;
			}
		}

		// Also check for taxonomy-based attributes.
		$all_taxonomies = get_object_taxonomies( self::POST_TYPE_LISTING );
		foreach ( $all_taxonomies as $taxonomy ) {
			// Only process hp_listing_* taxonomies.
			if ( strpos( $taxonomy, 'hp_listing_' ) !== 0 ) {
				continue;
			}

			// Skip the main category taxonomy.
			if ( $taxonomy === self::TAX_LISTING_CATEGORY ) {
				continue;
			}

			// Get terms for this taxonomy.
			$terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$field_key            = str_replace( 'hp_listing_', '', $taxonomy );
				$field_key            = str_replace( '-', '_', $field_key );
				$fields[ $field_key ] = implode( ',', $terms );
			}
		}

		return $fields;
	}

	/**
	 * Get post images.
	 *
	 * @param int $post_id The post ID.
	 * @return string Formatted gallery images string for GeoDirectory.
	 */
	private function get_post_images( $post_id ) {
		$images         = array();
		$attachment_ids = array();

		// Get featured image.
		if ( has_post_thumbnail( $post_id ) ) {
			$attachment_ids[] = get_post_thumbnail_id( $post_id );
		}

		// Get attached images.
		$attached_media = get_attached_media( 'image', $post_id );

		if ( ! empty( $attached_media ) ) {
			foreach ( $attached_media as $attachment ) {
				$attachment_ids[] = $attachment->ID;
			}
		}

		// Remove duplicates.
		$attachment_ids = array_unique( $attachment_ids );

		if ( ! empty( $attachment_ids ) ) {
			foreach ( $attachment_ids as $index => $id ) {
				$images[] = array(
					'id'      => (int) $id,
					'caption' => get_post_meta( $id, '_wp_attachment_image_alt', true ),
					'weight'  => $index + 1,
				);
			}
		}

		return $this->format_images_data( $images );
	}

	/**
	 * Get the featured image URL.
	 *
	 * @param int $post_id The post ID.
	 * @return string The featured image URL.
	 */
	private function get_featured_image( $post_id ) {
		$image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
		return isset( $image[0] ) ? esc_url( $image[0] ) : '';
	}

	/**
	 * Get listings terms (categories).
	 *
	 * @param int    $post_id The post ID.
	 * @param string $taxonomy The taxonomy to get terms from.
	 * @param string $return_type Return 'ids' or 'names'.
	 * @return array Array of term IDs or names.
	 */
	private function get_listings_terms( $post_id, $taxonomy = self::TAX_LISTING_CATEGORY, $return_type = 'ids' ) {
		global $wpdb;

		$terms = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, tm.meta_value as gd_equivalent
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
				LEFT JOIN {$wpdb->termmeta} tm ON t.term_id = tm.term_id and tm.meta_key = 'gd_equivalent'
				WHERE tr.object_id = %d and tt.taxonomy = %s",
				$post_id,
				$taxonomy
			)
		);

		$categories = array();

		foreach ( $terms as $term ) {
			$gd_term_id = (int) $term->gd_equivalent;

			if ( $gd_term_id ) {
				$gd_term = $wpdb->get_row( $wpdb->prepare( "SELECT name, term_id FROM {$wpdb->terms} WHERE term_id = %d", $gd_term_id ) );

				if ( $gd_term ) {
					$categories[] = ( 'names' === $return_type ) ? $gd_term->name : $gd_term->term_id;
				}
			}
		}

		return $categories;
	}

	/**
	 * Count the number of listings.
	 *
	 * @return int The number of listings.
	 */
	private function count_listings() {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} 
				WHERE post_type = %s 
				AND post_status IN (" . implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) ) . ')',
				array_merge( array( self::POST_TYPE_LISTING ), $this->post_statuses )
			)
		);

		return $count;
	}

	/**
	 * Count the number of HivePress packages.
	 *
	 * @return int The number of packages.
	 */
	private function count_packages() {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_status = 'publish'",
				self::POST_TYPE_PACKAGE
			)
		);

		return $count ? $count : 0;
	}

	/**
	 * Import packages from HivePress to GeoDirectory.
	 *
	 * @since 2.2.0
	 * @param array $task Task details.
	 * @return array Result of the import operation.
	 */
	public function task_import_packages( array $task ) {
		global $wpdb;

		if ( ! class_exists( 'GeoDir_Pricing_Package' ) ) {
			$this->log( __( 'Pricing Manager not active. Skipping packages...', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$this->log( __( 'Importing packages...', 'geodir-converter' ) );

		$post_type = $this->get_import_post_type();

		// Get HivePress listing packages.
		$packages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content, post_status, post_parent, menu_order
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_status = 'publish'
				ORDER BY menu_order ASC",
				'hp_listing_package'
			)
		);

		if ( empty( $packages ) ) {
			$this->log( __( 'Packages: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$imported = $updated = $failed = 0;

		foreach ( $packages as $package ) {
			$package_id   = absint( $package->ID );
			$package_meta = $this->get_post_meta( $package_id );

			// Get HivePress package settings from post meta.
			$submit_limit  = isset( $package_meta['hp_submit_limit'] ) ? absint( $package_meta['hp_submit_limit'] ) : 1;
			$expire_period = isset( $package_meta['hp_expire_period'] ) ? absint( $package_meta['hp_expire_period'] ) : 30;
			$is_featured   = isset( $package_meta['hp_featured'] ) && $package_meta['hp_featured'];
			$is_primary    = isset( $package_meta['hp_primary'] ) && $package_meta['hp_primary'];

			// Get price from linked WooCommerce product if exists.
			$price      = 0.0;
			$product_id = absint( $package->post_parent );
			if ( $product_id ) {
				$product_meta = $this->get_post_meta( $product_id );
				$price        = isset( $product_meta['_regular_price'] ) ? floatval( $product_meta['_regular_price'] ) : 0.0;
				if ( empty( $price ) && isset( $product_meta['_price'] ) ) {
					$price = floatval( $product_meta['_price'] );
				}
			}

			$is_free = $price <= 0;

			// Check if package already exists.
			$existing_package = $this->package_exists( $post_type, $package_id, $is_free );

			$package_data = array(
				'post_type'       => $post_type,
				'name'            => $package->post_title,
				'title'           => $package->post_title,
				'description'     => $package->post_content,
				'fa_icon'         => $is_featured ? 'fas fa-star' : '',
				'amount'          => $price,
				'time_interval'   => $expire_period > 0 ? $expire_period : 30,
				'time_unit'       => 'D',
				'recurring'       => false,
				'recurring_limit' => 0,
				'trial'           => '',
				'trial_amount'    => '',
				'trial_interval'  => '',
				'trial_unit'      => '',
				'is_default'      => $is_primary ? 1 : 0,
				'display_order'   => (int) $package->menu_order,
				'downgrade_pkg'   => 0,
				'post_status'     => 'pending',
				'status'          => true,
			);

			if ( $existing_package ) {
				$package_data['id'] = absint( $existing_package->id );
			}

			if ( $this->is_test_mode() ) {
				$existing_package ? $updated++ : $imported++;
				continue;
			}

			$package_data  = GeoDir_Pricing_Package::prepare_data_for_save( $package_data );
			$gd_package_id = GeoDir_Pricing_Package::insert_package( $package_data );

			if ( ! $gd_package_id || is_wp_error( $gd_package_id ) ) {
				$this->log( sprintf( __( 'Failed to import package: %s', 'geodir-converter' ), $package->post_title ), 'error' );
				++$failed;
			} else {
				$is_update = ! empty( $existing_package );

				if ( $is_update ) {
					++$updated;
				} else {
					++$imported;
				}

				// Store reference to original HivePress package.
				if ( ! $this->package_has_meta( $gd_package_id ) ) {
					GeoDir_Pricing_Package::update_meta( $gd_package_id, '_hivepress_package_id', $package_id );
				}
			}
		}

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_failed_imports( $failed );

		$this->log(
			sprintf(
				__( 'Packages: %1$d imported, %2$d updated, %3$d failed', 'geodir-converter' ),
				$imported,
				$updated,
				$failed
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Check if package exists by HivePress package ID.
	 *
	 * @since 2.2.0
	 * @param string $post_type Post type.
	 * @param int    $package_id HivePress package ID.
	 * @param bool   $free_fallback Whether to fallback to free package if no match found.
	 * @return object|null Existing package object or null.
	 */
	private function package_exists( $post_type, $package_id, $free_fallback = true ) {
		global $wpdb;

		$existing_package = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT p.*, g.* 
				FROM ' . GEODIR_PRICING_PACKAGES_TABLE . ' AS p
				INNER JOIN ' . GEODIR_PRICING_PACKAGE_META_TABLE . ' AS g ON p.ID = g.package_id
				WHERE p.post_type = %s AND g.meta_key = %s AND g.meta_value = %d
				LIMIT 1',
				$post_type,
				'_hivepress_package_id',
				(int) $package_id
			)
		);

		if ( ! $existing_package && $free_fallback ) {
			$existing_package = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . GEODIR_PRICING_PACKAGES_TABLE . ' 
					WHERE post_type = %s AND amount = 0 AND status = 1
					ORDER BY display_order ASC, ID ASC
					LIMIT 1',
					$post_type
				)
			);
		}

		return $existing_package;
	}

	/**
	 * Check if package already has HivePress meta assigned.
	 *
	 * @since 2.2.0
	 * @param int $package_id Package ID.
	 * @return bool True if package has HivePress meta, false otherwise.
	 */
	private function package_has_meta( $package_id ) {
		global $wpdb;

		$meta_value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM ' . GEODIR_PRICING_PACKAGE_META_TABLE . ' 
				WHERE package_id = %d AND meta_key = %s
				LIMIT 1',
				(int) $package_id,
				'_hivepress_package_id'
			)
		);

		return ! empty( $meta_value );
	}
}
