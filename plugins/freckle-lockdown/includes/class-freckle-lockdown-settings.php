<?php
/**
 * Settings page: Settings > Freckle Lockdown.
 *
 * Two toggles + one text field, stored as a single option array.
 */

namespace Freckle\Lockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION_KEY = 'freckle_lockdown_options';

	protected static $instance = null;

	protected $defaults = array(
		'frontend_lockdown_enabled' => false,
		'login_rename_enabled'      => false,
		'login_slug'                => 'freckleadmin',
	);

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Returns the saved options merged over the defaults.
	 *
	 * @return array
	 */
	public function get_options() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, $this->defaults );
	}

	public function add_settings_page() {
		add_options_page(
			__( 'Freckle Lockdown', 'freckle-lockdown' ),
			__( 'Freckle Lockdown', 'freckle-lockdown' ),
			'manage_options',
			'freckle-lockdown',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'freckle_lockdown',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => $this->defaults,
			)
		);
	}

	/**
	 * Sanitizes and validates submitted settings.
	 *
	 * @param array $input Raw posted values.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$output = $this->get_options();

		$output['frontend_lockdown_enabled'] = ! empty( $input['frontend_lockdown_enabled'] );
		$output['login_rename_enabled']      = ! empty( $input['login_rename_enabled'] );

		$slug = isset( $input['login_slug'] ) ? sanitize_title( wp_unslash( $input['login_slug'] ) ) : '';
		if ( '' === $slug ) {
			$slug = $this->defaults['login_slug'];
			add_settings_error( self::OPTION_KEY, 'login_slug', __( 'Login slug cannot be empty — reverted to the default.', 'freckle-lockdown' ) );
		}

		// Refuse slugs that collide with existing WP entry points, since
		// that would create a redirect loop / break the site outright.
		$reserved = array( 'wp-admin', 'wp-login.php', 'wp-login', 'admin', 'login', 'wp-content', 'wp-includes' );
		if ( in_array( $slug, $reserved, true ) ) {
			$slug = $this->defaults['login_slug'];
			add_settings_error( self::OPTION_KEY, 'login_slug', __( 'That slug is reserved by WordPress — reverted to the default.', 'freckle-lockdown' ) );
		}

		$output['login_slug'] = $slug;

		return $output;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options   = $this->get_options();
		$login_url = home_url( '/' . $options['login_slug'] . '/' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Freckle Lockdown', 'freckle-lockdown' ); ?></h1>

			<?php if ( $options['login_rename_enabled'] ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: current login URL */
							esc_html__( 'Login rename is active. Your login page is now: %s — bookmark it before you navigate away.', 'freckle-lockdown' ),
							'<code>' . esc_html( $login_url ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $options['frontend_lockdown_enabled'] ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Front-end lockdown is active. Every public page — including for logged-in admins — returns a 404.', 'freckle-lockdown' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'freckle_lockdown' );
				do_settings_sections( 'freckle_lockdown' );
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Front-end lockdown', 'freckle-lockdown' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[frontend_lockdown_enabled]" value="1" <?php checked( $options['frontend_lockdown_enabled'] ); ?> />
								<?php esc_html_e( 'Return a 404 for every public front-end request (applies to everyone, including logged-in admins).', 'freckle-lockdown' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rename login URL', 'freckle-lockdown' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[login_rename_enabled]" value="1" <?php checked( $options['login_rename_enabled'] ); ?> />
								<?php esc_html_e( 'Move the login form off wp-login.php / wp-admin onto a custom slug.', 'freckle-lockdown' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="freckle_login_slug"><?php esc_html_e( 'Login slug', 'freckle-lockdown' ); ?></label>
						</th>
						<td>
							<code><?php echo esc_html( home_url( '/' ) ); ?></code>
							<input type="text" id="freckle_login_slug" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[login_slug]" value="<?php echo esc_attr( $options['login_slug'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'e.g. freckleadmin. Letters, numbers and hyphens only.', 'freckle-lockdown' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<p class="description">
				<?php esc_html_e( 'Locked out? Add define( \'FRECKLE_LOCKDOWN_DISABLE\', true ); to wp-config.php to disable both features immediately.', 'freckle-lockdown' ); ?>
			</p>
		</div>
		<?php
	}
}
