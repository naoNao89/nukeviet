<?php

/**
 * NukeViet Analytics Engine Installation Script
 * 
 * This script installs and configures the Rust-based analytics engine
 * for enhanced visitor statistics and bot detection.
 * 
 * @package NukeViet
 * @author The Augster
 * @copyright (C) 2024 NukeViet. All rights reserved
 * @license GNU/GPL version 2 or any later version
 */

define('NV_SYSTEM', true);
define('NV_ROOTDIR', dirname(__FILE__));

// Load NukeViet core
require_once NV_ROOTDIR . '/includes/mainfile.php';

// Check admin permissions
if (!defined('NV_IS_ADMIN') || !nv_user_in_groups($admin_info['admin_id'], 1)) {
    exit('Access denied. Admin privileges required.');
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

/**
 * Check system requirements
 */
function check_requirements()
{
    $requirements = [
        'php_version' => [
            'name' => 'PHP Version >= 7.4',
            'status' => version_compare(PHP_VERSION, '7.4.0', '>='),
            'current' => PHP_VERSION
        ],
        'ffi_extension' => [
            'name' => 'PHP FFI Extension',
            'status' => extension_loaded('ffi'),
            'current' => extension_loaded('ffi') ? 'Loaded' : 'Not loaded'
        ],
        'ffi_enabled' => [
            'name' => 'FFI Enabled',
            'status' => extension_loaded('ffi') && (ini_get('ffi.enable') == '1' || ini_get('ffi.enable') === true),
            'current' => ini_get('ffi.enable') ? 'Enabled' : 'Disabled'
        ],
        'rust_library' => [
            'name' => 'Rust Analytics Library',
            'status' => file_exists(NV_ROOTDIR . '/rust-analytics/target/release/libnukeviet_analytics.so') ||
                       file_exists(NV_ROOTDIR . '/rust-analytics/target/release/nukeviet_analytics.dll') ||
                       file_exists(NV_ROOTDIR . '/rust-analytics/target/release/libnukeviet_analytics.dylib'),
            'current' => 'Checking...'
        ],
        'data_directory' => [
            'name' => 'Data Directory Writable',
            'status' => is_writable(NV_ROOTDIR . '/data'),
            'current' => is_writable(NV_ROOTDIR . '/data') ? 'Writable' : 'Not writable'
        ]
    ];
    
    // Check which library file exists
    $library_files = [
        NV_ROOTDIR . '/rust-analytics/target/release/libnukeviet_analytics.so',
        NV_ROOTDIR . '/rust-analytics/target/release/nukeviet_analytics.dll',
        NV_ROOTDIR . '/rust-analytics/target/release/libnukeviet_analytics.dylib'
    ];
    
    $found_library = false;
    foreach ($library_files as $file) {
        if (file_exists($file)) {
            $requirements['rust_library']['current'] = basename($file) . ' found';
            $found_library = true;
            break;
        }
    }
    
    if (!$found_library) {
        $requirements['rust_library']['current'] = 'Library not found';
    }
    
    return $requirements;
}

/**
 * Install database tables
 */
function install_database_tables()
{
    global $db;
    
    try {
        // Create enhanced statistics table
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
            created_at int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_timestamp (timestamp),
            KEY idx_is_bot (is_bot),
            KEY idx_visitor_id (visitor_id),
            KEY idx_should_count (should_count),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Create analytics configuration table
        $db->query("CREATE TABLE IF NOT EXISTS " . NV_PREFIXLANG . "_statistics_config (
            id int(11) NOT NULL AUTO_INCREMENT,
            config_section varchar(100) NOT NULL,
            config_key varchar(100) NOT NULL,
            config_value text,
            config_type varchar(20) NOT NULL DEFAULT 'string',
            description text,
            created_at int(11) NOT NULL DEFAULT 0,
            updated_at int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY unique_config (config_section, config_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Insert default configuration
        $default_configs = [
            ['bot_detection', 'enabled', '1', 'boolean', 'Enable advanced bot detection'],
            ['bot_detection', 'sensitivity', '0.8', 'float', 'Bot detection sensitivity (0.0-1.0)'],
            ['bot_detection', 'confidence_threshold', '0.7', 'float', 'Minimum confidence for bot classification'],
            ['bot_detection', 'behavioral_analysis', '1', 'boolean', 'Enable behavioral analysis'],
            ['bot_detection', 'ip_reputation', '1', 'boolean', 'Enable IP reputation checking'],
            ['bot_detection', 'ml_classification', '1', 'boolean', 'Enable machine learning classification'],
            ['analytics', 'track_page_views', '1', 'boolean', 'Track page views'],
            ['analytics', 'track_unique_visitors', '1', 'boolean', 'Track unique visitors'],
            ['analytics', 'track_session_duration', '1', 'boolean', 'Track session duration'],
            ['analytics', 'track_bounce_rate', '1', 'boolean', 'Track bounce rate'],
            ['analytics', 'track_geography', '1', 'boolean', 'Track geographic data'],
            ['analytics', 'track_device_info', '1', 'boolean', 'Track device information'],
            ['analytics', 'track_referrers', '1', 'boolean', 'Track referrer information'],
            ['analytics', 'session_timeout', '30', 'integer', 'Session timeout in minutes'],
            ['analytics', 'data_retention_days', '365', 'integer', 'Data retention period in days'],
            ['analytics', 'real_time_processing', '1', 'boolean', 'Enable real-time processing'],
            ['performance', 'enable_caching', '1', 'boolean', 'Enable caching'],
            ['performance', 'cache_size_mb', '64', 'integer', 'Cache size in MB'],
            ['performance', 'cache_ttl_seconds', '300', 'integer', 'Cache TTL in seconds'],
            ['performance', 'batch_size', '100', 'integer', 'Batch processing size'],
            ['performance', 'queue_size', '1000', 'integer', 'Processing queue size'],
            ['performance', 'worker_threads', '4', 'integer', 'Number of worker threads'],
            ['performance', 'enable_compression', '1', 'boolean', 'Enable compression']
        ];
        
        $timestamp = time();
        foreach ($default_configs as $config) {
            $db->query("INSERT IGNORE INTO " . NV_PREFIXLANG . "_statistics_config (
                config_section, config_key, config_value, config_type, description, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)", $config + [$timestamp, $timestamp]);
        }
        
        return true;
    } catch (Exception $e) {
        throw new Exception('Database installation failed: ' . $e->getMessage());
    }
}

/**
 * Test analytics engine
 */
function test_analytics_engine()
{
    try {
        require_once NV_ROOTDIR . '/includes/core/nukeviet_analytics.php';
        
        $analytics = new NukeVietAnalytics();
        $analytics->initialize();
        
        if (!$analytics->isEnabled()) {
            throw new Exception('Analytics engine failed to initialize');
        }
        
        $test_result = $analytics->test();
        if (!$test_result) {
            throw new Exception('Analytics engine test failed');
        }
        
        $version = $analytics->getVersion();
        
        return [
            'status' => true,
            'version' => $version,
            'message' => 'Analytics engine is working correctly'
        ];
        
    } catch (Exception $e) {
        return [
            'status' => false,
            'message' => 'Analytics engine test failed: ' . $e->getMessage()
        ];
    }
}

/**
 * Create data directory and set permissions
 */
function setup_data_directory()
{
    $data_dir = NV_ROOTDIR . '/data';
    $analytics_db = $data_dir . '/nukeviet_analytics.db';
    
    if (!is_dir($data_dir)) {
        if (!mkdir($data_dir, 0755, true)) {
            throw new Exception('Failed to create data directory');
        }
    }
    
    if (!is_writable($data_dir)) {
        if (!chmod($data_dir, 0755)) {
            throw new Exception('Failed to set data directory permissions');
        }
    }
    
    // Create .htaccess to protect database file
    $htaccess_content = "Order Deny,Allow\nDeny from all\n";
    file_put_contents($data_dir . '/.htaccess', $htaccess_content);
    
    return true;
}

// Handle installation steps
switch ($step) {
    case 1:
        // Requirements check
        $requirements = check_requirements();
        $all_passed = true;
        foreach ($requirements as $req) {
            if (!$req['status']) {
                $all_passed = false;
                break;
            }
        }
        break;
        
    case 2:
        // Database installation
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                setup_data_directory();
                install_database_tables();
                $success = 'Database tables installed successfully!';
                header('Location: ?step=3');
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
        break;
        
    case 3:
        // Engine testing
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $test_result = test_analytics_engine();
            if ($test_result['status']) {
                $success = $test_result['message'];
                header('Location: ?step=4');
                exit;
            } else {
                $error = $test_result['message'];
            }
        }
        break;
        
    case 4:
        // Installation complete
        $success = 'NukeViet Analytics Engine installed successfully!';
        break;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NukeViet Analytics Engine Installation</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #2c3e50; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .step { background: #ecf0f1; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .step.active { background: #3498db; color: white; }
        .step.completed { background: #27ae60; color: white; }
        .requirement { padding: 10px; margin: 5px 0; border-radius: 3px; }
        .requirement.pass { background: #d5f4e6; color: #27ae60; }
        .requirement.fail { background: #fadbd8; color: #e74c3c; }
        .error { background: #fadbd8; color: #e74c3c; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .success { background: #d5f4e6; color: #27ae60; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .btn { background: #3498db; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #2980b9; }
        .btn.disabled { background: #bdc3c7; cursor: not-allowed; }
    </style>
</head>
<body>
    <div class="header">
        <h1>NukeViet Analytics Engine Installation</h1>
        <p>Enhanced visitor statistics with intelligent bot detection</p>
    </div>
    
    <div class="steps">
        <div class="step <?= $step == 1 ? 'active' : ($step > 1 ? 'completed' : '') ?>">
            Step 1: Requirements Check
        </div>
        <div class="step <?= $step == 2 ? 'active' : ($step > 2 ? 'completed' : '') ?>">
            Step 2: Database Installation
        </div>
        <div class="step <?= $step == 3 ? 'active' : ($step > 3 ? 'completed' : '') ?>">
            Step 3: Engine Testing
        </div>
        <div class="step <?= $step == 4 ? 'active' : '' ?>">
            Step 4: Installation Complete
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <?php if ($step == 1): ?>
        <h2>System Requirements</h2>
        <?php foreach ($requirements as $key => $req): ?>
            <div class="requirement <?= $req['status'] ? 'pass' : 'fail' ?>">
                <strong><?= $req['name'] ?>:</strong> 
                <?= $req['current'] ?> 
                <?= $req['status'] ? '✓' : '✗' ?>
            </div>
        <?php endforeach; ?>
        
        <?php if ($all_passed): ?>
            <p><a href="?step=2" class="btn">Continue to Database Installation</a></p>
        <?php else: ?>
            <p><span class="btn disabled">Fix requirements before continuing</span></p>
            <p><strong>Installation Requirements:</strong></p>
            <ul>
                <li>PHP 7.4 or higher with FFI extension enabled</li>
                <li>Rust analytics library compiled (run: <code>cd rust-analytics && cargo build --release</code>)</li>
                <li>Writable data directory</li>
            </ul>
        <?php endif; ?>
        
    <?php elseif ($step == 2): ?>
        <h2>Database Installation</h2>
        <p>This step will create the necessary database tables for enhanced analytics.</p>
        <form method="post">
            <p><button type="submit" class="btn">Install Database Tables</button></p>
        </form>
        
    <?php elseif ($step == 3): ?>
        <h2>Engine Testing</h2>
        <p>Testing the analytics engine to ensure it's working correctly.</p>
        <form method="post">
            <p><button type="submit" class="btn">Test Analytics Engine</button></p>
        </form>
        
    <?php elseif ($step == 4): ?>
        <h2>Installation Complete!</h2>
        <p>The NukeViet Analytics Engine has been successfully installed and configured.</p>
        
        <h3>What's Next?</h3>
        <ul>
            <li><strong>Admin Panel:</strong> Configure analytics settings in the Statistics module admin panel</li>
            <li><strong>Integration:</strong> The enhanced analytics will automatically start tracking visitors</li>
            <li><strong>Monitoring:</strong> Check the analytics dashboard for detailed visitor statistics</li>
        </ul>
        
        <h3>Features Enabled:</h3>
        <ul>
            <li>✓ Advanced bot detection with machine learning</li>
            <li>✓ Real-time visitor analytics</li>
            <li>✓ Enhanced device and browser detection</li>
            <li>✓ Geographic tracking</li>
            <li>✓ Session analysis</li>
            <li>✓ Accurate visitor counting (excluding bots)</li>
        </ul>
        
        <p><a href="<?= NV_BASE_ADMINURL ?>index.php?<?= NV_LANG_VARIABLE ?>=<?= NV_LANG_DATA ?>&<?= NV_NAME_VARIABLE ?>=statistics" class="btn">Go to Statistics Admin</a></p>
    <?php endif; ?>
    
    <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #bdc3c7; color: #7f8c8d; font-size: 12px;">
        <p>NukeViet Analytics Engine v1.0.0 | Enhanced by The Augster</p>
    </div>
</body>
</html>
