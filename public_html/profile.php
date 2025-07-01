<?php
// profile.php - User profile management with secure profile picture upload

require_once __DIR__ . '/../config/config.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$feedback = [];
$feedback_is_error = false;

// Handle Profile Picture Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    if ($_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/profile_pictures/';
        
        // Create directories if they don't exist
        if (!is_dir($upload_dir)) {
            $uploads_dir = __DIR__ . '/../uploads/';
            if (!is_dir($uploads_dir)) {
                mkdir($uploads_dir, 0755, true);
            }
            mkdir($upload_dir, 0755, true);
        }

        // File validation
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES['profile_picture']['type'];
        $file_size = $_FILES['profile_picture']['size'];
        $max_file_size = 2 * 1024 * 1024; // 2MB

        if (!in_array($file_type, $allowed_types)) {
            $feedback[] = 'Invalid file type. Please upload a JPG, PNG, or GIF.';
            $feedback_is_error = true;
        } elseif ($file_size > $max_file_size) {
            $feedback[] = 'File too large. Maximum size is 2MB.';
            $feedback_is_error = true;
        } else {
            // Get current profile picture for cleanup
            $stmt_current = $conn->prepare("SELECT profilePicture FROM users WHERE id = ?");
            $stmt_current->bind_param("i", $userId);
            $stmt_current->execute();
            $current_result = $stmt_current->get_result();
            $current_user = $current_result->fetch_assoc();
            $old_profile_picture = $current_user['profilePicture'] ?? null;
            $stmt_current->close();

            // Generate unique filename
            $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $new_filename = 'user_' . $userId . '_' . time() . '.' . $file_extension;
            $full_path = $upload_dir . $new_filename;

            // Upload and update database
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $full_path)) {
                $stmt = $conn->prepare("UPDATE users SET profilePicture = ? WHERE id = ?");
                $stmt->bind_param("si", $new_filename, $userId);
                
                if ($stmt->execute()) {
                    // Delete old file if exists
                    if ($old_profile_picture && $old_profile_picture !== $new_filename) {
                        $old_full_path = $upload_dir . $old_profile_picture;
                        if (file_exists($old_full_path)) {
                            unlink($old_full_path);
                        }
                    }
                    $feedback[] = 'Profile picture updated successfully!';
                } else {
                    // Cleanup on database failure
                    if (file_exists($full_path)) {
                        unlink($full_path);
                    }
                    $feedback[] = 'Database error. Could not save picture.';
                    $feedback_is_error = true;
                }
                $stmt->close();
            } else {
                $feedback[] = 'Could not upload file. Please try again.';
                $feedback_is_error = true;
            }
        }
    } else {
        $feedback[] = 'File upload error. Please try again.';
        $feedback_is_error = true;
    }
}

// Fetch user data
$stmt = $conn->prepare("SELECT u.*, r.name as roleName FROM users u JOIN roles r ON u.roleId = r.id WHERE u.id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    die("Error: User not found.");
}

// Set variables for display
$loggedInUserProfilePicture = $user['profilePicture'];
$loggedInUserRoleName = $user['roleName'];
$admin_roles = [1, 2, 3];
$can_see_user_management = in_array($_SESSION['user_role_id'], $admin_roles);
$admin_roles_no_change = [1, 2];
$can_change_password = !in_array($_SESSION['user_role_id'], $admin_roles_no_change);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Safety Hub</title>
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
                    <h2 class="text-2xl font-semibold text-gray-800">My Profile</h2>
                </div>
            </header>

            <!-- Content -->
            <div class="flex-1 overflow-y-auto p-6">
                <!-- Feedback Messages -->
                <?php if (!empty($feedback)): ?>
                    <div class="mb-6 p-4 rounded-lg <?php echo $feedback_is_error ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                        <?php foreach($feedback as $msg): ?>
                            <p class="<?php echo $feedback_is_error ? 'text-red-700' : 'text-green-700'; ?>"><?php echo htmlspecialchars($msg); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Profile Card -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden max-w-4xl mx-auto">
                    <!-- Header Section -->
                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-8 text-white">
                        <div class="flex items-center">
                            <div class="mr-6">
                                <?php if (!empty($user['profilePicture']) && getProfilePicturePath($user['profilePicture'])): ?>
                                    <img src="serve_image.php?file=<?php echo urlencode($user['profilePicture']); ?>" 
                                         alt="Profile Picture" 
                                         class="w-24 h-32 object-cover border-2 border-white rounded-lg shadow-lg">
                                <?php else: ?>
                                    <div class="w-24 h-32 bg-blue-400 border-2 border-white rounded-lg shadow-lg flex flex-col items-center justify-center">
                                        <i data-lucide="user" class="w-12 h-12 text-white mb-2"></i>
                                        <span class="text-xs text-white text-center">No Photo</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h1 class="text-3xl font-bold mb-2"><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></h1>
                                <p class="text-xl text-blue-100 mb-1"><?php echo htmlspecialchars($user['title']); ?></p>
                                <p class="text-blue-200"><?php echo htmlspecialchars($loggedInUserRoleName); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Content Section -->
                    <div class="p-6">
                        <!-- Profile Picture Upload -->
                        <div class="mb-8 pb-6 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Profile Picture</h3>
                            <div class="flex items-start space-x-6">
                                <div class="text-center">
                                    <?php if (!empty($user['profilePicture']) && getProfilePicturePath($user['profilePicture'])): ?>
                                        <img src="serve_image.php?file=<?php echo urlencode($user['profilePicture']); ?>" 
                                             alt="Current Profile Picture" 
                                             class="w-32 h-40 object-cover border-2 border-gray-300 rounded-lg shadow-md mb-2">
                                    <?php else: ?>
                                        <div class="w-32 h-40 bg-gray-100 border-2 border-gray-300 rounded-lg shadow-md flex flex-col items-center justify-center mb-2">
                                            <i data-lucide="user" class="w-16 h-16 text-gray-400 mb-2"></i>
                                            <span class="text-sm text-gray-500">No Photo</span>
                                        </div>
                                    <?php endif; ?>
                                    <p class="text-xs text-gray-500">ID Card Format</p>
                                </div>
                                <div class="flex-1">
                                    <form action="profile.php" method="post" enctype="multipart/form-data" class="mb-4">
                                        <label for="profile_picture" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 cursor-pointer transition-colors">
                                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                                            Upload New Picture
                                        </label>
                                        <input type="file" name="profile_picture" id="profile_picture" class="hidden" onchange="this.form.submit()">
                                    </form>
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <h4 class="text-sm font-medium text-gray-800 mb-2">Requirements:</h4>
                                        <ul class="text-sm text-gray-600 space-y-1">
                                            <li>• Maximum 2MB file size</li>
                                            <li>• JPG, PNG, or GIF format</li>
                                            <li>• Portrait orientation recommended</li>
                                            <li>• Clear headshot with neutral background</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Personal Information -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-6">
                                <h3 class="text-lg font-semibold text-gray-800 pb-2 border-b border-gray-200">Personal Information</h3>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Email Address</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['email']); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Mobile Phone</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['mobile_phone'] ?: 'Not provided'); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Employee ID</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['employeeId'] ?: 'Not assigned'); ?></p>
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

                                <!-- Password Change Link -->
                                <?php if ($can_change_password): ?>
                                    <div class="pt-4">
                                        <a href="change_password.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                                            <i data-lucide="key" class="w-4 h-4 mr-2"></i>
                                            Change Password
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        lucide.createIcons();
        document.getElementById('menu-button').addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('open');
        });
    </script>
</body>
</html>