<?php
// config/lms_config.php - Learning Management System Configuration

// --- Course Management Settings ---
define('COURSE_UPLOAD_DIR', __DIR__ . '/../uploads/courses/');
define('COURSE_MAX_SIZE', 104857600); // 100MB
define('COURSE_ALLOWED_TYPES', [
    'application/pdf',
    'video/mp4',
    'video/avi',
    'video/mov',
    'application/zip', // For SCORM packages
    'application/x-zip-compressed',
    'application/powerpoint',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'
]);

// --- SCORM Configuration ---
define('SCORM_ENABLED', true);
define('SCORM_UPLOAD_DIR', __DIR__ . '/../uploads/scorm/');
define('SCORM_EXTRACT_DIR', __DIR__ . '/../uploads/scorm/extracted/');
define('SCORM_VERSION_SUPPORT', ['1.2', '2004']);

// --- Assessment Settings ---
define('ASSESSMENT_PASSING_SCORE', 70); // Percentage
define('ASSESSMENT_MAX_ATTEMPTS', 3);
define('ASSESSMENT_TIME_LIMIT', 3600); // 1 hour in seconds
define('ASSESSMENT_QUESTION_TYPES', [
    'multiple_choice',
    'true_false',
    'fill_blank',
    'essay'
]);

// --- Certificate Settings ---
define('CERTIFICATE_TEMPLATE_DIR', __DIR__ . '/../templates/certificates/');
define('CERTIFICATE_OUTPUT_DIR', __DIR__ . '/../uploads/certificates/');
define('CERTIFICATE_VALIDITY_DAYS', 365);
define('CERTIFICATE_AUTO_GENERATE', true);

// --- Training Matrix Settings ---
define('TRAINING_MATRIX_EXPORT_FORMATS', ['xlsx', 'csv', 'pdf']);
define('TRAINING_MATRIX_COLUMNS', [
    'employee_name',
    'employee_id',
    'job_title',
    'course_name',
    'completion_date',
    'expiration_date',
    'status'
]);

// --- Course Categories ---
define('COURSE_CATEGORIES', [
    'orientation' => 'New Employee Orientation',
    'safety' => 'Safety Training',
    'equipment' => 'Equipment Operation',
    'compliance' => 'Compliance Training',
    'hazard' => 'Hazard-Specific Training',
    'emergency' => 'Emergency Procedures',
    'management' => 'Management Training'
]);

// --- LMS Helper Functions ---

/**
 * Check if user has completed required training
 * @param mysqli $conn Database connection
 * @param int $userId User ID
 * @param int $courseId Course ID
 * @return bool True if completed, false otherwise
 */
function hasUserCompletedCourse($conn, $userId, $courseId) {
    $stmt = $conn->prepare("
        SELECT id FROM course_completions 
        WHERE user_id = ? AND course_id = ? AND status = 'completed'
    ");
    $stmt->bind_param("ii", $userId, $courseId);
    $stmt->execute();
    $result = $stmt->get_result();
    $completed = $result->num_rows > 0;
    $stmt->close();
    return $completed;
}

/**
 * Get courses required for a specific job title
 * @param mysqli $conn Database connection
 * @param string $jobTitle Job title
 * @return array Array of required course IDs
 */
function getRequiredCoursesForTitle($conn, $jobTitle) {
    $stmt = $conn->prepare("
        SELECT course_id FROM course_requirements 
        WHERE job_title = ? OR job_title = 'all'
    ");
    $stmt->bind_param("s", $jobTitle);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $courses = [];
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row['course_id'];
    }
    $stmt->close();
    return $courses;
}

/**
 * Calculate course completion percentage for user
 * @param mysqli $conn Database connection
 * @param int $userId User ID
 * @return float Completion percentage
 */
function getUserCompletionPercentage($conn, $userId) {
    // Get user's job title
    $stmt = $conn->prepare("SELECT title FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user) return 0;
    
    $requiredCourses = getRequiredCoursesForTitle($conn, $user['title']);
    if (empty($requiredCourses)) return 100;
    
    $completed = 0;
    foreach ($requiredCourses as $courseId) {
        if (hasUserCompletedCourse($conn, $userId, $courseId)) {
            $completed++;
        }
    }
    
    return ($completed / count($requiredCourses)) * 100;
}

/**
 * Check if certificate is still valid
 * @param string $completionDate Date of course completion
 * @param int $validityDays Number of days certificate is valid
 * @return bool True if valid, false if expired
 */
function isCertificateValid($completionDate, $validityDays = CERTIFICATE_VALIDITY_DAYS) {
    try {
        $completion = new DateTime($completionDate);
        $expiration = $completion->add(new DateInterval("P{$validityDays}D"));
        $today = new DateTime();
        return $today <= $expiration;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get upcoming certificate expirations for user
 * @param mysqli $conn Database connection
 * @param int $userId User ID
 * @param int $daysBefore Number of days before expiration to warn
 * @return array Array of expiring certificates
 */
function getUpcomingExpirations($conn, $userId, $daysBefore = 30) {
    $stmt = $conn->prepare("
        SELECT cc.*, c.name as course_name, c.validity_days
        FROM course_completions cc
        JOIN courses c ON cc.course_id = c.id
        WHERE cc.user_id = ? AND cc.status = 'completed'
        AND DATE_ADD(cc.completion_date, INTERVAL c.validity_days DAY) <= DATE_ADD(NOW(), INTERVAL ? DAY)
        AND DATE_ADD(cc.completion_date, INTERVAL c.validity_days DAY) >= NOW()
    ");
    $stmt->bind_param("ii", $userId, $daysBefore);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $expirations = [];
    while ($row = $result->fetch_assoc()) {
        $expirations[] = $row;
    }
    $stmt->close();
    return $expirations;
}

/**
 * Generate training matrix data for export
 * @param mysqli $conn Database connection
 * @param array $filters Optional filters for data
 * @return array Training matrix data
 */
function generateTrainingMatrixData($conn, $filters = []) {
    $whereClause = "WHERE 1=1";
    $params = [];
    $types = "";
    
    if (!empty($filters['department'])) {
        $whereClause .= " AND u.department = ?";
        $params[] = $filters['department'];
        $types .= "s";
    }
    
    if (!empty($filters['job_title'])) {
        $whereClause .= " AND u.title = ?";
        $params[] = $filters['job_title'];
        $types .= "s";
    }
    
    $sql = "
        SELECT 
            u.firstName, u.lastName, u.employeeId, u.title,
            c.name as course_name, cc.completion_date, cc.expiration_date,
            CASE 
                WHEN cc.expiration_date IS NULL OR cc.expiration_date >= NOW() THEN 'Valid'
                ELSE 'Expired'
            END as status
        FROM users u
        LEFT JOIN course_completions cc ON u.id = cc.user_id
        LEFT JOIN courses c ON cc.course_id = c.id
        {$whereClause}
        ORDER BY u.lastName, u.firstName, c.name
    ";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    return $data;
}
?>