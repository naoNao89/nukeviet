<?php

/**
 * NukeViet Content Management System
 * @version 4.x
 * @author VINADES.,JSC <contact@vinades.vn>
 * @copyright (C) 2009-2021 VINADES.,JSC. All rights reserved
 * @license GNU/GPL version 2 or any later version
 * @see https://github.com/nukeviet The NukeViet CMS GitHub project
 */

if (!defined('NV_MAINFILE')) {
    exit('Stop!!!');
}

$lang_translator['author'] = 'VINADES.,JSC <contact@vinades.vn>';
$lang_translator['createdate'] = '04/03/2010, 15:22';
$lang_translator['copyright'] = '@Copyright (C) 2009-2021 VINADES.,JSC. All rights reserved';
$lang_translator['info'] = '';
$lang_translator['langtype'] = 'lang_module';

$lang_module['confirm_password'] = 'Enter the password confirmation to continue';
$lang_module['confirm_password_info'] = 'To use this feature, you need to confirm your password, enter your password in the box below and click Confirm';
$lang_module['confirm'] = 'Confirm';
$lang_module['secretkey'] = 'Secret Key';
$lang_module['wrong_confirm'] = 'Confirmation code is incorrect, please re-enter';
$lang_module['cfg_step1'] = 'Step 1: Scan the QR code';
$lang_module['cfg_step1_manual'] = 'Scan QR-code above with Two-Factor Authentication software (for example Google Authenticator) on your phone. If you can not use the camera, please';
$lang_module['cfg_step1_manual1'] = 'enter this code';
$lang_module['cfg_step1_manual2'] = 'manual';
$lang_module['cfg_step1_note'] = 'Note secrecy code';
$lang_module['cfg_step2_info'] = 'After scanning the code or manually enter successful, the application will display a 6-digit string, that string entry below to confirm';
$lang_module['cfg_step2_info2'] = 'Verification 6-digit display on the screen of the app on your phone';
$lang_module['cfg_step2'] = 'Step 2: Enter the code from the app';
$lang_module['title_2step'] = 'Two-step authentication';
$lang_module['status_2step'] = 'Two-step authentication is';
$lang_module['active_2step'] = 'ON';
$lang_module['deactive_2step'] = 'OFF';
$lang_module['backupcode_2step'] = 'You have <strong>%d</strong> unused backup codes';
$lang_module['backupcode_2step_view'] = 'See backup codes';
$lang_module['backupcode_2step_note'] = 'Note: Save a backup code carefully to prevent the loss of the phone you can use this code to access the account. If you forget it and lose your phone you can not log into your account';
$lang_module['turnoff2step'] = 'Turn off two-step authentication';
$lang_module['turnon2step'] = 'Setting up two-step authentication';
$lang_module['creat_other_code'] = 'Create new backup codes';
$lang_module['email_subject'] = 'Privacy notice';
$lang_module['email_2step_on'] = 'Your <strong>%4$s</strong> account at <a href="%5$s"><strong>%6$s</strong></a> has just enabled Two-Factor Authentication. Information:<br /><br />- Time: <strong>%1$s</strong><br />- IP: <strong>%2$s</strong><br />- Browser: <strong>%3$s</strong><br /><br />If this is you, ignore this email. If this is not you, your account is most likely stolen. Please contact the site administrator for assistance';
$lang_module['email_2step_off'] = 'Your <strong>%5$s</strong> account at <a href="%6$s"><strong>%7$s</strong></a> has just disabled Two-Factor Authentication. Information:<br /><br />- Time: <strong>%1$s</strong><br />- IP: <strong>%2$s</strong><br />- Browser: <strong>%3$s</strong><br /><br />If this is you, ignore this email. If this is not you, please check your personal information at <a href="%4$s">%4$s</a>';
$lang_module['email_code_renew'] = 'Your <strong>%5$s</strong> account at <a href="%6$s"><strong>%7$s</strong></a> has just recreated the backup code. Information:<br /><br />- Time: <strong>%1$s</strong><br />- IP: <strong>%2$s</strong><br />- Browser: <strong>%3$s</strong><br /><br />If this is you, ignore this email. If this is not you, please check your personal information at <a href="%4$s">%4$s</a>';

$lang_module['change_2step_notvalid'] = 'Your account doesn\'t have a password, so Two-Step Authentication can\'t be changed. Please create a password and then return to this page. Please <a class="btn btn-primary btn-xs" href="%s">click here</a> to create a password';

// OneAuth Integration
$lang_module['oneauth_title'] = 'OneAuth Multi-Factor Authentication';
$lang_module['oneauth_setup'] = 'Setup OneAuth';
$lang_module['oneauth_verify'] = 'Verify with OneAuth';
$lang_module['oneauth_disable'] = 'Disable OneAuth';
$lang_module['oneauth_invalid_email'] = 'Please enter a valid email address';
$lang_module['oneauth_select_methods'] = 'Please select at least one authentication method';
$lang_module['oneauth_setup_success'] = 'OneAuth has been successfully configured for your account';
$lang_module['oneauth_setup_error'] = 'Error setting up OneAuth';
$lang_module['oneauth_verify_success'] = 'OneAuth verification successful';
$lang_module['oneauth_verify_failed'] = 'OneAuth verification failed';
$lang_module['oneauth_verify_error'] = 'Error during OneAuth verification';
$lang_module['oneauth_invalid_transaction'] = 'Invalid transaction ID';
$lang_module['oneauth_push_message'] = 'Login request from';
$lang_module['oneauth_push_sent'] = 'Push notification sent to your device';
$lang_module['oneauth_push_failed'] = 'Failed to send push notification';
$lang_module['oneauth_push_error'] = 'Error sending push notification';
$lang_module['oneauth_biometric_invalid'] = 'Invalid biometric data';
$lang_module['oneauth_biometric_success'] = 'Biometric authentication successful';
$lang_module['oneauth_biometric_failed'] = 'Biometric authentication failed';
$lang_module['oneauth_biometric_error'] = 'Error during biometric authentication';
$lang_module['oneauth_password_required'] = 'Password is required to disable OneAuth';
$lang_module['oneauth_password_incorrect'] = 'Incorrect password';
$lang_module['oneauth_disabled_success'] = 'OneAuth has been disabled for your account';
$lang_module['oneauth_disable_error'] = 'Error disabling OneAuth';
$lang_module['oneauth_method_push'] = 'Push Notification';
$lang_module['oneauth_method_totp'] = 'Time-based OTP';
$lang_module['oneauth_method_sms'] = 'SMS';
$lang_module['oneauth_method_email'] = 'Email';
$lang_module['oneauth_method_biometric'] = 'Biometric';
$lang_module['oneauth_not_available'] = 'OneAuth is not available or not configured';

// WebAuthn Integration
$lang_module['webauthn_title'] = 'WebAuthn / FIDO2 Authentication';
$lang_module['webauthn_register'] = 'Register Security Key';
$lang_module['webauthn_authenticate'] = 'Authenticate with Security Key';
$lang_module['webauthn_manage'] = 'Manage Security Keys';
$lang_module['webauthn_not_available'] = 'WebAuthn is not available. HTTPS is required.';
$lang_module['webauthn_registration_error'] = 'Error starting registration';
$lang_module['webauthn_registration_success'] = 'Security key registered successfully';
$lang_module['webauthn_registration_failed'] = 'Security key registration failed';
$lang_module['webauthn_invalid_response'] = 'Invalid registration response';
$lang_module['webauthn_authentication_error'] = 'Error starting authentication';
$lang_module['webauthn_authentication_success'] = 'Authentication successful';
$lang_module['webauthn_authentication_failed'] = 'Authentication failed';
$lang_module['webauthn_invalid_auth_response'] = 'Invalid authentication response';
$lang_module['webauthn_delete_invalid'] = 'Invalid credential or password required';
$lang_module['webauthn_password_incorrect'] = 'Incorrect password';
$lang_module['webauthn_delete_success'] = 'Security key deleted successfully';
$lang_module['webauthn_delete_error'] = 'Error deleting security key';
$lang_module['webauthn_credential_not_found'] = 'Security key not found';
$lang_module['webauthn_credential_name'] = 'Security Key Name';
$lang_module['webauthn_credential_created'] = 'Created';
$lang_module['webauthn_credential_last_used'] = 'Last Used';
$lang_module['webauthn_credential_usage_count'] = 'Usage Count';
$lang_module['webauthn_no_credentials'] = 'No security keys registered';
$lang_module['webauthn_register_new'] = 'Register New Security Key';
$lang_module['webauthn_touch_key'] = 'Please touch your security key';
$lang_module['webauthn_insert_key'] = 'Please insert and touch your security key';
$lang_module['webauthn_use_biometric'] = 'Please use your biometric authentication';
$lang_module['never'] = 'Never';

// Enhanced Security Features
$lang_module['enhanced_security'] = 'Enhanced Security Features';
$lang_module['security_overview'] = 'Security Overview';
$lang_module['recent_activity'] = 'Recent Security Activity';
$lang_module['backup_codes_enhanced'] = 'Enhanced Backup Codes';
$lang_module['rate_limit_exceeded'] = 'Too many attempts. Please try again later.';
$lang_module['security_log'] = 'Security Log';
$lang_module['qr_code_enhanced'] = 'Enhanced QR Code';
$lang_module['rust_backend'] = 'Powered by Rust Security Engine';
$lang_module['crypto_enhanced'] = 'Enhanced Cryptographic Protection';
