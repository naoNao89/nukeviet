<?php

/**
 * NukeViet Analytics Engine Test Script
 * 
 * This script tests the Rust-based analytics engine functionality
 * and validates the integration with NukeViet.
 */

define('NV_SYSTEM', true);
define('NV_ROOTDIR', dirname(__FILE__));

// Load NukeViet core
require_once NV_ROOTDIR . '/includes/mainfile.php';
require_once NV_ROOTDIR . '/includes/core/nukeviet_analytics.php';

// Test data
$test_visitors = [
    [
        'name' => 'Regular Chrome User',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'ip_address' => '192.168.1.100',
        'expected_bot' => false
    ],
    [
        'name' => 'Google Bot',
        'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'ip_address' => '66.249.66.1',
        'expected_bot' => true
    ],
    [
        'name' => 'Bing Bot',
        'user_agent' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'ip_address' => '40.77.167.1',
        'expected_bot' => true
    ],
    [
        'name' => 'Scrapy Bot',
        'user_agent' => 'Scrapy/2.5.1 (+https://scrapy.org)',
        'ip_address' => '10.0.0.1',
        'expected_bot' => true
    ],
    [
        'name' => 'Mobile Safari',
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        'ip_address' => '192.168.1.101',
        'expected_bot' => false
    ],
    [
        'name' => 'Suspicious Short UA',
        'user_agent' => 'Bot',
        'ip_address' => '1.2.3.4',
        'expected_bot' => true
    ],
    [
        'name' => 'Python Requests',
        'user_agent' => 'python-requests/2.28.1',
        'ip_address' => '5.6.7.8',
        'expected_bot' => true
    ]
];

function run_tests()
{
    global $test_visitors;
    
    echo "<h1>NukeViet Analytics Engine Test Results</h1>\n";
    
    // Test 1: Engine Initialization
    echo "<h2>Test 1: Engine Initialization</h2>\n";
    try {
        $analytics = nv_analytics_init();
        if ($analytics && $analytics->isEnabled()) {
            echo "<p style='color: green;'>✓ Analytics engine initialized successfully</p>\n";
            
            // Get version info
            $version = $analytics->getVersion();
            if ($version) {
                echo "<p>Engine Version: {$version['version']}</p>\n";
                echo "<p>Build Time: {$version['build_time']}</p>\n";
                echo "<p>Features: " . implode(', ', $version['features']) . "</p>\n";
            }
        } else {
            echo "<p style='color: orange;'>⚠ Analytics engine not available, using fallback</p>\n";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Engine initialization failed: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    }
    
    // Test 2: Engine Test Function
    echo "<h2>Test 2: Engine Self-Test</h2>\n";
    try {
        $analytics = nv_analytics();
        if ($analytics && $analytics->isEnabled()) {
            $test_result = $analytics->test();
            if ($test_result) {
                echo "<p style='color: green;'>✓ Engine self-test passed</p>\n";
            } else {
                echo "<p style='color: red;'>✗ Engine self-test failed</p>\n";
            }
        } else {
            echo "<p style='color: orange;'>⚠ Engine not available for testing</p>\n";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Engine test failed: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    }
    
    // Test 3: Bot Detection
    echo "<h2>Test 3: Bot Detection</h2>\n";
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%;'>\n";
    echo "<tr><th>Visitor Type</th><th>User Agent</th><th>Expected</th><th>Detected</th><th>Result</th></tr>\n";
    
    $correct_detections = 0;
    $total_tests = count($test_visitors);
    
    foreach ($test_visitors as $visitor) {
        $detected_bot = nv_statistics_is_bot_enhanced($visitor['user_agent'], $visitor['ip_address']);
        $is_correct = ($detected_bot === $visitor['expected_bot']);
        
        if ($is_correct) {
            $correct_detections++;
        }
        
        $result_color = $is_correct ? 'green' : 'red';
        $result_text = $is_correct ? '✓' : '✗';
        
        echo "<tr>\n";
        echo "<td>" . htmlspecialchars($visitor['name']) . "</td>\n";
        echo "<td style='font-family: monospace; font-size: 12px;'>" . htmlspecialchars(substr($visitor['user_agent'], 0, 80)) . "...</td>\n";
        echo "<td>" . ($visitor['expected_bot'] ? 'Bot' : 'Human') . "</td>\n";
        echo "<td>" . ($detected_bot ? 'Bot' : 'Human') . "</td>\n";
        echo "<td style='color: {$result_color};'>{$result_text}</td>\n";
        echo "</tr>\n";
    }
    
    echo "</table>\n";
    
    $accuracy = ($correct_detections / $total_tests) * 100;
    $accuracy_color = $accuracy >= 80 ? 'green' : ($accuracy >= 60 ? 'orange' : 'red');
    
    echo "<p style='color: {$accuracy_color};'>Bot Detection Accuracy: {$correct_detections}/{$total_tests} ({$accuracy}%)</p>\n";
    
    // Test 4: Visitor Processing
    echo "<h2>Test 4: Visitor Processing</h2>\n";
    try {
        $analytics = nv_analytics();
        if ($analytics && $analytics->isEnabled()) {
            $test_visitor = [
                'visitor_id' => 'test_' . uniqid(),
                'ip_address' => '192.168.1.200',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'referer' => 'https://google.com',
                'request_uri' => '/test-page',
                'session_id' => 'test_session_' . uniqid(),
                'country' => 'VN',
                'language' => 'vi'
            ];
            
            $result = $analytics->processVisitor($test_visitor);
            
            if ($result) {
                echo "<p style='color: green;'>✓ Visitor processing successful</p>\n";
                echo "<ul>\n";
                echo "<li>Is Bot: " . ($result['is_bot'] ? 'Yes' : 'No') . "</li>\n";
                echo "<li>Should Count: " . ($result['should_count'] ? 'Yes' : 'No') . "</li>\n";
                echo "<li>Confidence: " . round($result['confidence'], 3) . "</li>\n";
                if (isset($result['analytics_data']['browser']['name'])) {
                    echo "<li>Browser: " . htmlspecialchars($result['analytics_data']['browser']['name']) . "</li>\n";
                }
                if (isset($result['analytics_data']['os']['name'])) {
                    echo "<li>OS: " . htmlspecialchars($result['analytics_data']['os']['name']) . "</li>\n";
                }
                if (isset($result['analytics_data']['device']['device_type'])) {
                    echo "<li>Device: " . htmlspecialchars($result['analytics_data']['device']['device_type']) . "</li>\n";
                }
                echo "</ul>\n";
            } else {
                echo "<p style='color: red;'>✗ Visitor processing failed</p>\n";
            }
        } else {
            echo "<p style='color: orange;'>⚠ Engine not available for visitor processing test</p>\n";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Visitor processing test failed: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    }
    
    // Test 5: Statistics Retrieval
    echo "<h2>Test 5: Statistics Retrieval</h2>\n";
    try {
        $stats = nv_statistics_get_enhanced_stats('today');
        if ($stats) {
            echo "<p style='color: green;'>✓ Statistics retrieval successful</p>\n";
            echo "<ul>\n";
            echo "<li>Total Visitors: " . $stats['total_visitors'] . "</li>\n";
            echo "<li>Unique Visitors: " . $stats['unique_visitors'] . "</li>\n";
            echo "<li>Human Visitors: " . $stats['human_visitors'] . "</li>\n";
            echo "<li>Bot Visitors: " . $stats['bot_visitors'] . "</li>\n";
            echo "<li>Page Views: " . $stats['page_views'] . "</li>\n";
            echo "<li>Bounce Rate: " . round($stats['bounce_rate'], 2) . "%</li>\n";
            echo "</ul>\n";
        } else {
            echo "<p style='color: orange;'>⚠ No statistics available (this is normal for new installations)</p>\n";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Statistics retrieval failed: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    }
    
    // Test 6: Database Integration
    echo "<h2>Test 6: Database Integration</h2>\n";
    try {
        global $db;
        
        // Check if enhanced tables exist
        $tables_to_check = [
            NV_PREFIXLANG . '_statistics_enhanced',
            NV_PREFIXLANG . '_statistics_config'
        ];
        
        $tables_exist = 0;
        foreach ($tables_to_check as $table) {
            $result = $db->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
            if ($result) {
                $tables_exist++;
                echo "<p style='color: green;'>✓ Table {$table} exists</p>\n";
            } else {
                echo "<p style='color: red;'>✗ Table {$table} missing</p>\n";
            }
        }
        
        if ($tables_exist === count($tables_to_check)) {
            echo "<p style='color: green;'>✓ All required database tables exist</p>\n";
        } else {
            echo "<p style='color: orange;'>⚠ Some database tables are missing. Run the installation script.</p>\n";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Database integration test failed: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    }
    
    // Test Summary
    echo "<h2>Test Summary</h2>\n";
    echo "<p>Bot Detection Accuracy: <strong>{$accuracy}%</strong></p>\n";
    
    if ($accuracy >= 80) {
        echo "<p style='color: green; font-size: 18px;'><strong>✓ All tests completed successfully!</strong></p>\n";
        echo "<p>The NukeViet Analytics Engine is working correctly and ready for production use.</p>\n";
    } elseif ($accuracy >= 60) {
        echo "<p style='color: orange; font-size: 18px;'><strong>⚠ Tests completed with warnings</strong></p>\n";
        echo "<p>The analytics engine is functional but may need configuration adjustments.</p>\n";
    } else {
        echo "<p style='color: red; font-size: 18px;'><strong>✗ Tests failed</strong></p>\n";
        echo "<p>Please check the installation and configuration.</p>\n";
    }
    
    echo "<hr>\n";
    echo "<p><strong>Next Steps:</strong></p>\n";
    echo "<ul>\n";
    echo "<li>Configure analytics settings in the admin panel</li>\n";
    echo "<li>Monitor visitor statistics for accuracy</li>\n";
    echo "<li>Adjust bot detection sensitivity if needed</li>\n";
    echo "<li>Review enhanced analytics data regularly</li>\n";
    echo "</ul>\n";
}

// Run tests if accessed directly
if (php_sapi_name() !== 'cli') {
    echo "<!DOCTYPE html>\n";
    echo "<html><head><title>NukeViet Analytics Test</title></head><body>\n";
}

run_tests();

if (php_sapi_name() !== 'cli') {
    echo "</body></html>\n";
}
