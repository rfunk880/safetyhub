<?php
// logout.php

// Include our central configuration file to start the session
require_once __DIR__ . '/../config/config.php';

// Destroy the session
session_unset();
session_destroy();

// Start a new session for the logout message
session_start();
$_SESSION['logout_message'] = 'You have been successfully logged out.';

// Redirect to login page
header('Location: login.php');
exit();
?>