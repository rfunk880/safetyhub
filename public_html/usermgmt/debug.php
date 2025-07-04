<?php
// debug.php - Place this in usermgmt/ folder to diagnose issues

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>Debug Information</h1>";
echo "<p>Script started successfully</p>";

echo "<h2>1. File Paths Check</h2>";
$config_path = __DIR__ . '/../../config/config.php';
$auth_path = __DIR__ . '/../../src/auth.php';

echo "Config path: " . $config_path . "<br>";
echo "Config exists: " . (file_exists($config_path) ? "YES" : "NO") . "<br>";
echo "Auth path: " . $auth_path . "<br>";
echo "Auth exists: " . (file_exists($auth_path) ? "YES" : "NO") . "<br>";

echo "<h2>2. Include Config</h2>";
try {
    require_once $config_path;
    echo "✅ Config loaded successfully<br>";
} catch (Exception $e) {
    echo "❌ Config error: " . $e->getMessage() . "<br>";
    die();
} catch (Error $e) {
    echo "❌ Config fatal error: " . $e->getMessage() . "<br>";
    die();
}

echo "<h2>3. Include Auth</h2>";
try {
    require_once $auth_path;
    echo "✅ Auth loaded successfully<br>";
} catch (Exception $e) {
    echo "❌ Auth error: " . $e->getMessage() . "<br>";
} catch (Error $e) {
    echo "❌ Auth fatal error: " . $e->getMessage() . "<br>";
}

echo "<h2>4. Session Check</h2>";
echo "Session started: " . (session_status() === PHP_SESSION_ACTIVE ? "YES" : "NO") . "<br>";
echo "User logged in: " . (isset($_SESSION['user_id']) ? "YES (ID: " . $_SESSION['user_id'] . ")" : "NO") . "<br>";

echo "<h2>5. Database Check</h2>";
if (isset($conn)) {
    echo "Database connection: ✅ Connected<br>";
    echo "Connection info: " . $conn->host_info . "<br>";
} else {
    echo "Database connection: ❌ Not available<br>";
}

echo "<h2>6. Function Check</h2>";
$functions_to_check = ['isUserLoggedIn', 'getRoleName', 'isUserArchived'];
foreach ($functions_to_check as $func) {
    echo "Function {$func}: " . (function_exists($func) ? "✅ EXISTS" : "❌ MISSING") . "<br>";
}

echo "<h2>7. PHP Version & Memory</h2>";
echo "PHP Version: " . PHP_VERSION . "<br>";
echo "Memory Limit: " . ini_get('memory_limit') . "<br>";
echo "Max Execution Time: " . ini_get('max_execution_time') . "<br>";

echo "<h2>8. Test Basic Query</h2>";
if (isset($conn)) {
    try {
        $result = $conn->query("SELECT COUNT(*) as count FROM users");
        if ($result) {
            $row = $result->fetch_assoc();
            echo "✅ Database query successful - " . $row['count'] . " users found<br>";
        } else {
            echo "❌ Database query failed: " . $conn->error . "<br>";
        }
    } catch (Exception $e) {
        echo "❌ Database query exception: " . $e->getMessage() . "<br>";
    }
}

echo "<h2>9. Navigation Function</h2>";
if (function_exists('renderNavigation')) {
    echo "✅ renderNavigation function exists<br>";
} else {
    echo "❌ renderNavigation function missing - will use fallback<br>";
}

echo "<p><strong>If you see this message, the basic PHP execution is working!</strong></p>";
echo "<p><a href='index.php'>Try main index.php again</a></p>";
?>