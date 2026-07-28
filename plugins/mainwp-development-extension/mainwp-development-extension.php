<?php
/*
  Plugin Name: MainWP Development Extension
  Plugin URI: https://mainwp.com
  Description: MainWP Development Extension simplifies your Development by providing you with a place to start when developing your next MainWP Extension.
  Version: 4.0
  Author: MainWP
  Author URI: https://mainwp.com
  Documentation URI: https://kb.mainwp.com/docs/development-extension/
 */


namespace MainWP\Extensions\Development;

if ( ! defined( 'MAINWP_DEVELOPMENT_PLUGIN_FILE' ) ) {
	define( 'MAINWP_DEVELOPMENT_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'MAINWP_DEVELOPMENT_PLUGIN_DIR' ) ) {
	define( 'MAINWP_DEVELOPMENT_PLUGIN_DIR', plugin_dir_path( MAINWP_DEVELOPMENT_PLUGIN_FILE ) );
}

if ( ! defined( 'MAINWP_DEVELOPMENT_PLUGIN_URL' ) ) {
	define( 'MAINWP_DEVELOPMENT_PLUGIN_URL', plugin_dir_url( MAINWP_DEVELOPMENT_PLUGIN_FILE ) );
}

if ( ! defined( 'MAINWP_DEVELOPMENT_LOG_PRIORITY' ) ) {
	define( 'MAINWP_DEVELOPMENT_LOG_PRIORITY', 2024011 );
}

class MainWP_Development_Extension_Activator {

	protected $mainwpMainActivated = false;
	protected $childEnabled        = false;
	protected $childKey            = false;
	protected $childFile;
	protected $plugin_handle    = 'mainwp-development-extension';
	protected $product_id       = 'MainWP Development Extension';
	protected $software_version = '4.0';

	public function __construct() {
		$this->childFile = __FILE__;

		// Register given function as __autoload() implementation
		spl_autoload_register( array( $this, 'autoload' ) );

		// Register activation and deactivation hooks.
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		/**
		 * This is a filter similar to adding the management page in WordPress.
		 * It calls the function get_this_extension, which adds the extension to the $extensions array.
		 * This array is a list of all of the extensions MainWP uses, and the functions that
		 * it has to call to show settings for them. In this case, the function is settings.
		 */
		add_filter( 'mainwp_getextensions', array( &$this, 'get_this_extension' ) );
		add_filter( 'mainwp_log_specific_actions', array( $this, 'hook_log_specific' ), 10, 2 );

		/**
		 * This variable checks to see if MainWP is activated. By default it will return false & return admin notices to the user
		 * that MainWP Dashboard needs to be activated. If MainWP is activated, then call the function activate_this_plugin.
		 */
		$this->mainwpMainActivated = apply_filters( 'mainwp_activated_check', false );
		if ( $this->mainwpMainActivated !== false ) {
			$this->activate_this_plugin();
		} else {
			add_action( 'mainwp_activated', array( &$this, 'activate_this_plugin' ) );
		}

		add_action( 'admin_notices', array( &$this, 'admin_notices' ) );
	}

	/**
	 * This function will go through the /class folder and require all of the files.
	 * The class name is passed in by spl_autoload_register.
	 * It is the name of the class that is being instantiated.
	 * For example, if you do new MainWP_Development_Overview, then
	 * $class_name will be MainWP_Development_Overview.
	 *
	 * The class name is also used to determine the file name.
	 * For example, MainWP_Development_Overview is in the file
	 * class/class-mainwp-development-overview.php.
	 *
	 * @param string $class_name The name of the class to load.
	 */
	public function autoload( $class_name ) {

		if ( 0 === strpos( $class_name, 'MainWP\Extensions\Development' ) ) {
			// trim the namespace prefix: MainWP\Extensions\Development\.
			$class_name = str_replace( 'MainWP\Extensions\Development\\', '', $class_name );
		} else {
			return;
		}

		if ( 0 !== strpos( $class_name, 'MainWP_Development' ) ) {
			return;
		}
		$class_name = str_replace( '_', '-', strtolower( $class_name ) );
		$class_file = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . str_replace( basename( __FILE__ ), '', plugin_basename( __FILE__ ) ) . 'class' . DIRECTORY_SEPARATOR . 'class-' . $class_name . '.php';
		if ( file_exists( $class_file ) ) {
			require_once $class_file;
		}
	}

	/**
	 * It calls the function get_this_extension, which adds the extension to the $extensions array.
	 * This array is a list of all of the extensions MainWP uses, and the functions that
	 * it has to call to show settings for them. In this case, the function is settings.
	 */
	public function get_this_extension( $pArray ) {

		$pArray[] = array(
			'plugin'     => __FILE__,
			'api'        => $this->plugin_handle,
			'mainwp'     => true,
			'callback'   => array( &$this, 'settings' ),
			'apiManager' => true,
		);

		return $pArray;
	}

	/**
	 * Main extension page.
	 *
	 * @return void
	 */
	public function settings() {
		do_action( 'mainwp_pageheader_extensions', __FILE__ );
		MainWP_Development_Overview::get_instance()->render_tabs();
		do_action( 'mainwp_pagefooter_extensions', __FILE__ );
	}

	/**
	 * This function is called when the plugin is activated. It checks to see if MainWP is activated. If it is, then it calls the functions
	 * hook_managesites_subpage, hook_get_metaboxes, widgets_screen_options & initiates the main Admin Class that controls the rest of the extension's behavior.
	 */
	public function activate_this_plugin() {

		$this->mainwpMainActivated = apply_filters( 'mainwp_activated_check', $this->mainwpMainActivated );
		$this->childEnabled        = apply_filters( 'mainwp_extension_enabled_check', __FILE__ );
		$this->childKey            = $this->childEnabled['key'];

		if ( function_exists( 'mainwp_current_user_can' ) && ! mainwp_current_user_can( 'extension', 'mainwp-development-extension' ) ) {
			return;
		}

		add_filter( 'mainwp_getsubpages_sites', array( &$this, 'hook_managesites_subpage' ), 10, 1 );
		add_filter( 'mainwp_getmetaboxes', array( &$this, 'hook_get_metaboxes' ) );
		add_filter( 'mainwp_widgets_screen_options', array( MainWP_Development_Admin::get_instance(), 'widgets_screen_options' ), 10, 1 );

		MainWP_Development_Admin::get_instance();
	}

	/**
	 * Hook hook_log_specific.
	 *
	 * @return mixed $inputs.
	 */
	public function hook_log_specific( $inputs ) {
		$inputs[ MAINWP_DEVELOPMENT_LOG_PRIORITY ] = __( 'Development logs', 'mainwp-pro-reports-extension' );
		return $inputs;
	}
	public function get_child_key() {
		return $this->childKey;
	}

	public function get_child_file() {
		return $this->childFile;
	}

	/**
	 * Displays an admin notice if MainWP is not activated.
	 *
	 * @return void
	 */
	public function admin_notices() {
		global $current_screen;
		if ( $current_screen->parent_base == 'plugins' && $this->mainwpMainActivated == false ) {
			echo '<div class="error"><p>' . sprintf( esc_html__( 'MainWP Development Extension requires %1$sMainWP Dashboard Plugin%2$s to be activated in order to work. Please install and activate %3$sMainWP Dashboard Plugin%4$s first.' ), '<a href="http://mainwp.com/" target="_blank">', '</a>', '<a href="http://mainwp.com/" target="_blank">', '</a>' ) . '</p></div>';
		}
	}

	/**
	 * Activates the extension in WordPress.
	 * @return void
	 */
	public function activate() {
		$options = array(
			'product_id'       => $this->product_id,
			'software_version' => $this->software_version,
		);
		do_action( 'mainwp_activate_extention', $this->plugin_handle, $options );
	}

	/**
	 * Deactivates the extension in WordPress.
	 *
	 * @return void
	 */
	public function deactivate() {
		do_action( 'mainwp_deactivate_extention', $this->plugin_handle );
	}

	/**
	 * Adds metabox (widget) on the MainWP Dashboard overview page via the 'mainwp_getmetaboxes' filter.
	 *
	 * @param array $metaboxes Array containing metaboxes data.
	 *
	 * @return array $metaboxes Updated array that contains metaboxes data.
	 */
	public function hook_get_metaboxes( $metaboxes ) {
		if ( ! $this->childEnabled ) {
			return $metaboxes;
		}

		if ( ! is_array( $metaboxes ) ) {
			$metaboxes = array();
		}

		$metaboxes[] = array(
			'id'            => 'development-widget',
			'plugin'        => $this->childFile,
			'key'           => $this->childKey,
			'metabox_title' => __( 'Development', 'mainwp-development-extension' ),
			'callback'      => array( MainWP_Development_Widget::get_instance(), 'render_metabox' ),
		);


		return $metaboxes;
	}


	/**
	 * Method hook_managesites_subpage().
	 *
	 * @param array $subPage Input sub pages.
	 *
	 * @return array $subPage Output sub pages.
	 */
	public function hook_managesites_subpage( $subPage ) {
		$subPage[] = array(
			'title'            => __( 'Development Individual', 'mainwp-development-extension' ),
			'slug'             => 'DevelopmentIndividual',
			'sitetab'          => true,
			'menu_hidden'      => true,
			'callback'         => array( MainWP_Development_Individual::get_instance(), 'render_individual_page' ),
		);
		return $subPage;
	}


}

global $mainWPDevelopmentExtensionActivator;
$mainWPDevelopmentExtensionActivator = new MainWP_Development_Extension_Activator();
