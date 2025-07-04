<?php
// /includes/navigation.php
// Shared navigation structure for SafetyHub
// This file is automatically included by config.php - no need to manually include

// Only render navigation if we're in a web page context (not CLI or API)
if (!defined('SKIP_NAVIGATION') && isset($_SESSION['user_id'])) {
    
    // Get current user information for sidebar display
    if (!isset($loggedInUser)) {
        $stmt = $conn->prepare("SELECT u.*, r.name as roleName FROM users u LEFT JOIN roles r ON u.roleId = r.id WHERE u.id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $loggedInUser = $result->fetch_assoc();
        $stmt->close();
    }

    // Set navigation variables
    $loggedInUserProfilePicture = $loggedInUser['profilePicture'] ?? null;
    $loggedInUserRoleName = $loggedInUser['roleName'] ?? 'Unknown';
    $loggedInUserName = ($loggedInUser['firstName'] ?? '') . ' ' . ($loggedInUser['lastName'] ?? '');

    // Permission checks for menu items
    $admin_roles = [1, 2, 3]; // Super Admin, Admin, Manager
    $can_see_user_management = in_array($_SESSION['user_role_id'], $admin_roles);

    // Communication module permission - Direct role check (more reliable than function check)
    $can_see_communication = false;
    if (isset($_SESSION['user_role_id'])) {
        $user_role = $_SESSION['user_role_id'];
        // Roles 1, 2, 3 should see communications (Super Admin, Admin, Manager)
        $can_see_communication = in_array($user_role, [1, 2, 3]);
    }

    // Determine current page for active state
    $current_page = basename($_SERVER['PHP_SELF']);
    $current_path = $_SERVER['REQUEST_URI'];

    // Helper function to determine if nav item should be active
    function isNavActive($page_patterns) {
        global $current_page, $current_path;
        
        if (is_string($page_patterns)) {
            $page_patterns = [$page_patterns];
        }
        
        foreach ($page_patterns as $pattern) {
            if ($current_page === $pattern || strpos($current_path, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    // Store navigation HTML in a variable that pages can output
    ob_start();
    ?>
    <!-- Sidebar Navigation -->
    <aside id="sidebar" class="sidebar absolute md:relative bg-gray-800 text-white w-64 h-full flex-shrink-0 z-20">
        <!-- Logo -->
        <div class="p-4 flex items-center border-b border-gray-700">
            <img src="https://swfunk.com/wp-content/uploads/2020/04/Goal-Zero-1.png" alt="Logo" class="h-10 w-auto mr-3">
            <h1 class="text-xl font-bold">Safety Hub</h1>
        </div>

        <!-- Navigation Menu -->
        <nav class="p-4">
            <!-- Dashboard -->
            <a href="/dashboard.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 <?php echo isNavActive('dashboard.php') ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i data-lucide="layout-dashboard" class="w-5 h-5 mr-3"></i>
                Dashboard
            </a>

            <!-- User Management -->
            <?php if ($can_see_user_management): ?>
            <a href="/usermgmt/index.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 <?php echo isNavActive(['usermgmt/', 'profile.php', 'view_profile.php', 'bulk_upload.php', 'change_password.php']) ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i data-lucide="users" class="w-5 h-5 mr-3"></i>
                User Management
            </a>
            <?php endif; ?>

            <!-- Communication Module -->
            <?php if ($can_see_communication): ?>
            <a href="/communication/index.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 <?php echo isNavActive(['communication/']) ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i data-lucide="message-circle" class="w-5 h-5 mr-3"></i>
                Communications
            </a>
            <?php endif; ?>

            <!-- Safety Documentation -->
            <?php if ($can_see_user_management): ?>
            <a href="/documentation/index.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 <?php echo isNavActive(['documentation/']) ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i data-lucide="file-text" class="w-5 h-5 mr-3"></i>
                Documentation
            </a>
            <?php endif; ?>

            <!-- Safety Audits & Inspections -->
            <?php if ($can_see_user_management): ?>
            <a href="/audits/index.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 <?php echo isNavActive(['audits/']) ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i data-lucide="clipboard-check" class="w-5 h-5 mr-3"></i>
                Audits & Inspections
            </a>
            <?php endif; ?>

            <!-- Incident Reporting -->
            <?php if ($can_see_user_management): ?>
            <a href="/incidents/index.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 <?php echo isNavActive(['incidents/']) ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i data-lucide="alert-triangle" class="w-5 h-5 mr-3"></i>
                Incident Reporting
            </a>
            <?php endif; ?>

            <!-- Risk Assessment -->
            <?php if ($can_see_user_management): ?>
            <a href="/risk/index.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 <?php echo isNavActive(['risk/']) ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i data-lucide="shield-alert" class="w-5 h-5 mr-3"></i>
                Risk Assessment
            </a>
            <?php endif; ?>

            <!-- Course Management (LMS) -->
            <?php if ($can_see_user_management): ?>
            <a href="/courses/index.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 <?php echo isNavActive(['courses/']) ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i data-lucide="book-open" class="w-5 h-5 mr-3"></i>
                Course Management
            </a>
            <?php endif; ?>

            <!-- Training Records -->
            <?php if ($can_see_user_management): ?>
            <a href="/training/index.php" class="flex items-center py-3 px-3 rounded-lg transition-colors mb-1 <?php echo isNavActive(['training/']) ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i data-lucide="graduation-cap" class="w-5 h-5 mr-3"></i>
                Training Records
            </a>
            <?php endif; ?>

            <!-- Section Divider for Future Modules -->
            <div class="border-t border-gray-600 my-4"></div>
            
            <!-- Placeholder for future modules -->
            <div class="text-gray-500 text-xs uppercase tracking-wider px-3 mb-2">Coming Soon</div>
            
            <div class="flex items-center py-3 px-3 rounded-lg text-gray-500 cursor-not-allowed mb-1">
                <i data-lucide="bar-chart-3" class="w-5 h-5 mr-3"></i>
                Analytics & Reports
            </div>
            
            <div class="flex items-center py-3 px-3 rounded-lg text-gray-500 cursor-not-allowed mb-1">
                <i data-lucide="settings" class="w-5 h-5 mr-3"></i>
                System Settings
            </div>
        </nav>

        <!-- User Info at Bottom -->
        <div class="absolute bottom-0 w-full border-t border-gray-700">
            <a href="/usermgmt/profile.php" class="flex items-center p-4 hover:bg-gray-700 transition-colors group">
                <?php if (!empty($loggedInUserProfilePicture) && file_exists("uploads/profile_pictures/" . $loggedInUserProfilePicture)): ?>
                    <img src="serve_image.php?file=<?php echo urlencode($loggedInUserProfilePicture); ?>" 
                         alt="Profile" 
                         class="w-10 h-10 rounded-full object-cover mr-3 border border-gray-500 group-hover:border-blue-400">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-gray-600 flex items-center justify-center mr-3 border border-gray-500 group-hover:border-blue-400">
                        <i data-lucide="user" class="w-6 h-6 text-gray-400"></i>
                    </div>
                <?php endif; ?>
                <div class="min-w-0 flex-1">
                    <p class="font-medium truncate group-hover:text-blue-300"><?php echo htmlspecialchars($loggedInUserName); ?></p>
                    <p class="text-sm text-gray-400 truncate"><?php echo htmlspecialchars($loggedInUserRoleName); ?></p>
                </div>
                <i data-lucide="chevron-right" class="w-4 h-4 text-gray-400 group-hover:text-blue-300"></i>
            </a>
            
            <!-- Logout Link -->
            <a href="/logout.php" class="flex items-center p-4 hover:bg-red-600 transition-colors group border-t border-gray-700">
                <i data-lucide="log-out" class="w-5 h-5 mr-3 text-gray-400 group-hover:text-white"></i>
                <span class="text-gray-400 group-hover:text-white">Sign Out</span>
            </a>
        </div>
    </aside>

    <!-- Mobile Menu Button (for responsive design) -->
    <button id="menu-button" class="md:hidden fixed top-4 left-4 z-30 bg-gray-800 text-white p-2 rounded-lg">
        <i data-lucide="menu" class="w-6 h-6"></i>
    </button>

    <script>
    // Mobile menu toggle functionality
    document.addEventListener('DOMContentLoaded', function() {
        const menuButton = document.getElementById('menu-button');
        const sidebar = document.getElementById('sidebar');
        
        if (menuButton && sidebar) {
            menuButton.addEventListener('click', function() {
                sidebar.classList.toggle('open');
            });
        }
        
        // Initialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    });
    </script>
    <?php
    
    // Capture the navigation HTML
    $GLOBALS['navigation_html'] = ob_get_clean();
}

// Function for pages to output the navigation
function renderNavigation() {
    if (isset($GLOBALS['navigation_html'])) {
        echo $GLOBALS['navigation_html'];
    }
}