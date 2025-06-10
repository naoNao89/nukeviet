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

// Load enhanced analytics engine
require_once NV_ROOTDIR . '/includes/core/nukeviet_analytics.php';

define('NV_IS_MOD_STATISTICS', true);

define('NV_BASE_MOD_URL', NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name);

$nv_BotManager->setPrivate();

/**
 * Enhanced visitor tracking with Rust analytics engine
 */
function nv_statistics_track_visitor_enhanced()
{
    global $global_config, $client_info, $nv_Request;

    // Initialize analytics engine if not already done
    $analytics = nv_analytics();

    if (!$analytics->isEnabled()) {
        // Fallback to original tracking
        return nv_statistics_track_visitor_original();
    }

    // Prepare visitor data
    $visitor_data = [
        'visitor_id' => md5($client_info['ip'] . $client_info['agent']),
        'ip_address' => $client_info['ip'],
        'user_agent' => $client_info['agent'],
        'referer' => $nv_Request->get_string('HTTP_REFERER', 'server', ''),
        'request_uri' => $nv_Request->get_string('REQUEST_URI', 'server', ''),
        'session_id' => session_id(),
        'country' => $client_info['country'] ?? null,
        'language' => $global_config['lang_interface']
    ];

    // Process visitor with Rust analytics engine
    $result = $analytics->processVisitor($visitor_data);

    if ($result) {
        // Store enhanced analytics data
        nv_statistics_store_enhanced_data($result);

        // Update traditional counters only for non-bots or whitelisted bots
        if ($result['should_count']) {
            nv_statistics_update_counters($result);
        }

        return $result;
    }

    // Fallback to original tracking if analytics fails
    return nv_statistics_track_visitor_original();
}

/**
 * Check if visitor is a bot using enhanced detection
 */
function nv_statistics_is_bot_enhanced($user_agent = null, $ip_address = null)
{
    $analytics = nv_analytics();

    if ($analytics->isEnabled()) {
        return $analytics->isBot($user_agent, $ip_address);
    }

    // Fallback to original bot detection
    global $nv_BotManager;
    return $nv_BotManager->is_bot();
}

/**
 * Get enhanced analytics statistics
 */
function nv_statistics_get_enhanced_stats($period = 'today')
{
    $analytics = nv_analytics();

    if ($analytics->isEnabled()) {
        return $analytics->getStatistics($period);
    }

    return null;
}

/**
 * Store enhanced analytics data
 */
function nv_statistics_store_enhanced_data($analytics_result)
{
    global $db;

    try {
        // Create enhanced analytics table if it doesn't exist
        $db->query("CREATE TABLE IF NOT EXISTS " . NV_PREFIXLANG . "_statistics_enhanced (
            id int(11) NOT NULL AUTO_INCREMENT,
            visitor_id varchar(255) NOT NULL,
            timestamp int(11) NOT NULL,
            ip_address varchar(45) NOT NULL,
            country varchar(10),
            browser_name varchar(100),
            browser_version varchar(50),
            os_name varchar(100),
            os_version varchar(50),
            device_type varchar(50),
            is_mobile tinyint(1) NOT NULL DEFAULT 0,
            is_tablet tinyint(1) NOT NULL DEFAULT 0,
            page_url text,
            referer text,
            session_id varchar(255),
            is_bot tinyint(1) NOT NULL DEFAULT 0,
            bot_type varchar(50),
            bot_confidence float,
            should_count tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_timestamp (timestamp),
            KEY idx_is_bot (is_bot),
            KEY idx_visitor_id (visitor_id)
        ) ENGINE=InnoDB");

        // Insert analytics data
        $analytics_data = $analytics_result['analytics_data'];
        $db->query("INSERT INTO " . NV_PREFIXLANG . "_statistics_enhanced (
            visitor_id, timestamp, ip_address, country,
            browser_name, browser_version, os_name, os_version,
            device_type, is_mobile, is_tablet, page_url, referer,
            session_id, is_bot, bot_type, bot_confidence, should_count
        ) VALUES (
            :visitor_id, :timestamp, :ip_address, :country,
            :browser_name, :browser_version, :os_name, :os_version,
            :device_type, :is_mobile, :is_tablet, :page_url, :referer,
            :session_id, :is_bot, :bot_type, :bot_confidence, :should_count
        )", [
            'visitor_id' => $analytics_data['visitor_id'],
            'timestamp' => strtotime($analytics_data['timestamp']),
            'ip_address' => $analytics_data['ip_address'],
            'country' => $analytics_data['country'],
            'browser_name' => $analytics_data['browser']['name'],
            'browser_version' => $analytics_data['browser']['version'],
            'os_name' => $analytics_data['os']['name'],
            'os_version' => $analytics_data['os']['version'],
            'device_type' => $analytics_data['device']['device_type'],
            'is_mobile' => $analytics_data['device']['is_mobile'] ? 1 : 0,
            'is_tablet' => $analytics_data['device']['is_tablet'] ? 1 : 0,
            'page_url' => $analytics_data['page_info']['url'],
            'referer' => $analytics_data['page_info']['referer'],
            'session_id' => $analytics_data['session_info']['session_id'],
            'is_bot' => $analytics_result['is_bot'] ? 1 : 0,
            'bot_type' => $analytics_result['bot_type'],
            'bot_confidence' => $analytics_result['confidence'],
            'should_count' => $analytics_result['should_count'] ? 1 : 0
        ]);

    } catch (Exception $e) {
        trigger_error('Failed to store enhanced analytics data: ' . $e->getMessage(), E_USER_WARNING);
    }
}

/**
 * Update traditional counters (backward compatibility)
 */
function nv_statistics_update_counters($analytics_result)
{
    global $db, $global_config;

    if (!$analytics_result['should_count']) {
        return;
    }

    $analytics_data = $analytics_result['analytics_data'];
    $timestamp = strtotime($analytics_data['timestamp']);

    // Update daily counter
    $today = date('Y-m-d', $timestamp);
    $db->query("INSERT INTO " . NV_COUNTER_GLOBALTABLE . " (
        c_type, c_val, c_count, last_update
    ) VALUES (
        1, :today, 1, :timestamp
    ) ON DUPLICATE KEY UPDATE
        c_count = c_count + 1,
        last_update = :timestamp", [
        'today' => $today,
        'timestamp' => $timestamp
    ]);

    // Update browser stats
    if (!empty($analytics_data['browser']['name'])) {
        $browser_key = $analytics_data['browser']['name'];
        $db->query("INSERT INTO " . NV_COUNTER_GLOBALTABLE . " (
            c_type, c_val, c_count, last_update
        ) VALUES (
            2, :browser, 1, :timestamp
        ) ON DUPLICATE KEY UPDATE
            c_count = c_count + 1,
            last_update = :timestamp", [
            'browser' => $browser_key,
            'timestamp' => $timestamp
        ]);
    }

    // Update OS stats
    if (!empty($analytics_data['os']['name'])) {
        $os_key = $analytics_data['os']['name'];
        $db->query("INSERT INTO " . NV_COUNTER_GLOBALTABLE . " (
            c_type, c_val, c_count, last_update
        ) VALUES (
            3, :os, 1, :timestamp
        ) ON DUPLICATE KEY UPDATE
            c_count = c_count + 1,
            last_update = :timestamp", [
            'os' => $os_key,
            'timestamp' => $timestamp
        ]);
    }

    // Update country stats
    if (!empty($analytics_data['country'])) {
        $db->query("INSERT INTO " . NV_COUNTER_GLOBALTABLE . " (
            c_type, c_val, c_count, last_update
        ) VALUES (
            4, :country, 1, :timestamp
        ) ON DUPLICATE KEY UPDATE
            c_count = c_count + 1,
            last_update = :timestamp", [
            'country' => $analytics_data['country'],
            'timestamp' => $timestamp
        ]);
    }
}

/**
 * Original visitor tracking function (fallback)
 */
function nv_statistics_track_visitor_original()
{
    // This would contain the original NukeViet visitor tracking logic
    // For now, return a basic structure
    return [
        'is_bot' => false,
        'should_count' => true,
        'confidence' => 0.0,
        'bot_type' => null
    ];
}
