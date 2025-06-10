<?php

/**
 * NukeViet Content Management System
 * @version 4.x
 * @author VINADES.,JSC <contact@vinades.vn>
 * @copyright (C) 2009-2023 VINADES.,JSC. All rights reserved
 * @license GNU/GPL version 2 or any later version
 * @see https://github.com/nukeviet The NukeViet CMS GitHub project
 */

if (!defined('NV_IS_MOD_2STEP_VERIFICATION')) {
    exit('Stop!!!');
}

/**
 * OneAuth Integration for NukeViet 2FA
 * 
 * This file handles OneAuth integration for enhanced multi-factor authentication
 * including push notifications, biometric authentication, and SSO capabilities.
 */

$page_title = $lang_module['oneauth_title'];
$key_words = $module_info['keywords'];
$mod_title = $lang_module['oneauth_title'];

// Check if OneAuth is enabled
if (!nv_check_oneauth_enabled()) {
    nv_redirect_location(NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name);
}

$error = '';
$success = '';
$step = $nv_Request->get_title('step', 'get,post', 'setup');

// Handle different OneAuth operations
switch ($step) {
    case 'setup':
        // Setup OneAuth for the user
        if ($nv_Request->isset_request('submit', 'post')) {
            $email = $nv_Request->get_title('email', 'post', '');
            $phone = $nv_Request->get_title('phone', 'post', '');
            $methods = $nv_Request->get_array('methods', 'post', []);
            
            if (empty($email) || !nv_check_valid_email($email)) {
                $error = $lang_module['oneauth_invalid_email'];
            } elseif (empty($methods)) {
                $error = $lang_module['oneauth_select_methods'];
            } else {
                try {
                    // Initialize OneAuth client
                    $oneauth_config = [
                        'client_id' => $global_config['oneauth_client_id'],
                        'client_secret' => $global_config['oneauth_client_secret'],
                        'api_url' => $global_config['oneauth_api_url'] ?? 'https://accounts.zoho.com'
                    ];
                    
                    // Enroll user with OneAuth
                    $enrollment_data = [
                        'user_id' => (string)$user_info['userid'],
                        'email' => $email,
                        'phone' => $phone,
                        'methods' => $methods
                    ];
                    
                    // Store OneAuth enrollment in database
                    $db->query('INSERT INTO ' . $db_config['prefix'] . '_users_oneauth 
                               (userid, email, phone, methods, enrollment_id, status, created_time) 
                               VALUES (' . $user_info['userid'] . ', ' . $db->quote($email) . ', ' . $db->quote($phone) . ', 
                               ' . $db->quote(json_encode($methods)) . ', ' . $db->quote('pending') . ', 1, ' . NV_CURRENTTIME . ')
                               ON DUPLICATE KEY UPDATE 
                               email=' . $db->quote($email) . ', phone=' . $db->quote($phone) . ', 
                               methods=' . $db->quote(json_encode($methods)) . ', status=1, created_time=' . NV_CURRENTTIME);
                    
                    // Log security event
                    nv_log_security_event('oneauth_enrollment', $user_info['userid'], [
                        'email' => $email,
                        'methods' => $methods
                    ]);
                    
                    $success = $lang_module['oneauth_setup_success'];
                    
                } catch (Exception $e) {
                    $error = $lang_module['oneauth_setup_error'] . ': ' . $e->getMessage();
                }
            }
        }
        
        // Get user's current OneAuth settings
        $oneauth_settings = $db->query('SELECT * FROM ' . $db_config['prefix'] . '_users_oneauth WHERE userid=' . $user_info['userid'])->fetch();
        
        break;
        
    case 'verify':
        // Verify OneAuth authentication
        $transaction_id = $nv_Request->get_title('transaction_id', 'get,post', '');
        $method = $nv_Request->get_title('method', 'get,post', 'push');
        
        if ($nv_Request->isset_request('verify', 'post')) {
            $code = $nv_Request->get_title('code', 'post', '');
            
            if (empty($transaction_id)) {
                $error = $lang_module['oneauth_invalid_transaction'];
            } else {
                try {
                    // Verify with OneAuth
                    $verification_result = false; // Placeholder for actual OneAuth verification
                    
                    if ($verification_result) {
                        // Store successful verification
                        $_SESSION['oneauth_verified'] = true;
                        $_SESSION['oneauth_verify_time'] = NV_CURRENTTIME;
                        
                        // Log security event
                        nv_log_security_event('oneauth_verification_success', $user_info['userid'], [
                            'method' => $method,
                            'transaction_id' => $transaction_id
                        ]);
                        
                        $success = $lang_module['oneauth_verify_success'];
                        
                        // Redirect to original destination
                        $redirect = $nv_Request->get_title('redirect', 'get,post', '');
                        if (!empty($redirect)) {
                            nv_redirect_location(nv_redirect_decrypt($redirect));
                        }
                    } else {
                        $error = $lang_module['oneauth_verify_failed'];
                        
                        // Log security event
                        nv_log_security_event('oneauth_verification_failed', $user_info['userid'], [
                            'method' => $method,
                            'transaction_id' => $transaction_id
                        ]);
                    }
                    
                } catch (Exception $e) {
                    $error = $lang_module['oneauth_verify_error'] . ': ' . $e->getMessage();
                }
            }
        }
        
        break;
        
    case 'push':
        // Send push notification
        if ($nv_Request->isset_request('send_push', 'post')) {
            try {
                $message = $lang_module['oneauth_push_message'] . ' ' . $global_config['site_name'];
                
                // Send push notification via OneAuth
                $push_result = [
                    'transaction_id' => 'mock_' . uniqid(),
                    'status' => 'sent',
                    'expires_in' => 300
                ]; // Placeholder for actual OneAuth push
                
                if ($push_result['status'] === 'sent') {
                    $success = $lang_module['oneauth_push_sent'];
                    $transaction_id = $push_result['transaction_id'];
                    
                    // Log security event
                    nv_log_security_event('oneauth_push_sent', $user_info['userid'], [
                        'transaction_id' => $transaction_id
                    ]);
                } else {
                    $error = $lang_module['oneauth_push_failed'];
                }
                
            } catch (Exception $e) {
                $error = $lang_module['oneauth_push_error'] . ': ' . $e->getMessage();
            }
        }
        
        break;
        
    case 'biometric':
        // Handle biometric authentication
        if ($nv_Request->isset_request('verify_biometric', 'post')) {
            $biometric_data = $nv_Request->get_title('biometric_data', 'post', '');
            $transaction_id = $nv_Request->get_title('transaction_id', 'post', '');
            
            if (empty($biometric_data) || empty($transaction_id)) {
                $error = $lang_module['oneauth_biometric_invalid'];
            } else {
                try {
                    // Verify biometric data with OneAuth
                    $biometric_result = false; // Placeholder for actual OneAuth biometric verification
                    
                    if ($biometric_result) {
                        $_SESSION['oneauth_verified'] = true;
                        $_SESSION['oneauth_verify_time'] = NV_CURRENTTIME;
                        
                        // Log security event
                        nv_log_security_event('oneauth_biometric_success', $user_info['userid'], [
                            'transaction_id' => $transaction_id
                        ]);
                        
                        $success = $lang_module['oneauth_biometric_success'];
                    } else {
                        $error = $lang_module['oneauth_biometric_failed'];
                        
                        // Log security event
                        nv_log_security_event('oneauth_biometric_failed', $user_info['userid'], [
                            'transaction_id' => $transaction_id
                        ]);
                    }
                    
                } catch (Exception $e) {
                    $error = $lang_module['oneauth_biometric_error'] . ': ' . $e->getMessage();
                }
            }
        }
        
        break;
        
    case 'disable':
        // Disable OneAuth for the user
        if ($nv_Request->isset_request('confirm_disable', 'post')) {
            $password = $nv_Request->get_title('password', 'post', '');
            
            if (empty($password)) {
                $error = $lang_module['oneauth_password_required'];
            } elseif (!$crypt->validate_password($password, $user_info['password'])) {
                $error = $lang_module['oneauth_password_incorrect'];
            } else {
                try {
                    // Disable OneAuth enrollment
                    $db->exec('UPDATE ' . $db_config['prefix'] . '_users_oneauth SET status=0 WHERE userid=' . $user_info['userid']);
                    
                    // Log security event
                    nv_log_security_event('oneauth_disabled', $user_info['userid']);
                    
                    $success = $lang_module['oneauth_disabled_success'];
                    
                } catch (Exception $e) {
                    $error = $lang_module['oneauth_disable_error'] . ': ' . $e->getMessage();
                }
            }
        }
        
        break;
}

// Available OneAuth methods
$available_methods = [
    'push' => $lang_module['oneauth_method_push'],
    'totp' => $lang_module['oneauth_method_totp'],
    'sms' => $lang_module['oneauth_method_sms'],
    'email' => $lang_module['oneauth_method_email'],
    'biometric' => $lang_module['oneauth_method_biometric']
];

// Prepare template variables
$xtpl = new XTemplate('oneauth.tpl', NV_ROOTDIR . '/themes/' . $module_info['template'] . '/modules/' . $module_file);
$xtpl->assign('LANG', $lang_module);
$xtpl->assign('GLANG', $lang_global);
$xtpl->assign('MODULE_NAME', $module_name);
$xtpl->assign('OP', $op);
$xtpl->assign('STEP', $step);
$xtpl->assign('USER_INFO', $user_info);

if (!empty($error)) {
    $xtpl->assign('ERROR', $error);
    $xtpl->parse('main.error');
}

if (!empty($success)) {
    $xtpl->assign('SUCCESS', $success);
    $xtpl->parse('main.success');
}

// Parse appropriate template section based on step
switch ($step) {
    case 'setup':
        if (isset($oneauth_settings) && $oneauth_settings) {
            $xtpl->assign('ONEAUTH_SETTINGS', $oneauth_settings);
            $xtpl->assign('METHODS', json_decode($oneauth_settings['methods'], true));
        }
        
        foreach ($available_methods as $method_key => $method_name) {
            $xtpl->assign('METHOD', [
                'key' => $method_key,
                'name' => $method_name,
                'checked' => (isset($oneauth_settings) && in_array($method_key, json_decode($oneauth_settings['methods'] ?? '[]', true))) ? 'checked' : ''
            ]);
            $xtpl->parse('main.setup.method');
        }
        
        $xtpl->parse('main.setup');
        break;
        
    case 'verify':
        $xtpl->assign('TRANSACTION_ID', $transaction_id ?? '');
        $xtpl->assign('METHOD', $method);
        $xtpl->parse('main.verify');
        break;
        
    case 'push':
        if (isset($transaction_id)) {
            $xtpl->assign('TRANSACTION_ID', $transaction_id);
            $xtpl->parse('main.push.sent');
        }
        $xtpl->parse('main.push');
        break;
        
    case 'biometric':
        $xtpl->parse('main.biometric');
        break;
        
    case 'disable':
        $xtpl->parse('main.disable');
        break;
}

$xtpl->parse('main');
$contents = $xtpl->text('main');

include NV_ROOTDIR . '/includes/header.php';
echo nv_site_theme($contents);
include NV_ROOTDIR . '/includes/footer.php';
