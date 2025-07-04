<?php
// /public_html/communication/distribute_talk.php
// Final distribution handler

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../config/communication.php';
require_once __DIR__ . '/../../src/communication.php';

if (!isUserLoggedIn()) {
    header('Location: /login.php');
    exit;
}

requireCommAdminAccess();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $talk_id = $_POST['talk_id'] ?? 0;
    $employee_ids_str = $_POST['employee_ids'] ?? '';
    $employee_ids = array_filter(explode(',', $employee_ids_str));
    
    if ($talk_id && !empty($employee_ids)) {
        // Verify the talk is still in draft status
        $talk_details = getSafetyTalkById($talk_id, $conn);
        if (!$talk_details || $talk_details['status'] !== 'draft') {
            header('Location: index.php?error=' . urlencode('Safety talk not found or already distributed.'));
            exit;
        }
        
        // Update talk status to distributed
        $stmt = $conn->prepare("UPDATE safety_talks SET status = 'distributed' WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $talk_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Distribute to selected employees
        $result = distributeTalk($talk_id, $employee_ids, $conn);
        
        // Clean up session data
        unset($_SESSION['pending_distribution']);
        
        // Clean up test distributions for this talk
        $stmt_cleanup = $conn->prepare("DELETE FROM test_distributions WHERE safety_talk_id = ?");
        if ($stmt_cleanup) {
            $stmt_cleanup->bind_param("i", $talk_id);
            $stmt_cleanup->execute();
            $stmt_cleanup->close();
        }
        
        if ($result['success_count'] > 0) {
            $message = "Safety talk '{$talk_details['title']}' distributed successfully to {$result['success_count']} employees.";
            if (!empty($result['skipped'])) {
                $message .= " Some employees had already received this talk and were skipped.";
            }
            if (!empty($result['errors'])) {
                $message .= " However, some notifications failed: " . implode(', ', array_slice($result['errors'], 0, 3));
                if (count($result['errors']) > 3) {
                    $message .= ' and ' . (count($result['errors']) - 3) . ' more errors.';
                }
            }
            header('Location: index.php?message=' . urlencode($message));
        } else {
            $error_msg = 'Failed to distribute safety talk.';
            if (!empty($result['errors'])) {
                $error_msg .= ' Errors: ' . implode(', ', array_slice($result['errors'], 0, 2));
            }
            header('Location: preview_talk.php?id=' . $talk_id . '&error=' . urlencode($error_msg));
        }
        exit;
    } else {
        header('Location: index.php?error=' . urlencode('Invalid distribution data.'));
        exit;
    }
}

header('Location: index.php');
exit;
?>