<?php
// /public_html/documentation/view.php
// Document Viewer Feature - View Individual Documents with Revisions
// Enhanced with Production-Ready PDF.js Integration

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

// Get document ID
$document_id = intval($_GET['id'] ?? 0);
if (!$document_id) {
    header('Location: /documentation/index.php?error=' . urlencode('Invalid document ID.'));
    exit;
}

// Get document details
$document = getDocumentById($document_id, $conn);
if (!$document) {
    header('Location: /documentation/index.php?error=' . urlencode('Document not found.'));
    exit;
}

// Allow admins to view archived documents, but redirect regular users
if ($document['archived_date'] && !hasDocAdminAccess()) {
    header('Location: /documentation/index.php?error=' . urlencode('Document not found.'));
    exit;
}

// Check if user has permission to view this document based on visibility
$user_role_id = $_SESSION['user_role_id'];
$can_view = false;

switch ($document['visibility']) {
    case 'all':
        $can_view = true;
        break;
    case 'employees_only':
        $can_view = in_array($user_role_id, [1, 2, 3, 4, 5]); // All except subcontractors
        break;
    case 'supervisors_plus':
        $can_view = in_array($user_role_id, [1, 2, 3, 4]); // Supervisors and above
        break;
    case 'managers_only':
        $can_view = in_array($user_role_id, [1, 2, 3]); // Managers and above
        break;
    case 'admins_only':
        $can_view = in_array($user_role_id, [1, 2]); // Admins only
        break;
}

if (!$can_view) {
    header('Location: /documentation/index.php?error=' . urlencode('You do not have permission to view this document.'));
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
                'message' => $result ? 'Document unarchived successfully' : 'Failed to unarchive document'
            ]);
            exit;
        }
        
        if ($action === 'add_revision') {
            $revision_name = trim($_POST['revision_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $date_modified = $_POST['date_modified'] ?? date('Y-m-d');
            $uploaded_by = $_SESSION['user_id'];
            
            if (empty($revision_name)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Revision name is required.']);
                exit;
            }
            
            if (!isset($_FILES['revision_file']) || $_FILES['revision_file']['error'] !== UPLOAD_ERR_OK) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Please select a file for the revision.']);
                exit;
            }
            
            $file = $_FILES['revision_file'];
            
            // Validate file
            if ($file['size'] > DOC_MAX_FILE_SIZE) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'File is too large. Maximum size is ' . formatDocFileSize(DOC_MAX_FILE_SIZE) . '.']);
                exit;
            }
            
            if (!isDocFileExtensionAllowed($file['name'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'File type not allowed.']);
                exit;
            }
            
            // Add revision
            $revision_id = addDocumentRevision(
                $document_id,
                $revision_name,
                $description,
                $document['tags'], // Inherit tags from parent
                $document['access_type'], // Inherit access type
                $document['visibility'], // Inherit visibility
                $date_modified,
                $file,
                $uploaded_by,
                $conn
            );
            
            if ($revision_id) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Revision added successfully!',
                    'redirect' => 'view.php?id=' . $document_id . '&revision=' . $revision_id
                ]);
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Failed to add revision.']);
            }
            exit;
        }
    }
}

// Handle file serving
if (isset($_GET['serve_file'])) {
    $serve_document_id = $current_revision_id;
    $serve_document = getDocumentById($serve_document_id, $conn);
    
    if (!$serve_document) {
        http_response_code(404);
        exit('Document not found');
    }
    
    $file_path = DOCUMENTATION_UPLOAD_DIR . $serve_document['file_path'];
    
    // Debug: Log file path for troubleshooting
    error_log("Trying to serve file: " . $file_path);
    error_log("Upload directory: " . DOCUMENTATION_UPLOAD_DIR);
    error_log("File path from DB: " . $serve_document['file_path']);
    
    if (!file_exists($file_path)) {
        // Try with file extension added
        $extension = strtolower(pathinfo($serve_document['original_filename'], PATHINFO_EXTENSION));
        $file_path_with_ext = $file_path . '.' . $extension;
        error_log("Trying with extension: " . $file_path_with_ext);
        
        if (file_exists($file_path_with_ext)) {
            $file_path = $file_path_with_ext;
            error_log("Found file with extension!");
        } else {
            // Try alternative path - maybe file is in old location
            $alt_file_path = __DIR__ . '/../../uploads/safety_documents/' . $serve_document['file_path'];
            error_log("Trying alternative path: " . $alt_file_path);
            
            if (file_exists($alt_file_path)) {
                $file_path = $alt_file_path;
            } else {
                // Try alternative path with extension
                $alt_file_path_ext = $alt_file_path . '.' . $extension;
                error_log("Trying alternative path with extension: " . $alt_file_path_ext);
                
                if (file_exists($alt_file_path_ext)) {
                    $file_path = $alt_file_path_ext;
                } else {
                    http_response_code(404);
                    error_log("File not found at any location. Checked: " . $file_path . ", " . $file_path_with_ext . ", " . $alt_file_path . ", " . $alt_file_path_ext);
                    exit('File not found. Please contact administrator. Document ID: ' . $serve_document_id);
                }
            }
        }
    }
    
    // Set appropriate headers
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_path);
    finfo_close($finfo);
    
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . filesize($file_path));
    
    // For PDF files, try to display inline
    if ($mime_type === 'application/pdf') {
        header('Content-Disposition: inline; filename="' . $serve_document['original_filename'] . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . $serve_document['original_filename'] . '"');
    }
    
    readfile($file_path);
    exit;
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
    
    <!-- PDF.js CDN with fallback and error handling -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" 
            integrity="sha512-..." 
            crossorigin="anonymous"
            onerror="handlePDFJSLoadError()"></script>
    
    <script>
        // PDF.js Error Handling and Fallback
        function handlePDFJSLoadError() {
            console.warn('Primary PDF.js CDN failed, loading backup...');
            const script = document.createElement('script');
            script.src = 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js';
            script.onerror = function() {
                console.error('All PDF.js CDNs failed');
                showPDFLoadError();
            };
            document.head.appendChild(script);
        }
        
        function showPDFLoadError() {
            const container = document.getElementById('pdf-viewer-container');
            if (container) {
                container.innerHTML = `
                    <div class="p-8 text-center bg-red-50 border border-red-200 rounded-lg">
                        <i data-lucide="alert-triangle" class="w-12 h-12 text-red-500 mx-auto mb-4"></i>
                        <h3 class="text-lg font-medium text-red-900 mb-2">PDF Viewer Unavailable</h3>
                        <p class="text-red-700 mb-4">Unable to load PDF viewer. This may be due to network restrictions.</p>
                        <?php if ($can_download_for_user): ?>
                        <a href="?id=<?php echo $document_id; ?>&revision=<?php echo $current_revision_id; ?>&serve_file=1" 
                           class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                            Download PDF Instead
                        </a>
                        <?php else: ?>
                        <p class="text-sm text-red-600">Please contact your administrator for assistance.</p>
                        <?php endif; ?>
                    </div>
                `;
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }
        }
    </script>
    
    <style>
        body { font-family: 'Inter', sans-serif; }
        .sidebar { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) { 
            .sidebar { transform: translateX(-100%); } 
            .sidebar.open { transform: translateX(0); } 
        }
        .document-viewer {
            min-height: 600px;
            background: #f8f9fa;
        }
        .pinned-indicator {
            background: linear-gradient(45deg, #f59e0b, #d97706);
        }
        .tag {
            transition: all 0.2s ease;
        }
        .tag:hover {
            transform: scale(1.05);
        }
        .pdf-viewer-container {
            background: #ffffff;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen">
        
        <!-- Include Navigation -->
        <?php 
        global $navigation_html;
        echo $navigation_html; 
        ?>
        
        <!-- Main Content Area -->
        <main class="flex-1 overflow-auto">
            <div class="p-6">
                
                <!-- Header -->
                <div class="mb-6">
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center mb-2">
                                <?php if ($document['is_pinned']): ?>
                                <div class="pinned-indicator w-6 h-6 rounded-full flex items-center justify-center mr-3">
                                    <i data-lucide="pin" class="w-3 h-3 text-white"></i>
                                </div>
                                <?php endif; ?>
                                <h1 class="text-3xl font-bold text-gray-900 truncate"><?php echo htmlspecialchars($document['title']); ?></h1>
                                <?php if ($document['archived_date']): ?>
                                <span class="ml-3 px-2 py-1 text-sm bg-red-100 text-red-800 rounded-full">
                                    Archived
                                </span>
                                <?php endif; ?>
                                <?php if ($current_revision_id !== $document_id): ?>
                                <span class="ml-3 px-2 py-1 text-sm bg-blue-100 text-blue-800 rounded-full">
                                    Revision: <?php echo htmlspecialchars($current_document['title']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Revision Navigation -->
                            <?php if (!empty($revisions) || $current_revision_id !== $document_id): ?>
                            <div class="mb-3">
                                <div class="flex items-center space-x-2">
                                    <?php
                                    // Find current position in revisions
                                    $all_versions = array_merge([['id' => $document_id, 'title' => 'Original', 'upload_date' => $document['upload_date']]], $revisions);
                                    usort($all_versions, function($a, $b) {
                                        return strtotime($a['upload_date']) - strtotime($b['upload_date']);
                                    });
                                    
                                    $current_index = 0;
                                    foreach ($all_versions as $index => $version) {
                                        if ($version['id'] == $current_revision_id) {
                                            $current_index = $index;
                                            break;
                                        }
                                    }
                                    
                                    $prev_version = $current_index > 0 ? $all_versions[$current_index - 1] : null;
                                    $next_version = $current_index < count($all_versions) - 1 ? $all_versions[$current_index + 1] : null;
                                    ?>
                                    
                                    <!-- Previous Version -->
                                    <?php if ($prev_version): ?>
                                    <a href="view.php?id=<?php echo $document_id; ?>&revision=<?php echo $prev_version['id']; ?>" 
                                       class="flex items-center px-3 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition-colors">
                                        <i data-lucide="chevron-left" class="w-4 h-4 mr-1"></i>
                                        Previous
                                    </a>
                                    <?php else: ?>
                                    <span class="flex items-center px-3 py-1 bg-gray-50 text-gray-400 rounded cursor-not-allowed">
                                        <i data-lucide="chevron-left" class="w-4 h-4 mr-1"></i>
                                        Previous
                                    </span>
                                    <?php endif; ?>
                                    
                                    <!-- Version Selector -->
                                    <select onchange="changeRevision(this.value)" 
                                            class="px-3 py-1 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                        <?php foreach ($all_versions as $version): ?>
                                        <option value="<?php echo $version['id']; ?>" <?php echo $version['id'] == $current_revision_id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($version['title']); ?>
                                            (<?php echo date('M j, Y', strtotime($version['upload_date'])); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <!-- Next Version -->
                                    <?php if ($next_version): ?>
                                    <a href="view.php?id=<?php echo $document_id; ?>&revision=<?php echo $next_version['id']; ?>" 
                                       class="flex items-center px-3 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition-colors">
                                        Next
                                        <i data-lucide="chevron-right" class="w-4 h-4 ml-1"></i>
                                    </a>
                                    <?php else: ?>
                                    <span class="flex items-center px-3 py-1 bg-gray-50 text-gray-400 rounded cursor-not-allowed">
                                        Next
                                        <i data-lucide="chevron-right" class="w-4 h-4 ml-1"></i>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($current_document['description']): ?>
                            <p class="text-gray-600 mb-3"><?php echo htmlspecialchars($current_document['description']); ?></p>
                            <?php endif; ?>
                            
                            <!-- Document Meta -->
                            <div class="flex flex-wrap items-center gap-4 text-sm text-gray-600">
                                <div class="flex items-center">
                                    <i data-lucide="calendar" class="w-4 h-4 mr-1"></i>
                                    Modified: <?php echo date('M j, Y', strtotime($current_document['date_modified'])); ?>
                                </div>
                                <div class="flex items-center">
                                    <i data-lucide="upload" class="w-4 h-4 mr-1"></i>
                                    Uploaded: <?php echo date('M j, Y', strtotime($current_document['upload_date'])); ?>
                                </div>
                                <div class="flex items-center">
                                    <i data-lucide="user" class="w-4 h-4 mr-1"></i>
                                    By: <?php echo htmlspecialchars($current_document['uploader_name'] ?? 'Unknown'); ?>
                                </div>
                                <div class="flex items-center">
                                    <i data-lucide="file" class="w-4 h-4 mr-1"></i>
                                    <?php echo formatDocFileSize($current_document['file_size']); ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="flex items-center space-x-3 ml-4">
                            <!-- Favorite Button -->
                            <button onclick="toggleFavorite()" id="favoriteBtn"
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
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Back Button -->
                            <a href="/documentation/index.php" 
                               class="flex items-center px-3 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                                <span class="hidden sm:inline">Back</span>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Tags -->
                    <?php if (!empty($document_tags)): ?>
                    <div class="flex flex-wrap gap-2 mb-4">
                        <?php foreach ($document_tags as $tag): 
                            $tag = trim($tag);
                            if ($tag):
                        ?>
                        <a href="/documentation/index.php?tag=<?php echo urlencode($tag); ?>" 
                           class="tag inline-flex items-center px-3 py-1 rounded-full text-sm bg-blue-100 text-blue-800 hover:bg-blue-200">
                            <?php echo htmlspecialchars($tag); ?>
                        </a>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Document Viewer -->
                <div class="bg-white rounded-lg shadow mb-6">
                    <div class="p-4 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900">Document Viewer</h2>
                            <div class="flex items-center space-x-2 text-sm text-gray-600">
                                <i data-lucide="<?php echo getDocFileIcon($current_document['original_filename']); ?>" class="w-4 h-4"></i>
                                <span><?php echo htmlspecialchars($current_document['original_filename']); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="document-viewer">
                        <?php if ($current_document['access_type'] === 'view_only' && $is_pdf): ?>
                        <!-- View Only PDF - Custom Viewer to Prevent Download -->
                        <div class="p-8 text-center">
                            <div class="w-20 h-20 bg-blue-100 rounded-lg flex items-center justify-center mx-auto mb-4">
                                <i data-lucide="file-text" class="w-10 h-10 text-blue-600"></i>
                            </div>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">View-Only PDF Document</h3>
                            <p class="text-gray-600 mb-4">This document is restricted to view-only access.</p>
                            <p class="text-sm text-gray-500 mb-6">
                                File: <?php echo htmlspecialchars($current_document['original_filename']); ?> 
                                (<?php echo formatDocFileSize($current_document['file_size']); ?>)
                            </p>
                            
                            <!-- Custom PDF Viewer Button -->
                            <button onclick="openRestrictedPDFViewer()" 
                                    class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 mb-4">
                                <i data-lucide="eye" class="w-4 h-4 mr-2"></i>
                                Open Secure Viewer
                            </button>
                            
                            <div class="text-xs text-gray-500">
                                <p>• Download and print functions are disabled</p>
                                <p>• Document content is protected</p>
                            </div>
                        </div>
                        
                        <?php elseif ($current_document['access_type'] === 'view_only' && !$is_pdf): ?>
                        <!-- View Only - Non-PDF -->
                        <div class="p-8 text-center">
                            <i data-lucide="eye-off" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">Preview Not Available</h3>
                            <p class="text-gray-600 mb-4">This document is set to view-only mode and cannot be previewed online.</p>
                            <p class="text-sm text-gray-500">File: <?php echo htmlspecialchars($current_document['original_filename']); ?></p>
                        </div>
                        
                        <?php elseif ($is_pdf): ?>
                        <!-- Enhanced PDF Viewer with PDF.js -->
                        <div id="pdf-viewer-container" class="w-full">
                            <!-- Loading State -->
                            <div id="pdf-loading" class="flex items-center justify-center h-96 bg-gray-50">
                                <div class="text-center">
                                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
                                    <p class="text-gray-600">Loading PDF viewer...</p>
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
                            
                            <?php if ($can_download_for_user): ?>
                            <a href="?id=<?php echo $document_id; ?>&revision=<?php echo $current_revision_id; ?>&serve_file=1" target="_blank"
                               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 mb-3">
                                <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                                Download Document
                            </a>
                            <?php else: ?>
                            <div class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-600 rounded-lg cursor-not-allowed mb-3">
                                <i data-lucide="lock" class="w-4 h-4 mr-2"></i>
                                Download Restricted
                            </div>
                            <?php endif; ?>
                            
                            <div class="text-sm text-gray-500">
                                <p>File type: <?php echo strtoupper($file_extension); ?></p>
                                <p>Access: <?php echo ucwords(str_replace('_', ' ', $current_document['access_type'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Addendums Section -->
                <?php if (!empty($addendums) || $is_admin): ?>
                <div class="bg-white rounded-lg shadow">
                    <div class="p-4 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900">
                                Addendums 
                                <span class="text-sm font-normal text-gray-500">(<?php echo count($addendums); ?>)</span>
                            </h2>
                            <?php if ($is_admin): ?>
                            <button onclick="addAddendum()" 
                                    class="inline-flex items-center px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                                Add Addendum
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="p-4">
                        <?php if (empty($addendums)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i data-lucide="file-plus" class="w-12 h-12 text-gray-300 mx-auto mb-3"></i>
                            <p>No addendums available for this document.</p>
                        </div>
                        <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($addendums as $addendum): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                <div class="flex items-center">
                                    <div class="p-2 bg-white rounded-lg mr-3">
                                        <i data-lucide="<?php echo getDocFileIcon($addendum['original_filename']); ?>" class="w-5 h-5 text-gray-600"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-medium text-gray-900"><?php echo htmlspecialchars($addendum['title']); ?></h3>
                                        <p class="text-sm text-gray-600">
                                            <?php echo date('M j, Y', strtotime($addendum['upload_date'])); ?> • 
                                            <?php echo formatDocFileSize($addendum['file_size']); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <a href="view.php?id=<?php echo $addendum['id']; ?>" 
                                       class="px-3 py-1 text-sm text-blue-600 hover:text-blue-800">
                                        View
                                    </a>
                                    <?php if ($addendum['access_type'] === 'downloadable' && ($user_has_admin_access || $addendum['access_type'] === 'downloadable')): ?>
                                        <?php if ($user_has_admin_access || $addendum['access_type'] === 'downloadable'): ?>
                                        <a href="view.php?id=<?php echo $addendum['id']; ?>&serve_file=1" 
                                           class="px-3 py-1 text-sm text-green-600 hover:text-green-800">
                                            Download
                                        </a>
                                        <?php else: ?>
                                        <span class="px-3 py-1 text-sm text-gray-400 cursor-not-allowed">
                                            Restricted
                                        </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();
        
        // Production PDF.js Viewer Class
        class ProductionPDFViewer {
            constructor() {
                this.pdfDoc = null;
                this.pageNum = 1;
                this.scale = 0.96; // Set default to 96% (0.96)
                this.searchMatches = [];
                this.currentMatch = -1;
                this.isLoading = false;
                this.retryCount = 0;
                this.maxRetries = 3;
                
                // Configuration from PHP
                this.config = {
                    canDownload: <?php echo $can_download_for_user ? 'true' : 'false'; ?>,
                    canPrint: <?php echo $can_print_for_user ? 'true' : 'false'; ?>,
                    isAdmin: <?php echo $user_has_admin_access ? 'true' : 'false'; ?>,
                    pdfUrl: '?id=<?php echo $document_id; ?>&revision=<?php echo $current_revision_id; ?>&serve_file=1',
                    documentTitle: <?php echo json_encode(htmlspecialchars($current_document['title'])); ?>
                };
                
                this.init();
            }
            
            init() {
                // Wait for PDF.js to load
                this.waitForPDFJS().then(() => {
                    this.setupUI();
                    this.loadPDF();
                }).catch((error) => {
                    console.error('PDF.js failed to load:', error);
                    this.showError('PDF viewer failed to load. Please refresh the page or contact support.');
                });
            }
            
            waitForPDFJS() {
                return new Promise((resolve, reject) => {
                    if (typeof pdfjsLib !== 'undefined') {
                        // Set worker path for CDN
                        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                        resolve();
                        return;
                    }
                    
                    let attempts = 0;
                    const checkPDFJS = () => {
                        attempts++;
                        if (typeof pdfjsLib !== 'undefined') {
                            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                            resolve();
                        } else if (attempts < 50) { // Wait up to 5 seconds
                            setTimeout(checkPDFJS, 100);
                        } else {
                            reject(new Error('PDF.js library failed to load'));
                        }
                    };
                    checkPDFJS();
                });
            }
            
            setupUI() {
                const container = document.getElementById('pdf-viewer-container');
                container.innerHTML = `
                    <div class="pdf-viewer-container border border-gray-200 rounded-lg overflow-hidden">
                        <!-- PDF Controls -->
                        <div class="bg-gray-50 border-b border-gray-200 p-3 flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <button id="prevPage" class="px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed">
                                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                                </button>
                                <span class="text-sm text-gray-600 min-w-24 text-center">
                                    Page <span id="pageNum">1</span> of <span id="pageCount">--</span>
                                </span>
                                <button id="nextPage" class="px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed">
                                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                </button>
                            </div>
                            
                            <div class="flex items-center space-x-3">
                                <button id="zoomOut" class="px-3 py-2 bg-gray-200 rounded hover:bg-gray-300">
                                    <i data-lucide="zoom-out" class="w-4 h-4"></i>
                                </button>
                                <span id="zoomLevel" class="text-sm text-gray-600 min-w-12 text-center">115%</span>
                                <button id="zoomIn" class="px-3 py-2 bg-gray-200 rounded hover:bg-gray-300">
                                    <i data-lucide="zoom-in" class="w-4 h-4"></i>
                                </button>
                                
                                ${this.config.canDownload ? `
                                    <a href="${this.config.pdfUrl}" target="_blank"
                                       class="px-3 py-2 bg-green-600 text-white rounded hover:bg-green-700 inline-flex items-center">
                                        <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                                        <span class="hidden sm:inline">Download</span>
                                    </a>
                                ` : `
                                    <div class="px-3 py-2 bg-gray-100 text-gray-500 rounded cursor-not-allowed inline-flex items-center" title="Download restricted">
                                        <i data-lucide="lock" class="w-4 h-4 mr-2"></i>
                                        <span class="hidden sm:inline">Restricted</span>
                                    </div>
                                `}
                            </div>
                        </div>
                        
                        <!-- Search Bar -->
                        <div class="bg-gray-50 border-b border-gray-200 p-3">
                            <div class="flex items-center space-x-3">
                                <div class="flex-1 relative">
                                    <input type="text" id="searchInput" placeholder="Search in document..." 
                                           class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    <i data-lucide="search" class="absolute left-3 top-3 w-4 h-4 text-gray-400"></i>
                                </div>
                                <button id="searchPrev" class="px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:bg-gray-300" disabled>
                                    <i data-lucide="chevron-up" class="w-4 h-4"></i>
                                </button>
                                <button id="searchNext" class="px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:bg-gray-300" disabled>
                                    <i data-lucide="chevron-down" class="w-4 h-4"></i>
                                </button>
                                <span id="searchCount" class="text-sm text-gray-600 min-w-16 text-center">0 of 0</span>
                            </div>
                        </div>
                        
                        <!-- PDF Canvas -->
                        <div class="bg-white overflow-auto relative" style="height: 600px;" id="pdfContainer">
                            <canvas id="pdfCanvas" class="mx-auto block" style="display: block; max-width: 100%; height: auto; border: 1px solid #e5e7eb;"></canvas>
                            <div id="pdfOverlay" class="absolute top-0 left-0 pointer-events-none" style="width: 100%; height: 100%;"></div>
                        </div>
                        
                        <!-- Status Bar -->
                        <div class="bg-gray-50 border-t border-gray-200 p-2 text-center">
                            <span id="pdfStatus" class="text-sm text-gray-600">Ready</span>
                        </div>
                    </div>
                `;
                
                // Initialize icons
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
                
                this.setupEventListeners();
            }
            
            setupEventListeners() {
                // Navigation
                document.getElementById('prevPage').addEventListener('click', () => this.prevPage());
                document.getElementById('nextPage').addEventListener('click', () => this.nextPage());
                
                // Zoom
                document.getElementById('zoomIn').addEventListener('click', () => this.zoomIn());
                document.getElementById('zoomOut').addEventListener('click', () => this.zoomOut());
                
                // Search
                const searchInput = document.getElementById('searchInput');
                searchInput.addEventListener('input', () => this.performSearch());
                searchInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && this.searchMatches.length > 0) {
                        this.nextSearchMatch();
                    }
                });
                
                document.getElementById('searchNext').addEventListener('click', () => this.nextSearchMatch());
                document.getElementById('searchPrev').addEventListener('click', () => this.prevSearchMatch());
                
                // Disable right-click for non-admin users (optional security measure)
                if (!this.config.isAdmin) {
                    document.getElementById('pdfContainer').addEventListener('contextmenu', (e) => {
                        e.preventDefault();
                        return false;
                    });
                }
            }
            
            async loadPDF() {
                if (this.isLoading) return;
                
                this.isLoading = true;
                this.updateStatus('Loading PDF...');
                
                try {
                    const loadingTask = pdfjsLib.getDocument({
                        url: this.config.pdfUrl,
                        httpHeaders: {
                            'Cache-Control': 'no-cache'
                        }
                    });
                    
                    // Monitor loading progress
                    loadingTask.onProgress = (progress) => {
                        if (progress.total) {
                            const percent = Math.round((progress.loaded / progress.total) * 100);
                            this.updateStatus(`Loading PDF... ${percent}%`);
                        }
                    };
                    
                    this.pdfDoc = await loadingTask.promise;
                    
                    document.getElementById('pageCount').textContent = this.pdfDoc.numPages;
                    
                    // Use a safe default scale that definitely works
                    this.scale = 1.15; // Start with a known working scale
                    console.log('Starting with safe scale:', this.scale);
                    
                    await this.renderPage(this.pageNum);
                    
                    // Force canvas to be visible and properly sized
                    const canvas = document.getElementById('pdfCanvas');
                    canvas.style.display = 'block';
                    canvas.style.maxWidth = '100%';
                    canvas.style.height = 'auto';
                    canvas.style.visibility = 'visible';
                    
                    this.updateStatus(`Loaded successfully (${this.pdfDoc.numPages} pages)`);
                    this.retryCount = 0; // Reset retry count on success
                    
                } catch (error) {
                    console.error('Error loading PDF:', error);
                    this.handleLoadError(error);
                } finally {
                    this.isLoading = false;
                }
            }
            
            handleLoadError(error) {
                this.retryCount++;
                
                if (this.retryCount <= this.maxRetries) {
                    this.updateStatus(`Load failed, retrying... (${this.retryCount}/${this.maxRetries})`);
                    setTimeout(() => this.loadPDF(), 2000 * this.retryCount); // Exponential backoff
                } else {
                    this.showError('Failed to load PDF after multiple attempts. Please refresh the page or contact support.');
                }
            }
            
            async renderPage(num) {
                if (!this.pdfDoc || this.isLoading) return;
                
                try {
                    const page = await this.pdfDoc.getPage(num);
                    const viewport = page.getViewport({scale: this.scale});
                    
                    const canvas = document.getElementById('pdfCanvas');
                    const ctx = canvas.getContext('2d');
                    
                    // Set canvas dimensions before rendering
                    canvas.height = viewport.height;
                    canvas.width = viewport.width;
                    
                    // Clear any previous content
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    
                    const renderContext = {
                        canvasContext: ctx,
                        viewport: viewport
                    };
                    
                    // Render the page
                    await page.render(renderContext).promise;
                    
                    // Update UI
                    document.getElementById('pageNum').textContent = num;
                    document.getElementById('zoomLevel').textContent = Math.round(this.scale * 100) + '%';
                    document.getElementById('prevPage').disabled = (num <= 1);
                    document.getElementById('nextPage').disabled = (num >= this.pdfDoc.numPages);
                    
                    this.pageNum = num;
                    
                    // Clear any existing highlights and re-render search highlights if needed
                    this.clearHighlights();
                    if (this.searchMatches.length > 0 && this.currentMatch >= 0) {
                        await this.highlightSearchMatches(page, viewport);
                    }
                    
                } catch (error) {
                    console.error('Error rendering page:', error);
                    this.updateStatus('Error rendering page');
                }
            }
            
            prevPage() {
                if (this.pageNum <= 1) return;
                this.renderPage(this.pageNum - 1);
            }
            
            nextPage() {
                if (this.pageNum >= this.pdfDoc.numPages) return;
                this.renderPage(this.pageNum + 1);
            }
            
            zoomIn() {
                this.scale *= 1.25;
                // Ensure scale is never exactly 1.0
                if (this.scale === 1.0) {
                    this.scale = 1.01;
                }
                this.renderPage(this.pageNum);
            }
            
            zoomOut() {
                this.scale *= 0.8;
                // Ensure scale is never exactly 1.0
                if (this.scale === 1.0) {
                    this.scale = 1.01;
                }
                this.renderPage(this.pageNum);
            }
            
            async performSearch() {
                const query = document.getElementById('searchInput').value.trim();
                if (!query || !this.pdfDoc) {
                    this.clearSearch();
                    return;
                }
                
                this.searchMatches = [];
                this.currentMatch = -1;
                this.updateStatus('Searching...');
                
                try {
                    // Search through all pages and store text items with positions
                    for (let i = 1; i <= this.pdfDoc.numPages; i++) {
                        const page = await this.pdfDoc.getPage(i);
                        const textContent = await page.getTextContent();
                        const text = textContent.items.map(item => item.str).join(' ');
                        
                        // Also store individual text items for highlighting
                        const regex = new RegExp(query, 'gi');
                        let match;
                        let textIndex = 0;
                        
                        for (const item of textContent.items) {
                            const itemText = item.str;
                            const itemRegex = new RegExp(query, 'gi');
                            let itemMatch;
                            
                            while ((itemMatch = itemRegex.exec(itemText)) !== null) {
                                this.searchMatches.push({
                                    page: i,
                                    textItem: item,
                                    matchIndex: itemMatch.index,
                                    matchText: itemMatch[0],
                                    fullText: itemText,
                                    globalIndex: textIndex + itemMatch.index
                                });
                            }
                            textIndex += itemText.length + 1; // +1 for space
                        }
                    }
                    
                    this.updateSearchUI();
                    
                    if (this.searchMatches.length > 0) {
                        this.currentMatch = 0;
                        this.goToSearchMatch();
                        this.updateStatus(`Found ${this.searchMatches.length} matches`);
                    } else {
                        this.updateStatus('No matches found');
                    }
                    
                } catch (error) {
                    console.error('Search error:', error);
                    this.updateStatus('Search failed');
                }
            }
            
            updateSearchUI() {
                const count = this.searchMatches.length;
                const current = this.currentMatch >= 0 ? this.currentMatch + 1 : 0;
                
                document.getElementById('searchCount').textContent = `${current} of ${count}`;
                document.getElementById('searchNext').disabled = count === 0;
                document.getElementById('searchPrev').disabled = count === 0;
            }
            
            nextSearchMatch() {
                if (this.searchMatches.length === 0) return;
                this.currentMatch = (this.currentMatch + 1) % this.searchMatches.length;
                this.goToSearchMatch();
            }
            
            prevSearchMatch() {
                if (this.searchMatches.length === 0) return;
                this.currentMatch = this.currentMatch <= 0 ? this.searchMatches.length - 1 : this.currentMatch - 1;
                this.goToSearchMatch();
            }
            
            async goToSearchMatch() {
                if (this.currentMatch < 0 || this.currentMatch >= this.searchMatches.length) return;
                
                const match = this.searchMatches[this.currentMatch];
                if (match.page !== this.pageNum) {
                    await this.renderPage(match.page);
                } else {
                    // Re-highlight on current page
                    const page = await this.pdfDoc.getPage(this.pageNum);
                    const viewport = page.getViewport({scale: this.scale});
                    await this.highlightSearchMatches(page, viewport);
                }
                
                this.updateSearchUI();
            }
            
            async highlightSearchMatches(page, viewport) {
                try {
                    // Clear existing highlights
                    this.clearHighlights();
                    
                    // Get current page matches
                    const currentPageMatches = this.searchMatches.filter(match => match.page === this.pageNum);
                    if (currentPageMatches.length === 0) return;
                    
                    const overlay = document.getElementById('pdfOverlay');
                    const canvas = document.getElementById('pdfCanvas');
                    const container = document.getElementById('pdfContainer');
                    
                    // Get canvas position relative to container
                    const canvasRect = canvas.getBoundingClientRect();
                    const containerRect = container.getBoundingClientRect();
                    
                    const canvasOffsetX = canvasRect.left - containerRect.left + container.scrollLeft;
                    const canvasOffsetY = canvasRect.top - containerRect.top + container.scrollTop;
                    
                    for (let i = 0; i < currentPageMatches.length; i++) {
                        const match = currentPageMatches[i];
                        const textItem = match.textItem;
                        
                        // Get text position using PDF viewport transformation
                        const transform = textItem.transform;
                        
                        // Calculate position - PDF coordinates to canvas coordinates
                        const x = transform[4]; // x position from PDF
                        const y = transform[5]; // y position from PDF
                        
                        // Transform PDF coordinates to canvas coordinates
                        const canvasX = x * viewport.scale;
                        const canvasY = viewport.height - (y * viewport.scale); // Flip Y coordinate
                        
                        // Estimate text dimensions
                        const fontSize = Math.abs(transform[0]) * viewport.scale;
                        const charWidth = fontSize * 0.5; // Approximate character width
                        const textWidth = match.matchText.length * charWidth;
                        const textHeight = fontSize;
                        
                        // Adjust for canvas position within container
                        const finalX = canvasOffsetX + canvasX;
                        const finalY = canvasOffsetY + canvasY - textHeight;
                        
                        // Create highlight element
                        const highlight = document.createElement('div');
                        highlight.className = 'search-highlight';
                        
                        // Determine if this is the current match
                        const isCurrentMatch = (this.searchMatches.indexOf(match) === this.currentMatch);
                        
                        highlight.style.cssText = `
                            position: absolute;
                            left: ${finalX}px;
                            top: ${finalY}px;
                            width: ${textWidth}px;
                            height: ${textHeight}px;
                            background-color: ${isCurrentMatch ? 'rgba(255, 235, 59, 0.8)' : 'rgba(255, 193, 7, 0.6)'};
                            border: ${isCurrentMatch ? '2px solid #f57f17' : '1px solid #ff8f00'};
                            border-radius: 2px;
                            pointer-events: none;
                            z-index: 10;
                            box-shadow: ${isCurrentMatch ? '0 0 4px rgba(245, 127, 23, 0.8)' : 'none'};
                        `;
                        
                        overlay.appendChild(highlight);
                    }
                    
                } catch (error) {
                    console.error('Error highlighting matches:', error);
                }
            }
            
            clearHighlights() {
                const overlay = document.getElementById('pdfOverlay');
                if (overlay) {
                    // Remove all highlight elements
                    const highlights = overlay.querySelectorAll('.search-highlight');
                    highlights.forEach(highlight => highlight.remove());
                }
            }
            
            clearSearch() {
                this.searchMatches = [];
                this.currentMatch = -1;
                this.clearHighlights();
                this.updateSearchUI();
            }
            
            updateStatus(message) {
                const statusEl = document.getElementById('pdfStatus');
                if (statusEl) {
                    statusEl.textContent = message;
                }
            }
            
            showError(message) {
                const container = document.getElementById('pdf-viewer-container');
                container.innerHTML = `
                    <div class="p-8 text-center">
                        <i data-lucide="alert-triangle" class="w-16 h-16 text-red-500 mx-auto mb-4"></i>
                        <h3 class="text-lg font-medium text-red-900 mb-2">PDF Viewer Error</h3>
                        <p class="text-red-700 mb-4">${message}</p>
                        ${this.config.canDownload ? `
                            <a href="${this.config.pdfUrl}" target="_blank"
                               class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                                <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                                Download PDF Instead
                            </a>
                        ` : ''}
                    </div>
                `;
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }
        }
        
        // Initialize PDF viewer for PDF documents
        <?php if ($is_pdf): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Hide loading state
            const loading = document.getElementById('pdf-loading');
            if (loading) {
                loading.style.display = 'none';
            }
            
            // Initialize PDF viewer
            new ProductionPDFViewer();
        });
        <?php endif; ?>
        
        // Revision change handler
        function changeRevision(revisionId) {
            if (revisionId == <?php echo $document_id; ?>) {
                window.location.href = 'view.php?id=<?php echo $document_id; ?>';
            } else {
                window.location.href = 'view.php?id=<?php echo $document_id; ?>&revision=' + revisionId;
            }
        }
        
        // Restricted PDF Viewer for View-Only Documents
        function openRestrictedPDFViewer() {
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50';
            modal.innerHTML = `
                <div class="w-full h-full max-w-6xl mx-4 my-4 bg-white rounded-lg flex flex-col">
                    <div class="flex items-center justify-between p-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">
                            <?php echo htmlspecialchars($current_document['title']); ?> (View Only)
                        </h3>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-red-600 bg-red-50 px-2 py-1 rounded">
                                Download & Print Disabled
                            </span>
                            <button onclick="closeRestrictedViewer()" class="p-2 text-gray-400 hover:text-gray-600">
                                <i data-lucide="x" class="w-5 h-5"></i>
                            </button>
                        </div>
                    </div>
                    <div class="flex-1 bg-gray-100">
                        <iframe src="?id=<?php echo $document_id; ?>&revision=<?php echo $current_revision_id; ?>&serve_file=1#toolbar=0&navpanes=0&scrollbar=1&view=FitH&zoom=100" 
                                class="w-full h-full border-0"
                                oncontextmenu="return false;"
                                title="Restricted PDF Viewer">
                        </iframe>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            lucide.createIcons();
            
            // Disable right-click on the modal
            modal.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                return false;
            });
        }
        
        function closeRestrictedViewer() {
            const modal = document.querySelector('.fixed.inset-0');
            if (modal) {
                modal.remove();
            }
        }
        
        // Add revision modal
        function addRevision() {
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            modal.innerHTML = `
                <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Add New Revision</h3>
                    <form id="revisionForm" enctype="multipart/form-data">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Revision Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="revision_name" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="e.g., Version 2.1, Updated 2025">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Description
                                </label>
                                <textarea name="description" rows="3"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                          placeholder="Brief description of changes..."></textarea>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Date Modified
                                </label>
                                <input type="date" name="date_modified" value="${new Date().toISOString().split('T')[0]}"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Revision File <span class="text-red-500">*</span>
                                </label>
                                <input type="file" name="revision_file" required 
                                       accept="<?php echo implode(',', array_map(function($ext) { return '.' . $ext; }, DOC_ALLOWED_EXTENSIONS)); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <p class="text-xs text-gray-500 mt-1">Max size: <?php echo formatDocFileSize(DOC_MAX_FILE_SIZE); ?></p>
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-3 mt-6">
                            <button type="button" onclick="closeRevisionModal()" 
                                    class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                Add Revision
                            </button>
                        </div>
                    </form>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Handle form submission
            document.getElementById('revisionForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'add_revision');
                
                try {
                    const button = this.querySelector('button[type="submit"]');
                    button.disabled = true;
                    button.innerHTML = 'Adding...';
                    
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        if (result.redirect) {
                            window.location.href = result.redirect;
                        } else {
                            location.reload();
                        }
                    } else {
                        alert(result.message || 'Failed to add revision');
                        button.disabled = false;
                        button.innerHTML = 'Add Revision';
                    }
                } catch (error) {
                    console.error('Error adding revision:', error);
                    alert('Failed to add revision');
                }
            });
        }
        
        function closeRevisionModal() {
            const modal = document.querySelector('.fixed.inset-0');
            if (modal) {
                modal.remove();
            }
        }
        
        // Close modal on backdrop click
        document.addEventListener('click', function(e) {
            if (e.target.matches('.fixed.inset-0')) {
                closeRevisionModal();
            }
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
                    const btn = document.getElementById('favoriteBtn');
                    const icon = btn.querySelector('i');
                    const text = btn.querySelector('span');
                    
                    if (result.action === 'added') {
                        btn.className = 'flex items-center px-3 py-2 bg-yellow-100 text-yellow-800 rounded-lg hover:bg-yellow-200 transition-colors';
                        icon.classList.add('fill-current');
                        if (text) text.textContent = 'Favorited';
                    } else {
                        btn.className = 'flex items-center px-3 py-2 bg-gray-100 text-gray-600 rounded-lg hover:bg-yellow-200 transition-colors';
                        icon.classList.remove('fill-current');
                        if (text) text.textContent = 'Add to Favorites';
                    }
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
                    if (result.success) {
                        location.reload(); // Reload to update UI
                    } else {
                        alert(result.message || 'Failed to unarchive document');
                    }
                } catch (error) {
                    console.error('Error unarchiving document:', error);
                    alert('Failed to unarchive document');
                }
            }
        }
        
        // Placeholder functions for future features
        function editDocument() {
            alert('Edit functionality will be implemented in the next phase.');
        }
        
        function addAddendum() {
            alert('Add addendum functionality will be implemented in the next phase.');
        }
        
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('open');
        }
    </script>
</body>
</html>