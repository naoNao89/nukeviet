//! OneAuth integration for Zoho's multi-factor authentication

use reqwest::Client;
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::time::Duration;
use crate::config;
use crate::error::{Result, TwoFAError};

/// OneAuth client for API interactions
pub struct OneAuthClient {
    client: Client,
    base_url: String,
    client_id: String,
    client_secret: String,
    access_token: Option<String>,
}

/// OneAuth authentication request
#[derive(Debug, Serialize)]
pub struct AuthRequest {
    pub user_id: String,
    pub method: AuthMethod,
    pub device_info: DeviceInfo,
}

/// OneAuth authentication methods
#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum AuthMethod {
    Push,
    Totp,
    Sms,
    Email,
    Biometric,
}

/// Device information for OneAuth
#[derive(Debug, Serialize, Deserialize)]
pub struct DeviceInfo {
    pub device_id: String,
    pub device_name: String,
    pub platform: String,
    pub app_version: String,
}

/// OneAuth authentication response
#[derive(Debug, Deserialize)]
pub struct AuthResponse {
    pub status: String,
    pub transaction_id: String,
    pub message: String,
    pub expires_in: Option<u64>,
}

/// OneAuth token response
#[derive(Debug, Deserialize)]
pub struct TokenResponse {
    pub access_token: String,
    pub token_type: String,
    pub expires_in: u64,
    pub refresh_token: Option<String>,
}

/// OneAuth verification request
#[derive(Debug, Serialize)]
pub struct VerificationRequest {
    pub transaction_id: String,
    pub code: Option<String>,
    pub biometric_data: Option<String>,
}

/// OneAuth verification response
#[derive(Debug, Deserialize)]
pub struct VerificationResponse {
    pub status: String,
    pub verified: bool,
    pub message: String,
}

/// OneAuth user enrollment
#[derive(Debug, Serialize)]
pub struct EnrollmentRequest {
    pub user_id: String,
    pub email: String,
    pub phone: Option<String>,
    pub methods: Vec<AuthMethod>,
}

/// OneAuth enrollment response
#[derive(Debug, Deserialize)]
pub struct EnrollmentResponse {
    pub status: String,
    pub enrollment_id: String,
    pub qr_code: Option<String>,
    pub setup_url: Option<String>,
}

impl OneAuthClient {
    /// Create a new OneAuth client
    pub fn new() -> Result<Self> {
        let config = config::get()?;
        
        if !config.oneauth.enabled {
            return Err(TwoFAError::Config("OneAuth is disabled".to_string()));
        }
        
        let client = Client::builder()
            .timeout(Duration::from_secs(config.oneauth.timeout_seconds))
            .build()
            .map_err(|e| TwoFAError::Http(e))?;
        
        Ok(Self {
            client,
            base_url: config.oneauth.api_url.clone(),
            client_id: config.oneauth.client_id.clone(),
            client_secret: config.oneauth.client_secret.clone(),
            access_token: None,
        })
    }
    
    /// Authenticate with OneAuth API
    pub async fn authenticate(&mut self) -> Result<()> {
        let mut params = HashMap::new();
        params.insert("grant_type", "client_credentials");
        params.insert("client_id", &self.client_id);
        params.insert("client_secret", &self.client_secret);
        params.insert("scope", "oneauth.verify");
        
        let response = self.client
            .post(&format!("{}/oauth/v2/token", self.base_url))
            .form(&params)
            .send()
            .await
            .map_err(|e| TwoFAError::Http(e))?;
        
        if !response.status().is_success() {
            let error_text = response.text().await.unwrap_or_default();
            return Err(TwoFAError::OneAuthApi(format!("Authentication failed: {}", error_text)));
        }
        
        let token_response: TokenResponse = response.json().await
            .map_err(|e| TwoFAError::Http(e))?;
        
        self.access_token = Some(token_response.access_token);
        Ok(())
    }
    
    /// Enroll a user with OneAuth
    pub async fn enroll_user(&mut self, request: EnrollmentRequest) -> Result<EnrollmentResponse> {
        self.ensure_authenticated().await?;
        
        let response = self.client
            .post(&format!("{}/api/v1/users/enroll", self.base_url))
            .bearer_auth(self.access_token.as_ref().unwrap())
            .json(&request)
            .send()
            .await
            .map_err(|e| TwoFAError::Http(e))?;
        
        if !response.status().is_success() {
            let error_text = response.text().await.unwrap_or_default();
            return Err(TwoFAError::OneAuthApi(format!("Enrollment failed: {}", error_text)));
        }
        
        let enrollment_response: EnrollmentResponse = response.json().await
            .map_err(|e| TwoFAError::Http(e))?;
        
        Ok(enrollment_response)
    }
    
    /// Initiate authentication with OneAuth
    pub async fn initiate_auth(&mut self, request: AuthRequest) -> Result<AuthResponse> {
        self.ensure_authenticated().await?;
        
        let response = self.client
            .post(&format!("{}/api/v1/auth/initiate", self.base_url))
            .bearer_auth(self.access_token.as_ref().unwrap())
            .json(&request)
            .send()
            .await
            .map_err(|e| TwoFAError::Http(e))?;
        
        if !response.status().is_success() {
            let error_text = response.text().await.unwrap_or_default();
            return Err(TwoFAError::OneAuthApi(format!("Auth initiation failed: {}", error_text)));
        }
        
        let auth_response: AuthResponse = response.json().await
            .map_err(|e| TwoFAError::Http(e))?;
        
        Ok(auth_response)
    }
    
    /// Verify authentication with OneAuth
    pub async fn verify_auth(&mut self, request: VerificationRequest) -> Result<VerificationResponse> {
        self.ensure_authenticated().await?;
        
        let response = self.client
            .post(&format!("{}/api/v1/auth/verify", self.base_url))
            .bearer_auth(self.access_token.as_ref().unwrap())
            .json(&request)
            .send()
            .await
            .map_err(|e| TwoFAError::Http(e))?;
        
        if !response.status().is_success() {
            let error_text = response.text().await.unwrap_or_default();
            return Err(TwoFAError::OneAuthApi(format!("Verification failed: {}", error_text)));
        }
        
        let verification_response: VerificationResponse = response.json().await
            .map_err(|e| TwoFAError::Http(e))?;
        
        Ok(verification_response)
    }
    
    /// Send push notification for authentication
    pub async fn send_push_notification(&mut self, user_id: &str, _message: &str) -> Result<AuthResponse> {
        let device_info = DeviceInfo {
            device_id: uuid::Uuid::new_v4().to_string(),
            device_name: "NukeViet CMS".to_string(),
            platform: "web".to_string(),
            app_version: "1.0.0".to_string(),
        };
        
        let auth_request = AuthRequest {
            user_id: user_id.to_string(),
            method: AuthMethod::Push,
            device_info,
        };
        
        self.initiate_auth(auth_request).await
    }
    
    /// Verify biometric authentication
    pub async fn verify_biometric(&mut self, transaction_id: &str, biometric_data: &str) -> Result<bool> {
        let verification_request = VerificationRequest {
            transaction_id: transaction_id.to_string(),
            code: None,
            biometric_data: Some(biometric_data.to_string()),
        };
        
        let response = self.verify_auth(verification_request).await?;
        Ok(response.verified)
    }
    
    /// Verify TOTP code through OneAuth
    pub async fn verify_totp(&mut self, transaction_id: &str, code: &str) -> Result<bool> {
        let verification_request = VerificationRequest {
            transaction_id: transaction_id.to_string(),
            code: Some(code.to_string()),
            biometric_data: None,
        };
        
        let response = self.verify_auth(verification_request).await?;
        Ok(response.verified)
    }
    
    /// Check if user is enrolled with OneAuth
    pub async fn is_user_enrolled(&mut self, user_id: &str) -> Result<bool> {
        self.ensure_authenticated().await?;
        
        let response = self.client
            .get(&format!("{}/api/v1/users/{}/status", self.base_url, user_id))
            .bearer_auth(self.access_token.as_ref().unwrap())
            .send()
            .await
            .map_err(|e| TwoFAError::Http(e))?;
        
        Ok(response.status().is_success())
    }
    
    /// Ensure the client is authenticated
    async fn ensure_authenticated(&mut self) -> Result<()> {
        if self.access_token.is_none() {
            self.authenticate().await?;
        }
        Ok(())
    }
}

/// Helper functions for OneAuth integration
pub mod helpers {
    use super::*;
    
    /// Create device info from user agent and IP
    pub fn create_device_info(user_agent: &str, ip_address: &str) -> DeviceInfo {
        let device_id = format!("{}_{}", ip_address, 
            ring::digest::digest(&ring::digest::SHA256, user_agent.as_bytes())
                .as_ref()
                .iter()
                .map(|b| format!("{:02x}", b))
                .collect::<String>()[..16].to_string()
        );
        
        DeviceInfo {
            device_id,
            device_name: "Web Browser".to_string(),
            platform: parse_platform(user_agent),
            app_version: "1.0.0".to_string(),
        }
    }
    
    /// Parse platform from user agent
    fn parse_platform(user_agent: &str) -> String {
        if user_agent.contains("Windows") {
            "Windows".to_string()
        } else if user_agent.contains("Mac") {
            "macOS".to_string()
        } else if user_agent.contains("Linux") {
            "Linux".to_string()
        } else if user_agent.contains("Android") {
            "Android".to_string()
        } else if user_agent.contains("iPhone") || user_agent.contains("iPad") {
            "iOS".to_string()
        } else {
            "Unknown".to_string()
        }
    }
    
    /// Validate OneAuth configuration
    pub fn validate_config(config: &crate::config::OneAuthConfig) -> Result<()> {
        if config.enabled {
            if config.client_id.is_empty() {
                return Err(TwoFAError::Config("OneAuth client ID is required".to_string()));
            }
            
            if config.client_secret.is_empty() {
                return Err(TwoFAError::Config("OneAuth client secret is required".to_string()));
            }
            
            if !config.api_url.starts_with("https://") {
                return Err(TwoFAError::Config("OneAuth API URL must use HTTPS".to_string()));
            }
        }
        
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    
    #[tokio::test]
    async fn test_device_info_creation() {
        let user_agent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36";
        let ip = "192.168.1.1";
        
        let device_info = helpers::create_device_info(user_agent, ip);
        assert_eq!(device_info.platform, "Windows");
        assert!(!device_info.device_id.is_empty());
    }
    
    #[tokio::test]
    async fn test_config_validation() {
        let mut config = crate::config::OneAuthConfig {
            enabled: true,
            api_url: "https://api.example.com".to_string(),
            client_id: "test_id".to_string(),
            client_secret: "test_secret".to_string(),
            timeout_seconds: 30,
            retry_attempts: 3,
        };
        
        assert!(helpers::validate_config(&config).is_ok());
        
        config.client_id = String::new();
        assert!(helpers::validate_config(&config).is_err());
    }
}
