<?php
// /public_html/documentation/upload.php
// Document Upload Feature - Admin Interface

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

// Check admin access
if (!hasDocAdminAccess()) {
    header('Location: /documentation/index.php?error=' . urlencode('Access denied. Only administrators can upload documents.'));
    exit;
}

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $tags = $_POST['tags'] ?? [];
    $access_type = $_POST['access_type'] ?? 'view_only';
    $visibility = $_POST['visibility'] ?? 'all';
    $date_modified = $_POST['date_modified'] ?? date('Y-m-d');
    $uploaded_by = $_SESSION['user_id'];
    
    // Validate required fields
    if (empty($title)) {
        $error = 'Document title is required.';
    } elseif (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please select a document file to upload.';
    } else {
        // Validate file
        $file = $_FILES['document'];
        
        // Check file size
        if ($file['size'] > DOC_MAX_FILE_SIZE) {
            $error = 'File is too large. Maximum size is ' . formatDocFileSize(DOC_MAX_FILE_SIZE) . '.';
        } 
        // Check file extension
        elseif (!isDocFileExtensionAllowed($file['name'])) {
            $error = 'File type not allowed. Allowed types: ' . implode(', ', DOC_ALLOWED_EXTENSIONS) . '.';
        }
        // Validate MIME type for security
        else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime_type, DOC_ALLOWED_FILE_TYPES)) {
                $error = 'Invalid file type detected. Please upload a valid document.';
            }
        }
        
        // If validation passes, upload the document
        if (empty($error)) {
            $document_id = addDocument(
                $title,
                $description,
                $tags,
                $access_type,
                $visibility,
                $date_modified,
                $file,
                $uploaded_by,
                $conn
            );
            
            if ($document_id) {
                header('Location: /documentation/index.php?message=' . urlencode('Document uploaded successfully!'));
                exit;
            } else {
                $error = 'Failed to upload document. Please try again.';
            }
        }
    }
}

// Get user info
$user_name = $_SESSION['user_first_name'] . ' ' . $_SESSION['user_last_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Document - Safety Hub</title>
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
        .file-drop-zone {
            transition: all 0.3s ease;
            border: 2px dashed #d1d5db;
        }
        .file-drop-zone.dragover {
            border-color: #3b82f6;
            background-color: #eff6ff;
        }
        .tag-input {
            min-height: 42px;
        }
        .tag {
            transition: all 0.2s ease;
        }
        .tag:hover {
            transform: scale(1.05);
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
                            <h1 class="text-3xl font-bold text-gray-900">Upload Document</h1>
                            <p class="text-gray-600 mt-1">Add a new safety document to the repository</p>
                        </div>
                        <a href="/documentation/index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                            Back to Documents
                        </a>
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

                <!-- Upload Form -->
                <div class="bg-white rounded-lg shadow">
                    <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                        
                        <!-- File Upload Section -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Document File <span class="text-red-500">*</span>
                            </label>
                            <div class="file-drop-zone rounded-lg p-8 text-center" id="fileDropZone" onclick="document.getElementById('documentFile').click()">
                                <div id="dropZoneContent">
                                    <i data-lucide="upload" class="w-12 h-12 text-gray-400 mx-auto mb-4"></i>
                                    <p class="text-lg font-medium text-gray-900 mb-2">Drop your document here</p>
                                    <p class="text-sm text-gray-600 mb-4">or click anywhere in this area to browse files</p>
                                    <input type="file" name="document" id="documentFile" accept="<?php echo implode(',', array_map(function($ext) { return '.' . $ext; }, DOC_ALLOWED_EXTENSIONS)); ?>" 
                                           class="hidden" required>
                                </div>
                                <div id="filePreview" class="hidden">
                                    <div class="flex items-center justify-center">
                                        <div class="flex items-center p-4 bg-blue-50 rounded-lg">
                                            <i data-lucide="file" class="w-8 h-8 text-blue-600 mr-3" id="fileIcon"></i>
                                            <div class="text-left">
                                                <p class="font-medium text-gray-900" id="fileName"></p>
                                                <p class="text-sm text-gray-600" id="fileSize"></p>
                                            </div>
                                            <button type="button" onclick="clearFile()" class="ml-4 p-1 text-gray-400 hover:text-gray-600">
                                                <i data-lucide="x" class="w-5 h-5"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p class="mt-2 text-sm text-gray-600">
                                Supported formats: <?php echo implode(', ', DOC_ALLOWED_EXTENSIONS); ?> 
                                (Max size: <?php echo formatDocFileSize(DOC_MAX_FILE_SIZE); ?>)
                            </p>
                        </div>

                        <!-- Document Details -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            
                            <!-- Left Column -->
                            <div class="space-y-6">
                                
                                <!-- Title -->
                                <div>
                                    <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                                        Document Title <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" name="title" id="title" required maxlength="255"
                                           value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                           placeholder="Enter document title...">
                                </div>
                                
                                <!-- Description -->
                                <div>
                                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                                        Description
                                    </label>
                                    <textarea name="description" id="description" rows="4"
                                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                              placeholder="Brief description of the document content..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                                </div>

                                <!-- Date Modified -->
                                <div>
                                    <label for="date_modified" class="block text-sm font-medium text-gray-700 mb-2">
                                        Date Last Modified
                                    </label>
                                    <input type="date" name="date_modified" id="date_modified"
                                           value="<?php echo $_POST['date_modified'] ?? date('Y-m-d'); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    <p class="mt-1 text-sm text-gray-600">Document's actual last modified date (defaults to today)</p>
                                </div>
                                
                            </div>
                            
                            <!-- Right Column -->
                            <div class="space-y-6">
                                
                                <!-- Access Type -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Access Type
                                    </label>
                                    <div class="space-y-3">
                                        <?php foreach (DOC_ACCESS_TYPES as $key => $label): ?>
                                        <label class="flex items-start">
                                            <input type="radio" name="access_type" value="<?php echo $key; ?>" 
                                                   <?php echo (!isset($_POST['access_type']) && $key === 'view_only') || ($_POST['access_type'] ?? '') === $key ? 'checked' : ''; ?>
                                                   class="mt-1 w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                                            <div class="ml-3">
                                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($label); ?></span>
                                                <p class="text-xs text-gray-600">
                                                    <?php
                                                    switch($key) {
                                                        case 'view_only':
                                                            echo 'Users can view but not download';
                                                            break;
                                                        case 'searchable':
                                                            echo 'Users can view and search content';
                                                            break;
                                                        case 'downloadable':
                                                            echo 'Users can view and download';
                                                            break;
                                                    }
                                                    ?>
                                                </p>
                                            </div>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Visibility -->
                                <div>
                                    <label for="visibility" class="block text-sm font-medium text-gray-700 mb-2">
                                        Visibility
                                    </label>
                                    <select name="visibility" id="visibility" 
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                        <?php foreach (DOC_ROLE_VISIBILITY as $key => $label): ?>
                                        <option value="<?php echo $key; ?>" <?php echo ($_POST['visibility'] ?? 'all') === $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="mt-1 text-sm text-gray-600">Who can see this document</p>
                                </div>

                                <!-- Tags -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Tags
                                    </label>
                                    <div class="tag-input border border-gray-300 rounded-lg p-3 focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-transparent">
                                        <div class="flex flex-wrap gap-2 mb-2" id="tagContainer">
                                            <!-- Tags will be added here dynamically -->
                                        </div>
                                        <input type="text" id="tagInput" placeholder="Type a tag and press Enter..."
                                               class="w-full border-0 outline-0 text-sm"
                                               onkeydown="handleTagInput(event)">
                                    </div>
                                    <p class="mt-2 text-sm text-gray-600">Add tags to help categorize and search for this document</p>
                                    
                                    <!-- Suggested Tags -->
                                    <div class="mt-3">
                                        <p class="text-sm font-medium text-gray-700 mb-2">Suggested Categories:</p>
                                        <div class="flex flex-wrap gap-2">
                                            <?php foreach (DOC_CATEGORIES as $key => $label): ?>
                                            <button type="button" onclick="addTag('<?php echo htmlspecialchars($key); ?>')" 
                                                    class="px-3 py-1 text-xs bg-gray-100 text-gray-700 rounded-full hover:bg-blue-100 hover:text-blue-700 transition-colors">
                                                <?php echo htmlspecialchars($label); ?>
                                            </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex items-center justify-end space-x-4 pt-6 border-t border-gray-200">
                            <a href="/documentation/index.php" 
                               class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                Cancel
                            </a>
                            <button type="submit" 
                                    class="inline-flex items-center px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                                Upload Document
                            </button>
                        </div>
                        
                    </form>
                </div>

            </div>
        </main>
    </div>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();
        
        // Tag management
        let tags = <?php echo json_encode($_POST['tags'] ?? []); ?>;
        
        function updateTagDisplay() {
            const container = document.getElementById('tagContainer');
            container.innerHTML = '';
            
            tags.forEach((tag, index) => {
                const tagElement = document.createElement('div');
                tagElement.className = 'tag inline-flex items-center px-3 py-1 rounded-full text-sm bg-blue-100 text-blue-800';
                tagElement.innerHTML = `
                    <span>${escapeHtml(tag)}</span>
                    <button type="button" onclick="removeTag(${index})" class="ml-2 text-blue-600 hover:text-blue-800">
                        <i data-lucide="x" class="w-3 h-3"></i>
                    </button>
                    <input type="hidden" name="tags[]" value="${escapeHtml(tag)}">
                `;
                container.appendChild(tagElement);
            });
            
            // Re-initialize Lucide icons for new elements
            lucide.createIcons();
        }
        
        function addTag(tag) {
            tag = tag.trim();
            if (tag && !tags.includes(tag)) {
                tags.push(tag);
                updateTagDisplay();
                document.getElementById('tagInput').value = '';
            }
        }
        
        function removeTag(index) {
            tags.splice(index, 1);
            updateTagDisplay();
        }
        
        function handleTagInput(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addTag(event.target.value);
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Initialize tag display
        updateTagDisplay();
        
        // File upload handling
        const fileInput = document.getElementById('documentFile');
        const dropZone = document.getElementById('fileDropZone');
        const dropZoneContent = document.getElementById('dropZoneContent');
        const filePreview = document.getElementById('filePreview');
        
        // File input change handler
        fileInput.addEventListener('change', handleFileSelect);
        
        // Drag and drop handlers
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        
        dropZone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
        });
        
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                handleFileSelect();
            }
        });
        
        function handleFileSelect() {
            const file = fileInput.files[0];
            if (file) {
                displayFilePreview(file);
            }
        }
        
        function displayFilePreview(file) {
            const fileName = document.getElementById('fileName');
            const fileSize = document.getElementById('fileSize');
            const fileIcon = document.getElementById('fileIcon');
            
            fileName.textContent = file.name;
            fileSize.textContent = formatFileSize(file.size);
            
            // Set appropriate icon based on file extension
            const extension = file.name.split('.').pop().toLowerCase();
            let iconName = 'file';
            switch(extension) {
                case 'pdf': iconName = 'file-text'; break;
                case 'doc':
                case 'docx': iconName = 'file-text'; break;
                case 'xls':
                case 'xlsx': iconName = 'sheet'; break;
                case 'ppt':
                case 'pptx': iconName = 'presentation'; break;
            }
            
            fileIcon.setAttribute('data-lucide', iconName);
            lucide.createIcons();
            
            dropZoneContent.classList.add('hidden');
            filePreview.classList.remove('hidden');
        }
        
        function clearFile() {
            fileInput.value = '';
            dropZoneContent.classList.remove('hidden');
            filePreview.classList.add('hidden');
        }
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        // Auto-populate title from filename
        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            const titleInput = document.getElementById('title');
            
            if (file && !titleInput.value.trim()) {
                // Remove extension and clean up the filename for title
                let title = file.name.replace(/\.[^/.]+$/, "");
                title = title.replace(/[_-]/g, ' ');
                title = title.replace(/\b\w/g, l => l.toUpperCase());
                titleInput.value = title;
            }
        });
        
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('open');
        }
    </script>
</body>
</html>