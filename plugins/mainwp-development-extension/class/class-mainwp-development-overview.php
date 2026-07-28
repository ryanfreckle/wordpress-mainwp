<?php
/**
 * MainWP Development Overview
 *
 * This class handles the Overview process.
 *
 * @package MainWP/Extensions
 */

 namespace MainWP\Extensions\Development;

 /**
  * Class MainWP_Development_Overview
  *
  * @package MainWP/Extensions
  */
class MainWP_Development_Overview {

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
	 * MainWP_Development_Overview constructor.
     *
     * @return void
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

		// $this->handle_settings_post();

	}

	/**
	 * Handle settings post.
	 *
	 * @return void
	 */
	public function handle_settings_post() {
		// Code to handle Form posts.
	}

	/**
	 * Render extension page tabs.
     *
     * @return void
     */
	public static function render_tabs() {

		$current_tab = 'dashboard';

		if ( isset( $_GET['tab'] ) ) {
			if ( $_GET['tab'] == 'dashboard' ) {
				$current_tab = 'dashboard';
			} elseif ( $_GET['tab'] == 'settings' ) {
				$current_tab = 'settings';
			}
		}

		?>

		<div class="ui labeled icon inverted menu mainwp-sub-submenu" id="mainwp-pro-development-menu">
			<a href="admin.php?page=Extensions-Mainwp-Development-Extension&tab=dashboard" class="item <?php echo ( $current_tab == 'dashboard' ) ? 'active' : ''; ?>"><i class="tasks icon"></i> <?php esc_html_e( 'Dashboard', 'mainwp-development-extension' ); ?></a>
			<a href="admin.php?page=Extensions-Mainwp-Development-Extension&tab=settings" class="item <?php echo ( $current_tab == 'settings' || $current_tab == '' ) ? 'active' : ''; ?>"><i class="file alternate outline icon"></i> <?php esc_html_e( 'Settings', 'mainwp-development-extension' ); ?></a>
		</div>
		<?php

		if ( $current_tab == 'settings' ) {
			?>
            <div>Settings Page Placeholder</div>
			<?php
		} else {
			?>
            <div>Dashboard Page Placeholder</div>
			<?php
		}
	}
}
