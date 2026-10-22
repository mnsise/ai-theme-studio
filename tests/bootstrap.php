<?php
/**
 * Unit test bootstrap: minimal, behaviour-faithful stubs of the WordPress
 * functions used by the pure (WordPress-independent) parts of the plugin.
 * Integration behaviour (pages, menus, Elementor documents) is covered by
 * tests/Integration, which needs a real WordPress (see docs/TESTING.md).
 *
 * @package AIThemeStudio
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ATS_VERSION', '1.0.0' );
define( 'ATS_DB_VERSION', '1' );
define( 'ATS_FILE', dirname( __DIR__ ) . '/ai-theme-studio.php' );
define( 'ATS_DIR', dirname( __DIR__ ) . '/' );
define( 'ATS_URL', 'https://example.test/wp-content/plugins/ai-theme-studio/' );
define( 'ATS_MIN_ELEMENTOR', '3.16.0' );
define( 'ATS_MIN_WP', '6.3' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MB_IN_BYTES', 1048576 );

require_once ATS_DIR . 'includes/autoload.php';

/**
 * Shared in-memory WordPress state for stubs.
 */
final class WP_Stub_State {
	/** @var array<string, mixed> */
	public static array $options = array();
	/** @var array<string, array<int, array{0: callable, 1: int}>> */
	public static array $filters = array();
	/** @var array<string, mixed> */
	public static array $transients = array();
	/** @var array<int, array<string, mixed>> */
	public static array $meta = array();
	/** @var array<int, array{url: string, args: array<string, mixed>}> */
	public static array $http = array();
	/** @var array<int, mixed> */
	public static array $http_responses = array();

	public static function reset(): void {
		self::$options        = array();
		self::$filters        = array();
		self::$transients     = array();
		self::$meta           = array();
		self::$http           = array();
		self::$http_responses = array();
	}
}

class WP_Error {
	/** @var array<string, array<int, string>> */
	public array $errors = array();
	/** @var array<string, mixed> */
	public array $error_data = array();

	public function __construct( string $code = '', string $message = '', $data = '' ) {
		if ( $code ) {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}

	public function get_error_code(): string {
		return (string) array_key_first( $this->errors );
	}

	public function get_error_message(): string {
		$code = $this->get_error_code();
		return $code ? $this->errors[ $code ][0] : '';
	}

	public function get_error_data() {
		return $this->error_data[ $this->get_error_code() ] ?? null;
	}

	public function add_data( $data ): void {
		$this->error_data[ $this->get_error_code() ] = $data;
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = '' ) {
	return $text;
}
function esc_html__( $text, $domain = '' ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function _n( $single, $plural, $number, $domain = '' ) {
	return 1 === $number ? $single : $plural;
}

function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	WP_Stub_State::$filters[ $hook ][] = array( $cb, $args );
	return true;
}
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	return add_filter( $hook, $cb, $priority, $args );
}
function has_filter( $hook ) {
	return ! empty( WP_Stub_State::$filters[ $hook ] );
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( WP_Stub_State::$filters[ $hook ] ?? array() as $f ) {
		$value = call_user_func_array( $f[0], array_slice( array_merge( array( $value ), $args ), 0, max( 1, $f[1] ) ) );
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	foreach ( WP_Stub_State::$filters[ $hook ] ?? array() as $f ) {
		call_user_func_array( $f[0], $args );
	}
}

function get_option( $name, $default = false ) {
	return WP_Stub_State::$options[ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	WP_Stub_State::$options[ $name ] = $value;
	return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( ! isset( WP_Stub_State::$options[ $name ] ) ) {
		WP_Stub_State::$options[ $name ] = $value;
	}
	return true;
}
function delete_option( $name ) {
	unset( WP_Stub_State::$options[ $name ] );
	return true;
}

function get_transient( $key ) {
	return WP_Stub_State::$transients[ $key ] ?? false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	WP_Stub_State::$transients[ $key ] = $value;
	return true;
}

function get_post_meta( $id, $key = '', $single = false ) {
	$v = WP_Stub_State::$meta[ $id ][ $key ] ?? '';
	return $single ? $v : array( $v );
}
function update_post_meta( $id, $key, $value ) {
	WP_Stub_State::$meta[ $id ][ $key ] = $value;
	return true;
}

function get_current_user_id() {
	return 1;
}

function wp_salt( $scheme = 'auth' ) {
	return 'unit-test-salt-' . $scheme . '-0123456789abcdef0123456789abcdef';
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = strip_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}
function sanitize_textarea_field( $str ) {
	$str = strip_tags( (string) $str );
	return trim( $str );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_title( $title ) {
	$title = strip_tags( (string) $title );
	$title = mb_strtolower( $title );
	$title = preg_replace( '/[^\p{L}\p{N}\s-]+/u', '', $title );
	$title = preg_replace( '/[\s-]+/u', '-', $title );
	return trim( $title, '-' );
}
function sanitize_email( $email ) {
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}
function is_email( $email ) {
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}
function sanitize_hex_color( $color ) {
	return preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $color ) ? $color : null;
}
function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
}
function absint( $v ) {
	return abs( (int) $v );
}
function rest_sanitize_boolean( $v ) {
	if ( is_string( $v ) ) {
		$v = strtolower( $v );
		if ( in_array( $v, array( 'false', '0', '' ), true ) ) {
			return false;
		}
	}
	return (bool) $v;
}
function esc_url_raw( $url, $protocols = null ) {
	$url    = trim( (string) $url );
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	$allow  = $protocols ? $protocols : array( 'http', 'https', 'mailto', 'tel' );
	if ( '' === $scheme || ! in_array( $scheme, $allow, true ) ) {
		return '';
	}
	return $url;
}
function esc_url( $url, $protocols = null ) {
	return esc_url_raw( $url, $protocols );
}
function untrailingslashit( $s ) {
	return rtrim( (string) $s, '/\\' );
}
function trailingslashit( $s ) {
	return untrailingslashit( $s ) . '/';
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}
function home_url( $path = '' ) {
	return 'https://example.test' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function wp_trim_words( $text, $num = 55, $more = '…' ) {
	$words = preg_split( '/\s+/u', trim( (string) $text ) );
	return count( $words ) > $num ? implode( ' ', array_slice( $words, 0, $num ) ) . $more : implode( ' ', $words );
}
function wp_basename( $path ) {
	return basename( (string) $path );
}
function wp_kses( $html, $allowed ) {
	return strip_tags( (string) $html, '<' . implode( '><', array_keys( (array) $allowed ) ) . '>' );
}
function wp_kses_post( $html ) {
	return (string) $html;
}
function current_user_can( $cap, ...$args ) {
	return true;
}
function get_locale() {
	return 'fa_IR';
}

function wp_remote_post( $url, $args = array() ) {
	WP_Stub_State::$http[] = array(
		'url'  => $url,
		'args' => $args,
	);
	$next = array_shift( WP_Stub_State::$http_responses );
	return null === $next ? new WP_Error( 'http_request_failed', 'No stubbed response' ) : $next;
}
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
}
function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}
function wp_remote_retrieve_header( $response, $header ) {
	return is_array( $response ) ? (string) ( $response['headers'][ strtolower( $header ) ] ?? '' ) : '';
}

require_once __DIR__ . '/Support/TestCase.php';
