<?php
/**
 * wp properx-portal send --form=1 [--since=YYYY-MM-DD] [--dry-run]
 *
 * Sends paid entries that were not sent yet, for retries and for moving older applications
 * across. Prints entry ids only. An alternative for those with shell access; the entry list's bulk
 * action does the same.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paid entries of a form.
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

function properx_portal_cli_send( $args, $assoc ) {
	$form_id = isset( $assoc['form'] ) ? (int) $assoc['form'] : 0;
	$since   = isset( $assoc['since'] ) ? (string) $assoc['since'] : '';
	$dry_run = ! empty( $assoc['dry-run'] );

	if ( properx_portal_form_id() !== $form_id ) {
		WP_CLI::error( 'Only the configured form can be sent: --form=' . properx_portal_form_id() );
	}

	if ( '' !== $since && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
		WP_CLI::error( '--since must be YYYY-MM-DD.' );
	}

	if ( ! $dry_run && ! properx_portal_can_send() ) {
		WP_CLI::error( 'Sending is off, or the portal is not set up: Forms > Settings > ProPerX Portal.' );
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
