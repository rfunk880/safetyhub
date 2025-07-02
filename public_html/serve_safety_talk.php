<?php
// /public_html/serve_safety_talk.php
// Secure file serving for safety talk uploads

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../config/communication.php';

// Basic authentication check - users must be logged in
if (!isUserLoggedIn()) {
    http_response_code(403);
    exit('You are not authorized to view this file.');
}

// Get the requested filename from the URL
$filename = $_GET['file'] ?? '';

// --- SECURITY CHECKS ---
// 1. Ensure the filename is not empty and does not contain ".." to prevent directory traversal attacks
if (empty($filename) || strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
    http_response_code(400);
    exit('Invalid filename.');
}

// 2. Construct the full file path
$full_path = COMMUNICATION_UPLOAD_DIR . $filename;

// 3. Check if the file actually exists
if (!file_exists($full_path)) {
    http_response_code(404);
    exit('File not found.');
}

// 4. Security check: Only allow access to files that are actually referenced in the database
// Check for both old and new file path formats
$stmt = $conn->prepare("
    SELECT id, title FROM safety_talks 
    WHERE file_path = ? 
    OR file_path = ? 
    OR file_path = ?
");
$new_format = '/serve_safety_talk.php?file=' . urlencode($filename);
$old_format = COMMUNICATION_UPLOAD_URL . $filename;
$old_format2 = '/uploads/safety_talks/' . $filename;

$stmt->bind_param("sss", $new_format, $old_format, $old_format2);
$stmt->execute();
$result = $stmt->get_result();
$safety_talk = $result->fetch_assoc();
$stmt->close();

if (!$safety_talk) {
    http_response_code(403);
    exit('File access not authorized. No matching safety talk found.');
}

// 5. Additional permission check - user must have access to communication module
if (!hasCommAdminAccess() && !canReceiveSafetyTalks()) {
    http_response_code(403);
    exit('You do not have permission to access this file.');
}

// 6. Get the file's MIME type to send the correct Content-Type header
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $full_path);
finfo_close($finfo);

// 7. Verify it's an allowed file type
$allowed_mime_types = ['application/pdf', 'video/mp4'];
if (!in_array($mimeType, $allowed_mime_types)) {
    http_response_code(403);
    exit('Invalid file type.');
}

// --- SERVE THE FILE ---
// Set caching headers for better performance
$lastModified = filemtime($full_path);
$etag = md5($lastModified . $full_path);

header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
header('Etag: ' . $etag);
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour

// Check if client has cached version
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
    $ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;
    $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : '';
    
    if ($ifModifiedSince >= $lastModified || $ifNoneMatch === $etag) {
        http_response_code(304); // Not Modified
        exit;
    }
}

// Set appropriate headers for the file type
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($full_path));

// For PDFs, display inline; for videos, allow inline viewing
if ($mimeType === 'application/pdf') {
    header('Content-Disposition: inline; filename="' . basename($filename) . '"');
} else {
    header('Content-Disposition: inline; filename="' . basename($filename) . '"');
}

// Clear the output buffer to prevent corrupting the file
if (ob_get_level()) {
    ob_end_clean();
}

// Read the file and output its contents to the browser
readfile($full_path);
exit;
?>