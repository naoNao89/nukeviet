//! WebAuthn implementation for hardware-based authentication

use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use crate::config;
use crate::error::{Result, TwoFAError};

/// WebAuthn service for managing FIDO2/WebAuthn authentication
pub struct WebAuthnService {
    pending_registrations: Arc<Mutex<HashMap<String, (String, u32)>>>,
    pending_authentications: Arc<Mutex<HashMap<String, (String, u32)>>>,
}

/// User credential information
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct UserCredential {
    pub credential_id: Vec<u8>,
    pub user_id: u32,
    pub counter: u32,
    pub public_key: Vec<u8>,
    pub created_at: chrono::DateTime<chrono::Utc>,
    pub last_used: Option<chrono::DateTime<chrono::Utc>>,
    pub name: String,
}

/// Registration result
#[derive(Debug, Serialize, Deserialize)]
pub struct RegistrationResult {
    pub challenge_id: String,
    pub challenge: String,
}

/// Authentication result
#[derive(Debug, Serialize, Deserialize)]
pub struct AuthenticationResult {
    pub challenge_id: String,
    pub challenge: String,
}

/// Mock credential for simplified implementation
#[derive(Debug, Serialize, Deserialize)]
pub struct MockCredential {
    pub id: String,
    pub public_key: Vec<u8>,
}

impl WebAuthnService {
    /// Create a new WebAuthn service
    pub fn new() -> Result<Self> {
        let config = config::get()?;

        if !config.webauthn.enabled {
            return Err(TwoFAError::Config("WebAuthn is disabled".to_string()));
        }

        Ok(Self {
            pending_registrations: Arc::new(Mutex::new(HashMap::new())),
            pending_authentications: Arc::new(Mutex::new(HashMap::new())),
        })
    }

    /// Start credential registration for a user
    pub fn start_registration(
        &self,
        user_id: u32,
        _username: &str,
        _display_name: &str,
        _existing_credentials: Vec<MockCredential>,
    ) -> Result<RegistrationResult> {
        let _config = config::get()?;

        let challenge_id = uuid::Uuid::new_v4().to_string();
        let challenge = format!("mock_challenge_{}", challenge_id);

        // Store pending registration
        let mut pending = self.pending_registrations.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;
        pending.insert(challenge_id.clone(), (challenge.clone(), user_id));

        Ok(RegistrationResult {
            challenge_id,
            challenge,
        })
    }
    
    /// Complete credential registration
    pub fn complete_registration(
        &self,
        challenge_id: &str,
        credential: MockCredential,
    ) -> Result<UserCredential> {
        let mut pending = self.pending_registrations.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;

        let (_challenge, user_id) = pending.remove(challenge_id)
            .ok_or_else(|| TwoFAError::InvalidInput("Invalid challenge ID".to_string()))?;

        let user_credential = UserCredential {
            credential_id: credential.id.as_bytes().to_vec(),
            user_id,
            counter: 0,
            public_key: credential.public_key,
            created_at: chrono::Utc::now(),
            last_used: None,
            name: format!("Security Key {}", chrono::Utc::now().format("%Y-%m-%d")),
        };

        Ok(user_credential)
    }
    
    /// Start authentication challenge
    pub fn start_authentication(
        &self,
        user_id: u32,
        credentials: Vec<MockCredential>,
    ) -> Result<AuthenticationResult> {
        if credentials.is_empty() {
            return Err(TwoFAError::InvalidInput("No credentials available for user".to_string()));
        }

        let challenge_id = uuid::Uuid::new_v4().to_string();
        let challenge = format!("auth_challenge_{}", challenge_id);

        // Store pending authentication
        let mut pending = self.pending_authentications.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;
        pending.insert(challenge_id.clone(), (challenge.clone(), user_id));

        Ok(AuthenticationResult {
            challenge_id,
            challenge,
        })
    }
    
    /// Complete authentication
    pub fn complete_authentication(
        &self,
        challenge_id: &str,
        credential: MockCredential,
        user_credentials: &mut [UserCredential],
    ) -> Result<bool> {
        let mut pending = self.pending_authentications.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;

        let (_challenge, _user_id) = pending.remove(challenge_id)
            .ok_or_else(|| TwoFAError::InvalidInput("Invalid challenge ID".to_string()))?;

        // For now, just verify that the credential ID matches one of the user's credentials
        let credential_id = credential.id.as_bytes().to_vec();
        let credential_found = user_credentials.iter_mut()
            .find(|uc| uc.credential_id == credential_id);

        if let Some(user_cred) = credential_found {
            user_cred.counter += 1;
            user_cred.last_used = Some(chrono::Utc::now());
            Ok(true)
        } else {
            Ok(false)
        }
    }
    
    /// Get supported authenticator types
    pub fn get_supported_authenticators() -> Vec<String> {
        vec![
            "platform".to_string(),      // Built-in authenticators (Touch ID, Face ID, Windows Hello)
            "cross-platform".to_string(), // External authenticators (USB keys, NFC)
        ]
    }
    
    /// Validate credential format
    pub fn validate_credential(credential: &MockCredential) -> Result<bool> {
        // Basic validation - check if credential has required fields
        if credential.id.is_empty() {
            return Ok(false);
        }

        // Additional validation could be added here
        Ok(true)
    }
    
    /// Clean up expired challenges
    pub fn cleanup_expired_challenges(&self) -> Result<()> {
        let _cutoff = chrono::Utc::now() - chrono::Duration::minutes(5);

        // Clean up registrations (in a real implementation, you'd track timestamps)
        let mut pending_reg = self.pending_registrations.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;
        pending_reg.clear(); // Simplified cleanup

        // Clean up authentications
        let mut pending_auth = self.pending_authentications.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;
        pending_auth.clear(); // Simplified cleanup

        Ok(())
    }
}

/// WebAuthn configuration helper
#[derive(Debug, Clone)]
pub struct WebAuthnConfig {
    pub rp_id: String,
    pub rp_name: String,
    pub rp_origin: String,
    pub timeout: u32,
}

impl WebAuthnConfig {
    pub fn from_config(config: &crate::config::WebAuthnConfig) -> Result<Self> {
        Ok(Self {
            rp_id: config.rp_id.clone(),
            rp_name: config.rp_name.clone(),
            rp_origin: config.rp_origin.clone(),
            timeout: config.timeout_ms,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config;
    
    #[tokio::test]
    async fn test_webauthn_service_creation() {
        config::init_test().unwrap();
        let service = WebAuthnService::new();
        assert!(service.is_ok());
    }

    #[tokio::test]
    async fn test_registration_start() {
        config::init_test().unwrap();
        let service = WebAuthnService::new().unwrap();

        let result = service.start_registration(
            1,
            "test@example.com",
            "Test User",
            vec![]
        );

        assert!(result.is_ok());
        let reg_result = result.unwrap();
        assert!(!reg_result.challenge_id.is_empty());
    }
}
