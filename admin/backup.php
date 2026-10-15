<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';

if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

if (isset($_GET['download'])) {
    $path = backup_file_path((string)$_GET['download']);
    if (!$path) {
        http_response_code(404);
        echo 'Backup not found.';
        exit;
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['create_backup'])) {
    try {
        $file = create_database_backup($conn);
        $_SESSION['message'] = 'Backup saved: ' . basename($file);
    } catch (Throwable $e) {
        $_SESSION['error'] = 'The backup could not be created.';
    }
    header('Location: backup.php');
    exit;
}

$backups = list_database_backups();
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Database Backup | The Breeze Spa</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root { --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --text-main: #1e293b; --text-muted: #64748b; --border-color: #e2e8f0; --sidebar-width: 250px; }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
    body { background: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; }
    .sidebar { width: var(--sidebar-width); background: var(--brand-dark); color: #fff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; }
    .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; }
    .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
    .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; }
    .user-profile p { color: #94a3b8; font-size: 12px; text-transform: uppercase; }
    .sidebar-menu { padding: 20px 0; flex: 1; overflow-y: auto; }
    .menu-item { display: flex; align-items: center; padding: 12px 24px; color: #cbd5e1; text-decoration: none; font-size: 14px; font-weight: 500; border-left: 3px solid transparent; }
    .menu-item i { width: 24px; margin-right: 10px; color: #64748b; }
    .menu-item:hover, .menu-item.active { background: #1e293b; color: #fff; border-left-color: var(--brand-blue); }
    .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
    .logout-btn { display: flex; align-items: center; padding: 15px 24px; color: #ef4444; text-decoration: none; font-weight: 600; border-top: 1px solid #1e293b; background: #0b1221; }
    .main-content { flex: 1; margin-left: var(--sidebar-width); }
    .topbar { height: 60px; background: #fff; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; }
    .content-wrapper { padding: 30px; }
    .alert { padding: 14px 16px; margin-bottom: 16px; border-left: 4px solid; }
    .alert-success { background: #d1fae5; color: #065f46; border-left-color: #10b981; }
    .alert-error { background: #fee2e2; color: #991b1b; border-left-color: #dc2626; }
    .flat-card { background: #fff; border: 1px solid var(--border-color); padding: 28px; margin-bottom: 20px; }
    .lede { color: var(--text-muted); line-height: 1.6; margin: 12px 0 18px; }
    .btn { background: var(--brand-blue); color: #fff; border: none; padding: 10px 14px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
    code { background: #f8fafc; border: 1px solid var(--border-color); padding: 2px 6px; }
    table { width: 100%; border-collapse: collapse; margin-top: 18px; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color); }
    th { font-size: 12px; text-transform: uppercase; color: var(--text-muted); background: #f8fafc; }
    @media (max-width: 800px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; } }
  </style>
</head>
<body>
  <aside class="sidebar">
    <div class="sidebar-header"><img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo"></div>
    <div class="user-profile"><h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4><p>admin</p></div>
    <nav class="sidebar-menu">
      <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
      <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
      <a href="password_requests.php" class="menu-item"><i class="fas fa-key"></i> Password Requests</a>
      <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
      <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
      <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
      <a href="reports.php" class="menu-item"><i class="fas fa-file-invoice-dollar"></i> Reports</a>
      <a href="till.php" class="menu-item"><i class="fas fa-cash-register"></i> Close of Day</a>
      <a href="clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
      <a href="backup.php" class="menu-item active"><i class="fas fa-database"></i> Database Backup</a>
      <a href="audit_logs.php" class="menu-item"><i class="fas fa-server"></i> System Audit Logs</a>
    </nav>
    <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Terminate Session</a>
  </aside>
  <main class="main-content">
    <header class="topbar"><h2>Database Backup</h2><div><?= date('l, F j, Y') ?></div></header>
    <div class="content-wrapper">
      <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <div class="flat-card">
        <h3>Copy of the spa database</h3>
        <p class="lede">This stays on this computer while you are developing in XAMPP. On the aaPanel server, run the same copy once a day and keep it outside the website folder. The database password stays as it is until you deploy. Set <code>SPA_DB_HOST</code>, <code>SPA_DB_USER</code>, <code>SPA_DB_PASS</code>, and <code>SPA_DB_NAME</code> on the server. Optional <code>SPA_BACKUP_DIR</code> chooses where the files are stored.</p>
        <p class="lede">Daily command for aaPanel cron: <code>php /www/wwwroot/your-site/scripts/backup_db.php</code></p>
        <form method="post">
          <?= csrf_field() ?>
          <button type="submit" name="create_backup" value="1" class="btn">Create backup now</button>
        </form>
        <table>
          <thead><tr><th>File</th><th>When</th><th>Size</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($backups as $file): ?>
            <tr>
              <td><?= htmlspecialchars($file['name']) ?></td>
              <td><?= htmlspecialchars(date('d M Y, h:i A', $file['mtime'])) ?></td>
              <td><?= number_format($file['size'] / 1024, 1) ?> KB</td>
              <td><a class="btn" href="backup.php?download=<?= urlencode($file['name']) ?>">Download</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$backups): ?>
            <tr><td colspan="4" style="text-align:center; color:#64748b; padding:28px;">No backup yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</body>
</html>
