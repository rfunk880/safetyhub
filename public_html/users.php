<?php
// users.php
// This page displays a list of all users and provides tools for management.

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/auth.php';

// --- START OF PAGE LOGIC ---

// Determine if the logged-in user has editing permissions.
$user_can_edit = in_array($_SESSION['user_role_id'], [1, 2, 3]); 
$user_is_super_admin = ($_SESSION['user_role_id'] == 1);

// Helper function to create sortable table headers.
function createSortableHeader($label, $column, $current_sort, $current_order) {
    $order = ($current_sort == $column && $current_order == 'ASC') ? 'DESC' : 'ASC';
    $isActive = $current_sort == $column;
    $icon = $current_order == 'ASC' ? 'arrow-up' : 'arrow-down';
    $url_params = http_build_query(array_merge($_GET, ['sort' => $column, 'order' => $order]));
    echo "<th class='p-3 font-medium text-gray-600 sortable-header " . ($isActive ? 'active' : '') . "'><a href='?" . $url_params . "'>" . $label;
    if ($isActive) {
        echo "<i data-lucide='" . $icon . "' class='sort-icon w-4 h-4'></i>";
    }
    echo "</a></th>";
}

// --- Handle POST Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'save_user' && $user_can_edit) {
        $userId = $_POST['userId'];
        $roleId = (int)$_POST['userRole'];
        if (!$user_is_super_admin && $roleId == 1) {
            if (!empty($userId)) {
                $user_role_query = $conn->prepare("SELECT roleId FROM users WHERE id = ?");
                $user_role_query->bind_param("i", $userId);
                $user_role_query->execute();
                $roleId = $user_role_query->get_result()->fetch_assoc()['roleId'];
                $user_role_query->close();
            } else { $roleId = 5; }
        }
        if ($user_is_super_admin && $userId == $_SESSION['user_id'] && $roleId != 1) {
            $roleId = 1; 
            $_SESSION['toastMessage'] = 'Error: Super Admins cannot change their own role.';
        }
        $firstName = $conn->real_escape_string($_POST['firstName']);
        $lastName = $conn->real_escape_string($_POST['lastName']);
        $email = $conn->real_escape_string($_POST['email']);
        $employeeId = $conn->real_escape_string($_POST['employeeId']);
        $title = $conn->real_escape_string($_POST['title']);
        $mobile_phone = $conn->real_escape_string($_POST['mobile_phone']);
        $alt_phone = $conn->real_escape_string($_POST['alt_phone']);
        $emergency_contact_name = $conn->real_escape_string($_POST['emergency_contact_name']);
        $emergency_contact_phone = $conn->real_escape_string($_POST['emergency_contact_phone']);
        $type = $conn->real_escape_string($_POST['userType']);
        $terminationDate = empty($_POST['terminationDate']) ? null : $conn->real_escape_string($_POST['terminationDate']);

        if (empty($userId)) {
            $stmt = $conn->prepare("INSERT INTO users (firstName, lastName, email, password, employeeId, roleId, title, mobile_phone, alt_phone, emergency_contact_name, emergency_contact_phone, type, terminationDate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $defaultPassword = password_hash('password123', PASSWORD_DEFAULT);
            $stmt->bind_param("sssssisssssss", $firstName, $lastName, $email, $defaultPassword, $employeeId, $roleId, $title, $mobile_phone, $alt_phone, $emergency_contact_name, $emergency_contact_phone, $type, $terminationDate);
            if ($stmt->execute()) { $_SESSION['toastMessage'] = 'User added successfully!'; }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("UPDATE users SET firstName=?, lastName=?, email=?, employeeId=?, roleId=?, title=?, mobile_phone=?, alt_phone=?, emergency_contact_name=?, emergency_contact_phone=?, type=?, terminationDate=? WHERE id=?");
            $stmt->bind_param("ssssisssssssi", $firstName, $lastName, $email, $employeeId, $roleId, $title, $mobile_phone, $alt_phone, $emergency_contact_name, $emergency_contact_phone, $type, $terminationDate, $userId);
            if ($stmt->execute() && !isset($_SESSION['toastMessage'])) { $_SESSION['toastMessage'] = 'User updated successfully!';}
            $stmt->close();
        }
    } elseif ($post_action === 'archive_user' && $user_can_edit) {
        $userId = (int)$_POST['userId'];
        $user_query = $conn->prepare("SELECT firstName, terminationDate FROM users WHERE id = ?");
        $user_query->bind_param("i", $userId);
        $user_query->execute();
        $user_result = $user_query->get_result()->fetch_assoc();
        if ($user_result) {
            $isArchived = isUserArchived($user_result);
            $newDate = $isArchived ? null : (new DateTime('yesterday'))->format('Y-m-d');
            $_SESSION['toastMessage'] = $isArchived ? "{$user_result['firstName']} has been un-archived." : "{$user_result['firstName']} has been archived.";
            $stmt = $conn->prepare("UPDATE users SET terminationDate=? WHERE id=?");
            $stmt->bind_param("si", $newDate, $userId);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($post_action === 'delete_selected' && $user_is_super_admin) {
        $userIds = $_POST['userIds'] ?? [];
        if (!empty($userIds)) {
            $sanitizedIds = array_map('intval', $userIds);
            $sanitizedIds = array_diff($sanitizedIds, [$_SESSION['user_id']]);
            
            if(!empty($sanitizedIds)) {
                // Clean up profile pictures before deleting users
                cleanupUserProfilePictures($conn, $sanitizedIds);
                
                // Delete users from database
                $placeholders = implode(',', array_fill(0, count($sanitizedIds), '?'));
                $types = str_repeat('i', count($sanitizedIds));
                $stmt = $conn->prepare("DELETE FROM users WHERE id IN ($placeholders)");
                $stmt->bind_param($types, ...$sanitizedIds);
                $stmt->execute();
                $_SESSION['toastMessage'] = "Successfully deleted {$stmt->affected_rows} user(s) and cleaned up their profile pictures.";
                $stmt->close();
            } else {
                 $_SESSION['toastMessage'] = "No users were deleted (you cannot delete yourself).";
            }
        }
    } elseif ($post_action === 'send_setup_email' && $user_can_edit) {
        $userId = (int)$_POST['userId'];
        $user_query = $conn->prepare("SELECT email FROM users WHERE id = ?");
        $user_query->bind_param("i", $userId);
        $user_query->execute();
        $user = $user_query->get_result()->fetch_assoc();
        if ($user && $user['email']) {
            $token = bin2hex(random_bytes(50));
            $stmt_token = $conn->prepare("INSERT INTO password_resets (email, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = ?, created_at = NOW()");
            $stmt_token->bind_param("sss", $user['email'], $token, $token);
            $stmt_token->execute();
            if(function_exists('sendSetupEmail') && sendSetupEmail($user['email'], $token)) {
                $_SESSION['toastMessage'] = "Setup email sent to {$user['email']}.";
            } else {
                $_SESSION['toastMessage'] = "Error sending email to {$user['email']}.";
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
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
    exit();
}

// --- Define filters, sorting, and pagination parameters from URL ---
$search_query = trim($_GET['search'] ?? '');
$role_filter = (int)($_GET['role'] ?? 0);
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? 'active';
$per_page = (int)($_GET['per_page'] ?? 25);
if (!in_array($per_page, [25, 50, 100])) $per_page = 25;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$valid_sort_columns = ['lastName', 'title'];
$sort_by = in_array($_GET['sort'] ?? '', $valid_sort_columns) ? $_GET['sort'] : 'lastName';
$sort_order = in_array(strtoupper($_GET['order'] ?? ''), ['ASC', 'DESC']) ? strtoupper($_GET['order']) : 'ASC';
$where_clauses = []; $filter_params = []; $filter_types = '';

$where_clauses[] = "id != ?";
$filter_params[] = $_SESSION['user_id'];
$filter_types .= 'i';

if ($search_query !== '') {
    $where_clauses[] = "(firstName LIKE ? OR lastName LIKE ? OR email LIKE ?)";
    $search_param = "%{$search_query}%";
    array_push($filter_params, $search_param, $search_param, $search_param);
    $filter_types .= 'sss';
}
if ($role_filter > 0) { $where_clauses[] = "roleId = ?"; $filter_params[] = $role_filter; $filter_types .= 'i';}
if ($type_filter !== '') { $where_clauses[] = "type = ?"; $filter_params[] = $type_filter; $filter_types .= 's';}
if ($status_filter === 'archived') { $where_clauses[] = "terminationDate IS NOT NULL AND terminationDate < CURDATE()"; } 
else { $where_clauses[] = "(terminationDate IS NULL OR terminationDate >= CURDATE())"; }
$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";
$count_sql = "SELECT COUNT(id) as total FROM users" . $where_sql;
$stmt_count = $conn->prepare($count_sql);
if (!empty($filter_params)) { $stmt_count->bind_param($filter_types, ...$filter_params); }
$stmt_count->execute();
$total_users = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_users / $per_page);
$stmt_count->close();
$offset = ($page - 1) * $per_page;
$sql = "SELECT id, firstName, lastName, email, employeeId, roleId, title, mobile_phone, alt_phone, emergency_contact_name, emergency_contact_phone, terminationDate, type, profilePicture FROM users" . $where_sql . " ORDER BY {$sort_by} {$sort_order} LIMIT ? OFFSET ?";
$main_params = $filter_params; array_push($main_params, $per_page, $offset); $main_types = $filter_types . 'ii';
$stmt = $conn->prepare($sql);
if(!empty($main_types)){ $stmt->bind_param($main_types, ...$main_params); }
$stmt->execute();
$result = $stmt->get_result();
$users = [];
if ($result) { while($row = $result->fetch_assoc()) { $users[$row['id']] = $row; } }
$stmt->close();

$roles = [];
$result_roles = $conn->query("SELECT id, name FROM roles ORDER BY id"); 
if ($result_roles) { while($row = $result_roles->fetch_assoc()) { $roles[] = $row; } }
$loggedInUserRoleName = getRoleName($_SESSION['user_role_id'], $conn->query("SELECT id, name FROM roles")->fetch_all(MYSQLI_ASSOC));
$stmt = $conn->prepare("SELECT profilePicture FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$loggedInUser = $stmt->get_result()->fetch_assoc();
$stmt->close();
$loggedInUserProfilePicture = $loggedInUser ? $loggedInUser['profilePicture'] : null;
$toastMessage = $_SESSION['toastMessage'] ?? ''; unset($_SESSION['toastMessage']);
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
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .sidebar.open { transform: translateX(0); } }
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
        <!-- Sidebar -->
        <aside id="sidebar" class="sidebar absolute md:relative bg-gray-800 text-white w-64 h-full flex-shrink-0 z-20">
            <div class="p-4 flex items-center">
                <img src="https://swfunk.com/wp-content/uploads/2020/04/Goal-Zero-1.png" alt="Logo" class="h-10 w-auto mr-3">
                <h1 class="text-2xl font-bold text-white">Safety Hub</h1>
            </div>
            <nav class="mt-8">
                <a href="users.php" class="flex items-center mt-4 py-2 px-6 bg-gray-700 text-gray-100"><i data-lucide="users" class="w-5 h-5"></i><span class="mx-3">User Management</span></a>
            </nav>
            <div class="absolute bottom-0 w-full">
                 <div class="p-4 border-t border-gray-700">
                    <a href="profile.php" class="flex items-center group">
                        <?php if (!empty($loggedInUserProfilePicture) && getProfilePicturePath($loggedInUserProfilePicture)): ?>
                            <img src="serve_image.php?file=<?php echo urlencode($loggedInUserProfilePicture); ?>" alt="Profile" class="w-10 h-10 rounded-full object-cover mr-3 group-hover:ring-2 ring-blue-400">
                        <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-gray-600 flex items-center justify-center mr-3 group-hover:ring-2 ring-blue-400">
                                <i data-lucide="user" class="w-6 h-6 text-gray-400"></i>
                            </div>
                        <?php endif; ?>
                        <div>
                            <p class="text-lg font-semibold group-hover:text-blue-300"><?php echo htmlspecialchars($_SESSION['user_first_name'] . ' ' . $_SESSION['user_last_name']); ?></p>
                            <p class="text-sm text-gray-400"><?php echo htmlspecialchars($loggedInUserRoleName); ?></p>
                        </div>
                    </a>
                 </div>
                <a href="logout.php" class="flex items-center py-4 px-6 text-gray-400 hover:bg-gray-700 hover:text-gray-100"><i data-lucide="log-out" class="w-5 h-5"></i><span class="mx-3">Logout</span></a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <header class="flex justify-between items-center p-4 bg-white border-b">
                 <button id="menu-button" class="md:hidden text-gray-500 focus:outline-none"><i data-lucide="menu" class="w-6 h-6"></i></button>
                <h2 class="text-xl font-semibold text-gray-700">User Management</h2>
                <div class="flex items-center"><span class="text-sm text-gray-600 mr-4">Welcome, <?php echo htmlspecialchars($_SESSION['user_first_name']); ?>!</span></div>
            </header>

            <div class="flex-1 p-4 md:p-6 overflow-y-auto">
                <div class="bg-white p-6 rounded-lg shadow-md">
                    <form id="filtersForm" method="GET" action="users.php" class="flex flex-col md:flex-row justify-between items-center mb-4">
                        <div class="flex flex-wrap items-center gap-2 mb-4 md:mb-0">
                            <div class="relative"><input type="text" id="searchInput" name="search" class="pl-10 pr-4 py-2 border rounded-lg w-full sm:w-auto" placeholder="Search..." value="<?php echo htmlspecialchars($search_query); ?>"><i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400"></i></div>
                            <select name="role" class="border rounded-lg py-2 px-3"><option value="">All Roles</option><?php foreach ($roles as $role): ?><option value="<?php echo $role['id']; ?>" <?php if ($role_filter == $role['id']) echo 'selected'; ?>><?php echo htmlspecialchars($role['name']); ?></option><?php endforeach; ?></select>
                            <select name="type" class="border rounded-lg py-2 px-3"><option value="">All Types</option><option value="Employee" <?php if ($type_filter == 'Employee') echo 'selected'; ?>>Employee</option><option value="Subcontractor" <?php if ($type_filter == 'Subcontractor') echo 'selected'; ?>>Subcontractor</option></select>
                            <select name="status" class="border rounded-lg py-2 px-3"><option value="active" <?php if ($status_filter == 'active') echo 'selected'; ?>>Active Users</option><option value="archived" <?php if ($status_filter == 'archived') echo 'selected'; ?>>Archived Users</option></select>
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">Filter</button>
                        </div>
                        <div class="flex items-center space-x-2">
                            <?php if ($user_can_edit): ?>
                                <?php if ($user_is_super_admin): ?>
                                    <a href="bulk_upload.php" class="flex items-center bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                                        <i data-lucide="upload" class="w-5 h-5 mr-2"></i> Bulk Import
                                    </a>
                                <?php endif; ?>
                                <button id="addUserBtn" type="button" class="flex items-center bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700"><i data-lucide="plus" class="w-5 h-5 mr-2"></i> Add User</button>
                            <?php endif; ?>
                        </div>
                    </form>

                    <form id="bulkActionForm" method="POST" action="">
                        <input type="hidden" name="action" id="bulkActionInput" value="">
                        <div class="mb-2 flex items-center space-x-2">
                            <button id="sendSelectedBtn" type="button" style="display: none;" class="flex items-center bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700"><i data-lucide="mail" class="w-5 h-5 mr-2"></i> Send Setup Email</button>
                            <?php if ($user_is_super_admin): ?><button id="deleteSelectedBtn" type="button" style="display: none;" class="flex items-center bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700"><i data-lucide="trash-2" class="w-5 h-5 mr-2"></i> Delete Selected</button><?php endif; ?>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead><tr class="bg-gray-50 border-b"><th class="p-3 w-4"><input type="checkbox" id="selectAllCheckbox"></th><?php createSortableHeader('Name', 'lastName', $sort_by, $sort_order); ?><th class="p-3 font-medium text-gray-600">Mobile Phone</th><?php createSortableHeader('Title', 'title', $sort_by, $sort_order); ?><th class="p-3 font-medium text-gray-600">Type</th><th class="p-3 font-medium text-gray-600">Role</th><th class="p-3 font-medium text-gray-600">Status</th><?php if ($user_can_edit): ?><th class="p-3 font-medium text-gray-600 text-right">Actions</th><?php endif; ?></tr></thead>
                                <tbody>
                                    <?php if (empty($users)): ?>
                                        <tr><td colspan="8" class="text-center py-8 text-gray-500"><i data-lucide="user-x" class="w-16 h-16 mx-auto mb-4 text-gray-300"></i><p class="text-lg">No users found.</p></td></tr>
                                    <?php else: foreach ($users as $user): ?>
                                        <tr class="border-b hover:bg-gray-50">
                                            <td class="p-3"><input type="checkbox" class="user-checkbox" name="userIds[]" value="<?php echo $user['id']; ?>"></td>
                                            <td class="p-3"><div class="font-medium text-gray-900"><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></div><div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div></td>
                                            <td class="p-3 text-gray-700"><?php echo htmlspecialchars($user['mobile_phone'] ?: 'N/A'); ?></td>
                                            <td class="p-3 text-gray-700"><?php echo htmlspecialchars($user['title'] ?: 'N/A'); ?></td>
                                            <td class="p-3 text-gray-700"><?php echo htmlspecialchars($user['type']); ?></td>
                                            <td class="p-3 text-gray-700"><?php echo getRoleName($user['roleId'], $roles); ?></td>
                                            <td class="p-3"><span class="px-2 py-1 text-xs font-medium rounded-full <?php echo isUserArchived($user) ? 'bg-gray-200 text-gray-800' : 'bg-green-100 text-green-800'; ?>"><?php echo isUserArchived($user) ? 'Archived' : 'Active'; ?></span></td>
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
                                                            title="<?php echo isUserArchived($user) ? 'Un-archive User' : 'Archive User'; ?>" 
                                                            class="p-2 text-gray-500 hover:text-red-600 hover:bg-red-100 rounded-full transition-colors">
                                                        <i data-lucide="<?php echo isUserArchived($user) ? 'user-check' : 'user-x'; ?>" class="w-5 h-5"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                    <div class="flex items-center justify-between mt-4">
                        <div class="flex items-center gap-x-6">
                            <form method="GET" action="users.php" class="flex items-center">
                                <span class="text-sm text-gray-700 mr-2">Show</span>
                                <select name="per_page" onchange="this.form.submit()" class="border rounded-lg py-1 px-2">
                                    <option value="25" <?php if ($per_page == 25) echo 'selected'; ?>>25</option>
                                    <option value="50" <?php if ($per_page == 50) echo 'selected'; ?>>50</option>
                                    <option value="100" <?php if ($per_page == 100) echo 'selected'; ?>>100</option>
                                </select>
                                <?php foreach ($_GET as $key => $value) { 
                                    if ($key != 'per_page' && $key != 'page') { 
                                        echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">'; 
                                    } 
                                } ?>
                            </form>
                            <div class="flex items-center space-x-1">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-1 border rounded-lg hover:bg-gray-100 text-sm">Previous</a>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="px-3 py-1 border rounded-lg text-sm <?php echo $i == $page ? 'bg-blue-600 text-white' : 'hover:bg-gray-100'; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="px-3 py-1 border rounded-lg hover:bg-gray-100 text-sm">Next</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-sm text-gray-700">Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $per_page, $total_users); ?> of <?php echo $total_users; ?> results</div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Add/Edit User Modal -->
    <div id="userModalBackdrop" class="modal-backdrop"></div>
    <div id="userModal" class="modal bg-white rounded-lg shadow-xl w-full max-w-2xl">
        <form id="userForm" method="POST" action="">
            <input type="hidden" name="action" value="save_user">
            <input type="hidden" id="userId" name="userId">
            <div class="p-6 border-b">
                <h3 id="modalTitle" class="text-2xl font-semibold">Add New User</h3>
            </div>
            <div class="p-6 space-y-4">
                <h4 class="text-lg font-semibold text-gray-800 border-b pb-2">Basic Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="firstName" class="block text-sm font-medium text-gray-700">First Name</label>
                        <input type="text" id="firstName" name="firstName" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label for="lastName" class="block text-sm font-medium text-gray-700">Last Name</label>
                        <input type="text" id="lastName" name="lastName" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" required>
                    </div>
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                    <input type="email" id="email" name="email" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" required>
                </div>
                <h4 class="text-lg font-semibold text-gray-800 border-b pb-2 pt-4">Company Details</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="employeeId" class="block text-sm font-medium text-gray-700">Employee ID</label>
                        <input type="text" id="employeeId" name="employeeId" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700">Title</label>
                        <input type="text" id="title" name="title" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label for="userRole" class="block text-sm font-medium text-gray-700">Role</label>
                        <select id="userRole" name="userRole" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" required>
                            <?php foreach ($roles as $role): 
                                if (!$user_is_super_admin && $role['id'] == 1) continue; ?>
                                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="userType" class="block text-sm font-medium text-gray-700">User Type</label>
                        <select id="userType" name="userType" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" required>
                            <option value="Employee">Employee</option>
                            <option value="Subcontractor">Subcontractor</option>
                        </select>
                    </div>
                </div>
                <h4 class="text-lg font-semibold text-gray-800 border-b pb-2 pt-4">Contact Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="mobile_phone" class="block text-sm font-medium text-gray-700">Mobile Phone</label>
                        <input type="tel" id="mobile_phone" name="mobile_phone" maxlength="12" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" placeholder="555-555-1234" pattern="\d{3}-\d{3}-\d{4}" title="Please enter a 10-digit phone number (e.g., 555-555-1234)" required>
                    </div>
                    <div>
                        <label for="alt_phone" class="block text-sm font-medium text-gray-700">Alternate Phone</label>
                        <input type="tel" id="alt_phone" name="alt_phone" maxlength="12" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" placeholder="555-555-1234" pattern="\d{3}-\d{3}-\d{4}" title="Please enter a 10-digit phone number (e.g., 555-555-1234)">
                    </div>
                </div>
                <h4 class="text-lg font-semibold text-gray-800 border-b pb-2 pt-4">Emergency Contact</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700">Full Name</label>
                        <input type="text" id="emergency_contact_name" name="emergency_contact_name" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                        <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" maxlength="12" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500" placeholder="555-555-1234" pattern="\d{3}-\d{3}-\d{4}" title="Please enter a 10-digit phone number (e.g., 555-555-1234)">
                    </div>
                </div>
                <h4 class="text-lg font-semibold text-gray-800 border-b pb-2 pt-4">Status</h4>
                <div>
                    <label for="terminationDate" class="block text-sm font-medium text-gray-700">Termination Date (optional)</label>
                    <input type="date" id="terminationDate" name="terminationDate" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500">
                </div>
            </div>
            <div class="pt-4 p-6 bg-gray-50 flex justify-end space-x-2">
                <button type="button" id="cancelBtn" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Save User</button>
            </div>
        </form>
    </div>
    <form id="singleActionForm" method="POST" action="">
        <input type="hidden" id="singleActionType" name="action">
        <input type="hidden" id="singleActionUserId" name="userId">
    </form>
    
    <!-- Toast Notification -->
    <?php if ($toastMessage): ?>
    <div id="toast" class="fixed top-4 right-4 z-50 bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg">
        <?php echo htmlspecialchars($toastMessage); ?>
    </div>
    <script>
        setTimeout(() => {
            const toast = document.getElementById('toast');
            if (toast) {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }
        }, 3000);
    </script>
    <?php endif; ?>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
        lucide.createIcons();
        const usersData = <?php echo json_encode(array_values($users)); ?>;
        const userModal = document.getElementById('userModal');
        const userModalBackdrop = document.getElementById('userModalBackdrop');
        const userForm = document.getElementById('userForm');
        const modalTitle = document.getElementById('modalTitle');
        const cancelBtn = document.getElementById('cancelBtn');
        const addUserBtn = document.getElementById('addUserBtn');
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        const userCheckboxes = document.querySelectorAll('.user-checkbox');
        const bulkActionForm = document.getElementById('bulkActionForm');
        const bulkActionInput = document.getElementById('bulkActionInput');
        const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
        const sendSelectedBtn = document.getElementById('sendSelectedBtn');
        
        if (selectAllCheckbox) {
            const toggleBulkButtons = () => {
                const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
                const show = checkedCount > 0;
                if (deleteSelectedBtn) deleteSelectedBtn.style.display = show ? 'flex' : 'none';
                if (sendSelectedBtn) sendSelectedBtn.style.display = show ? 'flex' : 'none';
            };
            selectAllCheckbox.addEventListener('change', (e) => {
                userCheckboxes.forEach(checkbox => { checkbox.checked = e.target.checked; });
                toggleBulkButtons();
            });
            userCheckboxes.forEach(checkbox => { checkbox.addEventListener('change', toggleBulkButtons); });
            if (deleteSelectedBtn) {
                deleteSelectedBtn.addEventListener('click', () => {
                    const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
                    if(confirm(`Are you sure you want to permanently delete ${checkedCount} user(s)? This action cannot be undone.`)) {
                        bulkActionInput.value = 'delete_selected';
                        bulkActionForm.submit();
                    }
                });
            }
            if (sendSelectedBtn) {
                sendSelectedBtn.addEventListener('click', () => {
                    const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
                     if(confirm(`Are you sure you want to send setup emails to ${checkedCount} user(s)?`)) {
                        bulkActionInput.value = 'send_setup_emails';
                        bulkActionForm.submit();
                    }
                });
            }
        }
        
        const formatPhoneNumber = (e) => {
            let input = e.target.value.replace(/\D/g, '').substring(0, 10);
            const size = input.length;
            if (size > 6) { input = `${input.substring(0, 3)}-${input.substring(3, 6)}-${input.substring(6, 10)}`; }
            else if (size > 3) { input = `${input.substring(0, 3)}-${input.substring(3, 6)}`;}
            e.target.value = input;
        };
        document.getElementById('mobile_phone').addEventListener('input', formatPhoneNumber);
        document.getElementById('alt_phone').addEventListener('input', formatPhoneNumber);
        document.getElementById('emergency_contact_phone').addEventListener('input', formatPhoneNumber);
        
        window.openModal = (userId = null) => {
            userForm.reset();
            if (userId) {
                const user = usersData.find(u => u.id == userId);
                if (user) {
                    modalTitle.textContent = 'Edit User';
                    document.getElementById('userId').value = user.id;
                    document.getElementById('firstName').value = user.firstName;
                    document.getElementById('lastName').value = user.lastName;
                    document.getElementById('email').value = user.email;
                    document.getElementById('employeeId').value = user.employeeId;
                    document.getElementById('userRole').value = user.roleId;
                    document.getElementById('title').value = user.title;
                    document.getElementById('mobile_phone').value = user.mobile_phone;
                    document.getElementById('alt_phone').value = user.alt_phone;
                    document.getElementById('emergency_contact_name').value = user.emergency_contact_name;
                    document.getElementById('emergency_contact_phone').value = user.emergency_contact_phone;
                    document.getElementById('userType').value = user.type;
                    document.getElementById('terminationDate').value = user.terminationDate;
                }
            } else {
                modalTitle.textContent = 'Add New User';
                document.getElementById('userId').value = '';
            }
            userModal.classList.add('active');
            userModalBackdrop.classList.add('active');
        };
        
        window.closeModal = () => {
            userModal.classList.remove('active');
            userModalBackdrop.classList.remove('active');
        };
        
        window.handleAction = (action, userId) => {
            if (action === 'send_setup_email') {
                if (!confirm('Are you sure you want to generate and send a new setup/reset link for this user?')) { return; }
            }
            const form = document.getElementById('singleActionForm');
            document.getElementById('singleActionType').value = action;
            document.getElementById('singleActionUserId').value = userId;
            form.submit();
        };
        
        if (addUserBtn) { addUserBtn.addEventListener('click', () => openModal()); }
        cancelBtn.addEventListener('click', closeModal);
        userModalBackdrop.addEventListener('click', closeModal);
        document.getElementById('menu-button').addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('open');
        });
    });
    </script>
</body>
</html>