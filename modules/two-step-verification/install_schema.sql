-- Database schema updates for enhanced 2FA with OneAuth and WebAuthn support
-- This file contains the SQL statements to create the necessary tables

-- Table for OneAuth integration
CREATE TABLE IF NOT EXISTS `nv4_users_oneauth` (
  `userid` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `methods` text NOT NULL COMMENT 'JSON array of enabled methods',
  `enrollment_id` varchar(255) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_time` int(11) NOT NULL,
  `updated_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`userid`),
  KEY `status` (`status`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for WebAuthn credentials
CREATE TABLE IF NOT EXISTS `nv4_users_webauthn` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `credential_id` varchar(255) NOT NULL,
  `public_key` text NOT NULL,
  `counter` int(11) NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `created_time` int(11) NOT NULL,
  `last_used` int(11) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `credential_id` (`credential_id`),
  KEY `userid` (`userid`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for 2FA security audit log
CREATE TABLE IF NOT EXISTS `nv4_2fa_security_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text,
  `timestamp` int(11) NOT NULL,
  `details` text COMMENT 'JSON data with additional event details',
  PRIMARY KEY (`id`),
  KEY `event_type` (`event_type`),
  KEY `user_id` (`user_id`),
  KEY `timestamp` (`timestamp`),
  KEY `ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for rate limiting
CREATE TABLE IF NOT EXISTS `nv4_2fa_rate_limit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempt_type` varchar(20) NOT NULL DEFAULT 'totp',
  `attempts` int(11) NOT NULL DEFAULT 1,
  `window_start` int(11) NOT NULL,
  `locked_until` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_ip_type` (`user_id`, `ip_address`, `attempt_type`),
  KEY `window_start` (`window_start`),
  KEY `locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for session tokens and challenges
CREATE TABLE IF NOT EXISTS `nv4_2fa_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `challenge_type` varchar(20) NOT NULL,
  `challenge_data` text NOT NULL,
  `created_time` int(11) NOT NULL,
  `expires_time` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `user_id` (`user_id`),
  KEY `expires_time` (`expires_time`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add indexes to existing backup codes table for better performance
ALTER TABLE `nv4_users_backupcodes` 
ADD INDEX IF NOT EXISTS `userid_used` (`userid`, `is_used`),
ADD INDEX IF NOT EXISTS `time_creat` (`time_creat`);

-- Add configuration table for 2FA settings
CREATE TABLE IF NOT EXISTS `nv4_2fa_config` (
  `config_name` varchar(50) NOT NULL,
  `config_value` text NOT NULL,
  `updated_time` int(11) NOT NULL,
  PRIMARY KEY (`config_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default configuration values
INSERT INTO `nv4_2fa_config` (`config_name`, `config_value`, `updated_time`) VALUES
('oneauth_enabled', '0', UNIX_TIMESTAMP()),
('oneauth_client_id', '', UNIX_TIMESTAMP()),
('oneauth_client_secret', '', UNIX_TIMESTAMP()),
('oneauth_api_url', 'https://accounts.zoho.com', UNIX_TIMESTAMP()),
('webauthn_enabled', '1', UNIX_TIMESTAMP()),
('webauthn_rp_name', 'NukeViet CMS', UNIX_TIMESTAMP()),
('webauthn_timeout', '60000', UNIX_TIMESTAMP()),
('webauthn_user_verification', 'preferred', UNIX_TIMESTAMP()),
('totp_issuer', 'NukeViet CMS', UNIX_TIMESTAMP()),
('totp_algorithm', 'SHA1', UNIX_TIMESTAMP()),
('totp_digits', '6', UNIX_TIMESTAMP()),
('totp_period', '30', UNIX_TIMESTAMP()),
('rate_limit_enabled', '1', UNIX_TIMESTAMP()),
('rate_limit_max_attempts', '5', UNIX_TIMESTAMP()),
('rate_limit_window_minutes', '15', UNIX_TIMESTAMP()),
('backup_codes_count', '10', UNIX_TIMESTAMP()),
('backup_codes_length', '8', UNIX_TIMESTAMP()),
('security_log_enabled', '1', UNIX_TIMESTAMP()),
('security_log_retention_days', '90', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE 
`updated_time` = UNIX_TIMESTAMP();

-- Create stored procedures for common operations

DELIMITER $$

-- Procedure to clean up expired sessions
CREATE PROCEDURE IF NOT EXISTS `sp_cleanup_2fa_sessions`()
BEGIN
    DELETE FROM `nv4_2fa_sessions` 
    WHERE `expires_time` < UNIX_TIMESTAMP();
    
    DELETE FROM `nv4_2fa_rate_limit` 
    WHERE `window_start` < (UNIX_TIMESTAMP() - 3600); -- Clean up rate limit entries older than 1 hour
END$$

-- Procedure to log security events
CREATE PROCEDURE IF NOT EXISTS `sp_log_2fa_security_event`(
    IN p_event_type VARCHAR(50),
    IN p_user_id INT,
    IN p_ip_address VARCHAR(45),
    IN p_user_agent TEXT,
    IN p_details TEXT
)
BEGIN
    INSERT INTO `nv4_2fa_security_log` 
    (`event_type`, `user_id`, `ip_address`, `user_agent`, `timestamp`, `details`)
    VALUES 
    (p_event_type, p_user_id, p_ip_address, p_user_agent, UNIX_TIMESTAMP(), p_details);
    
    -- Clean up old log entries (keep only last 90 days by default)
    DELETE FROM `nv4_2fa_security_log` 
    WHERE `timestamp` < (UNIX_TIMESTAMP() - (90 * 24 * 3600));
END$$

-- Procedure to check rate limits
CREATE PROCEDURE IF NOT EXISTS `sp_check_2fa_rate_limit`(
    IN p_user_id INT,
    IN p_ip_address VARCHAR(45),
    IN p_attempt_type VARCHAR(20),
    IN p_max_attempts INT,
    IN p_window_minutes INT,
    OUT p_allowed BOOLEAN,
    OUT p_remaining_attempts INT,
    OUT p_reset_time INT
)
BEGIN
    DECLARE v_current_attempts INT DEFAULT 0;
    DECLARE v_window_start INT;
    DECLARE v_locked_until INT DEFAULT NULL;
    
    SET v_window_start = UNIX_TIMESTAMP() - (p_window_minutes * 60);
    
    -- Check if user is currently locked
    SELECT `locked_until` INTO v_locked_until
    FROM `nv4_2fa_rate_limit`
    WHERE `user_id` = p_user_id 
      AND `ip_address` = p_ip_address 
      AND `attempt_type` = p_attempt_type
      AND `locked_until` > UNIX_TIMESTAMP();
    
    IF v_locked_until IS NOT NULL THEN
        SET p_allowed = FALSE;
        SET p_remaining_attempts = 0;
        SET p_reset_time = v_locked_until;
    ELSE
        -- Get current attempts in window
        SELECT COALESCE(`attempts`, 0) INTO v_current_attempts
        FROM `nv4_2fa_rate_limit`
        WHERE `user_id` = p_user_id 
          AND `ip_address` = p_ip_address 
          AND `attempt_type` = p_attempt_type
          AND `window_start` > v_window_start;
        
        IF v_current_attempts >= p_max_attempts THEN
            SET p_allowed = FALSE;
            SET p_remaining_attempts = 0;
            SET p_reset_time = UNIX_TIMESTAMP() + (p_window_minutes * 60);
            
            -- Lock the user
            INSERT INTO `nv4_2fa_rate_limit` 
            (`user_id`, `ip_address`, `attempt_type`, `attempts`, `window_start`, `locked_until`)
            VALUES 
            (p_user_id, p_ip_address, p_attempt_type, v_current_attempts + 1, UNIX_TIMESTAMP(), p_reset_time)
            ON DUPLICATE KEY UPDATE 
            `attempts` = `attempts` + 1,
            `locked_until` = p_reset_time;
        ELSE
            SET p_allowed = TRUE;
            SET p_remaining_attempts = p_max_attempts - v_current_attempts - 1;
            SET p_reset_time = UNIX_TIMESTAMP() + (p_window_minutes * 60);
            
            -- Increment attempt counter
            INSERT INTO `nv4_2fa_rate_limit` 
            (`user_id`, `ip_address`, `attempt_type`, `attempts`, `window_start`)
            VALUES 
            (p_user_id, p_ip_address, p_attempt_type, 1, UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE 
            `attempts` = `attempts` + 1;
        END IF;
    END IF;
END$$

DELIMITER ;

-- Create events for automatic cleanup (if event scheduler is enabled)
SET GLOBAL event_scheduler = ON;

CREATE EVENT IF NOT EXISTS `ev_cleanup_2fa_data`
ON SCHEDULE EVERY 1 HOUR
DO
  CALL sp_cleanup_2fa_sessions();

-- Add foreign key constraints
ALTER TABLE `nv4_users_oneauth` 
ADD CONSTRAINT `fk_oneauth_userid` 
FOREIGN KEY (`userid`) REFERENCES `nv4_users` (`userid`) 
ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `nv4_users_webauthn` 
ADD CONSTRAINT `fk_webauthn_userid` 
FOREIGN KEY (`userid`) REFERENCES `nv4_users` (`userid`) 
ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `nv4_2fa_security_log` 
ADD CONSTRAINT `fk_security_log_userid` 
FOREIGN KEY (`user_id`) REFERENCES `nv4_users` (`userid`) 
ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `nv4_2fa_rate_limit` 
ADD CONSTRAINT `fk_rate_limit_userid` 
FOREIGN KEY (`user_id`) REFERENCES `nv4_users` (`userid`) 
ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `nv4_2fa_sessions` 
ADD CONSTRAINT `fk_sessions_userid` 
FOREIGN KEY (`user_id`) REFERENCES `nv4_users` (`userid`) 
ON DELETE CASCADE ON UPDATE CASCADE;
