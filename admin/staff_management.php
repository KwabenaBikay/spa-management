<?php
session_start();
include '../db.php';

// Restrict access to admin and supervisor
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header("Location: ../login.php");
    exit;
}

$user_role = $_SESSION['user']['role'];

// Add new staff
if (isset($_POST['add_staff'])) {
    $name = trim($_POST['name']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role = $_POST['role'];

    // Check for duplicate username
    $check = $conn->prepare("SELECT id FROM staff WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $_SESSION['error'] = "Username already exists!";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO staff (name, username, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $name, $username, $hashed, $role);
        if ($stmt->execute()) {
            $inserted_id = $conn->insert_id;
            $_SESSION['message'] = "Staff added successfully!";
            
            // Audit log
            $conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
              id INT AUTO_INCREMENT PRIMARY KEY,
              user_id INT NULL,
              username VARCHAR(255) NULL,
              role VARCHAR(50) NULL,
              action VARCHAR(50) NOT NULL,
              entity VARCHAR(50) NOT NULL,
              entity_id INT NOT NULL,
              before_data TEXT NULL,
              after_data TEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $after = [
              'id' => $inserted_id,
              'name' => $name,
              'username' => $username,
              'role' => $role
            ];
            $userId = (int)($_SESSION['user']['id'] ?? 0);
            $logUsername = $_SESSION['user']['username'] ?? '';
            $userRole = $_SESSION['user']['role'] ?? '';
            if ($log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, entity, entity_id, before_data, after_data, created_at) VALUES (?,?,?,?,?,?,?,?, NOW())")) {
              $action = 'create'; $entity = 'staff';
              $beforeJson = null; $afterJson = json_encode($after);
              $log->bind_param('issssiss', $userId, $logUsername, $userRole, $action, $entity, $inserted_id, $beforeJson, $afterJson);
              $log->execute();
            }
        } else {
            $_SESSION['error'] = "Error adding staff.";
        }
    }
    header("Location: staff_management.php");
    exit;
}

// Delete staff
if (isset($_POST['delete_staff'])) {
    $id = $_POST['staff_id'];
    if ($id == $_SESSION['user']['id']) {
        $_SESSION['error'] = "You cannot delete your own account!";
    } else {
        // Fetch before snapshot for audit
        $before = null;
        if ($stmtSel = $conn->prepare("SELECT * FROM staff WHERE id = ?")) { 
            $stmtSel->bind_param('i', $id); 
            $stmtSel->execute(); 
            $beforeRes = $stmtSel->get_result(); 
            $before = $beforeRes ? $beforeRes->fetch_assoc() : null; 
        }
        
        $stmt = $conn->prepare("DELETE FROM staff WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $_SESSION['message'] = "Staff deleted successfully!";
        
        // Audit log
        $conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NULL,
          username VARCHAR(255) NULL,
          role VARCHAR(50) NULL,
          action VARCHAR(50) NOT NULL,
          entity VARCHAR(50) NOT NULL,
          entity_id INT NOT NULL,
          before_data TEXT NULL,
          after_data TEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $userId = (int)($_SESSION['user']['id'] ?? 0);
        $logUsername = $_SESSION['user']['username'] ?? '';
        $userRole = $_SESSION['user']['role'] ?? '';
        $beforeJson = $before ? json_encode($before) : null;
        $afterJson = null;
        if ($log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, entity, entity_id, before_data, after_data, created_at) VALUES (?,?,?,?,?,?,?,?, NOW())")) {
          $action = 'delete'; $entity = 'staff';
          $log->bind_param('issssiss', $userId, $logUsername, $userRole, $action, $entity, $id, $beforeJson, $afterJson);
          $log->execute();
        }
    }
    header("Location: staff_management.php");
    exit;
}

$staff_result = $conn->query("SELECT * FROM staff ORDER BY role ASC, name ASC");

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Staff Roster | The Breeze Spa</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
  <style>
    :root {
        --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
        --text-main: #1e293b; --text-muted: #64748b; --border-color: #e2e8f0;
        --sidebar-width: 250px; --topbar-height: 60px;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
    body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; overflow-x: hidden; font-size: 15px; }

    .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; border-right: 1px solid #1e293b; }
    .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; display: flex; align-items: center; gap: 15px; }
    .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
    .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; }
    .user-profile h4 { color: #ffffff; font-size: 15px; font-weight: 600; margin-bottom: 4px; }
    .user-profile p { color: #94a3b8; font-size: 12px; text-transform: uppercase; }
    .sidebar-menu { padding: 20px 0; flex: 1; }
    .menu-item { display: flex; align-items: center; padding: 12px 24px; color: #cbd5e1; text-decoration: none; font-size: 14px; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; }
    .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
    .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
    .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
    .logout-btn { display: flex; align-items: center; padding: 15px 24px; color: #ef4444; text-decoration: none; font-size: 14px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
    .logout-btn i { margin-right: 10px; }
    .logout-btn:hover { background-color: #ef4444; color: #ffffff; }

    .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; }
    .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
    .topbar h2 { font-size: 18px; font-weight: 600; color: var(--text-main); }
    .content-wrapper { padding: 30px; flex: 1; max-width: 1200px; margin: 0 auto; width: 100%;}

    .alert { padding: 15px 20px; border-radius: 2px; margin-bottom: 25px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px; border-left: 4px solid; }
    .alert-success { background: #d1fae5; color: #065f46; border-left-color: #10b981; }
    .alert-error { background: #fee2e2; color: #991b1b; border-left-color: #dc2626; }

    .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; margin-bottom: 30px; border-radius: 0; }
    .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
    .flat-card-header h3 { font-size: 16px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }

    .form-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: center; }
    .form-control { flex: 1; min-width: 180px; padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 14px; background: #f8fafc; outline: none; transition: all 0.2s; }
    .form-control:focus { border-color: var(--brand-blue); background: #ffffff; }
    
    .btn { padding: 10px 18px; font-size: 14px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; transition: all 0.2s; }
    .btn-primary { background: var(--brand-blue); color: #fff; }
    .btn-primary:hover { background: #0284c7; }
    .btn-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
    .btn-danger:hover { background: #fee2e2; }
    .btn-outline { background: #f8fafc; border: 1px solid var(--border-color); color: var(--text-main); }
    .btn-sm { padding: 6px 12px; font-size: 13px; }

    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 14px; text-align: left; border-bottom: 1px solid var(--border-color); }
    th { background: #f8fafc; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }

    .badge { display: inline-block; padding: 4px 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; border-radius: 2px; border: 1px solid; }
    .badge-admin { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
    .badge-reception { background: #fef3c7; color: #d97706; border-color: #fde68a; }
    .badge-supervisor { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    .badge-massage { background: #fdf2f8; color: #db2777; border-color: #fbcfe8; }
    .badge-therapist { background: #faf5ff; color: #7c3aed; border-color: #e9d5ff; }
    .badge-barbering { background: #ecfeff; color: #0891b2; border-color: #cffafe; }
    .badge-facials { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
    .badge-nails { background: #f5f3ff; color: #6d28d9; border-color: #ddd6fe; }
    .badge-salon { background: #f8fafc; color: #475569; border-color: #e2e8f0; }

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
            <p><i class="fas fa-shield-alt" style="margin-right: 5px; font-size: 10px;"></i> <?= htmlspecialchars($user_role) ?></p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
            <a href="staff_management.php" class="menu-item active"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
            <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            
            <?php if ($user_role === 'admin' || $user_role === 'supervisor'): ?>
            <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
            <?php endif; ?>
            
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="reports.php" class="menu-item"><i class="fas fa-file-invoice-dollar"></i> Financial Reports</a>
            <a href="clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
            
            <?php if ($user_role === 'admin'): ?>
            <a href="audit_logs.php" class="menu-item"><i class="fas fa-server"></i> System Audit Logs</a>
            <?php endif; ?>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Terminate Session</a>
    </aside>

  <main class="main-content">
    <header class="topbar">
        <h2>Staff Directory</h2>
    </header>

    <div class="content-wrapper">
      <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="flat-card">
        <div class="flat-card-header"><h3>Add New Staff Member</h3></div>
        <form method="post">
          <div class="form-row">
            <input type="text" name="name" placeholder="Full Name" class="form-control" required>
            <input type="text" name="username" placeholder="Username" class="form-control" required>
            <input type="password" name="password" placeholder="Password" class="form-control" required>
            <select name="role" class="form-control" required>
              <option value="">Select Role</option>
              <option value="admin">System Admin</option>
              <option value="reception">Frontdesk Officer</option>
              <option value="supervisor">Supervisor</option>
              <option value="massage">Massage Therapist</option>
              <option value="barbering">Barbering Staff</option>
              <option value="facials">Facial Staff</option>
              <option value="nails">Nails Tech</option>
              <option value="salon">Salon Staff</option>
            </select>
            <button type="submit" name="add_staff" class="btn btn-primary">
              <i class="fas fa-plus"></i> Add Staff
            </button>
          </div>
        </form>
      </div>

      <div class="flat-card">
        <div class="flat-card-header"><h3>Staff Members List</h3></div>
        <div style="overflow-x: auto;">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($staff = $staff_result->fetch_assoc()): ?>
              <tr>
                <td style="font-weight: 500;"><?= htmlspecialchars($staff['name']) ?></td>
                <td><?= htmlspecialchars($staff['username']) ?></td>
                <td>
                  <?php
                    $cls = match ($staff['role']) {
                      'admin' => 'badge-admin',
                      'reception' => 'badge-reception',
                      'supervisor' => 'badge-supervisor',
                      'massage' => 'badge-massage',
                      'therapist' => 'badge-therapist',
                      'barbering' => 'badge-barbering',
                      'facials' => 'badge-facials',
                      'nails' => 'badge-nails',
                      'salon' => 'badge-salon',
                      default => 'badge-salon'
                    };
                    $labelText = match ($staff['role']) {
                      'admin' => 'System Admin',
                      'reception' => 'Frontdesk Officer',
                      'supervisor' => 'Supervisor',
                      'massage' => 'Massage Therapist',
                      'therapist' => 'Therapist',
                      'barbering' => 'Barbering Staff',
                      'facials' => 'Facial Staff',
                      'nails' => 'Nails Tech',
                      'salon' => 'Salon Staff',
                      default => ucfirst($staff['role'])
                    };
                  ?>
                  <span class="badge <?= $cls ?>"><?= htmlspecialchars($labelText) ?></span>
                </td>
                <td>
                  <a href="edit_staff.php?id=<?= $staff['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                  <?php if ($staff['id'] != $_SESSION['user']['id']): ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this staff record?')">
                    <input type="hidden" name="staff_id" value="<?= $staff['id'] ?>">
                    <button type="submit" name="delete_staff" class="btn btn-danger btn-sm">Delete</button>
                  </form>
                  <?php else: ?>
                    <button class="btn btn-outline btn-sm" disabled style="opacity: 0.5;">Current User</button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
