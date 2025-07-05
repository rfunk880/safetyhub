<?php
// get_user_data.php - Returns user data as JSON for edit modal
require_once __DIR__ . '/../../config/config.php';

// Set JSON content type
header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Permission check - only admins can edit users
$admin_roles = [1, 2, 3];
if (!in_array($_SESSION['user_role_id'], $admin_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get user ID
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

// Fetch user data
$stmt = $conn->prepare("SELECT id, firstName, lastName, email, employeeId, roleId, title, mobile_phone, alt_phone, emergency_contact_name, emergency_contact_phone, type FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Return user data
echo json_encode($user);
?>