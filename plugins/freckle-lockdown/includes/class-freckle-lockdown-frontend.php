<?php
/**
 * Front-end lockdown: when enabled, every public request gets a plain 404,
 * regardless of login state. wp-admin, wp-login.php, admin-ajax.php, REST
 * and cron are untouched — this only hooks 'template_redirect', which never
 * fires for those.
 */

namespace Freckle\Lockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend_Lockdown {

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_block_frontend' ), 1 );
	}

	public function maybe_block_frontend() {
		$options = Settings::get_instance()->get_options();

		if ( empty( $options['frontend_lockdown_enabled'] ) ) {
			return;
		}

		// Deliberately no is_user_logged_in()/current_user_can() bypass —
		// the front end is locked for everyone, admins included.
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		include get_query_template( '404' );
		exit;
	}
}
