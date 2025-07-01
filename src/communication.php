<?php
// /src/communication.php
// Communication module business logic functions for SafetyHub
// This file contains all database operations and complex business logic
// This file should be included after config/config.php and config/communication.php
// NO configuration constants should be in this file - only business logic

// --- DATABASE OPERATIONS AND BUSINESS LOGIC ---
// All functions that require database access or complex processing

/**
 * Get all employees/subcontractors who can receive safety talks
 * DATABASE OPERATION - belongs in /src/ not /config/
 */
function getCommEmployees($conn) {
    $role_ids = implode(',', COMMUNICATION_EMPLOYEE_ROLES);
    $sql = "SELECT id, firstName, lastName, email, mobile_phone as phone, 
                   CONCAT(firstName, ' ', lastName) as name,
                   roleId, isActive
            FROM users 
            WHERE roleId IN ($role_ids) 
            AND isActive = 1
            AND (terminationDate IS NULL OR terminationDate > CURDATE())
            ORDER BY firstName, lastName";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Get Communication Employees SQL Error: " . $conn->error);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get admin by ID for communication module
 * DATABASE OPERATION - belongs in /src/ not /config/
 */
function getCommAdminById($admin_id, $conn) {
    $role_ids = implode(',', COMMUNICATION_ADMIN_ROLES);
    $stmt = $conn->prepare("SELECT id, firstName, lastName, email, 
                                  CONCAT(firstName, ' ', lastName) as name,
                                  roleId
                           FROM users 
                           WHERE id = ? AND roleId IN ($role_ids)");
    
    if (!$stmt) {
        error_log("Get Communication Admin SQL Error: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $admin = $result->fetch_assoc();
    $stmt->close();
    
    return $admin;
}

/**
 * Add a new safety talk
 * @param string $title Safety talk title
 * @param string $custom_content HTML content
 * @param int $admin_id ID of creating admin
 * @param mysqli $conn Database connection
 * @param array $content File/website content data
 * @param array $quiz_data Optional quiz data
 * @return int|false Talk ID on success, false on failure
 */
function addSafetyTalk($title, $custom_content, $admin_id, $conn, $content, $quiz_data = []) {
    $file_path = '';
    $file_type = '';
    $target_file = '';
    $description = ''; 
    $has_quiz = !empty($quiz_data['questions']);

    if ($content['type'] === 'file' && isset($content['data']['name']) && $content['data']['error'] == UPLOAD_ERR_OK) {
        $file_info = $content['data'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_info['tmp_name']);
        finfo_close($finfo);
        
        $allowed_mime_types = ['application/pdf', 'video/mp4'];
        if (!in_array($mime_type, $allowed_mime_types)) {
            error_log("Security Alert: File upload with incorrect MIME type attempted. Name: {$file_info['name']}, Type: {$mime_type}");
            return false;
        }
        
        // Check file size
        if ($file_info['size'] > COMM_MAX_FILE_SIZE) {
            error_log("File too large: {$file_info['name']}, Size: {$file_info['size']}");
            return false;
        }
        
        $file_name = time() . '_' . basename($file_info['name']);
        $target_file = COMMUNICATION_UPLOAD_DIR . $file_name;
        $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        
        if (!move_uploaded_file($file_info['tmp_name'], $target_file)) {
            return false;
        }
        $file_path = COMMUNICATION_UPLOAD_URL . $file_name;
        
    } elseif ($content['type'] === 'website') {
        $file_path = $content['data'];
        $file_type = 'website';
    }
    
    $stmt = $conn->prepare("INSERT INTO safety_talks (title, description, custom_content, file_path, file_type, created_by_admin_id, has_quiz) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) { 
        return false; 
    }
    
    $has_quiz_int = $has_quiz ? 1 : 0;
    $stmt->bind_param("sssssii", $title, $description, $custom_content, $file_path, $file_type, $admin_id, $has_quiz_int);
    
    if ($stmt->execute()) {
        $talk_id = $conn->insert_id;
        if ($has_quiz) {
            saveQuiz($talk_id, $quiz_data, $conn);
        }
        return $talk_id;
    } else {
        if ($content['type'] === 'file' && !empty($target_file) && file_exists($target_file)) {
            unlink($target_file);
        }
        return false;
    }
}

/**
 * Get safety talk by ID
 * @param int $talk_id Safety talk ID
 * @param mysqli $conn Database connection
 * @return array|null Talk data or null if not found
 */
function getSafetyTalkById($talk_id, $conn) {
    $stmt = $conn->prepare("SELECT * FROM safety_talks WHERE id = ?");
    if (!$stmt) return null;
    
    $stmt->bind_param("i", $talk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $talk = $result->fetch_assoc();
    $stmt->close();
    
    return $talk;
}

/**
 * Archive safety talk
 * @param int $talk_id Safety talk ID
 * @param mysqli $conn Database connection
 * @return bool Success status
 */
function archiveSafetyTalk($talk_id, $conn) {
    $stmt = $conn->prepare("UPDATE safety_talks SET is_archived = 1 WHERE id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("i", $talk_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Unarchive safety talk
 * @param int $talk_id Safety talk ID
 * @param mysqli $conn Database connection
 * @return bool Success status
 */
function unarchiveSafetyTalk($talk_id, $conn) {
    $stmt = $conn->prepare("UPDATE safety_talks SET is_archived = 0 WHERE id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("i", $talk_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Delete safety talk and all associated records
 * @param int $talk_id Safety talk ID
 * @param mysqli $conn Database connection
 * @return bool Success status
 */
function deleteSafetyTalkAndRecords($talk_id, $conn) {
    // Get file path for cleanup
    $talk = getSafetyTalkById($talk_id, $conn);
    
    // Delete the safety talk (cascading deletes will handle related records)
    $stmt = $conn->prepare("DELETE FROM safety_talks WHERE id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("i", $talk_id);
    $success = $stmt->execute();
    $stmt->close();
    
    // Clean up file if exists
    if ($success && $talk && $talk['file_type'] !== 'website' && !empty($talk['file_path'])) {
        $file_path = str_replace(COMMUNICATION_UPLOAD_URL, COMMUNICATION_UPLOAD_DIR, $talk['file_path']);
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    
    return $success;
}

// --- Distribution Functions ---

/**
 * Distribute safety talk to employees
 * @param int $safety_talk_id Safety talk ID
 * @param array $employee_ids Array of employee IDs
 * @param mysqli $conn Database connection
 * @return array Distribution report
 */
function distributeTalk($safety_talk_id, $employee_ids, $conn) {
    $report = [
        'success_count' => 0, 
        'errors' => [], 
        'newly_distributed' => [], 
        'skipped' => []
    ];
    
    $talk = getSafetyTalkById($safety_talk_id, $conn);
    if (!$talk) {
        $report['errors'][] = "Safety Talk not found.";
        return $report;
    }

    // Update first_distributed_at if this is the first distribution
    if (is_null($talk['first_distributed_at'])) {
        $stmt = $conn->prepare("UPDATE safety_talks SET first_distributed_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $safety_talk_id);
        $stmt->execute();
        $stmt->close();
    }

    foreach ($employee_ids as $employee_id) {
        $employee = getUserById($conn, $employee_id); // Using existing SafetyHub function
        if (!$employee) {
            $report['errors'][] = "Employee ID $employee_id not found.";
            continue;
        }

        // Check if already distributed
        $stmt = $conn->prepare("SELECT id FROM distributions WHERE safety_talk_id = ? AND employee_id = ?");
        $stmt->bind_param("ii", $safety_talk_id, $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $report['skipped'][] = $employee['firstName'] . ' ' . $employee['lastName'];
            $stmt->close();
            continue;
        }
        $stmt->close();

        // Create new distribution
        $unique_token = generateUniqueToken();
        $stmt = $conn->prepare("INSERT INTO distributions (safety_talk_id, employee_id, unique_link_token) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $safety_talk_id, $employee_id, $unique_token);
        
        if ($stmt->execute()) {
            $distribution_id = $conn->insert_id;
            
            // Send notification using existing email system
            $view_link = "https://" . $_SERVER['HTTP_HOST'] . "/communication/view_talk.php?token=" . $unique_token;
            $email_sent = sendSafetyTalkEmail(
                $employee['email'], 
                $employee['firstName'] . ' ' . $employee['lastName'],
                $talk['title'],
                $view_link
            );
            
            if ($email_sent) {
                $report['success_count']++;
                $report['newly_distributed'][] = $employee['firstName'] . ' ' . $employee['lastName'];
            } else {
                $report['errors'][] = "Email failed for " . $employee['firstName'] . ' ' . $employee['lastName'];
            }
        } else {
            $report['errors'][] = "Failed to create distribution for " . $employee['firstName'] . ' ' . $employee['lastName'];
        }
        $stmt->close();
    }

    return $report;
}

/**
 * Generate unique token for distribution links
 * @param int $length Token length
 * @return string Unique token
 */
function generateUniqueToken($length = 64) {
    return bin2hex(random_bytes($length / 2));
}

// --- Reporting Functions ---

/**
 * Get pending signatures report
 * @param mysqli $conn Database connection
 * @return array Report data
 */
function getPendingSignaturesReport($conn) {
    $sql = "SELECT st.id as safety_talk_id, 
                   st.title as safety_talk_title, 
                   st.first_distributed_at, 
                   DATEDIFF(NOW(), st.first_distributed_at) as days_since_distribution,
                   COUNT(DISTINCT d.id) as total_distributed, 
                   SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END) as total_signed,
                   GROUP_CONCAT(DISTINCT CASE WHEN c.id IS NULL THEN CONCAT(u.firstName, ' ', u.lastName) ELSE NULL END ORDER BY u.firstName, u.lastName SEPARATOR ', ') as pending_employee_names
            FROM safety_talks st 
            JOIN distributions d ON st.id = d.safety_talk_id 
            JOIN users u ON d.employee_id = u.id 
            LEFT JOIN confirmations c ON d.id = c.distribution_id 
            WHERE st.first_distributed_at IS NOT NULL 
            AND st.first_distributed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
            AND st.is_archived = 0 
            GROUP BY st.id, st.title, st.first_distributed_at 
            HAVING SUM(CASE WHEN c.id IS NULL THEN 1 ELSE 0 END) > 0 
            ORDER BY st.first_distributed_at DESC";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Pending Signatures Report SQL Error: " . $conn->error);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get safety talk history
 * @param mysqli $conn Database connection
 * @return array History data
 */
function getPastSafetyTalks($conn) {
    $sql = "SELECT st.id, st.title, 
                   st.first_distributed_at as initial_distribution, 
                   MAX(d.sent_at) as last_sent, 
                   COUNT(DISTINCT d.id) as total_distributed, 
                   COUNT(c.id) as total_confirmed 
            FROM safety_talks st 
            JOIN distributions d ON st.id = d.safety_talk_id 
            LEFT JOIN confirmations c ON d.id = c.distribution_id 
            WHERE st.is_archived = 0 
            AND st.first_distributed_at IS NOT NULL 
            GROUP BY st.id, st.title, st.first_distributed_at 
            ORDER BY last_sent DESC";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Get Past Safety Talks SQL Error: " . $conn->error);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get archived safety talks
 * @param mysqli $conn Database connection
 * @return array Archived talks data
 */
function getArchivedSafetyTalks($conn) {
    $sql = "SELECT st.id, st.title, 
                   st.first_distributed_at as initial_distribution, 
                   MAX(d.sent_at) as last_sent, 
                   COUNT(DISTINCT d.id) as total_distributed, 
                   COUNT(c.id) as total_confirmed 
            FROM safety_talks st 
            JOIN distributions d ON st.id = d.safety_talk_id 
            LEFT JOIN confirmations c ON d.id = c.distribution_id 
            WHERE st.is_archived = 1 
            AND st.first_distributed_at IS NOT NULL 
            GROUP BY st.id, st.title, st.first_distributed_at 
            ORDER BY last_sent DESC";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Get Archived Safety Talks SQL Error: " . $conn->error);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get orphaned safety talks (no distributions)
 * @param mysqli $conn Database connection
 * @return array Orphaned talks data
 */
function getOrphanedSafetyTalks($conn) {
    $sql = "SELECT st.id, st.title, st.created_at, 
                   CONCAT(u.firstName, ' ', u.lastName) as created_by_name
            FROM safety_talks st
            LEFT JOIN distributions d ON st.id = d.safety_talk_id
            LEFT JOIN users u ON st.created_by_admin_id = u.id
            WHERE d.id IS NULL
            ORDER BY st.created_at DESC";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Get Orphaned Safety Talks SQL Error: " . $conn->error);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get talk details for detailed view
 * @param int $talk_id Safety talk ID
 * @param mysqli $conn Database connection
 * @return array|null Talk details or null
 */
function getTalkDetails($talk_id, $conn) {
    $details = [];
    
    $stmt_talk = $conn->prepare("SELECT * FROM safety_talks WHERE id = ?");
    $stmt_talk->bind_param("i", $talk_id);
    $stmt_talk->execute();
    $result_talk = $stmt_talk->get_result();
    
    if ($result_talk->num_rows === 0) {
        return null;
    }
    
    $details = $result_talk->fetch_assoc();
    
    if ($details['has_quiz']) {
        $details['quiz'] = getQuizForTalk($talk_id, $conn);
    }
    
    $sql_dist = "SELECT d.id as distribution_id, 
                        CONCAT(u.firstName, ' ', u.lastName) as employee_name,
                        u.email as employee_email, 
                        u.mobile_phone as employee_phone, 
                        c.confirmation_date, 
                        c.signature_image_base64, 
                        d.notification_count, 
                        qr.score as quiz_score 
                 FROM distributions d 
                 JOIN users u ON d.employee_id = u.id 
                 LEFT JOIN confirmations c ON d.id = c.distribution_id 
                 LEFT JOIN quiz_results qr ON d.id = qr.distribution_id 
                 WHERE d.safety_talk_id = ? 
                 ORDER BY c.confirmation_date ASC, u.firstName, u.lastName ASC";
    
    $stmt_dist = $conn->prepare($sql_dist);
    $stmt_dist->bind_param("i", $talk_id);
    $stmt_dist->execute();
    $result_dist = $stmt_dist->get_result();
    $details['distributions'] = $result_dist->fetch_all(MYSQLI_ASSOC);
    
    return $details;
}

// --- Quiz Functions ---

/**
 * Save quiz for safety talk
 * @param int $talk_id Safety talk ID
 * @param array $quiz_data Quiz questions and answers
 * @param mysqli $conn Database connection
 */
function saveQuiz($talk_id, $quiz_data, $conn) {
    foreach ($quiz_data['questions'] as $q_index => $question_text) {
        if (empty(trim($question_text))) continue;
        
        $stmt_q = $conn->prepare("INSERT INTO quiz_questions (safety_talk_id, question_text, question_order) VALUES (?, ?, ?)");
        $stmt_q->bind_param("isi", $talk_id, $question_text, $q_index);
        
        if ($stmt_q->execute()) {
            $question_id = $conn->insert_id;
            $correct_answer_index = $quiz_data['correct_answers'][$q_index] ?? -1;
            
            foreach ($quiz_data['answers'][$q_index] as $a_index => $answer_text) {
                if (empty(trim($answer_text))) continue;
                $is_correct = ($a_index == $correct_answer_index) ? 1 : 0;
                
                $stmt_a = $conn->prepare("INSERT INTO quiz_answers (question_id, answer_text, is_correct, answer_order) VALUES (?, ?, ?, ?)");
                $stmt_a->bind_param("isii", $question_id, $answer_text, $is_correct, $a_index);
                $stmt_a->execute();
                $stmt_a->close();
            }
        }
        $stmt_q->close();
    }
}

/**
 * Get quiz for safety talk
 * @param int $talk_id Safety talk ID
 * @param mysqli $conn Database connection
 * @return array Quiz data
 */
function getQuizForTalk($talk_id, $conn) {
    $quiz = ['questions' => []];
    
    $stmt_q = $conn->prepare("SELECT id, question_text FROM quiz_questions WHERE safety_talk_id = ? ORDER BY question_order");
    $stmt_q->bind_param("i", $talk_id);
    $stmt_q->execute();
    $result_q = $stmt_q->get_result();
    
    while ($question = $result_q->fetch_assoc()) {
        $question['answers'] = [];
        
        $stmt_a = $conn->prepare("SELECT id, answer_text, is_correct FROM quiz_answers WHERE question_id = ? ORDER BY answer_order");
        $stmt_a->bind_param("i", $question['id']);
        $stmt_a->execute();
        $result_a = $stmt_a->get_result();
        
        while ($answer = $result_a->fetch_assoc()) {
            $question['answers'][] = $answer;
        }
        $stmt_a->close();
        $quiz['questions'][] = $question;
    }
    $stmt_q->close();
    
    return $quiz;
}

/**
 * Save quiz result
 * @param int $distribution_id Distribution ID
 * @param float $score Quiz score
 * @param bool $passed Whether quiz was passed
 * @param mysqli $conn Database connection
 * @return bool Success status
 */
function saveQuizResult($distribution_id, $score, $passed, $conn) {
    $stmt = $conn->prepare("INSERT INTO quiz_results (distribution_id, score, passed, attempt_date) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE score = VALUES(score), passed = VALUES(passed), attempt_date = NOW()");
    $stmt->bind_param("idi", $distribution_id, $score, $passed);
    return $stmt->execute();
}

// --- Confirmation Functions ---

/**
 * Save safety talk confirmation
 * @param int $distribution_id Distribution ID
 * @param string $signature_base64 Base64 encoded signature
 * @param string $ip_address User's IP address
 * @param bool $understood Whether user understood the content
 * @param mysqli $conn Database connection
 * @return bool|string True on success, error message on failure
 */
function saveTalkConfirmation($distribution_id, $signature_base64, $ip_address, $understood, $conn) {
    $stmt = $conn->prepare("INSERT INTO confirmations (distribution_id, signature_image_base64, ip_address, understood) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return "Prepare failed: " . $conn->error;
    }
    
    $understood_int = $understood ? 1 : 0;
    $stmt->bind_param("issi", $distribution_id, $signature_base64, $ip_address, $understood_int);
    
    if ($stmt->execute()) {
        return true;
    } else {
        if ($conn->errno == 1062) {
            return "This talk has already been confirmed.";
        }
        return "Execute failed: " . $stmt->error;
    }
}

/**
 * Check if user has confirmed a distribution
 * @param int $distribution_id Distribution ID
 * @param mysqli $conn Database connection
 * @return bool Whether confirmation exists
 */
function hasConfirmed($distribution_id, $conn) {
    $stmt = $conn->prepare("SELECT id FROM confirmations WHERE distribution_id = ?");
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("i", $distribution_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $confirmed = $result->num_rows > 0;
    $stmt->close();
    
    return $confirmed;
}

/**
 * Get distribution by token for employee access
 * @param string $token Unique distribution token
 * @param mysqli $conn Database connection
 * @return array|null Distribution data or null
 */
function getDistributionByToken($token, $conn) {
    $stmt = $conn->prepare("
        SELECT d.*, st.title as talk_title, st.custom_content, st.file_path, st.file_type, st.has_quiz,
               CONCAT(u.firstName, ' ', u.lastName) as employee_name, u.email as employee_email
        FROM distributions d
        JOIN safety_talks st ON d.safety_talk_id = st.id
        JOIN users u ON d.employee_id = u.id
        WHERE d.unique_link_token = ?
    ");
    
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = $result->fetch_assoc();
    $stmt->close();
    
    return $distribution;
}

/**
 * Update notification count for a distribution
 * @param int $distribution_id Distribution ID
 * @param mysqli $conn Database connection
 * @return bool Success status
 */
function incrementNotificationCount($distribution_id, $conn) {
    $stmt = $conn->prepare("UPDATE distributions SET notification_count = notification_count + 1 WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("i", $distribution_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Get distributions for reminder notifications
 * @param mysqli $conn Database connection
 * @return array Pending distributions
 */
function getPendingDistributionsForReminders($conn) {
    $sql = "SELECT d.id, d.unique_link_token, 
                   CONCAT(u.firstName, ' ', u.lastName) as employee_name,
                   u.email, u.mobile_phone as phone, 
                   st.title as safety_talk_title 
            FROM distributions d 
            JOIN users u ON d.employee_id = u.id 
            JOIN safety_talks st ON d.safety_talk_id = st.id 
            LEFT JOIN confirmations c ON d.id = c.distribution_id 
            WHERE c.id IS NULL 
            AND st.is_archived = 0 
            AND st.first_distributed_at IS NOT NULL 
            AND st.first_distributed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Get Pending for Reminders SQL Error: " . $conn->error);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Send reminder notification for a specific distribution
 * @param int $distribution_id Distribution ID
 * @param string $method Notification method ('email', 'sms', or 'both')
 * @param mysqli $conn Database connection
 * @return array Result with success status and any errors
 */
function sendReminderNotification($distribution_id, $method, $conn) {
    $result = ['success' => false, 'errors' => []];
    
    // Get distribution details
    $stmt = $conn->prepare("
        SELECT d.unique_link_token, 
               CONCAT(u.firstName, ' ', u.lastName) as employee_name,
               u.email, u.mobile_phone as phone, 
               st.title as safety_talk_title 
        FROM distributions d 
        JOIN users u ON d.employee_id = u.id 
        JOIN safety_talks st ON d.safety_talk_id = st.id 
        WHERE d.id = ?
    ");
    
    if (!$stmt) {
        $result['errors'][] = "Database error preparing statement";
        return $result;
    }
    
    $stmt->bind_param("i", $distribution_id);
    $stmt->execute();
    $dist_result = $stmt->get_result();
    $distribution = $dist_result->fetch_assoc();
    $stmt->close();
    
    if (!$distribution) {
        $result['errors'][] = "Distribution not found";
        return $result;
    }
    
    $view_link = "https://" . $_SERVER['HTTP_HOST'] . "/communication/view_talk.php?token=" . $distribution['unique_link_token'];
    
    // Send email reminder
    if ($method === 'email' || $method === 'both') {
        $email_sent = sendSafetyTalkEmail(
            $distribution['email'],
            $distribution['employee_name'],
            $distribution['safety_talk_title'] . " (REMINDER)",
            $view_link
        );
        
        if (!$email_sent) {
            $result['errors'][] = "Email reminder failed";
        }
    }
    
    // TODO: Implement SMS reminder if needed
    // if ($method === 'sms' || $method === 'both') {
    //     // SMS implementation would go here
    // }
    
    // Update notification count
    if (incrementNotificationCount($distribution_id, $conn)) {
        $result['success'] = true;
    } else {
        $result['errors'][] = "Failed to update notification count";
    }
    
    return $result;
}

/**
 * Get overall status report for all safety talks
 * @param mysqli $conn Database connection
 * @return array Overall status data
 */
function getOverallStatusReport($conn) {
    $sql = "SELECT st.title as safety_talk_title, 
                   COUNT(DISTINCT d.id) as total_distributed, 
                   COUNT(c.id) as total_confirmed 
            FROM safety_talks st 
            LEFT JOIN distributions d ON st.id = d.safety_talk_id 
            LEFT JOIN confirmations c ON d.id = c.distribution_id 
            WHERE st.is_archived = 0 
            GROUP BY st.id, st.title 
            ORDER BY st.created_at DESC";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Overall Status Report SQL Error: " . $conn->error);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Update safety talk content
 * @param int $talk_id Safety talk ID
 * @param string $new_content New HTML content
 * @param mysqli $conn Database connection
 * @return bool Success status
 */
function updateSafetyTalkContent($talk_id, $new_content, $conn) {
    $stmt = $conn->prepare("UPDATE safety_talks SET custom_content = ? WHERE id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("si", $new_content, $talk_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Update quiz for existing safety talk
 * @param int $talk_id Safety talk ID
 * @param array $quiz_data Quiz questions and answers
 * @param mysqli $conn Database connection
 * @return bool Success status
 */
function updateQuiz($talk_id, $quiz_data, $conn) {
    // Delete existing quiz questions (cascading delete will handle answers)
    $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE safety_talk_id = ?");
    $stmt->bind_param("i", $talk_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        saveQuiz($talk_id, $quiz_data, $conn);
        return true;
    }
    return false;
}

/**
 * Get distribution details by ID for resending notifications
 * @param int $distribution_id Distribution ID
 * @param mysqli $conn Database connection
 * @return array|null Distribution details or null
 */
function getDistributionById($distribution_id, $conn) {
    $stmt = $conn->prepare("
        SELECT d.*, 
               CONCAT(u.firstName, ' ', u.lastName) as employee_name,
               u.email as employee_email, 
               u.mobile_phone as employee_phone,
               st.title as safety_talk_title
        FROM distributions d
        JOIN users u ON d.employee_id = u.id
        JOIN safety_talks st ON d.safety_talk_id = st.id
        WHERE d.id = ?
    ");
    
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("i", $distribution_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = $result->fetch_assoc();
    $stmt->close();
    
    return $distribution;
}