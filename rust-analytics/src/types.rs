//! Core data types for the analytics engine

use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use std::collections::HashMap;

/// Main analytics engine structure
pub struct AnalyticsEngine {
    pub config: crate::config::AnalyticsConfig,
    pub bot_detector: crate::bot_detection::BotDetector,
    pub database: crate::database::AnalyticsDatabase,
}

impl AnalyticsEngine {
    pub fn new(config: crate::config::AnalyticsConfig) -> Result<Self, Box<dyn std::error::Error>> {
        let bot_detector = crate::bot_detection::BotDetector::new(&config)?;
        let database = crate::database::AnalyticsDatabase::new(&config.database_path)?;

        Ok(Self {
            config,
            bot_detector,
            database,
        })
    }

    pub fn process_visitor(
        &mut self,
        visitor: VisitorData,
    ) -> Result<VisitorResult, Box<dyn std::error::Error>> {
        // Detect if visitor is a bot
        let bot_classification = self.bot_detector.classify_visitor(&visitor)?;

        // Process analytics data
        let analytics_data =
            crate::visitor_analytics::process_visitor_data(&visitor, &bot_classification)?;

        // Store in database
        self.database.store_visitor_data(&analytics_data)?;

        Ok(VisitorResult {
            visitor_id: visitor.visitor_id.clone(),
            is_bot: bot_classification.is_bot,
            bot_type: bot_classification.bot_type.clone(),
            confidence: bot_classification.confidence,
            should_count: !bot_classification.is_bot
                || bot_classification.bot_type == Some(BotType::SearchEngine),
            analytics_data,
        })
    }

    pub fn get_statistics(
        &self,
        period: &str,
    ) -> Result<AnalyticsStats, Box<dyn std::error::Error>> {
        self.database.get_statistics(period)
    }
}

/// Visitor data input structure
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct VisitorData {
    pub visitor_id: String,
    pub ip_address: String,
    pub user_agent: String,
    pub referer: Option<String>,
    pub request_uri: String,
    pub timestamp: DateTime<Utc>,
    pub session_id: Option<String>,
    pub cookies: HashMap<String, String>,
    pub headers: HashMap<String, String>,
    pub country: Option<String>,
    pub language: Option<String>,
}

/// Bot classification result
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct BotClassification {
    pub is_bot: bool,
    pub bot_type: Option<BotType>,
    pub confidence: f64,
    pub detection_method: DetectionMethod,
    pub bot_name: Option<String>,
    pub reasons: Vec<String>,
}

/// Types of bots
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub enum BotType {
    SearchEngine,
    SocialMedia,
    Scraper,
    Monitor,
    Security,
    Malicious,
    Unknown,
}

/// Detection methods used
#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum DetectionMethod {
    UserAgent,
    Behavioral,
    MachineLearning,
    IPReputation,
    Fingerprinting,
    Combined,
}

/// Processed visitor result
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct VisitorResult {
    pub visitor_id: String,
    pub is_bot: bool,
    pub bot_type: Option<BotType>,
    pub confidence: f64,
    pub should_count: bool,
    pub analytics_data: AnalyticsData,
}

/// Analytics data for storage
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AnalyticsData {
    pub visitor_id: String,
    pub timestamp: DateTime<Utc>,
    pub ip_address: String,
    pub country: Option<String>,
    pub browser: BrowserInfo,
    pub os: OSInfo,
    pub device: DeviceInfo,
    pub page_info: PageInfo,
    pub session_info: SessionInfo,
    pub is_bot: bool,
    pub bot_classification: Option<BotClassification>,
}

/// Browser information
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct BrowserInfo {
    pub name: String,
    pub version: Option<String>,
    pub engine: Option<String>,
}

/// Operating system information
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct OSInfo {
    pub name: String,
    pub version: Option<String>,
    pub platform: Option<String>,
}

/// Device information
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DeviceInfo {
    pub device_type: DeviceType,
    pub is_mobile: bool,
    pub is_tablet: bool,
    pub screen_resolution: Option<String>,
}

/// Device types
#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum DeviceType {
    Desktop,
    Mobile,
    Tablet,
    Bot,
    Unknown,
}

/// Page information
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PageInfo {
    pub url: String,
    pub title: Option<String>,
    pub referer: Option<String>,
    pub language: Option<String>,
}

/// Session information
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SessionInfo {
    pub session_id: Option<String>,
    pub is_new_session: bool,
    pub page_views: u32,
    pub duration: Option<u64>,
}

/// Analytics statistics
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AnalyticsStats {
    pub period: String,
    pub total_visitors: u64,
    pub unique_visitors: u64,
    pub bot_visitors: u64,
    pub human_visitors: u64,
    pub page_views: u64,
    pub bounce_rate: f64,
    pub avg_session_duration: f64,
    pub top_countries: Vec<CountryStats>,
    pub top_browsers: Vec<BrowserStats>,
    pub top_os: Vec<OSStats>,
    pub bot_breakdown: Vec<BotStats>,
}

/// Country statistics
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct CountryStats {
    pub country: String,
    pub visitors: u64,
    pub percentage: f64,
}

/// Browser statistics
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct BrowserStats {
    pub browser: String,
    pub visitors: u64,
    pub percentage: f64,
}

/// OS statistics
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct OSStats {
    pub os: String,
    pub visitors: u64,
    pub percentage: f64,
}

/// Bot statistics
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct BotStats {
    pub bot_type: BotType,
    pub count: u64,
    pub percentage: f64,
}
