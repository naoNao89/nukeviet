//! Configuration management for the NukeViet 2FA service

use serde::{Deserialize, Serialize};
use std::sync::OnceLock;
use crate::error::{Result, TwoFAError};

/// Global configuration instance
static CONFIG: OnceLock<TwoFAConfig> = OnceLock::new();

/// Main configuration structure
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TwoFAConfig {
    /// Database configuration
    pub database: DatabaseConfig,
    
    /// OneAuth integration settings
    pub oneauth: OneAuthConfig,
    
    /// WebAuthn configuration
    pub webauthn: WebAuthnConfig,
    
    /// TOTP settings
    pub totp: TotpConfig,
    
    /// Rate limiting configuration
    pub rate_limit: RateLimitConfig,
    
    /// Security settings
    pub security: SecurityConfig,
    
    /// Logging configuration
    pub logging: LoggingConfig,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DatabaseConfig {
    pub url: String,
    pub max_connections: u32,
    pub timeout_seconds: u64,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct OneAuthConfig {
    pub enabled: bool,
    pub api_url: String,
    pub client_id: String,
    pub client_secret: String,
    pub timeout_seconds: u64,
    pub retry_attempts: u32,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct WebAuthnConfig {
    pub enabled: bool,
    pub rp_id: String,
    pub rp_name: String,
    pub rp_origin: String,
    pub timeout_ms: u32,
    pub user_verification: String, // "required", "preferred", "discouraged"
    pub authenticator_attachment: Option<String>, // "platform", "cross-platform"
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TotpConfig {
    pub issuer: String,
    pub algorithm: String, // "SHA1", "SHA256", "SHA512"
    pub digits: u32,
    pub step: u64,
    pub window: u8, // Number of time steps to check
    pub secret_length: usize,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RateLimitConfig {
    pub enabled: bool,
    pub max_attempts_per_minute: u32,
    pub max_attempts_per_hour: u32,
    pub lockout_duration_minutes: u32,
    pub cleanup_interval_minutes: u32,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SecurityConfig {
    pub require_secure_transport: bool,
    pub max_backup_codes: u32,
    pub backup_code_length: usize,
    pub secret_encryption_key: String,
    pub session_timeout_minutes: u32,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct LoggingConfig {
    pub level: String,
    pub security_events: bool,
    pub audit_trail: bool,
    pub log_file: Option<String>,
}

impl Default for TwoFAConfig {
    fn default() -> Self {
        Self {
            database: DatabaseConfig {
                url: "mysql://localhost/nukeviet".to_string(),
                max_connections: 10,
                timeout_seconds: 30,
            },
            oneauth: OneAuthConfig {
                enabled: false,
                api_url: "https://accounts.zoho.com".to_string(),
                client_id: String::new(),
                client_secret: String::new(),
                timeout_seconds: 30,
                retry_attempts: 3,
            },
            webauthn: WebAuthnConfig {
                enabled: true,
                rp_id: "localhost".to_string(),
                rp_name: "NukeViet CMS".to_string(),
                rp_origin: "https://localhost".to_string(),
                timeout_ms: 60000,
                user_verification: "preferred".to_string(),
                authenticator_attachment: None,
            },
            totp: TotpConfig {
                issuer: "NukeViet CMS".to_string(),
                algorithm: "SHA1".to_string(),
                digits: 6,
                step: 30,
                window: 1,
                secret_length: 32,
            },
            rate_limit: RateLimitConfig {
                enabled: true,
                max_attempts_per_minute: 5,
                max_attempts_per_hour: 20,
                lockout_duration_minutes: 15,
                cleanup_interval_minutes: 60,
            },
            security: SecurityConfig {
                require_secure_transport: true,
                max_backup_codes: 10,
                backup_code_length: 8,
                secret_encryption_key: String::new(),
                session_timeout_minutes: 30,
            },
            logging: LoggingConfig {
                level: "info".to_string(),
                security_events: true,
                audit_trail: true,
                log_file: None,
            },
        }
    }
}

/// Initialize configuration from JSON string
pub fn init_from_json(json: &str) -> Result<()> {
    let config: TwoFAConfig = serde_json::from_str(json)
        .map_err(|e| TwoFAError::Config(format!("Invalid JSON: {}", e)))?;
    
    validate_config(&config)?;
    
    CONFIG.set(config)
        .map_err(|_| TwoFAError::Config("Configuration already initialized".to_string()))?;
    
    Ok(())
}

/// Initialize configuration with default values
pub fn init_default() -> Result<()> {
    let config = TwoFAConfig::default();
    // For tests, allow re-initialization
    if CONFIG.get().is_none() {
        CONFIG.set(config)
            .map_err(|_| TwoFAError::Config("Configuration already initialized".to_string()))?;
    }

    Ok(())
}

/// Initialize configuration for tests (allows re-initialization)
#[cfg(test)]
pub fn init_test() -> Result<()> {
    use std::sync::Once;
    static INIT: Once = Once::new();

    INIT.call_once(|| {
        let config = TwoFAConfig::default();
        let _ = CONFIG.set(config);
    });

    Ok(())
}

/// Get the global configuration
pub fn get() -> Result<&'static TwoFAConfig> {
    CONFIG.get()
        .ok_or_else(|| TwoFAError::Config("Configuration not initialized".to_string()))
}

/// Validate configuration values
fn validate_config(config: &TwoFAConfig) -> Result<()> {
    // Validate TOTP settings
    if config.totp.digits < 6 || config.totp.digits > 8 {
        return Err(TwoFAError::Config("TOTP digits must be between 6 and 8".to_string()));
    }
    
    if config.totp.step < 15 || config.totp.step > 300 {
        return Err(TwoFAError::Config("TOTP step must be between 15 and 300 seconds".to_string()));
    }
    
    // Validate WebAuthn settings
    if config.webauthn.enabled {
        if config.webauthn.rp_id.is_empty() {
            return Err(TwoFAError::Config("WebAuthn RP ID cannot be empty".to_string()));
        }
        
        if config.webauthn.rp_name.is_empty() {
            return Err(TwoFAError::Config("WebAuthn RP name cannot be empty".to_string()));
        }
        
        if !config.webauthn.rp_origin.starts_with("https://") && config.webauthn.rp_origin != "http://localhost" {
            return Err(TwoFAError::Config("WebAuthn origin must use HTTPS or be localhost".to_string()));
        }
    }
    
    // Validate OneAuth settings
    if config.oneauth.enabled {
        if config.oneauth.client_id.is_empty() || config.oneauth.client_secret.is_empty() {
            return Err(TwoFAError::Config("OneAuth client ID and secret are required when enabled".to_string()));
        }
    }
    
    // Validate security settings
    if config.security.secret_encryption_key.len() < 32 {
        return Err(TwoFAError::Config("Encryption key must be at least 32 characters".to_string()));
    }
    
    Ok(())
}
