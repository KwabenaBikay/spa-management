<?php
session_start();
include 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $sql = "SELECT * FROM staff WHERE username = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Prevent admins from using the general login page
        if ($user['role'] === 'admin') {
            $error = "Admins must log in via the admin login page.";
        }
        // Check password (handles both hashed and plain text)
        elseif (password_verify($password, $user['password']) || $password === $user['password']) {
            $_SESSION['user'] = $user;
            $role = $user['role'];

            if ($role === 'frontdesk' || $role === 'reception') {
                header("Location: frontdesk/dashboard.php");
            } elseif ($role === 'admin' || $role === 'supervisor') {
                header("Location: admin/dashboard.php");
            } else {
                header("Location: sections/{$role}.php");
            }
            exit;
        } else {
            $error = "Invalid password.";
        }
    } else {
        $error = "User not found.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | The Breeze Spa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #ffffff; 
            overflow-x: hidden; /* Prevents horizontal scrolling */
        }

        /* Full-Screen Split Container */
        .login-wrapper {
            display: flex;
            width: 100vw; /* Stretches to full screen width */
            min-height: 100vh; /* Stretches to full screen height */
        }

        /* Left Side: Solid White Form Panel */
        .form-panel {
            flex: 1; /* Takes up 50% of the screen */
            display: flex;
            justify-content: center; /* Centers the inner content */
            align-items: center; 
            background-color: #ffffff;
            padding: 40px;
        }

        /* Inner container to keep inputs perfectly sized */
        .form-content {
            width: 100%;
            max-width: 450px; 
        }

        .form-header {
            margin-bottom: 40px;
        }

        .form-header h2 {
            color: #0f172a;
            font-size: 36px; 
            font-weight: 700;
            margin-bottom: 8px;
        }

        .form-header p {
            color: #64748b;
            font-size: 15px;
        }

        .error-message {
            background-color: #fef2f2;
            color: #dc2626;
            padding: 12px;
            border-left: 4px solid #dc2626;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .form-group {
            margin-bottom: 25px; 
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 10px;
        }

        .input-container {
            position: relative;
        }

        .input-container i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 15px;
        }

        .input-container input {
            width: 100%;
            padding: 14px 16px 14px 45px; 
            border: 1px solid #cbd5e1;
            border-radius: 4px; 
            font-size: 15px;
            color: #334155;
            outline: none;
            transition: all 0.2s;
        }

        .input-container input:focus {
            border-color: #0ea5e9; 
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        .btn-submit {
            background-color: #0ea5e9; 
            color: #ffffff;
            border: none;
            padding: 14px 30px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 15px;
            transition: background-color 0.2s;
            width: 100%; 
        }

        .btn-submit:hover {
            background-color: #0284c7; 
        }

        /* Links */
        .forgot-link { display: block; text-align: right; color: #0ea5e9; font-size: 13px; font-weight: 600; text-decoration: none; margin-top: 8px; }
        .forgot-link:hover { text-decoration: underline; }

        /* Right Side: Sea Blue Pattern Wall */
        .brand-panel {
            flex: 1; /* Takes up the other 50% */
            background-color: #0ea5e9; 
            background-image: url("data:image/svg+xml,%3Csvg width='52' height='26' viewBox='0 0 52 26' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.12'%3E%3Cpath d='M10 10c0-2.21-1.79-4-4-4-3.314 0-6-2.686-6-6h2c0 2.21 1.79 4 4 4 3.314 0 6 2.686 6 6 0 2.21 1.79 4 4 4 3.314 0 6 2.686 6 6 0 2.21 1.79 4 4 4v2c-3.314 0-6-2.686-6-6 0-2.21-1.79-4-4-4-3.314 0-6-2.686-6-6zm25.464-1.95l8.486 8.486-1.414 1.414-8.486-8.486 1.414-1.414z' /%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 60px;
            text-align: center;
            color: #ffffff;
        }

        .brand-logo {
            max-height: 160px;
            width: auto;
            margin-bottom: 30px;
            object-fit: contain;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.15));
        }

        .brand-panel h1 {
            font-size: 46px; 
            font-weight: 700;
            margin-bottom: 20px;
            letter-spacing: 0.5px;
        }

        .brand-panel p {
            font-size: 18px; 
            font-style: italic;
            font-weight: 300;
            color: rgba(255, 255, 255, 0.9);
        }

        /* Responsive Breakpoint */
        @media (max-width: 900px) {
            .login-wrapper {
                flex-direction: column;
            }
            .brand-panel {
                display: none; /* Hides the blue side on mobile so form fits */
            }
            .form-panel {
                min-height: 100vh;
            }
        }
    </style>
</head>
<body>

    <div class="login-wrapper">
        
        <div class="form-panel">
            <div class="form-content">
                <div class="form-header">
                    <h2>Sign In</h2>
                    <p>Enter your credentials to access your dashboard</p>
                </div>

                <?php if ($error): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-container">
                            <i class="fas fa-user"></i>
                            <input type="text" id="username" name="username" placeholder="Username" required autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-container">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password" placeholder="Password" required>
                        </div>
                        <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn-submit">Sign In</button>
                </form>
            </div>
        </div>

        <div class="brand-panel">
            <img class="brand-logo" src="assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" onerror="this.style.display='none';">
            <h1>The Breeze Spa</h1>
            <p>"A tranquil experience through rejuvenating hands."</p>
        </div>

    </div>

</body>
</html>