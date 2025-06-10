# Enhanced 2FA Installation Guide

## Quick Start

This guide will help you install and configure the enhanced 2FA system for NukeViet CMS.

## Prerequisites Check

Before installation, verify your system meets these requirements:

### System Requirements

- ✅ **NukeViet CMS**: Version 4.x or later
- ✅ **PHP**: Version 7.4 or later with OpenSSL extension
- ✅ **Database**: MySQL 5.7+ or MariaDB 10.2+
- ✅ **HTTPS**: Required for WebAuthn functionality
- ✅ **Rust**: Version 1.70+ (for building security engine)

### Check Your Environment

```bash
# Check PHP version and extensions
php -v
php -m | grep openssl

# Check MySQL version
mysql --version

# Check if HTTPS is enabled
curl -I https://yourdomain.com

# Check Rust installation
rustc --version
cargo --version
```

## Installation Steps

### Step 1: Backup Your System

**⚠️ Important**: Always backup before making changes!

```bash
# Backup database
mysqldump -u username -p nukeviet_db > backup_$(date +%Y%m%d).sql

# Backup NukeViet files
tar -czf nukeviet_backup_$(date +%Y%m%d).tar.gz /path/to/nukeviet/
```

### Step 2: Build the Rust Security Engine

```bash
# Navigate to the Rust project directory
cd nukeviet-2fa

# Build the release version
cargo build --release

# Verify the library was created
ls -la target/release/libnukeviet_2fa.*
```

**Expected output**:
- Linux: `libnukeviet_2fa.so`
- macOS: `libnukeviet_2fa.dylib`
- Windows: `nukeviet_2fa.dll`

### Step 3: Install Database Schema

```bash
# Connect to your MySQL database
mysql -u username -p nukeviet_db

# Execute the schema installation
SOURCE modules/two-step-verification/install_schema.sql;

# Verify tables were created
SHOW TABLES LIKE '%2fa%';
SHOW TABLES LIKE '%oneauth%';
SHOW TABLES LIKE '%webauthn%';
```

**Expected tables**:
- `nv4_users_oneauth`
- `nv4_users_webauthn`
- `nv4_2fa_security_log`
- `nv4_2fa_rate_limit`
- `nv4_2fa_sessions`
- `nv4_2fa_config`

### Step 4: Configure NukeViet

#### Option A: Admin Panel Configuration

1. Login to NukeViet admin panel
2. Go to **System** → **Configuration** → **Security**
3. Add these settings:

```
OneAuth Enabled: No (enable after setup)
OneAuth Client ID: [your_client_id]
OneAuth Client Secret: [your_client_secret]
WebAuthn Enabled: Yes
Site Encryption Key: [32_character_random_string]
```

#### Option B: Direct Configuration File

Edit `config/config_global.php`:

```php
// Enhanced 2FA Configuration
$global_config['oneauth_enabled'] = 0;
$global_config['oneauth_client_id'] = '';
$global_config['oneauth_client_secret'] = '';
$global_config['oneauth_api_url'] = 'https://accounts.zoho.com';
$global_config['webauthn_enabled'] = 1;
$global_config['sitekey'] = 'your_32_character_encryption_key_here_12345';
```

### Step 5: Test the Installation

Run the provided test scripts:

```bash
# Test PHP integration
php test_2fa_integration.php

# Test database schema
php test_sql_schema.php

# Test overall integration
php test_integration_simple.php
```

**Expected output**: All tests should pass with ✅ marks.

### Step 6: Verify User Experience

1. **Login as a test user**
2. **Navigate to Profile** → **Two-Step Verification**
3. **Test TOTP setup**:
   - Generate new secret
   - Scan QR code with authenticator app
   - Verify code works
4. **Test backup codes**:
   - Generate backup codes
   - Save them securely
   - Test one code

## OneAuth Setup (Optional)

### Register with Zoho OneAuth

1. Visit [Zoho OneAuth](https://www.zoho.com/oneauth/)
2. Create an account or login
3. Create a new application
4. Note your Client ID and Client Secret

### Configure OneAuth in NukeViet

```php
$global_config['oneauth_enabled'] = 1;
$global_config['oneauth_client_id'] = 'your_actual_client_id';
$global_config['oneauth_client_secret'] = 'your_actual_client_secret';
```

### Test OneAuth Integration

1. User goes to **Profile** → **Two-Step Verification** → **OneAuth**
2. Enter email and phone number
3. Select authentication methods
4. Complete enrollment process

## WebAuthn Setup

### Verify HTTPS

WebAuthn requires HTTPS. Test with:

```bash
# Check SSL certificate
openssl s_client -connect yourdomain.com:443 -servername yourdomain.com

# Test WebAuthn availability
curl -H "User-Agent: Mozilla/5.0" https://yourdomain.com/modules/two-step-verification/webauthn.php
```

### Test Hardware Key Registration

1. **User navigates to WebAuthn section**
2. **Clicks "Register Security Key"**
3. **Inserts hardware key** (YubiKey, etc.)
4. **Touches key when prompted**
5. **Verifies key is registered**

## Security Configuration

### Rate Limiting

Adjust based on your needs:

```sql
UPDATE nv4_2fa_config SET config_value = '3' WHERE config_name = 'rate_limit_max_attempts';
UPDATE nv4_2fa_config SET config_value = '10' WHERE config_name = 'rate_limit_window_minutes';
```

### Audit Logging

Enable comprehensive logging:

```sql
UPDATE nv4_2fa_config SET config_value = '1' WHERE config_name = 'security_log_enabled';
UPDATE nv4_2fa_config SET config_value = '90' WHERE config_name = 'security_log_retention_days';
```

## Troubleshooting

### Common Issues and Solutions

#### 1. Rust Library Not Found

**Error**: `Rust 2FA library not found`

**Solution**:
```bash
# Check library exists
ls -la nukeviet-2fa/target/release/libnukeviet_2fa.*

# Check permissions
chmod 755 nukeviet-2fa/target/release/libnukeviet_2fa.*

# Verify path in PHP
grep library_path modules/two-step-verification/rust_bridge.php
```

#### 2. Database Connection Errors

**Error**: `Database error during 2FA operation`

**Solution**:
```bash
# Test database connection
mysql -u username -p -e "SELECT 1"

# Check table existence
mysql -u username -p nukeviet_db -e "SHOW TABLES LIKE '%2fa%'"

# Verify permissions
mysql -u username -p nukeviet_db -e "SELECT * FROM nv4_2fa_config LIMIT 1"
```

#### 3. WebAuthn Not Working

**Error**: `WebAuthn is not available`

**Solution**:
```bash
# Verify HTTPS
curl -I https://yourdomain.com

# Check browser support
# Open browser console and run: navigator.credentials

# Verify configuration
grep webauthn_enabled config/config_global.php
```

#### 4. OneAuth API Errors

**Error**: `OneAuth API error: Authentication failed`

**Solution**:
```bash
# Test API connectivity
curl -v https://accounts.zoho.com/oauth/v2/token

# Verify credentials
grep oneauth_client config/config_global.php

# Check API limits
# Review Zoho OneAuth dashboard
```

## Performance Optimization

### Enable Caching

```php
// In config/config_global.php
$global_config['2fa_cache_enabled'] = 1;
$global_config['2fa_cache_ttl'] = 300; // 5 minutes
```

### Database Optimization

```sql
-- Add indexes for better performance
ALTER TABLE nv4_2fa_security_log ADD INDEX idx_timestamp (timestamp);
ALTER TABLE nv4_2fa_rate_limit ADD INDEX idx_window_start (window_start);
```

### Monitor Performance

```bash
# Check Rust library performance
time php -r "
require 'modules/two-step-verification/rust_bridge.php';
\$r = new NukeVietRust2FA();
for(\$i=0; \$i<1000; \$i++) \$r->generateSecret();
"
```

## Maintenance

### Regular Tasks

1. **Weekly**: Review security logs
2. **Monthly**: Update Rust dependencies
3. **Quarterly**: Audit user 2FA settings
4. **Annually**: Security assessment

### Update Process

```bash
# Update Rust dependencies
cd nukeviet-2fa
cargo update
cargo build --release

# Test after update
php test_integration_simple.php

# Deploy if tests pass
# (restart web server if needed)
```

## Getting Help

### Log Files

Check these locations for errors:
- PHP error log: `/var/log/php/error.log`
- NukeViet logs: `data/logs/`
- 2FA security log: Database table `nv4_2fa_security_log`

### Debug Mode

Enable debug output:

```php
// Temporary debug mode
$global_config['2fa_debug'] = true;
```

### Support Resources

- **NukeViet Documentation**: [nukeviet.vn](https://nukeviet.vn)
- **GitHub Issues**: Report bugs and feature requests
- **Community Forum**: Get help from other users

---

**🎉 Congratulations!** Your enhanced 2FA system is now installed and ready to provide enterprise-grade security for your NukeViet CMS.
