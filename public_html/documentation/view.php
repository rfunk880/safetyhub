<?php
// /public_html/documentation/view.php
// Document Viewer with Custom PDF Renderer

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include core configuration
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../config/documentation.php';
require_once __DIR__ . '/../../src/documentation.php';

// Check if user is logged in
if (!isUserLoggedIn()) {
    header('Location: /login.php');
    exit;
}

if (!canViewDocumentation()) {
    header('Location: /dashboard.php?error=' . urlencode('Access denied. You do not have permission to access the Documentation module.'));
    exit;
}

// Get document ID from URL
$document_id = intval($_GET['id'] ?? 0);
if ($document_id <= 0) {
    header('Location: /documentation/index.php?error=' . urlencode('Invalid document ID.'));
    exit;
}

// Get document details
$document = getDocumentById($document_id, $conn);
if (!$document) {
    header('Location: /documentation/index.php?error=' . urlencode('Document not found or you do not have permission to view it.'));
    exit;
}

// Check document visibility permissions
if (!canUserViewDocument($document, $_SESSION['user_role_id'])) {
    header('Location: /documentation/index.php?error=' . urlencode('You do not have permission to view this document.'));
    exit;
}

// Handle file serving
if (isset($_GET['serve_file'])) {
    $serve_document_id = intval($_GET['revision'] ?? $document_id);
    $serve_document = getDocumentById($serve_document_id, $conn);
    
    if (!$serve_document) {
        http_response_code(404);
        echo "Document not found.";
        exit;
    }
    
    if (empty($serve_document['file_path'])) {
        http_response_code(404);
        echo "File path not found in document record.";
        exit;
    }
    
    // Get file extension from original filename
    $file_extension = strtolower(pathinfo($serve_document['original_filename'], PATHINFO_EXTENSION));
    
    // Construct full file path - ensure proper path construction
    $base_path = rtrim(DOCUMENTATION_UPLOAD_DIR, '/') . '/' . ltrim($serve_document['file_path'], '/');
    
    // Try different file path variations
    $possible_paths = [
        $base_path, // Original path as stored
        $base_path . '.' . $file_extension, // Add extension if missing
        DOCUMENTATION_UPLOAD_DIR . $serve_document['file_path'],
        DOCUMENTATION_UPLOAD_DIR . $serve_document['file_path'] . '.' . $file_extension
    ];
    
    $found_path = null;
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            $found_path = $path;
            break;
        }
    }
    
    if (!$found_path) {
        // Look for files that start with the same name
        $upload_dir = DOCUMENTATION_UPLOAD_DIR;
        if (is_dir($upload_dir)) {
            $files_in_dir = scandir($upload_dir);
            $actual_files = array_filter($files_in_dir, function($file) use ($upload_dir) {
                return is_file($upload_dir . $file) && !in_array($file, ['.', '..']);
            });
            
            // Look for files that start with the same name
            $matching_files = array_filter($actual_files, function($file) use ($serve_document) {
                return strpos($file, $serve_document['file_path']) === 0;
            });
            
            if (!empty($matching_files)) {
                $found_path = $upload_dir . reset($matching_files);
            }
        }
    }
    
    if (!$found_path) {
        http_response_code(404);
        echo "File not found.";
        exit;
    }
    
    // Set headers for file serving
    $file_size = filesize($found_path);
    
    header('Content-Length: ' . $file_size);
    header('Content-Type: ' . getMimeType($file_extension));
    header('Cache-Control: private, max-age=3600');
    
    // For PDF files in browser
    if ($file_extension === 'pdf') {
        header('Content-Disposition: inline; filename="' . $serve_document['original_filename'] . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . $serve_document['original_filename'] . '"');
    }
    
    readfile($found_path);
    exit;
}

// Get document addendums and revisions
$addendums = getDocumentAddendums($document_id, $conn);
$revisions = getDocumentRevisions($document_id, $conn);

// Handle revision navigation
$current_revision_id = intval($_GET['revision'] ?? 0);
if ($current_revision_id && $current_revision_id !== $document_id) {
    // User is viewing a specific revision
    $current_document = getDocumentById($current_revision_id, $conn);
    if (!$current_document || $current_document['parent_document_id'] !== $document_id) {
        // Invalid revision, redirect to main document
        header('Location: view.php?id=' . $document_id);
        exit;
    }
} else {
    // Viewing the main document
    $current_document = $document;
    $current_revision_id = $document_id;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Toggle favorite
    if ($action === 'toggle_favorite') {
        $result = toggleDocumentFavorite($document_id, $_SESSION['user_id'], $conn);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $result !== false,
            'action' => $result,
            'message' => $result === 'added' ? 'Added to favorites' : 'Removed from favorites'
        ]);
        exit;
    }
    
    // Admin actions
    if (hasDocAdminAccess()) {
        if ($action === 'toggle_pin') {
            $result = toggleDocumentPin($document_id, $conn);
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Pin status updated' : 'Failed to update pin status'
            ]);
            exit;
        }
        
        if ($action === 'archive') {
            $result = archiveDocument($document_id, $conn);
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Document archived successfully' : 'Failed to archive document',
                'redirect' => $result ? '/documentation/index.php?message=' . urlencode('Document archived successfully.') : null
            ]);
            exit;
        }
        
        if ($action === 'unarchive') {
            $result = unarchiveDocument($document_id, $conn);
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Document unarchived successfully' : 'Failed to unarchive document',
                'redirect' => $result ? '/documentation/index.php?message=' . urlencode('Document unarchived successfully.') : null
            ]);
            exit;
        }
        
        if ($action === 'delete' && $_SESSION['user_role_id'] == 1) { // Super Admin only
            $result = deleteDocument($document_id, $conn);
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Document deleted permanently' : 'Failed to delete document',
                'redirect' => $result ? '/documentation/index.php?message=' . urlencode('Document deleted permanently.') : null
            ]);
            exit;
        }
    }
}

// Check if user has favorited this document
$stmt = $conn->prepare("SELECT id FROM document_favorites WHERE document_id = ? AND user_id = ?");
$stmt->bind_param("ii", $document_id, $_SESSION['user_id']);
$stmt->execute();
$is_favorited = $stmt->get_result()->num_rows > 0;
$stmt->close();

// Get user info
$user_name = $_SESSION['user_first_name'] . ' ' . $_SESSION['user_last_name'];
$is_admin = hasDocAdminAccess();

// Parse file extension for display
$file_extension = strtolower(pathinfo($current_document['original_filename'], PATHINFO_EXTENSION));
$is_pdf = $file_extension === 'pdf';
$can_download = $current_document['access_type'] === 'downloadable';
$can_embed = in_array($current_document['access_type'], ['searchable', 'downloadable']);

// Check if current user has admin privileges for unrestricted download/print access
$user_has_admin_access = hasDocAdminAccess();

// For non-admin users, restrict download capabilities even for 'downloadable' documents
// Admins always have full access regardless of document access_type
if (!$user_has_admin_access) {
    // Non-admin users follow the document's access_type restrictions
    $can_download_for_user = $current_document['access_type'] === 'downloadable';
    $can_print_for_user = $current_document['access_type'] === 'downloadable';
} else {
    // Admin users can always download and print
    $can_download_for_user = true;
    $can_print_for_user = true;
}

// Split tags
$document_tags = !empty($document['tags']) ? explode(',', $document['tags']) : [];

// Helper function to get MIME type
function getMimeType($extension) {
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain'
    ];
    
    return $mimeTypes[$extension] ?? 'application/octet-stream';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($document['title']); ?> - Safety Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@0.378.0/dist/umd/lucide.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- PDF.js for Custom Renderer -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    
    <style>
        body { font-family: 'Inter', sans-serif; }
        .sidebar { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) { 
            .sidebar { transform: translateX(-100%); } 
            .sidebar.open { transform: translateX(0); } 
        }
        
        /* Custom PDF Viewer Styles */
        .pdf-canvas {
            cursor: grab;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }
        
        .pdf-canvas:active {
            cursor: grabbing;
        }
        
        .pdf-controls {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
        }
        
        .loading-spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* Disable text selection and context menu for protected PDFs */
        .pdf-protected {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
            -webkit-tap-highlight-color: transparent;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen">
        
        <!-- Include Navigation -->
        <?php 
        require_once __DIR__ . '/../../includes/navigation.php';
        renderNavigation(); 
        ?>
        
        <!-- Main Content Area -->
        <main class="flex-1 overflow-auto">
            <div class="bg-white">
                
                <!-- Document Header -->
                <div class="border-b border-gray-200 px-6 py-4">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex-1 min-w-0">
                            <h1 class="text-2xl font-bold text-gray-900 truncate">
                                <?php echo htmlspecialchars($document['title']); ?>
                                <?php if ($document['archived_date']): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 ml-2">
                                    Archived
                                </span>
                                <?php endif; ?>
                            </h1>
                            <div class="flex items-center text-sm text-gray-500 mt-1">
                                <i data-lucide="calendar" class="w-4 h-4 mr-1"></i>
                                <span class="mr-4">Modified: <?php echo date('M j, Y', strtotime($document['date_modified'])); ?></span>
                                <i data-lucide="upload" class="w-4 h-4 mr-1"></i>
                                <span class="mr-4">Uploaded: <?php echo date('M j, Y', strtotime($document['upload_date'])); ?></span>
                                <i data-lucide="user" class="w-4 h-4 mr-1"></i>
                                <span>By: <?php echo htmlspecialchars($document['uploader_name'] ?? 'Unknown'); ?></span>
                                <i data-lucide="file" class="w-4 h-4 mr-1 ml-4"></i>
                                <span><?php echo formatDocFileSize($document['file_size']); ?></span>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3">
                            <!-- Back Button -->
                            <a href="/documentation/index.php" 
                               class="flex items-center px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                                <span class="hidden sm:inline">Back</span>
                            </a>
                            
                            <!-- Favorite Button -->
                            <button onclick="toggleFavorite()" 
                                    class="flex items-center px-3 py-2 <?php echo $is_favorited ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-600'; ?> rounded-lg hover:bg-yellow-200 transition-colors">
                                <i data-lucide="star" class="w-4 h-4 mr-2 <?php echo $is_favorited ? 'fill-current' : ''; ?>"></i>
                                <span class="hidden sm:inline"><?php echo $is_favorited ? 'Favorited' : 'Add to Favorites'; ?></span>
                            </button>
                            
                            <!-- Download Button -->
                            <?php if ($can_download_for_user): ?>
                            <a href="?id=<?php echo $document_id; ?>&revision=<?php echo $current_revision_id; ?>&serve_file=1" target="_blank"
                               class="flex items-center px-3 py-2 bg-green-100 text-green-800 rounded-lg hover:bg-green-200 transition-colors">
                                <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                                <span class="hidden sm:inline">Download</span>
                            </a>
                            <?php else: ?>
                            <!-- Restricted Download Message for Non-Admins -->
                            <div class="flex items-center px-3 py-2 bg-gray-100 text-gray-600 rounded-lg cursor-not-allowed" title="Download restricted - Contact administrator">
                                <i data-lucide="lock" class="w-4 h-4 mr-2"></i>
                                <span class="hidden sm:inline">Restricted</span>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Admin Actions -->
                            <?php if ($is_admin): ?>
                            <div class="relative" id="adminMenu">
                                <button onclick="toggleAdminMenu()" 
                                        class="flex items-center px-3 py-2 bg-blue-100 text-blue-800 rounded-lg hover:bg-blue-200 transition-colors">
                                    <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                                    <span class="hidden sm:inline">Admin</span>
                                    <i data-lucide="chevron-down" class="w-4 h-4 ml-1"></i>
                                </button>
                                <div id="adminDropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border z-10">
                                    <button onclick="togglePin()" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center">
                                        <i data-lucide="pin" class="w-4 h-4 mr-2"></i>
                                        <?php echo $document['is_pinned'] ? 'Unpin Document' : 'Pin Document'; ?>
                                    </button>
                                    <button onclick="editDocument()" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center">
                                        <i data-lucide="edit" class="w-4 h-4 mr-2"></i>
                                        Edit Document
                                    </button>
                                    <button onclick="addRevision()" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center">
                                        <i data-lucide="git-branch" class="w-4 h-4 mr-2"></i>
                                        Add Revision
                                    </button>
                                    <button onclick="addAddendum()" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center">
                                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                                        Add Addendum
                                    </button>
                                    <hr class="my-1">
                                    <?php if ($document['archived_date']): ?>
                                    <button onclick="unarchiveDocument()" class="w-full text-left px-4 py-2 text-sm text-green-600 hover:bg-green-50 flex items-center">
                                        <i data-lucide="archive-restore" class="w-4 h-4 mr-2"></i>
                                        Unarchive Document
                                    </button>
                                    <?php else: ?>
                                    <button onclick="archiveDocument()" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 flex items-center">
                                        <i data-lucide="archive" class="w-4 h-4 mr-2"></i>
                                        Archive Document
                                    </button>
                                    <?php endif; ?>
                                    
                                    <!-- Delete option - Super Admin only -->
                                    <?php if ($_SESSION['user_role_id'] == 1): ?>
                                    <hr class="my-1">
                                    <button onclick="deleteDocument()" class="w-full text-left px-4 py-2 text-sm text-red-700 hover:bg-red-100 flex items-center">
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
                                        Delete Document
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Tags -->
                    <?php if (!empty($document_tags)): ?>
                    <div class="flex flex-wrap gap-2 mb-4">
                        <?php foreach ($document_tags as $tag): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <?php echo htmlspecialchars(trim($tag)); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Revision Selector -->
                    <?php if (!empty($revisions)): ?>
                    <div class="flex items-center text-sm text-gray-600">
                        <label for="revision-select" class="mr-2">Viewing:</label>
                        <select id="revision-select" onchange="changeRevision(this.value)" 
                                class="border border-gray-300 rounded px-2 py-1 text-sm">
                            <option value="<?php echo $document_id; ?>" <?php echo $current_revision_id === $document_id ? 'selected' : ''; ?>>
                                Original Document
                            </option>
                            <?php foreach ($revisions as $revision): ?>
                            <option value="<?php echo $revision['id']; ?>" <?php echo $current_revision_id === $revision['id'] ? 'selected' : ''; ?>>
                                Revision <?php echo date('M j, Y', strtotime($revision['upload_date'])); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Document Content -->
                <div class="document-viewer">
                    <?php if ($current_document['access_type'] === 'view_only' && !$is_pdf): ?>
                    <!-- View Only - Non-PDF -->
                    <div class="p-8 text-center">
                        <i data-lucide="eye-off" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Preview Not Available</h3>
                        <p class="text-gray-600 mb-4">This document is set to view-only mode and cannot be previewed online.</p>
                        <p class="text-sm text-gray-500">File: <?php echo htmlspecialchars($current_document['original_filename']); ?></p>
                    </div>
                    
                    <?php elseif ($is_pdf): ?>
                    <!-- Custom PDF Renderer with Fallback -->
                    <div class="w-full bg-gray-100">
                        <div id="pdf-controls" class="pdf-controls flex items-center justify-between p-4 bg-white border-b border-gray-200 sticky top-0 z-10">
                            <div class="flex items-center space-x-4">
                                <button id="prev-page" class="px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                                </button>
                                <span id="page-info" class="text-sm text-gray-600 min-w-[100px] text-center">Page 1 of 1</span>
                                <button id="next-page" class="px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                </button>
                            </div>
                            <div class="flex items-center space-x-2">
                                <button id="zoom-out" class="px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">
                                    <i data-lucide="zoom-out" class="w-4 h-4"></i>
                                </button>
                                <span id="zoom-level" class="text-sm text-gray-600 min-w-[60px] text-center">100%</span>
                                <button id="zoom-in" class="px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">
                                    <i data-lucide="zoom-in" class="w-4 h-4"></i>
                                </button>
                                <button id="fit-width" class="px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 ml-2">
                                    <i data-lucide="maximize" class="w-4 h-4"></i>
                                </button>
                                <button id="toggle-fullscreen" class="px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 ml-2">
                                    <i data-lucide="expand" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>
                        <div id="pdf-container" class="p-4 overflow-auto <?php echo !$can_download_for_user ? 'pdf-protected' : ''; ?>" style="height: calc(100vh - 300px);">
                            <div class="flex items-center justify-center h-full">
                                <div class="text-center">
                                    <div class="loading-spinner rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
                                    <p class="text-gray-600">Loading PDF...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php else: ?>
                    <!-- Other Document Types -->
                    <div class="p-8 text-center">
                        <div class="w-20 h-20 bg-blue-100 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <i data-lucide="<?php echo getDocFileIcon($current_document['original_filename']); ?>" class="w-10 h-10 text-blue-600"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo htmlspecialchars($current_document['title']); ?></h3>
                        <p class="text-gray-600 mb-4">
                            <?php echo htmlspecialchars($current_document['original_filename']); ?> 
                            (<?php echo formatDocFileSize($current_document['file_size']); ?>)
                        </p>
                        <p class="text-sm text-gray-500 mb-6">
                            This file type cannot be previewed online. Please download to view.
                        </p>
                        <?php if ($can_download_for_user): ?>
                        <a href="?id=<?php echo $document_id; ?>&revision=<?php echo $current_revision_id; ?>&serve_file=1" 
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                            Download File
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Description and Addendums -->
            <?php if (!empty($document['description']) || !empty($addendums)): ?>
            <div class="bg-white border-t border-gray-200">
                <div class="px-6 py-4">
                    
                    <!-- Description -->
                    <?php if (!empty($document['description'])): ?>
                    <div class="mb-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Description</h3>
                        <div class="text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($document['description']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Addendums -->
                    <div class="border-t border-gray-200 pt-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-medium text-gray-900">
                                Addendums (<?php echo count($addendums); ?>)
                            </h3>
                            <?php if ($is_admin): ?>
                            <button onclick="addAddendum()" class="inline-flex items-center px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                                Add Addendum
                            </button>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (empty($addendums)): ?>
                        <div class="text-center py-8">
                            <i data-lucide="file-plus" class="w-12 h-12 text-gray-300 mx-auto mb-4"></i>
                            <p class="text-gray-600">No addendums available for this document.</p>
                        </div>
                        <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($addendums as $addendum): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($addendum['title']); ?></h4>
                                        <div class="text-sm text-gray-500 mt-1">
                                            Added <?php echo date('M j, Y', strtotime($addendum['upload_date'])); ?>
                                            by <?php echo htmlspecialchars($addendum['firstName'] . ' ' . $addendum['lastName']); ?>
                                        </div>
                                    </div>
                                    <a href="view.php?id=<?php echo $addendum['id']; ?>" 
                                       class="inline-flex items-center px-3 py-2 bg-blue-100 text-blue-800 rounded-lg hover:bg-blue-200 text-sm">
                                        <i data-lucide="eye" class="w-4 h-4 mr-2"></i>
                                        View
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();
        
        // Mobile sidebar toggle
        document.getElementById('menu-button')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('open');
        });
        
        // Toggle favorite
        async function toggleFavorite() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=toggle_favorite'
                });
                
                const result = await response.json();
                if (result.success) {
                    location.reload(); // Reload to update favorite status
                }
            } catch (error) {
                console.error('Error toggling favorite:', error);
            }
        }
        
        // Admin menu toggle
        function toggleAdminMenu() {
            const dropdown = document.getElementById('adminDropdown');
            dropdown.classList.toggle('hidden');
        }
        
        // Close admin menu when clicking outside
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('adminMenu');
            if (!menu.contains(event.target)) {
                document.getElementById('adminDropdown').classList.add('hidden');
            }
        });
        
        // Toggle pin
        async function togglePin() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=toggle_pin'
                });
                
                const result = await response.json();
                if (result.success) {
                    location.reload(); // Reload to update pin status
                }
            } catch (error) {
                console.error('Error toggling pin:', error);
            }
        }
        
        // Archive document
        async function archiveDocument() {
            if (confirm('Are you sure you want to archive this document? This action can be undone later.')) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=archive'
                    });
                    
                    const result = await response.json();
                    if (result.success && result.redirect) {
                        window.location.href = result.redirect;
                    } else {
                        alert(result.message || 'Failed to archive document');
                    }
                } catch (error) {
                    console.error('Error archiving document:', error);
                    alert('Failed to archive document');
                }
            }
        }
        
        // Unarchive document
        async function unarchiveDocument() {
            if (confirm('Are you sure you want to unarchive this document? It will be visible to users again.')) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=unarchive'
                    });
                    
                    const result = await response.json();
                    if (result.success && result.redirect) {
                        window.location.href = result.redirect;
                    } else {
                        alert(result.message || 'Failed to unarchive document');
                    }
                } catch (error) {
                    console.error('Error unarchiving document:', error);
                    alert('Failed to unarchive document');
                }
            }
        }
        
        // Delete document permanently (Super Admin only)
        async function deleteDocument() {
            if (confirm('Are you sure you want to PERMANENTLY delete this document? This action cannot be undone and will remove the document and its file from the system.')) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=delete'
                    });
                    
                    const result = await response.json();
                    if (result.success && result.redirect) {
                        window.location.href = result.redirect;
                    } else {
                        alert(result.message || 'Failed to delete document');
                    }
                } catch (error) {
                    console.error('Error deleting document:', error);
                    alert('Failed to delete document');
                }
            }
        }
        
        // Navigation functions
        function editDocument() {
            window.location.href = 'edit.php?id=<?php echo $document_id; ?>';
        }
        
        function addRevision() {
            window.location.href = 'add_revision.php?id=<?php echo $document_id; ?>';
        }
        
        function addAddendum() {
            window.location.href = 'add_addendum.php?id=<?php echo $document_id; ?>';
        }
        
        // Revision change handler
        function changeRevision(revisionId) {
            if (revisionId == <?php echo $document_id; ?>) {
                window.location.href = 'view.php?id=<?php echo $document_id; ?>';
            } else {
                window.location.href = 'view.php?id=<?php echo $document_id; ?>&revision=' + revisionId;
            }
        }

        // Robust Custom PDF Viewer Class
        class CustomPDFViewer {
            constructor() {
                this.pdf = null;
                this.currentPage = 1;
                this.totalPages = 1;
                this.scale = 1.0;
                this.container = document.getElementById('pdf-container');
                this.canvas = null;
                this.isProtected = <?php echo !$can_download_for_user ? 'true' : 'false'; ?>;
                this.isFullscreen = false;
                this.init();
            }
            
            async init() {
                try {
                    // Multiple PDF.js CDN attempts for reliability
                    await this.loadPDFJS();
                    
                    // Set PDF.js worker
                    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                    
                    // Load PDF with retry mechanism
                    await this.loadPDF();
                    
                    this.setupControls();
                    this.renderPage(1);
                    
                } catch (error) {
                    console.error('Error loading PDF:', error);
                    this.showError(error.message);
                }
            }
            
            async loadPDFJS() {
                // Check if PDF.js is already loaded
                if (typeof pdfjsLib !== 'undefined') {
                    return Promise.resolve();
                }
                
                // Try to load PDF.js from CDN
                return new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
                    script.onload = () => resolve();
                    script.onerror = () => {
                        // Try backup CDN
                        const backupScript = document.createElement('script');
                        backupScript.src = 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js';
                        backupScript.onload = () => resolve();
                        backupScript.onerror = () => reject(new Error('Failed to load PDF.js library'));
                        document.head.appendChild(backupScript);
                    };
                    document.head.appendChild(script);
                });
            }
            
            async loadPDF() {
                const maxRetries = 3;
                let lastError;
                
                for (let attempt = 1; attempt <= maxRetries; attempt++) {
                    try {
                        console.log(`PDF load attempt ${attempt}/${maxRetries}`);
                        
                        const response = await fetch('?id=<?php echo $document_id; ?>&revision=<?php echo $current_revision_id; ?>&serve_file=1');
                        
                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                        }
                        
                        const arrayBuffer = await response.arrayBuffer();
                        
                        if (arrayBuffer.byteLength === 0) {
                            throw new Error('Empty PDF file received');
                        }
                        
                        this.pdf = await pdfjsLib.getDocument({
                            data: arrayBuffer,
                            verbosity: 0 // Reduce console spam
                        }).promise;
                        
                        this.totalPages = this.pdf.numPages;
                        console.log(`PDF loaded successfully: ${this.totalPages} pages`);
                        return;
                        
                    } catch (error) {
                        lastError = error;
                        console.error(`PDF load attempt ${attempt} failed:`, error);
                        
                        if (attempt < maxRetries) {
                            // Wait before retry
                            await new Promise(resolve => setTimeout(resolve, 1000 * attempt));
                        }
                    }
                }
                
                throw lastError || new Error('Failed to load PDF after multiple attempts');
            }
            
            setupControls() {
                const prevBtn = document.getElementById('prev-page');
                const nextBtn = document.getElementById('next-page');
                const zoomInBtn = document.getElementById('zoom-in');
                const zoomOutBtn = document.getElementById('zoom-out');
                const fitWidthBtn = document.getElementById('fit-width');
                const fullscreenBtn = document.getElementById('toggle-fullscreen');
                
                prevBtn.addEventListener('click', () => this.previousPage());
                nextBtn.addEventListener('click', () => this.nextPage());
                zoomInBtn.addEventListener('click', () => this.zoomIn());
                zoomOutBtn.addEventListener('click', () => this.zoomOut());
                fitWidthBtn.addEventListener('click', () => this.fitToWidth());
                fullscreenBtn.addEventListener('click', () => this.toggleFullscreen());
                
                // Keyboard shortcuts
                document.addEventListener('keydown', (e) => {
                    if (e.target.tagName.toLowerCase() === 'input') return;
                    
                    switch(e.key) {
                        case 'ArrowLeft':
                        case 'PageUp':
                            e.preventDefault();
                            this.previousPage();
                            break;
                        case 'ArrowRight':
                        case 'PageDown':
                        case ' ': // Spacebar
                            e.preventDefault();
                            this.nextPage();
                            break;
                        case '+':
                        case '=':
                            e.preventDefault();
                            this.zoomIn();
                            break;
                        case '-':
                            e.preventDefault();
                            this.zoomOut();
                            break;
                        case 'f':
                        case 'F':
                            if (e.ctrlKey || e.metaKey) return; // Don't interfere with browser search
                            e.preventDefault();
                            this.toggleFullscreen();
                            break;
                        case 'Escape':
                            if (this.isFullscreen) {
                                e.preventDefault();
                                this.toggleFullscreen();
                            }
                            break;
                    }
                });
                
                // Mouse wheel zoom (with Ctrl key)
                this.container.addEventListener('wheel', (e) => {
                    if (e.ctrlKey || e.metaKey) {
                        e.preventDefault();
                        if (e.deltaY < 0) {
                            this.zoomIn();
                        } else {
                            this.zoomOut();
                        }
                    }
                });
                
                this.updateControls();
            }
            
            async renderPage(pageNum) {
                try {
                    const page = await this.pdf.getPage(pageNum);
                    const viewport = page.getViewport({ scale: this.scale });
                    
                    // Create or reuse canvas
                    if (!this.canvas) {
                        this.canvas = document.createElement('canvas');
                        this.canvas.className = 'pdf-canvas mx-auto shadow-lg border border-gray-200 rounded';
                        
                        // Apply protection if needed
                        if (this.isProtected) {
                            this.canvas.oncontextmenu = () => false;
                            this.canvas.onselectstart = () => false;
                            this.canvas.ondragstart = () => false;
                            this.canvas.style.userSelect = 'none';
                            this.canvas.style.webkitUserSelect = 'none';
                            this.canvas.style.mozUserSelect = 'none';
                            this.canvas.style.msUserSelect = 'none';
                        }
                    }
                    
                    const context = this.canvas.getContext('2d');
                    
                    // Set canvas size
                    this.canvas.height = viewport.height;
                    this.canvas.width = viewport.width;
                    
                    // Clear canvas
                    context.clearRect(0, 0, this.canvas.width, this.canvas.height);
                    
                    // Render PDF page into canvas
                    const renderContext = {
                        canvasContext: context,
                        viewport: viewport
                    };
                    
                    await page.render(renderContext).promise;
                    
                    // Update container
                    this.container.innerHTML = '';
                    this.container.appendChild(this.canvas);
                    
                    this.currentPage = pageNum;
                    this.updateControls();
                    
                } catch (error) {
                    console.error('Error rendering page:', error);
                    this.showError('Failed to render PDF page: ' + error.message);
                }
            }
            
            updateControls() {
                document.getElementById('page-info').textContent = `Page ${this.currentPage} of ${this.totalPages}`;
                document.getElementById('prev-page').disabled = this.currentPage <= 1;
                document.getElementById('next-page').disabled = this.currentPage >= this.totalPages;
                document.getElementById('zoom-level').textContent = Math.round(this.scale * 100) + '%';
                
                // Update fullscreen icon
                const fullscreenIcon = document.querySelector('#toggle-fullscreen i');
                if (fullscreenIcon) {
                    fullscreenIcon.setAttribute('data-lucide', this.isFullscreen ? 'minimize' : 'expand');
                    lucide.createIcons();
                }
            }
            
            previousPage() {
                if (this.currentPage > 1) {
                    this.renderPage(this.currentPage - 1);
                }
            }
            
            nextPage() {
                if (this.currentPage < this.totalPages) {
                    this.renderPage(this.currentPage + 1);
                }
            }
            
            zoomIn() {
                if (this.scale < 3.0) {
                    this.scale += 0.25;
                    this.renderPage(this.currentPage);
                }
            }
            
            zoomOut() {
                if (this.scale > 0.5) {
                    this.scale -= 0.25;
                    this.renderPage(this.currentPage);
                }
            }
            
            fitToWidth() {
                const containerWidth = this.container.clientWidth - 32; // Account for padding
                if (this.canvas && this.pdf) {
                    this.pdf.getPage(this.currentPage).then(page => {
                        const viewport = page.getViewport({ scale: 1.0 });
                        this.scale = containerWidth / viewport.width;
                        this.renderPage(this.currentPage);
                    });
                }
            }
            
            toggleFullscreen() {
                const viewer = document.querySelector('.document-viewer').parentElement;
                
                if (!this.isFullscreen) {
                    // Enter fullscreen
                    if (viewer.requestFullscreen) {
                        viewer.requestFullscreen();
                    } else if (viewer.webkitRequestFullscreen) {
                        viewer.webkitRequestFullscreen();
                    } else if (viewer.mozRequestFullScreen) {
                        viewer.mozRequestFullScreen();
                    } else if (viewer.msRequestFullscreen) {
                        viewer.msRequestFullscreen();
                    }
                    this.isFullscreen = true;
                } else {
                    // Exit fullscreen
                    if (document.exitFullscreen) {
                        document.exitFullscreen();
                    } else if (document.webkitExitFullscreen) {
                        document.webkitExitFullscreen();
                    } else if (document.mozCancelFullScreen) {
                        document.mozCancelFullScreen();
                    } else if (document.msExitFullscreen) {
                        document.msExitFullscreen();
                    }
                    this.isFullscreen = false;
                }
                
                this.updateControls();
            }
            
            showError(message = 'Unknown error occurred') {
                this.container.innerHTML = `
                    <div class="text-center py-8">
                        <i data-lucide="alert-triangle" class="w-12 h-12 text-red-500 mx-auto mb-4"></i>
                        <h3 class="text-lg font-medium text-red-900 mb-2">PDF Viewer Error</h3>
                        <p class="text-red-700 mb-4">${message}</p>
                        <div class="space-y-2">
                            <button onclick="location.reload()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 mr-2">
                                <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                                Retry
                            </button>
                            <?php if ($can_download_for_user): ?>
                            <a href="?id=<?php echo $document_id; ?>&revision=<?php echo $current_revision_id; ?>&serve_file=1" target="_blank"
                               class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                                <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                                Download PDF Instead
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                `;
                lucide.createIcons();
            }
        }

        // Initialize the custom PDF viewer for PDF documents
        <?php if ($is_pdf): ?>
        document.addEventListener('DOMContentLoaded', function() {
            new CustomPDFViewer();
        });
        <?php endif; ?>
    </script>
</body>
</html>