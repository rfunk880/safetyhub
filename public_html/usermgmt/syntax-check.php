<?php
// syntax-check.php - Check if index.php has syntax errors

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Syntax Check for index.php</h1>";

$file_path = __DIR__ . '/index.php';

if (!file_exists($file_path)) {
    echo "❌ index.php not found at: " . $file_path;
    exit;
}

echo "📁 File found: " . $file_path . "<br>";
echo "📏 File size: " . filesize($file_path) . " bytes<br>";

// Check syntax without executing
$output = [];
$return_code = 0;
exec("php -l " . escapeshellarg($file_path) . " 2>&1", $output, $return_code);

echo "<h2>Syntax Check Result:</h2>";
if ($return_code === 0) {
    echo "✅ <strong>No syntax errors detected</strong><br>";
    echo "<pre>" . implode("\n", $output) . "</pre>";
} else {
    echo "❌ <strong>Syntax errors found:</strong><br>";
    echo "<pre style='color: red; background: #ffe6e6; padding: 10px;'>" . implode("\n", $output) . "</pre>";
}

// Also check line by line for common issues
echo "<h2>Common Issue Check:</h2>";
$content = file_get_contents($file_path);

// Check for unclosed braces
$open_braces = substr_count($content, '{');
$close_braces = substr_count($content, '}');
echo "Open braces: {$open_braces}, Close braces: {$close_braces}<br>";
if ($open_braces !== $close_braces) {
    echo "❌ <strong>Brace mismatch detected!</strong><br>";
} else {
    echo "✅ Braces balanced<br>";
}

// Check for unclosed quotes
$single_quotes = substr_count($content, "'");
$double_quotes = substr_count($content, '"');
echo "Single quotes: {$single_quotes} " . ($single_quotes % 2 === 0 ? "✅" : "❌") . "<br>";
echo "Double quotes: {$double_quotes} " . ($double_quotes % 2 === 0 ? "✅" : "❌") . "<br>";

// Check for common problematic patterns
$problematic_patterns = [
    '/<\?php\s*\?>/i' => 'Empty PHP tags',
    '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*$/' => 'Incomplete assignments',
    '/function\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\([^)]*\)\s*$/' => 'Function without opening brace'
];

foreach ($problematic_patterns as $pattern => $description) {
    if (preg_match($pattern, $content)) {
        echo "⚠️ Potential issue: {$description}<br>";
    }
}

echo "<br><strong>Next steps:</strong>";
echo "<ol>";
echo "<li>If syntax errors are shown above, fix them</li>";
echo "<li>If no syntax errors, run <a href='debug.php'>debug.php</a></li>";
echo "<li>Check your server's PHP error log</li>";
echo "</ol>";
?>