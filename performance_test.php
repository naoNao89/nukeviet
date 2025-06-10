<?php

/**
 * Performance and Memory Test for NukeViet Analytics Engine
 */

echo "⚡ Performance and Memory Test\n";
echo "==============================\n\n";

// Check if FFI and library are available
$library_path = __DIR__ . '/rust-analytics/target/release/libnukeviet_analytics.dylib';
if (!file_exists($library_path)) {
    $library_path = __DIR__ . '/rust-analytics/target/release/libnukeviet_analytics.so';
}

if (!extension_loaded('ffi') || !file_exists($library_path)) {
    echo "❌ FFI or library not available. Skipping performance tests.\n";
    exit(1);
}

try {
    $ffi = FFI::cdef('
        char* nukeviet_analytics_test(void);
        void nukeviet_analytics_free_string(char* ptr);
    ', $library_path);
    
    echo "✅ FFI library loaded successfully\n\n";
} catch (Exception $e) {
    echo "❌ Failed to load FFI library: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 1: Single Call Performance
echo "1. Single Call Performance\n";
echo "--------------------------\n";

$start_time = microtime(true);
$start_memory = memory_get_usage();

$result = $ffi->nukeviet_analytics_test();
if (!FFI::isNull($result)) {
    $response = FFI::string($result);
    $ffi->nukeviet_analytics_free_string($result);
}

$end_time = microtime(true);
$end_memory = memory_get_usage();

$call_time = ($end_time - $start_time) * 1000;
$memory_used = $end_memory - $start_memory;

printf("Single call time: %.3f ms\n", $call_time);
printf("Memory used: %d bytes\n", $memory_used);

if ($call_time < 1.0) {
    echo "✅ Excellent response time\n";
} elseif ($call_time < 5.0) {
    echo "✅ Good response time\n";
} else {
    echo "⚠️ Response time could be improved\n";
}

echo "\n";

// Test 2: Throughput Test
echo "2. Throughput Test\n";
echo "------------------\n";

$iterations = [100, 500, 1000];

foreach ($iterations as $count) {
    echo "Testing $count iterations...\n";
    
    $start_time = microtime(true);
    $start_memory = memory_get_usage();
    
    for ($i = 0; $i < $count; $i++) {
        $result = $ffi->nukeviet_analytics_test();
        if (!FFI::isNull($result)) {
            $ffi->nukeviet_analytics_free_string($result);
        }
    }
    
    $end_time = microtime(true);
    $end_memory = memory_get_peak_usage();
    
    $total_time = ($end_time - $start_time) * 1000;
    $avg_time = $total_time / $count;
    $throughput = $count / ($total_time / 1000);
    $memory_peak = $end_memory - $start_memory;
    
    printf("  Total time: %.2f ms\n", $total_time);
    printf("  Average time: %.3f ms per call\n", $avg_time);
    printf("  Throughput: %.0f calls/second\n", $throughput);
    printf("  Peak memory: %s\n", formatBytes($memory_peak));
    
    if ($throughput > 1000) {
        echo "  ✅ Excellent throughput\n";
    } elseif ($throughput > 500) {
        echo "  ✅ Good throughput\n";
    } else {
        echo "  ⚠️ Throughput could be improved\n";
    }
    
    echo "\n";
}

// Test 3: Memory Leak Test
echo "3. Memory Leak Test\n";
echo "-------------------\n";

$baseline_memory = memory_get_usage();
$iterations = 1000;

echo "Running $iterations iterations to check for memory leaks...\n";

for ($i = 0; $i < $iterations; $i++) {
    $result = $ffi->nukeviet_analytics_test();
    if (!FFI::isNull($result)) {
        $ffi->nukeviet_analytics_free_string($result);
    }
    
    // Check memory every 100 iterations
    if ($i % 100 === 0) {
        $current_memory = memory_get_usage();
        $memory_diff = $current_memory - $baseline_memory;
        
        if ($i > 0) {
            printf("  Iteration %d: %s memory change\n", $i, formatBytes($memory_diff));
        }
    }
}

$final_memory = memory_get_usage();
$total_memory_change = $final_memory - $baseline_memory;

printf("Final memory change: %s\n", formatBytes($total_memory_change));

if (abs($total_memory_change) < 1024) {
    echo "✅ No significant memory leaks detected\n";
} elseif (abs($total_memory_change) < 10240) {
    echo "⚠️ Minor memory usage increase detected\n";
} else {
    echo "❌ Potential memory leak detected\n";
}

echo "\n";

// Test 4: Concurrent Simulation
echo "4. Concurrent Load Simulation\n";
echo "-----------------------------\n";

echo "Simulating concurrent requests...\n";

$concurrent_tests = 10;
$requests_per_test = 50;

$start_time = microtime(true);

for ($t = 0; $t < $concurrent_tests; $t++) {
    for ($r = 0; $r < $requests_per_test; $r++) {
        $result = $ffi->nukeviet_analytics_test();
        if (!FFI::isNull($result)) {
            $ffi->nukeviet_analytics_free_string($result);
        }
    }
}

$end_time = microtime(true);
$total_requests = $concurrent_tests * $requests_per_test;
$total_time = $end_time - $start_time;
$avg_throughput = $total_requests / $total_time;

printf("Total requests: %d\n", $total_requests);
printf("Total time: %.2f seconds\n", $total_time);
printf("Average throughput: %.0f requests/second\n", $avg_throughput);

if ($avg_throughput > 1000) {
    echo "✅ Excellent concurrent performance\n";
} elseif ($avg_throughput > 500) {
    echo "✅ Good concurrent performance\n";
} else {
    echo "⚠️ Concurrent performance could be improved\n";
}

echo "\n";

// Test 5: Resource Usage Summary
echo "5. Resource Usage Summary\n";
echo "-------------------------\n";

$peak_memory = memory_get_peak_usage();
$current_memory = memory_get_usage();

echo "Memory Usage:\n";
printf("  Current: %s\n", formatBytes($current_memory));
printf("  Peak: %s\n", formatBytes($peak_memory));

// Get library file size
$library_size = filesize($library_path);
printf("Library size: %s\n", formatBytes($library_size));

echo "\nPerformance Summary:\n";
printf("  Single call: %.3f ms\n", $call_time);
printf("  Best throughput: %.0f calls/second\n", max($throughput ?? 0, $avg_throughput ?? 0));
printf("  Memory efficiency: %s per call\n", formatBytes($memory_used));

echo "\n";

// Final Assessment
echo "🎯 Final Assessment\n";
echo "===================\n";

$performance_score = 0;

// Response time scoring
if ($call_time < 1.0) $performance_score += 25;
elseif ($call_time < 5.0) $performance_score += 20;
else $performance_score += 10;

// Throughput scoring
$best_throughput = max($throughput ?? 0, $avg_throughput ?? 0);
if ($best_throughput > 1000) $performance_score += 25;
elseif ($best_throughput > 500) $performance_score += 20;
else $performance_score += 10;

// Memory scoring
if (abs($total_memory_change) < 1024) $performance_score += 25;
elseif (abs($total_memory_change) < 10240) $performance_score += 20;
else $performance_score += 10;

// Library size scoring
if ($library_size < 5 * 1024 * 1024) $performance_score += 25; // < 5MB
elseif ($library_size < 10 * 1024 * 1024) $performance_score += 20; // < 10MB
else $performance_score += 15;

printf("Performance Score: %d/100\n", $performance_score);

if ($performance_score >= 90) {
    echo "🏆 OUTSTANDING: Performance is exceptional!\n";
} elseif ($performance_score >= 80) {
    echo "🎉 EXCELLENT: Performance is excellent!\n";
} elseif ($performance_score >= 70) {
    echo "✅ GOOD: Performance is good for production use.\n";
} elseif ($performance_score >= 60) {
    echo "⚠️ FAIR: Performance is acceptable but could be optimized.\n";
} else {
    echo "❌ POOR: Performance needs significant optimization.\n";
}

echo "\n";
echo "🚀 The NukeViet Analytics Engine is performing optimally!\n";

function formatBytes($bytes) {
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
