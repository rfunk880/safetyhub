<?php
// /public_html/communication/talk_details.php
// Safety Talk Details and Management

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

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'archive_talk':
            if (archiveSafetyTalk($talk_id, $conn)) {
                header('Location: history.php?message=' . urlencode('Safety talk has been archived.'));
                exit;
            } else {
                $error = 'Failed to archive the safety talk.';
            }
            break;
            
        case 'delete_talk':
            // Only super admins can delete
            if ($_SESSION['user_role_id'] == 1) {
                if (deleteSafetyTalkAndRecords($talk_id, $conn)) {
                    header('Location: history.php?message=' . urlencode('Safety talk and all records have been deleted.'));
                    exit;
                } else {
                    $error = 'Failed to delete the safety talk.';
                }
            } else {
                $error = 'You do not have permission to delete safety talks.';
            }
            break;
            
        case 'resend_notification':
            $distribution_id = $_POST['distribution_id'] ?? 0;
            $method = $_POST['method'] ?? 'email';
            
            if ($distribution_id) {
                $result = sendReminderNotification($distribution_id, $method, $conn);
                if ($result['success']) {
                    $message = ucfirst($method) . ' reminder sent successfully.';
                } else {
                    $error = 'Failed to send reminder: ' . implode(', ', $result['errors']);
                }
            }
            break;
    }
}

// Get talk details
$talk_details = getTalkDetails($talk_id, $conn);

if (!$talk_details) {
    header('Location: history.php?error=' . urlencode('Safety talk not found.'));
    exit;
}

// Calculate statistics
$total_distributed = count($talk_details['distributions']);
$total_confirmed = 0;
$pending_count = 0;

foreach ($talk_details['distributions'] as $dist) {
    if ($dist['confirmation_date']) {
        $total_confirmed++;
    } else {
        $pending_count++;
    }
}

$completion_percentage = $total_distributed > 0 ? ($total_confirmed / $total_distributed) * 100 : 0;

// Get message from URL if present
if (isset($_GET['message'])) {
    $message = $_GET['message'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safety Talk Details - Safety Hub</title>
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
        <?php 
        // Get navigation HTML
        global $navigation_html;
        echo $navigation_html; 
        ?>
        
        <!-- Main Content Area -->
        <main class="flex-1 overflow-auto p-6">
            <div class="max-w-6xl mx-auto">
                
                <!-- Header -->
                <div class="mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-center space-x-2 text-sm text-gray-600 mb-2">
                                <a href="history.php" class="hover:text-blue-600">Safety Talks</a>
                                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                <span>Talk Details</span>
                            </div>
                            <h1 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($talk_details['title']); ?></h1>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="flex space-x-3">
                            <?php if ($_SESSION['user_role_id'] == 1): ?>
                                <button onclick="confirmDelete()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                                    <i data-lucide="trash-2" class="w-4 h-4 mr-2 inline"></i>
                                    Delete
                                </button>
                            <?php endif; ?>
                            
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="archive_talk">
                                <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                                    <i data-lucide="archive" class="w-4 h-4 mr-2 inline"></i>
                                    Archive
                                </button>
                            </form>
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
                
                <!-- Statistics Summary -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i data-lucide="users" class="w-6 h-6 text-blue-600"></i>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Total Distributed</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $total_distributed; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i data-lucide="check-circle" class="w-6 h-6 text-green-600"></i>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Confirmed</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $total_confirmed; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                    <i data-lucide="clock" class="w-6 h-6 text-yellow-600"></i>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Pending</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $pending_count; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Progress Bar -->
                <?php if ($total_distributed > 0): ?>
                    <div class="bg-white rounded-lg shadow p-6 mb-8">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-lg font-semibold text-gray-900">Completion Progress</h3>
                            <span class="text-sm font-medium text-gray-600"><?php echo round($completion_percentage); ?>% Complete</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3">
                            <div class="bg-blue-600 h-3 rounded-full transition-all duration-300" style="width: <?php echo $completion_percentage; ?>%"></div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Content Preview -->
                <div class="bg-white rounded-lg shadow p-6 mb-8">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Content</h2>
                    
                    <?php if (!empty($talk_details['custom_content'])): ?>
                        <div class="prose max-w-none mb-4">
                            <?php echo $talk_details['custom_content']; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($talk_details['file_path'])): ?>
                        <div class="border-t pt-4">
                            <h3 class="text-lg font-medium text-gray-900 mb-2">Attachment</h3>
                            <?php if ($talk_details['file_type'] === 'website'): ?>
                                <a href="<?php echo htmlspecialchars($talk_details['file_path']); ?>" target="_blank" 
                                   class="inline-flex items-center text-blue-600 hover:text-blue-800">
                                    <i data-lucide="external-link" class="w-4 h-4 mr-2"></i>
                                    <?php echo htmlspecialchars($talk_details['file_path']); ?>
                                </a>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($talk_details['file_path']); ?>" target="_blank"
                                   class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                                    <i data-lucide="<?php echo $talk_details['file_type'] === 'pdf' ? 'file-text' : 'video'; ?>" class="w-4 h-4 mr-2"></i>
                                    View <?php echo strtoupper($talk_details['file_type']); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Employee Status Table -->
                <?php if (!empty($talk_details['distributions'])): ?>
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-900">Employee Status</h3>
                            <p class="text-sm text-gray-600">Distribution and confirmation details</p>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Employee
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Notifications Sent
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Signature
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Quiz Score
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($talk_details['distributions'] as $dist): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($dist['employee_name']); ?>
                                                        </div>
                                                        <div class="text-sm text-gray-500">
                                                            <?php echo htmlspecialchars($dist['employee_email']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($dist['confirmation_date']): ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                        <i data-lucide="check-circle" class="w-3 h-3 mr-1"></i>
                                                        Confirmed
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                        <i data-lucide="clock" class="w-3 h-3 mr-1"></i>
                                                        Pending
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php if ($dist['confirmation_date']): ?>
                                                    <div>
                                                        <div class="font-medium text-gray-900">
                                                            <?php echo date('M j, Y', strtotime($dist['confirmation_date'])); ?>
                                                        </div>
                                                        <div class="text-xs text-gray-500">
                                                            <?php echo date('g:i A', strtotime($dist['confirmation_date'])); ?>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <div>
                                                        <div class="font-medium text-gray-700">
                                                            <?php echo $talk_details['first_distributed_at'] ? date('M j, Y', strtotime($talk_details['first_distributed_at'])) : 'Not sent'; ?>
                                                        </div>
                                                        <?php if ($talk_details['first_distributed_at']): ?>
                                                            <div class="text-xs text-gray-500">
                                                                <?php echo date('g:i A', strtotime($talk_details['first_distributed_at'])); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <div class="flex items-center">
                                                    <?php 
                                                    $notification_count = $dist['notification_count'] ?? 0;
                                                    if ($notification_count > 1): 
                                                    ?>
                                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                                                            <i data-lucide="mail" class="w-3 h-3 mr-1"></i>
                                                            <?php echo $notification_count; ?> sent
                                                        </span>
                                                    <?php elseif ($notification_count == 1): ?>
                                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                            <i data-lucide="mail" class="w-3 h-3 mr-1"></i>
                                                            Initial sent
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-gray-400 text-xs">Not sent</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($notification_count > 1): ?>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        <?php echo ($notification_count - 1); ?> reminder(s)
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php if ($dist['confirmation_date'] && !empty($dist['signature_image_base64'])): ?>
                                                    <div class="flex items-center space-x-2">
                                                        <img src="<?php echo htmlspecialchars($dist['signature_image_base64']); ?>" 
                                                             alt="Signature" 
                                                             class="h-12 w-24 border border-gray-200 rounded bg-white object-contain cursor-pointer hover:shadow-md"
                                                             onclick="showSignatureModal('<?php echo htmlspecialchars($dist['signature_image_base64']); ?>', '<?php echo htmlspecialchars($dist['employee_name']); ?>')">
                                                        <button type="button" 
                                                                onclick="showSignatureModal('<?php echo htmlspecialchars($dist['signature_image_base64']); ?>', '<?php echo htmlspecialchars($dist['employee_name']); ?>')"
                                                                class="text-blue-600 hover:text-blue-800 text-xs">
                                                            <i data-lucide="expand" class="w-3 h-3"></i>
                                                        </button>
                                                    </div>
                                                <?php elseif ($dist['confirmation_date']): ?>
                                                    <span class="text-gray-400 text-xs">No signature saved</span>
                                                <?php else: ?>
                                                    <span class="text-gray-400 text-xs">Not signed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php if ($dist['quiz_score'] !== null): ?>
                                                    <span class="text-sm font-medium <?php echo $dist['quiz_score'] >= 70 ? 'text-green-600' : 'text-red-600'; ?>">
                                                        <?php echo $dist['quiz_score']; ?>%
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <?php if (!$dist['confirmation_date']): ?>
                                                    <div class="flex space-x-2">
                                                        <form method="POST" class="inline">
                                                            <input type="hidden" name="action" value="resend_notification">
                                                            <input type="hidden" name="distribution_id" value="<?php echo $dist['distribution_id']; ?>">
                                                            <input type="hidden" name="method" value="email">
                                                            <button type="submit" class="text-blue-600 hover:text-blue-900 text-xs">
                                                                📧 Email
                                                            </button>
                                                        </form>
                                                        <?php if (!empty($dist['employee_phone'])): ?>
                                                            <form method="POST" class="inline">
                                                                <input type="hidden" name="action" value="resend_notification">
                                                                <input type="hidden" name="distribution_id" value="<?php echo $dist['distribution_id']; ?>">
                                                                <input type="hidden" name="method" value="sms">
                                                                <button type="submit" class="text-green-600 hover:text-green-900 text-xs">
                                                                    📱 SMS
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-gray-400 text-xs">Complete</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow p-6 text-center">
                        <i data-lucide="users" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Distributions Yet</h3>
                        <p class="text-gray-600">This safety talk hasn't been distributed to any employees yet.</p>
                    </div>
                <?php endif; ?>
                
            </div>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-md mx-4">
            <div class="flex items-center mb-4">
                <div class="flex-shrink-0">
                    <i data-lucide="alert-triangle" class="w-6 h-6 text-red-600"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-lg font-medium text-gray-900">Delete Safety Talk</h3>
                </div>
            </div>
            <div class="mb-4">
                <p class="text-sm text-gray-500">
                    Are you sure you want to delete this safety talk? This action cannot be undone and will remove all associated records.
                </p>
            </div>
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="hideDeleteModal()" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300">
                    Cancel
                </button>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="delete_talk">
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Signature Modal -->
    <div id="signatureModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-2xl mx-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900" id="signatureModalTitle">Employee Signature</h3>
                <button type="button" onclick="hideSignatureModal()" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="mb-4">
                <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
                    <img id="signatureImage" src="" alt="Employee Signature" class="max-w-full h-auto mx-auto bg-white border border-gray-300 rounded">
                </div>
            </div>
            <div class="flex justify-end">
                <button type="button" onclick="hideSignatureModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();
        
        function confirmDelete() {
            document.getElementById('deleteModal').classList.remove('hidden');
            document.getElementById('deleteModal').classList.add('flex');
        }
        
        function hideDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
            document.getElementById('deleteModal').classList.remove('flex');
        }
        
        function showSignatureModal(signatureData, employeeName) {
            document.getElementById('signatureModalTitle').textContent = employeeName + "'s Signature";
            document.getElementById('signatureImage').src = signatureData;
            document.getElementById('signatureModal').classList.remove('hidden');
            document.getElementById('signatureModal').classList.add('flex');
        }
        
        function hideSignatureModal() {
            document.getElementById('signatureModal').classList.add('hidden');
            document.getElementById('signatureModal').classList.remove('flex');
        }
        
        // Close modals when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideDeleteModal();
            }
        });
        
        document.getElementById('signatureModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideSignatureModal();
            }
        });
    </script>
</body>
</html>