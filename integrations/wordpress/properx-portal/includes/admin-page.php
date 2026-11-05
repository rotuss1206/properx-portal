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
 * @return array{errors: list<string>, saved: bool}
 */
function properx_portal_handle_settings_post() {
	$outcome = array( 'errors' => array(), 'saved' => false );

	if ( ! current_user_can( 'manage_options' ) ) {
		return $outcome;
	}

	if ( isset( $_POST['properx_portal_save'] ) ) {
		check_admin_referer( 'properx_portal_save' );

		$outcome['errors'] = properx_portal_apply_settings_form( wp_unslash( $_POST ) );
		$outcome['saved']  = array() === $outcome['errors'];

		// Saved settings are checked at once. It can take up to the test's 15 seconds if nothing answers.
		if ( '' !== properx_portal_url() && '' !== properx_portal_secret() ) {
			properx_portal_run_connection_test();
		}
	}

	if ( isset( $_POST['properx_portal_test'] ) ) {
		check_admin_referer( 'properx_portal_test' );

		if ( '' !== properx_portal_url() && '' !== properx_portal_secret() ) {
			properx_portal_run_connection_test();
		} else {
			$outcome['errors'][] = 'Set the Portal URL and the Secret first.';
		}
	}

	return $outcome;
}

/**
 * The Secret field, its badge and the line under it. Prints only the state, never the value: the
 * field stays empty, and a new value replaces the old one.
 */
function properx_portal_print_secret_field() {
	$status = properx_portal_secret_status();
	$badges = array(
		'saved'      => array( 'is-ok', 'Saved' ),
		'not_set'    => array( 'is-none', 'Not set' ),
		'unreadable' => array( 'is-bad', 'Unreadable' ),
		'constant'   => array( 'is-ok', 'Set in wp-config.php' ),
	);
	$hints  = array(
		'saved'      => 'Paste a new value only to replace it.',
		'not_set'    => 'Paste the shared secret here. It is stored encrypted and never shown.',
		'unreadable' => 'The WordPress salts changed, so it can no longer be read. Paste it again.',
		'constant'   => 'Set in wp-config.php, which takes priority over this field.',
	);

	[ $class, $word ] = $badges[ $status['state'] ];

	echo '<div class="ppx-secret-row">';
	printf(
		'<input type="password" id="ppx-portal-secret" name="secret" value="" autocomplete="new-password" aria-describedby="ppx-portal-secret-hint" %s %s>',
		'saved' === $status['state'] ? 'placeholder="••••••••••••••••"' : '',
		disabled( properx_portal_secret_is_constant(), true, false )
	);
	printf( '<span class="ppx-badge %s">%s</span>', esc_attr( $class ), esc_html( $word ) );

	if ( '' !== $status['changed'] ) {
		echo '<small class="ppx-when">Last changed ' . esc_html( $status['changed'] ) . '</small>';
	}

	echo '</div><p class="ppx-hint" id="ppx-portal-secret-hint">' . esc_html( $hints[ $status['state'] ] ) . '</p>';
}

/**
 * The last check, beside the Test connection button. It stays after a reload.
 */
function properx_portal_print_connection_status() {
	$status = properx_portal_connection_status();

	switch ( $status['state'] ) {
		case 'ok':
			$class = 'is-ok';
			$words = 'Connected' . ( '' === $status['time'] ? '' : ' — checked ' . $status['time'] );
			break;
		case 'failed':
			$class = 'is-bad';
			$words = $status['message'] . ( '' === $status['time'] ? '' : ' Checked ' . $status['time'] . '.' );
			break;
		case 'stale':
			$class = 'is-none';
			$words = 'Not checked since the URL or the secret changed.';
			break;
		default:
			$class = 'is-none';
			$words = 'Not checked yet.';
	}

	printf( '<span class="ppx-badge ppx-connection %s" role="status">%s</span>', esc_attr( $class ), esc_html( $words ) );
}

/**
 * All green: one line that opens on a click. Otherwise the whole list, with what needs doing on top.
 *
 * @param list<array{label: string, ok: bool, help: string}> $items
 */
function properx_portal_print_checklist( array $items ) {
	$view = properx_portal_checklist_view( $items );
	$list = '<ul class="ppx-portal-check">';

	foreach ( $view['items'] as $item ) {
		$list .= sprintf(
			'<li class="%s"><strong>%s %s</strong>%s</li>',
			$item['ok'] ? 'is-ok' : 'is-bad',
			$item['ok'] ? '&#10003;' : '&#10007;',
			esc_html( $item['label'] ),
			'' === $item['help'] ? '' : '<span>' . esc_html( $item['help'] ) . '</span>'
		);
	}

	$list .= '</ul>';

	echo $view['all_ok']
		? '<details class="ppx-portal-passed"><summary>&#10003; All checks passed</summary>' . $list . '</details>'
		: $list;
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
		.ppx-portal-passed summary { padding: 10px 14px; border-left: 4px solid #2e7d32; border-radius: 4px; background: #edf7ed; color: #1b5e20; font-weight: 600; cursor: pointer; }
		.ppx-portal-passed[open] summary { margin-bottom: 8px; }
		.ppx-secret-row, .ppx-test-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
		.ppx-test-row { margin-top: 12px; }
		.ppx-portal-settings .ppx-secret-row input[type=password] { flex: 1 1 280px; }
		.ppx-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 13px; font-weight: 600; line-height: 18px; }
		.ppx-badge.is-ok { background: #edf7ed; color: #1b5e20; }
		.ppx-badge.is-bad { background: #fdecea; color: #8e1c1c; }
		.ppx-badge.is-none { background: #eceff3; color: #4a5568; }
		.ppx-connection { flex: 0 1 auto; font-weight: 400; border-radius: 6px; padding: 6px 12px; }
		.ppx-when { color: #5b6b7f; font-size: 12px; }
	</style>';

	echo '<div class="gform-settings-panel"><div class="gform-settings-panel__content ppx-portal-settings">';

	// "inline": wp-admin's script otherwise moves div.error away to the top of the screen.
	foreach ( $outcome['errors'] as $error ) {
		echo '<div class="alert error inline" role="alert"><p>' . esc_html( $error ) . '</p></div>';
	}

	if ( $outcome['saved'] ) {
		echo '<div class="alert success inline" role="status"><p>Settings saved.</p></div>';
	}

	echo '<h3>Setup checklist</h3>';
	properx_portal_print_checklist( properx_portal_checklist_items( properx_portal_checklist_facts() ) );

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
	properx_portal_print_secret_field();


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

	echo '<h3>Test connection</h3><p class="ppx-hint">Sends a signed request that changes nothing and says in plain words what is wrong. Saving the settings runs it too.</p><form method="post">';
	wp_nonce_field( 'properx_portal_test' );
	echo '<div class="ppx-test-row"><button type="submit" name="properx_portal_test" value="1" class="button">Test connection</button>';
	properx_portal_print_connection_status();
	echo '</div></form>';

	echo '</div></div>';
}
