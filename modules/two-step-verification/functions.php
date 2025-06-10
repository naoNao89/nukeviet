<?php

/**
 * NukeViet Content Management System
 * @version 4.x
 * @author VINADES.,JSC <contact@vinades.vn>
 * @copyright (C) 2009-2021 VINADES.,JSC. All rights reserved
 * @license GNU/GPL version 2 or any later version
 * @see https://github.com/nukeviet The NukeViet CMS GitHub project
 */

if (!defined('NV_SYSTEM')) {
    exit('Stop!!!');
}

define('NV_MOD_2STEP_VERIFICATION', true);

// Sau này ảo hóa thì thay đổi giá trị này thành giá trị cấu hình trong CSDL
define('NV_BRIDGE_USER_MODULE', 'users');

if (!isset($site_mods[NV_BRIDGE_USER_MODULE]) or (!defined('NV_IS_USER') and !defined('NV_IS_1STEP_USER'))) {
    nv_redirect_location(NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA);
}

// Load the Rust 2FA bridge
require_once NV_ROOTDIR . '/modules/two-step-verification/rust_bridge.php';

$GoogleAuthenticator = new \NukeViet\Core\GoogleAuthenticator();
$Rust2FA = new NukeVietRust2FA();
$nv_BotManager->setPrivate();

/**
 * nv_get_user_secretkey()
 *
 * @return string
 */
function nv_get_user_secretkey()
{
    global $db, $site_mods, $user_info, $db_config;

    $module_data = $db_config['prefix'] . '_' . $site_mods[NV_BRIDGE_USER_MODULE]['module_data'];
    $secretkey = $db->query('SELECT secretkey FROM ' . $module_data . ' WHERE userid=' . $user_info['userid'])->fetchColumn();

    if (empty($secretkey)) {
        global $GoogleAuthenticator, $Rust2FA;

        try {
            // Try to use Rust 2FA for better security
            $_secretkey = $Rust2FA->generateSecret();
        } catch (Exception $e) {
            // Fallback to GoogleAuthenticator if Rust library is not available
            $_secretkey = $GoogleAuthenticator->creatSecretkey();
        }

        while (1) {
            if ($db->query('SELECT COUNT(*) FROM ' . $module_data . ' WHERE secretkey=' . $db->quote($_secretkey))->fetchColumn() == 0) {
                // Encrypt the secret before storing
                try {
                    $encrypted_secret = $Rust2FA->encryptSecret($_secretkey);
                } catch (Exception $e) {
                    $encrypted_secret = $_secretkey; // Fallback to plain storage
                }

                if ($db->exec('UPDATE ' . $module_data . ' SET secretkey=' . $db->quote($encrypted_secret) . ' WHERE userid=' . $user_info['userid'])) {
                    $secretkey = $_secretkey;
                    break;
                }
                trigger_error('Error creat user secretkey!!!', 256);
            }

            // Generate a new secret if collision detected
            try {
                $_secretkey = $Rust2FA->generateSecret();
            } catch (Exception $e) {
                $_secretkey = $GoogleAuthenticator->creatSecretkey();
            }
        }
    } else {
        // Try to decrypt the secret if it's encrypted
        try {
            $decrypted = $Rust2FA->decryptSecret($secretkey);
            if ($decrypted !== false) {
                $secretkey = $decrypted;
            }
        } catch (Exception $e) {
            // Secret is probably not encrypted, use as-is
        }
    }

    return $secretkey;
}

/**
 * nv_creat_backupcodes()
 */
function nv_creat_backupcodes()
{
    global $user_info, $db, $db_config, $site_mods, $Rust2FA;

    $module_data = $db_config['prefix'] . '_' . $site_mods[NV_BRIDGE_USER_MODULE]['module_data'];
    $db->query('DELETE FROM ' . $module_data . '_backupcodes WHERE userid=' . $user_info['userid']);

    try {
        // Use Rust 2FA for secure backup code generation
        $new_code = $Rust2FA->generateBackupCodes(10);
    } catch (Exception $e) {
        // Fallback to original method
        $new_code = [];
        while (sizeof($new_code) < 10) {
            $code = nv_strtolower(nv_genpass(8, 0));
            if (!in_array($code, $new_code, true)) {
                $new_code[] = $code;
            }
        }
    }

    foreach ($new_code as $code) {
        try {
            // Hash the backup code for secure storage
            $hashed_code = $Rust2FA->hashBackupCode($code);
        } catch (Exception $e) {
            // Fallback to plain storage
            $hashed_code = $code;
        }

        $db->query('INSERT INTO ' . $module_data . '_backupcodes (userid, code, is_used, time_used, time_creat) VALUES (
        ' . $user_info['userid'] . ', ' . $db->quote($hashed_code) . ', 0, 0, ' . NV_CURRENTTIME . ')');
    }

    return $new_code; // Return the plain codes for display to user
}

/**
 * nv_verify_totp_code()
 * Enhanced TOTP verification with Rust backend
 */
function nv_verify_totp_code($secret, $code, $user_id)
{
    global $Rust2FA, $GoogleAuthenticator;

    try {
        // Use Rust 2FA for enhanced security and rate limiting
        return $Rust2FA->verifyTOTP($secret, $code, $user_id);
    } catch (Exception $e) {
        // Fallback to GoogleAuthenticator
        return $GoogleAuthenticator->verifyCode($secret, $code);
    }
}

/**
 * nv_verify_backup_code()
 * Verify backup code with secure comparison
 */
function nv_verify_backup_code($user_id, $provided_code)
{
    global $db, $db_config, $site_mods, $Rust2FA;

    $module_data = $db_config['prefix'] . '_' . $site_mods[NV_BRIDGE_USER_MODULE]['module_data'];

    // Get all unused backup codes for the user
    $result = $db->query('SELECT id, code FROM ' . $module_data . '_backupcodes WHERE userid=' . $user_id . ' AND is_used=0');

    while ($row = $result->fetch()) {
        try {
            // Use secure comparison from Rust bridge
            if ($Rust2FA->verifyBackupCode($row['code'], $provided_code)) {
                // Mark code as used
                $db->exec('UPDATE ' . $module_data . '_backupcodes SET is_used=1, time_used=' . NV_CURRENTTIME . ' WHERE id=' . $row['id']);
                return true;
            }
        } catch (Exception $e) {
            // Fallback to simple comparison
            if (hash_equals($row['code'], $provided_code)) {
                $db->exec('UPDATE ' . $module_data . '_backupcodes SET is_used=1, time_used=' . NV_CURRENTTIME . ' WHERE id=' . $row['id']);
                return true;
            }
        }
    }

    return false;
}

/**
 * nv_generate_qr_code()
 * Generate QR code for TOTP setup
 */
function nv_generate_qr_code($secret, $account, $issuer = null)
{
    global $Rust2FA, $GoogleAuthenticator, $global_config;

    $issuer = $issuer ?: ($global_config['site_name'] ?? 'NukeViet CMS');

    try {
        // Use Rust 2FA for QR code generation
        return $Rust2FA->generateQRCode($secret, $account, $issuer);
    } catch (Exception $e) {
        // Fallback to manual URI generation
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s',
            urlencode($issuer),
            urlencode($account),
            $secret,
            urlencode($issuer)
        );
    }
}

/**
 * nv_check_oneauth_enabled()
 * Check if OneAuth integration is enabled
 */
function nv_check_oneauth_enabled()
{
    global $global_config;

    return !empty($global_config['oneauth_enabled']) &&
           !empty($global_config['oneauth_client_id']) &&
           !empty($global_config['oneauth_client_secret']);
}

/**
 * nv_check_webauthn_enabled()
 * Check if WebAuthn is enabled and supported
 */
function nv_check_webauthn_enabled()
{
    global $global_config;

    // Check if HTTPS is enabled (required for WebAuthn)
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');

    return !empty($global_config['webauthn_enabled']) && $is_https;
}

/**
 * nv_log_security_event()
 * Log security events for audit trail
 */
function nv_log_security_event($event_type, $user_id, $details = [])
{
    global $db, $db_config, $client_info;

    $log_data = [
        'event_type' => $event_type,
        'user_id' => $user_id,
        'ip_address' => $client_info['ip'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'timestamp' => NV_CURRENTTIME,
        'details' => json_encode($details)
    ];

    // Log to database (you may need to create this table)
    try {
        $sql = 'INSERT INTO ' . $db_config['prefix'] . '_2fa_security_log
                (event_type, user_id, ip_address, user_agent, timestamp, details)
                VALUES (:event_type, :user_id, :ip_address, :user_agent, :timestamp, :details)';

        $stmt = $db->prepare($sql);
        $stmt->execute($log_data);
    } catch (Exception $e) {
        // Silently fail if table doesn't exist
        error_log('2FA Security Log Error: ' . $e->getMessage());
    }
}

// Lấy mã bí mật
$secretkey = nv_get_user_secretkey();

$tokend_key = md5($user_info['username'] . '_' . $user_info['current_login'] . '_' . NV_BRIDGE_USER_MODULE . '_confirm_pass_' . NV_CHECK_SESSION);
$tokend_confirm_password = $nv_Request->get_title($tokend_key, 'session', '');
$tokend = md5(NV_BRIDGE_USER_MODULE . '_confirm_pass_' . NV_CHECK_SESSION);

if ($tokend_confirm_password != $tokend and $op != 'confirm') {
    header('Location: ' . nv_url_rewrite(NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name . '&' . NV_OP_VARIABLE . '=' . $module_info['alias']['confirm'] . '&nv_redirect=' . nv_redirect_encrypt($client_info['selfurl']), true));
    exit();
}
