<?php
/**
 * MainWP Development Individual
 *
 * This class handles the Individual process.
 *
 * @package MainWP/Extensions
 */

 namespace MainWP\Extensions\Development;

 /**
  * Class MainWP_Development_Individual
  *
  * @package MainWP/Extensions
  */
class MainWP_Development_Individual
{
	/**
	 * @var self|null The singleton instance of the class.
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self|null
	 */
	public static function get_instance()
	{
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * MainWP_Development_Individual constructor.
     *
     * @return void
	 */
	public function __construct()
	{
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
	}

	/**
	 * Admin init.
	 *
	 * @return void
	 */
	public function admin_init()
	{

	}

	/**
	 * Render individual page.
     *
     * @return void
	 */
	public function render_individual_page()
	{
		do_action( 'mainwp_pageheader_sites', 'DevelopmentIndividual' );

		// Set by MainWP_Manage_Sites::on_load_subpages() from $_GET['id'] before this callback runs.
		$website_id = \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid();
		if ( empty( $website_id ) && isset( $_GET['id'] ) ) {
			$website_id = intval( $_GET['id'] );
		}
		?>
		<div class="ui segment">
			<h3 class="mainwp_box_title"><?php esc_html_e( 'Database Backup (WP Migrate DB Pro)', 'mainwp-development-extension' ); ?></h3>
			<div class="inside">
				<?php if ( empty( $website_id ) ) : ?>
					<div class="ui red message"><?php esc_html_e( 'Could not determine which site this is. Reload the page and try again.', 'mainwp-development-extension' ); ?></div>
				<?php else : ?>
					<p><?php esc_html_e( 'Runs a plain database export (no find & replace, no migration) on this site via WP Migrate DB Pro. Requires the companion "MainWP Migrate DB Backup - Child" plugin and WP Migrate DB Pro to be active on this site, and WP-CLI to be available on its server.', 'mainwp-development-extension' ); ?></p>
					<button type="button" class="ui green button mwp-dev-backup-btn" data-site-id="<?php echo esc_attr( $website_id ); ?>">
						<?php esc_html_e( 'Run Database Backup', 'mainwp-development-extension' ); ?>
					</button>
					<div id="mwp-dev-backup-status" class="mwp-dev-backup-status"></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		do_action( 'mainwp_pagefooter_sites', 'DevelopmentIndividual' );
	}
}