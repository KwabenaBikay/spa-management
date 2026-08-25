<?php
session_start();
include 'db.php';

$error = '';
$success = '';
$valid_token = false;
$user_id = null;

// 1. Verify Token from URL
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Check if token exists and hasn't expired
    $stmt = $conn->prepare("SELECT id FROM staff WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows === 1) {
        $valid_token = true;
        $user_id = $result->fetch_assoc()['id'];
    } else {
        $error = "This password reset link is invalid or has expired. Please request a new one.";
    }
} else {
    $error = "No reset token provided.";
}

// 2. Handle Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Hash new password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password and invalidate token
        $update = $conn->prepare("UPDATE staff SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $update->bind_param("si", $hashed_password, $user_id);
        
        if ($update->execute()) {
            $success = "Your password has been successfully reset. You can now log in.";
            $valid_token = false; // Hide form
        } else {
            $error = "System error: Unable to update password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password | The Breeze Spa</title>
    <style>
        body, html { margin:0; padding:0; height: 100%; font-family: "Segoe UI", sans-serif; }
        body { 
            background: url('assets/images/slide5.jpg') no-repeat center center fixed; 
            background-size: cover;
            display: flex; align-items: center; justify-content: center;
        }
        .overlay { 
            background: rgba(15, 23, 42, 0.7); 
            position: absolute; top: 0; left: 0; right: 0; bottom: 0; 
            display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .login-card { 
            background: #ffffff; width: 100%; max-width: 420px; padding: 40px; 
            border-radius: 4px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); text-align: center;
        }
        .logo { max-width: 120px; margin-bottom: 20px; }
        h2 { font-size: 22px; color: #1e293b; margin-bottom: 10px; }
        p { color: #64748b; font-size: 14px; margin-bottom: 25px; line-height: 1.5; }
        
        .input-group { text-align: left; margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px; text-transform: uppercase; }
        input { width: 100%; padding: 14px; border: 1px solid #cbd5e1; border-radius: 2px; font-size: 15px; box-sizing: border-box; }
        input:focus { border-color: #0ea5e9; outline: none; }
        
        button { width: 100%; padding: 14px; background:#0ea5e9; color:#fff; border:none; border-radius:2px; font-weight:600; cursor:pointer; font-size: 16px; transition: background 0.2s; }
        button:hover { background:#0284c7; }
        
        .alert { padding:15px; border-radius:2px; margin-bottom:20px; font-size: 14px; text-align: left; }
        .alert-success { color:#065f46; background:#d1fae5; border-left: 4px solid #10b981; }
        .alert-error { color:#991b1b; background:#fee2e2; border-left: 4px solid #dc2626; }
        
        .footer-link { display:block; margin-top:20px; color:#64748b; text-decoration:none; font-size: 14px; font-weight: 500; }
        .footer-link:hover { color: #0ea5e9; }
    </style>
</head>
<body>
    <div class="overlay">
        <div class="login-card">
            <img src="assets/images/The-Breeze-1.png" class="logo" alt="Logo" onerror="this.style.display='none'">
            <h2>Set New Password</h2>
            
            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <?php if ($valid_token): ?>
                <p>Please enter your new secure password below.</p>
                <form method="post" action="">
                    <div class="input-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required autofocus>
                    </div>
                    <div class="input-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" required>
                    </div>
                    <button type="submit">Update Password</button>
                </form>
            <?php endif; ?>
            
            <a class="footer-link" href="index.php">Return to Login</a>
        </div>
    </div>
</body>
</html>
