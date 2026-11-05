<?php
/**
 * Forms > Settings > ProPerX Portal, for administrators only. The secret is never printed: the page
 * says only "set" or "not set".
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'gform_loaded',
	function () {
		if ( is_admin() && current_user_can( 'manage_options' ) ) {
			GFForms::add_settings_page(
				array(
					'name'      => 'properx_portal',
					'tab_label' => 'ProPerX Portal',
					'title'     => 'ProPerX Portal',
					'handler'   => 'properx_portal_settings_page',
				),
				''
			);
		}
	}
);

// Installed without Gravity Forms: say so, instead of a settings tab that never appears.
add_action(
	'admin_notices',
	function () {
		if ( ! class_exists( 'GFForms' ) && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>ProPerX Portal needs Gravity Forms, which is not active.</p></div>';
		}
	}
);

// A heartbeat lost to a restore or a database move comes back on the next admin visit.
add_action( 'admin_init', 'properx_portal_schedule_heartbeat' );

/**
 * Handles the two forms, then returns what to show.
 *
 * @return array{errors: list<string>, saved: bool, test: ?array{ok: bool, message: string}}
 */
function properx_portal_handle_settings_post() {
	$outcome = array( 'errors' => array(), 'saved' => false, 'test' => null );

	if ( ! current_user_can( 'manage_options' ) ) {
		return $outcome;
	}

	if ( isset( $_POST['properx_portal_save'] ) ) {
		check_admin_referer( 'properx_portal_save' );

		$outcome['errors'] = properx_portal_apply_settings_form( wp_unslash( $_POST ) );
		$outcome['saved']  = array() === $outcome['errors'];
	}

	if ( isset( $_POST['properx_portal_test'] ) ) {
		check_admin_referer( 'properx_portal_test' );

		$outcome['test'] = '' !== properx_portal_url() && '' !== properx_portal_secret()
			? properx_portal_test_connection()
			: array( 'ok' => false, 'message' => 'Set the Portal URL and the Secret first.' );

		update_option(
			PROPERX_PORTAL_OPTION_TEST,
			array( 'ok' => $outcome['test']['ok'], 'fingerprint' => properx_portal_connection_fingerprint() ),
			false
		);
	}

	return $outcome;
}

function properx_portal_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Only an administrator can open this page.' );
	}

	$outcome  = properx_portal_handle_settings_post();
	$settings = properx_portal_stored_settings();

	// Gravity Forms draws its header, tab menu and footer around this handler (GFSettings::settings_page).
	echo '<style>
		.ppx-portal-settings h3 { margin: 24px 0 10px; }
		.ppx-portal-settings h3:first-child { margin-top: 0; }
		.ppx-portal-settings label.ppx-label { display: block; margin: 16px 0 4px; font-weight: 600; }
		.ppx-portal-settings input[type=text], .ppx-portal-settings input[type=url], .ppx-portal-settings input[type=password], .ppx-portal-settings input[type=number] { width: 100%; max-width: 480px; }
		.ppx-portal-settings .ppx-hint { margin: 4px 0 0; color: #5b6b7f; font-size: 13px; }
		.ppx-portal-settings .ppx-switch { display: block; margin: 14px 0 0; font-weight: 600; }
		.ppx-portal-check { margin: 0; padding: 0; list-style: none; }
		.ppx-portal-check li { margin: 0 0 8px; padding: 10px 14px; border-left: 4px solid; border-radius: 4px; }
		.ppx-portal-check li.is-ok { border-color: #2e7d32; background: #edf7ed; color: #1b5e20; }
		.ppx-portal-check li.is-bad { border-color: #c62828; background: #fdecea; color: #8e1c1c; }
		.ppx-portal-check strong { display: block; }
		.ppx-portal-check span { display: block; margin-top: 2px; font-size: 13px; }
	</style>';

	echo '<div class="gform-settings-panel"><div class="gform-settings-panel__content ppx-portal-settings">';

	// "inline": wp-admin's script otherwise moves div.error away to the top of the screen.
	foreach ( $outcome['errors'] as $error ) {
		echo '<div class="alert error inline" role="alert"><p>' . esc_html( $error ) . '</p></div>';
	}

	if ( $outcome['saved'] ) {
		echo '<div class="alert success inline" role="status"><p>Settings saved.</p></div>';
	}

	if ( $outcome['test'] ) {
		printf(
			'<div class="alert %s inline" role="status"><p>%s</p></div>',
			$outcome['test']['ok'] ? 'success' : 'error',
			esc_html( $outcome['test']['message'] )
		);
	}

	echo '<h3>Setup checklist</h3><ul class="ppx-portal-check">';

	foreach ( properx_portal_checklist_items( properx_portal_checklist_facts() ) as $item ) {
		printf(
			'<li class="%s"><strong>%s %s</strong>%s</li>',
			$item['ok'] ? 'is-ok' : 'is-bad',
			$item['ok'] ? '&#10003;' : '&#10007;',
			esc_html( $item['label'] ),
			'' === $item['help'] ? '' : '<span>' . esc_html( $item['help'] ) . '</span>'
		);
	}

	echo '</ul>';

	echo '<h3>Connection</h3><form method="post" autocomplete="off">';
	wp_nonce_field( 'properx_portal_save' );

	$from_constant = '<p class="ppx-hint">Set in wp-config.php, which takes priority over this field.</p>';

	echo '<label class="ppx-label" for="ppx-portal-url">Portal URL</label>';
	printf(
		'<input type="url" id="ppx-portal-url" name="url" value="%s" placeholder="https://" %s>',
		esc_attr( properx_portal_url() ),
		disabled( properx_portal_url_is_constant(), true, false )
	);
	echo properx_portal_url_is_constant() ? $from_constant : '';

	echo '<label class="ppx-label" for="ppx-portal-form">Form ID</label>';
	printf(
		'<input type="number" min="1" id="ppx-portal-form" name="form_id" value="%s" %s>',
		esc_attr( properx_portal_form_id() > 0 ? (string) properx_portal_form_id() : '' ),
		disabled( properx_portal_form_id_is_constant(), true, false )
	);
	echo properx_portal_form_id_is_constant() ? $from_constant : '<p class="ppx-hint">The Membership Application form; the number is in the ID column under Forms.</p>';

	echo '<label class="ppx-label" for="ppx-portal-secret">Secret</label>';
	printf(
		'<input type="password" id="ppx-portal-secret" name="secret" value="" autocomplete="new-password" %s>',
		disabled( properx_portal_secret_is_constant(), true, false )
	);

	if ( properx_portal_secret_is_constant() ) {
		echo $from_constant;
	} elseif ( properx_portal_secret_is_unreadable() ) {
		echo '<p class="ppx-hint"><strong>Set, but unreadable.</strong> The WordPress salts changed, so paste it again.</p>';
	} else {
		printf(
			'<p class="ppx-hint"><strong>%s.</strong> %s</p>',
			'' !== properx_portal_secret() ? 'set' : 'not set',
			'' !== properx_portal_secret() ? 'Leave empty to keep it; it is stored encrypted and never shown.' : 'Kevin pastes it here. It is stored encrypted and never shown.'
		);
	}

	echo '<h3>Switches</h3>';
	printf(
		'<label class="ppx-switch"><input type="checkbox" name="send_enabled" value="1" %s> Send paid applications to the portal</label><p class="ppx-hint">Off: nothing is sent. Applications paid while it is off can be sent later with "Resend to portal".</p>',
		checked( $settings['send_enabled'], true, false )
	);
	printf(
		'<label class="ppx-switch"><input type="checkbox" name="approval_email_enabled" value="1" %s> Approval email</label><p class="ppx-hint">On: "Approve &amp; Activate" sends one email with next steps. Off: the two existing emails go out as before. Keep it off until the client approves the text.</p>',
		checked( $settings['approval_email_enabled'], true, false )
	);

	echo '<p style="margin-top:20px"><button type="submit" name="properx_portal_save" value="1" class="button primary">Save settings</button></p></form>';

	echo '<h3>Test connection</h3><p class="ppx-hint">Sends a signed request that changes nothing and says in plain words what is wrong.</p><form method="post">';
	wp_nonce_field( 'properx_portal_test' );
	echo '<p><button type="submit" name="properx_portal_test" value="1" class="button">Test connection</button></p></form>';

	echo '</div></div>';
}
