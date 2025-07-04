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
            // WORKAROUND: Store both phone numbers in alt_phone column as JSON
            $mobile_input = trim($_POST['mobile_phone_field'] ?? '');
            $alt_input = trim($_POST['alt_phone'] ?? '');
            
            $phone_data = json_encode([
                'mobile' => $mobile_input,
                'alt' => $alt_input
            ]);
            
            $mobile_phone = ''; // Leave mobile_phone column empty
            $alt_phone = $phone_data; // Store JSON data in alt_phone column
            
            // Check if email already exists
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $_SESSION['toastMessage'] = "Error: Email already exists.";
                $check_stmt->close();
            } else {
                $check_stmt->close();
                // Hash password and create user
                $hashedPassword = $password ? password_hash($password, PASSWORD_DEFAULT) : password_hash('password123', PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("INSERT INTO users (firstName, lastName, email, password, employeeId, roleId, type, title, mobile_phone, alt_phone, emergency_contact_name, emergency_contact_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssississss", $firstName, $lastName, $email, $hashedPassword, $employeeId, $roleId, $type, $title, $mobile_phone, $alt_phone, $emergency_contact_name, $emergency_contact_phone);
                
                if ($stmt->execute()) {
                    $_SESSION['toastMessage'] = "User created successfully.";
                } else {
                    $_SESSION['toastMessage'] = "Error creating user.";
                }
                $stmt->close();
            }
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
        
        // WORKAROUND: Store both phone numbers in alt_phone column as JSON
        // Due to mobile_phone column truncation issue
        $mobile_input = trim($_POST['mobile_phone_field'] ?? '');
        $alt_input = trim($_POST['alt_phone'] ?? '');
        
        $phone_data = json_encode([
            'mobile' => $mobile_input,
            'alt' => $alt_input
        ]);
        
        $mobile_phone = ''; // Leave mobile_phone column empty
        $alt_phone = $phone_data; // Store JSON data in alt_phone column
        
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
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($result) {
                $token = bin2hex(random_bytes(32));
                $stmt_token = $conn->prepare("INSERT INTO password_reset_tokens (email, token, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE token = ?, created_at = NOW()");
                $stmt_token->bind_param("sss", $result['email'], $token, $token);
                $stmt_token->execute();
                
                if(function_exists('sendSetupEmail') && sendSetupEmail($result['email'], $token)) {
                    $_SESSION['toastMessage'] = "Setup email sent successfully.";
                } else {
                    $_SESSION['toastMessage'] = "Error sending setup email.";
                }
                $stmt_token->close();
            }
        }
    } elseif ($post_action === 'send_bulk_setup_emails' && $user_can_send_emails) {
        $userIds = $_POST['userIds'] ?? [];
        if (!empty($userIds)) {
            $sanitizedIds = array_map('intval', $userIds);
            $placeholders = implode(',', array_fill(0, count($sanitizedIds), '?'));
            $types = str_repeat('i', count($sanitizedIds));
            
            $stmt = $conn->prepare("SELECT id, email FROM users WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$sanitizedIds);
            $stmt->execute();
            $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            $email_count = 0;
            $error_count = 0;
            
            foreach ($users as $user) {
                $token = bin2hex(random_bytes(32));
                $stmt_token = $conn->prepare("INSERT INTO password_reset_tokens (email, token, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE token = ?, created_at = NOW()");
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
$per_page = 25; // Fixed to 25 per page (removed dropdown functionality)
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
    $where_clauses[] = "(u.firstName LIKE ? OR u.lastName LIKE ? OR u.email LIKE ? OR u.title LIKE ? OR u.alt_phone LIKE ?)";
    $search_param = "%{$search_query}%";
    array_push($filter_params, $search_param, $search_param, $search_param, $search_param, $search_param);
    $filter_types .= 'sssss';
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

// Include the header
include_once __DIR__ . '/../templates/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        /* Custom smaller font size for the table */
        .users-table {
            font-size: 0.875rem; /* 14px - slightly smaller than default */
        }
        .users-table th,
        .users-table td {
            font-size: 0.875rem; /* 14px */
        }
        .sidebar { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) { 
            .sidebar { transform: translateX(-100%); } 
            .sidebar.open { transform: translateX(0); } 
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="flex h-screen">
        
        <!-- Navigation -->
        <?php renderNavigation(); ?>
        
        <!-- Main Content -->
        <main class="flex-1 overflow-auto">
    <div class="container mx-auto px-4 py-8">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <!-- Header Section -->
            <div class="border-b border-gray-200 p-6">
                <div class="flex justify-between items-center mb-4">
                    <h1 class="text-2xl font-bold text-gray-900">User Management</h1>
                    <div class="flex items-center gap-3">
                        <?php if ($user_can_edit): ?>
                            <button onclick="showCreateModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                <i data-lucide="plus" class="w-4 h-4 mr-1"></i>Add User
                            </button>
                            <a href="bulk_upload.php" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm">
                                <i data-lucide="upload" class="w-4 h-4 mr-1"></i>Bulk Upload
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

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
                        <a href="users.php" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm">Clear</a>
                    </div>
                </form>

                <!-- Bulk Actions -->
                <div id="bulkActionsContainer" class="hidden">
                    <div class="flex items-center gap-2 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                        <span id="selectedCount" class="text-sm text-blue-700 font-medium">0 users selected</span>
                        <div class="flex items-center gap-2 ml-auto">
                            <?php if ($user_can_send_emails): ?>
                                <button id="sendBulkEmailsBtn" onclick="sendBulkEmails()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                    <i data-lucide="mail" class="w-4 h-4 mr-1"></i>Send Setup Emails
                                </button>
                            <?php endif; ?>
                            <?php if ($user_is_super_admin): ?>
                                <button id="deleteBulkBtn" onclick="deleteSelected()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm">
                                    <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>Delete Selected
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Users Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white border border-gray-200 users-table">
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
                            <th class="p-3 text-left">Email/Mobile</th>
                            <th class="p-3 text-left sortable-header <?php echo (strpos($sort_by, 'title') !== false) ? 'active' : ''; ?>">
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'title', 'order' => (strpos($sort_by, 'title') !== false && $sort_order === 'ASC') ? 'DESC' : 'ASC'])); ?>" class="flex items-center">
                                    Title
                                    <i data-lucide="chevron-<?php echo (strpos($sort_by, 'title') !== false && $sort_order === 'ASC') ? 'up' : 'down'; ?>" class="sort-icon w-4 h-4"></i>
                                </a>
                            </th>
                            <th class="p-3 text-left">Type</th>
                            <th class="p-3 text-left">Role</th>
                            <th class="p-3 text-center">Status</th>
                            <th class="p-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="8" class="p-8 text-center text-gray-500">
                                    <i data-lucide="users" class="w-12 h-12 mx-auto mb-4 text-gray-300"></i>
                                    <p class="text-lg font-medium mb-2">No users found</p>
                                    <p class="text-sm">Try adjusting your filters or create a new user.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr class="border-t border-gray-200 hover:bg-gray-50">
                                    <?php if ($user_can_edit): ?>
                                        <td class="p-3">
                                            <input type="checkbox" class="user-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500" value="<?php echo $user['id']; ?>">
                                        </td>
                                    <?php endif; ?>
                                    <td class="p-3">
                                        <div class="text-gray-900 font-medium"><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></div>
                                    </td>
                            <td class="p-3 text-gray-700">
                                        <div><?php echo htmlspecialchars($user['email']); ?></div>
                                        <?php 
                                        // Extract mobile phone from JSON data in alt_phone column
                                        $mobile_phone = '';
                                        if (!empty($user['alt_phone'])) {
                                            $phone_data = json_decode($user['alt_phone'], true);
                                            if (is_array($phone_data) && isset($phone_data['mobile'])) {
                                                $mobile_phone = $phone_data['mobile'];
                                            } else {
                                                // Handle legacy data (non-JSON) - treat as mobile phone
                                                $mobile_phone = $user['alt_phone'];
                                            }
                                        }
                                        if (!empty($mobile_phone)): ?>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($mobile_phone); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3 text-gray-700"><?php echo htmlspecialchars($user['title'] ?: 'N/A'); ?></td>
                                    <td class="p-3 text-gray-700"><?php echo htmlspecialchars($user['type']); ?></td>
                                    <td class="p-3 text-gray-700"><?php echo getRoleName($user['roleId'], $roles); ?></td>
                                    <td class="p-3 text-center">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo isUserArchived($user) ? 'bg-gray-200 text-gray-800' : 'bg-green-100 text-green-800'; ?>">
                                            <?php echo isUserArchived($user) ? 'Archived' : 'Active'; ?>
                                        </span>
                                    </td>
                                    <td class="p-3 text-center align-middle">
                                        <div class="flex items-center justify-center gap-1 h-full">
                                            <a href="view_profile.php?id=<?php echo $user['id']; ?>" class="inline-flex items-center justify-center w-7 h-7 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-full transition-colors" title="View Profile">
                                                👁️
                                            </a>
                                            <?php if ($user_can_edit): ?>
                                                <button onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)" class="inline-flex items-center justify-center w-7 h-7 text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 rounded-full transition-colors" title="Edit User">
                                                    ✏️
                                                </button>
                                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure?')">
                                                    <input type="hidden" name="action" value="archive_user">
                                                    <input type="hidden" name="userId" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="inline-flex items-center justify-center w-7 h-7 text-<?php echo isUserArchived($user) ? 'green' : 'orange'; ?>-600 hover:text-<?php echo isUserArchived($user) ? 'green' : 'orange'; ?>-800 hover:bg-<?php echo isUserArchived($user) ? 'green' : 'orange'; ?>-50 rounded-full transition-colors" title="<?php echo isUserArchived($user) ? 'Unarchive' : 'Archive'; ?> User">
                                                        <?php echo isUserArchived($user) ? '✅' : '📦'; ?>
                                                    </button>
                                                </form>
                                                <form method="POST" class="inline" onsubmit="return confirm('Send setup email to this user?')">
                                                    <input type="hidden" name="action" value="send_setup_email">
                                                    <input type="hidden" name="userId" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="inline-flex items-center justify-center w-7 h-7 text-green-600 hover:text-green-800 hover:bg-green-50 rounded-full transition-colors" title="Send Setup Email">
                                                        📧
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="border-t border-gray-200 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-700">
                            Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $per_page, $total_users); ?> of <?php echo $total_users; ?> users
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="px-3 py-2 border <?php echo $i == $page ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-700 hover:bg-gray-50'; ?> rounded-lg text-sm">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </main>
    </div>

    <!-- Create User Modal -->
    <div id="createUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-screen overflow-y-auto">
                <div class="border-b border-gray-200 p-6">
                    <div class="flex justify-between items-center">
                        <h2 class="text-xl font-semibold text-gray-900">Add New User</h2>
                        <button onclick="hideCreateModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>
                </div>
                
                <form method="POST" class="p-6">
                    <input type="hidden" name="action" value="create_user">
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="firstName" class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                            <input type="text" name="firstName" id="firstName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="lastName" class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                            <input type="text" name="lastName" id="lastName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                            <input type="email" name="email" id="email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="employeeId" class="block text-sm font-medium text-gray-700 mb-1">Employee ID</label>
                            <input type="text" name="employeeId" id="employeeId" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Job Title</label>
                        <input type="text" name="title" id="title" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="mobile_phone" class="block text-sm font-medium text-gray-700 mb-1">Mobile Phone</label>
                            <input type="tel" name="mobile_phone_field" id="mobile_phone" placeholder="123-456-7890" maxlength="12" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent phone-input" inputmode="numeric">
                        </div>
                        <div>
                            <label for="alt_phone" class="block text-sm font-medium text-gray-700 mb-1">Alternate Phone</label>
                            <input type="tel" name="alt_phone" id="alt_phone" placeholder="123-456-7890" maxlength="12" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent phone-input" inputmode="numeric">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Name</label>
                            <input type="text" name="emergency_contact_name" id="emergency_contact_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Phone</label>
                            <input type="tel" name="emergency_contact_phone" id="emergency_contact_phone" placeholder="123-456-7890" maxlength="12" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent phone-input" inputmode="numeric">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-3 gap-4 mb-4">
                        <div>
                            <label for="roleId" class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                            <select name="roleId" id="roleId" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                            <select name="type" id="type" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Type</option>
                                <option value="Employee">Employee</option>
                                <option value="Subcontractor">Subcontractor</option>
                            </select>
                        </div>
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                            <input type="password" name="password" id="password" placeholder="Leave blank for default" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="hideCreateModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-screen overflow-y-auto">
                <div class="border-b border-gray-200 p-6">
                    <div class="flex justify-between items-center">
                        <h2 class="text-xl font-semibold text-gray-900">Edit User</h2>
                        <button onclick="hideEditModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>
                </div>
                
                <form method="POST" class="p-6">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="userId" id="editUserId">
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="editFirstName" class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                            <input type="text" name="firstName" id="editFirstName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="editLastName" class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                            <input type="text" name="lastName" id="editLastName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="editEmail" class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                            <input type="email" name="email" id="editEmail" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="editEmployeeId" class="block text-sm font-medium text-gray-700 mb-1">Employee ID</label>
                            <input type="text" name="employeeId" id="editEmployeeId" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="editTitle" class="block text-sm font-medium text-gray-700 mb-1">Job Title</label>
                        <input type="text" name="title" id="editTitle" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="editMobilePhone" class="block text-sm font-medium text-gray-700 mb-1">Mobile Phone</label>
                            <input type="tel" name="mobile_phone_field" id="editMobilePhone" placeholder="123-456-7890" maxlength="12" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent phone-input" inputmode="numeric">
                        </div>
                        <div>
                            <label for="editAltPhone" class="block text-sm font-medium text-gray-700 mb-1">Alternate Phone</label>
                            <input type="tel" name="alt_phone" id="editAltPhone" placeholder="123-456-7890" maxlength="12" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent phone-input" inputmode="numeric">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="editEmergencyContactName" class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Name</label>
                            <input type="text" name="emergency_contact_name" id="editEmergencyContactName" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="editEmergencyContactPhone" class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Phone</label>
                            <input type="tel" name="emergency_contact_phone" id="editEmergencyContactPhone" placeholder="123-456-7890" maxlength="12" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent phone-input" inputmode="numeric">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="editRoleId" class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                            <select name="roleId" id="editRoleId" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="editType" class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                            <select name="type" id="editType" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Type</option>
                                <option value="Employee">Employee</option>
                                <option value="Subcontractor">Subcontractor</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="hideEditModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast Message -->
    <?php if ($toastMessage): ?>
        <div id="toast" class="fixed top-4 right-4 bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg z-50">
            <?php echo htmlspecialchars($toastMessage); ?>
        </div>
    <?php endif; ?>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Phone number formatting function
        function formatPhoneNumber(value) {
            // Remove all non-digit characters
            const digits = value.replace(/\D/g, '');
            
            // Limit to 10 digits
            const limitedDigits = digits.substring(0, 10);
            
            // Format as ###-###-####
            if (limitedDigits.length >= 6) {
                return limitedDigits.substring(0, 3) + '-' + limitedDigits.substring(3, 6) + '-' + limitedDigits.substring(6);
            } else if (limitedDigits.length >= 3) {
                return limitedDigits.substring(0, 3) + '-' + limitedDigits.substring(3);
            } else {
                return limitedDigits;
            }
        }

        // Apply phone formatting to all phone inputs
        function setupPhoneFormatting() {
            const phoneInputs = document.querySelectorAll('.phone-input');
            
            phoneInputs.forEach(input => {
                // Remove existing event listeners to prevent duplicates
                input.removeEventListener('input', handlePhoneInput);
                input.removeEventListener('keypress', handlePhoneKeypress);
                
                // Add fresh event listeners
                input.addEventListener('input', handlePhoneInput);
                input.addEventListener('keypress', handlePhoneKeypress);
            });
        }

        function handlePhoneInput(e) {
            const formatted = formatPhoneNumber(e.target.value);
            e.target.value = formatted;
        }

        function handlePhoneKeypress(e) {
            // Allow backspace, delete, tab, escape, enter
            if ([8, 9, 27, 13, 46].indexOf(e.keyCode) !== -1 ||
                // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                (e.keyCode === 65 && e.ctrlKey === true) ||
                (e.keyCode === 67 && e.ctrlKey === true) ||
                (e.keyCode === 86 && e.ctrlKey === true) ||
                (e.keyCode === 88 && e.ctrlKey === true)) {
                return;
            }
            // Ensure that it is a number and stop the keypress
            if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                e.preventDefault();
            }
            
            // Don't allow more than 10 digits
            const digits = e.target.value.replace(/\D/g, '');
            if (digits.length >= 10) {
                e.preventDefault();
            }
        }

        // Initialize phone formatting on page load
        setupPhoneFormatting();

        // Toast message handling
        <?php if ($toastMessage): ?>
        setTimeout(() => {
            const toast = document.getElementById('toast');
            if (toast) {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }
        }, 3000);
        <?php endif; ?>

        // Checkbox handling
        const selectAllCheckbox = document.getElementById('selectAll');
        const userCheckboxes = document.querySelectorAll('.user-checkbox');
        const bulkActionsContainer = document.getElementById('bulkActionsContainer');
        const selectedCountSpan = document.getElementById('selectedCount');

        function updateBulkActions() {
            const checkedBoxes = document.querySelectorAll('.user-checkbox:checked');
            const count = checkedBoxes.length;
            
            if (count > 0) {
                bulkActionsContainer.classList.remove('hidden');
                selectedCountSpan.textContent = `${count} user${count !== 1 ? 's' : ''} selected`;
            } else {
                bulkActionsContainer.classList.add('hidden');
            }
        }

        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                userCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                updateBulkActions();
            });
        }

        userCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateBulkActions);
        });

        // Modal functions
        function showCreateModal() {
            document.getElementById('createUserModal').classList.remove('hidden');
            setTimeout(() => {
                setupPhoneFormatting();
            }, 100);
        }

        function hideCreateModal() {
            document.getElementById('createUserModal').classList.add('hidden');
        }

        function showEditModal() {
            document.getElementById('editUserModal').classList.remove('hidden');
            setTimeout(() => {
                setupPhoneFormatting();
            }, 100);
        }

        function hideEditModal() {
            document.getElementById('editUserModal').classList.add('hidden');
        }

        function editUser(user) {
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editFirstName').value = user.firstName;
            document.getElementById('editLastName').value = user.lastName;
            document.getElementById('editEmail').value = user.email;
            document.getElementById('editEmployeeId').value = user.employeeId || '';
            document.getElementById('editTitle').value = user.title || '';
            
            // Extract phone data from JSON stored in alt_phone column
            let mobilePhone = '';
            let altPhone = '';
            
            try {
                const phoneData = JSON.parse(user.alt_phone || '{}');
                mobilePhone = phoneData.mobile || '';
                altPhone = phoneData.alt || '';
            } catch (e) {
                // If not JSON, treat as legacy mobile phone data
                mobilePhone = user.alt_phone || '';
            }
            
            document.getElementById('editMobilePhone').value = mobilePhone ? formatPhoneNumber(mobilePhone) : '';
            document.getElementById('editAltPhone').value = altPhone ? formatPhoneNumber(altPhone) : '';
            document.getElementById('editEmergencyContactPhone').value = user.emergency_contact_phone ? formatPhoneNumber(user.emergency_contact_phone) : '';
            
            document.getElementById('editEmergencyContactName').value = user.emergency_contact_name || '';
            document.getElementById('editRoleId').value = user.roleId;
            document.getElementById('editType').value = user.type;
            showEditModal();
        }

        // Add form submission debugging
        document.addEventListener('DOMContentLoaded', function() {
            const editForm = document.querySelector('#editUserModal form');
            const createForm = document.querySelector('#createUserModal form');
            
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    const mobilePhone = document.getElementById('editMobilePhone').value;
                    const altPhone = document.getElementById('editAltPhone').value;
                    const emergencyPhone = document.getElementById('editEmergencyContactPhone').value;
                    
                    console.log('=== Edit Form Submission Debug ===');
                    console.log('Mobile Phone field ID:', document.getElementById('editMobilePhone').id);
                    console.log('Mobile Phone value:', mobilePhone);
                    console.log('Mobile Phone length:', mobilePhone.length);
                    console.log('Alt Phone value:', altPhone);
                    console.log('Emergency Phone value:', emergencyPhone);
                    console.log('Form action:', editForm.action);
                    console.log('Form method:', editForm.method);
                    
                    // Log all form data
                    const formData = new FormData(editForm);
                    console.log('All form data:');
                    for (let [key, value] of formData.entries()) {
                        console.log(`  ${key}: ${value}`);
                    }
                    
                    // Don't prevent submission, just log the values
                });
            }
            
            if (createForm) {
                createForm.addEventListener('submit', function(e) {
                    const mobilePhone = document.getElementById('mobile_phone').value;
                    const altPhone = document.getElementById('alt_phone').value;
                    const emergencyPhone = document.getElementById('emergency_contact_phone').value;
                    
                    console.log('=== Create Form Submission Debug ===');
                    console.log('Mobile Phone value:', mobilePhone);
                    console.log('Alt Phone value:', altPhone);
                    console.log('Emergency Phone value:', emergencyPhone);
                    
                    // Log all form data
                    const formData = new FormData(createForm);
                    console.log('All create form data:');
                    for (let [key, value] of formData.entries()) {
                        console.log(`  ${key}: ${value}`);
                    }
                    
                    // Don't prevent submission, just log the values
                });
            }
        });

        // Bulk actions
        function sendBulkEmails() {
            const checkedBoxes = document.querySelectorAll('.user-checkbox:checked');
            if (checkedBoxes.length === 0) {
                alert('Please select users first.');
                return;
            }

            if (confirm(`Send setup emails to ${checkedBoxes.length} selected user(s)?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="send_bulk_setup_emails">';
                
                checkedBoxes.forEach(checkbox => {
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
            const checkedBoxes = document.querySelectorAll('.user-checkbox:checked');
            if (checkedBoxes.length === 0) {
                alert('Please select users first.');
                return;
            }

            if (confirm(`Are you sure you want to delete ${checkedBoxes.length} selected user(s)? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete_selected">';
                
                checkedBoxes.forEach(checkbox => {
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

        // Mobile menu toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const menuButton = document.getElementById('menu-button');
            const sidebar = document.getElementById('sidebar');
            
            if (menuButton && sidebar) {
                menuButton.addEventListener('click', function() {
                    sidebar.classList.toggle('open');
                });
            }
        });

        // Auto-submit filters when changed
        document.querySelectorAll('#filtersForm select').forEach(select => {
            select.addEventListener('change', () => {
                document.getElementById('filtersForm').submit();
            });
        });

        // Search with delay
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filtersForm').submit();
            }, 500);
        });
    </script>
</body>
</html>