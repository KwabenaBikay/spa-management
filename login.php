<?php
session_start();
include 'db.php';

$error = '';

// If already logged in, redirect based on role
if (isset($_SESSION['user']) && isset($_SESSION['user']['role'])) {
    $role = $_SESSION['user']['role'];
    if ($role === 'admin' || $role === 'supervisor') {
        header('Location: admin/dashboard.php');
        exit;
    } elseif ($role === 'reception' || $role === 'frontdesk') {
        header('Location: frontdesk/dashboard.php');
        exit;
    } elseif ($role === 'therapist' || $role === 'massage') {
        header('Location: sections/massage.php');
        exit;
    } else {
        header('Location: sections/' . $role . '.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $sql = "SELECT * FROM staff WHERE username = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // 🚫 Block admin from logging in through staff portal
        if ($user['role'] === 'admin') {
            $error = "Admins must log in through the admin login page.";
        } elseif (password_verify($password, $user['password']) || $password === $user['password']) {
            $_SESSION['user'] = $user;
            $role = $user['role'];

            // Redirect based on role
            if ($role === 'admin' || $role === 'supervisor') {
                header("Location: admin/dashboard.php");
            } elseif ($role === 'reception' || $role === 'frontdesk') {
                header("Location: frontdesk/dashboard.php");
            } elseif ($role === 'therapist' || $role === 'massage') {
                header("Location: sections/massage.php");
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
<html>
<head>
    <meta charset="UTF-8">
    <title>Login - Spa Management</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container">
    <h2>User Login</h2>
    
    <?php if ($error): ?>
        <p style="color: salmon;"><?= $error ?></p>
    <?php endif; ?>
    <form method="post" action="">
        <input type="text" name="username" placeholder="Username" required><br>
        <input type="password" name="password" placeholder="Password" required><br>
        <button type="submit">Login</button>
    </form>
</div>
</body>
</html>
