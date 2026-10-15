<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';
include '../includes/due_appointments.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header('Location: login.php');
    exit;
}

$user_role = $_SESSION['user']['role'];
ensure_appointments_table($conn);

$view = $_GET['view'] ?? 'upcoming';
$allowed_views = ['upcoming', 'today', 'completed', 'cancelled'];
if (!in_array($view, $allowed_views, true)) {
    $view = 'upcoming';
}

if (isset($_POST['schedule_appointment'])) {
    $client_name = trim($_POST['client_name'] ?? '');
    $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    $service_type = trim($_POST['service_type'] ?? '');
    $staff_assigned = trim($_POST['staff_assigned'] ?? '');
    $appointment_date = $_POST['appointment_date'] ?? '';
    $appointment_time = $_POST['appointment_time'] ?? '';

    if (strlen($phone) !== 10) {
        $_SESSION['error'] = 'Phone must be exactly 10 digits.';
    } elseif ($client_name === '' || $service_type === '' || $appointment_date === '' || $appointment_time === '') {
        $_SESSION['error'] = 'Please complete the booking details.';
    } else {
        $stmt = $conn->prepare("INSERT INTO appointments (client_name, phone, service_type, staff_assigned, appointment_date, appointment_time) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $client_name, $phone, $service_type, $staff_assigned, $appointment_date, $appointment_time);

        if ($stmt->execute()) {
            $inserted_id = $conn->insert_id;
            $_SESSION['message'] = 'Appointment scheduled.';

            $userId = (int)($_SESSION['user']['id'] ?? 0);
            $logUsername = $_SESSION['user']['username'] ?? '';
            $logUserRole = $_SESSION['user']['role'] ?? '';
            $afterData = json_encode(['id' => $inserted_id, 'client' => $client_name, 'date' => $appointment_date]);

            if ($log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, entity, entity_id, after_data, created_at) VALUES (?,?,?, 'create', 'appointments', ?, ?, NOW())")) {
                $log->bind_param('issis', $userId, $logUsername, $logUserRole, $inserted_id, $afterData);
                $log->execute();
            }
        } else {
            $_SESSION['error'] = 'System error: unable to schedule appointment.';
        }
    }
    header('Location: appointments.php?view=upcoming');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mark_reminded'])) {
    $redirect_view = $_POST['view'] ?? $view;
    if (!in_array($redirect_view, $allowed_views, true)) {
        $redirect_view = 'upcoming';
    }
    $caller = trim((string)($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? 'Admin'));
    if (mark_appointment_reminded($conn, (int)($_POST['appointment_id'] ?? 0), $caller)) {
        $_SESSION['message'] = 'Marked as called.';
    }
    header('Location: appointments.php?view=' . urlencode($redirect_view));
    exit;
}

if (isset($_POST['new_status'], $_POST['appointment_id']) && !isset($_POST['schedule_appointment'])) {
    $id = (int)$_POST['appointment_id'];
    $new_status = $_POST['new_status'] ?? '';
    $redirect_view = $_POST['view'] ?? $view;
    if (!in_array($redirect_view, $allowed_views, true)) {
        $redirect_view = 'upcoming';
    }
    if ($new_status === 'Cancelled' && $id > 0) {
        $stmt = $conn->prepare("UPDATE appointments SET status = 'Cancelled' WHERE id = ? AND status = 'Scheduled'");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $_SESSION['message'] = 'Appointment cancelled.';
        }
    }
    header('Location: appointments.php?view=' . urlencode($redirect_view));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_appointment']) && $user_role === 'admin') {
    $id = (int)($_POST['appointment_id'] ?? 0);
    $view = $_POST['view'] ?? $view;
    if (!in_array($view, $allowed_views, true)) {
        $view = 'upcoming';
    }
    $stmt = $conn->prepare("DELETE FROM appointments WHERE id = ? AND status IN ('Cancelled', 'Completed')");
    $stmt->bind_param('i', $id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $_SESSION['message'] = 'Appointment record deleted.';
    } else {
        $_SESSION['error'] = 'Only cancelled or completed bookings can be deleted.';
    }
    header('Location: appointments.php?view=' . urlencode($view));
    exit;
}

$today = date('Y-m-d');
$now = time();
$search = trim($_GET['search'] ?? '');

$counts = ['upcoming' => 0, 'today' => 0, 'completed' => 0, 'cancelled' => 0];
$count_sql = [
    'upcoming' => "SELECT COUNT(*) FROM appointments WHERE status = 'Scheduled' AND appointment_date >= '$today'",
    'today' => "SELECT COUNT(*) FROM appointments WHERE appointment_date = '$today'",
    'completed' => "SELECT COUNT(*) FROM appointments WHERE status = 'Completed'",
    'cancelled' => "SELECT COUNT(*) FROM appointments WHERE status = 'Cancelled'",
];
foreach ($count_sql as $key => $sql) {
    $res = $conn->query($sql);
    $counts[$key] = $res ? (int)$res->fetch_row()[0] : 0;
}

$rows = [];
if ($search !== '') {
    $like = "%$search%";
    $stmt = $conn->prepare("SELECT * FROM appointments WHERE client_name LIKE ? OR phone LIKE ? ORDER BY appointment_date DESC, appointment_time DESC");
    $stmt->bind_param('ss', $like, $like);
} elseif ($view === 'today') {
    $stmt = $conn->prepare("SELECT * FROM appointments WHERE appointment_date = ? ORDER BY appointment_time ASC");
    $stmt->bind_param('s', $today);
} elseif ($view === 'completed') {
    $stmt = $conn->prepare("SELECT * FROM appointments WHERE status = 'Completed' ORDER BY appointment_date DESC, appointment_time DESC");
} elseif ($view === 'cancelled') {
    $stmt = $conn->prepare("SELECT * FROM appointments WHERE status = 'Cancelled' ORDER BY appointment_date DESC, appointment_time DESC");
} else {
    $stmt = $conn->prepare("SELECT * FROM appointments WHERE status = 'Scheduled' AND appointment_date >= ? ORDER BY appointment_date ASC, appointment_time ASC");
    $stmt->bind_param('s', $today);
}
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $unix = strtotime($row['appointment_date'] . ' ' . $row['appointment_time']);
            $row['is_late'] = ($row['status'] === 'Scheduled') && $unix && ($unix < $now);
            $rows[] = $row;
        }
    }
}

$massage_names = [];
$msg_query = $conn->query("SELECT name FROM massage_types WHERE name IS NOT NULL AND name != '' ORDER BY name");
if ($msg_query) {
    while ($row = $msg_query->fetch_assoc()) {
        $massage_names[] = trim($row['name']);
    }
}

$servicesBySection = [];
$svcRes = $conn->query("SELECT service_name, section FROM services WHERE service_name IS NOT NULL AND service_name != '' ORDER BY section, service_name");
if ($svcRes) {
    while ($svc = $svcRes->fetch_assoc()) {
        $sec = trim((string)($svc['section'] ?? 'Other'));
        if ($sec === '') {
            $sec = 'Other';
        }
        $servicesBySection[$sec][] = $svc['service_name'];
    }
}

$staff_names = [];
$staff = $conn->query("SELECT name FROM staff WHERE role IN ('therapist', 'massage', 'barbering', 'facials', 'nails', 'salon') ORDER BY name ASC");
if ($staff) {
    while ($st = $staff->fetch_assoc()) {
        $staff_names[] = $st['name'];
    }
}

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

$view_labels = [
    'upcoming' => 'Upcoming',
    'today' => 'Today',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments | The Breeze Spa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
            --text-main: #1e293b; --text-muted: #475569; --border-color: #e2e8f0;
            --sidebar-width: 250px; --topbar-height: 60px;
            --danger-color: #dc2626; --success-color: #10b981;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; font-size: 16px; overflow-x: hidden; }

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

        .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; width: calc(100% - var(--sidebar-width)); }
        .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
        .topbar h2 { font-size: 22px; font-weight: 600; color: var(--text-main); }
        .topbar .date-stamp { font-size: 15px; color: var(--text-muted); font-weight: 500; }
        .content-wrapper { padding: 30px; flex: 1; width: 100%; }

        .alert { padding: 16px 20px; margin-bottom: 24px; font-size: 15px; font-weight: 500; border-left: 4px solid; background-color: var(--bg-card); display: flex; align-items: center; gap: 10px; }
        .alert-success { border-left-color: var(--success-color); color: #065f46; }
        .alert-error { border-left-color: var(--danger-color); color: #991b1b; }

        .flat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; margin-bottom: 30px; }
        .flat-card-header { margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .flat-card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }

        .ticket-form { display: grid; gap: 8px; }
        .ticket-block { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px; padding: 16px 0 4px; border-top: 1px solid var(--border-color); }
        .ticket-block.when { grid-template-columns: 1fr 1fr auto; }
        .ticket-block h4 { grid-column: 1 / -1; font-size: 11px; font-weight: 800; letter-spacing: 0.6px; text-transform: uppercase; color: var(--text-muted); }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; }
        .form-control { padding: 12px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 15px; outline: none; background-color: #f8fafc; width: 100%; }
        .ticket-form .form-control { width: 70%; }
        .form-control:focus { border-color: var(--brand-blue); background-color: #ffffff; }
        select.form-control optgroup { font-weight: 700; font-style: normal; color: #0f172a; text-transform: uppercase; }

        .diary-tabs { display: flex; flex-wrap: wrap; border: 1px solid var(--border-color); }
        .diary-tab { padding: 8px 14px; font-size: 12px; font-weight: 700; background: #fff; color: var(--text-muted); text-decoration: none; }
        .diary-tab:hover:not(.active) { background: #f1f5f9; color: var(--text-main); }
        .diary-tab.active { background: var(--brand-dark); color: #fff; }
        .search-form { display: flex; gap: 8px; }
        .search-form .form-control { width: 240px; padding: 8px 12px; }

        .btn { padding: 12px 20px; font-size: 15px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
        .btn-primary { background-color: var(--brand-blue); color: #ffffff; }
        .btn-primary:hover { background-color: #0284c7; }
        .btn-danger { background-color: #fef2f2; color: var(--danger-color); border: 1px solid #fca5a5; }
        .btn-danger:hover { background-color: var(--danger-color); color: #ffffff; }
        .btn-outline { background-color: #ffffff; border: 1px solid var(--border-color); color: var(--text-main); }
        .btn-outline:hover { background-color: #f1f5f9; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }

        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 16px 20px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 15px; vertical-align: middle; }
        th { background-color: #f8fafc; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 13px; letter-spacing: 0.5px; }
        tr:hover td { background-color: #f8fafc; }
        tr.late td:first-child { color: #dc2626; font-weight: 700; }
        .late-label { display: inline-block; margin-top: 4px; font-size: 10px; font-weight: 800; letter-spacing: 0.4px; text-transform: uppercase; color: #dc2626; }
        .row-actions { display: flex; gap: 8px; align-items: center; }

        .badge { padding: 6px 12px; font-size: 12px; font-weight: 700; border-radius: 2px; text-transform: uppercase; border: 1px solid; display: inline-block; letter-spacing: 0.5px; }
        .badge-scheduled { background-color: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
        .badge-completed { background-color: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .badge-cancelled { background-color: #fef2f2; color: #991b1b; border-color: #fecaca; }

        @media (max-width: 900px) { .ticket-block, .ticket-block.when { grid-template-columns: 1fr; } .ticket-form .form-control { width: 100%; } }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; width: 100%; } }
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
            <a href="password_requests.php" class="menu-item"><i class="fas fa-key"></i> Password Requests</a>
            <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
            <a href="appointments.php" class="menu-item active"><i class="fas fa-calendar-days"></i> Appointments</a>
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
            <h2>Appointments</h2>
            <div class="date-stamp">System Time: <?= date('Y-m-d H:i') ?></div>
        </header>

        <div class="content-wrapper">
            <?php if ($message): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="flat-card">
                <div class="flat-card-header"><h3>Book appointment</h3></div>
                <form method="post" class="ticket-form">
<?= csrf_field() ?>
                    <div class="ticket-block">
                        <h4>Client</h4>
                        <div class="form-group"><label>Client Name</label><input type="text" name="client_name" class="form-control" required></div>
                        <div class="form-group"><label>Phone</label><input type="tel" name="phone" class="form-control" maxlength="10" required></div>
                    </div>
                    <div class="ticket-block">
                        <h4>Service</h4>
                        <div class="form-group">
                            <label>Service</label>
                            <select name="service_type" class="form-control" required>
                                <option value="" disabled selected>Select service...</option>
                                <?php if ($massage_names): ?>
                                <optgroup label="MASSAGE">
                                    <?php foreach ($massage_names as $svc): ?>
                                    <option value="<?= htmlspecialchars($svc) ?>"><?= htmlspecialchars($svc) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                                <?php foreach ($servicesBySection as $section_name => $svc_list): ?>
                                <optgroup label="<?= htmlspecialchars(strtoupper($section_name)) ?>">
                                    <?php foreach ($svc_list as $svc): ?>
                                    <option value="<?= htmlspecialchars($svc) ?>"><?= htmlspecialchars($svc) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Staff</label>
                            <select name="staff_assigned" class="form-control">
                                <option value="">Any available</option>
                                <?php foreach ($staff_names as $name): ?>
                                <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="ticket-block when">
                        <h4>When</h4>
                        <div class="form-group"><label>Date</label><input type="date" name="appointment_date" class="form-control" value="<?= htmlspecialchars($today) ?>" min="<?= htmlspecialchars($today) ?>" required></div>
                        <div class="form-group"><label>Time</label><input type="time" name="appointment_time" class="form-control" required></div>
                        <div class="form-group"><label>&nbsp;</label><button type="submit" name="schedule_appointment" class="btn btn-primary"><i class="fas fa-calendar-plus"></i> Schedule</button></div>
                    </div>
                </form>
            </div>

            <div class="flat-card">
                <div class="flat-card-header">
                    <h3><?= $search !== '' ? 'Search results' : 'Diary' ?></h3>
                    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                        <div class="diary-tabs">
                            <?php foreach ($view_labels as $key => $label): ?>
                            <a class="diary-tab<?= ($search === '' && $view === $key) ? ' active' : '' ?>" href="appointments.php?view=<?= $key ?>"><?= $label ?> (<?= (int)$counts[$key] ?>)</a>
                            <?php endforeach; ?>
                        </div>
                        <form method="get" class="search-form">
                            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                            <input type="text" name="search" placeholder="Find name or phone" value="<?= htmlspecialchars($search) ?>" class="form-control">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                            <?php if ($search !== ''): ?><a href="appointments.php?view=<?= htmlspecialchars($view) ?>" class="btn btn-outline btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Client</th>
                                <th>Service & staff</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                            <tr class="<?= !empty($row['is_late']) ? 'late' : '' ?>">
                                <td>
                                    <div style="font-weight: 700;"><?= date('D, M j', strtotime($row['appointment_date'])) ?></div>
                                    <div style="font-size: 13px; color: var(--text-muted); margin-top: 3px;"><?= date('h:i A', strtotime($row['appointment_time'])) ?></div>
                                    <?php if (!empty($row['is_late'])): ?><span class="late-label">Late</span><?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($row['client_name']) ?></strong><br>
                                    <span style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($row['phone']) ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 500;"><?= htmlspecialchars($row['service_type']) ?></div>
                                    <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;"><?= htmlspecialchars($row['staff_assigned'] ?: 'Any available') ?></div>
                                </td>
                                <td>
                                    <?php
                                        $sClass = 'badge-scheduled';
                                        if ($row['status'] === 'Completed') $sClass = 'badge-completed';
                                        if ($row['status'] === 'Cancelled') $sClass = 'badge-cancelled';
                                    ?>
                                    <span class="badge <?= $sClass ?>"><?= htmlspecialchars($row['status']) ?></span>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <?php if ($row['status'] === 'Scheduled'): ?>
                                            <?php if (empty($row['reminded_at'])): ?>
                                            <a class="btn btn-outline btn-sm" href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', (string)$row['phone'])) ?>">Call</a>
                                            <form method="post" style="display:inline;">
<?= csrf_field() ?>
                                                <input type="hidden" name="mark_reminded" value="1">
                                                <input type="hidden" name="appointment_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                                                <button type="submit" class="btn btn-outline btn-sm">Called</button>
                                            </form>
                                            <?php else: ?>
                                            <span class="btn btn-outline btn-sm" style="cursor:default;">Called</span>
                                            <?php endif; ?>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this appointment?');">
<?= csrf_field() ?>
                                                <input type="hidden" name="appointment_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="new_status" value="Cancelled">
                                                <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                                                <button type="submit" name="update_status" class="btn btn-outline btn-sm">Cancel</button>
                                            </form>
                                        <?php elseif ($user_role === 'admin' && in_array($row['status'], ['Cancelled', 'Completed'], true)): ?>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this record?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="delete_appointment" value="1">
                                                <input type="hidden" name="appointment_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?>
                            <tr><td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);"><?= $search !== '' ? 'No match for that name or phone.' : 'No bookings in this view.' ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
