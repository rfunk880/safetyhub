<?php
// /public_html/documentation/search.php
// Advanced Search Feature for Documentation

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

// Handle AJAX requests for favorite toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
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
}

// Get search parameters
$search = trim($_GET['search'] ?? '');
$tags = $_GET['tags'] ?? [];
$categories = $_GET['categories'] ?? [];
$access_type = $_GET['access_type'] ?? '';
$visibility = $_GET['visibility'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$uploader = $_GET['uploader'] ?? '';
$file_type = $_GET['file_type'] ?? '';
$favorites_only = isset($_GET['favorites']) && $_GET['favorites'] === '1';
$sort_by = $_GET['sort_by'] ?? 'relevance';
$sort_order = $_GET['sort_order'] ?? 'desc';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// Build search query
$where_conditions = [];
$params = [];
$types = '';

// Base query with role-based visibility
$sql = "SELECT d.*, u.firstName, u.lastName,
               CASE 
                   WHEN df.user_id IS NOT NULL THEN 1 
                   ELSE 0 
               END as is_favorite,
               COUNT(df2.id) as favorite_count
        FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        LEFT JOIN document_favorites df ON d.id = df.document_id AND df.user_id = ?
        LEFT JOIN document_favorites df2 ON d.id = df2.document_id
        WHERE d.archived_date IS NULL AND (d.is_revision IS NULL OR d.is_revision = 0)";

$params[] = $_SESSION['user_id'];
$types .= 'i';

// Add role-based visibility filter
$user_role_id = $_SESSION['user_role_id'];
if ($user_role_id == 6) { // Subcontractor
    $where_conditions[] = "d.visibility IN ('all')";
} elseif ($user_role_id == 5) { // Employee
    $where_conditions[] = "d.visibility IN ('all', 'employees_only')";
} elseif ($user_role_id == 4) { // Supervisor
    $where_conditions[] = "d.visibility IN ('all', 'employees_only', 'supervisors_plus')";
}

// Text search
if (!empty($search)) {
    $where_conditions[] = "(d.title LIKE ? OR d.description LIKE ? OR d.tags LIKE ? OR d.original_filename LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ssss';
}

// Tags filter
if (!empty($tags)) {
    $tag_conditions = [];
    foreach ($tags as $tag) {
        $tag_conditions[] = "FIND_IN_SET(?, d.tags)";
        $params[] = trim($tag);
        $types .= 's';
    }
    $where_conditions[] = "(" . implode(" OR ", $tag_conditions) . ")";
}

// Categories filter (same as tags but for predefined categories)
if (!empty($categories)) {
    $category_conditions = [];
    foreach ($categories as $category) {
        $category_conditions[] = "FIND_IN_SET(?, d.tags)";
        $params[] = trim($category);
        $types .= 's';
    }
    $where_conditions[] = "(" . implode(" OR ", $category_conditions) . ")";
}

// Access type filter
if (!empty($access_type)) {
    $where_conditions[] = "d.access_type = ?";
    $params[] = $access_type;
    $types .= 's';
}

// Visibility filter
if (!empty($visibility)) {
    $where_conditions[] = "d.visibility = ?";
    $params[] = $visibility;
    $types .= 's';
}

// Date range filter
if (!empty($date_from)) {
    $where_conditions[] = "d.date_modified >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = "d.date_modified <= ?";
    $params[] = $date_to;
    $types .= 's';
}

// Uploader filter
if (!empty($uploader)) {
    $where_conditions[] = "(u.firstName LIKE ? OR u.lastName LIKE ? OR CONCAT(u.firstName, ' ', u.lastName) LIKE ?)";
    $uploader_param = '%' . $uploader . '%';
    $params[] = $uploader_param;
    $params[] = $uploader_param;
    $params[] = $uploader_param;
    $types .= 'sss';
}

// File type filter
if (!empty($file_type)) {
    $where_conditions[] = "d.original_filename LIKE ?";
    $params[] = '%.' . $file_type;
    $types .= 's';
}

// Favorites filter
if ($favorites_only) {
    $where_conditions[] = "df.user_id IS NOT NULL";
}

// Add WHERE conditions
if (!empty($where_conditions)) {
    $sql .= " AND " . implode(" AND ", $where_conditions);
}

$sql .= " GROUP BY d.id";

// Sorting
switch ($sort_by) {
    case 'title':
        $sql .= " ORDER BY d.title " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    case 'date_modified':
        $sql .= " ORDER BY d.date_modified " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    case 'upload_date':
        $sql .= " ORDER BY d.upload_date " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    case 'size':
        $sql .= " ORDER BY d.file_size " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    case 'popularity':
        $sql .= " ORDER BY favorite_count " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    default: // relevance
        $sql .= " ORDER BY d.is_pinned DESC, d.date_modified DESC";
}

// Pagination
$sql .= " LIMIT ? OFFSET ?";
$offset = ($page - 1) * $per_page;
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

// Execute search query
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Advanced Search SQL Error: " . $conn->error);
    $documents = [];
} else {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $documents = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get total count for pagination
$count_sql = str_replace("SELECT d.*, u.firstName, u.lastName, CASE WHEN df.user_id IS NOT NULL THEN 1 ELSE 0 END as is_favorite, COUNT(df2.id) as favorite_count", "SELECT COUNT(DISTINCT d.id) as total", $sql);
$count_sql = preg_replace('/GROUP BY d\.id.*$/', '', $count_sql);
$count_sql = preg_replace('/LIMIT \? OFFSET \?$/', '', $count_sql);

// Remove last two parameters (limit and offset)
$count_params = array_slice($params, 0, -2);
$count_types = substr($types, 0, -2);

$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) {
    $count_stmt->bind_param($count_types, ...$count_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_documents = $count_result->fetch_assoc()['total'] ?? 0;
    $count_stmt->close();
} else {
    $total_documents = 0;
}

$total_pages = ceil($total_documents / $per_page);

// Get popular tags for suggestions
$popular_tags = getPopularTags($conn, 20);

// Get available uploaders
$uploaders_sql = "SELECT DISTINCT u.firstName, u.lastName, CONCAT(u.firstName, ' ', u.lastName) as full_name
                  FROM documents d 
                  INNER JOIN users u ON d.uploaded_by = u.id 
                  WHERE d.archived_date IS NULL 
                  ORDER BY u.firstName, u.lastName";
$uploaders_result = $conn->query($uploaders_sql);
$available_uploaders = $uploaders_result ? $uploaders_result->fetch_all(MYSQLI_ASSOC) : [];

// Get available file types
$file_types_sql = "SELECT DISTINCT LOWER(SUBSTRING_INDEX(original_filename, '.', -1)) as extension
                   FROM documents 
                   WHERE archived_date IS NULL AND original_filename LIKE '%.%'
                   ORDER BY extension";
$file_types_result = $conn->query($file_types_sql);
$available_file_types = $file_types_result ? $file_types_result->fetch_all(MYSQLI_ASSOC) : [];

$user_name = $_SESSION['user_first_name'] . ' ' . $_SESSION['user_last_name'];
$is_admin = hasDocAdminAccess();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Search - Safety Hub</title>
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
        .document-card {
            transition: all 0.3s ease;
        }
        .document-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .search-filter {
            transition: all 0.3s ease;
        }
        .filter-toggle.active {
            background-color: #3b82f6;
            color: white;
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
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">Advanced Search</h1>
                            <p class="text-gray-600 mt-1">Find documents with detailed filters and sorting options</p>
                        </div>
                        <div class="flex items-center space-x-3">
                            <a href="/documentation/index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                                Back to Documents
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Search Form -->
                <div class="bg-white rounded-lg shadow mb-6">
                    <div class="p-6 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Search Filters</h2>
                        
                        <form method="GET" class="space-y-6">
                            
                            <!-- Primary Search -->
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                                <div class="lg:col-span-2">
                                    <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Search Text</label>
                                    <div class="relative">
                                        <i data-lucide="search" class="absolute left-3 top-3 w-4 h-4 text-gray-400"></i>
                                        <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" 
                                               placeholder="Search in titles, descriptions, tags, and filenames..." 
                                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    </div>
                                </div>
                                
                                <div>
                                    <label for="sort_by" class="block text-sm font-medium text-gray-700 mb-2">Sort By</label>
                                    <div class="flex space-x-2">
                                        <select name="sort_by" id="sort_by" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                            <option value="relevance" <?php echo $sort_by === 'relevance' ? 'selected' : ''; ?>>Relevance</option>
                                            <option value="title" <?php echo $sort_by === 'title' ? 'selected' : ''; ?>>Title</option>
                                            <option value="date_modified" <?php echo $sort_by === 'date_modified' ? 'selected' : ''; ?>>Date Modified</option>
                                            <option value="upload_date" <?php echo $sort_by === 'upload_date' ? 'selected' : ''; ?>>Upload Date</option>
                                            <option value="size" <?php echo $sort_by === 'size' ? 'selected' : ''; ?>>File Size</option>
                                            <option value="popularity" <?php echo $sort_by === 'popularity' ? 'selected' : ''; ?>>Popularity</option>
                                        </select>
                                        <select name="sort_order" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                            <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>↓</option>
                                            <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>↑</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Advanced Filters Toggle -->
                            <div class="flex items-center justify-between">
                                <button type="button" onclick="toggleAdvancedFilters()" 
                                        class="filter-toggle flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                    <i data-lucide="filter" class="w-4 h-4 mr-2"></i>
                                    Advanced Filters
                                    <i data-lucide="chevron-down" class="w-4 h-4 ml-2" id="filterChevron"></i>
                                </button>
                                
                                <div class="flex items-center space-x-4">
                                    <label class="flex items-center">
                                        <input type="checkbox" name="favorites" value="1" <?php echo $favorites_only ? 'checked' : ''; ?> 
                                               class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                        <span class="ml-2 text-sm text-gray-700">My Favorites Only</span>
                                    </label>
                                    
                                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                        Search
                                    </button>
                                </div>
                            </div>

                            <!-- Advanced Filters Panel -->
                            <div id="advancedFilters" class="<?php echo !empty(array_filter([$tags, $categories, $access_type, $visibility, $date_from, $date_to, $uploader, $file_type])) ? '' : 'hidden'; ?> space-y-6 pt-4 border-t border-gray-200">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                    
                                    <!-- Categories -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Categories</label>
                                        <div class="space-y-2 max-h-32 overflow-y-auto">
                                            <?php foreach (DOC_CATEGORIES as $key => $label): ?>
                                            <label class="flex items-center">
                                                <input type="checkbox" name="categories[]" value="<?php echo $key; ?>" 
                                                       <?php echo in_array($key, $categories) ? 'checked' : ''; ?>
                                                       class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                                <span class="ml-2 text-sm text-gray-700"><?php echo htmlspecialchars($label); ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Access Type -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Access Type</label>
                                        <select name="access_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                            <option value="">All Access Types</option>
                                            <?php foreach (DOC_ACCESS_TYPES as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $access_type === $key ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Visibility -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Visibility</label>
                                        <select name="visibility" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                            <option value="">All Visibility Levels</option>
                                            <?php foreach (DOC_ROLE_VISIBILITY as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $visibility === $key ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- File Type -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">File Type</label>
                                        <select name="file_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                            <option value="">All File Types</option>
                                            <?php foreach ($available_file_types as $type): ?>
                                            <option value="<?php echo $type['extension']; ?>" <?php echo $file_type === $type['extension'] ? 'selected' : ''; ?>>
                                                <?php echo strtoupper($type['extension']); ?> Files
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Uploader -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Uploaded By</label>
                                        <input type="text" name="uploader" value="<?php echo htmlspecialchars($uploader); ?>" 
                                               placeholder="Enter uploader name..." 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                               list="uploadersList">
                                        <datalist id="uploadersList">
                                            <?php foreach ($available_uploaders as $up): ?>
                                            <option value="<?php echo htmlspecialchars($up['full_name']); ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                    
                                    <!-- Date Range -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Date Range</label>
                                        <div class="space-y-2">
                                            <input type="date" name="date_from" value="<?php echo $date_from; ?>" 
                                                   placeholder="From date"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                            <input type="date" name="date_to" value="<?php echo $date_to; ?>" 
                                                   placeholder="To date"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                        </div>
                                    </div>
                                    
                                </div>
                                
                                <!-- Tags -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Tags</label>
                                    <div class="space-y-3">
                                        <!-- Selected Tags -->
                                        <div id="selectedTags" class="flex flex-wrap gap-2">
                                            <?php foreach ($tags as $tag): ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-blue-100 text-blue-800">
                                                <?php echo htmlspecialchars($tag); ?>
                                                <input type="hidden" name="tags[]" value="<?php echo htmlspecialchars($tag); ?>">
                                                <button type="button" onclick="removeTag(this)" class="ml-2 text-blue-600 hover:text-blue-800">
                                                    <i data-lucide="x" class="w-3 h-3"></i>
                                                </button>
                                            </span>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <!-- Tag Input -->
                                        <div class="flex">
                                            <input type="text" id="tagInput" placeholder="Type a tag and press Enter..." 
                                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-l-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                                   onkeydown="handleTagInput(event)">
                                            <button type="button" onclick="addCurrentTag()" 
                                                    class="px-4 py-2 bg-blue-600 text-white rounded-r-lg hover:bg-blue-700">
                                                Add
                                            </button>
                                        </div>
                                        
                                        <!-- Popular Tags -->
                                        <div>
                                            <p class="text-sm text-gray-600 mb-2">Popular tags:</p>
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach (array_slice($popular_tags, 0, 10) as $pop_tag): ?>
                                                <button type="button" onclick="addTag('<?php echo htmlspecialchars($pop_tag['tag']); ?>')" 
                                                        class="px-2 py-1 text-xs bg-gray-100 text-gray-700 rounded hover:bg-blue-100 hover:text-blue-700 transition-colors">
                                                    <?php echo htmlspecialchars($pop_tag['tag']); ?> (<?php echo $pop_tag['count']; ?>)
                                                </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                            </div>
                            
                        </form>
                    </div>
                </div>

                <!-- Search Results -->
                <div class="bg-white rounded-lg shadow">
                    <!-- Results Header -->
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h2 class="text-lg font-semibold text-gray-900">
                                Search Results
                                <span class="text-sm font-normal text-gray-500">
                                    (<?php echo $total_documents; ?> <?php echo $total_documents === 1 ? 'document' : 'documents'; ?> found)
                                </span>
                            </h2>
                            
                            <?php if (!empty($search) || !empty($tags) || !empty($categories) || $favorites_only): ?>
                            <a href="search.php" class="text-sm text-blue-600 hover:text-blue-800">Clear all filters</a>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Active Filters Display -->
                        <?php if (!empty($search) || !empty($tags) || !empty($categories) || $favorites_only): ?>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <?php if (!empty($search)): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs bg-gray-100 text-gray-800">
                                Search: "<?php echo htmlspecialchars($search); ?>"
                            </span>
                            <?php endif; ?>
                            
                            <?php foreach ($tags as $tag): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs bg-blue-100 text-blue-800">
                                Tag: <?php echo htmlspecialchars($tag); ?>
                            </span>
                            <?php endforeach; ?>
                            
                            <?php foreach ($categories as $category): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs bg-green-100 text-green-800">
                                Category: <?php echo htmlspecialchars(DOC_CATEGORIES[$category] ?? $category); ?>
                            </span>
                            <?php endforeach; ?>
                            
                            <?php if ($favorites_only): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs bg-yellow-100 text-yellow-800">
                                Favorites Only
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Documents Grid -->
                    <div class="p-6">
                        <?php if (empty($documents)): ?>
                        <!-- Empty State -->
                        <div class="text-center py-12">
                            <i data-lucide="search" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No documents found</h3>
                            <p class="text-gray-600 mb-6">Try adjusting your search criteria or filters.</p>
                            <a href="search.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                                Clear All Filters
                            </a>
                        </div>
                        <?php else: ?>
                        <!-- Documents Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                            <?php foreach ($documents as $doc): ?>
                            <div class="document-card bg-white border border-gray-200 rounded-lg p-4 hover:shadow-lg transition-all relative">
                                <!-- Pinned Indicator -->
                                <?php if ($doc['is_pinned']): ?>
                                <div class="absolute top-2 right-2 w-6 h-6 bg-gradient-to-r from-yellow-400 to-orange-500 rounded-full flex items-center justify-center">
                                    <i data-lucide="pin" class="w-3 h-3 text-white"></i>
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
                                        
                                        <!-- Popularity Indicator -->
                                        <?php if ($doc['favorite_count'] > 0): ?>
                                        <span class="text-xs text-gray-500"><?php echo $doc['favorite_count']; ?> ❤️</span>
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
                                        <a href="view.php?id=<?php echo $doc['id']; ?>&serve_file=1" 
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
        
        // Toggle advanced filters
        function toggleAdvancedFilters() {
            const filters = document.getElementById('advancedFilters');
            const chevron = document.getElementById('filterChevron');
            const toggle = document.querySelector('.filter-toggle');
            
            if (filters.classList.contains('hidden')) {
                filters.classList.remove('hidden');
                chevron.setAttribute('data-lucide', 'chevron-up');
                toggle.classList.add('active');
            } else {
                filters.classList.add('hidden');
                chevron.setAttribute('data-lucide', 'chevron-down');
                toggle.classList.remove('active');
            }
            
            lucide.createIcons();
        }
        
        // Tag management
        function addTag(tag) {
            tag = tag.trim();
            if (!tag) return;
            
            // Check if tag already exists
            const existingTags = Array.from(document.querySelectorAll('#selectedTags input[name="tags[]"]')).map(input => input.value);
            if (existingTags.includes(tag)) return;
            
            const container = document.getElementById('selectedTags');
            const tagElement = document.createElement('span');
            tagElement.className = 'inline-flex items-center px-3 py-1 rounded-full text-sm bg-blue-100 text-blue-800';
            tagElement.innerHTML = `
                ${escapeHtml(tag)}
                <input type="hidden" name="tags[]" value="${escapeHtml(tag)}">
                <button type="button" onclick="removeTag(this)" class="ml-2 text-blue-600 hover:text-blue-800">
                    <i data-lucide="x" class="w-3 h-3"></i>
                </button>
            `;
            container.appendChild(tagElement);
            lucide.createIcons();
            
            document.getElementById('tagInput').value = '';
        }
        
        function removeTag(button) {
            button.closest('span').remove();
        }
        
        function addCurrentTag() {
            const input = document.getElementById('tagInput');
            addTag(input.value);
        }
        
        function handleTagInput(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addCurrentTag();
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
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
        
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('open');
        }
        
        // Auto-show advanced filters if any are active
        <?php if (!empty(array_filter([$tags, $categories, $access_type, $visibility, $date_from, $date_to, $uploader, $file_type]))): ?>
        document.querySelector('.filter-toggle').classList.add('active');
        <?php endif; ?>
    </script>
</body>
</html>