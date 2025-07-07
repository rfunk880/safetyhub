<?php
// /config/documentation.php
// Documentation module configuration - separate modular config file
// This file should be included in main pages that need documentation functionality
// ONLY contains configuration, constants, and simple helper functions

// --- DOCUMENTATION MODULE CONFIGURATION ---
// Only define if not already defined
if (!defined('DOCUMENTATION_MODULE_ENABLED')) {
    define('DOCUMENTATION_MODULE_ENABLED', true);
}

// Documentation module paths
define('DOCUMENTATION_UPLOAD_DIR', __DIR__ . '/../uploads/safety_documents/');
define('DOCUMENTATION_UPLOAD_URL', '/uploads/safety_documents/');

// Ensure upload directory exists
if (!file_exists(DOCUMENTATION_UPLOAD_DIR)) {
    mkdir(DOCUMENTATION_UPLOAD_DIR, 0755, true);
}

// Role permissions for documentation module - using existing SafetyHub role IDs
define('DOCUMENTATION_ADMIN_ROLES', [1, 2, 3]); // Super Admin, Admin, Manager
define('DOCUMENTATION_USER_ROLES', [4, 5, 6]); // Supervisor, Employee, Subcontractor
define('DOCUMENTATION_ALL_ROLES', [1, 2, 3, 4, 5, 6]); // All users can view

// Documentation module constants
define('DOC_MAX_FILE_SIZE', 52428800); // 50MB (same as SMS config)
define('DOC_ALLOWED_FILE_TYPES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain'
]);

define('DOC_ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt']);

// Document categories - predefined tags
define('DOC_CATEGORIES', [
    'manual' => 'Safety Manuals',
    'procedure' => 'Safety Procedures',
    'policy' => 'Safety Policies',
    'sds' => 'Safety Data Sheets',
    'report' => 'Safety Reports',
    'training' => 'Training Materials',
    'emergency' => 'Emergency Procedures',
    'compliance' => 'Compliance Documents',
    'forms' => 'Forms & Checklists'
]);

// Document access types
define('DOC_ACCESS_TYPES', [
    'view_only' => 'View Only (Embedded)',
    'searchable' => 'Searchable (Embedded)',
    'downloadable' => 'Downloadable'
]);

// Document visibility by role
define('DOC_ROLE_VISIBILITY', [
    'all' => 'All Users',
    'employees_only' => 'Employees Only',
    'supervisors_plus' => 'Supervisors and Above',
    'managers_only' => 'Managers Only',
    'admins_only' => 'Administrators Only'
]);

// --- SIMPLE HELPER FUNCTIONS (Config Level) ---
// Only simple, stateless functions that don't require database access

/**
 * Check if current user has admin access to documentation module
 */
function hasDocAdminAccess() {
    if (!isset($_SESSION['user_role_id'])) {
        return false;
    }
    
    return in_array($_SESSION['user_role_id'], DOCUMENTATION_ADMIN_ROLES);
}

/**
 * Check if current user can view documentation
 */
function canViewDocumentation() {
    if (!isset($_SESSION['user_role_id'])) {
        return false;
    }
    
    return in_array($_SESSION['user_role_id'], DOCUMENTATION_ALL_ROLES);
}

/**
 * Require admin access for documentation module
 */
function requireDocAdminAccess() {
    if (!hasDocAdminAccess()) {
        header('Location: /dashboard.php?error=' . urlencode('Access denied. You need manager or admin privileges to access the Documentation module.'));
        exit;
    }
}

/**
 * Get allowed file extensions as string for JavaScript
 */
function getDocAllowedExtensionsString() {
    return implode(',', DOC_ALLOWED_EXTENSIONS);
}

/**
 * Check if file extension is allowed
 */
function isDocFileExtensionAllowed($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, DOC_ALLOWED_EXTENSIONS);
}

/**
 * Get file icon based on extension
 */
function getDocFileIcon($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    switch ($extension) {
        case 'pdf':
            return 'file-text';
        case 'doc':
        case 'docx':
            return 'file-text';
        case 'xls':
        case 'xlsx':
            return 'sheet';
        case 'ppt':
        case 'pptx':
            return 'presentation';
        default:
            return 'file';
    }
}

/**
 * Format file size for display
 */
function formatDocFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

?>