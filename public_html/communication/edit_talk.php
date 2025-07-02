<?php
// /public_html/communication/edit_talk.php
// Comprehensive Safety Talk Editor

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

// Get talk ID from URL
$talk_id = $_GET['id'] ?? 0;

if (!$talk_id) {
    header('Location: history.php?error=' . urlencode('No safety talk specified.'));
    exit;
}

// Initialize variables
$message = '';
$error = '';

// Get talk details
$talk = getSafetyTalkById($talk_id, $conn);

if (!$talk) {
    header('Location: history.php?error=' . urlencode('Safety talk not found.'));
    exit;
}

// Get existing quiz if it exists
$quiz = null;
if ($talk['has_quiz']) {
    $quiz = getQuizForTalk($talk_id, $conn);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_talk') {
        $title = trim($_POST['title'] ?? '');
        $custom_content = $_POST['custom_content'] ?? '';
        $talk_type = $_POST['talk_type'] ?? $talk['file_type'];
        $website_url = trim($_POST['website_url'] ?? '');
        $has_quiz = isset($_POST['has_quiz']);
        
        // Validate required fields
        if (empty($title)) {
            $error = "Title is required.";
        } elseif ($talk_type === 'website' && empty($website_url)) {
            $error = "Website URL is required.";
        } elseif ($talk_type === 'website' && !filter_var($website_url, FILTER_VALIDATE_URL)) {
            $error = "Please provide a valid website URL.";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Handle file upload if new file provided
                $new_file_path = $talk['file_path'];
                if ($talk_type === 'file' && isset($_FILES['new_file']) && $_FILES['new_file']['error'] == UPLOAD_ERR_OK) {
                    $file_info = $_FILES['new_file'];
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $file_info['tmp_name']);
                    finfo_close($finfo);
                    
                    $allowed_mime_types = ['application/pdf', 'video/mp4'];
                    if (!in_array($mime_type, $allowed_mime_types)) {
                        throw new Exception("Invalid file type. Only PDF and MP4 files are allowed.");
                    }
                    
                    if ($file_info['size'] > COMM_MAX_FILE_SIZE) {
                        throw new Exception("File too large. Maximum size is " . (COMM_MAX_FILE_SIZE / 1024 / 1024) . "MB.");
                    }
                    
                    // Delete old file if it exists and is not a website
                    if ($talk['file_type'] !== 'website' && !empty($talk['file_path'])) {
                        $old_file = str_replace('/serve_safety_talk.php?file=', '', $talk['file_path']);
                        $old_file = str_replace(COMMUNICATION_UPLOAD_URL, COMMUNICATION_UPLOAD_DIR, $old_file);
                        if (file_exists($old_file)) {
                            unlink($old_file);
                        }
                    }
                    
                    // Upload new file
                    $file_name = time() . '_' . basename($file_info['name']);
                    $target_file = COMMUNICATION_UPLOAD_DIR . $file_name;
                    
                    if (!move_uploaded_file($file_info['tmp_name'], $target_file)) {
                        throw new Exception("Failed to upload file.");
                    }
                    
                    $new_file_path = '/serve_safety_talk.php?file=' . urlencode($file_name);
                }
                
                // Handle website URL
                if ($talk_type === 'website') {
                    $new_file_path = $website_url;
                }
                
                // Update safety talk
                $stmt = $conn->prepare("
                    UPDATE safety_talks 
                    SET title = ?, custom_content = ?, file_path = ?, file_type = ?, has_quiz = ?
                    WHERE id = ?
                ");
                $has_quiz_int = $has_quiz ? 1 : 0;
                $stmt->bind_param("ssssii", $title, $custom_content, $new_file_path, $talk_type, $has_quiz_int, $talk_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update safety talk.");
                }
                $stmt->close();
                
                // Handle quiz updates
                if ($has_quiz && !empty($_POST['quiz_questions'])) {
                    // Delete existing quiz
                    $stmt = $conn->prepare("DELETE FROM quiz_answers WHERE question_id IN (SELECT id FROM quiz_questions WHERE safety_talk_id = ?)");
                    $stmt->bind_param("i", $talk_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE safety_talk_id = ?");
                    $stmt->bind_param("i", $talk_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Save new quiz
                    $quiz_data = [
                        'questions' => $_POST['quiz_questions'],
                        'answers' => $_POST['quiz_answers'] ?? [],
                        'correct_answers' => $_POST['correct_answers'] ?? []
                    ];
                    saveQuiz($talk_id, $quiz_data, $conn);
                } elseif (!$has_quiz) {
                    // Remove quiz if unchecked
                    $stmt = $conn->prepare("DELETE FROM quiz_answers WHERE question_id IN (SELECT id FROM quiz_questions WHERE safety_talk_id = ?)");
                    $stmt->bind_param("i", $talk_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE safety_talk_id = ?");
                    $stmt->bind_param("i", $talk_id);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Commit transaction
                $conn->commit();
                
                $message = "Safety talk updated successfully.";
                
                // Refresh talk data
                $talk = getSafetyTalkById($talk_id, $conn);
                if ($talk['has_quiz']) {
                    $quiz = getQuizForTalk($talk_id, $conn);
                } else {
                    $quiz = null;
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}
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
        
        <!-- Automatic Navigation -->
        <?php renderNavigation(); ?>
        
        <!-- Main Content Area -->
        <main class="flex-1 overflow-auto p-6">
            <div class="max-w-4xl mx-auto">
                
                <!-- Header -->
                <div class="mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-center space-x-2 text-sm text-gray-600 mb-2">
                                <a href="history.php" class="hover:text-blue-600">Safety Talks</a>
                                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                <a href="talk_details.php?id=<?php echo $talk_id; ?>" class="hover:text-blue-600">Talk Details</a>
                                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                <span>Edit</span>
                            </div>
                            <h1 class="text-3xl font-bold text-gray-900">Edit Safety Talk</h1>
                            <p class="text-gray-600 mt-2">Modify the content, attachments, and quiz for this safety communication</p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="talk_details.php?id=<?php echo $talk_id; ?>" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white font-medium rounded-lg hover:bg-gray-700">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                                Back to Details
                            </a>
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
                <form method="POST" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="action" value="update_talk">
                    
                    <!-- Basic Information -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-semibold text-gray-900 mb-4">Basic Information</h2>
                        
                        <div class="space-y-4">
                            <div>
                                <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                                <input type="text" name="title" id="title" required 
                                       value="<?php echo htmlspecialchars($talk['title']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="custom_content" class="block text-sm font-medium text-gray-700 mb-2">Content</label>
                                <textarea name="custom_content" id="custom_content" rows="10"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($talk['custom_content']); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attachment -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-semibold text-gray-900 mb-4">Attachment</h2>
                        
                        <div class="space-y-4">
                            <!-- Current Attachment -->
                            <?php if (!empty($talk['file_path'])): ?>
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <h3 class="text-sm font-medium text-gray-900 mb-2">Current Attachment:</h3>
                                    <?php if ($talk['file_type'] === 'website'): ?>
                                        <p class="text-sm text-gray-600">Website: <a href="<?php echo htmlspecialchars($talk['file_path']); ?>" target="_blank" class="text-blue-600 hover:text-blue-800"><?php echo htmlspecialchars($talk['file_path']); ?></a></p>
                                    <?php else: ?>
                                        <p class="text-sm text-gray-600">File: <?php echo htmlspecialchars(basename($talk['file_path'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Attachment Type -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Attachment Type</label>
                                <div class="space-y-2">
                                    <label class="flex items-center">
                                        <input type="radio" name="talk_type" value="file" <?php echo ($talk['file_type'] !== 'website') ? 'checked' : ''; ?> class="text-blue-600 focus:ring-blue-500">
                                        <span class="ml-2">Upload a File (PDF or MP4)</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="radio" name="talk_type" value="website" <?php echo ($talk['file_type'] === 'website') ? 'checked' : ''; ?> class="text-blue-600 focus:ring-blue-500">
                                        <span class="ml-2">Use a Website Link</span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- File Upload -->
                            <div id="file-upload-section" <?php echo ($talk['file_type'] === 'website') ? 'style="display:none;"' : ''; ?>>
                                <label for="new_file" class="block text-sm font-medium text-gray-700 mb-2">Upload New File (Optional)</label>
                                <input type="file" name="new_file" id="new_file" accept=".pdf,.mp4"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-sm text-gray-600">Leave empty to keep current file. Supported formats: PDF, MP4</p>
                            </div>
                            
                            <!-- Website URL -->
                            <div id="website-url-section" <?php echo ($talk['file_type'] !== 'website') ? 'style="display:none;"' : ''; ?>>
                                <label for="website_url" class="block text-sm font-medium text-gray-700 mb-2">Website URL</label>
                                <input type="url" name="website_url" id="website_url" 
                                       value="<?php echo ($talk['file_type'] === 'website') ? htmlspecialchars($talk['file_path']) : ''; ?>"
                                       placeholder="https://example.com/safety-page"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quiz Section -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-semibold text-gray-900 mb-4">Quiz/Questionnaire</h2>
                        
                        <div class="space-y-4">
                            <label class="flex items-center">
                                <input type="checkbox" name="has_quiz" id="has_quiz" <?php echo $talk['has_quiz'] ? 'checked' : ''; ?> class="text-blue-600 focus:ring-blue-500">
                                <span class="ml-2 text-sm font-medium text-gray-700">Require a questionnaire for this safety talk</span>
                            </label>
                            
                            <div id="quiz-builder" <?php echo !$talk['has_quiz'] ? 'style="display:none;"' : ''; ?> class="border-2 border-dashed border-gray-300 rounded-lg p-4">
                                <div id="questions-container">
                                    <?php if ($quiz && !empty($quiz['questions'])): ?>
                                        <?php foreach ($quiz['questions'] as $q_index => $question): ?>
                                            <div class="question-block bg-gray-50 p-4 rounded-lg mb-4">
                                                <div class="flex justify-between items-center mb-3">
                                                    <h4 class="font-medium">Question <?php echo $q_index + 1; ?></h4>
                                                    <button type="button" class="remove-question text-red-600 hover:text-red-800">
                                                        <i data-lucide="x" class="w-4 h-4"></i>
                                                    </button>
                                                </div>
                                                
                                                <div class="space-y-3">
                                                    <input type="text" name="quiz_questions[]" placeholder="Enter question..." 
                                                           value="<?php echo htmlspecialchars($question['question_text']); ?>"
                                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                                    
                                                    <div class="answers-container space-y-2">
                                                        <?php foreach ($question['answers'] as $a_index => $answer): ?>
                                                            <div class="flex items-center space-x-2">
                                                                <input type="radio" name="correct_answers[<?php echo $q_index; ?>]" value="<?php echo $a_index; ?>" <?php echo $answer['is_correct'] ? 'checked' : ''; ?> class="text-blue-600 focus:ring-blue-500">
                                                                <input type="text" name="quiz_answers[<?php echo $q_index; ?>][]" 
                                                                       value="<?php echo htmlspecialchars($answer['answer_text']); ?>"
                                                                       placeholder="Answer option..." 
                                                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                                                <button type="button" class="remove-answer text-red-600 hover:text-red-800">
                                                                    <i data-lucide="minus-circle" class="w-4 h-4"></i>
                                                                </button>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    
                                                    <button type="button" class="add-answer text-blue-600 hover:text-blue-800 text-sm">
                                                        <i data-lucide="plus-circle" class="w-4 h-4 inline mr-1"></i>Add Answer Option
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <button type="button" id="add-question" class="mt-4 inline-flex items-center px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700">
                                    <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                                    Add Question
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="flex justify-end space-x-4">
                        <a href="talk_details.php?id=<?php echo $talk_id; ?>" class="px-6 py-2 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50">
                            Cancel
                        </a>
                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700">
                            Update Safety Talk
                        </button>
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
        
        // Initialize Lucide icons
        lucide.createIcons();
        
        let questionCounter = <?php echo ($quiz && !empty($quiz['questions'])) ? count($quiz['questions']) : 0; ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Handle attachment type switching
            const attachmentRadios = document.querySelectorAll('input[name="talk_type"]');
            const fileSection = document.getElementById('file-upload-section');
            const websiteSection = document.getElementById('website-url-section');
            
            attachmentRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.value === 'file') {
                        fileSection.style.display = 'block';
                        websiteSection.style.display = 'none';
                    } else {
                        fileSection.style.display = 'none';
                        websiteSection.style.display = 'block';
                    }
                });
            });
            
            // Handle quiz toggle
            document.getElementById('has_quiz').addEventListener('change', function() {
                const quizBuilder = document.getElementById('quiz-builder');
                quizBuilder.style.display = this.checked ? 'block' : 'none';
            });
            
            // Add question functionality
            document.getElementById('add-question').addEventListener('click', addQuestion);
            
            // Initialize existing question handlers
            updateQuestionHandlers();
        });
        
        function addQuestion() {
            questionCounter++;
            const container = document.getElementById('questions-container');
            
            const questionHtml = `
                <div class="question-block bg-gray-50 p-4 rounded-lg mb-4">
                    <div class="flex justify-between items-center mb-3">
                        <h4 class="font-medium">Question ${questionCounter}</h4>
                        <button type="button" class="remove-question text-red-600 hover:text-red-800">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                    
                    <div class="space-y-3">
                        <input type="text" name="quiz_questions[]" placeholder="Enter question..." 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        
                        <div class="answers-container space-y-2">
                            <div class="flex items-center space-x-2">
                                <input type="radio" name="correct_answers[${questionCounter - 1}]" value="0" class="text-blue-600 focus:ring-blue-500">
                                <input type="text" name="quiz_answers[${questionCounter - 1}][]" placeholder="Answer option..." 
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <button type="button" class="remove-answer text-red-600 hover:text-red-800">
                                    <i data-lucide="minus-circle" class="w-4 h-4"></i>
                                </button>
                            </div>
                            <div class="flex items-center space-x-2">
                                <input type="radio" name="correct_answers[${questionCounter - 1}]" value="1" class="text-blue-600 focus:ring-blue-500">
                                <input type="text" name="quiz_answers[${questionCounter - 1}][]" placeholder="Answer option..." 
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <button type="button" class="remove-answer text-red-600 hover:text-red-800">
                                    <i data-lucide="minus-circle" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="button" class="add-answer text-blue-600 hover:text-blue-800 text-sm">
                            <i data-lucide="plus-circle" class="w-4 h-4 inline mr-1"></i>Add Answer Option
                        </button>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', questionHtml);
            updateQuestionHandlers();
            lucide.createIcons();
        }
        
        function updateQuestionHandlers() {
            // Remove question handlers
            document.querySelectorAll('.remove-question').forEach(btn => {
                btn.onclick = function() {
                    this.closest('.question-block').remove();
                    renumberQuestions();
                };
            });
            
            // Add answer handlers
            document.querySelectorAll('.add-answer').forEach(btn => {
                btn.onclick = function() {
                    const container = this.previousElementSibling;
                    const questionIndex = Array.from(document.querySelectorAll('.question-block')).indexOf(this.closest('.question-block'));
                    const answerIndex = container.children.length;
                    
                    const answerHtml = `
                        <div class="flex items-center space-x-2">
                            <input type="radio" name="correct_answers[${questionIndex}]" value="${answerIndex}" class="text-blue-600 focus:ring-blue-500">
                            <input type="text" name="quiz_answers[${questionIndex}][]" placeholder="Answer option..." 
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <button type="button" class="remove-answer text-red-600 hover:text-red-800">
                                <i data-lucide="minus-circle" class="w-4 h-4"></i>
                            </button>
                        </div>
                    `;
                    
                    container.insertAdjacentHTML('beforeend', answerHtml);
                    updateQuestionHandlers();
                    lucide.createIcons();
                };
            });
            
            // Remove answer handlers
            document.querySelectorAll('.remove-answer').forEach(btn => {
                btn.onclick = function() {
                    const container = this.closest('.answers-container');
                    if (container.children.length > 2) {
                        this.closest('div').remove();
                        renumberAnswers(container);
                    }
                };
            });
        }
        
        function renumberQuestions() {
            document.querySelectorAll('.question-block').forEach((block, index) => {
                block.querySelector('h4').textContent = `Question ${index + 1}`;
                
                // Update radio button names and answer array names
                block.querySelectorAll('input[type="radio"]').forEach((radio, radioIndex) => {
                    radio.name = `correct_answers[${index}]`;
                    radio.value = radioIndex;
                });
                
                block.querySelectorAll('input[name^="quiz_answers"]').forEach(input => {
                    input.name = `quiz_answers[${index}][]`;
                });
            });
        }
        
        function renumberAnswers(container) {
            container.querySelectorAll('input[type="radio"]').forEach((radio, index) => {
                radio.value = index;
            });
        }
    </script>
</body>
</html>