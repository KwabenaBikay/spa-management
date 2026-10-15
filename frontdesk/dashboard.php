<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';
include '../includes/client_timer.php';
include '../includes/due_appointments.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['frontdesk', 'reception'])) {
    header("Location: ../login.php");
    exit;
}

ensure_client_timer_schema($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mark_reminded'])) {
    $caller = trim((string)($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? 'Front desk'));
    mark_appointment_reminded($conn, (int)($_POST['appointment_id'] ?? 0), $caller);
    header('Location: dashboard.php');
    exit;
}

$today = date('Y-m-d');

$ongoing_services = [];
$stmt = $conn->prepare("SELECT * FROM clients WHERE DATE(created_at) = ? AND voided_at IS NULL ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $ongoing_res = $stmt->get_result();
    if ($ongoing_res) {
        while ($row = $ongoing_res->fetch_assoc()) {
            $ongoing_services[] = attach_timer_fields($row);
        }
    }
}
$stats = floor_stats_from_queue($ongoing_services);
$active_services = sort_queue_for_floor(array_values(array_filter($ongoing_services, static function ($client) {
    return ($client['status'] ?? 'Pending') !== 'Done';
})), 'active');
$due_today = fetch_due_appointments_today($conn);
$due_late_count = 0;
foreach ($due_today as $booking) {
    if (!empty($booking['is_late'])) {
        $due_late_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Front Office Dashboard | The Breeze Spa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        :root {
            --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
            --text-main: #1e293b; --text-muted: #475569; --border-color: #e2e8f0;
            --sidebar-width: 250px; --topbar-height: 60px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; overflow-x: hidden; font-size: 15px; }

        .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; border-right: 1px solid #1e293b; }
        .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; display: flex; align-items: center; justify-content: center; }
        .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
        .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; font-size: 14px; }
        .user-profile h4 { color: #ffffff; font-size: 15px; font-weight: 600; margin-bottom: 4px; }
        .user-profile p { color: #94a3b8; font-size: 12px; display: flex; align-items: center; gap: 6px; }
        .user-profile p i { font-size: 10px; color: #10b981; }
        
        .sidebar-menu { padding: 20px 0; flex: 1; }
        .menu-item { display: flex; align-items: center; padding: 14px 24px; color: #cbd5e1; text-decoration: none; font-size: 15px; font-weight: 500; border-left: 4px solid transparent; transition: all 0.2s; }
        .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
        .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
        .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
        .logout-btn { display: flex; align-items: center; padding: 16px 24px; color: #ef4444; text-decoration: none; font-size: 15px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
        .logout-btn:hover { background-color: #ef4444; color: #ffffff; }

        .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; width: calc(100% - var(--sidebar-width)); }
        .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; gap: 16px; }
        .topbar h2 { font-size: 18px; font-weight: 600; }
        .topbar .date-stamp { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .btn-register { display: inline-flex; align-items: center; gap: 8px; background: var(--brand-blue); color: #fff; text-decoration: none; font-weight: 700; font-size: 13px; padding: 10px 14px; }
        .btn-register:hover { background: #0284c7; }
        .content-wrapper { padding: 24px 30px 40px; flex: 1; width: 100%; }

        .kpi-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; margin-bottom: 20px; }
        .kpi-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 16px 18px; border-left: 4px solid var(--brand-blue); }
        .kpi-value { font-size: 22px; font-weight: 700; color: var(--text-main); line-height: 1.2; }
        .kpi-label { font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.4px; margin-bottom: 6px; }
        .kpi-sub { font-size: 12px; color: var(--text-muted); font-weight: 600; margin-top: 4px; }
        .kpi-card.kpi-wait { border-left-color: #d97706; }
        .kpi-card.kpi-session { border-left-color: #0284c7; }
        .kpi-card.kpi-alert { border-left-color: #dc2626; }
        .kpi-card.kpi-alert .kpi-value { color: #dc2626; }
        .kpi-card.kpi-pay { border-left-color: #10b981; }
        .section-split { display: grid; grid-template-columns: minmax(0, 2.2fr) minmax(280px, 1fr); gap: 20px; align-items: start; }
        @media (max-width: 1024px) { .section-split { grid-template-columns: 1fr; } }

        .action-grid { display: none; }
        .dashboard-grid { display: grid; grid-template-columns: 1fr; gap: 20px; margin-top: 20px; }
        .flat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 22px; }
        .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .flat-card-header h3 { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }

        .queue-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .queue-search { width: 220px; padding: 7px 10px; border: 1px solid var(--border-color); font-size: 13px; background: #f8fafc; }
        .queue-search:focus { outline: none; border-color: var(--brand-blue); background: #fff; }
        .client-phone { display: block; font-size: 11px; color: var(--text-muted); font-weight: 500; margin-top: 2px; }
        .wait-note { display: block; font-size: 11px; font-weight: 700; color: #d97706; margin-top: 3px; }
        tr.row-wait { background: #fffbeb; }
        tr.row-wait-long { background: #fef2f2; }
        tr.row-wait-long .wait-note { color: #dc2626; }
        .occ-stack { text-align: right; font-size: 12px; font-weight: 700; line-height: 1.45; white-space: nowrap; }
        .occ-wait { color: #d97706; }
        .occ-session { color: #0284c7; }
        .occ-over { color: #dc2626; }
        .occ-clear { color: #16a34a; }
        .queue-tab { padding: 7px 12px; font-size: 12px; font-weight: 700; background: #fff; color: var(--text-muted); border: none; cursor: pointer; }
        .queue-tab.active { background: var(--brand-dark); color: #fff; }

        .rank-row { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .rank-row:last-child { border-bottom: none; }
        .rank-name { font-weight: 600; }
        .rank-meta { color: var(--text-muted); font-size: 12px; margin-top: 2px; }
        .rank-value { font-weight: 700; white-space: nowrap; }
        .right-stack { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
        .due-list { max-height: 360px; overflow-y: auto; }
        .due-row { display: grid; grid-template-columns: 72px minmax(0, 1fr) auto; gap: 10px; padding: 12px 0; border-bottom: 1px solid #f1f5f9; align-items: center; }
        .due-actions { display: flex; flex-direction: column; gap: 6px; align-items: stretch; }
        .due-called { font-size: 11px; font-weight: 700; color: #065f46; text-transform: uppercase; }
        .due-row:last-child { border-bottom: none; }
        .due-time { font-weight: 800; font-variant-numeric: tabular-nums; font-size: 13px; }
        .due-row.late .due-time { color: #dc2626; }
        .due-name { font-weight: 700; font-size: 14px; }
        .due-meta { color: var(--text-muted); font-size: 12px; margin-top: 2px; }
        .due-late { display: inline-block; margin-top: 4px; font-size: 10px; font-weight: 800; letter-spacing: 0.4px; text-transform: uppercase; color: #dc2626; }
        .btn-checkin { display: inline-flex; align-items: center; gap: 6px; padding: 7px 10px; font-size: 12px; font-weight: 700; color: #fff; background: var(--brand-blue); text-decoration: none; white-space: nowrap; }
        .btn-checkin:hover { background: #0284c7; }
        .link-quiet { font-size: 12px; font-weight: 700; color: var(--brand-blue); text-decoration: none; }
        .link-quiet:hover { text-decoration: underline; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 14px; }
        th { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 12px; background: #f8fafc; }

        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; font-size: 12px; font-weight: 600; border-radius: 4px; text-transform: uppercase; border: 1px solid; }
        .badge-pending { background-color: #fef3c7; color: #d97706; border-color: #fde68a; }
        .badge-inprogress { background-color: #e0f2fe; color: #0284c7; border-color: #bae6fd; }
        .badge-done { background-color: #dcfce7; color: #16a34a; border-color: #bbf7d0; }
        .badge-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        .badge-pending .badge-dot { background-color: #d97706; }
        .badge-inprogress .badge-dot { background-color: #0284c7; animation: pulse 1.5s infinite; }
        .badge-done .badge-dot { background-color: #16a34a; }
        @keyframes pulse {
            0% { transform: scale(0.9); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.5; }
            100% { transform: scale(0.9); opacity: 1; }
        }

        @media (max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; width: 100%; } .kpi-grid { grid-template-columns: 1fr 1fr; } }

        /* Real-time Toast Notifications */
        #toast-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 1000;
            pointer-events: none;
        }
        .toast-notification {
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            color: #ffffff;
            padding: 16px 24px;
            border-radius: 4px;
            border-left: 4px solid #10b981;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -4px rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            gap: 15px;
            transform: translateX(120%);
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            max-width: 380px;
            pointer-events: auto;
        }
        .toast-notification.show {
            transform: translateX(0);
        }
        .toast-icon {
            font-size: 24px;
            color: #10b981;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .toast-content h4 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 2px;
            color: #ffffff;
        }
        .toast-content p {
            font-size: 13px;
            color: #cbd5e1;
            line-height: 1.4;
        }
        .toast-close {
            margin-left: auto;
            cursor: pointer;
            color: #94a3b8;
            background: none;
            border: none;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
        }
        .toast-close:hover {
            color: #ffffff;
        }
        .toast-notification.toast-timeup {
            border-left-color: #f59e0b;
        }
        .toast-notification.toast-timeup .toast-icon {
            color: #f59e0b;
        }

        .countdown {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-variant-numeric: tabular-nums;
            font-weight: 700;
            font-size: 13px;
            color: #0284c7;
            white-space: nowrap;
        }
        .countdown.warn { color: #d97706; }
        .countdown.over { color: #dc2626; font-weight: 800; }
        .countdown.over .over-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; }
        .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
            color: #0284c7;
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-print:hover { background: #0ea5e9; color: #ffffff; border-color: #0ea5e9; }
        .countdown-idle { color: var(--text-muted); font-weight: 500; font-size: 12px; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header"><img src="../assets/images/The-Breeze-1.png" alt="Logo" class="sidebar-logo"></div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><i class="fas fa-circle"></i> Front Office Ops</p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item active"><i class="fas fa-desktop"></i> Station Dashboard</a>
            <a href="add_client.php" class="menu-item"><i class="fas fa-user-plus"></i> Register Client</a>
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="view_clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
            <a href="till.php" class="menu-item"><i class="fas fa-cash-register"></i> Close of Day</a>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket" style="margin-right:12px;"></i> End Shift</a>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Floor Station</h2>
            <div class="topbar-right">
                <div class="date-stamp"><?= date("l, F j, Y") ?></div>
                <a class="btn-register" href="add_client.php"><i class="fas fa-user-plus"></i> Register Client</a>
            </div>
        </header>

        <div class="content-wrapper">

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Check-ins</div>
                    <div class="kpi-value" id="kpi-checkins"><?= number_format((int)$stats['checkins']) ?></div>
                    <div class="kpi-sub">Today</div>
                </div>
                <div class="kpi-card" style="border-left-color: #10b981;">
                    <div class="kpi-label">Revenue</div>
                    <div class="kpi-value" id="kpi-revenue">GHS <?= number_format((float)$stats['revenue'], 2) ?></div>
                    <div class="kpi-sub">Collected today</div>
                </div>
                <div class="kpi-card kpi-wait">
                    <div class="kpi-label">Waiting</div>
                    <div class="kpi-value" id="kpi-waiting"><?= number_format((int)$stats['waiting']) ?></div>
                    <div class="kpi-sub">Not started</div>
                </div>
                <div class="kpi-card kpi-session">
                    <div class="kpi-label">In Session</div>
                    <div class="kpi-value" id="kpi-in-progress"><?= number_format((int)$stats['in_progress']) ?></div>
                    <div class="kpi-sub">Being served</div>
                </div>
                <div class="kpi-card <?= ((int)$stats['overtime'] > 0) ? 'kpi-alert' : '' ?>" id="kpi-overtime-card">
                    <div class="kpi-label">Overtime</div>
                    <div class="kpi-value" id="kpi-overtime"><?= number_format((int)$stats['overtime']) ?></div>
                    <div class="kpi-sub">Past booked time</div>
                </div>
                <div class="kpi-card kpi-pay">
                    <div class="kpi-label">Cash / MoMo</div>
                    <div class="kpi-value" id="kpi-pay">GHS <?= number_format((float)$stats['cash'], 0) ?> / <?= number_format((float)$stats['momo'], 0) ?></div>
                    <div class="kpi-sub" id="kpi-pay-other"><?= ((float)$stats['other'] > 0) ? 'Other GHS ' . number_format((float)$stats['other'], 2) : 'Payment mix' ?></div>
                </div>
            </div>

            <div class="section-split">
                <div class="flat-card">
                    <div class="flat-card-header">
                        <h3>Today's Queue</h3>
                        <div class="queue-toolbar">
                            <input type="search" id="queue-search" class="queue-search" placeholder="Find name, code, phone" autocomplete="off">
                            <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;"><i class="fas fa-circle-notch fa-spin" style="color: var(--brand-blue); margin-right: 6px;"></i> Live</span>
                            <div class="queue-tabs" role="tablist">
                                <button type="button" class="queue-tab active" data-view="active" id="tab-active">Active (<?= (int)$stats['waiting'] + (int)$stats['in_progress'] ?>)</button>
                                <button type="button" class="queue-tab" data-view="completed" id="tab-completed">Completed (<?= (int)$stats['done'] ?>)</button>
                            </div>
                        </div>
                    </div>
                    <div class="table-container" style="overflow-x: auto;">
                        <table style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>Arrival</th>
                                    <th>Code</th>
                                    <th>Client</th>
                                    <th>Service / Section</th>
                                    <th>Staff</th>
                                    <th>Status</th>
                                    <th>Time Remaining</th>
                                    <th>Receipt</th>
                                </tr>
                            </thead>
                            <tbody id="queue-table-body">
                                <?php foreach ($active_services as $client):
                                    $status = $client['status'] ?? 'Pending';
                                    $badge_class = 'badge-pending';
                                    if ($status === 'In Progress') {
                                        $badge_class = 'badge-inprogress';
                                    } elseif ($status === 'Done') {
                                        $badge_class = 'badge-done';
                                    }
                                    $formatted_time = date('h:i A', strtotime($client['created_at']));
                                    $service_display = htmlspecialchars($client['service_type']);
                                    if (!empty($client['massage_type'])) {
                                        $service_display .= ' (' . htmlspecialchars($client['massage_type']) . ')';
                                    }
                                    $wait_mins = waiting_minutes($client);
                                    $row_class = wait_row_class($wait_mins);
                                ?>
                                <tr class="<?= htmlspecialchars($row_class) ?>">
                                    <td style="color: var(--text-muted); font-weight: 500;">
                                        <?= $formatted_time ?>
                                        <?php if ($wait_mins !== null): ?>
                                        <span class="wait-note" data-created-unix="<?= (int)$client['created_unix'] ?>"><?= (int)$wait_mins ?> min wait</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?= htmlspecialchars($client['client_code']) ?></code></td>
                                    <td>
                                        <span style="font-weight: 600;"><?= htmlspecialchars($client['name']) ?></span>
                                        <?php if (!empty($client['phone'])): ?>
                                        <span class="client-phone"><?= htmlspecialchars($client['phone']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-weight: 500;"><?= $service_display ?></span>
                                        <span style="display: block; font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($client['section']) ?></span>
                                    </td>
                                    <td style="font-weight: 500;"><?= htmlspecialchars($client['staff_name'] ?? 'Unassigned') ?></td>
                                    <td>
                                        <span class="badge <?= $badge_class ?>">
                                            <span class="badge-dot"></span>
                                            <?= htmlspecialchars($status) ?>
                                        </span>
                                    </td>
                                    <td><?= countdown_cell_html($client) ?></td>
                                    <td>
                                        <a class="btn-print" href="print_receipt.php?id=<?= (int)$client['id'] ?>" target="_blank" rel="noopener">
                                            <i class="fas fa-print"></i> Print
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($active_services)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;"><?= empty($ongoing_services) ? 'No clients registered today.' : 'No clients waiting or in session.' ?></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="right-stack">
                    <div class="flat-card">
                        <div class="flat-card-header">
                            <h3 id="due-heading">Due Today (<?= count($due_today) ?>)<?= $due_late_count ? ' · ' . $due_late_count . ' late' : '' ?></h3>
                            <a class="link-quiet" href="appointments.php">View all</a>
                        </div>
                        <div id="due-today-list" class="due-list">
                            <?php if (empty($due_today)): ?>
                                <p style="color: var(--text-muted); font-size: 14px; padding: 18px 0; text-align: center;">No bookings left for today.</p>
                            <?php else: ?>
                                <?php foreach ($due_today as $booking): ?>
                                <div class="due-row<?= !empty($booking['is_late']) ? ' late' : '' ?>">
                                    <div class="due-time"><?= htmlspecialchars($booking['time_label']) ?></div>
                                    <div>
                                        <div class="due-name"><?= htmlspecialchars($booking['client_name']) ?></div>
                                        <div class="due-meta"><?= htmlspecialchars($booking['service_type']) ?><?= $booking['staff_assigned'] !== '' ? ' · ' . htmlspecialchars($booking['staff_assigned']) : '' ?></div>
                                        <?php if (!empty($booking['phone'])): ?>
                                        <div class="due-meta"><?= htmlspecialchars($booking['phone']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($booking['is_late'])): ?>
                                        <span class="due-late">Late</span>
                                        <?php endif; ?>
                                        <?php if (!empty($booking['reminded'])): ?>
                                        <span class="due-called">Called</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="due-actions">
                                        <?php if (empty($booking['reminded']) && !empty($booking['phone'])): ?>
                                        <a class="btn-checkin" href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $booking['phone'])) ?>">Call</a>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="mark_reminded" value="1">
                                            <input type="hidden" name="appointment_id" value="<?= (int)$booking['id'] ?>">
                                            <button type="submit" class="btn-checkin" style="width:100%;">Called</button>
                                        </form>
                                        <?php endif; ?>
                                        <a class="btn-checkin" href="add_client.php?appointment_id=<?= (int)$booking['id'] ?>">Check in</a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flat-card">
                        <div class="flat-card-header"><h3>Floor by Section</h3></div>
                        <div id="section-rank">
                            <?php if (empty($stats['sections'])): ?>
                                <p style="color: var(--text-muted); font-size: 14px; padding: 18px 0; text-align: center;">No check-ins yet today.</p>
                            <?php else: ?>
                                <?php foreach ($stats['sections'] as $section_name => $section_row):
                                    $wait_n = (int)($section_row['waiting'] ?? 0);
                                    $sess_n = (int)($section_row['in_progress'] ?? 0);
                                    $over_n = (int)($section_row['overtime'] ?? 0);
                                    $visits_n = (int)($section_row['visits'] ?? 0);
                                ?>
                                <div class="rank-row">
                                    <div>
                                        <div class="rank-name"><?= htmlspecialchars($section_name) ?></div>
                                        <div class="rank-meta"><?= $visits_n ?> today</div>
                                    </div>
                                    <div class="occ-stack">
                                        <?php if ($wait_n === 0 && $sess_n === 0 && $over_n === 0): ?>
                                            <span class="occ-clear">Clear</span>
                                        <?php else: ?>
                                            <?php if ($wait_n > 0): ?><div class="occ-wait"><?= $wait_n ?> waiting</div><?php endif; ?>
                                            <?php if ($sess_n > 0): ?><div class="occ-session"><?= $sess_n ?> in session</div><?php endif; ?>
                                            <?php if ($over_n > 0): ?><div class="occ-over"><?= $over_n ?> overtime</div><?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        let clientStatuses = {};
        <?php foreach ($ongoing_services as $client): ?>
        clientStatuses[<?= (int)$client['id'] ?>] = <?= json_encode($client['status'] ?? 'Pending') ?>;
        <?php endforeach; ?>

        const csrfToken = <?= json_encode(csrf_token()) ?>;
        let latestQueue = <?= json_encode($ongoing_services, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        let latestDueToday = <?= json_encode($due_today, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        let queueView = 'active';
        let queueQuery = '';
        let serverUnix = <?= time() ?>;
        let serverUnixCapturedAt = Date.now();
        const timeUpBeeped = new Set();

        function nowUnix() {
            return serverUnix + (Date.now() - serverUnixCapturedAt) / 1000;
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function formatCountdown(totalSeconds) {
            const secs = Math.max(0, Math.floor(totalSeconds));
            const h = Math.floor(secs / 3600);
            const m = Math.floor((secs % 3600) / 60);
            const s = secs % 60;
            const mmss = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
            return h > 0 ? (String(h) + ':' + mmss) : mmss;
        }

        function countdownCellHtml(client) {
            const status = client.status || 'Pending';
            const mins = parseInt(client.duration_minutes, 10);
            const started = parseInt(client.service_started_unix, 10);
            const rawDuration = (client.duration || '').trim();

            if (!mins || status === 'Done') {
                return '<span class="countdown-idle">—</span>';
            }
            if (status !== 'In Progress' || !started) {
                return `<span class="countdown-idle">${escapeHtml(rawDuration || (mins + ' min'))}</span>`;
            }

            const serviceDisplay = client.massage_type
                ? `${client.service_type} (${client.massage_type})`
                : client.service_type;

            return `<span class="countdown" data-client-id="${parseInt(client.id, 10)}" data-started-unix="${started}" data-minutes="${mins}" data-name="${escapeHtml(client.name)}" data-service="${escapeHtml(serviceDisplay)}"><i class="fas fa-clock"></i> —</span>`;
        }

        function money(value, digits) {
            return Number(value || 0).toLocaleString(undefined, {
                minimumFractionDigits: digits,
                maximumFractionDigits: digits
            });
        }

        function countOvertime(queue) {
            const now = nowUnix();
            let count = 0;
            for (const client of queue || []) {
                if ((client.status || 'Pending') !== 'In Progress') continue;
                const mins = parseInt(client.duration_minutes, 10);
                const started = parseInt(client.service_started_unix, 10);
                if (mins && started && (started + mins * 60) <= now) count++;
            }
            return count;
        }

        function setOvertimeKpi(count) {
            const el = document.getElementById('kpi-overtime');
            const card = document.getElementById('kpi-overtime-card');
            if (el) el.textContent = Number(count).toLocaleString();
            if (card) card.classList.toggle('kpi-alert', Number(count) > 0);
        }

        function applyKpis(kpis) {
            if (!kpis) return;
            const checkinsEl = document.getElementById('kpi-checkins');
            const revenueEl = document.getElementById('kpi-revenue');
            const waitingEl = document.getElementById('kpi-waiting');
            const inProgressEl = document.getElementById('kpi-in-progress');
            const payEl = document.getElementById('kpi-pay');
            const payOtherEl = document.getElementById('kpi-pay-other');
            const tabActive = document.getElementById('tab-active');
            const tabCompleted = document.getElementById('tab-completed');

            if (checkinsEl) checkinsEl.textContent = Number(kpis.total_today).toLocaleString();
            if (revenueEl) revenueEl.textContent = 'GHS ' + money(kpis.rev_today, 2);
            if (waitingEl) waitingEl.textContent = Number(kpis.waiting).toLocaleString();
            if (inProgressEl) inProgressEl.textContent = Number(kpis.in_progress).toLocaleString();
            setOvertimeKpi(countOvertime(latestQueue));
            if (payEl) payEl.textContent = 'GHS ' + money(kpis.cash, 0) + ' / ' + money(kpis.momo, 0);
            if (payOtherEl) {
                payOtherEl.textContent = Number(kpis.other) > 0 ? ('Other GHS ' + money(kpis.other, 2)) : 'Payment mix';
            }
            if (tabActive) tabActive.textContent = 'Active (' + (Number(kpis.waiting) + Number(kpis.in_progress)) + ')';
            if (tabCompleted) tabCompleted.textContent = 'Completed (' + Number(kpis.done) + ')';
        }

        function renderDueToday(bookings) {
            const list = document.getElementById('due-today-list');
            const heading = document.getElementById('due-heading');
            const rows = bookings || [];
            let lateCount = 0;
            for (const booking of rows) {
                const unix = parseInt(booking.appointment_unix, 10);
                if (unix ? unix < nowUnix() : booking.is_late) lateCount++;
            }
            if (heading) {
                heading.textContent = 'Due Today (' + rows.length + ')' + (lateCount ? ' · ' + lateCount + ' late' : '');
            }
            if (!list) return;
            if (!rows.length) {
                list.innerHTML = '<p style="color: var(--text-muted); font-size: 14px; padding: 18px 0; text-align: center;">No bookings left for today.</p>';
                return;
            }
            list.innerHTML = rows.map(booking => {
                const staff = (booking.staff_assigned || '').trim();
                const phone = (booking.phone || '').trim();
                const unix = parseInt(booking.appointment_unix, 10);
                const late = unix ? unix < nowUnix() : !!booking.is_late;
                return `<div class="due-row${late ? ' late' : ''}">
                    <div class="due-time">${escapeHtml(booking.time_label || '')}</div>
                    <div>
                        <div class="due-name">${escapeHtml(booking.client_name || '')}</div>
                        <div class="due-meta">${escapeHtml(booking.service_type || '')}${staff ? ' · ' + escapeHtml(staff) : ''}</div>
                        ${phone ? `<div class="due-meta">${escapeHtml(phone)}</div>` : ''}
                        ${late ? '<span class="due-late">Late</span>' : ''}
                        ${booking.reminded ? '<span class="due-called">Called</span>' : ''}
                    </div>
                    <div class="due-actions">
                        ${!booking.reminded && phone ? `<a class="btn-checkin" href="tel:${escapeHtml(phone.replace(/\s+/g, ''))}">Call</a>
                        <form method="post"><input type="hidden" name="csrf" value="${escapeHtml(csrfToken)}"><input type="hidden" name="mark_reminded" value="1"><input type="hidden" name="appointment_id" value="${parseInt(booking.id, 10)}"><button type="submit" class="btn-checkin" style="width:100%;">Called</button></form>` : ''}
                        <a class="btn-checkin" href="add_client.php?appointment_id=${parseInt(booking.id, 10)}">Check in</a>
                    </div>
                </div>`;
            }).join('');
        }

        function renderSections(sections) {
            const box = document.getElementById('section-rank');
            if (!box) return;
            const entries = Object.entries(sections || {});
            if (!entries.length) {
                box.innerHTML = '<p style="color: var(--text-muted); font-size: 14px; padding: 18px 0; text-align: center;">No check-ins yet today.</p>';
                return;
            }
            entries.sort((a, b) => {
                const backlogA = Number(a[1].waiting || 0) + Number(a[1].overtime || 0);
                const backlogB = Number(b[1].waiting || 0) + Number(b[1].overtime || 0);
                if (backlogA !== backlogB) return backlogB - backlogA;
                const sess = Number(b[1].in_progress || 0) - Number(a[1].in_progress || 0);
                if (sess) return sess;
                return Number(b[1].visits || 0) - Number(a[1].visits || 0);
            });
            box.innerHTML = entries.map(([name, row]) => {
                const visits = Number(row.visits || 0);
                const waitN = Number(row.waiting || 0);
                const sessN = Number(row.in_progress || 0);
                const overN = Number(row.overtime || 0);
                let occ = '<span class="occ-clear">Clear</span>';
                if (waitN || sessN || overN) {
                    occ = '';
                    if (waitN) occ += `<div class="occ-wait">${waitN} waiting</div>`;
                    if (sessN) occ += `<div class="occ-session">${sessN} in session</div>`;
                    if (overN) occ += `<div class="occ-over">${overN} overtime</div>`;
                }
                return `<div class="rank-row">
                    <div>
                        <div class="rank-name">${escapeHtml(name)}</div>
                        <div class="rank-meta">${visits} today</div>
                    </div>
                    <div class="occ-stack">${occ}</div>
                </div>`;
            }).join('');
        }

        function occupancyFromQueue(queue) {
            const now = nowUnix();
            const sections = {};
            for (const client of queue || []) {
                const section = String(client.section || '').trim() || 'Unspecified';
                if (!sections[section]) {
                    sections[section] = { visits: 0, waiting: 0, in_progress: 0, overtime: 0 };
                }
                sections[section].visits++;
                const status = client.status || 'Pending';
                if (status === 'Done') continue;
                if (status === 'In Progress') {
                    sections[section].in_progress++;
                    const mins = parseInt(client.duration_minutes, 10);
                    const started = parseInt(client.service_started_unix, 10);
                    if (mins && started && (started + mins * 60) <= now) {
                        sections[section].overtime++;
                    }
                } else {
                    sections[section].waiting++;
                }
            }
            return sections;
        }

        function createdUnix(client) {
            const fromField = parseInt(client.created_unix, 10);
            if (fromField) return fromField;
            const raw = client.created_at ? new Date(String(client.created_at).replace(/-/g, '/')).getTime() : 0;
            return raw ? Math.floor(raw / 1000) : 0;
        }

        function sortQueueForView(list, view) {
            const now = nowUnix();
            const rows = (list || []).slice();
            rows.sort((a, b) => {
                const createdA = createdUnix(a);
                const createdB = createdUnix(b);
                if (view === 'completed') return createdB - createdA;

                const statusA = a.status || 'Pending';
                const statusB = b.status || 'Pending';
                const rankA = statusA === 'In Progress' ? 1 : 0;
                const rankB = statusB === 'In Progress' ? 1 : 0;
                if (rankA !== rankB) return rankA - rankB;
                if (statusA !== 'In Progress') return createdA - createdB;

                const minsA = parseInt(a.duration_minutes, 10) || 0;
                const minsB = parseInt(b.duration_minutes, 10) || 0;
                const startedA = parseInt(a.service_started_unix, 10) || 0;
                const startedB = parseInt(b.service_started_unix, 10) || 0;
                const overA = startedA && minsA && (startedA + minsA * 60) <= now ? 0 : 1;
                const overB = startedB && minsB && (startedB + minsB * 60) <= now ? 0 : 1;
                if (overA !== overB) return overA - overB;
                return (startedA + minsA * 60) - (startedB + minsB * 60);
            });
            return rows;
        }

        function matchesQueueSearch(client, query) {
            if (!query) return true;
            const hay = [client.name, client.client_code, client.phone].join(' ').toLowerCase();
            return hay.includes(query);
        }

        function waitMinutes(client) {
            if ((client.status || 'Pending') !== 'Pending') return null;
            const created = createdUnix(client);
            if (!created) return null;
            return Math.max(0, Math.floor((nowUnix() - created) / 60));
        }

        function waitRowClass(mins) {
            if (mins === null) return '';
            if (mins >= 30) return 'row-wait-long';
            if (mins >= 15) return 'row-wait';
            return '';
        }

        function queueRowHtml(client) {
            const clientId = parseInt(client.id, 10);
            const status = client.status || 'Pending';
            const timeStr = formatAMPM(client.created_at);
            const badgeClass = getBadgeClass(status);
            const serviceDisplay = client.massage_type
                ? `${client.service_type} (${client.massage_type})`
                : client.service_type;
            const mins = waitMinutes(client);
            const created = createdUnix(client);
            const waitHtml = mins === null
                ? ''
                : `<span class="wait-note" data-created-unix="${created}">${mins} min wait</span>`;
            const phone = (client.phone || '').trim();

            return `
                <tr class="${waitRowClass(mins)}">
                    <td style="color: var(--text-muted); font-weight: 500;">${escapeHtml(timeStr)}${waitHtml}</td>
                    <td><code>${escapeHtml(client.client_code)}</code></td>
                    <td>
                        <span style="font-weight: 600;">${escapeHtml(client.name)}</span>
                        ${phone ? `<span class="client-phone">${escapeHtml(phone)}</span>` : ''}
                    </td>
                    <td>
                        <span style="font-weight: 500;">${escapeHtml(serviceDisplay)}</span>
                        <span style="display: block; font-size: 11px; color: var(--text-muted);">${escapeHtml(client.section)}</span>
                    </td>
                    <td style="font-weight: 500;">${escapeHtml(client.staff_name || 'Unassigned')}</td>
                    <td>
                        <span class="badge ${badgeClass}">
                            <span class="badge-dot"></span>
                            ${escapeHtml(status)}
                        </span>
                    </td>
                    <td>${countdownCellHtml(client)}</td>
                    <td>
                        <a class="btn-print" href="print_receipt.php?id=${clientId}" target="_blank" rel="noopener">
                            <i class="fas fa-print"></i> Print
                        </a>
                    </td>
                </tr>
            `;
        }

        function renderQueue(queue) {
            const tbody = document.getElementById('queue-table-body');
            if (!tbody) return;
            const all = queue || [];
            if (all.length === 0 && !queueQuery) {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">No clients registered today.</td></tr>`;
                return;
            }

            let filtered = all.filter(client => matchesQueueSearch(client, queueQuery));
            if (!queueQuery) {
                filtered = filtered.filter(client => {
                    const done = (client.status || 'Pending') === 'Done';
                    return queueView === 'completed' ? done : !done;
                });
                filtered = sortQueueForView(filtered, queueView);
            } else {
                const waiting = filtered.filter(c => (c.status || 'Pending') !== 'Done');
                const done = filtered.filter(c => (c.status || 'Pending') === 'Done');
                filtered = sortQueueForView(waiting, 'active').concat(sortQueueForView(done, 'completed'));
            }

            if (filtered.length === 0) {
                let empty = queueView === 'completed'
                    ? 'No completed services yet today.'
                    : 'No clients waiting or in session.';
                if (queueQuery) empty = 'No match for that name, code, or phone.';
                tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">${empty}</td></tr>`;
                return;
            }

            tbody.innerHTML = filtered.map(queueRowHtml).join('');
            tickCountdowns();
        }

        function tickCountdowns() {
            document.querySelectorAll('.countdown[data-started-unix]').forEach(el => {
                const started = parseInt(el.dataset.startedUnix, 10);
                const minutes = parseInt(el.dataset.minutes, 10);
                const clientId = el.dataset.clientId;
                if (!started || !minutes) return;

                const remaining = (started + minutes * 60) - nowUnix();
                el.classList.remove('warn', 'over');

                if (remaining <= 0) {
                    const overtime = Math.abs(remaining);
                    el.classList.add('over');
                    el.innerHTML = '<i class="fas fa-clock"></i> +' + formatCountdown(overtime) + ' <span class="over-label">over</span>';
                    if (clientId && !timeUpBeeped.has(clientId)) {
                        timeUpBeeped.add(clientId);
                        showTimeUpToast(el.dataset.name || 'Client', el.dataset.service || 'service');
                    }
                    return;
                }

                if (remaining <= 5 * 60) {
                    el.classList.add('warn');
                }
                el.innerHTML = '<i class="fas fa-clock"></i> ' + formatCountdown(remaining);
            });
            setOvertimeKpi(countOvertime(latestQueue));
            document.querySelectorAll('.wait-note[data-created-unix]').forEach(el => {
                const created = parseInt(el.dataset.createdUnix, 10);
                if (!created) return;
                const mins = Math.max(0, Math.floor((nowUnix() - created) / 60));
                el.textContent = mins + ' min wait';
                const row = el.closest('tr');
                if (!row) return;
                row.classList.toggle('row-wait', mins >= 15 && mins < 30);
                row.classList.toggle('row-wait-long', mins >= 30);
            });
            renderSections(occupancyFromQueue(latestQueue));
        }

        function playAlertSound() {
            // Try playing custom MP3 file first
            const audio = new Audio('../assets/sounds/notification.mp3');
            audio.volume = 0.5;
            audio.play().catch(() => {
                // Fallback: Louder rising chime (C5 to E5)
                try {
                    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                    
                    // Tone 1: C5
                    const osc1 = audioCtx.createOscillator();
                    const gain1 = audioCtx.createGain();
                    osc1.connect(gain1);
                    gain1.connect(audioCtx.destination);
                    osc1.type = 'sine';
                    osc1.frequency.setValueAtTime(523.25, audioCtx.currentTime); 
                    gain1.gain.setValueAtTime(0.3, audioCtx.currentTime);
                    osc1.start();
                    osc1.stop(audioCtx.currentTime + 0.15);
                    
                    // Tone 2: E5 (played slightly later)
                    setTimeout(() => {
                        const osc2 = audioCtx.createOscillator();
                        const gain2 = audioCtx.createGain();
                        osc2.connect(gain2);
                        gain2.connect(audioCtx.destination);
                        osc2.type = 'sine';
                        osc2.frequency.setValueAtTime(659.25, audioCtx.currentTime);
                        gain2.gain.setValueAtTime(0.3, audioCtx.currentTime);
                        osc2.start();
                        osc2.stop(audioCtx.currentTime + 0.2);
                    }, 150);
                } catch(e) {}
            });
        }

        function showTimeUpToast(clientName, serviceName) {
            const container = document.getElementById('toast-container') || document.body;
            const toast = document.createElement('div');
            toast.className = 'toast-notification toast-timeup';
            toast.innerHTML = `
                <div class="toast-icon"><i class="fas fa-bell"></i></div>
                <div class="toast-content">
                    <h4>Time is up</h4>
                    <p><strong>${escapeHtml(clientName)}</strong> — ${escapeHtml(serviceName)} duration has ended. Staff still needs to mark the work done.</p>
                </div>
                <button class="toast-close" onclick="this.parentElement.classList.remove('show'); setTimeout(() => this.parentElement.remove(), 400);"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(toast);
            playAlertSound();
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.classList.remove('show');
                    setTimeout(() => toast.remove(), 400);
                }
            }, 8000);
        }

        function showToast(clientName, serviceName) {
            const container = document.getElementById('toast-container') || document.body;
            const toast = document.createElement('div');
            toast.className = 'toast-notification';
            toast.innerHTML = `
                <div class="toast-icon"><i class="fas fa-check-circle"></i></div>
                <div class="toast-content">
                    <h4>Service Completed!</h4>
                    <p><strong>${escapeHtml(clientName)}</strong> is done with ${escapeHtml(serviceName)}.</p>
                </div>
                <button class="toast-close" onclick="this.parentElement.classList.remove('show'); setTimeout(() => this.parentElement.remove(), 400);"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(toast);
            
            playAlertSound();

            setTimeout(() => toast.classList.add('show'), 100);
            
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.classList.remove('show');
                    setTimeout(() => toast.remove(), 400);
                }
            }, 5000);
        }

        function formatAMPM(dateString) {
            const date = new Date(dateString.replace(/-/g, "/"));
            let hours = date.getHours();
            let minutes = date.getMinutes();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            return hours + ':' + minutes + ' ' + ampm;
        }

        function getBadgeClass(status) {
            if (status === 'In Progress') return 'badge-inprogress';
            if (status === 'Done') return 'badge-done';
            return 'badge-pending';
        }

        async function pollQueueStatus() {
            try {
                const response = await fetch('../api/get_queue_status.php');
                if (!response.ok) return;
                const data = await response.json();
                const ongoing = data.queue || [];
                const kpis = data.kpis;

                if (data.server_unix) {
                    serverUnix = Number(data.server_unix);
                    serverUnixCapturedAt = Date.now();
                }

                latestQueue = ongoing;
                latestDueToday = data.due_today || [];

                const nextStatuses = {};
                for (const client of ongoing) {
                    const clientId = parseInt(client.id, 10);
                    const status = client.status || 'Pending';
                    const oldStatus = clientStatuses[clientId];
                    if (oldStatus !== undefined && oldStatus !== 'Done' && status === 'Done') {
                        const serviceDisplay = client.massage_type
                            ? `${client.service_type} (${client.massage_type})`
                            : client.service_type;
                        showToast(client.name, serviceDisplay);
                        timeUpBeeped.delete(String(clientId));
                    }
                    nextStatuses[clientId] = status;
                }
                clientStatuses = nextStatuses;

                applyKpis(kpis);
                renderQueue(latestQueue);
                renderDueToday(latestDueToday);
            } catch (err) {
                console.error('Error polling queue status:', err);
            }
        }

        document.querySelectorAll('.queue-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                queueView = tab.dataset.view === 'completed' ? 'completed' : 'active';
                document.querySelectorAll('.queue-tab').forEach(btn => btn.classList.toggle('active', btn === tab));
                renderQueue(latestQueue);
            });
        });

        const queueSearch = document.getElementById('queue-search');
        if (queueSearch) {
            queueSearch.addEventListener('input', () => {
                queueQuery = queueSearch.value.trim().toLowerCase();
                renderQueue(latestQueue);
            });
        }

        tickCountdowns();
        setInterval(tickCountdowns, 1000);
        setInterval(pollQueueStatus, 5000);
    </script>
    <div id="toast-container"></div>
</body>
</html>