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

// Load enhanced analytics if available
if (file_exists(NV_ROOTDIR . '/includes/core/nukeviet_analytics.php')) {
    require_once NV_ROOTDIR . '/includes/core/nukeviet_analytics.php';

    // Initialize analytics engine
    $nv_analytics_enabled = false;
    try {
        $analytics = nv_analytics_init();
        $nv_analytics_enabled = $analytics && $analytics->isEnabled();
    } catch (Exception $e) {
        // Analytics engine not available, continue with original tracking
        $nv_analytics_enabled = false;
    }
}

/**
 * Enhanced visitor tracking with analytics engine
 */
function nv_stat_track_visitor_enhanced()
{
    global $db, $client_info, $global_config, $nv_Request, $nv_analytics_enabled;

    if ($nv_analytics_enabled) {
        try {
            // Prepare visitor data for analytics engine
            $visitor_data = [
                'visitor_id' => md5($client_info['ip'] . $client_info['agent']),
                'ip_address' => $client_info['ip'],
                'user_agent' => $client_info['agent'],
                'referer' => $nv_Request->get_string('HTTP_REFERER', 'server', ''),
                'request_uri' => $nv_Request->get_string('REQUEST_URI', 'server', ''),
                'session_id' => session_id(),
                'country' => $client_info['country'],
                'language' => NV_LANG_DATA
            ];

            // Process with analytics engine
            $analytics = nv_analytics();
            $result = $analytics->processVisitor($visitor_data);

            if ($result) {
                // Store enhanced analytics data
                nv_statistics_store_enhanced_data($result);

                // Update traditional counters only if should count
                if ($result['should_count']) {
                    nv_stat_update_traditional($result);
                }

                return $result;
            }
        } catch (Exception $e) {
            // Log error and fallback to traditional tracking
            error_log('NukeViet Analytics Error: ' . $e->getMessage());
        }
    }

    // Fallback to traditional tracking
    return nv_stat_update_traditional();
}

/**
 * Traditional stat update function (enhanced)
 *
 * @throws PDOException
 */
function nv_stat_update_traditional($analytics_result = null)
{
    global $db, $client_info, $global_config;

    $last_update = $db->query('SELECT c_count FROM ' . NV_COUNTER_GLOBALTABLE . " WHERE c_type = 'c_time' AND c_val= 'last'")->fetchColumn();

    if (NV_SITE_TIMEZONE_NAME == $global_config['statistics_timezone']) {
        $last_year = date('Y', $last_update);
        $last_month = date('M', $last_update);
        $last_day = date('d', $last_update);

        $current_year = date('Y', NV_CURRENTTIME);
        $current_month = date('M', NV_CURRENTTIME);
        $current_day = date('d', NV_CURRENTTIME);
        $current_hour = date('H', NV_CURRENTTIME);
        $current_week = date('l', NV_CURRENTTIME);
    } else {
        date_default_timezone_set($global_config['statistics_timezone']);
        $last_year = date('Y', $last_update);
        $last_month = date('M', $last_update);
        $last_day = date('d', $last_update);

        $current_year = date('Y', NV_CURRENTTIME);
        $current_month = date('M', NV_CURRENTTIME);
        $current_day = date('d', NV_CURRENTTIME);
        $current_hour = date('H', NV_CURRENTTIME);
        $current_week = date('l', NV_CURRENTTIME);
        date_default_timezone_set(NV_SITE_TIMEZONE_NAME);
    }

    if ($last_year != $current_year) {
        $year_exists = $db->query('SELECT COUNT(*) FROM ' . NV_COUNTER_GLOBALTABLE . " WHERE c_type='year' AND c_val='" . $current_year . "'")->fetchColumn();
        if (!$year_exists) {
            $db->query('INSERT INTO ' . NV_COUNTER_GLOBALTABLE . " (c_type, c_val) VALUES ('year', '" . $current_year . "')");
        }

        $db->query('UPDATE ' . NV_COUNTER_GLOBALTABLE . ' SET c_count= 0, ' . NV_LANG_DATA . "_count= 0 WHERE (c_type='month' OR c_type='day' OR c_type='hour')");
    } elseif ($last_month != $current_month) {
        $db->query('UPDATE ' . NV_COUNTER_GLOBALTABLE . ' SET c_count= 0, ' . NV_LANG_DATA . "_count= 0 WHERE (c_type='day' OR c_type='hour')");
    } elseif ($last_day != $current_day) {
        $db->query('UPDATE ' . NV_COUNTER_GLOBALTABLE . ' SET c_count= 0, ' . NV_LANG_DATA . "_count= 0 WHERE c_type='hour'");
    }

    // Determine if this visitor should be counted
    $should_count = true;
    $is_enhanced_bot = false;

    if ($analytics_result) {
        // Use analytics engine result
        $should_count = $analytics_result['should_count'];
        $is_enhanced_bot = $analytics_result['is_bot'];
        $bot_name = $analytics_result['bot_type'] ?? '';

        // Use enhanced browser/OS detection if available
        if (isset($analytics_result['analytics_data']['browser']['name'])) {
            $br = $analytics_result['analytics_data']['browser']['name'];
        } else {
            $br = $client_info['browser']['key'];
        }
    } else {
        // Traditional bot detection
        $bot_name = ($client_info['is_bot'] and !empty($client_info['browser']['name'])) ? $client_info['browser']['name'] : '';
        $br = $client_info['browser']['key'];

        // Enhanced bot filtering - don't count obvious bots
        if ($client_info['is_bot'] && !in_array(strtolower($bot_name), ['googlebot', 'bingbot', 'yandexbot', 'coccocbot'])) {
            $should_count = false;
        }
    }

    if (strcasecmp($br, 'unknown') === 0) {
        if ($client_info['is_mobile']) {
            $br = 'Mobile';
        } elseif (!empty($bot_name)) {
            $br = 'bots';
        }
    }

    // Only update counters if visitor should be counted
    if ($should_count) {
        $sth = $db->prepare(
            'UPDATE ' . NV_COUNTER_GLOBALTABLE . ' SET last_update=' . NV_CURRENTTIME . ', c_count=c_count + 1, ' . NV_LANG_DATA . '_count= ' . NV_LANG_DATA . "_count + 1 WHERE
            (c_type='total' AND c_val='hits') OR
            (c_type='year' AND c_val='" . $current_year . "') OR
            (c_type='month' AND c_val='" . $current_month . "') OR
            (c_type='day' AND c_val='" . $current_day . "') OR
            (c_type='dayofweek' AND c_val='" . $current_week . "') OR
            (c_type='hour' AND c_val='" . $current_hour . "') OR
            (c_type='browser' AND c_val= :browser) OR
            (c_type='os' AND c_val= :client_os) OR
            (c_type='country' AND c_val= :country)"
        );
        $sth->bindParam(':browser', $br, PDO::PARAM_STR);
        $sth->bindParam(':client_os', $client_info['client_os']['key'], PDO::PARAM_STR);
        $sth->bindParam(':country', $client_info['country'], PDO::PARAM_STR);
        $sth->execute();
    }

    // Always update bot counter for tracking purposes
    if (!empty($bot_name) || $is_enhanced_bot) {
        $bot_counter_name = $bot_name ?: 'unknown_bot';
        $db->query("INSERT INTO " . NV_COUNTER_GLOBALTABLE . " (c_type, c_val, c_count, last_update)
                   VALUES ('bot', " . $db->quote($bot_counter_name) . ", 1, " . NV_CURRENTTIME . ")
                   ON DUPLICATE KEY UPDATE c_count = c_count + 1, last_update = " . NV_CURRENTTIME);
    }

    $db->query('UPDATE ' . NV_COUNTER_GLOBALTABLE . ' SET c_count= ' . NV_CURRENTTIME . " WHERE c_type='c_time' AND c_val= 'last'");

    return [
        'should_count' => $should_count,
        'is_bot' => $is_enhanced_bot || $client_info['is_bot'],
        'bot_name' => $bot_name
    ];
}

// Enhanced visitor tracking
$tracking_result = nv_stat_track_visitor_enhanced();

// Đếm lại sau 30 phút khách truy cập không hoạt động
$nv_Request->set_Cookie('statistic_' . NV_LANG_DATA, NV_CURRENTTIME, 1800);

// Store tracking result for potential use by other modules
if ($tracking_result) {
    $GLOBALS['nv_visitor_tracking'] = $tracking_result;
}
