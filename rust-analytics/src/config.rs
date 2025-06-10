//! Configuration module for the analytics engine

use serde::{Deserialize, Serialize};
use std::collections::HashMap;

/// Main configuration for the analytics engine
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AnalyticsConfig {
    /// Database file path
    pub database_path: String,

    /// Bot detection configuration
    pub bot_detection: BotDetectionConfig,

    /// Analytics processing configuration
    pub analytics: AnalyticsProcessingConfig,

    /// Performance and caching settings
    pub performance: PerformanceConfig,

    /// Integration settings
    pub integration: IntegrationConfig,
}

impl Default for AnalyticsConfig {
    fn default() -> Self {
        Self {
            database_path: "nukeviet_analytics.db".to_string(),
            bot_detection: BotDetectionConfig::default(),
            analytics: AnalyticsProcessingConfig::default(),
            performance: PerformanceConfig::default(),
            integration: IntegrationConfig::default(),
        }
    }
}

/// Bot detection configuration
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct BotDetectionConfig {
    /// Enable advanced bot detection
    pub enabled: bool,

    /// Detection sensitivity (0.0 to 1.0)
    pub sensitivity: f64,

    /// Known bot user agents patterns
    pub known_bot_patterns: Vec<String>,

    /// Whitelist of allowed bots (e.g., search engines)
    pub bot_whitelist: Vec<String>,

    /// Blacklist of blocked bots
    pub bot_blacklist: Vec<String>,

    /// Enable behavioral analysis
    pub behavioral_analysis: bool,

    /// Enable IP reputation checking
    pub ip_reputation: bool,

    /// Enable machine learning classification
    pub ml_classification: bool,

    /// Minimum confidence threshold for bot classification
    pub confidence_threshold: f64,
}

impl Default for BotDetectionConfig {
    fn default() -> Self {
        Self {
            enabled: true,
            sensitivity: 0.8,
            known_bot_patterns: vec![
                "bot".to_string(),
                "crawler".to_string(),
                "spider".to_string(),
                "scraper".to_string(),
                "googlebot".to_string(),
                "bingbot".to_string(),
                "yandexbot".to_string(),
                "facebookexternalhit".to_string(),
                "twitterbot".to_string(),
                "linkedinbot".to_string(),
                "coccocbot".to_string(),
            ],
            bot_whitelist: vec![
                "googlebot".to_string(),
                "bingbot".to_string(),
                "yandexbot".to_string(),
                "coccocbot".to_string(),
            ],
            bot_blacklist: vec![
                "scrapy".to_string(),
                "python-requests".to_string(),
                "curl".to_string(),
                "wget".to_string(),
            ],
            behavioral_analysis: true,
            ip_reputation: true,
            ml_classification: true,
            confidence_threshold: 0.7,
        }
    }
}

/// Analytics processing configuration
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AnalyticsProcessingConfig {
    /// Track page views
    pub track_page_views: bool,

    /// Track unique visitors
    pub track_unique_visitors: bool,

    /// Track session duration
    pub track_session_duration: bool,

    /// Track bounce rate
    pub track_bounce_rate: bool,

    /// Track geographic data
    pub track_geography: bool,

    /// Track device information
    pub track_device_info: bool,

    /// Track referrer information
    pub track_referrers: bool,

    /// Session timeout in minutes
    pub session_timeout: u32,

    /// Data retention period in days
    pub data_retention_days: u32,

    /// Real-time processing
    pub real_time_processing: bool,
}

impl Default for AnalyticsProcessingConfig {
    fn default() -> Self {
        Self {
            track_page_views: true,
            track_unique_visitors: true,
            track_session_duration: true,
            track_bounce_rate: true,
            track_geography: true,
            track_device_info: true,
            track_referrers: true,
            session_timeout: 30,
            data_retention_days: 365,
            real_time_processing: true,
        }
    }
}

/// Performance and caching configuration
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PerformanceConfig {
    /// Enable caching
    pub enable_caching: bool,

    /// Cache size in MB
    pub cache_size_mb: u32,

    /// Cache TTL in seconds
    pub cache_ttl_seconds: u32,

    /// Batch processing size
    pub batch_size: u32,

    /// Processing queue size
    pub queue_size: u32,

    /// Number of worker threads
    pub worker_threads: u32,

    /// Enable compression
    pub enable_compression: bool,
}

impl Default for PerformanceConfig {
    fn default() -> Self {
        Self {
            enable_caching: true,
            cache_size_mb: 64,
            cache_ttl_seconds: 300,
            batch_size: 100,
            queue_size: 1000,
            worker_threads: 4,
            enable_compression: true,
        }
    }
}

/// Integration configuration
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct IntegrationConfig {
    /// NukeViet database connection
    pub nukeviet_db: Option<DatabaseConfig>,

    /// External services configuration
    pub external_services: HashMap<String, ServiceConfig>,

    /// API endpoints
    pub api_endpoints: HashMap<String, String>,

    /// Webhook URLs
    pub webhooks: Vec<String>,

    /// Enable debug logging
    pub debug_logging: bool,

    /// Log level
    pub log_level: String,
}

impl Default for IntegrationConfig {
    fn default() -> Self {
        Self {
            nukeviet_db: None,
            external_services: HashMap::new(),
            api_endpoints: HashMap::new(),
            webhooks: Vec::new(),
            debug_logging: false,
            log_level: "info".to_string(),
        }
    }
}

/// Database configuration
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DatabaseConfig {
    pub host: String,
    pub port: u16,
    pub database: String,
    pub username: String,
    pub password: String,
    pub table_prefix: String,
}

/// External service configuration
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ServiceConfig {
    pub enabled: bool,
    pub api_key: Option<String>,
    pub endpoint: String,
    pub timeout_seconds: u32,
    pub retry_attempts: u32,
}

impl AnalyticsConfig {
    /// Load configuration from JSON string
    pub fn from_json(json: &str) -> Result<Self, serde_json::Error> {
        serde_json::from_str(json)
    }

    /// Save configuration to JSON string
    pub fn to_json(&self) -> Result<String, serde_json::Error> {
        serde_json::to_string_pretty(self)
    }

    /// Validate configuration
    pub fn validate(&self) -> Result<(), String> {
        if self.bot_detection.sensitivity < 0.0 || self.bot_detection.sensitivity > 1.0 {
            return Err("Bot detection sensitivity must be between 0.0 and 1.0".to_string());
        }

        if self.bot_detection.confidence_threshold < 0.0
            || self.bot_detection.confidence_threshold > 1.0
        {
            return Err("Confidence threshold must be between 0.0 and 1.0".to_string());
        }

        if self.analytics.session_timeout == 0 {
            return Err("Session timeout must be greater than 0".to_string());
        }

        if self.performance.worker_threads == 0 {
            return Err("Worker threads must be greater than 0".to_string());
        }

        Ok(())
    }
}
