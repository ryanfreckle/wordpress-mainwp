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
		?>
        <div class="ui message">
            <div class="ui placeholder segment">
                <div class="ui icon header">
                    <i class="pdf file outline icon"></i>
                    No documents are found.
                </div>
            </div>
        </div>
		<?php
		do_action( 'mainwp_pagefooter_sites', 'DevelopmentIndividual' );
	}
}