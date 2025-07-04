<?php
// Minimal working index.php for testing

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Basic Index.php Test</h1>";

try {
    require_once __DIR__ . '/../../config/config.php';
    echo "✅ Config loaded<br>";
} catch (Exception $e) {
    echo "❌ Config error: " . $e->getMessage() . "<br>";
    exit;
}

try {
    require_once __DIR__ . '/../../src/auth.php';
    echo "✅ Auth loaded<br>";
} catch (Exception $e) {
    echo "❌ Auth error: " . $e->getMessage() . "<br>";
    exit;
}

// Check if user is logged in
if (!isUserLoggedIn()) {
    echo "❌ Not logged in - <a href='../../login.php'>Login here</a><br>";
    exit;
}

echo "✅ User logged in<br>";
echo "User ID: " . $_SESSION['user_id'] . "<br>";
echo "Role ID: " . $_SESSION['user_role_id'] . "<br>";

// Simple user list
echo "<h2>User List (Basic)</h2>";
$result = $conn->query("SELECT id, firstName, lastName, email FROM users LIMIT 5");
if ($result) {
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Name</th><th>Email</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['firstName'] . ' ' . $row['lastName']) . "</td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "❌ Database query failed: " . $conn->error;
}

echo "<p><strong>If this works, the problem is in the complex index.php file</strong></p>";
?>