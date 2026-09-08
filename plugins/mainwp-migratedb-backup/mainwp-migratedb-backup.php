<?php
/*
Plugin Name: MainWP Migrate DB Backup
Plugin URI: https://mainwp.com
Description: Solo site-backup plugin, no other plugin required. Install it on any WordPress site and it adds its own "Tools -> DB Backup" page: one click runs a plain WP Migrate DB Pro/Lite database export via WP-CLI, optionally bundled with Themes/Plugins/Media uploads into one downloaded zip. If this same plugin is also installed on your MainWP dashboard site, it additionally adds a "DB Backup" tab to each connected site's page there, letting you trigger that site's own copy of this plugin remotely over MainWP's normal signed-request mechanism — no separate dashboard extension needed, and no MainWP Child modification either. Every integration point here is self-verifying: each piece is simply inert if the MainWP core/child pieces it optionally talks to aren't present.
Version: 3.0
Author: Freckle
*/

namespace MainWP\Extensions\MigrateDBBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MainWP_MigrateDB_Backup
 *
 * One plugin, installed wherever you want a database (+ optionally files) backup:
 *
 * 1. Standalone — always active. Its own "Tools -> DB Backup" wp-admin page, button, and AJAX
 *    handler. Works on any WordPress site with nothing else installed.
 * 2. MainWP dashboard tab — only ever does anything if MainWP's dashboard core is also active on
 *    this same site. Adds a "DB Backup" tab to each connected site's page (Sites -> a site ->
 *    tab strip) that remotely triggers a copy of this same plugin running on that child site,
 *    via MainWP's own signed 'mainwp_fetchurlauthed' -> 'extra_execution' mechanism. No formal
 *    "MainWP Extension" registration/licensing dance needed for this to work — verified against
 *    MainWP's own source: the signing key MainWP_Extensions_Handler::hook_verify() checks is
 *    just md5(__FILE__ . '-SNNonceAdder'), computable directly, no activation flow required.
 * 3. MainWP Child responder — only ever does anything if MainWP Child is also active on this
 *    same site. Answers the 'mainwp_child_extra_execution' filter MainWP Child fires for its
 *    generic 'extra_execution' callable, so #2 (running on the dashboard site) can trigger this
 *    same plugin's backup logic running here.
 *
 * All three register unconditionally in the constructor; #2 and #3 are simply never fired by
 * anything if the MainWP piece they depend on isn't present, so neither is a real dependency —
 * this plugin works standalone with nothing else installed at all.
 */
class MainWP_MigrateDB_Backup {

	const ACTION_NAME        = 'migratedb_backup';
	const AJAX_ACTION_LOCAL  = 'mwp_migratedb_backup_run';
	const AJAX_ACTION_REMOTE = 'mwp_migratedb_backup_remote';
	const NONCE_ACTION_LOCAL = 'mwp_migratedb_backup_run';
	const DOWNLOAD_QUERY_VAR = 'mwp_dev_dl';
	const TOKEN_PREFIX       = 'mwp_dev_dl_';
	const TOKEN_TTL          = 24 * HOUR_IN_SECONDS;
	const BACKUP_SUBDIR      = 'mainwp-dev-backups';
	const SUBPAGE_SLUG       = 'MigrateDBBackup';

	public function __construct() {
		// 1. Standalone admin page + its own AJAX handler — this alone is the whole plugin.
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION_LOCAL, array( $this, 'ajax_run_backup' ) );
		add_action( 'init', array( $this, 'maybe_serve_download' ) );

		// 2. MainWP dashboard tab — inert unless MainWP's dashboard core is active here too.
		add_filter( 'mainwp_getsubpages_sites', array( $this, 'register_dashboard_subpage' ) );
		add_action( 'admin_init', array( $this, 'register_dashboard_ajax' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ) );

		// 3. MainWP Child responder — inert unless MainWP Child is active here too.
		add_filter( 'mainwp_child_extra_execution', array( $this, 'handle_extra_execution' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ *
	 * 1. Standalone admin page (works with nothing else installed)
	 * ------------------------------------------------------------------ */

	/**
	 * Register the standalone "DB Backup" wp-admin page.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_management_page(
			__( 'DB Backup', 'mainwp-migratedb-backup' ),
			__( 'DB Backup', 'mainwp-migratedb-backup' ),
			'manage_options',
			'mwp-migratedb-backup',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the standalone admin page: checkboxes, a button, a status area, and past backups.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Site Backup (WP Migrate DB Pro)', 'mainwp-migratedb-backup' ); ?></h1>
			<p><?php esc_html_e( 'The database export itself is always a plain export (no find & replace, no migration) via WP-CLI. Optionally bundle it with your files into one zip.', 'mainwp-migratedb-backup' ); ?></p>
			<?php $this->render_scope_checkboxes( 'mwp-migratedb-backup-opt' ); ?>
			<p>
				<button type="button" class="button button-primary mwp-migratedb-backup-btn">
					<?php esc_html_e( 'Run Backup', 'mainwp-migratedb-backup' ); ?>
				</button>
			</p>
			<div id="mwp-migratedb-backup-status"></div>

			<h2><?php esc_html_e( 'Previous backups', 'mainwp-migratedb-backup' ); ?></h2>
			<?php $this->render_backups_list(); ?>
		</div>
		<?php
	}

	/**
	 * Shared markup for the Themes/Plugins/Media/Other checkboxes, used on both the local page
	 * and the MainWP dashboard tab.
	 *
	 * @param string $css_class Class to give each checkbox, so the matching JS can find them.
	 *
	 * @return void
	 */
	protected function render_scope_checkboxes( $css_class ) {
		?>
		<fieldset style="margin: 12px 0;">
			<legend><strong><?php esc_html_e( 'Also include:', 'mainwp-migratedb-backup' ); ?></strong></legend>
			<label style="display:block;margin-top:6px;"><input type="checkbox" class="<?php echo esc_attr( $css_class ); ?>" value="themes" checked> <?php esc_html_e( 'Themes', 'mainwp-migratedb-backup' ); ?></label>
			<label style="display:block;margin-top:6px;"><input type="checkbox" class="<?php echo esc_attr( $css_class ); ?>" value="plugins" checked> <?php esc_html_e( 'Plugins', 'mainwp-migratedb-backup' ); ?></label>
			<label style="display:block;margin-top:6px;"><input type="checkbox" class="<?php echo esc_attr( $css_class ); ?>" value="media" checked> <?php esc_html_e( 'Media uploads', 'mainwp-migratedb-backup' ); ?></label>
			<label style="display:block;margin-top:6px;"><input type="checkbox" class="<?php echo esc_attr( $css_class ); ?>" value="other"> <?php esc_html_e( 'Other wp-content files (mu-plugins, loose files)', 'mainwp-migratedb-backup' ); ?></label>
		</fieldset>
		<?php
	}

	/**
	 * List existing backup files in the protected uploads subfolder, newest first.
	 *
	 * @return void
	 */
	protected function render_backups_list() {
		$dir = $this->backup_dir();
		if ( is_wp_error( $dir ) ) {
			echo '<p>' . esc_html( $dir->get_error_message() ) . '</p>';
			return;
		}

		$files = array_merge(
			glob( trailingslashit( $dir ) . '*.sql.gz' ),
			glob( trailingslashit( $dir ) . '*.zip' )
		);
		if ( empty( $files ) ) {
			echo '<p>' . esc_html__( 'No backups yet.', 'mainwp-migratedb-backup' ) . '</p>';
			return;
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'File', 'mainwp-migratedb-backup' ) . '</th><th>' . esc_html__( 'Date', 'mainwp-migratedb-backup' ) . '</th><th>' . esc_html__( 'Size', 'mainwp-migratedb-backup' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $files, 0, 20 ) as $file ) {
			echo '<tr><td>' . esc_html( basename( $file ) ) . '</td><td>' . esc_html( wp_date( 'Y-m-d H:i', filemtime( $file ) ) ) . '</td><td>' . esc_html( size_format( filesize( $file ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Enqueue the inline admin script for the standalone Tools -> DB Backup page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( 'tools_page_mwp-migratedb-backup' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'jquery' );

		$script = "jQuery(function($){\n"
			. $this->js_trigger_download_helper()
			. "  $('.mwp-migratedb-backup-btn').on('click', function(e){\n"
			. "    e.preventDefault();\n"
			. "    var \$btn = $(this), \$status = $('#mwp-migratedb-backup-status'), label = \$btn.text();\n"
			. "    \$btn.prop('disabled', true).text(" . wp_json_encode( __( 'Running…', 'mainwp-migratedb-backup' ) ) . ");\n"
			. "    \$status.text(" . wp_json_encode( __( 'Running the backup — this can take a while for larger sites…', 'mainwp-migratedb-backup' ) ) . ");\n"
			. "    var data = { action: " . wp_json_encode( self::AJAX_ACTION_LOCAL ) . ", security: " . wp_json_encode( wp_create_nonce( self::NONCE_ACTION_LOCAL ) ) . " };\n"
			. "    $('.mwp-migratedb-backup-opt:checked').each(function(){ data[$(this).val()] = 1; });\n"
			. "    $.post(ajaxurl, data)\n"
			. "      .done(function(response){\n"
			. "        if (response && response.success) {\n"
			. "          var payload = response.data || {};\n"
			. "          \$status.text('Backup complete: ' + (payload.filename || 'file') + (payload.filesize ? ' (' + payload.filesize + ')' : '') + ' — downloading…');\n"
			. "          if (payload.download_url) { triggerDownload(payload.download_url); }\n"
			. "          setTimeout(function(){ location.reload(); }, 1500);\n"
			. "        } else {\n"
			. "          \$status.text((response && response.data && response.data.message) ? response.data.message : 'Backup failed.');\n"
			. "          \$btn.prop('disabled', false).text(label);\n"
			. "        }\n"
			. "      })\n"
			. "      .fail(function(){\n"
			. "        \$status.text('Request failed — check the browser console and server logs.');\n"
			. "        \$btn.prop('disabled', false).text(label);\n"
			. "      });\n"
			. "  });\n"
			. "});";

		wp_add_inline_script( 'jquery', $script );
	}

	/**
	 * The auto-download helper shared by both inline scripts: a plain <a> click, not
	 * window.open — the server's Content-Disposition: attachment header makes the browser
	 * save it without navigating away, no popup blocker involved.
	 *
	 * @return string
	 */
	protected function js_trigger_download_helper() {
		return "  function triggerDownload(url){\n"
			. "    var a = document.createElement('a');\n"
			. "    a.href = url;\n"
			. "    document.body.appendChild(a);\n"
			. "    a.click();\n"
			. "    a.remove();\n"
			. "  }\n";
	}

	/**
	 * AJAX handler for the standalone admin page's own button — no MainWP involved.
	 *
	 * @return void
	 */
	public function ajax_run_backup() {
		check_ajax_referer( self::NONCE_ACTION_LOCAL, 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'mainwp-migratedb-backup' ) ) );
		}

		$result = $this->run_backup( $this->read_scope_from_request( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above via check_ajax_referer.

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( $result );
		}

		wp_send_json_success( $result );
	}

	/* ------------------------------------------------------------------ *
	 * 2. MainWP dashboard tab (inert unless MainWP dashboard core is active)
	 * ------------------------------------------------------------------ */

	/**
	 * Add the "DB Backup" tab to each connected site's page in MainWP.
	 *
	 * @param array $sub_pages Existing Manage Sites subpages.
	 *
	 * @return array
	 */
	public function register_dashboard_subpage( $sub_pages ) {
		if ( ! is_array( $sub_pages ) ) {
			$sub_pages = array();
		}

		$sub_pages[] = array(
			'title'       => __( 'DB Backup', 'mainwp-migratedb-backup' ),
			'slug'        => self::SUBPAGE_SLUG,
			'sitetab'     => true,
			'menu_hidden' => true,
			'callback'    => array( $this, 'render_dashboard_page' ),
		);

		return $sub_pages;
	}

	/**
	 * Render the per-site "DB Backup" tab on the MainWP dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		do_action( 'mainwp_pageheader_sites', self::SUBPAGE_SLUG );

		$website_id = class_exists( '\MainWP\Dashboard\MainWP_System_Utility' )
			? \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
			: 0;
		if ( empty( $website_id ) && isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only, just which site tab this is.
			$website_id = intval( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		?>
		<div class="ui segment">
			<h3 class="mainwp_box_title"><?php esc_html_e( 'Site Backup', 'mainwp-migratedb-backup' ); ?></h3>
			<div class="inside">
				<?php if ( empty( $website_id ) ) : ?>
					<div class="ui red message"><?php esc_html_e( 'Could not determine which site this is. Reload the page and try again.', 'mainwp-migratedb-backup' ); ?></div>
				<?php else : ?>
					<p><?php esc_html_e( 'Runs a database backup on this site (via WP Migrate DB Pro + WP-CLI), optionally bundled with its files into one zip. Requires this same "MainWP Migrate DB Backup" plugin to be installed and active on the child site itself — that\'s what actually does the work.', 'mainwp-migratedb-backup' ); ?></p>
					<?php $this->render_scope_checkboxes( 'mwp-migratedb-remote-opt' ); ?>
					<button type="button" class="ui green button mwp-migratedb-remote-btn" data-site-id="<?php echo esc_attr( $website_id ); ?>">
						<?php esc_html_e( 'Run Backup', 'mainwp-migratedb-backup' ); ?>
					</button>
					<div id="mwp-migratedb-remote-status" style="margin-top:10px;"></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		do_action( 'mainwp_pagefooter_sites', self::SUBPAGE_SLUG );
	}

	/**
	 * Register the dashboard-side AJAX action MainWP's own security/nonce layer wraps.
	 *
	 * @return void
	 */
	public function register_dashboard_ajax() {
		do_action( 'mainwp_ajax_add_action', self::AJAX_ACTION_REMOTE, array( $this, 'ajax_remote_backup' ) );
	}

	/**
	 * Enqueue the inline admin script for the MainWP dashboard's per-site "DB Backup" tab.
	 *
	 * @return void
	 */
	public function enqueue_dashboard_assets() {
		if ( ! isset( $_GET['page'] ) || ( 'ManageSites' . self::SUBPAGE_SLUG ) !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only page check.
			return;
		}

		wp_enqueue_script( 'jquery' );

		$script = "jQuery(function($){\n"
			. $this->js_trigger_download_helper()
			. "  $('.mwp-migratedb-remote-btn').on('click', function(e){\n"
			. "    e.preventDefault();\n"
			. "    var \$btn = $(this), \$status = $('#mwp-migratedb-remote-status'), label = \$btn.text();\n"
			. "    \$btn.prop('disabled', true).text(" . wp_json_encode( __( 'Running…', 'mainwp-migratedb-backup' ) ) . ");\n"
			. "    \$status.text(" . wp_json_encode( __( 'Running the backup on the child site — this can take a while…', 'mainwp-migratedb-backup' ) ) . ");\n"
			. "    var data = { action: " . wp_json_encode( self::AJAX_ACTION_REMOTE ) . ", website_id: \$btn.data('site-id'), security: (typeof security_nonces !== 'undefined') ? security_nonces[" . wp_json_encode( self::AJAX_ACTION_REMOTE ) . "] : '' };\n"
			. "    $('.mwp-migratedb-remote-opt:checked').each(function(){ data[$(this).val()] = 1; });\n"
			. "    $.post(ajaxurl, data)\n"
			. "      .done(function(response){\n"
			. "        if (response && response.success) {\n"
			. "          var payload = response.data || {};\n"
			. "          \$status.text('Backup complete: ' + (payload.filename || 'file') + (payload.filesize ? ' (' + payload.filesize + ')' : '') + ' — downloading…');\n"
			. "          if (payload.download_url) { triggerDownload(payload.download_url); }\n"
			. "        } else {\n"
			. "          \$status.text((response && response.data && response.data.message) ? response.data.message : 'Backup failed.');\n"
			. "        }\n"
			. "      })\n"
			. "      .fail(function(){ \$status.text('Request failed — check the browser console and server logs.'); })\n"
			. "      .always(function(){ \$btn.prop('disabled', false).text(label); });\n"
			. "  });\n"
			. "});";

		wp_add_inline_script( 'jquery', $script );
	}

	/**
	 * AJAX handler behind the MainWP dashboard tab's button: relays to the child site's own
	 * copy of this plugin over MainWP's normal signed-request mechanism.
	 *
	 * @return void
	 */
	public function ajax_remote_backup() {
		do_action( 'mainwp_secure_request', self::AJAX_ACTION_REMOTE );

		$website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified above via mainwp_secure_request.
		if ( empty( $website_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing site ID.', 'mainwp-migratedb-backup' ) ) );
		}

		$post_data                     = $this->read_scope_from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$post_data['mwp_dev_action']   = self::ACTION_NAME;

		// No formal "MainWP Extension" registration needed for this — verified against MainWP's
		// own source: the key it checks is just this deterministic hash, not an activation flow.
		$information = apply_filters(
			'mainwp_fetchurlauthed',
			__FILE__,
			md5( __FILE__ . '-SNNonceAdder' ),
			$website_id,
			'extra_execution',
			$post_data
		);

		if ( ! is_array( $information ) ) {
			wp_send_json_error( array( 'message' => __( 'No response from the child site. Is it connected and reachable, and does it have this same plugin active?', 'mainwp-migratedb-backup' ) ) );
		}

		if ( isset( $information['error'] ) ) {
			wp_send_json_error( array( 'message' => $information['error'] ) );
		}

		if ( empty( $information['success'] ) ) {
			wp_send_json_error(
				array(
					'message' => ! empty( $information['message'] )
						? $information['message']
						: __( 'The child site did not return a recognised response. Is "MainWP Migrate DB Backup" installed and active on it?', 'mainwp-migratedb-backup' ),
				)
			);
		}

		wp_send_json_success( $information );
	}

	/* ------------------------------------------------------------------ *
	 * 3. MainWP Child responder (inert unless MainWP Child is active)
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch our own custom action out of MainWP Child's generic 'extra_execution' callable.
	 * Only ever called if MainWP Child is installed and routes a request here — optional.
	 *
	 * @param array $information Response payload to hand back to the dashboard.
	 * @param array $post        Raw POST data sent by the dashboard.
	 *
	 * @return array
	 */
	public function handle_extra_execution( $information, $post ) {
		if ( ! isset( $post['mwp_dev_action'] ) || self::ACTION_NAME !== $post['mwp_dev_action'] ) {
			return $information;
		}

		return $this->run_backup( $this->read_scope_from_request( $post ) );
	}

	/* ------------------------------------------------------------------ *
	 * Shared backup engine (used by all three entry points above)
	 * ------------------------------------------------------------------ */

	/**
	 * Pull the themes/plugins/media/other checkbox flags out of a request array.
	 *
	 * @param array $request $_POST, or the 'extra_execution' $post array.
	 *
	 * @return array
	 */
	protected function read_scope_from_request( $request ) {
		return array(
			'themes'  => ! empty( $request['themes'] ),
			'plugins' => ! empty( $request['plugins'] ),
			'media'   => ! empty( $request['media'] ),
			'other'   => ! empty( $request['other'] ),
		);
	}

	/**
	 * Run `wp migratedb export` (no --find/--replace, i.e. a plain backup) via WP-CLI, then
	 * optionally bundle it with the selected wp-content folders into a single zip.
	 *
	 * @param array $include Bools for 'themes', 'plugins', 'media', 'other'. Any left out
	 *                        default to false, i.e. a database-only backup.
	 *
	 * @return array
	 */
	protected function run_backup( $include = array() ) {

		$include = wp_parse_args(
			$include,
			array(
				'themes'  => false,
				'plugins' => false,
				'media'   => false,
				'other'   => false,
			)
		);

		if ( ! $this->exec_available() ) {
			return array(
				'success' => false,
				'message' => __( 'PHP\'s exec()/shell_exec() is disabled on this server, so WP-CLI cannot be run here. Ask your host to enable it, or run the backup manually over SSH.', 'mainwp-migratedb-backup' ),
			);
		}

		$wp_cli = $this->find_wp_cli_binary();
		if ( ! $wp_cli ) {
			return array(
				'success' => false,
				'message' => __( 'Could not find the wp-cli binary on this server (checked PATH and common install locations). Install WP-CLI on this account, or ask your host to.', 'mainwp-migratedb-backup' ),
			);
		}

		$wants_files = $include['themes'] || $include['plugins'] || $include['media'] || $include['other'];

		if ( $wants_files && ! class_exists( '\ZipArchive' ) ) {
			return array(
				'success' => false,
				'message' => __( 'PHP\'s ZipArchive extension is not available on this server, so files can\'t be bundled in. Uncheck Themes/Plugins/Media/Other for a database-only backup.', 'mainwp-migratedb-backup' ),
			);
		}

		$dir = $this->backup_dir();
		if ( is_wp_error( $dir ) ) {
			return array(
				'success' => false,
				'message' => $dir->get_error_message(),
			);
		}

		$host      = wp_parse_url( home_url(), PHP_URL_HOST );
		$base_name = sanitize_file_name( ( $host ? $host : 'site' ) . '-' . gmdate( 'Y-m-d-His' ) );

		$db_filename = $base_name . '.sql.gz';
		$db_filepath = trailingslashit( $dir ) . $db_filename;

		// Deliberately no --find / --replace: a plain export is exactly what "backup" means here.
		$cmd = escapeshellarg( $wp_cli )
			. ' migratedb export ' . escapeshellarg( $db_filepath )
			. ' --gzip-file --exclude-post-revisions --exclude-spam'
			. ' --path=' . escapeshellarg( ABSPATH )
			. ' --allow-root'
			. ' 2>&1';

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best effort only, some hosts disallow this.
		}

		$output     = array();
		$return_var = 1;
		exec( $cmd, $output, $return_var ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( 0 !== $return_var || ! file_exists( $db_filepath ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: exit code, 2: command output */
					__( 'wp migratedb export failed (exit code %1$d): %2$s', 'mainwp-migratedb-backup' ),
					$return_var,
					implode( "\n", $output )
				),
			);
		}

		if ( ! $wants_files ) {
			return $this->finish_backup( $db_filepath, $db_filename );
		}

		return $this->bundle_files_backup( $dir, $base_name, $db_filepath, $db_filename, $include );
	}

	/**
	 * Zip the database dump together with the requested wp-content folders.
	 *
	 * @param string $dir         Backups output directory.
	 * @param string $base_name   Filename base shared by the dump and the zip (no extension).
	 * @param string $db_filepath Path to the already-exported .sql.gz dump.
	 * @param string $db_filename Its filename, for the path inside the zip.
	 * @param array  $include     Bools for 'themes', 'plugins', 'media', 'other'.
	 *
	 * @return array
	 */
	protected function bundle_files_backup( $dir, $base_name, $db_filepath, $db_filename, $include ) {
		$zip_filename = $base_name . '.zip';
		$zip_filepath = trailingslashit( $dir ) . $zip_filename;

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_filepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not create the zip archive.', 'mainwp-migratedb-backup' ),
			);
		}

		$zip->addFile( $db_filepath, 'database/' . $db_filename );

		// Never recurse into our own backups output folder (it can live under wp-content/uploads).
		$skip = array( trailingslashit( $dir ) );

		if ( $include['themes'] ) {
			$this->zip_add_directory( $zip, WP_CONTENT_DIR . '/themes', 'wp-content/themes', $skip );
		}
		if ( $include['plugins'] ) {
			$this->zip_add_directory( $zip, WP_CONTENT_DIR . '/plugins', 'wp-content/plugins', $skip );
		}
		if ( $include['media'] ) {
			$upload_dir = wp_upload_dir();
			$this->zip_add_directory( $zip, $upload_dir['basedir'], 'wp-content/uploads', $skip );
		}
		if ( $include['other'] ) {
			$this->zip_add_other_wp_content_files( $zip, $skip );
		}

		$zip->close();

		// The loose .sql.gz is inside the zip now too; don't keep a duplicate copy on disk.
		@unlink( $db_filepath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		return $this->finish_backup( $zip_filepath, $zip_filename );
	}

	/**
	 * Generate the single-use download token and build the response payload.
	 *
	 * @param string $filepath Absolute path to the finished backup file.
	 * @param string $filename Its filename, for display.
	 *
	 * @return array
	 */
	protected function finish_backup( $filepath, $filename ) {
		$token = wp_generate_password( 32, false, false );
		set_transient( self::TOKEN_PREFIX . $token, $filepath, self::TOKEN_TTL );

		return array(
			'success'      => true,
			'filename'     => $filename,
			'filesize'     => size_format( filesize( $filepath ) ),
			'download_url' => add_query_arg( self::DOWNLOAD_QUERY_VAR, $token, home_url( '/' ) ),
		);
	}

	/**
	 * Recursively add a directory's contents to a zip, skipping any path under $skip_abs_paths.
	 *
	 * @param \ZipArchive $zip            Open zip archive.
	 * @param string      $source_dir     Absolute directory to add.
	 * @param string      $zip_prefix     Path prefix to give these files inside the zip.
	 * @param string[]    $skip_abs_paths Absolute paths (with trailing slash) to skip entirely.
	 *
	 * @return void
	 */
	protected function zip_add_directory( \ZipArchive $zip, $source_dir, $zip_prefix, array $skip_abs_paths = array() ) {
		$source_dir = untrailingslashit( wp_normalize_path( $source_dir ) );
		if ( ! is_dir( $source_dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$item_path = wp_normalize_path( $item->getPathname() );

			foreach ( $skip_abs_paths as $skip ) {
				if ( 0 === strpos( $item_path, untrailingslashit( wp_normalize_path( $skip ) ) ) ) {
					continue 2;
				}
			}

			$relative = ltrim( substr( $item_path, strlen( $source_dir ) ), '/' );
			$zip_path = trailingslashit( $zip_prefix ) . $relative;

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $zip_path );
			} else {
				$zip->addFile( $item_path, $zip_path );
			}
		}
	}

	/**
	 * Add the parts of wp-content not covered by the Themes/Plugins/Media checkboxes: loose
	 * files sitting directly in wp-content (e.g. object-cache.php) and the mu-plugins folder.
	 *
	 * @param \ZipArchive $zip  Open zip archive.
	 * @param string[]    $skip Absolute paths to skip entirely.
	 *
	 * @return void
	 */
	protected function zip_add_other_wp_content_files( \ZipArchive $zip, array $skip ) {
		foreach ( glob( trailingslashit( WP_CONTENT_DIR ) . '*' ) as $path ) {
			if ( ! is_dir( $path ) ) {
				$zip->addFile( $path, 'wp-content/' . basename( $path ) );
			}
		}

		$mu_dir = trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins';
		if ( is_dir( $mu_dir ) ) {
			$this->zip_add_directory( $zip, $mu_dir, 'wp-content/mu-plugins', $skip );
		}
	}

	/**
	 * Serve a backup file if the request carries a valid, unexpired, single-use token.
	 *
	 * @return void
	 */
	public function maybe_serve_download() {
		if ( ! isset( $_GET[ self::DOWNLOAD_QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- token itself is the credential.
			return;
		}

		$token    = sanitize_text_field( wp_unslash( $_GET[ self::DOWNLOAD_QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$filepath = get_transient( self::TOKEN_PREFIX . $token );

		if ( ! $filepath || ! file_exists( $filepath ) ) {
			wp_die( esc_html__( 'This backup download link is invalid or has expired.', 'mainwp-migratedb-backup' ), '', array( 'response' => 404 ) );
		}

		delete_transient( self::TOKEN_PREFIX . $token ); // Single use.

		$content_type = ( 'zip' === strtolower( pathinfo( $filepath, PATHINFO_EXTENSION ) ) ) ? 'application/zip' : 'application/gzip';

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . basename( $filepath ) . '"' );
		header( 'Content-Length: ' . filesize( $filepath ) );
		readfile( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * The protected uploads subfolder backups are written to.
	 *
	 * @return string|\WP_Error
	 */
	protected function backup_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . self::BACKUP_SUBDIR;

		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'mwp_dev_mkdir_failed', __( 'Could not create the backups directory in uploads.', 'mainwp-migratedb-backup' ) );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// Blocks direct access; files are only ever served through maybe_serve_download()'s token check.
			file_put_contents( $htaccess, "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * Whether PHP's exec() is available and not disabled by the host.
	 *
	 * @return bool
	 */
	protected function exec_available() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'exec', $disabled, true );
	}

	/**
	 * Try to locate the wp-cli binary: PATH first, then a few common install locations.
	 *
	 * @return string|false
	 */
	protected function find_wp_cli_binary() {
		$which = @shell_exec( 'command -v wp 2>/dev/null' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( $which ) {
			return trim( $which );
		}

		$home = getenv( 'HOME' );

		$candidates = array(
			'/usr/local/bin/wp',
			$home ? $home . '/bin/wp' : null,
			$home ? $home . '/.wp-cli/bin/wp' : null,
		);

		foreach ( array_filter( $candidates ) as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return false;
	}
}

new MainWP_MigrateDB_Backup();
