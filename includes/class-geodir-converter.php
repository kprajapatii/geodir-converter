<?php
/**
 * Main Plugin class
 *
 * @since      2.0.2
 * @package    GeoDir_Converter
 * @version    2.0.2
 */

namespace GeoDir_Converter;

use GeoDir_Converter\Admin\GeoDir_Converter_Admin;
use GeoDir_Converter\Importers\GeoDir_Converter_PMD;
use GeoDir_Converter\Importers\GeoDir_Converter_Listify;
use GeoDir_Converter\Traits\GeoDir_Converter_Trait_Singleton;
use GeoDir_Converter\Importers\GeoDir_Converter_Business_Directory;
use GeoDir_Converter\Importers\GeoDir_Converter_Vantage;
use GeoDir_Converter\Importers\GeoDir_Converter_EDirectory;
use GeoDir_Converter\Importers\GeoDir_Converter_Directorist;
use GeoDir_Converter\Importers\GeoDir_Converter_ListingPro;
use GeoDir_Converter\Importers\GeoDir_Converter_HivePress;
use GeoDir_Converter\Importers\GeoDir_Converter_Directories_Pro;
use GeoDir_Converter\Importers\GeoDir_Converter_uListing;
use GeoDir_Converter\Importers\GeoDir_Converter_Connections;
use GeoDir_Converter\Importers\GeoDir_Converter_CSV;

defined( 'ABSPATH' ) || exit;

/**
 * Class GeoDir_Converter
 *
 * Handles the core functionality of the Geodir Converter plugin.
 */
final class GeoDir_Converter {
	use GeoDir_Converter_Trait_Singleton;

	/**
	 * Ajax handler.
	 *
	 * @var GeoDir_Converter_Ajax
	 */
	public $ajax;

	/**
	 * Admin page.
	 *
	 * @var GeoDir_Converter_Admin
	 */
	public $admin;

	/**
	 * GeoDir_Converter constructor.
	 */
	private function __construct() {
		$this->init_hooks();
		$this->load_importers();

		$this->ajax = GeoDir_Converter_Ajax::instance();

		if ( is_admin() ) {
			$this->admin = GeoDir_Converter_Admin::instance();
		}
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_importer' ) );

		// Skip invoice emails for imported invoices.
		add_filter( 'getpaid_skip_invoice_email', array( $this, 'skip_invoice_email' ), 10, 3 );

		if ( is_admin() ) {
			add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );
			add_filter( 'geodir_cpt_cf_save_data', array( $this, 'cpt_cf_save_data' ), 10, 2 );
		}
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function load_importers() {
		GeoDir_Converter_CSV::instance();
		GeoDir_Converter_Listify::instance();
		GeoDir_Converter_PMD::instance();
		GeoDir_Converter_Business_Directory::instance();
		GeoDir_Converter_Vantage::instance();
		GeoDir_Converter_EDirectory::instance();
		GeoDir_Converter_Directorist::instance();
		GeoDir_Converter_ListingPro::instance();
		GeoDir_Converter_HivePress::instance();
		GeoDir_Converter_Directories_Pro::instance();
		GeoDir_Converter_uListing::instance();
		GeoDir_Converter_Connections::instance();
	}

	/**
	 * Define constant if not already set.
	 *
	 * @param  string      $name  Name of the constant to define.
	 * @param  string|bool $value Value of the constant to define.
	 */
	private function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * Get script version for cache busting.
	 *
	 * @return string
	 */
	public function get_script_version() {
		return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? time() : GEODIR_CONVERTER_VERSION;
	}

	/**
	 * Get script suffix based on debug mode.
	 *
	 * @return string
	 */
	public function get_script_suffix() {
		return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
	}

	/**
	 * Load the plugin text domain for translation.
	 */
	public function load_plugin_textdomain() {
		// Determines the current locale.
		$locale = determine_locale();

		unload_textdomain( 'geodir-converter', true );
		load_textdomain( 'geodir-converter', WP_LANG_DIR . '/geodir-converter/geodir-converter-' . $locale . '.mo' );
		load_plugin_textdomain( 'geodir-converter', false, basename( dirname( GEODIR_CONVERTER_PLUGIN_FILE ) ) . '/languages/' );
	}

	/**
	 * Adds a link to the plugin's admin page on the plugins overview screen
	 *
	 * @param array  $links Array of plugin action links.
	 * @param string $file The plugin basename.
	 * @return array Modified array of plugin action links.
	 */
	public function plugin_action_links( $links, $file ) {
		if ( GEODIR_CONVERTER_PLUGIN_BASENAME === $file ) {
			$convert_link = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( $this->import_page_url() ),
				esc_attr__( 'Convert', 'geodir-converter' ),
				esc_html__( 'Convert', 'geodir-converter' )
			);

			$links['convert'] = $convert_link;
		}

		return $links;
	}

	/**
	 * Returns a url to the plugin's admin page
	 *
	 * @return string Admin page URL.
	 */
	public function import_page_url() {
		return add_query_arg(
			array(
				'page' => 'geodir-converter',
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Maybe redirect the user to the plugin's admin page
	 *
	 * @return void
	 */
	public function maybe_redirect_to_importer() {
		if ( '1' === get_transient( '_geodir_converter_installed' ) ) {
			delete_transient( '_geodir_converter_installed' );

			// Bail if activating from network, or bulk.
			if ( is_network_admin() || isset( $_GET['activate-multi'] ) ) {
				return;
			}

			// Redirect to the converter page.
			wp_redirect( esc_url( $this->import_page_url() ) );
			exit;
		}
	}

	/**
	 * Retrieves a list of all registered importers
	 *
	 * @return array List of registered importers.
	 */
	public function get_importers() {
		return apply_filters( 'geodir_converter_importers', array() );
	}

	/**
	 * Filter to skip sending completed invoice emails for invoices created by GeoDir Converter.
	 *
	 * @param bool   $skip     Whether to skip sending the email.
	 * @param string $type     The email type.
	 * @param object $invoice  The invoice object.
	 * @return bool
	 */
	public function skip_invoice_email( $skip, $type, $invoice ) {
		if ( $invoice->get_created_via() === 'geodir-converter' ) {
			return true;
		}

		return $skip;
	}

	/**
	 * Filter to save custom fields data for Directorist.
	 *
	 * @param array  $cf_data The custom fields data.
	 * @param object $field The custom field object.
	 * @return array The filtered custom fields data.
	 */
	public function cpt_cf_save_data( $cf_data, $field ) {
		if ( ! isset( $cf_data['tab_parent'] ) && ! isset( $cf_data['tab_level'] ) && isset( $field->tab_parent ) && isset( $field->tab_level ) ) {
			if ( defined( 'GEODIR_CONVERT_CF_DIRECTORIST' ) ) {
				$cf_data['db_data']['tab_parent'] = $field->tab_parent;
				$cf_data['db_format'][]           = '%d';

				$cf_data['db_data']['tab_level'] = $field->tab_level;
				$cf_data['db_format'][]          = '%d';
			}
		}

		return $cf_data;
	}
}
