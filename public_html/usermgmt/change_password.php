<?php
// change_password.php
// Allows a logged-in user to change their own password.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/auth.php'; // Ensures the user is logged in.

$userId = $_SESSION['user_id'];
$feedback = '';
$is_success = false;

// --- Permission Check ---
// Define which roles are not allowed to change passwords here.
$admin_roles_no_change = [1, 2]; // 1 = Super Admin, 2 = Admin
$can_change_password = !in_array($_SESSION['user_role_id'], $admin_roles_no_change);

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only process the form if the user has permission
    if ($can_change_password) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // 1. Fetch the user's current hashed password from the database
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user) {
            // 2. Verify the current password is correct
            if (password_verify($current_password, $user['password'])) {
                // 3. Check if new passwords match
                if ($new_password === $confirm_password) {
                    // 4. Check for password complexity (e.g., minimum length)
                    if (strlen($new_password) >= 8) {
                        // 5. Hash the new password and update the database
                        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $update_stmt->bind_param("si", $new_password_hash, $userId);
                        
                        if ($update_stmt->execute()) {
                            $feedback = 'Your password has been changed successfully!';
                            $is_success = true;
                        } else {
                            $feedback = 'Error updating password. Please try again.';
                        }
                        $update_stmt->close();
                    } else {
                        $feedback = 'New password must be at least 8 characters long.';
                    }
                } else {
                    $feedback = 'New passwords do not match.';
                }
            } else {
                $feedback = 'Incorrect current password.';
            }
        } else {
            $feedback = 'Could not find user account.';
        }
    } else {
        // This message will be shown if an admin tries to submit the form directly.
        $feedback = 'Administrators must use the "Forgot Password" feature to reset passwords.';
    }
}


// --- Fetch user data for sidebar display ---
$stmt = $conn->prepare("SELECT u.*, r.name as roleName FROM users u JOIN roles r ON u.roleId = r.id WHERE u.id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user_for_sidebar = $result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user_for_sidebar) {
    die("Error: User not found.");
}
$loggedInUserProfilePicture = $user_for_sidebar['profilePicture']; 
$loggedInUserRoleName = $user_for_sidebar['roleName'];
$admin_roles = [1, 2, 3]; // Super Admin, Admin, Manager
$can_see_user_management = in_array($_SESSION['user_role_id'], $admin_roles);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Safety Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@0.378.0/dist/umd/lucide.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .sidebar { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .sidebar.open { transform: translateX(0); } }
    </style>
</head>
<body class="bg-gray-100">
    <div id="app" class="flex h-screen">
        <!-- Sidebar -->
<!-- NEW - Just this one line -->
<?php renderNavigation(); ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <header class="flex justify-between items-center p-4 bg-white border-b">
                 <button id="menu-button" class="md:hidden text-gray-500 focus:outline-none"><i data-lucide="menu" class="w-6 h-6"></i></button>
                <h2 class="text-xl font-semibold text-gray-700">Change Password</h2>
                <a href="profile.php" class="flex items-center text-blue-600 hover:text-blue-800 text-sm">
                    <i data-lucide="arrow-left" class="w-4 h-4 mr-1"></i>
                    Back to Profile
                </a>
            </header>

            <div class="flex-1 p-4 md:p-6 overflow-y-auto">
                <div class="bg-white p-6 rounded-lg shadow-md max-w-lg mx-auto">
                    <?php if ($feedback): ?>
                        <div class="mb-4 p-4 rounded-md <?php echo $is_success ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>" role="alert">
                           <?php echo htmlspecialchars($feedback); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($can_change_password): ?>
                        <form action="change_password.php" method="POST">
                            <div class="mb-4">
                                <label for="current_password" class="block text-sm font-medium text-gray-700">Current Password</label>
                                <input type="password" name="current_password" id="current_password" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" required>
                            </div>
                            <div class="mb-4">
                                <label for="new_password" class="block text-sm font-medium text-gray-700">New Password</label>
                                <input type="password" name="new_password" id="new_password" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" required>
                                 <p class="text-xs text-gray-500 mt-1">Must be at least 8 characters long.</p>
                            </div>
                             <div class="mb-6">
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                <input type="password" name="confirm_password" id="confirm_password" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" required>
                            </div>
                            <div class="flex items-center justify-end">
                                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline">
                                    Update Password
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="p-4 text-center bg-blue-50 border border-blue-200 rounded-lg">
                            <i data-lucide="shield-alert" class="w-12 h-12 text-blue-500 mx-auto mb-3"></i>
                            <h4 class="font-semibold text-lg text-blue-800">Administrator Password Policy</h4>
                            <p class="text-blue-700 mt-1">For security, administrators must change their passwords using the "Forgot Password" link on the login page. This ensures all password changes are verified via email.</p>
                            <a href="logout.php" class="mt-4 inline-block text-sm font-bold text-blue-600 hover:underline">Proceed to Login Page</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    <script>
        lucide.createIcons();
        document.getElementById('menu-button').addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('open');
        });
    </script>
</body>
</html>
