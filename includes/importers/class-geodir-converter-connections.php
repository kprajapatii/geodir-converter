<?php
/**
 * Connections Converter Class.
 *
 * @since     2.2.0
 * @package   GeoDir_Converter
 */

namespace GeoDir_Converter\Importers;

use WP_Error;
use GeoDir_Media;
use GeoDir_Converter\GeoDir_Converter_Utils;
use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Main converter class for importing from Connections.
 *
 * @since 2.2.0
 */
class GeoDir_Converter_Connections extends GeoDir_Converter_Importer {

	/**
	 * Post type identifier for entries.
	 *
	 * @var string
	 */
	const POST_TYPE_ENTRY = 'connections';

	/**
	 * Taxonomy identifier for entry categories within Connections.
	 *
	 * @var string
	 */
	const TAX_ENTRY_CATEGORY = 'category';

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
	protected $importer_id = 'connections';

	/**
	 * The import listing status IDs.
	 *
	 * @var array
	 */
	protected $post_statuses = array( 'approved', 'pending' );

	/**
	 * Batch size for processing items.
	 *
	 * @var int
	 */
	private $batch_size = 50;

	/**
	 * Cached map of Connections term IDs to GeoDirectory term IDs.
	 *
	 * @var array|null
	 */
	private $term_map = null;

	/**
	 * Supported Connections entry types.
	 *
	 * @var array
	 */
	private $entry_types = array( 'individual', 'organization', 'family', 'connection_group' );

	/**
	 * Supported Connections visibility options.
	 *
	 * @var array
	 */
	private $visibility_options = array( 'public', 'private', 'unlisted' );

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
		return __( 'Connections', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Import entries from your Connections installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @return string
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/connections.webp';
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
			<h6 class="fs-base"><?php esc_html_e( 'Connections Importer Settings', 'geodir-converter' ); ?></h6>

			<?php
			if ( ! $this->is_connections_available() ) {
				$this->render_plugin_notice(
					__( 'Connections Business Directory', 'geodir-converter' ),
					__( 'entries', 'geodir-converter' ),
					'https://wordpress.org/plugins/connections/'
				);
			}

			$this->display_post_type_select();
			$this->display_entry_type_select();
			$this->display_visibility_select();
			$this->display_status_select();
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

		$settings['connections_entry_types'] = $this->sanitize_filter_values(
			isset( $settings['connections_entry_types'] ) ? (array) $settings['connections_entry_types'] : array(),
			$this->entry_types
		);

		$settings['connections_visibility'] = $this->sanitize_filter_values(
			isset( $settings['connections_visibility'] ) ? (array) $settings['connections_visibility'] : array(),
			$this->visibility_options
		);

		$settings['connections_statuses'] = $this->sanitize_filter_values(
			isset( $settings['connections_statuses'] ) ? (array) $settings['connections_statuses'] : array(),
			$this->post_statuses
		);

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'invalid_import_settings', implode( '<br>', $errors ) );
		}

		return $settings;
	}

	/**
	 * Sanitize selected filter values.
	 *
	 * @param array $values  Submitted values.
	 * @param array $allowed Allowed options.
	 * @return array
	 */
	private function sanitize_filter_values( array $values, array $allowed ) {
		$values = array_map( 'sanitize_text_field', $values );
		$values = array_intersect( $values, $allowed );

		return array_values( $values );
	}

	/**
	 * Get selected entry types.
	 *
	 * @return array
	 */
	private function get_selected_entry_types() {
		$selected = $this->get_import_setting( 'connections_entry_types', array() );
		$selected = is_array( $selected ) ? $selected : array();

		return $this->sanitize_filter_values( $selected, $this->entry_types );
	}

	/**
	 * Get selected statuses.
	 *
	 * @return array
	 */
	private function get_selected_statuses() {
		$selected = $this->get_import_setting( 'connections_statuses', array() );
		$selected = is_array( $selected ) ? $selected : array();

		return $this->sanitize_filter_values( $selected, $this->post_statuses );
	}

	/**
	 * Get selected visibility settings.
	 *
	 * @return array
	 */
	private function get_selected_visibility() {
		$selected = $this->get_import_setting( 'connections_visibility', array() );
		$selected = is_array( $selected ) ? $selected : array();

		return $this->sanitize_filter_values( $selected, $this->visibility_options );
	}

	/**
	 * Display entry type selector.
	 */
	private function display_entry_type_select() {
		$options = array();

		foreach ( $this->entry_types as $type ) {
			$options[ $type ] = ucwords( str_replace( '_', ' ', $type ) );
		}

		aui()->select(
			array(
				'id'          => 'connections_entry_types',
				'name'        => 'connections_entry_types[]',
				'label'       => esc_html__( 'Entry Types', 'geodir-converter' ),
				'label_type'  => 'top',
				'label_class' => 'font-weight-bold fw-bold',
				'value'       => (array) $this->get_import_setting( 'connections_entry_types', array() ),
				'options'     => $options,
				'select2'     => true,
				'multiple'    => true,
				'placeholder' => esc_html__( 'All entry types', 'geodir-converter' ),
				'help_text'   => esc_html__( 'Choose which Connections entry types to import. Leave empty to import all types.', 'geodir-converter' ),
			),
			true
		);
	}

	/**
	 * Display visibility selector.
	 */
	private function display_visibility_select() {
		$options = array();

		foreach ( $this->visibility_options as $visibility ) {
			$options[ $visibility ] = ucwords( $visibility );
		}

		aui()->select(
			array(
				'id'          => 'connections_visibility',
				'name'        => 'connections_visibility[]',
				'label'       => esc_html__( 'Visibility', 'geodir-converter' ),
				'label_type'  => 'top',
				'label_class' => 'font-weight-bold fw-bold',
				'value'       => (array) $this->get_import_setting( 'connections_visibility', array() ),
				'options'     => $options,
				'select2'     => true,
				'multiple'    => true,
				'placeholder' => esc_html__( 'All visibility levels', 'geodir-converter' ),
				'help_text'   => esc_html__( 'Limit imports to specific visibility settings. Leave empty to import all.', 'geodir-converter' ),
			),
			true
		);
	}

	/**
	 * Display status selector.
	 */
	private function display_status_select() {
		$options = array(
			'approved' => esc_html__( 'Approved', 'geodir-converter' ),
			'pending'  => esc_html__( 'Pending', 'geodir-converter' ),
		);

		aui()->select(
			array(
				'id'          => 'connections_statuses',
				'name'        => 'connections_statuses[]',
				'label'       => esc_html__( 'Entry Status', 'geodir-converter' ),
				'label_type'  => 'top',
				'label_class' => 'font-weight-bold fw-bold',
				'value'       => (array) $this->get_import_setting( 'connections_statuses', array() ),
				'options'     => $options,
				'select2'     => true,
				'multiple'    => true,
				'placeholder' => esc_html__( 'All statuses', 'geodir-converter' ),
				'help_text'   => esc_html__( 'Select the Connections entry statuses to import. Leave empty to include all statuses.', 'geodir-converter' ),
			),
			true
		);
	}

	/**
	 * Determine if Connections data tables are available.
	 *
	 * @return bool
	 */
	private function is_connections_available() {
		return defined( 'CN_ENTRY_TABLE' ) && defined( 'CN_TERMS_TABLE' ) && defined( 'CN_TERM_TAXONOMY_TABLE' );
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
	 * Calculate the total number of items to be imported.
	 */
	public function set_import_total() {
		if ( ! $this->is_connections_available() ) {
			return;
		}

		$total_items  = 0;
		$total_items += $this->count_connection_categories();
		$total_items += count( $this->get_custom_fields() );
		$total_items += $this->count_listings();

		$this->increase_imports_total( (int) $total_items );
	}

	/**
	 * Count available Connections categories.
	 *
	 * @return int
	 */
	private function count_connection_categories() {
		if ( ! $this->is_connections_available() ) {
			return 0;
		}

		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . CN_TERM_TAXONOMY_TABLE . ' WHERE taxonomy = %s',
				self::TAX_ENTRY_CATEGORY
			)
		);

		return (int) $count;
	}

	/**
	 * Retrieve Connections categories.
	 *
	 * @return array
	 */
	private function get_connections_categories() {
		if ( ! $this->is_connections_available() ) {
			return array();
		}

		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.term_id, t.name, t.slug, tt.description, tt.parent
				FROM ' . CN_TERMS_TABLE . ' t
				INNER JOIN ' . CN_TERM_TAXONOMY_TABLE . ' tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				ORDER BY tt.parent ASC, t.term_id ASC',
				self::TAX_ENTRY_CATEGORY
			)
		);
	}

	/**
	 * Reset persisted term map.
	 */
	private function reset_term_map() {
		$this->term_map = array();
		$this->options_handler->update_option( 'connections_term_map', array() );
	}

	/**
	 * Get persisted term map.
	 *
	 * @return array
	 */
	private function get_term_map() {
		if ( null === $this->term_map ) {
			$this->term_map = (array) $this->options_handler->get_option_no_cache( 'connections_term_map', array() );
		}

		return $this->term_map;
	}

	/**
	 * Retrieve mapped GeoDirectory term ID for a Connections term ID.
	 *
	 * @param int $source_id Connections term ID.
	 * @return int
	 */
	private function get_mapped_term_id( $source_id ) {
		$map = $this->get_term_map();

		return isset( $map[ $source_id ] ) ? (int) $map[ $source_id ] : 0;
	}

	/**
	 * Persist Connections -> GeoDirectory term mapping.
	 *
	 * @param int $source_id Connections term ID.
	 * @param int $target_id GeoDirectory term ID.
	 */
	private function set_term_map_value( $source_id, $target_id ) {
		$map               = $this->get_term_map();
		$map[ $source_id ] = (int) $target_id;
		$this->term_map    = $map;

		$this->options_handler->update_option( 'connections_term_map', $map );
	}

	/**
	 * Import categories from Connections to GeoDirectory.
	 *
	 * @since 2.2.0
	 * @param array $task Import task.
	 *
	 * @return array Result of the import operation.
	 */
	public function task_import_categories( $task ) {
		if ( ! $this->is_connections_available() ) {
			$this->log( esc_html__( 'Connections tables not found. Skipping category import.', 'geodir-converter' ), 'error' );
			return $this->next_task( $task );
		}

		$this->set_import_total();

		$this->log( esc_html__( 'Categories: Import started.', 'geodir-converter' ) );

		$categories = $this->get_connections_categories();

		if ( empty( $categories ) ) {
			$this->log( esc_html__( 'Categories: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		if ( $this->is_test_mode() ) {
			$this->log(
				sprintf(
					/* translators: %d: number of imported categories. */
					esc_html__( 'Categories: Import completed. %1$d imported in test mode.', 'geodir-converter' ),
					count( $categories )
				),
				'success'
			);
			return $this->next_task( $task );
		}

		$this->reset_term_map();

		$post_type = $this->get_import_post_type();
		$taxonomy  = "{$post_type}category";

		$imported = 0;
		$failed   = 0;

		foreach ( $categories as $category ) {
			$slug   = ! empty( $category->slug ) ? sanitize_title( $category->slug ) : sanitize_title( $category->name . '-' . $category->term_id );
			$parent = (int) $category->parent;

			$parent_id     = 0;
			$parent_mapped = $parent ? $this->get_mapped_term_id( $parent ) : 0;
			if ( $parent_mapped ) {
				$parent_id = $parent_mapped;
			}

			$args = array(
				'description' => isset( $category->description ) ? wp_kses_post( $category->description ) : '',
				'slug'        => $slug,
			);

			if ( $parent_id ) {
				$args['parent'] = $parent_id;
			}

			$term = term_exists( $slug, $taxonomy );

			if ( $term ) {
				$new_term_id = is_array( $term ) ? $term['term_id'] : $term;
				if ( $parent_id ) {
					wp_update_term( $new_term_id, $taxonomy, array( 'parent' => $parent_id ) );
				}
			} else {
				$term = wp_insert_term( $category->name, $taxonomy, $args );

				if ( is_wp_error( $term ) ) {
					++$failed;
					$this->log(
						sprintf(
							/* translators: 1: category name, 2: error message. */
							esc_html__( 'Failed to import category "%1$s": %2$s', 'geodir-converter' ),
							esc_html( $category->name ),
							esc_html( $term->get_error_message() )
						),
						'error'
					);
					continue;
				}

				$new_term_id = $term['term_id'];
				++$imported;
			}

			$this->set_term_map_value( (int) $category->term_id, (int) $new_term_id );
		}

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );

		$this->log(
			sprintf(
				/* translators: 1: number imported, 2: number failed. */
				esc_html__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$imported,
				$failed
			),
			$failed ? 'warning' : 'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Get custom fields for Connections entries.
	 *
	 * @return array The custom fields.
	 */
	private function get_custom_fields() {
		$post_type   = $this->get_import_post_type();
		$definitions = array_merge(
			$this->get_base_connection_fields(),
			$this->get_predefined_field_definitions()
		);

		if ( $this->is_connections_available() ) {
			$definitions = array_merge( $definitions, $this->get_dynamic_field_definitions() );
		}

		$fields = array();

		foreach ( $definitions as $definition ) {
			$this->maybe_add_field_definition( $fields, $post_type, $definition );
		}

		return $fields;
	}

	/**
	 * Returns static Connections-specific field definitions.
	 *
	 * @return array
	 */
	private function get_base_connection_fields() {
		return array(
			array(
				'type'           => 'number',
				'field_key'      => $this->importer_id . '_id',
				'label'          => __( 'Connections ID', 'geodir-converter' ),
				'description'    => __( 'Original Connections entry ID.', 'geodir-converter' ),
				'placeholder'    => __( 'Connections ID', 'geodir-converter' ),
				'icon'           => 'far fa-id-card',
				'only_for_admin' => 1,
				'required'       => 0,
			),
			array(
				'type'        => 'text',
				'field_key'   => 'connections_entry_type',
				'label'       => __( 'Entry Type', 'geodir-converter' ),
				'description' => __( 'Original Connections entry type.', 'geodir-converter' ),
				'icon'        => 'fas fa-layer-group',
			),
			array(
				'type'        => 'text',
				'field_key'   => 'connections_visibility',
				'label'       => __( 'Visibility', 'geodir-converter' ),
				'description' => __( 'Visibility setting in Connections.', 'geodir-converter' ),
				'icon'        => 'fas fa-eye',
			),
			array(
				'type'        => 'text',
				'field_key'   => 'connections_contact_first_name',
				'label'       => __( 'Contact First Name', 'geodir-converter' ),
				'description' => __( 'Primary contact first name.', 'geodir-converter' ),
				'icon'        => 'fas fa-user',
			),
			array(
				'type'        => 'text',
				'field_key'   => 'connections_contact_last_name',
				'label'       => __( 'Contact Last Name', 'geodir-converter' ),
				'description' => __( 'Primary contact last name.', 'geodir-converter' ),
				'icon'        => 'fas fa-user',
			),
			array(
				'type'        => 'text',
				'field_key'   => 'connections_family_name',
				'label'       => __( 'Family Name', 'geodir-converter' ),
				'description' => __( 'Family name for family entries.', 'geodir-converter' ),
				'icon'        => 'fas fa-users',
			),
			array(
				'type'        => 'text',
				'field_key'   => 'connections_title',
				'label'       => __( 'Title', 'geodir-converter' ),
				'description' => __( 'Title or position.', 'geodir-converter' ),
				'icon'        => 'fas fa-briefcase',
			),
			array(
				'type'        => 'text',
				'field_key'   => 'connections_organization',
				'label'       => __( 'Organization', 'geodir-converter' ),
				'description' => __( 'Associated organization.', 'geodir-converter' ),
				'icon'        => 'fas fa-building',
			),
			array(
				'type'        => 'text',
				'field_key'   => 'connections_department',
				'label'       => __( 'Department', 'geodir-converter' ),
				'description' => __( 'Organization department.', 'geodir-converter' ),
				'icon'        => 'fas fa-sitemap',
			),
			array(
				'type'         => 'datepicker',
				'data_type'    => 'DATE',
				'field_key'    => 'connections_birthday',
				'label'        => __( 'Birthday', 'geodir-converter' ),
				'description'  => __( 'Birthday date imported from Connections.', 'geodir-converter' ),
				'icon'         => 'fas fa-birthday-cake',
				'show_in'      => '[detail]',
				'extra_fields' => array(
					'date_format' => 'F j, Y',
					'date_range'  => '',
				),
			),
			array(
				'type'         => 'datepicker',
				'data_type'    => 'DATE',
				'field_key'    => 'connections_anniversary',
				'label'        => __( 'Anniversary', 'geodir-converter' ),
				'description'  => __( 'Anniversary date imported from Connections.', 'geodir-converter' ),
				'icon'         => 'fas fa-ring',
				'show_in'      => '[detail]',
				'extra_fields' => array(
					'date_format' => 'F j, Y',
					'date_range'  => '',
				),
			),
			array(
				'type'        => 'textarea',
				'field_key'   => 'connections_notes',
				'label'       => __( 'Notes', 'geodir-converter' ),
				'description' => __( 'Private notes stored in Connections.', 'geodir-converter' ),
				'icon'        => 'far fa-sticky-note',
			),
		);
	}

	/**
	 * Returns definitions for common GeoDirectory fields we populate if missing.
	 *
	 * @return array
	 */
	private function get_predefined_field_definitions() {
		return array(
			array(
				'type'        => 'phone',
				'field_key'   => 'phone',
				'label'       => __( 'Phone', 'geodir-converter' ),
				'description' => __( 'Primary phone number.', 'geodir-converter' ),
				'icon'        => 'fas fa-phone',
			),
			array(
				'type'        => 'email',
				'field_key'   => 'email',
				'label'       => __( 'Email', 'geodir-converter' ),
				'description' => __( 'Primary email address.', 'geodir-converter' ),
				'icon'        => 'fas fa-envelope',
			),
			array(
				'type'        => 'url',
				'field_key'   => 'website',
				'label'       => __( 'Website', 'geodir-converter' ),
				'description' => __( 'Website URL.', 'geodir-converter' ),
				'icon'        => 'fas fa-globe',
			),
			array(
				'type'        => 'phone',
				'field_key'   => 'fax',
				'label'       => __( 'Fax', 'geodir-converter' ),
				'description' => __( 'Fax number.', 'geodir-converter' ),
				'icon'        => 'fas fa-fax',
			),
			array(
				'type'        => 'image',
				'field_key'   => 'company_logo',
				'label'       => __( 'Business Logo', 'geodir-converter' ),
				'description' => __( 'Upload a business logo.', 'geodir-converter' ),
				'icon'        => 'fas fa-image',
			),
			array(
				'type'        => 'url',
				'field_key'   => 'facebook',
				'label'       => __( 'Facebook', 'geodir-converter' ),
				'description' => __( 'Facebook profile URL.', 'geodir-converter' ),
				'icon'        => 'fab fa-facebook',
			),
			array(
				'type'        => 'url',
				'field_key'   => 'twitter',
				'label'       => __( 'Twitter', 'geodir-converter' ),
				'description' => __( 'Twitter profile URL.', 'geodir-converter' ),
				'icon'        => 'fab fa-twitter',
			),
			array(
				'type'        => 'url',
				'field_key'   => 'instagram',
				'label'       => __( 'Instagram', 'geodir-converter' ),
				'description' => __( 'Instagram profile URL.', 'geodir-converter' ),
				'icon'        => 'fab fa-instagram',
			),
			array(
				'type'        => 'url',
				'field_key'   => 'linkedin',
				'label'       => __( 'LinkedIn', 'geodir-converter' ),
				'description' => __( 'LinkedIn profile URL.', 'geodir-converter' ),
				'icon'        => 'fab fa-linkedin',
			),
			array(
				'type'        => 'url',
				'field_key'   => 'pinterest',
				'label'       => __( 'Pinterest', 'geodir-converter' ),
				'description' => __( 'Pinterest profile URL.', 'geodir-converter' ),
				'icon'        => 'fab fa-pinterest',
			),
			array(
				'type'        => 'url',
				'field_key'   => 'youtube',
				'label'       => __( 'YouTube', 'geodir-converter' ),
				'description' => __( 'YouTube channel URL.', 'geodir-converter' ),
				'icon'        => 'fab fa-youtube',
			),
			array(
				'type'        => 'url',
				'field_key'   => 'tiktok',
				'label'       => __( 'TikTok', 'geodir-converter' ),
				'description' => __( 'TikTok profile URL.', 'geodir-converter' ),
				'icon'        => 'fab fa-tiktok',
			),
			array(
				'type'        => 'url',
				'field_key'   => 'telegram',
				'label'       => __( 'Telegram', 'geodir-converter' ),
				'description' => __( 'Telegram link.', 'geodir-converter' ),
				'icon'        => 'fab fa-telegram',
			),
			array(
				'type'        => 'text',
				'field_key'   => 'whatsapp',
				'label'       => __( 'WhatsApp', 'geodir-converter' ),
				'description' => __( 'WhatsApp number.', 'geodir-converter' ),
				'icon'        => 'fab fa-whatsapp',
			),
			array(
				'type'        => 'text',
				'field_key'   => 'skype',
				'label'       => __( 'Skype', 'geodir-converter' ),
				'description' => __( 'Skype username.', 'geodir-converter' ),
				'icon'        => 'fab fa-skype',
			),
		);
	}

	/**
	 * Build dynamic field definitions for external data types.
	 *
	 * @return array
	 */
	private function get_dynamic_field_definitions() {
		$fields = array();

		$fields = array_merge( $fields, $this->build_dynamic_phone_fields() );
		$fields = array_merge( $fields, $this->build_dynamic_email_fields() );
		$fields = array_merge( $fields, $this->build_dynamic_social_fields() );
		$fields = array_merge( $fields, $this->build_dynamic_messenger_fields() );
		$fields = array_merge( $fields, $this->build_dynamic_date_fields() );
		$fields = array_merge( $fields, $this->build_dynamic_link_fields() );

		return $fields;
	}

	/**
	 * Dynamic phone field definitions for unmapped phone types.
	 *
	 * @return array
	 */
	private function build_dynamic_phone_fields() {
		$fields = array();

		if ( ! defined( 'CN_ENTRY_PHONE_TABLE' ) ) {
			return $fields;
		}

		foreach ( $this->get_distinct_type_slugs( CN_ENTRY_PHONE_TABLE, 'type' ) as $slug => $label ) {
			if ( $this->map_phone_type_to_field_key( $slug ) ) {
				continue;
			}

			$fields[] = array(
				'type'        => 'phone',
				'field_key'   => 'connections_phone_' . $slug,
				'label'       => sprintf( __( '%s Phone', 'geodir-converter' ), $label ),
				'description' => __( 'Imported phone number from Connections.', 'geodir-converter' ),
				'icon'        => 'fas fa-phone',
			);
		}

		return $fields;
	}

	/**
	 * Dynamic email field definitions for unmapped email types.
	 *
	 * @return array
	 */
	private function build_dynamic_email_fields() {
		$fields = array();

		if ( ! defined( 'CN_ENTRY_EMAIL_TABLE' ) ) {
			return $fields;
		}

		foreach ( $this->get_distinct_type_slugs( CN_ENTRY_EMAIL_TABLE, 'type' ) as $slug => $label ) {
			if ( $this->map_email_type_to_field_key( $slug ) ) {
				continue;
			}

			$fields[] = array(
				'type'        => 'email',
				'field_key'   => 'connections_email_' . $slug,
				'label'       => sprintf( __( '%s Email', 'geodir-converter' ), $label ),
				'description' => __( 'Imported email address from Connections.', 'geodir-converter' ),
				'icon'        => 'fas fa-envelope',
			);
		}

		return $fields;
	}

	/**
	 * Collects additional social network fields that do not have a predefined match.
	 *
	 * @return array
	 */
	private function build_dynamic_social_fields() {
		$fields = array();

		if ( ! defined( 'CN_ENTRY_SOCIAL_TABLE' ) ) {
			return $fields;
		}

		foreach ( $this->get_distinct_type_slugs( CN_ENTRY_SOCIAL_TABLE, 'type' ) as $slug => $label ) {
			if ( $this->map_social_type_to_field_key( $slug ) ) {
				continue;
			}

			$fields[] = array(
				'type'        => 'url',
				'field_key'   => 'connections_social_' . $slug,
				'label'       => sprintf( __( '%s Profile', 'geodir-converter' ), $label ),
				'description' => __( 'Imported from a Connections social profile.', 'geodir-converter' ),
				'icon'        => 'fas fa-share-alt',
			);
		}

		return $fields;
	}

	/**
	 * Collects additional messenger fields without predefined matches.
	 *
	 * @return array
	 */
	private function build_dynamic_messenger_fields() {
		$fields = array();

		if ( ! defined( 'CN_ENTRY_MESSENGER_TABLE' ) ) {
			return $fields;
		}

		foreach ( $this->get_distinct_type_slugs( CN_ENTRY_MESSENGER_TABLE, 'type' ) as $slug => $label ) {
			if ( $this->map_messenger_type_to_field_key( $slug ) ) {
				continue;
			}

			$fields[] = array(
				'type'        => 'text',
				'field_key'   => 'connections_messenger_' . $slug,
				'label'       => sprintf( __( '%s Handle', 'geodir-converter' ), $label ),
				'description' => __( 'Imported from a Connections messenger account.', 'geodir-converter' ),
				'icon'        => 'fas fa-comments',
			);
		}

		return $fields;
	}

	/**
	 * Collects additional date fields for custom date types.
	 *
	 * @return array
	 */
	private function build_dynamic_date_fields() {
		$fields = array();

		if ( ! defined( 'CN_ENTRY_DATE_TABLE' ) ) {
			return $fields;
		}

		foreach ( $this->get_distinct_type_slugs( CN_ENTRY_DATE_TABLE, 'type' ) as $slug => $label ) {
			// Birthday/anniversary handled as predefined fields.
			if ( in_array( strtolower( $slug ), array( 'birthday', 'anniversary' ), true ) ) {
				continue;
			}

			$fields[] = array(
				'type'        => 'datepicker',
				'data_type'   => 'DATE',
				'field_key'   => 'connections_date_' . $slug,
				'label'       => sprintf( __( '%s Date', 'geodir-converter' ), $label ),
				'description' => __( 'Date value imported from Connections.', 'geodir-converter' ),
				'icon'        => 'fas fa-calendar',
			);
		}

		return $fields;
	}

	/**
	 * Collects additional link fields for custom link types.
	 *
	 * @return array
	 */
	private function build_dynamic_link_fields() {
		$fields = array();

		if ( ! defined( 'CN_ENTRY_LINK_TABLE' ) ) {
			return $fields;
		}

		foreach ( $this->get_distinct_type_slugs( CN_ENTRY_LINK_TABLE, 'type' ) as $slug => $label ) {
			$fields[] = array(
				'type'        => 'url',
				'field_key'   => 'connections_link_' . $slug,
				'label'       => sprintf( __( '%s Link', 'geodir-converter' ), $label ),
				'description' => __( 'Link imported from Connections.', 'geodir-converter' ),
				'icon'        => 'fas fa-link',
			);
		}

		return $fields;
	}

	/**
	 * Adds a field definition if it does not already exist.
	 *
	 * @param array  $fields     Collected fields.
	 * @param string $post_type  GeoDirectory post type.
	 * @param array  $definition Field definition.
	 * @return void
	 */
	private function maybe_add_field_definition( array &$fields, $post_type, array $definition ) {
		if ( empty( $definition['field_key'] ) ) {
			return;
		}

		$field_key = sanitize_key( $definition['field_key'] );

		foreach ( $fields as $field ) {
			if ( isset( $field['field_key'] ) && $field['field_key'] === $field_key ) {
				return;
			}
		}

		$definition['field_key'] = $field_key;
		$fields[]                = $definition;
	}

	/**
	 * Retrieves distinct type values from a Connections table.
	 *
	 * @param string $table  Table constant.
	 * @param string $column Column name.
	 * @return array [slug => label]
	 */
	private function get_distinct_type_slugs( $table, $column ) {
		global $wpdb;

		$values = array();

		if ( empty( $table ) || empty( $column ) ) {
			return $values;
		}

		$results = $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} != ''" ); // phpcs:ignore

		if ( empty( $results ) ) {
			return $values;
		}

		foreach ( $results as $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$slug = sanitize_key( $value );
			if ( '' === $slug || isset( $values[ $slug ] ) ) {
				continue;
			}

			$values[ $slug ] = $this->format_type_label( $value );
		}

		return $values;
	}

	/**
	 * Formats a type label into a human readable string.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function format_type_label( $value ) {
		$value = trim( (string) $value );
		$value = str_replace( array( '_', '-' ), ' ', $value );

		return ucwords( $value );
	}

	/**
	 * Maps a Connections social type to a GeoDirectory field key.
	 *
	 * @param string $type Social type.
	 * @return string Field key or empty string if no predefined match exists.
	 */
	private function map_social_type_to_field_key( $type ) {
		$type = strtolower( trim( (string) $type ) );

		$map = array(
			'facebook'  => 'facebook',
			'fb'        => 'facebook',
			'twitter'   => 'twitter',
			'x'         => 'twitter',
			'instagram' => 'instagram',
			'linkedin'  => 'linkedin',
			'youtube'   => 'youtube',
			'pinterest' => 'pinterest',
			'tiktok'    => 'tiktok',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : '';
	}

	/**
	 * Maps a Connections messenger type to a GeoDirectory field key.
	 *
	 * @param string $type Messenger type.
	 * @return string Field key or empty string if no predefined match exists.
	 */
	private function map_messenger_type_to_field_key( $type ) {
		$type = strtolower( trim( (string) $type ) );

		$map = array(
			'whatsapp' => 'whatsapp',
			'skype'    => 'skype',
			'telegram' => 'telegram',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : '';
	}

	/**
	 * Maps email types to predefined field keys.
	 *
	 * @param string $type Email type.
	 * @return string Field key or empty string.
	 */
	private function map_email_type_to_field_key( $type ) {
		$type = strtolower( trim( (string) $type ) );

		$map = array(
			'work'     => 'email',
			'business' => 'email',
			'office'   => 'email',
			'primary'  => 'email',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : '';
	}

	/**
	 * Maps phone types to predefined field keys.
	 *
	 * @param string $type Phone type.
	 * @return string Field key or empty string.
	 */
	private function map_phone_type_to_field_key( $type ) {
		$type = strtolower( trim( (string) $type ) );

		$map = array(
			'work'     => 'phone',
			'business' => 'phone',
			'office'   => 'phone',
			'primary'  => 'phone',
			'main'     => 'phone',
			'fax'      => 'fax',
			'faxphone' => 'fax',
			'whatsapp' => 'whatsapp',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : '';
	}

	/**
	 * Import custom fields from Connections to GeoDirectory.
	 *
	 * @since 2.2.0
	 * @param array $task Import task.
	 *
	 * @return array Result of the import operation.
	 */
	public function task_import_fields( array $task ) {
		$this->log( esc_html__( 'Importing custom fields...', 'geodir-converter' ) );

		$post_type   = $this->get_import_post_type();
		$package_ids = $this->get_package_ids( $post_type );
		$fields      = $this->get_custom_fields();

		if ( empty( $fields ) ) {
			$this->log(
				sprintf(
					/* translators: %s: post type name. */
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

			if ( empty( $gd_field ) ) {
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
				$this->log(
					sprintf(
						/* translators: %1$s: field label, %2$s: error message */
						__( 'Failed to import custom field: %1$s - %2$s', 'geodir-converter' ),
						isset( $field['label'] ) ? $field['label'] : $gd_field['htmlvar_name'],
						$error_msg
					),
					'error'
				);
			}
		}

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$this->log(
			sprintf(
				/* translators: 1: imported count, 2: updated count, 3: skipped count, 4: failed count. */
				esc_html__( 'Custom fields: Import completed. %1$d imported, %2$d updated, %3$d skipped, %4$d failed.', 'geodir-converter' ),
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
	 * Prepare a single field for import.
	 *
	 * @param array  $field      Field data from Connections.
	 * @param string $post_type  GeoDirectory post type.
	 * @param array  $package_ids Package IDs for show_on_pkg.
	 *
	 * @return array Prepared field data for GeoDirectory.
	 */
	private function prepare_single_field( array $field, $post_type, $package_ids = array() ) {
		$field_type = isset( $field['type'] ) ? $field['type'] : 'text';
		$field_key  = isset( $field['field_key'] ) ? sanitize_key( $field['field_key'] ) : '';

		if ( empty( $field_key ) ) {
			return array();
		}

		// Check for existing field using GeoDirectory function to get full field data.
		$existing_field = geodir_get_field_infoby( 'htmlvar_name', $field_key, $post_type );
		$field_id       = ! empty( $existing_field ) ? (int) $existing_field['id'] : 0;

		$gd_field_type     = $this->map_field_type( $field_type );
		$data_type         = $this->map_data_type( $gd_field_type );
		$requested_show_in = isset( $field['show_in'] ) ? sanitize_text_field( $field['show_in'] ) : '[detail]';
		$extra_fields      = array();
		if ( ! empty( $field['extra_fields'] ) && is_array( $field['extra_fields'] ) ) {
			$extra_fields = $field['extra_fields'];
		}

		// Start with existing field data if available, preserving icons, settings, etc.
		if ( $existing_field ) {
			$gd_field             = $existing_field;
			$gd_field['field_id'] = $field_id;
			unset( $gd_field['id'] );

			// Update only specific fields, preserve existing icons and settings.
			$gd_field['post_type']      = $post_type;
			$gd_field['data_type']      = $data_type;
			$gd_field['field_type']     = $gd_field_type;
			$gd_field['field_type_key'] = $gd_field_type;
			$gd_field['htmlvar_name']   = $field_key;
			$gd_field['is_active']      = '1';
			$gd_field['show_in']        = ! empty( $field['show_in'] ) ? $requested_show_in : ( ! empty( $gd_field['show_in'] ) ? $gd_field['show_in'] : '[detail]' );
			$gd_field['show_on_pkg']    = $package_ids;

			// Only update label/description if not already set or if explicitly provided.
			if ( ! empty( $field['label'] ) ) {
				$gd_field['admin_title']    = sanitize_text_field( $field['label'] );
				$gd_field['frontend_title'] = sanitize_text_field( $field['label'] );
				$gd_field['clabels']        = sanitize_text_field( $field['label'] );
			}

			if ( ! empty( $field['description'] ) ) {
				$gd_field['frontend_desc'] = sanitize_text_field( $field['description'] );
			}

			// Preserve existing icon unless explicitly overridden.
			if ( ! empty( $field['icon'] ) && empty( $gd_field['field_icon'] ) ) {
				$gd_field['field_icon'] = sanitize_text_field( $field['icon'] );
			}
		} else {
			// Create new field.
			$gd_field = array(
				'post_type'         => $post_type,
				'data_type'         => $data_type,
				'field_type'        => $gd_field_type,
				'htmlvar_name'      => $field_key,
				'admin_title'       => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : $field_key,
				'frontend_title'    => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : $field_key,
				'frontend_desc'     => isset( $field['description'] ) ? sanitize_text_field( $field['description'] ) : '',
				'placeholder_value' => isset( $field['placeholder'] ) ? sanitize_text_field( $field['placeholder'] ) : '',
				'default_value'     => '',
				'is_active'         => '1',
				'is_default'        => '0',
				'is_required'       => isset( $field['required'] ) && $field['required'] ? 1 : 0,
				'for_admin_use'     => isset( $field['only_for_admin'] ) && $field['only_for_admin'] ? 1 : 0,
				'show_in'           => $requested_show_in,
				'show_on_pkg'       => $package_ids,
				'clabels'           => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : $field_key,
				'option_values'     => isset( $field['options'] ) ? $field['options'] : '',
				'field_icon'        => isset( $field['icon'] ) ? sanitize_text_field( $field['icon'] ) : 'fas fa-info-circle',
			);
		}

		// Handle multiselect extra_fields.
		if ( 'multiselect' === $gd_field_type ) {
			$extra_fields['multi_display_type'] = 'select';
		}

		if ( ! empty( $extra_fields ) ) {
			$gd_field['extra_fields'] = maybe_serialize( $extra_fields );
		}

		return $gd_field;
	}

	/**
	 * Map Connections field type to GeoDirectory field type.
	 *
	 * @param string $field_type Connections field type.
	 *
	 * @return string GeoDirectory field type.
	 */
	private function map_field_type( $field_type ) {
		$type_map = array(
			'text'        => 'text',
			'textarea'    => 'textarea',
			'select'      => 'select',
			'multiselect' => 'multiselect',
			'radio'       => 'radio',
			'checkbox'    => 'checkbox',
			'date'        => 'datepicker',
			'datepicker'  => 'datepicker',
			'email'       => 'email',
			'url'         => 'url',
			'phone'       => 'phone',
			'number'      => 'number',
			'image'       => 'file',
		);

		return isset( $type_map[ $field_type ] ) ? $type_map[ $field_type ] : 'text';
	}

	/**
	 * Map GeoDirectory field type to data type.
	 *
	 * @param string $field_type GeoDirectory field type.
	 *
	 * @return string GeoDirectory data type.
	 */
	private function map_data_type( $field_type ) {
		$type_map = array(
			'text'        => 'VARCHAR',
			'textarea'    => 'TEXT',
			'select'      => 'VARCHAR',
			'multiselect' => 'TEXT',
			'radio'       => 'VARCHAR',
			'checkbox'    => 'TINYINT',
			'datepicker'  => 'DATE',
			'email'       => 'VARCHAR',
			'url'         => 'TEXT',
			'number'      => 'INT',
			'phone'       => 'VARCHAR',
			'file'        => 'TEXT',
		);

		return isset( $type_map[ $field_type ] ) ? $type_map[ $field_type ] : 'VARCHAR';
	}

	/**
	 * Count total listings to import.
	 *
	 * @return int Total count.
	 */
	private function count_listings() {
		if ( ! $this->is_connections_available() ) {
			return 0;
		}

		global $wpdb;

		list( $where, $params ) = $this->get_entry_filter_sql();

		$sql = 'SELECT COUNT(*) FROM ' . CN_ENTRY_TABLE . ' WHERE ' . $where;

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Build SQL WHERE clause for entry filters.
	 *
	 * @return array
	 */
	private function get_entry_filter_sql() {
		$clauses = array( '1=1' );
		$params  = array();

		$statuses = $this->get_selected_statuses();
		if ( ! empty( $statuses ) && count( $statuses ) < count( $this->post_statuses ) ) {
			$clauses[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params    = array_merge( $params, $statuses );
		}

		$entry_types = $this->get_selected_entry_types();
		if ( ! empty( $entry_types ) && count( $entry_types ) < count( $this->entry_types ) ) {
			$clauses[] = 'entry_type IN (' . implode( ',', array_fill( 0, count( $entry_types ), '%s' ) ) . ')';
			$params    = array_merge( $params, $entry_types );
		}

		$visibility = $this->get_selected_visibility();
		if ( ! empty( $visibility ) && count( $visibility ) < count( $this->visibility_options ) ) {
			$clauses[] = 'visibility IN (' . implode( ',', array_fill( 0, count( $visibility ), '%s' ) ) . ')';
			$params    = array_merge( $params, $visibility );
		}

		return array( implode( ' AND ', $clauses ), $params );
	}

	/**
	 * Fetch a batch of Connections entries.
	 *
	 * @param int $limit  Batch size.
	 * @param int $offset Query offset.
	 * @return array
	 */
	private function get_entries_batch( $limit, $offset ) {
		global $wpdb;

		list( $where, $params ) = $this->get_entry_filter_sql();

		$params[] = (int) $limit;
		$params[] = (int) $offset;

		$sql = 'SELECT * FROM ' . CN_ENTRY_TABLE . ' WHERE ' . $where . ' ORDER BY id ASC LIMIT %d OFFSET %d';

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Determine a human-readable entry title.
	 *
	 * @param object $entry Connections entry row.
	 * @return string
	 */
	private function get_entry_title( $entry ) {
		if ( ! empty( $entry->organization ) ) {
			return wp_strip_all_tags( $entry->organization );
		}

		if ( ! empty( $entry->family_name ) ) {
			return wp_strip_all_tags( $entry->family_name );
		}

		$parts = array(
			! empty( $entry->honorific_prefix ) ? $entry->honorific_prefix : '',
			! empty( $entry->first_name ) ? $entry->first_name : '',
			! empty( $entry->middle_name ) ? $entry->middle_name : '',
			! empty( $entry->last_name ) ? $entry->last_name : '',
			! empty( $entry->honorific_suffix ) ? $entry->honorific_suffix : '',
		);

		$name = trim( preg_replace( '/\s+/', ' ', implode( ' ', array_filter( $parts ) ) ) );

		if ( ! empty( $name ) ) {
			return wp_strip_all_tags( $name );
		}

		if ( ! empty( $entry->contact_first_name ) || ! empty( $entry->contact_last_name ) ) {
			return wp_strip_all_tags( trim( $entry->contact_first_name . ' ' . $entry->contact_last_name ) );
		}

		/* translators: %d: entry ID. */
		return sprintf( __( 'Connections Entry #%d', 'geodir-converter' ), absint( $entry->id ) );
	}

	/**
	 * Parse listings for import.
	 *
	 * @param array $task Import task.
	 *
	 * @return array|false Next task or false if complete.
	 */
	public function task_parse_listings( array $task ) {
		if ( ! $this->is_connections_available() ) {
			$this->log( esc_html__( 'Connections tables not found. Aborting listing import.', 'geodir-converter' ), 'error' );
			return $this->next_task( $task );
		}

		$batch_size = $this->get_batch_size();
		$offset     = isset( $task['offset'] ) ? (int) $task['offset'] : 0;

		$entries = $this->get_entries_batch( $batch_size, $offset );

		if ( empty( $entries ) ) {
			return $this->next_task( $task, true );
		}

		foreach ( $entries as $entry ) {
			$status = $this->import_single_listing( $entry );
			$title  = $this->get_entry_title( $entry );

			switch ( $status ) {
				case self::IMPORT_STATUS_SUCCESS:
				case self::IMPORT_STATUS_UPDATED:
					$message = self::IMPORT_STATUS_SUCCESS === $status ? self::LOG_TEMPLATE_SUCCESS : self::LOG_TEMPLATE_UPDATED;
					$this->log( sprintf( $message, 'listing', $title ), self::IMPORT_STATUS_SUCCESS === $status ? 'success' : 'warning' );
					$this->increase_succeed_imports( 1 );
					break;
				case self::IMPORT_STATUS_SKIPPED:
					$this->log( sprintf( self::LOG_TEMPLATE_SKIPPED, 'listing', $title ), 'warning' );
					$this->increase_skipped_imports( 1 );
					break;
				case self::IMPORT_STATUS_FAILED:
				default:
					$this->log( sprintf( self::LOG_TEMPLATE_FAILED, 'listing', $title ), 'error' );
					$this->increase_failed_imports( 1 );
					break;
			}
		}

		$task['offset'] = $offset + count( $entries );

		return $task;
	}

	/**
	 * Import a single listing.
	 *
	 * @param object $entry Listing object from Connections.
	 *
	 * @return int Import status.
	 */
	private function import_single_listing( $entry ) {
		if ( empty( $entry ) || ! isset( $entry->id ) ) {
			return self::IMPORT_STATUS_FAILED;
		}

		$post_type  = $this->get_import_post_type();
		$gd_post_id = ! $this->is_test_mode() ? $this->get_gd_listing_id( $entry->id, $this->importer_id . '_id', $post_type ) : false;
		$is_update  = ! empty( $gd_post_id );

		$categories   = $this->get_entry_categories( $entry->id );
		$location     = $this->build_entry_location( $entry );
		$media_assets = $this->get_entry_media_assets( $entry );
		$images       = isset( $media_assets['gallery'] ) ? (array) $media_assets['gallery'] : array();
		$phones       = $this->get_phone_field_data( $entry->id );
		$emails       = $this->get_email_field_data( $entry->id );
		$websites     = $this->get_website_field_data( $entry->id );
		$social       = $this->get_social_field_data( $entry->id );
		$messenger    = $this->get_messenger_field_data( $entry->id );
		$dates        = $this->get_date_field_data( $entry->id );
		$links        = $this->get_link_field_data( $entry->id );

		$post_date = ! empty( $entry->ts ) ? gmdate( 'Y-m-d H:i:s', strtotime( $entry->ts ) ) : current_time( 'mysql' );

		$listing_data = array(
			'post_author'                    => $this->map_entry_author( $entry ),
			'post_title'                     => $this->get_entry_title( $entry ),
			'post_content'                   => ! empty( $entry->bio ) ? wp_kses_post( $entry->bio ) : '',
			'post_content_filtered'          => '',
			'post_excerpt'                   => ! empty( $entry->excerpt ) ? wp_kses_post( $entry->excerpt ) : '',
			'post_status'                    => $this->map_entry_status( $entry->status ),
			'post_type'                      => $post_type,
			'comment_status'                 => 'closed',
			'ping_status'                    => 'closed',
			'post_name'                      => $this->normalize_entry_slug( $entry ),
			'post_date'                      => $post_date,
			'post_date_gmt'                  => get_gmt_from_date( $post_date ),
			'post_modified'                  => current_time( 'mysql' ),
			'post_modified_gmt'              => current_time( 'mysql', 1 ),
			'tax_input'                      => array(
				"{$post_type}category" => $categories,
			),
			'default_category'               => ! empty( $categories ) ? (int) $categories[0] : 0,
			'featured_image'                 => ! empty( $images ) ? esc_url_raw( $images[0] ) : '',
			'street'                         => $location['street'],
			'street2'                        => $location['street2'],
			'city'                           => $location['city'],
			'region'                         => $location['region'],
			'country'                        => $location['country'],
			'zip'                            => $location['zip'],
			'latitude'                       => $location['latitude'],
			'longitude'                      => $location['longitude'],
			'mapview'                        => '',
			'mapzoom'                        => '',
			$this->importer_id . '_id'       => (int) $entry->id,
			'connections_entry_type'         => isset( $entry->entry_type ) ? sanitize_text_field( $entry->entry_type ) : '',
			'connections_visibility'         => isset( $entry->visibility ) ? sanitize_text_field( $entry->visibility ) : '',
			'connections_contact_first_name' => isset( $entry->contact_first_name ) ? sanitize_text_field( $entry->contact_first_name ) : '',
			'connections_contact_last_name'  => isset( $entry->contact_last_name ) ? sanitize_text_field( $entry->contact_last_name ) : '',
			'connections_family_name'        => isset( $entry->family_name ) ? sanitize_text_field( $entry->family_name ) : '',
			'connections_title'              => isset( $entry->title ) ? sanitize_text_field( $entry->title ) : '',
			'connections_organization'       => isset( $entry->organization ) ? sanitize_text_field( $entry->organization ) : '',
			'connections_department'         => isset( $entry->department ) ? sanitize_text_field( $entry->department ) : '',
			'connections_notes'              => ! empty( $entry->notes ) ? wp_kses_post( $entry->notes ) : '',
		);

		$structured_fields = array_merge(
			isset( $phones['fields'] ) ? $phones['fields'] : array(),
			isset( $emails['fields'] ) ? $emails['fields'] : array(),
			isset( $websites['fields'] ) ? $websites['fields'] : array(),
			isset( $social['fields'] ) ? $social['fields'] : array(),
			isset( $messenger['fields'] ) ? $messenger['fields'] : array(),
			isset( $dates['fields'] ) ? $dates['fields'] : array(),
			isset( $links['fields'] ) ? $links['fields'] : array()
		);

		foreach ( $structured_fields as $field_key => $value ) {
			// Skip empty values.
			if ( '' === $value || null === $value ) {
				continue;
			}

			// Trim string values.
			if ( is_string( $value ) ) {
				$value = trim( $value );
				if ( '' === $value ) {
					continue;
				}
			}

			// Ensure field key is valid.
			$field_key = sanitize_key( $field_key );
			if ( empty( $field_key ) ) {
				continue;
			}

			$listing_data[ $field_key ] = $value;
		}

		if ( $this->is_test_mode() ) {
			return self::IMPORT_STATUS_SUCCESS;
		}

		// Delete existing media if updating.
		if ( $is_update ) {
			GeoDir_Media::delete_files( (int) $gd_post_id, 'post_images' );
			GeoDir_Media::delete_files( (int) $gd_post_id, 'company_logo' );
		}

		// Process gallery images.
		$listing_data['post_images'] = $this->format_images_data( array(), $images );

		if ( ! empty( $media_assets['logo'] ) ) {
			$listing_data['company_logo'] = esc_url_raw( $media_assets['logo'] ) . '|||';
		}

		if ( $is_update ) {
			$listing_data['ID'] = (int) $gd_post_id;
			$gd_post_id         = wp_update_post( $listing_data, true );
		} else {
			$gd_post_id = wp_insert_post( $listing_data, true );
		}

		if ( is_wp_error( $gd_post_id ) ) {
			$this->log( $gd_post_id->get_error_message(), 'error' );
			return self::IMPORT_STATUS_FAILED;
		}

		return $is_update ? self::IMPORT_STATUS_UPDATED : self::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Map Connections categories to GeoDirectory categories.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_entry_categories( $entry_id ) {
		if ( ! $this->is_connections_available() ) {
			return array();
		}

		global $wpdb;

		$term_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT tt.term_id
				FROM ' . CN_TERM_RELATIONSHIP_TABLE . ' tr
				INNER JOIN ' . CN_TERM_TAXONOMY_TABLE . ' tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tr.entry_id = %d AND tt.taxonomy = %s',
				$entry_id,
				self::TAX_ENTRY_CATEGORY
			)
		);

		if ( empty( $term_ids ) ) {
			return array();
		}

		$mapped = array();

		foreach ( $term_ids as $term_id ) {
			$mapped_id = $this->get_mapped_term_id( (int) $term_id );
			if ( $mapped_id ) {
				$mapped[] = $mapped_id;
			}
		}

		return array_values( array_unique( $mapped ) );
	}

	/**
	 * Build a normalized location array for a Connections entry.
	 *
	 * @param object $entry Entry row.
	 * @return array
	 */
	private function build_entry_location( $entry ) {
		$defaults = array(
			'street'    => '',
			'street2'   => '',
			'city'      => '',
			'region'    => '',
			'country'   => '',
			'zip'       => '',
			'latitude'  => '',
			'longitude' => '',
		);

		$location = wp_parse_args( $defaults, $this->get_default_location() );

		$address = $this->get_entry_address_row( $entry->id );

		if ( $address ) {
			$location['street']    = ! empty( $address->line_1 ) ? $address->line_1 : $location['street'];
			$location['street2']   = $this->implode_address_lines( array( $address->line_2, $address->line_3, $address->line_4 ) );
			$location['city']      = ! empty( $address->city ) ? $address->city : $location['city'];
			$location['region']    = ! empty( $address->state ) ? $address->state : $location['region'];
			$location['country']   = ! empty( $address->country ) ? $address->country : $location['country'];
			$location['zip']       = ! empty( $address->zipcode ) ? $address->zipcode : $location['zip'];
			$location['latitude']  = ! empty( $address->latitude ) ? (string) $address->latitude : $location['latitude'];
			$location['longitude'] = ! empty( $address->longitude ) ? (string) $address->longitude : $location['longitude'];
		}

		if ( ! empty( $location['latitude'] ) && ! empty( $location['longitude'] ) ) {
			$this->log(
				sprintf(
					/* translators: 1: latitude value, 2: longitude value. */
					esc_html__( 'Resolving address from coordinates %1$s,%2$s', 'geodir-converter' ),
					$location['latitude'],
					$location['longitude']
				),
				'info'
			);

			$lookup = GeoDir_Converter_Utils::get_location_from_coords( $location['latitude'], $location['longitude'] );

			if ( ! is_wp_error( $lookup ) ) {
				$location = array_merge( $location, $lookup );
			}
		}

		return $location;
	}

	/**
	 * Retrieve the preferred address for an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return object|null
	 */
	private function get_entry_address_row( $entry_id ) {
		if ( ! defined( 'CN_ENTRY_ADDRESS_TABLE' ) ) {
			return null;
		}

		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . CN_ENTRY_ADDRESS_TABLE . ' WHERE entry_id = %d ORDER BY preferred DESC, `order` ASC LIMIT 1',
				$entry_id
			)
		);
	}

	/**
	 * Combine multiple address lines.
	 *
	 * @param array $lines Address lines.
	 * @return string
	 */
	private function implode_address_lines( array $lines ) {
		$lines = array_filter( array_map( 'trim', $lines ) );

		return implode( ', ', $lines );
	}

	/**
	 * Retrieve media assets (gallery + logo) for an entry.
	 *
	 * @param object $entry Entry row.
	 * @return array
	 */
	private function get_entry_media_assets( $entry ) {
		$assets = array(
			'gallery' => array(),
			'logo'    => '',
		);

		if ( ! defined( 'CN_IMAGE_PATH' ) || ! defined( 'CN_IMAGE_BASE_URL' ) ) {
			return $assets;
		}

		$slug = ! empty( $entry->slug ) ? rawurldecode( $entry->slug ) : '';

		if ( empty( $slug ) ) {
			$slug = 'connections-entry-' . $entry->id;
		}

		$directory = trailingslashit( CN_IMAGE_PATH . $slug );
		$base_url  = trailingslashit( CN_IMAGE_BASE_URL . $slug );

		$options = array();

		if ( ! empty( $entry->options ) ) {
			$options = maybe_unserialize( $entry->options );
			if ( ! is_array( $options ) ) {
				$options = array();
			}
		}

		$gallery = array();

		if ( ! empty( $options['image']['name']['original'] ) ) {
			$file = $options['image']['name']['original'];
			if ( $file && file_exists( $directory . $file ) ) {
				$gallery[] = esc_url_raw( $base_url . $file );
			}
		}

		if ( ! empty( $options['logo']['name']['original'] ) ) {
			$file = $options['logo']['name']['original'];
			if ( $file && file_exists( $directory . $file ) ) {
				$assets['logo'] = esc_url_raw( $base_url . $file );
			}
		}

		if ( is_dir( $directory ) ) {
			$gallery_files = glob( $directory . '*gallery*.*' );

			if ( ! empty( $gallery_files ) ) {
				foreach ( $gallery_files as $file ) {
					$gallery[] = esc_url_raw( $base_url . basename( $file ) );
				}
			}

			$all_files = glob( $directory . '*.*' );
			foreach ( (array) $all_files as $file ) {
				$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
					$url       = esc_url_raw( $base_url . basename( $file ) );
					$gallery[] = $url;
				}
			}
		}

		if ( empty( $assets['logo'] ) && ! empty( $gallery ) ) {
			// Use first gallery image as logo fallback.
			$assets['logo'] = $gallery[0];
		}

		$assets['gallery'] = array_values( array_unique( array_filter( $gallery ) ) );

		return $assets;
	}

	/**
	 * Normalize entry slug.
	 *
	 * @param object $entry Entry row.
	 * @return string
	 */
	private function normalize_entry_slug( $entry ) {
		$slug = '';

		if ( ! empty( $entry->slug ) ) {
			$slug = sanitize_title( rawurldecode( $entry->slug ) );
		}

		if ( empty( $slug ) ) {
			$slug = 'connections-entry-' . absint( $entry->id );
		}

		return $slug;
	}

	/**
	 * Map Connections status to WordPress status.
	 *
	 * @param string $status Connections status.
	 * @return string
	 */
	private function map_entry_status( $status ) {
		$status = strtolower( (string) $status );

		if ( 'approved' === $status ) {
			return 'publish';
		}

		if ( 'pending' === $status ) {
			return 'pending';
		}

		return 'draft';
	}

	/**
	 * Determine the WordPress author for an entry.
	 *
	 * @param object $entry Entry row.
	 * @return int
	 */
	private function map_entry_author( $entry ) {
		$candidate_ids = array(
			isset( $entry->owner ) ? (int) $entry->owner : 0,
			isset( $entry->added_by ) ? (int) $entry->added_by : 0,
			isset( $entry->edited_by ) ? (int) $entry->edited_by : 0,
			isset( $entry->user ) ? (int) $entry->user : 0,
		);

		foreach ( $candidate_ids as $candidate ) {
			if ( $candidate && get_userdata( $candidate ) ) {
				return $candidate;
			}
		}

		return (int) $this->get_import_setting( 'wp_author_id', get_current_user_id() );
	}

	/**
	 * Retrieve phone rows for an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_phone_rows( $entry_id ) {
		if ( ! defined( 'CN_ENTRY_PHONE_TABLE' ) ) {
			return array();
		}

		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type, number, preferred, visibility FROM ' . CN_ENTRY_PHONE_TABLE . ' WHERE entry_id = %d ORDER BY preferred DESC, `order` ASC',
				$entry_id
			)
		);
	}

	/**
	 * Retrieve email rows for an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_email_rows( $entry_id ) {
		if ( ! defined( 'CN_ENTRY_EMAIL_TABLE' ) ) {
			return array();
		}

		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type, address, preferred, visibility FROM ' . CN_ENTRY_EMAIL_TABLE . ' WHERE entry_id = %d ORDER BY preferred DESC, `order` ASC',
				$entry_id
			)
		);
	}

	/**
	 * Retrieve link rows for an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_link_rows( $entry_id ) {
		if ( ! defined( 'CN_ENTRY_LINK_TABLE' ) ) {
			return array();
		}

		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type, title, url, target, follow, image, logo FROM ' . CN_ENTRY_LINK_TABLE . ' WHERE entry_id = %d ORDER BY preferred DESC, `order` ASC',
				$entry_id
			)
		);
	}

	/**
	 * Retrieve social rows for an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_social_rows( $entry_id ) {
		if ( ! defined( 'CN_ENTRY_SOCIAL_TABLE' ) ) {
			return array();
		}

		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type, url, preferred FROM ' . CN_ENTRY_SOCIAL_TABLE . ' WHERE entry_id = %d ORDER BY preferred DESC, `order` ASC',
				$entry_id
			)
		);
	}

	/**
	 * Retrieve messenger rows for an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_messenger_rows( $entry_id ) {
		if ( ! defined( 'CN_ENTRY_MESSENGER_TABLE' ) ) {
			return array();
		}

		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type, uid, visibility FROM ' . CN_ENTRY_MESSENGER_TABLE . ' WHERE entry_id = %d ORDER BY preferred DESC, `order` ASC',
				$entry_id
			)
		);
	}

	/**
	 * Retrieve date rows for an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_date_rows( $entry_id ) {
		if ( ! defined( 'CN_ENTRY_DATE_TABLE' ) ) {
			return array();
		}

		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type, `date`, visibility, preferred FROM ' . CN_ENTRY_DATE_TABLE . ' WHERE entry_id = %d ORDER BY preferred DESC, `order` ASC',
				$entry_id
			)
		);
	}

	/**
	 * Prepare phone summary text + per-field values.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_phone_field_data( $entry_id ) {
		$rows = $this->get_phone_rows( $entry_id );

		if ( empty( $rows ) ) {
			return array(
				'summary' => '',
				'fields'  => array(),
			);
		}

		$lines  = array();
		$fields = array();

		// Separate preferred and non-preferred rows for predefined fields.
		$preferred_rows = array();
		$other_rows     = array();

		foreach ( $rows as $row ) {
			$number = isset( $row->number ) ? trim( (string) $row->number ) : '';
			if ( '' === $number ) {
				continue;
			}

			$field_key     = $this->map_phone_type_to_field_key( $row->type );
			$is_predefined = ! empty( $field_key ) && in_array( $field_key, array( 'phone', 'fax', 'whatsapp' ), true );

			if ( $is_predefined && ! empty( $row->preferred ) ) {
				$preferred_rows[ $field_key ] = $row;
			} elseif ( $is_predefined && ! isset( $preferred_rows[ $field_key ] ) ) {
				$preferred_rows[ $field_key ] = $row;
			} else {
				$other_rows[] = $row;
			}
		}

		// Process preferred rows first (for predefined fields, use first/preferred value only).
		foreach ( $preferred_rows as $field_key => $row ) {
			$number = trim( (string) $row->number );
			$label  = ! empty( $row->type ) ? $this->format_type_label( $row->type ) : __( 'Phone', 'geodir-converter' );

			if ( ! empty( $row->preferred ) ) {
				$label .= ' (' . __( 'Preferred', 'geodir-converter' ) . ')';
			}

			$lines[]              = sprintf( '%1$s: %2$s', $label, $number );
			$fields[ $field_key ] = sanitize_text_field( $number );
		}

		// Process other rows (custom phone types).
		foreach ( $other_rows as $row ) {
			$number = trim( (string) $row->number );
			$label  = ! empty( $row->type ) ? $this->format_type_label( $row->type ) : __( 'Phone', 'geodir-converter' );

			if ( ! empty( $row->preferred ) ) {
				$label .= ' (' . __( 'Preferred', 'geodir-converter' ) . ')';
			}

			if ( ! empty( $row->visibility ) && 'public' !== strtolower( $row->visibility ) ) {
				$label .= sprintf( ' [%s]', ucwords( $row->visibility ) );
			}

			$lines[] = sprintf( '%1$s: %2$s', $label, $number );

			$field_key = $this->map_phone_type_to_field_key( $row->type );
			if ( '' === $field_key ) {
				$slug      = sanitize_key( $row->type );
				$field_key = 'connections_phone_' . ( $slug ? $slug : 'other' );
			}

			// Only add if not already set (predefined fields take priority).
			if ( ! isset( $fields[ $field_key ] ) ) {
				$fields[ $field_key ] = array();
			}
			$fields[ $field_key ][] = sanitize_text_field( $number );
		}

		// Normalize custom fields (arrays to strings).
		foreach ( $fields as $key => $value ) {
			if ( is_array( $value ) ) {
				$fields[ $key ] = implode( "\n", array_unique( array_filter( array_map( 'trim', $value ) ) ) );
			}
		}

		return array(
			'summary' => implode( "\n", $lines ),
			'fields'  => $fields,
		);
	}

	/**
	 * Prepare email summary text + per-field values.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_email_field_data( $entry_id ) {
		$rows = $this->get_email_rows( $entry_id );

		if ( empty( $rows ) ) {
			return array(
				'summary' => '',
				'fields'  => array(),
			);
		}

		$lines  = array();
		$fields = array();

		// Separate preferred and non-preferred rows for predefined fields.
		$preferred_rows = array();
		$other_rows     = array();

		foreach ( $rows as $row ) {
			$email = isset( $row->address ) ? sanitize_email( $row->address ) : '';
			if ( '' === $email ) {
				continue;
			}

			$field_key     = $this->map_email_type_to_field_key( $row->type );
			$is_predefined = ! empty( $field_key ) && 'email' === $field_key;

			if ( $is_predefined && ! empty( $row->preferred ) ) {
				$preferred_rows[ $field_key ] = $row;
			} elseif ( $is_predefined && ! isset( $preferred_rows[ $field_key ] ) ) {
				$preferred_rows[ $field_key ] = $row;
			} else {
				$other_rows[] = $row;
			}
		}

		// Process preferred rows first (for predefined fields, use first/preferred value only).
		foreach ( $preferred_rows as $field_key => $row ) {
			$email = sanitize_email( $row->address );
			$label = ! empty( $row->type ) ? $this->format_type_label( $row->type ) : __( 'Email', 'geodir-converter' );

			if ( ! empty( $row->preferred ) ) {
				$label .= ' (' . __( 'Preferred', 'geodir-converter' ) . ')';
			}

			$lines[]              = sprintf( '%1$s: %2$s', $label, $email );
			$fields[ $field_key ] = $email;
		}

		// Process other rows (custom email types).
		foreach ( $other_rows as $row ) {
			$email = sanitize_email( $row->address );
			$label = ! empty( $row->type ) ? $this->format_type_label( $row->type ) : __( 'Email', 'geodir-converter' );

			if ( ! empty( $row->preferred ) ) {
				$label .= ' (' . __( 'Preferred', 'geodir-converter' ) . ')';
			}

			if ( ! empty( $row->visibility ) && 'public' !== strtolower( $row->visibility ) ) {
				$label .= sprintf( ' [%s]', ucwords( $row->visibility ) );
			}

			$lines[] = sprintf( '%1$s: %2$s', $label, $email );

			$field_key = $this->map_email_type_to_field_key( $row->type );
			if ( '' === $field_key ) {
				$slug      = sanitize_key( $row->type );
				$field_key = 'connections_email_' . ( $slug ? $slug : 'other' );
			}

			// Only add if not already set (predefined fields take priority).
			if ( ! isset( $fields[ $field_key ] ) ) {
				$fields[ $field_key ] = array();
			}
			$fields[ $field_key ][] = $email;
		}

		// Normalize custom fields (arrays to strings).
		foreach ( $fields as $key => $value ) {
			if ( is_array( $value ) ) {
				$fields[ $key ] = implode( ', ', array_unique( array_filter( array_map( 'trim', $value ) ) ) );
			}
		}

		return array(
			'summary' => implode( "\n", $lines ),
			'fields'  => $fields,
		);
	}

	/**
	 * Prepare website summary + per-field values.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_website_field_data( $entry_id ) {
		$rows = $this->get_link_rows( $entry_id );

		if ( empty( $rows ) ) {
			return array(
				'summary' => '',
				'fields'  => array(),
			);
		}

		$lines       = array();
		$fields      = array();
		$website_set = false;

		foreach ( $rows as $row ) {
			$url = isset( $row->url ) ? esc_url_raw( $row->url ) : '';

			if ( '' === $url || ! $this->is_website_link_type( $row->type ) ) {
				continue;
			}

			$label = ! empty( $row->title ) ? $row->title : __( 'Website', 'geodir-converter' );

			if ( ! empty( $row->type ) ) {
				$label .= sprintf( ' (%s)', $this->format_type_label( $row->type ) );
			}

			$details = array( $url );

			if ( ! empty( $row->target ) ) {
				$details[] = sprintf( __( 'Target: %s', 'geodir-converter' ), $row->target );
			}

			if ( isset( $row->follow ) ) {
				$details[] = sprintf(
					__( 'Follow: %s', 'geodir-converter' ),
					$row->follow ? __( 'Yes', 'geodir-converter' ) : __( 'No', 'geodir-converter' )
				);
			}

			$lines[] = sprintf( '%1$s: %2$s', $label, implode( ' | ', $details ) );

			// For predefined website field, use first URL only.
			if ( ! $website_set ) {
				$fields['website'] = $url;
				$website_set       = true;
			}
		}

		return array(
			'summary' => implode( "\n", $lines ),
			'fields'  => $fields,
		);
	}

	/**
	 * Prepare social profile summary + per-field values.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_social_field_data( $entry_id ) {
		$rows = $this->get_social_rows( $entry_id );

		if ( empty( $rows ) ) {
			return array(
				'summary' => '',
				'fields'  => array(),
			);
		}

		$lines  = array();
		$fields = array();

		foreach ( $rows as $row ) {
			$url = isset( $row->url ) ? esc_url_raw( $row->url ) : '';
			if ( '' === $url ) {
				continue;
			}

			$label = ! empty( $row->type ) ? $this->format_type_label( $row->type ) : __( 'Social', 'geodir-converter' );

			if ( ! empty( $row->preferred ) ) {
				$label .= ' (' . __( 'Preferred', 'geodir-converter' ) . ')';
			}

			$lines[] = sprintf( '%1$s: %2$s', $label, $url );

			$field_key = $this->map_social_type_to_field_key( $row->type );
			if ( '' === $field_key ) {
				$slug      = sanitize_key( $row->type );
				$field_key = 'connections_social_' . ( $slug ? $slug : 'profile' );
			}

			$fields[ $field_key ][] = $url;
		}

		return array(
			'summary' => implode( "\n", $lines ),
			'fields'  => $this->normalize_field_value_map( $fields ),
		);
	}

	/**
	 * Prepare messenger summary + per-field values.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_messenger_field_data( $entry_id ) {
		$rows = $this->get_messenger_rows( $entry_id );

		if ( empty( $rows ) ) {
			return array(
				'summary' => '',
				'fields'  => array(),
			);
		}

		$lines  = array();
		$fields = array();

		foreach ( $rows as $row ) {
			$uid = isset( $row->uid ) ? sanitize_text_field( $row->uid ) : '';
			if ( '' === $uid ) {
				continue;
			}

			$label = ! empty( $row->type ) ? $this->format_type_label( $row->type ) : __( 'Messenger', 'geodir-converter' );

			if ( ! empty( $row->visibility ) && 'public' !== strtolower( $row->visibility ) ) {
				$label .= sprintf( ' [%s]', ucwords( $row->visibility ) );
			}

			$lines[] = sprintf( '%1$s: %2$s', $label, $uid );

			$field_key = $this->map_messenger_type_to_field_key( $row->type );
			if ( '' === $field_key ) {
				$slug      = sanitize_key( $row->type );
				$field_key = 'connections_messenger_' . ( $slug ? $slug : 'handle' );
			}

			$fields[ $field_key ][] = $uid;
		}

		return array(
			'summary' => implode( "\n", $lines ),
			'fields'  => $this->normalize_field_value_map( $fields ),
		);
	}

	/**
	 * Prepare date summary + per-field values.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_date_field_data( $entry_id ) {
		$rows = $this->get_date_rows( $entry_id );

		if ( empty( $rows ) ) {
			return array(
				'summary' => '',
				'fields'  => array(),
			);
		}

		$lines  = array();
		$fields = array();

		foreach ( $rows as $row ) {
			if ( empty( $row->date ) || '0000-00-00' === $row->date ) {
				continue;
			}

			// Ensure date is in YYYY-MM-DD format for GeoDirectory date fields.
			$date_value = sanitize_text_field( $row->date );
			$date_parts = explode( '-', $date_value );
			if ( count( $date_parts ) === 3 ) {
				// Validate and format date.
				$timestamp = strtotime( $date_value );
				if ( false !== $timestamp ) {
					$date_value = gmdate( 'Y-m-d', $timestamp );
				}
			}

			$label = ! empty( $row->type ) ? $this->format_type_label( $row->type ) : __( 'Date', 'geodir-converter' );

			if ( ! empty( $row->preferred ) ) {
				$label .= ' (' . __( 'Preferred', 'geodir-converter' ) . ')';
			}

			if ( ! empty( $row->visibility ) && 'public' !== strtolower( $row->visibility ) ) {
				$label .= sprintf( ' [%s]', ucwords( $row->visibility ) );
			}

			$formatted_date = mysql2date( get_option( 'date_format', 'F j, Y' ), $date_value );

			$lines[] = sprintf( '%1$s: %2$s', $label, $formatted_date );

			$slug = sanitize_key( $row->type );
			if ( 'birthday' === $slug ) {
				$field_key = 'connections_birthday';
			} elseif ( 'anniversary' === $slug ) {
				$field_key = 'connections_anniversary';
			} else {
				$field_key = 'connections_date_' . ( $slug ? $slug : 'custom' );
			}

			// For date fields, use first/preferred value only (date fields typically store single dates).
			if ( ! isset( $fields[ $field_key ] ) || ! empty( $row->preferred ) ) {
				$fields[ $field_key ] = $date_value;
			}
		}

		return array(
			'summary' => implode( "\n", $lines ),
			'fields'  => $fields,
		);
	}

	/**
	 * Prepare link summary + per-field values.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	private function get_link_field_data( $entry_id ) {
		$rows = $this->get_link_rows( $entry_id );

		if ( empty( $rows ) ) {
			return array(
				'summary' => '',
				'fields'  => array(),
			);
		}

		$lines  = array();
		$fields = array();

		foreach ( $rows as $row ) {
			$url = isset( $row->url ) ? esc_url_raw( $row->url ) : '';
			if ( '' === $url ) {
				continue;
			}

			$label = ! empty( $row->title ) ? $row->title : __( 'Link', 'geodir-converter' );

			if ( ! empty( $row->type ) ) {
				$label .= sprintf( ' (%s)', $this->format_type_label( $row->type ) );
			}

			$details = array( $url );

			if ( ! empty( $row->target ) ) {
				$details[] = sprintf( __( 'Target: %s', 'geodir-converter' ), $row->target );
			}

			if ( isset( $row->follow ) ) {
				$details[] = sprintf(
					__( 'Follow: %s', 'geodir-converter' ),
					$row->follow ? __( 'Yes', 'geodir-converter' ) : __( 'No', 'geodir-converter' )
				);
			}

			if ( ! empty( $row->image ) ) {
				$details[] = __( 'Shows as image', 'geodir-converter' );
			}

			if ( ! empty( $row->logo ) ) {
				$details[] = __( 'Displays as logo', 'geodir-converter' );
			}

			$lines[] = sprintf( '%1$s: %2$s', $label, implode( ' | ', $details ) );

			if ( $this->is_website_link_type( $row->type ) ) {
				continue;
			}

			$slug      = sanitize_key( $row->type );
			$field_key = 'connections_link_' . ( $slug ? $slug : 'custom' );

			$fields[ $field_key ][] = $url;
		}

		return array(
			'summary' => implode( "\n", $lines ),
			'fields'  => $this->normalize_field_value_map( $fields ),
		);
	}

	/**
	 * Normalize multi-value field arrays into strings.
	 *
	 * @param array  $field_values Map of field key => array values.
	 * @param string $glue         Implode glue.
	 * @return array
	 */
	private function normalize_field_value_map( array $field_values, $glue = "\n" ) {
		foreach ( $field_values as $key => $values ) {
			$values = array_filter( array_map( 'trim', (array) $values ) );

			if ( empty( $values ) ) {
				unset( $field_values[ $key ] );
				continue;
			}

			$field_values[ $key ] = implode( $glue, array_unique( $values ) );
		}

		return $field_values;
	}

	/**
	 * Determine if a Connections link type represents a website.
	 *
	 * @param string $type Link type.
	 * @return bool
	 */
	private function is_website_link_type( $type ) {
		$type = sanitize_key( $type );

		$website_types = array(
			'website',
			'web',
			'url',
			'site',
			'homepage',
			'home_page',
			'business',
			'personal',
		);

		return in_array( $type, $website_types, true ) || '' === $type;
	}
}
