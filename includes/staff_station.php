<?php
require_once __DIR__ . '/session.php';
include dirname(__DIR__) . '/db.php';
include dirname(__DIR__) . '/includes/client_timer.php';

if (!isset($station) || !is_array($station)) {
    header('Location: ../login.php');
    exit;
}

$station_file = (string)($station['file'] ?? 'salon.php');
$station_title = (string)($station['title'] ?? 'Station');
$allowed_roles = $station['roles'] ?? [];

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], $allowed_roles, true)) {
    header('Location: ../login.php');
    exit;
}

ensure_client_timer_schema($conn);

$staff_user = $_SESSION['user'];
$staff_name = (string)($staff_user['name'] ?? '');
$role = (string)$staff_user['role'];
$sections = $station['sections'] ?? staff_sections_for_role($role);
$view = ($_GET['view'] ?? 'active') === 'completed' ? 'completed' : 'active';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['status'], $_POST['client_id'])) {
    $client_id = (int)$_POST['client_id'];
    $new_status = (string)$_POST['status'];
    $redirect_view = $new_status === 'Done' ? 'completed' : 'active';
    if ($client_id > 0 && $sections) {
        $in = implode(',', array_fill(0, count($sections), '?'));
        $check = $conn->prepare("SELECT * FROM clients WHERE id = ? AND section IN ($in)");
        if ($check) {
            $types = 'i' . str_repeat('s', count($sections));
            $params = array_merge([$client_id], array_values($sections));
            $check->bind_param($types, ...$params);
            $check->execute();
            $ticket = $check->get_result()->fetch_assoc();
            if ($ticket && is_ticket_assigned_to($ticket, $staff_user)) {
                update_client_status($conn, $client_id, $new_status);
            }
        }
    }
    header('Location: ' . $station_file . '?view=' . $redirect_view);
    exit;
}

$queue = filter_clients_for_staff(fetch_section_clients_today($conn, $sections), $staff_user);
$stats = floor_stats_from_queue($queue);
$active = sort_queue_for_floor(array_values(array_filter($queue, static function ($client) {
    return ($client['status'] ?? 'Pending') !== 'Done';
})), 'active');
$completed = sort_queue_for_floor(array_values(array_filter($queue, static function ($client) {
    return ($client['status'] ?? 'Pending') === 'Done';
})), 'completed');
$rows = $view === 'completed' ? $completed : $active;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($station_title) ?> | The Breeze Spa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .menu-item { display: flex; align-items: center; padding: 14px 24px; color: #cbd5e1; text-decoration: none; font-size: 15px; font-weight: 500; border-left: 4px solid transparent; }
        .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
        .menu-item.active { background: #1e293b; color: #fff; border-left-color: var(--brand-blue); }
        .menu-item.active i { color: var(--brand-blue); }
        .logout-btn { display: flex; align-items: center; padding: 16px 24px; color: #ef4444; text-decoration: none; font-size: 15px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
        .logout-btn i { margin-right: 10px; }
        .logout-btn:hover { background-color: #ef4444; color: #ffffff; }

        .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; width: calc(100% - var(--sidebar-width)); }
        .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
        .topbar h2 { font-size: 18px; font-weight: 600; }
        .topbar .date-stamp { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        .content-wrapper { padding: 24px 30px 40px; flex: 1; width: 100%; }

        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
        .kpi-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 16px 18px; border-left: 4px solid var(--brand-blue); }
        .kpi-value { font-size: 22px; font-weight: 700; line-height: 1.2; }
        .kpi-label { font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.4px; margin-bottom: 6px; }
        .kpi-sub { font-size: 12px; color: var(--text-muted); font-weight: 600; margin-top: 4px; }
        .kpi-card.kpi-wait { border-left-color: #d97706; }
        .kpi-card.kpi-session { border-left-color: #0284c7; }
        .kpi-card.kpi-alert { border-left-color: #dc2626; }
        .kpi-card.kpi-alert .kpi-value { color: #dc2626; }
        .kpi-card.kpi-done { border-left-color: #10b981; }

        .flat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 22px; }
        .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .flat-card-header h3 { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .queue-tabs { display: flex; border: 1px solid var(--border-color); }
        .queue-tab { padding: 7px 12px; font-size: 12px; font-weight: 700; background: #fff; color: var(--text-muted); text-decoration: none; }
        .queue-tab.active { background: var(--brand-dark); color: #fff; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 14px; vertical-align: middle; }
        th { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 12px; background: #f8fafc; }
        .client-phone { display: block; font-size: 11px; color: var(--text-muted); font-weight: 500; margin-top: 2px; }
        .wait-note { display: block; font-size: 11px; font-weight: 700; color: #d97706; margin-top: 3px; }
        tr.row-wait { background: #fffbeb; }
        tr.row-wait-long { background: #fef2f2; }
        tr.row-wait-long .wait-note { color: #dc2626; }
        tr.row-mine td:first-child { box-shadow: inset 4px 0 0 #0ea5e9; }
        .you-tag { display: inline-block; margin-left: 6px; font-size: 10px; font-weight: 800; letter-spacing: 0.4px; text-transform: uppercase; color: #0284c7; }

        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; font-size: 12px; font-weight: 600; border-radius: 4px; text-transform: uppercase; border: 1px solid; }
        .badge-pending { background-color: #fef3c7; color: #d97706; border-color: #fde68a; }
        .badge-inprogress { background-color: #e0f2fe; color: #0284c7; border-color: #bae6fd; }
        .badge-done { background-color: #dcfce7; color: #16a34a; border-color: #bbf7d0; }
        .badge-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        .badge-pending .badge-dot { background-color: #d97706; }
        .badge-inprogress .badge-dot { background-color: #0284c7; animation: pulse 1.5s infinite; }
        .badge-done .badge-dot { background-color: #16a34a; }
        @keyframes pulse { 0% { transform: scale(0.9); opacity: 1; } 50% { transform: scale(1.2); opacity: 0.5; } 100% { transform: scale(0.9); opacity: 1; } }

        select.status-select { padding: 8px 10px; border: 1px solid var(--border-color); background: #f8fafc; cursor: pointer; font-size: 13px; font-weight: 600; min-width: 130px; }
        select.status-select:focus { outline: none; border-color: var(--brand-blue); background: #fff; }

        .countdown { display: inline-flex; align-items: center; gap: 6px; font-variant-numeric: tabular-nums; font-weight: 700; font-size: 13px; color: #0284c7; white-space: nowrap; }
        .countdown.warn { color: #d97706; }
        .countdown.over { color: #dc2626; font-weight: 800; }
        .countdown.over .over-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; }
        .countdown-idle { color: var(--text-muted); font-weight: 500; font-size: 12px; }

        #toast-container { position: fixed; bottom: 30px; right: 30px; display: flex; flex-direction: column; gap: 10px; z-index: 1000; pointer-events: none; }
        .toast-notification { background: rgba(15, 23, 42, 0.95); color: #ffffff; padding: 16px 24px; border-left: 4px solid #0ea5e9; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); display: flex; align-items: center; gap: 15px; transform: translateX(120%); transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1); max-width: 380px; pointer-events: auto; }
        .toast-notification.show { transform: translateX(0); }
        .toast-notification.toast-timeup { border-left-color: #f59e0b; }
        .toast-icon { font-size: 24px; color: #0ea5e9; }
        .toast-notification.toast-timeup .toast-icon { color: #f59e0b; }
        .toast-content h4 { font-size: 14px; font-weight: 600; margin-bottom: 2px; }
        .toast-content p { font-size: 13px; color: #cbd5e1; }
        .toast-close { margin-left: auto; cursor: pointer; color: #94a3b8; background: none; border: none; font-size: 16px; }

        @media (max-width: 900px) { .kpi-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; width: 100%; } }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sidebar-header">
        <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo">
    </div>
    <div class="user-profile">
        <h4><?= htmlspecialchars($staff_name) ?></h4>
        <p><i class="fas fa-circle"></i> <?= htmlspecialchars($station_title) ?></p>
    </div>
    <nav class="sidebar-menu">
        <a href="<?= htmlspecialchars($station_file) ?>" class="menu-item active"><i class="fas fa-desktop"></i> Station</a>
    </nav>
    <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> End Shift</a>
</aside>

<main class="main-content">
    <header class="topbar">
        <h2><?= htmlspecialchars($station_title) ?></h2>
        <div class="date-stamp"><?= date('l, F j, Y') ?></div>
    </header>

    <div class="content-wrapper">
        <div class="kpi-grid">
            <div class="kpi-card kpi-wait">
                <div class="kpi-label">Waiting</div>
                <div class="kpi-value" id="kpi-waiting"><?= (int)$stats['waiting'] ?></div>
                <div class="kpi-sub" id="kpi-mine">Assigned to you</div>
            </div>
            <div class="kpi-card kpi-session">
                <div class="kpi-label">In session</div>
                <div class="kpi-value" id="kpi-in-progress"><?= (int)$stats['in_progress'] ?></div>
            </div>
            <div class="kpi-card <?= (int)$stats['overtime'] > 0 ? 'kpi-alert' : '' ?>" id="kpi-overtime-card">
                <div class="kpi-label">Overtime</div>
                <div class="kpi-value" id="kpi-overtime"><?= (int)$stats['overtime'] ?></div>
            </div>
            <div class="kpi-card kpi-done">
                <div class="kpi-label">Done today</div>
                <div class="kpi-value" id="kpi-done"><?= (int)$stats['done'] ?></div>
            </div>
        </div>

        <div class="flat-card">
            <div class="flat-card-header">
                <h3><?= $view === 'completed' ? 'Your completed' : 'Your clients' ?></h3>
                <div class="queue-tabs">
                    <a class="queue-tab<?= $view === 'active' ? ' active' : '' ?>" href="<?= htmlspecialchars($station_file) ?>?view=active">Active (<?= count($active) ?>)</a>
                    <a class="queue-tab<?= $view === 'completed' ? ' active' : '' ?>" href="<?= htmlspecialchars($station_file) ?>?view=completed">Completed (<?= count($completed) ?>)</a>
                </div>
            </div>
            <div class="table-container" style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Arrival</th>
                            <th>Client</th>
                            <th>Service</th>
                            <th>Assigned</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="worker-table-body">
                        <?php foreach ($rows as $client):
                            $status = $client['status'] ?? 'Pending';
                            $wait_mins = waiting_minutes($client);
                            $row_class = trim(wait_row_class($wait_mins) . ' row-mine');
                            $created = !empty($client['created_at']) ? strtotime($client['created_at']) : false;
                        ?>
                        <tr class="<?= htmlspecialchars($row_class) ?>">
                            <td style="color: var(--text-muted); font-weight: 500;">
                                <?= $created ? date('h:i A', $created) : '—' ?>
                                <?php if ($wait_mins !== null): ?>
                                <span class="wait-note" data-created-unix="<?= (int)$client['created_unix'] ?>"><?= (int)$wait_mins ?> min wait</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-weight: 600;"><?= htmlspecialchars($client['name'] ?? '') ?></span>
                                <?php if (!empty($client['phone'])): ?>
                                <span class="client-phone"><?= htmlspecialchars($client['phone']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-weight: 500;"><?= htmlspecialchars(client_service_label($client)) ?></span>
                                <?php if (!empty($client['client_code'])): ?>
                                <span class="client-phone"><?= htmlspecialchars($client['client_code']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($client['staff_name'] ?: $staff_name) ?>
                            </td>
                            <td>
                                <span class="badge <?= staff_status_badge_class($status) ?>">
                                    <span class="badge-dot"></span>
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <td><?= countdown_cell_html($client) ?></td>
                            <td>
                                <form method="post">
<?= csrf_field() ?>
                                    <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
                                    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                                    <select name="status" class="status-select" data-current="<?= htmlspecialchars($status) ?>" onchange="onStatusChange(this)">
                                        <option value="Pending" <?= $status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="In Progress" <?= $status === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                        <option value="Done" <?= $status === 'Done' ? 'selected' : '' ?>>Done</option>
                                    </select>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                        <tr><td colspan="7" style="text-align:center; color:var(--text-muted); padding:30px;"><?= $view === 'completed' ? 'No finished tickets assigned to you today.' : 'No clients assigned to you yet.' ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<div id="toast-container"></div>
<script>
    const stationFile = <?= json_encode($station_file) ?>;
    const queueView = <?= json_encode($view) ?>;
    const myName = <?= json_encode($staff_name) ?>;
    const myAliases = <?= json_encode(staff_identity_names($staff_user)) ?>;
    let latestQueue = <?= json_encode($queue) ?>;
    let clientIds = new Set(<?= json_encode(array_map('intval', array_column($queue, 'id'))) ?>);
    const timeUpBeeped = new Set();
    let serverUnix = <?= time() ?>;
    let serverUnixCapturedAt = Date.now();

    function nowUnix() {
        return serverUnix + (Date.now() - serverUnixCapturedAt) / 1000;
    }
    function escapeHtml(value) {
        return String(value ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function formatCountdown(totalSeconds) {
        const secs = Math.max(0, Math.floor(totalSeconds));
        const h = Math.floor(secs / 3600);
        const m = Math.floor((secs % 3600) / 60);
        const s = secs % 60;
        const mmss = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        return h > 0 ? (String(h) + ':' + mmss) : mmss;
    }
    function serviceLabel(client) {
        return client.massage_type ? `${client.service_type} (${client.massage_type})` : (client.service_type || '');
    }
    function isMine(client) {
        const assigned = (client.staff_name || '').trim().toLowerCase();
        if (!assigned) return false;
        return (myAliases || []).some(name => String(name || '').trim().toLowerCase() === assigned);
    }
    function badgeClass(status) {
        if (status === 'In Progress') return 'badge-inprogress';
        if (status === 'Done') return 'badge-done';
        return 'badge-pending';
    }
    function countdownCellHtml(client) {
        const status = client.status || 'Pending';
        const mins = parseInt(client.duration_minutes, 10);
        const started = parseInt(client.service_started_unix, 10);
        const rawDuration = (client.duration || '').trim();
        if (!mins || status === 'Done') return '<span class="countdown-idle">—</span>';
        if (status !== 'In Progress' || !started) {
            const label = escapeHtml(rawDuration || (mins + ' min'));
            return `<span class="countdown-idle">${status === 'Pending' ? (label + ' booked') : label}</span>`;
        }
        return `<span class="countdown" data-client-id="${parseInt(client.id, 10)}" data-started-unix="${started}" data-minutes="${mins}" data-name="${escapeHtml(client.name)}" data-service="${escapeHtml(serviceLabel(client))}"><i class="fas fa-clock"></i> —</span>`;
    }
    function waitClass(mins) {
        if (mins === null || mins === undefined) return '';
        if (mins >= 30) return 'row-wait-long';
        if (mins >= 15) return 'row-wait';
        return '';
    }
    function actionHtml(client) {
        const status = client.status || 'Pending';
        const view = status === 'Done' ? 'completed' : 'active';
        return `<form method="post">
<?= csrf_field() ?>
            <input type="hidden" name="client_id" value="${parseInt(client.id,10)}">
            <input type="hidden" name="view" value="${view}">
            <select name="status" class="status-select" data-current="${escapeHtml(status)}" onchange="onStatusChange(this)">
                <option value="Pending" ${status === 'Pending' ? 'selected' : ''}>Pending</option>
                <option value="In Progress" ${status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                <option value="Done" ${status === 'Done' ? 'selected' : ''}>Done</option>
            </select>
        </form>`;
    }
    function rowHtml(client) {
        const status = client.status || 'Pending';
        const mine = isMine(client);
        const created = parseInt(client.created_unix, 10);
        const waitMins = status === 'Pending' && created ? Math.max(0, Math.floor((nowUnix() - created) / 60)) : null;
        const classes = [waitClass(waitMins), mine ? 'row-mine' : ''].filter(Boolean).join(' ');
        const arrival = created ? new Date(created * 1000).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'}) : '—';
        const waitHtml = waitMins !== null ? `<span class="wait-note" data-created-unix="${created}">${waitMins} min wait</span>` : '';
        return `<tr class="${classes}">
            <td style="color: var(--text-muted); font-weight: 500;">${escapeHtml(arrival)}${waitHtml}</td>
            <td><span style="font-weight:600;">${escapeHtml(client.name)}</span>${client.phone ? `<span class="client-phone">${escapeHtml(client.phone)}</span>` : ''}</td>
            <td><span style="font-weight:500;">${escapeHtml(serviceLabel(client))}</span>${client.client_code ? `<span class="client-phone">${escapeHtml(client.client_code)}</span>` : ''}</td>
            <td>${escapeHtml(client.staff_name || myName)}</td>
            <td><span class="badge ${badgeClass(status)}"><span class="badge-dot"></span> ${escapeHtml(status)}</span></td>
            <td>${countdownCellHtml(client)}</td>
            <td><div class="row-actions">${actionHtml(client)}</div></td>
        </tr>`;
    }
    function sortQueue(queue, view) {
        const rows = [...(queue || [])];
        rows.sort((a, b) => {
            const createdA = parseInt(a.created_unix, 10) || 0;
            const createdB = parseInt(b.created_unix, 10) || 0;
            if (view === 'completed') return createdB - createdA;
            const statusA = a.status || 'Pending';
            const statusB = b.status || 'Pending';
            const rankA = statusA === 'In Progress' ? 1 : 0;
            const rankB = statusB === 'In Progress' ? 1 : 0;
            if (rankA !== rankB) return rankA - rankB;
            return createdA - createdB;
        });
        return rows;
    }
    function renderQueue() {
        const tbody = document.getElementById('worker-table-body');
        if (!tbody) return;
        const waiting = (latestQueue || []).filter(c => (c.status || 'Pending') !== 'Done');
        const done = (latestQueue || []).filter(c => (c.status || 'Pending') === 'Done');
        const rows = sortQueue(queueView === 'completed' ? done : waiting, queueView);
        if (!rows.length) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:var(--text-muted); padding:30px;">${queueView === 'completed' ? 'No finished tickets assigned to you today.' : 'No clients assigned to you yet.'}</td></tr>`;
            return;
        }
        tbody.innerHTML = rows.map(rowHtml).join('');
        tickCountdowns();
    }
    function applyKpis(kpis) {
        if (!kpis) return;
        const waitingEl = document.getElementById('kpi-waiting');
        const inEl = document.getElementById('kpi-in-progress');
        const overEl = document.getElementById('kpi-overtime');
        const overCard = document.getElementById('kpi-overtime-card');
        const doneEl = document.getElementById('kpi-done');
        const mineEl = document.getElementById('kpi-mine');
        if (waitingEl) waitingEl.textContent = Number(kpis.waiting || 0).toLocaleString();
        if (inEl) inEl.textContent = Number(kpis.in_progress || 0).toLocaleString();
        if (overEl) overEl.textContent = Number(kpis.overtime || 0).toLocaleString();
        if (overCard) overCard.classList.toggle('kpi-alert', Number(kpis.overtime || 0) > 0);
        if (doneEl) doneEl.textContent = Number(kpis.done || 0).toLocaleString();
        if (mineEl) mineEl.textContent = 'Assigned to you';
        const activeTab = document.querySelector('.queue-tab[href*="view=active"]');
        const doneTab = document.querySelector('.queue-tab[href*="view=completed"]');
        const waitingCount = (latestQueue || []).filter(c => (c.status || 'Pending') !== 'Done').length;
        const doneCount = (latestQueue || []).filter(c => (c.status || 'Pending') === 'Done').length;
        if (activeTab) activeTab.textContent = 'Active (' + waitingCount + ')';
        if (doneTab) doneTab.textContent = 'Completed (' + doneCount + ')';
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
                el.classList.add('over');
                el.innerHTML = '<i class="fas fa-clock"></i> +' + formatCountdown(Math.abs(remaining)) + ' <span class="over-label">over</span>';
                if (clientId && !timeUpBeeped.has(clientId)) {
                    timeUpBeeped.add(clientId);
                    showToast(el.dataset.name || 'Client', 'Time is up for ' + (el.dataset.service || 'this service') + '.', true);
                }
                return;
            }
            if (remaining <= 5 * 60) el.classList.add('warn');
            el.innerHTML = '<i class="fas fa-clock"></i> ' + formatCountdown(remaining);
        });
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
    }
    function playAlertSound() {
        const audio = new Audio('../assets/sounds/notification.mp3');
        audio.volume = 0.5;
        audio.play().catch(() => {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const osc1 = audioCtx.createOscillator();
                const gain1 = audioCtx.createGain();
                osc1.connect(gain1); gain1.connect(audioCtx.destination);
                osc1.type = 'sine'; osc1.frequency.setValueAtTime(523.25, audioCtx.currentTime);
                gain1.gain.setValueAtTime(0.3, audioCtx.currentTime);
                osc1.start(); osc1.stop(audioCtx.currentTime + 0.15);
            } catch (e) {}
        });
    }
    function showToast(title, body, timeUp) {
        const container = document.getElementById('toast-container') || document.body;
        const toast = document.createElement('div');
        toast.className = 'toast-notification' + (timeUp ? ' toast-timeup' : '');
        toast.innerHTML = `<div class="toast-icon"><i class="fas ${timeUp ? 'fa-clock' : 'fa-user-plus'}"></i></div><div class="toast-content"><h4>${escapeHtml(title)}</h4><p>${escapeHtml(body)}</p></div><button class="toast-close" type="button"><i class="fas fa-times"></i></button>`;
        container.appendChild(toast);
        playAlertSound();
        setTimeout(() => toast.classList.add('show'), 100);
        const close = () => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 400); };
        toast.querySelector('.toast-close').addEventListener('click', close);
        setTimeout(close, 5000);
    }
    function onStatusChange(sel) {
        const previous = sel.dataset.current || 'Pending';
        if (sel.value === 'Done' && !confirm('Mark this client done?')) {
            sel.value = previous;
            return;
        }
        sel.form.submit();
    }
    function statusSelectOpen() {
        const el = document.activeElement;
        return el && el.classList && el.classList.contains('status-select');
    }
    async function pollQueue() {
        try {
            const response = await fetch('../api/get_section_queue.php');
            if (!response.ok) return;
            const data = await response.json();
            const clients = (Array.isArray(data) ? data : (data.queue || [])).filter(isMine);
            if (data.server_unix) {
                serverUnix = data.server_unix;
                serverUnixCapturedAt = Date.now();
            }
            for (const client of clients) {
                const id = parseInt(client.id, 10);
                if (!clientIds.has(id)) {
                    clientIds.add(id);
                    showToast('New client', (client.name || 'A client') + ' is waiting for ' + (serviceLabel(client) || 'service') + '.');
                }
            }
            latestQueue = clients;
            applyKpis(data.kpis || {});
            if (!statusSelectOpen()) {
                renderQueue();
            }
        } catch (err) {}
    }
    setInterval(tickCountdowns, 1000);
    setInterval(pollQueue, 5000);
    tickCountdowns();
</script>
</body>
</html>
