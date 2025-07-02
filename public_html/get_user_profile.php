<?php
// get_user_profile.php - Returns user profile HTML for modal display
require_once __DIR__ . '/../config/config.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

// Permission check - only admins can view profiles
$admin_roles = [1, 2, 3];
if (!in_array($_SESSION['user_role_id'], $admin_roles)) {
    http_response_code(403);
    exit('Access denied');
}

// Get user ID
$viewUserId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($viewUserId <= 0) {
    http_response_code(400);
    exit('Invalid user ID');
}

// Fetch user data
$stmt = $conn->prepare("SELECT u.*, r.name as roleName FROM users u JOIN roles r ON u.roleId = r.id WHERE u.id = ?");
$stmt->bind_param("i", $viewUserId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    exit('User not found');
}

// Use the isUserArchived() function from config.php

// Generate profile picture URL
$profilePictureUrl = '';
if (!empty($user['profilePicture'])) {
    $profilePictureUrl = "serve_image.php?file=" . urlencode($user['profilePicture']);
}
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Profile Picture and Basic Info -->
    <div class="lg:col-span-1">
        <div class="bg-gray-50 rounded-lg p-6 text-center">
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
                    <p class="text-gray-900"><?php echo htmlspecialchars($user['mobile_phone'] ?: 'Not provided'); ?></p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Alternative Phone</label>
                    <p class="text-gray-900"><?php echo htmlspecialchars($user['alt_phone'] ?: 'Not provided'); ?></p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">User Type</label>
                    <p class="text-gray-900"><?php echo htmlspecialchars(ucfirst($user['type']) ?: 'Not specified'); ?></p>
                </div>
                
                <?php if (isUserArchived($user)): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Termination Date</label>
                    <p class="text-red-600"><?php echo date('M j, Y', strtotime($user['terminationDate'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($user['emergency_contact_name']) || !empty($user['emergency_contact_phone'])): ?>
            <div class="mt-6 pt-6 border-t border-gray-200">
                <h4 class="text-md font-semibold text-gray-900 mb-3">Emergency Contact</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contact Name</label>
                        <p class="text-gray-900"><?php echo htmlspecialchars($user['emergency_contact_name'] ?: 'Not provided'); ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contact Phone</label>
                        <p class="text-gray-900"><?php echo htmlspecialchars($user['emergency_contact_phone'] ?: 'Not provided'); ?></p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="mt-6 pt-6 border-t border-gray-200">
                <h4 class="text-md font-semibold text-gray-900 mb-3">Emergency Contact</h4>
                <p class="text-gray-500 italic">No emergency contact information provided</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>