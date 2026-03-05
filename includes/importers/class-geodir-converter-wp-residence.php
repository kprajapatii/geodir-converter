<?php
/**
 * WP Residence Converter Class.
 *
 * @since     2.1.4
 * @package   GeoDir_Converter
 */

namespace GeoDir_Converter\Importers;

use WP_Error;
use WP_Query;
use WP_Post;
use GeoDir_Media;
use GeoDir_Comments;
use WPInv_Invoice;
use GeoDir_Pricing_Package;
use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Main converter class for importing from WP Residence theme.
 *
 * @since 2.1.4
 */
class GeoDir_Converter_WP_Residence extends GeoDir_Converter_Importer {
	/**
	 * Post type identifier for properties.
	 *
	 * @var string
	 */
	const POST_TYPE_PROPERTY = 'estate_property';

	/**
	 * Post type identifier for agents.
	 *
	 * @var string
	 */
	const POST_TYPE_AGENT = 'estate_agent';

	/**
	 * Post type identifier for agencies.
	 *
	 * @var string
	 */
	const POST_TYPE_AGENCY = 'estate_agency';

	/**
	 * Post type identifier for reviews.
	 *
	 * @var string
	 */
	const POST_TYPE_REVIEW = 'estate_review';

	/**
	 * Post type identifier for invoices.
	 *
	 * @var string
	 */
	const POST_TYPE_INVOICE = 'wpestate_invoice';

	/**
	 * Post type identifier for membership packages.
	 *
	 * @var string
	 */
	const POST_TYPE_PACKAGE = 'membership_package';

	/**
	 * Taxonomy identifier for property categories.
	 *
	 * @var string
	 */
	const TAX_PROPERTY_CATEGORY = 'property_category';

	/**
	 * Taxonomy identifier for property action/type (sale, rent).
	 *
	 * @var string
	 */
	const TAX_PROPERTY_ACTION = 'property_action_category';

	/**
	 * Taxonomy identifier for property city.
	 *
	 * @var string
	 */
	const TAX_PROPERTY_CITY = 'property_city';

	/**
	 * Taxonomy identifier for property area/neighborhood.
	 *
	 * @var string
	 */
	const TAX_PROPERTY_AREA = 'property_area';

	/**
	 * Taxonomy identifier for property county/state.
	 *
	 * @var string
	 */
	const TAX_PROPERTY_STATE = 'property_county_state';

	/**
	 * Taxonomy identifier for property features.
	 *
	 * @var string
	 */
	const TAX_PROPERTY_FEATURES = 'property_features';

	/**
	 * Taxonomy identifier for property status.
	 *
	 * @var string
	 */
	const TAX_PROPERTY_STATUS = 'property_status';

	/**
	 * Import action for property types/actions.
	 */
	const ACTION_IMPORT_PROPERTY_TYPES = 'import_property_types';

	/**
	 * Import action for features.
	 */
	const ACTION_IMPORT_FEATURES = 'import_features';

	/**
	 * Import action for reviews.
	 */
	const ACTION_IMPORT_REVIEWS = 'import_reviews';

	/**
	 * Import action for packages.
	 */
	const ACTION_IMPORT_PACKAGES = 'import_packages';

	/**
	 * Import action for parsing invoices.
	 */
	const ACTION_PARSE_INVOICES = 'parse_invoices';

	/**
	 * Import action for invoices.
	 */
	const ACTION_IMPORT_INVOICES = 'import_invoices';

	/**
	 * GetPaid invoice post type.
	 */
	const GD_POST_TYPE_INVOICE = 'wpi_invoice';

	/**
	 * Batch size for chunking import tasks.
	 */
	const BATCH_SIZE_IMPORT = 10;

	/**
	 * Source identifier for imported items.
	 */
	const CREATED_VIA = 'geodir-converter';

	/**
	 * WP Residence meta keys.
	 */
	const META_PROPERTY_PRICE     = 'property_price';
	const META_PROPERTY_SIZE      = 'property_size';
	const META_PROPERTY_ROOMS     = 'property_rooms';
	const META_PROPERTY_BEDROOMS  = 'property_bedrooms';
	const META_PROPERTY_BATHROOMS = 'property_bathrooms';
	const META_PROPERTY_ADDRESS   = 'property_address';
	const META_PROPERTY_CITY      = 'property_city';
	const META_PROPERTY_AREA      = 'property_area';
	const META_PROPERTY_COUNTY    = 'property_county';
	const META_PROPERTY_STATE     = 'property_state';
	const META_PROPERTY_ZIP       = 'property_zip';
	const META_PROPERTY_COUNTRY   = 'property_country';
	const META_PROPERTY_LATITUDE  = 'property_latitude';
	const META_PROPERTY_LONGITUDE = 'property_longitude';
	const META_PROPERTY_GALLERY   = 'property_gallery_images';
	const META_PROPERTY_AGENT     = 'property_agent';
	const META_PROPERTY_FEATURED  = 'prop_featured';
	const META_PROPERTY_LOT_SIZE  = 'property_lot_size';
	const META_PROPERTY_YEAR      = 'property_year';
	const META_PROPERTY_GARAGE    = 'property_garage';
	const META_EMBED_VIDEO_TYPE   = 'embed_video_type';
	const META_EMBED_VIDEO_ID     = 'embed_video_id';
	const META_VIRTUAL_TOUR       = 'embed_virtual_tour';
	const META_AGENT_EMAIL        = 'agent_email';
	const META_AGENT_PHONE        = 'agent_phone';
	const META_AGENT_MOBILE       = 'agent_mobile';
	const META_AGENT_SKYPE        = 'agent_skype';
	const META_AGENT_POSITION     = 'agent_position';
	const META_AGENCY_EMAIL       = 'agency_email';
	const META_AGENCY_PHONE       = 'agency_phone';
	const META_SECOND_PRICE       = 'property_second_price';
	const META_PRICE_LABEL        = 'property_label';
	const META_PRICE_BEFORE_LABEL = 'property_price_before_label';
	const META_FLOOR_PLANS        = 'use_floor_plans';
	const META_PLAN_TITLE         = 'plan_title';
	const META_PLAN_DESCRIPTION   = 'plan_description';
	const META_PLAN_IMAGE         = 'plan_image';
	const META_PLAN_SIZE          = 'plan_size';
	const META_PLAN_ROOMS         = 'plan_rooms';
	const META_PLAN_BATH          = 'plan_bath';
	const META_PLAN_PRICE         = 'plan_price';
	const META_ENERGY_CLASS       = 'energy_class';
	const META_ENERGY_INDEX       = 'energy_index';
	const META_INTERNAL_ID        = 'property_internal_id';
	const META_HOA                = 'property_hoa';
	const META_TAXES              = 'property_taxes';

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
	protected $importer_id = 'wp_residence';

	/**
	 * The import listing status ID.
	 *
	 * @var array
	 */
	protected $post_statuses = array( 'publish', 'pending', 'draft', 'private', 'expired', 'disabled' );

	/**
	 * Property status mapping from WP Residence to display values.
	 *
	 * @var array
	 */
	protected $status_map = array(
		'normal'   => 'Available',
		'hot'      => 'Hot Offer',
		'featured' => 'Featured',
		'reduced'  => 'Reduced',
		'new'      => 'New',
		'sold'     => 'Sold',
		'rented'   => 'Rented',
	);

	/**
	 * Invoice status mapping from WP Residence to GetPaid.
	 *
	 * @var array
	 */
	protected $invoice_status_map = array(
		'confirmed' => 'publish',
		'pending'   => 'wpi-pending',
		'canceled'  => 'wpi-cancelled',
		'failed'    => 'wpi-failed',
	);

	/**
	 * Energy class options.
	 *
	 * @var array
	 */
	protected $energy_classes = array(
		'A+',
		'A',
		'B',
		'C',
		'D',
		'E',
		'F',
		'G',
	);

	/**
	 * Cached gd_equivalent term meta mapping.
	 *
	 * @var array|null
	 */
	private $gd_term_map_cache = null;

	/**
	 * Cached WP Residence custom fields definition.
	 *
	 * @var array|null
	 */
	private $custom_fields_cache = null;

	/**
	 * Cached packages mapping.
	 *
	 * @var array|null
	 */
	private $packages_mapping_cache = null;

	/**
	 * Cached import settings.
	 *
	 * @var array|null
	 */
	private $import_settings_cache = null;

	/**
	 * Initialize hooks.
	 *
	 * @since 2.1.4
	 *
	 * @return void
	 */
	protected function init() {
		add_action( 'init', array( $this, 'maybe_register_post_types' ), 0 );
	}

	/**
	 * Register WP Residence post types and taxonomies if not already registered.
	 *
	 * This allows the importer to work even when the WP Residence theme
	 * is not active, as long as the data exists in the database.
	 *
	 * @since 2.1.4
	 *
	 * @return void
	 */
	public function maybe_register_post_types() {
		if ( ! post_type_exists( self::POST_TYPE_PROPERTY ) ) {
			register_post_type(
				self::POST_TYPE_PROPERTY,
				array(
					'label'  => 'Properties',
					'public' => false,
				)
			);
		}

		$taxonomies = array(
			self::TAX_PROPERTY_CATEGORY => array(
				'label'        => 'Property Categories',
				'hierarchical' => true,
			),
			self::TAX_PROPERTY_ACTION   => array(
				'label'        => 'Property Actions',
				'hierarchical' => true,
			),
			self::TAX_PROPERTY_CITY     => array(
				'label'        => 'Property Cities',
				'hierarchical' => true,
			),
			self::TAX_PROPERTY_AREA     => array(
				'label'        => 'Property Areas',
				'hierarchical' => true,
			),
			self::TAX_PROPERTY_STATE    => array(
				'label'        => 'Property States',
				'hierarchical' => true,
			),
			self::TAX_PROPERTY_FEATURES => array(
				'label'        => 'Property Features',
				'hierarchical' => false,
			),
			self::TAX_PROPERTY_STATUS   => array(
				'label'        => 'Property Status',
				'hierarchical' => true,
			),
		);

		foreach ( $taxonomies as $taxonomy => $args ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy(
					$taxonomy,
					self::POST_TYPE_PROPERTY,
					array(
						'label'        => $args['label'],
						'public'       => false,
						'hierarchical' => $args['hierarchical'],
					)
				);
			}
		}
	}

	/**
	 * Check if a field should be skipped during import.
	 *
	 * @since 2.1.4
	 *
	 * @param string $field_name The field name to check.
	 * @return bool True if the field should be skipped, false otherwise.
	 */
	protected function should_skip_field( $field_name ) {
		$skip_fields = array(
			'property_gallery_images',
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
	 * @since 2.1.4
	 *
	 * @return string Importer title.
	 */
	public function get_title() {
		return __( 'WP Residence', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @since 2.1.4
	 *
	 * @return string Importer description.
	 */
	public function get_description() {
		return __( 'Import properties, agents, and reviews from your WP Residence theme installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @since 2.1.4
	 *
	 * @return string Icon URL.
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/wp-residence.png';
	}

	/**
	 * Get the first import task action.
	 *
	 * @since 2.1.4
	 *
	 * @return string Import action identifier.
	 */
	public function get_action() {
		return self::ACTION_IMPORT_CATEGORIES;
	}

	/**
	 * Render the importer settings form.
	 *
	 * @since 2.1.4
	 *
	 * @return void
	 */
	public function render_settings() {
		?>
		<form class="geodir-converter-settings-form" method="post">
			<h6 class="fs-base"><?php esc_html_e( 'WP Residence Importer Settings', 'geodir-converter' ); ?></h6>

			<?php
			// Check if WP Residence is active.
			if ( ! $this->is_wp_residence_active() ) {
				aui()->alert(
					array(
						'type'    => 'warning',
						'heading' => esc_html__( 'WP Residence theme not detected.', 'geodir-converter' ),
						'content' => esc_html__( 'Please make sure WP Residence theme is installed and was previously active. The importer will still work with existing data in the database.', 'geodir-converter' ),
						'class'   => 'mb-3',
					),
					true
				);
			}

			// Show plugin notices for optional features.
			if ( ! class_exists( 'GeoDir_Pricing_Package' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'GeoDirectory Pricing Manager', 'geodir-converter' ),
					'packages',
					esc_url( 'https://wpgeodirectory.com/downloads/pricing-manager/' )
				);
			}

			if ( ! class_exists( 'WPInv_Plugin' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'GetPaid (Invoicing)', 'geodir-converter' ),
					'invoices',
					esc_url( 'https://wordpress.org/plugins/invoicing/' )
				);
			}

			$this->display_post_type_select();
			$this->display_category_filter_select();
			$this->display_property_type_filter_select();
			$this->display_author_select( true );
			$this->display_import_agents_checkbox();
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
	 * Display the category filter multiselect.
	 *
	 * @since 2.1.4
	 *
	 * @return void
	 */
	protected function display_category_filter_select() {
		$selected_categories = $this->get_import_setting( 'filter_categories', array() );
		$categories          = $this->get_wp_residence_categories();

		if ( empty( $categories ) ) {
			return;
		}

		$options = array( '' => esc_html__( 'All Categories', 'geodir-converter' ) );
		foreach ( $categories as $cat ) {
			$prefix = '';
			if ( $cat->parent > 0 ) {
				$prefix = '— ';
			}
			$options[ $cat->term_id ] = $prefix . $cat->name . ' (' . $cat->count . ')';
		}

		aui()->select(
			array(
				'id'          => $this->importer_id . '_filter_categories',
				'name'        => 'filter_categories[]',
				'label'       => esc_html__( 'Filter by Property Categories', 'geodir-converter' ),
				'label_type'  => 'top',
				'label_class' => 'font-weight-bold fw-bold',
				'value'       => $selected_categories,
				'options'     => $options,
				'multiple'    => true,
				'select2'     => true,
				'help_text'   => esc_html__( 'Select specific categories to import. Leave empty to import all categories.', 'geodir-converter' ),
			),
			true
		);
	}

	/**
	 * Display the property type filter multiselect.
	 *
	 * @since 2.1.4
	 *
	 * @return void
	 */
	protected function display_property_type_filter_select() {
		$selected_types = $this->get_import_setting( 'filter_property_types', array() );
		$types          = $this->get_wp_residence_property_types();

		if ( empty( $types ) ) {
			return;
		}

		$options = array( '' => esc_html__( 'All Property Types', 'geodir-converter' ) );
		foreach ( $types as $type ) {
			$options[ $type->term_id ] = $type->name . ' (' . $type->count . ')';
		}

		aui()->select(
			array(
				'id'          => $this->importer_id . '_filter_property_types',
				'name'        => 'filter_property_types[]',
				'label'       => esc_html__( 'Filter by Property Types', 'geodir-converter' ),
				'label_type'  => 'top',
				'label_class' => 'font-weight-bold fw-bold',
				'value'       => $selected_types,
				'options'     => $options,
				'multiple'    => true,
				'select2'     => true,
				'help_text'   => esc_html__( 'Select specific property types (For Sale, For Rent, etc.) to import. Leave empty to import all types.', 'geodir-converter' ),
			),
			true
		);
	}

	/**
	 * Get WP Residence property categories.
	 *
	 * @since 2.1.4
	 *
	 * @return array Array of term objects.
	 */
	private function get_wp_residence_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX_PROPERTY_CATEGORY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		// Sort to show parent categories first, then children.
		$sorted = array();
		$this->sort_terms_hierarchically( $terms, $sorted );

		return $sorted;
	}

	/**
	 * Get WP Residence property types (action categories).
	 *
	 * @since 2.1.4
	 *
	 * @return array Array of term objects.
	 */
	private function get_wp_residence_property_types() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX_PROPERTY_ACTION,
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
	 * Sort terms hierarchically.
	 *
	 * @since 2.1.4
	 *
	 * @param array $terms  Array of term objects.
	 * @param array $sorted Reference to sorted array.
	 * @param int   $parent Parent term ID.
	 * @return void
	 */
	private function sort_terms_hierarchically( $terms, &$sorted, $parent = 0 ) {
		foreach ( $terms as $term ) {
			if ( $term->parent == $parent ) {
				$sorted[] = $term;
				$this->sort_terms_hierarchically( $terms, $sorted, $term->term_id );
			}
		}
	}

	/**
	 * Display the import agents checkbox.
	 *
	 * @since 2.1.4
	 *
	 * @return void
	 */
	protected function display_import_agents_checkbox() {
		$import_agents = (bool) $this->get_import_setting( 'import_agents', true );

		aui()->input(
			array(
				'id'          => $this->importer_id . '_import_agents',
				'type'        => 'checkbox',
				'name'        => 'import_agents',
				'label_type'  => 'top',
				'label_class' => 'font-weight-bold fw-bold',
				'label'       => esc_html__( 'Import Agents', 'geodir-converter' ),
				'checked'     => $import_agents,
				'value'       => 'yes',
				'switch'      => 'md',
				'help_text'   => esc_html__( 'Import agent information as post meta for each property.', 'geodir-converter' ),
			),
			true
		);
	}

	/**
	 * Check if WP Residence theme is active or data exists in the database.
	 *
	 * @since 2.1.4
	 *
	 * @return bool True if WP Residence is active or data exists.
	 */
	private function is_wp_residence_active() {
		$theme = wp_get_theme();
		if ( stripos( $theme->get( 'Name' ), 'residence' ) !== false ||
			stripos( $theme->get_template(), 'residence' ) !== false ) {
			return true;
		}

		// Check if data exists in the database from a previously active installation.
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1",
				self::POST_TYPE_PROPERTY
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Validate and sanitize importer settings.
	 *
	 * @since 2.1.4
	 *
	 * @param array $settings The settings to validate.
	 * @param array $files    The uploaded files to validate.
	 * @return array|WP_Error Validated and sanitized settings, or WP_Error on failure.
	 */
	public function validate_settings( array $settings, array $files = array() ) {
		$post_types = geodir_get_posttypes();
		$errors     = array();

		$settings['gd_post_type']  = isset( $settings['gd_post_type'] ) && ! empty( $settings['gd_post_type'] ) ? sanitize_text_field( $settings['gd_post_type'] ) : 'gd_place';
		$settings['wp_author_id']  = ( isset( $settings['wp_author_id'] ) && ! empty( $settings['wp_author_id'] ) ) ? absint( $settings['wp_author_id'] ) : get_current_user_id();
		$settings['test_mode']     = ( isset( $settings['test_mode'] ) && ! empty( $settings['test_mode'] ) && $settings['test_mode'] != 'no' ) ? 'yes' : 'no';
		$settings['import_agents'] = ( isset( $settings['import_agents'] ) && ! empty( $settings['import_agents'] ) && $settings['import_agents'] != 'no' ) ? 'yes' : 'no';

		// Sanitize category filter.
		$settings['filter_categories'] = isset( $settings['filter_categories'] ) && is_array( $settings['filter_categories'] )
			? array_filter( array_map( 'absint', $settings['filter_categories'] ) )
			: array();

		// Sanitize property type filter.
		$settings['filter_property_types'] = isset( $settings['filter_property_types'] ) && is_array( $settings['filter_property_types'] )
			? array_filter( array_map( 'absint', $settings['filter_property_types'] ) )
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
	 * Get next import task in the sequence.
	 *
	 * @since 2.1.4
	 *
	 * @param array $task         The current task data.
	 * @param bool  $reset_offset Whether to reset the offset counter.
	 * @return array|false The next task data, or false if all tasks are completed.
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
			self::ACTION_IMPORT_PROPERTY_TYPES,
			self::ACTION_IMPORT_FEATURES,
			self::ACTION_IMPORT_FIELDS,
			self::ACTION_IMPORT_PACKAGES,
			self::ACTION_PARSE_LISTINGS,
			self::ACTION_PARSE_INVOICES,
		);

		$key = array_search( $task['action'], $tasks, true );
		if ( false !== $key && $key + 1 < count( $tasks ) ) {
			$task['action'] = $tasks[ $key + 1 ];
			return $task;
		}

		return false;
	}

	/**
	 * Calculate and set the total number of items to be imported.
	 *
	 * Counts categories, types, features, fields, packages, listings,
	 * reviews, and invoices to determine the overall import total.
	 *
	 * @since 2.1.4
	 *
	 * @return void
	 */
	public function set_import_total() {
		global $wpdb;

		$total_items = 0;

		// Count property categories.
		$categories   = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_PROPERTY_CATEGORY ) );
		$total_items += is_wp_error( $categories ) ? 0 : (int) $categories;

		// Count property types/actions.
		$types        = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_PROPERTY_ACTION ) );
		$total_items += is_wp_error( $types ) ? 0 : (int) $types;

		// Count features.
		$features     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_PROPERTY_FEATURES ) );
		$total_items += is_wp_error( $features ) ? 0 : (int) $features;

		// Count custom fields.
		$custom_fields = $this->get_custom_fields();
		$total_items  += (int) count( $custom_fields );

		// Count packages.
		if ( class_exists( 'GeoDir_Pricing_Package' ) ) {
			$packages     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", self::POST_TYPE_PACKAGE ) );
			$total_items += is_wp_error( $packages ) ? 0 : (int) $packages;
		}

		// Count properties.
		$total_items += (int) $this->count_listings();

		// Count reviews (respecting listing filters).
		$filter_categories     = $this->get_import_setting( 'filter_categories', array() );
		$filter_property_types = $this->get_import_setting( 'filter_property_types', array() );

		if ( ! empty( $filter_categories ) || ! empty( $filter_property_types ) ) {
			$query_parts  = $this->build_listings_query( $filter_categories, $filter_property_types );
			$review_query = "SELECT COUNT(*) FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID" . $query_parts['joins'] . $query_parts['where'];
			$reviews      = $wpdb->get_var( $wpdb->prepare( $review_query, $query_parts['params'] ) );
		} else {
			$reviews = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->comments} c
					INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
					WHERE p.post_type = %s",
					self::POST_TYPE_PROPERTY
				)
			);
		}
		$total_items += is_wp_error( $reviews ) ? 0 : (int) $reviews;

		// Count invoices.
		if ( class_exists( 'WPInv_Plugin' ) ) {
			$invoices     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", self::POST_TYPE_INVOICE ) );
			$total_items += is_wp_error( $invoices ) ? 0 : (int) $invoices;
		}

		$this->increase_imports_total( $total_items );
	}

	/**
	 * Count the total number of properties to import.
	 *
	 * Respects any active category and property type filters.
	 *
	 * @since 2.1.4
	 *
	 * @return int Total number of properties matching the current filters.
	 */
	private function count_listings() {
		global $wpdb;

		$filter_categories     = $this->get_import_setting( 'filter_categories', array() );
		$filter_property_types = $this->get_import_setting( 'filter_property_types', array() );

		$query_parts = $this->build_listings_query( $filter_categories, $filter_property_types );

		$query = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p" . $query_parts['joins'] . $query_parts['where'];
		$count = $wpdb->get_var( $wpdb->prepare( $query, $query_parts['params'] ) );

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Build the shared query parts for listing queries with filters.
	 *
	 * @since 2.1.4
	 *
	 * @param array $filter_categories     Category term IDs to filter by.
	 * @param array $filter_property_types Property type term IDs to filter by.
	 * @return array Array with 'joins', 'where', and 'params' keys.
	 */
	private function build_listings_query( $filter_categories, $filter_property_types ) {
		global $wpdb;

		$joins  = '';
		$where  = ' WHERE p.post_type = %s AND p.post_status IN (' . implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) ) . ')';
		$params = array_merge( array( self::POST_TYPE_PROPERTY ), $this->post_statuses );

		// Add category filter join.
		if ( ! empty( $filter_categories ) ) {
			$joins           .= " INNER JOIN {$wpdb->term_relationships} tr_cat ON p.ID = tr_cat.object_id";
			$joins           .= " INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tr_cat.term_taxonomy_id = tt_cat.term_taxonomy_id";
			$cat_placeholders = implode( ',', array_fill( 0, count( $filter_categories ), '%d' ) );
			$where           .= " AND tt_cat.taxonomy = %s AND tt_cat.term_id IN ({$cat_placeholders})";
			$params[]         = self::TAX_PROPERTY_CATEGORY;
			$params           = array_merge( $params, $filter_categories );
		}

		// Add property type filter join.
		if ( ! empty( $filter_property_types ) ) {
			$joins            .= " INNER JOIN {$wpdb->term_relationships} tr_type ON p.ID = tr_type.object_id";
			$joins            .= " INNER JOIN {$wpdb->term_taxonomy} tt_type ON tr_type.term_taxonomy_id = tt_type.term_taxonomy_id";
			$type_placeholders = implode( ',', array_fill( 0, count( $filter_property_types ), '%d' ) );
			$where            .= " AND tt_type.taxonomy = %s AND tt_type.term_id IN ({$type_placeholders})";
			$params[]          = self::TAX_PROPERTY_ACTION;
			$params            = array_merge( $params, $filter_property_types );
		}

		return array(
			'joins'  => $joins,
			'where'  => $where,
			'params' => $params,
		);
	}

	/**
	 * Get custom fields for WP Residence properties.
	 *
	 * @since 2.1.4
	 *
	 * @return array Array of custom fields.
	 */
	private function get_custom_fields() {
		$fields = array(
			array(
				'type'           => 'text',
				'data_type'      => 'INT',
				'field_key'      => $this->importer_id . '_id',
				'label'          => __( 'WP Residence ID', 'geodir-converter' ),
				'description'    => __( 'Original WP Residence Property ID.', 'geodir-converter' ),
				'placeholder'    => __( 'WP Residence ID', 'geodir-converter' ),
				'icon'           => 'far fa-id-card',
				'only_for_admin' => 1,
				'required'       => 0,
			),
			array(
				'type'        => 'text',
				'data_type'   => 'FLOAT',
				'field_key'   => 'property_price',
				'label'       => __( 'Price', 'geodir-converter' ),
				'description' => __( 'The price of the property.', 'geodir-converter' ),
				'placeholder' => __( 'Price', 'geodir-converter' ),
				'icon'        => 'fas fa-dollar-sign',
				'required'    => 0,
				'extra'       => array(
					'is_price'                  => 1,
					'thousand_separator'        => 'comma',
					'decimal_separator'         => 'period',
					'decimal_display'           => 'if',
					'currency_symbol'           => '$',
					'currency_symbol_placement' => 'left',
				),
			),
			array(
				'type'        => 'text',
				'data_type'   => 'FLOAT',
				'field_key'   => 'property_second_price',
				'label'       => __( 'Second Price', 'geodir-converter' ),
				'description' => __( 'Alternative price (e.g., monthly rent).', 'geodir-converter' ),
				'placeholder' => __( 'Second Price', 'geodir-converter' ),
				'icon'        => 'fas fa-money-bill',
				'required'    => 0,
				'extra'       => array(
					'is_price'                  => 1,
					'thousand_separator'        => 'comma',
					'decimal_separator'         => 'period',
					'decimal_display'           => 'if',
					'currency_symbol'           => '$',
					'currency_symbol_placement' => 'left',
				),
			),
			array(
				'type'        => 'text',
				'data_type'   => 'VARCHAR',
				'field_key'   => 'price_label',
				'label'       => __( 'Price Label', 'geodir-converter' ),
				'description' => __( 'Price unit label (e.g., per month).', 'geodir-converter' ),
				'placeholder' => __( 'Price Label', 'geodir-converter' ),
				'icon'        => 'fas fa-tag',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'data_type'   => 'VARCHAR',
				'field_key'   => 'price_before_label',
				'label'       => __( 'Price Before Label', 'geodir-converter' ),
				'description' => __( 'Label displayed before the price (e.g., Starting from).', 'geodir-converter' ),
				'placeholder' => __( 'Price Before Label', 'geodir-converter' ),
				'icon'        => 'fas fa-tag',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'data_type'   => 'INT',
				'field_key'   => 'property_size',
				'label'       => __( 'Property Size', 'geodir-converter' ),
				'description' => __( 'The size of the property in sq ft.', 'geodir-converter' ),
				'placeholder' => __( 'Size', 'geodir-converter' ),
				'icon'        => 'fas fa-ruler-combined',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'data_type'   => 'INT',
				'field_key'   => 'property_lot_size',
				'label'       => __( 'Lot Size', 'geodir-converter' ),
				'description' => __( 'The lot size of the property.', 'geodir-converter' ),
				'placeholder' => __( 'Lot Size', 'geodir-converter' ),
				'icon'        => 'fas fa-expand',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'data_type'   => 'INT',
				'field_key'   => 'property_rooms',
				'label'       => __( 'Rooms', 'geodir-converter' ),
				'description' => __( 'Total number of rooms.', 'geodir-converter' ),
				'placeholder' => __( 'Rooms', 'geodir-converter' ),
				'icon'        => 'fas fa-door-open',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'data_type'   => 'INT',
				'field_key'   => 'property_bedrooms',
				'label'       => __( 'Bedrooms', 'geodir-converter' ),
				'description' => __( 'Number of bedrooms.', 'geodir-converter' ),
				'placeholder' => __( 'Bedrooms', 'geodir-converter' ),
				'icon'        => 'fas fa-bed',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'data_type'   => 'INT',
				'field_key'   => 'property_bathrooms',
				'label'       => __( 'Bathrooms', 'geodir-converter' ),
				'description' => __( 'Number of bathrooms.', 'geodir-converter' ),
				'placeholder' => __( 'Bathrooms', 'geodir-converter' ),
				'icon'        => 'fas fa-bath',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'data_type'   => 'INT',
				'field_key'   => 'property_garage',
				'label'       => __( 'Garage', 'geodir-converter' ),
				'description' => __( 'Number of garage spaces.', 'geodir-converter' ),
				'placeholder' => __( 'Garage', 'geodir-converter' ),
				'icon'        => 'fas fa-car',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'data_type'   => 'VARCHAR',
				'field_key'   => 'property_year',
				'label'       => __( 'Year Built', 'geodir-converter' ),
				'description' => __( 'Year the property was built.', 'geodir-converter' ),
				'placeholder' => __( 'Year Built', 'geodir-converter' ),
				'icon'        => 'fas fa-calendar',
				'required'    => 0,
			),
			array(
				'type'        => 'select',
				'field_key'   => 'property_type',
				'label'       => __( 'Property Type', 'geodir-converter' ),
				'description' => __( 'Property listing type (e.g., For Sale, For Rent).', 'geodir-converter' ),
				'placeholder' => __( 'Property Type', 'geodir-converter' ),
				'icon'        => 'fas fa-home',
				'required'    => 0,
				'options'     => implode( ',', $this->get_taxonomy_term_names( self::TAX_PROPERTY_ACTION ) ),
			),
			array(
				'type'        => 'select',
				'field_key'   => 'property_status',
				'label'       => __( 'Property Status', 'geodir-converter' ),
				'description' => __( 'Current status of the property.', 'geodir-converter' ),
				'placeholder' => __( 'Status', 'geodir-converter' ),
				'icon'        => 'fas fa-info-circle',
				'required'    => 0,
				'options'     => implode( ',', $this->get_taxonomy_term_names( self::TAX_PROPERTY_STATUS ) ),
			),
			array(
				'type'        => 'select',
				'field_key'   => 'energy_class',
				'label'       => __( 'Energy Class', 'geodir-converter' ),
				'description' => __( 'Energy efficiency class.', 'geodir-converter' ),
				'placeholder' => __( 'Energy Class', 'geodir-converter' ),
				'icon'        => 'fas fa-leaf',
				'required'    => 0,
				'options'     => implode( ',', $this->energy_classes ),
			),
			array(
				'type'        => 'text',
				'field_key'   => 'energy_index',
				'label'       => __( 'Energy Index', 'geodir-converter' ),
				'description' => __( 'Energy index value.', 'geodir-converter' ),
				'placeholder' => __( 'Energy Index', 'geodir-converter' ),
				'icon'        => 'fas fa-bolt',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'field_key'   => 'property_internal_id',
				'label'       => __( 'MLS/Internal ID', 'geodir-converter' ),
				'description' => __( 'Internal or MLS property ID.', 'geodir-converter' ),
				'placeholder' => __( 'Internal ID', 'geodir-converter' ),
				'icon'        => 'fas fa-fingerprint',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'field_key'   => 'property_hoa',
				'label'       => __( 'HOA Fees', 'geodir-converter' ),
				'description' => __( 'Home Owner Association fees.', 'geodir-converter' ),
				'placeholder' => __( 'HOA Fees', 'geodir-converter' ),
				'icon'        => 'fas fa-home',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'field_key'   => 'property_taxes',
				'label'       => __( 'Property Taxes', 'geodir-converter' ),
				'description' => __( 'Annual property taxes.', 'geodir-converter' ),
				'placeholder' => __( 'Property Taxes', 'geodir-converter' ),
				'icon'        => 'fas fa-file-invoice-dollar',
				'required'    => 0,
			),
			array(
				'type'        => 'checkbox',
				'field_key'   => 'featured',
				'label'       => __( 'Is Featured?', 'geodir-converter' ),
				'description' => __( 'Mark property as featured.', 'geodir-converter' ),
				'placeholder' => __( 'Is Featured?', 'geodir-converter' ),
				'icon'        => 'fas fa-star',
				'required'    => 0,
			),
			array(
				'type'        => 'url',
				'field_key'   => 'video_url',
				'label'       => __( 'Video URL', 'geodir-converter' ),
				'description' => __( 'Property video URL.', 'geodir-converter' ),
				'placeholder' => __( 'Video URL', 'geodir-converter' ),
				'icon'        => 'fas fa-video',
				'required'    => 0,
			),
			array(
				'type'        => 'textarea',
				'field_key'   => 'virtual_tour',
				'label'       => __( 'Virtual Tour', 'geodir-converter' ),
				'description' => __( 'Virtual tour embed code.', 'geodir-converter' ),
				'placeholder' => __( 'Virtual Tour', 'geodir-converter' ),
				'icon'        => 'fas fa-vr-cardboard',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'field_key'   => 'agent_name',
				'label'       => __( 'Agent Name', 'geodir-converter' ),
				'description' => __( 'Name of the property agent.', 'geodir-converter' ),
				'placeholder' => __( 'Agent Name', 'geodir-converter' ),
				'icon'        => 'fas fa-user-tie',
				'required'    => 0,
			),
			array(
				'type'        => 'email',
				'field_key'   => 'agent_email',
				'label'       => __( 'Agent Email', 'geodir-converter' ),
				'description' => __( 'Email address of the agent.', 'geodir-converter' ),
				'placeholder' => __( 'Agent Email', 'geodir-converter' ),
				'icon'        => 'far fa-envelope',
				'required'    => 0,
			),
			array(
				'type'        => 'phone',
				'field_key'   => 'agent_phone',
				'label'       => __( 'Agent Phone', 'geodir-converter' ),
				'description' => __( 'Phone number of the agent.', 'geodir-converter' ),
				'placeholder' => __( 'Agent Phone', 'geodir-converter' ),
				'icon'        => 'fas fa-phone',
				'required'    => 0,
			),
			array(
				'type'        => 'phone',
				'field_key'   => 'agent_mobile',
				'label'       => __( 'Agent Mobile', 'geodir-converter' ),
				'description' => __( 'Mobile number of the agent.', 'geodir-converter' ),
				'placeholder' => __( 'Agent Mobile', 'geodir-converter' ),
				'icon'        => 'fas fa-mobile-alt',
				'required'    => 0,
			),
			array(
				'type'        => 'text',
				'field_key'   => 'agent_position',
				'label'       => __( 'Agent Position', 'geodir-converter' ),
				'description' => __( 'Job position of the agent.', 'geodir-converter' ),
				'placeholder' => __( 'Agent Position', 'geodir-converter' ),
				'icon'        => 'fas fa-briefcase',
				'required'    => 0,
			),
		);

		// Add custom fields from WP Residence theme settings.
		$wp_estate_custom_fields = $this->get_wp_residence_custom_fields();
		foreach ( $wp_estate_custom_fields as $custom_field ) {
			$fields[] = array(
				'type'        => 'text',
				'data_type'   => 'VARCHAR',
				'field_key'   => $custom_field['field_key'],
				'label'       => $custom_field['label'],
				/* translators: %s: field name */
			'description' => sprintf( __( 'WP Residence custom field: %s', 'geodir-converter' ), $custom_field['label'] ),
				'placeholder' => $custom_field['label'],
				'icon'        => 'fas fa-info-circle',
				'required'    => 0,
			);
		}

		return $fields;
	}

	/**
	 * Get term names for a taxonomy as a flat array.
	 *
	 * @since 2.1.6
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array Term names.
	 */
	private function get_taxonomy_term_names( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'names',
			)
		);

		return ! empty( $terms ) && ! is_wp_error( $terms ) ? $terms : array();
	}

	/**
	 * Get WP Residence custom fields from theme settings.
	 *
	 * WP Residence stores custom fields in 'wp_estate_custom_fields' option.
	 * Each field is an array: $field[0] = name, $field[1] = label
	 * The meta key is generated by: sanitize_key(mb_substr(sanitize_title($name), 0, 45))
	 *
	 * @since 2.1.4
	 *
	 * @return array Array of custom fields with 'meta_key', 'field_key', and 'label'.
	 */
	private function get_wp_residence_custom_fields() {
		if ( null !== $this->custom_fields_cache ) {
			return $this->custom_fields_cache;
		}

		$custom_fields = array();

		// WP Residence uses wpresidence_get_option but it retrieves from theme options.
		// We try multiple sources for the custom fields.
		$wp_estate_custom_fields = $this->get_wpresidence_option( 'wp_estate_custom_fields' );

		if ( empty( $wp_estate_custom_fields ) || ! is_array( $wp_estate_custom_fields ) ) {
			return $custom_fields;
		}

		foreach ( $wp_estate_custom_fields as $field ) {
			// Skip empty fields.
			if ( empty( $field[0] ) ) {
				continue;
			}

			$name  = $field[0];
			$label = isset( $field[1] ) ? stripslashes( $field[1] ) : $name;

			// Generate the meta key the same way WP Residence does.
			// See: wpresidence_generate_custom_fields() in property_details_section_functions.php
			$meta_key  = sanitize_key( mb_substr( sanitize_title( $name ), 0, 45 ) );
			$field_key = str_replace( '-', '_', $meta_key );

			$custom_fields[] = array(
				'meta_key'  => $meta_key,
				'field_key' => $field_key,
				'label'     => $label,
				'name'      => $name,
			);
		}

		$this->custom_fields_cache = $custom_fields;

		return $custom_fields;
	}

	/**
	 * Get WP Residence theme option.
	 *
	 * WP Residence uses Redux framework which stores options in wp_options.
	 * The option name is typically 'wpresidence_admin' or similar.
	 *
	 * @since 2.1.4
	 *
	 * @param string $option_name The option name to retrieve.
	 * @return mixed The option value or empty array.
	 */
	private function get_wpresidence_option( $option_name ) {
		// Try using the theme function if available.
		if ( function_exists( 'wpresidence_get_option' ) ) {
			return wpresidence_get_option( $option_name, '' );
		}

		// Try Redux framework option storage.
		$redux_options = get_option( 'wpresidence_admin', array() );
		if ( ! empty( $redux_options ) && isset( $redux_options[ $option_name ] ) ) {
			return $redux_options[ $option_name ];
		}

		// Try direct option.
		$direct_option = get_option( $option_name, array() );
		if ( ! empty( $direct_option ) ) {
			return $direct_option;
		}

		return array();
	}

	/**
	 * Get database data type for field type.
	 *
	 * @since 2.1.4
	 *
	 * @param string $field_type Field type.
	 * @return string Data type.
	 */
	private function get_data_type_for_field( $field_type ) {
		$type_map = array(
			'text'        => 'VARCHAR',
			'email'       => 'TEXT',
			'url'         => 'TEXT',
			'phone'       => 'VARCHAR',
			'checkbox'    => 'TINYINT',
			'radio'       => 'VARCHAR',
			'select'      => 'VARCHAR',
			'multiselect' => 'VARCHAR',
			'textarea'    => 'TEXT',
			'datepicker'  => 'DATE',
		);

		return isset( $type_map[ $field_type ] ) ? $type_map[ $field_type ] : 'VARCHAR';
	}

	/**
	 * Extract numeric value from a string.
	 *
	 * Extracts the first number found in a string. Useful for fields like
	 * "2 cars" where we want to store just the number 2.
	 *
	 * @since 2.1.4
	 *
	 * @param mixed $value The value to extract number from.
	 * @return int|string The extracted number or empty string if no number found.
	 */
	private function extract_numeric_value( $value ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		if ( empty( $value ) || ! is_string( $value ) ) {
			return '';
		}

		// Extract the first number from the string.
		if ( preg_match( '/(\d+)/', $value, $matches ) ) {
			return (int) $matches[1];
		}

		return '';
	}

	/**
	 * Import categories from WP Residence to GeoDirectory.
	 *
	 * @since 2.1.4
	 *
	 * @param array $task Import task.
	 * @return array Result of the import operation.
	 */
	public function task_import_categories( $task ) {
		global $wpdb;
		$this->log( __( 'Categories: Import started.', 'geodir-converter' ) );
		$this->set_import_total();

		if ( 0 === intval(
			wp_count_terms(
				array(
					'taxonomy'   => self::TAX_PROPERTY_CATEGORY,
					'hide_empty' => false,
				)
			)
		) ) {
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
				self::TAX_PROPERTY_CATEGORY
			)
		);

		if ( empty( $categories ) || is_wp_error( $categories ) ) {
			$this->log( __( 'Categories: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		if ( $this->is_test_mode() ) {
			$this->log(
				/* translators: %1$d: number imported, %2$d: number failed */
				sprintf(
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
			/* translators: %1$d: number imported, %2$d: number failed */
			sprintf(
				__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$result['imported'],
				$result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Import property types/actions from WP Residence to GeoDirectory.
	 *
	 * @since 2.1.4
	 *
	 * @param array $task Import task.
	 * @return array Result of the import operation.
	 */
	public function task_import_property_types( $task ) {
		global $wpdb;
		$this->log( __( 'Property Types: Import started.', 'geodir-converter' ) );

		if ( 0 === intval(
			wp_count_terms(
				array(
					'taxonomy'   => self::TAX_PROPERTY_ACTION,
					'hide_empty' => false,
				)
			)
		) ) {
			$this->log( __( 'Property Types: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$post_type = $this->get_import_post_type();

		$types = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, tt.*
				FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				ORDER BY t.name ASC",
				self::TAX_PROPERTY_ACTION
			)
		);

		if ( empty( $types ) || is_wp_error( $types ) ) {
			$this->log( __( 'Property Types: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		if ( $this->is_test_mode() ) {
			$this->log(
				/* translators: %1$d: number imported, %2$d: number failed */
				sprintf(
					__( 'Property Types: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
					count( $types ),
					0
				),
				'success'
			);
			return $this->next_task( $task );
		}

		// Import as tags taxonomy.
		$result = $this->import_taxonomy_terms( $types, $post_type . '_tags', 'ct_cat_top_desc' );

		$this->increase_succeed_imports( (int) $result['imported'] );
		$this->increase_failed_imports( (int) $result['failed'] );

		$this->log(
			/* translators: %1$d: number imported, %2$d: number failed */
			sprintf(
				__( 'Property Types: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$result['imported'],
				$result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Import features from WP Residence to GeoDirectory as a multiselect custom field.
	 *
	 * @since 2.1.4
	 *
	 * @param array $task Import task data.
	 * @return array|false Next task data, or false when complete.
	 */
	public function task_import_features( $task ) {
		global $wpdb;
		$this->log( __( 'Features: Creating multiselect field...', 'geodir-converter' ) );

		if ( 0 === intval(
			wp_count_terms(
				array(
					'taxonomy'   => self::TAX_PROPERTY_FEATURES,
					'hide_empty' => false,
				)
			)
		) ) {
			$this->log( __( 'Features: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$post_type   = $this->get_import_post_type();
		$package_ids = $this->get_package_ids( $post_type );

		// Get all feature terms.
		$features = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.name, t.slug
				FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				ORDER BY t.name ASC",
				self::TAX_PROPERTY_FEATURES
			)
		);

		if ( empty( $features ) || is_wp_error( $features ) ) {
			$this->log( __( 'Features: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$feature_names  = wp_list_pluck( $features, 'name' );
		$option_values  = implode( ',', $feature_names );
		$existing_field = geodir_get_field_infoby( 'htmlvar_name', 'features', $post_type );

		if ( $this->is_test_mode() ) {
			$this->log(
				/* translators: %1$s: action (created/updated), %2$d: number of options */
				sprintf(
					__( 'Features: Field would be %1$s with %2$d options.', 'geodir-converter' ),
					$existing_field ? 'updated' : 'created',
					count( $features )
				),
				'success'
			);
			$this->increase_succeed_imports( count( $features ) );
			return $this->next_task( $task );
		}

		$field = array(
			'post_type'          => $post_type,
			'field_type'         => 'multiselect',
			'data_type'          => 'VARCHAR',
			'admin_title'        => __( 'Features & Amenities', 'geodir-converter' ),
			'frontend_title'     => __( 'Features & Amenities', 'geodir-converter' ),
			'frontend_desc'      => __( 'Select the property features and amenities.', 'geodir-converter' ),
			'htmlvar_name'       => 'features',
			'is_active'          => '1',
			'for_admin_use'      => '0',
			'default_value'      => '',
			'show_in'            => '[detail],[listing]',
			'is_required'        => '0',
			'option_values'      => $option_values,
			'validation_pattern' => '',
			'validation_msg'     => '',
			'required_msg'       => '',
			'field_icon'         => 'fas fa-check-square',
			'css_class'          => 'gd-comma-list',
			'cat_sort'           => '1',
			'cat_filter'         => '1',
			'show_on_pkg'        => $package_ids,
			'clabels'            => __( 'Features & Amenities', 'geodir-converter' ),
			'single_use'         => '0',
		);

		if ( $existing_field ) {
			$field['field_id'] = (int) $existing_field['id'];

			if ( ! empty( $existing_field['option_values'] ) ) {
				$existing_options       = array_map( 'trim', explode( ',', $existing_field['option_values'] ) );
				$new_options            = array_map( 'trim', explode( ',', $option_values ) );
				$merged_options         = array_unique( array_merge( $existing_options, $new_options ) );
				$field['option_values'] = implode( ',', $merged_options );
			}
		}

		$result = geodir_custom_field_save( $field );

		if ( is_wp_error( $result ) ) {
			$this->log( __( 'Features: Failed to create/update field.', 'geodir-converter' ), 'error' );
			$this->increase_failed_imports( 1 );
		} else {
			$this->log(
				/* translators: %1$s: action (created/updated), %2$d: number of options */
				sprintf(
					__( 'Features: Field %1$s successfully with %2$d options.', 'geodir-converter' ),
					$existing_field ? 'updated' : 'created',
					count( $features )
				),
				'success'
			);
			$this->increase_succeed_imports( count( $features ) );
		}

		return $this->next_task( $task );
	}

	/**
	 * Import fields from WP Residence to GeoDirectory.
	 *
	 * @since 2.1.4
	 *
	 * @param array $task Task details.
	 * @return array Result of the import operation.
	 */
	public function task_import_fields( array $task ) {
		$this->log( __( 'Importing property fields...', 'geodir-converter' ) );

		$post_type   = $this->get_import_post_type();
		$fields      = $this->get_custom_fields();
		$package_ids = $this->get_package_ids( $post_type );

		if ( empty( $fields ) ) {
			$this->log( __( 'No custom fields to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$imported = $updated = $skipped = $failed = 0;

		foreach ( $fields as $field ) {
			$gd_field = $this->prepare_single_field( $field, $post_type, $package_ids );

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

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_skipped_imports( $skipped );
		$this->increase_failed_imports( $failed );

		$this->log(
			/* translators: %1$d: imported count, %2$d: updated count, %3$d: skipped count, %4$d: failed count */
			sprintf(
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
	 * Convert WP Residence field to GD field.
	 *
	 * @since 2.1.4
	 *
	 * @param array  $field       The WP Residence field data.
	 * @param string $post_type   The post type.
	 * @param array  $package_ids The package IDs.
	 * @return array The GD field data.
	 */
	private function prepare_single_field( $field, $post_type, $package_ids = array() ) {
		$field_type = isset( $field['type'] ) ? $field['type'] : 'text';
		$field_id   = $this->field_exists( $field['field_key'], $post_type );

		// Use data_type from field definition if provided, otherwise derive from field type.
		$data_type = isset( $field['data_type'] ) ? $field['data_type'] : $this->get_data_type_for_field( $field_type );

		$gd_field = array(
			'post_type'     => $post_type,
			'data_type'     => $data_type,
			'field_type'    => $field_type,
			'htmlvar_name'  => $field['field_key'],
			'is_active'     => '1',
			'option_values' => '',
			'is_default'    => '0',
		);

		if ( $field_id ) {
			$gd_field['field_id'] = $field_id;
		}

		$option_values = '';
		if ( isset( $field['options'] ) && ! empty( $field['options'] ) ) {
			$option_values = is_array( $field['options'] ) ? implode( ',', $field['options'] ) : $field['options'];
		}

		$gd_field = array_merge(
			$gd_field,
			array(
				'admin_title'       => $field['label'],
				'frontend_desc'     => isset( $field['description'] ) ? $field['description'] : '',
				'placeholder_value' => isset( $field['placeholder'] ) ? $field['placeholder'] : '',
				'frontend_title'    => $field['label'],
				'default_value'     => '',
				'for_admin_use'     => isset( $field['only_for_admin'] ) && 1 === $field['only_for_admin'] ? 1 : 0,
				'is_required'       => isset( $field['required'] ) && 1 === $field['required'] ? 1 : 0,
				'show_in'           => '[detail]',
				'show_on_pkg'       => $package_ids,
				'clabels'           => $field['label'],
				'option_values'     => $option_values,
				'field_icon'        => isset( $field['icon'] ) ? $field['icon'] : 'fas fa-info-circle',
			)
		);

		// Handle checkbox data type.
		if ( 'checkbox' === $field_type ) {
			$gd_field['data_type'] = 'TINYINT';
		}

		// Handle extra fields (e.g., price formatting options).
		if ( isset( $field['extra'] ) && ! empty( $field['extra'] ) ) {
			$gd_field['extra_fields'] = maybe_serialize( $field['extra'] );
			$gd_field['extra']        = $field['extra'];
		}

		return $gd_field;
	}

	/**
	 * Import packages/plans from WP Residence.
	 *
	 * @since 2.1.4
	 *
	 * @param array $task Import task.
	 * @return array|bool Updated task or false.
	 */
	public function task_import_packages( array $task ) {
		global $wpdb;

		if ( ! class_exists( 'GeoDir_Pricing_Package' ) ) {
			$this->log( __( 'Pricing Manager not active. Skipping packages...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$post_type = $this->get_import_post_type();
		$packages  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content, post_status, menu_order
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_status = 'publish'
				ORDER BY menu_order ASC",
				self::POST_TYPE_PACKAGE
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

			$pack_listings  = isset( $package_meta['pack_listings'] ) ? absint( $package_meta['pack_listings'] ) : 0;
			$pack_price     = isset( $package_meta['pack_price'] ) ? floatval( $package_meta['pack_price'] ) : 0.0;
			$pack_time      = isset( $package_meta['pack_time_type'] ) ? absint( $package_meta['pack_time_type'] ) : 30;
			$pack_unlimited = isset( $package_meta['mem_list_unl'] ) ? absint( $package_meta['mem_list_unl'] ) : 0;
			$pack_featured  = isset( $package_meta['pack_featured'] ) ? absint( $package_meta['pack_featured'] ) : 0;
			$is_free        = 0.0 === $pack_price || 0 === (int) $pack_price;

			$existing_package = $this->package_exists( $post_type, $package_id, $is_free );

			$package_data = array(
				'post_type'       => $post_type,
				'name'            => $package->post_title,
				'title'           => $package->post_title,
				'description'     => $package->post_content,
				'fa_icon'         => '',
				'amount'          => $pack_price,
				'time_interval'   => $pack_time > 0 ? $pack_time : 30,
				'time_unit'       => 'D',
				'recurring'       => false,
				'recurring_limit' => 0,
				'trial'           => '',
				'trial_amount'    => '',
				'trial_interval'  => '',
				'trial_unit'      => '',
				'is_default'      => ( 'publish' === $package->post_status && 1 === (int) $package->menu_order ) ? 1 : 0,
				'display_order'   => (int) $package->menu_order,
				'downgrade_pkg'   => 0,
				'post_status'     => 'pending',
				'status'          => 'publish' === $package->post_status ? true : false,
			);

			if ( $existing_package ) {
				$package_data['id'] = absint( $existing_package->id );
			}

			if ( $this->is_test_mode() ) {
				$existing_package ? $updated++ : $imported++;
				continue;
			}

			$package_data = GeoDir_Pricing_Package::prepare_data_for_save( $package_data );
			$new_pack_id  = GeoDir_Pricing_Package::insert_package( $package_data );

			if ( ! $new_pack_id || is_wp_error( $new_pack_id ) ) {
				/* translators: %s: package title */
				$this->log( sprintf( __( 'Failed to import package: %s', 'geodir-converter' ), $package->post_title ), 'error' );
				++$failed;
			} else {
				$is_update = ! empty( $existing_package );

				$log_message = $is_update
					/* translators: %s: package title */
					? sprintf( __( 'Updated package: %s', 'geodir-converter' ), $package->post_title )
					/* translators: %s: package title */
					: sprintf( __( 'Imported package: %s', 'geodir-converter' ), $package->post_title );
				$this->log( $log_message );

				$is_update ? ++$updated : ++$imported;

				if ( ! $this->package_has_meta( $new_pack_id ) ) {
					GeoDir_Pricing_Package::update_meta( $new_pack_id, '_wp_residence_package_id', $package_id );
				}
			}
		}

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_failed_imports( $failed );

		$this->log(
			/* translators: %1$d: imported count, %2$d: updated count, %3$d: failed count */
			sprintf(
				__( 'Packages: %1$d imported, %2$d updated, %3$d failed', 'geodir-converter' ),
				$imported,
				$updated,
				$failed
			),
			'info'
		);

		return $this->next_task( $task );
	}

	/**
	 * Check if package exists by WP Residence package ID.
	 *
	 * @since 2.1.4
	 *
	 * @param string $post_type     Post type.
	 * @param int    $package_id    WP Residence package ID.
	 * @param bool   $free_fallback Whether to fallback to free package if no match found.
	 * @return object|null Existing package object or null.
	 */
	private function package_exists( $post_type, $package_id, $free_fallback = true ) {
		global $wpdb;

		if ( ! defined( 'GEODIR_PRICING_PACKAGES_TABLE' ) || ! defined( 'GEODIR_PRICING_PACKAGE_META_TABLE' ) ) {
			return null;
		}

		$existing_package = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT p.*, g.*
				FROM ' . GEODIR_PRICING_PACKAGES_TABLE . ' AS p
				INNER JOIN ' . GEODIR_PRICING_PACKAGE_META_TABLE . ' AS g ON p.ID = g.package_id
				WHERE p.post_type = %s AND g.meta_key = %s AND g.meta_value = %d
				LIMIT 1',
				$post_type,
				'_wp_residence_package_id',
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
	 * Check if package already has WP Residence meta assigned.
	 *
	 * @since 2.1.4
	 *
	 * @param int $package_id Package ID.
	 * @return bool True if package has WP Residence meta, false otherwise.
	 */
	private function package_has_meta( $package_id ) {
		global $wpdb;

		if ( ! defined( 'GEODIR_PRICING_PACKAGE_META_TABLE' ) ) {
			return false;
		}

		$meta_value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM ' . GEODIR_PRICING_PACKAGE_META_TABLE . '
				WHERE package_id = %d AND meta_key = %s
				LIMIT 1',
				(int) $package_id,
				'_wp_residence_package_id'
			)
		);

		return ! empty( $meta_value );
	}

	/**
	 * Get packages mapping from WP Residence package IDs to GeoDirectory package IDs.
	 *
	 * @since 2.1.4
	 *
	 * @return array Packages mapping array.
	 */
	private function get_packages_mapping() {
		if ( null !== $this->packages_mapping_cache ) {
			return $this->packages_mapping_cache;
		}

		global $wpdb;

		if ( ! defined( 'GEODIR_PRICING_PACKAGE_META_TABLE' ) ) {
			$this->packages_mapping_cache = array();
			return $this->packages_mapping_cache;
		}

		$this->packages_mapping_cache = array();
		$results                      = $wpdb->get_results(
			'SELECT meta_value AS wpr_id, package_id AS gd_id
			FROM ' . GEODIR_PRICING_PACKAGE_META_TABLE . "
			WHERE meta_key = '_wp_residence_package_id'"
		);

		foreach ( $results as $row ) {
			$this->packages_mapping_cache[ $row->wpr_id ] = $row->gd_id;
		}

		return $this->packages_mapping_cache;
	}

	/**
	 * Parse and batch listings for background import.
	 *
	 * @since 2.1.4
	 *
	 * @param array $task The task to import.
	 * @return array Result of the import operation.
	 */
	public function task_parse_listings( array $task ) {
		$offset         = isset( $task['offset'] ) ? (int) $task['offset'] : 0;
		$total_listings = isset( $task['total_listings'] ) ? (int) $task['total_listings'] : 0;
		$batch_size     = (int) $this->get_batch_size();

		if ( ! isset( $task['total_listings'] ) ) {
			$total_listings         = $this->count_listings();
			$task['total_listings'] = $total_listings;
		}

		// Get filter settings.
		$filter_categories     = $this->get_import_setting( 'filter_categories', array() );
		$filter_property_types = $this->get_import_setting( 'filter_property_types', array() );

		if ( 0 === $offset ) {
			$filter_msg = '';
			if ( ! empty( $filter_categories ) ) {
				/* translators: %d: number of categories */
				$filter_msg .= sprintf( __( ' (filtered by %d categories)', 'geodir-converter' ), count( $filter_categories ) );
			}
			if ( ! empty( $filter_property_types ) ) {
				/* translators: %d: number of property types */
				$filter_msg .= sprintf( __( ' (filtered by %d property types)', 'geodir-converter' ), count( $filter_property_types ) );
			}
			/* translators: %1$d: number of properties, %2$s: filter description */
			$this->log( sprintf( __( 'Starting property parsing process: %1$d properties found%2$s.', 'geodir-converter' ), $total_listings, $filter_msg ) );
		}

		if ( 0 === $total_listings ) {
			$this->log( __( 'No properties found for parsing. Skipping process.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		wp_suspend_cache_addition( true );

		// Build the filtered query.
		$listings = $this->get_filtered_listings( $filter_categories, $filter_property_types, $batch_size, $offset );

		if ( empty( $listings ) ) {
			$this->log( __( 'Import process completed. No more properties found.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$batched_tasks = array_chunk( $listings, self::BATCH_SIZE_IMPORT, true );
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
	 * Get filtered listings based on category and property type filters.
	 *
	 * @since 2.1.4
	 *
	 * @param array $filter_categories    Category term IDs to filter by.
	 * @param array $filter_property_types Property type term IDs to filter by.
	 * @param int   $limit                Number of results to return.
	 * @param int   $offset               Offset for pagination.
	 * @return array Array of post objects.
	 */
	private function get_filtered_listings( $filter_categories, $filter_property_types, $limit, $offset ) {
		global $wpdb;

		$query_parts = $this->build_listings_query( $filter_categories, $filter_property_types );

		$query  = "SELECT DISTINCT p.ID, p.post_title, p.post_status FROM {$wpdb->posts} p";
		$query .= $query_parts['joins'] . $query_parts['where'];
		$query .= ' ORDER BY p.ID ASC LIMIT %d OFFSET %d';

		$params   = $query_parts['params'];
		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $query, $params ) );
	}

	/**
	 * Import a batch of listings (called by background process).
	 *
	 * @since 2.1.4
	 *
	 * @param array $task The task to import.
	 * @return bool Result of the import operation.
	 */
	public function task_import_listings( $task ) {
		$listings = isset( $task['listings'] ) && ! empty( $task['listings'] ) ? (array) $task['listings'] : array();

		/* translators: %d: number of properties in batch */
		$this->log( sprintf( __( 'Processing batch of %d properties...', 'geodir-converter' ), count( $listings ) ) );

		$packages_mapping = $this->is_test_mode() ? array() : $this->get_packages_mapping();

		foreach ( $listings as $listing ) {
			$label  = $listing->post_title . ' (#' . $listing->ID . ')';
			$status = $this->import_single_listing( $listing, $packages_mapping );

			$this->process_import_result( $status, 'property', $label, $listing->ID );
		}

		$this->flush_failed_items();

		return false;
	}

	/**
	 * Convert a single WP Residence property to GeoDirectory format.
	 *
	 * @since 2.1.4
	 *
	 * @param object $listing          The post object to convert.
	 * @param array  $packages_mapping Packages mapping.
	 * @return int Import status.
	 */
	private function import_single_listing( $listing, $packages_mapping = array() ) {
		$post = get_post( $listing->ID );

		if ( ! $post ) {
			return GeoDir_Converter_Importer::IMPORT_STATUS_FAILED;
		}

		$post_type        = $this->get_import_post_type();
		$gd_post_id       = ! $this->is_test_mode() ? $this->get_gd_listing_id( $post->ID, 'wp_residence_id', $post_type ) : false;
		$is_update        = ! empty( $gd_post_id );
		$post_meta        = $this->get_post_meta( $post->ID );
		$default_location = $this->get_default_location();

		// Get categories.
		$categories = $this->get_listings_terms( $post->ID, self::TAX_PROPERTY_CATEGORY );

		// Get property types as tags.
		$types = $this->get_listings_terms( $post->ID, self::TAX_PROPERTY_ACTION, 'names' );

		// Get property type for the dedicated field (first type term name).
		$type_terms    = wp_get_post_terms( $post->ID, self::TAX_PROPERTY_ACTION, array( 'fields' => 'names' ) );
		$property_type = ! empty( $type_terms ) && ! is_wp_error( $type_terms ) ? $type_terms[0] : '';

		// Get features.
		$feature_terms = wp_get_post_terms( $post->ID, self::TAX_PROPERTY_FEATURES, array( 'fields' => 'names' ) );
		$feature_names = is_array( $feature_terms ) && ! is_wp_error( $feature_terms ) ? $feature_terms : array();

		// Get property status.
		$status_terms    = wp_get_post_terms( $post->ID, self::TAX_PROPERTY_STATUS, array( 'fields' => 'names' ) );
		$property_status = ! empty( $status_terms ) && ! is_wp_error( $status_terms ) ? $status_terms[0] : '';

		// Process location data.
		$location = $default_location;
		$lat      = isset( $post_meta[ self::META_PROPERTY_LATITUDE ] ) ? $post_meta[ self::META_PROPERTY_LATITUDE ] : '';
		$lng      = isset( $post_meta[ self::META_PROPERTY_LONGITUDE ] ) ? $post_meta[ self::META_PROPERTY_LONGITUDE ] : '';

		if ( ! empty( $lat ) && ! empty( $lng ) ) {
			$location['latitude']  = $lat;
			$location['longitude'] = $lng;
		}

		// Build address from meta.
		$street_address = ! empty( $post_meta[ self::META_PROPERTY_ADDRESS ] ) ? $post_meta[ self::META_PROPERTY_ADDRESS ] : '';

		// Build location from WP Residence meta values.
		if ( ! empty( $post_meta[ self::META_PROPERTY_CITY ] ) ) {
			$location['city'] = $post_meta[ self::META_PROPERTY_CITY ];
		}
		if ( ! empty( $post_meta[ self::META_PROPERTY_STATE ] ) ) {
			$location['region'] = $post_meta[ self::META_PROPERTY_STATE ];
		} elseif ( ! empty( $post_meta[ self::META_PROPERTY_COUNTY ] ) ) {
			$location['region'] = $post_meta[ self::META_PROPERTY_COUNTY ];
		}
		if ( ! empty( $post_meta[ self::META_PROPERTY_COUNTRY ] ) ) {
			$location['country'] = $post_meta[ self::META_PROPERTY_COUNTRY ];
		}
		if ( ! empty( $post_meta[ self::META_PROPERTY_ZIP ] ) ) {
			$location['zip'] = $post_meta[ self::META_PROPERTY_ZIP ];
		}
		if ( ! empty( $post_meta[ self::META_PROPERTY_AREA ] ) ) {
			$location['neighbourhood'] = $post_meta[ self::META_PROPERTY_AREA ];
		}

		// Always reverse geocode to normalize location data format.
		$location = $this->geocode_location( $lat, $lng, $location, $post->ID );

		$location = wp_parse_args(
			$location,
			array(
				'city'          => '',
				'region'        => '',
				'country'       => '',
				'zip'           => '',
				'latitude'      => '',
				'longitude'     => '',
				'neighbourhood' => '',
			)
		);

		// Process video URL.
		$video_url  = '';
		$video_type = isset( $post_meta[ self::META_EMBED_VIDEO_TYPE ] ) ? $post_meta[ self::META_EMBED_VIDEO_TYPE ] : '';
		$video_id   = isset( $post_meta[ self::META_EMBED_VIDEO_ID ] ) ? $post_meta[ self::META_EMBED_VIDEO_ID ] : '';
		if ( ! empty( $video_id ) ) {
			if ( 'youtube' === $video_type || 'vimeo' === $video_type ) {
				$video_url = 'youtube' === $video_type
					? 'https://www.youtube.com/watch?v=' . $video_id
					: 'https://vimeo.com/' . $video_id;
			} else {
				$video_url = $video_id;
			}
		}

		// Get agent info (only if import_agents setting is enabled).
		$agent_name     = '';
		$agent_email    = '';
		$agent_phone    = '';
		$agent_mobile   = '';
		$agent_position = '';

		if ( 'yes' === $this->get_import_setting( 'import_agents', 'yes' ) ) {
			$agent_id    = isset( $post_meta[ self::META_PROPERTY_AGENT ] ) ? absint( $post_meta[ self::META_PROPERTY_AGENT ] ) : 0;
			$agent_email = isset( $post_meta[ self::META_AGENT_EMAIL ] ) ? $post_meta[ self::META_AGENT_EMAIL ] : '';
			$agent_phone = isset( $post_meta[ self::META_AGENT_PHONE ] ) ? $post_meta[ self::META_AGENT_PHONE ] : '';

			if ( $agent_id ) {
				$agent_post = get_post( $agent_id );
				if ( $agent_post ) {
					$agent_name = $agent_post->post_title;
					$agent_meta = $this->get_post_meta( $agent_id );
					if ( empty( $agent_email ) && ! empty( $agent_meta['agent_email'] ) ) {
						$agent_email = $agent_meta['agent_email'];
					}
					if ( empty( $agent_phone ) && ! empty( $agent_meta['agent_phone'] ) ) {
						$agent_phone = $agent_meta['agent_phone'];
					}
					if ( ! empty( $agent_meta[ self::META_AGENT_MOBILE ] ) ) {
						$agent_mobile = $agent_meta[ self::META_AGENT_MOBILE ];
					}
					if ( ! empty( $agent_meta[ self::META_AGENT_POSITION ] ) ) {
						$agent_position = $agent_meta[ self::META_AGENT_POSITION ];
					}
				}
			}
		}

		// Map post status.
		$gd_status = 'publish';
		if ( in_array( $post->post_status, array( 'expired', 'disabled' ), true ) ) {
			$gd_status = 'draft';
		} elseif ( in_array( $post->post_status, array( 'pending', 'draft', 'private' ), true ) ) {
			$gd_status = $post->post_status;
		}

		// Build the listing data.
		$gd_listing = array(
			'post_author'           => $post->post_author ? $post->post_author : $this->get_import_setting( 'wp_author_id', get_current_user_id() ),
			'post_title'            => $post->post_title,
			'post_content'          => $post->post_content ? $post->post_content : '',
			'post_content_filtered' => $post->post_content,
			'post_excerpt'          => $post->post_excerpt ? $post->post_excerpt : '',
			'post_status'           => $gd_status,
			'post_type'             => $post_type,
			'comment_status'        => $post->comment_status,
			'ping_status'           => $post->ping_status,
			'post_name'             => $post->post_name ? $post->post_name : 'property-' . $post->ID,
			'post_date_gmt'         => $post->post_date_gmt,
			'post_date'             => $post->post_date,
			'post_modified_gmt'     => $post->post_modified_gmt,
			'post_modified'         => $post->post_modified,
			'tax_input'             => array(
				$post_type . 'category' => $categories,
				$post_type . '_tags'    => $types,
			),

			// GD fields.
			'default_category'      => ! empty( $categories ) ? $categories[0] : 0,
			'featured_image'        => $this->get_featured_image( $post->ID ),
			'submit_ip'             => '',
			'overall_rating'        => 0,
			'rating_count'          => 0,

			// Location.
			'street'                => $street_address,
			'street2'               => '',
			'city'                  => $location['city'],
			'region'                => $location['region'],
			'country'               => $location['country'],
			'zip'                   => $location['zip'],
			'neighbourhood'         => $location['neighbourhood'],
			'latitude'              => $location['latitude'],
			'longitude'             => $location['longitude'],
			'mapview'               => '',
			'mapzoom'               => '',

			// Custom fields.
			'wp_residence_id'       => $post->ID,
			'property_price'        => isset( $post_meta[ self::META_PROPERTY_PRICE ] ) ? $post_meta[ self::META_PROPERTY_PRICE ] : '',
			'property_second_price' => isset( $post_meta[ self::META_SECOND_PRICE ] ) ? $post_meta[ self::META_SECOND_PRICE ] : '',
			'price_label'           => isset( $post_meta[ self::META_PRICE_LABEL ] ) ? $post_meta[ self::META_PRICE_LABEL ] : '',
			'price_before_label'    => isset( $post_meta[ self::META_PRICE_BEFORE_LABEL ] ) ? $post_meta[ self::META_PRICE_BEFORE_LABEL ] : '',
			'property_size'         => isset( $post_meta[ self::META_PROPERTY_SIZE ] ) ? $post_meta[ self::META_PROPERTY_SIZE ] : '',
			'property_lot_size'     => isset( $post_meta[ self::META_PROPERTY_LOT_SIZE ] ) ? $post_meta[ self::META_PROPERTY_LOT_SIZE ] : '',
			'property_rooms'        => isset( $post_meta[ self::META_PROPERTY_ROOMS ] ) ? $post_meta[ self::META_PROPERTY_ROOMS ] : '',
			'property_bedrooms'     => isset( $post_meta[ self::META_PROPERTY_BEDROOMS ] ) ? $post_meta[ self::META_PROPERTY_BEDROOMS ] : '',
			'property_bathrooms'    => isset( $post_meta[ self::META_PROPERTY_BATHROOMS ] ) ? $post_meta[ self::META_PROPERTY_BATHROOMS ] : '',
			'property_garage'       => isset( $post_meta[ self::META_PROPERTY_GARAGE ] ) ? $this->extract_numeric_value( $post_meta[ self::META_PROPERTY_GARAGE ] ) : '',
			'property_year'         => isset( $post_meta[ self::META_PROPERTY_YEAR ] ) ? $post_meta[ self::META_PROPERTY_YEAR ] : '',
			'property_type'         => $property_type,
			'property_status'       => $property_status,
			'energy_class'          => isset( $post_meta[ self::META_ENERGY_CLASS ] ) ? $post_meta[ self::META_ENERGY_CLASS ] : '',
			'energy_index'          => isset( $post_meta[ self::META_ENERGY_INDEX ] ) ? $post_meta[ self::META_ENERGY_INDEX ] : '',
			'property_internal_id'  => isset( $post_meta[ self::META_INTERNAL_ID ] ) ? $post_meta[ self::META_INTERNAL_ID ] : '',
			'property_hoa'          => isset( $post_meta[ self::META_HOA ] ) ? $post_meta[ self::META_HOA ] : '',
			'property_taxes'        => isset( $post_meta[ self::META_TAXES ] ) ? $post_meta[ self::META_TAXES ] : '',
			'featured'              => isset( $post_meta[ self::META_PROPERTY_FEATURED ] ) ? (int) $post_meta[ self::META_PROPERTY_FEATURED ] : 0,
			'video_url'             => $video_url,
			'virtual_tour'          => isset( $post_meta[ self::META_VIRTUAL_TOUR ] ) ? $post_meta[ self::META_VIRTUAL_TOUR ] : '',
			'features'              => ! empty( $feature_names ) ? implode( ',', $feature_names ) : '',
			'agent_name'            => $agent_name,
			'agent_email'           => $agent_email,
			'agent_phone'           => $agent_phone,
			'agent_mobile'          => $agent_mobile,
			'agent_position'        => $agent_position,
		);

		// Add user-defined custom fields from WP Residence theme settings.
		$wp_residence_custom_fields = $this->get_wp_residence_custom_fields();
		foreach ( $wp_residence_custom_fields as $custom_field ) {
			$meta_key  = $custom_field['meta_key'];
			$field_key = $custom_field['field_key'];

			if ( isset( $post_meta[ $meta_key ] ) && '' !== $post_meta[ $meta_key ] ) {
				$gd_listing[ $field_key ] = $post_meta[ $meta_key ];
			}
		}

		if ( $this->is_test_mode() ) {
			// Count reviews that would be imported so progress is accurate.
			$review_count = $this->count_property_reviews( $post->ID );
			if ( $review_count > 0 ) {
				$this->increase_succeed_imports( $review_count );
			}

			return GeoDir_Converter_Importer::IMPORT_STATUS_SUCCESS;
		}

		if ( $is_update ) {
			GeoDir_Media::delete_files( (int) $gd_post_id, 'post_images' );
		}

		// Process images.
		$gd_listing['post_images'] = $this->get_post_images( $post_meta, $post->ID );

		// Listing package.
		if ( class_exists( 'GeoDir_Pricing_Package' ) && ! empty( $packages_mapping ) ) {
			$user_pack_id = get_user_meta( $post->post_author, 'package_id', true );
			if ( $user_pack_id && ! empty( $packages_mapping[ $user_pack_id ] ) ) {
				$gd_listing['package_id'] = (int) $packages_mapping[ $user_pack_id ];
			}
		}

		if ( $is_update ) {
			$gd_listing['ID'] = absint( $gd_post_id );
			$gd_post_id       = wp_update_post( $gd_listing, true );
		} else {
			$gd_post_id = wp_insert_post( $gd_listing, true );
		}

		if ( is_wp_error( $gd_post_id ) ) {
			$this->log( $gd_post_id->get_error_message() );
			return GeoDir_Converter_Importer::IMPORT_STATUS_FAILED;
		}

		// Import reviews/comments.
		$this->add_reviews_import_tasks( $post->ID, $gd_post_id );

		return $is_update ? GeoDir_Converter_Importer::IMPORT_STATUS_UPDATED : GeoDir_Converter_Importer::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Get listings terms as GD equivalent term IDs.
	 *
	 * @since 2.1.4
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $return   Return type (ids or names).
	 * @return array Term IDs or names.
	 */
	private function get_listings_terms( $post_id, $taxonomy, $return = 'ids' ) {
		global $wpdb;

		$terms = wp_get_post_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		if ( 'names' === $return ) {
			return wp_list_pluck( $terms, 'name' );
		}

		$map      = $this->get_gd_term_map();
		$term_ids = array();
		foreach ( $terms as $term ) {
			if ( isset( $map[ $term->term_id ] ) ) {
				$term_ids[] = (int) $map[ $term->term_id ];
			}
		}

		return $term_ids;
	}

	/**
	 * Get the gd_equivalent term meta mapping for all terms, cached.
	 *
	 * @since 2.1.4
	 *
	 * @return array Associative array of source_term_id => gd_term_id.
	 */
	private function get_gd_term_map() {
		if ( null !== $this->gd_term_map_cache ) {
			return $this->gd_term_map_cache;
		}

		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = 'gd_equivalent' AND meta_value != ''"
		);

		$this->gd_term_map_cache = array();
		foreach ( $results as $row ) {
			$this->gd_term_map_cache[ (int) $row->term_id ] = (int) $row->meta_value;
		}

		return $this->gd_term_map_cache;
	}

	/**
	 * Get featured image URL.
	 *
	 * @since 2.1.4
	 *
	 * @param int $post_id Post ID.
	 * @return string Featured image URL.
	 */
	private function get_featured_image( $post_id ) {
		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( $thumbnail_id ) {
			$image_url = wp_get_attachment_url( $thumbnail_id );
			return $image_url ? $image_url : '';
		}

		return '';
	}

	/**
	 * Get post images formatted for GeoDirectory.
	 *
	 * @since 2.1.4
	 *
	 * @param array $post_meta Post meta array.
	 * @param int   $post_id   Post ID.
	 * @return string Formatted images string.
	 */
	private function get_post_images( $post_meta, $post_id ) {
		$images = array();

		// Get featured image first.
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			$images[] = array(
				'id'      => $thumbnail_id,
				'caption' => '',
				'weight'  => 0,
			);
		}

		// Get gallery images.
		$gallery_ids = isset( $post_meta[ self::META_PROPERTY_GALLERY ] ) ? maybe_unserialize( $post_meta[ self::META_PROPERTY_GALLERY ] ) : '';

		if ( empty( $gallery_ids ) ) {
			// Try alternative meta key.
			$gallery_ids = isset( $post_meta['wpestate_property_gallery'] ) ? maybe_unserialize( $post_meta['wpestate_property_gallery'] ) : '';
		}

		if ( ! empty( $gallery_ids ) ) {
			$gallery_array = is_array( $gallery_ids ) ? $gallery_ids : explode( ',', $gallery_ids );

			$weight = 1;
			foreach ( $gallery_array as $attachment_id ) {
				$attachment_id = absint( trim( $attachment_id ) );
				if ( $attachment_id && $attachment_id !== $thumbnail_id ) {
					$images[] = array(
						'id'      => $attachment_id,
						'caption' => '',
						'weight'  => $weight++,
					);
				}
			}
		}

		return $this->format_images_data( $images );
	}

	/**
	 * Count reviews for a WP Residence property.
	 *
	 * @since 2.1.4
	 *
	 * @param int $post_id WP Residence property ID.
	 * @return int Number of reviews.
	 */
	private function count_property_reviews( $post_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} c
				WHERE c.comment_post_ID = %d
				AND c.comment_type IN ('', 'comment', 'review')",
				$post_id
			)
		);
	}

	/**
	 * Add review import tasks for a property.
	 *
	 * @since 2.1.4
	 *
	 * @param int $wpr_post_id WP Residence property ID.
	 * @param int $gd_post_id  GeoDirectory post ID.
	 * @return void
	 */
	private function add_reviews_import_tasks( $wpr_post_id, $gd_post_id ) {
		global $wpdb;

		// Get comments/reviews for this property.
		$reviews = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, cm.meta_value as rating
				FROM {$wpdb->comments} c
				LEFT JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_id AND cm.meta_key = 'review_stars'
				WHERE c.comment_post_ID = %d
				AND c.comment_type IN ('', 'comment', 'review')
				ORDER BY c.comment_ID ASC",
				$wpr_post_id
			)
		);

		if ( empty( $reviews ) ) {
			return;
		}

		// Batch reviews and queue them.
		$batched_reviews = array_chunk( $reviews, self::BATCH_SIZE_IMPORT );
		$import_tasks    = array();

		foreach ( $batched_reviews as $review_batch ) {
			$review_data = array();

			foreach ( $review_batch as $review ) {
				$review_data[] = array(
					'comment_id'       => $review->comment_ID,
					'comment_content'  => $review->comment_content,
					'comment_author'   => $review->comment_author,
					'comment_email'    => $review->comment_author_email,
					'user_id'          => $review->user_id,
					'comment_date'     => $review->comment_date,
					'comment_date_gmt' => $review->comment_date_gmt,
					'comment_approved' => $review->comment_approved,
					'rating'           => $review->rating ? absint( $review->rating ) : 0,
				);
			}

			$import_tasks[] = array(
				'action'     => self::ACTION_IMPORT_REVIEWS,
				'gd_post_id' => $gd_post_id,
				'reviews'    => $review_data,
			);
		}

		$this->background_process->add_import_tasks( $import_tasks );
	}

	/**
	 * Import a batch of reviews (called by background process).
	 *
	 * @since 2.1.4
	 *
	 * @param array $task Import task.
	 * @return bool Result of the import operation.
	 */
	public function task_import_reviews( $task ) {
		$gd_post_id = isset( $task['gd_post_id'] ) ? absint( $task['gd_post_id'] ) : 0;
		$reviews    = isset( $task['reviews'] ) && is_array( $task['reviews'] ) ? $task['reviews'] : array();

		if ( ! $gd_post_id || empty( $reviews ) ) {
			return false;
		}

		if ( $this->is_test_mode() ) {
			$this->increase_succeed_imports( count( $reviews ) );
			return false;
		}

		$imported = $skipped = $failed = 0;

		foreach ( $reviews as $review_data ) {
			$original_comment_id = $review_data['comment_id'];
			$rating              = $review_data['rating'];
			$review_agent        = 'geodir-converter-wpr-' . $original_comment_id;

			// Check if already imported.
			$existing_review = get_comments(
				array(
					'comment_agent' => $review_agent,
					'number'        => 1,
				)
			);

			$comment_data = array(
				'comment_post_ID'      => $gd_post_id,
				'user_id'              => $review_data['user_id'],
				'comment_date'         => $review_data['comment_date'],
				'comment_date_gmt'     => $review_data['comment_date_gmt'],
				'comment_content'      => $review_data['comment_content'],
				'comment_author'       => $review_data['comment_author'],
				'comment_author_email' => $review_data['comment_email'],
				'comment_agent'        => $review_agent,
				'comment_approved'     => $review_data['comment_approved'],
				'comment_type'         => 'review',
			);

			$is_existing = ! empty( $existing_review ) && isset( $existing_review[0]->comment_ID );

			if ( $is_existing ) {
				$comment_data['comment_ID'] = (int) $existing_review[0]->comment_ID;
				$comment_id                 = wp_update_comment( $comment_data );
			} else {
				$comment_id = wp_insert_comment( $comment_data );
			}

			if ( is_wp_error( $comment_id ) || ! $comment_id ) {
				++$failed;
				continue;
			}

			$is_existing ? ++$skipped : ++$imported;

			// Save rating.
			if ( $rating && class_exists( 'GeoDir_Comments' ) ) {
				$_REQUEST['geodir_overallrating'] = absint( $rating );
				GeoDir_Comments::save_rating( $comment_id );
				unset( $_REQUEST['geodir_overallrating'] );
			}
		}

		$this->increase_succeed_imports( $imported );
		$this->increase_skipped_imports( $skipped );
		$this->increase_failed_imports( $failed );

		if ( $imported + $skipped > 0 ) {
			$this->log(
				/* translators: %1$d: imported count, %2$d: skipped count, %3$d: failed count */
				sprintf(
					__( 'Review batch: %1$d imported, %2$d skipped, %3$d failed', 'geodir-converter' ),
					$imported,
					$skipped,
					$failed
				),
				'info'
			);
		}

		return false;
	}

	/**
	 * Parse and batch invoices for background import.
	 *
	 * @since 2.1.4
	 *
	 * @param array $task Import task.
	 * @return array|false Updated task or false.
	 */
	public function task_parse_invoices( array $task ) {
		global $wpdb;

		if ( ! class_exists( 'WPInv_Plugin' ) ) {
			$this->log( __( 'Invoicing plugin is not active. Skipping invoices...', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		$offset         = isset( $task['offset'] ) ? (int) $task['offset'] : 0;
		$total_invoices = isset( $task['total_invoices'] ) ? (int) $task['total_invoices'] : 0;
		$batch_size     = (int) $this->get_batch_size();

		if ( ! isset( $task['total_invoices'] ) ) {
			$total_invoices         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", self::POST_TYPE_INVOICE ) );
			$task['total_invoices'] = $total_invoices;
		}

		if ( 0 === $offset ) {
			/* translators: %d: number of invoices */
			$this->log( sprintf( __( 'Starting invoice parsing process: %d invoices found.', 'geodir-converter' ), $total_invoices ) );
		}

		if ( 0 === $total_invoices ) {
			$this->log( __( 'No invoices found for parsing. Skipping process.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		wp_suspend_cache_addition( true );

		$invoices = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_status, post_author, post_date, post_date_gmt
				FROM {$wpdb->posts}
				WHERE post_type = %s
				ORDER BY ID ASC
				LIMIT %d OFFSET %d",
				self::POST_TYPE_INVOICE,
				$batch_size,
				$offset
			)
		);

		if ( empty( $invoices ) ) {
			$this->log( __( 'Invoice parsing completed. No more invoices found.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		$batched_tasks = array_chunk( $invoices, self::BATCH_SIZE_IMPORT, true );
		$import_tasks  = array();
		foreach ( $batched_tasks as $batch ) {
			$import_tasks[] = array(
				'action'   => self::ACTION_IMPORT_INVOICES,
				'invoices' => $batch,
			);
		}

		$this->background_process->add_import_tasks( $import_tasks );

		$complete = ( $offset + $batch_size >= $total_invoices );

		if ( ! $complete ) {
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		return $this->next_task( $task, true );
	}

	/**
	 * Import a batch of invoices (called by background process).
	 *
	 * @since 2.1.4
	 *
	 * @param array $task Import task.
	 * @return bool Result of the import operation.
	 */
	public function task_import_invoices( $task ) {
		$invoices = isset( $task['invoices'] ) && is_array( $task['invoices'] ) ? $task['invoices'] : array();

		if ( empty( $invoices ) ) {
			return false;
		}

		if ( $this->is_test_mode() ) {
			$this->increase_succeed_imports( count( $invoices ) );
			return false;
		}

		$packages_mapping = $this->get_packages_mapping();
		$imported         = $skipped = $failed = 0;

		foreach ( $invoices as $invoice ) {
			$result = $this->import_single_invoice( $invoice, $packages_mapping );

			if ( self::IMPORT_STATUS_SUCCESS === $result || self::IMPORT_STATUS_UPDATED === $result ) {
				++$imported;
			} elseif ( self::IMPORT_STATUS_SKIPPED === $result ) {
				++$skipped;
			} else {
				++$failed;
				$invoice_label = $invoice->post_title . ' (#' . $invoice->ID . ')';
				$this->record_failed_item( $invoice->ID, self::ACTION_IMPORT_INVOICES, 'invoice', $invoice_label, sprintf( self::LOG_TEMPLATE_FAILED, 'invoice', $invoice_label ) );
			}
		}

		$this->increase_succeed_imports( $imported );
		$this->increase_skipped_imports( $skipped );
		$this->increase_failed_imports( $failed );

		$this->flush_failed_items();

		if ( $imported + $skipped + $failed > 0 ) {
			$this->log(
				/* translators: %1$d: imported count, %2$d: skipped count, %3$d: failed count */
				sprintf(
					__( 'Invoice batch: %1$d imported, %2$d skipped, %3$d failed', 'geodir-converter' ),
					$imported,
					$skipped,
					$failed
				),
				'info'
			);
		}

		return false;
	}

	/**
	 * Import a single WP Residence invoice to GetPaid.
	 *
	 * @since 2.1.4
	 *
	 * @param object $invoice          The WP Residence invoice post object.
	 * @param array  $packages_mapping Packages mapping.
	 * @return int Import status constant.
	 */
	private function import_single_invoice( $invoice, $packages_mapping = array() ) {
		$invoice_id   = absint( $invoice->ID );
		$invoice_meta = $this->get_post_meta( $invoice_id );

		// Check if already imported.
		$existing_id = $this->get_gd_post_id( $invoice_id, '_wp_residence_invoice_id' );
		$is_update   = ! empty( $existing_id );

		// Get invoice details from meta.
		$invoice_price  = isset( $invoice_meta['invoice_price'] ) ? floatval( $invoice_meta['invoice_price'] ) : 0;
		$invoice_status = isset( $invoice_meta['invoice_status'] ) ? strtolower( $invoice_meta['invoice_status'] ) : 'pending';
		$package_id     = isset( $invoice_meta['invoice_pack'] ) ? absint( $invoice_meta['invoice_pack'] ) : 0;
		$user_id        = absint( $invoice->post_author );
		$payment_type   = isset( $invoice_meta['invoice_payment_type'] ) ? $invoice_meta['invoice_payment_type'] : '';
		$transaction_id = isset( $invoice_meta['invoice_transaction_id'] ) ? $invoice_meta['invoice_transaction_id'] : '';
		$currency       = isset( $invoice_meta['invoice_currency'] ) ? $invoice_meta['invoice_currency'] : 'USD';

		// Map status.
		$wpi_status = isset( $this->invoice_status_map[ $invoice_status ] )
			? $this->invoice_status_map[ $invoice_status ]
			: 'wpi-pending';

		// Try to find the matching GetPaid item from the package mapping.
		$gd_package_id = ! empty( $packages_mapping[ $package_id ] ) ? (int) $packages_mapping[ $package_id ] : 0;
		$wpinv_item    = null;

		if ( $gd_package_id && function_exists( 'wpinv_get_item_by' ) ) {
			$wpinv_item = wpinv_get_item_by( 'custom_id', $gd_package_id, 'package' );
		}

		if ( ! $wpinv_item || ! $wpinv_item->exists() ) {
			/* translators: %d: invoice ID */
			$this->log( sprintf( __( 'Skipped invoice #%d: no matching package item found.', 'geodir-converter' ), $invoice_id ), 'warning' );
			return self::IMPORT_STATUS_SKIPPED;
		}

		// Get user info.
		$user      = get_userdata( $user_id );
		$email     = $user ? $user->user_email : '';
		$firstname = $user ? $user->first_name : '';
		$lastname  = $user ? $user->last_name : '';

		$wpi_invoice = new WPInv_Invoice( $is_update ? $existing_id : 0 );
		$wpi_invoice->set_props(
			array(
				'post_type'      => self::GD_POST_TYPE_INVOICE,
				'post_title'     => $invoice->post_title ? $invoice->post_title : 'WPR-' . $invoice_id,
				'post_name'      => 'inv-wpr-' . $invoice_id,
				'status'         => $wpi_status,
				'created_via'    => self::CREATED_VIA,
				'date_created'   => $invoice->post_date ? $invoice->post_date : current_time( 'mysql' ),
				'gateway'        => ! empty( $payment_type ) ? sanitize_key( $payment_type ) : 'manual',
				'transaction_id' => $transaction_id,
				'user_id'        => $user_id,
				'email'          => $email,
				'first_name'     => $firstname,
				'last_name'      => $lastname,
				'currency'       => $currency,
			)
		);

		$saved_id = $wpi_invoice->save();

		if ( is_wp_error( $saved_id ) ) {
			return self::IMPORT_STATUS_FAILED;
		}

		$wpi_invoice->add_item(
			array(
				'cart_discounts' => array(),
				'item_id'        => $wpinv_item->get_id(),
				'quantity'       => 1,
			)
		);

		$wpi_invoice->recalculate_total();
		$wpi_invoice->save();

		if ( ! $is_update ) {
			update_post_meta( $saved_id, '_wp_residence_invoice_id', $invoice_id );
		}

		return $is_update ? self::IMPORT_STATUS_UPDATED : self::IMPORT_STATUS_SUCCESS;
	}
}
