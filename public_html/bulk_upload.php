<?php
// bulk_upload.php
// This page handles the CSV file upload and processing for bulk user creation.

// Include configuration first to ensure session is started
require_once __DIR__ . '/../config/config.php';

// --- Page Access Control ---
// Only allow Super Admins to access this page.
// Role ID: 1=Super Admin
if (!isset($_SESSION['user_role_id']) || $_SESSION['user_role_id'] != 1) {
    header("Location: users.php");
    exit;
}

// Initialize variables for providing feedback to the user.
$feedback = [];
$error_count = 0;
$success_count = 0;

// --- Handle File Upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['user_csv'])) {
    // Check for upload errors.
    if ($_FILES['user_csv']['error'] === UPLOAD_ERR_OK) {
        $file_path = $_FILES['user_csv']['tmp_name'];
        $file_handle = fopen($file_path, 'r');

        // Get the header row from the CSV to validate its structure.
        $header = fgetcsv($file_handle);
        $required_headers = ['FirstName', 'LastName', 'Email', 'RoleID', 'Type', 'Title'];
        
        // Check if all required headers are present.
        if (!$header || !empty(array_diff($required_headers, $header))) {
             $feedback[] = ['status' => 'error', 'message' => 'Invalid CSV header. Please use the provided template. Required columns are: ' . implode(', ', $required_headers), 'line' => 1];
        } else {
            // Check for duplicate emails in the CSV itself
            $csv_emails = [];
            $csv_data = [];
            $line_number = 1;
            
            // First pass: collect all data and check for internal duplicates
            while (($data = fgetcsv($file_handle)) !== FALSE) {
                $line_number++;
                $row = array_combine($header, $data);
                
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }
                
                // Check for duplicate emails within the CSV
                if (!empty($row['Email'])) {
                    $email_lower = strtolower(trim($row['Email']));
                    if (in_array($email_lower, $csv_emails)) {
                        $feedback[] = ['status' => 'error', 'message' => "Duplicate email in CSV: '{$row['Email']}'", 'line' => $line_number];
                        $error_count++;
                        continue;
                    }
                    $csv_emails[] = $email_lower;
                }
                
                $row['line_number'] = $line_number;
                $csv_data[] = $row;
            }
            
            // Close and reopen file handle is not needed since we stored the data
            fclose($file_handle);
            
            // Check for existing emails in database
            if (!empty($csv_emails)) {
                $placeholders = implode(',', array_fill(0, count($csv_emails), '?'));
                $existing_emails_stmt = $conn->prepare("SELECT email FROM users WHERE LOWER(email) IN ($placeholders)");
                $existing_emails_stmt->bind_param(str_repeat('s', count($csv_emails)), ...$csv_emails);
                $existing_emails_stmt->execute();
                $existing_result = $existing_emails_stmt->get_result();
                $existing_emails = [];
                while ($row = $existing_result->fetch_assoc()) {
                    $existing_emails[] = strtolower($row['email']);
                }
                $existing_emails_stmt->close();
            }

            // Prepare the SQL statement for inserting new users
            $stmt = $conn->prepare(
                "INSERT INTO users (firstName, lastName, email, password, employeeId, roleId, title, mobile_phone, alt_phone, emergency_contact_name, emergency_contact_phone, type, terminationDate) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            // Second pass: process the data
            foreach ($csv_data as $row) {
                $line_number = $row['line_number'];
                
                // --- Data Validation for each row ---
                if (empty($row['FirstName']) || empty($row['LastName']) || empty($row['Email']) || empty($row['RoleID']) || empty($row['Type']) || empty($row['Title'])) {
                    $feedback[] = ['status' => 'error', 'message' => "Skipped row: Missing required fields (FirstName, LastName, Email, RoleID, Type, Title).", 'line' => $line_number];
                    $error_count++;
                    continue;
                }

                if (!filter_var($row['Email'], FILTER_VALIDATE_EMAIL)) {
                    $feedback[] = ['status' => 'error', 'message' => "Skipped row: Invalid email format for '{$row['Email']}'.", 'line' => $line_number];
                    $error_count++;
                    continue;
                }
                
                // Check if email already exists in database
                if (in_array(strtolower(trim($row['Email'])), $existing_emails)) {
                    $feedback[] = ['status' => 'error', 'message' => "Skipped row: Email '{$row['Email']}' already exists in database.", 'line' => $line_number];
                    $error_count++;
                    continue;
                }
                
                // --- Prepare Data for Insertion ---
                // Assign a default password if one isn't provided in the CSV.
                $password = !empty($row['Password']) ? $row['Password'] : 'password123';
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                // Format the termination date, or set it to NULL if empty.
                $terminationDate = !empty($row['TerminationDate']) ? date('Y-m-d', strtotime($row['TerminationDate'])) : null;

                // Bind parameters to the prepared statement.
                $stmt->bind_param(
                    "sssssisssssss",
                    $row['FirstName'],
                    $row['LastName'],
                    $row['Email'],
                    $password_hash,
                    $row['EmployeeID'],
                    $row['RoleID'],
                    $row['Title'],
                    $row['MobilePhone'],
                    $row['AltPhone'],
                    $row['EmergencyContactName'],
                    $row['EmergencyContactPhone'],
                    $row['Type'],
                    $terminationDate
                );

                // Execute the statement and track success/failure.
                try {
                    if ($stmt->execute()) {
                        $success_count++;
                    } else {
                        $feedback[] = ['status' => 'error', 'message' => "Database error for email '{$row['Email']}': " . $conn->error, 'line' => $line_number];
                        $error_count++;
                    }
                } catch (mysqli_sql_exception $e) {
                    // Handle specific database errors gracefully
                    if ($e->getCode() == 1062) { // Duplicate entry error
                        $feedback[] = ['status' => 'error', 'message' => "Skipped row: Email '{$row['Email']}' already exists in database.", 'line' => $line_number];
                    } else {
                        $feedback[] = ['status' => 'error', 'message' => "Database error for email '{$row['Email']}': " . $e->getMessage(), 'line' => $line_number];
                    }
                    $error_count++;
                }
            }
            $stmt->close();
        }
        
        // --- Final Feedback Message ---
        if($error_count == 0 && $success_count > 0) {
            $feedback[] = ['status' => 'success', 'message' => "Successfully imported {$success_count} users!", 'line' => ''];
        } elseif ($success_count > 0 && $error_count > 0) {
             $feedback[] = ['status' => 'warning', 'message' => "Partial success: Imported {$success_count} users, but encountered {$error_count} errors.", 'line' => ''];
        } elseif ($success_count == 0 && $error_count > 0) {
            $feedback[] = ['status' => 'error', 'message' => "Import failed: No users were imported due to {$error_count} errors.", 'line' => ''];
        }

    } else {
         $feedback[] = ['status' => 'error', 'message' => 'File upload failed with error code: ' . $_FILES['user_csv']['error'], 'line' => ''];
    }
}
?>
<?php
// Get roles from the database to display the logged-in user's role name.
$roles = [];
$result_roles = $conn->query("SELECT id, name FROM roles ORDER BY id");
if ($result_roles) {
    while($row = $result_roles->fetch_assoc()) {
        $roles[] = $row;
    }
}
$loggedInUserRoleName = getRoleName($_SESSION['user_role_id'], $roles);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk User Import - Safety Hub</title>
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
        <aside id="sidebar" class="sidebar absolute md:relative bg-gray-800 text-white w-64 h-full flex-shrink-0 z-20">
             <div class="p-4 flex items-center">
                <img src="https://swfunk.com/wp-content/uploads/2020/04/Goal-Zero-1.png" alt="Logo" class="h-10 w-auto mr-3">
                <h1 class="text-2xl font-bold text-white">Safety Hub</h1>
            </div>
            <nav class="mt-8">
                <a href="users.php" class="flex items-center mt-4 py-2 px-6 bg-gray-700 text-gray-100"><i data-lucide="users" class="w-5 h-5"></i><span class="mx-3">User Management</span></a>
                 <a href="#" class="flex items-center mt-4 py-2 px-6 text-gray-400 hover:bg-gray-700 hover:text-gray-100"><i data-lucide="book-marked" class="w-5 h-5"></i><span class="mx-3">LMS</span></a>
                <a href="#" class="flex items-center mt-4 py-2 px-6 text-gray-400 hover:bg-gray-700 hover:text-gray-100"><i data-lucide="shield-check" class="w-5 h-5"></i><span class="mx-3">SMS</span></a>
                <a href="#" class="flex items-center mt-4 py-2 px-6 text-gray-400 hover:bg-gray-700 hover:text-gray-100"><i data-lucide="layout-dashboard" class="w-5 h-5"></i><span class="mx-3">Dashboards</span></a>
            </nav>
            <div class="absolute bottom-0 w-full">
                 <div class="p-4 border-t border-gray-700">
                    <p class="text-sm font-medium">Logged in as:</p>
                    <p class="text-lg font-semibold"><?php echo htmlspecialchars($_SESSION['user_first_name'] . ' ' . $_SESSION['user_last_name']); ?></p>
                    <p class="text-sm text-gray-400"><?php echo htmlspecialchars($loggedInUserRoleName); ?></p>
                 </div>
                <a href="logout.php" class="flex items-center py-4 px-6 text-gray-400 hover:bg-gray-700 hover:text-gray-100"><i data-lucide="log-out" class="w-5 h-5"></i><span class="mx-3">Logout</span></a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <header class="flex justify-between items-center p-4 bg-white border-b">
                 <button id="menu-button" class="md:hidden text-gray-500 focus:outline-none"><i data-lucide="menu" class="w-6 h-6"></i></button>
                <h2 class="text-xl font-semibold text-gray-700">Bulk User Import</h2>
                <a href="users.php" class="flex items-center text-blue-600 hover:text-blue-800">
                    <i data-lucide="arrow-left" class="w-5 h-5 mr-2"></i>
                    Back to User Management
                </a>
            </header>

            <div class="flex-1 p-4 md:p-6 overflow-y-auto">
                <div class="bg-white p-6 rounded-lg shadow-md max-w-4xl mx-auto">
                    <h3 class="text-xl font-semibold mb-2">Instructions</h3>
                    <ol class="list-decimal list-inside text-gray-600 mb-4 space-y-2">
                        <li>Download the CSV template. The required columns are: <strong>FirstName, LastName, Email, RoleID, Type, Title</strong>.</li>
                        <li>Fill in the user data. The <strong>RoleID</strong> must be a number (e.g., 5 for Employee).</li>
                        <li>A default password of 'password123' will be assigned if the password field is left blank.</li>
                        <li>Upload the completed CSV file below.</li>
                    </ol>
                    
                    <!-- NOTE: You will need to create this user_template.csv file and place it in your project's directory -->
                    <a href="download_template.php" download class="inline-flex items-center bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 mb-6">
                        <i data-lucide="download" class="w-5 h-5 mr-2"></i>
                        Download CSV Template
                    </a>

                    <form action="bulk_upload.php" method="post" enctype="multipart/form-data" class="border-t pt-6">
                        <label for="user_csv" class="block text-sm font-medium text-gray-700">Upload CSV File</label>
                        <div class="mt-2 flex items-center">
                            <input type="file" name="user_csv" id="user_csv" accept=".csv" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" required>
                            <button type="submit" class="ml-4 whitespace-nowrap inline-flex items-center bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700">
                                <i data-lucide="upload" class="w-5 h-5 mr-2"></i>
                                Upload and Process
                            </button>
                        </div>
                    </form>

                    <?php if (!empty($feedback)): ?>
                    <div class="mt-6 border-t pt-6">
                        <h3 class="text-xl font-semibold mb-2">Import Results</h3>
                        <div class="space-y-2 max-h-60 overflow-y-auto pr-2">
                        <?php foreach ($feedback as $item): 
                            $color_class = 'bg-gray-100 text-gray-800'; // Default
                            if ($item['status'] === 'success') $color_class = 'bg-green-100 text-green-800';
                            if ($item['status'] === 'error') $color_class = 'bg-red-100 text-red-800';
                            if ($item['status'] === 'warning') $color_class = 'bg-yellow-100 text-yellow-800';
                        ?>
                            <div class="p-3 rounded-md <?php echo $color_class; ?>">
                                <span class="font-semibold"><?php echo ucfirst($item['status']); ?>:</span>
                                <?php if($item['line']): ?>
                                <span class="font-mono text-sm">[Line <?php echo $item['line']; ?>]</span>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($item['message']); ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
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
