<?php
// /public_html/communication/test_view_talk.php
// Test Safety Talk Viewing Page

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/communication.php';
require_once __DIR__ . '/../../src/communication.php';

// Get test token from URL or preview mode
$token = $_GET['token'] ?? '';
$preview_mode = isset($_GET['preview']) && $_GET['id'];

if (empty($token) && !$preview_mode) {
    http_response_code(400);
    die('Invalid test link.');
}

// Initialize variables
$message = '';
$error = '';
$test_distribution = null;
$distribution = null;

if ($preview_mode) {
    // Preview mode - direct access by admin
    $talk_id = $_GET['id'] ?? 0;
    $talk_details = getSafetyTalkById($talk_id, $conn);
    
    if (!$talk_details || $talk_details['status'] !== 'draft') {
        http_response_code(404);
        die('Safety talk not found.');
    }
    
    // Convert to distribution format for template reuse
    $distribution = [
        'id' => $talk_id,
        'safety_talk_id' => $talk_id,
        'talk_title' => '[PREVIEW] ' . $talk_details['title'],
        'custom_content' => $talk_details['custom_content'],
        'file_path' => $talk_details['file_path'],
        'file_type' => $talk_details['file_type'],
        'has_quiz' => $talk_details['has_quiz'],
        'employee_name' => 'Preview User'
    ];
} else {
    // Test token mode
    if (strpos($token, 'TEST_') !== 0) {
        http_response_code(400);
        die('Invalid test token.');
    }
    
    // Get test distribution by token
    try {
        $stmt = $conn->prepare("
            SELECT td.*, st.title as talk_title, st.custom_content, st.file_path, st.file_type, st.has_quiz,
                   u.firstName, u.lastName
            FROM test_distributions td
            JOIN safety_talks st ON td.safety_talk_id = st.id
            JOIN users u ON td.created_by = u.id
            WHERE td.test_token = ?
        ");
        
        if ($stmt) {
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result();
            $test_distribution = $result->fetch_assoc();
            $stmt->close();
            
            // Mark as viewed
            if ($test_distribution) {
                $stmt_update = $conn->prepare("UPDATE test_distributions SET viewed_at = NOW() WHERE test_token = ?");
                if ($stmt_update) {
                    $stmt_update->bind_param("s", $token);
                    $stmt_update->execute();
                    $stmt_update->close();
                }
            }
        } else {
            $test_distribution = null;
        }
    } catch (Exception $e) {
        $test_distribution = null;
    }

    if (!$test_distribution) {
        http_response_code(404);
        die('Test safety talk not found or expired.');
    }

    // Convert test data to same format as regular distribution for template reuse
    $distribution = [
        'id' => $test_distribution['id'],
        'safety_talk_id' => $test_distribution['safety_talk_id'],
        'talk_title' => '[TEST PREVIEW] ' . $test_distribution['talk_title'],
        'custom_content' => $test_distribution['custom_content'],
        'file_path' => $test_distribution['file_path'],
        'file_type' => $test_distribution['file_type'],
        'has_quiz' => $test_distribution['has_quiz'],
        'employee_name' => 'Test User (' . $test_distribution['firstName'] . ' ' . $test_distribution['lastName'] . ')'
    ];
}

// Load quiz data if needed
if ($distribution['has_quiz']) {
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

$has_confirmed = false; // Tests never require confirmation

// Handle file serving
if (isset($_GET['serve_file']) && $_GET['serve_file'] == '1') {
    if ($distribution['file_type'] !== 'website' && !empty($distribution['file_path'])) {
        
        // Extract filename from the stored file path
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
        
        if (!empty($filename)) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TEST PREVIEW - <?php echo htmlspecialchars($distribution['talk_title']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@0.378.0/dist/umd/lucide.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        
        /* Mobile-first responsive design */
        .max-w-4xl { max-width: 100%; }
        @media (min-width: 768px) {
            .max-w-4xl { max-width: 56rem; }
        }
        
        /* PDF viewer container */
        .pdf-viewer-container {
            position: relative;
            width: 100%;
            background: #f8f9fa;
            border-radius: 0.5rem;
            overflow: hidden;
        }
        
        .pdf-viewer-container iframe {
            width: 100%;
            border: none;
            display: block;
        }
        
        /* Mobile spacing improvements */
        @media (max-width: 768px) {
            .py-8 { padding-top: 1rem; padding-bottom: 1rem; }
            .px-4 { padding-left: 1rem; padding-right: 1rem; }
            .p-6 { padding: 1rem; }
            .mb-6 { margin-bottom: 1rem; }
            .text-2xl { font-size: 1.5rem; }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-4xl mx-auto py-8 px-4">
        
        <!-- Test Header -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
            <div class="flex items-center">
                <i data-lucide="alert-triangle" class="w-6 h-6 text-yellow-600 mr-3"></i>
                <div>
                    <h2 class="text-lg font-semibold text-yellow-800">
                        <?php echo $preview_mode ? 'PREVIEW MODE' : 'TEST PREVIEW MODE'; ?>
                    </h2>
                    <p class="text-yellow-700">
                        <?php if ($preview_mode): ?>
                            This is a preview of how the safety communication will appear to employees. No confirmation is required.
                        <?php else: ?>
                            This is a test preview of how the safety communication will appear to employees. No confirmation is required.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Regular Header -->
        <div class="bg-white rounded-lg shadow-sm p-4 mb-4">
            <div class="text-center">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="shield-check" class="w-6 h-6 text-blue-600"></i>
                </div>
                <h1 class="text-xl md:text-2xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($distribution['talk_title']); ?></h1>
                <p class="text-blue-700 font-medium">
                    Hello <?php echo htmlspecialchars($distribution['employee_name']); ?>
                </p>
                <p class="text-sm text-gray-600 mt-2">
                    Please review the material below and confirm your understanding
                </p>
            </div>
        </div>
        
        <!-- Content -->
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
                <div class="pdf-viewer-container">
                    <?php
                    // Generate the file serving URL
                    $file_url = ($preview_mode ? '/communication/test_view_talk.php?preview=1&id=' . $distribution['safety_talk_id'] : '/communication/test_view_talk.php?token=' . urlencode($token)) . '&serve_file=1';
                    
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
                        <!-- PDF Viewer -->
                        <div class="relative">
                            <iframe src="<?php echo htmlspecialchars($file_url); ?>" 
                                    class="w-full h-screen border-0 rounded-lg"
                                    style="min-height: 80vh;">
                            </iframe>
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
                        <!-- Other file types -->
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
        
        <!-- Quiz Section (if enabled, but no submission required) -->
        <?php if (!empty($distribution['quiz']['questions'])): ?>
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Knowledge Check Preview</h2>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                    <p class="text-blue-800">
                        <i data-lucide="help-circle" class="w-5 h-5 inline mr-2"></i>
                        This safety communication includes a knowledge check. In the actual version, employees will need to complete and pass this quiz.
                    </p>
                </div>
                <button type="button" id="start-quiz-btn" class="bg-blue-600 text-white py-3 px-6 rounded-lg font-medium hover:bg-blue-700">
                    <i data-lucide="play-circle" class="w-5 h-5 inline mr-2"></i>
                    Preview Knowledge Check
                </button>
            </div>
        <?php endif; ?>
        
        <!-- Test Footer -->
        <div class="bg-green-50 border border-green-200 rounded-lg p-6 text-center">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="check-circle" class="w-8 h-8 text-green-600"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-900 mb-2">
                <?php echo $preview_mode ? 'Preview Complete' : 'Test Preview Complete'; ?>
            </h2>
            <p class="text-gray-600 mb-4">
                This is how employees will see: <strong><?php echo htmlspecialchars(str_replace(['[TEST PREVIEW] ', '[PREVIEW] '], '', $distribution['talk_title'])); ?></strong>
            </p>
            <p class="text-sm text-gray-500">
                In the actual version, employees will be required to confirm their understanding with a digital signature.
            </p>
            <?php if ($preview_mode): ?>
            <div class="mt-4">
                <button onclick="window.close();" class="bg-gray-600 text-white py-2 px-4 rounded-lg hover:bg-gray-700">
                    Close Preview
                </button>
            </div>
            <?php endif; ?>
        </div>
        
    </div>

    <!-- Quiz Modal (same as regular view but no confirmation) -->
    <?php if (!empty($distribution['quiz']['questions'])): ?>
    <div id="quiz-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" style="display: none;">
        <div class="relative top-20 mx-auto p-5 border w-11/12 max-w-2xl shadow-lg rounded-md bg-white">
            
            <!-- Quiz Header -->
            <div class="mb-6">
                <h3 class="text-xl font-bold text-gray-900">Knowledge Check Preview</h3>
                <p class="text-gray-600">This is how the quiz will appear to employees.</p>
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
                            Try Again
                        </button>
                        <button type="button" id="quiz-close-btn" class="px-6 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700">
                            Close Preview
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
        
        document.addEventListener('DOMContentLoaded', function() {
            // Quiz functionality (preview only)
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
                const closeBtn = document.getElementById('quiz-close-btn');

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
                    quizNextBtn.textContent = (index === window.quizData.length - 1) ? 'Show Results' : 'Next Question';
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
                    
                    resultScore.textContent = `Preview Score: ${score.toFixed(0)}% (${correctCount}/${window.quizData.length} correct)`;
                    resultSummary.innerHTML = summaryHtml;
                    
                    if (passed) {
                        resultHeader.textContent = 'Quiz Preview - Would Pass!';
                        resultHeader.className = 'text-2xl font-bold mb-2 text-green-600';
                        resultIcon.innerHTML = '<i data-lucide="check-circle" class="w-16 h-16 text-green-600"></i>';
                    } else {
                        resultHeader.textContent = 'Quiz Preview - Would Need Retake';
                        resultHeader.className = 'text-2xl font-bold mb-2 text-red-600';
                        resultIcon.innerHTML = '<i data-lucide="x-circle" class="w-16 h-16 text-red-600"></i>';
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

                if (closeBtn) {
                    closeBtn.addEventListener('click', () => {
                        quizModal.style.display = 'none';
                    });
                }
            }
        });
    </script>
</body>
</html>