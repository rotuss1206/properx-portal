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
			// Before the cutoff the standard price stands struck through before today's.
			$choices[0]['text']      = 'Individual Membership · Annual <span class="ppx-plan-price">'
				. ( $founding ? '<s>' . properx_portal_money( PROPERX_PORTAL_PRICES['individual']['standard'] ) . '/yr</s> ' : '' )
				. properx_portal_money( $individual ) . '/yr</span>';
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
					$choices[ $i ]['text'] = 'Just me<span class="ppx-choice-text">Individual membership for one licensed producer.</span><span class="ppx-choice-price">'
						. ( $founding ? '<s>' . properx_portal_money( PROPERX_PORTAL_PRICES['individual']['standard'] ) . '</s> ' : '' )
						. properx_portal_money( $individual ) . ' per year</span>';
				} elseif ( PROPERX_PORTAL_JOINING_AGENCY === $choice['value'] ) {
					$choices[ $i ]['text'] = 'My agency (2 or more people)<span class="ppx-choice-text">Enroll your agency on one annual invoice.</span><span class="ppx-choice-price">From ' . properx_portal_money( $from ) . ' per person per year</span>';
				}
			}

			$field->choices = $choices;
		}
	}

	return $form;
}

// The partner code is filled from ?code= (its parameter name); links made for the live form's earlier
// "Partner Code" field use ?partner=, and still fill it.

add_filter( 'gform_field_value_code', 'properx_portal_partner_code_from_link', 10, 3 );

function properx_portal_partner_code_from_link( $value, $field = null, $name = '' ) {
	if ( '' !== (string) $value || ! is_object( $field ) || 'ppx_partner_code' !== (string) ( $field->adminLabel ?? '' ) || (int) ( $field->formId ?? 0 ) !== properx_portal_form_id() ) {
		return $value;
	}

	return isset( $_GET['partner'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['partner'] ) ) : $value; // phpcs:ignore WordPress.Security.NonceVerification -- a link's prefill.
}

// NPN: 7 to 10 digits (the theme's own check allows 1 to 10).

add_filter( 'gform_field_validation', 'properx_portal_validate_npn', 20, 4 );

/** The NPN's Admin Field Label: ours, or the one live already gave it (the helper keeps that). */
const PROPERX_PORTAL_NPN_LABELS = array( 'ppx_npn', 'agent_npn' );

function properx_portal_validate_npn( $result, $value, $form, $field ) {
	if ( ! properx_portal_is_apply_form( $form ) || ! in_array( (string) ( $field->adminLabel ?? '' ), PROPERX_PORTAL_NPN_LABELS, true ) || '' === trim( (string) $value ) ) {
		return $result;
	}

	if ( ! preg_match( '/^\d{7,10}$/', trim( (string) $value ) ) ) {
		$result['is_valid'] = false;
		$result['message']  = 'Enter your NPN: 7 to 10 digits, numbers only.';
	}

	return $result;
}

// Styles and the small script (order summary, button text): only where the form is shown.

// The form's look on the page only (the stored form and the entries keep their own labels).

add_filter( 'gform_pre_render', 'properx_portal_apply_layout', 20 );

/**
 * Descriptions and errors below the inputs, so inputs side by side line up; an "Optional" description becomes the
 * "(optional)" marker next to the label, as the "(required)" one.
 */
function properx_portal_apply_layout( $form ) {
	if ( ! properx_portal_is_apply_form( $form ) ) {
		return $form;
	}

	$form['descriptionPlacement'] = 'below';
	// Errors under the inputs as well, in red, the same for every field (apply.css).
	$form['validationPlacement'] = 'below';

	foreach ( $form['fields'] as $field ) {
		if ( is_object( $field ) && 'optional' === strtolower( trim( (string) $field->description ) ) ) {
			$field->description = '';
			$field->label      .= ' (optional)';
		}
	}

	return $form;
}

add_filter( 'gform_field_css_class', 'properx_portal_apply_field_class', 10, 3 );

/**
 * Classes the stylesheet works with, by Admin Field Label, so live and the copy match whatever the ids.
 */
function properx_portal_apply_field_class( $classes, $field, $form ) {
	if ( ! properx_portal_is_apply_form( $form ) || ! is_object( $field ) ) {
		return $classes;
	}

	$by_label = array(
		'ppx_payment_method' => 'ppx-payment',
		'ppx_plan'           => 'ppx-plan',
		'ppx_license_copy'   => 'ppx-license-copy',
	);
	$label    = (string) ( $field->adminLabel ?? '' );

	if ( isset( $by_label[ $label ] ) ) {
		$classes .= ' ' . $by_label[ $label ];
	}

	// An address field that asks only for the state ("Resident license state"): the same width on any page.
	if ( 'address' === $field->type && is_array( $field->inputs ) ) {
		$shown = array_values( array_filter( $field->inputs, fn ( $input ) => empty( $input['isHidden'] ) ) );

		if ( 1 === count( $shown ) && str_ends_with( (string) $shown[0]['id'], '.4' ) ) {
			$classes .= ' ppx-state-only';
		}
	}

	// The credit card surcharge only feeds the total and the order summary: never shown, also when
	// conditional logic shows its field again.
	if ( 'product' === $field->type && 'calculation' === $field->inputType ) {
		$classes .= ' ppx-hidden-product';
	}

	return $classes;
}

add_filter( 'gform_field_content', 'properx_portal_apply_field_content', 10, 5 );

/**
 * "(optional)" in a label becomes a marker like "(Required)"; the license copy gets a control that
 * looks like the other inputs (the file input stays, visually hidden, focusable and labelled).
 */
function properx_portal_apply_field_content( $content, $field, $value, $entry_id, $form_id ) {
	// The page only: not the form editor or an entry's screen. Not is_admin() either: after a failed
	// submission Gravity Forms Stripe draws the form again through admin-ajax.php, where is_admin() is true.
	if ( (int) $form_id !== properx_portal_form_id() || ! is_object( $field ) || properx_portal_is_admin_screen() ) {
		return $content;
	}

	$content = preg_replace(
		'#(<span class=[\'"]gform-field-label__text[\'"]>)(.*?)\s*\(optional\)(</span>)#i',
		'$1$2$3<span class="ppx-optional">(Optional)</span>',
		$content,
		1
	);

	if ( 'fileupload' === $field->type && PROPERX_PORTAL_LICENSE_COPY_LABEL === (string) ( $field->adminLabel ?? '' ) ) {
		$input_id = 'input_' . (int) $form_id . '_' . (int) $field->id;
		$content  = preg_replace(
			'#(<input[^>]*\bid=[\'"]' . preg_quote( $input_id, '#' ) . '[\'"][^>]*type=[\'"]file[\'"][^>]*>|<input[^>]*type=[\'"]file[\'"][^>]*\bid=[\'"]' . preg_quote( $input_id, '#' ) . '[\'"][^>]*>)#',
			'<span class="ppx-file" data-ppx-file data-max-bytes="' . (int) ( $field->maxFileSize ? $field->maxFileSize : 10 ) * 1048576 . '" data-types="' . esc_attr( (string) $field->allowedExtensions ) . '">$1'
				. '<label class="ppx-file__box" for="' . esc_attr( $input_id ) . '" aria-hidden="true"><span class="ppx-file__button">Choose file</span><span class="ppx-file__name" data-ppx-file-name>No file chosen</span></label>'
				. '<span class="ppx-file__error" data-ppx-file-error role="alert"></span></span>',
			$content,
			1
		);
	}

	return $content;
}

// No "X of N max characters" counters on this form; the inputs keep their maximum length.
add_filter( 'gform_counter_script', 'properx_portal_apply_counter_script', 10, 2 );

function properx_portal_apply_counter_script( $script, $form_id ) {
	return (int) $form_id === properx_portal_form_id() ? '' : $script;
}

/**
 * The Payment Element offers what the form's "Payment" cards name: a card (credit or debit, Apple Pay and
 * Google Pay included) or a bank account (ACH), one at a time, as the chosen card says (apply.js switches it
 * in the browser; the payment is made with the same type). An explicit list also replaces the Stripe
 * account's automatic methods; Link is turned off in the browser (apply.js).
 */
add_filter( 'gform_stripe_payment_element_payment_methods', 'properx_portal_apply_payment_methods', 20, 3 );

function properx_portal_apply_payment_methods( $methods, $feed = null, $form = null ) {
	if ( ! properx_portal_is_apply_form( $form ) ) {
		return $methods;
	}

	// At payment: the chosen card. On the page, before any choice reaches the server: both, the browser
	// narrows it to the chosen one.
	$field  = function_exists( 'properx_portal_field_by_label' ) ? properx_portal_field_by_label( $form, 'ppx_payment_method' ) : null;
	$chosen = null === $field ? '' : (string) rgpost( 'input_' . $field->id );

	if ( 'ach' === $chosen ) {
		return array( 'us_bank_account' );
	}

	return in_array( $chosen, array( 'credit', 'debit' ), true ) ? array( 'card' ) : PROPERX_PORTAL_PAYMENT_METHODS;
}

const PROPERX_PORTAL_PAYMENT_METHODS = array( 'card', 'us_bank_account' );

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
		$events[ PROPERX_PORTAL_ACCEPTED_EVENT ] = 'ProPerX: application paid or bank payment started';
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

/**
 * Gravity Forms' own screens (the form editor, an entry), where the form is shown as Gravity Forms draws
 * it. A form drawn through admin-ajax.php for the page (a failed submission) is not one of them.
 */
function properx_portal_is_admin_screen() {
	if ( class_exists( 'GFCommon' ) && ( GFCommon::is_form_editor() || GFCommon::is_entry_detail() ) ) {
		return true;
	}

	return is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() );
}
