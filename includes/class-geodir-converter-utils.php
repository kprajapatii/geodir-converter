<?php
/**
 * Utility Class for Geodir Converter.
 *
 * @since      2.0.2
 * @package    GeoDir_Converter
 * @version    2.0.2
 */

namespace GeoDir_Converter;

use WP_Error;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class for handling various utility functions.
 *
 * @since 2.0.2
 */
class GeoDir_Converter_Utils {
	const OPEN_STREET_MAP_API = 'https://nominatim.openstreetmap.org/reverse';

	/**
	 * Get location data (city, state, zip, country) from latitude and longitude.
	 *
	 * Uses Nominatim (OpenStreetMap) reverse geocoding.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return WP_Error|array Location data with keys 'city', 'state', 'zip', 'country', or WP_Error on failure.
	 */
	public static function get_location_from_coords( $lat, $lng ) {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return new WP_Error( 'invalid_location', esc_html__( 'Invalid latitude or longitude', 'geodir-converter' ) );
		}

		// Check cache first.
		$cache_key = 'geodir_converter_location_' . md5( $lat . ',' . $lng );
		$location  = get_transient( $cache_key );

		if ( false !== $location ) {
			return $location;
		}

		$endpoint = self::OPEN_STREET_MAP_API;
		$args     = array(
			'headers' => array(
				'User-Agent' => sprintf( 'GeoDir_Converter/2.0.2 ( %s )', get_bloginfo( 'admin_email' ) ),
			),
			'timeout' => 10,
		);

		$url = add_query_arg(
			array(
				'lat'            => $lat,
				'lon'            => $lng,
				'format'         => 'json',
				'addressdetails' => 1,
			),
			$endpoint
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'invalid_location', esc_html__( 'Failed to retrieve location data', 'geodir-converter' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['address'] ) || empty( $data['address'] ) ) {
			return new WP_Error( 'invalid_location', esc_html__( 'Invalid location data', 'geodir-converter' ) );
		}

		$location = self::parse_location_data( $lat, $lng, $data );

		// Cache the location for 1 hour.
		set_transient( $cache_key, $location, 60 * 60 );

		return $location;
	}

	/**
	 * Parse address data from latitude and longitude.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @param array $location Location data.
	 * @return array Location data with keys ['latitude', 'longitude', 'address', 'city', 'state', 'zip', 'country'], or WP_Error on failure.
	 */
	private static function parse_location_data( $lat, $lng, $location ) {
		$fallback_countries = array( 'gb', 'bm', 'no', 'se', 'ro' );
		$address            = $location['address'];

		$city = '';
		if ( isset( $address['village'] ) ) {
			$city = $address['village'];
		} elseif ( isset( $address['town'] ) ) {
			$city = $address['town'];
		} elseif ( isset( $address['city'] ) ) {
			$city = $address['city'];
		} elseif ( isset( $address['county'] ) ) {
			$city = $address['county'];
		} elseif ( isset( $address['city_district'] ) ) {
			$city = $address['city_district'];
		} elseif ( isset( $address['state_district'] ) ) {
			$city = $address['state_district'];
		}

		// Bermuda, Norway, Sweden, Romania.
		if ( isset( $address['country_code'], $address['state'] ) && ! empty( $address['country_code'] ) && empty( $address['state'] ) && in_array( $address['country_code'], $fallback_countries ) ) {
			if ( isset( $address['county'] ) && ! empty( $address['county'] ) ) {
				$address['state'] = $address['county'];
			} elseif ( isset( $address['state_district'] ) && ! empty( $address['state_district'] ) ) {
				$address['state'] = $address['state_district'];
			}
		}

		$state = '';
		if ( isset( $address['province'] ) && ! empty( $address['province'] ) ) {
			$state = $address['province'];
		} elseif ( isset( $address['state'] ) && ! empty( $address['state'] ) ) {
			$state = $address['state'];
		} elseif ( isset( $address['region'] ) && ! empty( $address['region'] ) ) {
			$state = $address['region'];
		}

		if ( $state ) {
			$state = str_replace( ' (state)', '', $state );
		}

		$data = array(
			'latitude'  => $lat,
			'longitude' => $lng,
			'address'   => isset( $location['display_name'] ) ? $location['display_name'] : '',
			'city'      => $city,
			'state'     => $state,
			'region'    => $state,
			'zip'       => isset( $address['postcode'] ) ? $address['postcode'] : '',
			'country'   => isset( $address['country'] ) ? $address['country'] : '',
		);

		if ( 'New Zealand / Aotearoa' === $data['country'] ) {
			$data['country'] = 'New Zealand';
		}

		return $data;
	}

	/**
	 * Parse CSV file.
	 *
	 * @param string $file_path The path to the CSV file.
	 * @param array  $required_headers The required headers.
	 * @param string $delimiter CSV delimiter. Default ','.
	 * @return array|WP_Error An array of parsed rows or a WP_Error object on failure.
	 *
	 * @throws WP_Error|Exception If the CSV file is not found, not readable, has invalid headers, or contains no valid data rows or an error occurs while parsing the CSV file.
	 */
	public static function parse_csv( $file_path, $required_headers = array(), $delimiter = ',' ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'CSV file not found.', 'geodir-converter' ) );
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_not_readable', __( 'CSV file is not readable. Please check file permissions.', 'geodir-converter' ) );
		}

		// Validate delimiter.
		$delimiter = ! empty( $delimiter ) ? $delimiter : ',';
		if ( strlen( $delimiter ) > 1 ) {
			$delimiter = ',';
		}

		$data            = array();
		$line_number     = 0;
		$max_line_length = 0;

		try {
			if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
				// Get headers.
				$headers = fgetcsv( $handle, 0, $delimiter );
				++$line_number;

				if ( empty( $headers ) || ! is_array( $headers ) ) {
					return new WP_Error( 'invalid_headers', __( 'CSV file has invalid or missing headers.', 'geodir-converter' ) );
				}

				// Validate headers (no empty or duplicate headers).
				$headers = array_map( 'trim', $headers );
				if ( count( $headers ) !== count( array_filter( $headers ) ) ) {
					return new WP_Error( 'empty_headers', __( 'CSV headers contain empty values. Please ensure all columns have headers.', 'geodir-converter' ) );
				}

				if ( count( $headers ) !== count( array_unique( $headers ) ) ) {
					return new WP_Error( 'duplicate_headers', __( 'CSV headers contain duplicate values. Each column must have a unique header.', 'geodir-converter' ) );
				}

				// Remove spaces and convert to lowercase.
				$headers = array_map(
					function ( $header ) {
						return trim( str_replace( ' ', '', strtolower( $header ) ) );
					},
					$headers
				);

				// Required headers check.
				if ( ! empty( $required_headers ) ) {
					$missing_headers = array_diff( $required_headers, array_map( 'strtolower', $headers ) );

					if ( ! empty( $missing_headers ) ) {
						return new WP_Error(
							'missing_headers',
							sprintf(
								__( 'CSV is missing required headers: %s', 'geodir-converter' ),
								implode( ', ', $missing_headers )
							)
						);
					}
				}

				// Process rows.
				while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
					++$line_number;

					// Skip empty rows.
					if ( count( array_filter( $row ) ) === 0 ) {
						continue;
					}

					// Check for row length mismatch.
					if ( count( $row ) !== count( $headers ) ) {
						return new WP_Error(
							'row_length_mismatch',
							sprintf(
								__( 'Row %1$d has %2$d columns while the header has %3$d columns. Please ensure all rows have the correct number of columns.', 'geodir-converter' ),
								$line_number,
								count( $row ),
								count( $headers )
							),
						);
					}

					// Track max line length for memory management.
					$line_length     = strlen( implode( '', $row ) );
					$max_line_length = max( $max_line_length, $line_length );

					// Memory limit check.
					if ( $max_line_length > 1048576 ) { // 1MB per line limit
						return new WP_Error( 'excessive_row_length', __( 'CSV contains excessively long rows. Please check your data format.', 'geodir-converter' ) );
					}

					// Sanitize and validate row data.
					$sanitized_row = array();
					foreach ( array_combine( $headers, $row ) as $key => $value ) {
						// Use sanitize_textarea_field to preserve newlines for multiselect fields.
						$value = sanitize_textarea_field( $value );

						// Field-specific validation could be added here.
						$sanitized_row[ $key ] = $value;
					}

					$data[] = $sanitized_row;

					// Limit number of rows for memory protection.
					if ( count( $data ) >= 10000 ) {
						break;
					}
				}

				fclose( $handle );
			} else {
				return new WP_Error( 'file_open_failed', __( 'Failed to open CSV file for reading.', 'geodir-converter' ) );
			}
		} catch ( Exception $e ) {
			// Re-throw with line number information if it's a parsing error.
			if ( $line_number > 0 && $e->getCode() === 422 ) {
				return new WP_Error(
					'parsing_error',
					sprintf(
						__( 'Error at line %1$d: %2$s', 'geodir-converter' ),
						$line_number,
						$e->getMessage()
					),
				);
			}
			throw $e;
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'no_data', __( 'CSV file contains no valid data rows.', 'geodir-converter' ) );
		}

		return $data;
	}
}
