<?php
session_start();
include '../db.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['frontdesk', 'reception'])) {
    header("Location: ../login.php");
    exit;
}

$current_month = date('Y-m');
$today = date('Y-m-d');

// 1. Fetch counts from database by section (MTD for Chart)
$sections = ['Massage', 'Pedicure & Manicure', 'Barbering', 'Hair Salon', 'Facials', 'Make-Up services'];
$section_counts = [];
foreach ($sections as $section) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM clients WHERE section = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?");
    $stmt->bind_param("ss", $section, $current_month);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $section_counts[$section] = $result['count'];
}

// 2. Total clients (Volume)
$total_today = $conn->query("SELECT COUNT(*) as total FROM clients WHERE DATE(created_at) = '$today'")->fetch_assoc()['total'];
$total_month = $conn->query("SELECT COUNT(*) as total FROM clients WHERE DATE_FORMAT(created_at, '%Y-%m') = '$current_month'")->fetch_assoc()['total'];

// 3. Financials (Revenue)
$rev_today = $conn->query("SELECT SUM(amount) as total FROM clients WHERE DATE(created_at) = '$today'")->fetch_assoc()['total'] ?? 0;
$rev_month = $conn->query("SELECT SUM(amount) as total FROM clients WHERE DATE_FORMAT(created_at, '%Y-%m') = '$current_month'")->fetch_assoc()['total'] ?? 0;

// 4. Daily Service Breakdown (For the new table)
$daily_breakdown = [];
$breakdown_res = $conn->query("SELECT service_type, COUNT(*) as qty, SUM(amount) as total_revenue FROM clients WHERE DATE(created_at) = '$today' GROUP BY service_type ORDER BY total_revenue DESC");
if ($breakdown_res) {
    while ($row = $breakdown_res->fetch_assoc()) {
        $daily_breakdown[] = $row;
    }
}

// 5. Today's Ongoing Services / Queue
$ongoing_services = [];
$hour = (int)date('H');
if (false && $hour >= 20) { // Disabled for testing; change to 'true && $hour >= 20' to reactivate
    $ongoing_res = $conn->query("SELECT * FROM clients WHERE DATE(created_at) = '$today' AND (status != 'Done' OR status IS NULL) ORDER BY created_at DESC");
} else {
    $ongoing_res = $conn->query("SELECT * FROM clients WHERE DATE(created_at) = '$today' ORDER BY created_at DESC");
}
if ($ongoing_res) {
    while ($row = $ongoing_res->fetch_assoc()) {
        $ongoing_services[] = $row;
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
        .topbar h2 { font-size: 18px; font-weight: 600; }
        .topbar .date-stamp { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        .content-wrapper { padding: 30px; flex: 1; max-width: 1400px; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 25px; display: flex; align-items: center; gap: 20px; border-left: 4px solid var(--brand-blue); }
        .kpi-icon { font-size: 32px; color: var(--brand-blue); width: 45px; text-align: center; }
        .kpi-value { font-size: 26px; font-weight: 700; color: var(--text-main); line-height: 1.2; }
        .kpi-label { font-size: 12px; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 0.5px; }

        .action-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .action-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; text-align: center; transition: all 0.2s; display: flex; flex-direction: column; align-items: center; text-decoration: none; color: var(--text-main); }
        .action-card:hover { border-color: var(--brand-blue); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(14, 165, 233, 0.1); }
        .action-icon { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 15px; }

        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px; }
        .flat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 25px; }
        .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; }
        .flat-card-header h3 { font-size: 16px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .chart-container { position: relative; height: 320px; width: 100%; }

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

        @media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; width: 100%; } }

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
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket" style="margin-right:12px;"></i> End Shift</a>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Welcome back, <?= htmlspecialchars(explode(' ', $_SESSION['user']['name'])[0]) ?></h2>
            <div class="date-stamp"><?= date("l, F j, Y") ?></div>
        </header>

        <div class="content-wrapper">
            
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon"><i class="fas fa-users"></i></div>
                    <div><div class="kpi-label">Today's Check-ins</div><div class="kpi-value" id="kpi-checkins"><?= number_format($total_today) ?></div></div>
                </div>
                <div class="kpi-card" style="border-left-color: #10b981;">
                    <div class="kpi-icon" style="color: #10b981;"><i class="fas fa-wallet"></i></div>
                    <div><div class="kpi-label">Today's Revenue</div><div class="kpi-value" id="kpi-revenue">GHS <?= number_format($rev_today, 2) ?></div></div>
                </div>
                <div class="kpi-card" style="border-left-color: #8b5cf6;">
                    <div class="kpi-icon" style="color: #8b5cf6;"><i class="fas fa-calendar-check"></i></div>
                    <div><div class="kpi-label">MTD Clients</div><div class="kpi-value" id="kpi-mtd-clients"><?= number_format($total_month) ?></div></div>
                </div>
                <div class="kpi-card" style="border-left-color: #f59e0b;">
                    <div class="kpi-icon" style="color: #f59e0b;"><i class="fas fa-chart-line"></i></div>
                    <div><div class="kpi-label">MTD Revenue</div><div class="kpi-value" id="kpi-mtd-revenue">GHS <?= number_format($rev_month, 2) ?></div></div>
                </div>
            </div>

            <div class="action-grid">
                <a href="add_client.php" class="action-card">
                    <div class="action-icon" style="background-color: #f0f9ff; color: var(--brand-blue);"><i class="fas fa-user-plus"></i></div>
                    <h3 style="font-weight:600; margin-bottom:8px;">New Registration</h3>
                    <p style="font-size:14px; color:var(--text-muted);">Process walk-in or new client</p>
                </a>
                <a href="appointments.php" class="action-card">
                    <div class="action-icon" style="background-color: #ecfdf5; color: #10b981;"><i class="fas fa-calendar-alt"></i></div>
                    <h3 style="font-weight:600; margin-bottom:8px;">Appointments</h3>
                    <p style="font-size:14px; color:var(--text-muted);">Manage calls & scheduling</p>
                </a>
                <a href="view_clients.php" class="action-card">
                    <div class="action-icon" style="background-color: #f5f3ff; color: #8b5cf6;"><i class="fas fa-clipboard-list"></i></div>
                    <h3 style="font-weight:600; margin-bottom:8px;">Client Directory</h3>
                    <p style="font-size:14px; color:var(--text-muted);">Search and manage records</p>
                </a>
            </div>

            <div class="flat-card" style="margin-bottom: 30px;">
                <div class="flat-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h3>Today's Service Queue & Status</h3>
                    <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;"><i class="fas fa-circle-notch fa-spin" style="color: var(--brand-blue); margin-right: 6px;"></i> Live Updates</span>
                </div>
                <div class="table-container" style="overflow-x: auto;">
                    <table style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Arrival Time</th>
                                <th>Client Code</th>
                                <th>Client Name</th>
                                <th>Service / Section</th>
                                <th>Assigned Staff</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="queue-table-body">
                            <?php foreach ($ongoing_services as $client): 
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
                            ?>
                            <tr>
                                <td style="color: var(--text-muted); font-weight: 500;"><?= $formatted_time ?></td>
                                <td><code><?= htmlspecialchars($client['client_code']) ?></code></td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($client['name']) ?></td>
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
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($ongoing_services)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">No clients registered today.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="flat-card">
                    <div class="flat-card-header"><h3>MTD Section Traffic</h3></div>
                    <div class="chart-container"><canvas id="trafficChart"></canvas></div>
                </div>

                <div class="flat-card">
                    <div class="flat-card-header"><h3>Today's Service Breakdown</h3></div>
                    <div style="overflow-y: auto; max-height: 320px;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>Qty</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($daily_breakdown as $row): ?>
                                <tr>
                                    <td style="font-weight:500;"><?= htmlspecialchars($row['service_type']) ?></td>
                                    <td><?= htmlspecialchars($row['qty']) ?></td>
                                    <td style="color: #10b981; font-weight:600;">GHS <?= number_format($row['total_revenue'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($daily_breakdown)): ?>
                                <tr><td colspan="3" style="text-align:center; color:var(--text-muted); padding: 30px;">No transactions recorded today.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        Chart.defaults.font.family = "'Segoe UI', sans-serif";
        Chart.defaults.color = '#475569';
        new Chart(document.getElementById('trafficChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($section_counts)) ?>,
                datasets: [{
                    label: 'Client Volume',
                    data: <?= json_encode(array_values($section_counts)) ?>,
                    backgroundColor: '#0ea5e9',
                    borderRadius: 2,
                    barThickness: 40
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, border: { display: false } },
                    y: { beginAtZero: true, grid: { color: '#e2e8f0' }, border: { display: false }, ticks: { stepSize: 1 } }
                }
            }
        });

        // Real-time Status updates & Toast notification trigger
        let clientStatuses = {};
        <?php foreach ($ongoing_services as $client): ?>
        clientStatuses[<?= (int)$client['id'] ?>] = '<?= htmlspecialchars($client['status'] ?? 'Pending') ?>';
        <?php endforeach; ?>

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

        function showToast(clientName, serviceName) {
            const container = document.getElementById('toast-container') || document.body;
            const toast = document.createElement('div');
            toast.className = 'toast-notification';
            toast.innerHTML = `
                <div class="toast-icon"><i class="fas fa-check-circle"></i></div>
                <div class="toast-content">
                    <h4>Service Completed!</h4>
                    <p><strong>${clientName}</strong> is done with ${serviceName}.</p>
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
                const ongoing = data.queue;
                const kpis = data.kpis;
                
                // Update KPIs
                if (kpis) {
                    const checkinsEl = document.getElementById('kpi-checkins');
                    const revenueEl = document.getElementById('kpi-revenue');
                    const mtdClientsEl = document.getElementById('kpi-mtd-clients');
                    const mtdRevenueEl = document.getElementById('kpi-mtd-revenue');
                    
                    if (checkinsEl) checkinsEl.textContent = Number(kpis.total_today).toLocaleString();
                    if (revenueEl) revenueEl.textContent = 'GHS ' + Number(kpis.rev_today).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    if (mtdClientsEl) mtdClientsEl.textContent = Number(kpis.total_month).toLocaleString();
                    if (mtdRevenueEl) mtdRevenueEl.textContent = 'GHS ' + Number(kpis.rev_month).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
                
                const tbody = document.getElementById('queue-table-body');
                if (!tbody) return;
                
                if (ongoing.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">No clients registered today.</td></tr>`;
                    clientStatuses = {};
                    return;
                }
                
                let html = '';
                for (const client of ongoing) {
                    const clientId = parseInt(client.id);
                    const status = client.status || 'Pending';
                    const oldStatus = clientStatuses[clientId];
                    
                    if (oldStatus !== undefined && oldStatus !== 'Done' && status === 'Done') {
                        const serviceDisplay = client.massage_type ? `${client.service_type} (${client.massage_type})` : client.service_type;
                        showToast(client.name, serviceDisplay);
                    }
                    
                    clientStatuses[clientId] = status;
                    
                    const timeStr = formatAMPM(client.created_at);
                    const badgeClass = getBadgeClass(status);
                    const serviceDisplay = client.massage_type ? `${client.service_type} (${client.massage_type})` : client.service_type;
                    
                    html += `
                        <tr>
                            <td style="color: var(--text-muted); font-weight: 500;">${timeStr}</td>
                            <td><code>${client.client_code}</code></td>
                            <td style="font-weight: 600;">${client.name}</td>
                            <td>
                                <span style="font-weight: 500;">${serviceDisplay}</span>
                                <span style="display: block; font-size: 11px; color: var(--text-muted);">${client.section}</span>
                            </td>
                            <td style="font-weight: 500;">${client.staff_name || 'Unassigned'}</td>
                            <td>
                                <span class="badge ${badgeClass}">
                                    <span class="badge-dot"></span>
                                    ${status}
                                </span>
                            </td>
                        </tr>
                    `;
                }
                tbody.innerHTML = html;
            } catch (err) {
                console.error('Error polling queue status:', err);
            }
        }

        // Poll every 5 seconds
        setInterval(pollQueueStatus, 5000);
    </script>
    <div id="toast-container"></div>
</body>
</html>