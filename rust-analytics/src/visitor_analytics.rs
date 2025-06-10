//! Visitor analytics processing module

use crate::types::{
    AnalyticsData, BotClassification, BrowserInfo, DeviceInfo, DeviceType, OSInfo, PageInfo,
    SessionInfo, VisitorData,
};
use regex::Regex;
use std::collections::HashMap;

/// Process visitor data and extract analytics information
pub fn process_visitor_data(
    visitor: &VisitorData,
    bot_classification: &BotClassification,
) -> Result<AnalyticsData, Box<dyn std::error::Error>> {
    let browser_info = parse_browser_info(&visitor.user_agent)?;
    let os_info = parse_os_info(&visitor.user_agent)?;
    let device_info = parse_device_info(&visitor.user_agent)?;
    let page_info = parse_page_info(visitor)?;
    let session_info = parse_session_info(visitor)?;

    Ok(AnalyticsData {
        visitor_id: visitor.visitor_id.clone(),
        timestamp: visitor.timestamp,
        ip_address: visitor.ip_address.clone(),
        country: visitor.country.clone(),
        browser: browser_info,
        os: os_info,
        device: device_info,
        page_info,
        session_info,
        is_bot: bot_classification.is_bot,
        bot_classification: Some(bot_classification.clone()),
    })
}

/// Parse browser information from user agent
fn parse_browser_info(user_agent: &str) -> Result<BrowserInfo, Box<dyn std::error::Error>> {
    let ua_lower = user_agent.to_lowercase();

    // Browser detection patterns
    let browsers = vec![
        ("chrome", r"chrome/(\d+\.\d+)"),
        ("firefox", r"firefox/(\d+\.\d+)"),
        ("safari", r"version/(\d+\.\d+).*safari"),
        ("edge", r"edg/(\d+\.\d+)"),
        ("opera", r"opera/(\d+\.\d+)"),
        ("internet explorer", r"msie (\d+\.\d+)"),
        ("coccoc", r"coccoc/(\d+\.\d+)"),
    ];

    for (browser_name, pattern) in browsers {
        let regex = Regex::new(pattern)?;
        if let Some(captures) = regex.captures(&ua_lower) {
            let version = captures.get(1).map(|m| m.as_str().to_string());
            return Ok(BrowserInfo {
                name: browser_name.to_string(),
                version,
                engine: detect_browser_engine(&ua_lower),
            });
        }
    }

    // Check for bots
    if ua_lower.contains("bot") || ua_lower.contains("crawler") || ua_lower.contains("spider") {
        return Ok(BrowserInfo {
            name: "bot".to_string(),
            version: None,
            engine: None,
        });
    }

    Ok(BrowserInfo {
        name: "unknown".to_string(),
        version: None,
        engine: None,
    })
}

/// Detect browser engine
fn detect_browser_engine(user_agent: &str) -> Option<String> {
    if user_agent.contains("webkit") {
        Some("webkit".to_string())
    } else if user_agent.contains("gecko") {
        Some("gecko".to_string())
    } else if user_agent.contains("trident") {
        Some("trident".to_string())
    } else if user_agent.contains("presto") {
        Some("presto".to_string())
    } else {
        None
    }
}

/// Parse operating system information from user agent
fn parse_os_info(user_agent: &str) -> Result<OSInfo, Box<dyn std::error::Error>> {
    let ua_lower = user_agent.to_lowercase();

    // OS detection patterns
    let os_patterns = vec![
        ("windows", r"windows nt (\d+\.\d+)"),
        ("macos", r"mac os x (\d+[._]\d+)"),
        ("linux", r"linux"),
        ("android", r"android (\d+\.\d+)"),
        ("ios", r"os (\d+[._]\d+)"),
        ("ubuntu", r"ubuntu"),
        ("debian", r"debian"),
        ("centos", r"centos"),
        ("fedora", r"fedora"),
    ];

    for (os_name, pattern) in os_patterns {
        let regex = Regex::new(pattern)?;
        if let Some(captures) = regex.captures(&ua_lower) {
            let version = captures.get(1).map(|m| m.as_str().replace('_', "."));
            return Ok(OSInfo {
                name: os_name.to_string(),
                version,
                platform: detect_platform(&ua_lower),
            });
        }
    }

    Ok(OSInfo {
        name: "unknown".to_string(),
        version: None,
        platform: None,
    })
}

/// Detect platform architecture
fn detect_platform(user_agent: &str) -> Option<String> {
    if user_agent.contains("x86_64") || user_agent.contains("amd64") {
        Some("x64".to_string())
    } else if user_agent.contains("i386") || user_agent.contains("i686") {
        Some("x86".to_string())
    } else if user_agent.contains("arm64") || user_agent.contains("aarch64") {
        Some("arm64".to_string())
    } else if user_agent.contains("arm") {
        Some("arm".to_string())
    } else {
        None
    }
}

/// Parse device information from user agent
fn parse_device_info(user_agent: &str) -> Result<DeviceInfo, Box<dyn std::error::Error>> {
    let ua_lower = user_agent.to_lowercase();

    let is_mobile = ua_lower.contains("mobile")
        || ua_lower.contains("android")
        || ua_lower.contains("iphone")
        || ua_lower.contains("ipod");

    let is_tablet = ua_lower.contains("tablet") || ua_lower.contains("ipad");

    let is_bot =
        ua_lower.contains("bot") || ua_lower.contains("crawler") || ua_lower.contains("spider");

    let device_type = if is_bot {
        DeviceType::Bot
    } else if is_tablet {
        DeviceType::Tablet
    } else if is_mobile {
        DeviceType::Mobile
    } else {
        DeviceType::Desktop
    };

    Ok(DeviceInfo {
        device_type,
        is_mobile,
        is_tablet,
        screen_resolution: None, // Would need JavaScript to detect this
    })
}

/// Parse page information
fn parse_page_info(visitor: &VisitorData) -> Result<PageInfo, Box<dyn std::error::Error>> {
    Ok(PageInfo {
        url: visitor.request_uri.clone(),
        title: None, // Would need to be provided by the calling application
        referer: visitor.referer.clone(),
        language: visitor.language.clone(),
    })
}

/// Parse session information
fn parse_session_info(visitor: &VisitorData) -> Result<SessionInfo, Box<dyn std::error::Error>> {
    let is_new_session =
        visitor.session_id.is_none() || !visitor.cookies.contains_key("session_id");

    Ok(SessionInfo {
        session_id: visitor.session_id.clone(),
        is_new_session,
        page_views: 1,  // This would be tracked across requests
        duration: None, // Would be calculated from session start time
    })
}

/// Enhanced user agent parser with more detailed information
pub struct UserAgentParser {
    browser_patterns: HashMap<String, Regex>,
    os_patterns: HashMap<String, Regex>,
    device_patterns: HashMap<String, Regex>,
}

impl UserAgentParser {
    pub fn new() -> Result<Self, Box<dyn std::error::Error>> {
        let mut browser_patterns = HashMap::new();
        let mut os_patterns = HashMap::new();
        let mut device_patterns = HashMap::new();

        // Browser patterns
        browser_patterns.insert("chrome".to_string(), Regex::new(r"(?i)chrome/(\d+\.\d+)")?);
        browser_patterns.insert(
            "firefox".to_string(),
            Regex::new(r"(?i)firefox/(\d+\.\d+)")?,
        );
        browser_patterns.insert(
            "safari".to_string(),
            Regex::new(r"(?i)version/(\d+\.\d+).*safari")?,
        );
        browser_patterns.insert("edge".to_string(), Regex::new(r"(?i)edg/(\d+\.\d+)")?);
        browser_patterns.insert("opera".to_string(), Regex::new(r"(?i)opera/(\d+\.\d+)")?);
        browser_patterns.insert("ie".to_string(), Regex::new(r"(?i)msie (\d+\.\d+)")?);
        browser_patterns.insert("coccoc".to_string(), Regex::new(r"(?i)coccoc/(\d+\.\d+)")?);

        // OS patterns
        os_patterns.insert(
            "windows".to_string(),
            Regex::new(r"(?i)windows nt (\d+\.\d+)")?,
        );
        os_patterns.insert(
            "macos".to_string(),
            Regex::new(r"(?i)mac os x (\d+[._]\d+)")?,
        );
        os_patterns.insert("linux".to_string(), Regex::new(r"(?i)linux")?);
        os_patterns.insert(
            "android".to_string(),
            Regex::new(r"(?i)android (\d+\.\d+)")?,
        );
        os_patterns.insert("ios".to_string(), Regex::new(r"(?i)os (\d+[._]\d+)")?);

        // Device patterns
        device_patterns.insert("mobile".to_string(), Regex::new(r"(?i)mobile")?);
        device_patterns.insert("tablet".to_string(), Regex::new(r"(?i)tablet|ipad")?);
        device_patterns.insert("bot".to_string(), Regex::new(r"(?i)bot|crawler|spider")?);

        Ok(Self {
            browser_patterns,
            os_patterns,
            device_patterns,
        })
    }

    pub fn parse(
        &self,
        user_agent: &str,
    ) -> Result<(BrowserInfo, OSInfo, DeviceInfo), Box<dyn std::error::Error>> {
        let browser = self.parse_browser(user_agent)?;
        let os = self.parse_os(user_agent)?;
        let device = self.parse_device(user_agent)?;

        Ok((browser, os, device))
    }

    fn parse_browser(&self, user_agent: &str) -> Result<BrowserInfo, Box<dyn std::error::Error>> {
        for (name, pattern) in &self.browser_patterns {
            if let Some(captures) = pattern.captures(user_agent) {
                let version = captures.get(1).map(|m| m.as_str().to_string());
                return Ok(BrowserInfo {
                    name: name.clone(),
                    version,
                    engine: detect_browser_engine(&user_agent.to_lowercase()),
                });
            }
        }

        Ok(BrowserInfo {
            name: "unknown".to_string(),
            version: None,
            engine: None,
        })
    }

    fn parse_os(&self, user_agent: &str) -> Result<OSInfo, Box<dyn std::error::Error>> {
        for (name, pattern) in &self.os_patterns {
            if let Some(captures) = pattern.captures(user_agent) {
                let version = captures.get(1).map(|m| m.as_str().replace('_', "."));
                return Ok(OSInfo {
                    name: name.clone(),
                    version,
                    platform: detect_platform(&user_agent.to_lowercase()),
                });
            }
        }

        Ok(OSInfo {
            name: "unknown".to_string(),
            version: None,
            platform: None,
        })
    }

    fn parse_device(&self, user_agent: &str) -> Result<DeviceInfo, Box<dyn std::error::Error>> {
        let _ua_lower = user_agent.to_lowercase();

        let is_bot = self.device_patterns["bot"].is_match(user_agent);
        let is_tablet = self.device_patterns["tablet"].is_match(user_agent);
        let is_mobile = self.device_patterns["mobile"].is_match(user_agent) && !is_tablet;

        let device_type = if is_bot {
            DeviceType::Bot
        } else if is_tablet {
            DeviceType::Tablet
        } else if is_mobile {
            DeviceType::Mobile
        } else {
            DeviceType::Desktop
        };

        Ok(DeviceInfo {
            device_type,
            is_mobile,
            is_tablet,
            screen_resolution: None,
        })
    }
}
