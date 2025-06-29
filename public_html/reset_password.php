<?php
// reset_password.php

require_once __DIR__ . '/../config/config.php';

$token = $_GET['token'] ?? '';
$error_message = '';
$success_message = '';
$token_is_valid = false;
$email = '';

if (empty($token)) {
    $error_message = "Invalid or missing reset token.";
} else {
    // 1. Validate the token
    // A token is considered valid if it exists and is less than 1 hour old.
    $stmt = $conn->prepare("SELECT email, created_at FROM password_resets WHERE token = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $token_is_valid = true;
        $email = $result->fetch_assoc()['email'];
    } else {
        $error_message = "This password reset link is invalid or has expired. Please request a new one.";
    }
    $stmt->close();
}


// 2. Handle the form submission if the token is valid
if ($token_is_valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];

    if ($password === $password_confirm) {
        if (strlen($password) >= 8) {
            // 3. Update the user's password
            $new_password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt_update = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmt_update->bind_param("ss", $new_password_hash, $email);
            $stmt_update->execute();

            // 4. Delete the used token
            $stmt_delete = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $stmt_delete->bind_param("s", $email);
            $stmt_delete->execute();

            $success_message = "Your password has been successfully updated! You can now log in.";
            $token_is_valid = false; // Hide the form after success
        } else {
            $error_message = "Password must be at least 8 characters long.";
        }
    } else {
        $error_message = "Passwords do not match.";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Safety Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="w-full max-w-md">
        <div class="bg-white shadow-lg rounded-lg p-8">
            <div class="flex justify-center items-center mb-6">
                <img src="https://swfunk.com/wp-content/uploads/2020/04/Goal-Zero-1.png" alt="Logo" class="h-12 w-auto mr-4">
                <h1 class="text-3xl font-bold text-gray-800">Reset Password</h1>
            </div>

            <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($token_is_valid): ?>
                <p class="text-gray-600 text-center mb-6">Create a new password for <?php echo htmlspecialchars($email); ?>.</p>
                <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="POST">
                    <div class="mb-4">
                        <label for="password" class="block text-gray-700 text-sm font-bold mb-2">New Password</label>
                        <input type="password" name="password" id="password" class="shadow-sm appearance-none border rounded-lg w-full py-3 px-4 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                    </div>
                     <div class="mb-6">
                        <label for="password_confirm" class="block text-gray-700 text-sm font-bold mb-2">Confirm New Password</label>
                        <input type="password" name="password_confirm" id="password_confirm" class="shadow-sm appearance-none border rounded-lg w-full py-3 px-4 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                    </div>
                    <div class="flex items-center justify-between">
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg focus:outline-none focus:shadow-outline">
                            Set New Password
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="login.php" class="inline-block align-baseline font-bold text-sm text-blue-500 hover:text-blue-800">
                    Back to Login
                </a>
            </div>
        </div>
        <p class="text-center text-gray-500 text-xs mt-4">&copy;<?php echo date("Y"); ?> SW Funk Industrial. All rights reserved.</p>
    </div>
</body>
</html>
