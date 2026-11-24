<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: removes its settings, scheduled jobs and
 * any license copies still waiting for the portal. Notes and the sent/failed status on Gravity Forms entries stay: they are the record of what
 * was sent.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'properx_portal_settings' );
delete_option( 'properx_portal_last_test' );
delete_option( 'properx_portal_cron_seen' );

wp_unschedule_hook( 'properx_portal_send' );
wp_unschedule_hook( 'properx_portal_send_cancellation' );
wp_unschedule_hook( 'properx_portal_cron_heartbeat' );
wp_unschedule_hook( 'properx_portal_license_copy_cleanup' );

// License copies still waiting for the portal can no longer be sent: they go too.
$properx_portal_uploads = wp_upload_dir( null, false );
$properx_portal_private = trailingslashit( $properx_portal_uploads['basedir'] ) . 'properx-portal-private';

if ( is_dir( $properx_portal_private ) ) {
	foreach ( (array) glob( $properx_portal_private . '/{,.}*', GLOB_BRACE ) as $properx_portal_file ) {
		if ( is_file( $properx_portal_file ) ) {
			wp_delete_file( $properx_portal_file );
		}
	}

	@rmdir( $properx_portal_private ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
