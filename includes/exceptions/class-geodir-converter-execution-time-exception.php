<?php
/**
 * Exception class for not enough execution time.
 *
 * @package GeoDir_Converter
 * @subpackage Exceptions
 * @since   1.0.0
 */

namespace GeoDir_Converter\Exceptions;

use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Exception thrown when the execution time limit is reached during import.
 *
 * @since 1.0.0
 */
class GeoDir_Converter_Execution_Time_Exception extends Exception {}
