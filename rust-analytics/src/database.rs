//! Database module for analytics data storage

use crate::types::{
    AnalyticsData, AnalyticsStats, BotStats, BotType, BrowserStats, CountryStats, OSStats,
};
use chrono::Utc;
use rusqlite::{params, Connection};
use std::path::Path;

/// Analytics database manager
pub struct AnalyticsDatabase {
    conn: Connection,
}

impl AnalyticsDatabase {
    /// Create a new database connection
    pub fn new<P: AsRef<Path>>(db_path: P) -> Result<Self, Box<dyn std::error::Error>> {
        let conn = Connection::open(db_path)?;
        let db = Self { conn };
        db.initialize_schema()?;
        Ok(db)
    }

    /// Initialize database schema
    fn initialize_schema(&self) -> Result<(), Box<dyn std::error::Error>> {
        // Visitors table
        self.conn.execute(
            "CREATE TABLE IF NOT EXISTS visitors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visitor_id TEXT NOT NULL,
                timestamp INTEGER NOT NULL,
                ip_address TEXT NOT NULL,
                country TEXT,
                browser_name TEXT NOT NULL,
                browser_version TEXT,
                browser_engine TEXT,
                os_name TEXT NOT NULL,
                os_version TEXT,
                os_platform TEXT,
                device_type TEXT NOT NULL,
                is_mobile BOOLEAN NOT NULL,
                is_tablet BOOLEAN NOT NULL,
                page_url TEXT NOT NULL,
                page_title TEXT,
                referer TEXT,
                language TEXT,
                session_id TEXT,
                is_new_session BOOLEAN NOT NULL,
                is_bot BOOLEAN NOT NULL,
                bot_type TEXT,
                bot_name TEXT,
                bot_confidence REAL,
                detection_method TEXT,
                created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
            )",
            [],
        )?;

        // Sessions table
        self.conn.execute(
            "CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL UNIQUE,
                visitor_id TEXT NOT NULL,
                start_time INTEGER NOT NULL,
                end_time INTEGER,
                page_views INTEGER NOT NULL DEFAULT 1,
                duration INTEGER,
                is_bot BOOLEAN NOT NULL,
                created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
            )",
            [],
        )?;

        // Page views table
        self.conn.execute(
            "CREATE TABLE IF NOT EXISTS page_views (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visitor_id TEXT NOT NULL,
                session_id TEXT,
                page_url TEXT NOT NULL,
                page_title TEXT,
                referer TEXT,
                timestamp INTEGER NOT NULL,
                is_bot BOOLEAN NOT NULL,
                created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
            )",
            [],
        )?;

        // Create indexes for better performance
        self.conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_visitors_timestamp ON visitors(timestamp)",
            [],
        )?;

        self.conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_visitors_is_bot ON visitors(is_bot)",
            [],
        )?;

        self.conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_visitors_country ON visitors(country)",
            [],
        )?;

        self.conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_sessions_start_time ON sessions(start_time)",
            [],
        )?;

        self.conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_page_views_timestamp ON page_views(timestamp)",
            [],
        )?;

        Ok(())
    }

    /// Store visitor analytics data
    pub fn store_visitor_data(
        &self,
        data: &AnalyticsData,
    ) -> Result<(), Box<dyn std::error::Error>> {
        let timestamp = data.timestamp.timestamp();

        // Insert visitor data
        self.conn.execute(
            "INSERT INTO visitors (
                visitor_id, timestamp, ip_address, country,
                browser_name, browser_version, browser_engine,
                os_name, os_version, os_platform,
                device_type, is_mobile, is_tablet,
                page_url, page_title, referer, language,
                session_id, is_new_session, is_bot,
                bot_type, bot_name, bot_confidence, detection_method
            ) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16, ?17, ?18, ?19, ?20, ?21, ?22, ?23, ?24)",
            params![
                data.visitor_id,
                timestamp,
                data.ip_address,
                data.country,
                data.browser.name,
                data.browser.version,
                data.browser.engine,
                data.os.name,
                data.os.version,
                data.os.platform,
                format!("{:?}", data.device.device_type),
                data.device.is_mobile,
                data.device.is_tablet,
                data.page_info.url,
                data.page_info.title,
                data.page_info.referer,
                data.page_info.language,
                data.session_info.session_id,
                data.session_info.is_new_session,
                data.is_bot,
                data.bot_classification.as_ref().and_then(|bc| bc.bot_type.as_ref().map(|bt| format!("{:?}", bt))),
                data.bot_classification.as_ref().and_then(|bc| bc.bot_name.clone()),
                data.bot_classification.as_ref().map(|bc| bc.confidence),
                data.bot_classification.as_ref().map(|bc| format!("{:?}", bc.detection_method)),
            ],
        )?;

        // Insert page view
        self.conn.execute(
            "INSERT INTO page_views (
                visitor_id, session_id, page_url, page_title, referer, timestamp, is_bot
            ) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)",
            params![
                data.visitor_id,
                data.session_info.session_id,
                data.page_info.url,
                data.page_info.title,
                data.page_info.referer,
                timestamp,
                data.is_bot,
            ],
        )?;

        // Update or insert session data
        if let Some(session_id) = &data.session_info.session_id {
            if data.session_info.is_new_session {
                self.conn.execute(
                    "INSERT INTO sessions (
                        session_id, visitor_id, start_time, is_bot
                    ) VALUES (?1, ?2, ?3, ?4)",
                    params![session_id, data.visitor_id, timestamp, data.is_bot,],
                )?;
            } else {
                self.conn.execute(
                    "UPDATE sessions SET 
                        end_time = ?1,
                        page_views = page_views + 1,
                        duration = ?1 - start_time
                    WHERE session_id = ?2",
                    params![timestamp, session_id],
                )?;
            }
        }

        Ok(())
    }

    /// Get analytics statistics for a given period
    pub fn get_statistics(
        &self,
        period: &str,
    ) -> Result<AnalyticsStats, Box<dyn std::error::Error>> {
        let (start_time, end_time) = self.parse_period(period)?;

        // Total visitors
        let total_visitors: u64 = self.conn.query_row(
            "SELECT COUNT(*) FROM visitors WHERE timestamp BETWEEN ?1 AND ?2",
            params![start_time, end_time],
            |row| row.get(0),
        )?;

        // Unique visitors
        let unique_visitors: u64 = self.conn.query_row(
            "SELECT COUNT(DISTINCT visitor_id) FROM visitors WHERE timestamp BETWEEN ?1 AND ?2",
            params![start_time, end_time],
            |row| row.get(0),
        )?;

        // Bot visitors
        let bot_visitors: u64 = self.conn.query_row(
            "SELECT COUNT(*) FROM visitors WHERE timestamp BETWEEN ?1 AND ?2 AND is_bot = 1",
            params![start_time, end_time],
            |row| row.get(0),
        )?;

        let human_visitors = total_visitors - bot_visitors;

        // Page views
        let page_views: u64 = self.conn.query_row(
            "SELECT COUNT(*) FROM page_views WHERE timestamp BETWEEN ?1 AND ?2",
            params![start_time, end_time],
            |row| row.get(0),
        )?;

        // Bounce rate (sessions with only 1 page view)
        let single_page_sessions: u64 = self.conn.query_row(
            "SELECT COUNT(*) FROM sessions WHERE start_time BETWEEN ?1 AND ?2 AND page_views = 1",
            params![start_time, end_time],
            |row| row.get(0),
        )?;

        let total_sessions: u64 = self.conn.query_row(
            "SELECT COUNT(*) FROM sessions WHERE start_time BETWEEN ?1 AND ?2",
            params![start_time, end_time],
            |row| row.get(0),
        )?;

        let bounce_rate = if total_sessions > 0 {
            (single_page_sessions as f64 / total_sessions as f64) * 100.0
        } else {
            0.0
        };

        // Average session duration
        let avg_session_duration: f64 = self.conn.query_row(
            "SELECT AVG(duration) FROM sessions WHERE start_time BETWEEN ?1 AND ?2 AND duration IS NOT NULL",
            params![start_time, end_time],
            |row| row.get(0),
        ).unwrap_or(0.0);

        // Top countries
        let top_countries = self.get_top_countries(start_time, end_time)?;

        // Top browsers
        let top_browsers = self.get_top_browsers(start_time, end_time)?;

        // Top OS
        let top_os = self.get_top_os(start_time, end_time)?;

        // Bot breakdown
        let bot_breakdown = self.get_bot_breakdown(start_time, end_time)?;

        Ok(AnalyticsStats {
            period: period.to_string(),
            total_visitors,
            unique_visitors,
            bot_visitors,
            human_visitors,
            page_views,
            bounce_rate,
            avg_session_duration,
            top_countries,
            top_browsers,
            top_os,
            bot_breakdown,
        })
    }

    fn parse_period(&self, period: &str) -> Result<(i64, i64), Box<dyn std::error::Error>> {
        let now = Utc::now().timestamp();

        let start_time = match period {
            "today" => {
                let today = Utc::now()
                    .date_naive()
                    .and_hms_opt(0, 0, 0)
                    .unwrap()
                    .and_utc();
                today.timestamp()
            }
            "yesterday" => {
                let yesterday = Utc::now()
                    .date_naive()
                    .pred_opt()
                    .unwrap()
                    .and_hms_opt(0, 0, 0)
                    .unwrap()
                    .and_utc();
                yesterday.timestamp()
            }
            "week" => now - (7 * 24 * 60 * 60),
            "month" => now - (30 * 24 * 60 * 60),
            "year" => now - (365 * 24 * 60 * 60),
            _ => return Err("Invalid period".into()),
        };

        let end_time = if period == "yesterday" {
            let yesterday_end = Utc::now()
                .date_naive()
                .pred_opt()
                .unwrap()
                .and_hms_opt(23, 59, 59)
                .unwrap()
                .and_utc();
            yesterday_end.timestamp()
        } else {
            now
        };

        Ok((start_time, end_time))
    }

    fn get_top_countries(
        &self,
        start_time: i64,
        end_time: i64,
    ) -> Result<Vec<CountryStats>, Box<dyn std::error::Error>> {
        let mut stmt = self.conn.prepare(
            "SELECT country, COUNT(*) as count 
             FROM visitors 
             WHERE timestamp BETWEEN ?1 AND ?2 AND country IS NOT NULL AND is_bot = 0
             GROUP BY country 
             ORDER BY count DESC 
             LIMIT 10",
        )?;

        let total_visitors: u64 = self.conn.query_row(
            "SELECT COUNT(*) FROM visitors WHERE timestamp BETWEEN ?1 AND ?2 AND is_bot = 0",
            params![start_time, end_time],
            |row| row.get(0),
        )?;

        let rows = stmt.query_map(params![start_time, end_time], |row| {
            let country: String = row.get(0)?;
            let count: u64 = row.get(1)?;
            let percentage = if total_visitors > 0 {
                (count as f64 / total_visitors as f64) * 100.0
            } else {
                0.0
            };

            Ok(CountryStats {
                country,
                visitors: count,
                percentage,
            })
        })?;

        let mut countries = Vec::new();
        for row in rows {
            countries.push(row?);
        }

        Ok(countries)
    }

    fn get_top_browsers(
        &self,
        start_time: i64,
        end_time: i64,
    ) -> Result<Vec<BrowserStats>, Box<dyn std::error::Error>> {
        let mut stmt = self.conn.prepare(
            "SELECT browser_name, COUNT(*) as count 
             FROM visitors 
             WHERE timestamp BETWEEN ?1 AND ?2 AND is_bot = 0
             GROUP BY browser_name 
             ORDER BY count DESC 
             LIMIT 10",
        )?;

        let total_visitors: u64 = self.conn.query_row(
            "SELECT COUNT(*) FROM visitors WHERE timestamp BETWEEN ?1 AND ?2 AND is_bot = 0",
            params![start_time, end_time],
            |row| row.get(0),
        )?;

        let rows = stmt.query_map(params![start_time, end_time], |row| {
            let browser: String = row.get(0)?;
            let count: u64 = row.get(1)?;
            let percentage = if total_visitors > 0 {
                (count as f64 / total_visitors as f64) * 100.0
            } else {
                0.0
            };

            Ok(BrowserStats {
                browser,
                visitors: count,
                percentage,
            })
        })?;

        let mut browsers = Vec::new();
        for row in rows {
            browsers.push(row?);
        }

        Ok(browsers)
    }

    fn get_top_os(
        &self,
        start_time: i64,
        end_time: i64,
    ) -> Result<Vec<OSStats>, Box<dyn std::error::Error>> {
        let mut stmt = self.conn.prepare(
            "SELECT os_name, COUNT(*) as count 
             FROM visitors 
             WHERE timestamp BETWEEN ?1 AND ?2 AND is_bot = 0
             GROUP BY os_name 
             ORDER BY count DESC 
             LIMIT 10",
        )?;

        let total_visitors: u64 = self.conn.query_row(
            "SELECT COUNT(*) FROM visitors WHERE timestamp BETWEEN ?1 AND ?2 AND is_bot = 0",
            params![start_time, end_time],
            |row| row.get(0),
        )?;

        let rows = stmt.query_map(params![start_time, end_time], |row| {
            let os: String = row.get(0)?;
            let count: u64 = row.get(1)?;
            let percentage = if total_visitors > 0 {
                (count as f64 / total_visitors as f64) * 100.0
            } else {
                0.0
            };

            Ok(OSStats {
                os,
                visitors: count,
                percentage,
            })
        })?;

        let mut os_list = Vec::new();
        for row in rows {
            os_list.push(row?);
        }

        Ok(os_list)
    }

    fn get_bot_breakdown(
        &self,
        start_time: i64,
        end_time: i64,
    ) -> Result<Vec<BotStats>, Box<dyn std::error::Error>> {
        let mut stmt = self.conn.prepare(
            "SELECT bot_type, COUNT(*) as count 
             FROM visitors 
             WHERE timestamp BETWEEN ?1 AND ?2 AND is_bot = 1 AND bot_type IS NOT NULL
             GROUP BY bot_type 
             ORDER BY count DESC",
        )?;

        let total_bots: u64 = self.conn.query_row(
            "SELECT COUNT(*) FROM visitors WHERE timestamp BETWEEN ?1 AND ?2 AND is_bot = 1",
            params![start_time, end_time],
            |row| row.get(0),
        )?;

        let rows = stmt.query_map(params![start_time, end_time], |row| {
            let bot_type_str: String = row.get(0)?;
            let count: u64 = row.get(1)?;
            let percentage = if total_bots > 0 {
                (count as f64 / total_bots as f64) * 100.0
            } else {
                0.0
            };

            let bot_type = match bot_type_str.as_str() {
                "SearchEngine" => BotType::SearchEngine,
                "SocialMedia" => BotType::SocialMedia,
                "Scraper" => BotType::Scraper,
                "Monitor" => BotType::Monitor,
                "Security" => BotType::Security,
                "Malicious" => BotType::Malicious,
                _ => BotType::Unknown,
            };

            Ok(BotStats {
                bot_type,
                count,
                percentage,
            })
        })?;

        let mut bots = Vec::new();
        for row in rows {
            bots.push(row?);
        }

        Ok(bots)
    }
}
