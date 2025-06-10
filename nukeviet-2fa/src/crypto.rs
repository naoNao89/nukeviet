//! Cryptographic utilities for secure 2FA operations

use ring::{aead, digest, pbkdf2, rand};
use base64::{Engine as _, engine::general_purpose};
use zeroize::Zeroize;
use std::num::NonZeroU32;
use crate::error::{Result, TwoFAError};

/// Secure string that zeros memory on drop
#[derive(Clone)]
pub struct SecureString(String);

impl Drop for SecureString {
    fn drop(&mut self) {
        self.0.zeroize();
    }
}

impl SecureString {
    pub fn new(s: String) -> Self {
        Self(s)
    }
    
    pub fn as_str(&self) -> &str {
        &self.0
    }
    
    pub fn into_string(self) -> String {
        self.0.clone()
    }
}

/// Encryption key for securing sensitive data
pub struct EncryptionKey {
    key: aead::LessSafeKey,
}

impl Drop for EncryptionKey {
    fn drop(&mut self) {
        // The key will be automatically zeroed when dropped
    }
}

/// Encrypted data container
#[derive(Debug, Clone)]
pub struct EncryptedData {
    pub nonce: Vec<u8>,
    pub ciphertext: Vec<u8>,
}

impl EncryptionKey {
    /// Create a new encryption key from a password
    pub fn from_password(password: &str, salt: &[u8]) -> Result<Self> {
        let mut key_bytes = [0u8; 32];
        pbkdf2::derive(
            pbkdf2::PBKDF2_HMAC_SHA256,
            NonZeroU32::new(100_000).unwrap(),
            salt,
            password.as_bytes(),
            &mut key_bytes,
        );
        
        let unbound_key = aead::UnboundKey::new(&aead::CHACHA20_POLY1305, &key_bytes)
            .map_err(|_| TwoFAError::Crypto("Failed to create encryption key".to_string()))?;
        
        let key = aead::LessSafeKey::new(unbound_key);
        
        // Zero the key bytes
        let mut key_bytes = key_bytes;
        key_bytes.zeroize();
        
        Ok(Self { key })
    }
    
    /// Generate a random encryption key
    pub fn generate() -> Result<Self> {
        let rng = rand::SystemRandom::new();
        let mut key_bytes = [0u8; 32];
        rng.fill(&mut key_bytes)
            .map_err(|_| TwoFAError::Crypto("Failed to generate random key".to_string()))?;
        
        let unbound_key = aead::UnboundKey::new(&aead::CHACHA20_POLY1305, &key_bytes)
            .map_err(|_| TwoFAError::Crypto("Failed to create encryption key".to_string()))?;
        
        let key = aead::LessSafeKey::new(unbound_key);
        
        // Zero the key bytes
        let mut key_bytes = key_bytes;
        key_bytes.zeroize();
        
        Ok(Self { key })
    }
    
    /// Encrypt data
    pub fn encrypt(&self, plaintext: &[u8]) -> Result<EncryptedData> {
        let rng = rand::SystemRandom::new();
        let mut nonce_bytes = [0u8; 12];
        rng.fill(&mut nonce_bytes)
            .map_err(|_| TwoFAError::Crypto("Failed to generate nonce".to_string()))?;
        
        let nonce = aead::Nonce::assume_unique_for_key(nonce_bytes);
        
        let mut ciphertext = plaintext.to_vec();
        self.key.seal_in_place_append_tag(nonce, aead::Aad::empty(), &mut ciphertext)
            .map_err(|_| TwoFAError::Crypto("Encryption failed".to_string()))?;
        
        Ok(EncryptedData {
            nonce: nonce_bytes.to_vec(),
            ciphertext,
        })
    }
    
    /// Decrypt data
    pub fn decrypt(&self, encrypted: &EncryptedData) -> Result<Vec<u8>> {
        if encrypted.nonce.len() != 12 {
            return Err(TwoFAError::Crypto("Invalid nonce length".to_string()));
        }
        
        let mut nonce_bytes = [0u8; 12];
        nonce_bytes.copy_from_slice(&encrypted.nonce);
        let nonce = aead::Nonce::assume_unique_for_key(nonce_bytes);
        
        let mut ciphertext = encrypted.ciphertext.clone();
        let plaintext = self.key.open_in_place(nonce, aead::Aad::empty(), &mut ciphertext)
            .map_err(|_| TwoFAError::Crypto("Decryption failed".to_string()))?;
        
        Ok(plaintext.to_vec())
    }
}

/// Encrypt a secret for database storage
pub fn encrypt_secret(secret: &str, encryption_key: &str) -> Result<String> {
    let salt = generate_salt()?;
    let key = EncryptionKey::from_password(encryption_key, &salt)?;
    let encrypted = key.encrypt(secret.as_bytes())?;
    
    let combined = CombinedEncryptedData {
        salt,
        nonce: encrypted.nonce,
        ciphertext: encrypted.ciphertext,
    };
    
    let serialized = serde_json::to_vec(&combined)
        .map_err(|e| TwoFAError::Crypto(format!("Serialization failed: {}", e)))?;
    
    Ok(general_purpose::STANDARD.encode(serialized))
}

/// Decrypt a secret from database storage
pub fn decrypt_secret(encrypted_data: &str, encryption_key: &str) -> Result<SecureString> {
    let decoded = general_purpose::STANDARD.decode(encrypted_data)
        .map_err(|e| TwoFAError::Crypto(format!("Base64 decode failed: {}", e)))?;
    
    let combined: CombinedEncryptedData = serde_json::from_slice(&decoded)
        .map_err(|e| TwoFAError::Crypto(format!("Deserialization failed: {}", e)))?;
    
    let key = EncryptionKey::from_password(encryption_key, &combined.salt)?;
    let encrypted = EncryptedData {
        nonce: combined.nonce,
        ciphertext: combined.ciphertext,
    };
    
    let plaintext = key.decrypt(&encrypted)?;
    let secret = String::from_utf8(plaintext)
        .map_err(|e| TwoFAError::Crypto(format!("UTF-8 decode failed: {}", e)))?;
    
    Ok(SecureString::new(secret))
}

/// Generate a cryptographically secure salt
pub fn generate_salt() -> Result<Vec<u8>> {
    let rng = rand::SystemRandom::new();
    let mut salt = vec![0u8; 32];
    rng.fill(&mut salt)
        .map_err(|_| TwoFAError::Crypto("Failed to generate salt".to_string()))?;
    Ok(salt)
}

/// Hash a password using PBKDF2
pub fn hash_password(password: &str, salt: &[u8]) -> Result<String> {
    let mut hash = [0u8; 32];
    pbkdf2::derive(
        pbkdf2::PBKDF2_HMAC_SHA256,
        NonZeroU32::new(100_000).unwrap(),
        salt,
        password.as_bytes(),
        &mut hash,
    );
    
    Ok(general_purpose::STANDARD.encode(hash))
}

/// Verify a password against a hash
pub fn verify_password(password: &str, salt: &[u8], expected_hash: &str) -> Result<bool> {
    let computed_hash = hash_password(password, salt)?;
    Ok(constant_time_eq::constant_time_eq(
        computed_hash.as_bytes(),
        expected_hash.as_bytes()
    ))
}

/// Generate a secure random token
pub fn generate_token(length: usize) -> Result<String> {
    let rng = rand::SystemRandom::new();
    let mut bytes = vec![0u8; length];
    rng.fill(&mut bytes)
        .map_err(|_| TwoFAError::Crypto("Failed to generate token".to_string()))?;
    
    Ok(general_purpose::URL_SAFE_NO_PAD.encode(bytes))
}

/// Hash data using SHA-256
pub fn hash_sha256(data: &[u8]) -> String {
    let digest = digest::digest(&digest::SHA256, data);
    general_purpose::STANDARD.encode(digest.as_ref())
}

/// Generate a secure backup code
pub fn generate_backup_code() -> Result<String> {
    let rng = rand::SystemRandom::new();
    let mut code = String::new();
    
    for i in 0..8 {
        if i == 4 {
            code.push('-'); // Add separator in the middle
        }
        
        let mut byte = [0u8; 1];
        rng.fill(&mut byte)
            .map_err(|_| TwoFAError::Crypto("Failed to generate backup code".to_string()))?;
        
        // Generate alphanumeric character (excluding confusing ones)
        let chars = b"23456789ABCDEFGHJKLMNPQRSTUVWXYZ";
        let char_index = (byte[0] as usize) % chars.len();
        code.push(chars[char_index] as char);
    }
    
    Ok(code)
}

/// Constant-time string comparison
pub fn secure_compare(a: &str, b: &str) -> bool {
    constant_time_eq::constant_time_eq(a.as_bytes(), b.as_bytes())
}

/// Combined encrypted data for serialization
#[derive(serde::Serialize, serde::Deserialize)]
struct CombinedEncryptedData {
    salt: Vec<u8>,
    nonce: Vec<u8>,
    ciphertext: Vec<u8>,
}

#[cfg(test)]
mod tests {
    use super::*;
    
    #[test]
    fn test_encryption_decryption() {
        let password = "test_password";
        let salt = generate_salt().unwrap();
        let key = EncryptionKey::from_password(password, &salt).unwrap();
        
        let plaintext = b"Hello, World!";
        let encrypted = key.encrypt(plaintext).unwrap();
        let decrypted = key.decrypt(&encrypted).unwrap();
        
        assert_eq!(plaintext, decrypted.as_slice());
    }
    
    #[test]
    fn test_secret_encryption() {
        let secret = "JBSWY3DPEHPK3PXP";
        let encryption_key = "my_encryption_key_12345678901234567890";
        
        let encrypted = encrypt_secret(secret, encryption_key).unwrap();
        let decrypted = decrypt_secret(&encrypted, encryption_key).unwrap();
        
        assert_eq!(secret, decrypted.as_str());
    }
    
    #[test]
    fn test_password_hashing() {
        let password = "test_password";
        let salt = generate_salt().unwrap();
        
        let hash = hash_password(password, &salt).unwrap();
        assert!(verify_password(password, &salt, &hash).unwrap());
        assert!(!verify_password("wrong_password", &salt, &hash).unwrap());
    }
    
    #[test]
    fn test_backup_code_generation() {
        let code = generate_backup_code().unwrap();
        assert_eq!(code.len(), 9); // 8 chars + 1 separator
        assert!(code.contains('-'));
    }
    
    #[test]
    fn test_secure_compare() {
        assert!(secure_compare("hello", "hello"));
        assert!(!secure_compare("hello", "world"));
        assert!(!secure_compare("hello", "hello2"));
    }
}
