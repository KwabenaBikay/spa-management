<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';
include '../includes/due_appointments.php';
include '../includes/client_timer.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['frontdesk', 'reception'])) {
    header("Location: ../login.php");
    exit;
}

// Ensure audit_logs exists
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

// Fetch data for dropdowns
$massage_types = [];
if ($res = $conn->query("SELECT name, category, price_60, price_90, price_120 FROM massage_types ORDER BY name")) {
    while ($row = $res->fetch_assoc()) {
        $massage_types[] = [
            'name' => $row['name'],
            'category' => $row['category'],
            'price_60' => (float)$row['price_60'],
            'price_90' => (float)$row['price_90'],
            'price_120' => (float)$row['price_120']
        ];
    }
}

$servicesBySection = [];
$servicePriceBySectionAndName = [];
$svcRes = $conn->query("SELECT * FROM services");
if ($svcRes) {
    while ($svc = $svcRes->fetch_assoc()) {
        $sec = $svc['section'] ?? 'Other';
        $nm = $svc['service_name'] ?? '';
        $pr = (float)($svc['price'] ?? 0);
        if ($nm !== '') {
            $servicesBySection[$sec][] = $nm;
            $servicePriceBySectionAndName[$sec][$nm] = $pr;
        }
    }
}

$success = $error = '';
$inserted_id = 0;
$registered_code = '';
$prefill = null;
$appointment_id = (int)($_POST['appointment_id'] ?? $_GET['appointment_id'] ?? 0);
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($request_method == "POST") {
    $name = trim($_POST['name']);
    $phone = preg_replace('/\D+/', '', $_POST['phone']);
    $staff_name = trim((string)($_POST['staff_name'] ?? ''));
    if (strlen($phone) !== 10) { $error = "Phone must be exactly 10 digits."; }
    if ($staff_name === '') { $error = $error ?: "Please assign a staff member."; }
    
    $service_type = $_POST['service_type'];
    $amount = (float)$_POST['amount'];
    $payment_mode = $_POST['payment_mode'];
    $section = $_POST['section'];
    $duration = $_POST['form_type'] === 'massage' ? $_POST['duration'] : null;
    $massage_type = $_POST['form_type'] === 'massage' ? $_POST['massage_type'] : null;

    if (empty($error)) {
        $inserted_id = 0;
        $client_code = '';
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $client_code = generate_next_client_code($conn);
            $stmt = $conn->prepare("INSERT INTO clients (name, phone, service_type, amount, duration, payment_mode, massage_type, staff_name, section, client_code, status, service_started_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NULL)");
            $stmt->bind_param("ssssssssss", $name, $phone, $service_type, $amount, $duration, $payment_mode, $massage_type, $staff_name, $section, $client_code);
            
            if ($stmt->execute()) {
                $inserted_id = $conn->insert_id;
                break;
            }

            if ($conn->errno === 1062) {
                usleep(30000);
                continue;
            }

            break;
        }

        if ($inserted_id > 0) {
            $registered_code = $client_code;
            $success = "Registration Successful! Code: $client_code";
            
            // Audit Log
            $after = ['id' => $inserted_id, 'name' => $name, 'client_code' => $client_code];
            $userId = (int)($_SESSION['user']['id'] ?? 0);
            $username = $_SESSION['user']['username'] ?? '';
            $role = $_SESSION['user']['role'] ?? '';
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, entity, entity_id, after_data, created_at) VALUES (?,?,?, 'create', 'clients', ?, ?, NOW())");
            $afterJson = json_encode($after);
            $log->bind_param('issis', $userId, $username, $role, $inserted_id, $afterJson);
            $log->execute();

            if ($appointment_id > 0) {
                $doneAppt = $conn->prepare("UPDATE appointments SET status = 'Completed' WHERE id = ? AND status = 'Scheduled'");
                if ($doneAppt) {
                    $doneAppt->bind_param('i', $appointment_id);
                    if ($doneAppt->execute() && $doneAppt->affected_rows > 0) {
                        $success .= ' Booking marked as arrived.';
                    }
                }
            }
        } else {
            $error = "System Error: Unable to register client.";
        }
    }
}

if ($appointment_id > 0 && empty($success)) {
    ensure_appointments_table($conn);
    $apptStmt = $conn->prepare("SELECT * FROM appointments WHERE id = ? AND status = 'Scheduled' LIMIT 1");
    if ($apptStmt) {
        $apptStmt->bind_param('i', $appointment_id);
        $apptStmt->execute();
        $appointment = $apptStmt->get_result()->fetch_assoc();
        if ($appointment) {
            $prefill = resolve_appointment_for_register($conn, $appointment);
            if ($request_method === 'POST') {
                $prefill['name'] = trim((string)($_POST['name'] ?? $prefill['name']));
                $prefill['phone'] = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? $prefill['phone']));
                $prefill['staff_name'] = trim((string)($_POST['staff_name'] ?? $prefill['staff_name']));
            }
        } else {
            $appointment_id = 0;
            if ($error === '') {
                $error = "That booking is no longer waiting to be checked in.";
            }
        }
    }
}

$prefill_name = htmlspecialchars($prefill['name'] ?? '', ENT_QUOTES);
$prefill_phone = htmlspecialchars($prefill['phone'] ?? '', ENT_QUOTES);
$prefill_staff_raw = trim((string)($prefill['staff_name'] ?? ''));
$prefill_staff = htmlspecialchars($prefill_staff_raw, ENT_QUOTES);
$staffBySection = floor_staff_by_section($conn);
$massageStaff = $staffBySection['Massage'] ?? [];

function staff_options_html(array $people, string $selected): string
{
    $html = '<option value="">Select staff</option>';
    foreach ($people as $name) {
        $isSelected = strcasecmp($selected, (string)$name) === 0 ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars((string)$name, ENT_QUOTES) . '"' . $isSelected . '>' . htmlspecialchars((string)$name) . '</option>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register Client | The Breeze Spa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #0ea5e9;
            --brand-dark: #0f172a;
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #475569;
            --border-color: #e2e8f0;
            --success-color: #10b981;
            --danger-color: #dc2626;
            --sidebar-width: 250px;
            --topbar-height: 60px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: var(--bg-body); display: flex; color: var(--text-main); font-size: 15px; min-height: 100vh; overflow-x: hidden; }

        .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; border-right: 1px solid #1e293b; }
        .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; display: flex; align-items: center; justify-content: center; }
        .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
        .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; font-size: 14px; }
        .user-profile h4 { color: #ffffff; font-size: 15px; font-weight: 600; margin-bottom: 4px; }
        .user-profile p { color: #94a3b8; font-size: 12px; display: flex; align-items: center; gap: 6px; }
        .sidebar-menu { padding: 20px 0; flex: 1; }
        .menu-item { display: flex; align-items: center; padding: 14px 24px; color: #cbd5e1; text-decoration: none; font-size: 15px; font-weight: 500; border-left: 4px solid transparent; transition: all 0.2s; }
        .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
        .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
        .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
        .logout-btn { display: flex; align-items: center; padding: 16px 24px; color: #ef4444; text-decoration: none; font-size: 15px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
        .logout-btn:hover { background-color: #ef4444; color: #ffffff; }

        .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; width: calc(100% - var(--sidebar-width)); min-height: 100vh; position: relative; background-color: var(--bg-body); }
        .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; gap: 16px; }
        .topbar h2 { font-size: 18px; font-weight: 600; }
        .topbar .date-stamp { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .btn-queue { display: inline-flex; align-items: center; gap: 8px; background: #fff; color: var(--text-main); border: 1px solid var(--border-color); text-decoration: none; font-weight: 700; font-size: 13px; padding: 10px 14px; }
        .btn-queue:hover { border-color: var(--brand-blue); color: var(--brand-blue); }

        .page-watermarks { position: absolute; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }
        .page-watermarks img { position: absolute; width: 84px; height: auto; opacity: 0.07; user-select: none; }
        .page-watermarks img:nth-child(1) { top: 8%; left: 4%; transform: rotate(-16deg); }
        .page-watermarks img:nth-child(2) { top: 20%; right: 6%; width: 72px; transform: rotate(12deg); }
        .page-watermarks img:nth-child(3) { bottom: 14%; left: 8%; width: 78px; transform: rotate(10deg); }
        .page-watermarks img:nth-child(4) { bottom: 10%; right: 12%; width: 70px; transform: rotate(-10deg); }

        .content-wrapper { padding: 24px 30px 40px; flex: 1; width: 100%; position: relative; z-index: 1; }
        .container { max-width: 931px; margin: 0 auto; width: 100%; }

        .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 40px 42px 36px; }
        .flat-card-header { margin-bottom: 18px; }
        .flat-card-header h3 { font-size: 20px; font-weight: 700; }

        .btn-group { display: flex; border: 1px solid var(--border-color); margin-bottom: 8px; }
        .form-btn { flex: 1; padding: 12px 14px; border: none; background: #fff; font-weight: 700; cursor: pointer; font-size: 13px; color: var(--text-muted); }
        .form-btn.active { background: var(--brand-dark); color: #fff; }

        form { display: none; grid-template-columns: 1fr 1fr; gap: 18px 20px; }
        .ticket-block { grid-column: span 2; display: grid; grid-template-columns: 1fr 1fr; gap: 18px 20px; padding: 18px 0 6px; border-top: 1px solid var(--border-color); }
        .ticket-block h4 { grid-column: span 2; font-size: 11px; font-weight: 800; letter-spacing: 0.6px; text-transform: uppercase; color: var(--text-muted); }
        .span-2 { grid-column: span 2; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 7px; text-transform: uppercase; }
        .form-control { padding: 14px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 15px; width: 100%; outline: none; background: #f8fafc; }
        .form-control:focus { border-color: var(--brand-blue); background: #ffffff; }
        select.form-control optgroup { font-weight: 700; font-style: normal; color: #0f172a; text-transform: uppercase; }
        select.form-control option { font-weight: 400; color: #1e293b; text-transform: none; }

        .category-tabs { display: flex; border: 1px solid var(--border-color); }
        .tab-btn { flex: 1; padding: 12px 10px; border: none; background: #fff; font-weight: 700; cursor: pointer; font-size: 13px; color: var(--text-muted); outline: none; }
        .tab-btn.active { background: var(--brand-blue); color: white; }

        .ticket-total { grid-column: span 2; display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 16px 18px; background: #f8fafc; border: 1px solid var(--border-color); }
        .ticket-total-label { font-size: 11px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-muted); }
        .ticket-total-hint { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
        .ticket-total-value { display: flex; align-items: baseline; gap: 8px; font-weight: 800; color: var(--text-main); }
        .ticket-total-value > span { font-size: 14px; color: var(--text-muted); }
        .ticket-total-input { border: none; background: transparent; font-size: 28px; font-weight: 800; width: 160px; color: var(--text-main); outline: none; appearance: textfield; -moz-appearance: textfield; }
        .ticket-total-input::-webkit-outer-spin-button,
        .ticket-total-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .ticket-total-input:read-only { cursor: default; }

        .btn { grid-column: span 2; padding: 16px; border: none; background: var(--brand-blue); color: white; font-weight: 700; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #0284c7; }
        .btn-secondary { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 14px 18px; background: #fff; color: var(--text-main); border: 1px solid var(--border-color); font-weight: 700; text-decoration: none; }
        .btn-secondary:hover { border-color: var(--brand-blue); color: var(--brand-blue); }
        .btn-print-lg { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 14px 18px; background: var(--brand-blue); color: #fff; font-weight: 700; text-decoration: none; }
        .btn-print-lg:hover { background: #0284c7; }

        .alert { padding: 14px 16px; margin-bottom: 16px; border-left: 4px solid; font-weight: 500; background: #fff; }
        .alert-error { border-color: var(--danger-color); background: #fef2f2; color: #991b1b; }
        .alert-info { border-color: var(--brand-blue); background: #f0f9ff; color: #075985; }

        .result-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 42px 40px; text-align: center; }
        .result-icon { width: 56px; height: 56px; margin: 0 auto 16px; border-radius: 50%; background: #ecfdf5; color: #059669; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .result-card h3 { font-size: 22px; margin-bottom: 8px; }
        .result-card p { color: var(--text-muted); margin-bottom: 8px; }
        .result-code { display: inline-block; margin: 8px 0 24px; padding: 8px 14px; background: #f8fafc; border: 1px solid var(--border-color); font-weight: 800; letter-spacing: 0.4px; }
        .result-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }

        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; width: 100%; } }
        @media (max-width: 600px) {
            form, .ticket-block { grid-template-columns: 1fr; }
            .ticket-block h4, .span-2, .ticket-total, .btn { grid-column: span 1; }
            .result-actions { flex-direction: column; }
        }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo">
        </div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><i class="fas fa-circle" style="color: #10b981; font-size: 10px; margin-right: 5px;"></i> Front Office Ops</p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-desktop"></i> Station Dashboard</a>
            <a href="add_client.php" class="menu-item active"><i class="fas fa-user-plus"></i> Register Client</a>
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="view_clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
            <a href="till.php" class="menu-item"><i class="fas fa-cash-register"></i> Close of Day</a>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> End Shift</a>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2><?= $success ? 'Registered' : ($prefill ? 'Check In' : 'Register Client') ?></h2>
            <div class="topbar-right">
                <div class="date-stamp"><?= date('l, F j, Y') ?></div>
                <a class="btn-queue" href="dashboard.php"><i class="fas fa-list"></i> Back to Queue</a>
            </div>
        </header>
        <div class="page-watermarks" aria-hidden="true">
            <img src="../assets/images/The-Breeze-watermark.png" alt="">
            <img src="../assets/images/The-Breeze-watermark.png" alt="">
            <img src="../assets/images/The-Breeze-watermark.png" alt="">
            <img src="../assets/images/The-Breeze-watermark.png" alt="">
        </div>
        <div class="content-wrapper">
        <div class="container">
            <?php if ($success): ?>
            <div class="result-card">
                <div class="result-icon"><i class="fas fa-check"></i></div>
                <h3>Client registered</h3>
                <p><?= (strpos($success, 'arrived') !== false) ? 'Booking marked as arrived.' : 'Walk-in is on today’s queue.' ?></p>
                <?php if ($registered_code !== ''): ?>
                <div class="result-code"><?= htmlspecialchars($registered_code) ?></div>
                <?php endif; ?>
                <div class="result-actions">
                    <?php if ($inserted_id): ?>
                    <a class="btn-print-lg" href="print_receipt.php?id=<?= (int)$inserted_id ?>" target="_blank" rel="noopener"><i class="fas fa-print"></i> Print Receipt</a>
                    <?php endif; ?>
                    <a class="btn-secondary" href="add_client.php"><i class="fas fa-user-plus"></i> Register next walk-in</a>
                    <a class="btn-secondary" href="dashboard.php">Back to Queue</a>
                </div>
            </div>
            <?php else: ?>
            <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($prefill): ?>
            <div class="alert alert-info">
                <i class="fas fa-calendar-check"></i>
                Checking in <strong><?= htmlspecialchars($prefill['name']) ?></strong><?= $prefill['time_label'] !== '' ? ' for ' . htmlspecialchars($prefill['time_label']) : '' ?>. Confirm service and payment, then register.
            </div>
            <?php endif; ?>

            <div class="flat-card">
                <div class="flat-card-header"><h3><?= $prefill ? 'Check In Appointment' : 'New walk-in ticket' ?></h3></div>
                
                <div class="btn-group">
                    <button type="button" class="form-btn active" onclick="toggleForm('massage')">Massage</button>
                    <button type="button" class="form-btn" onclick="toggleForm('others')">Other services</button>
                </div>

                <form method="post" id="massage-form">
<?= csrf_field() ?>
                    <input type="hidden" name="form_type" value="massage">
                    <input type="hidden" name="service_type" value="Massage">
                    <input type="hidden" name="section" value="Massage">
                    <input type="hidden" name="appointment_id" value="<?= (int)$appointment_id ?>">

                    <div class="ticket-block">
                        <h4>Client</h4>
                        <div class="form-group"><label>Client Name</label><input type="text" name="name" class="form-control" value="<?= $prefill_name ?>" required></div>
                        <div class="form-group"><label>Phone</label><input type="tel" name="phone" class="form-control" maxlength="10" value="<?= $prefill_phone ?>" required></div>
                    </div>

                    <div class="ticket-block">
                        <h4>Service</h4>
                        <div class="form-group span-2">
                            <label>Category</label>
                            <div class="category-tabs">
                                <button type="button" class="tab-btn active" data-category="Full Body Massage" onclick="selectMassageCategory('Full Body Massage', this)">Full Body</button>
                                <button type="button" class="tab-btn" data-category="Express Massage" onclick="selectMassageCategory('Express Massage', this)">Express</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Massage Type</label>
                            <select name="massage_type" id="m-type" class="form-control" required>
                                <option value="">Select...</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Duration</label>
                            <select name="duration" id="m-duration" class="form-control" required>
                                <option value="">Select...</option>
                                <option value="60 min">60 min</option>
                                <option value="90 min">90 min</option>
                                <option value="120 min">120 min</option>
                            </select>
                        </div>
                    </div>

                    <div class="ticket-block">
                        <h4>Pay</h4>
                        <div class="ticket-total">
                            <div>
                                <div class="ticket-total-label">Total</div>
                                <div class="ticket-total-hint">Filled from the service price</div>
                            </div>
                            <div class="ticket-total-value">
                                <span>GHS</span>
                                <input type="number" name="amount" id="m-amt" class="ticket-total-input" step="0.01" readonly required>
                            </div>
                        </div>
                        <div class="form-group"><label>Payment Mode</label><select name="payment_mode" class="form-control" required><option>Cash</option><option>Mobile Money</option></select></div>
                        <div class="form-group"><label>Staff</label><select name="staff_name" class="form-control" required><?= staff_options_html($massageStaff, $prefill_staff_raw) ?></select></div>
                    </div>

                    <button type="submit" class="btn"><?= $prefill ? 'Check In Client' : 'Register Client' ?></button>
                </form>

                <form method="post" id="others-form">
<?= csrf_field() ?>
                    <input type="hidden" name="form_type" value="others">
                    <input type="hidden" name="appointment_id" value="<?= (int)$appointment_id ?>">

                    <div class="ticket-block">
                        <h4>Client</h4>
                        <div class="form-group"><label>Client Name</label><input type="text" name="name" class="form-control" value="<?= $prefill_name ?>" required></div>
                        <div class="form-group"><label>Phone</label><input type="tel" name="phone" class="form-control" maxlength="10" value="<?= $prefill_phone ?>" required></div>
                    </div>

                    <div class="ticket-block">
                        <h4>Service</h4>
                        <div class="form-group"><label>Section</label><select name="section" id="o-sec" class="form-control" required><option value="">Select Section</option>
                            <?php foreach(array_keys($servicesBySection) as $sec): ?><option value="<?= htmlspecialchars($sec) ?>"><?= htmlspecialchars($sec) ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="form-group"><label>Service</label><select name="service_type" id="o-svc" class="form-control" required disabled></select></div>
                    </div>

                    <div class="ticket-block">
                        <h4>Pay</h4>
                        <div class="ticket-total">
                            <div>
                                <div class="ticket-total-label">Total</div>
                                <div class="ticket-total-hint">Filled from the service price</div>
                            </div>
                            <div class="ticket-total-value">
                                <span>GHS</span>
                                <input type="number" name="amount" id="o-amt" class="ticket-total-input" step="0.01" readonly required>
                            </div>
                        </div>
                        <div class="form-group"><label>Payment</label><select name="payment_mode" class="form-control" required><option>Cash</option><option>Mobile Money</option></select></div>
                        <div class="form-group"><label>Staff</label><select name="staff_name" id="o-staff" class="form-control" required disabled><?= staff_options_html([], $prefill_staff_raw) ?></select></div>
                    </div>

                    <button type="submit" class="btn"><?= $prefill ? 'Check In Client' : 'Register Client' ?></button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        </div>
    </main>

<script>
    const svcs = <?php echo json_encode($servicesBySection); ?>;
    const prices = <?php echo json_encode($servicePriceBySectionAndName); ?>;
    const massageTypesList = <?php echo json_encode($massage_types); ?>;
    const staffBySection = <?php echo json_encode($staffBySection); ?>;

    if (document.getElementById('massage-form')) {

    const hairSalonGroups = [
        { label: 'BRAIDS', keywords: ['cornrow', 'Knotless', 'Goddess', 'Butterfly braids', 'Bob Braids', 'twists', 'Shoulder length', 'Medium length braids', 'Long braids', 'Jumbo Box', 'Crochet'] },
        { label: 'HAIR EXTENSIONS', keywords: ['Sew in', 'Wig', 'Pony tail', 'Revamping', 'Bridal'] },
        { label: 'LOCKING WITH EXTENSIONS', keywords: ['Artificial locs', 'Butterfly locks', 'Faux locs'] },
        { label: 'WASHING & TREATMENTS', keywords: ['Shampoo', 'conditioning', 'Keratin', 'Scalp', 'Relaxer'] }
    ];

    function hairSalonGroupLabel(serviceName) {
        const name = String(serviceName).toLowerCase();
        for (const group of hairSalonGroups) {
            if (group.keywords.some(kw => name.includes(kw.toLowerCase()))) {
                return group.label;
            }
        }
        return null;
    }

    function appendServiceOption(parent, name) {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        parent.appendChild(opt);
    }

    function populateStaffSelect(section, selected) {
        const select = document.getElementById('o-staff');
        if (!select) return;
        const names = staffBySection[section] || [];
        select.innerHTML = '<option value="">Select staff</option>';
        names.forEach(name => {
            const opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            if (selected && String(selected).toLowerCase() === String(name).toLowerCase()) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });
        select.disabled = !section;
    }

    function populateServiceSelect(section) {
        const select = document.getElementById('o-svc');
        select.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select Service';
        select.appendChild(placeholder);

        const list = svcs[section] || [];
        if (section === 'Hair Salon') {
            const buckets = {};
            hairSalonGroups.forEach(g => { buckets[g.label] = []; });
            const unmatched = [];
            list.forEach(name => {
                const label = hairSalonGroupLabel(name);
                if (label) buckets[label].push(name);
                else unmatched.push(name);
            });
            hairSalonGroups.forEach(g => {
                if (!buckets[g.label].length) return;
                const groupEl = document.createElement('optgroup');
                groupEl.label = g.label;
                buckets[g.label].forEach(name => appendServiceOption(groupEl, name));
                select.appendChild(groupEl);
            });
            unmatched.forEach(name => appendServiceOption(select, name));
        } else {
            list.forEach(name => appendServiceOption(select, name));
        }
        select.disabled = false;
    }

    function toggleForm(t) {
        document.getElementById('massage-form').style.display = t === 'massage' ? 'grid' : 'none';
        document.getElementById('others-form').style.display = t === 'others' ? 'grid' : 'none';
        document.querySelectorAll('.form-btn').forEach((b,i) => i === (t==='massage'?0:1) ? b.classList.add('active') : b.classList.remove('active'));
    }

    document.getElementById('o-sec').addEventListener('change', e => {
        populateServiceSelect(e.target.value);
        populateStaffSelect(e.target.value);
    });

    document.getElementById('o-svc').addEventListener('change', e => {
        let p = prices[document.getElementById('o-sec').value][e.target.value];
        if(p) document.getElementById('o-amt').value = p.toFixed(2);
    });

    function selectMassageCategory(cat, btn) {
        // Toggle active button style
        document.querySelectorAll('.tab-btn').forEach(b => {
            if (b === btn) {
                b.classList.add('active');
            } else {
                b.classList.remove('active');
            }
        });
        
        // Rebuild select options
        const mTypeSelect = document.getElementById('m-type');
        mTypeSelect.innerHTML = '<option value="">Select...</option>';
        massageTypesList.forEach(m => {
            if (m.category === cat) {
                mTypeSelect.innerHTML += `<option value="${m.name}">${m.name}</option>`;
            }
        });
        
        // Reset type, duration and price fields
        document.getElementById('m-duration').innerHTML = '<option value="">Select...</option>';
        document.getElementById('m-amt').value = '';
    }

    function updateMassagePrice() {
        const type = document.getElementById('m-type').value;
        const duration = document.getElementById('m-duration').value;
        const massage = massageTypesList.find(m => m.name === type);
        
        if (massage && duration) {
            let price = 0;
            if (duration === '60 min') price = massage.price_60;
            else if (duration === '90 min') price = massage.price_90;
            else if (duration === '120 min') price = massage.price_120;
            document.getElementById('m-amt').value = price.toFixed(2);
        } else {
            document.getElementById('m-amt').value = '';
        }
    }

    document.getElementById('m-type').addEventListener('change', e => {
        const type = e.target.value;
        const massage = massageTypesList.find(m => m.name === type);
        const mDurationSelect = document.getElementById('m-duration');
        
        mDurationSelect.innerHTML = '<option value="">Select...</option>';
        document.getElementById('m-amt').value = '';
        
        if (massage) {
            let optionsCount = 0;
            let lastOptionValue = '';
            
            if (massage.price_60 > 0) {
                mDurationSelect.innerHTML += '<option value="60 min">60 min</option>';
                optionsCount++;
                lastOptionValue = '60 min';
            }
            if (massage.price_90 > 0) {
                mDurationSelect.innerHTML += '<option value="90 min">90 min</option>';
                optionsCount++;
                lastOptionValue = '90 min';
            }
            if (massage.price_120 > 0) {
                mDurationSelect.innerHTML += '<option value="120 min">120 min</option>';
                optionsCount++;
                lastOptionValue = '120 min';
            }
            
            if (optionsCount === 1) {
                mDurationSelect.value = lastOptionValue;
                updateMassagePrice();
            }
        }
    });

    document.getElementById('m-duration').addEventListener('change', updateMassagePrice);

    const prefill = <?= json_encode($prefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    function applyAppointmentPrefill() {
        if (!prefill || !prefill.appointment_id) return;

        if (prefill.form === 'others') {
            toggleForm('others');
            if (prefill.section) {
                const sec = document.getElementById('o-sec');
                sec.value = prefill.section;
                populateServiceSelect(prefill.section);
                populateStaffSelect(prefill.section, prefill.staff_name || '');
                if (prefill.service_type) {
                    const svc = document.getElementById('o-svc');
                    svc.value = prefill.service_type;
                    svc.dispatchEvent(new Event('change'));
                }
            }
            return;
        }

        toggleForm('massage');
        const category = prefill.category || 'Full Body Massage';
        const tab = document.querySelector('.tab-btn[data-category="' + category + '"]') || document.querySelector('.tab-btn');
        if (tab) {
            selectMassageCategory(category, tab);
        }
        if (prefill.massage_type) {
            const mType = document.getElementById('m-type');
            mType.value = prefill.massage_type;
            mType.dispatchEvent(new Event('change'));
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const defaultTab = document.querySelector('.tab-btn[data-category="Full Body Massage"]');
        if (defaultTab) {
            selectMassageCategory('Full Body Massage', defaultTab);
        }
        applyAppointmentPrefill();
    });

    toggleForm('massage');
    }
</script>
</body>
</html>