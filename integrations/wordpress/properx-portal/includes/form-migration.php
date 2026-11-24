<?php
/**
 * "Apply /apply form changes" (Forms > Settings > ProPerX Portal): brings the Membership Application form
 * to the single-entry layout of docs/wordpress/changes.md, "Single entry /apply", step 4.
 *
 * - Works on the form only: fields, notifications, confirmations, the button. Never entries, never feeds
 *   (the Stripe and User Registration feeds are changed by hand, step 5).
 * - Existing fields are found by id, and only if their type is the expected one (a different form, or a
 *   field changed by hand, stops everything with a reason). New fields are found by Admin Field Label.
 * - Shows every change first (dry run); applies only after confirmation, saving a backup of the form
 *   (Gravity Forms export format) that can be downloaded or restored here.
 * - Running it again changes nothing.
 *
 * Wording that waits for the client's final text sits in PROPERX_PORTAL_AGREEMENTS: change it there and
 * run the action again.
 */

defined( 'ABSPATH' ) || exit;

/** The three required agreements of the individual application (placeholders from the design). */
const PROPERX_PORTAL_AGREEMENTS = array(
	24 => array(
		'label' => 'Billing, refunds and the 24-month rule',
		'text'  => 'I understand membership is billed annually, dues are non-refundable, and a canceled member can\'t reapply for 24 months.',
	),
	32 => array(
		'label' => 'Automatic renewal',
		'text'  => 'I understand my membership renews each year unless I cancel before the renewal date.',
	),
	25 => array(
		'label' => 'Terms of Service and Privacy Policy',
		'text'  => 'I have read and agree to the ProPerX Alliance <a href="/terms-of-service/" target="_blank">Terms of Service</a> and <a href="/privacy-policy/" target="_blank">Privacy Policy</a>.',
	),
);

/** Existing fields of form 1 the changes rely on: id => type. */
const PROPERX_PORTAL_FORM_FIELDS = array(
	4  => 'html',
	5  => 'name',
	7  => 'email',
	8  => 'phone',
	9  => 'text',
	10 => 'html',
	11 => 'address',
	12 => 'html',
	14 => 'text',
	15 => 'date',
	16 => 'address',
	18 => 'html',
	28 => 'product',
	21 => 'html',
	29 => 'radio',
	30 => 'product',
	31 => 'html',
	19 => 'stripe_creditcard',
	22 => 'html',
	24 => 'consent',
	32 => 'consent',
	25 => 'consent',
	27 => 'select',
);

/** Removed fields (none since 2.3.0: "License Type(s) Held", field 17, stays and gets "Other"). */
const PROPERX_PORTAL_REMOVED_FIELDS = array();

/** The license types held, as the portal lists them (App\Support\LicenseTypes). */
const PROPERX_PORTAL_LICENSE_TYPE_COLUMNS = '3';

const PROPERX_PORTAL_LICENSE_TYPES = array( 'Life', 'Health', 'Property & Casualty', 'Annuity', 'Variable', 'Other' );

/** The confirmation after "Just me" (go-live requirements, "Revised"). */
const PROPERX_PORTAL_CONFIRMATION = '<h3>Membership Application Submitted Successfully!</h3>'
	. '<p>Thank you for applying to join ProPerX Alliance!</p>'
	. '<p>We\'ve received your application, and our team will verify your active insurance license using the license number you provided. Watch your inbox for a welcome email with your next steps, including how to log in to your member portal and start accessing your benefits.</p>'
	. '<p>Please note: Membership is exclusively available to actively licensed insurance professionals.</p>'
	. '<p>We look forward to welcoming you to ProPerX Alliance!</p>';

const PROPERX_PORTAL_OPTION_BACKUPS = 'properx_portal_form_backups';
const PROPERX_PORTAL_BACKUPS_KEPT   = 5;

/**
 * @return array<string, mixed> Show when "Who's joining?" is the given value (plus extra rules).
 */
function properx_portal_condition( $joining_id, $value, array $extra = array() ) {
	return array(
		'actionType' => 'show',
		'logicType'  => 'all',
		'rules'      => array_merge( array( array( 'fieldId' => (string) $joining_id, 'operator' => 'is', 'value' => $value ) ), $extra ),
	);
}

/**
 * The partner code's settings (at the top, for both kinds of application). A form's own partner code
 * field (live: "Partner Code" above the agreements) is given the same ones instead of a second field.
 *
 * @return array<string, mixed>
 */
function properx_portal_partner_code_settings() {
	return array(
		'label'                => 'Partner code (optional)',
		'adminLabel'           => 'ppx_partner_code',
		'description'          => 'From an MGA or FMO partner. Leave blank if you don\'t have one.',
		'maxLength'            => 50,
		'inputName'            => 'code',
		'allowsPrepopulate'    => true,
		'cssClass'             => 'ppx-partner-code',
		'layoutGridColumnSpan' => 6,
		// Partner codes are longer than five characters: no input mask.
		'inputMask'            => false,
		'inputMaskValue'       => '',
		'inputMaskIsCustom'    => false,
	);
}

/** The license number's settings; a form's own "Resident License Number" field is given the same ones. */
function properx_portal_license_number_settings() {
	return array(
		'label'                => 'License number',
		'adminLabel'           => 'ppx_license_number',
		'isRequired'           => true,
		'maxLength'            => 50,
		'description'          => 'As printed on your resident insurance license. We use it to verify your license is active.',
		'layoutGridColumnSpan' => 12,
	);
}

/**
 * The new fields, in their order, with the id each will get. Without the partner code when the form
 * has its own (it is moved to the top instead).
 *
 * @return list<array<string, mixed>>
 */
function properx_portal_new_top_fields( $next_id, $with_partner_code = true ) {
	$joining = $next_id;
	$agency  = fn ( array $field ) => $field + array( 'conditionalLogic' => properx_portal_condition( $joining, PROPERX_PORTAL_JOINING_AGENCY ) );
	$partner = array( 'type' => 'text' ) + properx_portal_partner_code_settings();
	unset( $partner['inputMask'], $partner['inputMaskValue'], $partner['inputMaskIsCustom'] );
	$fields  = array(
		array(
			'type'              => 'radio',
			'label'             => 'Who\'s joining?',
			'adminLabel'        => 'ppx_joining',
			'isRequired'        => true,
			'errorMessage'      => 'Choose who is joining.',
			'inputName'         => 'join',
			'allowsPrepopulate' => true,
			'cssClass'          => 'ppx-joining',
			'choices'           => array(
				array( 'text' => 'Just me', 'value' => PROPERX_PORTAL_JOINING_INDIVIDUAL, 'isSelected' => true, 'price' => '' ),
				array( 'text' => 'My agency (2 or more people)', 'value' => PROPERX_PORTAL_JOINING_AGENCY, 'isSelected' => false, 'price' => '' ),
			),
		),
		$with_partner_code ? $partner : null,
		$agency(
			array(
				'type'       => 'html',
				'label'      => 'Agency principal',
				'adminLabel' => 'ppx_agency_heading',
				'content'    => '<h3 class="ppx-section-title">Agency principal</h3><p class="ppx-section-text">The owner or person authorized to enroll the agency. You\'ll manage your agency\'s membership.</p>',
			)
		),
		$agency( array( 'type' => 'text', 'label' => 'First name', 'adminLabel' => 'ppx_agency_first_name', 'isRequired' => true, 'maxLength' => 100, 'layoutGridColumnSpan' => 6, 'autocompleteAttribute' => 'given-name', 'enableAutocomplete' => true ) ),
		$agency( array( 'type' => 'text', 'label' => 'Last name', 'adminLabel' => 'ppx_agency_last_name', 'isRequired' => true, 'maxLength' => 100, 'layoutGridColumnSpan' => 6, 'autocompleteAttribute' => 'family-name', 'enableAutocomplete' => true ) ),
		$agency( array( 'type' => 'email', 'label' => 'Email address', 'adminLabel' => 'ppx_agency_email', 'isRequired' => true, 'description' => 'This will be your agency admin login email.', 'layoutGridColumnSpan' => 6, 'autocompleteAttribute' => 'email', 'enableAutocomplete' => true ) ),
		$agency( array( 'type' => 'phone', 'label' => 'Phone number', 'adminLabel' => 'ppx_agency_phone', 'isRequired' => true, 'phoneFormat' => 'standard', 'layoutGridColumnSpan' => 6, 'autocompleteAttribute' => 'tel', 'enableAutocomplete' => true ) ),
		$agency( array( 'type' => 'text', 'label' => 'Agency name', 'adminLabel' => 'ppx_agency_name', 'isRequired' => true, 'maxLength' => 255, 'layoutGridColumnSpan' => 6, 'autocompleteAttribute' => 'organization', 'enableAutocomplete' => true ) ),
		$agency(
			array(
				'type'                 => 'select',
				'label'                => 'About how many people?',
				'adminLabel'           => 'ppx_agency_headcount',
				'description'          => 'A starting point. Your price adjusts to the people you add.',
				'placeholder'          => 'Select',
				'layoutGridColumnSpan' => 6,
				'choices'              => array(
					array( 'text' => '2 to 9 people', 'value' => '2-9', 'isSelected' => false, 'price' => '' ),
					array( 'text' => '10 to 49 people', 'value' => '10-49', 'isSelected' => false, 'price' => '' ),
					array( 'text' => '50 or more people', 'value' => '50+', 'isSelected' => false, 'price' => '' ),
				),
			)
		),
		$agency(
			array(
				'type'       => 'html',
				'label'      => 'What happens next',
				'adminLabel' => 'ppx_agency_next',
				'content'    => '<div class="ppx-next"><h4>What happens next</h4><ol><li>Add your agency details and your NPN.</li><li>Add your people, one at a time or by spreadsheet. Your rate updates as you go.</li><li>Review and pay by card or bank. Everyone gets an email invitation to set up their account.</li></ol>[properx_pricing view="apply-tiers"]</div><p class="ppx-continue-note">You\'ll continue in the secure member portal. Your progress is saved, so you can finish later.</p>',
			)
		),
	);

	$fields = array_values( array_filter( $fields ) );

	foreach ( $fields as $i => $field ) {
		$fields[ $i ] = $field + array( 'id' => $next_id + $i, 'isRequired' => false, 'cssClass' => '', 'description' => '' );
	}

	return $fields;
}

/**
 * The next free field id: above every field and above the form's own counter (ids of deleted fields
 * may still hold old entries' values).
 *
 * @param list<array<string, mixed>> $fields
 */
function properx_portal_next_field_id( array $fields, $floor = 0 ) {
	return max( (int) $floor, 1 + max( array_map( fn ( $field ) => (int) $field['id'], $fields ) ) );
}

/**
 * Index of the first field the test accepts, or -1.
 *
 * @param list<array<string, mixed>> $fields
 */
function properx_portal_find_field( array $fields, callable $test ) {
	foreach ( $fields as $i => $field ) {
		if ( $test( $field ) ) {
			return $i;
		}
	}

	return -1;
}

/** A field the helper does not own (no "ppx_" Admin Field Label). */
function properx_portal_is_foreign_field( array $field ) {
	return 0 !== strpos( (string) ( $field['adminLabel'] ?? '' ), 'ppx_' );
}

/** The form's own license number field (live: "Resident License Number", text), not the NPN. */
function properx_portal_is_own_license_number( array $field ) {
	$label = wp_strip_all_tags( (string) ( $field['label'] ?? '' ) );

	return 'text' === ( $field['type'] ?? '' ) && 14 !== (int) $field['id'] && properx_portal_is_foreign_field( $field )
		&& preg_match( '/\blicen[cs]e\s*(number|no\b|#)/i', $label ) && ! preg_match( '/\bNPN\b|producer/i', $label );
}

/** The form's own partner code field (live: "Partner Code", text, filled from ?partner=). */
function properx_portal_is_own_partner_code( array $field ) {
	return 'text' === ( $field['type'] ?? '' ) && properx_portal_is_foreign_field( $field )
		&& ( preg_match( '/\bpartner\s*code\b/i', wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ) ) || 'partner' === (string) ( $field['inputName'] ?? '' ) );
}

/**
 * Whether any entry has a value in the field (all entries, also spam and trash). Unknown counts as yes,
 * so a field is never deleted on a guess.
 */
function properx_portal_field_has_values( $form_id, $field_id ) {
	if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'count_entries' ) || $form_id <= 0 ) {
		return true;
	}

	$count = GFAPI::count_entries(
		(int) $form_id,
		array( 'field_filters' => array( array( 'key' => (string) $field_id, 'operator' => 'isnot', 'value' => '' ) ) )
	);

	return ! is_numeric( $count ) || (int) $count > 0;
}

/**
 * A second copy of a field the helper added before the form's own one was known: deleted while no entry
 * has a value in it, otherwise kept for those entries, hidden from the page (administrative) and renamed so
 * that nothing reads it any more.
 *
 * @param list<array<string, mixed>> $fields
 * @param list<string>               $changes
 * @return list<array<string, mixed>>
 */
function properx_portal_drop_duplicate_field( array $fields, $at, callable $has_values, array &$changes, $instead ) {
	$field = $fields[ $at ];
	$name  = wp_strip_all_tags( (string) ( $field['label'] ?? '' ) );

	if ( ! $has_values( (int) $field['id'] ) ) {
		array_splice( $fields, $at, 1 );
		$changes[] = sprintf( 'Field %d "%s" removed: %s (no entry has a value in it)', $field['id'], $name, $instead );

		return $fields;
	}

	$field['adminLabel'] = $field['adminLabel'] . '_old';
	$field['visibility'] = 'administrative';
	$field['isRequired'] = false;
	$fields[ $at ]       = $field;
	$changes[]           = sprintf( 'Field %d "%s": hidden from the page and renamed "%s" (%s; kept for the entries that have a value in it)', $field['id'], $name, $field['adminLabel'], $instead );

	return $fields;
}

/**
 * Step 1b: one partner code, at the top under "Who's joining?". A form's own partner code field (live:
 * field 34 "Partner Code" near the payment, filled from ?partner=) becomes it, keeping its id and its
 * entries; a copy the helper added earlier goes.
 *
 * @param list<array<string, mixed>> $fields
 * @param list<string>               $changes
 * @return list<array<string, mixed>>
 */
function properx_portal_plan_partner_code( array $fields, callable $has_values, array &$changes ) {
	$own = properx_portal_find_field( $fields, 'properx_portal_is_own_partner_code' );

	if ( $own < 0 ) {
		return $fields;
	}

	$added = properx_portal_index_of( $fields, 'adminLabel', 'ppx_partner_code' );

	if ( $added >= 0 ) {
		$fields = properx_portal_drop_duplicate_field( $fields, $added, $has_values, $changes, sprintf( 'the form\'s own field %d "Partner Code" is the partner code now', $fields[ $own ]['id'] ) );
		$own    = properx_portal_find_field( $fields, 'properx_portal_is_own_partner_code' );
	}

	$field = $fields[ $own ];

	foreach ( properx_portal_partner_code_settings() as $property => $value ) {
		properx_portal_set( $field, $property, $value, $changes );
	}

	array_splice( $fields, $own, 1 );
	array_splice( $fields, properx_portal_index_of( $fields, 'adminLabel', 'ppx_joining' ) + 1, 0, array( $field ) );
	$changes[] = sprintf( 'Field %d "%s": moved to the top, under "Who\'s joining?" (the only partner code; links with ?partner= still fill it)', $field['id'], $field['label'] );

	return $fields;
}

/**
 * Step 3c: the license block in one order and one layout, whatever the form had: the license number (full
 * width), the NPN beside the expiration date, the state (its select half the width, apply.css), the
 * license types, "Other" and the copy. Rows are Gravity Forms' layout groups: a field that should stand
 * alone gets a group of its own.
 *
 * @param list<array<string, mixed>> $fields
 * @param list<string>               $changes
 * @return list<array<string, mixed>>
 */
function properx_portal_plan_license_layout( array $fields, array &$changes ) {
	$keys  = array( array( 'adminLabel', 'ppx_license_number' ), array( 'id', 14 ), array( 'id', 15 ), array( 'id', 16 ), array( 'adminLabel', 'ppx_license_types' ), array( 'adminLabel', 'ppx_license_type_other' ), array( 'adminLabel', PROPERX_PORTAL_LICENSE_COPY_LABEL ) );
	$at    = array_map( fn ( $key ) => properx_portal_index_of( $fields, $key[0], $key[1] ), $keys );
	$found = array_values( array_filter( $at, fn ( $i ) => $i >= 0 ) );

	if ( count( $found ) !== count( $keys ) ) {
		return $fields;
	}

	if ( range( min( $found ), min( $found ) + count( $found ) - 1 ) !== $found ) {
		$block = array_map( fn ( $i ) => $fields[ $i ], $found );
		$start = min( $found );
		$rest  = array_values( array_diff_key( $fields, array_flip( $found ) ) );
		array_splice( $rest, $start, 0, $block );
		$fields    = $rest;
		$changes[] = 'License fields in this order: ' . implode( ', ', array_map( fn ( $field ) => sprintf( '%d "%s"', $field['id'], wp_strip_all_tags( (string) $field['label'] ) ), $block ) );
	}

	$index = fn ( $key ) => properx_portal_index_of( $fields, $key[0], $key[1] );
	$alone = function ( $i, $span ) use ( &$fields, &$changes ) {
		$field  = $fields[ $i ];
		$group  = (string) ( $field['layoutGroupId'] ?? '' );
		$shared = '' === $group || count( array_filter( $fields, fn ( $other ) => (string) ( $other['layoutGroupId'] ?? '' ) === $group ) ) > 1;

		properx_portal_set( $field, 'layoutGridColumnSpan', $span, $changes );

		if ( $shared ) {
			properx_portal_set( $field, 'layoutGroupId', substr( md5( 'ppx-row-' . $field['id'] ), 0, 8 ), $changes );
		}

		$fields[ $i ] = $field;
	};

	$alone( $index( $keys[0] ), 12 );

	// The NPN and the expiration date: one row, half and half.
	$npn   = $index( $keys[1] );
	$group = (string) ( $fields[ $npn ]['layoutGroupId'] ?? '' );
	$with  = array_filter( $fields, fn ( $other ) => '' !== $group && (string) ( $other['layoutGroupId'] ?? '' ) === $group && ! in_array( (int) $other['id'], array( 14, 15 ), true ) );

	if ( '' === $group || array() !== $with ) {
		$group = substr( md5( 'ppx-row-14' ), 0, 8 );
	}

	foreach ( array( $npn, $index( $keys[2] ) ) as $i ) {
		properx_portal_set( $fields[ $i ], 'layoutGridColumnSpan', 6, $changes );
		properx_portal_set( $fields[ $i ], 'layoutGroupId', $group, $changes );
	}

	$alone( $index( $keys[3] ), 12 );

	return $fields;
}

/**
 * @return array<string, mixed>
 */
function properx_portal_order_summary_field( $id, $joining_id ) {
	return array(
		'id'               => $id,
		'type'             => 'html',
		'label'            => 'Order summary',
		'adminLabel'       => 'ppx_order_summary',
		'isRequired'       => false,
		'cssClass'         => 'ppx-order-summary',
		'description'      => '',
		'conditionalLogic' => properx_portal_condition( $joining_id, PROPERX_PORTAL_JOINING_INDIVIDUAL ),
		'content'          => '<div class="ppx-summary" data-ppx-summary><div class="ppx-summary-row"><span>Individual membership, 1 year</span><span data-ppx-summary-plan></span></div><div class="ppx-summary-row" data-ppx-summary-surcharge-row><span>Credit card surcharge (2.9%)</span><span data-ppx-summary-surcharge></span></div><div class="ppx-summary-row ppx-summary-total"><span>Total today</span><span data-ppx-summary-total></span></div><p class="ppx-summary-note">The surcharge applies to credit cards only. Debit cards and bank payments have no surcharge.</p></div>',
	);
}

/**
 * Index of a field in the list by id, or -1.
 *
 * @param list<array<string, mixed>> $fields
 */
function properx_portal_index_of( array $fields, $key, $value ) {
	foreach ( $fields as $i => $field ) {
		if ( (string) ( $field[ $key ] ?? '' ) === (string) $value ) {
			return $i;
		}
	}

	return -1;
}

/**
 * Sets one property of a field and says what changed (nothing when it already has the value).
 *
 * @param array<string, mixed> $field
 * @param list<string>         $changes
 */
function properx_portal_set( array &$field, $property, $value, array &$changes ) {
	if ( ( $field[ $property ] ?? null ) == $value ) {
		return;
	}

	$field[ $property ] = $value;
	$changes[]          = sprintf( 'Field %d "%s": %s', $field['id'], wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ), properx_portal_describe( $property, $value ) );
}

function properx_portal_describe( $property, $value ) {
	switch ( $property ) {
		case 'conditionalLogic':
			$rules = array_map( fn ( $rule ) => 'field ' . $rule['fieldId'] . ' is "' . $rule['value'] . '"', $value['rules'] ?? array() );

			return 'conditional logic: show if ' . implode( ' and ', $rules );
		case 'choices':
			return 'choices: ' . implode( ' / ', array_map( fn ( $choice ) => $choice['value'], $value ) );
		case 'inputs':
			return 'inputs: ' . implode( ', ', array_map( fn ( $input ) => $input['id'] . ' ' . $input['label'], $value ) );
		case 'isRequired':
			return $value ? 'required' : 'not required';
		case 'content':
		case 'checkboxLabel':
		case 'description':
			return $property . ': "' . mb_substr( wp_strip_all_tags( (string) $value ), 0, 90 ) . ( mb_strlen( wp_strip_all_tags( (string) $value ) ) > 90 ? '…' : '' ) . '"';
	}

	return $property . ': "' . wp_strip_all_tags( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) . '"';
}

/**
 * The whole change, worked out on a copy of the form (arrays, as Gravity Forms exports them).
 *
 * @param array<string, mixed> $form
 * @param callable|null        $has_values Whether entries have a value in a field (by id); Gravity Forms' count by default.
 * @return array{form: array<string, mixed>, changes: list<string>, problems: list<string>}
 */
function properx_portal_plan_form_changes( array $form, $has_values = null ) {
	$changes    = array();
	$problems   = array();
	$fields     = array_values( (array) ( $form['fields'] ?? array() ) );
	$floor      = (int) ( $form['nextFieldId'] ?? 0 );
	$has_values = is_callable( $has_values ) ? $has_values : fn ( $field_id ) => properx_portal_field_has_values( (int) ( $form['id'] ?? 0 ), (int) $field_id );

	// The form must be the application form: every field the changes rely on, with its type.
	foreach ( PROPERX_PORTAL_FORM_FIELDS as $id => $type ) {
		$at = properx_portal_index_of( $fields, 'id', $id );

		if ( $at < 0 ) {
			$problems[] = sprintf( 'Field %d (%s) is missing: this is not the Membership Application form, or it was changed by hand.', $id, $type );
		} elseif ( $fields[ $at ]['type'] !== $type ) {
			$problems[] = sprintf( 'Field %d is a %s, expected a %s.', $id, $fields[ $at ]['type'], $type );
		}
	}

	if ( array() !== $problems ) {
		return array( 'form' => $form, 'changes' => array(), 'problems' => $problems );
	}

	// 1. "Who's joining?", the partner code and the "My agency" block, at the top.
	$joining_at = properx_portal_index_of( $fields, 'adminLabel', 'ppx_joining' );

	if ( $joining_at < 0 ) {
		$new    = properx_portal_new_top_fields( properx_portal_next_field_id( $fields, $floor ), properx_portal_find_field( $fields, 'properx_portal_is_own_partner_code' ) < 0 );
		$fields = array_merge( $new, $fields );

		foreach ( $new as $field ) {
			$changes[] = sprintf( 'New field %d "%s" (%s) at the top%s', $field['id'], wp_strip_all_tags( $field['label'] ), $field['type'], empty( $field['conditionalLogic'] ) ? '' : ', shown for "My agency"' );
		}
	}

	// 1b. One partner code: the form's own field (live: "Partner Code" near the payment), moved up.
	$fields = properx_portal_plan_partner_code( $fields, $has_values, $changes );

	$joining_id = (int) $fields[ properx_portal_index_of( $fields, 'adminLabel', 'ppx_joining' ) ]['id'];
	$individual = properx_portal_condition( $joining_id, PROPERX_PORTAL_JOINING_INDIVIDUAL );

	// 2. "License Type(s) Held" goes.
	foreach ( PROPERX_PORTAL_REMOVED_FIELDS as $id => $type ) {
		$at = properx_portal_index_of( $fields, 'id', $id );

		if ( $at >= 0 && $fields[ $at ]['type'] === $type ) {
			$changes[] = sprintf( 'Field %d "%s" removed (entries keep their values)', $id, wp_strip_all_tags( (string) $fields[ $at ]['label'] ) );
			array_splice( $fields, $at, 1 );
		}
	}

	// 3. The order summary, after the payment details.
	if ( properx_portal_index_of( $fields, 'adminLabel', 'ppx_order_summary' ) < 0 ) {
		$next = properx_portal_next_field_id( $fields, $floor );
		array_splice( $fields, properx_portal_index_of( $fields, 'id', 19 ) + 1, 0, array( properx_portal_order_summary_field( $next, $joining_id ) ) );
		$changes[] = sprintf( 'New field %d "Order summary" (html) after field 19, shown for "Just me"', $next );
	}

	// 3b. The license (plugin 2.3.0, go-live requirements): the license number, required, before the
	// NPN, which becomes optional; the license types with "Other" and its words; a copy of the license.
	$fields = properx_portal_plan_license_fields( $fields, $joining_id, $changes, $has_values, $floor );

	// 3c. The license block's order and rows, the same on every form.
	$fields = properx_portal_plan_license_layout( $fields, $changes );

	// 4. The existing fields: shown for "Just me" only, with their new texts.
	foreach ( $fields as $i => $field ) {
		$id = (int) $field['id'];

		if ( ! isset( PROPERX_PORTAL_FORM_FIELDS[ $id ] ) ) {
			continue;
		}

		switch ( $id ) {
			case 14:
				// Live names it "agent_npn" (something there may read it): kept; the plugin knows both.
				if ( '' === (string) ( $field['adminLabel'] ?? '' ) ) {
					properx_portal_set( $field, 'adminLabel', 'ppx_npn', $changes );
				}

				properx_portal_set( $field, 'maxLength', 10, $changes );
				properx_portal_set( $field, 'isRequired', false, $changes );
				properx_portal_set( $field, 'label', 'NPN (National Producer Number) (optional)', $changes );
				properx_portal_set( $field, 'description', 'The number NIPR assigned to your insurance license, 7 to 10 digits, if you have it.', $changes );
				break;
			case 16:
				properx_portal_set( $field, 'label', 'Resident license state', $changes );
				properx_portal_set( $field, 'isRequired', true, $changes );
				// The state's own sub-label already says where the license is held.
				properx_portal_set( $field, 'description', '', $changes );
				break;
			case 18:
				properx_portal_set( $field, 'content', str_replace( 'Membership Selection', 'Membership', (string) $field['content'] ), $changes );
				break;
			case 28:
				properx_portal_set( $field, 'adminLabel', 'ppx_plan', $changes );

				// The only plan: chosen from the start, so the total and the payment show at once.
				if ( isset( $field['choices'][0] ) && empty( $field['choices'][0]['isSelected'] ) ) {
					$choices                  = $field['choices'];
					$choices[0]['isSelected'] = true;
					properx_portal_set( $field, 'choices', $choices, $changes );
				}
				break;
			case 29:
				properx_portal_set( $field, 'adminLabel', 'ppx_payment_method', $changes );
				properx_portal_set( $field, 'label', 'Payment', $changes );
				properx_portal_set( $field, 'description', 'The 2.9% surcharge applies to credit cards only. Debit cards and bank payments have no surcharge.', $changes );
				properx_portal_set(
					$field,
					'choices',
					array(
						array( 'text' => 'Credit card <span class="ppx-choice-note">2.9% surcharge</span>', 'value' => 'credit', 'isSelected' => true, 'price' => '' ),
						array( 'text' => 'Debit card', 'value' => 'debit', 'isSelected' => false, 'price' => '' ),
						array( 'text' => 'Bank account (ACH) <span class="ppx-choice-note">No surcharge</span>', 'value' => 'ach', 'isSelected' => false, 'price' => '' ),
					),
					$changes
				);
				break;
			case 30:
				properx_portal_set( $field, 'label', 'Credit card surcharge (2.9%)', $changes );
				properx_portal_set( $field, 'calculationFormula', '{Membership:28} * 0.029', $changes );
				properx_portal_set( $field, 'calculationRounding', '2', $changes );
				break;
			case 24:
			case 32:
			case 25:
				properx_portal_set( $field, 'label', PROPERX_PORTAL_AGREEMENTS[ $id ]['label'], $changes );
				properx_portal_set( $field, 'checkboxLabel', PROPERX_PORTAL_AGREEMENTS[ $id ]['text'], $changes );
				properx_portal_set( $field, 'isRequired', true, $changes );
				break;
		}

		$extra = array();

		if ( 30 === $id ) {
			$extra[] = array( 'fieldId' => '29', 'operator' => 'is', 'value' => 'credit' );
		} elseif ( 31 === $id ) {
			$extra[] = array( 'fieldId' => '29', 'operator' => 'is', 'value' => 'ach' );
		}

		properx_portal_set( $field, 'conditionalLogic', properx_portal_condition( $joining_id, PROPERX_PORTAL_JOINING_INDIVIDUAL, $extra ), $changes );

		$fields[ $i ] = $field;
	}

	$form['fields'] = $fields;

	// Gravity Forms' own counter for the next field: above every field, so a field added later in the form
	// editor never takes the id of one added here.
	$next_field_id = properx_portal_next_field_id( $fields );

	if ( (int) ( $form['nextFieldId'] ?? 0 ) < $next_field_id ) {
		$form['nextFieldId'] = $next_field_id;
		$changes[]           = sprintf( 'Next field id: %d (above every field)', $next_field_id );
	}

	// 5. Emails about an individual application only go for "Just me".
	foreach ( (array) ( $form['notifications'] ?? array() ) as $key => $notification ) {
		if ( 'ppxpaymentmismatch' !== $key && ( $notification['conditionalLogic'] ?? null ) != $individual ) {
			$form['notifications'][ $key ]['conditionalLogic'] = $individual;
			$changes[] = sprintf( 'Notification "%s": send only for "Just me"', $notification['name'] );
		}
	}

	// 5b. Staff hear when Stripe charged another kind of payment than the one chosen (same recipients as
	// the staff email about a new application). Entry number and link only.
	if ( ! isset( $form['notifications']['ppxpaymentmismatch'] ) ) {
		$staff = null;

		foreach ( (array) ( $form['notifications'] ?? array() ) as $notification ) {
			if ( 'form_submission' === ( $notification['event'] ?? '' ) && 'email' === ( $notification['toType'] ?? '' ) ) {
				$staff = $notification;
				break;
			}
		}

		if ( null !== $staff ) {
			$form['notifications']['ppxpaymentmismatch'] = array(
				'id'       => 'ppxpaymentmismatch',
				'name'     => 'Payment method differs (staff)',
				'isActive' => true,
				'event'    => PROPERX_PORTAL_MISMATCH_EVENT,
				'toType'   => 'email',
				'to'       => $staff['to'],
				'from'     => $staff['from'] ?? '{admin_email}',
				'fromName' => $staff['fromName'] ?? '',
				'subject'  => 'Application {entry_id}: payment method differs from the one chosen',
				'message'  => 'Stripe charged application {entry_id} with another kind of payment than the one chosen on the form (credit card, debit card or bank account), so the 2.9% credit card surcharge may be wrong. The entry\'s notes say which. Open the entry: {entry_url}',
				'disableAutoformat' => false,
			);
			$changes[] = 'Notification "Payment method differs (staff)": to the staff email\'s recipients when Stripe charged another kind of payment than the one chosen';
		}
	}

	// 5c. The agent welcome (go-live requirements): to the applicant once the payment is made (card) or
	// started (ACH), on the plugin's own event (includes/welcome-email.php), from the sender of the
	// applicant's existing email. Added once; afterwards it is the client's to edit.
	if ( ! isset( $form['notifications'][ PROPERX_PORTAL_WELCOME_NOTIFICATION ] ) ) {
		$sender = array( 'from' => '{admin_email}', 'fromName' => 'ProPerX Alliance' );

		foreach ( (array) ( $form['notifications'] ?? array() ) as $notification ) {
			if ( 'field' === ( $notification['toType'] ?? '' ) && ! empty( $notification['from'] ) ) {
				$sender = array( 'from' => $notification['from'], 'fromName' => (string) ( $notification['fromName'] ?? '' ) );
				break;
			}
		}

		$form['notifications'][ PROPERX_PORTAL_WELCOME_NOTIFICATION ] = array(
			'id'                => PROPERX_PORTAL_WELCOME_NOTIFICATION,
			'name'              => 'Agent welcome',
			'isActive'          => true,
			'event'             => PROPERX_PORTAL_ACCEPTED_EVENT,
			'toType'            => 'field',
			'to'                => '7',
			'from'              => $sender['from'],
			'fromName'          => $sender['fromName'],
			'subject'           => PROPERX_PORTAL_WELCOME_SUBJECT,
			'message'           => properx_portal_welcome_message(),
			'conditionalLogic'  => $individual,
			'disableAutoformat' => false,
		);
		$changes[] = 'Notification "Agent welcome": the client\'s welcome to the applicant once the payment is made (card) or started (ACH), "Just me" only; links from the plugin\'s settings';
	}

	// 5c2. Emails only once paid (go-live decisions): the agent welcome on the plugin's event (an earlier
	// version of these changes put it on "Form is submitted"); the staff email on "Subscription Created";
	// "User Pending Confirmation" off, the agent welcome replaces it. Nothing else depends on it.
	foreach ( (array) ( $form['notifications'] ?? array() ) as $key => $notification ) {
		$event = (string) ( $notification['event'] ?? '' );

		if ( PROPERX_PORTAL_WELCOME_NOTIFICATION === $key ) {
			if ( 'form_submission' === $event ) {
				$form['notifications'][ $key ]['event'] = PROPERX_PORTAL_ACCEPTED_EVENT;
				$changes[] = 'Notification "Agent welcome": event "ProPerX: application paid or bank payment started" (not on submission)';
			}

			continue;
		}

		if ( 'ppxpaymentmismatch' === $key ) {
			continue;
		}

		if ( 'email' === ( $notification['toType'] ?? '' ) && 'form_submission' === $event ) {
			$form['notifications'][ $key ]['event'] = 'create_subscription';
			$changes[] = sprintf( 'Notification "%s": event "Subscription Created" (paid applications only)', $notification['name'] );
		} elseif ( 'field' === ( $notification['toType'] ?? '' ) && ( ! isset( $notification['isActive'] ) || ! empty( $notification['isActive'] ) ) ) {
			$form['notifications'][ $key ]['isActive'] = false;
			$changes[] = sprintf( 'Notification "%s": inactive (the agent welcome replaces it)', $notification['name'] );
		}
	}

	// 5d. The confirmation after "Just me": the client's revised wording.
	foreach ( (array) ( $form['confirmations'] ?? array() ) as $key => $confirmation ) {
		if ( ! empty( $confirmation['isDefault'] ) && ( 'message' !== ( $confirmation['type'] ?? '' ) || PROPERX_PORTAL_CONFIRMATION !== ( $confirmation['message'] ?? '' ) ) ) {
			$form['confirmations'][ $key ]['type']    = 'message';
			$form['confirmations'][ $key ]['message'] = PROPERX_PORTAL_CONFIRMATION;
			$changes[] = sprintf( 'Confirmation "%s": the client\'s revised text ("Membership Application Submitted Successfully! …")', $confirmation['name'] );
		}
	}

	// 6. "My agency" while the handoff is off: its own confirmation.
	$fallback = array(
		'id'                => 'ppxagencyfallback',
		'name'              => 'My agency (portal handoff off)',
		'isDefault'         => false,
		'isActive'          => true,
		'type'              => 'message',
		'message'           => 'Thank you. ProPerX will contact you shortly to enroll your agency. Questions? Call 888-388-1720.',
		'conditionalLogic'  => properx_portal_condition( $joining_id, PROPERX_PORTAL_JOINING_AGENCY ),
		'disableAutoformat' => false,
		'url'               => '',
		'pageId'            => '',
		'queryString'       => '',
	);

	if ( ( $form['confirmations']['ppxagencyfallback'] ?? null ) != $fallback ) {
		$form['confirmations']['ppxagencyfallback'] = $fallback;
		$changes[] = 'Confirmation "My agency (portal handoff off)": shown for "My agency" when the portal is not used';
	}

	// 7. The button and the form's class.
	if ( 'Join ProPerX Alliance' !== ( $form['button']['text'] ?? '' ) ) {
		$form['button']['text'] = 'Join ProPerX Alliance';
		$changes[] = 'Button text: "Join ProPerX Alliance" (the page shows the amount, or "Continue to agency enrollment")';
	}

	$classes = preg_split( '/\s+/', trim( (string) ( $form['cssClass'] ?? '' ) ) );

	if ( ! in_array( 'ppx-apply', $classes, true ) ) {
		$form['cssClass'] = trim( implode( ' ', array_filter( $classes ) ) . ' ppx-apply' );
		$changes[] = 'Form CSS class: add "ppx-apply"';
	}

	return array( 'form' => $form, 'changes' => $changes, 'problems' => array() );
}

/**
 * The license fields of "Just me" (step 3b): new ones go in by Admin Field Label, field 17 (the
 * live form's "License Type(s) Held") is kept and gets "Other".
 *
 * @param list<array<string, mixed>> $fields
 * @param list<string>               $changes
 * @return list<array<string, mixed>>
 */
function properx_portal_plan_license_fields( array $fields, $joining_id, array &$changes, $has_values = null, $floor = 0 ) {
	$individual = properx_portal_condition( $joining_id, PROPERX_PORTAL_JOINING_INDIVIDUAL );
	$has_values = is_callable( $has_values ) ? $has_values : fn ( $field_id ) => true;
	$next_id    = function () use ( &$fields, $floor ) {
		return properx_portal_next_field_id( $fields, $floor );
	};
	$insert     = function ( array $field, $after_id, $where ) use ( &$fields, &$changes ) {
		$at = properx_portal_index_of( $fields, 'id', $after_id );
		array_splice( $fields, $at < 0 ? count( $fields ) : $at + 1, 0, array( $field ) );
		$changes[] = sprintf( 'New field %d "%s" (%s) %s, shown for "Just me"', $field['id'], $field['label'], $field['type'], $where );
	};

	// The license number, required, right before the NPN: the form's own field when it has one (live:
	// "Resident License Number"), otherwise a new one. A copy the helper added before goes.
	$own = properx_portal_find_field( $fields, 'properx_portal_is_own_license_number' );

	if ( $own >= 0 ) {
		$added = properx_portal_index_of( $fields, 'adminLabel', 'ppx_license_number' );

		if ( $added >= 0 ) {
			$fields = properx_portal_drop_duplicate_field( $fields, $added, $has_values, $changes, sprintf( 'the form\'s own field %d "%s" is the license number now', $fields[ $own ]['id'], wp_strip_all_tags( (string) $fields[ $own ]['label'] ) ) );
			$own    = properx_portal_find_field( $fields, 'properx_portal_is_own_license_number' );
		}

		$field = $fields[ $own ];

		foreach ( properx_portal_license_number_settings() + array( 'conditionalLogic' => $individual ) as $property => $value ) {
			properx_portal_set( $field, $property, $value, $changes );
		}

		$fields[ $own ] = $field;
	} elseif ( properx_portal_index_of( $fields, 'adminLabel', 'ppx_license_number' ) < 0 ) {
		$id = $next_id();
		array_splice(
			$fields,
			properx_portal_index_of( $fields, 'id', 14 ),
			0,
			array( array( 'id' => $id, 'type' => 'text', 'cssClass' => '', 'conditionalLogic' => $individual ) + properx_portal_license_number_settings() )
		);
		$changes[] = sprintf( 'New field %d "License number" (text, required) before field 14, shown for "Just me"', $id );
	}

	// The license types: field 17 when the form still has it, otherwise a new one after field 16.
	$types_at = properx_portal_index_of( $fields, 'adminLabel', 'ppx_license_types' );

	if ( $types_at < 0 ) {
		$types_at = properx_portal_index_of( $fields, 'id', 17 );

		if ( $types_at >= 0 && 'checkbox' === $fields[ $types_at ]['type'] ) {
			properx_portal_set( $fields[ $types_at ], 'adminLabel', 'ppx_license_types', $changes );
		} else {
			$insert(
				array(
					'id'               => $next_id(),
					'type'             => 'checkbox',
					'label'            => 'License type(s) held',
					'adminLabel'       => 'ppx_license_types',
					'isRequired'       => true,
					'cssClass'         => '',
					'description'      => '',
					'choices'          => array(),
					'inputs'           => array(),
					'conditionalLogic' => $individual,
				),
				16,
				'after field 16'
			);
			$types_at = properx_portal_index_of( $fields, 'adminLabel', 'ppx_license_types' );
		}
	}

	$types = $fields[ $types_at ];
	$used  = array();

	foreach ( (array) ( $types['inputs'] ?? array() ) as $input ) {
		$used[ (string) $input['label'] ] = (string) $input['id'];
	}

	$choices = array();
	$inputs  = array();
	$number  = 1;

	foreach ( PROPERX_PORTAL_LICENSE_TYPES as $type ) {
		// Existing inputs keep their ids (old entries' values); new ones take the next free number
		// (Gravity Forms skips multiples of 10).
		if ( ! isset( $used[ $type ] ) ) {
			while ( in_array( $types['id'] . '.' . $number, $used, true ) || 0 === $number % 10 ) {
				++$number;
			}

			$used[ $type ] = $types['id'] . '.' . $number;
		}

		$choices[] = array( 'text' => $type, 'value' => $type, 'isSelected' => false, 'price' => '' );
		$inputs[]  = array( 'id' => $used[ $type ], 'label' => $type, 'name' => '' );
	}

	properx_portal_set( $types, 'label', 'License type(s) held', $changes );
	properx_portal_set( $types, 'isRequired', true, $changes );
	properx_portal_set( $types, 'choices', $choices, $changes );
	properx_portal_set( $types, 'inputs', $inputs, $changes );
	properx_portal_set( $types, 'conditionalLogic', $individual, $changes );
	// The same look whether the field is the live form's 17 or a new one: three columns, "Other" closing
	// the second row, across the full width.
	properx_portal_set( $types, 'enableDisplayInColumns', true, $changes );
	properx_portal_set( $types, 'displayColumns', PROPERX_PORTAL_LICENSE_TYPE_COLUMNS, $changes );
	properx_portal_set( $types, 'displayAlignment', 'horizontal', $changes );
	properx_portal_set( $types, 'layoutGridColumnSpan', 12, $changes );
	$fields[ $types_at ] = $types;

	// "Other": its words, shown when "Other" is ticked.
	if ( properx_portal_index_of( $fields, 'adminLabel', 'ppx_license_type_other' ) < 0 ) {
		$insert(
			array(
				'id'               => $next_id(),
				'type'             => 'text',
				'label'            => 'Other license type',
				'adminLabel'       => 'ppx_license_type_other',
				'isRequired'       => true,
				'maxLength'        => 100,
				'cssClass'         => '',
				'description'      => '',
				'conditionalLogic' => properx_portal_condition( $joining_id, PROPERX_PORTAL_JOINING_INDIVIDUAL, array( array( 'fieldId' => (string) $types['id'], 'operator' => 'is', 'value' => 'Other' ) ) ),
			),
			$types['id'],
			'after the license types (when "Other" is ticked)'
		);
	}

	// A copy of the license, kept encrypted until the portal has it (includes/license-copy.php).
	if ( properx_portal_index_of( $fields, 'adminLabel', PROPERX_PORTAL_LICENSE_COPY_LABEL ) < 0 ) {
		$insert(
			array(
				'id'                => $next_id(),
				'type'              => 'fileupload',
				'label'             => 'Copy of your license',
				'adminLabel'        => PROPERX_PORTAL_LICENSE_COPY_LABEL,
				'isRequired'        => true,
				'allowedExtensions' => 'pdf,jpg,jpeg,png',
				'maxFileSize'       => 10,
				'multipleFiles'     => false,
				'maxFiles'          => '',
				'cssClass'          => '',
				'description'       => 'A photo or scan of your current resident insurance license: PDF, JPG or PNG, up to 10 MB. It speeds up the verification of your license.',
				'conditionalLogic'  => $individual,
			),
			(int) $fields[ properx_portal_index_of( $fields, 'adminLabel', 'ppx_license_type_other' ) ]['id'],
			'after "Other license type" (PDF, JPG, PNG, up to 10 MB)'
		);
	}

	return $fields;
}

/**
 * The form as Gravity Forms exports it (Forms > Import/Export), for a backup.
 */
function properx_portal_form_export_json( $form ) {
	$export = class_exists( 'GFExport' ) ? GFExport::prepare_forms_for_export( array( $form ) ) : array( $form );

	$export['version'] = class_exists( 'GFForms' ) ? GFForms::$version : '';

	return wp_json_encode( $export );
}

/**
 * Keeps a backup in the database (no file on the server), the newest first.
 *
 * @return string Its key.
 */
function properx_portal_store_backup( $form ) {
	$backups = get_option( PROPERX_PORTAL_OPTION_BACKUPS, array() );
	$backups = is_array( $backups ) ? $backups : array();
	$key     = gmdate( 'Ymd-His' ) . '-' . (int) rgar( $form, 'id' );

	$backups = array( $key => array( 'form_id' => (int) rgar( $form, 'id' ), 'at' => time(), 'json' => properx_portal_form_export_json( $form ) ) ) + $backups;

	update_option( PROPERX_PORTAL_OPTION_BACKUPS, array_slice( $backups, 0, PROPERX_PORTAL_BACKUPS_KEPT, true ), false );

	return $key;
}

/**
 * The form as arrays (Gravity Forms field objects become plain arrays).
 *
 * @return array<string, mixed>|null
 */
function properx_portal_form_as_array( $form_id ) {
	$form = GFAPI::get_form( (int) $form_id );

	return is_array( $form ) ? json_decode( wp_json_encode( $form ), true ) : null;
}

/**
 * The admin action: preview, or apply after confirmation.
 *
 * @return array{form_id: int, changes: list<string>, problems: list<string>, applied: bool, backup: string, message: string}
 */
function properx_portal_handle_form_migration_post() {
	$outcome = array( 'form_id' => properx_portal_form_id(), 'changes' => array(), 'problems' => array(), 'applied' => false, 'backup' => '', 'message' => '' );

	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['properx_portal_form_migration'] ) ) {
		return $outcome;
	}

	check_admin_referer( 'properx_portal_form_migration' );

	$form_id            = absint( $_POST['form_id'] ?? 0 );
	$outcome['form_id'] = $form_id;
	$form               = properx_portal_form_as_array( $form_id );

	if ( null === $form ) {
		$outcome['problems'][] = sprintf( 'Form %d does not exist.', $form_id );

		return $outcome;
	}

	$plan = properx_portal_plan_form_changes( $form );

	$outcome['changes']  = $plan['changes'];
	$outcome['problems'] = $plan['problems'];

	if ( 'apply' !== ( $_POST['properx_portal_form_migration'] ?? '' ) || array() !== $plan['problems'] ) {
		return $outcome;
	}

	if ( empty( $_POST['confirm'] ) ) {
		$outcome['message'] = 'Nothing changed: tick the confirmation first.';

		return $outcome;
	}

	if ( array() === $plan['changes'] ) {
		$outcome['message'] = 'Nothing to change: the form is already up to date.';

		return $outcome;
	}

	$outcome['backup'] = properx_portal_store_backup( $form );
	$result            = GFAPI::update_form( $plan['form'], $form_id );

	if ( is_wp_error( $result ) ) {
		$outcome['problems'][] = 'The form could not be saved: ' . $result->get_error_message() . ' Nothing was changed; the backup is below.';

		return $outcome;
	}

	$outcome['applied'] = true;
	$outcome['message'] = sprintf( 'Applied %d change(s) to form %d. A backup of the form as it was is listed below.', count( $plan['changes'] ), $form_id );

	return $outcome;
}

// Download or restore a backup (administrators only, with a nonce).

add_action( 'admin_post_properx_portal_form_backup', 'properx_portal_form_backup_action' );

function properx_portal_form_backup_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Only an administrator can do this.' );
	}

	$key = sanitize_key( wp_unslash( $_REQUEST['backup'] ?? '' ) );
	check_admin_referer( 'properx_portal_form_backup_' . $key );

	$backups = get_option( PROPERX_PORTAL_OPTION_BACKUPS, array() );
	$backup  = is_array( $backups ) ? ( $backups[ $key ] ?? null ) : null;

	if ( null === $backup ) {
		wp_die( 'This backup is no longer kept.' );
	}

	if ( 'restore' === ( $_REQUEST['do'] ?? '' ) ) {
		$export = json_decode( $backup['json'], true );
		$result = is_array( $export ) && isset( $export[0] ) ? GFAPI::update_form( $export[0], (int) $backup['form_id'] ) : new WP_Error( 'bad_backup', 'The backup cannot be read.' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'gf_settings', 'subview' => 'properx_portal', 'ppx_restored' => is_wp_error( $result ) ? '0' : '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="gravityforms-form-' . (int) $backup['form_id'] . '-' . $key . '.json"' );
	echo $backup['json']; // phpcs:ignore WordPress.Security.EscapeOutput -- a JSON download.
	exit;
}

/**
 * The section on the settings page.
 */
function properx_portal_print_form_migration( array $outcome ) {
	$forms = class_exists( 'GFAPI' ) ? GFAPI::get_forms() : array();

	echo '<h3>Application form changes</h3>';
	echo '<p class="ppx-hint">Brings the Membership Application form to the single-entry layout ("Who\'s joining?", the "My agency" block, the new texts). Shows every change first. Never touches entries or the Stripe and User Registration feeds: change those by hand (changes.md, "Single entry /apply", step 5). Running it again changes nothing.</p>';

	if ( isset( $_GET['ppx_restored'] ) ) {
		echo '1' === $_GET['ppx_restored']
			? '<div class="alert success inline" role="status"><p>The form was restored from the backup.</p></div>'
			: '<div class="alert error inline" role="alert"><p>The backup could not be restored.</p></div>';
	}

	foreach ( $outcome['problems'] as $problem ) {
		echo '<div class="alert error inline" role="alert"><p>' . esc_html( $problem ) . '</p></div>';
	}

	if ( '' !== $outcome['message'] ) {
		printf( '<div class="alert %s inline" role="status"><p>%s</p></div>', $outcome['applied'] ? 'success' : 'warning', esc_html( $outcome['message'] ) );
	}

	echo '<form method="post">';
	wp_nonce_field( 'properx_portal_form_migration' );
	echo '<label class="ppx-label" for="ppx-migration-form">Form</label><select id="ppx-migration-form" name="form_id">';

	foreach ( $forms as $form ) {
		printf( '<option value="%d" %s>%s (ID %d)</option>', (int) $form['id'], selected( (int) $form['id'], $outcome['form_id'], false ), esc_html( $form['title'] ), (int) $form['id'] );
	}

	echo '</select>';

	$previewed = isset( $_POST['properx_portal_form_migration'] ) && array() === $outcome['problems'] && ! $outcome['applied'];

	if ( $previewed ) {
		if ( array() === $outcome['changes'] ) {
			echo '<p><strong>Nothing to change: the form is already up to date.</strong></p>';
		} else {
			echo '<p><strong>' . count( $outcome['changes'] ) . ' change(s):</strong></p><ol class="ppx-changes">';

			foreach ( $outcome['changes'] as $change ) {
				echo '<li>' . esc_html( $change ) . '</li>';
			}

			echo '</ol><label class="ppx-switch"><input type="checkbox" name="confirm" value="1"> Apply these changes to the selected form. A backup is saved first.</label>';
		}
	}

	echo '<p class="ppx-test-row"><button type="submit" name="properx_portal_form_migration" value="preview" class="button">Preview changes</button>';

	if ( $previewed && array() !== $outcome['changes'] ) {
		echo ' <button type="submit" name="properx_portal_form_migration" value="apply" class="button primary">Apply /apply form changes</button>';
	}

	echo '</p></form>';

	if ( $outcome['applied'] ) {
		echo '<ol class="ppx-changes">';

		foreach ( $outcome['changes'] as $change ) {
			echo '<li>' . esc_html( $change ) . '</li>';
		}

		echo '</ol>';
	}

	$backups = get_option( PROPERX_PORTAL_OPTION_BACKUPS, array() );

	if ( is_array( $backups ) && array() !== $backups ) {
		echo '<p class="ppx-hint">Backups taken before changes (the last ' . (int) PROPERX_PORTAL_BACKUPS_KEPT . ' are kept). Restoring replaces the form\'s fields, notifications and confirmations; entries and feeds stay.</p><ul class="ppx-backups">';

		foreach ( $backups as $key => $backup ) {
			$base = admin_url( 'admin-post.php?action=properx_portal_form_backup&backup=' . rawurlencode( $key ) );

			printf(
				'<li>Form %d, %s: <a href="%s">Download</a> · <a href="%s" onclick="return confirm(\'Restore form %d as it was then?\')">Restore</a></li>',
				(int) $backup['form_id'],
				esc_html( properx_portal_format_time( $backup['at'] ) ),
				esc_url( wp_nonce_url( $base, 'properx_portal_form_backup_' . $key ) ),
				esc_url( wp_nonce_url( $base . '&do=restore', 'properx_portal_form_backup_' . $key ) ),
				(int) $backup['form_id']
			);
		}

		echo '</ul>';
	}
}
