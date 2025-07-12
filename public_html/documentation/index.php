<?php
// /public_html/documentation/index.php
// Final Documentation Module - Complete Document Browser

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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Toggle favorite
    if ($action === 'toggle_favorite') {
        $document_id = intval($_POST['document_id'] ?? 0);
        $result = toggleDocumentFavorite($document_id, $_SESSION['user_id'], $conn);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $result !== false,
            'action' => $result,
            'message' => $result === 'added' ? 'Added to favorites' : 'Removed from favorites'
        ]);
        exit;
    }
    
    // Admin-only actions
    if (hasDocAdminAccess()) {
        // Toggle pin
        if ($action === 'toggle_pin') {
            $document_id = intval($_POST['document_id'] ?? 0);
            $result = toggleDocumentPin($document_id, $conn);
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Pin status updated' : 'Failed to update pin status'
            ]);
            exit;
        }
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$tag = $_GET['tag'] ?? '';
$category = $_GET['category'] ?? '';
$favorites_only = isset($_GET['favorites']) && $_GET['favorites'] === '1';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 12;

// Get user info and admin status
$user_name = $_SESSION['user_first_name'] . ' ' . $_SESSION['user_last_name'];
$is_admin = hasDocAdminAccess();

// Get documents and pagination info
$include_archived = $is_admin && isset($_GET['include_archived']) && $_GET['include_archived'] === '1';
$documents = getDocuments($conn, $search, $tag, $favorites_only, $_SESSION['user_id'], $page, $per_page, $include_archived);
$total_documents = getDocumentCount($conn, $search, $tag, $favorites_only, $_SESSION['user_id'], $include_archived);
$total_pages = ceil($total_documents / $per_page);

// Get popular tags for tag cloud
$popular_tags = getPopularTags($conn, 15);

// Get messages from URL
$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safety Documentation - Safety Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@0.378.0/dist/umd/lucide.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .sidebar { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) { 
            .sidebar { transform: translateX(-100%); } 
            .sidebar.open { transform: translateX(0); } 
        }
        .tag-cloud .tag {
            transition: all 0.2s ease;
        }
        .tag-cloud .tag:hover {
            transform: scale(1.05);
        }
        .document-card {
            transition: all 0.3s ease;
        }
        .document-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .pinned-indicator {
            background: linear-gradient(45deg, #f59e0b, #d97706);
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
                
                <!-- Header Section -->
                <div class="mb-6">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">Safety Documentation</h1>
                            <p class="text-gray-600 mt-1">Company safety documentation and resources</p>
                        </div>
                        <?php if ($is_admin): ?>
                        <a href="upload.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            Upload Document
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Search and Filters -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <form method="GET" class="space-y-4">
                            <div class="flex flex-col md:flex-row gap-4">
                                <!-- Search Input -->
                                <div class="flex-1">
                                    <div class="relative">
                                        <i data-lucide="search" class="absolute left-3 top-3 w-4 h-4 text-gray-400"></i>
                                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                               placeholder="Search documents..." 
                                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    </div>
                                </div>
                                
                                <!-- Category Filter -->
                                <div class="md:w-48">
                                    <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                        <option value="">All Categories</option>
                                        <?php foreach (DOC_CATEGORIES as $key => $label): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $category === $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- Favorites Toggle -->
                                <div class="flex items-center">
                                    <label class="flex items-center">
                                        <input type="checkbox" name="favorites" value="1" <?php echo $favorites_only ? 'checked' : ''; ?> 
                                               class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                        <span class="ml-2 text-sm text-gray-700">My Favorites</span>
                                    </label>
                                </div>
                                
                                <!-- Archived Toggle (Admin Only) -->
                                <?php if ($is_admin): ?>
                                <div class="flex items-center">
                                    <label class="flex items-center">
                                        <input type="checkbox" name="include_archived" value="1" <?php echo $include_archived ? 'checked' : ''; ?> 
                                               class="w-4 h-4 text-red-600 border-gray-300 rounded focus:ring-red-500">
                                        <span class="ml-2 text-sm text-gray-700">Include Archived</span>
                                    </label>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Search Button -->
                                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                    Search
                                </button>
                            </div>
                            
                            <!-- Selected Tag -->
                            <?php if ($tag): ?>
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-gray-600">Filtered by tag:</span>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    <?php echo htmlspecialchars($tag); ?>
                                    <a href="?" class="ml-1 text-blue-600 hover:text-blue-800">
                                        <i data-lucide="x" class="w-3 h-3"></i>
                                    </a>
                                </span>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($message): ?>
                <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <!-- Tag Cloud -->
                <?php if (!empty($popular_tags) && empty($search)): ?>
                <div class="mb-6 bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">Popular Tags</h2>
                    <div class="tag-cloud flex flex-wrap gap-2">
                        <?php foreach ($popular_tags as $pop_tag): ?>
                        <a href="?tag=<?php echo urlencode($pop_tag['tag']); ?>" 
                           class="tag inline-flex items-center px-3 py-1 rounded-full text-sm bg-gray-100 text-gray-800 hover:bg-blue-100 hover:text-blue-800">
                            <?php echo htmlspecialchars($pop_tag['tag']); ?>
                            <span class="ml-1 text-xs text-gray-500">(<?php echo $pop_tag['count']; ?>)</span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Documents Section -->
                <div class="bg-white rounded-lg shadow">
                    <!-- Section Header -->
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h2 class="text-lg font-semibold text-gray-900">
                                <?php if ($search || $tag || $favorites_only): ?>
                                    Search Results
                                <?php else: ?>
                                    All Documents
                                <?php endif; ?>
                                <span class="text-sm font-normal text-gray-500">
                                    (<?php echo $total_documents; ?> <?php echo $total_documents === 1 ? 'document' : 'documents'; ?>)
                                </span>
                            </h2>
                            
                            <?php if ($search || $tag || $favorites_only): ?>
                            <a href="?" class="text-sm text-blue-600 hover:text-blue-800">Clear all filters</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Documents Grid -->
                    <div class="p-6">
                        <?php if (empty($documents)): ?>
                        <!-- Empty State -->
                        <div class="text-center py-12">
                            <i data-lucide="file-text" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">
                                <?php if ($search || $tag || $favorites_only): ?>
                                    No documents found
                                <?php else: ?>
                                    No documents yet
                                <?php endif; ?>
                            </h3>
                            <p class="text-gray-600 mb-6">
                                <?php if ($search || $tag || $favorites_only): ?>
                                    Try adjusting your search or filters.
                                <?php else: ?>
                                    <?php echo $is_admin ? 'Upload your first document to get started.' : 'Documents will appear here once uploaded by administrators.'; ?>
                                <?php endif; ?>
                            </p>
                            <?php if ($is_admin && !($search || $tag || $favorites_only)): ?>
                            <a href="upload.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                                Upload First Document
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <!-- Documents Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                            <?php foreach ($documents as $doc): ?>
                            <div class="document-card bg-white border border-gray-200 rounded-lg p-4 hover:shadow-lg transition-all relative <?php echo $doc['archived_date'] ? 'opacity-75 border-red-200' : ''; ?>">
                                <!-- Pinned Indicator -->
                                <?php if ($doc['is_pinned']): ?>
                                <div class="pinned-indicator absolute top-2 right-2 w-6 h-6 rounded-full flex items-center justify-center">
                                    <i data-lucide="pin" class="w-3 h-3 text-white"></i>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Archived Indicator -->
                                <?php if ($doc['archived_date']): ?>
                                <div class="absolute top-2 left-2 px-2 py-1 text-xs bg-red-100 text-red-800 rounded-full">
                                    Archived
                                </div>
                                <?php endif; ?>
                                
                                <!-- Document Header -->
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex items-center">
                                        <div class="p-2 bg-blue-100 rounded-lg mr-3">
                                            <i data-lucide="<?php echo getDocFileIcon($doc['original_filename']); ?>" class="w-5 h-5 text-blue-600"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <h3 class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($doc['title']); ?></h3>
                                            <p class="text-xs text-gray-500"><?php echo date('M j, Y', strtotime($doc['date_modified'])); ?></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Description -->
                                <?php if ($doc['description']): ?>
                                <p class="text-xs text-gray-600 mb-3 line-clamp-2"><?php echo htmlspecialchars(substr($doc['description'], 0, 100)); ?><?php echo strlen($doc['description']) > 100 ? '...' : ''; ?></p>
                                <?php endif; ?>
                                
                                <!-- Tags -->
                                <?php if ($doc['tags']): ?>
                                <div class="mb-3">
                                    <div class="flex flex-wrap gap-1">
                                        <?php 
                                        $doc_tags = array_slice(explode(',', $doc['tags']), 0, 2);
                                        foreach ($doc_tags as $doc_tag): 
                                            $doc_tag = trim($doc_tag);
                                            if ($doc_tag):
                                        ?>
                                        <span class="inline-block px-2 py-1 text-xs bg-gray-100 text-gray-700 rounded">
                                            <?php echo htmlspecialchars($doc_tag); ?>
                                        </span>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Footer Actions -->
                                <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                                    <div class="flex items-center space-x-2">
                                        <!-- Favorite Button -->
                                        <button onclick="toggleFavorite(<?php echo $doc['id']; ?>)" 
                                                class="p-1 rounded hover:bg-gray-100 transition-colors" 
                                                id="fav-btn-<?php echo $doc['id']; ?>">
                                            <i data-lucide="star" class="w-4 h-4 <?php echo $doc['is_favorite'] ? 'text-yellow-500 fill-current' : 'text-gray-400'; ?>"></i>
                                        </button>
                                        
                                        <!-- Admin Pin Button -->
                                        <?php if ($is_admin): ?>
                                        <button onclick="togglePin(<?php echo $doc['id']; ?>)" 
                                                class="p-1 rounded hover:bg-gray-100 transition-colors">
                                            <i data-lucide="pin" class="w-4 h-4 <?php echo $doc['is_pinned'] ? 'text-orange-500' : 'text-gray-400'; ?>"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="flex items-center space-x-2">
                                        <!-- View Button -->
                                        <a href="view.php?id=<?php echo $doc['id']; ?>" 
                                           class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                            View
                                        </a>
                                        
                                        <!-- Download Button (if allowed) -->
                                        <?php if ($doc['access_type'] === 'downloadable'): ?>
                                        <a href="download.php?id=<?php echo $doc['id']; ?>" 
                                           class="text-xs text-green-600 hover:text-green-800 font-medium">
                                            Download
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="mt-8 flex justify-center">
                            <nav class="flex items-center space-x-2">
                                <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                   class="px-3 py-2 text-sm text-gray-600 hover:text-gray-900 border border-gray-300 rounded hover:bg-gray-50">
                                    Previous
                                </a>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                   class="px-3 py-2 text-sm <?php echo $i === $page ? 'bg-blue-600 text-white' : 'text-gray-600 hover:text-gray-900 border border-gray-300 hover:bg-gray-50'; ?> rounded">
                                    <?php echo $i; ?>
                                </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                   class="px-3 py-2 text-sm text-gray-600 hover:text-gray-900 border border-gray-300 rounded hover:bg-gray-50">
                                    Next
                                </a>
                                <?php endif; ?>
                            </nav>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();
        
        // Toggle favorite
        async function toggleFavorite(documentId) {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=toggle_favorite&document_id=${documentId}`
                });
                
                const result = await response.json();
                if (result.success) {
                    const btn = document.getElementById(`fav-btn-${documentId}`);
                    const icon = btn.querySelector('i');
                    
                    if (result.action === 'added') {
                        icon.classList.add('text-yellow-500', 'fill-current');
                        icon.classList.remove('text-gray-400');
                    } else {
                        icon.classList.remove('text-yellow-500', 'fill-current');
                        icon.classList.add('text-gray-400');
                    }
                }
            } catch (error) {
                console.error('Error toggling favorite:', error);
            }
        }
        
        // Toggle pin (admin only)
        async function togglePin(documentId) {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=toggle_pin&document_id=${documentId}`
                });
                
                const result = await response.json();
                if (result.success) {
                    location.reload(); // Reload to update pin order
                }
            } catch (error) {
                console.error('Error toggling pin:', error);
            }
        }
        
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('open');
        }
    </script>
</body>
</html>