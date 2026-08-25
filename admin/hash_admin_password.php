<?php
// Script to hash the admin password for better security
// Run this once to update the admin password to hashed version

include '../db.php';

// Get the admin user
$stmt = $conn->prepare("SELECT * FROM staff WHERE username = ? AND role = 'admin'");
$stmt->bind_param("s", "Bismark");
$stmt->execute();
$result = $stmt->get_result();

if ($admin = $result->fetch_assoc()) {
    // Hash the plain text password
    $hashed_password = password_hash("l0v3u", PASSWORD_DEFAULT);
    
    // Update the password in database
    $update_stmt = $conn->prepare("UPDATE staff SET password = ? WHERE id = ?");
    $update_stmt->bind_param("si", $hashed_password, $admin['id']);
    
    if ($update_stmt->execute()) {
        echo "Admin password has been successfully hashed and updated!";
        echo "<br>You can now delete this file for security.";
    } else {
        echo "Error updating password: " . $conn->error;
    }
} else {
    echo "Admin user not found!";
}
?> 