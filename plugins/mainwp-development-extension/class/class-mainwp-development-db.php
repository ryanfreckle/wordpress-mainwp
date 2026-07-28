<?php
/**
 * MainWP Development DB
 *
 * This class handles the DB process.
 *
 * @package MainWP/Extensions
 */

 namespace MainWP\Extensions\Development;

 /**
  * Class MainWP_Development_DB
  *
  * @package MainWP/Extensions
  */
class MainWP_Development_DB {

	/**
	 * @var self|null The singleton instance of the class.
	 */
	private static $instance = null;

	/**
	 * @var \wpdb $wpdb WordPress database object.
	 */
	private $wpdb;

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
	 * MainWP_Development_DB constructor.
	 *
	 * @return void
	 */
	public function __construct() {

	}

	/**
	 * Install Extension.
	 *
	 * @return void
	 */
	public function install() {

	}
}
