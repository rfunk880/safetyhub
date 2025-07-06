<?php
// /public_html/usermgmt/profile.php
// User profile management with secure profile picture upload
// Updated to use same layout and functions as view_profile.php

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
                   if (file_exists($full_path)) {
                       unlink($full_path);
                   }
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

// Fetch user data
$stmt = $conn->prepare("SELECT u.*, r.name as roleName FROM users u LEFT JOIN roles r ON u.roleId = r.id WHERE u.id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
   header("Location: ../login.php");
   exit;
}

// Generate profile picture URL
$profilePictureUrl = '';
if (!empty($user['profilePicture'])) {
   $profilePictureUrl = "serve_image.php?file=" . urlencode($user['profilePicture']);
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
       <!-- Sidebar -->
       <?php 
       if (function_exists('renderNavigation')) {
           renderNavigation();
       } else {
           // Fallback navigation if renderNavigation() doesn't exist
           include __DIR__ . '/../../includes/navigation.php';
       }
       ?>

       <!-- Main Content -->
       <main class="flex-1 flex flex-col min-w-0">
           <!-- Header -->
           <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4 flex items-center justify-between">
               <div class="flex items-center">
                   <button onclick="toggleSidebar()" class="md:hidden text-gray-600 hover:text-gray-900 mr-4">
                       <i data-lucide="menu" class="w-6 h-6"></i>
                   </button>
                   <h2 class="text-2xl font-semibold text-gray-800">My Profile</h2>
               </div>
               <a href="../dashboard/index.php" class="flex items-center text-blue-600 hover:text-blue-800 transition-colors">
                   <i data-lucide="arrow-left" class="w-5 h-5 mr-2"></i>
                   Back to Dashboard
               </a>
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
                   <!-- Profile Content -->
                   <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 p-6">
                       
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
                               
                               <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo isUserArchived($user) ? 'bg-gray-200 text-gray-800' : 'bg-green-100 text-green-800'; ?>">
                                   <i data-lucide="<?php echo isUserArchived($user) ? 'user-x' : 'user-check'; ?>" class="w-4 h-4 mr-1"></i>
                                   <?php echo isUserArchived($user) ? 'Archived' : 'Active'; ?>
                               </span>
                           </div>
                       </div>
                       
                       <!-- Detailed Information -->
                       <div class="lg:col-span-2">
                           <div class="bg-gray-50 rounded-lg p-6">
                               <h3 class="text-lg font-semibold text-gray-900 mb-4">Contact Information</h3>
                               
                               <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                   <div>
                                       <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                                       <p class="text-gray-900"><?php echo htmlspecialchars($user['email']); ?></p>
                                   </div>
                                   
                                   <div>
                                       <label class="block text-sm font-medium text-gray-700 mb-1">Employee ID</label>
                                       <p class="text-gray-900"><?php echo htmlspecialchars($user['employeeId'] ?: 'Not assigned'); ?></p>
                                   </div>
                                   
                                   <div>
                                       <label class="block text-sm font-medium text-gray-700 mb-1">Mobile Phone</label>
                                       <p class="text-gray-900"><?php 
                                           $mobile_phone = $user['mobile_phone_new'] ?? '';
                                           if (!empty($mobile_phone)) {
                                               $digits = preg_replace('/[^0-9]/', '', $mobile_phone);
                                               if (strlen($digits) === 10) {
                                                   echo htmlspecialchars(substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4));
                                               } else {
                                                   echo htmlspecialchars($mobile_phone);
                                               }
                                           } else {
                                               echo 'Not provided';
                                           }
                                       ?></p>
                                   </div>
                                   
                                   <div>
                                       <label class="block text-sm font-medium text-gray-700 mb-1">Alternate Phone</label>
                                       <p class="text-gray-900"><?php 
                                           $alt_phone = $user['alt_phone'] ?? '';
                                           if (!empty($alt_phone)) {
                                               $digits = preg_replace('/[^0-9]/', '', $alt_phone);
                                               if (strlen($digits) === 10) {
                                                   echo htmlspecialchars(substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4));
                                               } else {
                                                   echo htmlspecialchars($alt_phone);
                                               }
                                           } else {
                                               echo 'Not provided';
                                           }
                                       ?></p>
                                   </div>
                               </div>
                           </div>
                           
                           <!-- Emergency Contact Information -->
                           <div class="bg-gray-50 rounded-lg p-6 mt-6">
                               <h3 class="text-lg font-semibold text-gray-900 mb-4">Emergency Contact</h3>
                               
                               <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                   <div>
                                       <label class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Name</label>
                                       <p class="text-gray-900"><?php echo htmlspecialchars($user['emergency_contact_name'] ?: 'Not provided'); ?></p>
                                   </div>
                                   
                                   <div>
                                       <label class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Phone</label>
                                       <p class="text-gray-900"><?php 
                                           $emergency_phone = $user['emergency_contact_phone'] ?? '';
                                           if (!empty($emergency_phone)) {
                                               $digits = preg_replace('/[^0-9]/', '', $emergency_phone);
                                               if (strlen($digits) === 10) {
                                                   echo htmlspecialchars(substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4));
                                               } else {
                                                   echo htmlspecialchars($emergency_phone);
                                               }
                                           } else {
                                               echo 'Not provided';
                                           }
                                       ?></p>
                                   </div>
                               </div>
                           </div>
                       </div>
                   </div>
               </div>
           </div>
       </main>
   </div>

   <!-- Profile Picture Upload Modal -->
   <div id="uploadModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
       <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
           <div class="flex items-center justify-between mb-4">
               <h3 class="text-lg font-semibold text-gray-900">Upload Profile Picture</h3>
               <button onclick="hideUploadModal()" class="text-gray-400 hover:text-gray-600">
                   <i data-lucide="x" class="w-5 h-5"></i>
               </button>
           </div>
           
           <form method="POST" enctype="multipart/form-data" class="space-y-4">
               <div>
                   <label for="profile_picture" class="block text-sm font-medium text-gray-700 mb-2">
                       Choose Image (JPG, PNG, GIF - Max 2MB)
                   </label>
                   <input type="file" 
                          id="profile_picture" 
                          name="profile_picture" 
                          accept="image/jpeg,image/png,image/gif" 
                          required
                          class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
               </div>
               
               <div class="flex gap-3 pt-4">
                   <button type="submit" 
                           class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-lg transition-colors">
                       Upload
                   </button>
                   <button type="button" 
                           onclick="hideUploadModal()" 
                           class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-700 py-2 px-4 rounded-lg transition-colors">
                       Cancel
                   </button>
               </div>
           </form>
       </div>
   </div>

   <script>
       // Initialize Lucide icons
       lucide.createIcons();

       // Modal functions
       function showUploadModal() {
           document.getElementById('uploadModal').classList.remove('hidden');
       }

       function hideUploadModal() {
           document.getElementById('uploadModal').classList.add('hidden');
       }

       // Sidebar toggle for mobile
       function toggleSidebar() {
           const sidebar = document.getElementById('sidebar');
           if (sidebar) {
               sidebar.classList.toggle('hidden');
           }
       }

       // Close modal when clicking outside
       document.getElementById('uploadModal').addEventListener('click', function(e) {
           if (e.target === this) {
               hideUploadModal();
           }
       });

       // Close sidebar when clicking outside on mobile
       document.addEventListener('click', function(e) {
           const sidebar = document.getElementById('sidebar');
           const menuButton = e.target.closest('[onclick="toggleSidebar()"]');
           
           if (sidebar && !sidebar.contains(e.target) && !menuButton && window.innerWidth < 768) {
               sidebar.classList.add('hidden');
           }
       });
   </script>
   <script>
// Phone number formatting function
function formatPhoneNumber(value) {
   const digits = value.replace(/\D/g, '');
   const limitedDigits = digits.substring(0, 10);
   
   if (limitedDigits.length >= 6) {
       return limitedDigits.substring(0, 3) + '-' + limitedDigits.substring(3, 6) + '-' + limitedDigits.substring(6);
   } else if (limitedDigits.length >= 3) {
       return limitedDigits.substring(0, 3) + '-' + limitedDigits.substring(3);
   } else {
       return limitedDigits;
   }
}

function handlePhoneInput(event) {
   const input = event.target;
   const formattedValue = formatPhoneNumber(input.value);
   input.value = formattedValue;
}

function handlePhoneKeypress(event) {
   if ([8, 9, 27, 13, 46].indexOf(event.keyCode) !== -1 ||
       (event.keyCode === 65 && event.ctrlKey === true) ||
       (event.keyCode === 67 && event.ctrlKey === true) ||
       (event.keyCode === 86 && event.ctrlKey === true) ||
       (event.keyCode === 88 && event.ctrlKey === true)) {
       return;
   }
   if ((event.shiftKey || (event.keyCode < 48 || event.keyCode > 57)) && (event.keyCode < 96 || event.keyCode > 105)) {
       event.preventDefault();
   }
   
   const digits = event.target.value.replace(/\D/g, '');
   if (digits.length >= 10) {
       event.preventDefault();
   }
}

// Apply phone formatting
function setupPhoneFormatting() {
   const phoneInputs = document.querySelectorAll('input[type="tel"]');
   
   phoneInputs.forEach(input => {
       if (input.value) {
           input.value = formatPhoneNumber(input.value);
       }
       
       input.removeEventListener('input', handlePhoneInput);
       input.removeEventListener('keypress', handlePhoneKeypress);
       
       input.addEventListener('input', handlePhoneInput);
       input.addEventListener('keypress', handlePhoneKeypress);
   });
}

// Handle form submission
document.addEventListener('DOMContentLoaded', function() {
   setupPhoneFormatting();
   
   const form = document.querySelector('form');
   if (form) {
       form.addEventListener('submit', function(e) {
           const phoneInputs = this.querySelectorAll('input[type="tel"]');
           phoneInputs.forEach(input => {
               input.value = input.value.replace(/\D/g, '');
           });
       });
   }
});
</script>
</body>
</html>