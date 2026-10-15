<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';

// Restrict access to admin and supervisor
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['user']['role'];

$floor_roles = ['reception', 'massage', 'barbering', 'facials', 'nails', 'salon'];
$management_roles = ['admin', 'supervisor'];

// Check if staff ID is provided
if (!isset($_GET['id'])) {
    header("Location: staff_management.php");
    exit;
}

$staff_id = (int)$_GET['id'];

// Fetch staff details
$stmt = $conn->prepare("SELECT * FROM staff WHERE id = ?");
$stmt->bind_param("i", $staff_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: staff_management.php");
    exit;
}

$staff = $result->fetch_assoc();
$is_self = $staff_id === (int)($_SESSION['user']['id'] ?? 0);

if ($user_role !== 'admin' && in_array($staff['role'], $management_roles, true) && !$is_self) {
    $_SESSION['error'] = 'Only an administrator can edit Admin or Supervisor accounts.';
    header("Location: staff_management.php");
    exit;
}

$assignable_roles = $user_role === 'admin'
    ? array_merge($floor_roles, $management_roles)
    : ($is_self ? [$staff['role']] : $floor_roles);
$role_options = [
    'admin' => 'System Admin',
    'supervisor' => 'Supervisor',
    'reception' => 'Frontdesk Officer',
    'massage' => 'Massage Therapist',
    'barbering' => 'Barbering Staff',
    'facials' => 'Facial Staff',
    'nails' => 'Nails Tech',
    'salon' => 'Salon Staff',
];

$error = '';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $username = trim($_POST['username']);
    $role = $_POST['role'] ?? '';
    $password = $_POST['password'];

    if (!in_array($role, $assignable_roles, true)) {
        $error = $user_role === 'admin'
            ? 'Please choose a valid role.'
            : 'Only an administrator can assign Admin or Supervisor roles.';
    } elseif ($staff['role'] === 'admin' && $role !== 'admin') {
        $admin_count = (int)($conn->query("SELECT COUNT(*) FROM staff WHERE role = 'admin'")->fetch_row()[0] ?? 0);
        if ($admin_count < 2) {
            $error = 'Cannot demote the last administrator.';
        }
    }

    if (empty($error)) {
        $check = $conn->prepare("SELECT id FROM staff WHERE username = ? AND id != ?");
        $check->bind_param("si", $username, $staff_id);
        $check->execute();
        $check_result = $check->get_result();

        if ($check_result->num_rows > 0) {
            $error = "Username already exists!";
        } elseif ($password !== '' && !is_password_complex($password, $error)) {
            // $error set by is_password_complex
        } else {
            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE staff SET name = ?, username = ?, password = ?, role = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $name, $username, $hashed, $role, $staff_id);
            } else {
                $stmt = $conn->prepare("UPDATE staff SET name = ?, username = ?, role = ? WHERE id = ?");
                $stmt->bind_param("sssi", $name, $username, $role, $staff_id);
            }

            if ($stmt->execute()) {
                $_SESSION['message'] = "Staff updated successfully!";
                header("Location: staff_management.php");
                exit;
            } else {
                $error = "Error updating staff.";
            }
        }
    }
}

$message = $_SESSION['message'] ?? '';
if (empty($error)) {
    $error = $_SESSION['error'] ?? '';
}
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Edit Staff Profile | The Breeze Spa</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
  <style>
    :root {
        --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
        --text-main: #1e293b; --text-muted: #475569; --border-color: #e2e8f0;
        --sidebar-width: 250px; --topbar-height: 60px;
        --danger-color: #dc2626; --success-color: #10b981;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; overflow-x: hidden; }

    /* FLAT SIDEBAR */
    .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; border-right: 1px solid #1e293b; }
    .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; display: flex; align-items: center; justify-content: center; }
    .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
    .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; font-size: 14px; }
    .user-profile h4 { color: #ffffff; font-weight: 600; margin-bottom: 4px; }
    .user-profile p { color: #94a3b8; font-size: 12px; text-transform: uppercase; }

    .sidebar-menu { padding: 20px 0; flex: 1; }
    .menu-item { display: flex; align-items: center; padding: 12px 24px; color: #cbd5e1; text-decoration: none; font-size: 14px; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; }
    .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
    .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
    .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }

    .logout-btn { display: flex; align-items: center; padding: 15px 24px; color: #ef4444; text-decoration: none; font-size: 14px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
    .logout-btn i { margin-right: 10px; }
    .logout-btn:hover { background-color: #ef4444; color: #ffffff; }

    /* MAIN CONTENT */
    .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; }
    .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
    .topbar h2 { font-size: 18px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 15px; }
    .topbar .meta { font-size: 13px; color: var(--text-muted); font-weight: 500; }
    .content-wrapper { padding: 30px; flex: 1; max-width: 600px; margin: 0 auto; width: 100%; }

    .alert { padding: 16px 20px; border-radius: 2px; margin-bottom: 25px; font-size: 15px; font-weight: 500; border-left: 4px solid; background: var(--bg-card); box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 10px; }
    .alert-error { color: #991b1b; border-left-color: var(--danger-color); background: #fef2f2; }
    .alert-success { color: #065f46; border-left-color: var(--success-color); background: #ecfdf5; }

    .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; margin-bottom: 30px; border-radius: 0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
    .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 25px; }
    .flat-card-header h3 { font-size: 16px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; }

    .form-group { display: flex; flex-direction: column; margin-bottom: 20px; }
    .form-group label { font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; }
    .form-control { padding: 12px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 15px; background: #f8fafc; outline: none; transition: border-color 0.2s; width: 100%; }
    .form-control:focus { border-color: var(--brand-blue); background: #ffffff; }

    .form-hint { font-size: 12px; color: var(--text-muted); margin-top: 6px; }

    .btn { padding: 12px 24px; font-size: 15px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; transition: background-color 0.2s; }
    .btn-primary { background: var(--brand-blue); color: #fff; }
    .btn-primary:hover { background: #0284c7; }
    .btn-outline { background: transparent; border: 1px solid var(--border-color); color: var(--text-main); }
    .btn-outline:hover { background: #f1f5f9; }
    .btn-sm { padding: 8px 14px; font-size: 13px; }

    .action-group { display: flex; gap: 15px; margin-top: 25px; }

    @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; } }
  </style>
</head>
<body>

  <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo">
        </div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><?= htmlspecialchars($user_role) ?></p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
            <a href="staff_management.php" class="menu-item active"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
            <a href="password_requests.php" class="menu-item"><i class="fas fa-key"></i> Password Requests</a>
            <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            <?php if ($user_role === 'admin' || $user_role === 'supervisor'): ?>
            <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
            <?php endif; ?>
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="reports.php" class="menu-item"><i class="fas fa-file-invoice-dollar"></i> Reports</a>
            <a href="till.php" class="menu-item"><i class="fas fa-cash-register"></i> Close of Day</a>
            <a href="clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
            <?php if ($user_role === 'admin'): ?>
                
            <a href="backup.php" class="menu-item"><i class="fas fa-database"></i> Database Backup</a>
            <a href="audit_logs.php" class="menu-item"><i class="fas fa-server"></i> System Audit Logs</a>
            <?php endif; ?>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Terminate Session</a>
    </aside>

  <main class="main-content">
    <header class="topbar">
        <h2>
            <a href="staff_management.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
            Edit Staff Profile
        </h2>
        <div class="meta">Staff ID: <?= htmlspecialchars($staff['id']) ?></div>
    </header>

    <div class="content-wrapper">
      <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="flat-card">
        <div class="flat-card-header"><h3>Staff Configuration</h3></div>

        <form method="post">
<?= csrf_field() ?>
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" id="name" class="form-control" value="<?= htmlspecialchars($staff['name']) ?>" required>
            </div>

            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" id="username" class="form-control" value="<?= htmlspecialchars($staff['username']) ?>" required>
            </div>

            <div class="form-group">
                <label>Role / Position</label>
                <select name="role" id="role" class="form-control" required>
                    <option value="">Select Role</option>
                    <?php foreach ($role_options as $value => $label): ?>
                        <?php if (in_array($value, $assignable_roles, true)): ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= $staff['role'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="Leave blank to keep current">
                <span class="form-hint">Leave blank to keep the current password unchanged.</span>
            </div>

            <div class="action-group">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                <a href="staff_management.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
      </div>
    </div>
  </main>
</body>
</html>
