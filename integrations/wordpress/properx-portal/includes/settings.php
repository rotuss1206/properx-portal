<?php
/**
 * Stored settings. A constant in wp-config.php wins over the stored value:
 *
 *     define( 'PROPERX_PORTAL_URL', 'https://portal.example.org' );
 *     define( 'PROPERX_PORTAL_SECRET', '...' );   // same value as WP_INTAKE_SECRET in the portal
 *     define( 'PROPERX_PORTAL_FORM_ID', 1 );
 *
 * The secret is stored encrypted (sodium) with a key derived from the WordPress salts. A database
 * copy alone does not reveal it. If the salts change it can no longer be read, and the page asks for it again.
 */

defined( 'ABSPATH' ) || exit;

const PROPERX_PORTAL_OPTION          = 'properx_portal_settings';
const PROPERX_PORTAL_OPTION_TEST     = 'properx_portal_last_test';
const PROPERX_PORTAL_OPTION_CRON     = 'properx_portal_cron_seen';
const PROPERX_PORTAL_HEARTBEAT       = 'properx_portal_cron_heartbeat';
const PROPERX_PORTAL_SECRET_MIN_SIZE = 32;

/**
 * @return array{url: string, form_id: int, secret: string, send_enabled: bool, approval_email_enabled: bool}
 */
function properx_portal_stored_settings() {
	$stored = get_option( PROPERX_PORTAL_OPTION, array() );

	return array_merge(
		array(
			'url'                    => '',
			'form_id'                => 0,
			'secret'                 => '',
			'send_enabled'           => false,
			'approval_email_enabled' => false,
		),
		is_array( $stored ) ? $stored : array()
	);
}

function properx_portal_save_settings( array $changes ) {
	update_option( PROPERX_PORTAL_OPTION, array_merge( properx_portal_stored_settings(), $changes ) );
}

function properx_portal_url_is_constant() {
	return defined( 'PROPERX_PORTAL_URL' );
}

function properx_portal_secret_is_constant() {
	return defined( 'PROPERX_PORTAL_SECRET' );
}

function properx_portal_form_id_is_constant() {
	return defined( 'PROPERX_PORTAL_FORM_ID' );
}

function properx_portal_url() {
	$url = properx_portal_url_is_constant() ? PROPERX_PORTAL_URL : properx_portal_stored_settings()['url'];

	return rtrim( (string) $url, '/' );
}

function properx_portal_form_id() {
	return (int) ( properx_portal_form_id_is_constant() ? PROPERX_PORTAL_FORM_ID : properx_portal_stored_settings()['form_id'] );
}

/**
 * The plain secret, for signing only. Never print or log it. Empty when missing or unreadable.
 */
function properx_portal_secret() {
	if ( properx_portal_secret_is_constant() ) {
		return (string) PROPERX_PORTAL_SECRET;
	}

	$stored = properx_portal_stored_settings()['secret'];

	return '' === $stored ? '' : (string) properx_portal_decrypt( $stored, properx_portal_encryption_key() );
}

/**
 * A secret is stored but the current salts cannot read it.
 */
function properx_portal_secret_is_unreadable() {
	return ! properx_portal_secret_is_constant()
		&& '' !== properx_portal_stored_settings()['secret']
		&& '' === properx_portal_secret();
}

function properx_portal_is_configured() {
	return '' !== properx_portal_url() && '' !== properx_portal_secret() && properx_portal_form_id() > 0;
}

function properx_portal_sending_enabled() {
	return (bool) properx_portal_stored_settings()['send_enabled'];
}

function properx_portal_approval_email_enabled() {
	return (bool) properx_portal_stored_settings()['approval_email_enabled'];
}

function properx_portal_encryption_key() {
	return sodium_crypto_generichash( 'properx-portal-secret|' . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
}

function properx_portal_encrypt( $plain, $key ) {
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

	return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
}

/**
 * @return string|null Null when it cannot be read: damaged, or another key.
 */
function properx_portal_decrypt( $stored, $key ) {
	$raw = base64_decode( (string) $stored, true );

	if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
		return null;
	}

	$plain = sodium_crypto_secretbox_open(
		substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
		substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
		$key
	);

	return false === $plain ? null : $plain;
}

/**
 * Stands for the current URL and secret, so a changed value needs a new Test connection. Keyed, so
 * it says nothing about the secret.
 */
function properx_portal_connection_fingerprint() {
	return hash_hmac( 'sha256', properx_portal_url() . '|' . properx_portal_secret(), properx_portal_encryption_key() );
}

/**
 * New values from the settings form. Fields set by a constant are left alone, an empty secret
 * keeps the stored one.
 *
 * @return list<string> What could not be saved.
 */
function properx_portal_apply_settings_form( array $input ) {
	$errors  = array();
	$changes = array(
		'send_enabled'           => ! empty( $input['send_enabled'] ),
		'approval_email_enabled' => ! empty( $input['approval_email_enabled'] ),
	);

	if ( ! properx_portal_url_is_constant() ) {
		$url = trim( (string) ( $input['url'] ?? '' ) );

		if ( '' === $url || properx_portal_url_is_acceptable( $url ) ) {
			$changes['url'] = rtrim( $url, '/' );
		} else {
			$errors[] = 'Portal URL must be a full https:// address.';
		}
	}

	if ( ! properx_portal_form_id_is_constant() ) {
		$changes['form_id'] = absint( $input['form_id'] ?? 0 );
	}

	if ( ! properx_portal_secret_is_constant() ) {
		$secret = trim( (string) ( $input['secret'] ?? '' ) );

		if ( '' !== $secret && strlen( $secret ) < PROPERX_PORTAL_SECRET_MIN_SIZE ) {
			$errors[] = 'The secret is too short: it was not saved. Paste the whole value.';
		} elseif ( '' !== $secret ) {
			$changes['secret'] = properx_portal_encrypt( $secret, properx_portal_encryption_key() );
		}
	}

	properx_portal_save_settings( $changes );

	return $errors;
}

function properx_portal_url_is_acceptable( $url ) {
	return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && ! empty( wp_parse_url( $url, PHP_URL_HOST ) );
}

// Life cycle.

function properx_portal_activate() {
	add_option( PROPERX_PORTAL_OPTION, array( 'send_enabled' => false, 'approval_email_enabled' => false ) );
	properx_portal_schedule_heartbeat();
}

/**
 * Removes nothing. Only the heartbeat stops; a queued delivery waits and runs once the plugin is back.
 */
function properx_portal_deactivate() {
	wp_clear_scheduled_hook( PROPERX_PORTAL_HEARTBEAT );
}

function properx_portal_schedule_heartbeat() {
	if ( ! wp_next_scheduled( PROPERX_PORTAL_HEARTBEAT ) ) {
		wp_schedule_event( time(), 'hourly', PROPERX_PORTAL_HEARTBEAT );
	}
}

// Shows that WP-Cron really runs on this site; the setup checklist reads it.
add_action(
	PROPERX_PORTAL_HEARTBEAT,
	function () {
		update_option( PROPERX_PORTAL_OPTION_CRON, time(), false );
	}
);
