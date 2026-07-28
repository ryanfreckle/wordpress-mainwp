<?php
/**
 * MainWP Development Admin
 *
 * This class handles the extension process.
 *
 * @package MainWP/Extensions
 */

 namespace MainWP\Extensions\Development;

 /**
  * Class MainWP_Development_Admin
  *
  * @package MainWP/Extensions
  */
class MainWP_Development_Admin {

	public static $instance = null;
	public $version         = '1.6';

	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * MainWP_Development_Admin constructor.
	 *
	 * @return void
	 */
	public function __construct() {

		add_action( 'init', array( &$this, 'localization' ) );
		add_filter( 'plugin_row_meta', array( &$this, 'plugin_row_meta' ), 10, 2 );
		add_action( 'mainwp_delete_site', array( &$this, 'hook_delete_site' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_scripts' ) );

		MainWP_Development_DB::get_instance()->install();
		MainWP_Development_Ajax::get_instance();
		MainWP_Development_Overview::get_instance();
		MainWP_Development_Individual::get_instance();
	}

	/**
	 * Register the /languages folder. This will allow us to translate the extension.
	 *
	 * @return void
	 */
	public function localization() {
		load_plugin_textdomain( 'mainwp-development-extension', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Displays the meta data winthin the plugin row on the WP > Plugins > Installed Plugins page.
	 *
	 * @param $plugin_meta
	 * @param $plugin_file
	 *
	 * @return mixed Array of plugin meta data.
	 */
	public function plugin_row_meta( $plugin_meta, $plugin_file ) {
		if ( 'mainwp-development-extension/mainwp-development-extension.php' != $plugin_file ) {
			return $plugin_meta;
		}
		$slug     = basename( $plugin_file, '.php' );
		$api_data = get_option( $slug . '_APIManAdder' );
		if ( ! is_array( $api_data ) || ! isset( $api_data['activated_key'] ) || $api_data['activated_key'] != 'Activated' || ! isset( $api_data['api_key'] ) || empty( $api_data['api_key'] ) ) {
			return $plugin_meta;
		}
		$plugin_meta[] = '<a href="?do=checkUpgrade" title="Check for updates.">Check for Update</a>';
		return $plugin_meta;
	}

	/**
	 * This method is responsible for loading all JS & CSS for the extension.
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts() {
		if ( isset( $_GET['page'] ) && ( 'Extensions-Mainwp-Development-Extension' === $_GET['page'] || 'ManageSitesDevelopmentIndividual' === $_GET['page'] || ( 'managesites' === $_GET['page'] && isset( $_GET['dashboard'] ) ) ) ) {
			wp_enqueue_style( 'mainwp-development-extension', MAINWP_DEVELOPMENT_PLUGIN_URL . 'css/mainwp-development-extension.css', array(), $this->version );
			wp_enqueue_script( 'mainwp-development-extension', MAINWP_DEVELOPMENT_PLUGIN_URL . 'js/mainwp-development-extension.js', array(), $this->version, true );
		}
	}

	/**
	 * Widgets screen options.
	 *
	 * @param array $input Input.
	 *
	 * @return array $input Input.
	 */
	public function widgets_screen_options( $input ) {
		$input['advanced-development-widget'] = __( 'Development Widget', 'mainwp-development-extension' );
		return $input;
	}
}
