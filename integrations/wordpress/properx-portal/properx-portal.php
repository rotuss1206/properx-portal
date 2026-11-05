<?php
/**
 * Plugin Name:       ProPerX Portal
 * Description:       Sends paid Gravity Forms membership applications to the ProPerX member portal and sends one approval email when a member is approved. Settings: Forms > Settings > ProPerX Portal.
 * Version:           2.0.5
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            KEENEY
 * License:           Proprietary
 *
 * Both features are off until an administrator turns them on. No card data, no files, and no personal
 * data in entry notes or logs. Deactivating removes nothing; deleting the plugin removes its settings
 * and leaves the notes on the entries.
 */

defined( 'ABSPATH' ) || exit;

// The earlier must-use version defines the same functions. Stop here rather than break the site.
if ( function_exists( 'properx_portal_is_configured' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<div class="notice notice-error"><p>ProPerX Portal is not running: remove the older properx-portal-connector.php from wp-content/mu-plugins.</p></div>';
			}
		}
	);

	return;
}

define( 'PROPERX_PORTAL_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/checklist.php';
require_once __DIR__ . '/includes/connector.php';
require_once __DIR__ . '/includes/approval-email.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/cli.php';

register_activation_hook( __FILE__, 'properx_portal_activate' );
register_deactivation_hook( __FILE__, 'properx_portal_deactivate' );
