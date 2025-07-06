<?php
// config/usermgmt_config.php - User Management Module Configuration

// --- User Role Definitions ---
define('USER_ROLES', [
    1 => 'Super Administrator',
    2 => 'Administrator',
    3 => 'Manager',
    4 => 'Supervisor',
    5 => 'Employee',
    6 => 'Subcontractor'
]);

// --- Admin Role IDs (for permission checks) ---
define('ADMIN_ROLES', [1, 2, 3]); // Super Admin, Admin, Manager

// --- User Management Settings ---
define('DEFAULT_PASSWORD', 'password123');
define('PASSWORD_MIN_LENGTH', 8);
define('REQUIRE_PASSWORD_COMPLEXITY', true);

// --- Profile Picture Settings ---
define('PROFILE_PICTURE_MAX_SIZE', 2097152); // 2MB in bytes
define('PROFILE_PICTURE_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif']);
define('PROFILE_PICTURE_UPLOAD_DIR', __DIR__ . '/../uploads/profile_pictures/');

// --- Bulk Upload Settings ---
define('BULK_UPLOAD_ALLOWED_TYPES', ['csv', 'xls', 'xlsx']);
define('BULK_UPLOAD_MAX_SIZE', 5242880); // 5MB
define('BULK_UPLOAD_REQUIRED_HEADERS', [
    'FirstName', 'LastName', 'Email', 'RoleID', 'Type', 'Title'
]);

// --- User Field Validation ---
define('USER_FIELD_LENGTHS', [
    'firstName' => 50,
    'lastName' => 50,
    'email' => 100,
    'employeeId' => 20,
    'title' => 100,
    'mobile_phone_new' => 12, // Updated to support ###-###-#### format
    'alt_phone' => 12,    // Updated to support ###-###-#### format
    'emergency_contact_name' => 100,
    'emergency_contact_phone' => 12 // Updated to support ###-###-#### format
]);

// --- Phone Number Format ---
define('PHONE_NUMBER_PATTERN', '/^[0-9]{3}-[0-9]{3}-[0-9]{4}$/'); // ###-###-#### format
define('PHONE_NUMBER_EXAMPLE', '804-555-1234');

// --- User Management Helper Functions ---

/**
 * Get role name by role ID
 * @param int $roleId The role ID to look up
 * @param array $roles Array of roles from database
 * @return string Role name or 'N/A' if not found
 */
function getRoleName($roleId, $roles) {
    foreach ($roles as $role) {
        if ($role['id'] == $roleId) {
            return $role['name'];
        }
    }
    return 'N/A';
}

/**
 * Check if a user is archived based on termination date
 * @param array $user User data array
 * @return bool True if user is archived, false otherwise
 */
function isUserArchived($user) {
    if (empty($user['terminationDate'])) {
        return false;
    }
    try {
        $termDate = new DateTime($user['terminationDate']);
        $today = new DateTime('today');
        return $termDate < $today;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Deletes a user's profile picture from the filesystem
 * @param string $profilePictureFilename The filename of the profile picture
 * @return bool True if file was deleted or didn't exist, false on error
 */
function deleteProfilePicture($profilePictureFilename) {
    if (empty($profilePictureFilename)) {
        return true; // No file to delete
    }
    
    // Build full path to the file in uploads/profile_pictures/
    $full_path = PROFILE_PICTURE_UPLOAD_DIR . basename($profilePictureFilename);
    
    // Delete file if it exists
    if (file_exists($full_path)) {
        return unlink($full_path);
    }
    
    return true; // File doesn't exist
}

/**
 * Cleanup profile pictures for deleted users
 * @param mysqli $conn Database connection
 * @param array $userIds Array of user IDs being deleted
 */
function cleanupUserProfilePictures($conn, $userIds) {
    if (empty($userIds)) {
        return;
    }
    
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));
    
    $stmt = $conn->prepare("SELECT profilePicture FROM users WHERE id IN ($placeholders) AND profilePicture IS NOT NULL");
    $stmt->bind_param($types, ...$userIds);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        deleteProfilePicture($row['profilePicture']);
    }
    
    $stmt->close();
}

/**
 * Get the full path to a profile picture for serving
 * @param string $filename The profile picture filename
 * @return string|null Full path if file exists, null otherwise
 */
function getProfilePicturePath($filename) {
    if (empty($filename)) {
        return null;
    }
    
    $full_path = PROFILE_PICTURE_UPLOAD_DIR . basename($filename);
    
    return file_exists($full_path) ? $full_path : null;
}

/**
 * Validate phone number format (###-###-####)
 * @param string $phone Phone number to validate
 * @return bool True if valid format, false otherwise
 */
function validatePhoneNumber($phone) {
    if (empty($phone)) {
        return true; // Optional field
    }
    return preg_match(PHONE_NUMBER_PATTERN, $phone);
}

/**
 * Format phone number to ###-###-#### format
 * @param string $phone Raw phone number (digits only or with existing formatting)
 * @return string Formatted phone number or empty string if invalid
 */
function formatPhoneNumber($phone) {
    if (empty($phone)) {
        return '';
    }
    
    // Remove all non-digit characters
    $digits = preg_replace('/[^0-9]/', '', $phone);
    
    // Must be exactly 10 digits
    if (strlen($digits) !== 10) {
        return '';
    }
    
    // Format as ###-###-####
    return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
}

/**
 * Clean phone number input (remove formatting, keep only digits)
 * @param string $phone Phone number with any formatting
 * @return string Digits only, limited to 10 characters
 */
function cleanPhoneNumber($phone) {
    if (empty($phone)) {
        return '';
    }
    
    // Remove all non-digit characters and limit to 10 digits
    $digits = preg_replace('/[^0-9]/', '', $phone);
    return substr($digits, 0, 10);
}

/**
 * Check if user has administrative privileges
 * @param int $roleId User's role ID
 * @return bool True if user is admin, false otherwise
 */
function isAdminUser($roleId) {
    return in_array($roleId, ADMIN_ROLES);
}
?>