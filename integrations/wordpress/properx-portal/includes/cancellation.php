<?php
/**
 * Cancellations. When Gravity Forms Stripe cancels the subscription of an entry of the membership
 * form (canceled in the entry, or Stripe's customer.subscription.deleted: canceled now or ended at
 * the period's end), the portal is told, so the member keeps access until the paid period ends and
 * may not apply again for 24 months. Same signature, queue, retries and entry notes as an application.
 * Nothing is sent while "Send cancellations to the portal" is off.
 */

defined( 'ABSPATH' ) || exit;

const PROPERX_PORTAL_CANCEL_HOOK        = 'properx_portal_send_cancellation';
const PROPERX_PORTAL_META_CANCELED_AT   = 'properx_portal_canceled_at';
const PROPERX_PORTAL_META_CANCEL_STATUS = 'properx_portal_cancel_status';

function properx_portal_cancellations_enabled() {
	return (bool) properx_portal_stored_settings()['cancel_send_enabled'];
}

function properx_portal_can_send_cancellations() {
	return properx_portal_cancellations_enabled() && properx_portal_is_configured();
}

add_action( 'gform_subscription_canceled', 'properx_portal_on_canceled', 10, 3 );
add_action( PROPERX_PORTAL_CANCEL_HOOK, 'properx_portal_deliver_cancellation', 10, 3 );

/**
 * Keeps the moment of the cancellation on the entry (a retry or a resend reports the same one) and
 * queues the delivery.
 */
function properx_portal_on_canceled( $entry, $feed = null, $transaction_id = '' ) {
	if ( (int) rgar( $entry, 'form_id' ) !== properx_portal_form_id() ) {
		return;
	}

	if ( ! gform_get_meta( $entry['id'], PROPERX_PORTAL_META_CANCELED_AT ) ) {
		gform_update_meta( $entry['id'], PROPERX_PORTAL_META_CANCELED_AT, time() );
	}

	if ( properx_portal_can_send_cancellations() ) {
		properx_portal_schedule_cancellation( (int) $entry['id'], 0, time() );
	}
}

function properx_portal_schedule_cancellation( $entry_id, $attempt, $at ) {
	$args = array( $entry_id, $attempt, time() );

	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( $at, PROPERX_PORTAL_CANCEL_HOOK, $args, 'properx-portal' );
	} else {
		wp_schedule_single_event( $at, PROPERX_PORTAL_CANCEL_HOOK, $args );
	}
}

/**
 * The email (to find the member) and the Stripe ids; nothing else from the entry.
 */
function properx_portal_cancellation_payload( $entry ) {
	$payload      = properx_portal_payload( $entry );
	$canceled_at  = (int) gform_get_meta( $entry['id'], PROPERX_PORTAL_META_CANCELED_AT );

	return array(
		'entry_id'               => (string) $entry['id'],
		'email'                  => $payload['email'],
		'canceled_at'            => gmdate( 'c', $canceled_at > 0 ? $canceled_at : time() ),
		'stripe_customer_id'     => $payload['stripe_customer_id'],
		'stripe_subscription_id' => $payload['stripe_subscription_id'],
	);
}

/**
 * One delivery, as properx_portal_post() for an application.
 *
 * @return array{status: string, reason: string} status: ok, retry or rejected.
 */
function properx_portal_post_cancellation( $entry ) {
	$body      = wp_json_encode( properx_portal_cancellation_payload( $entry ) );
	$timestamp = (string) time();

	$response = wp_remote_post(
		properx_portal_url() . '/api/intake/cancellation',
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
		$outcome = is_array( $json ) ? (string) rgar( $json, 'outcome' ) : '';
		$reasons = array(
			'canceled'  => 'the member is marked canceled; access until the paid period ends',
			'not_found' => 'no member with this email or subscription in the portal',
			'conflict'  => 'the portal did not change an agency member; ProPerX staff can check it',
		);

		return array( 'status' => 'ok', 'reason' => $reasons[ $outcome ] ?? $outcome );
	}

	if ( 422 === $code ) {
		$fields = is_array( $json ) && ! empty( $json['fields'] ) ? implode( ', ', (array) $json['fields'] ) : 'unknown';

		return array( 'status' => 'rejected', 'reason' => 'invalid fields: ' . $fields );
	}

	return array( 'status' => 'retry', 'reason' => 'HTTP ' . $code );
}

/**
 * The queued job: retries like an application, a note on the entry for every attempt.
 */
function properx_portal_deliver_cancellation( $entry_id, $attempt = 0, $queued_at = 0 ) {
	$entry = GFAPI::get_entry( $entry_id );

	if ( is_wp_error( $entry ) || ! gform_get_meta( $entry_id, PROPERX_PORTAL_META_CANCELED_AT ) ) {
		return;
	}

	if ( ! properx_portal_can_send_cancellations() ) {
		properx_portal_note( $entry_id, 'Cancellation not sent to portal: sending cancellations is off.', false );

		return;
	}

	$result = properx_portal_post_cancellation( $entry );

	if ( 'ok' === $result['status'] ) {
		gform_update_meta( $entry_id, PROPERX_PORTAL_META_CANCEL_STATUS, 'sent' );
		properx_portal_note( $entry_id, 'Cancellation sent to portal: OK (' . $result['reason'] . ')', true );

		return;
	}

	gform_update_meta( $entry_id, PROPERX_PORTAL_META_CANCEL_STATUS, 'failed' );

	if ( 'retry' === $result['status'] && isset( PROPERX_PORTAL_RETRY_DELAYS[ $attempt ] ) ) {
		$delay = PROPERX_PORTAL_RETRY_DELAYS[ $attempt ];

		properx_portal_schedule_cancellation( $entry_id, $attempt + 1, time() + $delay );
		properx_portal_note( $entry_id, sprintf( 'Cancellation sent to portal: failed: %s. Retry %d of %d in %d minutes.', $result['reason'], $attempt + 1, count( PROPERX_PORTAL_RETRY_DELAYS ), $delay / 60 ), false );

		return;
	}

	properx_portal_note( $entry_id, 'Cancellation sent to portal: failed: ' . $result['reason'] . '. No more retries; use "Resend cancellation to portal".', false );
}

// Entry screen: "Resend cancellation to portal", next to the application's resend.

add_action(
	'admin_post_properx_portal_resend_cancellation',
	function () {
		$entry_id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0;

		check_admin_referer( 'properx_portal_resend_cancellation_' . $entry_id );

		if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) ) {
			wp_die( 'You are not allowed to do this.' );
		}

		if ( properx_portal_can_send_cancellations() && gform_get_meta( $entry_id, PROPERX_PORTAL_META_CANCELED_AT ) ) {
			properx_portal_schedule_cancellation( $entry_id, 0, time() );
			properx_portal_note( $entry_id, 'Resend cancellation to portal queued.', true );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=gf_entries' ) );
		exit;
	}
);

/**
 * The cancellation's part of the entry's ProPerX Portal box.
 */
function properx_portal_entry_cancellation_box( $entry ) {
	if ( ! gform_get_meta( $entry['id'], PROPERX_PORTAL_META_CANCELED_AT ) ) {
		return;
	}

	$status = gform_get_meta( $entry['id'], PROPERX_PORTAL_META_CANCEL_STATUS );

	echo '<p>Cancellation: ' . esc_html( $status ? $status : 'not sent' ) . '</p>';

	if ( ! properx_portal_can_send_cancellations() ) {
		echo '<p>Sending cancellations is off.</p>';
	} else {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=properx_portal_resend_cancellation&entry_id=' . (int) $entry['id'] ), 'properx_portal_resend_cancellation_' . (int) $entry['id'] );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">Resend cancellation to portal</a></p>';
	}
}
