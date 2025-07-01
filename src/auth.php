<?php
// src/auth.php
// This file contains all user authentication and management functions.
// Updated to work with modular configuration system

/**
 * Checks if a user is currently logged in by verifying the session.
 * @return bool True if the user is logged in, false otherwise.
 */
function isUserLoggedIn() {
    // A user is considered logged in if their user_id is set in the session.
    return isset($_SESSION['user_id']);
}

/**
 * Fetches a user's complete data from the database by their ID.
 * @param mysqli $conn The mysqli database connection object.
 * @param int $id The ID of the user to fetch.
 * @return array|false The user's data as an associative array, or false if not found.
 */
function getUserById($conn, $id) {
    $stmt = $conn->prepare("SELECT u.*, r.name as roleName FROM users u LEFT JOIN roles r ON u.roleId = r.id WHERE u.id = ?");
    if ($stmt === false) {
        // Log error if prepare fails
        error_log('Prepare failed in getUserById: ' . $conn->error);
        return false;
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    return $user;
}

/**
 * Updates the profile picture path for a given user in the database.
 * @param mysqli $conn The mysqli database connection object.
 * @param int $userId The ID of the user to update.
 * @param string $fileName The new filename of the profile picture.
 * @return bool True on success, false on failure.
 */
function updateUserProfilePicture($conn, $userId, $fileName) {
    $stmt = $conn->prepare("UPDATE users SET profilePicture = ? WHERE id = ?");
     if ($stmt === false) {
        error_log('Prepare failed in updateUserProfilePicture: ' . $conn->error);
        return false;
    }
    $stmt->bind_param("si", $fileName, $userId);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

/**
 * Verifies user credentials and logs them in.
 * @param mysqli $conn The mysqli database connection object.
 * @param string $email The user's email.
 * @param string $password The user's password.
 * @return bool True on successful login, false otherwise.
 */
function handleLogin($conn, $email, $password) {
    $stmt = $conn->prepare("SELECT id, password, firstName, lastName, roleId FROM users WHERE email = ? AND (terminationDate IS NULL OR terminationDate >= CURDATE())");
    if ($stmt === false) {
        error_log('Prepare failed in handleLogin: ' . $conn->error);
        return false;
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Verify the password
        if (password_verify($password, $user['password'])) {
            // Success! Regenerate session ID for security.
            session_regenerate_id(true);
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_first_name'] = $user['firstName'];
            $_SESSION['user_last_name'] = $user['lastName']; 
            $_SESSION['user_role_id'] = $user['roleId'];
            
            $stmt->close();
            return true;
        }
    }
    
    $stmt->close();
    return false;
}

/**
 * Check if current user has administrative privileges
 * @return bool True if user is admin, false otherwise
 */
function isCurrentUserAdmin() {
    if (!isset($_SESSION['user_role_id'])) {
        return false;
    }
    return isAdminUser($_SESSION['user_role_id']);
}

/**
 * Check if current user can access user management
 * @return bool True if user can access, false otherwise
 */
function canAccessUserManagement() {
    return isCurrentUserAdmin();
}

/**
 * Validate user input for profile updates
 * @param array $data User input data
 * @return array Array of validation errors (empty if valid)
 */
function validateUserData($data) {
    $errors = [];
    
    // Validate required fields
    if (empty(trim($data['firstName'] ?? ''))) {
        $errors[] = 'First name is required';
    } elseif (strlen($data['firstName']) > USER_FIELD_LENGTHS['firstName']) {
        $errors[] = 'First name is too long';
    }
    
    if (empty(trim($data['lastName'] ?? ''))) {
        $errors[] = 'Last name is required';
    } elseif (strlen($data['lastName']) > USER_FIELD_LENGTHS['lastName']) {
        $errors[] = 'Last name is too long';
    }
    
    if (empty(trim($data['email'] ?? ''))) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    } elseif (strlen($data['email']) > USER_FIELD_LENGTHS['email']) {
        $errors[] = 'Email is too long';
    }
    
    // Validate phone numbers if provided
    if (!empty($data['mobile_phone']) && !validatePhoneNumber($data['mobile_phone'])) {
        $errors[] = 'Mobile phone must be in format ' . PHONE_NUMBER_EXAMPLE;
    }
    
    if (!empty($data['alt_phone']) && !validatePhoneNumber($data['alt_phone'])) {
        $errors[] = 'Alternate phone must be in format ' . PHONE_NUMBER_EXAMPLE;
    }
    
    if (!empty($data['emergency_contact_phone']) && !validatePhoneNumber($data['emergency_contact_phone'])) {
        $errors[] = 'Emergency contact phone must be in format ' . PHONE_NUMBER_EXAMPLE;
    }
    
    // Validate field lengths
    $fieldChecks = [
        'title' => 'Job title',
        'employeeId' => 'Employee ID',
        'emergency_contact_name' => 'Emergency contact name'
    ];
    
    foreach ($fieldChecks as $field => $label) {
        if (!empty($data[$field]) && strlen($data[$field]) > USER_FIELD_LENGTHS[$field]) {
            $errors[] = "$label is too long";
        }
    }
    
    return $errors;
}

/**
 * Validate password strength
 * @param string $password Password to validate
 * @return array Array of validation errors (empty if valid)
 */
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long';
    }
    
    if (REQUIRE_PASSWORD_COMPLEXITY) {
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }
    }
    
    return $errors;
}

/**
 * Check if email already exists for another user
 * @param mysqli $conn Database connection
 * @param string $email Email to check
 * @param int $excludeUserId User ID to exclude from check (for updates)
 * @return bool True if email exists, false otherwise
 */
function emailExists($conn, $email, $excludeUserId = null) {
    if ($excludeUserId) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->bind_param("si", $email, $excludeUserId);
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Log user activity for audit purposes
 * @param mysqli $conn Database connection
 * @param int $userId User ID performing action
 * @param string $action Action performed
 * @param string $details Additional details
 * @return bool Success status
 */
function logUserActivity($conn, $userId, $action, $details = '') {
    $stmt = $conn->prepare("INSERT INTO user_activity_log (user_id, action, details, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    if ($stmt === false) {
        error_log('Failed to prepare user activity log statement: ' . $conn->error);
        return false;
    }
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt->bind_param("issss", $userId, $action, $details, $ipAddress, $userAgent);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Generate secure password reset token
 * @return string Random token
 */
function generatePasswordResetToken() {
    return bin2hex(random_bytes(32));
}

/**
 * Store password reset token in database
 * @param mysqli $conn Database connection
 * @param string $email User email
 * @param string $token Reset token
 * @return bool Success status
 */
function storePasswordResetToken($conn, $email, $token) {
    $stmt = $conn->prepare("INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE token = ?, created_at = NOW()");
    if ($stmt === false) {
        error_log('Failed to prepare password reset token statement: ' . $conn->error);
        return false;
    }
    
    $stmt->bind_param("sss", $email, $token, $token);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Verify password reset token
 * @param mysqli $conn Database connection
 * @param string $token Reset token
 * @return array|false User data if valid, false otherwise
 */
function verifyPasswordResetToken($conn, $token) {
    $stmt = $conn->prepare("
        SELECT pr.email, u.id, u.firstName, u.lastName 
        FROM password_resets pr 
        JOIN users u ON pr.email = u.email 
        WHERE pr.token = ? AND pr.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    if ($stmt === false) {
        error_log('Failed to prepare password reset verification statement: ' . $conn->error);
        return false;
    }
    
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    return $user ?: false;
}

/**
 * Delete used password reset token
 * @param mysqli $conn Database connection
 * @param string $token Reset token to delete
 * @return bool Success status
 */
function deletePasswordResetToken($conn, $token) {
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
    if ($stmt === false) {
        error_log('Failed to prepare password reset token deletion statement: ' . $conn->error);
        return false;
    }
    
    $stmt->bind_param("s", $token);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}
?>