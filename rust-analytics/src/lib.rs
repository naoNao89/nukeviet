//! NukeViet Analytics Engine
//!
//! Advanced analytics engine for NukeViet CMS with intelligent bot detection
//! and real-time visitor classification.

use std::ffi::{CStr, CString};
use std::os::raw::c_char;

pub mod bot_detection;
pub mod config;
pub mod database;
pub mod ffi;
pub mod types;
pub mod visitor_analytics;

pub use config::AnalyticsConfig;
pub use types::*;

/// Initialize the analytics engine
///
/// # Safety
/// This function is unsafe because it dereferences a raw pointer from C.
/// The caller must ensure that `config_json` is a valid, null-terminated C string.
#[no_mangle]
pub unsafe extern "C" fn analytics_init(config_json: *const c_char) -> *mut AnalyticsEngine {
    if config_json.is_null() {
        return std::ptr::null_mut();
    }

    let config_str = match CStr::from_ptr(config_json).to_str() {
        Ok(s) => s,
        Err(_) => return std::ptr::null_mut(),
    };

    let config: AnalyticsConfig = match serde_json::from_str(config_str) {
        Ok(c) => c,
        Err(_) => return std::ptr::null_mut(),
    };

    match AnalyticsEngine::new(config) {
        Ok(engine) => Box::into_raw(Box::new(engine)),
        Err(_) => std::ptr::null_mut(),
    }
}

/// Process a visitor request
///
/// # Safety
/// This function is unsafe because it dereferences raw pointers from C.
/// The caller must ensure that `engine` is a valid pointer and `visitor_json` is a valid, null-terminated C string.
#[no_mangle]
pub unsafe extern "C" fn analytics_process_visitor(
    engine: *mut AnalyticsEngine,
    visitor_json: *const c_char,
) -> *mut c_char {
    if engine.is_null() || visitor_json.is_null() {
        return std::ptr::null_mut();
    }

    let engine = &mut *engine;
    let visitor_str = match CStr::from_ptr(visitor_json).to_str() {
        Ok(s) => s,
        Err(_) => return std::ptr::null_mut(),
    };

    let visitor_data: VisitorData = match serde_json::from_str(visitor_str) {
        Ok(v) => v,
        Err(_) => return std::ptr::null_mut(),
    };

    match engine.process_visitor(visitor_data) {
        Ok(result) => {
            let result_json = match serde_json::to_string(&result) {
                Ok(json) => json,
                Err(_) => return std::ptr::null_mut(),
            };

            match CString::new(result_json) {
                Ok(c_str) => c_str.into_raw(),
                Err(_) => std::ptr::null_mut(),
            }
        }
        Err(_) => std::ptr::null_mut(),
    }
}

/// Get analytics statistics
///
/// # Safety
/// This function is unsafe because it dereferences raw pointers from C.
/// The caller must ensure that `engine` is a valid pointer and `period` is a valid, null-terminated C string.
#[no_mangle]
pub unsafe extern "C" fn analytics_get_stats(
    engine: *mut AnalyticsEngine,
    period: *const c_char,
) -> *mut c_char {
    if engine.is_null() || period.is_null() {
        return std::ptr::null_mut();
    }

    let engine = &*engine;
    let period_str = match CStr::from_ptr(period).to_str() {
        Ok(s) => s,
        Err(_) => return std::ptr::null_mut(),
    };

    match engine.get_statistics(period_str) {
        Ok(stats) => {
            let stats_json = match serde_json::to_string(&stats) {
                Ok(json) => json,
                Err(_) => return std::ptr::null_mut(),
            };

            match CString::new(stats_json) {
                Ok(c_str) => c_str.into_raw(),
                Err(_) => std::ptr::null_mut(),
            }
        }
        Err(_) => std::ptr::null_mut(),
    }
}

/// Free memory allocated by the analytics engine
///
/// # Safety
/// This function is unsafe because it takes ownership of a raw pointer.
/// The caller must ensure that `ptr` was allocated by this library and is not used after this call.
#[no_mangle]
pub unsafe extern "C" fn analytics_free_string(ptr: *mut c_char) {
    if !ptr.is_null() {
        let _ = CString::from_raw(ptr);
    }
}

/// Cleanup and free the analytics engine
///
/// # Safety
/// This function is unsafe because it takes ownership of a raw pointer.
/// The caller must ensure that `engine` was allocated by this library and is not used after this call.
#[no_mangle]
pub unsafe extern "C" fn analytics_cleanup(engine: *mut AnalyticsEngine) {
    if !engine.is_null() {
        let _ = Box::from_raw(engine);
    }
}
