<?php
session_start();
include '../db.php';

if (isset($_SESSION['user']) && isset($_SESSION['user']['role']) && in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM staff WHERE username = ? AND role IN ('admin', 'supervisor') LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password']) || $password === $user['password']) {
                $_SESSION['user'] = $user;
                header("Location: dashboard.php");
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        } else {
            $error = 'Invalid username or password. (Access restricted to Management)';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management Login | The Breeze Spa</title>
    <style>
        body, html { margin:0; padding:0; height: 100%; font-family: "Segoe UI", sans-serif; }
        body { 
            background: url('../assets/images/slide5.jpg') no-repeat center center fixed; 
            background-size: cover;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .overlay { 
            background: rgba(0, 0, 0, 0.4); 
            position: absolute; 
            top: 0; left: 0; right: 0; bottom: 0; 
            display: flex; 
            align-items: center; 
            justify-content: center;
            padding: 20px;
        }
        .login-card { 
            background: #ffffff; 
            width: 100%; 
            max-width: 420px; 
            padding: 40px; 
            border-radius: 8px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.2); 
            text-align: center;
        }
        .logo { max-width: 150px; margin-bottom: 20px; }
        h2 { font-size: 22px; color: #1e293b; margin-bottom: 5px; }
        .motto { color: #64748b; font-size: 14px; font-style: italic; margin-bottom: 30px; }
        
        .input-group { text-align: left; margin-bottom: 15px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 5px; }
        input { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 15px; box-sizing: border-box; }
        input:focus { border-color: #0ea5e9; outline: none; box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1); }
        
        button { width: 100%; padding: 12px; background:#0ea5e9; color:#fff; border:none; border-radius:4px; font-weight:600; cursor:pointer; margin-top:10px; font-size: 16px; }
        button:hover { background:#0284c7; }
        
        .error { color:#dc2626; background:#fef2f2; padding:10px; border-radius:4px; margin-bottom:20px; font-size: 14px; }
        .footer-link { display:block; margin-top:20px; color:#64748b; text-decoration:none; font-size: 13px; }
        .footer-link:hover { color: #0ea5e9; }
    </style>
</head>
<body>
    <div class="overlay">
        <div class="login-card">
            <img src="../assets/images/The-Breeze-1.png" class="logo" alt="Logo">
            <h2>Management Portal</h2>
            <p class="motto">"A tranquil experience through rejuvenating hands."</p>
            
            <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            
            <form method="post" action="">
                <div class="input-group">
                    <label>Username</label>
                    <input type="text" name="username" required autofocus>
                </div>
                <div class="input-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <div style="text-align: right; margin-top: -5px; margin-bottom: 15px;">
                    <a href="forgot_password.php" style="color: #0ea5e9; font-size: 13px; text-decoration: none; font-weight: 500;">Forgot Password?</a>
                </div>
                <button type="submit">LOG IN</button>
            </form>
            <a class="footer-link" href="../index.php">Return to Floor Staff Login</a>
        </div>
    </div>
</body>
</html>