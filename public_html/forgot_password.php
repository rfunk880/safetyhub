<?php
// forgot_password.php

require_once __DIR__ . '/../config/config.php';

$message = '';
$is_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];

    // 1. Check if the email exists in the users table
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // 2. Generate a secure, unique token
        $token = bin2hex(random_bytes(50));

        // 3. Store the token in the password_resets table
        $stmt_token = $conn->prepare("INSERT INTO password_resets (email, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = ?, created_at = NOW()");
        $stmt_token->bind_param("sss", $email, $token, $token);
        $stmt_token->execute();
        
        // 4. Send the email using our new function
        if(sendSetupEmail($email, $token)) {
            $message = 'If an account with that email exists, a password reset link has been sent.';
            $is_success = true;
        } else {
            $message = 'There was an error sending the email. Please try again later.';
            $is_success = false;
        }

    } else {
        // Always show a generic success message to prevent email enumeration
        $message = 'If an account with that email exists, a password reset link has been sent.';
        $is_success = true;
    }
    
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Safety Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="w-full max-w-md">
        <div class="bg-white shadow-lg rounded-lg p-8">
            <div class="flex justify-center items-center mb-6">
                <img src="https://swfunk.com/wp-content/uploads/2020/04/Goal-Zero-1.png" alt="Logo" class="h-12 w-auto mr-4">
                <h1 class="text-3xl font-bold text-gray-800">Forgot Password</h1>
            </div>

            <p class="text-gray-600 text-center mb-6">Enter your email address and we will send a link to reset your password.</p>
            
            <?php if ($message): ?>
                <div class="px-4 py-3 rounded-lg relative mb-6 <?php echo $is_success ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>" role="alert">
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <form action="forgot_password.php" method="POST">
                <div class="mb-4">
                    <label for="email" class="block text-gray-700 text-sm font-bold mb-2">Email Address</label>
                    <input type="email" name="email" id="email" class="shadow-sm appearance-none border rounded-lg w-full py-3 px-4 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                </div>
                <div class="flex items-center justify-between">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg focus:outline-none focus:shadow-outline">
                        Send Reset Link
                    </button>
                </div>
            </form>

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
