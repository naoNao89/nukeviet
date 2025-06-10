<?php

/**
 * NukeViet Analytics Engine PHP Integration
 * 
 * This class provides PHP integration with the Rust-based analytics engine
 * for advanced bot detection and visitor analytics.
 * 
 * @package NukeViet
 * @author The Augster
 * @copyright (C) 2024 NukeViet. All rights reserved
 * @license GNU/GPL version 2 or any later version
 * @see https://github.com/nukeviet/nukeviet
 */

if (!defined('NV_MAINFILE')) {
    exit('Stop!!!');
}

/**
 * NukeViet Analytics Engine wrapper class
 */
class NukeVietAnalytics
{
    private $ffi = null;
    private $engine = null;
    private $config = [];
    private $library_path = '';
    private $enabled = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->library_path = NV_ROOTDIR . '/rust-analytics/target/release/libnukeviet_analytics.so';
        
        // Check if FFI is available and library exists
        if (extension_loaded('ffi') && file_exists($this->library_path)) {
            $this->initializeFFI();
        } else {
            trigger_error('NukeViet Analytics: FFI extension not available or library not found', E_USER_WARNING);
        }
    }

    /**
     * Initialize FFI interface
     */
    private function initializeFFI()
    {
        try {
            $this->ffi = FFI::cdef('
                typedef struct AnalyticsEngine AnalyticsEngine;
                
                typedef enum {
                    Success = 0,
                    InvalidInput = 1,
                    JsonParseError = 2,
                    DatabaseError = 3,
                    ConfigError = 4,
                    EngineError = 5,
                    MemoryError = 6
                } AnalyticsErrorCode;
                
                typedef struct {
                    AnalyticsErrorCode error_code;
                    char* data;
                    char* error_message;
                } AnalyticsResult;
                
                AnalyticsEngine* nukeviet_analytics_init(const char* config_json);
                AnalyticsResult nukeviet_analytics_process_visitor(AnalyticsEngine* engine, const char* visitor_json);
                AnalyticsResult nukeviet_analytics_get_stats(AnalyticsEngine* engine, const char* period);
                AnalyticsResult nukeviet_analytics_is_bot(AnalyticsEngine* engine, const char* user_agent, const char* ip_address);
                char* nukeviet_analytics_version(void);
                char* nukeviet_analytics_test(void);
                AnalyticsResult nukeviet_analytics_validate_config(const char* config_json);
                void nukeviet_analytics_free_string(char* ptr);
                void nukeviet_analytics_free_result(AnalyticsResult result);
                void nukeviet_analytics_cleanup(AnalyticsEngine* engine);
            ', $this->library_path);
            
            $this->enabled = true;
        } catch (Exception $e) {
            trigger_error('NukeViet Analytics: Failed to initialize FFI - ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /**
     * Initialize analytics engine with configuration
     */
    public function initialize($config = [])
    {
        if (!$this->enabled) {
            return false;
        }

        // Default configuration
        $default_config = [
            'database_path' => NV_ROOTDIR . '/data/nukeviet_analytics.db',
            'bot_detection' => [
                'enabled' => true,
                'sensitivity' => 0.8,
                'confidence_threshold' => 0.7,
                'behavioral_analysis' => true,
                'ip_reputation' => true,
                'ml_classification' => true
            ],
            'analytics' => [
                'track_page_views' => true,
                'track_unique_visitors' => true,
                'track_session_duration' => true,
                'track_bounce_rate' => true,
                'track_geography' => true,
                'track_device_info' => true,
                'track_referrers' => true,
                'session_timeout' => 30,
                'data_retention_days' => 365,
                'real_time_processing' => true
            ],
            'performance' => [
                'enable_caching' => true,
                'cache_size_mb' => 64,
                'cache_ttl_seconds' => 300,
                'batch_size' => 100,
                'queue_size' => 1000,
                'worker_threads' => 4,
                'enable_compression' => true
            ],
            'integration' => [
                'debug_logging' => false,
                'log_level' => 'info'
            ]
        ];

        $this->config = array_merge_recursive($default_config, $config);
        $config_json = json_encode($this->config);

        try {
            $this->engine = $this->ffi->nukeviet_analytics_init($config_json);
            return !FFI::isNull($this->engine);
        } catch (Exception $e) {
            trigger_error('NukeViet Analytics: Failed to initialize engine - ' . $e->getMessage(), E_USER_WARNING);
            return false;
        }
    }

    /**
     * Process visitor data and get analytics result
     */
    public function processVisitor($visitor_data)
    {
        if (!$this->enabled || FFI::isNull($this->engine)) {
            return null;
        }

        // Prepare visitor data
        $visitor_json = json_encode([
            'visitor_id' => $visitor_data['visitor_id'] ?? uniqid('visitor_', true),
            'ip_address' => $visitor_data['ip_address'] ?? $_SERVER['REMOTE_ADDR'],
            'user_agent' => $visitor_data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'],
            'referer' => $visitor_data['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? null),
            'request_uri' => $visitor_data['request_uri'] ?? $_SERVER['REQUEST_URI'],
            'timestamp' => date('c'),
            'session_id' => $visitor_data['session_id'] ?? session_id(),
            'cookies' => $visitor_data['cookies'] ?? $_COOKIE,
            'headers' => $visitor_data['headers'] ?? getallheaders(),
            'country' => $visitor_data['country'] ?? null,
            'language' => $visitor_data['language'] ?? ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null)
        ]);

        try {
            $result = $this->ffi->nukeviet_analytics_process_visitor($this->engine, $visitor_json);
            return $this->handleResult($result);
        } catch (Exception $e) {
            trigger_error('NukeViet Analytics: Failed to process visitor - ' . $e->getMessage(), E_USER_WARNING);
            return null;
        }
    }

    /**
     * Check if a visitor is a bot (simplified interface)
     */
    public function isBot($user_agent = null, $ip_address = null)
    {
        if (!$this->enabled || FFI::isNull($this->engine)) {
            return false;
        }

        $user_agent = $user_agent ?? $_SERVER['HTTP_USER_AGENT'];
        $ip_address = $ip_address ?? $_SERVER['REMOTE_ADDR'];

        try {
            $result = $this->ffi->nukeviet_analytics_is_bot($this->engine, $user_agent, $ip_address);
            $data = $this->handleResult($result);
            
            return $data ? $data['is_bot'] : false;
        } catch (Exception $e) {
            trigger_error('NukeViet Analytics: Failed to check bot status - ' . $e->getMessage(), E_USER_WARNING);
            return false;
        }
    }

    /**
     * Get analytics statistics
     */
    public function getStatistics($period = 'today')
    {
        if (!$this->enabled || FFI::isNull($this->engine)) {
            return null;
        }

        try {
            $result = $this->ffi->nukeviet_analytics_get_stats($this->engine, $period);
            return $this->handleResult($result);
        } catch (Exception $e) {
            trigger_error('NukeViet Analytics: Failed to get statistics - ' . $e->getMessage(), E_USER_WARNING);
            return null;
        }
    }

    /**
     * Get engine version information
     */
    public function getVersion()
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $version_ptr = $this->ffi->nukeviet_analytics_version();
            if (!FFI::isNull($version_ptr)) {
                $version_json = FFI::string($version_ptr);
                $this->ffi->nukeviet_analytics_free_string($version_ptr);
                return json_decode($version_json, true);
            }
        } catch (Exception $e) {
            trigger_error('NukeViet Analytics: Failed to get version - ' . $e->getMessage(), E_USER_WARNING);
        }

        return null;
    }

    /**
     * Test if the analytics engine is working
     */
    public function test()
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $test_ptr = $this->ffi->nukeviet_analytics_test();
            if (!FFI::isNull($test_ptr)) {
                $test_json = FFI::string($test_ptr);
                $this->ffi->nukeviet_analytics_free_string($test_ptr);
                $test_data = json_decode($test_json, true);
                return $test_data['status'] === 'ok';
            }
        } catch (Exception $e) {
            trigger_error('NukeViet Analytics: Test failed - ' . $e->getMessage(), E_USER_WARNING);
        }

        return false;
    }

    /**
     * Handle FFI result structure
     */
    private function handleResult($result)
    {
        try {
            if ($result->error_code === 0) { // Success
                if (!FFI::isNull($result->data)) {
                    $json_data = FFI::string($result->data);
                    $this->ffi->nukeviet_analytics_free_result($result);
                    return json_decode($json_data, true);
                }
            } else {
                $error_message = 'Unknown error';
                if (!FFI::isNull($result->error_message)) {
                    $error_message = FFI::string($result->error_message);
                }
                trigger_error('NukeViet Analytics: ' . $error_message, E_USER_WARNING);
                $this->ffi->nukeviet_analytics_free_result($result);
            }
        } catch (Exception $e) {
            trigger_error('NukeViet Analytics: Failed to handle result - ' . $e->getMessage(), E_USER_WARNING);
        }

        return null;
    }

    /**
     * Check if analytics engine is enabled and available
     */
    public function isEnabled()
    {
        return $this->enabled && !FFI::isNull($this->engine);
    }

    /**
     * Destructor - cleanup resources
     */
    public function __destruct()
    {
        if ($this->enabled && !FFI::isNull($this->engine)) {
            try {
                $this->ffi->nukeviet_analytics_cleanup($this->engine);
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }
    }
}

/**
 * Global analytics instance
 */
$nv_analytics = null;

/**
 * Initialize global analytics instance
 */
function nv_analytics_init($config = [])
{
    global $nv_analytics;
    
    if ($nv_analytics === null) {
        $nv_analytics = new NukeVietAnalytics();
        $nv_analytics->initialize($config);
    }
    
    return $nv_analytics;
}

/**
 * Get global analytics instance
 */
function nv_analytics()
{
    global $nv_analytics;
    
    if ($nv_analytics === null) {
        $nv_analytics = nv_analytics_init();
    }
    
    return $nv_analytics;
}
