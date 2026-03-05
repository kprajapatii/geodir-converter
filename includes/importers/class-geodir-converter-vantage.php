<?php
/**
 * Vantage Converter Class.
 *
 * @since      2.0.2
 * @package    GeoDir_Converter
 * @version    2.0.2
 */

namespace GeoDir_Converter\Importers;

use WP_User;
use WP_Error;
use WP_Query;
use Exception;
use GeoDir_Media;
use WPInv_Invoice;
use GetPaid_Form_Item;
use GeoDir_Pricing_Package;

use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Main converter class for importing from Vantage.
 *
 * @since 2.0.2
 */
class GeoDir_Converter_Vantage extends GeoDir_Converter_Importer {
	/**
	 * Action identifier for parsing payments.
	 *
	 * @var string
	 */
	const ACTION_PARSE_PAYMENTS = 'parse_payments';

	/**
	 * Action identifier for importing payments.
	 *
	 * @var string
	 */
	const ACTION_IMPORT_PAYMENTS = 'import_payments';

	/**
	 * Post type identifier for listings.
	 *
	 * @var string
	 */
	private const POST_TYPE_LISTING = 'listing';

	/**
	 * Post type identifier for plans.
	 *
	 * @var string
	 */
	private const POST_TYPE_PLAN = 'listing-pricing-plan';

	/**
	 * Post type identifier for payments.
	 *
	 * @var string
	 */
	private const POST_TYPE_TRANSACTION = 'transaction';

	/**
	 * Taxonomy identifier for listing categories.
	 *
	 * @var string
	 */
	private const TAX_LISTING_CATEGORY = 'listing_category';

	/**
	 * Taxonomy identifier for listing tags.
	 *
	 * @var string
	 */
	private const TAX_LISTING_TAG = 'listing_tag';

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
	protected $importer_id = 'vantage';

	/**
	 * The import listing status ID.
	 *
	 * @var array
	 */
	protected $post_statuses = array( 'publish', 'expired', 'draft', 'deleted', 'pending' );

	/**
	 * Payment statuses.
	 *
	 * @var array
	 */
	protected $payment_statuses = array(
		'tr_pending'   => 'wpi-pending',
		'tr_failed'    => 'wpi-failed',
		'tr_completed' => 'publish',
		'tr_activated' => 'publish',
	);

	/**
	 * Batch size for processing items.
	 *
	 * @var int
	 */
	private $batch_size = 50;

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
		return __( 'Vantage', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer description.
	 */
	public function get_description() {
		return __( 'Import listings, events, users and invoices from your Vantage installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer icon URL.
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/vantage.png';
	}

	/**
	 * Get importer task action.
	 *
	 * @since 2.0.2
	 *
	 * @return string The first import action identifier.
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
			<h6 class="fs-base"><?php esc_html_e( 'Vantage Importer Settings', 'geodir-converter' ); ?></h6>
			
			<?php
			if ( ! defined( 'WPINV_VERSION' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'Invoicing', 'geodir-converter' ),
					'payments',
					esc_url( 'https://wordpress.org/plugins/invoicing' )
				);
			}

			if ( ! defined( 'GEODIR_PRICING_VERSION' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'GeoDirectory Pricing Manager', 'geodir-converter' ),
					'plans',
					esc_url( 'https://wpgeodirectory.com/downloads/pricing-manager/' )
				);
			}

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
	 * @return array|WP_Error Validated and sanitized settings or WP_Error on failure.
	 */
	public function validate_settings( array $settings, array $files = array() ) {
		$post_types = geodir_get_posttypes();
		$errors     = array();

		$settings['test_mode']    = ( isset( $settings['test_mode'] ) && ! empty( $settings['test_mode'] ) && $settings['test_mode'] != 'no' ) ? 'yes' : 'no';
		$settings['gd_post_type'] = isset( $settings['gd_post_type'] ) && ! empty( $settings['gd_post_type'] ) ? sanitize_text_field( $settings['gd_post_type'] ) : 'gd_place';

		if ( ! in_array( $settings['gd_post_type'], $post_types, true ) ) {
			$errors[] = esc_html__( 'The selected post type is invalid. Please choose a valid post type.', 'geodir-converter' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'invalid_import_settings', implode( '<br>', $errors ) );
		}

		return $settings;
	}

	/**
	 * Get next task.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task         The current task.
	 * @param bool  $reset_offset Whether to reset the offset.
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
			self::ACTION_IMPORT_PACKAGES,
			self::ACTION_IMPORT_FIELDS,
			self::ACTION_PARSE_LISTINGS,
			self::ACTION_PARSE_PAYMENTS,
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

		// Count plans.
		$total_items += (int) $this->count_plans();

		// Count fields.
		$fields       = $this->get_fields();
		$total_items += (int) count( $fields );

		// Count listings.
		$total_items += (int) $this->count_listings();

		// Count payments.
		$total_items += (int) $this->count_payments();

		$this->increase_imports_total( $total_items );
	}

	/**
	 * Import categories from Vantage to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task.
	 * @return array|false Result of the import operation or false if import is complete.
	 */
	public function task_import_categories( $task ) {
		global $wpdb;

		// Set total number of items to import.
		$this->set_import_total();

		// Log import started.
		$this->log( __( 'Categories: Import started.', 'geodir-converter' ) );

		if ( 0 === (int) wp_count_terms( self::TAX_LISTING_CATEGORY ) ) {
			$this->log( __( 'Categories: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task, true );
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
			return $this->next_task( $task, true );
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

		return $this->next_task( $task, true );
	}

	/**
	 * Import tags from Vantage to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Task details.
	 * @return array|false Updated task details or false if import is complete.
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
			return $this->next_task( $task, true );
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

		return $this->next_task( $task, true );
	}

	/**
	 * Import fields from Vantage to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 */
	public function task_import_fields( array $task ) {
		global $plugin_prefix;

		$this->log( __( 'Importing fields...', 'geodir-converter' ) );

		$imported  = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed    = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped   = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$updated   = isset( $task['updated'] ) ? absint( $task['updated'] ) : 0;
		$fields    = $this->get_fields();
		$post_type = $this->get_import_post_type();

		if ( empty( $fields ) ) {
			$this->log( __( 'No fields found for import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task, true );
		}

		$table       = $plugin_prefix . $post_type . '_detail';
		$package_ids = $this->get_package_ids( $post_type );

		// Fields to skip.
		$skip_keys = array( 'tax_input[listing_category]', 'tax_input[listing_tag]', '_app_media' );

		foreach ( $fields as $field ) {
			// Skip fields that shouldn't be imported.
			if ( in_array( $field['id'], $skip_keys, true ) ) {
				++$skipped;
				/* translators: %s: field label */
				$this->log( sprintf( __( 'Skipped field: %s', 'geodir-converter' ), $field['props']['label'] ), 'warning' );
				continue;
			}

			$gd_field = $this->prepare_single_field( $field['id'], $field, $post_type, $package_ids );

			// Skip fields that shouldn't be imported.
			if ( $this->should_skip_field( $gd_field['htmlvar_name'] ) ) {
				++$skipped;
				/* translators: %s: field label */
				$this->log( sprintf( __( 'Skipped field: %s', 'geodir-converter' ), $field['props']['label'] ), 'warning' );
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
				/* translators: %s: field label */
			$this->log( sprintf( __( 'Failed to import field: %s', 'geodir-converter' ), $field['props']['label'] ), 'error' );
			}
		}

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_skipped_imports( $skipped );
		$this->increase_failed_imports( $failed );

		$this->log(
			sprintf(
				/* translators: %1$d: imported count, %2$d: updated count, %3$d: skipped count, %4$d: failed count */
				__( 'Fields import completed: %1$d imported, %2$d updated, %3$d skipped, %4$d failed.', 'geodir-converter' ),
				$imported,
				$updated,
				$skipped,
				$failed
			),
			'success'
		);

		return $this->next_task( $task, true );
	}

	/**
	 * Import packages from Vantage to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Updated task with the next action or false if import is complete.
	 */
	public function task_import_packages( array $task ) {
		global $wpdb;

		// Abort early if the payment manager plugin is not installed.
		if ( ! class_exists( 'GeoDir_Pricing_Package' ) ) {
			$this->log( __( 'Payment manager plugin is not active. Skipping plans...', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		// Set Pricing Manager cart option if WPINV is active.
		if ( defined( 'WPINV_VERSION' ) ) {
			geodir_update_option( 'pm_cart', 'invoicing' );
		}

		$offset      = isset( $task['offset'] ) ? (int) $task['offset'] : 0;
		$imported    = isset( $task['imported'] ) ? (int) $task['imported'] : 0;
		$failed      = isset( $task['failed'] ) ? (int) $task['failed'] : 0;
		$skipped     = isset( $task['skipped'] ) ? (int) $task['skipped'] : 0;
		$total_plans = isset( $task['total_plans'] ) ? (int) $task['total_plans'] : 0;
		$batch_size  = (int) $this->get_batch_size();
		$post_type   = $this->get_import_post_type();

		// Determine total listings count if not set.
		if ( ! isset( $task['total_plans'] ) ) {
			$total_plans         = $this->count_plans();
			$task['total_plans'] = $total_plans;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			$this->log( __( 'Starting plans import process...', 'geodir-converter' ) );
		}

		// Exit early if there are no plans to import.
		if ( 0 === $total_plans ) {
			$this->log( __( 'No plans found for import. Skipping process.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		// Disable cache addition.
		wp_suspend_cache_addition( true );

		$plans = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT ID
                FROM {$wpdb->posts}
                WHERE post_type = %s
                LIMIT %d OFFSET %d",
				array( self::POST_TYPE_PLAN, $batch_size, $offset )
			)
		);

		if ( empty( $plans ) ) {
			$this->log( __( 'Finished importing plans.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		foreach ( $plans as $plan ) {
			$plan_title = $plan->post_title;
			$plan       = get_post( $plan->ID );
			if ( ! $plan ) {
				/* translators: %s: plan title */
			$this->log( sprintf( __( 'Failed to import plan: %s', 'geodir-converter' ), $plan_title ), 'error' );
				++$failed;
				continue;
			}

			$plan_id          = absint( $plan->ID );
			$plan_label       = $plan->post_title;
			$plan_description = $plan->post_content;

			// Retrieve all post meta data at once.
			$plan_meta = get_post_meta( $plan_id );
			$plan_meta = array_map(
				function ( $meta ) {
					return isset( $meta[0] ) ? $meta[0] : '';
				},
				$plan_meta
			);

			// Check if the plan already exists.
			$existing_package = $this->get_existing_package( $post_type, $plan_id, 0 === (float) $plan_meta['price'] );

			$package_data = array(
				'post_type'       => $post_type,
				'name'            => $plan_label,
				'title'           => $plan_label,
				'description'     => $plan_description,
				'fa_icon'         => '',
				'amount'          => (float) $plan_meta['price'],
				'time_interval'   => (int) $plan_meta['period'],
				'time_unit'       => $plan_meta['period_type'],
				'recurring'       => 'forced_recurring' === $plan_meta['recurring'] ? true : false,
				'recurring_limit' => 0,
				'trial'           => '',
				'trial_amount'    => '',
				'trial_interval'  => '',
				'trial_unit'      => '',
				'is_default'      => ( 'publish' === $plan->post_status && 1 === (int) $plan->menu_order ) ? 1 : 0,
				'display_order'   => (int) $plan->menu_order,
				'downgrade_pkg'   => 0,
				'post_status'     => 'pending',
				'status'          => 'publish' === $plan->post_status ? true : false,
			);

			// If existing package found, update ID before saving.
			if ( $existing_package ) {
				$package_data['id'] = absint( $existing_package->id );
			}

			if ( $this->is_test_mode() ) {
				$existing_package ? ++$skipped : ++$imported;
				continue;
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

				$existing_package ? ++$skipped : ++$imported;

				GeoDir_Pricing_Package::update_meta( $package_id, '_vantage_package_id', $plan_id );
			}
		}

		wp_suspend_cache_addition( false );

		$this->increase_succeed_imports( (int) $imported );
		$this->increase_skipped_imports( (int) $skipped );
		$this->increase_failed_imports( (int) $failed );

		$this->log(
			sprintf(
				/* translators: %1$d: imported count, %2$d: updated count, %3$d: failed count */
				__( 'Plans import completed: %1$d imported, %2$d updated, %3$d failed.', 'geodir-converter' ),
				$imported,
				$skipped,
				$failed
			),
			$failed ? 'warning' : 'success'
		);

		return $this->next_task( $task, true );
	}

	/**
	 * Get existing package based on Vantage package ID or find a suitable free package.
	 *
	 * @since 2.0.2
	 *
	 * @param string $post_type        The post type associated with the package.
	 * @param int    $vantage_plan_id  The Vantage package ID.
	 * @param bool   $free_fallback    Whether to fallback to a free package if no match is found.
	 * @return object|null The existing package object if found, or null otherwise.
	 */
	private function get_existing_package( $post_type, $vantage_plan_id, $free_fallback = true ) {
		global $wpdb;

		// Fetch the package by BDP ID.
		$query = $wpdb->prepare(
			'SELECT p.*, g.* 
            FROM ' . GEODIR_PRICING_PACKAGES_TABLE . ' AS p
            INNER JOIN ' . GEODIR_PRICING_PACKAGE_META_TABLE . ' AS g ON p.ID = g.package_id
            WHERE p.post_type = %s AND g.meta_key = %s AND g.meta_value = %d
            LIMIT 1',
			$post_type,
			'_vantage_package_id',
			(int) $vantage_plan_id
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
	 * Get standard and custom fields.
	 *
	 * @since 2.0.2
	 *
	 * @return array Array of standard and custom fields.
	 */
	private function get_fields() {
		$standard_fields = array(
			array(
				'id'       => 'vantage_id',
				'type'     => 'int',
				'priority' => 1,
				'props'    => array(
					'label'       => __( 'Vantage ID', 'geodir-converter' ),
					'description' => __( 'Original Vantage Listing ID.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'Vantage ID', 'geodir-converter' ),
					'icon'        => 'far fa-id-card',
				),
			),
			array(
				'id'       => 'email',
				'type'     => 'text',
				'priority' => 4,
				'props'    => array(
					'label'       => __( 'Email', 'geodir-converter' ),
					'description' => __( 'The email of the listing.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'Email', 'geodir-converter' ),
					'icon'        => 'far fa-envelope',
				),
			),
			array(
				'id'       => 'phone',
				'type'     => 'text',
				'priority' => 2,
				'props'    => array(
					'label'       => __( 'Phone', 'geodir-converter' ),
					'description' => __( 'The phone number of the listing.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'Phone', 'geodir-converter' ),
					'icon'        => 'fa-solid fa-phone',
				),
			),
			array(
				'id'       => 'website',
				'type'     => 'text',
				'priority' => 3,
				'props'    => array(
					'label'       => __( 'Website', 'geodir-converter' ),
					'description' => __( 'The website of the listing.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'Website', 'geodir-converter' ),
					'icon'        => 'fa-solid fa-globe',
				),
			),
			array(
				'id'       => 'facebook',
				'type'     => 'text',
				'priority' => 3,
				'props'    => array(
					'label'       => __( 'Facebook', 'geodir-converter' ),
					'description' => __( 'The Facebook page of the listing.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'Facebook', 'geodir-converter' ),
					'icon'        => 'fa-brands fa-facebook',
				),
			),
			array(
				'id'       => 'twitter',
				'type'     => 'text',
				'priority' => 3,
				'props'    => array(
					'label'       => __( 'Twitter', 'geodir-converter' ),
					'description' => __( 'The Twitter page of the listing.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'Twitter', 'geodir-converter' ),
					'icon'        => 'fa-brands fa-twitter',
				),
			),
			array(
				'id'       => 'instagram',
				'type'     => 'text',
				'priority' => 3,
				'props'    => array(
					'label'       => __( 'Instagram', 'geodir-converter' ),
					'description' => __( 'The Instagram page of the listing.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'Instagram', 'geodir-converter' ),
					'icon'        => 'fa-brands fa-instagram',
				),
			),
			array(
				'id'       => 'youtube',
				'type'     => 'text',
				'priority' => 3,
				'props'    => array(
					'label'       => __( 'YouTube', 'geodir-converter' ),
					'description' => __( 'The YouTube page of the listing.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'YouTube', 'geodir-converter' ),
					'icon'        => 'fa-brands fa-youtube',
				),
			),
			array(
				'id'       => 'pinterest',
				'type'     => 'text',
				'priority' => 3,
				'props'    => array(
					'label'       => __( 'Pinterest', 'geodir-converter' ),
					'description' => __( 'The Pinterest page of the listing.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'Pinterest', 'geodir-converter' ),
					'icon'        => 'fa-brands fa-pinterest',
				),
			),
			array(
				'id'       => 'linkedin',
				'type'     => 'text',
				'priority' => 3,
				'props'    => array(
					'label'       => __( 'LinkedIn', 'geodir-converter' ),
					'description' => __( 'The LinkedIn page of the listing.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'LinkedIn', 'geodir-converter' ),
					'icon'        => 'fa-brands fa-linkedin',
				),
			),
			array(
				'id'       => 'featured',
				'type'     => 'checkbox',
				'priority' => 18,
				'props'    => array(
					'label'       => __( 'Is Featured?', 'geodir-converter' ),
					'frontend'    => __( 'Is Featured?', 'geodirectory' ),
					'description' => __( 'Mark listing as a featured.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'Is Featured?', 'geodir-converter' ),
					'icon'        => 'fas fa-certificate',
				),
			),
			array(
				'id'       => 'claimed',
				'type'     => 'checkbox',
				'priority' => 19,
				'props'    => array(
					'label'       => __( 'Is Claimed', 'geodir-converter' ),
					'frontend'    => __( 'Business Owner/Associate?', 'geodir-converter' ),
					'description' => __( 'Mark listing as a claimed.', 'geodir-converter' ),
					'required'    => false,
					'placeholder' => __( 'Is Claimed', 'geodir-converter' ),
					'icon'        => 'far fa-check',
				),
			),
		);

		$vantage_post_type = self::POST_TYPE_LISTING;
		$options           = (array) get_option( "app_{$vantage_post_type}_options", array() );
		$form_fields       = isset( $options['app_form'] ) ? (array) $options['app_form'] : array();
		$fields            = array_merge( $standard_fields, $form_fields );

		return $fields;
	}

	/**
	 * Convert Vantage field to GeoDirectory field.
	 *
	 * @since 2.0.2
	 *
	 * @param string $key         The field key.
	 * @param array  $field       The Vantage field data.
	 * @param string $post_type   The post type.
	 * @param array  $package_ids The package IDs.
	 * @return array The GeoDirectory field data.
	 */
	private function prepare_single_field( $key, $field, $post_type, $package_ids = array() ) {
		$field         = $this->normalize_vantage_field( $field );
		$gd_field_key  = $this->map_field_key( $key );
		$gd_field_type = $this->map_field_type( $field['type'] );
		$gd_data_type  = $this->map_data_type( $field['type'] );
		$gd_field      = geodir_get_field_infoby( 'htmlvar_name', $gd_field_key, $post_type );
		$props         = isset( $field['props'] ) ? (array) $field['props'] : array();

		if ( $gd_field ) {
			$gd_field['field_id'] = (int) $gd_field['id'];
			unset( $gd_field['id'] );
		} else {
			$gd_field = array(
				'post_type'     => $post_type,
				'data_type'     => $gd_data_type,
				'field_type'    => $gd_field_type,
				'htmlvar_name'  => $gd_field_key,
				'is_active'     => '1',
				'option_values' => '',
				'is_default'    => '0',
			);

			if ( 'checkbox' === $gd_field_type ) {
				$gd_field['data_type'] = 'TINYINT';
			}
		}

		$gd_field = array_merge(
			$gd_field,
			array(
				'admin_title'       => isset( $props['label'] ) ? $props['label'] : '',
				'frontend_desc'     => isset( $props['tip'] ) ? $props['tip'] : '',
				'placeholder_value' => isset( $props['placeholder'] ) ? $props['placeholder'] : '',
				'frontend_title'    => isset( $props['label'] ) ? $props['label'] : '',
				'default_value'     => '',
				'for_admin_use'     => 0,
				'is_required'       => isset( $props['required'] ) && 1 === (int) $props['required'] ? 1 : 0,
				'show_in'           => ( 'listing_title' === $key ) ? '[owntab],[detail],[mapbubble]' : '[owntab],[detail]',
				'show_on_pkg'       => $package_ids,
				'clabels'           => isset( $props['label'] ) ? $props['label'] : '',
				'field_icon'        => isset( $props['icon'] ) ? $props['icon'] : '',
			)
		);

		// Add file field extra data if available.
		if ( 'file' === $field['type'] ) {
			$gd_field['extra'] = array(
				'gd_file_types' => geodir_image_extensions(),
				'file_limit'    => 1,
			);
		}

		// Add options if available.
		if ( isset( $props['options'] ) && ! empty( $props['options'] ) && is_array( $props['options'] ) ) {
			$option_values = array();
			foreach ( $props['options'] as $option ) {
				$option_values[] = $option['value'];
			}

			$gd_field['option_values'] = implode( ',', $option_values );
		}

		return $gd_field;
	}

	/**
	 * Get the corresponding GD field key for a given shortname.
	 *
	 * @since 2.0.2
	 *
	 * @param string $shortname The field shortname.
	 * @return string The mapped field key or the original shortname if no match is found.
	 */
	private function map_field_key( $shortname ) {
		$fields_map = array(
			'listing_title'     => 'post_title',
			'short_description' => 'post_excerpt',
			'description'       => 'post_content',
			'listing_category'  => 'post_category',
			'listing_tags'      => 'post_tags',
			'zip_code'          => 'zip',
		);

		return isset( $fields_map[ $shortname ] ) ? $fields_map[ $shortname ] : $shortname;
	}

	/**
	 * Map Vantage field type to GeoDirectory field type.
	 *
	 * @since 2.0.2
	 *
	 * @param string $field_type The Vantage field type.
	 * @return string The GeoDirectory field type.
	 */
	private function map_field_type( $field_type ) {
		switch ( $field_type ) {
			case 'input_text':
			case 'email':
				return 'text';
			case 'textarea':
				return 'textarea';
			case 'file':
				return 'file';
			case 'select':
				return 'select';
			case 'url':
				return 'url';
			case 'radio':
				return 'radio';
			case 'checkbox':
				return 'checkbox';
			case 'number':
				return 'number';
			default:
				return 'text';
		}
	}

	/**
	 * Map Vantage field type to GeoDirectory data type.
	 *
	 * @since 2.0.2
	 *
	 * @param string $field_type The Vantage field type.
	 * @return string The GeoDirectory data type.
	 */
	private function map_data_type( $field_type ) {
		switch ( $field_type ) {
			case 'input_text':
			case 'textarea':
			case 'url':
			case 'select':
			case 'radio':
				return 'TEXT';
			case 'checkbox':
				return 'TINYINT';
			case 'number':
				return 'INT';
			default:
				return 'VARCHAR';
		}
	}

	/**
	 * Normalize and set default values for a given field.
	 *
	 * @since 2.0.2
	 *
	 * @param array $field Field values to normalize.
	 * @return array Normalized field values.
	 */
	private function normalize_vantage_field( $field ) {
		$defaults = array(
			'id'    => '',
			'type'  => 'input_text',
			'props' => array(
				'required'    => 0,
				'label'       => '',
				'tip'         => '',
				'disable'     => 0,
				'placeholder' => '',
				'tax'         => '',
				'options'     => array(),
				'extensions'  => '',
				'file_limit'  => 5,
				'embed_limit' => 0,
				'file_size'   => 0,
				'icon'        => '',
			),
		);

		return wp_parse_args( $field, $defaults );
	}

	/**
	 * Parse listings from Vantage and queue them for import.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the parsing operation or false if complete.
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

		$listings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT ID FROM {$wpdb->posts}
                WHERE post_type = %s
                LIMIT %d OFFSET %d",
				array( self::POST_TYPE_LISTING, $batch_size, $offset )
			)
		);

		if ( empty( $listings ) ) {
			$this->log( __( 'Parsing process completed. No more listings found.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		// Batch the tasks.
		$batched_tasks = array_chunk( $listings, $this->batch_size );
		$import_tasks  = array();
		foreach ( $batched_tasks as $batch ) {
			$import_tasks[] = array(
				'action'   => self::ACTION_IMPORT_LISTINGS,
				'post_ids' => wp_list_pluck( $batch, 'ID' ),
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
	 * Import listings from Vantage to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task The task to import.
	 * @return false Always returns false to indicate task completion.
	 */
	public function task_import_listings( $task ) {
		$post_ids = isset( $task['post_ids'] ) && ! empty( $task['post_ids'] ) ? (array) $task['post_ids'] : array();
		$mapping  = (array) $this->options_handler->get_option_no_cache( 'listings_mapping', array() );

		if ( empty( $post_ids ) ) {
			return false;
		}

		$listings = get_posts(
			array(
				'post__in'    => $post_ids,
				'post_type'   => self::POST_TYPE_LISTING,
				'numberposts' => -1,
				'post_status' => $this->post_statuses,
			)
		);

		if ( empty( $listings ) ) {
			return false;
		}

		foreach ( $listings as $listing ) {
			$title  = $listing->post_title;
			$result = $this->import_single_listing( $listing );

			$this->process_import_result( $result['status'], 'listing', $title, $listing->ID );

			// Update listings mapping.
			if ( in_array( $result['status'], array( self::IMPORT_STATUS_SUCCESS, self::IMPORT_STATUS_UPDATED ), true ) ) {
				$mapping[ (int) $listing->ID ] = array(
					'gd_post_id'    => $result['gd_post_id'],
					'gd_package_id' => $result['gd_package_id'],
				);
			}
		}

		// Update listings mapping.
		$this->options_handler->update_option( 'listings_mapping', $mapping );

		$this->flush_failed_items();

		return false;
	}

	/**
	 * Convert a single Vantage listing to GeoDirectory format.
	 *
	 * @since 2.0.2
	 *
	 * @param object $post The post to convert.
	 * @return array Import result with status and optional post/package IDs.
	 */
	private function import_single_listing( $post ) {
		// Check if the post has already been imported.
		$post_type         = $this->get_import_post_type();
		$is_test           = $this->is_test_mode();
		$default_wp_author = (int) $this->get_import_setting( 'wp_author_id', 1 );
		$gd_post_id        = ! $is_test ? (int) $this->get_gd_listing_id( $post->ID, 'vantage_id', $post_type ) : false;
		$is_update         = ! empty( $gd_post_id );

		// Get post meta.
		$post_meta = $this->get_post_meta( $post->ID );

		// Get categories and tags.
		$categories = $this->get_categories( $post->ID, self::TAX_LISTING_CATEGORY );
		$tags       = $this->get_categories( $post->ID, self::TAX_LISTING_TAG, 'names' );

		// Location & Address.
		$location = $this->get_default_location();
		$coord    = $this->get_listing_coordinates( $post->ID );
		$address  = isset( $post_meta['address'] ) && ! empty( $post_meta['address'] ) ? $post_meta['address'] : '';

		$has_coordinates = isset( $coord->lat, $coord->lng ) && ! empty( $coord->lat ) && ! empty( $coord->lng );

		if ( $has_coordinates ) {
			$location['latitude']  = $coord->lat;
			$location['longitude'] = $coord->lng;
			$location = $this->geocode_location( $coord->lat, $coord->lng, $location, $post->ID );

			if ( isset( $location['address'] ) && ! empty( $location['address'] ) ) {
				$address = $location['address'];
			}
		} else {
			$address               = isset( $post_meta['geo_street'] ) && ! empty( $post_meta['geo_street'] ) ? $post_meta['geo_street'] : $address;
			$location['city']      = isset( $post_meta['geo_city'] ) && ! empty( $post_meta['geo_city'] ) ? $post_meta['geo_city'] : $location['city'];
			$location['region']    = isset( $post_meta['geo_state_long'] ) && ! empty( $post_meta['geo_state_long'] ) ? $post_meta['geo_state_long'] : $location['region'];
			$location['country']   = isset( $post_meta['geo_country_long'] ) && ! empty( $post_meta['geo_country_long'] ) ? $post_meta['geo_country_long'] : $location['country'];
			$location['zip']       = isset( $post_meta['geo_postal_code'] ) && ! empty( $post_meta['geo_postal_code'] ) ? $post_meta['geo_postal_code'] : '';
		}

		// Post status normalization.
		switch ( $post->post_status ) {
			case 'expired':
				$gd_post_status = 'gd-expired';
				break;
			case 'deleted':
				$gd_post_status = 'gd-closed';
				break;
			default:
				$gd_post_status = $post->post_status;
		}

		$wp_author_id = $post->post_author ? (int) $post->post_author : $default_wp_author;

		// Prepare the listing data.
		$listing = array(
			// Standard WP Fields.
			'post_author'           => $wp_author_id,
			'post_title'            => $post->post_title,
			'post_content'          => $post->post_content,
			'post_content_filtered' => $post->post_content_filtered,
			'post_excerpt'          => $post->post_excerpt,
			'post_status'           => $gd_post_status,
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
			'default_category'      => ! empty( $categories ) ? $categories[0] : 0,
			'featured_image'        => $this->get_featured_image( $post->ID ),
			'submit_ip'             => '',
			'overall_rating'        => 0,
			'rating_count'          => 0,

			'street'                => $address,
			'street2'               => '',
			'city'                  => isset( $location['city'] ) ? $location['city'] : '',
			'region'                => isset( $location['region'] ) ? $location['region'] : '',
			'country'               => isset( $location['country'] ) ? $location['country'] : '',
			'zip'                   => isset( $location['zip'] ) ? $location['zip'] : '',
			'latitude'              => isset( $location['latitude'] ) ? $location['latitude'] : '',
			'longitude'             => isset( $location['longitude'] ) ? $location['longitude'] : '',
			'mapview'               => '',
			'mapzoom'               => '',

			// Vantage standard fields.
			'vantage_id'            => $post->ID,
			'phone'                 => isset( $post_meta['phone'] ) ? $post_meta['phone'] : '',
			'website'               => isset( $post_meta['website'] ) ? $post_meta['website'] : '',
			'email'                 => isset( $post_meta['email'] ) ? $post_meta['email'] : '',
			'facebook'              => isset( $post_meta['facebook'] ) ? $post_meta['facebook'] : '',
			'twitter'               => isset( $post_meta['twitter'] ) ? $post_meta['twitter'] : '',
			'instagram'             => isset( $post_meta['instagram'] ) ? $post_meta['instagram'] : '',
			'youtube'               => isset( $post_meta['youtube'] ) ? $post_meta['youtube'] : '',
			'pinterest'             => isset( $post_meta['pinterest'] ) ? $post_meta['pinterest'] : '',
			'linkedin'              => isset( $post_meta['linkedin'] ) ? $post_meta['linkedin'] : '',
			'featured'              => isset( $post_meta['_listing-featured-home'] ) && 1 === (int) $post_meta['_listing-featured-home'] ? (bool) true : false,
		);

		// Process expiration date.
		if ( isset( $post_meta['_listing_duration'] ) && (int) $post_meta['_listing_duration'] > 0 ) {
			$duration               = (int) $post_meta['_listing_duration'];
			$listing['expire_date'] = gmdate( 'Y-m-d H:i:s', strtotime( $post->post_date . ' + ' . $duration . 'days' ) );
		}

		// Process package.
		$gd_package_id = 0;
		if ( class_exists( 'GeoDir_Pricing_Package' ) && isset( $post_meta['_app_plan_id'] ) && ! empty( $post_meta['_app_plan_id'] ) ) {
			$plan_id = absint( (int) filter_var( $post_meta['_app_plan_id'], FILTER_SANITIZE_NUMBER_INT ) );
			$package = $this->get_existing_package( $post_type, $plan_id, false );

			if ( $package ) {
				$gd_package_id         = (int) $package->id;
				$listing['package_id'] = $gd_package_id;
			}
		}

		if ( empty( $listing['package_id'] ) ) {
			$listing['package_id'] = geodir_get_post_package_id( $gd_post_id, $post_type );
		}

		// Handle test mode.
		if ( $is_test ) {
			return array(
				'status' => self::IMPORT_STATUS_SUCCESS,
			);
		}

		// Delete existing media if updating.
		if ( $is_update ) {
			GeoDir_Media::delete_files( (int) $gd_post_id, 'post_images' );
		}

		// Process gallery images.
		if ( isset( $post_meta['_app_media'] ) && ! empty( $post_meta['_app_media'] ) ) {
			$images = $this->get_gallery_images( $post_meta['_app_media'] );
			if ( ! empty( $images ) ) {
				$listing['post_images'] = $images;
			}
		}

		if ( empty( $listing['package_id'] ) ) {
			$listing['package_id'] = geodir_get_post_package_id( $gd_post_id, $post_type );
		}

		// Disable cache addition.
		wp_suspend_cache_addition( true );

		// Insert or update the post.
		$gd_post_id = $is_update
		? wp_update_post( array_merge( array( 'ID' => $gd_post_id ), $listing ), true )
		: wp_insert_post( $listing, true );

		// Handle errors during post insertion/update.
		if ( is_wp_error( $gd_post_id ) ) {
			$this->log( $gd_post_id->get_error_message() );
			return array(
				'status' => self::IMPORT_STATUS_FAILED,
			);
		}

		// Update custom fields.
		$fields = $this->process_form_fields( $post, $post_meta );
		if ( $fields && ( $gd_post = geodir_get_post_info( $gd_post_id ) ) ) {
			foreach ( $fields as $key => $val ) {
				if ( property_exists( $gd_post, $key ) ) {
					update_post_meta( $gd_post_id, $key, $val );
				}
			}
		}

		wp_suspend_cache_addition( false );

		$status = array_merge(
			array(
				'gd_post_id'    => (int) $gd_post_id,
				'gd_package_id' => (int) $gd_package_id,
				'status'        => $is_update ? self::IMPORT_STATUS_UPDATED : self::IMPORT_STATUS_SUCCESS,
			),
		);

		return $status;
	}

	/**
	 * Parse payments from Vantage and queue them for import.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Updated task with the next action or false if complete.
	 */
	public function task_parse_payments( array $task ) {
		global $wpdb;

		// Abort early if the invoices plugin is not installed.
		if ( ! class_exists( 'WPInv_Plugin' ) ) {
			$this->log( __( 'Invoices plugin is not active. Skipping invoices...', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		$offset         = isset( $task['offset'] ) ? (int) $task['offset'] : 0;
		$total_payments = isset( $task['total_payments'] ) ? (int) $task['total_payments'] : 0;
		$batch_size     = (int) $this->get_batch_size();

		// Determine total payments count if not set.
		if ( ! isset( $task['total_payments'] ) ) {
			$total_payments         = $this->count_payments();
			$task['total_payments'] = $total_payments;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			$this->log( __( 'Starting payments parsing process...', 'geodir-converter' ) );
		}

		// Exit early if there are no payments to import.
		if ( 0 === $total_payments ) {
			$this->log( __( 'No payments found for parsing. Skipping process.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		$payments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT ID FROM {$wpdb->posts}
                WHERE post_type = %s
                LIMIT %d OFFSET %d",
				array( self::POST_TYPE_TRANSACTION, $batch_size, $offset )
			)
		);

		if ( empty( $payments ) ) {
			$this->log( __( 'Finished parsing payments. No more payments found.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		// Batch the tasks.
		$batched_tasks = array_chunk( $payments, $this->batch_size );
		$import_tasks  = array();
		foreach ( $batched_tasks as $batch ) {
			$import_tasks[] = array(
				'action'      => self::ACTION_IMPORT_PAYMENTS,
				'payment_ids' => wp_list_pluck( $batch, 'ID' ),
			);
		}

		$this->background_process->add_import_tasks( $import_tasks );

		$complete = ( $offset >= $total_payments );
		if ( ! $complete ) {
			// Continue import with the next batch.
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		return $this->next_task( $task, true );
	}

	/**
	 * Import payments from Vantage to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task The task to import.
	 * @return false Always returns false to indicate task completion.
	 */
	public function task_import_payments( $task ) {
		global $wpdb;

		$payment_ids = isset( $task['payment_ids'] ) && ! empty( $task['payment_ids'] ) ? (array) $task['payment_ids'] : array();

		if ( empty( $payment_ids ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $payment_ids ), '%d' ) );

		$args   = $payment_ids;
		$args[] = self::POST_TYPE_TRANSACTION;

		$sql = call_user_func_array(
			array( $wpdb, 'prepare' ),
			array_merge(
				array(
					"SELECT * FROM {$wpdb->posts}
                    WHERE ID IN ($placeholders) AND post_type = %s",
				),
				$args
			)
		);

		$payments = $wpdb->get_results( $sql );

		if ( empty( $payments ) ) {
			return false;
		}

		$mapping = (array) $this->options_handler->get_option_no_cache( 'listings_mapping', array() );

		foreach ( $payments as $payment ) {
			$payment_id    = $payment->ID;
			$import_status = $this->import_single_payment( $payment, $mapping );

			switch ( $import_status ) {
				case self::IMPORT_STATUS_SUCCESS:
					$this->log( sprintf( self::LOG_TEMPLATE_SUCCESS, 'invoice', "INV-{$payment_id}" ), 'success' );
					$this->increase_succeed_imports( 1 );
					break;

				case self::IMPORT_STATUS_UPDATED:
					$this->log( sprintf( self::LOG_TEMPLATE_UPDATED, 'invoice', "INV-{$payment_id}" ), 'warning' );
					$this->increase_succeed_imports( 1 );
					break;

				case self::IMPORT_STATUS_SKIPPED:
					$this->log( sprintf( self::LOG_TEMPLATE_SKIPPED, 'invoice', "INV-{$payment_id}" ), 'warning' );
					$this->increase_skipped_imports( 1 );
					break;

				case self::IMPORT_STATUS_FAILED:
					$this->log( sprintf( self::LOG_TEMPLATE_FAILED, 'invoice', "INV-{$payment_id}" ), 'warning' );
					$this->increase_failed_imports( 1 );
					$this->record_failed_item( $payment_id, self::ACTION_IMPORT_PAYMENTS, 'invoice', "INV-{$payment_id}", sprintf( self::LOG_TEMPLATE_FAILED, 'invoice', "INV-{$payment_id}" ) );
					break;
			}
		}

		$this->flush_failed_items();

		return false;
	}

	/**
	 * Import a single payment.
	 *
	 * @since 2.0.2
	 *
	 * @param object $payment The payment to import.
	 * @param array  $mapping The listings mapping.
	 * @return int Import status constant (IMPORT_STATUS_SUCCESS, IMPORT_STATUS_UPDATED, or IMPORT_STATUS_FAILED).
	 */
	private function import_single_payment( $payment, $mapping ) {
		$vantage_options = (array) get_option( 'va_options', array() );
		$is_test         = $this->is_test_mode();
		$payment_id      = $payment->ID;
		$invoice_id      = ! $is_test ? $this->get_gd_post_id( $payment->ID, 'vantage_invoice_id' ) : false;
		$is_update       = ! empty( $invoice_id );

		// Bulk fetch and flatten post meta.
		$payment_meta_raw = get_post_meta( $payment_id );
		$payment_meta     = array();
		foreach ( $payment_meta_raw as $key => $value ) {
			$payment_meta[ $key ] = isset( $value[0] ) ? $value[0] : '';
		}

		$invoice_status = isset( $this->payment_statuses[ $payment->post_status ] ) ? $this->payment_statuses[ $payment->post_status ] : 'wpi-pending';
		$total_price    = isset( $payment_meta['total_price'] ) ? (float) $payment_meta['total_price'] : 0;
		$charged_tax    = 0;
		$gateway        = isset( $payment_meta['gateway'] ) ? strtolower( $payment_meta['gateway'] ) : '';

		// Get transaction ID.
		$transaction_id = isset( $payment_meta['paypal_subscription_id'] ) && 'paypal' === $gateway ? $payment_meta['paypal_subscription_id'] : '';

		// Tax calculation.
		$tax_charge  = isset( $vantage_options['tax_charge'] ) ? (float) $vantage_options['tax_charge'] : 0.0;
		$charged_tax = $tax_charge > 0 ? $total_price * ( $tax_charge / 100 ) : 0.0;
		$taxes       = $charged_tax > 0 ? array( __( 'Tax', 'geodir-converter' ) => array( 'initial_tax' => $charged_tax ) ) : array();

		$wpi_invoice = new WPInv_Invoice();
		$wpi_invoice->set_props(
			array(
				// Basic info.
				'post_type'      => 'wpi_invoice',
				'description'    => $payment->post_content,
				'status'         => $invoice_status,
				'created_via'    => 'geodir-converter',
				'date_created'   => $payment->post_date,
				'due_date'       => $payment->post_date,
				'date_completed' => $payment->post_date,

				// Payment info.
				'gateway'        => $gateway,
				'total'          => (float) $total_price,
				'subtotal'       => $total_price > 0 ? (float) $total_price - (float) $charged_tax : 0,
				'taxes'          => $taxes,

				// Billing details.
				'user_id'        => $payment->post_author,
				'user_ip'        => isset( $payment_meta['ip_address'] ) ? $payment_meta['ip_address'] : '',
				'currency'       => isset( $payment_meta['currency'] ) ? $payment_meta['currency'] : '',
				'transaction_id' => $transaction_id,
			)
		);

		$order_items = new WP_Query(
			array(
				'connected_type'  => 'order-connection',
				'connected_from'  => $payment->ID,
				'connected_query' => array( 'post_status' => 'any' ),
				'post_status'     => 'any',
				'nopaging'        => true,
			)
		);

		// Get the package ID.
		$gd_post_info = array();
		if ( ! empty( $order_items->posts ) ) {
			foreach ( $order_items->posts as $order_item ) {
				if ( self::POST_TYPE_LISTING === $order_item->post_type && isset( $mapping[ $order_item->ID ]['gd_post_id'] ) ) {
					$gd_post_info = $mapping[ $order_item->ID ];
					break;
				}
			}
		}

		if ( isset( $gd_post_info['gd_package_id'] ) && 0 !== (int) $gd_post_info['gd_package_id'] ) {
			$wpinv_item = wpinv_get_item_by( 'custom_id', (int) $gd_post_info['gd_package_id'], 'package' );
			if ( $wpinv_item ) {
				$item = new GetPaid_Form_Item( $wpinv_item->get_id() );
				$item->set_name( $wpinv_item->get_name() );
				$item->set_description( $wpinv_item->get_description() );
				$item->set_price( $wpinv_item->get_price() );
				$item->set_quantity( 1 );
				$wpi_invoice->add_item( $item );
			} else {
				$package = GeoDir_Pricing_Package::get_package( (int) $gd_post_info['gd_package_id'] );
				if ( $package ) {
					$item = new GetPaid_Form_Item( $package['id'] );
					$item->set_name( $package['title'] );
					$item->set_description( $package['description'] );
					$item->set_price( (float) $package['amount'] );
					$item->set_quantity( 1 );
					$wpi_invoice->add_item( $item );
				}
			}
		}

		// Handle test mode.
		if ( $is_test ) {
			return $is_update ? self::IMPORT_STATUS_UPDATED : self::IMPORT_STATUS_SUCCESS;
		}

		// Insert or update the post.
		if ( $is_update ) {
			$wpi_invoice->ID = absint( $invoice_id );
		}

		// Disable cache addition.
		wp_suspend_cache_addition( true );

		$wpi_invoice_id = $wpi_invoice->save();

		if ( is_wp_error( $wpi_invoice_id ) ) {
			$this->log( sprintf( self::LOG_TEMPLATE_FAILED, 'invoice', $wpi_invoice_id->get_error_message() ), 'error' );
			return self::IMPORT_STATUS_FAILED;
		}

		// Update post meta.
		update_post_meta( $wpi_invoice_id, 'vantage_invoice_id', $payment->ID );

		wp_suspend_cache_addition( false );

		return $is_update ? self::IMPORT_STATUS_UPDATED : self::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Get the featured image URL.
	 *
	 * @since 2.0.2
	 *
	 * @param int $post_id The post ID.
	 * @return string The featured image URL.
	 */
	private function get_featured_image( $post_id ) {
		$image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
		return isset( $image[0] ) ? esc_url( $image[0] ) : '';
	}

	/**
	 * Get gallery images from Vantage format.
	 *
	 * @since 2.0.2
	 *
	 * @param string|array $media The Vantage media format.
	 * @return string|array The images in the GeoDirectory format or empty string if none.
	 */
	private function get_gallery_images( $media ) {
		$image_ids = maybe_unserialize( $media );

		if ( ! is_array( $image_ids ) || empty( $image_ids ) ) {
			return '';
		}

		$images = array_map(
			function ( $id ) {
				if ( is_object( $id ) ) {
					$id = ! empty( $id->ID ) ? $id->ID : 0;
				}

				if ( is_object( $id ) ) {
					$id = ! empty( $id->ID ) ? $id->ID : 0;
				}

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
	 * Get the listing coordinates.
	 *
	 * @since 2.0.2
	 *
	 * @param int  $post_id          The post ID.
	 * @param bool $fallback_to_zero Whether to fallback to zero coordinates if none found.
	 * @return object|null The coordinates object with lat and lng properties.
	 */
	private function get_listing_coordinates( $post_id, $fallback_to_zero = true ) {
		global $wpdb;

		if ( ! isset( $wpdb->app_geodata ) ) {
			return (object) array(
				'lat' => 0,
				'lng' => 0,
			);
		}

		$coord = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->app_geodata WHERE post_id = %d", $post_id ) );

		if ( ! $coord && $fallback_to_zero ) {
			return (object) array(
				'lat' => 0,
				'lng' => 0,
			);
		}

		return $coord;
	}

	/**
	 * Process form fields and extract values from post meta.
	 *
	 * @since 2.0.2
	 *
	 * @param object $post      The post object.
	 * @param array  $post_meta The post meta data.
	 * @return array The processed fields.
	 */
	private function process_form_fields( $post, $post_meta ) {
		$form_fields = $this->get_fields();
		$fields      = array();

		foreach ( $form_fields as $field ) {
			if ( isset( $post_meta[ $field['id'] ] ) ) {
				$gd_key = $this->map_field_key( $field['id'] );
				$value  = $post_meta[ $field['id'] ];

				if ( $this->should_skip_field( $gd_key ) ) {
					continue;
				}

				// Unserialize a value if it's serialized.
				if ( is_string( $value ) && is_serialized( $value ) ) {
					$value = maybe_unserialize( $value );
				}

				$fields[ $gd_key ] = $value;
			}
		}

		return $fields;
	}

	/**
	 * Retrieves the current post's categories.
	 *
	 * @since 2.0.2
	 *
	 * @param int    $post_id     The post ID.
	 * @param string $taxonomy    The taxonomy to query for.
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
	 * Counts the number of listings.
	 *
	 * @since 2.0.2
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
	 * Counts the number of plans.
	 *
	 * @since 2.0.2
	 *
	 * @return int The number of plans.
	 */
	private function count_plans() {
		global $wpdb;

		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", self::POST_TYPE_PLAN ) );

		return $count;
	}

	/**
	 * Counts the number of payments.
	 *
	 * @since 2.0.2
	 *
	 * @return int The number of payments.
	 */
	private function count_payments() {
		global $wpdb;

		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", self::POST_TYPE_TRANSACTION ) );

		return $count;
	}
}
