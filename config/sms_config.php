<?php
// config/sms_config.php - Safety Management System Configuration

// --- Incident Management Settings ---
define('INCIDENT_UPLOAD_DIR', __DIR__ . '/../uploads/incidents/');
define('INCIDENT_PHOTO_MAX_SIZE', 10485760); // 10MB
define('INCIDENT_PHOTO_ALLOWED_TYPES', [
    'image/jpeg',
    'image/png', 
    'image/gif'
]);

define('INCIDENT_SEVERITY_LEVELS', [
    'low' => 'Low - No injury, minor property damage',
    'medium' => 'Medium - Minor injury, moderate property damage',
    'high' => 'High - Serious injury, significant property damage',
    'critical' => 'Critical - Fatality, major property damage'
]);

define('INCIDENT_TYPES', [
    'injury' => 'Personal Injury',
    'near_miss' => 'Near Miss',
    'property_damage' => 'Property Damage',
    'environmental' => 'Environmental Incident',
    'security' => 'Security Incident',
    'equipment_failure' => 'Equipment Failure'
]);

// --- Audit and Inspection Settings ---
define('AUDIT_UPLOAD_DIR', __DIR__ . '/../uploads/audits/');
define('AUDIT_TEMPLATE_DIR', __DIR__ . '/../templates/audits/');
define('AUDIT_FREQUENCIES', [
    'daily' => 'Daily',
    'weekly' => 'Weekly', 
    'monthly' => 'Monthly',
    'quarterly' => 'Quarterly',
    'annually' => 'Annually'
]);

define('AUDIT_TYPES', [
    'safety_inspection' => 'Safety Inspection',
    'equipment_check' => 'Equipment Check',
    'housekeeping' => 'Housekeeping Audit',
    'ppe_compliance' => 'PPE Compliance Check',
    'emergency_equipment' => 'Emergency Equipment Check'
]);

// --- Risk Assessment Settings ---
define('RISK_MATRIX_PROBABILITY', [
    1 => 'Very Unlikely',
    2 => 'Unlikely', 
    3 => 'Possible',
    4 => 'Likely',
    5 => 'Very Likely'
]);

define('RISK_MATRIX_SEVERITY', [
    1 => 'Negligible',
    2 => 'Minor',
    3 => 'Moderate', 
    4 => 'Major',
    5 => 'Catastrophic'
]);

define('RISK_LEVELS', [
    1 => ['level' => 'Very Low', 'color' => '#28a745', 'action' => 'Monitor'],
    2 => ['level' => 'Low', 'color' => '#ffc107', 'action' => 'Monitor'],
    3 => ['level' => 'Medium', 'color' => '#fd7e14', 'action' => 'Review Controls'],
    4 => ['level' => 'High', 'color' => '#dc3545', 'action' => 'Immediate Action'],
    5 => ['level' => 'Very High', 'color' => '#6f42c1', 'action' => 'Stop Work']
]);

// --- Dashboard Metrics Configuration ---
define('DASHBOARD_METRICS', [
    'ltir' => 'Lost Time Injury Rate',
    'trir' => 'Total Recordable Injury Rate', 
    'dart' => 'Days Away/Restricted/Transfer Rate',
    'near_miss_ratio' => 'Near Miss to Incident Ratio',
    'training_compliance' => 'Training Compliance Rate'
]);

define('DASHBOARD_KPI_TARGETS', [
    'ltir' => 2.0,
    'trir' => 3.0,
    'dart' => 1.5,
    'near_miss_ratio' => 10.0,
    'training_compliance' => 95.0
]);

// --- OSHA/MSHA Report Settings ---
define('OSHA_FORMS', [
    '300' => 'OSHA 300 - Log of Work-Related Injuries and Illnesses',
    '300A' => 'OSHA 300A - Summary of Work-Related Injuries and Illnesses',
    '301' => 'OSHA 301 - Injury and Illness Incident Report'
]);

define('MSHA_FORMS', [
    '7000-1' => 'MSHA 7000-1 - Accident/Injury/Illness Report',
    '7000-2' => 'MSHA 7000-2 - Quarterly Employment and Coal Production Report'
]);

// --- Notification Settings ---
define('INCIDENT_NOTIFICATION_ROLES', [1, 2, 3]); // Super Admin, Admin, Manager
define('CRITICAL_INCIDENT_IMMEDIATE_NOTIFY', true);
define('INCIDENT_FOLLOW_UP_DAYS', [1, 7, 30]); // Follow-up reminders

// --- Document Management ---
define('SAFETY_DOC_UPLOAD_DIR', __DIR__ . '/../uploads/safety_documents/');
define('SAFETY_DOC_MAX_SIZE', 52428800); // 50MB
define('SAFETY_DOC_ALLOWED_TYPES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
]);

define('SAFETY_DOC_CATEGORIES', [
    'manual' => 'Safety Manuals',
    'procedure' => 'Safety Procedures', 
    'policy' => 'Safety Policies',
    'sds' => 'Safety Data Sheets',
    'report' => 'Safety Reports',
    'training' => 'Training Materials'
]);

// --- SMS Helper Functions ---

/**
 * Calculate risk score based on probability and severity
 * @param int $probability Probability rating (1-5)
 * @param int $severity Severity rating (1-5) 
 * @return int Risk score
 */
function calculateRiskScore($probability, $severity) {
    return $probability * $severity;
}

/**
 * Get risk level information based on score
 * @param int $riskScore Risk score from calculation
 * @return array Risk level information
 */
function getRiskLevel($riskScore) {
    $riskLevels = RISK_LEVELS;
    if ($riskScore <= 4) return $riskLevels[1];
    if ($riskScore <= 8) return $riskLevels[2]; 
    if ($riskScore <= 12) return $riskLevels[3];
    if ($riskScore <= 16) return $riskLevels[4];
    return $riskLevels[5];
}

/**
 * Check if incident requires immediate notification
 * @param string $severity Incident severity level
 * @param string $type Incident type
 * @return bool True if immediate notification required
 */
function requiresImmediateNotification($severity, $type) {
    return in_array($severity, ['high', 'critical']) || 
           ($type === 'injury' && $severity !== 'low');
}

/**
 * Generate incident report number
 * @param string $type Incident type
 * @param string $date Incident date
 * @return string Formatted incident number
 */
function generateIncidentNumber($type, $date) {
    $year = date('Y', strtotime($date));
    $typeCode = strtoupper(substr($type, 0, 3));
    $sequence = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    return "INC-{$year}-{$typeCode}-{$sequence}";
}

/**
 * Calculate LTIR (Lost Time Injury Rate)
 * @param mysqli $conn Database connection
 * @param string $startDate Start date for calculation
 * @param string $endDate End date for calculation
 * @param int $totalHours Total hours worked in period
 * @return float LTIR value
 */
function calculateLTIR($conn, $startDate, $endDate, $totalHours) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as lost_time_injuries 
        FROM incidents 
        WHERE incident_date BETWEEN ? AND ? 
        AND severity IN ('high', 'critical')
        AND lost_time_days > 0
    ");
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($totalHours == 0) return 0;
    return ($result['lost_time_injuries'] / $totalHours) * 200000;
}

/**
 * Calculate TRIR (Total Recordable Injury Rate) 
 * @param mysqli $conn Database connection
 * @param string $startDate Start date for calculation
 * @param string $endDate End date for calculation
 * @param int $totalHours Total hours worked in period
 * @return float TRIR value
 */
function calculateTRIR($conn, $startDate, $endDate, $totalHours) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as recordable_injuries 
        FROM incidents 
        WHERE incident_date BETWEEN ? AND ? 
        AND type = 'injury'
        AND severity IN ('medium', 'high', 'critical')
    ");
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($totalHours == 0) return 0;
    return ($result['recordable_injuries'] / $totalHours) * 200000;
}

/**
 * Get incident trend data for dashboard
 * @param mysqli $conn Database connection
 * @param int $months Number of months to include
 * @return array Trend data by month
 */
function getIncidentTrendData($conn, $months = 12) {
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(incident_date, '%Y-%m') as month,
            COUNT(*) as total_incidents,
            SUM(CASE WHEN type = 'injury' THEN 1 ELSE 0 END) as injuries,
            SUM(CASE WHEN type = 'near_miss' THEN 1 ELSE 0 END) as near_misses
        FROM incidents 
        WHERE incident_date >= DATE_SUB(NOW(), INTERVAL ? MONTH)
        GROUP BY DATE_FORMAT(incident_date, '%Y-%m')
        ORDER BY month
    ");
    $stmt->bind_param("i", $months);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $trends = [];
    while ($row = $result->fetch_assoc()) {
        $trends[] = $row;
    }
    $stmt->close();
    return $trends;
}

/**
 * Check if audit is overdue
 * @param string $lastAuditDate Date of last audit
 * @param string $frequency Audit frequency
 * @return bool True if overdue, false otherwise
 */
function isAuditOverdue($lastAuditDate, $frequency) {
    if (empty($lastAuditDate)) return true;
    
    try {
        $lastAudit = new DateTime($lastAuditDate);
        $today = new DateTime();
        
        switch ($frequency) {
            case 'daily': $interval = 'P1D'; break;
            case 'weekly': $interval = 'P1W'; break; 
            case 'monthly': $interval = 'P1M'; break;
            case 'quarterly': $interval = 'P3M'; break;
            case 'annually': $interval = 'P1Y'; break;
            default: return false;
        }
        
        $nextDue = $lastAudit->add(new DateInterval($interval));
        return $today > $nextDue;
    } catch (Exception $e) {
        return true; // If date parsing fails, consider overdue
    }
}

/**
 * Generate OSHA 300 log data
 * @param mysqli $conn Database connection  
 * @param int $year Year for report
 * @return array OSHA 300 log entries
 */
function generateOSHA300Data($conn, $year) {
    $stmt = $conn->prepare("
        SELECT 
            i.*,
            u.firstName, u.lastName, u.employeeId, u.title
        FROM incidents i
        JOIN users u ON i.user_id = u.id
        WHERE YEAR(i.incident_date) = ?
        AND i.type = 'injury'
        AND i.severity IN ('medium', 'high', 'critical')
        ORDER BY i.incident_date
    ");
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $entries = [];
    while ($row = $result->fetch_assoc()) {
        $entries[] = $row;
    }
    $stmt->close();
    return $entries;
}
?>