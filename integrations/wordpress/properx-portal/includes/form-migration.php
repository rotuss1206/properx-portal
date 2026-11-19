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

/** Removed: "License Type(s) Held". Old entries keep their values. */
const PROPERX_PORTAL_REMOVED_FIELDS = array( 17 => 'checkbox' );

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
 * The new fields, in their order, with the id each will get.
 *
 * @return list<array<string, mixed>>
 */
function properx_portal_new_top_fields( $next_id ) {
	$joining = $next_id;
	$agency  = fn ( array $field ) => $field + array( 'conditionalLogic' => properx_portal_condition( $joining, PROPERX_PORTAL_JOINING_AGENCY ) );
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
		array(
			'type'                 => 'text',
			'label'                => 'Partner code (optional)',
			'adminLabel'           => 'ppx_partner_code',
			'description'          => 'From an MGA or FMO partner. Leave blank if you don\'t have one.',
			'maxLength'            => 50,
			'inputName'            => 'code',
			'allowsPrepopulate'    => true,
			'cssClass'             => 'ppx-partner-code',
			'layoutGridColumnSpan' => 6,
		),
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

	foreach ( $fields as $i => $field ) {
		$fields[ $i ] = $field + array( 'id' => $next_id + $i, 'isRequired' => false, 'cssClass' => '', 'description' => '' );
	}

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
 * @return array{form: array<string, mixed>, changes: list<string>, problems: list<string>}
 */
function properx_portal_plan_form_changes( array $form ) {
	$changes  = array();
	$problems = array();
	$fields   = array_values( (array) ( $form['fields'] ?? array() ) );

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
		$next   = 1 + max( array_map( fn ( $field ) => (int) $field['id'], $fields ) );
		$new    = properx_portal_new_top_fields( $next );
		$fields = array_merge( $new, $fields );

		foreach ( $new as $field ) {
			$changes[] = sprintf( 'New field %d "%s" (%s) at the top%s', $field['id'], wp_strip_all_tags( $field['label'] ), $field['type'], empty( $field['conditionalLogic'] ) ? '' : ', shown for "My agency"' );
		}
	}

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
		$next = 1 + max( array_map( fn ( $field ) => (int) $field['id'], $fields ) );
		array_splice( $fields, properx_portal_index_of( $fields, 'id', 19 ) + 1, 0, array( properx_portal_order_summary_field( $next, $joining_id ) ) );
		$changes[] = sprintf( 'New field %d "Order summary" (html) after field 19, shown for "Just me"', $next );
	}

	// 4. The existing fields: shown for "Just me" only, with their new texts.
	foreach ( $fields as $i => $field ) {
		$id = (int) $field['id'];

		if ( ! isset( PROPERX_PORTAL_FORM_FIELDS[ $id ] ) ) {
			continue;
		}

		switch ( $id ) {
			case 14:
				properx_portal_set( $field, 'adminLabel', 'ppx_npn', $changes );
				properx_portal_set( $field, 'maxLength', 10, $changes );
				properx_portal_set( $field, 'description', 'The number NIPR assigned to your insurance license, 7 to 10 digits. We use it to verify your license is active.', $changes );
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
