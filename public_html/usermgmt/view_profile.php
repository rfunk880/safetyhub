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

// Handle POST actions without redirecting
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   $post_action = $_POST['action'] ?? '';
   $actionUserId = (int)($_POST['userId'] ?? 0);
   
   if ($actionUserId === $viewUserId) {
       if ($post_action === 'send_setup_email') {
           $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
           $stmt->bind_param("i", $actionUserId);
           $stmt->execute();
           $user_email = $stmt->get_result()->fetch_assoc();
           $stmt->close();
           
           if ($user_email) {
               $token = bin2hex(random_bytes(50));
               $stmt_token = $conn->prepare("INSERT INTO password_resets (email, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = ?, created_at = NOW()");
               $stmt_token->bind_param("sss", $user_email['email'], $token, $token);
               $stmt_token->execute();
               $stmt_token->close();
               
               if (function_exists('sendSetupEmail') && sendSetupEmail($user_email['email'], $token)) {
                   $_SESSION['success_message'] = "Setup email sent successfully to {$user_email['email']}.";
               } else {
                   $_SESSION['error_message'] = "Error sending email to {$user_email['email']}.";
               }
           }
       }
       
       if ($post_action === 'archive_user') {
           // Check current termination status
           $stmt = $conn->prepare("SELECT terminationDate FROM users WHERE id = ?");
           $stmt->bind_param("i", $actionUserId);
           $stmt->execute();
           $current_user = $stmt->get_result()->fetch_assoc();
           $stmt->close();
           
           if ($current_user) {
               $isCurrentlyArchived = !empty($current_user['terminationDate']) && $current_user['terminationDate'] < date('Y-m-d');
               
               if ($isCurrentlyArchived) {
                   // Unarchive - set terminationDate to NULL
                   $stmt = $conn->prepare("UPDATE users SET terminationDate = NULL WHERE id = ?");
                   $stmt->bind_param("i", $actionUserId);
                   $stmt->execute();
                   $_SESSION['success_message'] = "User unarchived successfully.";
                   $stmt->close();
               } else {
                   // Archive - set terminationDate to yesterday
                   $yesterday = date('Y-m-d', strtotime('-1 day'));
                   $stmt = $conn->prepare("UPDATE users SET terminationDate = ? WHERE id = ?");
                   $stmt->bind_param("si", $yesterday, $actionUserId);
                   $stmt->execute();
                   $_SESSION['success_message'] = "User archived successfully.";
                   $stmt->close();
               }
           }
       }
       
       if ($post_action === 'update_profile_picture' && isset($_FILES['profile_picture'])) {
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
                   $_SESSION['error_message'] = 'Invalid file type. Please upload a JPG, PNG, or GIF.';
               } elseif ($file_size > PROFILE_PICTURE_MAX_SIZE) {
                   $_SESSION['error_message'] = 'File too large. Maximum size is 2MB.';
               } else {
                   // Get current profile picture for cleanup
                   $stmt_current = $conn->prepare("SELECT profilePicture FROM users WHERE id = ?");
                   $stmt_current->bind_param("i", $actionUserId);
                   $stmt_current->execute();
                   $current_result = $stmt_current->get_result();
                   $current_user = $current_result->fetch_assoc();
                   $old_profile_picture = $current_user['profilePicture'] ?? null;
                   $stmt_current->close();

                   // Generate unique filename
                   $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                   $new_filename = 'user_' . $actionUserId . '_' . time() . '.' . $file_extension;
                   $full_path = $upload_dir . $new_filename;

                   // Upload and update database
                   if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $full_path)) {
                       $stmt = $conn->prepare("UPDATE users SET profilePicture = ? WHERE id = ?");
                       $stmt->bind_param("si", $new_filename, $actionUserId);
                       
                       if ($stmt->execute()) {
                           // Delete old file if exists
                           if ($old_profile_picture && $old_profile_picture !== $new_filename) {
                               deleteProfilePicture($old_profile_picture);
                           }
                           
                           $_SESSION['success_message'] = 'Profile picture updated successfully!';
                       } else {
                           $_SESSION['error_message'] = 'Failed to update profile picture in database.';
                           // Clean up uploaded file
                           unlink($full_path);
                       }
                       $stmt->close();
                   } else {
                       $_SESSION['error_message'] = 'Failed to upload file.';
                   }
               }
           } else {
               $_SESSION['error_message'] = 'Upload error: ' . $_FILES['profile_picture']['error'];
           }
       }
       
       if ($post_action === 'edit_user') {
           $firstName = trim($_POST['firstName'] ?? '');
           $lastName = trim($_POST['lastName'] ?? '');
           $email = trim($_POST['email'] ?? '');
           $employeeId = trim($_POST['employeeId'] ?? '');
           $roleId = (int)($_POST['roleId'] ?? 0);
           $type = trim($_POST['type'] ?? '');
           $title = trim($_POST['title'] ?? '');
           
           // Clean phone numbers - store only digits
           $mobile_phone = '';
           $alt_phone = '';
           $emergency_contact_phone = '';
           
           if (!empty($_POST['mobile_phone'])) {
               $mobile_cleaned = cleanPhoneNumber($_POST['mobile_phone']);
               if (strlen($mobile_cleaned) === 10) {
                   $mobile_phone = $mobile_cleaned; // Store just the digits, not formatted
               }
           }
           
           if (!empty($_POST['alt_phone'])) {
               $alt_cleaned = cleanPhoneNumber($_POST['alt_phone']);
               if (strlen($alt_cleaned) === 10) {
                   $alt_phone = $alt_cleaned; // Store just the digits, not formatted
               }
           }
           
           if (!empty($_POST['emergency_contact_phone'])) {
               $emergency_cleaned = cleanPhoneNumber($_POST['emergency_contact_phone']);
               if (strlen($emergency_cleaned) === 10) {
                   $emergency_contact_phone = $emergency_cleaned; // Store just the digits, not formatted
               }
           }
           
           $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
           
           // Validate phone numbers
           $validation_errors = [];
           if (!empty($mobile_phone) && strlen($mobile_phone) !== 10) {
               $validation_errors[] = 'Mobile phone must be 10 digits.';
           }
           if (!empty($alt_phone) && strlen($alt_phone) !== 10) {
               $validation_errors[] = 'Alternative phone must be 10 digits.';
           }
           if (!empty($emergency_contact_phone) && strlen($emergency_contact_phone) !== 10) {
               $validation_errors[] = 'Emergency contact phone must be 10 digits.';
           }
           
           if (!empty($firstName) && !empty($lastName) && !empty($email) && $roleId > 0 && empty($validation_errors)) {
               $stmt = $conn->prepare("UPDATE users SET firstName = ?, lastName = ?, email = ?, employeeId = ?, roleId = ?, type = ?, title = ?, mobile_phone_new = ?, alt_phone = ?, emergency_contact_name = ?, emergency_contact_phone = ? WHERE id = ?");
               $stmt->bind_param("ssssississsi", $firstName, $lastName, $email, $employeeId, $roleId, $type, $title, $mobile_phone, $alt_phone, $emergency_contact_name, $emergency_contact_phone, $actionUserId);
               
               if ($stmt->execute()) {
                   $_SESSION['success_message'] = "User updated successfully.";
               } else {
                   $_SESSION['error_message'] = "Error updating user.";
               }
               $stmt->close();
           } else {
               if (!empty($validation_errors)) {
                   $_SESSION['error_message'] = implode(' ', $validation_errors);
               } else {
                   $_SESSION['error_message'] = "Error: Invalid data provided.";
               }
           }
       }
   }
   
   // Redirect to clear POST data
   header("Location: view_profile.php?id=" . $viewUserId);
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

// Fetch all roles for the edit form
$roles_stmt = $conn->prepare("SELECT id, name FROM roles ORDER BY id");
$roles_stmt->execute();
$roles_result = $roles_stmt->get_result();
$roles = $roles_result->fetch_all(MYSQLI_ASSOC);
$roles_stmt->close();

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
               
               <!-- Success/Error Messages -->
               <?php if (isset($_SESSION['success_message'])): ?>
                   <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                       <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                   </div>
               <?php endif; ?>
               <?php if (isset($_SESSION['error_message'])): ?>
                   <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                       <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                   </div>
               <?php endif; ?>
               
               <!-- Header -->
<div class="mb-6">
                    <div>
                           <div class="flex items-center space-x-2 text-sm text-gray-600 mb-2">
                               <a href="index.php" class="hover:text-blue-600">User Management</a>
                               <i data-lucide="chevron-right" class="w-4 h-4"></i>
                               <span>View Profile</span>
                           </div>
<h1 class="text-3xl font-bold text-gray-900">
                                <?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?>
                            </h1>
                       </div>
                       

               <!-- Profile Content -->
               <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                   
                   <!-- Profile Picture and Basic Info -->
                   <div class="lg:col-span-1">
                       <div class="bg-white rounded-lg shadow p-6 text-center">
                           <div class="relative inline-block">
                               <?php if ($profilePictureUrl): ?>
                                   <img src="<?php echo htmlspecialchars($profilePictureUrl); ?>" 
                                        alt="Profile Picture" 
                                        class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-white shadow-lg">
                               <?php else: ?>
                                   <div class="w-32 h-32 rounded-full mx-auto mb-4 bg-gray-300 flex items-center justify-center">
                                       <i data-lucide="user" class="w-16 h-16 text-gray-500"></i>
                                   </div>
                               <?php endif; ?>
                               
                               <!-- Upload Profile Picture Button -->
                               <button onclick="showUploadModal()" 
                                       class="absolute bottom-0 right-0 bg-blue-600 hover:bg-blue-700 text-white rounded-full p-2 shadow-lg transition-colors">
                                   <i data-lucide="camera" class="w-4 h-4"></i>
                               </button>
                           </div>
                           
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
                   </div>
                   
                   <!-- Detailed Information -->
                   <div class="lg:col-span-2">
                       <div class="bg-white rounded-lg shadow p-6">
                           <h3 class="text-lg font-semibold text-gray-900 mb-6">Contact Information</h3>
                           
                           <div class="space-y-6">
                               <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                   <div>
                                       <label class="block text-sm font-medium text-gray-600 mb-1">Email Address</label>
                                       <p class="text-gray-900">
                                           <?php echo htmlspecialchars($user['email']); ?>
                                       </p>
                                   </div>

                                   <div>
                                       <label class="block text-sm font-medium text-gray-600 mb-1">Employee ID</label>
                                       <p class="text-gray-900">
                                           <?php echo htmlspecialchars($user['employeeId'] ?: 'Not assigned'); ?>
                                       </p>
                                   </div>

<div>
   <label class="block text-sm font-medium text-gray-600 mb-1">Mobile Phone</label>
   <p class="text-gray-800"><?php 
       // Use mobile_phone_new field and format it for display
       $mobile_phone = $user['mobile_phone_new'] ?? '';
       if (!empty($mobile_phone)) {
           // Format phone number for display (###-###-####)
           $digits = preg_replace('/[^0-9]/', '', $mobile_phone);
           if (strlen($digits) === 10) {
               $formatted_phone = substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
               echo htmlspecialchars($formatted_phone);
           } else {
               echo htmlspecialchars($mobile_phone);
           }
       } else {
           echo 'Not provided';
       }
   ?></p>
</div>

<div>
   <label class="block text-sm font-medium text-gray-600 mb-1">Alternate Phone</label>
   <p class="text-gray-800"><?php 
       // Format alt phone for display
       $alt_phone = $user['alt_phone'] ?? '';
       if (!empty($alt_phone)) {
           // Format phone number for display (###-###-####)
           $digits = preg_replace('/[^0-9]/', '', $alt_phone);
           if (strlen($digits) === 10) {
               $formatted_alt_phone = substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
               echo htmlspecialchars($formatted_alt_phone);
           } else {
               echo htmlspecialchars($alt_phone);
           }
       } else {
           echo 'Not provided';
       }
   ?></p>
</div>

                                   <div>
                                       <label class="block text-sm font-medium text-gray-600 mb-1">Emergency Contact Name</label>
                                       <p class="text-gray-900">
                                           <?php echo htmlspecialchars($user['emergency_contact_name'] ?: 'Not provided'); ?>
                                       </p>
                                   </div>

<div>
   <label class="block text-sm font-medium text-gray-600 mb-1">Emergency Contact Phone</label>
   <p class="text-gray-800"><?php 
       // Format emergency contact phone for display
       $emergency_phone = $user['emergency_contact_phone'] ?? '';
       if (!empty($emergency_phone)) {
           // Format phone number for display (###-###-####)
           $digits = preg_replace('/[^0-9]/', '', $emergency_phone);
           if (strlen($digits) === 10) {
               $formatted_emergency_phone = substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
               echo htmlspecialchars($formatted_emergency_phone);
           } else {
               echo htmlspecialchars($emergency_phone);
           }
       } else {
           echo 'Not provided';
       }
   ?></p>
</div>

                                   <div>
                                       <label class="block text-sm font-medium text-gray-600 mb-1">Role</label>
                                       <p class="text-gray-900">
                                           <?php echo htmlspecialchars($user['roleName'] ?: 'Not assigned'); ?>
                                       </p>
                                   </div>

                                   <div>
                                       <label class="block text-sm font-medium text-gray-600 mb-1">Employee Type</label>
                                       <p class="text-gray-900">
                                           <?php echo htmlspecialchars($user['type'] ?: 'Not specified'); ?>
                                       </p>
                                   </div>

                                   <?php if (!empty($user['terminationDate'])): ?>
                                       <div>
                                           <label class="block text-sm font-medium text-gray-600 mb-1">Termination Date</label>
                                           <p class="text-gray-900">
                                               <?php echo htmlspecialchars(date('M j, Y', strtotime($user['terminationDate']))); ?>
                                           </p>
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

   <!-- Edit User Modal -->
   <div id="editUserModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 hidden z-50">
       <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
           <div class="p-6">
               <div class="flex justify-between items-center mb-6">
                   <h2 class="text-xl font-bold text-gray-900">Edit User</h2>
                   <button onclick="hideEditModal()" class="text-gray-400 hover:text-gray-600">
                       <i data-lucide="x" class="w-6 h-6"></i>
                   </button>
               </div>
               
               <form method="POST" class="space-y-4">
                   <input type="hidden" name="action" value="edit_user">
                   <input type="hidden" name="userId" value="<?php echo $user['id']; ?>">
                   
                   <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                       <div>
                           <label class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                           <input type="text" name="firstName" value="<?php echo htmlspecialchars($user['firstName']); ?>" 
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                       </div>
                       
                       <div>
                           <label class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                           <input type="text" name="lastName" value="<?php echo htmlspecialchars($user['lastName']); ?>" 
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                       </div>
                       
                       <div>
                           <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                           <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" 
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                       </div>
                       
                       <div>
                           <label class="block text-sm font-medium text-gray-700 mb-1">Employee ID</label>
                           <input type="text" name="employeeId" value="<?php echo htmlspecialchars($user['employeeId']); ?>" 
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                       </div>
                       
                       <div>
                           <label class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                           <select name="roleId" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                               <?php foreach ($roles as $role): ?>
                                   <option value="<?php echo $role['id']; ?>" <?php echo $role['id'] == $user['roleId'] ? 'selected' : ''; ?>>
                                       <?php echo htmlspecialchars($role['name']); ?>
                                   </option>
                               <?php endforeach; ?>
                           </select>
                       </div>
                       
                       <div>
                           <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                           <select name="type" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                               <option value="Employee" <?php echo $user['type'] == 'Employee' ? 'selected' : ''; ?>>Employee</option>
                               <option value="Subcontractor" <?php echo $user['type'] == 'Subcontractor' ? 'selected' : ''; ?>>Subcontractor</option>
                           </select>
                       </div>
                       
                       <div class="md:col-span-2">
                           <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                           <input type="text" name="title" value="<?php echo htmlspecialchars($user['title']); ?>" 
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                       </div>
                       
                       <div>
                           <label class="block text-sm font-medium text-gray-700 mb-1">Mobile Phone</label>
<input type="tel" name="mobile_phone" value="<?php echo htmlspecialchars($user['mobile_phone_new']); ?>" 
                                  placeholder="<?php echo PHONE_NUMBER_EXAMPLE; ?>" maxlength="12"
                                  class="phone-input w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                       </div>
                       
                       <div>
                           <label class="block text-sm font-medium text-gray-700 mb-1">Alternative Phone</label>
                           <input type="tel" name="alt_phone" value="<?php echo htmlspecialchars($user['alt_phone']); ?>" 
                                  placeholder="<?php echo PHONE_NUMBER_EXAMPLE; ?>" maxlength="12"
                                  class="phone-input w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                       </div>
                       
                       <div>
                           <label class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Name</label>
                           <input type="text" name="emergency_contact_name" value="<?php echo htmlspecialchars($user['emergency_contact_name']); ?>" 
                                  class="w-full border border-gray-300 rounded-