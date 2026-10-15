<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['user']['role'];

function analytics_range_kpis(mysqli $conn, string $start, string $end): array
{
    $net = client_net_amount_sql();
    $stmt = $conn->prepare("SELECT SUM(CASE WHEN voided_at IS NULL THEN 1 ELSE 0 END) AS visits, COALESCE(SUM($net), 0) AS revenue FROM clients WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['visits' => 0, 'revenue' => 0];
    $visits = (int)$row['visits'];
    $revenue = (float)$row['revenue'];
    return [
        'visits' => $visits,
        'revenue' => $revenue,
        'avg' => $visits > 0 ? ($revenue / $visits) : 0,
    ];
}

function analytics_delta(float $current, float $previous): array
{
    if ($previous == 0.0 && $current == 0.0) {
        return ['label' => 'No prior data', 'class' => 'muted'];
    }
    if ($previous == 0.0) {
        return ['label' => 'New activity', 'class' => 'up'];
    }
    $pct = (($current - $previous) / $previous) * 100;
    $sign = $pct >= 0 ? '+' : '';
    return [
        'label' => $sign . number_format($pct, 1) . '% vs prior period',
        'class' => $pct >= 0 ? 'up' : 'down',
    ];
}

$allowed_periods = ['today', 'week', 'month', 'last_month', 'year', 'custom'];
$period = $_GET['period'] ?? 'month';
if (!in_array($period, $allowed_periods, true)) {
    $period = 'month';
}

switch ($period) {
    case 'today':
        $start_date = $end_date = date('Y-m-d');
        break;
    case 'week':
        $dow = (int)date('N');
        $start_date = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
        $end_date = date('Y-m-d');
        break;
    case 'month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-d');
        break;
    case 'last_month':
        $start_date = date('Y-m-01', strtotime('first day of last month'));
        $end_date = date('Y-m-t', strtotime('last month'));
        break;
    case 'year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-m-d');
        break;
    default:
        $start_date = $_GET['start_date'] ?? date('Y-m-01');
        $end_date = $_GET['end_date'] ?? date('Y-m-d');
        $period = 'custom';
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-d');
}
if ($start_date > $end_date) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

$days_in_range = (int)((strtotime($end_date) - strtotime($start_date)) / 86400) + 1;
$prev_end = date('Y-m-d', strtotime($start_date . ' -1 day'));
$prev_start = date('Y-m-d', strtotime($prev_end . ' -' . ($days_in_range - 1) . ' days'));

if (isset($_POST['export_csv'])) {
    $exp_start = $_POST['exp_start'] ?? $start_date;
    $exp_end = $_POST['exp_end'] ?? $end_date;
    $stmt = $conn->prepare("SELECT name AS client_name, phone, service_type, amount, refund_amount, voided_at, duration, payment_mode, massage_type, staff_name, section, client_code, DATE(created_at) as transaction_date
              FROM clients WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
    $stmt->bind_param("ss", $exp_start, $exp_end);
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Breeze_Ledger_' . $exp_start . '_to_' . $exp_end . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Client Name', 'Phone', 'Service', 'Section', 'Duration', 'Amount', 'Attendant', 'Payment Mode', 'Client Code', 'Voided']);
    while ($row = $result->fetch_assoc()) {
        $service_type = !empty($row['massage_type']) ? $row['massage_type'] : $row['service_type'];
        fputcsv($output, [
            $row['transaction_date'],
            $row['client_name'],
            $row['phone'],
            $service_type,
            $row['section'],
            $row['duration'],
            number_format(client_net_amount($row), 2, '.', ''),
            $row['staff_name'],
            $row['payment_mode'],
            $row['client_code'],
            !empty($row['voided_at']) ? 'Yes' : '',
        ]);
    }
    fclose($output);
    exit;
}

$current = analytics_range_kpis($conn, $start_date, $end_date);
$previous = analytics_range_kpis($conn, $prev_start, $prev_end);
$delta_revenue = analytics_delta($current['revenue'], $previous['revenue']);
$delta_visits = analytics_delta($current['visits'], $previous['visits']);
$delta_avg = analytics_delta($current['avg'], $previous['avg']);

$pay_cash = 0.0;
$pay_momo = 0.0;
$pay_other = 0.0;
$pay_stmt = $conn->prepare("SELECT payment_mode, COALESCE(SUM(" . client_net_amount_sql() . "), 0) AS total FROM clients WHERE DATE(created_at) BETWEEN ? AND ? AND payment_mode IS NOT NULL AND payment_mode != '' GROUP BY payment_mode");
$pay_stmt->bind_param("ss", $start_date, $end_date);
$pay_stmt->execute();
$pay_res = $pay_stmt->get_result();
while ($row = $pay_res->fetch_assoc()) {
    $mode = strtolower((string)$row['payment_mode']);
    $total = (float)$row['total'];
    if (strpos($mode, 'cash') !== false) {
        $pay_cash += $total;
    } elseif (strpos($mode, 'mobile') !== false || strpos($mode, 'momo') !== false) {
        $pay_momo += $total;
    } else {
        $pay_other += $total;
    }
}

$sections = [];
$sec_stmt = $conn->prepare("SELECT section, SUM(CASE WHEN voided_at IS NULL THEN 1 ELSE 0 END) AS visits, COALESCE(SUM(" . client_net_amount_sql() . "), 0) AS revenue FROM clients WHERE DATE(created_at) BETWEEN ? AND ? AND section IS NOT NULL AND section != '' GROUP BY section ORDER BY revenue DESC");
$sec_stmt->bind_param("ss", $start_date, $end_date);
$sec_stmt->execute();
$sec_res = $sec_stmt->get_result();
while ($row = $sec_res->fetch_assoc()) {
    $sections[] = $row;
}
$section_max = !empty($sections) ? (float)$sections[0]['revenue'] : 0;

$staff_rows = [];
$staff_stmt = $conn->prepare("SELECT staff_name, SUM(CASE WHEN voided_at IS NULL THEN 1 ELSE 0 END) AS visits, COALESCE(SUM(" . client_net_amount_sql() . "), 0) AS revenue FROM clients WHERE DATE(created_at) BETWEEN ? AND ? AND staff_name IS NOT NULL AND staff_name != '' GROUP BY staff_name ORDER BY revenue DESC LIMIT 6");
$staff_stmt->bind_param("ss", $start_date, $end_date);
$staff_stmt->execute();
$staff_res = $staff_stmt->get_result();
while ($row = $staff_res->fetch_assoc()) {
    $staff_rows[] = $row;
}

$service_rows = [];
$svc_stmt = $conn->prepare("SELECT COALESCE(NULLIF(massage_type, ''), service_type) AS service_name, SUM(CASE WHEN voided_at IS NULL THEN 1 ELSE 0 END) AS visits, COALESCE(SUM(" . client_net_amount_sql() . "), 0) AS revenue
    FROM clients
    WHERE DATE(created_at) BETWEEN ? AND ? AND COALESCE(NULLIF(massage_type, ''), service_type) IS NOT NULL AND COALESCE(NULLIF(massage_type, ''), service_type) != ''
    GROUP BY COALESCE(NULLIF(massage_type, ''), service_type)
    ORDER BY revenue DESC
    LIMIT 6");
$svc_stmt->bind_param("ss", $start_date, $end_date);
$svc_stmt->execute();
$svc_res = $svc_stmt->get_result();
while ($row = $svc_res->fetch_assoc()) {
    $service_rows[] = $row;
}

$use_daily = $days_in_range <= 45;
$trend_labels = [];
$trend_values = [];
if ($use_daily) {
    $bucket = [];
    for ($t = strtotime($start_date); $t <= strtotime($end_date); $t += 86400) {
        $bucket[date('Y-m-d', $t)] = 0.0;
    }
    $tr_stmt = $conn->prepare("SELECT DATE(created_at) AS bucket, COALESCE(SUM(" . client_net_amount_sql() . "), 0) AS total FROM clients WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY bucket");
    $tr_stmt->bind_param("ss", $start_date, $end_date);
    $tr_stmt->execute();
    $tr_res = $tr_stmt->get_result();
    while ($row = $tr_res->fetch_assoc()) {
        $bucket[$row['bucket']] = (float)$row['total'];
    }
    foreach ($bucket as $day => $val) {
        $trend_labels[] = date('M j', strtotime($day));
        $trend_values[] = $val;
    }
} else {
    $tr_stmt = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket, COALESCE(SUM(" . client_net_amount_sql() . "), 0) AS total FROM clients WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY bucket");
    $tr_stmt->bind_param("ss", $start_date, $end_date);
    $tr_stmt->execute();
    $tr_res = $tr_stmt->get_result();
    while ($row = $tr_res->fetch_assoc()) {
        $trend_labels[] = date('M Y', strtotime($row['bucket'] . '-01'));
        $trend_values[] = (float)$row['total'];
    }
}

$heatmap_data = [];
for ($d = 1; $d <= 7; $d++) {
    for ($h = 8; $h <= 20; $h++) {
        $heatmap_data[$d][$h] = 0;
    }
}
$heatmap_max = 0;
$busiest_label = 'Not enough data';
$hm_stmt = $conn->prepare("SELECT DAYOFWEEK(created_at) as day_num, HOUR(created_at) as hour_num, COUNT(*) as volume FROM clients WHERE DATE(created_at) BETWEEN ? AND ? AND voided_at IS NULL GROUP BY day_num, hour_num");
$hm_stmt->bind_param("ss", $start_date, $end_date);
$hm_stmt->execute();
$heatmap_res = $hm_stmt->get_result();
$days_map_lookup = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];
if ($heatmap_res) {
    while ($row = $heatmap_res->fetch_assoc()) {
        $d = (int)$row['day_num'];
        $h = (int)$row['hour_num'];
        $vol = (int)$row['volume'];
        if (isset($heatmap_data[$d][$h])) {
            $heatmap_data[$d][$h] = $vol;
            if ($vol > $heatmap_max) {
                $heatmap_max = $vol;
                $hour_label = ($h > 12) ? ($h - 12) . 'PM' : (($h == 12) ? '12PM' : $h . 'AM');
                $busiest_label = ($days_map_lookup[$d] ?? '') . ' at ' . $hour_label;
            }
        }
    }
}

$view = ($_GET['view'] ?? 'snapshot') === 'operations' ? 'operations' : 'snapshot';
$qs = function ($extra = []) use ($period, $start_date, $end_date) {
    $params = array_merge(['period' => $period, 'start_date' => $start_date, 'end_date' => $end_date], $extra);
    return 'reports.php?' . http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | The Breeze Spa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
            --text-main: #1e293b; --text-muted: #475569; --border-color: #e2e8f0;
            --sidebar-width: 250px; --topbar-height: 60px;
            --success-color: #10b981;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-main); min-height: 100vh; font-size: 15px; }

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
        .logout-btn:hover { background-color: #ef4444; color: #ffffff; }

        .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; width: calc(100% - var(--sidebar-width)); }
        .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
        .topbar h2 { font-size: 18px; font-weight: 600; }
        .topbar .date-stamp { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        .content-wrapper { padding: 24px 30px 40px; flex: 1; width: 100%; max-width: none; }

        .control-bar { background: var(--bg-card); border: 1px solid var(--border-color); padding: 16px 20px; margin-bottom: 20px; }
        .presets { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .preset {
            padding: 7px 12px; font-size: 12px; font-weight: 700; text-decoration: none;
            color: var(--text-muted); background: #f8fafc; border: 1px solid var(--border-color); text-transform: uppercase; letter-spacing: 0.3px;
        }
        .preset.active, .preset:hover { background: var(--brand-blue); color: #fff; border-color: var(--brand-blue); }
        .control-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; }
        .filter-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase; }
        .form-control { padding: 9px 12px; border: 1px solid var(--border-color); font-size: 14px; background: #f8fafc; outline: none; }
        .form-control:focus { border-color: var(--brand-blue); background: #fff; }
        .btn { padding: 9px 16px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-primary { background: var(--brand-blue); color: #fff; }
        .btn-primary:hover { background: #0284c7; }
        .btn-success { background: var(--success-color); color: #fff; }
        .range-note { font-size: 13px; color: var(--text-muted); margin-top: 10px; }

        .view-tabs { display: flex; gap: 0; margin-bottom: 20px; border: 1px solid var(--border-color); width: fit-content; }
        .view-tab { padding: 9px 16px; font-size: 13px; font-weight: 700; text-decoration: none; color: var(--text-muted); background: #fff; }
        .view-tab.active { background: var(--brand-dark); color: #fff; }

        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .kpi-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 18px 20px; border-left: 4px solid var(--brand-blue); }
        .kpi-label { font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.4px; margin-bottom: 6px; }
        .kpi-value { font-size: 24px; font-weight: 700; line-height: 1.15; }
        .kpi-sub { font-size: 12px; color: var(--text-muted); margin-top: 6px; font-weight: 600; }
        .delta { display: inline-block; margin-top: 8px; font-size: 12px; font-weight: 700; }
        .delta.up { color: #059669; }
        .delta.down { color: #dc2626; }
        .delta.muted { color: var(--text-muted); font-weight: 600; }

        .grid-2 { display: grid; grid-template-columns: 1.4fr 1fr; gap: 16px; margin-bottom: 16px; }
        .grid-2-eq { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 20px; }
        .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
        .flat-card-header h3 { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .flat-card-header span { font-size: 12px; color: var(--text-muted); }
        .chart-container { position: relative; height: 280px; }

        .rank-row { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; padding: 9px 0; border-bottom: 1px solid #f1f5f9; }
        .rank-row:last-child { border-bottom: none; }
        .rank-name { font-size: 14px; font-weight: 600; }
        .rank-meta { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
        .rank-value { font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
        .bar { height: 4px; background: #e2e8f0; margin-top: 6px; }
        .bar > span { display: block; height: 100%; background: var(--brand-blue); }
        .empty { color: var(--text-muted); padding: 20px 0; font-size: 14px; }

        .heatmap-container { width: 100%; overflow-x: auto; }
        .heatmap-table { width: 100%; min-width: 700px; border-collapse: separate; border-spacing: 3px; }
        .heatmap-table th { background: transparent; color: var(--text-muted); font-size: 11px; text-align: center; padding: 8px 4px; font-weight: 600; border: none; text-transform: uppercase; }
        .heatmap-table td { text-align: center; padding: 12px 4px; font-size: 13px; font-weight: 600; border: none; }
        .heatmap-table .day-label { text-align: right; padding-right: 12px; color: var(--text-muted); font-size: 12px; font-weight: 700; text-transform: uppercase; background: transparent; width: 54px; }

        @media (max-width: 1100px) { .kpi-grid, .grid-2, .grid-2-eq { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; width: 100%; } }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-header"><img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo"></div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><?= htmlspecialchars($user_role) ?></p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
            <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
            <a href="password_requests.php" class="menu-item"><i class="fas fa-key"></i> Password Requests</a>
            <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            <?php if ($user_role === 'admin' || $user_role === 'supervisor'): ?>
            <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
            <?php endif; ?>
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="reports.php" class="menu-item active"><i class="fas fa-file-invoice-dollar"></i> Reports</a>
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
            <h2>Reports</h2>
            <div class="date-stamp"><?= date('d M Y, H:i') ?></div>
        </header>

        <div class="content-wrapper">
            <div class="control-bar">
                <div class="presets">
                    <a class="preset <?= $period === 'today' ? 'active' : '' ?>" href="reports.php?period=today">Today</a>
                    <a class="preset <?= $period === 'week' ? 'active' : '' ?>" href="reports.php?period=week">This week</a>
                    <a class="preset <?= $period === 'month' ? 'active' : '' ?>" href="reports.php?period=month">This month</a>
                    <a class="preset <?= $period === 'last_month' ? 'active' : '' ?>" href="reports.php?period=last_month">Last month</a>
                    <a class="preset <?= $period === 'year' ? 'active' : '' ?>" href="reports.php?period=year">This year</a>
                </div>
                <div class="control-row">
                    <form method="get" class="filter-form">
                        <input type="hidden" name="period" value="custom">
                        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                        <div class="form-group">
                            <label>From</label>
                            <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>To</label>
                            <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Apply</button>
                    </form>
                    <form method="post">
<?= csrf_field() ?>
                        <input type="hidden" name="exp_start" value="<?= htmlspecialchars($start_date) ?>">
                        <input type="hidden" name="exp_end" value="<?= htmlspecialchars($end_date) ?>">
                        <button type="submit" name="export_csv" class="btn btn-success"><i class="fas fa-file-csv"></i> Download CSV</button>
                    </form>
                </div>
                <div class="range-note">
                    Showing <?= date('d M Y', strtotime($start_date)) ?> – <?= date('d M Y', strtotime($end_date)) ?>
                    · compared with <?= date('d M Y', strtotime($prev_start)) ?> – <?= date('d M Y', strtotime($prev_end)) ?>
                </div>
            </div>

            <div class="view-tabs">
                <a class="view-tab <?= $view === 'snapshot' ? 'active' : '' ?>" href="<?= htmlspecialchars($qs(['view' => 'snapshot'])) ?>">Snapshot</a>
                <a class="view-tab <?= $view === 'operations' ? 'active' : '' ?>" href="<?= htmlspecialchars($qs(['view' => 'operations'])) ?>">Operations</a>
            </div>

            <?php if ($view === 'snapshot'): ?>
            <div class="kpi-grid">
                <div class="kpi-card" style="border-left-color:#10b981;">
                    <div class="kpi-label">Revenue</div>
                    <div class="kpi-value">GHS <?= number_format($current['revenue'], 2) ?></div>
                    <div class="delta <?= $delta_revenue['class'] ?>"><?= htmlspecialchars($delta_revenue['label']) ?></div>
                </div>
                <div class="kpi-card" style="border-left-color:#8b5cf6;">
                    <div class="kpi-label">Visits</div>
                    <div class="kpi-value"><?= number_format($current['visits']) ?></div>
                    <div class="delta <?= $delta_visits['class'] ?>"><?= htmlspecialchars($delta_visits['label']) ?></div>
                </div>
                <div class="kpi-card" style="border-left-color:#f59e0b;">
                    <div class="kpi-label">Average ticket</div>
                    <div class="kpi-value">GHS <?= number_format($current['avg'], 2) ?></div>
                    <div class="delta <?= $delta_avg['class'] ?>"><?= htmlspecialchars($delta_avg['label']) ?></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Collections</div>
                    <div class="kpi-value" style="font-size:18px;">Cash GHS <?= number_format($pay_cash, 2) ?></div>
                    <div class="kpi-sub">Mobile Money GHS <?= number_format($pay_momo, 2) ?><?php if ($pay_other > 0): ?> · Other GHS <?= number_format($pay_other, 2) ?><?php endif; ?></div>
                </div>
            </div>

            <div class="grid-2">
                <div class="flat-card">
                    <div class="flat-card-header">
                        <h3>Revenue trend</h3>
                        <span><?= $use_daily ? 'Daily in this period' : 'Monthly in this period' ?></span>
                    </div>
                    <div class="chart-container"><canvas id="revenueTrendChart"></canvas></div>
                </div>
                <div class="flat-card">
                    <div class="flat-card-header">
                        <h3>Revenue by section</h3>
                        <span>Highest first</span>
                    </div>
                    <?php if (empty($sections)): ?>
                        <div class="empty">No section activity in this period.</div>
                    <?php else: ?>
                        <?php foreach ($sections as $sec):
                            $pct = $section_max > 0 ? ((float)$sec['revenue'] / $section_max) * 100 : 0;
                        ?>
                        <div class="rank-row">
                            <div>
                                <div class="rank-name"><?= htmlspecialchars($sec['section']) ?></div>
                                <div class="rank-meta"><?= number_format((int)$sec['visits']) ?> visits</div>
                                <div class="bar"><span style="width: <?= number_format($pct, 1) ?>%"></span></div>
                            </div>
                            <div class="rank-value">GHS <?= number_format((float)$sec['revenue'], 2) ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid-2-eq">
                <div class="flat-card">
                    <div class="flat-card-header">
                        <h3>Top attendants</h3>
                        <span>By revenue</span>
                    </div>
                    <?php if (empty($staff_rows)): ?>
                        <div class="empty">No attendant totals in this period.</div>
                    <?php else: ?>
                        <?php foreach ($staff_rows as $row): ?>
                        <div class="rank-row">
                            <div>
                                <div class="rank-name"><?= htmlspecialchars($row['staff_name']) ?></div>
                                <div class="rank-meta"><?= number_format((int)$row['visits']) ?> visits</div>
                            </div>
                            <div class="rank-value">GHS <?= number_format((float)$row['revenue'], 2) ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="flat-card">
                    <div class="flat-card-header">
                        <h3>Top services</h3>
                        <span>By revenue</span>
                    </div>
                    <?php if (empty($service_rows)): ?>
                        <div class="empty">No service totals in this period.</div>
                    <?php else: ?>
                        <?php foreach ($service_rows as $row): ?>
                        <div class="rank-row">
                            <div>
                                <div class="rank-name"><?= htmlspecialchars($row['service_name']) ?></div>
                                <div class="rank-meta"><?= number_format((int)$row['visits']) ?> visits</div>
                            </div>
                            <div class="rank-value">GHS <?= number_format((float)$row['revenue'], 2) ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <p class="range-note">Need the full list? Open the <a href="clients.php" style="color:var(--brand-blue); font-weight:700;">Client Directory</a>.</p>

            <?php else: ?>
            <div class="flat-card">
                <div class="flat-card-header">
                    <h3>Busiest hours</h3>
                    <span>Peak: <?= htmlspecialchars($busiest_label) ?></span>
                </div>
                <div class="heatmap-container">
                    <table class="heatmap-table">
                        <thead>
                            <tr>
                                <th></th>
                                <?php for ($h = 8; $h <= 20; $h++): ?>
                                    <th><?= ($h > 12) ? ($h - 12) . 'PM' : (($h == 12) ? '12PM' : $h . 'AM') ?></th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $days_map = [2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat', 1 => 'Sun'];
                            foreach ($days_map as $d_num => $d_label):
                            ?>
                            <tr>
                                <td class="day-label"><?= $d_label ?></td>
                                <?php
                                for ($h = 8; $h <= 20; $h++):
                                    $vol = $heatmap_data[$d_num][$h];
                                    $intensity = $heatmap_max > 0 ? ($vol / $heatmap_max) : 0;
                                    $bg_color = $vol > 0 ? "rgba(14, 165, 233, " . max(0.15, $intensity) . ")" : "#f8fafc";
                                    $text_color = $intensity > 0.5 ? "#ffffff" : "var(--text-muted)";
                                ?>
                                <td style="background-color: <?= $bg_color ?>; color: <?= $text_color ?>;" title="<?= (int)$vol ?> visits">
                                    <?= $vol > 0 ? (int)$vol : '–' ?>
                                </td>
                                <?php endfor; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($view === 'snapshot'): ?>
    <script>
        Chart.defaults.font.family = "'Segoe UI', sans-serif";
        Chart.defaults.color = '#475569';
        new Chart(document.getElementById('revenueTrendChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: <?= json_encode($trend_labels) ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?= json_encode($trend_values) ?>,
                    borderColor: '#0ea5e9',
                    backgroundColor: 'rgba(14, 165, 233, 0.1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#0ea5e9',
                    pointRadius: <?= count($trend_labels) > 20 ? 0 : 3 ?>,
                    fill: true,
                    tension: 0.15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                    y: { beginAtZero: true, border: { display: false }, ticks: { callback: (val) => 'GHS ' + Number(val).toLocaleString() } }
                }
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
