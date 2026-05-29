<?php
namespace AdvancedWcCsvImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Coordinator Class
 *
 * Bootstraps the Admin controllers, AJAX handlers, CLI modules, and Action Scheduler hook registrations.
 */
class Plugin {

	/**
	 * Singleton Instance.
	 *
	 * @var Plugin
	 */
	private static $instance = null;

	/**
	 * Get Singleton Instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers hooks and instantiates controller services.
	 */
	private function __construct() {
		$this->init_components();
	}

	/**
	 * Initialize components.
	 */
	private function init_components() {
		// 1. Boot up Admin Controller.
		$admin_controller = new Admin\Controller();
		$admin_controller->init();

		// 2. Boot up AJAX Handlers.
		$ajax_handler = new Admin\AjaxHandler();
		$ajax_handler->register_hooks();

		// 3. Register Action Scheduler background processing queues.
		$queue_manager = new Services\QueueManager();
		$queue_manager->register_hooks();

		// 4. Register Action Scheduler background retry queues.
		$retry_service = new Services\RetryService();
		$retry_service->register_hooks();

		// 5. Initialize WP-CLI commands if running in CLI mode.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'cli_init', array( $this, 'register_cli_commands' ) );
		}
	}

	/**
	 * Register WP-CLI hooks.
	 */
	public function register_cli_commands() {
		\WP_CLI::add_command( 'wc-import', '\AdvancedWcCsvImporter\Cli\CliController' );
	}
}
