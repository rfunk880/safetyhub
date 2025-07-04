<?php
// /public_html/communication/edit_talk.php
// Edit Safety Talk (before distribution)

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../config/communication.php';
require_once __DIR__ . '/../../src/communication.php';

if (!isUserLoggedIn()) {
    header('Location: /login.php');
    exit;
}

requireCommAdminAccess();

$talk_id = $_GET['id'] ?? 0;
if (!$talk_id) {
    header('Location: index.php?error=' . urlencode('Invalid safety talk.'));
    exit;
}

// Get talk details
$talk_details = getSafetyTalkById($talk_id, $conn);
if (!$talk_details || $talk_details['status'] !== 'draft') {
    header('Location: index.php?error=' . urlencode('Safety talk not found or already distributed.'));
    exit;
}

// Get quiz data if exists
$quiz_data = [];
if ($talk_details['has_quiz']) {
    $quiz_data = getQuizForTalk($talk_id, $conn);
}

// Get pending employee IDs from session
$selected_employee_ids = $_SESSION['pending_distribution']['employee_ids'] ?? [];

$message = '';
$error = '';

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
        // Update the safety talk
        $update_success = updateSafetyTalkTitle($talk_id, $title, $conn);
        $update_success = updateSafetyTalkContent($talk_id, $custom_content, $conn) && $update_success;
        
        // Handle file updates if needed
        if ($talk_type === 'file' && isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
            // Process new file upload
            $file_info = $_FILES['attachment'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_info['tmp_name']);
            finfo_close($finfo);
            
            $allowed_mime_types = ['application/pdf', 'video/mp4'];
            if (in_array($mime_type, $allowed_mime_types)) {
                // Check file size
                if ($file_info['size'] <= COMM_MAX_FILE_SIZE) {
                    // Delete old file if exists
                    if (!empty($talk_details['file_path']) && $talk_details['file_type'] !== 'website') {
                        $old_filename = '';
                        if (strpos($talk_details['file_path'], '/serve_safety_talk.php?file=') === 0) {
                            parse_str(parse_url($talk_details['file_path'], PHP_URL_QUERY), $params);
                            $old_filename = $params['file'] ?? '';
                        } else {
                            $old_filename = basename($talk_details['file_path']);
                        }
                        if ($old_filename) {
                            $old_file_path = COMMUNICATION_UPLOAD_DIR . $old_filename;
                            if (file_exists($old_file_path)) {
                                unlink($old_file_path);
                            }
                        }
                    }
                    
                    // Upload new file
                    $file_name = time() . '_' . basename($file_info['name']);
                    $target_file = COMMUNICATION_UPLOAD_DIR . $file_name;
                    $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
                    
                    if (move_uploaded_file($file_info['tmp_name'], $target_file)) {
                        $file_path = '/serve_safety_talk.php?file=' . urlencode($file_name);
                        updateSafetyTalkAttachment($talk_id, $file_path, $file_type, $conn);
                    } else {
                        $error = "Failed to upload file.";
                    }
                } else {
                    $error = "File too large. Maximum size: " . formatCommFileSize(COMM_MAX_FILE_SIZE);
                }
            } else {
                $error = "Invalid file type. Only PDF and MP4 files are allowed.";
            }
        } elseif ($talk_type === 'website') {
            $website_url = $_POST['website_url'] ?? '';
            if (!empty($website_url) && filter_var($website_url, FILTER_VALIDATE_URL)) {
                updateSafetyTalkAttachment($talk_id, $website_url, 'website', $conn);
            } elseif (!empty($website_url)) {
                $error = "Please provide a valid website URL.";
            }
        } elseif ($talk_type === 'content_only') {
            // Remove existing attachment
            updateSafetyTalkAttachment($talk_id, '', '', $conn);
        }
        
        // Handle quiz updates
        if (!empty($_POST['quiz_questions'])) {
            $new_quiz_data = [
                'questions' => $_POST['quiz_questions'],
                'answers' => $_POST['quiz_answers'] ?? [],
                'correct_answers' => $_POST['correct_answers'] ?? []
            ];
            updateQuiz($talk_id, $new_quiz_data, $conn);
            
            // Update has_quiz flag
            $stmt = $conn->prepare("UPDATE safety_talks SET has_quiz = 1 WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $talk_id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            // Remove quiz
            $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE safety_talk_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $talk_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Update has_quiz flag
            $stmt = $conn->prepare("UPDATE safety_talks SET has_quiz = 0 WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $talk_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // Update session with new employee selection
        $_SESSION['pending_distribution']['employee_ids'] = $employee_ids;
        
        if (!$error && $update_success) {
            header('Location: preview_talk.php?id=' . $talk_id . '&message=' . urlencode('Safety talk updated successfully.'));
            exit;
        } elseif (!$error) {
            $error = "Failed to update safety talk.";
        }
    }
}

$employees = getCommEmployees($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Safety Talk - Safety Hub</title>
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
        
        <!-- Navigation -->
        <?php renderNavigation(); ?>
        
        <!-- Main Content -->
        <main class="flex-1 overflow-auto p-6">
            <div class="max-w-4xl mx-auto">
                
                <!-- Header -->
                <div class="mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">Edit Safety Talk</h1>
                            <p class="text-gray-600 mt-2">Make changes before distributing to employees</p>
                        </div>
                        <a href="preview_talk.php?id=<?php echo $talk_id; ?>" class="inline-flex items-center px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                            Back to Preview
                        </a>
                    </div>
                </div>
                
                <!-- Process Steps -->
                <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">Safety Talk Creation Process</h2>
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center text-sm font-medium">✏️</div>
                            <span class="ml-2 text-sm font-medium text-blue-600">Edit Content</span>
                        </div>
                        <i data-lucide="arrow-right" class="w-4 h-4 text-gray-400"></i>
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-gray-300 text-white rounded-full flex items-center justify-center text-sm font-medium">2</div>
                            <span class="ml-2 text-sm text-gray-500">Preview & Test</span>
                        </div>
                        <i data-lucide="arrow-right" class="w-4 h-4 text-gray-400"></i>
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-gray-300 text-white rounded-full flex items-center justify-center text-sm font-medium">3</div>
                            <span class="ml-2 text-sm text-gray-500">Distribute</span>
                        </div>
                    </div>
                </div>
                
                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Edit Form -->
                <form method="POST" enctype="multipart/form-data" class="space-y-8">
                    
                    <!-- Basic Information -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-semibold text-gray-900 mb-4">Basic Information</h2>
                        
                        <div class="space-y-4">
                            <div>
                                <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                                <input type="text" id="title" name="title" required
                                       value="<?php echo htmlspecialchars($talk_details['title']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="custom_content" class="block text-sm font-medium text-gray-700 mb-2">Content</label>
                                <textarea id="custom_content" name="custom_content" rows="8"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($talk_details['custom_content']); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attachment Options -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-semibold text-gray-900 mb-4">Attachment</h2>
                        
                        <?php if (!empty($talk_details['file_path'])): ?>
                            <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                <p class="text-blue-800">
                                    <strong>Current attachment:</strong> 
                                    <?php 
                                    if ($talk_details['file_type'] === 'website') {
                                        echo 'Website - ' . htmlspecialchars($talk_details['file_path']);
                                    } else {
                                        echo ucfirst($talk_details['file_type']) . ' file';
                                    }
                                    ?>
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-3">Content Type</label>
                                <div class="space-y-2">
                                    <label class="flex items-center">
                                        <input type="radio" name="talk_type" value="content_only" 
                                               <?php echo empty($talk_details['file_path']) ? 'checked' : ''; ?>
                                               class="text-blue-600 focus:ring-blue-500">
                                        <span class="ml-2 text-sm text-gray-700">Content Only</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="radio" name="talk_type" value="file"
                                               <?php echo (!empty($talk_details['file_path']) && $talk_details['file_type'] !== 'website') ? 'checked' : ''; ?>
                                               class="text-blue-600 focus:ring-blue-500">
                                        <span class="ml-2 text-sm text-gray-700">File Attachment (PDF or MP4)</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="radio" name="talk_type" value="website"
                                               <?php echo ($talk_details['file_type'] === 'website') ? 'checked' : ''; ?>
                                               class="text-blue-600 focus:ring-blue-500">
                                        <span class="ml-2 text-sm text-gray-700">Website Link</span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- File Upload -->
                            <div id="file_section" class="<?php echo (empty($talk_details['file_path']) || $talk_details['file_type'] === 'website') ? 'hidden' : ''; ?>">
                                <label for="attachment" class="block text-sm font-medium text-gray-700 mb-2">Upload New File (optional)</label>
                                <input type="file" id="attachment" name="attachment" accept=".pdf,.mp4"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <p class="text-sm text-gray-500 mt-1">Leave empty to keep current file. Maximum file size: <?php echo formatCommFileSize(getCommMaxFileSize()); ?>. Allowed formats: PDF, MP4</p>
                            </div>
                            
                            <!-- Website URL -->
                            <div id="website_section" class="<?php echo ($talk_details['file_type'] !== 'website') ? 'hidden' : ''; ?>">
                                <label for="website_url" class="block text-sm font-medium text-gray-700 mb-2">Website URL</label>
                                <input type="url" id="website_url" name="website_url"
                                       value="<?php echo ($talk_details['file_type'] === 'website') ? htmlspecialchars($talk_details['file_path']) : ''; ?>"
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
                                               <?php echo in_array($employee['id'], $selected_employee_ids) ? 'checked' : ''; ?>
                                               class="text-blue-600 focus:ring-blue-500 mr-3">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($employee['name']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($employee['email']); ?></div>
                                        </div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quiz Section -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-xl font-semibold text-gray-900">Quiz (Optional)</h2>
                            <button type="button" id="add_quiz_btn" onclick="addQuizQuestion()" class="inline-flex items-center px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100">
                                <i data-lucide="plus" class="w-4 h-4 mr-1"></i>
                                Add Question
                            </button>
                        </div>
                        
                        <div id="quiz_container" class="space-y-4">
                            <?php if (!empty($quiz_data['questions'])): ?>
                                <?php foreach ($quiz_data['questions'] as $q_index => $question): ?>
                                <div class="border border-gray-200 rounded-lg p-4" id="question_<?php echo $q_index + 1; ?>">
                                    <div class="flex items-center justify-between mb-3">
                                        <h4 class="font-medium text-gray-900">Question <?php echo $q_index + 1; ?></h4>
                                        <button type="button" onclick="removeQuizQuestion(<?php echo $q_index + 1; ?>)" class="text-red-600 hover:text-red-800">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="space-y-3">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Question</label>
                                            <input type="text" name="quiz_questions[]" required
                                                   value="<?php echo htmlspecialchars($question['question_text']); ?>"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Answer Options</label>
                                            <div class="space-y-2">
                                                <?php 
                                                $correct_answer_index = -1;
                                                foreach ($question['answers'] as $a_index => $answer) {
                                                    if ($answer['is_correct']) $correct_answer_index = $a_index;
                                                }
                                                ?>
                                                
                                                <?php foreach ($question['answers'] as $a_index => $answer): ?>
                                                <div class="flex items-center space-x-2">
                                                    <input type="radio" name="correct_answers[<?php echo $q_index; ?>]" value="<?php echo $a_index; ?>" 
                                                           <?php echo $answer['is_correct'] ? 'checked' : ''; ?>
                                                           class="text-blue-600 focus:ring-blue-500">
                                                    <input type="text" name="quiz_answers[<?php echo $q_index; ?>][]" 
                                                           value="<?php echo htmlspecialchars($answer['answer_text']); ?>"
                                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                                </div>
                                                <?php endforeach; ?>
                                                
                                                <!-- Add empty options if less than 4 -->
                                                <?php for ($i = count($question['answers']); $i < 4; $i++): ?>
                                                <div class="flex items-center space-x-2">
                                                    <input type="radio" name="correct_answers[<?php echo $q_index; ?>]" value="<?php echo $i; ?>"
                                                           class="text-blue-600 focus:ring-blue-500">
                                                    <input type="text" name="quiz_answers[<?php echo $q_index; ?>][]"
                                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                           placeholder="Answer option <?php echo chr(65 + $i); ?> (optional)">
                                                </div>
                                                <?php endfor; ?>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-1">Select the radio button next to the correct answer</p>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-600">
                                Changes will be saved and you'll return to the preview page
                            </div>
                            <div class="space-x-3">
                                <a href="preview_talk.php?id=<?php echo $talk_id; ?>" class="px-6 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                                    Cancel
                                </a>
                                <button type="submit" class="px-6 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700">
                                    Save Changes
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
        let quizQuestionCount = <?php echo count($quiz_data['questions'] ?? []); ?>;
        
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