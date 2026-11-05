<?php
/**
 * The connector. On payment it queues a delivery (Action Scheduler if present, otherwise WP-Cron).
 * Failed deliveries retry with growing pauses for about 24 hours. The result of each attempt is a
 * note on the entry. Nothing is sent while "Send paid applications to the portal" is off.
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

/**
 * Whether anything may leave for the portal at all.
 */
function properx_portal_can_send() {
	return properx_portal_sending_enabled() && properx_portal_is_configured();
}

/**
 * What a test request's result means, in words an administrator can act on.
 *
 * @return array{ok: bool, message: string}
 */
function properx_portal_test_connection() {
	$timestamp = (string) time();

	$response = wp_remote_post(
		properx_portal_url() . '/api/intake/ping',
		array(
			'timeout'   => 15,
			'sslverify' => 'local' !== wp_get_environment_type(),
			'headers'   => array(
				'Accept'      => 'application/json',
				'X-Timestamp' => $timestamp,
				'X-Signature' => hash_hmac( 'sha256', $timestamp . '.', properx_portal_secret() ),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		$error = $response->get_error_message();

		if ( false !== stripos( $error, 'timed out' ) || false !== stripos( $error, 'cURL error 28' ) ) {
			return array( 'ok' => false, 'message' => 'No answer within 15 seconds. A firewall may be dropping the request, or the portal is down. Check that this server can reach the portal.' );
		}

		if ( false !== stripos( $error, 'SSL' ) || false !== stripos( $error, 'certificate' ) ) {
			return array( 'ok' => false, 'message' => 'The portal\'s TLS certificate could not be verified from this server.' );
		}

		return array( 'ok' => false, 'message' => 'Could not connect to the portal. Check the Portal URL and this server\'s outbound access.' );
	}

	return properx_portal_test_result( (int) wp_remote_retrieve_response_code( $response ) );
}

/**
 * @return array{ok: bool, message: string}
 */
function properx_portal_test_result( $code ) {
	switch ( $code ) {
		case 200:
			return array( 'ok' => true, 'message' => 'Connected. The portal accepted the signature, so paid applications will reach it.' );
		case 401:
			return array( 'ok' => false, 'message' => 'The portal refused the signature (401). The Secret differs from WP_INTAKE_SECRET, or this server\'s clock is more than 5 minutes off.' );
		case 403:
			return array( 'ok' => false, 'message' => 'Blocked before reaching the portal (403), probably by the WAF. Allow this server\'s outbound IP for /api/intake/*.' );
		case 404:
			return array( 'ok' => false, 'message' => 'Not found (404). INTAKE_ENABLED is off on the portal, or the Portal URL is wrong.' );
	}

	return array( 'ok' => false, 'message' => 'The portal answered HTTP ' . $code . '. Try again in a few minutes.' );
}

/**
 * Runs the test and keeps its result, so the page can show it after a reload. Reasons come from
 * properx_portal_test_connection(), in fixed words; nothing from the secret or the signature.
 *
 * @return array{ok: bool, message: string}
 */
function properx_portal_run_connection_test() {
	$result = properx_portal_test_connection();

	update_option(
		PROPERX_PORTAL_OPTION_TEST,
		array(
			'ok'          => $result['ok'],
			'message'     => $result['message'],
			'at'          => time(),
			'fingerprint' => properx_portal_connection_fingerprint(),
		),
		false
	);

	return $result;
}

/**
 * The last test as it stands now. A result for an older URL or secret no longer counts.
 *
 * @return array{state: string, time: string, message: string} state: none, stale, ok or failed.
 */
function properx_portal_connection_status() {
	$none = array( 'state' => 'none', 'time' => '', 'message' => '' );

	if ( '' === properx_portal_url() || '' === properx_portal_secret() ) {
		return $none;
	}

	$last = get_option( PROPERX_PORTAL_OPTION_TEST, array() );

	if ( ! is_array( $last ) || ! isset( $last['ok'] ) ) {
		return $none;
	}

	if ( ( $last['fingerprint'] ?? '' ) !== properx_portal_connection_fingerprint() ) {
		return array( 'state' => 'stale', 'time' => '', 'message' => '' );
	}

	return array(
		'state'   => $last['ok'] ? 'ok' : 'failed',
		'time'    => empty( $last['at'] ) ? '' : properx_portal_format_time( $last['at'] ),
		'message' => (string) ( $last['message'] ?? '' ),
	);
}

// Subscriptions: a card payment, or an ACH payment once Stripe confirms it. One-off payments too,
// should the form ever change. Yearly renewals do not fire these.
add_action( 'gform_post_subscription_started', 'properx_portal_on_paid' );
add_action( 'gform_post_payment_completed', 'properx_portal_on_paid' );
add_action( PROPERX_PORTAL_HOOK, 'properx_portal_deliver', 10, 3 );

function properx_portal_on_paid( $entry ) {
	if ( properx_portal_can_send() && (int) rgar( $entry, 'form_id' ) === properx_portal_form_id() ) {
		properx_portal_schedule( (int) $entry['id'], 0, time() );
	}
}

add_filter( 'gform_disable_notification', 'properx_portal_send_start_notification_once', 10, 5 );

/**
 * Stripe's webhooks can arrive together, and Gravity Forms then starts the subscription twice in the
 * same second, so an email on the "Subscription Created" event would go out twice. This lets each such
 * notification go once per entry. add_option() is the atomic part: only one request can create the option.
 */
function properx_portal_send_start_notification_once( $is_disabled, $notification, $form, $entry, $data = array() ) {
	if ( $is_disabled || 'create_subscription' !== rgar( $notification, 'event' ) || (int) rgar( $form, 'id' ) !== properx_portal_form_id() ) {
		return $is_disabled;
	}

	$meta = 'properx_portal_sent_' . rgar( $notification, 'id' );
	$lock = 'properx_portal_lock_' . md5( $entry['id'] . '|' . rgar( $notification, 'id' ) );

	if ( gform_get_meta( $entry['id'], $meta ) || ! add_option( $lock, time(), '', false ) ) {
		return true;
	}

	gform_update_meta( $entry['id'], $meta, time() );
	delete_option( $lock );

	return false;
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
	GFAPI::add_note( $entry_id, 0, 'ProPerX Portal', $text, 'properx-portal', $success ? 'success' : 'error' );
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
		properx_portal_url() . '/api/intake/individual',
		array(
			'timeout'   => 15,
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

	// Turned off while this was queued. "Resend to portal" sends it later.
	if ( ! properx_portal_can_send() ) {
		properx_portal_note( $entry_id, 'Not sent to portal: sending is off.', false );

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
		if ( (int) rgar( $form, 'id' ) === properx_portal_form_id() ) {
			$boxes['properx_portal'] = array(
				'title'    => 'ProPerX Portal',
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

	if ( ! properx_portal_can_send() ) {
		echo '<p>Sending is off: Forms &gt; Settings &gt; ProPerX Portal.</p>';
	} elseif ( properx_portal_is_paid( $entry ) ) {
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

		if ( properx_portal_can_send() && ! is_wp_error( $entry ) && properx_portal_is_paid( $entry ) ) {
			properx_portal_schedule( $entry_id, 0, time() );
			properx_portal_note( $entry_id, 'Resend to portal queued.', true );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=gf_entries' ) );
		exit;
	}
);

// Entry list: bulk "Resend to portal".

add_filter(
	'gform_entry_list_bulk_actions',
	function ( $actions, $form_id ) {
		if ( properx_portal_can_send() && (int) $form_id === properx_portal_form_id() ) {
			$actions['properx_portal_resend'] = 'Resend to portal';
		}

		return $actions;
	},
	10,
	2
);

add_action(
	'gform_entry_list_action',
	function ( $action, $entries, $form_id ) {
		if ( 'properx_portal_resend' !== $action || ! properx_portal_can_send() || (int) $form_id !== properx_portal_form_id() || ! GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) ) {
			return;
		}

		$queued  = 0;
		$skipped = 0;

		foreach ( (array) $entries as $entry_id ) {
			$entry = GFAPI::get_entry( (int) $entry_id );

			if ( ! is_wp_error( $entry ) && properx_portal_is_paid( $entry ) ) {
				properx_portal_schedule( (int) $entry_id, 0, time() );
				properx_portal_note( (int) $entry_id, 'Resend to portal queued.', true );
				++$queued;
			} else {
				++$skipped;
			}
		}

		// This action runs while the list is being drawn, where Gravity Forms prints its own messages.
		printf(
			'<div class="alert success"><p>%s</p></div>',
			esc_html( sprintf( 'Resend to portal: %d queued, %d skipped (not paid).', $queued, $skipped ) )
		);
	},
	10,
	3
);
