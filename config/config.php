<?php
// config/config.php
// Main SafetyHub configuration file
// Now automatically includes navigation for all pages

// Include the Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// --- Error Log Configuration ---
// Move error log outside public_html for security
ini_set('error_log', __DIR__ . '/../logs/php_error.log');

// Add the "use" statements for the PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// --- Force Session Save Path ---
ini_set('session.save_path', realpath(dirname($_SERVER['DOCUMENT_ROOT']) . '/../sessions'));

// --- Start Session ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Database Configuration ---
$servername = "localhost";
$username = "uurcj7i5e3hxa";
$password = "#b#%7}%2be5z";
$dbname = "dbdhrpfijmvzzj";

// Create and check connection
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log("Database Connection Failed: " . $conn->connect_error);
    die("A critical error occurred. Please try again later.");
}

// Set the character set to prevent encoding issues
$conn->set_charset("utf8mb4");

// --- Include User Management Configuration ---
require_once __DIR__ . '/usermgmt_config.php';

// --- Application Constants ---
define('APP_NAME', 'Safety Hub');
define('APP_VERSION', '1.0.0');

// --- Helper Functions ---

/**
 * Sends a setup or password reset email to a user.
 *
 * @param string $recipient_email The email address of the user.
 * @param string $token The unique reset token.
 * @return bool True on success, false on failure.
 */
function sendSetupEmail($recipient_email, $token) {
    $mail = new PHPMailer(true);

    try {
        //Server settings
        $mail->isSMTP();
        $mail->Host       = 'c1111634.sgvps.net';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'safetyhub@swfic.net';
        $mail->Password   = 'sgeBEcJUY!9x';
        $mail->SMTPSecure = 'ssl';
        $mail->Port       = 465;

        //Recipients
        $mail->setFrom('safetyhub@swfic.net', 'Safety Hub');
        $mail->addAddress($recipient_email);
        $mail->addReplyTo('no-reply@swfic.net', 'No Reply');

        // Email Content
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 
                   "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $path = dirname($_SERVER['PHP_SELF']);
        $reset_link = "{$protocol}://{$host}{$path}/reset_password.php?token={$token}";
        
        $mail->isHTML(true);
        $mail->Subject = 'Your Safety Hub Account: Action Required';
        $mail->Body    = "
            <p>Hello,</p>
            <p>An account has been created for you on the Safety Hub, or a password reset has been requested.</p>
            <p>Please click the link below to set up your password and access your account:</p>
            <p><a href='{$reset_link}' style='display: inline-block; padding: 10px 20px; font-size: 16px; color: #ffffff; background-color: #007bff; text-decoration: none; border-radius: 5px;'>Set Up My Account / Reset Password</a></p>
            <p>If you did not request this, please ignore this email.</p>
            <p>Thank you,<br>The Safety Hub Team</p>";
        $mail->AltBody = "Hello,\n\nPlease visit the following link to set up your account or reset your password: \n{$reset_link}\n\nThank you,\nThe Safety Hub Team";

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the error for debugging, but don't show it to the end-user.
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// --- AUTOMATIC NAVIGATION LOADING ---
// Load navigation for all web pages (not CLI scripts or API endpoints)
if (isset($_SERVER['HTTP_HOST']) && !defined('SKIP_NAVIGATION')) {
    // Include navigation after database and session are ready
    require_once __DIR__ . '/../includes/navigation.php';
}
?>