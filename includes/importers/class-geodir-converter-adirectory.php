<?php
/**
 * aDirectory Converter Class.
 *
 * @since     2.1.6
 * @package   GeoDir_Converter
 */

namespace GeoDir_Converter\Importers;

use WP_Error;
use WP_Query;
use GeoDir_Media;
use GeoDir_Comments;
use GeoDir_Event_Manager;

use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Main converter class for importing from aDirectory plugin.
 *
 * @since 2.1.6
 */
class GeoDir_Converter_aDirectory extends GeoDir_Converter_Importer {
	/**
	 * Taxonomy identifier for categories.
	 *
	 * @var string
	 */
	const TAX_CATEGORY = 'adqs_category';

	/**
	 * Taxonomy identifier for locations.
	 *
	 * @var string
	 */
	const TAX_LOCATION = 'adqs_location';

	/**
	 * Taxonomy identifier for tags.
	 *
	 * @var string
	 */
	const TAX_TAGS = 'adqs_tags';

	/**
	 * Taxonomy identifier for listing/directory types.
	 *
	 * @var string
	 */
	const TAX_LISTING_TYPES = 'adqs_listing_types';

	/**
	 * Import action for reviews.
	 */
	const ACTION_IMPORT_REVIEWS = 'import_reviews';

	/**
	 * Import action for parsing events.
	 */
	const ACTION_PARSE_EVENTS = 'parse_events';

	/**
	 * Import action for importing events.
	 */
	const ACTION_IMPORT_EVENTS = 'import_events';

	/**
	 * GeoDirectory event post type.
	 */
	const GD_POST_TYPE_EVENT = 'gd_event';

	/**
	 * aDirectory preset meta keys.
	 */
	const META_ADDRESS      = '_address';
	const META_PHONE        = '_phone';
	const META_EMAIL        = '_email';
	const META_FAX          = '_fax';
	const META_WEBSITE      = '_website';
	const META_VIDEO        = '_video';
	const META_TAGLINE      = '_tagline';
	const META_ZIP          = '_zip';
	const META_MAP_LAT      = '_map_lat';
	const META_MAP_LON      = '_map_lon';
	const META_HIDE_MAP     = '_hide_map';
	const META_PRICE_TYPE   = '_price_type';
	const META_PRICE        = '_price';
	const META_PRICE_SUB    = '_price_sub';
	const META_PRICE_RANGE  = '_price_range';
	const META_IS_FEATURED  = '_is_featured';
	const META_EXPIRY_DATE  = '_expiry_date';
	const META_EXPIRY_NEVER = '_expiry_never';
	const META_IMAGES       = '_images';
	const META_VIEW_COUNT   = '_view_count';
	const META_BUSINESS     = 'adqs_business_data';
	const META_SOCIAL       = 'adqs_social_media_link';

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
	protected $importer_id = 'adirectory';

	/**
	 * The import listing status ID.
	 *
	 * @var array
	 */
	protected $post_statuses = array( 'publish', 'pending', 'draft', 'private', 'expired' );

	/**
	 * Batch size for processing items.
	 *
	 * @var int
	 */
	protected $batch_size = 10;

	/**
	 * Cached custom fields definition.
	 *
	 * @var array|null
	 */
	private $custom_fields_cache = null;

	/**
	 * Initialize hooks.
	 *
	 * @since 2.1.6
	 */
	protected function init() {
		add_action( 'init', array( $this, 'maybe_register_taxonomies' ), 0 );
	}

	/**
	 * Register aDirectory taxonomies and post types if not already registered.
	 *
	 * This allows the importer to work even when the aDirectory plugin
	 * is not active, as long as the data exists in the database.
	 *
	 * @since 2.1.6
	 *
	 * @return void
	 */
	public function maybe_register_taxonomies() {
		if ( ! taxonomy_exists( self::TAX_LISTING_TYPES ) ) {
			register_taxonomy( self::TAX_LISTING_TYPES, array(), array(
				'label'        => 'Directory Types',
				'public'       => false,
				'hierarchical' => true,
			) );
		}

		if ( ! taxonomy_exists( self::TAX_CATEGORY ) ) {
			register_taxonomy( self::TAX_CATEGORY, array(), array(
				'label'        => 'Listing Categories',
				'public'       => false,
				'hierarchical' => true,
			) );
		}

		if ( ! taxonomy_exists( self::TAX_LOCATION ) ) {
			register_taxonomy( self::TAX_LOCATION, array(), array(
				'label'        => 'Locations',
				'public'       => false,
				'hierarchical' => true,
			) );
		}

		if ( ! taxonomy_exists( self::TAX_TAGS ) ) {
			register_taxonomy( self::TAX_TAGS, array(), array(
				'label'  => 'Tags',
				'public' => false,
			) );
		}

		// Register dynamic post types from directory types.
		$directories = get_terms( array(
			'taxonomy'   => self::TAX_LISTING_TYPES,
			'hide_empty' => false,
		) );

		if ( ! is_wp_error( $directories ) && ! empty( $directories ) ) {
			foreach ( $directories as $dir ) {
				$cpt = get_term_meta( $dir->term_id, 'adqs_directory_post_type', true );
				if ( ! empty( $cpt ) && ! post_type_exists( $cpt ) ) {
					register_post_type( $cpt, array(
						'label'  => $dir->name,
						'public' => false,
					) );
				}
			}
		}
	}

	/**
	 * Check if a field should be skipped during import.
	 *
	 * @since 2.1.6
	 *
	 * @param string $field_name The field name to check.
	 * @return bool True if the field should be skipped, false otherwise.
	 */
	protected function should_skip_field( $field_name ) {
		$skip_fields = array(
			'images',
			'post_images',
		);

		if ( in_array( $field_name, $skip_fields, true ) ) {
			return true;
		}

		return parent::should_skip_field( $field_name );
	}

	/**
	 * Get importer title.
	 *
	 * @since 2.1.6
	 *
	 * @return string Importer title.
	 */
	public function get_title() {
		return __( 'aDirectory', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @since 2.1.6
	 *
	 * @return string Importer description.
	 */
	public function get_description() {
		return __( 'Import listings, categories, and reviews from your aDirectory plugin installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @since 2.1.6
	 *
	 * @return string Icon URL.
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/adirectory.png';
	}

	/**
	 * Get importer task action.
	 *
	 * @since 2.1.6
	 *
	 * @return string Import action identifier.
	 */
	public function get_action() {
		return self::ACTION_IMPORT_CATEGORIES;
	}

	/**
	 * Render importer settings.
	 *
	 * @since 2.1.6
	 *
	 * @return void
	 */
	public function render_settings() {
		?>
		<form class="geodir-converter-settings-form" method="post">
			<h6 class="fs-base"><?php esc_html_e( 'aDirectory Importer Settings', 'geodir-converter' ); ?></h6>

			<?php
			if ( ! $this->is_adirectory_active() ) {
				aui()->alert(
					array(
						'type'    => 'warning',
						'heading' => esc_html__( 'aDirectory plugin not detected.', 'geodir-converter' ),
						'content' => esc_html__( 'Please make sure aDirectory plugin is installed and was previously active. The importer will still work with existing data in the database.', 'geodir-converter' ),
						'class'   => 'mb-3',
					),
					true
				);
			}

			if ( ! class_exists( 'GeoDir_Event_Manager' ) && $this->has_event_directory_types() ) {
				$this->render_plugin_notice(
					esc_html__( 'GeoDirectory Events', 'geodir-converter' ),
					'events',
					esc_url( 'https://wpgeodirectory.com/downloads/events/' )
				);
			}

			$this->display_directory_type_select();
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
	 * Display the directory type selector.
	 *
	 * @since 2.1.6
	 *
	 * @return void
	 */
	protected function display_directory_type_select() {
		$selected_types = $this->get_import_setting( 'directory_types', array() );
		$directories    = $this->get_adirectory_types();

		if ( empty( $directories ) ) {
			return;
		}

		$options = array( '' => esc_html__( 'All Directory Types', 'geodir-converter' ) );
		foreach ( $directories as $dir ) {
			$options[ $dir->term_id ] = $dir->name . ' (' . $dir->count . ')';
		}

		aui()->select(
			array(
				'id'          => $this->importer_id . '_directory_types',
				'name'        => 'directory_types[]',
				'label'       => esc_html__( 'Filter by Directory Type', 'geodir-converter' ),
				'label_type'  => 'top',
				'label_class' => 'font-weight-bold fw-bold',
				'value'       => $selected_types,
				'options'     => $options,
				'multiple'    => true,
				'select2'     => true,
				'help_text'   => esc_html__( 'Select specific directory types to import. Leave empty to import all.', 'geodir-converter' ),
			),
			true
		);
	}

	/**
	 * Get aDirectory directory types.
	 *
	 * @since 2.1.6
	 *
	 * @return array Array of term objects.
	 */
	private function get_adirectory_types() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX_LISTING_TYPES,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return $terms;
	}

	/**
	 * Check if aDirectory plugin is active or was previously active.
	 *
	 * @since 2.1.6
	 *
	 * @return bool True if aDirectory is active or data exists.
	 */
	private function is_adirectory_active() {
		if ( defined( 'ADQS_DIRECTORY_VERSION' ) || class_exists( 'ADQS_Init' ) ) {
			return true;
		}

		// Check if data exists in the database from a previously active installation.
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1",
				self::TAX_LISTING_TYPES
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Check if any aDirectory directory types appear to be event directories.
	 *
	 * Detects event directory types by checking if the directory name or slug
	 * contains "event", or if its field definitions include date/time fields
	 * that indicate event-like content.
	 *
	 * @since 2.1.6
	 *
	 * @return bool True if event directory types exist.
	 */
	private function has_event_directory_types() {
		return ! empty( $this->get_event_post_types() );
	}

	/**
	 * Get aDirectory post types that represent events.
	 *
	 * A directory type is considered an "event" type if its name/slug contains "event"
	 * or its field definitions include event date fields (fieldid containing 'event_date').
	 *
	 * @since 2.1.6
	 *
	 * @return array Array of event post type slugs.
	 */
	private function get_event_post_types() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache       = array();
		$directories = get_terms( array(
			'taxonomy'   => self::TAX_LISTING_TYPES,
			'hide_empty' => false,
		) );

		if ( is_wp_error( $directories ) || empty( $directories ) ) {
			return $cache;
		}

		foreach ( $directories as $dir ) {
			$is_event = false;

			// Check name/slug for "event" keyword.
			if ( false !== stripos( $dir->name, 'event' ) || false !== stripos( $dir->slug, 'event' ) ) {
				$is_event = true;
			}

			// Also check field definitions for event date fields.
			if ( ! $is_event ) {
				$field_defs = get_term_meta( $dir->term_id, 'adqs_metafields_types', true );
				if ( is_array( $field_defs ) ) {
					foreach ( $field_defs as $section ) {
						$fields = isset( $section['fields'] ) ? $section['fields'] : array();
						foreach ( $fields as $field ) {
							$fieldid = isset( $field['fieldid'] ) ? $field['fieldid'] : '';
							if ( false !== stripos( $fieldid, 'event_date' ) ) {
								$is_event = true;
								break 2;
							}
						}
					}
				}
			}

			if ( $is_event ) {
				$cpt = get_term_meta( $dir->term_id, 'adqs_directory_post_type', true );
				if ( ! empty( $cpt ) ) {
					$cache[] = $cpt;
				}
			}
		}

		return $cache;
	}

	/**
	 * Get aDirectory post types, optionally excluding event types.
	 *
	 * @since 2.1.6
	 *
	 * @param bool $exclude_events Whether to exclude event post types.
	 * @return array Array of post type slugs.
	 */
	private function get_non_event_post_types() {
		$all_types   = $this->get_adirectory_post_types();
		$event_types = $this->get_event_post_types();

		if ( empty( $event_types ) ) {
			return $all_types;
		}

		return array_values( array_diff( $all_types, $event_types ) );
	}

	/**
	 * Validate importer settings.
	 *
	 * @since 2.1.6
	 *
	 * @param array $settings The settings to validate.
	 * @param array $files    The files to validate.
	 * @return array|WP_Error Validated and sanitized settings.
	 */
	public function validate_settings( array $settings, array $files = array() ) {
		$post_types = geodir_get_posttypes();
		$errors     = array();

		$settings['gd_post_type']     = isset( $settings['gd_post_type'] ) && ! empty( $settings['gd_post_type'] ) ? sanitize_text_field( $settings['gd_post_type'] ) : 'gd_place';
		$settings['wp_author_id']     = ( isset( $settings['wp_author_id'] ) && ! empty( $settings['wp_author_id'] ) ) ? absint( $settings['wp_author_id'] ) : get_current_user_id();
		$settings['test_mode']        = ( isset( $settings['test_mode'] ) && ! empty( $settings['test_mode'] ) && $settings['test_mode'] != 'no' ) ? 'yes' : 'no';
		$settings['directory_types']  = isset( $settings['directory_types'] ) && is_array( $settings['directory_types'] )
			? array_filter( array_map( 'absint', $settings['directory_types'] ) )
			: array();

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
	 * @since 2.1.6
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
			self::ACTION_IMPORT_FIELDS,
			self::ACTION_PARSE_LISTINGS,
			self::ACTION_PARSE_EVENTS,
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
	 * @since 2.1.6
	 *
	 * @return void
	 */
	public function set_import_total() {
		global $wpdb;

		$total_items = 0;

		// Count categories.
		$categories   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_CATEGORY ) );
		$total_items += $categories;

		// Count tags.
		$tags         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_TAGS ) );
		$total_items += $tags;

		// Count custom fields.
		$custom_fields = $this->get_custom_fields();
		$total_items  += (int) count( $custom_fields );

		// Count listings (non-event).
		$total_items += (int) $this->count_listings();

		// Count events (if GD Events addon is active).
		if ( class_exists( 'GeoDir_Event_Manager' ) ) {
			$total_items += (int) $this->count_events();
		}

		// Count reviews.
		$post_types = $this->get_adirectory_post_types();
		if ( ! empty( $post_types ) ) {
			$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$reviews = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->comments} c
					INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
					WHERE p.post_type IN ({$type_placeholders}) AND c.comment_approved = '1'",
					$post_types
				)
			);
			$total_items += $reviews;
		}

		$this->increase_imports_total( $total_items );
	}

	/**
	 * Get all aDirectory post types.
	 *
	 * @since 2.1.6
	 *
	 * @return array Array of post type slugs.
	 */
	private function get_adirectory_post_types() {
		$filter_types = $this->get_import_setting( 'directory_types', array() );
		$post_types   = array();

		$args = array(
			'taxonomy'   => self::TAX_LISTING_TYPES,
			'hide_empty' => false,
		);

		if ( ! empty( $filter_types ) ) {
			$args['include'] = $filter_types;
		}

		$directories = get_terms( $args );

		if ( is_wp_error( $directories ) || empty( $directories ) ) {
			return $post_types;
		}

		foreach ( $directories as $dir ) {
			$cpt = get_term_meta( $dir->term_id, 'adqs_directory_post_type', true );
			if ( ! empty( $cpt ) ) {
				$post_types[] = $cpt;
			}
		}

		return $post_types;
	}

	/**
	 * Count the total number of listings to import.
	 *
	 * @since 2.1.6
	 *
	 * @return int Total number of listings.
	 */
	private function count_listings() {
		global $wpdb;

		// Only count non-event post types for regular listings.
		$post_types = $this->get_non_event_post_types();
		if ( empty( $post_types ) ) {
			return 0;
		}

		$type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders})",
				array_merge( $post_types, $this->post_statuses )
			)
		);

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Count the total number of events to import.
	 *
	 * @since 2.1.6
	 *
	 * @return int Total number of events.
	 */
	private function count_events() {
		global $wpdb;

		$event_types = $this->get_event_post_types();
		if ( empty( $event_types ) ) {
			return 0;
		}

		$type_placeholders   = implode( ',', array_fill( 0, count( $event_types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders})",
				array_merge( $event_types, $this->post_statuses )
			)
		);

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Import categories from aDirectory to GeoDirectory.
	 *
	 * @since 2.1.6
	 *
	 * @param array $task Import task.
	 * @return array Result of the import operation.
	 */
	public function task_import_categories( $task ) {
		global $wpdb;
		$this->log( __( 'Categories: Import started.', 'geodir-converter' ) );
		$this->set_import_total();

		if ( 0 === intval( wp_count_terms( array( 'taxonomy' => self::TAX_CATEGORY, 'hide_empty' => false ) ) ) ) {
			$this->log( __( 'Categories: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$post_type = $this->get_import_post_type();

		$categories = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, tt.*
				FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				ORDER BY tt.parent ASC, t.name ASC",
				self::TAX_CATEGORY
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
					/* translators: %1$d: number imported, %2$d: number failed */
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
				/* translators: %1$d: number imported, %2$d: number failed */
				__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$result['imported'],
				$result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Import tags from aDirectory to GeoDirectory.
	 *
	 * @since 2.1.6
	 *
	 * @param array $task Import task.
	 * @return array Result of the import operation.
	 */
	public function task_import_tags( $task ) {
		global $wpdb;
		$this->log( __( 'Tags: Import started.', 'geodir-converter' ) );

		$post_type = $this->get_import_post_type();

		$tags = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, tt.*
				FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				ORDER BY t.name ASC",
				self::TAX_TAGS
			)
		);

		if ( empty( $tags ) || is_wp_error( $tags ) ) {
			$this->log( __( 'Tags: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		if ( $this->is_test_mode() ) {
			$this->increase_succeed_imports( count( $tags ) );
			$this->log(
				sprintf(
					/* translators: %1$d: number imported, %2$d: number failed */
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
				/* translators: %1$d: number imported, %2$d: number failed */
				__( 'Tags: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$result['imported'],
				$result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Get custom fields for aDirectory listings.
	 *
	 * @since 2.1.6
	 *
	 * @return array The custom fields.
	 */
	private function get_custom_fields() {
		if ( null !== $this->custom_fields_cache ) {
			return $this->custom_fields_cache;
		}

		$fields = array(
			array(
				'type'           => 'text',
				'data_type'      => 'INT',
				'field_key'      => $this->importer_id . '_id',
				'label'          => __( 'aDirectory ID', 'geodir-converter' ),
				'description'    => __( 'Original aDirectory Listing ID.', 'geodir-converter' ),
				'placeholder'    => __( 'aDirectory ID', 'geodir-converter' ),
				'icon'           => 'far fa-id-card',
				'only_for_admin' => 1,
				'required'       => 0,
			),
			array(
				'type'        => 'phone',
				'field_key'   => 'phone',
				'label'       => __( 'Phone', 'geodir-converter' ),
				'description' => __( 'Business phone number.', 'geodir-converter' ),
				'placeholder' => __( 'Phone', 'geodir-converter' ),
				'icon'        => 'fas fa-phone',
				'required'    => 0,
			),
			array(
				'type'        => 'email',
				'field_key'   => 'email',
				'label'       => __( 'Email', 'geodir-converter' ),
				'description' => __( 'Business email address.', 'geodir-converter' ),
				'placeholder' => __( 'Email', 'geodir-converter' ),
				'icon'        => 'far fa-envelope',
				'required'    => 0,
			),
			array(
				'type'        => 'phone',
				'field_key'   => 'fax',
				'label'       => __( 'Fax', 'geodir-converter' ),
				'description' => __( 'Business fax number.', 'geodir-converter' ),
				'placeholder' => __( 'Fax', 'geodir-converter' ),
				'icon'        => 'fas fa-fax',
				'required'    => 0,
			),
			array(
				'type'        => 'url',
				'field_key'   => 'website',
				'label'       => __( 'Website', 'geodir-converter' ),
				'description' => __( 'Business website URL.', 'geodir-converter' ),
				'placeholder' => __( 'Website', 'geodir-converter' ),
				'icon'        => 'fas fa-globe',
				'required'    => 0,
			),
			array(
				'type'        => 'url',
				'field_key'   => 'video_url',
				'label'       => __( 'Video URL', 'geodir-converter' ),
				'description' => __( 'Listing video URL.', 'geodir-converter' ),
				'placeholder' => __( 'Video URL', 'geodir-converter' ),
				'icon'        => 'fas fa-video',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'field_key'   => 'tagline',
				'label'       => __( 'Tagline', 'geodir-converter' ),
				'description' => __( 'Business tagline or slogan.', 'geodir-converter' ),
				'placeholder' => __( 'Tagline', 'geodir-converter' ),
				'icon'        => 'fas fa-quote-right',
				'required'    => 0,
			),
			array(
				'type'        => 'checkbox',
				'field_key'   => 'featured',
				'label'       => __( 'Is Featured?', 'geodir-converter' ),
				'description' => __( 'Mark listing as featured.', 'geodir-converter' ),
				'icon'        => 'fas fa-star',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'field_key'   => 'price_range',
				'label'       => __( 'Price Range', 'geodir-converter' ),
				'description' => __( 'Business price range.', 'geodir-converter' ),
				'placeholder' => __( 'Price Range', 'geodir-converter' ),
				'icon'        => 'fas fa-dollar-sign',
				'required'    => 0,
			),
			array(
				'type'        => 'business_hours',
				'field_key'   => 'business_hours',
				'label'       => __( 'Business Hours', 'geodir-converter' ),
				'description' => __( 'Business operating hours.', 'geodir-converter' ),
				'icon'        => 'fas fa-clock',
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

		// Add individual social media URL fields.
		$social_fields = $this->get_social_media_fields();
		if ( ! empty( $social_fields ) ) {
			$fields = array_merge( $fields, $social_fields );
		}

		// Discover dynamic custom fields from field definitions.
		$additional_fields = $this->discover_custom_fields();
		if ( ! empty( $additional_fields ) ) {
			$fields = array_merge( $fields, $additional_fields );
		}

		$this->custom_fields_cache = $fields;

		return $fields;
	}

	/**
	 * Discover custom fields from aDirectory field builder definitions.
	 *
	 * @since 2.1.6
	 *
	 * @return array Additional custom fields.
	 */
	private function discover_custom_fields() {
		$fields     = array();
		$field_keys = array();

		$directories = get_terms(
			array(
				'taxonomy'   => self::TAX_LISTING_TYPES,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $directories ) || empty( $directories ) ) {
			return $fields;
		}

		foreach ( $directories as $dir ) {
			$field_definitions = get_term_meta( $dir->term_id, 'adqs_metafields_types', true );

			if ( empty( $field_definitions ) || ! is_array( $field_definitions ) ) {
				continue;
			}

			foreach ( $field_definitions as $section ) {
				$section_fields = isset( $section['fields'] ) ? $section['fields'] : array();

				if ( empty( $section_fields ) || ! is_array( $section_fields ) ) {
					// Handle flat field definitions (no sections wrapper).
					if ( isset( $section['input_type'] ) && isset( $section['fieldid'] ) ) {
						$section_fields = array( $section );
					} else {
						continue;
					}
				}

				foreach ( $section_fields as $field_def ) {
					$field_id   = isset( $field_def['fieldid'] ) ? $field_def['fieldid'] : '';
					$field_type = isset( $field_def['input_type'] ) ? $field_def['input_type'] : '';
					$label      = isset( $field_def['label'] ) ? $field_def['label'] : '';

					if ( empty( $field_id ) || empty( $field_type ) || empty( $label ) ) {
						continue;
					}

					// Skip preset fields that are already handled.
					if ( $this->is_preset_field( $field_type ) ) {
						continue;
					}

					// Generate a human-readable field key from label.
					$field_key = sanitize_title( $label );
					$field_key = str_replace( '-', '_', $field_key );
					$field_key = substr( $field_key, 0, 50 );

					if ( isset( $field_keys[ $field_key ] ) ) {
						continue;
					}

					$gd_type = $this->map_field_type( $field_type );
					if ( ! $gd_type ) {
						continue;
					}

					$field = array(
						'type'        => $gd_type,
						'field_key'   => $field_key,
						'label'       => $label,
						/* translators: %s: field name */
						'description' => sprintf( __( 'Imported from aDirectory field: %s', 'geodir-converter' ), $label ),
						'icon'        => $this->get_icon_for_field( $field_key ),
						'required'    => ! empty( $field_def['is_required'] ) ? 1 : 0,
						'_adqs_meta'  => '_' . $field_type . '_' . $field_id,
					);

					// Handle select/checkbox options.
					if ( in_array( $gd_type, array( 'select', 'multiselect' ), true ) && ! empty( $field_def['options'] ) ) {
						$options = array();
						foreach ( (array) $field_def['options'] as $opt ) {
							if ( is_array( $opt ) && isset( $opt['label'] ) ) {
								$options[] = $opt['label'];
							} elseif ( is_string( $opt ) ) {
								$options[] = $opt;
							}
						}
						if ( ! empty( $options ) ) {
							$field['options'] = implode( ',', $options );
						}
					}

					$fields[]               = $field;
					$field_keys[ $field_key ] = '_' . $field_type . '_' . $field_id;
				}
			}
		}

		return $fields;
	}

	/**
	 * Check if a field type is a preset field.
	 *
	 * @since 2.1.6
	 *
	 * @param string $field_type The field type.
	 * @return bool True if preset.
	 */
	private function is_preset_field( $field_type ) {
		$presets = array(
			'address', 'phone', 'email', 'fax', 'website', 'video',
			'tagline', 'zip', 'map', 'pricing', 'businesshour',
			'social_media_link', 'view_count',
		);

		return in_array( $field_type, $presets, true );
	}

	/**
	 * Get individual social media fields.
	 *
	 * Creates individual URL fields for each social media platform,
	 * matching GeoDirectory's predefined social field types.
	 *
	 * @since 2.1.6
	 *
	 * @return array Array of social media field definitions.
	 */
	private function get_social_media_fields() {
		$post_type = $this->get_import_post_type();

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
			// Skip if field already exists as predefined GeoDirectory field.
			if ( ! empty( $post_type ) && $this->field_exists( $platform_key, $post_type ) ) {
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
	 * Map aDirectory field type to GeoDirectory field type.
	 *
	 * @since 2.1.6
	 *
	 * @param string $ad_type aDirectory field type.
	 * @return string|false GeoDirectory field type or false if not supported.
	 */
	private function map_field_type( $ad_type ) {
		$type_map = array(
			'text'        => 'text',
			'textarea'    => 'textarea',
			'number'      => 'text',
			'email'       => 'email',
			'url'         => 'url',
			'phone'       => 'phone',
			'date'        => 'datepicker',
			'time'        => 'time',
			'select'      => 'select',
			'radio'       => 'radio',
			'checkbox'    => 'multiselect',
			'file'         => 'file',
			'field_images' => 'file',
			'color'        => 'text',
			'password'     => 'text',
			'range'        => 'text',
		);

		return isset( $type_map[ $ad_type ] ) ? $type_map[ $ad_type ] : false;
	}

	/**
	 * Get database data type for field type.
	 *
	 * @since 2.1.6
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
			'radio'          => 'VARCHAR',
			'select'         => 'VARCHAR',
			'multiselect'    => 'VARCHAR',
			'time'           => 'TIME',
			'file'           => 'TEXT',
			'business_hours' => 'TEXT',
		);

		return isset( $type_map[ $field_type ] ) ? $type_map[ $field_type ] : 'VARCHAR';
	}

	/**
	 * Get appropriate icon for field based on field key.
	 *
	 * @since 2.1.6
	 *
	 * @param string $field_key Field key.
	 * @return string Icon class.
	 */
	private function get_icon_for_field( $field_key ) {
		$icon_map = array(
			'phone'     => 'fas fa-phone',
			'email'     => 'fas fa-envelope',
			'website'   => 'fas fa-globe',
			'address'   => 'fas fa-map-marker-alt',
			'location'  => 'fas fa-map-marker-alt',
			'price'     => 'fas fa-dollar-sign',
			'rating'    => 'fas fa-star',
			'date'      => 'fas fa-calendar',
			'time'      => 'far fa-clock',
			'hours'     => 'far fa-clock',
			'facebook'  => 'fab fa-facebook',
			'twitter'   => 'fab fa-twitter',
			'instagram' => 'fab fa-instagram',
			'linkedin'  => 'fab fa-linkedin',
			'youtube'   => 'fab fa-youtube',
		);

		foreach ( $icon_map as $keyword => $icon ) {
			if ( strpos( $field_key, $keyword ) !== false ) {
				return $icon;
			}
		}

		return 'fas fa-info-circle';
	}

	/**
	 * Import custom fields from aDirectory to GeoDirectory.
	 *
	 * @since 2.1.6
	 *
	 * @param array $task Task details.
	 * @return array Result of the import operation.
	 */
	public function task_import_fields( array $task ) {
		$this->log( __( 'Importing custom fields...', 'geodir-converter' ) );

		$post_type   = $this->get_import_post_type();
		$fields      = $this->get_custom_fields();
		$package_ids = $this->get_package_ids( $post_type );

		// Also create fields on gd_event post type if GD Events is active.
		$fields_cpts = array( $post_type );
		if ( class_exists( 'GeoDir_Event_Manager' ) && $this->has_event_directory_types() ) {
			$fields_cpts[] = self::GD_POST_TYPE_EVENT;
		}

		if ( empty( $fields ) ) {
			$this->log( __( 'No custom fields to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$imported = $updated = $skipped = $failed = 0;

		foreach ( $fields as $field ) {
			foreach ( $fields_cpts as $cpt ) {
				$gd_field = $this->prepare_single_field( $field, $cpt, $package_ids );

				if ( $this->should_skip_field( $gd_field['htmlvar_name'] ) ) {
					++$skipped;
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
					/* translators: %1$s: field name, %2$s: error message */
					$this->log( sprintf( __( 'Failed to import field: %1$s - %2$s', 'geodir-converter' ), $field['label'], $error_msg ), 'error' );
				}
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

		return $this->next_task( $task );
	}

	/**
	 * Prepare single field for GeoDirectory.
	 *
	 * @since 2.1.6
	 *
	 * @param array  $field       Field data.
	 * @param string $post_type   Post type.
	 * @param array  $package_ids Package IDs.
	 * @return array GeoDirectory field data.
	 */
	private function prepare_single_field( $field, $post_type, $package_ids = array() ) {
		$field_type = isset( $field['type'] ) ? $field['type'] : 'text';
		$field_id   = $this->field_exists( $field['field_key'], $post_type );

		$gd_field = array(
			'post_type'         => $post_type,
			'data_type'         => isset( $field['data_type'] ) ? $field['data_type'] : $this->map_data_type( $field_type ),
			'field_type'        => $field_type,
			'htmlvar_name'      => $field['field_key'],
			'admin_title'       => $field['label'],
			'frontend_title'    => $field['label'],
			'frontend_desc'     => isset( $field['description'] ) ? $field['description'] : '',
			'placeholder_value' => isset( $field['placeholder'] ) ? $field['placeholder'] : '',
			'default_value'     => '',
			'is_active'         => '1',
			'for_admin_use'     => isset( $field['only_for_admin'] ) && $field['only_for_admin'] ? 1 : 0,
			'is_required'       => isset( $field['required'] ) && 1 === $field['required'] ? 1 : 0,
			'show_in'           => '[detail]',
			'show_on_pkg'       => $package_ids,
			'clabels'           => $field['label'],
			'option_values'     => isset( $field['options'] ) ? $field['options'] : '',
			'field_icon'        => isset( $field['icon'] ) ? $field['icon'] : 'fas fa-info-circle',
		);

		// Set field_type_key for special field types so GD registers them correctly.
		if ( 'business_hours' === $field_type ) {
			$gd_field['field_type_key'] = 'business_hours';
		}

		if ( $field_id ) {
			$gd_field['field_id'] = $field_id;
		}

		return $gd_field;
	}

	/**
	 * Parse and batch listings for background import.
	 *
	 * @since 2.1.6
	 *
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

		// Only parse non-event post types — events are handled separately.
		$post_types = $this->get_non_event_post_types();
		if ( empty( $post_types ) ) {
			$this->log( __( 'No non-event aDirectory post types found. Skipping listings.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		wp_suspend_cache_addition( true );

		$type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) );

		$listings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_status
				FROM {$wpdb->posts}
				WHERE post_type IN ({$type_placeholders})
				AND post_status IN ({$status_placeholders})
				LIMIT %d OFFSET %d",
				array_merge(
					$post_types,
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

		wp_suspend_cache_addition( false );

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
	 * @since 2.1.6
	 *
	 * @param array $task The task to import.
	 * @return bool Result of the import operation.
	 */
	public function task_import_listings( $task ) {
		$listings = isset( $task['listings'] ) && ! empty( $task['listings'] ) ? (array) $task['listings'] : array();

		foreach ( $listings as $listing ) {
			$title  = $listing->post_title;
			$status = $this->import_single_listing( $listing );

			$this->process_import_result( $status, 'listing', $title, $listing->ID );
		}

		$this->flush_failed_items();

		return false;
	}

	/**
	 * Convert a single aDirectory listing to GeoDirectory format.
	 *
	 * @since 2.1.6
	 *
	 * @param object $listing The post object to convert.
	 * @return int Import status.
	 */
	private function import_single_listing( $listing ) {
		$post = get_post( $listing->ID );

		if ( ! $post ) {
			return self::IMPORT_STATUS_FAILED;
		}

		$post_type        = $this->get_import_post_type();
		$gd_post_id       = ! $this->is_test_mode() ? $this->get_gd_listing_id( $post->ID, $this->importer_id . '_id', $post_type ) : false;
		$is_update        = ! empty( $gd_post_id );
		$post_meta        = $this->get_post_meta( $post->ID );
		$default_location = $this->get_default_location();

		// Get categories.
		$categories = $this->get_listings_terms( $post->ID, self::TAX_CATEGORY );

		// Get tags.
		$tags = $this->get_listings_terms( $post->ID, self::TAX_TAGS );

		// Location data.
		$location  = $default_location;
		$latitude  = isset( $post_meta[ self::META_MAP_LAT ] ) ? $post_meta[ self::META_MAP_LAT ] : '';
		$longitude = isset( $post_meta[ self::META_MAP_LON ] ) ? $post_meta[ self::META_MAP_LON ] : '';
		$address   = isset( $post_meta[ self::META_ADDRESS ] ) ? $post_meta[ self::META_ADDRESS ] : '';

		$location['latitude']  = $latitude;
		$location['longitude'] = $longitude;
		$location = $this->geocode_location( $latitude, $longitude, $location, $post->ID );

		// Map post status.
		$post_status = $post->post_status;
		if ( $post_status === 'expired' ) {
			$post_status = 'draft';
		}

		// Build the listing data.
		$listing_data = array(
			// Standard WP Fields.
			'post_author'              => $post->post_author ? $post->post_author : $this->get_import_setting( 'wp_author_id', \get_current_user_id() ),
			'post_title'               => $post->post_title,
			'post_content'             => $post->post_content ? $post->post_content : '',
			'post_content_filtered'    => $post->post_content,
			'post_excerpt'             => $post->post_excerpt ? $post->post_excerpt : '',
			'post_status'              => $post_status,
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

			'street'                   => $address,
			'street2'                  => '',
			'city'                     => isset( $location['city'] ) ? $location['city'] : '',
			'region'                   => isset( $location['region'] ) ? $location['region'] : '',
			'country'                  => isset( $location['country'] ) ? $location['country'] : '',
			'zip'                      => isset( $post_meta[ self::META_ZIP ] ) ? $post_meta[ self::META_ZIP ] : ( isset( $location['zip'] ) ? $location['zip'] : '' ),
			'latitude'                 => isset( $location['latitude'] ) ? $location['latitude'] : '',
			'longitude'                => isset( $location['longitude'] ) ? $location['longitude'] : '',
			'mapview'                  => '',
			'mapzoom'                  => '',

			// aDirectory standard fields.
			$this->importer_id . '_id' => $post->ID,
			'featured'                 => ( isset( $post_meta[ self::META_IS_FEATURED ] ) && $post_meta[ self::META_IS_FEATURED ] === 'yes' ) ? 1 : 0,
			'phone'                    => isset( $post_meta[ self::META_PHONE ] ) ? $post_meta[ self::META_PHONE ] : '',
			'email'                    => isset( $post_meta[ self::META_EMAIL ] ) ? $post_meta[ self::META_EMAIL ] : '',
			'fax'                      => isset( $post_meta[ self::META_FAX ] ) ? $post_meta[ self::META_FAX ] : '',
			'website'                  => isset( $post_meta[ self::META_WEBSITE ] ) ? $post_meta[ self::META_WEBSITE ] : '',
			'video_url'                => isset( $post_meta[ self::META_VIDEO ] ) ? $post_meta[ self::META_VIDEO ] : '',
			'tagline'                  => isset( $post_meta[ self::META_TAGLINE ] ) ? $post_meta[ self::META_TAGLINE ] : '',
			'price_range'              => isset( $post_meta[ self::META_PRICE_RANGE ] ) ? $post_meta[ self::META_PRICE_RANGE ] : '',
		);

		// Process expiration.
		if ( ! empty( $post_meta[ self::META_EXPIRY_DATE ] ) && ( ! isset( $post_meta[ self::META_EXPIRY_NEVER ] ) || $post_meta[ self::META_EXPIRY_NEVER ] !== 'yes' ) ) {
			$listing_data['expire_date'] = date( 'Y-m-d', strtotime( $post_meta[ self::META_EXPIRY_DATE ] ) );
		}

		// Process social profiles — map to individual GD social URL fields.
		$social_links = $this->parse_social_links( $post_meta );
		foreach ( $social_links as $platform => $url ) {
			$listing_data[ $platform ] = $url;
		}

		// Process business hours.
		$business_hours = $this->format_business_hours( $post_meta );
		if ( ! empty( $business_hours ) ) {
			$listing_data['business_hours'] = $business_hours;
		}

		// Process dynamic custom fields.
		$custom_fields = $this->process_custom_attributes( $post, $post_meta );
		if ( ! empty( $custom_fields ) ) {
			$core_fields = array(
				'post_author', 'post_title', 'post_content', 'post_status', 'post_type',
				'post_name', 'post_date', 'default_category', 'latitude', 'longitude',
				'phone', 'email', 'fax', 'website', 'video_url', 'tagline', 'featured', 'price_range',
				'business_hours', 'facebook', 'twitter', 'instagram', 'youtube', 'linkedin', 'pinterest', 'whatsapp',
			);

			foreach ( $custom_fields as $key => $value ) {
				if ( in_array( $key, $core_fields, true ) && ! empty( $listing_data[ $key ] ) ) {
					continue;
				}
				$listing_data[ $key ] = $value;
			}
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
		$listing_data['post_images'] = $this->get_post_images( $post->ID, $post_meta );

		// Insert or update the post.
		if ( $is_update ) {
			$gd_post_id = wp_update_post( array_merge( array( 'ID' => $gd_post_id ), $listing_data ), true );
		} else {
			$gd_post_id = wp_insert_post( $listing_data, true );
		}

		if ( is_wp_error( $gd_post_id ) ) {
			$this->log( $gd_post_id->get_error_message() );
			return self::IMPORT_STATUS_FAILED;
		}

		// Import comments/reviews.
		$this->import_comments( $post->ID, $gd_post_id );

		return $is_update ? self::IMPORT_STATUS_UPDATED : self::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Parse and batch events for background import.
	 *
	 * @since 2.1.6
	 *
	 * @param array $task The task to import.
	 * @return array Result of the import operation.
	 */
	public function task_parse_events( array $task ) {
		global $wpdb;

		if ( ! class_exists( 'GeoDir_Event_Manager' ) ) {
			$this->log( __( 'Events addon not active. Skipping events...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$event_types = $this->get_event_post_types();
		if ( empty( $event_types ) ) {
			$this->log( __( 'No event directory types found. Skipping events.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		$offset       = isset( $task['offset'] ) ? (int) $task['offset'] : 0;
		$total_events = isset( $task['total_events'] ) ? (int) $task['total_events'] : 0;
		$batch_size   = (int) $this->get_batch_size();

		if ( ! isset( $task['total_events'] ) ) {
			$total_events         = $this->count_events();
			$task['total_events'] = $total_events;
		}

		if ( 0 === $offset ) {
			/* translators: %d: number of events */
			$this->log( sprintf( __( 'Starting events parsing process: %d events found.', 'geodir-converter' ), $total_events ) );
		}

		if ( 0 === $total_events ) {
			$this->log( __( 'No events found for parsing. Skipping process.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		wp_suspend_cache_addition( true );

		$type_placeholders   = implode( ',', array_fill( 0, count( $event_types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) );

		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_status
				FROM {$wpdb->posts}
				WHERE post_type IN ({$type_placeholders})
				AND post_status IN ({$status_placeholders})
				LIMIT %d OFFSET %d",
				array_merge(
					$event_types,
					$this->post_statuses,
					array( $batch_size, $offset )
				)
			)
		);

		wp_suspend_cache_addition( false );

		if ( empty( $events ) ) {
			$this->log( __( 'Events parsing completed. No more events found.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$batched_tasks = array_chunk( $events, $this->batch_size, true );
		$import_tasks  = array();
		foreach ( $batched_tasks as $batch ) {
			$import_tasks[] = array(
				'action' => self::ACTION_IMPORT_EVENTS,
				'events' => $batch,
			);
		}

		$this->background_process->add_import_tasks( $import_tasks );

		$complete = ( $offset + $batch_size >= $total_events );

		if ( ! $complete ) {
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		return $this->next_task( $task, true );
	}

	/**
	 * Import a batch of events (called by background process).
	 *
	 * @since 2.1.6
	 *
	 * @param array $task The task to import.
	 * @return bool Result of the import operation.
	 */
	public function task_import_events( $task ) {
		$events = isset( $task['events'] ) && ! empty( $task['events'] ) ? (array) $task['events'] : array();

		foreach ( $events as $event ) {
			$title  = $event->post_title;
			$status = $this->import_single_event( $event );

			$this->process_import_result( $status, 'event', $title, $event->ID, self::ACTION_IMPORT_EVENTS );
		}

		$this->flush_failed_items();

		return false;
	}

	/**
	 * Convert a single aDirectory event to GeoDirectory event format.
	 *
	 * @since 2.1.6
	 *
	 * @param object $event The post object to convert.
	 * @return int Import status.
	 */
	private function import_single_event( $event ) {
		$post = get_post( $event->ID );

		if ( ! $post ) {
			return self::IMPORT_STATUS_FAILED;
		}

		$post_type        = self::GD_POST_TYPE_EVENT;
		$gd_event_id      = ! $this->is_test_mode() ? $this->get_gd_listing_id( $post->ID, $this->importer_id . '_id', $post_type ) : false;
		$is_update        = ! empty( $gd_event_id );
		$post_meta        = $this->get_post_meta( $post->ID );
		$default_location = $this->get_default_location();

		// Get categories.
		$categories = $this->get_listings_terms( $post->ID, self::TAX_CATEGORY );

		// Get tags.
		$tags = $this->get_listings_terms( $post->ID, self::TAX_TAGS );

		// Location data.
		$location  = $default_location;
		$latitude  = isset( $post_meta[ self::META_MAP_LAT ] ) ? $post_meta[ self::META_MAP_LAT ] : '';
		$longitude = isset( $post_meta[ self::META_MAP_LON ] ) ? $post_meta[ self::META_MAP_LON ] : '';
		$address   = isset( $post_meta[ self::META_ADDRESS ] ) ? $post_meta[ self::META_ADDRESS ] : '';

		$location['latitude']  = $latitude;
		$location['longitude'] = $longitude;
		$location = $this->geocode_location( $latitude, $longitude, $location, $post->ID );

		// Map post status.
		$post_status = $post->post_status;
		if ( $post_status === 'expired' ) {
			$post_status = 'draft';
		}

		// Extract event-specific date/time fields from custom field meta.
		$event_dates = $this->extract_event_dates( $post_meta );

		// Build the event data.
		$event_data = array(
			// Standard WP Fields.
			'post_author'              => $post->post_author ? $post->post_author : $this->get_import_setting( 'wp_author_id', \get_current_user_id() ),
			'post_title'               => $post->post_title,
			'post_content'             => $post->post_content ? $post->post_content : '',
			'post_content_filtered'    => $post->post_content,
			'post_excerpt'             => $post->post_excerpt ? $post->post_excerpt : '',
			'post_status'              => $post_status,
			'post_type'                => $post_type,
			'comment_status'           => $post->comment_status,
			'ping_status'              => $post->ping_status,
			'post_name'                => $post->post_name ? $post->post_name : 'event-' . $post->ID,
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

			'street'                   => $address,
			'street2'                  => '',
			'city'                     => isset( $location['city'] ) ? $location['city'] : '',
			'region'                   => isset( $location['region'] ) ? $location['region'] : '',
			'country'                  => isset( $location['country'] ) ? $location['country'] : '',
			'zip'                      => isset( $post_meta[ self::META_ZIP ] ) ? $post_meta[ self::META_ZIP ] : ( isset( $location['zip'] ) ? $location['zip'] : '' ),
			'latitude'                 => isset( $location['latitude'] ) ? $location['latitude'] : '',
			'longitude'                => isset( $location['longitude'] ) ? $location['longitude'] : '',
			'mapview'                  => '',
			'mapzoom'                  => '',

			// aDirectory ID for dedup.
			$this->importer_id . '_id' => $post->ID,

			// Event dates.
			'event_dates'              => $event_dates,

			// Standard fields.
			'phone'                    => isset( $post_meta[ self::META_PHONE ] ) ? $post_meta[ self::META_PHONE ] : '',
			'email'                    => isset( $post_meta[ self::META_EMAIL ] ) ? $post_meta[ self::META_EMAIL ] : '',
			'website'                  => isset( $post_meta[ self::META_WEBSITE ] ) ? $post_meta[ self::META_WEBSITE ] : '',
		);

		// Process social profiles.
		$social_links = $this->parse_social_links( $post_meta );
		foreach ( $social_links as $platform => $url ) {
			$event_data[ $platform ] = $url;
		}

		// Map ticket URL if available.
		$ticket_url = $this->get_event_meta_value( $post_meta, 'ticket_url', 'url' );
		if ( ! empty( $ticket_url ) ) {
			$event_data['event_reg_url'] = $ticket_url;
		}

		// Handle test mode.
		if ( $this->is_test_mode() ) {
			return self::IMPORT_STATUS_SUCCESS;
		}

		// Delete existing media if updating.
		if ( $is_update ) {
			GeoDir_Media::delete_files( (int) $gd_event_id, 'post_images' );
		}

		// Process gallery images.
		$event_data['post_images'] = $this->get_post_images( $post->ID, $post_meta );

		// Insert or update the post.
		if ( $is_update ) {
			$gd_event_id = wp_update_post( array_merge( array( 'ID' => $gd_event_id ), $event_data ), true );
		} else {
			$gd_event_id = wp_insert_post( $event_data, true );
		}

		if ( is_wp_error( $gd_event_id ) ) {
			$this->log( $gd_event_id->get_error_message() );
			return self::IMPORT_STATUS_FAILED;
		}

		// Import comments/reviews.
		$this->import_comments( $post->ID, $gd_event_id );

		return $is_update ? self::IMPORT_STATUS_UPDATED : self::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Extract event date/time fields from aDirectory post meta.
	 *
	 * Searches for custom fields with fieldid containing 'event_date', 'event_time',
	 * 'event_end_date' to build GD's event_dates array.
	 *
	 * @since 2.1.6
	 *
	 * @param array $post_meta Post meta data.
	 * @return array GeoDirectory event_dates array.
	 */
	private function extract_event_dates( $post_meta ) {
		$start_date = '';
		$start_time = '00:00';
		$end_date   = '';
		$end_time   = '23:59';

		// Look for known event meta key patterns.
		$start_date = $this->get_event_meta_value( $post_meta, 'event_date', 'date' );
		$start_time_raw = $this->get_event_meta_value( $post_meta, 'event_time', 'time' );
		$end_date   = $this->get_event_meta_value( $post_meta, 'event_end_date', 'date' );

		if ( ! empty( $start_time_raw ) ) {
			$converted = $this->convert_to_24h( $start_time_raw );
			$start_time = ! empty( $converted ) ? $converted : '00:00';
		}

		// Use start date as end date if not set.
		if ( empty( $end_date ) && ! empty( $start_date ) ) {
			$end_date = $start_date;
		}

		return array(
			'recurring'       => 0,
			'start_date'      => $start_date,
			'end_date'        => $end_date,
			'all_day'         => 0,
			'start_time'      => $start_time,
			'end_time'        => $end_time,
			'duration_x'      => '',
			'repeat_type'     => 'custom',
			'repeat_x'        => '',
			'repeat_end_type' => '',
			'max_repeat'      => '',
			'recurring_dates' => '',
			'different_times' => '',
			'start_times'     => '',
			'end_times'       => '',
			'repeat_days'     => '',
			'repeat_weeks'    => '',
		);
	}

	/**
	 * Get an event-related meta value by searching for fieldid patterns.
	 *
	 * aDirectory stores custom field values as `_{type}_{fieldid}`, e.g.,
	 * `_date_cf_event_date`, `_time_cf_event_time`, `_url_cf_ticket_url`.
	 *
	 * @since 2.1.6
	 *
	 * @param array  $post_meta    Post meta data.
	 * @param string $fieldid_part The fieldid part to search for (e.g., 'event_date').
	 * @param string $type         The expected field type prefix (e.g., 'date', 'time', 'url').
	 * @return string The meta value, or empty string if not found.
	 */
	private function get_event_meta_value( $post_meta, $fieldid_part, $type ) {
		// First try the exact pattern: _{type}_cf_{fieldid_part}
		$key = "_{$type}_cf_{$fieldid_part}";
		if ( isset( $post_meta[ $key ] ) && '' !== $post_meta[ $key ] ) {
			return $post_meta[ $key ];
		}

		// Fallback: search for any meta key containing the fieldid part.
		foreach ( $post_meta as $meta_key => $meta_value ) {
			if ( false !== strpos( $meta_key, $fieldid_part ) && false !== strpos( $meta_key, "_{$type}_" ) ) {
				return $meta_value;
			}
		}

		return '';
	}

	/**
	 * Parse social links from aDirectory meta into individual platform URLs.
	 *
	 * Maps aDirectory social media link entries (with icon classes like 'fab fa-facebook')
	 * to individual GeoDirectory social fields (facebook, twitter, instagram, etc.).
	 *
	 * @since 2.1.6
	 *
	 * @param array $post_meta Post meta data.
	 * @return array Associative array of platform_key => URL pairs.
	 */
	private function parse_social_links( $post_meta ) {
		if ( empty( $post_meta[ self::META_SOCIAL ] ) ) {
			return array();
		}

		$links = $post_meta[ self::META_SOCIAL ];

		if ( is_string( $links ) ) {
			$links = json_decode( $links, true );
		}

		if ( ! is_array( $links ) ) {
			return array();
		}

		// Map Font Awesome icon classes to platform keys.
		$icon_to_platform = array(
			'facebook'  => 'facebook',
			'twitter'   => 'twitter',
			'instagram' => 'instagram',
			'youtube'   => 'youtube',
			'linkedin'  => 'linkedin',
			'pinterest' => 'pinterest',
			'whatsapp'  => 'whatsapp',
		);

		// Also detect platforms from URL patterns as fallback.
		$url_patterns = array(
			'facebook.com'  => 'facebook',
			'twitter.com'   => 'twitter',
			'x.com'         => 'twitter',
			'instagram.com' => 'instagram',
			'youtube.com'   => 'youtube',
			'linkedin.com'  => 'linkedin',
			'pinterest.com' => 'pinterest',
			'whatsapp'      => 'whatsapp',
		);

		$result = array();

		foreach ( $links as $link ) {
			if ( ! is_array( $link ) || empty( $link['url'] ) ) {
				continue;
			}

			$platform = '';

			// First try to detect from icon class (e.g., 'fab fa-facebook' -> 'facebook').
			if ( ! empty( $link['icon'] ) ) {
				foreach ( $icon_to_platform as $keyword => $platform_key ) {
					if ( false !== strpos( $link['icon'], $keyword ) ) {
						$platform = $platform_key;
						break;
					}
				}
			}

			// Fallback: detect from URL.
			if ( empty( $platform ) ) {
				foreach ( $url_patterns as $pattern => $platform_key ) {
					if ( false !== strpos( $link['url'], $pattern ) ) {
						$platform = $platform_key;
						break;
					}
				}
			}

			// Only assign to known platforms, skip duplicates.
			if ( ! empty( $platform ) && ! isset( $result[ $platform ] ) ) {
				$result[ $platform ] = $link['url'];
			}
		}

		return $result;
	}

	/**
	 * Format business hours from aDirectory meta into GeoDirectory business_hours JSON format.
	 *
	 * GeoDirectory expects: ["Mo 09:00-17:00","Tu 09:00-17:00"],["UTC":"+5.5"]
	 *
	 * @since 2.1.6
	 *
	 * @param array $post_meta Post meta data.
	 * @return string GeoDirectory business_hours JSON string, or empty string.
	 */
	private function format_business_hours( $post_meta ) {
		if ( empty( $post_meta[ self::META_BUSINESS ] ) ) {
			return '';
		}

		$data = maybe_unserialize( $post_meta[ self::META_BUSINESS ] );

		if ( ! is_array( $data ) ) {
			return '';
		}

		$status = isset( $data['status'] ) ? $data['status'] : '';

		if ( $status === 'hide_b_h' || empty( $status ) ) {
			return '';
		}

		// Map aDirectory day keys to GeoDirectory abbreviations.
		$day_abbr_map = array(
			'monday'    => 'Mo',
			'tuesday'   => 'Tu',
			'wednesday' => 'We',
			'thursday'  => 'Th',
			'friday'    => 'Fr',
			'saturday'  => 'Sa',
			'sunday'    => 'Su',
		);

		$days_parts = array();

		if ( $status === 'open_twenty_four' ) {
			// Open 24/7 — all days 00:00-00:00.
			foreach ( $day_abbr_map as $day_key => $abbr ) {
				$days_parts[] = $abbr . ' 00:00-00:00';
			}
		} else {
			// open_specific — parse per-day data.
			foreach ( $day_abbr_map as $day_key => $abbr ) {
				if ( ! isset( $data[ $day_key ] ) || ! is_array( $data[ $day_key ] ) ) {
					continue;
				}

				$day_data = $data[ $day_key ];

				if ( empty( $day_data['enable'] ) || $day_data['enable'] !== 'on' ) {
					continue; // Day is closed, skip it.
				}

				// Per-day 24-hour flag.
				if ( ! empty( $day_data['open_24'] ) && $day_data['open_24'] === 'on' ) {
					$days_parts[] = $abbr . ' 00:00-00:00';
					continue;
				}

				// Collect time slots for this day.
				$hours = array();
				foreach ( $day_data as $key => $slot ) {
					if ( is_numeric( $key ) && is_array( $slot ) && isset( $slot['open'], $slot['close'] ) ) {
						$open  = $this->convert_to_24h( $slot['open'] );
						$close = $this->convert_to_24h( $slot['close'] );
						if ( $open && $close ) {
							$hours[] = $open . '-' . $close;
						}
					}
				}

				if ( ! empty( $hours ) ) {
					$days_parts[] = $abbr . ' ' . implode( ',', $hours );
				}
			}
		}

		if ( empty( $days_parts ) ) {
			return '';
		}

		// Build GeoDirectory JSON string format.
		$offset = get_option( 'gmt_offset', 0 );
		$result = '["' . implode( '","', $days_parts ) . '"]';
		$result .= ',["UTC":"' . $offset . '"]';

		// Sanitize using GeoDirectory function if available.
		if ( function_exists( 'geodir_sanitize_business_hours' ) ) {
			$result = geodir_sanitize_business_hours( $result );
		}

		return $result;
	}

	/**
	 * Convert 12-hour time format (e.g., "09:00 AM") to 24-hour format (e.g., "09:00").
	 *
	 * @since 2.1.6
	 *
	 * @param string $time Time string in 12h or 24h format.
	 * @return string Time in 24-hour HH:MM format, or empty string on failure.
	 */
	private function convert_to_24h( $time ) {
		$time = trim( $time );

		// Already in 24h format (e.g., "09:00" or "17:30").
		if ( preg_match( '/^\d{1,2}:\d{2}$/', $time ) ) {
			return $time;
		}

		// 12h format (e.g., "09:00 AM", "02:30 PM").
		$timestamp = strtotime( $time );
		if ( false === $timestamp ) {
			return '';
		}

		return date( 'H:i', $timestamp );
	}

	/**
	 * Process custom attributes from aDirectory field definitions.
	 *
	 * @since 2.1.6
	 *
	 * @param object $post      Post object.
	 * @param array  $post_meta Post meta data.
	 * @return array Processed custom fields.
	 */
	private function process_custom_attributes( $post, $post_meta ) {
		$fields = array();

		// Build a mapping of field_key => meta_key from discovered fields.
		$custom_fields = $this->get_custom_fields();
		$meta_map      = array();

		foreach ( $custom_fields as $field ) {
			if ( isset( $field['_adqs_meta'] ) ) {
				$meta_map[ $field['field_key'] ] = $field['_adqs_meta'];
			}
		}

		// Map each dynamic field.
		foreach ( $meta_map as $field_key => $meta_key ) {
			if ( isset( $post_meta[ $meta_key ] ) && ( ! empty( $post_meta[ $meta_key ] ) || $post_meta[ $meta_key ] === '0' ) ) {
				$value = $post_meta[ $meta_key ];

				// Handle serialized data.
				if ( is_string( $value ) && is_serialized( $value ) ) {
					$value = maybe_unserialize( $value );
				}

				// Handle arrays.
				if ( is_array( $value ) ) {
					$value = implode( ',', array_map( 'trim', $value ) );
				}

				// Handle boolean values.
				if ( is_bool( $value ) ) {
					$value = $value ? 1 : 0;
				}

				$fields[ $field_key ] = $value;
			}
		}

		return $fields;
	}

	/**
	 * Get post images from aDirectory gallery.
	 *
	 * @since 2.1.6
	 *
	 * @param int   $post_id   The post ID.
	 * @param array $post_meta Post meta data.
	 * @return string Formatted gallery images string for GeoDirectory.
	 */
	private function get_post_images( $post_id, $post_meta = array() ) {
		$images         = array();
		$attachment_ids = array();

		// Get featured image.
		if ( has_post_thumbnail( $post_id ) ) {
			$attachment_ids[] = get_post_thumbnail_id( $post_id );
		}

		// Get gallery from _images meta (array of attachment IDs).
		$gallery = isset( $post_meta[ self::META_IMAGES ] ) ? $post_meta[ self::META_IMAGES ] : '';

		if ( ! empty( $gallery ) ) {
			if ( is_string( $gallery ) && is_serialized( $gallery ) ) {
				$gallery = maybe_unserialize( $gallery );
			}

			if ( is_array( $gallery ) ) {
				foreach ( $gallery as $att_id ) {
					if ( is_numeric( $att_id ) ) {
						$attachment_ids[] = (int) $att_id;
					}
				}
			}
		}

		// Get attached images as fallback.
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
	 * @since 2.1.6
	 *
	 * @param int $post_id The post ID.
	 * @return string The featured image URL.
	 */
	private function get_featured_image( $post_id ) {
		$image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
		return isset( $image[0] ) ? esc_url( $image[0] ) : '';
	}

	/**
	 * Get listings terms (categories or tags) with GD equivalent mapping.
	 *
	 * @since 2.1.6
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $taxonomy The taxonomy to get terms from.
	 * @return array Array of GD term IDs.
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

		// Collect all GD term IDs that need validation.
		$candidate_ids = array();
		foreach ( $terms as $term ) {
			$gd_term_id = (int) $term->gd_equivalent;
			if ( $gd_term_id ) {
				$candidate_ids[] = $gd_term_id;
			}
		}

		if ( empty( $candidate_ids ) ) {
			return array();
		}

		// Batch validate all GD term IDs in a single query.
		$placeholders = implode( ',', array_fill( 0, count( $candidate_ids ), '%d' ) );
		$valid_ids    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->terms} WHERE term_id IN ({$placeholders})",
				$candidate_ids
			)
		);

		$valid_ids_map = array_flip( array_map( 'intval', $valid_ids ) );
		$gd_terms      = array();

		foreach ( $candidate_ids as $id ) {
			if ( isset( $valid_ids_map[ $id ] ) ) {
				$gd_terms[] = $id;
			}
		}

		return $gd_terms;
	}

	/**
	 * Import comments/reviews from aDirectory listing to GeoDirectory listing.
	 *
	 * @since 2.1.6
	 *
	 * @param int $ad_listing_id aDirectory listing ID.
	 * @param int $gd_post_id    GeoDirectory post ID.
	 * @return void
	 */
	private function import_comments( $ad_listing_id, $gd_post_id ) {
		global $wpdb;

		if ( $this->is_test_mode() ) {
			return;
		}

		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_post_ID, comment_author, comment_author_email,
				        comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
				        comment_content, comment_karma, comment_approved, comment_agent,
				        comment_type, comment_parent, user_id
				FROM {$wpdb->comments}
				WHERE comment_post_ID = %d AND comment_approved = '1'",
				$ad_listing_id
			)
		);

		if ( empty( $comments ) ) {
			return;
		}

		foreach ( $comments as $comment ) {
			// Check if comment already exists on GD post.
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

			// Reassign comment to GD post.
			$wpdb->update(
				$wpdb->comments,
				array( 'comment_post_ID' => $gd_post_id ),
				array( 'comment_ID' => $comment->comment_ID ),
				array( '%d' ),
				array( '%d' )
			);

			// Handle aDirectory rating (1-5 scale, same as GD).
			$rating = get_comment_meta( $comment->comment_ID, 'adqs_review_rating', true );

			if ( $rating && class_exists( 'GeoDir_Comments' ) ) {
				$gd_rating = max( 1, min( 5, (int) $rating ) );
				$_REQUEST['geodir_overallrating'] = $gd_rating;
				GeoDir_Comments::save_rating( $comment->comment_ID );
				unset( $_REQUEST['geodir_overallrating'] );
			}
		}

		// Update comment count.
		wp_update_comment_count( $gd_post_id );
	}
}
