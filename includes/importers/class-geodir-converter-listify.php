<?php
/**
 * Ajax Class for Geodir Converter.
 *
 * @since      2.0.2
 * @package    GeoDir_Converter
 * @version    2.0.2
 */

namespace GeoDir_Converter\Importers;

use WP_Query;
use WP_Post;
use WP_Error;
use GeoDir_Media;
use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;
use GeoDir_Converter\GeoDir_Converter_Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Listify importer class.
 *
 * @since 2.0.2
 */
class GeoDir_Converter_Listify extends GeoDir_Converter_Importer {
	/**
	 * Post type identifier for job listings.
	 *
	 * @var string
	 */
	private const POST_TYPE_LISTING = 'job_listing';

	/**
	 * Taxonomy identifier for job listing categories.
	 *
	 * @var string
	 */
	private const TAX_LISTING_CATEGORY = 'job_listing_category';

	/**
	 * Taxonomy identifier for job listing types.
	 *
	 * @var string
	 */
	private const TAX_LISTING_TYPE = 'job_listing_type';

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
	protected $importer_id = 'listify';

	/**
	 * The import listing status ID.
	 *
	 * @var array
	 */
	protected $post_statuses = array( 'publish', 'expired', 'draft' );

	/**
	 * Initialize hooks.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	protected function init() {
	}

	/**
	 * Get importer title.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer title.
	 */
	public function get_title() {
		return __( 'Listify', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer description.
	 */
	public function get_description() {
		return __( 'Import listings from your Listify installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer icon URL.
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/listify.png';
	}

	/**
	 * Get importer task action.
	 *
	 * @since 2.0.2
	 *
	 * @return string The initial import action identifier.
	 */
	public function get_action() {
		return self::ACTION_IMPORT_CATEGORIES;
	}

	/**
	 * Render importer settings.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function render_settings() {
		?>
		<form class="geodir-converter-settings-form" method="post">
			<h6 class="fs-base"><?php esc_html_e( 'Listify Importer Settings', 'geodir-converter' ); ?></h6>
			
			<?php
			$this->display_post_type_select();
			$this->display_author_select( true );
			$this->display_test_mode_checkbox();
			$this->display_progress();
			$this->display_logs( $this->get_logs() );
			$this->display_error_alert();
			?>
						
			<?php $this->display_action_buttons(); ?>
		</form>
		<?php
	}

	/**
	 * Validate importer settings.
	 *
	 * @since 2.0.2
	 *
	 * @param array $settings The settings to validate.
	 * @param array $files    The files to validate.
	 * @return array|WP_Error Validated and sanitized settings, or WP_Error on failure.
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
	 * Normalize and set default values for a given field.
	 *
	 * @param array $field Field values to normalize.
	 * @return array Normalized field values.
	 */
	private function normalize_wpjm_field( $field ) {
		$defaults = array(
			'label'         => null,
			'placeholder'   => null,
			'description'   => null,
			'priority'      => 10,
			'value'         => null,
			'default'       => null,
			'classes'       => array(),
			'type'          => 'text',
			'show_in_admin' => true,
			'required'      => false,
		);

		return wp_parse_args( $field, $defaults );
	}

	/**
	 * Get the corresponding GD field key for a given field key.
	 *
	 * @since 2.0.2
	 * @param string $field_key The field key.
	 * @return string The mapped field key or the original field key if no match is found.
	 */
	private function get_gd_field_key( $field_key ) {
		$fields_map = array(
			'job_title'         => 'post_title',
			'job_description'   => 'post_content',
			'job_category'      => 'post_category',
			'job_location'      => 'address',
			'job_hours'         => 'business_hours',
			'gallery_images'    => 'post_images',
			'company_facebook'  => 'facebook',
			'company_twitter'   => 'twitter',
			'company_pinterest' => 'pinterest',
			'company_linkedin'  => 'linkedin',
			'company_github'    => 'github',
			'company_instagram' => 'instagram',
		);

		return isset( $fields_map[ $field_key ] ) ? $fields_map[ $field_key ] : $field_key;
	}

	/**
	 * Calculate the total number of items to be imported.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function set_import_total() {
		global $wpdb;

		$total_items = 0;

		// Count categories.
		$categories   = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_LISTING_CATEGORY ) );
		$total_items += is_wp_error( $categories ) ? 0 : $categories;

		// Count custom fields.
		$custom_fields = $this->get_fields();

		$total_items += (int) count( $custom_fields );

		// Count listings.
		$total_items += (int) $this->count_listings();

		$this->increase_imports_total( $total_items );
	}

	/**
	 * Import categories from Listify to GeoDirectory.
	 *
	 * @since 2.0.2
	 * @param array $task Import task.
	 *
	 * @return array Result of the import operation.
	 */
	public function task_import_categories( $task ) {
		global $wpdb;
		$this->log( __( 'Categories: Import started.', 'geodir-converter' ) );
		$this->set_import_total();

		if ( ! get_option( 'job_manager_enable_categories' ) || 0 === intval( wp_count_terms( self::TAX_LISTING_CATEGORY ) ) ) {
			$this->log( __( 'Categories: No items to import.', 'geodir-converter' ), 'warning' );
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
			$this->log( __( 'Categories: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		if ( $this->is_test_mode() ) {
			$this->increase_succeed_imports( count( $categories ) );
			$this->log(
				sprintf(
				/* translators: %1$d: number of imported terms, %2$d: number of failed imports */
					__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
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
				/* translators: %1$d: number of imported terms, %2$d: number of failed imports */
				__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$result['imported'],
				$result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Import fields from Listify to GeoDirectory.
	 *
	 * @since 2.0.2
	 * @param array $task Task details.
	 * @return array Result of the import operation.
	 */
	public function task_import_fields( array $task ) {
		global $plugin_prefix;

		$this->log( __( 'Importing standard fields...', 'geodir-converter' ) );

		// Get WPJM fields.
		$fields = $this->get_fields();

		if ( empty( $fields ) ) {
			$this->log( __( 'No custom fields found for import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$post_type   = $this->get_import_post_type();
		$package_ids = $this->get_package_ids( $post_type );
		$table       = $plugin_prefix . $post_type . '_detail';

		$imported = $updated = $skipped = $failed = 0;

		foreach ( $fields as $key => $field ) {
			$gd_field = $this->prepare_single_field( $key, $field, $post_type, $package_ids );

			// Skip fields that shouldn't be imported.
			if ( $this->should_skip_field( $gd_field['htmlvar_name'] ) ) {
				++$skipped;
				/* translators: %s: field name */
			$this->log( sprintf( __( 'Skipped custom field: %s', 'geodir-converter' ), $field['label'] ), 'warning' );
				continue;
			}

			$column_exists = geodir_column_exist( $table, $gd_field['htmlvar_name'] );

			if ( $this->is_test_mode() ) {
				$column_exists ? $updated++ : $imported++;
				continue;
			}

			if ( $gd_field && geodir_custom_field_save( $gd_field ) ) {
				$column_exists ? ++$updated : ++$imported;
			} else {
				++$failed;
				/* translators: %s: field name */
			$this->log( sprintf( __( 'Failed to import custom field: %s', 'geodir-converter' ), $field['label'] ), 'error' );
			}
		}

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_skipped_imports( $skipped );
		$this->increase_failed_imports( $failed );

		$this->log(
			sprintf(
				/* translators: %1$d: imported count, %2$d: updated count, %3$d: skipped count, %4$d: failed count */
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
	 * Get WPJM fields.
	 *
	 * @since 2.0.2
	 * @return array Array of WPJM fields.
	 */
	private function get_fields() {
		$allowed_application_method = get_option( 'job_manager_allowed_application_method', '' );
		switch ( $allowed_application_method ) {
			case 'email':
				$application_method_label       = __( 'Application email', 'geodir-converter' );
				$application_method_placeholder = __( 'you@example.com', 'geodir-converter' );
				break;
			case 'url':
				$application_method_label       = __( 'Application URL', 'geodir-converter' );
				$application_method_placeholder = __( 'https://', 'geodir-converter' );
				break;
			default:
				$application_method_label       = __( 'Application email/URL', 'geodir-converter' );
				$application_method_placeholder = __( 'Enter an email address or website URL', 'geodir-converter' );
				break;
		}

		$job_type = function_exists( 'job_manager_multi_job_type' ) && job_manager_multi_job_type() ? 'multiselect' : 'select';

		$fields = array(
			'job'     => array(
				'job_title'            => array(
					'label'       => __( 'Job Title', 'geodir-converter' ),
					'type'        => 'text',
					'required'    => true,
					'placeholder' => '',
					'priority'    => 1,
				),
				'job_location'         => array(
					'label'       => __( 'Location', 'geodir-converter' ),
					'description' => __( 'Leave this blank if the location is not important', 'geodir-converter' ),
					'type'        => 'text',
					'required'    => false,
					'placeholder' => __( 'e.g. "London"', 'geodir-converter' ),
					'priority'    => 2,
				),
				'remote_position'      => array(
					'label'       => __( 'Remote Position', 'geodir-converter' ),
					'description' => __( 'Select if this is a remote position.', 'geodir-converter' ),
					'type'        => 'checkbox',
					'required'    => false,
					'priority'    => 3,
				),
				'job_type'             => array(
					'label'       => __( 'Job type', 'geodir-converter' ),
					'type'        => $job_type,
					'required'    => true,
					'placeholder' => __( 'Choose job type&hellip;', 'geodir-converter' ),
					'priority'    => 4,
					'default'     => 'full-time',
					'taxonomy'    => self::TAX_LISTING_TYPE,
				),
				'job_category'         => array(
					'label'       => __( 'Job category', 'geodir-converter' ),
					'type'        => 'term-multiselect',
					'required'    => true,
					'placeholder' => '',
					'priority'    => 5,
					'default'     => '',
					'taxonomy'    => self::TAX_LISTING_CATEGORY,
				),
				'job_description'      => array(
					'label'    => __( 'Description', 'geodir-converter' ),
					'type'     => 'wp-editor',
					'required' => true,
					'priority' => 6,
				),
				'application'          => array(
					'label'       => $application_method_label,
					'type'        => 'text',
					'required'    => true,
					'placeholder' => $application_method_placeholder,
					'priority'    => 7,
				),
				'job_salary'           => array(
					'label'       => __( 'Salary', 'geodir-converter' ),
					'type'        => 'text',
					'required'    => false,
					'placeholder' => __( 'e.g. 20000', 'geodir-converter' ),
					'priority'    => 8,
				),
				'job_salary_currency'  => array(
					'label'       => __( 'Salary Currency', 'geodir-converter' ),
					'type'        => 'text',
					'required'    => false,
					'placeholder' => __( 'e.g. USD', 'geodir-converter' ),
					'description' => __( 'Add a salary currency, this field is optional. Leave it empty to use the default salary currency.', 'geodir-converter' ),
					'priority'    => 9,
				),
				'job_salary_unit'      => array(
					'label'       => __( 'Salary Unit', 'geodir-converter' ),
					'type'        => 'select',
					'options'     => $this->get_salary_unit_options(),
					'description' => __( 'Add a salary period unit, this field is optional. Leave it empty to use the default salary unit, if one is defined.', 'geodir-converter' ),
					'required'    => false,
					'priority'    => 10,
				),
				'job_schedule_listing' => array(
					'label'       => __( 'Scheduled Date', 'wp-job-manager' ),
					'description' => __( 'Optionally set the date when this listing will be published.', 'wp-job-manager' ),
					'type'        => 'date',
					'required'    => false,
					'placeholder' => '',
					'priority'    => '6.5',
				),
			),
			'company' => array(
				'company_name'    => array(
					'label'       => __( 'Company name', 'geodir-converter' ),
					'type'        => 'text',
					'required'    => true,
					'placeholder' => __( 'Enter the name of the company', 'geodir-converter' ),
					'priority'    => 1,
				),
				'company_website' => array(
					'label'       => __( 'Website', 'geodir-converter' ),
					'type'        => 'text',
					'required'    => false,
					'placeholder' => __( 'http://', 'geodir-converter' ),
					'priority'    => 2,
				),
				'company_tagline' => array(
					'label'       => __( 'Tagline', 'geodir-converter' ),
					'type'        => 'text',
					'required'    => false,
					'placeholder' => __( 'Briefly describe your company', 'geodir-converter' ),
					'maxlength'   => 64,
					'priority'    => 3,
				),
				'company_video'   => array(
					'label'       => __( 'Video', 'geodir-converter' ),
					'type'        => 'text',
					'required'    => false,
					'placeholder' => __( 'A link to a video about your company', 'geodir-converter' ),
					'priority'    => 4,
				),
				'company_twitter' => array(
					'label'       => __( 'Twitter username', 'geodir-converter' ),
					'type'        => 'text',
					'required'    => false,
					'placeholder' => __( '@yourcompany', 'geodir-converter' ),
					'priority'    => 5,
				),
				'company_logo'    => array(
					'label'              => __( 'Logo', 'geodir-converter' ),
					'type'               => 'file',
					'required'           => false,
					'placeholder'        => '',
					'priority'           => 6,
					'ajax'               => true,
					'multiple'           => false,
					'allowed_mime_types' => array(
						'jpg'  => 'image/jpeg',
						'jpeg' => 'image/jpeg',
						'gif'  => 'image/gif',
						'png'  => 'image/png',
					),
				),
			),
		);

		$fields['job'] = array_merge(
			array(
				'wpjm_id' => $this->normalize_wpjm_field(
					array(
						'label'       => __( 'WPJM ID', 'wp-job-manager' ),
						'description' => __( 'Original WPJM Job ID', 'wp-job-manager' ),
						'type'        => 'INT',
						'required'    => false,
						'placeholder' => '',
						'priority'    => 1,
					)
				),
			),
			$fields['job']
		);

		$fields = apply_filters( 'submit_job_form_fields', $fields );
		$fields = array_merge( $fields['job'], $fields['company'] );

		uasort( $fields, array( $this, 'sort_by_priority' ) );

		if ( ! get_option( 'job_manager_enable_categories' ) || 0 === intval( wp_count_terms( self::TAX_LISTING_CATEGORY ) ) ) {
			unset( $fields['job_category'] );
		}

		if ( ! get_option( 'job_manager_enable_types' ) || 0 === intval( wp_count_terms( self::TAX_LISTING_TYPE ) ) ) {
			unset( $fields['job_type'] );
		}

		if ( ! get_option( 'job_manager_enable_salary' ) ) {
			unset( $fields['job_salary'] );

			if ( ! get_option( 'job_manager_enable_salary_currency' ) ) {
				unset( $fields['job_salary_currency'] );
			}

			if ( ! get_option( 'job_manager_enable_salary_unit' ) ) {
				unset( $fields['job_salary_unit'] );
			}
		}

		if ( ! get_option( 'job_manager_enable_remote_position' ) ) {
			unset( $fields['remote_position'] );
		}

		if ( ! get_option( 'job_manager_enable_scheduled_listings' ) ) {
			unset( $fields['job_schedule_listing'] );
		}

		return $fields;
	}

	/**
	 * Convert BDP field to GD field.
	 *
	 * @since 2.0.2
	 * @param string $key The field key.
	 * @param array  $field The BDP field data.
	 * @param string $post_type The post type.
	 * @param array  $package_ids The package data.
	 * @return array|false The GD field data or false if conversion fails.
	 */
	private function prepare_single_field( $key, $field, $post_type, $package_ids = array() ) {
		$field        = $this->normalize_wpjm_field( $field );
		$gd_field_key = $this->get_gd_field_key( $key );
		$field_type   = isset( $field['type'] ) ? $field['type'] : 'TEXT';
		$gd_field     = geodir_get_field_infoby( 'htmlvar_name', $gd_field_key, $post_type );

		if ( ! $gd_field ) {
			$gd_field = array(
				'post_type'     => $post_type,
				'data_type'     => 'TEXT',
				'field_type'    => $field_type,
				'htmlvar_name'  => $gd_field_key,
				'is_active'     => '1',
				'option_values' => '',
				'is_default'    => '0',
			);
		} else {
			$gd_field['field_id'] = (int) $gd_field['id'];
			unset( $gd_field['id'] );
		}

		$gd_field = array_merge(
			$gd_field,
			array(
				'admin_title'       => $field['label'],
				'frontend_desc'     => $field['description'],
				'placeholder_value' => $field['placeholder'],
				'frontend_title'    => $field['label'],
				'default_value'     => isset( $field['default'] ) ? $field['default'] : '',
				'for_admin_use'     => in_array( $gd_field_key, array( 'wpjm_id' ), true ) ? 1 : 0,
				'is_required'       => isset( $field['required'] ) && true === $field['required'] ? 1 : 0,
				'show_in'           => ( 'listing_title' === $gd_field_key ) ? '[owntab],[detail],[mapbubble]' : '[owntab],[detail]',
				'show_on_pkg'       => $package_ids,
				'clabels'           => $field['label'],
				'option_values'     => isset( $field['options'] ) && ! empty( $field['options'] ) ? implode( ',', $field['options'] ) : '',
			)
		);

		if ( 'file' === $field_type ) {
			$gd_field['extra'] = array(
				'field_type'    => 'file',
				'gd_file_types' => isset( $field['allowed_mime_types'] ) ? array_keys( $field['allowed_mime_types'] ) : geodir_image_extensions(),
				'file_limit'    => isset( $field['multiple'] ) && ! (bool) $field['multiple'] ? 1 : 100,
			);
		} elseif ( 'checkbox' === $field_type ) {
			$gd_field['data_type'] = 'TINYINT';
		} elseif ( 'date' === $field_type ) {
			$gd_field['field_type'] = 'datepicker';
		}

		if ( 'phone' === $gd_field_key ) {
			$gd_field['field_type'] = 'phone';
		} elseif ( 'business_hours' === $gd_field_key ) {
			$gd_field = array_merge(
				$gd_field,
				array(
					'htmlvar_name' => 'business_hours',
					'field_type'   => 'business_hours',
					'field_icon'   => 'fas fa-clock',
				)
			);
		}

		if ( in_array( $gd_field_key, array( 'facebook', 'twitter', 'pinterest', 'linkedin', 'github', 'instagram' ), true ) ) {
			$gd_field['field_icon'] = "fab fa-{$gd_field_key}";
			$gd_field['field_type'] = 'url';
		}

		return $gd_field;
	}

	/**
	 * Import listings from Listify to GeoDirectory.
	 *
	 * @since 2.0.2
	 * @param array $task The offset to start importing from.
	 * @return array Result of the import operation.
	 */
	public function task_parse_listings( array $task ) {
		global $wpdb;

		$offset         = isset( $task['offset'] ) ? (int) $task['offset'] : 0;
		$total_listings = isset( $task['total_listings'] ) ? (int) $task['total_listings'] : 0;
		$batch_size     = (int) $this->get_batch_size();

		// Determine total listings count if not set.
		if ( ! isset( $task['total_listings'] ) ) {
			$total_listings         = $this->count_listings();
			$task['total_listings'] = $total_listings;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			$this->log( __( 'Starting listings parsing process...', 'geodir-converter' ) );
		}

		// Exit early if there are no listings to import.
		if ( 0 === $total_listings ) {
			$this->log( __( 'No listings found for parsing. Skipping process.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		wp_suspend_cache_addition( true );

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

		// Batch the tasks.
		$batched_tasks = array_chunk( $listings, 10, true );
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
			// Continue import with the next batch.
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		return $this->next_task( $task, true );
	}

	/**
	 * Import listings from Listify to GeoDirectory.
	 *
	 * @since 2.0.2
	 * @param array $task The task to import.
	 * @return bool Result of the import operation.
	 */
	public function task_import_listings( $task ) {
		$listings = isset( $task['listings'] ) && ! empty( $task['listings'] ) ? (array) $task['listings'] : array();

		foreach ( $listings as $listing ) {
			$title  = $listing->post_title;
			$result = $this->import_single_listing( $listing );

			$this->process_import_result( $result, 'listing', $title, $listing->ID );
		}

		$this->flush_failed_items();

		return false;
	}

	/**
	 * Convert a single Listify listing to GeoDirectory format.
	 *
	 * @since 2.0.2
	 * @param  \WP_Post $listing The post object to convert.
	 * @return array|int Converted listing data or import status.
	 */
	private function import_single_listing( $listing ) {
		$post = get_post( $listing->ID );

		if ( ! $post ) {
			return self::IMPORT_STATUS_FAILED;
		}

		// Check if the post has already been imported.
		$post_type  = $this->get_import_post_type();
		$gd_post_id = ! $this->is_test_mode() ? $this->get_gd_listing_id( $post->ID, 'wpjm_id', $post_type ) : false;
		$is_update  = ! empty( $gd_post_id );

		// Retrieve all post meta data at once.
		$post_meta = $this->get_post_meta( $post->ID );

		// Retrieve default location and process fields.
		$default_location = $this->get_default_location();
		$fields           = $this->process_form_fields( $post, $post_meta );
		$categories       = $this->get_categories( $post->ID, self::TAX_LISTING_CATEGORY );

		// Location & Address.
		$location = $this->get_default_location();
		$address  = isset( $post_meta['geolocation_street'], $post_meta['geolocation_street_number'] ) ? $post_meta['geolocation_street_number'] . ' ' . $post_meta['geolocation_street'] : '';

		$has_coordinates = isset( $post_meta['geolocation_lat'], $post_meta['geolocation_long'] )
			&& ! empty( $post_meta['geolocation_lat'] )
			&& ! empty( $post_meta['geolocation_long'] );

		if ( $has_coordinates ) {
			$this->log( 'Pulling listing address from coordinates: ' . $post_meta['geolocation_lat'] . ', ' . $post_meta['geolocation_long'], 'info' );
			$location_lookup = GeoDir_Converter_Utils::get_location_from_coords( $post_meta['geolocation_lat'], $post_meta['geolocation_long'] );

			if ( ! is_wp_error( $location_lookup ) ) {
				$address  = isset( $location_lookup['address'] ) && ! empty( $location_lookup['address'] ) ? $location_lookup['address'] : $address;
				$location = array_merge( $location, $location_lookup );
			}
		} else {
			$location['city']      = isset( $post_meta['geolocation_city'] ) ? $post_meta['geolocation_city'] : $default_location['city'];
			$location['region']    = isset( $post_meta['geolocation_state_long'] ) ? $post_meta['geolocation_state_long'] : $default_location['region'];
			$location['country']   = isset( $post_meta['geolocation_country_long'] ) ? $post_meta['geolocation_country_long'] : $default_location['country'];
			$location['zip']       = isset( $post_meta['geolocation_postcode'] ) ? $post_meta['geolocation_postcode'] : '';
			$location['latitude']  = isset( $post_meta['geolocation_lat'] ) ? $post_meta['geolocation_lat'] : $default_location['latitude'];
			$location['longitude'] = isset( $post_meta['geolocation_long'] ) ? $post_meta['geolocation_long'] : $default_location['longitude'];
		}

		// Prepare the listing data.
		$listing = array(
			// Standard WP Fields.
			'post_author'           => $post->post_author ? $post->post_author : get_current_user_id(),
			'post_title'            => $this->get_job_title( $post ),
			'post_content'          => $post->post_content ? $post->post_content : '',
			'post_content_filtered' => $this->get_job_description( $post ),
			'post_excerpt'          => $post->post_excerpt ? $post->post_excerpt : '',
			'post_status'           => $post->post_status,
			'post_type'             => $post_type,
			'comment_status'        => $post->comment_status,
			'ping_status'           => $post->ping_status,
			'post_name'             => $post->post_name ? $post->post_name : 'listing-' . $post->ID,
			'post_date_gmt'         => $post->post_date_gmt,
			'post_date'             => $post->post_date,
			'post_modified_gmt'     => $post->post_modified_gmt,
			'post_modified'         => $post->post_modified,
			'tax_input'             => array(
				$post_type . 'category' => $categories,
				$post_type . '_tags'    => array(),
			),

			// GD fields.
			'default_category'      => ! empty( $categories ) ? $categories[0] : 0,
			'featured_image'        => $this->get_featured_image( $post->ID ),
			'submit_ip'             => '',
			'overall_rating'        => 0,
			'rating_count'          => 0,

			'street'                => $address,
			'street2'               => '',
			'city'                  => $location['city'],
			'region'                => $location['region'],
			'country'               => $location['country'],
			'zip'                   => $location['zip'],
			'latitude'              => $location['latitude'],
			'longitude'             => $location['longitude'],
			'mapview'               => '',
			'mapzoom'               => '',

			// WPJM standard fields.
			'wpjm_id'               => $post->ID,
			'featured'              => isset( $post_meta['_featured'] ) ? (bool) $post_meta['_featured'] : false,
			'position_filled'       => isset( $post_meta['_filled'] ) ? (bool) $post_meta['_filled'] : false,
			'claimed'               => isset( $post_meta['_claimed'] ) ? (bool) $post_meta['_claimed'] : false,
			'expire_date'           => isset( $post_meta['_job_expires'] ) ? $post_meta['_job_expires'] : '',
			'phone'                 => isset( $post_meta['_phone'] ) ? $post_meta['_phone'] : '',
		);

		// Handle test mode.
		if ( $this->is_test_mode() ) {
			return GeoDir_Converter_Importer::IMPORT_STATUS_SUCCESS;
		}

		// Delete existing media if updating.
		if ( $is_update ) {
			GeoDir_Media::delete_files( (int) $gd_post_id, 'post_images' );
		}

		// Process gallery images.
		if ( isset( $post_meta['_gallery'] ) && ! empty( $post_meta['_gallery'] ) ) {
			$images = $this->get_gallery_images( $post_meta['_gallery'] );
			if ( ! empty( $images ) ) {
				$listing['post_images'] = $images;
			}
		}

		// Insert or update the post.
		if ( $is_update ) {
			$listing['ID'] = absint( $gd_post_id );
			$gd_post_id    = wp_update_post( $listing, true );
		} else {
			$gd_post_id = wp_insert_post( $listing, true );
		}

		// Handle errors during post insertion/update.
		if ( is_wp_error( $gd_post_id ) ) {
			$this->log( $gd_post_id->get_error_message() );
			return GeoDir_Converter_Importer::IMPORT_STATUS_FAILED;
		}

		// Update custom fields.
		$gd_post = geodir_get_post_info( (int) $gd_post_id );
		if ( ! empty( $gd_post ) && ! empty( $fields ) ) {
			foreach ( $fields as $field_key => $field_value ) {
				if ( property_exists( $gd_post, $field_key ) ) {
					$gd_post->{$field_key} = $field_value;
				}
			}

			$updated = wp_update_post( (array) $gd_post, true );
			if ( is_wp_error( $updated ) ) {
				$this->log( $updated->get_error_message() );
			}
		}

		return $is_update ? GeoDir_Converter_Importer::IMPORT_STATUS_UPDATED : GeoDir_Converter_Importer::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Counts the number of listings.
	 *
	 * @since 2.0.2
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
	 * Process form fields and extract values from post meta.
	 *
	 * @since 2.0.2
	 *
	 * @param object $post      The post object.
	 * @param array  $post_meta The post meta data.
	 * @return array The processed fields keyed by GeoDirectory field name.
	 */
	private function process_form_fields( $post, $post_meta ) {
		$form_fields = $this->get_fields();
		$fields      = array();

		foreach ( $form_fields as $field_key => $field ) {
			if ( isset( $post_meta[ "_{$field_key}" ] ) ) {
				$gd_key = $this->get_gd_field_key( $field_key );
				$value  = $post_meta[ "_{$field_key}" ];

				if ( $this->should_skip_field( $gd_key ) ) {
					continue;
				}

				// Unserialize a value if it's serialized.
				if ( is_string( $value ) && is_serialized( $value ) ) {
					$value = maybe_unserialize( $value );
				}

				if ( 'business_hours' === $gd_key ) {
					$value = $this->get_business_hours( $post );
				}

				$fields[ $gd_key ] = $value;
			}
		}

		return $fields;
	}

	/**
	 * Convert Listify business hours to GeoDirectory format.
	 *
	 * @since 2.0.2
	 * @param object $post The post object.
	 * @return string Converted business hours.
	 */
	private function get_business_hours( $post ) {
		$hours = $post->_job_hours;
		if ( empty( $hours ) ) {
			return '';
		}

		$new_map = array(
			'mon' => 'Mo',
			'tue' => 'Tu',
			'wed' => 'We',
			'thu' => 'Th',
			'fri' => 'Fr',
			'sat' => 'Sa',
			'sun' => 'Su',
		);

		$new_parts = array();

		foreach ( $hours as $day => $schedule ) {
			if ( ! isset( $new_map[ $day ] ) || empty( $schedule[0]['open'] ) || 'Closed' === $schedule[0]['open'] ) {
				continue;
			}

			$day         = $new_map[ $day ];
			$start       = date( 'H:i', strtotime( $schedule[0]['open'] ) );
			$end         = date( 'H:i', strtotime( $schedule[0]['close'] ) );
			$new_parts[] = "$day $start-$end";
		}

		if ( empty( $new_parts ) ) {
			return 'N;';
		}

		$offset = isset( $post->_job_hours_gmt ) && ! empty( $post->_job_hours_gmt ) ? $post->_job_hours_gmt : get_option( 'gmt_offset' );
		$new    = wp_json_encode( $new_parts );
		$new   .= ',["UTC":"' . $offset . '"]';

		return $new;
	}

	/**
	 * Get the featured image URL.
	 *
	 * @since 2.0.2
	 * @param int $post_id The post ID.
	 * @return string The featured image URL.
	 */
	private function get_featured_image( $post_id ) {
		$image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
		return isset( $image[0] ) ? esc_url( $image[0] ) : '';
	}

	/**
	 * Get gallery images.
	 *
	 * @since 2.0.2
	 * @param string $gallery_images Gallery shortcode or serialized image IDs.
	 * @return string Formatted gallery images data.
	 */
	private function get_gallery_images( $gallery_images ) {
		if ( has_shortcode( $gallery_images, 'gallery' ) ) {
			// Remove brackets and parse attributes.
			$atts      = shortcode_parse_atts( trim( str_replace( array( '[gallery', ']' ), '', $gallery_images ) ) );
			$image_ids = isset( $atts['ids'] ) ? explode( ',', $atts['ids'] ) : array();
		} elseif ( is_string( $gallery_images ) && is_serialized( $gallery_images ) ) {
			$image_ids = maybe_unserialize( $gallery_images );
		}

		if ( ! is_array( $image_ids ) || empty( $image_ids ) ) {
			return '';
		}

		$images = array_map(
			function ( $id ) {
				$id = absint( $id );

				return array(
					'id'      => (int) $id,
					'caption' => '',
					'weight'  => 1,
				);
			},
			$image_ids
		);

		return $this->format_images_data( $images );
	}

	/**
	 * Retrieves the current post's categories.
	 *
	 * @since 2.0.2
	 * @param int    $post_id The post ID.
	 * @param string $taxonomy The taxonomy to query for.
	 * @param string $return_type Determines whether to return IDs or names.
	 * @return array An array of category IDs or names based on the $return_type.
	 */
	private function get_categories( $post_id, $taxonomy = self::TAX_LISTING_CATEGORY, $return_type = 'ids' ) {
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
	 * Gets the job title for the listing.
	 *
	 * @since 2.0.2
	 * @param WP_Post $post post object.
	 * @return string The job title.
	 */
	private function get_job_title( $post ) {
		$title = wp_strip_all_tags( get_the_title( $post ) );

		/**
		 * Filters the job title.
		 *
		 * @since 2.0.2
		 * @param string  $title The job title.
		 * @param WP_Post $post  The post object.
		 */
		return apply_filters( 'geodir_converter_job_title', $title, $post );
	}

	/**
	 * Gets the job description.
	 *
	 * @since 2.0.2
	 * @param WP_Post $post post object.
	 * @return string The job description.
	 */
	private function get_job_description( $post ) {
		$description = str_replace( '<!--more-->', '', $post->post_content );
		$description = apply_filters( 'the_content', $description );

		/**
		 * Filters the job description.
		 *
		 * @since 2.0.2
		 * @param string  $description The job description.
		 * @param WP_Post $post        The post object.
		 */
		return apply_filters( 'geodir_converter_job_description', $description, $post );
	}

	/**
	 * Return an associative array containing the options for salary units, based on Google Structured Data documentation.
	 *
	 * @since 2.0.2
	 *
	 * @param bool $include_empty Whether to include an empty option as default.
	 * @return array Where the key is the identifier used by Google Structured Data, and the value is a translated label.
	 */
	private function get_salary_unit_options( $include_empty = true ) {
		$options = array(
			''      => __( '--', 'geodir-converter' ),
			'YEAR'  => __( 'Year', 'geodir-converter' ),
			'MONTH' => __( 'Month', 'geodir-converter' ),
			'WEEK'  => __( 'Week', 'geodir-converter' ),
			'DAY'   => __( 'Day', 'geodir-converter' ),
			'HOUR'  => __( 'Hour', 'geodir-converter' ),
		);

		if ( ! $include_empty ) {
			unset( $options[''] );
		}

		return apply_filters( 'job_manager_get_salary_unit_options', $options, $include_empty );
	}
}
