<?php
/**
 * Renames the login URL to a custom slug (e.g. /freckleadmin/) and blocks
 * direct access to wp-login.php and to wp-admin for logged-out visitors.
 *
 * Same technique plugins like WPS Hide Login use: intercept on
 * 'plugins_loaded' (early — before wp-login.php's own logic or wp-admin's
 * auth_redirect() run), then rewrite the URLs WordPress generates so links,
 * redirects and logout all point at the new slug instead of wp-login.php.
 *
 * This only ever affects the *login* URL. wp-admin itself is not moved —
 * once you're authenticated, wp-admin/* works exactly as normal.
 */

namespace Freckle\Lockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Login_Rename {

	protected $enabled = false;
	protected $slug    = 'freckleadmin';

	public function __construct() {
		$options = Settings::get_instance()->get_options();

		$this->enabled = ! empty( $options['login_rename_enabled'] );
		$this->slug    = ! empty( $options['login_slug'] ) ? $options['login_slug'] : $this->slug;

		if ( ! $this->enabled ) {
			return;
		}

		// Hooked on wp_loaded (not plugins_loaded): by this point WP has
		// finished its own bootstrap — including wp_functionality_constants()
		// (AUTOSAVE_INTERVAL, WP_POST_REVISIONS, etc.) — which wp-login.php's
		// login_header()/wp_print_head_scripts() needs. Requiring wp-login.php
		// any earlier than that fatals with "Undefined constant AUTOSAVE_INTERVAL".
		// wp_loaded still fires well before wp-admin's own auth_redirect(), so
		// the intercept still wins the race there.
		add_action( 'wp_loaded', array( $this, 'intercept_request' ), 0 );

		add_filter( 'site_url', array( $this, 'filter_login_url' ), 10, 4 );
		add_filter( 'network_site_url', array( $this, 'filter_login_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'filter_redirect' ), 10, 2 );
	}

	/**
	 * Runs early on every request. Decides whether to:
	 * - serve wp-login.php (request is for our custom slug)
	 * - block a direct hit on the real wp-login.php
	 * - redirect a logged-out wp-admin request to the new slug
	 */
	public function intercept_request() {
		if ( defined( 'FRECKLE_LOCKDOWN_SERVING_LOGIN' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$path = $this->request_path();

		// Hit on the custom slug: serve the real login page directly.
		if ( $path === trim( $this->slug, '/' ) ) {
			define( 'FRECKLE_LOCKDOWN_SERVING_LOGIN', true );
			require ABSPATH . 'wp-login.php';
			exit;
		}

		global $pagenow;

		// Direct hit on the real wp-login.php: pretend it doesn't exist.
		if ( 'wp-login.php' === $pagenow ) {
			$this->send_404();
		}

		// Logged-out visitor hitting wp-admin: bounce to the new login
		// slug instead of letting core redirect to (blocked) wp-login.php.
		if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() && 'admin-ajax.php' !== $pagenow ) {
			wp_safe_redirect( home_url( '/' . trim( $this->slug, '/' ) . '/' ) );
			exit;
		}
	}

	/**
	 * Rewrites any generated wp-login.php URL onto the custom slug, e.g.
	 * wp_login_url(), wp_logout_url(), wp_registration_url().
	 *
	 * @param string      $url    The complete site URL including scheme and path.
	 * @param string      $path   Path relative to the site URL.
	 * @param string|null $scheme Scheme to give the URL context.
	 * @param int|null    $blog_id Site ID, or null for the current site.
	 * @return string
	 */
	public function filter_login_url( $url, $path, $scheme = null, $blog_id = null ) {
		if ( defined( 'FRECKLE_LOCKDOWN_SERVING_LOGIN' ) ) {
			return $url;
		}

		if ( is_string( $path ) && 0 === strpos( $path, 'wp-login.php' ) ) {
			$url = str_replace( 'wp-login.php', trim( $this->slug, '/' ), $url );
		}

		return $url;
	}

	/**
	 * Same idea, but for wp_redirect() calls (e.g. after logout).
	 *
	 * @param string $location Redirect target.
	 * @param int    $status   HTTP status code.
	 * @return string
	 */
	public function filter_redirect( $location, $status ) {
		if ( defined( 'FRECKLE_LOCKDOWN_SERVING_LOGIN' ) ) {
			return $location;
		}

		if ( is_string( $location ) && false !== strpos( $location, 'wp-login.php' ) ) {
			$location = str_replace( 'wp-login.php', trim( $this->slug, '/' ), $location );
		}

		return $location;
	}

	/**
	 * Normalized, trimmed request path (no leading/trailing slash, no query string).
	 *
	 * @return string
	 */
	protected function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return trim( (string) $path, '/' );
	}

	/**
	 * Sends a generic 404 and stops execution — used to hide wp-login.php.
	 */
	protected function send_404() {
		status_header( 404 );
		nocache_headers();
		wp_die( esc_html__( 'Not Found', 'freckle-lockdown' ), '', array( 'response' => 404 ) );
	}
}
