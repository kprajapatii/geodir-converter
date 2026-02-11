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
 * GeoDir_Converter_Admin_Leads Class.
 */
class GeoDir_Converter_Admin {
	use GeoDir_Converter_Trait_Singleton;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 */
	private function register_hooks() {
		add_action( 'admin_menu', array( $this, 'admin_menus' ) );
		add_filter( 'aui_screen_ids', array( $this, 'screen_ids' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 1 );
	}

	/**
	 * Add the admin menus
	 *
	 * @since 1.0.0
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
	 * @param array $screen_ids
	 * @return array
	 */
	public function screen_ids( $screen_ids = array() ) {
		$screen_ids[] = 'tools_page_geodir-converter';
		return $screen_ids;
	}

	/**
	 * Register admin scripts
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
				<h1 class="h2"><?php esc_html_e( 'Import Listings', 'geodir-converter' ); ?></h1>
				<p class="fs-base"><?php esc_html_e( 'Import listings from another website or platform.', 'geodir-converter' ); ?></p>

				<?php if ( empty( $default_location ) || ( empty( $default_location->city ) && empty( $default_location->region ) && empty( $default_location->country ) ) ) : ?>
					<div class="notice notice-error notice-large me-0 ms-0 mb-3">
						<p class="mb-0">
							<?php esc_html_e( "Don't forget to set up your default GeoDirectory listing location before running this tool!", 'geodir-converter' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<div class="card border-0 shadow-sm p-0 mb-4 mw-100">
					<div class="card-header bg-white">
						<h6 class="h6 mb-0 text-dark py-0"><?php esc_html_e( 'I want to import listings from:', 'geodir-converter' ); ?></h6>
					</div>

					<div class="card-body pt-2 pb-4">
						<?php if ( ! empty( $importers ) ) : ?>
							<div class="list-group list-group-flush list-group-hoverable">
								<?php
								foreach ( $importers as $importer_id => $importer ) :
									$in_progress = $importer->background_process->is_in_progress();
									$btn_class   = $in_progress ? 'btn-translucent-success' : 'btn-outline-primary';
									?>
									<div class="list-group-item geodir-converter-importer" 
										data-importer="<?php echo esc_attr( $importer_id ); ?>" 
										data-progress="<?php echo (int) $in_progress; ?>">
										<div class="row align-items-center">
											<div class="col-auto">
												<img class="geodir-converter-icon" src="<?php echo esc_url( $importer->get_icon() ); ?>" alt="<?php esc_attr_e( 'Importer Icon', 'geodir-converter' ); ?>"/>
											</div>
											<div class="col text-truncate">
												<h6 class="text-reset fs-lg mb-1 d-block"><?php echo esc_html( $importer->get_title() ); ?></h6>
												<p class="d-block text-secondary text-truncate mb-0"><?php echo esc_html( $importer->get_description() ); ?></p>
											</div>
											<div class="col-auto">
												<button class="btn <?php echo esc_attr( $btn_class ); ?> btn-sm list-group-item-actions geodir-converter-configure">
													<?php echo $in_progress ? esc_html__( 'Importing...', 'geodir-converter' ) : esc_html__( 'Run Converter', 'geodir-converter' ); ?>
												</button>

												<button class="btn btn-gray-dark btn-sm list-group-item-actions geodir-converter-back d-none">
													<i class="fa fa-arrow-left"></i> <?php echo esc_html__( 'Back', 'geodir-converter' ); ?>
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
							<p class="pt-5 pb-5 mb-0 fs-base text-center text-body">
								<?php esc_html_e( 'No importers available.', 'geodir-converter' ); ?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>

		<?php
	}
}
