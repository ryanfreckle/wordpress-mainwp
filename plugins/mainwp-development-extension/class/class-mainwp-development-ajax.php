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
        /**
		 * Example MainWP AJAX actions.
		 */
		do_action( 'mainwp_ajax_add_action', 'mainwp_development_do_something', array( &$this, 'ajax_do_something' ) );
	}

    /**
     * Ajax reload data.
     *
     * @return void
     */
	public function ajax_do_something() {

		do_action( 'mainwp_secure_request', 'mainwp_development_do_something' );
		// Do your PHP Work here then return the results via wp_send_json.
	}
}
