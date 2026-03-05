<?php
/**
 * Background Process for GeoDir Converter
 *
 * @package GeoDir_Converter
 * @subpackage Importers
 * @since 2.0.2
 */

namespace GeoDir_Converter\Importers;

use Exception;
use InvalidArgumentException;
use GeoDir_Background_Process;
use GeoDir_Converter\Abstracts\GeoDir_Converter_Importer;
use GeoDir_Converter\Exceptions\GeoDir_Converter_Execution_Time_Exception;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GeoDir_Background_Process', false ) ) {
	require_once GEODIRECTORY_PLUGIN_DIR . '/includes/abstracts/class-geodir-background-process.php';
}

/**
 * GeoDir Converter Background Process class.
 *
 * @since 2.0.2
 */
class GeoDir_Converter_Background_Process extends GeoDir_Background_Process {
	/**
	 * Maximum execution time (in seconds) for a single request.
	 *
	 * Prevents PHP timeouts during large imports.
	 *
	 * @var int
	 */
	public const MAX_REQUEST_TIMEOUT = 30;

	/**
	 * The importer instance.
	 *
	 * @var GeoDir_Converter_Importer
	 */
	protected $importer;

	/**
	 * Maximum execution time for the background process.
	 *
	 * @var int
	 */
	protected $max_execution_time = 0;

	/**
	 * Constructor.
	 *
	 * @since 2.0.2
	 * @param GeoDir_Converter_Importer $importer The importer instance.
	 */
	public function __construct( $importer ) {
		$this->action   = 'geodir_converter_import_' . $importer->get_id();
		$this->importer = $importer;

		parent::__construct();

		$this->max_execution_time = intval( ini_get( 'max_execution_time' ) );
	}

	/**
	 * Calculates time left for the background process.
	 *
	 * @since 2.0.2
	 *
	 * @return int Time remaining in seconds before the process should stop.
	 */
	protected function time_left() {
		if ( $this->max_execution_time > 0 ) {
			return $this->start_time + $this->max_execution_time - time();
		} else {
			return self::MAX_REQUEST_TIMEOUT;
		}
	}

	/**
	 * Checks if the background process is in progress.
	 *
	 * @since 2.0.2
	 * @return bool True if the process is running, false otherwise.
	 */
	public function is_in_progress() {
		return $this->is_process_running() || ! $this->is_queue_empty();
	}

	/**
	 * Checks if the background process is aborting.
	 *
	 * @since 2.0.2
	 *
	 * @return bool True if the process is aborting, false otherwise.
	 */
	public function is_aborting() {
		return $this->importer->options_handler->get_option_no_cache( 'abort_current', false );
	}

	/**
	 * Checks if the background process is paused.
	 *
	 * @since 2.2.0
	 *
	 * @return bool True if the process is paused, false otherwise.
	 */
	public function is_paused() {
		return (bool) $this->importer->options_handler->get_option_no_cache( 'paused', false );
	}

	/**
	 * Pauses the background process.
	 *
	 * Sets the paused flag. The currently running batch will finish
	 * its current item, then stop picking up new items.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function pause() {
		if ( $this->is_in_progress() && ! $this->is_paused() ) {
			$this->importer->options_handler->update_option( 'paused', true );
			$this->clear_scheduled_event();
		}
	}

	/**
	 * Resumes a paused background process.
	 *
	 * Clears the paused flag and re-dispatches the queue.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function resume() {
		if ( $this->is_paused() ) {
			$this->importer->options_handler->delete_option( 'paused' );

			if ( ! $this->is_queue_empty() ) {
				$this->dispatch();
			}
		}
	}

	/**
	 * Dispatches the background process.
	 *
	 * Overrides parent to prevent dispatch when paused.
	 *
	 * @since 2.2.0
	 * @return array|WP_Error|void
	 */
	public function dispatch() {
		if ( $this->is_paused() ) {
			return;
		}

		return parent::dispatch();
	}

	/**
	 * Touches the background process to restart if needed.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function touch() {
		if ( $this->is_paused() ) {
			return;
		}

		if ( ! $this->is_process_running() && ! $this->is_queue_empty() ) {
			// Background process down, but was not finished. Restart it.
			$this->dispatch();
		}
	}

	/**
	 * Aborts the background process if it's in progress.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function abort() {
		if ( $this->is_in_progress() || $this->is_paused() ) {
			// Clear pause first so the abort can proceed.
			$this->importer->options_handler->delete_option( 'paused' );
			$this->importer->options_handler->update_option( 'abort_current', true );

			// If paused, the process isn't running, so manually clear the queue.
			if ( ! $this->is_process_running() ) {
				$this->delete_all_batches();
				$this->clear_scheduled_event();
				$this->clear_options();
				do_action( $this->identifier . '_complete' );
			}
		}
	}

	/**
	 * Clears options on start and finish.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function clear_options() {
		$this->importer->options_handler->delete_option( 'abort_current' );
		$this->importer->options_handler->delete_option( 'paused' );
	}

	/**
	 * Complete the background process.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	protected function complete() {
		parent::complete();

		$this->clear_options();

		do_action( $this->identifier . '_complete' );
	}

	/**
	 * Process a single task in the queue.
	 *
	 * @since 2.0.2
	 * @param mixed $task Queue item to iterate over.
	 * @return mixed Modified task for further processing or false to remove the item from the queue.
	 * @throws GeoDir_Converter_Execution_Time_Exception When the execution time limit is reached.
	 * @throws InvalidArgumentException When an invalid action is provided.
	 */
	protected function task( $task ) {
		if ( $this->is_aborting() ) {
			$this->cancel_process();
			return false;
		}

		// Pause check: stop processing without destroying the queue.
		if ( $this->is_paused() ) {
			add_filter( $this->identifier . '_time_exceeded', '__return_true' );
			return $task;
		}

		if ( ! isset( $task['action'] ) ) {
			return false;
		}

		$task['offset'] = isset( $task['offset'] ) ? (int) $task['offset'] : 0;

		try {
			// Time left until script termination.
			$time_left = $this->time_left();

			// Leave 5 seconds for importing/batching/logging.
			$timeout = min( $time_left - 5, self::MAX_REQUEST_TIMEOUT );

			if ( $timeout <= 0 ) {
				throw new GeoDir_Converter_Execution_Time_Exception(
					sprintf(
						/* translators: %d: Maximum execution time in seconds */
						__( 'Maximum execution time is set to %d seconds.', 'geodir-booking' ),
						$timeout
					)
				);
			}

			$action        = $task['action'];
			$import_method = "task_{$action}";

			if ( method_exists( $this->importer, $import_method ) ) {
				$this->importer->suspend_hooks();
				$result = $this->importer->$import_method( $task );
				$this->importer->restore_hooks();
				$this->importer->flush_stats();
				$this->importer->flush_logs();
				$this->importer->flush_failed_items();
				return $result;
			}

			throw new InvalidArgumentException(
				sprintf(
					/* translators: %s: Invalid action name */
					__( 'Invalid action: %s', 'geodir-converter' ),
					$import_method
				)
			);
		} catch ( GeoDir_Converter_Execution_Time_Exception $e ) {
			// Restart the process if execution time exceeded.
			add_filter( $this->identifier . '_time_exceeded', '__return_true' );

			$this->importer->log( $e->getMessage(), 'warning' );
			$this->importer->flush_stats();
			$this->importer->flush_logs();
			$this->importer->flush_failed_items();

			/**
			 * Edge case: Hosts with low `max_execution_time` settings.
			 * WP Background Processing does not check execution time and defaults to 20s per cycle.
			 * If the process times out, it relies on WP-Cron (5 min interval).
			 * This can cause an infinite loop if timeout is negative.
			 */
			return $task;
		} catch ( Exception $e ) {
			$this->importer->log( 'Import error: ' . $e->getMessage(), 'error' );
			$this->importer->flush_stats();
			$this->importer->flush_logs();
			$this->importer->flush_failed_items();
		}

		return false;
	}

	/**
	 * Adds converter tasks to the background process.
	 *
	 * @since 2.0.2
	 *
	 * @param array $workload The workload to process.
	 * @return void
	 */
	public function add_converter_tasks( $workload ) {
		$tasks = array(
			array_merge(
				$workload,
				array(
					'action' => $this->importer->get_action(),
				)
			),
		);

		$this->add_tasks( $tasks );
	}

	/**
	 * Adds import tasks to the background process.
	 *
	 * @since 2.0.2
	 *
	 * @param array $workloads Array of workload items, each containing id, title, and type.
	 * @return void
	 */
	public function add_import_tasks( $workloads ) {
		$tasks = array_map(
			function ( $workload ) {
				$workload['action'] = isset( $workload['action'] ) ? $workload['action'] : GeoDir_Converter_Importer::ACTION_IMPORT_LISTINGS;
				return $workload;
			},
			$workloads
		);

		$this->add_tasks( $tasks );
	}

	/**
	 * Adds tasks to the background process.
	 *
	 * @since 2.0.2
	 *
	 * @param array $tasks The tasks to add.
	 * @return void
	 */
	protected function add_tasks( $tasks ) {
		$batch_size = $this->importer->get_batch_size();
		$batches    = array_chunk( $tasks, $batch_size );

		foreach ( $batches as $batch ) {
			$this->data( $batch )->save();
		}

		$this->touch();
	}

	/**
	 * Re-queues failed items for retry.
	 *
	 * @since 2.2.0
	 * @return bool True if items were re-queued, false otherwise.
	 */
	public function retry_failed_items() {
		$failed_items = $this->importer->get_failed_items();

		if ( empty( $failed_items ) ) {
			return false;
		}

		// Build tasks from failed items, grouped by action.
		$tasks = array();
		foreach ( $failed_items as $item ) {
			$tasks[] = array(
				'action'    => isset( $item['action'] ) ? $item['action'] : GeoDir_Converter_Importer::ACTION_IMPORT_LISTINGS,
				'source_id' => $item['source_id'],
				'title'     => isset( $item['item_title'] ) ? $item['item_title'] : '',
				'retry'     => true,
			);
		}

		// Adjust stats: subtract failed count so progress recalculates correctly.
		$failed_count = count( $tasks );
		$stats        = (array) $this->importer->options_handler->get_option_no_cache( 'stats' );
		$empty_stats  = $this->importer->empty_stats();
		$stats        = wp_parse_args( $stats, $empty_stats );

		$stats['failed'] = max( 0, (int) $stats['failed'] - $failed_count );

		$this->importer->options_handler->update_option( 'stats', $stats );

		// Clear failed items and re-queue.
		$this->importer->clear_failed_items();
		$this->add_import_tasks( $tasks );

		return true;
	}
}
