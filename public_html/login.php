<?php
// login.php

// Include our central configuration file.
require_once __DIR__ . '/../config/config.php';

$error_message = '';

// If user is already logged in, redirect them based on their role.
if (isset($_SESSION['user_id'])) {
    $admin_roles = [1, 2, 3]; // Super Admin, Admin, Manager
    if (in_array($_SESSION['user_role_id'], $admin_roles)) {
        header('Location: usermgmt/index.php');
    } else {
        header('Location: profile.php');
    }
    exit();
}

// Handle the form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $pass = $_POST['password'];

    // Updated query to fetch user details including their roleId.
    $stmt = $conn->prepare("SELECT id, password, firstName, lastName, roleId FROM users WHERE email = ? AND (terminationDate IS NULL OR terminationDate >= CURDATE())");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Verify the password
        if (password_verify($pass, $user['password'])) {
            // Success! Regenerate session ID for security.
            session_regenerate_id(true); 
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_first_name'] = $user['firstName'];
            $_SESSION['user_last_name'] = $user['lastName'];
            $_SESSION['user_role_id'] = $user['roleId'];
            
            // --- ROLE-BASED REDIRECTION ---
            // Define which roles are considered administrative.
            $admin_roles = [1, 2, 3]; // Super Admin, Admin, Manager

            // Check if the user's role is in the admin list.
            if (in_array($user['roleId'], $admin_roles)) {
                // Redirect admins to the user management page.
                header('Location: usermgmt/index.php');
            } else {
                // Redirect all other users to their profile page.
                header('Location: profile.php');
            }
            exit();
        }
    }

    // If we reach here, login failed.
    $error_message = 'Invalid email or password.';
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Safety Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="w-full max-w-md">
        <div class="bg-white shadow-lg rounded-lg p-8">
            <div class="flex justify-center items-center mb-6">
                <img src="https://swfunk.com/wp-content/uploads/2020/04/Goal-Zero-1.png" alt="Logo" class="h-12 w-auto mr-4">
                <h1 class="text-3xl font-bold text-gray-800">Safety Hub</h1>
            </div>
            
            <?php if ($error_message): ?>
                <div class="px-4 py-3 rounded-lg relative mb-6 bg-red-100 border border-red-400 text-red-700">
                    <strong class="font-bold">Error:</strong>
                    <span class="block sm:inline"><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-4">
                    <label for="email" class="block text-gray-700 text-sm font-bold mb-2">Email Address</label>
                    <input type="email" name="email" id="email" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                           placeholder="Enter your email">
                </div>
                
                <div class="mb-6">
                    <label for="password" class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                    <input type="password" name="password" id="password" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                           placeholder="Enter your password">
                </div>
                
                <div class="flex items-center justify-between mb-6">
                    <button type="submit" 
                            class="w-full bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-200">
                        Sign In
                    </button>
                </div>
            </form>
            
            <div class="text-center">
                <a href="forgot_password.php" class="text-blue-500 hover:text-blue-700 text-sm">
                    Forgot your password?
                </a>
            </div>
        </div>
    </div>
</body>
</html>