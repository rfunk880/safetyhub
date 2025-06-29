<?php
// login.php

// Include our central configuration file.
require_once __DIR__ . '/../config/config.php';

$error_message = '';

// If user is already logged in, redirect them based on their role.
if (isset($_SESSION['user_id'])) {
    $admin_roles = [1, 2, 3]; // Super Admin, Admin, Manager
    if (in_array($_SESSION['user_role_id'], $admin_roles)) {
        header('Location: users.php');
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
                header('Location: users.php');
            } else {
                // Redirect all other users to their profile page.
                header('Location: profile.php');
            }
            exit();
        }
    }

    // If we reach here, login failed.
    $error_message = 'Invalid email or password.';
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Safety Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@0.378.0/dist/umd/lucide.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .password-container { position: relative; }
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6b7280;
        }
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
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="mb-4">
                    <label for="email" class="block text-gray-700 text-sm font-bold mb-2">Email Address</label>
                    <input type="email" name="email" id="email" class="shadow-sm appearance-none border rounded-lg w-full py-3 px-4 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                </div>
                <div class="mb-6">
                    <label for="password" class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                    <div class="password-container">
                        <input type="password" name="password" id="password" class="shadow-sm appearance-none border rounded-lg w-full py-3 px-4 text-gray-700 mb-3 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <span id="passwordToggle" class="password-toggle">
                            <i data-lucide="eye"></i>
                        </span>
                    </div>
                </div>
                <div class="flex items-center justify-between">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg focus:outline-none focus:shadow-outline">
                        Sign In
                    </button>
                </div>
                <div class="text-center mt-4">
                    <a href="forgot_password.php" class="inline-block align-baseline font-bold text-sm text-blue-500 hover:text-blue-800">
                        Forgot Password?
                    </a>
                </div>
            </form>
        </div>
        <p class="text-center text-gray-500 text-xs mt-4">&copy;<?php echo date("Y"); ?> SW Funk Industrial. All rights reserved.</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();

            const passwordToggle = document.getElementById('passwordToggle');
            const passwordInput = document.getElementById('password');

            passwordToggle.addEventListener('click', function() {
                // Toggle the type attribute
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);

                // Toggle the icon
                const icon = this.querySelector('i');
                if (type === 'password') {
                    icon.setAttribute('data-lucide', 'eye');
                } else {
                    icon.setAttribute('data-lucide', 'eye-off');
                }
                lucide.createIcons();
            });
        });
    </script>
</body>
</html>
