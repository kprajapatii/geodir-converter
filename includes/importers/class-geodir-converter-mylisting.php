<?php
/**
 * MyListing Converter Class.
 *
 * @since     2.1.6
 * @package   GeoDir_Converter
 */

namespace GeoDir_Converter\Importers;

use WP_Error;
use WP_Query;
use GeoDir_Media;
use GeoDir_Comments;
use GeoDir_Pricing_Package;
use GeoDir_Converter\GeoDir_Converter_Utils;
use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Main converter class for importing from MyListing theme.
 *
 * @since 2.1.6
 */
class GeoDir_Converter_MyListing extends GeoDir_Converter_Importer {
	/**
	 * Post type identifier for listings.
	 *
	 * @var string
	 */
	const POST_TYPE_LISTING = 'job_listing';

	/**
	 * Post type identifier for listing types.
	 *
	 * @var string
	 */
	const POST_TYPE_LISTING_TYPE = 'case27_listing_type';

	/**
	 * Taxonomy identifier for listing categories.
	 *
	 * @var string
	 */
	const TAX_LISTING_CATEGORY = 'job_listing_category';

	/**
	 * Taxonomy identifier for regions.
	 *
	 * @var string
	 */
	const TAX_REGION = 'region';

	/**
	 * Taxonomy identifier for tags.
	 *
	 * @var string
	 */
	const TAX_LISTING_TAGS = 'case27_job_listing_tags';

	/**
	 * Import action for reviews.
	 */
	const ACTION_IMPORT_REVIEWS = 'import_reviews';

	/**
	 * MyListing meta keys.
	 */
	const META_LISTING_TYPE = '_case27_listing_type';
	const META_FEATURED     = '_featured';
	const META_CLAIMED      = '_claimed';
	const META_JOB_EMAIL    = '_job_email';
	const META_JOB_PHONE    = '_job_phone';
	const META_JOB_WEBSITE  = '_job_website';
	const META_JOB_VIDEO    = '_job_video_url';
	const META_JOB_LOGO     = '_job_logo';
	const META_JOB_COVER    = '_job_cover';
	const META_JOB_GALLERY  = '_job_gallery';
	const META_JOB_EXPIRES  = '_job_expires';
	const META_LINKS        = '_links';
	const META_RATING       = '_case27_average_rating';
	const META_PACKAGE_ID   = '_user_package_id';

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
	protected $importer_id = 'mylisting';

	/**
	 * The import listing status ID.
	 *
	 * @var array
	 */
	protected $post_statuses = array( 'publish', 'pending', 'draft', 'private', 'expired', 'preview', 'unpublish' );

	/**
	 * Batch size for processing items.
	 *
	 * @var int
	 */
	protected $batch_size = 10;

	/**
	 * Cached table existence checks.
	 *
	 * @var array
	 */
	private $table_exists_cache = array();

	/**
	 * Cached listing type field definitions indexed by slug.
	 *
	 * @var array
	 */
	private $listing_type_fields_cache = array();

	/**
	 * Initialize hooks.
	 *
	 * @since 2.1.6
	 */
	protected function init() {
		add_action( 'init', array( $this, 'maybe_register_post_types' ), 0 );
	}

	/**
	 * Register MyListing post types and taxonomies if not already registered.
	 *
	 * This allows the importer to work even when the MyListing theme
	 * is not active, as long as the data exists in the database.
	 *
	 * @since 2.1.6
	 *
	 * @return void
	 */
	public function maybe_register_post_types() {
		if ( ! post_type_exists( self::POST_TYPE_LISTING ) ) {
			register_post_type( self::POST_TYPE_LISTING, array(
				'label'  => 'Listings',
				'public' => false,
			) );
		}

		if ( ! post_type_exists( self::POST_TYPE_LISTING_TYPE ) ) {
			register_post_type( self::POST_TYPE_LISTING_TYPE, array(
				'label'  => 'Listing Types',
				'public' => false,
			) );
		}

		if ( ! taxonomy_exists( self::TAX_LISTING_CATEGORY ) ) {
			register_taxonomy( self::TAX_LISTING_CATEGORY, self::POST_TYPE_LISTING, array(
				'label'        => 'Listing Categories',
				'public'       => false,
				'hierarchical' => true,
			) );
		}

		if ( ! taxonomy_exists( self::TAX_LISTING_TAGS ) ) {
			register_taxonomy( self::TAX_LISTING_TAGS, self::POST_TYPE_LISTING, array(
				'label'  => 'Listing Tags',
				'public' => false,
			) );
		}

		if ( ! taxonomy_exists( self::TAX_REGION ) ) {
			register_taxonomy( self::TAX_REGION, self::POST_TYPE_LISTING, array(
				'label'        => 'Regions',
				'public'       => false,
				'hierarchical' => true,
			) );
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
			'job_gallery',
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
		return __( 'MyListing', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @since 2.1.6
	 *
	 * @return string Importer description.
	 */
	public function get_description() {
		return __( 'Import listings, categories, and reviews from your MyListing theme installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @since 2.1.6
	 *
	 * @return string Icon URL.
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/mylisting.png';
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
			<h6 class="fs-base"><?php esc_html_e( 'MyListing Importer Settings', 'geodir-converter' ); ?></h6>

			<?php
			if ( ! $this->is_mylisting_active() ) {
				aui()->alert(
					array(
						'type'    => 'warning',
						'heading' => esc_html__( 'MyListing theme not detected.', 'geodir-converter' ),
						'content' => esc_html__( 'Please make sure MyListing theme is installed and was previously active. The importer will still work with existing data in the database.', 'geodir-converter' ),
						'class'   => 'mb-3',
					),
					true
				);
			}

			if ( ! class_exists( 'GeoDir_Pricing_Package' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'GeoDirectory Pricing Manager', 'geodir-converter' ),
					'packages',
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
	 * Check if MyListing theme is active or was previously active.
	 *
	 * @since 2.1.6
	 *
	 * @return bool True if MyListing is active or data exists.
	 */
	private function is_mylisting_active() {
		// Check if the theme is currently active.
		if ( function_exists( 'mylisting' ) || class_exists( '\MyListing\App' ) ) {
			return true;
		}

		// Check if data exists in the database from a previously active installation.
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1",
				self::POST_TYPE_LISTING
			)
		);

		return (int) $count > 0;
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
			self::ACTION_IMPORT_PACKAGES,
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
	 *
	 * @since 2.1.6
	 *
	 * @return void
	 */
	public function set_import_total() {
		global $wpdb;

		$total_items = 0;

		// Count categories.
		$categories   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_LISTING_CATEGORY ) );
		$total_items += $categories;

		// Count tags.
		$tags         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_LISTING_TAGS ) );
		$total_items += $tags;

		// Count regions.
		$regions      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_REGION ) );
		$total_items += $regions;

		// Count custom fields.
		$custom_fields = $this->get_custom_fields();
		$total_items  += (int) count( $custom_fields );

		// Count packages.
		if ( class_exists( 'GeoDir_Pricing_Package' ) ) {
			$packages     = (int) $this->count_packages();
			$total_items += $packages;
		}

		// Count listings.
		$total_items += (int) $this->count_listings();

		// Count reviews.
		$reviews      = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} c
				INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
				WHERE p.post_type = %s AND c.comment_approved = '1'",
				self::POST_TYPE_LISTING
			)
		);
		$total_items += $reviews;

		$this->increase_imports_total( $total_items );
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

		$status_placeholders = implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) );
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$status_placeholders})",
				array_merge( array( self::POST_TYPE_LISTING ), $this->post_statuses )
			)
		);

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Count the total number of WooCommerce packages to import.
	 *
	 * @since 2.1.6
	 *
	 * @return int Total number of packages.
	 */
	private function count_packages() {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				AND tt.taxonomy = 'product_type'
				AND t.slug = %s",
				'job_package'
			)
		);

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Import categories from MyListing to GeoDirectory.
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

		if ( 0 === intval( wp_count_terms( array( 'taxonomy' => self::TAX_LISTING_CATEGORY, 'hide_empty' => false ) ) ) ) {
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
	 * Import tags and regions from MyListing to GeoDirectory.
	 *
	 * @since 2.1.6
	 *
	 * @param array $task Import task.
	 * @return array Result of the import operation.
	 */
	public function task_import_tags( $task ) {
		global $wpdb;
		$this->log( __( 'Tags & Regions: Import started.', 'geodir-converter' ) );

		$post_type       = $this->get_import_post_type();
		$total_imported  = 0;
		$total_failed    = 0;

		// Import tags.
		$tags = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, tt.*
				FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				ORDER BY t.name ASC",
				self::TAX_LISTING_TAGS
			)
		);

		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			if ( $this->is_test_mode() ) {
				$total_imported += count( $tags );
			} else {
				$result          = $this->import_taxonomy_terms( $tags, $post_type . '_tags', 'ct_cat_top_desc' );
				$total_imported += (int) $result['imported'];
				$total_failed   += (int) $result['failed'];
			}
		}

		// Import regions as tags too.
		$regions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, tt.*
				FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				ORDER BY tt.parent ASC, t.name ASC",
				self::TAX_REGION
			)
		);

		if ( ! empty( $regions ) && ! is_wp_error( $regions ) ) {
			if ( $this->is_test_mode() ) {
				$total_imported += count( $regions );
			} else {
				$result          = $this->import_taxonomy_terms( $regions, $post_type . '_tags', 'ct_cat_top_desc' );
				$total_imported += (int) $result['imported'];
				$total_failed   += (int) $result['failed'];
			}
		}

		$this->increase_succeed_imports( $total_imported );
		$this->increase_failed_imports( $total_failed );

		$this->log(
			sprintf(
				/* translators: %1$d: number imported, %2$d: number failed */
				__( 'Tags & Regions: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$total_imported,
				$total_failed
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Get custom fields for MyListing listings.
	 *
	 * @since 2.1.6
	 *
	 * @return array The custom fields.
	 */
	private function get_custom_fields() {
		$fields = array(
			array(
				'type'           => 'text',
				'data_type'      => 'INT',
				'field_key'      => $this->importer_id . '_id',
				'label'          => __( 'MyListing ID', 'geodir-converter' ),
				'description'    => __( 'Original MyListing Listing ID.', 'geodir-converter' ),
				'placeholder'    => __( 'MyListing ID', 'geodir-converter' ),
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
				'type'        => 'checkbox',
				'field_key'   => 'featured',
				'label'       => __( 'Is Featured?', 'geodir-converter' ),
				'description' => __( 'Mark listing as featured.', 'geodir-converter' ),
				'icon'        => 'fas fa-star',
				'required'    => 0,
			),
			array(
				'type'        => 'checkbox',
				'field_key'   => 'claimed',
				'label'       => __( 'Is Claimed?', 'geodir-converter' ),
				'description' => __( 'Mark listing as claimed by owner.', 'geodir-converter' ),
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
			array(
				'type'        => 'business_hours',
				'field_key'   => 'business_hours',
				'label'       => __( 'Business Hours', 'geodir-converter' ),
				'description' => __( 'Business operating hours.', 'geodir-converter' ),
				'placeholder' => __( 'Business Hours', 'geodir-converter' ),
				'icon'        => 'far fa-clock',
				'required'    => 0,
			),
		);

		// Add individual social media URL fields.
		$social_fields = $this->get_social_media_fields();
		if ( ! empty( $social_fields ) ) {
			$fields = array_merge( $fields, $social_fields );
		}

		// Discover additional fields from listing type definitions.
		$additional_fields = $this->discover_listing_type_fields();
		if ( ! empty( $additional_fields ) ) {
			$fields = array_merge( $fields, $additional_fields );
		}

		return $fields;
	}

	/**
	 * Get individual social media URL fields for GeoDirectory.
	 *
	 * Instead of a single textarea, creates individual URL fields for each
	 * social platform that GeoDirectory supports natively.
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
	 * Discover custom fields from MyListing listing type definitions.
	 *
	 * @since 2.1.6
	 *
	 * @return array Additional custom fields.
	 */
	private function discover_listing_type_fields() {
		global $wpdb;

		$fields      = array();
		$field_keys  = array();
		$skip_fields = array(
			'job_title', 'job_description', 'job_category', 'job_tags', 'job_region',
			'job_location', 'job_email', 'job_phone', 'job_website', 'job_video_url',
			'job_gallery', 'job_logo', 'job_cover_image', 'job_links',
			'featured_image', 'listing_type', 'related_listing',
		);

		// Get all listing types.
		$listing_types = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				self::POST_TYPE_LISTING_TYPE
			)
		);

		if ( empty( $listing_types ) ) {
			return $fields;
		}

		foreach ( $listing_types as $type ) {
			$type_fields = get_post_meta( $type->ID, 'case27_listing_type_fields', true );

			if ( empty( $type_fields ) || ! is_array( $type_fields ) ) {
				continue;
			}

			foreach ( $type_fields as $field_config ) {
				$slug = isset( $field_config['slug'] ) ? $field_config['slug'] : '';

				if ( empty( $slug ) || in_array( $slug, $skip_fields, true ) || isset( $field_keys[ $slug ] ) ) {
					continue;
				}

				$field_type = isset( $field_config['type'] ) ? $field_config['type'] : 'text';
				$gd_type    = $this->map_field_type( $field_type );

				if ( ! $gd_type ) {
					continue;
				}

				$label     = isset( $field_config['label'] ) ? $field_config['label'] : ucwords( str_replace( array( '_', '-' ), ' ', $slug ) );
				$field_key = str_replace( '-', '_', sanitize_title( $slug ) );

				$field = array(
					'type'        => $gd_type,
					'field_key'   => $field_key,
					'label'       => $label,
					/* translators: %s: field slug */
				'description' => sprintf( __( 'Imported from MyListing field: %s', 'geodir-converter' ), $slug ),
					'icon'        => $this->get_icon_for_field( $field_key ),
					'required'    => ! empty( $field_config['required'] ) ? 1 : 0,
				);

				// Handle select/checkbox options.
				if ( in_array( $gd_type, array( 'select', 'multiselect' ), true ) && ! empty( $field_config['options'] ) ) {
					$options = array();
					foreach ( (array) $field_config['options'] as $opt ) {
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

				$fields[]              = $field;
				$field_keys[ $slug ] = true;
			}
		}

		return $fields;
	}

	/**
	 * Map MyListing field type to GeoDirectory field type.
	 *
	 * @since 2.1.6
	 *
	 * @param string $ml_type MyListing field type.
	 * @return string|false GeoDirectory field type or false if not supported.
	 */
	private function map_field_type( $ml_type ) {
		$type_map = array(
			'text'           => 'text',
			'textarea'       => 'textarea',
			'wp-editor'      => 'textarea',
			'email'          => 'email',
			'url'            => 'url',
			'number'         => 'text',
			'date'           => 'datepicker',
			'select'         => 'select',
			'multiselect'    => 'multiselect',
			'radio'          => 'radio',
			'checkbox'       => 'checkbox',
			'checkboxes'     => 'multiselect',
			'file'           => 'file',
			'links'          => false,
			'work-hours'     => false,
			'texteditor'     => 'textarea',
			'password'       => 'text',
			'switcher'       => 'checkbox',
		);

		return isset( $type_map[ $ml_type ] ) ? $type_map[ $ml_type ] : false;
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
			'text'        => 'VARCHAR',
			'email'       => 'VARCHAR',
			'url'         => 'TEXT',
			'phone'       => 'VARCHAR',
			'textarea'    => 'TEXT',
			'checkbox'    => 'TINYINT',
			'datepicker'  => 'DATE',
			'radio'       => 'VARCHAR',
			'select'      => 'VARCHAR',
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
	 * Import custom fields from MyListing to GeoDirectory.
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
	 * Import packages from WooCommerce job_package products to GeoDirectory.
	 *
	 * @since 2.1.6
	 *
	 * @param array $task Task details.
	 * @return array Result of the import operation.
	 */
	public function task_import_packages( array $task ) {
		global $wpdb;

		$this->log( __( 'Packages: Import started.', 'geodir-converter' ) );

		if ( ! class_exists( 'GeoDir_Pricing_Package' ) ) {
			$this->log( __( 'Packages: GeoDirectory Pricing Manager not active. Skipping.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$post_type = $this->get_import_post_type();

		// Get WooCommerce job_package products.
		$packages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_content, p.post_excerpt
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				AND tt.taxonomy = 'product_type'
				AND t.slug = %s",
				'job_package'
			)
		);

		if ( empty( $packages ) ) {
			$this->log( __( 'Packages: No packages found to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$imported = $failed = 0;

		foreach ( $packages as $package ) {
			if ( $this->is_test_mode() ) {
				++$imported;
				continue;
			}

			$price    = get_post_meta( $package->ID, '_price', true );
			$duration = get_post_meta( $package->ID, '_job_listing_duration', true );
			$limit    = get_post_meta( $package->ID, '_job_listing_limit', true );
			$featured = get_post_meta( $package->ID, '_job_listing_featured', true );

			$package_data = array(
				'name'        => $package->post_title,
				'title'       => $package->post_title,
				'description' => $package->post_content ? $package->post_content : $package->post_excerpt,
				'amount'      => $price ? (float) $price : 0,
				'time_period' => $duration ? (int) $duration : 0,
				'time_unit'   => 'D',
				'post_type'   => $post_type,
				'status'      => 1,
				'fa_icon'     => 'fas fa-briefcase',
				'display_order' => 0,
			);

			$result = GeoDir_Pricing_Package::save( $package_data );

			if ( $result && ! is_wp_error( $result ) ) {
				++$imported;
				// Store mapping for later use.
				update_post_meta( $package->ID, '_geodir_converter_package_id', $result );
			} else {
				++$failed;
				/* translators: %s: package title */
			$this->log( sprintf( __( 'Failed to import package: %s', 'geodir-converter' ), $package->post_title ), 'error' );
			}
		}

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );

		$this->log(
			sprintf(
				/* translators: %1$d: number imported, %2$d: number failed */
				__( 'Packages: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$imported,
				$failed
			),
			'success'
		);

		return $this->next_task( $task );
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

		wp_suspend_cache_addition( true );

		$status_placeholders = implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) );
		$listings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_status
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_status IN ({$status_placeholders})
				LIMIT %d OFFSET %d",
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
	 * Convert a single MyListing listing to GeoDirectory format.
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
		$categories = $this->get_listings_terms( $post->ID, self::TAX_LISTING_CATEGORY );

		// Get tags (including regions imported as tags).
		$tags    = $this->get_listings_terms( $post->ID, self::TAX_LISTING_TAGS );
		$regions = $this->get_listings_terms( $post->ID, self::TAX_REGION );
		if ( ! empty( $regions ) ) {
			$tags = array_unique( array_merge( $tags, $regions ) );
		}

		// Location data from the custom locations table.
		$location = $this->get_listing_location( $post->ID, $default_location );

		// Map post status.
		$post_status = $post->post_status;
		if ( in_array( $post_status, array( 'expired', 'preview', 'unpublish' ), true ) ) {
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

			// MyListing standard fields.
			$this->importer_id . '_id' => $post->ID,
			'featured'                 => ! empty( $post_meta[ self::META_FEATURED ] ) ? 1 : 0,
			'claimed'                  => ! empty( $post_meta[ self::META_CLAIMED ] ) ? 1 : 0,
			'phone'                    => isset( $post_meta[ self::META_JOB_PHONE ] ) ? $post_meta[ self::META_JOB_PHONE ] : '',
			'email'                    => isset( $post_meta[ self::META_JOB_EMAIL ] ) ? $post_meta[ self::META_JOB_EMAIL ] : '',
			'website'                  => isset( $post_meta[ self::META_JOB_WEBSITE ] ) ? $post_meta[ self::META_JOB_WEBSITE ] : '',
			'video_url'                => isset( $post_meta[ self::META_JOB_VIDEO ] ) ? $post_meta[ self::META_JOB_VIDEO ] : '',
		);

		// Process expiration.
		if ( ! empty( $post_meta[ self::META_JOB_EXPIRES ] ) ) {
			$listing_data['expire_date'] = date( 'Y-m-d', strtotime( $post_meta[ self::META_JOB_EXPIRES ] ) );
		}

		// Process social profiles — map to individual GD social URL fields.
		$social_links = $this->parse_social_links( $post_meta );
		foreach ( $social_links as $platform => $url ) {
			$listing_data[ $platform ] = $url;
		}

		// Process business hours.
		$business_hours = $this->format_business_hours( $post->ID );
		if ( ! empty( $business_hours ) ) {
			$listing_data['business_hours'] = $business_hours;
		}

		// Process dynamic custom fields from listing type definitions.
		$custom_fields = $this->process_custom_attributes( $post, $post_meta );
		if ( ! empty( $custom_fields ) ) {
			$core_fields = array(
				'post_author', 'post_title', 'post_content', 'post_status', 'post_type',
				'post_name', 'post_date', 'default_category', 'latitude', 'longitude',
				'phone', 'email', 'website', 'video_url', 'featured', 'claimed',
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
	 * Get listing location from the MyListing locations table.
	 *
	 * @since 2.1.6
	 *
	 * @param int   $listing_id       The listing ID.
	 * @param array $default_location Default location values.
	 * @return array Location data.
	 */
	private function get_listing_location( $listing_id, $default_location = array() ) {
		global $wpdb;

		$location = $default_location;

		// Try to get location from MyListing custom table.
		$table_name = $wpdb->prefix . 'mylisting_locations';

		if ( ! isset( $this->table_exists_cache[ $table_name ] ) ) {
			$this->table_exists_cache[ $table_name ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name );
		}

		if ( $this->table_exists_cache[ $table_name ] ) {
			$loc = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT address, lat, lng FROM {$table_name} WHERE listing_id = %d LIMIT 1",
					$listing_id
				)
			);

			if ( $loc ) {
				$location['latitude']  = $loc->lat;
				$location['longitude'] = $loc->lng;

				if ( ! empty( $loc->address ) ) {
					$location['address'] = $loc->address;
				}

				// Always reverse geocode to normalize location data format.
				if ( ! empty( $loc->lat ) && ! empty( $loc->lng ) ) {
					$location_lookup = GeoDir_Converter_Utils::get_location_from_coords( $loc->lat, $loc->lng );

					if ( ! is_wp_error( $location_lookup ) ) {
						$location = array_merge( $location, $location_lookup );

						// Preserve original address from locations table.
						if ( ! empty( $loc->address ) ) {
							$location['address'] = $loc->address;
						}
					} else {
						// Fall back to geo meta if geocoding fails.
						$post_meta   = $this->get_post_meta( $listing_id );
						$geo_city    = isset( $post_meta['geolocation_city'] ) ? $post_meta['geolocation_city'] : '';
						$geo_country = isset( $post_meta['geolocation_country_long'] ) ? $post_meta['geolocation_country_long'] : '';
						$geo_state   = isset( $post_meta['geolocation_state_long'] ) ? $post_meta['geolocation_state_long'] : '';
						$geo_zip     = isset( $post_meta['geolocation_postcode'] ) ? $post_meta['geolocation_postcode'] : '';

						if ( ! empty( $geo_city ) )    $location['city']    = $geo_city;
						if ( ! empty( $geo_state ) )   $location['region']  = $geo_state;
						if ( ! empty( $geo_country ) ) $location['country'] = $geo_country;
						if ( ! empty( $geo_zip ) )     $location['zip']     = $geo_zip;
					}
				}

				return $location;
			}
		}

		// Fallback: check WP Job Manager geolocation post meta.
		$post_meta = $this->get_post_meta( $listing_id );
		$latitude  = isset( $post_meta['geolocation_lat'] ) ? $post_meta['geolocation_lat'] : '';
		$longitude = isset( $post_meta['geolocation_long'] ) ? $post_meta['geolocation_long'] : '';

		if ( ! empty( $latitude ) && ! empty( $longitude ) ) {
			$location['latitude']  = $latitude;
			$location['longitude'] = $longitude;

			// Always reverse geocode to normalize location data format.
			$location_lookup = GeoDir_Converter_Utils::get_location_from_coords( $latitude, $longitude );

			if ( ! is_wp_error( $location_lookup ) ) {
				$location = array_merge( $location, $location_lookup );
			} else {
				// Fall back to geo meta if geocoding fails.
				$geo_city    = isset( $post_meta['geolocation_city'] ) ? $post_meta['geolocation_city'] : '';
				$geo_state   = isset( $post_meta['geolocation_state_long'] ) ? $post_meta['geolocation_state_long'] : '';
				$geo_country = isset( $post_meta['geolocation_country_long'] ) ? $post_meta['geolocation_country_long'] : '';
				$geo_zip     = isset( $post_meta['geolocation_postcode'] ) ? $post_meta['geolocation_postcode'] : '';

				if ( ! empty( $geo_city ) )    $location['city']    = $geo_city;
				if ( ! empty( $geo_state ) )   $location['region']  = $geo_state;
				if ( ! empty( $geo_country ) ) $location['country'] = $geo_country;
				if ( ! empty( $geo_zip ) )     $location['zip']     = $geo_zip;
			}
		}

		return $location;
	}

	/**
	 * Parse social links from MyListing _links meta into individual platform URLs.
	 *
	 * MyListing stores social links as a serialized array of {network, url} pairs
	 * in the _links post meta. This method maps them to individual GD social fields.
	 *
	 * @since 2.1.6
	 *
	 * @param array $post_meta Post meta data.
	 * @return array Associative array of platform_key => URL pairs.
	 */
	private function parse_social_links( $post_meta ) {
		if ( empty( $post_meta[ self::META_LINKS ] ) ) {
			return array();
		}

		$links = maybe_unserialize( $post_meta[ self::META_LINKS ] );

		if ( ! is_array( $links ) ) {
			return array();
		}

		// Map MyListing network names to GD platform keys.
		$network_to_platform = array(
			'facebook'  => 'facebook',
			'twitter'   => 'twitter',
			'x'         => 'twitter',
			'instagram' => 'instagram',
			'youtube'   => 'youtube',
			'linkedin'  => 'linkedin',
			'pinterest' => 'pinterest',
			'whatsapp'  => 'whatsapp',
		);

		// Fallback: detect platforms from URL patterns.
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

			// First try to detect from network name.
			if ( ! empty( $link['network'] ) ) {
				$network_key = strtolower( trim( $link['network'] ) );
				foreach ( $network_to_platform as $keyword => $platform_key ) {
					if ( false !== strpos( $network_key, $keyword ) ) {
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
	 * Format business hours from MyListing workhours table into GeoDirectory business_hours JSON format.
	 *
	 * GeoDirectory expects: ["Mo 09:00-17:00","Tu 09:00-17:00"],["UTC":"+5.5"]
	 *
	 * MyListing stores work hours as minute offsets from Monday midnight (0) in the
	 * {prefix}mylisting_workhours table. Each day = 1440 minutes.
	 *
	 * @since 2.1.6
	 *
	 * @param int $listing_id The listing ID.
	 * @return string GeoDirectory business_hours JSON string, or empty string.
	 */
	private function format_business_hours( $listing_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'mylisting_workhours';

		if ( ! isset( $this->table_exists_cache[ $table_name ] ) ) {
			$this->table_exists_cache[ $table_name ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name );
		}

		if ( ! $this->table_exists_cache[ $table_name ] ) {
			return '';
		}

		$hours = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT start, end, timezone FROM {$table_name} WHERE listing_id = %d ORDER BY start ASC",
				$listing_id
			)
		);

		if ( empty( $hours ) ) {
			return '';
		}

		// GeoDirectory day abbreviations indexed by day number (0 = Monday).
		$day_abbr   = array( 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su' );
		$day_length = 1440; // Minutes per day.
		$day_slots  = array_fill( 0, 7, array() );
		$timezone   = null;

		foreach ( $hours as $hour ) {
			$start_min = (int) $hour->start;
			$end_min   = (int) $hour->end;

			// Capture timezone from first row if available.
			if ( null === $timezone && ! empty( $hour->timezone ) ) {
				$timezone = $hour->timezone;
			}

			$start_day = min( 6, (int) floor( $start_min / $day_length ) );

			// Convert to within-day minutes and format as HH:MM.
			$open  = sprintf( '%02d:%02d', floor( ( $start_min % $day_length ) / 60 ), ( $start_min % $day_length ) % 60 );
			$close = sprintf( '%02d:%02d', floor( ( $end_min % $day_length ) / 60 ), ( $end_min % $day_length ) % 60 );

			$day_slots[ $start_day ][] = $open . '-' . $close;
		}

		$days_parts = array();
		foreach ( $day_slots as $day_index => $slots ) {
			if ( ! empty( $slots ) ) {
				$days_parts[] = $day_abbr[ $day_index ] . ' ' . implode( ',', $slots );
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
	 * Process custom attributes from MyListing listing type field definitions.
	 *
	 * @since 2.1.6
	 *
	 * @param object $post      Post object.
	 * @param array  $post_meta Post meta data.
	 * @return array Processed custom fields.
	 */
	private function process_custom_attributes( $post, $post_meta ) {
		$fields = array();

		// System fields handled elsewhere.
		$skip_meta_keys = array(
			self::META_LISTING_TYPE,
			self::META_FEATURED,
			self::META_CLAIMED,
			self::META_JOB_EMAIL,
			self::META_JOB_PHONE,
			self::META_JOB_WEBSITE,
			self::META_JOB_VIDEO,
			self::META_JOB_LOGO,
			self::META_JOB_COVER,
			self::META_JOB_GALLERY,
			self::META_JOB_EXPIRES,
			self::META_LINKS,
			self::META_RATING,
			self::META_PACKAGE_ID,
			'_job_location',
			'_thumbnail_id',
			'_edit_lock',
			'_edit_last',
			'geolocation_lat',
			'geolocation_long',
			'geolocation_city',
			'geolocation_state_short',
			'geolocation_state_long',
			'geolocation_country_short',
			'geolocation_country_long',
			'geolocation_street',
			'geolocation_postcode',
		);

		// Get the listing type and its field definitions.
		$listing_type_slug = isset( $post_meta[ self::META_LISTING_TYPE ] ) ? $post_meta[ self::META_LISTING_TYPE ] : '';

		if ( ! empty( $listing_type_slug ) ) {
			// Cache listing type field definitions to avoid repeated DB lookups.
			if ( ! isset( $this->listing_type_fields_cache[ $listing_type_slug ] ) ) {
				$listing_type = get_page_by_path( $listing_type_slug, OBJECT, self::POST_TYPE_LISTING_TYPE );
				$this->listing_type_fields_cache[ $listing_type_slug ] = $listing_type
					? get_post_meta( $listing_type->ID, 'case27_listing_type_fields', true )
					: false;
			}

			$type_fields = $this->listing_type_fields_cache[ $listing_type_slug ];

			if ( ! empty( $type_fields ) && is_array( $type_fields ) ) {
				foreach ( $type_fields as $field_config ) {
					$slug     = isset( $field_config['slug'] ) ? $field_config['slug'] : '';
					$meta_key = '_' . $slug;

					if ( empty( $slug ) || in_array( $meta_key, $skip_meta_keys, true ) ) {
						continue;
					}

					if ( isset( $post_meta[ $meta_key ] ) && ( ! empty( $post_meta[ $meta_key ] ) || $post_meta[ $meta_key ] === '0' ) ) {
						$value     = $post_meta[ $meta_key ];
						$field_key = str_replace( '-', '_', sanitize_title( $slug ) );

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
			}
		}

		return $fields;
	}

	/**
	 * Get post images from MyListing gallery.
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

		// Get gallery from _job_gallery meta.
		$gallery = isset( $post_meta[ self::META_JOB_GALLERY ] ) ? $post_meta[ self::META_JOB_GALLERY ] : '';

		if ( ! empty( $gallery ) ) {
			if ( is_string( $gallery ) && is_serialized( $gallery ) ) {
				$gallery = maybe_unserialize( $gallery );
			}

			if ( is_array( $gallery ) ) {
				foreach ( $gallery as $image ) {
					// MyListing stores URLs/GUIDs, not attachment IDs.
					if ( is_numeric( $image ) ) {
						$attachment_ids[] = (int) $image;
					} elseif ( is_string( $image ) && ! empty( $image ) ) {
						// Resolve URL to attachment ID.
						$att_id = $this->get_attachment_id_from_url( $image );
						if ( $att_id ) {
							$attachment_ids[] = $att_id;
						}
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
	 * Resolve a URL or GUID to an attachment ID.
	 *
	 * @since 2.1.6
	 *
	 * @param string $url The image URL or GUID.
	 * @return int|false Attachment ID or false.
	 */
	private function get_attachment_id_from_url( $url ) {
		global $wpdb;

		// Try WordPress function first.
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id ) {
			return $attachment_id;
		}

		// Try matching by GUID in wp_posts.
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment' LIMIT 1",
				$url
			)
		);

		return $attachment_id ? (int) $attachment_id : false;
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
		// Try post thumbnail first.
		$image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
		if ( isset( $image[0] ) ) {
			return esc_url( $image[0] );
		}

		// Try _job_logo meta.
		$logo = get_post_meta( $post_id, self::META_JOB_LOGO, true );
		if ( ! empty( $logo ) ) {
			if ( is_numeric( $logo ) ) {
				$image = wp_get_attachment_image_src( (int) $logo, 'full' );
				return isset( $image[0] ) ? esc_url( $image[0] ) : '';
			}
			return esc_url( $logo );
		}

		return '';
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
	 * Import comments/reviews from MyListing listing to GeoDirectory listing.
	 *
	 * @since 2.1.6
	 *
	 * @param int $ml_listing_id MyListing listing ID.
	 * @param int $gd_post_id    GeoDirectory post ID.
	 * @return void
	 */
	private function import_comments( $ml_listing_id, $gd_post_id ) {
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
				$ml_listing_id
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

			// Handle MyListing rating (1-10 scale -> GD 1-5 scale).
			$ml_rating = get_comment_meta( $comment->comment_ID, '_case27_post_rating', true );

			if ( $ml_rating && class_exists( 'GeoDir_Comments' ) ) {
				// Convert 1-10 scale to 1-5 scale.
				$gd_rating = max( 1, min( 5, round( (float) $ml_rating / 2 ) ) );
				$_REQUEST['geodir_overallrating'] = (int) $gd_rating;
				GeoDir_Comments::save_rating( $comment->comment_ID );
				unset( $_REQUEST['geodir_overallrating'] );
			}
		}

		// Update comment count.
		wp_update_comment_count( $gd_post_id );
	}
}
