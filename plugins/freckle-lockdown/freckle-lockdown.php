<?php
/*
  Plugin Name: Freckle Lockdown
  Plugin URI: https://freckleonline.com
  Description: Locks down the public front end (returns 404 to everyone, including admins) and renames the wp-admin login URL to a custom slug. Both are configurable from Settings > Freckle Lockdown.
  Version: 1.0
  Author: Freckle
  Author URI: https://freckleonline.com
 */

namespace Freckle\Lockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FRECKLE_LOCKDOWN_PLUGIN_FILE' ) ) {
	define( 'FRECKLE_LOCKDOWN_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'FRECKLE_LOCKDOWN_PLUGIN_DIR' ) ) {
	define( 'FRECKLE_LOCKDOWN_PLUGIN_DIR', plugin_dir_path( FRECKLE_LOCKDOWN_PLUGIN_FILE ) );
}

if ( ! defined( 'FRECKLE_LOCKDOWN_PLUGIN_URL' ) ) {
	define( 'FRECKLE_LOCKDOWN_PLUGIN_URL', plugin_dir_url( FRECKLE_LOCKDOWN_PLUGIN_FILE ) );
}

/**
 * Escape hatch: if the login rename ever locks you out (wrong slug, cache
 * issue, etc.), add this to wp-config.php and reload — it disables both
 * features immediately without needing FTP/SFTP access to deactivate:
 *
 *     define( 'FRECKLE_LOCKDOWN_DISABLE', true );
 */
if ( defined( 'FRECKLE_LOCKDOWN_DISABLE' ) && FRECKLE_LOCKDOWN_DISABLE ) {
	return;
}

require_once FRECKLE_LOCKDOWN_PLUGIN_DIR . 'includes/class-freckle-lockdown-settings.php';
require_once FRECKLE_LOCKDOWN_PLUGIN_DIR . 'includes/class-freckle-lockdown-frontend.php';
require_once FRECKLE_LOCKDOWN_PLUGIN_DIR . 'includes/class-freckle-lockdown-login-rename.php';

/**
 * Boots the plugin's pieces. Kept deliberately simple (no MainWP dependency,
 * no framework) — this is a plain standalone WordPress plugin.
 */
final class Plugin {

	public function __construct() {
		Settings::get_instance();
		new Frontend_Lockdown();
		new Login_Rename();
	}
}

new Plugin();
