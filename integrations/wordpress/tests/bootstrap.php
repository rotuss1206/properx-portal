<?php

// Small stand-ins for WordPress, enough for the plugin's own logic. The whole flow is checked on the local copy.

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

const ABSPATH = '/wordpress/';
const HOUR_IN_SECONDS = 3600;

$GLOBALS['wp_options']  = array();
$GLOBALS['wp_hooks']    = array();
$GLOBALS['wp_removed']  = array();
$GLOBALS['entry_meta']  = array();

function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) {
	$GLOBALS['wp_hooks'][ $hook ][] = $callback;
}

function add_filter( $hook, $callback = null, $priority = 10, $args = 1 ) {
	$GLOBALS['wp_hooks'][ $hook ][] = $callback;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['wp_removed'][] = array( $hook, $callback );
}

function register_activation_hook( $file, $callback ) {
}

function register_deactivation_hook( $file, $callback ) {
}

function get_option( $name, $default = false ) {
	return $GLOBALS['wp_options'][ $name ] ?? $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['wp_options'][ $name ] = $value;

	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['wp_options'] ) ) {
		return false;
	}

	$GLOBALS['wp_options'][ $name ] = $value;

	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['wp_options'][ $name ] );

	return true;
}

function wp_salt( $scheme = 'auth' ) {
	return $GLOBALS['wp_salt'] ?? 'test-salt-one';
}

function wp_get_environment_type() {
	return 'production';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function absint( $value ) {
	return abs( (int) $value );
}

function esc_url( $url, $protocols = null ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function wp_date( $format, $timestamp ) {
	return gmdate( $format, $timestamp );
}

function current_user_can( $capability ) {
	return true;
}

function check_admin_referer( $action ) {
	return 1;
}

function wp_unslash( $value ) {
	return $value;
}

function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="nonce">';
}

function disabled( $value, $current, $echo ) {
	return $value === $current ? 'disabled' : '';
}

function checked( $value, $current, $echo ) {
	return $value === $current ? 'checked' : '';
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

class WP_Error {
	public function __construct( private string $text ) {
	}

	public function get_error_message() {
		return $this->text;
	}

	public function get_error_code() {
		return 'http_request_failed';
	}
}

function wp_remote_post( $url, $args ) {
	$GLOBALS['wp_requests'][] = array( $url, $args );

	return $GLOBALS['wp_response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['code'];
}

function rgar( $array, $key, $default = null ) {
	return is_array( $array ) && isset( $array[ $key ] ) ? $array[ $key ] : $default;
}

function gform_get_meta( $entry_id, $key ) {
	return $GLOBALS['entry_meta'][ $entry_id ][ $key ] ?? false;
}

function gform_update_meta( $entry_id, $key, $value ) {
	$GLOBALS['entry_meta'][ $entry_id ][ $key ] = $value;
}

$plugin = dirname( __DIR__ ) . '/properx-portal';

define( 'PROPERX_PORTAL_PLUGIN_FILE', $plugin . '/properx-portal.php' );

require_once $plugin . '/includes/settings.php';
require_once $plugin . '/includes/checklist.php';
require_once $plugin . '/includes/connector.php';
require_once $plugin . '/includes/approval-email.php';
require_once $plugin . '/includes/admin-page.php';
