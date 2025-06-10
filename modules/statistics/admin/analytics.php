<?php

/**
 * NukeViet Content Management System
 * @version 4.x
 * @author VINADES.,JSC <contact@vinades.vn>
 * @copyright (C) 2009-2023 VINADES.,JSC. All rights reserved
 * @license GNU/GPL version 2 or any later version
 * @see https://github.com/nukeviet/nukeviet The NukeViet CMS GitHub project
 */

if (!defined('NV_IS_FILE_ADMIN')) {
    exit('Stop!!!');
}

$page_title = $lang_module['analytics_title'];

// Load analytics engine
require_once NV_ROOTDIR . '/includes/core/nukeviet_analytics.php';

$analytics = nv_analytics();
$contents = '';
$error = '';

// Handle AJAX requests
if ($nv_Request->isset_request('ajax', 'get')) {
    $action = $nv_Request->get_title('action', 'get', '');
    
    switch ($action) {
        case 'get_stats':
            $period = $nv_Request->get_title('period', 'get', 'today');
            $stats = nv_statistics_get_enhanced_stats($period);
            
            if ($stats) {
                nv_jsonOutput([
                    'status' => 'success',
                    'data' => $stats
                ]);
            } else {
                nv_jsonOutput([
                    'status' => 'error',
                    'message' => 'Failed to get statistics'
                ]);
            }
            break;
            
        case 'test_engine':
            $test_result = $analytics->test();
            $version = $analytics->getVersion();
            
            nv_jsonOutput([
                'status' => 'success',
                'data' => [
                    'engine_working' => $test_result,
                    'engine_enabled' => $analytics->isEnabled(),
                    'version_info' => $version
                ]
            ]);
            break;
            
        case 'bot_check':
            $user_agent = $nv_Request->get_title('user_agent', 'get', '');
            $ip_address = $nv_Request->get_title('ip_address', 'get', '');
            
            if (!empty($user_agent) && !empty($ip_address)) {
                $is_bot = nv_statistics_is_bot_enhanced($user_agent, $ip_address);
                
                nv_jsonOutput([
                    'status' => 'success',
                    'data' => [
                        'is_bot' => $is_bot,
                        'user_agent' => $user_agent,
                        'ip_address' => $ip_address
                    ]
                ]);
            } else {
                nv_jsonOutput([
                    'status' => 'error',
                    'message' => 'Missing user agent or IP address'
                ]);
            }
            break;
    }
}

// Handle configuration updates
if ($nv_Request->isset_request('save_config', 'post')) {
    $config = [
        'bot_detection' => [
            'enabled' => $nv_Request->get_bool('bot_detection_enabled', 'post', true),
            'sensitivity' => $nv_Request->get_float('bot_detection_sensitivity', 'post', 0.8),
            'confidence_threshold' => $nv_Request->get_float('confidence_threshold', 'post', 0.7),
            'behavioral_analysis' => $nv_Request->get_bool('behavioral_analysis', 'post', true),
            'ip_reputation' => $nv_Request->get_bool('ip_reputation', 'post', true),
            'ml_classification' => $nv_Request->get_bool('ml_classification', 'post', true)
        ],
        'analytics' => [
            'track_page_views' => $nv_Request->get_bool('track_page_views', 'post', true),
            'track_unique_visitors' => $nv_Request->get_bool('track_unique_visitors', 'post', true),
            'track_session_duration' => $nv_Request->get_bool('track_session_duration', 'post', true),
            'track_bounce_rate' => $nv_Request->get_bool('track_bounce_rate', 'post', true),
            'track_geography' => $nv_Request->get_bool('track_geography', 'post', true),
            'track_device_info' => $nv_Request->get_bool('track_device_info', 'post', true),
            'track_referrers' => $nv_Request->get_bool('track_referrers', 'post', true),
            'session_timeout' => $nv_Request->get_int('session_timeout', 'post', 30),
            'data_retention_days' => $nv_Request->get_int('data_retention_days', 'post', 365),
            'real_time_processing' => $nv_Request->get_bool('real_time_processing', 'post', true)
        ]
    ];
    
    // Save configuration to database
    foreach ($config as $section => $settings) {
        foreach ($settings as $key => $value) {
            $config_name = $section . '_' . $key;
            $config_value = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
            
            $db->query("INSERT INTO " . NV_CONFIG_GLOBALTABLE . " (
                lang, module, config_name, config_value
            ) VALUES (
                'sys', 'statistics', :config_name, :config_value
            ) ON DUPLICATE KEY UPDATE config_value = :config_value", [
                'config_name' => $config_name,
                'config_value' => $config_value
            ]);
        }
    }
    
    nv_insert_logs(NV_LANG_DATA, $module_name, 'Analytics configuration updated', '', $admin_info['userid']);
    nv_redirect_location(NV_BASE_ADMINURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name . '&' . NV_OP_VARIABLE . '=analytics&saved=1');
}

// Get current configuration
$current_config = [];
$result = $db->query("SELECT config_name, config_value FROM " . NV_CONFIG_GLOBALTABLE . " WHERE lang = 'sys' AND module = 'statistics' AND config_name LIKE '%bot_detection%' OR config_name LIKE '%analytics_%'");
while ($row = $result->fetch()) {
    $current_config[$row['config_name']] = $row['config_value'];
}

// Check if engine is working
$engine_status = [
    'enabled' => $analytics->isEnabled(),
    'working' => false,
    'version' => null
];

if ($analytics->isEnabled()) {
    $engine_status['working'] = $analytics->test();
    $engine_status['version'] = $analytics->getVersion();
}

$xtpl = new XTemplate('analytics.tpl', NV_ROOTDIR . '/themes/' . $global_config['module_theme'] . '/modules/' . $module_file);
$xtpl->assign('LANG', $lang_module);
$xtpl->assign('GLANG', $lang_global);
$xtpl->assign('MODULE_NAME', $module_name);
$xtpl->assign('OP', $op);

// Engine status
$xtpl->assign('ENGINE_STATUS', $engine_status);
if ($engine_status['enabled']) {
    $xtpl->parse('main.engine_enabled');
    if ($engine_status['working']) {
        $xtpl->parse('main.engine_working');
    } else {
        $xtpl->parse('main.engine_error');
    }
} else {
    $xtpl->parse('main.engine_disabled');
}

// Configuration form
$config_sections = [
    'bot_detection' => [
        'enabled' => $current_config['bot_detection_enabled'] ?? '1',
        'sensitivity' => $current_config['bot_detection_sensitivity'] ?? '0.8',
        'confidence_threshold' => $current_config['confidence_threshold'] ?? '0.7',
        'behavioral_analysis' => $current_config['behavioral_analysis'] ?? '1',
        'ip_reputation' => $current_config['ip_reputation'] ?? '1',
        'ml_classification' => $current_config['ml_classification'] ?? '1'
    ],
    'analytics' => [
        'track_page_views' => $current_config['analytics_track_page_views'] ?? '1',
        'track_unique_visitors' => $current_config['analytics_track_unique_visitors'] ?? '1',
        'track_session_duration' => $current_config['analytics_track_session_duration'] ?? '1',
        'track_bounce_rate' => $current_config['analytics_track_bounce_rate'] ?? '1',
        'track_geography' => $current_config['analytics_track_geography'] ?? '1',
        'track_device_info' => $current_config['analytics_track_device_info'] ?? '1',
        'track_referrers' => $current_config['analytics_track_referrers'] ?? '1',
        'session_timeout' => $current_config['analytics_session_timeout'] ?? '30',
        'data_retention_days' => $current_config['analytics_data_retention_days'] ?? '365',
        'real_time_processing' => $current_config['analytics_real_time_processing'] ?? '1'
    ]
];

foreach ($config_sections as $section => $settings) {
    foreach ($settings as $key => $value) {
        $xtpl->assign(strtoupper($section . '_' . $key), $value);
        
        if (in_array($key, ['enabled', 'behavioral_analysis', 'ip_reputation', 'ml_classification', 'track_page_views', 'track_unique_visitors', 'track_session_duration', 'track_bounce_rate', 'track_geography', 'track_device_info', 'track_referrers', 'real_time_processing'])) {
            if ($value == '1') {
                $xtpl->parse('main.config.' . $section . '.' . $key . '_checked');
            }
        }
    }
    $xtpl->parse('main.config.' . $section);
}

$xtpl->parse('main.config');

// Success message
if ($nv_Request->isset_request('saved', 'get')) {
    $xtpl->parse('main.saved');
}

$xtpl->parse('main');
$contents = $xtpl->text('main');

include NV_ROOTDIR . '/includes/header.php';
echo nv_admin_theme($contents);
include NV_ROOTDIR . '/includes/footer.php';
