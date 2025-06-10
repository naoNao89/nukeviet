//! Error handling for the NukeViet 2FA service

use thiserror::Error;

/// Result type alias for the 2FA service
pub type Result<T> = std::result::Result<T, TwoFAError>;

/// Comprehensive error types for 2FA operations
#[derive(Error, Debug)]
pub enum TwoFAError {
    #[error("Invalid TOTP code")]
    InvalidTotpCode,
    
    #[error("Invalid secret key: {0}")]
    InvalidSecret(String),
    
    #[error("Rate limit exceeded for user {user_id}")]
    RateLimitExceeded { user_id: u32 },
    
    #[error("WebAuthn error: {0}")]
    WebAuthn(String),
    
    #[error("OneAuth API error: {0}")]
    OneAuthApi(String),
    
    #[error("Configuration error: {0}")]
    Config(String),
    
    #[error("Cryptographic error: {0}")]
    Crypto(String),
    
    #[error("QR code generation error: {0}")]
    QrCode(String),
    
    #[error("Database error: {0}")]
    Database(#[from] sqlx::Error),
    
    #[error("HTTP request error: {0}")]
    Http(#[from] reqwest::Error),
    
    #[error("JSON serialization error: {0}")]
    Json(#[from] serde_json::Error),
    
    #[error("Time error: {0}")]
    Time(String),
    
    #[error("Invalid input: {0}")]
    InvalidInput(String),
    
    #[error("Service unavailable: {0}")]
    ServiceUnavailable(String),
    
    #[error("Authentication failed")]
    AuthenticationFailed,
    
    #[error("User not found")]
    UserNotFound,
    
    #[error("Backup code already used")]
    BackupCodeUsed,
    
    #[error("Invalid backup code")]
    InvalidBackupCode,
    
    #[error("Internal error: {0}")]
    Internal(String),
}

impl TwoFAError {
    /// Convert error to a numeric code for C FFI
    pub fn to_code(&self) -> i32 {
        match self {
            TwoFAError::InvalidTotpCode => -1001,
            TwoFAError::InvalidSecret(_) => -1002,
            TwoFAError::RateLimitExceeded { .. } => -1003,
            TwoFAError::WebAuthn(_) => -1004,
            TwoFAError::OneAuthApi(_) => -1005,
            TwoFAError::Config(_) => -1006,
            TwoFAError::Crypto(_) => -1007,
            TwoFAError::QrCode(_) => -1008,
            TwoFAError::Database(_) => -1009,
            TwoFAError::Http(_) => -1010,
            TwoFAError::Json(_) => -1011,
            TwoFAError::Time(_) => -1012,
            TwoFAError::InvalidInput(_) => -1013,
            TwoFAError::ServiceUnavailable(_) => -1014,
            TwoFAError::AuthenticationFailed => -1015,
            TwoFAError::UserNotFound => -1016,
            TwoFAError::BackupCodeUsed => -1017,
            TwoFAError::InvalidBackupCode => -1018,
            TwoFAError::Internal(_) => -1999,
        }
    }
    
    /// Check if the error is retryable
    pub fn is_retryable(&self) -> bool {
        matches!(
            self,
            TwoFAError::ServiceUnavailable(_) |
            TwoFAError::Http(_) |
            TwoFAError::Database(_)
        )
    }
    
    /// Check if the error should be logged as a security event
    pub fn is_security_event(&self) -> bool {
        matches!(
            self,
            TwoFAError::RateLimitExceeded { .. } |
            TwoFAError::AuthenticationFailed |
            TwoFAError::InvalidTotpCode |
            TwoFAError::InvalidBackupCode
        )
    }
}

/// Security event types for logging
#[derive(Debug, Clone, serde::Serialize)]
pub enum SecurityEvent {
    TotpVerificationFailed {
        user_id: u32,
        ip_address: String,
        timestamp: chrono::DateTime<chrono::Utc>,
    },
    RateLimitTriggered {
        user_id: u32,
        ip_address: String,
        timestamp: chrono::DateTime<chrono::Utc>,
    },
    BackupCodeUsed {
        user_id: u32,
        ip_address: String,
        timestamp: chrono::DateTime<chrono::Utc>,
    },
    WebAuthnChallengeFailed {
        user_id: u32,
        ip_address: String,
        timestamp: chrono::DateTime<chrono::Utc>,
    },
}

impl SecurityEvent {
    /// Convert to JSON for logging
    pub fn to_json(&self) -> Result<String> {
        Ok(serde_json::to_string(self)?)
    }
}
