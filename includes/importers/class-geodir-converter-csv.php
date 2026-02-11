<?php
/**
 * CSV Converter Class.
 *
 * @since     2.3.0
 * @package   GeoDir_Converter
 */

namespace GeoDir_Converter\Importers;

use WP_Error;
use GeoDir_Media;
use GeoDir_Converter\GeoDir_Converter_Utils;
use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Main converter class for importing from CSV files.
 *
 * @since 2.3.0
 */
class GeoDir_Converter_CSV extends GeoDir_Converter_Importer {

	/**
	 * Action identifier for parsing listings.
	 *
	 * @var string
	 */
	const ACTION_PARSE_LISTINGS = 'parse_listings';

	/**
	 * Action identifier for importing listings.
	 *
	 * @var string
	 */
	const ACTION_IMPORT_LISTINGS = 'import_listings';

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
	protected $importer_id = 'csv';

	/**
	 * CSV file attachment ID.
	 *
	 * @var int
	 */
	private $csv_file_id = 0;

	/**
	 * CSV delimiter.
	 *
	 * @var string
	 */
	private $csv_delimiter = ',';

	/**
	 * Column mapping (CSV column => GeoDirectory field).
	 *
	 * @var array
	 */
	private $column_mapping = array();

	/**
	 * Initialize hooks.
	 *
	 * @since 2.3.0
	 */
	protected function init() {
	}

	/**
	 * Get class instance.
	 *
	 * @since 2.3.0
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
	 * @since 2.3.0
	 * @return string
	 */
	public function get_title() {
		return __( 'CSV', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @since 2.3.0
	 * @return string
	 */
	public function get_description() {
		return __( 'Import listings from a CSV file with column mapping.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @since 2.3.0
	 * @return string
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/csv.png';
	}

	/**
	 * Get importer task action.
	 *
	 * @since 2.3.0
	 * @return string
	 */
	public function get_action() {
		return self::ACTION_PARSE_LISTINGS;
	}

	/**
	 * Render importer settings.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function render_settings() {
		$csv_file_id   = $this->get_import_setting( 'csv_file_id', 0 );
		$csv_delimiter = $this->get_import_setting( 'csv_delimiter', ',' );
		$mapping_step  = $csv_file_id > 0 ? true : false;
		?>
		<form class="geodir-converter-settings-form geodir-converter-csv-form" method="post" enctype="multipart/form-data">
			<h6 class="fs-base"><?php esc_html_e( 'CSV Importer Settings', 'geodir-converter' ); ?></h6>

			<?php if ( ! $mapping_step ) : ?>
				<?php $this->render_upload_step(); ?>
			<?php else : ?>
				<?php $this->render_mapping_step(); ?>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Render the upload step HTML.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function render_upload_step() {
		$csv_delimiter = $this->get_import_setting( 'csv_delimiter', ',' );
		?>
		<div class="geodir-converter-connect-wrapper">
			<h2 class="wp-heading-inline mb-3 fs-base text-uppercase fw-normal text-gray-dark"><?php esc_html_e( 'Upload CSV File', 'geodir-converter' ); ?></h2>
			<?php
			$this->display_post_type_select();
			aui()->input(
				array(
					'id'          => 'csv_delimiter',
					'name'        => 'csv_delimiter',
					'type'        => 'text',
					'label'       => esc_html__( 'CSV Delimiter', 'geodir-converter' ),
					'label_type'  => 'top',
					'label_class' => 'font-weight-bold fw-bold',
					'value'       => $csv_delimiter,
					'placeholder' => ',',
					'help_text'   => esc_html__( 'Enter the delimiter used in your CSV file (e.g., , or ; or |). Default is comma (,).', 'geodir-converter' ),
					'wrap_class'  => 'mb-3',
				),
				true
			);
			?>
			<div class="geodir-converter-uploads-wrapper">
				<label class="form-label font-weight-bold fw-bold d-block mb-2"><?php esc_html_e( 'Upload CSV File', 'geodir-converter' ); ?></label>
				<p class="text-muted mb-2"><?php esc_html_e( 'Upload your CSV file to import listings. The file will be parsed and you can then map columns to GeoDirectory fields.', 'geodir-converter' ); ?></p>

				<div class="file-uploader rounded p-4 text-center bg-light geodir-converter-drop-zone">
					<p class="mb-2 font-weight-bold"><?php esc_html_e( 'Drag and drop your CSV file here', 'geodir-converter' ); ?></p>
					<p class="text-muted mb-2"><?php esc_html_e( 'or use the button below to browse your files.', 'geodir-converter' ); ?></p>
					<input type="file" accept=".csv,.txt" class="d-none geodir-converter-files-input">
					<button type="button" class="btn btn-outline-primary btn-sm geodir-converter-files-btn" <?php disabled( $this->background_process->is_in_progress() ); ?>><?php esc_html_e( 'Select File', 'geodir-converter' ); ?></button>
				</div>

				<div class="mt-4 geodir-converter-uploads">
					<?php
					$import_settings = (array) $this->options_handler->get_option( 'import_settings', array() );
					$import_files    = isset( $import_settings['import_files'] ) ? (array) $import_settings['import_files'] : array();
					foreach ( $import_files as $file ) :
						?>
						<div class="upload-item my-2" data-id="upload-<?php echo esc_attr( time() ); ?>">
							<div class="d-flex justify-content-between align-items-center">
								<span class="fw-bold text-truncate"><?php echo esc_html( $file['name'] ); ?></span>
								<i class="fas fa-solid text-muted ms-2 geodir-converter-progress-icon fa-check text-success" aria-hidden="true"></i>
							</div>
							<div class="progress my-1" role="progressbar" aria-valuemin="0" aria-valuemax="100">
								<div class="progress-bar progress-bar-striped bg-gray-dark" style="width: 100%;"><?php echo esc_html( '100%' ); ?></div>
							</div>
							<div class="geodir-converter-progress-status small text-muted mt-1">
								<?php
								/* translators: %d: number of rows */
								printf( esc_html__( 'Successfully parsed CSV: Found %d rows.', 'geodir-converter' ), isset( $file['row_count'] ) ? absint( $file['row_count'] ) : 0 );
								?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the mapping step HTML.
	 *
	 * @return void
	 */
	public function render_mapping_step() {
		$csv_file_id     = $this->get_import_setting( 'csv_file_id', 0 );
		$csv_delimiter   = $this->get_import_setting( 'csv_delimiter', ',' );
		$saved_templates = $this->get_saved_templates();
		?>
		<div class="geodir-converter-csv-mapping-step">
			<?php
			$this->display_post_type_select();
			$this->display_author_select( true );
			$this->display_test_mode_checkbox();
			?>
			<?php
			aui()->alert(
				array(
					'type'    => 'info',
					'content' => esc_html__( 'Make sure you have created all the custom fields you want to map in GeoDirectory first. They will appear in the mapping dropdown below.', 'geodir-converter' ),
					'footer'  => '<button type="button" class="btn btn-sm btn-primary geodir-converter-refresh-fields"><i class="fas fa-sync-alt"></i> ' . esc_html__( 'Refresh Fields', 'geodir-converter' ) . '</button>',
					'class'   => 'mt-3 mb-3',
				),
				true
			);
			?>
			<div id="geodir-converter-csv-mapping-wrapper" class="mt-3">
				<?php $this->render_column_mapping_table(); ?>
			</div>
            
			<input type="hidden" name="csv_file_id" value="<?php echo esc_attr( $csv_file_id ); ?>" />
			<input type="hidden" name="csv_delimiter" value="<?php echo esc_attr( $csv_delimiter ); ?>" />
			
			<div class="geodir-converter-templates-section border-top pt-3 mt-3">
				<h6 class="mb-3 d-flex align-items-center text-muted fs-sm">
					<i class="fas fa-layer-group me-2"></i>
					<?php esc_html_e( 'Mapping Templates', 'geodir-converter' ); ?>
				</h6>
				<div class="row g-3">
					<?php if ( ! empty( $saved_templates ) ) : ?>
						<div class="col-md-6 geodir-converter-template-load-section">
							<label class="form-label mb-2">
								<?php esc_html_e( 'Load Template', 'geodir-converter' ); ?>
							</label>
							<div class="input-group">
								<select class="form-select form-select-sm" id="csv_template_select">
									<option value=""><?php esc_html_e( 'Choose a saved template...', 'geodir-converter' ); ?></option>
									<?php foreach ( $saved_templates as $template_id => $template ) : ?>
										<option value="<?php echo esc_attr( $template_id ); ?>" data-name="<?php echo esc_attr( $template['name'] ); ?>">
											<?php echo esc_html( $template['name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<button type="button" class="btn btn-sm btn-primary geodir-converter-load-template" title="<?php esc_attr_e( 'Load selected template', 'geodir-converter' ); ?>">
									<i class="fas fa-arrow-down"></i>
								</button>
								<button type="button" class="btn btn-sm btn-outline-danger geodir-converter-delete-template" title="<?php esc_attr_e( 'Delete selected template', 'geodir-converter' ); ?>">
									<i class="fas fa-trash-alt"></i>
								</button>
							</div>
						</div>
					<?php endif; ?>
					<div class="<?php echo ! empty( $saved_templates ) ? 'col-md-6' : 'col-12'; ?> geodir-converter-template-save-section">
						<label class="form-label mb-2">
							<?php esc_html_e( 'Save Current Mapping', 'geodir-converter' ); ?>
						</label>
						<div class="input-group">
							<input type="text" class="form-control form-control-sm" id="csv_template_name" placeholder="<?php esc_attr_e( 'Enter template name...', 'geodir-converter' ); ?>">
							<button type="button" class="btn btn-sm btn-outline-primary geodir-converter-save-template">
								<i class="fas fa-save me-1"></i>
								<?php esc_html_e( 'Save', 'geodir-converter' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>
			
			<div class="geodir-converter-actions mt-3 mb-3 d-flex justify-content-between align-items-center">
				<div>
					<button type="button" class="btn btn-primary btn-sm geodir-converter-import me-2"><?php esc_html_e( 'Start Import', 'geodir-converter' ); ?></button>
					<button type="button" class="btn btn-outline-danger btn-sm geodir-converter-abort"><?php esc_html_e( 'Abort', 'geodir-converter' ); ?></button>
				</div>
				<button type="button" class="btn btn-outline-secondary btn-sm geodir-converter-csv-back"><?php esc_html_e( 'Upload Different File', 'geodir-converter' ); ?></button>
			</div>
			<?php
			$this->display_progress();
			$this->display_logs( $this->get_logs() );
			$this->display_error_alert();
			?>
		</div>
		<?php
	}

	/**
	 * Render column mapping table.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function render_column_mapping_table() {
		$csv_file_id   = $this->get_import_setting( 'csv_file_id', 0 );
		$csv_delimiter = $this->get_import_setting( 'csv_delimiter', ',' );
		$post_type     = $this->get_import_setting( 'gd_post_type', 'gd_place' );

		if ( ! $csv_file_id ) {
			return;
		}

		$csv_file = get_attached_file( $csv_file_id );
		if ( ! $csv_file || ! file_exists( $csv_file ) ) {
			echo '<div class="alert alert-danger">' . esc_html__( 'CSV file not found. Please upload again.', 'geodir-converter' ) . '</div>';
			return;
		}

		$headers = $this->get_csv_headers( $csv_file, $csv_delimiter );
		if ( is_wp_error( $headers ) ) {
			echo '<div class="alert alert-danger">' . esc_html( $headers->get_error_message() ) . '</div>';
			return;
		}

		$sample_data = $this->get_import_setting( 'csv_sample_data', array() );
		$row_count   = $this->get_import_setting( 'csv_row_count', 0 );
		$gd_fields   = $this->get_mapping_fields( $post_type );

		?>
		<?php if ( $row_count > 0 ) : ?>
			<p class="mb-3 fs-base mb-2 font-weight-bold">
				<?php
				printf(
					/* translators: %d: number of listings */
					esc_html__( 'Found %d listings.', 'geodir-converter' ),
					absint( $row_count )
				);
				?>
			</p>
		<?php endif; ?>
		
		<table class="table geodir-converter-mapping-table">
			<thead>
				<tr>
					<th style="width: 40%;"><?php esc_html_e( 'Column name', 'geodir-converter' ); ?></th>
					<th style="width: 60%;"><?php esc_html_e( 'Map to field', 'geodir-converter' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $headers as $column ) : ?>
					<?php
					$field_name    = 'csv_mapping[' . esc_attr( $column ) . ']';
					$saved_mapping = $this->get_import_setting( 'csv_mapping', array() );
					$selected      = isset( $saved_mapping[ $column ] ) ? $saved_mapping[ $column ] : '';
					$sample_value  = isset( $sample_data[ $column ] ) ? $sample_data[ $column ] : '';
					?>
					<tr>
						<td style="max-width:350px">
							<strong class="fs-sm"><?php echo esc_html( $column ); ?></strong>
							<?php if ( ! empty( $sample_value ) ) : ?>
								<div class="text-muted small mt-1" style="word-wrap: break-word; overflow-wrap: break-word;"><?php echo esc_html( $sample_value ); ?></div>
							<?php endif; ?>
						</td>
						<td> 
							<select name="<?php echo esc_attr( $field_name ); ?>" class="form-select form-select-sm geodir-converter-field-mapping aui-select2">
								<option value=""><?php esc_html_e( 'Do not import', 'geodir-converter' ); ?></option>
								<?php foreach ( $gd_fields as $field_key => $field_label ) : ?>
									<option value="<?php echo esc_attr( $field_key ); ?>" <?php selected( $selected, $field_key ); ?>>
										<?php echo esc_html( $field_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Get mapping fields for a post type.
	 *
	 * @since 2.3.0
	 * @param string $post_type Post type.
	 * @return array Array of field_key => field_label.
	 */
	public function get_mapping_fields( $post_type ) {
		$fields = array();

		$fields['post_title']    = __( 'Post Title', 'geodir-converter' );
		$fields['post_content']  = __( 'Post Content', 'geodir-converter' );
		$fields['post_excerpt']  = __( 'Post Excerpt', 'geodir-converter' );
		$fields['post_status']   = __( 'Post Status', 'geodir-converter' );
		$fields['post_date']     = __( 'Post Date', 'geodir-converter' );
		$fields['post_tags']     = __( 'Post Tags', 'geodir-converter' );
		/* translators: %s: taxonomy name */
		$fields['post_category'] = sprintf( __( 'Post Category (%s)', 'geodir-converter' ), $post_type . 'category' );

		$gd_core_fields = array(
			'street'         => __( 'Street Address', 'geodir-converter' ),
			'street2'        => __( 'Street Address 2', 'geodir-converter' ),
			'city'           => __( 'City', 'geodir-converter' ),
			'region'         => __( 'Region/State', 'geodir-converter' ),
			'country'        => __( 'Country', 'geodir-converter' ),
			'zip'            => __( 'ZIP/Postal Code', 'geodir-converter' ),
			'latitude'       => __( 'Latitude', 'geodir-converter' ),
			'longitude'      => __( 'Longitude', 'geodir-converter' ),
			'featured_image' => __( 'Featured Image (URL)', 'geodir-converter' ),
			'post_images'    => __( 'Gallery Images (comma-separated URLs)', 'geodir-converter' ),
		);

		$fields = array_merge( $fields, $gd_core_fields );

		global $wpdb;
		$table_name    = GEODIR_CUSTOM_FIELDS_TABLE;
		$custom_fields = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT htmlvar_name, admin_title, frontend_title 
				FROM ' . $table_name . ' 
				WHERE post_type = %s 
				AND is_active = 1 
				ORDER BY sort_order ASC, admin_title ASC',
				$post_type
			)
		);

		if ( ! empty( $custom_fields ) ) {
			foreach ( $custom_fields as $field ) {
				$field_label                    = ! empty( $field->admin_title ) ? $field->admin_title : ( ! empty( $field->frontend_title ) ? $field->frontend_title : $field->htmlvar_name );
				$fields[ $field->htmlvar_name ] = $field_label;
			}
		}

		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( 'post_tag' === $taxonomy->name ) {
				/* translators: %s: taxonomy label */
				$fields[ 'tax_input[' . $taxonomy->name . ']' ] = sprintf( __( 'Tags (%s)', 'geodir-converter' ), $taxonomy->label );
			} else {
				/* translators: %s: taxonomy label */
				$fields[ 'tax_input[' . $taxonomy->name . ']' ] = sprintf( __( 'Categories (%s)', 'geodir-converter' ), $taxonomy->label );
			}
		}

		return $fields;
	}

	/**
	 * Validate importer settings.
	 *
	 * @since 2.3.0
	 * @param array $settings The settings to validate.
	 * @param array $files    The files to validate.
	 * @return array|WP_Error Validated and sanitized settings or WP_Error on failure.
	 */
	public function validate_settings( array $settings, array $files = array() ) {
		$post_types = geodir_get_posttypes();
		$errors     = array();

		$settings['gd_post_type']    = isset( $settings['gd_post_type'] ) && ! empty( $settings['gd_post_type'] ) ? sanitize_text_field( $settings['gd_post_type'] ) : 'gd_place';
		$settings['wp_author_id']    = ( isset( $settings['wp_author_id'] ) && ! empty( $settings['wp_author_id'] ) ) ? absint( $settings['wp_author_id'] ) : get_current_user_id();
		$settings['test_mode']       = ( isset( $settings['test_mode'] ) && ! empty( $settings['test_mode'] ) && 'no' !== $settings['test_mode'] ) ? 'yes' : 'no';
		$settings['csv_file_id']     = isset( $settings['csv_file_id'] ) ? absint( $settings['csv_file_id'] ) : 0;
		$settings['csv_delimiter']   = isset( $settings['csv_delimiter'] ) ? sanitize_text_field( $settings['csv_delimiter'] ) : ',';
		$settings['csv_date_format'] = 'auto'; // Always auto-detect.
		$settings['csv_encoding']    = 'auto'; // Always auto-detect.
		$csv_mapping                 = isset( $settings['csv_mapping'] ) && is_array( $settings['csv_mapping'] ) ? $settings['csv_mapping'] : array();
		$sanitized_mapping           = array();

		foreach ( $csv_mapping as $csv_column => $gd_field ) {
			$csv_column = sanitize_text_field( $csv_column );
			$gd_field   = sanitize_text_field( $gd_field );
			if ( ! empty( $csv_column ) && ! empty( $gd_field ) ) {
				$sanitized_mapping[ $csv_column ] = $gd_field;
			}
		}
		$settings['csv_mapping'] = $sanitized_mapping;

		if ( ! in_array( $settings['gd_post_type'], $post_types, true ) ) {
			$errors[] = esc_html__( 'The selected post type is invalid. Please choose a valid post type.', 'geodir-converter' );
		}

		if ( empty( $settings['csv_file_id'] ) || ! get_post( $settings['csv_file_id'] ) ) {
			$errors[] = esc_html__( 'CSV file is required. Please upload a CSV file.', 'geodir-converter' );
		}

		if ( empty( $settings['csv_mapping'] ) ) {
			$errors[] = esc_html__( 'Please map at least one CSV column to a GeoDirectory field.', 'geodir-converter' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'invalid_import_settings', implode( '<br>', $errors ) );
		}

		if ( ! empty( $settings['csv_file_id'] ) ) {
			$csv_file = get_attached_file( $settings['csv_file_id'] );
			if ( $csv_file && file_exists( $csv_file ) ) {
				$delimiter = $settings['csv_delimiter'];

				// Auto-detect encoding.
				$settings['csv_encoding'] = $this->detect_csv_encoding( $csv_file );

				$headers = $this->get_csv_headers( $csv_file, $delimiter );

				if ( ! is_wp_error( $headers ) ) {
					$settings['csv_sample_data'] = $this->get_csv_sample_data( $csv_file, $delimiter, $headers );

					// Auto-detect date format from sample data.
					if ( ! empty( $settings['csv_sample_data'] ) ) {
						$settings['csv_date_format'] = $this->detect_date_format( $settings['csv_sample_data'] );
					}

					$settings['csv_row_count'] = $this->count_csv_rows( $csv_file, $delimiter );
				}
			}
		}

		/**
		 * Filter CSV import settings after validation.
		 *
		 * @since 2.3.0
		 * @param array $settings Validated settings.
		 * @param array $files    Uploaded files.
		 */
		$settings = apply_filters( 'geodir_converter_csv_validate_settings', $settings, $files );

		return $settings;
	}

	/**
	 * Get next task.
	 *
	 * @since 2.3.0
	 * @param array $task The current task.
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
	 * Task: Parse CSV file and batch listings for import.
	 *
	 * @since 2.3.0
	 * @param array $task Task data.
	 * @return array|false Task data or false on completion.
	 */
	public function task_parse_listings( array $task ) {
		$csv_file_id   = $this->get_import_setting( 'csv_file_id', 0 );
		$csv_delimiter = $this->get_import_setting( 'csv_delimiter', ',' );
		$offset        = isset( $task['offset'] ) ? (int) $task['offset'] : 0;
		$batch_size    = (int) $this->get_batch_size();

		if ( ! $csv_file_id ) {
			$this->log( __( 'CSV file not found.', 'geodir-converter' ), 'error' );
			return false;
		}

		$csv_file = get_attached_file( $csv_file_id );
		if ( ! $csv_file || ! file_exists( $csv_file ) ) {
			$this->log( __( 'CSV file not found on server.', 'geodir-converter' ), 'error' );
			return false;
		}

		if ( 0 === $offset ) {
			$this->log( __( 'Starting listings parsing process...', 'geodir-converter' ) );
		}

		$all_rows = GeoDir_Converter_Utils::parse_csv( $csv_file, array(), $csv_delimiter );
		if ( is_wp_error( $all_rows ) ) {
			/* translators: %s: error message */
			$this->log( sprintf( __( 'Failed to parse CSV: %s', 'geodir-converter' ), $all_rows->get_error_message() ), 'error' );
			return false;
		}

		if ( empty( $all_rows ) ) {
			$this->log( __( 'No listings found for parsing. Skipping process.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		if ( 0 === $offset ) {
			$this->log( sprintf( __( 'Found %d listings for import.', 'geodir-converter' ), count( $all_rows ) ), 'success' );
			$this->increase_imports_total( count( $all_rows ) );
		}

		$rows = array_slice( $all_rows, $offset, $batch_size, true );

		if ( empty( $rows ) ) {
			$this->log( __( 'Import process completed. No more listings found.', 'geodir-converter' ) );
			return $this->next_task( $task, true );
		}

		$batched_tasks = array_chunk( $rows, 10, true );
		$import_tasks  = array();
		foreach ( $batched_tasks as $batch ) {
			$import_tasks[] = array(
				'action' => self::ACTION_IMPORT_LISTINGS,
				'rows'   => $batch,
			);
		}

		$this->background_process->add_import_tasks( $import_tasks );

		$complete = ( $offset + $batch_size >= count( $all_rows ) );

		if ( ! $complete ) {
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		return $this->next_task( $task, true );
	}

	/**
	 * Task: Import a batch of listings from CSV.
	 *
	 * @since 2.3.0
	 * @param array $task Task data.
	 * @return array|false Stats array or false on completion.
	 */
	public function task_import_listings( array $task ) {
		$post_type      = $this->get_import_post_type();
		$settings       = $this->get_import_settings();
		$column_mapping = isset( $settings['csv_mapping'] ) && is_array( $settings['csv_mapping'] ) ? $settings['csv_mapping'] : array();
		$rows           = isset( $task['rows'] ) && is_array( $task['rows'] ) ? $task['rows'] : array();

		$this->maybe_create_importer_id_field( $post_type );

		$stats = array(
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
		);

		if ( empty( $rows ) ) {
			return $stats;
		}

		foreach ( $rows as $row ) {
			$result = $this->import_single_listing( $row, $column_mapping, $post_type, $settings );

			switch ( $result['status'] ) {
				case self::IMPORT_STATUS_SUCCESS:
					$this->log( sprintf( self::LOG_TEMPLATE_SUCCESS, 'listing', $result['post_title'] ), 'success' );
					++$stats['imported'];
					break;
				case self::IMPORT_STATUS_UPDATED:
					$this->log( sprintf( self::LOG_TEMPLATE_UPDATED, 'listing', $result['post_title'] ), 'warning' );
					++$stats['updated'];
					break;
				case self::IMPORT_STATUS_SKIPPED:
					$this->log( sprintf( self::LOG_TEMPLATE_SKIPPED, 'listing', $result['post_title'] ), 'warning' );
					++$stats['skipped'];
					break;
				case self::IMPORT_STATUS_FAILED:
				default:
					$this->log( sprintf( self::LOG_TEMPLATE_FAILED, 'listing', $result['post_title'] ), 'warning' );
					++$stats['failed'];
					break;
			}
		}

		$this->increase_succeed_imports( $stats['imported'] + $stats['updated'] );
		$this->increase_skipped_imports( $stats['skipped'] );
		$this->increase_failed_imports( $stats['failed'] );

		return false;
	}

	/**
	 * Import a single listing from CSV.
	 *
	 * @since 2.3.0
	 * @param array  $row The row data.
	 * @param array  $column_mapping The column mapping.
	 * @param string $post_type The post type.
	 * @param array  $settings The settings.
	 * @return array Array with 'status' and 'post_title' keys.
	 */
	protected function import_single_listing( $row, $column_mapping, $post_type, $settings ) {
		/**
		 * Action fired before processing a single CSV listing row.
		 *
		 * @since 2.3.0
		 * @param array  $row            The CSV row data.
		 * @param array  $column_mapping The column mapping.
		 * @param string $post_type      The post type.
		 * @param array  $settings       The import settings.
		 */
		do_action( 'geodir_converter_csv_before_import_listing', $row, $column_mapping, $post_type, $settings );

		/**
		 * Filter CSV row data before processing.
		 *
		 * @since 2.3.0
		 * @param array  $row            The CSV row data.
		 * @param array  $column_mapping The column mapping.
		 * @param string $post_type      The post type.
		 * @param array  $settings       The import settings.
		 */
		$row = apply_filters( 'geodir_converter_csv_row_data', $row, $column_mapping, $post_type, $settings );

		if ( empty( $row ) || ! is_array( $row ) ) {
			return array(
				'status'     => self::IMPORT_STATUS_FAILED,
				'post_title' => '',
			);
		}

		$listing_data = array(
			'post_type'   => $post_type,
			'post_status' => isset( $settings['post_status'] ) ? $settings['post_status'] : 'publish',
			'post_author' => isset( $settings['wp_author_id'] ) ? absint( $settings['wp_author_id'] ) : get_current_user_id(),
		);

		$tax_input   = array();
		$date_format = isset( $settings['csv_date_format'] ) ? $settings['csv_date_format'] : 'auto';

		foreach ( $column_mapping as $csv_column => $gd_field ) {
			if ( empty( $gd_field ) || ! isset( $row[ $csv_column ] ) ) {
				continue;
			}

			$value = trim( $row[ $csv_column ] );
			if ( '' === $value ) {
				continue;
			}

			/**
			 * Filter CSV field value before processing.
			 *
			 * @since 2.3.0
			 * @param string $value       The CSV field value.
			 * @param string $csv_column  The CSV column name.
			 * @param string $gd_field    The GeoDirectory field name.
			 * @param array  $row         The full CSV row data.
			 * @param string $post_type   The post type.
			 */
			$value = apply_filters( 'geodir_converter_csv_field_value', $value, $csv_column, $gd_field, $row, $post_type );

			$taxonomy = null;

			if ( strpos( $gd_field, 'tax_input[' ) === 0 ) {
				preg_match( '/tax_input\[(.+?)\]/', $gd_field, $matches );
				if ( ! empty( $matches[1] ) ) {
					$taxonomy = $matches[1];
				}
			} elseif ( 'post_tags' === $gd_field ) {
				$taxonomy = $post_type . '_tags';
			} elseif ( 'post_category' === $gd_field ) {
				$taxonomy = $post_type . 'category';
			}

			if ( $taxonomy ) {
				$terms                  = array_map( 'trim', explode( ',', $value ) );
				$terms                  = array_filter( $terms );
				$tax_input[ $taxonomy ] = $terms;
				continue;
			}

			if ( 'post_date' === $gd_field ) {
				$listing_data[ $gd_field ] = $this->convert_date_format( $value, $date_format );
				continue;
			}

			if ( in_array( $gd_field, array( 'post_title', 'post_content', 'post_excerpt', 'post_status' ), true ) ) {
				$listing_data[ $gd_field ] = $value;
				continue;
			}

			// Update field options if needed.
			$this->maybe_update_field_options( $gd_field, $value, $post_type );

			// Format field value.
			$value = $this->format_field_value( $gd_field, $value, $post_type );

			// Convert date fields.
			$field_info = geodir_get_field_infoby( 'htmlvar_name', $gd_field, $post_type );
			if ( $field_info && isset( $field_info['field_type'] ) && 'datepicker' === $field_info['field_type'] ) {
				$value = $this->convert_date_format( $value, $date_format );
			}

			$listing_data[ $gd_field ] = $value;
		}

		if ( empty( $listing_data['post_title'] ) ) {
			return array(
				'status'     => self::IMPORT_STATUS_FAILED,
				'post_title' => '',
			);
		}

		$row_hash   = md5( wp_json_encode( $row ) );
		$gd_post_id = ! $this->is_test_mode() ? $this->get_gd_listing_id( $row_hash, $this->importer_id . '_id', $post_type ) : false;
		$is_update  = ! empty( $gd_post_id );

		$tax_input = $this->process_taxonomy_terms( $tax_input, $post_type );
		if ( ! empty( $tax_input ) ) {
			$listing_data['tax_input'] = $tax_input;
		}

		$location     = $this->build_location( $listing_data );
		$listing_data = array_merge( $listing_data, $location );

		$listing_data[ $this->importer_id . '_id' ] = $row_hash;

		/**
		 * Filter listing data before inserting/updating post.
		 *
		 * @since 2.3.0
		 * @param array  $listing_data The listing data array.
		 * @param array  $row           The CSV row data.
		 * @param string $post_type     The post type.
		 * @param array  $settings      The import settings.
		 */
		$listing_data = apply_filters( 'geodir_converter_csv_listing_data', $listing_data, $row, $post_type, $settings );

		if ( $this->is_test_mode() ) {
			/**
			 * Action fired after processing a listing in test mode.
			 *
			 * @since 2.3.0
			 * @param array  $listing_data The listing data array.
			 * @param array  $row           The CSV row data.
			 * @param string $post_type     The post type.
			 */
			do_action( 'geodir_converter_csv_test_mode_listing', $listing_data, $row, $post_type );

			return array(
				'status'     => self::IMPORT_STATUS_SUCCESS,
				'post_title' => $listing_data['post_title'],
			);
		}

		if ( $is_update ) {
			GeoDir_Media::delete_files( (int) $gd_post_id, 'post_images' );
		}

		if ( ! empty( $listing_data['featured_image'] ) ) {
			$this->log( sprintf( __( 'Importing featured image: %s', 'geodir-converter' ), $listing_data['featured_image'] ), 'info' );
			$attachment = $this->import_attachment( $listing_data['featured_image'] );

			if ( is_array( $attachment ) && isset( $attachment['url'] ) ) {
				$listing_data['featured_image'] = esc_url_raw( $attachment['url'] );
			} else {
				unset( $listing_data['featured_image'] );
			}
		}

		if ( ! empty( $listing_data['post_images'] ) ) {
			$image_urls = array_map( 'trim', explode( ',', $listing_data['post_images'] ) );
			$image_urls = array_filter( $image_urls );
			$images     = array();

			$this->log( sprintf( __( 'Importing %d post images', 'geodir-converter' ), count( $image_urls ) ), 'info' );

			foreach ( $image_urls as $image_url ) {
				$imported_image = $this->import_attachment( $image_url );
				if ( is_array( $imported_image ) && isset( $imported_image['id'] ) ) {
					$images[] = array(
						'id'      => (int) $imported_image['id'],
						'caption' => '',
						'weight'  => count( $images ),
					);
				}
			}

			if ( ! empty( $images ) ) {
				$listing_data['post_images'] = $this->format_images_data( $images );
			} else {
				unset( $listing_data['post_images'] );
			}
		}

		if ( $is_update ) {
			$listing_data['ID'] = (int) $gd_post_id;
			$gd_post_id         = wp_update_post( $listing_data, true );
		} else {
			$gd_post_id = wp_insert_post( $listing_data, true );
		}

		if ( is_wp_error( $gd_post_id ) ) {
			$this->log( $gd_post_id->get_error_message(), 'error' );

			/**
			 * Action fired when a listing import fails.
			 *
			 * @since 2.3.0
			 * @param WP_Error $gd_post_id   The error object.
			 * @param array    $listing_data The listing data array.
			 * @param array    $row          The CSV row data.
			 */
			do_action( 'geodir_converter_csv_import_failed', $gd_post_id, $listing_data, $row );

			return array(
				'status'     => self::IMPORT_STATUS_FAILED,
				'post_title' => '',
			);
		}

		/**
		 * Action fired after successfully importing a listing.
		 *
		 * @since 2.3.0
		 * @param int    $gd_post_id   The imported post ID.
		 * @param array  $listing_data The listing data array.
		 * @param array  $row          The CSV row data.
		 * @param bool   $is_update    Whether this was an update or new import.
		 */
		do_action( 'geodir_converter_csv_imported_listing', $gd_post_id, $listing_data, $row, $is_update );

		return $is_update ? array(
			'status'     => self::IMPORT_STATUS_UPDATED,
			'post_title' => $listing_data['post_title'],
		) : array(
			'status'     => self::IMPORT_STATUS_SUCCESS,
			'post_title' => $listing_data['post_title'],
		);
	}

	/**
	 * Build a normalized location array for CSV row data.
	 *
	 * @since 2.3.0
	 * @param array $listing_data The listing data.
	 * @return array
	 */
	private function build_location( $listing_data ) {
		$defaults = array(
			'street'    => '',
			'street2'   => '',
			'city'      => '',
			'region'    => '',
			'country'   => '',
			'zip'       => '',
			'latitude'  => '',
			'longitude' => '',
			'mapview'   => '',
			'mapzoom'   => '',
		);

		$location = wp_parse_args( $this->get_default_location(), $defaults );

		if ( isset( $listing_data['street'] ) && ! empty( $listing_data['street'] ) ) {
			$location['street'] = $listing_data['street'];
		}
		if ( isset( $listing_data['street2'] ) && ! empty( $listing_data['street2'] ) ) {
			$location['street2'] = $listing_data['street2'];
		}
		if ( isset( $listing_data['city'] ) && ! empty( $listing_data['city'] ) ) {
			$location['city'] = $listing_data['city'];
		}
		if ( isset( $listing_data['region'] ) && ! empty( $listing_data['region'] ) ) {
			$location['region'] = $listing_data['region'];
		}
		if ( isset( $listing_data['country'] ) && ! empty( $listing_data['country'] ) ) {
			$location['country'] = $listing_data['country'];
		}
		if ( isset( $listing_data['zip'] ) && ! empty( $listing_data['zip'] ) ) {
			$location['zip'] = $listing_data['zip'];
		}

		$has_coords = false;
		if ( isset( $listing_data['latitude'], $listing_data['longitude'] ) && ! empty( $listing_data['latitude'] ) && ! empty( $listing_data['longitude'] ) ) {
			$latitude  = preg_replace( '/[^0-9.\-]/', '', $listing_data['latitude'] );
			$longitude = preg_replace( '/[^0-9.\-]/', '', $listing_data['longitude'] );

			if ( is_numeric( $latitude ) && is_numeric( $longitude ) ) {
				$location['latitude']  = (float) $latitude;
				$location['longitude'] = (float) $longitude;
				$has_coords            = true;
			}
		}

		if ( $has_coords ) {
			$this->log(
				sprintf(
					/* translators: 1: latitude value, 2: longitude value */
					esc_html__( 'Resolving address from coordinates %1$s,%2$s', 'geodir-converter' ),
					$location['latitude'],
					$location['longitude']
				),
				'info'
			);

			$lookup = GeoDir_Converter_Utils::get_location_from_coords( $location['latitude'], $location['longitude'] );

			if ( ! is_wp_error( $lookup ) ) {
				$location['street'] = $lookup['address'];
				unset( $lookup['address'] );
				$location = array_merge( $location, $lookup );
			}
		}

		return $location;
	}

	/**
	 * Process taxonomy terms from CSV mapping.
	 *
	 * @since 2.3.0
	 * @param array  $tax_input The raw taxonomy input from CSV.
	 * @param string $post_type The post type.
	 * @return array Processed taxonomy input array.
	 */
	private function process_taxonomy_terms( $tax_input, $post_type ) {
		if ( empty( $tax_input ) || ! is_array( $tax_input ) ) {
			return array();
		}

		$processed = array();

		foreach ( $tax_input as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) ) {
				$terms = array( $terms );
			}

			$terms = array_filter( array_map( 'trim', $terms ) );

			if ( empty( $terms ) ) {
				continue;
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$processed_terms = array();
			$is_tag_taxonomy = ( 'post_tag' === $taxonomy || str_ends_with( $taxonomy, '_tags' ) );

			foreach ( $terms as $term_name ) {
				if ( empty( $term_name ) ) {
					continue;
				}

				if ( $this->is_test_mode() ) {
					$processed_terms[] = $is_tag_taxonomy ? $term_name : 0;
					continue;
				}

				if ( $is_tag_taxonomy ) {
					$processed_terms[] = $term_name;
					continue;
				}

				$term = term_exists( $term_name, $taxonomy );

				if ( ! $term ) {
					$term = wp_insert_term( $term_name, $taxonomy );

					if ( is_wp_error( $term ) ) {
						$this->log(
							sprintf(
								/* translators: 1: term name, 2: taxonomy name, 3: error message */
								esc_html__( 'Failed to create term "%1$s" in taxonomy "%2$s": %3$s', 'geodir-converter' ),
								esc_html( $term_name ),
								esc_html( $taxonomy ),
								esc_html( $term->get_error_message() )
							),
							'warning'
						);
						continue;
					}
				}

				$term_id           = is_array( $term ) ? $term['term_id'] : $term;
				$processed_terms[] = $term_id;
			}

			if ( ! empty( $processed_terms ) ) {
				$processed[ $taxonomy ] = $processed_terms;
			}
		}

		return $processed;
	}

	/**
	 * Maybe create the importer ID field.
	 *
	 * @since 2.3.0
	 * @param string $post_type Post type.
	 * @return void
	 */
	private function maybe_create_importer_id_field( $post_type ) {
		$field_key = $this->importer_id . '_id';
		$existing  = geodir_get_field_infoby( 'htmlvar_name', $field_key, $post_type );

		if ( $existing ) {
			return;
		}

		$package_ids = $this->get_package_ids( $post_type );
		$gd_field    = array(
			'post_type'         => $post_type,
			'data_type'         => 'VARCHAR',
			'field_type'        => 'text',
			'htmlvar_name'      => $field_key,
			'admin_title'       => __( 'CSV ID', 'geodir-converter' ),
			'frontend_title'    => __( 'CSV ID', 'geodir-converter' ),
			'frontend_desc'     => __( 'Original CSV row identifier.', 'geodir-converter' ),
			'placeholder_value' => '',
			'default_value'     => '',
			'is_active'         => '1',
			'is_default'        => '0',
			'is_required'       => 0,
			'for_admin_use'     => 1,
			'show_in'           => '',
			'show_on_pkg'       => $package_ids,
			'clabels'           => __( 'CSV ID', 'geodir-converter' ),
			'field_icon'        => 'far fa-id-card',
		);

		geodir_custom_field_save( $gd_field );
	}

	/**
	 * Maybe update field options if CSV value contains multiple options.
	 *
	 * @since 2.3.0
	 * @param string $gd_field The GeoDirectory field key.
	 * @param string $value The CSV value.
	 * @param string $post_type The post type.
	 * @return void
	 */
	private function maybe_update_field_options( $gd_field, $value, $post_type ) {
		if ( empty( $value ) || empty( $gd_field ) ) {
			return;
		}

		$field_info = geodir_get_field_infoby( 'htmlvar_name', $gd_field, $post_type );
		if ( ! $field_info ) {
			return;
		}

		$field_type = isset( $field_info['field_type'] ) ? $field_info['field_type'] : '';
		if ( ! in_array( strtolower( $field_type ), array( 'select', 'radio', 'checkbox', 'multiselect' ), true ) ) {
			return;
		}

		$existing_options = isset( $field_info['option_values'] ) ? $field_info['option_values'] : '';
		if ( empty( $existing_options ) ) {
			$existing_options = array();
		} else {
			if ( strpos( $existing_options, ',' ) !== false ) {
				$existing_options = array_map( 'trim', explode( ',', $existing_options ) );
			} else {
				$existing_options = array_map( 'trim', explode( "\n", $existing_options ) );
			}

			$existing_options = array_filter( $existing_options );
		}

		if ( strpos( $value, ',' ) !== false ) {
			$csv_options = array_map( 'trim', explode( ',', $value ) );
		} elseif ( strpos( $value, "\n" ) !== false ) {
			$csv_options = array_map( 'trim', explode( "\n", $value ) );
		} else {
			$csv_options = array( trim( $value ) );
		}

		$csv_options = array_filter( $csv_options );

		if ( empty( $csv_options ) ) {
			return;
		}

		$new_options = array_diff( $csv_options, $existing_options );

		if ( empty( $new_options ) ) {
			return;
		}

		// Merge existing and new options.
		$merged_options = array_merge( $existing_options, $new_options );
		$merged_options = array_unique( $merged_options );
		$merged_options = array_filter( $merged_options );

		$package_ids = $this->get_package_ids( $post_type );

		$field_info['field_id']      = (int) $field_info['id'];
		$field_info['option_values'] = implode( "\n", $merged_options );
		$field_info['show_on_pkg']   = $package_ids;

		unset( $field_info['id'] );

		$result = geodir_custom_field_save( $field_info );

		if ( ! is_wp_error( $result ) ) {
			$this->log(
				sprintf(
					/* translators: 1: field name, 2: number of new options */
					esc_html__( 'Updated field "%1$s" with %2$d new option(s).', 'geodir-converter' ),
					esc_html( $gd_field ),
					count( $new_options )
				),
				'info'
			);
		}
	}

	/**
	 * Format field value based on field type.
	 *
	 * @since 2.3.0
	 * @param string $gd_field The GeoDirectory field key.
	 * @param string $value The CSV value.
	 * @param string $post_type The post type.
	 * @return string Formatted value.
	 */
	private function format_field_value( $gd_field, $value, $post_type ) {
		if ( empty( $value ) || empty( $gd_field ) ) {
			return $value;
		}

		$field_info = geodir_get_field_infoby( 'htmlvar_name', $gd_field, $post_type );
		if ( ! $field_info ) {
			return $value;
		}

		$field_type = isset( $field_info['field_type'] ) ? $field_info['field_type'] : '';

		if ( 'multiselect' === $field_type ) {
			if ( strpos( $value, ',' ) !== false ) {
				$values = array_map( 'trim', explode( ',', $value ) );
			} elseif ( strpos( $value, "\n" ) !== false ) {
				$values = array_map( 'trim', explode( "\n", $value ) );
			} else {
				$values = array( trim( $value ) );
			}

			$values = array_filter( $values );
			return implode( ',', $values );
		}

		if ( in_array( $field_type, array( 'select', 'radio' ), true ) ) {
			if ( strpos( $value, ',' ) !== false ) {
				$values = array_map( 'trim', explode( ',', $value ) );
				return ! empty( $values ) ? $values[0] : $value;
			}
		}

		return $value;
	}

	/**
	 * Get sample data from first CSV row.
	 *
	 * @since 2.3.0
	 * @param string $file_path CSV file path.
	 * @param string $delimiter CSV delimiter.
	 * @param array  $headers CSV headers.
	 * @return array Array of column => sample_value.
	 */
	public function get_csv_sample_data( $file_path, $delimiter, $headers ) {
		$sample_data = array();

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return $sample_data;
		}

		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			return $sample_data;
		}

		fgetcsv( $handle, 0, $delimiter );
		$row = fgetcsv( $handle, 0, $delimiter );
		fclose( $handle );

		if ( ! empty( $row ) && is_array( $row ) ) {
			foreach ( $headers as $index => $header ) {
				if ( isset( $row[ $index ] ) ) {
					$value = trim( $row[ $index ] );
					if ( ! empty( $value ) ) {
						if ( mb_strlen( $value ) > 150 ) {
							$value = mb_substr( $value, 0, 150 ) . '...';
						}
						$sample_data[ $header ] = $value;
					}
				}
			}
		}

		return $sample_data;
	}

	/**
	 * Get CSV headers from file.
	 *
	 * @since 2.3.0
	 * @param string $file_path CSV file path.
	 * @param string $delimiter CSV delimiter.
	 * @return array|WP_Error Array of headers or WP_Error on failure.
	 */
	public function get_csv_headers( $file_path, $delimiter = ',' ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'CSV file not found or not readable.', 'geodir-converter' ) );
		}

		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			return new WP_Error( 'file_open_failed', __( 'Failed to open CSV file.', 'geodir-converter' ) );
		}

		$headers = fgetcsv( $handle, 0, $delimiter );
		fclose( $handle );

		if ( empty( $headers ) || ! is_array( $headers ) ) {
			return new WP_Error( 'invalid_headers', __( 'CSV file has invalid or missing headers.', 'geodir-converter' ) );
		}

		$headers = array_map( 'trim', $headers );
		$headers = array_filter( $headers );

		return array_values( $headers );
	}

	/**
	 * Count CSV rows (excluding header).
	 *
	 * @since 2.3.0
	 * @param string $file_path CSV file path.
	 * @param string $delimiter CSV delimiter.
	 * @return int Number of rows.
	 */
	public function count_csv_rows( $file_path, $delimiter = ',' ) {
		$count  = 0;
		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			return 0;
		}

		fgetcsv( $handle, 0, $delimiter );

		while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			if ( ! empty( array_filter( $row ) ) ) {
				++$count;
			}
		}

		fclose( $handle );
		return $count;
	}

	/**
	 * Get date format options.
	 *
	 * @since 2.3.0
	 * @return array Date format options.
	 */
	private function get_date_format_options() {
		return array(
			'auto'        => __( 'Auto-detect', 'geodir-converter' ),
			'Y-m-d'       => 'YYYY-MM-DD (2024-12-01)',
			'm/d/Y'       => 'MM/DD/YYYY (12/01/2024)',
			'd/m/Y'       => 'DD/MM/YYYY (01/12/2024)',
			'd-m-Y'       => 'DD-MM-YYYY (01-12-2024)',
			'Y/m/d'       => 'YYYY/MM/DD (2024/12/01)',
			'm-d-Y'       => 'MM-DD-YYYY (12-01-2024)',
			'd.m.Y'       => 'DD.MM.YYYY (01.12.2024)',
			'Y-m-d H:i:s' => 'YYYY-MM-DD HH:MM:SS (2024-12-01 14:30:00)',
			'm/d/Y H:i'   => 'MM/DD/YYYY HH:MM (12/01/2024 14:30)',
			'd/m/Y H:i'   => 'DD/MM/YYYY HH:MM (01/12/2024 14:30)',
		);
	}

	/**
	 * Get encoding options.
	 *
	 * @since 2.3.0
	 * @return array Encoding options.
	 */
	private function get_encoding_options() {
		return array(
			'auto'         => __( 'Auto-detect', 'geodir-converter' ),
			'UTF-8'        => 'UTF-8',
			'UTF-8-BOM'    => 'UTF-8 with BOM',
			'ISO-8859-1'   => 'ISO-8859-1 (Latin-1)',
			'Windows-1252' => 'Windows-1252',
			'ASCII'        => 'ASCII',
		);
	}

	/**
	 * Auto-detect date format from sample data.
	 *
	 * @since 2.3.0
	 * @param array $sample_data Sample data from CSV.
	 * @return string Detected date format or 'auto' if not detected.
	 */
	private function detect_date_format( $sample_data ) {
		$date_formats = array(
			'Y-m-d'       => '/^\d{4}-\d{2}-\d{2}$/',
			'm/d/Y'       => '/^\d{2}\/\d{2}\/\d{4}$/',
			'd/m/Y'       => '/^\d{2}\/\d{2}\/\d{4}$/',
			'd-m-Y'       => '/^\d{2}-\d{2}-\d{4}$/',
			'Y/m/d'       => '/^\d{4}\/\d{2}\/\d{2}$/',
			'm-d-Y'       => '/^\d{2}-\d{2}-\d{4}$/',
			'd.m.Y'       => '/^\d{2}\.\d{2}\.\d{4}$/',
			'Y-m-d H:i:s' => '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			'm/d/Y H:i'   => '/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/',
			'd/m/Y H:i'   => '/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/',
		);

		foreach ( $sample_data as $value ) {
			if ( empty( $value ) ) {
				continue;
			}

			// Try to find a date-like value.
			foreach ( $date_formats as $format => $pattern ) {
				if ( preg_match( $pattern, trim( $value ) ) ) {
					// Verify it's actually a valid date.
					$date_obj = \DateTime::createFromFormat( $format, trim( $value ) );
					if ( $date_obj && trim( $value ) === $date_obj->format( $format ) ) {
						return $format;
					}
				}
			}

			// Try strtotime as fallback.
			$timestamp = strtotime( trim( $value ) );
			if ( false !== $timestamp && 0 < $timestamp ) {
				// Try to determine format from parsed date.
				$parsed = date_parse( trim( $value ) );
				if ( $parsed && ! empty( $parsed['year'] ) ) {
					// Common formats based on separator.
					if ( false !== strpos( $value, '-' ) ) {
						if ( 4 === strlen( $parsed['year'] ) && '2' === $parsed['year'][0] ) {
							return 'Y-m-d';
						}
					} elseif ( false !== strpos( $value, '/' ) ) {
						if ( 4 === strlen( $parsed['year'] ) && '2' === $parsed['year'][0] ) {
							// Check if month comes first (US format).
							if ( 12 >= $parsed['month'] && 31 >= $parsed['day'] ) {
								return 'm/d/Y';
							}
							return 'd/m/Y';
						}
					}
				}
			}
		}

		return 'auto';
	}

	/**
	 * Detect CSV file encoding.
	 *
	 * @since 2.3.0
	 * @param string $file_path CSV file path.
	 * @return string Detected encoding.
	 */
	private function detect_csv_encoding( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return 'UTF-8';
		}

		$content = file_get_contents( $file_path, false, null, 0, 10000 );

		// Check for BOM.
		if ( "\xEF\xBB\xBF" === substr( $content, 0, 3 ) ) {
			return 'UTF-8-BOM';
		}

		// Use mb_detect_encoding if available.
		if ( function_exists( 'mb_detect_encoding' ) ) {
			$detected = mb_detect_encoding( $content, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII' ), true );
			if ( $detected ) {
				return $detected;
			}
		}

		// Check if content is valid UTF-8.
		if ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $content, 'UTF-8' ) ) {
			return 'UTF-8';
		}

		// Default fallback.
		return 'UTF-8';
	}

	/**
	 * Convert date string to WordPress format.
	 *
	 * @since 2.3.0
	 * @param string $date_string The date string from CSV.
	 * @param string $format      The date format.
	 * @return string WordPress formatted date or original string on failure.
	 */
	private function convert_date_format( $date_string, $format ) {
		if ( empty( $date_string ) || empty( $format ) || 'auto' === $format ) {
			// Try strtotime as fallback for auto.
			if ( 'auto' === $format ) {
				$timestamp = strtotime( $date_string );
				if ( false !== $timestamp && $timestamp > 0 ) {
					return date( 'Y-m-d H:i:s', $timestamp );
				}
			}
			return $date_string;
		}

		$timestamp = false;

		if ( 'auto' !== $format ) {
			$date_obj = \DateTime::createFromFormat( $format, $date_string );
			if ( $date_obj ) {
				$timestamp = $date_obj->getTimestamp();
			}
		}

		if ( false === $timestamp ) {
			$timestamp = strtotime( $date_string );
		}

		if ( false === $timestamp ) {
			return $date_string;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Get saved mapping templates.
	 *
	 * @since 2.3.0
	 * @return array Saved templates.
	 */
	private function get_saved_templates() {
		$templates = $this->options_handler->get_option( 'csv_mapping_templates', array() );
		return is_array( $templates ) ? $templates : array();
	}

	/**
	 * Save mapping template.
	 *
	 * @since 2.3.0
	 * @param string $name    Template name.
	 * @param array  $mapping Column mapping.
	 * @return array|WP_Error Array with template_id and template_name on success or WP_Error on failure.
	 */
	public function save_mapping_template( $name, $mapping ) {
		if ( empty( $name ) || empty( $mapping ) ) {
			return new WP_Error( 'invalid_template', __( 'Template name and mapping are required.', 'geodir-converter' ) );
		}

		$templates   = $this->get_saved_templates();
		$template_id = sanitize_key( $name ) . '_' . time();

		$templates[ $template_id ] = array(
			'name'    => sanitize_text_field( $name ),
			'mapping' => $mapping,
			'created' => gmdate( 'Y-m-d H:i:s' ),
		);

		$this->options_handler->update_option( 'csv_mapping_templates', $templates );

		/**
		 * Action fired after saving a mapping template.
		 *
		 * @since 2.3.0
		 * @param string $template_id The template ID.
		 * @param string $name         The template name.
		 * @param array  $mapping     The mapping data.
		 */
		do_action( 'geodir_converter_csv_template_saved', $template_id, $name, $mapping );

		return array(
			'template_id'   => $template_id,
			'template_name' => sanitize_text_field( $name ),
		);
	}

	/**
	 * Load mapping template.
	 *
	 * @since 2.3.0
	 * @param string $template_id Template ID.
	 * @return array|WP_Error Template data or WP_Error on failure.
	 */
	public function load_mapping_template( $template_id ) {
		$templates = $this->get_saved_templates();

		if ( ! isset( $templates[ $template_id ] ) ) {
			return new WP_Error( 'template_not_found', __( 'Template not found.', 'geodir-converter' ) );
		}

		/**
		 * Filter template data before loading.
		 *
		 * @since 2.3.0
		 * @param array  $template_data The template data.
		 * @param string $template_id   The template ID.
		 */
		$template = apply_filters( 'geodir_converter_csv_template_data', $templates[ $template_id ], $template_id );

		return $template;
	}

	/**
	 * Delete mapping template.
	 *
	 * @since 2.3.0
	 * @param string $template_id Template ID.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	public function delete_mapping_template( $template_id ) {
		$templates = $this->get_saved_templates();

		if ( ! isset( $templates[ $template_id ] ) ) {
			return new WP_Error( 'template_not_found', __( 'Template not found.', 'geodir-converter' ) );
		}

		unset( $templates[ $template_id ] );
		$this->options_handler->update_option( 'csv_mapping_templates', $templates );

		/**
		 * Action fired after deleting a mapping template.
		 *
		 * @since 2.3.0
		 * @param string $template_id The template ID.
		 */
		do_action( 'geodir_converter_csv_template_deleted', $template_id );

		return true;
	}
}
