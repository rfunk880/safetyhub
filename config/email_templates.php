<?php
// config/email_templates.php - Centralized Email Content Management
// This file contains all email templates and content for easy management

// --- Email Template Configuration ---
define('EMAIL_TEMPLATES', [
    
    // User Management Email Templates
    'user_setup' => [
        'subject' => 'Your Safety Hub Account: Action Required',
        'html_template' => 'user_setup_html',
        'text_template' => 'user_setup_text',
        'variables' => ['reset_link', 'user_name']
    ],
    
    'password_reset' => [
        'subject' => 'Safety Hub: Password Reset Request',
        'html_template' => 'password_reset_html',
        'text_template' => 'password_reset_text',
        'variables' => ['reset_link', 'user_name']
    ],
    
    'account_created' => [
        'subject' => 'Welcome to Safety Hub - Account Created',
        'html_template' => 'account_created_html',
        'text_template' => 'account_created_text',
        'variables' => ['user_name', 'login_url', 'temp_password']
    ],
    
    // Safety Talk Email Templates
    'safety_talk_assignment' => [
        'subject' => 'New Safety Talk Assignment: {talk_title}',
        'html_template' => 'safety_talk_assignment_html',
        'text_template' => 'safety_talk_assignment_text',
        'variables' => ['user_name', 'talk_title', 'talk_link', 'due_date']
    ],
    
    'safety_talk_reminder' => [
        'subject' => 'Reminder: Safety Talk Pending - {talk_title}',
        'html_template' => 'safety_talk_reminder_html', 
        'text_template' => 'safety_talk_reminder_text',
        'variables' => ['user_name', 'talk_title', 'talk_link', 'days_remaining']
    ],
    
    'safety_talk_overdue' => [
        'subject' => 'OVERDUE: Safety Talk - {talk_title}',
        'html_template' => 'safety_talk_overdue_html',
        'text_template' => 'safety_talk_overdue_text',
        'variables' => ['user_name', 'talk_title', 'talk_link', 'days_overdue']
    ],
    
    // Training/LMS Email Templates
    'training_assignment' => [
        'subject' => 'New Training Assignment: {course_name}',
        'html_template' => 'training_assignment_html',
        'text_template' => 'training_assignment_text',
        'variables' => ['user_name', 'course_name', 'course_link', 'due_date']
    ],
    
    'training_reminder' => [
        'subject' => 'Training Reminder: {course_name}',
        'html_template' => 'training_reminder_html',
        'text_template' => 'training_reminder_text', 
        'variables' => ['user_name', 'course_name', 'course_link', 'days_remaining']
    ],
    
    'certificate_expiring' => [
        'subject' => 'Certificate Expiring Soon: {course_name}',
        'html_template' => 'certificate_expiring_html',
        'text_template' => 'certificate_expiring_text',
        'variables' => ['user_name', 'course_name', 'expiration_date', 'renewal_link']
    ],
    
    // Incident/SMS Email Templates
    'incident_notification' => [
        'subject' => 'URGENT: Incident Reported - {incident_number}',
        'html_template' => 'incident_notification_html',
        'text_template' => 'incident_notification_text',
        'variables' => ['incident_number', 'incident_type', 'severity', 'reporter_name', 'incident_date', 'view_link']
    ],
    
    'audit_reminder' => [
        'subject' => 'Audit Due: {audit_type}',
        'html_template' => 'audit_reminder_html',
        'text_template' => 'audit_reminder_text',
        'variables' => ['user_name', 'audit_type', 'due_date', 'audit_link']
    ]
]);

// --- HTML Email Templates ---
$EMAIL_HTML_TEMPLATES = [
    
    'user_setup_html' => '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f8f9fa;">
        <div style="background-color: #007bff; padding: 20px; text-align: center;">
            <h1 style="color: white; margin: 0;">Safety Hub</h1>
        </div>
        <div style="padding: 30px; background-color: white;">
            <h2 style="color: #333; margin-top: 0;">Account Setup Required</h2>
            <p>Hello,</p>
            <p>An account has been created for you on the Safety Hub, or a password reset has been requested.</p>
            <p>Please click the button below to set up your password and access your account:</p>
            <div style="text-align: center; margin: 30px 0;">
                <a href="{reset_link}" style="display: inline-block; padding: 15px 30px; font-size: 16px; color: #ffffff; background-color: #007bff; text-decoration: none; border-radius: 5px; font-weight: bold;">Set Up My Account</a>
            </div>
            <p style="color: #666; font-size: 14px;">If the button doesn\'t work, copy and paste this link into your browser:</p>
            <p style="color: #007bff; word-break: break-all; font-size: 14px;">{reset_link}</p>
            <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
            <p style="color: #666; font-size: 12px;">If you did not request this, please ignore this email.</p>
            <p style="margin-bottom: 0;"><strong>The Safety Hub Team</strong></p>
        </div>
    </div>',
    
    'safety_talk_assignment_html' => '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f8f9fa;">
        <div style="background-color: #28a745; padding: 20px; text-align: center;">
            <h1 style="color: white; margin: 0;">🛡️ Safety Hub</h1>
        </div>
        <div style="padding: 30px; background-color: white;">
            <h2 style="color: #333; margin-top: 0;">New Safety Talk Assignment</h2>
            <p>Hello {user_name},</p>
            <p>You have been assigned a new safety talk:</p>
            <div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #28a745; margin-top: 0;">{talk_title}</h3>
                <p style="margin: 0;"><strong>Due Date:</strong> {due_date}</p>
            </div>
            <p>Please review the safety talk and confirm your understanding by clicking the button below:</p>
            <div style="text-align: center; margin: 30px 0;">
                <a href="{talk_link}" style="display: inline-block; padding: 15px 30px; font-size: 16px; color: #ffffff; background-color: #28a745; text-decoration: none; border-radius: 5px; font-weight: bold;">View Safety Talk</a>
            </div>
            <p style="color: #666; font-size: 14px;">This safety talk is important for your safety and the safety of your coworkers. Please complete it promptly.</p>
            <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
            <p style="margin-bottom: 0;"><strong>Stay Safe,<br>The Safety Hub Team</strong></p>
        </div>
    </div>',
    
    'incident_notification_html' => '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f8f9fa;">
        <div style="background-color: #dc3545; padding: 20px; text-align: center;">
            <h1 style="color: white; margin: 0;">⚠️ INCIDENT ALERT</h1>
        </div>
        <div style="padding: 30px; background-color: white;">
            <h2 style="color: #dc3545; margin-top: 0;">Incident Reported</h2>
            <p>An incident has been reported and requires immediate attention:</p>
            <div style="background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; border-radius: 5px; margin: 20px 0;">
                <p style="margin: 5px 0;"><strong>Incident #:</strong> {incident_number}</p>
                <p style="margin: 5px 0;"><strong>Type:</strong> {incident_type}</p>
                <p style="margin: 5px 0;"><strong>Severity:</strong> <span style="color: #dc3545; font-weight: bold;">{severity}</span></p>
                <p style="margin: 5px 0;"><strong>Reported By:</strong> {reporter_name}</p>
                <p style="margin: 5px 0;"><strong>Date:</strong> {incident_date}</p>
            </div>
            <div style="text-align: center; margin: 30px 0;">
                <a href="{view_link}" style="display: inline-block; padding: 15px 30px; font-size: 16px; color: #ffffff; background-color: #dc3545; text-decoration: none; border-radius: 5px; font-weight: bold;">View Incident Details</a>
            </div>
            <p style="color: #721c24; background-color: #f8d7da; padding: 15px; border-radius: 5px; font-weight: bold;">This incident requires immediate review and response.</p>
            <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
            <p style="margin-bottom: 0;"><strong>Safety Hub Alert System</strong></p>
        </div>
    </div>',
    
    'training_assignment_html' => '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f8f9fa;">
        <div style="background-color: #17a2b8; padding: 20px; text-align: center;">
            <h1 style="color: white; margin: 0;">📚 Safety Hub LMS</h1>
        </div>
        <div style="padding: 30px; background-color: white;">
            <h2 style="color: #333; margin-top: 0;">New Training Assignment</h2>
            <p>Hello {user_name},</p>
            <p>You have been assigned a new training course:</p>
            <div style="background-color: #d1ecf1; padding: 20px; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #17a2b8; margin-top: 0;">{course_name}</h3>
                <p style="margin: 0;"><strong>Due Date:</strong> {due_date}</p>
            </div>
            <p>This training is required for your role. Please complete it by the due date.</p>
            <div style="text-align: center; margin: 30px 0;">
                <a href="{course_link}" style="display: inline-block; padding: 15px 30px; font-size: 16px; color: #ffffff; background-color: #17a2b8; text-decoration: none; border-radius: 5px; font-weight: bold;">Start Training</a>
            </div>
            <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
            <p style="margin-bottom: 0;"><strong>Keep Learning,<br>The Safety Hub Team</strong></p>
        </div>
    </div>'
];

// --- Plain Text Email Templates ---
$EMAIL_TEXT_TEMPLATES = [
    
    'user_setup_text' => '
SAFETY HUB - ACCOUNT SETUP REQUIRED

Hello,

An account has been created for you on the Safety Hub, or a password reset has been requested.

Please visit the following link to set up your password and access your account:
{reset_link}

If you did not request this, please ignore this email.

Thank you,
The Safety Hub Team',
    
    'safety_talk_assignment_text' => '
SAFETY HUB - NEW SAFETY TALK ASSIGNMENT

Hello {user_name},

You have been assigned a new safety talk: {talk_title}
Due Date: {due_date}

Please review the safety talk and confirm your understanding:
{talk_link}

This safety talk is important for your safety and the safety of your coworkers.

Stay Safe,
The Safety Hub Team',
    
    'incident_notification_text' => '
INCIDENT ALERT - IMMEDIATE ATTENTION REQUIRED

An incident has been reported:

Incident #: {incident_number}
Type: {incident_type}
Severity: {severity}
Reported By: {reporter_name}
Date: {incident_date}

View incident details: {view_link}

This incident requires immediate review and response.

Safety Hub Alert System',
    
    'training_assignment_text' => '
SAFETY HUB LMS - NEW TRAINING ASSIGNMENT

Hello {user_name},

You have been assigned a new training course: {course_name}
Due Date: {due_date}

This training is required for your role. Please complete it by the due date.

Start training: {course_link}

Keep Learning,
The Safety Hub Team'
];

// --- Email Template Helper Functions ---

/**
 * Get email template content
 * @param string $templateName Template name from EMAIL_TEMPLATES
 * @param string $format 'html' or 'text'
 * @return string Template content
 */
function getEmailTemplate($templateName, $format = 'html') {
    global $EMAIL_HTML_TEMPLATES, $EMAIL_TEXT_TEMPLATES;
    
    if (!isset(EMAIL_TEMPLATES[$templateName])) {
        return '';
    }
    
    $template = EMAIL_TEMPLATES[$templateName];
    $templateKey = $template[$format . '_template'];
    
    if ($format === 'html' && isset($EMAIL_HTML_TEMPLATES[$templateKey])) {
        return $EMAIL_HTML_TEMPLATES[$templateKey];
    } elseif ($format === 'text' && isset($EMAIL_TEXT_TEMPLATES[$templateKey])) {
        return $EMAIL_TEXT_TEMPLATES[$templateKey];
    }
    
    return '';
}

/**
 * Replace variables in email template
 * @param string $content Template content
 * @param array $variables Key-value pairs for replacement
 * @return string Processed content
 */
function replaceEmailVariables($content, $variables) {
    foreach ($variables as $key => $value) {
        $content = str_replace('{' . $key . '}', $value, $content);
    }
    return $content;
}

/**
 * Get email subject with variable replacement
 * @param string $templateName Template name
 * @param array $variables Variables for replacement
 * @return string Processed subject
 */
function getEmailSubject($templateName, $variables = []) {
    if (!isset(EMAIL_TEMPLATES[$templateName])) {
        return 'Safety Hub Notification';
    }
    
    $subject = EMAIL_TEMPLATES[$templateName]['subject'];
    return replaceEmailVariables($subject, $variables);
}

/**
 * Generate complete email content
 * @param string $templateName Template name
 * @param array $variables Variables for replacement
 * @param string $format 'html' or 'text'
 * @return array ['subject' => string, 'body' => string]
 */
function generateEmailContent($templateName, $variables = [], $format = 'html') {
    $subject = getEmailSubject($templateName, $variables);
    $body = getEmailTemplate($templateName, $format);
    $body = replaceEmailVariables($body, $variables);
    
    return [
        'subject' => $subject,
        'body' => $body
    ];
}

/**
 * Validate email template variables
 * @param string $templateName Template name
 * @param array $variables Provided variables
 * @return array Missing required variables
 */
function validateEmailVariables($templateName, $variables) {
    if (!isset(EMAIL_TEMPLATES[$templateName])) {
        return ['Template not found'];
    }
    
    $required = EMAIL_TEMPLATES[$templateName]['variables'];
    $provided = array_keys($variables);
    $missing = array_diff($required, $provided);
    
    return $missing;
}

/**
 * Get list of all available email templates
 * @return array Template names and descriptions
 */
function getAvailableEmailTemplates() {
    $templates = [];
    foreach (EMAIL_TEMPLATES as $name => $config) {
        $templates[$name] = [
            'name' => $name,
            'subject' => $config['subject'],
            'variables' => $config['variables']
        ];
    }
    return $templates;
}
?>