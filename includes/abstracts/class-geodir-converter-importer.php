<?php
/**
 * Abstract Base Importer Class
 *
 * @package GeoDir_Converter
 * @subpackage Abstracts
 * @since 2.0.2
 */

namespace GeoDir_Converter\Abstracts;

use WP_Error;
use Geodir_Media;
use GeoDir_Admin_Taxonomies;
use GeoDir_Converter\GeoDir_Converter_Utils;
use GeoDir_Converter\GeoDir_Converter_Options_Handler;
use GeoDir_Converter\Importers\GeoDir_Converter_Background_Process;
use GeoDir_Converter\Traits\GeoDir_Converter_Trait_Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract Base Importer Class
 *
 * This class serves as a foundation for all specific importer classes.
 */
abstract class GeoDir_Converter_Importer {
	use GeoDir_Converter_Trait_Singleton;

	/**
	 * Number of records processed per batch.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 1000;

	/**
	 * Action identifier for importing categories.
	 *
	 * @var string
	 */
	const ACTION_IMPORT_CATEGORIES = 'import_categories';

	/**
	 * Action identifier for importing tags.
	 *
	 * @var string
	 */
	const ACTION_IMPORT_TAGS = 'import_tags';

	/**
	 * Action identifier for importing packages.
	 *
	 * @var string
	 */
	const ACTION_IMPORT_PACKAGES = 'import_packages';

	/**
	 * Action identifier for importing custom fields.
	 *
	 * @var string
	 */
	const ACTION_IMPORT_FIELDS = 'import_fields';

	/**
	 * Action identifier for parsing listings.
	 *
	 * @var string
	 */
	const ACTION_PARSE_LISTINGS = 'parse_listings';

	/**
	 * Action identifier for importing listings.
	 *
	 * @var string
	 */
	const ACTION_IMPORT_LISTINGS = 'import_listings';

	/**
	 * Import status indicating failure.
	 *
	 * @var int
	 */
	const IMPORT_STATUS_FAILED = 0;

	/**
	 * Import status indicating success.
	 *
	 * @var int
	 */
	const IMPORT_STATUS_SUCCESS = 1;

	/**
	 * Import status indicating the item was skipped.
	 *
	 * @var int
	 */
	const IMPORT_STATUS_SKIPPED = 2;

	/**
	 * Import status indicating the item was updated.
	 *
	 * @var int
	 */
	const IMPORT_STATUS_UPDATED = 3;

	/**
	 * Log template for started.
	 *
	 * @var string
	 */
	protected const LOG_TEMPLATE_STARTED = '%s: Import started.';

	/**
	 * Log template for success.
	 *
	 * @var string
	 */
	protected const LOG_TEMPLATE_SUCCESS = 'Imported %s: %s';

	/**
	 * Log template for skipped.
	 *
	 * @var string
	 */
	protected const LOG_TEMPLATE_SKIPPED = 'Skipped %s: %s';

	/**
	 * Log template for failed.
	 *
	 * @var string
	 */
	protected const LOG_TEMPLATE_FAILED = 'Failed to import %s: %s';

	/**
	 * Log template for updated.
	 *
	 * @var string
	 */
	protected const LOG_TEMPLATE_UPDATED = 'Updated %s: %s';

	/**
	 * Log template for finished.
	 *
	 * @var string
	 */
	protected const LOG_TEMPLATE_FINISHED = '%s: Import completed. Processed: %d, Imported: %d, Updated: %d, Skipped: %d, Failed: %d';

	/**
	 * The importer ID.
	 *
	 * @var string
	 */
	protected $importer_id;

	/**
	 * Buffer of failed items to be written in a single batch.
	 *
	 * @since 2.2.0
	 * @var array
	 */
	protected $pending_failed_items = array();

	/**
	 * Cached import settings for the current request.
	 *
	 * @var array|null
	 */
	private $cached_import_settings = null;

	/**
	 * Buffered stats increments to be flushed in a single write.
	 *
	 * @var array
	 */
	private $stats_buffer = array();

	/**
	 * Buffered log entries to be flushed in a single write.
	 *
	 * @var array
	 */
	private $logs_buffer = array();

	/**
	 * Background process instance.
	 *
	 * @var GeoDir_Converter_Background_Process
	 */
	public $background_process;

	/**
	 * Options handler instance.
	 *
	 * @var GeoDir_Converter_Options_Handler
	 */
	public $options_handler;

	/**
	 * Constructor.
	 *
	 * Initializes the options handler, background process, and registers the importer.
	 *
	 * @since 2.0.2
	 */
	public function __construct() {
		$this->options_handler    = new GeoDir_Converter_Options_Handler( "geodir_converter_{$this->importer_id}" );
		$this->background_process = new GeoDir_Converter_Background_Process( $this );

		add_filter( 'geodir_converter_importers', array( $this, 'register' ) );

		$this->init();
	}

	/**
	 * Initialize the importer.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	abstract protected function init();

	/**
	 * Register the importer.
	 *
	 * @since 2.0.2
	 *
	 * @param array $importers Existing importers.
	 * @return array Modified importers array.
	 */
	public function register( array $importers ) {
		$importers[ $this->importer_id ] = $this;

		return $importers;
	}

	/**
	 * Get the importer ID.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer identifier.
	 */
	public function get_id() {
		return $this->importer_id;
	}

	/**
	 * Get importer title.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer title.
	 */
	abstract public function get_title();

	/**
	 * Get importer description.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer description.
	 */
	abstract public function get_description();

	/**
	 * Get importer icon URL.
	 *
	 * @since 2.0.2
	 *
	 * @return string The URL to the importer icon image.
	 */
	abstract public function get_icon();

	/**
	 * Retrieves the action identifier for the importer task.
	 *
	 * This action is the first step executed by the WordPress background process
	 * and determines how the import job will be processed.
	 *
	 * @return string The action identifier associated with the importer.
	 */
	abstract public function get_action();

	/**
	 * Validate importer settings.
	 *
	 * @since 2.0.2
	 *
	 * @param array $settings The settings to validate.
	 * @param array $files    The files to validate.
	 * @return array|WP_Error Validated and sanitized settings, or WP_Error on failure.
	 */
	abstract public function validate_settings( array $settings, array $files = array() );

	/**
	 * Render importer settings.
	 *
	 * This method should be overridden by child classes to display custom settings.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function render_settings() {
		echo '<p>' . esc_html__( 'This importer does not have any custom settings.', 'geodir-converter' ) . '</p>';
	}

	/**
	 * Render a notice for a missing plugin.
	 *
	 * @since 2.0.2
	 *
	 * @param string $plugin_name The name of the plugin.
	 * @param string $import_type The type of data that won't be imported.
	 * @param string $plugin_url  The URL to download the plugin.
	 * @return void
	 */
	protected function render_plugin_notice( $plugin_name, $import_type, $plugin_url ) {
		aui()->alert(
			array(
				'type'    => 'info',
				/* translators: %1$s: plugin name */
				'heading' => wp_sprintf( esc_html__( 'The %1$s plugin is not active.', 'geodir-converter' ), $plugin_name ),
				'content' => wp_sprintf(
					/* translators: %1$s: import type, %2$s: opening link tag, %3$s: plugin name, %4$s: closing link tag */
					esc_html__(
						'%1$s will not be imported unless you install and activate the %2$s%3$s%4$s plugin first.',
						'geodir-converter'
					),
					esc_html( ucfirst( $import_type ) ),
					'<a href="' . esc_url( $plugin_url ) . '">',
					esc_html( $plugin_name ),
					'</a>'
				),
				'class'   => 'mb-3',
			),
			true
		);
	}

	/**
	 * Displays the logs associated with the process.
	 *
	 * @since 2.0.2
	 *
	 * @param array $logs An array containing log entries.
	 * @return void
	 */
	public function display_logs( array $logs = array() ) {
		echo '<ul class="geodir-converter-logs ps-0 pe-0">';
		foreach ( $logs as $log ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->log_to_html( $log );
		}
		echo '</ul>';
	}

	/**
	 * Displays the progress bar and stats summary.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function display_progress() {
		?>
		<div class="geodir-converter-progress mt-3 mb-3 d-none">
			<div class="progress">
				<div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
			</div>
			<div class="geodir-converter-progress-footer d-flex justify-content-between align-items-center mt-2">
				<div class="geodir-converter-stats-summary d-flex gap-3">
					<span class="geodir-converter-stat-succeed d-none">
						<strong class="geodir-converter-stat-succeed-count">0</strong> <?php esc_html_e( 'imported', 'geodir-converter' ); ?>
					</span>
					<span class="geodir-converter-stat-skipped d-none">
						<strong class="geodir-converter-stat-skipped-count">0</strong> <?php esc_html_e( 'skipped', 'geodir-converter' ); ?>
					</span>
					<span class="geodir-converter-stat-failed d-none">
						<strong class="geodir-converter-stat-failed-count">0</strong> <?php esc_html_e( 'failed', 'geodir-converter' ); ?>
					</span>
					<span class="geodir-converter-stat-total d-none">
						<strong class="geodir-converter-stat-total-count">0</strong> <?php esc_html_e( 'total', 'geodir-converter' ); ?>
					</span>
				</div>
				<span class="geodir-converter-elapsed-time d-none">
					<span class="geodir-converter-elapsed-value">00:00:00</span>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Display the post type selection dropdown.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function display_post_type_select() {
		$post_type_options = geodir_get_posttypes( 'options' );
		$gd_post_type      = $this->get_import_setting( 'gd_post_type' );
		$new_cpt_url       = add_query_arg(
			array(
				'page' => 'gd-settings',
				'tab'  => 'cpts',
			),
			admin_url( 'admin.php' )
		);

		// Remove gd_events from the list of post types. Events are imported separately.
		unset( $post_type_options['gd_events'] );

		aui()->select(
			array(
				'id'          => $this->importer_id . '_gd_post_type',
				'name'        => 'gd_post_type',
				'label'       => esc_html__( 'GD Listing Post Type', 'geodirectory' ),
				'label_type'  => 'top',
				'label_class' => 'font-weight-bold fw-bold',
				'value'       => $gd_post_type,
				'options'     => $post_type_options,
				'wrap_class'  => 'geodir-converter-post-type',
				'help_text'   => wp_kses_post(
					sprintf(
					/* translators: %s is the link to create a new post type */
						__( 'Choose the post type to assign imported listings to. <a href="%s" target="_blank">Create a new post type</a>.', 'geodir-converter' ),
						esc_url( $new_cpt_url )
					)
				),
			),
			true
		);
	}

	/**
	 * Display the author selection dropdown.
	 *
	 * @since 2.0.2
	 *
	 * @param bool $default_user Optional. Whether to show as default user selector. Default false.
	 * @return void
	 */
	public function display_author_select( $default_user = false ) {
		$wp_author_id = $this->get_import_setting( 'wp_author_id', '' );
		$wp_users     = wp_list_pluck( get_users(), 'display_name', 'ID' );

		$wp_users = array( '' => esc_html__( 'Current WordPress User', 'geodir-converter' ) ) + $wp_users;

		$label     = esc_html__( 'Assign Imported Listings to a WordPress User', 'geodir-converter' );
		$help_text = esc_html__( 'Select the WordPress user to assign imported listings to. Leave blank to use the default WordPress user.', 'geodir-converter' );

		if ( $default_user ) {
			$label     = esc_html__( 'Set Default WordPress User for Imported Listings', 'geodir-converter' );
			$help_text = esc_html__( 'Select the default WordPress user to assign imported listings to if the listing does not have an author.', 'geodir-converter' );
		}

		aui()->select(
			array(
				'id'          => $this->importer_id . '_wp_author_id',
				'name'        => 'wp_author_id',
				'select2'     => true,
				'label'       => $label,
				'label_class' => 'font-weight-bold fw-bold',
				'label_type'  => 'top',
				'value'       => $wp_author_id,
				'options'     => $wp_users,
				'help_text'   => $help_text,
			),
			true
		);
	}

	/**
	 * Display the test mode toggle checkbox.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function display_test_mode_checkbox() {
		$is_test_mode = (bool) $this->is_test_mode();

		aui()->input(
			array(
				'id'          => $this->importer_id . '_test_mode',
				'type'        => 'checkbox',
				'name'        => 'test_mode',
				'label_type'  => 'top',
				'label_class' => 'font-weight-bold fw-bold',
				'label'       => esc_html__( 'Test Mode', 'geodirectory' ),
				'checked'     => $is_test_mode,
				'value'       => 'yes',
				'switch'      => 'md',
				'help_text'   => esc_html__( 'Run a test import without importing any data.', 'geodirectory' ),
			),
			true
		);
	}

	/**
	 * Displays an error alert.
	 *
	 * @since 2.0.2
	 *
	 * @param string $message Optional. Error message to display. Defaults to empty.
	 */
	public function display_error_alert( $message = '' ) {
		$message = ! empty( $message ) ? esc_html( $message ) : '';
		?>
		<div class="alert alert-danger geodir-converter-error d-none d-flex align-items-start mt-3" role="alert">
			<i class="fas fa-exclamation-circle me-2 mt-1" style="font-size: 16px;"></i>
			<div>
			<?php
			echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the batch size for importing listings.
	 *
	 * @since 2.0.2
	 *
	 * @return int The batch size.
	 */
	public function get_batch_size() {
		return (int) apply_filters( "geodir_converter_{$this->importer_id}_batch_size", self::BATCH_SIZE );
	}

	/**
	 * Check if the importer is in test mode.
	 *
	 * @since 2.0.2
	 *
	 * @return bool True if in test mode, false otherwise.
	 */
	protected function is_test_mode() {
		return $this->get_import_setting( 'test_mode', 'no' ) === 'yes';
	}

	/**
	 * Get the import settings.
	 *
	 * @since 2.0.2
	 *
	 * @return array The import settings.
	 */
	protected function get_import_settings() {
		if ( null === $this->cached_import_settings ) {
			$this->cached_import_settings = (array) $this->options_handler->get_option_no_cache( 'import_settings', array() );
		}

		return $this->cached_import_settings;
	}

	/**
	 * Get a saved setting from import_settings option.
	 *
	 * @since 2.0.2
	 *
	 * @param string $key     The setting key to retrieve.
	 * @param mixed  $default Optional. Default value to return if the setting does not exist.
	 * @return mixed The setting value or default if not found.
	 */
	protected function get_import_setting( $key, $default = null ) {
		$settings = $this->get_import_settings();
		if ( isset( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}

		return $default;
	}

	/**
	 * Get the GeoDirectory post type for the import.
	 *
	 * @since 2.0.2
	 *
	 * @param string $default Optional. Default post type. Default 'gd_place'.
	 * @return string The GeoDirectory post type.
	 */
	protected function get_import_post_type( $default = 'gd_place' ) {
		$post_type = $this->get_import_setting( 'gd_post_type', $default );

		return $post_type;
	}

	/**
	 * Check if a field should be skipped during import.
	 *
	 * @since 2.0.2
	 *
	 * @param string $field_name The field name to check.
	 * @return bool True if the field should be skipped, false otherwise.
	 */
	protected function should_skip_field( $field_name ) {
		$preserved_keys = array(
			'ID',
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_excerpt',
			'post_status',
			'comment_status',
			'ping_status',
			'post_password',
			'post_name',
			'to_ping',
			'pinged',
			'post_modified',
			'post_modified_gmt',
			'post_content_filtered',
			'post_parent',
			'guid',
			'menu_order',
			'post_type',
			'post_mime_type',
			'comment_count',
			'geodir_search',
			'type',
			'near',
			'geo_lat',
			'geo_lon',
			'action',
			'security',
			'preview',
			'post_images',
			'featured_image',
			'address',
			'city',
			'region',
			'country',
			'neighbourhood',
			'zip',
			'latitude',
			'longitude',
			'mapview',
			'mapzoom',
			'street',
		);

		if ( in_array( $field_name, $preserved_keys, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the default location data.
	 *
	 * @since 2.0.2
	 *
	 * @return array {
	 *     The default location data.
	 *
	 *     @type string $city      City name.
	 *     @type string $region    Region name.
	 *     @type string $country   Country name.
	 *     @type string $zip       Zip code.
	 *     @type string $latitude  Latitude coordinate.
	 *     @type string $longitude Longitude coordinate.
	 * }
	 */
	protected function get_default_location() {
		global $geodirectory;

		$default_location = $geodirectory->location->get_default_location();

		return array(
			'city'      => isset( $default_location->city ) ? $default_location->city : '',
			'region'    => isset( $default_location->region ) ? $default_location->region : '',
			'country'   => isset( $default_location->country ) ? $default_location->country : '',
			'zip'       => '',
			'latitude'  => isset( $default_location->latitude ) ? $default_location->latitude : '',
			'longitude' => isset( $default_location->longitude ) ? $default_location->longitude : '',
		);
	}

	/**
	 * Sorts an array by priority value.
	 *
	 * @since 2.0.2
	 *
	 * @param array $a First array to compare.
	 * @param array $b Second array to compare.
	 * @return int Sorting order: -1, 0, or 1.
	 */
	protected function sort_by_priority( $a, $b ) {
		$a_priority = isset( $a['priority'] ) ? (float) $a['priority'] : 100000;
		$b_priority = isset( $b['priority'] ) ? (float) $b['priority'] : 100000;

		if ( $a_priority === $b_priority ) {
			return 0;
		}

		return ( $a_priority < $b_priority ) ? -1 : 1;
	}

	/**
	 * Get package IDs for a given post type.
	 *
	 * @since 2.0.2
	 *
	 * @param string $post_type The post type.
	 * @return array Array of package IDs.
	 */
	protected function get_package_ids( $post_type ) {
		$package_ids = array();

		if ( function_exists( 'geodir_pricing_get_packages' ) ) {
			$packages = geodir_pricing_get_packages( array( 'post_type' => $post_type ) );

			if ( ! empty( $packages ) && is_array( $packages ) ) {
				$package_ids = wp_list_pluck( $packages, 'id' );
			}
		}

		return $package_ids;
	}

	/**
	 * Get all post meta for a given post as a key-value array.
	 *
	 * @since 2.0.2
	 *
	 * @param int $post_id The post ID.
	 * @return array Associative array of meta keys to unserialized meta values.
	 */
	public function get_post_meta( $post_id ) {
		global $wpdb;

		$post_meta_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d",
				(int) $post_id
			),
			ARRAY_A
		);

		$post_meta = array();
		foreach ( $post_meta_raw as $meta ) {
			$post_meta[ $meta['meta_key'] ] = maybe_unserialize( $meta['meta_value'] );
		}

		unset( $post_meta_raw ); // Free memory.

		return $post_meta;
	}

	/**
	 * Suspend non-essential hooks during bulk import for performance.
	 *
	 * Defers term/comment counting and removes hooks that send emails,
	 * clear caches, or run pricing validations during each post save.
	 * Call restore_hooks() after the batch to re-enable everything.
	 *
	 * @since 2.2.0
	 */
	public function suspend_hooks() {
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		// Prevent email notifications on post status transitions.
		remove_action( 'transition_post_status', array( 'GeoDir_Post_Data', 'transition_post_status' ), 6 );

		// Prevent Elementor cache clear on every save.
		if ( class_exists( 'GeoDir_Elementor' ) ) {
			remove_action( 'save_post', array( 'GeoDir_Elementor', 'clear_cache' ) );
		}

		// Prevent pricing hooks from running during import.
		if ( class_exists( 'GeoDir_Pricing_Bundle' ) ) {
			remove_action( 'save_post', array( 'GeoDir_Pricing_Bundle', 'on_save_post' ), 99 );
		}

		if ( class_exists( 'GeoDir_Pricing_Post' ) ) {
			remove_filter( 'wp_insert_post_data', array( 'GeoDir_Pricing_Post', 'wp_insert_post_data' ), 9 );
		}
	}

	/**
	 * Restore hooks and flush deferred counts after bulk import.
	 *
	 * @since 2.2.0
	 */
	public function restore_hooks() {
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		// Restore hooks.
		add_action( 'transition_post_status', array( 'GeoDir_Post_Data', 'transition_post_status' ), 6, 3 );

		if ( class_exists( 'GeoDir_Elementor' ) ) {
			add_action( 'save_post', array( 'GeoDir_Elementor', 'clear_cache' ) );
		}

		if ( class_exists( 'GeoDir_Pricing_Bundle' ) ) {
			add_action( 'save_post', array( 'GeoDir_Pricing_Bundle', 'on_save_post' ), 99, 3 );
		}

		if ( class_exists( 'GeoDir_Pricing_Post' ) ) {
			add_filter( 'wp_insert_post_data', array( 'GeoDir_Pricing_Post', 'wp_insert_post_data' ), 9, 2 );
		}
	}

	/**
	 * Reverse geocode coordinates and merge into a location array.
	 *
	 * Calls the geocoding API (with caching), logs the result, and merges
	 * the normalised location data into the provided array.
	 *
	 * @since 2.2.0
	 *
	 * @param float $lat      Latitude.
	 * @param float $lng      Longitude.
	 * @param array $location Existing location array to merge into.
	 * @param int   $post_id  Source post ID (used in log messages).
	 * @return array The merged location array.
	 */
	protected function geocode_location( $lat, $lng, array $location, $post_id ) {
		if ( empty( $lat ) || empty( $lng ) ) {
			return $location;
		}

		$result = GeoDir_Converter_Utils::get_location_from_coords( $lat, $lng );

		if ( ! is_wp_error( $result ) ) {
			$source = isset( $result['_source'] ) ? $result['_source'] : 'unknown';
			$this->log(
				sprintf(
					/* translators: 1: post ID, 2: city, 3: country, 4: source (api/cache/memory) */
					__( 'Geocoded (#%1$d): %2$s, %3$s (%4$s)', 'geodir-converter' ),
					$post_id,
					! empty( $result['city'] ) ? $result['city'] : '?',
					! empty( $result['country'] ) ? $result['country'] : '?',
					$source
				)
			);
			unset( $result['_source'] );
			$location = array_merge( $location, $result );
		} else {
			$this->log(
				sprintf(
					/* translators: 1: post ID, 2: error message */
					__( 'Geocoding failed (#%1$d): %2$s', 'geodir-converter' ),
					$post_id,
					$result->get_error_message()
				),
				'warning'
			);
		}

		return $location;
	}

	/**
	 * Process the result of a single listing import.
	 *
	 * Handles logging and counter updates for all import status codes.
	 * Eliminates the duplicated switch/log/count block across importers.
	 *
	 * @since 2.2.0
	 *
	 * @param int    $status    Import status constant (IMPORT_STATUS_*).
	 * @param string $item_type Human-readable item type (e.g. 'listing', 'property').
	 * @param string $label     Display label for the item (e.g. 'My Listing (#123)').
	 * @param int    $source_id Source post ID for failed item tracking.
	 * @param string $action    Action identifier for failed item tracking.
	 */
	public function process_import_result( $status, $item_type, $label, $source_id = 0, $action = self::ACTION_IMPORT_LISTINGS ) {
		switch ( $status ) {
			case self::IMPORT_STATUS_SUCCESS:
			case self::IMPORT_STATUS_UPDATED:
				if ( self::IMPORT_STATUS_SUCCESS === $status ) {
					$this->log( sprintf( self::LOG_TEMPLATE_SUCCESS, $item_type, $label ), 'success' );
				} else {
					$this->log( sprintf( self::LOG_TEMPLATE_UPDATED, $item_type, $label ), 'warning' );
				}
				$this->increase_succeed_imports( 1 );
				break;

			case self::IMPORT_STATUS_SKIPPED:
				$this->log( sprintf( self::LOG_TEMPLATE_SKIPPED, $item_type, $label ), 'warning' );
				$this->increase_skipped_imports( 1 );
				break;

			case self::IMPORT_STATUS_FAILED:
			default:
				$this->log( sprintf( self::LOG_TEMPLATE_FAILED, $item_type, $label ), 'warning' );
				$this->increase_failed_imports( 1 );
				$this->record_failed_item( $source_id, $action, $item_type, $label, sprintf( self::LOG_TEMPLATE_FAILED, $item_type, $label ) );
				break;
		}
	}

	/**
	 * Format image data for GeoDirectory.
	 *
	 * @since 2.0.2
	 * @param array $images Images (single or multiple).
	 * @param array $image_urls Optional. Image URLs to add to the formatted images.
	 * @return string Formatted image data.
	 */
	protected function format_images_data( $images, $image_urls = array() ) {
		$attachments = array();

		foreach ( $images as $index => $attachment ) {
			$attachment_id = isset( $attachment['id'] ) ? absint( $attachment['id'] ) : 0;

			// Skip invalid or non-image attachments.
			if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
				continue;
			}

			$attachments[] = array(
				'url'     => wp_get_attachment_url( $attachment_id ),
				'title'   => get_the_title( $attachment_id ),
				'caption' => isset( $attachment['caption'] ) ? sanitize_text_field( $attachment['caption'] ) : '',
				'weight'  => isset( $attachment['weight'] ) ? $attachment['weight'] : $index,
			);
		}

		if ( empty( $attachments ) && empty( $image_urls ) ) {
			return '';
		}

		// Sort attachments by weight.
		usort(
			$attachments,
			function ( $a, $b ) {
				return $a['weight'] - $b['weight'];
			}
		);

		$formatted_images = array();

		foreach ( $attachments as $attachment ) {
			$formatted_images[] = sprintf(
				'%s||%s|%s',
				esc_url( $attachment['url'] ),
				esc_html( $attachment['title'] ),
				esc_html( $attachment['caption'] )
			);
		}

		if ( ! empty( $image_urls ) ) {
			$formatted_images = array_merge( $formatted_images, $image_urls );
		}

		return implode( '::', $formatted_images );
	}

	/**
	 * Import image attachment from a URL.
	 *
	 * Downloads the image, sideloads it into the media library, and returns
	 * attachment data including the ID, URL, and file path.
	 *
	 * @since 2.0.2
	 *
	 * @param string $url The URL of the image to import.
	 * @return array|false Attachment data array with 'id', 'url', and 'src' keys, or false on failure.
	 */
	protected function import_attachment( $url ) {
		$uploads   = wp_upload_dir();
		$timeout   = 5;
		$temp_file = Geodir_Media::download_url( esc_url_raw( $url ), $timeout );

		if ( is_wp_error( $temp_file ) || ! file_exists( $temp_file ) ) {
			return false;
		}

		$file_type = wp_check_filetype( basename( wp_parse_url( $url, PHP_URL_PATH ) ) );

		if ( empty( $file_type['ext'] ) && empty( $file_type['type'] ) ) {
			return false;
		}

		$image = array(
			'name'     => basename( $url ),
			'type'     => $file_type['type'],
			'tmp_name' => $temp_file,
			'error'    => 0,
			'size'     => filesize( $temp_file ),
		);

		$result = wp_handle_sideload(
			$image,
			array(
				'test_form' => false,
				'test_size' => true,
			)
		);

		// Delete temp file.
		@unlink( $temp_file );

		if ( isset( $result['error'] ) && ! empty( $result['error'] ) ) {
			return false;
		}

		$attach_id       = wp_insert_attachment(
			array(
				'guid'           => $uploads['baseurl'] . '/' . basename( $result['file'] ),
				'post_mime_type' => $file_type['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $result['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$result['file']
		);
		$attachment_data = wp_generate_attachment_metadata( $attach_id, $result['file'] );

		if ( empty( $attachment_data['file'] ) && isset( $file_type['ext'] ) && 'svg' === $file_type['ext'] ) {
			$attachment_data['file'] = str_replace( $uploads['basedir'], '', $result['file'] );
		}

		wp_update_attachment_metadata( $attach_id, $attachment_data );

		$attachment = array(
			'id'  => $attach_id,
			'url' => wp_get_attachment_url( $attach_id ),
			'src' => $attachment_data['file'],
		);

		return $attachment;
	}

	/**
	 * Import taxonomy terms into GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array  $terms        Array of term objects to import.
	 * @param string $taxonomy     The taxonomy to import terms into.
	 * @param string $desc_meta_key The meta key for storing term description. Default 'ct_cat_top_desc'.
	 * @param array  $params       Optional. Additional parameters including 'importer_id' and 'eq_suffix'.
	 * @return array {
	 *     Result of the import operation.
	 *
	 *     @type int $imported Number of successfully imported terms.
	 *     @type int $failed   Number of failed term imports.
	 * }
	 */
	protected function import_taxonomy_terms( $terms, $taxonomy, $desc_meta_key = 'ct_cat_top_desc', $params = array() ) {
		$imported = 0;
		$failed   = 0;

		if ( empty( $terms ) ) {
			return compact( 'imported', 'failed' );
		}

		$params = wp_parse_args(
			$params,
			array(
				'importer_id' => '',
				'eq_suffix'   => '',
			)
		);

		$admin_taxonomies = new GeoDir_Admin_Taxonomies();

		$equivalent_key = 'gd_equivalent';
		if ( ! empty( $params['eq_suffix'] ) ) {
			$equivalent_key .= $params['eq_suffix'];
		}

		foreach ( $terms as $term ) {
			$args = array(
				'description' => $term->description,
				'slug'        => $term->slug,
			);

			// Handle parent terms.
			if ( ! empty( $term->parent ) ) {
				$parent = get_term_meta( $term->parent, $equivalent_key, true );

				if ( $parent ) {
					$args['parent'] = $parent;
				}
			}

			if ( ! $this->is_test_mode() ) {
				$id = term_exists( $term->slug, $taxonomy );
				if ( ! $id ) {
					$id = wp_insert_term( $term->name, $taxonomy, $args );
				}

				if ( is_wp_error( $id ) ) {
					++$failed;
					$this->log(
						sprintf(
							/* translators: %1$s: term name, %2$s: error message */
							esc_html__( 'Taxonomy error with "%1$s": %2$s', 'geodir-converter' ),
							esc_html( $term->name ),
							esc_html( $id->get_error_message() )
						),
						'error'
					);
					continue;
				}

				$term_id = is_array( $id ) ? $id['term_id'] : $id;

				if ( ! empty( $term->description ) ) {
					update_term_meta( $term_id, $desc_meta_key, $term->description );
				}

				if ( $params['importer_id'] == 'directorist' && strpos( $taxonomy, 'category' ) !== false ) {
					$category_icon = get_term_meta( $term->term_id, 'category_icon', true );

					if ( $category_icon ) {
						update_term_meta( $term_id, 'ct_cat_font_icon', $category_icon );

						$category_icon = $admin_taxonomies->generate_cat_icon( $category_icon, '#ff8c00' );

						if ( $category_icon ) {
							update_term_meta( $term_id, 'ct_cat_color', '#ff8c00' );
							update_term_meta( $term_id, 'ct_cat_icon', $category_icon );
						}
					}

					$image_id = get_term_meta( $term->term_id, 'image', true );

					if ( $image_id && ( $attachment_url = wp_get_attachment_url( $image_id ) ) ) {
						$image_url = geodir_file_relative_url( $attachment_url );

						update_term_meta(
							$term_id,
							'ct_cat_default_img',
							array(
								'id'  => $image_id,
								'src' => $image_url,
							)
						);
					}
				}

				update_term_meta( $term->term_id, $equivalent_key, $term_id );
			}

			++$imported;
		}

		return compact( 'imported', 'failed' );
	}

	/**
	 * Start the import process.
	 *
	 * Validates settings, clears previous import data, and dispatches
	 * the background process to handle the import.
	 *
	 * @since 2.0.2
	 *
	 * @param array $settings The import settings.
	 * @param array $files    Optional. Uploaded files to process. Default empty array.
	 * @return WP_Error|void WP_Error on validation failure, void on success.
	 */
	public function import( $settings, $files = array() ) {
		// Parse CSV files.
		$rows         = array();
		$import_files = array();

		if ( is_array( $files ) && ! empty( $files ) ) {
			foreach ( $files['tmp_name'] as $key => $file ) {
				$extension = pathinfo( $files['name'][ $key ], PATHINFO_EXTENSION );

				$import_files[ $key ] = array(
					'name'      => $files['name'][ $key ],
					'type'      => isset( $files['type'][ $key ] ) ? sanitize_text_field( $files['type'][ $key ] ) : '',
					'size'      => isset( $files['size'][ $key ] ) ? absint( $files['size'][ $key ] ) : 0,
					'extension' => $extension,
				);

				if ( in_array( $extension, array( 'csv' ), true ) ) {
					$csv_rows = GeoDir_Converter_Utils::parse_csv( $file );

					if ( is_wp_error( $csv_rows ) ) {
						return $csv_rows;
					}

					$import_files[ $key ]['row_count'] = count( $csv_rows );

					$rows = array_merge( $rows, $csv_rows );
				}
			}
		}

		// Validate and sanitize settings.
		$settings = $this->validate_settings( $settings, $import_files );

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		// reset all importer options.
		$this->clear_import_options();

		// save import settings.
		$this->options_handler->update_option( 'import_settings', $settings );
		// set import start time.
		$this->options_handler->update_option( 'import_start_time', time() );

		if ( $this->is_test_mode() ) {
			$this->log( esc_html__( 'Test mode is enabled. No data will be imported.', 'geodir-converter' ), 'error' );
		}

		// Start the background process.
		$this->background_process->add_converter_tasks(
			array(
				'importer_id' => $this->importer_id,
				'settings'    => $settings,
				'rows'        => $rows,
			)
		);
	}

	/**
	 * Increases the total count of imports by the specified increment.
	 *
	 * @since 2.0.2
	 *
	 * @param int $increment The amount by which to increase the total imports count.
	 * @return void
	 */
	public function increase_imports_total( $increment ) {
		$this->increase_field( 'total', $increment );
	}

	/**
	 * Increases the count of successful imports by the specified increment.
	 *
	 * @since 2.0.2
	 *
	 * @param int $increment The amount by which to increase the successful imports count.
	 * @return void
	 */
	public function increase_succeed_imports( $increment ) {
		$this->increase_field( 'succeed', $increment );
	}

	/**
	 * Increases the count of skipped imports by the specified increment.
	 *
	 * @since 2.0.2
	 *
	 * @param int $increment The amount by which to increase the skipped imports count.
	 * @return void
	 */
	public function increase_skipped_imports( $increment ) {
		$this->increase_field( 'skipped', $increment );
	}

	/**
	 * Increases the count of failed imports by the specified increment.
	 *
	 * @since 2.0.2
	 *
	 * @param int $increment The amount by which to increase the failed imports count.
	 * @return void
	 */
	public function increase_failed_imports( $increment ) {
		$this->increase_field( 'failed', $increment );
	}

	/**
	 * Increases the value of a specific stats field by the specified increment.
	 *
	 * Buffers the increment in memory. Call flush_stats() to write to the database.
	 *
	 * @since 2.0.2
	 *
	 * @param string $field     The name of the stats field to increase.
	 * @param int    $increment The amount by which to increase the field's value.
	 * @return void
	 */
	protected function increase_field( $field, $increment ) {
		if ( ! isset( $this->stats_buffer[ $field ] ) ) {
			$this->stats_buffer[ $field ] = 0;
		}
		$this->stats_buffer[ $field ] += (int) $increment;
	}

	/**
	 * Flush buffered stats increments to the database in a single write.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function flush_stats() {
		if ( empty( $this->stats_buffer ) ) {
			return;
		}

		$stats       = (array) $this->options_handler->get_option_no_cache( 'stats' );
		$empty_stats = self::empty_stats();
		$stats       = wp_parse_args( $stats, $empty_stats );

		foreach ( $this->stats_buffer as $field => $increment ) {
			$stats[ $field ] = (int) $stats[ $field ] + $increment;
		}

		$this->options_handler->update_option( 'stats', (array) $stats );
		$this->stats_buffer = array();
	}

	/**
	 * Retrieves the import statistics.
	 *
	 * @since 2.0.2
	 *
	 * @return array {
	 *     An array containing import statistics.
	 *
	 *     @type int $total   Total items to import.
	 *     @type int $succeed Successfully imported items.
	 *     @type int $skipped Skipped items.
	 *     @type int $failed  Failed items.
	 * }
	 */
	public function get_stats() {
		$stats       = (array) $this->options_handler->get_option_no_cache( 'stats' );
		$empty_stats = self::empty_stats();
		$stats       = wp_parse_args( $stats, $empty_stats );

		return array(
			'total'   => (int) $stats['total'],
			'succeed' => (int) $stats['succeed'],
			'skipped' => (int) $stats['skipped'],
			'failed'  => (int) $stats['failed'],
		);
	}

	/**
	 * Returns an array representing empty statistics, with all counts initialized to 0.
	 *
	 * @since 2.0.2
	 *
	 * @return array An array containing empty statistics.
	 */
	public function empty_stats() {
		return array(
			'total'   => 0,
			'succeed' => 0,
			'skipped' => 0,
			'failed'  => 0,
		);
	}

	/**
	 * Get the import progress as a percentage.
	 *
	 * @since 2.0.2
	 *
	 * @return float The import progress percentage (0-100).
	 */
	public function get_progress() {
		$stats = $this->get_stats();

		$total     = (int) $stats['total'];
		$processed = $stats['succeed'] + $stats['skipped'] + $stats['failed'];

		if ( $total == 0 ) {
			return $this->background_process->is_in_progress() ? 0 : 100;
		} else {
			return $this->background_process->is_in_progress() ? min( round( $processed / $total * 100 ), 100 ) : 100;
		}
	}

	/**
	 * Clear all import-related options.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function clear_import_options() {
		$this->options_handler->delete_option( 'stats' );
		$this->options_handler->delete_option( 'import_log' );
		$this->options_handler->delete_option( 'import_settings' );
		$this->options_handler->delete_option( 'import_start_time' );
		$this->options_handler->delete_option( 'failed_items' );
		$this->options_handler->delete_option( 'paused' );
	}

	/**
	 * Record a failed import item for potential retry.
	 *
	 * Items are buffered in memory and written to the database
	 * when flush_failed_items() is called.
	 *
	 * @since 2.2.0
	 * @param int    $source_id  The original source item ID.
	 * @param string $action     The task action (e.g., ACTION_IMPORT_LISTINGS).
	 * @param string $item_type  Human-readable item type (e.g., 'listing', 'category').
	 * @param string $item_title The item title for display purposes.
	 * @param string $error      The error description.
	 */
	public function record_failed_item( $source_id, $action, $item_type, $item_title, $error = '' ) {
		$key = $action . '_' . $source_id;

		$this->pending_failed_items[ $key ] = array(
			'source_id'     => $source_id,
			'action'        => $action,
			'item_type'     => $item_type,
			'item_title'    => $item_title,
			'error_message' => $error,
			'timestamp'     => time(),
			'retry_count'   => 0,
		);
	}

	/**
	 * Flush buffered failed items to the database.
	 *
	 * @since 2.2.0
	 */
	public function flush_failed_items() {
		if ( empty( $this->pending_failed_items ) ) {
			return;
		}

		$failed_items = (array) $this->options_handler->get_option_no_cache( 'failed_items', array() );

		foreach ( $this->pending_failed_items as $key => $item ) {
			// Preserve retry_count if re-failing during a retry.
			if ( isset( $failed_items[ $key ] ) ) {
				$item['retry_count'] = (int) $failed_items[ $key ]['retry_count'] + 1;
			}
			$failed_items[ $key ] = $item;
		}

		$this->options_handler->update_option( 'failed_items', $failed_items );
		$this->pending_failed_items = array();
	}

	/**
	 * Get all recorded failed items.
	 *
	 * @since 2.2.0
	 *
	 * @return array Array of failed item records.
	 */
	public function get_failed_items() {
		return (array) $this->options_handler->get_option_no_cache( 'failed_items', array() );
	}

	/**
	 * Get the count of tracked failed items.
	 *
	 * @since 2.2.0
	 *
	 * @return int The number of failed items.
	 */
	public function get_failed_items_count() {
		return count( $this->get_failed_items() );
	}

	/**
	 * Check if there are any recorded failed items.
	 *
	 * @since 2.2.0
	 *
	 * @return bool True if there are failed items, false otherwise.
	 */
	public function has_failed_items() {
		return $this->get_failed_items_count() > 0;
	}

	/**
	 * Clear all failed items.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function clear_failed_items() {
		$this->options_handler->delete_option( 'failed_items' );
		$this->pending_failed_items = array();
	}

	/**
	 * Display the action buttons (Import, Pause, Resume, Abort, Retry Failed).
	 *
	 * @since 2.2.0
	 */
	public function display_action_buttons() {
		?>
		<div class="geodir-converter-actions mt-3 d-flex flex-wrap gap-2 align-items-center">
			<button type="button" class="btn btn-primary btn-sm geodir-converter-import">
				<i class="fas fa-play me-1"></i><?php esc_html_e( 'Start Import', 'geodir-converter' ); ?>
			</button>
			<button type="button" class="btn btn-outline-danger btn-sm geodir-converter-abort" disabled>
				<i class="fas fa-stop me-1"></i><?php esc_html_e( 'Abort', 'geodir-converter' ); ?>
			</button>
			<button type="button" class="btn btn-outline-warning btn-sm geodir-converter-retry-failed d-none">
				<i class="fas fa-redo me-1"></i><?php esc_html_e( 'Retry Failed', 'geodir-converter' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Check if a listing has already been imported.
	 *
	 * @since 2.0.2
	 *
	 * @param int    $listing_id    The original listing ID.
	 * @param string $meta_key    The meta key to search for.
	 * @param string $post_type   The post type to search within. Default 'gd_place'.
	 * @return int|false The existing GD post ID if found, false otherwise.
	 */
	public function get_gd_listing_id( $listing_id, $meta_key, $post_type = 'gd_place' ) {
		global $wpdb, $plugin_prefix;

		$details_table = $plugin_prefix . $post_type . '_detail';

		$gd_post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pd.post_id
                FROM {$details_table} pd
                INNER JOIN {$wpdb->posts} p ON p.ID = pd.post_id
                WHERE pd.{$meta_key} = %d
                AND p.post_type = %s
                LIMIT 1",
				absint( $listing_id ),
				$post_type
			)
		);

		return $gd_post_id ? (int) $gd_post_id : false;
	}

	/**
	 * Check if a post has already been imported.
	 *
	 * @since 2.0.2
	 *
	 * @param int    $post_id    The original post ID.
	 * @param string $meta_key   The meta key to search for.
	 * @return int|false The existing GD post ID if found, false otherwise.
	 */
	public function get_gd_post_id( $post_id, $meta_key ) {
		global $wpdb;

		$gd_post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = %s
                AND pm.meta_value = %s
                LIMIT 1",
				$meta_key,
				$post_id
			)
		);

		return $gd_post_id ? (int) $gd_post_id : false;
	}

	/**
	 * Retrieves the import log with optional skipping.
	 *
	 * @since 2.0.2
	 *
	 * @param int $skip_logs Number of logs to skip. Defaults to 0.
	 * @return array The filtered import log entries.
	 */
	public function get_logs( $skip_logs = 0 ) {
		$logs = $this->options_handler->get_option_no_cache( 'import_log', array() );

		if ( ! is_array( $logs ) ) {
			return array();
		}

		$skip_logs = max( 0, (int) $skip_logs );

		return array_slice( $logs, $skip_logs );
	}

	/**
	 * Log a message.
	 *
	 * Buffers the log entry in memory. Call flush_logs() to write to the database.
	 *
	 * @since 2.0.2
	 *
	 * @param string $message The message to log.
	 * @param string $status  The status of log message. Accepts 'info', 'success', 'warning', 'error'. Default 'info'.
	 * @return void
	 */
	public function log( $message, $status = 'info' ) {
		$start_time   = $this->options_handler->get_option( 'import_start_time' );
		$current_time = time();
		$elapsed      = $start_time ? $current_time - $start_time : 0;
		$formatted    = $this->format_elapsed_time( $elapsed );

		$this->logs_buffer[] = array(
			'message'   => "{$formatted} – {$message}",
			'status'    => $status,
			'timestamp' => gmdate( 'Y-m-d H:i:s', $current_time ),
		);
	}

	/**
	 * Flush buffered log entries to the database in a single write.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function flush_logs() {
		if ( empty( $this->logs_buffer ) ) {
			return;
		}

		$logs = $this->options_handler->get_option_no_cache( 'import_log', array() );
		$logs = array_merge( $logs, $this->logs_buffer );
		$this->options_handler->update_option( 'import_log', $logs );
		$this->logs_buffer = array();
	}

	/**
	 * Converts a log entry into HTML format.
	 *
	 * @since 2.0.2
	 *
	 * @param array $log    Log entry with 'status' and 'message' keys.
	 * @param bool  $inline Whether the log should be displayed inline. Default false.
	 * @return string HTML representation of the log entry.
	 */
	public function log_to_html( array $log, bool $inline = false ) {
		$log += array(
			'status'  => 'info',
			'message' => '',
		);

		$html = '';

		if ( ! empty( $log['message'] ) && ! $inline ) {
			$html .= '<li>';
			$html .= '<p class="notice notice-' . esc_attr( $log['status'] ) . ' ms-0 me-0 mb-2">';
			$html .= esc_html( $log['message'] );
			$html .= '</p>';
			$html .= '</li>';
		} else {
			$html .= esc_html( $log['message'] );
		}

		return $html;
	}

	/**
	 * Converts an array of logs into HTML format.
	 *
	 * @since 2.0.2
	 *
	 * @param array $logs   An array of log entries.
	 * @param bool  $inline Whether the logs should be displayed inline. Default false.
	 * @return array Array of HTML string representations of the log entries.
	 */
	public function logs_to_html( array $logs, bool $inline = false ) {
		$logs_html = array();
		foreach ( $logs as $log ) {
			$logs_html[] = $this->log_to_html( $log, $inline );
		}
		return $logs_html;
	}

	/**
	 * Format elapsed time in H:i:s format.
	 *
	 * @since 2.2.0
	 *
	 * @param int $seconds Number of seconds since start.
	 * @return string Formatted time string (e.g. '01:23:45').
	 */
	private function format_elapsed_time( $seconds ) {
		$hours   = floor( $seconds / 3600 );
		$minutes = floor( ( $seconds % 3600 ) / 60 );
		$seconds = $seconds % 60;

		return sprintf( '%02d:%02d:%02d', $hours, $minutes, $seconds );
	}

	/**
	 * Check if a custom field already exists.
	 *
	 * @since 2.1.4
	 * @param string $htmlvar_name Field HTML variable name.
	 * @param string $post_type Post type.
	 * @return int|null Field ID if exists, null otherwise.
	 */
	protected function field_exists( $htmlvar_name, $post_type ) {
		global $wpdb;

		$field_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . GEODIR_CUSTOM_FIELDS_TABLE . ' WHERE htmlvar_name = %s AND post_type = %s LIMIT 1',
				$htmlvar_name,
				$post_type
			)
		);

		return $field_id ? (int) $field_id : null;
	}
}
