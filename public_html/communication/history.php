<?php
// /public_html/communication/history.php
// Safety Talk History

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

// Get safety talk history
$past_talks = getPastSafetyTalks($conn);

// Get message from URL if present
$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safety Talk History - Safety Hub</title>
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
                            <h1 class="text-3xl font-bold text-gray-900">Safety Talk History</h1>
                            <p class="text-gray-600 mt-2">View and manage all safety communications and their status</p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white font-medium rounded-lg hover:bg-gray-700">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                                Back to Dashboard
                            </a>
                            <a href="archived.php" class="inline-flex items-center px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                                <i data-lucide="archive" class="w-4 h-4 mr-2"></i>
                                View Archived
                            </a>
                            <a href="create_talk.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700">
                                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                                Create New
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
                
                <!-- Safety Talks Table -->
                <?php if (!empty($past_talks)): ?>
                    <div class="bg-white rounded-lg shadow">
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
                                            Progress
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($past_talks as $talk): ?>
                                        <?php
                                        $completion_percentage = $talk['total_distributed'] > 0 ?
                                            ($talk['total_confirmed'] / $talk['total_distributed']) * 100 : 0;
                                        
                                        // Determine status
                                        if ($talk['total_distributed'] == 0) {
                                            $status = 'Never Distributed';
                                            $status_color = 'bg-gray-100 text-gray-800';
                                        } elseif ($talk['total_confirmed'] == $talk['total_distributed']) {
                                            $status = 'Complete';
                                            $status_color = 'bg-green-100 text-green-800';
                                        } else {
                                            $status = 'In Progress';
                                            $status_color = 'bg-yellow-100 text-yellow-800';
                                        }
                                        ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($talk['title']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    Created: <?php echo date('M j, Y', strtotime($talk['created_at'])); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php if ($talk['initial_distribution']): ?>
                                                    <div class="font-medium text-gray-900">
                                                        <?php echo date('M j, Y', strtotime($talk['initial_distribution'])); ?>
                                                    </div>
                                                    <div class="text-xs">
                                                        <?php echo date('g:i A', strtotime($talk['initial_distribution'])); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-gray-400 italic">Not distributed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php if ($talk['last_sent'] && $talk['last_sent'] !== $talk['initial_distribution']): ?>
                                                    <div class="font-medium text-gray-900">
                                                        <?php echo date('M j, Y', strtotime($talk['last_sent'])); ?>
                                                    </div>
                                                    <div class="text-xs">
                                                        <?php echo date('g:i A', strtotime($talk['last_sent'])); ?>
                                                    </div>
                                                <?php elseif ($talk['initial_distribution']): ?>
                                                    <span class="text-gray-400 italic">Same as initial</span>
                                                <?php else: ?>
                                                    <span class="text-gray-400 italic">Never sent</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-1">
                                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                                            <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" 
                                                                 style="width: <?php echo $completion_percentage; ?>%"></div>
                                                        </div>
                                                    </div>
                                                    <div class="ml-3 text-sm font-medium text-gray-900">
                                                        <?php echo $talk['total_confirmed']; ?>/<?php echo $talk['total_distributed']; ?>
                                                    </div>
                                                </div>
                                                <div class="text-xs text-gray-500 mt-1">
                                                    <?php echo round($completion_percentage); ?>% complete
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_color; ?>">
                                                    <?php echo $status; ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <div class="flex items-center space-x-3">
                                                    <!-- View Details Icon -->
                                                    <a href="talk_details.php?id=<?php echo $talk['id']; ?>" 
                                                       title="View Details"
                                                       class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-100 rounded-full transition-colors">
                                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                                    </a>
                                                    
                                                    <!-- Edit Icon - AVAILABLE FOR ALL TALKS INCLUDING "NEVER DISTRIBUTED" -->
                                                    <?php if ($talk['status'] === 'draft' || $talk['total_distributed'] == 0): ?>
                                                        <a href="edit_talk.php?id=<?php echo $talk['id']; ?>" 
                                                           title="Edit Safety Talk"
                                                           class="p-2 text-green-600 hover:text-green-900 hover:bg-green-100 rounded-full transition-colors">
                                                            <i data-lucide="edit" class="w-4 h-4"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <span title="Cannot edit after distribution" 
                                                              class="p-2 text-gray-400 cursor-not-allowed rounded-full">
                                                            <i data-lucide="edit" class="w-4 h-4"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Distribute/Send Icon - Only for Never Distributed talks -->
                                                    <?php if ($talk['total_distributed'] == 0): ?>
                                                        <a href="distribute_talk.php?id=<?php echo $talk['id']; ?>" 
                                                           title="Distribute Safety Talk"
                                                           class="p-2 text-purple-600 hover:text-purple-900 hover:bg-purple-100 rounded-full transition-colors">
                                                            <i data-lucide="send" class="w-4 h-4"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <span title="Already distributed" 
                                                              class="p-2 text-gray-400 cursor-not-allowed rounded-full">
                                                            <i data-lucide="send" class="w-4 h-4"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Archive Icon -->
                                                    <button onclick="archiveTalk(<?php echo $talk['id']; ?>)" 
                                                            title="Archive Safety Talk"
                                                            class="p-2 text-orange-600 hover:text-orange-900 hover:bg-orange-100 rounded-full transition-colors">
                                                        <i data-lucide="archive" class="w-4 h-4"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow p-12 text-center">
                        <i data-lucide="message-square" class="w-16 h-16 mx-auto text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Safety Talks</h3>
                        <p class="text-gray-500 mb-6">You haven't created any safety talks yet.</p>
                        <div class="flex justify-center space-x-3">
                            <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white font-medium rounded-lg hover:bg-gray-700">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                                Back to Dashboard
                            </a>
                            <a href="create_talk.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700">
                                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                                Create Your First Safety Talk
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
                
            </div>
        </main>
    </div>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();
        
        // Archive safety talk function
        function archiveTalk(talkId) {
            if (confirm('Are you sure you want to archive this safety talk? It will be moved to the archived section.')) {
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'talk_details.php?id=' + talkId;
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'archive_talk';
                
                form.appendChild(actionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>