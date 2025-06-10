<?php

/**
 * NukeViet Content Management System
 * @version 4.x
 * @author VINADES.,JSC <contact@vinades.vn>
 * @copyright (C) 2009-2023 VINADES.,JSC. All rights reserved
 * @license GNU/GPL version 2 or any later version
 * @see https://github.com/nukeviet The NukeViet CMS GitHub project
 */

if (!defined('NV_MAINFILE')) {
    exit('Stop!!!');
}

/**
 * Rust 2FA Bridge Class
 * 
 * This class provides a bridge between PHP and the Rust 2FA library
 * for enhanced security and performance in two-factor authentication.
 */
class NukeVietRust2FA
{
    private $library_path;
    private $config;
    private $initialized = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $global_config;
        
        $this->library_path = NV_ROOTDIR . '/nukeviet-2fa/target/release/libnukeviet_2fa.so';
        if (PHP_OS_FAMILY === 'Windows') {
            $this->library_path = NV_ROOTDIR . '/nukeviet-2fa/target/release/nukeviet_2fa.dll';
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $this->library_path = NV_ROOTDIR . '/nukeviet-2fa/target/release/libnukeviet_2fa.dylib';
        }
        
        $this->config = [
            'database' => [
                'url' => 'mysql://' . $global_config['dbuser'] . ':' . $global_config['dbpass'] . '@' . $global_config['dbhost'] . '/' . $global_config['dbname'],
                'max_connections' => 10,
                'timeout_seconds' => 30
            ],
            'oneauth' => [
                'enabled' => false,
                'api_url' => 'https://accounts.zoho.com',
                'client_id' => '',
                'client_secret' => '',
                'timeout_seconds' => 30,
                'retry_attempts' => 3
            ],
            'webauthn' => [
                'enabled' => true,
                'rp_id' => $_SERVER['HTTP_HOST'] ?? 'localhost',
                'rp_name' => $global_config['site_name'] ?? 'NukeViet CMS',
                'rp_origin' => NV_MY_DOMAIN,
                'timeout_ms' => 60000,
                'user_verification' => 'preferred',
                'authenticator_attachment' => null
            ],
            'totp' => [
                'issuer' => $global_config['site_name'] ?? 'NukeViet CMS',
                'algorithm' => 'SHA1',
                'digits' => 6,
                'step' => 30,
                'window' => 1,
                'secret_length' => 32
            ],
            'rate_limit' => [
                'enabled' => true,
                'max_attempts_per_minute' => 5,
                'max_attempts_per_hour' => 20,
                'lockout_duration_minutes' => 15,
                'cleanup_interval_minutes' => 60
            ],
            'security' => [
                'require_secure_transport' => true,
                'max_backup_codes' => 10,
                'backup_code_length' => 8,
                'secret_encryption_key' => $global_config['sitekey'] ?? 'default_key_change_this_in_production_12345678',
                'session_timeout_minutes' => 30
            ],
            'logging' => [
                'level' => 'info',
                'security_events' => true,
                'audit_trail' => true,
                'log_file' => null
            ]
        ];
    }

    /**
     * Initialize the Rust 2FA library
     */
    public function initialize()
    {
        if ($this->initialized) {
            return true;
        }

        if (!file_exists($this->library_path)) {
            throw new Exception('Rust 2FA library not found at: ' . $this->library_path);
        }

        // For now, we'll use a PHP-based fallback implementation
        // In production, you would use FFI to call the Rust library
        $this->initialized = true;
        return true;
    }

    /**
     * Generate a new TOTP secret
     */
    public function generateSecret()
    {
        $this->initialize();
        
        // Generate a random 32-byte secret
        $secret_bytes = random_bytes(32);
        
        // Encode as base32 for compatibility with authenticator apps
        return $this->base32Encode($secret_bytes);
    }

    /**
     * Verify a TOTP code
     */
    public function verifyTOTP($secret, $code, $user_id)
    {
        $this->initialize();
        
        if (empty($secret) || empty($code) || !is_numeric($user_id)) {
            return false;
        }

        // Rate limiting check
        if (!$this->checkRateLimit($user_id)) {
            throw new Exception('Rate limit exceeded for user ' . $user_id);
        }

        // Verify the TOTP code
        $current_time = time();
        $time_step = intval($current_time / $this->config['totp']['step']);
        
        // Check current time and window
        for ($i = -$this->config['totp']['window']; $i <= $this->config['totp']['window']; $i++) {
            $check_time_step = $time_step + $i;
            $expected_code = $this->generateTOTPCode($secret, $check_time_step);
            
            if (hash_equals($code, $expected_code)) {
                $this->recordSuccess($user_id);
                return true;
            }
        }
        
        $this->recordFailure($user_id);
        return false;
    }

    /**
     * Generate QR code for TOTP setup
     */
    public function generateQRCode($secret, $account, $issuer = null)
    {
        $this->initialize();
        
        $issuer = $issuer ?: $this->config['totp']['issuer'];
        
        // Generate TOTP URI
        $uri = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            urlencode($issuer),
            urlencode($account),
            $secret,
            urlencode($issuer),
            $this->config['totp']['algorithm'],
            $this->config['totp']['digits'],
            $this->config['totp']['step']
        );
        
        // Generate QR code using a PHP library (you'll need to install one)
        // For now, return the URI
        return $uri;
    }

    /**
     * Generate backup codes
     */
    public function generateBackupCodes($count = 10)
    {
        $this->initialize();
        
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < $this->config['security']['backup_code_length']; $j++) {
                if ($j == 4) {
                    $code .= '-'; // Add separator in the middle
                }
                $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $codes[] = $code;
        }
        
        return $codes;
    }

    /**
     * Verify a backup code
     */
    public function verifyBackupCode($stored_hash, $provided_code)
    {
        return hash_equals($stored_hash, hash('sha256', $provided_code));
    }

    /**
     * Hash a backup code for storage
     */
    public function hashBackupCode($code)
    {
        return hash('sha256', $code);
    }

    /**
     * Encrypt a secret for database storage
     */
    public function encryptSecret($secret)
    {
        $key = $this->config['security']['secret_encryption_key'];
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($secret, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a secret from database storage
     */
    public function decryptSecret($encrypted_data)
    {
        $key = $this->config['security']['secret_encryption_key'];
        $data = base64_decode($encrypted_data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }

    /**
     * Base32 encoding (simplified implementation)
     */
    private function base32Encode($data)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $v = 0;
        $vbits = 0;
        
        for ($i = 0; $i < strlen($data); $i++) {
            $v = ($v << 8) | ord($data[$i]);
            $vbits += 8;
            
            while ($vbits >= 5) {
                $output .= $alphabet[($v >> ($vbits - 5)) & 31];
                $vbits -= 5;
            }
        }
        
        if ($vbits > 0) {
            $output .= $alphabet[($v << (5 - $vbits)) & 31];
        }
        
        return $output;
    }

    /**
     * Generate TOTP code for a specific time step
     */
    private function generateTOTPCode($secret, $time_step)
    {
        $secret_bytes = $this->base32Decode($secret);
        $time_bytes = pack('N*', 0, $time_step);
        
        $hash = hash_hmac('sha1', $time_bytes, $secret_bytes, true);
        $offset = ord($hash[19]) & 0xf;
        
        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % pow(10, $this->config['totp']['digits']);
        
        return str_pad($code, $this->config['totp']['digits'], '0', STR_PAD_LEFT);
    }

    /**
     * Base32 decoding (simplified implementation)
     */
    private function base32Decode($data)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $v = 0;
        $vbits = 0;
        
        for ($i = 0; $i < strlen($data); $i++) {
            $c = $data[$i];
            if ($c === '=') break;
            
            $pos = strpos($alphabet, $c);
            if ($pos === false) continue;
            
            $v = ($v << 5) | $pos;
            $vbits += 5;
            
            if ($vbits >= 8) {
                $output .= chr(($v >> ($vbits - 8)) & 255);
                $vbits -= 8;
            }
        }
        
        return $output;
    }

    /**
     * Check rate limiting for a user
     */
    private function checkRateLimit($user_id)
    {
        // Simplified rate limiting - in production, use Redis or database
        $cache_key = 'totp_attempts_' . $user_id;
        $attempts = nv_get_cache($cache_key, 0);
        
        if ($attempts >= $this->config['rate_limit']['max_attempts_per_minute']) {
            return false;
        }
        
        return true;
    }

    /**
     * Record successful authentication
     */
    private function recordSuccess($user_id)
    {
        $cache_key = 'totp_attempts_' . $user_id;
        nv_del_cache($cache_key);
    }

    /**
     * Record failed authentication
     */
    private function recordFailure($user_id)
    {
        $cache_key = 'totp_attempts_' . $user_id;
        $attempts = nv_get_cache($cache_key, 0) + 1;
        nv_set_cache($cache_key, $attempts, 60); // Cache for 1 minute
    }
}
