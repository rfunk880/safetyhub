<?php
// /public_html/usermgmt/profile.php
// User profile management with secure profile picture upload

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/usermgmt_config.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$feedback = [];
$feedback_is_error = false;

// Handle Profile Picture Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    if ($_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = PROFILE_PICTURE_UPLOAD_DIR;
        
        // Create directories if they don't exist
        if (!is_dir($upload_dir)) {
            $uploads_dir = dirname($upload_dir);
            if (!is_dir($uploads_dir)) {
                mkdir($uploads_dir, 0755, true);
            }
            mkdir($upload_dir, 0755, true);
        }

        // File validation
        $file_type = $_FILES['profile_picture']['type'];
        $file_size = $_FILES['profile_picture']['size'];

        if (!in_array($file_type, PROFILE_PICTURE_ALLOWED_TYPES)) {
            $feedback[] = 'Invalid file type. Please upload a JPG, PNG, or GIF.';
            $feedback_is_error = true;
        } elseif ($file_size > PROFILE_PICTURE_MAX_SIZE) {
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
                        deleteProfilePicture($old_profile_picture);
                    }
                    
                    $feedback[] = 'Profile picture updated successfully!';
                } else {
                    $feedback[] = 'Failed to update profile picture in database.';
                    $feedback_is_error = true;
                    // Clean up uploaded file
                    unlink($full_path);
                }
                $stmt->close();
            } else {
                $feedback[] = 'Failed to upload file.';
                $feedback_is_error = true;
            }
        }
    } else {
        $feedback[] = 'Upload error: ' . $_FILES['profile_picture']['error'];
        $feedback_is_error = true;
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $mobile_phone = trim($_POST['mobile_phone'] ?? '');
    $alt_phone = trim($_POST['alt_phone'] ?? '');
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
    
    // Validate phone numbers
    $errors = [];
    if ($mobile_phone && !validatePhoneNumber($mobile_phone)) {
        $errors[] = 'Mobile phone format is invalid. Use format: ' . PHONE_NUMBER_EXAMPLE;
    }
    if ($alt_phone && !validatePhoneNumber($alt_phone)) {
        $errors[] = 'Alternative phone format is invalid. Use format: ' . PHONE_NUMBER_EXAMPLE;
    }
    if ($emergency_contact_phone && !validatePhoneNumber($emergency_contact_phone)) {
        $errors[] = 'Emergency contact phone format is invalid. Use format: ' . PHONE_NUMBER_EXAMPLE;
    }
    
    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE users SET mobile_phone = ?, alt_phone = ?, emergency_contact_name = ?, emergency_contact_phone = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $mobile_phone, $alt_phone, $emergency_contact_name, $emergency_contact_phone, $userId);
        
        if ($stmt->execute()) {
            $feedback[] = 'Profile updated successfully!';
        } else {
            $feedback[] = 'Failed to update profile.';
            $feedback_is_error = true;
        }
        $stmt->close();
    } else {
        $feedback = array_merge($feedback, $errors);
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
    die("User not found.");
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
            <div class="max-w-4xl mx-auto">
                
                <!-- Header -->
                <div class="mb-6">
                    <h1 class="text-3xl font-bold text-gray-900">My Profile</h1>
                    <p class="text-gray-600 mt-2">Manage your personal information and settings</p>
                </div>
                
                <!-- Feedback Messages -->
                <?php if (!empty($feedback)): ?>
                    <div class="mb-6 p-4 rounded-lg <?php echo $feedback_is_error ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-green-50 border border-green-200 text-green-700'; ?>">
                        <?php foreach ($feedback as $message): ?>
                            <div class="flex items-center">
                                <i data-lucide="<?php echo $feedback_is_error ? 'alert-circle' : 'check-circle'; ?>" class="w-5 h-5 mr-2"></i>
                                <?php echo htmlspecialchars($message); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    <!-- Profile Information Section -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-lg shadow p-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-6">Profile Information</h2>
                            
                            <!-- Read-only Information -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8 p-4 bg-gray-50 rounded-lg">
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">First Name</label>
                                    <p class="text-gray-800 font-medium"><?php echo htmlspecialchars($user['firstName']); ?></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Last Name</label>
                                    <p class="text-gray-800 font-medium"><?php echo htmlspecialchars($user['lastName']); ?></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Email Address</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['email']); ?></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Employee ID</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['employeeId'] ?: 'Not assigned'); ?></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Job Title</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['title'] ?: 'Not specified'); ?></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1">Role</label>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($user['roleName']); ?></p>
                                </div>
                            </div>
                            
                            <!-- Editable Contact Information -->
                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="update_profile" value="1">
                                
                                <h3 class="text-md font-semibold text-gray-900 border-b border-gray-200 pb-2">
                                    Contact Information
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="mobile_phone" class="block text-sm font-medium text-gray-700 mb-2">
                                            Mobile Phone
                                        </label>
                                        <input type="tel" 
                                               id="mobile_phone" 
                                               name="mobile_phone" 
                                               value="<?php echo htmlspecialchars($user['mobile_phone'] ?? ''); ?>"
                                               placeholder="<?php echo PHONE_NUMBER_EXAMPLE; ?>"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <p class="text-xs text-gray-500 mt-1">Format: <?php echo PHONE_NUMBER_EXAMPLE; ?></p>
                                    </div>
                                    
                                    <div>
                                        <label for="alt_phone" class="block text-sm font-medium text-gray-700 mb-2">
                                            Alternative Phone
                                        </label>
                                        <input type="tel" 
                                               id="alt_phone" 
                                               name="alt_phone" 
                                               value="<?php echo htmlspecialchars($user['alt_phone'] ?? ''); ?>"
                                               placeholder="<?php echo PHONE_NUMBER_EXAMPLE; ?>"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                </div>
                                
                                <h3 class="text-md font-semibold text-gray-900 border-b border-gray-200 pb-2">
                                    Emergency Contact
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700 mb-2">
                                            Emergency Contact Name
                                        </label>
                                        <input type="text" 
                                               id="emergency_contact_name" 
                                               name="emergency_contact_name" 
                                               value="<?php echo htmlspecialchars($user['emergency_contact_name'] ?? ''); ?>"
                                               placeholder="Full name of emergency contact"
                                               maxlength="<?php echo USER_FIELD_LENGTHS['emergency_contact_name']; ?>"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    
                                    <div>
                                        <label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700 mb-2">
                                            Emergency Contact Phone
                                        </label>
                                        <input type="tel" 
                                               id="emergency_contact_phone" 
                                               name="emergency_contact_phone" 
                                               value="<?php echo htmlspecialchars($user['emergency_contact_phone'] ?? ''); ?>"
                                               placeholder="<?php echo PHONE_NUMBER_EXAMPLE; ?>"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                </div>
                                
                                <div class="flex justify-end">
                                    <button type="submit" 
                                            class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                        <i data-lucide="save" class="w-4 h-4 inline mr-2"></i>
                                        Update Profile
                                    </button>
                                </div>
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
        
        // Phone number formatting
        function formatPhoneNumber(input) {
            // Remove all non-digits
            let value = input.value.replace(/\D/g, '');
            
            // Add +1 prefix if not present and we have 10 digits
            if (value.length === 10) {
                value = '1' + value;
            }
            
            // Add + prefix
            if (value.length === 11 && value.startsWith('1')) {
                value = '+' + value;
            }
            
            input.value = value;
        }
        
        // Add event listeners to phone inputs
        document.querySelectorAll('input[type="tel"]').forEach(input => {
            input.addEventListener('blur', () => formatPhoneNumber(input));
        });
    </script>
</body>
</html> Profile Picture Section -->
                    <div class="lg:col-span-1">
                        <div class="bg-white rounded-lg shadow p-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Profile Picture</h2>
                            
                            <div class="text-center">
                                <?php if ($profilePictureUrl): ?>
                                    <img src="<?php echo htmlspecialchars($profilePictureUrl); ?>" 
                                         alt="Profile Picture" 
                                         class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-white shadow-lg">
                                <?php else: ?>
                                    <div class="w-32 h-32 rounded-full mx-auto mb-4 bg-gray-300 flex items-center justify-center">
                                        <i data-lucide="user" class="w-16 h-16 text-gray-500"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                                    <div>
                                        <input type="file" 
                                               name="profile_picture" 
                                               accept="image/jpeg,image/png,image/gif" 
                                               class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                    </div>
                                    <button type="submit" 
                                            class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                        <i data-lucide="upload" class="w-4 h-4 inline mr-2"></i>
                                        Upload Picture
                                    </button>
                                </form>
                                
                                <p class="text-xs text-gray-500 mt-2">
                                    Max size: 2MB. Formats: JPG, PNG, GIF
                                </p>
                            </div>
                        </div>
                        
                        <!-- Account Actions -->
                        <div class="bg-white rounded-lg shadow p-6 mt-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Account Actions</h2>
                            <div class="space-y-3">
                                <a href="change_password.php" 
                                   class="flex items-center w-full px-4 py-2 text-left bg-gray-50 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                                    <i data-lucide="key" class="w-4 h-4 mr-3"></i>
                                    Change Password
                                </a>
                                <a href="../logout.php" 
                                   class="flex items-center w-full px-4 py-2 text-left bg-red-50 text-red-700 rounded-lg hover:bg-red-100 transition-colors">
                                    <i data-lucide="log-out" class="w-4 h-4 mr-3"></i>
                                    Sign Out
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!--