<?php
// /config/communication.php
// Communication module configuration - separate modular config file
// This file should be included in main pages that need communication functionality
// ONLY contains configuration, constants, and simple helper functions

// Add PHPMailer use statements at the top of the file
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// --- COMMUNICATION MODULE CONFIGURATION ---
// Only define if not already defined
if (!defined('COMMUNICATION_MODULE_ENABLED')) {
    define('COMMUNICATION_MODULE_ENABLED', true);
}

// Communication module paths
define('COMMUNICATION_UPLOAD_DIR', __DIR__ . '/../uploads/safety_talks/');
define('COMMUNICATION_UPLOAD_URL', '/uploads/safety_talks/');

// Ensure upload directory exists
if (!file_exists(COMMUNICATION_UPLOAD_DIR)) {
    mkdir(COMMUNICATION_UPLOAD_DIR, 0755, true);
}

// Role permissions for communication module - using existing SafetyHub role IDs
define('COMMUNICATION_ADMIN_ROLES', [1, 2, 3]); // Super Admin, Admin, Manager
define('COMMUNICATION_EMPLOYEE_ROLES', [4, 5, 6]); // Supervisor, Employee, Subcontractor

// Communication email settings (extends existing email config)
define('COMM_EMAIL_FROM', 'safetyhub@swfic.net');
define('COMM_EMAIL_FROM_NAME', 'Safety Hub - Communications');

// Communication SMS/Twilio settings
define('COMM_TWILIO_ACCOUNT_SID', 'AC5a1bd30f59b6b3c8facb7583d885e56a');
define('COMM_TWILIO_AUTH_TOKEN', '348c30ee560c83ffad91effa8d20d70c');
define('COMM_TWILIO_PHONE_NUMBER', '+18047350956');
define('COMM_TWILIO_MESSAGING_SERVICE_SID', 'MGe20e6e071e8b46e0b42e305e221378ec');

// Communication module constants
define('COMM_MAX_FILE_SIZE', 50 * 1024 * 1024); // 50MB
define('COMM_ALLOWED_FILE_TYPES', ['pdf', 'mp4']);
define('COMM_QUIZ_PASS_PERCENTAGE', 80); // 80% to pass quiz
define('COMM_SMS_MAX_LENGTH', 160); // Standard SMS length

// --- SIMPLE HELPER FUNCTIONS (Config Level) ---
// Only simple, stateless functions that don't require database access

/**
 * Check if current user has admin access to communication module
 */
function hasCommAdminAccess() {
    if (!isset($_SESSION['user_role_id'])) {
        return false;
    }
    
    return in_array($_SESSION['user_role_id'], COMMUNICATION_ADMIN_ROLES);
}

/**
 * Check if current user can receive safety talks
 */
function canReceiveSafetyTalks() {
    if (!isset($_SESSION['user_role_id'])) {
        return false;
    }
    
    return in_array($_SESSION['user_role_id'], COMMUNICATION_EMPLOYEE_ROLES);
}

/**
 * Require admin access for communication module
 */
function requireCommAdminAccess() {
    if (!hasCommAdminAccess()) {
        header('Location: /dashboard.php?error=' . urlencode('Access denied. You need manager or admin privileges to access the Communication module.'));
        exit;
    }
}

/**
 * Check if file type is allowed for safety talks
 */
function isCommFileTypeAllowed($file_extension) {
    return in_array(strtolower($file_extension), COMM_ALLOWED_FILE_TYPES);
}

/**
 * Get file size limit for communication uploads
 */
function getCommMaxFileSize() {
    return COMM_MAX_FILE_SIZE;
}

/**
 * Format file size for display
 */
function formatCommFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * Format phone number for SMS (ensure +1 format)
 */
function formatPhoneForSMS($phone) {
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // If it's 10 digits, add +1
    if (strlen($phone) === 10) {
        return '+1' . $phone;
    }
    
    // If it's 11 digits and starts with 1, add +
    if (strlen($phone) === 11 && substr($phone, 0, 1) === '1') {
        return '+' . $phone;
    }
    
    // If it already has country code format, return as is
    if (substr($phone, 0, 1) === '+') {
        return $phone;
    }
    
    // Default: add +1 prefix
    return '+1' . $phone;
}

/**
 * Send safety talk notification email using existing email configuration
 * Simple email function - complex email logic should go in /src/communication.php
 */
function sendSafetyTalkEmail($recipient_email, $recipient_name, $talk_title, $view_link) {
    $mail = new PHPMailer(true);

    try {
        // Use existing SafetyHub email configuration
        $mail->isSMTP();
        $mail->Host       = 'c1111634.sgvps.net';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'safetyhub@swfic.net';
        $mail->Password   = 'sgeBEcJUY!9x';
        $mail->SMTPSecure = 'ssl';
        $mail->Port       = 465;

        //Recipients
        $mail->setFrom(COMM_EMAIL_FROM, COMM_EMAIL_FROM_NAME);
        $mail->addAddress($recipient_email, $recipient_name);
        $mail->addReplyTo('no-reply@swfic.net', 'No Reply');

        // Email Content
        $mail->isHTML(true);
        $mail->Subject = 'New Safety Communication: ' . $talk_title;
        $mail->Body    = "
            <p>Hello {$recipient_name},</p>
            <p>A new safety communication has been distributed to you:</p>
            <h3>{$talk_title}</h3>
            <p>Please click the link below to view the safety communication and confirm your understanding:</p>
            <p><a href='{$view_link}' style='display: inline-block; padding: 10px 20px; font-size: 16px; color: #ffffff; background-color: #dc3545; text-decoration: none; border-radius: 5px;'>View Safety Communication</a></p>
            <p>This communication requires your confirmation. Please review the content and follow any instructions provided.</p>
            <p>Thank you for your attention to safety,<br>Safety Hub Team</p>";
        
        $mail->AltBody = "Hello {$recipient_name},\n\nA new safety communication has been distributed: {$talk_title}\n\nPlease visit: {$view_link}\n\nThank you,\nSafety Hub Team";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Safety Talk Email Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Send safety talk notification SMS using Twilio
 * Simple SMS function - complex SMS logic should go in /src/communication.php
 */
function sendSafetyTalkSMS($recipient_phone, $recipient_name, $talk_title, $view_link) {
    // Only load Twilio if we're actually sending SMS
    require_once __DIR__ . '/../vendor/autoload.php';
    
    try {
        $twilio = new \Twilio\Rest\Client(COMM_TWILIO_ACCOUNT_SID, COMM_TWILIO_AUTH_TOKEN);
        
        // Format phone number
        $formatted_phone = formatPhoneForSMS($recipient_phone);
        
        // Create short message (SMS length limit)
        $message = "Safety Communication: {$talk_title}. Please view and confirm: {$view_link}";
        
        // Truncate if too long
        if (strlen($message) > COMM_SMS_MAX_LENGTH) {
            $message = "Safety Communication: {$talk_title}. Please check your email for details.";
        }
        
        $twilio->messages->create(
            $formatted_phone,
            [
                'from' => COMM_TWILIO_PHONE_NUMBER,
                'body' => $message,
                'messagingServiceSid' => COMM_TWILIO_MESSAGING_SERVICE_SID
            ]
        );
        
        return true;
    } catch (Exception $e) {
        error_log("Safety Talk SMS Error: " . $e->getMessage());
        return false;
    }
}