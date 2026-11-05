<?php

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase {

	private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		$GLOBALS['wp_options'] = array();
		$GLOBALS['wp_removed'] = array();
		$GLOBALS['entry_meta'] = array();
		$GLOBALS['wp_salt']    = 'test-salt-one';
	}

	// The secret

	public function test_the_secret_is_stored_encrypted_and_reads_back(): void {
		properx_portal_apply_settings_form( array( 'url' => 'https://portal.test', 'form_id' => '1', 'secret' => self::SECRET ) );

		$this->assertStringNotContainsString( self::SECRET, json_encode( get_option( PROPERX_PORTAL_OPTION ) ) );
		$this->assertStringNotContainsString( self::SECRET, base64_decode( properx_portal_stored_settings()['secret'] ) );
		$this->assertSame( self::SECRET, properx_portal_secret() );
	}

	public function test_two_encryptions_of_one_secret_differ(): void {
		$key = str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );

		$this->assertNotSame( properx_portal_encrypt( self::SECRET, $key ), properx_portal_encrypt( self::SECRET, $key ) );
	}

	public function test_damaged_or_foreign_data_does_not_read(): void {
		$key   = str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$other = str_repeat( 'o', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$box   = properx_portal_encrypt( self::SECRET, $key );

		$this->assertNull( properx_portal_decrypt( $box, $other ) );
		$this->assertNull( properx_portal_decrypt( 'not base64 !', $key ) );
		$this->assertNull( properx_portal_decrypt( base64_encode( 'short' ), $key ) );
		$this->assertNull( properx_portal_decrypt( substr( $box, 0, -6 ) . 'AAAAAA', $key ) );
	}

	public function test_changed_salts_make_the_secret_unreadable_and_say_so(): void {
		properx_portal_apply_settings_form( array( 'secret' => self::SECRET ) );
		$this->assertFalse( properx_portal_secret_is_unreadable() );

		$GLOBALS['wp_salt'] = 'test-salt-two';

		$this->assertSame( '', properx_portal_secret() );
		$this->assertTrue( properx_portal_secret_is_unreadable() );
		$this->assertFalse( properx_portal_is_configured() );
	}

	public function test_an_empty_secret_field_keeps_the_stored_one(): void {
		properx_portal_apply_settings_form( array( 'secret' => self::SECRET ) );
		properx_portal_apply_settings_form( array( 'secret' => '', 'url' => 'https://portal.test' ) );

		$this->assertSame( self::SECRET, properx_portal_secret() );
	}

	public function test_a_short_secret_is_refused_and_the_old_one_stays(): void {
		properx_portal_apply_settings_form( array( 'secret' => self::SECRET ) );

		$errors = properx_portal_apply_settings_form( array( 'secret' => 'short' ) );

		$this->assertCount( 1, $errors );
		$this->assertStringNotContainsString( 'short"', implode( ' ', $errors ) );
		$this->assertSame( self::SECRET, properx_portal_secret() );
	}

	// Settings

	public function test_both_switches_are_off_by_default_and_on_activation(): void {
		$this->assertFalse( properx_portal_sending_enabled() );
		$this->assertFalse( properx_portal_approval_email_enabled() );

		properx_portal_activate_options_for_test();

		$this->assertFalse( properx_portal_sending_enabled() );
		$this->assertFalse( properx_portal_approval_email_enabled() );
	}

	public function test_an_unticked_box_turns_a_switch_off(): void {
		properx_portal_apply_settings_form( array( 'send_enabled' => '1', 'approval_email_enabled' => '1' ) );
		$this->assertTrue( properx_portal_sending_enabled() );
		$this->assertTrue( properx_portal_approval_email_enabled() );

		properx_portal_apply_settings_form( array() );
		$this->assertFalse( properx_portal_sending_enabled() );
		$this->assertFalse( properx_portal_approval_email_enabled() );
	}

	public function test_the_portal_url_must_be_https(): void {
		$this->assertCount( 1, properx_portal_apply_settings_form( array( 'url' => 'http://portal.test' ) ) );
		$this->assertCount( 1, properx_portal_apply_settings_form( array( 'url' => 'portal.test' ) ) );
		$this->assertSame( '', properx_portal_url() );

		$this->assertSame( array(), properx_portal_apply_settings_form( array( 'url' => 'https://portal.test/' ) ) );
		$this->assertSame( 'https://portal.test', properx_portal_url() );
	}

	public function test_nothing_can_be_sent_until_configured_and_switched_on(): void {
		$this->assertFalse( properx_portal_can_send() );

		properx_portal_apply_settings_form( array( 'url' => 'https://portal.test', 'form_id' => '1', 'secret' => self::SECRET ) );
		$this->assertFalse( properx_portal_can_send() );

		properx_portal_apply_settings_form( array( 'form_id' => '1', 'url' => 'https://portal.test', 'send_enabled' => '1' ) );
		$this->assertTrue( properx_portal_can_send() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_constants_win_and_the_form_cannot_change_them(): void {
		define( 'PROPERX_PORTAL_URL', 'https://constant.test/' );
		define( 'PROPERX_PORTAL_SECRET', 'constant-secret' );
		define( 'PROPERX_PORTAL_FORM_ID', 7 );

		$errors = properx_portal_apply_settings_form( array( 'url' => 'https://form.test', 'form_id' => '2', 'secret' => self::SECRET ) );

		$this->assertSame( array(), $errors );
		$this->assertTrue( properx_portal_url_is_constant() && properx_portal_secret_is_constant() && properx_portal_form_id_is_constant() );
		$this->assertSame( 'https://constant.test', properx_portal_url() );
		$this->assertSame( 'constant-secret', properx_portal_secret() );
		$this->assertSame( 7, properx_portal_form_id() );
		$this->assertSame( '', get_option( PROPERX_PORTAL_OPTION )['secret'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_removes_the_settings(): void {
		update_option( PROPERX_PORTAL_OPTION, array( 'url' => 'x' ) );
		update_option( PROPERX_PORTAL_OPTION_TEST, array( 'ok' => true ) );
		update_option( PROPERX_PORTAL_OPTION_CRON, 1 );
		update_option( 'somebody_elses', 1 );

		define( 'WP_UNINSTALL_PLUGIN', 'properx-portal/properx-portal.php' );

		eval( 'function wp_unschedule_hook( $hook ) { $GLOBALS["unscheduled"][] = $hook; }' );

		require dirname( __DIR__ ) . '/properx-portal/uninstall.php';

		$this->assertSame( array( 'somebody_elses' => 1 ), $GLOBALS['wp_options'] );
		$this->assertSame( array( 'properx_portal_send', 'properx_portal_cron_heartbeat' ), $GLOBALS['unscheduled'] );
	}

	// Checklist

	private function greenFacts(): array {
		return array(
			'url_set'          => true,
			'secret_set'       => true,
			'secret_broken'    => false,
			'environment'      => 'production',
			'form_id'          => 1,
			'form_exists'      => true,
			'form_has_stripe'  => true,
			'stripe_installed' => true,
			'stripe_mode'      => 'live',
			'stripe_secret'    => true,
			'cron_disabled'    => false,
			'cron_seen_ago'    => 60,
			'test_ok'          => true,
			'send_enabled'     => true,
		);
	}

	public function test_a_finished_setup_is_all_green_with_no_help_text(): void {
		$items = properx_portal_checklist_items( $this->greenFacts() );

		$this->assertCount( 7, $items );

		foreach ( $items as $item ) {
			$this->assertTrue( $item['ok'], $item['label'] );
			$this->assertSame( '', $item['help'] );
		}
	}

	public static function brokenFacts(): array {
		return array(
			'no url'        => array( array( 'url_set' => false ), 0, 'Portal URL' ),
			'no secret'     => array( array( 'secret_set' => false ), 0, 'Kevin pastes' ),
			'salts changed' => array( array( 'secret_set' => false, 'secret_broken' => true ), 0, 'salts' ),
			'local'         => array( array( 'environment' => 'local' ), 1, 'WP_ENVIRONMENT_TYPE' ),
			'no form id'    => array( array( 'form_id' => 0, 'form_exists' => false ), 2, 'Enter the Form ID' ),
			'no form'       => array( array( 'form_exists' => false ), 2, 'does not exist' ),
			'no feed'       => array( array( 'form_has_stripe' => false ), 2, 'no Stripe feed' ),
			'no add-on'     => array( array( 'stripe_installed' => false, 'stripe_mode' => '', 'stripe_secret' => false ), 3, 'not active' ),
			'test mode'     => array( array( 'stripe_mode' => 'test' ), 3, 'not in Live mode' ),
			'no signing'    => array( array( 'stripe_secret' => false ), 3, 'signing secret' ),
			'cron not yet'  => array( array( 'cron_seen_ago' => null ), 4, 'has not run yet' ),
			'cron disabled' => array( array( 'cron_seen_ago' => null, 'cron_disabled' => true ), 4, 'DISABLE_WP_CRON' ),
			'cron old'      => array( array( 'cron_seen_ago' => 3 * HOUR_IN_SECONDS ), 4, 'over 2 hours' ),
			'no test'       => array( array( 'test_ok' => false ), 5, 'Test connection' ),
			'not sending'   => array( array( 'send_enabled' => false ), 6, 'Send paid applications' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'brokenFacts' )]
	public function test_each_problem_turns_its_line_red_and_says_what_to_do( array $change, int $line, string $help ): void {
		$items = properx_portal_checklist_items( array_merge( $this->greenFacts(), $change ) );

		$this->assertFalse( $items[ $line ]['ok'] );
		$this->assertStringContainsString( $help, $items[ $line ]['help'] );

		foreach ( $items as $index => $item ) {
			if ( $index !== $line ) {
				$this->assertTrue( $item['ok'], 'Only one line should be red: ' . $item['label'] );
			}
		}
	}

	public function test_a_changed_url_or_secret_needs_a_new_test(): void {
		properx_portal_apply_settings_form( array( 'url' => 'https://portal.test', 'secret' => self::SECRET ) );
		$first = properx_portal_connection_fingerprint();

		$this->assertStringNotContainsString( self::SECRET, $first );

		properx_portal_apply_settings_form( array( 'url' => 'https://other.test' ) );
		$this->assertNotSame( $first, properx_portal_connection_fingerprint() );
	}

	public function test_connection_results_name_the_cause(): void {
		$this->assertTrue( properx_portal_test_result( 200 )['ok'] );

		foreach ( array( 401 => 'WP_INTAKE_SECRET', 403 => 'WAF', 404 => 'INTAKE_ENABLED', 500 => 'HTTP 500' ) as $code => $words ) {
			$result = properx_portal_test_result( $code );

			$this->assertFalse( $result['ok'] );
			$this->assertStringContainsString( $words, $result['message'] );
		}
	}

	// Approval email

	private function email(): array {
		return properx_portal_approval_email( 'https://site.test/wp-login.php?action=rp&key=abc&login=x', 'https://site.test/plans/', 'https://site.test/wp-login.php' );
	}

	public function test_the_email_has_the_agreed_subject_links_and_both_parts(): void {
		$email = $this->email();

		$this->assertSame( 'Welcome to ProPerX Alliance — Your Membership Is Approved', $email['subject'] );

		foreach ( array( $email['html'], $email['text'] ) as $part ) {
			$this->assertStringContainsString( 'https://site.test/plans/', $part );
			$this->assertStringContainsString( 'https://my-benefits.net/2027-group-health-coverage-enrollment-form/', $part );
			$this->assertStringContainsString( '(440) 776-7379', $part );
			$this->assertStringContainsString( 'action=rp', $part );
			$this->assertStringContainsString( 'Ready to enroll?', str_replace( array( '&ldquo;', '&rdquo;' ), '', $part ) );
			$this->assertStringNotContainsString( 'ProPerx', $part );
		}

		$this->assertStringContainsString( 'src="cid:' . PROPERX_PORTAL_LOGO_CID . '"', $email['html'] );
		$this->assertStringContainsString( 'alt="ProPerX Alliance"', $email['html'] );
		$this->assertStringNotContainsString( '<', $email['text'] );
	}

	public function test_the_draft_for_the_client_says_the_same_as_the_plugin(): void {
		$email = $this->email();
		$draft = file_get_contents( dirname( __DIR__, 3 ) . '/docs/wordpress/emails.md' );

		$this->assertStringContainsString( $email['subject'], $draft );

		$sentences = array(
			'Your ProPerX Alliance membership application has been reviewed and approved. Here is what to do next.',
			'Once you are signed in, the 2027 plans page shows the "Ready to enroll?" section.',
			"Compare the five plans and open the full summary for any you're considering. Find your monthly rate by your age and who you're covering. Then complete the enrollment form; have your plan choice and the details for anyone you're covering ready.",
			'Enrollment is subject to final plan approval and ProPerX Alliance membership requirements.',
			'Call us at (440) 776-7379.',
		);

		foreach ( $sentences as $sentence ) {
			$this->assertStringContainsString( $sentence, $email['text'] );
			$this->assertStringContainsString( $sentence, $draft );
		}
	}

	public function test_the_logo_ships_with_the_plugin(): void {
		$logo = dirname( __DIR__ ) . '/properx-portal/assets/logo-color.png';

		$this->assertFileExists( $logo );
		$this->assertSame( 'image/png', getimagesize( $logo )['mime'] );
	}

	public function test_with_the_switch_off_the_old_emails_stay_hooked(): void {
		$GLOBALS['wp_hooks'] = array();

		properx_portal_take_over_approval_email();

		$this->assertSame( array(), $GLOBALS['wp_removed'] );
		$this->assertArrayNotHasKey( 'set_user_role', $GLOBALS['wp_hooks'] );
	}

	public function test_with_the_switch_on_both_old_emails_are_unhooked(): void {
		$GLOBALS['wp_hooks'] = array();
		properx_portal_apply_settings_form( array( 'approval_email_enabled' => '1' ) );

		properx_portal_take_over_approval_email();

		$this->assertContains( array( 'set_user_role', 'send_activation_email_on_role_change' ), $GLOBALS['wp_removed'] );
		$this->assertContains( array( 'admin_post_properx_activate_member', 'properx_handle_activate_member' ), $GLOBALS['wp_removed'] );
		$this->assertContains( 'properx_portal_on_role_change', $GLOBALS['wp_hooks']['set_user_role'] );
		$this->assertContains( 'properx_portal_handle_activate_member', $GLOBALS['wp_hooks']['admin_post_properx_activate_member'] );
	}

	// Started-subscription notification

	private function configuredForm(): array {
		properx_portal_apply_settings_form( array( 'form_id' => '1' ) );

		return array( 'id' => 1 );
	}

	public function test_a_notification_on_the_subscription_event_goes_once_per_entry(): void {
		$form         = $this->configuredForm();
		$notification = array( 'id' => 'abc', 'event' => 'create_subscription' );

		$this->assertFalse( properx_portal_send_start_notification_once( false, $notification, $form, array( 'id' => 5 ) ) );
		$this->assertTrue( properx_portal_send_start_notification_once( false, $notification, $form, array( 'id' => 5 ) ) );
		$this->assertFalse( properx_portal_send_start_notification_once( false, $notification, $form, array( 'id' => 6 ) ) );
		$this->assertFalse( properx_portal_send_start_notification_once( false, array( 'id' => 'other', 'event' => 'create_subscription' ), $form, array( 'id' => 5 ) ) );
	}

	public function test_a_request_arriving_during_the_first_one_is_held_back_by_the_lock(): void {
		$form = $this->configuredForm();

		add_option( 'properx_portal_lock_' . md5( '5|abc' ), time() );

		$this->assertTrue( properx_portal_send_start_notification_once( false, array( 'id' => 'abc', 'event' => 'create_subscription' ), $form, array( 'id' => 5 ) ) );
	}

	public function test_other_notifications_and_forms_are_left_alone(): void {
		$form = $this->configuredForm();

		foreach ( array( 1, 2 ) as $repeat ) {
			$this->assertFalse( properx_portal_send_start_notification_once( false, array( 'id' => 'abc', 'event' => 'form_submission' ), $form, array( 'id' => 5 ) ) );
			$this->assertFalse( properx_portal_send_start_notification_once( false, array( 'id' => 'abc', 'event' => 'create_subscription' ), array( 'id' => 2 ), array( 'id' => 5 ) ) );
		}

		$this->assertTrue( properx_portal_send_start_notification_once( true, array( 'id' => 'abc', 'event' => 'create_subscription' ), $form, array( 'id' => 5 ) ) );
	}

	// Package

	public function test_the_built_zip_unpacks_into_one_folder_on_any_server(): void {
		$root  = dirname( __DIR__, 3 );
		$files = glob( $root . '/dist/properx-portal-*.zip' );

		if ( ! $files ) {
			$this->markTestSkipped( 'Run scripts/build-wp-package.ps1 first.' );
		}

		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', file_get_contents( $root . '/integrations/wordpress/properx-portal/properx-portal.php' ), $version );
		$this->assertFileExists( $root . '/dist/properx-portal-' . $version[1] . '.zip' );

		$zip = new ZipArchive();
		$zip->open( $root . '/dist/properx-portal-' . $version[1] . '.zip' );
		$names = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}

		$this->assertContains( 'properx-portal/properx-portal.php', $names );
		$this->assertContains( 'properx-portal/uninstall.php', $names );
		$this->assertContains( 'properx-portal/includes/approval-email.php', $names );
		$this->assertContains( 'properx-portal/assets/logo-color.png', $names );

		foreach ( $names as $name ) {
			$this->assertStringStartsWith( 'properx-portal/', $name );
			$this->assertStringNotContainsString( '\\', $name );
		}
	}

	// Portal environment

	private function envExampleNames(): array {
		$root = dirname( __DIR__, 3 );

		preg_match_all( '/^(?:# )?([A-Z][A-Z0-9_]+)(?:=|$)/m', file_get_contents( $root . '/docs/wordpress/portal-env.example' ), $matches );

		return array_unique( $matches[1] );
	}

	public function test_every_variable_of_the_production_list_exists_in_the_portal(): void {
		$root  = dirname( __DIR__, 3 );
		$known = file_get_contents( $root . '/.env.example' );

		foreach ( glob( $root . '/config/*.php' ) as $config ) {
			$known .= file_get_contents( $config );
		}

		foreach ( $this->envExampleNames() as $name ) {
			$this->assertMatchesRegularExpression( '/\b' . $name . '\b/', $known, $name . ' is not read by the portal' );
		}
	}

	public function test_every_portal_setting_is_in_the_production_list(): void {
		$root = dirname( __DIR__, 3 );

		preg_match_all( '/^([A-Z][A-Z0-9_]+)=/m', file_get_contents( $root . '/.env.example' ), $matches );

		// Framework settings with a working default, and packages the portal does not use.
		$not_for_production = '/^(APP_LOCALE|APP_FALLBACK_LOCALE|APP_FAKER_LOCALE|APP_MAINTENANCE_DRIVER|BCRYPT_ROUNDS|LOG_STACK|LOG_DEPRECATIONS_CHANNEL|SESSION_PATH|SESSION_DOMAIN|BROADCAST_CONNECTION|FILESYSTEM_DISK|MEMCACHED_HOST|REDIS_.+|AWS_.+|VITE_APP_NAME)$/';

		foreach ( array_unique( $matches[1] ) as $name ) {
			if ( ! preg_match( $not_for_production, $name ) ) {
				$this->assertContains( $name, $this->envExampleNames(), $name . ' is missing from docs/wordpress/portal-env.example' );
			}
		}
	}

	public function test_the_production_list_holds_only_placeholders(): void {
		$text = file_get_contents( dirname( __DIR__, 3 ) . '/docs/wordpress/portal-env.example' );

		$this->assertDoesNotMatchRegularExpression( '/=\s*(whsec_|sk_live_|rk_live_|sk_test_|base64:|https:\/\/[a-z0-9-]+\.(keeney|properx))/i', $text );
		$this->assertDoesNotMatchRegularExpression( '/[0-9a-f]{32,}/i', $text );
	}
}

function properx_portal_activate_options_for_test() {
	add_option( PROPERX_PORTAL_OPTION, array( 'send_enabled' => false, 'approval_email_enabled' => false ) );
}
