<?php
// /src/auth.php
// This file contains all user authentication and management functions.
// This version uses the original mysqli ($conn) connection method.

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
    $stmt = $conn->prepare("SELECT id, password, roleId FROM users WHERE email = ?");
    if ($stmt === false) {
        error_log('Prepare failed in handleLogin: ' . $conn->error);
        return false;
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role_id'] = $user['roleId']; // Make sure session key is consistent
        return true;
    }
    return false;
}
