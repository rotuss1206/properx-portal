<?php
/**
 * The license copy uploaded on the application (field with Admin Field Label ppx_license_copy).
 *
 * - As soon as the entry is saved (before any email goes), the file leaves Gravity Forms' uploads
 *   folder: it is encrypted (sodium, key from the WordPress salts) into the plugin's private folder
 *   under a random name, the original is deleted and the entry keeps no link to it. So the site never
 *   serves it, and staff emails carry no file link.
 * - After the paid application reached the portal, the connector sends the copy (properx_portal_send_license_copy)
 *   and deletes it here once the portal has it. A failure leaves it, with a note, for the next retry.
 * - Copies of entries that were never paid go after PROPERX_PORTAL_LICENSE_COPY_DAYS; a deleted entry
 *   takes its copy along.
 */

defined( 'ABSPATH' ) || exit;

const PROPERX_PORTAL_LICENSE_COPY_LABEL = 'ppx_license_copy';
const PROPERX_PORTAL_LICENSE_COPY_META  = 'properx_portal_license_copy';
const PROPERX_PORTAL_LICENSE_COPY_DIR   = 'properx-portal-private';
const PROPERX_PORTAL_LICENSE_COPY_DAYS  = 30;
const PROPERX_PORTAL_LICENSE_COPY_CLEAN = 'properx_portal_license_copy_cleanup';

function properx_portal_license_copy_key() {
	return sodium_crypto_generichash( 'properx-portal-license-copy|' . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
}

/**
 * The private folder, created with rules that refuse every web request.
 */
function properx_portal_license_copy_dir() {
	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . PROPERX_PORTAL_LICENSE_COPY_DIR;

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
	}

	if ( ! file_exists( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}

	return $dir;
}

/**
 * @return array{file: string, name: string, size: int, at: int}|null
 */
function properx_portal_license_copy( $entry_id ) {
	$meta = gform_get_meta( (int) $entry_id, PROPERX_PORTAL_LICENSE_COPY_META );

	return is_array( $meta ) && ! empty( $meta['file'] ) ? $meta : null;
}

/**
 * Plain bytes of an entry's copy, or null (missing or unreadable).
 */
function properx_portal_license_copy_contents( $entry_id ) {
	$copy = properx_portal_license_copy( $entry_id );
	$path = null === $copy ? '' : properx_portal_license_copy_dir() . '/' . basename( $copy['file'] );

	if ( '' === $path || ! is_readable( $path ) ) {
		return null;
	}

	return properx_portal_decrypt( (string) file_get_contents( $path ), properx_portal_license_copy_key() );
}

function properx_portal_forget_license_copy( $entry_id ) {
	$copy = properx_portal_license_copy( $entry_id );

	if ( null !== $copy ) {
		wp_delete_file( properx_portal_license_copy_dir() . '/' . basename( $copy['file'] ) );
	}

	gform_delete_meta( (int) $entry_id, PROPERX_PORTAL_LICENSE_COPY_META );
}

// Right after the entry is saved; the entry returned here is the one emails and the confirmation use.
add_filter( 'gform_entry_post_save', 'properx_portal_take_license_copy', 5, 2 );

function properx_portal_take_license_copy( $entry, $form ) {
	if ( (int) rgar( $form, 'id' ) !== properx_portal_form_id() || ! function_exists( 'properx_portal_field_by_label' ) ) {
		return $entry;
	}

	$field = properx_portal_field_by_label( $form, PROPERX_PORTAL_LICENSE_COPY_LABEL );
	$url   = null === $field ? '' : trim( (string) rgar( $entry, (string) $field->id ) );

	if ( '' === $url ) {
		return $entry;
	}

	// A single file field holds one URL; a multi-file one a JSON list (only the first is kept).
	$list = json_decode( $url, true );
	$url  = is_array( $list ) ? (string) reset( $list ) : $url;
	$path = class_exists( 'GFFormsModel' ) ? GFFormsModel::get_physical_file_path( $url, $entry['id'] ) : '';

	if ( '' === $path || ! is_readable( $path ) ) {
		properx_portal_note( $entry['id'], 'License copy: the uploaded file could not be read; ask the member to upload it in the portal.', false );

		return $entry;
	}

	$contents = (string) file_get_contents( $path );
	$name     = sanitize_file_name( wp_basename( $path ) );
	$file     = wp_generate_password( 32, false ) . '.bin';

	if ( false === file_put_contents( properx_portal_license_copy_dir() . '/' . $file, properx_portal_encrypt( $contents, properx_portal_license_copy_key() ) ) ) {
		properx_portal_note( $entry['id'], 'License copy: it could not be stored for the portal; ask the member to upload it in the portal.', false );

		return $entry;
	}

	gform_update_meta( $entry['id'], PROPERX_PORTAL_LICENSE_COPY_META, array( 'file' => $file, 'name' => $name, 'size' => strlen( $contents ), 'at' => time() ) );

	// No copy stays where the site serves files, and the entry keeps no link to one.
	wp_delete_file( $path );
	GFAPI::update_entry_field( $entry['id'], $field->id, '' );
	$entry[ (string) $field->id ] = '';

	properx_portal_note( $entry['id'], 'License copy received; it goes to the portal with the paid application.', true );

	return $entry;
}

/**
 * Sends an entry's copy to the portal. Called by the connector once the application itself is in.
 *
 * @return array{status: string, reason: string} status: ok (nothing more to do), retry or rejected.
 */
function properx_portal_send_license_copy( $entry ) {
	$copy = properx_portal_license_copy( $entry['id'] );

	if ( null === $copy ) {
		return array( 'status' => 'ok', 'reason' => '' );
	}

	$contents = properx_portal_license_copy_contents( $entry['id'] );

	if ( null === $contents ) {
		properx_portal_forget_license_copy( $entry['id'] );

		return array( 'status' => 'rejected', 'reason' => 'the stored copy could not be read' );
	}

	$body      = wp_json_encode( array( 'entry_id' => (string) $entry['id'], 'file_name' => $copy['name'], 'content' => base64_encode( $contents ) ) );
	$timestamp = (string) time();

	$response = wp_remote_post(
		properx_portal_url() . '/api/intake/license-copy',
		array(
			'timeout'   => 60,
			'sslverify' => 'local' !== wp_get_environment_type(),
			'headers'   => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'X-Timestamp'  => $timestamp,
				'X-Signature'  => hash_hmac( 'sha256', $timestamp . '.' . $body, properx_portal_secret() ),
			),
			'body'      => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array( 'status' => 'retry', 'reason' => 'license copy: connection error (' . $response->get_error_code() . ')' );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 === $code ) {
		$outcome = is_array( $json ) ? (string) rgar( $json, 'outcome' ) : '';
		properx_portal_forget_license_copy( $entry['id'] );

		return array( 'status' => 'ok', 'reason' => 'skipped' === $outcome ? 'license copy not stored: no member account for it' : 'license copy ' . ( 'already_stored' === $outcome ? 'already in' : 'stored in' ) . ' License Documents' );
	}

	// The portal checks the bytes: a file that is not a PDF, JPG or PNG up to 10 MB will not pass later.
	if ( 422 === $code ) {
		properx_portal_forget_license_copy( $entry['id'] );

		return array( 'status' => 'rejected', 'reason' => 'license copy refused by the portal (not a PDF, JPG or PNG up to 10 MB); ask the member to upload it in the portal' );
	}

	return array( 'status' => 'retry', 'reason' => 'license copy: HTTP ' . $code );
}

// A deleted entry takes its copy along.
add_action( 'gform_delete_entry', 'properx_portal_forget_license_copy' );

// Daily: copies of entries never paid, older than PROPERX_PORTAL_LICENSE_COPY_DAYS.
add_action( PROPERX_PORTAL_LICENSE_COPY_CLEAN, 'properx_portal_clean_license_copies' );

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( PROPERX_PORTAL_LICENSE_COPY_CLEAN ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', PROPERX_PORTAL_LICENSE_COPY_CLEAN );
		}
	}
);

function properx_portal_clean_license_copies() {
	global $wpdb;

	$table = class_exists( 'GFFormsModel' ) ? GFFormsModel::get_entry_meta_table_name() : '';

	if ( '' === $table ) {
		return;
	}

	$entry_ids = $wpdb->get_col( $wpdb->prepare( "SELECT entry_id FROM {$table} WHERE meta_key = %s", PROPERX_PORTAL_LICENSE_COPY_META ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	foreach ( $entry_ids as $entry_id ) {
		$copy  = properx_portal_license_copy( $entry_id );
		$entry = GFAPI::get_entry( (int) $entry_id );

		if ( is_wp_error( $entry ) ) {
			properx_portal_forget_license_copy( $entry_id );
		} elseif ( null !== $copy && $copy['at'] < time() - PROPERX_PORTAL_LICENSE_COPY_DAYS * DAY_IN_SECONDS && ! properx_portal_is_paid( $entry ) ) {
			properx_portal_forget_license_copy( $entry_id );
			properx_portal_note( $entry_id, 'License copy deleted: the application was not paid within ' . PROPERX_PORTAL_LICENSE_COPY_DAYS . ' days.', true );
		}
	}
}

/**
 * The entry box's line about the copy.
 */
function properx_portal_license_copy_status( $entry_id ) {
	$copy = properx_portal_license_copy( $entry_id );

	return null === $copy ? '' : 'License copy: kept encrypted until the portal has it.';
}
