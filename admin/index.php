<?php
session_start();

// Check if the user is already logged in as an admin or manager
if (isset($_SESSION['user']) && in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    // If logged in, send them straight to the dashboard
    header("Location: dashboard.php");
    exit;
} else {
    // If NOT logged in, send them to the login page
    header("Location: login.php");
    exit;
}
?>