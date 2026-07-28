<?php
/**
 * MainWP Development Utility
 *
 * This class handles the Utility process.
 *
 * @package MainWP/Extensions
 */

 namespace MainWP\Extensions\Development;

 /**
  * Class MainWP_Development_Utility
  *
  * @package MainWP/Extensions
  */
class MainWP_Development_Utility {


	private $option_handle = 'mainwp_development_settings';

	private $option = null;

	// Singleton
	private static $instance = null;

	static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		if ( null === $this->option ) {
			$this->option = get_option( $this->option_handle );
		}
	}

	public function get_setting( $key = null, $default = '' ) {
		if ( isset( $this->option[ $key ] ) ) {
			return $this->option[ $key ];
		}
		return $default;
	}

	public function update_setting( $key, $value ) {
		$this->option[ $key ] = $value;
		return update_option( $this->option_handle, $this->option );
	}

	public static function get_timestamp( $timestamp ) {
		$gmtOffset = get_option( 'gmt_offset' );

		return ( $gmtOffset ? ( $gmtOffset * HOUR_IN_SECONDS ) + $timestamp : $timestamp );
	}

	public static function format_timestamp( $timestamp, $gmt = false ) {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp, $gmt );
	}

	public static function format_datestamp( $timestamp, $gmt = false ) {
		return date_i18n( get_option( 'date_format' ), $timestamp, $gmt );
	}

	public static function format_date( $timestamp ) {
		return date_i18n( get_option( 'date_format' ), $timestamp );
	}

	static function ctype_digit( $str ) {
		return ( is_string( $str ) || is_int( $str ) || is_float( $str ) ) && preg_match( '/^\d+\z/', $str );
	}

	/**
	 * Method map_fields()
	 *
	 * Map Site.
	 *
	 * @param mixed $website Website to map.
	 * @param mixed $keys Keys to map.
	 * @param bool  $object_output Output format array|object.
	 *
	 * @return object $outputSite Mapped site.
	 */
	public static function map_fields( &$website, $keys, $object_output = false ) {
		$outputSite = array();
		if ( ! empty( $website ) ) {
			if ( is_object( $website ) ) {
				foreach ( $keys as $key ) {
					if ( property_exists( $website, $key ) ) {
						$outputSite[ $key ] = $website->$key;
					}
				}
			} elseif ( is_array( $website ) ) {
				foreach ( $keys as $key ) {
					if ( isset( $website[ $key ] ) ) {
						$outputSite[ $key ] = $website[ $key ];
					}
				}
			}
		}

		if ( $object_output ) {
			return (object) $outputSite;
		} else {
			return $outputSite;
		}
	}

	public static function esc_content( $content, $type = '' ) {
		if ( $type == 'note' ) {

			$allowed_html = array(
				'a'      => array(
					'href'  => array(),
					'title' => array(),
				),
				'br'     => array(),
				'em'     => array(),
				'strong' => array(),
				'p'      => array(),
				'hr'     => array(),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
				'h1'     => array(),
				'h2'     => array(),
			);

			$content = wp_kses( $content, $allowed_html );

		} else {
			$content = stripslashes( $content );
			$content = wp_kses_post( wpautop( wptexturize( $content ) ) );
		}

		return $content;
	}

	/**
	 * Get Websites
	 *
	 * Gets all child sites through the 'mainwp_getsites' filter.
	 *
	 * @param array|null $site_id  Child sites ID.
	 *
	 * @return array Child sites array.
	 */
	public static function get_websites( $site_id = null ) {
		global $mainWPDevelopmentExtensionActivator;
		return apply_filters( 'mainwp_getsites', $mainWPDevelopmentExtensionActivator->get_child_file(), $mainWPDevelopmentExtensionActivator->get_child_key(), $site_id, false );
	}

	/**
	 * Get Websites
	 *
	 * Gets all child sites through the 'mainwp_getsites' filter.
	 *
	 * @param array $site_ids  Child sites IDs.
	 * @param array $group_ids Groups IDs.
	 *
	 * @return array Child sites array.
	 */
	public static function get_db_sites( $site_ids, $group_ids = array() ) {
		if ( ! is_array( $site_ids ) ) {
			$site_ids = array();
		}

		if ( ! is_array( $group_ids ) ) {
			$group_ids = array();
		}

		if ( ! empty( $site_ids ) || ! empty( $group_ids ) ) {
			global $mainWPDevelopmentExtensionActivator;
			return apply_filters( 'mainwp_getdbsites', $mainWPDevelopmentExtensionActivator->get_child_file(), $mainWPDevelopmentExtensionActivator->get_child_key(), $site_ids, $group_ids );
		}
		return false;
	}

	/**
	 * Method verify_action_nonce(es).
	 */
	public static function verify_action_nonce() {
		if ( isset( $_GET['_nonce_development'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce_development'] ) ), 'development_nonce' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Method get_nice_url()
	 *
	 * Grab url.
	 *
	 * @param string $pUrl Website URL.
	 * @param bool   $showHttp Show HTTP.
	 *
	 * @return string $url.
	 */
	public static function get_nice_url( $pUrl, $showHttp = false ) {
		$url = $pUrl;

		if ( self::starts_with( $url, 'http://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 7 );
			}
		} elseif ( self::starts_with( $pUrl, 'https://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 8 );
			}
		} else {
			if ( $showHttp ) {
				$url = 'http://' . $url;
			}
		}

		if ( self::ends_with( $url, '/' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 0, strlen( $url ) - 1 );
			}
		} else {
			$url = $url . '/';
		}

		return $url;
	}

	/**
	 * Method starts_with()
	 *
	 * Start of Stack Trace.
	 *
	 * @param mixed $haystack The full stack.
	 * @param mixed $needle The function that is throwing the error.
	 *
	 * @return mixed Needle in the Haystack.
	 */
	public static function starts_with( $haystack, $needle ) {
		return ! strncmp( $haystack, $needle, strlen( $needle ) );
	}

	/**
	 * Method ends_with()
	 *
	 * End of Stack Trace.
	 *
	 * @param mixed $haystack Haystack parameter.
	 * @param mixed $needle Needle parameter.
	 *
	 * @return boolean
	 */
	public static function ends_with( $haystack, $needle ) {
		$length = strlen( $needle );
		if ( 0 === $length ) {
			return true;
		}

		return ( substr( $haystack, - $length ) === $needle );
	}

	/**
	 * Debugging log info.
	 *
	 * Sets logging for debugging purpose.
	 *
	 * @param string $message Log info message.
	 */
	public static function log_info( $message ) {
		static::log_debug( $message, 2 );
	}

	/**
	 * Debugging log.
	 *
	 * Sets logging for debugging purpose.
	 *
	 * @param string $message Log debug message.
	 */
	public static function log_debug( $message, $type = false ) {
		// Set color: 0 - LOG, 1 - WARNING, 2 - INFO, 3- DEBUG.
		$log_color = 3;
		if ( false !== $type ) {
			$log_color = intval( $type );
			if ( ! in_array( $log_color, array( 0, 1, 2, 3 ) ) ) {
				$log_color = 2;
			}
		}
		do_action( 'mainwp_log_action', 'Development :: ' . $message, MAINWP_DEVELOPMENT_LOG_PRIORITY, $log_color );
	}

}
