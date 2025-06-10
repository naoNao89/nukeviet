//! Rate limiting implementation for 2FA operations

use governor::{Quota, RateLimiter as GovernorRateLimiter, DefaultDirectRateLimiter};
use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};
use crate::config;
use crate::error::{Result, TwoFAError};

/// Rate limiter for 2FA operations
pub struct RateLimiter {
    limiters: Arc<Mutex<HashMap<u32, UserRateLimit>>>,
    cleanup_interval: Duration,
    last_cleanup: Arc<Mutex<Instant>>,
}

/// Per-user rate limiting state
struct UserRateLimit {
    minute_limiter: DefaultDirectRateLimiter,
    hour_limiter: DefaultDirectRateLimiter,
    lockout_until: Option<Instant>,
    failed_attempts: u32,
    last_attempt: Instant,
}

impl RateLimiter {
    /// Create a new rate limiter
    pub fn new() -> Self {
        Self {
            limiters: Arc::new(Mutex::new(HashMap::new())),
            cleanup_interval: Duration::from_secs(300), // 5 minutes
            last_cleanup: Arc::new(Mutex::new(Instant::now())),
        }
    }
    
    /// Check if a user is within rate limits
    pub fn check_rate_limit(&mut self, user_id: u32) -> Result<bool> {
        let config = config::get()?;
        
        if !config.rate_limit.enabled {
            return Ok(true);
        }
        
        self.cleanup_if_needed()?;
        
        let mut limiters = self.limiters.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;
        
        let user_limit = limiters.entry(user_id).or_insert_with(|| {
            UserRateLimit::new(&config.rate_limit)
        });
        
        let now = Instant::now();
        
        // Check if user is locked out
        if let Some(lockout_until) = user_limit.lockout_until {
            if now < lockout_until {
                return Ok(false);
            } else {
                // Lockout expired, reset
                user_limit.lockout_until = None;
                user_limit.failed_attempts = 0;
            }
        }
        
        // Check rate limits
        let minute_ok = user_limit.minute_limiter.check().is_ok();
        let hour_ok = user_limit.hour_limiter.check().is_ok();
        
        if !minute_ok || !hour_ok {
            user_limit.failed_attempts += 1;
            user_limit.last_attempt = now;
            
            // Check if we should trigger lockout
            if user_limit.failed_attempts >= config.rate_limit.max_attempts_per_minute {
                user_limit.lockout_until = Some(
                    now + Duration::from_secs(config.rate_limit.lockout_duration_minutes as u64 * 60)
                );
            }
            
            return Ok(false);
        }
        
        user_limit.last_attempt = now;
        Ok(true)
    }
    
    /// Record a successful authentication (resets failed attempts)
    pub fn record_success(&mut self, user_id: u32) -> Result<()> {
        let mut limiters = self.limiters.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;
        
        if let Some(user_limit) = limiters.get_mut(&user_id) {
            user_limit.failed_attempts = 0;
            user_limit.lockout_until = None;
        }
        
        Ok(())
    }
    
    /// Record a failed authentication attempt
    pub fn record_failure(&mut self, user_id: u32) -> Result<()> {
        let config = config::get()?;
        
        if !config.rate_limit.enabled {
            return Ok(());
        }
        
        let mut limiters = self.limiters.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;
        
        let user_limit = limiters.entry(user_id).or_insert_with(|| {
            UserRateLimit::new(&config.rate_limit)
        });
        
        user_limit.failed_attempts += 1;
        user_limit.last_attempt = Instant::now();
        
        // Check if we should trigger lockout
        if user_limit.failed_attempts >= config.rate_limit.max_attempts_per_minute {
            user_limit.lockout_until = Some(
                Instant::now() + Duration::from_secs(config.rate_limit.lockout_duration_minutes as u64 * 60)
            );
        }
        
        Ok(())
    }
    
    /// Get remaining lockout time for a user
    pub fn get_lockout_remaining(&self, user_id: u32) -> Result<Option<Duration>> {
        let limiters = self.limiters.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;
        
        if let Some(user_limit) = limiters.get(&user_id) {
            if let Some(lockout_until) = user_limit.lockout_until {
                let now = Instant::now();
                if now < lockout_until {
                    return Ok(Some(lockout_until - now));
                }
            }
        }
        
        Ok(None)
    }
    
    /// Clean up old rate limit entries
    fn cleanup_if_needed(&mut self) -> Result<()> {
        let mut last_cleanup = self.last_cleanup.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;
        
        let now = Instant::now();
        if now.duration_since(*last_cleanup) < self.cleanup_interval {
            return Ok(());
        }
        
        let mut limiters = self.limiters.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;
        
        let cutoff = now - Duration::from_secs(3600); // Remove entries older than 1 hour
        limiters.retain(|_, user_limit| {
            user_limit.last_attempt > cutoff
        });
        
        *last_cleanup = now;
        Ok(())
    }
    
    /// Get rate limit statistics for a user
    pub fn get_user_stats(&self, user_id: u32) -> Result<Option<UserRateLimitStats>> {
        let limiters = self.limiters.lock()
            .map_err(|e| TwoFAError::Internal(format!("Mutex lock failed: {}", e)))?;
        
        if let Some(user_limit) = limiters.get(&user_id) {
            Ok(Some(UserRateLimitStats {
                failed_attempts: user_limit.failed_attempts,
                lockout_until: user_limit.lockout_until,
                last_attempt: user_limit.last_attempt,
            }))
        } else {
            Ok(None)
        }
    }
}

impl UserRateLimit {
    fn new(config: &crate::config::RateLimitConfig) -> Self {
        let minute_quota = Quota::per_minute(
            std::num::NonZeroU32::new(config.max_attempts_per_minute).unwrap()
        );
        let hour_quota = Quota::per_hour(
            std::num::NonZeroU32::new(config.max_attempts_per_hour).unwrap()
        );
        
        Self {
            minute_limiter: GovernorRateLimiter::direct(minute_quota),
            hour_limiter: GovernorRateLimiter::direct(hour_quota),
            lockout_until: None,
            failed_attempts: 0,
            last_attempt: Instant::now(),
        }
    }
}

/// Rate limit statistics for a user
#[derive(Debug, Clone)]
pub struct UserRateLimitStats {
    pub failed_attempts: u32,
    pub lockout_until: Option<Instant>,
    pub last_attempt: Instant,
}

impl Default for RateLimiter {
    fn default() -> Self {
        Self::new()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config;

    use std::time::Duration;
    
    #[tokio::test]
    async fn test_rate_limiting() {
        config::init_test().unwrap();
        let mut limiter = RateLimiter::new();

        // Should allow initial attempts
        assert!(limiter.check_rate_limit(1).unwrap());

        // Record multiple failures
        for _ in 0..5 {
            limiter.record_failure(1).unwrap();
        }

        // Should be rate limited now
        assert!(!limiter.check_rate_limit(1).unwrap());

        // Record success should reset
        limiter.record_success(1).unwrap();
        assert!(limiter.check_rate_limit(1).unwrap());
    }

    #[tokio::test]
    async fn test_lockout_duration() {
        config::init_test().unwrap();
        let mut limiter = RateLimiter::new();

        // Trigger lockout
        for _ in 0..10 {
            limiter.record_failure(1).unwrap();
        }

        // Should have lockout time remaining
        let remaining = limiter.get_lockout_remaining(1).unwrap();
        assert!(remaining.is_some());
        assert!(remaining.unwrap() > Duration::from_secs(0));
    }
}
