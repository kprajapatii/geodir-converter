<?php
/**
 * Options Handler Class.
 *
 * @package GeoDir_Converter
 * @version 2.0.2
 * @since   2.0.2
 */

namespace GeoDir_Converter;

defined( 'ABSPATH' ) || exit;

/**
 * This class handles the options for GeoDir Converter.
 *
 * @package GeoDir_Converter
 * @version 2.0.2
 * @since   2.0.2
 */
class GeoDir_Converter_Options_Handler {
	/**
	 * Prefix for the options.
	 *
	 * @var string
	 */
	protected $prefix = '';

	/**
	 * Constructor to initialize the prefix.
	 *
	 * @since 2.0.2
	 *
	 * @param string $prefix The prefix for the options.
	 */
	public function __construct( $prefix ) {
		$this->prefix = $prefix;
	}

	/**
	 * Get the full option name with the prefix.
	 *
	 * @since 2.0.2
	 *
	 * @param string $option The option name.
	 * @return string The full option name with the prefix.
	 */
	public function get_option_name( $option ) {
		return $this->prefix . '_' . $option;
	}

	/**
	 * Retrieve an option value from the database.
	 *
	 * @since 2.0.2
	 *
	 * @param string $option  The option name.
	 * @param mixed  $default Optional. Default value if option does not exist. Default is false.
	 * @return mixed The option value.
	 */
	public function get_option( $option, $default = false ) {
		$option = $this->get_option_name( $option );
		return get_option( $option, $default );
	}

	/**
	 * Retrieve an option value from the database without cache.
	 *
	 * @since 2.0.2
	 *
	 * @param string $option  The option name.
	 * @param mixed  $default Optional. Default value if option does not exist. Default is false.
	 * @return mixed The option value without cache.
	 */
	public function get_option_no_cache( $option, $default = false ) {
		global $wpdb;

		$option_name = $this->get_option_name( $option );

		$old_error_reporting_level = $wpdb->suppress_errors();
		$query                     = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name );
		$row                       = $wpdb->get_row( $query );
		$wpdb->suppress_errors( $old_error_reporting_level );

		if ( is_object( $row ) ) {
			return maybe_unserialize( $row->option_value );
		} else {
			return $default;
		}
	}

	/**
	 * Update an option value in the database.
	 *
	 * @since 2.0.2
	 *
	 * @param string $option   The option name.
	 * @param mixed  $value    The option value.
	 * @param string $autoload Optional. Whether to load the option when WordPress starts up. Default is 'no'.
	 * @return bool True if the option value was updated, false otherwise.
	 */
	public function update_option( $option, $value, $autoload = 'no' ) {
		$option = $this->get_option_name( $option );
		return update_option( $option, $value, $autoload );
	}

	/**
	 * Delete an option from the database.
	 *
	 * @since 2.0.2
	 *
	 * @param string $option The option name.
	 * @return bool True if the option was successfully deleted, false otherwise.
	 */
	public function delete_option( $option ) {
		$option = $this->get_option_name( $option );
		return delete_option( $option );
	}
}
