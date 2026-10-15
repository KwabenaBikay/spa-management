<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';
include '../includes/till_panel.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'], true)) {
    header('Location: login.php');
    exit;
}

$user_role = (string)$_SESSION['user']['role'];
$date = (string)($_POST['close_date'] ?? $_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
    $date = date('Y-m-d');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['close_till'])) {
    $errorText = save_till_close(
        $conn,
        $date,
        (float)($_POST['cash_counted'] ?? 0),
        (float)($_POST['momo_counted'] ?? 0),
        (string)($_POST['note'] ?? '')
    );
    if ($errorText === '') {
        $_SESSION['message'] = 'Close of day saved.';
    } else {
        $_SESSION['error'] = $errorText;
    }
    header('Location: till.php?date=' . urlencode($date));
    exit;
}

$expected = till_expected($conn, $date);
$history = fetch_till_closes($conn);
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Close of Day | The Breeze Spa</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root { --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #fff; --text-main: #1e293b; --text-muted: #64748b; --border-color: #e2e8f0; --sidebar-width: 250px; }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
    body { background: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; }
    .sidebar { width: var(--sidebar-width); background: var(--brand-dark); color: #fff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; }
    .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; }
    .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
    .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; }
    .user-profile h4 { font-size: 15px; }
    .user-profile p { color: #94a3b8; font-size: 12px; text-transform: uppercase; }
    .sidebar-menu { padding: 20px 0; flex: 1; overflow-y: auto; }
    .menu-item { display: flex; align-items: center; padding: 12px 24px; color: #cbd5e1; text-decoration: none; font-size: 14px; font-weight: 500; border-left: 3px solid transparent; }
    .menu-item i { width: 24px; margin-right: 10px; color: #64748b; }
    .menu-item:hover, .menu-item.active { background: #1e293b; color: #fff; border-left-color: var(--brand-blue); }
    .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
    .logout-btn { display: flex; align-items: center; padding: 15px 24px; color: #ef4444; text-decoration: none; font-weight: 600; border-top: 1px solid #1e293b; background: #0b1221; }
    .logout-btn i { margin-right: 10px; }
    .main-content { flex: 1; margin-left: var(--sidebar-width); }
    .topbar { height: 60px; background: #fff; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; }
    .content-wrapper { padding: 30px; }
    .alert { padding: 14px 16px; margin-bottom: 16px; border-left: 4px solid; }
    .alert-success { background: #d1fae5; color: #065f46; border-left-color: #10b981; }
    .alert-error { background: #fee2e2; color: #991b1b; border-left-color: #dc2626; }
    .flat-card { background: #fff; border: 1px solid var(--border-color); padding: 28px; margin-bottom: 20px; }
    .flat-card-header { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 14px; }
    .flat-card-header h3 { font-size: 14px; letter-spacing: 0.4px; text-transform: uppercase; }
    .lede { color: var(--text-muted); margin-bottom: 16px; line-height: 1.5; }
    .expect-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
    .expect-grid div { border: 1px solid var(--border-color); padding: 14px; }
    .expect-grid span { display: block; color: var(--text-muted); font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px; }
    .count-form, .date-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
    label { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 700; color: var(--text-muted); }
    input { padding: 10px; border: 1px solid var(--border-color); font-size: 15px; background: #f8fafc; }
    .btn { background: var(--brand-blue); color: #fff; border: none; padding: 10px 14px; font-weight: 700; cursor: pointer; }
    .variance { margin-bottom: 14px; font-weight: 700; }
    .variance.ok { color: #065f46; }
    .variance.off { color: #991b1b; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color); }
    th { font-size: 12px; text-transform: uppercase; color: var(--text-muted); background: #f8fafc; }
    .diff { display: block; color: var(--text-muted); font-size: 12px; font-weight: 600; }
    @media (max-width: 800px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; } .expect-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <aside class="sidebar">
    <div class="sidebar-header"><img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo"></div>
    <div class="user-profile"><h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4><p><?= htmlspecialchars($user_role) ?></p></div>
    <nav class="sidebar-menu">
      <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
      <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
      <a href="password_requests.php" class="menu-item"><i class="fas fa-key"></i> Password Requests</a>
      <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
      <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
      <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
      <a href="reports.php" class="menu-item"><i class="fas fa-file-invoice-dollar"></i> Reports</a>
      <a href="till.php" class="menu-item active"><i class="fas fa-cash-register"></i> Close of Day</a>
      <a href="clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
      <?php if ($user_role === 'admin'): ?>
      <a href="backup.php" class="menu-item"><i class="fas fa-database"></i> Database Backup</a>
      <a href="audit_logs.php" class="menu-item"><i class="fas fa-server"></i> System Audit Logs</a>
      <?php endif; ?>
    </nav>
    <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Terminate Session</a>
  </aside>
  <main class="main-content">
    <header class="topbar"><h2>Close of Day</h2><div><?= date('l, F j, Y') ?></div></header>
    <div class="content-wrapper">
      <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php render_till_panel($expected, $history, $date); ?>
    </div>
  </main>
</body>
</html>
