<?php
session_start();
include '../db.php';

// RBAC: Allow Admin and Supervisor
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header("Location: ../login.php");
    exit;
}

$user_role = $_SESSION['user']['role'];

// --- DYNAMIC DATE FILTER LOGIC ---
// Default to the current month (1st day to last day) if no filter is applied
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Sanitize for SQL
$start_sql = $conn->real_escape_string($start_date);
$end_sql = $conn->real_escape_string($end_date);


// Handle CSV export (Now uses the dynamic date range!)
if (isset($_POST['export_csv'])) {
    $exp_start = $_POST['exp_start'] ?? $start_date;
    $exp_end = $_POST['exp_end'] ?? $end_date;
    
    $query = "SELECT name AS client_name, phone, service_type, amount, duration, payment_mode, massage_type, staff_name, section, client_code, DATE(created_at) as transaction_date 
              FROM clients 
              WHERE DATE(created_at) BETWEEN ? AND ? 
              ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $exp_start, $exp_end);
    $stmt->execute();
    $result = $stmt->get_result();
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Financial_Ledger_' . $exp_start . '_to_' . $exp_end . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Date', 'Name', 'Service type', 'Duration', 'Amount', 'Card number', 'Attendant', 'Mode of payment', 'Client number'));
    
    while ($row = $result->fetch_assoc()) {
        $service_type = !empty($row['massage_type']) ? $row['massage_type'] : $row['service_type'];
        fputcsv($output, array(
            $row['transaction_date'],
            $row['client_name'],
            $service_type,
            $row['duration'],
            $row['amount'],
            $row['client_code'],
            $row['staff_name'],
            $row['payment_mode'],
            $row['phone']
        ));
    }
    fclose($output);
    exit;
}

// --- ANALYTICS QUERIES (Filtered by Date Range) ---

// 1. Core KPIs
$total_transactions = $conn->query("SELECT COUNT(*) as count FROM clients WHERE DATE(created_at) BETWEEN '$start_sql' AND '$end_sql'")->fetch_assoc()['count'] ?? 0;
$total_revenue = $conn->query("SELECT SUM(amount) as total FROM clients WHERE DATE(created_at) BETWEEN '$start_sql' AND '$end_sql'")->fetch_assoc()['total'] ?? 0;
$average_transaction = ($total_transactions > 0) ? ($total_revenue / $total_transactions) : 0;

// 2. Trend Analysis (Always shows trailing 6 months for macro perspective)
$monthly_revenue = $conn->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, SUM(amount) as total FROM clients WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month");

// 3. Section Performance (Filtered)
$section_revenue = $conn->query("SELECT section, SUM(amount) as total FROM clients WHERE DATE(created_at) BETWEEN '$start_sql' AND '$end_sql' AND section != '' GROUP BY section ORDER BY total DESC");

// 4. Payment Method Reconciliation (Filtered)
$payment_methods = $conn->query("SELECT payment_mode, COUNT(*) as count, SUM(amount) as total FROM clients WHERE DATE(created_at) BETWEEN '$start_sql' AND '$end_sql' AND payment_mode != '' GROUP BY payment_mode ORDER BY total DESC");

// 5. Top Performing Staff (Filtered)
$staff_revenue = $conn->query("SELECT staff_name, SUM(amount) as total FROM clients WHERE DATE(created_at) BETWEEN '$start_sql' AND '$end_sql' AND staff_name != '' GROUP BY staff_name ORDER BY total DESC LIMIT 5");

// 6. Top Services By Volume (Filtered)
$top_services = $conn->query("SELECT service_type, COUNT(*) as volume FROM clients WHERE DATE(created_at) BETWEEN '$start_sql' AND '$end_sql' AND service_type != '' GROUP BY service_type ORDER BY volume DESC LIMIT 5");

// 7. Recent Transactions List (Filtered)
$recent_transactions = $conn->query("SELECT * FROM clients WHERE DATE(created_at) BETWEEN '$start_sql' AND '$end_sql' ORDER BY created_at DESC LIMIT 8");

// 8. Foot Traffic Heatmap Matrix (Filtered)
$heatmap_data = [];
// Initialize grid for Days 1-7 (Sun-Sat) and Hours 8-20 (8 AM - 8 PM)
for ($d = 1; $d <= 7; $d++) { 
    for ($h = 8; $h <= 20; $h++) { 
        $heatmap_data[$d][$h] = 0; 
    } 
}
$heatmap_max = 0;

$heatmap_sql = "
    SELECT DAYOFWEEK(created_at) as day_num, HOUR(created_at) as hour_num, COUNT(*) as volume 
    FROM clients 
    WHERE DATE(created_at) BETWEEN '$start_sql' AND '$end_sql' 
    GROUP BY day_num, hour_num
";
$heatmap_res = $conn->query($heatmap_sql);

if ($heatmap_res) {
    while ($row = $heatmap_res->fetch_assoc()) {
        $d = (int)$row['day_num'];
        $h = (int)$row['hour_num'];
        if (isset($heatmap_data[$d][$h])) {
            $heatmap_data[$d][$h] = (int)$row['volume'];
            if ($heatmap_data[$d][$h] > $heatmap_max) {
                $heatmap_max = $heatmap_data[$d][$h]; // Track highest volume for color scaling
            }
        }
    }
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics | The Breeze Spa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
            --text-main: #1e293b; --text-muted: #475569; --border-color: #e2e8f0;
            --sidebar-width: 250px; --topbar-height: 60px;
            --chart-1: #0ea5e9; --chart-2: #8b5cf6; --chart-3: #10b981;
            --chart-4: #f59e0b; --chart-5: #f43f5e; --chart-6: #64748b;
            --warning-color: #f59e0b; --success-color: #10b981;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; font-size: 16px; }

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

        .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; }
        .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
        .topbar h2 { font-size: 22px; font-weight: 600; color: var(--text-main); }
        .topbar .date-stamp { font-size: 15px; color: var(--text-muted); font-weight: 500; }
        .content-wrapper { padding: 30px; flex: 1; max-width: 1600px; }

        /* Tools Header (Filters & Export) */
        .tools-header { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 25px; }
        .filter-card { flex: 1; background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 20px; display: flex; align-items: center; justify-content: space-between; border-left: 4px solid var(--brand-blue); }
        .export-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 20px; display: flex; align-items: center; justify-content: center; border-left: 4px solid var(--success-color); }
        
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 5px; text-transform: uppercase; }
        .form-control { padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 14px; background-color: #f8fafc; outline: none; }
        .form-control:focus { border-color: var(--brand-blue); background-color: #ffffff; }
        
        .btn { padding: 10px 20px; font-size: 14px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; transition: background-color 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background-color: var(--brand-blue); color: #ffffff; }
        .btn-primary:hover { background-color: #0284c7; }
        .btn-success { background-color: var(--success-color); color: #ffffff; }
        .btn-success:hover { background-color: #059669; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 25px; display: flex; align-items: center; gap: 20px; border-radius: 0; }
        .kpi-icon { font-size: 32px; color: var(--brand-blue); width: 45px; text-align: center; }
        .kpi-value { font-size: 28px; font-weight: 700; color: var(--text-main); line-height: 1.2; }
        .kpi-label { font-size: 13px; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 0.5px; }

        .flat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 0; padding: 30px; margin-bottom: 30px; }
        .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; }
        .flat-card-header h3 { font-size: 16px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }
        
        .analytics-grid-2 { display: grid; grid-template-columns: 1.8fr 1.2fr; gap: 30px; margin-bottom: 30px; }
        .analytics-grid-3 { display: grid; grid-template-columns: 1.2fr 1.2fr 0.8fr; gap: 30px; margin-bottom: 30px; }
        .chart-container { position: relative; height: 320px; width: 100%; }

        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 14px; }
        th { background-color: #f8fafc; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }
        tr:hover td { background-color: #f8fafc; }
        .amount-text { font-family: monospace; font-weight: 600; color: #047857; font-size: 14px; }
        
        .badge { padding: 4px 8px; font-size: 11px; font-weight: 600; border-radius: 2px; text-transform: uppercase; border: 1px solid; display: inline-block; }
        .badge-gray { background-color: #f1f5f9; color: #475569; border-color: #cbd5e1; }

        /* Heatmap Styles */
        .heatmap-container { width: 100%; overflow-x: auto; padding-top: 10px; }
        .heatmap-table { width: 100%; min-width: 700px; border-collapse: separate; border-spacing: 3px; margin: 0; }
        .heatmap-table th { background: transparent; color: var(--text-muted); font-size: 11px; text-align: center; padding: 8px 4px; font-weight: 600; border: none; letter-spacing: 0; text-transform: uppercase; }
        .heatmap-table td { text-align: center; padding: 12px 4px; border-radius: 2px; font-size: 13px; font-weight: 600; transition: transform 0.1s; cursor: crosshair; border: none; }
        .heatmap-table td:hover { transform: scale(1.15); box-shadow: 0 4px 8px rgba(0,0,0,0.15); z-index: 10; position: relative; }
        .heatmap-table .day-label { text-align: right; padding-right: 15px; color: var(--text-muted); font-size: 12px; font-weight: 700; text-transform: uppercase; background: transparent; width: 60px; cursor: default; }
        .heatmap-table .day-label:hover { transform: none; box-shadow: none; z-index: 1; }

        @media (max-width: 1200px) { .analytics-grid-3, .analytics-grid-2 { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; } .tools-header { flex-direction: column; } }
    </style>
</head>
<body>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header"><img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo"></div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><?= htmlspecialchars($user_role) ?></p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
            <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
            <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            
            <?php if ($user_role === 'admin' || $user_role === 'supervisor'): ?>
            <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
            <?php endif; ?>
            
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="reports.php" class="menu-item active"><i class="fas fa-file-invoice-dollar"></i> Business Analytics</a>
            <a href="clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
            
            <?php if ($user_role === 'admin'): ?>
            <a href="audit_logs.php" class="menu-item"><i class="fas fa-server"></i> System Audit Logs</a>
            <?php endif; ?>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Terminate Session</a>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Business Intelligence & Reporting</h2>
            <div class="date-stamp">System Time: <?= date('Y-m-d H:i') ?></div>
        </header>

        <div class="content-wrapper">
            
            <div class="tools-header">
                <div class="filter-card">
                    <div>
                        <h3 style="font-size: 16px; font-weight: 600; color: var(--text-main); margin-bottom: 4px;"><i class="fas fa-filter" style="color: var(--brand-blue); margin-right: 8px;"></i> Dynamic Analytics Filter</h3>
                        <p style="font-size: 13px; color: var(--text-muted); margin: 0;">Select a date range to recalculate all dashboard metrics.</p>
                    </div>
                    <form method="get" style="display: flex; gap: 15px; align-items: flex-end;">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-sync-alt"></i> Apply Filter</button>
                    </form>
                </div>

                <div class="export-card">
                    <form method="post" style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
                        <input type="hidden" name="exp_start" value="<?= htmlspecialchars($start_date) ?>">
                        <input type="hidden" name="exp_end" value="<?= htmlspecialchars($end_date) ?>">
                        <div style="font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">Export Current View</div>
                        <button type="submit" name="export_csv" class="btn btn-success"><i class="fas fa-file-csv"></i> Download CSV Ledger</button>
                    </form>
                </div>
            </div>

            <div style="margin-bottom: 15px; font-size: 14px; font-weight: 600; color: var(--brand-blue);">
                <i class="fas fa-info-circle"></i> Displaying metrics from <?= date('M d, Y', strtotime($start_date)) ?> to <?= date('M d, Y', strtotime($end_date)) ?>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card" style="border-left-color: #10b981;">
                    <div class="kpi-icon" style="color: #10b981;"><i class="fas fa-wallet"></i></div>
                    <div>
                        <div class="kpi-label">Gross Revenue</div>
                        <div class="kpi-value">GHS <?= number_format($total_revenue, 2) ?></div>
                    </div>
                </div>
                <div class="kpi-card" style="border-left-color: #8b5cf6;">
                    <div class="kpi-icon" style="color: #8b5cf6;"><i class="fas fa-receipt"></i></div>
                    <div>
                        <div class="kpi-label">Transaction Volume</div>
                        <div class="kpi-value"><?= number_format($total_transactions) ?></div>
                    </div>
                </div>
                <div class="kpi-card" style="border-left-color: #f59e0b;">
                    <div class="kpi-icon" style="color: #f59e0b;"><i class="fas fa-chart-pie"></i></div>
                    <div>
                        <div class="kpi-label">Average Ticket Size</div>
                        <div class="kpi-value">GHS <?= number_format($average_transaction, 2) ?></div>
                    </div>
                </div>
            </div>

            <div class="flat-card">
                <div class="flat-card-header">
                    <h3><i class="fas fa-fire-alt" style="color:var(--warning-color); margin-right:8px;"></i> Foot Traffic Heatmap (Busiest Hours)</h3>
                </div>
                <div class="heatmap-container">
                    <table class="heatmap-table">
                        <thead>
                            <tr>
                                <th></th>
                                <?php for($h=8; $h<=20; $h++): ?>
                                    <th><?= ($h > 12) ? ($h-12).'PM' : (($h == 12) ? '12PM' : $h.'AM') ?></th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Order mapping: Monday(2) to Sunday(1)
                            $days_map = [2=>'Mon', 3=>'Tue', 4=>'Wed', 5=>'Thu', 6=>'Fri', 7=>'Sat', 1=>'Sun'];
                            
                            foreach($days_map as $d_num => $d_label): 
                            ?>
                            <tr>
                                <td class="day-label"><?= $d_label ?></td>
                                <?php 
                                for($h=8; $h<=20; $h++): 
                                    $vol = $heatmap_data[$d_num][$h];
                                    $intensity = $heatmap_max > 0 ? ($vol / $heatmap_max) : 0;
                                    
                                    // Scale opacity based on maximum volume in the filtered range
                                    $bg_color = $vol > 0 ? "rgba(14, 165, 233, " . max(0.15, $intensity) . ")" : "#f8fafc";
                                    $text_color = $intensity > 0.5 ? "#ffffff" : "var(--text-muted)";
                                    $display_text = $vol > 0 ? $vol : '-';
                                ?>
                                <td style="background-color: <?= $bg_color ?>; color: <?= $text_color ?>;" title="<?= $vol ?> visits on <?= $d_label ?>s at <?= ($h > 12) ? ($h-12).'PM' : (($h == 12) ? '12PM' : $h.'AM') ?>">
                                    <?= $display_text ?>
                                </td>
                                <?php endfor; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="analytics-grid-2">
                <div class="flat-card" style="margin-bottom: 0;">
                    <div class="flat-card-header">
                        <h3><i class="fas fa-chart-line" style="color:var(--text-muted); margin-right:8px;"></i> Trailing 6-Month Revenue</h3>
                    </div>
                    <div class="chart-container"><canvas id="revenueTrendChart"></canvas></div>
                </div>
                <div class="flat-card" style="margin-bottom: 0;">
                    <div class="flat-card-header">
                        <h3><i class="fas fa-layer-group" style="color:var(--text-muted); margin-right:8px;"></i> Revenue By Section</h3>
                    </div>
                    <div class="chart-container"><canvas id="sectionChart"></canvas></div>
                </div>
            </div>

            <div class="analytics-grid-3">
                <div class="flat-card" style="margin-bottom: 0;">
                    <div class="flat-card-header"><h3>Top Performers (Revenue)</h3></div>
                    <div class="chart-container"><canvas id="staffChart"></canvas></div>
                </div>
                <div class="flat-card" style="margin-bottom: 0;">
                    <div class="flat-card-header"><h3>Most Popular Services</h3></div>
                    <div class="chart-container"><canvas id="servicesChart"></canvas></div>
                </div>
                <div class="flat-card" style="margin-bottom: 0;">
                    <div class="flat-card-header"><h3>Payment Methods</h3></div>
                    <div class="chart-container"><canvas id="paymentChart"></canvas></div>
                </div>
            </div>

            <div class="flat-card">
                <div class="flat-card-header">
                    <h3>Recent Transactions (Filtered Period)</h3>
                    <a href="clients.php" class="btn btn-outline btn-sm" style="font-size: 12px; padding: 6px 12px;">View Master Ledger</a>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Code</th>
                                <th>Client Name</th>
                                <th>Service Executed</th>
                                <th>Staff</th>
                                <th>Payment</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($txn = $recent_transactions->fetch_assoc()): ?>
                            <tr>
                                <td style="color: var(--text-muted); font-size: 13px;"><?= date('M d, H:i', strtotime($txn['created_at'])) ?></td>
                                <td><span class="badge badge-gray"><?= htmlspecialchars($txn['client_code'] ?: 'N/A') ?></span></td>
                                <td><strong><?= htmlspecialchars($txn['name']) ?></strong></td>
                                <td><?= htmlspecialchars($txn['service_type']) ?></td>
                                <td><i class="fas fa-user-circle" style="color: var(--text-muted); margin-right: 6px;"></i><?= htmlspecialchars($txn['staff_name']) ?></td>
                                <td><?= htmlspecialchars($txn['payment_mode']) ?></td>
                                <td class="amount-text">GHS <?= number_format($txn['amount'], 2) ?></td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if ($recent_transactions->num_rows === 0): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 30px; color: var(--text-muted);">No transactions found in this date range.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <script>
        Chart.defaults.font.family = "'Segoe UI', sans-serif";
        Chart.defaults.color = '#475569';
        const professionalPalette = ['#0ea5e9', '#8b5cf6', '#10b981', '#f59e0b', '#f43f5e', '#64748b'];

        // 1. Line Chart (Unfiltered Trailing 6 Months)
        const trendCtx = document.getElementById('revenueTrendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: [<?php 
                    $monthly_revenue->data_seek(0);
                    while($r=$monthly_revenue->fetch_assoc()) echo "'" . date('M y', strtotime($r['month'].'-01')) . "',"; 
                ?>],
                datasets: [{ 
                    label: 'Gross Revenue', 
                    data: [<?php $monthly_revenue->data_seek(0); while($r=$monthly_revenue->fetch_assoc()) echo $r['total'] . ","; ?>], 
                    borderColor: '#0ea5e9', backgroundColor: 'rgba(14, 165, 233, 0.1)', borderWidth: 2,
                    pointBackgroundColor: '#ffffff', pointBorderColor: '#0ea5e9', pointRadius: 4, fill: true, tension: 0
                }]
            },
            options: { 
                responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { 
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, border: { display: false }, ticks: { callback: (val) => 'GHS ' + val.toLocaleString() } } 
                } 
            }
        });

        // 2. Section Chart (Filtered)
        const sectionCtx = document.getElementById('sectionChart').getContext('2d');
        new Chart(sectionCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php while($r=$section_revenue->fetch_assoc()) echo "'" . htmlspecialchars($r['section']) . "',"; ?>],
                datasets: [{
                    data: [<?php $section_revenue->data_seek(0); while($r=$section_revenue->fetch_assoc()) echo $r['total'] . ","; ?>],
                    backgroundColor: professionalPalette, borderWidth: 0
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '75%', plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8 } } } }
        });

        // 3. Staff Performance (Filtered)
        const staffCtx = document.getElementById('staffChart').getContext('2d');
        new Chart(staffCtx, {
            type: 'bar',
            data: {
                labels: [<?php while($r=$staff_revenue->fetch_assoc()) echo "'" . htmlspecialchars($r['staff_name']) . "',"; ?>],
                datasets: [{
                    label: 'Revenue Generated',
                    data: [<?php $staff_revenue->data_seek(0); while($r=$staff_revenue->fetch_assoc()) echo $r['total'] . ","; ?>],
                    backgroundColor: '#8b5cf6', borderRadius: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, grid: { color: '#e2e8f0' }, border: { display: false } }, y: { grid: { display: false }, border: { display: false } } }
            }
        });

        // 4. Services Volume (Filtered)
        const servicesCtx = document.getElementById('servicesChart').getContext('2d');
        new Chart(servicesCtx, {
            type: 'bar',
            data: {
                labels: [<?php while($r=$top_services->fetch_assoc()) echo "'" . htmlspecialchars($r['service_type']) . "',"; ?>],
                datasets: [{
                    label: 'Service Count',
                    data: [<?php $top_services->data_seek(0); while($r=$top_services->fetch_assoc()) echo $r['volume'] . ","; ?>],
                    backgroundColor: '#10b981', borderRadius: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false }, border: { display: false } }, y: { beginAtZero: true, border: { display: false }, ticks: { stepSize: 1 } } }
            }
        });

        // 5. Payment Methods (Filtered)
        const paymentCtx = document.getElementById('paymentChart').getContext('2d');
        new Chart(paymentCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php while($r=$payment_methods->fetch_assoc()) echo "'" . htmlspecialchars($r['payment_mode']) . "',"; ?>],
                datasets: [{
                    data: [<?php $payment_methods->data_seek(0); while($r=$payment_methods->fetch_assoc()) echo $r['total'] . ","; ?>],
                    backgroundColor: ['#f59e0b', '#0ea5e9', '#f43f5e', '#64748b'], borderWidth: 0
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '75%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } } }
        });
    </script>
</body>
</html>