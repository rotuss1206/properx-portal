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
		$GLOBALS['wp_requests'] = array();
		$GLOBALS['wp_response'] = array( 'code' => 200 );
		$_POST                 = array();
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
		$this->assertSame( array( 'properx_portal_send', 'properx_portal_send_cancellation', 'properx_portal_cron_heartbeat' ), $GLOBALS['unscheduled'] );
	}

	public function test_the_settings_page_leaves_the_header_and_tab_menu_to_gravity_forms(): void {
		$page = file_get_contents( dirname( __DIR__ ) . '/properx-portal/includes/admin-page.php' );

		$this->assertStringNotContainsString( 'GFSettings::page_header', $page );
		$this->assertStringNotContainsString( 'GFSettings::page_footer', $page );
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
			'test_state'       => 'ok',
			'test_time'        => 'November 5, 2026 1:35 pm',
			'test_message'     => '',
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
			'no secret'     => array( array( 'secret_set' => false ), 0, 'Paste the Secret' ),
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
			'no test'       => array( array( 'test_state' => 'none', 'test_time' => '' ), 5, 'Press Test connection' ),
			'stale test'    => array( array( 'test_state' => 'stale', 'test_time' => '' ), 5, 'changed since' ),
			'failed test'   => array( array( 'test_state' => 'failed', 'test_message' => 'The portal refused the signature (401).' ), 5, 'refused the signature' ),
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

	public function test_all_green_is_reported_as_passed_in_the_setup_order(): void {
		$items = properx_portal_checklist_items( $this->greenFacts() );
		$view  = properx_portal_checklist_view( $items );

		$this->assertTrue( $view['all_ok'] );
		$this->assertSame( $items, $view['items'] );
	}

	public function test_red_lines_come_first_and_keep_their_order(): void {
		$items = properx_portal_checklist_items( array_merge( $this->greenFacts(), array( 'test_state' => 'none', 'test_time' => '', 'environment' => 'local' ) ) );
		$view  = properx_portal_checklist_view( $items );

		$this->assertFalse( $view['all_ok'] );
		$this->assertSame( array( 'This is not a local environment', 'Test connection succeeded' ), array_column( array_slice( $view['items'], 0, 2 ), 'label' ) );
		$this->assertSame( 'Portal URL and secret are set', $view['items'][2]['label'] );
		$this->assertCount( 7, $view['items'] );
	}

	public function test_the_checklist_gives_the_time_of_the_test(): void {
		$green = properx_portal_checklist_items( $this->greenFacts() );
		$this->assertSame( 'Test connection succeeded (November 5, 2026 1:35 pm)', $green[5]['label'] );

		$failed = properx_portal_checklist_items( array_merge( $this->greenFacts(), array( 'test_state' => 'failed', 'test_message' => 'Reason.' ) ) );
		$this->assertSame( 'Test connection failed (November 5, 2026 1:35 pm)', $failed[5]['label'] );
		$this->assertSame( 'Reason.', $failed[5]['help'] );
	}

	// Secret and connection state on the page

	private function configure(): void {
		properx_portal_apply_settings_form( array( 'url' => 'https://portal.test', 'form_id' => '1', 'secret' => self::SECRET ) );
	}

	private function page(): string {
		ob_start();
		properx_portal_settings_page();

		return (string) ob_get_clean();
	}

	public function test_a_missing_secret_shows_not_set_with_no_placeholder(): void {
		$html = $this->page();

		$this->assertStringContainsString( '>Not set</span>', $html );
		$this->assertStringNotContainsString( '>Saved</span>', $html );
		$this->assertStringNotContainsString( 'placeholder="••', $html );
		$this->assertStringContainsString( 'Not checked yet.', $html );
		$this->assertStringContainsString( 'Paste the shared secret here. It is stored encrypted and never shown.', $html );
	}

	public function test_a_saved_secret_shows_the_badge_time_and_an_empty_field(): void {
		$this->configure();
		$html = $this->page();

		$this->assertStringContainsString( '>Saved</span>', $html );
		$this->assertStringContainsString( 'Last changed ' . properx_portal_format_time( time() ), $html );
		$this->assertStringContainsString( 'placeholder="••••••••••••••••"', $html );
		$this->assertStringContainsString( 'name="secret" value=""', $html );
		$this->assertStringContainsString( 'Paste a new value only to replace it.', $html );
	}

	public function test_saving_without_a_new_secret_keeps_the_time_of_the_last_change(): void {
		$this->configure();
		properx_portal_save_settings( array( 'secret_changed_at' => 1000 ) );

		properx_portal_apply_settings_form( array( 'url' => 'https://portal.test', 'form_id' => '1', 'secret' => '' ) );

		$this->assertSame( 1000, properx_portal_stored_settings()['secret_changed_at'] );
	}

	public function test_nothing_secret_reaches_the_page_in_any_state(): void {
		$this->configure();
		$blob = properx_portal_stored_settings()['secret'];

		foreach ( array( array( 'code' => 200 ), array( 'code' => 401 ), new WP_Error( 'cURL error ' . self::SECRET ) ) as $response ) {
			$GLOBALS['wp_response'] = $response;
			$_POST                  = array( 'properx_portal_test' => '1' );
			$html                   = $this->page();

			$this->assertStringNotContainsString( self::SECRET, $html );
			$this->assertStringNotContainsString( $blob, $html );
			$this->assertStringNotContainsString( properx_portal_connection_fingerprint(), $html );
			$this->assertStringNotContainsString( self::SECRET, json_encode( get_option( PROPERX_PORTAL_OPTION_TEST ) ) );
		}
	}

	public function test_saving_runs_the_test_and_the_result_stays_after_a_reload(): void {
		$_POST = array( 'properx_portal_save' => '1', 'url' => 'https://portal.test', 'form_id' => '1', 'secret' => self::SECRET );
		$this->page();
		$this->assertCount( 1, $GLOBALS['wp_requests'] );
		$this->assertSame( 'https://portal.test/api/intake/ping', $GLOBALS['wp_requests'][0][0] );

		$_POST = array();
		$html  = $this->page();

		$this->assertCount( 1, $GLOBALS['wp_requests'], 'A reload sends nothing.' );
		$this->assertStringContainsString( 'Connected — checked ' . properx_portal_format_time( time() ), $html );
		$this->assertStringContainsString( 'Test connection succeeded (' . properx_portal_format_time( time() ) . ')', $html );
	}

	public function test_a_failed_test_keeps_its_reason_and_time_after_a_reload(): void {
		$GLOBALS['wp_response'] = array( 'code' => 401 );
		$_POST                  = array( 'properx_portal_save' => '1', 'url' => 'https://portal.test', 'form_id' => '1', 'secret' => self::SECRET );
		$this->page();

		$_POST = array();
		$html  = $this->page();

		$this->assertStringContainsString( 'ppx-connection is-bad', $html );
		$this->assertStringContainsString( 'refused the signature (401)', $html );
		$this->assertStringContainsString( 'Checked ' . properx_portal_format_time( time() ), $html );
		$this->assertStringContainsString( 'Test connection failed (' . properx_portal_format_time( time() ) . ')', $html );
	}

	public function test_saving_without_url_or_secret_sends_nothing(): void {
		$_POST = array( 'properx_portal_save' => '1', 'url' => 'https://portal.test', 'form_id' => '1', 'secret' => '' );
		$this->page();

		$this->assertSame( array(), $GLOBALS['wp_requests'] );
	}

	public function test_a_result_for_an_older_url_no_longer_counts(): void {
		$this->configure();
		properx_portal_run_connection_test();
		$this->assertSame( 'ok', properx_portal_connection_status()['state'] );

		properx_portal_apply_settings_form( array( 'url' => 'https://other.test', 'form_id' => '1' ) );

		$this->assertSame( 'stale', properx_portal_connection_status()['state'] );
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

	private function email( string $first_name = 'Taylor' ): array {
		return properx_portal_approval_email( 'https://site.test/wp-login.php?action=rp&key=abc&login=x', 'https://site.test/plans/', 'https://site.test/wp-login.php', $first_name );
	}

	public function test_the_email_has_the_agreed_subject_links_and_both_parts(): void {
		$email = $this->email();

		$this->assertSame( 'Welcome to ProPerX Alliance — Your Membership Is Approved', $email['subject'] );

		foreach ( array( $email['html'], $email['text'] ) as $part ) {
			$this->assertStringContainsString( 'https://site.test/plans/', $part );
			$this->assertStringContainsString( 'https://my-benefits.net/2027-group-health-coverage-enrollment-form/', $part );
			$this->assertStringContainsString( '+1 (888) 388-1720', $part );
			$this->assertStringContainsString( 'action=rp', $part );
			$this->assertStringContainsString( 'How to enroll', str_replace( array( '&ldquo;', '&rdquo;' ), '', $part ) );
			$this->assertStringNotContainsString( 'Ready to enroll', $part );
			$this->assertStringNotContainsString( 'ProPerx', $part );
		}

		$this->assertStringContainsString( 'src="cid:' . PROPERX_PORTAL_LOGO_CID . '"', $email['html'] );
		$this->assertStringContainsString( 'alt="ProPerX Alliance"', $email['html'] );
		$this->assertStringNotContainsString( '<', $email['text'] );
	}

	public function test_the_email_greets_by_first_name_safely_and_has_a_fallback(): void {
		$email = $this->email( 'Taylor' );
		$this->assertStringContainsString( '>Hi Taylor,</p>', $email['html'] );
		$this->assertStringStartsWith( "Hi Taylor,
", $email['text'] );

		$this->assertStringStartsWith( "Hi there,
", $this->email( '  ' )['text'] );
		$this->assertStringContainsString( '>Hi there,</p>', $this->email( '' )['html'] );

		$html = $this->email( '<b>Bad</b>' )['html'];
		$this->assertStringNotContainsString( '<b>Bad', $html );
		$this->assertStringContainsString( '&lt;b&gt;Bad', $html );
	}

	public function test_the_phone_link_dials_the_shown_number(): void {
		$this->assertStringContainsString( 'href="tel:+18883881720"', $this->email()['html'] );
	}

	public function test_the_draft_for_the_client_says_the_same_as_the_plugin(): void {
		$email = $this->email();
		$draft = file_get_contents( dirname( __DIR__, 3 ) . '/docs/wordpress/emails.md' );

		$this->assertStringContainsString( $email['subject'], $draft );

		$sentences = array(
			'Hi Taylor,',
			'Your ProPerX Alliance membership application has been reviewed and approved. Here is what to do next.',
			'Once you are signed in, the 2027 plans page shows the "How to enroll" section.',
			"Compare the five plans and open the full summary for any you're considering. Find your monthly rate by your age and who you're covering. Then complete the enrollment form; have your plan choice and the details for anyone you're covering ready.",
			'Enrollment is subject to final plan approval and ProPerX Alliance membership requirements.',
			'Call us at +1 (888) 388-1720.',
		);

		foreach ( $sentences as $sentence ) {
			$this->assertStringContainsString( $sentence, $email['text'] );
			$this->assertStringContainsString( str_replace( 'Taylor', '{first name}', $sentence ), $draft );
		}
	}

	public function test_the_header_names_the_author_and_no_personal_address(): void {
		$header = file_get_contents( dirname( __DIR__ ) . '/properx-portal/properx-portal.php' );

		$this->assertMatchesRegularExpression( '/^ \* Author:\s+KEENEY$/m', $header );
		$this->assertStringNotContainsString( 'Author URI', $header );
		$this->assertStringNotContainsString( 'Plugin URI', $header );
		$this->assertDoesNotMatchRegularExpression( '/[a-z0-9._-]+@[a-z0-9-]+\.[a-z]{2,}/i', $header );
	}

	public function test_the_plugin_names_no_people(): void {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/properx-portal', FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$this->assertDoesNotMatchRegularExpression( '/\b(Kevin|Sergey|Cheverda|Vasyl)\b/i', file_get_contents( $file->getPathname() ), $file->getFilename() );
			}
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

	// Cancellations (the 24-month rule)

	private function canceledEntry(): array {
		$entry = array(
			'id'             => 42,
			'form_id'        => 1,
			'5.3'            => 'Avery',
			'5.6'            => 'Stone',
			'7'              => 'avery@example.test',
			'14'             => '12345678',
			'15'             => '2027-06-30',
			'transaction_id' => 'sub_Avery',
			'status'         => 'active',
			'payment_status' => 'Cancelled',
		);

		$GLOBALS['gf_entries'][42]                         = $entry;
		$GLOBALS['entry_meta'][42]['stripe_customer_id'] = 'cus_Avery';

		return $entry;
	}

	private function configureWith( array $extra ): void {
		properx_portal_apply_settings_form( array( 'url' => 'https://portal.test', 'form_id' => '1', 'secret' => self::SECRET ) + $extra );
	}

	public function test_sending_cancellations_is_off_by_default_and_has_its_own_switch(): void {
		$this->configureWith( array( 'send_enabled' => '1' ) );
		$this->assertFalse( properx_portal_can_send_cancellations() );

		$this->configureWith( array( 'cancel_send_enabled' => '1' ) );
		$this->assertTrue( properx_portal_can_send_cancellations() );
		$this->assertFalse( properx_portal_can_send(), 'the applications switch is separate' );

		$_POST = array();
		ob_start();
		properx_portal_settings_page();
		$page = ob_get_clean();
		$this->assertStringContainsString( 'name="cancel_send_enabled" value="1" checked', $page );
		$this->assertStringContainsString( 'Send cancellations to the portal', $page );
	}

	public function test_a_cancellation_keeps_its_moment_and_is_queued_only_when_switched_on(): void {
		$GLOBALS['wp_scheduled'] = array();
		$this->configure();
		$entry = $this->canceledEntry();

		properx_portal_on_canceled( $entry, array(), 'sub_Avery' );
		$this->assertIsInt( $GLOBALS['entry_meta'][42][ PROPERX_PORTAL_META_CANCELED_AT ] );
		$this->assertSame( array(), $GLOBALS['wp_scheduled'] );

		$first = $GLOBALS['entry_meta'][42][ PROPERX_PORTAL_META_CANCELED_AT ] = 1700000000;
		$this->configureWith( array( 'cancel_send_enabled' => '1' ) );
		properx_portal_on_canceled( $entry, array(), 'sub_Avery' );

		$this->assertSame( $first, $GLOBALS['entry_meta'][42][ PROPERX_PORTAL_META_CANCELED_AT ], 'the first moment stays' );
		$this->assertSame( PROPERX_PORTAL_CANCEL_HOOK, $GLOBALS['wp_scheduled'][0][1] );
		$this->assertSame( 42, $GLOBALS['wp_scheduled'][0][2][0] );

		// Another form is left alone.
		$GLOBALS['wp_scheduled'] = array();
		properx_portal_on_canceled( array( 'id' => 7, 'form_id' => 2 ) );
		$this->assertSame( array(), $GLOBALS['wp_scheduled'] );
	}

	public function test_the_cancellation_sends_only_the_email_the_moment_and_the_stripe_ids_signed(): void {
		$this->configureWith( array( 'cancel_send_enabled' => '1' ) );
		$this->canceledEntry();
		$GLOBALS['entry_meta'][42][ PROPERX_PORTAL_META_CANCELED_AT ] = 1700000000;
		$GLOBALS['wp_response'] = array( 'code' => 200, 'body' => '{"outcome":"canceled","member_profile_id":5}' );

		properx_portal_deliver_cancellation( 42 );

		[ $url, $args ] = $GLOBALS['wp_requests'][0];
		$this->assertSame( 'https://portal.test/api/intake/cancellation', $url );
		$this->assertSame(
			array( 'entry_id' => '42', 'email' => 'avery@example.test', 'canceled_at' => '2023-11-14T22:13:20+00:00', 'stripe_customer_id' => 'cus_Avery', 'stripe_subscription_id' => 'sub_Avery' ),
			json_decode( $args['body'], true )
		);
		$this->assertSame( hash_hmac( 'sha256', $args['headers']['X-Timestamp'] . '.' . $args['body'], self::SECRET ), $args['headers']['X-Signature'] );
		$this->assertStringNotContainsString( 'Stone', $args['body'] );
		$this->assertStringNotContainsString( '12345678', $args['body'] );

		$this->assertSame( 'sent', $GLOBALS['entry_meta'][42][ PROPERX_PORTAL_META_CANCEL_STATUS ] );
		$this->assertSame( array( 'Cancellation sent to portal: OK (the member is marked canceled; access until the paid period ends)', 'success' ), end( $GLOBALS['gf_notes'][42] ) );
	}

	public function test_a_failed_cancellation_retries_with_a_note_and_bad_fields_do_not(): void {
		$this->configureWith( array( 'cancel_send_enabled' => '1' ) );
		$this->canceledEntry();
		$GLOBALS['entry_meta'][42][ PROPERX_PORTAL_META_CANCELED_AT ] = 1700000000;
		$GLOBALS['wp_scheduled'] = array();

		$GLOBALS['wp_response'] = array( 'code' => 503, 'body' => '' );
		properx_portal_deliver_cancellation( 42 );
		$this->assertSame( 'failed', $GLOBALS['entry_meta'][42][ PROPERX_PORTAL_META_CANCEL_STATUS ] );
		$this->assertSame( 1, $GLOBALS['wp_scheduled'][0][2][1] );
		$this->assertStringContainsString( 'Retry 1 of 6 in 5 minutes', end( $GLOBALS['gf_notes'][42] )[0] );

		$GLOBALS['wp_scheduled'] = array();
		$GLOBALS['wp_response']  = array( 'code' => 422, 'body' => '{"fields":["canceled_at"]}' );
		properx_portal_deliver_cancellation( 42, 1 );
		$this->assertSame( array(), $GLOBALS['wp_scheduled'] );
		$this->assertStringContainsString( 'invalid fields: canceled_at. No more retries', end( $GLOBALS['gf_notes'][42] )[0] );

		// Switched off meanwhile: a note, nothing sent.
		$GLOBALS['wp_requests'] = array();
		$this->configure();
		properx_portal_deliver_cancellation( 42 );
		$this->assertSame( array(), $GLOBALS['wp_requests'] );
		$this->assertSame( 'Cancellation not sent to portal: sending cancellations is off.', end( $GLOBALS['gf_notes'][42] )[0] );
	}

	public function test_an_application_the_portal_blocks_is_noted_with_the_date(): void {
		$this->configureWith( array( 'send_enabled' => '1' ) );
		$entry                  = $this->canceledEntry();
		$GLOBALS['wp_response'] = array( 'code' => 200, 'body' => '{"outcome":"blocked","member_profile_id":5,"conflicts":["rejoin_wait"],"eligible_on":"2028-11-17"}' );

		$this->assertSame(
			array( 'status' => 'ok', 'reason' => 'not added: canceled less than 24 months ago, eligible again on 2028-11-17; ProPerX staff were told' ),
			properx_portal_post( $entry )
		);
	}

	// "My agency": handoff to the portal's agency enrollment

	private function agencyForm(): array {
		$fields = array();

		foreach ( array( 30 => 'ppx_joining', 31 => 'ppx_agency_first_name', 32 => 'ppx_agency_last_name', 33 => 'ppx_agency_email', 34 => 'ppx_agency_phone', 35 => 'ppx_agency_name', 36 => 'ppx_agency_headcount', 37 => 'ppx_partner_code' ) as $id => $label ) {
			$fields[] = (object) array( 'id' => $id, 'adminLabel' => $label, 'failed_validation' => false, 'validation_message' => '' );
		}

		return array( 'id' => 1, 'fields' => $fields );
	}

	private function postAgencyBlock( string $joining = 'agency' ): void {
		$_POST = array(
			'input_30' => $joining,
			'input_31' => 'Dana',
			'input_32' => 'Whitfield',
			'input_33' => 'dana@agency.test',
			'input_34' => '(330) 555-0188',
			'input_35' => 'Whitfield Insurance Group LLC',
			'input_36' => '10-49',
			'input_37' => ' midwestfmo ',
		);
	}

	private function validationResult(): array {
		return array( 'is_valid' => true, 'form' => $this->agencyForm(), 'failed_validation_page' => 1 );
	}

	private function fieldByLabel( array $form, string $label ): object {
		return properx_portal_field_by_label( $form, $label );
	}

	protected function tearDown(): void {
		$GLOBALS['properx_portal_agency_link'] = null;
	}

	public function test_the_agency_handoff_is_off_by_default_and_sends_nothing(): void {
		$this->configureWith( array( 'send_enabled' => '1' ) );
		$this->postAgencyBlock();

		$this->assertFalse( properx_portal_agency_handoff_enabled() );
		$this->assertTrue( properx_portal_agency_validation( $this->validationResult(), 'form-submit' )['is_valid'] );
		$this->assertSame( array(), $GLOBALS['wp_requests'] );
	}

	public function test_my_agency_is_sent_signed_and_the_visitor_goes_to_the_portal_link(): void {
		$this->configureWith( array( 'agency_handoff_enabled' => '1' ) );
		$this->postAgencyBlock();
		$GLOBALS['wp_response'] = array( 'code' => 200, 'body' => '{"outcome":"started","url":"https://portal.test/join/agency/start/abc"}' );

		$result = properx_portal_agency_validation( $this->validationResult(), 'form-submit' );

		$this->assertTrue( $result['is_valid'] );
		[ $url, $args ] = $GLOBALS['wp_requests'][0];
		$this->assertSame( 'https://portal.test/api/intake/agency-start', $url );
		$this->assertSame( hash_hmac( 'sha256', $args['headers']['X-Timestamp'] . '.' . $args['body'], self::SECRET ), $args['headers']['X-Signature'] );

		$body = json_decode( $args['body'], true );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $body['request_id'] );
		unset( $body['request_id'] );
		$this->assertSame(
			array( 'first_name' => 'Dana', 'last_name' => 'Whitfield', 'email' => 'dana@agency.test', 'phone' => '(330) 555-0188', 'agency_name' => 'Whitfield Insurance Group LLC', 'headcount' => '10-49', 'partner_code' => 'MIDWESTFMO' ),
			$body
		);

		$this->assertSame( array( 'redirect' => 'https://portal.test/join/agency/start/abc' ), properx_portal_agency_confirmation( array( 'type' => 'message' ), array( 'id' => 1 ), array( 'id' => 9 ) ) );

		properx_portal_agency_note( array( 'id' => 9 ), array( 'id' => 1 ) );
		$this->assertSame( 'Agency enrollment opened in the portal (one-time link, nothing charged on this site).', end( $GLOBALS['gf_notes'][9] )[0] );
		$this->assertStringNotContainsString( 'Dana', json_encode( $GLOBALS['gf_notes'][9] ) );
	}

	public function test_each_submission_has_its_own_request_id(): void {
		$this->configureWith( array( 'agency_handoff_enabled' => '1' ) );
		$this->postAgencyBlock();
		$GLOBALS['wp_response'] = array( 'code' => 200, 'body' => '{"outcome":"started","url":"https://portal.test/join/agency/start/abc"}' );

		properx_portal_agency_validation( $this->validationResult(), 'form-submit' );
		properx_portal_agency_validation( $this->validationResult(), 'form-submit' );

		$this->assertNotSame( json_decode( $GLOBALS['wp_requests'][0][1]['body'], true )['request_id'], json_decode( $GLOBALS['wp_requests'][1][1]['body'], true )['request_id'] );
	}

	public function test_just_me_validation_only_requests_and_other_forms_are_left_alone(): void {
		$this->configureWith( array( 'agency_handoff_enabled' => '1' ) );

		$this->postAgencyBlock( 'individual' );
		properx_portal_agency_validation( $this->validationResult(), 'form-submit' );

		$this->postAgencyBlock();
		properx_portal_agency_validation( $this->validationResult(), 'api-validate' );

		$other         = $this->validationResult();
		$other['form']['id'] = 2;
		properx_portal_agency_validation( $other, 'form-submit' );

		$invalid             = $this->validationResult();
		$invalid['is_valid'] = false;
		properx_portal_agency_validation( $invalid, 'form-submit' );

		$this->assertSame( array(), $GLOBALS['wp_requests'] );
		$this->assertSame( array( 'type' => 'message' ), properx_portal_agency_confirmation( array( 'type' => 'message' ), array( 'id' => 1 ), array( 'id' => 9 ) ) );
	}

	public function test_an_existing_member_gets_the_portal_s_message_on_the_email_field(): void {
		$this->configureWith( array( 'agency_handoff_enabled' => '1' ) );
		$this->postAgencyBlock();
		$GLOBALS['wp_response'] = array( 'code' => 200, 'body' => json_encode( array( 'outcome' => 'existing_member', 'message' => PROPERX_PORTAL_EMAIL_REFUSED ) ) );

		$result = properx_portal_agency_validation( $this->validationResult(), 'form-submit' );

		$this->assertFalse( $result['is_valid'] );
		$email = $this->fieldByLabel( $result['form'], 'ppx_agency_email' );
		$this->assertTrue( $email->failed_validation );
		$this->assertSame( PROPERX_PORTAL_EMAIL_REFUSED, $email->validation_message );
		$this->assertSame( array( 'type' => 'message' ), properx_portal_agency_confirmation( array( 'type' => 'message' ), array( 'id' => 1 ), array( 'id' => 9 ) ) );
	}

	public function test_the_refusal_text_is_the_portal_s_neutral_one(): void {
		$portal = file_get_contents( dirname( __DIR__, 3 ) . '/app/Services/Intake/AgencyHandoff.php' );

		$this->assertStringContainsString( '"' . PROPERX_PORTAL_EMAIL_REFUSED . '"', $portal );
		$this->assertStringNotContainsString( 'already has', PROPERX_PORTAL_EMAIL_REFUSED );
	}

	public function test_fields_the_portal_refuses_are_marked(): void {
		$this->configureWith( array( 'agency_handoff_enabled' => '1' ) );
		$this->postAgencyBlock();
		$GLOBALS['wp_response'] = array( 'code' => 422, 'body' => '{"message":"Validation failed.","fields":["phone"]}' );

		$result = properx_portal_agency_validation( $this->validationResult(), 'form-submit' );

		$this->assertFalse( $result['is_valid'] );
		$this->assertTrue( $this->fieldByLabel( $result['form'], 'ppx_agency_phone' )->failed_validation );
		$this->assertFalse( $this->fieldByLabel( $result['form'], 'ppx_agency_email' )->failed_validation );
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function unreachablePortals(): array {
		return array(
			'no connection'   => array( array( 'error' => true ) ),
			'switched off'    => array( array( 'code' => 404 ) ),
			'bad signature'   => array( array( 'code' => 401 ) ),
			'server error'    => array( array( 'code' => 500 ) ),
			'a foreign link'  => array( array( 'code' => 200, 'body' => '{"outcome":"started","url":"https://evil.test/join/agency/start/abc"}' ) ),
			'not a start url' => array( array( 'code' => 200, 'body' => '{"outcome":"started","url":"https://portal.test/member"}' ) ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unreachablePortals' )]
	public function test_a_portal_that_does_not_answer_well_stops_the_form_with_a_clear_message( array $response ): void {
		$this->configureWith( array( 'agency_handoff_enabled' => '1' ) );
		$this->postAgencyBlock();
		$GLOBALS['wp_response'] = isset( $response['error'] ) ? new WP_Error( 'cURL error 28: timed out' ) : $response;

		$result = properx_portal_agency_validation( $this->validationResult(), 'form-submit' );

		$this->assertFalse( $result['is_valid'] );
		$joining = $this->fieldByLabel( $result['form'], 'ppx_joining' );
		$this->assertTrue( $joining->failed_validation );
		$this->assertSame( PROPERX_PORTAL_UNREACHABLE, $joining->validation_message );
		$this->assertNull( $GLOBALS['properx_portal_agency_link'] );
	}

	// /apply form changes ("Apply /apply form changes")

	private function formBefore(): array {
		return json_decode( file_get_contents( __DIR__ . '/fixtures/apply-form-before.json' ), true );
	}

	private function fieldOf( array $form, string $key, $value ): ?array {
		$at = properx_portal_index_of( $form['fields'], $key, $value );

		return $at < 0 ? null : $form['fields'][ $at ];
	}

	public function test_the_form_changes_put_the_choice_and_the_agency_block_on_top(): void {
		$plan = properx_portal_plan_form_changes( $this->formBefore() );
		$form = $plan['form'];

		$this->assertSame( array(), $plan['problems'] );
		$this->assertSame(
			array( 'ppx_joining', 'ppx_partner_code', 'ppx_agency_heading', 'ppx_agency_first_name', 'ppx_agency_last_name', 'ppx_agency_email', 'ppx_agency_phone', 'ppx_agency_name', 'ppx_agency_headcount', 'ppx_agency_next' ),
			array_column( array_slice( $form['fields'], 0, 10 ), 'adminLabel' )
		);

		$joining = $this->fieldOf( $form, 'adminLabel', 'ppx_joining' );
		$this->assertSame( 33, $joining['id'] );
		$this->assertSame( array( 'individual', 'agency' ), array_column( $joining['choices'], 'value' ) );
		$this->assertTrue( $joining['choices'][0]['isSelected'] );
		$this->assertSame( 'join', $joining['inputName'] );
		$this->assertTrue( $joining['allowsPrepopulate'] );
		$this->assertSame( 'code', $this->fieldOf( $form, 'adminLabel', 'ppx_partner_code' )['inputName'] );
		$this->assertSame( array( '2-9', '10-49', '50+' ), array_column( $this->fieldOf( $form, 'adminLabel', 'ppx_agency_headcount' )['choices'], 'value' ) );

		// The agency block shows for "My agency", the partner code for both.
		$this->assertSame( array( array( 'fieldId' => '33', 'operator' => 'is', 'value' => 'agency' ) ), $this->fieldOf( $form, 'adminLabel', 'ppx_agency_email' )['conditionalLogic']['rules'] );
		$this->assertArrayNotHasKey( 'conditionalLogic', $this->fieldOf( $form, 'adminLabel', 'ppx_partner_code' ) );
		$this->assertStringContainsString( '[properx_pricing view="apply-tiers"]', $this->fieldOf( $form, 'adminLabel', 'ppx_agency_next' )['content'] );
	}

	public function test_the_individual_fields_show_for_just_me_and_license_types_go(): void {
		$form = properx_portal_plan_form_changes( $this->formBefore() )['form'];
		$just = array( 'fieldId' => '33', 'operator' => 'is', 'value' => 'individual' );

		foreach ( array_keys( PROPERX_PORTAL_FORM_FIELDS ) as $id ) {
			$this->assertSame( $just, $this->fieldOf( $form, 'id', $id )['conditionalLogic']['rules'][0], "field $id" );
		}

		$this->assertNull( $this->fieldOf( $form, 'id', 17 ) );
		$this->assertSame( 'ppx_npn', $this->fieldOf( $form, 'id', 14 )['adminLabel'] );
		$this->assertTrue( $this->fieldOf( $form, 'id', 16 )['isRequired'] );
		$this->assertTrue( $this->fieldOf( $form, 'id', 15 )['isRequired'], 'the license expiration date stays (default until the client decides)' );

		$payment = $this->fieldOf( $form, 'id', 29 );
		$this->assertSame( array( 'credit', 'debit', 'ach' ), array_column( $payment['choices'], 'value' ) );

		$surcharge = $this->fieldOf( $form, 'id', 30 );
		$this->assertSame( '{Membership:28} * 0.029', $surcharge['calculationFormula'] );
		$this->assertSame( array( $just, array( 'fieldId' => '29', 'operator' => 'is', 'value' => 'credit' ) ), $surcharge['conditionalLogic']['rules'] );
		$this->assertSame( array( $just, array( 'fieldId' => '29', 'operator' => 'is', 'value' => 'ach' ) ), $this->fieldOf( $form, 'id', 31 )['conditionalLogic']['rules'] );

		// The order summary sits right after the payment details.
		$at = properx_portal_index_of( $form['fields'], 'id', 19 );
		$this->assertSame( 'ppx_order_summary', $form['fields'][ $at + 1 ]['adminLabel'] );

		foreach ( PROPERX_PORTAL_AGREEMENTS as $id => $agreement ) {
			$this->assertSame( $agreement['text'], $this->fieldOf( $form, 'id', $id )['checkboxLabel'] );
		}
	}

	public function test_emails_go_for_just_me_and_my_agency_has_its_own_confirmation(): void {
		$form = properx_portal_plan_form_changes( $this->formBefore() )['form'];

		foreach ( $form['notifications'] as $key => $notification ) {
			if ( 'ppxpaymentmismatch' !== $key ) {
				$this->assertSame( array( array( 'fieldId' => '33', 'operator' => 'is', 'value' => 'individual' ) ), $notification['conditionalLogic']['rules'], $notification['name'] );
			}
		}

		$mismatch = $form['notifications']['ppxpaymentmismatch'];
		$this->assertSame( PROPERX_PORTAL_MISMATCH_EVENT, $mismatch['event'] );
		$this->assertSame( 'staff@example.org', $mismatch['to'], 'the staff email\'s recipients' );
		$this->assertStringNotContainsString( '{Name', $mismatch['message'] . $mismatch['subject'] );

		$this->assertSame( 'agency', $form['confirmations']['ppxagencyfallback']['conditionalLogic']['rules'][0]['value'] );
		$this->assertFalse( $form['confirmations']['ppxagencyfallback']['isDefault'] );
		$this->assertStringContainsString( 'ppx-apply', $form['cssClass'] );
	}

	public function test_running_the_changes_again_changes_nothing(): void {
		$first  = properx_portal_plan_form_changes( $this->formBefore() );
		$second = properx_portal_plan_form_changes( $first['form'] );

		$this->assertNotEmpty( $first['changes'] );
		$this->assertSame( array(), $second['changes'] );
		$this->assertSame( $first['form'], $second['form'] );
	}

	public function test_another_form_or_a_changed_field_stops_everything(): void {
		$form = $this->formBefore();
		$form['fields'][ properx_portal_index_of( $form['fields'], 'id', 29 ) ]['type'] = 'select';
		unset( $form['fields'][ properx_portal_index_of( $form['fields'], 'id', 24 ) ] );

		$plan = properx_portal_plan_form_changes( $form );

		$this->assertSame( array(), $plan['changes'] );
		$this->assertSame( $form, $plan['form'] );
		$this->assertContains( 'Field 29 is a select, expected a radio.', $plan['problems'] );
		$this->assertCount( 2, $plan['problems'] );
	}

	public function test_the_changes_name_fields_and_values_without_entry_data(): void {
		$changes = properx_portal_plan_form_changes( $this->formBefore() )['changes'];

		$this->assertContains( 'Field 17 "License Type(s) Held" removed (entries keep their values)', $changes );
		$this->assertContains( 'New field 33 "Who\'s joining?" (radio) at the top', $changes );
		$this->assertContains( 'Notification "User Pending Confirmation": send only for "Just me"', $changes );
	}

	// Prices and the founding cutoff

	public function test_prices_switch_at_midnight_eastern_on_january_1_2027(): void {
		$before = ( new DateTimeImmutable( '2026-12-31 23:59:59', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp();
		$after  = $before + 1;

		$this->assertSame( 169, properx_portal_price( 'individual', $before ) );
		$this->assertSame( 199, properx_portal_price( 'individual', $after ) );
		$this->assertSame( 129, properx_portal_price( 'agency_enterprise', $before ) );
		$this->assertSame( 139, properx_portal_price( 'agency_enterprise', $after ) );
	}

	public function test_agency_prices_and_the_cutoff_match_the_portal(): void {
		$config = file_get_contents( dirname( __DIR__, 3 ) . '/config/agency.php' );

		foreach ( array( '2to9' => 'agency', '10to49' => 'agency_plus', '50plus' => 'agency_enterprise' ) as $key => $plan ) {
			preg_match( "/'agency_{$key}' => \[.*?'rates' => \['founding' => (\d+), 'standard' => (\d+)\]/s", $config, $rates );
			$this->assertSame( (int) $rates[1], PROPERX_PORTAL_PRICES[ $plan ]['founding'] * 100, $plan );
			$this->assertSame( (int) $rates[2], PROPERX_PORTAL_PRICES[ $plan ]['standard'] * 100, $plan );
		}

		$this->assertStringContainsString( "'founding_cutoff' => '" . PROPERX_PORTAL_FOUNDING_UNTIL . "'", $config );
		$this->assertStringContainsString( "'founding_cutoff_timezone' => '" . PROPERX_PORTAL_PRICE_TIMEZONE . "'", $config );
	}

	public function test_the_plan_and_the_choice_show_today_s_prices(): void {
		properx_portal_apply_settings_form( array( 'form_id' => '1' ) );
		$form   = properx_portal_plan_form_changes( $this->formBefore() )['form'];
		$fields = array_map( fn ( $field ) => (object) $field, $form['fields'] );
		$before = ( new DateTimeImmutable( '2026-12-01', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp();

		$priced = properx_portal_apply_prices( array( 'id' => 1, 'fields' => $fields ), $before );
		$plan   = properx_portal_field_by_label( $priced, 'ppx_plan' );
		$this->assertSame( '$169.00', $plan->choices[0]['price'] );
		$this->assertStringContainsString( '<s>$199/yr</s>', $plan->choices[0]['text'] );
		$this->assertStringContainsString( 'From $129 per person per year', properx_portal_field_by_label( $priced, 'ppx_joining' )->choices[1]['text'] );

		$fields = array_map( fn ( $field ) => (object) $field, $form['fields'] );
		$after  = properx_portal_apply_prices( array( 'id' => 1, 'fields' => $fields ), $before + 86400 * 60 );
		$this->assertSame( '$199.00', properx_portal_field_by_label( $after, 'ppx_plan' )->choices[0]['price'] );
		$this->assertStringNotContainsString( '<s>', properx_portal_field_by_label( $after, 'ppx_plan' )->choices[0]['text'] );
		$this->assertStringContainsString( 'From $139', properx_portal_field_by_label( $after, 'ppx_joining' )->choices[1]['text'] );
	}

	public function test_the_tier_box_lists_today_s_rates(): void {
		$before = ( new DateTimeImmutable( '2026-12-01', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp();

		$html = properx_portal_pricing_shortcode( array( 'view' => 'apply-tiers' ), '', '', $before );
		$this->assertStringContainsString( '<strong>$159</strong>', $html );
		$this->assertStringContainsString( 'Founding rates for agencies that join by December 31, 2026', $html );

		$html = properx_portal_pricing_shortcode( array( 'view' => 'apply-tiers' ), '', '', $before + 86400 * 60 );
		$this->assertStringContainsString( '<strong>$179</strong>', $html );
		$this->assertStringNotContainsString( 'Founding', $html );
	}

	public function test_the_npn_needs_7_to_10_digits(): void {
		properx_portal_apply_settings_form( array( 'form_id' => '1' ) );
		$field = (object) array( 'id' => 14, 'adminLabel' => 'ppx_npn' );
		$ok    = array( 'is_valid' => true, 'message' => '' );

		$this->assertTrue( properx_portal_validate_npn( $ok, '1234567', array( 'id' => 1 ), $field )['is_valid'] );
		$this->assertTrue( properx_portal_validate_npn( $ok, '1234567890', array( 'id' => 1 ), $field )['is_valid'] );
		$this->assertSame( 'Enter your NPN: 7 to 10 digits, numbers only.', properx_portal_validate_npn( $ok, '123456', array( 'id' => 1 ), $field )['message'] );
		$this->assertFalse( properx_portal_validate_npn( $ok, '12345678901', array( 'id' => 1 ), $field )['is_valid'] );
		$this->assertTrue( properx_portal_validate_npn( $ok, '12', array( 'id' => 2 ), $field )['is_valid'], 'other forms are left alone' );
	}

	// The card type check after payment

	public function test_stripe_payment_methods_are_read_as_credit_debit_or_bank(): void {
		$this->assertSame( 'credit', properx_portal_payment_kind( array( 'type' => 'card', 'card' => array( 'funding' => 'credit' ) ) ) );
		$this->assertSame( 'debit', properx_portal_payment_kind( array( 'type' => 'card', 'card' => array( 'funding' => 'debit' ) ) ) );
		$this->assertSame( 'debit', properx_portal_payment_kind( array( 'type' => 'card', 'card' => array( 'funding' => 'prepaid' ) ) ) );
		$this->assertSame( 'ach', properx_portal_payment_kind( array( 'type' => 'us_bank_account' ) ) );
		$this->assertSame( 'unknown', properx_portal_payment_kind( array( 'type' => 'card', 'card' => array( 'funding' => 'unknown' ) ) ) );
		$this->assertSame( 'unknown', properx_portal_payment_kind( null ) );
	}

	public function test_a_different_payment_method_is_a_mismatch_with_a_note_and_no_card_details(): void {
		$this->assertSame( array( 'mismatch' => false, 'note' => 'Payment method check: OK (credit card).' ), properx_portal_payment_check_result( 'credit', 'credit' ) );

		$missed = properx_portal_payment_check_result( 'debit', 'credit' );
		$this->assertTrue( $missed['mismatch'] );
		$this->assertSame( 'Payment method check: chose debit card, paid with credit card. The 2.9% credit card surcharge was not charged.', $missed['note'] );

		$extra = properx_portal_payment_check_result( 'credit', 'debit' );
		$this->assertTrue( $extra['mismatch'] );
		$this->assertStringContainsString( 'consider refunding it', $extra['note'] );

		$this->assertTrue( properx_portal_payment_check_result( 'ach', 'debit' )['mismatch'] );
		$this->assertFalse( properx_portal_payment_check_result( 'ach', 'unknown' )['mismatch'] );
	}

	public function test_older_entries_map_their_payment_texts(): void {
		$this->assertSame( 'credit', PROPERX_PORTAL_PAYMENT_KINDS['Credit Card (3% surcharge applies)'] );
		$this->assertSame( 'ach', PROPERX_PORTAL_PAYMENT_KINDS['ACH Direct Debit'] );
	}

	// Intake: the partner code goes along for reporting

	public function test_the_partner_code_is_sent_with_the_application_and_older_entries_send_none(): void {
		$GLOBALS['gf_forms'][1] = array( 'id' => 1, 'fields' => array( (object) array( 'id' => 34, 'adminLabel' => 'ppx_partner_code' ) ) );
		$entry                  = array( 'id' => 50, 'form_id' => 1, '34' => ' midwestfmo ', '17.1' => 'Life', 'transaction_id' => 'sub_X1' );

		$payload = properx_portal_payload( $entry );
		$this->assertSame( 'MIDWESTFMO', $payload['partner_code'] );
		$this->assertSame( array( 'Life' ), $payload['license_types'] );

		$GLOBALS['gf_forms'] = array();
		$this->assertNull( properx_portal_payload( $entry )['partner_code'] );
	}

	public function test_an_empty_headcount_starts_from_the_smallest_tier(): void {
		$this->configureWith( array( 'agency_handoff_enabled' => '1' ) );
		$this->postAgencyBlock();
		unset( $_POST['input_36'] );
		$GLOBALS['wp_response'] = array( 'code' => 200, 'body' => '{"outcome":"started","url":"https://portal.test/join/agency/start/abc"}' );

		properx_portal_agency_validation( $this->validationResult(), 'form-submit' );

		$this->assertSame( '2-9', json_decode( $GLOBALS['wp_requests'][0][1]['body'], true )['headcount'] );
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
