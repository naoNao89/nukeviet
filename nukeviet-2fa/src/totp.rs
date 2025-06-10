//! TOTP (Time-based One-Time Password) implementation

use totp_rs::{Algorithm, TOTP, Secret};
use crate::config;
use crate::error::{Result, TwoFAError};
use crate::rate_limit::RateLimiter;
use base64::{Engine as _, engine::general_purpose};
use zeroize::Zeroize;
use std::time::{SystemTime, UNIX_EPOCH};

/// Generate a new TOTP secret
pub fn generate_secret() -> Result<String> {
    let _config = config::get()?;

    // Generate a random 32-byte secret
    let rng = ring::rand::SystemRandom::new();
    let mut secret_bytes = [0u8; 32];
    rng.fill(&mut secret_bytes)
        .map_err(|_| TwoFAError::Crypto("Failed to generate secret".to_string()))?;

    // Encode as base32 for compatibility with authenticator apps
    let secret = base32::encode(base32::Alphabet::Rfc4648 { padding: false }, &secret_bytes);
    Ok(secret)
}

/// Create a TOTP instance from a secret
fn create_totp(secret: &str, _account: &str) -> Result<TOTP> {
    let config = config::get()?;

    let algorithm = match config.totp.algorithm.as_str() {
        "SHA1" => Algorithm::SHA1,
        "SHA256" => Algorithm::SHA256,
        "SHA512" => Algorithm::SHA512,
        _ => return Err(TwoFAError::Config("Invalid TOTP algorithm".to_string())),
    };

    let secret_bytes = Secret::Encoded(secret.to_string())
        .to_bytes()
        .map_err(|e| TwoFAError::InvalidSecret(e.to_string()))?;

    TOTP::new(
        algorithm,
        config.totp.digits as usize,
        1, // skew
        config.totp.step,
        secret_bytes,
    ).map_err(|e| TwoFAError::InvalidSecret(e.to_string()))
}

/// Generate the current TOTP code for a secret
pub fn generate_current_code(secret: &str, account: &str) -> Result<String> {
    let totp = create_totp(secret, account)?;
    let current_time = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|e| TwoFAError::Time(e.to_string()))?
        .as_secs();
    
    Ok(totp.generate(current_time))
}

/// Verify a TOTP code with rate limiting
pub fn verify_code(secret: &str, code: &str, user_id: u32) -> Result<bool> {
    // Check rate limiting first
    let mut rate_limiter = RateLimiter::new();
    if !rate_limiter.check_rate_limit(user_id)? {
        return Err(TwoFAError::RateLimitExceeded { user_id });
    }
    
    let config = config::get()?;
    let account = format!("user_{}", user_id);
    let totp = create_totp(secret, &account)?;
    
    let current_time = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|e| TwoFAError::Time(e.to_string()))?
        .as_secs();
    
    // Check current time and window
    for i in 0..=config.totp.window {
        let check_time = current_time.saturating_sub(i as u64 * config.totp.step);
        let expected_code = totp.generate(check_time);
        if constant_time_eq::constant_time_eq(code.as_bytes(), expected_code.as_bytes()) {
            return Ok(true);
        }

        if i > 0 {
            let check_time = current_time + i as u64 * config.totp.step;
            let expected_code = totp.generate(check_time);
            if constant_time_eq::constant_time_eq(code.as_bytes(), expected_code.as_bytes()) {
                return Ok(true);
            }
        }
    }
    
    Ok(false)
}

/// Generate a TOTP URI for QR code generation
pub fn generate_uri(secret: &str, account: &str, issuer: Option<&str>) -> Result<String> {
    let config = config::get()?;
    let issuer_name = issuer.unwrap_or(&config.totp.issuer);

    // Manually construct the TOTP URI
    let uri = format!(
        "otpauth://totp/{}:{}?secret={}&issuer={}&algorithm={}&digits={}&period={}",
        issuer_name,
        account,
        secret,
        issuer_name,
        config.totp.algorithm,
        config.totp.digits,
        config.totp.step
    );

    Ok(uri)
}

/// Validate a TOTP secret format
pub fn validate_secret(secret: &str) -> Result<()> {
    if secret.is_empty() {
        return Err(TwoFAError::InvalidSecret("Secret cannot be empty".to_string()));
    }

    // Try to decode the secret to validate format
    let _ = Secret::Encoded(secret.to_string())
        .to_bytes()
        .map_err(|e| TwoFAError::InvalidSecret(format!("Invalid secret format: {}", e)))?;

    Ok(())
}

/// Generate backup codes for a user
pub fn generate_backup_codes(count: usize) -> Result<Vec<String>> {
    let config = config::get()?;
    let mut codes = Vec::with_capacity(count);
    
    for _ in 0..count {
        let mut code = String::new();
        for _ in 0..config.security.backup_code_length {
            code.push(char::from(b'0' + (rand::random::<u8>() % 10)));
        }
        codes.push(code);
    }
    
    Ok(codes)
}

/// Verify a backup code
pub fn verify_backup_code(stored_hash: &str, provided_code: &str) -> Result<bool> {
    // Hash the provided code and compare with stored hash
    let provided_hash = hash_backup_code(provided_code)?;
    Ok(constant_time_eq::constant_time_eq(
        stored_hash.as_bytes(),
        provided_hash.as_bytes()
    ))
}

/// Hash a backup code for storage
pub fn hash_backup_code(code: &str) -> Result<String> {
    use ring::digest;
    
    let mut code_bytes = code.as_bytes().to_vec();
    let digest = digest::digest(&digest::SHA256, &code_bytes);
    code_bytes.zeroize(); // Clear sensitive data
    
    Ok(general_purpose::STANDARD.encode(digest.as_ref()))
}

/// Get the current time step for TOTP
pub fn get_current_time_step() -> Result<u64> {
    let config = config::get()?;
    let current_time = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|e| TwoFAError::Time(e.to_string()))?
        .as_secs();
    
    Ok(current_time / config.totp.step)
}

/// Calculate remaining time until next TOTP code
pub fn get_remaining_time() -> Result<u64> {
    let config = config::get()?;
    let current_time = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|e| TwoFAError::Time(e.to_string()))?
        .as_secs();
    
    let time_step = current_time / config.totp.step;
    let next_step_time = (time_step + 1) * config.totp.step;
    
    Ok(next_step_time - current_time)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config;
    
    #[tokio::test]
    async fn test_generate_secret() {
        config::init_test().unwrap();
        let secret = generate_secret().unwrap();
        assert!(!secret.is_empty());
        assert!(validate_secret(&secret).is_ok());
    }

    #[tokio::test]
    async fn test_totp_verification() {
        config::init_test().unwrap();
        let secret = generate_secret().unwrap();
        let account = "test@example.com";

        let code = generate_current_code(&secret, account).unwrap();
        assert!(verify_code(&secret, &code, 1).unwrap());
    }

    #[tokio::test]
    async fn test_backup_codes() {
        config::init_test().unwrap();
        let codes = generate_backup_codes(10).unwrap();
        assert_eq!(codes.len(), 10);

        for code in &codes {
            let hash = hash_backup_code(code).unwrap();
            assert!(verify_backup_code(&hash, code).unwrap());
        }
    }
}
