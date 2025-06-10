<?php

/**
 * NukeViet Analytics Engine Integration Verification
 * 
 * This script verifies that all components are properly integrated
 * and working together correctly.
 */

echo "🔍 NukeViet Analytics Integration Verification\n";
echo "==============================================\n\n";

$errors = [];
$warnings = [];
$success_count = 0;
$total_checks = 0;

function check($description, $condition, $error_msg = null, $warning_msg = null) {
    global $errors, $warnings, $success_count, $total_checks;
    $total_checks++;
    
    echo sprintf("%-50s", $description . "...");
    
    if ($condition) {
        echo "✅ PASS\n";
        $success_count++;
    } else {
        if ($error_msg) {
            echo "❌ FAIL\n";
            $errors[] = $error_msg;
        } else {
            echo "⚠️  WARN\n";
            $warnings[] = $warning_msg ?: "Check failed";
        }
    }
}

// 1. PHP Environment Checks
echo "1. PHP Environment\n";
echo "------------------\n";

check("PHP Version >= 7.4", version_compare(PHP_VERSION, '7.4.0', '>='), 
    "PHP 7.4 or higher required, found: " . PHP_VERSION);

check("FFI Extension", extension_loaded('ffi'), 
    "FFI extension not loaded. Install with: apt-get install php-ffi");

check("FFI Enabled", ini_get('ffi.enable'), 
    "FFI not enabled. Set ffi.enable=1 in php.ini");

check("JSON Extension", extension_loaded('json'), 
    "JSON extension required");

check("PDO Extension", extension_loaded('pdo'), 
    "PDO extension required for database");

echo "\n";

// 2. File System Checks
echo "2. File System\n";
echo "--------------\n";

$required_files = [
    'rust-analytics/Cargo.toml' => 'Rust project configuration',
    'rust-analytics/src/lib.rs' => 'Rust library source',
    'rust-analytics/src/ffi.rs' => 'FFI interface',
    'includes/core/nukeviet_analytics.php' => 'PHP integration layer',
    'includes/mainfile.php' => 'NukeViet core file'
];

foreach ($required_files as $file => $description) {
    check($description, file_exists($file), 
        "Required file missing: $file");
}

// Check for compiled library
$library_extensions = ['dylib', 'so', 'dll'];
$library_found = false;
foreach ($library_extensions as $ext) {
    $lib_path = "rust-analytics/target/release/libnukeviet_analytics.$ext";
    if (file_exists($lib_path)) {
        $library_found = $lib_path;
        break;
    }
}

check("Compiled Rust Library", $library_found !== false, 
    "Compiled library not found. Run: cd rust-analytics && cargo build --release");

if ($library_found) {
    $lib_size = filesize($library_found);
    check("Library Size > 1MB", $lib_size > 1024*1024, 
        "Library seems too small: " . number_format($lib_size) . " bytes");
}

echo "\n";

// 3. Rust Build Environment
echo "3. Rust Build Environment\n";
echo "-------------------------\n";

// Check if cargo is available
$cargo_version = shell_exec('cargo --version 2>/dev/null');
check("Cargo Available", !empty($cargo_version), 
    "Cargo not found. Install Rust from https://rustup.rs/");

if (!empty($cargo_version)) {
    echo "   Cargo Version: " . trim($cargo_version) . "\n";
}

// Check if we can build
if (is_dir('rust-analytics')) {
    $build_check = shell_exec('cd rust-analytics && cargo check 2>&1');
    $build_success = strpos($build_check, 'Finished') !== false;
    check("Rust Code Compiles", $build_success, 
        "Rust compilation failed: " . $build_check);
}

echo "\n";

// 4. FFI Integration Test
echo "4. FFI Integration\n";
echo "------------------\n";

try {
    if ($library_found && extension_loaded('ffi')) {
        $ffi = FFI::cdef('
            char* nukeviet_analytics_test(void);
            void nukeviet_analytics_free_string(char* ptr);
        ', $library_found);
        
        check("FFI Library Loading", true);
        
        $test_result = $ffi->nukeviet_analytics_test();
        $ffi_call_success = !FFI::isNull($test_result);
        check("FFI Function Call", $ffi_call_success);
        
        if ($ffi_call_success) {
            $result_string = FFI::string($test_result);
            $ffi->nukeviet_analytics_free_string($test_result);
            
            $json_data = json_decode($result_string, true);
            check("FFI JSON Response", is_array($json_data) && isset($json_data['status']));
            
            if ($json_data && $json_data['status'] === 'ok') {
                echo "   Engine Message: " . $json_data['message'] . "\n";
            }
        }
    } else {
        check("FFI Library Loading", false, "Library or FFI not available");
        check("FFI Function Call", false, "Cannot test without library");
        check("FFI JSON Response", false, "Cannot test without function call");
    }
} catch (Exception $e) {
    check("FFI Integration", false, "FFI test failed: " . $e->getMessage());
}

echo "\n";

// 5. Performance Verification
echo "5. Performance\n";
echo "--------------\n";

if ($library_found && extension_loaded('ffi')) {
    try {
        $ffi = FFI::cdef('
            char* nukeviet_analytics_test(void);
            void nukeviet_analytics_free_string(char* ptr);
        ', $library_found);
        
        // Performance test
        $iterations = 50;
        $start_time = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $result = $ffi->nukeviet_analytics_test();
            if (!FFI::isNull($result)) {
                $ffi->nukeviet_analytics_free_string($result);
            }
        }
        
        $end_time = microtime(true);
        $avg_time = (($end_time - $start_time) * 1000) / $iterations;
        
        check("Average Call Time < 5ms", $avg_time < 5.0, 
            "Performance too slow: " . number_format($avg_time, 3) . "ms per call");
        
        echo "   Average call time: " . number_format($avg_time, 3) . "ms\n";
        
        // Memory test
        $memory_before = memory_get_usage();
        for ($i = 0; $i < 10; $i++) {
            $result = $ffi->nukeviet_analytics_test();
            if (!FFI::isNull($result)) {
                $ffi->nukeviet_analytics_free_string($result);
            }
        }
        $memory_after = memory_get_usage();
        $memory_diff = $memory_after - $memory_before;
        
        check("No Memory Leaks", $memory_diff < 1024, 
            "Possible memory leak: " . $memory_diff . " bytes");
        
    } catch (Exception $e) {
        check("Performance Test", false, "Performance test failed: " . $e->getMessage());
    }
} else {
    echo "   Skipping performance tests (FFI not available)\n";
}

echo "\n";

// 6. Integration Summary
echo "6. Summary\n";
echo "----------\n";

$success_rate = ($success_count / $total_checks) * 100;

echo "Total Checks: $total_checks\n";
echo "Passed: $success_count\n";
echo "Failed: " . count($errors) . "\n";
echo "Warnings: " . count($warnings) . "\n";
echo "Success Rate: " . number_format($success_rate, 1) . "%\n\n";

if (count($errors) > 0) {
    echo "❌ ERRORS:\n";
    foreach ($errors as $error) {
        echo "   • $error\n";
    }
    echo "\n";
}

if (count($warnings) > 0) {
    echo "⚠️  WARNINGS:\n";
    foreach ($warnings as $warning) {
        echo "   • $warning\n";
    }
    echo "\n";
}

// Final verdict
if (count($errors) === 0) {
    if (count($warnings) === 0) {
        echo "🎉 EXCELLENT! All checks passed.\n";
        echo "   The NukeViet Analytics Engine is fully integrated and ready for production.\n";
    } else {
        echo "✅ GOOD! Core functionality working with minor warnings.\n";
        echo "   The system is functional but consider addressing the warnings.\n";
    }
} else {
    echo "❌ ISSUES FOUND! Please fix the errors before proceeding.\n";
    echo "   The system may not work correctly until these issues are resolved.\n";
}

echo "\n";
echo "Next Steps:\n";
echo "-----------\n";
if (count($errors) === 0) {
    echo "• Run full tests: php test_analytics.php\n";
    echo "• Test web interface: visit demo_analytics.php in browser\n";
    echo "• Configure analytics in NukeViet admin panel\n";
    echo "• Monitor system performance and accuracy\n";
} else {
    echo "• Fix the errors listed above\n";
    echo "• Re-run this verification script\n";
    echo "• Check installation documentation\n";
}
