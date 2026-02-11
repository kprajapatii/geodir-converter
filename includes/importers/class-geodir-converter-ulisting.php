<?php
/**
 * uListing Converter Class.
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
 * Main converter class for importing from uListing.
 *
 * @since 2.2.0
 */
class GeoDir_Converter_uListing extends GeoDir_Converter_Importer {
	/**
	 * Post type identifier for listings.
	 *
	 * @var string
	 */
	const POST_TYPE_LISTING = 'listing';

	/**
	 * Post type identifier for uListing pricing plans.
	 *
	 * @var string
	 */
	const POST_TYPE_PLAN = 'stm_pricing_plans';

	/**
	 * Taxonomy identifier for listing categories.
	 *
	 * @var string
	 */
	const TAX_LISTING_CATEGORY = 'listing-category';

	/**
	 * Meta key used to link GeoDirectory packages to uListing plans.
	 *
	 * @var string
	 */
	const PACKAGE_META_KEY = '_ulisting_plan_id';

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
	protected $importer_id = 'ulisting';

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
	 * Cached uListing attributes keyed by normalized field name.
	 *
	 * @var array
	 */
	private $ulisting_attributes = array();

	/**
	 * Cached option labels for attribute values.
	 *
	 * @var array
	 */
	private $attribute_option_cache = array();

	/**
	 * Cached uListing listing types.
	 *
	 * @var array|null
	 */
	private $ulisting_listing_types = null;

	/**
	 * Initialize hooks.
	 *
	 * @since 2.2.0
	 */
	protected function init() {
	}

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
		return __( 'uListing', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Import listings from your uListing installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @return string
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/ulisting.png';
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
			<h6 class="fs-base"><?php esc_html_e( 'uListing Importer Settings', 'geodir-converter' ); ?></h6>
			
			<?php
			$this->display_post_type_select();
			$this->display_listing_type_select();
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

		if ( ! in_array( $settings['gd_post_type'], $post_types, true ) ) {
			$errors[] = esc_html__( 'The selected post type is invalid. Please choose a valid post type.', 'geodir-converter' );
		}

		if ( empty( $settings['wp_author_id'] ) || ! get_userdata( (int) $settings['wp_author_id'] ) ) {
			$errors[] = esc_html__( 'The selected WordPress author is invalid. Please select a valid author to import listings to.', 'geodir-converter' );
		}

		$available_types                   = $this->get_ulisting_listing_types();
		$settings['ulisting_listing_type'] = isset( $settings['ulisting_listing_type'] ) ? absint( $settings['ulisting_listing_type'] ) : 0;
		if ( $settings['ulisting_listing_type'] && ! isset( $available_types[ $settings['ulisting_listing_type'] ] ) ) {
			$errors[] = esc_html__( 'The selected uListing listing type is invalid. Please choose a valid listing type.', 'geodir-converter' );
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
	 * Import categories from uListing to GeoDirectory.
	 *
	 * @since 2.2.0
	 * @param array $task Import task.
	 *
	 * @return array Result of the import operation.
	 */
	public function task_import_categories( array $task ) {
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

		$result = $this->import_taxonomy_terms( $categories, $post_type . 'category' );
		$result = wp_parse_args(
			$result,
			array(
				'imported' => 0,
				'failed'   => 0,
			)
		);

		$this->increase_succeed_imports( (int) $result['imported'] );
		$this->increase_failed_imports( (int) $result['failed'] );

		$this->log(
			sprintf(
				/* translators: %1$d: number of imported categories, %2$d: number of failed categories */
				esc_html__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				(int) $result['imported'],
				(int) $result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Import packages from uListing to GeoDirectory.
	 *
	 * @since 2.2.0
	 * @param array $task Task details.
	 * @return array Result of the import operation.
	 */
	public function task_import_packages( array $task ) {
		global $wpdb;

		if ( ! class_exists( 'GeoDir_Pricing_Package' ) ) {
			$this->log( esc_html__( 'Packages: Pricing Manager not active. Skipping packages.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$this->log( esc_html__( 'Packages: Import started.', 'geodir-converter' ) );

		$plans = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content, post_status, menu_order
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_status = 'publish'
				ORDER BY menu_order ASC, ID ASC",
				self::POST_TYPE_PLAN
			)
		);

		if ( empty( $plans ) ) {
			$this->log( esc_html__( 'Packages: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$post_type   = $this->get_import_post_type();
		$imported    = 0;
		$updated     = 0;
		$failed      = 0;
		$default_set = false;

		foreach ( $plans as $plan ) {
			$plan_id       = absint( $plan->ID );
			$plan_meta     = $this->get_post_meta( $plan_id );
			$plan_type     = isset( $plan_meta['type'] ) ? sanitize_key( $plan_meta['type'] ) : 'limit_count';
			$plan_status   = isset( $plan_meta['status'] ) ? sanitize_key( $plan_meta['status'] ) : 'active';
			$payment_type  = isset( $plan_meta['payment_type'] ) ? sanitize_key( $plan_meta['payment_type'] ) : 'one_time';
			$duration_type = isset( $plan_meta['duration_type'] ) ? sanitize_key( $plan_meta['duration_type'] ) : '';

			$price         = isset( $plan_meta['price'] ) ? floatval( $plan_meta['price'] ) : 0.0;
			$duration      = isset( $plan_meta['duration'] ) ? absint( $plan_meta['duration'] ) : 0;
			$listing_limit = isset( $plan_meta['listing_limit'] ) ? absint( $plan_meta['listing_limit'] ) : 0;
			$image_limit   = isset( $plan_meta['listing_image_limit'] ) ? absint( $plan_meta['listing_image_limit'] ) : 0;
			$feature_limit = isset( $plan_meta['feature_limit'] ) ? absint( $plan_meta['feature_limit'] ) : 0;
			$time_interval = $duration > 0 ? $duration : 0;
			$time_unit     = $time_interval > 0 ? $this->map_duration_type_to_unit( $duration_type ) : '';
			$is_recurring  = ( 'subscription' === $payment_type );
			$is_free       = $price <= 0;
			$max_posts     = 0;

			if ( 'limit_count' === $plan_type && $listing_limit > 0 ) {
				$max_posts = $listing_limit;
			} elseif ( 'feature' === $plan_type && $feature_limit > 0 ) {
				$max_posts = $feature_limit;
			}

			$existing_package = $this->package_exists( $post_type, $plan_id, $is_free );

			$package_data = array(
				'post_type'       => $post_type,
				'name'            => $plan->post_title,
				'title'           => $plan->post_title,
				'description'     => $plan->post_content,
				'fa_icon'         => $feature_limit > 0 ? 'fas fa-star' : '',
				'amount'          => $price,
				'time_interval'   => $time_interval,
				'time_unit'       => $time_unit,
				'recurring'       => $is_recurring ? 1 : 0,
				'recurring_limit' => 0,
				'trial'           => '',
				'trial_amount'    => '',
				'trial_interval'  => '',
				'trial_unit'      => '',
				'is_default'      => 0,
				'display_order'   => isset( $plan->menu_order ) ? (int) $plan->menu_order : 0,
				'downgrade_pkg'   => 0,
				'post_status'     => 'pending',
				'status'          => ( 'publish' === $plan->post_status && 'inactive' !== $plan_status ) ? 1 : 0,
				'max_posts'       => $max_posts,
				'image_limit'     => $image_limit,
			);

			if ( $existing_package ) {
				$package_data['id'] = absint( $existing_package->id );
			}

			if ( ! $default_set && $package_data['status'] ) {
				$package_data['is_default'] = 1;
				$default_set                = true;
			}

			if ( $this->is_test_mode() ) {
				$existing_package ? ++$updated : ++$imported;
				continue;
			}

			$package_data = GeoDir_Pricing_Package::prepare_data_for_save( $package_data );
			$package_id   = GeoDir_Pricing_Package::insert_package( $package_data );

			if ( ! $package_id || is_wp_error( $package_id ) ) {
				$this->log( sprintf( esc_html__( 'Packages: Failed to import %s.', 'geodir-converter' ), esc_html( $plan->post_title ) ), 'error' );
				++$failed;
				continue;
			}

			GeoDir_Pricing_Package::update_meta( $package_id, self::PACKAGE_META_KEY, $plan_id );

			$log_message = $existing_package
				? sprintf( esc_html__( 'Packages: Updated %s.', 'geodir-converter' ), esc_html( $plan->post_title ) )
				: sprintf( esc_html__( 'Packages: Imported %s.', 'geodir-converter' ), esc_html( $plan->post_title ) );

			$this->log( $log_message, 'info' );

			if ( $existing_package ) {
				++$updated;
			} else {
				++$imported;
			}
		}

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_failed_imports( $failed );

		$this->log(
			sprintf(
				/* translators: 1: imported packages count, 2: updated packages count, 3: failed packages count */
				esc_html__( 'Packages: %1$d imported, %2$d updated, %3$d failed.', 'geodir-converter' ),
				$imported,
				$updated,
				$failed
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Display uListing listing type selector.
	 */
	private function display_listing_type_select() {
		$selected_type = $this->get_selected_listing_type_id();
		$listing_types = $this->get_ulisting_listing_types();
		$options       = array( 0 => esc_html__( 'All listing types', 'geodir-converter' ) );

		if ( ! empty( $listing_types ) ) {
			foreach ( $listing_types as $id => $title ) {
				$options[ $id ] = $title;
			}
		}

		aui()->select(
			array(
				'id'          => 'ulisting_listing_type',
				'name'        => 'ulisting_listing_type',
				'label'       => esc_html__( 'uListing Listing Type', 'geodir-converter' ),
				'label_type'  => 'top',
				'label_class' => 'font-weight-bold fw-bold',
				'value'       => $selected_type,
				'options'     => $options,
				'help_text'   => esc_html__( 'Choose which uListing listing type to import. Leave set to "All listing types" to import everything.', 'geodir-converter' ),
			),
			true
		);
	}

	/**
	 * Get custom fields from uListing attributes.
	 *
	 * @since 2.2.0
	 * @return array Array of custom field definitions.
	 */
	private function get_custom_fields() {
		global $wpdb;

		$fields = array();

		$table_name = $wpdb->prefix . 'ulisting_attribute';

		// Check if table exists.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return $fields;
		}

		// Validate table name to prevent SQL injection.
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table_name ) ) {
			return $fields;
		}

		$table_name_escaped = esc_sql( $table_name );

		// Get the selected listing type ID.
		$listing_type_id = $this->get_selected_listing_type_id();

		// If a specific listing type is selected, get only its attributes.
		$attribute_ids = array();
		if ( $listing_type_id ) {
			$attribute_ids = get_post_meta( $listing_type_id, 'listing_type_attribute', true );

			// If no attributes found for this listing type, return empty.
			if ( empty( $attribute_ids ) || ! is_array( $attribute_ids ) ) {
				return $fields;
			}

			// Sanitize attribute IDs.
			$attribute_ids = array_map( 'absint', $attribute_ids );
			$attribute_ids = array_filter( $attribute_ids );

			if ( empty( $attribute_ids ) ) {
				return $fields;
			}
		}

		// Build query based on whether we're filtering by listing type.
		if ( ! empty( $attribute_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $attribute_ids ), '%d' ) );
			$attributes   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title, name, type, affix, icon, thumbnail_id
					FROM `{$table_name_escaped}`
					WHERE id IN ($placeholders)
					ORDER BY id ASC",
					$attribute_ids
				)
			);
		} else {
			// No specific listing type selected - get all attributes.
			$attributes = $wpdb->get_results(
				"SELECT id, title, name, type, affix, icon, thumbnail_id
				FROM `{$table_name_escaped}`
				ORDER BY id ASC"
			);
		}

		if ( empty( $attributes ) ) {
			return $fields;
		}

		// Reset attribute caches.
		$this->ulisting_attributes    = array();
		$this->attribute_option_cache = array();

		// Add importer ID field.
		$fields[] = array(
			'type'        => 'text',
			'field_key'   => $this->importer_id . '_id',
			'label'       => __( 'uListing ID', 'geodir-converter' ),
			'description' => __( 'Original uListing Listing ID.', 'geodir-converter' ),
			'icon'        => 'far fa-id-card',
			'required'    => 0,
		);

		foreach ( $attributes as $attribute ) {
			$field_type = $this->map_field_type( $attribute->type );

			if ( ! $field_type ) {
				continue;
			}

			$normalized_name = $this->normalize_field_name( $attribute->name );

			if ( $normalized_name ) {
				$this->ulisting_attributes[ $normalized_name ] = $attribute;
			}

			if ( $this->is_description_field( $normalized_name ) ) {
				continue;
			}

			// Skip uListing's location/address fields – GeoDirectory already provides these.
			if ( 'location' === $attribute->type || $this->map_to_address_field( $normalized_name ) ) {
				continue;
			}

			$field = array(
				'type'        => $field_type,
				'field_key'   => $attribute->name,
				'label'       => ! empty( $attribute->title ) ? $attribute->title : $attribute->name,
				'description' => '',
				'icon'        => ! empty( $attribute->icon ) ? $attribute->icon : $this->get_icon_for_field( $attribute->name ),
				'required'    => 0,
			);

			// Get options for select/multiselect/radio/checkbox fields.
			if ( in_array( $field_type, array( 'select', 'multiselect', 'radio' ), true ) ) {
				$options = $this->get_attribute_options( $attribute->id );
				if ( ! empty( $options ) ) {
					$field['options'] = implode( "\n", $options );
				}
			}

			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * Get attribute options from taxonomy.
	 *
	 * @param int $attribute_id Attribute ID.
	 * @return array Array of option strings.
	 */
	private function get_attribute_options( $attribute_id ) {
		$options = array();

		$terms = get_terms(
			array(
				'taxonomy'     => 'listing-attribute-options',
				'hide_empty'   => false,
				'attribute_id' => absint( $attribute_id ),
			)
		);

		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[] = $term->name;
			}
		}

		return $options;
	}

	/**
	 * Retrieve available uListing listing types.
	 *
	 * @return array Listing types keyed by ID.
	 */
	private function get_ulisting_listing_types() {
		if ( null !== $this->ulisting_listing_types ) {
			return $this->ulisting_listing_types;
		}

		global $wpdb;

		$types = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_status = 'publish'
				ORDER BY post_title ASC, ID ASC",
				'listing_type'
			)
		);

		$listing_types = array();

		if ( ! empty( $types ) ) {
			foreach ( $types as $type ) {
				$listing_types[ $type->ID ] = $type->post_title;
			}
		}

		$this->ulisting_listing_types = $listing_types;

		return $this->ulisting_listing_types;
	}

	/**
	 * Map a uListing attribute name to a GeoDirectory address field.
	 *
	 * @param string $field_name Attribute name.
	 * @return string|false GeoDirectory field key or false if not an address field.
	 */
	private function map_to_address_field( $field_name ) {
		$field_name_clean = $this->normalize_field_name( $field_name );

		if ( empty( $field_name_clean ) ) {
			return false;
		}

		$mapping = array(
			'address'        => 'street',
			'street'         => 'street',
			'street_address' => 'street',
			'street1'        => 'street',
			'street2'        => 'street2',
			'city'           => 'city',
			'town'           => 'city',
			'region'         => 'region',
			'state'          => 'region',
			'province'       => 'region',
			'county'         => 'region',
			'country'        => 'country',
			'zip'            => 'zip',
			'postcode'       => 'zip',
			'postal_code'    => 'zip',
			'latitude'       => 'latitude',
			'lat'            => 'latitude',
			'longitude'      => 'longitude',
			'lng'            => 'longitude',
		);

		return isset( $mapping[ $field_name_clean ] ) ? $mapping[ $field_name_clean ] : false;
	}

	/**
	 * Determine if a field should populate post content.
	 *
	 * @param string $field_name Field name.
	 * @return bool
	 */
	private function is_description_field( $field_name ) {
		$field_name_clean = $this->normalize_field_name( $field_name );

		return in_array( $field_name_clean, array( 'description', 'post_content' ), true );
	}

	/**
	 * Normalize a field name for internal comparisons.
	 *
	 * @param string $field_name Field name.
	 * @return string Normalized field name.
	 */
	private function normalize_field_name( $field_name ) {
		$field_name = strtolower( (string) $field_name );
		$field_name = preg_replace( '/^field_/', '', $field_name );

		return str_replace( array( '-', ' ' ), '_', $field_name );
	}

	/**
	 * Get the selected listing type ID from settings.
	 *
	 * @return int Listing type ID or 0 for all types.
	 */
	private function get_selected_listing_type_id() {
		$selected = $this->get_import_setting( 'ulisting_listing_type', 0 );

		return absint( $selected );
	}

	/**
	 * Determine if a listing belongs to the selected listing type.
	 *
	 * @param int $listing_id Listing ID.
	 * @return bool
	 */
	private function listing_matches_selected_type( $listing_id ) {
		$listing_type_id = $this->get_selected_listing_type_id();

		if ( ! $listing_type_id ) {
			return true;
		}

		global $wpdb;

		$rel_table = $wpdb->prefix . 'ulisting_listing_type_relationships';
		$match     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT listing_id
				FROM {$rel_table}
				WHERE listing_id = %d
				AND listing_type_id = %d
				LIMIT 1",
				$listing_id,
				$listing_type_id
			)
		);

		return ! empty( $match );
	}

	/**
	 * Get uListing attribute details for a field name.
	 *
	 * @param string $field_name Field name.
	 * @return object|null
	 */
	private function get_ulisting_attribute( $field_name ) {
		$key = $this->normalize_field_name( $field_name );

		return isset( $this->ulisting_attributes[ $key ] ) ? $this->ulisting_attributes[ $key ] : null;
	}

	/**
	 * Determine if the attribute type is choice-based.
	 *
	 * @param string $type Attribute type.
	 * @return bool
	 */
	private function is_choice_attribute_type( $type ) {
		$choice_types = array( 'select', 'multiselect', 'checkbox', 'radio_button', 'yes_no' );

		return in_array( $type, $choice_types, true );
	}

	/**
	 * Convert choice-based attribute values to their option labels.
	 *
	 * @param object     $attribute Attribute details.
	 * @param int|string $value     Raw value.
	 * @return int|string|array
	 */
	private function maybe_convert_choice_value( $attribute, $value ) {
		if ( empty( $attribute ) || empty( $attribute->type ) || ! $this->is_choice_attribute_type( $attribute->type ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $index => $single_value ) {
				$value[ $index ] = $this->get_attribute_option_label( $attribute->id, $single_value );
			}

			return $value;
		}

		return $this->get_attribute_option_label( $attribute->id, $value );
	}

	/**
	 * Resolve the display label for an attribute option value.
	 *
	 * @param int        $attribute_id Attribute ID.
	 * @param int|string $value        Raw value stored by uListing.
	 * @return string
	 */
	private function get_attribute_option_label( $attribute_id, $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;

		if ( '' === $value || null === $value ) {
			return '';
		}

		$cache_key = $attribute_id . ':' . $value;
		if ( isset( $this->attribute_option_cache[ $cache_key ] ) ) {
			return $this->attribute_option_cache[ $cache_key ];
		}

		$label = (string) $value;
		$term  = false;

		if ( is_numeric( $value ) ) {
			$term = get_term( (int) $value, 'listing-attribute-options' );
		}

		if ( ! $term || is_wp_error( $term ) ) {
			$term = get_term_by( 'slug', sanitize_title( (string) $value ), 'listing-attribute-options' );
		}

		if ( $term && ! is_wp_error( $term ) ) {
			$attr_meta = (int) get_term_meta( $term->term_id, 'attribute_id', true );

			if ( ! $attr_meta || (int) $attribute_id === $attr_meta ) {
				$label = $term->name;
			}
		}

		$this->attribute_option_cache[ $cache_key ] = $label;

		return $label;
	}

	/**
	 * Map uListing field type to GeoDirectory field type.
	 *
	 * @param string $ulisting_type uListing field type.
	 * @return string|false GeoDirectory field type or false if not supported.
	 */
	private function map_field_type( $ulisting_type ) {
		$type_map = array(
			'text'         => 'text',
			'text_area'    => 'textarea',
			'wp_editor'    => 'textarea',
			'number'       => 'number',
			'date'         => 'datepicker',
			'time'         => 'time',
			'select'       => 'select',
			'multiselect'  => 'multiselect',
			'checkbox'     => 'checkbox',
			'radio_button' => 'radio',
			'yes_no'       => 'checkbox',
			'file'         => 'file',
			'gallery'      => 'file',
			'price'        => 'text',
			'location'     => 'address',
			'video'        => 'textarea',
		);

		return isset( $type_map[ $ulisting_type ] ) ? $type_map[ $ulisting_type ] : false;
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
			'multiselect' => 'TEXT',
			'file'        => 'TEXT',
			'number'      => 'INT',
			'address'     => 'VARCHAR',
		);

		return isset( $type_map[ $field_type ] ) ? $type_map[ $field_type ] : 'VARCHAR';
	}

	/**
	 * Map uListing duration type to GeoDirectory time unit.
	 *
	 * @param string $duration_type Duration type string.
	 * @return string GeoDirectory time unit.
	 */
	private function map_duration_type_to_unit( $duration_type ) {
		$duration_type = strtolower( (string) $duration_type );

		switch ( $duration_type ) {
			case 'day':
			case 'days':
				return 'D';
			case 'month':
			case 'months':
				return 'M';
			case 'year':
			case 'years':
				return 'Y';
			default:
				return 'D';
		}
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
			if ( false !== strpos( $field_key, $keyword ) ) {
				return $icon;
			}
		}

		return 'fas fa-info-circle';
	}

	/**
	 * Import fields from uListing to GeoDirectory.
	 *
	 * @since 2.2.0
	 * @param array $task Task details.
	 * @return array Result of the import operation.
	 */
	public function task_import_fields( array $task ) {
		$listing_type_id   = $this->get_selected_listing_type_id();
		$listing_type_name = __( 'All listing types', 'geodir-converter' );

		if ( $listing_type_id ) {
			$listing_types     = $this->get_ulisting_listing_types();
			$listing_type_name = isset( $listing_types[ $listing_type_id ] ) ? $listing_types[ $listing_type_id ] : "Listing Type ID: {$listing_type_id}";
		}

		$this->log(
			sprintf(
				/* translators: %s: listing type name */
				__( 'Importing custom fields from: %s', 'geodir-converter' ),
				$listing_type_name
			)
		);

		$post_type   = $this->get_import_post_type();
		$fields      = $this->get_custom_fields();
		$package_ids = $this->get_package_ids( $post_type );

		if ( empty( $fields ) ) {
			$this->log(
				sprintf(
					/* translators: %1$s: listing type name, %2$s: post type */
					__( 'No custom fields found for %1$s (post type: %2$s)', 'geodir-converter' ),
					$listing_type_name,
					$post_type
				),
				'warning'
			);
			return $this->next_task( $task );
		}

		$this->log(
			sprintf(
				/* translators: %1$d: number of fields, %2$s: listing type name */
				__( 'Found %1$d custom fields for: %2$s', 'geodir-converter' ),
				count( $fields ),
				$listing_type_name
			)
		);

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

			// Set global $post for GeoDirectory functions.
			global $post;
			$original_post = $post;
			$post          = (object) array( 'ID' => 0 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			$result = geodir_custom_field_save( $gd_field );

			// Restore original $post.
			$post = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

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
	 * @param array  $field uListing field data.
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

		$listing_type_id = $this->get_selected_listing_type_id();
		$params          = array();
		$statuses        = implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) );

		$sql = "SELECT p.ID, p.post_title, p.post_status FROM {$wpdb->posts} p";

		if ( $listing_type_id ) {
			$rel_table = $wpdb->prefix . 'ulisting_listing_type_relationships';
			$sql      .= " INNER JOIN {$rel_table} rel ON rel.listing_id = p.ID AND rel.listing_type_id = %d";
			$params[]  = $listing_type_id;
		}

		$sql     .= " WHERE p.post_type = %s AND p.post_status IN ({$statuses}) LIMIT %d OFFSET %d";
		$params[] = self::POST_TYPE_LISTING;
		$params   = array_merge( $params, $this->post_statuses, array( $batch_size, $offset ) );

		$listings = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

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
	 * Convert a single uListing listing to GeoDirectory format.
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

		if ( ! $this->listing_matches_selected_type( $post->ID ) ) {
			return self::IMPORT_STATUS_SKIPPED;
		}

		$default_location = $this->get_default_location();

		// Get categories.
		$categories = $this->get_listings_terms( $post->ID, self::TAX_LISTING_CATEGORY );

		// Get field values from uListing attribute relationships.
		$field_values = $this->get_field_values( $post->ID );

		// Location data.
		$location = $default_location;

		// Map individual address components.
		$location_fields = array(
			'address'   => array( 'address', 'street', 'street_address' ),
			'city'      => array( 'city', 'town' ),
			'region'    => array( 'region', 'state', 'province', 'county' ),
			'country'   => array( 'country' ),
			'zip'       => array( 'zip', 'postal_code', 'postcode' ),
			'latitude'  => array( 'latitude', 'lat' ),
			'longitude' => array( 'longitude', 'lng' ),
		);

		foreach ( $location_fields as $gd_key => $possible_keys ) {
			if ( ! empty( $location[ $gd_key ] ) ) {
				continue;
			}

			foreach ( $possible_keys as $possible_key ) {
				if ( isset( $field_values[ $possible_key ] ) ) {
					$value = trim( (string) $field_values[ $possible_key ] );
					if ( '' === $value ) {
						continue;
					}

					$location[ $gd_key ] = $value;
					break;
				}
			}
		}

		$has_coordinates = isset( $field_values['latitude'], $field_values['longitude'] ) && ! empty( $field_values['latitude'] ) && ! empty( $field_values['longitude'] );
		$latitude        = isset( $field_values['latitude'] ) && ! empty( $field_values['latitude'] ) ? $field_values['latitude'] : $location['latitude'];
		$longitude       = isset( $field_values['longitude'] ) && ! empty( $field_values['longitude'] ) ? $field_values['longitude'] : $location['longitude'];

		// If we have coordinates, attempt to backfill missing address parts.
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
			),

			// GD fields.
			'default_category'         => ! empty( $categories ) ? $categories[0] : 0,
			'featured_image'           => $this->get_featured_image( $post->ID ),
			'submit_ip'                => '',
			'overall_rating'           => 0,
			'rating_count'             => 0,

			'street'                   => isset( $location['address'] ) ? $location['address'] : '',
			'street2'                  => '',
			'city'                     => isset( $location['city'] ) ? $location['city'] : '',
			'region'                   => isset( $location['region'] ) ? $location['region'] : '',
			'country'                  => isset( $location['country'] ) ? $location['country'] : '',
			'zip'                      => isset( $location['zip'] ) ? $location['zip'] : '',
			'latitude'                 => isset( $location['latitude'] ) ? $location['latitude'] : '',
			'longitude'                => isset( $location['longitude'] ) ? $location['longitude'] : '',
			'mapview'                  => '',
			'mapzoom'                  => '',

			// uListing standard fields.
			$this->importer_id . '_id' => absint( $post->ID ),
		);

		// Add custom field values.
		foreach ( $field_values as $field_name => $field_value ) {
			// Skip location field (handled above).
			if ( 'location' === $field_name ) {
				continue;
			}

			// Skip null values.
			if ( null === $field_value ) {
				continue;
			}

			// Handle multiselect fields.
			if ( is_array( $field_value ) ) {
				$field_value = implode( ',', array_filter( array_map( 'trim', $field_value ) ) );
			}

			if ( $this->is_description_field( $field_name ) ) {
				if ( '' !== trim( (string) $field_value ) ) {
					$listing_data['post_content'] = wp_kses_post( (string) $field_value );
				}
				continue;
			}

			$address_field = $this->map_to_address_field( $field_name );
			if ( $address_field ) {
				// Only override existing address data if we don't already have a value (except for lat/lng).
				$can_override = in_array( $address_field, array( 'latitude', 'longitude' ), true ) || empty( $listing_data[ $address_field ] );
				if ( $can_override && '' !== trim( (string) $field_value ) ) {
					$listing_data[ $address_field ] = $field_value;
				}
				continue;
			}

			$listing_data[ $field_name ] = $field_value;
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
	 * Get field values from uListing attribute relationships.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array of field values.
	 */
	private function get_field_values( $post_id ) {
		global $wpdb;

		$field_values = array();

		$table_name = $wpdb->prefix . 'ulisting_listing_attribute_relationships';

		// Check if table exists.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return $field_values;
		}

		// Validate table name to prevent SQL injection.
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table_name ) ) {
			return $field_values;
		}

		$table_name_escaped = esc_sql( $table_name );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attribute, value
				FROM `{$table_name_escaped}`
				WHERE listing_id = %d
				ORDER BY sort ASC",
				absint( $post_id )
			)
		);

		if ( empty( $results ) ) {
			return $field_values;
		}

		foreach ( $results as $row ) {
			$field_name = $row->attribute;
			$value      = $row->value;

			// Handle serialized values.
			if ( is_serialized( $value ) ) {
				$value = maybe_unserialize( $value );
			}

			$attribute_info = $this->get_ulisting_attribute( $field_name );
			if ( $attribute_info ) {
				$value = $this->maybe_convert_choice_value( $attribute_info, $value );
			}

			// Handle multiselect fields (multiple rows with same attribute).
			if ( isset( $field_values[ $field_name ] ) ) {
				if ( ! is_array( $field_values[ $field_name ] ) ) {
					$field_values[ $field_name ] = array( $field_values[ $field_name ] );
				}
				$field_values[ $field_name ][] = $value;
			} else {
				$field_values[ $field_name ] = $value;
			}
		}

		return $field_values;
	}

	/**
	 * Get listings terms (categories).
	 *
	 * @param int    $post_id The post ID.
	 * @param string $taxonomy The taxonomy to get terms from.
	 * @return array Array of term IDs.
	 */
	private function get_listings_terms( $post_id, $taxonomy ) {
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
					$term_data[] = $gd_term->term_id;
				} else {
					// Fallback to original term if GD equivalent term not found.
					$term_data[] = $term->term_id;
				}
			} else {
				// No GD equivalent, use original term.
				$term_data[] = $term->term_id;
			}
		}

		return $term_data;
	}

	/**
	 * Count the number of listings.
	 *
	 * @return int The number of listings.
	 */
	private function count_listings() {
		global $wpdb;

		$listing_type_id = $this->get_selected_listing_type_id();
		$params          = array();
		$statuses        = implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) );

		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} p";

		if ( $listing_type_id ) {
			$rel_table = $wpdb->prefix . 'ulisting_listing_type_relationships';
			$sql      .= " INNER JOIN {$rel_table} rel ON rel.listing_id = p.ID AND rel.listing_type_id = %d";
			$params[]  = $listing_type_id;
		}

		$sql     .= " WHERE p.post_type = %s AND p.post_status IN ({$statuses})";
		$params[] = self::POST_TYPE_LISTING;
		$params   = array_merge( $params, $this->post_statuses );

		$count = $wpdb->get_var( $wpdb->prepare( $sql, $params ) );

		return (int) $count;
	}

	/**
	 * Count the number of uListing pricing plans.
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
				self::POST_TYPE_PLAN
			)
		);

		return $count ? (int) $count : 0;
	}

	/**
	 * Check if a GeoDirectory package already exists for the given uListing plan.
	 *
	 * @param string $post_type GeoDirectory post type.
	 * @param int    $plan_id   uListing plan ID.
	 * @param bool   $free_fallback Whether to fallback to an existing free package.
	 *
	 * @return object|null Existing package row or null.
	 */
	private function package_exists( $post_type, $plan_id, $free_fallback = true ) {
		global $wpdb;

		$existing_package = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT p.*
				FROM ' . GEODIR_PRICING_PACKAGES_TABLE . ' AS p
				INNER JOIN ' . GEODIR_PRICING_PACKAGE_META_TABLE . ' AS pm ON pm.package_id = p.id
				WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value = %d
				LIMIT 1',
				$post_type,
				self::PACKAGE_META_KEY,
				(int) $plan_id
			)
		);

		if ( ! $existing_package && $free_fallback ) {
			$existing_package = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . GEODIR_PRICING_PACKAGES_TABLE . ' 
					WHERE post_type = %s AND amount = 0 AND status = 1
					ORDER BY display_order ASC, id ASC
					LIMIT 1',
					$post_type
				)
			);
		}

		return $existing_package;
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
	 * Import comments from uListing listing to GeoDirectory listing.
	 *
	 * @since 2.2.0
	 * @param int $ulisting_listing_id uListing listing ID.
	 * @param int $gd_post_id GeoDirectory post ID.
	 * @return void
	 */
	private function import_comments( $ulisting_listing_id, $gd_post_id ) {
		global $wpdb;

		if ( $this->is_test_mode() ) {
			return;
		}

		// Get all comments for the uListing listing.
		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_post_ID, comment_author, comment_author_email, 
				        comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
				        comment_content, comment_karma, comment_approved, comment_agent,
				        comment_type, comment_parent, user_id
				FROM {$wpdb->comments}
				WHERE comment_post_ID = %d",
				$ulisting_listing_id
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
		}

		// Update comment count for the new post.
		wp_update_comment_count( $gd_post_id );
	}
}
