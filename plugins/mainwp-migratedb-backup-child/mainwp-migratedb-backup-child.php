<?php
/*
Plugin Name: MainWP Migrate DB Backup - Child
Plugin URI: https://mainwp.com
Description: Self-contained WP Migrate DB Pro database backup button. Adds its own "DB Backup" page to this site's wp-admin with a one-click backup + download — works standalone, no other plugin required. If the MainWP Child plugin and the "MainWP Development Extension" dashboard plugin also happen to be present, it can additionally be triggered remotely from the MainWP dashboard, but that's optional. Requires WP Migrate DB Pro (or WP Migrate Lite) and WP-CLI on the server.
Version: 2.0
Author: Freckle
*/

namespace MainWP\Extensions\MigrateDBBackup\Child;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MainWP_MigrateDB_Backup_Child
 *
 * Standalone: registers its own wp-admin page, button, and AJAX handler, so this plugin
 * runs a WP Migrate DB Pro backup on its own, on any site, with nothing else installed.
 *
 * Optionally also answers 'extra_execution' requests from the MainWP Development Extension
 * dashboard plugin (via the 'mainwp_child_extra_execution' filter MainWP Child fires on every
 * request routed through its 'extra_execution' callable) if that happens to be present — but
 * nothing here requires it; that hook is simply never fired when MainWP Child isn't installed.
 */
class MainWP_MigrateDB_Backup_Child {

	const ACTION_NAME       = 'migratedb_backup';
	const AJAX_ACTION       = 'mwp_migratedb_backup_run';
	const NONCE_ACTION      = 'mwp_migratedb_backup_run';
	const DOWNLOAD_QUERY_VAR = 'mwp_dev_dl';
	const TOKEN_PREFIX      = 'mwp_dev_dl_';
	const TOKEN_TTL         = 24 * HOUR_IN_SECONDS;
	const BACKUP_SUBDIR     = 'mainwp-dev-backups';

	public function __construct() {
		// Standalone admin page + its own AJAX handler — this is the whole plugin on its own.
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_run_backup' ) );
		add_action( 'init', array( $this, 'maybe_serve_download' ) );

		// Optional bonus: only ever fires if the MainWP Child plugin is also installed.
		add_filter( 'mainwp_child_extra_execution', array( $this, 'handle_extra_execution' ), 10, 2 );
	}

	/**
	 * Register the standalone "DB Backup" wp-admin page.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_management_page(
			__( 'DB Backup', 'mainwp-migratedb-backup-child' ),
			__( 'DB Backup', 'mainwp-migratedb-backup-child' ),
			'manage_options',
			'mwp-migratedb-backup',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the standalone admin page: a button, a status area, and a list of past backups.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Database Backup (WP Migrate DB Pro)', 'mainwp-migratedb-backup-child' ); ?></h1>
			<p><?php esc_html_e( 'Runs a plain database export (no find & replace, no migration) via WP Migrate DB Pro and WP-CLI.', 'mainwp-migratedb-backup-child' ); ?></p>
			<p>
				<button type="button" class="button button-primary mwp-migratedb-backup-btn">
					<?php esc_html_e( 'Run Database Backup', 'mainwp-migratedb-backup-child' ); ?>
				</button>
			</p>
			<div id="mwp-migratedb-backup-status"></div>

			<h2><?php esc_html_e( 'Previous backups', 'mainwp-migratedb-backup-child' ); ?></h2>
			<?php $this->render_backups_list(); ?>
		</div>
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

		$files = glob( trailingslashit( $dir ) . '*.sql.gz' );
		if ( empty( $files ) ) {
			echo '<p>' . esc_html__( 'No backups yet.', 'mainwp-migratedb-backup-child' ) . '</p>';
			return;
		}

		usort( $files, static function ( $a, $b ) {
			return filemtime( $b ) <=> filemtime( $a );
		} );

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'File', 'mainwp-migratedb-backup-child' ) . '</th><th>' . esc_html__( 'Date', 'mainwp-migratedb-backup-child' ) . '</th><th>' . esc_html__( 'Size', 'mainwp-migratedb-backup-child' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $files, 0, 20 ) as $file ) {
			echo '<tr><td>' . esc_html( basename( $file ) ) . '</td><td>' . esc_html( wp_date( 'Y-m-d H:i', filemtime( $file ) ) ) . '</td><td>' . esc_html( size_format( filesize( $file ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Enqueue the small inline admin script that wires the button to our own AJAX action.
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
			. "  $('.mwp-migratedb-backup-btn').on('click', function(e){\n"
			. "    e.preventDefault();\n"
			. "    var \$btn = $(this), \$status = $('#mwp-migratedb-backup-status'), label = \$btn.text();\n"
			. "    \$btn.prop('disabled', true).text(" . wp_json_encode( __( 'Running…', 'mainwp-migratedb-backup-child' ) ) . ");\n"
			. "    \$status.text(" . wp_json_encode( __( 'Running WP Migrate DB Pro export — this can take a while for larger databases…', 'mainwp-migratedb-backup-child' ) ) . ");\n"
			. "    $.post(ajaxurl, { action: " . wp_json_encode( self::AJAX_ACTION ) . ", security: " . wp_json_encode( wp_create_nonce( self::NONCE_ACTION ) ) . " })\n"
			. "      .done(function(response){\n"
			. "        if (response && response.success) {\n"
			. "          var data = response.data || {};\n"
			. "          var html = 'Backup complete: ' + (data.filename || 'file') + (data.filesize ? ' (' + data.filesize + ')' : '');\n"
			. "          if (data.download_url) { html += ' — <a href=\"' + data.download_url + '\" target=\"_blank\" rel=\"noopener\">Download</a>'; }\n"
			. "          \$status.html(html);\n"
			. "          location.reload();\n"
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
	 * AJAX handler for the standalone admin page's own button — no MainWP involved.
	 *
	 * @return void
	 */
	public function ajax_run_backup() {
		check_ajax_referer( self::NONCE_ACTION, 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'mainwp-migratedb-backup-child' ) ) );
		}

		$result = $this->run_backup();

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( $result );
		}

		wp_send_json_success( $result );
	}

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

		return $this->run_backup();
	}

	/**
	 * Run `wp migratedb export` (no --find/--replace, i.e. a plain backup) via WP-CLI.
	 *
	 * @return array
	 */
	protected function run_backup() {

		if ( ! $this->exec_available() ) {
			return array(
				'success' => false,
				'message' => __( 'PHP\'s exec()/shell_exec() is disabled on this server, so WP-CLI cannot be run here. Ask your host to enable it, or run the backup manually over SSH.', 'mainwp-migratedb-backup-child' ),
			);
		}

		$wp_cli = $this->find_wp_cli_binary();
		if ( ! $wp_cli ) {
			return array(
				'success' => false,
				'message' => __( 'Could not find the wp-cli binary on this server (checked PATH and common install locations). Install WP-CLI on this account, or ask your host to.', 'mainwp-migratedb-backup-child' ),
			);
		}

		$dir = $this->backup_dir();
		if ( is_wp_error( $dir ) ) {
			return array(
				'success' => false,
				'message' => $dir->get_error_message(),
			);
		}

		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$filename = sanitize_file_name( ( $host ? $host : 'site' ) . '-' . gmdate( 'Y-m-d-His' ) . '.sql.gz' );
		$filepath = trailingslashit( $dir ) . $filename;

		// Deliberately no --find / --replace: a plain export is exactly what "backup" means here.
		$cmd = escapeshellarg( $wp_cli )
			. ' migratedb export ' . escapeshellarg( $filepath )
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

		if ( 0 !== $return_var || ! file_exists( $filepath ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: exit code, 2: command output */
					__( 'wp migratedb export failed (exit code %1$d): %2$s', 'mainwp-migratedb-backup-child' ),
					$return_var,
					implode( "\n", $output )
				),
			);
		}

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
			wp_die( esc_html__( 'This backup download link is invalid or has expired.', 'mainwp-migratedb-backup-child' ), '', array( 'response' => 404 ) );
		}

		delete_transient( self::TOKEN_PREFIX . $token ); // Single use.

		nocache_headers();
		header( 'Content-Type: application/gzip' );
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
			return new \WP_Error( 'mwp_dev_mkdir_failed', __( 'Could not create the backups directory in uploads.', 'mainwp-migratedb-backup-child' ) );
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

new MainWP_MigrateDB_Backup_Child();
