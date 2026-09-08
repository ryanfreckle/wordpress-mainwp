<?php
/**
 * MainWP Development
 *
 * This class handles the extension process.
 *
 * @package MainWP/Extensions
 */

 namespace MainWP\Extensions\Development;

 /**
  * Class MainWP_Development
  *
  * @package MainWP/Extensions
  */
class MainWP_Development_Ajax {

	/**
	 * @var string The update version.
	 */
	public $update_version = '1.0';

	/**
	 * @var self|null The singleton instance of the class.
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self|null
	 */
	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * MainWP_Development_Ajax constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
	}

	/**
     * Admin init.
     *
	 * @return void
	 */
	public function admin_init() {
		do_action( 'mainwp_ajax_add_action', 'mainwp_development_migratedb_backup', array( &$this, 'ajax_migratedb_backup' ) );
	}

	/**
	 * Ajax handler: trigger a WP Migrate DB Pro database backup on one child site.
	 *
	 * Sends a signed 'extra_execution' request to the child site (handled there by the
	 * companion "MainWP Migrate DB Backup - Child" plugin's 'mainwp_child_extra_execution' hook).
	 *
	 * @return void
	 */
	public function ajax_migratedb_backup() {

		do_action( 'mainwp_secure_request', 'mainwp_development_migratedb_backup' );

		$website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0;

		if ( empty( $website_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing site ID.', 'mainwp-development-extension' ) ) );
		}

		global $mainWPDevelopmentExtensionActivator;

		$information = apply_filters(
			'mainwp_fetchurlauthed',
			$mainWPDevelopmentExtensionActivator->get_child_file(),
			$mainWPDevelopmentExtensionActivator->get_child_key(),
			$website_id,
			'extra_execution',
			array( 'mwp_dev_action' => 'migratedb_backup' )
		);

		if ( ! is_array( $information ) ) {
			wp_send_json_error( array( 'message' => __( 'No response from the child site. Is it connected and reachable?', 'mainwp-development-extension' ) ) );
		}

		if ( isset( $information['error'] ) ) {
			wp_send_json_error( array( 'message' => $information['error'] ) );
		}

		if ( empty( $information['success'] ) ) {
			wp_send_json_error(
				array(
					'message' => ! empty( $information['message'] )
						? $information['message']
						: __( 'The child site did not return a recognised response. Is the "MainWP Migrate DB Backup - Child" plugin installed and active on it?', 'mainwp-development-extension' ),
				)
			);
		}

		wp_send_json_success( $information );
	}
}
