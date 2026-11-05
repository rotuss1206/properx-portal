<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: removes its settings and scheduled
 * jobs. Notes and the sent/failed status on Gravity Forms entries stay: they are the record of what
 * was sent.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'properx_portal_settings' );
delete_option( 'properx_portal_last_test' );
delete_option( 'properx_portal_cron_seen' );

wp_unschedule_hook( 'properx_portal_send' );
wp_unschedule_hook( 'properx_portal_cron_heartbeat' );
