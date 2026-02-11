<?php
/**
 * Listing Pro Converter Class.
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
use GeoDir_Event_Manager;
use GeoDir_Converter\GeoDir_Converter_Utils;
use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Main converter class for importing from Listing Pro.
 *
 * @since 2.1.4
 */
class GeoDir_Converter_ListingPro extends GeoDir_Converter_Importer {
	/**
	 * Post type identifier for listings.
	 *
	 * @var string
	 */
	const POST_TYPE_LISTING = 'listing';

	/**
	 * Taxonomy identifier for listing categories.
	 *
	 * @var string
	 */
	const TAX_LISTING_CATEGORY = 'listing-category';

	/**
	 * Taxonomy identifier for listing tags.
	 *
	 * @var string
	 */
	const TAX_LISTING_TAG = 'list-tags';

	/**
	 * Taxonomy identifier for listing features.
	 *
	 * @var string
	 */
	const TAX_LISTING_FEATURES = 'features';

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
	 * Import action for events.
	 */
	const ACTION_PARSE_EVENTS = 'parse_events';

	/**
	 * Import action for events.
	 */
	const ACTION_IMPORT_EVENTS = 'import_events';

	/**
	 * Import action for parsing invoices.
	 */
	const ACTION_PARSE_INVOICES = 'parse_invoices';

	/**
	 * Import action for invoices.
	 */
	const ACTION_IMPORT_INVOICES = 'import_invoices';

	/**
	 * Import action for parsing ads/campaigns.
	 */
	const ACTION_PARSE_ADS = 'parse_ads';

	/**
	 * Import action for ads/campaigns.
	 */
	const ACTION_IMPORT_ADS = 'import_ads';

	/**
	 * Post type for reviews.
	 */
	const POST_TYPE_REVIEW = 'lp-reviews';

	/**
	 * Post type for custom form fields.
	 */
	const POST_TYPE_FORM_FIELDS = 'form-fields';

	/**
	 * Post type for price plans.
	 */
	const POST_TYPE_PRICE_PLAN = 'price_plan';

	/**
	 * Post type for events.
	 */
	const POST_TYPE_EVENTS = 'events';

	/**
	 * Post type for ads.
	 */
	const POST_TYPE_ADS = 'lp-ads';

	/**
	 * GeoDirectory event post type.
	 */
	const GD_POST_TYPE_EVENT = 'gd_event';

	/**
	 * GetPaid invoice post type.
	 */
	const GD_POST_TYPE_INVOICE = 'wpi_invoice';

	/**
	 * GetPaid Advertising ad post type.
	 */
	const GD_POST_TYPE_AD = 'adv_ad';

	/**
	 * GetPaid Advertising zone post type.
	 */
	const GD_POST_TYPE_AD_ZONE = 'adv_zone';

	/**
	 * Batch size for chunking import tasks.
	 */
	const BATCH_SIZE_IMPORT = 10;

	/**
	 * Source identifier for imported items.
	 */
	const CREATED_VIA = 'geodir-converter';

	/**
	 * ListingPro meta keys (patterns where needed).
	 */
	const META_GALLERY_IDS      = 'gallery_image_ids';
	const META_OPTIONS_PATTERN  = 'lp_%s_options';
	const META_FIELDS_PATTERN   = 'lp_%s_options_fields';
	const META_LISTING_RATE     = 'listing_rate';
	const META_LISTING_REVIEWED = 'listing_reviewed';
	const META_POST_VIEWS       = 'post_views_count';
	const META_CLAIMED          = 'claimed';
	const META_PLAN_ID          = 'plan_id';
	const META_ADS_LISTING      = 'ads_listing';
	const META_AD_TYPE          = 'ad_type';

	/**
	 * Form field meta keys.
	 */
	const FIELD_TYPE               = 'field-type';
	const FIELD_RADIO_OPTIONS      = 'radio-options';
	const FIELD_SELECT_OPTIONS     = 'select-options';
	const FIELD_MULTICHECK_OPTIONS = 'multicheck-options';
	const FIELD_CATEGORIES         = 'field-cat';
	const FIELD_EXCLUSIVE          = 'exclusive_field';

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
	protected $importer_id = 'listingpro';

	/**
	 * The import listing status ID.
	 *
	 * @var array
	 */
	protected $post_statuses = array( 'publish', 'pending', 'draft', 'private' );

	/**
	 * Price status mapping from ListingPro keys to GeoDirectory display values.
	 *
	 * @var array
	 */
	protected $price_status_map = array(
		'notsay'         => 'Not Say',
		'inexpensive'    => 'Cheap',
		'moderate'       => 'Moderate',
		'pricey'         => 'Expensive',
		'ultra_high_end' => 'Ultra High',
	);

	/**
	 * Invoice status mapping from ListingPro to GetPaid.
	 *
	 * @var array
	 */
	protected $invoice_status_map = array(
		'success'     => 'publish',
		'in progress' => 'wpi-pending',
		'pending'     => 'wpi-pending',
		'failed'      => 'wpi-failed',
	);

	/**
	 * Ad status mapping from ListingPro to GetPaid Advertising.
	 *
	 * @var array
	 */
	protected $ad_status_map = array(
		'success'     => 'publish',
		'pending'     => 'pending',
		'in progress' => 'pending',
		'failed'      => 'draft',
		'active'      => 'publish',
	);

	/**
	 * Initialize hooks.
	 *
	 * @since 2.1.4
	 */
	protected function init() {
	}

	/**
	 * Get ListingPro theme key.
	 *
	 * @since 2.1.4
	 * @return string Theme key.
	 */
	private function get_theme_key() {
		return defined( 'THEMENAME' ) ? strtolower( THEMENAME ) : 'listingpro';
	}

	/**
	 * Check if a field should be skipped during import.
	 *
	 * @param string $field_name The field name to check.
	 * @return bool True if the field should be skipped, false otherwise.
	 */
	protected function should_skip_field( $field_name ) {
		$skip_fields = array(
			'24_hours_open',
		);

		if ( in_array( $field_name, $skip_fields, true ) ) {
			return true;
		}

		return parent::should_skip_field( $field_name );
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
		return __( 'Listing Pro', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Import listings from your Listing Pro theme installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @return string
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/listingpro.jpeg';
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
			<h6 class="fs-base"><?php esc_html_e( 'Listing Pro Importer Settings', 'geodir-converter' ); ?></h6>
			
			<?php
			// Show plugin notices for optional features.
			if ( ! class_exists( 'GeoDir_Pricing_Package' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'GeoDirectory Pricing Manager', 'geodir-converter' ),
					'packages',
					esc_url( 'https://wpgeodirectory.com/downloads/pricing-manager/' )
				);
			}

			if ( ! class_exists( 'GeoDir_Event_Manager' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'GeoDirectory Events', 'geodir-converter' ),
					'events',
					esc_url( 'https://wpgeodirectory.com/downloads/events/' )
				);
			}

			if ( ! class_exists( 'WPInv_Plugin' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'GetPaid (Invoicing)', 'geodir-converter' ),
					'invoices',
					esc_url( 'https://wordpress.org/plugins/invoicing/' )
				);
			}

			if ( ! class_exists( 'Adv_Ad' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'GetPaid Advertising', 'geodir-converter' ),
					'ads',
					esc_url( 'https://wpgetpaid.com/downloads/advertising/' )
				);
			}

			$this->display_post_type_select();
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
			self::ACTION_IMPORT_TAGS,
			self::ACTION_IMPORT_FEATURES,
			self::ACTION_IMPORT_FIELDS,
			self::ACTION_IMPORT_PACKAGES,
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
	 */
	public function set_import_total() {
		global $wpdb;

		$total_items = 0;

		$categories   = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_LISTING_CATEGORY ) );
		$total_items += is_wp_error( $categories ) ? 0 : $categories;

		$tags         = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_LISTING_TAG ) );
		$total_items += is_wp_error( $tags ) ? 0 : $tags;

		$features     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::TAX_LISTING_FEATURES ) );
		$total_items += is_wp_error( $features ) ? 0 : $features;

		$custom_fields = $this->get_custom_fields();
		$total_items  += (int) count( $custom_fields );

		if ( class_exists( 'GeoDir_Pricing_Package' ) ) {
			$packages     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", self::POST_TYPE_PRICE_PLAN ) );
			$total_items += is_wp_error( $packages ) ? 0 : $packages;
		}

		$total_items += (int) $this->count_listings();

		$reviews      = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", self::POST_TYPE_REVIEW ) );
		$total_items += is_wp_error( $reviews ) ? 0 : $reviews;

		if ( class_exists( 'GeoDir_Event_Manager' ) ) {
			$events       = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} 
                    WHERE post_type = %s 
                    AND post_status IN (" . implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) ) . ')',
					array_merge( array( self::POST_TYPE_EVENTS ), $this->post_statuses )
				)
			);
			$total_items += is_wp_error( $events ) ? 0 : $events;
		}

		if ( class_exists( 'WPInv_Plugin' ) && $this->orders_table_exists() ) {
			$orders_table = $wpdb->prefix . 'listing_orders';
			$orders       = $wpdb->get_var( "SELECT COUNT(*) FROM `{$orders_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_items += is_wp_error( $orders ) ? 0 : $orders;
		}

		if ( class_exists( 'Adv_Ad' ) && $this->campaigns_table_exists() ) {
			$campaigns_table = $wpdb->prefix . 'listing_campaigns';
			$campaigns       = $wpdb->get_var( "SELECT COUNT(*) FROM `{$campaigns_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_items    += is_wp_error( $campaigns ) ? 0 : $campaigns;
		}

		$this->increase_imports_total( $total_items );
	}

	/**
	 * Get custom fields from ListingPro form-fields post type.
	 *
	 * @since 2.1.4
	 * @param string $post_type Post type to get fields for.
	 * @return array Array of custom fields.
	 */
	private function get_custom_fields( $post_type = '' ) {
		global $wpdb;

		$common_fields = array(
			array(
				'type'           => 'number',
				'field_key'      => $this->importer_id . '_id',
				'label'          => __( 'Listing Pro ID', 'geodir-converter' ),
				'description'    => __( 'Original Listing Pro Listing ID.', 'geodir-converter' ),
				'placeholder'    => __( 'Listing Pro ID', 'geodir-converter' ),
				'icon'           => 'far fa-id-card',
				'only_for_admin' => 1,
				'required'       => 0,
			),
			array(
				'type'        => 'email',
				'field_key'   => 'email',
				'label'       => __( 'Email', 'geodir-converter' ),
				'description' => __( 'The email of the listing.', 'geodir-converter' ),
				'placeholder' => __( 'Email', 'geodir-converter' ),
				'icon'        => 'far fa-envelope',
				'required'    => 0,
			),
			array(
				'type'        => 'phone',
				'field_key'   => 'phone',
				'label'       => __( 'Phone', 'geodir-converter' ),
				'description' => __( 'The phone number of the listing.', 'geodir-converter' ),
				'placeholder' => __( 'Phone', 'geodir-converter' ),
				'icon'        => 'fa-solid fa-phone',
				'required'    => 0,
			),
			array(
				'type'        => 'url',
				'field_key'   => 'website',
				'label'       => __( 'Website', 'geodir-converter' ),
				'description' => __( 'The website of the listing.', 'geodir-converter' ),
				'placeholder' => __( 'Website', 'geodir-converter' ),
				'icon'        => 'fa-solid fa-globe',
				'required'    => 0,
			),
		);

		if ( self::GD_POST_TYPE_EVENT === $post_type ) {
			$fields = array_merge(
				$common_fields,
				array(
					array(
						'type'        => 'text',
						'field_key'   => 'event_ticket_url',
						'label'       => __( 'Ticket URL', 'geodir-converter' ),
						'description' => __( 'Event ticket purchase URL.', 'geodir-converter' ),
						'placeholder' => __( 'Ticket URL', 'geodir-converter' ),
						'icon'        => 'fas fa-ticket-alt',
						'required'    => 0,
					),
				)
			);
		} else {
			$fields = array_merge(
				$common_fields,
				array(
					array(
						'type'        => 'url',
						'field_key'   => 'facebook',
						'label'       => __( 'Facebook', 'geodir-converter' ),
						'description' => __( 'The Facebook page of the listing.', 'geodir-converter' ),
						'placeholder' => __( 'Facebook', 'geodir-converter' ),
						'icon'        => 'fa-brands fa-facebook',
						'required'    => 0,
					),
					array(
						'type'        => 'url',
						'field_key'   => 'twitter',
						'label'       => __( 'Twitter', 'geodir-converter' ),
						'description' => __( 'The Twitter page of the listing.', 'geodir-converter' ),
						'placeholder' => __( 'Twitter', 'geodir-converter' ),
						'icon'        => 'fa-brands fa-twitter',
						'required'    => 0,
					),
					array(
						'type'        => 'url',
						'field_key'   => 'instagram',
						'label'       => __( 'Instagram', 'geodir-converter' ),
						'description' => __( 'The Instagram page of the listing.', 'geodir-converter' ),
						'placeholder' => __( 'Instagram', 'geodir-converter' ),
						'icon'        => 'fa-brands fa-instagram',
						'required'    => 0,
					),
					array(
						'type'        => 'url',
						'field_key'   => 'youtube',
						'label'       => __( 'YouTube', 'geodir-converter' ),
						'description' => __( 'The YouTube page of the listing.', 'geodir-converter' ),
						'placeholder' => __( 'YouTube', 'geodir-converter' ),
						'icon'        => 'fa-brands fa-youtube',
						'required'    => 0,
					),
					array(
						'type'        => 'url',
						'field_key'   => 'pinterest',
						'label'       => __( 'Pinterest', 'geodir-converter' ),
						'description' => __( 'The Pinterest page of the listing.', 'geodir-converter' ),
						'placeholder' => __( 'Pinterest', 'geodir-converter' ),
						'icon'        => 'fa-brands fa-pinterest',
						'required'    => 0,
					),
					array(
						'type'        => 'url',
						'field_key'   => 'linkedin',
						'label'       => __( 'LinkedIn', 'geodir-converter' ),
						'description' => __( 'The LinkedIn page of the listing.', 'geodir-converter' ),
						'placeholder' => __( 'LinkedIn', 'geodir-converter' ),
						'icon'        => 'fa-brands fa-linkedin',
						'required'    => 0,
					),
					array(
						'type'        => 'checkbox',
						'field_key'   => 'featured',
						'label'       => __( 'Is Featured?', 'geodir-converter' ),
						'description' => __( 'Mark listing as a featured.', 'geodir-converter' ),
						'placeholder' => __( 'Is Featured?', 'geodir-converter' ),
						'icon'        => 'fas fa-certificate',
						'required'    => 0,
					),
					array(
						'type'        => 'checkbox',
						'field_key'   => 'claimed',
						'label'       => __( 'Business Owner/Associate?', 'geodir-converter' ),
						'description' => __( 'Mark listing as a claimed.', 'geodir-converter' ),
						'placeholder' => __( 'Is Claimed?', 'geodir-converter' ),
						'icon'        => 'far fa-check',
						'required'    => 0,
					),
					array(
						'type'        => 'text',
						'field_key'   => 'price',
						'label'       => __( 'Price', 'geodir-converter' ),
						'description' => __( 'The price of the listing.', 'geodir-converter' ),
						'placeholder' => __( 'Price', 'geodir-converter' ),
						'icon'        => 'fas fa-dollar-sign',
						'required'    => 0,
					),
					array(
						'type'        => 'text',
						'field_key'   => 'price_range',
						'label'       => __( 'Price Range', 'geodir-converter' ),
						'description' => __( 'The price range of the listing.', 'geodir-converter' ),
						'placeholder' => __( 'Price Range', 'geodir-converter' ),
						'icon'        => 'fas fa-tags',
						'required'    => 0,
					),
					array(
						'type'        => 'business_hours',
						'field_key'   => 'business_hours',
						'label'       => __( 'Business Hours', 'geodir-converter' ),
						'description' => __( 'The business hours of the listing.', 'geodir-converter' ),
						'placeholder' => __( 'Business Hours', 'geodir-converter' ),
						'icon'        => 'fas fa-clock',
						'required'    => 0,
					),
					array(
						'type'        => 'image',
						'field_key'   => 'company_logo',
						'label'       => __( 'Business Logo', 'geodir-converter' ),
						'description' => __( 'Upload a business logo.', 'geodir-converter' ),
						'placeholder' => __( 'Business Logo', 'geodir-converter' ),
						'icon'        => 'fas fa-image',
						'required'    => 0,
					),
					array(
						'type'        => 'text',
						'field_key'   => 'tagline_text',
						'label'       => __( 'Tagline', 'geodir-converter' ),
						'description' => __( 'Business tagline or slogan.', 'geodir-converter' ),
						'placeholder' => __( 'Tagline', 'geodir-converter' ),
						'icon'        => 'fas fa-quote-right',
						'required'    => 0,
					),
					array(
						'type'        => 'text',
						'field_key'   => 'whatsapp',
						'label'       => __( 'WhatsApp', 'geodir-converter' ),
						'description' => __( 'WhatsApp number.', 'geodir-converter' ),
						'placeholder' => __( 'WhatsApp', 'geodir-converter' ),
						'icon'        => 'fa-brands fa-whatsapp',
						'required'    => 0,
					),
					array(
						'type'        => 'select',
						'field_key'   => 'price_status',
						'label'       => __( 'Price Status', 'geodir-converter' ),
						'description' => __( 'Price visibility status.', 'geodir-converter' ),
						'placeholder' => __( 'Price Status', 'geodir-converter' ),
						'icon'        => 'fas fa-eye',
						'required'    => 0,
						'options'     => implode( ',', $this->price_status_map ),
					),
				)
			);
		}

		if ( self::GD_POST_TYPE_EVENT !== $post_type ) {
			$form_fields = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_name
					FROM {$wpdb->posts} p
					WHERE p.post_type = %s
					AND p.post_status = 'publish'
					ORDER BY p.post_title ASC",
					self::POST_TYPE_FORM_FIELDS
				)
			);

			if ( ! empty( $form_fields ) && ! is_wp_error( $form_fields ) ) {
				foreach ( $form_fields as $form_field ) {
					$field_meta = $this->get_post_meta( $form_field->ID );

					$theme_key  = $this->get_theme_key();
					$lp_options = ! empty( $field_meta[ sprintf( self::META_OPTIONS_PATTERN, $theme_key ) ] )
						? maybe_unserialize( $field_meta[ sprintf( self::META_OPTIONS_PATTERN, $theme_key ) ] )
						: array();

					if ( ! is_array( $lp_options ) ) {
						$lp_options = array();
					}

					$field_type         = isset( $lp_options[ self::FIELD_TYPE ] ) ? $lp_options[ self::FIELD_TYPE ] : 'text';
					$radio_options      = isset( $lp_options[ self::FIELD_RADIO_OPTIONS ] ) ? $lp_options[ self::FIELD_RADIO_OPTIONS ] : '';
					$select_options     = isset( $lp_options[ self::FIELD_SELECT_OPTIONS ] ) ? $lp_options[ self::FIELD_SELECT_OPTIONS ] : '';
					$multicheck_options = isset( $lp_options[ self::FIELD_MULTICHECK_OPTIONS ] ) ? $lp_options[ self::FIELD_MULTICHECK_OPTIONS ] : '';
					$field_categories   = isset( $lp_options[ self::FIELD_CATEGORIES ] ) ? $lp_options[ self::FIELD_CATEGORIES ] : array();
					$exclusive_field    = isset( $lp_options[ self::FIELD_EXCLUSIVE ] ) ? (int) $lp_options[ self::FIELD_EXCLUSIVE ] : 0;
					$gd_field_type      = $this->get_field_type_map( $field_type );
					$field_key          = str_replace( '-', '_', $form_field->post_name );

					$field = array(
						'type'        => $gd_field_type,
						'field_key'   => $field_key,
						'label'       => $form_field->post_title,
						'description' => '',
						'placeholder' => $form_field->post_title,
						'icon'        => 'fas fa-info-circle',
						'required'    => 0,
						'lp_field_id' => $form_field->ID,
						'exclusive'   => $exclusive_field,
					);

					if ( 'radio' === $field_type && ! empty( $radio_options ) ) {
						$field['options'] = $radio_options;
					} elseif ( 'select' === $field_type && ! empty( $select_options ) ) {
						$field['options'] = $select_options;
					} elseif ( 'checkboxes' === $field_type && ! empty( $multicheck_options ) ) {
						$field['options'] = $multicheck_options;
					}

					if ( ! empty( $field_categories ) && is_array( $field_categories ) ) {
						$field['lp_categories'] = $field_categories;
					}

					$fields[] = $field;
				}
			}
		}

		return $fields;
	}

	/**
	 * Get field type mapping from ListingPro to GeoDirectory.
	 *
	 * @since 2.1.4
	 * @param string $lp_type ListingPro field type.
	 * @return string GeoDirectory field type.
	 */
	private function get_field_type_map( $lp_type ) {
		$type_map = array(
			'text'       => 'text',
			'checkbox'   => 'checkbox',
			'check'      => 'checkbox',
			'checkboxes' => 'multiselect',
			'radio'      => 'radio',
			'select'     => 'select',
		);

		return isset( $type_map[ $lp_type ] ) ? $type_map[ $lp_type ] : 'text';
	}

	/**
	 * Get database data type for field type.
	 *
	 * @since 2.1.4
	 * @param string $field_type Field type.
	 * @return string Data type.
	 */
	private function get_data_type_for_field( $field_type ) {
		$type_map = array(
			'text'           => 'VARCHAR',
			'email'          => 'TEXT',
			'url'            => 'TEXT',
			'phone'          => 'VARCHAR',
			'number'         => 'INT',
			'checkbox'       => 'TINYINT',
			'check'          => 'TINYINT',
			'radio'          => 'VARCHAR',
			'select'         => 'VARCHAR',
			'multiselect'    => 'VARCHAR',
			'checkboxes'     => 'VARCHAR',
			'business_hours' => 'TEXT',
		);

		return isset( $type_map[ $field_type ] ) ? $type_map[ $field_type ] : 'VARCHAR';
	}

	/**
	 * Add category-based conditional fields to GD field.
	 *
	 * @since 2.1.4
	 * @param array  $gd_field GeoDirectory field array.
	 * @param array  $lp_categories ListingPro category IDs.
	 * @param array  $lp_field ListingPro field data.
	 * @param string $post_type Post type.
	 * @return array Updated GD field with conditions.
	 */
	private function add_category_conditions( $gd_field, $lp_categories, $lp_field, $post_type ) {
		global $wpdb;

		if ( empty( $lp_categories ) || ! is_array( $lp_categories ) ) {
			return $gd_field;
		}

		$exclusive  = isset( $lp_field['exclusive'] ) && 1 === (int) $lp_field['exclusive'];
		$conditions = array();

		$gd_category_ids = array();
		foreach ( $lp_categories as $lp_cat_id ) {
			$gd_cat_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->termmeta}
					WHERE term_id = %d
					AND meta_key = 'gd_equivalent'",
					absint( $lp_cat_id )
				)
			);

			if ( $gd_cat_id ) {
				$gd_category_ids[] = absint( $gd_cat_id );
			}
		}

		if ( empty( $gd_category_ids ) ) {
			return $gd_field;
		}

		$action = $exclusive ? 'hide' : 'show';

		foreach ( $gd_category_ids as $cat_id ) {
			$conditions[] = array(
				'action'    => $action,
				'field'     => 'post_category',
				'condition' => 'equals to',
				'value'     => (string) $cat_id,
			);
		}

		$extra_fields = isset( $gd_field['extra_fields'] ) && ! empty( $gd_field['extra_fields'] ) ? maybe_unserialize( $gd_field['extra_fields'] ) : array();

		if ( ! is_array( $extra_fields ) ) {
			$extra_fields = array();
		}

		$extra_fields['conditions'] = $conditions;
		$gd_field['extra_fields']   = maybe_serialize( $extra_fields );

		return $gd_field;
	}

	/**
	 * Import categories from Listing Pro to GeoDirectory.
	 *
	 * @since 2.1.4
	 * @param array $task Import task.
	 *
	 * @return array Result of the import operation.
	 */
	public function task_import_categories( $task ) {
		global $wpdb;
		$this->log( esc_html__( 'Categories: Import started.', 'geodir-converter' ) );
		$this->set_import_total();

		if ( 0 === intval( wp_count_terms( self::TAX_LISTING_CATEGORY ) ) ) {
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
				/* translators: %1$d: number of imported terms, %2$d: number of failed imports */
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
				/* translators: %1$d: number of imported terms, %2$d: number of failed imports */
				esc_html__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$result['imported'],
				$result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Import tags from Listing Pro to GeoDirectory.
	 *
	 * @since 2.1.4
	 * @param array $task Import task.
	 *
	 * @return array Result of the import operation.
	 */
	public function task_import_tags( $task ) {
		global $wpdb;
		$this->log( esc_html__( 'Tags: Import started.', 'geodir-converter' ) );

		if ( 0 === intval( wp_count_terms( self::TAX_LISTING_TAG ) ) ) {
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
				self::TAX_LISTING_TAG
			)
		);

		if ( empty( $tags ) || is_wp_error( $tags ) ) {
			$this->log( esc_html__( 'Tags: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		if ( $this->is_test_mode() ) {
			$this->log(
				sprintf(
				/* translators: %1$d: number of imported terms, %2$d: number of failed imports */
					esc_html__( 'Tags: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
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
				esc_html__( 'Tags: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
				$result['imported'],
				$result['failed']
			),
			'success'
		);

		return $this->next_task( $task );
	}

	/**
	 * Import features from Listing Pro to GeoDirectory as a multiselect custom field.
	 *
	 * @since 2.1.4
	 * @param array $task Import task.
	 *
	 * @return array Result of the import operation.
	 */
	public function task_import_features( $task ) {
		global $wpdb;
		$this->log( esc_html__( 'Features: Creating multiselect field...', 'geodir-converter' ) );

		if ( 0 === intval( wp_count_terms( self::TAX_LISTING_FEATURES ) ) ) {
			$this->log( esc_html__( 'Features: No items to import.', 'geodir-converter' ), 'warning' );
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
				self::TAX_LISTING_FEATURES
			)
		);

		if ( empty( $features ) || is_wp_error( $features ) ) {
			$this->log( esc_html__( 'Features: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$feature_names  = wp_list_pluck( $features, 'name' );
		$option_values  = implode( ',', $feature_names );
		$existing_field = geodir_get_field_infoby( 'htmlvar_name', 'features', $post_type );

		if ( $this->is_test_mode() ) {
			$this->log(
				sprintf(
					esc_html__( 'Features: Field would be %1$s with %2$d options.', 'geodir-converter' ),
					$existing_field ? 'updated' : 'created',
					count( $features )
				),
				'success'
			);
			$this->increase_succeed_imports( 1 );
			return $this->next_task( $task );
		}

		$field = array(
			'post_type'          => $post_type,
			'field_type'         => 'multiselect',
			'data_type'          => 'VARCHAR',
			'admin_title'        => __( 'Features', 'geodir-converter' ),
			'frontend_title'     => __( 'Features', 'geodir-converter' ),
			'frontend_desc'      => __( 'Select the listing features.', 'geodir-converter' ),
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
			'clabels'            => __( 'Features', 'geodir-converter' ),
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
			$this->log( esc_html__( 'Features: Failed to create/update field.', 'geodir-converter' ), 'error' );
			$this->increase_failed_imports( 1 );
		} else {
			$this->log(
				sprintf(
					esc_html__( 'Features: Field %1$s successfully with %2$d options.', 'geodir-converter' ),
					$existing_field ? 'updated' : 'created',
					count( $features )
				),
				'success'
			);
			$this->increase_succeed_imports( 1 );
		}

		return $this->next_task( $task );
	}

	/**
	 * Import fields from Listing Pro to GeoDirectory.
	 *
	 * @since 2.1.4
	 * @param array $task Task details.
	 * @return array Result of the import operation.
	 */
	public function task_import_fields( array $task ) {
		$this->log( esc_html__( 'Importing standard fields...', 'geodir-converter' ) );

		$fields_cpts = array( $this->get_import_post_type() );

		if ( class_exists( 'GeoDir_Event_Manager' ) ) {
			$fields_cpts[] = self::GD_POST_TYPE_EVENT;
		}

		$imported = $updated = $skipped = $failed = 0;

		foreach ( $fields_cpts as $post_type ) {
			$fields = $this->get_custom_fields( $post_type );

			if ( empty( $fields ) ) {
				$this->log( sprintf( __( 'No custom fields found for post type: %s', 'geodir-converter' ), $post_type ), 'warning' );
				continue;
			}

			$package_ids = $this->get_package_ids( $post_type );

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
					$this->log( sprintf( __( 'Failed to import custom field: %1$s - %2$s', 'geodir-converter' ), $field['label'], $error_msg ), 'error' );
				}
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
	 * Convert Listing Pro field to GD field.
	 *
	 * @since 2.1.4
	 * @param array  $field The Listing Pro field data.
	 * @param string $post_type The post type.
	 * @param array  $package_ids The package data.
	 * @return array|false The GD field data or false if conversion fails.
	 */
	private function prepare_single_field( $field, $post_type, $package_ids = array() ) {
		$field_type = isset( $field['type'] ) ? $field['type'] : 'text';
		$field_id   = $this->field_exists( $field['field_key'], $post_type );

		$gd_field = array(
			'post_type'     => $post_type,
			'data_type'     => $this->get_data_type_for_field( $field_type ),
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
				'frontend_desc'     => $field['description'],
				'placeholder_value' => isset( $field['placeholder'] ) ? $field['placeholder'] : '',
				'frontend_title'    => $field['label'],
				'default_value'     => '',
				'for_admin_use'     => in_array( $field['field_key'], array( 'listingpro_id' ), true ) ? 1 : 0,
				'is_required'       => isset( $field['required'] ) && 1 === $field['required'] ? 1 : 0,
				'show_in'           => '[detail]',
				'show_on_pkg'       => $package_ids,
				'clabels'           => $field['label'],
				'option_values'     => $option_values,
				'field_icon'        => isset( $field['icon'] ) ? $field['icon'] : 'fas fa-info-circle',
			)
		);

		if ( 'business_hours' === $field_type ) {
			$gd_field = array_merge(
				$gd_field,
				array(
					'htmlvar_name' => 'business_hours',
					'field_type'   => 'business_hours',
					'field_icon'   => 'fas fa-clock',
					'data_type'    => 'TEXT',
				)
			);
		} elseif ( 'checkbox' === $field_type || 'check' === $field_type ) {
			$gd_field['data_type']  = 'TINYINT';
			$gd_field['field_type'] = 'checkbox';
		} elseif ( 'multiselect' === $field_type || 'checkboxes' === $field_type ) {
			$gd_field['data_type']  = 'VARCHAR';
			$gd_field['field_type'] = 'multiselect';
		}

		if ( in_array( $field['field_key'], array( 'facebook', 'twitter', 'pinterest', 'linkedin', 'instagram', 'youtube' ), true ) ) {
			$gd_field['field_icon'] = "fab fa-{$field['field_key']}";
			$gd_field['field_type'] = 'url';
		}

		if ( isset( $field['lp_categories'] ) && ! empty( $field['lp_categories'] ) ) {
			$gd_field = $this->add_category_conditions( $gd_field, $field['lp_categories'], $field, $post_type );
		}

		return $gd_field;
	}

	/**
	 * Import packages/plans from ListingPro.
	 *
	 * @since 2.1.4
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
		$plans     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content, post_status, menu_order 
				FROM {$wpdb->posts} 
				WHERE post_type = %s 
				AND post_status = 'publish'
				ORDER BY menu_order ASC",
				self::POST_TYPE_PRICE_PLAN
			)
		);

		if ( empty( $plans ) ) {
			$this->log( esc_html__( 'Packages: No items to import.', 'geodir-converter' ), 'warning' );
			return $this->next_task( $task );
		}

		$imported = $updated = $failed = 0;

		foreach ( $plans as $plan ) {
			$plan_id    = absint( $plan->ID );
			$plan_meta  = $this->get_post_meta( $plan_id );
			$theme_key  = $this->get_theme_key();
			$lp_options = ! empty( $plan_meta[ sprintf( self::META_OPTIONS_PATTERN, $theme_key ) ] ) ? maybe_unserialize( $plan_meta[ sprintf( self::META_OPTIONS_PATTERN, $theme_key ) ] ) : array();

			if ( ! is_array( $lp_options ) ) {
				$lp_options = array();
			}

			$plan_price = isset( $plan_meta['plan_price'] ) ? floatval( $plan_meta['plan_price'] ) : 0.0;
			$plan_time  = isset( $plan_meta['plan_time'] ) ? absint( $plan_meta['plan_time'] ) : 0;
			$is_free    = 0.0 === $plan_price || 0 === $plan_price;

			$existing_package = $this->package_exists( $post_type, $plan_id, $is_free );

			$package_data = array(
				'post_type'       => $post_type,
				'name'            => $plan->post_title,
				'title'           => $plan->post_title,
				'description'     => $plan->post_content,
				'fa_icon'         => '',
				'amount'          => $plan_price,
				'time_interval'   => $plan_time > 0 ? $plan_time : 30,
				'time_unit'       => 'D',
				'recurring'       => false,
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

			if ( $existing_package ) {
				$package_data['id'] = absint( $existing_package->id );
			}

			if ( $this->is_test_mode() ) {
				$existing_package ? $updated++ : $imported++;
				continue;
			}

			$package_data = GeoDir_Pricing_Package::prepare_data_for_save( $package_data );
			$package_id   = GeoDir_Pricing_Package::insert_package( $package_data );

			if ( ! $package_id || is_wp_error( $package_id ) ) {
				$this->log( sprintf( __( 'Failed to import package: %s', 'geodir-converter' ), $plan->post_title ), 'error' );
				++$failed;
			} else {
				$is_update = ! empty( $existing_package );

				$log_message = $is_update ? sprintf( __( 'Updated package: %s', 'geodir-converter' ), $plan->post_title ) : sprintf( __( 'Imported package: %s', 'geodir-converter' ), $plan->post_title );
				$this->log( $log_message );

				$is_update ? ++$updated : ++$imported;

				if ( ! $this->package_has_meta( $package_id ) ) {
					GeoDir_Pricing_Package::update_meta( $package_id, '_listingpro_plan_id', $plan_id );
				}
			}
		}

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_failed_imports( $failed );

		$this->log(
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
	 * Check if package exists by ListingPro plan ID.
	 *
	 * @since 2.0.3
	 * @param string $post_type Post type.
	 * @param int    $plan_id ListingPro plan ID.
	 * @param bool   $free_fallback Whether to fallback to free package if no match found.
	 *
	 * @return object|null Existing package object or null.
	 */
	private function package_exists( $post_type, $plan_id, $free_fallback = true ) {
		global $wpdb;

		$existing_package = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT p.*, g.* 
				FROM ' . GEODIR_PRICING_PACKAGES_TABLE . ' AS p
				INNER JOIN ' . GEODIR_PRICING_PACKAGE_META_TABLE . ' AS g ON p.ID = g.package_id
				WHERE p.post_type = %s AND g.meta_key = %s AND g.meta_value = %d
				LIMIT 1',
				$post_type,
				'_listingpro_plan_id',
				(int) $plan_id
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
	 * Check if package already has ListingPro meta assigned.
	 *
	 * @since 2.1.4
	 * @param int $package_id Package ID.
	 * @return bool True if package has ListingPro meta, false otherwise.
	 */
	private function package_has_meta( $package_id ) {
		global $wpdb;

		$meta_value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM ' . GEODIR_PRICING_PACKAGE_META_TABLE . ' 
				WHERE package_id = %d AND meta_key = %s
				LIMIT 1',
				(int) $package_id,
				'_listingpro_plan_id'
			)
		);

		return ! empty( $meta_value );
	}

	/**
	 * Check if the listing orders table exists.
	 *
	 * @since 2.1.4
	 * @return bool True if table exists, false otherwise.
	 */
	private function orders_table_exists() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'listing_orders';
		$exists     = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		return $exists === $table_name;
	}

	/**
	 * Check if the listing campaigns table exists.
	 *
	 * @since 2.1.4
	 * @return bool True if table exists, false otherwise.
	 */
	private function campaigns_table_exists() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'listing_campaigns';
		$exists     = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		return $exists === $table_name;
	}

	/**
	 * Get packages mapping from ListingPro plan IDs to GeoDirectory package IDs.
	 *
	 * @since 2.1.4
	 * @return array Packages mapping array.
	 */
	private function get_packages_mapping() {
		global $wpdb;

		$packages_mapping = array();
		$results          = $wpdb->get_results(
			'SELECT meta_value AS lp_id, package_id AS gd_id 
			FROM ' . GEODIR_PRICING_PACKAGE_META_TABLE . " 
			WHERE meta_key = '_listingpro_plan_id'"
		);

		foreach ( $results as $row ) {
			$packages_mapping[ $row->lp_id ] = $row->gd_id;
		}

		return $packages_mapping;
	}

	/**
	 * Import ad zones for ListingPro ad types.
	 *
	 * @since 2.1.4
	 * @return array Zone ID mapping.
	 */
	private function import_ad_zones() {
		$listingpro_options = get_option( 'listingpro_options', array() );

		$zones_mapping = array(
			'lp_random_ads'             => 0,
			'lp_detail_page_ads'        => 0,
			'lp_top_in_search_page_ads' => 0,
		);

		$zone_titles = array(
			'lp_random_ads'             => __( 'Sponsored Listings', 'geodir-converter' ),
			'lp_detail_page_ads'        => __( 'Listing Detail Sidebar', 'geodir-converter' ),
			'lp_top_in_search_page_ads' => __( 'Above Search Results', 'geodir-converter' ),
		);

		$zone_prices = array(
			'lp_random_ads'             => isset( $listingpro_options['lp_random_ads'] ) ? floatval( $listingpro_options['lp_random_ads'] ) : 0,
			'lp_detail_page_ads'        => isset( $listingpro_options['lp_detail_page_ads'] ) ? floatval( $listingpro_options['lp_detail_page_ads'] ) : 0,
			'lp_top_in_search_page_ads' => isset( $listingpro_options['lp_top_in_search_page_ads'] ) ? floatval( $listingpro_options['lp_top_in_search_page_ads'] ) : 0,
		);

		foreach ( $zones_mapping as $mode => $zone_id ) {
			$existing_zone = get_posts(
				array(
					'post_type'      => self::GD_POST_TYPE_AD_ZONE,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'meta_key'       => '_listingpro_ad_mode',
					'meta_value'     => $mode,
				)
			);

			if ( ! empty( $existing_zone ) ) {
				$zone_id = $existing_zone[0]->ID;

				// Update the zone if needed.
				$zone_data = array(
					'ID'           => $zone_id,
					'post_title'   => $zone_titles[ $mode ],
					'post_status'  => 'publish',
					'post_content' => sprintf( __( 'Imported from ListingPro %s campaigns', 'geodir-converter' ), $zone_titles[ $mode ] ),
				);
				wp_update_post( $zone_data );

				// Update zone price.
				update_post_meta( $zone_id, '_adv_zone_price', $zone_prices[ $mode ] );

				$zones_mapping[ $mode ] = $zone_id;
			} else {
				$zone_id = wp_insert_post(
					array(
						'post_type'    => self::GD_POST_TYPE_AD_ZONE,
						'post_title'   => $zone_titles[ $mode ],
						'post_status'  => 'publish',
						'post_content' => sprintf( __( 'Imported from ListingPro %s campaigns', 'geodir-converter' ), $zone_titles[ $mode ] ),
					)
				);

				if ( ! is_wp_error( $zone_id ) && $zone_id ) {
					update_post_meta( $zone_id, '_listingpro_ad_mode', $mode );
					update_post_meta( $zone_id, '_adv_zone_price', $zone_prices[ $mode ] );
					$zones_mapping[ $mode ] = $zone_id;
				}
			}
		}

		return $zones_mapping;
	}

	/**
	 * Parse and batch listings for background import.
	 *
	 * @since 2.1.4
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
	 * Import a batch of listings (called by background process).
	 *
	 * @since 2.1.4
	 * @param array $task The task to import.
	 * @return bool Result of the import operation.
	 */
	public function task_import_listings( $task ) {
		$listings = isset( $task['listings'] ) && ! empty( $task['listings'] ) ? (array) $task['listings'] : array();

		$packages_mapping = $this->is_test_mode() ? array() : $this->get_packages_mapping();

		foreach ( $listings as $listing ) {
			$title  = $listing->post_title;
			$status = $this->import_single_listing( $listing, $packages_mapping );

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
	 * Parse events from ListingPro and queue for import.
	 *
	 * @since 2.1.4
	 * @param array $task Import task.
	 * @return array|bool Result of the import operation.
	 */
	public function task_parse_events( array $task ) {
		global $wpdb;

		if ( ! class_exists( 'GeoDir_Event_Manager' ) ) {
			$this->log( __( 'Events addon not active. Skipping events...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$offset       = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$total_events = isset( $task['total_events'] ) ? absint( $task['total_events'] ) : 0;
		$batch_size   = absint( $this->get_batch_size() );
		$post_type    = self::GD_POST_TYPE_EVENT;

		if ( ! isset( $task['total_events'] ) ) {
			$total_events             = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} 
					WHERE post_type = %s 
					AND post_status IN (" . implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) ) . ')',
					array_merge( array( self::POST_TYPE_EVENTS ), $this->post_statuses )
				)
			);
				$task['total_events'] = $total_events;
		}

		if ( 0 === $offset ) {
			$this->log( sprintf( __( 'Starting events parsing process: %d events found.', 'geodir-converter' ), $total_events ) );
		}

		if ( 0 === $total_events ) {
			$this->log( __( 'No events found for parsing. Skipping process.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		wp_suspend_cache_addition( false );

		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_status 
				FROM {$wpdb->posts} 
				WHERE post_type = %s 
				AND post_status IN (" . implode( ',', array_fill( 0, count( $this->post_statuses ), '%s' ) ) . ') 
				LIMIT %d OFFSET %d',
				array_merge(
					array( self::POST_TYPE_EVENTS ),
					$this->post_statuses,
					array( $batch_size, $offset )
				)
			)
		);

		if ( empty( $events ) ) {
			$this->log( __( 'Import process completed. No more events found.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$batched_tasks = array_chunk( $events, self::BATCH_SIZE_IMPORT, true );
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

		return $this->next_task( $task );
	}

	/**
	 * Import a batch of events.
	 *
	 * @since 2.1.4
	 * @param array $task The task to import.
	 * @return bool Result of the import operation.
	 */
	public function task_import_events( $task ) {
		$events = isset( $task['events'] ) && ! empty( $task['events'] ) ? (array) $task['events'] : array();

		foreach ( $events as $event ) {
			$title  = $event->post_title;
			$status = $this->import_single_event( $event );

			switch ( $status ) {
				case self::IMPORT_STATUS_SUCCESS:
				case self::IMPORT_STATUS_UPDATED:
					if ( self::IMPORT_STATUS_SUCCESS === $status ) {
						$this->log( sprintf( self::LOG_TEMPLATE_SUCCESS, 'event', $title ), 'success' );
					} else {
						$this->log( sprintf( self::LOG_TEMPLATE_UPDATED, 'event', $title ), 'warning' );
					}

					$this->increase_succeed_imports( 1 );
					break;
				case self::IMPORT_STATUS_SKIPPED:
					$this->log( sprintf( self::LOG_TEMPLATE_SKIPPED, 'event', $title ), 'warning' );
					$this->increase_skipped_imports( 1 );
					break;
				case self::IMPORT_STATUS_FAILED:
				default:
					$this->log( sprintf( self::LOG_TEMPLATE_FAILED, 'event', $title ), 'warning' );
					$this->increase_failed_imports( 1 );
					break;
			}
		}

		return false;
	}

	/**
	 * Import a single event from ListingPro.
	 *
	 * @since 2.1.4
	 * @param object $event Event object with ID, post_title, post_status.
	 * @return string Import status.
	 */
	private function import_single_event( $event ) {
		$post_type   = self::GD_POST_TYPE_EVENT;
		$post        = get_post( $event->ID );
		$gd_event_id = ! $this->is_test_mode() ? $this->get_gd_listing_id( $post->ID, 'listingpro_id', $post_type ) : false;
		$is_update   = ! empty( $gd_event_id );
		$post_meta   = $this->get_post_meta( $post->ID );

		$event_date   = isset( $post_meta['event-date'] ) ? absint( $post_meta['event-date'] ) : 0;
		$event_time   = isset( $post_meta['event-time'] ) ? sanitize_text_field( $post_meta['event-time'] ) : '';
		$event_date_e = isset( $post_meta['event-date-e'] ) ? absint( $post_meta['event-date-e'] ) : 0;
		$event_time_e = isset( $post_meta['event-time-e'] ) ? sanitize_text_field( $post_meta['event-time-e'] ) : '';
		$event_lat    = isset( $post_meta['event-lat'] ) ? sanitize_text_field( $post_meta['event-lat'] ) : '';
		$event_lon    = isset( $post_meta['event-lon'] ) ? sanitize_text_field( $post_meta['event-lon'] ) : '';
		$event_loc    = isset( $post_meta['event-loc'] ) ? sanitize_text_field( $post_meta['event-loc'] ) : '';
		$ticket_url   = isset( $post_meta['ticket-url'] ) ? esc_url( $post_meta['ticket-url'] ) : '';
		$listing_id   = isset( $post_meta['event-lsiting-id'] ) ? absint( $post_meta['event-lsiting-id'] ) : 0;

		$default_location = $this->get_default_location();
		$location         = $default_location;

		if ( $event_lat && $event_lon ) {
			$location_lookup = GeoDir_Converter_Utils::get_location_from_coords( $event_lat, $event_lon );

			if ( ! is_wp_error( $location_lookup ) ) {
				$location = array_merge( $location, $location_lookup );
			} else {
				$location['latitude']  = $event_lat;
				$location['longitude'] = $event_lon;
			}
		}

		$start_date = $event_date ? date( 'Y-m-d', $event_date ) : '';
		$start_time = $event_time ? $this->convert_time_to_24hr( $event_time ) : '00:00';
		$end_date   = $event_date_e ? date( 'Y-m-d', $event_date_e ) : $start_date;
		$end_time   = $event_time_e ? $this->convert_time_to_24hr( $event_time_e ) : '23:59';

		$gd_event = array(
			'post_author'           => $post->post_author ? $post->post_author : get_current_user_id(),
			'post_title'            => $post->post_title ? $post->post_title : '&mdash;',
			'post_content'          => $post->post_content ? $post->post_content : '',
			'post_content_filtered' => $post->post_content ? $post->post_content : '',
			'post_excerpt'          => $post->post_excerpt ? $post->post_excerpt : '',
			'post_status'           => $post->post_status,
			'post_date'             => $post->post_date,
			'post_date_gmt'         => $post->post_date_gmt,
			'post_type'             => $post_type,
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'city'                  => $location['city'],
			'region'                => $location['region'],
			'country'               => $location['country'],
			'latitude'              => $location['latitude'],
			'longitude'             => $location['longitude'],
			'street'                => $event_loc,
			'listingpro_id'         => $post->ID,
			'featured_image'        => $this->get_featured_image( $post->ID ),
			'event_dates'           => array(
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
			),
		);

		if ( $ticket_url ) {
			$gd_event['event_reg_url'] = $ticket_url;
		}

		if ( $this->is_test_mode() ) {
			return $is_update ? self::IMPORT_STATUS_SKIPPED : self::IMPORT_STATUS_SUCCESS;
		}

		if ( $is_update ) {
			GeoDir_Media::delete_files( (int) $gd_event_id, 'post_images' );
			$gd_event['ID'] = absint( $gd_event_id );
			$gd_event_id    = wp_update_post( $gd_event, true );
		} else {
			$gd_event_id = wp_insert_post( $gd_event, true );
		}

		if ( is_wp_error( $gd_event_id ) ) {
			return self::IMPORT_STATUS_FAILED;
		}

		if ( $listing_id ) {
			$gd_listing_id = $this->get_gd_listing_id( $listing_id, 'listingpro_id', $this->get_import_post_type() );
			if ( $gd_listing_id ) {
				update_post_meta( $gd_event_id, 'event_listing', $gd_listing_id );
			}
		}

		return $is_update ? self::IMPORT_STATUS_UPDATED : self::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Convert a single Listing Pro listing to GeoDirectory format.
	 *
	 * @since 2.1.4
	 * @param  WP_Post $listing The post object to convert.
	 * @return array|int Converted listing data or import status.
	 */
	private function import_single_listing( $listing, $packages_mapping = array() ) {
		$post             = get_post( $listing->ID );
		$post_type        = $this->get_import_post_type();
		$gd_post_id       = ! $this->is_test_mode() ? $this->get_gd_listing_id( $post->ID, 'listingpro_id', $post_type ) : false;
		$is_update        = ! empty( $gd_post_id );
		$post_meta        = $this->get_post_meta( $post->ID );
		$default_location = $this->get_default_location();
		$fields           = $this->process_form_fields( $post, $post_meta, $post_type );
		$categories       = $this->get_listings_terms( $post->ID, self::TAX_LISTING_CATEGORY );
		$tags             = $this->get_listings_terms( $post->ID, self::TAX_LISTING_TAG, 'names' );
		$feature_terms    = wp_get_post_terms( $post->ID, self::TAX_LISTING_FEATURES, array( 'fields' => 'names' ) );
		$feature_names    = is_array( $feature_terms ) && ! is_wp_error( $feature_terms ) ? $feature_terms : array();
		$location         = $default_location;
		$theme_key        = $this->get_theme_key();
		$lp_options       = ! empty( $post_meta[ sprintf( self::META_OPTIONS_PATTERN, $theme_key ) ] ) ? maybe_unserialize( $post_meta[ sprintf( self::META_OPTIONS_PATTERN, $theme_key ) ] ) : array();

		if ( ! is_array( $lp_options ) ) {
			$lp_options = array();
		}

		$address         = isset( $post_meta['gAddress'] ) ? $post_meta['gAddress'] : ( isset( $lp_options['gAddress'] ) ? $lp_options['gAddress'] : '' );
		$has_coordinates = ( isset( $post_meta['latitude'] ) && ! empty( $post_meta['latitude'] ) && isset( $post_meta['longitude'] ) && ! empty( $post_meta['longitude'] ) )
			|| ( isset( $lp_options['latitude'] ) && ! empty( $lp_options['latitude'] ) && isset( $lp_options['longitude'] ) && ! empty( $lp_options['longitude'] ) );

		if ( $has_coordinates ) {
			$lat = isset( $post_meta['latitude'] ) ? $post_meta['latitude'] : $lp_options['latitude'];
			$lng = isset( $post_meta['longitude'] ) ? $post_meta['longitude'] : $lp_options['longitude'];

			$this->log( 'Pulling listing address from coordinates: ' . $lat . ', ' . $lng, 'info' );
			$location_lookup = GeoDir_Converter_Utils::get_location_from_coords( $lat, $lng );

			if ( ! is_wp_error( $location_lookup ) ) {
				$address  = isset( $location_lookup['address'] ) && ! empty( $location_lookup['address'] ) ? $location_lookup['address'] : $address;
				$location = array_merge( $location, $location_lookup );
			} else {
				$location['latitude']  = $lat;
				$location['longitude'] = $lng;
			}
		}

		$location = wp_parse_args(
			$location,
			array(
				'city'      => '',
				'region'    => '',
				'country'   => '',
				'zip'       => '',
				'latitude'  => '',
				'longitude' => '',
			)
		);

		$listing = array(
			'post_author'           => $post->post_author ? $post->post_author : get_current_user_id(),
			'post_title'            => $post->post_title,
			'post_content'          => $post->post_content ? $post->post_content : '',
			'post_content_filtered' => $post->post_content,
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
				$post_type . '_tags'    => $tags,
			),

			// GD fields.
			'default_category'      => ! empty( $categories ) ? $categories[0] : 0,
			'featured_image'        => $this->get_featured_image( $post->ID ),
			'submit_ip'             => '',
			'overall_rating'        => isset( $post_meta[ self::META_LISTING_RATE ] ) ? floatval( $post_meta[ self::META_LISTING_RATE ] ) : 0,
			'rating_count'          => isset( $post_meta[ self::META_LISTING_REVIEWED ] ) ? absint( $post_meta[ self::META_LISTING_REVIEWED ] ) : 0,

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

			'listingpro_id'         => $post->ID,
			'claimed'               => isset( $post_meta[ self::META_CLAIMED ] ) ? (bool) $post_meta[ self::META_CLAIMED ] : false,
			'plan_id'               => isset( $post_meta[ self::META_PLAN_ID ] ) ? absint( $post_meta[ self::META_PLAN_ID ] ) : 0,
			'post_views'            => isset( $post_meta[ self::META_POST_VIEWS ] ) ? absint( $post_meta[ self::META_POST_VIEWS ] ) : 0,
			'features'              => ! empty( $feature_names ) ? implode( ',', $feature_names ) : '',
		);

		if ( $this->is_test_mode() ) {
			return GeoDir_Converter_Importer::IMPORT_STATUS_SUCCESS;
		}

		if ( $is_update ) {
			GeoDir_Media::delete_files( (int) $gd_post_id, 'post_images' );
		}

		$listing['post_images'] = $this->get_post_images( $post_meta );

		if ( ! empty( $fields ) ) {
			foreach ( $fields as $key => $value ) {
				if ( empty( $listing[ $key ] ) ) {
					$listing[ $key ] = $value;
				}
			}
		}

		// Listing package.
		if ( class_exists( 'GeoDir_Pricing_Package' ) ) {
			if ( empty( $listing['package_id'] ) ) {
				$lp_plan_id = $this->listing_get_metabox_by_ID( 'Plan_id', (int) $post->ID );

				if ( $lp_plan_id && ! empty( $packages_mapping[ $lp_plan_id ] ) ) {
					$listing['package_id'] = (int) $packages_mapping[ $lp_plan_id ];
				}
			}

			if ( empty( $listing['package_id'] ) ) {
				$listing['package_id'] = geodir_get_post_package_id( $gd_post_id, $post_type );
			}

			if ( empty( $listing['expire_date'] ) ) {
				$lp_plan_duration = $this->listing_get_metabox_by_ID( 'lp_purchase_days', (int) $post->ID );

				if ( $lp_plan_duration ) {
					$listing['expire_date'] = strtotime( get_the_time( 'd-m-Y' ) . ' + ' . (int) $lp_plan_duration . ' days' );
				}
			}
		}

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

		$this->add_reviews_import_tasks( $post->ID, $gd_post_id );
		$this->add_invoices_import_tasks( $post->ID, $gd_post_id );
		$this->add_ads_import_tasks( $post->ID, $gd_post_id );

		return $is_update ? GeoDir_Converter_Importer::IMPORT_STATUS_UPDATED : GeoDir_Converter_Importer::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Add review import tasks for a listing.
	 *
	 * @since 2.1.4
	 * @param int $lp_listing_id ListingPro listing ID.
	 * @param int $gd_post_id GeoDirectory post ID.
	 * @return void
	 */
	private function add_reviews_import_tasks( $lp_listing_id, $gd_post_id ) {
		global $wpdb;

		$theme_key = $this->get_theme_key();

		$reviews = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_content, p.post_author, 
				        p.post_date, p.post_date_gmt, p.post_status
				FROM {$wpdb->posts} p
				WHERE p.post_type = %s
				AND p.post_parent = %d
				ORDER BY p.ID ASC",
				self::POST_TYPE_REVIEW,
				$lp_listing_id
			)
		);

		if ( empty( $reviews ) ) {
			return;
		}

		$batched_reviews = array_chunk( $reviews, self::BATCH_SIZE_IMPORT );
		$import_tasks    = array();

		foreach ( $batched_reviews as $review_batch ) {
			$review_data = array();

			foreach ( $review_batch as $review ) {
				$review_meta = get_post_meta( $review->ID, sprintf( self::META_OPTIONS_PATTERN, $theme_key ), true );
				$rating      = is_array( $review_meta ) && isset( $review_meta['rating'] ) ? absint( $review_meta['rating'] ) : 0;

				$review_data[] = array(
					'review_id'     => $review->ID,
					'post_title'    => $review->post_title,
					'post_content'  => $review->post_content,
					'post_author'   => $review->post_author,
					'post_date'     => $review->post_date,
					'post_date_gmt' => $review->post_date_gmt,
					'post_status'   => $review->post_status,
					'rating'        => $rating,
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
			return false;
		}

		$imported = $skipped = $failed = 0;

		foreach ( $reviews as $review_data ) {
			$review_id    = $review_data['review_id'];
			$rating       = $review_data['rating'];
			$review_agent = 'geodir-converter-lp-' . $review_id;

			$existing_review = get_comments(
				array(
					'comment_agent' => $review_agent,
					'number'        => 1,
				)
			);

			$author       = get_userdata( $review_data['post_author'] );
			$author_name  = $author ? $author->display_name : 'Anonymous';
			$author_email = $author ? $author->user_email : 'anonymous@localhost.local';

			$comment_data = array(
				'comment_post_ID'      => $gd_post_id,
				'user_id'              => $review_data['post_author'],
				'comment_date'         => $review_data['post_date'],
				'comment_date_gmt'     => $review_data['post_date_gmt'],
				'comment_content'      => $review_data['post_content'],
				'comment_author'       => $author_name,
				'comment_author_email' => $author_email,
				'comment_agent'        => $review_agent,
				'comment_approved'     => 'publish' === $review_data['post_status'] ? 1 : 0,
				'comment_type'         => 'review',
			);

			if ( ! empty( $existing_review ) && isset( $existing_review[0]->comment_ID ) ) {
				$comment_data['comment_ID'] = (int) $existing_review[0]->comment_ID;
				$comment_id                 = wp_update_comment( $comment_data );
				++$skipped;
			} else {
				$comment_id = wp_insert_comment( $comment_data );
				++$imported;
			}

			if ( is_wp_error( $comment_id ) || ! $comment_id ) {
				++$failed;
				$this->log( sprintf( __( 'Failed to import review #%d', 'geodir-converter' ), $review_id ), 'error' );
				continue;
			}

			if ( $rating ) {
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
	 * Add invoice import tasks for a listing.
	 *
	 * @since 2.1.4
	 * @param int $lp_listing_id ListingPro listing ID.
	 * @param int $gd_post_id GeoDirectory post ID.
	 * @return void
	 */
	private function add_invoices_import_tasks( $lp_listing_id, $gd_post_id ) {
		global $wpdb;

		if ( ! class_exists( 'WPInv_Plugin' ) || ! $this->orders_table_exists() ) {
			return;
		}

		$packages_mapping = $this->get_packages_mapping();
		if ( empty( $packages_mapping ) ) {
			return;
		}

		$orders_table = $wpdb->prefix . 'listing_orders';

		// Get orders for this listing.
		$orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$orders_table} WHERE post_id = %d ORDER BY main_id ASC",
				$lp_listing_id
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		// Batch orders and queue them.
		$batched_orders = array_chunk( $orders, self::BATCH_SIZE_IMPORT );
		$import_tasks   = array();

		foreach ( $batched_orders as $order_batch ) {
			$import_tasks[] = array(
				'action'           => self::ACTION_IMPORT_INVOICES,
				'orders'           => $order_batch,
				'packages_mapping' => $packages_mapping,
			);
		}

		$this->background_process->add_import_tasks( $import_tasks );
	}

	/**
	 * Import a batch of invoices.
	 *
	 * @since 2.1.4
	 * @param array $task Import task.
	 * @return bool Result of the import operation.
	 */
	public function task_import_invoices( $task ) {
		$orders           = isset( $task['orders'] ) && is_array( $task['orders'] ) ? $task['orders'] : array();
		$packages_mapping = isset( $task['packages_mapping'] ) && is_array( $task['packages_mapping'] ) ? $task['packages_mapping'] : array();

		if ( empty( $orders ) ) {
			return false;
		}

		if ( $this->is_test_mode() ) {
			return false;
		}

		$imported = $skipped = $failed = 0;

		foreach ( $orders as $order ) {
			$result = $this->import_single_invoice( $order, $packages_mapping );

			if ( self::IMPORT_STATUS_SUCCESS === $result ) {
				++$imported;
			} elseif ( self::IMPORT_STATUS_SKIPPED === $result ) {
				++$skipped;
			} else {
				++$failed;
			}
		}

		if ( $imported > 0 || $skipped > 0 || $failed > 0 ) {
			$this->log(
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
	 * Import a single invoice.
	 *
	 * @since 2.1.4
	 * @param object $order Order data.
	 * @param array  $packages_mapping Packages mapping.
	 * @return string Import status.
	 */
	private function import_single_invoice( $order, $packages_mapping ) {
		$invoice_id = $this->get_gd_post_id( $order->main_id, 'listingpro_order_id' );
		$is_update  = ! empty( $invoice_id );

		$package_id = isset( $packages_mapping[ $order->plan_id ] ) ? (int) $packages_mapping[ $order->plan_id ] : 0;
		if ( ! $package_id ) {
			return self::IMPORT_STATUS_SKIPPED;
		}

		$wpinv_item = wpinv_get_item_by( 'custom_id', $package_id, 'package' );
		if ( ! $wpinv_item || ! $wpinv_item->exists() ) {
			return self::IMPORT_STATUS_SKIPPED;
		}

		$status = isset( $this->invoice_status_map[ strtolower( $order->status ) ] ) ? $this->invoice_status_map[ strtolower( $order->status ) ] : 'wpi-pending';

		// Prepare taxes array.
		$taxes = array();
		if ( ! empty( $order->tax ) && (float) $order->tax > 0 ) {
			$taxes[ __( 'Tax', 'geodir-converter' ) ] = array( 'initial_tax' => (float) $order->tax );
		}

		$wpi_invoice = new WPInv_Invoice();
		$wpi_invoice->set_props(
			array(
				'post_type'      => self::GD_POST_TYPE_INVOICE,
				'post_title'     => ! empty( $order->order_id ) ? $order->order_id : 'LP-' . $order->main_id,
				'post_name'      => 'inv-lp-' . $order->main_id,
				'description'    => ! empty( $order->description ) ? $order->description : '',
				'status'         => $status,
				'created_via'    => self::CREATED_VIA,
				'date_created'   => ! empty( $order->date ) ? date( 'Y-m-d H:i:s', strtotime( $order->date ) ) : current_time( 'mysql' ),
				'gateway'        => ! empty( $order->payment_method ) ? strtolower( $order->payment_method ) : 'manual',
				'transaction_id' => ! empty( $order->transaction_id ) ? $order->transaction_id : '',
				'taxes'          => $taxes,
				'user_id'        => (int) $order->user_id,
				'email'          => ! empty( $order->email ) ? $order->email : '',
				'first_name'     => ! empty( $order->firstname ) ? $order->firstname : '',
				'last_name'      => ! empty( $order->lastname ) ? $order->lastname : '',
				'currency'       => ! empty( $order->currency ) ? $order->currency : 'USD',
			)
		);

		$invoice_id = $wpi_invoice->save();

		if ( is_wp_error( $invoice_id ) ) {
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

		update_post_meta( $invoice_id, 'listingpro_order_id', $order->main_id );

		return $is_update ? self::IMPORT_STATUS_UPDATED : self::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Add ad import tasks for a listing.
	 *
	 * @since 2.1.4
	 * @param int $lp_listing_id ListingPro listing ID.
	 * @param int $gd_post_id GeoDirectory post ID.
	 * @return void
	 */
	private function add_ads_import_tasks( $lp_listing_id, $gd_post_id ) {
		global $wpdb;

		if ( ! class_exists( 'Adv_Ad' ) || ! $this->campaigns_table_exists() ) {
			return;
		}

		$campaigns_table = $wpdb->prefix . 'listing_campaigns';

		// Import zones (once).
		static $zones_mapping = null;
		if ( null === $zones_mapping ) {
			$zones_mapping = $this->import_ad_zones();
		}

		if ( empty( $zones_mapping ) ) {
			return;
		}

		// Get lp-ads posts for this listing.
		$theme_key = $this->get_theme_key();
		$meta_key  = sprintf( self::META_OPTIONS_PATTERN, $theme_key );

		$search_pattern = sprintf(
			's:11:"ads_listing";s:%d:"%d"',
			strlen( (string) $lp_listing_id ),
			$lp_listing_id
		);

		$lp_ads_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID 
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND pm.meta_key = %s
				AND pm.meta_value LIKE %s",
				self::POST_TYPE_ADS,
				$meta_key,
				'%' . $wpdb->esc_like( $search_pattern ) . '%'
			)
		);

		if ( empty( $lp_ads_posts ) ) {
			return;
		}

		$ad_post_ids = wp_list_pluck( $lp_ads_posts, 'ID' );

		// Get all campaigns for these ad posts in one query.
		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$campaigns_table} 
				WHERE post_id IN (" . implode( ',', array_fill( 0, count( $ad_post_ids ), '%d' ) ) . ')
				ORDER BY main_id ASC',
				$ad_post_ids
			)
		);

		if ( empty( $campaigns ) ) {
			return;
		}

		// Build campaigns with zones data.
		$campaigns_with_zones = array();
		foreach ( $campaigns as $campaign ) {
			// Get ad zones from lp-ads post meta.
			$lp_ad_meta = $this->get_post_meta( $campaign->post_id );
			$lp_options = ! empty( $lp_ad_meta[ $meta_key ] ) ? maybe_unserialize( $lp_ad_meta[ $meta_key ] ) : array();
			$ad_zones   = isset( $lp_options[ self::META_AD_TYPE ] ) && is_array( $lp_options[ self::META_AD_TYPE ] ) ? $lp_options[ self::META_AD_TYPE ] : array();

			// Add zones to campaign object.
			$campaign->ad_zones     = ! empty( $ad_zones ) ? implode( ',', $ad_zones ) : '';
			$campaigns_with_zones[] = $campaign;
		}

		// Batch and queue campaigns.
		$batched_campaigns = array_chunk( $campaigns_with_zones, self::BATCH_SIZE_IMPORT );
		$import_tasks      = array();

		foreach ( $batched_campaigns as $campaign_batch ) {
			$import_tasks[] = array(
				'action'        => self::ACTION_IMPORT_ADS,
				'campaigns'     => $campaign_batch,
				'zones_mapping' => $zones_mapping,
				'gd_listing_id' => $gd_post_id,
				'lp_listing_id' => $lp_listing_id,
			);
		}

		$this->background_process->add_import_tasks(
			$import_tasks
		);
	}

	/**
	 * Import a batch of ads/campaigns.
	 *
	 * @since 2.1.4
	 * @param array $task Import task.
	 * @return bool Result of the import operation.
	 */
	public function task_import_ads( $task ) {
		$campaigns     = isset( $task['campaigns'] ) && is_array( $task['campaigns'] ) ? $task['campaigns'] : array();
		$zones_mapping = isset( $task['zones_mapping'] ) && is_array( $task['zones_mapping'] ) ? $task['zones_mapping'] : array();
		$gd_listing_id = isset( $task['gd_listing_id'] ) ? absint( $task['gd_listing_id'] ) : 0;
		$lp_listing_id = isset( $task['lp_listing_id'] ) ? absint( $task['lp_listing_id'] ) : 0;

		if ( empty( $campaigns ) || ! $gd_listing_id || ! $lp_listing_id ) {
			return false;
		}

		if ( $this->is_test_mode() ) {
			return false;
		}

		$imported = $skipped = $failed = 0;

		foreach ( $campaigns as $campaign ) {
			$result = $this->import_single_ad( $campaign, $zones_mapping, $gd_listing_id, $lp_listing_id );

			if ( self::IMPORT_STATUS_SUCCESS === $result ) {
				++$imported;
			} elseif ( self::IMPORT_STATUS_SKIPPED === $result ) {
				++$skipped;
			} else {
				++$failed;
			}
		}

		if ( $imported > 0 || $skipped > 0 || $failed > 0 ) {
			$this->log(
				sprintf(
					__( 'Ad batch: %1$d imported, %2$d skipped, %3$d failed', 'geodir-converter' ),
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
	 * Import a single ad/campaign.
	 *
	 * @since 2.1.4
	 * @param object $campaign Campaign data.
	 * @param array  $zones_mapping Zones mapping.
	 * @param int    $gd_listing_id GeoDirectory listing ID.
	 * @param int    $lp_listing_id ListingPro listing ID.
	 * @return string Import status.
	 */
	private function import_single_ad( $campaign, $zones_mapping, $gd_listing_id, $lp_listing_id ) {
		if ( ! $gd_listing_id || ! $lp_listing_id ) {
			return self::IMPORT_STATUS_SKIPPED;
		}

		// Get ad zones from the campaign data.
		$ad_zones = ! empty( $campaign->ad_zones ) ? explode( ',', $campaign->ad_zones ) : array();

		// If no zones specified, default to all zones.
		if ( empty( $ad_zones ) ) {
			$ad_zones = array_keys( $zones_mapping );
		}

		// Import ad for each zone.
		$imported = 0;
		foreach ( $ad_zones as $zone_type ) {
			$zone_type = trim( $zone_type );
			$zone_id   = isset( $zones_mapping[ $zone_type ] ) ? absint( $zones_mapping[ $zone_type ] ) : 0;

			if ( ! $zone_id ) {
				continue;
			}

			$result = $this->import_single_zone_ad( $campaign, $zone_id, $zone_type, $gd_listing_id );

			if ( self::IMPORT_STATUS_SUCCESS === $result || self::IMPORT_STATUS_UPDATED === $result ) {
				++$imported;
			}
		}

		return $imported > 0 ? self::IMPORT_STATUS_SUCCESS : self::IMPORT_STATUS_SKIPPED;
	}

	/**
	 * Import a single ad for a specific zone.
	 *
	 * @since 2.1.4
	 * @param object $campaign Campaign data.
	 * @param int    $zone_id Zone ID.
	 * @param string $zone_type Zone type identifier.
	 * @param int    $gd_listing_id GeoDirectory listing ID.
	 * @return string Import status.
	 */
	private function import_single_zone_ad( $campaign, $zone_id, $zone_type, $gd_listing_id ) {
		$ad_id     = $this->get_gd_post_id( $campaign->main_id . '-' . $zone_type, 'listingpro_campaign_zone_id' );
		$is_update = ! empty( $ad_id );
		$status    = isset( $this->ad_status_map[ strtolower( $campaign->status ) ] ) ? $this->ad_status_map[ strtolower( $campaign->status ) ] : 'pending';

		$ad_data = array(
			'post_type'    => self::GD_POST_TYPE_AD,
			'post_title'   => get_the_title( $gd_listing_id ),
			'post_status'  => $status,
			'post_author'  => ! empty( $campaign->user_id ) ? absint( $campaign->user_id ) : get_current_user_id(),
			'post_content' => '',
		);

		if ( $is_update ) {
			$ad_data['ID'] = $ad_id;
			$ad_id         = wp_update_post( $ad_data, true );
		} else {
			$ad_id = wp_insert_post( $ad_data, true );
		}

		if ( is_wp_error( $ad_id ) || ! $ad_id ) {
			return self::IMPORT_STATUS_FAILED;
		}

		update_post_meta( $ad_id, '_adv_ad_type', 'listing' );
		update_post_meta( $ad_id, '_adv_ad_zone', $zone_id );
		update_post_meta( $ad_id, '_adv_ad_listing', $gd_listing_id );
		update_post_meta( $ad_id, '_adv_ad_listing_content', 'featured_image' );
		update_post_meta( $ad_id, '_adv_ad_description', '' );
		update_post_meta( $ad_id, '_adv_ad_target_url', get_permalink( $gd_listing_id ) );
		update_post_meta( $ad_id, '_adv_ad_new_tab', '1' );
		update_post_meta( $ad_id, 'listingpro_campaign_zone_id', $campaign->main_id . '-' . $zone_type );
		update_post_meta( $ad_id, 'listingpro_campaign_id', $campaign->main_id );

		if ( ! empty( $campaign->ad_date ) ) {
			update_post_meta( $ad_id, '_adv_ad_date_paid', date( 'Y-m-d H:i:s', strtotime( $campaign->ad_date ) ) );
		}

		if ( class_exists( 'WPInv_Plugin' ) ) {
			$this->import_ad_campaign_invoice( $campaign, $ad_id, $zone_type );
		}

		return $is_update ? self::IMPORT_STATUS_UPDATED : self::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Import invoice for an ad campaign.
	 *
	 * @since 2.1.4
	 * @param object $campaign Campaign data.
	 * @param int    $ad_id GetPaid ad ID.
	 * @param string $zone_type Zone type identifier.
	 * @return void
	 */
	private function import_ad_campaign_invoice( $campaign, $ad_id, $zone_type ) {
		$invoice_id = $this->get_gd_post_id( $campaign->main_id . '-' . $zone_type, 'listingpro_campaign_zone_invoice_id' );
		$is_update  = ! empty( $invoice_id );
		$status     = isset( $this->invoice_status_map[ strtolower( $campaign->status ) ] ) ? $this->invoice_status_map[ strtolower( $campaign->status ) ] : 'wpi-pending';

		if ( $is_update ) {
			$wpi_invoice = new WPInv_Invoice( $invoice_id );
		} else {
			$wpi_invoice = new WPInv_Invoice();
		}

		$taxes = array();
		if ( ! empty( $campaign->tax ) && (float) $campaign->tax > 0 ) {
			$taxes[ __( 'Tax', 'geodir-converter' ) ] = array( 'initial_tax' => (float) $campaign->tax );
		}

		$wpi_invoice->set_props(
			array(
				'post_type'      => self::GD_POST_TYPE_INVOICE,
				'post_title'     => ! empty( $campaign->transaction_id ) ? $campaign->transaction_id : 'LP-AD-' . $campaign->main_id . '-' . $zone_type,
				'post_name'      => 'inv-lp-ad-' . $campaign->main_id . '-' . $zone_type,
				'description'    => sprintf( __( 'Ad campaign for %s zone', 'geodir-converter' ), $zone_type ),
				'status'         => $status,
				'created_via'    => self::CREATED_VIA,
				'date_created'   => ! empty( $campaign->ad_date ) ? date( 'Y-m-d H:i:s', strtotime( $campaign->ad_date ) ) : current_time( 'mysql' ),
				'gateway'        => ! empty( $campaign->payment_method ) ? strtolower( $campaign->payment_method ) : 'manual',
				'transaction_id' => ! empty( $campaign->transaction_id ) ? $campaign->transaction_id : '',
				'taxes'          => $taxes,
				'user_id'        => ! empty( $campaign->user_id ) ? absint( $campaign->user_id ) : 0,
				'currency'       => ! empty( $campaign->currency ) ? $campaign->currency : 'USD',
			)
		);

		// Set the price if available.
		if ( ! empty( $campaign->price ) ) {
			$wpi_invoice->set_subtotal( floatval( $campaign->price ) );
			$wpi_invoice->set_total( floatval( $campaign->price ) );
		}

		$invoice_id = $wpi_invoice->save();

		if ( is_wp_error( $invoice_id ) || ! $invoice_id ) {
			return;
		}

		update_post_meta( $ad_id, '_adv_ad_invoicing_invoice_id', $invoice_id );
		update_post_meta( $invoice_id, 'listingpro_campaign_zone_invoice_id', $campaign->main_id . '-' . $zone_type );
		update_post_meta( $invoice_id, 'listingpro_campaign_id', $campaign->main_id );
	}

	/**
	 * Convert ListingPro price status key to GeoDirectory display value.
	 *
	 * @since 2.1.4
	 * @param string $price_status_key ListingPro price status key.
	 * @return string GeoDirectory price status value.
	 */
	private function convert_price_status( $price_status_key ) {
		return isset( $this->price_status_map[ $price_status_key ] ) ? $this->price_status_map[ $price_status_key ] : '';
	}

	/**
	 * Convert time from 12-hour format to 24-hour format.
	 *
	 * @since 2.1.4
	 * @param string $time Time string in 12-hour format (e.g., "12:00 AM", "3:30 PM").
	 * @return string Time in 24-hour format (e.g., "00:00", "15:30").
	 */
	private function convert_time_to_24hr( $time ) {
		if ( empty( $time ) ) {
			return '00:00';
		}

		$time      = trim( $time );
		$timestamp = strtotime( $time );

		if ( false === $timestamp ) {
			return '00:00';
		}

		return date( 'H:i', $timestamp );
	}

	/**
	 * Parse ListingPro business hours to GeoDirectory format.
	 *
	 * @since 2.1.4
	 * @param array $hours ListingPro business hours array.
	 * @return string GeoDirectory formatted business hours.
	 */
	private function parse_business_hours( $hours ) {
		if ( empty( $hours ) || ! is_array( $hours ) ) {
			return '';
		}

		$day_map = array(
			'Monday'    => 'Mo',
			'Tuesday'   => 'Tu',
			'Wednesday' => 'We',
			'Thursday'  => 'Th',
			'Friday'    => 'Fr',
			'Saturday'  => 'Sa',
			'Sunday'    => 'Su',
		);

		$new_parts = array();

		foreach ( $hours as $day => $schedule ) {
			if ( ! isset( $day_map[ $day ] ) ) {
				continue;
			}

			if ( empty( $schedule['open'] ) || empty( $schedule['close'] ) ) {
				continue;
			}

			if ( 'closed' === strtolower( $schedule['open'] ) || 'closed' === strtolower( $schedule['close'] ) ) {
				continue;
			}

			$day_abbr = $day_map[ $day ];
			$open     = $schedule['open'];
			$close    = $schedule['close'];

			if ( strpos( $open, ':' ) === false ) {
				$open = date( 'H:i', strtotime( $open ) );
			}
			if ( strpos( $close, ':' ) === false ) {
				$close = date( 'H:i', strtotime( $close ) );
			}

			$new_parts[] = $day_abbr . ' ' . $open . '-' . $close;
		}

		if ( empty( $new_parts ) ) {
			return '';
		}

		$offset  = get_option( 'gmt_offset', 0 );
		$result  = '["' . implode( '","', $new_parts ) . '"]';
		$result .= ',["UTC":"' . $offset . '"]';

		return $result;
	}

	/**
	 * Get post images.
	 *
	 * @param array $post_meta Post meta data.
	 * @return string Formatted gallery images string for GeoDirectory.
	 */
	private function get_post_images( $post_meta ) {
		if ( empty( $post_meta['gallery_image_ids'] ) ) {
			return '';
		}

		$image_ids = maybe_unserialize( $post_meta['gallery_image_ids'] );
		if ( ! is_array( $image_ids ) ) {
			$image_ids = explode( ',', $image_ids );
		}

		if ( empty( $image_ids ) ) {
			return '';
		}

		$image_ids = array_filter( array_map( 'intval', $image_ids ) );

		if ( empty( $image_ids ) ) {
			return '';
		}

		$images        = array();
		$invalid_count = 0;

		foreach ( $image_ids as $index => $attachment_id ) {
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				++$invalid_count;
				continue;
			}

			$images[] = array(
				'id'      => $attachment_id,
				'caption' => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'weight'  => $index + 1,
			);
		}

		if ( ! empty( $images ) ) {
			$this->log( sprintf( __( 'Processing %d gallery images', 'geodir-converter' ), count( $images ) ), 'info' );
		}

		if ( $invalid_count > 0 ) {
			$this->log( sprintf( __( 'Skipped %d invalid/non-image attachments', 'geodir-converter' ), $invalid_count ), 'warning' );
		}

		return $this->format_images_data( $images );
	}

	/**
	 * Process form fields.
	 *
	 * @param WP_Post $post Post object.
	 * @param array   $post_meta Post meta data.
	 * @param string  $post_type Post type.
	 * @return array Processed fields.
	 */
	private function process_form_fields( $post, $post_meta, $post_type ) {
		$fields = array();

		$theme_key         = $this->get_theme_key();
		$lp_options        = ! empty( $post_meta[ sprintf( self::META_OPTIONS_PATTERN, $theme_key ) ] ) ? maybe_unserialize( $post_meta[ sprintf( self::META_OPTIONS_PATTERN, $theme_key ) ] ) : array();
		$lp_options_fields = ! empty( $post_meta[ sprintf( self::META_FIELDS_PATTERN, $theme_key ) ] ) ? maybe_unserialize( $post_meta[ sprintf( self::META_FIELDS_PATTERN, $theme_key ) ] ) : array();

		if ( ! is_array( $lp_options ) ) {
			$lp_options = array();
		}

		if ( ! is_array( $lp_options_fields ) ) {
			$lp_options_fields = array();
		}

		$business_hours_data = ! empty( $post_meta['business_hours'] ) ? maybe_unserialize( $post_meta['business_hours'] ) : ( ! empty( $lp_options['business_hours'] ) ? $lp_options['business_hours'] : array() );

		if ( ! empty( $business_hours_data ) && is_array( $business_hours_data ) ) {
			$fields['business_hours'] = $this->parse_business_hours( $business_hours_data );
		}

		if ( ! empty( $post_meta['video'] ) ) {
			$fields['video'] = esc_url( $post_meta['video'] );
		}

		$price_value = ! empty( $post_meta['list_price'] ) ? $post_meta['list_price'] : ( ! empty( $lp_options['list_price'] ) ? $lp_options['list_price'] : '' );
		if ( $price_value ) {
			$price_value     = preg_replace( '/[^0-9.]/', '', $price_value );
			$fields['price'] = floatval( $price_value );
		}

		$price_from = ! empty( $post_meta['list_price'] ) ? $post_meta['list_price'] : ( ! empty( $lp_options['list_price'] ) ? $lp_options['list_price'] : '' );
		$price_to   = ! empty( $post_meta['list_price_to'] ) ? $post_meta['list_price_to'] : ( ! empty( $lp_options['list_price_to'] ) ? $lp_options['list_price_to'] : '' );

		if ( $price_from ) {
			$price_from = preg_replace( '/[^0-9.]/', '', $price_from );
		}

		if ( $price_to ) {
			$price_to = preg_replace( '/[^0-9.]/', '', $price_to );
		}

		if ( $price_from && $price_to ) {
			$fields['price_range'] = $price_from . ' - ' . $price_to;
		} elseif ( $price_from ) {
			$fields['price_range'] = $price_from;
		} elseif ( $price_to ) {
			$fields['price_range'] = $price_to;
		}

		$simple_fields = array(
			'phone'        => 'geodir_clean',
			'email'        => 'sanitize_email',
			'website'      => 'esc_url',
			'twitter'      => 'esc_url',
			'facebook'     => 'esc_url',
			'instagram'    => 'esc_url',
			'linkedin'     => 'esc_url',
			'pinterest'    => 'esc_url',
			'whatsapp'     => 'geodir_clean',
			'mappin'       => 'esc_url_raw',
			'tagline_text' => 'sanitize_text_field',
		);

		foreach ( $simple_fields as $field_key => $sanitize_func ) {
			$value = ! empty( $post_meta[ $field_key ] ) ? $post_meta[ $field_key ] : ( ! empty( $lp_options[ $field_key ] ) ? $lp_options[ $field_key ] : '' );
			if ( $value ) {
				$fields[ $field_key ] = $sanitize_func( $value );
			}
		}

		if ( ! empty( $lp_options['price_status'] ) ) {
			$price_status_value = $this->convert_price_status( $lp_options['price_status'] );
			if ( $price_status_value ) {
				$fields['price_status'] = $price_status_value;
			}
		}

		if ( ! empty( $lp_options['business_logo'] ) ) {
			$logo_url = esc_url( $lp_options['business_logo'] );
			if ( $logo_url ) {
				$fields['company_logo'] = $logo_url . '|||';
			}
		}

		$custom_fields_def = $this->get_custom_fields();

		foreach ( $custom_fields_def as $field_def ) {
			$field_key        = $field_def['field_key'];
			$field_type       = isset( $field_def['type'] ) ? $field_def['type'] : 'text';
			$lp_field_key     = str_replace( '_', '-', $field_key );
			$lp_field_id      = isset( $field_def['lp_field_id'] ) ? $field_def['lp_field_id'] : 0;
			$original_lp_name = $lp_field_id ? get_post_field( 'post_name', $lp_field_id ) : $lp_field_key;

			if ( isset( $fields[ $field_key ] ) ) {
				continue;
			}

			$possible_keys = array_unique( array( $field_key, $lp_field_key, $original_lp_name ) );
			$value         = null;

			foreach ( $possible_keys as $try_key ) {
				if ( isset( $post_meta[ $try_key ] ) ) {
					$value = $post_meta[ $try_key ];
					break;
				} elseif ( isset( $lp_options[ $try_key ] ) ) {
					$value = $lp_options[ $try_key ];
					break;
				} elseif ( isset( $lp_options_fields[ $try_key ] ) ) {
					$value = $lp_options_fields[ $try_key ];
					break;
				}
			}

			if ( null !== $value ) {
				if ( is_string( $value ) && is_serialized( $value ) ) {
					$value = maybe_unserialize( $value );
				}

				if ( 'multiselect' === $field_type && is_array( $value ) ) {
					$value = implode( ',', array_map( 'trim', $value ) );
				} elseif ( 'radio' === $field_type ) {
					if ( is_array( $value ) ) {
						$value = isset( $value[0] ) ? $value[0] : '';
					}
				} elseif ( 'checkbox' === $field_type ) {
					if ( is_string( $value ) ) {
						$value = ( '0' !== $value && ! empty( $value ) && 'No' !== $value ) ? 1 : 0;
					} else {
						$value = ! empty( $value ) ? 1 : 0;
					}
				}

				$fields[ $field_key ] = $value;
			}
		}

		return apply_filters( 'geodir_converter_listingpro_process_form_fields', $fields, $post, $post_meta, $post_type );
	}

	/**
	 * Counts the number of listings.
	 *
	 * @since 2.1.4
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
	 * Get featured image URL for a post.
	 *
	 * @since 2.1.4
	 * @param int $post_id The post ID.
	 * @return string The featured image URL or empty string.
	 */
	private function get_featured_image( $post_id ) {
		$image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
		return isset( $image[0] ) ? esc_url( $image[0] ) : '';
	}

	/**
	 * Get categories/tags/features for a post.
	 *
	 * @since 2.1.4
	 * @param int    $post_id The post ID.
	 * @param string $taxonomy The taxonomy to get terms from.
	 * @param string $return_type Return 'ids' or 'names'.
	 * @return array Array of term IDs or names.
	 */
	private function get_listings_terms( $post_id, $taxonomy = self::TAX_LISTING_CATEGORY, $return_type = 'ids' ) {
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

	public function listing_get_metabox_by_ID( $name, $postid ) {
		if ( $postid ) {
			$metabox = get_post_meta( $postid, 'lp_listingpro_options', true );

			return ! empty( $metabox ) && isset( $metabox[ $name ] ) ? $metabox[ $name ] : '';
		} else {
			return false;
		}
	}
}
