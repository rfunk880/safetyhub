<?php
// /public_html/usermgmt/view_profile.php
// Admin view of any user's profile

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/usermgmt_config.php';

// Authentication and permission check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Only allow Super Admin, Admin, and Manager to view other profiles
if (!isAdminUser($_SESSION['user_role_id'])) {
    header("Location: profile.php");
    exit;
}

// Get user ID from URL
$viewUserId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($viewUserId <= 0) {
    header("Location: index.php");
    exit;
}

// Fetch user data
$stmt = $conn->prepare("SELECT u.*, r.name as roleName FROM users u JOIN roles r ON u.roleId = r.id WHERE u.id = ?");
$stmt->bind_param("i", $viewUserId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['error_message'] = "User not found.";
    header("Location: index.php");
    exit;
}

// Generate profile picture URL
$profilePictureUrl = '';
if (!empty($user['profilePicture'])) {
    $profilePictureUrl = "../serve_image.php?file=" . urlencode($user['profilePicture']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Profile - <?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?> - Safety Hub</title>
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

        <!-- Main Content -->
        <main class="flex-1 overflow-auto p-6">
            <div class="max-w-4xl mx-auto">
                
                <!-- Header -->
                <div class="mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-center space-x-2 text-sm text-gray-600 mb-2">
                                <a href="index.php" class="hover:text-blue-600">User Management</a>
                                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                <span>View Profile</span>
                            </div>
                            <h1 class="text-3xl font-bold text-gray-900">
                                <?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?>
                            </h1>
                            <p class="text-gray-600 mt-2"><?php echo htmlspecialchars($user['title'] ?: 'No title assigned'); ?></p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white font-medium rounded-lg hover:bg-gray-700">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                                Back to Users
                            </a>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    <!-- Profile Picture and Basic Info -->
                    <div class="lg:col-span-1">
                        <div class="bg-white rounded-lg shadow p-6 text-center">
                            <?php if ($profilePictureUrl): ?>
                                <img src="<?php echo htmlspecialchars($profilePictureUrl); ?>" 
                                     alt="Profile Picture" 
                                     class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-white shadow-lg">
                            <?php else: ?>
                                <div class="w-32 h-32 rounded-full mx-auto mb-4 bg-gray-300 flex items-center justify-center">
                                    <i data-lucide="user" class="w-16 h-16 text-gray-500"></i>
                                </div>
                            <?php endif; ?>
                            
                            <h2 class="text-xl font-semibold text-gray-900 mb-2">
                                <?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?>
                            </h2>
                            
                            <p class="text-gray-600 mb-2"><?php echo htmlspecialchars($user['title'] ?: 'No title assigned'); ?></p>
                            <p class="text-sm text-gray-500 mb-4"><?php echo htmlspecialchars($user['roleName']); ?></p>
                            
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo isUserArchived($user) ? 'bg-gray-200 text-gray-800' : 'bg-green-100 text-green-800'; ?>">
                                <i data-lucide="<?php echo isUserArchived($user) ? 'user-x' : 'user-check'; ?>" class="w-4 h-4 mr-1"></i>
                                <?php echo isUserArchived($user) ? 'Archived' : 'Active'; ?>
                            </span>
                        </div>
                        
                        <!-- Admin Actions -->
                        <div class="bg-white rounded-lg shadow p-6 mt-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Admin Actions</h3>
                            <div class="flex flex-wrap gap-3">
                                <button onclick="openModal(<?php echo $user['id']; ?>)" 
                                        class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                    <i data-lucide="edit" class="w-4 h-4 mr-2"></i>
                                    Edit User
                                </button>
                                
                                <button onclick="sendSetupEmail(<?php echo $user['id']; ?>)" 
                                        class="inline-flex items-center px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors">
                                    <i data-lucide="mail" class="w-4 h-4 mr-2"></i>
                                    Send Setup Email
                                </button>
                                
                                <button onclick="archiveUser(<?php echo $user['id']; ?>, <?php echo isUserArchived($user) ? 'false' : 'true'; ?>)" 
                                        class="inline-flex items-center px-4 py-2 <?php echo isUserArchived($user) ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'; ?> text-white rounded-lg transition-colors">
                                    <i data-lucide="<?php echo isUserArchived($user) ? 'user-check' : 'user-x'; ?>" class="w-4 h-4 mr-2"></i>
                                    <?php echo isUserArchived($user) ? 'Un-archive User' : 'Archive User'; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Details -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-lg shadow p-6">
                            <h2 class="text-xl font-semibold text-gray-900 mb-6">Profile Information</h2>
                            
                            <!-- Personal Information -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">First Name</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['firstName']); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Last Name</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['lastName']); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Email Address</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['email']); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Role</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['roleName']); ?></p>
                                </div>
                            </div>

                            <!-- Contact Information -->
                            <div class="border-t border-gray-200 pt-6 mb-8">
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Contact Information</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">Mobile Phone</label>
                                        <p class="text-gray-800"><?php echo htmlspecialchars($user['mobile_phone'] ?: 'Not provided'); ?></p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">Alternative Phone</label>
                                        <p class="text-gray-800"><?php echo htmlspecialchars($user['alt_phone'] ?: 'Not provided'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Emergency Contact -->
                            <div class="border-t border-gray-200 pt-6 mb-8">
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Emergency Contact</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">Emergency Contact Name</label>
                                        <p class="text-gray-800"><?php echo htmlspecialchars($user['emergency_contact_name'] ?: 'Not provided'); ?></p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">Emergency Contact Phone</label>
                                        <p class="text-gray-800"><?php echo htmlspecialchars($user['emergency_contact_phone'] ?: 'Not provided'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Employment Information -->
                            <div class="border-t border-gray-200 pt-6">
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Employment Information</h3>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">Employee ID</label>
                                        <p class="text-gray-800"><?php echo htmlspecialchars($user['employeeId'] ?: 'Not assigned'); ?></p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">Employee Type</label>
                                        <p class="text-gray-800"><?php echo htmlspecialchars($user['type'] ?: 'Not specified'); ?></p>
                                    </div>

                                    <?php if (!empty($user['terminationDate'])): ?>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-600 mb-1">Termination Date</label>
                                            <p class="text-gray-800"><?php echo htmlspecialchars(date('M j, Y', strtotime($user['terminationDate']))); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
    </div>

    <!-- Hidden forms for actions -->
    <form id="actionForm" method="POST" action="index.php" class="hidden">
        <input type="hidden" name="action" id="actionType">
        <input type="hidden" name="userId" id="actionUserId">
    </form>

    <script>
        lucide.createIcons();
        
        function sendSetupEmail(userId) {
            if (confirm('Send a setup/reset email to this user?')) {
                document.getElementById('actionType').value = 'send_setup_email';
                document.getElementById('actionUserId').value = userId;
                document.getElementById('actionForm').submit();
            }
        }

        function archiveUser(userId, archive) {
            const action = archive === 'true' ? 'archive' : 'un-archive';
            if (confirm(`Are you sure you want to ${action} this user?`)) {
                document.getElementById('actionType').value = 'archive_user';
                document.getElementById('actionUserId').value = userId;
                document.getElementById('actionForm').submit();
            }
        }

        function openModal(userId) {
            // Redirect to users page and trigger edit modal
            window.location.href = `index.php?edit=${userId}`;
        }
    </script>
</body>
</html>