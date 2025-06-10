//! QR code generation for TOTP setup

use qrcode::{QrCode, EcLevel};
use image::{ImageBuffer, Luma, DynamicImage};
use base64::{Engine as _, engine::general_purpose};
use crate::error::{Result, TwoFAError};
use crate::totp;
use std::io::Cursor;

/// Generate a QR code for TOTP setup
pub fn generate_qr_code(secret: &str, account: &str, issuer: &str) -> Result<String> {
    // Generate the TOTP URI
    let uri = totp::generate_uri(secret, account, Some(issuer))?;
    
    // Create QR code
    let qr_code = QrCode::with_error_correction_level(&uri, EcLevel::M)
        .map_err(|e| TwoFAError::QrCode(format!("Failed to create QR code: {}", e)))?;
    
    // Convert to image
    let image = qr_code.render::<Luma<u8>>().build();
    
    // Convert to base64 PNG
    let png_data = image_to_png_base64(&DynamicImage::ImageLuma8(image))?;
    
    Ok(png_data)
}

/// Generate a QR code with custom size
pub fn generate_qr_code_with_size(
    secret: &str, 
    account: &str, 
    issuer: &str, 
    size: u32
) -> Result<String> {
    let uri = totp::generate_uri(secret, account, Some(issuer))?;
    
    let qr_code = QrCode::with_error_correction_level(&uri, EcLevel::M)
        .map_err(|e| TwoFAError::QrCode(format!("Failed to create QR code: {}", e)))?;
    
    // Render with custom size
    let image = qr_code.render::<Luma<u8>>()
        .max_dimensions(size, size)
        .build();
    
    let png_data = image_to_png_base64(&DynamicImage::ImageLuma8(image))?;
    
    Ok(png_data)
}

/// Generate QR code as SVG string
pub fn generate_qr_code_svg(secret: &str, account: &str, issuer: &str) -> Result<String> {
    let uri = totp::generate_uri(secret, account, Some(issuer))?;

    let qr_code = QrCode::with_error_correction_level(&uri, EcLevel::M)
        .map_err(|e| TwoFAError::QrCode(format!("Failed to create QR code: {}", e)))?;

    // Generate SVG (simplified version)
    let svg = qr_code.render::<qrcode::render::svg::Color>()
        .min_dimensions(200, 200)
        .dark_color(qrcode::render::svg::Color("#000000"))
        .light_color(qrcode::render::svg::Color("#ffffff"))
        .build();

    Ok(svg)
}

/// Generate QR code with custom colors and styling
pub fn generate_styled_qr_code(
    secret: &str,
    account: &str,
    issuer: &str,
    dark_color: (u8, u8, u8),
    light_color: (u8, u8, u8),
    size: u32,
) -> Result<String> {
    let uri = totp::generate_uri(secret, account, Some(issuer))?;
    
    let qr_code = QrCode::with_error_correction_level(&uri, EcLevel::M)
        .map_err(|e| TwoFAError::QrCode(format!("Failed to create QR code: {}", e)))?;
    
    // Create colored image
    let modules = qr_code.to_colors();
    let width = qr_code.width();
    let scale = size / width as u32;
    
    let mut img = ImageBuffer::new(size, size);
    
    for (y, row) in modules.chunks(width).enumerate() {
        for (x, &module) in row.iter().enumerate() {
            let color = if module == qrcode::Color::Dark {
                image::Rgb([dark_color.0, dark_color.1, dark_color.2])
            } else {
                image::Rgb([light_color.0, light_color.1, light_color.2])
            };
            
            // Scale up the pixel
            for dy in 0..scale {
                for dx in 0..scale {
                    let px = x as u32 * scale + dx;
                    let py = y as u32 * scale + dy;
                    if px < size && py < size {
                        img.put_pixel(px, py, color);
                    }
                }
            }
        }
    }
    
    let dynamic_img = DynamicImage::ImageRgb8(img);
    let png_data = image_to_png_base64(&dynamic_img)?;
    
    Ok(png_data)
}

/// Convert image to base64 PNG data
fn image_to_png_base64(image: &DynamicImage) -> Result<String> {
    let mut buffer = Vec::new();
    let mut cursor = Cursor::new(&mut buffer);
    
    image.write_to(&mut cursor, image::ImageFormat::Png)
        .map_err(|e| TwoFAError::QrCode(format!("Failed to encode PNG: {}", e)))?;
    
    let base64_data = general_purpose::STANDARD.encode(&buffer);
    Ok(format!("data:image/png;base64,{}", base64_data))
}

/// Validate QR code content
pub fn validate_qr_content(content: &str) -> Result<bool> {
    // Check if it's a valid TOTP URI
    if !content.starts_with("otpauth://totp/") {
        return Ok(false);
    }
    
    // Try to parse the URI
    let url = url::Url::parse(content)
        .map_err(|e| TwoFAError::QrCode(format!("Invalid URI: {}", e)))?;
    
    // Check for required parameters
    let query_pairs: std::collections::HashMap<_, _> = url.query_pairs().collect();
    
    if !query_pairs.contains_key("secret") {
        return Ok(false);
    }
    
    if !query_pairs.contains_key("issuer") {
        return Ok(false);
    }
    
    Ok(true)
}

/// Extract TOTP parameters from QR code content
pub fn extract_totp_params(content: &str) -> Result<TotpParams> {
    if !validate_qr_content(content)? {
        return Err(TwoFAError::QrCode("Invalid QR code content".to_string()));
    }
    
    let url = url::Url::parse(content)
        .map_err(|e| TwoFAError::QrCode(format!("Invalid URI: {}", e)))?;
    
    let query_pairs: std::collections::HashMap<_, _> = url.query_pairs().collect();
    
    let secret = query_pairs.get("secret")
        .ok_or_else(|| TwoFAError::QrCode("Missing secret parameter".to_string()))?
        .to_string();
    
    let issuer = query_pairs.get("issuer")
        .ok_or_else(|| TwoFAError::QrCode("Missing issuer parameter".to_string()))?
        .to_string();
    
    let account = url.path().trim_start_matches('/').to_string();
    
    let digits = query_pairs.get("digits")
        .and_then(|d| d.parse().ok())
        .unwrap_or(6);
    
    let period = query_pairs.get("period")
        .and_then(|p| p.parse().ok())
        .unwrap_or(30);
    
    let algorithm = query_pairs.get("algorithm")
        .map(|a| a.to_string())
        .unwrap_or_else(|| "SHA1".to_string());
    
    Ok(TotpParams {
        secret,
        issuer,
        account,
        digits,
        period,
        algorithm,
    })
}

/// TOTP parameters extracted from QR code
#[derive(Debug, Clone)]
pub struct TotpParams {
    pub secret: String,
    pub issuer: String,
    pub account: String,
    pub digits: u32,
    pub period: u64,
    pub algorithm: String,
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config;
    
    #[tokio::test]
    async fn test_qr_generation() {
        config::init_default().unwrap();
        
        let secret = "JBSWY3DPEHPK3PXP";
        let account = "test@example.com";
        let issuer = "Test App";
        
        let qr_data = generate_qr_code(secret, account, issuer).unwrap();
        assert!(qr_data.starts_with("data:image/png;base64,"));
    }
    
    #[tokio::test]
    async fn test_qr_validation() {
        let valid_uri = "otpauth://totp/Test%20App:test@example.com?secret=JBSWY3DPEHPK3PXP&issuer=Test%20App";
        assert!(validate_qr_content(valid_uri).unwrap());
        
        let invalid_uri = "https://example.com";
        assert!(!validate_qr_content(invalid_uri).unwrap());
    }
    
    #[tokio::test]
    async fn test_param_extraction() {
        let uri = "otpauth://totp/Test%20App:test@example.com?secret=JBSWY3DPEHPK3PXP&issuer=Test%20App&digits=6&period=30";
        let params = extract_totp_params(uri).unwrap();
        
        assert_eq!(params.secret, "JBSWY3DPEHPK3PXP");
        assert_eq!(params.issuer, "Test App");
        assert_eq!(params.digits, 6);
        assert_eq!(params.period, 30);
    }
}
