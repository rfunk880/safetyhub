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
        <?php renderNavigation(); ?>
        
        <!-- Main Content Area -->
        <main class="flex-1 overflow-auto p-6">
            <div class="max-w-6xl mx-auto">
                
                <!-- Header -->
                <div class="mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">Safety Talk History</h1>
                            <p class="text-gray-600 mt-2">All distributed safety communications and their status</p>
                        </div>
                        <div class="space-x-3">
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
                
                <!-- Statistics Summary -->
                <?php if (!empty($past_talks)): ?>
                    <?php 
                    $total_talks = count($past_talks);
                    $total_distributed = array_sum(array_column($past_talks, 'total_distributed'));
                    $total_confirmed = array_sum(array_column($past_talks, 'total_confirmed'));
                    $completion_rate = $total_distributed > 0 ? ($total_confirmed / $total_distributed) * 100 : 0;
                    ?>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                        <div class="bg-white rounded-lg shadow p-6">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                        <i data-lucide="message-circle" class="w-6 h-6 text-blue-600"></i>
                                    </div>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600">Total Talks</p>
                                    <p class="text-2xl font-bold text-gray-900"><?php echo $total_talks; ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow p-6">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                        <i data-lucide="send" class="w-6 h-6 text-green-600"></i>
                                    </div>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600">Total Distributed</p>
                                    <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_distributed); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow p-6">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                                        <i data-lucide="check-circle" class="w-6 h-6 text-purple-600"></i>
                                    </div>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600">Total Confirmed</p>
                                    <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_confirmed); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow p-6">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                        <i data-lucide="target" class="w-6 h-6 text-yellow-600"></i>
                                    </div>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600">Completion Rate</p>
                                    <p class="text-2xl font-bold text-gray-900"><?php echo number_format($completion_rate, 1); ?>%</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Safety Talks Table -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">All Safety Talks</h2>
                    </div>
                    
                    <?php if (!empty($past_talks)): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Talk Title
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
                                        if ($completion_percentage == 100) {
                                            $status_color = 'bg-green-100 text-green-800';
                                            $status_text = 'Complete';
                                        } elseif ($completion_percentage >= 75) {
                                            $status_color = 'bg-blue-100 text-blue-800';
                                            $status_text = 'Nearly Complete';
                                        } elseif ($completion_percentage >= 50) {
                                            $status_color = 'bg-yellow-100 text-yellow-800';
                                            $status_text = 'In Progress';
                                        } else {
                                            $status_color = 'bg-red-100 text-red-800';
                                            $status_text = 'Low Response';
                                        }
                                        ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($talk['title']); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                <?php echo date('M j, Y', strtotime($talk['initial_distribution'])); ?>
                                                <div class="text-xs text-gray-400">
                                                    <?php echo date('g:i A', strtotime($talk['initial_distribution'])); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                <?php echo date('M j, Y', strtotime($talk['last_sent'])); ?>
                                                <div class="text-xs text-gray-400">
                                                    <?php echo date('g:i A', strtotime($talk['last_sent'])); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <div class="flex-1">
                                                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                                                            <span><?php echo $talk['total_confirmed']; ?> of <?php echo $talk['total_distributed']; ?></span>
                                                            <span><?php echo number_format($completion_percentage, 1); ?>%</span>
                                                        </div>
                                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                                            <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo $completion_percentage; ?>%"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_color; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <a href="talk_details.php?id=<?php echo $talk['id']; ?>" 
                                                   class="text-blue-600 hover:text-blue-900 mr-3">
                                                    View Details
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12">
                            <i data-lucide="message-circle" class="w-12 h-12 text-gray-400 mx-auto mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No Safety Talks Found</h3>
                            <p class="text-gray-600 mb-6">Get started by creating your first safety communication.</p>
                            <a href="create_talk.php" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700">
                                <i data-lucide="plus" class="w-5 h-5 mr-2"></i>
                                Create Safety Talk
                            </a>
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