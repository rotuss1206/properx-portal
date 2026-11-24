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

function is_admin() {
	return false;
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

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
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

	// A list of answers, one per request, when a test needs them to differ.
	return empty( $GLOBALS['wp_responses'] ) ? $GLOBALS['wp_response'] : array_shift( $GLOBALS['wp_responses'] );
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['wp_scheduled'][] = array( $timestamp, $hook, $args );

	return true;
}

class GFAPI {
	public static function get_entry( $entry_id ) {
		return $GLOBALS['gf_entries'][ $entry_id ] ?? new WP_Error( 'not found' );
	}

	public static function add_note( $entry_id, $user_id, $user_name, $note, $type = '', $sub_type = '' ) {
		$GLOBALS['gf_notes'][ $entry_id ][] = array( $note, $sub_type );
	}

	// The checklist asks for the form and its Stripe feed; these tests have neither.
	public static function get_form( $form_id ) {
		return $GLOBALS['gf_forms'][ $form_id ] ?? false;
	}

	public static function get_forms() {
		return array_values( $GLOBALS['gf_forms'] ?? array() );
	}

	public static function update_entry_field( $entry_id, $input_id, $value ) {
		$GLOBALS['gf_entries'][ $entry_id ][ (string) $input_id ] = $value;

		return true;
	}

	public static function send_notifications( $form, $entry, $event = 'form_submission' ) {
		$GLOBALS['gf_sent_events'][] = array( (int) $entry['id'], $event );

		return array();
	}

	public static function get_feeds( $feed_ids = null, $form_id = null, $addon_slug = null ) {
		return array();
	}
}

function selected( $value, $current, $echo ) {
	return $value === $current ? 'selected' : '';
}

function admin_url( $path = '' ) {
	return 'https://site.test/wp-admin/' . $path;
}

function wp_nonce_url( $url, $action ) {
	return $url . '&_wpnonce=nonce';
}

function add_shortcode( $tag, $callback ) {
	$GLOBALS['wp_shortcodes'][ $tag ] = $callback;
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function rgars( $array, $path ) {
	foreach ( explode( '/', $path ) as $key ) {
		if ( ! is_array( $array ) || ! isset( $array[ $key ] ) ) {
			return null;
		}
		$array = $array[ $key ];
	}

	return $array;
}

function rgpost( $name ) {
	return $_POST[ $name ] ?? '';
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

function gform_delete_meta( $entry_id, $key ) {
	unset( $GLOBALS['entry_meta'][ $entry_id ][ $key ] );
}

const DAY_IN_SECONDS = 86400;

function wp_upload_dir( $time = null, $create = true ) {
	return array( 'basedir' => $GLOBALS['wp_upload_basedir'] );
}

function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}

function trailingslashit( $path ) {
	return rtrim( $path, '/\\' ) . '/';
}

function wp_delete_file( $file ) {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}

function wp_generate_password( $length = 12, $special = true ) {
	return substr( bin2hex( random_bytes( $length ) ), 0, $length );
}

function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '-', $name );
}

function wp_basename( $path ) {
	return basename( $path );
}

function wp_next_scheduled( $hook ) {
	return false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	return true;
}

class GFFormsModel {
	public static function get_physical_file_path( $url, $entry_id = null ) {
		return $GLOBALS['gf_upload_paths'][ $url ] ?? '';
	}

	public static function get_entry_meta_table_name() {
		return '';
	}
}

$plugin = dirname( __DIR__ ) . '/properx-portal';

define( 'PROPERX_PORTAL_PLUGIN_FILE', $plugin . '/properx-portal.php' );

require_once $plugin . '/includes/settings.php';
require_once $plugin . '/includes/welcome-email.php';
require_once $plugin . '/includes/checklist.php';
require_once $plugin . '/includes/connector.php';
require_once $plugin . '/includes/license-copy.php';
require_once $plugin . '/includes/cancellation.php';
require_once $plugin . '/includes/agency-handoff.php';
require_once $plugin . '/includes/apply-form.php';
require_once $plugin . '/includes/form-migration.php';
require_once $plugin . '/includes/approval-email.php';
require_once $plugin . '/includes/admin-page.php';
