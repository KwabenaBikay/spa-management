<?php
require_once __DIR__ . '/includes/session.php';
include 'db.php';
include 'includes/password_requests.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    record_password_request($conn, (string)($_POST['username'] ?? ''));
    $success = 'Your request has been sent. An administrator will set a new password and give it to you.';
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

    </style>
</head>
<body>
    <div class="overlay">
        <div class="login-card">
            <img src="assets/images/The-Breeze-1.png" class="logo" alt="Logo" onerror="this.style.display='none'">
            <h2>Password Recovery</h2>
            <p>Enter your system username. The administrator will see the request and set a new password for you.</p>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php else: ?>
                <form method="post" action="">
<?= csrf_field() ?>
                    <div class="input-group">
                        <label>System Username</label>
                        <input type="text" name="username" placeholder="Enter username" required autofocus>
                    </div>
                    <button type="submit">Request help</button>
                </form>
            <?php endif; ?>
            
            <a class="footer-link" href="index.php"><i class="fas fa-arrow-left"></i> Return to Login</a>
        </div>
    </div>
</body>
</html>
