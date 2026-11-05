<?php
/**
 * One approval email instead of two. While the "Approval email" switch is on:
 *
 * - the theme's message (send_activation_email_on_role_change on set_user_role) is unhooked;
 * - the KEENEY plugin's "Approve & Activate" handler, which sends its own message directly, is
 *   replaced by one that does the same checks and steps without that message;
 * - this plugin sends its email whenever a Pending Member becomes an Active Member, by that
 *   button or by changing the role on the Users screen.
 *
 * Neither the theme nor the KEENEY plugin is edited. With the switch off nothing here runs and both
 * old messages go out as before. The body greets the member by first name and otherwise only says
 * what to do next.
 *
 * The HTML and text parts and the logo reach the message through phpmailer_init, which WordPress
 * and Gravity SMTP's SMTP and PHP Mail connections fire. Its API connections (SendGrid and the like)
 * do not: there the mail goes out as HTML only, with the logo's alt text in place of the picture.
 */

defined( 'ABSPATH' ) || exit;

const PROPERX_PORTAL_ROLE_PENDING = 'pending_member';
const PROPERX_PORTAL_ROLE_ACTIVE  = 'active_member';
const PROPERX_PORTAL_LOGO_CID     = 'properx-portal-logo';

/** As on the plans page, in its "Need help?" line. */
const PROPERX_PORTAL_PHONE         = '+1 (888) 388-1720';
const PROPERX_PORTAL_PLANS_PATH    = '2027-health-plan-options';
const PROPERX_PORTAL_ENROLLMENT_URL = 'https://my-benefits.net/2027-group-health-coverage-enrollment-form/';

add_action( 'init', 'properx_portal_take_over_approval_email' );

function properx_portal_take_over_approval_email() {
	if ( ! properx_portal_approval_email_enabled() ) {
		return;
	}

	remove_action( 'set_user_role', 'send_activation_email_on_role_change', 10 );
	remove_action( 'admin_post_properx_activate_member', 'properx_handle_activate_member' );

	add_action( 'admin_post_properx_activate_member', 'properx_portal_handle_activate_member' );
	add_action( 'set_user_role', 'properx_portal_on_role_change', 10, 3 );
	add_action( 'admin_notices', 'properx_portal_approval_notice' );
}

/**
 * The same as the KEENEY plugin's properx_handle_activate_member(), including the nonce and the
 * address it returns to; the email comes from properx_portal_on_role_change() through set_role().
 */
function properx_portal_handle_activate_member() {
	if ( ! current_user_can( 'edit_users' ) ) {
		wp_die( esc_html__( 'You are not authorized to perform this action.' ) );
	}

	$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

	if ( ! $user_id || ! check_admin_referer( 'properx_activate_' . $user_id ) ) {
		wp_die( esc_html__( 'Security check failed. Please try again from the Users list.' ) );
	}

	$user = get_userdata( $user_id );

	if ( ! $user || ! in_array( PROPERX_PORTAL_ROLE_PENDING, (array) $user->roles, true ) ) {
		wp_die( esc_html__( 'This user is not currently a Pending Member.' ) );
	}

	$GLOBALS['properx_portal_approval_sent'] = null;

	$user->set_role( PROPERX_PORTAL_ROLE_ACTIVE );

	$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'users.php' );
	$flag     = false === $GLOBALS['properx_portal_approval_sent'] ? 'properx_portal_email_failed' : 'properx_activated';

	wp_safe_redirect( add_query_arg( $flag, '1', $redirect ) );
	exit;
}

function properx_portal_approval_notice() {
	if ( isset( $_GET['properx_portal_email_failed'] ) && current_user_can( 'edit_users' ) ) {
		echo '<div class="notice notice-error"><p>The member was activated, but the approval email could not be sent. They can use "Lost your password?" on the sign-in page; check the site\'s mail settings.</p></div>';
	}
}

function properx_portal_on_role_change( $user_id, $new_role, $old_roles ) {
	if ( PROPERX_PORTAL_ROLE_ACTIVE !== $new_role || ! in_array( PROPERX_PORTAL_ROLE_PENDING, (array) $old_roles, true ) ) {
		return;
	}

	$user = get_userdata( $user_id );

	if ( $user ) {
		$GLOBALS['properx_portal_approval_sent'] = properx_portal_send_approval_email( $user );
	}
}

/**
 * @return bool Whether it was handed to the mail system.
 */
function properx_portal_send_approval_email( WP_User $user ) {
	$key = get_password_reset_key( $user );

	if ( is_wp_error( $key ) ) {
		error_log( 'ProPerX Portal: no password link for user ' . (int) $user->ID );

		return false;
	}

	$plans_page = get_page_by_path( PROPERX_PORTAL_PLANS_PATH );

	$email = properx_portal_approval_email(
		network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' ),
		$plans_page ? get_permalink( $plans_page ) : home_url( '/' . PROPERX_PORTAL_PLANS_PATH . '/' ),
		wp_login_url(),
		(string) $user->first_name
	);

	$add_parts = function ( $mailer ) use ( $email ) {
		$mailer->AltBody = $email['text'];
		$mailer->addEmbeddedImage( plugin_dir_path( PROPERX_PORTAL_PLUGIN_FILE ) . 'assets/logo-color.png', PROPERX_PORTAL_LOGO_CID, 'properx-alliance.png', 'base64', 'image/png' );
	};
	$from_name = fn () => 'ProPerX Alliance';

	add_action( 'phpmailer_init', $add_parts );
	add_filter( 'wp_mail_from_name', $from_name );

	$sent = wp_mail( $user->user_email, $email['subject'], $email['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );

	remove_filter( 'wp_mail_from_name', $from_name );
	remove_action( 'phpmailer_init', $add_parts );

	if ( ! $sent ) {
		error_log( 'ProPerX Portal: approval email not sent for user ' . (int) $user->ID );
	}

	return (bool) $sent;
}

/**
 * The email. Pending client approval: the same text is in docs/wordpress/emails.md.
 * The steps of the third item come from the members' block of the plans page ("Ready to enroll?").
 *
 * @return array{subject: string, html: string, text: string}
 */
function properx_portal_approval_email( $password_url, $plans_url, $login_url, $first_name = '' ) {
	$navy  = '#14315c';
	$ink   = '#222222';
	$muted = '#5b6b7f';
	$p     = 'margin:0 0 10px;font-size:15px;line-height:1.55;color:' . $ink . ';';
	$small = 'margin:0;font-size:13px;line-height:1.5;color:' . $muted . ';';
	$h     = 'margin:0 0 6px;font-size:15px;font-weight:bold;color:' . $navy . ';';
	$a     = 'color:' . $navy . ';font-weight:bold;';
	$cell  = 'padding:0 0 22px;';

	$enrollment = PROPERX_PORTAL_ENROLLMENT_URL;
	$phone      = PROPERX_PORTAL_PHONE;
	$tel        = 'tel:+' . preg_replace( '/\D/', '', $phone );
	$name       = trim( (string) $first_name );
	$greeting   = 'Hi ' . ( '' === $name ? 'there' : $name ) . ',';

	$html = '<!doctype html><html lang="en"><body style="margin:0;padding:0;background:#f4f6f9;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;"><tr><td align="center" style="padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #e2e6ea;border-radius:8px;">'
		. '<tr><td style="padding:28px 32px 8px;"><img src="cid:' . PROPERX_PORTAL_LOGO_CID . '" width="180" alt="ProPerX Alliance" style="display:block;border:0;font-size:18px;font-weight:bold;color:' . $navy . ';"></td></tr>'
		. '<tr><td style="padding:16px 32px 8px;">'
		. '<p style="' . $p . '">' . esc_html( $greeting ) . '</p>'
		. '<p style="' . $p . '">Your ProPerX Alliance membership application has been reviewed and approved. Here is what to do next.</p>'
		. '</td></tr>'
		. '<tr><td style="padding:8px 32px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
		. '<tr><td style="' . $cell . '"><p style="' . $h . '">1. Set your password and sign in</p>'
		. '<p style="' . $p . '">Once you are signed in, the 2027 plans page shows the &ldquo;Ready to enroll?&rdquo; section.</p>'
		. '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:6px;background:' . $navy . ';"><a href="' . esc_url( $password_url ) . '" style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;">Set your password</a></td></tr></table>'
		. '<p style="' . $small . 'padding-top:10px;">If this link has expired, use &ldquo;Lost your password?&rdquo; on the <a href="' . esc_url( $login_url ) . '" style="color:' . $navy . ';">sign-in page</a> with the email address you applied with.</p></td></tr>'
		. '<tr><td style="' . $cell . '"><p style="' . $h . '">2. See the 2027 health plans</p>'
		. '<p style="' . $p . '"><a href="' . esc_url( $plans_url ) . '" style="' . $a . '">2027 Health Plan Options</a></p></td></tr>'
		. '<tr><td style="' . $cell . '"><p style="' . $h . '">3. Choose a plan and enroll</p>'
		. '<p style="' . $p . '">Compare the five plans and open the full summary for any you&rsquo;re considering. Find your monthly rate by your age and who you&rsquo;re covering. Then complete the enrollment form; have your plan choice and the details for anyone you&rsquo;re covering ready.</p>'
		. '<p style="' . $p . '"><a href="' . esc_url( $enrollment ) . '" style="' . $a . '">Open the 2027 enrollment form</a></p>'
		. '<p style="' . $small . '">Enrollment is subject to final plan approval and ProPerX Alliance membership requirements.</p></td></tr>'
		. '<tr><td style="' . $cell . '"><p style="' . $h . '">4. Questions?</p>'
		. '<p style="' . $p . 'margin-bottom:0;">Call us at <a href="' . esc_url( $tel, array( 'tel' ) ) . '" style="' . $a . '">' . esc_html( $phone ) . '</a>.</p></td></tr>'
		. '</table></td></tr>'
		. '</table></td></tr></table></body></html>';

	$text = "{$greeting}\n\n"
		. "Your ProPerX Alliance membership application has been reviewed and approved. Here is what to do next.\n\n"
		. "1. Set your password and sign in\n"
		. "Once you are signed in, the 2027 plans page shows the \"Ready to enroll?\" section.\n"
		. "Set your password: {$password_url}\n"
		. "If this link has expired, use \"Lost your password?\" on the sign-in page ({$login_url}) with the email address you applied with.\n\n"
		. "2. See the 2027 health plans\n"
		. "{$plans_url}\n\n"
		. "3. Choose a plan and enroll\n"
		. "Compare the five plans and open the full summary for any you're considering. Find your monthly rate by your age and who you're covering. Then complete the enrollment form; have your plan choice and the details for anyone you're covering ready.\n"
		. "Open the 2027 enrollment form: {$enrollment}\n"
		. "Enrollment is subject to final plan approval and ProPerX Alliance membership requirements.\n\n"
		. "4. Questions?\n"
		. "Call us at {$phone}.\n";

	return array(
		'subject' => 'Welcome to ProPerX Alliance — Your Membership Is Approved',
		'html'    => $html,
		'text'    => $text,
	);
}
