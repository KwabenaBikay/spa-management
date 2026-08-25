<?php
session_start();
include 'db.php';

$error = '';
$success = '';
$test_link = ''; // Used for testing without a real mail server

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);

    // 1. Verify the user exists
    $stmt = $conn->prepare("SELECT id, email FROM staff WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // 2. Generate secure token and expiry time (30 mins from now)
        $token = bin2hex(random_bytes(32)); 
        $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

        // 3. Save token to database
        $update = $conn->prepare("UPDATE staff SET reset_token = ?, reset_expires = ? WHERE id = ?");
        $update->bind_param("ssi", $token, $expires, $user['id']);
        $update->execute();

        // 4. Send Email (Simulated for this code)
        $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
        
        /* // REAL EMAIL CODE (Uncomment when on a live server with SMTP):
        $to = $user['email'];
        $subject = "Password Reset Request - The Breeze Spa";
        $message = "Click this link to reset your password (valid for 30 mins):\n\n" . $reset_link;
        $headers = "From: noreply@thebreezespa.com";
        mail($to, $subject, $message, $headers);
        */

        $success = 'If an account matches that username, a reset link has been generated.';
        $test_link = $reset_link; // Displaying on screen for your local testing
    } else {
        // We show the exact same message to prevent "User Enumeration" hacking
        $success = 'If an account matches that username, a reset link has been generated.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | The Breeze Spa</title>
    <style>
        body, html { margin:0; padding:0; height: 100%; font-family: "Segoe UI", sans-serif; }
        body { 
            background: url('assets/images/slide5.jpg') no-repeat center center fixed; 
            background-size: cover;
            display: flex; align-items: center; justify-content: center;
        }
        .overlay { 
            background: rgba(15, 23, 42, 0.7); /* Darker overlay for contrast */
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

        /* Testing block */
        .test-link-box { margin-top: 20px; padding: 15px; background: #f8fafc; border: 1px dashed #cbd5e1; font-size: 12px; word-wrap: break-word; text-align: left;}
    </style>
</head>
<body>
    <div class="overlay">
        <div class="login-card">
            <img src="assets/images/The-Breeze-1.png" class="logo" alt="Logo" onerror="this.style.display='none'">
            <h2>Password Recovery</h2>
            <p>Enter your system username. We will generate a secure reset link for your account.</p>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <?php if($test_link): ?>
                    <div class="test-link-box">
                        <strong>DEVELOPER TEST LINK:</strong><br>
                        <a href="<?= $test_link ?>"><?= $test_link ?></a>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <form method="post" action="">
                    <div class="input-group">
                        <label>System Username</label>
                        <input type="text" name="username" placeholder="Enter username" required autofocus>
                    </div>
                    <button type="submit">Generate Reset Link</button>
                </form>
            <?php endif; ?>
            
            <a class="footer-link" href="index.php"><i class="fas fa-arrow-left"></i> Return to Login</a>
        </div>
    </div>
</body>
</html>
