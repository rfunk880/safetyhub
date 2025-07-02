<?php
// /public_html/communication/index.php
// Communication Module Dashboard

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

// Get dashboard data
$pending_reports = getPendingSignaturesReport($conn);
$overall_status = getOverallStatusReport($conn);
$recent_talks = getPastSafetyTalks($conn);

// Limit recent talks to last 5
$recent_talks = array_slice($recent_talks, 0, 5);

// Calculate summary statistics
$total_pending = 0;
$total_distributed = 0;
$total_confirmed = 0;

foreach ($pending_reports as $report) {
    $total_distributed += $report['total_distributed'];
    $total_confirmed += $report['total_signed'];
    $total_pending += ($report['total_distributed'] - $report['total_signed']);
}

// Get message from URL if present
$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Communications Dashboard - Safety Hub</title>
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
        .stat-card { transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen">
        
        <!-- Automatic Navigation -->
        <?php renderNavigation(); ?>
        
        <!-- Main Content Area -->
        <main class="flex-1 overflow-auto p-6">
            <div class="max-w-7xl mx-auto">
                
                <!-- Header -->
                <div class="mb-6">
                    <h1 class="text-3xl font-bold text-gray-900">Communications Dashboard</h1>
                    <p class="text-gray-600 mt-2">Manage and track safety communications across your organization</p>
                </div>
                
                <!-- Success/Error Messages -->
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
                
                <!-- Quick Actions -->
                <div class="mb-8">
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-semibold text-gray-900 mb-4">Quick Actions</h2>
                        <div class="flex flex-wrap gap-4">
                            <a href="create_talk.php" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                                <i data-lucide="plus" class="w-5 h-5 mr-2"></i>
                                Create Safety Talk
                            </a>
                            <a href="history.php" class="inline-flex items-center px-6 py-3 bg-gray-600 text-white font-medium rounded-lg hover:bg-gray-700 transition-colors">
                                <i data-lucide="history" class="w-5 h-5 mr-2"></i>
                                View History
                            </a>
                            <a href="archived.php" class="inline-flex items-center px-6 py-3 bg-gray-600 text-white font-medium rounded-lg hover:bg-gray-700 transition-colors">
                                <i data-lucide="archive" class="w-5 h-5 mr-2"></i>
                                Archived Talks
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <!-- Total Distributed -->
                    <div class="stat-card bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i data-lucide="send" class="w-6 h-6 text-blue-600"></i>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Total Distributed</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_distributed); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Confirmed -->
                    <div class="stat-card bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i data-lucide="check-circle" class="w-6 h-6 text-green-600"></i>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Total Confirmed</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_confirmed); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pending Signatures -->
                    <div class="stat-card bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                    <i data-lucide="clock" class="w-6 h-6 text-yellow-600"></i>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Pending Signatures</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_pending); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Signatures Report -->
                <?php if (!empty($pending_reports)): ?>
                <div class="mb-8">
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-900">Pending Signatures (Last 30 Days)</h2>
                            <p class="text-sm text-gray-600 mt-1">Safety talks awaiting employee confirmation</p>
                        </div>
                        <div class="p-6">
                            <div class="space-y-4">
                                <?php foreach ($pending_reports as $report): ?>
                                    <?php
                                    $total_dist = (int)$report['total_distributed'];
                                    $total_signed = (int)$report['total_signed'];
                                    $pending_count = $total_dist - $total_signed;
                                    $completion_percentage = $total_dist > 0 ? ($total_signed / $total_dist) * 100 : 0;
                                    ?>
                                    <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                                        <div class="flex justify-between items-start mb-3">
                                            <div>
                                                <h3 class="font-medium text-gray-900"><?php echo htmlspecialchars($report['safety_talk_title']); ?></h3>
                                                <p class="text-sm text-gray-600">
                                                    Distributed <?php echo $report['days_since_distribution']; ?> days ago
                                                </p>
                                            </div>
                                            <a href="talk_details.php?id=<?php echo $report['safety_talk_id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                                View Details →
                                            </a>
                                        </div>
                                        
                                        <!-- Progress Bar -->
                                        <div class="mb-3">
                                            <div class="flex justify-between text-sm text-gray-600 mb-1">
                                                <span><?php echo $total_signed; ?> of <?php echo $total_dist; ?> confirmed</span>
                                                <span><?php echo number_format($completion_percentage, 1); ?>%</span>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-2">
                                                <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo $completion_percentage; ?>%"></div>
                                            </div>
                                        </div>
                                        
                                        <!-- Pending Employees -->
                                        <?php if ($pending_count > 0 && !empty($report['pending_employee_names'])): ?>
                                        <div class="text-sm">
                                            <span class="font-medium text-gray-700">Pending (<?php echo $pending_count; ?>):</span>
                                            <span class="text-gray-600"><?php echo htmlspecialchars($report['pending_employee_names']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="mb-8">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="text-center py-8">
                            <i data-lucide="check-circle" class="w-16 h-16 text-green-500 mx-auto mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">All Clear!</h3>
                            <p class="text-gray-600">No pending signatures for active safety talks.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Recent Activity -->
                <?php if (!empty($recent_talks)): ?>
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-xl font-semibold text-gray-900">Recent Safety Talks</h2>
                        <p class="text-sm text-gray-600 mt-1">Latest safety communications activity</p>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <?php foreach ($recent_talks as $talk): ?>
                            <div class="flex justify-between items-center py-3 border-b border-gray-100 last:border-b-0">
                                <div>
                                    <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($talk['title']); ?></h4>
                                    <p class="text-sm text-gray-600">
                                        <?php echo $talk['total_confirmed']; ?> of <?php echo $talk['total_distributed']; ?> confirmed
                                        • Last sent <?php echo $talk['last_sent'] ? date('M j, Y', strtotime($talk['last_sent'])) : 'Never'; ?>
                                    </p>
                                </div>
                                <a href="talk_details.php?id=<?php echo $talk['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                    View →
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-4 text-center">
                            <a href="history.php" class="text-blue-600 hover:text-blue-800 font-medium">View All History →</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
        </main>
        
    </div>
    
    <script>
        // Initialize Lucide icons
        lucide.createIcons();
    </script>
</body>
</html>