<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';

// RBAC: Allow Admin and Supervisor only
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['user']['role'];

$current_month = date('Y-m');
$net_amount_sql = client_net_amount_sql();

$total_transactions = 0;
$stmt_trans = $conn->prepare("SELECT COUNT(*) as count FROM clients WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND voided_at IS NULL");
if ($stmt_trans) {
    $stmt_trans->bind_param("s", $current_month);
    $stmt_trans->execute();
    $res = $stmt_trans->get_result();
    if ($res && $r = $res->fetch_assoc()) {
        $total_transactions = (int)($r['count'] ?? 0);
    }
}

$total_revenue = 0.0;
$stmt_rev = $conn->prepare("SELECT COALESCE(SUM($net_amount_sql),0) as total FROM clients WHERE DATE_FORMAT(created_at, '%Y-%m') = ?");
if ($stmt_rev) {
    $stmt_rev->bind_param("s", $current_month);
    $stmt_rev->execute();
    $res = $stmt_rev->get_result();
    if ($res && $r = $res->fetch_assoc()) {
        $total_revenue = (float)($r['total'] ?? 0);
    }
}
$average_ticket = $total_transactions > 0 ? ($total_revenue / $total_transactions) : 0;

// Insights: New vs Returning (current month)
$new_vs_returning = [ 'new' => 0, 'returning' => 0 ];
$identifierColumn = 'phone';
$preferredIdentifiers = ['phone','client_phone','contact','mobile','tel','client_contact'];
$colsRs = $conn->query("SHOW COLUMNS FROM clients");
if ($colsRs) {
    $available = [];
    while ($c = $colsRs->fetch_assoc()) { $available[] = strtolower($c['Field']); }
    foreach ($preferredIdentifiers as $cand) {
        if (in_array($cand, $available, true)) { $identifierColumn = $cand; break; }
    }
}

$idColSql = "`" . $conn->real_escape_string($identifierColumn) . "`";
$first_visits_sql = "SELECT COUNT(*) AS cnt FROM (
  SELECT $idColSql AS identifier, MIN(DATE_FORMAT(created_at, '%Y-%m')) AS first_month
  FROM clients
  WHERE $idColSql IS NOT NULL AND $idColSql <> '' AND voided_at IS NULL
  GROUP BY $idColSql
) t WHERE t.first_month = ?";
$stmt_fv = $conn->prepare($first_visits_sql);
if ($stmt_fv) {
    $stmt_fv->bind_param("s", $current_month);
    $stmt_fv->execute();
    $first_visits = $stmt_fv->get_result();
    if ($first_visits && ($row = $first_visits->fetch_assoc())) { $new_vs_returning['new'] = (int)$row['cnt']; }
}
$new_vs_returning['returning'] = max(0, (int)$total_transactions - (int)$new_vs_returning['new']);

// Insights: Section Performance (this month)
$section_performance = [];
$stmt_sec = $conn->prepare("SELECT section, SUM(CASE WHEN voided_at IS NULL THEN 1 ELSE 0 END) AS visits, COALESCE(SUM($net_amount_sql),0) AS revenue
  FROM clients
  WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
  GROUP BY section ORDER BY revenue DESC");
if ($stmt_sec) {
    $stmt_sec->bind_param("s", $current_month);
    $stmt_sec->execute();
    $section_rs = $stmt_sec->get_result();
    if ($section_rs) { while ($r = $section_rs->fetch_assoc()) { $section_performance[] = $r; } }
}

// Handle alert messages
$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | The Breeze Spa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
            --text-main: #1e293b; --text-muted: #64748b; --border-color: #e2e8f0;
            --sidebar-width: 250px; --topbar-height: 60px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; overflow-x: hidden; }

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

        .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; }
        .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
        .topbar h2 { font-size: 18px; font-weight: 600; color: var(--text-main); }
        .topbar .date-stamp { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        .content-wrapper { padding: 30px; flex: 1; }

        .flat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 0; padding: 20px; display: flex; flex-direction: column; }
        .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
        .flat-card-header h3 { font-size: 15px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 20px; display: flex; align-items: center; gap: 20px; border-left: 4px solid var(--brand-blue); }
        .kpi-icon { font-size: 28px; color: var(--brand-blue); width: 40px; text-align: center; }
        .kpi-meta { display: flex; flex-direction: column; }
        .kpi-label { font-size: 12px; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 0.5px; margin-bottom: 5px; }
        .kpi-value { font-size: 24px; font-weight: 700; color: var(--text-main); }

        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px; }
        .chart-container { position: relative; height: 300px; width: 100%; }

        .insight-list { list-style: none; }
        .insight-list li { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border-color); font-size: 14px; }
        .insight-list li:last-child { border-bottom: none; }
        .insight-list .label { color: var(--text-main); font-weight: 500; }
        .insight-list .value { color: var(--text-muted); font-family: monospace; font-size: 13px; }

        @media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); transition: transform 0.3s ease; } .main-content { margin-left: 0; } .kpi-grid { grid-template-columns: 1fr; } }
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
            <a href="dashboard.php" class="menu-item active"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
            <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
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
            <h2>System Dashboard</h2>
            <div class="date-stamp">System Time: <?= date('Y-m-d H:i') ?></div>
        </header>

        <div class="content-wrapper">
            
            <?php if ($message): ?>
                <div style="background:#10b981; color:white; padding:12px 20px; margin-bottom:20px; font-size:14px; font-weight:500;">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon"><i class="fas fa-wallet"></i></div>
                    <div class="kpi-meta">
                        <span class="kpi-label">MTD Revenue</span>
                        <span class="kpi-value">GHS <?= number_format($total_revenue, 2) ?></span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon"><i class="fas fa-receipt"></i></div>
                    <div class="kpi-meta">
                        <span class="kpi-label">MTD Visits</span>
                        <span class="kpi-value"><?= number_format($total_transactions) ?></span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon"><i class="fas fa-chart-pie"></i></div>
                    <div class="kpi-meta">
                        <span class="kpi-label">Average Ticket</span>
                        <span class="kpi-value">GHS <?= number_format($average_ticket, 2) ?></span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon"><i class="fas fa-user-plus"></i></div>
                    <div class="kpi-meta">
                        <span class="kpi-label">New Clients (MTD)</span>
                        <span class="kpi-value"><?= number_format($new_vs_returning['new']) ?></span>
                    </div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="flat-card">
                    <div class="flat-card-header">
                        <h3>Section Performance (MTD)</h3>
                    </div>
                    <ul class="insight-list">
                        <?php if (!empty($section_performance)): ?>
                            <?php foreach ($section_performance as $i => $sec): if ($i >= 6) break; ?>
                                <li>
                                    <span class="label"><i class="fas fa-layer-group" style="color:var(--text-muted); margin-right:8px; font-size:12px;"></i> <?= htmlspecialchars($sec['section'] ?: 'Unspecified') ?></span>
                                    <span class="value"><?= number_format($sec['visits']) ?> visits &nbsp;|&nbsp; GHS <?= number_format($sec['revenue'], 2) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li><span class="label" style="color:var(--text-muted);">No visits recorded this month yet.</span></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="flat-card">
                    <div class="flat-card-header">
                        <h3>Client Mix (MTD)</h3>
                    </div>
                    <div style="flex: 1; display: flex; flex-direction: column; justify-content: center; text-align: center;">
                        <div style="font-size: 42px; font-weight: 700; color: var(--brand-blue); margin-bottom: 8px;">
                            <?= number_format($new_vs_returning['new']) ?>
                        </div>
                        <div style="font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">New Clients</div>
                        <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 20px 0;">
                        <div style="font-size: 28px; font-weight: 600; color: var(--text-main); margin-bottom: 8px;">
                            <?= number_format($new_vs_returning['returning']) ?>
                        </div>
                        <div style="font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">Returning Clients</div>
                        <a href="reports.php" style="margin-top:24px; display:inline-block; font-size:13px; font-weight:700; color:var(--brand-blue); text-decoration:none;">Open Reports →</a>
                    </div>
                </div>
            </div>

        </div>
    </main>
</body>
</html>