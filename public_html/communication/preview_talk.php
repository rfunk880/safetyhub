<?php
// /public_html/communication/preview_talk.php
// Preview and Test Safety Talk Before Distribution

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
    header('Location: index.php?error=' . urlencode('No safety talk specified.'));
    exit;
}

// Get talk details
$talk_details = getSafetyTalkById($talk_id, $conn);

if (!$talk_details || $talk_details['status'] !== 'draft') {
    header('Location: index.php?error=' . urlencode('Safety talk not found or already distributed.'));
    exit;
}

// Get pending distribution info from session
$pending_distribution = $_SESSION['pending_distribution'] ?? null;

if (!$pending_distribution || $pending_distribution['talk_id'] != $talk_id) {
    header('Location: index.php?error=' . urlencode('Invalid distribution session.'));
    exit;
}

// Initialize variables
$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';

// Handle test notification AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_notification') {
    header('Content-Type: application/json');
    
    $test_email = trim($_POST['test_email'] ?? '');
    $test_phone = trim($_POST['test_phone'] ?? '');
    
    $response = ['success' => false, 'message' => '', 'email_sent' => false, 'sms_sent' => false];
    
    // Validate inputs
    if (empty($test_email) && empty($test_phone)) {
        $response['message'] = 'Please provide at least one email address or phone number for testing.';
        echo json_encode($response);
        exit;
    }
    
    if (!empty($test_email) && !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Please provide a valid email address.';
        echo json_encode($response);
        exit;
    }
    
    // Create a temporary test distribution with a special test token
    $test_token = 'TEST_' . generateUniqueToken();
    $stmt = $conn->prepare("INSERT INTO test_distributions (safety_talk_id, test_token, test_email, test_phone, created_by) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isssi", $talk_id, $test_token, $test_email, $test_phone, $_SESSION['user_id']);
        if ($stmt->execute()) {
            $test_view_link = "https://" . $_SERVER['HTTP_HOST'] . "/communication/test_view_talk.php?token=" . $test_token;
            
            // Test email notification
            if (!empty($test_email)) {
                try {
                    $email_sent = sendSafetyTalkEmail(
                        $test_email,
                        'Test Administrator',
                        '[TEST PREVIEW] ' . $talk_details['title'],
                        $test_view_link
                    );
                    
                    if ($email_sent) {
                        $response['email_sent'] = true;
                    } else {
                        $response['message'] .= 'Email test failed. ';
                    }
                } catch (Exception $e) {
                    $response['message'] .= 'Email error: ' . $e->getMessage() . '. ';
                }
            }
            
            // Test SMS notification
            if (!empty($test_phone)) {
                try {
                    $sms_sent = sendSafetyTalkSMS(
                        $test_phone,
                        'Test Administrator',
                        '[TEST PREVIEW] ' . $talk_details['title'],
                        $test_view_link
                    );
                    
                    if ($sms_sent) {
                        $response['sms_sent'] = true;
                    } else {
                        $response['message'] .= 'SMS test failed. ';
                    }
                } catch (Exception $e) {
                    $response['message'] .= 'SMS error: ' . $e->getMessage() . '. ';
                }
            }
            
            // Set success status and final message
            if ($response['email_sent'] || $response['sms_sent']) {
                $response['success'] = true;
                $sent_methods = [];
                if ($response['email_sent']) $sent_methods[] = 'email';
                if ($response['sms_sent']) $sent_methods[] = 'SMS';
                $response['message'] = 'Test safety talk sent successfully via ' . implode(' and ', $sent_methods) . '!';
                $response['test_url'] = $test_view_link;
            } else {
                $response['message'] = trim($response['message']) ?: 'Test notification failed to send.';
            }
        }
        $stmt->close();
    }
    
    echo json_encode($response);
    exit;
}

// Get employee count for display
$employee_count = count($pending_distribution['employee_ids']);

// Get employees for display
$employee_names = [];
if ($employee_count <= 10) {
    $employee_ids_str = implode(',', array_map('intval', $pending_distribution['employee_ids']));
    $stmt = $conn->prepare("SELECT CONCAT(firstName, ' ', lastName) as name FROM users WHERE id IN ($employee_ids_str)");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $employee_names[] = $row['name'];
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview & Test Safety Talk - Safety Hub</title>
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
                            <h1 class="text-3xl font-bold text-gray-900">Preview & Test Safety Talk</h1>
                            <p class="text-gray-600 mt-2">Test your safety communication before sending to employees</p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="edit_talk.php?id=<?php echo $talk_id; ?>" class="inline-flex items-center px-4 py-2 text-blue-700 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100">
                                <i data-lucide="edit" class="w-4 h-4 mr-2"></i>
                                Edit Safety Talk
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Process Steps -->
                <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">Safety Talk Creation Process</h2>
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-green-600 text-white rounded-full flex items-center justify-center text-sm font-medium">✓</div>
                            <span class="ml-2 text-sm font-medium text-green-600">Create Content</span>
                        </div>
                        <i data-lucide="arrow-right" class="w-4 h-4 text-gray-400"></i>
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center text-sm font-medium">2</div>
                            <span class="ml-2 text-sm font-medium text-blue-600">Preview & Test</span>
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

                <!-- Safety Talk Preview -->
                <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Safety Talk Preview</h2>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <div class="flex items-center">
                            <i data-lucide="info" class="w-5 h-5 text-blue-600 mr-2"></i>
                            <div>
                                <p class="text-blue-800 font-medium">Ready for Testing</p>
                                <p class="text-blue-700 text-sm">Your safety talk has been created and is ready for testing. Send test notifications to verify how it appears on different devices.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Talk Details -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <h3 class="font-medium text-gray-900 mb-2">Title</h3>
                            <p class="text-gray-700"><?php echo htmlspecialchars($talk_details['title']); ?></p>
                        </div>
                        <div>
                            <h3 class="font-medium text-gray-900 mb-2">Recipients</h3>
                            <p class="text-gray-700"><?php echo $employee_count; ?> employees selected</p>
                            <?php if (!empty($employee_names)): ?>
                                <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars(implode(', ', array_slice($employee_names, 0, 5))); ?><?php echo count($employee_names) > 5 ? ' and ' . (count($employee_names) - 5) . ' more' : ''; ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3 class="font-medium text-gray-900 mb-2">Content Type</h3>
                            <p class="text-gray-700">
                                <?php 
                                if ($talk_details['file_type'] === 'pdf') echo 'PDF Document';
                                elseif ($talk_details['file_type'] === 'mp4') echo 'Video (MP4)';
                                elseif ($talk_details['file_type'] === 'website') echo 'Website Link';
                                else echo 'Text Content Only';
                                ?>
                            </p>
                        </div>
                        <div>
                            <h3 class="font-medium text-gray-900 mb-2">Quiz Included</h3>
                            <p class="text-gray-700"><?php echo $talk_details['has_quiz'] ? 'Yes' : 'No'; ?></p>
                        </div>
                    </div>

                    <!-- Content Preview -->
                    <?php if (!empty($talk_details['custom_content'])): ?>
                    <div class="mb-6">
                        <h3 class="font-medium text-gray-900 mb-3">Content Preview</h3>
                        <div class="max-h-40 overflow-y-auto bg-gray-50 p-4 rounded-lg">
                            <div class="prose prose-sm max-w-none">
                                <?php echo $talk_details['custom_content']; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Quick Preview Button -->
                    <div class="text-center">
                        <a href="test_view_talk.php?preview=1&id=<?php echo $talk_id; ?>" 
                           target="_blank" 
                           class="inline-flex items-center px-6 py-3 bg-gray-600 text-white font-medium rounded-lg hover:bg-gray-700">
                            <i data-lucide="eye" class="w-5 h-5 mr-2"></i>
                            Preview in Browser
                        </a>
                    </div>
                </div>

                <!-- Test Notifications -->
                <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Send Test Notifications</h2>
                    <p class="text-gray-600 mb-6">
                        Send the complete safety talk to test email/SMS addresses to see exactly how employees will receive and view it.
                    </p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="test_email" class="block text-sm font-medium text-gray-700 mb-2">Test Email Address</label>
                            <input type="email" id="test_email" name="test_email"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="admin@company.com">
                        </div>
                        
                        <div>
                            <label for="test_phone" class="block text-sm font-medium text-gray-700 mb-2">Test Phone Number</label>
                            <input type="tel" id="test_phone" name="test_phone"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="+1234567890">
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-600">
                            The complete safety talk will be sent with a [TEST PREVIEW] prefix
                        </div>
                        <button type="button" id="send_test_btn" class="inline-flex items-center px-4 py-2 bg-yellow-600 text-white font-medium rounded-lg hover:bg-yellow-700">
                            <i data-lucide="send" class="w-4 h-4 mr-2"></i>
                            Send Test
                        </button>
                    </div>
                    
                    <!-- Test Results -->
                    <div id="test_results" class="mt-4" style="display: none;">
                        <div id="test_message_display" class="p-3 rounded-lg"></div>
                    </div>
                </div>

                <!-- Final Distribution -->
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Ready to Distribute?</h2>
                    
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                        <div class="flex items-center">
                            <i data-lucide="users" class="w-5 h-5 text-green-600 mr-2"></i>
                            <div>
                                <p class="text-green-800 font-medium">Distribution Ready</p>
                                <p class="text-green-700 text-sm">Once you're satisfied with the testing, distribute this safety talk to all selected employees.</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-600">
                            This will immediately send notifications to <?php echo $employee_count; ?> selected employees
                        </div>
                        <div class="space-x-3">
                            <a href="edit_talk.php?id=<?php echo $talk_id; ?>" class="px-6 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                                Make Changes
                            </a>
                            <form method="POST" action="distribute_talk.php" class="inline">
                                <input type="hidden" name="talk_id" value="<?php echo $talk_id; ?>">
                                <input type="hidden" name="employee_ids" value="<?php echo implode(',', $pending_distribution['employee_ids']); ?>">
                                <button type="submit" class="inline-flex items-center px-6 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700">
                                    <i data-lucide="send" class="w-4 h-4 mr-2"></i>
                                    Distribute Now
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
            </div>
        </main>
        
    </div>
    
    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Test notification functionality
        document.getElementById('send_test_btn').addEventListener('click', function() {
            const testEmail = document.getElementById('test_email').value.trim();
            const testPhone = document.getElementById('test_phone').value.trim();
            const sendBtn = this;
            const resultsDiv = document.getElementById('test_results');
            const messageDiv = document.getElementById('test_message_display');
            
            // Validation
            if (!testEmail && !testPhone) {
                showTestResult('error', 'Please provide at least one email address or phone number for testing.');
                return;
            }
            
            // Disable button and show loading
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 mr-2 animate-spin"></i>Sending Test...';
            
            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'test_notification');
            formData.append('test_email', testEmail);
            formData.append('test_phone', testPhone);
            
            // Send AJAX request
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let details = [];
                    if (data.email_sent) details.push('✓ Email sent successfully');
                    if (data.sms_sent) details.push('✓ SMS sent successfully');
                    if (data.test_url) details.push(`<a href="${data.test_url}" target="_blank" class="text-blue-600 hover:text-blue-800">📱 View Test Link</a>`);
                    
                    showTestResult('success', data.message + '<br><small>' + details.join('<br>') + '</small>');
                } else {
                    showTestResult('error', data.message || 'Test notification failed.');
                }
            })
            .catch(error => {
                console.error('Test notification error:', error);
                showTestResult('error', 'An error occurred while sending the test notification.');
            })
            .finally(() => {
                // Re-enable button
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i data-lucide="send" class="w-4 h-4 mr-2"></i>Send Test';
                lucide.createIcons();
            });
        });
        
        function showTestResult(type, message) {
            const resultsDiv = document.getElementById('test_results');
            const messageDiv = document.getElementById('test_message_display');
            
            resultsDiv.style.display = 'block';
            
            if (type === 'success') {
                messageDiv.className = 'p-3 rounded-lg bg-green-50 border border-green-200 text-green-700';
            } else {
                messageDiv.className = 'p-3 rounded-lg bg-red-50 border border-red-200 text-red-700';
            }
            
            messageDiv.innerHTML = message;
            
            // Scroll to results
            resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    </script>
</body>
</html>