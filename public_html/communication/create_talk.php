<?php
// /public_html/communication/create_talk.php
// Create and Distribute Safety Talk

// Include core configuration (automatically loads navigation)
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../config/communication.php';
require_once __DIR__ . '/../../src/communication.php';

// Ensure user is logged in and has communication access
if (!isUserLoggedIn()) {
    header('Location: /login.php');
    exit;
}

requireCommAdminAccess();

// Initialize variables
$message = '';
$error = '';
$warning = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $custom_content = $_POST['custom_content'] ?? '';
    $talk_type = $_POST['talk_type'] ?? 'content_only';
    $employee_ids = $_POST['employee_ids'] ?? [];
    
    // Validate input
    if (empty($title)) {
        $error = "Title is required.";
    } elseif (empty($employee_ids)) {
        $error = "Please select at least one employee to receive this safety talk.";
    } else {
        // Prepare content data
        $content = ['type' => $talk_type, 'data' => ''];
        
        if ($talk_type === 'file' && isset($_FILES['attachment'])) {
            $content['data'] = $_FILES['attachment'];
        } elseif ($talk_type === 'website') {
            $content['data'] = $_POST['website_url'] ?? '';
            if (empty($content['data']) || !filter_var($content['data'], FILTER_VALIDATE_URL)) {
                $error = "Please provide a valid website URL.";
            }
        }
        
        // Prepare quiz data if provided
        $quiz_data = [];
        if (!empty($_POST['quiz_questions'])) {
            $quiz_data = [
                'questions' => $_POST['quiz_questions'],
                'answers' => $_POST['quiz_answers'] ?? [],
                'correct_answers' => $_POST['correct_answers'] ?? []
            ];
        }
        
        if (!$error) {
            // Create the safety talk
            $talk_id = addSafetyTalk($title, $custom_content, $_SESSION['user_id'], $conn, $content, $quiz_data);
            
            if ($talk_id) {
                // Distribute to selected employees
                $result = distributeTalk($talk_id, $employee_ids, $conn);
                
                if ($result['success_count'] > 0) {
                    $message = "Safety talk created and distributed successfully to {$result['success_count']} employees.";
                    if (!empty($result['skipped'])) {
                        $warning = "The following employees had already received this talk and were skipped: " . implode(', ', $result['skipped']);
                    }
                    if (!empty($result['errors'])) {
                        $error = "Some notifications failed: " . implode(', ', $result['errors']);
                    }
                } elseif (!$error) {
                    $error = "Failed to distribute the safety talk.";
                }
            } else {
                $error = "Failed to create the safety talk.";
            }
        }
    }
}

// Get list of employees for distribution
$employees = getCommEmployees($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Safety Talk - Safety Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@0.378.0/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.tiny.cloud/1/x714o0198ntd2m36i96zsv3gapt1q51sgbjupl2obyew5078/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .sidebar { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) { 
            .sidebar { transform: translateX(-100%); } 
            .sidebar.open { transform: translateX(0); } 
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen">
        
        <!-- Automatic Navigation -->
        <?php renderNavigation(); ?>
        
        <!-- Main Content Area -->
        <main class="flex-1 overflow-auto p-6">
            <div class="max-w-4xl mx-auto">
                
                <!-- Header -->
                <div class="mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">Create Safety Talk</h1>
                            <p class="text-gray-600 mt-2">Create and distribute a new safety communication</p>
                        </div>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                            Back to Dashboard
                        </a>
                    </div>
                </div>
                
                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($warning): ?>
                    <div class="mb-6 bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg">
                        <?php echo htmlspecialchars($warning); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Form -->
                <form method="POST" enctype="multipart/form-data" class="space-y-8">
                    
                    <!-- Basic Information -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-semibold text-gray-900 mb-4">Basic Information</h2>
                        
                        <div class="space-y-4">
                            <div>
                                <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                                <input type="text" id="title" name="title" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="Enter safety talk title">
                            </div>
                            
                            <div>
                                <label for="custom_content" class="block text-sm font-medium text-gray-700 mb-2">Content</label>
                                <textarea id="custom_content" name="custom_content" rows="8"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                          placeholder="Enter safety talk content"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attachment Options -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-semibold text-gray-900 mb-4">Attachment (Optional)</h2>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-3">Content Type</label>
                                <div class="space-y-2">
                                    <label class="flex items-center">
                                        <input type="radio" name="talk_type" value="content_only" checked
                                               class="text-blue-600 focus:ring-blue-500">
                                        <span class="ml-2 text-sm text-gray-700">Content Only</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="radio" name="talk_type" value="file"
                                               class="text-blue-600 focus:ring-blue-500">
                                        <span class="ml-2 text-sm text-gray-700">File Attachment (PDF or MP4)</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="radio" name="talk_type" value="website"
                                               class="text-blue-600 focus:ring-blue-500">
                                        <span class="ml-2 text-sm text-gray-700">Website Link</span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- File Upload -->
                            <div id="file_section" class="hidden">
                                <label for="attachment" class="block text-sm font-medium text-gray-700 mb-2">Upload File</label>
                                <input type="file" id="attachment" name="attachment" accept=".pdf,.mp4"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <p class="text-sm text-gray-500 mt-1">Maximum file size: <?php echo formatCommFileSize(getCommMaxFileSize()); ?>. Allowed formats: PDF, MP4</p>
                            </div>
                            
                            <!-- Website URL -->
                            <div id="website_section" class="hidden">
                                <label for="website_url" class="block text-sm font-medium text-gray-700 mb-2">Website URL</label>
                                <input type="url" id="website_url" name="website_url"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="https://example.com">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Employee Selection -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-semibold text-gray-900 mb-4">Select Recipients</h2>
                        
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-700">Employees</span>
                                <div class="space-x-2">
                                    <button type="button" onclick="selectAllEmployees()" class="text-sm text-blue-600 hover:text-blue-800">Select All</button>
                                    <button type="button" onclick="deselectAllEmployees()" class="text-sm text-blue-600 hover:text-blue-800">Deselect All</button>
                                </div>
                            </div>
                            
                            <div class="max-h-64 overflow-y-auto border border-gray-200 rounded-lg p-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                    <?php foreach ($employees as $employee): ?>
                                    <label class="flex items-center p-2 hover:bg-gray-50 rounded">
                                        <input type="checkbox" name="employee_ids[]" value="<?php echo $employee['id']; ?>"
                                               class="text-blue-600 focus:ring-blue-500 mr-3">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($employee['name']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($employee['email']); ?></div>
                                        </div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <?php if (empty($employees)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i data-lucide="users" class="w-12 h-12 mx-auto mb-2"></i>
                                <p>No employees found. Please add employees to the system first.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Quiz Section (Optional) -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-xl font-semibold text-gray-900">Quiz (Optional)</h2>
                            <button type="button" id="add_quiz_btn" onclick="addQuizQuestion()" class="inline-flex items-center px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100">
                                <i data-lucide="plus" class="w-4 h-4 mr-1"></i>
                                Add Question
                            </button>
                        </div>
                        
                        <div id="quiz_container" class="space-y-4">
                            <!-- Quiz questions will be added here dynamically -->
                        </div>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-600">
                                Safety talk will be distributed immediately to selected employees
                            </div>
                            <div class="space-x-3">
                                <a href="index.php" class="px-6 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                                    Cancel
                                </a>
                                <button type="submit" class="px-6 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700">
                                    Create & Distribute
                                </button>
                            </div>
                        </div>
                    </div>
                    
                </form>
                
            </div>
        </main>
        
    </div>
    
    <script>
        // Initialize TinyMCE
        tinymce.init({
            selector: '#custom_content',
            plugins: 'lists link image table code help wordcount',
            toolbar: 'undo redo | blocks | bold italic | alignleft aligncenter alignright | indent outdent | bullist numlist | code | help',
            height: 300
        });
        
        // Handle content type radio buttons
        document.querySelectorAll('input[name="talk_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.getElementById('file_section').classList.add('hidden');
                document.getElementById('website_section').classList.add('hidden');
                
                if (this.value === 'file') {
                    document.getElementById('file_section').classList.remove('hidden');
                } else if (this.value === 'website') {
                    document.getElementById('website_section').classList.remove('hidden');
                }
            });
        });
        
        // Employee selection functions
        function selectAllEmployees() {
            document.querySelectorAll('input[name="employee_ids[]"]').forEach(checkbox => {
                checkbox.checked = true;
            });
        }
        
        function deselectAllEmployees() {
            document.querySelectorAll('input[name="employee_ids[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
        }
        
        // Quiz functionality
        let quizQuestionCount = 0;
        
        function addQuizQuestion() {
            quizQuestionCount++;
            const container = document.getElementById('quiz_container');
            
            const questionDiv = document.createElement('div');
            questionDiv.className = 'border border-gray-200 rounded-lg p-4';
            questionDiv.id = `question_${quizQuestionCount}`;
            
            questionDiv.innerHTML = `
                <div class="flex items-center justify-between mb-3">
                    <h4 class="font-medium text-gray-900">Question ${quizQuestionCount}</h4>
                    <button type="button" onclick="removeQuizQuestion(${quizQuestionCount})" class="text-red-600 hover:text-red-800">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                </div>
                
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Question</label>
                        <input type="text" name="quiz_questions[]" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Enter question">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Answer Options</label>
                        <div class="space-y-2">
                            <div class="flex items-center space-x-2">
                                <input type="radio" name="correct_answers[${quizQuestionCount-1}]" value="0" required
                                       class="text-blue-600 focus:ring-blue-500">
                                <input type="text" name="quiz_answers[${quizQuestionCount-1}][]" required
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="Answer option A">
                            </div>
                            <div class="flex items-center space-x-2">
                                <input type="radio" name="correct_answers[${quizQuestionCount-1}]" value="1" required
                                       class="text-blue-600 focus:ring-blue-500">
                                <input type="text" name="quiz_answers[${quizQuestionCount-1}][]" required
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="Answer option B">
                            </div>
                            <div class="flex items-center space-x-2">
                                <input type="radio" name="correct_answers[${quizQuestionCount-1}]" value="2"
                                       class="text-blue-600 focus:ring-blue-500">
                                <input type="text" name="quiz_answers[${quizQuestionCount-1}][]"
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="Answer option C (optional)">
                            </div>
                            <div class="flex items-center space-x-2">
                                <input type="radio" name="correct_answers[${quizQuestionCount-1}]" value="3"
                                       class="text-blue-600 focus:ring-blue-500">
                                <input type="text" name="quiz_answers[${quizQuestionCount-1}][]"
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="Answer option D (optional)">
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Select the radio button next to the correct answer</p>
                    </div>
                </div>
            `;
            
            container.appendChild(questionDiv);
            
            // Re-initialize Lucide icons for the new elements
            lucide.createIcons();
        }
        
        function removeQuizQuestion(questionNumber) {
            const questionDiv = document.getElementById(`question_${questionNumber}`);
            if (questionDiv) {
                questionDiv.remove();
            }
        }
        
        // Initialize Lucide icons
        lucide.createIcons();
    </script>
</body>
</html>