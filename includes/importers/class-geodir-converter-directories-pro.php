<?php
/**
 * Directories Pro Converter Class.
 *
 * @since     2.2.0
 * @package   GeoDir_Converter
 */

namespace GeoDir_Converter\Importers;

use WP_Error;
use GeoDir_Media;
use GeoDir_Comments;
use GeoDir_Converter\GeoDir_Converter_Utils;
use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Main converter class for importing from Directories Pro.
 *
 * @since 2.2.0
 */
class GeoDir_Converter_Directories_Pro extends GeoDir_Converter_Importer {

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
	protected $importer_id = 'directories_pro';

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
		return __( 'Directories Pro', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Import listings from your Directories Pro installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @return string
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/directories-pro.jpeg';
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
		$drts_bundles = $this->get_available_bundles();

		if ( empty( $drts_bundles ) ) {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'No Directories Pro bundles found. Please ensure Directories Pro is installed and has created content.', 'geodir-converter' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<form class="geodir-converter-settings-form" method="post">
			<h6 class="fs-base"><?php esc_html_e( 'Directories Pro Importer Settings', 'geodir-converter' ); ?></h6>

			<?php
			$this->display_post_type_select();
			$this->display_bundle_select( $drts_bundles );
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
		$settings['test_mode']    = ( isset( $settings['test_mode'] ) && ! empty( $settings['test_mode'] ) && 'no' !== $settings['test_mode'] ) ? 'yes' : 'no';
		$settings['drts_bundle']  = isset( $settings['drts_bundle'] ) && ! empty( $settings['drts_bundle'] ) ? sanitize_text_field( $settings['drts_bundle'] ) : '';

		if ( ! in_array( $settings['gd_post_type'], $post_types, true ) ) {
			$errors[] = esc_html__( 'The selected post type is invalid. Please choose a valid post type.', 'geodir-converter' );
		}

		if ( empty( $settings['drts_bundle'] ) ) {
			$errors[] = esc_html__( 'Please select a Directories Pro bundle to import from.', 'geodir-converter' );
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
			self::ACTION_IMPORT_TAGS,
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
		$bundle_name = $this->get_import_setting( 'drts_bundle', '' );

		if ( empty( $bundle_name ) ) {
			return;
		}

		$bundle_info = $this->get_bundle_info( $bundle_name );
		if ( ! $bundle_info ) {
			return;
		}

		$post_type = $bundle_info['post_type'];

		// Count categories.
		$category_taxonomy = $this->get_category_taxonomy( $bundle_name, $bundle_info );
		if ( ! empty( $category_taxonomy ) ) {
			$categories   = (int) wp_count_terms( $category_taxonomy );
			$total_items += $categories;
		}

		// Count tags.
		$tag_taxonomy = $this->get_tag_taxonomy( $bundle_name, $bundle_info );
		if ( ! empty( $tag_taxonomy ) ) {
			$tags         = (int) wp_count_terms( $tag_taxonomy );
			$total_items += $tags;
		}

		// Count custom fields.
		$custom_fields = $this->get_custom_fields();
		$total_items  += (int) count( $custom_fields );

		// Count listings.
		$total_items += (int) $this->count_listings( $post_type );

		$this->increase_imports_total( $total_items );
	}

	/**
	 * Import categories from Directories Pro to GeoDirectory.
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

		$bundle_name = $this->get_import_setting( 'drts_bundle', '' );
		if ( empty( $bundle_name ) ) {
			$this->log( esc_html__( 'Categories: No bundle selected.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$bundle_info       = $this->get_bundle_info( $bundle_name );
		$category_taxonomy = $this->get_category_taxonomy( $bundle_name, $bundle_info );

		if ( empty( $category_taxonomy ) ) {
			$post_type      = ! empty( $bundle_info['post_type'] ) ? $bundle_info['post_type'] : '';
			$all_taxonomies = ! empty( $post_type ) ? get_object_taxonomies( $post_type ) : array();
			$this->log(
				sprintf(
					/* translators: %1$s: post type, %2$s: list of taxonomies */
					esc_html__( 'Categories: No category taxonomy found for post type %1$s. Available taxonomies: %2$s', 'geodir-converter' ),
					$post_type,
					implode( ', ', $all_taxonomies )
				),
				'warning'
			);
			return $this->next_task( $task );
		}

		if ( 0 === (int) wp_count_terms( $category_taxonomy ) ) {
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
				$category_taxonomy
			)
		);

		if ( empty( $categories ) || is_wp_error( $categories ) ) {
			$this->log( esc_html__( 'Categories: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		if ( $this->is_test_mode() ) {
			$this->log(
				sprintf(
				/* translators: %1$d: number of imported categories, %2$d: number of failed categories */
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
				/* translators: %1$d: number of imported categories, %2$d: number of failed categories */
				esc_html__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$result['imported'],
				$result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Import tags from Directories Pro to GeoDirectory.
	 *
	 * @since 2.2.0
	 * @param array $task Import task.
	 *
	 * @return array Result of the import operation.
	 */
	public function task_import_tags( $task ) {
		global $wpdb;

		$this->log( esc_html__( 'Tags: Import started.', 'geodir-converter' ) );

		$bundle_name = $this->get_import_setting( 'drts_bundle', '' );
		if ( empty( $bundle_name ) ) {
			$this->log( esc_html__( 'Tags: No bundle selected.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$bundle_info  = $this->get_bundle_info( $bundle_name );
		$tag_taxonomy = $this->get_tag_taxonomy( $bundle_name, $bundle_info );

		if ( empty( $tag_taxonomy ) ) {
			$post_type      = ! empty( $bundle_info['post_type'] ) ? $bundle_info['post_type'] : '';
			$all_taxonomies = ! empty( $post_type ) ? get_object_taxonomies( $post_type ) : array();
			$this->log(
				sprintf(
					/* translators: %1$s: post type, %2$s: list of taxonomies */
					esc_html__( 'Tags: No tag taxonomy found for post type %1$s. Available taxonomies: %2$s', 'geodir-converter' ),
					$post_type,
					implode( ', ', $all_taxonomies )
				),
				'warning'
			);
			return $this->next_task( $task );
		}

		if ( 0 === (int) wp_count_terms( $tag_taxonomy ) ) {
			$this->log( esc_html__( 'Tags: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$post_type = $this->get_import_post_type();

		$tags = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, tt.*
				FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				$tag_taxonomy
			)
		);

		if ( empty( $tags ) || is_wp_error( $tags ) ) {
			$this->log( esc_html__( 'Tags: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		if ( $this->is_test_mode() ) {
			$this->log(
				sprintf(
				/* translators: %1$d: number of imported tags, %2$d: number of failed tags */
					esc_html__( 'Tags: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
					count( $tags ),
					0
				),
				'success'
			);
			return $this->next_task( $task );
		}

		$result = $this->import_taxonomy_terms( $tags, $post_type . '_tags', '' );

		$this->increase_succeed_imports( (int) $result['imported'] );
		$this->increase_failed_imports( (int) $result['failed'] );

		$this->log(
			sprintf(
				/* translators: %1$d: number of imported tags, %2$d: number of failed tags */
				esc_html__( 'Tags: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$result['imported'],
				$result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Get custom fields for Directories Pro listings.
	 *
	 * @return array The custom fields.
	 */
	private function get_custom_fields() {
		global $wpdb;

		$fields = array();

		$bundle_name = $this->get_import_setting( 'drts_bundle', '' );
		if ( empty( $bundle_name ) ) {
			return $fields;
		}

		$table_name   = $wpdb->prefix . 'drts_entity_field';
		$config_table = $wpdb->prefix . 'drts_entity_fieldconfig';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return $fields;
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $config_table ) ) !== $config_table ) {
			return $fields;
		}

		$fields = array(
			array(
				'type'           => 'number',
				'field_key'      => $this->importer_id . '_id',
				'label'          => __( 'Directories Pro ID', 'geodir-converter' ),
				'description'    => __( 'Original Directories Pro Listing ID.', 'geodir-converter' ),
				'placeholder'    => __( 'Directories Pro ID', 'geodir-converter' ),
				'icon'           => 'far fa-id-card',
				'only_for_admin' => 1,
				'required'       => 0,
			),
		);

		// Validate table names to prevent SQL injection.
		// Table names are safe as they come from $wpdb->prefix, but we validate the pattern.
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table_name ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $config_table ) ) {
			return $fields;
		}

		$table_name   = esc_sql( $table_name );
		$config_table = esc_sql( $config_table );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.*, fc.fieldconfig_type, fc.fieldconfig_settings, fc.fieldconfig_schema 
				FROM `{$table_name}` f 
				LEFT JOIN `{$config_table}` fc ON f.field_fieldconfig_name = fc.fieldconfig_name 
				WHERE f.field_bundle_name = %s",
				$bundle_name
			)
		);

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$field_data_raw        = maybe_unserialize( $row->field_data );
				$field_config_settings = maybe_unserialize( $row->fieldconfig_settings );

				$field_name  = ! empty( $row->field_fieldconfig_name ) ? $row->field_fieldconfig_name : 'field_' . $row->field_id;
				$field_label = ! empty( $field_data_raw['label'] ) ? $field_data_raw['label'] : $field_name;
				$field_type  = $row->fieldconfig_type;

				// Handle social_accounts field type - split into individual social media fields.
				if ( 'social_accounts' === $field_type ) {
					$post_type     = $this->get_import_post_type();
					$social_fields = $this->get_social_media_fields( $field_label, $post_type );
					foreach ( $social_fields as $social_field ) {
						$social_field['original_key'] = $field_name; // Store original for data mapping.
						$fields[]                     = $social_field;
					}
					// Always add mapping for social_accounts even if fields exist.
					continue;
				}

				// Handle opening hours fields -> business_hours.
				// Check both field type and field name for various opening hours field variations.
				$opening_hours_field_names = array( 'field_opening_hours', 'field_business_hours', 'field_operating_hours' );
				$field_name_clean          = $this->remove_field_prefix( $field_name );
				$is_opening_hours_field    = 'directory_opening_hours' === $field_type
					|| in_array( $field_name, $opening_hours_field_names, true )
					|| in_array( $field_name_clean, array( 'opening_hours', 'business_hours', 'operating_hours' ), true );

				if ( $is_opening_hours_field ) {
					$field    = array(
						'type'          => 'business_hours',
						'field_key'     => 'business_hours',
						'label'         => ! empty( $field_label ) ? $field_label : __( 'Business Hours', 'geodir-converter' ),
						'description'   => ! empty( $field_data_raw['description'] ) ? $field_data_raw['description'] : '',
						'icon'          => 'fas fa-clock',
						'required'      => ! empty( $field_data_raw['required'] ) ? 1 : 0,
						'is_predefined' => true,
						'original_key'  => $field_name,
					);
					$fields[] = $field;
					continue;
				}

				// Skip address fields - they map to GeoDirectory's built-in address fields.
				$address_field = $this->map_to_address_field( $field_name );
				if ( $address_field ) {
					// Don't import as custom field - it will be handled in import_single_listing.
					continue;
				}

				// Check if this is a choice field and determine if it's multiselect BEFORE mapping field type.
				$is_multiselect = false;
				if ( 'choice' === $field_type ) {
					$max_num_items = ! empty( $field_data_raw['max_num_items'] ) ? (int) $field_data_raw['max_num_items'] : 1;
					// Also check widget type - checkboxes = multiselect.
					$widget = ! empty( $field_data_raw['widget'] ) ? $field_data_raw['widget'] : '';
					if ( $max_num_items > 1 || 0 === $max_num_items || 'checkboxes' === $widget ) {
						$is_multiselect = true;
					}
				}

				$geodir_field_type = $this->map_field_type( $field_type, $field_config_settings, $field_data_raw, $is_multiselect );
				if ( ! $geodir_field_type ) {
					continue;
				}

				// Check if this field maps to a predefined GeoDirectory field.
				$predefined_key = $this->map_to_predefined_field( $field_name, $field_type );
				// Remove 'field_' prefix from field name if not mapped to predefined field.
				$final_field_key = $predefined_key ? $predefined_key : $this->remove_field_prefix( $field_name );

				// If mapped to predefined field, use appropriate type and icon.
				if ( $predefined_key ) {
					// Override field type for predefined fields if needed.
					if ( 'address' === $predefined_key ) {
						$geodir_field_type = 'address';
					} elseif ( 'phone' === $predefined_key ) {
						$geodir_field_type = 'phone';
					} elseif ( 'email' === $predefined_key ) {
						$geodir_field_type = 'email';
					} elseif ( 'website' === $predefined_key ) {
						$geodir_field_type = 'url';
					} elseif ( 'business_hours' === $predefined_key ) {
						$geodir_field_type = 'business_hours';
					} elseif ( 'video' === $predefined_key ) {
						$geodir_field_type = 'textarea';
					} elseif ( in_array( $predefined_key, array( 'facebook', 'twitter', 'instagram', 'youtube', 'linkedin', 'pinterest', 'whatsapp' ), true ) ) {
						$geodir_field_type = 'url';
					}
				}

				$field = array(
					'type'          => $geodir_field_type,
					'field_key'     => $final_field_key,
					'label'         => $field_label,
					'description'   => ! empty( $field_data_raw['description'] ) ? $field_data_raw['description'] : '',
					'icon'          => $this->get_icon_for_field( $final_field_key ),
					'required'      => ! empty( $field_data_raw['required'] ) ? 1 : 0,
					'is_predefined' => ! empty( $predefined_key ),
					'original_key'  => $field_name, // Store original for data mapping.
				);

				// Handle choice fields with options.
				if ( in_array( $field_type, array( 'choice' ), true ) && ! empty( $field_config_settings['options']['options'] ) ) {
					$options = array();
					foreach ( $field_config_settings['options']['options'] as $key => $value ) {
						// If key and value are the same, just use the value to avoid redundancy.
						// Otherwise, use key:value format for GeoDirectory.
						if ( $key === $value ) {
							$options[] = $value;
						} else {
							$options[] = $key . ':' . $value;
						}
					}
					// GeoDirectory uses newline-separated values for options.
					$field['options'] = implode( "\n", $options );
					// Set multiselect type if detected.
					if ( $is_multiselect ) {
						$field['type'] = 'multiselect';
						// Store widget type for multi_display_type mapping.
						$widget          = ! empty( $field_data_raw['widget'] ) ? $field_data_raw['widget'] : '';
						$field['widget'] = $widget; // Store for later use in prepare_single_field.
					}
				}

				// Handle wp_image - check if multiple images allowed.
				if ( 'wp_image' === $field_type ) {
					$max_num_items = ! empty( $field_data_raw['max_num_items'] ) ? (int) $field_data_raw['max_num_items'] : 1;
					if ( $max_num_items > 1 || 0 === $max_num_items ) {
						// Multiple images - use images type if available, otherwise file.
						$geodir_field_type = 'file'; // GeoDirectory uses 'file' for multiple images too.
					}
				}

				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Remove 'field_' prefix from field name.
	 *
	 * @param string $field_name Field name.
	 * @return string Field name without prefix.
	 */
	private function remove_field_prefix( $field_name ) {
		return preg_replace( '/^field_/', '', $field_name );
	}

	/**
	 * Check if a field name maps to a GeoDirectory address field.
	 *
	 * @param string $field_name Directories Pro field name.
	 * @return string|false GeoDirectory address field key (street, city, region, country, zip) or false.
	 */
	private function map_to_address_field( $field_name ) {
		// Remove 'field_' prefix for matching.
		$field_name_clean = $this->remove_field_prefix( $field_name );
		$field_name_lower = strtolower( $field_name_clean );

		// Map common address field names to GeoDirectory address fields.
		$address_mapping = array(
			// Street/Address mappings.
			'address'              => 'street',
			'street'               => 'street',
			'street_address'       => 'street',
			'field_address'        => 'street',
			'field_street'         => 'street',
			'field_street_address' => 'street',
			// City mappings.
			'city'                 => 'city',
			'field_city'           => 'city',
			// Region/State/Province mappings.
			'region'               => 'region',
			'state'                => 'region',
			'province'             => 'region',
			'field_region'         => 'region',
			'field_state'          => 'region',
			'field_province'       => 'region',
			// Country mappings.
			'country'              => 'country',
			'field_country'        => 'country',
			// Zip/Postal mappings.
			'zip'                  => 'zip',
			'postal'               => 'zip',
			'postal_code'          => 'zip',
			'zip_code'             => 'zip',
			'field_zip'            => 'zip',
			'field_postal'         => 'zip',
			'field_postal_code'    => 'zip',
			'field_zip_code'       => 'zip',
		);

		// Check exact match first.
		if ( isset( $address_mapping[ $field_name ] ) ) {
			return $address_mapping[ $field_name ];
		}

		// Check without prefix.
		if ( isset( $address_mapping[ $field_name_clean ] ) ) {
			return $address_mapping[ $field_name_clean ];
		}

		// Check case-insensitive match.
		if ( isset( $address_mapping[ $field_name_lower ] ) ) {
			return $address_mapping[ $field_name_lower ];
		}

		// Check partial matches (e.g., "field_city_name" contains "city").
		foreach ( $address_mapping as $key => $gd_field ) {
			if ( false !== strpos( $field_name_lower, $key ) || false !== strpos( $field_name_clean, $key ) ) {
				return $gd_field;
			}
		}

		return false;
	}

	/**
	 * Map Directories Pro field name to GeoDirectory predefined field key.
	 * Uses Directories Pro's predefined field names from directory_listing_fields.php.
	 *
	 * @param string $field_name Directories Pro field name.
	 * @param string $field_type Directories Pro field type.
	 * @return string|false GeoDirectory field key or false if no mapping.
	 */
	private function map_to_predefined_field( $field_name, $field_type = '' ) {
		// Directories Pro predefined field names -> GeoDirectory field keys.
		// Based on directory_listing_fields.php.
		$drts_predefined_fields = array(
			'field_phone'           => 'phone',
			'field_fax'             => 'fax',
			'field_email'           => 'email',
			'field_website'         => 'website',
			'field_opening_hours'   => 'business_hours',
			'field_business_hours'  => 'business_hours',
			'field_operating_hours' => 'business_hours',
			'field_videos'          => 'video',
		);

		// Check exact match with Directories Pro predefined field names.
		if ( isset( $drts_predefined_fields[ $field_name ] ) ) {
			return $drts_predefined_fields[ $field_name ];
		}

		// Also check without 'field_' prefix for flexibility.
		$field_name_without_prefix = preg_replace( '/^field_/', '', $field_name );
		if ( isset( $drts_predefined_fields[ 'field_' . $field_name_without_prefix ] ) ) {
			return $drts_predefined_fields[ 'field_' . $field_name_without_prefix ];
		}

		// Fallback: Check by field type for standard types.
		if ( 'email' === $field_type ) {
			return 'email';
		}

		if ( 'phone' === $field_type ) {
			return 'phone';
		}

		if ( 'url' === $field_type ) {
			return 'website';
		}

		if ( 'directory_opening_hours' === $field_type ) {
			return 'business_hours';
		}

		if ( 'video' === $field_type ) {
			return 'video';
		}

		return false;
	}

	/**
	 * Get individual social media fields from social_accounts field type.
	 * Only creates fields that don't already exist as predefined GeoDirectory fields.
	 *
	 * @param string $base_label Base label for the social accounts field.
	 * @param string $post_type Post type to check for existing fields.
	 * @return array Array of social media field definitions.
	 */
	private function get_social_media_fields( $base_label = '', $post_type = '' ) {
		$social_platforms = array(
			'facebook'  => array(
				'label' => __( 'Facebook', 'geodir-converter' ),
				'icon'  => 'fab fa-facebook',
			),
			'twitter'   => array(
				'label' => __( 'Twitter', 'geodir-converter' ),
				'icon'  => 'fab fa-twitter',
			),
			'instagram' => array(
				'label' => __( 'Instagram', 'geodir-converter' ),
				'icon'  => 'fab fa-instagram',
			),
			'youtube'   => array(
				'label' => __( 'YouTube', 'geodir-converter' ),
				'icon'  => 'fab fa-youtube',
			),
			'linkedin'  => array(
				'label' => __( 'LinkedIn', 'geodir-converter' ),
				'icon'  => 'fab fa-linkedin',
			),
			'pinterest' => array(
				'label' => __( 'Pinterest', 'geodir-converter' ),
				'icon'  => 'fab fa-pinterest',
			),
			'whatsapp'  => array(
				'label' => __( 'WhatsApp', 'geodir-converter' ),
				'icon'  => 'fab fa-whatsapp',
			),
		);

		$fields = array();
		foreach ( $social_platforms as $platform_key => $platform_data ) {
			// Check if field already exists as predefined GeoDirectory field.
			if ( ! empty( $post_type ) && $this->field_exists( $platform_key, $post_type ) ) {
				// Field already exists, skip creating it.
				continue;
			}

			$fields[] = array(
				'type'          => 'url',
				'field_key'     => $platform_key,
				'label'         => $platform_data['label'],
				'description'   => sprintf(
					/* translators: %s: social media platform name */
					__( 'The %s page of the listing.', 'geodir-converter' ),
					$platform_data['label']
				),
				'icon'          => $platform_data['icon'],
				'required'      => 0,
				'is_predefined' => true,
			);
		}

		return $fields;
	}

	/**
	 * Map Directories Pro field type to GeoDirectory field type.
	 *
	 * @param string $drts_type Directories Pro field type.
	 * @param array  $field_config_settings Field configuration settings.
	 * @param array  $field_data_raw Raw field data.
	 * @param bool   $is_multiselect Whether this is a multiselect field.
	 * @return string|false GeoDirectory field type or false if not supported.
	 */
	private function map_field_type( $drts_type, $field_config_settings = array(), $field_data_raw = array(), $is_multiselect = false ) {
		// Handle choice fields - check if it's multi-select.
		if ( 'choice' === $drts_type ) {
			// If already determined as multiselect, return it.
			if ( $is_multiselect ) {
				return 'multiselect';
			}
			$max_num_items = ! empty( $field_data_raw['max_num_items'] ) ? (int) $field_data_raw['max_num_items'] : 1;
			// Check widget settings for multiple selection (widget is in field_data_raw, not field_config_settings).
			$widget = ! empty( $field_data_raw['widget'] ) ? $field_data_raw['widget'] : '';
			if ( 'checkboxes' === $widget ) {
				return 'multiselect';
			}
			if ( $max_num_items > 1 || 0 === $max_num_items ) {
				return 'multiselect';
			}
			return 'select';
		}

		$type_map = array(
			'string'                  => 'text',
			'text'                    => 'textarea',
			'email'                   => 'email',
			'url'                     => 'url',
			'phone'                   => 'phone',
			'number'                  => 'number',
			'date'                    => 'datepicker',
			'time'                    => 'time',
			'boolean'                 => 'checkbox',
			'wp_image'                => 'file',
			'video'                   => 'textarea',
			'social_accounts'         => false, // Handled separately - split into individual fields.
			'range'                   => 'text', // Range fields store "min-max" as string.
			'color'                   => 'text',
			'location_address'        => 'address',
			'directory_opening_hours' => false, // Handled separately - maps to business_hours.
		);

		return isset( $type_map[ $drts_type ] ) ? $type_map[ $drts_type ] : false;
	}

	/**
	 * Get database data type for field type.
	 *
	 * @param string $field_type Field type.
	 * @return string Data type.
	 */
	private function map_data_type( $field_type ) {
		$type_map = array(
			'text'           => 'VARCHAR',
			'email'          => 'VARCHAR',
			'url'            => 'TEXT',
			'phone'          => 'VARCHAR',
			'textarea'       => 'TEXT',
			'checkbox'       => 'TINYINT',
			'datepicker'     => 'DATE',
			'time'           => 'VARCHAR',
			'radio'          => 'VARCHAR',
			'select'         => 'VARCHAR',
			'multiselect'    => 'TEXT', // Multiselect can have long comma-separated values.
			'file'           => 'TEXT',
			'images'         => 'TEXT',
			'address'        => 'VARCHAR',
			'number'         => 'INT',
			'business_hours' => 'TEXT',
			'html'           => 'TEXT',
		);

		return isset( $type_map[ $field_type ] ) ? $type_map[ $field_type ] : 'VARCHAR';
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
	 * Import fields from Directories Pro to GeoDirectory.
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
			$this->log(
				sprintf(
					/* translators: %s: post type name */
					__( 'No custom fields found for post type: %s', 'geodir-converter' ),
					$post_type
				),
				'warning'
			);
			return $this->next_task( $task );
		}

		$imported = 0;
		$updated  = 0;
		$skipped  = 0;
		$failed   = 0;

		foreach ( $fields as $field ) {
			$gd_field = $this->prepare_single_field( $field, $post_type, $package_ids );

			if ( $this->should_skip_field( $gd_field['htmlvar_name'] ) ) {
				++$skipped;
				$this->log(
					sprintf(
					/* translators: %s: field label */
						__( 'Skipped custom field: %s', 'geodir-converter' ),
						$field['label']
					),
					'warning'
				);
				continue;
			}

			$field_exists = ! empty( $gd_field['field_id'] );

			if ( $this->is_test_mode() ) {
				$field_exists ? $updated++ : $imported++;
				continue;
			}

			$result = geodir_custom_field_save( $gd_field );

			if ( $result && ! is_wp_error( $result ) ) {
				// If extra_fields is set, ensure it's updated in database.
				// geodir_custom_field_save() might not always update extra_fields for existing fields.
				if ( ! empty( $gd_field['extra_fields'] ) ) {
					global $wpdb;

					// Get the id - either from existing field or from result.
					// Note: field_exists() returns the 'id' column value, stored as 'field_id' in $gd_field array.
					$id_to_update = 0;
					if ( ! empty( $gd_field['field_id'] ) ) {
						$id_to_update = $gd_field['field_id']; // This is actually the 'id' value from the database.
					} elseif ( ! empty( $result ) ) {
						// Field was just created, get the id from result.
						$id_to_update = is_numeric( $result ) ? $result : ( isset( $result['id'] ) ? $result['id'] : ( isset( $result['field_id'] ) ? $result['field_id'] : 0 ) );
					}

					if ( $id_to_update ) {
						// Use geodir_db_cpt_table to get the correct table name.
						$table_name = $wpdb->prefix . 'geodir_custom_fields';
						$wpdb->update(
							$table_name,
							array( 'extra_fields' => $gd_field['extra_fields'] ),
							array( 'id' => $id_to_update ),
							array( '%s' ),
							array( '%d' )
						);
					}
				}

				if ( $field_exists ) {
					++$updated;
				} else {
					++$imported;
				}
			} else {
				++$failed;
				$error_msg = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown error', 'geodir-converter' );
				$this->log(
					sprintf(
						/* translators: %1$s: field label, %2$s: error message */
						__( 'Failed to import custom field: %1$s - %2$s', 'geodir-converter' ),
						$field['label'],
						$error_msg
					),
					'error'
				);
			}
		}

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_skipped_imports( $skipped );
		$this->increase_failed_imports( $failed );

		$this->log(
			sprintf(
				/* translators: %1$d: number of imported fields, %2$d: number of updated fields, %3$d: number of skipped fields, %4$d: number of failed fields */
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
	 * @param array  $field Directories Pro field data.
	 * @param string $post_type Post type.
	 * @param array  $package_ids Package IDs.
	 * @return array GeoDirectory field data.
	 */
	private function prepare_single_field( $field, $post_type, $package_ids = array() ) {
		$field_type = isset( $field['type'] ) ? $field['type'] : 'text';
		$field_id   = $this->field_exists( $field['field_key'], $post_type );

		// Determine data type - use TEXT for multiselect to handle long comma-separated values.
		$data_type = $this->map_data_type( $field_type );
		if ( 'multiselect' === $field_type ) {
			$data_type = 'TEXT';
		}

		$gd_field = array(
			'post_type'         => $post_type,
			'data_type'         => $data_type,
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

		// Handle multiselect display type based on Directories Pro widget type.
		$extra_fields = '';
		if ( 'multiselect' === $field_type ) {
			// Map Directories Pro widget to GeoDirectory multi_display_type.
			// 'checkboxes' in Directories Pro = 'checkbox' in GeoDirectory.
			// 'select' in Directories Pro = 'select' in GeoDirectory.
			$widget             = ! empty( $field['widget'] ) ? $field['widget'] : '';
			$multi_display_type = 'select'; // Default.
			if ( 'checkboxes' === $widget ) {
				$multi_display_type = 'checkbox';
			} elseif ( 'select' === $widget ) {
				$multi_display_type = 'select';
			}
			$extra_fields_array = array( 'multi_display_type' => $multi_display_type );
			$extra_fields       = maybe_serialize( $extra_fields_array );
		}
		// Always set extra_fields, even if empty, to ensure it's saved/updated.
		// For existing fields, we need to explicitly set it to update the database.
		$gd_field['extra_fields'] = $extra_fields;

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

		$bundle_name = $this->get_import_setting( 'drts_bundle', '' );
		if ( empty( $bundle_name ) ) {
			$this->log( esc_html__( 'No bundle selected.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$bundle_info = $this->get_bundle_info( $bundle_name );
		if ( ! $bundle_info ) {
			$this->log( esc_html__( 'Bundle not found.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$post_type      = $bundle_info['post_type'];
		$offset         = isset( $task['offset'] ) ? (int) $task['offset'] : 0;
		$total_listings = isset( $task['total_listings'] ) ? (int) $task['total_listings'] : 0;
		$batch_size     = (int) $this->get_batch_size();

		if ( ! isset( $task['total_listings'] ) ) {
			$total_listings         = $this->count_listings( $post_type );
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
					array( $post_type ),
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
	 * Convert a single Directories Pro listing to GeoDirectory format.
	 *
	 * @since 2.2.0
	 * @param  object $listing The post object to convert.
	 * @return int Import status.
	 */
	private function import_single_listing( $listing ) {
		$post       = get_post( $listing->ID );
		$post_type  = $this->get_import_post_type();
		$gd_post_id = ! $this->is_test_mode() ? $this->get_gd_listing_id( $post->ID, $this->importer_id . '_id', $post_type ) : false;
		$is_update  = ! empty( $gd_post_id );

		$bundle_name = $this->get_import_setting( 'drts_bundle', '' );
		$bundle_info = $this->get_bundle_info( $bundle_name );

		if ( ! $bundle_info ) {
			return self::IMPORT_STATUS_FAILED;
		}

		$default_location = $this->get_default_location();

		// Get categories.
		$category_taxonomy = $this->get_category_taxonomy( $bundle_name, $bundle_info );
		$categories        = array();
		if ( ! empty( $category_taxonomy ) ) {
			$categories = $this->get_listings_terms( $post->ID, $category_taxonomy );
		}

		// Get tags (use names for tags, not IDs).
		$tag_taxonomy = $this->get_tag_taxonomy( $bundle_name, $bundle_info );
		$tags         = array();
		if ( ! empty( $tag_taxonomy ) ) {
			$tags = $this->get_listings_terms( $post->ID, $tag_taxonomy, 'names' );
		}

		// Get field values from Directories Pro tables.
		$field_values = $this->get_field_values( $post->ID, $bundle_name );

		// Log if no field values found.
		if ( empty( $field_values ) && ! $this->is_test_mode() ) {
			$this->log( sprintf( 'No field values found for listing ID %d (bundle: %s)', $post->ID, $bundle_name ), 'warning' );
		}

		// Location data.
		$location = $default_location;

		$latitude  = isset( $field_values['location_latitude'] ) && ! empty( $field_values['location_latitude'] ) ? $field_values['location_latitude'] : '';
		$longitude = isset( $field_values['location_longitude'] ) && ! empty( $field_values['location_longitude'] ) ? $field_values['location_longitude'] : '';

		if ( $latitude && $longitude ) {
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
			'post_author'              => $post->post_author ? $post->post_author : $this->get_import_setting( 'wp_author_id', get_current_user_id() ),
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
				"{$post_type}_tags"    => $tags,
			),

			// GD fields.
			'default_category'         => ! empty( $categories ) ? $categories[0] : 0,
			'featured_image'           => $this->get_featured_image( $post->ID ),
			'submit_ip'                => '',
			'overall_rating'           => 0,
			'rating_count'             => 0,

			'street'                   => ! empty( $field_values['location_address'] ) ? $field_values['location_address'] : '',
			'street2'                  => '',
			'city'                     => isset( $location['city'] ) ? $location['city'] : '',
			'region'                   => isset( $location['region'] ) ? $location['region'] : '',
			'country'                  => isset( $location['country'] ) ? $location['country'] : '',
			'zip'                      => isset( $location['zip'] ) ? $location['zip'] : '',
			'latitude'                 => isset( $location['latitude'] ) ? $location['latitude'] : '',
			'longitude'                => isset( $location['longitude'] ) ? $location['longitude'] : '',
			'mapview'                  => '',
			'mapzoom'                  => '',

			// Directories Pro standard fields.
			$this->importer_id . '_id' => absint( $post->ID ),
		);

		// Map address fields to GeoDirectory built-in address fields.
		foreach ( $field_values as $field_name => $field_value ) {
			$address_field = $this->map_to_address_field( $field_name );
			if ( $address_field && ! empty( $field_value ) ) {
				// Override location defaults with actual field values.
				$listing_data[ $address_field ] = $field_value;
			}
		}

		// Map field values to GeoDirectory field keys.
		$field_mapping = $this->get_field_name_mapping( $bundle_name );

		// Add custom field values.
		foreach ( $field_values as $field_name => $field_value ) {
			// Skip location fields (handled separately) and taxonomy fields.
			// Skip 'location_address' - it's a special Directories Pro location field, not a custom field.
			if ( in_array( $field_name, array( 'location_latitude', 'location_longitude', 'location_address', 'directory_category', 'directory_tag' ), true ) ) {
				continue;
			}

			// Skip address fields - they are handled above.
			if ( $this->map_to_address_field( $field_name ) ) {
				continue;
			}

			// Skip social_accounts field - it should have been split into individual fields in get_field_values().
			if ( false !== strpos( $field_name, 'social_account' ) || 'social_accounts' === $field_name ) {
				continue;
			}

			// Map to predefined GeoDirectory field key if mapping exists.
			$gd_field_key = isset( $field_mapping[ $field_name ] ) ? $field_mapping[ $field_name ] : $field_name;

			// Skip null values.
			if ( null === $field_value ) {
				continue;
			}

			// Handle business_hours field conversion.
			// Note: Time fields are already converted in get_field_values() to JSON string format.
			// Only convert if it's an array (from other sources like directory_opening_hours field type).
			if ( 'business_hours' === $gd_field_key && is_array( $field_value ) ) {
				$field_value = $this->convert_opening_hours_to_business_hours( $field_value );
			}

			// Check if this is a multiselect field and format accordingly.
			$field_info = geodir_get_field_infoby( 'htmlvar_name', $gd_field_key, $post_type );
			if ( $field_info && ! empty( $field_info['field_type'] ) && 'multiselect' === $field_info['field_type'] ) {
				// For multiselect, ensure value is properly formatted.
				// If it's already a comma-separated string, keep it.
				// If it's an array, convert to comma-separated string.
				if ( is_array( $field_value ) ) {
					$field_value = implode( ',', array_filter( array_map( 'trim', $field_value ) ) );
				} elseif ( is_string( $field_value ) ) {
					// Ensure it's trimmed.
					$field_value = trim( $field_value );
				}
			}

			// Add to listing_data - GeoDirectory will handle saving to detail table or post meta.
			$listing_data[ $gd_field_key ] = $field_value;
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
	 * Get field name mapping (original Directories Pro field name -> GeoDirectory field key).
	 *
	 * @param string $bundle_name Bundle name.
	 * @return array Field name mapping.
	 */
	private function get_field_name_mapping( $bundle_name ) {
		global $wpdb;

		$cache_key = 'drts_field_mapping_' . $bundle_name;
		$mapping   = wp_cache_get( $cache_key, 'geodir_converter' );

		if ( false !== $mapping ) {
			return $mapping;
		}

		$mapping = array();

		$table_name   = $wpdb->prefix . 'drts_entity_field';
		$config_table = $wpdb->prefix . 'drts_entity_fieldconfig';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return $mapping;
		}

		// Validate table names to prevent SQL injection.
		// Table names are safe as they come from $wpdb->prefix, but we validate the pattern.
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table_name ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $config_table ) ) {
			return $mapping;
		}

		$table_name   = esc_sql( $table_name );
		$config_table = esc_sql( $config_table );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.*, fc.fieldconfig_type 
				FROM `{$table_name}` f 
				LEFT JOIN `{$config_table}` fc ON f.field_fieldconfig_name = fc.fieldconfig_name 
				WHERE f.field_bundle_name = %s",
				$bundle_name
			)
		);

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				// Use field_fieldconfig_name as the actual field name (this is what's stored in the data tables).
				$field_name = ! empty( $row->field_fieldconfig_name ) ? $row->field_fieldconfig_name : 'field_' . $row->field_id;
				$field_type = $row->fieldconfig_type;

				// Remove 'field_' prefix from key for mapping (since get_field_values() removes it too).
				$field_key = $this->remove_field_prefix( $field_name );

				if ( 'social_accounts' === $field_type ) {
					// Social accounts are handled separately in get_field_values().
					continue;
				}

				// Check if this is an opening hours field (by type or name).
				$opening_hours_field_names = array( 'field_opening_hours', 'field_business_hours', 'field_operating_hours' );
				$field_name_clean          = $this->remove_field_prefix( $field_name );
				$is_opening_hours_field    = 'directory_opening_hours' === $field_type
					|| in_array( $field_name, $opening_hours_field_names, true )
					|| in_array( $field_name_clean, array( 'opening_hours', 'business_hours', 'operating_hours' ), true );

				if ( $is_opening_hours_field ) {
					// Map opening hours to business_hours.
					$mapping[ $field_key ] = 'business_hours';
				} else {
					// Check if this field maps to a predefined GeoDirectory field.
					$predefined_key = $this->map_to_predefined_field( $field_name, $field_type );

					if ( $predefined_key ) {
						$mapping[ $field_key ] = $predefined_key;
					} else {
						// For fields that don't map to predefined fields, use the key without prefix.
						$mapping[ $field_key ] = $field_key;
					}
				}
			}
		}

		wp_cache_set( $cache_key, $mapping, 'geodir_converter', 3600 );

		return $mapping;
	}

	/**
	 * Convert Directories Pro opening hours format to GeoDirectory business_hours format.
	 * Returns JSON string format: ["Mo 09:00-17:00","Tu 09:00-17:00"],["UTC":"+00:00"]
	 *
	 * @param array $drts_hours Directories Pro opening hours data.
	 * @return string GeoDirectory business_hours JSON string format.
	 */
	private function convert_opening_hours_to_business_hours( $drts_hours ) {
		if ( ! is_array( $drts_hours ) || empty( $drts_hours ) ) {
			return '';
		}

		// Map day keys to GeoDirectory abbreviations.
		$day_abbr_map = array(
			'sun' => 'Su',
			'mon' => 'Mo',
			'tue' => 'Tu',
			'wed' => 'We',
			'thu' => 'Th',
			'fri' => 'Fr',
			'sat' => 'Sa',
		);

		$days_parts = array();

		foreach ( $drts_hours as $day => $day_data ) {
			if ( ! isset( $day_abbr_map[ $day ] ) ) {
				continue;
			}

			// Skip if closed or empty.
			if ( empty( $day_data ) || ( isset( $day_data['closed'] ) && $day_data['closed'] ) ) {
				continue;
			}

			// Extract hours.
			$hours = array();
			if ( isset( $day_data['hours'] ) && is_array( $day_data['hours'] ) ) {
				foreach ( $day_data['hours'] as $time_slot ) {
					if ( isset( $time_slot['open'] ) && isset( $time_slot['close'] ) ) {
						$hours[] = $time_slot['open'] . '-' . $time_slot['close'];
					}
				}
			}

			if ( ! empty( $hours ) ) {
				$day_abbr     = $day_abbr_map[ $day ];
				$days_parts[] = $day_abbr . ' ' . implode( ',', $hours );
			}
		}

		if ( empty( $days_parts ) ) {
			return '';
		}

		// Build GeoDirectory JSON string format.
		$offset  = get_option( 'gmt_offset', 0 );
		$result  = '["' . implode( '","', $days_parts ) . '"]';
		$result .= ',["UTC":"' . $offset . '"]';

		// Sanitize using GeoDirectory function if available.
		if ( function_exists( 'geodir_sanitize_business_hours' ) ) {
			$result = geodir_sanitize_business_hours( $result );
		}

		return $result;
	}

	/**
	 * Get field values from Directories Pro tables.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $bundle_name Bundle name.
	 * @return array Field values.
	 */
	private function get_field_values( $post_id, $bundle_name ) {
		global $wpdb;

		$field_values = array();

		// Dynamically discover all drts_entity_field_* tables.
		$table_pattern = $wpdb->prefix . 'drts_entity_field_%';
		$tables        = $wpdb->get_col(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				str_replace( '_', '\_', $table_pattern )
			)
		);

		foreach ( $tables as $table_name ) {
			// Validate table name to prevent SQL injection.
			// Table names come from SHOW TABLES, but we validate the pattern for safety.
			if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table_name ) ) {
				continue;
			}

			$table_name_escaped = esc_sql( $table_name );

			// Get all columns for this table.
			$columns = $wpdb->get_col( "DESCRIBE `{$table_name_escaped}`" );

			if ( empty( $columns ) ) {
				continue;
			}

			// Handle time fields (opening hours) - they have start, end, day columns.
			if ( false !== strpos( $table_name, '_field_time' ) && in_array( 'start', $columns, true ) && in_array( 'end', $columns, true ) && in_array( 'day', $columns, true ) ) {
				// This is a time field table (opening hours).
				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT field_name, start, end, day, all_day 
						FROM `{$table_name_escaped}` 
						WHERE entity_type = 'post' 
						AND entity_id = %d 
						AND bundle_name = %s 
						ORDER BY field_name, day ASC, weight ASC",
						absint( $post_id ),
						$bundle_name
					)
				);

				if ( ! empty( $results ) ) {
					// Group time slots by field name and day.
					$time_fields = array();
					foreach ( $results as $row ) {
						$field_key = $this->remove_field_prefix( $row->field_name );
						if ( ! isset( $time_fields[ $field_key ] ) ) {
							$time_fields[ $field_key ] = array();
						}

						// Convert day number (1-7, Monday-Sunday) to GeoDirectory format (sun-sat).
						// Directories Pro: 1=Monday, 2=Tuesday, ..., 7=Sunday.
						// GeoDirectory: sun, mon, tue, wed, thu, fri, sat.
						$day_map = array(
							1 => 'mon',
							2 => 'tue',
							3 => 'wed',
							4 => 'thu',
							5 => 'fri',
							6 => 'sat',
							7 => 'sun',
						);
						$day     = isset( $day_map[ (int) $row->day ] ) ? $day_map[ (int) $row->day ] : '';

						if ( empty( $day ) ) {
							continue;
						}

						if ( ! isset( $time_fields[ $field_key ][ $day ] ) ) {
							$time_fields[ $field_key ][ $day ] = array(
								'active' => '1',
								'hours'  => array(),
							);
						}

						// Skip if all_day is set (handled separately if needed).
						// For now, we'll process regular time slots.
						if ( ! empty( $row->all_day ) ) {
							// Handle all_day if needed in the future.
							continue;
						}

						// Convert seconds since midnight to HH:MM format.
						$start_seconds = (int) $row->start;
						$end_seconds   = (int) $row->end;

						// Skip if start or end is 0 or invalid.
						if ( $start_seconds <= 0 || $end_seconds <= 0 ) {
							continue;
						}

						$start_hours   = floor( $start_seconds / 3600 );
						$start_minutes = floor( ( $start_seconds % 3600 ) / 60 );
						$end_hours     = floor( $end_seconds / 3600 );
						$end_minutes   = floor( ( $end_seconds % 3600 ) / 60 );

						$open_time  = sprintf( '%02d:%02d', $start_hours, $start_minutes );
						$close_time = sprintf( '%02d:%02d', $end_hours, $end_minutes );

						$time_fields[ $field_key ][ $day ]['hours'][] = array(
							'open'  => $open_time,
							'close' => $close_time,
						);
					}

					// Convert to GeoDirectory JSON string format and add to field_values.
					foreach ( $time_fields as $field_key => $days_data ) {
						// Map GeoDirectory day keys to abbreviations.
						$day_abbr_map = array(
							'mon' => 'Mo',
							'tue' => 'Tu',
							'wed' => 'We',
							'thu' => 'Th',
							'fri' => 'Fr',
							'sat' => 'Sa',
							'sun' => 'Su',
						);

						$days_parts = array();

						foreach ( $days_data as $day => $day_data ) {
							if ( ! isset( $day_abbr_map[ $day ] ) ) {
								continue;
							}

							// Check if hours array exists and has items.
							$has_hours = ! empty( $day_data['hours'] ) && is_array( $day_data['hours'] ) && count( $day_data['hours'] ) > 0;

							if ( ! $has_hours ) {
								continue;
							}

							// Build time ranges for this day.
							$times = array();
							foreach ( $day_data['hours'] as $time_slot ) {
								if ( isset( $time_slot['open'] ) && isset( $time_slot['close'] ) ) {
									$times[] = $time_slot['open'] . '-' . $time_slot['close'];
								}
							}

							if ( ! empty( $times ) ) {
								$day_abbr     = $day_abbr_map[ $day ];
								$days_parts[] = $day_abbr . ' ' . implode( ',', $times );
							}
						}

						// Build GeoDirectory JSON string format.
						if ( ! empty( $days_parts ) ) {
							$offset  = get_option( 'gmt_offset', 0 );
							$result  = '["' . implode( '","', $days_parts ) . '"]';
							$result .= ',["UTC":"' . $offset . '"]';

							// Sanitize using GeoDirectory function if available.
							if ( function_exists( 'geodir_sanitize_business_hours' ) ) {
								$result = geodir_sanitize_business_hours( $result );
							}

							$field_values[ $field_key ] = $result;
						}
					}
				}
				continue;
			}

			// Build SELECT query based on available columns.
			// Column names are validated from DESCRIBE, so they're safe.
			if ( in_array( 'min', $columns, true ) && in_array( 'max', $columns, true ) ) {
				// Range fields have min and max columns.
				$select = 'field_name, min, max';
			} elseif ( in_array( 'value', $columns, true ) ) {
				// Standard fields with 'value' column.
				$select = 'field_name, value';
			} elseif ( in_array( 'id', $columns, true ) ) {
				// Video/file/image fields use 'id' column.
				$select = 'field_name, id as value';
			} else {
				// Skip if no recognizable value column.
				continue;
			}

			// Note: $select is safe as it's built from validated column names from DESCRIBE.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$select} FROM `{$table_name_escaped}` 
					WHERE entity_type = 'post' 
					AND entity_id = %d 
					AND bundle_name = %s 
					ORDER BY field_name, weight ASC",
					absint( $post_id ),
					$bundle_name
				)
			);

			if ( empty( $results ) ) {
				continue;
			}

			foreach ( $results as $row ) {
				$field_name = $row->field_name;

				// Handle range fields (min/max).
				if ( isset( $row->min ) && isset( $row->max ) ) {
					// Combine min and max as "min-max" format.
					$value = $row->min . '-' . $row->max;
				} elseif ( isset( $row->value ) ) {
					$value = $row->value;

					// Handle date fields - convert timestamp to Y-m-d format.
					// Check if this is a date field table by checking table name.
					if ( false !== strpos( $table_name, '_field_date' ) && is_numeric( $value ) && $value > 0 ) {
						$value = gmdate( 'Y-m-d', (int) $value );
					}
				} else {
					$value = '';
				}

				// Remove 'field_' prefix from field name.
				$field_key = $this->remove_field_prefix( $field_name );

				if ( ! isset( $field_values[ $field_key ] ) ) {
					$field_values[ $field_key ] = $value;
				} elseif ( is_array( $field_values[ $field_key ] ) ) {
					$field_values[ $field_key ][] = $value;
				} else {
					$field_values[ $field_key ] = array( $field_values[ $field_key ], $value );
				}
			}
		}

		// Convert arrays to comma-separated strings for multi-value fields.
		foreach ( $field_values as $key => $value ) {
			if ( is_array( $value ) ) {
				$field_values[ $key ] = implode( ',', array_filter( $value ) );
			}
		}

		// Process social_accounts field - split into individual social media fields.
		$social_accounts_key = null;
		foreach ( $field_values as $key => $value ) {
			if ( false !== strpos( $key, 'social_account' ) || 'social_accounts' === $key ) {
				$social_accounts_key = $key;
				break;
			}
		}

		if ( $social_accounts_key && ! empty( $field_values[ $social_accounts_key ] ) ) {
			$social_data = $field_values[ $social_accounts_key ];

			// Try to decode JSON first.
			$decoded = json_decode( $social_data, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				$social_data = $decoded;
			} else {
				// Try unserialize.
				$unserialized = maybe_unserialize( $social_data );
				if ( is_array( $unserialized ) ) {
					$social_data = $unserialized;
				}
			}

			// If we have an array, map to individual fields.
			if ( is_array( $social_data ) ) {
				$social_platforms = array( 'facebook', 'twitter', 'instagram', 'youtube', 'linkedin', 'pinterest', 'whatsapp' );

				foreach ( $social_platforms as $platform ) {
					if ( isset( $social_data[ $platform ] ) && ! empty( $social_data[ $platform ] ) ) {
						$field_values[ $platform ] = $social_data[ $platform ];
					}
				}
			} elseif ( is_string( $social_data ) && ! empty( $social_data ) ) {
				// If it's a single URL string, try to detect platform and assign.
				// This handles cases where social_accounts is stored as a single URL.
				if ( false !== strpos( $social_data, 'facebook.com' ) ) {
					$field_values['facebook'] = $social_data;
				} elseif ( false !== strpos( $social_data, 'twitter.com' ) || false !== strpos( $social_data, 'x.com' ) ) {
					$field_values['twitter'] = $social_data;
				} elseif ( false !== strpos( $social_data, 'instagram.com' ) ) {
					$field_values['instagram'] = $social_data;
				} elseif ( false !== strpos( $social_data, 'youtube.com' ) ) {
					$field_values['youtube'] = $social_data;
				} elseif ( false !== strpos( $social_data, 'linkedin.com' ) ) {
					$field_values['linkedin'] = $social_data;
				} elseif ( false !== strpos( $social_data, 'pinterest.com' ) ) {
					$field_values['pinterest'] = $social_data;
				} elseif ( false !== strpos( $social_data, 'whatsapp' ) ) {
					$field_values['whatsapp'] = $social_data;
				}
			}

			// Remove the original social_accounts field.
			unset( $field_values[ $social_accounts_key ] );
		}

		// Remove taxonomy fields (they're handled separately).
		unset( $field_values['directory_category'], $field_values['directory_tag'] );

		return $field_values;
	}

	/**
	 * Import comments from Directories Pro listing to GeoDirectory listing.
	 *
	 * @since 2.2.0
	 * @param int $drts_listing_id Directories Pro listing ID.
	 * @param int $gd_post_id GeoDirectory post ID.
	 * @return void
	 */
	private function import_comments( $drts_listing_id, $gd_post_id ) {
		global $wpdb;

		if ( $this->is_test_mode() ) {
			return;
		}

		// Get all comments for the Directories Pro listing.
		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_post_ID, comment_author, comment_author_email, 
				        comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
				        comment_content, comment_karma, comment_approved, comment_agent,
				        comment_type, comment_parent, user_id
				FROM {$wpdb->comments}
				WHERE comment_post_ID = %d",
				$drts_listing_id
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
			if ( 'review' === $comment->comment_type || false !== strpos( $comment->comment_type, 'review' ) ) {
				// Check if rating exists in comment meta.
				$rating = get_comment_meta( $comment->comment_ID, 'rating', true );
				if ( empty( $rating ) ) {
					$rating = get_comment_meta( $comment->comment_ID, '_drts_voting_rating', true );
				}

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
	 * Check if field should be skipped.
	 *
	 * @param string $htmlvar_name Field HTML variable name.
	 * @return bool True if should skip, false otherwise.
	 */
	protected function should_skip_field( $htmlvar_name ) {
		// Don't skip predefined GeoDirectory fields - they should be created/updated.
		$predefined_fields = array(
			'email',
			'phone',
			'fax',
			'website',
			'facebook',
			'twitter',
			'instagram',
			'youtube',
			'linkedin',
			'pinterest',
			'whatsapp',
			'featured',
			'claimed',
			'business_hours',
			'video',
		);

		if ( in_array( $htmlvar_name, $predefined_fields, true ) ) {
			return false;
		}

		// Use parent class logic for other fields.
		return parent::should_skip_field( $htmlvar_name );
	}

	/**
	 * Get listings terms (categories/tags).
	 *
	 * @param int    $post_id The post ID.
	 * @param string $taxonomy The taxonomy to get terms from.
	 * @param string $return_type Return 'ids' or 'names'.
	 * @return array Array of term IDs or names.
	 */
	private function get_listings_terms( $post_id, $taxonomy, $return_type = 'ids' ) {
		global $wpdb;

		$terms = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, tm.meta_value as gd_equivalent
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
				LEFT JOIN {$wpdb->termmeta} tm ON t.term_id = tm.term_id AND tm.meta_key = 'gd_equivalent'
				WHERE tr.object_id = %d AND tt.taxonomy = %s",
				$post_id,
				$taxonomy
			)
		);

		$term_data = array();

		foreach ( $terms as $term ) {
			$gd_term_id = (int) $term->gd_equivalent;

			if ( $gd_term_id ) {
				$gd_term = $wpdb->get_row( $wpdb->prepare( "SELECT name, term_id FROM {$wpdb->terms} WHERE term_id = %d", $gd_term_id ) );

				if ( $gd_term ) {
					$term_data[] = ( 'names' === $return_type ) ? $gd_term->name : $gd_term->term_id;
				} else {
					// Fallback to original term if GD equivalent term not found.
					$term_data[] = ( 'names' === $return_type ) ? $term->name : $term->term_id;
				}
			} else {
				// No GD equivalent, use original term.
				$term_data[] = ( 'names' === $return_type ) ? $term->name : $term->term_id;
			}
		}

		return $term_data;
	}

	/**
	 * Count the number of listings.
	 *
	 * @param string $post_type Post type.
	 * @return int The number of listings.
	 */
	private function count_listings( $post_type ) {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} 
				WHERE post_type = %s 
				AND post_status IN (" . implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) ) . ')',
				array_merge( array( $post_type ), $this->post_statuses )
			)
		);

		return $count;
	}

	/**
	 * Display bundle selection dropdown.
	 *
	 * @param array $bundles Available bundles.
	 */
	private function display_bundle_select( $bundles ) {
		$drts_bundle = $this->get_import_setting( 'drts_bundle', '' );

		aui()->select(
			array(
				'id'          => 'drts_bundle',
				'name'        => 'drts_bundle',
				'label'       => esc_html__( 'Directories Pro Bundle', 'geodir-converter' ),
				'label_type'  => 'top',
				'label_class' => 'font-weight-bold fw-bold',
				'value'       => $drts_bundle,
				'options'     => array_merge(
					array( '' => esc_html__( '-- Select Bundle --', 'geodir-converter' ) ),
					array_combine(
						array_keys( $bundles ),
						array_map(
							function ( $bundle ) {
								return $bundle['label'] . ' (' . $bundle['name'] . ') - ' . $bundle['count'] . ' listings';
							},
							$bundles
						)
					)
				),
				'help_text'   => esc_html__( 'Select the Directories Pro bundle to import from.', 'geodir-converter' ),
			),
			true
		);
	}

	/**
	 * Get available Directories Pro bundles.
	 *
	 * @return array
	 */
	private function get_available_bundles() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'drts_entity_bundle';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return array();
		}

		// Validate table name to prevent SQL injection.
		// Table name comes from $wpdb->prefix, but we validate the pattern for safety.
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table_name ) ) {
			return array();
		}

		$table_name_escaped = esc_sql( $table_name );

		$results = $wpdb->get_results(
			"SELECT bundle_name, bundle_type, bundle_info, bundle_entitytype_name 
			FROM `{$table_name_escaped}` 
			WHERE bundle_entitytype_name = 'post' 
			ORDER BY bundle_name ASC"
		);

		if ( empty( $results ) ) {
			return array();
		}

		$bundles = array();
		foreach ( $results as $row ) {
			$bundle_info = maybe_unserialize( $row->bundle_info );
			$label       = ! empty( $bundle_info['label'] ) ? $bundle_info['label'] : $row->bundle_name;
			$post_type   = $row->bundle_name;
			$count       = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) );

			$bundles[ $row->bundle_name ] = array(
				'name'      => $row->bundle_name,
				'type'      => $row->bundle_type,
				'label'     => $label,
				'count'     => $count,
				'info'      => $bundle_info,
				'post_type' => $post_type,
			);
		}

		return $bundles;
	}

	/**
	 * Get bundle information.
	 *
	 * @param string $bundle_name Bundle name.
	 * @return array|null
	 */
	private function get_bundle_info( $bundle_name ) {
		$bundles = $this->get_available_bundles();
		return ! empty( $bundles[ $bundle_name ] ) ? $bundles[ $bundle_name ] : null;
	}

	/**
	 * Get category taxonomy for bundle.
	 *
	 * @param string $bundle_name Bundle name.
	 * @param array  $bundle_info Bundle information.
	 * @return string
	 */
	private function get_category_taxonomy( $bundle_name, $bundle_info = array() ) {
		global $wpdb;

		if ( empty( $bundle_info ) ) {
			$bundle_info = $this->get_bundle_info( $bundle_name );
		}

		if ( empty( $bundle_info ) ) {
			return '';
		}

		$post_type = $bundle_info['post_type'];

		// Check bundle info for taxonomy configuration.
		if ( ! empty( $bundle_info['info']['taxonomies']['directory_cat_type'] ) ) {
			$taxonomy = $bundle_info['info']['taxonomies']['directory_cat_type'];
			if ( taxonomy_exists( $taxonomy ) ) {
				return $taxonomy;
			}
		}

		// Get all taxonomies registered for this post type.
		$taxonomies = get_object_taxonomies( $post_type );
		if ( ! empty( $taxonomies ) ) {
			// Look for category-like taxonomies.
			foreach ( $taxonomies as $tax ) {
				if ( strpos( $tax, 'cat' ) !== false || strpos( $tax, 'category' ) !== false ) {
					return $tax;
				}
			}
		}

		// Check database for taxonomies used with this post type.
		$taxonomy_from_db = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT DISTINCT tt.taxonomy 
				FROM {$wpdb->term_taxonomy} tt
				INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
				WHERE p.post_type = %s
				AND (tt.taxonomy LIKE %s OR tt.taxonomy LIKE %s)
				LIMIT 1",
				$post_type,
				'%cat%',
				'%category%'
			)
		);

		if ( ! empty( $taxonomy_from_db ) ) {
			return $taxonomy_from_db;
		}

		// Fallback to common patterns.
		$possible_taxonomies = array(
			$bundle_name . '_cat',
			$post_type . '_cat',
			str_replace( '_listing', '_cat', $bundle_name ),
			str_replace( '_listing', '_cat', $post_type ),
			'directory_cat',
			'directory_dir_cat',
		);

		foreach ( $possible_taxonomies as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				return $tax;
			}
		}

		return '';
	}

	/**
	 * Get tag taxonomy for bundle.
	 *
	 * @param string $bundle_name Bundle name.
	 * @param array  $bundle_info Bundle information.
	 * @return string
	 */
	private function get_tag_taxonomy( $bundle_name, $bundle_info = array() ) {
		global $wpdb;

		if ( empty( $bundle_info ) ) {
			$bundle_info = $this->get_bundle_info( $bundle_name );
		}

		if ( empty( $bundle_info ) ) {
			return '';
		}

		$post_type = $bundle_info['post_type'];

		// Check bundle info for taxonomy configuration.
		if ( ! empty( $bundle_info['info']['taxonomies']['directory_tag_type'] ) ) {
			$taxonomy = $bundle_info['info']['taxonomies']['directory_tag_type'];
			if ( taxonomy_exists( $taxonomy ) ) {
				return $taxonomy;
			}
		}

		// Get all taxonomies registered for this post type.
		$taxonomies = get_object_taxonomies( $post_type );
		if ( ! empty( $taxonomies ) ) {
			// Look for tag-like taxonomies.
			foreach ( $taxonomies as $tax ) {
				if ( strpos( $tax, 'tag' ) !== false ) {
					return $tax;
				}
			}
		}

		// Check database for taxonomies used with this post type.
		$taxonomy_from_db = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT DISTINCT tt.taxonomy 
				FROM {$wpdb->term_taxonomy} tt
				INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
				WHERE p.post_type = %s
				AND tt.taxonomy LIKE %s
				LIMIT 1",
				$post_type,
				'%tag%'
			)
		);

		if ( ! empty( $taxonomy_from_db ) ) {
			return $taxonomy_from_db;
		}

		// Fallback to common patterns.
		$possible_taxonomies = array(
			$bundle_name . '_tag',
			$post_type . '_tag',
			str_replace( '_listing', '_tag', $bundle_name ),
			str_replace( '_listing', '_tag', $post_type ),
			'directory_tag',
			'directory_dir_tag',
		);

		foreach ( $possible_taxonomies as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				return $tax;
			}
		}

		return '';
	}
}
