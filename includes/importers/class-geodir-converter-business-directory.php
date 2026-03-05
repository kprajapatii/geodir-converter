<?php
/**
 * Ajax Class for Geodir Converter.
 *
 * @since      2.0.2
 * @package    GeoDir_Converter
 * @version    2.0.2
 */

namespace GeoDir_Converter\Importers;

use WP_Error;
use WP_Query;
use GeoDir_Media;
use GeoDir_Pricing_Package;
use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Main ajax class for handling AJAX requests.
 *
 * @since 1.0.0
 */
class GeoDir_Converter_Business_Directory extends GeoDir_Converter_Importer {
	/**
	 * Post type name for job listings.
	 *
	 * @var string
	 */
	private const POST_TYPE_LISTING = 'wpbdp_listing';

	/**
	 * Taxonomy name for job listing categories.
	 *
	 * Used to classify listings into different categories.
	 *
	 * @var string
	 */
	private const TAX_LISTING_CATEGORY = 'wpbdp_category';

	/**
	 * Taxonomy name for job listing tags.
	 *
	 * Used for tagging and filtering job listings.
	 *
	 * @var string
	 */
	private const TAX_LISTING_TAG = 'wpbdp_tag';

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
	protected $importer_id = 'business_directory';

	/**
	 * The import listing status ID.
	 *
	 * @var array
	 */
	protected $post_statuses = array( 'publish', 'draft', 'pending' );

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
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
		return __( 'Business Directory', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer description.
	 */
	public function get_description() {
		return __( 'Import listings from your Business Directory installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer icon URL.
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/business-directory.png';
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
			<h6 class="fs-base"><?php esc_html_e( 'Business Directory Importer Settings', 'geodir-converter' ); ?></h6>
			
			<?php
			if ( ! defined( 'GEODIR_PRICING_VERSION' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'GeoDirectory Pricing Manager', 'geodir-converter' ),
					'plans',
					esc_url( 'https://wpgeodirectory.com/downloads/pricing-manager/' )
				);
			}

			$this->display_post_type_select();
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
	 * @param array $files    Optional. The uploaded files to validate. Default empty array.
	 * @return array|WP_Error Validated settings array or WP_Error on failure.
	 */
	public function validate_settings( array $settings, array $files = array() ) {
		global $wpdb;

		$post_types      = geodir_get_posttypes();
		$errors          = array();
		$required_tables = array(
			'wpbdp_form_fields',
			'wpbdp_listings',
			'wpbdp_plans',
		);

		$settings['test_mode']    = ( isset( $settings['test_mode'] ) && ! empty( $settings['test_mode'] ) && $settings['test_mode'] != 'no' ) ? 'yes' : 'no';
		$settings['gd_post_type'] = ( isset( $settings['gd_post_type'] ) && ! empty( $settings['gd_post_type'] ) ) ? sanitize_text_field( $settings['gd_post_type'] ) : 'gd_place';

		if ( ! in_array( $settings['gd_post_type'], $post_types, true ) ) {
			$errors[] = esc_html__( 'The selected post type is invalid. Please choose a valid post type.', 'geodir-converter' );
		}

		// Check if the required tables exist.
		$missing_tables = array();
		foreach ( $required_tables as $table ) {
			$table_name = $wpdb->prefix . $table;

			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) ) {
				$missing_tables[] = $table_name;
			}
		}

		if ( ! empty( $missing_tables ) ) {
			$errors[] = sprintf(
				'<h6 class="mb-2">%s</h6> <p class="mb-0">%s</p>',
				esc_html__( 'Required tables are missing', 'geodir-converter' ),
				esc_html( implode( ', ', $missing_tables ) )
			);
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'invalid_import_settings', implode( '<br>', $errors ) );
		}

		return $settings;
	}

	/**
	 * Get next task in the import sequence.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task The current task data.
	 * @return array|false The next task array or false if all tasks are completed.
	 */
	public function next_task( $task ) {
		$task['imported'] = 0;
		$task['failed']   = 0;
		$task['skipped']  = 0;
		$task['updated']  = 0;

		$tasks = array(
			self::ACTION_IMPORT_CATEGORIES,
			self::ACTION_IMPORT_TAGS,
			self::ACTION_IMPORT_PACKAGES,
			self::ACTION_IMPORT_FIELDS,
			self::ACTION_IMPORT_LISTINGS,
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

		// Count tags.
		$tags         = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_LISTING_TAG ) );
		$total_items += is_wp_error( $tags ) ? 0 : $tags;

		// Count packages.
		$packages     = (int) $this->get_plans( true );
		$total_items += $packages;

		// Count custom fields.
		$custom_fields = (int) $this->get_form_fields( true );
		$total_items  += $custom_fields;

		// Count listings.
		$total_items += (int) $this->count_listings();

		$this->increase_imports_total( $total_items );
	}

	/**
	 * Import categories from Business Directory to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Task details including the current action and counters.
	 * @return array Updated task details with the next action.
	 */
	public function task_import_categories( array $task ) {
		global $wpdb;

		$this->log( __( 'Categories: Import started.', 'geodir-converter' ) );
		$this->set_import_total();

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
			$this->log( __( 'No categories available for import. Skipping...', 'geodir-converter' ) );
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
	 * Import tags from Business Directory to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Task details including the current action and counters.
	 * @return array Updated task details with the next action.
	 */
	public function task_import_tags( array $task ) {
		global $wpdb;

		$this->log( __( 'Tags: Import started.', 'geodir-converter' ) );

		$post_type = $this->get_import_post_type();

		$tags = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, tt.*
                FROM {$wpdb->terms} AS t
                INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
                WHERE tt.taxonomy = %s",
				self::TAX_LISTING_TAG
			)
		);

		if ( empty( $tags ) || is_wp_error( $tags ) ) {
			$this->log( __( 'Tags: No items to import.', 'geodir-converter' ), 'notice' );
			return $this->next_task( $task );
		}

        if ( $this->is_test_mode() ) {
			$this->increase_succeed_imports( count( $tags ) );
			$this->log(
				sprintf(
				/* translators: %1$d: number of imported terms, %2$d: number of failed imports */
					__( 'Tags: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
					count( $tags ),
					0
				),
				'success'
			);
			return $this->next_task( $task );
		}

		$result = $this->import_taxonomy_terms( $tags, $post_type . '_tags', 'ct_cat_top_desc' );

		$this->increase_succeed_imports( (int) $result['imported'] );
		$this->increase_failed_imports( (int) $result['failed'] );

		$this->log(
			sprintf(
			/* translators: %1$d: number of imported terms, %2$d: number of failed imports */
				__( 'Tags: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$result['imported'],
				$result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Import packages from Business Directory to GeoDirectory pricing plans.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details including the current action and counters.
	 * @return array Updated task with the next action.
	 */
	public function task_import_packages( array $task ) {
		$post_type = $this->get_import_post_type();
		$plans     = $this->get_plans();

		if ( empty( $plans ) ) {
			$this->log( __( 'Packages: No items to import.', 'geodir-converter' ), 'notice' );
			return $this->next_task( $task );
		}

		$imported = $updated = $failed = 0;

		foreach ( $plans as $plan ) {
			$plan_id          = absint( $plan['id'] );
			$plan_label       = sanitize_text_field( $plan['label'] );
			$plan_description = sanitize_textarea_field( $plan['description'] );

			// Check if the plan already exists.
			$existing_package = $this->get_existing_package( $post_type, $plan_id, 'free' === $plan['tag'] );

			$package_data = array(
				'post_type'       => $post_type,
				'name'            => $plan_label,
				'title'           => $plan_label,
				'description'     => $plan_description,
				'fa_icon'         => '',
				'amount'          => floatval( $plan['amount'] ),
				'time_interval'   => absint( $plan['days'] ),
				'time_unit'       => $plan['days'] > 0 ? 'D' : 'M',
				'recurring'       => (bool) $plan['recurring'],
				'recurring_limit' => 0,
				'trial'           => '',
				'trial_amount'    => '',
				'trial_interval'  => '',
				'trial_unit'      => '',
				'is_default'      => 0,
				'display_order'   => absint( $plan['weight'] ),
				'downgrade_pkg'   => 0,
				'post_status'     => 'pending',
				'status'          => (bool) $plan['enabled'],
			);

			// If existing package found, update ID before saving.
			if ( $existing_package ) {
				$package_data['id'] = absint( $existing_package->id );
			}

			// Prepare and insert/update package.
			$package_data = GeoDir_Pricing_Package::prepare_data_for_save( $package_data );
			$package_id   = GeoDir_Pricing_Package::insert_package( $package_data );

			if ( ! $package_id || is_wp_error( $package_id ) ) {
				/* translators: %s: plan name */
				$this->log( sprintf( __( 'Failed to import plan: %s', 'geodir-converter' ), $plan_label ), 'error' );
				++$failed;
			} else {
				$log_message = $existing_package
				/* translators: %s: plan name */
				? sprintf( __( 'Updated plan: %s', 'geodir-converter' ), $plan_label )
				/* translators: %s: plan name */
				: sprintf( __( 'Imported new plan: %s', 'geodir-converter' ), $plan_label );

				$this->log( $log_message );

				$existing_package ? ++$updated : ++$imported;

				GeoDir_Pricing_Package::update_meta( $package_id, '_bdp_package_id', $plan_id );
			}
		}

		$this->increase_succeed_imports( (int) $imported );
		$this->increase_skipped_imports( (int) $updated );
		$this->increase_failed_imports( (int) $failed );

		$this->log(
			sprintf(
				/* translators: %1$d: imported count, %2$d: updated count, %3$d: failed count */
				__( 'Plans import completed: %1$d imported, %2$d updated, %3$d failed.', 'geodir-converter' ),
				$imported,
				$updated,
				$failed
			),
			$failed ? 'warning' : 'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Import custom fields from Business Directory to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details including the current action and counters.
	 * @return array Updated task with the next action.
	 */
	public function task_import_fields( array $task ) {
		global $plugin_prefix;

		$this->log( __( 'Importing listing fields...', 'geodir-converter' ) );

		$fields = $this->get_form_fields();

		if ( empty( $fields ) ) {
			$this->log( __( 'No fields found for import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$post_type   = $this->get_import_post_type();
		$table       = $plugin_prefix . $post_type . '_detail';
		$package_ids = $this->get_package_ids( $post_type );

		$imported = $updated = $skipped = $failed = 0;

		foreach ( $fields as $field ) {
			$field['display_flags'] = ! empty( $field['display_flags'] ) ? explode( ',', $field['display_flags'] ) : array();
			$field['validators']    = ! empty( $field['validators'] ) ? explode( ',', $field['validators'] ) : array();
			$field['field_data']    = ! empty( $field['field_data'] ) ? unserialize( $field['field_data'] ) : array();

			$gd_field = $this->prepare_single_field( $field['shortname'], $field, $post_type, $package_ids );

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
				__( 'Custom fields import completed: %1$d imported, %2$d updated, %3$d skipped, %4$d failed.', 'geodir-converter' ),
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
	 * Import listings from Business Directory to GeoDirectory in batches.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task The import task data including offset and batch size.
	 * @return array|false Updated task for the next batch or false if complete.
	 */
	public function task_import_listings( array $task ) {
		global $wpdb;

		$this->log( __( 'Starting listings import...', 'geodir-converter' ) );

		$offset         = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$batch_size     = $this->get_batch_size();
		$total_listings = $this->count_listings();

		if ( 0 === $total_listings ) {
			$this->log( __( 'No listings to import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
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
			$this->log( __( 'Finished importing listings.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		foreach ( $listings as $listing ) {
			$post = get_post( $listing->ID );
			if ( ! $post ) {
				$this->process_import_result( self::IMPORT_STATUS_FAILED, 'listing', $listing->post_title, $listing->ID );
				continue;
			}

			$result = $this->import_single_listing( $post );

			$this->process_import_result( $result, 'listing', $post->post_title, $post->ID );
		}

		$this->flush_failed_items();

		$complete = ( $offset + $batch_size >= $total_listings );

		if ( ! $complete ) {
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		return $this->next_task( $task );
	}

	/**
	 * Convert a single Business Directory listing to GeoDirectory format.
	 *
	 * @since 2.0.2
	 *
	 * @param \WP_Post $post The Business Directory listing post object.
	 * @return int Import status constant (IMPORT_STATUS_SUCCESS, IMPORT_STATUS_FAILED, or IMPORT_STATUS_SKIPPED).
	 */
	private function import_single_listing( $post ) {
		// Check if the post has already been imported.
		$post_type  = $this->get_import_post_type();
		$gd_post_id = ! $this->is_test_mode() ? $this->get_gd_listing_id( $post->ID, 'bdp_id', $post_type ) : false;
		$is_update  = ! empty( $gd_post_id );

		$default_location = $this->get_default_location();
		$subscription     = $this->get_subscription( $post->ID );
		$post_meta        = get_post_meta( $post->ID );

		$fields     = $this->process_form_fields( $post_meta );
		$categories = $this->get_categories( $post->ID, self::TAX_LISTING_CATEGORY );
		$tags       = $this->get_categories( $post->ID, self::TAX_LISTING_TAG, 'names' );
		$images     = $this->get_listing_images( $post );

		$listing = array(
			// Standard WP Fields.
			'post_author'           => $post->post_author ? $post->post_author : get_current_user_id(),
			'post_content'          => $post->post_content ? $post->post_content : '',
			'post_content_filtered' => $post->post_content_filtered,
			'post_title'            => $post->post_title,
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
				"{$post_type}category" => $categories,
				"{$post_type}_tags"    => $tags,
			),

			// GD fields.
			'default_category'      => isset( $categories[0] ) ? $categories[0] : 0,
			'featured_image'        => $this->get_featured_image( $post->ID ),
			'submit_ip'             => '',
			'overall_rating'        => 0,
			'rating_count'          => 0,

			// Address.
			'city'                  => isset( $post->geolocation_city ) ? $post->geolocation_city : $default_location['city'],
			'region'                => isset( $post->geolocation_state ) ? $post->geolocation_state : $default_location['region'],
			'country'               => isset( $post->geolocation_country ) ? $post->geolocation_country : $default_location['country'],
			'latitude'              => isset( $post->geolocation_lat ) ? $post->geolocation_lat : $default_location['latitude'],
			'longitude'             => isset( $post->geolocation_long ) ? $post->geolocation_long : $default_location['longitude'],
			'mapview'               => '',
			'mapzoom'               => '',

			// BDP fields.
			'bdp_id'                => $post->ID,
		);

		if ( empty( $post->geolocation_city ) && ( empty( $post->geolocation_lat ) || empty( $post->geolocation_long ) ) && ! empty( $fields['street'] ) && empty( $fields['city'] ) && ( empty( $fields['latitude'] ) || empty( $fields['longitude'] ) ) ) {
			$zip      = ! empty( $fields['zip'] ) ? $fields['zip'] : '-';
			$gps_data = \GeoDir_Admin_Import_Export::get_post_gps_from_address(
				array(
					'street' => $fields['street'],
					'city'   => $zip,
					'region' => '-',
					'zip'    => '-',
				)
			); // GPS requires at least 4 non empty location fields.
			if ( ! ( is_array( $gps_data ) && ! empty( $gps_data['latitude'] ) && ! empty( $gps_data['longitude'] ) ) ) {
				$street   = explode( ',', $fields['street'] );
				$gps_data = \GeoDir_Admin_Import_Export::get_post_gps_from_address(
					array(
						'street' => trim( $street[0] ),
						'city'   => $zip,
						'region' => '-',
						'zip'    => '-',
					)
				);
			}

			if ( ( is_array( $gps_data ) && ! empty( $gps_data['latitude'] ) && ! empty( $gps_data['longitude'] ) ) ) {
				$listing['latitude']  = $gps_data['latitude'];
				$listing['longitude'] = $gps_data['longitude'];
			}
		}

		if ( $subscription && class_exists( 'GeoDir_Pricing_Package' ) ) {
			// paid to not resolved to free package.
			$package = $this->get_existing_package( $post_type, $subscription->fee_id, false );

			if ( $package ) {
				$listing['package_id'] = $package->id;
			}
		}

		if ( ! empty( $images ) ) {
			$listing['post_images'] = $images;
		}

		if ( $this->is_test_mode() ) {
			return GeoDir_Converter_Importer::IMPORT_STATUS_SUCCESS;
		}

		// Delete existing media if updating.
		if ( $is_update ) {
			GeoDir_Media::delete_files( (int) $gd_post_id, 'post_images' );
		}

		// Insert or update post.
		if ( $is_update ) {
			$listing['ID'] = absint( $gd_post_id );
			$gd_post_id    = wp_update_post( $listing, true );
		} else {
			$gd_post_id = wp_insert_post( $listing, true );
		}

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

		return $is_update ? GeoDir_Converter_Importer::IMPORT_STATUS_SKIPPED : GeoDir_Converter_Importer::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Convert BDP field to GD field.
	 *
	 * @since 2.0.2
	 * @param string $key       The field key.
	 * @param array  $field     The BDP field data.
	 * @param string $post_type The post type.
	 * @param array  $package_ids   The package data.
	 * @return array|false The GD field data or false if conversion fails.
	 */
	private function prepare_single_field( $key, $field, $post_type, $package_ids = array() ) {
		$field        = $this->normalize_bdp_field( $field );
		$gd_field_key = $this->get_gd_field_key( $key );
		$field_type   = $this->get_gd_field_type( $field['field_type'] );
		$gd_field     = geodir_get_field_infoby( 'htmlvar_name', $gd_field_key, $post_type );

		if ( $gd_field ) {
			$gd_field['field_id'] = (int) $gd_field['id'];
			unset( $gd_field['id'] );
		} else {
			$gd_field = array(
				'post_type'     => $post_type,
				'data_type'     => 'TEXT',
				'field_type'    => $field_type,
				'htmlvar_name'  => $gd_field_key,
				'is_active'     => '1',
				'option_values' => '',
				'is_default'    => '0',
			);

			if ( 'checkbox' === $field_type ) {
				$gd_field['data_type'] = 'TINYINT';
			}
		}

		$gd_field = array_merge(
			$gd_field,
			array(
				'admin_title'       => $field['label'],
				'frontend_desc'     => $field['description'],
				'placeholder_value' => $field['placeholder'],
				'frontend_title'    => $field['label'],
				'default_value'     => $field['default'],
				'for_admin_use'     => in_array( 'private', $field['display_flags'], true ) || $gd_field_key == 'bdp_id' ? 1 : 0,
				'is_required'       => in_array( 'required', $field['validators'], true ) ? 1 : 0,
				'show_in'           => ( 'listing_title' === $key ) ? '[owntab],[detail],[mapbubble]' : '[owntab],[detail]',
				'show_on_pkg'       => $package_ids,
				'clabels'           => $field['label'],
			)
		);

		if ( 'image' === $field['field_type'] ) {
			$gd_field['extra'] = array(
				'gd_file_types' => geodir_image_extensions(),
				'file_limit'    => 1,
			);
		}

		if ( isset( $field['field_data']['options'] ) && ! empty( $field['field_data']['options'] ) && is_array( $field['field_data']['options'] ) ) {
			$gd_field['option_values'] = implode( ',', $field['field_data']['options'] );
		}

		return $gd_field;
	}

	/**
	 * Process form fields and extract values from post meta.
	 *
	 * @since 2.0.2
	 *
	 * @param array $post_meta The post meta data keyed by meta key.
	 * @return array The processed fields as key-value pairs mapped to GeoDirectory field keys.
	 */
	private function process_form_fields( $post_meta ) {
		$form_fields = $this->get_form_fields();
		$fields      = array();

		foreach ( $form_fields as $field ) {
			if ( isset( $field['id'], $post_meta[ '_wpbdp[fields][' . (int) $field['id'] . ']' ] ) ) {
				$meta_key = '_wpbdp[fields][' . (int) $field['id'] . ']';
				$key      = $this->get_gd_field_key( $field['shortname'] );
				$value    = $post_meta[ $meta_key ][0];

				// Unserialize a value if it's serialized.
				if ( is_string( $value ) && is_serialized( $value ) ) {
					$value = maybe_unserialize( $value );
				}

				// Process a field value based on its type.
				if ( in_array( $field['field_type'], array( 'checkbox', 'radio' ), true ) ) {
					$value = is_string( $value ) ? explode( "\t", $value ) : $value;
				}

				if ( 'image' === $field['field_type'] ) {
					$images = array();

					// Ensure $value is always an array.
					foreach ( $value as $attachment ) {
						if ( ! is_array( $attachment ) ) {
							$images = array(
								$value,
							);
						}
					}

					$images = array_map(
						function ( $image ) {
							return array(
								'id'      => absint( $image[0] ),
								'caption' => isset( $image[1] ) ? $image[1] : '',
							);
						},
						$images
					);

					$value = $this->format_images_data( $images );
				}

				if ( 'website' === $key && is_array( $value ) ) {
					$value = isset( $value[0] ) ? $value[0] : '';
				}

				if ( $key == 'address' ) {
					$key = 'street';
				}

				$fields[ $key ] = $value;
			}
		}

		return $fields;
	}

	/**
	 * Get the corresponding GD field key for a given shortname.
	 *
	 * @since 2.0.2
	 * @param string $shortname The field shortname.
	 * @return string The mapped field key or the original shortname if no match is found.
	 */
	private function get_gd_field_key( $shortname ) {
		$fields_map = array(
			'listing_title'     => 'post_title',
			'short_description' => 'post_excerpt',
			'description'       => 'post_content',
			'listing_category'  => 'post_category',
			'listing_tags'      => 'post_tags',
			'xtwitter'          => 'twitter',
			'zip_code'          => 'zip',
		);

		return isset( $fields_map[ $shortname ] ) ? $fields_map[ $shortname ] : $shortname;
	}

	/**
	 * Get the corresponding GD field type.
	 *
	 * @since 2.0.2
	 * @param string $bdp_field_type The BDP field type.
	 * @return string The mapped field type.
	 */
	private function get_gd_field_type( $bdp_field_type ) {
		$field_type_map = array(
			'textfield'       => 'text',
			'phone_number'    => 'phone',
			'date'            => 'datepicker',
			'image'           => 'file',
			'social-twitter'  => 'text',
			'social-facebook' => 'text',
			'social-linkedin' => 'text',
			'social-network'  => 'text',
		);

		return isset( $field_type_map[ $bdp_field_type ] ) ? $field_type_map[ $bdp_field_type ] : $bdp_field_type;
	}

	/**
	 * Normalize and set default values for a given BDP field.
	 *
	 * @since 2.0.2
	 *
	 * @param array $field Field values to normalize.
	 * @return array Normalized field values merged with defaults.
	 */
	private function normalize_bdp_field( $field ) {
		$defaults = array(
			'shortname'     => '',
			'label'         => '',
			'tag'           => '',
			'description'   => '',
			'placeholder'   => '',
			'default'       => '',
			'field_type'    => 'textfield',
			'association'   => 'meta',
			'weight'        => 0,
			'validators'    => array(),
			'display_flags' => array(),
			'field_data'    => array(),
		);

		return wp_parse_args( $field, $defaults );
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
	 * Get existing package based on BDP package ID or find a suitable free package.
	 *
	 * @since 2.0.2
	 *
	 * @param string $post_type     The post type associated with the package.
	 * @param int    $bdp_id        The Business Directory Plugin (BDP) package ID.
	 * @param bool   $free_fallback Optional. Whether to fallback to a free package if no match is found. Default true.
	 * @return object|null The existing package object if found, or null otherwise.
	 */
	private function get_existing_package( $post_type, $bdp_id, $free_fallback = true ) {
		global $wpdb;

		// Fetch the package by BDP ID.
		$query = $wpdb->prepare(
			'SELECT p.*, g.* 
            FROM ' . GEODIR_PRICING_PACKAGES_TABLE . ' AS p
            INNER JOIN ' . GEODIR_PRICING_PACKAGE_META_TABLE . ' AS g ON p.ID = g.package_id
            WHERE p.post_type = %s AND g.meta_key = %s AND g.meta_value = %d
            LIMIT 1',
			$post_type,
			'_bdp_package_id',
			(int) $bdp_id
		);

		$existing_package = $wpdb->get_row( $query );

		// If not found, attempt to retrieve a free package.
		if ( ! $existing_package && $free_fallback ) {
			$query_free = $wpdb->prepare(
				'SELECT * FROM ' . GEODIR_PRICING_PACKAGES_TABLE . ' 
                WHERE post_type = %s AND amount = 0 AND status = 1
                ORDER BY display_order ASC, ID ASC
                LIMIT 1',
				$post_type
			);

			$existing_package = $wpdb->get_row( $query_free );
		}

		return $existing_package;
	}

	/**
	 * Retrieves the current post's categories using a custom query.
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
	 * @param int|WP_Post $post (default: null) The post object or ID.
	 * @return string Gallery images string.
	 */
	private function get_listing_images( $post ) {
		$image_ids = get_post_meta( $post->ID, '_wpbdp[images]', true );

		// Ensure $image_ids is always an array.
		$image_ids = is_array( $image_ids ) ? $image_ids : array( $image_ids );

		$images = array_map(
			function ( $id ) {
				$id = absint( $id );

				return array(
					'id'      => $id,
					'caption' => get_post_meta( $id, '_wpbdp_image_caption', true ),
					'weight'  => absint( get_post_meta( $id, '_wpbdp_image_weight', true ) ),
				);
			},
			$image_ids
		);

		return $this->format_images_data( $images );
	}

	/**
	 * Get the subscription for a listing.
	 *
	 * @since 2.0.2
	 *
	 * @param int $post_id The post ID.
	 * @return object|null The subscription row object or null if not found.
	 */
	private function get_subscription( $post_id ) {
		global $wpdb;

		$subscription = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpbdp_listings WHERE listing_id = % d", $post_id ) );

		return $subscription;
	}

	/**
	 * Retrieves the form fields from Business Directory.
	 *
	 * @since 2.0.2
	 *
	 * @param bool $count Optional. Whether to return only the count of form fields. Default false.
	 * @return array|int The form fields array (with the predefined BDP ID field added) or the count.
	 */
	private function get_form_fields( $count = false ) {
		global $wpdb;

		if ( $count ) {
			$field_count = (int) $wpdb->get_var( "SELECT COUNT( * ) FROM {$wpdb->prefix}wpbdp_form_fields" );
			return $field_count + 1; // Add 1 for the predefined 'bdp_id' field.
		}

		$form_fields = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}wpbdp_form_fields ORDER BY weight DESC",
			ARRAY_A
		);

		// Add the predefined BDP ID field to the beginning of the fields.
		array_unshift(
			$form_fields,
			$this->normalize_bdp_field(
				array(
					'shortname'   => 'bdp_id',
					'label'       => __( 'BDP ID', 'geodir-converter' ),
					'description' => __( 'Business Directory Plugin ID', 'geodir-converter' ),
					'placeholder' => __( 'BDP ID', 'geodir-converter' ),
					'field_type'  => 'textfield',
					'association' => 'meta',
				)
			)
		);

		return $form_fields;
	}

	/**
	 * Retrieves the package plans from Business Directory.
	 *
	 * @since 2.0.2
	 *
	 * @param bool $count Optional. Whether to return only the count of plans. Default false.
	 * @return array|int The package plans array or the count when $count is true.
	 */
	private function get_plans( $count = false ) {
		global $wpdb;

		if ( $count ) {
			return (int) $wpdb->get_var(
				"SELECT COUNT( * ) FROM {$wpdb->prefix}wpbdp_plans"
			);
		}

		$plans = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}wpbdp_plans ORDER BY weight DESC",
			ARRAY_A
		);

		return $plans;
	}
}
