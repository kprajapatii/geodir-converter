<?php
/**
 * Ajax Class for Geodir Converter.
 *
 * @since      2.0.2
 * @package    GeoDir_Converter
 * @version    2.0.2
 */

namespace GeoDir_Converter;

use finfo;
use WP_Error;
use GeoDir_Converter\Traits\GeoDir_Converter_Trait_Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * Main ajax class for handling AJAX requests.
 *
 * @since 1.0.0
 */
class GeoDir_Converter_Ajax {
	use GeoDir_Converter_Trait_Singleton;

	/**
	 * Name of the nonce used for security verification.
	 *
	 * @var string
	 */
	protected $nonce_name = 'geodir_converter_nonce';

	/**
	 * Prefix used for AJAX action names.
	 *
	 * @var string
	 */
	protected $action_prefix = 'geodir_converter_';

	/**
	 * List of AJAX actions along with their details.
	 *
	 * @var array
	 */
	protected $ajax_actions = array(
		'progress'             => array(
			'method' => 'GET',
		),
		'import'               => array(
			'method' => 'POST',
		),
		'abort'                => array(
			'method' => 'POST',
		),
		'upload'               => array(
			'method' => 'POST',
		),
		'csv_parse'            => array(
			'method' => 'POST',
		),
		'csv_get_fields'       => array(
			'method' => 'POST',
		),
		'csv_refresh_fields'   => array(
			'method' => 'POST',
		),
		'csv_get_mapping_step' => array(
			'method' => 'POST',
		),
		'csv_clear_file'       => array(
			'method' => 'POST',
		),
		'csv_save_template'    => array(
			'method' => 'POST',
		),
		'csv_load_template'    => array(
			'method' => 'POST',
		),
		'csv_delete_template'  => array(
			'method' => 'POST',
		),
	);

	/**
	 * Ajax constructor.
	 */
	public function __construct() {
		foreach ( $this->ajax_actions as $action => $details ) {
			$no_priv = isset( $details['no_priv'] ) ? $details['no_priv'] : false;
			$this->add_ajax_action( $action, $no_priv );
		}
	}

	/**
	 * Retrieves input data for processing AJAX requests.
	 *
	 * @param string $action The name of the AJAX action without the 'wp' prefix.
	 * @return array An array containing input data for the AJAX request.
	 */
	protected function get_request_input( $action ) {
		$method = isset( $this->ajax_actions[ $action ]['method'] ) ? $this->ajax_actions[ $action ]['method'] : '';

		switch ( $method ) {
			case 'GET':
				$input = $_GET;
				break;
			case 'POST':
				$input = $_POST;
				break;
			default:
				$input = $_REQUEST;
		}

		return $input;
	}

	/**
	 * Retrieve nonces for AJAX actions.
	 *
	 * @return array Nonces for AJAX actions.
	 */
	public function get_nonces() {
		$nonces = array();
		foreach ( $this->ajax_actions as $action_name => $details ) {
			$nonces[ $this->action_prefix . $action_name ] = wp_create_nonce( $this->action_prefix . $action_name );
		}

		return $nonces;
	}

	/**
	 * Add AJAX action hooks.
	 *
	 * @param string $action  AJAX action name.
	 * @param bool   $no_priv Whether the action is available for non-logged in users.
	 */
	public function add_ajax_action( $action, $no_priv = false ) {
		add_action( 'wp_ajax_' . $this->action_prefix . $action, array( $this, $action ) );

		if ( $no_priv ) {
			add_action( 'wp_ajax_nopriv_' . $this->action_prefix . $action, array( $this, $action ) );
		}
	}

	/**
	 * Check the validity of the nonce.
	 *
	 * @param string $action AJAX action name.
	 * @return bool True if the nonce is valid, otherwise false.
	 */
	protected function check_nonce( $action ) {
		if ( ! isset( $this->ajax_actions[ $action ] ) ) {
			return false;
		}

		$input = $this->get_request_input( $action );

		$nonce = isset( $input[ $this->nonce_name ] ) ? $input[ $this->nonce_name ] : '';

		return wp_verify_nonce( $nonce, $this->action_prefix . $action );
	}

	/**
	 * Verify the validity of the nonce.
	 *
	 * @param string $action AJAX action name.
	 */
	protected function verify_nonce( $action ) {
		if ( ! $this->check_nonce( $action ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Request does not pass security verification. Please refresh the page and try one more time.', 'geodir-converter' ),
				)
			);
		}
	}

	/**
	 * Get importer instance.
	 *
	 * @since 2.0.2
	 * @param string $importer_id The importer ID.
	 * @throws Exception If importer is not found.
	 * @return object
	 */
	private function get_importer( $importer_id ) {
		$importers = GeoDir_Converter::instance()->get_importers();

		if ( ! isset( $importers[ $importer_id ] ) ) {
			return new WP_Error( 'importer_not_found', __( 'Importer not found.', 'geodir-converter' ) );
		}

		return $importers[ $importer_id ];
	}

	/**
	 * Send JSON error response.
	 *
	 * @since 2.0.2
	 * @param string $message The error message.
	 * @return void
	 */
	private function send_json_error( $message ) {
		wp_send_json_error( array( 'message' => $message ) );
	}

	/**
	 * AJAX handler for starting the import process.
	 */
	public function import() {
		$this->verify_nonce( __FUNCTION__ );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( __( 'You do not have permission to perform this action.', 'geodir-converter' ) );
		}

		$importer_id = isset( $_POST['importerId'] ) ? wp_unslash( $_POST['importerId'] ) : '';
		$settings    = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$settings    = is_array( $settings ) ? $settings : json_decode( $settings, true );
		$files       = isset( $_FILES['files'] ) ? wp_unslash( $_FILES['files'] ) : array();

		$importer = $this->get_importer( $importer_id );

		if ( is_wp_error( $importer ) ) {
			$this->send_json_error( $importer->get_error_message() );
		}

		$result = $importer->import( $settings, $files );

		if ( is_wp_error( $result ) ) {
			$this->send_json_error( $result->get_error_message() );
		}

		$progress = $importer->get_progress();

		wp_send_json_success(
			array(
				'progress' => (int) $progress,
				'complete' => $progress >= 100,
			)
		);
	}

	/**
	 * AJAX handler for getting import progress.
	 */
	public function progress() {
		$this->verify_nonce( __FUNCTION__ );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( __( 'You do not have permission to perform this action.', 'geodir-converter' ) );
		}

		$importer_id = isset( $_GET['importerId'] ) ? sanitize_text_field( $_GET['importerId'] ) : '';
		$logs_shown  = isset( $_GET['logsShown'] ) ? absint( $_GET['logsShown'] ) : '';

		$importer = $this->get_importer( $importer_id );

		if ( is_wp_error( $importer ) ) {
			$this->send_json_error( $importer->get_error_message() );
		}

		$progress    = $importer->get_progress();
		$in_progress = $importer->background_process->is_in_progress();
		$logs        = $importer->get_logs( $logs_shown );

		// Build notice.
		if ( ! $in_progress ) {
			$logs[] = array(
				'message' => __( 'Import completed.', 'geodir-converter' ),
				'status'  => 'success',
			);
		}

		// Calculate new "logs_shown".
		$logs_shown += count( $logs );

		wp_send_json_success(
			array(
				'progress'   => $progress,
				'message'    => sprintf(
					__( 'Import progress: %d%%', 'geodir-converter' ),
					$progress
				),
				'logsShown'  => $logs_shown,
				'logs'       => $importer->logs_to_html( $logs ),
				'inProgress' => (bool) $in_progress,
			)
		);
	}

	/**
	 * AJAX handler for uploading CSV file.
	 */
	public function upload() {
		$this->verify_nonce( __FUNCTION__ );
		$importer_id = isset( $_POST['importerId'] ) ? sanitize_text_field( $_POST['importerId'] ) : '';

		// Check if file was uploaded.
		if ( ! isset( $_FILES['file'] ) ) {
			$this->send_json_error( __( 'No file was uploaded. Please select a file and try again.', 'geodir-converter' ) );
		}

		$file = $_FILES['file'];

		// Check for upload errors.
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			$upload_errors = array(
				UPLOAD_ERR_INI_SIZE   => __( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'geodir-converter' ),
				UPLOAD_ERR_FORM_SIZE  => __( 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.', 'geodir-converter' ),
				UPLOAD_ERR_PARTIAL    => __( 'The uploaded file was only partially uploaded. Please try again.', 'geodir-converter' ),
				UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded. Please select a file and try again.', 'geodir-converter' ),
				UPLOAD_ERR_NO_TMP_DIR => __( 'Missing a temporary folder. Please contact the site administrator.', 'geodir-converter' ),
				UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk. Please contact the site administrator.', 'geodir-converter' ),
				UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the file upload. Please contact the site administrator.', 'geodir-converter' ),
			);

			$error_message = isset( $upload_errors[ $file['error'] ] )
				? $upload_errors[ $file['error'] ]
				: __( 'Unknown upload error.', 'geodir-converter' );

			$this->send_json_error( $error_message );
		}

		// Verify the file was uploaded via HTTP POST.
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->send_json_error( __( 'Invalid file upload method detected.', 'geodir-converter' ) );
		}

		$max_size = wp_max_upload_size();
		if ( $file['size'] > $max_size ) {
			$this->send_json_error(
				sprintf(
					__( 'File size exceeds the maximum limit of %s. Please upload a smaller file.', 'geodir-converter' ),
					size_format( $max_size )
				)
			);
		}

		// Validate extension.
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( $ext !== 'csv' ) {
			$this->send_json_error(
				__( 'Invalid file type. Only CSV files are allowed.', 'geodir-converter' )
			);
		}

		// MIME type validation.
		$finfo         = new finfo( FILEINFO_MIME_TYPE );
		$mime          = $finfo->file( $file['tmp_name'] );
		$allowed_mimes = array( 'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'text/comma-separated-values' );

		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			$this->send_json_error(
				sprintf(
					__( 'Invalid file format detected (%s). Please upload a valid CSV file.', 'geodir-converter' ),
					esc_html( $mime )
				)
			);
		}

		// Parse CSV.
		$importer = $this->get_importer( $importer_id );

		if ( is_wp_error( $importer ) ) {
			$this->send_json_error( $importer->get_error_message() );
		}

		$rows = GeoDir_Converter_Utils::parse_csv( $file['tmp_name'] );

		if ( is_wp_error( $rows ) ) {
			$this->send_json_error( $rows->get_error_message() );
		}

		if ( empty( $rows ) ) {
			$this->send_json_error( __( 'The CSV file is empty or could not be parsed. Please check the file format and try again.', 'geodir-converter' ) );
		}

		// Detect module type based on headers.
		$module_type = $importer->detect_module_type( array_keys( $rows[0] ) );

		if ( ! $module_type ) {
			$this->send_json_error(
				__( 'Unable to determine if this is an Events or Listings CSV. Please ensure your CSV contains the correct headers.', 'geodir-converter' ),
			);
		}

		wp_send_json_success(
			array(
				'message'     => sprintf(
					__( 'Successfully parsed %1$s: Found %2$d rows.', 'geodir-converter' ),
					$module_type,
					count( $rows )
				),
				'module_type' => $module_type,
				'file_name'   => esc_html( $file['name'] ),
				'file_size'   => size_format( $file['size'] ),
				'total_rows'  => count( $rows ),
			)
		);
	}

	/**
	 * AJAX handler for aborting the import process.
	 */
	public function abort() {
		$this->verify_nonce( __FUNCTION__ );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( __( 'You do not have permission to perform this action.', 'geodir-converter' ) );
		}

		$importer_id = isset( $_POST['importerId'] ) ? sanitize_text_field( $_POST['importerId'] ) : '';

		$importer = $this->get_importer( $importer_id );

		if ( is_wp_error( $importer ) ) {
			$this->send_json_error( $importer->get_error_message() );
		}

		// Abort the background process.
		$importer->background_process->abort();

		wp_send_json_success();
	}

	/**
	 * AJAX handler for parsing CSV file.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function csv_parse() {
		$this->verify_nonce( __FUNCTION__ );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( __( 'You do not have permission to perform this action.', 'geodir-converter' ) );
		}

		if ( ! isset( $_FILES['file'] ) || ! isset( $_FILES['file']['error'] ) || UPLOAD_ERR_OK !== $_FILES['file']['error'] ) {
			$this->send_json_error( __( 'No file uploaded or upload error.', 'geodir-converter' ) );
		}

		$delimiter = isset( $_POST['csv_delimiter'] ) ? sanitize_text_field( wp_unslash( $_POST['csv_delimiter'] ) ) : ',';
		if ( empty( $delimiter ) || strlen( $delimiter ) > 1 ) {
			$delimiter = ',';
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload( $_FILES['file'], array( 'test_form' => false ) );

		if ( isset( $upload['error'] ) ) {
			$this->send_json_error( $upload['error'] );
		}

		$file_name  = isset( $_FILES['file']['name'] ) ? sanitize_file_name( $_FILES['file']['name'] ) : 'import.csv';
		$attachment = array(
			'post_mime_type' => 'text/csv',
			'post_title'     => $file_name,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attach_id ) ) {
			$this->send_json_error( $attach_id->get_error_message() );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );

		$csv_importer = $this->get_importer( 'csv' );
		if ( is_wp_error( $csv_importer ) ) {
			wp_delete_attachment( $attach_id, true );
			$this->send_json_error( $csv_importer->get_error_message() );
		}

		$headers = $csv_importer->get_csv_headers( $upload['file'], $delimiter );
		if ( is_wp_error( $headers ) ) {
			wp_delete_attachment( $attach_id, true );
			$this->send_json_error( $headers->get_error_message() );
		}

		$sample_data = $csv_importer->get_csv_sample_data( $upload['file'], $delimiter, $headers );
		$total_rows  = $csv_importer->count_csv_rows( $upload['file'], $delimiter );

		$existing_settings = $csv_importer->options_handler->get_option( 'import_settings', array() );
		$gd_post_type      = isset( $_POST['gd_post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['gd_post_type'] ) ) : 'gd_place';

		$csv_importer->options_handler->update_option(
			'import_settings',
			array_merge(
				$existing_settings,
				array(
					'csv_file_id'     => $attach_id,
					'csv_delimiter'   => $delimiter,
					'csv_sample_data' => $sample_data,
					'csv_row_count'   => $total_rows,
					'gd_post_type'    => $gd_post_type,
					'import_files'    => array(
						array(
							'name'      => $file_name,
							'row_count' => $total_rows,
						),
					),
				)
			)
		);

		wp_send_json_success(
			array(
				'file_id'    => $attach_id,
				'delimiter'  => $delimiter,
				'headers'    => $headers,
				'total_rows' => $total_rows,
				/* translators: %1$d: number of columns, %2$d: number of rows */
				'message'    => sprintf(
					__( 'CSV file parsed successfully. Found %1$d columns and %2$d rows.', 'geodir-converter' ),
					count( $headers ),
					$total_rows
				),
			)
		);
	}

	/**
	 * AJAX handler for getting CSV mapping fields.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function csv_get_fields() {
		$this->verify_nonce( __FUNCTION__ );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( __( 'You do not have permission to perform this action.', 'geodir-converter' ) );
		}

		$csv_importer = $this->get_importer( 'csv' );
		if ( is_wp_error( $csv_importer ) ) {
			$this->send_json_error( $csv_importer->get_error_message() );
		}

		$post_type = isset( $_POST['gd_post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['gd_post_type'] ) ) : 'gd_place';
		$fields    = $csv_importer->get_mapping_fields( $post_type );

		wp_send_json_success( array( 'fields' => $fields ) );
	}

	/**
	 * AJAX handler for refreshing CSV mapping fields.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function csv_refresh_fields() {
		$this->verify_nonce( __FUNCTION__ );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( __( 'You do not have permission to perform this action.', 'geodir-converter' ) );
		}

		$csv_importer = $this->get_importer( 'csv' );
		if ( is_wp_error( $csv_importer ) ) {
			$this->send_json_error( $csv_importer->get_error_message() );
		}

		$post_type = isset( $_POST['gd_post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['gd_post_type'] ) ) : 'gd_place';

		$import_settings                 = $csv_importer->options_handler->get_option( 'import_settings', array() );
		$import_settings['gd_post_type'] = $post_type;
		$csv_importer->options_handler->update_option( 'import_settings', $import_settings );

		$fields = $csv_importer->get_mapping_fields( $post_type );

		ob_start();
		$csv_importer->render_column_mapping_table();
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'fields' => $fields,
				'html'   => $html,
			)
		);
	}

	/**
	 * AJAX handler for getting CSV mapping step HTML.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function csv_get_mapping_step() {
		$this->verify_nonce( __FUNCTION__ );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( __( 'You do not have permission to perform this action.', 'geodir-converter' ) );
		}

		$csv_importer = $this->get_importer( 'csv' );
		if ( is_wp_error( $csv_importer ) ) {
			$this->send_json_error( $csv_importer->get_error_message() );
		}

		$file_id   = isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
		$delimiter = isset( $_POST['delimiter'] ) ? sanitize_text_field( wp_unslash( $_POST['delimiter'] ) ) : ',';

		if ( ! $file_id ) {
			$this->send_json_error( __( 'File ID is required.', 'geodir-converter' ) );
		}

		$import_settings                  = $csv_importer->options_handler->get_option( 'import_settings', array() );
		$import_settings['csv_file_id']   = $file_id;
		$import_settings['csv_delimiter'] = $delimiter;
		$csv_importer->options_handler->update_option( 'import_settings', $import_settings );

		ob_start();
		$csv_importer->render_settings();
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * AJAX handler for clearing CSV file settings.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function csv_clear_file() {
		$this->verify_nonce( __FUNCTION__ );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( __( 'You do not have permission to perform this action.', 'geodir-converter' ) );
		}

		$csv_importer = $this->get_importer( 'csv' );
		if ( is_wp_error( $csv_importer ) ) {
			$this->send_json_error( $csv_importer->get_error_message() );
		}

		$csv_importer->clear_import_options();

		ob_start();
		$csv_importer->render_settings();
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * AJAX handler for saving CSV mapping template.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function csv_save_template() {
		$this->verify_nonce( __FUNCTION__ );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( __( 'You do not have permission to perform this action.', 'geodir-converter' ) );
		}

		$csv_importer = $this->get_importer( 'csv' );
		if ( is_wp_error( $csv_importer ) ) {
			$this->send_json_error( $csv_importer->get_error_message() );
		}

		$name    = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '';
		$mapping = isset( $_POST['csv_mapping'] ) && is_array( $_POST['csv_mapping'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['csv_mapping'] ) ) : array();

		if ( empty( $name ) ) {
			$this->send_json_error( __( 'Template name is required.', 'geodir-converter' ) );
		}

		if ( empty( $mapping ) ) {
			$this->send_json_error( __( 'Mapping data is required.', 'geodir-converter' ) );
		}

		$result = $csv_importer->save_mapping_template( $name, $mapping );

		if ( is_wp_error( $result ) ) {
			$this->send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'       => __( 'Template saved successfully.', 'geodir-converter' ),
				'template_id'   => isset( $result['template_id'] ) ? $result['template_id'] : '',
				'template_name' => isset( $result['template_name'] ) ? $result['template_name'] : $name,
			)
		);
	}

	/**
	 * AJAX handler for loading CSV mapping template.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function csv_load_template() {
		$this->verify_nonce( __FUNCTION__ );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( __( 'You do not have permission to perform this action.', 'geodir-converter' ) );
		}

		$csv_importer = $this->get_importer( 'csv' );
		if ( is_wp_error( $csv_importer ) ) {
			$this->send_json_error( $csv_importer->get_error_message() );
		}

		$template_id = isset( $_POST['template_id'] ) ? sanitize_text_field( wp_unslash( $_POST['template_id'] ) ) : '';

		if ( empty( $template_id ) ) {
			$this->send_json_error( __( 'Template ID is required.', 'geodir-converter' ) );
		}

		$template = $csv_importer->load_mapping_template( $template_id );

		if ( is_wp_error( $template ) ) {
			$this->send_json_error( $template->get_error_message() );
		}

		// Update import settings with the template mapping.
		$import_settings                 = $csv_importer->options_handler->get_option( 'import_settings', array() );
		$import_settings['csv_mapping'] = isset( $template['mapping'] ) ? $template['mapping'] : array();
		$csv_importer->options_handler->update_option( 'import_settings', $import_settings );

		wp_send_json_success(
			array(
				'mapping' => $template['mapping'],
				'message' => __( 'Template loaded successfully.', 'geodir-converter' ),
			)
		);
	}

	/**
	 * AJAX handler for deleting CSV mapping template.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function csv_delete_template() {
		$this->verify_nonce( __FUNCTION__ );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( __( 'You do not have permission to perform this action.', 'geodir-converter' ) );
		}

		$csv_importer = $this->get_importer( 'csv' );
		if ( is_wp_error( $csv_importer ) ) {
			$this->send_json_error( $csv_importer->get_error_message() );
		}

		$template_id = isset( $_POST['template_id'] ) ? sanitize_text_field( wp_unslash( $_POST['template_id'] ) ) : '';

		if ( empty( $template_id ) ) {
			$this->send_json_error( __( 'Template ID is required.', 'geodir-converter' ) );
		}

		$result = $csv_importer->delete_mapping_template( $template_id );

		if ( is_wp_error( $result ) ) {
			$this->send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Template deleted successfully.', 'geodir-converter' ),
			)
		);
	}
}
