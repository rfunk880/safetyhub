<?php
// /public_html/communication/view_talk.php
// Employee Safety Talk Viewing and Confirmation Page for SafetyHub

// Include the SafetyHub configuration
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/communication.php';

// Get token from URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    die('Invalid access link.');
}

// Initialize variables
$message = '';
$error = '';

// Get distribution by token
try {
    $stmt = $conn->prepare("
        SELECT d.*, st.title as talk_title, st.custom_content, st.file_path, st.file_type, st.has_quiz,
               CONCAT(u.firstName, ' ', u.lastName) as employee_name, u.email as employee_email
        FROM distributions d
        JOIN safety_talks st ON d.safety_talk_id = st.id
        JOIN users u ON d.employee_id = u.id
        WHERE d.unique_link_token = ?
    ");
    
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $distribution = $result->fetch_assoc();
        $stmt->close();
    } else {
        $distribution = null;
    }
} catch (Exception $e) {
    $distribution = null;
    $error = "Database error: " . $e->getMessage();
}

if (!$distribution) {
    http_response_code(404);
    die('Safety talk not found or link has expired.');
}

// Load quiz data if the safety talk has a quiz
if ($distribution && $distribution['has_quiz']) {
    // Get quiz questions for this safety talk
    $stmt_quiz = $conn->prepare("
        SELECT qq.id, qq.question_text, qq.question_order
        FROM quiz_questions qq 
        WHERE qq.safety_talk_id = ? 
        ORDER BY qq.question_order
    ");
    if ($stmt_quiz) {
        $stmt_quiz->bind_param("i", $distribution['safety_talk_id']);
        $stmt_quiz->execute();
        $quiz_result = $stmt_quiz->get_result();
        
        $distribution['quiz'] = ['questions' => []];
        while ($question = $quiz_result->fetch_assoc()) {
            // Get answers for this question
            $stmt_answers = $conn->prepare("
                SELECT id, answer_text, is_correct, answer_order
                FROM quiz_answers 
                WHERE question_id = ? 
                ORDER BY answer_order
            ");
            if ($stmt_answers) {
                $stmt_answers->bind_param("i", $question['id']);
                $stmt_answers->execute();
                $answers_result = $stmt_answers->get_result();
                
                $question['answers'] = [];
                while ($answer = $answers_result->fetch_assoc()) {
                    $question['answers'][] = $answer;
                }
                $stmt_answers->close();
            }
            $distribution['quiz']['questions'][] = $question;
        }
        $stmt_quiz->close();
    }
}

// Check if already confirmed
$has_confirmed = false;
try {
    $stmt = $conn->prepare("SELECT id FROM confirmations WHERE distribution_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $distribution['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $has_confirmed = $result->num_rows > 0;
        $stmt->close();
    }
} catch (Exception $e) {
    $error = "Error checking confirmation: " . $e->getMessage();
}

// Handle file serving (for PDF/MP4 viewing)
if (isset($_GET['serve_file']) && $_GET['serve_file'] == '1') {
    if ($distribution['file_type'] !== 'website' && !empty($distribution['file_path'])) {
        
        // Extract filename from the stored file path
        $filename = '';
        if (strpos($distribution['file_path'], '/serve_safety_talk.php?file=') === 0) {
            // Parse the URL to get the filename parameter
            $url_parts = parse_url($distribution['file_path']);
            if (isset($url_parts['query'])) {
                parse_str($url_parts['query'], $params);
                $filename = $params['file'] ?? '';
                // URL decode the filename to handle spaces and special characters
                $filename = urldecode($filename);
            }
        } else {
            // Fallback: just use basename
            $filename = basename($distribution['file_path']);
        }
        
        if (!empty($filename)) {
            // Use the SafetyHub upload directory
            $full_path = COMMUNICATION_UPLOAD_DIR . $filename;
            
            if (file_exists($full_path)) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $full_path);
                finfo_close($finfo);
                
                header('Content-Type: ' . $mimeType);
                header('Content-Length: ' . filesize($full_path));
                header('Content-Disposition: inline; filename="' . basename($filename) . '"');
                header('Cache-Control: private, max-age=3600');
                
                if (ob_get_level()) {
                    ob_end_clean();
                }
                
                readfile($full_path);
                exit;
            }
        }
    }
    
    http_response_code(404);
    exit('File not found.');
}

// Handle form submission (confirmation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$has_confirmed) {
    $understood = isset($_POST['understood_material']) && $_POST['understood_material'];
    $signature_data_url = $_POST['signature_data_url'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'];
    
    // Basic validation
    if (!$understood) {
        $error = "You must confirm that you understand the material.";
    } elseif (empty($signature_data_url) || $signature_data_url === 'data:,') {
        $error = "Please provide your signature.";
    } else {
        // Save confirmation
        try {
            $stmt = $conn->prepare("INSERT INTO confirmations (distribution_id, signature_image_base64, ip_address, understood) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                $understood_int = $understood ? 1 : 0;
                $stmt->bind_param("issi", $distribution['id'], $signature_data_url, $ip_address, $understood_int);
                
                if ($stmt->execute()) {
                    $message = "Thank you for confirming the safety talk: " . htmlspecialchars($distribution['talk_title']);
                    $has_confirmed = true;
                } else {
                    $error = "Failed to submit confirmation: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = "Database error: " . $conn->error;
            }
        } catch (Exception $e) {
            $error = "Error saving confirmation: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safety Talk - <?php echo htmlspecialchars($distribution['talk_title'] ?? 'Safety Communication'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@0.378.0/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        
        /* Mobile-first responsive design */
        .max-w-4xl { max-width: 100%; }
        @media (min-width: 768px) {
            .max-w-4xl { max-width: 56rem; }
        }
        
        /* PDF viewer container for mobile optimization */
        .pdf-viewer-container {
            position: relative;
            width: 100%;
            background: #f8f9fa;
            border-radius: 0.5rem;
            overflow: hidden;
        }
        
        /* Mobile-optimized iframe */
        .pdf-viewer-container iframe {
            width: 100%;
            border: none;
            display: block;
        }
        
        /* Signature pad improvements */
        .signature-canvas { border: 1px solid #d1d5db; background: white; width: 100%; height: 150px; }
        .signature-tabs { 
            display: flex; 
            border-bottom: 1px solid #d1d5db; 
            margin-bottom: 1rem;
            overflow-x: auto; /* Allow horizontal scroll on small screens */
        }
        .signature-tab { 
            padding: 0.75rem 1rem; 
            cursor: pointer; 
            border-bottom: 2px solid transparent;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .signature-tab.active { border-bottom-color: #3b82f6; color: #3b82f6; font-weight: 500; }
        .signature-content { display: none; }
        .signature-content.active { display: block; }
        
        /* Improved signature pad styles for mobile */
        #signature-pad-container {
            width: 100%;
            min-height: 150px;
            border: 1px solid #d1d5db;
            background-color: #fff;
            touch-action: none; /* Prevent scrolling on touch */
            position: relative;
            border-radius: 0.375rem;
        }
        #signature-canvas {
            display: block;
            width: 100%;
            height: 150px;
            touch-action: none;
            cursor: crosshair;
            border-radius: 0.375rem;
        }
        .signature-typed-preview {
            font-family: 'Dancing Script', cursive;
            font-size: 2rem;
            text-align: center;
            padding: 2rem;
            background: #f9fafb;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            min-height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            word-break: break-word; /* Handle long names on mobile */
        }
        
        /* Mobile spacing improvements */
        @media (max-width: 768px) {
            .py-8 { padding-top: 1rem; padding-bottom: 1rem; }
            .px-4 { padding-left: 1rem; padding-right: 1rem; }
            .p-6 { padding: 1rem; }
            .mb-6 { margin-bottom: 1rem; }
            .text-2xl { font-size: 1.5rem; }
            .signature-typed-preview { font-size: 1.5rem; padding: 1rem; }
        }
        
        /* Button improvements for mobile */
        button, .button, input[type="submit"] {
            min-height: 44px; /* Apple's recommended minimum touch target */
            font-size: 1rem;
        }
        
        /* Checkbox and form improvements for mobile */
        input[type="checkbox"] {
            min-width: 18px;
            min-height: 18px;
        }
        
        /* Hide custom content header on mobile if embedded content exists */
        @media (max-width: 768px) {
            .mobile-hide-when-embedded {
                display: none;
            }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-4xl mx-auto py-8 px-4">
        
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-sm p-4 mb-4">
            <div class="text-center">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="shield-check" class="w-6 h-6 text-blue-600"></i>
                </div>
                <h1 class="text-xl md:text-2xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($distribution['talk_title'] ?? 'Safety Communication'); ?></h1>
                <p class="text-blue-700 font-medium">
                    Hello <?php echo htmlspecialchars($distribution['employee_name'] ?? 'Team Member'); ?>
                </p>
                <p class="text-sm text-gray-600 mt-2">
                    Please review the material below and confirm your understanding
                </p>
            </div>
        </div>
        
        <!-- Messages -->
        <?php if ($message): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <!-- Content -->
        <?php if (!$has_confirmed): ?>
            
            <!-- Custom Content -->
            <?php if (!empty($distribution['custom_content'])): ?>
                <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Safety Information</h2>
                    <div class="prose max-w-none">
                        <?php echo $distribution['custom_content']; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- File Attachment -->
            <?php if (!empty($distribution['file_path'])): ?>
                <div class="bg-white rounded-lg shadow-sm mb-6">
                    <!-- Mobile-friendly embedded viewer -->
                    <div class="pdf-viewer-container">
                        <?php
                        // Generate the file serving URL
                        $file_url = '/communication/view_talk.php?token=' . urlencode($token) . '&serve_file=1';
                        
                        // Extract filename to get actual file extension
                        $filename = '';
                        if (strpos($distribution['file_path'], '/serve_safety_talk.php?file=') === 0) {
                            $url_parts = parse_url($distribution['file_path']);
                            if (isset($url_parts['query'])) {
                                parse_str($url_parts['query'], $params);
                                $filename = $params['file'] ?? '';
                                $filename = urldecode($filename);
                            }
                        } else {
                            $filename = basename($distribution['file_path']);
                        }
                        
                        $file_extension = $filename ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : '';
                        
                        // Determine actual file type from extension or database
                        $is_pdf = ($distribution['file_type'] === 'pdf' || $file_extension === 'pdf');
                        $is_video = ($distribution['file_type'] === 'mp4' || $file_extension === 'mp4');
                        $is_website = ($distribution['file_type'] === 'website');
                        ?>
                        
                        <?php if ($is_pdf): ?>
                            <!-- PDF Viewer with mobile optimization -->
                            <div class="relative">
                                <iframe src="<?php echo htmlspecialchars($file_url); ?>" 
                                        class="w-full h-screen border-0 rounded-lg"
                                        style="min-height: 80vh;">
                                </iframe>
                                <!-- Mobile fallback button -->
                                <div class="md:hidden absolute top-4 right-4">
                                    <a href="<?php echo htmlspecialchars($file_url); ?>" 
                                       target="_blank" 
                                       class="bg-blue-600 text-white px-3 py-2 rounded text-sm shadow-lg hover:bg-blue-700">
                                        📱 Open in Browser
                                    </a>
                                </div>
                            </div>
                            
                        <?php elseif ($is_video): ?>
                            <!-- Video Player -->
                            <video controls class="w-full rounded-lg" style="max-height: 80vh;">
                                <source src="<?php echo htmlspecialchars($file_url); ?>" type="video/mp4">
                                Your browser does not support the video tag.
                                <a href="<?php echo htmlspecialchars($file_url); ?>" class="text-blue-600">Download video</a>
                            </video>
                            
                        <?php elseif ($is_website): ?>
                            <!-- Website iframe -->
                            <iframe src="<?php echo htmlspecialchars($distribution['file_path']); ?>" 
                                    class="w-full border-0 rounded-lg"
                                    style="min-height: 80vh;">
                            </iframe>
                            
                        <?php else: ?>
                            <!-- Other file types - simple download -->
                            <div class="text-center p-8">
                                <div class="mb-4">
                                    <i data-lucide="file-text" class="w-16 h-16 mx-auto text-gray-400"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">Training Material</h3>
                                <p class="text-gray-600 mb-4"><?php echo htmlspecialchars($filename ?: basename($distribution['file_path'])); ?></p>
                                <a href="<?php echo htmlspecialchars($file_url); ?>" 
                                   target="_blank" 
                                   class="inline-flex items-center bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700">
                                    <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                                    View Material
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Quiz Section (if talk has quiz) -->
            <?php if (!empty($distribution['quiz']['questions']) && !$has_confirmed): ?>
                <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Knowledge Check Required</h2>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                        <p class="text-blue-800">
                            <i data-lucide="help-circle" class="w-5 h-5 inline mr-2"></i>
                            This safety communication includes a knowledge check. You must complete and pass the quiz before you can submit your confirmation.
                        </p>
                    </div>
                    <button type="button" id="start-quiz-btn" class="bg-green-600 text-white py-3 px-6 rounded-lg font-medium hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                        <i data-lucide="play-circle" class="w-5 h-5 inline mr-2"></i>
                        Start Knowledge Check
                    </button>
                </div>
            <?php endif; ?>
            
            <!-- Confirmation Form -->
            <?php if (!empty($distribution['quiz']['questions'])): ?>
                <!-- Quiz must be completed first -->
                <div class="bg-white rounded-lg shadow-sm p-4" id="confirmation-section" style="<?php echo !$has_confirmed ? 'display: none;' : ''; ?>">
            <?php else: ?>
                <!-- No quiz, show confirmation immediately -->
                <div class="bg-white rounded-lg shadow-sm p-4" id="confirmation-section">
            <?php endif; ?>
                <div class="text-center mb-6">
                    <h2 class="text-lg font-semibold text-gray-900">Ready to Confirm?</h2>
                    <p class="text-sm text-gray-600">Check the box and add your signature</p>
                </div>
                
                <form method="POST" action="view_talk.php?token=<?php echo htmlspecialchars($token); ?>" id="confirmationForm">
                    
                    <!-- Understanding Checkbox -->
                    <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                        <label class="flex items-start space-x-3 cursor-pointer">
                            <input type="checkbox" name="understood_material" id="understood_material" required 
                                   class="mt-1 h-5 w-5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="text-gray-700 text-sm leading-relaxed">
                                I confirm that I have reviewed and understood the safety information provided above.
                            </span>
                        </label>
                    </div>
                    
                    <!-- Signature Section -->
                    <div class="mb-6">
                        <label class="block text-base font-medium text-gray-700 mb-3 text-center">Add Your Signature</label>
                        
                        <!-- Signature Tabs -->
                        <div class="signature-tabs">
                            <div class="signature-tab active" data-tab="draw">✍️ Draw</div>
                            <div class="signature-tab" data-tab="type">⌨️ Type Name</div>
                        </div>
                        
                        <!-- Draw Signature -->
                        <div id="draw-signature" class="signature-content active">
                            <div id="signature-pad-container">
                                <canvas id="signature-canvas" class="signature-canvas" width="600" height="150"></canvas>
                            </div>
                            <div class="mt-3 text-center">
                                <button type="button" id="clear-signature" class="px-4 py-2 text-sm bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                                    🗑️ Clear
                                </button>
                            </div>
                        </div>
                        
                        <!-- Type Signature -->
                        <div id="type-signature" class="signature-content">
                            <input type="text" id="signature-text" placeholder="Type your full name" 
                                   value="<?php echo htmlspecialchars($distribution['employee_name'] ?? ''); ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-blue-500 focus:border-blue-500 mb-3 text-center">
                            <div class="signature-typed-preview" id="signature-preview">
                                <?php echo htmlspecialchars($distribution['employee_name'] ?? 'Your Name'); ?>
                            </div>
                        </div>
                        
                        <input type="hidden" name="signature_data_url" id="signature_data_url">
                    </div>
                    
                    <!-- Submit Button -->
                    <button type="submit" class="w-full bg-blue-600 text-white py-4 px-4 rounded-lg font-semibold text-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 shadow-lg">
                        ✅ Submit Confirmation
                    </button>
                    
                </form>
            </div>
            
        <?php else: ?>
            
            <!-- Confirmation Complete -->
            <div class="bg-white rounded-lg shadow-sm p-8 text-center">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="check-circle" class="w-8 h-8 text-green-600"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-2">Confirmation Complete</h2>
                <p class="text-gray-600 mb-6">
                    Thank you for confirming the safety talk: <strong><?php echo htmlspecialchars($distribution['talk_title'] ?? 'Safety Communication'); ?></strong>
                </p>
                <button onclick="window.close();" class="bg-gray-600 text-white py-2 px-4 rounded-lg hover:bg-gray-700">
                    Close Window
                </button>
            </div>
            
        <?php endif; ?>
        
    </div>

    <!-- Quiz Modal -->
    <?php if (!empty($distribution['quiz']['questions']) && !$has_confirmed): ?>
    <div id="quiz-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" style="display: none;">
        <div class="relative top-20 mx-auto p-5 border w-11/12 max-w-2xl shadow-lg rounded-md bg-white">
            
            <!-- Quiz Header -->
            <div class="mb-6">
                <h3 class="text-xl font-bold text-gray-900">Knowledge Check</h3>
                <p class="text-gray-600">Answer all questions correctly to proceed with confirmation.</p>
                <div class="mt-2">
                    <div class="bg-gray-200 rounded-full h-2">
                        <div id="quiz-progress" class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                    </div>
                    <p id="quiz-counter" class="text-sm text-gray-600 mt-1">Question 1 of <?php echo count($distribution['quiz']['questions']); ?></p>
                </div>
            </div>
            
            <!-- Quiz View -->
            <div id="quiz-view">
                <div id="quiz-body" class="mb-6">
                    <!-- Questions will be populated by JavaScript -->
                </div>
                
                <div class="flex justify-between">
                    <button type="button" id="quiz-prev-btn" class="px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50" style="display: none;">
                        Previous
                    </button>
                    <button type="button" id="quiz-next-btn" class="px-6 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700">
                        Next Question
                    </button>
                </div>
            </div>
            
            <!-- Quiz Results View -->
            <div id="quiz-results-view" style="display: none;">
                <div class="text-center">
                    <div id="quiz-result-icon" class="w-16 h-16 mx-auto mb-4"></div>
                    <h4 id="quiz-result-header" class="text-2xl font-bold mb-2"></h4>
                    <p id="quiz-result-score" class="text-lg text-gray-600 mb-4"></p>
                    <div id="quiz-result-summary" class="text-left bg-gray-50 rounded-lg p-4 mb-4"></div>
                    <div id="quiz-result-actions">
                        <button type="button" id="quiz-retake-btn" class="px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 mr-3">
                            Retake Quiz
                        </button>
                        <button type="button" id="quiz-continue-btn" class="px-6 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700" style="display: none;">
                            Continue to Confirmation
                        </button>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Pass quiz data to JavaScript
        window.quizData = <?php echo json_encode($distribution['quiz']['questions'] ?? []); ?>;
        window.hasQuiz = <?php echo json_encode(!empty($distribution['quiz']['questions'])); ?>;
        
        // Initialize Lucide icons
        lucide.createIcons();
        
        // Improved signature functionality with better mobile support
        let signaturePad;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize signature pad with improved mobile handling
            initializeSignaturePad();
            
            // Tab switching
            document.querySelectorAll('.signature-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabType = this.dataset.tab;
                    switchSignatureTab(tabType);
                });
            });
            
            // Type signature
            const signatureText = document.getElementById('signature-text');
            const signaturePreview = document.getElementById('signature-preview');
            if (signatureText && signaturePreview) {
                signatureText.addEventListener('input', function() {
                    signaturePreview.textContent = this.value || 'Your Name';
                });
            }
            
            // Form submission
            document.getElementById('confirmationForm').addEventListener('submit', function(e) {
                if (!captureSignature()) {
                    e.preventDefault();
                    alert('Please provide your signature before submitting.');
                }
            });

            // Quiz functionality
            if (window.hasQuiz && window.quizData.length > 0) {
                const startQuizBtn = document.getElementById('start-quiz-btn');
                const quizModal = document.getElementById('quiz-modal');
                const quizView = document.getElementById('quiz-view');
                const resultsView = document.getElementById('quiz-results-view');
                const quizCounter = document.getElementById('quiz-counter');
                const quizProgress = document.getElementById('quiz-progress');
                const quizBody = document.getElementById('quiz-body');
                const quizNextBtn = document.getElementById('quiz-next-btn');
                const quizPrevBtn = document.getElementById('quiz-prev-btn');
                const resultHeader = document.getElementById('quiz-result-header');
                const resultScore = document.getElementById('quiz-result-score');
                const resultSummary = document.getElementById('quiz-result-summary');
                const resultIcon = document.getElementById('quiz-result-icon');
                const retakeBtn = document.getElementById('quiz-retake-btn');
                const continueBtn = document.getElementById('quiz-continue-btn');
                const confirmationSection = document.getElementById('confirmation-section');

                let currentQuestionIndex = 0;
                let userAnswers = new Array(window.quizData.length);

                function showQuestion(index) {
                    const question = window.quizData[index];
                    const progress = ((index + 1) / window.quizData.length) * 100;
                    
                    quizCounter.textContent = `Question ${index + 1} of ${window.quizData.length}`;
                    quizProgress.style.width = progress + '%';
                    
                    let answersHtml = '';
                    question.answers.forEach((answer, i) => {
                        const checked = userAnswers[index] === i ? 'checked' : '';
                        answersHtml += `
                            <label class="flex items-start space-x-3 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer mb-2">
                                <input type="radio" name="question_${index}" value="${i}" ${checked}
                                       class="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300">
                                <span class="text-gray-700">${answer.answer_text}</span>
                            </label>
                        `;
                    });
                    
                    quizBody.innerHTML = `
                        <div class="mb-6">
                            <h4 class="text-lg font-semibold text-gray-900 mb-4">${question.question_text}</h4>
                            <div class="space-y-2">${answersHtml}</div>
                        </div>
                    `;
                    
                    // Add event listeners for radio buttons
                    quizBody.querySelectorAll(`input[name="question_${index}"]`).forEach(radio => {
                        radio.addEventListener('change', (e) => {
                            userAnswers[index] = parseInt(e.target.value);
                        });
                    });
                    
                    // Update button states
                    quizPrevBtn.style.display = index > 0 ? 'block' : 'none';
                    quizNextBtn.textContent = (index === window.quizData.length - 1) ? 'Submit Answers' : 'Next Question';
                }

                function showResults() {
                    let correctCount = 0;
                    let summaryHtml = '<div class="space-y-3">';
                    
                    window.quizData.forEach((question, qIndex) => {
                        const correctAnswerIndex = question.answers.findIndex(a => a.is_correct == 1);
                        const userAnswerIndex = userAnswers[qIndex];
                        const isCorrect = userAnswerIndex === correctAnswerIndex;
                        
                        if (isCorrect) {
                            correctCount++;
                        }
                        
                        const statusClass = isCorrect ? 'text-green-600' : 'text-red-600';
                        const statusIcon = isCorrect ? '✓' : '✗';
                        const userAnswerText = (userAnswerIndex !== undefined && question.answers[userAnswerIndex]) 
                            ? question.answers[userAnswerIndex].answer_text 
                            : 'No answer';
                        
                        summaryHtml += `
                            <div class="border border-gray-200 rounded-lg p-3">
                                <div class="font-medium ${statusClass}">
                                    ${statusIcon} Question ${qIndex + 1}: ${question.question_text}
                                </div>
                                <div class="text-sm text-gray-600 mt-1">
                                    Your answer: ${userAnswerText}
                                </div>
                                ${!isCorrect ? `<div class="text-sm text-green-600 mt-1">Correct answer: ${question.answers[correctAnswerIndex].answer_text}</div>` : ''}
                            </div>
                        `;
                    });
                    summaryHtml += '</div>';
                    
                    const score = (correctCount / window.quizData.length) * 100;
                    const passed = score >= 75; // 75% passing score
                    
                    quizView.style.display = 'none';
                    resultsView.style.display = 'block';
                    
                    resultScore.textContent = `Score: ${score.toFixed(0)}% (${correctCount}/${window.quizData.length} correct)`;
                    resultSummary.innerHTML = summaryHtml;
                    
                    if (passed) {
                        resultHeader.textContent = 'Congratulations! You passed!';
                        resultHeader.className = 'text-2xl font-bold mb-2 text-green-600';
                        resultIcon.innerHTML = '<i data-lucide="check-circle" class="w-16 h-16 text-green-600"></i>';
                        retakeBtn.style.display = 'none';
                        continueBtn.style.display = 'inline-block';
                    } else {
                        resultHeader.textContent = 'Please try again';
                        resultHeader.className = 'text-2xl font-bold mb-2 text-red-600';
                        resultIcon.innerHTML = '<i data-lucide="x-circle" class="w-16 h-16 text-red-600"></i>';
                        retakeBtn.style.display = 'inline-block';
                        continueBtn.style.display = 'none';
                    }
                    
                    // Re-initialize Lucide icons
                    lucide.createIcons();
                }

                // Event listeners
                if (startQuizBtn) {
                    startQuizBtn.addEventListener('click', () => {
                        currentQuestionIndex = 0;
                        userAnswers = new Array(window.quizData.length);
                        resultsView.style.display = 'none';
                        quizView.style.display = 'block';
                        quizModal.style.display = 'flex';
                        showQuestion(currentQuestionIndex);
                    });
                }

                if (quizNextBtn) {
                    quizNextBtn.addEventListener('click', () => {
                        if (userAnswers[currentQuestionIndex] === undefined) {
                            alert('Please select an answer before continuing.');
                            return;
                        }
                        
                        if (currentQuestionIndex < window.quizData.length - 1) {
                            currentQuestionIndex++;
                            showQuestion(currentQuestionIndex);
                        } else {
                            showResults();
                        }
                    });
                }

                if (quizPrevBtn) {
                    quizPrevBtn.addEventListener('click', () => {
                        if (currentQuestionIndex > 0) {
                            currentQuestionIndex--;
                            showQuestion(currentQuestionIndex);
                        }
                    });
                }

                if (retakeBtn) {
                    retakeBtn.addEventListener('click', () => {
                        if (startQuizBtn) startQuizBtn.click();
                    });
                }

                if (continueBtn) {
                    continueBtn.addEventListener('click', () => {
                        quizModal.style.display = 'none';
                        confirmationSection.style.display = 'block';
                        confirmationSection.scrollIntoView({ behavior: 'smooth' });
                    });
                }
            }
        });
        
        function initializeSignaturePad() {
            const canvas = document.getElementById('signature-canvas');
            const container = document.getElementById('signature-pad-container');
            
            if (!canvas || !container) return;
            
            // Resize canvas for high DPI displays
            function resizeCanvas() {
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                const rect = container.getBoundingClientRect();
                
                canvas.width = rect.width * ratio;
                canvas.height = 150 * ratio; // Fixed height
                
                const ctx = canvas.getContext('2d');
                ctx.scale(ratio, ratio);
                
                // Set canvas style dimensions
                canvas.style.width = rect.width + 'px';
                canvas.style.height = '150px';
                
                // Reinitialize signature pad if it exists
                if (signaturePad) {
                    const data = signaturePad.toData();
                    signaturePad.clear();
                    signaturePad.fromData(data);
                }
            }
            
            // Initialize SignaturePad with improved settings
            signaturePad = new SignaturePad(canvas, {
                backgroundColor: 'rgb(255, 255, 255)',
                penColor: 'rgb(0, 0, 0)',
                velocityFilterWeight: 0.7,
                minWidth: 1,
                maxWidth: 3,
                throttle: 16, // Improved responsiveness
                minDistance: 2 // Better line quality
            });
            
            // Handle window resize
            window.addEventListener('resize', resizeCanvas);
            
            // Initial resize with slight delay to ensure proper dimensions
            setTimeout(resizeCanvas, 100);
            
            // Clear button
            const clearBtn = document.getElementById('clear-signature');
            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    if (document.querySelector('.signature-tab[data-tab="draw"]').classList.contains('active')) {
                        signaturePad.clear();
                    } else {
                        const textInput = document.getElementById('signature-text');
                        const preview = document.getElementById('signature-preview');
                        if (textInput) textInput.value = '';
                        if (preview) preview.textContent = 'Your Name';
                    }
                });
            }
        }
        
        function switchSignatureTab(tabType) {
            // Update tabs
            document.querySelectorAll('.signature-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector(`[data-tab="${tabType}"]`).classList.add('active');
            
            // Update content
            document.querySelectorAll('.signature-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(`${tabType}-signature`).classList.add('active');
        }
        
        function captureSignature() {
            const activeTab = document.querySelector('.signature-tab.active').dataset.tab;
            
            if (activeTab === 'draw') {
                // Check if signature pad has content
                if (signaturePad.isEmpty()) {
                    return false;
                } else {
                    document.getElementById('signature_data_url').value = signaturePad.toDataURL();
                    return true;
                }
            } else if (activeTab === 'type') {
                const signatureText = document.getElementById('signature-text').value.trim();
                if (!signatureText) {
                    return false;
                } else {
                    // Create a canvas with the typed signature using Dancing Script font
                    const tempCanvas = document.createElement('canvas');
                    tempCanvas.width = 500;
                    tempCanvas.height = 150;
                    const tempCtx = tempCanvas.getContext('2d');
                    
                    // Fill background
                    tempCtx.fillStyle = '#fff';
                    tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
                    
                    // Draw text signature
                    tempCtx.font = "70px 'Dancing Script', cursive";
                    tempCtx.fillStyle = '#000';
                    tempCtx.textAlign = 'center';
                    tempCtx.textBaseline = 'middle';
                    tempCtx.fillText(signatureText, tempCanvas.width / 2, tempCanvas.height / 2);
                    
                    document.getElementById('signature_data_url').value = tempCanvas.toDataURL();
                    return true;
                }
            }
            
            return false;
        }
    </script>
</body>
</html>