<?php
/**
 * PhpMyDirectory Converter Class.
 *
 * @since      2.0.2
 * @package    GeoDir_Converter
 * @version    2.0.2
 */

namespace GeoDir_Converter\Importers;

use WP_Error;
use WP_User;
use Exception;
use GeoDir_Media;
use WPInv_Invoice;
use WPInv_Discount;
use GeoDir_Comments;
use GetPaid_Form_Item;
use GeoDir_Pricing_Package;
use GeoDir_Converter\GeoDir_Converter_WPDB;
use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Main converter class for importing from PhpMyDirectory.
 *
 * @since 2.0.2
 */
class GeoDir_Converter_PMD extends GeoDir_Converter_Importer {
	/**
	 * Action identifier for importing users.
	 *
	 * @var string
	 */
	private const ACTION_IMPORT_USERS = 'import_users';

	/**
	 * Action identifier for importing blog categories.
	 *
	 * @var string
	 */
	private const ACTION_IMPORT_BLOG_CATEGORIES = 'import_blog_categories';

	/**
	 * Action identifier for importing event categories.
	 *
	 * @var string
	 */
	private const ACTION_IMPORT_EVENTS_CATEGORIES = 'import_events_categories';

	/**
	 * Action identifier for importing invoices.
	 *
	 * @var string
	 */
	private const ACTION_IMPORT_INVOICES = 'import_invoices';

	/**
	 * Action identifier for importing discounts.
	 *
	 * @var string
	 */
	private const ACTION_IMPORT_DISCOUNTS = 'import_discounts';

	/**
	 * Action identifier for importing products.
	 *
	 * @var string
	 */
	private const ACTION_IMPORT_PRODUCTS = 'import_products';

	/**
	 * Action identifier for importing reviews.
	 *
	 * @var string
	 */
	private const ACTION_IMPORT_REVIEWS = 'import_reviews';

	/**
	 * Action identifier for importing events.
	 *
	 * @var string
	 */
	private const ACTION_IMPORT_EVENTS = 'import_events';

	/**
	 * Action identifier for importing comments.
	 *
	 * @var string
	 */
	private const ACTION_IMPORT_COMMENTS = 'import_comments';

	/**
	 * Action identifier for importing posts.
	 *
	 * @var string
	 */
	private const ACTION_IMPORT_POSTS = 'import_posts';

	/**
	 * Action identifier for importing pages.
	 *
	 * @var string
	 */
	private const ACTION_IMPORT_PAGES = 'import_pages';

	/**
	 * Post type identifier for events.
	 *
	 * @var string
	 */
	private const POST_TYPE_EVENTS = 'gd_event';

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
	protected $importer_id = 'pmd';

	/**
	 * Database connection instance.
	 *
	 * @var GeoDir_Converter_WPDB|null
	 */
	private $db_connection = null;

	/**
	 * Database table prefix.
	 *
	 * @var string
	 */
	private $db_prefix = '';

	/**
	 * PMD site URL.
	 *
	 * @var string
	 */
	private $url = '';

	/**
	 * Initialize hooks.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	protected function init() {
		$this->url = $this->get_import_setting( 'site_url' );

		// Handle logins for imported users.
		add_filter( 'wp_authenticate_user', array( $this, 'handle_user_login' ), 10, 2 );
	}

	/**
	 * Get importer title.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer title.
	 */
	public function get_title() {
		return __( 'PhpMyDirectory', 'geodir-converter' );
	}

	/**
	 * Get importer description.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer description.
	 */
	public function get_description() {
		return __( 'Import listings, events, users and invoices from your PhpMyDirectory installation.', 'geodir-converter' );
	}

	/**
	 * Get importer icon URL.
	 *
	 * @since 2.0.2
	 *
	 * @return string The importer icon URL.
	 */
	public function get_icon() {
		return GEODIR_CONVERTER_PLUGIN_URL . 'assets/images/pmd.jpeg';
	}

	/**
	 * Get importer task action.
	 *
	 * @since 2.0.2
	 *
	 * @return string The first import action identifier.
	 */
	public function get_action() {
		return self::ACTION_IMPORT_USERS;
	}

	/**
	 * Render importer settings.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function render_settings() {
		$users       = count_users();
		$total_users = isset( $users['total_users'] ) ? (int) $users['total_users'] : 0;
		?>
		<form class="geodir-converter-settings-form" method="post">
			<h6 class="fs-base"><?php esc_html_e( 'PhpMyDirectory Connection Settings', 'geodir-converter' ); ?></h6>

			<?php
			if ( ! defined( 'WPINV_VERSION' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'Invoicing', 'geodir-converter' ),
					'invoices',
					esc_url( 'https://wordpress.org/plugins/invoicing' )
				);
			}

			if ( ! defined( 'GEODIR_EVENT_VERSION' ) ) {
				$this->render_plugin_notice(
					esc_html__( 'Events Addon', 'geodir-converter' ),
					'events',
					esc_url( 'https://wpgeodirectory.com/downloads/events/' )
				);
			}

			$this->display_post_type_select();
			$this->display_form_fields();
			$this->display_test_mode_checkbox();
			$this->display_progress();
			$this->display_logs( $this->get_logs() );
			$this->display_error_alert();
			?>

			<?php $this->display_action_buttons(); ?>
		</form>
		<?php
	}

	/**
	 * Render form fields for the importer settings.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	private function display_form_fields() {
		$import_settings  = (array) $this->options_handler->get_option( 'import_settings', array() );
		$default_settings = array(
			'site_url'          => '',
			'database_host'     => 'localhost',
			'database_name'     => 'pmd',
			'database_user'     => 'root',
			'database_password' => '',
			'database_prefix'   => 'pmd_',
		);

		$settings = wp_parse_args( array_map( 'sanitize_text_field', $import_settings ), $default_settings );

		// Define fields.
		$fields = array(
			'site_url'          => array(
				'label'       => __( 'PMD Root URL', 'geodir-converter' ),
				'type'        => 'url',
				'placeholder' => 'https://mysite.com/',
				'value'       => $settings['site_url'],
				'required'    => true,
			),
			'database_host'     => array(
				'label'    => __( 'Database Host Name', 'geodir-converter' ),
				'type'     => 'text',
				'value'    => $settings['database_host'],
				'required' => true,
			),
			'database_name'     => array(
				'label'    => __( 'Database Name', 'geodir-converter' ),
				'type'     => 'text',
				'value'    => $settings['database_name'],
				'required' => true,
			),
			'database_user'     => array(
				'label'    => __( 'Database Username', 'geodir-converter' ),
				'type'     => 'text',
				'value'    => $settings['database_user'],
				'required' => true,
			),
			'database_password' => array(
				'label' => __( 'Database Password', 'geodir-converter' ),
				'type'  => 'password',
				'value' => $settings['database_password'],
			),
			'database_prefix'   => array(
				'label' => __( 'Table Prefix', 'geodir-converter' ),
				'type'  => 'text',
				'value' => $settings['database_prefix'],
			),
		);

		echo '<div class="row">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $fields as $id => $field ) {
			aui()->input(
				array(
					'id'          => esc_attr( $id ),
					'name'        => esc_attr( $id ),
					'type'        => esc_attr( $field['type'] ),
					'placeholder' => isset( $field['placeholder'] ) ? esc_attr( $field['placeholder'] ) : '',
					'label'       => esc_html( $field['label'] ),
					'label_type'  => 'top',
					'value'       => esc_attr( $field['value'] ),
					'required'    => isset( $field['required'] ) ? $field['required'] : false,
					'wrap_class'  => 'col-md-6',
				),
				true
			);
		}
		echo '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Validate importer settings.
	 *
	 * @since 2.0.2
	 *
	 * @param array $settings The settings to validate.
	 * @param array $files    The files to validate.
	 * @return array|WP_Error Validated and sanitized settings or WP_Error on failure.
	 */
	public function validate_settings( array $settings, array $files = array() ) {
		$errors = array();

		$settings['site_url']        = isset( $settings['site_url'] ) ? esc_url_raw( $settings['site_url'] ) : '';
		$settings['database_host']   = isset( $settings['database_host'] ) ? sanitize_text_field( $settings['database_host'] ) : '';
		$settings['database_name']   = isset( $settings['database_name'] ) ? sanitize_text_field( $settings['database_name'] ) : '';
		$settings['database_user']   = isset( $settings['database_user'] ) ? sanitize_text_field( $settings['database_user'] ) : '';
		$settings['database_prefix'] = isset( $settings['database_prefix'] ) ? sanitize_text_field( $settings['database_prefix'] ) : '';

		// Validate and sanitize site URL.
		if ( empty( $settings['site_url'] ) ) {
			$errors[] = esc_html__( 'PMD root URL is required.', 'geodir-converter' );
		}

		if ( ! wp_http_validate_url( $settings['site_url'] ) ) {
			$errors[] = esc_html__( 'Invalid PMD root URL.', 'geodir-converter' );
		}

		// Validate and sanitize database host.
		if ( empty( $settings['database_host'] ) ) {
			$errors[] = esc_html__( 'Database host is required.', 'geodir-converter' );
		}

		// Validate and sanitize database name.
		if ( empty( $settings['database_name'] ) ) {
			$errors[] = esc_html__( 'Database name is required.', 'geodir-converter' );
		}

		// Validate and sanitize database user.
		if ( empty( $settings['database_user'] ) ) {
			$errors[] = esc_html__( 'Database username is required.', 'geodir-converter' );
		}

		// Validate and sanitize database password.
		if ( empty( $settings['database_password'] ) ) {
			$errors[] = esc_html__( 'Database password is required.', 'geodir-converter' );
		}

		// If there are no errors, try to establish a database connection.
		if ( empty( $errors ) ) {
			$connection_result = $this->test_database_connection( $settings );
			if ( is_wp_error( $connection_result ) ) {
				$errors[] = $connection_result->get_error_message();
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'invalid_import_settings', implode( '<br>', $errors ) );
		}

		return $settings;
	}

	/**
	 * Test the database connection and required tables.
	 *
	 * @since 2.0.2
	 *
	 * @param array $settings Validated settings.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function test_database_connection( $settings ) {
		$this->options_handler->update_option( 'import_settings', $settings );

		// Reset the connection to force a new connection with new settings.
		$this->db_connection = null;

		// Get database connection.
		$db_connection = $this->get_db_connection();

		// Check if we got an error instead of a connection.
		if ( is_wp_error( $db_connection ) ) {
			if ( $db_connection->get_error_code() === 'db_connect_fail' ) {
				return new WP_Error(
					'db_connect_fail',
					sprintf(
					/* translators: %s is the database error message */
						'<h6 class="mb-2">%s</h6> 
                    <ul class="ps-0">
                        <li>%s</li>
                        <li>%s</li>
                        <li>%s</li>
                    </ul>
                    <strong class="mb-0">%s</strong>',
						esc_html__( 'Database connection failed', 'geodir-converter' ),
						esc_html__( 'Are you sure you have the correct username and password?', 'geodir-converter' ),
						esc_html__( 'Are you sure you have typed the correct hostname?', 'geodir-converter' ),
						esc_html__( 'Are you sure the database server is running?', 'geodir-converter' ),
						esc_html( mysqli_connect_error() ) // Display actual DB error.
					)
				);
			}

			return $db_connection;
		}

		// Required tables to check.
		$required_tables = array(
			'users',
			'users_groups_lookup',
			'categories',
			'images',
			'listings',
			'fields',
			'invoices',
			'discount_codes',
			'products',
			'events',
			'events_categories',
			'blog',
			'blog_comments',
			'pages',
			'locations',
			'orders',
			'reviews',
			'ratings',
		);

		// Check if the required tables exist.
		$missing_tables = array();
		foreach ( $required_tables as $table ) {
			$table_name = $settings['database_prefix'] . $table;
			$query      = $db_connection->prepare( 'SHOW TABLES LIKE %s', $db_connection->esc_like( $table_name ) );

			if ( ! $db_connection->get_var( $query ) ) {
				$missing_tables[] = $table_name;
			}
		}

		if ( ! empty( $missing_tables ) ) {
			return new WP_Error(
				'tables_not_found',
				sprintf(
					'<h6 class="mb-2">%s</h6> <p class="mb-0">%s</p>',
					esc_html__( 'Required tables are missing', 'geodir-converter' ),
					esc_html( implode( ', ', $missing_tables ) )
				)
			);
		}

		return true;
	}

	/**
	 * Get or create the database connection.
	 *
	 * @since 2.0.2
	 *
	 * @return GeoDir_Converter_WPDB|WP_Error The database connection instance or WP_Error on failure.
	 */
	protected function get_db_connection() {
		try {
			if ( $this->db_connection === null ) {
				$settings = $this->options_handler->get_option( 'import_settings', array() );

				if ( empty( $settings['database_user'] ) || empty( $settings['database_password'] ) ||
					empty( $settings['database_name'] ) || empty( $settings['database_host'] ) ) {
					return new WP_Error( 'invalid_db_settings', __( 'Invalid database settings', 'geodir-converter' ) );
				}

				$this->db_connection = new GeoDir_Converter_WPDB(
					$settings['database_user'],
					$settings['database_password'],
					$settings['database_name'],
					$settings['database_host']
				);

				$this->db_prefix = isset( $settings['database_prefix'] ) ? $settings['database_prefix'] : '';

				$this->db_connection->hide_errors();
				$this->db_connection->db_connect();

				if ( is_wp_error( $this->db_connection->error ) ) {
					return $this->db_connection->error;
				}
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'db_connect_fail', $e->getMessage() );
		}

		return $this->db_connection;
	}

	/**
	 * Get next task.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task The current task.
	 * @return array|false The next task or false if all tasks are completed.
	 */
	public function next_task( $task ) {
		$task['imported'] = 0;
		$task['failed']   = 0;
		$task['skipped']  = 0;
		$task['updated']  = 0;

		$tasks = array(
			self::ACTION_IMPORT_USERS,
			self::ACTION_IMPORT_PRODUCTS,
			self::ACTION_IMPORT_FIELDS,
			self::ACTION_IMPORT_CATEGORIES,
			self::ACTION_IMPORT_BLOG_CATEGORIES,
			self::ACTION_IMPORT_EVENTS_CATEGORIES,
			self::ACTION_IMPORT_LISTINGS,
			self::ACTION_IMPORT_EVENTS,
			self::ACTION_IMPORT_REVIEWS,
			self::ACTION_IMPORT_PAGES,
			self::ACTION_IMPORT_POSTS,
			self::ACTION_IMPORT_COMMENTS,
			self::ACTION_IMPORT_DISCOUNTS,
			self::ACTION_IMPORT_INVOICES,
		);

		$key = array_search( $task['action'], $tasks, true );
		if ( false !== $key && $key + 1 < count( $tasks ) ) {
			$task['action'] = $tasks[ $key + 1 ];
			return $task;
		}

		return false;
	}

	/**
	 * Import users from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_users( array $task ) {
		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset      = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported    = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed      = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped     = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$total_users = isset( $task['total_users'] ) ? absint( $task['total_users'] ) : 0;
		$batch_size  = absint( $this->get_batch_size() );

		$users_table      = $this->db_prefix . 'users';
		$user_roles_table = $this->db_prefix . 'users_groups_lookup';

		// Determine total users count if not set.
		if ( ! $total_users ) {
			$total_users         = (int) $db->get_var( "SELECT COUNT(*) FROM {$users_table}" );
			$task['total_users'] = $total_users;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of users */
			$this->log( sprintf( __( 'Starting user import: %d users found.', 'geodir-converter' ), $total_users ) );
		}

		// Exit early if there are no users to import.
		if ( 0 === $total_users ) {
			$this->log( __( 'No users available for import. Skipping...', 'geodir-converter' ) );
			return false;
		}

		$users = $db->get_results(
			$db->prepare( "SELECT * FROM $users_table LIMIT %d, %d", $offset, $batch_size )
		);

		if ( empty( $users ) ) {
			$this->log( __( 'No more users to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get existing user mapping.
		$user_mapping = (array) $this->options_handler->get_option_no_cache( 'user_mapping', array() );

		foreach ( $users as $user ) {
			$existing_user = get_user_by( 'email', $user->user_email );
			$user_data     = array();

			// Don't modify the super admin user login.
			if ( 1 !== (int) $user->id ) {
				$user_data = array(
					'user_pass'  => $user->pass,
					'user_login' => sanitize_user( $user->display_name ),
					'user_email' => sanitize_email( $user->user_email ),
				);
			}

			$user_data = array_merge(
				$user_data,
				array(
					'user_nicename'   => sanitize_title( $user->display_name ),
					'display_name'    => sanitize_text_field( "{$user->user_first_name} {$user->user_last_name}" ),
					'user_registered' => $user->created ? $user->created : current_time( 'mysql' ),
				)
			);

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				++$imported;
				continue;
			}

			if ( $existing_user ) {
				$user_data['ID'] = $existing_user->ID;
				$user_id         = wp_update_user( $user_data );
			} else {
				$user_id = wp_insert_user( $user_data );
			}

			if ( is_wp_error( $user_id ) ) {
				$this->log( $user_id->get_error_message() );
				++$failed;
				continue;
			}

			// Set user role.
			$group_id = (int) $db->get_var( $db->prepare( "SELECT group_id FROM $user_roles_table WHERE user_id = %d", $user->id ) );
			$wp_role  = $this->map_user_role( $group_id );

			wp_update_user(
				array(
					'ID'   => $user_id,
					'role' => $wp_role,
				)
			);

			$this->update_user_meta( $user_id, $user );
			$user_mapping[ $user->id ] = (int) $user_id;

			$existing_user ? ++$skipped : ++$imported;
		}

		// Update user mapping.
		$this->options_handler->update_option( 'user_mapping', $user_mapping );

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_users );

		if ( ! $complete ) {
			/* translators: %1$d: processed count, %2$d: total count */
			$this->log( sprintf( __( 'Batch complete. Progress: %1$d/%2$d users imported.', 'geodir-converter' ), ( $imported + $failed + $skipped ), $total_users ) );
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'User import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_users,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Map PMD user group to WordPress role.
	 *
	 * @since 2.0.2
	 *
	 * @param int $group_id The PMD group ID.
	 * @return string The corresponding WordPress role.
	 */
	private function map_user_role( $group_id ) {
		switch ( $group_id ) {
			case 1:
				return 'administrator';
			case 2:
				return 'editor';
			case 3:
				return 'author';
			default:
				return 'subscriber';
		}
	}

	/**
	 * Update user meta data.
	 *
	 * @since 2.0.2
	 *
	 * @param int    $user_id The WordPress user ID.
	 * @param object $user    The original user data from PMD.
	 * @return void
	 */
	private function update_user_meta( $user_id, $user ) {
		$meta_fields = array(
			'first_name'        => 'user_first_name',
			'last_name'         => 'user_last_name',
			'user_organization' => 'user_organization',
			'user_address1'     => 'user_address1',
			'user_address2'     => 'user_address2',
			'user_city'         => 'user_city',
			'user_state'        => 'user_state',
			'user_country'      => 'user_country',
			'user_zip'          => 'user_zip',
			'user_phone'        => 'user_phone',
		);

		// Don't modify the super admin user password.
		if ( 1 !== (int) $user_id ) {
			$meta_fields = array_merge(
				$meta_fields,
				array(
					'pmd_password_hash' => 'password_hash',
					'pmd_password_salt' => 'password_salt',
				)
			);
		}

		foreach ( $meta_fields as $wp_key => $pmd_key ) {
			if ( isset( $user->$pmd_key ) ) {
				update_user_meta( $user_id, $wp_key, sanitize_text_field( $user->$pmd_key ) );
			}
		}
	}

	/**
	 * Import products from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array Result of the import operation.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_products( array $task ) {
		// Abort early if the payment manager plugin is not installed.
		if ( ! class_exists( 'GeoDir_Pricing_Package' ) ) {
			$this->log( __( 'Payment manager plugin is not active. Skipping products...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset         = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported       = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed         = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped        = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$total_products = isset( $task['total_products'] ) ? absint( $task['total_products'] ) : 0;
		$batch_size     = absint( $this->get_batch_size() );
		$products_table = $this->db_prefix . 'products';
		$pricing_table  = $products_table . '_pricing';
		$post_type      = $this->get_import_post_type();

		// Determine total products count if not set.
		if ( ! isset( $task['total_products'] ) ) {
			$total_products         = (int) $db->get_var( "SELECT COUNT(*) FROM {$products_table}" );
			$task['total_products'] = $total_products;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of products */
			$this->log( sprintf( __( 'Starting products import: %d products found.', 'geodir-converter' ), $total_products ) );
		}

		// Exit early if there are no products to import.
		if ( 0 === $total_products ) {
			$this->log( __( 'No products available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Older pricing table is different from this.
		$pricing_fields = $db->get_col( "SHOW COLUMNS FROM `$pricing_table`" );

		if ( ! in_array( 'overdue_pricing_id', $pricing_fields, true ) ) {
			$this->log( __( 'Skipping products as you are using an incompatible version of PMD', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Fetch products for current batch, ordered by product ID.
		$products = $db->get_results(
			$db->prepare(
				"SELECT 
					p.`id` as product_id, 
					p.`name`, 
					p.`active`, 
					p.`description`, 
					pp.`period`, 
					pp.`period_count`, 
					pp.`setup_price`, 
					pp.`price`, 
					pp.`renewable`,
					pp.`id` as package_id,
					pp.`overdue_pricing_id`,
					pp.`ordering`
				FROM `$products_table` p 
				LEFT JOIN `$pricing_table` pp ON p.`id` = pp.`product_id` 
				ORDER BY p.`id` ASC 
				LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $products ) ) {
			$this->log( __( 'No more products to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$imported = $skipped = $failed = 0;

		// Get packages mapping.
		$packages_mapping = (array) $this->options_handler->get_option_no_cache( 'packages_mapping', array() );

		// Import products.
		foreach ( $products as $product ) {
			$product_id       = absint( $product->product_id );
			$plan_label       = $product->name;
			$plan_description = $product->description;

			// Check if the plan already exists.
			$existing_package = $this->get_existing_package( $post_type, $product_id, (float) $product->price === 0 );

			$package_data = array(
				'post_type'       => $post_type,
				'name'            => $plan_label,
				'title'           => $plan_label,
				'description'     => $plan_description,
				'fa_icon'         => '',
				'amount'          => (float) $product->price,
				'time_interval'   => absint( $product->period_count ),
				'time_unit'       => $product->period ? $this->get_time_unit( $product->period ) : 'M',
				'recurring'       => $product->renewable,
				'recurring_limit' => 0,
				'trial'           => '',
				'trial_amount'    => '',
				'trial_interval'  => '',
				'trial_unit'      => '',
				'is_default'      => 0,
				'display_order'   => $product->ordering,
				'downgrade_pkg'   => $product->overdue_pricing_id ? absint( $product->overdue_pricing_id ) : 0,
				'post_status'     => $product->active ? 'publish' : 'default',
				'status'          => (bool) $product->active,
			);

			// If existing package found, update ID before saving.
			if ( $existing_package ) {
				$package_data['id'] = absint( $existing_package->id );
			}

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				$existing_package ? ++$skipped : ++$imported;
				continue;
			}

			// Prepare and insert/update package.
			$package_data = GeoDir_Pricing_Package::prepare_data_for_save( $package_data );
			$package_id   = GeoDir_Pricing_Package::insert_package( $package_data );

			if ( ! $package_id || is_wp_error( $package_id ) ) {
				/* translators: %s: plan name */
				$this->log( sprintf( __( 'Failed to import plan: %s', 'geodir-converter' ), $plan_label ), 'error' );
				++$failed;
			} else {
				/* translators: %s: plan name */
				$log_message = $existing_package
					? sprintf( __( 'Updated plan: %s', 'geodir-converter' ), $plan_label )
					: sprintf( __( 'Imported new plan: %s', 'geodir-converter' ), $plan_label );

				$this->log( $log_message );

				$existing_package ? ++$skipped : ++$imported;

				// Store package mapping.
				$packages_mapping[ (int) $product_id ] = (int) $package_id;

				GeoDir_Pricing_Package::update_meta( $package_id, '_pmd_package_id', $product_id );
			}
		}

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		// Save packages mapping.
		$this->options_handler->update_option( 'packages_mapping', $packages_mapping );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_products );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d products imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_products
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Products import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_products,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Get the time unit based on the period.
	 *
	 * @since 2.0.2
	 *
	 * @param string $period The period to convert.
	 * @return string The time unit.
	 */
	public function get_time_unit( $period ) {
		$periods = array(
			'days'   => 'D',
			'weeks'  => 'W',
			'months' => 'M',
			'years'  => 'Y',
		);

		return isset( $periods[ $period ] ) ? $periods[ $period ] : 'M';
	}

	/**
	 * Get existing package based on PMD package ID or find a suitable free package.
	 *
	 * @since 2.0.2
	 *
	 * @param string $post_type     The post type associated with the package.
	 * @param int    $package_id    The package ID.
	 * @param bool   $free_fallback Whether to fallback to a free package if no match is found.
	 * @return object|null The existing package object if found, or null otherwise.
	 */
	private function get_existing_package( $post_type, $package_id, $free_fallback = true ) {
		global $wpdb;

		// Fetch the package by package ID.
		$query = $wpdb->prepare(
			'SELECT p.*, g.* 
            FROM ' . GEODIR_PRICING_PACKAGES_TABLE . ' AS p
            INNER JOIN ' . GEODIR_PRICING_PACKAGE_META_TABLE . ' AS g ON p.ID = g.package_id
            WHERE p.post_type = %s AND g.meta_key = %s AND g.meta_value = %d
            LIMIT 1',
			$post_type,
			'_pmd_package_id',
			(int) $package_id
		);

		$existing_package = $wpdb->get_row( $query );

		// If not found, attempt to retrieve a free package.
		if ( ! $existing_package && $free_fallback ) {
			$existing_package = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . GEODIR_PRICING_PACKAGES_TABLE . ' 
                WHERE post_type = %s AND amount = 0 AND status = 1
                ORDER BY display_order ASC, ID ASC
                LIMIT 1',
					$post_type
				)
			);
		}

		return $existing_package;
	}

	/**
	 * Import custom fields from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_fields( array $task ) {
		global $plugin_prefix;

		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		$offset        = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported      = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed        = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped       = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$updated       = isset( $task['updated'] ) ? absint( $task['updated'] ) : 0;
		$total_fields  = isset( $task['total_fields'] ) ? absint( $task['total_fields'] ) : 0;
		$mapped_fields = isset( $task['mapped_fields'] ) ? (array) $task['mapped_fields'] : array();
		$batch_size    = absint( $this->get_batch_size() );
		$fields_table  = $this->db_prefix . 'fields';
		$post_type     = $this->get_import_post_type();
		$package_ids   = $this->get_package_ids( $post_type );
		$details_table = $plugin_prefix . $post_type . '_detail';

		// Import standard fields if not already done.
		if ( ! isset( $task['standard_fields_imported'] ) ) {
			$this->import_standard_fields( $post_type );
			$task['standard_fields_imported'] = true;
		}

		wp_suspend_cache_addition( true );

		// Determine total fields count if not set.
		if ( ! isset( $task['total_fields'] ) ) {
			$total_fields         = (int) $db->get_var( "SELECT COUNT(*) FROM {$fields_table}" );
			$task['total_fields'] = $total_fields;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of fields */
			$this->log( sprintf( __( 'Starting custom fields import: %d fields found.', 'geodir-converter' ), $total_fields ) );
		}

		// Exit early if there are no fields to import.
		if ( 0 === $total_fields ) {
			$this->log( __( 'No custom fields available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get fields for current batch.
		$fields = $db->get_results(
			$db->prepare(
				"SELECT f.* FROM {$fields_table} f ORDER BY f.group_id ASC, f.ordering ASC LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $fields ) ) {
			$this->log( __( 'No more custom fields to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		foreach ( $fields as $field ) {
			// Skip if field already exists.
			$field_key     = $this->get_field_key( $field->name );
			$gd_field_key  = $this->map_field_key( $field_key );
			$gd_field_type = $this->map_field_type( $field->type );
			$gd_data_type  = $this->map_data_type( $field->type );
			$gd_field      = geodir_get_field_infoby( 'htmlvar_name', $gd_field_key, $post_type );

			if ( $gd_field ) {
				$gd_field['field_id'] = (int) $gd_field['id'];
				unset( $gd_field['id'] );
			} else {
				$gd_field = array();
			}

			// Prepare field data.
			$gd_field = array_merge(
				(array) $gd_field,
				array(
					'post_type'          => $post_type,
					'data_type'          => $gd_data_type,
					'field_type'         => $gd_field_type,
					'admin_title'        => $field->name,
					'htmlvar_name'       => $gd_field_key,
					'frontend_title'     => $field->name,
					'frontend_desc'      => $field->description,
					'is_active'          => 1,
					'is_required'        => $field->required,
					'validation_pattern' => $field->regex,
					'validation_msg'     => $field->regex_error,
					'option_values'      => $this->get_field_options( $field->options ),
					'show_on_pkg'        => $package_ids,
					'for_admin_use'      => $field->admin_only,
				)
			);

			// Skip fields that shouldn't be imported.
			if ( $this->should_skip_field( $gd_field['htmlvar_name'] ) ) {
				++$skipped;
				/* translators: %s: field name */
				$this->log( sprintf( __( 'Skipped custom field: %s', 'geodir-converter' ), $field->name ), 'warning' );
				continue;
			}

			$column_exists = geodir_column_exist( $details_table, $gd_field['htmlvar_name'] );

			if ( $this->is_test_mode() ) {
				$column_exists ? $updated++ : $imported++;
				continue;
			}

			$result = geodir_custom_field_save( $gd_field );

			if ( is_wp_error( $result ) || ! $result ) {
				++$failed;
				$this->log(
					sprintf(
						/* translators: %1$s: field name, %2$s: error message */
						__( 'Failed to import field %1$s: %2$s', 'geodir-converter' ),
						$field->name,
						is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown error', 'geodir-converter' )
					),
					'error'
				);
				continue;
			}

			$mapped_fields[] = $gd_field_key;

			$column_exists ? ++$updated : ++$imported;
		}

		// Update task progress.
		$task['mapped_fields'] = $mapped_fields;
		$task['imported']      = absint( $imported );
		$task['failed']        = absint( $failed );
		$task['skipped']       = absint( $skipped );
		$task['updated']       = absint( $updated );

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_fields );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d fields imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_fields
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Custom fields import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_fields,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Import standard fields from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param string $post_type The post type to import standard fields for.
	 * @return void
	 */
	private function import_standard_fields( $post_type ) {
		global $plugin_prefix;

		$table       = $plugin_prefix . $post_type . '_detail';
		$package_ids = $this->get_package_ids( $post_type );

		$standard_fields = array(
			'pmd_id'   => array(
				'label'       => __( 'PMD ID', 'geodir-converter' ),
				'description' => __( 'Original PMD Listing ID.', 'geodir-converter' ),
				'type'        => 'int',
				'required'    => false,
				'placeholder' => __( 'PMD ID', 'geodir-converter' ),
				'icon'        => 'far fa-id-card',
				'priority'    => 1,
			),
			'phone'    => array(
				'label'       => __( 'Phone', 'geodir-converter' ),
				'description' => __( 'The phone number of the listing.', 'geodir-converter' ),
				'type'        => 'text',
				'required'    => false,
				'placeholder' => __( 'Phone', 'geodir-converter' ),
				'icon'        => 'far fa-phone',
				'priority'    => 2,
			),
			'website'  => array(
				'label'       => __( 'Website', 'geodir-converter' ),
				'description' => __( 'The website of the listing.', 'geodir-converter' ),
				'type'        => 'text',
				'required'    => false,
				'placeholder' => __( 'Website', 'geodir-converter' ),
				'icon'        => 'far fa-globe',
				'priority'    => 3,
			),
			'email'    => array(
				'label'       => __( 'Email', 'geodir-converter' ),
				'description' => __( 'The email of the listing.', 'geodir-converter' ),
				'type'        => 'text',
				'required'    => false,
				'placeholder' => __( 'Email', 'geodir-converter' ),
				'icon'        => 'far fa-envelope',
				'priority'    => 4,
			),
			'featured' => array(
				'label'       => __( 'Featured', 'geodir-converter' ),
				'frontend'    => __( 'Is Featured?', 'geodirectory' ),
				'description' => __( 'Mark listing as a featured.', 'geodir-converter' ),
				'type'        => 'checkbox',
				'required'    => false,
				'placeholder' => __( 'Featured', 'geodir-converter' ),
				'icon'        => 'far fa-star',
				'priority'    => 20,
			),
		);

		if ( 'gd_event' === $post_type ) {
			$standard_fields = array_merge(
				$standard_fields,
				array(
					'venue'        => array(
						'label'       => __( 'Venue', 'geodir-converter' ),
						'description' => __( 'The venue that will host this event.', 'geodir-converter' ),
						'type'        => 'text',
						'required'    => false,
						'placeholder' => __( 'Venue', 'geodir-converter' ),
						'icon'        => 'far fa-map-marker-alt',
						'priority'    => 10,
					),
					'location'     => array(
						'label'       => __( 'Location', 'geodir-converter' ),
						'description' => __( 'The actual location of this event.', 'geodir-converter' ),
						'type'        => 'text',
						'required'    => false,
						'placeholder' => __( 'Location', 'geodir-converter' ),
						'icon'        => 'far fa-map-marker-alt',
						'priority'    => 11,
					),
					'contact_name' => array(
						'label'       => __( 'Contact Name', 'geodir-converter' ),
						'description' => __( 'The contact person.', 'geodir-converter' ),
						'type'        => 'text',
						'required'    => false,
						'placeholder' => __( 'Contact Name', 'geodir-converter' ),
						'icon'        => 'far fa-user',
						'priority'    => 13,
					),
				)
			);
		} else {
			$standard_fields = array_merge(
				$standard_fields,
				array(
					'company_logo' => array(
						'label'       => __( 'Company Logo', 'geodir-converter' ),
						'description' => __( 'You can upload your company logo.', 'geodir-converter' ),
						'type'        => 'image',
						'required'    => false,
						'placeholder' => __( 'Company Logo', 'geodir-converter' ),
						'icon'        => 'far fa-image',
						'priority'    => 2,
					),
					'twitter'      => array(
						'label'       => __( 'Twitter', 'geodir-converter' ),
						'description' => __( 'You can enter your business or listing twitter url.', 'geodir-converter' ),
						'type'        => 'url',
						'required'    => false,
						'placeholder' => __( '@yourcompany', 'geodir-converter' ),
						'icon'        => 'fab fa-twitter',
						'priority'    => 2,
					),
					'facebook'     => array(
						'label'       => __( 'Facebook', 'geodir-converter' ),
						'description' => __( 'You can enter your business or listing facebook url.', 'geodir-converter' ),
						'type'        => 'url',
						'required'    => false,
						'placeholder' => __( '@yourcompany', 'geodir-converter' ),
						'icon'        => 'fab fa-facebook',
						'priority'    => 3,
					),
					'google'       => array(
						'label'       => __( 'Google+', 'geodir-converter' ),
						'description' => __( 'You can enter your business or listing google url.', 'geodir-converter' ),
						'type'        => 'url',
						'required'    => false,
						'placeholder' => __( '@yourcompany', 'geodir-converter' ),
						'icon'        => 'fab fa-google',
						'priority'    => 4,
					),
					'linkedin'     => array(
						'label'       => __( 'LinkedIn', 'geodir-converter' ),
						'description' => __( 'You can enter your business or listing linkedin url.', 'geodir-converter' ),
						'type'        => 'url',
						'required'    => false,
						'placeholder' => __( '@yourcompany', 'geodir-converter' ),
						'icon'        => 'fab fa-linkedin',
						'priority'    => 5,
					),
					'pinterest'    => array(
						'label'       => __( 'Pinterest', 'geodir-converter' ),
						'description' => __( 'You can enter your business or listing pinterest url.', 'geodir-converter' ),
						'type'        => 'url',
						'required'    => false,
						'placeholder' => __( '@yourcompany', 'geodir-converter' ),
						'icon'        => 'fab fa-pinterest',
						'priority'    => 6,
					),
					'youtube'      => array(
						'label'       => __( 'YouTube', 'geodir-converter' ),
						'description' => __( 'You can enter your business or listing youtube url.', 'geodir-converter' ),
						'type'        => 'url',
						'required'    => false,
						'placeholder' => __( '@yourcompany', 'geodir-converter' ),
						'icon'        => 'fab fa-youtube',
						'priority'    => 7,
					),
					'foursquare'   => array(
						'label'       => __( 'Foursquare', 'geodir-converter' ),
						'description' => __( 'You can enter your business or listing foursquare url.', 'geodir-converter' ),
						'type'        => 'url',
						'required'    => false,
						'placeholder' => __( '@yourcompany', 'geodir-converter' ),
						'icon'        => 'fab fa-foursquare',
						'priority'    => 8,
					),
					'instagram'    => array(
						'label'       => __( 'Instagram', 'geodir-converter' ),
						'description' => __( 'You can enter your business or listing instagram url.', 'geodir-converter' ),
						'type'        => 'url',
						'required'    => false,
						'placeholder' => __( '@yourcompany', 'geodir-converter' ),
						'icon'        => 'fab fa-instagram',
						'priority'    => 9,
					),
					'claimed'      => array(
						'label'       => __( 'Is Claimed', 'geodir-converter' ),
						'frontend'    => __( 'Business Owner/Associate?', 'geodir-converter' ),
						'description' => __( 'Mark listing as a claimed.', 'geodir-converter' ),
						'type'        => 'checkbox',
						'required'    => false,
						'placeholder' => __( 'Is Claimed', 'geodir-converter' ),
						'icon'        => 'far fa-check',
						'priority'    => 10,
					),
				)
			);
		}

		$imported = $updated = $skipped = $failed = 0;

		foreach ( $standard_fields as $field_key => $field ) {
			$gd_field_key  = $this->map_field_key( $field_key );
			$gd_field_type = $this->map_field_type( $field['type'] );
			$gd_data_type  = $this->map_data_type( $field['type'] );
			$gd_field      = geodir_get_field_infoby( 'htmlvar_name', $gd_field_key, $post_type );

			if ( $gd_field ) {
				$gd_field['field_id'] = (int) $gd_field['id'];
				unset( $gd_field['id'] );
			} else {
				$gd_field = array();
			}

			$gd_field = array_merge(
				(array) $gd_field,
				array(
					'post_type'         => $post_type,
					'data_type'         => $gd_data_type,
					'field_type'        => $gd_field_type,
					'htmlvar_name'      => $gd_field_key,
					'admin_title'       => $field['label'],
					'frontend_desc'     => $field['description'],
					'placeholder_value' => $field['placeholder'],
					'frontend_title'    => isset( $field['frontend'] ) ? $field['frontend'] : $field['label'],
					'clabels'           => $field['label'],
					'is_required'       => true === $field['required'] ? 1 : 0,
					'show_in'           => '[owntab],[detail]',
					'show_on_pkg'       => $package_ids,
					'field_icon'        => isset( $field['icon'] ) ? $field['icon'] : '',
					'is_active'         => '1',
					'is_default'        => '0',
				)
			);

			if ( 'image' === $field['type'] ) {
				$gd_field['extra'] = array(
					'gd_file_types' => geodir_image_extensions(),
					'file_limit'    => 1,
				);
			}

			// Skip fields that shouldn't be imported.
			if ( $this->should_skip_field( $gd_field['htmlvar_name'] ) ) {
				++$skipped;
				/* translators: %s: field name */
				$this->log( sprintf( __( 'Skipped standard field: %s', 'geodir-converter' ), $field['label'] ), 'warning' );
				continue;
			}

			$column_exists = geodir_column_exist( $table, $gd_field['htmlvar_name'] );

			if ( $this->is_test_mode() ) {
				$column_exists ? $updated++ : $imported++;
				continue;
			}

			if ( $gd_field && geodir_custom_field_save( $gd_field ) ) {
				$column_exists ? ++$updated : ++$imported;
			} else {
				++$failed;
				/* translators: %s: field name */
				$this->log( sprintf( __( 'Failed to import standard field: %s', 'geodir-converter' ), $field['label'] ), 'error' );
			}
		}

		$this->increase_succeed_imports( $imported + $updated );
		$this->increase_skipped_imports( $skipped );
		$this->increase_failed_imports( $failed );

		$this->log(
			sprintf(
				/* translators: %1$d: imported count, %2$d: updated count, %3$d: skipped count, %4$d: failed count */
				__( 'Standard fields import completed: %1$d imported, %2$d updated, %3$d skipped, %4$d failed.', 'geodir-converter' ),
				$imported,
				$updated,
				$skipped,
				$failed
			),
			'success'
		);
	}

	/**
	 * Import categories from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_categories( array $task ) {
		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset           = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported         = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed           = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped          = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$updated          = isset( $task['updated'] ) ? absint( $task['updated'] ) : 0;
		$total_categories = isset( $task['total_categories'] ) ? absint( $task['total_categories'] ) : 0;
		$batch_size       = absint( $this->get_batch_size() );

		$categories_table = $this->db_prefix . 'categories';
		$post_type        = $this->get_import_post_type();

		// Determine total categories count if not set.
		if ( ! $total_categories ) {
			$total_categories         = (int) $db->get_var( "SELECT COUNT(*) FROM {$categories_table}" );
			$task['total_categories'] = $total_categories;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of categories */
			$this->log( sprintf( __( 'Starting category import: %d categories found.', 'geodir-converter' ), $total_categories ) );
		}

		// Exit early if there are no categories to import.
		if ( 0 === $total_categories ) {
			$this->log( __( 'No categories available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get categories for current batch.
		$categories = $db->get_results(
			$db->prepare(
				"SELECT c.* FROM {$categories_table} c ORDER BY c.parent_id ASC, c.id ASC LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $categories ) ) {
			$this->log( __( 'No more categories to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get existing category mapping.
		$category_mapping = (array) $this->options_handler->get_option_no_cache( 'category_mapping', array() );

		foreach ( $categories as $category ) {
			// Skip if already imported or ROOT category.
			if ( 'ROOT' === $category->title ) {
				++$skipped;
				continue;
			}

			$parent_term_id = 0;
			if ( ! empty( $category->parent_id ) && isset( $category_mapping[ $category->parent_id ] ) ) {
				$parent_term_id = $category_mapping[ $category->parent_id ];
			}

			$category_data = array(
				/* translators: %d: category ID */
				'cat_name'        => ! empty( $category->title ) ? $category->title : sprintf( __( 'Category %d', 'geodir-converter' ), $category->id ),
				'cat_description' => ! empty( $category->description ) ? $category->description : '',
				'parent'          => $parent_term_id,
			);

			$term_slug = sanitize_title( $category->friendly_url );
			$term      = term_exists( $term_slug, $post_type . 'category' );

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				$term ? ++$skipped : ++$imported;
				continue;
			}

			if ( ! $term ) {
				$term = wp_insert_term(
					$category_data['cat_name'],
					$post_type . 'category',
					array(
						'description' => $category_data['cat_description'],
						'parent'      => $parent_term_id,
						'slug'        => $term_slug,
					)
				);

				if ( is_wp_error( $term ) ) {
					$this->log(
						sprintf(
							/* translators: %1$s: category name, %2$s: error message */
							__( 'Failed to import category %1$s: %2$s', 'geodir-converter' ),
							$category->title,
							$term->get_error_message()
						)
					);
					++$failed;
					continue;
				}
			}

			$term_id = absint( $term['term_id'] );

			// Store category mapping.
			$category_mapping[ $category->id ] = $term_id;

			// Import category description.
			if ( ! empty( $category->description ) ) {
				update_term_meta( $term_id, 'ct_cat_top_desc', $category->description );
			}

			// Import category images.
			$image_extensions = array( 'jpg', 'jpeg', 'png' );

			// Import category map icon.
			if ( ! empty( $category->small_image_url ) ) {
				if ( ! $this->import_category_image( $term_id, $category->small_image_url, 'ct_cat_icon' ) ) {
					$this->log(
						sprintf(
							/* translators: %1$s: image identifier */
							__( 'Failed to import category image %1$s', 'geodir-converter' ),
							$category->small_image_url,
						)
					);
				}
			} else {
				foreach ( $image_extensions as $extension ) {
					$category_image_url = $this->url . '/files/categories/' . $category->id . '-map.' . $extension;
					if ( $this->import_category_image( $term_id, $category_image_url, 'ct_cat_icon', false ) ) {
						break;
					}
				}
			}

			// Import category default image.
			if ( ! empty( $category->large_image_url ) ) {
				if ( ! $this->import_category_image( $term_id, $category->large_image_url, 'ct_cat_default_img' ) ) {
					$this->log(
						sprintf(
							/* translators: %1$s: image identifier */
							__( 'Failed to import category image %1$s', 'geodir-converter' ),
							$category->large_image_url,
						)
					);
				}
			} else {
				foreach ( $image_extensions as $extension ) {
					$category_image_url = $this->url . '/files/categories/' . $category->id . '.' . $extension;
					if ( $this->import_category_image( $term_id, $category_image_url, 'ct_cat_default_img', false ) ) {
						break;
					}
				}
			}

			++$imported;
		}

		// Update category mapping.
		$this->options_handler->update_option( 'category_mapping', $category_mapping );

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_categories );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d categories imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_categories
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Category import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_categories,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Import blog categories from PMD to WordPress.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_blog_categories( array $task ) {
		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset           = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported         = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed           = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped          = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$total_categories = isset( $task['total_blog_categories'] ) ? absint( $task['total_blog_categories'] ) : 0;
		$batch_size       = absint( $this->get_batch_size() );
		$categories_table = $this->db_prefix . 'blog_categories';

		// Determine total categories count if not set.
		if ( ! $total_categories ) {
			$total_categories              = (int) $db->get_var( "SELECT COUNT(*) FROM {$categories_table}" );
			$task['total_blog_categories'] = $total_categories;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of categories */
			$this->log( sprintf( __( 'Starting blog category import: %d categories found.', 'geodir-converter' ), $total_categories ) );
		}

		// Exit early if there are no categories to import.
		if ( 0 === $total_categories ) {
			$this->log( __( 'No blog categories available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get categories for current batch.
		$categories = $db->get_results(
			$db->prepare(
				"SELECT c.* FROM {$categories_table} c ORDER BY c.id ASC LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $categories ) ) {
			$this->log( __( 'No more blog categories to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		if ( $this->is_test_mode() ) {
			$this->log(
				sprintf(
				/* translators: %1$d: number of imported terms, %2$d: number of failed imports */
					__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
					count( $categories ),
					0
				),
				'success'
			);
			return $this->next_task( $task );
		}

		// Get existing category mapping.
		$category_mapping = (array) $this->options_handler->get_option_no_cache( 'blog_category_mapping', array() );

		foreach ( $categories as $category ) {
			$category_data = array(
				/* translators: %d: category ID */
				'cat_name' => ! empty( $category->title ) ? $category->title : sprintf( __( 'Category %d', 'geodir-converter' ), $category->id ),
			);

			$term_slug = sanitize_title( $category->friendly_url );
			$term      = term_exists( $term_slug, 'category' );

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				$term ? ++$skipped : ++$imported;
				continue;
			}

			if ( ! $term ) {
				$term = wp_insert_term(
					$category_data['cat_name'],
					'category',
					array(
						'slug' => $term_slug,
					)
				);

				if ( is_wp_error( $term ) ) {
					$this->log(
						sprintf(
							/* translators: %1$s: category name, %2$s: error message */
							__( 'Failed to import category %1$s: %2$s', 'geodir-converter' ),
							$category->title,
							$term->get_error_message()
						)
					);
					++$failed;
					continue;
				}
			}

			// Store category mapping.
			$category_mapping[ $category->id ] = absint( $term['term_id'] );

			++$imported;
		}

		// Update category mapping.
		$this->options_handler->update_option( 'blog_category_mapping', $category_mapping );

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_categories );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d blog categories imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_categories
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Blog category import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_categories,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Import event categories from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_events_categories( array $task ) {
		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset           = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported         = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed           = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped          = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$total_categories = isset( $task['total_events_categories'] ) ? absint( $task['total_events_categories'] ) : 0;
		$batch_size       = absint( $this->get_batch_size() );
		$categories_table = $this->db_prefix . 'events_categories';
		$post_type        = self::POST_TYPE_EVENTS;

		// Determine total categories count if not set.
		if ( ! $total_categories ) {
			$total_categories                = (int) $db->get_var( "SELECT COUNT(*) FROM {$categories_table}" );
			$task['total_events_categories'] = $total_categories;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of categories */
			$this->log( sprintf( __( 'Starting event category import: %d categories found.', 'geodir-converter' ), $total_categories ) );
		}

		// Exit early if there are no categories to import.
		if ( 0 === $total_categories ) {
			$this->log( __( 'No event categories available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get categories for current batch.
		$categories = $db->get_results(
			$db->prepare(
				"SELECT c.* FROM {$categories_table} c ORDER BY c.id ASC LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $categories ) ) {
			$this->log( __( 'No more event categories to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		if ( $this->is_test_mode() ) {
			$this->log(
				sprintf(
				/* translators: %1$d: number of imported terms, %2$d: number of failed imports */
					__( 'Categories: Import completed. %1$d imported, %2$d failed.', 'geodir-converter' ),
					count( $categories ),
					0
				),
				'success'
			);
			return $this->next_task( $task );
		}

		// Get existing category mapping.
		$category_mapping = (array) $this->options_handler->get_option_no_cache( 'events_category_mapping', array() );

		foreach ( $categories as $category ) {
			$category_data = array(
				/* translators: %d: category ID */
				'cat_name' => ! empty( $category->title ) ? $category->title : sprintf( __( 'Category %d', 'geodir-converter' ), $category->id ),
			);

			$term_slug = sanitize_title( $category->friendly_url );
			$term      = term_exists( $term_slug, $post_type . 'category' );

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				$term ? ++$skipped : ++$imported;
				continue;
			}

			if ( ! $term ) {
				$term = wp_insert_term(
					$category_data['cat_name'],
					$post_type . 'category',
					array(
						'slug' => $term_slug,
					)
				);

				if ( is_wp_error( $term ) ) {
					$this->log(
						sprintf(
							/* translators: %1$s: category name, %2$s: error message */
							__( 'Failed to import event category %1$s: %2$s', 'geodir-converter' ),
							$category->title,
							$term->get_error_message()
						)
					);
					++$failed;
					continue;
				}
			}

			// Store category mapping.
			$category_mapping[ $category->id ] = absint( $term['term_id'] );

			++$imported;
		}

		// Update category mapping.
		$this->options_handler->update_option( 'events_category_mapping', $category_mapping );

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_categories );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d event categories imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_categories
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Event category import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_categories,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Import category image.
	 *
	 * @since 2.0.2
	 *
	 * @param int    $term_id   Term ID.
	 * @param string $image_url Image URL.
	 * @param string $meta_key  Meta key.
	 * @return bool True on success, false on failure.
	 */
	public function import_category_image( $term_id, $image_url, $meta_key ) {
		$attachment_data = $this->import_attachment( $image_url );

		if ( ! isset( $attachment_data['id'], $attachment_data['src'] ) ) {
			return false;
		}

		update_term_meta(
			$term_id,
			$meta_key,
			array(
				'id'  => absint( $attachment_data['id'] ),
				'src' => $attachment_data['src'],
			)
		);

		return true;
	}

	/**
	 * Import listings from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_listings( array $task ) {
		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset         = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$total_listings = isset( $task['total_listings'] ) ? absint( $task['total_listings'] ) : 0;
		$batch_size     = absint( $this->get_batch_size() );
		$fields         = isset( $task['mapped_fields'] ) ? (array) $task['mapped_fields'] : array();
		$listings_table = $this->db_prefix . 'listings';

		// Determine total listings count if not set.
		if ( ! $total_listings ) {
			$total_listings         = (int) $db->get_var( "SELECT COUNT(*) FROM {$listings_table}" );
			$task['total_listings'] = $total_listings;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of listings */
			$this->log( sprintf( __( 'Starting listing import: %d listings found.', 'geodir-converter' ), $total_listings ) );
		}

		// Exit early if there are no listings to import.
		if ( 0 === $total_listings ) {
			$this->log( __( 'No listings available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get listings for current batch.
		$listings = $db->get_results(
			$db->prepare(
				"SELECT l.* FROM {$listings_table} l ORDER BY l.id ASC LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $listings ) ) {
			$this->log( __( 'No more listings to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		foreach ( $listings as $pmd_listing ) {
			$listing = $this->import_single_listing( $pmd_listing, $fields );

			$this->process_import_result( $listing, 'listing', $pmd_listing->title, $pmd_listing->id );
		}

		$this->flush_failed_items();

		$complete = ( $offset + $batch_size >= $total_listings );

		if ( ! $complete ) {
			// Continue import with the next batch.
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		return $this->next_task( $task );
	}

	/**
	 * Import a single listing from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param object $listing The listing to import.
	 * @param array  $fields  The mapped custom fields.
	 * @return int Import status constant (IMPORT_STATUS_SUCCESS, IMPORT_STATUS_SKIPPED, or IMPORT_STATUS_FAILED).
	 */
	public function import_single_listing( $listing, $fields ) {
		// Check if the post has already been imported.
		$post_type        = $this->get_import_post_type();
		$listings_mapping = (array) $this->options_handler->get_option_no_cache( 'listings_mapping', array() );
		$user_mapping     = (array) $this->options_handler->get_option_no_cache( 'user_mapping', array() );
		$gd_post_id       = ! $this->is_test_mode() ? $this->get_gd_listing_id( $listing->id, 'pmd_id', $post_type ) : false;
		$is_update        = ! empty( $gd_post_id );

		// Retrieve default location and process fields.
		$default_location = $this->get_default_location();

		$categories = $this->get_listing_categories( $listing );
		$tags       = ! empty( $listing->keywords ) ? array_map( 'trim', explode( ',', $listing->keywords ) ) : array();

		$status = ( ! empty( $listing->status ) && 'active' === $listing->status ) ? 'publish' : $listing->status;
		$status = ( ! empty( $listing->status ) && 'suspended' === $listing->status ) ? 'trash' : $status;

		// Set the default locations.
		$default_location = $this->get_listing_location( (int) $listing->location_id );

		// Prepare the listing data.
		$wp_listing = array(
			// Standard WP Fields.
			'post_author'           => isset( $user_mapping[ (int) $listing->user_id ] ) ? $user_mapping[ (int) $listing->user_id ] : 1,
			'post_title'            => ( $listing->title ) ? $listing->title : 'NO TITLE',
			'post_content'          => $listing->description ? $listing->description : '',
			'post_content_filtered' => $listing->description ? $listing->description : '',
			'post_excerpt'          => $listing->description_short ? $listing->description_short : '',
			'post_status'           => $status,
			'post_type'             => $post_type,
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_name'             => ( $listing->friendly_url ) ? $listing->friendly_url : 'listing-' . $listing->id,
			'post_date_gmt'         => ( $listing->date ) ? $listing->date : current_time( 'mysql', 1 ),
			'post_date'             => ( $listing->date ) ? $listing->date : current_time( 'mysql' ),
			'post_modified_gmt'     => ( $listing->date_update ) ? $listing->date_update : current_time( 'mysql', 1 ),
			'post_modified'         => ( $listing->date_update ) ? $listing->date_update : current_time( 'mysql' ),
			'tax_input'             => array(
				$post_type . 'category' => $categories,
				$post_type . '_tags'    => $tags,
			),

			// GD fields.
			'default_category'      => ! empty( $categories ) ? $categories[0] : 0,

			// location.
			'street'                => ! empty( $listing->listing_address1 ) ? $listing->listing_address1 : $default_location['street'],
			'street2'               => ! empty( $listing->listing_address2 ) ? $listing->listing_address2 : $default_location['street2'],
			'city'                  => ! empty( $listing->location_text_3 ) ? $listing->location_text_3 : $default_location['city'],
			'region'                => ! empty( $listing->location_text_2 ) ? $listing->location_text_2 : $default_location['region'],
			'country'               => ! empty( $listing->location_text_1 ) ? $listing->location_text_1 : $default_location['country'],
			'zip'                   => $listing->listing_zip,
			'latitude'              => ! empty( $listing->latitude ) ? $listing->latitude : $default_location['latitude'],
			'longitude'             => ! empty( $listing->longitude ) ? $listing->longitude : $default_location['longitude'],
			'mapview'               => '',
			'mapzoom'               => '',

			// PMD standard fields.
			'pmd_id'                => $listing->id,
			'phone'                 => ! empty( $listing->phone ) ? $listing->phone : '',
			'fax'                   => ! empty( $listing->fax ) ? $listing->fax : '',
			'business_hours'        => $this->get_business_hours( $listing->hours ),
			'website'               => ! empty( $listing->www ) ? $listing->www : '',
			'email'                 => ! empty( $listing->mail ) ? $listing->mail : '',
			'facebook'              => ! empty( $listing->facebook_page_id ) ? 'https://facebook.com/' . $listing->facebook_page_id : '',
			'google'                => ! empty( $listing->google_page_id ) ? 'https://plus.google.com/' . $listing->google_page_id : '',
			'linkedin'              => ! empty( $listing->linkedin_company_id ) ? 'https://linkedin.com/company/' . $listing->linkedin_company_id : '',
			'twitter'               => ! empty( $listing->twitter_id ) ? 'https://twitter.com/' . $listing->twitter_id : '',
			'pinterest'             => ! empty( $listing->pinterest_id ) ? 'https://pinterest.com/' . $listing->pinterest_id : '',
			'youtube'               => ! empty( $listing->youtube_id ) ? 'https://youtube.com/user/' . $listing->youtube_id : '',
			'foursquare'            => ! empty( $listing->foursquare_id ) ? 'https://foursquare.com/' . $listing->foursquare_id : '',
			'instagram'             => ! empty( $listing->instagram_id ) ? 'https://instagram.com/' . $listing->instagram_id : '',
			'featured'              => ! empty( $listing->featured ) ? $listing->featured : 0,
			'claimed'               => ! empty( $listing->claimed ) ? $listing->claimed : 0,

			// misc.
			'submit_ip'             => ! empty( $listing->ip ) ? $listing->ip : '',
			'overall_rating'        => ! empty( $listing->rating ) ? $listing->rating : 0,
			'rating_count'          => ! empty( $listing->votes ) ? $listing->votes : 0,
		);

		// set listings with no GPS to draft.
		if ( 'publish' === $status && empty( $wp_listing['latitude'] ) ) {
			$wp_listing['post_status'] = 'draft';
		}

		$images = $this->get_listing_images( $listing->id );
		if ( ! empty( $images ) ) {
			$wp_listing['post_images'] = $images;
		}

		if ( ! empty( $listing->logo_extension ) ) {
			$wp_listing['company_logo'] = trailingslashit( $this->url ) . 'files/logo/' . $listing->id . '.' . $listing->logo_extension . '|||';
		}

		// Update custom fields.
		foreach ( $fields as $field_key ) {
			if ( isset( $listing->{$field_key} ) ) {
				$wp_listing[ $field_key ] = ! empty( $listing->{$field_key} ) ? $listing->{$field_key} : '';
			}
		}

		if ( $this->is_test_mode() ) {
			return GeoDir_Converter_Importer::IMPORT_STATUS_SUCCESS;
		}

		// Delete existing media if updating.
		if ( $is_update ) {
			GeoDir_Media::delete_files( (int) $gd_post_id, 'post_images' );
		}

		// Insert or update the post.
		if ( $is_update ) {
			$wp_listing['ID'] = absint( $gd_post_id );
			$gd_post_id       = wp_update_post( $wp_listing, true );
		} else {
			$gd_post_id = wp_insert_post( $wp_listing, true );
		}

		// Handle errors during post insertion/update.
		if ( is_wp_error( $gd_post_id ) ) {
			$this->log( $gd_post_id->get_error_message() );
			return GeoDir_Converter_Importer::IMPORT_STATUS_FAILED;
		}

		// Set featured image.
		if ( ! empty( $listing->logo_background ) ) {
			$url           = trailingslashit( $this->url ) . 'files/logo/background/' . $listing->id . '.' . $listing->logo_background;
			$attachment_id = media_sideload_image( $url, $listing->id, $listing->title, 'id' );

			if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $gd_post_id, $attachment_id );
			}
		}

		// Update listings mapping.
		$listings_mapping[ (int) $listing->id ] = (int) $gd_post_id;
		$this->options_handler->update_option( 'listings_mapping', $listings_mapping );

		return $is_update ? GeoDir_Converter_Importer::IMPORT_STATUS_SKIPPED : GeoDir_Converter_Importer::IMPORT_STATUS_SUCCESS;
	}

	/**
	 * Get gallery images.
	 *
	 * @since 2.0.2
	 *
	 * @param int $listing_id The listing ID.
	 * @return string The formatted images string.
	 */
	private function get_listing_images( $listing_id ) {
		$db         = $this->get_db_connection();
		$table      = $this->db_prefix . 'images';
		$extensions = array( 'jpg', 'jpeg', 'gif', 'png', 'svg' );
		$images     = $db->get_results( $db->prepare( "SELECT * FROM {$table} WHERE listing_id = %d ORDER BY ordering ASC", $listing_id ) );

		if ( empty( $images ) ) {
			return '';
		}

		$images = array_map(
			function ( $image ) use ( $extensions ) {
				$id = absint( $image->id );

				if ( ! in_array( strtolower( $image->extension ), $extensions, true ) ) {
					return null;
				}

				$title       = preg_replace( '/[^A-Za-z0-9 ]/', '', $image->title );
				$description = preg_replace( '/[^A-Za-z0-9 ]/', '', $image->description );

				return array(
					'url'     => trailingslashit( $this->url ) . "files/images/{$id}.{$image->extension}",
					'title'   => wp_slash( esc_attr( $title ) ),
					'caption' => wp_slash( esc_attr( $description ) ),
					'weight'  => isset( $image->ordering ) ? $image->ordering : 1,
				);
			},
			$images
		);

		// Remove null entries from filtered-out non-image files.
		$images = array_filter( $images );

		if ( empty( $images ) ) {
			return '';
		}

		usort(
			$images,
			function ( $a, $b ) {
				return $a['weight'] - $b['weight'];
			}
		);

		$formatted_images = array();

		foreach ( $images as $image ) {
			$formatted_images[] = sprintf(
				'%s||%s|%s',
				esc_url( $image['url'] ),
				esc_html( $image['title'] ),
				esc_html( $image['caption'] )
			);
		}

		return implode( '::', $formatted_images );
	}

	/**
	 * Retrieves the location data for a given location ID.
	 *
	 * @since 2.0.2
	 *
	 * @param int $location_id The ID of the location to retrieve.
	 * @return array The location data.
	 */
	public function get_listing_location( $location_id ) {
		global $geodirectory;

		$default_location = $geodirectory->location->get_default_location();
		$location         = array(
			'country'   => $default_location->country,
			'region'    => $default_location->region,
			'city'      => $default_location->city,
			'latitude'  => $default_location->latitude,
			'longitude' => $default_location->longitude,
		);

		if ( $location_id ) {
			$db        = $this->get_db_connection();
			$table     = $this->db_prefix . 'locations';
			$locations = $db->get_results( "SELECT * FROM {$table} AS l ORDER BY l.id ASC" );

			// Build lookup arrays keyed by ID and by friendly_url.
			$locations_by_id   = array();
			$locations_by_slug = array();
			foreach ( $locations as $loc ) {
				$locations_by_id[ $loc->id ]             = $loc;
				$locations_by_slug[ $loc->friendly_url ] = $loc->title;
			}

			if ( isset( $locations_by_id[ $location_id ] ) ) {
				$row = $locations_by_id[ $location_id ];

				if ( $row->level > 1 ) {
					$friendly_urls = explode( '/', trim( $row->friendly_url_path, '/\\' ) );

					foreach ( $friendly_urls as $key => $slug ) {
						if ( isset( $locations_by_slug[ $slug ] ) ) {
							$friendly_urls[ $key ] = $locations_by_slug[ $slug ];
						}
					}

					$location['region'] = ! empty( $friendly_urls[1] ) ? $friendly_urls[1] : $row->title;
					$location['city']   = $row->level > 2 && ! empty( $friendly_urls[2] ) ? $friendly_urls[2] : $location['region'];
				}
			}
		}

		return $location;
	}

	/**
	 * Retrieves the categories for a given listing.
	 *
	 * @since 2.0.2
	 *
	 * @param object $listing The listing object.
	 * @return array The categories.
	 */
	public function get_listing_categories( $listing ) {
		$db    = $this->get_db_connection();
		$table = $this->db_prefix . 'listings_categories';

		$category_mapping   = (array) $this->options_handler->get_option_no_cache( 'category_mapping' );
		$listing_categories = $db->get_results(
			$db->prepare(
				"SELECT cat_id FROM {$table} c WHERE c.list_id = %d ORDER BY c.cat_id",
				$listing->id
			)
		);

		if ( empty( $listing_categories ) ) {
			$categories = array( $listing->primary_category_id );
		}

		$categories = array();
		foreach ( $listing_categories as $category ) {
			if ( isset( $category_mapping[ $category->cat_id ] ) ) {
				$categories[] = (int) $category_mapping[ $category->cat_id ];
			}
		}

		return $categories;
	}

	/**
	 * Get business hours.
	 *
	 * @since 2.0.2
	 *
	 * @param array $hours  The hours to convert.
	 * @param int   $offset The offset to use.
	 * @return string The converted hours.
	 */
	public function get_business_hours( $hours, $offset = 0 ) {
		if ( empty( $hours ) ) {
			return '';
		}

		$hours     = maybe_unserialize( $hours );
		$new_parts = array();

		if ( ! empty( $hours ) ) {
			$new_map = array(
				'1' => 'Mo',
				'2' => 'Tu',
				'3' => 'We',
				'4' => 'Th',
				'5' => 'Fr',
				'6' => 'Sa',
				'7' => 'Su',
			);

			foreach ( $hours as $times ) {
				$time_parts = explode( ' ', $times );
				$key        = isset( $time_parts[0] ) ? $time_parts[0] : '';
				if ( $key ) {
					$map_key = $new_map[ $key ];
					if ( ! isset( $new_parts[ $map_key ] ) ) {
						$new_parts[ $map_key ] = $map_key . ' ' . $time_parts[1] . '-' . $time_parts[2];
					} else {
						$new_parts[ $map_key ] .= ',' . $time_parts[1] . '-' . $time_parts[2];
					}
				}
			}
		}

		$new = wp_json_encode( $new_parts );
		if ( ! empty( $new ) ) {
			$new  = '["' . implode( '","', $new_parts ) . '"]';
			$new .= ',["UTC":"' . $offset . '"]';
		}

		return $new;
	}

	/**
	 * Import events from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_events( array $task ) {
		// Abort early if events addon is not installed.
		if ( ! class_exists( 'GeoDir_Event_Manager' ) ) {
			$this->log( __( 'Events addon is not active. Skipping events...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset       = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported     = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed       = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped      = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$total_events = isset( $task['total_events'] ) ? absint( $task['total_events'] ) : 0;
		$batch_size   = absint( $this->get_batch_size() );
		$events_table = $this->db_prefix . 'events';
		$post_type    = self::POST_TYPE_EVENTS;

		// Import standard fields if not already done.
		if ( ! isset( $task['event_standard_fields_imported'] ) ) {
			$this->import_standard_fields( $post_type );
			$task['event_standard_fields_imported'] = true;
		}

		// Determine total events count if not set.
		if ( ! isset( $task['total_events'] ) ) {
			$total_events         = (int) $db->get_var( "SELECT COUNT(*) FROM {$events_table}" );
			$task['total_events'] = $total_events;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of events */
			$this->log( sprintf( __( 'Starting events import: %d events found.', 'geodir-converter' ), $total_events ) );
		}

		// Exit early if there are no events to import.
		if ( 0 === $total_events ) {
			$this->log( __( 'No events available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Load user mapping.
		$user_mapping = (array) $this->options_handler->get_option_no_cache( 'user_mapping', array() );

		// Get events for current batch.
		$events = $db->get_results(
			$db->prepare(
				"SELECT e.* FROM {$events_table} e ORDER BY e.id ASC LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $events ) ) {
			$this->log( __( 'No more events to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		foreach ( $events as $event ) {
			$gd_event_id = ! $this->is_test_mode() ? $this->get_gd_listing_id( $event->id, 'pmd_id', $post_type ) : false;
			$is_update   = ! empty( $gd_event_id );

			$repeat_type = array(
				'daily'   => 'day',
				'weekly'  => 'week',
				'monthly' => 'month',
				'yearly'  => 'year',
			);
			$status_map  = array(
				'suspended' => 'trash',
				'active'    => 'publish',
			);

			$categories = $this->get_post_categories( $event->id, 'events', 'event_id' );
			$tags       = ! empty( $event->keywords ) ? array_map( 'trim', explode( ',', $event->keywords ) ) : array();
			$status     = isset( $status_map[ $event->status ] ) ? $status_map[ $event->status ] : 'trash';

			// Prepare the listing data.
			$gd_event = array(
				// Standard WP Fields.
				'post_author'           => isset( $user_mapping[ (int) $event->user_id ] ) ? $user_mapping[ (int) $event->user_id ] : 1,
				'post_title'            => ( $event->title ) ? $event->title : '&mdash;',
				'post_content'          => $event->description ? $event->description : '',
				'post_content_filtered' => $event->description ? $event->description : '',
				'post_excerpt'          => $event->description_short ? $event->description_short : '',
				'post_status'           => $status,
				'post_type'             => $post_type,
				'comment_status'        => 'open',
				'ping_status'           => 'closed',
				'post_name'             => ( $event->friendly_url ) ? $event->friendly_url : 'event-' . $event->id,
				'post_date_gmt'         => ( $event->date ) ? $event->date : current_time( 'mysql', 1 ),
				'post_date'             => ( $event->date ) ? $event->date : current_time( 'mysql' ),
				'post_modified_gmt'     => ( $event->date_update ) ? $event->date_update : current_time( 'mysql', 1 ),
				'post_modified'         => ( $event->date_update ) ? $event->date_update : current_time( 'mysql' ),
				'tax_input'             => array(
					$post_type . 'category' => $categories,
					$post_type . '_tags'    => $tags,
				),

				// GD fields.
				'default_category'      => ! empty( $categories ) ? $categories[0] : 0,

				// location.
				'latitude'              => $event->latitude,
				'longitude'             => $event->longitude,
				'mapview'               => '',
				'mapzoom'               => '',
				'recurring'             => $event->recurring,

				// PMD standard fields.
				'pmd_id'                => $event->id,
				'phone'                 => ! empty( $event->phone ) ? $event->phone : '',
				'website'               => ! empty( $event->website ) ? $event->website : '',
				'email'                 => ! empty( $event->email ) ? $event->email : '',
				'venue'                 => ! empty( $event->venue ) ? $event->venue : '',
				'location'              => ! empty( $event->location ) ? $event->location : '',
				'event_reg_desc'        => ! empty( $event->admission ) ? $event->admission : '',
				'contact_name'          => ! empty( $event->contact_name ) ? $event->contact_name : '',
				'submit_ip'             => ! empty( $event->ip ) ? $event->ip : '',

				'event_dates'           => array(
					'recurring'       => $event->recurring,
					'start_date'      => date( 'Y-m-d', strtotime( $event->date_start ) ),
					'end_date'        => date( 'Y-m-d', strtotime( $event->date_end ) ),
					'all_day'         => 0,
					'start_time'      => date( 'g:i a', strtotime( $event->date_start ) ),
					'end_time'        => date( 'g:i a', strtotime( $event->date_end ) ),
					'duration_x'      => '',
					'repeat_type'     => isset( $repeat_type[ $event->recurring_type ] ) ? $repeat_type[ $event->recurring_type ] : 'custom',
					'repeat_x'        => $event->recurring_interval,
					'repeat_end_type' => '',
					'max_repeat'      => '',
					'recurring_dates' => '',
					'different_times' => '',
					'start_times'     => '',
					'end_times'       => '',
					'repeat_days'     => $event->recurring_days,
					'repeat_weeks'    => '',
				),
			);

			$images = $this->get_listing_images( $event->id );
			if ( ! empty( $images ) ) {
				$gd_event['post_images'] = $images;
			}

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				$is_update ? ++$skipped : ++$imported;
				continue;
			}

			// Delete existing media if updating.
			if ( $is_update ) {
				GeoDir_Media::delete_files( (int) $gd_event_id, 'post_images' );
			}

			// Insert or update the post.
			if ( $is_update ) {
				$gd_event['ID'] = absint( $gd_event_id );
				$gd_event_id    = wp_update_post( $gd_event, true );
			} else {
				$gd_event_id = wp_insert_post( $gd_event, true );
			}

			if ( is_wp_error( $gd_event_id ) ) {
				/* translators: %s: event ID */
				$this->log( sprintf( __( 'Failed to import event %s.', 'geodir-converter' ), $event->id ) );
				++$failed;
				/* translators: %s: event ID */
				$this->record_failed_item( $event->id, self::ACTION_IMPORT_EVENTS, 'event', $event->title, sprintf( __( 'Failed to import event %s.', 'geodir-converter' ), $event->id ) );
				continue;
			}

			// Set featured image.
			if ( ! empty( $event->image_extension ) ) {
				$url           = trailingslashit( $this->url ) . 'files/events/images/' . $event->id . '.' . $event->image_extension;
				$attachment_id = media_sideload_image( $url, $event->id, $event->title, 'id' );

				if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
					set_post_thumbnail( $gd_event_id, $attachment_id );
				}
			}

			$is_update ? ++$skipped : ++$imported;
		}

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$this->flush_failed_items();

		$complete = ( $offset + $batch_size >= $total_events );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d events imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_events
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Events import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_events,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Import reviews from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_reviews( array $task ) {
		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset           = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported         = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed           = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped          = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$total_reviews    = isset( $task['total_reviews'] ) ? absint( $task['total_reviews'] ) : 0;
		$batch_size       = absint( $this->get_batch_size() );
		$user_mapping     = (array) $this->options_handler->get_option_no_cache( 'user_mapping', array() );
		$listings_mapping = (array) $this->options_handler->get_option_no_cache( 'listings_mapping', array() );
		$reviews_table    = $this->db_prefix . 'reviews';
		$ratings_table    = $this->db_prefix . 'ratings';
		$users_table      = $this->db_prefix . 'users';

		// Determine total reviews count if not set.
		if ( ! isset( $task['total_reviews'] ) ) {
			$total_reviews         = (int) $db->get_var( "SELECT COUNT(*) FROM {$reviews_table}" );
			$task['total_reviews'] = $total_reviews;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of reviews */
			$this->log( sprintf( __( 'Starting reviews import: %d reviews found.', 'geodir-converter' ), $total_reviews ) );
		}

		// Exit early if there are no reviews to import.
		if ( 0 === $total_reviews ) {
			$this->log( __( 'No reviews available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get comments for current batch.
		$reviews = $db->get_results(
			$db->prepare(
				"SELECT 
                r.`id` as `review_id`, 
                r.`status`, 
                r.`listing_id`, 
                r.`user_id`, 
                r.`date`, 
                r.`review`, 
                u.`user_first_name`, 
                u.`user_last_name`, 
                u.`user_email`, 
                r.`rating_id` 
				FROM `$reviews_table` AS r 
                LEFT JOIN `$users_table` AS u ON r.`user_id` = u.`id`  
                LIMIT %d,%d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $reviews ) ) {
			$this->log( __( 'No more reviews to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		foreach ( $reviews as $review ) {
			$review_agent    = 'geodir-converter' . md5( $review->user_email ) . $review->review_id;
			$existing_review = get_comments(
				array(
					'comment_agent' => $review_agent,
					'number'        => 1,
				)
			);

			$listing_id = isset( $listings_mapping[ (int) $review->listing_id ] ) ? $listings_mapping[ (int) $review->listing_id ] : 0;
			if ( ! $listing_id ) {
				/* translators: %s: review ID */
				$this->log( sprintf( __( 'Failed to import review %s.', 'geodir-converter' ), $review->review_id ) );
				++$failed;
				continue;
			}

			$approved = 0;
			if ( 'active' === $review->status ) {
				$approved = 1;
			}

			$comment_data = array(
				'comment_post_ID'      => $listing_id,
				'user_id'              => isset( $user_mapping[ (int) $review->user_id ] ) ? $user_mapping[ (int) $review->user_id ] : 1,
				'comment_date'         => $review->date,
				'comment_date_gmt'     => $review->date,
				'comment_content'      => $review->review,
				'comment_author'       => $review->user_first_name . ' ' . $review->user_last_name,
				'comment_author_email' => $review->user_email,
				'comment_agent'        => $review_agent,
				'comment_approved'     => $approved,
			);

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				$existing_review ? ++$skipped : ++$imported;
				continue;
			}

			if ( ! empty( $existing_review ) && isset( $existing_review[0]->comment_ID ) ) {
				$comment_data['comment_ID'] = (int) $existing_review[0]->comment_ID;

				$comment_id = wp_update_comment( $comment_data );
			} else {
				$comment_id = wp_insert_comment( $comment_data );
			}

			if ( is_wp_error( $comment_id ) ) {
				/* translators: %s: review ID */
				$this->log( sprintf( __( 'Failed to import review %s.', 'geodir-converter' ), $review->review_id ) );
				++$failed;
				continue;
			}

			// Unset any existing rating.
			unset( $_REQUEST['geodir_overallrating'] );

			// Set the rating.
			if ( $review->rating_id ) {
				$rating = $db->get_var(
					$db->prepare(
						"SELECT rating FROM $ratings_table WHERE id = %d",
						$review->rating_id
					)
				);

				if ( $rating ) {
					$_REQUEST['geodir_overallrating'] = absint( $rating );
					GeoDir_Comments::save_rating( $comment_id );
				}
			}

			$existing_review ? ++$skipped : ++$imported;
		}

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_reviews );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d reviews imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_reviews
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Reviews import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_reviews,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Import posts from PMD to WordPress.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_posts( array $task ) {
		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset        = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported      = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed        = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped       = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$total_posts   = isset( $task['total_posts'] ) ? absint( $task['total_posts'] ) : 0;
		$batch_size    = absint( $this->get_batch_size() );
		$user_mapping  = (array) $this->options_handler->get_option_no_cache( 'user_mapping', array() );
		$posts_mapping = (array) $this->options_handler->get_option_no_cache( 'posts_mapping', array() );
		$posts_table   = $this->db_prefix . 'blog';

		// Determine total posts count if not set.
		if ( ! isset( $task['total_posts'] ) ) {
			$total_posts         = (int) $db->get_var( "SELECT COUNT(*) FROM {$posts_table}" );
			$task['total_posts'] = $total_posts;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of posts */
			$this->log( sprintf( __( 'Starting posts import: %d posts found.', 'geodir-converter' ), $total_posts ) );
		}

		// Exit early if there are no posts to import.
		if ( 0 === $total_posts ) {
			$this->log( __( 'No posts available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get posts for current batch.
		$posts = $db->get_results(
			$db->prepare(
				"SELECT p.* FROM {$posts_table} p ORDER BY p.id ASC LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $posts ) ) {
			$this->log( __( 'No more posts to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		foreach ( $posts as $post ) {

			$post_id   = ! $this->is_test_mode() ? $this->get_gd_post_id( $post->id, 'pmd_blog_id' ) : false;
			$is_update = ! empty( $post_id );
			$status    = 'active' === $post->status ? 'publish' : 'draft';

			$post_data = array(
				'post_author'       => isset( $user_mapping[ (int) $post->user_id ] ) ? $user_mapping[ (int) $post->user_id ] : 1,
				'post_content'      => ( $post->content ) ? $post->content : '',
				'post_title'        => ( $post->title ) ? $post->title : '',
				'post_name'         => ( $post->friendly_url ) ? $post->friendly_url : '',
				'post_excerpt'      => ( $post->content_short ) ? $post->content_short : '',
				'post_status'       => $status,
				'post_date_gmt'     => ( $post->date ) ? $post->date : '',
				'post_date'         => ( $post->date ) ? $post->date : '',
				'post_modified_gmt' => ( $post->date_updated ) ? $post->date_updated : '',
				'post_modified'     => ( $post->date_updated ) ? $post->date_updated : '',
			);

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				$is_update ? ++$skipped : ++$imported;
				continue;
			}

			// Insert or update the post.
			if ( $is_update ) {
				$post_data['ID'] = absint( $post_id );
				$post_id         = wp_update_post( $post_data, true );
			} else {
				$post_id = wp_insert_post( $post_data, true );
			}

			if ( is_wp_error( $post_id ) ) {
				/* translators: %s: post title */
				$this->log( sprintf( __( 'Failed to import post %s.', 'geodir-converter' ), $post->title ) );
				++$failed;
				continue;
			}

			// Store post mapping.
			$posts_mapping[ $post->id ] = $post_id;

			// PMD ID meta.
			update_post_meta( $post_id, 'pmd_blog_id', $post->id );

			// maybe attach featured image.
			if ( ! empty( $post->image_extension ) ) {
				$url           = trailingslashit( $this->url ) . 'files/blog/' . absint( $post->id ) . '.' . $post->image_extension;
				$attachment_id = media_sideload_image( $url, $post->id, $post->title, 'id' );

				if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
					set_post_thumbnail( $post_id, $attachment_id );
				}
			}

			// set categories.
			$categories = $this->get_post_categories( $post->id, 'blog', 'blog_id' );
			if ( ! empty( $categories ) ) {
				wp_set_post_categories( $post_id, $categories );
			}

			++$imported;
		}

		// Update posts mapping.
		$this->options_handler->update_option( 'posts_mapping', $posts_mapping );

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_posts );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d posts imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_posts
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Post import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_posts,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Retrieves the categories for a given post.
	 *
	 * @since 2.0.2
	 *
	 * @param int    $post_id   The post ID.
	 * @param string $table     The PMD table name prefix for categories lookup.
	 * @param string $column_id The column name for the post ID in the lookup table.
	 * @return array The categories.
	 */
	public function get_post_categories( $post_id, $table = 'blog', $column_id = 'blog_id' ) {
		$db         = $this->get_db_connection();
		$cats_table = $this->db_prefix . $table . '_categories_lookup';

		$category_mapping = (array) $this->options_handler->get_option_no_cache( $table . '_category_mapping' );

		$categories_lookup = $db->get_results(
			$db->prepare(
				"SELECT category_id FROM {$cats_table} c WHERE c.{$column_id} = %d ORDER BY c.category_id",
				(int) $post_id
			)
		);

		$categories = array();
		foreach ( $categories_lookup as $category ) {
			if ( isset( $category_mapping[ (int) $category->category_id ] ) ) {
				$categories[] = (int) $category_mapping[ (int) $category->category_id ];
			}
		}

		return $categories;
	}

	/**
	 * Import pages from PMD to WordPress.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_pages( array $task ) {
		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset      = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported    = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed      = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped     = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$total_pages = isset( $task['total_pages'] ) ? absint( $task['total_pages'] ) : 0;
		$batch_size  = absint( $this->get_batch_size() );
		$pages_table = $this->db_prefix . 'pages';

		// Determine total pages count if not set.
		if ( ! isset( $task['total_pages'] ) ) {
			$total_pages         = (int) $db->get_var( "SELECT COUNT(*) FROM {$pages_table}" );
			$task['total_pages'] = $total_pages;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of pages */
			$this->log( sprintf( __( 'Starting pages import: %d pages found.', 'geodir-converter' ), $total_pages ) );
		}

		// Exit early if there are no pages to import.
		if ( 0 === $total_pages ) {
			$this->log( __( 'No pages available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get pages for current batch.
		$pages = $db->get_results(
			$db->prepare(
				"SELECT p.* FROM {$pages_table} p ORDER BY p.id ASC LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $pages ) ) {
			$this->log( __( 'No more pages to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		foreach ( $pages as $page ) {

			$page_id   = ! $this->is_test_mode() ? $this->get_gd_post_id( $page->id, 'pmd_page_id' ) : false;
			$is_update = ! empty( $page_id );
			$status    = ( ! empty( $page->active ) && '1' == $page->active ) ? 'publish' : 'draft';

			$current_time     = current_time( 'Y-m-d H:i:s' );
			$current_time_gmt = current_time( 'Y-m-d H:i:s', true );

			$page_data = array(
				'post_author'       => (int) $this->get_import_setting( 'author' ),
				'post_content'      => ( $page->content ) ? $page->content : '',
				'post_title'        => ( $page->title ) ? $page->title : '',
				'post_name'         => ( $page->friendly_url ) ? $page->friendly_url : '',
				'post_type'         => 'page',
				'post_status'       => $status,
				'post_date_gmt'     => $current_time_gmt,
				'post_date'         => $current_time,
				'post_modified_gmt' => $current_time_gmt,
				'post_modified'     => $current_time,
			);

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				$is_update ? ++$skipped : ++$imported;
				continue;
			}

			// Insert or update the post.
			if ( $is_update ) {
				$page_data['ID'] = absint( $page_id );
				$page_id         = wp_update_post( $page_data, true );
			} else {
				$page_id = wp_insert_post( $page_data, true );
			}

			if ( is_wp_error( $page_id ) ) {
				/* translators: %s: page title */
				$this->log( sprintf( __( 'Failed to import page %s.', 'geodir-converter' ), $page->title ) );
				++$failed;
				continue;
			}

			// PMD ID meta.
			update_post_meta( $page_id, 'pmd_page_id', $page->id );

			++$imported;
		}

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_pages );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d pages imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_pages
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Page import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_pages,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Import comments from PMD to WordPress.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_comments( array $task ) {
		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset         = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported       = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed         = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped        = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$total_comments = isset( $task['total_comments'] ) ? absint( $task['total_comments'] ) : 0;
		$batch_size     = absint( $this->get_batch_size() );
		$posts_mapping  = (array) $this->options_handler->get_option_no_cache( 'posts_mapping', array() );
		$comments_table = $this->db_prefix . 'blog_comments';
		$users_table    = $this->db_prefix . 'users';

		// Determine total comments count if not set.
		if ( ! isset( $task['total_comments'] ) ) {
			$total_comments         = (int) $db->get_var( "SELECT COUNT(*) FROM {$comments_table}" );
			$task['total_comments'] = $total_comments;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of comments */
			$this->log( sprintf( __( 'Starting comments import: %d comments found.', 'geodir-converter' ), $total_comments ) );
		}

		// Exit early if there are no comments to import.
		if ( 0 === $total_comments ) {
			$this->log( __( 'No comments available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get comments for current batch.
		$comments = $db->get_results(
			$db->prepare(
				"SELECT c.id AS comment_id, 
                c.status, 
                c.blog_id, 
                c.user_id, 
                c.date, 
                c.comment, 
                c.name AS user_comment_name, 
                c.email AS user_comment_email, 
                u.user_first_name, 
                u.user_last_name, 
                u.user_email 
                FROM {$comments_table} c 
                LEFT JOIN {$users_table} u ON c.user_id = u.id 
                LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $comments ) ) {
			$this->log( __( 'No more comments to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		foreach ( $comments as $comment ) {
			$comment_agent    = 'geodir-converter' . md5( $comment->user_email ) . $comment->comment_id;
			$existing_comment = get_comments(
				array(
					'comment_agent' => $comment_agent,
					'number'        => 1,
				)
			);

			if ( ! isset( $posts_mapping[ $comment->blog_id ] ) ) {
				/* translators: %s: comment ID */
				$this->log( sprintf( __( 'Failed to import comment %s.', 'geodir-converter' ), $comment->comment_id ) );
				++$failed;
				continue;
			}

			$approved = 0;
			if ( 'active' === $comment->status ) {
				$approved = 1;
			}

			$post_id = absint( $posts_mapping[ $comment->blog_id ] );

			$comment_author = ! empty( $comment->user_first_name ) && ! empty( $comment->user_last_name ) ? $comment->user_first_name . ' ' . $comment->user_last_name : $comment->user_comment_name;

			$comment_data = array(
				'comment_post_ID'      => $post_id,
				'user_id'              => $comment->user_id,
				'comment_date'         => $comment->date,
				'comment_date_gmt'     => $comment->date,
				'comment_content'      => $comment->comment,
				'comment_author'       => $comment_author,
				'comment_author_email' => ! empty( $comment->user_email ) ? $comment->user_email : $comment->user_comment_email,
				'comment_agent'        => $comment_agent,
				'comment_approved'     => $approved,
			);

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				$existing_comment ? ++$skipped : ++$imported;
				continue;
			}

			if ( $existing_comment && isset( $existing_comment[0]->comment_ID ) ) {
				$comment_data['comment_ID'] = (int) $existing_comment[0]->comment_ID;

				$comment_id = wp_update_comment( $comment_data );
			} else {
				$comment_id = wp_insert_comment( $comment_data );
			}

			if ( is_wp_error( $comment_id ) ) {
				/* translators: %s: comment ID */
				$this->log( sprintf( __( 'Failed to import comment %s.', 'geodir-converter' ), $comment->comment_id ) );
				++$failed;
				continue;
			}

			$existing_comment ? ++$skipped : ++$imported;
		}

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_comments );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d comments imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_comments
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Comments import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_comments,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Import discounts from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_discounts( array $task ) {
		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset          = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported        = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed          = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped         = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$total_discounts = isset( $task['total_discounts'] ) ? absint( $task['total_discounts'] ) : 0;
		$batch_size      = absint( $this->get_batch_size() );
		$discounts_table = $this->db_prefix . 'discount_codes';

		// Determine total discounts count if not set.
		if ( ! isset( $task['total_discounts'] ) ) {
			$total_discounts         = (int) $db->get_var( "SELECT COUNT(*) FROM {$discounts_table}" );
			$task['total_discounts'] = $total_discounts;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of discounts */
			$this->log( sprintf( __( 'Starting discounts import: %d discounts found.', 'geodir-converter' ), $total_discounts ) );
		}

		// Exit early if there are no discounts to import.
		if ( 0 === $total_discounts ) {
			$this->log( __( 'No discounts available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get discounts for current batch.
		$discounts = $db->get_results(
			$db->prepare(
				"SELECT d.* FROM {$discounts_table} d ORDER BY d.id ASC LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $discounts ) ) {
			$this->log( __( 'No more discounts to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$packages_mapping = (array) $this->options_handler->get_option_no_cache( 'packages_mapping', array() );

		foreach ( $discounts as $discount ) {
			$wpinv_discount = new WPInv_Discount( $discount->code );
			$is_update      = $wpinv_discount->exists();

			$pricing_ids    = explode( ',', $discount->pricing_ids );
			$discount_items = array();

			foreach ( $pricing_ids as $item_id ) {
				$package_id = ( isset( $packages_mapping[ $item_id ] ) ) ? (int) $packages_mapping[ $item_id ] : 0;
				$wpinv_item = wpinv_get_item_by( 'custom_id', $package_id, 'package' );

				if ( $wpinv_item && $wpinv_item->exists() ) {
					$discount_items[] = (int) $wpinv_item->id;
				}
			}

			$required_pricing_ids = explode( ',', $discount->pricing_ids_required );
			$required_items       = array();

			foreach ( $required_pricing_ids as $item_id ) {
				$package_id = ( isset( $packages_mapping[ $item_id ] ) ) ? (int) $packages_mapping[ $item_id ] : 0;
				$wpinv_item = wpinv_get_item_by( 'custom_id', $package_id, 'package' );

				if ( $wpinv_item && $wpinv_item->exists() ) {
					$required_items[] = (int) $wpinv_item->id;
				}
			}

			$wpinv_discount->set_props(
				array(
					'name'           => $discount->title,
					'code'           => $discount->code,
					'amount'         => $discount->value,
					'status'         => 'publish',
					'start'          => date( 'Y-m-d H:i:s', strtotime( $discount->date_start ) ),
					'expiration'     => date( 'Y-m-d H:i:s', strtotime( $discount->date_expire ) ),
					'is_single_use'  => ! empty( $discount->is_single_use ),
					'type'           => ( 'percentage' === $discount->discount_type ) ? 'percent' : 'flat',
					'is_recurring'   => ( 'onetime' === $discount->type ) ? false : true,
					'items'          => array_filter( wp_parse_id_list( $discount_items ) ),
					'required_items' => array_filter( wp_parse_id_list( $required_items ) ),
					'max_uses'       => $discount->used_limit,
				)
			);

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				$is_update ? ++$skipped : ++$imported;
				continue;
			}

			$discount_id = $wpinv_discount->save();

			if ( is_wp_error( $discount_id ) ) {
				/* translators: %s: discount title */
				$this->log( sprintf( __( 'Failed to import discount %s.', 'geodir-converter' ), $discount->title ) );
				++$failed;
				continue;
			}

			$is_update ? ++$skipped : ++$imported;
		}

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_discounts );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d discounts imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_discounts
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Discount import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_discounts,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Import invoices from PMD to GeoDirectory.
	 *
	 * @since 2.0.2
	 *
	 * @param array $task Import task details.
	 * @return array|false Result of the import operation or false if import is complete.
	 * @throws Exception If database connection fails.
	 */
	public function task_import_invoices( array $task ) {
		if ( ! class_exists( 'WPInv_Plugin' ) ) {
			$this->log( __( 'Invoices plugin is not active. Skipping invoices...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		$db = $this->get_db_connection();
		if ( is_wp_error( $db ) ) {
			throw new Exception( $db->get_error_message() );
		}

		wp_suspend_cache_addition( true );

		$offset         = isset( $task['offset'] ) ? absint( $task['offset'] ) : 0;
		$imported       = isset( $task['imported'] ) ? absint( $task['imported'] ) : 0;
		$failed         = isset( $task['failed'] ) ? absint( $task['failed'] ) : 0;
		$skipped        = isset( $task['skipped'] ) ? absint( $task['skipped'] ) : 0;
		$total_invoices = isset( $task['total_invoices'] ) ? absint( $task['total_invoices'] ) : 0;
		$user_mapping   = (array) $this->options_handler->get_option_no_cache( 'user_mapping', array() );
		$batch_size     = absint( $this->get_batch_size() );
		$invoices_table = $this->db_prefix . 'invoices';

		// Determine total invoices count if not set.
		if ( ! isset( $task['total_invoices'] ) ) {
			$total_invoices         = (int) $db->get_var( "SELECT COUNT(*) FROM {$invoices_table}" );
			$task['total_invoices'] = $total_invoices;
		}

		// Log the import start message only for the first batch.
		if ( 0 === $offset ) {
			/* translators: %d: number of invoices */
			$this->log( sprintf( __( 'Starting invoices import: %d invoices found.', 'geodir-converter' ), $total_invoices ) );
		}

		// Exit early if there are no invoices to import.
		if ( 0 === $total_invoices ) {
			$this->log( __( 'No invoices available for import. Skipping...', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get invoices for current batch.
		$invoices = $db->get_results(
			$db->prepare(
				"SELECT i.* FROM {$invoices_table} i ORDER BY i.id ASC LIMIT %d, %d",
				$offset,
				$batch_size
			)
		);

		if ( empty( $invoices ) ) {
			$this->log( __( 'No more invoices to import. Process completed.', 'geodir-converter' ) );
			return $this->next_task( $task );
		}

		// Get packages mapping.
		$packages_mapping = (array) $this->options_handler->get_option_no_cache( 'packages_mapping', array() );

		foreach ( $invoices as $invoice ) {

			$invoice_id = ! $this->is_test_mode() ? $this->get_gd_post_id( $invoice->id, 'pmd_invoice_id' ) : false;
			$is_update  = ! empty( $invoice_id );
			$user_id    = isset( $user_mapping[ $invoice->user_id ] ) ? $user_mapping[ $invoice->user_id ] : 0;
			$user_meta  = get_user_meta( $user_id );

			// Map PMD status to GeoDirectory status.
			$status_map = array(
				'unpaid'   => 'wpi-pending',
				'canceled' => 'wpi-cancelled',
				'paid'     => 'publish',
			);
			$status     = isset( $status_map[ $invoice->status ] ) ? $status_map[ $invoice->status ] : $invoice->status;

			$user_address = isset( $user_meta['user_address1'][0] ) ? $user_meta['user_address1'][0] : '';
			if ( isset( $user_meta['user_address2'][0] ) && ! empty( $user_meta['user_address2'][0] ) ) {
				$user_address .= ' ' . $user_meta['user_address2'][0];
			}

			$taxes = array();
			if ( (float) $invoice->tax > 0 ) {
				$taxes[ __( 'Tax', 'geodir-converter' ) ] = array( 'initial_tax' => (float) $invoice->tax );
			}

			if ( (float) $invoice->tax2 > 0 ) {
				$taxes[ __( 'Tax 2', 'geodir-converter' ) ] = array( 'initial_tax' => (float) $invoice->tax2 );
			}

			$wpi_invoice = new WPInv_Invoice();
			$wpi_invoice->set_props(
				array(
					// Basic info.
					'post_type'      => 'wpi_invoice',
					'post_title'     => $invoice->id,
					'post_name'      => 'inv-' . $invoice->id,
					'description'    => $invoice->description,
					'status'         => $status,
					'created_via'    => 'geodir-converter',
					'date_created'   => date( 'Y-m-d H:i:s', strtotime( $invoice->date ) ),

					// Payment info.
					'gateway'        => strtolower( $invoice->gateway_id ),
					'discount_code'  => $invoice->discount_code,
					'discounts'      => (float) $invoice->discount_code_value > 0 ? array(
						array(
							'initial_discount'   => $invoice->discount_code_value,
							'recurring_discount' => 0,
						),
					) : array(),
					'taxes'          => $taxes,
					'total'          => (float) $invoice->total,
					'subtotal'       => (float) $invoice->subtotal,
					'due_date'       => date( 'Y-m-d H:i:s', strtotime( $invoice->date_due ) ),
					'date_completed' => date( 'Y-m-d H:i:s', strtotime( $invoice->date_paid ) ),

					// Billing details.
					'user_id'        => $user_id,
					'first_name'     => isset( $user_meta['first_name'][0] ) ? $user_meta['first_name'][0] : '',
					'last_name'      => isset( $user_meta['last_name'][0] ) ? $user_meta['last_name'][0] : '',
					'address'        => $user_address,
					'company'        => isset( $user_meta['user_organization'][0] ) ? $user_meta['user_organization'][0] : '',
					'zip'            => isset( $user_meta['user_zip'][0] ) ? $user_meta['user_zip'][0] : '',
					'state'          => isset( $user_meta['user_state'][0] ) ? $user_meta['user_state'][0] : '',
					'city'           => isset( $user_meta['user_city'][0] ) ? $user_meta['user_city'][0] : '',
					'country'        => isset( $user_meta['user_country'][0] ) ? $user_meta['user_country'][0] : '',
					'phone'          => isset( $user_meta['user_phone'][0] ) ? $user_meta['user_phone'][0] : '',
				)
			);

			// Set item data.
			$package_id = isset( $packages_mapping[ $invoice->order_id ] ) ? $packages_mapping[ $invoice->order_id ] : 0;
			$wpinv_item = wpinv_get_item_by( 'custom_id', $package_id, 'package' );

			if ( $wpinv_item ) {
				$item = new GetPaid_Form_Item( $wpinv_item->get_id() );
				$item->set_name( $wpinv_item->get_name() );
				$item->set_description( $wpinv_item->get_description() );
				$item->set_price( $wpinv_item->get_price() );
				$item->set_quantity( 1 );
				$wpi_invoice->add_item( $item );
			} else {
				$package = GeoDir_Pricing_Package::get_package( (int) $package_id );
				if ( $package ) {
					$item = new GetPaid_Form_Item( $package['id'] );
					$item->set_name( $package['title'] );
					$item->set_description( $package['description'] );
					$item->set_price( (float) $package['amount'] );
					$item->set_quantity( 1 );
					$wpi_invoice->add_item( $item );
				}
			}

			// Insert or update the post.
			if ( $is_update ) {
				$wpi_invoice->ID = absint( $invoice_id );
			}

			// Handle test mode.
			if ( $this->is_test_mode() ) {
				$is_update ? ++$skipped : ++$imported;
				continue;
			}

			$wpi_invoice_id = $wpi_invoice->save();

			if ( is_wp_error( $wpi_invoice_id ) ) {
				/* translators: %s: invoice title */
				$this->log( sprintf( __( 'Failed to import invoice %s.', 'geodir-converter' ), $invoice->title ) );
				++$failed;
				continue;
			}

			// Update post meta.
			update_post_meta( $wpi_invoice_id, 'pmd_invoice_id', $invoice->id );

			$is_update ? ++$skipped : ++$imported;
		}

		// Update task progress.
		$task['imported'] = absint( $imported );
		$task['failed']   = absint( $failed );
		$task['skipped']  = absint( $skipped );

		$this->increase_succeed_imports( $imported );
		$this->increase_failed_imports( $failed );
		$this->increase_skipped_imports( $skipped );

		$complete = ( $offset + $batch_size >= $total_invoices );

		if ( ! $complete ) {
			$this->log(
				sprintf(
					/* translators: %1$d: processed count, %2$d: total count */
					__( 'Batch complete. Progress: %1$d/%2$d invoices imported.', 'geodir-converter' ),
					( $imported + $failed + $skipped ),
					$total_invoices
				)
			);
			$task['offset'] = $offset + $batch_size;
			return $task;
		}

		/* translators: %1$d: processed count, %2$d: total count, %3$d: imported, %4$d: failed, %5$d: skipped */
		$message = sprintf(
			__( 'Invoice import completed: %1$d/%2$d processed. Imported: %3$d, Failed: %4$d, Skipped: %5$d.', 'geodir-converter' ),
			( $imported + $failed + $skipped ),
			$total_invoices,
			$imported,
			$failed,
			$skipped
		);

		$this->log( $message, 'success' );

		return $this->next_task( $task );
	}

	/**
	 * Generate a unique GD field key.
	 *
	 * @since 2.0.2
	 *
	 * @param string $field_name The field name.
	 * @return string The generated GD field key.
	 */
	private function get_field_key( $field_name ) {
		$field_name = ! empty( $field_name ) ? $field_name : sanitize_title( $field_name );
		$field_key  = sanitize_key( $field_name );

		return $field_key;
	}

	/**
	 * Get the corresponding GD field key for a given shortname.
	 *
	 * @since 2.0.2
	 *
	 * @param string $shortname The field shortname.
	 * @return string The mapped field key or the original shortname if no match is found.
	 */
	private function map_field_key( $shortname ) {
		$fields_map = array(
			'listing_title'     => 'post_title',
			'short_description' => 'post_excerpt',
			'description'       => 'post_content',
			'listing_category'  => 'post_category',
			'listing_tags'      => 'post_tags',
			'xtwitter'          => 'twitter',
			'zip_code'          => 'zip',
		);

		return isset( $fields_map[ $shortname ] ) ? $fields_map[ $shortname ] : $shortname;
	}

	/**
	 * Map PMD field type to GeoDirectory field type.
	 *
	 * @since 2.0.2
	 *
	 * @param string $field_type The PMD field type.
	 * @return string The GeoDirectory field type.
	 */
	private function map_field_type( $field_type ) {
		switch ( $field_type ) {
			case 'text':
			case 'color':
			case 'hidden':
				return 'text';
			case 'htmleditor':
				return 'html';
			case 'textarea':
				return 'textarea';
			case 'image':
				return 'file';
			case 'select':
				return 'select';
			case 'select_multiple':
				return 'multiselect';
			case 'text_select':
			case 'currency':
				return 'text';
			case 'url':
				return 'url';
			case 'radio':
				return 'radio';
			case 'checkbox':
				return 'checkbox';
			case 'date':
				return 'datetime';
			case 'number':
				return 'int';
			case 'decimal':
			case 'rating':
				return 'float';
			default:
				return 'text';
		}
	}

	/**
	 * Map PMD field type to GeoDirectory data type.
	 *
	 * @since 2.0.2
	 *
	 * @param string $field_type The PMD field type.
	 * @return string The GeoDirectory data type.
	 */
	private function map_data_type( $field_type ) {
		switch ( $field_type ) {
			case 'text':
			case 'textarea':
			case 'url':
			case 'select':
			case 'select_multiple':
			case 'text_select':
			case 'currency':
			case 'color':
			case 'radio':
			case 'hidden':
			case 'htmleditor':
				return 'TEXT';
			case 'checkbox':
				return 'TINYINT';
			case 'date':
				return 'DATE';
			case 'number':
				return 'INT';
			case 'decimal':
				return 'DECIMAL';
			case 'rating':
				return 'FLOAT';
			default:
				return 'VARCHAR';
		}
	}

	/**
	 * Get field options for select, radio, checkbox fields.
	 *
	 * @since 2.0.2
	 *
	 * @param string $field_options The field options.
	 * @return string The formatted options string.
	 */
	private function get_field_options( $field_options ) {
		if ( empty( $field_options ) ) {
			return '';
		}

		$options = maybe_unserialize( $field_options );
		if ( ! is_array( $options ) ) {
			return '';
		}

		$formatted_options = array();
		foreach ( $options as $key => $value ) {
			$formatted_options[] = $key . ':' . $value;
		}

		return implode( '\n', $formatted_options );
	}

	/**
	 * Handle authentication for imported PMD users.
	 *
	 * Allows users imported from PhpMyDirectory to log in using their original
	 * passwords and automatically upgrades their password to WordPress format
	 * after successful authentication.
	 *
	 * @since 2.0.2
	 *
	 * @param WP_User|WP_Error|null $user     WP_User object if the user is authenticated.
	 * @param string                $password The password in plain text.
	 * @return WP_User|WP_Error|null The authenticated user or the original response.
	 */
	public function handle_user_login( $user, $password ) {
		// Return early if no valid user object.
		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		// Get user's PMD password hash type and salt.
		$hash_type = get_user_meta( $user->ID, 'pmd_password_hash', true );

		// Return early if not a PMD user.
		if ( empty( $hash_type ) ) {
			return $user;
		}

		// Get salt (not all users have salts).
		$salt     = get_user_meta( $user->ID, 'pmd_password_salt', true );
		$salt     = ! empty( $salt ) ? $salt : '';
		$is_valid = false;

		// Verify password based on hash type using timing-safe comparison.
		if ( 'md5' === $hash_type ) {
			$is_valid = hash_equals( $user->user_pass, md5( $password . $salt ) ) ||
				hash_equals( $user->user_pass, md5( $salt . $password ) );
		} elseif ( 'sha256' === $hash_type ) {
			$is_valid = hash_equals( $user->user_pass, hash( 'sha256', $password . $salt ) ) ||
				hash_equals( $user->user_pass, hash( 'sha256', $salt . $password ) );
		}

		// If password is valid, upgrade to WordPress password system.
		if ( $is_valid ) {
			wp_set_password( $password, $user->ID );
			delete_user_meta( $user->ID, 'pmd_password_hash' );
			delete_user_meta( $user->ID, 'pmd_password_salt' );
		}

		return $user;
	}
}
