<?php
/**
 * The application form (/apply) with "Who's joining?": prices that switch from founding to standard at
 * the cutoff, NPN 7-10 digits, the order summary and button text, and a check after payment that the
 * card type the person declared (credit / debit / bank) is the one Stripe charged.
 *
 * Fields are found by their Admin Field Label (ppx_*), set by "Apply /apply form changes" or by hand
 * (docs/wordpress/changes.md, "Single entry /apply"). Prices live only here; the plugin's tests compare
 * the agency rates with the portal's config/agency.php.
 */

defined( 'ABSPATH' ) || exit;

/** Founding rates for payments before this moment (America/New_York), as in the portal. */
const PROPERX_PORTAL_FOUNDING_UNTIL = '2027-01-01 00:00:00';
const PROPERX_PORTAL_PRICE_TIMEZONE = 'America/New_York';

/** Dollars per person per year: founding, then standard. */
const PROPERX_PORTAL_PRICES = array(
	'individual'        => array( 'label' => 'Individual membership', 'people' => '', 'founding' => 169, 'standard' => 199 ),
	'agency'            => array( 'label' => 'Agency', 'people' => '2–9 people', 'founding' => 159, 'standard' => 179 ),
	'agency_plus'       => array( 'label' => 'Agency Plus', 'people' => '10–49 people', 'founding' => 149, 'standard' => 159 ),
	'agency_enterprise' => array( 'label' => 'Agency Enterprise', 'people' => '50+ people', 'founding' => 129, 'standard' => 139 ),
);

/** Credit cards only, in percent. */
const PROPERX_PORTAL_SURCHARGE_PERCENT = 2.9;

/** Values of the Payment field (ppx_payment_method). Older entries hold the earlier texts. */
const PROPERX_PORTAL_PAYMENT_KINDS = array(
	'credit'                             => 'credit',
	'debit'                              => 'debit',
	'ach'                                => 'ach',
	'Credit Card (3% surcharge applies)' => 'credit',
	'Debit Card'                         => 'debit',
	'ACH Direct Debit'                   => 'ach',
);

const PROPERX_PORTAL_MISMATCH_EVENT = 'properx_payment_method_mismatch';

function properx_portal_is_founding( $now = null ) {
	$cutoff = new DateTimeImmutable( PROPERX_PORTAL_FOUNDING_UNTIL, new DateTimeZone( PROPERX_PORTAL_PRICE_TIMEZONE ) );

	return ( null === $now ? time() : (int) $now ) < $cutoff->getTimestamp();
}

/**
 * Today's price of a plan, in dollars.
 */
function properx_portal_price( $plan, $now = null ) {
	return PROPERX_PORTAL_PRICES[ $plan ][ properx_portal_is_founding( $now ) ? 'founding' : 'standard' ];
}

function properx_portal_money( $dollars ) {
	return '$' . ( floor( $dollars ) == $dollars ? number_format( $dollars ) : number_format( $dollars, 2 ) );
}

/**
 * Whether a form is the application form this plugin works with.
 */
function properx_portal_is_apply_form( $form ) {
	return is_array( $form ) && (int) rgar( $form, 'id' ) === properx_portal_form_id() && properx_portal_form_id() > 0;
}

// Prices in the labels, on the page and when the entry is priced.

add_filter( 'gform_pre_render', 'properx_portal_apply_prices' );
add_filter( 'gform_pre_validation', 'properx_portal_apply_prices' );
add_filter( 'gform_pre_submission_filter', 'properx_portal_apply_prices' );
add_filter( 'gform_admin_pre_render', 'properx_portal_apply_prices' );

/**
 * The membership plan (ppx_plan) and "Who's joining?" (ppx_joining) show today's prices; the plan is
 * charged at today's price. Before the cutoff: $169 with $199 struck through; after it: $199.
 */
function properx_portal_apply_prices( $form, $now = null ) {
	if ( ! properx_portal_is_apply_form( $form ) ) {
		return $form;
	}

	$founding   = properx_portal_is_founding( $now );
	$individual = properx_portal_price( 'individual', $now );
	$from       = properx_portal_price( 'agency_enterprise', $now );

	foreach ( $form['fields'] as $field ) {
		$label = is_object( $field ) ? (string) ( $field->adminLabel ?? '' ) : (string) rgar( $field, 'adminLabel' );

		if ( 'ppx_plan' === $label && is_array( $field->choices ) && isset( $field->choices[0] ) ) {
			$choices                 = $field->choices;
			$choices[0]['text']      = 'Individual Membership · Annual <span class="ppx-plan-price">' . properx_portal_money( $individual ) . '/yr'
				. ( $founding ? ' <s>' . properx_portal_money( PROPERX_PORTAL_PRICES['individual']['standard'] ) . '/yr</s>' : '' ) . '</span>';
			$choices[0]['price']     = '$' . number_format( $individual, 2 ) . '';
			$field->choices          = $choices;
			$field->description      = $founding
				? 'Founding member rate, locked for as long as you remain a member. Billed once a year.'
				: 'Billed once a year.';
		}

		if ( 'ppx_joining' === $label && is_array( $field->choices ) ) {
			$choices = $field->choices;

			foreach ( $choices as $i => $choice ) {
				if ( PROPERX_PORTAL_JOINING_INDIVIDUAL === $choice['value'] ) {
					$choices[ $i ]['text'] = 'Just me<span class="ppx-choice-text">Individual membership for one licensed producer.</span><span class="ppx-choice-price">' . properx_portal_money( $individual ) . ' per year</span>';
				} elseif ( PROPERX_PORTAL_JOINING_AGENCY === $choice['value'] ) {
					$choices[ $i ]['text'] = 'My agency (2 or more people)<span class="ppx-choice-text">Enroll your agency on one annual invoice.</span><span class="ppx-choice-price">From ' . properx_portal_money( $from ) . ' per person per year</span>';
				}
			}

			$field->choices = $choices;
		}
	}

	return $form;
}

// NPN: 7 to 10 digits (the theme's own check allows 1 to 10).

add_filter( 'gform_field_validation', 'properx_portal_validate_npn', 20, 4 );

function properx_portal_validate_npn( $result, $value, $form, $field ) {
	if ( ! properx_portal_is_apply_form( $form ) || 'ppx_npn' !== (string) ( $field->adminLabel ?? '' ) || '' === trim( (string) $value ) ) {
		return $result;
	}

	if ( ! preg_match( '/^\d{7,10}$/', trim( (string) $value ) ) ) {
		$result['is_valid'] = false;
		$result['message']  = 'Enter your NPN: 7 to 10 digits, numbers only.';
	}

	return $result;
}

// Styles and the small script (order summary, button text): only where the form is shown.

add_action( 'gform_enqueue_scripts', 'properx_portal_apply_assets', 10, 2 );

function properx_portal_apply_assets( $form, $is_ajax = false ) {
	if ( ! properx_portal_is_apply_form( $form ) ) {
		return;
	}

	$version = properx_portal_plugin_version();
	$base    = plugins_url( 'assets/', PROPERX_PORTAL_PLUGIN_FILE );

	wp_enqueue_style( 'properx-portal-apply', $base . 'apply.css', array(), $version );
	wp_enqueue_script( 'properx-portal-apply', $base . 'apply.js', array( 'jquery', 'gform_gravityforms' ), $version, true );
	wp_add_inline_script( 'properx-portal-apply', 'window.ppxApply = ' . wp_json_encode( properx_portal_apply_script_data( $form ) ) . ';', 'before' );
}

/**
 * What the script needs: field ids by role, today's plan price, the button texts.
 *
 * @return array<string, mixed>
 */
function properx_portal_apply_script_data( $form ) {
	$ids = array();

	foreach ( array( 'ppx_joining', 'ppx_payment_method', 'ppx_order_summary' ) as $label ) {
		$field          = properx_portal_field_by_label( $form, $label );
		$ids[ $label ] = null === $field ? 0 : (int) $field->id;
	}

	return array(
		'formId'           => (int) rgar( $form, 'id' ),
		'joiningField'     => $ids['ppx_joining'],
		'paymentField'     => $ids['ppx_payment_method'],
		'summaryField'     => $ids['ppx_order_summary'],
		'agencyValue'      => PROPERX_PORTAL_JOINING_AGENCY,
		'planPrice'        => properx_portal_price( 'individual' ),
		'surchargePercent' => PROPERX_PORTAL_SURCHARGE_PERCENT,
		'payText'          => 'Pay {amount} and join ProPerX Alliance →',
		'agencyText'       => 'Continue to agency enrollment →',
	);
}

function properx_portal_plugin_version() {
	if ( ! function_exists( 'get_file_data' ) ) {
		return '1';
	}

	$data = get_file_data( PROPERX_PORTAL_PLUGIN_FILE, array( 'Version' => 'Version' ) );

	return (string) $data['Version'];
}

// The pricing table shortcode: [properx_pricing view="apply-tiers"] (the "What happens next" box).

add_shortcode( 'properx_pricing', 'properx_portal_pricing_shortcode' );

/**
 * @param array<string, string>|string $atts
 */
function properx_portal_pricing_shortcode( $atts = array(), $content = '', $tag = '', $now = null ) {
	$view     = is_array( $atts ) && isset( $atts['view'] ) ? (string) $atts['view'] : 'apply-tiers';
	$founding = properx_portal_is_founding( $now );

	if ( 'apply-tiers' !== $view ) {
		return '';
	}

	$html = '<div class="ppx-tiers">';

	foreach ( array( 'agency', 'agency_plus', 'agency_enterprise' ) as $plan ) {
		$tier  = PROPERX_PORTAL_PRICES[ $plan ];
		$html .= sprintf(
			'<div class="ppx-tier"><span class="ppx-tier-name">%s · %s</span><span class="ppx-tier-price"><strong>%s</strong> per person / year</span></div>',
			esc_html( $tier['label'] ),
			esc_html( str_replace( '–', ' to ', $tier['people'] ) ),
			esc_html( properx_portal_money( properx_portal_price( $plan, $now ) ) )
		);
	}

	$html .= '</div>';
	$html .= $founding
		? '<p class="ppx-tiers-note">Founding rates for agencies that join by December 31, 2026, locked while the agency remains a member.</p>'
		: '<p class="ppx-tiers-note">Rates per person per year, billed annually to the agency.</p>';

	return $html;
}

// After payment: did Stripe charge the kind of payment the person declared?

add_filter( 'gform_notification_events', 'properx_portal_notification_events', 10, 2 );

function properx_portal_notification_events( $events, $form = null ) {
	if ( null === $form || properx_portal_is_apply_form( $form ) ) {
		$events[ PROPERX_PORTAL_MISMATCH_EVENT ] = 'ProPerX: payment method differs from the one chosen';
	}

	return $events;
}

add_action( 'gform_post_subscription_started', 'properx_portal_check_payment_kind', 20, 2 );

/**
 * What a Stripe payment method is, in the form's terms.
 *
 * @param array<string, mixed>|null $payment_method
 * @return string credit, debit, ach or unknown.
 */
function properx_portal_payment_kind( $payment_method ) {
	if ( ! is_array( $payment_method ) ) {
		return 'unknown';
	}

	if ( 'us_bank_account' === rgar( $payment_method, 'type' ) ) {
		return 'ach';
	}

	if ( 'card' === rgar( $payment_method, 'type' ) ) {
		$funding = (string) rgars( $payment_method, 'card/funding' );

		if ( 'credit' === $funding ) {
			return 'credit';
		}

		if ( in_array( $funding, array( 'debit', 'prepaid' ), true ) ) {
			return 'debit';
		}
	}

	return 'unknown';
}

/**
 * The note for the entry. No card details: kinds only.
 *
 * @return array{mismatch: bool, note: string}
 */
function properx_portal_payment_check_result( $declared, $paid ) {
	$words = array( 'credit' => 'credit card', 'debit' => 'debit card', 'ach' => 'bank account (ACH)', 'unknown' => 'a method Stripe did not describe' );

	if ( 'unknown' === $paid ) {
		return array( 'mismatch' => false, 'note' => 'Payment method check: chose ' . $words[ $declared ] . '; Stripe did not say the card type. Check the payment in Stripe if needed.' );
	}

	if ( $declared === $paid ) {
		return array( 'mismatch' => false, 'note' => 'Payment method check: OK (' . $words[ $paid ] . ').' );
	}

	$effect = 'credit' === $paid
		? ' The 2.9% credit card surcharge was not charged.'
		: ( 'credit' === $declared ? ' The 2.9% surcharge was charged although this is not a credit card; consider refunding it.' : '' );

	return array( 'mismatch' => true, 'note' => 'Payment method check: chose ' . $words[ $declared ] . ', paid with ' . $words[ $paid ] . '.' . $effect );
}

function properx_portal_check_payment_kind( $entry, $subscription = array() ) {
	$form = GFAPI::get_form( (int) rgar( $entry, 'form_id' ) );

	if ( ! properx_portal_is_apply_form( $form ) || gform_get_meta( $entry['id'], 'properx_portal_payment_kind' ) ) {
		return;
	}

	$field    = properx_portal_field_by_label( $form, 'ppx_payment_method' );
	$declared = null === $field ? '' : ( PROPERX_PORTAL_PAYMENT_KINDS[ (string) rgar( $entry, (string) $field->id ) ] ?? '' );
	$id       = (string) rgar( $subscription, 'subscription_id', rgar( $entry, 'transaction_id' ) );

	if ( '' === $declared || 0 !== strpos( $id, 'sub_' ) || ! function_exists( 'gf_stripe' ) ) {
		return;
	}

	$api      = gf_stripe()->include_stripe_api();
	$response = $api->make_request( 'subscriptions/' . $id, array( 'expand' => array( 'latest_invoice.payment_intent.payment_method' ) ), 'GET', 200 );
	$paid     = is_array( $response ) ? properx_portal_payment_kind( rgars( $response, 'latest_invoice/payment_intent/payment_method' ) ) : 'unknown';
	$result   = properx_portal_payment_check_result( $declared, $paid );

	gform_update_meta( $entry['id'], 'properx_portal_payment_kind', $paid );
	properx_portal_note( (int) $entry['id'], $result['note'], ! $result['mismatch'] );

	if ( $result['mismatch'] ) {
		GFAPI::send_notifications( $form, $entry, PROPERX_PORTAL_MISMATCH_EVENT );
	}
}
