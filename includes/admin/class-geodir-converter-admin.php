<?php
/**
 * GeoDir Converter Admin Leads
 *
 * @since      2.0.2
 * @package    GeoDir_Converter
 * @version    2.0.2
 */

namespace GeoDir_Converter\Admin;

use GeoDir_Converter\GeoDir_Converter;
use GeoDir_Converter\GeoDir_Converter_Ajax;
use GeoDir_Converter\Traits\GeoDir_Converter_Trait_Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * GeoDir_Converter_Admin class.
 *
 * Handles the admin page and assets for the GeoDir Converter plugin.
 *
 * @since 2.0.2
 */
class GeoDir_Converter_Admin {
	use GeoDir_Converter_Trait_Singleton;

	/**
	 * Constructor.
	 *
	 * @since 2.0.2
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'admin_menu', array( $this, 'admin_menus' ) );
		add_filter( 'aui_screen_ids', array( $this, 'screen_ids' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 1 );
	}

	/**
	 * Add the admin menus.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function admin_menus() {
		add_submenu_page(
			'tools.php',
			esc_html__( 'GeoDirectory Converter', 'geodir-converter' ),
			esc_html__( 'GeoDirectory Converter', 'geodir-converter' ),
			'manage_options',
			'geodir-converter',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Tell AyeCode UI to load on certain admin pages.
	 *
	 * @since 2.0.2
	 *
	 * @param array $screen_ids Array of screen IDs where AyeCode UI should load.
	 * @return array Modified array of screen IDs.
	 */
	public function screen_ids( $screen_ids = array() ) {
		$screen_ids[] = 'tools_page_geodir-converter';
		return $screen_ids;
	}

	/**
	 * Register and enqueue admin scripts and styles.
	 *
	 * @since 2.0.2
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'tools_page_geodir-converter' !== $hook ) {
			return;
		}

		$script_version = GeoDir_Converter::instance()->get_script_version();
		$suffix         = GeoDir_Converter::instance()->get_script_suffix();
		$nonces         = GeoDir_Converter_Ajax::instance()->get_nonces();

		wp_enqueue_style( 'geodir-converter-admin', GEODIR_CONVERTER_PLUGIN_URL . "assets/css/admin{$suffix}.css", array(), $script_version );

		wp_enqueue_script( 'geodir-converter-admin', GEODIR_CONVERTER_PLUGIN_URL . "assets/js/admin{$suffix}.js", array( 'jquery' ), $script_version, true );
		wp_localize_script(
			'geodir-converter-admin',
			'GeoDir_Converter',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => $nonces,
				'actions' => array(
					'import'               => 'geodir_converter_import',
					'progress'             => 'geodir_converter_progress',
					'abort'                => 'geodir_converter_abort',
					'pause'                => 'geodir_converter_pause',
					'resume'               => 'geodir_converter_resume',
					'retry_failed'         => 'geodir_converter_retry_failed',
					'upload'               => 'geodir_converter_upload',
					'csv_parse'            => 'geodir_converter_csv_parse',
					'csv_get_fields'       => 'geodir_converter_csv_get_fields',
					'csv_refresh_fields'   => 'geodir_converter_csv_refresh_fields',
					'csv_get_mapping_step' => 'geodir_converter_csv_get_mapping_step',
					'csv_clear_file'       => 'geodir_converter_csv_clear_file',
					'csv_save_template'    => 'geodir_converter_csv_save_template',
					'csv_load_template'    => 'geodir_converter_csv_load_template',
					'csv_delete_template'  => 'geodir_converter_csv_delete_template',
				),
				'i18n'    => array(
					'selectImport'            => __( 'I want to import listings from:', 'geodir-converter' ),
					'importSource'            => __( 'Import listings from:', 'geodir-converter' ),
					'runConverter'            => __( 'Run Converter', 'geodir-converter' ),
					'loading'                 => __( 'Loading...', 'geodir-converter' ),
					'import'                  => __( 'Start Import', 'geodir-converter' ),
					'importing'               => __( 'Importing...', 'geodir-converter' ),
					'abort'                   => __( 'Abort', 'geodir-converter' ),
					'aborting'                => __( 'Aborting...', 'geodir-converter' ),
					'pause'                   => __( 'Pause', 'geodir-converter' ),
					'pausing'                 => __( 'Pausing...', 'geodir-converter' ),
					'resume'                  => __( 'Resume', 'geodir-converter' ),
					'resuming'                => __( 'Resuming...', 'geodir-converter' ),
					'paused'                  => __( 'Paused', 'geodir-converter' ),
					'retryFailed'             => __( 'Retry Failed', 'geodir-converter' ),
					'retrying'                => __( 'Retrying...', 'geodir-converter' ),
					'uploading'               => __( 'Uploading...', 'geodir-converter' ),
					'selectField'             => __( 'Select a field...', 'geodir-converter' ),
					'failedLoadMapping'       => __( 'Failed to load mapping step.', 'geodir-converter' ),
					'failedRefreshFields'     => __( 'Failed to refresh fields.', 'geodir-converter' ),
					'failedClearFile'         => __( 'Failed to clear file. Please refresh the page.', 'geodir-converter' ),
					'fileUploadSuccess'       => __( 'File uploaded successfully', 'geodir-converter' ),
					'uploadFailed'            => __( 'Upload failed: ', 'geodir-converter' ),
					'unknownError'            => __( 'Unknown error', 'geodir-converter' ),
					'serverErrorUpload'       => __( 'Server error during upload.', 'geodir-converter' ),
					'templateNameRequired'    => __( 'Template name is required.', 'geodir-converter' ),
					'templateMappingRequired' => __( 'Please map at least one field before saving.', 'geodir-converter' ),
					'templateSaved'           => __( 'Template saved successfully.', 'geodir-converter' ),
					'templateSaveFailed'      => __( 'Failed to save template.', 'geodir-converter' ),
					'templateSelectRequired'  => __( 'Please select a template.', 'geodir-converter' ),
					'templateLoaded'          => __( 'Template loaded successfully.', 'geodir-converter' ),
					'templateLoadFailed'      => __( 'Failed to load template.', 'geodir-converter' ),
					'templateDeleted'         => __( 'Template deleted successfully.', 'geodir-converter' ),
					'templateDeleteFailed'    => __( 'Failed to delete template.', 'geodir-converter' ),
					'templateDeleteConfirm'   => __( 'Are you sure you want to delete this template?', 'geodir-converter' ),
					'loadTemplate'            => __( 'Load Template', 'geodir-converter' ),
					'saveCurrentMapping'      => __( 'Save Current Mapping', 'geodir-converter' ),
					'chooseTemplate'          => __( 'Choose a saved template...', 'geodir-converter' ),
					'loadSelectedTemplate'    => __( 'Load selected template', 'geodir-converter' ),
					'deleteSelectedTemplate'  => __( 'Delete selected template', 'geodir-converter' ),
					'enterTemplateName'       => __( 'Enter template name...', 'geodir-converter' ),
					'save'                    => __( 'Save', 'geodir-converter' ),
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @since 2.0.2
	 */
	public function render_admin_page() {
		global $geodirectory;

		$importers        = GeoDir_Converter::instance()->get_importers();
		$default_location = $geodirectory->location->get_default_location();

		?>
		<div class="bsui">
			<div class="geodir-converter-wrapper mt-5 me-auto ms-auto">
				<div class="geodir-converter-page-header mb-4">
					<h1 class="h2 mb-1"><?php esc_html_e( 'Import Listings', 'geodir-converter' ); ?></h1>
					<p class="text-muted mb-0"><?php esc_html_e( 'Import listings from another website or platform.', 'geodir-converter' ); ?></p>
				</div>

				<?php if ( empty( $default_location ) || ( empty( $default_location->city ) && empty( $default_location->region ) && empty( $default_location->country ) ) ) : ?>
					<div class="alert alert-warning d-flex align-items-start me-0 ms-0 mb-3" role="alert">
						<i class="fas fa-exclamation-triangle me-2 mt-1" style="font-size: 16px;"></i>
						<div>
							<?php esc_html_e( "Don't forget to set up your default GeoDirectory listing location before running this tool!", 'geodir-converter' ); ?>
						</div>
					</div>
				<?php endif; ?>

				<div class="card border-0 shadow-sm p-0 mb-4 mw-100 geodir-converter-card">
					<div class="card-header bg-white d-flex align-items-center">
						<i class="fas fa-exchange-alt text-primary me-2"></i>
						<h6 class="h6 mb-0 text-dark py-0"><?php esc_html_e( 'I want to import listings from:', 'geodir-converter' ); ?></h6>
					</div>

					<div class="card-body pt-0 pb-3">
						<?php if ( ! empty( $importers ) ) : ?>
							<div class="list-group list-group-flush list-group-hoverable">
								<?php
								foreach ( $importers as $importer_id => $importer ) :
									$in_progress = $importer->background_process->is_in_progress();
									$is_paused   = $importer->background_process->is_paused();
									$is_active   = $in_progress || $is_paused;
									$progress    = $is_active ? (int) $importer->get_progress() : 0;
									$stats       = $is_active ? $importer->get_stats() : array();

									if ( $is_paused ) {
										$btn_class = 'btn-translucent-warning';
										$btn_text  = esc_html__( 'Paused', 'geodir-converter' );
									} elseif ( $in_progress ) {
										$btn_class = 'btn-translucent-success';
										$btn_text  = esc_html__( 'Importing...', 'geodir-converter' );
									} else {
										$btn_class = 'btn-outline-primary';
										$btn_text  = esc_html__( 'Run Converter', 'geodir-converter' );
									}
									?>
									<div class="list-group-item geodir-converter-importer"
										data-importer="<?php echo esc_attr( $importer_id ); ?>"
										data-progress="<?php echo (int) $is_active; ?>">
										<div class="row align-items-center">
											<div class="col-auto">
												<div class="geodir-converter-icon-wrapper">
													<img class="geodir-converter-icon" src="<?php echo esc_url( $importer->get_icon() ); ?>" alt="<?php esc_attr_e( 'Importer Icon', 'geodir-converter' ); ?>"/>
												</div>
											</div>
											<div class="col text-truncate">
												<h6 class="text-reset fs-lg mb-1 d-block"><?php echo esc_html( $importer->get_title() ); ?></h6>
												<p class="d-block text-secondary text-truncate mb-0" style="font-size: 13px;"><?php echo esc_html( $importer->get_description() ); ?></p>
												<div class="geodir-converter-mini-progress mt-2 <?php echo $is_active ? '' : 'd-none'; ?>">
													<div class="progress" style="height: 4px;">
														<div class="progress-bar progress-bar-striped <?php echo $is_paused ? '' : 'progress-bar-animated'; ?>" role="progressbar" style="width: <?php echo esc_attr( $progress ); ?>%;" aria-valuenow="<?php echo esc_attr( $progress ); ?>" aria-valuemin="0" aria-valuemax="100"></div>
													</div>
													<?php if ( ! empty( $stats ) && ! empty( $stats['total'] ) ) : ?>
														<div class="d-flex justify-content-between mt-1 geodir-converter-mini-info" style="font-size: 11px;">
															<span class="text-muted geodir-converter-mini-count"><?php echo esc_html( ( $stats['succeed'] + $stats['skipped'] + $stats['failed'] ) . ' / ' . $stats['total'] ); ?></span>
															<span class="text-muted geodir-converter-mini-percent"><?php echo esc_html( $progress . '%' ); ?></span>
														</div>
													<?php endif; ?>
												</div>
											</div>
											<div class="col-auto">
												<button class="btn <?php echo esc_attr( $btn_class ); ?> btn-sm list-group-item-actions geodir-converter-configure">
													<?php echo $btn_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</button>

												<button class="btn btn-gray-dark btn-sm list-group-item-actions geodir-converter-back d-none">
													<i class="fas fa-arrow-left me-1"></i><?php echo esc_html__( 'Back', 'geodir-converter' ); ?>
												</button>
											</div>
										</div>
										<div class="geodir-converter-settings d-none pt-3 mt-4 border-top border-gray">
											<?php $importer->render_settings(); ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<div class="text-center py-5">
								<i class="fas fa-inbox text-muted mb-3" style="font-size: 48px;"></i>
								<p class="mb-0 fs-base text-muted">
									<?php esc_html_e( 'No importers available.', 'geodir-converter' ); ?>
								</p>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>

		<?php
	}
}
