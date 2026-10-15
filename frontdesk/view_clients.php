<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['frontdesk', 'reception'])) {
    header('Location: ../login.php');
    exit;
}

$view = $_GET['view'] ?? 'today';
$allowed_views = ['today', 'all'];
if (!in_array($view, $allowed_views, true)) {
    $view = 'today';
}

if (handle_client_money_post($conn, true)) {
    $view = $_POST['view'] ?? $view;
    if (!in_array($view, $allowed_views, true)) {
        $view = 'today';
    }
    header('Location: view_clients.php?view=' . urlencode($view));
    exit;
}

$today = date('Y-m-d');
$search = trim($_GET['search'] ?? '');

$counts = ['today' => 0, 'all' => 0];
if ($res = $conn->prepare("SELECT COUNT(*) FROM clients WHERE DATE(created_at) = ?")) {
    $res->bind_param('s', $today);
    $res->execute();
    $counts['today'] = (int)$res->get_result()->fetch_row()[0];
}
if ($res = $conn->query("SELECT COUNT(*) FROM clients")) {
    $counts['all'] = (int)$res->fetch_row()[0];
}

$rows = [];
if ($search !== '') {
    $like = "%$search%";
    $stmt = $conn->prepare("SELECT * FROM clients WHERE name LIKE ? OR phone LIKE ? OR client_code LIKE ? ORDER BY created_at DESC");
    $stmt->bind_param('sss', $like, $like, $like);
} elseif ($view === 'all') {
    $stmt = $conn->prepare("SELECT * FROM clients ORDER BY created_at DESC");
} else {
    $stmt = $conn->prepare("SELECT * FROM clients WHERE DATE(created_at) = ? ORDER BY created_at DESC");
    $stmt->bind_param('s', $today);
}

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $unix = !empty($row['created_at']) ? strtotime($row['created_at']) : false;
            $is_today = $unix ? (date('Y-m-d', $unix) === $today) : false;
            $row['when_date'] = $unix ? date('D, M j', $unix) : '';
            $row['when_time'] = $unix ? date('h:i A', $unix) : '';
            $row['show_date'] = !$is_today;
            $row['is_today'] = $is_today;
            $service = trim((string)($row['service_type'] ?? ''));
            if (!empty($row['massage_type'])) {
                $service .= ' (' . $row['massage_type'] . ')';
            }
            $row['service_label'] = $service;
            $rows[] = $row;
        }
    }
}

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

$view_labels = [
    'today' => 'Today',
    'all' => 'All',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Client Directory | Front Office</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
            --text-main: #1e293b; --text-muted: #475569; --border-color: #e2e8f0;
            --sidebar-width: 250px; --topbar-height: 60px;
            --danger-color: #dc2626; --success-color: #10b981;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; font-size: 15px; overflow-x: hidden; }

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
        .btn-queue { display: inline-flex; align-items: center; gap: 8px; background: #fff; color: var(--text-main); border: 1px solid var(--border-color); text-decoration: none; font-weight: 700; font-size: 13px; padding: 10px 14px; }
        .btn-queue:hover { border-color: var(--brand-blue); color: var(--brand-blue); }
        .content-wrapper { padding: 24px 30px 40px; flex: 1; width: 100%; }

        .alert { padding: 14px 16px; margin-bottom: 16px; font-size: 15px; font-weight: 500; border-left: 4px solid; background-color: var(--bg-card); display: flex; align-items: center; gap: 10px; }
        .alert-success { border-left-color: var(--success-color); color: #065f46; }
        .alert-error { border-left-color: var(--danger-color); color: #991b1b; }

        .flat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 28px 30px; margin-bottom: 20px; }
        .flat-card-header { margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .flat-card-header h3 { font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; }

        .form-control { padding: 12px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 15px; outline: none; background-color: #f8fafc; }
        .form-control:focus { border-color: var(--brand-blue); background-color: #ffffff; }

        .diary-tabs { display: flex; border: 1px solid var(--border-color); }
        .diary-tab { padding: 8px 14px; font-size: 12px; font-weight: 700; background: #fff; color: var(--text-muted); text-decoration: none; }
        .diary-tab.active { background: var(--brand-dark); color: #fff; }
        .search-form { display: flex; gap: 8px; }
        .search-form .form-control { width: 240px; padding: 8px 12px; }

        .btn { padding: 10px 18px; font-size: 15px; font-weight: 700; border: none; border-radius: 2px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-primary { background-color: var(--brand-blue); color: #ffffff; }
        .btn-primary:hover { background: #0284c7; }
        .btn-outline { background-color: #ffffff; border: 1px solid var(--border-color); color: var(--text-main); }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .btn-danger { background-color: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
        .adjust summary { cursor: pointer; font-size: 13px; font-weight: 700; color: #0369a1; }
        .adjust-body { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; min-width: 170px; }
        .adjust-body form { display: flex; flex-direction: column; gap: 6px; }
        .adjust-body .form-control { width: 170px; padding: 6px 8px; font-size: 13px; }
        .meta-note { font-size: 12px; color: #64748b; font-weight: 700; }
        .btn-print { display: inline-flex; align-items: center; gap: 6px; padding: 7px 10px; font-size: 12px; font-weight: 700; color: #fff; background: var(--brand-blue); text-decoration: none; }
        .btn-print:hover { background: #0284c7; }

        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 14px; }
        th { background-color: #f8fafc; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 12px; }
        .row-actions { display: flex; gap: 8px; align-items: center; }
        code { font-size: 13px; background: #f8fafc; border: 1px solid var(--border-color); padding: 2px 6px; }

        .badge { padding: 5px 10px; font-size: 11px; font-weight: 700; border-radius: 2px; text-transform: uppercase; border: 1px solid; display: inline-block; }
        .badge-pending { background-color: #fef3c7; color: #92400e; border-color: #fde68a; }
        .badge-inprogress { background-color: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
        .badge-done { background-color: #dcfce7; color: #166534; border-color: #bbf7d0; }

        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; width: 100%; } }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo">
        </div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><i class="fas fa-circle"></i> Front Office Ops</p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-desktop"></i> Station Dashboard</a>
            <a href="add_client.php" class="menu-item"><i class="fas fa-user-plus"></i> Register Client</a>
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="view_clients.php" class="menu-item active"><i class="fas fa-address-book"></i> Client Directory</a>
            <a href="till.php" class="menu-item"><i class="fas fa-cash-register"></i> Close of Day</a>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> End Shift</a>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Client Directory</h2>
            <div class="topbar-right">
                <div class="date-stamp"><?= date('l, F j, Y') ?></div>
                <a class="btn-queue" href="dashboard.php"><i class="fas fa-list"></i> Back to Queue</a>
            </div>
        </header>

        <div class="content-wrapper">
            <?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="flat-card">
                <div class="flat-card-header">
                    <h3><?= $search !== '' ? 'Search results' : 'Visits' ?></h3>
                    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                        <div class="diary-tabs">
                            <?php foreach ($view_labels as $key => $label): ?>
                            <a class="diary-tab<?= ($search === '' && $view === $key) ? ' active' : '' ?>" href="view_clients.php?view=<?= $key ?>"><?= $label ?> (<?= (int)$counts[$key] ?>)</a>
                            <?php endforeach; ?>
                        </div>
                        <form method="get" class="search-form">
                            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                            <input type="text" name="search" placeholder="Find name, phone or code" value="<?= htmlspecialchars($search) ?>" class="form-control">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                            <?php if ($search !== ''): ?><a href="view_clients.php?view=<?= htmlspecialchars($view) ?>" class="btn btn-outline btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
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
                                <th>Amount</th>
                                <th>Code</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($row['show_date'])): ?>
                                    <div style="font-weight: 700;"><?= htmlspecialchars($row['when_date']) ?></div>
                                    <?php endif; ?>
                                    <div style="<?= !empty($row['show_date']) ? 'font-size: 13px; color: var(--text-muted); margin-top: 3px;' : 'font-weight: 700;' ?>"><?= htmlspecialchars($row['when_time']) ?></div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($row['name'] ?? '') ?></strong><br>
                                    <span style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($row['phone'] ?? '') ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 500;"><?= htmlspecialchars($row['service_label']) ?></div>
                                    <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;"><?= htmlspecialchars($row['staff_name'] ?: '—') ?></div>
                                </td>
                                <td><?= htmlspecialchars(client_amount_label($row)) ?></td>
                                <td><code><?= htmlspecialchars($row['client_code'] ?? '') ?></code></td>
                                <td>
                                    <?php
                                        $status = $row['status'] ?? 'Pending';
                                        $sClass = 'badge-pending';
                                        if ($status === 'In Progress') $sClass = 'badge-inprogress';
                                        elseif ($status === 'Done') $sClass = 'badge-done';
                                    ?>
                                    <span class="badge <?= $sClass ?>"><?= htmlspecialchars($status) ?></span>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <a class="btn-print" href="print_receipt.php?id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">Print</a>
                                        <a href="edit_client.php?id=<?= (int)$row['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                                        <?= client_adjustment_forms($row, $view, !empty($row['is_today'])) ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?>
                            <tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);"><?php
                                if ($search !== '') echo 'No match for that name, phone, or code.';
                                elseif ($view === 'all') echo 'No client records.';
                                else echo 'No tickets today.';
                            ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
