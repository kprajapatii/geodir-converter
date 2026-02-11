<?php
/**
 * GeoDirectory Directory Converter
 *
 * @package           GeoDir_Converter
 * @author            AyeCode Ltd
 * @copyright         2019 AyeCode Ltd
 * @license           GPLv3
 *
 * @wordpress-plugin
 * Plugin Name:       GeoDirectory Directory Converter
 * Plugin URI:        https://wpgeodirectory.com/downloads/directory-converter/
 * Description:       Convert directories like phpMyDirectory, Listify, vantage, Directorist and Business Directory Plugin to GeoDirectory.
 * Version:           2.1.3
 * Requires at least: 5.0
 * Requires PHP:      5.6
 * Author:            AyeCode Ltd
 * Author URI:        https://ayecode.io
 * License:           GPLv3
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins:  geodirectory
 * Text Domain:       geodir-converter
 * Domain Path:       /languages
 * Update URL:        https://github.com/AyeCode/geodir-converter/
 * Update ID:         768885
 */

namespace GeoDir_Converter;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants.
define( 'GEODIR_CONVERTER_VERSION', '2.1.3' );
define( 'GEODIR_CONVERTER_MINIMUM_PHP_VERSION', '5.6' );
define( 'GEODIR_CONVERTER_MINIMUM_WP_VERSION', '5.0' );
define( 'GEODIR_CONVERTER_MINIMUM_GD_VERSION', '2.3.0' );
define( 'GEODIR_CONVERTER_PLUGIN_FILE', __FILE__ );
define( 'GEODIR_CONVERTER_NAMESPACE', __NAMESPACE__ );
define( 'GEODIR_CONVERTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GEODIR_CONVERTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GEODIR_CONVERTER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload classes.
 */
require_once GEODIR_CONVERTER_PLUGIN_DIR . 'includes/class-geodir-converter-autoloader.php';
$autoloader = new Geodir_Converter_Autoloader( GEODIR_CONVERTER_NAMESPACE, GEODIR_CONVERTER_PLUGIN_DIR );
$autoloader->register();

/**
 * Display PHP version error.
 *
 * @return void
 */
function php_version_error() {
	$message = sprintf(
		/* translators: 1: Current PHP version 2: Required PHP version */
		esc_html__( 'GeoDirectory Directory Converter requires PHP version %2$s or higher. You are running version %1$s. Please upgrade your PHP version.', 'geodir-converter' ),
		PHP_VERSION,
		GEODIR_CONVERTER_MINIMUM_PHP_VERSION
	);

	printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
}

/**
 * Display WordPress version error.
 *
 * @return void
 */
function wordpress_version_error() {
	$message = sprintf(
		/* translators: 1: Current WordPress version 2: Required WordPress version */
		esc_html__( 'GeoDirectory Directory Converter requires WordPress version %2$s or higher. You are running version %1$s. Please upgrade WordPress.', 'geodir-converter' ),
		get_bloginfo( 'version' ),
		GEODIR_CONVERTER_MINIMUM_WP_VERSION
	);

	printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
}

/**
 * Check if GeoDirectory is installed and active, and display appropriate notices.
 *
 * @since 2.0.2
 * @return void
 */
function check_geodirectory() {
	// If this is not an admin page or GD is activated, abort early.
	if ( ! is_admin() || did_action( 'geodirectory_loaded' ) ) {
		return;
	}

	include_once ABSPATH . 'wp-admin/includes/plugin.php';

	$class    = 'notice notice-warning is-dismissible';
	$action   = 'install-plugin';
	$slug     = 'geodirectory';
	$basename = 'geodirectory/geodirectory.php';

	if ( ! file_exists( WP_PLUGIN_DIR . '/' . $basename ) ) {
		// GeoDirectory is not installed.
		$install_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'plugin' => $slug,
				),
				admin_url( 'update.php' )
			),
			$action . '_' . $slug
		);

		$message = sprintf(
			/* translators: 1: Plugin name, 2: GeoDirectory link opening tag, 3: GeoDirectory link closing tag, 4: Installation link opening tag, 5: Installation link closing tag, 6: Required GeoDirectory version */
			esc_html__( '%1$s requires the %2$sGeoDirectory%3$s plugin (version %6$s or higher) to be installed and active. %4$sClick here to install it.%5$s', 'geodir-converter' ),
			'<strong>GeoDirectory Converter</strong>',
			'<a href="https://wpgeodirectory.com" target="_blank" title="GeoDirectory">',
			'</a>',
			"<a href='$install_url' title='Install GeoDirectory'>",
			'</a>',
			GEODIR_CONVERTER_MINIMUM_GD_VERSION
		);
	} elseif ( is_plugin_inactive( $basename ) ) {
		// GeoDirectory is installed but not active.
		$activation_url = wp_nonce_url(
			admin_url( "plugins.php?action=activate&plugin=$basename" ),
			"activate-plugin_$basename"
		);

		$message = sprintf(
			/* translators: 1: Plugin name, 2: GeoDirectory link opening tag, 3: GeoDirectory link closing tag, 4: Activation link opening tag, 5: Activation link closing tag, 6: Required GeoDirectory version */
			esc_html__( '%1$s requires the %2$sGeoDirectory%3$s plugin (version %6$s or higher) to be installed and active. %4$sClick here to activate it.%5$s', 'geodir-converter' ),
			'<strong>GeoDirectory Converter</strong>',
			'<a href="https://wpgeodirectory.com" target="_blank" title="GeoDirectory">',
			'</a>',
			"<a href='$activation_url' title='Activate GeoDirectory'>",
			'</a>',
			GEODIR_CONVERTER_MINIMUM_GD_VERSION
		);
	} elseif ( version_compare( GEODIRECTORY_VERSION, GEODIR_CONVERTER_MINIMUM_GD_VERSION, '<' ) ) {
		// GeoDirectory is active but outdated.
		$message = sprintf(
			/* translators: 1: Plugin name, 2: Required GeoDirectory version, 3: Current GeoDirectory version */
			esc_html__( '%1$s requires GeoDirectory version %2$s or higher. You are running version %3$s. Please update GeoDirectory.', 'geodir-converter' ),
			'<strong>GeoDirectory Converter</strong>',
			GEODIR_CONVERTER_MINIMUM_GD_VERSION,
			GEODIRECTORY_VERSION
		);
	} else {
		// GeoDirectory is installed, active, and up to date.
		return;
	}

	printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), wp_kses_post( $message ) );
}

/**
 * Begins execution of the plugin.
 *
 * @since 2.0.2
 * @return void
 */
function initialize() {
	// Check PHP version.
	if ( version_compare( PHP_VERSION, GEODIR_CONVERTER_MINIMUM_PHP_VERSION, '<' ) ) {
		add_action( 'admin_notices', GEODIR_CONVERTER_NAMESPACE . '\php_version_error' );
		return;
	}

	// Check WordPress version.
	if ( version_compare( get_bloginfo( 'version' ), GEODIR_CONVERTER_MINIMUM_WP_VERSION, '<' ) ) {
		add_action( 'admin_notices', GEODIR_CONVERTER_NAMESPACE . '\wordpress_version_error' );
		return;
	}

	// Check GeoDirectory status.
	add_action( 'admin_notices', GEODIR_CONVERTER_NAMESPACE . '\check_geodirectory' );

	// Initialize the plugin.
	Geodir_Converter::instance();
}

add_action( 'geodirectory_loaded', GEODIR_CONVERTER_NAMESPACE . '\initialize' );

/**
 * The code that runs during plugin activation.
 *
 * @since 2.0.2
 */
function activate() {
	// Set a transient showing the plugin has been activated. Used to redirect users to the plugin page.
	set_transient( '_geodir_converter_installed', '1', MINUTE_IN_SECONDS );
}

register_activation_hook( __FILE__, GEODIR_CONVERTER_NAMESPACE . '\activate' );
