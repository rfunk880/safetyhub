<?php
// /public_html/communication/archived.php
// Archived Safety Talks Page

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

// Handle POST actions (archive/unarchive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $talk_id = (int)$_POST['talk_id'];
    
    if ($action === 'unarchive_talk' && $talk_id > 0) {
        $stmt = $conn->prepare("UPDATE safety_talks SET is_archived = 0 WHERE id = ?");
        $stmt->bind_param("i", $talk_id);
        
        if ($stmt->execute()) {
            $message = "Safety talk has been unarchived successfully.";
        } else {
            $error = "Failed to unarchive safety talk.";
        }
        $stmt->close();
    }
}

// Get archived safety talks
$archived_talks = getArchivedSafetyTalks($conn);

// Get message from URL if present
$message = $_GET['message'] ?? $message ?? '';
$error = $_GET['error'] ?? $error ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Safety Talks - Safety Hub</title>
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
                            <h1 class="text-3xl font-bold text-gray-900">Archived Safety Talks</h1>
                            <p class="text-gray-600 mt-2">View and manage archived safety communications</p>
                        </div>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white font-medium rounded-lg hover:bg-gray-700">
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
                
                <?php if ($error): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Archived Talks Table -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-xl font-semibold text-gray-900">Archived Safety Talks</h2>
                        <p class="text-sm text-gray-600 mt-1">Safety talks that have been archived</p>
                    </div>
                    
                    <?php if (!empty($archived_talks)): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Safety Talk
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Initial Distribution
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Last Sent
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Distributed
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Confirmed
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($archived_talks as $talk): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($talk['title']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?php if ($talk['initial_distribution']): ?>
                                            <?php echo date('M j, Y g:i A', strtotime($talk['initial_distribution'])); ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">Never distributed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?php if ($talk['last_sent']): ?>
                                            <?php echo date('M j, Y g:i A', strtotime($talk['last_sent'])); ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <?php echo number_format($talk['total_distributed']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <?php echo number_format($talk['total_confirmed']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-medium space-x-2">
                                        <a href="talk_details.php?id=<?php echo $talk['id']; ?>" 
                                           class="text-blue-600 hover:text-blue-900">
                                            View Details
                                        </a>
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Are you sure you want to unarchive this safety talk?');">
                                            <input type="hidden" name="action" value="unarchive_talk">
                                            <input type="hidden" name="talk_id" value="<?php echo $talk['id']; ?>">
                                            <button type="submit" class="text-green-600 hover:text-green-900 border-none bg-transparent cursor-pointer">
                                                Unarchive
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="p-12 text-center">
                        <i data-lucide="archive" class="w-16 h-16 mx-auto text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Archived Safety Talks</h3>
                        <p class="text-gray-500">No safety talks have been archived yet.</p>
                        <div class="mt-6">
                            <a href="index.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                                Back to Dashboard
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
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