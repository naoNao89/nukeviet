//! Advanced bot detection engine with machine learning capabilities

use crate::config::AnalyticsConfig;
use crate::types::{BotClassification, BotType, DetectionMethod, VisitorData};
use regex::Regex;
use std::collections::HashMap;
use std::sync::Arc;

/// Type alias for complex analysis result to improve readability
type AnalysisResult = Result<Option<(f64, Option<BotType>, String)>, Box<dyn std::error::Error>>;

/// Advanced bot detection engine
pub struct BotDetector {
    config: Arc<AnalyticsConfig>,
    user_agent_patterns: Vec<Regex>,
    ip_reputation_cache: HashMap<String, f64>,
    behavioral_analyzer: BehavioralAnalyzer,
    ml_classifier: MLClassifier,
}

impl BotDetector {
    pub fn new(config: &AnalyticsConfig) -> Result<Self, Box<dyn std::error::Error>> {
        let user_agent_patterns = Self::compile_patterns(&config.bot_detection.known_bot_patterns)?;

        Ok(Self {
            config: Arc::new(config.clone()),
            user_agent_patterns,
            ip_reputation_cache: HashMap::new(),
            behavioral_analyzer: BehavioralAnalyzer::new(),
            ml_classifier: MLClassifier::new()?,
        })
    }

    /// Classify a visitor as bot or human
    pub fn classify_visitor(
        &mut self,
        visitor: &VisitorData,
    ) -> Result<BotClassification, Box<dyn std::error::Error>> {
        let mut detection_scores = Vec::new();
        let mut reasons = Vec::new();

        // User agent analysis
        if let Some((score, _bot_type, reason)) = self.analyze_user_agent(&visitor.user_agent)? {
            detection_scores.push(score);
            if score > 0.5 {
                reasons.push(reason);
            }
        }

        // Behavioral analysis
        if self.config.bot_detection.behavioral_analysis {
            if let Some((score, reason)) = self.behavioral_analyzer.analyze(visitor)? {
                detection_scores.push(score);
                if score > 0.5 {
                    reasons.push(reason);
                }
            }
        }

        // IP reputation analysis
        if self.config.bot_detection.ip_reputation {
            if let Some((score, reason)) = self.analyze_ip_reputation(&visitor.ip_address)? {
                detection_scores.push(score);
                if score > 0.5 {
                    reasons.push(reason);
                }
            }
        }

        // Machine learning classification
        if self.config.bot_detection.ml_classification {
            if let Some((score, reason)) = self.ml_classifier.classify(visitor)? {
                detection_scores.push(score * 1.2); // Give ML higher weight
                if score > 0.5 {
                    reasons.push(reason);
                }
            }
        }

        // Calculate final confidence score
        let confidence = if detection_scores.is_empty() {
            0.0
        } else {
            detection_scores.iter().sum::<f64>() / detection_scores.len() as f64
        };

        let is_bot = confidence >= self.config.bot_detection.confidence_threshold;
        let bot_type = if is_bot {
            self.determine_bot_type(&visitor.user_agent, &reasons)
        } else {
            None
        };

        let detection_method = if detection_scores.len() > 1 {
            DetectionMethod::Combined
        } else if self.config.bot_detection.ml_classification {
            DetectionMethod::MachineLearning
        } else {
            DetectionMethod::UserAgent
        };

        Ok(BotClassification {
            is_bot,
            bot_type,
            confidence,
            detection_method,
            bot_name: self.extract_bot_name(&visitor.user_agent),
            reasons,
        })
    }

    fn compile_patterns(patterns: &[String]) -> Result<Vec<Regex>, regex::Error> {
        patterns
            .iter()
            .map(|pattern| Regex::new(&format!("(?i){}", pattern)))
            .collect()
    }

    fn analyze_user_agent(&self, user_agent: &str) -> AnalysisResult {
        let user_agent_lower = user_agent.to_lowercase();

        // Check against known bot patterns
        for pattern in &self.user_agent_patterns {
            if pattern.is_match(&user_agent_lower) {
                let bot_type = self.classify_bot_type_from_ua(&user_agent_lower);
                return Ok(Some((
                    0.9,
                    bot_type,
                    format!("User agent matches bot pattern: {}", pattern.as_str()),
                )));
            }
        }

        // Check for suspicious characteristics
        let mut suspicion_score = 0.0;
        let mut reasons = Vec::new();

        // Very short or very long user agents
        if user_agent.len() < 10 {
            suspicion_score += 0.3;
            reasons.push("Unusually short user agent".to_string());
        } else if user_agent.len() > 500 {
            suspicion_score += 0.4;
            reasons.push("Unusually long user agent".to_string());
        }

        // Missing common browser indicators
        if !user_agent_lower.contains("mozilla")
            && !user_agent_lower.contains("webkit")
            && !user_agent_lower.contains("gecko")
        {
            suspicion_score += 0.3;
            reasons.push("Missing common browser indicators".to_string());
        }

        // Programming language indicators
        let prog_languages = [
            "python", "java", "curl", "wget", "php", "ruby", "perl", "go",
        ];
        for lang in &prog_languages {
            if user_agent_lower.contains(lang) {
                suspicion_score += 0.6;
                let reason = format!("Contains programming language indicator: {}", lang);
                reasons.push(reason);
                break;
            }
        }

        if suspicion_score > 0.0 {
            Ok(Some((
                suspicion_score,
                Some(BotType::Unknown),
                reasons.join(", "),
            )))
        } else {
            Ok(None)
        }
    }

    fn classify_bot_type_from_ua(&self, user_agent: &str) -> Option<BotType> {
        if user_agent.contains("google")
            || user_agent.contains("bing")
            || user_agent.contains("yandex")
        {
            Some(BotType::SearchEngine)
        } else if user_agent.contains("facebook")
            || user_agent.contains("twitter")
            || user_agent.contains("linkedin")
        {
            Some(BotType::SocialMedia)
        } else if user_agent.contains("monitor") || user_agent.contains("uptime") {
            Some(BotType::Monitor)
        } else if user_agent.contains("security") || user_agent.contains("scan") {
            Some(BotType::Security)
        } else if user_agent.contains("scraper") || user_agent.contains("crawler") {
            Some(BotType::Scraper)
        } else {
            Some(BotType::Unknown)
        }
    }

    fn analyze_ip_reputation(
        &mut self,
        ip: &str,
    ) -> Result<Option<(f64, String)>, Box<dyn std::error::Error>> {
        // Check cache first
        if let Some(&score) = self.ip_reputation_cache.get(ip) {
            return Ok(if score > 0.5 {
                Some((score, "IP has poor reputation".to_string()))
            } else {
                None
            });
        }

        // Simple IP analysis (in real implementation, this would query reputation services)
        let mut risk_score = 0.0;

        // Check for common bot hosting providers
        let bot_hosting_patterns = [
            "amazonaws",
            "googlecloud",
            "azure",
            "digitalocean",
            "linode",
            "vultr",
            "ovh",
            "hetzner",
        ];

        // This is a simplified check - in production, you'd do reverse DNS lookup
        for pattern in &bot_hosting_patterns {
            if ip.contains(pattern) {
                risk_score += 0.3;
                break;
            }
        }

        // Cache the result
        self.ip_reputation_cache.insert(ip.to_string(), risk_score);

        Ok(if risk_score > 0.0 {
            Some((risk_score, "IP from known hosting provider".to_string()))
        } else {
            None
        })
    }

    fn determine_bot_type(&self, user_agent: &str, reasons: &[String]) -> Option<BotType> {
        // Analyze reasons and user agent to determine bot type
        let user_agent_lower = user_agent.to_lowercase();

        if user_agent_lower.contains("google") || user_agent_lower.contains("bing") {
            Some(BotType::SearchEngine)
        } else if reasons.iter().any(|r| r.contains("social")) {
            Some(BotType::SocialMedia)
        } else if reasons.iter().any(|r| r.contains("scraper")) {
            Some(BotType::Scraper)
        } else {
            Some(BotType::Unknown)
        }
    }

    fn extract_bot_name(&self, user_agent: &str) -> Option<String> {
        let user_agent_lower = user_agent.to_lowercase();

        let known_bots = [
            "googlebot",
            "bingbot",
            "yandexbot",
            "facebookexternalhit",
            "twitterbot",
            "linkedinbot",
            "coccocbot",
            "slurp",
        ];

        for bot in &known_bots {
            if user_agent_lower.contains(bot) {
                return Some(bot.to_string());
            }
        }

        None
    }
}

/// Behavioral analysis for bot detection
struct BehavioralAnalyzer {
    // In a real implementation, this would track visitor behavior patterns
}

impl BehavioralAnalyzer {
    fn new() -> Self {
        Self {}
    }

    fn analyze(
        &self,
        _visitor: &VisitorData,
    ) -> Result<Option<(f64, String)>, Box<dyn std::error::Error>> {
        // Placeholder for behavioral analysis
        // In a real implementation, this would analyze:
        // - Request frequency
        // - Navigation patterns
        // - Mouse movements (if available)
        // - Keyboard patterns
        // - Session duration
        Ok(None)
    }
}

/// Machine learning classifier for bot detection
struct MLClassifier {
    // In a real implementation, this would contain trained ML models
}

impl MLClassifier {
    fn new() -> Result<Self, Box<dyn std::error::Error>> {
        Ok(Self {})
    }

    fn classify(
        &self,
        _visitor: &VisitorData,
    ) -> Result<Option<(f64, String)>, Box<dyn std::error::Error>> {
        // Placeholder for ML classification
        // In a real implementation, this would use trained models to classify visitors
        Ok(None)
    }
}
