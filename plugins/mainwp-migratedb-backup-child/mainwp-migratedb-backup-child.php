<?php
/*
Plugin Name: MainWP Migrate DB Backup - Child
Plugin URI: https://mainwp.com
Description: Child-site companion for the "MainWP Development Extension" dashboard extension. Lets the MainWP dashboard trigger a one-click WP Migrate DB Pro database backup (a plain export, no find & replace) on this site. Requires the MainWP Child plugin, WP Migrate DB Pro (or WP Migrate Lite), and WP-CLI on the server.
Version: 1.0
Author: Freckle
*/

namespace MainWP\Extensions\MigrateDBBackup\Child;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MainWP_MigrateDB_Backup_Child
 *
 * Receives 'extra_execution' requests from the MainWP Development Extension dashboard
 * plugin (via the 'mainwp_child_extra_execution' filter that MainWP Child fires on every
 * request routed through its 'extra_execution' callable), runs a WP Migrate DB Pro backup,
 * and serves the resulting file back through a short-lived, single-use token URL.
 */
class MainWP_MigrateDB_Backup_Child {

	const ACTION_NAME       = 'migratedb_backup';
	const DOWNLOAD_QUERY_VAR = 'mwp_dev_dl';
	const TOKEN_PREFIX      = 'mwp_dev_dl_';
	const TOKEN_TTL         = 24 * HOUR_IN_SECONDS;
	const BACKUP_SUBDIR     = 'mainwp-dev-backups';

	public function __construct() {
		add_filter( 'mainwp_child_extra_execution', array( $this, 'handle_extra_execution' ), 10, 2 );
		add_action( 'init', array( $this, 'maybe_serve_download' ) );
	}

	/**
	 * Dispatch our own custom action out of MainWP Child's generic 'extra_execution' callable.
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
