<?php
/**
 * The setup checklist: what is true on this site (properx_portal_checklist_facts) and what that
 * means for the person setting it up (properx_portal_checklist_items, no WordPress calls).
 */

defined( 'ABSPATH' ) || exit;

/** A heartbeat older than this means WP-Cron is not running. It fires hourly. */
const PROPERX_PORTAL_CRON_MAX_AGE = 2 * HOUR_IN_SECONDS;

/**
 * @return array<string, mixed>
 */
function properx_portal_checklist_facts() {
	$form_id = properx_portal_form_id();
	$form    = $form_id > 0 && class_exists( 'GFAPI' ) ? GFAPI::get_form( $form_id ) : false;
	$feeds   = $form && class_exists( 'GFAPI' ) ? GFAPI::get_feeds( null, $form_id, 'gravityformsstripe' ) : array();

	$stripe_mode   = '';
	$stripe_secret = false;

	if ( function_exists( 'gf_stripe' ) && gf_stripe() ) {
		$stripe_mode   = (string) gf_stripe()->get_api_mode( gf_stripe()->get_plugin_settings() );
		$stripe_secret = '' !== (string) gf_stripe()->get_webhook_signing_secret( $stripe_mode );
	}

	$last_test = get_option( PROPERX_PORTAL_OPTION_TEST, array() );

	return array(
		'url_set'          => '' !== properx_portal_url(),
		'secret_set'       => '' !== properx_portal_secret(),
		'secret_broken'    => properx_portal_secret_is_unreadable(),
		'environment'      => wp_get_environment_type(),
		'form_id'          => $form_id,
		'form_exists'      => (bool) $form,
		'form_has_stripe'  => is_array( $feeds ) && array() !== $feeds,
		'stripe_installed' => function_exists( 'gf_stripe' ),
		'stripe_mode'      => $stripe_mode,
		'stripe_secret'    => $stripe_secret,
		'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		'cron_seen_ago'    => get_option( PROPERX_PORTAL_OPTION_CRON ) ? time() - (int) get_option( PROPERX_PORTAL_OPTION_CRON ) : null,
		'test_ok'          => is_array( $last_test ) && ! empty( $last_test['ok'] ) && ( $last_test['fingerprint'] ?? '' ) === properx_portal_connection_fingerprint(),
		'send_enabled'     => properx_portal_sending_enabled(),
	);
}

/**
 * @param array<string, mixed> $facts
 * @return list<array{label: string, ok: bool, help: string}> Help says what to do; empty once ok.
 */
function properx_portal_checklist_items( array $facts ) {
	$items = array();

	$add = function ( $label, $ok, $help ) use ( &$items ) {
		$items[] = array( 'label' => $label, 'ok' => (bool) $ok, 'help' => $ok ? '' : $help );
	};

	if ( $facts['secret_broken'] ) {
		$connection_help = 'The stored secret can no longer be read, because the WordPress salts changed. Kevin needs to paste it again.';
	} elseif ( ! $facts['url_set'] && ! $facts['secret_set'] ) {
		$connection_help = 'Enter the Portal URL. Kevin pastes the Secret.';
	} elseif ( ! $facts['url_set'] ) {
		$connection_help = 'Enter the Portal URL.';
	} else {
		$connection_help = 'Kevin pastes the Secret (the same value as WP_INTAKE_SECRET in the portal).';
	}

	$add( 'Portal URL and secret are set', $facts['url_set'] && $facts['secret_set'], $connection_help );

	$add(
		'This is not a local environment',
		'local' !== $facts['environment'],
		'WP_ENVIRONMENT_TYPE is "local", which turns off TLS checks for the portal. Normal on a developer copy; on the live site remove it from wp-config.php.'
	);

	if ( $facts['form_id'] < 1 ) {
		$form_help = 'Enter the Form ID of the Membership Application form.';
	} elseif ( ! $facts['form_exists'] ) {
		$form_help = sprintf( 'Form %d does not exist. Check the Form ID under Forms.', $facts['form_id'] );
	} else {
		$form_help = sprintf( 'Form %d has no Stripe feed, so no payment would ever be sent. Check the Form ID, or the form\'s Settings > Stripe.', $facts['form_id'] );
	}

	$add( 'The form exists and has a Stripe feed', $facts['form_exists'] && $facts['form_has_stripe'], $form_help );

	if ( ! $facts['stripe_installed'] ) {
		$stripe_help = 'The Gravity Forms Stripe add-on is not active.';
	} elseif ( 'live' !== $facts['stripe_mode'] ) {
		$stripe_help = 'Stripe is not in Live mode: Forms > Settings > Stripe > API. Normal on a test copy; on the live site real payments would not be processed.';
	} else {
		$stripe_help = 'No Live signing secret: Forms > Settings > Stripe > Webhooks. Without it Stripe\'s confirmation is refused, and bank (ACH) payments stay in Processing and are never sent.';
	}

	$add( 'Gravity Forms Stripe is in Live mode with a signing secret', 'live' === $facts['stripe_mode'] && $facts['stripe_secret'], $stripe_help );

	if ( $facts['cron_disabled'] && null === $facts['cron_seen_ago'] ) {
		$cron_help = 'DISABLE_WP_CRON is set and WP-Cron has not run yet. A server task must call wp-cron.php every few minutes; ask your host.';
	} elseif ( null === $facts['cron_seen_ago'] ) {
		$cron_help = 'WP-Cron has not run yet. It runs when someone visits the site: reload this page in a minute. If this stays red, ask your host whether wp-cron.php is blocked.';
	} else {
		$cron_help = 'WP-Cron last ran over 2 hours ago, so deliveries would wait. Ask your host to check that wp-cron.php can run.';
	}

	$add( 'WP-Cron works', null !== $facts['cron_seen_ago'] && $facts['cron_seen_ago'] <= PROPERX_PORTAL_CRON_MAX_AGE, $cron_help );

	$add(
		'Test connection succeeded',
		$facts['test_ok'],
		'Press Test connection below. It needs running again after the URL or the secret changes.'
	);

	$add(
		'Sending to the portal is on',
		$facts['send_enabled'],
		'Turn on "Send paid applications to the portal" once everything above is green.'
	);

	return $items;
}
