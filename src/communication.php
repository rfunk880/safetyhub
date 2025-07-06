<?php
// /src/communication.php
// Communication module business logic functions for SafetyHub
// Updated with new workflow functions and FIXED user table queries
// NEW: Supports status column (draft, distributed, archived) workflow

// --- DATABASE OPERATIONS AND BUSINESS LOGIC ---

/**
 * Get all employees/subcontractors who can receive safety talks
 */
function getCommEmployees($conn) {
    $role_ids = implode(',', COMMUNICATION_EMPLOYEE_ROLES);
    $sql = "SELECT id, firstName, lastName, email, mobile_phone_new as phone, 
                   CONCAT(firstName, ' ', lastName) as name,
                   roleId
            FROM users 
            WHERE roleId IN ($role_ids) 
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
 * Add a new safety talk (now defaults to draft status)
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
        
        $file_path = '/serve_safety_talk.php?file=' . urlencode($file_name);
        
    } elseif ($content['type'] === 'website') {
        $file_path = $content['data'];
        $file_type = 'website';
    }
    
    // Create as draft by default
    $stmt = $conn->prepare("INSERT INTO safety_talks (title, description, custom_content, file_path, file_type, created_by_admin_id, has_quiz, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft')");
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
 */
function getSafetyTalkById($talk_id, $conn) {
    $stmt = $conn->prepare("SELECT * FROM safety_talks WHERE id = ?");
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("i", $talk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $talk = $result->fetch_assoc();
    $stmt->close();
    
    return $talk;
}

/**
 * Check if safety talk is in draft status
 */
function isSafetyTalkDraft($talk_id, $conn) {
    $stmt = $conn->prepare("SELECT status FROM safety_talks WHERE id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("i", $talk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $talk = $result->fetch_assoc();
    $stmt->close();
    
    return $talk && $talk['status'] === 'draft';
}

/**
 * Distribute safety talk to employees (updated for new workflow)
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

    // Update first_distributed_at and status if this is the first distribution
    if (is_null($talk['first_distributed_at'])) {
        $stmt = $conn->prepare("UPDATE safety_talks SET first_distributed_at = NOW(), status = 'distributed' WHERE id = ?");
        $stmt->bind_param("i", $safety_talk_id);
        $stmt->execute();
        $stmt->close();
    }

    foreach ($employee_ids as $employee_id) {
        $employee = getUserById($conn, $employee_id);
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
            
            // Send notifications
            $view_link = "https://" . $_SERVER['HTTP_HOST'] . "/communication/view_talk.php?token=" . $unique_token;
            $employee_name = $employee['firstName'] . ' ' . $employee['lastName'];
            
            // Send Email notification
            $email_sent = sendSafetyTalkEmail(
                $employee['email'], 
                $employee_name,
                $talk['title'], 
                $view_link
            );
            
            // Send SMS notification
            $sms_sent = false;
            if (!empty($employee['mobile_phone'])) {
                $sms_sent = sendSafetyTalkSMS(
                    $employee['mobile_phone'],
                    $employee_name,
                    $talk['title'],
                    $view_link
                );
            }
            
            // Update distribution status
            $email_status = $email_sent ? 'sent' : 'failed';
            $sms_status = $sms_sent ? 'sent' : 'failed';
            
            $stmt_update = $conn->prepare("UPDATE distributions SET email_status = ?, sms_status = ? WHERE id = ?");
            $stmt_update->bind_param("ssi", $email_status, $sms_status, $distribution_id);
            $stmt_update->execute();
            $stmt_update->close();
            
            if ($email_sent || $sms_sent) {
                $report['success_count']++;
                $report['newly_distributed'][] = $employee_name;
            } else {
                $report['errors'][] = "Failed to send notifications to " . $employee_name;
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
 */
function generateUniqueToken($length = 64) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Create test distribution (NEW FUNCTION)
 */
function createTestDistribution($safety_talk_id, $conn) {
    $test_token = generateUniqueToken(32);
    
    $stmt = $conn->prepare("INSERT INTO test_distributions (safety_talk_id, test_token) VALUES (?, ?)");
    if (!$stmt) return false;
    
    $stmt->bind_param("is", $safety_talk_id, $test_token);
    
    if ($stmt->execute()) {
        $stmt->close();
        return $test_token;
    } else {
        $stmt->close();
        return false;
    }
}

/**
 * Get test distribution by token
 */
function getTestDistributionByToken($token, $conn) {
    $stmt = $conn->prepare("
        SELECT td.*, st.title as talk_title, st.custom_content, st.file_path, st.file_type, st.has_quiz
        FROM test_distributions td
        JOIN safety_talks st ON td.safety_talk_id = st.id
        WHERE td.test_token = ?
    ");
    
    if (!$stmt) return null;
    
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $test_distribution = $result->fetch_assoc();
    $stmt->close();
    
    return $test_distribution;
}

/**
 * Clean up old test distributions (run via cron job)
 */
function cleanupOldTestDistributions($conn, $days_old = 7) {
    $stmt = $conn->prepare("DELETE FROM test_distributions WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    if ($stmt) {
        $stmt->bind_param("i", $days_old);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Get pending signatures report - FIXED to use users table
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
            AND st.status = 'distributed'
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
 * Get overall status report
 */
function getOverallStatusReport($conn) {
    $sql = "SELECT 
                COUNT(DISTINCT st.id) as total_talks,
                COUNT(DISTINCT d.id) as total_distributions,
                COUNT(DISTINCT c.id) as total_confirmations
            FROM safety_talks st
            LEFT JOIN distributions d ON st.id = d.safety_talk_id
            LEFT JOIN confirmations c ON d.id = c.distribution_id
            WHERE st.status = 'distributed'";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Overall Status Report SQL Error: " . $conn->error);
        return [];
    }
    return $result->fetch_assoc();
}

/**
 * Get safety talk history - updated for new status system
 */
function getPastSafetyTalks($conn) {
    $sql = "SELECT st.id, st.title, st.status,
                   st.first_distributed_at as initial_distribution, 
                   MAX(d.sent_at) as last_sent, 
                   COUNT(DISTINCT d.id) as total_distributed, 
                   COUNT(c.id) as total_confirmed,
                   st.created_at 
            FROM safety_talks st 
            LEFT JOIN distributions d ON st.id = d.safety_talk_id 
            LEFT JOIN confirmations c ON d.id = c.distribution_id 
            WHERE st.status IN ('distributed', 'draft')
            GROUP BY st.id, st.title, st.status, st.first_distributed_at, st.created_at
            ORDER BY st.created_at DESC";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Get Past Safety Talks SQL Error: " . $conn->error);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get archived safety talks
 */
function getArchivedSafetyTalks($conn) {
    $sql = "SELECT st.id, st.title, 
                   st.first_distributed_at as initial_distribution, 
                   MAX(d.sent_at) as last_sent, 
                   COUNT(DISTINCT d.id) as total_distributed, 
                   COUNT(c.id) as total_confirmed 
            FROM safety_talks st 
            LEFT JOIN distributions d ON st.id = d.safety_talk_id 
            LEFT JOIN confirmations c ON d.id = c.distribution_id 
            WHERE st.status = 'archived'
            GROUP BY st.id, st.title, st.first_distributed_at 
            ORDER BY st.first_distributed_at DESC";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Get Archived Safety Talks SQL Error: " . $conn->error);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Update safety talk content
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
 * Update safety talk title
 */
function updateSafetyTalkTitle($talk_id, $title, $conn) {
    $stmt = $conn->prepare("UPDATE safety_talks SET title = ? WHERE id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("si", $title, $talk_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Update safety talk file/website attachment
 */
function updateSafetyTalkAttachment($talk_id, $file_path, $file_type, $conn) {
    $stmt = $conn->prepare("UPDATE safety_talks SET file_path = ?, file_type = ? WHERE id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("ssi", $file_path, $file_type, $talk_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Update quiz for existing safety talk
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
 * Get distribution details by ID for resending notifications - FIXED to use users table
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

/**
 * Get talk details for detailed view - FIXED to use users table with correct fields
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
    
    // FIXED: Updated to use 'users' table with correct field names
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
 */
function saveQuizResult($distribution_id, $score, $passed, $conn) {
    $stmt = $conn->prepare("INSERT INTO quiz_results (distribution_id, score, passed, attempt_date) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE score = VALUES(score), passed = VALUES(passed), attempt_date = NOW()");
    $stmt->bind_param("idi", $distribution_id, $score, $passed);
    return $stmt->execute();
}

/**
 * Save safety talk confirmation
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
 * Check if distribution has been confirmed
 */
function hasConfirmed($distribution_id, $conn) {
    $stmt = $conn->prepare("SELECT id FROM confirmations WHERE distribution_id = ?");
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("i", $distribution_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_confirmed = $result->num_rows > 0;
    $stmt->close();
    
    return $has_confirmed;
}

/**
 * Get distribution by token for employee access - FIXED to use users table
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
 * Archive a safety talk
 */
function archiveSafetyTalk($talk_id, $conn) {
    $stmt = $conn->prepare("UPDATE safety_talks SET status = 'archived' WHERE id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("i", $talk_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Unarchive a safety talk (NEW FUNCTION)
 */
function unarchiveSafetyTalk($talk_id, $conn) {
    // Check current status and set appropriate status when unarchiving
    $talk = getSafetyTalkById($talk_id, $conn);
    if (!$talk) return false;
    
    $new_status = is_null($talk['first_distributed_at']) ? 'draft' : 'distributed';
    
    $stmt = $conn->prepare("UPDATE safety_talks SET status = ? WHERE id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("si", $new_status, $talk_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Delete safety talk and all associated records
 */
function deleteSafetyTalkAndRecords($talk_id, $conn) {
    // Get talk details first to check for files to delete
    $talk = getSafetyTalkById($talk_id, $conn);
    if (!$talk) {
        return false;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete test distributions first
        $stmt = $conn->prepare("DELETE FROM test_distributions WHERE safety_talk_id = ?");
        $stmt->bind_param("i", $talk_id);
        $stmt->execute();
        $stmt->close();
        
        // Delete quiz answers first (foreign key constraint)
        $stmt = $conn->prepare("
            DELETE qa FROM quiz_answers qa 
            JOIN quiz_questions qq ON qa.question_id = qq.id 
            WHERE qq.safety_talk_id = ?
        ");
        $stmt->bind_param("i", $talk_id);
        $stmt->execute();
        $stmt->close();
        
        // Delete quiz questions
        $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE safety_talk_id = ?");
        $stmt->bind_param("i", $talk_id);
        $stmt->execute();
        $stmt->close();
        
        // Delete quiz results
        $stmt = $conn->prepare("
            DELETE qr FROM quiz_results qr 
            JOIN distributions d ON qr.distribution_id = d.id 
            WHERE d.safety_talk_id = ?
        ");
        $stmt->bind_param("i", $talk_id);
        $stmt->execute();
        $stmt->close();
        
        // Delete confirmations
        $stmt = $conn->prepare("
            DELETE c FROM confirmations c 
            JOIN distributions d ON c.distribution_id = d.id 
            WHERE d.safety_talk_id = ?
        ");
        $stmt->bind_param("i", $talk_id);
        $stmt->execute();
        $stmt->close();
        
        // Delete distributions
        $stmt = $conn->prepare("DELETE FROM distributions WHERE safety_talk_id = ?");
        $stmt->bind_param("i", $talk_id);
        $stmt->execute();
        $stmt->close();
        
        // Finally delete the safety talk
        $stmt = $conn->prepare("DELETE FROM safety_talks WHERE id = ?");
        $stmt->bind_param("i", $talk_id);
        $stmt->execute();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        // Clean up file if exists
        if ($talk && $talk['file_type'] !== 'website' && !empty($talk['file_path'])) {
            $file_path = str_replace('/serve_safety_talk.php?file=', '', $talk['file_path']);
            $file_path = COMMUNICATION_UPLOAD_DIR . urldecode($file_path);
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        error_log("Delete Safety Talk Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send reminder notification for a specific distribution - FIXED to use users table
 */
function sendReminderNotification($distribution_id, $method, $conn) {
    $result = ['success' => false, 'errors' => []];
    
    // Get distribution details - FIXED to use users table
    $stmt = $conn->prepare("
        SELECT d.unique_link_token, 
               CONCAT(u.firstName, ' ', u.lastName) as employee_name,
               u.email, u.mobile_phone_new as phone, 
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
        } else {
            $result['success'] = true;
        }
    }
    
    // Send SMS reminder if requested
    if ($method === 'sms' || $method === 'both') {
        if (!empty($distribution['phone'])) {
            $sms_sent = sendSafetyTalkSMS(
                $distribution['phone'],
                $distribution['employee_name'],
                $distribution['safety_talk_title'] . " (REMINDER)",
                $view_link
            );
            
            if (!$sms_sent) {
                $result['errors'][] = "SMS reminder failed";
            } else {
                $result['success'] = true;
            }
        } else {
            $result['errors'][] = "No phone number available for SMS";
        }
    }
    
    // Update notification count if any method was successful
    if ($result['success'] && incrementNotificationCount($distribution_id, $conn)) {
        // Successfully updated notification count
    } else if ($result['success']) {
        $result['errors'][] = "Failed to update notification count";
    }
    
    return $result;
}

/**
 * Update notification count for a distribution
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
 * Migrate safety talks from is_archived to status column (NEW FUNCTION)
 * This function helps transition from the old is_archived system to the new status system
 */
function migrateSafetyTalksToStatusColumn($conn) {
    // Check if migration is needed
    $check_stmt = $conn->prepare("SHOW COLUMNS FROM safety_talks LIKE 'status'");
    $check_stmt->execute();
    $status_column_exists = $check_stmt->get_result()->num_rows > 0;
    $check_stmt->close();
    
    if (!$status_column_exists) {
        // Add status column if it doesn't exist
        $conn->query("ALTER TABLE safety_talks ADD COLUMN status ENUM('draft', 'distributed', 'archived') DEFAULT 'draft'");
    }
    
    // Check if is_archived column exists
    $check_archived = $conn->prepare("SHOW COLUMNS FROM safety_talks LIKE 'is_archived'");
    $check_archived->execute();
    $archived_column_exists = $check_archived->get_result()->num_rows > 0;
    $check_archived->close();
    
    if ($archived_column_exists) {
        // Migrate data from is_archived to status
        $conn->query("UPDATE safety_talks SET status = 'archived' WHERE is_archived = 1");
        $conn->query("UPDATE safety_talks SET status = 'distributed' WHERE is_archived = 0 AND first_distributed_at IS NOT NULL");
        $conn->query("UPDATE safety_talks SET status = 'draft' WHERE is_archived = 0 AND first_distributed_at IS NULL");
        
        // Remove the old is_archived column
        $conn->query("ALTER TABLE safety_talks DROP COLUMN is_archived");
    }
    
    return true;
}

// Note: getUserById() function is already defined in /src/auth.php
// We use the existing function instead of redefining it here
?>