<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';
include '../includes/password_requests.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'], true)) {
    header('Location: login.php');
    exit;
}

ensure_password_requests_table($conn);

$user_role = (string)$_SESSION['user']['role'];
$management_roles = ['admin', 'supervisor'];
$role_labels = [
    'admin' => 'System Admin',
    'supervisor' => 'Supervisor',
    'reception' => 'Frontdesk Officer',
    'massage' => 'Massage Therapist',
    'barbering' => 'Barbering Staff',
    'facials' => 'Facial Staff',
    'nails' => 'Nails Tech',
    'salon' => 'Salon Staff',
    'therapist' => 'Massage Therapist',
];

$view = ($_GET['view'] ?? 'pending') === 'done' ? 'done' : 'pending';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['set_password'])) {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $password = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $stmt = $conn->prepare("SELECT r.id, r.username, s.id AS staff_id, s.role, s.name
        FROM password_requests r
        JOIN staff s ON s.id = r.staff_id
        WHERE r.id = ? AND r.status = 'pending'
        LIMIT 1");
    $request = null;
    if ($stmt) {
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
    }

    if (!$request) {
        $_SESSION['error'] = 'That request is no longer waiting.';
    } elseif (in_array($request['role'], $management_roles, true) && $user_role !== 'admin') {
        $_SESSION['error'] = 'Only an administrator can set a password for Admin or Supervisor accounts.';
    } elseif (!is_password_complex($password, $pwErr)) {
        $_SESSION['error'] = $pwErr;
    } elseif ($password !== $confirm) {
        $_SESSION['error'] = 'The two passwords do not match.';
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $staff_id = (int)$request['staff_id'];
        $update = $conn->prepare('UPDATE staff SET password = ? WHERE id = ?');
        $update->bind_param('si', $hashed, $staff_id);
        if ($update->execute()) {
            $resolver = (string)($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? 'Administrator');
            $done = $conn->prepare("UPDATE password_requests SET status = 'done', resolved_at = NOW(), resolved_by = ? WHERE id = ?");
            $done->bind_param('si', $resolver, $request_id);
            $done->execute();
            login_clear_failures((string)$request['username']);

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
            $actor_id = (int)($_SESSION['user']['id'] ?? 0);
            $actor_name = (string)($_SESSION['user']['username'] ?? '');
            $after = json_encode(['username' => $request['username'], 'request_id' => $request_id]);
            if ($log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, entity, entity_id, after_data, created_at) VALUES (?,?,?, 'update', 'staff', ?, ?, NOW())")) {
                $log->bind_param('issis', $actor_id, $actor_name, $user_role, $staff_id, $after);
                $log->execute();
            }

            $_SESSION['message'] = 'Password set for ' . $request['name'] . '. Tell them the new password in person.';
            $view = 'pending';
        } else {
            $_SESSION['error'] = 'The password could not be saved.';
        }
    }

    header('Location: password_requests.php?view=' . urlencode($view));
    exit;
}

$counts = ['pending' => 0, 'done' => 0];
$count_res = $conn->query("SELECT status, COUNT(*) AS c FROM password_requests GROUP BY status");
if ($count_res) {
    while ($row = $count_res->fetch_assoc()) {
        $status = (string)$row['status'];
        if (isset($counts[$status])) {
            $counts[$status] = (int)$row['c'];
        }
    }
}

$list = $conn->prepare("SELECT r.id, r.username, r.requested_at, r.resolved_at, r.resolved_by, s.name, s.role
    FROM password_requests r
    JOIN staff s ON s.id = r.staff_id
    WHERE r.status = ?
    ORDER BY " . ($view === 'done' ? 'r.resolved_at DESC' : 'r.requested_at ASC'));
$rows = [];
if ($list) {
    $list->bind_param('s', $view);
    $list->execute();
    $result = $list->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Password Requests | The Breeze Spa</title>
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
    .sidebar-menu { padding: 20px 0; flex: 1; overflow-y: auto; }
    .menu-item { display: flex; align-items: center; padding: 12px 24px; color: #cbd5e1; text-decoration: none; font-size: 14px; font-weight: 500; border-left: 3px solid transparent; }
    .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
    .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
    .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
    .logout-btn { display: flex; align-items: center; padding: 15px 24px; color: #ef4444; text-decoration: none; font-size: 14px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
    .logout-btn i { margin-right: 10px; }
    .logout-btn:hover { background-color: #ef4444; color: #ffffff; }
    .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; }
    .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
    .topbar h2 { font-size: 18px; font-weight: 600; }
    .content-wrapper { padding: 30px; flex: 1; }
    .alert { padding: 15px 20px; margin-bottom: 25px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px; border-left: 4px solid; }
    .alert-success { background: #d1fae5; color: #065f46; border-left-color: #10b981; }
    .alert-error { background: #fee2e2; color: #991b1b; border-left-color: #dc2626; }
    .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; }
    .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
    .flat-card-header h3 { font-size: 16px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .diary-tabs { display: flex; border: 1px solid var(--border-color); }
    .diary-tab { padding: 8px 14px; font-size: 13px; font-weight: 700; color: var(--text-muted); text-decoration: none; background: #fff; }
    .diary-tab.active { background: var(--brand-dark); color: #fff; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 14px; text-align: left; border-bottom: 1px solid var(--border-color); vertical-align: middle; }
    th { background: #f8fafc; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }
    .meta { display: block; margin-top: 4px; color: var(--text-muted); font-size: 13px; }
    .set-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .form-control { padding: 8px 10px; border: 1px solid var(--border-color); background: #f8fafc; font-size: 14px; min-width: 150px; }
    .btn { padding: 8px 14px; font-size: 13px; font-weight: 700; border: none; cursor: pointer; background: var(--brand-blue); color: #fff; }
    .btn:hover { background: #0284c7; }
    .locked { color: var(--text-muted); font-size: 13px; font-weight: 600; }
    @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; } }
  </style>
</head>
<body>
  <aside class="sidebar">
    <div class="sidebar-header">
      <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo">
    </div>
    <div class="user-profile">
      <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
      <p><?= htmlspecialchars($user_role) ?></p>
    </div>
    <nav class="sidebar-menu">
      <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
      <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
      <a href="password_requests.php" class="menu-item active"><i class="fas fa-key"></i> Password Requests</a>
      <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
      <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
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
      <h2>Password Requests</h2>
      <div><?= date('l, F j, Y') ?></div>
    </header>
    <div class="content-wrapper">
      <?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div class="flat-card">
        <div class="flat-card-header">
          <h3><?= $view === 'done' ? 'Completed' : 'Waiting' ?></h3>
          <div class="diary-tabs">
            <a class="diary-tab<?= $view === 'pending' ? ' active' : '' ?>" href="password_requests.php?view=pending">Waiting (<?= (int)$counts['pending'] ?>)</a>
            <a class="diary-tab<?= $view === 'done' ? ' active' : '' ?>" href="password_requests.php?view=done">Done (<?= (int)$counts['done'] ?>)</a>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table>
            <thead>
              <tr>
                <th>Staff</th>
                <th>Requested</th>
                <th><?= $view === 'done' ? 'Set by' : 'New password' ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row):
                  $role = (string)($row['role'] ?? '');
                  $can_set = $view === 'pending' && ($user_role === 'admin' || !in_array($role, $management_roles, true));
                  $when = $view === 'done' ? ($row['resolved_at'] ?: $row['requested_at']) : $row['requested_at'];
              ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($row['name'] ?? '') ?></strong>
                  <span class="meta"><?= htmlspecialchars($row['username'] ?? '') ?> · <?= htmlspecialchars($role_labels[$role] ?? ucfirst($role)) ?></span>
                </td>
                <td><?= $when ? htmlspecialchars(date('d M Y, h:i A', strtotime($when))) : '—' ?>
                  <?php if ($view === 'done' && !empty($row['requested_at'])): ?>
                  <span class="meta">Asked <?= htmlspecialchars(date('d M Y, h:i A', strtotime($row['requested_at']))) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($can_set): ?>
                  <form method="post" class="set-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="set_password" value="1">
                    <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
                    <input type="password" name="new_password" class="form-control" placeholder="New password" minlength="10" required>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Confirm" minlength="10" required>
                    <button type="submit" class="btn">Set password</button>
                  </form>
                  <?php elseif ($view === 'pending'): ?>
                  <span class="locked">Only an administrator can set this password.</span>
                  <?php else: ?>
                  <?= htmlspecialchars($row['resolved_by'] ?: '—') ?>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
              <tr><td colspan="3" style="text-align:center; color:var(--text-muted); padding:36px;"><?= $view === 'done' ? 'No completed requests yet.' : 'No one is waiting for a new password.' ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
