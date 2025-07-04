<?php
// usermgmt/index.php - User Management Page with proper navigation

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/auth.php';

// Make sure navigation is available - create fallback if needed
if (!function_exists('renderNavigation')) {
    function renderNavigation() {
        echo '<aside id="sidebar" class="sidebar absolute md:relative bg-gray-800 text-white w-64 h-full flex-shrink-0 z-20">
            <div class="p-4 flex items-center border-b border-gray-700">
                <img src="https://swfunk.com/wp-content/uploads/2020/04/Goal-Zero-1.png" alt="Logo" class="h-10 w-auto mr-3">
                <h1 class="text-xl font-bold">Safety Hub</h1>
            </div>
            
            <nav class="p-4">
                <a href="../../dashboard.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 text-gray-300 hover:bg-gray-700 hover:text-white">
                    <i data-lucide="layout-dashboard" class="w-5 h-5 mr-3"></i>
                    Dashboard
                </a>
                
                <a href="index.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 bg-gray-700 text-white">
                    <i data-lucide="users" class="w-5 h-5 mr-3"></i>
                    User Management
                </a>
                
                <a href="../../profile.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 text-gray-300 hover:bg-gray-700 hover:text-white">
                    <i data-lucide="user" class="w-5 h-5 mr-3"></i>
                    Profile
                </a>
                
                <a href="../../communication/index.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 text-gray-300 hover:bg-gray-700 hover:text-white">
                    <i data-lucide="message-circle" class="w-5 h-5 mr-3"></i>
                    Communications
                </a>
            </nav>
            
            <!-- User Info at Bottom -->
            <div class="absolute bottom-0 w-full border-t border-gray-700">
                <div class="p-4">
                    <div class="flex items-center mb-2">
                        <div class="w-8 h-8 bg-gray-600 rounded-full flex items-center justify-center mr-3">
                            <i data-lucide="user" class="w-4 h-4"></i>
                        </div>
                        <div>
                            <div class="text-sm font-medium">' . htmlspecialchars($_SESSION['user_first_name'] . ' ' . $_SESSION['user_last_name']) . '</div>
                        </div>
                    </div>
                    <a href="../../login.php" class="text-xs text-gray-400 hover:text-white">Sign Out</a>
                </div>
            </div>
        </aside>';
    }
}

// Check if user is logged in and has appropriate permissions
if (!isUserLoggedIn()) {
    header("Location: ../../login.php");
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
                // Set default password if none provided
                if (empty($password)) {
                    $password = 'password123';
                }
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("INSERT INTO users (firstName, lastName, email, password, employeeId, roleId, type, title, mobile_phone, alt_phone, emergency_contact_name, emergency_contact_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssissssss", $firstName, $lastName, $email, $password_hash, $employeeId, $roleId, $type, $title, $mobile_phone, $alt_phone, $emergency_contact_name, $emergency_contact_phone);
                
                if ($stmt->execute()) {
                    $_SESSION['toastMessage'] = "User created successfully.";
                } else {
                    $_SESSION['toastMessage'] = "Error creating user: " . $stmt->error;
                }
                $stmt->close();
            }
            $check_stmt->close();
        }
    }
    
    if ($post_action === 'send_setup_email' && $user_can_edit) {
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
    }
    
    if ($post_action === 'archive_user' && $user_can_edit) {
        $userId = (int)($_POST['userId'] ?? 0);
        if ($userId > 0) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $stmt = $conn->prepare("UPDATE users SET terminationDate = ? WHERE id = ?");
            $stmt->bind_param("si", $yesterday, $userId);
            $stmt->execute();
            $_SESSION['toastMessage'] = "User archived successfully.";
            $stmt->close();
        }
    }
    
    header("Location: index.php");
    exit();
}

// Filtering and sorting logic
$filter_type = $_GET['filter'] ?? 'all';
$sort_by = $_GET['sort'] ?? 'lastName';
$sort_order = $_GET['order'] ?? 'ASC';
$search_query = $_GET['search'] ?? '';

// Build WHERE clause for filtering
$where_conditions = [];
$params = [];
$param_types = "";

if ($filter_type === 'active') {
    $where_conditions[] = "(terminationDate IS NULL OR terminationDate >= CURDATE())";
} elseif ($filter_type === 'archived') {
    $where_conditions[] = "(terminationDate IS NOT NULL AND terminationDate < CURDATE())";
}

if (!empty($search_query)) {
    $where_conditions[] = "(firstName LIKE ? OR lastName LIKE ? OR email LIKE ? OR employeeId LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $param_types .= "ssss";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Valid sort columns
$valid_sorts = ['firstName', 'lastName', 'email', 'title', 'type', 'roleId'];
if (!in_array($sort_by, $valid_sorts)) {
    $sort_by = 'lastName';
}

$sort_order = ($sort_order === 'DESC') ? 'DESC' : 'ASC';

// Get users
$sql = "SELECT u.*, r.name as roleName FROM users u LEFT JOIN roles r ON u.roleId = r.id $where_clause ORDER BY $sort_by $sort_order";
$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

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
        
        <!-- Use the navigation system -->
        <?php renderNavigation(); ?>
        
        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <header class="flex justify-between items-center p-4 bg-white border-b">
                <div class="flex items-center">
                    <button id="menu-button" class="md:hidden text-gray-500 focus:outline-none mr-4">
                        <i data-lucide="menu" class="w-6 h-6"></i>
                    </button>
                    <h2 class="text-xl font-semibold text-gray-700">User Management</h2>
                </div>
                <div class="flex items-center">
                    <span class="text-sm text-gray-600 mr-4">Welcome, <?php echo htmlspecialchars($_SESSION['user_first_name']); ?>!</span>
                </div>
            </header>

            <!-- Content -->
            <div class="flex-1 p-4 md:p-6 overflow-y-auto">
                <div class="bg-white p-6 rounded-lg shadow-md">
                    
                    <!-- Filters and Actions -->
                    <div class="mb-6">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                            <!-- Search and Filter -->
                            <div class="flex flex-col md:flex-row gap-4">
                                <form method="GET" class="flex gap-4">
                                    <div class="relative">
                                        <input type="text" 
                                               name="search" 
                                               value="<?php echo htmlspecialchars($search_query); ?>" 
                                               placeholder="Search users..." 
                                               class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                        <i data-lucide="search" class="w-5 h-5 text-gray-400 absolute left-3 top-2.5"></i>
                                    </div>
                                    
                                    <select name="filter" 
                                            class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                        <option value="all" <?php echo ($filter_type === 'all') ? 'selected' : ''; ?>>All Users</option>
                                        <option value="active" <?php echo ($filter_type === 'active') ? 'selected' : ''; ?>>Active Only</option>
                                        <option value="archived" <?php echo ($filter_type === 'archived') ? 'selected' : ''; ?>>Archived Only</option>
                                    </select>
                                    
                                    <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm">
                                        Filter
                                    </button>
                                </form>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="flex flex-col md:flex-row gap-2">
                                <button onclick="openModal('createUserModal')" 
                                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                    <i data-lucide="plus" class="w-4 h-4 mr-1"></i>Add User
                                </button>
                                
                                <a href="bulk_upload.php" 
                                   class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm text-center">
                                    <i data-lucide="upload" class="w-4 h-4 mr-1"></i>Bulk Upload
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Users Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white border border-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="p-3 text-left">
                                        <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    </th>
                                    <th class="p-3 text-left sortable-header">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'lastName', 'order' => ($sort_by === 'lastName' && $sort_order === 'ASC') ? 'DESC' : 'ASC'])); ?>" class="flex items-center">
                                            Name
                                            <i data-lucide="chevron-<?php echo ($sort_by === 'lastName' && $sort_order === 'ASC') ? 'up' : 'down'; ?>" class="sort-icon w-4 h-4"></i>
                                        </a>
                                    </th>
                                    <th class="p-3 text-left">Email</th>
                                    <th class="p-3 text-left">Title</th>
                                    <th class="p-3 text-left">Type</th>
                                    <th class="p-3 text-left">Role</th>
                                    <th class="p-3 text-left">Status</th>
                                    <th class="p-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="8" class="p-8 text-center text-gray-500">
                                            No users found matching your criteria.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="p-3">
                                                <input type="checkbox" name="userIds[]" value="<?php echo $user['id']; ?>" class="user-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            </td>
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
                                            <td class="p-3 text-right">
                                                <div class="flex justify-end space-x-2">
                                                    <a href="view_profile.php?id=<?php echo $user['id']; ?>" 
                                                       class="text-blue-600 hover:text-blue-800 text-sm">
                                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                                    </a>
                                                    <button onclick="editUser(<?php echo $user['id']; ?>)" 
                                                            class="text-green-600 hover:text-green-800 text-sm">
                                                        <i data-lucide="edit" class="w-4 h-4"></i>
                                                    </button>
                                                    <button onclick="sendSetupEmail(<?php echo $user['id']; ?>)" 
                                                            class="text-purple-600 hover:text-purple-800 text-sm">
                                                        <i data-lucide="mail" class="w-4 h-4"></i>
                                                    </button>
                                                    <button onclick="archiveUser(<?php echo $user['id']; ?>)" 
                                                            class="text-red-600 hover:text-red-800 text-sm">
                                                        <i data-lucide="archive" class="w-4 h-4"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Create User Modal -->
    <div class="modal-backdrop" id="createUserModal-backdrop"></div>
    <div class="modal bg-white rounded-lg shadow-lg w-full max-w-2xl p-6" id="createUserModal">
        <h3 class="text-lg font-semibold mb-4">Create New User</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_user">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                    <input type="text" name="firstName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                    <input type="text" name="lastName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                    <input type="email" name="email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Employee ID</label>
                    <input type="text" name="employeeId" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                    <select name="roleId" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">Select Role...</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="Employee">Employee</option>
                        <option value="Subcontractor">Subcontractor</option>
                    </select>
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Job Title</label>
                    <input type="text" name="title" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" onclick="closeModal('createUserModal')" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Create User
                </button>
            </div>
        </form>
    </div>

    <!-- Toast Notification -->
    <?php if ($toastMessage): ?>
        <div id="toast" class="fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50">
            <?php echo htmlspecialchars($toastMessage); ?>
        </div>
    <?php endif; ?>

    <!-- JavaScript -->
    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Mobile menu toggle
        document.getElementById('menu-button').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        });

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.getElementById(modalId + '-backdrop').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.getElementById(modalId + '-backdrop').classList.remove('active');
        }

        // Close modal when clicking backdrop
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
            backdrop.addEventListener('click', function() {
                const modalId = this.id.replace('-backdrop', '');
                closeModal(modalId);
            });
        });

        // Toast notification
        <?php if ($toastMessage): ?>
            setTimeout(() => {
                const toast = document.getElementById('toast');
                if (toast) {
                    toast.style.display = 'none';
                }
            }, 5000);
        <?php endif; ?>

        // Select all checkbox
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // User actions
        function editUser(userId) {
            // Implementation for edit user
            console.log('Edit user:', userId);
        }

        function sendSetupEmail(userId) {
            if (confirm('Send setup email to this user?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'send_setup_email';
                
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

        function archiveUser(userId) {
            if (confirm('Are you sure you want to archive this user?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'archive_user';
                
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
    </script>
</body>
</html>