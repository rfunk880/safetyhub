<?php
// /public_html/download_template.php

// **STEP 1: Include the necessary files.**
// config.php provides the database connection ($conn)
// auth.php provides the isUserLoggedIn() function and session checks.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/auth.php'; 

// --- SECURITY CHECKS ---
// 1. Ensure the user is logged in.
if (!isUserLoggedIn()) {
    http_response_code(403); // Forbidden
    die('Access Denied: You are not logged in.');
}

if ($_SESSION['user_role_id'] != 1) {
    http_response_code(403);
    die('Access Denied: You do not have permission to download this file.');
}


// --- FILE SERVING LOGIC ---
// Define the path to the template file inside the secure 'private' folder.
$filePath = __DIR__ . '/../private_files/user_template.csv';

if (!file_exists($filePath)) {
    http_response_code(404);
    die('Error: The template file could not be found on the server.');
}

// Send the correct headers to the browser to force a download dialog.
header('Content-Description: File Transfer');
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="user_template.csv"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));

// Clear the output buffer to prevent corrupting the file
if (ob_get_level()) {
  ob_end_clean();
}

// Read the file and output its contents directly to the browser.
readfile($filePath);
exit;
