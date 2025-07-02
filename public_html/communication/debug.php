<?php
// Debug version to check the distribution and safety_talks table structure
require_once __DIR__ . '/../../config/config.php';

echo "<h2>Debug: History.php Distribution Date Issue</h2>";

// Test the corrected query (using sent_at instead of created_at)
echo "<h3>Testing CORRECTED History Query:</h3>";
$sql = "SELECT st.id, st.title, 
               st.first_distributed_at as initial_distribution, 
               MAX(d.sent_at) as last_sent, 
               COUNT(DISTINCT d.id) as total_distributed, 
               COUNT(c.id) as total_confirmed,
               st.created_at
        FROM safety_talks st 
        LEFT JOIN distributions d ON st.id = d.safety_talk_id 
        LEFT JOIN confirmations c ON d.id = c.distribution_id 
        WHERE st.is_archived = 0 
        GROUP BY st.id, st.title, st.first_distributed_at, st.created_at
        ORDER BY st.created_at DESC";

$result = $conn->query($sql);
if (!$result) {
    echo "<p style='color: red;'>SQL Error: " . $conn->error . "</p>";
} else {
    echo "<p style='color: green;'>Query successful! Results:</p>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Title</th><th>First Distributed At</th><th>Last Sent</th><th>Total Distributed</th><th>Total Confirmed</th><th>Created At</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['title']) . "</td>";
        echo "<td style='" . ($row['initial_distribution'] ? 'color: green;' : 'color: red; font-weight: bold;') . "'>" . htmlspecialchars($row['initial_distribution'] ?: 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($row['last_sent'] ?: 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($row['total_distributed']) . "</td>";
        echo "<td>" . htmlspecialchars($row['total_confirmed']) . "</td>";
        echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Show sample data from safety_talks
echo "<h3>Sample Safety Talks Data:</h3>";
$result = $conn->query("SELECT id, title, created_at, first_distributed_at, is_archived FROM safety_talks ORDER BY id DESC LIMIT 5");
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Title</th><th>Created At</th><th>First Distributed At</th><th>Is Archived</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['title']) . "</td>";
    echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
    echo "<td style='" . ($row['first_distributed_at'] ? 'color: green;' : 'color: red; font-weight: bold;') . "'>" . htmlspecialchars($row['first_distributed_at'] ?: 'NULL - THIS IS THE ISSUE!') . "</td>";
    echo "<td>" . htmlspecialchars($row['is_archived']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Show sample data from distributions
echo "<h3>Sample Distributions Data:</h3>";
$result = $conn->query("SELECT id, safety_talk_id, employee_id, sent_at, notification_count FROM distributions ORDER BY id DESC LIMIT 5");
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Safety Talk ID</th><th>Employee ID</th><th>Sent At</th><th>Notification Count</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['safety_talk_id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['employee_id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['sent_at']) . "</td>";
    echo "<td>" . htmlspecialchars($row['notification_count']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check if first_distributed_at needs to be updated
echo "<h3>Missing first_distributed_at Fix:</h3>";
echo "<p>If first_distributed_at is NULL but distributions exist, we need to update it:</p>";

$fix_sql = "UPDATE safety_talks st 
            SET first_distributed_at = (
                SELECT MIN(d.sent_at) 
                FROM distributions d 
                WHERE d.safety_talk_id = st.id
            ) 
            WHERE st.first_distributed_at IS NULL 
            AND EXISTS (
                SELECT 1 FROM distributions d2 WHERE d2.safety_talk_id = st.id
            )";

echo "<div style='background: #f0f0f0; padding: 10px; border: 1px solid #ccc;'>";
echo "<strong>SQL to fix the issue:</strong><br>";
echo "<code>" . htmlspecialchars($fix_sql) . "</code>";
echo "</div>";

echo "<h3>🎯 The Fix:</h3>";
echo "<ol>";
echo "<li><strong>Issue:</strong> 'first_distributed_at' is NULL even though distributions exist</li>";
echo "<li><strong>Solution:</strong> Update first_distributed_at to use the earliest distribution date</li>";
echo "<li><strong>Then:</strong> Update the history.php query to use the correct date fields</li>";
echo "</ol>";

// Count how many talks need fixing
$count_result = $conn->query("SELECT COUNT(*) as count FROM safety_talks st WHERE st.first_distributed_at IS NULL AND EXISTS (SELECT 1 FROM distributions d WHERE d.safety_talk_id = st.id)");
$count_row = $count_result->fetch_assoc();
echo "<p><strong>" . $count_row['count'] . " safety talks need their first_distributed_at field updated.</strong></p>";
?>