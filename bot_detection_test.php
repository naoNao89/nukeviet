<?php

/**
 * Comprehensive Bot Detection Test
 * Tests the accuracy of bot detection with various user agents
 */

echo "🤖 Bot Detection Accuracy Test\n";
echo "==============================\n\n";

// Test cases with expected results
$test_cases = [
    // Human browsers
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', false, 'Chrome Windows'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', false, 'Chrome macOS'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', false, 'Safari iOS'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/120.0', false, 'Firefox Windows'],
    ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', false, 'Chrome Linux'],
    
    // Search engine bots
    ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true, 'Googlebot'],
    ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', true, 'Bingbot'],
    ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)', true, 'YandexBot'],
    ['Mozilla/5.0 (compatible; DuckDuckBot-Https/1.1; +https://duckduckgo.com/duckduckbot)', true, 'DuckDuckBot'],
    
    // Social media bots
    ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', true, 'Facebook Bot'],
    ['Twitterbot/1.0', true, 'Twitter Bot'],
    ['LinkedInBot/1.0 (compatible; Mozilla/5.0; +http://www.linkedin.com/)', true, 'LinkedIn Bot'],
    
    // Scrapers and crawlers
    ['Scrapy/2.5.1 (+https://scrapy.org)', true, 'Scrapy'],
    ['python-requests/2.28.1', true, 'Python Requests'],
    ['curl/7.68.0', true, 'cURL'],
    ['wget/1.20.3 (linux-gnu)', true, 'wget'],
    
    // Monitoring and security
    ['UptimeRobot/2.0; +http://www.uptimerobot.com/', true, 'UptimeRobot'],
    ['Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)', true, 'Pingdom'],
    ['Mozilla/5.0 (compatible; Nmap Scripting Engine; https://nmap.org/book/nse.html)', true, 'Nmap'],
    
    // Suspicious patterns
    ['Bot', true, 'Simple Bot'],
    ['Test', true, 'Test Agent'],
    ['', true, 'Empty Agent'],
    ['a', true, 'Single Character'],
    
    // Edge cases
    ['Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)', false, 'Internet Explorer'],
    ['Opera/9.80 (Windows NT 6.0) Presto/2.12.388 Version/12.14', false, 'Opera'],
];

function simple_bot_detection($user_agent) {
    $ua_lower = strtolower($user_agent);
    $bot_indicators = ['bot', 'crawler', 'spider', 'scraper', 'python', 'curl', 'wget'];
    
    $score = 0;
    
    // Check for bot keywords
    foreach ($bot_indicators as $indicator) {
        if (strpos($ua_lower, $indicator) !== false) {
            $score += 0.8;
            break;
        }
    }
    
    // Check length
    if (strlen($user_agent) < 10) {
        $score += 0.9;
    } elseif (strlen($user_agent) < 20) {
        $score += 0.6;
    }
    
    // Check for missing browser indicators
    if (!preg_match('/(mozilla|webkit|gecko)/i', $user_agent)) {
        $score += 0.4;
    }
    
    // Check for programming language indicators
    $prog_languages = ['python', 'java', 'php', 'ruby', 'perl', 'go'];
    foreach ($prog_languages as $lang) {
        if (strpos($ua_lower, $lang) !== false) {
            $score += 0.7;
            break;
        }
    }
    
    return $score > 0.5;
}

// Run tests
$correct = 0;
$total = count($test_cases);
$false_positives = 0;
$false_negatives = 0;

echo "Running " . $total . " test cases...\n\n";

foreach ($test_cases as $i => $case) {
    list($user_agent, $expected, $description) = $case;
    
    $detected = simple_bot_detection($user_agent);
    $is_correct = ($detected === $expected);
    
    if ($is_correct) {
        $correct++;
        $status = "✅";
    } else {
        if ($detected && !$expected) {
            $false_positives++;
            $status = "❌ FP"; // False Positive
        } else {
            $false_negatives++;
            $status = "❌ FN"; // False Negative
        }
    }
    
    $result_text = $detected ? "Bot" : "Human";
    $expected_text = $expected ? "Bot" : "Human";
    
    printf("%-3s %-15s | %-8s | %-8s | %s\n", 
        $status, 
        $description, 
        $expected_text, 
        $result_text,
        substr($user_agent, 0, 60) . (strlen($user_agent) > 60 ? "..." : "")
    );
}

echo "\n";

// Calculate metrics
$accuracy = ($correct / $total) * 100;
$precision = $correct > 0 ? ($correct / ($correct + $false_positives)) * 100 : 0;
$recall = $correct > 0 ? ($correct / ($correct + $false_negatives)) * 100 : 0;
$f1_score = ($precision + $recall) > 0 ? 2 * ($precision * $recall) / ($precision + $recall) : 0;

echo "📊 Test Results:\n";
echo "================\n";
echo "Total Tests: " . $total . "\n";
echo "Correct: " . $correct . "\n";
echo "False Positives: " . $false_positives . "\n";
echo "False Negatives: " . $false_negatives . "\n";
echo "\n";
echo "📈 Metrics:\n";
echo "===========\n";
printf("Accuracy:  %.1f%%\n", $accuracy);
printf("Precision: %.1f%%\n", $precision);
printf("Recall:    %.1f%%\n", $recall);
printf("F1 Score:  %.1f%%\n", $f1_score);
echo "\n";

// Performance assessment
if ($accuracy >= 90) {
    echo "🎉 EXCELLENT: Bot detection accuracy is excellent!\n";
} elseif ($accuracy >= 80) {
    echo "✅ GOOD: Bot detection accuracy is good.\n";
} elseif ($accuracy >= 70) {
    echo "⚠️  FAIR: Bot detection accuracy is acceptable but could be improved.\n";
} else {
    echo "❌ POOR: Bot detection accuracy needs significant improvement.\n";
}

echo "\n";
echo "💡 Analysis:\n";
echo "============\n";

if ($false_positives > 0) {
    echo "• False Positives: " . $false_positives . " legitimate users incorrectly flagged as bots\n";
    echo "  Impact: May exclude real visitors from analytics\n";
}

if ($false_negatives > 0) {
    echo "• False Negatives: " . $false_negatives . " bots incorrectly classified as humans\n";
    echo "  Impact: May inflate visitor statistics with bot traffic\n";
}

if ($accuracy < 90) {
    echo "\n";
    echo "🔧 Recommendations:\n";
    echo "===================\n";
    echo "• Fine-tune detection thresholds\n";
    echo "• Add more sophisticated pattern matching\n";
    echo "• Implement machine learning classification\n";
    echo "• Add IP reputation checking\n";
    echo "• Consider behavioral analysis\n";
}

echo "\n";
echo "🚀 The bot detection engine is " . ($accuracy >= 80 ? "ready for production" : "functional but needs tuning") . "!\n";
