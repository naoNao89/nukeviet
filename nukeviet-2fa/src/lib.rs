//! NukeViet 2FA Service
//!
//! A modern, secure two-factor authentication service for NukeViet CMS
//! with support for TOTP, WebAuthn, and OneAuth integration.

use std::ffi::{CStr, CString};
use std::os::raw::c_char;

pub mod config;
pub mod crypto;
pub mod error;
pub mod oneauth;
pub mod totp;
pub mod webauthn;
pub mod rate_limit;
pub mod qr;

pub use error::{Result, TwoFAError};

/// Initialize the 2FA service with configuration
#[no_mangle]
pub extern "C" fn nukeviet_2fa_init(config_json: *const c_char) -> i32 {
    if config_json.is_null() {
        return -1;
    }

    let config_str = unsafe {
        match CStr::from_ptr(config_json).to_str() {
            Ok(s) => s,
            Err(_) => return -1,
        }
    };

    match config::init_from_json(config_str) {
        Ok(_) => 0,
        Err(_) => -1,
    }
}

/// Generate a new TOTP secret
#[no_mangle]
pub extern "C" fn nukeviet_2fa_generate_secret() -> *mut c_char {
    match totp::generate_secret() {
        Ok(secret) => {
            match CString::new(secret) {
                Ok(c_string) => c_string.into_raw(),
                Err(_) => std::ptr::null_mut(),
            }
        }
        Err(_) => std::ptr::null_mut(),
    }
}

/// Verify a TOTP code
#[no_mangle]
pub extern "C" fn nukeviet_2fa_verify_totp(
    secret: *const c_char,
    code: *const c_char,
    user_id: u32,
) -> i32 {
    if secret.is_null() || code.is_null() {
        return -1;
    }

    let secret_str = unsafe {
        match CStr::from_ptr(secret).to_str() {
            Ok(s) => s,
            Err(_) => return -1,
        }
    };

    let code_str = unsafe {
        match CStr::from_ptr(code).to_str() {
            Ok(s) => s,
            Err(_) => return -1,
        }
    };

    match totp::verify_code(secret_str, code_str, user_id) {
        Ok(true) => 1,
        Ok(false) => 0,
        Err(_) => -1,
    }
}

/// Generate QR code for TOTP setup
#[no_mangle]
pub extern "C" fn nukeviet_2fa_generate_qr(
    secret: *const c_char,
    account: *const c_char,
    issuer: *const c_char,
) -> *mut c_char {
    if secret.is_null() || account.is_null() || issuer.is_null() {
        return std::ptr::null_mut();
    }

    let secret_str = unsafe {
        match CStr::from_ptr(secret).to_str() {
            Ok(s) => s,
            Err(_) => return std::ptr::null_mut(),
        }
    };

    let account_str = unsafe {
        match CStr::from_ptr(account).to_str() {
            Ok(s) => s,
            Err(_) => return std::ptr::null_mut(),
        }
    };

    let issuer_str = unsafe {
        match CStr::from_ptr(issuer).to_str() {
            Ok(s) => s,
            Err(_) => return std::ptr::null_mut(),
        }
    };

    match qr::generate_qr_code(secret_str, account_str, issuer_str) {
        Ok(qr_data) => {
            match CString::new(qr_data) {
                Ok(c_string) => c_string.into_raw(),
                Err(_) => std::ptr::null_mut(),
            }
        }
        Err(_) => std::ptr::null_mut(),
    }
}

/// Free memory allocated by the library
#[no_mangle]
pub extern "C" fn nukeviet_2fa_free_string(ptr: *mut c_char) {
    if !ptr.is_null() {
        unsafe {
            let _ = CString::from_raw(ptr);
        }
    }
}
