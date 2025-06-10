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
 * WebAuthn Integration for NukeViet 2FA
 * 
 * This file handles WebAuthn/FIDO2 authentication including hardware security keys,
 * platform authenticators (Touch ID, Face ID, Windows Hello), and biometric authentication.
 */

$page_title = $lang_module['webauthn_title'];
$key_words = $module_info['keywords'];
$mod_title = $lang_module['webauthn_title'];

// Check if WebAuthn is enabled and HTTPS is available
if (!nv_check_webauthn_enabled()) {
    $error = $lang_module['webauthn_not_available'];
    nv_redirect_location(NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name);
}

$error = '';
$success = '';
$step = $nv_Request->get_title('step', 'get,post', 'register');

// Handle different WebAuthn operations
switch ($step) {
    case 'register':
        // Register a new WebAuthn credential
        if ($nv_Request->isset_request('start_registration', 'post')) {
            try {
                $credential_name = $nv_Request->get_title('credential_name', 'post', '');
                if (empty($credential_name)) {
                    $credential_name = 'Security Key ' . date('Y-m-d H:i');
                }
                
                // Get existing credentials for exclusion
                $existing_credentials = $db->query('SELECT credential_id FROM ' . $db_config['prefix'] . '_users_webauthn WHERE userid=' . $user_info['userid'] . ' AND status=1')->fetchAll();
                
                // Generate registration challenge
                $challenge_data = [
                    'challenge_id' => uniqid('webauthn_reg_'),
                    'user_id' => $user_info['userid'],
                    'username' => $user_info['username'],
                    'display_name' => $user_info['full_name'] ?: $user_info['username'],
                    'existing_credentials' => $existing_credentials,
                    'rp_id' => $_SERVER['HTTP_HOST'],
                    'rp_name' => $global_config['site_name'],
                    'timeout' => 60000,
                    'user_verification' => 'preferred'
                ];
                
                // Store challenge in session
                $_SESSION['webauthn_registration_challenge'] = $challenge_data;
                
                // Log security event
                nv_log_security_event('webauthn_registration_started', $user_info['userid'], [
                    'challenge_id' => $challenge_data['challenge_id']
                ]);
                
                $registration_challenge = $challenge_data;
                
            } catch (Exception $e) {
                $error = $lang_module['webauthn_registration_error'] . ': ' . $e->getMessage();
            }
        }
        
        if ($nv_Request->isset_request('complete_registration', 'post')) {
            $credential_response = $nv_Request->get_title('credential_response', 'post', '');
            $credential_name = $nv_Request->get_title('credential_name', 'post', '');
            
            if (empty($credential_response) || empty($_SESSION['webauthn_registration_challenge'])) {
                $error = $lang_module['webauthn_invalid_response'];
            } else {
                try {
                    $challenge_data = $_SESSION['webauthn_registration_challenge'];
                    $credential_data = json_decode($credential_response, true);
                    
                    if (!$credential_data) {
                        throw new Exception('Invalid credential data');
                    }
                    
                    // Store the credential in database
                    $credential_id = $credential_data['id'] ?? uniqid('cred_');
                    $public_key = $credential_data['response']['publicKey'] ?? '';
                    
                    $db->query('INSERT INTO ' . $db_config['prefix'] . '_users_webauthn 
                               (userid, credential_id, public_key, counter, name, created_time, last_used, status) 
                               VALUES (' . $user_info['userid'] . ', ' . $db->quote($credential_id) . ', 
                               ' . $db->quote($public_key) . ', 0, ' . $db->quote($credential_name) . ', 
                               ' . NV_CURRENTTIME . ', 0, 1)');
                    
                    // Clear session challenge
                    unset($_SESSION['webauthn_registration_challenge']);
                    
                    // Log security event
                    nv_log_security_event('webauthn_registration_completed', $user_info['userid'], [
                        'credential_id' => $credential_id,
                        'credential_name' => $credential_name
                    ]);
                    
                    $success = $lang_module['webauthn_registration_success'];
                    
                } catch (Exception $e) {
                    $error = $lang_module['webauthn_registration_failed'] . ': ' . $e->getMessage();
                }
            }
        }
        
        // Get user's existing credentials
        $user_credentials = $db->query('SELECT * FROM ' . $db_config['prefix'] . '_users_webauthn WHERE userid=' . $user_info['userid'] . ' AND status=1 ORDER BY created_time DESC')->fetchAll();
        
        break;
        
    case 'authenticate':
        // Authenticate with WebAuthn
        if ($nv_Request->isset_request('start_authentication', 'post')) {
            try {
                // Get user's credentials
                $user_credentials = $db->query('SELECT credential_id, public_key FROM ' . $db_config['prefix'] . '_users_webauthn WHERE userid=' . $user_info['userid'] . ' AND status=1')->fetchAll();
                
                if (empty($user_credentials)) {
                    throw new Exception('No credentials found for user');
                }
                
                // Generate authentication challenge
                $challenge_data = [
                    'challenge_id' => uniqid('webauthn_auth_'),
                    'user_id' => $user_info['userid'],
                    'credentials' => $user_credentials,
                    'rp_id' => $_SERVER['HTTP_HOST'],
                    'timeout' => 60000,
                    'user_verification' => 'preferred'
                ];
                
                // Store challenge in session
                $_SESSION['webauthn_authentication_challenge'] = $challenge_data;
                
                // Log security event
                nv_log_security_event('webauthn_authentication_started', $user_info['userid'], [
                    'challenge_id' => $challenge_data['challenge_id']
                ]);
                
                $authentication_challenge = $challenge_data;
                
            } catch (Exception $e) {
                $error = $lang_module['webauthn_authentication_error'] . ': ' . $e->getMessage();
            }
        }
        
        if ($nv_Request->isset_request('complete_authentication', 'post')) {
            $auth_response = $nv_Request->get_title('auth_response', 'post', '');
            
            if (empty($auth_response) || empty($_SESSION['webauthn_authentication_challenge'])) {
                $error = $lang_module['webauthn_invalid_auth_response'];
            } else {
                try {
                    $challenge_data = $_SESSION['webauthn_authentication_challenge'];
                    $auth_data = json_decode($auth_response, true);
                    
                    if (!$auth_data) {
                        throw new Exception('Invalid authentication data');
                    }
                    
                    $credential_id = $auth_data['id'] ?? '';
                    
                    // Verify the credential exists and belongs to the user
                    $credential = $db->query('SELECT * FROM ' . $db_config['prefix'] . '_users_webauthn 
                                            WHERE userid=' . $user_info['userid'] . ' AND credential_id=' . $db->quote($credential_id) . ' AND status=1')->fetch();
                    
                    if (!$credential) {
                        throw new Exception('Invalid credential');
                    }
                    
                    // Update credential counter and last used time
                    $db->exec('UPDATE ' . $db_config['prefix'] . '_users_webauthn 
                             SET counter=counter+1, last_used=' . NV_CURRENTTIME . ' 
                             WHERE userid=' . $user_info['userid'] . ' AND credential_id=' . $db->quote($credential_id));
                    
                    // Clear session challenge
                    unset($_SESSION['webauthn_authentication_challenge']);
                    
                    // Mark as authenticated
                    $_SESSION['webauthn_verified'] = true;
                    $_SESSION['webauthn_verify_time'] = NV_CURRENTTIME;
                    
                    // Log security event
                    nv_log_security_event('webauthn_authentication_success', $user_info['userid'], [
                        'credential_id' => $credential_id,
                        'credential_name' => $credential['name']
                    ]);
                    
                    $success = $lang_module['webauthn_authentication_success'];
                    
                    // Redirect to original destination
                    $redirect = $nv_Request->get_title('redirect', 'get,post', '');
                    if (!empty($redirect)) {
                        nv_redirect_location(nv_redirect_decrypt($redirect));
                    }
                    
                } catch (Exception $e) {
                    $error = $lang_module['webauthn_authentication_failed'] . ': ' . $e->getMessage();
                    
                    // Log security event
                    nv_log_security_event('webauthn_authentication_failed', $user_info['userid'], [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        break;
        
    case 'manage':
        // Manage existing credentials
        if ($nv_Request->isset_request('delete_credential', 'post')) {
            $credential_id = $nv_Request->get_title('credential_id', 'post', '');
            $password = $nv_Request->get_title('password', 'post', '');
            
            if (empty($credential_id) || empty($password)) {
                $error = $lang_module['webauthn_delete_invalid'];
            } elseif (!$crypt->validate_password($password, $user_info['password'])) {
                $error = $lang_module['webauthn_password_incorrect'];
            } else {
                try {
                    // Get credential info before deletion
                    $credential = $db->query('SELECT name FROM ' . $db_config['prefix'] . '_users_webauthn 
                                            WHERE userid=' . $user_info['userid'] . ' AND credential_id=' . $db->quote($credential_id))->fetch();
                    
                    if ($credential) {
                        // Delete the credential
                        $db->exec('UPDATE ' . $db_config['prefix'] . '_users_webauthn 
                                 SET status=0 WHERE userid=' . $user_info['userid'] . ' AND credential_id=' . $db->quote($credential_id));
                        
                        // Log security event
                        nv_log_security_event('webauthn_credential_deleted', $user_info['userid'], [
                            'credential_id' => $credential_id,
                            'credential_name' => $credential['name']
                        ]);
                        
                        $success = $lang_module['webauthn_delete_success'];
                    } else {
                        $error = $lang_module['webauthn_credential_not_found'];
                    }
                    
                } catch (Exception $e) {
                    $error = $lang_module['webauthn_delete_error'] . ': ' . $e->getMessage();
                }
            }
        }
        
        // Get user's credentials for management
        $user_credentials = $db->query('SELECT * FROM ' . $db_config['prefix'] . '_users_webauthn WHERE userid=' . $user_info['userid'] . ' AND status=1 ORDER BY created_time DESC')->fetchAll();
        
        break;
}

// Prepare template variables
$xtpl = new XTemplate('webauthn.tpl', NV_ROOTDIR . '/themes/' . $module_info['template'] . '/modules/' . $module_file);
$xtpl->assign('LANG', $lang_module);
$xtpl->assign('GLANG', $lang_global);
$xtpl->assign('MODULE_NAME', $module_name);
$xtpl->assign('OP', $op);
$xtpl->assign('STEP', $step);
$xtpl->assign('USER_INFO', $user_info);
$xtpl->assign('NV_BASE_SITEURL', NV_BASE_SITEURL);

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
    case 'register':
        if (isset($registration_challenge)) {
            $xtpl->assign('REGISTRATION_CHALLENGE', json_encode($registration_challenge));
            $xtpl->parse('main.register.challenge');
        }
        
        if (isset($user_credentials) && !empty($user_credentials)) {
            foreach ($user_credentials as $credential) {
                $xtpl->assign('CREDENTIAL', [
                    'id' => $credential['credential_id'],
                    'name' => $credential['name'],
                    'created_time' => date('Y-m-d H:i:s', $credential['created_time']),
                    'last_used' => $credential['last_used'] ? date('Y-m-d H:i:s', $credential['last_used']) : $lang_module['never'],
                    'counter' => $credential['counter']
                ]);
                $xtpl->parse('main.register.existing_credential');
            }
            $xtpl->parse('main.register.existing_credentials');
        }
        
        $xtpl->parse('main.register');
        break;
        
    case 'authenticate':
        if (isset($authentication_challenge)) {
            $xtpl->assign('AUTHENTICATION_CHALLENGE', json_encode($authentication_challenge));
            $xtpl->parse('main.authenticate.challenge');
        }
        $xtpl->parse('main.authenticate');
        break;
        
    case 'manage':
        if (isset($user_credentials) && !empty($user_credentials)) {
            foreach ($user_credentials as $credential) {
                $xtpl->assign('CREDENTIAL', [
                    'id' => $credential['credential_id'],
                    'name' => $credential['name'],
                    'created_time' => date('Y-m-d H:i:s', $credential['created_time']),
                    'last_used' => $credential['last_used'] ? date('Y-m-d H:i:s', $credential['last_used']) : $lang_module['never'],
                    'counter' => $credential['counter']
                ]);
                $xtpl->parse('main.manage.credential');
            }
        } else {
            $xtpl->parse('main.manage.no_credentials');
        }
        $xtpl->parse('main.manage');
        break;
}

$xtpl->parse('main');
$contents = $xtpl->text('main');

include NV_ROOTDIR . '/includes/header.php';
echo nv_site_theme($contents);
include NV_ROOTDIR . '/includes/footer.php';
