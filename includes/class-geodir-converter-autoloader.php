<?php
/**
 * Autoloader for Geodir Converter plugin
 *
 * @since      2.0.2
 * @package    GeoDir_Converter
 * @version    2.0.2
 */

namespace GeoDir_Converter;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader class for Geodir Converter plugin.
 *
 * @since 2.0.2
 */
class Geodir_Converter_Autoloader {

	/**
	 * Namespace prefix for autoloading.
	 *
	 * @var string
	 */
	private $namespace_prefix;

	/**
	 * Base directory for autoloading.
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * PSR-4 prefix to directory mappings.
	 *
	 * @var array
	 */
	private $psr4_mappings = array();

	/**
	 * Constructor.
	 *
	 * @since 2.0.2
	 *
	 * @param string $namespace_prefix The namespace prefix for autoloading.
	 * @param string $base_dir         The base directory for autoloading.
	 */
	public function __construct( string $namespace_prefix, string $base_dir ) {
		$this->namespace_prefix = $namespace_prefix;
		$this->base_dir         = $base_dir;

		$this->add_psr4_mapping( $namespace_prefix, $base_dir . 'includes/' );
	}

	/**
	 * Register the autoloader.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function register() {
		spl_autoload_register( array( $this, 'autoload' ) );
	}

	/**
	 * Add a PSR-4 mapping.
	 *
	 * @since 2.0.2
	 *
	 * @param string $prefix   The namespace prefix.
	 * @param string $base_dir The base directory for the namespace prefix.
	 * @return void
	 */
	public function add_psr4_mapping( string $prefix, string $base_dir ) {
		$prefix   = trim( $prefix, '\\' ) . '\\';
		$base_dir = rtrim( $base_dir, DIRECTORY_SEPARATOR ) . '/';

		$this->psr4_mappings[ $prefix ] = $base_dir;
	}

	/**
	 * Autoload function for class loading.
	 *
	 * @since 2.0.2
	 *
	 * @param string $class Full class name.
	 * @return void
	 */
	public function autoload( string $class ) {
		$prefix = $this->namespace_prefix . '\\';

		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$mapped_file    = $this->load_mapped_file( $relative_class );

		if ( $mapped_file ) {
			require_once $mapped_file;
		}
	}

	/**
	 * Load the mapped file for a namespace prefix and relative class.
	 *
	 * @since 2.0.2
	 *
	 * @param string $relative_class The relative class name.
	 * @return string|false The mapped file name on success, or false on failure.
	 */
	private function load_mapped_file( $relative_class ) {
		foreach ( $this->psr4_mappings as $prefix => $directory ) {
			if ( 0 === strpos( $relative_class, $prefix ) ) {
				$file_path = $this->build_file_path( $relative_class, $prefix, $directory );

				if ( $this->require_file( $file_path ) ) {
					return $file_path;
				}
			}
		}

		// Try the default mapping if no prefix match was found.
		$file_path = $this->build_file_path( $relative_class, '', $this->base_dir . 'includes/' );

		return $this->require_file( $file_path ) ? $file_path : false;
	}

	/**
	 * Build the file path for a given class.
	 *
	 * @since 2.0.2
	 *
	 * @param string $relative_class The relative class name.
	 * @param string $prefix         The namespace prefix.
	 * @param string $base_dir       The base directory for the files.
	 * @return string The constructed file path.
	 */
	private function build_file_path( $relative_class, $prefix, $base_dir ) {
		// Remove the prefix and convert to a relative path.
		$relative_path = substr( strtolower( $relative_class ), strlen( $prefix ) );
		$relative_path = str_replace( array( '\\', '_' ), array( '/', '-' ), $relative_path );

		// Add `class-` prefix to the file name.
		$path_parts                             = explode( '/', $relative_path );
		$path_parts[ count( $path_parts ) - 1 ] = 'class-' . $path_parts[ count( $path_parts ) - 1 ];

		return $base_dir . implode( '/', $path_parts ) . '.php';
	}


	/**
	 * If a file exists, require it from the file system.
	 *
	 * @since 2.0.2
	 *
	 * @param string $file The file to require.
	 * @return bool True if the file exists, false if not.
	 */
	private function require_file( string $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;
			return true;
		}
		return false;
	}
}
