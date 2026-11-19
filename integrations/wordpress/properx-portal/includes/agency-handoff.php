<?php
/**
 * "My agency" on /apply: the short block (principal, agency name, about how many people, partner
 * code) goes to the portal, which opens a new agency enrollment and answers with a one-time link;
 * the visitor is sent there instead of a confirmation. Nothing is charged and no user is created on
 * this site (the Stripe and User Registration feeds have the condition "Who's joining? is Just me").
 *
 * The call happens while the form is checked, so a portal that cannot be reached stops the submission
 * with a message and the visitor can try again; nothing is stored on this site but the entry. Off until
 * "Send agency enrollments to the portal" is ticked.
 *
 * Fields are found by their Admin Field Label (below), so the same code works after the form changes
 * are applied by the plugin or by hand.
 */

defined( 'ABSPATH' ) || exit;

/** Admin Field Label => what it holds. */
const PROPERX_PORTAL_AGENCY_FIELDS = array(
	'joining'      => 'ppx_joining',
	'first_name'   => 'ppx_agency_first_name',
	'last_name'    => 'ppx_agency_last_name',
	'email'        => 'ppx_agency_email',
	'phone'        => 'ppx_agency_phone',
	'agency_name'  => 'ppx_agency_name',
	'headcount'    => 'ppx_agency_headcount',
	'partner_code' => 'ppx_partner_code',
);

/** Values of "Who's joining?". */
const PROPERX_PORTAL_JOINING_INDIVIDUAL = 'individual';
const PROPERX_PORTAL_JOINING_AGENCY     = 'agency';

/** Values of "About how many people?", as the portal expects them. */
const PROPERX_PORTAL_HEADCOUNTS = array( '2-9', '10-49', '50+' );

/** Used only when the portal refuses an email without its own words (the portal's AgencyHandoff::EMAIL_REFUSED_MESSAGE is the same text). */
const PROPERX_PORTAL_EMAIL_REFUSED = 'We couldn\'t start agency enrollment with this email. If you\'re already a ProPerX member, sign in to the member portal or contact us at 888-388-1720.';

const PROPERX_PORTAL_UNREACHABLE = 'We couldn\'t open the agency enrollment just now. Please try again in a few minutes. If it keeps happening, call ProPerX at 888-388-1720.';

function properx_portal_agency_handoff_enabled() {
	return (bool) properx_portal_stored_settings()['agency_handoff_enabled'] && properx_portal_is_configured();
}

/**
 * The form's field with this Admin Field Label, or null.
 *
 * @return object|null
 */
function properx_portal_field_by_label( $form, $label ) {
	foreach ( (array) rgar( $form, 'fields' ) as $field ) {
		if ( is_object( $field ) && $label === (string) ( $field->adminLabel ?? '' ) ) {
			return $field;
		}
	}

	return null;
}

/**
 * The submitted value of a field found by its Admin Field Label ('' when absent).
 */
function properx_portal_posted_value( $form, $key ) {
	$field = properx_portal_field_by_label( $form, PROPERX_PORTAL_AGENCY_FIELDS[ $key ] );

	if ( null === $field ) {
		return '';
	}

	$value = rgpost( 'input_' . $field->id );

	return is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
}

/**
 * The block as the portal expects it, with a fresh request id (each submission starts its own enrollment).
 *
 * @return array<string, string>
 */
function properx_portal_agency_payload( $form ) {
	return array(
		'request_id'   => bin2hex( random_bytes( 16 ) ),
		'first_name'   => properx_portal_posted_value( $form, 'first_name' ),
		'last_name'    => properx_portal_posted_value( $form, 'last_name' ),
		'email'        => properx_portal_posted_value( $form, 'email' ),
		'phone'        => properx_portal_posted_value( $form, 'phone' ),
		'agency_name'  => properx_portal_posted_value( $form, 'agency_name' ),
		// Optional on the form: the smallest tier is the starting point; the portal adjusts it to the people added.
		'headcount'    => in_array( properx_portal_posted_value( $form, 'headcount' ), PROPERX_PORTAL_HEADCOUNTS, true ) ? properx_portal_posted_value( $form, 'headcount' ) : '2-9',
		'partner_code' => strtoupper( properx_portal_posted_value( $form, 'partner_code' ) ),
	);
}

/**
 * One call to the portal. Never logs or notes the payload.
 *
 * @return array{status: string, url?: string, message?: string, fields?: list<string>} status: started, refused, invalid or unreachable.
 */
function properx_portal_agency_start( array $payload ) {
	$body      = wp_json_encode( $payload );
	$timestamp = (string) time();

	$response = wp_remote_post(
		properx_portal_url() . '/api/intake/agency-start',
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
		return array( 'status' => 'unreachable' );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$json = is_array( $json ) ? $json : array();

	if ( 200 === $code && 'started' === rgar( $json, 'outcome' ) && properx_portal_is_portal_link( (string) rgar( $json, 'url' ) ) ) {
		return array( 'status' => 'started', 'url' => (string) $json['url'] );
	}

	// An email that cannot start an enrollment (a portal account, or a recent cancellation): the portal's neutral words.
	if ( 200 === $code && in_array( rgar( $json, 'outcome' ), array( 'existing_member', 'blocked' ), true ) ) {
		return array( 'status' => 'refused', 'message' => (string) rgar( $json, 'message' ) );
	}

	if ( 422 === $code ) {
		return array( 'status' => 'invalid', 'fields' => array_values( array_map( 'strval', (array) rgar( $json, 'fields', array() ) ) ) );
	}

	return array( 'status' => 'unreachable' );
}

/**
 * Only a link on the configured portal is followed.
 */
function properx_portal_is_portal_link( $url ) {
	$portal = wp_parse_url( properx_portal_url() );
	$link   = wp_parse_url( $url );

	return is_array( $portal ) && is_array( $link )
		&& 'https' === ( $link['scheme'] ?? '' )
		&& strtolower( (string) ( $link['host'] ?? '' ) ) === strtolower( (string) ( $portal['host'] ?? '' ) )
		&& 0 === strpos( (string) ( $link['path'] ?? '' ), '/join/agency/start/' );
}

/** The link of this request's handoff, read by the confirmation. */
$GLOBALS['properx_portal_agency_link'] = null;

add_filter( 'gform_validation', 'properx_portal_agency_validation', 50, 2 );

/**
 * When the form is submitted with "My agency" and everything else is valid: ask the portal for the
 * enrollment. A refusal or an unreachable portal turns into a message on the form.
 */
function properx_portal_agency_validation( $result, $context = 'form-submit' ) {
	$form = rgar( $result, 'form' );

	if ( ! rgar( $result, 'is_valid' ) || 'form-submit' !== $context || (int) rgar( $form, 'id' ) !== properx_portal_form_id() || ! properx_portal_agency_handoff_enabled() ) {
		return $result;
	}

	if ( PROPERX_PORTAL_JOINING_AGENCY !== properx_portal_posted_value( $form, 'joining' ) ) {
		return $result;
	}

	$answer = properx_portal_agency_start( properx_portal_agency_payload( $form ) );

	if ( 'started' === $answer['status'] ) {
		$GLOBALS['properx_portal_agency_link'] = $answer['url'];

		return $result;
	}

	$messages = array();

	if ( 'refused' === $answer['status'] ) {
		$messages['email'] = '' !== $answer['message'] ? $answer['message'] : PROPERX_PORTAL_EMAIL_REFUSED;
	} elseif ( 'invalid' === $answer['status'] && array() !== $answer['fields'] ) {
		foreach ( $answer['fields'] as $name ) {
			if ( isset( PROPERX_PORTAL_AGENCY_FIELDS[ $name ] ) ) {
				$messages[ $name ] = 'Please check this field.';
			}
		}
	}

	if ( array() === $messages ) {
		$messages['joining'] = PROPERX_PORTAL_UNREACHABLE;
	}

	foreach ( $messages as $key => $message ) {
		$field = properx_portal_field_by_label( $form, PROPERX_PORTAL_AGENCY_FIELDS[ $key ] );

		if ( null === $field ) {
			$field = properx_portal_field_by_label( $form, PROPERX_PORTAL_AGENCY_FIELDS['joining'] );
		}

		if ( null !== $field ) {
			$field->failed_validation  = true;
			$field->validation_message = $message;
		}
	}

	$result['is_valid'] = false;
	$result['form']     = $form;

	return $result;
}

add_filter( 'gform_confirmation', 'properx_portal_agency_confirmation', 50, 4 );

/**
 * "My agency" answered by the portal: straight on to the enrollment (works for AJAX forms too).
 */
function properx_portal_agency_confirmation( $confirmation, $form, $entry, $ajax = false ) {
	$link = $GLOBALS['properx_portal_agency_link'] ?? null;

	if ( null === $link || (int) rgar( $form, 'id' ) !== properx_portal_form_id() ) {
		return $confirmation;
	}

	return array( 'redirect' => $link );
}

add_action( 'gform_after_submission', 'properx_portal_agency_note', 50, 2 );

/**
 * A note on the entry that the enrollment was opened. No link and no personal data.
 */
function properx_portal_agency_note( $entry, $form ) {
	if ( null !== ( $GLOBALS['properx_portal_agency_link'] ?? null ) && (int) rgar( $form, 'id' ) === properx_portal_form_id() ) {
		properx_portal_note( (int) $entry['id'], 'Agency enrollment opened in the portal (one-time link, nothing charged on this site).', true );
	}
}
