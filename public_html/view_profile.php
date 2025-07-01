<?php
// view_profile.php - Admin view of any user's profile

require_once __DIR__ . '/../config/config.php';

// Authentication and permission check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Only allow Super Admin, Admin, and Manager to view other profiles
$admin_roles = [1, 2, 3];
if (!in_array($_SESSION['user_role_id'], $admin_roles)) {
    header("Location: profile.php");
    exit;
}

// Get user ID from URL
$viewUserId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($viewUserId <= 0) {
    header("Location: users.php");
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
    header("Location: users.php");
    exit;
}

// Get logged-in user info for sidebar
$stmt = $conn->prepare("SELECT u.*, r.name as roleName FROM users u JOIN roles r ON u.roleId = r.id WHERE u.id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$loggedInUser = $result->fetch_assoc();
$stmt->close();

$loggedInUserProfilePicture = $loggedInUser['profilePicture'];
$loggedInUserRoleName = $loggedInUser['roleName'];
$can_see_user_management = true; // All admin roles can see user management
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
<body class="bg-gray-50">
    <div class="flex h-screen">
        <!-- Sidebar -->
<!-- NEW - Just this one line -->
<?php renderNavigation(); ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Header -->
            <header class="flex justify-between items-center p-6 bg-white border-b border-gray-200">
                <div class="flex items-center">
                    <button id="menu-button" class="md:hidden text-gray-500 hover:text-gray-700 mr-4">
                        <i data-lucide="menu" class="w-6 h-6"></i>
                    </button>
                    <h2 class="text-2xl font-semibold text-gray-800">User Profile</h2>
                </div>
                <a href="users.php" class="flex items-center text-blue-600 hover:text-blue-800 transition-colors">
                    <i data-lucide="arrow-left" class="w-5 h-5 mr-2"></i>
                    Back to User Management
                </a>
            </header>

            <!-- Content -->
            <div class="flex-1 overflow-y-auto p-6">
                <!-- Profile Card -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden max-w-4xl mx-auto">
                    <!-- Header Section -->
                    <div class="bg-gradient-to-r from-indigo-500 to-indigo-600 px-6 py-8 text-white">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="mr-6">
                                    <?php if (!empty($user['profilePicture']) && getProfilePicturePath($user['profilePicture'])): ?>
                                        <img src="serve_image.php?file=<?php echo urlencode($user['profilePicture']); ?>" 
                                             alt="Profile Picture" 
                                             class="w-24 h-32 object-cover border-2 border-white rounded-lg shadow-lg">
                                    <?php else: ?>
                                        <div class="w-24 h-32 bg-indigo-400 border-2 border-white rounded-lg shadow-lg flex flex-col items-center justify-center">
                                            <i data-lucide="user" class="w-12 h-12 text-white mb-2"></i>
                                            <span class="text-xs text-white text-center">No Photo</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h1 class="text-3xl font-bold mb-2"><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></h1>
                                    <p class="text-xl text-indigo-100 mb-1"><?php echo htmlspecialchars($user['title']); ?></p>
                                    <p class="text-indigo-200"><?php echo htmlspecialchars($user['roleName']); ?></p>
                                </div>
                            </div>
                            <!-- Status Badge -->
                            <div class="text-right">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo isUserArchived($user) ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'; ?>">
                                    <?php echo isUserArchived($user) ? 'Archived' : 'Active'; ?>
                                </span>
                                <p class="text-indigo-200 text-sm mt-2"><?php echo htmlspecialchars($user['type']); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Content Section -->
                    <div class="p-6">
                        <!-- Contact Information -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                            <div class="space-y-6">
                                <h3 class="text-lg font-semibold text-gray-800 pb-2 border-b border-gray-200">Contact Information</h3>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Email Address</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['email']); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Mobile Phone</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['mobile_phone'] ?: 'Not provided'); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Alternate Phone</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['alt_phone'] ?: 'Not provided'); ?></p>
                                </div>
                            </div>

                            <div class="space-y-6">
                                <h3 class="text-lg font-semibold text-gray-800 pb-2 border-b border-gray-200">Emergency Contact</h3>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Contact Name</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['emergency_contact_name'] ?: 'Not provided'); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Contact Phone</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['emergency_contact_phone'] ?: 'Not provided'); ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Employment Information -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-6 border-t border-gray-200">
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1">Employee ID</label>
                                <p class="text-gray-800"><?php echo htmlspecialchars($user['employeeId'] ?: 'Not assigned'); ?></p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1">Employee Type</label>
                                <p class="text-gray-800"><?php echo htmlspecialchars($user['type']); ?></p>
                            </div>

                            <?php if (!empty($user['terminationDate'])): ?>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Termination Date</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars(date('M j, Y', strtotime($user['terminationDate']))); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Admin Actions -->
                        <div class="pt-6 border-t border-gray-200 mt-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Admin Actions</h3>
                            <div class="flex flex-wrap gap-3">
                                <a href="users.php" onclick="openModal(<?php echo $user['id']; ?>); return false;" 
                                   class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                    <i data-lucide="edit" class="w-4 h-4 mr-2"></i>
                                    Edit User
                                </a>
                                
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
                </div>
            </div>
        </main>
    </div>

    <!-- Hidden forms for actions -->
    <form id="actionForm" method="POST" action="users.php" class="hidden">
        <input type="hidden" name="action" id="actionType">
        <input type="hidden" name="userId" id="actionUserId">
    </form>

    <script>
        lucide.createIcons();
        
        document.getElementById('menu-button').addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('open');
        });

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
            window.location.href = `users.php?edit=${userId}`;
        }
    </script>
</body>
</html>