<?php
// /public_html/serve_image.php

require_once __DIR__ . '/../config/config.php';

// Basic authentication check - users must be logged in to view images
if (!isset($_SESSION['user_id'])) {
    http_response_code(403); // Forbidden
    exit('You are not authorized to view this image.');
}

// Get the requested filename from the URL
$filename = $_GET['file'] ?? '';

// --- SECURITY CHECKS ---
// 1. Ensure the filename is not empty and does not contain ".." to prevent directory traversal attacks
if (empty($filename) || strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
    http_response_code(400); // Bad Request
    exit('Invalid filename.');
}

// 2. Verify it's a valid profile picture filename format (user_ID_timestamp.ext)
if (!preg_match('/^user_\d+_\d+\.(jpg|jpeg|png|gif)$/i', $filename)) {
    http_response_code(400); // Bad Request
    exit('Invalid filename format.');
}

// 3. Get the full path using our helper function
$filePath = getProfilePicturePath($filename);

// 4. Check if the file actually exists
if (!$filePath) {
    http_response_code(404); // Not Found
    exit('Image not found.');
}

// 5. Additional security: verify the requesting user has permission to view this image
// Extract user ID from filename
preg_match('/^user_(\d+)_/', $filename, $matches);
$image_user_id = isset($matches[1]) ? (int)$matches[1] : 0;

// Allow users to view their own images, or any admin to view any image
$user_can_view = ($image_user_id === $_SESSION['user_id']) || in_array($_SESSION['user_role_id'], [1, 2, 3]);

if (!$user_can_view) {
    http_response_code(403); // Forbidden
    exit('You do not have permission to view this image.');
}

// 6. Get the file's MIME type to send the correct Content-Type header
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filePath);
finfo_close($finfo);

// Verify it's actually an image
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
    http_response_code(403); // Forbidden
    exit('Invalid file type.');
}

// --- SERVE THE FILE ---
// Set caching headers for better performance
$lastModified = filemtime($filePath);
$etag = md5($lastModified . $filePath);

header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
header('Etag: ' . $etag);
header('Cache-Control: public, max-age=86400'); // Cache for 1 day

// Check if client has cached version
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
    $ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;
    $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : '';
    
    if ($ifModifiedSince >= $lastModified || $ifNoneMatch === $etag) {
        http_response_code(304); // Not Modified
        exit;
    }
}

// Set the content type header and serve the file
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));

// Clear the output buffer to prevent corrupting the file
if (ob_get_level()) {
    ob_end_clean();
}

// Read the file and output its contents to the browser
readfile($filePath);
exit;
?>