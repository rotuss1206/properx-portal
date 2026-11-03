<?php
/**
 * Plugin Name: ProPerx Portal Connector
 * Description: Sends paid Gravity Forms membership applications to the ProPerx member portal.
 * Version:     1.0.0
 * Author:      ProPerx Alliance
 *
 * Must-use plugin: copy this file to wp-content/mu-plugins/. It needs three constants in
 * wp-config.php and does nothing without them:
 *
 *     define( 'PROPERX_PORTAL_URL', 'https://portal.example.org' );
 *     define( 'PROPERX_PORTAL_SECRET', '...' );   // same value as WP_INTAKE_SECRET in the portal
 *     define( 'PROPERX_PORTAL_FORM_ID', 1 );
 *
 * On payment it queues a delivery (Action Scheduler if present, otherwise WP-Cron). Failed
 * deliveries retry with growing pauses for about 24 hours. The result of each attempt is a note
 * on the entry. No card data, no files, and no personal data in notes or logs.
 *
 * Admin: "Resend to portal" in the entry's side panel.
 * WP-CLI: wp properx-portal send --form=1 [--since=YYYY-MM-DD] [--dry-run]
 */

defined( 'ABSPATH' ) || exit;

/**
 * Portal field => Gravity Forms input id of form 1. A list is joined: the address into one line,
 * the license types into an array.
 */
const PROPERX_PORTAL_FIELD_MAP = array(
	'first_name'         => '5.3',
	'last_name'          => '5.6',
	'email'              => '7',
	'phone'              => '8',
	'firm_name'          => '9',
	'npn'                => '14',
	'license_expires_at' => '15',
	'license_state'      => '16.4',
	'address'            => array( '11.1', '11.2', '11.3', '11.4', '11.5' ),
	'license_types'      => array( '17.1', '17.2', '17.3', '17.4', '17.5' ),
);

/** Pauses before each retry, in seconds: 22 hours 20 minutes in all. */
const PROPERX_PORTAL_RETRY_DELAYS = array( 300, 900, 3600, 10800, 21600, 43200 );

const PROPERX_PORTAL_HOOK        = 'properx_portal_send';
const PROPERX_PORTAL_META_STATUS = 'properx_portal_status';

function properx_portal_is_configured() {
	return defined( 'PROPERX_PORTAL_URL' ) && '' !== (string) PROPERX_PORTAL_URL
		&& defined( 'PROPERX_PORTAL_SECRET' ) && '' !== (string) PROPERX_PORTAL_SECRET
		&& defined( 'PROPERX_PORTAL_FORM_ID' ) && (int) PROPERX_PORTAL_FORM_ID > 0;
}

add_action(
	'admin_notices',
	function () {
		if ( ! properx_portal_is_configured() && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>ProPerx Portal Connector is off: PROPERX_PORTAL_URL, PROPERX_PORTAL_SECRET and PROPERX_PORTAL_FORM_ID must be set in wp-config.php.</p></div>';
		}
	}
);

if ( ! properx_portal_is_configured() ) {
	return;
}

// Subscriptions: a card payment, or an ACH payment once Stripe confirms it. One-off payments too,
// should the form ever change. Yearly renewals do not fire these.
add_action( 'gform_post_subscription_started', 'properx_portal_on_paid' );
add_action( 'gform_post_payment_completed', 'properx_portal_on_paid' );
add_action( PROPERX_PORTAL_HOOK, 'properx_portal_deliver', 10, 3 );

function properx_portal_on_paid( $entry ) {
	if ( (int) rgar( $entry, 'form_id' ) === (int) PROPERX_PORTAL_FORM_ID ) {
		properx_portal_schedule( (int) $entry['id'], 0, time() );
	}
}

/**
 * The third argument keeps a resend apart from an identical event already in the queue.
 */
function properx_portal_schedule( $entry_id, $attempt, $at ) {
	$args = array( $entry_id, $attempt, time() );

	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( $at, PROPERX_PORTAL_HOOK, $args, 'properx-portal' );
	} else {
		wp_schedule_single_event( $at, PROPERX_PORTAL_HOOK, $args );
	}
}

function properx_portal_note( $entry_id, $text, $success ) {
	GFAPI::add_note( $entry_id, 0, 'ProPerx Portal', $text, 'properx-portal', $success ? 'success' : 'error' );
}

function properx_portal_is_paid( $entry ) {
	return 'active' === rgar( $entry, 'status' ) && in_array( rgar( $entry, 'payment_status' ), array( 'Active', 'Paid' ), true );
}

/**
 * Only the mapped fields and the Stripe ids.
 */
function properx_portal_payload( $entry ) {
	$payload = array( 'entry_id' => (string) $entry['id'] );

	foreach ( PROPERX_PORTAL_FIELD_MAP as $field => $input ) {
		if ( is_array( $input ) ) {
			$values = array_values( array_filter( array_map( 'trim', array_map( 'strval', array_map( fn ( $id ) => rgar( $entry, $id ), $input ) ) ), 'strlen' ) );

			if ( 'address' === $field ) {
				$payload[ $field ] = implode( ', ', $values );
			} else {
				$payload[ $field ] = $values;
			}
		} else {
			$payload[ $field ] = trim( (string) rgar( $entry, $input ) );
		}
	}

	$customer     = gform_get_meta( $entry['id'], 'stripe_customer_id' );
	$subscription = (string) rgar( $entry, 'transaction_id' );

	$payload['stripe_customer_id']     = $customer ? (string) $customer : null;
	$payload['stripe_subscription_id'] = 0 === strpos( $subscription, 'sub_' ) ? $subscription : null;

	foreach ( array( 'phone', 'firm_name', 'license_state', 'address' ) as $optional ) {
		if ( '' === $payload[ $optional ] ) {
			$payload[ $optional ] = null;
		}
	}

	return $payload;
}

/**
 * One delivery. TLS is verified everywhere except WP_ENVIRONMENT_TYPE=local (self-signed certificate).
 *
 * @return array{status: string, reason: string} status: ok, retry or rejected.
 */
function properx_portal_post( $entry ) {
	$body      = wp_json_encode( properx_portal_payload( $entry ) );
	$timestamp = (string) time();

	$response = wp_remote_post(
		rtrim( (string) PROPERX_PORTAL_URL, '/' ) . '/api/intake/individual',
		array(
			'timeout'   => 15,
			'sslverify' => 'local' !== wp_get_environment_type(),
			'headers'   => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'X-Timestamp'  => $timestamp,
				'X-Signature'  => hash_hmac( 'sha256', $timestamp . '.' . $body, (string) PROPERX_PORTAL_SECRET ),
			),
			'body'      => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array( 'status' => 'retry', 'reason' => 'connection error (' . $response->get_error_code() . ')' );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( $code >= 200 && $code < 300 ) {
		$outcome   = is_array( $json ) ? (string) rgar( $json, 'outcome' ) : '';
		$conflicts = is_array( $json ) && ! empty( $json['conflicts'] ) ? '; differs in portal: ' . implode( ', ', (array) $json['conflicts'] ) : '';

		return array( 'status' => 'ok', 'reason' => $outcome . $conflicts );
	}

	// Bad data will not fix itself. The portal names the fields, never their values.
	if ( 422 === $code ) {
		$fields = is_array( $json ) && ! empty( $json['fields'] ) ? implode( ', ', (array) $json['fields'] ) : 'unknown';

		return array( 'status' => 'rejected', 'reason' => 'invalid fields: ' . $fields );
	}

	// Portal switched off or down, secret or clock wrong, rate limited: all can recover.
	return array( 'status' => 'retry', 'reason' => 'HTTP ' . $code );
}

/**
 * The queued job.
 */
function properx_portal_deliver( $entry_id, $attempt = 0, $queued_at = 0 ) {
	$entry = GFAPI::get_entry( $entry_id );

	if ( is_wp_error( $entry ) || ! properx_portal_is_paid( $entry ) ) {
		return;
	}

	$result = properx_portal_post( $entry );

	if ( 'ok' === $result['status'] ) {
		gform_update_meta( $entry_id, PROPERX_PORTAL_META_STATUS, 'sent' );
		properx_portal_note( $entry_id, 'Sent to portal: OK (' . $result['reason'] . ')', true );

		return;
	}

	gform_update_meta( $entry_id, PROPERX_PORTAL_META_STATUS, 'failed' );

	if ( 'retry' === $result['status'] && isset( PROPERX_PORTAL_RETRY_DELAYS[ $attempt ] ) ) {
		$delay = PROPERX_PORTAL_RETRY_DELAYS[ $attempt ];

		properx_portal_schedule( $entry_id, $attempt + 1, time() + $delay );
		properx_portal_note( $entry_id, sprintf( 'Sent to portal: failed: %s. Retry %d of %d in %d minutes.', $result['reason'], $attempt + 1, count( PROPERX_PORTAL_RETRY_DELAYS ), $delay / 60 ), false );

		return;
	}

	properx_portal_note( $entry_id, 'Sent to portal: failed: ' . $result['reason'] . '. No more retries; use "Resend to portal".', false );
}

// Entry screen: "Resend to portal". A link, because the side panel sits inside Gravity Forms' own form.

add_filter(
	'gform_entry_detail_meta_boxes',
	function ( $boxes, $entry, $form ) {
		if ( (int) rgar( $form, 'id' ) === (int) PROPERX_PORTAL_FORM_ID ) {
			$boxes['properx_portal'] = array(
				'title'    => 'ProPerx Portal',
				'callback' => 'properx_portal_entry_box',
				'context'  => 'side',
			);
		}

		return $boxes;
	},
	10,
	3
);

function properx_portal_entry_box( $args ) {
	$entry = $args['entry'];
	$url   = wp_nonce_url( admin_url( 'admin-post.php?action=properx_portal_resend&entry_id=' . (int) $entry['id'] ), 'properx_portal_resend_' . (int) $entry['id'] );

	$status = gform_get_meta( $entry['id'], PROPERX_PORTAL_META_STATUS );

	echo '<p>Status: ' . esc_html( $status ? $status : 'not sent' ) . '</p>';

	if ( properx_portal_is_paid( $entry ) ) {
		echo '<p><a class="button" href="' . esc_url( $url ) . '">Resend to portal</a></p>';
	} else {
		echo '<p>Only paid entries are sent.</p>';
	}
}

add_action(
	'admin_post_properx_portal_resend',
	function () {
		$entry_id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0;

		check_admin_referer( 'properx_portal_resend_' . $entry_id );

		if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) ) {
			wp_die( 'You are not allowed to do this.' );
		}

		$entry = GFAPI::get_entry( $entry_id );

		if ( ! is_wp_error( $entry ) && properx_portal_is_paid( $entry ) ) {
			properx_portal_schedule( $entry_id, 0, time() );
			properx_portal_note( $entry_id, 'Resend to portal queued.', true );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=gf_entries' ) );
		exit;
	}
);

/**
 * Paid entries of a form, for the WP-CLI command.
 *
 * @return list<int>
 */
function properx_portal_paid_entry_ids( $form_id, $since ) {
	$criteria = array(
		'status'        => 'active',
		'field_filters' => array(
			'mode' => 'any',
			array( 'key' => 'payment_status', 'value' => 'Active' ),
			array( 'key' => 'payment_status', 'value' => 'Paid' ),
		),
	);

	if ( $since ) {
		$criteria['start_date'] = $since . ' 00:00:00';
	}

	$ids = GFAPI::get_entry_ids( $form_id, $criteria, array( 'key' => 'id', 'direction' => 'ASC', 'is_numeric' => true ) );

	return array_map( 'intval', (array) $ids );
}

/**
 * wp properx-portal send --form=1 [--since=YYYY-MM-DD] [--dry-run]
 *
 * Sends paid entries that were not sent yet, for retries and for moving older applications
 * across. Prints entry ids only.
 */
function properx_portal_cli_send( $args, $assoc ) {
	$form_id = isset( $assoc['form'] ) ? (int) $assoc['form'] : 0;
	$since   = isset( $assoc['since'] ) ? (string) $assoc['since'] : '';
	$dry_run = ! empty( $assoc['dry-run'] );

	if ( (int) PROPERX_PORTAL_FORM_ID !== $form_id ) {
		WP_CLI::error( 'Only the configured form can be sent: --form=' . (int) PROPERX_PORTAL_FORM_ID );
	}

	if ( '' !== $since && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
		WP_CLI::error( '--since must be YYYY-MM-DD.' );
	}

	$sent    = 0;
	$failed  = 0;
	$skipped = 0;

	foreach ( properx_portal_paid_entry_ids( $form_id, $since ) as $entry_id ) {
		if ( 'sent' === gform_get_meta( $entry_id, PROPERX_PORTAL_META_STATUS ) ) {
			++$skipped;

			continue;
		}

		if ( $dry_run ) {
			WP_CLI::log( 'Would send entry ' . $entry_id );
			++$sent;

			continue;
		}

		properx_portal_deliver( $entry_id );

		if ( 'sent' === gform_get_meta( $entry_id, PROPERX_PORTAL_META_STATUS ) ) {
			WP_CLI::log( 'Sent entry ' . $entry_id );
			++$sent;
		} else {
			WP_CLI::warning( 'Failed entry ' . $entry_id . ' (see the entry notes)' );
			++$failed;
		}
	}

	$prefix = $dry_run ? 'Dry run: would send ' : 'Sent ';

	WP_CLI::success( sprintf( '%s%d, failed %d, already sent %d.', $prefix, $sent, $failed, $skipped ) );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'properx-portal send', 'properx_portal_cli_send' );
}
