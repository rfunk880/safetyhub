<?php
// users.php - Complete file with shared navigation

// Include the shared navigation and other core files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/auth.php';

// Check if user is logged in and has appropriate permissions
if (!isUserLoggedIn()) {
    header("Location: login.php");
    exit();
}

// Check user permissions for user management
$admin_roles = [1, 2, 3]; // Super Admin, Admin, Manager
$user_can_edit = in_array($_SESSION['user_role_id'], $admin_roles);
$user_is_super_admin = ($_SESSION['user_role_id'] == 1);
$user_can_send_emails = in_array($_SESSION['user_role_id'], [1, 2]); // Super Admin, Admin only

if (!$user_can_edit) {
    die("Access denied. You don't have permission to view this page.");
}

// Handle POST actions (create, edit, archive, email actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    
    if ($post_action === 'create_user' && $user_can_edit) {
        $firstName = trim($_POST['firstName'] ?? '');
        $lastName = trim($_POST['lastName'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $employeeId = trim($_POST['employeeId'] ?? '');
        $roleId = (int)($_POST['roleId'] ?? 0);
        $type = trim($_POST['type'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $mobile_phone = trim($_POST['mobile_phone'] ?? '');
        $alt_phone = trim($_POST['alt_phone'] ?? '');
        $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
        $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if (empty($firstName) || empty($lastName) || empty($email) || $roleId <= 0) {
            $_SESSION['toastMessage'] = "Error: All required fields must be filled.";
        } else {
            // Check if email already exists
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $_SESSION['toastMessage'] = "Error: Email already exists.";
            } else {
                // Use default password if none provided
                if (empty($password)) {
                    $password = 'password123';
                }
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("INSERT INTO users (firstName, lastName, email, password, employeeId, roleId, title, mobile_phone, alt_phone, emergency_contact_name, emergency_contact_phone, type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("sssssissssss", $firstName, $lastName, $email, $hashedPassword, $employeeId, $roleId, $title, $mobile_phone, $alt_phone, $emergency_contact_name, $emergency_contact_phone, $type);
                
                if ($stmt->execute()) {
                    $_SESSION['toastMessage'] = "User created successfully.";
                } else {
                    $_SESSION['toastMessage'] = "Error creating user.";
                }
                $stmt->close();
            }
            $check_stmt->close();
        }
    } elseif ($post_action === 'edit_user' && $user_can_edit) {
        $userId = (int)($_POST['userId'] ?? 0);
        $firstName = trim($_POST['firstName'] ?? '');
        $lastName = trim($_POST['lastName'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $employeeId = trim($_POST['employeeId'] ?? '');
        $roleId = (int)($_POST['roleId'] ?? 0);
        $type = trim($_POST['type'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $mobile_phone = trim($_POST['mobile_phone'] ?? '');
        $alt_phone = trim($_POST['alt_phone'] ?? '');
        $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
        $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
        
        if ($userId > 0 && !empty($firstName) && !empty($lastName) && !empty($email) && $roleId > 0) {
            $stmt = $conn->prepare("UPDATE users SET firstName = ?, lastName = ?, email = ?, employeeId = ?, roleId = ?, type = ?, title = ?, mobile_phone = ?, alt_phone = ?, emergency_contact_name = ?, emergency_contact_phone = ? WHERE id = ?");
            $stmt->bind_param("ssssississsi", $firstName, $lastName, $email, $employeeId, $roleId, $type, $title, $mobile_phone, $alt_phone, $emergency_contact_name, $emergency_contact_phone, $userId);
            
            if ($stmt->execute()) {
                $_SESSION['toastMessage'] = "User updated successfully.";
            } else {
                $_SESSION['toastMessage'] = "Error updating user.";
            }
            $stmt->close();
        } else {
            $_SESSION['toastMessage'] = "Error: Invalid data provided.";
        }
    } elseif ($post_action === 'archive_user' && $user_can_edit) {
        $userId = (int)($_POST['userId'] ?? 0);
        if ($userId > 0 && $userId !== $_SESSION['user_id']) {
            // Check current termination status
            $stmt = $conn->prepare("SELECT terminationDate FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($result) {
                $isCurrentlyArchived = !empty($result['terminationDate']) && $result['terminationDate'] < date('Y-m-d');
                
                if ($isCurrentlyArchived) {
                    // Unarchive - set terminationDate to NULL
                    $stmt = $conn->prepare("UPDATE users SET terminationDate = NULL WHERE id = ?");
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $_SESSION['toastMessage'] = "User unarchived successfully.";
                } else {
                    // Archive - set terminationDate to yesterday
                    $yesterday = date('Y-m-d', strtotime('-1 day'));
                    $stmt = $conn->prepare("UPDATE users SET terminationDate = ? WHERE id = ?");
                    $stmt->bind_param("si", $yesterday, $userId);
                    $stmt->execute();
                    $_SESSION['toastMessage'] = "User archived successfully.";
                }
                $stmt->close();
            }
        }
    } elseif ($post_action === 'send_setup_email' && $user_can_edit) {
        $userId = (int)($_POST['userId'] ?? 0);
        if ($userId > 0) {
            $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($user) {
                $token = bin2hex(random_bytes(50));
                $stmt_token = $conn->prepare("INSERT INTO password_resets (email, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = ?, created_at = NOW()");
                $stmt_token->bind_param("sss", $user['email'], $token, $token);
                $stmt_token->execute();
                
                if (function_exists('sendSetupEmail') && sendSetupEmail($user['email'], $token)) {
                    $_SESSION['toastMessage'] = "Setup email sent successfully to {$user['email']}.";
                } else {
                    $_SESSION['toastMessage'] = "Error sending email to {$user['email']}.";
                }
            }
        }
    } elseif ($post_action === 'send_setup_emails' && $user_can_edit) {
        $userIds = $_POST['userIds'] ?? [];
        if (!empty($userIds)) {
            $sanitizedIds = array_map('intval', $userIds);
            $placeholders = implode(',', array_fill(0, count($sanitizedIds), '?'));
            $types = str_repeat('i', count($sanitizedIds));
            $user_query = $conn->prepare("SELECT email FROM users WHERE id IN ($placeholders)");
            $user_query->bind_param($types, ...$sanitizedIds);
            $user_query->execute();
            $result = $user_query->get_result();
            $email_count = 0; $error_count = 0;
            while($user = $result->fetch_assoc()) {
                $token = bin2hex(random_bytes(50));
                $stmt_token = $conn->prepare("INSERT INTO password_resets (email, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = ?, created_at = NOW()");
                $stmt_token->bind_param("sss", $user['email'], $token, $token);
                $stmt_token->execute();
                if(function_exists('sendSetupEmail') && sendSetupEmail($user['email'], $token)) { $email_count++; } else { $error_count++; }
            }
            $_SESSION['toastMessage'] = "Sent {$email_count} setup emails. Failed to send {$error_count}.";
        }
    } elseif ($post_action === 'delete_selected' && $user_is_super_admin) {
        $userIds = $_POST['userIds'] ?? [];
        if (!empty($userIds)) {
            $sanitizedIds = array_map('intval', $userIds);
            // Remove current user from deletion list
            $sanitizedIds = array_diff($sanitizedIds, [$_SESSION['user_id']]);
            
            if (!empty($sanitizedIds)) {
                $placeholders = implode(',', array_fill(0, count($sanitizedIds), '?'));
                $types = str_repeat('i', count($sanitizedIds));
                $stmt = $conn->prepare("DELETE FROM users WHERE id IN ($placeholders)");
                $stmt->bind_param($types, ...$sanitizedIds);
                
                if ($stmt->execute()) {
                    $_SESSION['toastMessage'] = "Successfully deleted {$stmt->affected_rows} user(s).";
                } else {
                    $_SESSION['toastMessage'] = "Error deleting users.";
                }
                $stmt->close();
            } else {
                $_SESSION['toastMessage'] = "No users were deleted (you cannot delete yourself).";
            }
        }
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
    exit();
}

// Define filters, sorting, and pagination parameters from URL
$search_query = trim($_GET['search'] ?? '');
$role_filter = (int)($_GET['role'] ?? 0);
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? 'active';
$per_page = (int)($_GET['per_page'] ?? 25);
if (!in_array($per_page, [25, 50, 100])) $per_page = 25;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$valid_sort_columns = ['u.lastName', 'u.title'];
$sort_by = in_array('u.' . ($_GET['sort'] ?? ''), $valid_sort_columns) ? 'u.' . ($_GET['sort'] ?? '') : 'u.lastName';
$sort_order = in_array(strtoupper($_GET['order'] ?? ''), ['ASC', 'DESC']) ? strtoupper($_GET['order']) : 'ASC';

// Build WHERE clauses and parameters
$where_clauses = []; $filter_params = []; $filter_types = '';

$where_clauses[] = "u.id != ?";
$filter_params[] = $_SESSION['user_id'];
$filter_types .= 'i';

if ($search_query !== '') {
    $where_clauses[] = "(u.firstName LIKE ? OR u.lastName LIKE ? OR u.email LIKE ?)";
    $search_param = "%{$search_query}%";
    array_push($filter_params, $search_param, $search_param, $search_param);
    $filter_types .= 'sss';
}
if ($role_filter > 0) { $where_clauses[] = "u.roleId = ?"; $filter_params[] = $role_filter; $filter_types .= 'i';}
if ($type_filter !== '') { $where_clauses[] = "u.type = ?"; $filter_params[] = $type_filter; $filter_types .= 's'; }
if ($status_filter === 'active') { 
    $where_clauses[] = "(u.terminationDate IS NULL OR u.terminationDate >= CURDATE())"; 
} elseif ($status_filter === 'archived') { 
    $where_clauses[] = "u.terminationDate IS NOT NULL AND u.terminationDate < CURDATE()"; 
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM users u WHERE " . implode(' AND ', $where_clauses);
$count_stmt = $conn->prepare($count_query);
if (!empty($filter_params)) { $count_stmt->bind_param($filter_types, ...$filter_params); }
$count_stmt->execute();
$total_users = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = ceil($total_users / $per_page);
$offset = ($page - 1) * $per_page;

// Get users with pagination
$user_query = "SELECT u.id, u.firstName, u.lastName, u.email, u.employeeId, u.roleId, u.title, u.mobile_phone, u.alt_phone, u.emergency_contact_name, u.emergency_contact_phone, u.type, u.terminationDate, u.profilePicture, r.name as roleName FROM users u LEFT JOIN roles r ON u.roleId = r.id WHERE " . implode(' AND ', $where_clauses) . " ORDER BY {$sort_by} {$sort_order} LIMIT {$per_page} OFFSET {$offset}";
$user_stmt = $conn->prepare($user_query);
if (!empty($filter_params)) { $user_stmt->bind_param($filter_types, ...$filter_params); }
$user_stmt->execute();
$users = $user_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$user_stmt->close();

// Debug: Log the first user to check if type field is populated
if (!empty($users)) {
    error_log("Debug - First user data: " . print_r($users[0], true));
}

// Get roles for dropdowns
$roles = [];
$result_roles = $conn->query("SELECT id, name FROM roles ORDER BY id");
if ($result_roles) {
    while($row = $result_roles->fetch_assoc()) {
        $roles[] = $row;
    }
}

// Get toast message and clear it
$toastMessage = $_SESSION['toastMessage'] ?? ''; 
unset($_SESSION['toastMessage']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Safety Hub</title>
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
        .modal-backdrop { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0,0,0,0.5); z-index: 40; }
        .modal { display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 50; max-height: 90vh; overflow-y: auto; }
        .modal.active, .modal-backdrop.active { display: block; }
        .sortable-header a { display: flex; align-items: center; }
        .sortable-header .sort-icon { margin-left: 4px; color: #9ca3af; }
        .sortable-header.active .sort-icon { color: #1f2937; }
    </style>
</head>
<body class="bg-gray-100">
    <div id="app" class="flex h-screen">
        
        <!-- Use the shared navigation -->
        <?php renderNavigation(); ?>
        
        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <header class="flex justify-between items-center p-4 bg-white border-b">
                <h2 class="text-xl font-semibold text-gray-700">User Management</h2>
                <div class="flex items-center">
                    <span class="text-sm text-gray-600 mr-4">Welcome, <?php echo htmlspecialchars($_SESSION['user_first_name']); ?>!</span>
                </div>
            </header>

            <div class="flex-1 p-4 md:p-6 overflow-y-auto">
                <div class="bg-white p-6 rounded-lg shadow-md">
                    <!-- Filters Form -->
                    <form id="filtersForm" method="GET" action="users.php" class="flex flex-col md:flex-row justify-between items-center mb-4">
                        <div class="flex flex-wrap items-center gap-2 mb-4 md:mb-0">
                            <div class="relative">
                                <input type="text" id="searchInput" name="search" placeholder="Search users..." value="<?php echo htmlspecialchars($search_query); ?>" class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <i data-lucide="search" class="absolute left-3 top-2.5 h-5 w-5 text-gray-400"></i>
                            </div>
                            <select name="role" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">All Roles</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>" <?php echo $role_filter == $role['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="type" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">All Types</option>
                                <option value="Employee" <?php echo $type_filter === 'Employee' ? 'selected' : ''; ?>>Employee</option>
                                <option value="Subcontractor" <?php echo $type_filter === 'Subcontractor' ? 'selected' : ''; ?>>Subcontractor</option>
                            </select>
                            <select name="status" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All</option>
                            </select>
                            <select name="per_page" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25 per page</option>
                                <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 per page</option>
                                <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 per page</option>
                            </select>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Apply Filters</button>
                            <a href="users.php" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">Clear</a>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if ($user_can_edit): ?>
                                <button type="button" onclick="openModal()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center">
                                    <i data-lucide="plus" class="w-4 h-4 mr-2"></i>Add User
                                </button>
                                <a href="bulk_upload.php" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 flex items-center">
                                    <i data-lucide="upload" class="w-4 h-4 mr-2"></i>Bulk Import
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <!-- Results Summary and Action Buttons -->
                    <div class="mb-4 flex justify-between items-center">
                        <p class="text-sm text-gray-600">
                            Showing <?php echo min($offset + 1, $total_users); ?>-<?php echo min($offset + $per_page, $total_users); ?> of <?php echo $total_users; ?> users
                        </p>
                        <div class="flex items-center space-x-2">
                            <!-- Bulk Actions (shown when users are selected) -->
                            <div id="bulkActions" class="hidden space-x-2">
                                <?php if ($user_can_send_emails): ?>
                                    <button type="button" onclick="sendBulkEmails()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                        <i data-lucide="mail" class="w-4 h-4 mr-1"></i>Send Setup Emails
                                    </button>
                                <?php endif; ?>
                                <?php if ($user_is_super_admin): ?>
                                    <button type="button" onclick="deleteSelected()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm">
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>Delete Selected
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Users Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white border border-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <?php if ($user_can_edit): ?>
                                        <th class="p-3 text-left">
                                            <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        </th>
                                    <?php endif; ?>
                                    <th class="p-3 text-left sortable-header <?php echo (strpos($sort_by, 'lastName') !== false) ? 'active' : ''; ?>">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'lastName', 'order' => (strpos($sort_by, 'lastName') !== false && $sort_order === 'ASC') ? 'DESC' : 'ASC'])); ?>" class="flex items-center">
                                            Name
                                            <i data-lucide="chevron-<?php echo (strpos($sort_by, 'lastName') !== false && $sort_order === 'ASC') ? 'up' : 'down'; ?>" class="sort-icon w-4 h-4"></i>
                                        </a>
                                    </th>
                                    <th class="p-3 text-left">Email</th>
                                    <th class="p-3 text-left sortable-header <?php echo (strpos($sort_by, 'title') !== false) ? 'active' : ''; ?>">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'title', 'order' => (strpos($sort_by, 'title') !== false && $sort_order === 'ASC') ? 'DESC' : 'ASC'])); ?>" class="flex items-center">
                                            Title
                                            <i data-lucide="chevron-<?php echo (strpos($sort_by, 'title') !== false && $sort_order === 'ASC') ? 'up' : 'down'; ?>" class="sort-icon w-4 h-4"></i>
                                        </a>
                                    </th>
                                    <th class="p-3 text-left">Type</th>
                                    <th class="p-3 text-left">Role</th>
                                    <th class="p-3 text-left">Status</th>
                                    <?php if ($user_can_edit): ?>
                                        <th class="p-3 text-right">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="<?php echo $user_can_edit ? '8' : '6'; ?>" class="p-8 text-center text-gray-500">
                                            No users found matching your criteria.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr class="hover:bg-gray-50">
                                            <?php if ($user_can_edit): ?>
                                                <td class="p-3">
                                                    <input type="checkbox" name="userIds[]" value="<?php echo $user['id']; ?>" class="user-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                </td>
                                            <?php endif; ?>
                                            <td class="p-3">
                                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></div>
                                            </td>
                                            <td class="p-3 text-gray-700"><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td class="p-3 text-gray-700"><?php echo htmlspecialchars($user['title'] ?: 'N/A'); ?></td>
                                            <td class="p-3 text-gray-700"><?php echo htmlspecialchars($user['type']); ?></td>
                                            <td class="p-3 text-gray-700"><?php echo getRoleName($user['roleId'], $roles); ?></td>
                                            <td class="p-3">
                                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo isUserArchived($user) ? 'bg-gray-200 text-gray-800' : 'bg-green-100 text-green-800'; ?>">
                                                    <?php echo isUserArchived($user) ? 'Archived' : 'Active'; ?>
                                                </span>
                                            </td>
                                            <?php if ($user_can_edit): ?>
                                            <td class="p-3 text-right">
                                                <div class="flex justify-end items-center space-x-1">
                                                    <!-- View Profile Icon -->
                                                    <a href="view_profile.php?id=<?php echo $user['id']; ?>" 
                                                       title="View Profile" 
                                                       target="_blank"
                                                       class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-100 rounded-full transition-colors">
                                                        <i data-lucide="eye" class="w-5 h-5"></i>
                                                    </a>

                                                    <!-- Send Setup Email Icon -->
                                                    <button type="button" 
                                                            onclick="handleAction('send_setup_email', <?php echo $user['id']; ?>)" 
                                                            title="Send Setup/Reset Email" 
                                                            class="p-2 text-gray-500 hover:text-yellow-600 hover:bg-yellow-100 rounded-full transition-colors">
                                                        <i data-lucide="key-round" class="w-5 h-5"></i>
                                                    </button>

                                                    <!-- Edit Icon -->
                                                    <button type="button" 
                                                            onclick="openModal(<?php echo $user['id']; ?>)" 
                                                            title="Edit User" 
                                                            class="p-2 text-gray-500 hover:text-green-600 hover:bg-green-100 rounded-full transition-colors">
                                                        <i data-lucide="edit" class="w-5 h-5"></i>
                                                    </button>

                                                    <!-- Archive/Un-archive Icon -->
                                                    <button type="button" 
                                                            onclick="handleAction('archive_user', <?php echo $user['id']; ?>)" 
                                                            title="<?php echo isUserArchived($user) ? 'Unarchive User' : 'Archive User'; ?>" 
                                                            class="p-2 text-gray-500 hover:text-red-600 hover:bg-red-100 rounded-full transition-colors">
                                                        <i data-lucide="<?php echo isUserArchived($user) ? 'user-check' : 'user-x'; ?>" class="w-5 h-5"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="flex justify-between items-center mt-6">
                            <div class="text-sm text-gray-600">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50">First</a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Previous</a>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                       class="px-3 py-2 text-sm border rounded-lg <?php echo $i === $page ? 'bg-blue-600 text-white border-blue-600' : 'bg-white border-gray-300 hover:bg-gray-50'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Next</a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Last</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal Backdrop -->
    <div id="modalBackdrop" class="modal-backdrop"></div>

    <!-- User Modal -->
    <div id="userModal" class="modal bg-white rounded-lg shadow-xl w-full max-w-2xl p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 id="modalTitle" class="text-lg font-semibold">Add User</h3>
            <button type="button" onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        
        <form id="userForm" method="POST" action="users.php">
            <input type="hidden" name="action" id="formAction" value="create_user">
            <input type="hidden" name="userId" id="userId" value="">
            
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="firstName" class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                        <input type="text" name="firstName" id="firstName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label for="lastName" class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                        <input type="text" name="lastName" id="lastName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <input type="email" name="email" id="email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label for="employeeId" class="block text-sm font-medium text-gray-700 mb-1">Employee ID</label>
                        <input type="text" name="employeeId" id="employeeId" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Job Title</label>
                    <input type="text" name="title" id="title" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="mobile_phone" class="block text-sm font-medium text-gray-700 mb-1">Mobile Phone</label>
                        <input type="tel" name="mobile_phone" id="mobile_phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label for="alt_phone" class="block text-sm font-medium text-gray-700 mb-1">Alternate Phone</label>
                        <input type="tel" name="alt_phone" id="alt_phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Name</label>
                        <input type="text" name="emergency_contact_name" id="emergency_contact_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Phone</label>
                        <input type="tel" name="emergency_contact_phone" id="emergency_contact_phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                        <select name="type" id="type" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">Select Type</option>
                            <option value="Employee">Employee</option>
                            <option value="Subcontractor">Subcontractor</option>
                        </select>
                    </div>
                    <div>
                        <label for="roleId" class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                        <select name="roleId" id="roleId" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">Select Role</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div id="passwordField">
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="password" id="password" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <p class="text-xs text-gray-500 mt-1">Leave blank to use default password (password123)</p>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <span id="submitButtonText">Create User</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Toast Notification -->
    <?php if ($toastMessage): ?>
        <div id="toast" class="fixed bottom-4 right-4 bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg z-50">
            <?php echo htmlspecialchars($toastMessage); ?>
        </div>
    <?php endif; ?>

    <script>
        lucide.createIcons();
        
        // Mobile menu toggle (handled by navigation.php but ensuring it works)
        document.addEventListener('DOMContentLoaded', function() {
            const menuButton = document.getElementById('menu-button');
            const sidebar = document.getElementById('sidebar');
            
            if (menuButton && sidebar) {
                menuButton.addEventListener('click', function() {
                    sidebar.classList.toggle('open');
                });
            }
        });

        // User management JavaScript functions
        const users = <?php echo json_encode($users); ?>;
        
        function openModal(userId = null) {
            const modal = document.getElementById('userModal');
            const backdrop = document.getElementById('modalBackdrop');
            const form = document.getElementById('userForm');
            const title = document.getElementById('modalTitle');
            const action = document.getElementById('formAction');
            const submitButton = document.getElementById('submitButtonText');
            const passwordField = document.getElementById('passwordField');
            
            if (userId) {
                // Edit mode
                const user = users.find(u => u.id == userId);
                if (user) {
                    title.textContent = 'Edit User';
                    action.value = 'edit_user';
                    submitButton.textContent = 'Update User';
                    passwordField.style.display = 'none';
                    
                    // Set all form values
                    document.getElementById('userId').value = user.id;
                    document.getElementById('firstName').value = user.firstName || '';
                    document.getElementById('lastName').value = user.lastName || '';
                    document.getElementById('email').value = user.email || '';
                    document.getElementById('employeeId').value = user.employeeId || '';
                    document.getElementById('title').value = user.title || '';
                    document.getElementById('mobile_phone').value = user.mobile_phone || '';
                    document.getElementById('alt_phone').value = user.alt_phone || '';
                    document.getElementById('emergency_contact_name').value = user.emergency_contact_name || '';
                    document.getElementById('emergency_contact_phone').value = user.emergency_contact_phone || '';
                    
                    // Set dropdown values with explicit value assignment
                    const typeSelect = document.getElementById('type');
                    const roleSelect = document.getElementById('roleId');
                    
                    typeSelect.value = user.type || '';
                    roleSelect.value = user.roleId || '';
                    
                    // Debug logging to check values
                    console.log('User type:', user.type);
                    console.log('Type select value after setting:', typeSelect.value);
                    console.log('User roleId:', user.roleId);
                    console.log('Role select value after setting:', roleSelect.value);
                }
            } else {
                // Create mode
                title.textContent = 'Add User';
                action.value = 'create_user';
                submitButton.textContent = 'Create User';
                passwordField.style.display = 'block';
                form.reset();
                document.getElementById('formAction').value = 'create_user';
            }
            
            modal.classList.add('active');
            backdrop.classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
            document.getElementById('modalBackdrop').classList.remove('active');
        }
        
        function handleAction(action, userId) {
            if (confirm('Are you sure you want to perform this action?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'users.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = action;
                
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'userId';
                userIdInput.value = userId;
                
                form.appendChild(actionInput);
                form.appendChild(userIdInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function sendBulkEmails() {
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one user.');
                return;
            }
            
            if (confirm(`Send setup emails to ${checkboxes.length} selected users?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'users.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'send_setup_emails';
                form.appendChild(actionInput);
                
                checkboxes.forEach(checkbox => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'userIds[]';
                    input.value = checkbox.value;
                    form.appendChild(input);
                });
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function deleteSelected() {
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one user to delete.');
                return;
            }
            
            if (confirm(`Are you sure you want to permanently delete ${checkboxes.length} selected user(s)? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'users.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_selected';
                form.appendChild(actionInput);
                
                checkboxes.forEach(checkbox => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'userIds[]';
                    input.value = checkbox.value;
                    form.appendChild(input);
                });
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Select all functionality
        document.getElementById('selectAll')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActionVisibility();
        });
        
        // Update bulk action visibility when individual checkboxes change
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('user-checkbox')) {
                updateBulkActionVisibility();
            }
        });
        
        function updateBulkActionVisibility() {
            const checkedBoxes = document.querySelectorAll('.user-checkbox:checked');
            const bulkActions = document.getElementById('bulkActions');
            
            if (checkedBoxes.length > 0) {
                bulkActions.classList.remove('hidden');
            } else {
                bulkActions.classList.add('hidden');
            }
        }
        
        // Close modal when clicking backdrop
        document.getElementById('modalBackdrop')?.addEventListener('click', closeModal);
        
        // Auto-submit filters form when selections change
        document.querySelectorAll('#filtersForm select').forEach(select => {
            select.addEventListener('change', function() {
                document.getElementById('filtersForm').submit();
            });
        });
        
        // Auto-hide toast after 5 seconds
        const toast = document.getElementById('toast');
        if (toast) {
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }
        
        // Search with Enter key
        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('filtersForm').submit();
            }
        });
    </script>
</body>
</html>