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
        <?php renderNavigation(); ?>
        
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
                            <p class="text-gray-600 mt-2">
                                Created <?php echo date('M j, Y \a\t g:i A', strtotime($talk_details['created_at'])); ?>
                                <?php if ($talk_details['first_distributed_at']): ?>
                                    • First distributed <?php echo date('M j, Y \a\t g:i A', strtotime($talk_details['first_distributed_at'])); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="flex items-center space-x-3">
                            <?php if (!$talk_details['is_archived']): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="archive_talk">
                                    <button type="submit" class="inline-flex items-center px-4 py-2 text-yellow-700 bg-yellow-50 border border-yellow-200 rounded-lg hover:bg-yellow-100">
                                        <i data-lucide="archive" class="w-4 h-4 mr-2"></i>
                                        Archive
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <?php if ($_SESSION['user_role_id'] == 1): // Super Admin only ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this safety talk and all associated records? This action cannot be undone.')">
                                    <input type="hidden" name="action" value="delete_talk">
                                    <button type="submit" class="inline-flex items-center px-4 py-2 text-red-700 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100">
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
                                        Delete
                                    </button>
                                </form>
                            <?php endif; ?>
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
                
                <!-- Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i data-lucide="send" class="w-6 h-6 text-blue-600"></i>
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
                
                <!-- Progress Overview -->
                <div class="bg-white rounded-lg shadow p-6 mb-8">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Completion Progress</h2>
                    <div class="mb-4">
                        <div class="flex justify-between text-sm text-gray-600 mb-2">
                            <span><?php echo $total_confirmed; ?> of <?php echo $total_distributed; ?> employees confirmed</span>
                            <span><?php echo number_format($completion_percentage, 1); ?>% complete</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3">
                            <div class="bg-blue-600 h-3 rounded-full transition-all duration-300" style="width: <?php echo $completion_percentage; ?>%"></div>
                        </div>
                    </div>
                    
                    <?php if ($completion_percentage == 100): ?>
                        <div class="flex items-center text-green-700 bg-green-50 px-4 py-2 rounded-lg">
                            <i data-lucide="check-circle" class="w-5 h-5 mr-2"></i>
                            <span class="font-medium">All employees have confirmed this safety talk!</span>
                        </div>
                    <?php elseif ($pending_count > 0): ?>
                        <div class="flex items-center text-yellow-700 bg-yellow-50 px-4 py-2 rounded-lg">
                            <i data-lucide="clock" class="w-5 h-5 mr-2"></i>
                            <span class="font-medium"><?php echo $pending_count; ?> employees still need to confirm this safety talk</span>
                        </div>
                    <?php endif; ?>
                </div>
                
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
                    
                    <?php if ($talk_details['has_quiz']): ?>
                        <div class="border-t pt-4 mt-4">
                            <div class="flex items-center text-purple-700 bg-purple-50 px-4 py-2 rounded-lg">
                                <i data-lucide="help-circle" class="w-5 h-5 mr-2"></i>
                                <span class="font-medium">This safety talk includes a quiz</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Employee Status Table -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-xl font-semibold text-gray-900">Employee Status</h2>
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
                                        Confirmation Date
                                    </th>
                                    <?php if ($talk_details['has_quiz']): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Quiz Score
                                    </th>
                                    <?php endif; ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($talk_details['distributions'] as $dist): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div>
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
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                                Confirmed
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php if ($dist['confirmation_date']): ?>
                                            <?php echo date('M j, Y g:i A', strtotime($dist['confirmation_date'])); ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">Not confirmed</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($talk_details['has_quiz']): ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php if ($dist['quiz_score'] !== null): ?>
                                            <span class="<?php echo $dist['quiz_score'] >= COMM_QUIZ_PASS_PERCENTAGE ? 'text-green-600 font-medium' : 'text-red-600 font-medium'; ?>">
                                                <?php echo number_format($dist['quiz_score'], 1); ?>%
                                            </span>
                                        <?php else: ?>
                                            <span class="text-gray-400">Not taken</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <?php if (!$dist['confirmation_date']): ?>
                                            <div class="flex items-center space-x-2">
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="resend_notification">
                                                    <input type="hidden" name="distribution_id" value="<?php echo $dist['distribution_id']; ?>">
                                                    <input type="hidden" name="method" value="email">
                                                    <button type="submit" class="text-blue-600 hover:text-blue-900" title="Resend Email">
                                                        <i data-lucide="mail" class="w-4 h-4"></i>
                                                    </button>
                                                </form>
                                                
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="resend_notification">
                                                    <input type="hidden" name="distribution_id" value="<?php echo $dist['distribution_id']; ?>">
                                                    <input type="hidden" name="method" value="sms">
                                                    <button type="submit" class="text-green-600 hover:text-green-900" title="Send SMS">
                                                        <i data-lucide="message-square" class="w-4 h-4"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            </div>
        </main>
        
    </div>
    
    <script>
        // Initialize Lucide icons
        lucide.createIcons();
    </script>
</body>
</html>