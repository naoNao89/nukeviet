//! FFI (Foreign Function Interface) module for PHP integration

use crate::config::AnalyticsConfig;
use crate::types::{AnalyticsEngine, VisitorData};
use std::ffi::{CStr, CString};
use std::os::raw::c_char;
use std::ptr;

/// FFI-safe error codes
#[repr(C)]
pub enum AnalyticsErrorCode {
    Success = 0,
    InvalidInput = 1,
    JsonParseError = 2,
    DatabaseError = 3,
    ConfigError = 4,
    EngineError = 5,
    MemoryError = 6,
}

/// FFI-safe result structure
#[repr(C)]
pub struct AnalyticsResult {
    pub error_code: AnalyticsErrorCode,
    pub data: *mut c_char,
    pub error_message: *mut c_char,
}

impl AnalyticsResult {
    fn success(data: String) -> Self {
        let c_data = match CString::new(data) {
            Ok(s) => s.into_raw(),
            Err(_) => ptr::null_mut(),
        };

        Self {
            error_code: AnalyticsErrorCode::Success,
            data: c_data,
            error_message: ptr::null_mut(),
        }
    }

    fn error(code: AnalyticsErrorCode, message: String) -> Self {
        let c_message = match CString::new(message) {
            Ok(s) => s.into_raw(),
            Err(_) => ptr::null_mut(),
        };

        Self {
            error_code: code,
            data: ptr::null_mut(),
            error_message: c_message,
        }
    }
}

/// Initialize analytics engine with configuration
///
/// # Safety
/// This function is unsafe because it dereferences a raw pointer from C.
/// The caller must ensure that `config_json` is a valid, null-terminated C string.
#[no_mangle]
pub unsafe extern "C" fn nukeviet_analytics_init(
    config_json: *const c_char,
) -> *mut AnalyticsEngine {
    if config_json.is_null() {
        return ptr::null_mut();
    }

    let config_str = match CStr::from_ptr(config_json).to_str() {
        Ok(s) => s,
        Err(_) => return ptr::null_mut(),
    };

    let config: AnalyticsConfig = match serde_json::from_str(config_str) {
        Ok(c) => c,
        Err(_) => return ptr::null_mut(),
    };

    // Validate configuration
    if config.validate().is_err() {
        return ptr::null_mut();
    }

    match AnalyticsEngine::new(config) {
        Ok(engine) => Box::into_raw(Box::new(engine)),
        Err(_) => ptr::null_mut(),
    }
}

/// Process visitor data and return analytics result
///
/// # Safety
/// This function is unsafe because it dereferences raw pointers from C.
/// The caller must ensure that `engine` is a valid pointer and `visitor_json` is a valid, null-terminated C string.
#[no_mangle]
pub unsafe extern "C" fn nukeviet_analytics_process_visitor(
    engine: *mut AnalyticsEngine,
    visitor_json: *const c_char,
) -> AnalyticsResult {
    if engine.is_null() || visitor_json.is_null() {
        return AnalyticsResult::error(
            AnalyticsErrorCode::InvalidInput,
            "Engine or visitor data is null".to_string(),
        );
    }

    let engine = &mut *engine;
    let visitor_str = match CStr::from_ptr(visitor_json).to_str() {
        Ok(s) => s,
        Err(_) => {
            return AnalyticsResult::error(
                AnalyticsErrorCode::InvalidInput,
                "Invalid visitor JSON string".to_string(),
            )
        }
    };

    let visitor_data: VisitorData = match serde_json::from_str(visitor_str) {
        Ok(v) => v,
        Err(e) => {
            return AnalyticsResult::error(
                AnalyticsErrorCode::JsonParseError,
                format!("Failed to parse visitor JSON: {}", e),
            )
        }
    };

    match engine.process_visitor(visitor_data) {
        Ok(result) => match serde_json::to_string(&result) {
            Ok(json) => AnalyticsResult::success(json),
            Err(e) => AnalyticsResult::error(
                AnalyticsErrorCode::JsonParseError,
                format!("Failed to serialize result: {}", e),
            ),
        },
        Err(e) => AnalyticsResult::error(
            AnalyticsErrorCode::EngineError,
            format!("Engine processing error: {}", e),
        ),
    }
}

/// Get analytics statistics for a period
///
/// # Safety
/// This function is unsafe because it dereferences raw pointers from C.
/// The caller must ensure that `engine` is a valid pointer and `period` is a valid, null-terminated C string.
#[no_mangle]
pub unsafe extern "C" fn nukeviet_analytics_get_stats(
    engine: *mut AnalyticsEngine,
    period: *const c_char,
) -> AnalyticsResult {
    if engine.is_null() || period.is_null() {
        return AnalyticsResult::error(
            AnalyticsErrorCode::InvalidInput,
            "Engine or period is null".to_string(),
        );
    }

    let engine = &*engine;
    let period_str = match CStr::from_ptr(period).to_str() {
        Ok(s) => s,
        Err(_) => {
            return AnalyticsResult::error(
                AnalyticsErrorCode::InvalidInput,
                "Invalid period string".to_string(),
            )
        }
    };

    match engine.get_statistics(period_str) {
        Ok(stats) => match serde_json::to_string(&stats) {
            Ok(json) => AnalyticsResult::success(json),
            Err(e) => AnalyticsResult::error(
                AnalyticsErrorCode::JsonParseError,
                format!("Failed to serialize statistics: {}", e),
            ),
        },
        Err(e) => AnalyticsResult::error(
            AnalyticsErrorCode::DatabaseError,
            format!("Database error: {}", e),
        ),
    }
}

/// Check if visitor is a bot (simplified interface)
///
/// # Safety
/// This function is unsafe because it dereferences raw pointers from C.
/// The caller must ensure that all pointers are valid and C strings are null-terminated.
#[no_mangle]
pub unsafe extern "C" fn nukeviet_analytics_is_bot(
    engine: *mut AnalyticsEngine,
    user_agent: *const c_char,
    ip_address: *const c_char,
) -> AnalyticsResult {
    if engine.is_null() || user_agent.is_null() || ip_address.is_null() {
        return AnalyticsResult::error(
            AnalyticsErrorCode::InvalidInput,
            "Engine, user agent, or IP address is null".to_string(),
        );
    }

    let engine = &mut *engine;
    let ua_str = match CStr::from_ptr(user_agent).to_str() {
        Ok(s) => s,
        Err(_) => {
            return AnalyticsResult::error(
                AnalyticsErrorCode::InvalidInput,
                "Invalid user agent string".to_string(),
            )
        }
    };

    let ip_str = match CStr::from_ptr(ip_address).to_str() {
        Ok(s) => s,
        Err(_) => {
            return AnalyticsResult::error(
                AnalyticsErrorCode::InvalidInput,
                "Invalid IP address string".to_string(),
            )
        }
    };

    // Create minimal visitor data for bot detection
    let visitor_data = VisitorData {
        visitor_id: uuid::Uuid::new_v4().to_string(),
        ip_address: ip_str.to_string(),
        user_agent: ua_str.to_string(),
        referer: None,
        request_uri: "/".to_string(),
        timestamp: chrono::Utc::now(),
        session_id: None,
        cookies: std::collections::HashMap::new(),
        headers: std::collections::HashMap::new(),
        country: None,
        language: None,
    };

    match engine.process_visitor(visitor_data) {
        Ok(result) => {
            let simple_result = serde_json::json!({
                "is_bot": result.is_bot,
                "bot_type": result.bot_type,
                "confidence": result.confidence,
                "should_count": result.should_count
            });

            match serde_json::to_string(&simple_result) {
                Ok(json) => AnalyticsResult::success(json),
                Err(e) => AnalyticsResult::error(
                    AnalyticsErrorCode::JsonParseError,
                    format!("Failed to serialize bot detection result: {}", e),
                ),
            }
        }
        Err(e) => AnalyticsResult::error(
            AnalyticsErrorCode::EngineError,
            format!("Bot detection error: {}", e),
        ),
    }
}

/// Get engine version and build info
#[no_mangle]
pub extern "C" fn nukeviet_analytics_version() -> *mut c_char {
    let version_info = serde_json::json!({
        "version": env!("CARGO_PKG_VERSION"),
        "name": env!("CARGO_PKG_NAME"),
        "description": env!("CARGO_PKG_DESCRIPTION"),
        "build_time": chrono::Utc::now().to_rfc3339(),
        "features": [
            "advanced_bot_detection",
            "behavioral_analysis",
            "machine_learning",
            "real_time_processing"
        ]
    });

    match serde_json::to_string(&version_info) {
        Ok(json) => match CString::new(json) {
            Ok(c_str) => c_str.into_raw(),
            Err(_) => ptr::null_mut(),
        },
        Err(_) => ptr::null_mut(),
    }
}

/// Free memory allocated for strings
///
/// # Safety
/// This function is unsafe because it takes ownership of a raw pointer.
/// The caller must ensure that `ptr` was allocated by this library and is not used after this call.
#[no_mangle]
pub unsafe extern "C" fn nukeviet_analytics_free_string(ptr: *mut c_char) {
    if !ptr.is_null() {
        let _ = CString::from_raw(ptr);
    }
}

/// Free memory allocated for AnalyticsResult
///
/// # Safety
/// This function is unsafe because it takes ownership of raw pointers within the result.
/// The caller must ensure that the pointers in `result` were allocated by this library.
#[no_mangle]
pub unsafe extern "C" fn nukeviet_analytics_free_result(result: AnalyticsResult) {
    if !result.data.is_null() {
        let _ = CString::from_raw(result.data);
    }
    if !result.error_message.is_null() {
        let _ = CString::from_raw(result.error_message);
    }
}

/// Cleanup and free the analytics engine
///
/// # Safety
/// This function is unsafe because it takes ownership of a raw pointer.
/// The caller must ensure that `engine` was allocated by this library and is not used after this call.
#[no_mangle]
pub unsafe extern "C" fn nukeviet_analytics_cleanup(engine: *mut AnalyticsEngine) {
    if !engine.is_null() {
        let _ = Box::from_raw(engine);
    }
}

/// Test function to verify FFI is working
#[no_mangle]
pub extern "C" fn nukeviet_analytics_test() -> *mut c_char {
    let test_result = serde_json::json!({
        "status": "ok",
        "message": "NukeViet Analytics Engine FFI is working",
        "timestamp": chrono::Utc::now().to_rfc3339()
    });

    match serde_json::to_string(&test_result) {
        Ok(json) => match CString::new(json) {
            Ok(c_str) => c_str.into_raw(),
            Err(_) => ptr::null_mut(),
        },
        Err(_) => ptr::null_mut(),
    }
}

/// Validate configuration JSON
///
/// # Safety
/// This function is unsafe because it dereferences a raw pointer from C.
/// The caller must ensure that `config_json` is a valid, null-terminated C string.
#[no_mangle]
pub unsafe extern "C" fn nukeviet_analytics_validate_config(
    config_json: *const c_char,
) -> AnalyticsResult {
    if config_json.is_null() {
        return AnalyticsResult::error(
            AnalyticsErrorCode::InvalidInput,
            "Configuration JSON is null".to_string(),
        );
    }

    let config_str = match CStr::from_ptr(config_json).to_str() {
        Ok(s) => s,
        Err(_) => {
            return AnalyticsResult::error(
                AnalyticsErrorCode::InvalidInput,
                "Invalid configuration JSON string".to_string(),
            )
        }
    };

    let config: AnalyticsConfig = match serde_json::from_str(config_str) {
        Ok(c) => c,
        Err(e) => {
            return AnalyticsResult::error(
                AnalyticsErrorCode::JsonParseError,
                format!("Failed to parse configuration JSON: {}", e),
            )
        }
    };

    match config.validate() {
        Ok(_) => AnalyticsResult::success("Configuration is valid".to_string()),
        Err(e) => AnalyticsResult::error(
            AnalyticsErrorCode::ConfigError,
            format!("Configuration validation failed: {}", e),
        ),
    }
}
