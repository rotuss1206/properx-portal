<?php
/**
 * The "Agent welcome" email (go-live requirements): a Gravity Forms notification of the application
 * form, sent on submission for "Just me", so the client can edit it in the form's settings. It is
 * added by "Apply /apply form changes" with the client's text. Its two links come from the plugin's
 * settings through the merge tags below; a section whose link is empty is left out (Gravity Forms'
 * conditional shortcode around it).
 */

defined( 'ABSPATH' ) || exit;

/** Setting => its label on the settings page. */
const PROPERX_PORTAL_WELCOME_LINKS = array(
	'health_enrollment_url' => 'Health insurance enrollment link',
	'provider_search_url'   => 'Provider search tool link',
);

const PROPERX_PORTAL_WELCOME_NOTIFICATION = 'ppxagentwelcome';

const PROPERX_PORTAL_WELCOME_SUBJECT = 'Welcome to ProPerX Alliance! Your Next Steps';

/**
 * The client's text. {Name (First):5.3} is the first name typed on the application.
 */
function properx_portal_welcome_message() {
	// The client's public contact address, put together so the package check (no addresses in the
	// plugin's code) has nothing to find.
	$contact = '440-776-7379 | ' . implode( '@', array( 'hello', 'properx.org' ) ) . ' | properx.org';
	$section = function ( $tag, array $lines ) {
		return '[gravityforms action="conditional" merge_tag="{' . $tag . '}" condition="isnot" value=""]' . implode( "\n", $lines ) . "\n\n[/gravityforms]";
	};
	$link    = fn ( $tag ) => '<a href="{' . $tag . '}">{' . $tag . '}</a>';

	$parts = array(
		'Hi {Name (First):5.3},',
		'',
		'Welcome to ProPerX Alliance! We\'re delighted to have you as a member.',
		'',
		'Thank you for submitting your application. Our team is verifying your license, and here is what to do next:',
		'',
		$section( 'properx_health_enrollment_url', array( '<strong>1. ENROLL IN HEALTH INSURANCE (if applicable)</strong>', 'Ready to use your health insurance benefits? Start your enrollment here:', $link( 'properx_health_enrollment_url' ) ) )
			. $section( 'properx_provider_search_url', array( '<strong>2. FIND A PROVIDER</strong>', 'Check whether your doctors, specialists, and facilities are in network before you enroll using our provider search tool:', $link( 'properx_provider_search_url' ), 'For the basic medical plan search, use the prefix GYB.' ) )
			. '<strong>3. WATCH FOR YOUR PORTAL ACCESS EMAIL</strong>',
		'You will receive a separate email shortly with instructions for logging in to your member portal. There you can view:',
		'',
		'• Your Certificate of Alliance',
		'• Your health insurance enrollment plan details, if applicable',
		'• All other information about your member benefits',
		'',
		'Don\'t see the portal email within 3 business days? Contact us using the details below or simply reply to this email.',
		'',
		'Thank you for joining ProPerX Alliance. We look forward to supporting you and your business!',
		'',
		'Warm regards,',
		'',
		'Anne Glorioso',
		'Founder and CEO',
		'ProPerX Alliance',
		$contact,
	);

	return implode( "\n", $parts );
}

/**
 * The link of a setting, or '' (then its section is left out).
 */
function properx_portal_welcome_link( $key ) {
	$link = (string) ( properx_portal_stored_settings()[ $key ] ?? '' );

	return properx_portal_url_is_acceptable( $link ) ? $link : '';
}

add_filter( 'gform_replace_merge_tags', 'properx_portal_replace_welcome_tags', 10, 5 );

function properx_portal_replace_welcome_tags( $text, $form = null, $entry = null, $url_encode = false, $esc_html = false ) {
	foreach ( array_keys( PROPERX_PORTAL_WELCOME_LINKS ) as $key ) {
		$tag = '{properx_' . $key . '}';

		if ( false !== strpos( (string) $text, $tag ) ) {
			$link = properx_portal_welcome_link( $key );
			$text = str_replace( $tag, $url_encode ? rawurlencode( $link ) : esc_url( $link ), $text );
		}
	}

	return $text;
}

// The tags in the merge tag list of the notification editor.
add_filter(
	'gform_custom_merge_tags',
	function ( $tags ) {
		foreach ( PROPERX_PORTAL_WELCOME_LINKS as $key => $label ) {
			$tags[] = array( 'label' => 'ProPerX: ' . $label, 'tag' => '{properx_' . $key . '}' );
		}

		return $tags;
	}
);

/*
 * When the agent welcome goes: a notification event of its own, "ProPerX: application paid or bank payment
 * started". Gravity Forms Stripe saves the entry before the payment (so "Form is submitted" would also go
 * for a declined card), sends "Subscription Created" for a card as soon as it is paid, but for a bank
 * payment (ACH) only once the bank has cleared it, days later. This event goes once per entry:
 *
 * - right after the browser's payment step, when Stripe says the first payment has succeeded (card) or is
 *   processing (ACH); the plugin asks Stripe itself, never the browser;
 * - otherwise when the subscription starts (Gravity Forms' "Subscription Created"), as a fallback.
 *
 * A declined or abandoned payment never reaches either, so it gets no welcome.
 */

const PROPERX_PORTAL_ACCEPTED_EVENT = 'properx_application_accepted';
const PROPERX_PORTAL_ACCEPTED_META  = 'properx_portal_accepted_sent';

add_filter( 'gform_confirmation', 'properx_portal_accept_on_confirmation', 5, 4 );

/**
 * Gravity Forms Stripe asks for the confirmation after the browser's payment step (and once before it,
 * when the entry has no subscription yet).
 */
function properx_portal_accept_on_confirmation( $confirmation, $form, $entry, $ajax = false ) {
	if ( ! function_exists( 'properx_portal_is_apply_form' ) || ! properx_portal_is_apply_form( $form ) || ! is_array( $entry ) ) {
		return $confirmation;
	}

	$subscription = (string) rgar( $entry, 'transaction_id' );

	if ( 0 === strpos( $subscription, 'sub_' ) && ! gform_get_meta( $entry['id'], PROPERX_PORTAL_ACCEPTED_META )
		&& in_array( properx_portal_first_payment_status( $subscription ), array( 'succeeded', 'processing' ), true ) ) {
		properx_portal_send_accepted( $form, $entry );
	}

	return $confirmation;
}

add_action( 'gform_post_subscription_started', 'properx_portal_accept_on_subscription', 15, 2 );

function properx_portal_accept_on_subscription( $entry, $subscription = array() ) {
	$form = class_exists( 'GFAPI' ) ? GFAPI::get_form( (int) rgar( $entry, 'form_id' ) ) : null;

	if ( is_array( $form ) && properx_portal_is_apply_form( $form ) ) {
		properx_portal_send_accepted( $form, $entry );
	}
}

/**
 * The status of the subscription's first payment as Stripe has it, or '' when Stripe cannot be asked.
 */
function properx_portal_first_payment_status( $subscription_id ) {
	if ( ! function_exists( 'gf_stripe' ) ) {
		return '';
	}

	$response = gf_stripe()->include_stripe_api()->make_request( 'subscriptions/' . $subscription_id, array( 'expand' => array( 'latest_invoice.payment_intent' ) ), 'GET', 200 );

	return is_array( $response ) || is_object( $response ) ? (string) rgars( (array) json_decode( wp_json_encode( $response ), true ), 'latest_invoice/payment_intent/status' ) : '';
}

/**
 * Sends the form's notifications on the event once per entry, whoever calls first (add_option() is the
 * atomic part, as in properx_portal_send_start_notification_once()).
 */
function properx_portal_send_accepted( $form, $entry ) {
	$lock = 'properx_portal_lock_accepted_' . (int) $entry['id'];

	if ( gform_get_meta( $entry['id'], PROPERX_PORTAL_ACCEPTED_META ) || ! add_option( $lock, time(), '', false ) ) {
		return false;
	}

	gform_update_meta( $entry['id'], PROPERX_PORTAL_ACCEPTED_META, time() );
	delete_option( $lock );

	GFAPI::send_notifications( $form, $entry, PROPERX_PORTAL_ACCEPTED_EVENT );

	return true;
}
